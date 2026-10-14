<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SEO_VIEW')) {
    exit;
}

$analytics = $data['analytics'] ?? [];
$stats = $analytics['visitor_stats'] ?? [];
$topPages = $analytics['top_pages'] ?? [];
$featureUsage = $analytics['feature_usage'] ?? [];
$featureTotals = $featureUsage['totals'] ?? [];
$featureTop = $featureUsage['top_features'] ?? [];
$featureAreas = $featureUsage['area_breakdown'] ?? [];
$daily = $analytics['daily_traffic'] ?? [];
$referrers = $analytics['referrers'] ?? [];
$backlinks = $analytics['backlinks'] ?? [];
$internalLinkSuggestions = $analytics['internal_link_suggestions'] ?? [];
$coreWebVitals = $analytics['core_web_vitals'] ?? [];
$coreWebVitalsMetrics = $coreWebVitals['metrics'] ?? [];
$coreWebVitalsPages = $coreWebVitals['problem_pages'] ?? [];
$trackingSettings = $analytics['tracking_settings'] ?? [];
$hasTable = $analytics['has_page_views'] ?? false;
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">SEO</div>
                <h2 class="page-title mb-1">Analytics Übersicht</h2>
                <div class="content-listing-header__meta">
                    <span>Page Views 30 Tage: <?= number_format((int)($stats['total'] ?? 0)) ?></span>
                    <span>Unique: <?= number_format((int)($stats['unique'] ?? 0)) ?></span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <a class="btn btn-outline-secondary btn-sm" href="/admin/seo-dashboard">Dashboard</a>
                <a class="btn btn-outline-primary btn-sm" href="/admin/seo-technical">Technik</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">

        <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <?php if (!$hasTable): ?>
            <div class="alert alert-info">
                <div class="d-flex">
                    <div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0"/><path d="M12 9h.01"/><path d="M11 12h1v4h1"/></svg>
                    </div>
                    <div>
                        <h4 class="alert-title">Page-View-Tracking nicht aktiv</h4>
                        <div class="text-secondary">Die Tabelle <code>page_views</code> existiert noch nicht. Aktivieren Sie das interne Tracking, um Besucherstatistiken zu erfassen.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row row-deck row-cards mb-4 admin-metric-grid">
            <div class="col-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Seitenaufrufe 30 Tage</div>
                        <div class="h1 mb-0"><?= number_format((int)($stats['total'] ?? 0)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Unique Visitors</div>
                        <div class="h1 mb-0"><?= number_format((int)($stats['unique'] ?? 0)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Absprungrate</div>
                        <div class="h1 mb-0"><?= htmlspecialchars((string)($stats['bounce_rate'] ?? '0%')) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Ø Sessiondauer</div>
                        <div class="h1 mb-0"><?= htmlspecialchars((string)($stats['avg_session_duration'] ?? '0s')) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Feature-Events 30 Tage</div>
                        <div class="h1 mb-0"><?= number_format((int)($featureTotals['total_events'] ?? 0)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Aktiv genutzte Features</div>
                        <div class="h1 mb-0"><?= number_format((int)($featureTotals['unique_features'] ?? 0)) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Ranking-/Traffic-Verlauf (30 Tage)</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead><tr><th>Tag</th><th class="text-end">Views</th></tr></thead>
                            <tbody>
                                <?php if (empty($daily)): ?>
                                    <tr><td colspan="2" class="text-center text-secondary py-3">Keine Trenddaten vorhanden.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($daily as $row): ?>
                                        <tr><td><?= htmlspecialchars((string)($row['day'] ?? '')) ?></td><td class="text-end"><strong><?= number_format((int)($row['views'] ?? 0)) ?></strong></td></tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card mt-4">
                    <div class="card-header"><h3 class="card-title">Top-Seiten (30 Tage)</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr><th>URL</th><th class="text-end">Aufrufe</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topPages)): ?>
                                    <tr><td colspan="2" class="text-center text-secondary py-3">Keine Daten vorhanden.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($topPages as $page): ?>
                                        <tr>
                                                <td><code class="small"><?= htmlspecialchars((string)$page['page_slug']) ?></code></td>
                                                <td class="text-end"><strong><?= number_format((int)$page['views']) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card mt-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title mb-0">Top-Features (30 Tage)</h3>
                        <span class="badge bg-blue-lt"><?= number_format((int)($featureTotals['unique_actors'] ?? 0)) ?> aktive Akteure</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr><th>Feature</th><th>Bereich</th><th class="text-end">Nutzungen</th><th class="text-end">Nutzer/Sessions</th><th>Zuletzt</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($featureTop)): ?>
                                    <tr><td colspan="5" class="text-center text-secondary py-3">Noch keine Feature-Metriken vorhanden.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($featureTop as $feature): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars((string)($feature['feature_label'] ?? 'Feature')) ?></strong>
                                                <div class="text-secondary small"><code><?= htmlspecialchars((string)($feature['feature_key'] ?? '')) ?></code></div>
                                            </td>
                                            <td>
                                                <span class="badge bg-azure-lt text-azure"><?= htmlspecialchars((string)($feature['feature_area'] ?? '')) ?></span>
                                                <?php if (!empty($feature['plugin_slug'])): ?>
                                                    <div class="text-secondary small mt-1">Plugin: <?= htmlspecialchars((string)$feature['plugin_slug']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><strong><?= number_format((int)($feature['total_uses'] ?? 0)) ?></strong></td>
                                            <td class="text-end"><?= number_format((int)($feature['unique_actors'] ?? 0)) ?></td>
                                            <td><?= htmlspecialchars((string)($feature['last_used_at'] ?? '—')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer text-secondary small">
                        <?= htmlspecialchars((string)($featureUsage['note'] ?? '')) ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Feature-Mix</h3></div>
                    <div class="list-group list-group-flush">
                        <?php if (empty($featureAreas)): ?>
                            <div class="list-group-item text-secondary text-center small">Noch keine Bereichsdaten vorhanden.</div>
                        <?php else: ?>
                            <?php foreach ($featureAreas as $area): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars((string)($area['feature_area'] ?? '')) ?></div>
                                        <div class="text-secondary small"><?= number_format((int)($area['feature_count'] ?? 0)) ?> Features · <?= number_format((int)($area['unique_actors'] ?? 0)) ?> Akteure</div>
                                    </div>
                                    <span class="badge bg-blue-lt"><?= number_format((int)($area['total_uses'] ?? 0)) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title mb-0">Core Web Vitals (Felddaten)</h3>
                        <div class="d-inline-flex align-items-center gap-2">
                            <span class="badge bg-blue-lt"><?= number_format((int)($coreWebVitals['sample_count'] ?? 0)) ?> Samples</span>
                            <i
                                class="ti ti-info-circle text-secondary admin-cwv-hint-icon"
                                aria-label="Hinweis zu Core Web Vitals Felddaten"
                                title="Felddaten werden erst nach ausreichend realen Seitenbesuchen im CrUX-Datensatz verfügbar."
                            ></i>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="datagrid mb-3">
                            <?php foreach (['ttfb' => 'TTFB', 'lcp' => 'LCP', 'inp' => 'INP', 'cls' => 'CLS'] as $metricKey => $metricLabel): ?>
                                <?php $metric = $coreWebVitalsMetrics[$metricKey] ?? []; ?>
                                <?php $badgeClass = match ((string)($metric['rating'] ?? 'unknown')) {
                                    'good' => 'bg-green-lt text-green',
                                    'needs-improvement' => 'bg-yellow-lt text-yellow',
                                    'poor' => 'bg-red-lt text-red',
                                    default => 'bg-secondary-lt text-secondary',
                                }; ?>
                                <div class="datagrid-item">
                                    <div class="datagrid-title"><?= htmlspecialchars($metricLabel) ?></div>
                                    <div class="datagrid-content d-flex align-items-center justify-content-between gap-2">
                                        <span><?= htmlspecialchars((string)($metric['display'] ?? '—')) ?></span>
                                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars((string)($metric['rating_label'] ?? 'Keine Daten')) ?></span>
                                    </div>
                                    <div class="text-secondary small mt-1"><?= htmlspecialchars((string)($metric['threshold'] ?? '')) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-hint"><?= htmlspecialchars((string)($coreWebVitals['note'] ?? '')) ?></div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Backlink-/Referrer-Monitoring</h3></div>
                    <div class="list-group list-group-flush">
                        <?php if (empty($backlinks)): ?>
                            <div class="list-group-item text-secondary text-center small">Keine Daten</div>
                        <?php else: ?>
                            <?php foreach ($backlinks as $ref): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="text-truncate small"><?= htmlspecialchars((string)$ref['domain']) ?></span>
                                    <span class="badge bg-blue-lt"><?= (int)$ref['hits'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-1">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Langsamste Seiten (Felddaten)</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead><tr><th>Pfad</th><th class="text-end">Samples</th><th class="text-end">Ø LCP</th><th class="text-end">Ø INP</th></tr></thead>
                            <tbody>
                                <?php if (empty($coreWebVitalsPages)): ?>
                                    <tr><td colspan="4" class="text-center text-secondary py-4">Noch keine aussagekräftigen CWV-Pfade vorhanden.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($coreWebVitalsPages as $page): ?>
                                        <tr>
                                            <td><code class="small"><?= htmlspecialchars((string)($page['page_path'] ?? '/')) ?></code></td>
                                            <td class="text-end"><?= number_format((int)($page['samples'] ?? 0)) ?></td>
                                            <td class="text-end"><?= isset($page['avg_lcp_ms']) && $page['avg_lcp_ms'] !== null ? number_format((int)$page['avg_lcp_ms']) . ' ms' : '—' ?></td>
                                            <td class="text-end"><?= isset($page['avg_inp_ms']) && $page['avg_inp_ms'] !== null ? number_format((int)$page['avg_inp_ms']) . ' ms' : '—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Tracking & Suchmaschinen</h3></div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="save_analytics_settings">
                            <div class="col-12">
                                <div class="alert alert-info mb-0">
                                    Tracker werden erst nach Einwilligung geladen: GA4 und Matomo über die Cookie-Kategorie <strong>Analytics</strong>, GTM und Meta Pixel über <strong>Marketing</strong>. Die Content-Security-Policy wird nur für die Hosts der hier eingetragenen Anbieter geöffnet; Matomo muss per <code>https://</code> erreichbar sein. Eigener Tracking-Code (Inline-Skripte) wird aus Sicherheitsgründen nicht ausgeführt.
                                </div>
                            </div>
                            <div class="col-12"><label for="seoAnalyticsGscProperty" class="form-label">Google Search Console Property</label><input id="seoAnalyticsGscProperty" class="form-control" type="text" name="gsc_property" value="<?= htmlspecialchars((string)($trackingSettings['seo_analytics_gsc_property'] ?? '')) ?>" placeholder="sc-domain:example.de"></div>
                            <div class="col-md-6"><label for="seoAnalyticsGa4Id" class="form-label">GA4 ID</label><input id="seoAnalyticsGa4Id" class="form-control" type="text" name="ga4_id" value="<?= htmlspecialchars((string)($trackingSettings['seo_analytics_ga4_id'] ?? '')) ?>" placeholder="G-XXXXXXX"></div>
                            <div class="col-md-6"><label for="seoAnalyticsGtmId" class="form-label">GTM ID</label><input id="seoAnalyticsGtmId" class="form-control" type="text" name="gtm_id" value="<?= htmlspecialchars((string)($trackingSettings['seo_analytics_gtm_id'] ?? '')) ?>" placeholder="GTM-XXXX"></div>
                            <div class="col-md-8"><label for="seoAnalyticsMatomoUrl" class="form-label">Matomo URL</label><input id="seoAnalyticsMatomoUrl" class="form-control" type="text" name="matomo_url" value="<?= htmlspecialchars((string)($trackingSettings['seo_analytics_matomo_url'] ?? '')) ?>"></div>
                            <div class="col-md-4"><label for="seoAnalyticsMatomoSiteId" class="form-label">Matomo Site ID</label><input id="seoAnalyticsMatomoSiteId" class="form-control" type="text" name="matomo_site_id" value="<?= htmlspecialchars((string)($trackingSettings['seo_analytics_matomo_site_id'] ?? '1')) ?>"></div>
                            <div class="col-md-6"><label for="seoAnalyticsFbPixelId" class="form-label">Meta Pixel</label><input id="seoAnalyticsFbPixelId" class="form-control" type="text" name="fb_pixel_id" value="<?= htmlspecialchars((string)($trackingSettings['seo_analytics_fb_pixel_id'] ?? '')) ?>"></div>
                            <div class="col-md-6"><label for="seoAnalyticsWebVitalsSampleRate" class="form-label">CWV Sample-Rate (%)</label><input id="seoAnalyticsWebVitalsSampleRate" class="form-control" type="number" min="1" max="100" name="web_vitals_sample_rate" value="<?= htmlspecialchars((string)($trackingSettings['seo_analytics_web_vitals_sample_rate'] ?? '100')) ?>"></div>
                            <div class="col-md-6 d-flex flex-column gap-2 justify-content-end">
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="exclude_admins" value="1" <?= !empty($trackingSettings['seo_analytics_exclude_admins']) ? 'checked' : '' ?>><span class="form-check-label">Admins ausschließen</span></label>
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="respect_dnt" value="1" <?= !empty($trackingSettings['seo_analytics_respect_dnt']) ? 'checked' : '' ?>><span class="form-check-label">Do Not Track respektieren</span></label>
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="anonymize_ip" value="1" <?= !empty($trackingSettings['seo_analytics_anonymize_ip']) ? 'checked' : '' ?>><span class="form-check-label">IP anonymisieren</span></label>
                            </div>
                            <div class="col-md-6 d-flex flex-column gap-2 justify-content-end">
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="web_vitals_enabled" value="1" <?= !empty($trackingSettings['seo_analytics_web_vitals_enabled']) ? 'checked' : '' ?>><span class="form-check-label">Core-Web-Vitals-Felddaten erfassen</span></label>
                                <div class="form-hint">Optional ergänzend: externe Google-/CrUX-Daten separat anbinden. Das interne Beacon misst reale Frontend-Felddaten ohne Drittservice.</div>
                            </div>
                            <div class="col-12"><button class="btn btn-primary" type="submit">Analytics speichern</button></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-1">
            <div class="col-lg-12">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Internal-Linking-Ideen</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead><tr><th>Inhalt</th><th>Typ</th><th>Score</th></tr></thead>
                            <tbody>
                                <?php if (empty($internalLinkSuggestions)): ?>
                                    <tr><td colspan="3" class="text-center text-secondary py-4">Keine offensichtlichen Lücken gefunden.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($internalLinkSuggestions as $suggestion): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)$suggestion['title']) ?><div class="text-secondary small"><?= htmlspecialchars((string)$suggestion['slug']) ?></div></td>
                                            <td><?= htmlspecialchars((string)$suggestion['type']) ?></td>
                                            <td><span class="badge bg-warning-lt text-warning"><?= (int)$suggestion['score'] ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
