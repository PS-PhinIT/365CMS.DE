<?php
/**
 * Community – Leistung (PR #5, TEST-PLAN `performance`): Abfragebudget je Seite, keine N+1-Abfragen, keine Abhängigkeit der Abfragezahl von der
 * Datenmenge, kein `OFFSET`, keine Volltabellenscans der großen Tabellen (EXPLAIN). DB-frei (SQLite-Attrappe); die echten Controller, Dienste
 * und Vorlagen laufen auf Testdaten (`TESTS/lib/CommunityPages.php`), jede Anfrage wie in einem neuen PHP-Prozess.
 *
 * Budget („Abfragen je Anfrage“) ist die Zahl der SQL-Anweisungen eines Seitenaufrufs im Dauerbetrieb: Beiträge schon gerendert (`body_html`),
 * Einstellungen im Speicher, Rollenrechte über die Rollentabelle des Cores (eine Abfrage je Rolle, nicht mitgezählt). Die Werte sind die
 * gemessenen Stände als Obergrenze: eine neue Abfrage lässt die Suite scheitern, eine eingesparte darf das Budget senken.
 *
 * Spezifikation (TEST-PLAN §3): „≤ 6 bei warmem Cache“. Im selben Prozess nach einer früheren Anfrage (warme Zwischenspeicher der Dienste)
 * erfüllen Startseite (2), Thema Seite 1 (6), Liste (3) und Suche (5) die Vorgabe; das Forum (7) und Folgeseiten eines Themas (7, die Seite wird
 * über die Position des Beitrags aufgelöst) liegen eine Abfrage darüber. Der Lasttest mit 10.000 Themen und 500.000 Beiträgen gegen MariaDB
 * (EXPLAIN, Seitengrenzen) läuft in `TESTS/community-db`; hier wird die Datenmenge verfünfzehnfacht und die Abfragezahl verglichen.
 *
 *   php TESTS/community-performance/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-performance-' . getmypid() . '/');
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
// Bibliotheken (CommonMark-Bündel, HTMLPurifier) für den echten Renderer der Beiträge.
require_once ABSPATH . 'assets/autoload.php';

use CMS\Services\Community\Support\ReadScope;

/** Spezifikation: Abfragen je Seite bei warmem Cache. */
const SPEC_BUDGET = 6;

/**
 * Seiten und ihr Budget: [Bezeichnung, Adresse (mit {long} für das lange Thema), Konto, Abfrageparameter, erwartete Ansicht, Abfragen, Schreibanweisungen].
 * Themenseiten lesen seit 4.2.0 die Signaturen aller Autoren der Seite in einer Sammelabfrage (`ProfileService::signatures()`): je eine Abfrage mehr.
 *
 * @return list<array{0: string, 1: string, 2: ?string, 3: array<string, string>, 4: string, 5: int, 6: int}>
 */
function budgets(): array
{
    return [
        ['Startseite (Gast)', '/forum', null, [], 'index', 3, 0],
        ['Forum (Gast)', '/forum/technik', null, [], 'forum', 10, 0],
        ['Forum (Mitglied)', '/forum/technik', 'member', [], 'forum', 21, 0],
        ['Thema Seite 1 (Gast)', '/forum/thema/{long}-langer-austausch', null, [], 'topic', 10, 0],
        ['Thema Seite 3 (Gast)', '/forum/thema/{long}-langer-austausch/seite-3', null, [], 'topic', 11, 0],
        ['Thema Seite 1 (Mitglied)', '/forum/thema/{long}-langer-austausch', 'member', [], 'topic', 23, 2],
        ['Thema Seite 1 (Moderation)', '/forum/thema/{long}-langer-austausch', 'admin', [], 'topic', 20, 2],
        ['Liste „neu“ (Gast)', '/forum/neu', null, [], 'listing', 4, 0],
        ['Suche (Gast)', '/forum/suche', null, ['q' => 'Einrichtung'], 'search', 6, 0],
        ['Neues Thema (Mitglied)', '/forum/technik/neues-thema', 'member', [], 'compose', 13, 0], // +1: noch nicht zugeordnete Anhänge der Formular-Kennung (Upload)
        ['Antworten (Mitglied)', '/forum/thema/{long}/antworten', 'member', [], 'compose', 15, 0], // +1: Anhänge der Formular-Kennung
    ];
}

