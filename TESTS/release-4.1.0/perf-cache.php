<?php
/**
 * Release-Prüfung 4.1.0, Performance-Cluster „cache“: Cache-Leeren, HTTP-Validatoren, Asset-Versionen,
 * Sitemap-Bundle, Feeds und Medien-Werkzeuge.
 *
 * Wird von TESTS/release-4.1.0/run.php eingebunden ($src, ABSPATH und Autoloader sind dort definiert).
 */

declare(strict_types=1);

T::run('PERF cache:0: Cache leeren lässt Flags und fremde APCu-Schlüssel stehen', static function () use ($src): void {
    $own = hash('sha512', 'beliebiger-schluessel') . '.cache';
    T::check(\CMS\CacheManager::isOwnCacheFileName($own), 'eigene Cache-Datei (<sha512>.cache) wird erkannt');
    foreach ([
        'db_schema_26.flag',
        'db_runtime_schema_26_3.flag',
        'db_runtime_menu_editor_abc.flag',
        'platform_ok_1a2b3c4d.flag',
        'theme_scan_x.ok',
        'session_gc_lifetime.cache',
        'opcache-warmup.json',
        '.update.lock',
        'sitemap.lock',
        strtoupper(hash('sha512', 'x')) . '.cache',
        hash('sha256', 'x') . '.cache',
        $own . '.bak',
    ] as $foreign) {
        T::check(!\CMS\CacheManager::isOwnCacheFileName($foreign), "{$foreign} bleibt beim Leeren erhalten");
    }

    $cache = $src('core/CacheManager.php');
    T::check(!preg_match('/glob\(\$this->cacheDir \. \'\*\'\)\s*\?:\s*\[\];\s*(?:\$success|\$count)/', $cache), 'clear()/clearAll() löschen nicht mehr cache/* pauschal');
    T::check(substr_count($cache, 'foreach ($this->ownCacheFiles() as $file)') === 2, 'clear() und clearAll() löschen nur eigene Dateien');
    T::check(substr_count($cache, 'apcu_clear_cache();') === 1 && str_contains($cache, "new \\APCUIterator('/^' . preg_quote(self::APCU_PREFIX, '/') . '/'"), 'APCu: nur Namensraum 365cms: (Vollleerung nur als Rückfall ohne APCUIterator)');
    T::check(str_contains($cache, "private const APCU_PREFIX = '365cms:';") && str_contains($cache, 'return self::APCU_PREFIX . hash('), 'Präfix der Schlüssel und des Löschmusters ist dieselbe Konstante');
});

T::run('PERF cache:1: Seiten-Validatoren hängen an der seitenweiten Inhaltsversion', static function () use ($src): void {
    $a = \CMS\Router::buildPublicPageValidatorKey('page', 7, 'de', 1000);
    $b = \CMS\Router::buildPublicPageValidatorKey('page', 7, 'de', 1001);
    T::check($a !== $b, 'neue Inhaltsversion ergibt neuen ETag-Schlüssel');
    T::check($a !== \CMS\Router::buildPublicPageValidatorKey('page', 7, 'en', 1000), 'Sprache bleibt Teil des Schlüssels');
    T::check($a !== \CMS\Router::buildPublicPageValidatorKey('hub', 7, 'de', 1000), 'Typ bleibt Teil des Schlüssels');

    $router = $src('core/Router.php');
    T::check(str_contains($router, '$siteVersion = CacheManager::siteContentVersion();')
        && str_contains($router, 'max($lastModifiedTs, $siteVersion)'), 'Last-Modified = max(updated_at, Inhaltsversion)');
    T::check(str_contains($src('core/Router.php'), "defined('CMS_VERSION')"), 'CMS-Version fließt in das ETag ein (Update ändert Asset-URLs)');

    $cache = $src('core/CacheManager.php');
    T::check(substr_count($cache, 'self::bumpSiteContentVersion();') === 2, 'clear() und clearAll() erhöhen die Inhaltsversion');
    T::check(str_contains($cache, "ON DUPLICATE KEY UPDATE option_value = IF(CAST(option_value AS UNSIGNED) < ?, ?, option_value)"), 'Version steigt nur (Autoload-Option)');

    $boot = $src('core/Bootstrap.php');
    T::check(preg_match("/\\\$this->mode === 'admin'\s*&& !in_array\(strtoupper\(\(string\) \(\\\$_SERVER\['REQUEST_METHOD'\] \?\? 'GET'\)\), \['GET', 'HEAD', 'OPTIONS'\], true\)\)/", $boot) === 1
        && str_contains($boot, 'Auth::isLoggedIn()) {'), 'schreibende Admin-Anfragen (Einstellungen, Menüs, Theme, Plugins) erhöhen die Version nach Abschluss');
    T::check(substr_count($boot, 'CacheManager::bumpSiteContentVersion();') >= 3, 'auch Inhalts-Hooks und geplante Veröffentlichung erhöhen die Version');
});

