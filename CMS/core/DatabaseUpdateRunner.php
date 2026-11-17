<?php
declare(strict_types=1);

/**
 * Versionsgeführter Datenbank-Updater für bestehende 365CMS-Installationen.
 *
 * Führt ausschließlich den zentralen, idempotenten Schema-Reparaturpfad aus.
 * Inhalte, Benutzer und Einstellungen werden nicht gelöscht.
 *
 * @package CMSv2\Core
 */

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

final class DatabaseUpdateRunner
{
    private const INSTALLED_VERSION_OPTION = 'installed_cms_version';
    private const INSTALLED_AT_OPTION = 'installed_cms_updated_at';
    private const INSTALLED_SCHEMA_OPTION = 'installed_cms_schema_version';
    private const DATABASE_SCHEMA_OPTION = 'db_schema_version';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{
     *     installed_version:string,
     *     target_version:string,
     *     installed_schema_version:string,
     *     target_schema_version:string,
     *     version_update_required:bool,
     *     schema_update_required:bool,
    *     downgrade_detected:bool,
    *     schema_downgrade_detected:bool,
    *     schema_integrity_ok:bool,
    *     schema_missing:list<string>
     * }
     */
    public function getStatus(): array
    {
        $installedVersion = $this->readSetting(self::INSTALLED_VERSION_OPTION);
        $installedSchemaVersion = $this->resolveHighestSchemaVersion(
            $this->readSetting(self::DATABASE_SCHEMA_OPTION),
            $this->readSetting(self::INSTALLED_SCHEMA_OPTION)
        );
        $targetVersion = Version::CURRENT;
        $targetSchemaVersion = SchemaManager::SCHEMA_VERSION;
        $coreDowngradeDetected = $installedVersion !== ''
            && version_compare($installedVersion, $targetVersion, '>');
        $schemaDowngradeDetected = $installedSchemaVersion !== ''
            && $this->compareSchemaVersions($installedSchemaVersion, $targetSchemaVersion) > 0;
        $schemaMissing = $schemaDowngradeDetected ? [] : $this->missingSchemaElements();
        $schemaIntegrityOk = !$schemaDowngradeDetected && $schemaMissing === [];

        return [
            'installed_version' => $installedVersion,
            'target_version' => $targetVersion,
            'installed_schema_version' => $installedSchemaVersion,
            'target_schema_version' => $targetSchemaVersion,
            'version_update_required' => $installedVersion === ''
                || version_compare($targetVersion, $installedVersion, '>'),
            'schema_update_required' => !$schemaDowngradeDetected && (
                $installedSchemaVersion === ''
                || $this->compareSchemaVersions($targetSchemaVersion, $installedSchemaVersion) > 0
                || !$schemaIntegrityOk
            ),
            'downgrade_detected' => $coreDowngradeDetected || $schemaDowngradeDetected,
            'schema_downgrade_detected' => $schemaDowngradeDetected,
            'schema_integrity_ok' => $schemaIntegrityOk,
            'schema_missing' => $schemaMissing,
        ];
    }

    /**
     * @return array{success:bool,message:string,before:array<string,mixed>,after:array<string,mixed>}
     */
    public function run(): array
    {
        $before = $this->getStatus();

        if (!empty($before['downgrade_detected'])) {
            return [
                'success' => false,
                'message' => 'Ein Datenbank-Downgrade wird aus Sicherheitsgründen nicht automatisch ausgeführt.',
                'before' => $before,
                'after' => $before,
            ];
        }

        try {
            // repairTables() löscht nur die Schema-Flag-Datei und führt die zentralen,
            // idempotenten CREATE-/ALTER-Migrationen erneut aus. Es löscht keine Daten.
            $this->db->repairTables();
            $missing = $this->missingSchemaElements();
            if ($missing !== []) {
                throw new \RuntimeException('Das Zielschema ist nach der Migration nicht vollständig verfügbar: ' . implode(', ', array_slice($missing, 0, 20)));
            }

            $this->writeSetting(self::INSTALLED_VERSION_OPTION, Version::CURRENT);
            $this->writeSetting(self::INSTALLED_AT_OPTION, date(DATE_ATOM));
            $this->writeSetting(self::INSTALLED_SCHEMA_OPTION, SchemaManager::SCHEMA_VERSION);
            $this->writeSetting(self::DATABASE_SCHEMA_OPTION, SchemaManager::SCHEMA_VERSION);

            $after = $this->getStatus();

            try {
                \CMS\Services\UpdateService::getInstance()->logUpdate(
                    'schema',
                    '365CMS Datenbankschema',
                    Version::CURRENT
                );
            } catch (\Throwable) {
                // Das Schema-Update selbst bleibt erfolgreich, wenn die optionale Historie scheitert.
            }

            return [
                'success' => true,
                'message' => 'Datenbankschema und Installationsversion wurden erfolgreich aktualisiert.',
                'before' => $before,
                'after' => $after,
            ];
        } catch (\Throwable $e) {
            error_log('DatabaseUpdateRunner::run failed: ' . $this->sanitizeDiagnosticText($e->getMessage()));

            return [
                'success' => false,
                'message' => 'Das Datenbank-Update konnte nicht abgeschlossen werden. Bitte die CMS-Logs prüfen.',
                'before' => $before,
                'after' => $this->getStatus(),
            ];
        }
    }