/**
 * Reiter des Community-Bereichs im Mitgliederbereich: [Bezeichnung, Reiter, Konto, Abfragen]. „Meine Antworten“ (früher 28 Abfragen für ein Mitglied und
 * 24 für die Moderation bei 20 Zeilen) und „Abos“ lasen je Zeile das Thema und die Leserechte; jetzt je eine Sammelabfrage für die ganze Seite.
 *
 * @return list<array{0: string, 1: string, 2: string, 3: int}>
 */
function memberBudgets(): array
{
    return [
        ['Meine Themen', 'themen', 'member', 9],
        ['Meine Antworten (Mitglied)', 'beitraege', 'member', 9],
        ['Meine Antworten (Moderation)', 'beitraege', 'admin', 5],
        ['Abos', 'abos', 'member', 10],
        ['Einstellungen', 'einstellungen', 'member', 6],
        ['Moderation', 'moderation', 'admin', 5],
    ];
}

/** Adresse mit der ID des langen Themas. */
function path(CommunityPages $w, string $path): string
{
    return str_replace('{long}', (string) $w->ids['long'], $path);
}

T::run('Seiten: richtige Ansicht, Abfragebudget je Anfrage, Schreibanweisungen beim Lesen', static function (): void {
    $w = CommunityPages::create();
    try {
        $total = 0;
        foreach (budgets() as [$label, $path, $viewer, $query, $view, $max, $writes]) {
            $page = $w->request(path($w, $path), $viewer, $query);
            T::same([$view, 200], [$page['view'], $page['status']], $label . ': Ansicht ' . $view . ', Status 200');
            $count = count($page['queries']);
            $total += $count;
            T::check($count <= $max, $label . ': ' . $count . ' Abfragen (Budget ' . $max . ')', implode("\n", array_map(static fn (string $sql): string => preg_replace('/\s+/', ' ', substr($sql, 0, 140)), $page['queries'])));
            T::check($page['writes'] <= $writes, $label . ': ' . $page['writes'] . ' Schreibanweisungen (höchstens ' . $writes . ': nur der Lesestand des angemeldeten Mitglieds)');
            T::check(!preg_match('/\bOFFSET\b/i', implode(' ', $page['queries'])), $label . ': kein OFFSET');
        }
        T::info('Summe der Budgets aller Seiten: ' . $total . ' Abfragen');
        // Gäste und Mitglieder schreiben beim Lesen nur den Lesestand: nie in Themen, Beiträge, Zähler oder Statistik.
        $page = $w->request(path($w, '/forum/thema/{long}-langer-austausch'), 'admin');
        $tables = [];
        foreach ($page['queries'] as $sql) {
            if (preg_match('/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|UPDATE|DELETE\s+FROM)\s+(\S+)/i', $sql, $m) === 1) {
                $tables[$m[1]] = true;
            }
        }
        T::same(['cms_community_read_states'], array_keys($tables), 'Themenseite (angemeldet): geschrieben wird nur cms_community_read_states');
    } finally {
        $w->close();
    }
});

