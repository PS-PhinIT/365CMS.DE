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
 * Erwartet: $data (aus PostsModule::getEditData())
 *           $csrfToken
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
$revisionHistory = is_array($data['revisionHistory'] ?? null) ? $data['revisionHistory'] : ['total' => 0, 'displayed' => 0, 'has_more' => false, 'items' => []];
$postRevisionItems = is_array($revisionHistory['items'] ?? null) ? $revisionHistory['items'] : [];
$postRevisionContentMeta = static function (array $summary): string {
    $parts = [];
    $parts[] = ((int) ($summary['length'] ?? 0)) . ' Zeichen';

    if (isset($summary['json_blocks']) && $summary['json_blocks'] !== null) {
        $parts[] = ((int) $summary['json_blocks']) . ' Block/Blöcke';
    }

    if (trim((string) ($summary['first_block_type'] ?? '')) !== '') {
        $parts[] = 'Startblock: ' . (string) $summary['first_block_type'];
    }

    return implode(' · ', $parts);
};
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
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row align-items-center">
            <div class="col-auto">
                <a href="/admin/posts" class="btn btn-ghost-secondary btn-sm me-2" id="postBackToList">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 6l-6 6l6 6"/></svg>
                    Zurück
                </a>
            </div>
            <div class="col">
                <div class="page-pretitle">Beiträge</div>
                <h2 class="page-title"><?php echo $isNew ? 'Neuer Beitrag' : 'Beitrag bearbeiten'; ?></h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-3'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <form method="post" action="/admin/posts" id="postForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="editor_locale" value="<?php echo htmlspecialchars($editorLocale); ?>">
            <?php if (!$isNew): ?>
                <input type="hidden" name="id" value="<?php echo (int)$post['id']; ?>">
            <?php endif; ?>

            <?php
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
            $postEditorHasValidActiveMapping = ($isEnglishEditorView && $activeContentInputId === 'postPlainEditorEn')
                || (!$isEnglishEditorView && $activeContentInputId === 'postPlainEditorDe');
            $postPermalinkHint = $permalinkService !== null
                ? $permalinkService->getPostPermalinkStructure()
                : '/blog/%postname%';
            $selectedCategoryName = 'Keine Kategorie';
            foreach ($categories as $cat) {
                if ($categoryId === (int)($cat['id'] ?? 0)) {
                    $selectedCategoryName = (string)($cat['name'] ?? 'Keine Kategorie');
                    break;
                }
            }
            $postEditorDraftKey = $isNew
                ? substr(hash('sha256', 'post:' . ($postTitleValue !== '' ? $postTitleValue : microtime((bool) true)) . ':' . session_id()), 0, 12)
                : '';
            ?>

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

            <div class="row g-3 cms-content-editor-layout cms-content-editor-layout--post <?php echo $isNew ? 'cms-content-editor-layout--post-new' : 'cms-content-editor-layout--post-existing'; ?>">
                <div class="col-lg-4 d-flex cms-editor-sidebar-slot">
                    <div class="card cms-edit-card cms-edit-top-card h-100 w-100">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label <?php echo (!$isEnglishEditorView && !$isEnglishOnlyPost) ? 'required' : ''; ?>" for="<?php echo htmlspecialchars($activeTitleInputId); ?>"><?php echo $isEnglishEditorView ? 'Englischer Titel' : ($isEnglishOnlyPost ? 'Deutscher Titel' : 'Titel'); ?></label>
                                <input type="text" class="form-control" id="<?php echo htmlspecialchars($activeTitleInputId); ?>" name="<?php echo $isEnglishEditorView ? 'title_en' : 'title'; ?>" value="<?php echo htmlspecialchars($activePostTitleValue); ?>" <?php echo (!$isEnglishEditorView && !$isEnglishOnlyPost) ? 'required' : ''; ?> placeholder="<?php echo htmlspecialchars($isEnglishEditorView ? 'English article title' : ($isEnglishOnlyPost ? 'Optional, falls du zusätzlich eine deutsche Variante pflegen möchtest' : 'Beitragstitel')); ?>">
                                <?php if ($isEnglishOnlyPost && !$isEnglishEditorView): ?>
                                    <div class="form-hint">Dieser Beitrag enthält aktuell nur englische Inhalte. Die Bearbeitung startet deshalb standardmäßig in der EN-Ansicht; deutsche Felder bleiben optional.</div>
                                <?php elseif ($isEnglishEditorView): ?>
                                    <div class="form-hint">Dies ist die eigenständige EN-Bearbeitung. Die deutsche Variante bleibt separat erhalten und wird beim Speichern nicht überschrieben.</div>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="<?php echo htmlspecialchars($activeSlugInputId); ?>"><?php echo $isEnglishEditorView ? 'Englischer Slug' : ($isEnglishOnlyPost ? 'Standard-Slug' : 'Slug'); ?></label>
                                <input type="text" class="form-control" id="<?php echo htmlspecialchars($activeSlugInputId); ?>" name="<?php echo $isEnglishEditorView ? 'slug_en' : 'slug'; ?>" value="<?php echo htmlspecialchars($activePostSlugValue); ?>" placeholder="<?php echo htmlspecialchars($isEnglishEditorView ? 'optional: english-url-slug' : 'wird automatisch generiert'); ?>">
                                <div class="form-hint">Aktive Struktur: <code><?php echo htmlspecialchars($postPermalinkHint); ?></code></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="categoryId">Kategorie</label>
                                <select class="form-select" id="categoryId" name="category_id">
                                    <option value="0">Keine Kategorie</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['id']; ?>" <?php if ($categoryId === (int)$cat['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars((string) ($cat['option_label'] ?? $cat['name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">Primäre Kategorie für Listen, Archive und Vorschau.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="additionalCategoryIds">Weitere Kategorien</label>
                                <select class="form-select" id="additionalCategoryIds" name="additional_category_ids[]" multiple size="6">
                                    <?php foreach ($categories as $cat): ?>
                                        <?php $catId = (int) ($cat['id'] ?? 0); ?>
                                        <?php if ($catId <= 0) { continue; } ?>
                                        <option value="<?php echo $catId; ?>" <?php echo in_array($catId, $additionalCategoryIds, true) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) ($cat['option_label'] ?? $cat['name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">Optional zusätzliche Kategorien für Archive, Taxonomie-Zuordnungen und Routing. Die Primärkategorie oben bleibt führend für Listen und Standard-Vorschau.</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="postTags">Tags</label>
                                <input type="text" class="form-control" id="postTags" name="tags" value="<?php echo $tagString; ?>" list="postTagsSuggestions" placeholder="z. B. Microsoft 365, PowerShell, Security">
                                <div class="form-hint">Mehrere Tags mit Komma trennen.</div>
                                <?php if (!empty($availableTags)): ?>
                                <datalist id="postTagsSuggestions">
                                    <?php foreach ($availableTags as $tag): ?>
                                    <option value="<?php echo htmlspecialchars((string)($tag['name'] ?? ''), ENT_QUOTES); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 cms-editor-sidebar-slot">
                    <div class="row g-3 h-100">
                        <div class="col-12 d-flex">
                            <div class="card cms-edit-card cms-edit-top-card w-100">
                                <div class="card-header">
                                    <h3 class="card-title">Aktionen</h3>
                                </div>
                                <div class="card-body d-flex flex-column gap-2">
                                    <button type="submit" name="_action" value="save" class="btn btn-primary w-100">
                                        <?php echo $isNew ? 'Erstellen' : 'Aktualisieren'; ?>
                                    </button>
                                    <?php if (!$isNew): ?>
                                    <div class="d-flex gap-2">
                                        <a href="<?php echo $postPreviewUrl; ?>" class="btn btn-outline-secondary w-100" target="_blank" rel="noopener noreferrer" title="Vorschau Deutsch">
                                            Vorschau DE
                                        </a>
                                        <a href="<?php echo htmlspecialchars($postPreviewUrlEn); ?>" class="btn btn-outline-secondary w-100" target="_blank" rel="noopener noreferrer" title="English public preview">
                                            Vorschau EN
                                        </a>
                                    </div>
                                    <div class="border-top pt-2 mt-1">
                                        <button
                                            type="submit"
                                            name="_action"
                                            value="delete"
                                            class="btn btn-outline-danger btn-sm w-100"
                                            form="postForm"
                                            formnovalidate
                                            data-confirm="Der Beitrag wird dauerhaft gelöscht. Wirklich fortfahren?"
                                        >Beitrag löschen</button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 d-flex">
                            <div class="card cms-edit-card cms-edit-top-card w-100">
                                <div class="card-header"><h3 class="card-title">Beitragsbild</h3></div>
                                <div class="card-body d-flex flex-column gap-2">
                                    <div id="featuredPreview" class="<?php echo $postFeaturedImageValue !== '' ? '' : 'd-none'; ?>">
                                        <img src="<?php echo htmlspecialchars(\CMS\Services\MediaDeliveryService::getInstance()->normalizeUrl($postFeaturedImageValue, true)); ?>" class="rounded" id="featuredImg" alt="Beitragsbild" style="max-width:100%;max-height:120px;object-fit:cover;display:block;">
                                    </div>
                                    <div id="featuredEmpty" class="text-secondary small <?php echo $postFeaturedImageValue !== '' ? 'd-none' : ''; ?>">Noch kein Beitragsbild ausgewählt.</div>
                                    <input type="hidden" name="featured_image" id="featuredInput" value="<?php echo htmlspecialchars($postFeaturedImageValue); ?>">
                                    <input type="hidden" name="featured_image_temp_path" id="featuredInput_temp_path" value="">
                                    <div class="d-flex gap-2 mt-auto">
                                        <button type="button" class="btn btn-outline-primary btn-sm w-100" id="btnSelectImage">Bild auswählen</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm <?php echo $postFeaturedImageValue !== '' ? '' : 'd-none'; ?>" id="btnRemoveImage">Entfernen</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 d-flex cms-editor-sidebar-slot">
                    <div class="card cms-edit-card cms-edit-top-card h-100 w-100 cms-publication-card">
                        <div class="card-header">
                            <h3 class="card-title">Veröffentlichung</h3>
                        </div>
                        <div class="card-body flex-fill">
                            <div class="mb-3">
                                <label class="form-label" for="status">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="draft" <?php if ($postStatusValue === 'draft') echo 'selected'; ?>>Entwurf</option>
                                    <option value="published" <?php if ($postStatusValue === 'published') echo 'selected'; ?>>Veröffentlicht</option>
                                    <option value="private" <?php if ($postStatusValue === 'private') echo 'selected'; ?>>Privat (nur Mitglieder)</option>
                                </select>
                                                        <div class="form-hint mt-2">Wenn der Status auf <strong>Veröffentlicht</strong> steht und Datum/Uhrzeit in der Zukunft liegen, wird der Beitrag automatisch erst zu diesem Zeitpunkt öffentlich sichtbar. <strong>Privat</strong> ist nur für eingeloggte Mitglieder sichtbar.</div>
                            </div>
                            <div class="row g-2 mt-1">
                                <div class="col-sm-7">
                                    <label class="form-label" for="publishDate">Veröffentlichungsdatum</label>
                                    <input type="date" class="form-control" id="publishDate" name="publish_date" value="<?php echo htmlspecialchars($publishDate); ?>">
                                </div>
                                <div class="col-sm-5">
                                    <label class="form-label" for="publishTime">Uhrzeit</label>
                                    <input type="time" class="form-control" id="publishTime" name="publish_time" value="<?php echo htmlspecialchars($publishTime); ?>" step="60">
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="form-label" for="authorDisplayName">Autoren-Anzeigename im Artikel</label>
                                <input type="text" class="form-control" id="authorDisplayName" name="author_display_name" value="<?php echo $authorDisplayName; ?>" maxlength="150" placeholder="Leer lassen = normaler 365CMS-Anzeigename des Autors">
                                <div class="form-hint">Optionaler Override nur für diesen Beitrag. Wenn leer, wird automatisch der Anzeigename des zugewiesenen 365CMS-Autors verwendet.</div>
                            </div>
                            <div class="mt-3">
                                <label class="form-label" for="authorDisplayUrl">Website des alternativen Autors</label>
                                <input type="url" class="form-control" id="authorDisplayUrl" name="author_display_url" value="<?php echo $authorDisplayUrl; ?>" maxlength="500" placeholder="https://beispiel.de (optional)">
                                <div class="form-hint">Optional. Wenn gesetzt, verlinkt der Autorenname im Artikel auf diese Website (öffnet in neuem Tab) statt auf die interne Autoren-Info-Seite. Muss mit http:// oder https:// beginnen.</div>
                            </div>
                            <div class="mt-3">
                                <label class="form-label" for="contentUpdatedDate">Aktualisierungsdatum (öffentlich sichtbar)</label>
                                <div class="row g-2">
                                    <div class="col-sm-7">
                                        <input type="date" class="form-control" id="contentUpdatedDate" name="content_updated_date" value="<?php echo htmlspecialchars($contentUpdatedDate); ?>">
                                    </div>
                                    <div class="col-sm-5">
                                        <input type="time" class="form-control" id="contentUpdatedTime" aria-label="Aktualisierungsuhrzeit (öffentlich sichtbar)" name="content_updated_time" value="<?php echo htmlspecialchars($contentUpdatedTime); ?>" step="60">
                                    </div>
                                </div>
                                <div class="form-hint">Optional. Leer lassen = kein „Aktualisiert am“-Hinweis im Artikel und kein Update-Badge in den Übersichten. Ausgefüllt = wird am Artikelende als „Aktualisiert am“ angezeigt.</div>
                                <label class="form-check mt-2 mb-0">
                                    <input type="checkbox" class="form-check-input" id="treatUpdateAsNew" name="treat_update_as_new" value="1"<?php echo $treatUpdateAsNewChecked ? ' checked' : ''; ?>>
                                    <span class="form-check-label">Als neuen Beitrag behandeln (an die Spitze der Übersichten rücken)</span>
                                </label>
                                <div class="form-hint">Beim Speichern aktiv: Das Veröffentlichungsdatum wird auf das Aktualisierungsdatum gesetzt, der Beitrag erscheint dadurch wieder ganz oben. Inaktiv: Der Beitrag bleibt unter seinem ursprünglichen Datum, zeigt aber weiterhin das Update-Badge. Diese Auswahl wird nicht gespeichert und muss bei jeder erneuten Aktualisierung neu gesetzt werden.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 cms-editor-sidebar-slot">
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
                </div>

                <div class="col-12 cms-editor-primary">
                    <div class="card cms-edit-card cms-editor-card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                            <h3 class="card-title"><?php echo $isEnglishEditorView ? 'Inhalt · English' : 'Inhalt · Deutsch'; ?></h3>
                            <div class="btn-group" role="group" aria-label="Inhaltssprache wählen">
                                <a class="btn <?php echo $isEnglishEditorView ? 'btn-outline-primary' : 'btn-primary'; ?>" href="<?php echo htmlspecialchars($postEditUrlDe); ?>" aria-current="<?php echo $isEnglishEditorView ? 'false' : 'page'; ?>" data-lang-tab="de">Deutsch</a>
                                <a class="btn <?php echo $isEnglishEditorView ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo htmlspecialchars($postEditUrlEn); ?>" aria-current="<?php echo $isEnglishEditorView ? 'page' : 'false'; ?>" data-lang-tab="en">English</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($isEnglishEditorView): ?>
                                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                                    <div class="text-secondary small">Die englische Version ist unter <code><?php echo htmlspecialchars($postPreviewUrlEn); ?></code> erreichbar.</div>
                                    <div class="btn-list">
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
                                </div>
                                <div class="mb-3 text-secondary small">Die EN-Bearbeitung wird aktuell direkt gepflegt. Vorhandene Medien bleiben dabei wie gewohnt nur referenziert und werden nicht erneut hochgeladen.</div>
                            <?php else: ?>
                                <div class="mb-3 text-secondary small">Standardansicht unter <code><?php echo htmlspecialchars($postPreviewUrl); ?></code></div>
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
                                <div class="editorjs-wrap mb-3" id="<?php echo htmlspecialchars($isEnglishEditorView ? 'editorjsEn_wrap' : 'editorjs_wrap'); ?>" data-editor-state="loading">
                                    <div
                                        id="<?php echo htmlspecialchars($isEnglishEditorView ? 'editorjsEn' : 'editorjs'); ?>"
                                        class="editorjs-holder"
                                        style="min-height:320px;"
                                    ></div>
                                </div>
                                <div class="d-none" aria-hidden="true">
                                    <div id="postHiddenEditorDe"></div>
                                    <div id="postHiddenEditorEn"></div>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>

                <div class="col-xl-4 d-flex cms-editor-sidebar-slot cms-editor-sidebar-slot--seo">
                    <details class="card cms-edit-card h-100 w-100 cms-collapsible-card cms-collapsible-card--seo">
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
                            <div class="mb-3">
                                <label class="form-label" for="<?php echo htmlspecialchars($activeExcerptInputId); ?>"><?php echo $isEnglishEditorView ? 'Englische Kurzfassung' : 'Kurzfassung'; ?></label>
                                <textarea class="form-control" id="<?php echo htmlspecialchars($activeExcerptInputId); ?>" name="<?php echo $isEnglishEditorView ? 'excerpt_en' : 'excerpt'; ?>" rows="5" placeholder="<?php echo htmlspecialchars($isEnglishEditorView ? 'Short English summary for overview pages…' : 'Kurze Zusammenfassung für Übersichten…'); ?>"><?php echo htmlspecialchars($activePostExcerptValue); ?></textarea>
                                <span class="form-hint"><span id="excerptCount">0</span> Zeichen</span>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="focusKeyphrase">Fokus-Keyphrase</label>
                                <input type="text" class="form-control" id="focusKeyphrase" name="focus_keyphrase" value="<?php echo $focusKeyphrase; ?>" placeholder="z. B. KI-Strategie Mittelstand">
                                <span class="form-hint">Mehrere Varianten per Komma möglich.</span>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="postSeoKeywords">SEO-Tags / Keywords</label>
                                <input type="text" class="form-control" id="postSeoKeywords" name="keywords" value="<?php echo $postSeoKeywords; ?>" placeholder="z. B. KI, Strategie, Mittelstand">
                                <span class="form-hint">Kommagetrennte Begriffe für die SEO-Metadaten.</span>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="metaTitle">Meta-Titel</label>
                                <input type="text" class="form-control" id="metaTitle" name="meta_title" value="<?php echo htmlspecialchars($postMetaTitleValue); ?>" maxlength="70">
                                <span class="form-hint"><span id="metaTitleCount">0</span>/70 Zeichen</span>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="metaDesc">Meta-Beschreibung</label>
                                <textarea class="form-control" id="metaDesc" name="meta_description" rows="4" maxlength="160"><?php echo htmlspecialchars($postMetaDescriptionValue); ?></textarea>
                                <span class="form-hint"><span id="metaDescCount">0</span>/160 Zeichen</span>
                            </div>
                            <div id="postSeoOverrideNotice" class="alert alert-info d-none cms-seo-override-notice" role="status" aria-live="polite">
                                <div class="cms-seo-override-header">
                                    <div class="fw-semibold cms-seo-override-title">SEO-Default-Hinweis</div>
                                </div>
                                <div class="cms-seo-override-actions">
                                    <button type="button" class="btn btn-sm btn-outline-primary d-none" id="postSeoResetMetaTitle">Meta-Titel auf Default zurücksetzen</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary d-none" id="postSeoResetMetaDescription">Meta-Beschreibung auf Default zurücksetzen</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="postSeoResetAllMetaDefaults">Alle lokalen SEO-Felder auf Default zurücksetzen</button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="canonicalUrl">Kanonische URL</label>
                                <input type="text" class="form-control" id="canonicalUrl" name="canonical_url" value="<?php echo $canonicalUrl; ?>" placeholder="Automatisch self-referencing, wenn leer">
                            </div>
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                <span class="text-secondary small">Vorschau-URL</span>
                                <span class="badge <?php echo $isScheduledPost ? 'bg-azure-lt text-azure' : 'bg-success-lt text-success'; ?>" id="postSlugState"><?php echo $isScheduledPost ? 'Geplant' : 'Slug gültig'; ?></span>
                            </div>
                            <div class="form-control-plaintext text-break small mb-3" id="postPreviewUrl"><?php echo htmlspecialchars($activePostPreviewUrl); ?></div>
                            <div id="postPublishWarning" class="alert <?php echo $isScheduledPost ? 'alert-info' : 'alert-warning'; ?> mb-0<?php echo $isScheduledPost ? '' : ' d-none'; ?>" role="alert"><?php echo $isScheduledPost ? 'Dieser Beitrag ist geplant und wird automatisch zum gewählten Termin veröffentlicht.' : ''; ?></div>
                        </div>
                    </details>
                </div>

                <div class="col-xl-4 d-flex cms-editor-sidebar-slot cms-editor-sidebar-slot--seo">
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
                    ?>
                </div>

                <div class="col-xl-4 d-flex cms-editor-sidebar-slot cms-editor-sidebar-slot--seo">
                    <?php
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

                <div class="col-12 cms-editor-sidebar-slot cms-editor-sidebar-slot--seo">
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
                    ?>
                </div>

                <?php if (!$isNew): ?>
                <div class="col-12 cms-editor-sidebar-slot cms-editor-sidebar-slot--seo">
                    <details class="card cms-edit-card">
                        <summary class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap" style="cursor:pointer; list-style:none;">
                            <div>
                                <h3 class="card-title mb-0">Revisionen & Vergleich</h3>
                                <div class="text-secondary small mt-1">Die letzten gespeicherten Snapshots zeigen Unterschiede zwischen aktuellem Stand und früheren Beitragsfassungen – inklusive Teaser, Kategorie, Tags und DE/EN-Inhalten.</div>
                            </div>
                            <span class="badge bg-blue-lt text-blue"><?php echo (int) ($revisionHistory['total'] ?? 0); ?> Revision(en)</span>
                        </summary>
                        <div class="card-body">
                            <?php if ($postRevisionItems === []): ?>
                                <div class="text-secondary">Noch keine gespeicherten Revisionen vorhanden. Beim nächsten inhaltlichen Speichern wird automatisch ein Snapshot angelegt.</div>
                            <?php else: ?>
                                <div class="alert alert-info" role="status">
                                    Die Ansicht bleibt bewusst read-only: Sie dient dem sicheren Vergleich, ohne frühere Beitragsfassungen still zurückzuschreiben.
                                </div>
                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($postRevisionItems as $revision): ?>
                                        <?php
                                        $revisionChangedFields = is_array($revision['changed_fields'] ?? null) ? $revision['changed_fields'] : [];
                                        $revisionFieldDiffs = is_array($revision['field_diffs'] ?? null) ? $revision['field_diffs'] : [];
                                        ?>
                                        <details class="border rounded-3 p-3">
                                            <summary class="d-flex flex-wrap align-items-center gap-2" style="cursor:pointer; list-style:none;">
                                                <strong><?php echo htmlspecialchars((string) ($revision['created_at_label'] ?? 'Unbekanntes Datum')); ?></strong>
                                                <span class="text-secondary">von <?php echo htmlspecialchars((string) ($revision['author_label'] ?? 'Unbekannt')); ?></span>
                                                <?php if ($revisionChangedFields !== []): ?>
                                                    <span class="text-secondary">· geändert:</span>
                                                    <?php foreach ($revisionChangedFields as $fieldLabel): ?>
                                                        <span class="badge bg-azure-lt text-azure"><?php echo htmlspecialchars((string) $fieldLabel); ?></span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </summary>

                                            <?php if ($revisionFieldDiffs === []): ?>
                                                <div class="text-secondary small mt-3">Zu den aktuell verglichenen Feldern wurden keine Unterschiede erkannt.</div>
                                            <?php else: ?>
                                                <div class="row g-3 mt-1">
                                                    <?php foreach ($revisionFieldDiffs as $fieldDiff): ?>
                                                        <div class="col-xl-6 d-flex">
                                                            <div class="card card-sm w-100">
                                                                <div class="card-header">
                                                                    <h4 class="card-title mb-0"><?php echo htmlspecialchars((string) ($fieldDiff['label'] ?? 'Unterschied')); ?></h4>
                                                                </div>
                                                                <div class="card-body">
                                                                    <div class="row g-3">
                                                                        <div class="col-md-6">
                                                                            <div class="text-secondary text-uppercase small mb-1"><?php echo htmlspecialchars((string) ($fieldDiff['current_label'] ?? 'Aktuell')); ?></div>
                                                                            <?php if (($fieldDiff['type'] ?? '') === 'content'): ?>
                                                                                <?php $currentSummary = is_array($fieldDiff['current_summary'] ?? null) ? $fieldDiff['current_summary'] : []; ?>
                                                                                <div class="fw-semibold mb-1"><?php echo htmlspecialchars((string) ($currentSummary['preview'] ?? '— leer —')); ?></div>
                                                                                <div class="small text-secondary"><?php echo htmlspecialchars($postRevisionContentMeta($currentSummary)); ?></div>
                                                                            <?php else: ?>
                                                                                <div class="fw-semibold"><?php echo htmlspecialchars((string) ($fieldDiff['current_value'] ?? '— leer —')); ?></div>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <div class="col-md-6">
                                                                            <div class="text-secondary text-uppercase small mb-1"><?php echo htmlspecialchars((string) ($fieldDiff['revision_label'] ?? 'Revision')); ?></div>
                                                                            <?php if (($fieldDiff['type'] ?? '') === 'content'): ?>
                                                                                <?php $revisionSummary = is_array($fieldDiff['revision_summary'] ?? null) ? $fieldDiff['revision_summary'] : []; ?>
                                                                                <div class="fw-semibold mb-1"><?php echo htmlspecialchars((string) ($revisionSummary['preview'] ?? '— leer —')); ?></div>
                                                                                <div class="small text-secondary"><?php echo htmlspecialchars($postRevisionContentMeta($revisionSummary)); ?></div>
                                                                            <?php else: ?>
                                                                                <div class="fw-semibold"><?php echo htmlspecialchars((string) ($fieldDiff['revision_value'] ?? '— leer —')); ?></div>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </details>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (!empty($revisionHistory['has_more'])): ?>
                                    <div class="text-secondary small mt-3">Aus Performance-Gründen werden hier nur die letzten <?php echo (int) ($revisionHistory['displayed'] ?? 0); ?> Revisionen angezeigt.</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
                <?php endif; ?>

                <div class="col-12 cms-editor-sidebar-slot cms-editor-sidebar-slot--seo">
                    <?php
                    $advancedSeoPanel = [
                        'hint' => 'Das Beitragsbild wird oben im Formular gesetzt. Hier kann ein separates OG-Bild für Social Media hinterlegt werden.',
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

        </form>

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
            'titleSelector' => '.page-header .page-title',
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
            'categoryLabelId' => 'postCategoryLabel',
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
                ['sourceId' => 'metaDesc', 'targetId' => 'metaDescCount'],
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
