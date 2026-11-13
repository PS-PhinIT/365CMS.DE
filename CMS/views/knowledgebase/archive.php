<?php declare(strict_types=1); if (!defined('ABSPATH')) exit; ?>

<?php
$archiveVariant = $archiveVariant ?? 'knowledgebase';
$archiveBasePath = $archiveBasePath ?? '/kb';
$archiveUrl = $archiveUrl ?? (SITE_URL . $archiveBasePath);
$entryBaseUrl = $entryBaseUrl ?? $archiveUrl;
$searchMode = $searchMode ?? 'default';
$searchModeOptions = isset($searchModeOptions) && is_array($searchModeOptions) ? $searchModeOptions : ['default', 'strict'];
$uiText = isset($uiText) && is_array($uiText) ? $uiText : [];
$t = static function (string $key, string $fallback) use ($uiText): string {
    return isset($uiText[$key]) && is_string($uiText[$key]) && $uiText[$key] !== '' ? $uiText[$key] : $fallback;
};
$archiveHeroEyebrow = $archiveHeroEyebrow ?? 'Wissen & Orientierung';
$archiveTitle = $archiveTitle ?? (string) ($settings['archive_title'] ?? 'Knowledgebase');
$archiveIntro = $archiveIntro ?? (string) ($settings['archive_intro'] ?? '');
$forceListLayout = $archiveVariant === 'glossary';
$showSidebar = false;
$hasFilters = $search !== '' || $category !== '';
$resultsLabel = $archiveResultsLabel ?? ($hasFilters ? 'Gefilterte Ergebnisse' : 'Alle Knowledgebase-Einträge');
$variantClass = $forceListLayout ? 'cms-kb-content--glossary' : 'cms-kb-content--overview';
$layoutClass = $forceListLayout ? 'cms-kb-archive-layout--list' : 'cms-kb-archive-layout--grid';
$gridClass = $forceListLayout ? 'cms-kb-entry-grid--list' : 'cms-kb-entry-grid--cards';
$entryClass = $forceListLayout ? 'cms-kb-entry--list' : 'cms-kb-entry--card';
$designSettings = is_array($settings ?? null) ? $settings : [];
$archiveDesignClasses = [
    $variantClass,
    'cms-kb-content--hero-' . \CMS\Services\Knowledgebase\Support\Defaults::designChoice($designSettings, 'archive_hero_style'),
    'cms-kb-content--cards-' . \CMS\Services\Knowledgebase\Support\Defaults::designChoice($designSettings, 'archive_card_style'),
    'cms-kb-content--cols-' . \CMS\Services\Knowledgebase\Support\Defaults::designChoice($designSettings, 'archive_columns'),
];
if (($designSettings['archive_card_accent_bar'] ?? '1') === '1') {
    $archiveDesignClasses[] = 'has-card-accent';
}
if ((int) ($designSettings['archive_excerpt_lines'] ?? 3) > 0) {
    $archiveDesignClasses[] = 'has-excerpt-clamp';
}
$totalEntries = isset($totalEntries) ? max(0, (int) $totalEntries) : count($entries);
$currentPage = isset($currentPage) ? max(1, (int) $currentPage) : 1;
$perPage = isset($perPage) ? max(1, (int) $perPage) : 25;
$totalPages = isset($totalPages) ? max(1, (int) $totalPages) : max(1, (int) ceil($totalEntries / $perPage));
$pageBaseUrl = $pageBaseUrl ?? $archiveUrl;
$pageQuery = [];
if ($search !== '') {
    $pageQuery['q'] = $search;
}
if ($category !== '') {
    $pageQuery['category'] = $category;
}
$pageQuery['search_mode'] = $searchMode;
$perPageOptions = isset($perPageOptions) && is_array($perPageOptions) ? $perPageOptions : [25, 50, 100, 200];
$pageQuery['per_page'] = $perPage;
$pageWindowStart = $totalEntries > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;
$pageWindowEnd = $totalEntries > 0 ? min($totalEntries, $pageWindowStart + count($entries) - 1) : 0;
?>