T::run('PERF cache:6: If-None-Match-Abgleich', static function () use ($src): void {
    $etag = '"abc123"';
    T::check(\CMS\CacheManager::etagMatches('"abc123"', $etag), 'exakt');
    T::check(\CMS\CacheManager::etagMatches('"abc123-gzip"', $etag), 'mod_deflate-Endung -gzip');
    T::check(\CMS\CacheManager::etagMatches('"abc123-br"', $etag), 'mod_brotli-Endung -br');
    T::check(\CMS\CacheManager::etagMatches('W/"abc123"', $etag), 'schwacher Validator');
    T::check(\CMS\CacheManager::etagMatches('"other", W/"abc123-gzip"', $etag), 'Liste');
    T::check(\CMS\CacheManager::etagMatches('*', $etag), 'Stern');
    T::check(!\CMS\CacheManager::etagMatches('"abc1234"', $etag), 'anderes ETag passt nicht');
    T::check(!\CMS\CacheManager::etagMatches('"abc"', $etag), 'Präfix passt nicht');
    T::check(!\CMS\CacheManager::etagMatches('', $etag), 'leerer Header passt nicht');

    $cache = $src('core/CacheManager.php');
    T::check(preg_match('/if \(\$ifNoneMatch !== \'\'\) \{\s*if \(self::etagMatches\(\$ifNoneMatch, \$etag\)\) \{\s*\$this->sendNotModified\(\);\s*return false;\s*\}\s*return true;/', $cache) === 1, 'mit If-None-Match entscheidet nur das ETag (RFC 9110)');

    $feed = $src('core/Services/FeedService.php');
    T::check(str_contains($feed, '$notModified = \CMS\CacheManager::etagMatches($ifNoneMatch, $etag);'), 'Feed nutzt denselben Abgleich');
    T::check(!str_contains($feed, "'  <lastBuildDate>' . gmdate(DATE_RSS) . "), 'lastBuildDate nicht mehr die Uhrzeit des Aufbaus');
    T::check(!str_contains($feed, '$this->sendRssResponse($cached, $cachedMtime);') && !str_contains($feed, '$this->sendRssResponse($xml, time());'), 'Last-Modified nicht mehr Cache-mtime oder time()');

    $newest = \CMS\Services\FeedService::newestFeedItemTimestamp([
        (object) ['updated_at' => '2026-01-02 10:00:00', 'published_at' => '2026-01-01 08:00:00'],
        ['updated_at' => '', 'published_at' => '2026-03-01 09:00:00', 'created_at' => '2025-12-01 00:00:00'],
        (object) ['updated_at' => null],
    ]);
    T::same(strtotime('2026-03-01 09:00:00'), $newest, 'jüngster Beitragszeitpunkt (auch geplante Veröffentlichung)');
    T::same(0, \CMS\Services\FeedService::newestFeedItemTimestamp([]), 'leerer Feed');
    $ts = strtotime('2026-03-01 09:00:00');
    T::same($ts, \CMS\Services\FeedService::extractLastBuildDate("<channel>\n  <lastBuildDate>" . gmdate(DATE_RSS, $ts) . "</lastBuildDate>\n"), 'lastBuildDate aus gespeichertem Feed');
    T::same(0, \CMS\Services\FeedService::extractLastBuildDate('<channel></channel>'), 'ohne lastBuildDate');
});

T::run('PERF cache:7/cwv:3/cache:4: Asset-Versionen und Uploads ohne immutable', static function () use ($src): void {
    $boot = $src('core/Bootstrap.php');
    T::check(!str_contains($boot, "SITE_URL . '/assets/photoswipe/photoswipe.css'") && str_contains($boot, "cms_asset_url('photoswipe/photoswipe.css')"), 'photoswipe.css versioniert');
    T::check(!str_contains($boot, "SITE_URL . '/assets/js/photoswipe-init.js'") && str_contains($boot, "cms_asset_url('js/photoswipe-init.js')"), 'photoswipe-init.js versioniert');
    T::check(preg_match("/new URL\\('\\.\\.\\/photoswipe\\/', import\\.meta\\.url\\)/", $src('assets/js/photoswipe-init.js')) === 1, 'Init-Modul löst die Bibliothek über import.meta.url auf (Query unschädlich)');

    $ht = $src('.htaccess');
    $images = strpos($ht, '<FilesMatch "\.(jpg|jpeg|gif|png|webp|avif|ico|svg)$">');
    $uploads = strpos($ht, 'Header set Cache-Control "public, max-age=86400, stale-while-revalidate=604800" env=CMS_UPLOAD_REQUEST');
    $end = $images !== false ? strpos($ht, '</FilesMatch>', $images) : false;
    T::check($images !== false && $uploads !== false && $end !== false && $uploads > $images && $uploads < $end, 'uploads/-Bilder: kein immutable (Datei ersetzen behält die URL)');
    T::check(str_contains($ht, 'SetEnvIfNoCase Request_URI "(^|/)uploads/" CMS_UPLOAD_REQUEST=1'), 'Umgebungsvariable für uploads/ vorhanden');
});

