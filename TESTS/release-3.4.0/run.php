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

// Schema v22 kam mit 3.4.0; spätere Releases heben die Version an. Geprüft wird: mindestens v22, in beiden Managern gleich.
$schemaVersionPattern = "/SCHEMA_VERSION = 'v(\\d+)'/";
release340Assert(preg_match($schemaVersionPattern, $schemaManager, $schemaMatch) === 1, 'SchemaManager deklariert keine SCHEMA_VERSION.');
release340Assert(preg_match($schemaVersionPattern, $migrationManager, $migrationMatch) === 1, 'MigrationManager deklariert keine SCHEMA_VERSION.');
release340Assert((int) $schemaMatch[1] >= 22, 'SchemaManager verwendet ein älteres Schema als v22.');
release340Assert($schemaMatch[1] === $migrationMatch[1], 'SchemaManager und MigrationManager verwenden unterschiedliche Schema-Versionen.');
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
// Die Liste darf wachsen (z. B. 'private' seit 4.0.0); die Pfade aus 3.4.0 müssen enthalten bleiben.
release340Assert(preg_match('/RUNTIME_CORE_ENTRIES = \[([^\]]*)\]/', $updateService, $runtimeEntriesMatch) === 1, 'UpdateService deklariert keine bewahrten Core-Pfade.');
foreach (['backups', 'cache', 'config', 'config.php', 'logs', 'uploads'] as $preservedEntry) {
    release340Assert(str_contains($runtimeEntriesMatch[1], "'" . $preservedEntry . "'"), 'Core-Update-Swap bewahrt ' . $preservedEntry . ' nicht.');
}
release340Assert(str_contains($updateService, 'acquireInstallLock'), 'UpdateService serialisiert parallele Installationen nicht.');
release340Assert(str_contains($updateService, 'integrity_hash_missing'), 'UpdateService akzeptiert Pakete ohne SHA-256-Prüfsumme.');
release340Assert(str_contains($updateService, 'installationCompleted'), 'UpdateService schützt Recovery-Backups nach fehlgeschlagenem Swap nicht.');

// Die Paketprüfung gilt dem aktuellen Release (Version::CURRENT), nicht fest 3.4.0: ältere Pakete liegen nicht mehr in RELEASE/.
$versionSource = file_get_contents($cms . 'core/Version.php');
release340Assert(is_string($versionSource) && preg_match("/const CURRENT = '(\\d+\\.\\d+\\.\\d+)'/", $versionSource, $versionMatch) === 1, 'Version::CURRENT ist nicht lesbar.');
$release = $versionMatch[1];

$manifestPath = $cms . 'marketplace/core/365cms/update.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
release340Assert(($manifest['version'] ?? '') === $release, 'Marketplace-Manifest hat nicht Version ' . $release . '.');
release340Assert(($manifest['schema_version'] ?? '') === 'v' . $schemaMatch[1], 'Marketplace-Manifest nennt nicht das Schema des SchemaManagers.');
release340Assert(($manifest['download_url'] ?? '') === 'https://365cms.de/marketplace/core/365cms/365CMS-' . $release . '-update.zip', 'Marketplace-Manifest hat keinen erwarteten Paketpfad.');

$updateZip = $root . 'RELEASE' . DIRECTORY_SEPARATOR . '365CMS-' . $release . '-update.zip';
$fullZip = $root . 'RELEASE' . DIRECTORY_SEPARATOR . '365CMS-' . $release . '-full.zip';
foreach ([$updateZip, $fullZip] as $package) {
    release340Assert(is_file($package), 'Release-Paket fehlt: ' . basename($package));
}

$updateHash = hash_file('sha256', $updateZip);
release340Assert(is_string($updateHash) && hash_equals((string) ($manifest['checksum_sha256'] ?? ''), $updateHash), 'SHA-256 des Updatepakets stimmt nicht mit dem Marketplace-Manifest überein.');
$checksumFile = $root . 'RELEASE' . DIRECTORY_SEPARATOR . '365CMS-' . $release . '-SHA256SUMS.txt';
$checksums = is_file($checksumFile) ? (string) file_get_contents($checksumFile) : '';
release340Assert($checksums !== '', 'Die veröffentlichte SHA-256-Datei fehlt oder ist leer.');
release340Assert(str_contains($checksums, $updateHash . '  365CMS-' . $release . '-update.zip'), 'Die SHA-256-Datei enthält nicht die aktuelle Update-Prüfsumme.');
$fullHash = hash_file('sha256', $fullZip);
release340Assert(is_string($fullHash) && str_contains($checksums, $fullHash . '  365CMS-' . $release . '-full.zip'), 'Die SHA-256-Datei enthält nicht die aktuelle Full-Paket-Prüfsumme.');

if (class_exists(ZipArchive::class)) {
    foreach ([$updateZip, $fullZip] as $package) {
        $zip = new ZipArchive();
        release340Assert($zip->open($package) === true, 'Release-Paket kann nicht geöffnet werden: ' . basename($package));
        try {
            release340Assert($zip->numFiles > 0 && $zip->numFiles <= 5000, 'Release-Paket hat eine ungültige Eintragsanzahl: ' . basename($package));
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                release340Assert(!str_contains($name, '../') && !str_starts_with($name, '/'), 'Unsicherer ZIP-Pfad: ' . $name);
                if (preg_match('#/CMS/(config|uploads|cache|logs|backups)/#', $name) !== 1) {
                    continue;
                }
                // Das Full-Paket legt die Laufzeitordner für Neuinstallationen mit Schutzdateien an (Zugriffssperre,
                // Platzhalter). Installationsdaten dürfen in keinem Paket stehen; das Update-Paket lässt die Ordner ganz aus.
                release340Assert(
                    $package === $fullZip && in_array(basename($name), ['.htaccess', 'index.html', '.gitkeep', '.gitignore'], true),
                    'Installationsspezifischer Laufzeitpfad im Release-Paket: ' . $name
                );
            }
        } finally {
            $zip->close();
        }
    }
}

echo "365CMS release package tests passed (" . $release . ").\n";
