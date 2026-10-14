<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SEO_VIEW')) {
    exit;
}

$sitemap = $data['sitemap'] ?? [];
$settings = $sitemap['settings'] ?? [];
$files = $sitemap['files'] ?? [];
$counts = $sitemap['counts'] ?? [];
$indexing = $sitemap['indexing'] ?? [];
$runtimeBaseUrl = function_exists('home_url') ? rtrim((string) home_url(), '/') : rtrim((string) SITE_URL, '/');
$buildRuntimePublicUrl = static function (string $path) use ($runtimeBaseUrl): string {
    return $runtimeBaseUrl . '/' . ltrim($path, '/');
};
$hasGenerationError = in_array((string)($alert['type'] ?? ''), ['danger', 'error'], true);
$imageSitemapEnabled = !empty($settings['seo_sitemap_image_enabled']);
$newsSitemapEnabled = !empty($settings['seo_sitemap_news_enabled']);
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">SEO</div>
                <h2 class="page-title">Sitemap &amp; Indexing</h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php
            $alertData = is_array($alert ?? null) ? $alert : [];
            require dirname(__DIR__) . '/partials/flash-alert.php';
            ?>
        <?php endif; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Pages</div><div class="h1 mb-0"><?= (int)($counts['pages'] ?? 0) ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Posts</div><div class="h1 mb-0"><?= (int)($counts['posts'] ?? 0) ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Bild-URLs</div><div class="h1 mb-0"><?= (int)($counts['images'] ?? 0) ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">News-Kandidaten</div><div class="h1 mb-0"><?= (int)($counts['news_candidates'] ?? 0) ?></div></div></div></div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">Dateistatus &amp; öffentliche URLs</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter">
                            <thead>
                                <tr>
                                    <th>Datei</th>
                                    <th>Status</th>
                                    <th>Aktualisiert</th>
                                    <th class="text-end">Größe</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($files as $name => $file): ?>
                                    <?php
                                    $publicFileUrl = $buildRuntimePublicUrl((string) $name);
                                    $fileName = (string)$name;
                                    $isFileEnabled = true;
                                    if ($fileName === 'images.xml') {
                                        $isFileEnabled = $imageSitemapEnabled;
                                    } elseif ($fileName === 'news.xml') {
                                        $isFileEnabled = $newsSitemapEnabled;
                                    }
                                    $hasNoEntriesState = in_array($fileName, ['plugins.xml', 'en-posts.xml'], true);
                                    $statusTooltip = '';
                                    if (empty($file['exists'])) {
                                        if ($hasNoEntriesState && !$hasGenerationError) {
                                            $statusTooltip = $fileName === 'plugins.xml'
                                                ? 'Wird erzeugt, sobald aktive Plugins öffentliche Seiten melden'
                                                : 'Wird erzeugt, sobald veröffentlichte Beiträge einen englischen Titel und Inhalt haben';
                                        } elseif (!$isFileEnabled) {
                                            $statusTooltip = 'Deaktiviert — in Einstellungen aktivieren';
                                        } elseif ($hasGenerationError) {
                                            $statusTooltip = 'Generierungsfehler — Logs prüfen';
                                        } else {
                                            $statusTooltip = 'Noch nicht generiert — Sitemaps generieren klicken';
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <div><code><?= htmlspecialchars((string) $name) ?></code></div>
                                            <div class="text-secondary small">
                                                <a href="<?= htmlspecialchars($publicFileUrl) ?>" target="_blank" rel="noopener noreferrer">
                                                    <?= htmlspecialchars($publicFileUrl) ?>
                                                </a>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($file['exists'])): ?>
                                                <span class="badge bg-success">vorhanden</span>
                                            <?php elseif ($hasNoEntriesState && !$hasGenerationError): ?>
                                                <span class="badge bg-secondary" title="<?= htmlspecialchars($statusTooltip) ?>">keine Einträge</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger" title="<?= htmlspecialchars($statusTooltip) ?>">fehlt</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($file['updated_at'] ?? '—')) ?></td>
                                        <td class="text-end"><?= !empty($file['exists']) ? number_format(((int) $file['size']) / 1024, 1, ',', '.') . ' KB' : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">Sitemap-Defaults</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="save_sitemap_settings">

                            <div class="col-md-6">
                                <label for="seoSitemapPagesPriority" class="form-label">Pages Priority</label>
                                <input id="seoSitemapPagesPriority" class="form-control" type="text" name="pages_priority" value="<?= htmlspecialchars((string) ($settings['pages_priority'] ?? '0.8')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="seoSitemapPagesChangefreq" class="form-label">Pages Changefreq</label>
                                <input id="seoSitemapPagesChangefreq" class="form-control" type="text" name="pages_changefreq" value="<?= htmlspecialchars((string) ($settings['pages_changefreq'] ?? 'weekly')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="seoSitemapPostsPriority" class="form-label">Posts Priority</label>
                                <input id="seoSitemapPostsPriority" class="form-control" type="text" name="posts_priority" value="<?= htmlspecialchars((string) ($settings['posts_priority'] ?? '0.6')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="seoSitemapPostsChangefreq" class="form-label">Posts Changefreq</label>
                                <input id="seoSitemapPostsChangefreq" class="form-control" type="text" name="posts_changefreq" value="<?= htmlspecialchars((string) ($settings['posts_changefreq'] ?? 'monthly')) ?>">
                            </div>
                            <div class="col-md-8">
                                <label for="seoSitemapNewsPublicationName" class="form-label">News-Publikation</label>
                                <input id="seoSitemapNewsPublicationName" class="form-control" type="text" name="news_publication_name" value="<?= htmlspecialchars((string) ($settings['seo_sitemap_news_publication_name'] ?? $settings['news_publication_name'] ?? '365CMS')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="seoSitemapNewsLanguage" class="form-label">News-Sprache</label>
                                <input id="seoSitemapNewsLanguage" class="form-control" type="text" name="news_language" value="<?= htmlspecialchars((string) ($settings['seo_sitemap_news_language'] ?? $settings['news_language'] ?? 'de')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="ping_google" value="1" <?= !empty($settings['ping_google']) ? 'checked' : '' ?>>
                                    <span class="form-check-label">Google pingen</span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="ping_bing" value="1" <?= !empty($settings['ping_bing']) ? 'checked' : '' ?>>
                                    <span class="form-check-label">Bing pingen</span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="image_enabled" value="1" <?= !empty($settings['seo_sitemap_image_enabled']) ? 'checked' : '' ?>>
                                    <span class="form-check-label">`images.xml` aktivieren</span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="news_enabled" value="1" <?= !empty($settings['seo_sitemap_news_enabled']) ? 'checked' : '' ?>>
                                    <span class="form-check-label">`news.xml` aktivieren</span>
                                </label>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">Sitemap-Einstellungen speichern</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title mb-0">IndexNow &amp; Google Submission</h3>
                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                            <span class="badge <?= !empty($indexing['indexnow_available']) ? 'bg-success' : 'bg-warning text-dark' ?>">
                                IndexNow-Key <?= !empty($indexing['indexnow_available']) ? 'bereit' : 'fehlt' ?>
                            </span>
                            <span class="badge <?= !empty($indexing['indexnow_key_file_active']) ? 'bg-primary' : 'bg-secondary' ?>">
                                Keydatei <?= !empty($indexing['indexnow_key_file_active']) ? 'dynamisch aktiv' : 'inaktiv' ?>
                            </span>
                            <span class="badge <?= !empty($indexing['indexnow_ready_for_submission']) ? 'bg-success' : 'bg-warning text-dark' ?>">
                                Prüfung <?= !empty($indexing['indexnow_ready_for_submission']) ? 'ok' : 'offen' ?>
                            </span>
                            <span class="badge <?= !empty($indexing['google_access_token_available']) ? 'bg-success' : 'bg-secondary' ?>">
                                Google-Token <?= !empty($indexing['google_access_token_available']) ? 'gespeichert' : 'fehlt' ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($indexing['indexnow_key_file_active']) && !empty($indexing['indexnow_key_url'])): ?>
                            <div class="alert alert-primary" role="status">
                                <div class="fw-semibold mb-1">IndexNow-Keydatei dynamisch aktiv</div>
                                <div class="small mb-2">Die Verifizierungsdatei wird automatisch vom Core ausgeliefert und ist erreichbar unter:</div>
                                <div><a href="<?= htmlspecialchars((string) $indexing['indexnow_key_url']) ?>" target="_blank" rel="noopener noreferrer"><code><?= htmlspecialchars((string) $indexing['indexnow_key_url']) ?></code></a></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($indexing['indexnow_selected_root_file'])): ?>
                            <div class="alert <?= !empty($indexing['indexnow_selected_root_file_valid']) ? 'alert-success' : 'alert-warning' ?>" role="status">
                                <div class="fw-semibold mb-1">Physische Root-TXT-Datei</div>
                                <div class="small mb-2">
                                    Gewählt: <code><?= htmlspecialchars((string) $indexing['indexnow_selected_root_file']) ?></code>
                                </div>
                                <?php if (!empty($indexing['indexnow_selected_root_file_url'])): ?>
                                    <div>
                                        <a href="<?= htmlspecialchars((string) $indexing['indexnow_selected_root_file_url']) ?>" target="_blank" rel="noopener noreferrer">
                                            <code><?= htmlspecialchars((string) $indexing['indexnow_selected_root_file_url']) ?></code>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($indexing['indexnow_validation_errors'])): ?>
                            <div class="alert alert-warning" role="alert">
                                <div class="fw-semibold mb-1">IndexNow-Prüfung</div>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ((array) $indexing['indexnow_validation_errors'] as $validationError): ?>
                                        <li><?= htmlspecialchars((string) $validationError) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($indexing['indexnow_validation_notes'])): ?>
                            <div class="text-secondary small mb-3">
                                <?php foreach ((array) $indexing['indexnow_validation_notes'] as $validationNote): ?>
                                    <div>• <?= htmlspecialchars((string) $validationNote) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="text-secondary small mb-3">
                            <?php foreach (($indexing['notes'] ?? []) as $note): ?>
                                <div>• <?= htmlspecialchars((string) $note) ?></div>
                            <?php endforeach; ?>
                        </div>

                        <div class="border rounded p-3 mb-3">
                            <div class="fw-semibold mb-2">Google-Access-Token dauerhaft speichern</div>
                            <div class="text-secondary small mb-2">
                                Einmal hinterlegt, wird das Token automatisch für alle Google-Meldungen (inkl. „Kürzlich veröffentlichte Inhalte“) verwendet — eine manuelle Eingabe ist dann nicht mehr nötig. Google-Access-Tokens laufen erfahrungsgemäß nach kurzer Zeit ab und müssen gelegentlich erneuert werden.
                            </div>
                            <form method="post" class="row g-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="save_google_access_token">
                                <div class="col-md-8">
                                    <input class="form-control" type="password" name="google_access_token" aria-label="Google Access-Token" autocomplete="off" placeholder="ya29...">
                                </div>
                                <div class="col-md-4">
                                    <button class="btn btn-outline-primary w-100" type="submit">Token speichern</button>
                                </div>
                            </form>
                            <?php if (!empty($indexing['google_access_token_available'])): ?>
                                <form method="post" class="mt-2" data-cms-confirm="Gespeichertes Google-Token wirklich entfernen?">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="clear_google_access_token">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Gespeichertes Token entfernen</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="submit_indexing_urls">
                            <div class="col-12">
                                <label for="seoIndexingUrls" class="form-label">URLs zur Übermittlung</label>
                                <textarea id="seoIndexingUrls" class="form-control" name="urls" rows="6" placeholder="<?= htmlspecialchars($runtimeBaseUrl) ?>/&#10;<?= htmlspecialchars($runtimeBaseUrl) ?>/blog/beispiel-artikel"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="submission_target[]" value="indexnow" <?= !empty($indexing['indexnow_available']) ? 'checked' : '' ?>>
                                    <span class="form-check-label">An IndexNow senden</span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="submission_target[]" value="google" <?= !empty($indexing['google_access_token_available']) ? 'checked' : '' ?>>
                                    <span class="form-check-label">An Google senden</span>
                                </label>
                            </div>
                            <div class="col-12">
                                <label for="seoIndexingGoogleAccessToken" class="form-label">Google Access-Token (optional)</label>
                                <input id="seoIndexingGoogleAccessToken" class="form-control" type="password" name="google_access_token" autocomplete="off" placeholder="ya29...">
                                <div class="form-hint">Nur für diese Aktion. Leer lassen, um automatisch das dauerhaft gespeicherte Token zu verwenden<?= !empty($indexing['google_access_token_available']) ? ' (aktuell vorhanden)' : ' (aktuell nicht vorhanden)' ?>.</div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">URLs jetzt übermitteln</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title">Kürzlich veröffentlichte Inhalte melden</h3>
                    </div>
                    <div class="card-body">
                        <?php $recentContentAnyTargetAvailable = !empty($indexing['indexnow_available']) || !empty($indexing['google_access_token_available']); ?>
                        <?php if (!$recentContentAnyTargetAvailable): ?>
                            <div class="alert alert-warning mb-3" role="alert">
                                Weder IndexNow noch Google sind aktuell konfiguriert. Bitte zuerst unter „Technisches SEO“ einen IndexNow-API-Key oder oben ein Google-Access-Token hinterlegen.
                            </div>
                        <?php else: ?>
                            <div class="text-secondary small mb-3">
                                Meldet alle veröffentlichten Seiten und Beiträge aus dem gewählten Zeitfenster in einem Schritt an die gewählten Dienste — praktisch nach Importen, Backfills oder Massenänderungen.
                            </div>
                        <?php endif; ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="submit_recent_content_indexnow">
                            <div class="col-md-8">
                                <label for="seoRecentContentRange" class="form-label">Zeitraum</label>
                                <select id="seoRecentContentRange" class="form-select" name="recent_content_range" <?= !$recentContentAnyTargetAvailable ? 'disabled' : '' ?>>
                                    <?php foreach ((array) ($indexing['recent_content_ranges'] ?? []) as $rangeKey => $rangeLabel): ?>
                                        <option value="<?= htmlspecialchars((string) $rangeKey) ?>"><?= htmlspecialchars((string) $rangeLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="recent_content_target[]" value="indexnow" <?= !empty($indexing['indexnow_available']) ? 'checked' : '' ?> <?= empty($indexing['indexnow_available']) ? 'disabled' : '' ?>>
                                    <span class="form-check-label">An IndexNow melden</span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="recent_content_target[]" value="google" <?= !empty($indexing['google_access_token_available']) ? 'checked' : '' ?> <?= empty($indexing['google_access_token_available']) ? 'disabled' : '' ?>>
                                    <span class="form-check-label">An Google melden</span>
                                </label>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button class="btn btn-primary w-100" type="submit" <?= !$recentContentAnyTargetAvailable ? 'disabled' : '' ?>>Jetzt melden</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Google: URL aus Index entfernen</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3" data-cms-confirm="URL wirklich bei Google zur Entfernung aus dem Index melden?">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_google_url">
                            <div class="col-md-7">
                                <label for="seoGoogleDeleteUrl" class="form-label">URL</label>
                                <input id="seoGoogleDeleteUrl" class="form-control" type="url" name="google_delete_url" placeholder="<?= htmlspecialchars($runtimeBaseUrl) ?>/veraltete-seite">
                            </div>
                            <div class="col-md-5">
                                <label for="seoGoogleDeleteAccessToken" class="form-label">Google Access-Token (optional)</label>
                                <input id="seoGoogleDeleteAccessToken" class="form-control" type="password" name="google_access_token" autocomplete="off" placeholder="ya29...">
                                <div class="form-hint">Leer lassen, um das dauerhaft gespeicherte Token zu verwenden.</div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-outline-danger" type="submit">URL bei Google löschen</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">Bundle-Struktur</h3>
                    </div>
                    <div class="card-body">
                        <div class="timeline-simple">
                            <div class="mb-3">
                                <div class="fw-bold"><code>sitemap.xml</code></div>
                                <div class="text-secondary small">Index-Datei mit Verweisen auf alle registrierten Teil-Sitemaps.</div>
                            </div>
                            <div class="mb-3">
                                <div class="fw-bold"><code>pages.xml</code></div>
                                <div class="text-secondary small">Statische Seiten inklusive Homepage.</div>
                            </div>
                            <div class="mb-3">
                                <div class="fw-bold"><code>posts.xml</code></div>
                                <div class="text-secondary small">Beiträge mit `lastMod`, Priority und Changefreq.</div>
                            </div>
                            <div class="mb-3">
                                <div class="fw-bold"><code>en-pages.xml</code></div>
                                <div class="text-secondary small">Englische Homepage und veröffentlichte Seiten mit englischem Titel und Inhalt.</div>
                            </div>
                            <div class="mb-3">
                                <div class="fw-bold"><code>en-posts.xml</code></div>
                                <div class="text-secondary small">Veröffentlichte Beiträge mit englischem Titel und Inhalt sowie ihrer englischen Permalink-URL.</div>
                            </div>
                            <div class="mb-3">
                                <div class="fw-bold"><code>plugins.xml</code></div>
                                <div class="text-secondary small">Öffentliche Plugin-Seiten, die Plugins über den Filter `cms_sitemap_entries` melden (z. B. Message Center, Tools).</div>
                            </div>
                            <div class="mb-3">
                                <div class="fw-bold"><code>images.xml</code></div>
                                <div class="text-secondary small">Bild-Sitemap für Beitrags- und Seitenbilder.</div>
                            </div>
                            <div>
                                <div class="fw-bold"><code>news.xml</code></div>
                                <div class="text-secondary small">Optionale News-Sitemap für aktuelle Artikel.</div>
                            </div>
                        </div>
                        <hr>
                        <div class="small text-secondary">
                            Aktive Endpunkte:
                            <strong><?= htmlspecialchars(implode(', ', (array) ($indexing['engines'] ?? []))) ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
