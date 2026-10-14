<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
if (!defined('CMS_ADMIN_PERFORMANCE_VIEW')) exit;

$media = $data['media'] ?? [];
$capacity = $data['capacity'] ?? [];
$library = $media['library'] ?? [];
$largestImages = $media['largest_images'] ?? [];
$conversion = $media['conversion'] ?? [];
$conversionCandidates = $conversion['candidates'] ?? [];
$settings = $data['settings'] ?? [];
$browserCacheTtl = (string)($settings['perf_browser_cache_ttl'] ?? '604800');
$browserCacheTtlOptions = [
    '259200' => '3 Tage',
    '604800' => '7 Tage (Standard)',
    '2678400' => '31 Tage',
];
if (!array_key_exists($browserCacheTtl, $browserCacheTtlOptions)) {
    $browserCacheTtl = '604800';
}
$capacityWarnings = is_array($capacity['warnings'] ?? null) ? $capacity['warnings'] : [];
$capacityJobs = is_array($capacity['active_jobs'] ?? null) ? $capacity['active_jobs'] : [];
$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2, ',', '.') . ' KB';
    return $bytes . ' B';
};
$buildConfirmMessage = static function (string $baseMessage) use ($capacity): string {
    $suffix = trim((string) ($capacity['confirm_suffix'] ?? ''));

    return $suffix !== '' ? $baseMessage . $suffix : $baseMessage;
};
$capacityStatus = (string) ($capacity['status'] ?? 'success');
$capacityStatusBadge = match ($capacityStatus) {
    'danger' => 'danger',
    'warning' => 'warning',
    default => 'success',
};
$diskFreeLabel = !empty($capacity['disk_total_bytes']) ? $formatBytes((int) ($capacity['disk_free_bytes'] ?? 0)) : '–';
$diskUsedLabel = ($capacity['disk_used_percent'] ?? null) !== null
    ? number_format((float) $capacity['disk_used_percent'], 1, ',', '.') . ' %'
    : '–';
$recommendedReserveLabel = $formatBytes((int) ($capacity['recommended_free_bytes'] ?? 0));
$loadLabel = ($capacity['load_1m'] ?? null) !== null
    ? number_format((float) $capacity['load_1m'], 2, ',', '.')
    : 'nicht verfügbar';
