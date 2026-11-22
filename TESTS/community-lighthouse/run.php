<?php
/**
 * Community – Lighthouse (PR #5, TEST-PLAN `lighthouse`): mobil mindestens 90 Punkte in Leistung, Barrierefreiheit, Best Practices und SEO für
 * Startseite, Forum und Thema, im Standard-Theme und in einem Theme mit Dunkelmodus (`cms-phinit` folgt mit dem Theme-PR).
 *
 * Teil 1 (immer, ohne Browser): die Größen und Eigenschaften, aus denen die Punkte entstehen, als Obergrenzen: Gewicht der Dateien
 * (community.css, community.js) und der Dokumente (roh und gzip), Zahl der Elemente, genau ein Stil und ein Modul-Skript im Kopf (nichts blockiert die
 * Darstellung), keine fremden Adressen, keine Schriften und keine `@import`; SEO: Titel, Beschreibung (`<meta name="description">`, höchstens 160 Zeichen,
 * ohne Markup, je Seite eines Themas anders), absolute kanonische Adresse, `rel=prev/next`, `noindex` genau auf den Seiten ohne Indexwert (Suche,
 * Listen, Schreibseiten, Folgeseiten des Forums, nicht gefunden), crawlbare Links mit aussagekräftigem Text.
 * Teil 2 (nur mit `node`, Playwright, Lighthouse und chrome-launcher, sonst übersprungen wie `community-editor`): `browser.cjs` startet Lighthouse mit
 * der Standardeinstellung für Mobilgeräte (langsames 4G, vierfach gedrosselte CPU) gegen die gerenderten Seiten. Mindestpunktzahl 90 (Umgebungsvariable
 * `CMS_TEST_LIGHTHOUSE_MIN` ändert sie). Gemessen wird der Beitrag der Community im Rahmen eines schlanken Themes; Schriften, Bilder und Skripte des echten
 * Themes kommen dazu und gehören zum Theme-Test.
 *
 *   php TESTS/community-lighthouse/run.php [-v]
 *   NODE_PATH=<Verzeichnis mit lighthouse und chrome-launcher> php TESTS/community-lighthouse/run.php   (Browser-Teil)
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-lighthouse-' . getmypid() . '/');
date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

require_once dirname(__DIR__) . '/lib/Mutant.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        Mutant::load($file);
    }
});
require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/lib/CommunityTestDatabase.php';
require_once dirname(__DIR__) . '/lib/CommunityPages.php';
require_once ABSPATH . 'assets/autoload.php';

/**
 * Seiten der Prüfung (Gäste, wie ein Suchroboter und Lighthouse sie sehen): [Schlüssel, Bezeichnung, Adresse, Abfrageparameter, indexierbar?,
 * Obergrenzen: gzip-Bytes, rohe Bytes, Elemente].
 *
 * @return list<array{0: string, 1: string, 2: string, 3: array<string, string>, 4: bool, 5: int, 6: int, 7: int}>
 */
function lhPages(CommunityPages $w): array
{
    $long = (int) $w->ids['long'];

    return [
        ['startseite', 'Startseite', '/forum', [], true, 4000, 12000, 250],
        ['forum', 'Forum', '/forum/technik', [], true, 6000, 30000, 600],
        ['thema', 'Thema', '/forum/thema/' . $long . '-langer-austausch', [], true, 8000, 30000, 600],
        ['thema-2', 'Thema Seite 2', '/forum/thema/' . $long . '-langer-austausch/seite-2', [], true, 8000, 30000, 600],
        ['liste', 'Liste „neu“', '/forum/neu', [], false, 6000, 30000, 600],
        ['suche', 'Suche', '/forum/suche', ['q' => 'Einrichtung'], false, 6000, 30000, 600],
        ['neues-thema', 'Neues Thema (ohne Konto)', '/forum/technik/neues-thema', [], false, 6000, 30000, 600],
        ['unbekannt', 'nicht gefunden', '/forum/thema/999999-gibt-es-nicht', [], false, 6000, 30000, 600],
    ];
}

