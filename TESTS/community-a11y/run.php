<?php
/**
 * Community – Barrierefreiheit (PR #5, TEST-PLAN `a11y`): Startseite, Forum, Thema, Editor, Suche und Mitgliederbereich ohne ernste oder
 * kritische Verstöße (axe-core), dazu Tastaturbedienung, Fokus, Sprunglink, Reflow und Screenreader-Namen.
 *
 * Teil 1 (immer, ohne Browser): die echten Vorlagen laufen auf Testdaten (`TESTS/lib/CommunityPages.php`) im Rahmen des Standard-Themes; die
 * Regeln von axe-core, die sich aus dem Dokumentbaum entscheiden lassen, prüft `TESTS/lib/A11yRules.php` (Sprache, Titel, Überschriften,
 * Landmarken, Namen, Beschriftungen, ID-Verweise, ARIA, Listen, Tabellen, Statusmeldungen, „Name enthält sichtbaren Text“); jede Regel
 * wird zuerst an einem absichtlich fehlerhaften Dokument erprobt. Dazu die Stile (`community.css`): Fokus, Unterstreichung von Links
 * im Text, Bewegung, Zielgröße.
 * Teil 2 (nur mit `node`, Playwright und axe-core, sonst übersprungen wie `community-editor`): `browser.cjs` lädt dieselben Seiten in
 * Chromium, hell und dunkel, Desktop und Mobil, im Standard-Theme und in einem Theme mit Dunkelmodus, und lässt axe-core laufen
 * (WCAG 2.0/2.1/2.2 A und AA, Empfehlungen); ernste und kritische Verstöße scheitern. Tastatur: Sprunglink, sichtbarer Fokus, Reflow bei
 * 320 px, Melden-Dialog (Fokus hinein, Escape, Fokus zurück). Farbkontrast prüft nur der Browser.
 * Nicht automatisierbar und Aufgabe der Abnahme (TEST-PLAN): ein Durchgang mit NVDA/VoiceOver über Thema, Antwort und Melden.
 *
 *   php TESTS/community-a11y/run.php [-v]
 *   NODE_PATH=<Verzeichnis mit axe-core> php TESTS/community-a11y/run.php   (Browser-Teil: node, playwright, axe-core)
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-a11y-' . getmypid() . '/');
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
require_once dirname(__DIR__) . '/lib/A11yRules.php';
require_once ABSPATH . 'assets/autoload.php';

/**
 * Die Seiten der Prüfung: [Schlüssel, Bezeichnung, Adresse, Konto, Abfrageparameter, Zusatz für den Browser (kind, keyboard, dialog)].
 *
 * @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: array<string, string>, 5: array<string, mixed>}>
 */
function a11yPages(CommunityPages $w): array
{
    $long = (int) $w->ids['long'];

    return [
        ['startseite', 'Startseite', '/forum', null, [], ['keyboard' => true]],
        ['startseite-mitglied', 'Startseite (Mitglied)', '/forum', 'member', [], []],
        ['forum', 'Forum', '/forum/technik', 'member', [], ['keyboard' => true]],
        ['forum-gast', 'Forum (Gast)', '/forum/technik', null, [], []],
        ['thema', 'Thema (Mitglied)', '/forum/thema/' . $long . '-langer-austausch', 'member', [], ['keyboard' => true, 'dialog' => true]],
        ['thema-gast', 'Thema Seite 2 (Gast)', '/forum/thema/' . $long . '-langer-austausch/seite-2', null, [], []],
        ['thema-mod', 'Thema (Moderation)', '/forum/thema/' . $long . '-langer-austausch', 'admin', [], []],
        ['liste', 'Liste „neu“', '/forum/neu', null, [], []],
        ['suche', 'Suche mit Treffern', '/forum/suche', null, ['q' => 'Einrichtung'], ['keyboard' => true]],
        ['suche-leer', 'Suche ohne Eingabe', '/forum/suche', null, [], []],
        ['neues-thema', 'Neues Thema (Editor)', '/forum/technik/neues-thema', 'member', [], ['kind' => 'editor', 'keyboard' => true]],
        ['antworten', 'Antworten (Editor)', '/forum/thema/' . $long . '/antworten', 'member', [], ['kind' => 'editor']],
        ['anmeldung', 'Schreiben ohne Konto (Hinweis)', '/forum/technik/neues-thema', null, [], []],
    ];
}

