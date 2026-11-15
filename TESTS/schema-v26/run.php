<?php
/**
 * Schema v26 (Release 4.1.0): Neuinstallation, Update und Laufzeitprüfung legen dieselben Tabellen und Spalten an.
 *
 * Statisch, ohne Datenbank: prüft die Definitionen in SchemaManager::getSchemaQueries(), die Liste FEATURE_TABLES_V26,
 * die ALTER-Schritte im MigrationManager und die Laufzeit-Ergänzung in SchemaManager::ensureFeatureSchema().
 *
 * Aufruf: php TESTS/schema-v26/run.php [-v]
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';
T::$verbose = in_array('-v', $argv ?? [], true);

$root = dirname(__DIR__, 2) . '/CMS/';
if (!defined('ABSPATH')) {
    define('ABSPATH', $root);
}
require_once $root . 'core/SchemaManager.php';

$schemaSource = (string) file_get_contents($root . 'core/SchemaManager.php');
$migrationSource = (string) file_get_contents($root . 'core/MigrationManager.php');
$queries = \CMS\SchemaManager::getSchemaQueries('cms_', 'utf8mb4');

T::run('Versionsstand', static function () use ($migrationSource, $root): void {
    // Die Marke wird erst mit der Release-Vorbereitung 4.1.0 gehoben; bis dahin müssen SchemaManager, MigrationManager
    // und Marketplace-Manifest übereinstimmen (Manifest beschreibt das veröffentlichte Paket).
    $version = \CMS\SchemaManager::SCHEMA_VERSION;
    T::check(in_array($version, ['v25', 'v26'], true), 'SchemaManager::SCHEMA_VERSION ist v25 (Entwicklung) oder v26 (Release 4.1.0)');
    T::check(str_contains($migrationSource, "public const SCHEMA_VERSION = '{$version}';"), 'MigrationManager nennt dieselbe Marke');
    $manifest = json_decode((string) file_get_contents($root . 'marketplace/core/365cms/update.json'), true);
    T::same($version, (string) ($manifest['schema_version'] ?? ''), 'Marketplace-Manifest nennt dieselbe Marke');
});

T::run('Neue Tabellen', static function () use ($queries, $migrationSource, $schemaSource): void {
    $expected = ['password_history', 'user_identities', 'media_usage', 'content_workflow_events', 'content_preview_links', 'reusable_blocks', 'reusable_block_usage', 'reusable_block_revisions', 'backup_offsite_uploads', 'webhook_endpoints', 'webhook_deliveries', 'payment_events', 'invoice_sequences', 'invoices'];
    T::same($expected, \CMS\SchemaManager::FEATURE_TABLES_V26, 'FEATURE_TABLES_V26 enthält genau die v26-Tabellen');
    foreach ($expected as $table) {
        T::check(isset($queries[$table]), "Definition für {$table} vorhanden (Neuinstallation)");
        T::check(str_contains((string) ($queries[$table] ?? ''), "CREATE TABLE IF NOT EXISTS cms_{$table} ("), "{$table}: idempotentes CREATE mit Präfix");
        T::check(!preg_match('/\bJSON\b(?!_)/', (string) ($queries[$table] ?? '')), "{$table}: kein Spaltentyp JSON (LONGTEXT *_json, MariaDB)");
    }
    T::check(str_contains($migrationSource, 'array_merge(SchemaManager::FEATURE_TABLES, SchemaManager::FEATURE_TABLES_V26)'), 'Update legt die v26-Tabellen über dieselben Definitionen an');
    T::check(str_contains($schemaSource, 'array_merge(self::FEATURE_TABLES, self::FEATURE_TABLES_V26)'), 'Laufzeitprüfung legt die v26-Tabellen über dieselben Definitionen an');
});

T::run('Sicherheitsrelevante Schlüssel', static function () use ($queries): void {
    $identities = (string) $queries['user_identities'];
    T::check(str_contains($identities, 'UNIQUE KEY uq_user_identities_subject (provider, tenant_id, subject)'), 'SSO-Bindung eindeutig über provider + tenant_id + subject (GHSA-6hhv-gc4g-f4r7)');
    T::check(str_contains($identities, 'UNIQUE KEY uq_user_identities_user_provider (user_id, provider)'), 'höchstens eine Bindung je Konto und Anbieter');
    T::check(!preg_match('/token/i', $identities), 'user_identities speichert keine Tokens');
    $usage = (string) $queries['media_usage'];
    T::check(str_contains($usage, 'PRIMARY KEY (media_path(191), content_type, content_id, context)'), 'media_usage: eine Zeile je Medium, Inhalt und Kontext');
    T::check(str_contains($usage, 'KEY idx_media_usage_content (content_type, content_id)'), 'media_usage: Index je Inhalt (Neuindizieren/Löschen)');
    T::check(str_contains((string) $queries['webhook_endpoints'], 'secret_encrypted TEXT NOT NULL'), 'Webhook-Geheimnis nur verschlüsselt');
    T::check(str_contains((string) $queries['webhook_deliveries'], 'UNIQUE KEY uq_webhook_deliveries_uuid (delivery_uuid)'), 'Zustellung eindeutig je UUID');
    T::check(str_contains((string) $queries['password_history'], 'password_hash VARCHAR(255) NOT NULL'), 'Passwort-Historie speichert Hashes');
});

T::run('Neue Spalten', static function () use ($queries, $migrationSource, $schemaSource): void {
    $columns = [
        'users' => ['password_changed_at', 'DATETIME NULL DEFAULT NULL'],
        'passkey_credentials' => ['user_verified', 'TINYINT(1) NULL DEFAULT NULL'],
    ];
    foreach ($columns as $table => [$column, $type]) {
        T::check(str_contains((string) $queries[$table], "{$column} {$type}"), "{$table}.{$column} in der Neuinstallation");
        T::check(str_contains($migrationSource, "ALTER TABLE `{\$p}{$table}` ADD COLUMN `{$column}` {$type}"), "{$table}.{$column} im Update");
        T::check(str_contains($schemaSource, "ALTER TABLE {\$this->prefix}{$table} ADD COLUMN {$column} {$type}"), "{$table}.{$column} in der Laufzeitprüfung");
    }
});

T::run('Audit 4.1.0: Konstruktor ohne Rekursion, Integritätsprüfung, Reparatur (DB-410-02/04/05)', static function () use ($root, $queries, $migrationSource, $schemaSource): void {
    // DB-410-02: Im Konstruktor von Database (SchemaManager/MigrationManager) nie Database::instance() aufrufen.
    $roles = (string) file_get_contents($root . 'includes/functions/roles.php');
    T::check(str_contains($roles, 'function cms_ensure_role_permissions_table(?\CMS\Database $db = null): void {') && str_contains($roles, '$db ??= \CMS\Database::instance();'), 'cms_ensure_role_permissions_table() nimmt die Verbindung entgegen');
    foreach (['SchemaManager' => $schemaSource, 'MigrationManager' => $migrationSource] as $name => $source) {
        T::check(!str_contains($source, 'cms_ensure_role_permissions_table();') && str_contains($source, 'cms_ensure_role_permissions_table($this->db);'), "{$name} übergibt die eigene Verbindung");
        T::check(!preg_match('/Database::instance\(\)|Database::get_instance\(\)/', $source), "{$name} ruft Database::instance() nicht auf");
    }

    // DB-410-04: Pflichtspalten gehören zu Tabellen, die Neuinstallation oder Laufzeitprüfung anlegen.
    $runtimeOnly = ['has_locale_de', 'has_locale_en'];
    foreach (\CMS\SchemaManager::REQUIRED_COLUMNS as $table => $columns) {
        T::check(isset($queries[$table]), "Pflichttabelle {$table} hat eine CREATE-Definition");
        foreach ($columns as $column) {
            $inCreate = preg_match('/\n\s+' . preg_quote($column, '/') . '\s/', (string) ($queries[$table] ?? '')) === 1;
            T::check($inCreate || in_array($column, $runtimeOnly, true), "{$table}.{$column} in der Neuinstallation");
        }
    }
    $runner = (string) file_get_contents($root . 'core/DatabaseUpdateRunner.php');
    T::check(str_contains($runner, 'public function missingSchemaElements(): array') && !str_contains($runner, 'isTargetSchemaReady'), 'Datenbank-Update prüft das ganze Zielschema (nicht nur ai_quota_usage)');
    T::check(str_contains($runner, "'schema_missing' => \$schemaMissing"), 'Status nennt fehlende Elemente');
    T::check(str_contains($runner, 'SchemaManager::FEATURE_TABLES, SchemaManager::FEATURE_TABLES_V26, array_keys(SchemaManager::REQUIRED_COLUMNS)'), 'alle Feature-Tabellen v25/v26 geprüft');

    // DB-410-05: Reparatur läuft auch bei gespeicherter Zielversion.
    T::check(str_contains($migrationSource, 'public function run(bool $force = false): void') && str_contains($migrationSource, '$this->run(true);'), 'repairTables() erzwingt die idempotenten Migrationen');
    T::check(str_contains($migrationSource, 'if ($comparison > 0 || ($comparison === 0 && !$force)) {'), 'höhere Schema-Marke wird nie überschrieben, gleiche nur bei Reparatur');
});

T::finish();
