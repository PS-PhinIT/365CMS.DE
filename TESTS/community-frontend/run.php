<?php
/**
 * Community-Frontend (PR #4, Teil 1): Routenmuster (Reihenfolge, Treffer wie im Router), Seitensegmente, `PostService::cursorForPage()`
 * (Seitenadresse `seite-N` über Keyset), Ansichten ohne Inline-Skript mit maskierter Ausgabe, Routenanmeldung im Modul-Gate.
 * Die Seiten selbst (Router, Theme) prüft die Suite nicht; die Rechte prüft `community-access`.
 *
 *   php TESTS/community-frontend/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-frontend-' . getmypid() . '/');
date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

// Gegenprobe-Haken (TESTS/lib/Mutant.php): mit CMS_TEST_MUTANT=<name> lädt der Autoloader eine Mutante statt des Originals.
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

use CMS\Services\Community\Http\AttachmentController;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\PostService;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Urls;

/** Nachbildung von `Router::matchRoute()` (gleiche Ersetzung der Platzhalter). */
$match = static function (string $pattern, string $uri): array|false {
    $pattern = preg_replace_callback('/:([a-zA-Z0-9_]+)/', static fn (array $m): string => '([a-zA-Z0-9_-]+)', $pattern);
    if (preg_match('#^' . $pattern . '$#', $uri, $matches) === 1) {
        array_shift($matches);

        return $matches;
    }

    return false;
};
/** Erster Treffer in der Reihenfolge der Eintragung (wie `Router::dispatch()` nach dem Exakttreffer). */
$route = static function (string $uri, string $locale = 'de') use ($match): ?array {
    foreach (Urls::routePatterns($locale) as $name => $pattern) {
        if ($pattern === $uri) {
            return [$name, []];
        }
    }
    foreach (Urls::routePatterns($locale) as $name => $pattern) {
        $params = $match($pattern, $uri);
        if ($params !== false) {
            return [$name, $params];
        }
    }

    return null;
};

