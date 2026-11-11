<?php
/**
 * Feed-Aggregator – Artikelkarte (Raster, Liste, Magazin).
 *
 * Theme-Override: themes/<slug>/feeds/card.php
 *
 * @var array<string,mixed> $item     Artikel inkl. source_name, category_name
 * @var array<string,mixed> $settings Aggregator-Einstellungen (show_*, excerpt_length, open_in_new_tab)
 * @var string              $lang
 * @var bool                $hero     erste große Karte im Magazin-Layout
 */

declare(strict_types=1);

use CMS\Services\Feed\AggregatorFrontendController;
use CMS\Services\Feed\FeedI18n;

if (!defined('ABSPATH')) {
    exit;
}

$fdLink = AggregatorFrontendController::safeUrl($item['link'] ?? '');
$fdImage = !empty($settings['show_image']) ? AggregatorFrontendController::safeUrl($item['image_url'] ?? '') : '';
$fdTitle = trim((string) ($item['title'] ?? '')) !== '' ? (string) $item['title'] : FeedI18n::text('feeds.public.untitled', $lang);
$fdNewTab = !empty($settings['open_in_new_tab']);
$fdTarget = $fdNewTab ? ' target="_blank" rel="noopener noreferrer nofollow"' : ' rel="nofollow"';
$fdExcerptLength = max(50, (int) ($settings['excerpt_length'] ?? 160));
$fdExcerpt = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) ($item['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
if (mb_strlen($fdExcerpt) > $fdExcerptLength) {
    $fdExcerpt = rtrim(mb_substr($fdExcerpt, 0, $fdExcerptLength)) . '…';
}
$fdDate = FeedI18n::formatDate((string) ($item['pub_date'] ?? ''), $lang);
$fdClasses = 'fd-card' . (!empty($hero) ? ' fd-card--hero' : '') . (!empty($item['is_featured']) ? ' fd-card--featured' : '');
?>
<article class="<?php echo esc_attr($fdClasses); ?>">
    <?php if ($fdImage !== '' && $fdLink !== '') : ?>
        <a href="<?php echo esc_attr($fdLink); ?>" class="fd-card__image-link" tabindex="-1" aria-hidden="true"<?php echo $fdTarget; ?>>
            <img src="<?php echo esc_attr($fdImage); ?>" alt="" class="fd-card__image" loading="lazy" decoding="async" referrerpolicy="no-referrer">
        </a>
    <?php endif; ?>
    <div class="fd-card__body">
        <?php if (!empty($item['is_featured'])) : ?>
            <span class="fd-card__badge"><?php echo esc_html(FeedI18n::text('feeds.public.featured', $lang)); ?></span>
        <?php endif; ?>
        <h3 class="fd-card__title">
            <?php if ($fdLink !== '') : ?>
                <a href="<?php echo esc_attr($fdLink); ?>"<?php echo $fdTarget; ?>><?php echo esc_html($fdTitle); ?></a>
            <?php else : ?>
                <?php echo esc_html($fdTitle); ?>
            <?php endif; ?>
        </h3>
        <?php if (!empty($settings['show_excerpt']) && $fdExcerpt !== '') : ?>
            <p class="fd-card__excerpt"><?php echo esc_html($fdExcerpt); ?></p>
        <?php endif; ?>
        <div class="fd-card__meta">
            <?php if (!empty($settings['show_source']) && (string) ($item['source_name'] ?? '') !== '') : ?>
                <span class="fd-card__source"><?php echo esc_html((string) $item['source_name']); ?></span>
            <?php endif; ?>
            <?php if (!empty($settings['show_date']) && $fdDate !== '') : ?>
                <time class="fd-card__date" datetime="<?php echo esc_attr(date('c', (int) strtotime((string) $item['pub_date']))); ?>"><?php echo esc_html($fdDate); ?></time>
            <?php endif; ?>
            <?php if (!empty($settings['show_source']) && (string) ($item['author'] ?? '') !== '') : ?>
                <span class="fd-card__author"><?php echo esc_html(FeedI18n::text('feeds.public.by', $lang, (string) $item['author'])); ?></span>
            <?php endif; ?>
        </div>
    </div>
</article>
