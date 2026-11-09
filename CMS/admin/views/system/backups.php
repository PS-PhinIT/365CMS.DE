<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SYSTEM_VIEW')) {
    exit;
}

/**
 * @var array $data Backup-Daten
 * @var string $csrfToken CSRF-Token
 * @var array|null $alert Admin-Statusmeldung
 */

$backups = $data['backups'];
$history = $data['history'];
$storage = is_array($data['storage'] ?? null) ? $data['storage'] : [];
$serviceError = (string) ($data['service_error'] ?? '');
$canWriteBackups = !empty($data['can_write']);
$validationReport = is_array($alert['report_payload'] ?? null) ? $alert['report_payload'] : [];
$restoreModalId = 'backupRestoreConfirmModal';
$errorModalId = 'backupErrorLogModal';

if (!function_exists('cms_admin_backups_render_status_badge')) {
    function cms_admin_backups_render_status_badge(string $status): string
    {
        return match ($status) {
            'ok' => '<span class="badge bg-success-lt text-success">OK</span>',
            'blocked' => '<span class="badge bg-danger-lt text-danger">Blockiert</span>',
            'skipped' => '<span class="badge bg-secondary-lt text-secondary">Übersprungen</span>',
            default => '<span class="badge bg-warning-lt text-warning">Hinweis</span>',
        };
    }
}
?>