T::run('Dateien der Community: schlank, ohne Schriften, ohne fremde Adressen', static function (): void {
    // [Datei, Obergrenze roh, Obergrenze gzip] in Bytes
    foreach ([
        ['css/community.css', 9000, 2500], ['js/community/community.js', 11000, 3200], ['css/captcha.css', 2000, 800], ['js/captcha/captcha.js', 5000, 2000],
        ['js/community/editor.js', 12000, 4000], ['js/community/md-bridge.js', 12000, 4400],
    ] as [$file, $raw, $gz]) {
        $content = (string) file_get_contents(ABSPATH . 'assets/' . $file);
        T::check(strlen($content) <= $raw, $file . ': ' . strlen($content) . ' Byte roh (höchstens ' . $raw . ')');
        T::check(strlen((string) gzencode($content, 9)) <= $gz, $file . ': ' . strlen((string) gzencode($content, 9)) . ' Byte gzip (höchstens ' . $gz . ')');
        if (str_ends_with($file, '.css')) {
            T::check(!str_contains($content, '@import') && !str_contains($content, '@font-face'), $file . ': kein @import, keine eigenen Schriften (keine zusätzlichen Anfragen vor der Darstellung)');
            T::check(preg_match('~url\(\s*[\'"]?(https?:)?//~i', $content) !== 1, $file . ': keine fremden Adressen');
        } else {
            T::check(preg_match('~https?://~i', (string) preg_replace('~//.*$~m', '', $content)) !== 1, $file . ': keine fremden Adressen');
        }
    }
    // Das Modul der Seite startet nach dem Parsen (type="module" ist deferred); der Skriptteil ist klein und lädt den Editor nur auf Formularseiten
    $controller = (string) file_get_contents(ABSPATH . 'core/Services/Community/Http/PublicController.php');
    T::check(substr_count($controller, '<script type="module" src=') === 3 && !str_contains($controller, '<script src='), 'Kopfangaben: Skripte nur als type="module" (nicht blockierend), nie ohne Typ');
    T::check(str_contains($controller, "if (!empty(\$meta['editor']))"), 'der Editor wird nur auf Formularseiten geladen');
});

T::run('Seiten: Gewicht, Kopf, nichts blockiert die Darstellung, keine fremden Adressen', static function (): void {
    $w = CommunityPages::create();
    try {
        foreach (lhPages($w) as [$key, $label, $path, $query, , $gzMax, $rawMax, $elementsMax]) {
            $page = $w->request($path, null, $query);
            $html = $w->html($page);
            $gz = strlen((string) gzencode($html, 9));
            $elements = preg_match_all('/<[a-z][a-z0-9-]*[\s>\/]/i', $html);
            T::check($gz <= $gzMax, $label . ': Dokument ' . $gz . ' Byte gzip (höchstens ' . $gzMax . ')');
            T::check(strlen($html) <= $rawMax, $label . ': Dokument ' . strlen($html) . ' Byte roh (höchstens ' . $rawMax . ')');
            T::check($elements <= $elementsMax, $label . ': ' . $elements . ' Elemente (höchstens ' . $elementsMax . ')');
            $x = xpath($html);
            T::check($x->query('//head//link[@rel="stylesheet"]')->length <= 2, $label . ': höchstens zwei Stylesheets im Kopf (Theme-Stil, community.css)');
            T::same(0, $x->query('//script[not(@src)]')->length, $label . ': keine Inline-Skripte');
            T::same(0, $x->query('//script[@src][not(@type="module")]')->length, $label . ': Skripte nur als type="module" (deferred)');
            T::check($x->query('//script[@src]')->length <= 1, $label . ': höchstens ein Skript im Kopf');
            T::same(0, $x->query('//img[not(@loading)]')->length, $label . ': Bilder laden verzögert (loading) oder es gibt keine');
            // Adressen: relativ oder die eigene Website (Kanonisch, prev, next), nie ein fremder Host
            $foreign = [];
            // Inhalte der Mitglieder (Beitragstext) können beliebige Links tragen: geprüft wird die Seite der Community selbst
            foreach ($x->query('//*[@src or @href][not(ancestor::*[contains(@class, "cms-community-post__body")])]') as $el) {
                $url = $el->getAttribute('src') ?: $el->getAttribute('href');
                if (preg_match('~^(https?:)?//([^/]+)~i', $url, $m) === 1 && !str_contains($m[2], 'example.test')) {
                    $foreign[] = $url;
                }
            }
            T::same([], $foreign, $label . ': keine fremden Adressen (Daten gehen an keinen Dritten)');
        }
    } finally {
        $w->close();
    }
});