T::run('Routenmuster', static function () use ($route): void {
    Settings::setInstance(Settings::fromArray([]));
    try {
        T::same(['index', []], $route('/forum'), 'Startseite');
        T::same(['forum', ['allgemein']], $route('/forum/allgemein'), 'Forum');
        T::same(['forum_page', ['allgemein', 'seite-2']], $route('/forum/allgemein/seite-2'), 'Forum mit Seite');
        T::same(['topic', ['12-hallo']], $route('/forum/thema/12-hallo'), 'Thema geht vor dem Forum-Muster (sonst wäre „thema“ ein Forum)');
        T::same(['topic_page', ['12-hallo', 'seite-3']], $route('/forum/thema/12-hallo/seite-3'), 'Thema mit Seite');
        T::same(['post', ['7']], $route('/forum/beitrag/7'), 'Dauerlink eines Beitrags');
        T::same(['topic', ['12-hallo']], $route('/en/forum/topic/12-hallo', 'en'), 'englische Adresse');
        T::same(['topic_page', ['12-hallo', 'page-2']], $route('/en/forum/topic/12-hallo/page-2', 'en'), 'englisches Seitensegment');
        T::same(['index', []], $route('/en/forum', 'en'), 'englische Startseite');
        T::same(['forum_page', ['thema', '12']], $route('/en/forum/thema/12', 'en'), 'deutsches Segment unter /en ist nur ein Forum-Slug mit Seite: „thema“ ist reserviert, es gibt kein solches Forum (404)');
        T::same(null, $route('/forum/a/b/c'), 'tiefere Pfade gehören nicht zur Community');
        T::same(['unread', ['12']], $route('/forum/thema/12/ungelesen'), 'Ungelesen geht vor topic_page');
        T::same(['attachment', ['5']], $route('/forum/anhang/5'), 'Anhang geht vor forum_page (sonst wäre „anhang“ ein Forum)');
        T::same(['attachment_preview', ['5']], $route('/forum/anhang/5/vorschau'), 'Vorschau eines Anhangs');
        T::same(['attachment_preview', ['5']], $route('/en/forum/attachment/5/preview', 'en'), 'englische Vorschau');
        T::same(['unread', ['12']], $route('/en/forum/topic/12/unread', 'en'), 'englisches Ungelesen');
        foreach (['search' => ['/forum/suche', '/en/forum/search'], 'list_new' => ['/forum/neu', '/en/forum/new'], 'list_active' => ['/forum/aktiv', '/en/forum/active'], 'list_unanswered' => ['/forum/unbeantwortet', '/en/forum/unanswered']] as $name => [$de, $en]) {
            T::same([$name, []], $route($de), $name . ': feste Adresse (de)');
            T::same([$name, []], $route($en, 'en'), $name . ': feste Adresse (en)');
        }
        T::same('/forum/suche', Urls::search(), 'Urls::search');
        T::same('/en/forum/unanswered', Urls::listing('unanswered', 'en'), 'Urls::listing (en)');
        T::same('/forum/neu', Urls::listing('new'), 'Urls::listing (de)');
        T::same(['reply', ['12']], $route('/forum/thema/12/antworten'), 'Antwortformular geht vor topic_page (sonst wäre „antworten“ eine Seite)');
        T::same(['new_topic', ['allgemein']], $route('/forum/allgemein/neues-thema'), 'Neues Thema geht vor forum_page');
        T::same(['edit', ['7']], $route('/forum/beitrag/7/bearbeiten'), 'Bearbeiten-Formular');
        T::same(['reply', ['12']], $route('/en/forum/topic/12/reply', 'en'), 'englisches Antwortformular');
        T::same(['new_topic', ['general']], $route('/en/forum/general/new-topic', 'en'), 'englisches Formular „Neues Thema“');
        T::same(['edit', ['7']], $route('/en/forum/post/7/edit', 'en'), 'englisches Bearbeiten-Formular');
        T::same('/forum/allgemein/neues-thema', Urls::newTopic('allgemein'), 'Urls::newTopic');
        T::same('/forum/thema/12/antworten?zitat=5', Urls::reply(12, 'de', 5), 'Urls::reply mit Zitat');
        T::same('/en/forum/post/7/edit', Urls::edit(7, 'en'), 'Urls::edit (en)');
        T::same(['member', ['3']], $route('/forum/mitglieder/3'), 'Profil geht vor forum_page (sonst wäre „mitglieder“ ein Forum)');
        T::same(['member', ['3']], $route('/en/forum/members/3', 'en'), 'englisches Profil');
        T::same('/en/forum/members/3', Urls::member(3, 'en'), 'Urls::member (en)');

        $order = array_keys(Urls::routePatterns('de'));
        T::check(array_search('topic', $order, true) < array_search('forum_page', $order, true) && array_search('post', $order, true) < array_search('forum', $order, true), 'feste Segmente werden vor dem Forum-Slug eingetragen');

        Settings::setInstance(Settings::fromArray(['base_path' => 'diskussion']));
        T::same('/diskussion/thema/:ref', Urls::routePatterns('de')['topic'], 'base_path wirkt auf die Muster');
    } finally {
        Settings::setInstance(null);
    }
});

T::run('Seitensegment', static function (): void {
    T::same(3, Urls::parsePageSegment('seite-3', 'de'), 'seite-3');
    T::same(3, Urls::parsePageSegment('page-3', 'en'), 'page-3');
    T::same(null, Urls::parsePageSegment('page-3', 'de'), 'Segment der anderen Sprache');
    foreach (['seite-0', 'seite-', 'seite-01', 'seite-1x', 'seite--1', 'seite-9999999', 'x'] as $bad) {
        T::same(null, Urls::parsePageSegment($bad, 'de'), 'ungültig: ' . $bad);
    }
    T::same('/forum/thema/5-x/seite-2', Urls::topic(5, 'x', 'de', 2), 'Urls::topic() bildet dasselbe Segment');
});

