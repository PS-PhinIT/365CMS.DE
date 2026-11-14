<?php
/**
 * Login-Showcase (LS-01 … LS-15) und Theme-Farbimport (PH-25) ohne Datenbank.
 *
 * Prüft Normalisierung und Validierung der Einstellungen, die Datenform von
 * CmsAuthPageService::getShowcase(), das Escaping des Partials, die Ausgabe in der Core-Loginseite,
 * den Theme-Override `<theme>/auth/showcase.php` und den Farbvorschlag aus `theme.json`.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/login-showcase/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');
define('SITE_URL', 'https://cms.example');
define('SITE_NAME', 'Österreich-Portal');

$uploadRoot = sys_get_temp_dir() . '/cms-login-showcase-' . bin2hex(random_bytes(4)) . '/uploads/';
mkdir($uploadRoot . 'media/2026', 0777, true);
define('UPLOAD_PATH', $uploadRoot);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once ABSPATH . 'includes/functions/escaping.php';
require_once dirname(__DIR__) . '/lib/TestRunner.php';

use CMS\Services\CmsAuthPageService;

T::$verbose = in_array('-v', $argv ?? [], true);

// 1×1-PNG plus WebP-Geschwisterdatei im temporären Upload-Verzeichnis.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
file_put_contents($uploadRoot . 'media/2026/screen.png', (string) $png);
file_put_contents($uploadRoot . 'media/2026/screen.webp', 'RIFF');
file_put_contents($uploadRoot . 'media/2026/plain.jpg', (string) $png);

$service = (new ReflectionClass(CmsAuthPageService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(CmsAuthPageService::class, 'instance'))->setValue(null, $service);
(new ReflectionProperty(CmsAuthPageService::class, 'logger'))->setValue($service, \CMS\Logger::instance()->withChannel('test.login-showcase'));
(new ReflectionProperty(CmsAuthPageService::class, 'prefix'))->setValue($service, 'cms_');

$call = static function (string $method, mixed ...$args) use ($service): mixed {
    return (new ReflectionMethod($service, $method))->invoke($service, ...$args);
};
$defaults = $call('getDefaultSettings');
$withShowcase = static function (array $overrides) use ($defaults): array {
    return array_merge($defaults, [
        'showcase_enabled' => '1',
        'showcase_headline' => 'Alles für Mitglieder',
    ], $overrides);
};
$renderShowcase = static function (?array $showcase, string $pageType = 'login', string $mode = 'cms') use ($service): string {
    ob_start();
    $service->renderShowcase($showcase, $pageType, $mode);

    return (string) ob_get_clean();
};

T::run('LS-01/LS-02: Schlüssel, Standardwerte und Normalisierung', static function () use ($call, $defaults): void {
    $keys = ['showcase_enabled', 'showcase_pages', 'showcase_image_url', 'showcase_image_alt', 'showcase_image_width',
        'showcase_image_height', 'showcase_image_position', 'showcase_eyebrow', 'showcase_headline', 'showcase_text',
        'showcase_features', 'showcase_cta_label', 'showcase_cta_url'];
    foreach ($keys as $key) {
        T::check(array_key_exists($key, $defaults), "Standardwert für {$key}");
    }
    T::same('0', $defaults['showcase_enabled'], 'Showcase ist standardmäßig aus');
    T::same('login', $defaults['showcase_pages'], 'Standard: nur Anmeldung');
    T::same('left', $defaults['showcase_image_position'], 'Standard: Bild links');

    $normalized = $call('normalizeSettingsInput', []);
    $missing = array_diff(array_keys($defaults), array_keys($normalized));
    T::same([], array_values($missing), 'normalizeSettingsInput() liefert jeden Standardschlüssel (saveSettings schreibt alle neu)');
    T::same('0', $normalized['showcase_enabled'], 'fehlendes Häkchen wird zu 0');

    $normalized = $call('normalizeSettingsInput', [
        'showcase_enabled' => '1',
        'showcase_pages' => 'alles',
        'showcase_image_position' => 'center',
        'showcase_eyebrow' => str_repeat('E', 80),
        'showcase_headline' => '<b>Fett</b> ' . str_repeat('H', 200),
        'showcase_text' => "Zeile <script>x</script>eins\r\n\r\n  Zeile zwei  \n" . str_repeat('t', 900),
        'showcase_cta_label' => 'Mehr',
        'showcase_cta_url' => '/mitgliedschaft',
    ]);
    T::same('1', $normalized['showcase_enabled'], 'Schalter 1');
    T::same('login', $normalized['showcase_pages'], 'unbekannte Seitenauswahl fällt auf login zurück');
    T::same('left', $normalized['showcase_image_position'], 'unbekannte Bildposition fällt auf left zurück');
    T::same(60, mb_strlen($normalized['showcase_eyebrow']), 'Eyebrow max. 60 Zeichen');
    T::check(!str_contains($normalized['showcase_headline'], '<') && mb_strlen($normalized['showcase_headline']) === 120, 'Überschrift ohne HTML, max. 120 Zeichen');
    T::check(mb_strlen($normalized['showcase_text']) <= 600 && !str_contains($normalized['showcase_text'], '<script'), 'Text ohne HTML, max. 600 Zeichen');
    T::check(str_starts_with($normalized['showcase_text'], "Zeile xeins\n\nZeile zwei\n"), 'Zeilen getrimmt, Zeilenumbrüche normalisiert', $normalized['showcase_text']);
    T::same('/mitgliedschaft', $normalized['showcase_cta_url'], 'interne CTA-URL bleibt');
});

T::run('LS-01: Feature-Liste höchstens 8 Punkte à 140 Zeichen', static function () use ($call): void {
    $lines = ['', '  Erster Punkt  ', '<em>Zweiter</em>', str_repeat('L', 200)];
    for ($i = 4; $i <= 12; $i++) {
        $lines[] = 'Punkt ' . $i;
    }
    $normalized = $call('normalizeSettingsInput', ['showcase_features' => implode("\r\n", $lines)]);
    $features = explode("\n", $normalized['showcase_features']);
    T::same(8, count($features), 'acht Punkte bleiben');
    T::same('Erster Punkt', $features[0], 'Leerzeilen entfernt, Punkte getrimmt');
    T::same('Zweiter', $features[1], 'HTML entfernt');
    T::same(140, mb_strlen($features[2]), 'Punkt auf 140 Zeichen gekürzt');
});

T::run('LS-01/LS-11: Bild nur aus /uploads/ mit erlaubter Endung', static function () use ($call): void {
    $accepted = [
        '/uploads/media/2026/screen.png' => '/uploads/media/2026/screen.png',
        'https://cms.example/uploads/media/2026/screen.png' => '/uploads/media/2026/screen.png',
        '/uploads/media/2026/plain.jpg' => '/uploads/media/2026/plain.jpg',
        '/uploads/a/bild.AVIF' => '/uploads/a/bild.AVIF',
    ];
    foreach ($accepted as $input => $expected) {
        T::same($expected, $call('sanitizeShowcaseImageUrl', $input), 'erlaubt: ' . $input);
    }

    $rejected = [
        'https://evil.example/uploads/x.jpg',
        'http://cms.example/uploads/x.jpg',
        'https://cms.example:8443/uploads/x.jpg',
        '//evil.example/uploads/x.jpg',
        'javascript:alert(1)//uploads/x.jpg',
        'data:image/png;base64,AAAA',
        '/uploads/../config/app.php',
        '/uploads/%2e%2e/secret.jpg',
        '/uploads/x.jpg?v=1',
        '/uploads/x.jpg#frag',
        '/uploads/x.svg',
        '/uploads/x.gif',
        '/uploads/x.php',
        '/themes/cms-default/screenshot.png',
        'uploads/x.jpg',
        '/uploads/x".jpg',
        "/uploads/x\n.jpg",
        '/uploads\\x.jpg',
    ];
    foreach ($rejected as $input) {
        T::same('', $call('sanitizeShowcaseImageUrl', $input), 'abgelehnt: ' . json_encode($input));
    }
});

T::run('LS-01/LS-05: Speichern mit Prüfungen (Alt-Text Pflicht, externe URL, CTA)', static function () use ($service, $call): void {
    $result = $service->saveSettings(['showcase_image_url' => '/uploads/media/2026/screen.png', 'showcase_image_alt' => '']);
    T::same(false, $result['success'] ?? null, 'Bild ohne Alternativtext wird nicht gespeichert');
    T::check(in_array('Showcase-Bild: Bitte einen Alternativtext angeben.', $result['errors'] ?? [], true), 'Fehlermeldung zum Alternativtext');
    T::same('/uploads/media/2026/screen.png', $result['values']['showcase_image_url'] ?? null, 'normalisierte Werte gehen zurück (Formular neu befüllen)');
    T::same('1', $result['values']['showcase_image_width'] ?? null, 'Breite aus der Bilddatei ermittelt');
    T::same('1', $result['values']['showcase_image_height'] ?? null, 'Höhe aus der Bilddatei ermittelt');

    $result = $service->saveSettings(['showcase_image_url' => 'https://evil.example/x.jpg', 'showcase_image_alt' => 'Alt']);
    T::same(false, $result['success'] ?? null, 'externe Bild-URL wird abgelehnt');
    T::check(str_contains(implode(' ', $result['errors'] ?? []), '/uploads/'), 'Fehlermeldung nennt /uploads/');

    $result = $service->saveSettings(['showcase_cta_label' => 'Los', 'showcase_cta_url' => 'javascript:alert(1)']);
    T::same(false, $result['success'] ?? null, 'javascript:-Link wird abgelehnt');
    T::same('', $result['values']['showcase_cta_url'] ?? null, 'javascript:-Link wird nicht übernommen');

    $result = $service->saveSettings(['showcase_cta_label' => 'Los']);
    T::same(false, $result['success'] ?? null, 'Linktext ohne Ziel wird abgelehnt');

    $values = $call('normalizeSettingsInput', ['showcase_image_url' => '/uploads/fehlt.png']);
    T::same('', $values['showcase_image_width'], 'ohne lesbare Datei keine Breite');
});

T::run('LS-08: getShowcase()-Datenform und Bedingungen', static function () use ($service, $withShowcase, $defaults): void {
    T::same(null, $service->buildShowcase($defaults, 'login'), 'abgeschaltet → null');
    T::same(null, $service->buildShowcase($withShowcase(['showcase_headline' => '', 'showcase_features' => '']), 'login'), 'ohne Überschrift und Features → null');
    T::check(is_array($service->buildShowcase($withShowcase(['showcase_headline' => '', 'showcase_features' => 'Nur ein Punkt']), 'login')), 'ein Feature genügt');
    T::same(null, $service->buildShowcase($withShowcase([]), 'register'), 'Modus login: nicht auf der Registrierung');
    T::check(is_array($service->buildShowcase($withShowcase(['showcase_pages' => 'login_register']), 'register')), 'Modus login_register: auf der Registrierung');
    T::same(null, $service->buildShowcase($withShowcase(['showcase_pages' => 'login_register']), 'forgot-password'), 'nie auf Passwort-Reset');
    T::same(null, $service->buildShowcase($withShowcase(['showcase_pages' => 'login_register']), 'confirm-email'), 'nie auf der E-Mail-Bestätigung');

    $showcase = $service->buildShowcase($withShowcase([
        'showcase_image_url' => '/uploads/media/2026/screen.png',
        'showcase_image_alt' => 'Dashboard-Ansicht',
        'showcase_image_width' => '1280',
        'showcase_image_height' => '800',
        'showcase_image_position' => 'right',
        'showcase_eyebrow' => 'Neu',
        'showcase_text' => "Absatz eins\n\nAbsatz zwei",
        'showcase_features' => "A\nB",
        'showcase_cta_label' => 'Mehr erfahren',
        'showcase_cta_url' => '/mitglied',
    ]), 'login');
    T::same(['position', 'eyebrow', 'headline', 'paragraphs', 'features', 'cta', 'image'], array_keys((array) $showcase), 'Schlüssel des Vertrags');
    T::same('right', $showcase['position'] ?? null, 'Position');
    T::same(['Absatz eins', 'Absatz zwei'], $showcase['paragraphs'] ?? null, 'Absätze als Liste');
    T::same(['A', 'B'], $showcase['features'] ?? null, 'Features als Liste');
    T::same(['label' => 'Mehr erfahren', 'url' => '/mitglied'], $showcase['cta'] ?? null, 'CTA');
    T::same([
        'src' => '/uploads/media/2026/screen.png',
        'webp' => '/uploads/media/2026/screen.webp',
        'alt' => 'Dashboard-Ansicht',
        'width' => 1280,
        'height' => 800,
    ], $showcase['image'] ?? null, 'Bild mit WebP-Geschwister und Abmessungen');

    $showcase = $service->buildShowcase($withShowcase([
        'showcase_image_url' => '/uploads/media/2026/plain.jpg',
        'showcase_image_alt' => 'Foto',
    ]), 'login');
    T::same(null, $showcase['image']['webp'], 'keine WebP-Datei → webp null');
    T::same(null, $showcase['image']['width'], 'ohne gespeicherte Abmessungen → width null');

    $showcase = $service->buildShowcase($withShowcase(['showcase_image_url' => '/uploads/media/2026/plain.jpg', 'showcase_image_alt' => '']), 'login');
    T::same(null, $showcase['image'], 'Bild ohne Alt-Text wird nicht ausgegeben');
    $showcase = $service->buildShowcase($withShowcase(['showcase_image_url' => 'https://evil.example/x.jpg', 'showcase_image_alt' => 'X']), 'login');
    T::same(null, $showcase['image'], 'externe Bild-URL wird auch beim Lesen verworfen');
    $showcase = $service->buildShowcase($withShowcase(['showcase_cta_label' => 'X', 'showcase_cta_url' => 'javascript:alert(1)']), 'login');
    T::same(null, $showcase['cta'], 'unsichere CTA-URL wird beim Lesen verworfen');
});

T::run('LS-09/LS-12: Partial – Markup, Escaping, Lazy Loading, WebP', static function () use ($service, $withShowcase, $renderShowcase): void {
    T::same('', $renderShowcase(null), 'null rendert nichts');

    $xss = '"><img src=x onerror=alert(1)><script>alert(2)</script>';
    $html = $renderShowcase([
        'position' => 'right',
        'eyebrow' => $xss,
        'headline' => $xss,
        'paragraphs' => [$xss],
        'features' => [$xss, 'Zweiter Punkt'],
        'cta' => ['label' => $xss, 'url' => 'javascript:alert(3)'],
        'image' => ['src' => '/uploads/a.png" onerror="x', 'webp' => null, 'alt' => $xss, 'width' => 640, 'height' => 400],
    ]);
    T::check(!str_contains($html, '<script') && !str_contains($html, '<img src=x'), 'keine ungeescapten Nutzlasten');
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
    libxml_clear_errors();
    $attributeNames = [];
    $elementNames = [];
    foreach ((new DOMXPath($dom))->query('//*') ?: [] as $element) {
        $elementNames[] = $element->nodeName;
        foreach ($element->attributes ?? [] as $attribute) {
            $attributeNames[] = $attribute->nodeName;
        }
    }
    T::same([], array_values(array_filter($attributeNames, static fn (string $name): bool => str_starts_with($name, 'on'))), 'kein on*-Attribut im Markup');
    T::check(!in_array('script', $elementNames, true) && substr_count(implode(',', $elementNames), 'img') === 1, 'kein eingeschleustes script/img-Element');
    T::check(str_contains($html, '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;'), 'Werte mit ENT_QUOTES escaped');
    T::check(!str_contains($html, 'javascript:'), 'javascript:-URL über esc_url entfernt');
    T::check(str_contains($html, 'class="cms-auth-showcase cms-auth-showcase--image-right"'), 'Positions-Modifier');
    T::check(str_contains($html, 'aria-labelledby="cms-auth-showcase-title"') && str_contains($html, '<h2 id="cms-auth-showcase-title"'), 'section über h2 benannt');
    T::check(str_contains($html, 'loading="lazy"') && str_contains($html, 'decoding="async"'), 'Bild lazy und async');
    T::check(str_contains($html, 'width="640" height="400"'), 'width/height ausgegeben');
    T::check(str_contains($html, '<figure class="cms-auth-showcase__media">'), 'Bild in figure');
    T::same(2, substr_count($html, '<svg class="cms-auth-showcase__check" aria-hidden="true" focusable="false"'), 'dekorative Häkchen mit aria-hidden');
    T::check(!str_contains($html, '<picture'), 'ohne WebP kein picture');
    T::check(!str_contains($html, 'style='), 'keine style-Attribute');

    $html = $renderShowcase($service->buildShowcase($withShowcase([
        'showcase_image_url' => '/uploads/media/2026/screen.png',
        'showcase_image_alt' => 'Bild',
    ]), 'login'));
    T::check(str_contains($html, '<picture>') && str_contains($html, '<source type="image/webp" srcset="/uploads/media/2026/screen.webp">'), 'WebP über picture/source');
    T::check(str_contains($html, 'cms-auth-showcase--image-left'), 'Standardposition links');
    T::check(!str_contains($html, ' width="'), 'ohne Abmessungen keine width/height-Attribute');

    $html = $renderShowcase(['position' => 'left', 'eyebrow' => '', 'headline' => 'Titel', 'paragraphs' => [], 'features' => [], 'cta' => null,
        'image' => ['src' => '/uploads/a.png', 'webp' => null, 'alt' => '', 'width' => null, 'height' => null]]);
    T::check(!str_contains($html, '<img') && str_contains($html, 'cms-auth-showcase--no-image'), 'leerer Alt-Text → kein Bild');
});

T::run('LS-08 … LS-13: Core-Loginseite (Modus cms)', static function () use ($service, $withShowcase): void {
    $renderView = static function (array $settings, string $pageType, array $passkey = ['available' => false, 'options_json' => '{}']) use ($service): string {
        $render = static function (array $settings, string $pageType, array $passkeyPayload, ?array $showcase): string {
            $confirmEmail = [];
            $pageTitleText = 'Anmelden – Test';
            $flashError = '';
            $flashSuccess = '';
            $oldInput = [];
            $csrfToken = 'tok';
            $documentLanguage = 'de';
            $requestLocale = 'de';
            $loginUrl = '/cms-login';
            $registerUrl = '/cms-register';
            $forgotUrl = '/cms-password-forgot';
            $homeUrl = '/';
            $loginRedirect = '';
            $forgotStep = 'request';
            $resetToken = '';
            $registerEnabled = true;
            $legalLinks = ['privacy' => ['url' => '', 'title' => ''], 'terms' => ['url' => '', 'title' => ''], 'imprint' => ['url' => '', 'title' => '']];
            $passkeyErrorText = 'Die Passkey-Anmeldung konnte nicht abgeschlossen werden.';
            ob_start();
            require ABSPATH . 'views/auth/cms-auth.php';

            return (string) ob_get_clean();
        };

        return $render($settings, $pageType, $passkey, $service->buildShowcase($settings, $pageType));
    };

    $settings = $withShowcase(['showcase_features' => "<b>Punkt</b>\nZwei", 'brand_name' => 'Ärzte-Netz', 'layout_variant' => 'split']);
    $html = $renderView($settings, 'login', ['available' => true, 'options_json' => '{}']);
    $dumpFile = getenv('LOGIN_SHOWCASE_DUMP');
    if (is_string($dumpFile) && $dumpFile !== '') {
        file_put_contents($dumpFile, $html); // Sichtprüfung der gerenderten Seite
    }
    $mainEnd = strpos($html, '</main>');
    $section = strpos($html, '<section id="cms-auth-showcase"');
    T::check($mainEnd !== false && $section !== false && $section > $mainEnd, 'Showcase steht nach </main>');
    T::check(strpos($html, '</body>') > $section, 'Showcase vor </body>');
    T::check(str_contains($html, 'Ä') && str_contains($html, '<span class="cms-auth-logo-fallback">Är</span>'), 'Initialen multibyte-sicher (mb_substr)');
    T::check(!str_contains($html, 'style="'), 'keine style-Attribute in der Loginseite');
    T::check(!str_contains($html, 'window.alert'), 'kein window.alert im Passkey-Skript');
    T::check(str_contains($html, 'id="cms-passkey-message" class="cms-auth-passkey-message" role="alert"'), 'Inline-Meldung mit role=alert für Passkey-Fehler');
    T::check(preg_match('/<aside class="cms-auth-panel" aria-hidden="true">(.*?)<\/aside>/s', $html, $aside) === 1 && !str_contains($aside[1], '<a ') && !str_contains($aside[1], '<h1'), 'aria-hidden-Panel ohne Links und ohne doppelte h1');
    T::same(1, substr_count($html, '<h1'), 'genau eine h1');
    T::check(str_contains($html, 'background-image: linear-gradient(160deg, var(--cms-auth-bg-start) 0%, var(--cms-auth-bg-end) 100%)'), 'Hintergrundverlauf angewendet');
    T::check(str_contains($html, '@media (min-width: 768px)') && str_contains($html, 'grid-template-columns: minmax(0, 1fr) minmax(0, 1fr)'), 'zweispaltiges Grid ab 768 px');
    T::check(str_contains($html, '--cms-auth-showcase-width: 1040px'), 'eigene Showcase-Breite');
    T::check(preg_match_all('/<(style|script)\b(?![^>]*\bnonce=)[^>]*>/i', $html) === 0, 'alle Inline-Styles/-Skripte mit Nonce');
    T::check(preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html) !== 1, 'keine Inline-Event-Handler');
    T::check(str_contains($html, '&lt;b&gt;') === false && str_contains($html, '<span>Punkt</span>'), 'Features escaped/bereinigt ausgegeben');

    // Struktur: wohlgeformte Verschachtelung (HTML5-Elemente als unbekannte Tags ignorieren).
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $errors = array_filter(libxml_get_errors(), static fn (LibXMLError $e): bool => $e->code !== 801);
    libxml_clear_errors();
    T::same([], array_map(static fn (LibXMLError $e): string => trim($e->message) . ' @' . $e->line, array_values($errors)), 'HTML ohne Parserfehler');

    $html = $renderView(array_merge($settings, ['showcase_enabled' => '0']), 'login');
    T::check(!str_contains($html, 'cms-auth-showcase"') && !str_contains($html, '<section id="cms-auth-showcase"'), 'abgeschaltet: keine Ausgabe');
    $html = $renderView($settings, 'forgot-password');
    T::check(!str_contains($html, '<section id="cms-auth-showcase"'), 'Passwort-Reset: keine Ausgabe');
});

T::run('LS-14: Theme-Override <theme>/auth/showcase.php', static function () use ($service, $withShowcase, $renderShowcase, $uploadRoot): void {
    $themeDir = dirname($uploadRoot) . '/themes/override-theme/';
    mkdir($themeDir . 'auth', 0777, true);
    file_put_contents($themeDir . 'auth/showcase.php', '<?php echo "THEME:" . htmlspecialchars($showcase["headline"], ENT_QUOTES) . ":" . $pageType . ":" . $showcaseMode;');

    $manager = (new ReflectionClass(\CMS\ThemeManager::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(\CMS\ThemeManager::class, 'themePath'))->setValue($manager, $themeDir);
    (new ReflectionProperty(\CMS\ThemeManager::class, 'activeTheme'))->setValue($manager, 'override-theme');
    $instance = new ReflectionProperty(\CMS\ThemeManager::class, 'instance');
    $instance->setValue(null, $manager);

    T::same($themeDir . 'auth/showcase.php', $service->resolveShowcasePartial(), 'Override wird gefunden');
    T::same('THEME:Alles für Mitglieder:login:legacy', $renderShowcase($service->buildShowcase($withShowcase([]), 'login'), 'login', 'legacy'), 'Override erhält $showcase, $pageType und $showcaseMode');

    unlink($themeDir . 'auth/showcase.php');
    T::same(ABSPATH . 'views/auth/partials/showcase.php', $service->resolveShowcasePartial(), 'ohne Override das Core-Partial');

    // PH-25: Farbvorschlag aus theme.json
    T::same([], $service->getThemeLoginColors(), 'Theme ohne theme.json → keine Farben (Button ausgeblendet)');
    file_put_contents($themeDir . 'theme.json', json_encode(['login_page' => ['colors' => [
        'primary_color' => '#AABBCC',
        'link_color' => 'red',
        'text_color' => '#12345',
        'unknown_key' => '#000000',
        'card_background' => '#ffffff',
    ]]]));
    T::same(['card_background' => '#ffffff', 'primary_color' => '#aabbcc'], $service->getThemeLoginColors(), 'nur bekannte Schlüssel mit #rrggbb, kleingeschrieben');
    file_put_contents($themeDir . 'theme.json', '{"login_page": {"colors": "nope"}}');
    T::same([], $service->getThemeLoginColors(), 'ungültiger Block → keine Farben');
    T::same(false, $service->importThemeColors()['success'] ?? null, 'Import ohne Farben schlägt ohne Schreibzugriff fehl');

    (new ReflectionProperty(\CMS\ThemeManager::class, 'themePath'))->setValue($manager, ABSPATH . 'themes/cms-default/');
    $colors = $service->getThemeLoginColors();
    T::same(CmsAuthPageService::COLOR_SETTING_KEYS, array_keys($colors), 'cms-default liefert alle zehn Farbschlüssel');

    $instance->setValue(null, null);
});

T::run('LS-03 … LS-07/LS-15/PH-25: Admin, Router und Theme-Vorlage (statisch)', static function (): void {
    $read = static fn (string $path): string => (string) file_get_contents(ABSPATH . $path);
    $view = $read('admin/views/themes/cms-loginpage.php');
    T::check(str_contains($view, 'data-login-tab="showcase"') && str_contains($view, 'id="cms-login-tab-showcase"'), 'Tab „Showcase“ mit Panel');
    foreach (['showcase_enabled', 'showcase_pages', 'showcase_image_url', 'showcase_image_alt', 'showcase_image_position', 'showcase_eyebrow',
        'showcase_headline', 'showcase_text', 'showcase_features', 'showcase_cta_label', 'showcase_cta_url'] as $field) {
        T::check(str_contains($view, 'name="' . $field . '"'), "Formularfeld {$field}");
    }
    $mainForm = substr($view, (int) strpos($view, '<form method="POST">'), (int) strpos($view, '</form>') - (int) strpos($view, '<form method="POST">'));
    T::check(str_contains($mainForm, 'name="showcase_cta_url"') && str_contains($mainForm, 'name="brand_name"'), 'Showcase-Felder stehen im einzigen Speicherformular');
    T::check(str_contains($view, 'data-open-media-picker') && str_contains($view, 'data-preview-variant="screenshot"'), 'Medien-Picker mit Vorschau-Variante screenshot');
    T::check(str_contains($view, 'id="settingsMediaPickerModal"') && str_contains($view, "generateToken('editorjs_media')"), 'Picker-Modal mit editorjs_media-Token');
    T::check(str_contains($view, "<?php if (\$themeColors !== []): ?>") && str_contains($view, 'form="cms-loginpage-theme-colors-form"'), 'Farbimport nur bei vorhandenem Theme-Block');
    T::check(str_contains($view, 'value="import_theme_colors"') && str_contains($view, 'name="csrf_token" value="<?php echo $esc($themeColorsToken); ?>"'), 'Farbimport per POST mit CSRF-Token');
    T::check(!preg_match('/<[a-z][^>]*\son(?:click|change|input|submit)\s*=/i', $view), 'keine Inline-Event-Handler im Admin');

    // Admin-Ansicht einmal rendern (mit Farbvorschlag und abgelehntem Showcase-Speichern).
    $_SESSION = [];
    $renderAdmin = static function (array $data, ?array $alert): string {
        $csrfToken = 'tok';
        ob_start();
        require ABSPATH . 'admin/views/themes/cms-loginpage.php';

        return (string) ob_get_clean();
    };
    $settings = (new ReflectionMethod(CmsAuthPageService::class, 'getDefaultSettings'))->invoke(CmsAuthPageService::getInstance());
    $adminHtml = $renderAdmin([
        'settings' => array_merge($settings, ['showcase_image_url' => '/uploads/a.png', 'showcase_features' => "A\nB\nC"]),
        'page_options' => [],
        'registration_enabled' => true,
        'passkey_enabled' => true,
        'theme_colors' => ['primary_color' => '#112233'],
        'active_theme' => 'cms-default',
        'showcase_visible' => false,
        'preview_urls' => ['login' => '/cms-login', 'showcase' => '/cms-login#cms-auth-showcase', 'showcase_register' => ''],
    ], ['type' => 'danger', 'message' => 'Nicht gespeichert', 'details' => ['Showcase-Bild: Bitte einen Alternativtext angeben.']]);
    T::check(str_contains($adminHtml, 'data-initial-tab="showcase"'), 'nach abgelehntem Speichern öffnet der Showcase-Tab');
    T::check(str_contains($adminHtml, 'id="cms-loginpage-theme-colors-form"') && str_contains($adminHtml, '<code>#112233</code>'), 'Farbvorschlag und Importformular sichtbar');
    T::check(str_contains($adminHtml, '3 von 8 Punkten'), 'Zeilenzähler zeigt die Zahl der Punkte');
    $adminHtml = $renderAdmin(['settings' => $settings, 'theme_colors' => [], 'preview_urls' => []], null);
    T::check(!str_contains($adminHtml, 'cms-loginpage-theme-colors-form') && !str_contains($adminHtml, 'Farben aus dem aktiven Theme übernehmen</button>'), 'ohne Theme-Block kein Import-Button');
    preg_match_all('/\sid="([^"]+)"/', $adminHtml, $ids);
    T::same([], array_values(array_unique(array_diff_assoc($ids[1], array_unique($ids[1])))), 'keine doppelten IDs in der Admin-Ansicht');

    $entry = $read('admin/cms-loginpage.php');
    T::check(str_contains($entry, "cms_asset_url('js/admin-media-integrations.js')") && str_contains($entry, '->handlePost($post)'), 'Skript über page_assets, POST über handlePost()');
    $module = $read('admin/modules/themes/CmsLoginPageModule.php');
    T::check(str_contains($module, "'cms_loginpage.saved'") && str_contains($module, "'cms_loginpage.theme_colors_imported'"), 'Audit-Log für Speichern und Farbimport');
    T::check(str_contains($module, "'showcase' => \$loginPath . '#cms-auth-showcase'"), 'Vorschau-Link für den Showcase');

    $js = $read('assets/js/admin-media-integrations.js');
    T::check(str_contains($js, "variant === 'screenshot'") && str_contains($js, "variant === 'favicon'"), 'Vorschau-Variante screenshot ergänzt, favicon/logo bleiben');

    $router = $read('core/Routing/PublicRouter.php');
    T::check(preg_match("/render\\('login', \\[\\s*'login_redirect' => \\\$safeRedirectTarget,\\s*'showcase' => Services\\\\CmsAuthPageService::getInstance\\(\\)->getShowcase\\('login'\\),/", $router) === 1, 'Legacy-Login erhält $showcase');

    $login = $read('themes/cms-default/login.php');
    $card = strrpos($login, '<!-- Footer Links -->');
    $render = strpos($login, "renderShowcase(\$showcase, 'login', 'legacy')");
    T::check($card !== false && $render !== false && $render > $card, 'cms-default gibt den Showcase nach der Karte aus');
});

// Temporäre Upload-/Theme-Verzeichnisse aufräumen.
$cleanup = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(dirname($uploadRoot), FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($cleanup as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir(dirname($uploadRoot));

T::finish();
