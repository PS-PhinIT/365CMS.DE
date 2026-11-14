<?php
/**
 * Einwilligungsverlauf des Newsletters im Audit-Log (Entscheidung E-1: keine eigene Tabelle, keine Schemaänderung).
 *
 * `newsletter_subscribers.consent_at`/`consent_text` halten nur die letzte Einwilligung; jede An-/Abmeldung,
 * Bestätigung und Änderung landet zusätzlich als Eintrag in `audit_log`:
 * Kategorie `newsletter`, Aktion `newsletter.<action>`, Entität `newsletter_subscriber` + ID,
 * Metadaten `source` (member/public/block/link …), `status`, `consent_text`, `locale`, `segment`, `double_opt_in`.
 * Die E-Mail-Adresse wird nicht protokolliert (Bezug über die Abonnenten-ID).
 *
 * @package CMS\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\AuditLogger;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterConsentLog
{
    public const CATEGORY = 'newsletter';
    public const ACTIONS = ['subscribe', 'confirm', 'unsubscribe', 'preferences', 'resend'];

    /** @var (\Closure(string, string, string, string, ?int, array<string, mixed>): void)|null Testnaht */
    private static ?\Closure $writer = null;

    /**
     * @param array<string, mixed> $subscriber Zeile aus `newsletter_subscribers` (mindestens `id`)
     * @param array<string, mixed> $extra      zusätzliche Metadaten (z. B. `double_opt_in`)
     */
    public static function record(string $action, array $subscriber, string $source, array $extra = []): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return;
        }
        $id = (int) ($subscriber['id'] ?? 0);
        $metadata = array_filter([
            'source' => $source,
            'status' => (string) ($subscriber['status'] ?? ''),
            'locale' => (string) ($subscriber['locale'] ?? ''),
            'segment' => (string) ($subscriber['segment_slug'] ?? ''),
            'consent_text' => in_array($action, ['subscribe', 'confirm'], true) ? (string) ($subscriber['consent_text'] ?? '') : '',
            'consent_at' => in_array($action, ['subscribe', 'confirm'], true) ? (string) ($subscriber['consent_at'] ?? '') : '',
        ], static fn (string $value): bool => $value !== '') + $extra;

        $description = match ($action) {
            'subscribe' => 'Newsletter-Anmeldung (Einwilligung erteilt)',
            'confirm' => 'Newsletter-Anmeldung bestätigt (Double-Opt-In)',
            'unsubscribe' => 'Newsletter-Abmeldung (Einwilligung widerrufen)',
            'preferences' => 'Newsletter-Einstellungen geändert',
            default => 'Newsletter-Bestätigungsmail erneut gesendet',
        };

        try {
            if (self::$writer !== null) {
                (self::$writer)(self::CATEGORY, 'newsletter.' . $action, $description, 'newsletter_subscriber', $id > 0 ? $id : null, $metadata);

                return;
            }
            AuditLogger::instance()->log(self::CATEGORY, 'newsletter.' . $action, $description, 'newsletter_subscriber', $id > 0 ? $id : null, $metadata);
        } catch (\Throwable) {
            // Protokollieren darf An- und Abmeldung nie verhindern.
        }
    }

    /**
     * Nur für Tests: Schreibziel ersetzen (null = AuditLogger).
     *
     * @internal
     */
    public static function useWriter(?\Closure $writer): void
    {
        self::$writer = $writer;
    }
}