/**
 * @return DOMXPath
 */
function xpath(string $html): DOMXPath
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    return new DOMXPath($doc);
}

T::run('SEO (Lighthouse-Kategorie): Titel, Beschreibung, kanonische Adresse, noindex, crawlbare Links', static function (): void {
    $w = CommunityPages::create();
    try {
        $descriptions = [];
        foreach (lhPages($w) as [$key, $label, $path, $query, $indexable]) {
            $page = $w->request($path, null, $query);
            $html = $w->html($page);
            $x = xpath($html);
            $title = trim($x->evaluate('string(//head/title)'));
            T::check($title !== '' && mb_strlen($title) <= 70, $label . ': Titel vorhanden und höchstens 70 Zeichen (' . mb_strlen($title) . ')');
            T::same('de', $x->evaluate('string(/html/@lang)'), $label . ': Dokumentsprache');
            $description = $x->query('//head/meta[@name="description"]');
            $robots = $x->query('//head/meta[@name="robots"]');
            if ($indexable) {
                T::same(1, $description->length, $label . ': genau eine Meta-Beschreibung');
                $text = (string) $description->item(0)?->getAttribute('content');
                T::check(mb_strlen($text) >= 10 && mb_strlen($text) <= 160, $label . ': Beschreibung 10 bis 160 Zeichen (' . mb_strlen($text) . ')');
                T::check($text === strip_tags($text) && !str_contains($text, "\n") && !str_contains($text, '  '), $label . ': Beschreibung ohne Markup und Zeilenumbrüche');
                T::same(0, $robots->length, $label . ': nicht auf noindex');
                $canonical = (string) $x->query('//head/link[@rel="canonical"]')->item(0)?->getAttribute('href');
                T::check(preg_match('~^https://example\.test/forum~', $canonical) === 1, $label . ': absolute kanonische Adresse (' . $canonical . ')');
                $descriptions[$key] = $text;
            } else {
                T::same(0, $description->length, $label . ': keine Beschreibung auf Seiten ohne Indexwert');
                T::same('noindex, follow', (string) $robots->item(0)?->getAttribute('content'), $label . ': noindex, follow');
            }
            // Links: crawlbar (echte Adresse) und mit aussagekräftigem Text
            $bad = [];
            foreach ($x->query('//a[not(ancestor::*[contains(@class, "cms-community-post__body")])]') as $link) {
                $href = trim($link->getAttribute('href'));
                $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $link->getAttribute('aria-label') ?: $link->textContent)));
                if ($href === '' || $href === '#' || str_starts_with(strtolower($href), 'javascript:')) {
                    $bad[] = 'nicht crawlbar: ' . $href;
                }
                if (in_array($text, ['hier', 'mehr', 'click here', 'here', 'more', 'read more', 'learn more', 'link', 'this', 'go', 'klicken sie hier'], true)) {
                    $bad[] = 'nichtssagender Text: ' . $text;
                }
            }
            T::same([], $bad, $label . ': Links crawlbar und mit aussagekräftigem Text');
        }
        // Beschreibungen sind je Seite eigen: Startseite, Forum, Thema und Folgeseite des Themas
        T::same(count($descriptions), count(array_unique($descriptions)), 'jede indexierbare Seite hat eine eigene Beschreibung');
        T::check(str_contains($descriptions['forum'], 'Technik'), 'Forum: die Beschreibung nennt das Forum');
        T::check(str_contains($descriptions['thema'], 'ich suche Tipps zur Einrichtung'), 'Thema: die Beschreibung ist der Anfang des ersten Beitrags (ohne Markup)');

        // Folgeseite des Forums (Cursor): nicht indexieren, also auch keine Beschreibung
        $next = (string) ($w->request('/forum/technik')['vars']['next'] ?? '');
        T::check($next !== '', 'Vorbedingung: das Forum hat eine Folgeseite');
        $later = xpath($w->html($w->request('/forum/technik', null, ['nach' => $next])));
        T::same(0, $later->query('//head/meta[@name="description"]')->length, 'Folgeseite des Forums: keine Beschreibung');
        T::same('noindex, follow', (string) $later->query('//head/meta[@name="robots"]')->item(0)?->getAttribute('content'), 'Folgeseite des Forums: noindex, follow');
        // Beschreibung aus dem Forum: eigene Beschreibung des Forums hat Vorrang, lange Texte werden an der Wortgrenze gekürzt
        $forumText = str_repeat('Alles rund um Netzwerke und Technik im Haus. ', 8);
        $w->db->execute('UPDATE cms_community_forums SET description = ? WHERE id = ?', [$forumText, $w->ids['forum']]);
        $text = (string) xpath($w->html($w->request('/forum/technik')))->query('//head/meta[@name="description"]')->item(0)?->getAttribute('content');
        T::check(mb_strlen($text) <= 155 && str_ends_with($text, '…') && str_starts_with($text, 'Alles rund um Netzwerke'), 'Forum: eigene Beschreibung, höchstens 155 Zeichen, gekürzt mit „…“ (' . mb_strlen($text) . ')');
        $cut = mb_substr($text, 0, -1);
        T::check(in_array(mb_substr($forumText, mb_strlen($cut), 1), [' ', '.', ',', ';', ':'], true), 'gekürzt an einer Wortgrenze (nächstes Zeichen im Original: „' . mb_substr($forumText, mb_strlen($cut), 1) . '“)');
        // Markup und Anführungszeichen im Beitrag: die Beschreibung ist reiner, maskierter Text
        $w->db->execute('UPDATE cms_community_posts SET body_html = ? WHERE id = ?', ['<p>Eine <strong>fette</strong> „Aussage“ mit &lt;b&gt; und "Quotes" &amp; mehr.</p><script>alert(1)</script>', $w->ids['long_first']]);
        $html = $w->html($w->request('/forum/thema/' . $w->ids['long'] . '-langer-austausch'));
        $text = (string) xpath($html)->query('//head/meta[@name="description"]')->item(0)?->getAttribute('content');
        T::check(!str_contains($text, '<strong>') && !str_contains($text, '<script') && str_contains($text, 'Eine fette'), 'Beschreibung aus dem Beitrag ohne Markup (' . $text . ')');
        T::check(substr_count($html, '<meta name="description"') === 1 && !str_contains($html, 'content="Eine fette „Aussage“ mit <b>'), 'Meta-Beschreibung: Anführungszeichen und spitze Klammern maskiert (kein Ausbruch aus dem Attribut)');
    } finally {
        $w->close();
    }
});

