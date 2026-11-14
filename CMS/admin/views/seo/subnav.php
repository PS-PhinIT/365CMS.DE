<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SEO_VIEW')) {
    exit;
}

// Sitemap/robots.txt laufen über /admin/seo-sitemap und brauchen das Token der Aktion admin_seo_suite
$seoActionToken = (string) ($seoSuiteCsrfToken ?? $csrfToken ?? '');
$seoActionEndpoint = '/admin/seo-sitemap';
$seoReturnTo = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/seo-sitemap');

$seoSubnavItems = [
    ['slug' => 'seo-dashboard', 'label' => 'Dashboard', 'url' => '/admin/seo-dashboard'],
    ['slug' => 'analytics', 'label' => 'Analytics', 'url' => '/admin/analytics'],
    ['slug' => 'seo-audit', 'label' => 'Audit', 'url' => '/admin/seo-audit'],
    ['slug' => 'seo-meta', 'label' => 'Meta-Daten', 'url' => '/admin/seo-meta'],
    ['slug' => 'seo-social', 'label' => 'Social', 'url' => '/admin/seo-social'],
    ['slug' => 'seo-schema', 'label' => 'Strukturierte Daten', 'url' => '/admin/seo-schema'],
    ['slug' => 'seo-sitemap', 'label' => 'Sitemap', 'url' => '/admin/seo-sitemap'],
    ['slug' => 'seo-search-engines', 'label' => 'Suchmaschinen', 'url' => '/admin/seo-search-engines'],
    ['slug' => 'seo-technical', 'label' => 'Technisches SEO', 'url' => '/admin/seo-technical'],
    ['slug' => 'redirect-manager', 'label' => 'Weiterleitungen', 'url' => '/admin/redirect-manager'],
    ['slug' => 'not-found-monitor', 'label' => '404-Monitor', 'url' => '/admin/not-found-monitor'],
];

$sectionNavGroups = [[
    'items' => $seoSubnavItems,
]];

$currentSectionPage = (string) ($activePage ?? 'seo-dashboard');
?>
<div class="admin-seo-subnav">
    <?php require dirname(__DIR__) . '/partials/section-subnav.php'; ?>
</div>

<div class="card mb-4 admin-redesign-toolbar-card">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
            <form method="post" action="<?= htmlspecialchars($seoActionEndpoint) ?>" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($seoActionToken) ?>">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($seoReturnTo) ?>">
                <input type="hidden" name="action" value="regenerate_sitemap_bundle">
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    Sitemaps generieren
                </button>
            </form>

            <form method="post" action="<?= htmlspecialchars($seoActionEndpoint) ?>" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($seoActionToken) ?>">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($seoReturnTo) ?>">
                <input type="hidden" name="action" value="save_robots">
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    robots.txt schreiben
                </button>
            </form>
        </div>
    </div>
</div>
