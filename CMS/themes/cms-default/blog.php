<?php
/**
 * Meridian CMS Default – Blog-Liste Template
 *
 * Vom Router bereitgestellte Variablen:
 *   $posts        – array of stdObjects (Beiträge der aktuellen Seite)
 *   $total        – int, Gesamtanzahl
 *   $currentPage  – int, aktuelle Seite
 *   $totalPages   – int, Gesamtzahl Seiten
 *   $perPage      – int, Beiträge pro Seite
 *
 * @package CMSv2\Themes\CmsDefault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Standardwerte für den Fall, dass Router Variablen nicht liefert
$posts       = $posts       ?? [];
$total       = $total       ?? 0;
$currentPage = $currentPage ?? 1;
$totalPages  = $totalPages  ?? 1;

$showSidebar = meridian_show_sidebar();
$activeCategory = htmlspecialchars($_GET['category'] ?? '');
$activeTag      = htmlspecialchars($_GET['tag'] ?? '');
$pageTitle      = 'Blog';
if ($activeCategory) {
    $pageTitle = 'Kategorie: ' . $activeCategory;
} elseif ($activeTag) {
    $pageTitle = 'Tag: ' . $activeTag;
}

// Sidebar-Daten
$recentSidebar = meridian_get_recent_posts(5);
$sidebarCats   = meridian_get_categories(8);
$tagCloud = meridian_get_tags(20); // [name, slug, count] – Links über den Slug
?>

<div class="page-wrap<?php echo $showSidebar ? '' : ' page-wrap--no-sidebar'; ?>">

    <!-- Content Area -->
    <div class="content-main">
        <h1 class="sr-only">Blog<?php echo (int) ($currentPage ?? 1) > 1 ? ' – Seite ' . (int) $currentPage : ''; ?></h1>

            <?php if (!empty($posts)): ?>
            <?php
                // Erste 4 → Listen-Cards, nächste 6 → Grid-Cards
                $listPosts = array_slice($posts, 0, 4);
                $gridPosts = array_slice($posts, 4, 6);
            ?>

            <?php require __DIR__ . '/partials/blog-list-cards.php'; ?>

            <?php if (!empty($gridPosts)): ?>
            <?php require __DIR__ . '/partials/blog-grid-cards.php'; ?>
            <?php endif; ?>

            <!-- Paginierung -->
            <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Seitennavigation">
                <?php
                $baseUrl = SITE_URL . '/blog';
                $qParts  = [];
                if ($activeCategory) {
                    $qParts[] = 'category=' . urlencode($activeCategory);
                }
                if ($activeTag) {
                    $qParts[] = 'tag=' . urlencode($activeTag);
                }
                $qBase = $qParts ? '?' . implode('&', $qParts) . '&' : '?';

                // Prev
                if ($currentPage > 1): ?>
                <a class="pagination-item pagination-item--prev"
                   href="<?php echo $baseUrl . $qBase . 'page=' . ($currentPage - 1); ?>"
                   aria-label="Vorherige Seite">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                </a>
                <?php endif;

                // Page numbers
                $start = max(1, $currentPage - 2);
                $end   = min($totalPages, $currentPage + 2);
                if ($start > 1): ?>
                    <a class="pagination-item" href="<?php echo $baseUrl . $qBase . 'page=1'; ?>">1</a>
                    <?php if ($start > 2): ?>
                        <span class="pagination-gap">…</span>
                    <?php endif; ?>
                <?php endif;

                for ($p = $start; $p <= $end; $p++): ?>
                <a class="pagination-item<?php echo $p === $currentPage ? ' pagination-item--active' : ''; ?>"
                   href="<?php echo $baseUrl . $qBase . 'page=' . $p; ?>"
                   <?php echo $p === $currentPage ? 'aria-current="page"' : ''; ?>>
                    <?php echo $p; ?>
                </a>
                <?php endfor;

                if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?>
                        <span class="pagination-gap">…</span>
                    <?php endif; ?>
                    <a class="pagination-item" href="<?php echo $baseUrl . $qBase . 'page=' . $totalPages; ?>"><?php echo $totalPages; ?></a>
                <?php endif;

                // Next
                if ($currentPage < $totalPages): ?>
                <a class="pagination-item pagination-item--next"
                   href="<?php echo $baseUrl . $qBase . 'page=' . ($currentPage + 1); ?>"
                   aria-label="Nächste Seite">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

            <?php else: ?>
            <!-- Empty State -->
            <div class="empty-state" style="text-align:center;padding:4rem 2rem;">
                <p style="font-size:3rem;margin:0">📭</p>
                <h2 style="margin:.75rem 0 .5rem;">Keine Artikel gefunden</h2>
                <?php if ($activeCategory || $activeTag): ?>
                <p style="color:var(--ink-60)">Für diese Auswahl wurden keine Beiträge gefunden.</p>
                <a href="<?php echo SITE_URL; ?>/blog" class="btn-solid" style="display:inline-block;margin-top:1rem;">Alle Artikel anzeigen</a>
                <?php else: ?>
                <p style="color:var(--ink-60)">Die ersten Artikel erscheinen hier, sobald sie veröffentlicht werden.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

    </div>

    <!-- Sidebar -->
    <?php if ($showSidebar): ?>
    <aside class="sidebar" aria-label="Sidebar">

        <!-- Suche in Sidebar -->
        <div>
            <div class="widget-title">Suche</div>
                <form action="<?php echo SITE_URL; ?>/search" method="GET" role="search">
                    <div style="display:flex;gap:.5rem;">
                        <input type="search" name="q" class="form-control form-control--sm"
                               placeholder="Artikel suchen …" autocomplete="off"
                               value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
                        <button type="submit" class="btn-solid" aria-label="Suchen">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            </svg>
                        </button>
                    </div>
                </form>
        </div>

        <!-- Aktuelle Beiträge -->
        <?php if (!empty($recentSidebar)): ?>
        <div>
            <div class="widget-title">Aktuelle Beiträge</div>
            <?php $rNum = 1; foreach ($recentSidebar as $recent): $rArr = (array)$recent; ?>
            <div class="recent-item">
                <div class="recent-num"><?php echo str_pad((string)$rNum++, 2, '0', STR_PAD_LEFT); ?></div>
                <div class="recent-body">
                    <?php if (!empty($rArr['category_name'])): ?>
                    <div class="rcat"><?php echo htmlspecialchars($rArr['category_name']); ?></div>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars(meridian_post_url($rArr), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($rArr['title']); ?></a>
                    <time><?php echo time_ago($rArr['published_at'] ?? $rArr['created_at'] ?? ''); ?></time>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Kategorien -->
        <?php if (!empty($sidebarCats)): ?>
        <div>
            <div class="widget-title">Kategorien</div>
            <?php foreach ($sidebarCats as $cat): ?>
            <div class="cat-row<?php echo ($activeCategory === ($cat['slug'] ?? '')) ? ' cat-row--active' : ''; ?>">
                <a href="<?php echo htmlspecialchars(meridian_archive_url('category', (string) ($cat['slug'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                </a>
                <?php if (!empty($cat['post_count'])): ?>
                <span class="cat-count"><?php echo (int)$cat['post_count']; ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Tag Cloud -->
        <?php if (!empty($tagCloud)): ?>
        <div>
            <div class="widget-title">Tags</div>
            <div class="tag-cloud">
                <?php foreach ($tagCloud as $tag): $tagLink = meridian_tag_link_data($tag); if ($tagLink['name'] === '') { continue; } ?>
                <a href="<?php echo htmlspecialchars(meridian_archive_url('tag', $tagLink['slug']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($tagLink['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </aside>
    <?php endif; ?>

</div><!-- /.page-wrap -->
