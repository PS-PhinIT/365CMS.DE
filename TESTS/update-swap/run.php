<?php
/**
 * Tests für den Verzeichnistausch beim Core-Update (UpdateService::swapDirectoryContentsWithRollback) ohne Datenbank.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/update-swap/run.php [-v]
 */

declare(strict_types=1);

use CMS\Services\UpdateService;

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

/** @param array<string,string> $files relativer Pfad => Inhalt */
function swapTestTree(string $root, array $files): void
{
    foreach ($files as $path => $content) {
        $file = $root . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, $content);
    }
}

/** @return array<string,string> relativer Pfad => Inhalt */
function swapTestRead(string $root): array
{
    $result = [];
    if (!is_dir($root)) {
        return $result;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $result[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
    }
    ksort($result);

    return $result;
}

function swapTestRemove(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

function swapTestRun(string $source, string $target, string $backup): ?Throwable
{
    $service = (new ReflectionClass(UpdateService::class))->newInstanceWithoutConstructor();
    try {
        (new ReflectionMethod($service, 'swapDirectoryContentsWithRollback'))->invoke($service, $source, $target, $backup, 'core');
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

$installed = [
    '.htaccess' => 'alt-htaccess',
    'index.php' => 'alt-index',
    'obsolete.php' => 'entfällt',
    'core/Version.php' => '3.5.6',
    'config.php' => 'stub',
    'config/app.php' => 'zugangsdaten',
    'uploads/downloads/handbuch.pdf' => 'pdf',
    'private/forms/uploads/anhang.png' => 'anhang',
    'plugins/cms-importer/cms-importer.php' => 'importer-alt',
    'plugins/cms-importer/alt-datei.php' => 'nur alt',
    'plugins/eigenes-plugin/eigenes-plugin.php' => 'drittanbieter',
    'themes/cms-default/style.css' => 'theme-alt',
    'themes/mein-theme/style.css' => 'eigenes theme',
];
$package = [
    '.htaccess' => 'neu-htaccess',
    'index.php' => 'neu-index',
    'core/Version.php' => '4.0.0',
    'plugins/cms-importer/cms-importer.php' => 'importer-neu',
    'themes/cms-default/style.css' => 'theme-neu',
    'themes/cms-default/neu.css' => 'neue datei',
];

T::run('Core-Update führt plugins/ und themes/ zusammen', static function () use ($installed, $package): void {
    $base = sys_get_temp_dir() . '/cms-update-swap-' . bin2hex(random_bytes(4));
    swapTestTree($base . '/target', $installed);
    swapTestTree($base . '/source', $package);

    $error = swapTestRun($base . '/source', $base . '/target', $base . '/rollback');
    T::check($error === null, 'Tausch ohne Fehler', $error?->getMessage() ?? '');

    $after = swapTestRead($base . '/target');
    T::same('4.0.0', $after['core/Version.php'] ?? null, 'Core-Dateien werden ersetzt');
    T::same('neu-htaccess', $after['.htaccess'] ?? null, 'Punktdateien werden ersetzt');
    T::check(!isset($after['obsolete.php']), 'nicht mehr ausgelieferte Core-Dateien werden entfernt');
    T::same('zugangsdaten', $after['config/app.php'] ?? null, 'config/ bleibt unangetastet');
    T::same('stub', $after['config.php'] ?? null, 'config.php bleibt unangetastet');
    T::same('pdf', $after['uploads/downloads/handbuch.pdf'] ?? null, 'uploads/ bleibt unangetastet');
    T::same('anhang', $after['private/forms/uploads/anhang.png'] ?? null, 'private/ (privater Speicher ohne CMS_PRIVATE_PATH) bleibt unangetastet');
    T::same('importer-neu', $after['plugins/cms-importer/cms-importer.php'] ?? null, 'mitgeliefertes Plugin wird ersetzt');
    T::check(!isset($after['plugins/cms-importer/alt-datei.php']), 'mitgeliefertes Plugin wird vollständig ersetzt (keine Altdateien)');
    T::same('drittanbieter', $after['plugins/eigenes-plugin/eigenes-plugin.php'] ?? null, 'nachinstalliertes Plugin bleibt erhalten');
    T::same('theme-neu', $after['themes/cms-default/style.css'] ?? null, 'mitgeliefertes Theme wird ersetzt');
    T::same('neue datei', $after['themes/cms-default/neu.css'] ?? null, 'neue Theme-Dateien kommen an');
    T::same('eigenes theme', $after['themes/mein-theme/style.css'] ?? null, 'eigenes Theme bleibt erhalten');
    T::check(!is_dir($base . '/rollback'), 'Rollback-Verzeichnis wird nach Erfolg entfernt');

    swapTestRemove($base);
});

T::run('Paket ohne plugins/ lässt installierte Plugins stehen', static function () use ($installed): void {
    $base = sys_get_temp_dir() . '/cms-update-swap-' . bin2hex(random_bytes(4));
    swapTestTree($base . '/target', $installed);
    swapTestTree($base . '/source', ['index.php' => 'neu-index', 'core/Version.php' => '4.0.0']);

    $error = swapTestRun($base . '/source', $base . '/target', $base . '/rollback');
    T::check($error === null, 'Tausch ohne Fehler', $error?->getMessage() ?? '');
    $after = swapTestRead($base . '/target');
    T::same('importer-alt', $after['plugins/cms-importer/cms-importer.php'] ?? null, 'Plugins bleiben vollständig erhalten');
    T::same('theme-alt', $after['themes/cms-default/style.css'] ?? null, 'Themes bleiben vollständig erhalten');

    swapTestRemove($base);
});

T::run('Installation ohne plugins/ übernimmt den Paketordner', static function () use ($package): void {
    $base = sys_get_temp_dir() . '/cms-update-swap-' . bin2hex(random_bytes(4));
    swapTestTree($base . '/target', ['index.php' => 'alt']);
    swapTestTree($base . '/source', $package);

    $error = swapTestRun($base . '/source', $base . '/target', $base . '/rollback');
    T::check($error === null, 'Tausch ohne Fehler', $error?->getMessage() ?? '');
    T::same('importer-neu', swapTestRead($base . '/target')['plugins/cms-importer/cms-importer.php'] ?? null, 'plugins/ aus dem Paket wird angelegt');

    swapTestRemove($base);
});

T::run('Core-Update auf einem Marketplace-Host lässt marketplace/ unangetastet', static function () use ($installed, $package): void {
    $base = sys_get_temp_dir() . '/cms-update-swap-' . bin2hex(random_bytes(4));
    $feed = '{"plugins":[{"slug":"cms-demo","version":"1.2.0","download_url":"https://365cms.de/marketplace/plugins/cms-demo/cms-demo-1.2.0.zip","sha256":"'
        . str_repeat('a', 64) . '"}]}';
    $marketplace = [
        'marketplace/plugins/index.json' => $feed,
        'marketplace/plugins/cms-demo/cms-demo-1.2.0.zip' => 'PK-plugin-paket',
        'marketplace/themes/index.json' => '{"themes":[{"slug":"mein-theme","download_url":"x","sha256":"y"}]}',
        'marketplace/themes/mein-theme/mein-theme-2.0.0.zip' => 'PK-theme-paket',
        'marketplace/core/365cms/update.json' => '{"version":"4.0.1"}',
        'marketplace/core/365cms/365CMS-4.0.1-update.zip' => 'PK-core-paket',
    ];
    swapTestTree($base . '/target', $installed + $marketplace);
    swapTestTree($base . '/source', $package);

    $error = swapTestRun($base . '/source', $base . '/target', $base . '/rollback');
    T::check($error === null, 'Tausch ohne Fehler', $error?->getMessage() ?? '');
    $after = swapTestRead($base . '/target');
    T::same('4.0.0', $after['core/Version.php'] ?? null, 'Core-Dateien werden ersetzt');
    foreach ($marketplace as $path => $content) {
        T::same($content, $after[$path] ?? null, $path . ' bleibt erhalten');
    }
    T::check(!is_dir($base . '/rollback'), 'Rollback-Verzeichnis wird nach Erfolg entfernt (ohne marketplace/ mitzunehmen)');

    swapTestRemove($base);
});

T::run('Paket mit marketplace/ wird abgelehnt und überschreibt keine Feeds', static function () use ($installed, $package): void {
    $base = sys_get_temp_dir() . '/cms-update-swap-' . bin2hex(random_bytes(4));
    swapTestTree($base . '/target', $installed + [
        'marketplace/plugins/index.json' => '{"plugins":[{"slug":"cms-demo","download_url":"d","sha256":"s"}]}',
        'marketplace/plugins/cms-demo/cms-demo-1.2.0.zip' => 'PK-plugin-paket',
    ]);
    // Platzhalter-Feed ohne download_url/sha256, wie ihn das Update-Paket 4.0.0 ursprünglich mitbrachte.
    swapTestTree($base . '/source', $package + ['marketplace/plugins/index.json' => '{"plugins":[{"slug":"cms-demo","install_supported":false}]}']);
    $before = swapTestRead($base . '/target');

    $service = (new ReflectionClass(UpdateService::class))->newInstanceWithoutConstructor();
    $preflight = null;
    try {
        (new ReflectionMethod($service, 'assertInstallSourceSafe'))->invoke($service, $base . '/source', 'core');
    } catch (Throwable $e) {
        $preflight = $e;
    }
    T::check($preflight instanceof RuntimeException && str_contains($preflight->getMessage(), 'marketplace'), 'Vorprüfung lehnt das Paket vor dem Tausch ab', $preflight?->getMessage() ?? 'kein Fehler');

    $error = swapTestRun($base . '/source', $base . '/target', $base . '/rollback');
    T::check($error instanceof RuntimeException && str_contains($error->getMessage(), 'marketplace'), 'Tausch lehnt das Paket ebenfalls ab', $error?->getMessage() ?? 'kein Fehler');
    T::same($before, swapTestRead($base . '/target'), 'Installation inkl. marketplace/ ist danach unverändert');

    swapTestRemove($base);
});

T::run('Fehler beim Tausch stellt den alten Stand vollständig wieder her', static function () use ($installed, $package): void {
    $base = sys_get_temp_dir() . '/cms-update-swap-' . bin2hex(random_bytes(4));
    swapTestTree($base . '/target', $installed);
    // „uploads“ im Paket ist ein Laufzeitpfad; der Fehler tritt erst nach plugins/ und themes/ auf.
    swapTestTree($base . '/source', $package + ['uploads/x.txt' => 'unzulässig']);
    $before = swapTestRead($base . '/target');
    $sourceBefore = swapTestRead($base . '/source');

    $error = swapTestRun($base . '/source', $base . '/target', $base . '/rollback');
    T::check($error instanceof RuntimeException && str_contains($error->getMessage(), 'Laufzeitpfad'), 'Paket mit Laufzeitpfad wird abgelehnt', $error?->getMessage() ?? 'kein Fehler');
    T::same($before, swapTestRead($base . '/target'), 'Installation ist danach unverändert (inkl. Plugins und Themes)');
    T::same($sourceBefore, swapTestRead($base . '/source'), 'Staging-Inhalt liegt wieder vollständig im Staging');

    swapTestRemove($base);
});

T::finish();