T::run('Mitgliederbereich: Abfragebudget je Reiter, keine Abfrage je Zeile, nur lesen', static function (): void {
    $w = CommunityPages::create();
    try {
        foreach (memberBudgets() as [$label, $tab, $viewer, $max]) {
            $page = $w->member($tab, $viewer);
            $count = count($page['queries']);
            T::check($count <= $max, $label . ': ' . $count . ' Abfragen (Budget ' . $max . ')', implode("\n", array_map(static fn (string $sql): string => preg_replace('/\s+/', ' ', substr($sql, 0, 140)), $page['queries'])));
            T::same(0, $page['writes'], $label . ': keine Schreibanweisung beim Lesen');
            T::check(!preg_match('/\bOFFSET\b/i', implode(' ', $page['queries'])), $label . ': kein OFFSET');
            T::check(strlen($page['html']) > 400, $label . ': der Reiter zeigt Inhalt (' . strlen($page['html']) . ' Byte)');
        }
        // Zeilen je Seite: „Meine Antworten“ zeigt 20 Zeilen, aber kein Thema wird einzeln gelesen.
        $posts = $w->member('beitraege', 'member');
        T::check(substr_count($posts['html'], 'list-group-item') >= 15, 'Meine Antworten: Seite mit mindestens 15 Zeilen (' . substr_count($posts['html'], 'list-group-item') . ')');
        $singles = array_filter($posts['queries'], static fn (string $sql): bool => preg_match('/FROM cms_community_topics WHERE id = \? LIMIT 1/', $sql) === 1);
        T::same(0, count($singles), 'Meine Antworten: kein Thema wird einzeln gelesen (eine Sammelabfrage für die Seite)');
        $subs = $w->member('abos', 'member');
        T::same(0, count(array_filter($subs['queries'], static fn (string $sql): bool => preg_match('/FROM cms_community_topics WHERE id = \? LIMIT 1/', $sql) === 1)), 'Abos: kein Thema wird einzeln gelesen');
        T::check(substr_count($subs['html'], 'Abo beenden') === 4, 'Abos: vier Zeilen (3 Themen, 1 Forum) mit Schaltfläche „Abo beenden“');
    } finally {
        $w->close();
    }
});

T::run('Spezifikation „≤ 6 Abfragen bei warmem Cache“: im selben Prozess nach einer früheren Anfrage', static function (): void {
    $w = CommunityPages::create();
    try {
        // [Seite, Adresse, Konto, Abfrageparameter, höchstens im warmen Prozess]; über der Vorgabe: Forum und Folgeseiten (siehe Kopf der Suite).
        $cases = [
            ['Startseite', '/forum', null, [], 2],
            ['Thema Seite 1', '/forum/thema/{long}-langer-austausch', null, [], 6],
            ['Liste „neu“', '/forum/neu', null, [], 3],
            ['Suche', '/forum/suche', null, ['q' => 'Einrichtung'], 5],
            ['Forum', '/forum/technik', null, [], 7],
            ['Thema Seite 2', '/forum/thema/{long}-langer-austausch/seite-2', null, [], 7],
        ];
        foreach ($cases as [$label, $path, $viewer, $query, $max]) {
            $w->request(path($w, $path), $viewer, $query);
            $warm = $w->request(path($w, $path), $viewer, $query, false);
            $count = count($warm['queries']);
            T::check($count <= $max, $label . ' (warm): ' . $count . ' Abfragen (höchstens ' . $max . ')');
            if ($max <= SPEC_BUDGET) {
                T::check($count <= SPEC_BUDGET, $label . ': erfüllt die Vorgabe von ' . SPEC_BUDGET . ' Abfragen');
            }
        }
    } finally {
        $w->close();
    }
});

/**
 * Abfragezahlen der Seiten einer Welt (nacheinander aufgebaut: eine Welt ersetzt die Singletons der anderen).
 *
 * @param array{topics?: int, replies?: int} $size
 * @param array<string, mixed> $settings
 * @return array{counts: array<string, int>, views: array<string, string>, topics: int, posts: int, vars: array<string, mixed>}
 */
