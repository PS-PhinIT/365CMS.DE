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
                <h2 class="page-title">Sitemap &amp; robots.txt</h2>
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
                                    // Fehlende Dateien sind meist kein Fehler: robots.txt wird dynamisch ausgeliefert,
                                    // das Sitemap-Bundle beim ersten Abruf erzeugt, leere Teil-Sitemaps nicht geschrieben.
                                    $delivery = (string) ($file['delivery'] ?? (!empty($file['exists']) ? 'file' : 'on_demand'));
                                    $statusTooltip = match ($fileName) {
                                        'plugins.xml' => 'Wird erzeugt, sobald aktive Plugins öffentliche Seiten melden',
                                        'en-pages.xml' => 'Wird erzeugt, sobald veröffentlichte Seiten einen englischen Titel und Inhalt haben',
                                        'en-posts.xml' => 'Wird erzeugt, sobald veröffentlichte Beiträge einen englischen Titel und Inhalt haben',
                                        default => 'Teil-Sitemap ohne Einträge – wird beim nächsten Generieren wieder geschrieben, sobald Inhalte vorhanden sind',
                                    };
                                    $statusBadge = ['class' => 'bg-success', 'label' => 'vorhanden'];
                                    if ($delivery !== 'file') {
                                        if (!$isFileEnabled) {
                                            $statusBadge = ['class' => 'bg-secondary', 'label' => 'deaktiviert'];
                                            $statusTooltip = 'Deaktiviert — in Einstellungen aktivieren';
                                        } elseif ($hasGenerationError && $delivery !== 'dynamic') {
                                            $statusBadge = ['class' => 'bg-danger', 'label' => 'fehlt'];
                                            $statusTooltip = 'Generierungsfehler — Logs prüfen';
                                        } elseif ($delivery === 'dynamic') {
                                            $statusBadge = ['class' => 'bg-success', 'label' => 'dynamisch'];
                                            $statusTooltip = 'Keine statische Datei nötig – das CMS liefert die robots.txt bei jedem Abruf aus (Inhalt in den SEO-Einstellungen).';
                                        } elseif ($delivery === 'on_demand') {
                                            $statusBadge = ['class' => 'bg-info', 'label' => 'bei Abruf'];
                                            $statusTooltip = 'Wird beim nächsten Abruf von /sitemap.xml automatisch erzeugt und gespeichert (nach Inhaltsänderungen neu) – oder „Sitemaps generieren“ klicken.';
                                        } else {
                                            $statusBadge = ['class' => 'bg-secondary', 'label' => 'keine Einträge'];
                                        }
                                    } else {
                                        $statusTooltip = '';
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
                                            <span class="badge <?= htmlspecialchars($statusBadge['class']) ?>"<?= $statusTooltip !== '' ? ' title="' . htmlspecialchars($statusTooltip) . '"' : '' ?>><?= htmlspecialchars($statusBadge['label']) ?></span>
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
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">Sitemap bei Suchmaschinen einreichen</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary small">
                            Reichen Sie die Index-Datei einmalig in den Webmaster-Tools ein. Danach rufen die Suchmaschinen sie regelmäßig selbst ab;
                            einzelne Änderungen meldet IndexNow sofort.
                        </p>
                        <div class="mb-3">
                            <div class="small text-secondary mb-1">Sitemap-Index</div>
                            <a href="<?= htmlspecialchars($buildRuntimePublicUrl('sitemap.xml')) ?>" target="_blank" rel="noopener noreferrer">
                                <code><?= htmlspecialchars($buildRuntimePublicUrl('sitemap.xml')) ?></code>
                            </a>
                        </div>
                        <ul class="small mb-3 ps-3">
                            <li><strong>Google:</strong> Search Console → <em>Sitemaps</em> → URL der Index-Datei hinzufügen.</li>
                            <li><strong>Bing:</strong> Bing Webmaster Tools → <em>Sitemaps</em> → Sitemap einreichen (gilt auch für Yahoo und DuckDuckGo).</li>
                            <li><strong>Yandex, Seznam, Naver, Baidu:</strong> jeweils im eigenen Webmaster-Tool unter „Sitemaps“.</li>
                            <li><code>robots.txt</code> verweist automatisch auf <code>sitemap.xml</code> – auch ohne Einreichung wird die Sitemap gefunden.</li>
                        </ul>
                        <a class="btn btn-outline-primary" href="/admin/seo-search-engines">Suchmaschinen &amp; IndexNow verwalten</a>
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
                            Suchmaschinen finden das Bundle über die <code>Sitemap:</code>-Zeile der <code>robots.txt</code> und über die Webmaster-Tools.
                            Den früheren Sitemap-Ping haben Google (2023) und Bing (2022) eingestellt – für sofortige Benachrichtigungen dient IndexNow im Bereich
                            <a href="/admin/seo-search-engines">Suchmaschinen</a>.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