    private function readSetting(string $optionName): string
    {
        if (!$this->db->tableExists($this->db->getPrefix() . 'settings')) {
            return '';
        }

        try {
            return trim((string) $this->db->get_var(
                "SELECT option_value FROM {$this->db->getPrefix()}settings WHERE option_name = ? LIMIT 1",
                [$optionName]
            ));
        } catch (\Throwable) {
            return '';
        }
    }

    private function writeSetting(string $optionName, string $value): void
    {
        $this->db->execute(
            "INSERT INTO {$this->db->getPrefix()}settings (option_name, option_value, autoload)
             VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
            [$optionName, $value]
        );
    }

    private function compareSchemaVersions(string $left, string $right): int
    {
        $leftVersion = $this->extractSchemaVersionNumber($left);
        $rightVersion = $this->extractSchemaVersionNumber($right);

        if ($leftVersion !== null && $rightVersion !== null) {
            return $leftVersion <=> $rightVersion;
        }

        return version_compare($left, $right);
    }

    private function extractSchemaVersionNumber(string $version): ?int
    {
        return preg_match('/^v(\d+)$/i', trim($version), $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function resolveHighestSchemaVersion(string $databaseSchemaVersion, string $installedSchemaVersion): string
    {
        if ($databaseSchemaVersion === '') {
            return $installedSchemaVersion;
        }
        if ($installedSchemaVersion === '') {
            return $databaseSchemaVersion;
        }

        return $this->compareSchemaVersions($databaseSchemaVersion, $installedSchemaVersion) >= 0
            ? $databaseSchemaVersion
            : $installedSchemaVersion;
    }

    /**
     * DB-410-04: Fehlende Teile des Zielschemas – alle Feature-Tabellen (v25/v26), die Pflichtspalten aus
     * SchemaManager::REQUIRED_COLUMNS und `posts.status` als VARCHAR. Bisher prüfte das Update nur `ai_quota_usage`
     * und meldete Erfolg, auch wenn v26-Tabellen fehlten. Zwei Abfragen auf information_schema.
     *
     * @return list<string> z. B. `table:webhook_deliveries`, `column:users.password_changed_at`
     */
    public function missingSchemaElements(): array
    {
        $prefix = $this->db->getPrefix();
        try {
            $tables = array_map('strval', $this->db->get_col(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
                [str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%']
            ));
            $columns = [];
            foreach ($this->db->get_results(
                'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('
                . implode(', ', array_fill(0, count(SchemaManager::REQUIRED_COLUMNS), '?')) . ')',
                array_map(static fn (string $table): string => $prefix . $table, array_keys(SchemaManager::REQUIRED_COLUMNS))
            ) as $row) {
                $columns[(string) $row->TABLE_NAME][(string) $row->COLUMN_NAME] = (string) $row->COLUMN_TYPE;
            }
        } catch (\Throwable) {
            return ['schema:unreadable'];
        }

        $known = array_flip($tables);
        $missing = [];
        foreach (array_merge(SchemaManager::FEATURE_TABLES, SchemaManager::FEATURE_TABLES_V26, SchemaManager::FEATURE_TABLES_V27, array_keys(SchemaManager::REQUIRED_COLUMNS)) as $table) {
            if (!isset($known[$prefix . $table])) {
                $missing[] = 'table:' . $table;
            }
        }
        foreach (SchemaManager::REQUIRED_COLUMNS as $table => $required) {
            if (!isset($known[$prefix . $table])) {
                continue;
            }
            foreach ($required as $column) {
                if (!isset($columns[$prefix . $table][$column])) {
                    $missing[] = 'column:' . $table . '.' . $column;
                }
            }
        }
        $statusType = $columns[$prefix . 'posts']['status'] ?? '';
        if ($statusType === '' || SchemaManager::postStatusColumnNeedsWidening($statusType)) {
            $missing[] = 'column:posts.status (VARCHAR(20))';
        }

        return array_values(array_unique($missing));
    }

    private function sanitizeDiagnosticText(string $value): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return function_exists('mb_substr') ? mb_substr($value, 0, 500, 'UTF-8') : substr($value, 0, 500);
    }
}
