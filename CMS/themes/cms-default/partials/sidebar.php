<?php
/**
 * Meridian CMS Default – Wiederverwendbare Sidebar
 *
 * Erwartet folgende Variablen (optional, werden intern ermittelt falls nicht gesetzt):
 *   $recentSidebar – array, neueste Beiträge
 *   $sidebarCats   – array, Kategorien
 *   $tagCloud      – array, Tags als [name, slug] (Strings werden als Name behandelt)
 *
 * @package CMSv2\Themes\CmsDefault\Partials
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$sbRecent = $recentSidebar ?? meridian_get_recent_posts(5);
$sbCats   = $sidebarCats   ?? meridian_get_categories(8);
$sbTags   = $tagCloud      ?? [];

if (empty($sbTags)) {
    $sbTags = meridian_get_tags(20);
}
?>
<aside class="sidebar" aria-label="Seiten-Seitenleiste">

    <!-- Newsletter Widget -->
    <?php require __DIR__ . '/newsletter.php'; ?>

    <!-- Kategorien Widget -->
    <?php if (!empty($sbCats)): ?>
    <div class="sidebar-widget">
        <div class="widget-title">Kategorien</div>
        <ul class="sidebar-cat-list" style="list-style:none;margin:0;padding:0;">
            <?php foreach ($sbCats as $cat):
                $cat = (array) $cat;
                $catSlug  = $cat['slug'] ?? '';
                $catName  = $cat['name'] ?? '';
                $catCount = isset($cat['post_count']) ? (int)$cat['post_count'] : 0;
                $isActive = (isset($_GET['category']) && $_GET['category'] === $catSlug);
            ?>
            <li>
                <a href="<?php echo htmlspecialchars(meridian_archive_url('category', (string) ($catSlug)), ENT_QUOTES, 'UTF-8'); ?>"
                   class="cat-row<?php echo $isActive ? ' active' : ''; ?>"
                   style="display:flex;justify-content:space-between;align-items:center;padding:.35rem 0;text-decoration:none;color:var(--ink-soft);font-size:.9rem;border-bottom:1px solid var(--rule);">
                    <span><?php echo htmlspecialchars($catName); ?></span>
                    <?php if ($catCount > 0): ?>
                    <span class="cat-count" style="font-size:.75rem;color:var(--ink-ghost);background:var(--surface-tint);padding:.1rem .45rem;border-radius:20px;"><?php echo $catCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Zuletzt erschienen Widget -->
    <?php if (!empty($sbRecent)): ?>
    <div class="sidebar-widget">
        <div class="widget-title">Zuletzt erschienen</div>
        <?php foreach ($sbRecent as $i => $rp):
            $rp    = (array)$rp;
            $rLink = meridian_post_url($rp);
            $rCat  = htmlspecialchars($rp['category_name'] ?? '');
            $rDate = meridian_format_date($rp['published_at'] ?? $rp['created_at'] ?? '', true);
        ?>
        <div class="recent-item" style="display:flex;gap:.75rem;align-items:flex-start;padding:.6rem 0;border-bottom:1px solid var(--rule);">
            <div class="recent-num" style="font-size:1.2rem;font-weight:700;color:var(--ink-ghost);min-width:2rem;font-family:var(--font-serif);"><?php echo str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT); ?></div>
            <div class="recent-body" style="flex:1;min-width:0;">
                <?php if ($rCat): ?>
                <div class="rcat" style="font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;color:var(--accent-text, var(--accent));font-weight:600;margin-bottom:.2rem;"><?php echo $rCat; ?></div>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($rLink); ?>"
                   style="font-size:.88rem;font-weight:600;line-height:1.35;color:var(--ink-soft);text-decoration:none;display:block;"><?php echo htmlspecialchars($rp['title'] ?? ''); ?></a>
                <?php if ($rDate): ?>
                <time style="font-size:.73rem;color:var(--ink-ghost);"><?php echo $rDate; ?></time>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Tag-Cloud Widget -->
    <?php if (!empty($sbTags)): ?>
    <div class="sidebar-widget">
        <div class="widget-title">Tags</div>
        <div class="tag-cloud" style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.5rem;">
            <?php foreach ($sbTags as $tag): $tagLink = meridian_tag_link_data($tag); if ($tagLink['name'] === '') { continue; } ?>
            <a href="<?php echo htmlspecialchars(meridian_archive_url('tag', $tagLink['slug']), ENT_QUOTES, 'UTF-8'); ?>"
               class="<?php echo (isset($_GET['tag']) && $_GET['tag'] === $tagLink['slug']) ? 'tag-active' : ''; ?>"
               style="font-size:.78rem;padding:.25rem .65rem;border-radius:20px;background:var(--surface-tint);color:var(--ink-muted);text-decoration:none;transition:background .2s,color .2s;">
                <?php echo htmlspecialchars($tagLink['name'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</aside>
