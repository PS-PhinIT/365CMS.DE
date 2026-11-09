<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}


$aiTranslationEnabled = !empty($aiTranslationEnabled);
$aiSeoMetadataEnabled = !empty($aiSeoMetadataEnabled);
$aiSeoMetadataUnavailableReason = is_string($aiSeoMetadataUnavailableReason ?? null) ? trim($aiSeoMetadataUnavailableReason) : '';
$aiSettingsUrl = is_string($aiSettingsUrl ?? null) ? trim($aiSettingsUrl) : '';

/**
 * Posts – Edit / Create View
 *
 * Aufbau: Kopfleiste (Zurück, Speicherstatus, Fokusmodus, Vorschau, Speichern/Planen/Veröffentlichen),
 * Schreibbereich (Kategorie-Label, Serif-Titel, EditorJS) und rechte Einstellungs-Spalte
 * (Veröffentlichungs-Checkliste, Veröffentlichung, Kategorie, Tags, Beitragsbild, SEO-Beschreibung, Revisionen).
 * Verhalten: assets/js/post-editor/*.js (ES-Module), Styles: assets/css/admin-post-editor.css.
 *
 * Erwartet: $data (aus PostsModule::getEditData())
 *           $csrfToken
 *           $postEditorApiUrl, $postEditorApiToken (JSON-API für Autosave, Revisionen, Wiederherstellen)
 */

