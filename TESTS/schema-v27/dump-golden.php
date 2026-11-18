<?php
/**
 * Erzeugt TESTS/schema-v27/golden.json neu: die kanonische Struktur der 27 Tabellen `community_*`, gelesen aus
 * `information_schema` einer echten MariaDB/MySQL, in die die Definitionen aus `SchemaManager::getSchemaQueries()` eingespielt
 * wurden. Das ist die Gegenprobe für TESTS/schema-v27 (Definition = Snapshot = Doku) und TESTS/schema-upgrade (Datenbank =
 * Snapshot). Eine Änderung am Schema ändert die Datei bewusst und sichtbar im Diff.
 *
 *   CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/schema-v27/dump-golden.php
 *
 * Danach die Tabellen in HANDOFF-PR2-PR5.md anpassen (der Test vergleicht beides) und `php TESTS/schema-v27/run.php` ausführen.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$host = (string) getenv('CMS_TEST_DB_HOST');
$user = (string) getenv('CMS_TEST_DB_USER');
if ($host === '' || $user === '') {
    fwrite(STDERR, "CMS_TEST_DB_HOST und CMS_TEST_DB_USER setzen (Recht CREATE/DROP DATABASE).\n");
    exit(2);
}

$root = dirname(__DIR__, 2) . '/CMS/';
define('ABSPATH', $root);
require_once $root . 'core/SchemaManager.php';

$pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $user, (string) getenv('CMS_TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'cms_golden_' . getmypid();
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $pdo->exec("USE `{$database}`");
    $queries = \CMS\SchemaManager::getSchemaQueries('cms_', 'utf8mb4');
    foreach (\CMS\SchemaManager::FEATURE_TABLES_V27 as $table) {
        $pdo->exec($queries[$table]);
    }
    $golden = v27ReadStructure($pdo, $database, 'cms_', \CMS\SchemaManager::FEATURE_TABLES_V27);
} finally {
    $pdo->exec("DROP DATABASE `{$database}`");
}

// Eine Zeile je Spalte und je Index: lesbare Diffs.
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$lines = [];
foreach ($golden as $table => $structure) {
    $columns = implode(",\n", array_map(static fn (array $c): string => '            ' . json_encode($c, $flags), $structure['columns']));
    $indexes = implode(",\n", array_map(static fn (string $name, array $i): string => '            ' . json_encode($name, $flags) . ': ' . json_encode($i, $flags), array_keys($structure['indexes']), $structure['indexes']));
    $lines[] = '    ' . json_encode($table, $flags) . ": {\n        \"columns\": [\n" . $columns . "\n        ],\n        \"primary\": " . json_encode($structure['primary'], $flags) . ",\n        \"indexes\": {\n" . $indexes . "\n        }\n    }";
}
file_put_contents(__DIR__ . '/golden.json', "{\n" . implode(",\n", $lines) . "\n}\n");
echo 'golden.json: ' . count($golden) . ' Tabellen, ' . array_sum(array_map(static fn (array $t): int => count($t['columns']), $golden)) . " Spalten\n";