/** Gute Seite als Grundlage der Regelprüfung: ein `<main>`, ein Sprunglink, eine Überschrift. */
function goodPage(string $main = '<h1>Titel</h1><p>Text</p>', string $head = '', string $extraBody = ''): string
{
    return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Seite</title>' . $head . '</head><body>'
        . '<a class="skip-link" href="#main-content">Zum Inhalt springen</a><header><nav aria-label="Hauptnavigation"><ul><li><a href="/">Start</a></li></ul></nav></header>'
        . '<main id="main-content" tabindex="-1">' . $main . '</main>' . $extraBody . '<footer><p>Fuß</p></footer></body></html>';
}

// ── Regelwerk ──────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Regelwerk: eine gute Seite besteht, jede Regel erkennt ihren Verstoß', static function (): void {
    T::same([], A11yRules::check(goodPage()), 'Grundseite: keine Verstöße');
    T::same([], A11yRules::check(goodPage('<h1>Titel</h1><form><label for="a">Name</label><input id="a" type="text"><label>Ort <input type="text" name="o"></label><select aria-label="Art"><option>x</option></select><button type="submit">Senden</button></form><img src="x.png" alt=""><ul><li>a</li></ul><table><caption>t</caption><tr><th scope="col">h</th></tr><tr><td>d</td></tr></table><dl><dt>a</dt><dd>b</dd></dl><button type="button" aria-pressed="false" aria-label="Gefällt mir, 0">👍 0</button><p class="cms-community-flash" role="status">Ok</p>')), 'gute Formulare, Bilder, Listen, Tabellen und Schaltflächen: keine Verstöße');

    // [Regel, Seite (HTML), ganzes Dokument?]
    $cases = [
        ['html-has-lang', str_replace('<html lang="de">', '<html>', goodPage()), true],
        ['html-lang-valid', str_replace('lang="de"', 'lang="de de"', goodPage()), true],
        ['document-title', str_replace('<title>Seite</title>', '', goodPage()), true],
        ['page-has-heading-one', goodPage('<h2>Nur zwei</h2>'), true],
        ['page-has-heading-one', goodPage('<h1>Eins</h1><h1>Zwei</h1>'), true],
        ['heading-order', goodPage('<h1>Titel</h1><h3>Sprung</h3>'), true],
        ['empty-heading', goodPage('<h1>Titel</h1><h2></h2>'), true],
        ['landmark-one-main', str_replace('<main id="main-content" tabindex="-1"><h1>Titel</h1><p>Text</p></main>', '<main id="main-content"><h1>Titel</h1></main><main><p>x</p></main>', goodPage()), true],
        ['skip-link', str_replace('<a class="skip-link" href="#main-content">Zum Inhalt springen</a>', '', goodPage()), true],
        ['skip-link', str_replace('href="#main-content"', 'href="#gibtsnicht"', goodPage()), true],
        ['region', goodPage('<h1>Titel</h1>', '', '<div>Inhalt außerhalb</div>'), true],
        ['landmark-unique', str_replace('<footer>', '<nav><a href="/x">x</a></nav><footer>', goodPage()), true],
        ['landmark-unique', str_replace('<footer>', '<nav aria-label="Hauptnavigation"><a href="/x">x</a></nav><footer>', goodPage()), true],
        ['image-alt', goodPage('<h1>T</h1><img src="a.png">'), true],
        ['svg-img-alt', goodPage('<h1>T</h1><svg role="img"><path d=""/></svg>'), true],
        ['label', goodPage('<h1>T</h1><input type="text" name="a" placeholder="Nur Platzhalter">'), true],
        ['label', goodPage('<h1>T</h1><textarea name="b"></textarea>'), true],
        ['select-name', goodPage('<h1>T</h1><select><option>a</option></select>'), true],
        ['form-field-multiple-labels', goodPage('<h1>T</h1><label for="a">1</label><label for="a">2</label><input id="a" type="text">'), true],
        ['button-name', goodPage('<h1>T</h1><button type="button"></button>'), true],
        ['button-name', goodPage('<h1>T</h1><button type="button"><span aria-hidden="true">👍</span></button>'), true],
        ['link-name', goodPage('<h1>T</h1><a href="/x"></a>'), true],
        ['label-content-name-mismatch', goodPage('<h1>T</h1><button type="button" aria-label="like">👍 0</button>'), true],
        ['label-content-name-mismatch', goodPage('<h1>T</h1><a href="/x" aria-label="Zur Startseite">Weiter lesen</a>'), true],
        ['radiogroup', goodPage('<h1>T</h1><label><input type="radio" name="m" value="a"> A</label><label><input type="radio" name="m" value="b"> B</label>'), true],
        ['frame-title', goodPage('<h1>T</h1><iframe src="/x"></iframe>'), true],
        ['duplicate-id', goodPage('<h1>T</h1><p id="x">a</p><p id="x">b</p>'), true],
        ['aria-valid-attr-value', goodPage('<h1>T</h1><p aria-labelledby="gibtsnicht">a</p>'), true],
        ['aria-valid-attr-value', goodPage('<h1>T</h1><button type="button" aria-pressed="ja">a</button>'), true],
        ['aria-valid-attr-value', goodPage('<h1>T</h1><label for="fehlt">a</label>'), true],
        ['anchor', goodPage('<h1>T</h1><a href="#fehlt">Sprung</a>'), true],
        ['aria-roles', goodPage('<h1>T</h1><div role="schalter">a</div>'), true],
        ['aria-valid-attr', goodPage('<h1>T</h1><div aria-foo="x">a</div>'), true],
        ['aria-hidden-focus', goodPage('<h1>T</h1><div aria-hidden="true"><a href="/x">Link</a></div>'), true],
        ['tabindex', goodPage('<h1>T</h1><div tabindex="3">a</div>'), true],
        ['accesskey', goodPage('<h1>T</h1><a href="/x" accesskey="s">Suche</a>'), true],
        ['listitem', goodPage('<h1>T</h1><li>lose</li>'), true],
        ['list', goodPage('<h1>T</h1><ul><div>kein li</div></ul>'), true],
        ['definition-list', goodPage('<h1>T</h1><dt>x</dt>'), true],
        ['table', goodPage('<h1>T</h1><table><tr><td>a</td></tr></table>'), true],
        ['th-has-data-cells', goodPage('<h1>T</h1><table><tr><th>a</th></tr><tr><td>b</td></tr></table>'), true],
        ['link-target', goodPage('<h1>T</h1><a href="https://example.org" target="_blank">extern</a>'), true],
        ['meta-viewport', goodPage('<h1>T</h1>', '<meta name="viewport" content="width=device-width, user-scalable=no">'), true],
        ['meta-refresh', goodPage('<h1>T</h1>', '<meta http-equiv="refresh" content="5">'), true],
        ['aria-live', goodPage('<h1>T</h1><p class="cms-community-flash">Gespeichert</p>'), true],
        ['aria-live', goodPage('<h1>T</h1><div id="cc-preview" class="x" hidden></div>'), true],
    ];
    foreach ($cases as [$rule, $html]) {
        $found = A11yRules::check($html);
        T::check(array_filter($found, static fn (string $line): bool => str_starts_with($line, $rule . ':')) !== [], 'Regel ' . $rule . ' erkennt den Verstoß', implode("\n", $found));
    }

    // Was nicht zur Seite gehört, zählt nicht: versteckte Teile, geschlossene Dialoge, Vorlagen
    $ignored = goodPage('<h1>Titel</h1><dialog><h2></h2><button type="button"></button></dialog><div hidden><img src="a.png"><a href="/x"></a></div><template><h4></h4></template>');
    T::same([], A11yRules::check($ignored), 'geschlossener Dialog, [hidden] und <template> werden nicht geprüft');
    $open = goodPage('<h1>Titel</h1><dialog open><button type="button"></button></dialog>');
    T::check(A11yRules::check($open) !== [], 'ein geöffneter Dialog wird geprüft');
    // Ausschnitt: ohne Dokumentregeln
    T::same([], A11yRules::check('<div><p>nur ein Ausschnitt</p></div>', false), 'Ausschnitt ohne Dokumentregeln');
    T::check(A11yRules::check('<div><img src="a.png"></div>', false) !== [], 'Ausschnitt: Elementregeln gelten');
});