$rollbackManifestFileCount = 0;
$manifestFiles = glob(ABSPATH . 'cache/performance-webp-manifests/*.json') ?: [];
if ($manifestFiles !== []) {
    usort($manifestFiles, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $latestManifestPath = $manifestFiles[0] ?? null;
    if (is_string($latestManifestPath) && is_file($latestManifestPath)) {
        $manifestPayload = json_decode((string) file_get_contents($latestManifestPath), true);
        if (is_array($manifestPayload) && is_array($manifestPayload['entries'] ?? null)) {
            $rollbackManifestFileCount = count($manifestPayload['entries']);
        }
    }
}
$rollbackConfirmMessage = sprintf(
    'Im letzten Rollback-Manifest sind %d Datei%s erfasst. Wirklich diese WebP-Konvertierung zurückrollen? Referenzen werden zurückgesetzt und gesicherte Originale wiederhergestellt.',
    $rollbackManifestFileCount,
    $rollbackManifestFileCount === 1 ? '' : 'en'
);
?>
<div class="page-header d-print-none admin-redesign-header"><div class="container-xl"><div class="row g-2 align-items-center"><div class="col"><div class="page-pretitle">Performance</div><h2 class="page-title">Medien-Optimierung</h2><div class="text-secondary mt-1">Bildbibliothek, Alt-Texte, Dateigrößen und WebP-/EXIF-Strategie im Blick.</div></div></div></div></div>
<div class="page-body admin-redesign-page"><div class="container-xl admin-redesign-shell">
    <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
    <?php require __DIR__ . '/subnav.php'; ?>

    <div class="row row-deck row-cards mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">Bibliothek</div><div class="h1 mb-0"><?php echo (int)($library['total_files'] ?? 0); ?></div><div class="text-secondary">Dateien</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">Speicherbedarf</div><div class="h1 mb-0"><?php echo htmlspecialchars($formatBytes((int)($media['upload_size'] ?? 0))); ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">Fehlende Alt-Texte</div><div class="h1 mb-0 text-warning"><?php echo (int)($library['missing_alt'] ?? 0); ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">WebP-Dateien</div><div class="h1 mb-0"><?php echo (int)($library['webp_files'] ?? 0); ?></div></div></div></div>
    </div>

    <div class="card mb-4"><div class="card-header"><h3 class="card-title">Medien-Strategie</h3></div><div class="card-body"><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="action" value="save_media_settings"><div class="row"><div class="col-md-3"><label class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="perf_lazy_loading" value="1" <?php echo ($settings['perf_lazy_loading'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="form-check-label">Lazy Loading für CMS-Medien aktivieren</span></label></div><div class="col-md-3"><label class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="perf_webp_uploads" value="1" <?php echo ($settings['perf_webp_uploads'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="form-check-label">WebP-Begleitdateien bei Upload erzeugen</span></label></div><div class="col-md-3"><label class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="perf_strip_exif" value="1" <?php echo ($settings['perf_strip_exif'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="form-check-label">EXIF-Metadaten beim Upload entfernen</span></label></div><div class="col-md-3"><label for="fld-perf_lazy_loading_eager_images" class="form-label">Eager-Bilder am Seitenanfang</label><input id="fld-perf_lazy_loading_eager_images" type="number" min="0" max="5" step="1" class="form-control" name="perf_lazy_loading_eager_images" value="<?php echo htmlspecialchars((string)($settings['perf_lazy_loading_eager_images'] ?? '1')); ?>"></div></div><div class="row mt-3"><div class="col-md-4"><label class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="perf_browser_cache" value="1" <?php echo ($settings['perf_browser_cache'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="form-check-label">Browser-Caching für Medien aktivieren</span></label></div><div class="col-md-4"><label for="fld-perf_browser_cache_ttl" class="form-label">Medien Browser-Cache TTL</label><select id="fld-perf_browser_cache_ttl" class="form-select" name="perf_browser_cache_ttl"><?php foreach ($browserCacheTtlOptions as $ttlValue => $ttlLabel): ?><option value="<?php echo htmlspecialchars((string) $ttlValue, ENT_QUOTES); ?>" <?php echo $browserCacheTtl === (string) $ttlValue ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $ttlLabel, ENT_QUOTES); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><div class="form-hint mt-md-4">Gilt für <code>/media-file</code> und öffentliche direkte <code>/uploads</code>-Bilder, sofern Apache <code>.htaccess</code>-Regeln auswertet.</div></div></div><div class="form-text mb-3">Diese Optionen werden mit den Medien-Einstellungen synchronisiert und wirken auf neue Uploads sowie Editor.js-Ausgaben. Die ersten Eager-Bilder schützen Hero-/LCP-Medien vor versehentlichem Lazy Loading.</div><button type="submit" class="btn btn-primary">Medien-Einstellungen speichern</button></form></div></div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Kapazitäts-Pre-Check vor Medienjobs</h3>
            <span class="badge bg-<?php echo $capacityStatusBadge; ?>-lt"><?php echo htmlspecialchars((string) ($capacity['status_label'] ?? 'Bereit')); ?></span>
        </div>
        <div class="card-body">
            <div class="row row-cards mb-3">
                <div class="col-md-3"><div class="card card-sm"><div class="card-body"><div class="subheader">Freier Speicher</div><div class="h3 mb-0"><?php echo htmlspecialchars($diskFreeLabel); ?></div></div></div></div>
                <div class="col-md-3"><div class="card card-sm"><div class="card-body"><div class="subheader">Disk-Auslastung</div><div class="h3 mb-0"><?php echo htmlspecialchars($diskUsedLabel); ?></div></div></div></div>
                <div class="col-md-3"><div class="card card-sm"><div class="card-body"><div class="subheader">Empfohlener Puffer</div><div class="h3 mb-0"><?php echo htmlspecialchars($recommendedReserveLabel); ?></div></div></div></div>
                <div class="col-md-3"><div class="card card-sm"><div class="card-body"><div class="subheader">Last (1m)</div><div class="h3 mb-0"><?php echo htmlspecialchars($loadLabel); ?></div></div></div></div>
            </div>

            <?php if ($capacityWarnings !== []): ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($capacityWarnings as $warning): ?>
                        <div class="alert alert-<?php echo htmlspecialchars((string) (($warning['level'] ?? 'warning') === 'danger' ? 'danger' : 'warning')); ?> mb-0">
                            <strong><?php echo htmlspecialchars((string) ($warning['title'] ?? 'Warnung')); ?>:</strong>
                            <?php echo htmlspecialchars((string) ($warning['detail'] ?? '')); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-success mb-0">Die Medien-Optimierung sieht aktuell unkritisch aus. WebP kann also ohne Nervenkostüm-Schweißperlen anlaufen.</div>
            <?php endif; ?>

            <?php if ($capacityJobs !== []): ?>
                <div class="mt-3">
                    <div class="small text-secondary mb-2">Erkannte parallele Hintergrundjobs</div>
                    <ul class="mb-0 text-secondary small">
                        <?php foreach ($capacityJobs as $job): ?>
                            <li><?php echo htmlspecialchars((string) ($job['detail'] ?? ($job['label'] ?? 'Job'))); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div>
                <h3 class="card-title">WebP-Massenkonvertierung</h3>
                <div class="text-secondary small">Konvertiert geeignete Bilder batchweise, unterstützt Dry-Run und legt für ersetzte Originale ein Rollback-Manifest an.</div>
            </div>
            <form method="post" class="m-0" data-confirm-title="WebP-Konvertierung rückgängig machen?" data-confirm-message="<?php echo htmlspecialchars($rollbackConfirmMessage, ENT_QUOTES); ?>" data-confirm-text="Zurückrollen" data-confirm-class="btn-danger" data-confirm-status-class="bg-danger">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                <input type="hidden" name="action" value="rollback_webp_conversion">
                <button type="submit" class="btn btn-outline-warning">Letzte Konvertierung zurückrollen</button>
            </form>
        </div>
        <div class="card-body">
            <div class="row row-cards mb-3">
                <div class="col-md-4"><div class="card card-sm"><div class="card-body"><div class="subheader">Konvertierbare Bilder</div><div class="h2 mb-0"><?php echo (int)($conversion['convertible_files'] ?? 0); ?></div></div></div></div>
                <div class="col-md-4"><div class="card card-sm"><div class="card-body"><div class="subheader">Aktuelles Dateivolumen</div><div class="h2 mb-0"><?php echo htmlspecialchars($formatBytes((int)($conversion['convertible_bytes'] ?? 0))); ?></div></div></div></div>
                <div class="col-md-4"><div class="card card-sm"><div class="card-body"><div class="subheader">Server-Support</div><div class="h2 mb-0 <?php echo !empty($conversion['supported']) ? 'text-success' : 'text-danger'; ?>"><?php echo !empty($conversion['supported']) ? 'WebP bereit' : 'Nicht verfügbar'; ?></div></div></div></div>
            </div>

            <form method="post" class="card card-sm mb-3" data-confirm-title="WebP-Batch starten" data-confirm-message="<?php echo htmlspecialchars($buildConfirmMessage('WebP-Batch wirklich starten? Bei aktivierter Original-Ersetzung werden Originale gesichert, Referenzen angepasst und ein Rollback-Manifest geschrieben.'), ENT_QUOTES); ?>" data-confirm-text="Batch starten" data-confirm-class="btn-success" data-confirm-status-class="bg-success">
                <div class="card-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                    <input type="hidden" name="action" value="convert_media_to_webp">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label for="fld-webp_batch_limit" class="form-label">Batch-Größe</label>
                            <input id="fld-webp_batch_limit" type="number" class="form-control" name="webp_batch_limit" min="1" max="200" step="1" value="25">
                        </div>
                        <div class="col-md-4">
                            <label class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="webp_replace_originals" value="1">
                                <span class="form-check-label">Originale nach Referenzersatz sichern und entfernen</span>
                            </label>
                            <div class="form-hint">Ohne Haken bleiben Originaldateien erhalten; WebP-Dateien werden nur ergänzt und bekannte Referenzen angepasst.</div>
                        </div>
                        <div class="col-md-5 d-flex gap-2 justify-content-md-end">
                            <button type="submit" name="webp_mode" value="dry_run" class="btn btn-outline-primary" <?php echo empty($conversion['supported']) || empty($conversion['convertible_files']) ? 'disabled' : ''; ?>>Dry-Run prüfen</button>
                            <button type="submit" name="webp_mode" value="convert" class="btn btn-success" <?php echo empty($conversion['supported']) || empty($conversion['convertible_files']) ? 'disabled' : ''; ?>>Batch konvertieren</button>
                        </div>
                    </div>
                </div>
            </form>

            <?php if (empty($conversion['supported'])): ?>
                <div class="alert alert-warning mb-0">Auf diesem Server fehlt GD mit WebP-Support. Ohne das bleibt die WebP-Rakete leider am Boden.</div>
            <?php elseif (empty($conversionCandidates)): ?>
                <div class="alert alert-secondary mb-0">Keine JPG-, PNG- oder GIF-Dateien gefunden, die aktuell als Kandidaten für WebP infrage kommen.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table table-striped">
                        <thead><tr><th>Datei</th><th>Format</th><th>Größe</th></tr></thead>
                        <tbody>
                        <?php foreach ($conversionCandidates as $candidate): ?>
                            <tr>
                                <td class="text-break"><?php echo htmlspecialchars((string)($candidate['path'] ?? '')); ?></td>
                                <td><span class="badge bg-secondary-lt"><?php echo htmlspecialchars(strtoupper((string)($candidate['extension'] ?? ''))); ?></span></td>
                                <td><?php echo htmlspecialchars($formatBytes((int)($candidate['size'] ?? 0))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card"><div class="card-header"><h3 class="card-title">Größte Bilder</h3></div><div class="table-responsive"><table class="table table-vcenter card-table table-striped"><thead><tr><th>Datei</th><th>Größe</th><th>Dimensionen</th><th>Format</th></tr></thead><tbody><?php if (empty($largestImages)): ?><tr><td colspan="4" class="text-center text-secondary py-4">Keine Bilddaten gefunden.</td></tr><?php else: ?><?php foreach ($largestImages as $image): ?><tr><td class="text-break"><?php echo htmlspecialchars((string)$image['path']); ?></td><td><?php echo htmlspecialchars($formatBytes((int)$image['size'])); ?></td><td><?php echo (int)$image['width']; ?> × <?php echo (int)$image['height']; ?></td><td><span class="badge bg-<?php echo !empty($image['is_webp']) ? 'success' : 'secondary'; ?>-lt"><?php echo !empty($image['is_webp']) ? 'WebP' : 'Klassisch'; ?></span></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div>
</div></div>