function measure(array $size, array $settings): array
{
    $w = CommunityPages::create($size, $settings);
    try {
        $counts = [];
        $views = [];
        foreach (budgets() as [$label, $path, $viewer, $query]) {
            $page = $w->request(path($w, $path), $viewer, $query);
            $counts[$label] = count($page['queries']);
            $views[$label] = $page['view'];
        }
        foreach (memberBudgets() as [$label, $tab, $viewer]) {
            $counts[$label] = count($w->member($tab, $viewer)['queries']);
            $views[$label] = 'member-' . $tab;
        }
        $topic = $w->request(path($w, '/forum/thema/{long}-langer-austausch'), 'admin');
        $forum = $w->request('/forum/technik');

        return [
            'counts' => $counts, 'views' => $views, 'topics' => $w->db->count('community_topics'), 'posts' => $w->db->count('community_posts'),
            'vars' => ['posts' => count($topic['vars']['posts'] ?? []), 'topics' => count($forum['vars']['topics'] ?? []), 'next' => (string) ($forum['vars']['next'] ?? '')],
        ];
    } finally {
        $w->close();
    }
}

T::run('Kein N+1: die Abfragezahl hängt nicht von der Zahl der Beiträge und Themen auf der Seite ab', static function (): void {
    // Seitengröße 5 gegen 20 Themen und 5 gegen 15 Beiträge: gleiche Zahl an Abfragen, für Gast, Mitglied und Moderation.
    $small = measure([], ['topics_per_page' => 5, 'posts_per_page' => 5]);
    $large = measure([], ['topics_per_page' => 20, 'posts_per_page' => 15]);
    // Das angeheftete Thema steht zusätzlich zur Seitengröße oben.
    T::same([5, 6], [$small['vars']['posts'], $small['vars']['topics']], 'Vorbedingung: kleine Seiten zeigen 5 Beiträge und 5 Themen (+ 1 angeheftetes)');
    T::same([15, 21], [$large['vars']['posts'], $large['vars']['topics']], 'Vorbedingung: große Seiten zeigen 15 Beiträge und 20 Themen (+ 1 angeheftetes)');
    foreach ($small['counts'] as $label => $count) {
        T::same($count, $large['counts'][$label], $label . ': gleiche Abfragezahl bei 20 statt 5 Themen und 15 statt 5 Beiträgen je Seite');
    }
});

T::run('Datenmenge: fünfzehnfache Menge ändert die Abfragezahl der Seiten nicht', static function (): void {
    $small = measure(['topics' => 20, 'replies' => 6], []);
    $big = measure(['topics' => 300, 'replies' => 20], []);
    T::check($big['posts'] > 6000 && $small['posts'] < 600, 'Vorbedingung: ' . $big['posts'] . ' gegen ' . $small['posts'] . ' Beiträge, ' . $big['topics'] . ' gegen ' . $small['topics'] . ' Themen');
    foreach ($small['counts'] as $label => $count) {
        T::same([$small['views'][$label], $count], [$big['views'][$label], $big['counts'][$label]], $label . ': gleiche Ansicht und Abfragezahl bei ' . $big['topics'] . ' statt ' . $small['topics'] . ' Themen');
    }
    T::check($big['vars']['next'] !== '', 'Forum mit 300 Themen: Cursor für die nächste Seite');

    // Keyset über viele Seiten: die Folgeseite kommt über den Cursor, ohne Zeilenversatz, mit höchstens dem Aufwand der ersten.
    $w = CommunityPages::create(['topics' => 300, 'replies' => 2]);
    try {
        $first = $w->request('/forum/technik');
        $next = (string) ($first['vars']['next'] ?? '');
        $second = $w->request('/forum/technik', null, ['nach' => $next]);
        T::same('forum', $second['view'], 'Folgeseite des Forums über ?nach=');
        T::check($second['vars']['topics'] !== [] && ($second['vars']['topics'][0]['id'] ?? 0) !== ($first['vars']['topics'][0]['id'] ?? -1), 'Folgeseite zeigt andere Themen als die erste');
        T::check(count($second['queries']) <= count($first['queries']), 'Folgeseite des Forums: nicht mehr Abfragen als die erste (' . count($second['queries']) . ' gegen ' . count($first['queries']) . ')');
        T::check(!preg_match('/\bOFFSET\b/i', implode(' ', $second['queries'])), 'Folgeseite des Forums: kein OFFSET');
    } finally {
        $w->close();
    }
});