T::run('cursorForPage', static function (): void {
    $db = CommunityTestDatabase::create();
    $forum = $db->forum();
    $topic = $db->topic(['forum' => $forum, 'reply_count' => 6]);
    $ids = [];
    foreach (range(1, 7) as $n) {
        $ids[] = $db->post(['topic' => $topic, 'first' => $n === 1]);
    }
    $hidden = $db->post(['topic' => $topic, 'status' => 'hidden']);
    $db->post(['topic' => $topic]); // 8. veröffentlichter Beitrag nach dem ausgeblendeten
    $allow = static fn (?int $user, string $action, array $ctx): AccessDecision => AccessDecision::allow();
    $posts = new PostService($db, Settings::fromArray(['posts_per_page' => 5]), static fn (): int => 1800000000, $allow);

    T::same(0, $posts->cursorForPage($topic, 1), 'Seite 1: ab Anfang');
    T::same($ids[4], $posts->cursorForPage($topic, 2), 'Seite 2 beginnt hinter dem 5. veröffentlichten Beitrag');
    T::same(null, $posts->cursorForPage($topic, 3), 'Seite 3 liegt bei 8 Beiträgen hinter dem Ende (10 wären nötig)');
    T::same(0, $posts->cursorForPage($topic, 0), 'Seite 0 und kleiner: Seite 1');
    // Ein ausgeblendeter Beitrag zählt nicht mit: Seite 2 beginnt trotz seiner ID hinter dem 5. veröffentlichten.
    T::check($hidden > $ids[6], 'Fixture: der ausgeblendete Beitrag liegt hinter den ersten sieben');
    $page2 = $posts->listPage(null, $topic, $posts->cursorForPage($topic, 2));
    T::same(true, $page2['success'], 'listPage mit dem Cursor gelingt');
    T::same(3, count(array_filter($page2['items'], static fn (array $i): bool => ($i['status'] ?? '') === 'published')), 'Seite 2 zeigt die übrigen drei veröffentlichten Beiträge');
});

T::run('Globale Listen', static function (): void {
    $db = CommunityTestDatabase::create();
    $f1 = $db->forum();
    $f2 = $db->forum();
    $f3 = $db->forum();
    $u = $db->user();
    $t1 = $db->topic(['forum' => $f1, 'user' => $u, 'created_at' => '2026-12-01 10:00:00', 'last_post_at' => '2026-12-05 10:00:00', 'reply_count' => 2]);
    $t2 = $db->topic(['forum' => $f1, 'user' => $u, 'created_at' => '2026-12-02 10:00:00', 'last_post_at' => '2026-12-02 10:00:00']);
    $t3 = $db->topic(['forum' => $f2, 'user' => $u, 'created_at' => '2026-12-03 10:00:00', 'last_post_at' => '2026-12-04 10:00:00', 'reply_count' => 1]);
    $t4 = $db->topic(['forum' => $f2, 'user' => $u, 'created_at' => '2026-12-04 10:00:00', 'last_post_at' => '2026-12-04 10:00:00']);
    $db->topic(['forum' => $f3, 'user' => $u, 'created_at' => '2026-12-09 10:00:00', 'last_post_at' => '2026-12-09 10:00:00']);
    $db->topic(['forum' => $f1, 'user' => $u, 'status' => 'hidden', 'created_at' => '2026-12-10 10:00:00', 'last_post_at' => '2026-12-10 10:00:00']);
    $db->topic(['forum' => $f1, 'user' => $u, 'status' => 'pending', 'created_at' => '2026-12-11 10:00:00', 'last_post_at' => '2026-12-11 10:00:00']);
    $repo = new TopicRepository($db);
    $ids = static fn (\CMS\Services\Community\Support\Page $page): array => array_map(static fn (array $r): int => (int) $r['id'], $page->items);

    T::same([$t4, $t3, $t2, $t1], $ids($repo->listGlobal('new', [$f1, $f2])), 'new: jüngste zuerst, nur lesbare Foren, nur veröffentlicht');
    T::same([$t1, $t4, $t3, $t2], $ids($repo->listGlobal('active', [$f1, $f2])), 'active: nach letzter Aktivität, bei gleicher Zeit die höhere ID zuerst');
    T::same([$t4, $t2], $ids($repo->listGlobal('unanswered', [$f1, $f2])), 'unanswered: nur ohne Antwort');
    T::same([], $ids($repo->listGlobal('new', [])), 'ohne lesbares Forum: leer');
    T::same([$t4], $ids($repo->listGlobal('new', [$f2, 999999], null, 1)), 'unbekannte Foren-IDs stören nicht');
    foreach (['new', 'active', 'unanswered'] as $kind) {
        $all = $ids($repo->listGlobal($kind, [$f1, $f2], null, 100));
        $walk = [];
        $page = $repo->listGlobal($kind, [$f1, $f2], null, 1);
        while (true) {
            array_push($walk, ...$ids($page));
            if ($page->next === null) {
                break;
            }
            $page = $repo->listGlobal($kind, [$f1, $f2], \CMS\Services\Community\Support\Cursor::decode($page->next), 1);
        }
        T::same($all, $walk, $kind . ': Blättern mit Cursor liefert dieselbe Reihenfolge ohne Lücke oder Dublette');
    }
    try {
        $repo->listGlobal('x', [$f1]);
        T::check(false, 'unbekannte Art wirft');
    } catch (\InvalidArgumentException) {
        T::check(true, 'unbekannte Art wirft');
    }
});

