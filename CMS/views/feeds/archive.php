<?php
/**
 * Feed-Aggregator – Übersicht aller öffentlichen Bereiche (/<archiv-slug>, auch in /<archiv-slug>/embed).
 *
 * Theme-Override: themes/<slug>/feeds/archive.php
 *
 * @var array<string,mixed> $data settings, categories, items, search, page, pages, total, archive_url, archive_path,
 *                                prev_url, next_url, lang, embed
 * @var \CMS\Services\Feed\AggregatorFrontendController $controller
 */

declare(strict_types=1);

use CMS\Services\Feed\FeedI18n;

if (!defined('ABSPATH')) {
    exit;
}

$fdLang = (string) ($data['lang'] ?? 'de');
$fdT = static fn (string $key, string|int ...$args): string => FeedI18n::text('feeds.public.' . $key, $fdLang, ...$args);
$fdSettings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
$fdItems = is_array($data['items'] ?? null) ? $data['items'] : [];
$fdCategories = is_array($data['categories'] ?? null) ? $data['categories'] : [];
$fdSearch = (string) ($data['search'] ?? '');
$fdEmbed = !empty($data['embed']);
?>
<div class="fd-aggregator<?php echo $fdEmbed ? '' : ' fd-in-page'; ?>">
    <header class="fd-header">
        <div class="fd-header__inner">
            <?php if ($fdEmbed) : ?>
                <h1 class="fd-header__title"><?php echo esc_html((string) ($fdSettings['archive_title'] ?? '')); ?></h1>
            <?php endif; ?>
            <?php if ($fdEmbed && (string) ($fdSettings['archive_description'] ?? '') !== '') : ?>
                <p class="fd-header__desc"><?php echo esc_html((string) $fdSettings['archive_description']); ?></p>
            <?php endif; ?>
            <form method="get" action="<?php echo esc_attr((string) $data['archive_url']); ?>" class="fd-search" role="search">
                <label class="fd-sr-only" for="fd-search-input"><?php echo esc_html($fdT('search_label')); ?></label>
                <input type="search" id="fd-search-input" name="q" class="fd-search__input" maxlength="120"
                       value="<?php echo esc_attr($fdSearch); ?>" placeholder="<?php echo esc_attr($fdT('search_placeholder')); ?>">
                <button type="submit" class="fd-search__btn"><?php echo esc_html($fdT('search_button')); ?></button>
            </form>
        </div>
    </header>

    <div class="fd-main">
        <?php if (count($fdCategories) > 1) : ?>
            <nav class="fd-cat-nav" aria-label="<?php echo esc_attr($fdT('categories_aria')); ?>">
                <a href="<?php echo esc_attr((string) $data['archive_url']); ?>" class="fd-cat-nav__item fd-cat-nav__item--active" aria-current="page"><?php echo esc_html($fdT('all')); ?></a>
                <?php foreach ($fdCategories as $fdCategory) : ?>
                    <a href="<?php echo esc_attr((string) $fdCategory['url']); ?>" class="fd-cat-nav__item"<?php echo $fdEmbed ? ' target="_top"' : ''; ?>>
                        <?php if ((string) $fdCategory['icon'] !== '') : ?><span class="fd-cat-nav__icon" aria-hidden="true"><?php echo esc_html((string) $fdCategory['icon']); ?></span><?php endif; ?>
                        <?php echo esc_html((string) $fdCategory['name']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php if ($fdSearch !== '') : ?>
            <div class="fd-search-hint">
                <?php echo esc_html($fdT('results_for')); ?> „<strong><?php echo esc_html($fdSearch); ?></strong>“
                <a href="<?php echo esc_attr((string) $data['archive_url']); ?>" class="fd-search-hint__reset"><?php echo esc_html($fdT('reset')); ?></a>
            </div>
        <?php endif; ?>

        <?php if ($fdItems === []) : ?>
            <div class="fd-empty">
                <p class="fd-empty__icon" aria-hidden="true">📭</p>
                <p class="fd-empty__text"><?php echo esc_html($fdSearch !== '' ? $fdT('empty_search') : $fdT('empty_default')); ?></p>
            </div>
        <?php else : ?>
            <div class="fd-grid">
                <?php foreach ($fdItems as $fdItem) {
                    $controller->renderCard($fdItem, $fdSettings, $fdLang);
                } ?>
            </div>
        <?php endif; ?>

        <?php require __DIR__ . '/pagination.inc.php'; ?>
    </div>
</div>
