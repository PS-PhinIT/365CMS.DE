<?php
/**
 * Sicherheitsprüfungen „Dateien, Zugriff, Ausgabe“ (ohne Datenbank): Medienimport des Content-Exchange,
 * Papierkorb-Filter der Hub-Sites, übernommene Download-Dateien, 404 für unveröffentlichte Inhalte,
 * JSON-LD-Maskierung, CSRF im Importer und LIKE-Platzhalter im Installer.
 * Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Install\InstallerService;
use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\ContentTypes\ImportPreview;
use CMS\Services\ContentTypes\ImportResult;
use CMS\Services\Downloads\DownloadService;
use CMS\Services\Exchange\MediaExchangeProvider;
use CMS\Services\MediaService;
use CMS\WP_Error;

$faWork = sys_get_temp_dir() . '/cms-tests-files-access-' . getmypid();
@mkdir($faWork . '/uploads', 0775, true);
@mkdir($faWork . '/stage/files/media/bilder', 0775, true);
@mkdir($faWork . '/stage/files/media/fonts', 0775, true);
@mkdir($faWork . '/stage/files/media/member/user-1', 0775, true);
register_shutdown_function(static function () use ($faWork): void {
    if (!is_dir($faWork)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($faWork, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($faWork);
});

if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', $faWork . '/uploads/');
}
if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', 'https://example.test/uploads');
}
if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://example.test');
}
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

/** Abschnitt einer Quelldatei zwischen zwei Markern (für Prüfungen, die ohne Datenbank nur am Quelltext möglich sind). */
$faSlice = static function (string $file, string $from, string $to): string {
    $source = (string) file_get_contents(ABSPATH . $file);
    $start = strpos($source, $from);
    if ($start === false) {
        return '';
    }
    $end = strpos($source, $to, $start + strlen($from));

    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
};

// Kleinste gültige Dateien für die Inhaltsprüfung.
$faPng = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$faPdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$faHtml = '<html><body><script>alert(1)</script></body></html>';

T::run('Medienimport: Whitelist und Inhaltsprüfung des Uploads gelten auch für lokale Dateien', static function () use ($faWork, $faPng, $faPdf, $faHtml): void {
    $service = new MediaService();
    $file = static function (string $name, string $content) use ($faWork): string {
        $path = $faWork . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    };

    T::check(is_array($service->validateLocalFile($file('ok.png', $faPng), 'ok.png')), 'echtes PNG wird akzeptiert');
    T::check(is_array($service->validateLocalFile($file('ok.pdf', $faPdf), 'ok.pdf')), 'PDF wird akzeptiert');
    T::check($service->validateLocalFile($file('fake.jpg', $faHtml), 'bild.jpg') instanceof WP_Error, 'HTML-Inhalt mit Endung .jpg wird abgelehnt');
    T::check($service->validateLocalFile($file('fake.txt', $faHtml), 'notiz.txt') instanceof WP_Error, 'HTML-Inhalt mit Endung .txt wird abgelehnt (MIME-Abweichung)');
    T::check($service->validateLocalFile($file('ok2.png', $faPng), 'shell.php.png') instanceof WP_Error, 'Doppelendung .php.png wird abgelehnt');
    T::check($service->validateLocalFile($faWork . '/fehlt.png', 'fehlt.png') instanceof WP_Error, 'fehlende Datei wird abgelehnt');
    T::check(
        $service->validateLocalFile($file('ok3.png', $faPng), 'shell.php.png', ['block_dangerous_types' => false]) instanceof WP_Error,
        'Sperrliste lässt sich für lokale Dateien nicht über die Einstellungen abschalten'
    );

    foreach (['info.xhtml', 'x.xht', 'x.xml', 'x.svgz', 'p.mjs', 'x.shtml', 'x.phps', 'x.css', 'x.svg', 'x.html', 'x.js', 'x.php', 'ohne-endung'] as $name) {
        T::check(!$service->isAllowedFileName($name), 'Endung nicht erlaubt: ' . $name);
    }
    foreach (['bild.jpg', 'bild.PNG', 'doku.pdf', 'film.mp4'] as $name) {
        T::check($service->isAllowedFileName($name), 'Endung erlaubt: ' . $name);
    }
    T::check(!$service->isAllowedFileName('film.mp4', ['allowed_types' => ['image']]), 'Whitelist folgt den Medien-Einstellungen (allowed_types)');

    foreach (['fonts/x.woff2', 'member/user-1/a.pdf', 'form-uploads/x.pdf', 'dl-manager/a/b.zip', 'themes/x.png', 'plugins/x.png', 'assets/x.png', 'import/x.zip', 'Fonts/x.png'] as $path) {
        T::check($service->isInSystemFolder($path), 'Systemordner erkannt: ' . $path);
    }
    foreach (['bilder/fonts/x.png', 'x.png', 'memberfotos/x.png'] as $path) {
        T::check(!$service->isInSystemFolder($path), 'kein Systemordner: ' . $path);
    }
});

