<?php
/**
 * Meridian CMS Default – Tag-Archiv Template
 *
 * Vom Router bereitgestellte Variablen:
 *   $posts         – array of stdObjects
 *   $total         – int (Übersicht: Anzahl der Tags)
 *   $currentPage   – int
 *   $totalPages    – int
 *   $tag           – array (name, slug, description) seit 365CMS 3.4,
 *                    bei älteren Aufrufern ein String mit dem Tag-Namen
 *   $query         – string, optionaler Filter (?q=)
 *   $isOverview    – bool, true für die Tag-Übersicht (/tag)
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

// Tag-Daten normalisieren: Array/Objekt vom Router, String oder ?tag= bei älteren Aufrufern
$tagData   = is_array($tag ?? null) || is_object($tag ?? null) ? (array) $tag : [];
$tagLegacy = is_string($tag ?? null) && trim($tag) !== '' ? $tag : ($_GET['tag'] ?? '');
$tagLegacy = is_string($tagLegacy) ? trim($tagLegacy) : '';
$tagLabel  = trim((string) ($tagData['name'] ?? ''));
$tagSlug   = trim((string) ($tagData['slug'] ?? ''));
if (!$isOverview && $tagLabel === '' && $tagSlug === '') {
    $tagLabel = $tagSlug = $tagLegacy;
}
$tagName = htmlspecialchars($tagLabel !== '' ? $tagLabel : $tagSlug, ENT_QUOTES, 'UTF-8');
$tagRaw  = $tagSlug !== '' ? $tagSlug : $tagLabel;
$tagDesc = htmlspecialchars(trim((string) ($tagData['description'] ?? '')), ENT_QUOTES, 'UTF-8');

// Paginierung: Übersicht bleibt auf /tag, Archive nutzen den Slug
if ($isOverview) {
    $baseUrl = cms_get_archive_url('tag') . ($query !== '' ? '?q=' . urlencode($query) : '');
} else {
    $baseUrl = meridian_archive_url('tag', (string) ($tagRaw));
}
$qSep = str_contains($baseUrl, '?') ? '&' : '?';

$showSidebar   = (bool) meridian_setting('layout', 'show_sidebar', true);
$recentSidebar = meridian_get_recent_posts(5);
$sidebarCats   = meridian_get_categories(8);
$tagCloud      = meridian_get_tags(20); // [name, slug, count] – Links über den Slug
?>

<!-- Tag-Archiv Header -->
<div class="archive-header" style="background:var(--surface-tint);border-bottom:1px solid var(--rule);padding:2.5rem 0 2rem;">
    <div class="container" style="max-width:var(--max);margin:0 auto;padding:0 1.5rem;">
        <nav class="breadcrumb" aria-label="Breadcrumb" style="font-size:.8rem;color:var(--ink-muted);margin-bottom:.75rem;">
            <a href="<?php echo SITE_URL; ?>/" style="color:var(--ink-muted);text-decoration:none;">Startseite</a>
            <span style="margin:0 .4rem;">›</span>
            <a href="<?php echo SITE_URL; ?>/blog" style="color:var(--ink-muted);text-decoration:none;">Blog</a>
            <span style="margin:0 .4rem;">›</span>
            <span style="color:var(--ink);"><?php echo $isOverview ? $tagName : 'Tag: ' . $tagName; ?></span>
        </nav>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;">
            <span style="font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--accent-text, var(--accent));"><?php echo $isOverview ? 'Übersicht' : 'Tag-Archiv'; ?></span>
        </div>
        <h1 style="font-family:var(--font-serif);font-size:clamp(1.6rem,4vw,2.4rem);font-weight:700;margin:0 0 .75rem;color:var(--ink);"><?php echo $tagName; ?></h1>
        <?php if ($tagDesc): ?>
        <p style="font-size:.95rem;color:var(--ink-muted);margin:0 0 .75rem;max-width:580px;"><?php echo $tagDesc; ?></p>
        <?php endif; ?>
        <p style="font-size:.82rem;color:var(--ink-ghost);">
            <?php if ($isOverview): ?>
            <?php echo number_format($total); ?> <?php echo $total === 1 ? 'Tag' : 'Tags'; ?> mit veröffentlichten Artikeln
            <?php else: ?>
            <?php echo number_format($total); ?> <?php echo $total === 1 ? 'Artikel' : 'Artikel'; ?> mit diesem Tag
            <?php endif; ?>
        </p>
    </div>
</div>

<!-- Inhalt -->
<div class="container" style="max-width:var(--max);margin:0 auto;padding:2rem 1.5rem;">
    <div class="page-wrap<?php echo $showSidebar ? ' page-wrap--sidebar' : ''; ?>">

        <div class="content-main">
            <?php if ($isOverview || !empty($posts)): ?>

            <?php
            if ($isOverview) {
                $overviewType = 'tag';
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
                for ($p = max(1, $currentPage - 2); $p <= min($totalPages, $currentPage + 2); $p++): ?>
                <a class="pagination-item<?php echo $p === $currentPage ? ' pagination-item--active' : ''; ?>"
                   href="<?php echo htmlspecialchars($baseUrl . $qSep . 'page=' . $p, ENT_QUOTES, 'UTF-8'); ?>"
                   <?php echo $p === $currentPage ? 'aria-current="page"' : ''; ?>><?php echo $p; ?></a>
                <?php endfor;
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
                <p style="font-size:3rem;margin:0;">🏷️</p>
                <h2 style="margin:.75rem 0 .5rem;">Keine Artikel mit diesem Tag</h2>
                <p style="color:var(--ink-muted);">Zum Tag <strong><?php echo $tagName; ?></strong> wurden noch keine Artikel veröffentlicht.</p>
                <a href="<?php echo SITE_URL; ?>/blog" class="btn-solid" style="display:inline-block;margin-top:1rem;">Alle Artikel anzeigen</a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($showSidebar): ?>
        <?php require __DIR__ . '/partials/sidebar.php'; ?>
        <?php endif; ?>

    </div>
</div>
