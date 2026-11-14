<?php
/**
 * Mitgliederbereich „Feed-Abo“ (`/member/plugin/feeds`): Mitglieder wählen Quellen des Feed-Aggregators und
 * erhalten neue Artikel per E-Mail (täglich um 9 und/oder 15 Uhr oder wöchentlich).
 *
 * Ein Abo je Mitglied (`feeds_subscriptions`); die Empfängeradresse kommt beim Versand aus dem Benutzerkonto.
 * Wählbar sind nur aktive Quellen öffentlicher Bereiche. Speichern und Kündigen laufen per POST mit dem Core-Token
 * der Registry. Der Versand selbst liegt im `DigestMailer` (stündlicher Cron).
 *
 * @package CMS\Services\Feed
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

use CMS\Hooks;
use CMS\Member\PluginDashboardRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedMemberSection
{
    public const SLUG = 'feeds';

    public static function register(): void
    {
        Hooks::addAction('member_dashboard_init', static function (mixed $registry = null): void {
            if (!$registry instanceof PluginDashboardRegistry) {
                return;
            }
            try {
                if (!AggregatorSettings::isEnabled() || !AggregatorRepository::getInstance()->isReady()) {
                    return;
                }
            } catch (\Throwable) {
                return;
            }

            $registry->register([
                'plugin' => 'cms-feed',
                'slug' => self::SLUG,
                'label' => self::t('feeds.member.title'),
                'icon' => '📰',
                'category' => 'plugins',
                'priority' => 70,
                'csrf' => 'core',
                'dashboard_widget' => [
                    'title' => self::t('feeds.member.title'),
                    'description' => self::t('feeds.member.widget_description'),
                    'color' => '#0891b2',
                    'stats_callback' => [self::class, 'stats'],
                ],
                'render_callback' => [self::class, 'render'],
                'post_callback' => [self::class, 'handlePost'],
            ]);
        }, 25);
    }

    /** @return array{count:int,label:string} */
    public static function stats(object $user): array
    {
        $subscription = AggregatorRepository::getInstance()->subscriptionForUser((int) ($user->id ?? 0));
        $count = $subscription !== null && (int) $subscription['is_active'] === 1
            ? count(LegacyNormalizer::idList($subscription['source_ids'] ?? '[]'))
            : 0;

        return ['count' => $count, 'label' => self::t('feeds.member.sources_count')];
    }

    /**
     * Verarbeitet Speichern/Kündigen vor jeder Ausgabe; die Registry zeigt die Meldung nach der
     * 303-Weiterleitung als Flash.
     *
     * @param array<string, mixed> $params
     * @return array{type: string, message: string}|null
     */
    public static function handlePost(object $user, array $params = []): ?array
    {
        $userId = (int) ($user->id ?? 0);
        $repo = AggregatorRepository::getInstance();
        $action = is_scalar($_POST['member_feed_action'] ?? null) ? (string) $_POST['member_feed_action'] : '';
        $notice = null;

        if ($userId > 0 && $action === 'delete') {
            $repo->deleteSubscription($userId);
            $notice = ['success', self::t('feeds.member.deleted')];
        } elseif ($userId > 0 && $action === 'save') {
            $allowed = array_flip(array_map(static fn (array $s): int => (int) $s['id'], self::selectableSources()));
            $sourceIds = array_values(array_filter(
                LegacyNormalizer::idList(is_array($_POST['source_ids'] ?? null) ? $_POST['source_ids'] : []),
                static fn (int $id): bool => isset($allowed[$id])
            ));
            $existing = $repo->subscriptionForUser($userId);
            $saved = $repo->saveSubscription($userId, [
                'source_ids' => $sourceIds,
                'frequency' => $_POST['frequency'] ?? 'daily',
                'daily_mode' => $_POST['daily_mode'] ?? '09',
                'weekly_day' => $_POST['weekly_day'] ?? 1,
                'weekly_time' => $_POST['weekly_time'] ?? '09',
                'language' => is_scalar($_POST['language'] ?? null) ? (string) $_POST['language'] : 'de',
                'is_active' => isset($_POST['is_active']) && $sourceIds !== [],
                // Neues Abo: erst Artikel ab jetzt versenden, nicht den ganzen Bestand.
                'last_sent_at' => $existing['last_sent_at'] ?? AggregatorRepository::now(),
            ]);
            $notice = $saved
                ? ['success', $sourceIds === [] ? self::t('feeds.member.saved_empty') : self::t('feeds.member.saved')]
                : ['danger', self::t('feeds.member.save_failed')];
        }

        return $notice !== null ? ['type' => $notice[0], 'message' => $notice[1]] : null;
    }

    /** @param array<string, mixed> $params */
    public static function render(object $user, array $params = []): void
    {
        self::renderForm((int) ($user->id ?? 0), null);
    }

    /**
     * Aktive Quellen öffentlicher Bereiche, nach Bereich sortiert.
     *
     * @return list<array<string, mixed>>
     */
    private static function selectableSources(): array
    {
        $public = [];
        foreach (AggregatorRepository::getInstance()->categories(true) as $category) {
            $public[(int) $category['id']] = $category;
        }

        return array_values(array_filter(
            AggregatorRepository::getInstance()->sources(['status' => 'active']),
            static fn (array $source): bool => isset($public[(int) $source['category_id']])
        ));
    }

    /** @param array{0:string,1:string}|null $notice */
    private static function renderForm(int $userId, ?array $notice): void
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $subscription = AggregatorRepository::getInstance()->subscriptionForUser($userId);
        $selected = array_flip(LegacyNormalizer::idList($subscription['source_ids'] ?? '[]'));
        $frequency = LegacyNormalizer::frequency($subscription['frequency'] ?? 'daily');
        $dailyMode = LegacyNormalizer::dailyMode($subscription['daily_mode'] ?? '09');
        $weeklyDay = LegacyNormalizer::weeklyDay($subscription['weekly_day'] ?? 1);
        $weeklyTime = LegacyNormalizer::weeklyTime($subscription['weekly_time'] ?? '09');
        $language = FeedI18n::normalizeLocale((string) ($subscription['language'] ?? FeedI18n::systemLocale()));
        $active = $subscription === null || (int) $subscription['is_active'] === 1;

        if ($notice !== null) {
            echo '<div class="alert alert-' . $e($notice[0]) . '" role="status">' . $e($notice[1]) . '</div>';
        }

        $sources = self::selectableSources();
        if ($sources === []) {
            echo '<p class="text-secondary mb-0">' . $e(self::t('feeds.member.no_sources')) . '</p>';

            return;
        }

        echo '<p class="text-secondary">' . $e(self::t('feeds.member.intro')) . '</p>';
        if ($subscription !== null && $selected !== []) {
            echo '<p><strong>' . $e(self::t('feeds.member.current_plan')) . '</strong> ' . $e(DigestMailer::planLabel($subscription, FeedI18n::systemLocale()))
                . ($active ? '' : ' · ' . $e(self::t('feeds.member.paused'))) . '</p>';
        }

        echo '<form method="post" action="/member/plugin/' . self::SLUG . '">'
            . PluginDashboardRegistry::instance()->csrfField(self::SLUG)
            . '<input type="hidden" name="member_feed_action" value="save">';

        $currentCategory = null;
        echo '<fieldset class="mb-3"><legend class="form-label">' . $e(self::t('feeds.member.sources')) . '</legend>';
        foreach ($sources as $source) {
            if ($currentCategory !== (int) $source['category_id']) {
                if ($currentCategory !== null) {
                    echo '</div>';
                }
                $currentCategory = (int) $source['category_id'];
                echo '<div class="mb-2"><div class="fw-bold small text-uppercase text-secondary mt-2">' . $e($source['category_name'] ?? '') . '</div>';
            }
            $id = (int) $source['id'];
            echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="source_ids[]" value="' . $id . '"'
                . (isset($selected[$id]) ? ' checked' : '') . '> <span class="form-check-label">' . $e($source['name']) . '</span></label>';
        }
        echo '</div></fieldset>';

        echo '<div class="row g-3 mb-3">'
            . '<div class="col-sm-6"><label class="form-label" for="feed-frequency">' . $e(self::t('feeds.member.frequency')) . '</label>'
            . '<select class="form-select" id="feed-frequency" name="frequency">'
            . self::option('daily', self::t('feeds.member.frequency_daily'), $frequency)
            . self::option('weekly', self::t('feeds.member.frequency_weekly'), $frequency)
            . '</select></div>'
            . '<div class="col-sm-6"><label class="form-label" for="feed-daily-mode">' . $e(self::t('feeds.member.daily_mode')) . '</label>'
            . '<select class="form-select" id="feed-daily-mode" name="daily_mode">';
        foreach (['09', '15', '09_15'] as $mode) {
            echo self::option($mode, FeedI18n::text('feeds.plan.daily_' . $mode, FeedI18n::systemLocale()), $dailyMode);
        }
        echo '</select></div>'
            . '<div class="col-sm-6"><label class="form-label" for="feed-weekly-day">' . $e(self::t('feeds.member.weekly_day')) . '</label>'
            . '<select class="form-select" id="feed-weekly-day" name="weekly_day">';
        for ($day = 1; $day <= 7; $day++) {
            echo self::option((string) $day, FeedI18n::text('feeds.weekday.' . $day, FeedI18n::systemLocale()), (string) $weeklyDay);
        }
        echo '</select></div>'
            . '<div class="col-sm-6"><label class="form-label" for="feed-weekly-time">' . $e(self::t('feeds.member.weekly_time')) . '</label>'
            . '<select class="form-select" id="feed-weekly-time" name="weekly_time">'
            . self::option('09', '09:00', $weeklyTime) . self::option('15', '15:00', $weeklyTime)
            . '</select></div>'
            . '<div class="col-sm-6"><label class="form-label" for="feed-language">' . $e(self::t('feeds.member.language')) . '</label>'
            . '<select class="form-select" id="feed-language" name="language">'
            . self::option('de', 'Deutsch', $language) . self::option('en', 'English', $language)
            . '</select></div></div>';

        echo '<label class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="is_active" value="1"' . ($active ? ' checked' : '') . '>'
            . ' <span class="form-check-label">' . $e(self::t('feeds.member.active')) . '</span></label>';
        echo '<button type="submit" class="btn btn-primary">' . $e(self::t('feeds.member.save')) . '</button></form>';

        if ($subscription !== null) {
            echo '<form method="post" action="/member/plugin/' . self::SLUG . '" class="mt-3"'
                . ' data-confirm-title="' . $e(self::t('feeds.member.delete')) . '"'
                . ' data-confirm-message="' . $e(self::t('feeds.member.delete_confirm')) . '"'
                . ' data-confirm-text="' . $e(self::t('feeds.member.delete')) . '" data-confirm-class="btn-danger">'
                . PluginDashboardRegistry::instance()->csrfField(self::SLUG)
                . '<input type="hidden" name="member_feed_action" value="delete">'
                . '<button type="submit" class="btn btn-outline-danger btn-sm">' . $e(self::t('feeds.member.delete')) . '</button></form>';
        }
    }

    private static function option(string $value, string $label, string $current): string
    {
        return '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . ($value === $current ? ' selected' : '') . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    private static function t(string $key): string
    {
        return FeedI18n::admin($key);
    }
}
