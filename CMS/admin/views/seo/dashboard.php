<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SEO_VIEW')) {
    exit;
}

$dashboard = $data['dashboard'] ?? [];
$overview = $data['overview'] ?? [];
$content = $overview['content'] ?? [];
$scores = $overview['scores'] ?? [];
$total = $overview['total'] ?? 0;
$status = $dashboard['status'] ?? [];
$topIssues = $dashboard['top_issues'] ?? [];
$contentBuckets = $dashboard['content_buckets'] ?? [];
$recentCritical = $dashboard['recent_critical'] ?? [];
$trendSummary = $dashboard['trends'] ?? [];
$trendMetrics = $trendSummary['metrics'] ?? [];
$trendNote = (string)($trendSummary['note'] ?? '');
$trendLastCapturedAt = (string)($trendSummary['last_captured_at'] ?? '');
$trendDays = (int)($trendSummary['history_days'] ?? 14);

$deltaToneClasses = [
    'success' => 'bg-green-lt text-green',
    'danger' => 'bg-red-lt text-red',
    'warning' => 'bg-yellow-lt text-yellow',
    'info' => 'bg-blue-lt text-blue',
    'neutral' => 'bg-secondary-lt text-secondary',
];

$renderSparkline = static function (array $points, string $strokeColor, string $label): string {
    $values = array_map(static fn(array $point): int => (int)($point['value'] ?? 0), $points);
    if ($values === []) {
        return '<div class="text-secondary small">Keine Daten</div>';
    }

    $width = 100.0;
    $height = 32.0;
    $padding = 3.0;
    $min = min($values);
    $max = max($values);
    $range = max(1.0, (float)($max - $min));
    $count = max(1, count($values) - 1);
    $path = [];

    foreach ($values as $index => $value) {
        $x = $padding + (($width - (2 * $padding)) * ($index / $count));
        $normalized = ($value - $min) / $range;
        $y = $height - $padding - (($height - (2 * $padding)) * $normalized);
        $path[] = sprintf('%s%.2F %.2F', $index === 0 ? 'M ' : 'L ', $x, $y);
    }

    $lastX = $padding + (($width - (2 * $padding)) * ((count($values) - 1) / $count));
    $lastValue = (float)$values[array_key_last($values)];
    $lastY = $height - $padding - (($height - (2 * $padding)) * (($lastValue - $min) / $range));

    return sprintf(
        '<svg viewBox="0 0 100 32" role="img" aria-label="%s"><path d="%s" fill="none" stroke="%s" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path><circle cx="%.2F" cy="%.2F" r="2.5" fill="%s"></circle></svg>',
        htmlspecialchars($label, ENT_QUOTES),
        htmlspecialchars(implode(' ', $path), ENT_QUOTES),
        htmlspecialchars($strokeColor, ENT_QUOTES),
        $lastX,
        $lastY,
        htmlspecialchars($strokeColor, ENT_QUOTES)
    );
};

$formatDelta = static function (int $delta): string {
    if ($delta > 0) {
        return '+' . number_format($delta);
    }

    if ($delta < 0) {
        return number_format($delta);
    }

    return '±0';
};