T::run('Medienimport: Content-Exchange lehnt unerlaubte Typen, Inhalte und Zielordner ab', static function () use ($faWork, $faPng, $faPdf, $faHtml): void {
    $stage = $faWork . '/stage';
    $files = [
        'bilder/info.xhtml' => '<html xmlns="http://www.w3.org/1999/xhtml"><script src="/uploads/bilder/p.mjs"/></html>',
        'bilder/p.mjs' => 'alert(1)',
        'bilder/shell.php.jpg' => $faPng,
        'bilder/bild.jpg' => $faHtml,
        'fonts/x.css' => 'body{}',
        'fonts/logo.png' => $faPng,
        'member/user-1/a.pdf' => $faPdf,
        'bilder/echt.png' => $faPng,
        'bilder/doku.pdf' => $faPdf,
        'bilder/vorhanden.png' => $faPng,
    ];
    foreach ($files as $path => $content) {
        file_put_contents($stage . '/files/media/' . $path, $content);
    }
    @mkdir(rtrim((string) UPLOAD_PATH, '/') . '/bilder', 0775, true);
    file_put_contents(rtrim((string) UPLOAD_PATH, '/') . '/bilder/vorhanden.png', $faPng);

    $provider = new MediaExchangeProvider();
    $context = new ImportContext(ImportContext::STRATEGY_OVERWRITE, 1, false, [], $stage);
    $record = static fn (string $path): array => ['path' => $path, 'has_file' => true, 'sha256' => hash('sha256', $files[$path])];

    foreach (['bilder/info.xhtml', 'bilder/p.mjs', 'bilder/shell.php.jpg', 'bilder/bild.jpg', 'fonts/x.css', 'fonts/logo.png', 'member/user-1/a.pdf'] as $path) {
        T::same(ImportPreview::ACTION_INVALID, $provider->preview($record($path), $context)->action, 'Vorschau ungültig: ' . $path);
        T::same(ImportResult::FAILED, $provider->import($record($path), $context)->status, 'Import fehlgeschlagen: ' . $path);
        T::check(!file_exists(rtrim((string) UPLOAD_PATH, '/') . '/' . $path), 'keine Datei angelegt: ' . $path);
    }
    T::same(ImportPreview::ACTION_INVALID, $provider->preview(['path' => '.versteckt/bild.png', 'has_file' => false], $context)->action, 'versteckter Ordner wird abgelehnt');

    T::same(ImportPreview::ACTION_CREATE, $provider->preview($record('bilder/echt.png'), $context)->action, 'echtes PNG: Vorschau „anlegen“');
    T::same(ImportPreview::ACTION_CREATE, $provider->preview($record('bilder/doku.pdf'), $context)->action, 'PDF: Vorschau „anlegen“');
    T::same(ImportPreview::ACTION_CONFLICT, $provider->preview($record('bilder/vorhanden.png'), $context)->action, 'vorhandene Datei: Vorschau „Konflikt“ (Strategien unverändert)');
});

