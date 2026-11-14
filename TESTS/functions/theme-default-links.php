<?php
/**
 * Standardverweise des Themes cms-default (Prüfbereich „Fehlende oder falsche Verweise“, Laufzeitprüfung): optionale
 * Seiten (/impressum, /datenschutz, /about) werden nur mit veröffentlichter Seite verlinkt, „Newsletter“ zeigt auf
 * /newsletter, unbekannte Mitglieder-Plugin-Bereiche antworten mit 404. Ohne Datenbank.
 * Wird von TESTS/functions/run.php eingebunden.
 */

declare(strict_types=1);

T::run('Theme: Standardmenüs verlinken keine fehlenden Seiten', static function (): void {
    $root = dirname(__DIR__, 2) . '/CMS/';
    $functions = (string) file_get_contents($root . 'themes/cms-default/functions.php');
    $footer = (string) file_get_contents($root . 'themes/cms-default/footer.php');
    $contact = (string) file_get_contents($root . 'themes/cms-default/contact.php');

    T::check(str_contains($functions, "['label' => 'Newsletter', 'url' => '/newsletter'"), 'Standardmenü: „Newsletter“ zeigt auf /newsletter');
    T::check(!str_contains($footer, "meridian_auth_url('register'), ENT_QUOTES, 'UTF-8'); ?>\">Newsletter"), 'Footer-Fallback: „Newsletter“ zeigt nicht auf die Registrierung');
    T::check(substr_count($footer, '$filterFooterMenu($footer') === 5, 'Footer: alle fünf Menüs werden gefiltert');
    foreach (['footer.php' => $footer, 'contact.php' => $contact] as $name => $source) {
        T::check(preg_match('#SITE_URL; \?>/(impressum|datenschutz)#', $source) === 0, "{$name}: kein fester Link auf /impressum oder /datenschutz");
        T::check(str_contains($source, 'meridian_default_link_available('), "{$name}: Rechtsseiten werden geprüft");
    }

    // Die Prüfung selbst: alles außer den optionalen Seiten gilt ohne Datenbankzugriff als erreichbar.
    if (!function_exists('meridian_default_link_available')
        && preg_match('/^function meridian_default_link_available\(.*?^}$/ms', $functions, $match) === 1) {
        eval($match[0]);
    }
    if (!T::check(function_exists('meridian_default_link_available'), 'meridian_default_link_available() ist definiert')) {
        return;
    }
    foreach (['/', '/blog', '/contact', '/newsletter', '/en/newsletter', '/cms-login', '/impressum/archiv', 'https://example.org/impressum-extern', '#', ''] as $url) {
        T::check(meridian_default_link_available($url), "erreichbar ohne Seitenprüfung: '{$url}'");
    }
});

T::run('Mitgliederbereich: unbekannter Plugin-Bereich antwortet mit 404', static function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/CMS/core/Member/PluginDashboardRegistry.php');
    // Unbekannter Bereich und fehlende Berechtigung laufen über renderError(404); die Fehlerseite wird nur dort
    // (Theme-Override oder Core-Vorlage) und erst nach http_response_code() eingebunden.
    T::check(
        preg_match('#if \(\$section === null \|\| !\$this->checkCapability\(\$section\[\'capability\'\]\)\) \{\s+\$this->renderError\(404\);#', $source) === 1,
        'unbekannter Bereich und fehlende Berechtigung antworten mit 404'
    );
    $renderError = preg_match('#private function renderError\(int \$status\): void\s*\{(.*?)\n    \}#s', $source, $match) === 1 ? $match[1] : '';
    T::check(
        $renderError !== '' && strpos($renderError, 'http_response_code($status);') < strpos($renderError, 'plugin-not-found.php'),
        'renderError() setzt den Statuscode vor der Einbindung der Fehlerseite'
    );
    T::check(
        preg_match_all('#plugin-not-found\.php#', $source) === 2 && substr_count($renderError, 'plugin-not-found.php') === 2,
        'keine weitere Einbindung ohne Statuscode'
    );
});
