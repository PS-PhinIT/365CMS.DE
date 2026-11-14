<?php
/**
 * Meridian CMS Default – Statische Seite Template
 *
 * Vom Router bereitgestellte Variable:
 *   $page – array: id, title, slug, content, meta_description, content_updated_at
 *
 * @package CMSv2\Themes\CmsDefault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$pageTitleRaw = trim((string) ($page['title'] ?? ''));
$pageTitle = htmlspecialchars($pageTitleRaw, ENT_QUOTES, 'UTF-8');
$pageContent = (string) ($page['content'] ?? '');
$pageUpdated = (string) ($page['content_updated_at'] ?? '');
$pageUpdatedLabel = $pageUpdated !== '' ? meridian_format_date($pageUpdated, false) : '';
$pageUpdatedAgo = $pageUpdated !== '' ? time_ago($pageUpdated) : '';
$pageExcerptRaw = trim((string) ($page['meta_description'] ?? ''));
$pageExcerpt = $pageExcerptRaw !== '' ? htmlspecialchars($pageExcerptRaw, ENT_QUOTES, 'UTF-8') : '';
$pageSlug = trim((string) ($page['slug'] ?? ''));
$pageImageReference = (string) ($page['featured_image'] ?? '');
$pageImage = function_exists('meridian_get_picture_sources')
    ? meridian_get_picture_sources($pageImageReference, null, 1600, 900)
    : ['url' => '', 'avif_url' => '', 'webp_url' => '', 'width' => 1600, 'height' => 900];
$pageImageDimensions = function_exists('meridian_image_dimension_attributes')
    ? meridian_image_dimension_attributes($pageImageReference, 1600, 900)
    : 'width="1600" height="900"';
$pageImageLoading = function_exists('meridian_image_loading_attributes')
    ? meridian_image_loading_attributes(true)
    : 'loading="eager" decoding="async"';
$pageId = (int) ($page['id'] ?? 0);
?>

<article class="page-detail">
    <div class="breadcrumb-bar">
        <nav class="breadcrumb-inner" aria-label="Breadcrumb">
            <a href="<?php echo SITE_URL; ?>/"><?php echo esc_html(meridian_t('theme.nav.home')); ?></a>
            <span class="sep" aria-hidden="true">›</span>
            <span class="cur" aria-current="page"><?php echo $pageTitle; ?></span>
        </nav>
    </div>

    <div class="page-detail-shell">
        <header class="page-hero<?php echo $pageImage['url'] !== '' ? ' page-hero--with-media' : ''; ?>">
            <div class="page-hero__intro">
                <div class="page-hero__eyebrow"><?php echo esc_html(meridian_t('theme.page.eyebrow')); ?></div>
                <h1 class="page-hero__title"><?php echo $pageTitle; ?></h1>
                <?php if ($pageExcerpt !== ''): ?>
                <p class="page-hero__excerpt"><?php echo $pageExcerpt; ?></p>
                <?php endif; ?>

                <div class="page-hero__meta">
                    <?php if ($pageUpdatedLabel !== ''): ?>
                    <span><strong><?php echo esc_html(meridian_t('theme.post.updated')); ?></strong> <?php echo htmlspecialchars($pageUpdatedLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <?php if ($pageUpdatedAgo !== ''): ?>
                    <span><?php echo htmlspecialchars($pageUpdatedAgo, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <?php if ($pageSlug !== ''): ?>
                    <span>Slug: <code><?php echo htmlspecialchars($pageSlug, ENT_QUOTES, 'UTF-8'); ?></code></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($pageImage['url'] !== ''): ?>
            <figure class="page-hero__media">
                <picture>
                    <?php if (($pageImage['avif_url'] ?? '') !== ''): ?>
                    <source srcset="<?php echo htmlspecialchars($pageImage['avif_url'], ENT_QUOTES, 'UTF-8'); ?>" type="image/avif">
                    <?php endif; ?>
                    <?php if ($pageImage['webp_url'] !== ''): ?>
                    <source srcset="<?php echo htmlspecialchars($pageImage['webp_url'], ENT_QUOTES, 'UTF-8'); ?>" type="image/webp">
                    <?php endif; ?>
                    <img src="<?php echo htmlspecialchars($pageImage['url'], ENT_QUOTES, 'UTF-8'); ?>"
                         alt="<?php echo $pageTitle; ?>"
                         <?php echo $pageImageLoading; ?>
                         <?php echo $pageImageDimensions; ?>>
                </picture>
            </figure>
            <?php endif; ?>
        </header>

        <div class="page-content-wrap page-content-wrap--detail">
            <article class="page-article page-article--detail">
                <div class="post-body page-content">
                    <?php echo $pageContent; // Kommt aus DB, bereits sanitiert ?>
                </div>
            </article>
        </div>
    </div>
</article>
