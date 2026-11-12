<?php declare(strict_types=1); if (!defined('ABSPATH')) exit; ?>

<?php
$synonymItems = array_values(array_filter(array_map(
    static fn(string $item): string => trim($item),
    preg_split('/[\r\n,]+/', (string) ($entry['synonyms'] ?? '')) ?: []
)));
$showRelatedEntries = ($settings['show_related_entries'] ?? '1') === '1';
$relatedPostsLimit = max(3, min(6, (int) ($settings['related_posts_limit'] ?? 4)));
$showKeywordBadges = ($settings['show_keyword_badges'] ?? '1') === '1';
$uiText = isset($uiText) && is_array($uiText) ? $uiText : [];
$t = static function (string $key, string $fallback) use ($uiText): string {
    return isset($uiText[$key]) && is_string($uiText[$key]) && $uiText[$key] !== '' ? $uiText[$key] : $fallback;
};
$kbUrl = $kbUrl ?? (SITE_URL . '/kb');
$singleUrl = $singleUrl ?? (SITE_URL . '/kb/' . rawurlencode((string) ($entry['slug'] ?? '')));
$structuredDataJson = isset($structuredDataJson) ? (string) $structuredDataJson : '';
$contentSections = \CMS\Services\Knowledgebase\Service\SectionContent::instance()->renderEntrySections($entry, (string) ($publicLang ?? 'de'));
$showSidebar = $showKeywordBadges || !empty($entry['tooltip_text']);
$designSettings = is_array($settings ?? null) ? $settings : [];
$singleDesignClasses = [
    'cms-kb-content--hero-' . \CMS\Services\Knowledgebase\Support\Defaults::designChoice($designSettings, 'single_hero_style'),
    'cms-kb-content--sections-' . \CMS\Services\Knowledgebase\Support\Defaults::designChoice($designSettings, 'single_section_style'),
    'cms-kb-content--sidebar-' . \CMS\Services\Knowledgebase\Support\Defaults::designChoice($designSettings, 'single_sidebar_style'),
    'cms-kb-content--sidebar-' . \CMS\Services\Knowledgebase\Support\Defaults::designChoice($designSettings, 'single_sidebar_position'),
];
if (($designSettings['single_section_tints'] ?? '1') === '1') {
    $singleDesignClasses[] = 'has-section-tints';
}
if (($designSettings['single_section_icons'] ?? '1') === '1') {
    $singleDesignClasses[] = 'has-section-icons';
}
if (($designSettings['single_sidebar_sticky'] ?? '1') === '1') {
    $singleDesignClasses[] = 'has-sticky-sidebar';
}
$formatRelatedPostDate = static function (?string $dateValue, string $locale = 'de'): string {
    $rawValue = trim((string) $dateValue);
    if ($rawValue === '') {
        return '';
    }

    if (function_exists('phinit_format_date')) {
        try {
            return (string) phinit_format_date($rawValue, 'numeric', $locale);
        } catch (\Throwable) {
        }
    }

    $timestamp = strtotime($rawValue);
    if ($timestamp === false) {
        return '';
    }

    return $locale === 'en' ? date('m/d/Y', $timestamp) : date('d.m.Y', $timestamp);
};
$estimateRelatedReadTime = static function (array $post): int {
    $contentSource = trim((string) ($post['content'] ?? ''));
    if ($contentSource === '') {
        $contentSource = trim((string) ($post['excerpt'] ?? ''));
    }

    if ($contentSource === '') {
        return 0;
    }

    if (function_exists('phinit_reading_time')) {
        try {
            return max(1, (int) phinit_reading_time($contentSource));
        } catch (\Throwable) {
        }
    }

    $wordCount = str_word_count(strip_tags($contentSource));
    if ($wordCount <= 0) {
        return 0;
    }

    return max(1, (int) ceil($wordCount / 220));
};
?>