// ── Seiten ─────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Seiten: keine Verstöße gegen die statischen Regeln (axe-Regeln ohne Browser)', static function (): void {
    $w = CommunityPages::create();
    try {
        foreach (a11yPages($w) as [$key, $label, $path, $viewer, $query]) {
            $page = $w->request($path, $viewer, $query);
            $html = $w->html($page);
            T::same([], A11yRules::check($html), $label . ': keine Verstöße');
            T::check(substr_count($html, '<main') === 1, $label . ': genau ein <main> (das Theme liefert ihn; die Vorlage keinen)');
            T::check(str_contains($html, 'id="main"'), $label . ': der Anker #main der Vorlage bleibt');
        }
        foreach (['themen', 'beitraege', 'abos', 'einstellungen', 'moderation'] as $tab) {
            $page = $w->member($tab, $tab === 'moderation' ? 'admin' : 'member');
            T::same([], A11yRules::check($w->memberHtml($page)), 'Mitgliederbereich ' . $tab . ': keine Verstöße');
        }
    } finally {
        $w->close();
    }
});

/**
 * @return DOMXPath
 */
function xp(string $html): DOMXPath
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    return new DOMXPath($doc);
}

T::run('Aufbau der Themenseite: Landmarken, Überschrift, Beiträge, Reaktionen, Seitenwechsel, Namen für Screenreader', static function (): void {
    $w = CommunityPages::create();
    try {
        $long = (int) $w->ids['long'];
        $page = $w->request('/forum/thema/' . $long . '-langer-austausch/seite-2', 'member');
        $x = xp($w->html($page));
        T::same('Langer Austausch über Netzwerke', trim($x->evaluate('string(//h1)')), 'die Überschrift der Seite ist der Titel des Themas');
        T::same(1, (int) $x->evaluate('count(//h1)'), 'genau eine Überschrift der Ebene 1');
        T::same('Brotkrumen', $x->evaluate('string(//nav[contains(@class,"crumbs")]/@aria-label)'), 'Brotkrumenpfad ist eine benannte Navigation');
        $articles = $x->query('//article');
        T::same(15, $articles->length, 'jeder Beitrag der Seite ist ein <article> (15)');
        $unnamed = 0;
        foreach ($articles as $article) {
            $id = (string) $article->getAttribute('aria-labelledby');
            $unnamed += $id !== '' && $x->query('//*[@id="' . $id . '"]')->length === 1 ? 0 : 1;
        }
        T::same(0, $unnamed, 'jeder Beitrag ist über den Namen des Autors benannt (aria-labelledby)');
        T::check($x->query('//article//time[@datetime]')->length === 15, 'jedes Datum ist ein <time datetime>');
        // Seitenwechsel: benannte Navigation, Zurück und Weiter, Position als Text
        $pager = $x->query('//nav[@aria-label="Seiten"]');
        T::same(1, $pager->length, 'Seitenwechsel ist eine benannte Navigation');
        T::check($x->query('//nav[@aria-label="Seiten"]//a[@rel="prev"]')->length === 1 && $x->query('//nav[@aria-label="Seiten"]//a[@rel="next"]')->length === 1, 'Seitenwechsel: Zurück (rel=prev) und Weiter (rel=next)');
        T::same('Seite 2 von 3', trim($x->evaluate('string(//nav[@aria-label="Seiten"]/span)')), 'Seitenwechsel nennt die Position als Text');
        // Reaktionen: Gruppe, Name mit Reaktion und Zähler, Zustand
        $buttons = $x->query('//div[@data-cc-reactions]//button');
        T::same(60, $buttons->length, 'Reaktionen: vier Schaltflächen je Beitrag (60)');
        T::same('group', $x->evaluate('string(//div[@data-cc-reactions][1]/@role)') . '', 'Reaktionen: role="group"');
        T::same('Reaktionen', $x->evaluate('string(//div[@data-cc-reactions][1]/@aria-label)'), 'Reaktionen: Gruppe heißt „Reaktionen“');
        $names = [];
        foreach ($x->query('//article[1]//div[@data-cc-reactions]//button') as $button) {
            $names[] = $button->getAttribute('aria-label') . '|' . $button->getAttribute('aria-pressed');
        }
        T::same(['Gefällt mir, 0|false', 'Liebe, 0|false', 'Lustig, 0|false', 'Gute Idee, 0|false'], $names, 'Reaktionen: deutscher Name, Zähler und Zustand (nicht der Schlüssel „like“)');
        T::same(4, (int) $x->evaluate('count(//article[1]//div[@data-cc-reactions]//button/span[@aria-hidden="true"])'), 'Reaktionen: das Emoji ist für Screenreader verborgen (der Name steht im aria-label)');
        // Aktionen der Beiträge sind Links oder echte Schaltflächen (keine Klickflächen)
        T::same(0, (int) $x->evaluate('count(//*[@onclick or @role="button"][not(self::button)])'), 'keine Klickflächen: Aktionen sind <a> oder <button>');
        T::check($x->query('//button[@data-cc-action]')->length > 0 && $x->query('//button[@data-cc-action][not(@type="button")]')->length === 0, 'Schaltflächen der Aktionen haben type="button"');
        // Abo-Schalter: Zustand per aria-pressed, Name wechselt mit dem Zustand über data-label-*
        T::same('true', $x->evaluate('string(//button[@data-cc-action="subscribe"]/@aria-pressed)'), 'Abo-Schalter trägt aria-pressed (das Mitglied hat das Thema abonniert)');
        T::same('Abo beenden', trim($x->evaluate('string(//button[@data-cc-action="subscribe"])')), 'Abo-Schalter: Beschriftung passt zum Zustand');
        // Statuszeile für Skript-Meldungen: Live-Region wird vom Skript beim Anzeigen mit Rolle versehen
        T::check($x->query('//*[@id="cc-status"][@hidden]')->length === 1, 'Statuszeile #cc-status ist vorhanden');
        $js = (string) file_get_contents(ABSPATH . 'assets/js/community/community.js');
        T::check(str_contains($js, "status.setAttribute('role', isError ? 'alert' : 'status')"), 'community.js: Meldungen erhalten role="alert" (Fehler) oder role="status"');

        // Melden-Dialog (Vorlage im Skript): ein <dialog> mit Name, Beschriftungen und Schließen per Escape
        $html = $w->html($page);
        T::check(substr_count($html, '<dialog id="cc-dialog"') === 1 && str_contains($js, 'dialog.showModal()'), 'der Melden-Dialog ist ein modales <dialog> (showModal: Hintergrund inert, Escape schließt)');
        T::check(preg_match('/<dialog[^>]*>\s*<form method="dialog">\s*<h2 data-cc-title>/', $html) === 1, 'Dialog: Überschrift im Dialog, Formular mit method="dialog"');
        T::check(str_contains($js, "createElement('label')") && str_contains($js, 'label.appendChild(input)'), 'community.js: Felder des Dialogs stecken in ihrem <label> (Beschriftung ohne ID-Verweis)');
        T::check(str_contains($js, 'const opener = document.activeElement;') && str_contains($js, 'opener.focus()'), 'community.js: der Fokus kehrt nach dem Schließen zum auslösenden Element zurück (WCAG 2.4.3)');
    } finally {
        $w->close();
    }
});

