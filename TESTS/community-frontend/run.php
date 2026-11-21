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

T::run('Ansichten und Controller (Quelltext)', static function (): void {
    foreach (['index', 'forum', 'topic', 'not-found'] as $view) {
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

T::finish();
