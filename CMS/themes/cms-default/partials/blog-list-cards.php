<?php
/**
 * Blog – Listen-Cards (4 Beiträge, newspaper-style)
 *
 * Erwartete Variable:
 *   $listPosts – array of stdObjects, max. 4 Einträge
 *
 * @package CMSv2\Themes\CmsDefault\Partials
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (empty($listPosts)) {
    return;
}
?>
<div class="section-label"><h2>Aktuelle Artikel</h2></div>

<div class="article-list">
    <?php foreach ($listPosts as $item): ?>
    <?php
        $iTitle   = htmlspecialchars($item->title ?? '');
        $iSlug    = htmlspecialchars($item->slug ?? '');
        $iCat     = htmlspecialchars($item->category_name ?? '');
        $iCatSlug = htmlspecialchars($item->category_slug ?? '');
        $iExcerpt = htmlspecialchars(
            !empty($item->excerpt) ? $item->excerpt : meridian_excerpt($item->content ?? '', 120)
        );
        $iDate    = !empty($item->published_at ?? $item->created_at ?? '')
                    ? time_ago($item->published_at ?? $item->created_at)
                    : '';
        $iRead    = !empty($item->read_time) ? $item->read_time . ' Min.' : '5 Min.';
        $iImageReference = (string) ($item->featured_image ?? '');
        $iImage = function_exists('meridian_get_picture_sources')
            ? meridian_get_picture_sources($iImageReference, null, 320, 218)
            : ['url' => '', 'avif_url' => '', 'webp_url' => '', 'width' => 320, 'height' => 218];
        $iImageDimensions = function_exists('meridian_image_dimension_attributes')
            ? meridian_image_dimension_attributes($iImageReference, 320, 218)
            : 'width="320" height="218"';
    ?>
    <article class="article-row">
        <?php if ($iImage['url'] !== ''): ?>
        <a href="<?php echo htmlspecialchars(meridian_post_url($item), ENT_QUOTES, 'UTF-8'); ?>" class="art-thumb">
            <picture>
                <?php if (($iImage['avif_url'] ?? '') !== ''): ?>
                <source srcset="<?php echo htmlspecialchars($iImage['avif_url'], ENT_QUOTES, 'UTF-8'); ?>" type="image/avif">
                <?php endif; ?>
                <?php if ($iImage['webp_url'] !== ''): ?>
                <source srcset="<?php echo htmlspecialchars($iImage['webp_url'], ENT_QUOTES, 'UTF-8'); ?>" type="image/webp">
                <?php endif; ?>
                <img src="<?php echo htmlspecialchars($iImage['url'], ENT_QUOTES, 'UTF-8'); ?>"
                     alt="<?php echo $iTitle; ?>"
                     <?php echo function_exists('meridian_image_loading_attributes') ? meridian_image_loading_attributes(false) : 'loading="lazy" decoding="async"'; ?>
                     <?php echo $iImageDimensions; ?>>
            </picture>
        </a>
        <?php else: ?>
        <a href="<?php echo htmlspecialchars(meridian_post_url($item), ENT_QUOTES, 'UTF-8'); ?>" class="art-thumb" aria-hidden="true" tabindex="-1">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </a>
        <?php endif; ?>

        <div class="art-body">
            <?php if ($iCat): ?>
            <div class="art-cat">
                <a href="<?php echo htmlspecialchars(meridian_archive_url('category', (string) ($iCatSlug ?: $iCat)), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo $iCat; ?>
                </a>
            </div>
            <?php endif; ?>
            <div class="art-title">
                <a href="<?php echo htmlspecialchars(meridian_post_url($item), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $iTitle; ?></a>
            </div>
            <?php if ($iExcerpt): ?>
            <div class="art-excerpt"><?php echo $iExcerpt; ?></div>
            <?php endif; ?>
            <div class="art-meta">
                <?php if ($iDate): ?>
                <time><?php echo $iDate; ?></time>
                <span class="dot"></span>
                <?php endif; ?>
                <span class="read-t"><?php echo $iRead; ?></span>
                <?php echo meridian_post_update_badge($item); ?>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
</div>