T::run('Aufbau von Forum, Startseite, Liste und Suche: Überschriften, Navigation, Formular, Listen', static function (): void {
    $w = CommunityPages::create();
    try {
        $x = xp($w->html($w->request('/forum', null)));
        T::same('Community', trim($x->evaluate('string(//h1)')), 'Startseite: Überschrift „Community“');
        T::check($x->query('//h2')->length >= 1, 'Startseite: Kategorien sind Überschriften der Ebene 2');
        T::same('Community', $x->evaluate('string(//nav[contains(@class,"cms-community-nav")]/@aria-label)'), 'Navigation der Community ist benannt');
        T::same('page', $x->evaluate('string(//nav[contains(@class,"cms-community-nav")]//a[@aria-current]/@aria-current)'), 'Navigation: die aktuelle Seite trägt aria-current="page"');
        T::same('search', $x->evaluate('string(//form[contains(@class,"nav__search")]/@role)'), 'Suchformular der Navigation: role="search"');
        T::same('Forum durchsuchen', trim($x->evaluate('string(//label[@for="cc-q"])')), 'Suchfeld hat eine Beschriftung (unsichtbar, nicht nur Platzhalter)');

        $x = xp($w->html($w->request('/forum/technik', null)));
        T::same('Technik', trim($x->evaluate('string(//h1)')), 'Forum: Überschrift ist der Forumstitel');
        T::same('page', $x->evaluate('string(//nav[@aria-label="Brotkrumen"]//*[@aria-current]/@aria-current)'), 'Brotkrumen: das aktuelle Forum trägt aria-current="page"');
        T::check($x->query('//ul[contains(@class,"topics")]/li')->length >= 20, 'Forum: Themen als Liste (<ul>/<li>)');
        T::check($x->query('//dl')->length >= 20 && $x->query('//dl/div/dt')->length > 0, 'Forum: Kennzahlen als Definitionsliste');

        $x = xp($w->html($w->request('/forum/suche', null, ['q' => 'Einrichtung'])));
        T::same('Suche', trim($x->evaluate('string(//h1)')), 'Suche: Überschrift');
        T::same('Nur in diesem Forum', trim($x->evaluate('string(//label[@for="cc-forum"])')), 'Suche: Forumsauswahl mit Beschriftung');
        $x = xp($w->html($w->request('/forum/neu', null)));
        T::check(trim($x->evaluate('string(//h1)')) !== '', 'Liste: Überschrift vorhanden');

        // Schreibseiten: Beschriftungen, Pflichtfelder, Fehlermeldung als Alarm, ohne JavaScript bedienbar
        $x = xp($w->html($w->request('/forum/technik/neues-thema', 'member')));
        T::same('Titel', trim($x->evaluate('string(//label[@for="cc-title"])')), 'Neues Thema: Titel beschriftet');
        T::same('Text', trim($x->evaluate('string(//label[@for="cc-body"])')), 'Neues Thema: Text beschriftet');
        T::check($x->query('//input[@id="cc-title"][@required] | //textarea[@id="cc-body"][@required]')->length === 2, 'Neues Thema: Pflichtfelder sind required');
        T::check($x->query('//form[@method="post"]//button[@type="submit"] | //form[@method="post"]//input[@type="submit"]')->length >= 1, 'Neues Thema: Absenden-Schaltfläche');
        T::check($x->query('//*[@id="cc-preview"][@aria-live="polite"]')->length === 1, 'Neues Thema: die Vorschau ist eine Live-Region (aria-live="polite")');
    } finally {
        $w->close();
    }
});

