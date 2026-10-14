<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SEO_VIEW')) {
    exit;
}

$meta = $data['meta'] ?? [];
$settings = $meta['settings'] ?? [];
$examples = $meta['examples'] ?? [];
$previewContexts = is_array($meta['preview_contexts'] ?? null) ? array_values($meta['preview_contexts']) : [];
$siteName = trim((string)($meta['site_name'] ?? (defined('SITE_NAME') ? SITE_NAME : '365CMS')));
$siteName = $siteName !== '' ? $siteName : '365CMS';
$initialPreviewContext = $previewContexts[0] ?? [
    'key' => 'homepage',
    'label' => 'Startseite',
    'title' => (string)($settings['seo_homepage_title'] ?? $siteName),
    'description' => (string)($settings['seo_homepage_description'] ?? $settings['seo_meta_description'] ?? ''),
    'url' => '/',
    'slug' => '',
    'social_image' => (string)($meta['default_social_image'] ?? ''),
];
$resolvePreviewTitle = static function (string $template, string $title, string $siteName, string $separator, string $description = '', string $slug = ''): string {
    $separator = trim($separator) !== '' ? trim($separator) : '|';
    $resolved = str_ireplace(
        ['%%title%%', '%%sitename%%', '%%sep%%', '%%excerpt%%', '%%slug%%'],
        [$title, $siteName, $separator, $description, $slug],
        $template !== '' ? $template : '%%title%% %%sep%% %%sitename%%'
    );
    $resolved = preg_replace('/\s{2,}/', ' ', $resolved) ?? $resolved;
    $escapedSeparator = preg_quote($separator, '/');
    $resolved = preg_replace('/\s*' . $escapedSeparator . '\s*/', ' ' . $separator . ' ', $resolved) ?? $resolved;
    $resolved = preg_replace('/^(?:' . $escapedSeparator . '\s*)+|(?:\s*' . $escapedSeparator . ')+$/', '', $resolved) ?? $resolved;

    return trim($resolved) !== '' ? trim($resolved) : ($title !== '' ? $title : $siteName);
};
$initialPreviewTitleSource = (string)($initialPreviewContext['key'] ?? '') === 'homepage'
    ? trim((string)($settings['seo_homepage_title'] ?? ''))
    : trim((string)($initialPreviewContext['title'] ?? ''));
$initialPreviewTitleSource = $initialPreviewTitleSource !== '' ? $initialPreviewTitleSource : (string)($initialPreviewContext['title'] ?? $siteName);
$initialPreviewDescription = (string)($initialPreviewContext['key'] ?? '') === 'homepage'
    ? trim((string)($settings['seo_homepage_description'] ?? $settings['seo_meta_description'] ?? ''))
    : trim((string)($settings['seo_meta_description'] ?? $initialPreviewContext['description'] ?? ''));
$initialPreviewDescription = $initialPreviewDescription !== ''
    ? $initialPreviewDescription
    : (string)($initialPreviewContext['description'] ?? 'Die globale Meta-Beschreibung wird hier als Vorschau angezeigt.');
