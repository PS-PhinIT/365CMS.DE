<?php
/**
 * Blog – Grid-Cards (6 Beiträge, 3-spaltig, 2 Zeilen)
 *
 * Erwartete Variable:
 *   $gridPosts – array of stdObjects, max. 6 Einträge
 *
 * @package CMSv2\Themes\CmsDefault\Partials
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (empty($gridPosts)) {
    return;
}

// Tonale Thumbnail-Gradienten, pro Karte rotiert
$gradients = [
    'linear-gradient(135deg,#1a2a3a,#1a1a18)',
    'linear-gradient(135deg,#1a2232,#2a1a18)',
    'linear-gradient(135deg,#1a2a1a,#1a1a18)',
    'linear-gradient(135deg,#2a1a2a,#1a1a20)',
    'linear-gradient(135deg,#1e2a1a,#1a1828)',
    'linear-gradient(135deg,#241a1a,#1a2020)',
];

$svgs = [
    '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
    '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
    '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
];
?>
<div class="section-label"><h2>Weitere Artikel</h2></div>

<div class="card-grid">
    <?php foreach ($gridPosts as $i => $item): ?>
    <?php
        $gTitle   = htmlspecialchars($item->title ?? '');
        $gSlug    = htmlspecialchars($item->slug ?? '');
        $gCat     = htmlspecialchars($item->category_name ?? '');
        $gCatSlug = htmlspecialchars($item->category_slug ?? '');
        $gExcerpt = htmlspecialchars(
            !empty($item->excerpt) ? $item->excerpt : meridian_excerpt($item->content ?? '', 160)
        );
        $gDate    = !empty($item->published_at ?? $item->created_at ?? '')
                    ? time_ago($item->published_at ?? $item->created_at)
                    : '';
        $gImageReference = (string) ($item->featured_image ?? '');
        $gImage = function_exists('meridian_get_picture_sources')
            ? meridian_get_picture_sources($gImageReference, null, 960, 560)
            : ['url' => '', 'avif_url' => '', 'webp_url' => '', 'width' => 960, 'height' => 560];
        $gImageDimensions = function_exists('meridian_image_dimension_attributes')
            ? meridian_image_dimension_attributes($gImageReference, 960, 560)
            : 'width="960" height="560"';
        $grad     = $gradients[$i % count($gradients)];
        $svg      = $svgs[$i % count($svgs)];
    ?>
    <div class="card">
        <?php if ($gImage['url'] !== ''): ?>
        <a href="<?php echo htmlspecialchars(meridian_post_url($item), ENT_QUOTES, 'UTF-8'); ?>" class="card-thumb" style="background:<?php echo $grad; ?>;">
            <picture>
                <?php if (($gImage['avif_url'] ?? '') !== ''): ?>
                <source srcset="<?php echo htmlspecialchars($gImage['avif_url'], ENT_QUOTES, 'UTF-8'); ?>" type="image/avif">
                <?php endif; ?>
                <?php if ($gImage['webp_url'] !== ''): ?>
                <source srcset="<?php echo htmlspecialchars($gImage['webp_url'], ENT_QUOTES, 'UTF-8'); ?>" type="image/webp">
                <?php endif; ?>
                <img src="<?php echo htmlspecialchars($gImage['url'], ENT_QUOTES, 'UTF-8'); ?>"
                     alt="<?php echo $gTitle; ?>"
                     <?php echo function_exists('meridian_image_loading_attributes') ? meridian_image_loading_attributes(false) : 'loading="lazy" decoding="async"'; ?>
                     <?php echo $gImageDimensions; ?>
                     style="width:100%;height:100%;object-fit:cover;opacity:.85;">
            </picture>
            <?php if ($gCat): ?>
            <span class="card-cat"><?php echo $gCat; ?></span>
            <?php endif; ?>
        </a>
        <?php else: ?>
        <a href="<?php echo htmlspecialchars(meridian_post_url($item), ENT_QUOTES, 'UTF-8'); ?>" class="card-thumb" style="background:<?php echo $grad; ?>;">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><?php echo $svg; ?></svg>
            <?php if ($gCat): ?>
            <span class="card-cat"><?php echo $gCat; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <div class="card-body">
            <h3><a href="<?php echo htmlspecialchars(meridian_post_url($item), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $gTitle; ?></a></h3>
            <?php if ($gExcerpt): ?>
            <p><?php echo $gExcerpt; ?></p>
            <?php endif; ?>
            <div class="card-footer">
                <?php if ($gDate): ?><time><?php echo $gDate; ?></time><?php endif; ?>
                <?php echo meridian_post_update_badge($item); ?>
                <a href="<?php echo htmlspecialchars(meridian_post_url($item), ENT_QUOTES, 'UTF-8'); ?>" class="read-link">Lesen →</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