T::run('Zustände: Hinweis nach dem Schreiben, Fehlermeldung, nicht gefunden, Schreiben ohne Konto', static function (): void {
    $w = CommunityPages::create();
    try {
        $long = (int) $w->ids['long'];
        // Hinweis aus einem Schreibvorgang (Session): Live-Region mit role="status"
        $page = $w->request('/forum/thema/' . $long . '-langer-austausch', 'member');
        $page['vars']['flash'] = 'Dein Beitrag wartet auf Freigabe.';
        $html = $w->html($page);
        T::same([], A11yRules::check($html), 'Thema mit Hinweis: keine Verstöße');
        T::check(str_contains($html, 'role="status">Dein Beitrag wartet auf Freigabe.'), 'Hinweis ist role="status"');
        foreach (['/forum/technik' => 'forum', '/forum/neu' => 'listing'] as $path => $view) {
            $page = $w->request($path, 'member');
            $page['vars']['flash'] = 'Gespeichert.';
            T::check(str_contains($w->html($page), 'role="status">Gespeichert.'), $view . ': Hinweis ist role="status"');
        }
        // Fehler im Formular: role="alert"
        $page = $w->request('/forum/technik/neues-thema', 'member');
        $page['vars']['error'] = 'Der Titel ist zu kurz.';
        $html = $w->html($page);
        T::same([], A11yRules::check($html), 'Formular mit Fehlermeldung: keine Verstöße');
        T::check(str_contains($html, 'role="alert">Der Titel ist zu kurz.'), 'Fehlermeldung ist role="alert"');
        // nicht gefunden
        $page = $w->request('/forum/thema/999999-gibt-es-nicht', null);
        T::same(['not-found', 404], [$page['view'], $page['status']], 'unbekanntes Thema: Seite „nicht gefunden“ mit Status 404');
        T::same([], A11yRules::check($w->html($page)), 'nicht gefunden: keine Verstöße');
        T::same(1, (int) xp($w->html($page))->evaluate('count(//h1)'), 'nicht gefunden: eine Überschrift');
        // Schreiben ohne Konto: erklärende Seite statt leerem Formular
        $page = $w->request('/forum/technik/neues-thema', null);
        T::same([401, 'compose'], [$page['status'], $page['view']], 'Schreiben ohne Konto: Hinweisseite (401)');
        T::same([], A11yRules::check($w->html($page)), 'Hinweisseite: keine Verstöße');
        T::check(xp($w->html($page))->query('//a[contains(@href, "login") or contains(@href, "anmelden")]')->length >= 1 || str_contains($w->html($page), 'anmelden'), 'Hinweisseite nennt den Weg zur Anmeldung');
    } finally {
        $w->close();
    }
});

