<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Media – Einstellungen View
 *
 * Erwartet: $data (aus MediaModule::getSettingsData())
 *           $alert, $csrfToken
 */

$settings  = $data['settings'] ?? [];
$diskUsage = $data['diskUsage'] ?? [];
$options = $data['options'] ?? [];
$constraints = is_array($data['constraints'] ?? null) ? $data['constraints'] : [];
$processingJob = is_array($data['processing_job'] ?? null) ? $data['processing_job'] : [];
$allTypes = is_array($options['allowed_types'] ?? null) ? $options['allowed_types'] : [];
$memberTypes = is_array($options['member_allowed_types'] ?? null) ? $options['member_allowed_types'] : [];
$thumbnailSizes = is_array($options['thumbnail_sizes'] ?? null) ? $options['thumbnail_sizes'] : [];
$s = is_array($settings) ? $settings : [];
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row align-items-center">
            <div class="col-auto">
                <div class="page-pretitle">Medienverwaltung</div>
                <h2 class="page-title">Einstellungen</h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl cms-settings-page">

        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-3'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <div class="cms-admin-info-box mb-3" role="note">
            <div class="cms-admin-info-box__head">
                <h3 class="cms-admin-info-box__title">Grenzwerte für Upload und Verarbeitung</h3>
            </div>
            <p class="cms-admin-info-box__text">
                Upload-Größen liegen zwischen <?php echo (int)($constraints['min_upload_size_mb'] ?? 1); ?> und <?php echo (int)($constraints['max_upload_size_mb'] ?? 256); ?> MB,
                JPEG-Qualität zwischen <?php echo (int)($constraints['jpeg_quality_min'] ?? 60); ?> und <?php echo (int)($constraints['jpeg_quality_max'] ?? 100); ?>,
                Bildmaße zwischen <?php echo (int)($constraints['dimension_min'] ?? 1); ?> und <?php echo (int)($constraints['dimension_max'] ?? 8000); ?> px
                sowie Thumbnail-Kanten zwischen <?php echo (int)($constraints['thumbnail_min'] ?? 50); ?> und <?php echo (int)($constraints['thumbnail_max'] ?? 6000); ?> px.
            </p>
        </div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="cms-settings-actions">
                <span class="text-secondary small me-auto">Upload-, Bild- und Sicherheitsregeln gelten systemweit.</span>
                <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
            </div>

            <div class="row">
                <!-- Hauptbereich -->
                <div class="col-12">
                    <h3 class="cms-settings-section-heading">Kernkonfiguration</h3>

                    <!-- Upload-Einstellungen -->
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">Upload-Einstellungen</h3></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label" for="maxUploadSize">Maximale Upload-Größe (MB)</label>
                                <input type="number" class="form-control" id="maxUploadSize" name="max_upload_size" value="<?php echo (int)($s['max_upload_size'] ?? 64); ?>" min="<?php echo (int)($constraints['min_upload_size_mb'] ?? 1); ?>" max="<?php echo (int)($constraints['max_upload_size_mb'] ?? 256); ?>" step="1" inputmode="numeric">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Erlaubte Dateitypen</label>
                                <div class="form-hint mb-2">SVG-Uploads sind gemäß Audit aus Sicherheitsgründen vollständig deaktiviert.</div>
                                <div class="row">
                                    <?php
                                    $active = (array)$s['allowed_types'];
                                    foreach ($allTypes as $type): ?>
                                        <div class="col-4 col-sm-3 col-md-2 mb-1">
                                            <label class="form-check">
                                                <input type="checkbox" class="form-check-input" name="allowed_types[]" value="<?php echo htmlspecialchars((string) $type, ENT_QUOTES); ?>" <?php echo in_array($type, $active, true) ? 'checked' : ''; ?>>
                                                <span class="form-check-label">.<?php echo htmlspecialchars((string) $type); ?></span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-sm-6">
                                    <label class="form-check form-switch mb-2">
                                        <input type="checkbox" class="form-check-input" name="organize_month_year" value="1" <?php echo !empty($s['organize_month_year']) ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Datumsordner Jahr/Monat/Tag anlegen</span>
                                    </label>
                                    <div class="form-hint mb-2">Aus = Upload in den aktuell geöffneten Ordner (Standard). An = legt darunter automatisch <code>YYYY/MM/DD</code> an.</div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-check form-switch mb-2">
                                        <input type="checkbox" class="form-check-input" name="sanitize_filename" value="1" <?php echo !empty($s['sanitize_filename']) ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Dateinamen bereinigen</span>
                                    </label>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-check form-switch mb-2">
                                        <input type="checkbox" class="form-check-input" name="unique_filename" value="1" <?php echo !empty($s['unique_filename']) ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Eindeutige Dateinamen</span>
                                    </label>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-check form-switch mb-2">
                                        <input type="checkbox" class="form-check-input" name="lowercase_filename" value="1" <?php echo !empty($s['lowercase_filename']) ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Dateinamen in Kleinbuchstaben</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bild-Verarbeitung -->
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">Bild-Verarbeitung</h3></div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-sm-6">
                                    <label class="form-check form-switch mb-2">
                                        <input type="checkbox" class="form-check-input" name="auto_webp" value="1" <?php echo !empty($s['auto_webp']) ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Automatisch zu WebP konvertieren</span>
                                    </label>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-check form-switch mb-2">
                                        <input type="checkbox" class="form-check-input" name="strip_exif" value="1" <?php echo !empty($s['strip_exif']) ? 'checked' : ''; ?>>
                                        <span class="form-check-label">EXIF-Daten entfernen</span>
                                    </label>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-sm-4 mb-3">
                                    <label class="form-label" for="jpegQuality">JPEG-Qualität (%)</label>
                                    <input type="number" class="form-control" id="jpegQuality" name="jpeg_quality" value="<?php echo (int)($s['jpeg_quality'] ?? 85); ?>" min="<?php echo (int)($constraints['jpeg_quality_min'] ?? 60); ?>" max="<?php echo (int)($constraints['jpeg_quality_max'] ?? 100); ?>" inputmode="numeric">
                                </div>
                                <div class="col-sm-4 mb-3">
                                    <label class="form-label" for="maxWidth">Max. Breite (px)</label>
                                    <input type="number" class="form-control" id="maxWidth" name="max_width" value="<?php echo (int)($s['max_width'] ?? 2560); ?>" min="<?php echo (int)($constraints['dimension_min'] ?? 1); ?>" max="<?php echo (int)($constraints['dimension_max'] ?? 8000); ?>" inputmode="numeric">
                                    <small class="form-hint">Wertebereich: 1 bis 8000 px</small>
                                </div>
                                <div class="col-sm-4 mb-3">
                                    <label class="form-label" for="maxHeight">Max. Höhe (px)</label>
                                    <input type="number" class="form-control" id="maxHeight" name="max_height" value="<?php echo (int)($s['max_height'] ?? 2560); ?>" min="<?php echo (int)($constraints['dimension_min'] ?? 1); ?>" max="<?php echo (int)($constraints['dimension_max'] ?? 8000); ?>" inputmode="numeric">
                                    <small class="form-hint">Wertebereich: 1 bis 8000 px</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Thumbnails -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title">Thumbnails</h3>
                            <div class="card-actions">
                                <label class="form-check form-switch m-0">
                                    <input type="checkbox" class="form-check-input" name="generate_thumbnails" value="1" <?php echo !empty($s['generate_thumbnails']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Aktiviert</span>
                                </label>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php
                                foreach ($thumbnailSizes as $sizeConfig):
                                    $label = (string) ($sizeConfig['label'] ?? 'Format');
                                    $wKey = (string) ($sizeConfig['width_field'] ?? '');
                                    $hKey = (string) ($sizeConfig['height_field'] ?? '');

                                    if ($wKey === '' || $hKey === '') {
                                        continue;
                                    }
                                ?>
                                    <div class="col-sm-6 col-md-3 mb-3">
                                        <label class="form-label fw-bold" for="thumb-size-<?php echo htmlspecialchars($wKey, ENT_QUOTES); ?>"><?php echo htmlspecialchars($label); ?></label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control" id="thumb-size-<?php echo htmlspecialchars($wKey, ENT_QUOTES); ?>" aria-label="<?php echo htmlspecialchars($label . ' – Breite', ENT_QUOTES); ?>" name="<?php echo htmlspecialchars($wKey, ENT_QUOTES); ?>" value="<?php echo (int)($s[$wKey] ?? 0); ?>" min="<?php echo (int)($constraints['thumbnail_min'] ?? 50); ?>" max="<?php echo (int)($constraints['thumbnail_max'] ?? 6000); ?>" inputmode="numeric" placeholder="B">
                                            <span class="input-group-text">×</span>
                                            <input type="number" class="form-control" aria-label="<?php echo htmlspecialchars($label . ' – Höhe', ENT_QUOTES); ?>" name="<?php echo htmlspecialchars($hKey, ENT_QUOTES); ?>" value="<?php echo (int)($s[$hKey] ?? 0); ?>" min="<?php echo (int)($constraints['thumbnail_min'] ?? 50); ?>" max="<?php echo (int)($constraints['thumbnail_max'] ?? 6000); ?>" inputmode="numeric" placeholder="H">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Member-Uploads -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title">Member-Uploads</h3>
                            <div class="card-actions">
                                <label class="form-check form-switch m-0">
                                    <input type="checkbox" class="form-check-input" name="member_uploads_enabled" value="1" <?php echo !empty($s['member_uploads_enabled']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Aktiviert</span>
                                </label>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-sm-6">
                                    <label class="form-label" for="memberMaxUpload">Max. Upload-Größe (MB)</label>
                                    <input type="number" class="form-control" id="memberMaxUpload" name="member_max_upload_size" value="<?php echo (int)($s['member_max_upload_size'] ?? 5); ?>" min="<?php echo (int)($constraints['min_upload_size_mb'] ?? 1); ?>" max="<?php echo (int)($constraints['max_upload_size_mb'] ?? 256); ?>" inputmode="numeric">
                                </div>
                                <div class="col-sm-6 d-flex align-items-end">
                                    <label class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input" name="member_delete_own" value="1" <?php echo !empty($s['member_delete_own']) ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Eigene Dateien löschen erlauben</span>
                                    </label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Erlaubte Dateitypen (Member)</label>
                                <div class="row">
                                    <?php
                                    $mActive = (array)$s['member_allowed_types'];
                                    foreach ($memberTypes as $type): ?>
                                        <div class="col-4 col-sm-3 col-md-2 mb-1">
                                            <label class="form-check">
                                                <input type="checkbox" class="form-check-input" name="member_allowed_types[]" value="<?php echo htmlspecialchars((string) $type, ENT_QUOTES); ?>" <?php echo in_array($type, $mActive, true) ? 'checked' : ''; ?>>
                                                <span class="form-check-label">.<?php echo htmlspecialchars((string) $type); ?></span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-12">
                    <h3 class="cms-settings-section-heading">Betrieb und Kontrolle</h3>
                    <!-- Hintergrundverarbeitung -->
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">WebP-/Thumbnail-Job</h3></div>
                        <div class="card-body">
                            <?php
                            $jobExists = !empty($processingJob['exists']);
                            $jobActive = !empty($processingJob['is_active']);
                            $jobStatus = (string)($processingJob['status'] ?? 'none');
                            $jobPercent = max(0, min(100, (int)($processingJob['percent'] ?? 0)));
                            $jobTotal = max(0, (int)($processingJob['total'] ?? 0));
                            $jobProcessed = max(0, (int)($processingJob['processed'] ?? 0));
                            ?>
                            <p class="text-secondary small mb-3">
                                Bestehende Bilder werden in kleinen Server-Schritten verarbeitet (max.
                                <?php echo (int)($constraints['processing_batch_size'] ?? 5); ?> pro Klick), damit keine langen Requests oder 500er entstehen.
                            </p>

                            <?php if ($jobExists): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="small text-secondary">Status: <?php echo htmlspecialchars($jobStatus); ?></span>
                                        <strong class="small"><?php echo $jobPercent; ?>%</strong>
                                    </div>
                                    <div class="progress" role="progressbar" aria-valuenow="<?php echo $jobPercent; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Medienjob-Fortschritt">
                                        <div class="progress-bar" style="width: <?php echo $jobPercent; ?>%"></div>
                                    </div>
                                    <div class="small text-secondary mt-1">
                                        <?php echo $jobProcessed; ?> / <?php echo $jobTotal; ?> Datei(en),
                                        erzeugt <?php echo (int)($processingJob['succeeded'] ?? 0); ?>,
                                        übersprungen <?php echo (int)($processingJob['skipped'] ?? 0); ?>,
                                        Fehler <?php echo (int)($processingJob['failed'] ?? 0); ?>.
                                    </div>
                                </div>

                                <?php if (!empty($processingJob['last_errors']) && is_array($processingJob['last_errors'])): ?>
                                    <div class="alert alert-warning py-2 mb-3" role="alert">
                                        <strong>Letzte Fehler:</strong>
                                        <ul class="mb-0 ps-3">
                                            <?php foreach (array_slice($processingJob['last_errors'], 0, 3) as $jobError): ?>
                                                <li><?php echo htmlspecialchars((string)$jobError); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label" for="processingMode">Job-Typ</label>
                                <select class="form-select" id="processingMode" name="processing_mode" <?php echo $jobActive ? 'disabled' : ''; ?>>
                                    <option value="all">WebP + Thumbnails nachziehen</option>
                                    <option value="webp">Nur WebP erzeugen</option>
                                    <option value="thumbnails">Nur Thumbnails erzeugen</option>
                                </select>
                                <div class="form-hint">
                                    Maximal <?php echo (int)($constraints['processing_max_candidates'] ?? 1000); ?> Quellbilder pro Job; vorhandene Thumbnail-Derivate werden nicht erneut in die Queue gelegt.
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <?php if ($jobActive): ?>
                                    <button type="submit" class="btn btn-primary" name="action" value="process_media_processing_job">Nächsten Batch verarbeiten</button>
                                    <button type="submit" class="btn btn-outline-danger" name="action" value="cancel_media_processing_job">Job abbrechen</button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-outline-primary" name="action" value="start_media_processing_job">Neuen Job vorbereiten</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sicherheit -->
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">Sicherheit</h3></div>
                        <div class="card-body">
                            <label class="form-check form-switch mb-2">
                                <input type="checkbox" class="form-check-input" name="block_dangerous_types" value="1" <?php echo !empty($s['block_dangerous_types']) ? 'checked' : ''; ?>>
                                <span class="form-check-label">Gefährliche Dateitypen blockieren</span>
                            </label>
                            <label class="form-check form-switch mb-2">
                                <input type="checkbox" class="form-check-input" name="validate_image_content" value="1" <?php echo !empty($s['validate_image_content']) ? 'checked' : ''; ?>>
                                <span class="form-check-label">Bild-Inhalte validieren</span>
                            </label>
                            <div class="alert alert-secondary py-2 mb-2" role="note">
                                <strong>Authentifizierung:</strong> Der interne Upload-Endpunkt bleibt aus Sicherheitsgründen immer auf angemeldete Admin- bzw. Member-Kontexte beschränkt.
                            </div>
                            <label class="form-check form-switch mb-0">
                                <input type="checkbox" class="form-check-input" name="protect_uploads_dir" value="1" <?php echo !empty($s['protect_uploads_dir']) ? 'checked' : ''; ?>>
                                <span class="form-check-label">Uploads-Verzeichnis schützen</span>
                            </label>
                        </div>
                    </div>

                    <!-- Speicher-Info -->
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">Speicherplatz</h3></div>
                        <div class="card-body">
                            <div class="mb-2 d-flex justify-content-between">
                                <span>Dateien</span>
                                <strong><?php echo (int)($diskUsage['count'] ?? 0); ?></strong>
                            </div>
                            <div class="mb-2 d-flex justify-content-between">
                                <span>Speicher</span>
                                <strong><?php echo htmlspecialchars($diskUsage['formatted'] ?? '0 B'); ?></strong>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
            <div class="cms-settings-actions cms-settings-actions-bottom">
                <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
            </div>
        </form>

    </div>
</div>