/**
 * Ausführungsplan einer Anweisung in der SQLite-Attrappe (Planzeilen).
 *
 * @param list<mixed> $params
 * @return list<string>
 */
function plan(CommunityTestDatabase $db, string $sql, array $params): array
{
    $stmt = $db->getPdo()->prepare('EXPLAIN QUERY PLAN ' . CommunityTestDatabase::translate($sql));
    foreach (array_values($params) as $i => $value) {
        $stmt->bindValue($i + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
    }
    $stmt->execute();

    return array_map(static fn (array $row): string => (string) ($row['detail'] ?? ''), $stmt->fetchAll(\PDO::FETCH_ASSOC));
}

/**
 * Sortierung ohne Index (SQLite: „TEMP B-TREE“) ist nur über eine kleine, vorab begrenzte Menge erlaubt:
 * Foren (wenige Zeilen), angeheftete Themen (`is_pinned = 1`), Sperren und Moderationszuweisungen eines Mitglieds, Fluten-Prüfung (`LIMIT 1`, Mitglied und Zeitfenster),
 * ausgeblendete Beiträge im ID-Bereich der Seite (`BETWEEN`), Reaktionen der Beiträge der Seite (`post_id IN`). Die Liste „neu“ (`ORDER BY id DESC
 * LIMIT`) ist der Primärschlüssel rückwärts; SQLite wählt dort einen anderen Index, MariaDB nicht (EXPLAIN-Gate `community-db`).
 */
function sortIsBounded(string $sql): bool
{
    foreach (['FROM cms_community_forums', 'is_pinned = 1', 'FROM cms_community_bans', 'FROM cms_community_forum_moderators', 'LIMIT 1', ' id BETWEEN ', 'post_id IN (', 'ORDER BY id DESC LIMIT ?'] as $needle) {
        if (str_contains($sql, $needle)) {
            return true;
        }
    }

    return false;
}

T::run('EXPLAIN: kein OFFSET, kein Volltabellenscan und keine Sortierung großer Tabellen (Beiträge, Themen, Reaktionen, Lesestand, Rechte)', static function (): void {
    $w = CommunityPages::create(['topics' => 120, 'replies' => 10]);
    try {
        $big = ['cms_community_posts', 'cms_community_topics', 'cms_community_reactions', 'cms_community_read_states', 'cms_community_forum_permissions', 'cms_community_subscriptions'];
        $seen = [];
        $problems = [];
        $cases = [];
        foreach (budgets() as [$label, $path, $viewer, $query]) {
            $cases[] = [$label, path($w, $path), $viewer, $query];
        }
        $cases[] = ['Thema Seite 3 (Moderation)', path($w, '/forum/thema/{long}-langer-austausch/seite-3'), 'admin', []];
        $cases[] = ['Forum, nächste Seite', '/forum/technik', null, ['nach' => (string) ($w->request('/forum/technik')['vars']['next'] ?? '')]];
        $pages = [];
        foreach ($cases as [$label, $path, $viewer, $query]) {
            $pages[$label] = $w->request($path, $viewer, $query);
        }
        foreach (memberBudgets() as [$label, $tab, $viewer]) {
            $pages['Mitgliederbereich: ' . $label] = $w->member($tab, $viewer);
        }
        foreach ($pages as $label => $page) {
            foreach ($page['queries'] as $i => $sql) {
                if (!preg_match('/^\s*SELECT/i', $sql)) {
                    continue;
                }
                $key = preg_replace('/\(\?(?:, \?)*\)/', '(?)', $sql);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                T::check(!preg_match('/\bOFFSET\b/i', $sql), 'kein OFFSET: ' . preg_replace('/\s+/', ' ', substr($sql, 0, 90)));
                foreach (plan($w->db, $sql, $page['params'][$i] ?? []) as $line) {
                    foreach ($big as $table) {
                        $fullScan = preg_match('/^SCAN ' . preg_quote($table, '/') . '\b(?!.*\bUSING (?:COVERING )?INDEX)/', $line) === 1;
                        if ($fullScan) {
                            $problems[] = $label . ': ' . $line . ' ← ' . preg_replace('/\s+/', ' ', substr($sql, 0, 120));
                        }
                    }
                    if (str_contains($line, 'TEMP B-TREE') && !sortIsBounded($sql)) {
                        $problems[] = $label . ': Sortierung ohne Index: ' . preg_replace('/\s+/', ' ', substr($sql, 0, 140));
                    }
                }
            }
        }
        T::check(count($seen) >= 25, count($seen) . ' verschiedene Abfragen der Seiten geprüft (mindestens 25)');
        T::same([], $problems, 'keine Volltabellenscans und keine Sortierung ohne Index');
    } finally {
        $w->close();
    }
});

T::run('Lesebereich (ReadScope): jede Zeile einmal, Schreiben verwirft nur die betroffene Tabelle, nichts bleibt zwischen Anfragen', static function (): void {
    $w = CommunityPages::create();
    try {
        $db = $w->db;
        $topics = new \CMS\Services\Community\Repository\TopicRepository($db);
        $forums = new \CMS\Services\Community\Repository\ForumRepository($db);
        $posts = new \CMS\Services\Community\Repository\PostRepository($db);
        $stats = new \CMS\Services\Community\Repository\UserStatsRepository($db);
        $topic = $w->ids['long'];
        $forum = $w->ids['forum'];
        $post = $w->ids['long_first'];

        // Außerhalb eines Bereichs liest jede Anfrage neu.
        $before = count($db->log);
        $topics->find($topic);
        $topics->find($topic);
        T::same(2, count($db->log) - $before, 'ohne Lesebereich: zwei Abfragen für zwei Aufrufe');
        T::same(false, ReadScope::active(), 'außerhalb: kein Lesebereich aktiv');

        ReadScope::run(static function () use ($db, $topics, $forums, $posts, $stats, $topic, $forum, $post, $w): void {
            T::same(true, ReadScope::active(), 'innerhalb: Lesebereich aktiv');
            $before = count($db->log);
            for ($i = 0; $i < 3; $i++) {
                $topics->find($topic);
                $forums->find($forum);
                $posts->find($post);
                $stats->get($w->ids['member']);
                $forums->tree();
            }
            T::same(5, count($db->log) - $before, 'drei Aufrufe je Art: je eine Abfrage (Thema, Forum, Beitrag, Statistik, Forenbaum)');
            $new = (new \CMS\Services\Community\Repository\ForumRepository($db))->tree();
            T::same(5, count($db->log) - $before, 'eine zweite Instanz teilt den Forenbaum im Bereich (keine weitere Abfrage)');
            T::check(isset($new[$forum]), 'der Forenbaum enthält das Forum');

            // Unbekannte Zeilen werden mit gemerkt (null), ohne weitere Abfrage
            $before = count($db->log);
            $topics->find(99999);
            $topics->find(99999);
            T::same(1, count($db->log) - $before, 'auch ein fehlendes Thema wird nur einmal abgefragt');

            // Schreiben in eine andere Tabelle lässt die Zeilen stehen
            $db->execute('UPDATE cms_community_read_states SET updated_at = updated_at WHERE user_id = 0');
            (new \ReflectionMethod($topics, 'run'))->invoke($topics, 'UPDATE cms_community_read_states SET updated_at = updated_at WHERE user_id = 0');
            $before = count($db->log);
            $topics->find($topic);
            $posts->find($post);
            T::same(0, count($db->log) - $before, 'Schreiben in den Lesestand verwirft keine Themen- und Beitragszeilen');

            // Schreiben in die Themen verwirft nur die Themen
            (new \ReflectionMethod($topics, 'run'))->invoke($topics, 'UPDATE cms_community_topics SET view_count = view_count WHERE id = ?', [$topic]);
            $before = count($db->log);
            $topics->find($topic);
            $posts->find($post);
            $forums->find($forum);
            T::same(1, count($db->log) - $before, 'Schreiben in die Themen: nur das Thema wird neu gelesen, Beitrag und Forum bleiben');

            // Schreiben in die Beiträge, Foren und Statistik
            (new \ReflectionMethod($posts, 'run'))->invoke($posts, 'UPDATE cms_community_posts SET body = body WHERE id = ?', [$post]);
            (new \ReflectionMethod($stats, 'run'))->invoke($stats, 'UPDATE cms_community_user_stats SET updated_at = updated_at WHERE user_id = ?', [$w->ids['member']]);
            $before = count($db->log);
            $posts->find($post);
            $stats->get($w->ids['member']);
            $forums->find($forum);
            T::same(2, count($db->log) - $before, 'Schreiben in Beiträge und Statistik: beide neu gelesen, das Forum bleibt');
            (new \ReflectionMethod($forums, 'run'))->invoke($forums, 'UPDATE cms_community_forums SET updated_at = updated_at WHERE id = ?', [$forum]);
            $before = count($db->log);
            $forums->find($forum);
            (new \CMS\Services\Community\Repository\ForumRepository($db))->tree();
            T::same(2, count($db->log) - $before, 'Schreiben in die Foren: Forum und Forenbaum werden neu gelesen');
            // Eine nicht lesbare Anweisung verwirft alles (sicher)
            ReadScope::forget('WITH x AS (SELECT 1) UPDATE y SET z = 1');
            $before = count($db->log);
            $topics->find($topic);
            T::same(1, count($db->log) - $before, 'unlesbare Anweisung: alles verworfen');
        });
        T::same(false, ReadScope::active(), 'nach dem Bereich: nicht mehr aktiv');
        $before = count($db->log);
        $topics->find($topic);
        T::same(1, count($db->log) - $before, 'nach dem Bereich ist alles vergessen');

        // Ablegen außerhalb eines Bereichs bleibt wirkungslos: der nächste Bereich liest die Zeile aus der Datenbank
        ReadScope::put(ReadScope::key($db, 'topic', $topic), ['id' => $topic, 'title' => 'Veraltet']);
        T::check((ReadScope::run(static fn () => $topics->find($topic)['title'] ?? null)) !== 'Veraltet', 'put() außerhalb eines Bereichs legt nichts ab');
        // Zwei Anfragen nacheinander: der zweite Bereich sieht den Stand der Datenbank, nicht den des ersten (auch bei Schreiben ohne Repository)
        ReadScope::run(static fn () => $topics->find($topic));
        $db->execute('UPDATE cms_community_topics SET title = ? WHERE id = ?', ['Neuer Titel', $topic]);
        T::same('Neuer Titel', ReadScope::run(static fn () => $topics->find($topic)['title'] ?? null), 'ein neuer Lesebereich liest neu: nichts bleibt von der vorigen Anfrage');
        // Verschiedene Zeilen derselben Art bleiben verschieden
        ReadScope::run(static function () use ($topics, $w, $forums, $posts): void {
            $first = $w->ids['first_topic'];
            $long = $w->ids['long'];
            T::same([$first, $long], [(int) $topics->find($first)['id'], (int) $topics->find($long)['id']], 'Themen: jede ID liefert ihre eigene Zeile im Lesebereich');
            T::same([$w->ids['forum'], $w->ids['help']], [(int) $forums->find($w->ids['forum'])['id'], (int) $forums->find($w->ids['help'])['id']], 'Foren: jede ID liefert ihre eigene Zeile im Lesebereich');
            T::same([$w->ids['long_first'], $w->ids['long_first'] + 1], [(int) $posts->find($w->ids['long_first'])['id'], (int) $posts->find($w->ids['long_first'] + 1)['id']], 'Beiträge: jede ID liefert ihre eigene Zeile im Lesebereich');
        });
        // Ausnahme im Bereich: der Bereich wird trotzdem geschlossen
        try {
            ReadScope::run(static function (): void {
                throw new \RuntimeException('Test');
            });
        } catch (\RuntimeException) {
        }
        T::same(false, ReadScope::active(), 'nach einer Ausnahme: nicht mehr aktiv');
        // Verschachtelt: erst der äußere Bereich leert
        ReadScope::run(static function () use ($topics, $topic, $db): void {
            ReadScope::run(static function () use ($topics, $topic): void {
                $topics->find($topic);
            });
            T::same(true, ReadScope::active(), 'innerer Bereich geschlossen, äußerer aktiv');
            $before = count($db->log);
            $topics->find($topic);
            T::same(0, count($db->log) - $before, 'der äußere Bereich behält, was der innere gelesen hat');
        });
        // Zwei Verbindungen teilen nichts
        $other = CommunityTestDatabase::create();
        ReadScope::run(static function () use ($db, $other): void {
            T::check(ReadScope::key($db, 'topic', 1) !== ReadScope::key($other, 'topic', 1), 'Schlüssel enthalten die Verbindung');
        });
    } finally {
        $w->close();
    }
});

T::run('Einbindung (Quelltext): Lesecontroller öffnen den Bereich, Schreibwege nicht, Prüfstelle nie im Betrieb', static function (): void {
    $controller = (string) file_get_contents(ABSPATH . 'core/Services/Community/Http/PublicController.php');
    foreach (['indexPage()', 'forumPage($slug, $pageSegment)', 'topicPage($ref, $pageSegment)', 'listingPage($kind)', 'searchPage()'] as $call) {
        T::check(str_contains($controller, 'ReadScope::run(fn () => $this->' . $call . ')'), 'Lesecontroller: ' . $call . ' läuft im Lesebereich');
    }
    foreach (['ComposeController.php', 'ApiController.php', 'AttachmentController.php'] as $file) {
        T::check(!str_contains((string) file_get_contents(ABSPATH . 'core/Services/Community/Http/' . $file), 'ReadScope'), $file . ': kein Lesebereich (Schreibwege lesen immer frisch)');
    }
    foreach (glob(ABSPATH . 'core/Services/Community/Service/*.php') ?: [] as $file) {
        T::check(!str_contains((string) file_get_contents($file), 'ReadScope'), basename($file) . ': Dienste öffnen keinen Lesebereich');
    }
    T::check(str_contains($controller, 'private static ?\Closure $sink = null;') && substr_count($controller, 'if (self::$sink !== null) {') === 1 && substr_count($controller, 'self::$sink = ') === 1, 'Prüfstelle: nur in render() abgefragt, nur über setSink() gesetzt');
    $callers = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ABSPATH, \FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/vendor/') && str_contains((string) file_get_contents($file->getPathname()), 'PublicController::setSink(')) {
            $callers[] = $file->getPathname();
        }
    }
    T::same([], $callers, 'setSink() wird im ganzen CMS-Quelltext nie aufgerufen (nur von Tests)');
    $abstract = (string) file_get_contents(ABSPATH . 'core/Services/Community/Repository/AbstractRepository.php');
    T::check(str_contains($abstract, 'ReadScope::forget($sql);'), 'jede Schreibanweisung eines Repositories verwirft gemerkte Zeilen ihrer Tabelle');
    foreach (['ForumRepository' => "'forum'", 'TopicRepository' => "'topic'", 'PostRepository' => "'post'", 'UserStatsRepository' => "'stats'"] as $repo => $kind) {
        T::check(str_contains((string) file_get_contents(ABSPATH . 'core/Services/Community/Repository/' . $repo . '.php'), 'ReadScope::key($this->db, ' . $kind), $repo . ': find()/get() über den Lesebereich');
    }
});

T::finish();