T::run('Hub-Sites: Abruf über die ID ignoriert den Papierkorb', static function () use ($faSlice): void {
    $method = $faSlice('core/Services/SiteTable/SiteTableRepository.php', 'public function getTableById(', 'public function getHubTableBySlug(');
    T::check(preg_match('/WHERE\s+id\s*=\s*\?\s+AND\s+trashed_at\s+IS\s+NULL/i', $method) === 1, 'getTableById() filtert trashed_at IS NULL');

    $service = (string) file_get_contents(ABSPATH . 'core/Services/SiteTableService.php');
    T::check(substr_count($service, '$this->repository->getTableById(') >= 3, 'Shortcode-Rendering und Export laufen über getTableById()');
    T::check(preg_match('/FROM\s+\{[^}]+\}site_tables/i', $service) !== 1, 'SiteTableService fragt site_tables nicht am Repository vorbei ab');
});

T::run('Downloads: übernommene Dateien werden geprüft in den privaten Speicher verschoben', static function () use ($faWork, $faSlice): void {
    @mkdir($faWork . '/legacy', 0775, true);
    @mkdir($faWork . '/private', 0775, true);
    $source = $faWork . '/legacy/handbuch.pdf';
    file_put_contents($source, 'Inhalt des Handbuchs');
    $hash = hash('sha256', 'Inhalt des Handbuchs');

    T::check(DownloadService::moveFileVerified($source, $faWork . '/private/a1.pdf'), 'Verschieben gelingt');
    T::check(!file_exists($source), 'Original unter uploads/downloads ist entfernt');
    T::same($hash, (string) hash_file('sha256', $faWork . '/private/a1.pdf'), 'Prüfsumme der verschobenen Datei stimmt');

    file_put_contents($source, 'Inhalt des Handbuchs');
    T::check(!DownloadService::moveFileVerified($source, $faWork . '/gibt-es-nicht/a2.pdf'), 'nicht beschreibbares Ziel: Fehler');
    T::check(is_file($source), 'Original bleibt bei Fehler erhalten');
    T::check(!DownloadService::moveFileVerified($source, $faWork . '/private/a1.pdf'), 'vorhandene Zieldatei wird nicht überschrieben');
    T::check(is_file($source), 'Original bleibt auch dann erhalten');
    T::check(!DownloadService::moveFileVerified($faWork . '/legacy/fehlt.pdf', $faWork . '/private/a3.pdf'), 'fehlende Quelle: Fehler');

    file_put_contents($faWork . '/legacy/kopie.pdf', 'Inhalt');
    T::check(DownloadService::copyFileVerified($faWork . '/legacy/kopie.pdf', $faWork . '/private/k1.pdf') && is_file($faWork . '/legacy/kopie.pdf'), 'geprüftes Kopieren lässt das Original liegen');
    $privatize = $faSlice('core/Services/Downloads/DownloadService.php', 'public function privatizeLegacyFiles(', 'public function moveToPrivateStorage(');
    $copyAt = strpos($privatize, '$this->copyToPrivateStorage(');
    $updateAt = strpos($privatize, '$this->repo->updateVersion(');
    $unlinkAt = strpos($privatize, '@unlink($source)');
    T::check(
        $copyAt !== false && $updateAt !== false && $unlinkAt !== false && $copyAt < $updateAt && $updateAt < $unlinkAt
            && !str_contains($privatize, 'moveToPrivateStorage('),
        'Altdatei: erst kopieren, dann Datensatz umstellen, zuletzt das Original löschen'
    );

    $afterSave = $faSlice('core/Services/Downloads/DownloadService.php', 'private function afterSave(', 'private function validateItem(');
    T::check(str_contains($afterSave, '$this->privatizeLegacyFiles($id)'), 'jedes Speichern eines nicht öffentlichen Downloads verschiebt Altdateien');
    T::check(str_contains($afterSave, "!== 'public'") && str_contains($afterSave, "!== 'published'"), 'Auslöser sind Zugriffsstufe und Status');
    $trash = $faSlice('core/Services/Downloads/DownloadService.php', 'public function trash(', 'public function restore(');
    T::check(str_contains($trash, '$this->privatizeLegacyFiles($id)'), 'auch der Papierkorb verschiebt Altdateien');
    $migrator = (string) file_get_contents(ABSPATH . 'core/Services/PluginMigration/DownloadsPluginMigrator.php');
    T::check(str_contains($migrator, '$restricted || !$published);'), 'Übernahme: auch unveröffentlichte Plugin-Downloads landen im privaten Speicher');
    $sweep = $faSlice('core/Services/Downloads/DownloadService.php', 'public function privatizeRestrictedLegacyFiles(', 'public function privatizeLegacyFiles(');
    T::check(
        str_contains($sweep, "i.access_level <> 'public' OR i.status <> 'published'") && str_contains($sweep, '$this->privatizeLegacyFiles('),
        'Nachlauf erfasst Bestände, die schon beschränkt oder unveröffentlicht sind'
    );
    T::check(
        str_contains($sweep, '$timeBudget') && str_contains($sweep, 'v.download_id > ?') && str_contains($sweep, "'complete' => false"),
        'Nachlauf hat ein Zeitbudget und blättert über alle Treffer'
    );
    T::check(
        str_contains((string) file_get_contents(ABSPATH . 'admin/modules/downloads/DownloadsModule.php'), "->privatizeRestrictedLegacyFiles()['complete']"),
        'Nachlauf wird im Admin-Bereich Downloads ausgelöst; die Sitzung gilt erst nach vollständigem Lauf als geprüft'
    );
    foreach (['setAccess' => 'public function trash(', 'setStatus' => 'public function setCategory('] as $method => $next) {
        T::check(str_contains($faSlice('core/Services/Downloads/DownloadService.php', 'public function ' . $method . '(', $next), '$this->afterSave($id'), $method . '() läuft über afterSave()');
    }
});

