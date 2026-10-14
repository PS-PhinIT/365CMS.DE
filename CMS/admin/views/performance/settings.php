<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
if (!defined('CMS_ADMIN_PERFORMANCE_VIEW')) exit;

$settings = $data['settings'] ?? [];
$phpInfo = $data['php_info'] ?? [];
$browserCacheTtl = (string)($settings['perf_browser_cache_ttl'] ?? '604800');
$browserCacheTtlOptions = [
    '259200' => '3 Tage',
    '604800' => '7 Tage (Standard)',
    '2678400' => '31 Tage',
];
if (!array_key_exists($browserCacheTtl, $browserCacheTtlOptions)) {
    $browserCacheTtl = '604800';
}
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Performance</div>
                <h2 class="page-title mb-1">Performance-Einstellungen</h2>
                <div class="content-listing-header__meta">
                    <span>Frontend-Auslieferung</span>
                    <span>Caching &amp; Sessions zentral</span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <a class="btn btn-outline-secondary btn-sm" href="/admin/performance">Übersicht</a>
                <a class="btn btn-outline-primary btn-sm" href="/admin/performance-cache">Cache</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-performance-page admin-redesign-page">
    <div class="container-xl cms-settings-page admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alert['type'] ?? 'info'); ?> mb-4"><?php echo htmlspecialchars($alert['message'] ?? ''); ?></div>
        <?php endif; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <form method="post" class="admin-content-card performance-settings-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="cms-settings-actions">
                <span class="text-secondary small me-auto">Primäre Schalter zuerst aktivieren, danach TTL- und Session-Werte anpassen.</span>
            </div>

            <div class="row g-4 mb-4 performance-delivery-grid">
                <div class="col-12">
                    <h3 class="cms-settings-section-heading">Auslieferung und Frontend</h3>
                </div>
                <div class="col-12 col-xl-8">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title">Frontend-Auslieferung</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="perf_lazy_loading" value="1" <?php echo ($settings['perf_lazy_loading'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Lazy Loading für Medien aktivieren</span>
                                    </label>
                                    <label class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="perf_minify_css" value="1" <?php echo ($settings['perf_minify_css'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                        <span class="form-check-label">CSS lokal minifizieren</span>
                                    </label>
                                    <label class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="perf_minify_js" value="1" <?php echo ($settings['perf_minify_js'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                        <span class="form-check-label">JS lokal minifizieren</span>
                                    </label>
                                    <div class="alert alert-info mb-0">
                                        <div class="fw-semibold mb-1">Server-Kompression</div>
                                        <div class="text-secondary small">
                                            GZIP/Brotli wird serverseitig über Apache-/Proxy-Konfiguration bereitgestellt und hier als Status angezeigt.
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-info mb-0">
                                        <div class="fw-semibold mb-1">Fonts & DSGVO</div>
                                        <div class="text-secondary">
                                            Schriftarten werden separat im <a href="<?php echo htmlspecialchars('/admin/font-manager'); ?>">Font Manager</a> verwaltet. Dort kannst du externe Theme-Fonts scannen und lokal self-hosten.
                                        </div>
                                    </div>
                                    <div class="form-text mt-3">
                                        Hinweis: Lazy Loading und Cache-Header wirken direkt. Medien-WebP/EXIF werden auf der Medien-Unterseite gespeichert. CSS/JS-Minify erzeugt lokale Cache-Dateien; Server-Kompression läuft ausschließlich über Apache/Brotli/GZIP-Konfiguration.
                                    </div>
                                    <div class="mt-3 small text-secondary">
                                        Kompression erkannt: zlib <?php echo !empty($phpInfo['gzip_enabled']) ? 'aktiv' : 'inaktiv'; ?>, Apache Brotli-Regel <?php echo !empty($phpInfo['brotli_configured']) ? 'vorhanden' : 'nicht gefunden'; ?>, Deflate-Regel <?php echo !empty($phpInfo['deflate_configured']) ? 'vorhanden' : 'nicht gefunden'; ?>.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title">Betriebsleitfaden</h3>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0 text-secondary">
                                <li class="mb-2">• Browser-Caching aktivieren</li>
                                <li class="mb-2">• HTML-/Seiten-Cache mit kurzer TTL nutzen</li>
                                <li class="mb-2">• Bilder per Lazy Loading verzögert laden</li>
                                <li class="mb-2">• CSS/JS nur nach Test minifizieren</li>
                                <li class="mb-0">• Externe Fonts möglichst lokal hosten</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4 performance-runtime-grid">
                <div class="col-12">
                    <h3 class="cms-settings-section-heading">Caching und Sessions</h3>
                </div>
                <div class="col-12 col-xl-8">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title">Caching</h3>
                        </div>
                        <div class="card-body">
                            <label class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="perf_page_cache" value="1" <?php echo ($settings['perf_page_cache'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <span class="form-check-label">Seiten-Cache aktivieren</span>
                            </label>
                            <label class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="perf_browser_cache" value="1" <?php echo ($settings['perf_browser_cache'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <span class="form-check-label">Browser-Caching-Header aktivieren</span>
                            </label>
                            <label class="form-check form-switch mb-4">
                                <input class="form-check-input" type="checkbox" name="perf_auto_clear_content_cache" value="1" <?php echo ($settings['perf_auto_clear_content_cache'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <span class="form-check-label">Cache bei Inhaltsänderungen automatisch leeren</span>
                            </label>

                            <div class="mb-3">
                                <label for="fld-perf_browser_cache_ttl" class="form-label">Bildauslieferung Cache-TTL</label>
                                <select id="fld-perf_browser_cache_ttl" class="form-select" name="perf_browser_cache_ttl">
                                    <?php foreach ($browserCacheTtlOptions as $ttlValue => $ttlLabel): ?>
                                        <option value="<?php echo htmlspecialchars((string) $ttlValue, ENT_QUOTES); ?>" <?php echo $browserCacheTtl === (string) $ttlValue ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $ttlLabel, ENT_QUOTES); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Steuert die Browser-Cache-Dauer für öffentlich ausgelieferte Medien über <code>/media-file</code> und direkte <code>/uploads</code>-Bilder per Upload-<code>.htaccess</code>. Standard sind 7 Tage.</div>
                            </div>
                            <div class="mb-0">
                                <label for="fld-perf_html_cache_ttl" class="form-label">HTML-/Seiten-Cache TTL (Sekunden)</label>
                                <input id="fld-perf_html_cache_ttl" type="number" min="0" max="86400" step="1" class="form-control" name="perf_html_cache_ttl" value="<?php echo htmlspecialchars((string)($settings['perf_html_cache_ttl'] ?? '300')); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title">Sessions</h3>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="fld-perf_session_timeout_admin" class="form-label">Admin-Session Timeout (Sekunden)</label>
                                <input id="fld-perf_session_timeout_admin" type="number" min="300" max="604800" step="1" class="form-control" name="perf_session_timeout_admin" value="<?php echo htmlspecialchars((string)($settings['perf_session_timeout_admin'] ?? '28800')); ?>" data-timeout-human>
                                <div class="performance-timeout-hint" data-timeout-hint-for="perf_session_timeout_admin"></div>
                            </div>
                            <div class="mb-0">
                                <label for="fld-perf_session_timeout_member" class="form-label">Member-Session Timeout (Sekunden)</label>
                                <input id="fld-perf_session_timeout_member" type="number" min="300" max="31536000" step="1" class="form-control" name="perf_session_timeout_member" value="<?php echo htmlspecialchars((string)($settings['perf_session_timeout_member'] ?? '2592000')); ?>" data-timeout-human>
                                <div class="performance-timeout-hint" data-timeout-hint-for="perf_session_timeout_member"></div>
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
<script <?= \CMS\Security::instance()->nonceAttr() ?>>
(() => {
    const formatTimeoutHint = (secondsValue) => {
        if (!Number.isFinite(secondsValue)) {
            return '= 0 Sekunden';
        }

        const seconds = Math.max(0, Math.floor(secondsValue));
        let unit = 'Sekunden';
        let value = seconds;

        if (seconds >= 86400) {
            unit = 'Tage';
            value = seconds / 86400;
        } else if (seconds >= 3600) {
            unit = 'Stunden';
            value = seconds / 3600;
        } else if (seconds >= 60) {
            unit = 'Minuten';
            value = seconds / 60;
        }

        const formatted = Number.isInteger(value)
            ? String(value)
            : value.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

        return `= ${formatted} ${unit}`;
    };

    document.querySelectorAll('input[data-timeout-human]').forEach((input) => {
        const hint = document.querySelector(`[data-timeout-hint-for="${input.name}"]`);
        if (!hint) {
            return;
        }

        const refresh = () => {
            hint.textContent = formatTimeoutHint(Number.parseInt(input.value, 10) || 0);
        };

        input.addEventListener('input', refresh);
        input.addEventListener('change', refresh);
        refresh();
    });
})();
</script>