$post       = $data['post'] ?? null;
if (is_array($post)) {
    foreach ($post as $postField => $postValue) {
        if (!is_scalar($postValue) && $postValue !== null) {
            $post[$postField] = '';
        }
    }
} elseif ($post !== null) {
    $post = null;
}
$isNew      = $data['isNew'] ?? true;
$categories = $data['categories'] ?? [];
$assignedCategoryIds = array_values(array_unique(array_map('intval', (array) ($data['assignedCategoryIds'] ?? []))));
$availableTags = $data['tags'] ?? [];
$postTagsData  = $data['postTags'] ?? [];
$postTemplates = is_array($data['postTemplates'] ?? null) ? $data['postTemplates'] : [];
if ($postTemplates === []) {
    $postTemplates = [['id' => 'default', 'label' => 'Standard', 'description' => '', 'meta_fields' => []]];
}
$postTemplateValue = trim((string) ($post['post_template'] ?? 'default'));
$postTemplateIds = array_values(array_filter(array_map(static fn(array $template): string => trim((string) ($template['id'] ?? '')), $postTemplates)));
if ($postTemplateValue === '' || !in_array($postTemplateValue, $postTemplateIds, true)) {
    $postTemplateValue = in_array('default', $postTemplateIds, true) ? 'default' : (string) ($postTemplateIds[0] ?? 'default');
}
$postTemplateMetaValues = [];
try {
    $decodedPostTemplateMeta = json_decode((string) ($post['post_meta_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (is_array($decodedPostTemplateMeta)) {
        $postTemplateMetaValues = $decodedPostTemplateMeta;
    }
} catch (\Throwable) {
    $postTemplateMetaValues = [];
}
$postEditorWidth = function_exists('get_option') ? (int)get_option('setting_post_editor_width', 750) : 750;
$postEditorWidth = max(320, min(1600, $postEditorWidth));
$postDefaultStatus = function_exists('get_option') ? (string)get_option('setting_post_default_status', 'draft') : 'draft';
if (!in_array($postDefaultStatus, ['draft', 'published', 'private'], true)) {
    $postDefaultStatus = 'draft';
}
$cmsEditorThemeTypographyConfig = static function (): array {
    $fontStacks = [
        'system-ui' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        'inter' => '"Inter", system-ui, sans-serif',
        'space-grotesk' => '"Space Grotesk", "Sora", "Inter", system-ui, sans-serif',
        'sora' => '"Sora", "Space Grotesk", "Inter", system-ui, sans-serif',
        'barlow' => '"Barlow", system-ui, sans-serif',
        'barlow-condensed' => '"Barlow Condensed", "Barlow", system-ui, sans-serif',
        'roboto' => '"Roboto", system-ui, sans-serif',
        'roboto-condensed' => '"Roboto Condensed", "Roboto", Arial, sans-serif',
        'open-sans' => '"Open Sans", system-ui, sans-serif',
        'lato' => '"Lato", system-ui, sans-serif',
        'montserrat' => '"Montserrat", system-ui, sans-serif',
        'poppins' => '"Poppins", system-ui, sans-serif',
        'source-sans' => '"Source Sans 3", "Inter", system-ui, sans-serif',
        'nunito' => '"Nunito", system-ui, sans-serif',
        'oswald' => '"Oswald", "Roboto Condensed", Arial, sans-serif',
        'rajdhani' => '"Rajdhani", "Barlow Condensed", "Inter", sans-serif',
        'exo2' => '"Exo 2", "Inter", system-ui, sans-serif',
        'dm-sans' => '"DM Sans", system-ui, sans-serif',
        'libre-baskerville' => '"Libre Baskerville", Georgia, serif',
        'playfair-display' => '"Playfair Display", Georgia, serif',
        'merriweather' => '"Merriweather", Georgia, serif',
        'jetbrains-mono' => '"JetBrains Mono", "Fira Code", "Cascadia Code", monospace',
        'fira-code' => '"Fira Code", "JetBrains Mono", "Cascadia Code", monospace',
        'source-code' => '"Source Code Pro", "Fira Code", monospace',
        'cascadia' => '"Cascadia Code", "JetBrains Mono", monospace',
        'system-mono' => '"Cascadia Code", Consolas, "Courier New", monospace',
        'arial' => 'Arial, "Helvetica Neue", Helvetica, sans-serif',
    ];
    $resolveStack = static function (string $key) use ($fontStacks): string {
        $key = strtolower(trim($key));
        $customStack = function_exists('get_option') ? trim((string) get_option('font_stack_' . $key, '')) : '';
        $stack = $customStack !== '' ? $customStack : ($fontStacks[$key] ?? $fontStacks['system-ui']);

        return preg_replace('/[^a-z0-9\s\'",.\-]/i', '', $stack) ?? $fontStacks['system-ui'];
    };

    $bodyKey = function_exists('get_option') ? (string) get_option('font_body', 'system-ui') : 'system-ui';
    $headingKey = function_exists('get_option') ? (string) get_option('font_heading', $bodyKey) : $bodyKey;
    $fontSize = function_exists('get_option') ? (int) get_option('font_size_base', 16) : 16;
    $lineHeight = function_exists('get_option') ? (float) get_option('font_line_height', 1.6) : 1.6;
    $activeThemeSlug = '';
    $themeStylesheetUrl = '';

    try {
        $themeManager = \CMS\ThemeManager::instance();
        $activeThemeSlug = $themeManager->getActiveThemeSlug();
        $themeStylesheetPath = rtrim($themeManager->getThemePath(), '/\\') . DIRECTORY_SEPARATOR . 'style.css';
        $themeStylesheetUrl = rtrim($themeManager->getThemeUrl(), '/') . '/style.css';
        if (is_file($themeStylesheetPath)) {
            $themeStylesheetUrl .= '?v=' . filemtime($themeStylesheetPath);
        }
    } catch (\Throwable) {
        $activeThemeSlug = '';
        $themeStylesheetUrl = '';
    }

    return [
        'bodyFontStack' => $resolveStack($bodyKey),
        'headingFontStack' => $resolveStack($headingKey),
        'fontSize' => max(12, min(24, $fontSize)) . 'px',
        'lineHeight' => (string) max(1.1, min(2.2, $lineHeight)),
        'activeThemeSlug' => $activeThemeSlug,
        'themeStylesheetUrl' => $themeStylesheetUrl,
    ];
};

$title      = htmlspecialchars($post['title'] ?? '');
$titleEn    = htmlspecialchars($post['title_en'] ?? '');
$slug       = htmlspecialchars($post['slug'] ?? '');
$slugEn     = htmlspecialchars($post['slug_en'] ?? '');
$content    = $post['content'] ?? '';
$contentEn  = $post['content_en'] ?? '';
$extractEditorPlainText = static function (string $rawContent): string {
    $rawContent = trim($rawContent);
    if ($rawContent === '') {
        return '';
    }

    $decoded = json_decode($rawContent, true);
    if (!is_array($decoded) || !isset($decoded['blocks']) || !is_array($decoded['blocks'])) {
        return strip_tags($rawContent);
    }

    $parts = [];
    foreach ($decoded['blocks'] as $block) {
        if (!is_array($block)) {
            continue;
        }

        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        foreach (['text', 'message', 'title', 'code', 'caption', 'content', 'html'] as $key) {
            $rawValue = $data[$key] ?? '';
            $value = is_scalar($rawValue) ? trim((string) $rawValue) : '';
            if ($value !== '') {
                $parts[] = trim(strip_tags(str_replace('<br>', "\n", $value)));
                break;
            }
        }
    }

    return trim(implode("\n\n", array_filter($parts, static fn(string $part): bool => $part !== '')));
};
$excerpt    = htmlspecialchars($post['excerpt'] ?? '');
$excerptEn  = htmlspecialchars($post['excerpt_en'] ?? '');
$status     = $post['status'] ?? $postDefaultStatus;
$categoryId = (int)($post['category_id'] ?? 0);
$featuredImg = htmlspecialchars($post['featured_image'] ?? '');
$publishedAtValue = (string)($post['published_at'] ?? '');
$publishDate = '';
$publishTime = '';
if ($publishedAtValue !== '') {
    $publishedTimestamp = strtotime($publishedAtValue);
    if ($publishedTimestamp !== false) {
        $publishDate = date('Y-m-d', $publishedTimestamp);
        $publishTime = date('H:i', $publishedTimestamp);
    }
} elseif ($isNew) {
    $publishDate = date('Y-m-d');
    $publishTime = date('H:i');
}
$isScheduledPost = $post !== null && \cms_post_is_scheduled($post);
$contentUpdatedAtValue = (string)($post['content_updated_at'] ?? '');
$contentUpdatedDate = '';
$contentUpdatedTime = '';
if ($contentUpdatedAtValue !== '') {
    $contentUpdatedTimestamp = strtotime($contentUpdatedAtValue);
    if ($contentUpdatedTimestamp !== false) {
        $contentUpdatedDate = date('Y-m-d', $contentUpdatedTimestamp);
        $contentUpdatedTime = date('H:i', $contentUpdatedTimestamp);
    }
}
$treatUpdateAsNewChecked = !empty($post['treat_update_as_new']);
$metaTitle  = htmlspecialchars($post['meta_title'] ?? '');
$metaDesc   = htmlspecialchars($post['meta_description'] ?? '');
$authorDisplayName = htmlspecialchars($post['author_display_name'] ?? '', ENT_QUOTES);
$authorDisplayUrl = htmlspecialchars($post['author_display_url'] ?? '', ENT_QUOTES);
$tagString  = htmlspecialchars(implode(', ', array_map(static fn(array $tag): string => (string)($tag['name'] ?? ''), $postTagsData)), ENT_QUOTES);
$seoMeta = $data['seoMeta'] ?? [];
$seoTemplateSettings = \CMS\Services\SeoAnalysisService::getInstance()->getSettings();
$permalinkService = class_exists('\CMS\Services\PermalinkService') ? \CMS\Services\PermalinkService::getInstance() : null;
$focusKeyphrase = htmlspecialchars((string)($seoMeta['focus_keyphrase'] ?? ''));
$postSeoKeywords = htmlspecialchars((string)($seoMeta['keywords'] ?? ''));
$canonicalUrl = htmlspecialchars((string)($seoMeta['canonical_url'] ?? ''));
$robotsIndex = !array_key_exists('robots_index', $seoMeta) || !empty($seoMeta['robots_index']);
$robotsFollow = !array_key_exists('robots_follow', $seoMeta) || !empty($seoMeta['robots_follow']);
$ogTitle = htmlspecialchars((string)($seoMeta['og_title'] ?? ''));
$ogDescription = htmlspecialchars((string)($seoMeta['og_description'] ?? ''));
$ogImage = htmlspecialchars((string)($seoMeta['og_image'] ?? ''));
$twitterTitle = htmlspecialchars((string)($seoMeta['twitter_title'] ?? ''));
$twitterDescription = htmlspecialchars((string)($seoMeta['twitter_description'] ?? ''));
$twitterImage = htmlspecialchars((string)($seoMeta['twitter_image'] ?? ''));
$twitterCard = htmlspecialchars((string)($seoMeta['twitter_card'] ?? 'summary_large_image'));
$schemaType = htmlspecialchars((string)($seoMeta['schema_type'] ?? 'Article'));
$sitemapPriority = htmlspecialchars((string)($seoMeta['sitemap_priority'] ?? ''));
$sitemapChangefreq = htmlspecialchars((string)($seoMeta['sitemap_changefreq'] ?? 'monthly'));
$hreflangGroup = htmlspecialchars((string)($seoMeta['hreflang_group'] ?? ''));
$hasGermanVariant = trim((string)($post['title'] ?? '')) !== ''
    || trim((string)($post['content'] ?? '')) !== ''
    || trim((string)($post['excerpt'] ?? '')) !== '';
$hasEnglishVariant = trim((string)($post['title_en'] ?? '')) !== ''
    || trim((string)($post['content_en'] ?? '')) !== ''
    || trim((string)($post['excerpt_en'] ?? '')) !== ''
    || trim((string)($post['slug_en'] ?? '')) !== '';
$isEnglishOnlyPost = !$hasGermanVariant && $hasEnglishVariant;
$defaultContentLanguage = $isEnglishOnlyPost ? 'en' : 'de';
$editorLocale = (($editorLocale ?? $defaultContentLanguage) === 'en') ? 'en' : 'de';
$isEnglishEditorView = $editorLocale === 'en';
$additionalCategoryIds = array_values(array_filter(
    $assignedCategoryIds,
    static fn (int $assignedId): bool => $assignedId > 0 && $assignedId !== $categoryId
));

$postTitleValue = (string)($post['title'] ?? '');
$postSlugValue = (string)($post['slug'] ?? '');
$postSlugEnValue = (string)($post['slug_en'] ?? '');
$postContentValue = (string)$content;
$postContentEnValue = (string)$contentEn;
$postContentPlainValue = $extractEditorPlainText($postContentValue);
$postContentPlainEnValue = $extractEditorPlainText($postContentEnValue);
$postExcerptValue = (string)($post['excerpt'] ?? '');
$postTitleEnValue = (string)($post['title_en'] ?? '');
$postExcerptEnValue = (string)($post['excerpt_en'] ?? '');
$postStatusValue = (string)$status;
$postMetaTitleValue = (string)($post['meta_title'] ?? '');
$postMetaDescriptionValue = (string)($post['meta_description'] ?? '');
$postFeaturedImageValue = (string)($post['featured_image'] ?? '');
$postPreviewUrlTemplate = $permalinkService !== null
    ? $permalinkService->buildPostUrlTemplate((string)($post['published_at'] ?? ''), (string)($post['created_at'] ?? ''))
    : '/blog/{slug}';
$postPreviewUrlTemplateEn = $permalinkService !== null
    ? $permalinkService->buildPostUrlTemplate((string)($post['published_at'] ?? ''), (string)($post['created_at'] ?? ''), 'en')
    : '/en/blog/{slug}';
$postPreviewUrl = str_replace('{slug}', ltrim($postSlugValue !== '' ? $postSlugValue : 'beitrag', '/'), $postPreviewUrlTemplate);
$postPreviewUrlEn = str_replace('{slug}', ltrim($postSlugEnValue !== '' ? $postSlugEnValue : ($postSlugValue !== '' ? $postSlugValue : 'beitrag'), '/'), $postPreviewUrlTemplateEn);
$postEditUrlDe = $isNew
    ? '/admin/posts?action=edit&lang=de'
    : '/admin/posts?action=edit&id=' . (int) ($post['id'] ?? 0) . '&lang=de';
$postEditUrlEn = $isNew
    ? '/admin/posts?action=edit&lang=en'
    : '/admin/posts?action=edit&id=' . (int) ($post['id'] ?? 0) . '&lang=en';
$activePostTitleValue = $isEnglishEditorView ? $postTitleEnValue : $postTitleValue;
$activePostSlugValue = $isEnglishEditorView ? $postSlugEnValue : $postSlugValue;
$activePostExcerptValue = $isEnglishEditorView ? $postExcerptEnValue : $postExcerptValue;
$activePostPreviewUrl = $isEnglishEditorView ? $postPreviewUrlEn : $postPreviewUrl;
$activePostPreviewUrlTemplate = $isEnglishEditorView ? $postPreviewUrlTemplateEn : $postPreviewUrlTemplate;
$activePostPreviewSlugFallback = $isEnglishEditorView
    ? ($postSlugEnValue !== '' ? $postSlugEnValue : ($postSlugValue !== '' ? $postSlugValue : 'beitrag'))
    : ($postSlugValue !== '' ? $postSlugValue : 'beitrag');
$activeTitleInputId = $isEnglishEditorView ? 'titleEn' : 'title';
$activeSlugInputId = $isEnglishEditorView ? 'slugEn' : 'slug';
$activeExcerptInputId = $isEnglishEditorView ? 'excerptEn' : 'excerpt';
$activeContentInputId = $isEnglishEditorView ? 'postPlainEditorEn' : 'postPlainEditorDe';
$activeEditorHolderId = $isEnglishEditorView ? 'editorjsEn' : 'editorjs';
$activeContentFieldName = $isEnglishEditorView ? 'content_en' : 'content';
$activeContentPlainTextValue = $isEnglishEditorView ? $postContentPlainEnValue : $postContentPlainValue;
$activeContentLabel = $isEnglishEditorView ? 'EditorJS Notfall-Fallback (EN)' : 'EditorJS Notfall-Fallback (DE)';
$postPermalinkHint = $permalinkService !== null
    ? $permalinkService->getPostPermalinkStructure()
    : '/blog/%postname%';
$categoryNamesById = [];
foreach ($categories as $cat) {
    $categoryNamesById[(int) ($cat['id'] ?? 0)] = (string) ($cat['name'] ?? '');
}
$selectedCategoryName = $categoryId > 0 && ($categoryNamesById[$categoryId] ?? '') !== ''
    ? $categoryNamesById[$categoryId]
    : 'Keine Kategorie';
$postEditorDraftKey = $isNew
    ? substr(hash('sha256', 'post:' . ($postTitleValue !== '' ? $postTitleValue : microtime((bool) true)) . ':' . session_id()), 0, 12)
    : '';

// Veröffentlichungs-Checkliste (dieselben Regeln wie PostsModule::save() und assets/js/post-editor/checklist.js).
$postEditorApiUrl = is_string($postEditorApiUrl ?? null) && $postEditorApiUrl !== '' ? $postEditorApiUrl : '/admin/post-editor-api';
$postEditorApiToken = is_string($postEditorApiToken ?? null) ? $postEditorApiToken : '';
$postRevisions = array_values(array_filter(is_array($data['revisions'] ?? null) ? $data['revisions'] : [], 'is_array'));
$storedPublishState = is_array($data['storedPublishState'] ?? null) ? $data['storedPublishState'] : null;
$publishChecklistService = \CMS\Services\PostPublishChecklistService::getInstance();
$isLivePost = !$isNew && $publishChecklistService->isLive($storedPublishState);
$storedPublishedAtTimestamp = $storedPublishState !== null ? strtotime((string) ($storedPublishState['published_at'] ?? '')) : false;
$storedPublishedAtIso = $storedPublishedAtTimestamp !== false ? date('c', $storedPublishedAtTimestamp) : '';
$checklistCategoryId = $categoryId > 0 ? $categoryId : (int) ($additionalCategoryIds[0] ?? 0);
$publishChecklist = $publishChecklistService->evaluate([
    'featured_image' => $postFeaturedImageValue,
    'meta_description' => $postMetaDescriptionValue,
    'category_id' => $checklistCategoryId,
]);
$publishChecklistPassedCount = count(array_filter($publishChecklist['items'], static fn(array $item): bool => !empty($item['passed'])));
$publishChecklistIcons = [
    'featured_image' => 'ti-photo',
    'meta_description' => 'ti-file-description',
    'category' => 'ti-folder',
];
$metaDescriptionLength = $publishChecklistService->metaDescriptionLength($postMetaDescriptionValue);
$metaDescriptionState = $metaDescriptionLength === 0
    ? 'is-empty'
    : ($metaDescriptionLength < \CMS\Services\PostPublishChecklistService::META_DESCRIPTION_MIN
        ? 'is-short'
        : ($metaDescriptionLength > \CMS\Services\PostPublishChecklistService::META_DESCRIPTION_MAX ? 'is-long' : 'is-ok'));
$kickerCategoryName = $checklistCategoryId > 0 && ($categoryNamesById[$checklistCategoryId] ?? '') !== ''
    ? $categoryNamesById[$checklistCategoryId]
    : 'Kategorie wählen';

// Speicherstatus in der Kopfleiste; nach einem abgelehnten Speichern (Inline-Rendering per POST) ist der Stand ungespeichert.
$postEditorIsInlineRender = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
$postUpdatedTimestamp = !$isNew ? strtotime((string) ($post['updated_at'] ?? '')) : false;
$postEditorSavedAtIso = $postUpdatedTimestamp !== false ? date('c', $postUpdatedTimestamp) : '';
if ($postEditorIsInlineRender) {
    $postEditorSaveStateLabel = 'Nicht gespeichert – bitte Hinweis prüfen';
} elseif ($postUpdatedTimestamp !== false) {
    $postEditorSaveStateLabel = date('Y-m-d', $postUpdatedTimestamp) === date('Y-m-d')
        ? 'Gespeichert um ' . date('H:i', $postUpdatedTimestamp)
        : 'Zuletzt gespeichert am ' . date('d.m.Y', $postUpdatedTimestamp) . ' um ' . date('H:i', $postUpdatedTimestamp);
} else {
    $postEditorSaveStateLabel = $isNew ? 'Noch nicht gespeichert' : 'Gespeichert';
}
$postEditorPostId = $isNew ? 0 : (int) ($post['id'] ?? 0);
?>

<div class="page-body cms-post-editor-page">
    <div class="container-xl">
        <header class="cms-editor-header" id="postEditorHeader">
            <div class="cms-editor-header__start">
                <a href="/admin/posts" class="btn btn-ghost-secondary cms-editor-header__back" id="postBackToList">
                    <i class="ti ti-arrow-left" aria-hidden="true"></i>
                    <span>Beiträge</span>
                </a>
                <div class="cms-editor-header__heading">
                    <h1 class="cms-editor-header__title">
                        <?php echo $isNew ? 'Neuer Beitrag' : 'Beitrag bearbeiten'; ?>
                        <span class="cms-editor-header__locale" title="<?php echo $isEnglishEditorView ? 'Englische Fassung' : 'Deutsche Fassung'; ?>"><?php echo $isEnglishEditorView ? 'EN' : 'DE'; ?></span>
                    </h1>
                    <div class="cms-editor-savestate" id="postEditorSaveState">
                        <span class="cms-editor-savestate__text" id="postEditorSaveStatus" data-saved-at="<?php echo htmlspecialchars($postEditorSavedAtIso, ENT_QUOTES); ?>"><?php echo htmlspecialchars($postEditorSaveStateLabel); ?></span>
                    </div>
                </div>
            </div>
            <div class="cms-editor-header__actions">
                <button type="button" class="btn btn-ghost-secondary btn-icon cms-editor-header__icon" id="postFocusModeToggle" aria-pressed="false" aria-label="Fokusmodus" title="Fokusmodus (Strg+Umschalt+F)" aria-keyshortcuts="Control+Shift+F">
                    <i class="ti ti-focus-2" aria-hidden="true"></i>
                </button>
                <?php if (!$isNew): ?>
                    <a class="btn btn-ghost-secondary cms-editor-header__btn" id="postPreviewButton" href="<?php echo htmlspecialchars($activePostPreviewUrl, ENT_QUOTES); ?>" target="_blank" rel="noopener noreferrer">
                        <i class="ti ti-eye" aria-hidden="true"></i>
                        <span class="cms-editor-header__label">Vorschau</span>
                        <span class="visually-hidden">(öffnet in neuem Tab)</span>
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-ghost-secondary cms-editor-header__btn" id="postPreviewButton" aria-disabled="true" aria-describedby="postPreviewHint">
                        <i class="ti ti-eye" aria-hidden="true"></i>
                        <span class="cms-editor-header__label">Vorschau</span>
                    </button>
                    <span class="visually-hidden" id="postPreviewHint">Die Vorschau ist nach dem ersten Speichern verfügbar.</span>
                <?php endif; ?>
                <?php if ($isLivePost): ?>
                    <button type="submit" form="postForm" class="btn btn-primary cms-editor-header__btn" id="postSaveButton" data-checklist-gate="conditional">
                        <i class="ti ti-device-floppy" aria-hidden="true"></i>
                        <span class="cms-editor-header__label">Aktualisieren</span>
                    </button>
                <?php else: ?>
                    <button type="submit" form="postForm" class="btn btn-outline-primary cms-editor-header__btn" id="postSaveButton" data-checklist-gate="conditional">
                        <i class="ti ti-device-floppy" aria-hidden="true"></i>
                        <span class="cms-editor-header__label">Speichern</span>
                    </button>
                    <button type="submit" form="postForm" name="publish_intent" value="schedule" class="btn btn-outline-primary cms-editor-header__btn" id="postScheduleButton" data-checklist-gate="always">
                        <i class="ti ti-calendar-time" aria-hidden="true"></i>
                        <span class="cms-editor-header__label">Planen</span>
                    </button>
                    <button type="submit" form="postForm" name="publish_intent" value="publish" class="btn btn-primary cms-editor-header__btn" id="postPublishButton" data-checklist-gate="always">
                        <i class="ti ti-send" aria-hidden="true"></i>
                        <span class="cms-editor-header__label">Veröffentlichen</span>
                    </button>
                <?php endif; ?>
                <span class="visually-hidden" id="postChecklistGateHint">Nicht verfügbar, solange die Veröffentlichungs-Checkliste unvollständig ist.</span>
            </div>
        </header>

        <?php if (!empty($alert)): ?>
            <div class="cms-editor-alert">
                <?php $alertData = $alert; $alertMarginClass = 'mb-0'; require __DIR__ . '/../partials/flash-alert.php'; ?>
            </div>
        <?php endif; ?>

        <form
            method="post"
            action="/admin/posts"
            id="postForm"
            class="cms-post-editor"
            data-post-editor
            data-post-id="<?php echo $postEditorPostId; ?>"
            data-is-new="<?php echo $isNew ? '1' : '0'; ?>"
            data-is-live="<?php echo $isLivePost ? '1' : '0'; ?>"
            data-stored-status="<?php echo htmlspecialchars((string) ($storedPublishState['status'] ?? ''), ENT_QUOTES); ?>"
            data-stored-published-at="<?php echo htmlspecialchars($storedPublishedAtIso, ENT_QUOTES); ?>"
            data-api-url="<?php echo htmlspecialchars($postEditorApiUrl, ENT_QUOTES); ?>"
            data-api-token="<?php echo htmlspecialchars($postEditorApiToken, ENT_QUOTES); ?>"
            data-locale="<?php echo htmlspecialchars($editorLocale, ENT_QUOTES); ?>"
            data-writing-width="<?php echo (int) $postEditorWidth; ?>"
            data-inline-render="<?php echo $postEditorIsInlineRender ? '1' : '0'; ?>"
        >
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="editor_locale" value="<?php echo htmlspecialchars($editorLocale); ?>">
            <?php if (!$isNew): ?>
                <input type="hidden" name="id" value="<?php echo (int)$post['id']; ?>">
            <?php endif; ?>

            <?php if ($isEnglishEditorView): ?>
                <input type="hidden" name="title" id="title" value="<?php echo htmlspecialchars($postTitleValue); ?>">
                <input type="hidden" name="slug" id="slug" value="<?php echo htmlspecialchars($postSlugValue); ?>">
                <input type="hidden" name="excerpt" id="excerpt" value="<?php echo htmlspecialchars($postExcerptValue); ?>">
                <input type="hidden" id="contentInput" name="content" value="<?php echo htmlspecialchars($postContentValue); ?>">
                <input type="hidden" id="contentInputEn" name="content_en" value="<?php echo htmlspecialchars($postContentEnValue); ?>">
            <?php else: ?>
                <input type="hidden" name="title_en" id="titleEn" value="<?php echo htmlspecialchars($postTitleEnValue); ?>">
                <input type="hidden" name="slug_en" id="slugEn" value="<?php echo htmlspecialchars($postSlugEnValue); ?>">
                <input type="hidden" name="excerpt_en" id="excerptEn" value="<?php echo htmlspecialchars($postExcerptEnValue); ?>">
                <input type="hidden" id="contentInput" name="content" value="<?php echo htmlspecialchars($postContentValue); ?>">
                <input type="hidden" id="contentInputEn" name="content_en" value="<?php echo htmlspecialchars($postContentEnValue); ?>">
            <?php endif; ?>
            <input type="hidden" name="content_original" value="<?php echo htmlspecialchars($postContentValue); ?>">
            <input type="hidden" name="content_en_original" value="<?php echo htmlspecialchars($postContentEnValue); ?>">

            <div class="cms-editor-layout">
                <div class="cms-editor-main">
                    <div class="cms-editor-writing" id="postEditorWriting">
                        <div class="cms-editor-writing__meta">
                            <button type="button" class="cms-editor-kicker" id="postCategoryLabel" data-jump-field="category_id">
                                <span class="visually-hidden">Kategorie ändern, aktuell: </span>
                                <span id="postCategoryLabelText"><?php echo htmlspecialchars($kickerCategoryName); ?></span>
                            </button>
                            <nav class="cms-editor-langs" aria-label="Inhaltssprache">
                                <a class="cms-editor-langs__link<?php echo $isEnglishEditorView ? '' : ' is-active'; ?>" href="<?php echo htmlspecialchars($postEditUrlDe); ?>" lang="de" data-lang-tab="de"<?php echo $isEnglishEditorView ? '' : ' aria-current="page"'; ?>>Deutsch</a>
                                <a class="cms-editor-langs__link<?php echo $isEnglishEditorView ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($postEditUrlEn); ?>" lang="en" data-lang-tab="en"<?php echo $isEnglishEditorView ? ' aria-current="page"' : ''; ?>>English</a>
                            </nav>
                        </div>

                        <div class="cms-editor-title">
                            <label class="visually-hidden" for="<?php echo htmlspecialchars($activeTitleInputId); ?>"><?php echo $isEnglishEditorView ? 'Englischer Titel' : ($isEnglishOnlyPost ? 'Deutscher Titel' : 'Titel'); ?></label>
                            <textarea class="form-control cms-editor-title-input" id="<?php echo htmlspecialchars($activeTitleInputId); ?>" name="<?php echo $isEnglishEditorView ? 'title_en' : 'title'; ?>" rows="1" maxlength="255" <?php echo (!$isEnglishEditorView && !$isEnglishOnlyPost) ? 'required' : ''; ?> autocomplete="off" placeholder="<?php echo htmlspecialchars($isEnglishEditorView ? 'English article title' : ($isEnglishOnlyPost ? 'Optional, falls du zusätzlich eine deutsche Variante pflegen möchtest' : 'Beitragstitel')); ?>"<?php echo $isEnglishEditorView ? ' lang="en"' : ''; ?>><?php echo htmlspecialchars($activePostTitleValue); ?></textarea>
                            <?php if ($isEnglishOnlyPost && !$isEnglishEditorView): ?>
                                <p class="form-hint cms-editor-title__hint">Dieser Beitrag enthält aktuell nur englische Inhalte. Die Bearbeitung startet deshalb standardmäßig in der EN-Ansicht; deutsche Felder bleiben optional.</p>
                            <?php elseif ($isEnglishEditorView): ?>
                                <p class="form-hint cms-editor-title__hint">Dies ist die eigenständige EN-Bearbeitung. Die deutsche Variante bleibt separat erhalten und wird beim Speichern nicht überschrieben.</p>
                            <?php endif; ?>
                        </div>

                        <?php if ($isEnglishEditorView && (!$isNew || $aiTranslationEnabled)): ?>
                            <div class="cms-editor-writing__toolbar btn-list">
                                <?php if (!$isNew): ?>
                                    <button
                                        type="submit"
                                        name="_action"
                                        value="copy_de_to_en"
                                        class="btn btn-outline-secondary btn-sm"
                                        id="copyPostDeToEnButton"
                                        form="postForm"
                                        formnovalidate
                                        data-confirm="Die EN-Fassung wird serverseitig mit den deutschen Beitragsinhalten überschrieben. Fortfahren?"
                                    >DE nach EN kopieren</button>
                                <?php endif; ?>
                                <?php if ($aiTranslationEnabled): ?>
                                    <button type="button" class="btn btn-primary btn-sm" id="translatePostDeToEnButton">Mit AI nach EN übersetzen</button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div
                            class="cms-editor-plain-wrap mb-3<?php echo !empty($useEditorJs) ? ' cms-editor-plain-wrap--enhanced' : ''; ?>"
                            id="<?php echo htmlspecialchars($isEnglishEditorView ? 'postPlainEditorWrapEn' : 'postPlainEditorWrapDe'); ?>"
                            <?php echo !empty($useEditorJs) ? 'hidden' : ''; ?>
                        >
                            <label class="form-label" for="<?php echo htmlspecialchars($activeContentInputId); ?>"><?php echo htmlspecialchars($activeContentLabel); ?></label>
                            <textarea
                                class="form-control cms-editor-plain-textarea"
                                id="<?php echo htmlspecialchars($activeContentInputId); ?>"
                                name="<?php echo htmlspecialchars($activeContentFieldName); ?>"
                                rows="14"
                                <?php echo !empty($useEditorJs) ? 'disabled' : ''; ?>
                            ><?php echo htmlspecialchars($activeContentPlainTextValue); ?></textarea>
                        </div>
                        <?php if (!empty($useEditorJs)): ?>
                            <div class="editorjs-wrap cms-editor-writing__editor" id="<?php echo htmlspecialchars($isEnglishEditorView ? 'editorjsEn_wrap' : 'editorjs_wrap'); ?>" data-editor-state="loading"<?php echo $isEnglishEditorView ? ' lang="en"' : ''; ?>>
                                <div id="<?php echo htmlspecialchars($activeEditorHolderId); ?>" class="editorjs-holder"></div>
                            </div>
                            <div class="d-none" aria-hidden="true">
                                <div id="postHiddenEditorDe"></div>
                                <div id="postHiddenEditorEn"></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="cms-editor-extras" id="postEditorExtras">
                        <details class="card cms-edit-card cms-collapsible-card cms-collapsible-card--post-template" open>
                            <summary class="card-header cms-collapsible-card__summary">
                                <h3 class="card-title mb-0">Beitrags-Template & Zusatzkarte</h3>
                                <span class="cms-collapsible-card__chevron" aria-hidden="true"></span>
                            </summary>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-lg-4">
                                        <label class="form-label" for="postTemplate">Beitrags-Template</label>
                                        <select class="form-select" id="postTemplate" name="post_template">
                                            <?php foreach ($postTemplates as $template): ?>
                                                <?php
                                                $templateId = trim((string) ($template['id'] ?? ''));
                                                if ($templateId === '') {
                                                    continue;
                                                }
                                                ?>
                                                <option value="<?php echo htmlspecialchars($templateId, ENT_QUOTES); ?>" <?php echo $templateId === $postTemplateValue ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string) ($template['label'] ?? $templateId), ENT_QUOTES); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-hint">Das aktive Theme steuert, welche Templates und Zusatzfelder verfügbar sind.</div>
                                    </div>

                                    <div class="col-lg-8">
                                        <?php foreach ($postTemplates as $template): ?>
                                            <?php
                                            $templateId = trim((string) ($template['id'] ?? ''));
                                            $templateLabel = trim((string) ($template['label'] ?? $templateId));
                                            $templateDescription = trim((string) ($template['description'] ?? ''));
                                            $metaFields = is_array($template['meta_fields'] ?? null) ? $template['meta_fields'] : [];
                                            if ($templateId === '' || $metaFields === []) {
                                                continue;
                                            }
                                            ?>
                                            <div class="border rounded-3 p-3" data-post-template-meta-panel="<?php echo htmlspecialchars($templateId, ENT_QUOTES); ?>" <?php echo $templateId === $postTemplateValue ? '' : 'hidden'; ?>>
                                                <div class="fw-semibold mb-1"><?php echo htmlspecialchars($templateLabel, ENT_QUOTES); ?> – Zusatzkarte</div>
                                                <?php if ($templateDescription !== ''): ?>
                                                    <div class="text-secondary small mb-3"><?php echo htmlspecialchars($templateDescription, ENT_QUOTES); ?></div>
                                                <?php endif; ?>
                                                <div class="row g-3">
                                                    <?php foreach ($metaFields as $fieldKey => $field): ?>
                                                        <?php
                                                        if (!is_array($field)) {
                                                            continue;
                                                        }
                                                        $fieldKey = preg_replace('/[^a-z0-9_-]/i', '', (string) $fieldKey) ?? '';
                                                        if ($fieldKey === '') {
                                                            continue;
                                                        }
                                                        $fieldId = 'postMeta_' . $templateId . '_' . $fieldKey;
                                                        $fieldType = strtolower(trim((string) ($field['type'] ?? 'text')));
                                                        $fieldLabel = trim((string) ($field['label'] ?? $fieldKey));
                                                        $fieldPlaceholder = trim((string) ($field['placeholder'] ?? ''));
                                                        $fieldValue = $postTemplateMetaValues[$fieldKey] ?? '';
                                                        $fieldStringValue = is_array($fieldValue) ? implode("\n", array_map('strval', $fieldValue)) : (string) $fieldValue;
                                                        ?>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="<?php echo htmlspecialchars($fieldId, ENT_QUOTES); ?>"><?php echo htmlspecialchars($fieldLabel, ENT_QUOTES); ?></label>
                                                            <?php if ($fieldType === 'textarea' || $fieldType === 'array'): ?>
                                                                <textarea class="form-control" id="<?php echo htmlspecialchars($fieldId, ENT_QUOTES); ?>" name="post_meta[<?php echo htmlspecialchars($fieldKey, ENT_QUOTES); ?>]" rows="<?php echo $fieldType === 'array' ? 3 : 4; ?>" placeholder="<?php echo htmlspecialchars($fieldPlaceholder, ENT_QUOTES); ?>"><?php echo htmlspecialchars($fieldStringValue); ?></textarea>
                                                                <?php if ($fieldType === 'array'): ?><div class="form-hint">Ein Eintrag pro Zeile oder per Komma getrennt.</div><?php endif; ?>
                                                            <?php elseif ($fieldType === 'select'): ?>
                                                                <select class="form-select" id="<?php echo htmlspecialchars($fieldId, ENT_QUOTES); ?>" name="post_meta[<?php echo htmlspecialchars($fieldKey, ENT_QUOTES); ?>]">
                                                                    <option value="">Nicht anzeigen</option>
                                                                    <?php foreach ((array) ($field['options'] ?? []) as $option): ?>
                                                                        <?php $optionValue = (string) $option; ?>
                                                                        <option value="<?php echo htmlspecialchars($optionValue, ENT_QUOTES); ?>" <?php echo $fieldStringValue === $optionValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($optionValue, ENT_QUOTES); ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            <?php else: ?>
                                                                <?php $inputType = in_array($fieldType, ['date', 'url'], true) ? $fieldType : 'text'; ?>
                                                                <input type="<?php echo htmlspecialchars($inputType, ENT_QUOTES); ?>" class="form-control" id="<?php echo htmlspecialchars($fieldId, ENT_QUOTES); ?>" name="post_meta[<?php echo htmlspecialchars($fieldKey, ENT_QUOTES); ?>]" value="<?php echo htmlspecialchars($fieldStringValue, ENT_QUOTES); ?>" placeholder="<?php echo htmlspecialchars($fieldPlaceholder, ENT_QUOTES); ?>">
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="text-secondary small" data-post-template-meta-empty <?php echo array_filter($postTemplates, static fn(array $template): bool => (string) ($template['id'] ?? '') === $postTemplateValue && !empty($template['meta_fields'])) ? 'hidden' : ''; ?>>Für dieses Template sind keine Zusatzfelder definiert.</div>
                                    </div>
                                </div>
                            </div>
                        </details>

                        <details class="card cms-edit-card cms-collapsible-card cms-collapsible-card--seo">
                            <summary class="card-header cms-collapsible-card__summary">
                                <h3 class="card-title mb-0">SEO-Card</h3>
                                <span class="cms-collapsible-card__chevron" aria-hidden="true"></span>
                            </summary>
                            <div class="card-body">
                                <?php if ($aiSeoMetadataEnabled): ?>
                                    <div class="alert alert-info py-2 mb-3">
                                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                            <span class="small">Erzeugt SEO-Metadaten aus dem Haupttext. Beitragstitel, Slug und URL-Felder bleiben unverändert.</span>
                                            <button type="button" class="btn btn-primary btn-sm" id="generatePostSeoMetadataButton">SEO mit AI füllen</button>
                                        </div>
                                    </div>
                                <?php elseif ($aiSeoMetadataUnavailableReason !== ''): ?>
                                    <div class="alert alert-warning py-2 mb-3 small" role="status">
                                        <strong>SEO mit AI ist hier nicht verfügbar.</strong>
                                        <?php echo htmlspecialchars($aiSeoMetadataUnavailableReason); ?>
                                        <?php if ($aiSettingsUrl !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($aiSettingsUrl); ?>">Zu den AI-Einstellungen</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="row g-3">
                                    <div class="col-lg-6">
                                        <label class="form-label" for="<?php echo htmlspecialchars($activeExcerptInputId); ?>"><?php echo $isEnglishEditorView ? 'Englische Kurzfassung' : 'Kurzfassung'; ?></label>
                                        <textarea class="form-control" id="<?php echo htmlspecialchars($activeExcerptInputId); ?>" name="<?php echo $isEnglishEditorView ? 'excerpt_en' : 'excerpt'; ?>" rows="5" placeholder="<?php echo htmlspecialchars($isEnglishEditorView ? 'Short English summary for overview pages…' : 'Kurze Zusammenfassung für Übersichten…'); ?>"><?php echo htmlspecialchars($activePostExcerptValue); ?></textarea>
                                        <span class="form-hint"><span id="excerptCount">0</span> Zeichen</span>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="mb-3">
                                            <label class="form-label" for="focusKeyphrase">Fokus-Keyphrase</label>
                                            <input type="text" class="form-control" id="focusKeyphrase" name="focus_keyphrase" value="<?php echo $focusKeyphrase; ?>" placeholder="z. B. KI-Strategie Mittelstand">
                                            <span class="form-hint">Mehrere Varianten per Komma möglich.</span>
                                        </div>
                                        <div class="mb-0">
                                            <label class="form-label" for="postSeoKeywords">SEO-Tags / Keywords</label>
                                            <input type="text" class="form-control" id="postSeoKeywords" name="keywords" value="<?php echo $postSeoKeywords; ?>" placeholder="z. B. KI, Strategie, Mittelstand">
                                            <span class="form-hint">Kommagetrennte Begriffe für die SEO-Metadaten.</span>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <label class="form-label" for="metaTitle">Meta-Titel</label>
                                        <input type="text" class="form-control" id="metaTitle" name="meta_title" value="<?php echo htmlspecialchars($postMetaTitleValue); ?>" maxlength="70">
                                        <span class="form-hint"><span id="metaTitleCount">0</span>/70 Zeichen · Die Meta-Beschreibung steht in der rechten Spalte.</span>
                                    </div>
                                    <div class="col-lg-6">
                                        <label class="form-label" for="canonicalUrl">Kanonische URL</label>
                                        <input type="text" class="form-control" id="canonicalUrl" name="canonical_url" value="<?php echo $canonicalUrl; ?>" placeholder="Automatisch self-referencing, wenn leer">
                                    </div>
                                </div>
                                <div id="postSeoOverrideNotice" class="alert alert-info d-none cms-seo-override-notice mt-3" role="status" aria-live="polite">
                                    <div class="cms-seo-override-header">
                                        <div class="fw-semibold cms-seo-override-title">SEO-Default-Hinweis</div>
                                    </div>
                                    <div class="cms-seo-override-actions">
                                        <button type="button" class="btn btn-sm btn-outline-primary d-none" id="postSeoResetMetaTitle">Meta-Titel auf Default zurücksetzen</button>
                                        <button type="button" class="btn btn-sm btn-outline-primary d-none" id="postSeoResetMetaDescription">Meta-Beschreibung auf Default zurücksetzen</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="postSeoResetAllMetaDefaults">Alle lokalen SEO-Felder auf Default zurücksetzen</button>
                                    </div>
                                </div>
                                <div id="postPublishWarning" class="alert <?php echo $isScheduledPost ? 'alert-info' : 'alert-warning'; ?> mt-3 mb-0<?php echo $isScheduledPost ? '' : ' d-none'; ?>" role="status"><?php echo $isScheduledPost ? 'Dieser Beitrag ist geplant und wird automatisch zum gewählten Termin veröffentlicht.' : ''; ?></div>
                            </div>
                        </details>

                        <div class="cms-editor-extras__pair">
                            <?php
                            $readabilityCard = [
                                'badgeId' => 'postReadabilityBadge',
                                'summaryId' => 'postReadabilitySummary',
                                'metrics' => [
                                    ['id' => 'postWordCount', 'label' => 'Wörter'],
                                    ['id' => 'postDensity', 'label' => 'Keyphrase-Dichte'],
                                    ['id' => 'postInternalLinks', 'label' => 'Interne Links'],
                                    ['id' => 'postExternalLinks', 'label' => 'Externe Links'],
                                    ['id' => 'postTransitionWords', 'label' => 'Signalwörter'],
                                    ['id' => 'postMissingAlt', 'label' => 'Bilder ohne Alt'],
                                    ['id' => 'postLongSentences', 'label' => 'Lange Sätze'],
                                    ['id' => 'postLongParagraphs', 'label' => 'Lange Absätze'],
                                ],
                            ];
                            require __DIR__ . '/../partials/content-readability-card.php';

                            $previewCard = [
                                'serpTitleId' => 'postSerpTitle',
                                'serpTitle' => $postMetaTitleValue ?: $activePostTitleValue,
                                'serpUrlId' => 'postSerpUrl',
                                'serpUrl' => $activePostPreviewUrl,
                                'serpDescriptionId' => 'postSerpDescription',
                                'serpDescription' => $postMetaDescriptionValue ?: 'Meta-Beschreibung wird automatisch aus dem ersten Absatz erzeugt.',
                                'socialImageId' => 'postSocialImage',
                                'socialImage' => $ogImage !== '' ? html_entity_decode($ogImage, ENT_QUOTES, 'UTF-8') : $postFeaturedImageValue,
                                'socialImageVisible' => $ogImage !== '' || $postFeaturedImageValue !== '',
                                'socialTitleId' => 'postSocialTitle',
                                'socialTitle' => $ogTitle !== '' ? html_entity_decode($ogTitle, ENT_QUOTES, 'UTF-8') : ($postMetaTitleValue ?: $activePostTitleValue),
                                'socialDescriptionId' => 'postSocialDescription',
                                'socialDescription' => $ogDescription !== '' ? html_entity_decode($ogDescription, ENT_QUOTES, 'UTF-8') : ($postMetaDescriptionValue ?: 'Social-Vorschau aus SEO-Daten'),
                            ];
                            require __DIR__ . '/../partials/content-preview-card.php';
                            ?>
                        </div>

                        <?php
                        $seoScorePanel = [
                            'badgeId' => 'postSeoScoreBadge',
                            'scoreLabelId' => 'postSeoScoreLabel',
                            'scoreBarId' => 'postSeoScoreBar',
                            'rulesId' => 'postSeoRules',
                            'hintBadgeContainerId' => 'postSeoHintBadges',
                            'summaryCards' => [
                                ['width' => 'col-md-2', 'label' => 'Titel', 'valueId' => 'postTitleCount', 'suffix' => 'Zeichen'],
                                ['width' => 'col-md-2', 'label' => 'Slug', 'valueId' => 'postSlugCount', 'suffix' => 'Zeichen'],
                                ['width' => 'col-md-3', 'label' => 'Status', 'badgeId' => 'postStatusBadge', 'badgeText' => 'Entwurf', 'badgeClass' => 'badge bg-yellow-lt text-yellow'],
                                ['width' => 'col-md-5', 'label' => 'Kategorie', 'bodyText' => $selectedCategoryName . ($isEnglishEditorView ? ' · EN-Ansicht' : ' · DE-Ansicht')],
                            ],
                        ];
                        require __DIR__ . '/../partials/content-seo-score-panel.php';

                        $advancedSeoPanel = [
                            'hint' => 'Das Beitragsbild wird in der rechten Spalte gesetzt. Hier kann ein separates OG-Bild für Social Media hinterlegt werden.',
                            'schemaTypeId' => 'schemaType',
                            'schemaTypeName' => 'schema_type',
                            'schemaTypeValue' => \CMS\Services\SEO\SeoSchemaRenderer::selectableTypeFor(html_entity_decode($schemaType, ENT_QUOTES, 'UTF-8'), 'post'),
                            'schemaTypeOptions' => \CMS\Services\SEO\SeoSchemaRenderer::typeOptionsFor('post'),
                            'sitemapPriorityId' => 'sitemapPriority',
                            'sitemapPriorityName' => 'sitemap_priority',
                            'sitemapPriorityValue' => html_entity_decode($sitemapPriority, ENT_QUOTES, 'UTF-8'),
                            'sitemapChangefreqId' => 'sitemapChangefreq',
                            'sitemapChangefreqName' => 'sitemap_changefreq',
                            'sitemapChangefreqValue' => html_entity_decode($sitemapChangefreq, ENT_QUOTES, 'UTF-8'),
                            'sitemapChangefreqOptions' => ['always', 'daily', 'weekly', 'monthly', 'yearly'],
                            'robotsIndexId' => 'postRobotsIndex',
                            'robotsIndexName' => 'robots_index',
                            'robotsIndexChecked' => $robotsIndex,
                            'robotsFollowId' => 'postRobotsFollow',
                            'robotsFollowName' => 'robots_follow',
                            'robotsFollowChecked' => $robotsFollow,
                            'hreflangGroupId' => 'hreflangGroup',
                            'hreflangGroupName' => 'hreflang_group',
                            'hreflangGroupValue' => html_entity_decode($hreflangGroup, ENT_QUOTES, 'UTF-8'),
                            'ogTitleId' => 'ogTitle',
                            'ogTitleValue' => html_entity_decode($ogTitle, ENT_QUOTES, 'UTF-8'),
                            'ogImageId' => 'ogImage',
                            'ogImageValue' => html_entity_decode($ogImage, ENT_QUOTES, 'UTF-8'),
                            'ogDescriptionId' => 'ogDescription',
                            'ogDescriptionValue' => html_entity_decode($ogDescription, ENT_QUOTES, 'UTF-8'),
                            'twitterTitleId' => 'twitterTitle',
                            'twitterTitleValue' => html_entity_decode($twitterTitle, ENT_QUOTES, 'UTF-8'),
                            'twitterCardId' => 'twitterCard',
                            'twitterCardName' => 'twitter_card',
                            'twitterCardValue' => html_entity_decode($twitterCard, ENT_QUOTES, 'UTF-8'),
                            'twitterCardOptions' => ['summary_large_image', 'summary'],
                            'twitterDescriptionId' => 'twitterDescription',
                            'twitterDescriptionValue' => html_entity_decode($twitterDescription, ENT_QUOTES, 'UTF-8'),
                            'twitterImageId' => 'twitterImage',
                            'twitterImageValue' => html_entity_decode($twitterImage, ENT_QUOTES, 'UTF-8'),
                        ];
                        require __DIR__ . '/../partials/content-advanced-seo-panel.php';
                        ?>
                    </div>
                </div>

                <aside class="cms-editor-settings" id="postEditorSettings" aria-label="Beitragseinstellungen">
                    <section class="cms-editor-checklist<?php echo $publishChecklist['passed'] ? ' is-complete' : ''; ?>" id="postPublishChecklist" aria-labelledby="postPublishChecklistTitle">
                        <div class="cms-editor-checklist__head">
                            <h2 class="cms-editor-checklist__title" id="postPublishChecklistTitle">Veröffentlichungs-Checkliste</h2>
                            <span class="cms-editor-checklist__summary" id="postChecklistSummary" role="status" aria-live="polite"><?php echo $publishChecklistPassedCount; ?> von <?php echo count($publishChecklist['items']); ?> erfüllt</span>
                        </div>
                        <ul class="cms-editor-checklist__list">
                            <?php foreach ($publishChecklist['items'] as $checklistItem): ?>
                                <?php $checklistItemPassed = !empty($checklistItem['passed']); ?>
                                <li class="cms-editor-checklist__item <?php echo $checklistItemPassed ? 'is-passed' : 'is-missing'; ?>" data-checklist-item="<?php echo htmlspecialchars((string) $checklistItem['id'], ENT_QUOTES); ?>" data-checklist-field="<?php echo htmlspecialchars((string) $checklistItem['field'], ENT_QUOTES); ?>">
                                    <i class="ti <?php echo $checklistItemPassed ? 'ti-circle-check' : 'ti-circle-x'; ?> cms-editor-checklist__icon" aria-hidden="true" data-checklist-icon></i>
                                    <div class="cms-editor-checklist__body">
                                        <span class="cms-editor-checklist__label"><?php echo htmlspecialchars((string) $checklistItem['label']); ?><span class="visually-hidden" data-checklist-state><?php echo $checklistItemPassed ? ' – erfüllt' : ' – fehlt'; ?></span></span>
                                        <span class="cms-editor-checklist__hint" data-checklist-hint><?php echo htmlspecialchars((string) $checklistItem['hint']); ?></span>
                                    </div>
                                    <button type="button" class="cms-editor-checklist__jump" data-jump-field="<?php echo htmlspecialchars((string) $checklistItem['field'], ENT_QUOTES); ?>" aria-label="Zum Feld <?php echo htmlspecialchars((string) $checklistItem['label'], ENT_QUOTES); ?>" title="Zum Feld <?php echo htmlspecialchars((string) $checklistItem['label'], ENT_QUOTES); ?>"<?php echo $checklistItemPassed ? ' hidden' : ''; ?>>
                                        <i class="ti ti-arrow-right" aria-hidden="true"></i>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="cms-editor-checklist__note">Pflicht für „Veröffentlichen“ und „Planen“.</p>
                    </section>

                    <details class="cms-editor-panel" id="postPanelPublication" open>
                        <summary class="cms-editor-panel__summary">
                            <h3 class="cms-editor-panel__title"><i class="ti ti-calendar-event" aria-hidden="true"></i>Veröffentlichung</h3>
                            <span class="cms-editor-panel__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="cms-editor-panel__body">
                            <div class="mb-3">
                                <label class="form-label" for="status">Status</label>
                                <select class="form-select" id="status" name="status" aria-describedby="statusHint">
                                    <option value="draft" <?php if ($postStatusValue === 'draft') echo 'selected'; ?>>Entwurf</option>
                                    <option value="published" <?php if ($postStatusValue === 'published') echo 'selected'; ?>>Veröffentlicht</option>
                                    <option value="private" <?php if ($postStatusValue === 'private') echo 'selected'; ?>>Privat (nur Mitglieder)</option>
                                </select>
                                <div class="form-hint" id="statusHint">„Veröffentlicht“ mit Datum in der Zukunft plant den Beitrag. „Privat“ ist nur für eingeloggte Mitglieder sichtbar.</div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label" for="publishDate">Datum</label>
                                    <input type="date" class="form-control" id="publishDate" name="publish_date" value="<?php echo htmlspecialchars($publishDate); ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="publishTime">Uhrzeit</label>
                                    <input type="time" class="form-control" id="publishTime" name="publish_time" value="<?php echo htmlspecialchars($publishTime); ?>" step="60">
                                </div>
                            </div>
                            <div class="form-hint mb-3">Für „Planen“ muss der Zeitpunkt in der Zukunft liegen.</div>
                            <fieldset class="cms-editor-panel__group">
                                <legend class="form-label">Aktualisierungsdatum (öffentlich sichtbar)</legend>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input type="date" class="form-control" id="contentUpdatedDate" name="content_updated_date" aria-label="Aktualisierungsdatum (öffentlich sichtbar)" value="<?php echo htmlspecialchars($contentUpdatedDate); ?>">
                                    </div>
                                    <div class="col-6">
                                        <input type="time" class="form-control" id="contentUpdatedTime" aria-label="Aktualisierungsuhrzeit (öffentlich sichtbar)" name="content_updated_time" value="<?php echo htmlspecialchars($contentUpdatedTime); ?>" step="60">
                                    </div>
                                </div>
                                <div class="form-hint">Optional. Leer lassen = kein „Aktualisiert am“-Hinweis und kein Update-Badge.</div>
                                <label class="form-check mt-2 mb-0">
                                    <input type="checkbox" class="form-check-input" id="treatUpdateAsNew" name="treat_update_as_new" value="1" aria-describedby="treatUpdateAsNewHint"<?php echo $treatUpdateAsNewChecked ? ' checked' : ''; ?>>
                                    <span class="form-check-label">Als neuen Beitrag behandeln</span>
                                </label>
                                <div class="form-hint" id="treatUpdateAsNewHint">Setzt beim Speichern das Veröffentlichungsdatum auf das Aktualisierungsdatum (Beitrag rückt an die Spitze). Wird nicht gespeichert und gilt nur für diesen Speichervorgang.</div>
                            </fieldset>
                        </div>
                    </details>

                    <details class="cms-editor-panel" id="postPanelCategory" open>
                        <summary class="cms-editor-panel__summary">
                            <h3 class="cms-editor-panel__title"><i class="ti ti-folder" aria-hidden="true"></i>Kategorie</h3>
                            <span class="cms-editor-panel__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="cms-editor-panel__body">
                            <div class="mb-3">
                                <label class="form-label" for="categoryId">Hauptkategorie</label>
                                <select class="form-select" id="categoryId" name="category_id" aria-describedby="categoryIdHint">
                                    <option value="0" data-category-name="">Keine Kategorie</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['id']; ?>" data-category-name="<?php echo htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES); ?>" <?php if ($categoryId === (int)$cat['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars((string) ($cat['option_label'] ?? $cat['name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint" id="categoryIdHint">Führend für Listen, Archive und Vorschau.</div>
                            </div>
                            <div>
                                <label class="form-label" for="additionalCategoryIds">Weitere Kategorien</label>
                                <select class="form-select" id="additionalCategoryIds" name="additional_category_ids[]" multiple size="5" aria-describedby="additionalCategoryIdsHint">
                                    <?php foreach ($categories as $cat): ?>
                                        <?php $catId = (int) ($cat['id'] ?? 0); ?>
                                        <?php if ($catId <= 0) { continue; } ?>
                                        <option value="<?php echo $catId; ?>" data-category-name="<?php echo htmlspecialchars((string) ($cat['name'] ?? ''), ENT_QUOTES); ?>" <?php echo in_array($catId, $additionalCategoryIds, true) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) ($cat['option_label'] ?? $cat['name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint" id="additionalCategoryIdsHint">Optional für Archive und Routing; Mehrfachauswahl mit Strg/⌘.</div>
                            </div>
                        </div>
                    </details>

                    <details class="cms-editor-panel" id="postPanelTags" open>
                        <summary class="cms-editor-panel__summary">
                            <h3 class="cms-editor-panel__title"><i class="ti ti-tags" aria-hidden="true"></i>Tags</h3>
                            <span class="cms-editor-panel__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="cms-editor-panel__body">
                            <label class="form-label" for="postTags">Tags</label>
                            <input type="text" class="form-control" id="postTags" name="tags" value="<?php echo $tagString; ?>" list="postTagsSuggestions" placeholder="z. B. Microsoft 365, PowerShell" aria-describedby="postTagsHint">
                            <div class="form-hint" id="postTagsHint">Mehrere Tags mit Komma trennen.</div>
                            <?php if (!empty($availableTags)): ?>
                                <datalist id="postTagsSuggestions">
                                    <?php foreach ($availableTags as $tag): ?>
                                        <option value="<?php echo htmlspecialchars((string)($tag['name'] ?? ''), ENT_QUOTES); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            <?php endif; ?>
                        </div>
                    </details>

                    <details class="cms-editor-panel" id="postPanelFeatured" open>
                        <summary class="cms-editor-panel__summary">
                            <h3 class="cms-editor-panel__title"><i class="ti ti-photo" aria-hidden="true"></i>Beitragsbild</h3>
                            <span class="cms-editor-panel__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="cms-editor-panel__body cms-editor-featured">
                            <div id="featuredPreview" class="cms-editor-featured__preview<?php echo $postFeaturedImageValue !== '' ? '' : ' d-none'; ?>">
                                <?php if ($postFeaturedImageValue !== ''): ?>
                                    <img src="<?php echo htmlspecialchars(\CMS\Services\MediaDeliveryService::getInstance()->normalizeUrl($postFeaturedImageValue, true), ENT_QUOTES); ?>" class="rounded" id="featuredImg" alt="Aktuelles Beitragsbild">
                                <?php endif; ?>
                            </div>
                            <div id="featuredEmpty" class="cms-editor-featured__empty<?php echo $postFeaturedImageValue !== '' ? ' d-none' : ''; ?>">
                                <i class="ti ti-photo" aria-hidden="true"></i>
                                <span>Noch kein Beitragsbild ausgewählt.</span>
                            </div>
                            <input type="hidden" name="featured_image" id="featuredInput" value="<?php echo htmlspecialchars($postFeaturedImageValue); ?>">
                            <input type="hidden" name="featured_image_temp_path" id="featuredInput_temp_path" value="">
                            <div class="cms-editor-featured__actions">
                                <button type="button" class="btn btn-outline-primary" id="btnSelectImage">
                                    <i class="ti ti-photo-plus" aria-hidden="true"></i>
                                    Bild auswählen
                                </button>
                                <button type="button" class="btn btn-outline-secondary <?php echo $postFeaturedImageValue !== '' ? '' : 'd-none'; ?>" id="btnRemoveImage">Entfernen</button>
                            </div>
                        </div>
                    </details>

                    <details class="cms-editor-panel" id="postPanelSeoDescription" open>
                        <summary class="cms-editor-panel__summary">
                            <h3 class="cms-editor-panel__title"><i class="ti ti-file-description" aria-hidden="true"></i>SEO-Beschreibung</h3>
                            <span class="cms-editor-panel__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="cms-editor-panel__body">
                            <label class="form-label" for="metaDesc">Meta-Beschreibung</label>
                            <textarea class="form-control" id="metaDesc" name="meta_description" rows="5" maxlength="160" aria-describedby="metaDescCounter metaDescHint"><?php echo htmlspecialchars($postMetaDescriptionValue); ?></textarea>
                            <div class="cms-editor-counter <?php echo $metaDescriptionState; ?>" id="metaDescCounter">
                                <span><span id="metaDescCount"><?php echo $metaDescriptionLength; ?></span> / 160 Zeichen</span>
                                <span class="cms-editor-counter__target">Ziel: 120–160</span>
                            </div>
                            <div class="form-hint" id="metaDescHint">Erscheint in Suchergebnissen unter dem Titel.</div>
                        </div>
                    </details>

                    <details class="cms-editor-panel" id="postPanelPermalink" open>
                        <summary class="cms-editor-panel__summary">
                            <h3 class="cms-editor-panel__title"><i class="ti ti-link" aria-hidden="true"></i>Permalink</h3>
                            <span class="cms-editor-panel__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="cms-editor-panel__body">
                            <label class="form-label" for="<?php echo htmlspecialchars($activeSlugInputId); ?>"><?php echo $isEnglishEditorView ? 'Englischer Slug' : ($isEnglishOnlyPost ? 'Standard-Slug' : 'Slug'); ?></label>
                            <input type="text" class="form-control" id="<?php echo htmlspecialchars($activeSlugInputId); ?>" name="<?php echo $isEnglishEditorView ? 'slug_en' : 'slug'; ?>" value="<?php echo htmlspecialchars($activePostSlugValue); ?>" placeholder="<?php echo htmlspecialchars($isEnglishEditorView ? 'optional: english-url-slug' : 'wird automatisch generiert'); ?>" aria-describedby="postSlugHint">
                            <div class="form-hint" id="postSlugHint">Aktive Struktur: <code><?php echo htmlspecialchars($postPermalinkHint); ?></code></div>
                            <div class="cms-editor-permalink">
                                <div class="cms-editor-permalink__head">
                                    <span>Vorschau-URL</span>
                                    <span class="badge <?php echo $isScheduledPost ? 'bg-azure-lt text-azure' : 'bg-success-lt text-success'; ?>" id="postSlugState"><?php echo $isScheduledPost ? 'Geplant' : 'Slug gültig'; ?></span>
                                </div>
                                <div class="cms-editor-permalink__url" id="postPreviewUrl"><?php echo htmlspecialchars($activePostPreviewUrl); ?></div>
                            </div>
                        </div>
                    </details>

                    <details class="cms-editor-panel" id="postPanelAuthor">
                        <summary class="cms-editor-panel__summary">
                            <h3 class="cms-editor-panel__title"><i class="ti ti-user-edit" aria-hidden="true"></i>Autor</h3>
                            <span class="cms-editor-panel__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="cms-editor-panel__body">
                            <div class="mb-3">
                                <label class="form-label" for="authorDisplayName">Anzeigename im Artikel</label>
                                <input type="text" class="form-control" id="authorDisplayName" name="author_display_name" value="<?php echo $authorDisplayName; ?>" maxlength="150" placeholder="Leer = Anzeigename des Autors" aria-describedby="authorDisplayNameHint">
                                <div class="form-hint" id="authorDisplayNameHint">Optionaler Override nur für diesen Beitrag.</div>
                            </div>
                            <div>
                                <label class="form-label" for="authorDisplayUrl">Website des alternativen Autors</label>
                                <input type="url" class="form-control" id="authorDisplayUrl" name="author_display_url" value="<?php echo $authorDisplayUrl; ?>" maxlength="500" placeholder="https://beispiel.de (optional)" aria-describedby="authorDisplayUrlHint">
                                <div class="form-hint" id="authorDisplayUrlHint">Verlinkt den Autorennamen auf diese Website statt auf die interne Autoren-Seite. Muss mit http:// oder https:// beginnen.</div>
                            </div>
                        </div>
                    </details>

                    <?php if (!$isNew): ?>
                        <details class="cms-editor-panel cms-editor-panel--revisions" id="postRevisionPanel">
                            <summary class="cms-editor-panel__summary">
                                <h3 class="cms-editor-panel__title"><i class="ti ti-history" aria-hidden="true"></i>Revisionen</h3>
                                <span class="cms-editor-panel__badge" id="postRevisionCount"><?php echo count($postRevisions); ?></span>
                                <span class="cms-editor-panel__chevron" aria-hidden="true"></span>
                            </summary>
                            <div class="cms-editor-panel__body">
                                <p class="cms-editor-panel__hint">Bei jedem Speichern und automatisch höchstens alle 10 Minuten; die letzten 30 Versionen bleiben erhalten.</p>
                                <ol class="cms-revision-list" id="postRevisionList" aria-label="Gespeicherte Revisionen"<?php echo $postRevisions === [] ? ' hidden' : ''; ?>>
                                    <?php foreach ($postRevisions as $revision): ?>
                                        <?php
                                        $revisionId = (int) ($revision['id'] ?? 0);
                                        $revisionDateLabel = (string) ($revision['created_at_label'] ?? 'Unbekanntes Datum');
                                        ?>
                                        <li class="cms-revision-list__item" data-revision-id="<?php echo $revisionId; ?>">
                                            <div class="cms-revision-list__meta">
                                                <time class="cms-revision-list__time" datetime="<?php echo htmlspecialchars((string) ($revision['created_at'] ?? ''), ENT_QUOTES); ?>"><?php echo htmlspecialchars($revisionDateLabel); ?></time>
                                                <span class="cms-revision-list__author"><?php echo htmlspecialchars((string) ($revision['author_label'] ?? 'Unbekannt')); ?> · <?php echo htmlspecialchars((string) ($revision['type_label'] ?? '')); ?></span>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-secondary cms-revision-list__compare" data-revision-compare="<?php echo $revisionId; ?>" aria-label="Revision vom <?php echo htmlspecialchars($revisionDateLabel, ENT_QUOTES); ?> vergleichen">Vergleichen</button>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                                <p class="cms-editor-panel__empty" id="postRevisionEmpty"<?php echo $postRevisions !== [] ? ' hidden' : ''; ?>>Noch keine Revisionen vorhanden. Beim nächsten Speichern wird automatisch eine angelegt.</p>
                            </div>
                        </details>

                        <div class="cms-editor-settings__danger">
                            <button
                                type="submit"
                                name="_action"
                                value="delete"
                                class="btn btn-outline-danger w-100"
                                form="postForm"
                                formnovalidate
                                data-confirm="Der Beitrag wird dauerhaft gelöscht. Wirklich fortfahren?"
                            >
                                <i class="ti ti-trash" aria-hidden="true"></i>
                                Beitrag löschen
                            </button>
                        </div>
                    <?php endif; ?>
                </aside>
            </div>
        </form>

        <dialog class="cms-editor-dialog" id="postChecklistDialog" aria-labelledby="postChecklistDialogTitle" aria-describedby="postChecklistDialogText">
            <div class="cms-editor-dialog__header">
                <h2 class="cms-editor-dialog__title" id="postChecklistDialogTitle">Noch nicht bereit</h2>
                <button type="button" class="btn btn-ghost-secondary btn-icon cms-editor-dialog__close" data-dialog-close aria-label="Dialog schließen">
                    <i class="ti ti-x" aria-hidden="true"></i>
                </button>
            </div>
            <div class="cms-editor-dialog__body">
                <p id="postChecklistDialogText">Bitte zuerst diese Punkte ergänzen:</p>
                <ul class="cms-editor-dialog__list" id="postChecklistDialogList"></ul>
            </div>
            <div class="cms-editor-dialog__footer">
                <button type="button" class="btn btn-outline-secondary" data-dialog-close>Schließen</button>
                <button type="button" class="btn btn-primary" data-dialog-jump autofocus>Zum ersten fehlenden Feld</button>
            </div>
        </dialog>

        <?php if (!$isNew): ?>
            <dialog class="cms-editor-dialog cms-editor-dialog--wide" id="postRevisionDialog" aria-labelledby="postRevisionDialogTitle" aria-describedby="postRevisionDialogMeta">
                <div class="cms-editor-dialog__header">
                    <div>
                        <h2 class="cms-editor-dialog__title" id="postRevisionDialogTitle">Revision vergleichen</h2>
                        <p class="cms-editor-dialog__subtitle" id="postRevisionDialogMeta"></p>
                    </div>
                    <button type="button" class="btn btn-ghost-secondary btn-icon cms-editor-dialog__close" data-dialog-close aria-label="Vergleich schließen">
                        <i class="ti ti-x" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="cms-editor-dialog__body cms-revision-compare" id="postRevisionDialogBody" tabindex="-1" aria-busy="false"></div>
                <div class="cms-editor-dialog__status" id="postRevisionDialogStatus" role="status" aria-live="polite"></div>
                <div class="cms-editor-dialog__footer">
                    <div class="cms-revision-restore-confirm" id="postRevisionRestoreConfirm" hidden>
                        <p class="cms-revision-restore-confirm__text" id="postRevisionRestoreConfirmText">Der aktuelle Stand wird vorher als Revision gesichert. Status, Slug und Veröffentlichungsdatum bleiben unverändert.</p>
                        <p class="cms-revision-restore-confirm__warning" id="postRevisionRestoreDirtyWarning" hidden>Ungespeicherte Änderungen im Editor gehen dabei verloren.</p>
                        <div class="btn-list">
                            <button type="button" class="btn btn-outline-secondary" id="postRevisionRestoreCancel">Abbrechen</button>
                            <button type="button" class="btn btn-primary" id="postRevisionRestoreConfirmButton">Ja, wiederherstellen</button>
                        </div>
                    </div>
                    <div class="btn-list" id="postRevisionDialogActions">
                        <button type="button" class="btn btn-outline-secondary" data-dialog-close>Schließen</button>
                        <button type="button" class="btn btn-primary" id="postRevisionRestoreButton">
                            <i class="ti ti-restore" aria-hidden="true"></i>
                            Diese Version wiederherstellen
                        </button>
                    </div>
                </div>
            </dialog>
        <?php endif; ?>

        <?php
        $pickerModalId = 'postFeaturedImageModal';
        $pickerOpenButtonId = 'btnSelectImage';
        $pickerInputId = 'featuredInput';
        $pickerPreviewContainerId = 'featuredPreview';
        $pickerRemoveButtonId = 'btnRemoveImage';
        $pickerEmptyStateId = 'featuredEmpty';
        $pickerTitleInputId = 'title';
        $pickerSlugInputId = 'slug';
        $pickerDialogTitle = 'Beitragsbild auswählen';
        $pickerIsNew = $isNew;
        $pickerContentType = 'post';
        require __DIR__ . '/../partials/featured-image-picker.php';

        $postContentUiConfig = [
            'formId' => 'postForm',
            'titleSelector' => '#postEditorSaveState',
            'backLinkId' => 'postBackToList',
            'removeButtonId' => 'btnRemoveImage',
            'imageInputId' => 'featuredInput',
            'tempPathInputId' => 'featuredInput_temp_path',
            'previewContainerId' => 'featuredPreview',
            'emptyStateId' => 'featuredEmpty',
            'currentTimestamp' => (int) round(microtime(true) * 1000),
            'slugInputId' => $activeSlugInputId,
            'previewUrlId' => 'postPreviewUrl',
            'previewBaseUrl' => '/blog/',
            'previewUrlTemplate' => $activePostPreviewUrlTemplate,
            'previewPlaceholderSlug' => $activePostPreviewSlugFallback,
            'statusSelectId' => 'status',
            'publishDateId' => 'publishDate',
            'publishTimeId' => 'publishTime',
            'publishWarningId' => 'postPublishWarning',
            'statusBadgeId' => 'postStatusBadge',
            'categorySelectId' => 'categoryId',
            'templateSelectId' => 'postTemplate',
            'templateMetaPanelSelector' => '[data-post-template-meta-panel]',
            'templateMetaEmptySelector' => '[data-post-template-meta-empty]',
            'statusMap' => [
                'draft' => ['label' => 'Entwurf', 'className' => 'badge bg-yellow-lt text-yellow'],
                'published' => ['label' => 'Veröffentlicht', 'className' => 'badge bg-green-lt text-green'],
                'private' => ['label' => 'Privat', 'className' => 'badge bg-purple-lt text-purple'],
                'scheduled' => ['label' => 'Geplant', 'className' => 'badge bg-azure-lt text-azure'],
            ],
            'countBindings' => [
                ['sourceId' => $activeTitleInputId, 'targetId' => 'postTitleCount'],
                ['sourceId' => $activeSlugInputId, 'targetId' => 'postSlugCount'],
                ['sourceId' => $activeExcerptInputId, 'targetId' => 'excerptCount'],
                ['sourceId' => 'metaTitle', 'targetId' => 'metaTitleCount'],
            ],
        ];

        $postContentSeoConfig = [
            'formId' => 'postForm',
            'titleId' => $activeTitleInputId,
            'slugId' => $activeSlugInputId,
            'excerptId' => $activeExcerptInputId,
            'metaTitleId' => 'metaTitle',
            'metaDescId' => 'metaDesc',
            'focusKeyphraseId' => 'focusKeyphrase',
            'ogTitleId' => 'ogTitle',
            'ogDescriptionId' => 'ogDescription',
            'ogImageId' => 'ogImage',
            'twitterTitleId' => 'twitterTitle',
            'twitterDescriptionId' => 'twitterDescription',
            'twitterImageId' => 'twitterImage',
            'featuredImageId' => 'featuredInput',
            'statusId' => 'status',
            'contentInputId' => $activeContentInputId,
            'editorContainerId' => $activeEditorHolderId,
            'serpTitleId' => 'postSerpTitle',
            'serpUrlId' => 'postSerpUrl',
            'serpDescriptionId' => 'postSerpDescription',
            'scoreBarId' => 'postSeoScoreBar',
            'scoreLabelId' => 'postSeoScoreLabel',
            'scoreBadgeId' => 'postSeoScoreBadge',
            'scoreRulesId' => 'postSeoRules',
            'socialTitleId' => 'postSocialTitle',
            'socialDescriptionId' => 'postSocialDescription',
            'socialImageId' => 'postSocialImage',
            'publishWarningId' => 'postPublishWarning',
            'slugStateId' => 'postSlugState',
            'wordCountId' => 'postWordCount',
            'densityId' => 'postDensity',
            'internalLinksId' => 'postInternalLinks',
            'externalLinksId' => 'postExternalLinks',
            'transitionWordsId' => 'postTransitionWords',
            'longSentencesId' => 'postLongSentences',
            'longParagraphsId' => 'postLongParagraphs',
            'missingAltId' => 'postMissingAlt',
            'readabilityBadgeId' => 'postReadabilityBadge',
            'readabilitySummaryId' => 'postReadabilitySummary',
            'hintBadgeContainerId' => 'postSeoHintBadges',
            'overrideNoticeId' => 'postSeoOverrideNotice',
            'overrideSummaryId' => 'postSeoOverrideSummary',
            'overrideListId' => 'postSeoOverrideList',
            'resetMetaTitleId' => 'postSeoResetMetaTitle',
            'resetMetaDescriptionId' => 'postSeoResetMetaDescription',
            'resetAllMetaDefaultsId' => 'postSeoResetAllMetaDefaults',
            'titleCreatesH1' => true,
            'previewBaseUrl' => '/blog/',
            'previewUrlTemplate' => $activePostPreviewUrlTemplate,
            'previewPlaceholderSlug' => $activePostPreviewSlugFallback,
            'siteName' => (string)SITE_NAME,
            'siteTitleFormat' => (string)($seoTemplateSettings['site_title_format'] ?? '%%title%% %%sep%% %%sitename%%'),
            'titleSeparator' => (string)($seoTemplateSettings['title_separator'] ?? '|'),
            'minWords' => (int)($seoTemplateSettings['analysis_min_words'] ?? 300),
            'maxSentenceWords' => (int)($seoTemplateSettings['analysis_sentence_words'] ?? 24),
            'maxParagraphWords' => (int)($seoTemplateSettings['analysis_paragraph_words'] ?? 120),
            'fallbackImage' => $postFeaturedImageValue,
        ];

        $postContentEditorJsConfig = [
            'formId' => 'postForm',
            'aiSeoMetadata' => $aiSeoMetadataEnabled ? [
                'buttonId' => 'generatePostSeoMetadataButton',
                'endpointUrl' => (string) ($aiSeoMetadataUrl ?? '/admin/ai-generate-seo-metadata'),
                'csrfToken' => (string) ($aiSeoMetadataToken ?? ''),
                'contentType' => 'post',
                'locale' => $editorLocale,
                'sourceEditorKey' => $isEnglishEditorView ? 'en' : 'de',
                'requestTimeoutMs' => 300000,
                'excerptId' => $activeExcerptInputId,
                'focusKeyphraseId' => 'focusKeyphrase',
                'keywordsId' => 'postSeoKeywords',
                'metaTitleId' => 'metaTitle',
                'metaDescriptionId' => 'metaDesc',
                'ogTitleId' => 'ogTitle',
                'ogDescriptionId' => 'ogDescription',
                'twitterTitleId' => 'twitterTitle',
                'twitterDescriptionId' => 'twitterDescription',
                'twitterCardId' => 'twitterCard',
                'schemaTypeId' => 'schemaType',
                'sitemapPriorityId' => 'sitemapPriority',
                'sitemapChangefreqId' => 'sitemapChangefreq',
                'robotsIndexId' => 'postRobotsIndex',
                'robotsFollowId' => 'postRobotsFollow',
            ] : null,
            'aiTranslation' => ($aiTranslationEnabled && $isEnglishEditorView) ? [
                'buttonId' => 'translatePostDeToEnButton',
                'endpointUrl' => (string) ($aiTranslationUrl ?? '/admin/ai-translate-editorjs'),
                'csrfToken' => (string) ($aiTranslationToken ?? ''),
                'contentType' => 'post',
                'sourceLocale' => 'de',
                'targetLocale' => 'en',
                'sourceEditorKey' => 'de',
                'targetEditorKey' => 'en',
                'sourceTitleId' => 'title',
                'targetTitleId' => 'titleEn',
                'sourceSlugId' => 'slug',
                'targetSlugId' => 'slugEn',
                'sourceExcerptId' => 'excerpt',
                'targetExcerptId' => 'excerptEn',
            ] : null,
            'editors' => [],
        ];

        $postContentEditorJsConfig['mediaUploadUrl'] = '/api/media';
        $postContentEditorJsConfig['csrfToken'] = $editorMediaToken ?? '';
        $postContentEditorJsConfig['themeTypography'] = $cmsEditorThemeTypographyConfig();
        $postContentEditorJsConfig['uploadContext'] = [
            'contentType' => 'post',
            'isNew' => $isNew,
            'draftKey' => $postEditorDraftKey,
            'slugInputId' => 'slug',
            'slugFallbackInputId' => 'slugEn',
            'titleInputId' => 'title',
            'titleFallbackInputId' => 'titleEn',
        ];
        $postContentEditorJsConfig['editors'] = $isEnglishEditorView
            ? [
                ['key' => 'de', 'holderId' => 'postHiddenEditorDe', 'inputId' => 'contentInput', 'lazy' => true],
                ['key' => 'en', 'holderId' => 'editorjsEn', 'inputId' => 'contentInputEn', 'lazy' => false, 'plainTextareaId' => 'postPlainEditorEn', 'plainWrapperId' => 'postPlainEditorWrapEn'],
            ]
            : [
                ['key' => 'de', 'holderId' => 'editorjs', 'inputId' => 'contentInput', 'lazy' => false, 'plainTextareaId' => 'postPlainEditorDe', 'plainWrapperId' => 'postPlainEditorWrapDe'],
                ['key' => 'en', 'holderId' => 'postHiddenEditorEn', 'inputId' => 'contentInputEn', 'lazy' => true],
            ];
        ?>

        <input type="hidden" id="contentEditorUiConfig" value="<?php echo htmlspecialchars((string) json_encode($postContentUiConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>">
        <input type="hidden" id="contentEditorSeoConfig" value="<?php echo htmlspecialchars((string) json_encode($postContentSeoConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>">
        <input type="hidden" id="contentEditorEditorJsConfig" value="<?php echo htmlspecialchars((string) json_encode($postContentEditorJsConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>">
    </div>
</div>