$normalizeIssueFilter = static function (string $issueLabel): string {
    $label = strtolower(trim($issueLabel));
    $map = [
        'meta title fehlt' => 'meta-title',
        'meta-title fehlt' => 'meta-title',
        'meta beschreibung fehlt' => 'meta-description',
        'meta-beschreibung fehlt' => 'meta-description',
        'h1 fehlt' => 'h1',
        'zu wenig text' => 'content-length',
        'zu kurze inhalte' => 'content-length',
        'interne links fehlen' => 'internal-links',
        'alt-text fehlt' => 'image-alt',
        'alt text fehlt' => 'image-alt',
    ];
    if (isset($map[$label])) {
        return $map[$label];
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', $label);
    $slug = trim((string)$slug, '-');
    return $slug !== '' ? $slug : 'issue';
};
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">SEO</div>
                <h2 class="page-title mb-1">SEO Dashboard</h2>
                <div class="content-listing-header__meta">
                    <span>Inhalte geprüft: <?= (int)$total ?></span>
                    <span>Kritisch: <?= (int)($scores['bad'] ?? 0) ?></span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <a class="btn btn-outline-secondary btn-sm" href="/admin/seo-audit">Audit</a>
                <a class="btn btn-outline-secondary btn-sm" href="/admin/seo-search-engines">Suchmaschinen</a>
                <a class="btn btn-outline-primary btn-sm" href="/admin/seo-technical">Technik</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-seo-dashboard-page admin-redesign-page">
    <div class="container-xl admin-redesign-shell">

        <?php if (!empty($alert)): ?>
            <?php
            $alertData = is_array($alert ?? null) ? $alert : [];
            require dirname(__DIR__) . '/partials/flash-alert.php';
            ?>
        <?php endif; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <div class="row row-deck row-cards mb-4 admin-metric-grid">
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Inhalte geprüft</div>
                        <div class="h1 mb-0"><?= (int)$total ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">SEO Gut</div>
                        <div class="h1 mb-0 text-success"><?= (int)($scores['good'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Warnungen</div>
                        <div class="h1 mb-0 text-warning"><?= (int)($scores['warning'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Kritisch</div>
                        <div class="h1 mb-0 text-danger"><?= (int)($scores['bad'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($trendMetrics)): ?>
            <div class="row row-deck row-cards mb-4">
                <?php foreach ($trendMetrics as $metric): ?>
                    <?php
                    $metricLabel = (string)($metric['label'] ?? 'Trend');
                    $metricValue = (int)($metric['value'] ?? 0);
                    $metricSuffix = (string)($metric['suffix'] ?? '');
                    $metricSecondary = (string)($metric['secondary'] ?? '');
                    $metricDelta = (int)($metric['delta'] ?? 0);
                    $metricDeltaTone = (string)($metric['delta_tone'] ?? 'neutral');
                    $metricStroke = (string)($metric['sparkline_color'] ?? '#206bc4');
                    $metricPoints = is_array($metric['points'] ?? null) ? $metric['points'] : [];
                    $deltaClass = $deltaToneClasses[$metricDeltaTone] ?? $deltaToneClasses['neutral'];
                    ?>
                    <div class="col-sm-6 col-lg-4">
                        <div class="card h-100">
                            <div class="card-body d-flex flex-column gap-3">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="subheader"><?= htmlspecialchars($metricLabel) ?></div>
                                        <div class="h1 mb-1"><?= number_format($metricValue) ?><?= htmlspecialchars($metricSuffix) ?></div>
                                        <div class="text-secondary small"><?= htmlspecialchars($metricSecondary) ?></div>
                                    </div>
                                    <div class="chart-sparkline chart-sparkline-wide" aria-hidden="true">
                                        <?= $renderSparkline($metricPoints, $metricStroke, $metricLabel . ' Verlauf der letzten ' . $trendDays . ' Tage') ?>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="badge <?= htmlspecialchars($deltaClass) ?>"><?= htmlspecialchars($formatDelta($metricDelta)) ?> vs. Verlaufspunkt</span>
                                    <span class="text-secondary small"><?= (int)$trendDays ?> Tage</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="text-secondary small mb-4">
                <?= htmlspecialchars($trendNote) ?>
                <?php if ($trendLastCapturedAt !== ''): ?>
                    · Letzter Snapshot: <?= htmlspecialchars($trendLastCapturedAt) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4 admin-seo-status-grid">
            <div class="col-md-6">
                <div class="card admin-content-card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="badge <?= !empty($status['sitemap_exists']) ? 'bg-success' : 'bg-info' ?> badge-pill p-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 18.5l-3 -1.5l-6 3v-13l6 -3l6 3l6 -3v7.5"/><path d="M9 4v13"/><path d="M15 7v5.5"/><path d="M21.121 20.121a3 3 0 1 0 -4.242 0c.418 .419 1.125 1.045 2.121 1.879c1.001 -.836 1.709 -1.462 2.121 -1.879z"/><path d="M19 18v.01"/></svg>
                        </span>
                        <div>
                            <div class="fw-bold">Sitemap</div>
                            <div class="text-secondary small">
                                <?= !empty($status['sitemap_exists']) ? 'Vorhanden · ' . htmlspecialchars((string)($status['sitemap_date'] ?? '')) : 'Wird beim ersten Abruf von /sitemap.xml erzeugt' ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card admin-content-card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="badge bg-success badge-pill p-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 4h11a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-11a1 1 0 0 1 -1 -1v-14a1 1 0 0 1 1 -1m3 0v18"/><path d="M13 8l2 0"/><path d="M13 12l2 0"/></svg>
                        </span>
                        <div>
                            <div class="fw-bold">robots.txt</div>
                            <div class="text-secondary small"><?= !empty($status['robots_exists']) ? 'Statische Datei · ' . htmlspecialchars((string)($status['robots_date'] ?? '')) : 'Dynamisch vom CMS ausgeliefert' ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-cards mb-4">
            <div class="col-lg-6">
                <div class="card h-100 admin-content-card">
                    <div class="card-header"><h3 class="card-title">Content-Status</h3></div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between"><span>Veröffentlichbar</span><strong class="text-success"><?= (int)($contentBuckets['publish_ready'] ?? 0) ?></strong></div>
                            <div class="list-group-item d-flex justify-content-between"><span>Meta-Daten fehlen</span><strong class="text-warning"><?= (int)($contentBuckets['needs_meta'] ?? 0) ?></strong></div>
                            <div class="list-group-item d-flex justify-content-between"><span>Interne Links fehlen</span><strong><?= (int)($contentBuckets['needs_links'] ?? 0) ?></strong></div>
                            <div class="list-group-item d-flex justify-content-between"><span>Lesbarkeit optimieren</span><strong class="text-danger"><?= (int)($contentBuckets['needs_readability'] ?? 0) ?></strong></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100 admin-content-card">
                    <div class="card-header"><h3 class="card-title">Häufigste SEO-Probleme</h3></div>
                    <div class="card-body">
                        <?php if (empty($topIssues)): ?>
                            <div class="text-success">✓ Keine häufigen Probleme erkannt.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($topIssues as $issue => $count): ?>
                                    <?php $filter = $normalizeIssueFilter((string)$issue); ?>
                                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?= htmlspecialchars('/admin/seo-audit?filter=' . rawurlencode($filter)) ?>">
                                        <span><?= htmlspecialchars((string)$issue) ?></span>
                                        <span class="badge bg-warning-lt text-warning"><?= (int)$count ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-2 text-end">
                                <a class="admin-seo-audit-link-all" href="<?= htmlspecialchars('/admin/seo-audit') ?>">Alle im Audit ansehen →</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xxl-8">
                <div class="card h-100 admin-content-card">
                    <div class="card-header"><h3 class="card-title">Kritische Inhalte</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead><tr><th>Inhalt</th><th>Score</th><th>Offene Punkte</th></tr></thead>
                            <tbody>
                                <?php if (empty($recentCritical)): ?>
                                    <tr><td colspan="3" class="text-center text-secondary py-4">Keine kritischen Inhalte – schöne Seltenheit.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentCritical as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($item['title'] ?? '')) ?><div class="text-secondary small"><?= htmlspecialchars((string)($item['type'] ?? '')) ?> · <?= htmlspecialchars((string)($item['slug'] ?? '')) ?></div></td>
                                            <td>
                                                <?php $itemScore = max(0, min(100, (int)($item['seo_score_value'] ?? 0))); ?>
                                                <?php
                                                $itemScoreToneClass = $itemScore < 40
                                                    ? 'is-danger'
                                                    : ($itemScore <= 70 ? 'is-warning' : 'is-success');
                                                ?>
                                                <div class="admin-seo-score-cell">
                                                    <div class="admin-seo-score-cell__value"><?= $itemScore ?>/100</div>
                                                    <div class="admin-seo-score-progress <?= $itemScoreToneClass ?>" style="--seo-score: <?= $itemScore ?>;">
                                                        <span class="admin-seo-score-progress__fill" aria-hidden="true"></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php foreach (array_slice((array)($item['seo_issues'] ?? []), 0, 3) as $issue): ?>
                                                    <div class="small text-warning"><?= htmlspecialchars((string)($issue['msg'] ?? '')) ?></div>
                                                <?php endforeach; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xxl-4">
                <div class="card h-100 admin-content-card">
                    <div class="card-header"><h3 class="card-title">Schnellzugriffe</h3></div>
                    <div class="list-group list-group-flush">
                        <a class="list-group-item list-group-item-action" href="<?= htmlspecialchars('/admin/seo-audit') ?>">SEO Audit & Bulk-Editor</a>
                        <a class="list-group-item list-group-item-action" href="<?= htmlspecialchars('/admin/seo-meta') ?>">Meta-Templates & Variablen</a>
                        <a class="list-group-item list-group-item-action" href="<?= htmlspecialchars('/admin/seo-social') ?>">OG, X/Twitter & Pinterest</a>
                        <a class="list-group-item list-group-item-action" href="<?= htmlspecialchars('/admin/seo-schema') ?>">Schema.org & Typ-Verteilung</a>
                        <a class="list-group-item list-group-item-action" href="<?= htmlspecialchars('/admin/seo-sitemap') ?>">Sitemaps & robots.txt</a>
                        <a class="list-group-item list-group-item-action" href="<?= htmlspecialchars('/admin/seo-search-engines') ?>">Suchmaschinen: IndexNow, Google & Verifizierung</a>
                        <a class="list-group-item list-group-item-action" href="<?= htmlspecialchars('/admin/seo-technical') ?>">Broken Links, hreflang & Bild-SEO</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