T::run('PERF cwv:1: EditorJS-Inline-CSS nur noch ohne Dubletten', static function () use ($src): void {
    $boot = $src('core/Bootstrap.php');
    T::check(preg_match("/\\\$criticalCss = '([^']*)';/", $boot, $m) === 1, 'Inline-Regeln vorhanden');
    $inline = $m[1] ?? '';
    T::check(strlen($inline) < 1200, 'Inline-Block unter 1,2 KB (vorher rund 8,8 KB)');
    T::check(!str_contains($boot, '$criticalCss .='), 'keine angehängten Spacer-Dubletten');
    T::check(!str_contains($inline, '.editorjs-spacer[data-height='), 'Spacer-Presets kommen aus editorjs-content.css');
    T::check(str_contains($inline, '.editorjs-block.editorjs-media-text{') && str_contains($inline, 'display:flex'), 'Regeln, die die Datei nicht mit gleichem Selektor setzt, bleiben');
    $css = $src('assets/css/editorjs-content.css');
    T::check(str_contains($css, '.editorjs-spacer[data-height="200"]') && str_contains($css, '.editorjs-block.editorjs-media-text[data-spacing-bottom="100"]'), 'entfernte Regeln stehen in editorjs-content.css');
    T::check(str_contains($boot, "cms_asset_url('css/editorjs-content.css')"), 'Stylesheet wird weiterhin geladen');
});

T::run('PERF cache:5/batch:6: Sitemap-Bundle', static function () use ($src): void {
    $seo = $src('core/Services/SEO/SeoSitemapService.php');
    T::check(str_contains($seo, "'sitemap.lock', 'c')") && str_contains($seo, 'flock($lock, LOCK_EX | LOCK_NB)'), 'Frontend-Neuaufbau unter nicht blockierender Sperre');
    T::check(str_contains($seo, 'flock($lock, LOCK_SH | LOCK_NB)') && str_contains($seo, 'SITEMAP_LOCK_WAIT_US'), 'parallele Anfragen warten begrenzt auf das Ergebnis');
    T::check(str_contains($seo, "'sitemap-build-'") && str_contains($seo, '@rename($buildDir . DIRECTORY_SEPARATOR . $file, ABSPATH . $file)'), 'Arbeitsverzeichnis plus rename()');
    $publish = strpos($seo, 'private function publishSitemapBuild(');
    $body = $publish !== false ? substr($seo, $publish, 1500) : '';
    T::check(strpos($body, "\$built[] = 'sitemap.xml';") !== false && strpos($body, "\$built[] = 'sitemap.xml';") < strpos($body, '@rename('), 'Index wird zuletzt ersetzt');
    T::check(preg_match('/foreach \(\$built as \$file\).*?in_array\(\$file, \$built, true\)/s', $body) === 1, 'veraltete Teile erst nach dem neuen Index entfernen');

    $en = substr($seo, (int) strpos($seo, 'private function getEnglishPageSitemapEntries('), 5000);
    T::check(!preg_match('/SELECT[^"]*\bcontent_en\b[^"]*FROM/s', $en) && !preg_match('/SELECT[^"]*\bexcerpt_en\b[^"]*FROM/s', $en), 'EN-Sitemaps laden keine Inhalte/Auszüge');
    T::check(str_contains($en, "CHAR_LENGTH(TRIM(COALESCE(content_en, ''))) > 0"), 'Filter auf vorhandene EN-Inhalte bleibt');
    T::check(!str_contains($seo, '$this->metaService->getContentMeta('), 'keine seo_meta-Abfrage je Eintrag');
    T::check(str_contains($seo, "\$this->loadSitemapSeoMeta('page') : [];") && str_contains($seo, "\$this->loadSitemapSeoMeta('post') : [];"), 'eine seo_meta-Abfrage je Inhaltstyp');
});

T::run('PERF batch:5: Medien-Werkzeuge', static function () use ($src): void {
    $media = $src('core/Services/MediaService.php');
    T::check(!str_contains($media, 'min(5000, $limit)') && str_contains($media, 'min(self::DERIVATIVE_CANDIDATE_MAX, $limit)'), 'keine stille Kappung bei 5 000');
    T::check(str_contains($media, 'private const DERIVATIVE_CANDIDATE_MAX = 20000;'), 'Obergrenze = MAX_IMAGES');
    T::same(20000, \CMS\Services\Media\ThumbnailRegenerationService::MAX_IMAGES, 'MAX_IMAGES unverändert 20 000');
    T::check(str_contains($media, '$this->derivativeCandidateMemo[$limit] = '), 'Thumbnail- und AVIF-Zählung teilen sich einen Durchlauf');
    T::check(substr_count($media, '$this->derivativeCandidateMemo = [];') >= 2, 'Upload und Löschen verwerfen die gemerkte Liste');
    $loop = substr($media, (int) strpos($media, 'public function collectImageDerivativeProcessingCandidates('), 3500);
    T::check(strpos($loop, 'isGeneratedDerivativeVariant($absolutePath)') < strpos($loop, 'is_readable($absolutePath)'), 'is_readable() erst nach Endungs- und Derivatprüfung');
});
