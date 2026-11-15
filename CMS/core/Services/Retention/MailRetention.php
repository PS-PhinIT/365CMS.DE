<?php
/**
 * Core-Aufbewahrungsregeln „Mail-Queue“ und „Mail-Protokoll“.
 *
 * Release-Prüfung 4.1.0 (PERF, batch:0): Die Mail-Queue speichert je Empfänger den vollständigen Body (Newsletter,
 * Feed-Digest, Formulare); ohne Frist wuchs sie unbegrenzt und blähte jedes Datenbank-Backup auf. Gelöscht werden
 * nur versendete oder endgültig fehlgeschlagene Jobs (Standard 30 Tage) sowie Einträge des Mail-Protokolls
 * (Standard 90 Tage), jeweils blockweise über `created_at` (Index idx_created_at). Offene und laufende Jobs bleiben
 * unberührt. Die Fristen lassen sich per Filter `cms_mail_retention_days` anpassen (`['mail_queue' => n,
 * 'mail_log' => n]`, 0 = nie löschen, sonst mindestens 7 Tage). Registriert werden die Regeln von
 * `CMS\Services\FeatureHooks\SystemFeatureHooks`.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Retention;

use CMS\Database;
use CMS\Hooks;
use CMS\Services\DataRetentionService;
use CMS\Services\MailQueueService;

if (!defined('ABSPATH')) {
    exit;
}

final class MailRetention
{
    public const QUEUE_RULE_KEY = 'mail_queue';
    public const LOG_RULE_KEY = 'mail_log';
    public const LOG_RETENTION_DAYS = 90;
    public const MIN_DAYS = 7;
    public const MAX_DAYS = 3650;
    public const SETTINGS_URL = '/admin/mail-settings';

    private const LOG_WHERE = 'created_at < (NOW() - INTERVAL ? DAY)';

    /**
     * Wirksame Fristen in Tagen (0 = nie löschen).
     *
     * @return array{mail_queue:int,mail_log:int}
     */
    public static function days(): array
    {
        $defaults = [
            self::QUEUE_RULE_KEY => MailQueueService::RETENTION_DAYS,
            self::LOG_RULE_KEY => self::LOG_RETENTION_DAYS,
        ];
        $configured = Hooks::applyFilters('cms_mail_retention_days', $defaults);

        return self::normalize(is_array($configured) ? $configured : [], $defaults);
    }

    /**
     * @param array<string, mixed> $configured
     * @param array<string, int>   $defaults
     * @return array{mail_queue:int,mail_log:int}
     */
    public static function normalize(array $configured, array $defaults = []): array
    {
        $defaults += [
            self::QUEUE_RULE_KEY => MailQueueService::RETENTION_DAYS,
            self::LOG_RULE_KEY => self::LOG_RETENTION_DAYS,
        ];
        $days = [];
        foreach ([self::QUEUE_RULE_KEY, self::LOG_RULE_KEY] as $key) {
            $value = isset($configured[$key]) && is_numeric($configured[$key]) ? (int) $configured[$key] : (int) $defaults[$key];
            // 0 = deaktiviert; sonst mindestens 7 Tage, damit Fehlkonfigurationen keine frischen Einträge löschen.
            $days[$key] = $value <= 0 ? 0 : max(self::MIN_DAYS, min(self::MAX_DAYS, $value));
        }

        return $days;
    }

    /**
     * @return list<RetentionRule>
     */
    public static function rules(): array
    {
        return [
            new RetentionRule(
                self::QUEUE_RULE_KEY,
                self::translate('retention.rule.mail_queue', 'Mail-Queue (erledigte Jobs)'),
                self::translate(
                    'retention.rule.mail_queue_desc',
                    'Versendete und endgültig fehlgeschlagene E-Mails samt Inhalt werden nach der Frist gelöscht; offene Jobs bleiben bestehen.'
                ),
                static fn (): int => self::days()[self::QUEUE_RULE_KEY],
                static fn (): int => MailQueueService::getInstance()->countPrunableJobs(self::days()[self::QUEUE_RULE_KEY]),
                static fn (): int => MailQueueService::getInstance()->pruneFinishedJobs(self::days()[self::QUEUE_RULE_KEY]),
                self::SETTINGS_URL,
            ),
            new RetentionRule(
                self::LOG_RULE_KEY,
                self::translate('retention.rule.mail_log', 'Mail-Protokoll'),
                self::translate('retention.rule.mail_log_desc', 'Einträge des Mail-Versandprotokolls werden nach der Frist gelöscht.'),
                static fn (): int => self::days()[self::LOG_RULE_KEY],
                static function (): int {
                    $db = Database::instance();

                    return (int) $db->get_var(
                        'SELECT COUNT(*) FROM ' . $db->getPrefix() . 'mail_log WHERE ' . self::LOG_WHERE,
                        [self::days()[self::LOG_RULE_KEY]]
                    );
                },
                static fn (): int => DataRetentionService::getInstance()->deleteInBatches(
                    'mail_log',
                    self::LOG_WHERE,
                    [self::days()[self::LOG_RULE_KEY]]
                ),
                self::SETTINGS_URL,
            ),
        ];
    }

    private static function translate(string $key, string $fallback): string
    {
        if (!function_exists('__')) {
            return $fallback;
        }

        $translated = (string) __($key);

        return $translated === $key ? $fallback : $translated;
    }
}
