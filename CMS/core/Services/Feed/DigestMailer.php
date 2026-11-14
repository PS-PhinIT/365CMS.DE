<?php
/**
 * E-Mail-Digests des Feed-Aggregators: Bereichs-Digests (`feeds_digests`) und Mitglieder-Abos
 * (`feeds_subscriptions`). Versand über die Mail-Queue, sonst direkt über den `MailService`.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

use CMS\Logger;
use CMS\Services\MailQueueService;
use CMS\Services\MailService;

if (!defined('ABSPATH')) {
    exit;
}

final class DigestMailer
{
    /** Intervalle der Bereichs-Digests in Stunden (Schlüssel = `frequency`). */
    public const DIGEST_INTERVAL_HOURS = [1 => 24, 2 => 12, 3 => 8, 4 => 6, 7 => 168];

    /** Kulanzfenster für Mitglieder-Abos: Versand bis zu drei Stunden nach dem geplanten Zeitpunkt. */
    public const SUBSCRIPTION_GRACE_SECONDS = 10800;

    private const MAIL_SOURCE = 'cms-feed-digest';

    public function __construct(private readonly AggregatorRepository $repository)
    {
    }

    /**
     * Versendet alle fälligen Bereichs-Digests und Mitglieder-Abos.
     *
     * @return array{digests_sent:int,digests_skipped:int,subscriptions_sent:int,subscriptions_skipped:int,failed:int}
     */
    public function sendDue(?int $now = null): array
    {
        $now ??= time();
        $result = ['digests_sent' => 0, 'digests_skipped' => 0, 'subscriptions_sent' => 0, 'subscriptions_skipped' => 0, 'failed' => 0];

        foreach ($this->repository->digests(true) as $digest) {
            if (!self::isDigestDue($digest, $now)) {
                continue;
            }
            $outcome = $this->sendDigest($digest, false, $now);
            $result[$outcome === 'sent' ? 'digests_sent' : ($outcome === 'empty' ? 'digests_skipped' : 'failed')]++;
        }

        foreach ($this->repository->activeSubscriptions() as $subscription) {
            $window = self::subscriptionWindow($subscription, $now);
            if ($window === null || !self::isSubscriptionDue($subscription, $window)) {
                continue;
            }
            $outcome = $this->sendSubscription($subscription, $window, $now);
            $result[$outcome === 'sent' ? 'subscriptions_sent' : ($outcome === 'empty' ? 'subscriptions_skipped' : 'failed')]++;
        }

        return $result;
    }

    /** @param array<string, mixed> $digest */
    public static function isDigestDue(array $digest, int $now): bool
    {
        if (empty($digest['is_active'])) {
            return false;
        }
        $lastSent = !empty($digest['last_sent_at']) ? strtotime((string) $digest['last_sent_at']) : false;
        if ($lastSent === false) {
            return true;
        }
        $hours = self::DIGEST_INTERVAL_HOURS[LegacyNormalizer::digestFrequency($digest['frequency'] ?? 1)] ?? 24;

        return $lastSent <= $now - $hours * 3600;
    }

    /**
     * Beginn des aktuellen Versandfensters eines Mitglieder-Abos oder null, wenn gerade kein Fenster offen ist.
     *
     * @param array<string, mixed> $subscription
     */
    public static function subscriptionWindow(array $subscription, int $now): ?int
    {
        $frequency = LegacyNormalizer::frequency($subscription['frequency'] ?? 'daily');
        if ($frequency === 'weekly') {
            if ((int) date('N', $now) !== LegacyNormalizer::weeklyDay($subscription['weekly_day'] ?? 1)) {
                return null;
            }
            $hours = [(int) LegacyNormalizer::weeklyTime($subscription['weekly_time'] ?? '09')];
        } else {
            $hours = match (LegacyNormalizer::dailyMode($subscription['daily_mode'] ?? '09')) {
                '15' => [15],
                '09_15' => [15, 9],
                default => [9],
            };
        }

        foreach ($hours as $hour) {
            $slot = mktime($hour, 0, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
            if ($slot !== false && $now >= $slot && $now < $slot + self::SUBSCRIPTION_GRACE_SECONDS) {
                return $slot;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $subscription */
    public static function isSubscriptionDue(array $subscription, int $windowStart): bool
    {
        $lastSent = !empty($subscription['last_sent_at']) ? strtotime((string) $subscription['last_sent_at']) : false;

        return $lastSent === false || $lastSent < $windowStart;
    }

    /**
     * Versendet einen Bereichs-Digest. Testversand: immer senden, Zeitpunkt nicht fortschreiben.
     *
     * @param array<string, mixed> $digest
     * @return 'sent'|'empty'|'failed'
     */
    public function sendDigest(array $digest, bool $test = false, ?int $now = null): string
    {
        $now ??= time();
        $settings = AggregatorSettings::all();
        $locale = FeedI18n::normalizeLocale((string) ($digest['language'] ?? 'de'));
        $lastSent = !$test && !empty($digest['last_sent_at']) ? strtotime((string) $digest['last_sent_at']) : false;
        $since = date('Y-m-d H:i:s', $lastSent !== false ? $lastSent : $now - 86400);
        $sentAt = date('Y-m-d H:i:s', $now);
        // Halboffenes Intervall [letzter Versand, jetzt) nach Abrufzeitpunkt: jeder Artikel genau einmal.
        $items = $this->repository->recentItems([], LegacyNormalizer::idList($digest['category_ids'] ?? '[]'), $since, (int) $settings['digest_max_items'], $test ? null : $sentAt);

        if ($items === [] && !$test) {
            $this->repository->markDigestSent((int) $digest['id'], $sentAt);

            return 'empty';
        }

        $subjectTemplate = $locale === 'en' ? (string) $settings['digest_subject_en'] : (string) $settings['digest_subject'];
        $subject = str_replace('{date}', FeedI18n::formatDate($sentAt, $locale), $subjectTemplate !== '' ? $subjectTemplate : (string) AggregatorSettings::DEFAULTS['digest_subject']);
        if ($test) {
            $subject = FeedI18n::text('feeds.mail.test_prefix', $locale) . $subject;
        }

        $frequencyLabel = FeedI18n::text('feeds.digest.frequency.' . LegacyNormalizer::digestFrequency($digest['frequency'] ?? 1), $locale);
        $html = $this->renderMail(
            FeedI18n::text('feeds.mail.digest_heading', $locale, (string) $digest['name']),
            FeedI18n::text('feeds.mail.new_items', $locale, FeedI18n::formatDate($sentAt, $locale), count($items)),
            $items,
            $locale,
            FeedI18n::text('feeds.mail.digest_footer', $locale, $this->siteName(), $frequencyLabel),
            $settings,
            200,
            false
        );

        if (!$this->send((string) $digest['email'], $subject, $html, $test)) {
            return 'failed';
        }
        if (!$test) {
            $this->repository->markDigestSent((int) $digest['id'], $sentAt);
        }

        return 'sent';
    }

    /**
     * @param array<string, mixed> $subscription Zeile aus `activeSubscriptions()` (inkl. E-Mail)
     * @return 'sent'|'empty'|'failed'
     */
    public function sendSubscription(array $subscription, int $windowStart, ?int $now = null): string
    {
        $now ??= time();
        $settings = AggregatorSettings::all();
        $locale = FeedI18n::normalizeLocale((string) ($subscription['language'] ?? 'de'));
        $frequency = LegacyNormalizer::frequency($subscription['frequency'] ?? 'daily');
        $lastSent = !empty($subscription['last_sent_at']) ? strtotime((string) $subscription['last_sent_at']) : false;
        $since = date('Y-m-d H:i:s', $lastSent !== false ? $lastSent : $now - ($frequency === 'weekly' ? 7 * 86400 : 86400));
        // Tatsächlichen Auswahlzeitpunkt speichern (nicht den Fensterbeginn), sonst doppelte Artikel im nächsten Abo-Versand.
        // Wegen $now >= $windowStart bleibt das Abo im laufenden Fenster trotzdem nicht erneut fällig.
        $markAt = date('Y-m-d H:i:s', $now);
        $items = $this->repository->recentItems(LegacyNormalizer::idList($subscription['source_ids'] ?? '[]'), [], $since, (int) $settings['digest_max_items'], $markAt);

        if ($items === []) {
            $this->repository->markSubscriptionSent((int) $subscription['id'], $markAt);

            return 'empty';
        }

        $date = FeedI18n::formatDate(date('Y-m-d H:i:s', $now), $locale);
        $html = $this->renderMail(
            FeedI18n::text('feeds.mail.subscription_heading', $locale),
            FeedI18n::text('feeds.mail.new_items', $locale, self::planLabel($subscription, $locale), count($items)),
            $items,
            $locale,
            FeedI18n::text('feeds.mail.subscription_footer', $locale, $this->siteUrl() . '/member/plugin/feeds'),
            $settings,
            220,
            true
        );

        if (!$this->send((string) $subscription['email'], FeedI18n::text('feeds.mail.subscription_subject', $locale, $date), $html, false)) {
            return 'failed';
        }
        $this->repository->markSubscriptionSent((int) $subscription['id'], $markAt);

        return 'sent';
    }

    /** @param array<string, mixed> $subscription */
    public static function planLabel(array $subscription, string $locale): string
    {
        if (LegacyNormalizer::frequency($subscription['frequency'] ?? 'daily') === 'weekly') {
            $day = LegacyNormalizer::weeklyDay($subscription['weekly_day'] ?? 1);

            return FeedI18n::text(
                'feeds.plan.weekly',
                $locale,
                FeedI18n::text('feeds.weekday.' . $day, $locale),
                LegacyNormalizer::weeklyTime($subscription['weekly_time'] ?? '09')
            );
        }

        return FeedI18n::text('feeds.plan.daily_' . LegacyNormalizer::dailyMode($subscription['daily_mode'] ?? '09'), $locale);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, bool|int|string> $settings
     */
    public function renderMail(string $heading, string $intro, array $items, string $locale, string $footer, array $settings, int $excerptLength, bool $withCategory): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $primary = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $settings['color_primary']) === 1 ? (string) $settings['color_primary'] : '#0891b2';

        $rows = '';
        foreach ($items as $item) {
            $meta = [(string) ($item['source_name'] ?? '')];
            if ($withCategory) {
                $meta[] = $locale === 'en' && !empty($item['category_name_en']) ? (string) $item['category_name_en'] : (string) ($item['category_name'] ?? '');
            }
            $meta[] = FeedI18n::formatDate((string) ($item['pub_date'] ?? ''), $locale, true);
            $meta = array_values(array_filter($meta, static fn (string $value): bool => $value !== ''));
            $link = (string) ($item['link'] ?? '');
            $title = $e((string) ($item['title'] ?? ''));
            $titleHtml = preg_match('#^https?://#i', $link) === 1
                ? '<a href="' . $e($link) . '" style="color:' . $e($primary) . ';text-decoration:none;font-weight:600;">' . $title . '</a>'
                : '<strong>' . $title . '</strong>';
            $description = SourceFetcher::plainExcerpt((string) ($item['description'] ?? ''), $excerptLength);

            $rows .= '<tr><td style="padding:14px 24px;border-bottom:1px solid #e2e8f0;">'
                . '<div style="font-size:16px;line-height:1.4;">' . $titleHtml . '</div>'
                . '<div style="font-size:12px;color:#64748b;margin-top:4px;">' . $e(implode(' · ', $meta)) . '</div>'
                . ($description !== '' ? '<div style="font-size:14px;color:#334155;margin-top:6px;line-height:1.5;">' . $e($description) . '</div>' : '')
                . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td style="padding:18px 24px;color:#64748b;">' . $e(FeedI18n::text('feeds.mail.no_items', $locale)) . '</td></tr>';
        }

        return '<!DOCTYPE html><html lang="' . $e($locale) . '"><head><meta charset="UTF-8"><title>' . $e($heading) . '</title></head>'
            . '<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">'
            . '<tr><td style="background:' . $e($primary) . ';color:#ffffff;padding:20px 24px;">'
            . '<div style="font-size:20px;font-weight:700;">' . $e($heading) . '</div>'
            . '<div style="font-size:13px;opacity:.9;margin-top:4px;">' . $e($intro) . '</div></td></tr>'
            . $rows
            . '<tr><td style="padding:16px 24px;font-size:12px;color:#94a3b8;">' . $e($footer) . '</td></tr>'
            . '</table></body></html>';
    }

    private function send(string $to, string $subject, string $html, bool $test): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $headers = ['X-365CMS-Source' => self::MAIL_SOURCE];
        if ($test) {
            $headers['X-365CMS-Test-Source'] = self::MAIL_SOURCE;
        }
        $fromEmail = trim((string) AggregatorSettings::get('digest_from_email'));
        if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $fromName = trim(str_replace(['"', "\r", "\n"], '', (string) AggregatorSettings::get('digest_from_name')));
            $headers['From'] = $fromName !== '' ? '"' . $fromName . '" <' . $fromEmail . '>' : $fromEmail;
        }

        try {
            $queue = MailQueueService::getInstance();
            if ($queue->shouldQueue($headers)) {
                return !empty($queue->enqueue($to, $subject, $html, $headers, null, self::MAIL_SOURCE)['success']);
            }

            return MailService::getInstance()->send($to, $subject, $html, $headers);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('feeds')->warning('Feed-Digest konnte nicht versendet werden.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function siteName(): string
    {
        return defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
    }

    private function siteUrl(): string
    {
        return defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
    }
}
