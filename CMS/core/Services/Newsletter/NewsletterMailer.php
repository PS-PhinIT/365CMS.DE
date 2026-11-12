<?php
/**
 * Newsletter-Mails: Ausgaben (personalisiert), Testversand, Bestätigungsmail (Double-Opt-In), Webversion.
 *
 * Platzhalter in Betreff und Inhalt: {{first_name}}, {{last_name}}, {{email}}, {{site_name}}, {{site_url}},
 * {{unsubscribe_url}}, {{web_url}}, {{date}}. Jede Ausgabe erhält eine Fußzeile mit Abmelde- und Weblink sowie
 * die Kopfzeilen `List-Unsubscribe` und `List-Unsubscribe-Post` (RFC 8058, Abmeldung mit einem Klick).
 *
 * @package CMSv2\Core\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\Logger;
use CMS\Services\MailQueueService;
use CMS\Services\MailService;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterMailer
{
    public const MAIL_SOURCE = 'newsletter';

    /**
     * Inhalt einer Kampagne: eigener Inhalt, sonst Inhalt der Vorlage.
     *
     * @param array<string, mixed> $campaign
     * @return array{subject: string, html: string, text: string}
     */
    public static function content(array $campaign): array
    {
        $html = trim((string) ($campaign['content_html'] ?? ''));
        $text = trim((string) ($campaign['content_text'] ?? ''));
        if ($html === '' && $text === '' && (int) ($campaign['template_id'] ?? 0) > 0) {
            $template = NewsletterRepository::instance()->template((int) $campaign['template_id']);
            $html = trim((string) ($template['content_html'] ?? ''));
            $text = trim((string) ($template['content_text'] ?? ''));
        }
        if ($html === '' && $text !== '') {
            $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        }

        return ['subject' => (string) ($campaign['subject'] ?? ''), 'html' => $html, 'text' => $text];
    }

    /**
     * Personalisierte Ausgabe für einen Abonnenten.
     *
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $subscriber
     * @return array{subject: string, html: string, headers: array<string, string>}
     */
    public static function renderCampaign(array $campaign, array $subscriber): array
    {
        $locale = NewsletterI18n::locale((string) ($subscriber['locale'] ?? 'de'));
        $token = (string) ($subscriber['optin_token'] ?? '');
        $unsubscribe = self::unsubscribeUrl($token, $locale);
        $web = self::webUrl((int) $campaign['id'], $locale, $token);
        $vars = self::vars($subscriber, $locale, $unsubscribe, $web);
        $content = self::content($campaign);

        return [
            'subject' => self::replace($content['subject'], $vars, false),
            'html' => self::layout(self::replace($content['html'], $vars, true), (string) ($campaign['preview_text'] ?? ''), $locale, $unsubscribe, $web, $vars),
            'headers' => self::listHeaders($unsubscribe),
        ];
    }

    /**
     * Webversion (ohne Personalisierung; mit Token des Empfängers inkl. Abmeldelink).
     *
     * @param array<string, mixed> $campaign
     * @param array<string, mixed>|null $subscriber
     */
    public static function renderWebVersion(array $campaign, ?array $subscriber, string $locale): string
    {
        $unsubscribe = $subscriber !== null ? self::unsubscribeUrl((string) $subscriber['optin_token'], $locale) : NewsletterI18n::url('/newsletter', $locale);
        $vars = self::vars($subscriber ?? [], $locale, $unsubscribe, '');

        return self::replace(self::content($campaign)['html'], $vars, true);
    }

    /**
     * Ausgabe an einen Abonnenten senden (über die Mail-Warteschlange, wenn aktiv).
     *
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $subscriber
     * @return array{success: bool, error?: string}
     */
    public static function sendCampaignTo(array $campaign, array $subscriber): array
    {
        $mail = self::renderCampaign($campaign, $subscriber);

        return self::deliver((string) $subscriber['email'], $mail['subject'], $mail['html'], $mail['headers'], false);
    }

    /**
     * Testversand an eine beliebige Adresse (Platzhalter mit Beispielwerten, Betreff mit Präfix).
     *
     * @param array<string, mixed> $campaign
     * @return array{success: bool, error?: string}
     */
    public static function sendTest(array $campaign, string $to, string $locale = 'de'): array
    {
        $to = NewsletterRepository::normalizeEmail($to);
        if ($to === '') {
            return ['success' => false, 'error' => (string) __('newsletter.error.email_invalid')];
        }
        $sample = ['email' => $to, 'first_name' => 'Max', 'last_name' => 'Muster', 'locale' => $locale, 'optin_token' => str_repeat('0', 48)];
        $mail = self::renderCampaign($campaign + ['id' => 0], $sample);

        return self::deliver($to, '[Test] ' . $mail['subject'], $mail['html'], [], true);
    }

    /**
     * Bestätigungsmail (Double-Opt-In). Wird direkt versendet; nur wenn das scheitert, über die Warteschlange.
     *
     * @param array<string, mixed> $subscriber
     */
    public static function sendConfirmation(array $subscriber): bool
    {
        $locale = NewsletterI18n::locale((string) ($subscriber['locale'] ?? 'de'));
        $url = NewsletterI18n::url('/newsletter/confirm/' . rawurlencode((string) $subscriber['optin_token']), $locale);
        $site = self::siteName();
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $body = '<p>' . $e(NewsletterI18n::t('newsletter.mail.confirm_intro', $locale, $site)) . '</p>'
            . '<p style="margin:24px 0"><a href="' . $e($url) . '" style="display:inline-block;padding:12px 20px;background:#2563eb;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600">'
            . $e(NewsletterI18n::t('newsletter.mail.confirm_button', $locale)) . '</a></p>'
            . '<p style="font-size:13px;color:#6b7280">' . $e(NewsletterI18n::t('newsletter.mail.confirm_link_hint', $locale)) . '<br><a href="' . $e($url) . '">' . $e($url) . '</a></p>'
            . '<p style="font-size:13px;color:#6b7280">' . $e(NewsletterI18n::t('newsletter.mail.confirm_ignore', $locale, NewsletterSettings::int('confirm_expiry_days'))) . '</p>';
        $html = self::layout($body, '', $locale, '', '', []);
        $subject = NewsletterI18n::t('newsletter.mail.confirm_subject', $locale, $site);

        $result = self::deliver((string) $subscriber['email'], $subject, $html, ['X-365CMS-Force-Direct' => '1'], false);
        if (!$result['success']) {
            $result = self::deliver((string) $subscriber['email'], $subject, $html, [], false);
        }
        if ($result['success']) {
            NewsletterRepository::instance()->markConfirmationSent((int) $subscriber['id']);
        }

        return $result['success'];
    }

    public static function unsubscribeUrl(string $token, string $locale): string
    {
        return $token !== '' ? NewsletterI18n::url('/newsletter/unsubscribe/' . rawurlencode($token), $locale) : '';
    }

    public static function webUrl(int $campaignId, string $locale, string $token = ''): string
    {
        if ($campaignId <= 0) {
            return '';
        }

        return NewsletterI18n::url('/newsletter/issue/' . $campaignId, $locale) . ($token !== '' ? '?t=' . rawurlencode($token) : '');
    }

    /** @return array<string, string> */
    public static function listHeaders(string $unsubscribeUrl): array
    {
        if ($unsubscribeUrl === '') {
            return [];
        }
        $parts = ['<' . $unsubscribeUrl . '>'];
        $sender = NewsletterSettings::senderEmail();
        if ($sender !== '') {
            $parts[] = '<mailto:' . $sender . '?subject=unsubscribe>';
        }

        return ['List-Unsubscribe' => implode(', ', $parts), 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
    }

    /**
     * @param array<string, mixed> $subscriber
     * @return array<string, string>
     */
    private static function vars(array $subscriber, string $locale, string $unsubscribe, string $web): array
    {
        $first = trim((string) ($subscriber['first_name'] ?? ''));

        return [
            'first_name' => $first !== '' ? $first : NewsletterI18n::t('newsletter.mail.name_fallback', $locale),
            'last_name' => trim((string) ($subscriber['last_name'] ?? '')),
            'email' => (string) ($subscriber['email'] ?? ''),
            'site_name' => self::siteName(),
            'site_url' => rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/') . ($locale === 'en' ? '/en' : ''),
            'unsubscribe_url' => $unsubscribe,
            'web_url' => $web,
            'date' => NewsletterI18n::date(date('Y-m-d'), $locale),
        ];
    }

    /** @param array<string, string> $vars */
    private static function replace(string $text, array $vars, bool $html): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($vars, $html): string {
            if (!array_key_exists($m[1], $vars)) {
                return $m[0];
            }

            return $html ? htmlspecialchars($vars[$m[1]], ENT_QUOTES, 'UTF-8') : $vars[$m[1]];
        }, $text);
    }

    /** @param array<string, string> $vars */
    private static function layout(string $body, string $preheader, string $locale, string $unsubscribe, string $web, array $vars): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $footer = NewsletterSettings::localized('mail_footer', $locale);
        $footerHtml = $footer !== '' ? '<p style="margin:0 0 8px">' . nl2br($e($vars !== [] ? self::replace($footer, $vars, false) : $footer)) . '</p>' : '';
        $links = [];
        if ($web !== '') {
            $links[] = '<a href="' . $e($web) . '" style="color:#6b7280">' . $e(NewsletterI18n::t('newsletter.mail.web_version', $locale)) . '</a>';
        }
        if ($unsubscribe !== '') {
            $links[] = '<a href="' . $e($unsubscribe) . '" style="color:#6b7280">' . $e(NewsletterI18n::t('newsletter.mail.unsubscribe', $locale)) . '</a>';
        }

        return '<!doctype html><html lang="' . $e($locale) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e(self::siteName()) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827">'
            . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $e($preheader) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:8px">'
            . '<tr><td style="padding:24px 28px 8px;font-size:13px;color:#6b7280">' . $e(self::siteName()) . '</td></tr>'
            . '<tr><td style="padding:8px 28px 24px;font-size:16px;line-height:1.6">' . $body . '</td></tr>'
            . '<tr><td style="padding:16px 28px 24px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#6b7280">'
            . $footerHtml . ($links !== [] ? '<p style="margin:0">' . implode(' · ', $links) . '</p>' : '')
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /**
     * @param array<string, string> $headers
     * @return array{success: bool, error?: string}
     */
    private static function deliver(string $to, string $subject, string $html, array $headers, bool $test): array
    {
        $headers['X-365CMS-Source'] = self::MAIL_SOURCE;
        if ($test) {
            $headers['X-365CMS-Test-Source'] = self::MAIL_SOURCE;
        }
        $from = NewsletterSettings::senderEmail();
        if ($from !== '') {
            $headers['From'] = '"' . NewsletterSettings::senderName() . '" <' . $from . '>';
        }
        $reply = NewsletterSettings::get('reply_to_email');
        if (filter_var($reply, FILTER_VALIDATE_EMAIL) !== false) {
            $headers['Reply-To'] = $reply;
        }

        try {
            $queue = MailQueueService::getInstance();
            if ($queue->shouldQueue($headers)) {
                $result = $queue->enqueue($to, $subject, $html, $headers, null, self::MAIL_SOURCE);

                return !empty($result['success']) ? ['success' => true] : ['success' => false, 'error' => (string) ($result['error'] ?? 'queue')];
            }
            $result = MailService::getInstance()->sendDetailed($to, $subject, $html, $headers);

            return !empty($result['success']) ? ['success' => true] : ['success' => false, 'error' => (string) ($result['error'] ?? 'mail')];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('newsletter')->warning('Newsletter-Mail konnte nicht versendet werden.', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private static function siteName(): string
    {
        return defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
    }
}