$metaPreviewConfig = [
    'siteName' => $siteName,
    'titleFormat' => (string)($settings['seo_site_title_format'] ?? '%%title%% %%sep%% %%sitename%%'),
    'titleSeparator' => (string)($settings['seo_title_separator'] ?? '|'),
    'defaultSocialImage' => (string)($meta['default_social_image'] ?? ''),
    'contexts' => $previewContexts,
];
$metaPreviewConfigJson = json_encode($metaPreviewConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$permalinkService = class_exists('\CMS\Services\PermalinkService') ? \CMS\Services\PermalinkService::getInstance() : null;
$buildRuntimePublicUrl = static function (string $path): string {
    $normalizedPath = '/' . ltrim($path, '/');

    if (function_exists('home_url')) {
        return home_url(ltrim($normalizedPath, '/'));
    }

    return rtrim((string) SITE_URL, '/') . $normalizedPath;
};
?>
<div class="page-header d-print-none admin-redesign-header"><div class="container-xl"><div class="content-listing-header"><div><div class="page-pretitle">SEO</div><h2 class="page-title mb-1">Meta-Daten & Variablen</h2><div class="content-listing-header__meta"><span>Site: <?= htmlspecialchars($siteName) ?></span><span>Kontexte: <?= count($previewContexts) ?></span></div></div><div class="admin-section-toolbar__actions"><a href="/admin/seo-meta?export=posts_csv" class="btn btn-outline-primary btn-sm">Beitrags-Metadaten als CSV</a><a href="/admin/seo-audit" class="btn btn-outline-secondary btn-sm">Audit</a><a href="/admin/seo-social" class="btn btn-outline-primary btn-sm">Social</a></div></div></div></div>
<div class="page-body admin-redesign-page"><div class="container-xl admin-redesign-shell">
    <?php if (!empty($alert)): ?>
        <?php
        $alertData = is_array($alert ?? null) ? $alert : [];
        require dirname(__DIR__) . '/partials/flash-alert.php';
        ?>
    <?php endif; ?>
    <?php require __DIR__ . '/subnav.php'; ?>
    <?php if (is_string($metaPreviewConfigJson) && $metaPreviewConfigJson !== ''): ?>
        <input type="hidden" id="seoMetaPreviewConfig" value="<?= htmlspecialchars($metaPreviewConfigJson, ENT_QUOTES) ?>">
    <?php endif; ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card h-100"><div class="card-header"><h3 class="card-title">Globale Meta-Defaults</h3></div><div class="card-body">
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_meta_defaults">
                    <div class="col-12"><label class="form-label">Homepage-Titel</label><input class="form-control" id="seoHomepageTitle" type="text" name="homepage_title" value="<?= htmlspecialchars((string)($settings['seo_homepage_title'] ?? '')) ?>"></div>
                    <div class="col-12"><label class="form-label">Homepage-Beschreibung</label><textarea class="form-control" id="seoHomepageDescription" name="homepage_description" rows="3"><?= htmlspecialchars((string)($settings['seo_homepage_description'] ?? '')) ?></textarea></div>
                    <div class="col-12"><label class="form-label">Globale Meta-Beschreibung</label><textarea class="form-control" id="seoGlobalMetaDescription" name="meta_description" rows="3"><?= htmlspecialchars((string)($settings['seo_meta_description'] ?? '')) ?></textarea></div>
                    <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="default_robots_index" value="1" <?= !empty($settings['seo_default_robots_index']) ? 'checked' : '' ?>><span class="form-check-label">Standard: index</span></label></div>
                    <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="default_robots_follow" value="1" <?= !empty($settings['seo_default_robots_follow']) ? 'checked' : '' ?>><span class="form-check-label">Standard: follow</span></label></div>
                    <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="self_referencing_canonical" value="1" <?= !empty($settings['seo_self_referencing_canonical']) ? 'checked' : '' ?>><span class="form-check-label">Self-referencing Canonical automatisch setzen</span></label></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Meta-Defaults speichern</button></div>
                </form>
            </div></div>
        </div>
        <div class="col-lg-5">
            <div class="card mb-4"><div class="card-header"><h3 class="card-title">Variablen</h3></div><div class="list-group list-group-flush">
                <?php foreach (($meta['variables'] ?? []) as $variable): ?>
                    <div class="list-group-item"><code><?= htmlspecialchars((string)$variable['token']) ?></code><div class="text-secondary small"><?= htmlspecialchars((string)$variable['description']) ?></div></div>
                <?php endforeach; ?>
            </div></div>
            <div class="card mb-4"><div class="card-header"><h3 class="card-title">Globaler Vorschau-Modus</h3></div><div class="card-body">
                <p class="text-secondary small mb-3">Testet live, wie globale Meta-Defaults im Editor-freien Kontext für Startseite, Archive und Taxonomien wirken – ohne zusätzlichen Schreibpfad und ohne Token in URLs.</p>
                <div class="btn-list mb-3" role="group" aria-label="Preview-Kontexte">
                    <?php foreach ($previewContexts as $index => $previewContext): ?>
                        <button type="button"
                                class="btn <?= $index === 0 ? 'btn-primary active' : 'btn-outline-primary' ?>"
                                data-seo-meta-context="<?= htmlspecialchars((string)($previewContext['key'] ?? '')) ?>"
                                aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>">
                            <?= htmlspecialchars((string)($previewContext['label'] ?? 'Vorschau')) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex flex-column gap-1 mb-3">
                    <div class="text-uppercase text-secondary small">Aktiver Kontext</div>
                    <div class="fw-semibold" id="seoMetaPreviewContextLabel"><?= htmlspecialchars((string)($initialPreviewContext['label'] ?? 'Startseite')) ?></div>
                    <div class="small text-secondary" id="seoMetaPreviewContextNote">Die Vorschau reagiert live auf Homepage-Titel, Titel-Template, Separator und globale Meta-Beschreibung.</div>
                    <div class="small text-success" id="seoMetaPreviewContextUrl"><?= htmlspecialchars((string)($initialPreviewContext['url'] ?? '/')) ?></div>
                </div>
                <?php
                $previewCard = [
                    'serpTitleId' => 'seoMetaSerpTitle',
                    'serpUrlId' => 'seoMetaSerpUrl',
                    'serpDescriptionId' => 'seoMetaSerpDescription',
                    'serpTitle' => $resolvePreviewTitle(
                        (string)($settings['seo_site_title_format'] ?? '%%title%% %%sep%% %%sitename%%'),
                        $initialPreviewTitleSource,
                        $siteName,
                        (string)($settings['seo_title_separator'] ?? '|'),
                        $initialPreviewDescription,
                        (string)($initialPreviewContext['slug'] ?? '')
                    ),
                    'serpUrl' => (string)($initialPreviewContext['url'] ?? '/'),
                    'serpDescription' => $initialPreviewDescription,
                    'socialImageId' => 'seoMetaSocialImage',
                    'socialImage' => (string)($initialPreviewContext['social_image'] ?? $meta['default_social_image'] ?? ''),
                    'socialTitleId' => 'seoMetaSocialTitle',
                    'socialTitle' => $resolvePreviewTitle(
                        (string)($settings['seo_site_title_format'] ?? '%%title%% %%sep%% %%sitename%%'),
                        $initialPreviewTitleSource,
                        $siteName,
                        (string)($settings['seo_title_separator'] ?? '|'),
                        $initialPreviewDescription,
                        (string)($initialPreviewContext['slug'] ?? '')
                    ),
                    'socialDescriptionId' => 'seoMetaSocialDescription',
                    'socialDescription' => $initialPreviewDescription,
                    'socialImageVisible' => trim((string)($initialPreviewContext['social_image'] ?? $meta['default_social_image'] ?? '')) !== '',
                ];
                require dirname(__DIR__) . '/partials/content-preview-card.php';
                ?>
            </div></div>
            <div class="card"><div class="card-header"><h3 class="card-title">SERP-Beispiele</h3></div><div class="card-body">
                <?php foreach ($examples as $example): ?>
                    <?php
                    $examplePath = (string)(($example['type'] ?? '') === 'post'
                        ? (($permalinkService !== null && (!empty($example['published_at']) || !empty($example['created_at'])))
                            ? $permalinkService->buildPostPathFromValues((string)($example['slug'] ?? ''), (string)($example['published_at'] ?? ''), (string)($example['created_at'] ?? ''))
                            : '/blog/' . ltrim((string)($example['slug'] ?? ''), '/'))
                        : '/' . ltrim((string)($example['slug'] ?? ''), '/'));
                    $exampleUrl = $buildRuntimePublicUrl($examplePath);
                    ?>
                    <div class="border rounded p-3 mb-3 bg-light">
                        <div class="text-primary fw-semibold mb-1"><?= htmlspecialchars((string)($example['resolved_meta_title'] ?? '')) ?></div>
                        <div class="small text-success mb-1"><?= htmlspecialchars($exampleUrl) ?></div>
                        <div class="small text-secondary"><?= htmlspecialchars((string)($example['resolved_meta_description'] ?? '')) ?></div>
                    </div>
                <?php endforeach; ?>
            </div></div>
        </div>
        <div class="col-12">
            <div class="card"><div class="card-header"><h3 class="card-title">Titel-Template & Analyse-Regeln</h3></div><div class="card-body">
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_templates">
                    <div class="col-lg-5"><label class="form-label">Titel-Template</label><input class="form-control" id="seoSiteTitleFormat" type="text" name="site_title_format" value="<?= htmlspecialchars((string)($settings['seo_site_title_format'] ?? '%%title%% %%sep%% %%sitename%%')) ?>"></div>
                    <div class="col-lg-2"><label class="form-label">Separator</label><input class="form-control" id="seoTitleSeparator" type="text" name="title_separator" value="<?= htmlspecialchars((string)($settings['seo_title_separator'] ?? '|')) ?>"></div>
                    <div class="col-lg-2"><label class="form-label">Min. Wörter</label><input class="form-control" type="number" name="analysis_min_words" min="100" value="<?= (int)($settings['seo_analysis_min_words'] ?? 300) ?>"></div>
                    <div class="col-lg-3"><label class="form-label">Max. Wörter pro Satz</label><input class="form-control" type="number" name="analysis_sentence_words" min="12" value="<?= (int)($settings['seo_analysis_sentence_words'] ?? 24) ?>"></div>
                    <div class="col-lg-3"><label class="form-label">Max. Wörter pro Absatz</label><input class="form-control" type="number" name="analysis_paragraph_words" min="40" value="<?= (int)($settings['seo_analysis_paragraph_words'] ?? 120) ?>"></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Template speichern</button></div>
                </form>
            </div></div>
        </div>
        <div class="col-12">
            <div class="card" id="post-seo-meta-csv"><div class="card-header"><div><h3 class="card-title mb-1">SEO-Metadaten der Beiträge per CSV</h3><div class="text-secondary small">Alle Beitrags-Metadaten exportieren, in Excel o. Ä. anpassen und wieder importieren. Titel, Slug, Inhalt, Auszug und Status der Beiträge werden dabei nicht verändert.</div></div></div><div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-4">
                        <h4 class="mb-2">1. Exportieren</h4>
                        <p class="text-secondary small mb-3">CSV mit allen Beiträgen (ohne Papierkorb), Semikolon-getrennt, UTF-8.</p>
                        <a href="/admin/seo-meta?export=posts_csv" class="btn btn-outline-primary">CSV exportieren</a>
                    </div>
                    <div class="col-lg-8">
                        <h4 class="mb-2">2. Bearbeitete CSV importieren</h4>
                        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="import_post_meta_csv">
                            <div class="col-md-8">
                                <label class="form-label" for="post-meta-csv-file">CSV-Datei</label>
                                <input type="file" class="form-control" id="post-meta-csv-file" name="post_meta_csv" accept=".csv,text/csv" required>
                            </div>
                            <div class="col-md-4 d-grid"><button type="submit" class="btn btn-primary">CSV importieren</button></div>
                            <div class="col-12 form-hint">
                                Zuordnung über <code>post_id</code> (alternativ <code>slug</code>). Die Spalten <code>slug</code>, <code>title</code>, <code>status</code> und <code>url</code> dienen nur zur Orientierung und werden nicht geschrieben.
                                Importiert werden: <code>meta_title</code>, <code>meta_description</code>, <code>focus_keyphrase</code>, <code>keywords</code>, <code>canonical_url</code>, <code>robots_index</code>, <code>robots_follow</code>, <code>og_*</code>, <code>twitter_*</code>, <code>schema_type</code>, <code>sitemap_priority</code>, <code>sitemap_changefreq</code>, <code>hreflang_group</code>.
                                Fehlende Spalten bleiben unverändert, leere Zellen setzen das Feld auf den Standard zurück. Maximal 5 MB / 5.000 Zeilen.
                            </div>
                        </form>
                    </div>
                </div>
            </div></div>
        </div>
    </div>
</div></div>
