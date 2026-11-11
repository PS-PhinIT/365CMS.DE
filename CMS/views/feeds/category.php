<?php
/**
 * Feed-Aggregator – Bereichsseite (/feed/<bereich>, /<archiv-slug>/<bereich>) im Layout des Bereichs.
 *
 * Theme-Override: themes/<slug>/feeds/category.php
 *
 * @var array<string,mixed> $data settings, category (id, name, description, icon, slug, layout), categories, sources,
 *                                items, search, page, pages, total, archive_path, archive_url, prev_url, next_url, lang
 * @var \CMS\Services\Feed\AggregatorFrontendController $controller
 */

declare(strict_types=1);

use CMS\Services\Feed\AggregatorFrontendController;
use CMS\Services\Feed\FeedI18n;

if (!defined('ABSPATH')) {
    exit;
}

$fdLang = (string) ($data['lang'] ?? 'de');
$fdT = static fn (string $key, string|int ...$args): string => FeedI18n::text('feeds.public.' . $key, $fdLang, ...$args);
$fdSettings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
$fdCategory = is_array($data['category'] ?? null) ? $data['category'] : [];
$fdItems = is_array($data['items'] ?? null) ? $data['items'] : [];
$fdSources = is_array($data['sources'] ?? null) ? $data['sources'] : [];
$fdSearch = (string) ($data['search'] ?? '');
$fdLayout = in_array($fdCategory['layout'] ?? '', ['grid', 'list', 'magazine'], true) ? (string) $fdCategory['layout'] : 'grid';
?>
<div class="fd-aggregator fd-in-page">
    <header class="fd-header">
        <div class="fd-header__inner">
            <div class="fd-header__breadcrumb">
                <a href="<?php echo esc_attr((string) $data['archive_path']); ?>"><?php echo esc_html($fdT('back_to_overview')); ?></a>
            </div>
            <form method="get" action="<?php echo esc_attr((string) $data['archive_url']); ?>" class="fd-search" role="search">
                <label class="fd-sr-only" for="fd-search-input"><?php echo esc_html($fdT('search_label')); ?></label>
                <input type="search" id="fd-search-input" name="q" class="fd-search__input" maxlength="120"
                       value="<?php echo esc_attr($fdSearch); ?>" placeholder="<?php echo esc_attr($fdT('search_placeholder')); ?>">
                <button type="submit" class="fd-search__btn"><?php echo esc_html($fdT('search_button')); ?></button>
            </form>
        </div>
    </header>

    <div class="fd-main">
        <?php if ($fdSearch !== '') : ?>
            <div class="fd-search-hint">
                <?php echo esc_html($fdT('results_for')); ?> „<strong><?php echo esc_html($fdSearch); ?></strong>“
                <a href="<?php echo esc_attr((string) $data['archive_url']); ?>" class="fd-search-hint__reset"><?php echo esc_html($fdT('reset')); ?></a>
            </div>
        <?php endif; ?>

        <?php if ($fdItems === []) : ?>
            <div class="fd-empty">
                <p class="fd-empty__icon" aria-hidden="true"><?php echo esc_html((string) ($fdCategory['icon'] ?? '') !== '' ? (string) $fdCategory['icon'] : '📭'); ?></p>
                <p class="fd-empty__text"><?php echo esc_html($fdSearch !== '' ? $fdT('empty_search') : $fdT('empty_category')); ?></p>
            </div>
        <?php else : ?>
            <div class="fd-<?php echo esc_attr($fdLayout); ?>">
                <?php foreach ($fdItems as $fdIndex => $fdItem) {
                    $controller->renderCard($fdItem, $fdSettings, $fdLang, $fdLayout === 'magazine' && $fdIndex === 0 && (int) $data['page'] === 1);
                } ?>
            </div>
        <?php endif; ?>

        <?php require __DIR__ . '/pagination.inc.php'; ?>

        <?php if ($fdSources !== []) : ?>
            <section class="fd-sources" aria-label="<?php echo esc_attr($fdT('sources')); ?>">
                <strong><?php echo esc_html($fdT('sources')); ?></strong>
                <ul>
                    <?php foreach ($fdSources as $fdSource) :
                        $fdSite = AggregatorFrontendController::safeUrl($fdSource['site_url'] ?? ''); ?>
                        <li>
                            <?php if ($fdSite !== '') : ?>
                                <a href="<?php echo esc_attr($fdSite); ?>" rel="noopener noreferrer nofollow" target="_blank"><?php echo esc_html((string) $fdSource['name']); ?></a>
                            <?php else : ?>
                                <?php echo esc_html((string) $fdSource['name']); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>