<div class="container-xl">
    <div class="page-header d-print-none mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="page-title">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-database-export me-2" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 6c0 1.657 3.582 3 8 3s8 -1.343 8 -3s-3.582 -3 -8 -3s-8 1.343 -8 3"/><path d="M4 6v6c0 1.657 3.582 3 8 3c1.118 0 2.183 -.086 3.15 -.241"/><path d="M20 12v-6"/><path d="M4 12v6c0 1.657 3.582 3 8 3c.157 0 .312 -.002 .466 -.005"/><path d="M16 19h6"/><path d="M19 16l3 3l-3 3"/></svg>
                    Backup & Restore
                </h2>
            </div>
            <div class="col-auto d-flex gap-2">
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_db">
                    <button type="submit" class="btn btn-outline-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 6c0 1.657 3.582 3 8 3s8 -1.343 8 -3s-3.582 -3 -8 -3s-8 1.343 -8 3"/><path d="M4 6v6c0 1.657 3.582 3 8 3s8 -1.343 8 -3"/><path d="M4 12v6c0 1.657 3.582 3 8 3s8 -1.343 8 -3"/></svg>
                        DB-Backup
                    </button>
                </form>
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_full">
                    <button type="submit" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 11l5 5l5 -5"/><path d="M12 4l0 12"/></svg>
                        Vollständiges Backup
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php if (!empty($alert)): ?>
        <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
    <?php endif; ?>

    <?php if ($serviceError !== ''): ?>
        <div class="alert alert-danger mb-4" role="alert">
            <h4 class="alert-title">Backup-Verzeichnis nicht nutzbar</h4>
            <div><?php echo htmlspecialchars($serviceError); ?></div>
            <div class="small mt-1">In <code>config/app.php</code> <code>CMS_BACKUP_PATH</code> prüfen: eigenes, für PHP beschreibbares Verzeichnis außerhalb des Webroots – absolut oder relativ zum CMS-Verzeichnis (z. B. <code>../365cms-backups</code>).</div>
        </div>
    <?php endif; ?>

    <?php if ($storage !== []): ?>
        <?php
        $storageEncryptionEnabled = !empty($storage['encryption_enabled']);
        $storageEncryptionAvailable = !empty($storage['encryption_available']);
        $storageLegacyCount = (int) ($storage['legacy_webroot_backups'] ?? 0);
        ?>
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Speicherort &amp; Verschlüsselung</h3>
                <div class="card-actions d-flex gap-2">
                    <?php if (!empty($storage['inside_webroot'])): ?>
                        <span class="badge bg-warning-lt text-warning">Im Webroot</span>
                    <?php else: ?>
                        <span class="badge bg-success-lt text-success">Außerhalb des Webroots</span>
                    <?php endif; ?>
                    <?php if ($storageEncryptionEnabled): ?>
                        <span class="badge bg-success-lt text-success">Verschlüsselung aktiv</span>
                    <?php else: ?>
                        <span class="badge bg-secondary-lt text-secondary">Unverschlüsselt</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="text-secondary small">Backup-Verzeichnis <?php echo !empty($storage['custom_path']) ? '(CMS_BACKUP_PATH)' : '(Standard)'; ?></div>
                        <div class="fw-semibold text-break"><code><?php echo htmlspecialchars((string) ($storage['path'] ?? '')); ?></code></div>
                        <?php if (empty($storage['writable'])): ?>
                            <div class="text-danger small mt-1">Das Verzeichnis ist für PHP nicht beschreibbar.</div>
                        <?php endif; ?>
                        <?php if (!empty($storage['inside_webroot'])): ?>
                            <div class="text-secondary small mt-2">Liegt im Webroot und ist nur per <code>.htaccess</code> geschützt (nginx/IIS werten sie nicht aus). Empfehlung: in <code>config/app.php</code> <code>CMS_BACKUP_PATH</code> auf ein eigenes Verzeichnis außerhalb des Webroots setzen (z. B. <code>../365cms-backups</code> oder einen Offsite-Mount) und vorhandene Backups dorthin verschieben.</div>
                        <?php else: ?>
                            <div class="text-secondary small mt-2">Nicht per HTTP erreichbar. Für eine Offsite-Kopie das Verzeichnis zusätzlich extern sichern (z. B. rsync/rclone; verschlüsselte Backups können unbedenklich bei Dritten liegen).</div>
                        <?php endif; ?>
                        <?php if ($storageLegacyCount > 0): ?>
                            <div class="text-warning small mt-2">Im bisherigen Verzeichnis <code>backups/</code> des CMS liegen noch <?php echo $storageLegacyCount; ?> Backup(s). Diese erscheinen hier nicht mehr – in das neue Verzeichnis verschieben oder löschen.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-6">
                        <div class="text-secondary small">Verschlüsselung neuer Backups</div>
                        <?php if (!$storageEncryptionAvailable): ?>
                            <div class="fw-semibold">Nicht verfügbar</div>
                            <div class="text-secondary small mt-1">Die PHP-Erweiterung <code>sodium</code> fehlt; Backups können nicht verschlüsselt werden.</div>
                        <?php else: ?>
                            <div class="fw-semibold"><?php echo $storageEncryptionEnabled ? 'Aktiv – XChaCha20-Poly1305, Schlüssel-ID ' . htmlspecialchars((string) ($storage['key_id'] ?? '')) : 'Aus'; ?></div>
                            <div class="text-secondary small mt-1">Datenbank-Dump und Datei-Archiv werden als <code>*.enc</code> gespeichert; Prüfen, Dry-Run und Restore entschlüsseln automatisch. Downloads bleiben verschlüsselt.</div>
                            <?php if ($storageEncryptionEnabled && (int) ($storage['unencrypted_backups'] ?? 0) > 0): ?>
                                <div class="text-warning small mt-1"><?php echo (int) $storage['unencrypted_backups']; ?> ältere(s) Backup(s) sind noch unverschlüsselt – nach einem neuen verschlüsselten Backup löschen.</div>
                            <?php endif; ?>
                            <?php if (!empty($storage['weak_key'])): ?>
                                <div class="text-danger small mt-1">Es ist kein sicherer Schlüssel konfiguriert (Platzhalter in <code>config/app.php</code>) – die Verschlüsselung schützt so nicht.</div>
                            <?php endif; ?>
                            <div class="alert alert-warning small mt-2 mb-0" role="alert">
                                Ohne Schlüssel ist ein verschlüsseltes Backup nicht wiederherstellbar.
                                <?php if (!empty($storage['dedicated_key'])): ?>
                                    <code>CMS_ENCRYPTION_KEY</code> aus <code>config/app.php</code> getrennt von den Backups sichern (z. B. Passwortmanager) und bei einem Schlüsselwechsel den alten Wert in <code>CMS_ENCRYPTION_KEYS_PREVIOUS</code> behalten.
                                <?php else: ?>
                                    Ohne eigenen <code>CMS_ENCRYPTION_KEY</code> wird der Schlüssel aus <code>AUTH_KEY</code>/<code>SECURE_AUTH_KEY</code> abgeleitet – diese Werte aus <code>config/app.php</code> getrennt von den Backups sichern.
                                <?php endif; ?>
                            </div>
                            <?php if ($canWriteBackups): ?>
                                <form method="post" class="mt-3">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="set_encryption">
                                    <input type="hidden" name="backup_encryption" value="<?php echo $storageEncryptionEnabled ? '0' : '1'; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $storageEncryptionEnabled ? 'btn-outline-secondary' : 'btn-success'; ?>">
                                        <?php echo $storageEncryptionEnabled ? 'Verschlüsselung deaktivieren' : 'Verschlüsselung aktivieren'; ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($validationReport !== []): ?>
        <?php
        $validationBackup = is_array($validationReport['backup'] ?? null) ? $validationReport['backup'] : [];
        $validationSummary = is_array($validationReport['summary'] ?? null) ? $validationReport['summary'] : [];
        $validationChecks = is_array($validationReport['checks'] ?? null) ? $validationReport['checks'] : [];
        $validationTables = is_array($validationReport['table_probes'] ?? null) ? $validationReport['table_probes'] : [];
        $restoreDryRun = is_array($validationReport['restore_dry_run'] ?? null) ? $validationReport['restore_dry_run'] : [];
        ?>
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Backup-Validierungsbericht</h3>
                <div class="card-actions d-flex gap-2 align-items-center">
                    <?php echo cms_admin_backups_render_status_badge((string) ($validationSummary['status'] ?? 'warning')); ?>
                    <span class="badge bg-blue-lt"><?php echo htmlspecialchars((string) ($validationBackup['name'] ?? 'Backup')); ?></span>
                    <?php if (!empty($validationBackup['encrypted'])): ?>
                        <span class="badge bg-success-lt text-success">Verschlüsselt</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-3">Die Validierung prüft Integrität, zentrale Tabellen im SQL-Dump und optional einen Restore-Trockentest in eine temporäre Datenbank. Dabei werden keine öffentlichen GET-Mutationen und keine Token-URLs eingeführt.</p>

                <div class="row g-3 mb-4">
                    <div class="col-md-3"><div class="text-secondary small">Typ</div><div class="fw-semibold"><?php echo htmlspecialchars((string) ($validationBackup['type'] ?? '-')); ?></div></div>
                    <div class="col-md-3"><div class="text-secondary small">Datum</div><div class="fw-semibold"><?php echo htmlspecialchars((string) ($validationBackup['date'] ?? '-')); ?></div></div>
                    <div class="col-md-3"><div class="text-secondary small">Größe</div><div class="fw-semibold"><?php echo htmlspecialchars((string) ($validationBackup['size_formatted'] ?? '-')); ?></div></div>
                    <div class="col-md-3"><div class="text-secondary small">CMS-Version im Backup</div><div class="fw-semibold"><?php echo htmlspecialchars((string) ($validationBackup['cms_version'] ?? '-')); ?></div></div>
                </div>

                <div class="table-responsive mb-4">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Check</th>
                                <th>Aktuell</th>
                                <th>Erwartet</th>
                                <th>Status</th>
                                <th>Hinweis</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($validationChecks as $check): ?>
                                <?php if (!is_array($check)) { continue; } ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars((string) ($check['label'] ?? 'Check')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($check['current'] ?? '—')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($check['expected'] ?? '—')); ?></td>
                                    <td><?php echo cms_admin_backups_render_status_badge((string) ($check['status'] ?? 'warning')); ?></td>
                                    <td class="text-secondary small"><?php echo htmlspecialchars((string) ($check['detail'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($validationTables !== []): ?>
                    <h4 class="mb-3">Probe-Lesen wichtiger Tabellen</h4>
                    <div class="table-responsive mb-4">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Tabelle</th>
                                    <th>Status</th>
                                    <th>Ergebnis</th>
                                    <th>Hinweis</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($validationTables as $tableCheck): ?>
                                    <?php if (!is_array($tableCheck)) { continue; } ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars((string) ($tableCheck['label'] ?? $tableCheck['table'] ?? 'Tabelle')); ?></div>
                                            <div class="text-secondary small"><code><?php echo htmlspecialchars((string) ($tableCheck['table'] ?? '')); ?></code></div>
                                        </td>
                                        <td><?php echo cms_admin_backups_render_status_badge((string) ($tableCheck['status'] ?? 'warning')); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($tableCheck['current'] ?? '—')); ?></td>
                                        <td class="text-secondary small"><?php echo htmlspecialchars((string) ($tableCheck['detail'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (!empty($restoreDryRun['requested'])): ?>
                    <h4 class="mb-3">Restore-Dry-Run</h4>
                    <div class="mb-3 d-flex gap-2 align-items-center flex-wrap">
                        <?php echo cms_admin_backups_render_status_badge((string) ($restoreDryRun['status'] ?? 'warning')); ?>
                        <span class="text-secondary small"><?php echo htmlspecialchars((string) ($restoreDryRun['message'] ?? '')); ?></span>
                    </div>

                    <?php if (!empty($restoreDryRun['tables']) && is_array($restoreDryRun['tables'])): ?>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table">
                                <thead>
                                    <tr>
                                        <th>Tabelle</th>
                                        <th>Live-Zeilen</th>
                                        <th>Dry-Run-Zeilen</th>
                                        <th>Status</th>
                                        <th>Hinweis</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($restoreDryRun['tables'] as $tableCheck): ?>
                                        <?php if (!is_array($tableCheck)) { continue; } ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string) ($tableCheck['label'] ?? $tableCheck['table'] ?? 'Tabelle')); ?></div>
                                                <div class="text-secondary small"><code><?php echo htmlspecialchars((string) ($tableCheck['table'] ?? '')); ?></code></div>
                                            </td>
                                            <td><?php echo htmlspecialchars((string) (($tableCheck['live_rows'] ?? null) === null ? '—' : (string) $tableCheck['live_rows'])); ?></td>
                                            <td><?php echo htmlspecialchars((string) (($tableCheck['restored_rows'] ?? null) === null ? '—' : (string) $tableCheck['restored_rows'])); ?></td>
                                            <td><?php echo cms_admin_backups_render_status_badge((string) ($tableCheck['status'] ?? 'warning')); ?></td>
                                            <td class="text-secondary small"><?php echo htmlspecialchars((string) ($tableCheck['detail'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Vorhandene Backups -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">Vorhandene Backups</h3>
            <div class="card-actions">
                <span class="badge bg-blue-lt"><?php echo count($backups); ?> Backup(s)</span>
            </div>
        </div>
        <?php if (!empty($backups)): ?>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Dateiname</th>
                            <th>Größe</th>
                            <th>Datum</th>
                            <th class="w-1 text-end">AKTIONEN</th>
                        </tr>
                        <tr class="backup-actions-subheader">
                            <th colspan="3"></th>
                            <th class="w-1 text-end">DB | Dateien | Prüfen | Dry-Run | Restore | Löschen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                            <?php
                            $name = is_array($backup) ? ($backup['name'] ?? $backup['filename'] ?? '') : (string)$backup;
                            $size = is_array($backup) ? ($backup['size_formatted'] ?? $backup['size'] ?? '-') : '-';
                            $date = is_array($backup) ? ($backup['date'] ?? $backup['created'] ?? '-') : '-';
                            $statusRaw = strtolower(trim((string) (is_array($backup) ? ($backup['status'] ?? '') : '')));
                            $isFailedBackup = in_array($statusRaw, ['failed', 'fehlgeschlagen', 'error', 'fehler', 'incomplete'], true)
                                || (is_array($backup) && array_key_exists('success', $backup) && empty($backup['success']));
                            $isPendingBackup = $statusRaw === 'pending';
                            $errorLog = is_array($backup)
                                ? (string) ($backup['error_log'] ?? $backup['error'] ?? $backup['message'] ?? 'Keine Fehlerdetails vorhanden.')
                                : 'Keine Fehlerdetails vorhanden.';
                            $canDownloadDatabase = !empty($backup['can_download_database']);
                            $canDownloadFiles = !empty($backup['can_download_files']);
                            $canRestore = !empty($backup['can_restore']);
                            if ($isFailedBackup || $isPendingBackup) {
                                $canRestore = false;
                            }
                            $downloadDatabaseToken = $canDownloadDatabase && function_exists('cms_admin_backups_create_download_token')
                                ? cms_admin_backups_create_download_token((string) $name, 'database')
                                : '';
                            $downloadFilesToken = $canDownloadFiles && function_exists('cms_admin_backups_create_download_token')
                                ? cms_admin_backups_create_download_token((string) $name, 'files')
                                : '';
                            if (is_numeric($size)) {
                                $size = round($size / 1024 / 1024, 2) . ' MB';
                            }
                            ?>
                            <tr class="<?php echo $isFailedBackup ? 'backup-row-failed' : ''; ?>">
                                <td>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1 text-muted" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/></svg>
                                    <?php echo htmlspecialchars($name); ?>
                                    <?php if (!empty($backup['encrypted'])): ?>
                                        <span class="badge bg-success-lt text-success" title="Verschlüsselt (XChaCha20-Poly1305)">Verschlüsselt</span>
                                    <?php endif; ?>
                                    <?php if ($isFailedBackup): ?>
                                        <i class="ti ti-alert-triangle backup-failed-icon" title="Fehlerhafter Backup-Stand" aria-hidden="true"></i>
                                        <button
                                            type="button"
                                            class="badge bg-danger-lt text-danger border-0 backup-failed-badge"
                                            data-cms-call="cmsAdminBackupsShowErrorLog" data-cms-call-args="<?php echo htmlspecialchars((string) json_encode([(string) $name, (string) $errorLog], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo $statusRaw === 'incomplete' ? 'Unvollständig' : 'Fehlgeschlagen'; ?>
                                        </button>
                                    <?php elseif ($isPendingBackup): ?>
                                        <span class="badge bg-warning-lt text-warning" title="<?php echo htmlspecialchars($errorLog); ?>">In Arbeit</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?php echo htmlspecialchars((string)$size); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars((string)$date); ?></td>
                                <td>
                                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                                        <?php if ($canDownloadDatabase && $downloadDatabaseToken !== ''): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="download">
                                                <input type="hidden" name="download_token" value="<?php echo htmlspecialchars($downloadDatabaseToken); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">DB</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($canDownloadFiles && $downloadFilesToken !== ''): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="download">
                                                <input type="hidden" name="download_token" value="<?php echo htmlspecialchars($downloadFilesToken); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Dateien</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="validate">
                                            <input type="hidden" name="backup_name" value="<?php echo htmlspecialchars($name); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Prüfen</button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="validate">
                                            <input type="hidden" name="backup_name" value="<?php echo htmlspecialchars($name); ?>">
                                            <input type="hidden" name="include_restore_dry_run" value="1">
                                                <button type="button" class="btn btn-sm btn-outline-warning"
                                                    data-cms-call="cmsAdminBackupsConfirmSubmit" data-cms-call-context="element" data-cms-call-args="<?php echo htmlspecialchars((string) json_encode(['dry_run', (string) $name], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                                                Dry-Run
                                            </button>
                                        </form>
                                        <?php if ($canRestore): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="restore">
                                                <input type="hidden" name="backup_name" value="<?php echo htmlspecialchars($name); ?>">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-warning <?php echo $canRestore ? '' : 'backup-restore-disabled'; ?>"
                                                    title="<?php echo $canRestore ? '' : 'Backup fehlerhaft — Wiederherstellung nicht möglich'; ?>"
                                                    data-cms-call="cmsAdminBackupsConfirmSubmit" data-cms-call-context="element" data-cms-call-args="<?php echo htmlspecialchars((string) json_encode(['restore', (string) $name, (string) $size, (string) $date], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                                    <?php echo $canRestore ? '' : 'aria-disabled="true"'; ?>>
                                                    Restore
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="backup_name" value="<?php echo htmlspecialchars($name); ?>">
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                    data-cms-call="cmsAdminBackupsConfirmSubmit" data-cms-call-context="element" data-cms-call-args="<?php echo htmlspecialchars((string) json_encode(['delete', (string) $name], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0"/><path d="M10 11l0 6"/><path d="M14 11l0 6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="card-body">
                <div class="empty">
                    <p class="empty-title">Keine Backups vorhanden</p>
                    <p class="empty-subtitle text-secondary">Erstellen Sie ein Backup mit den Buttons oben.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Backup-Historie -->
    <?php if (!empty($history)): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Backup-Historie</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Typ</th>
                            <th>Status</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($entry['date'] ?? $entry['timestamp'] ?? '-'); ?></td>
                                <td><span class="badge"><?php echo htmlspecialchars($entry['type'] ?? '-'); ?></span></td>
                                <td>
                                    <?php if (!empty($entry['success'])): ?>
                                        <span class="badge bg-success-lt">Erfolgreich</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-lt">Fehlgeschlagen</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?php echo htmlspecialchars($entry['message'] ?? $entry['name'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="modal modal-blur fade" id="<?php echo $restoreModalId; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">System wiederherstellen?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong>Datei:</strong> <span data-restore-backup-name>-</span></p>
                <p class="mb-2"><strong>Größe:</strong> <span data-restore-backup-size>-</span></p>
                <p class="mb-3"><strong>Datum:</strong> <span data-restore-backup-date>-</span></p>
                <p class="text-danger fw-semibold mb-0">Alle aktuellen Daten werden mit dem Stand dieses Backups überschrieben. Dieser Vorgang kann nicht rückgängig gemacht werden.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-danger" data-restore-confirm-button>Wiederherstellen</button>
            </div>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="<?php echo $errorModalId; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Fehlerprotokoll: <span data-backup-error-name>-</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <pre class="backup-error-log mb-0" data-backup-error-log>Keine Fehlerdetails vorhanden.</pre>
            </div>
        </div>
    </div>
</div>

<script <?= \CMS\Security::instance()->nonceAttr() ?>>
function cmsAdminBackupsSubmitForm(form) {
    if (window.cmsSubmitFormSafely) {
        window.cmsSubmitFormSafely(form);
        return;
    }

    if (form && typeof form.requestSubmit === 'function') {
        form.requestSubmit();
        return;
    }

    if (!form) {
        return;
    }

    var fallbackSubmitter = document.createElement('button');
    fallbackSubmitter.type = 'submit';
    fallbackSubmitter.hidden = true;
    form.appendChild(fallbackSubmitter);
    fallbackSubmitter.click();
    fallbackSubmitter.remove();
}

var cmsAdminBackupRestoreModal = null;
var cmsAdminBackupRestoreModalInstance = null;
var cmsAdminBackupRestoreForm = null;

function cmsAdminBackupsConfirmSubmit(button, kind, backupName, backupSize, backupDate) {
    var form = button ? button.closest('form') : null;
    var title = 'Backup-Aktion bestätigen';
    var message = 'Backup „' + backupName + '“ wirklich verarbeiten?';
    var confirmText = 'Bestätigen';
    var confirmClass = 'btn-warning';

    if (kind === 'dry_run') {
        title = 'Restore-Dry-Run starten?';
        message = backupName + ' wird testweise in eine temporäre Datenbank eingespielt und danach wieder entfernt.';
        confirmText = 'Dry-Run starten';
    } else if (kind === 'restore') {
        if (button && button.classList.contains('backup-restore-disabled')) {
            return;
        }

        cmsAdminBackupsOpenRestoreModal(form, backupName, backupSize, backupDate);
        return;
    } else if (kind === 'delete') {
        title = 'Backup löschen?';
        message = backupName + ' wird unwiderruflich gelöscht.';
        confirmText = 'Löschen';
        confirmClass = 'btn-danger';
    }

    cmsConfirm({
        title: title,
        message: message,
        confirmText: confirmText,
        confirmClass: confirmClass,
        onConfirm: function() {
            cmsAdminBackupsSubmitForm(form);
        }
    });
}

function cmsAdminBackupsOpenRestoreModal(form, backupName, backupSize, backupDate) {
    cmsAdminBackupRestoreModal = document.getElementById('<?php echo $restoreModalId; ?>');
    if (!cmsAdminBackupRestoreModal || typeof bootstrap === 'undefined' || !bootstrap || typeof bootstrap.Modal !== 'function') {
        return;
    }

    cmsAdminBackupRestoreForm = form;
    cmsAdminBackupRestoreModal.querySelector('[data-restore-backup-name]').textContent = backupName || '-';
    cmsAdminBackupRestoreModal.querySelector('[data-restore-backup-size]').textContent = backupSize || '-';
    cmsAdminBackupRestoreModal.querySelector('[data-restore-backup-date]').textContent = backupDate || '-';

    if (!cmsAdminBackupRestoreModalInstance) {
        cmsAdminBackupRestoreModalInstance = new bootstrap.Modal(cmsAdminBackupRestoreModal);
    }

    cmsAdminBackupRestoreModalInstance.show();
}

function cmsAdminBackupsShowErrorLog(backupName, errorLog) {
    var errorModal = document.getElementById('<?php echo $errorModalId; ?>');
    if (!errorModal || typeof bootstrap === 'undefined' || !bootstrap || typeof bootstrap.Modal !== 'function') {
        window.alert((backupName || 'Backup') + '\n\n' + (errorLog || 'Keine Fehlerdetails vorhanden.'));
        return;
    }

    errorModal.querySelector('[data-backup-error-name]').textContent = backupName || '-';
    errorModal.querySelector('[data-backup-error-log]').textContent = errorLog || 'Keine Fehlerdetails vorhanden.';
    var modalInstance = new bootstrap.Modal(errorModal);
    modalInstance.show();
}

document.addEventListener('DOMContentLoaded', function() {
    var restoreConfirmButton = document.querySelector('[data-restore-confirm-button]');
    if (!(restoreConfirmButton instanceof HTMLElement)) {
        return;
    }

    restoreConfirmButton.addEventListener('click', function() {
        if (cmsAdminBackupRestoreModalInstance) {
            cmsAdminBackupRestoreModalInstance.hide();
        }
        cmsAdminBackupsSubmitForm(cmsAdminBackupRestoreForm);
    });
});
</script>
