<?php
/**
 * Formulare, Navigation, Routing, Cron, Download-Auslieferung und Plugin-Übernahme
 * (Prüfbereich „Fehlende Funktionen“): reine Funktionen, dazu wenige Quelltextprüfungen für die Stellen,
 * die ohne Datenbank nicht ausführbar sind. Wird von TESTS/functions/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Router;
use CMS\Services\Downloads\DownloadFrontendController;
use CMS\Services\FeatureHooks\Registry;
use CMS\Services\Forms\FormUploadService;
use CMS\Services\IntegratedPluginService;

T::run('Routing: Datums-Parameter der Permalink-Struktur fangen keine Core-Pfade ab', static function (): void {
    $class = new ReflectionClass(Router::class);
    $router = $class->newInstanceWithoutConstructor();
    $match = $class->getMethod('matchRoute');
    $match->setAccessible(true);
    $hit = static fn (string $pattern, string $uri): array|false => $match->invoke($router, $pattern, $uri);

    foreach (['/download/handbuch', '/downloads/handbuch', '/kb/dkim', '/feed/security'] as $uri) {
        T::same(false, $hit('/:year/:slug', $uri), $uri . ' ist kein Beitrag der Struktur /%year%/%postname%');
    }
    T::same(['2026', 'mein-beitrag'], $hit('/:year/:slug', '/2026/mein-beitrag'), '/2026/mein-beitrag trifft /:year/:slug');
    T::same(false, $hit('/:year/:month/:slug', '/downloads/category/handbuch'), 'Download-Kategorie ist kein Beitrag der Struktur Jahr/Monat/Name');
    T::same(false, $hit('/:year/:month/:slug', '/newsletter/confirm/abc123'), 'Newsletter-Bestätigung ist kein Beitrag der Struktur Jahr/Monat/Name');
    T::same(['2026', '11', '13', 'x'], $hit('/:year/:month/:day/:slug', '/2026/11/13/x'), 'Datums-Preset trifft weiterhin');
    T::same(['handbuch'], $hit('/downloads/:slug', '/downloads/handbuch'), 'übrige Parameter bleiben unverändert');

    $source = (string) file_get_contents(ABSPATH . 'core/Routing/ThemeRouter.php');
    T::check(
        preg_match('/\$registerPostRoutes = function \(\).*?addRoute\(\'GET\', \$currentPostRoutePattern.*?addAction\(\'register_routes\', \$registerPostRoutes, PHP_INT_MAX\)/s', $source) === 1,
        'Beitragsmuster wird erst nach register_routes eingetragen'
    );
});

T::run('Navigation: Hook main_nav mit Theme-Markup', static function (): void {
    T::same('<a href="/downloads">Downloads</a>', Registry::navItem('/downloads', 'Downloads'), 'Desktop, inaktiv: reines <a>');
    T::same(
        '<a href="/kb" class="active" aria-current="page">Wissen &amp; mehr</a>',
        Registry::navItem('/kb', 'Wissen & mehr', true),
        'Desktop, aktiv: active und aria-current, Beschriftung maskiert'
    );
    T::same(
        '<a href="/newsletter" class="mobile-nav-link">Newsletter</a>',
        Registry::navItem('/newsletter', 'Newsletter', false, 'mobile'),
        'Mobil: mobile-nav-link'
    );
    T::same(
        '<a href="/x?a=1&amp;b=&quot;" class="mobile-nav-link active" aria-current="page">X</a>',
        Registry::navItem('/x?a=1&b="', 'X', true, 'mobile'),
        'Mobil, aktiv; Adresse maskiert'
    );

    $header = (string) file_get_contents(ABSPATH . 'themes/cms-default/header.php');
    T::check(str_contains($header, "doAction('main_nav', 'desktop')"), 'Theme cms-default löst main_nav in der Hauptnavigation aus');
    T::check(str_contains($header, "doAction('main_nav', 'mobile')"), 'Theme cms-default löst main_nav im mobilen Menü aus');
});

T::run('Formulare: Soll-Anzahl hochgeladener Dateien', static function (): void {
    $fields = [
        ['type' => 'text', 'name' => 'name'],
        ['type' => 'file', 'name' => 'anhang'],
        ['type' => 'file', 'name' => 'weitere'],
    ];
    T::same(0, FormUploadService::countFiles($fields, ['name' => 'A', 'anhang' => [], 'weitere' => []]), 'leere Datei-Felder zählen nicht');
    T::same(
        3,
        FormUploadService::countFiles($fields, ['name' => ['kein', 'upload'], 'anhang' => [['name' => 'a.pdf']], 'weitere' => [['name' => 'b.pdf'], ['name' => 'c.pdf']]]),
        'Dateien aller Datei-Felder werden summiert, andere Feldtypen nicht'
    );
    T::same(0, FormUploadService::countFiles($fields, ['anhang' => 'a.pdf']), 'Nicht-Listen zählen nicht');
});

T::run('Downloads: Folgeanfragen (Range/HEAD) erkennen', static function (): void {
    T::same(false, DownloadFrontendController::isFollowUpRequest('', 'GET'), 'GET ohne Range ist ein neuer Abruf');
    T::same(false, DownloadFrontendController::isFollowUpRequest('bytes=0-', 'GET'), 'Range ab Byte 0 ist ein neuer Abruf');
    T::same(false, DownloadFrontendController::isFollowUpRequest(' bytes=0-1023', 'get'), 'erstes Teilstück ist ein neuer Abruf');
    T::same(true, DownloadFrontendController::isFollowUpRequest('bytes=1048576-', 'GET'), 'Range ab Byte > 0 ist eine Folgeanfrage');
    T::same(true, DownloadFrontendController::isFollowUpRequest('', 'head'), 'HEAD ist eine Folgeanfrage');
});

T::run('Plugin-Übernahme: Stand für den Systemhinweis', static function (): void {
    T::same('running', IntegratedPluginService::migrationNoticeKind([]), 'ohne Zustand: läuft noch');
    T::same('running', IntegratedPluginService::migrationNoticeKind(['done' => false, 'report' => []]), 'nicht abgeschlossen: läuft noch');
    T::same('failed', IntegratedPluginService::migrationNoticeKind(['done' => false, 'error' => 'PDOException: x']), 'Ausnahme im letzten Lauf: fehlgeschlagen');
    T::same('done', IntegratedPluginService::migrationNoticeKind(['done' => true, 'report' => ['forms' => 2, 'submissions' => 9]]), 'abgeschlossen ohne Lücken');
    T::same('gaps', IntegratedPluginService::migrationNoticeKind(['done' => true, 'report' => ['forms' => 1, 'forms_failed' => 1]]), 'nicht angelegtes Formular: Lücken');
    T::same('gaps', IntegratedPluginService::migrationNoticeKind(['done' => true, 'report' => ['submissions_skipped' => 4]]), 'übersprungene Eingänge: Lücken');
    T::same('gaps', IntegratedPluginService::migrationNoticeKind(['done' => true, 'report' => ['missing_files' => 1]]), 'fehlende Dateien: Lücken');
});

T::run('Cron und Sprachschlüssel', static function (): void {
    $bootstrap = (string) file_get_contents(ABSPATH . 'core/Bootstrap.php');
    T::check(!str_contains($bootstrap, "throw new \\RuntimeException('SEO-Sitemap-Bundle"), 'Sitemap-Fehler bricht den Tageslauf nicht mehr per Ausnahme ab');

    $keys = [
        'forms.admin.warning_files_not_stored', 'forms.admin.warning_key_changed', 'forms.admin.trashed_was_contact',
        'forms.settings.from_email', 'forms.settings.from_email_hint',
        'admin.plugins.integrated.notice_running', 'admin.plugins.integrated.notice_gaps', 'admin.plugins.integrated.notice_failed',
    ];
    foreach (['de', 'en'] as $lang) {
        $yaml = (string) file_get_contents(ABSPATH . 'lang/' . $lang . '.yaml');
        foreach ($keys as $key) {
            T::check(str_contains($yaml, '  "' . $key . '": "'), $lang . '.yaml enthält ' . $key);
        }
    }
});