T::run('Mitgliederbereich: benannte Reiter, Tabellen mit Kopfzellen, beschriftete Formulare', static function (): void {
    $w = CommunityPages::create();
    try {
        $x = xp($w->memberHtml($w->member('einstellungen', 'member')));
        T::same('Community-Bereiche', $x->evaluate('string(//ul[contains(@class,"nav-tabs")]/@aria-label)'), 'Reiter: Liste ist benannt');
        T::same(1, (int) $x->evaluate('count(//ul[contains(@class,"nav-tabs")]//a[@aria-current="page"])'), 'Reiter: genau ein aktueller Reiter (aria-current)');
        T::check($x->query('//form//label | //form//fieldset/legend')->length >= 3, 'Einstellungen: Felder sind beschriftet, Gruppen haben eine Legende');
        T::check($x->query('//fieldset/legend')->length >= 1, 'Einstellungen: Gruppe von Auswahlfeldern in <fieldset> mit <legend>');
        $x = xp($w->memberHtml($w->member('abos', 'member')));
        T::check($x->query('//table//th[@scope="col"]')->length >= 3, 'Abos: Tabelle mit Spaltenköpfen (scope="col")');
        T::same(0, (int) $x->evaluate('count(//table//td[not(normalize-space())][not(.//button or .//a or .//form)])'), 'Abos: keine leeren Datenzellen');
        T::check($x->query('//button[normalize-space()="Abo beenden"]')->length === 4, 'Abos: je Zeile eine Schaltfläche „Abo beenden“ (4)');
        $x = xp($w->memberHtml($w->member('themen', 'member')));
        T::check($x->query('//table//th[@scope="col"]')->length >= 3, 'Meine Themen: Tabelle mit Spaltenköpfen');
        T::check($x->query('//*[@role="status"]')->length >= 1, 'Meine Themen: der Hinweis „Wartet auf Freigabe“ ist role="status"');
        $x = xp($w->memberHtml($w->member('moderation', 'admin')));
        T::check($x->query('//form//label[contains(@class,"visually-hidden")]')->length >= 1, 'Moderation: das Grund-Feld hat eine unsichtbare Beschriftung');
    } finally {
        $w->close();
    }
});