T::run('Browser (Chromium, Lighthouse): mobil mindestens 90 Punkte in allen Kategorien', static function (): void {
    $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
    $probe = $node !== '' ? trim((string) @shell_exec(escapeshellarg($node) . ' -e "try{require(\'playwright\');require(\'lighthouse/package.json\');require(\'chrome-launcher\');console.log(\'ok\')}catch(e){console.log(\'no\')}" 2>/dev/null')) : '';
    if ($probe !== 'ok') {
        T::info('Lighthouse-Prüfung übersprungen: node, Playwright (mit Chromium), lighthouse und chrome-launcher nötig (NODE_PATH auf das Verzeichnis mit lighthouse setzen)');

        return;
    }
    $dir = sys_get_temp_dir() . '/cms-community-lighthouse-' . getmypid();
    @mkdir($dir, 0700, true);
    $w = CommunityPages::create();
    try {
        $manifest = [];
        foreach (lhPages($w) as [$key, $label, $path, $query, $indexable]) {
            if (!in_array($key, ['startseite', 'forum', 'thema'], true)) {
                continue;
            }
            $page = $w->request($path, null, $query);
            foreach (['standard' => 'Standard-Theme', 'dunkel' => 'Theme mit Dunkelmodus'] as $theme => $themeLabel) {
                $file = $key . '--' . $theme . '.html';
                file_put_contents($dir . '/' . $file, $w->html($page, '/assets/', $theme));
                $manifest[] = ['name' => $label . ' (' . $themeLabel . ')', 'file' => $file];
            }
        }
        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    } finally {
        $w->close();
    }
    $min = max(1, (int) (getenv('CMS_TEST_LIGHTHOUSE_MIN') ?: 90));
    $output = [];
    exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/browser.cjs') . ' ' . escapeshellarg($dir) . ' ' . $min . ' 2>&1', $output, $code);
    $text = implode("\n", $output);
    T::info(str_replace("\n", ' | ', $text));
    T::same(0, $code, 'Lighthouse fehlerfrei: ' . (string) end($output), $text);
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
});

T::finish();
