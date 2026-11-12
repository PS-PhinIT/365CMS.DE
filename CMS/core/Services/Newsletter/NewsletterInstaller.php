<?php
/**
 * Schema der Newsletter-Tabellen anlegen bzw. ältere Plugin-Stände (cms-newsletter ≤ 3.x) anheben.
 *
 * Die Tabellen kommen aus `SchemaManager::FEATURE_TABLES` (eine Definition für Neuinstallation, Update und
 * Laufzeit). Für bestehende Plugin-Tabellen ergänzt `install()` die seit 4.0.0 hinzugekommenen Spalten und
 * Schlüssel, übernimmt den Plugin-Status `ready` als Entwurf und legt fehlende Standardwerte an.
 * Der Stand steht in der Option `cms_newsletter_version`; ohne Versionswechsel passiert nichts.
 *
 * @package CMSv2\Core\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\Database;
use CMS\Logger;
use CMS\SchemaManager;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterInstaller
{
    public const SCHEMA_VERSION = '4.0.0';
    public const VERSION_OPTION = 'cms_newsletter_version';
    public const TABLES = ['newsletter_subscribers', 'newsletter_templates', 'newsletter_campaigns', 'newsletter_sends', 'newsletter_settings'];

    /** @var array<string, array<string, string>> Spalte => Definition (in dieser Reihenfolge nachtragen) */
    private const ADDED_COLUMNS = [
        'newsletter_subscribers' => [
            'locale' => "VARCHAR(5) NOT NULL DEFAULT 'de' AFTER segment_slug",
            'user_id' => 'INT UNSIGNED DEFAULT NULL AFTER locale',
            'optin_sent_at' => 'DATETIME NULL AFTER optin_token',
            'consent_at' => 'DATETIME NULL AFTER optin_sent_at',
            'consent_text' => 'VARCHAR(500) DEFAULT NULL AFTER consent_at',
            'unsubscribed_at' => 'DATETIME NULL AFTER confirmed_at',
        ],
        'newsletter_campaigns' => [
            'content_html' => 'LONGTEXT NULL AFTER preview_text',
            'content_text' => 'LONGTEXT NULL AFTER content_html',
            'locale' => "VARCHAR(5) NOT NULL DEFAULT '' AFTER segment_slug",
            'is_public' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
            'started_at' => 'DATETIME NULL AFTER scheduled_at',
            'sent_count' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER recipient_count',
            'failed_count' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER sent_count',
            'created_by' => 'INT UNSIGNED DEFAULT NULL AFTER failed_count',
        ],
        'newsletter_sends' => [
            'sent_at' => 'DATETIME NULL AFTER status',
        ],
    ];

    public static function maybeUpgrade(): void
    {
        $db = Database::instance();
        $stored = (string) ($db->get_var("SELECT option_value FROM {$db->getPrefix()}settings WHERE option_name = ? LIMIT 1", [self::VERSION_OPTION]) ?? '');
        if ($stored === self::SCHEMA_VERSION && $db->tableExists($db->getPrefix() . 'newsletter_subscribers')) {
            return;
        }
        self::install();
    }

    public static function install(): void
    {
        $db = Database::instance();
        $prefix = $db->getPrefix();
        $queries = SchemaManager::getSchemaQueries($prefix, defined('DB_CHARSET') ? (string) DB_CHARSET : 'utf8mb4');
        foreach (self::TABLES as $table) {
            $db->getPdo()->exec($queries[$table]);
        }

        foreach (self::ADDED_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (!$db->columnExists($prefix . $table, $column)) {
                    $db->getPdo()->exec("ALTER TABLE {$prefix}{$table} ADD COLUMN {$column} {$definition}");
                }
            }
        }
        self::ensureIndex($prefix . 'newsletter_subscribers', 'idx_token', 'ADD KEY idx_token (optin_token)');
        self::ensureIndex($prefix . 'newsletter_subscribers', 'idx_user', 'ADD KEY idx_user (user_id)');
        self::ensureIndex($prefix . 'newsletter_campaigns', 'idx_scheduled', 'ADD KEY idx_scheduled (status, scheduled_at)');
        if (!self::indexExists($prefix . 'newsletter_sends', 'uniq_campaign_subscriber')) {
            // Doppelte Versandzeilen (im Plugin nie befüllt, aber möglich) vor dem eindeutigen Schlüssel entfernen.
            $db->getPdo()->exec("DELETE s1 FROM {$prefix}newsletter_sends s1 JOIN {$prefix}newsletter_sends s2
                ON s1.campaign_id = s2.campaign_id AND s1.subscriber_id = s2.subscriber_id AND s1.id > s2.id");
            $db->getPdo()->exec("ALTER TABLE {$prefix}newsletter_sends ADD UNIQUE KEY uniq_campaign_subscriber (campaign_id, subscriber_id)");
        }

        // Plugin-Status „ready“ gab es ohne Versand – im Core ist das ein Entwurf.
        $db->getPdo()->exec("UPDATE {$prefix}newsletter_campaigns SET status = 'draft' WHERE status = 'ready'");
        // Bestätigte Plugin-Abonnenten: Einwilligungszeitpunkt = Bestätigung, Abgemeldete ohne Zeitpunkt = letzte Änderung.
        $db->getPdo()->exec("UPDATE {$prefix}newsletter_subscribers SET consent_at = confirmed_at WHERE consent_at IS NULL AND confirmed_at IS NOT NULL");
        $db->getPdo()->exec("UPDATE {$prefix}newsletter_subscribers SET unsubscribed_at = updated_at WHERE status = 'unsubscribed' AND unsubscribed_at IS NULL");

        NewsletterSettings::seedDefaults();
        NewsletterRepository::instance()->seedDefaultTemplate();

        $db->getPdo()->prepare("INSERT INTO {$prefix}settings (option_name, option_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)")
            ->execute([self::VERSION_OPTION, self::SCHEMA_VERSION]);
        Logger::instance()->withChannel('newsletter')->info('Newsletter-Schema geprüft.', ['version' => self::SCHEMA_VERSION]);
    }

    private static function ensureIndex(string $table, string $index, string $alter): void
    {
        if (!self::indexExists($table, $index)) {
            Database::instance()->getPdo()->exec("ALTER TABLE {$table} {$alter}");
        }
    }

    private static function indexExists(string $table, string $index): bool
    {
        $stmt = Database::instance()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