// ── Stile ──────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Stile (community.css): Fokus, Links im Text, Bewegung, Dunkelmodus ohne Theme-Variablen', static function (): void {
    $css = (string) file_get_contents(ABSPATH . 'assets/css/community.css');
    $withoutComments = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    T::check(preg_match('/outline\s*:\s*(none|0)\b/i', $withoutComments) !== 1, 'kein „outline: none“ ohne Ersatz (die Fokusanzeige des Browsers bleibt)');
    // Links im Fließtext der Beiträge sind unterstrichen (WCAG 1.4.1: nicht allein durch die Farbe unterscheidbar; das Standard-Theme setzt text-decoration: none)
    T::check(preg_match('/\.cms-community-post__body\s+a\b[^{]*\{[^}]*text-decoration\s*:\s*underline/', $withoutComments) === 1, 'Links im Beitragstext sind unterstrichen');
    // Bewegung: Übergänge und Animationen nur mit Rücksicht auf prefers-reduced-motion
    if (preg_match('/\b(transition|animation)\s*:/', $withoutComments) === 1) {
        T::check(str_contains($withoutComments, 'prefers-reduced-motion'), 'Übergänge und Animationen berücksichtigen prefers-reduced-motion');
    } else {
        T::check(true, 'keine Übergänge oder Animationen');
    }
    // Dunkelmodus: dunkle Rückfallwerte ohne Textfarbe machen den Text in einem hellen Theme unlesbar (dunkle Fläche, dunkler Text)
    T::check(preg_match('/prefers-color-scheme\s*:\s*dark[^}]*--cc-bg/s', $withoutComments) !== 1, 'keine dunkle Rückfallfläche (--cc-bg) im Dunkelmodus: Flächenfarbe und Textfarbe kommen aus demselben Theme');
    // Schriftgrößen nie unter 0,75 rem bzw. 12 px
    preg_match_all('/font-size\s*:\s*([0-9.]+)(rem|em|px)/', $withoutComments, $sizes, PREG_SET_ORDER);
    $small = array_filter($sizes, static fn (array $m): bool => ($m[2] === 'px' ? (float) $m[1] / 16 : (float) $m[1]) < 0.75);
    T::same([], array_map(static fn (array $m): string => $m[0], $small), 'keine Schriftgröße unter 0,75 rem');
    // Bedienelemente haben eine Zielgröße (WCAG 2.2, 2.5.8): Schaltflächen der Reaktionen und der Werkzeugleiste mindestens 24 px hoch
    $target = preg_match('/\.cms-community-reactions button[^{]*\{[^}]*min-height\s*:\s*([0-9.]+)rem/', $withoutComments, $m) === 1 ? (float) $m[1] * 16 : 0.0;
    T::check($target >= 24, 'Schaltflächen der Reaktionen und der Werkzeugleiste: Zielgröße mindestens 24 px (min-height ' . $target . ' px)');
});

