<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$cms = $root . 'CMS' . DIRECTORY_SEPARATOR;

/** @throws RuntimeException */
function release340Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$schemaManager = file_get_contents($cms . 'core/SchemaManager.php');
$databaseRunner = file_get_contents($cms . 'core/DatabaseUpdateRunner.php');
$updateService = file_get_contents($cms . 'core/Services/UpdateService.php');
$installerService = file_get_contents($cms . 'install/InstallerService.php');
$updatesModule = file_get_contents($cms . 'admin/modules/system/UpdatesModule.php');
$updatesEntry = file_get_contents($cms . 'admin/updates.php');
$migrationManager = file_get_contents($cms . 'core/MigrationManager.php');

foreach ([$schemaManager, $databaseRunner, $updateService, $installerService, $updatesModule, $updatesEntry, $migrationManager] as $content) {
    release340Assert(is_string($content) && $content !== '', 'Eine erforderliche Install-/Update-Datei ist nicht lesbar.');
}

release340Assert(str_contains($schemaManager, "SCHEMA_VERSION = 'v22'"), 'SchemaManager verwendet nicht Schema v22.');
release340Assert(str_contains($migrationManager, "SCHEMA_VERSION = 'v22'"), 'MigrationManager verwendet nicht Schema v22.');
release340Assert(str_contains($schemaManager, "'ai_quota_usage' =>"), 'ai_quota_usage fehlt im zentralen Schema.');
release340Assert(str_contains($databaseRunner, 'DATABASE_SCHEMA_OPTION'), 'DatabaseUpdateRunner synchronisiert db_schema_version nicht.');
release340Assert(str_contains($databaseRunner, 'writeSetting(self::DATABASE_SCHEMA_OPTION'), 'DatabaseUpdateRunner schreibt db_schema_version nicht.');
release340Assert(str_contains($databaseRunner, 'schema_downgrade_detected'), 'DatabaseUpdateRunner blockiert kein Schema-Downgrade.');
release340Assert(str_contains($databaseRunner, 'resolveHighestSchemaVersion'), 'DatabaseUpdateRunner schützt bei abweichenden Schema-Markern nicht den höheren Wert.');
release340Assert(str_contains($databaseRunner, 'isTargetSchemaReady'), 'DatabaseUpdateRunner prüft die Schemaobjekte nicht vor dem Marker-Write.');
release340Assert(str_contains($installerService, 'getInstalledDatabaseUpdateStatus'), 'Installer zeigt keinen Datenbank-Update-Status.');
release340Assert(str_contains($updatesModule, 'runDatabaseUpdate'), 'Admin-Updates-Modul kann kein Schema-Update ausführen.');
release340Assert(str_contains($updatesModule, "'database_status' => \$databaseStatus"), 'Updates-Modul berücksichtigt den Datenbankstatus nicht einheitlich.');
release340Assert(str_contains($updatesEntry, "'run_database_update' => true"), 'Admin-Update-Route erlaubt kein Schema-Update.');
release340Assert(str_contains($updateService, "['backups', 'cache', 'config', 'config.php', 'logs', 'uploads']"), 'Core-Update-Swap bewahrt installationsspezifische Pfade nicht.');
release340Assert(str_contains($updateService, 'acquireInstallLock'), 'UpdateService serialisiert parallele Installationen nicht.');
release340Assert(str_contains($updateService, 'integrity_hash_missing'), 'UpdateService akzeptiert Pakete ohne SHA-256-Prüfsumme.');
release340Assert(str_contains($updateService, 'installationCompleted'), 'UpdateService schützt Recovery-Backups nach fehlgeschlagenem Swap nicht.');

$manifestPath = $cms . 'marketplace/core/365cms/update.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
release340Assert(($manifest['version'] ?? '') === '3.4.00', 'Marketplace-Manifest hat nicht Version 3.4.00.');
release340Assert(($manifest['download_url'] ?? '') === 'https://365cms.de/marketplace/core/365cms/365CMS-3.4.0-update.zip', 'Marketplace-Manifest hat keinen erwarteten Paketpfad.');

$updateZip = $root . 'RELEASE' . DIRECTORY_SEPARATOR . '365CMS-3.4.0-update.zip';
$fullZip = $root . 'RELEASE' . DIRECTORY_SEPARATOR . '365CMS-3.4.0-full.zip';
foreach ([$updateZip, $fullZip] as $package) {
    release340Assert(is_file($package), 'Release-Paket fehlt: ' . basename($package));
}

$updateHash = hash_file('sha256', $updateZip);
release340Assert(is_string($updateHash) && hash_equals((string) ($manifest['checksum_sha256'] ?? ''), $updateHash), 'SHA-256 des Updatepakets stimmt nicht mit dem Marketplace-Manifest überein.');
$checksumFile = $root . 'RELEASE' . DIRECTORY_SEPARATOR . '365CMS-3.4.0-SHA256SUMS.txt';
$checksums = is_file($checksumFile) ? (string) file_get_contents($checksumFile) : '';
release340Assert($checksums !== '', 'Die veröffentlichte SHA-256-Datei fehlt oder ist leer.');
release340Assert(str_contains($checksums, $updateHash . '  365CMS-3.4.0-update.zip'), 'Die SHA-256-Datei enthält nicht die aktuelle Update-Prüfsumme.');
$fullHash = hash_file('sha256', $fullZip);
release340Assert(is_string($fullHash) && str_contains($checksums, $fullHash . '  365CMS-3.4.0-full.zip'), 'Die SHA-256-Datei enthält nicht die aktuelle Full-Paket-Prüfsumme.');

if (class_exists(ZipArchive::class)) {
    foreach ([$updateZip, $fullZip] as $package) {
        $zip = new ZipArchive();
        release340Assert($zip->open($package) === true, 'Release-Paket kann nicht geöffnet werden: ' . basename($package));
        try {
            release340Assert($zip->numFiles > 0 && $zip->numFiles <= 5000, 'Release-Paket hat eine ungültige Eintragsanzahl: ' . basename($package));
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                release340Assert(!str_contains($name, '../') && !str_starts_with($name, '/'), 'Unsicherer ZIP-Pfad: ' . $name);
                release340Assert(!str_contains($name, '/CMS/config/') && !str_contains($name, '/CMS/uploads/') && !str_contains($name, '/CMS/cache/') && !str_contains($name, '/CMS/logs/') && !str_contains($name, '/CMS/backups/'), 'Installationsspezifischer Laufzeitpfad im Release-Paket: ' . $name);
            }
        } finally {
            $zip->close();
        }
    }
}

echo "365CMS 3.4.0 release package tests passed.\n";
