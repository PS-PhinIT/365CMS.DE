<?php
declare(strict_types=1);

/**
 * Pages Edit View – Seite erstellen / bearbeiten
 *
 * Erwartet:
 *   $editData['page']   – object|null  Bestehende Seite oder null
 *   $editData['isNew']  – bool         Neue Seite?
 *   $csrfToken          – string       CSRF-Token
 *   $alert              – array|null   Erfolgs-/Fehlermeldung
 *
 * @package CMSv2\Admin\Views
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Services\ContentLocalizationService;

$aiTranslationEnabled = !empty($aiTranslationEnabled);
$aiSeoMetadataEnabled = !empty($aiSeoMetadataEnabled);
$aiSeoMetadataUnavailableReason = is_string($aiSeoMetadataUnavailableReason ?? null) ? trim($aiSeoMetadataUnavailableReason) : '';
$aiSettingsUrl = is_string($aiSettingsUrl ?? null) ? trim($aiSettingsUrl) : '';

$pageAdminBaseUrl = '/admin/pages';
$page    = $editData['page'] ?? null;
$isNew   = $editData['isNew'] ?? true;
$categories = $editData['categories'] ?? [];
$pageTemplates = $editData['pageTemplates'] ?? [
    ['id' => 'default', 'label' => 'Standard', 'description' => '', 'meta_fields' => []],
];
$pageTemplateValue = (string) ($page->page_template ?? 'default');
if (!in_array($pageTemplateValue, array_column($pageTemplates, 'id'), true)) {
    $pageTemplateValue = 'default';
}
$pageTemplateMetaValues = [];
if (!empty($page->page_meta_json)) {
    try {
        $decodedPageMeta = json_decode((string) $page->page_meta_json, true, 16, JSON_THROW_ON_ERROR);
        if (is_array($decodedPageMeta)) {
            $pageTemplateMetaValues = $decodedPageMeta;
        }
    } catch (\JsonException $exception) {
        \CMS\Logger::instance()->withChannel('admin.pages')->warning('Gespeicherte Vorlagen-Zusatzfelder konnten nicht gelesen werden.', [
            'page_id' => $page->id ?? null, 'exception' => $exception->getMessage(),
        ]);
    }
}
$seoMeta = $editData['seoMeta'] ?? [];
$pageTemplates = is_array($editData['pageTemplates'] ?? null) ? $editData['pageTemplates'] : [];
$pageTemplateValue = trim((string) ($page->page_template ?? 'default')) ?: 'default';
$pageTemplateMetaValues = [];
$pageTemplateMetaRaw = trim((string) ($page->page_meta_json ?? ''));
if ($pageTemplateMetaRaw !== '') {
    $decodedPageTemplateMeta = json_decode($pageTemplateMetaRaw, true);
    $pageTemplateMetaValues = is_array($decodedPageTemplateMeta) ? $decodedPageTemplateMeta : [];
}
$revisionHistory = is_array($editData['revisionHistory'] ?? null) ? $editData['revisionHistory'] : ['total' => 0, 'displayed' => 0, 'has_more' => false, 'items' => []];
$pageRevisionItems = is_array($revisionHistory['items'] ?? null) ? $revisionHistory['items'] : [];
$pageRevisionContentMeta = static function (array $summary): string {
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
$seoTemplateSettings = \CMS\Services\SeoAnalysisService::getInstance()->getSettings();
$pageEditorWidth = function_exists('get_option') ? (int)get_option('setting_page_editor_width', 1050) : 1050;
$pageEditorWidth = max(320, min(1600, $pageEditorWidth));
$pageDefaultStatus = function_exists('get_option') ? (string)get_option('setting_page_default_status', 'draft') : 'draft';
if (!in_array($pageDefaultStatus, ['draft', 'published', 'private'], true)) {
    $pageDefaultStatus = 'draft';
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
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        foreach (['text', 'message', 'title', 'code', 'caption', 'content', 'html'] as $key) {
            $value = isset($data[$key]) ? trim((string) $data[$key]) : '';
            if ($value !== '') {
                $parts[] = trim(strip_tags(str_replace('<br>', "\n", $value)));
                break;
            }
        }
    }

    return trim(implode("\n\n", array_filter($parts, static fn(string $part): bool => $part !== '')));
};
$editorLocale = (($editorLocale ?? 'de') === 'en') ? 'en' : 'de';
$isEnglishEditorView = $editorLocale === 'en';
?>

<!-- Page Header -->
<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Seiten & Beiträge</div>
                <h2 class="page-title"><?= $isNew ? 'Neue Seite' : 'Seite bearbeiten' ?></h2>
            </div>
            <div class="col-auto ms-auto">
                <a href="<?= $pageAdminBaseUrl ?>" class="btn btn-outline-secondary" id="pageBackToList">
                    ← Zurück zur Liste
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Page Body -->
<div class="page-body">
    <div class="container-xl">

        <?php
        $alertData = is_array($alert ?? null) ? $alert : [];
        $alertMarginClass = 'mb-3';
        include __DIR__ . '/../partials/flash-alert.php';
        ?>

        <form method="post" action="<?= htmlspecialchars($pageAdminBaseUrl, ENT_QUOTES, 'UTF-8') ?>" id="pageForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="editor_locale" value="<?= htmlspecialchars($editorLocale) ?>">
            <?php if (!$isNew): ?>
                <input type="hidden" name="id" value="<?= (int)$page->id ?>">
            <?php endif; ?>

            <?php
            $pageTitleValue = (string)($page->title ?? '');
            $pageSlugValue = (string)($page->slug ?? '');
            $pageSlugEnValue = (string)($page->slug_en ?? '');
            $pageStatusValue = (string)($page->status ?? $pageDefaultStatus);
            $pageContentValue = (string)($page->content ?? '');
            $pageTitleEnValue = (string)($page->title_en ?? '');
            $pageContentEnValue = (string)($page->content_en ?? '');
            $pageContentPlainValue = $extractEditorPlainText($pageContentValue);
            $pageContentPlainEnValue = $extractEditorPlainText($pageContentEnValue);
            $pageHideTitleValue = (int)($page->hide_title ?? 0);
            $pageShowTitleTocValue = (int)($page->show_title_toc ?? 0);
            $pageCategoryIdValue = (int)($page->category_id ?? 0);
            $pageMetaTitleValue = (string)($page->meta_title ?? '');
            $pageMetaDescriptionValue = (string)($page->meta_description ?? '');
            $pageContentUpdatedAtValue = (string)($page->content_updated_at ?? '');
            $pageContentUpdatedDate = '';
            $pageContentUpdatedTime = '';
            if ($pageContentUpdatedAtValue !== '') {
                $pageContentUpdatedTimestamp = strtotime($pageContentUpdatedAtValue);
                if ($pageContentUpdatedTimestamp !== false) {
                    $pageContentUpdatedDate = date('Y-m-d', $pageContentUpdatedTimestamp);
                    $pageContentUpdatedTime = date('H:i', $pageContentUpdatedTimestamp);
                }
            }
            $pageFeaturedImageValue = (string)($page->featured_image ?? '');
            $pagePreviewSlug = ltrim($pageSlugValue, '/');
            $pagePreviewSlugEn = ltrim($pageSlugEnValue !== '' ? $pageSlugEnValue : $pageSlugValue, '/');
            $pagePreviewUrl = $pagePreviewSlug !== '' ? '/' . $pagePreviewSlug : '/';
            $pagePreviewUrlEn = ContentLocalizationService::getInstance()->buildLocalizedPath($pagePreviewSlugEn !== '' ? '/' . $pagePreviewSlugEn : '/', 'en');
            $pagePreviewUrlTemplate = '/{slug}';
            $pagePreviewUrlTemplateEn = ContentLocalizationService::getInstance()->buildLocalizedPath('/{slug}', 'en');
            $pageEditUrlDe = $isNew
                ? '/admin/pages?action=edit&lang=de'
                : '/admin/pages?action=edit&id=' . (int)($page->id ?? 0) . '&lang=de';
            $pageEditUrlEn = $isNew
                ? '/admin/pages?action=edit&lang=en'
                : '/admin/pages?action=edit&id=' . (int)($page->id ?? 0) . '&lang=en';
            $activePageTitleValue = $isEnglishEditorView ? $pageTitleEnValue : $pageTitleValue;
            $activePageSlugValue = $isEnglishEditorView ? $pageSlugEnValue : $pageSlugValue;
            $activePagePreviewUrl = $isEnglishEditorView ? $pagePreviewUrlEn : $pagePreviewUrl;
            $activePagePreviewUrlTemplate = $isEnglishEditorView ? $pagePreviewUrlTemplateEn : $pagePreviewUrlTemplate;
            $activePagePreviewSlugFallback = $isEnglishEditorView
                ? ($pageSlugEnValue !== '' ? $pageSlugEnValue : ($pageSlugValue !== '' ? $pageSlugValue : 'seite'))
                : ($pageSlugValue !== '' ? $pageSlugValue : 'seite');
            $activePageTitleInputId = $isEnglishEditorView ? 'pageTitleEn' : 'pageTitle';
            $activePageSlugInputId = $isEnglishEditorView ? 'pageSlugEn' : 'pageSlug';
            $activePageContentInputId = $isEnglishEditorView ? 'pagePlainEditorEn' : 'pagePlainEditorDe';
            $activePageEditorHolderId = $isEnglishEditorView ? 'editorjsEn' : 'editorjs';
            $activePageContentFieldName = $isEnglishEditorView ? 'content_en' : 'content';
            $activePageContentPlainTextValue = $isEnglishEditorView ? $pageContentPlainEnValue : $pageContentPlainValue;
            $activePageContentLabel = $isEnglishEditorView ? 'EditorJS Notfall-Fallback (EN)' : 'EditorJS Notfall-Fallback (DE)';
            $pageEditorHasValidActiveMapping = ($isEnglishEditorView && $activePageContentInputId === 'pagePlainEditorEn')
                || (!$isEnglishEditorView && $activePageContentInputId === 'pagePlainEditorDe');
            $pageFocusKeyphraseValue = (string)($seoMeta['focus_keyphrase'] ?? '');
            $pageKeywordsValue = (string)($seoMeta['keywords'] ?? '');
            $pageCanonicalUrlValue = (string)($seoMeta['canonical_url'] ?? '');
            $pageRobotsIndexValue = !array_key_exists('robots_index', $seoMeta) || !empty($seoMeta['robots_index']);
            $pageRobotsFollowValue = !array_key_exists('robots_follow', $seoMeta) || !empty($seoMeta['robots_follow']);
            $pageOgTitleValue = (string)($seoMeta['og_title'] ?? '');
            $pageOgDescriptionValue = (string)($seoMeta['og_description'] ?? '');
            $pageOgImageValue = (string)($seoMeta['og_image'] ?? '');
            $pageTwitterTitleValue = (string)($seoMeta['twitter_title'] ?? '');
            $pageTwitterDescriptionValue = (string)($seoMeta['twitter_description'] ?? '');
            $pageTwitterImageValue = (string)($seoMeta['twitter_image'] ?? '');
            $pageTwitterCardValue = (string)($seoMeta['twitter_card'] ?? 'summary_large_image');
            $pageSchemaTypeValue = (string)($seoMeta['schema_type'] ?? 'WebPage');
            $pageSitemapPriorityValue = (string)($seoMeta['sitemap_priority'] ?? '');
            $pageSitemapChangefreqValue = (string)($seoMeta['sitemap_changefreq'] ?? 'weekly');
            $pageHreflangGroupValue = (string)($seoMeta['hreflang_group'] ?? '');
            $pageEditorDraftKey = $isNew
                ? substr(hash('sha256', 'page:' . ($pageTitleValue !== '' ? $pageTitleValue : microtime((bool) true)) . ':' . session_id()), 0, 12)
                : '';
            ?>

            <?php if ($isEnglishEditorView): ?>
                <input type="hidden" name="title" id="pageTitle" value="<?= htmlspecialchars($pageTitleValue) ?>">
                <input type="hidden" name="slug" id="pageSlug" value="<?= htmlspecialchars($pageSlugValue) ?>">
                <input type="hidden" id="editorContent" name="content" value="<?= htmlspecialchars($pageContentValue) ?>">
                <input type="hidden" id="editorContentEn" name="content_en" value="<?= htmlspecialchars($pageContentEnValue) ?>">
            <?php else: ?>
                <input type="hidden" name="title_en" id="pageTitleEn" value="<?= htmlspecialchars($pageTitleEnValue) ?>">
                <input type="hidden" name="slug_en" id="pageSlugEn" value="<?= htmlspecialchars($pageSlugEnValue) ?>">
                <input type="hidden" id="editorContent" name="content" value="<?= htmlspecialchars($pageContentValue) ?>">
                <input type="hidden" id="editorContentEn" name="content_en" value="<?= htmlspecialchars($pageContentEnValue) ?>">
            <?php endif; ?>
            <input type="hidden" name="content_original" value="<?= htmlspecialchars($pageContentValue) ?>">
            <input type="hidden" name="content_en_original" value="<?= htmlspecialchars($pageContentEnValue) ?>">

            <div class="row g-3 cms-content-editor-layout cms-content-editor-layout--page <?php echo $isNew ? 'cms-content-editor-layout--page-new' : 'cms-content-editor-layout--page-existing'; ?>">
                <div class="col-lg-4 d-flex cms-editor-sidebar-slot">
                    <div class="card cms-edit-card cms-edit-top-card h-100 w-100">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label<?= $isEnglishEditorView ? '' : ' required' ?>" for="<?= htmlspecialchars($activePageTitleInputId) ?>"><?= $isEnglishEditorView ? 'Englischer Titel' : 'Titel' ?></label>
                                <input type="text" name="<?= $isEnglishEditorView ? 'title_en' : 'title' ?>" id="<?= htmlspecialchars($activePageTitleInputId) ?>" class="form-control"
                                       placeholder="<?= htmlspecialchars($isEnglishEditorView ? 'English page title' : 'Seitentitel') ?>"
                                       value="<?= htmlspecialchars($activePageTitleValue) ?>"<?= $isEnglishEditorView ? '' : ' required' ?>>
                            </div>
                            <div class="mb-3">
                                <label class="form-check mb-0">
                                    <input type="checkbox" name="hide_title" value="1"
                                           class="form-check-input"
                                           id="hideTitle"
                                             <?= $pageHideTitleValue === 1 ? 'checked' : '' ?>>
                                    <span class="form-check-label">Titel ausblenden</span>
                                </label>
                                <div class="form-hint">Blendet den sichtbaren Seitentitel im Frontend aus, ohne SEO-/Navigationsdaten zu entfernen.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-check mb-0">
                                    <input type="checkbox" name="show_title_toc" value="1"
                                           class="form-check-input"
                                           id="showTitleToc"
                                             <?= $pageShowTitleTocValue === 1 ? 'checked' : '' ?>>
                                    <span class="form-check-label">Eingeklapptes Inhaltsverzeichnis unter dem Titel anzeigen</span>
                                </label>
                                <div class="form-hint">Seitenspezifisch: funktioniert unabhängig vom aktiven Theme und unabhängig von den globalen TOC-Einstellungen. Wird nur bei mindestens zwei Überschriften ausgegeben.</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="<?= htmlspecialchars($activePageSlugInputId) ?>"><?= $isEnglishEditorView ? 'Englischer Slug' : 'Slug' ?></label>
                                <div class="input-group">
                                    <span class="input-group-text">/</span>
                                    <input type="text" name="<?= $isEnglishEditorView ? 'slug_en' : 'slug' ?>" id="<?= htmlspecialchars($activePageSlugInputId) ?>" class="form-control"
                                           placeholder="<?= htmlspecialchars($isEnglishEditorView ? 'english-page-slug' : 'seiten-url') ?>"
                                           value="<?= htmlspecialchars($activePageSlugValue) ?>">
                                </div>
                                <small class="form-hint"><?= $isEnglishEditorView ? 'Wenn leer, nutzt die EN-URL weiterhin den Standardslug.' : 'Wird automatisch aus dem Titel generiert, wenn leer.' ?></small>
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
                                        <?= $isNew ? 'Seite erstellen' : 'Speichern' ?>
                                    </button>
                                    <?php if (!$isNew): ?>
                                        <div class="d-flex gap-2">
                                            <a href="<?= htmlspecialchars($pagePreviewUrl) ?>"
                                               class="btn btn-outline-secondary w-100" target="_blank" rel="noopener noreferrer" title="Vorschau Deutsch">
                                                Vorschau DE
                                            </a>
                                            <a href="<?= htmlspecialchars($pagePreviewUrlEn) ?>"
                                               class="btn btn-outline-secondary w-100" target="_blank" rel="noopener noreferrer" title="English public preview">
                                                Vorschau EN
                                            </a>
                                        </div>
                                        <div class="border-top pt-2 mt-1">
                                            <button
                                                type="submit"
                                                name="_action"
                                                value="delete"
                                                class="btn btn-outline-danger btn-sm w-100"
                                                form="pageForm"
                                                formnovalidate
                                                data-confirm="Die Seite wird dauerhaft gelöscht. Wirklich fortfahren?"
                                            >Seite löschen</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 d-flex">
                            <div class="card cms-edit-card cms-edit-top-card w-100">
                                <div class="card-header">
                                    <h3 class="card-title">Contentheader Bild</h3>
                                </div>
                                <div class="card-body d-flex flex-column gap-2">
                                    <div class="small text-secondary mb-2">Erscheint links vom Seitentitel im Content-Header.</div>
                                    <div id="featuredImagePreview">
                                        <?php if ($pageFeaturedImageValue !== ''): ?>
                                            <img src="<?= htmlspecialchars(\CMS\Services\MediaDeliveryService::getInstance()->normalizeUrl($pageFeaturedImageValue, true)) ?>" alt="" class="img-fluid rounded" style="max-height:120px;object-fit:cover;width:100%;">
                                        <?php endif; ?>
                                    </div>
                                    <input type="hidden" name="featured_image" id="featuredImageInput" value="<?= htmlspecialchars($pageFeaturedImageValue) ?>">
                                    <input type="hidden" name="featured_image_temp_path" id="featuredImageInput_temp_path" value="">
                                    <div id="featuredImageEmpty" class="text-secondary small <?= $pageFeaturedImageValue !== '' ? 'd-none' : '' ?>">Noch kein Bild ausgewählt.</div>
                                    <div class="d-flex gap-2 mt-auto">
                                        <button type="button" class="btn btn-sm btn-outline-primary w-100" id="featuredImageBtn">Bild auswählen</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary <?= $pageFeaturedImageValue === '' ? 'd-none' : '' ?>" id="featuredImageRemove">Entfernen</button>
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
                                <label for="pageStatusSelect" class="form-label">Status</label>
                                <select name="status" class="form-select" id="pageStatusSelect">
                                    <option value="draft"<?= $pageStatusValue === 'draft' ? ' selected' : '' ?>>Entwurf</option>
                                    <option value="published"<?= $pageStatusValue === 'published' ? ' selected' : '' ?>>Veröffentlicht</option>
                                    <option value="private"<?= $pageStatusValue === 'private' ? ' selected' : '' ?>>Privat (nur Mitglieder)</option>
                                </select>
                                <div class="form-hint mt-2">Private Seiten sind nicht öffentlich erreichbar und nur für eingeloggte Mitglieder bzw. Administratoren sichtbar.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="pageCategoryId">Kategorie</label>
                                <select name="category_id" class="form-select" id="pageCategoryId">
                                    <option value="0">Keine Kategorie</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= (int)($category['id'] ?? 0) ?>"<?= $pageCategoryIdValue === (int)($category['id'] ?? 0) ? ' selected' : '' ?>><?= htmlspecialchars((string)($category['option_label'] ?? $category['name'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php require __DIR__ . '/../partials/page-template-fields.php'; ?>
                            <div class="mb-0">
                                <label class="form-label" for="pageContentUpdatedDate">Aktualisierungsdatum (öffentlich sichtbar)</label>
                                <div class="row g-2">
                                    <div class="col-sm-7">
                                        <input type="date" class="form-control" id="pageContentUpdatedDate" name="content_updated_date" value="<?= htmlspecialchars($pageContentUpdatedDate) ?>">
                                    </div>
                                    <div class="col-sm-5">
                                        <input type="time" class="form-control" id="pageContentUpdatedTime" aria-label="Aktualisierungsuhrzeit (öffentlich sichtbar)" name="content_updated_time" value="<?= htmlspecialchars($pageContentUpdatedTime) ?>" step="60">
                                    </div>
                                </div>
                                <div class="form-hint">Optional. Leer lassen = kein „Zuletzt aktualisiert“-Hinweis auf der Seite.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php include __DIR__ . '/../partials/page-template-fields.php'; ?>

                <div class="col-12 cms-editor-primary">
                    <div class="card cms-edit-card cms-editor-card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                            <h3 class="card-title"><?= $isEnglishEditorView ? 'Inhalt · English' : 'Inhalt · Deutsch' ?></h3>
                            <div class="btn-group" role="group" aria-label="Inhaltssprache wählen">
                                <a class="btn <?= $isEnglishEditorView ? 'btn-outline-primary' : 'btn-primary' ?>" href="<?= htmlspecialchars($pageEditUrlDe) ?>" aria-current="<?= $isEnglishEditorView ? 'false' : 'page' ?>" data-lang-tab="de">Deutsch</a>
                                <a class="btn <?= $isEnglishEditorView ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= htmlspecialchars($pageEditUrlEn) ?>" aria-current="<?= $isEnglishEditorView ? 'page' : 'false' ?>" data-lang-tab="en">English</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($isEnglishEditorView): ?>
                                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                                    <div class="text-secondary small">Die englische Version ist unter <code><?= htmlspecialchars($pagePreviewUrlEn) ?></code> erreichbar.</div>
                                    <div class="btn-list">
                                        <?php if (!$isNew): ?>
                                            <button
                                                type="submit"
                                                name="_action"
                                                value="copy_de_to_en"
                                                class="btn btn-outline-secondary btn-sm"
                                                id="copyPageDeToEnButton"
                                                form="pageForm"
                                                formnovalidate
                                                data-confirm="Die EN-Fassung wird serverseitig mit den deutschen Inhalten überschrieben. Fortfahren?"
                                            >DE nach EN kopieren</button>
                                        <?php endif; ?>
                                        <?php if ($aiTranslationEnabled): ?>
                                            <button type="button" class="btn btn-primary btn-sm" id="translatePageDeToEnButton">Mit AI nach EN übersetzen</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="mb-3 text-secondary small">Die EN-Bearbeitung läuft als eigene Admin-Seite. Die deutsche Fassung bleibt parallel erhalten und wird beim Speichern nicht durch einen In-Page-Tabwechsel gefährdet.</div>
                            <?php else: ?>
                                <div class="mb-3">
                                    <div class="text-secondary small mb-2">Standardansicht unter <code><?= htmlspecialchars($pagePreviewUrl) ?></code></div>
                                </div>
                            <?php endif; ?>

                            <div
                                class="cms-editor-plain-wrap mb-3<?= !empty($useEditorJs) ? ' cms-editor-plain-wrap--enhanced' : '' ?>"
                                id="<?= htmlspecialchars($isEnglishEditorView ? 'pagePlainEditorWrapEn' : 'pagePlainEditorWrapDe') ?>"
                                <?= !empty($useEditorJs) ? 'hidden' : '' ?>
                            >
                                <label class="form-label" for="<?= htmlspecialchars($activePageContentInputId) ?>"><?= htmlspecialchars($activePageContentLabel) ?></label>
                                <textarea
                                    class="form-control cms-editor-plain-textarea"
                                    id="<?= htmlspecialchars($activePageContentInputId) ?>"
                                    name="<?= htmlspecialchars($activePageContentFieldName) ?>"
                                    rows="14"
                                    <?= !empty($useEditorJs) ? 'disabled' : '' ?>
                                ><?= htmlspecialchars($activePageContentPlainTextValue) ?></textarea>
                            </div>
                            <?php if (!empty($useEditorJs)): ?>
                                <div class="editorjs-wrap mb-3" id="<?= htmlspecialchars($isEnglishEditorView ? 'editorjsEn_wrap' : 'editorjs_wrap') ?>" data-editor-state="loading">
                                    <div
                                        id="<?= htmlspecialchars($isEnglishEditorView ? 'editorjsEn' : 'editorjs') ?>"
                                        class="editorjs-holder"
                                        style="min-height:320px;"
                                    ></div>
                                </div>
                                <div class="d-none" aria-hidden="true">
                                    <div id="pageHiddenEditorDe"></div>
                                    <div id="pageHiddenEditorEn"></div>
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
                                        <span class="small">Erzeugt SEO-Metadaten aus dem Haupttext. Seitentitel, Slug und URL-Felder bleiben unverändert.</span>
                                        <button type="button" class="btn btn-primary btn-sm" id="generatePageSeoMetadataButton">SEO mit AI füllen</button>
                                    </div>
                                </div>
                            <?php elseif ($aiSeoMetadataUnavailableReason !== ''): ?>
                                <div class="alert alert-warning py-2 mb-3 small" role="status">
                                    <strong>SEO mit AI ist hier nicht verfügbar.</strong>
                                    <?= htmlspecialchars($aiSeoMetadataUnavailableReason) ?>
                                    <?php if ($aiSettingsUrl !== ''): ?>
                                        <a href="<?= htmlspecialchars($aiSettingsUrl) ?>">Zu den AI-Einstellungen</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="form-label" for="pageFocusKeyphrase">Fokus-Keyphrase</label>
                                <input type="text" name="focus_keyphrase" class="form-control" id="pageFocusKeyphrase" placeholder="z. B. Mitgliedschaft B2B-Netzwerk" value="<?= htmlspecialchars($pageFocusKeyphraseValue) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="pageSeoKeywords">SEO-Tags / Keywords</label>
                                <input type="text" name="keywords" class="form-control" id="pageSeoKeywords" placeholder="z. B. Microsoft 365, Managed Services, IT-Sicherheit" value="<?= htmlspecialchars($pageKeywordsValue) ?>">
                                <small class="form-hint">Nur für SEO-Metadaten: Kommagetrennte Begriffe werden im Head als Keywords ausgegeben, aber nicht sichtbar auf der Seite angezeigt.</small>
                            </div>
                            <div class="mb-3">
                                <label for="pageMetaTitle" class="form-label">Meta-Titel</label>
                                <input type="text" name="meta_title" class="form-control" id="pageMetaTitle" placeholder="SEO-Titel (Standard: Seitentitel)" maxlength="70" value="<?= htmlspecialchars($pageMetaTitleValue) ?>">
                                <small class="form-hint"><span id="metaTitleCount">0</span>/70 Zeichen</small>
                            </div>
                            <div class="mb-3">
                                <label for="pageMetaDescription" class="form-label">Meta-Beschreibung</label>
                                <textarea name="meta_description" class="form-control" rows="3" id="pageMetaDescription" placeholder="Kurze Beschreibung für Suchmaschinen…" maxlength="160"><?= htmlspecialchars($pageMetaDescriptionValue) ?></textarea>
                                <small class="form-hint"><span id="metaDescriptionCount">0</span>/160 Zeichen</small>
                            </div>
                            <div id="pageSeoOverrideNotice" class="alert alert-info d-none cms-seo-override-notice" role="status" aria-live="polite">
                                <div class="cms-seo-override-header">
                                    <div class="fw-semibold cms-seo-override-title">SEO-Default-Hinweis</div>
                                </div>
                                <div class="cms-seo-override-actions">
                                    <button type="button" class="btn btn-sm btn-outline-primary d-none" id="pageSeoResetMetaTitle">Meta-Titel auf Default zurücksetzen</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary d-none" id="pageSeoResetMetaDescription">Meta-Beschreibung auf Default zurücksetzen</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="pageSeoResetAllMetaDefaults">Alle lokalen SEO-Felder auf Default zurücksetzen</button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="pageCanonicalUrl">Kanonische URL</label>
                                <input type="text" name="canonical_url" id="pageCanonicalUrl" class="form-control" value="<?= htmlspecialchars($pageCanonicalUrlValue) ?>" placeholder="Automatisch self-referencing, wenn leer">
                            </div>
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2"><span class="text-secondary small">Vorschau-URL</span><span class="badge bg-success-lt text-success" id="pageSlugState">Slug gültig</span></div>
                            <div class="form-control-plaintext text-break small mb-3" id="pagePreviewUrl"><?= htmlspecialchars($activePagePreviewUrl) ?></div>
                            <div id="pagePublishWarning" class="alert alert-warning mb-0" role="alert"></div>
                        </div>
                    </details>
                </div>

                <div class="col-xl-4 d-flex cms-editor-sidebar-slot cms-editor-sidebar-slot--seo">
                    <?php
                    $readabilityCard = [
                        'badgeId' => 'pageReadabilityBadge',
                        'summaryId' => 'pageReadabilitySummary',
                        'metrics' => [
                            ['id' => 'pageWordCount', 'label' => 'Wörter'],
                            ['id' => 'pageDensity', 'label' => 'Keyphrase-Dichte'],
                            ['id' => 'pageInternalLinks', 'label' => 'Interne Links'],
                            ['id' => 'pageExternalLinks', 'label' => 'Externe Links'],
                            ['id' => 'pageTransitionWords', 'label' => 'Signalwörter'],
                            ['id' => 'pageMissingAlt', 'label' => 'Bilder ohne Alt'],
                            ['id' => 'pageLongSentences', 'label' => 'Lange Sätze'],
                            ['id' => 'pageLongParagraphs', 'label' => 'Lange Absätze'],
                        ],
                    ];
                    require __DIR__ . '/../partials/content-readability-card.php';
                    ?>
                </div>

                <div class="col-xl-4 d-flex cms-editor-sidebar-slot cms-editor-sidebar-slot--seo">
                    <?php
                    $previewCard = [
                        'serpTitleId' => 'pageSerpTitle',
                        'serpTitle' => $pageMetaTitleValue ?: $activePageTitleValue,
                        'serpUrlId' => 'pageSerpUrl',
                        'serpUrl' => $activePagePreviewUrl,
                        'serpDescriptionId' => 'pageSerpDescription',
                        'serpDescription' => $pageMetaDescriptionValue ?: 'Meta-Beschreibung wird automatisch aus dem ersten Absatz erzeugt.',
                        'socialImageId' => 'pageSocialImage',
                        'socialImage' => $pageOgImageValue !== '' ? $pageOgImageValue : $pageFeaturedImageValue,
                        'socialImageVisible' => $pageOgImageValue !== '' || $pageFeaturedImageValue !== '',
                        'socialTitleId' => 'pageSocialTitle',
                        'socialTitle' => $pageOgTitleValue !== '' ? $pageOgTitleValue : ($pageMetaTitleValue ?: $activePageTitleValue),
                        'socialDescriptionId' => 'pageSocialDescription',
                        'socialDescription' => $pageOgDescriptionValue !== '' ? $pageOgDescriptionValue : ($pageMetaDescriptionValue ?: 'Social-Vorschau aus SEO-Daten'),
                    ];
                    require __DIR__ . '/../partials/content-preview-card.php';
                    ?>
                </div>

                <div class="col-12 cms-editor-sidebar-slot cms-editor-sidebar-slot--seo">
                    <?php
                    $seoScorePanel = [
                        'badgeId' => 'pageSeoScoreBadge',
                        'scoreLabelId' => 'pageSeoScoreLabel',
                        'scoreBarId' => 'pageSeoScoreBar',
                        'rulesId' => 'pageSeoRules',
                        'hintBadgeContainerId' => 'pageSeoHintBadges',
                        'summaryCards' => [
                            ['width' => 'col-md-3', 'label' => 'Titel', 'valueId' => 'pageTitleCount', 'suffix' => 'Zeichen'],
                            ['width' => 'col-md-3', 'label' => 'Slug', 'valueId' => 'pageSlugCount', 'suffix' => 'Zeichen'],
                            ['width' => 'col-md-3', 'label' => 'Status', 'badgeId' => 'pageStatusBadge', 'badgeText' => 'Entwurf', 'badgeClass' => 'badge bg-yellow-lt text-yellow'],
                            ['width' => 'col-md-3', 'label' => 'Hinweis', 'bodyText' => $isEnglishEditorView ? 'EN-Ansicht mit separatem Save-Flow.' : 'DE-Ansicht mit separatem Save-Flow.'],
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
                                <div class="text-secondary small mt-1">Gespeicherte Snapshots zeigen Unterschiede zwischen aktuellem Stand und früheren Versionen der Seite – inklusive DE/EN-Titel, Slugs und Inhalte.</div>
                            </div>
                            <span class="badge bg-blue-lt text-blue"><?= (int) ($revisionHistory['total'] ?? 0) ?> Revision(en)</span>
                        </summary>
                        <div class="card-body">
                            <?php if ($pageRevisionItems === []): ?>
                                <div class="text-secondary">Noch keine gespeicherten Revisionen vorhanden. Beim nächsten inhaltlichen Speichern wird automatisch ein Snapshot angelegt.</div>
                            <?php else: ?>
                                <div class="alert alert-info" role="status">
                                    Die Ansicht bleibt bewusst read-only: Sie dient dem sicheren Vergleich, ohne versehentlich alte Inhalte direkt zurückzuschreiben. Kleine Zeitmaschine, große Vorsicht. 🕰️
                                </div>
                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($pageRevisionItems as $revision): ?>
                                        <?php
                                        $revisionChangedFields = is_array($revision['changed_fields'] ?? null) ? $revision['changed_fields'] : [];
                                        $revisionFieldDiffs = is_array($revision['field_diffs'] ?? null) ? $revision['field_diffs'] : [];
                                        ?>
                                        <details class="border rounded-3 p-3">
                                            <summary class="d-flex flex-wrap align-items-center gap-2" style="cursor:pointer; list-style:none;">
                                                <strong><?= htmlspecialchars((string) ($revision['created_at_label'] ?? 'Unbekanntes Datum')) ?></strong>
                                                <span class="text-secondary">von <?= htmlspecialchars((string) ($revision['author_label'] ?? 'Unbekannt')) ?></span>
                                                <?php if ($revisionChangedFields !== []): ?>
                                                    <span class="text-secondary">· geändert:</span>
                                                    <?php foreach ($revisionChangedFields as $fieldLabel): ?>
                                                        <span class="badge bg-azure-lt text-azure"><?= htmlspecialchars((string) $fieldLabel) ?></span>
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
                                                                    <h4 class="card-title mb-0"><?= htmlspecialchars((string) ($fieldDiff['label'] ?? 'Unterschied')) ?></h4>
                                                                </div>
                                                                <div class="card-body">
                                                                    <div class="row g-3">
                                                                        <div class="col-md-6">
                                                                            <div class="text-secondary text-uppercase small mb-1"><?= htmlspecialchars((string) ($fieldDiff['current_label'] ?? 'Aktuell')) ?></div>
                                                                            <?php if (($fieldDiff['type'] ?? '') === 'content'): ?>
                                                                                <?php $currentSummary = is_array($fieldDiff['current_summary'] ?? null) ? $fieldDiff['current_summary'] : []; ?>
                                                                                <div class="fw-semibold mb-1"><?= htmlspecialchars((string) ($currentSummary['preview'] ?? '— leer —')) ?></div>
                                                                                <div class="small text-secondary"><?= htmlspecialchars($pageRevisionContentMeta($currentSummary)) ?></div>
                                                                            <?php else: ?>
                                                                                <div class="fw-semibold"><?= htmlspecialchars((string) ($fieldDiff['current_value'] ?? '— leer —')) ?></div>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <div class="col-md-6">
                                                                            <div class="text-secondary text-uppercase small mb-1"><?= htmlspecialchars((string) ($fieldDiff['revision_label'] ?? 'Revision')) ?></div>
                                                                            <?php if (($fieldDiff['type'] ?? '') === 'content'): ?>
                                                                                <?php $revisionSummary = is_array($fieldDiff['revision_summary'] ?? null) ? $fieldDiff['revision_summary'] : []; ?>
                                                                                <div class="fw-semibold mb-1"><?= htmlspecialchars((string) ($revisionSummary['preview'] ?? '— leer —')) ?></div>
                                                                                <div class="small text-secondary"><?= htmlspecialchars($pageRevisionContentMeta($revisionSummary)) ?></div>
                                                                            <?php else: ?>
                                                                                <div class="fw-semibold"><?= htmlspecialchars((string) ($fieldDiff['revision_value'] ?? '— leer —')) ?></div>
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
                                    <div class="text-secondary small mt-3">Aus Performance-Gründen werden hier die letzten <?= (int) ($revisionHistory['displayed'] ?? 0) ?> Revisionen angezeigt.</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
                <?php endif; ?>

                <div class="col-12 cms-editor-sidebar-slot cms-editor-sidebar-slot--seo">
                    <?php
                    $advancedSeoPanel = [
                        'hint' => 'Das Contentheader-Bild wird oben im Formular unter Contentheader Bild gesetzt. Hier kann ein abweichendes OG-Bild für Social Media hinterlegt werden.',
                        'schemaTypeId' => 'pageSchemaType',
                        'schemaTypeName' => 'schema_type',
                        'schemaTypeValue' => \CMS\Services\SEO\SeoSchemaRenderer::selectableTypeFor($pageSchemaTypeValue, 'page'),
                        'schemaTypeOptions' => \CMS\Services\SEO\SeoSchemaRenderer::typeOptionsFor('page'),
                        'sitemapPriorityId' => 'pageSitemapPriority',
                        'sitemapPriorityName' => 'sitemap_priority',
                        'sitemapPriorityValue' => $pageSitemapPriorityValue,
                        'sitemapChangefreqId' => 'pageSitemapChangefreq',
                        'sitemapChangefreqName' => 'sitemap_changefreq',
                        'sitemapChangefreqValue' => $pageSitemapChangefreqValue,
                        'sitemapChangefreqOptions' => ['always', 'daily', 'weekly', 'monthly', 'yearly'],
                        'robotsIndexId' => 'pageRobotsIndex',
                        'robotsIndexName' => 'robots_index',
                        'robotsIndexChecked' => $pageRobotsIndexValue,
                        'robotsFollowId' => 'pageRobotsFollow',
                        'robotsFollowName' => 'robots_follow',
                        'robotsFollowChecked' => $pageRobotsFollowValue,
                        'hreflangGroupId' => 'pageHreflangGroup',
                        'hreflangGroupName' => 'hreflang_group',
                        'hreflangGroupValue' => $pageHreflangGroupValue,
                        'ogTitleId' => 'pageOgTitle',
                        'ogTitleValue' => $pageOgTitleValue,
                        'ogImageId' => 'pageOgImage',
                        'ogImageValue' => $pageOgImageValue,
                        'ogDescriptionId' => 'pageOgDescription',
                        'ogDescriptionValue' => $pageOgDescriptionValue,
                        'twitterTitleId' => 'pageTwitterTitle',
                        'twitterTitleValue' => $pageTwitterTitleValue,
                        'twitterCardId' => 'pageTwitterCard',
                        'twitterCardName' => 'twitter_card',
                        'twitterCardValue' => $pageTwitterCardValue,
                        'twitterCardOptions' => ['summary_large_image', 'summary'],
                        'twitterDescriptionId' => 'pageTwitterDescription',
                        'twitterDescriptionValue' => $pageTwitterDescriptionValue,
                        'twitterImageId' => 'pageTwitterImage',
                        'twitterImageValue' => $pageTwitterImageValue,
                    ];
                    require __DIR__ . '/../partials/content-advanced-seo-panel.php';
                    ?>
                </div>
            </div>
        </form>

        <?php
        $pickerModalId = 'pageFeaturedImageModal';
        $pickerOpenButtonId = 'featuredImageBtn';
        $pickerInputId = 'featuredImageInput';
        $pickerPreviewContainerId = 'featuredImagePreview';
        $pickerRemoveButtonId = 'featuredImageRemove';
        $pickerEmptyStateId = 'featuredImageEmpty';
        $pickerTitleInputId = $activePageTitleInputId;
        $pickerSlugInputId = $activePageSlugInputId;
        $pickerDialogTitle = 'Seitenbild auswählen';
        $pickerIsNew = $isNew;
        $pickerContentType = 'page';
        require __DIR__ . '/../partials/featured-image-picker.php';

        $pageContentUiConfig = [
            'formId' => 'pageForm',
            'titleSelector' => '.page-header .page-title',
            'backLinkId' => 'pageBackToList',
            'removeButtonId' => 'featuredImageRemove',
            'imageInputId' => 'featuredImageInput',
            'tempPathInputId' => 'featuredImageInput_temp_path',
            'previewContainerId' => 'featuredImagePreview',
            'emptyStateId' => 'featuredImageEmpty',
            'slugInputId' => $activePageSlugInputId,
            'previewUrlId' => 'pagePreviewUrl',
            'previewBaseUrl' => '/',
            'previewUrlTemplate' => $activePagePreviewUrlTemplate,
            'statusSelectId' => 'pageStatusSelect',
            'statusBadgeId' => 'pageStatusBadge',
            'templateSelectId' => 'pageTemplateSelect',
            'templateMetaPanelSelector' => '[data-page-template-meta-panel]',
            'templateMetaPanelAttribute' => 'data-page-template-meta-panel',
            'templateMetaEmptySelector' => '[data-page-template-meta-empty]',
            'statusMap' => [
                'draft' => ['label' => 'Entwurf', 'className' => 'badge bg-yellow-lt text-yellow'],
                'published' => ['label' => 'Veröffentlicht', 'className' => 'badge bg-green-lt text-green'],
                'private' => ['label' => 'Privat', 'className' => 'badge bg-purple-lt text-purple'],
            ],
            'countBindings' => [
                ['sourceId' => $activePageTitleInputId, 'targetId' => 'pageTitleCount'],
                ['sourceId' => $activePageSlugInputId, 'targetId' => 'pageSlugCount'],
                ['sourceId' => 'pageMetaTitle', 'targetId' => 'metaTitleCount'],
                ['sourceId' => 'pageMetaDescription', 'targetId' => 'metaDescriptionCount'],
            ],
        ];

        $pageContentSeoConfig = [
            'formId' => 'pageForm',
            'titleId' => $activePageTitleInputId,
            'slugId' => $activePageSlugInputId,
            'metaTitleId' => 'pageMetaTitle',
            'metaDescId' => 'pageMetaDescription',
            'focusKeyphraseId' => 'pageFocusKeyphrase',
            'ogTitleId' => 'pageOgTitle',
            'ogDescriptionId' => 'pageOgDescription',
            'ogImageId' => 'pageOgImage',
            'twitterTitleId' => 'pageTwitterTitle',
            'twitterDescriptionId' => 'pageTwitterDescription',
            'twitterImageId' => 'pageTwitterImage',
            'featuredImageId' => 'featuredImageInput',
            'statusId' => 'pageStatusSelect',
            'contentInputId' => $activePageContentInputId,
            'editorContainerId' => $activePageEditorHolderId,
            'serpTitleId' => 'pageSerpTitle',
            'serpUrlId' => 'pageSerpUrl',
            'serpDescriptionId' => 'pageSerpDescription',
            'scoreBarId' => 'pageSeoScoreBar',
            'scoreLabelId' => 'pageSeoScoreLabel',
            'scoreBadgeId' => 'pageSeoScoreBadge',
            'scoreRulesId' => 'pageSeoRules',
            'socialTitleId' => 'pageSocialTitle',
            'socialDescriptionId' => 'pageSocialDescription',
            'socialImageId' => 'pageSocialImage',
            'publishWarningId' => 'pagePublishWarning',
            'slugStateId' => 'pageSlugState',
            'wordCountId' => 'pageWordCount',
            'densityId' => 'pageDensity',
            'internalLinksId' => 'pageInternalLinks',
            'externalLinksId' => 'pageExternalLinks',
            'transitionWordsId' => 'pageTransitionWords',
            'longSentencesId' => 'pageLongSentences',
            'longParagraphsId' => 'pageLongParagraphs',
            'missingAltId' => 'pageMissingAlt',
            'readabilityBadgeId' => 'pageReadabilityBadge',
            'readabilitySummaryId' => 'pageReadabilitySummary',
            'hintBadgeContainerId' => 'pageSeoHintBadges',
            'overrideNoticeId' => 'pageSeoOverrideNotice',
            'overrideSummaryId' => 'pageSeoOverrideSummary',
            'overrideListId' => 'pageSeoOverrideList',
            'resetMetaTitleId' => 'pageSeoResetMetaTitle',
            'resetMetaDescriptionId' => 'pageSeoResetMetaDescription',
            'resetAllMetaDefaultsId' => 'pageSeoResetAllMetaDefaults',
            'hideTitleId' => 'hideTitle',
            'titleCreatesH1' => true,
            'previewBaseUrl' => '/',
            'previewUrlTemplate' => $activePagePreviewUrlTemplate,
            'previewPlaceholderSlug' => $activePagePreviewSlugFallback,
            'siteName' => (string)SITE_NAME,
            'siteTitleFormat' => (string)($seoTemplateSettings['site_title_format'] ?? '%%title%% %%sep%% %%sitename%%'),
            'titleSeparator' => (string)($seoTemplateSettings['title_separator'] ?? '|'),
            'minWords' => (int)($seoTemplateSettings['analysis_min_words'] ?? 300),
            'maxSentenceWords' => (int)($seoTemplateSettings['analysis_sentence_words'] ?? 24),
            'maxParagraphWords' => (int)($seoTemplateSettings['analysis_paragraph_words'] ?? 120),
            'fallbackImage' => $pageFeaturedImageValue,
        ];

        $pageContentEditorJsConfig = [
            'formId' => 'pageForm',
            'aiSeoMetadata' => $aiSeoMetadataEnabled ? [
                'buttonId' => 'generatePageSeoMetadataButton',
                'endpointUrl' => (string) ($aiSeoMetadataUrl ?? '/admin/ai-generate-seo-metadata'),
                'csrfToken' => (string) ($aiSeoMetadataToken ?? ''),
                'contentType' => 'page',
                'locale' => $editorLocale,
                'sourceEditorKey' => $isEnglishEditorView ? 'en' : 'de',
                'requestTimeoutMs' => 300000,
                'focusKeyphraseId' => 'pageFocusKeyphrase',
                'keywordsId' => 'pageSeoKeywords',
                'metaTitleId' => 'pageMetaTitle',
                'metaDescriptionId' => 'pageMetaDescription',
                'ogTitleId' => 'pageOgTitle',
                'ogDescriptionId' => 'pageOgDescription',
                'twitterTitleId' => 'pageTwitterTitle',
                'twitterDescriptionId' => 'pageTwitterDescription',
                'twitterCardId' => 'pageTwitterCard',
                'schemaTypeId' => 'pageSchemaType',
                'sitemapPriorityId' => 'pageSitemapPriority',
                'sitemapChangefreqId' => 'pageSitemapChangefreq',
                'robotsIndexId' => 'pageRobotsIndex',
                'robotsFollowId' => 'pageRobotsFollow',
            ] : null,
            'aiTranslation' => ($aiTranslationEnabled && $isEnglishEditorView) ? [
                'buttonId' => 'translatePageDeToEnButton',
                'endpointUrl' => (string) ($aiTranslationUrl ?? '/admin/ai-translate-editorjs'),
                'csrfToken' => (string) ($aiTranslationToken ?? ''),
                'contentType' => 'page',
                'sourceLocale' => 'de',
                'targetLocale' => 'en',
                'sourceEditorKey' => 'de',
                'targetEditorKey' => 'en',
                'sourceTitleId' => 'pageTitle',
                'targetTitleId' => 'pageTitleEn',
                'sourceSlugId' => 'pageSlug',
                'targetSlugId' => 'pageSlugEn',
            ] : null,
            'editors' => [],
        ];

        $pageContentEditorJsConfig['mediaUploadUrl'] = '/api/media';
        $pageContentEditorJsConfig['csrfToken'] = $editorMediaToken ?? '';
        $pageContentEditorJsConfig['themeTypography'] = $cmsEditorThemeTypographyConfig();
        $pageContentEditorJsConfig['uploadContext'] = [
            'contentType' => 'page',
            'isNew' => $isNew,
            'draftKey' => $pageEditorDraftKey,
            'slugInputId' => 'pageSlug',
            'slugFallbackInputId' => 'pageSlugEn',
            'titleInputId' => 'pageTitle',
            'titleFallbackInputId' => 'pageTitleEn',
        ];
        $pageContentEditorJsConfig['editors'] = $isEnglishEditorView
            ? [
                ['key' => 'de', 'holderId' => 'pageHiddenEditorDe', 'inputId' => 'editorContent', 'lazy' => true],
                ['key' => 'en', 'holderId' => 'editorjsEn', 'inputId' => 'editorContentEn', 'lazy' => false, 'plainTextareaId' => 'pagePlainEditorEn', 'plainWrapperId' => 'pagePlainEditorWrapEn'],
            ]
            : [
                ['key' => 'de', 'holderId' => 'editorjs', 'inputId' => 'editorContent', 'lazy' => false, 'plainTextareaId' => 'pagePlainEditorDe', 'plainWrapperId' => 'pagePlainEditorWrapDe'],
                ['key' => 'en', 'holderId' => 'pageHiddenEditorEn', 'inputId' => 'editorContentEn', 'lazy' => true],
            ];
        ?>

        <input type="hidden" id="contentEditorUiConfig" value="<?= htmlspecialchars((string) json_encode($pageContentUiConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">
        <input type="hidden" id="contentEditorSeoConfig" value="<?= htmlspecialchars((string) json_encode($pageContentSeoConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">
        <input type="hidden" id="contentEditorEditorJsConfig" value="<?= htmlspecialchars((string) json_encode($pageContentEditorJsConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">

    </div><!-- /.container-xl -->
</div><!-- /.page-body -->