<main class="cms-kb-content cms-kb-content--archive <?php echo htmlspecialchars(implode(' ', $archiveDesignClasses), ENT_QUOTES, 'UTF-8'); ?>">
    <header class="cms-kb-hero">
        <div class="cms-kb-hero__content cms-kb-hero__content--compact">
            <div class="cms-kb-hero__headline">
                <p class="cms-kb-hero__eyebrow"><?php echo htmlspecialchars($archiveHeroEyebrow, ENT_QUOTES, 'UTF-8'); ?></p>
                <h1><?php echo htmlspecialchars($archiveTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>

            <div class="cms-kb-hero__subline cms-kb-hero__subline--compact">
                <?php if ($archiveIntro !== ''): ?>
                    <p class="cms-kb-hero__intro"><?php echo htmlspecialchars($archiveIntro, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>

                <?php if ($hasFilters): ?>
                    <div class="cms-kb-chip-row" aria-label="<?php echo htmlspecialchars($t('active_filters_aria', 'Aktive Filter'), ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if ($search !== ''): ?>
                            <span class="cms-kb-chip"><?php echo htmlspecialchars($t('filter_search_prefix', 'Suche') . ': ' . $search, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if ($category !== ''): ?>
                            <span class="cms-kb-chip"><?php echo htmlspecialchars($t('filter_category_prefix', 'Kategorie') . ': ' . $category, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (($settings['show_search'] ?? '1') === '1'): ?>
            <nav class="cms-kb-filters cms-kb-filters--inline" aria-label="<?php echo htmlspecialchars($t('filters_aria', 'Knowledgebase-Filter'), ENT_QUOTES, 'UTF-8'); ?>">
                <form method="get" role="search" class="cms-kb-filters__form" action="<?php echo htmlspecialchars($archiveUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="cms-kb-filters__field cms-kb-filters__field--search">
                        <label for="kb-search-query"><?php echo htmlspecialchars($t('search_label', 'Begriff suchen'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input id="kb-search-query" type="search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars($t('search_placeholder', 'Begriff suchen …'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="cms-kb-filters__field cms-kb-filters__field--category">
                        <label for="kb-category-filter"><?php echo htmlspecialchars($t('category_label', 'Kategorie'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <select id="kb-category-filter" name="category">
                            <option value=""><?php echo htmlspecialchars($t('all_categories_option', 'Alle Kategorien'), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php foreach ($categories as $item): ?>
                                <?php $categoryValue = (string) ($item['category'] ?? ''); ?>
                                <option value="<?php echo htmlspecialchars($categoryValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $category === $categoryValue ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($categoryValue, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cms-kb-filters__field cms-kb-filters__field--search-mode">
                        <label for="kb-search-mode-filter"><?php echo htmlspecialchars($t('search_mode_label', 'Suchmodus'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <select id="kb-search-mode-filter" name="search_mode">
                            <?php foreach ($searchModeOptions as $modeOption): ?>
                                <?php $modeOption = (string) $modeOption; ?>
                                <?php if (!in_array($modeOption, ['default', 'strict'], true)) { continue; } ?>
                                <option value="<?php echo htmlspecialchars($modeOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $searchMode === $modeOption ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($modeOption === 'strict' ? $t('search_mode_strict', 'Strict') : $t('search_mode_default', 'Standard (tolerant)'), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cms-kb-filters__field cms-kb-filters__field--per-page">
                        <label for="kb-per-page-filter"><?php echo htmlspecialchars($t('per_page_label', 'Anzahl'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <select id="kb-per-page-filter" name="per_page">
                            <?php foreach ($perPageOptions as $option): ?>
                                <option value="<?php echo (int) $option; ?>" <?php echo $perPage === (int) $option ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((int) $option . ' ' . $t('entries_suffix', 'Einträge'), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cms-kb-filters__actions">
                        <button type="submit"><?php echo htmlspecialchars($t('search_button', 'Suchen'), ENT_QUOTES, 'UTF-8'); ?></button>

                        <?php if ($search !== '' || $category !== ''): ?>
                            <a href="<?php echo htmlspecialchars($archiveUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t('reset_button', 'Zurücksetzen'), ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </nav>
            <?php endif; ?>

            <?php if (!empty($categories) && ($settings['show_category_sidebar'] ?? '1') === '1'): ?>
                <div class="cms-kb-chip-row cms-kb-chip-row--categories" aria-label="<?php echo htmlspecialchars($t('categories_aria', 'Kategorien'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php $allQuery = ['per_page' => $perPage, 'search_mode' => $searchMode]; if ($search !== '') { $allQuery['q'] = $search; } ?>
                    <a class="cms-kb-chip<?php echo $category === '' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($archiveUrl . '?' . http_build_query($allQuery), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t('all_categories_chip', 'Alle'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php foreach ($categories as $item): ?>
                        <?php $categoryValue = (string) ($item['category'] ?? ''); ?>
                        <?php $categoryLinkQuery = ['category' => $categoryValue, 'per_page' => $perPage, 'search_mode' => $searchMode]; if ($search !== '') { $categoryLinkQuery['q'] = $search; } ?>
                        <a class="cms-kb-chip<?php echo $category === $categoryValue ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($archiveUrl . '?' . http_build_query($categoryLinkQuery), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($categoryValue, ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <div class="cms-kb-archive-layout <?php echo htmlspecialchars($layoutClass, ENT_QUOTES, 'UTF-8'); ?><?php echo $showSidebar ? ' has-sidebar' : ''; ?>">
        <section class="cms-kb-listing cms-kb-listing--<?php echo htmlspecialchars($archiveVariant, ENT_QUOTES, 'UTF-8'); ?>" aria-labelledby="kb-results-heading">
            <h2 id="kb-results-heading" class="screen-reader-text"><?php echo htmlspecialchars($resultsLabel, ENT_QUOTES, 'UTF-8'); ?></h2>

            <?php if (empty($entries)): ?>
                <div class="cms-kb-empty-state" role="status" aria-live="polite">
                    <p class="cms-kb-empty-state__title"><?php echo htmlspecialchars($t('empty_title', 'Keine Einträge gefunden'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="cms-kb-empty-state__body"><?php echo htmlspecialchars($t('empty_body', 'Versuche es mit einem anderen Suchbegriff oder entferne den Kategorie-Filter.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            <?php else: ?>
                <div class="cms-kb-entry-grid <?php echo htmlspecialchars($gridClass, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php foreach ($entries as $item): ?>
                        <article class="cms-kb-entry <?php echo htmlspecialchars($entryClass, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="cms-kb-entry__main">
                                <header class="cms-kb-entry__header">
                                    <p class="cms-kb-entry__meta">
                                        <?php echo htmlspecialchars((string) ($item['category'] ?? $t('fallback_category', 'Allgemein')), ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <h2>
                                        <a href="<?php echo htmlspecialchars($entryBaseUrl . '/' . rawurlencode((string) $item['slug']), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </h2>
                                </header>

                                <?php if (!empty($item['excerpt'])): ?>
                                    <p class="cms-kb-entry__excerpt"><?php echo htmlspecialchars((string) $item['excerpt'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="cms-kb-entry__aside">
                                <footer class="cms-kb-entry__footer">
                                    <a class="cms-kb-entry__cta" href="<?php echo htmlspecialchars($entryBaseUrl . '/' . rawurlencode((string) $item['slug']), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">… </span><?php echo htmlspecialchars($t('entry_cta', 'zum Eintrag'), ENT_QUOTES, 'UTF-8'); ?></a>
                                </footer>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="cms-kb-chip-row" aria-label="<?php echo htmlspecialchars($t('pagination_aria', 'Seitennavigation'), ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if ($currentPage > 1): ?>
                            <?php $prevQuery = $pageQuery; $prevQuery['page'] = $currentPage - 1; ?>
                            <a class="cms-kb-chip" href="<?php echo htmlspecialchars($pageBaseUrl . '?' . http_build_query($prevQuery), ENT_QUOTES, 'UTF-8'); ?>" rel="prev"><?php echo htmlspecialchars($t('pagination_prev', '← Zurück'), ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endif; ?>

                        <span class="cms-kb-chip" aria-current="page"><?php echo htmlspecialchars($t('pagination_page_prefix', 'Seite') . ' ' . number_format($currentPage) . ' ' . $t('pagination_page_connector', 'von') . ' ' . number_format($totalPages), ENT_QUOTES, 'UTF-8'); ?></span>

                        <?php if ($currentPage < $totalPages): ?>
                            <?php $nextQuery = $pageQuery; $nextQuery['page'] = $currentPage + 1; ?>
                            <a class="cms-kb-chip" href="<?php echo htmlspecialchars($pageBaseUrl . '?' . http_build_query($nextQuery), ENT_QUOTES, 'UTF-8'); ?>" rel="next"><?php echo htmlspecialchars($t('pagination_next', 'Weiter →'), ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</main>
