<?php
/**
 * MigrationManager – inkrementelle Spalten-Migrationen (H-10)
 *
 * Verantwortlich für:
 * - Hinzufügen fehlender Spalten / Indizes zu bestehenden Tabellen (ALTER TABLE)
 * - Öffentliche repairTables()-Methode (Diagnose → Tabellen-Reparatur)
 *
 * Ausgelagert aus Database.php, um die God-Klasse aufzuteilen.
 *
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

use PDOException;

if (!defined('ABSPATH')) {
    exit;
}

class MigrationManager
{
    private Database $db;
    private string $prefix;

    /**
     * Aktuelle Schema-Version – erhöhen wenn neue Migrations hinzukommen.
     * Wird in cms_settings (option_name = 'db_schema_version') gespeichert.
     */
    public const SCHEMA_VERSION = 'v25';

    public function __construct(Database $db)
    {
        $this->db     = $db;
        $this->prefix = $db->getPrefix();
    }

    /**
     * Vollständige DB-Reparatur:
     * - Flag löschen → createTables() wird erneut ausgeführt
     * - Migrations laufen sowieso durch createTables → hier nur repairTables-Wrapper
     */
    public function repairTables(): void
    {
        $schemaManager = new SchemaManager($this->db);

        if (method_exists($schemaManager, 'clearFlag')) {
            $schemaManager->clearFlag();
        } elseif (method_exists($schemaManager, 'getFlagFile')) {
            $flagFile = $schemaManager->getFlagFile();
            if (is_string($flagFile) && $flagFile !== '' && file_exists($flagFile)) {
                @unlink($flagFile);
            }
        }

        $schemaManager->createTables();
    }

    /**
     * Führt alle inkrementellen Migrations-SQLs aus.
     * Idempotent: PDOException für bereits vorhandene Spalten / Duplikat-Keys werden ignoriert.
     * Versionsprüfung: läuft nur einmal bis SCHEMA_VERSION erreicht ist (1 DB-Query auf
     * normalen Requests statt vieler ALTER TABLE Statements).
     */
    public function run(): void
    {
        $currentVersion = $this->getCurrentVersion();
        if ($currentVersion !== '' && preg_match('/^v\d+$/i', $currentVersion) !== 1) {
            error_log('MigrationManager: Unbekannte Schema-Version wird nicht automatisch überschrieben: ' . $currentVersion);
            return;
        }
        // Höhere bekannte Schema-Marker dürfen bei älterem Code niemals überschrieben werden.
        if ($this->compareSchemaVersions($currentVersion, self::SCHEMA_VERSION) >= 0) {
            return;
        }

        $p   = $this->prefix;
        $pdo = $this->db->getPdo();
        $charset = defined('DB_CHARSET') ? (string) DB_CHARSET : 'utf8mb4';

        $migrations = [
            "CREATE TABLE IF NOT EXISTS `{$p}passkey_credentials` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `credential_id` VARCHAR(512) NOT NULL,
                `public_key` TEXT NOT NULL,
                `sign_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `aaguid` VARCHAR(64) DEFAULT NULL,
                `attestation_fmt` VARCHAR(32) DEFAULT NULL,
                `name` VARCHAR(128) DEFAULT '',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `last_used_at` TIMESTAMP NULL DEFAULT NULL,
                INDEX `idx_user` (`user_id`),
                UNIQUE INDEX `idx_cred_id` (`credential_id`(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}mail_log` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `recipient` VARCHAR(255) NOT NULL,
                `subject` VARCHAR(255) NOT NULL,
                `status` ENUM('sent','failed') NOT NULL DEFAULT 'sent',
                `transport` VARCHAR(50) NOT NULL DEFAULT 'smtp',
                `provider` VARCHAR(50) NOT NULL DEFAULT 'default',
                `message_id` VARCHAR(255) DEFAULT NULL,
                `error_message` TEXT DEFAULT NULL,
                `meta` LONGTEXT,
                `source` VARCHAR(100) NOT NULL DEFAULT 'system',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_status` (`status`),
                INDEX `idx_recipient` (`recipient`),
                INDEX `idx_created_at` (`created_at`),
                INDEX `idx_source` (`source`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}mail_queue` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `recipient` VARCHAR(255) NOT NULL,
                `subject` VARCHAR(255) NOT NULL,
                `body` LONGTEXT,
                `headers` LONGTEXT,
                `content_type` VARCHAR(20) NOT NULL DEFAULT 'html',
                `source` VARCHAR(100) NOT NULL DEFAULT 'system',
                `status` ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
                `available_at` DATETIME DEFAULT NULL,
                `sent_at` DATETIME DEFAULT NULL,
                `locked_at` DATETIME DEFAULT NULL,
                `last_attempt_at` DATETIME DEFAULT NULL,
                `attachment_path` VARCHAR(500) DEFAULT NULL,
                `attachment_name` VARCHAR(255) DEFAULT NULL,
                `attachment_mime` VARCHAR(150) DEFAULT NULL,
                `error_category` VARCHAR(50) DEFAULT NULL,
                `last_error` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_status` (`status`),
                INDEX `idx_status_available` (`status`, `available_at`),
                INDEX `idx_available_at` (`available_at`),
                INDEX `idx_locked_at` (`locked_at`),
                INDEX `idx_error_category` (`error_category`),
                INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "ALTER TABLE `{$p}mail_queue` ADD COLUMN `content_type` VARCHAR(20) NOT NULL DEFAULT 'html' AFTER `headers`",
            "ALTER TABLE `{$p}mail_queue` ADD COLUMN `source` VARCHAR(100) NOT NULL DEFAULT 'system' AFTER `content_type`",
            "ALTER TABLE `{$p}mail_queue` ADD COLUMN `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5 AFTER `attempts`",
            "ALTER TABLE `{$p}mail_queue` ADD COLUMN `locked_at` DATETIME DEFAULT NULL AFTER `sent_at`",
            "ALTER TABLE `{$p}mail_queue` ADD COLUMN `last_attempt_at` DATETIME DEFAULT NULL AFTER `locked_at`",
            "ALTER TABLE `{$p}mail_queue` ADD COLUMN `attachment_path` VARCHAR(500) DEFAULT NULL AFTER `last_attempt_at`",
            "ALTER TABLE `{$p}mail_queue` ADD COLUMN `attachment_name` VARCHAR(255) DEFAULT NULL AFTER `attachment_path`",
            "ALTER TABLE `{$p}mail_queue` ADD COLUMN `attachment_mime` VARCHAR(150) DEFAULT NULL AFTER `attachment_name`",
            "ALTER TABLE `{$p}mail_queue` ADD COLUMN `error_category` VARCHAR(50) DEFAULT NULL AFTER `attachment_mime`",
            "ALTER TABLE `{$p}mail_queue` ADD INDEX `idx_status_available` (`status`, `available_at`)",
            "ALTER TABLE `{$p}mail_queue` ADD INDEX `idx_locked_at` (`locked_at`)",
            "ALTER TABLE `{$p}mail_queue` ADD INDEX `idx_error_category` (`error_category`)",
            // RBAC: member_dashboard_access on roles
            "ALTER TABLE `{$p}roles` ADD COLUMN `member_dashboard_access` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Zugriff auf Member-Dashboard' AFTER `capabilities`",
            // RBAC: sort_order on roles
            "ALTER TABLE `{$p}roles` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `member_dashboard_access`",
            // Groups: role_id on user_groups
            "ALTER TABLE `{$p}user_groups` ADD COLUMN `role_id` INT UNSIGNED NULL COMMENT 'Verknüpfte RBAC-Rolle' AFTER `description`",
            // H-05: action-Feld für DB-basiertes Rate-Limiting (Schema v5)
            "ALTER TABLE `{$p}login_attempts` ADD COLUMN `action` VARCHAR(30) NOT NULL DEFAULT 'login' AFTER `ip_address`",
            "ALTER TABLE `{$p}login_attempts` ADD INDEX `idx_ip_action` (`ip_address`, `action`)",
            "ALTER TABLE `{$p}login_attempts` ADD INDEX `idx_action` (`action`)",
            // H-05: veraltete success-Spalte entfernen (falls aus alter Installation vorhanden)
            "ALTER TABLE `{$p}login_attempts` DROP COLUMN `success`",
            "ALTER TABLE `{$p}login_attempts` DROP INDEX `idx_success`",
            // H-02: UNIQUE-Key auf user_meta(user_id, meta_key) für ON DUPLICATE KEY UPDATE (Schema v6)
            "ALTER TABLE `{$p}user_meta` ADD UNIQUE KEY `uq_user_meta` (`user_id`, `meta_key`)",
            // H-02: User-Display-Name bei alten Tabellen (falls fehlend)
            "ALTER TABLE `{$p}users` ADD COLUMN `display_name` VARCHAR(100) NOT NULL AFTER `password`",
            // H-01: Audit-Log Action-Spalte (Fix für fehlende Spalte bei Update von alter Version)
            "ALTER TABLE `{$p}audit_log` ADD COLUMN `action` VARCHAR(100) NOT NULL DEFAULT 'unknown' AFTER `category`",
            "ALTER TABLE `{$p}audit_log` ADD INDEX `idx_action` (`action`)",
                        // v12: published_at für Alt-Datensätze nachziehen, damit Sortierung/Datumsanzeige konsistent bleibt
                        "UPDATE `{$p}posts`
                         SET `published_at` = COALESCE(`created_at`, NOW())
                         WHERE `status` = 'published'
                             AND `published_at` IS NULL",
            // v13: Notification-Center liest pro User chronologisch absteigend.
            "ALTER TABLE `{$p}notifications` ADD INDEX `idx_user_created` (`user_id`, `created_at`)",
            // v23: Sprachspalten wurden bisher nur von PostsModule::ensureColumns() (Admin) ergänzt –
            // Blog und API brachen auf frischen Installationen mit "Unknown column 'title_en'" ab.
            "ALTER TABLE `{$p}posts` ADD COLUMN `title_en` VARCHAR(255) DEFAULT NULL AFTER `title`",
            "ALTER TABLE `{$p}posts` ADD COLUMN `content_en` LONGTEXT DEFAULT NULL AFTER `content`",
            "ALTER TABLE `{$p}posts` ADD COLUMN `excerpt_en` TEXT DEFAULT NULL AFTER `excerpt`",
            // v23: Blog-, Archiv- und Feed-Listen filtern auf status und sortieren nach published_at.
            "ALTER TABLE `{$p}posts` ADD INDEX `idx_status_published` (`status`, `published_at`)",
            // v23: idx_key doppelte den UNIQUE-Index auf option_name und kostete nur Schreibzeit.
            "ALTER TABLE `{$p}settings` DROP INDEX `idx_key`",
            // v24: Revisionen im Beitragseditor (PostRevisionService). Erfasst werden zusätzlich
            // Revisionstyp (manual/autosave/restore/baseline; Bestandszeilen = legacy), der auslösende
            // Benutzer, Beitragsbild und Meta-Felder sowie ein Fingerabdruck gegen Duplikate.
            "ALTER TABLE `{$p}post_revisions` ADD COLUMN `revision_type` VARCHAR(20) NOT NULL DEFAULT 'legacy'",
            "ALTER TABLE `{$p}post_revisions` ADD COLUMN `created_by` INT UNSIGNED DEFAULT NULL",
            "ALTER TABLE `{$p}post_revisions` ADD COLUMN `featured_image` VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE `{$p}post_revisions` ADD COLUMN `meta_title` VARCHAR(255) DEFAULT NULL",
            "ALTER TABLE `{$p}post_revisions` ADD COLUMN `meta_description` TEXT DEFAULT NULL",
            "ALTER TABLE `{$p}post_revisions` ADD COLUMN `fingerprint` CHAR(40) DEFAULT NULL",
            // v25: eigene Admin-Sidebar-Layouts (AdminMenu\LayoutRepository) und lange Admin-Aufgaben
            // (BatchJobService). Die Definitionen stammen aus SchemaManager, damit Neuinstallation, Update
            // und Laufzeitprüfung dieselben Tabellen anlegen.
            SchemaManager::getSchemaQueries($p, $charset)['admin_menu_layouts'],
            SchemaManager::getSchemaQueries($p, $charset)['batch_jobs'],
            // v25: Papierkorb (TrashProvider) für Seiten, Beiträge und Hub-Sites. Seiten und Beiträge
            // merken sich den vorherigen Status; Hub-Sites (site_tables) haben keinen, dort markiert
            // trashed_at den Papierkorb.
            "ALTER TABLE `{$p}posts` ADD COLUMN `previous_status` VARCHAR(20) DEFAULT NULL",
            "ALTER TABLE `{$p}posts` ADD COLUMN `trashed_at` DATETIME DEFAULT NULL",
            "ALTER TABLE `{$p}posts` ADD COLUMN `trashed_by` INT UNSIGNED DEFAULT NULL",
            "ALTER TABLE `{$p}posts` ADD INDEX `idx_trashed_at` (`trashed_at`)",
            "ALTER TABLE `{$p}pages` ADD COLUMN `previous_status` VARCHAR(20) DEFAULT NULL",
            "ALTER TABLE `{$p}pages` ADD COLUMN `trashed_at` DATETIME DEFAULT NULL",
            "ALTER TABLE `{$p}pages` ADD COLUMN `trashed_by` INT UNSIGNED DEFAULT NULL",
            "ALTER TABLE `{$p}pages` ADD INDEX `idx_trashed_at` (`trashed_at`)",
            "ALTER TABLE `{$p}site_tables` ADD COLUMN `trashed_at` DATETIME DEFAULT NULL",
            "ALTER TABLE `{$p}site_tables` ADD COLUMN `trashed_by` INT UNSIGNED DEFAULT NULL",
            "ALTER TABLE `{$p}site_tables` ADD INDEX `idx_trashed_at` (`trashed_at`)",
            // v25: Redaktionskalender – „Geplantes Datum“ für Entwürfe (rein redaktionell, veröffentlicht nichts).
            "ALTER TABLE `{$p}posts` ADD COLUMN `planned_at` DATETIME DEFAULT NULL",
            "ALTER TABLE `{$p}posts` ADD INDEX `idx_planned_at` (`planned_at`)",
            "ALTER TABLE `{$p}pages` ADD COLUMN `planned_at` DATETIME DEFAULT NULL",
            "ALTER TABLE `{$p}pages` ADD INDEX `idx_planned_at` (`planned_at`)",
            // v25: Auslöser einer Seitenrevision (Übersicht „Letzte Änderungen“).
            "ALTER TABLE `{$p}page_revisions` ADD COLUMN `created_by` INT UNSIGNED DEFAULT NULL",
        ];

        // v25: Tabellen der in den Core übernommenen Funktionen (Formulare, Downloads, Feed-Aggregator) –
        // dieselben Definitionen wie bei der Neuinstallation, in Abhängigkeitsreihenfolge.
        $schemaQueries = SchemaManager::getSchemaQueries($p, $charset);
        foreach (SchemaManager::FEATURE_TABLES as $featureTable) {
            if (isset($schemaQueries[$featureTable])) {
                $migrations[] = $schemaQueries[$featureTable];
            }
        }

        foreach ($migrations as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                // Ignorierte Errors: Spalte/Key existiert bereits, Tabelle existiert noch nicht,
                // Spalte existiert nicht (DROP auf nicht vorhandene Spalte), Check constraint
                if (
                    !str_contains($msg, 'Duplicate column') &&
                    !str_contains($msg, 'Duplicate key name') &&
                    !str_contains($msg, 'already exists') &&
                    !str_contains($msg, "doesn't exist") &&
                    !str_contains($msg, 'Multiple definition') &&
                    !str_contains($msg, "Can't DROP") &&
                    !str_contains($msg, 'check that column/key exists')
                ) {
                    error_log('MigrationManager: ' . $msg);
                }
            }
        }

        // Version nach erfolgreichem Durchlauf persistieren
        $this->saveVersion(self::SCHEMA_VERSION);
    }

    /**
     * Liest die aktuelle Schema-Version aus der Settings-Tabelle.
     */
    private function getCurrentVersion(): string
    {
        try {
            $stmt = $this->db->getPdo()->prepare(
                "SELECT option_value FROM `{$this->prefix}settings` WHERE option_name = 'db_schema_version' LIMIT 1"
            );
            $stmt->execute();
            return (string)($stmt->fetchColumn() ?: '');
        } catch (\Throwable) {
            return ''; // Settings-Tabelle existiert noch nicht → Migration muss laufen
        }
    }

    /**
     * Speichert die Schema-Version in der Settings-Tabelle.
     */
    private function saveVersion(string $version): void
    {
        try {
            $this->db->getPdo()->exec(
                "INSERT INTO `{$this->prefix}settings` (option_name, option_value, autoload)
                 VALUES ('db_schema_version', " . $this->db->getPdo()->quote($version) . ", 0)
                 ON DUPLICATE KEY UPDATE option_value = " . $this->db->getPdo()->quote($version)
            );
        } catch (\Throwable) {
            // Nicht kritisch – beim nächsten Request wird erneut versucht
        }
    }

    private function compareSchemaVersions(string $left, string $right): int
    {
        $leftNumber = preg_match('/^v(\d+)$/i', trim($left), $leftMatches) === 1 ? (int) $leftMatches[1] : null;
        $rightNumber = preg_match('/^v(\d+)$/i', trim($right), $rightMatches) === 1 ? (int) $rightMatches[1] : null;

        if ($leftNumber !== null && $rightNumber !== null) {
            return $leftNumber <=> $rightNumber;
        }

        return $left === $right ? 0 : -1;
    }

}
