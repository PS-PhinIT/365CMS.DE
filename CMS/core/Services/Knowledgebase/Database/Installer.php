<?php

declare(strict_types=1);

namespace CMS\Services\Knowledgebase\Database;

use CMS\Database;
use CMS\Services\Knowledgebase\Service\SectionContent;
use CMS\Services\Knowledgebase\Support\ContentSections;
use CMS\Services\Knowledgebase\Support\Defaults;
use CMS\Services\Knowledgebase\Support\LoggerFactory;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema der Knowledgebase (Tabellen `kb_entries`, `kb_categories`, `kb_settings`).
 *
 * Die Tabellen heißen wie im früheren Plugin cms-knowledgebase, damit vorhandene Daten an Ort und Stelle
 * bleiben. Neuinstallation und Update legen sie über `SchemaManager::FEATURE_TABLES` an; `install()` ergänzt
 * fehlende Spalten älterer Plugin-Stände, verteilt Alt-HTML auf die Inhaltsbereiche und füllt Standardwerte.
 * Läuft einmal je SCHEMA_VERSION (Option `cms_knowledgebase_version`).
 */
final class Installer
{
    public const SCHEMA_VERSION = '4.0.0';

    private static ?self $instance = null;

    private $logger;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->logger = LoggerFactory::create();
    }

    public function install(): void
    {
        $db = Database::instance();
        $pdo = $db->getPdo();
        $prefix = $db->getPrefix();

        $queries = \CMS\SchemaManager::getSchemaQueries($prefix, defined('DB_CHARSET') ? (string) DB_CHARSET : 'utf8mb4');
        foreach (['kb_entries', 'kb_categories', 'kb_settings'] as $table) {
            $pdo->exec($queries[$table]);
        }

        $this->ensureSectionColumns();
        $this->ensureTrashColumns();
        $this->migrateLegacySectionContent();
        $this->seedDefaults();
        $this->syncCategoriesFromEntries();
        $this->storeVersion();
    }

    public function maybeUpgrade(): void
    {
        $currentVersion = $this->getStoredVersion();
        if ($currentVersion === self::SCHEMA_VERSION) {
            return;
        }

        $this->install();
        $this->logger->info('CMS Knowledgebase Schema geprüft/aktualisiert.', [
            'from' => $currentVersion,
            'to' => self::SCHEMA_VERSION,
        ]);
    }

    private function ensureSectionColumns(): void
    {
        $db = Database::instance();
        $pdo = $db->getPdo();
        $table = $db->getPrefix() . 'kb_entries';
        $previousColumn = 'content';

        // Kein "SHOW COLUMNS ... LIKE ?": SHOW-Statements unterstützen keine nativen Prepared-Statement-Platzhalter
        // (Core nutzt ATTR_EMULATE_PREPARES = false) und würden mit SQLSTATE 42000 abbrechen.
        $stmt = $db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $existingColumns = array_map('strtolower', array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []));

        foreach (ContentSections::additionalColumns() as $column) {
            if (preg_match('/^[a-z0-9_]+$/', $column) !== 1) {
                continue;
            }

            if (!in_array($column, $existingColumns, true)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} LONGTEXT DEFAULT NULL AFTER {$previousColumn}");
                $existingColumns[] = $column;
            }

            $previousColumn = $column;
        }
    }

    /** Papierkorb-Spalten (seit 4.0.0) für Tabellen aus dem früheren Plugin nachtragen. */
    private function ensureTrashColumns(): void
    {
        $db = Database::instance();
        $table = $db->getPrefix() . 'kb_entries';
        if (!$db->columnExists($table, 'trashed_at')) {
            $db->getPdo()->exec("ALTER TABLE {$table} ADD COLUMN trashed_at DATETIME NULL DEFAULT NULL AFTER max_links_per_page, ADD COLUMN trashed_by INT UNSIGNED NULL DEFAULT NULL AFTER trashed_at, ADD KEY idx_kb_trashed (trashed_at)");
        }
    }

    /**
     * Teilt Legacy-HTML aus `content` auf die strukturierten EditorJS-Bereiche auf.
     */
    private function migrateLegacySectionContent(): void
    {
        $db = Database::instance();
        $table = $db->getPrefix() . 'kb_entries';
        $additionalColumns = ContentSections::additionalColumns();
        $emptyConditions = implode(' AND ', array_map(
            static fn(string $column): string => "({$column} IS NULL OR {$column} = '')",
            $additionalColumns
        ));

        $stmt = $db->prepare("SELECT id, excerpt, content FROM {$table}
            WHERE content IS NOT NULL AND content <> '' AND content NOT LIKE '{%' AND {$emptyConditions}");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return;
        }

        $sectionContent = SectionContent::instance();
        $columns = ContentSections::columns();
        $assignments = implode(', ', array_map(static fn(string $column): string => "{$column} = :{$column}", $columns));
        $update = $db->prepare("UPDATE {$table} SET {$assignments} WHERE id = :id");
        $migrated = 0;

        foreach ($rows as $row) {
            $parts = $sectionContent->splitLegacyHtml((string) ($row['content'] ?? ''), (string) ($row['excerpt'] ?? ''));
            $params = ['id' => (int) ($row['id'] ?? 0)];
            foreach ($columns as $column) {
                $params[$column] = $sectionContent->sanitizeForStorage((string) ($parts[$column] ?? ''));
            }

            $update->execute($params);
            ++$migrated;
        }

        $this->logger->info('Knowledgebase-Inhalte in strukturierte Bereiche migriert.', ['entries' => $migrated]);
    }

    private function seedDefaults(): void
    {
        $db = Database::instance();
        $table = $db->getPrefix() . 'kb_settings';
        $stmt = $db->prepare("INSERT INTO {$table} (setting_key, setting_value)
            VALUES (:setting_key, :setting_value)
            ON DUPLICATE KEY UPDATE setting_value = setting_value");

        foreach (Defaults::settings() as $key => $value) {
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => $value,
            ]);
        }
    }

    private function syncCategoriesFromEntries(): void
    {
        $db = Database::instance();
        $entriesTable = $db->getPrefix() . 'kb_entries';
        $categoriesTable = $db->getPrefix() . 'kb_categories';
        $stmt = $db->prepare("SELECT DISTINCT category FROM {$entriesTable} WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return;
        }

        $insert = $db->prepare("INSERT INTO {$categoriesTable} (name, slug, sort_order)
            VALUES (:name, :slug, 0)
            ON DUPLICATE KEY UPDATE name = VALUES(name)");

        foreach ($rows as $row) {
            $name = trim((string) ($row['category'] ?? ''));
            if ($name === '') {
                continue;
            }

            $insert->execute([
                'name' => $name,
                'slug' => $this->slugify($name),
            ]);
        }
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
        $value = trim($value, '-');

        return mb_substr($value, 0, 140, 'UTF-8');
    }

    private function storeVersion(): void
    {
        $db = Database::instance();
        $settingsTable = $db->getPrefix() . 'settings';
        $stmt = $db->prepare("INSERT INTO {$settingsTable} (option_name, option_value, autoload)
            VALUES (:option_name, :option_value, 0)
            ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
        $stmt->execute([
            'option_name' => 'cms_knowledgebase_version',
            'option_value' => self::SCHEMA_VERSION,
        ]);
    }

    private function getStoredVersion(): string
    {
        $db = Database::instance();
        $settingsTable = $db->getPrefix() . 'settings';
        $stmt = $db->prepare("SELECT option_value FROM {$settingsTable} WHERE option_name = ? LIMIT 1");
        $stmt->execute(['cms_knowledgebase_version']);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : '';
    }
}
