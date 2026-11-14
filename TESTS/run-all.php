<?php
/**
 * Führt alle Test-Suiten `TESTS/<bereich>/run.php` nacheinander in eigenen PHP-Prozessen aus.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/run-all.php [-v]
 * Exit-Code 0 = alle Suiten grün, 1 = mindestens eine Suite fehlgeschlagen.
 */

declare(strict_types=1);

$suites = glob(__DIR__ . '/*/run.php') ?: [];
sort($suites);
$verbose = in_array('-v', $argv ?? [], true);
$failed = [];

foreach ($suites as $suite) {
    $name = basename(dirname($suite));
    echo PHP_EOL . '######## ' . $name . PHP_EOL;
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($suite) . ($verbose ? ' -v' : '');
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        $failed[] = $name;
    }
}

echo PHP_EOL . str_repeat('═', 72) . PHP_EOL;
echo 'Suiten: ' . count($suites) . ' | fehlgeschlagen: ' . ($failed === [] ? '0' : implode(', ', $failed)) . PHP_EOL;
exit($failed === [] ? 0 : 1);
