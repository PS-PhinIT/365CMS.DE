<?php
/**
 * Hilfsskript für TESTS/admin-menu/run.php (Teil 6): lädt den InstallerService wie CMS/install.php –
 * ohne Autoloader, ohne config.php und ohne Datenbank – und gibt die Daten des Installer-Schritts
 * „Admin-Layout“ als JSON aus. Läuft in einem eigenen PHP-Prozess, damit der Autoloader und die
 * Klassen des Testlaufs nichts verdecken.
 *
 * Aufruf: php TESTS/admin-menu/installer-preview.php <CMS-Verzeichnis>
 */

declare(strict_types=1);

$rootDir = rtrim((string) ($argv[1] ?? ''), '/');
if (($argc ?? 0) !== 2 || !is_file($rootDir . '/install/InstallerService.php')) {
    fwrite(STDERR, 'Aufruf: php installer-preview.php <CMS-Verzeichnis>' . PHP_EOL);
    exit(2);
}

define('ABSPATH', $rootDir . DIRECTORY_SEPARATOR);
define('CMS_INSTALLER_RUNNING', true);
require_once $rootDir . '/install/InstallerService.php';

$service = new \CMS\Install\InstallerService($rootDir);
$modules = $service->getDefaultInstallableCoreModuleStates();
$normalized = [];
foreach (['structured', 'classic', ' structured ', 'custom', 'Structured', '', 'x', null, ['structured'], 1] as $input) {
    $normalized[] = $service->normalizeAdminMenuProfile($input);
}
$on = $service->getAdminMenuProfilePreviews($modules);
$off = $service->getAdminMenuProfilePreviews(array_fill_keys(array_keys($modules), false));

$classes = array_values(array_filter(get_declared_classes(), static fn (string $class): bool => str_starts_with($class, 'CMS\\')));
sort($classes);

echo json_encode([
    'modules' => $modules,
    'normalized' => $normalized,
    'on' => $on,
    'off' => $off,
    'autoloaders' => count(spl_autoload_functions()),
    'classes' => $classes,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
