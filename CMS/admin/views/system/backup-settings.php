<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backup-Einstellungen: Verschlüsselung, Offsite-Ziel (S3-kompatibel), Verbindungstest, Offsite-Status je Backup.
 * Der geheime Schlüssel ist nur schreibbar (leer lassen = gespeicherten Wert behalten).
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$bsData = is_array($data ?? null) ? $data : [];
$bsSettings = (array) ($bsData['settings'] ?? []);
$bsStatus = (array) ($bsData['status'] ?? []);
$bsBackups = (array) ($bsData['backups'] ?? []);
$bsCanOffsite = !empty($bsData['can_offsite']);
$bsE = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$bsV = static fn (string $key, string $default = ''): string => is_scalar($bsSettings[$key] ?? null) && (string) $bsSettings[$key] !== '' ? (string) $bsSettings[$key] : $default;
$bsOn = static fn (string $key): string => !empty($bsSettings[$key]) && (string) $bsSettings[$key] !== '0' ? ' checked' : '';
$bsStatusLabels = [
    'uploaded' => ['Offsite gesichert', 'bg-green-lt text-green'],
    'pending' => ['Läuft / ausstehend', 'bg-azure-lt text-azure'],
    'failed' => ['Fehlgeschlagen', 'bg-red-lt text-red'],
    'deleted' => ['Offsite entfernt (Aufbewahrung)', 'bg-secondary-lt'],
];
$bsDisabled = $bsCanOffsite ? '' : ' disabled';
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">System</div>
                <h2 class="page-title">Backup-Einstellungen</h2>
            </div>
            <div class="col-auto ms-auto">
                <a class="btn" href="/admin/backups"><i class="ti ti-database-export me-1" aria-hidden="true"></i>Zu den Backups</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card mb-4">
                    <div class="card-header"><h3 class="card-title">Verschlüsselung</h3></div>
                    <div class="card-body">
                        <form method="post" action="/admin/backup-settings">
                            <input type="hidden" name="csrf_token" value="<?= $bsE($csrfToken) ?>">
                            <input type="hidden" name="action" value="set_encryption">
                            <input type="hidden" name="backup_encryption" value="0">
                            <label class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" name="backup_encryption" value="1"<?= !empty($bsData['encryption']) ? ' checked' : '' ?>>
                                <span class="form-check-label">Neue Backups verschlüsseln (empfohlen, Pflicht für Offsite-Kopien)</span>
                            </label>
                            <div class="form-hint mb-3">Gilt für alle ab jetzt erstellten Backups. Bestehende Backups bleiben, wie sie sind.</div>
                            <button type="submit" class="btn btn-outline-primary">Speichern</button>
                        </form>
                    </div>
                </div>

                <form method="post" action="/admin/backup-settings" class="card mb-4">
                    <input type="hidden" name="csrf_token" value="<?= $bsE($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_offsite">
                    <div class="card-header"><h3 class="card-title">Offsite-Ziel (S3-kompatibel)</h3></div>
                    <div class="card-body">
                        <p class="form-hint mt-0">AWS S3, Cloudflare R2, Hetzner Object Storage, MinIO und andere S3-kompatible Speicher. Nach jedem erfolgreichen Backup werden die verschlüsselten Dateien kopiert; fehlgeschlagene Uploads holt der tägliche Cron nach (höchstens 3 Versuche).</p>
                        <?php if (!$bsCanOffsite): ?>
                            <div class="alert alert-info" role="status">Zum Ändern fehlt das Recht <code>backups.offsite</code>.</div>
                        <?php endif; ?>
                        <label class="form-check form-switch mb-3">
                            <input type="checkbox" class="form-check-input" name="enabled" value="1"<?= $bsOn('enabled') . $bsDisabled ?>>
                            <span class="form-check-label">Offsite-Kopie aktiv</span>
                        </label>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label" for="bsEndpoint">Endpunkt (Host)</label>
                                <input type="text" class="form-control" id="bsEndpoint" name="endpoint" maxlength="253" value="<?= $bsE($bsV('endpoint')) ?>" placeholder="s3.eu-central-1.amazonaws.com" aria-describedby="bsEndpointHint"<?= $bsDisabled ?>>
                                <div class="form-hint" id="bsEndpointHint">Nur der Hostname, ohne https://. Beispiele: <code>fsn1.your-objectstorage.com</code>, <code>&lt;konto&gt;.r2.cloudflarestorage.com</code>.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="bsRegion">Region</label>
                                <input type="text" class="form-control" id="bsRegion" name="region" maxlength="40" value="<?= $bsE($bsV('region', 'us-east-1')) ?>"<?= $bsDisabled ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="bsBucket">Bucket</label>
                                <input type="text" class="form-control" id="bsBucket" name="bucket" maxlength="63" value="<?= $bsE($bsV('bucket')) ?>"<?= $bsDisabled ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="bsPrefix">Präfix (Ordner)</label>
                                <input type="text" class="form-control" id="bsPrefix" name="prefix" maxlength="200" value="<?= $bsE($bsV('prefix')) ?>" placeholder="365cms/meine-site/"<?= $bsDisabled ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="bsAccessKey">Zugangsschlüssel (Access Key ID)</label>
                                <input type="text" class="form-control" id="bsAccessKey" name="access_key" maxlength="128" autocomplete="off" value="<?= $bsE($bsV('access_key')) ?>"<?= $bsDisabled ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="bsSecretKey">Geheimer Schlüssel</label>
                                <input type="password" class="form-control" id="bsSecretKey" name="secret_key" maxlength="256" autocomplete="new-password" placeholder="<?= !empty($bsData['has_secret']) ? 'gespeichert – leer lassen zum Behalten' : '' ?>" aria-describedby="bsSecretHint"<?= $bsDisabled ?>>
                                <div class="form-hint" id="bsSecretHint">Wird verschlüsselt gespeichert und nie angezeigt.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="bsPartSize">Teilgröße Multipart</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="bsPartSize" name="part_size_mb" min="5" max="64" value="<?= (int) $bsV('part_size_mb', '16') ?>"<?= $bsDisabled ?>>
                                    <span class="input-group-text">MB</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="bsStorageClass">Speicherklasse (optional)</label>
                                <input type="text" class="form-control" id="bsStorageClass" name="storage_class" maxlength="40" value="<?= $bsE($bsV('storage_class')) ?>" placeholder="STANDARD_IA"<?= $bsDisabled ?>>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <label class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" name="path_style" value="1"<?= $bsOn('path_style') . $bsDisabled ?>>
                                    <span class="form-check-label">Path-Style-Adressen (MinIO, Hetzner)</span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="bsKeepLast">Offsite behalten: letzte</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="bsKeepLast" name="keep_last" min="0" max="365" value="<?= (int) $bsV('keep_last', '7') ?>"<?= $bsDisabled ?>>
                                    <span class="input-group-text">Backups</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="bsKeepDays">und nicht älter als</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="bsKeepDays" name="keep_days" min="0" max="3650" value="<?= (int) $bsV('keep_days', '30') ?>"<?= $bsDisabled ?>>
                                    <span class="input-group-text">Tage</span>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-hint">0 = Grenze aus. Das zuletzt erfolgreich hochgeladene Backup wird nie gelöscht.</div>
                            </div>
                            <div class="col-12">
                                <details>
                                    <summary class="text-secondary">Selbst gehosteter Speicher im eigenen Netz</summary>
                                    <label class="form-check mt-2">
                                        <input type="checkbox" class="form-check-input" name="allow_private_host" value="1"<?= $bsOn('allow_private_host') . $bsDisabled ?>>
                                        <span class="form-check-label">Privaten Host erlauben (nur genau diesen Endpunkt, z. B. MinIO im LAN)</span>
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" class="form-check-input" name="insecure_http" value="1"<?= $bsOn('insecure_http') . $bsDisabled ?>>
                                        <span class="form-check-label">HTTP ohne TLS (nur zusammen mit privatem Host)</span>
                                    </label>
                                </details>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer d-flex gap-2">
                        <button type="submit" class="btn btn-primary"<?= $bsDisabled ?>><i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Speichern</button>
                        <button type="submit" class="btn btn-outline-secondary" form="bsTestForm"<?= $bsCanOffsite && !empty($bsData['configured']) ? '' : ' disabled' ?>><i class="ti ti-plug-connected me-1" aria-hidden="true"></i>Verbindung prüfen</button>
                    </div>
                </form>
                <form method="post" action="/admin/backup-settings" id="bsTestForm" hidden>
                    <input type="hidden" name="csrf_token" value="<?= $bsE($csrfToken) ?>">
                    <input type="hidden" name="action" value="test_offsite">
                </form>
            </div>

            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Offsite-Status</h3></div>
                    <?php if ($bsBackups === []): ?>
                        <div class="card-body"><p class="text-secondary mb-0">Noch keine Backups vorhanden.</p></div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($bsBackups as $bsBackup): ?>
                                <?php
                                $bsName = (string) ($bsBackup['name'] ?? '');
                                $bsRow = $bsStatus[$bsName] ?? null;
                                [$bsLabel, $bsClass] = $bsRow !== null ? ($bsStatusLabels[$bsRow['status']] ?? [$bsRow['status'], 'bg-secondary-lt']) : ['Nicht offsite', 'bg-secondary-lt'];
                                ?>
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <span class="text-truncate"><?= $bsE($bsName) ?><span class="d-block small text-secondary"><?= $bsE((string) ($bsBackup['date'] ?? '')) ?></span></span>
                                        <span class="badge <?= $bsE($bsClass) ?>"><?= $bsE($bsLabel) ?></span>
                                    </div>
                                    <?php if ($bsRow !== null && $bsRow['status'] === 'failed' && $bsRow['last_error'] !== ''): ?>
                                        <div class="small text-danger mt-1"><?= $bsE($bsRow['last_error']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($bsCanOffsite && !empty($bsData['configured']) && ($bsRow === null || $bsRow['status'] !== 'uploaded')): ?>
                                        <form method="post" action="/admin/backup-settings" class="mt-2">
                                            <input type="hidden" name="csrf_token" value="<?= $bsE($csrfToken) ?>">
                                            <input type="hidden" name="action" value="upload_now">
                                            <input type="hidden" name="backup_name" value="<?= $bsE($bsName) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Jetzt offsite kopieren</button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