T::run('Unveröffentlichte Inhalte: 404 statt Login-Redirect für Anonyme', static function () use ($faSlice): void {
    $blog = $faSlice('core/Routing/ThemeRouter.php', "\$postStatus = (string) (\$postRow->status ?? '');", '$this->sendDraftPreviewHeaders();');
    T::check($blog !== '', 'Statusprüfung in renderBlogSingle() gefunden');
    T::same(1, substr_count($blog, 'buildLocalizedLoginRedirectPath()'), 'Beitrag: Login-Redirect nur noch im Zweig „private“');
    $draftBranch = substr($blog, (int) strpos($blog, 'cms_post_is_publicly_visible'));
    T::check(!str_contains($draftBranch, 'redirect('), 'Beitrag (Entwurf/geplant): kein Redirect');
    T::check(str_contains($draftBranch, 'canCurrentViewerAccessUnpublishedContent') && str_contains($draftBranch, 'render404()'), 'Beitrag (Entwurf/geplant): Berechtigungsprüfung mit 404');

    $page = $faSlice('core/Routing/ThemeRouter.php', 'private function renderPageFallback(', '$this->sendDraftPreviewHeaders();');
    T::same(1, substr_count($page, 'buildLocalizedLoginRedirectPath()'), 'Seite: Login-Redirect nur noch im Zweig „private“');
    $draftBranch = substr($page, (int) strpos($page, "\$pageStatus !== 'published'"));
    T::check(!str_contains($draftBranch, 'redirect(') && str_contains($draftBranch, 'return false;'), 'Seite (Entwurf): kein Redirect, Aufrufer rendert 404');

    $viewer = $faSlice('core/Routing/ThemeRouter.php', 'private function canCurrentViewerAccessUnpublishedContent(', 'private function sendDraftPreviewHeaders(');
    T::check(preg_match('/if \(\$viewerId <= 0\) \{\s+return false;/', $viewer) === 1, 'Anonyme gelten als nicht berechtigt');
    T::check(str_contains($viewer, '$authorId === $viewerId') && str_contains($viewer, 'hasCapability($capability)'), 'Vorschau für Autor und Berechtigte bleibt erhalten');
});

