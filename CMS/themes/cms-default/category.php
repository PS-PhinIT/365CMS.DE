<?php
/**
 * Meridian CMS Default – Kategorie-Archiv Template
 *
 * Vom Router bereitgestellte Variablen:
 *   $posts         – array of stdObjects
 *   $total         – int, Gesamtanzahl (Übersicht: Anzahl der Kategorien)
 *   $currentPage   – int, aktuelle Seite
 *   $totalPages    – int
 *   $category      – stdObject|array|null  (id, name, slug, description)
 *   $query         – string, optionaler Filter (?q=)
 *   $isOverview    – bool, true für die Kategorie-Übersicht
 *   $overviewItems – array, Einträge mit title, slug, description, count, url
 *
 * @package CMSv2\Themes\CmsDefault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$posts         = $posts ?? [];
$total         = (int) ($total ?? 0);
$currentPage   = (int) ($currentPage ?? 1);
$totalPages    = (int) ($totalPages ?? 1);
$query         = is_string($query ?? null) ? trim($query) : '';
$isOverview    = !empty($isOverview);
$overviewItems = is_array($overviewItems ?? null) ? $overviewItems : [];

// Kategorie-Daten normalisieren
$cat         = is_array($category ?? null) || is_object($category ?? null) ? (array) $category : [];
$catLegacy   = is_string($_GET['category'] ?? null) ? trim($_GET['category']) : '';
$catName     = htmlspecialchars((string) ($cat['name'] ?? ($catLegacy !== '' ? $catLegacy : 'Kategorie')), ENT_QUOTES, 'UTF-8');
$catSlug     = (string) ($cat['slug'] ?? $catLegacy);
$catDesc     = htmlspecialchars((string) ($cat['description'] ?? ''), ENT_QUOTES, 'UTF-8');

// Paginierung: Übersicht bleibt auf der Kategorie-Basis, Archive nutzen den Slug
if ($isOverview) {
    $baseUrl = cms_get_archive_url('category') . ($query !== '' ? '?q=' . urlencode($query) : '');
} else {
    $baseUrl = meridian_archive_url('category', (string) ($catSlug));
}
$qSep = str_contains($baseUrl, '?') ? '&' : '?';

$showSidebar   = meridian_show_sidebar();
$recentSidebar = meridian_get_recent_posts(5);
$sidebarCats   = meridian_get_categories(8);
$tagCloud      = meridian_get_tags(20); // [name, slug, count] – Links über den Slug
?>

<!-- Kategorie-Header -->
<div class="archive-header" style="background:var(--surface-tint);border-bottom:1px solid var(--rule);padding:2.5rem 0 2rem;">
    <div class="container" style="max-width:var(--max);margin:0 auto;padding:0 1.5rem;">
        <nav class="breadcrumb" aria-label="Breadcrumb" style="font-size:.8rem;color:var(--ink-muted);margin-bottom:.75rem;">
            <a href="<?php echo SITE_URL; ?>/" style="color:var(--ink-muted);text-decoration:none;">Startseite</a>
            <span style="margin:0 .4rem;">›</span>
            <a href="<?php echo SITE_URL; ?>/blog" style="color:var(--ink-muted);text-decoration:none;">Blog</a>
            <span style="margin:0 .4rem;">›</span>
            <span style="color:var(--ink);"><?php echo $catName; ?></span>
        </nav>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;">
            <span style="font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--accent-text, var(--accent));"><?php echo $isOverview ? 'Übersicht' : 'Kategorie'; ?></span>
        </div>
        <h1 style="font-family:var(--font-serif);font-size:clamp(1.6rem,4vw,2.4rem);font-weight:700;margin:0 0 .5rem;color:var(--ink);"><?php echo $catName; ?></h1>
        <?php if ($catDesc): ?>
        <p style="font-size:.95rem;color:var(--ink-muted);margin:0 0 .75rem;max-width:580px;"><?php echo $catDesc; ?></p>
        <?php endif; ?>
        <p style="font-size:.82rem;color:var(--ink-ghost);">
            <?php if ($isOverview): ?>
            <?php echo number_format($total); ?> <?php echo $total === 1 ? 'Kategorie' : 'Kategorien'; ?> mit veröffentlichten Artikeln
            <?php else: ?>
            <?php echo number_format($total); ?> <?php echo $total === 1 ? 'Artikel' : 'Artikel'; ?> gefunden
            <?php endif; ?>
        </p>
    </div>
</div>

<!-- Inhalt -->
<div class="container" style="max-width:var(--max);margin:0 auto;padding:2rem 1.5rem;">
    <div class="page-wrap<?php echo $showSidebar ? ' page-wrap--sidebar' : ' page-wrap--no-sidebar'; ?>">

        <div class="content-main">
            <?php if ($isOverview || !empty($posts)): ?>

            <?php
            if ($isOverview) {
                $overviewType = 'category';
                require __DIR__ . '/partials/archive-overview.php';
            } else {
                $listPosts = array_slice($posts, 0, 4);
                $gridPosts = array_slice($posts, 4);
                require __DIR__ . '/partials/blog-list-cards.php';
                if (!empty($gridPosts)) {
                    require __DIR__ . '/partials/blog-grid-cards.php';
                }
            }
            ?>

            <!-- Paginierung -->
            <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Seitennavigation">
                <?php if ($currentPage > 1): ?>
                <a class="pagination-item pagination-item--prev"
                   href="<?php echo htmlspecialchars($baseUrl . $qSep . 'page=' . ($currentPage - 1), ENT_QUOTES, 'UTF-8'); ?>"
                   aria-label="Vorherige Seite">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                </a>
                <?php endif;
                $start = max(1, $currentPage - 2);
                $end   = min($totalPages, $currentPage + 2);
                if ($start > 1): ?>
                    <a class="pagination-item" href="<?php echo htmlspecialchars($baseUrl . $qSep . 'page=1', ENT_QUOTES, 'UTF-8'); ?>">1</a>
                    <?php if ($start > 2): ?><span class="pagination-gap">…</span><?php endif; ?>
                <?php endif;
                for ($p = $start; $p <= $end; $p++): ?>
                <a class="pagination-item<?php echo $p === $currentPage ? ' pagination-item--active' : ''; ?>"
                   href="<?php echo htmlspecialchars($baseUrl . $qSep . 'page=' . $p, ENT_QUOTES, 'UTF-8'); ?>"
                   <?php echo $p === $currentPage ? 'aria-current="page"' : ''; ?>><?php echo $p; ?></a>
                <?php endfor;
                if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span class="pagination-gap">…</span><?php endif; ?>
                    <a class="pagination-item" href="<?php echo htmlspecialchars($baseUrl . $qSep . 'page=' . $totalPages, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $totalPages; ?></a>
                <?php endif;
                if ($currentPage < $totalPages): ?>
                <a class="pagination-item pagination-item--next"
                   href="<?php echo htmlspecialchars($baseUrl . $qSep . 'page=' . ($currentPage + 1), ENT_QUOTES, 'UTF-8'); ?>"
                   aria-label="Nächste Seite">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

            <?php else: ?>
            <div class="empty-state" style="text-align:center;padding:4rem 2rem;">
                <p style="font-size:3rem;margin:0;">📭</p>
                <h2 style="margin:.75rem 0 .5rem;">Keine Artikel in dieser Kategorie</h2>
                <p style="color:var(--ink-muted);">In der Kategorie <strong><?php echo $catName; ?></strong> wurden noch keine Beiträge veröffentlicht.</p>
                <a href="<?php echo SITE_URL; ?>/blog" class="btn-solid" style="display:inline-block;margin-top:1rem;">Alle Artikel anzeigen</a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($showSidebar): ?>
        <?php require __DIR__ . '/partials/sidebar.php'; ?>
        <?php endif; ?>

    </div>
</div>
