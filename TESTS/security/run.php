<?php
/**
 * Sicherheitsprüfungen ohne Datenbank. Jede Datei `TESTS/security/<thema>.php` wird eingebunden und
 * registriert ihre Prüfungen über T::run().
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/security/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';

T::$verbose = in_array('-v', $argv ?? [], true);

$parts = glob(__DIR__ . '/*.php') ?: [];
sort($parts);
foreach ($parts as $part) {
    if ($part !== __FILE__) {
        require $part;
    }
}

T::finish();