// ── Browser ────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Browser (Chromium, Playwright, axe-core): keine ernsten oder kritischen Verstöße, Tastatur, Fokus, Reflow, Dialog', static function (): void {
    $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
    $probe = $node !== '' ? trim((string) @shell_exec(escapeshellarg($node) . ' -e "try{require(\'playwright\');require(\'axe-core\');console.log(\'ok\')}catch(e){console.log(\'no\')}" 2>/dev/null')) : '';
    if ($probe !== 'ok') {
        T::info('Browser-Prüfung übersprungen: node, Playwright (mit Chromium) und axe-core nötig (NODE_PATH auf das Verzeichnis mit axe-core setzen)');

        return;
    }
    $dir = sys_get_temp_dir() . '/cms-community-a11y-' . getmypid();
    @mkdir($dir, 0700, true);
    $w = CommunityPages::create();
    try {
        $manifest = [];
        foreach (a11yPages($w) as [$key, $label, $path, $viewer, $query, $extra]) {
            $page = $w->request($path, $viewer, $query);
            // Das Standard-Theme für alle Seiten; ein Theme mit Dunkelmodus für die Kernseiten (hell und dunkel, Desktop und Mobil)
            $themes = in_array($key, ['startseite', 'forum', 'thema', 'suche', 'neues-thema'], true) ? ['standard', 'dunkel'] : ['standard'];
            foreach ($themes as $theme) {
                $file = $key . '--' . $theme . '.html';
                file_put_contents($dir . '/' . $file, $w->html($page, '/assets/', $theme));
                $manifest[] = ['name' => $label . ' (' . ($theme === 'standard' ? 'Standard-Theme' : 'Theme mit Dunkelmodus') . ')', 'file' => $file] + ($theme === 'standard' ? $extra : []);
            }
        }
        foreach (['themen', 'beitraege', 'abos', 'einstellungen', 'moderation'] as $tab) {
            $file = 'mitglied-' . $tab . '.html';
            file_put_contents($dir . '/' . $file, $w->memberHtml($w->member($tab, $tab === 'moderation' ? 'admin' : 'member')));
            $manifest[] = ['name' => 'Mitgliederbereich ' . $tab, 'file' => $file];
        }
        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    } finally {
        $w->close();
    }
    $output = [];
    exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/browser.cjs') . ' ' . escapeshellarg($dir) . ' 2>&1', $output, $code);
    $text = implode("\n", $output);
    foreach ($output as $line) {
        if (str_starts_with($line, 'Hinweise (nicht blockierend')) {
            T::info(trim(str_replace("\n", ' | ', $text)));
            break;
        }
    }
    T::same(0, $code, 'Browser-Prüfung fehlerfrei: ' . (string) end($output), $text);
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
});

T::finish();
