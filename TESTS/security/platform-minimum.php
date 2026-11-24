<?php
/**
 * Audit 4.2.0 (AP-18, Punkt 18.6): Die Mindest-PHP-Version steht an drei Stellen, die nicht auseinanderlaufen dürfen
 * (`config.php` prüft beim Start, der Installer vor der Installation, `Version::MIN_PHP` im Kern). Ohne Datenbank.
 * Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

T::run('AP-18 / 18.6: Mindest-PHP-Version ist in Start-Prüfung, Installer und Kern gleich', static function (): void {
    $core = \CMS\Version::MIN_PHP;
    T::check(preg_match('/^\d+\.\d+\.\d+$/', $core) === 1, 'Version::MIN_PHP ist eine Versionsnummer (' . $core . ')');

    $read = static fn (string $file): string => str_replace("\r\n", "\n", (string) file_get_contents(ABSPATH . $file));
    $default = static function (string $source): ?string {
        return preg_match("/if \(!defined\('CMS_MIN_PHP_VERSION'\)\) \{\s*define\('CMS_MIN_PHP_VERSION', '([0-9.]+)'\);/", $source, $m) === 1 ? $m[1] : null;
    };

    T::same($core, $default($read('config.php')), 'config.php (Prüfung bei jedem Start)');
    T::same($core, $default($read('install/InstallerService.php')), 'Installer (Prüfung vor der Installation)');
    T::check(
        preg_match("/!defined\('CMS_INSTALLER_RUNNING'\) && version_compare\(PHP_VERSION, CMS_MIN_PHP_VERSION, '<'\)/", $read('config.php')) === 1,
        'config.php bricht bei zu alter PHP-Version ab'
    );
});