<main class="cms-kb-content cms-kb-content--single <?php echo htmlspecialchars(implode(' ', $singleDesignClasses), ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($structuredDataJson !== ''): ?>
        <script type="application/ld+json"><?php echo $structuredDataJson; ?></script>
    <?php endif; ?>
    <article class="cms-kb-article">
        <nav class="cms-kb-breadcrumbs" aria-label="<?php echo htmlspecialchars($t('breadcrumb_aria', 'Breadcrumb'), ENT_QUOTES, 'UTF-8'); ?>">
            <ol class="cms-kb-breadcrumbs__list">
                <li><a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t('breadcrumb_home', 'Start'), ENT_QUOTES, 'UTF-8'); ?></a></li>
                <li aria-hidden="true">/</li>
                <li><a href="<?php echo htmlspecialchars($kbUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t('breadcrumb_kb', 'Knowledgebase'), ENT_QUOTES, 'UTF-8'); ?></a></li>
                <li aria-hidden="true">/</li>
                <li><span aria-current="page"><?php echo htmlspecialchars((string) $entry['title'], ENT_QUOTES, 'UTF-8'); ?></span></li>
            </ol>
        </nav>

        <header class="cms-kb-hero cms-kb-hero--single">
            <div class="cms-kb-hero__content">
                <?php if (!empty($entry['category'])): ?>
                    <p class="cms-kb-entry__meta"><?php echo htmlspecialchars((string) $entry['category'], ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>

                <h1><?php echo htmlspecialchars((string) $entry['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>
        </header>

        <div class="cms-kb-single-layout<?php echo $showSidebar ? ' has-sidebar' : ''; ?>">
            <section class="cms-kb-body">
                <?php if ($contentSections !== []): ?>
                    <div class="cms-kb-richtext cms-kb-sections">
                        <?php foreach ($contentSections as $contentSection): ?>
                            <?php $sectionHeadingId = 'cms-kb-section-' . $contentSection['key']; ?>
                            <section class="cms-kb-section cms-kb-section--<?php echo htmlspecialchars($contentSection['key'], ENT_QUOTES, 'UTF-8'); ?>" aria-labelledby="<?php echo htmlspecialchars($sectionHeadingId, ENT_QUOTES, 'UTF-8'); ?>">
                                <h2 id="<?php echo htmlspecialchars($sectionHeadingId, ENT_QUOTES, 'UTF-8'); ?>" class="cms-kb-section__title"><?php echo htmlspecialchars($t('section_' . $contentSection['key'], $contentSection['heading']), ENT_QUOTES, 'UTF-8'); ?></h2>
                                <div class="cms-kb-section__body">
                                    <?php echo $contentSection['html']; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($showRelatedEntries && !empty($relatedPosts)): ?>
                    <section class="cms-kb-related-block" aria-labelledby="cms-kb-related-heading">
                        <h2 id="cms-kb-related-heading" class="cms-kb-related-block__title"><?php echo htmlspecialchars($t('related_heading', 'Verwandte Artikel'), ENT_QUOTES, 'UTF-8'); ?></h2>
                        <div class="cms-kb-related-posts">
                            <?php foreach (array_slice($relatedPosts, 0, $relatedPostsLimit) as $related): ?>
                                <?php
                                $relatedDateRaw = trim((string) ($related['published_at'] ?? $related['created_at'] ?? ''));
                                $relatedDateLabel = $formatRelatedPostDate($relatedDateRaw, $contentLocale ?? 'de');
                                $relatedDateIso = '';
                                if ($relatedDateRaw !== '') {
                                    $relatedTimestamp = strtotime($relatedDateRaw);
                                    if ($relatedTimestamp !== false) {
                                        $relatedDateIso = date(DATE_ATOM, $relatedTimestamp);
                                    }
                                }
                                $relatedReadTime = $estimateRelatedReadTime($related);
                                ?>
                                <article class="cms-kb-related-post">
                                    <p class="cms-kb-related-post__meta">
                                        <?php if (!empty($related['category_name'])): ?>
                                            <span><?php echo htmlspecialchars((string) $related['category_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php else: ?>
                                            <span><?php echo htmlspecialchars($t('related_fallback_category', '365CMS Beitrag'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </p>
                                    <h3 class="cms-kb-related-post__title">
                                        <a href="<?php echo htmlspecialchars((string) ($related['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars((string) ($related['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </h3>
                                    <?php if ($relatedDateLabel !== '' || $relatedReadTime > 0): ?>
                                        <div class="cms-kb-related-post__details" aria-label="<?php echo htmlspecialchars($t('related_meta_aria', 'Metainformationen zum Eintrag'), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php if ($relatedDateLabel !== ''): ?>
                                                <time class="cms-kb-related-post__detail"<?php echo $relatedDateIso !== '' ? ' datetime="' . htmlspecialchars($relatedDateIso, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo htmlspecialchars($relatedDateLabel, ENT_QUOTES, 'UTF-8'); ?></time>
                                            <?php endif; ?>
                                            <?php if ($relatedReadTime > 0): ?>
                                                <span class="cms-kb-related-post__detail"><?php echo htmlspecialchars(($contentLocale ?? 'de') === 'en' ? $relatedReadTime . ' min read' : $relatedReadTime . ' Min. Lesezeit', ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <a class="cms-kb-related-post__cta" href="<?php echo htmlspecialchars((string) ($related['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">… </span><?php echo htmlspecialchars($t('entry_cta', 'zum Eintrag'), ENT_QUOTES, 'UTF-8'); ?></a>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </section>

            <?php if ($showSidebar): ?>
            <aside class="cms-kb-sidebar">
                <?php if ($showKeywordBadges): ?>
                    <section class="cms-kb-sidebar__section">
                        <h2><?php echo htmlspecialchars($t('details_heading', 'Begriffsdetails'), ENT_QUOTES, 'UTF-8'); ?></h2>
                        <ul class="cms-kb-sidebar__facts">
                            <li><strong><?php echo htmlspecialchars($t('details_keyword', 'Keyword'), ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo htmlspecialchars((string) $entry['keyword'], ENT_QUOTES, 'UTF-8'); ?></span></li>
                            <li><strong><?php echo htmlspecialchars($t('details_category', 'Bereich'), ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo htmlspecialchars((string) ($entry['category'] ?? $t('fallback_category', 'Allgemein')), ENT_QUOTES, 'UTF-8'); ?></span></li>
                            <?php if ($synonymItems !== []): ?>
                                <li><strong><?php echo htmlspecialchars($t('details_synonyms', 'Synonyme'), ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo htmlspecialchars(implode(', ', array_slice($synonymItems, 0, 4)), ENT_QUOTES, 'UTF-8'); ?></span></li>
                            <?php endif; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <?php if (!empty($entry['tooltip_text'])): ?>
                    <section class="cms-kb-sidebar__section">
                        <h2><?php echo htmlspecialchars($t('quick_explained_heading', 'Kurz erklärt'), ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p><?php echo htmlspecialchars((string) $entry['tooltip_text'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </section>
                <?php endif; ?>
            </aside>
            <?php endif; ?>
        </div>

        <footer class="cms-kb-article__footer">
            <a class="cms-kb-entry__cta" href="<?php echo htmlspecialchars($kbUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t('back_to_overview', '← Zurück zur Übersicht'), ENT_QUOTES, 'UTF-8'); ?></a>
        </footer>
    </article>
</main>