T::run('Anhänge', static function (): void {
    T::same('attachment; filename="bericht.pdf"; filename*=UTF-8\'\'bericht.pdf', AttachmentController::disposition(false, 'bericht.pdf'), 'Download');
    T::same('inline; filename="bild.png"; filename*=UTF-8\'\'bild.png', AttachmentController::disposition(true, 'bild.png'), 'Bild inline');
    $evil = AttachmentController::disposition(false, "..\\..//ev\"il\r\nSet-Cookie: x=1.pdf");
    T::check(!str_contains($evil, "\r") && !str_contains($evil, "\n") && substr_count($evil, '"') === 2 && !str_contains($evil, '/') && !str_contains($evil, '\\'), 'Kopfzeile ohne Umbruch, Anführungszeichen und Pfadtrenner im Namen: ' . $evil);
    T::check(str_starts_with(AttachmentController::disposition(false, '...'), 'attachment; filename="anhang"'), 'nur Punkte: Ersatzname');
    T::check(str_contains(AttachmentController::disposition(false, 'Übersicht Größe.txt'), "filename*=UTF-8''%C3%9Cbersicht%20Gr%C3%B6%C3%9Fe.txt"), 'Umlaute als filename* (RFC 5987)');
    T::check(str_starts_with(AttachmentController::disposition(false, ' .versteckt'), 'attachment; filename="versteckt"'), 'führender Punkt und Leerraum entfallen');

    $source = (string) file_get_contents(ABSPATH . 'core/Services/Community/Http/AttachmentController.php');
    foreach (["header('X-Content-Type-Options: nosniff')", "Content-Security-Policy: default-src 'none'; sandbox", "'private, no-store'", "'private, max-age=3600'", "resolve(\$viewer, \$id, \$preview)", 'countDownload'] as $needle) {
        T::check(str_contains($source, $needle), 'Auslieferung: ' . $needle);
    }
    T::check(str_contains($source, "!empty(\$file['public']) && \$match === \$etag"), '304 nur für Anhänge, die Gäste sehen dürfen');
    T::check(!str_contains($source, "header('Cache-Control: public"), 'nie Cache-Control: public');
    T::check(str_contains($source, "empty(\$file['inline'])") && str_contains($source, 'AttachmentService::instance()->countDownload'), 'nur Downloads werden gezählt (nicht eingebettete Bilder)');
    $hooks = (string) file_get_contents(ABSPATH . 'core/Services/FeatureHooks/CommunityHooks.php');
    T::check(strpos($hooks, 'AttachmentController::instance()->registerRoutes') < strpos($hooks, 'PublicController::instance()->registerRoutes'), 'Anhang-Routen vor den Leseseiten');
});

