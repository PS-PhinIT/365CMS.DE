<?php
/**
 * Einzelschritt für TESTS/schema-upgrade (eigener PHP-Prozess je Schritt, weil Database ein Singleton ist).
 *
 * php harness.php <cmsRoot> <dbName> <install|boot|update|status>
 *   install = wie install.php: getSchemaQueries() + Schema-Version, danach erster Request
 *   boot    = erster Request nach dem Core-Tausch (Database::instance() → automatische Migration)
 *   update  = boot + DatabaseUpdateRunner::run() (Admin → Updates → Datenbank-Update)
 *   status  = boot + DatabaseUpdateRunner::missingSchemaElements()
 *
 * Verbindung über CMS_TEST_DB_HOST, CMS_TEST_DB_USER, CMS_TEST_DB_PASS. Ausgabe: eine JSON-Zeile.
 */

declare(strict_types=1);

[$root, $dbName, $mode] = [rtrim((string) ($argv[1] ?? ''), '/') . '/', (string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')];
$host = (string) getenv('CMS_TEST_DB_HOST');
$user = (string) getenv('CMS_TEST_DB_USER');
$pass = (string) getenv('CMS_TEST_DB_PASS');

define('ABSPATH', $root);
define('CORE_PATH', $root . 'core/');
define('CMS_INSTALLER_RUNNING', true);
foreach ([
    'DB_HOST' => $host, 'DB_NAME' => $dbName, 'DB_USER' => $user, 'DB_PASS' => $pass, 'DB_PREFIX' => 'cms_', 'DB_CHARSET' => 'utf8mb4',
    'SITE_URL' => 'https://cms.example.test', 'SITE_URL_PATH' => '', 'SITE_NAME' => 'Schema-Test', 'ADMIN_EMAIL' => 'admin@example.test',
    'AUTH_KEY' => str_repeat('a', 64), 'SECURE_AUTH_KEY' => str_repeat('b', 64), 'NONCE_KEY' => str_repeat('c', 64), 'JWT_SECRET' => str_repeat('d', 64),
    'CMS_DEBUG' => false, 'LOG_PATH' => $root . 'logs/', 'THEME_PATH' => $root . 'themes/', 'PLUGIN_PATH' => $root . 'plugins/',
    'UPLOAD_PATH' => $root . 'uploads/', 'UPLOAD_URL' => '/uploads', 'ASSETS_PATH' => $root . 'assets/', 'ASSETS_URL' => '/assets',
    'DEFAULT_THEME' => 'cms-default', 'CMS_ENCRYPTION_KEY' => base64_encode(str_repeat('k', 32)),
] as $name => $value) {
    define($name, $value);
}
require_once $root . 'core/Version.php';
define('CMS_VERSION', \CMS\Version::CURRENT);
@mkdir($root . 'cache', 0755, true);
@mkdir($root . 'logs', 0755, true);
$errorLog = $root . 'logs/schema-upgrade.log';
@unlink($errorLog);
ini_set('error_log', $errorLog);
require_once $root . 'core/autoload.php';
foreach (glob($root . 'includes/functions/*.php') ?: [] as $file) {
    require_once $file;
}

$out = ['mode' => $mode];
if ($mode === 'install') {
    $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $dbName . ';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $out['install_errors'] = [];
    foreach (\CMS\SchemaManager::getSchemaQueries('cms_', 'utf8mb4') as $name => $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $out['install_errors'][] = $name . ': ' . $e->getMessage();
        }
    }
    $version = \CMS\SchemaManager::SCHEMA_VERSION;
    $stmt = $pdo->prepare('INSERT INTO cms_settings (option_name, option_value, autoload) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)');
    foreach (['db_schema_version' => $version, 'installed_cms_schema_version' => $version, 'installed_cms_version' => \CMS\Version::CURRENT] as $key => $value) {
        $stmt->execute([$key, $value]);
    }
    $pdo = null;
}
foreach (glob($root . 'cache/db_*.flag') ?: [] as $flag) {
    @unlink($flag);
}
if ($mode === 'status') {
    // Nur lesen: Flags setzen, damit der Konstruktor von Database nichts anlegt oder repariert.
    $version = \CMS\SchemaManager::SCHEMA_VERSION;
    file_put_contents($root . 'cache/db_schema_' . $version . '.flag', 'test');
    file_put_contents($root . 'cache/db_runtime_schema_' . $version . '_' . hash_file('crc32b', $root . 'core/SchemaManager.php') . '.flag', 'test');
}

$db = \CMS\Database::instance();
if ($mode === 'update') {
    $result = (new \CMS\DatabaseUpdateRunner($db))->run();
    $out['success'] = (bool) $result['success'];
    $out['message'] = (string) $result['message'];
}
if ($mode === 'status' && method_exists(\CMS\DatabaseUpdateRunner::class, 'missingSchemaElements')) {
    $out['missing'] = (new \CMS\DatabaseUpdateRunner($db))->missingSchemaElements();
}
$out['schema'] = (string) $db->get_var("SELECT option_value FROM cms_settings WHERE option_name = 'db_schema_version'");
$log = (string) @file_get_contents($errorLog);
// Hinweis beim Anlegen des Standard-Admins ist kein Fehler.
$out['errors'] = array_values(array_filter(explode("\n", $log), static fn (string $line): bool => trim($line) !== '' && !str_contains($line, 'Default admin created')));
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