T::run('JSON-LD: Ausgaben maskieren <, >, & (kein Ausbruch aus dem Script-Block)', static function (): void {
    $controller = (new ReflectionClass(\CMS\Services\Knowledgebase\Http\PublicController::class))->newInstanceWithoutConstructor();
    $build = new ReflectionMethod($controller, 'buildStructuredDataJson');
    $title = 'Das Tag </script><meta http-equiv=refresh content="0"> & <!-- \'x\'';
    $json = (string) $build->invoke($controller, ['title' => $title, 'excerpt' => 'Kurz & knapp'], 'https://example.test/kb/x', 'de');
    T::check($json !== '', 'Knowledgebase liefert JSON-LD');
    foreach (['<', '>', '&', "'"] as $char) {
        T::check(!str_contains($json, $char), 'Knowledgebase-JSON-LD enthält kein rohes ' . $char);
    }
    $decoded = json_decode($json, true);
    T::same($title, $decoded['@graph'][0]['name'] ?? null, 'Inhalt bleibt nach dem Dekodieren unverändert');
    T::same('Kurz & knapp', $decoded['@graph'][0]['description'] ?? null, 'Beschreibung bleibt unverändert');

    // Jede Core-Stelle, die einen JSON-LD-Block ausgibt, muss JSON_HEX_TAG und JSON_HEX_AMP setzen.
    $checked = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if ($file->getExtension() !== 'php' || str_contains($path, '/assets/') || str_contains($path, '/vendor/')) {
            continue;
        }
        $source = (string) file_get_contents($path);
        if (!str_contains($source, '<script type="application/ld+json"')) {
            continue;
        }
        $checked++;
        $relative = substr($path, strlen(ABSPATH));
        if ($relative === 'views/knowledgebase/single.php') {
            // Gibt nur den vom Controller kodierten String aus (oben geprüft).
            T::check(!str_contains($source, 'json_encode('), $relative . ' kodiert nicht selbst');
            continue;
        }
        T::check(str_contains($source, 'JSON_HEX_TAG') && str_contains($source, 'JSON_HEX_AMP'), $relative . ' setzt JSON_HEX_TAG | JSON_HEX_AMP');
    }
    T::check($checked >= 4, 'JSON-LD-Ausgabestellen gefunden (' . $checked . ')');
});

T::run('cms-importer: synchroner Upload prüft das CSRF-Token vor der Datei', static function () use ($faSlice): void {
    $method = $faSlice('plugins/cms-importer/includes/class-admin.php', 'private function process_upload_request(): array', 'move_uploaded_file(');
    $token = strpos($method, "is_valid_request_token((string) (\$_POST['_nonce'] ?? ''), 'cms-importer-upload')");
    $files = strpos($method, '$_FILES');
    T::check($token !== false, 'process_upload_request() prüft das Token');
    T::check($token !== false && $files !== false && $token < $files, 'Token-Prüfung steht vor dem Zugriff auf $_FILES');
    $page = (string) file_get_contents(ABSPATH . 'plugins/cms-importer/admin/page.php');
    T::check(str_contains($page, 'name="_nonce"'), 'das Formular sendet das Feld _nonce');
});

T::run('Installer: Tabellen-Präfix wird für LIKE maskiert und nachgefiltert', static function (): void {
    require_once ABSPATH . 'install/InstallerService.php';

    T::same('cms\\_%', InstallerService::likePrefixPattern('cms_'), 'Unterstrich wird maskiert');
    T::same('a\\_b\\_%', InstallerService::likePrefixPattern('a_b_'), 'mehrere Unterstriche werden maskiert');
    T::same('x\\%\\\\\\_%', InstallerService::likePrefixPattern('x%\\_'), 'Prozent und Backslash werden maskiert');
    T::same(
        ['cms_users', 'CMS_posts'],
        InstallerService::filterTablesByPrefix(['cms_users', 'cms2_users', 'cmsa_posts', 'cmsxusers', 'CMS_posts', 42], 'cms_'),
        'nur Tabellen mit exakt diesem Präfix bleiben übrig'
    );
    T::same(['a_b_x'], InstallerService::filterTablesByPrefix(['a_b_x', 'axb_x', 'a1b2x'], 'a_b_'), 'Gegenprobe mit Präfix a_b_');
    T::same([], InstallerService::filterTablesByPrefix(['cms_users'], ''), 'leeres Präfix löscht nichts');

    $source = (string) file_get_contents(ABSPATH . 'install/InstallerService.php');
    T::check(!str_contains($source, "LIKE '{\$prefix}%'"), 'cleanDatabase() baut das Muster nicht mehr ungefiltert zusammen');
});