T::run('Schreibseiten', static function (): void {
    $controller = (string) file_get_contents(ABSPATH . 'core/Services/Community/Http/ComposeController.php');
    T::check(str_contains($controller, "generateToken('form_guard')"), 'Formulare tragen ein frisches form_guard-Token');
    T::check(str_contains($controller, 'TopicService::instance()->create(') && str_contains($controller, 'PostService::instance()->reply(') && str_contains($controller, 'PostService::instance()->edit('), 'geschrieben wird nur über die Dienste');
    T::check(!str_contains($controller, 'Database') && !str_contains($controller, '->execute('), 'kein eigener Datenbankzugriff im Controller');
    T::check(substr_count($controller, 'header(\'Location: \' . $path, true, 303)') === 1, 'Post/Redirect/Get mit 303');
    T::check(str_contains($controller, "'edit_count' => (int) (\$post['edit_count'] ?? 0)") && str_contains($controller, '$form[\'edit_count\']);'), 'Bearbeiten sendet den Stand edit_count mit (Konflikterkennung)');
    T::check(str_contains($controller, "'seen_last_post_id'"), 'Antwort sendet seen_last_post_id (neue Beiträge erkennen)');
    T::check(str_contains($controller, "\$page->showNotFound()"), 'nicht lesbar: 404');
    $hooks = (string) file_get_contents(ABSPATH . 'core/Services/FeatureHooks/CommunityHooks.php');
    T::check(strpos($hooks, 'ComposeController::instance()->registerRoutes') < strpos($hooks, 'PublicController::instance()->registerRoutes'), 'Schreibseiten werden vor den Leseseiten angemeldet');

    $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $render = static function (array $vars) use ($e): string {
        extract($vars, EXTR_SKIP);
        ob_start();
        include ABSPATH . 'views/community/compose.php';

        return (string) ob_get_clean();
    };
    $base = ['heading' => 'X', 'submit_label' => 'Senden', 'cancel_url' => '/forum', 'title' => '<b>t</b>', 'body' => '</textarea><script>1</script>', 'reason' => '', 'seen' => 4, 'edit_count' => 2, 'error' => null, 'new_posts' => false, 'token' => 'tok"1'];
    $html = $render(array_merge($base, ['mode' => 'new', 'show_title' => true]));
    T::check(str_contains($html, 'name="csrf_token" value="tok&quot;1"'), 'compose: Token maskiert im Formular');
    T::check(!str_contains($html, '<script>1') && str_contains($html, '&lt;/textarea&gt;&lt;script&gt;'), 'compose: Eingaben maskiert (kein Ausbruch aus textarea)');
    T::check(str_contains($html, 'name="title"'), 'compose: Titelfeld bei neuem Thema');
    $html = $render(array_merge($base, ['mode' => 'reply', 'show_title' => false, 'quote' => 3, 'new_posts' => true]));
    T::check(!str_contains($html, 'name="title"') && str_contains($html, 'name="seen_last_post_id" value="4"') && str_contains($html, 'name="force"'), 'compose: Antwort ohne Titel, mit seen_last_post_id und „Trotzdem senden“ bei neuen Beiträgen');
    $html = $render(array_merge($base, ['mode' => 'edit', 'show_title' => false]));
    T::check(str_contains($html, 'name="edit_count" value="2"') && str_contains($html, 'name="reason"'), 'compose: Bearbeiten mit edit_count und Grund');
    $html = $render(array_merge($base, ['mode' => 'edit', 'show_title' => false, 'readonly' => true, 'error' => 'Nein', 'login_url' => '/login']));
    T::check(!str_contains($html, '<form') && str_contains($html, 'role="alert"') && str_contains($html, '/login'), 'compose: nur Hinweis ohne Formular (nicht angemeldet oder keine Berechtigung)');

    $text = new \ReflectionMethod(\CMS\Services\Community\Http\ComposeController::class, 'errorText');
    T::same('Bitte warte 7 Sekunden.', substr($text->invoke(null, ['error' => 'zzz_unbekannt', 'retry_after' => 7]), -23), 'errorText: unbekannter Code, Wartezeit');
    $status = new \ReflectionMethod(\CMS\Services\Community\Http\ComposeController::class, 'statusOf');
    T::same([401, 429, 422], [$status->invoke(null, ['error' => 'login_required']), $status->invoke(null, ['error' => 'rate_limited']), $status->invoke(null, ['error' => 'body_too_short'])], 'statusOf: Ablehnungsgründe mit ihrem Status, Eingabefehler 422');
});

