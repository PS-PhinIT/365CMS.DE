<?php
/**
 * Meridian CMS Default – Autoren-Archiv Template
 *
 * Vom Router bereitgestellte Variablen:
 *   $posts        – array of stdObjects (Beiträge des Autors)
 *   $total        – int
 *   $currentPage  – int
 *   $totalPages   – int
 *   $author       – array|stdObject|null (id, username, display_name, bio, avatar_url)
 *
 * @package CMSv2\Themes\CmsDefault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$posts       = $posts       ?? [];
$total       = $total       ?? 0;
$currentPage = $currentPage ?? 1;
$totalPages  = $totalPages  ?? 1;

// Autor-Daten normalisieren
$a            = (array)($author ?? []);
$authorName   = htmlspecialchars($a['display_name'] ?? $a['username'] ?? 'Autor', ENT_QUOTES, 'UTF-8');
$authorBioRaw = (string)($a['bio'] ?? $a['description'] ?? '');
$authorBio    = $authorBioRaw !== ''
    ? \CMS\Services\EditorService::getInstance()->renderContent($authorBioRaw)
    : '';
$authorAvatar = $a['avatar_url'] ?? '';
$authorSlug   = $a['slug'] ?? $a['username'] ?? '';
$authorInitials = meridian_author_initials($authorName);

$showSidebar   = (bool) meridian_setting('layout', 'show_sidebar', true);
$recentSidebar = meridian_get_recent_posts(5);
$sidebarCats   = meridian_get_categories(8);
$tagCloud      = meridian_get_tags(20); // [name, slug, count] – Links über den Slug
?>

<!-- Autoren-Header -->
<div class="archive-header author-header" style="background:var(--surface-tint);border-bottom:1px solid var(--rule);padding:2.5rem 0 2rem;">
    <div class="container" style="max-width:var(--max);margin:0 auto;padding:0 1.5rem;">
        <nav class="breadcrumb" aria-label="Breadcrumb" style="font-size:.8rem;color:var(--ink-muted);margin-bottom:1.25rem;">
            <a href="<?php echo SITE_URL; ?>/" style="color:var(--ink-muted);text-decoration:none;">Startseite</a>
            <span style="margin:0 .4rem;">›</span>
            <a href="<?php echo SITE_URL; ?>/blog" style="color:var(--ink-muted);text-decoration:none;">Blog</a>
            <span style="margin:0 .4rem;">›</span>
            <span style="color:var(--ink);">Autor</span>
        </nav>
        <div style="display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;">
            <!-- Avatar -->
            <?php if ($authorAvatar): ?>
            <img src="<?php echo htmlspecialchars($authorAvatar, ENT_QUOTES, 'UTF-8'); ?>"
                 alt="<?php echo $authorName; ?>"
                 style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--rule);">
            <?php else: ?>
            <div style="width:64px;height:64px;border-radius:50%;background:var(--accent-dark, #a06b18);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:700;flex-shrink:0;">
                <?php echo $authorInitials; ?>
            </div>
            <?php endif; ?>
            <!-- Info -->
            <div>
                <p style="font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--accent-text, var(--accent));margin:0 0 .25rem;">Autor</p>
                <h1 style="font-family:var(--font-serif);font-size:clamp(1.4rem,3.5vw,2rem);font-weight:700;margin:0 0 .3rem;color:var(--ink);"><?php echo $authorName; ?></h1>
                <?php if ($authorBio !== ''): ?>
                <div class="author-bio editorjs-content" style="font-size:.9rem;color:var(--ink-muted);margin:.25rem 0 0;">
                    <?php echo $authorBio; ?>
                </div>
                <?php endif; ?>
                <p style="font-size:.8rem;color:var(--ink-ghost);margin:.35rem 0 0;">
                    <?php echo number_format($total); ?> veröffentlichte Artikel
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Beiträge des Autors -->
<div class="container" style="max-width:var(--max);margin:0 auto;padding:2rem 1.5rem;">
    <div class="page-wrap<?php echo $showSidebar ? ' page-wrap--sidebar' : ''; ?>">

        <div class="content-main">
            <?php if (!empty($posts)): ?>

            <?php
            $listPosts = array_slice($posts, 0, 4);
            $gridPosts = array_slice($posts, 4);
            require __DIR__ . '/partials/blog-list-cards.php';
            if (!empty($gridPosts)) {
                require __DIR__ . '/partials/blog-grid-cards.php';
            }
            ?>

            <!-- Paginierung -->
            <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Seitennavigation">
                <?php
                $baseUrl = SITE_URL . '/author/' . urlencode($authorSlug);
                if ($currentPage > 1): ?>
                <a class="pagination-item pagination-item--prev"
                   href="<?php echo $baseUrl . '?page=' . ($currentPage - 1); ?>"
                   aria-label="Vorherige Seite">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                </a>
                <?php endif;
                for ($p = max(1, $currentPage - 2); $p <= min($totalPages, $currentPage + 2); $p++): ?>
                <a class="pagination-item<?php echo $p === $currentPage ? ' pagination-item--active' : ''; ?>"
                   href="<?php echo $baseUrl . '?page=' . $p; ?>"
                   <?php echo $p === $currentPage ? 'aria-current="page"' : ''; ?>><?php echo $p; ?></a>
                <?php endfor;
                if ($currentPage < $totalPages): ?>
                <a class="pagination-item pagination-item--next"
                   href="<?php echo $baseUrl . '?page=' . ($currentPage + 1); ?>"
                   aria-label="Nächste Seite">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

            <?php else: ?>
            <div class="empty-state" style="text-align:center;padding:4rem 2rem;">
                <p style="font-size:3rem;margin:0;">✍️</p>
                <h2 style="margin:.75rem 0 .5rem;">Noch keine Artikel</h2>
                <p style="color:var(--ink-muted);"><strong><?php echo $authorName; ?></strong> hat noch keine Beiträge veröffentlicht.</p>
                <a href="<?php echo SITE_URL; ?>/blog" class="btn-solid" style="display:inline-block;margin-top:1rem;">Alle Artikel anzeigen</a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($showSidebar): ?>
        <?php require __DIR__ . '/partials/sidebar.php'; ?>
        <?php endif; ?>

    </div>
</div>
