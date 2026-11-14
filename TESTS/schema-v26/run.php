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
    $expected = ['password_history', 'user_identities', 'backup_offsite_uploads', 'webhook_endpoints', 'webhook_deliveries'];
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

T::finish();