T::run('Ansichten und Controller (Quelltext)', static function (): void {
    foreach (['index', 'forum', 'topic', 'not-found', 'compose', 'listing', 'search', 'member', 'partials/nav'] as $view) {
        $source = (string) file_get_contents(ABSPATH . 'views/community/' . $view . '.php');
        T::check(stripos($source, '<script') === false && stripos($source, '<style') === false && preg_match('/\sstyle\s*=/i', $source) !== 1, $view . ': kein Inline-Skript oder -Style');
        T::check(preg_match('/\son[a-z]+\s*=/i', $source) !== 1, $view . ': keine Ereignisattribute');
    }
    $topic = (string) file_get_contents(ABSPATH . 'views/community/topic.php');
    T::check(substr_count($topic, "(string) (\$post['html'] ?? '')") === 1, 'topic: HTML nur aus dem gereinigten Feld html');
    T::check(!str_contains($topic, "\$post['body']") && !str_contains($topic, 'body_html'), 'topic: weder Rohtext noch body_html');
    foreach (['index', 'forum', 'topic'] as $view) {
        $source = (string) file_get_contents(ABSPATH . 'views/community/' . $view . '.php');
        T::check(preg_match('/<\?=\s*\$(?!e\(|e\b|postId\b)[a-z]/i', $source) !== 1, $view . ': Ausgaben laufen über $e() (Sichtprüfung der Muster)');
    }
    $controller = (string) file_get_contents(ABSPATH . 'core/Services/Community/Http/PublicController.php');
    T::check(str_contains($controller, "header('Cache-Control: private, no-store')"), 'Controller: Seiten sind private, no-store');
    T::check(str_contains($controller, "'noindex' => !\$public ||"), 'Controller: nicht öffentliche Themen sind noindex');
    T::check(substr_count($controller, 'notFound()') >= 8, 'Controller: nicht lesbar und fehlend führen beide zu notFound()');
    T::check(str_contains($controller, 'redirect($canonical, 301)'), 'Controller: falscher Slug → 301 über die ID');
    T::check(!str_contains($controller, '$_POST'), 'Controller liest nichts aus $_POST (nur Leseseiten)');

    $hooks = (string) file_get_contents(ABSPATH . 'core/Services/FeatureHooks/CommunityHooks.php');
    $gate = strpos($hooks, 'if (!CommunityModule::isEnabled())');
    T::check($gate !== false && strpos($hooks, "'register_routes'") > $gate, 'Routen werden nur bei aktivem Modul angemeldet');
    T::check(is_file(ABSPATH . 'assets/css/community.css') && filesize(ABSPATH . 'assets/css/community.css') < 25 * 1024, 'community.css vorhanden und unter 25 KB');
});

T::run('Signaturen: HTML maskiert, nur http(s)-Links, Zeilenumbrüche', static function (): void {
    $html = \CMS\Services\Community\Service\ProfileService::signatureHtml("Gruß <b>Anna</b>\nhttps://example.org/a?b=1&c=2.");
    T::same('Gruß &lt;b&gt;Anna&lt;/b&gt;<br>' . "\n" . '<a href="https://example.org/a?b=1&amp;c=2" rel="nofollow ugc noopener">https://example.org/a?b=1&amp;c=2</a>.', $html, 'maskiert, Link ohne Satzzeichen am Ende');
    T::check(!str_contains(\CMS\Services\Community\Service\ProfileService::signatureHtml('javascript:alert(1) "x" onclick=y'), '<a'), 'keine anderen Schemata als Link');
});

T::finish();
