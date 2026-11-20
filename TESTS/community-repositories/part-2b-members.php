<?php
/**
 * Teil der Suite `community-repositories` (Besitzer B2, PR #2b Welle 1): Mitglieder-Repositories.
 *
 *  - UserStatsRepository: Mengen (`ensureMany`, `lockMany` aufsteigend in Stücken zu 100, `statsMany`), Zähler (`bump` mit
 *    Lösungen, `bumpLocked` ohne `INSERT`, `bumpReactions`), Einstellungen (`settingsMany`, `settingsForUpdate`, `saveSettings`),
 *    `touchSeen`, `setRank`, Ränge (`ranks`, `rank`, `insertRank`, `updateRank`, `deleteRank`, `countRanks`, `claimRankSeed`).
 *  - MemberRepository: `accounts` (ohne Passwort), `metaMany` (nur erlaubte Schlüssel).
 *  - ReadStateRepository: `forUsers`, `latestMarksFor`.
 *  - BanRepository: `insert`, `find` (Notiz nur auf Wunsch), `activeList`, `lift`, `forUser`, `listActive`, Umfang `full` für
 *    unbekannte Werte in allen Lesemethoden.
 *  - ReportRepository: jede Methode gegen Fixtures (Formen, Typen, NULL), Grenzen der Fenster, Sperre über IDs (PRIMARY, nur offene),
 *    Bündel mit Keyset und Forenfilter, Umhängen beim Verschieben (nur offene Meldungen).
 *  - Nur MariaDB: echte Zeilensperren (`lockMany`, `settingsForUpdate`, `lockOpenByIds`), keine Lückensperre beim Erledigen, EXPLAIN.
 *
 * Läuft gegen das SQLite-Double und, mit `CMS_TEST_DB_HOST`, gegen MariaDB. Geladen von `run.php` vor `T::finish()`; nutzt dessen
 * Hilfen (`rt()`, `rIds()`, `rSource()`).
 */

declare(strict_types=1);

use CMS\Database;
use CMS\Services\Community\Repository\BanRepository;
use CMS\Services\Community\Repository\MemberRepository;
use CMS\Services\Community\Repository\ReadStateRepository;
use CMS\Services\Community\Repository\ReportRepository;
use CMS\Services\Community\Repository\UserStatsRepository;

/** Ruft `$call` auf und erwartet eine Ausnahme der Klasse `$class`. */
function b2Throws(\Closure $call, string $message, string $class = \InvalidArgumentException::class): void
{
    try {
        $call();
        T::check(false, $message, 'keine Ausnahme');
    } catch (\Throwable $e) {
        T::check($e instanceof $class, $message, get_class($e) . ': ' . $e->getMessage());
    }
}

/** Zahl der protokollierten Anweisungen, die auf das Muster passen. */
function b2Count(Database $db, string $pattern): int
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    return count(array_filter($db->log, static fn (string $sql): bool => preg_match($pattern, $sql) === 1));
}

/**
 * Gebundene Parameter der protokollierten Anweisungen, die auf das Muster passen.
 *
 * @return list<list<mixed>>
 */
function b2Params(Database $db, string $pattern): array
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $params = [];
    foreach ($db->log as $i => $sql) {
        if (preg_match($pattern, $sql) === 1) {
            $params[] = $db->paramsAt($i);
        }
    }

    return $params;
}

/** Erstes Wort jeder protokollierten Anweisung (`SELECT`, `INSERT`, `UPDATE`, …). */
function b2Verbs(Database $db): array
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    return array_map(static fn (string $sql): string => strtoupper((string) strtok(ltrim($sql), " \n")), $db->log);
}

/** Wert eines Schlüssels; `'<fehlt>'`, wenn der Schlüssel fehlt (unterscheidet NULL von „nicht vorhanden“). */
function b2Field(?array $row, string $key): mixed
{
    return $row !== null && array_key_exists($key, $row) ? $row[$key] : '<fehlt>';
}

/** Zeile von `community_user_stats` (ohne Repository). */
function b2Stats(Database $db, int $userId): ?array
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    return $db->row('community_user_stats', ['user_id' => $userId]);
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// UserStatsRepository
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

function b2ScenarioStatsSets(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new UserStatsRepository($db);
    [$a, $b, $c] = [101, 102, 103];
    $db->stats($b, ['post_count' => 7]);

    // ensureMany: eine Anweisung je 100 Mitglieder, IDs eindeutig und aufsteigend, vorhandene Zeilen bleiben
    $db->log = [];
    $repo->ensureMany([$c, $a, $b, $c, 0, -3], rt(5));
    T::same(['INSERT'], b2Verbs($db), 'ensureMany: eine Anweisung');
    T::check(str_starts_with($db->log[0] ?? '', 'INSERT IGNORE INTO ') && str_contains($db->log[0], ' (user_id, updated_at) VALUES (?, ?), (?, ?), (?, ?)'), 'ensureMany: INSERT IGNORE mit einer Zeile je Mitglied', $db->log[0] ?? '');
    T::same([$a, rt(5), $b, rt(5), $c, rt(5)], $db->paramsAt(0), 'ensureMany: IDs eindeutig, aufsteigend, ungültige entfallen');
    T::same([rt(5), rt(5)], [b2Stats($db, $a)['updated_at'] ?? null, b2Stats($db, $c)['updated_at'] ?? null], 'ensureMany legt fehlende Zeilen an');
    T::same([7, CommunityTestDatabase::FIXTURE_TIME], [(int) b2Stats($db, $b)['post_count'], b2Stats($db, $b)['updated_at']], 'ensureMany ändert vorhandene Zeilen nicht');
    $db->log = [];
    $repo->ensureMany([], rt());
    $repo->ensureMany([0, -1], rt());
    T::same(0, count($db->log), 'ensureMany ohne gültige ID: keine Anweisung');
    $many = range(1001, 1150);
    $db->log = [];
    $repo->ensureMany(array_reverse($many), rt(6));
    T::same(2, count($db->log), 'ensureMany: 150 Mitglieder in zwei Anweisungen (höchstens 100 Zeilen je Anweisung)');
    T::same([200, 100], [count($db->paramsAt(0)), count($db->paramsAt(1))], 'ensureMany: 100 und 50 Zeilen');
    T::same([1001, 1101], [$db->paramsAt(0)[0] ?? null, $db->paramsAt(1)[0] ?? null], 'ensureMany: Stücke aufsteigend');
    T::same(150, (int) $db->get_var('SELECT COUNT(*) FROM ' . $db->getPrefix() . 'community_user_stats WHERE user_id BETWEEN 1001 AND 1150'), 'ensureMany: alle 150 Zeilen angelegt');

    // lockMany: Sperre über IN (…) ORDER BY user_id, aufsteigend, ohne Schreibzugriff bei vorhandenen Zeilen
    $db->log = [];
    $repo->lockMany([$c, $a, $b, $a, 0], rt(7));
    T::same(['SELECT'], b2Verbs($db), 'lockMany: eine Anweisung für vorhandene Zeilen (kein Schreiben, kein Lesen ohne Sperre)');
    T::check(str_starts_with($db->log[0] ?? '', 'SELECT user_id FROM ') && str_contains($db->log[0], ' WHERE user_id IN (?, ?, ?) ORDER BY user_id ASC') && str_ends_with($db->log[0], ' FOR UPDATE'), 'lockMany: IN (…) ORDER BY user_id ASC FOR UPDATE', $db->log[0] ?? '');
    T::same([$a, $b, $c], $db->paramsAt(0), 'lockMany: IDs eindeutig und aufsteigend gebunden (Sperrfolge Ebene 4)');
    T::same(rt(5), b2Stats($db, $a)['updated_at'], 'lockMany ändert vorhandene Zeilen nicht');

    // Ausnahmefall: fehlende Zeile wird angelegt und erneut gesperrt
    $missing = 5000;
    $db->log = [];
    $repo->lockMany([$missing, $a], rt(8));
    T::same(['SELECT', 'INSERT', 'SELECT'], b2Verbs($db), 'lockMany mit fehlender Zeile: sperren, anlegen, erneut sperren');
    T::same([[$missing, rt(8)]], b2Params($db, '/^INSERT IGNORE/'), 'lockMany legt nur die fehlende Zeile an');
    T::same(2, b2Count($db, '/ FOR UPDATE$/'), 'beide Leseanweisungen sperren');
    T::same(rt(8), b2Stats($db, $missing)['updated_at'] ?? null, 'die fehlende Zeile existiert danach');

    // Stücke zu höchstens 100, aufsteigend über alle Stücke
    $ids = range(2001, 2250);
    $repo->ensureMany($ids, rt());
    $shuffled = $ids;
    shuffle($shuffled);
    $db->log = [];
    $repo->lockMany($shuffled, rt(9));
    T::same(3, count($db->log), 'lockMany: 250 Mitglieder in drei Sperren');
    T::same([range(2001, 2100), range(2101, 2200), range(2201, 2250)], [$db->paramsAt(0), $db->paramsAt(1), $db->paramsAt(2)], 'lockMany: Stücke zu 100, 100 und 50, aufsteigend über alle Stücke');
    T::same(3, b2Count($db, '/ ORDER BY user_id ASC FOR UPDATE$/'), 'lockMany: jedes Stück sperrt');
    $db->log = [];
    $repo->lockMany([], rt());
    $repo->lockMany([0, -5], rt());
    T::same(0, count($db->log), 'lockMany ohne gültige ID: keine Anweisung');

    // bump mit Lösungen
    $repo->bump($a, 0, 0, rt(10), 2);
    T::same([2, 0, 0, rt(10)], [(int) b2Stats($db, $a)['solutions_count'], (int) b2Stats($db, $a)['topic_count'], (int) b2Stats($db, $a)['post_count'], b2Stats($db, $a)['updated_at']], 'bump: solutions_count + 2');
    $repo->bump($a, 1, 1, rt(11));
    T::same([2, 1, 1], [(int) b2Stats($db, $a)['solutions_count'], (int) b2Stats($db, $a)['topic_count'], (int) b2Stats($db, $a)['post_count']], 'bump ohne fünften Parameter: Lösungen unverändert');
    $repo->bump($a, 0, 0, rt(12), -5);
    T::same(0, (int) b2Stats($db, $a)['solutions_count'], 'bump: Lösungen nie unter 0');
    $fresh = 6000;
    $repo->bump($fresh, 0, 1, rt(13), 1);
    T::same([1, 1], [(int) (b2Stats($db, $fresh)['post_count'] ?? -1), (int) (b2Stats($db, $fresh)['solutions_count'] ?? -1)], 'bump legt die Zeile weiterhin bei Bedarf an');

    // bumpLocked: wie bump, aber ohne INSERT (nur nach lockForUpdate/lockMany)
    $absent = 7000;
    $db->log = [];
    $repo->bumpLocked($absent, 1, 1, rt(14), 1);
    T::same(['UPDATE'], b2Verbs($db), 'bumpLocked: genau eine Anweisung, kein INSERT IGNORE (keine Lückensperre unter Sperren)');
    T::same(null, b2Stats($db, $absent), 'bumpLocked legt keine Zeile an');
    $repo->lockMany([$b], rt(15));
    $db->log = [];
    $repo->bumpLocked($b, 2, 3, rt(16), 1);
    T::same(['UPDATE'], b2Verbs($db), 'bumpLocked auf vorhandener Zeile: eine Anweisung');
    T::check(str_contains($db->log[0] ?? '', ' SET topic_count = CASE '), 'bumpLocked: UPDATE beginnt mit topic_count (Fehlerinjektion der Dienste greift)', $db->log[0] ?? '');
    $row = b2Stats($db, $b);
    T::same([2, 10, 1, rt(16)], [(int) $row['topic_count'], (int) $row['post_count'], (int) $row['solutions_count'], $row['updated_at']], 'bumpLocked: Themen, Beiträge und Lösungen atomar auf dem Datenbankwert');
    $repo->bumpLocked($b, -9, -1, rt(17), -9);
    $row = b2Stats($db, $b);
    T::same([0, 9, 0], [(int) $row['topic_count'], (int) $row['post_count'], (int) $row['solutions_count']], 'bumpLocked: nie unter 0');

    // statsMany
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_user_stats SET post_count = 12, rank_id = 3 WHERE user_id = ?', [$a]);
    $db->log = [];
    $stats = $repo->statsMany([$b, 9999, $a, $b, 0]);
    T::same([$a => ['post_count' => 12, 'rank_id' => 3], $b => ['post_count' => 9, 'rank_id' => null]], $stats, 'statsMany: post_count und rank_id je vorhandenem Mitglied, aufsteigend; unbekannte fehlen');
    T::same(1, count($db->log), 'statsMany: eine Abfrage');
    T::check(str_starts_with($db->log[0] ?? '', 'SELECT user_id, post_count, rank_id FROM '), 'statsMany liest nur die nötigen Spalten', $db->log[0] ?? '');
    $db->log = [];
    T::same([], $repo->statsMany([]), 'statsMany ohne IDs: []');
    T::same(0, count($db->log), 'statsMany ohne IDs: keine Abfrage');
    $all = $repo->statsMany($many);
    T::same([150, 1001, 1150], [count($all), array_key_first($all), array_key_last($all)], 'statsMany: 150 Mitglieder vollständig und aufsteigend');
    $db->log = [];
    $repo->statsMany($many);
    T::same(2, count($db->log), 'statsMany: Stücke zu höchstens 100 IDs');
}

function b2ScenarioStatsSettings(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new UserStatsRepository($db);
    $table = $db->getPrefix() . 'community_user_stats';
    [$a, $b, $c, $d, $none] = [11, 12, 13, 14, 15];
    $repo->ensureMany([$a, $b, $c, $d], rt());
    $db->execute("UPDATE {$table} SET settings_json = ?, last_seen_at = ? WHERE user_id = ?", ['{"notify_mail":false,"locale":"en","notify_types":{"reply":true}}', rt(-60), $a]);
    $db->execute("UPDATE {$table} SET settings_json = ? WHERE user_id = ?", ['{kaputt', $b]);
    $db->execute("UPDATE {$table} SET settings_json = ? WHERE user_id = ?", ['[1,2]', $d]);

    // settingsMany
    $db->log = [];
    $many = $repo->settingsMany([$d, $c, $none, $b, $a, $c]);
    T::same(1, count($db->log), 'settingsMany: eine Abfrage');
    T::check(str_starts_with($db->log[0] ?? '', 'SELECT user_id, settings_json, last_seen_at FROM '), 'settingsMany: nur user_id, settings_json, last_seen_at', $db->log[0] ?? '');
    T::same([$a, $b, $c, $d], array_keys($many), 'settingsMany: je vorhandener Zeile, aufsteigend; ohne Zeile fehlt');
    T::same(['settings' => ['notify_mail' => false, 'locale' => 'en', 'notify_types' => ['reply' => true]], 'last_seen_at' => rt(-60)], $many[$a] ?? null, 'settingsMany: dekodiertes JSON und last_seen_at');
    T::same(['settings' => [], 'last_seen_at' => null], $many[$b] ?? null, 'settingsMany: beschädigtes JSON → []');
    T::same(['settings' => [], 'last_seen_at' => null], $many[$c] ?? null, 'settingsMany: NULL → []');
    T::same([], $many[$d]['settings'] ?? null, 'settingsMany: JSON-Liste statt Objekt → []');
    $db->log = [];
    T::same([], $repo->settingsMany([0]), 'settingsMany ohne gültige ID: []');
    T::same(0, count($db->log), 'settingsMany ohne gültige ID: keine Abfrage');

    // settingsForUpdate: Mutex je Empfänger, liefert die Einstellungen
    $db->log = [];
    T::same(['notify_mail' => false, 'locale' => 'en', 'notify_types' => ['reply' => true]], $repo->settingsForUpdate($a), 'settingsForUpdate: dekodierte Einstellungen');
    T::same(1, count($db->log), 'settingsForUpdate: eine Anweisung');
    T::check(str_starts_with($db->log[0] ?? '', 'SELECT settings_json FROM ') && str_ends_with($db->log[0], ' WHERE user_id = ? LIMIT 1 FOR UPDATE'), 'settingsForUpdate: nur settings_json, Zeilensperre über PRIMARY', $db->log[0] ?? '');
    T::same([], $repo->settingsForUpdate($c), 'settingsForUpdate: NULL → []');
    T::same([], $repo->settingsForUpdate($b), 'settingsForUpdate: beschädigtes JSON → []');
    b2Throws(static fn () => $repo->settingsForUpdate($none), 'settingsForUpdate ohne Zeile: RuntimeException (keine Sperre auf eine Lücke, ensure() läuft vorher)', \RuntimeException::class);
    T::same(null, b2Stats($db, $none), 'settingsForUpdate legt keine Zeile an');

    // saveSettings
    $repo->saveSettings($c, ['auto_subscribe' => 'created', 'notify_types' => ['reply' => false], 'name' => 'Ä/ü'], rt(30));
    $row = b2Stats($db, $c);
    T::same(['{"auto_subscribe":"created","notify_types":{"reply":false},"name":"Ä/ü"}', rt(30)], [$row['settings_json'], $row['updated_at']], 'saveSettings: JSON ohne Escapes für Unicode und Schrägstriche, updated_at gesetzt');
    T::same(['auto_subscribe' => 'created', 'notify_types' => ['reply' => false], 'name' => 'Ä/ü'], $repo->settingsForUpdate($c), 'saveSettings und settingsForUpdate: Rundreise');
    $repo->saveSettings($c, [], rt(31));
    T::same([null, rt(31)], [b2Stats($db, $c)['settings_json'], b2Stats($db, $c)['updated_at']], 'saveSettings mit leerer Zuordnung: NULL');
    $repo->saveSettings($c, ['mail_quota' => ['day' => '2026-11-15', 'count' => 3]], rt(32));
    b2Throws(static fn () => $repo->saveSettings($c, ['a', 'b'], rt(33)), 'saveSettings: Liste statt Zuordnung → InvalidArgumentException');
    b2Throws(static fn () => $repo->saveSettings($c, ['x' => "\xff"], rt(33)), 'saveSettings: ungültiges UTF-8 → InvalidArgumentException');
    b2Throws(static fn () => $repo->saveSettings($c, ['x' => str_repeat('a', UserStatsRepository::MAX_SETTINGS_BYTES)], rt(33)), 'saveSettings: größer als MAX_SETTINGS_BYTES → InvalidArgumentException');
    T::same(['{"mail_quota":{"day":"2026-11-15","count":3}}', rt(32)], [b2Stats($db, $c)['settings_json'], b2Stats($db, $c)['updated_at']], 'abgelehnte saveSettings schreiben nichts');

    // touchSeen: höchstens ein Schreibzugriff je Fenster, nur nach oben
    $before = b2Stats($db, $d)['updated_at'];
    T::same(true, $repo->touchSeen($d, rt(100), rt(-200)), 'touchSeen: last_seen_at NULL → gesetzt');
    T::same([rt(100), $before], [b2Stats($db, $d)['last_seen_at'], b2Stats($db, $d)['updated_at']], 'touchSeen setzt nur last_seen_at');
    T::same(false, $repo->touchSeen($d, rt(150), rt(-150)), 'touchSeen innerhalb des Fensters: false');
    T::same(rt(100), b2Stats($db, $d)['last_seen_at'], 'touchSeen innerhalb des Fensters schreibt nicht');
    T::same(false, $repo->touchSeen($d, rt(400), rt(100)), 'touchSeen genau an der Grenze (last_seen_at = Grenze): false');
    T::same(true, $repo->touchSeen($d, rt(401), rt(101)), 'touchSeen nach dem Fenster: true');
    T::same(rt(401), b2Stats($db, $d)['last_seen_at'], 'touchSeen: neuer Zeitpunkt');
    T::same(false, $repo->touchSeen($none, rt(500), rt(200)), 'touchSeen ohne Zeile: false');
    T::same(null, b2Stats($db, $none), 'touchSeen legt keine Zeile an');

    // bumpReactions: zwei Zähler, nie unter 0, ohne INSERT
    $db->log = [];
    $repo->bumpReactions($a, 2, 0, rt(40));
    $repo->bumpReactions($a, -1, 3, rt(41));
    T::same(['UPDATE', 'UPDATE'], b2Verbs($db), 'bumpReactions: je Aufruf eine Anweisung, kein INSERT');
    $row = b2Stats($db, $a);
    T::same([1, 3, rt(41)], [(int) $row['reactions_received'], (int) $row['reactions_given'], $row['updated_at']], 'bumpReactions: erhaltene und gegebene Reaktionen atomar');
    $repo->bumpReactions($a, -10, -10, rt(42));
    T::same([0, 0], [(int) b2Stats($db, $a)['reactions_received'], (int) b2Stats($db, $a)['reactions_given']], 'bumpReactions: nie unter 0');
    $repo->bumpReactions($none, 1, 1, rt(43));
    T::same(null, b2Stats($db, $none), 'bumpReactions legt keine Zeile an');

    // setRank
    $repo->setRank($a, 5, rt(50));
    T::same([5, rt(50)], [(int) b2Stats($db, $a)['rank_id'], b2Stats($db, $a)['updated_at']], 'setRank: manueller Rang');
    $repo->setRank($a, null, rt(51));
    T::same([null, rt(51)], [b2Stats($db, $a)['rank_id'], b2Stats($db, $a)['updated_at']], 'setRank null: automatisch');
    b2Throws(static fn () => $repo->setRank($a, 0, rt(52)), 'setRank 0 → InvalidArgumentException');
    b2Throws(static fn () => $repo->setRank($a, -1, rt(52)), 'setRank negativ → InvalidArgumentException');
    T::same(null, b2Stats($db, $a)['rank_id'], 'abgelehntes setRank schreibt nichts');
}

function b2ScenarioRanks(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new UserStatsRepository($db);
    T::same([0, []], [$repo->countRanks(), $repo->ranks()], 'ohne Ränge: 0 und []');
    $r1 = $repo->insertRank(['title' => 'Neu', 'title_en' => 'New', 'min_posts' => 0, 'sort_order' => 10]);
    $r2 = $repo->insertRank(['title' => 'Team', 'min_posts' => null, 'color' => '#ff0000', 'icon' => 'star', 'sort_order' => 5]);
    $r3 = $repo->insertRank(['title' => 'Aktiv', 'min_posts' => 50, 'sort_order' => 10]);
    T::same([$r2, $r1, $r3], rIds($repo->ranks()), 'ranks: sort_order aufsteigend, bei Gleichstand id aufsteigend');
    T::same(['id' => $r2, 'title' => 'Team', 'title_en' => null, 'min_posts' => null, 'color' => '#ff0000', 'icon' => 'star', 'sort_order' => 5], $repo->rank($r2), 'rank: Form mit Typen (min_posts NULL = nur manuell)');
    T::same(['id' => $r1, 'title' => 'Neu', 'title_en' => 'New', 'min_posts' => 0, 'color' => null, 'icon' => null, 'sort_order' => 10], $repo->ranks()[1] ?? null, 'ranks: gleiche Form wie rank()');
    T::same(null, $repo->rank(9999), 'rank: unbekannt → null');
    T::same(3, $repo->countRanks(), 'countRanks');
    $db->log = [];
    $repo->ranks();
    T::same([UserStatsRepository::MAX_RANKS], $db->paramsAt(0), 'ranks: höchstens MAX_RANKS Zeilen');

    T::same(true, $repo->updateRank($r1, ['title' => 'Neuling', 'min_posts' => 1]), 'updateRank: gefunden und geändert');
    T::same(['Neuling', 1, 'New'], [$repo->rank($r1)['title'] ?? null, $repo->rank($r1)['min_posts'] ?? null, $repo->rank($r1)['title_en'] ?? null], 'updateRank ändert nur die genannten Spalten');
    T::same(true, $repo->updateRank($r1, ['title' => 'Neuling']), 'updateRank mit unveränderten Werten: true (gefunden)');
    T::same(true, $repo->updateRank($r1, ['title_en' => null, 'min_posts' => null]), 'updateRank: NULL setzen');
    T::same([null, null], [b2Field($repo->rank($r1), 'title_en'), b2Field($repo->rank($r1), 'min_posts')], 'updateRank: NULL gespeichert');
    T::same(false, $repo->updateRank(9999, ['title' => 'X']), 'updateRank: unbekannt → false');
    foreach ([
        'ohne Spalten' => [],
        'unbekannte Spalte' => ['id' => 5],
        'leerer Titel' => ['title' => ''],
        'Titel über 80 Zeichen' => ['title' => str_repeat('a', 81)],
        'Titel NULL' => ['title' => null],
        'min_posts negativ' => ['min_posts' => -1],
        'min_posts als Text' => ['min_posts' => 'viele'],
        'sort_order NULL' => ['sort_order' => null],
        'Farbe über 9 Zeichen' => ['color' => '#ff00ff00f'],
        'Symbol über 50 Zeichen' => ['icon' => str_repeat('i', 51)],
    ] as $label => $data) {
        b2Throws(static fn () => $repo->updateRank($r1, $data), 'updateRank lehnt ab: ' . $label);
    }
    b2Throws(static fn () => $repo->insertRank(['min_posts' => 3]), 'insertRank ohne Titel → InvalidArgumentException');
    b2Throws(static fn () => $repo->insertRank(['title' => 'X', 'id' => 99]), 'insertRank mit fremder Spalte → InvalidArgumentException');
    T::same(3, $repo->countRanks(), 'abgelehnte Schreibzugriffe legen keinen Rang an');
    T::same('Neuling', $repo->rank($r1)['title'] ?? null, 'abgelehnte Änderungen schreiben nichts');

    T::same(true, $repo->deleteRank($r3), 'deleteRank: gelöscht');
    T::same(false, $repo->deleteRank($r3), 'deleteRank zweimal: false');
    T::same([2, null], [$repo->countRanks(), $repo->rank($r3)], 'deleteRank entfernt die Zeile');

    // Marke der Grundausstattung in settings
    T::same('community_ranks_seeded', UserStatsRepository::RANK_SEED_MARKER, 'Marke community_ranks_seeded (ohne Punkt: nicht Gruppe community)');
    T::same(true, $repo->claimRankSeed(rt(60)), 'claimRankSeed: erste Beanspruchung');
    $marker = $db->row('settings', ['option_name' => UserStatsRepository::RANK_SEED_MARKER]);
    T::same([rt(60), 0], [$marker['option_value'] ?? null, (int) ($marker['autoload'] ?? -1)], 'Marke mit Zeitpunkt, ohne autoload');
    T::same(false, $repo->claimRankSeed(rt(61)), 'claimRankSeed zweimal: false (UNIQUE option_name)');
    T::same(rt(60), $db->row('settings', ['option_name' => UserStatsRepository::RANK_SEED_MARKER])['option_value'] ?? null, 'die zweite Beanspruchung ändert die Marke nicht');
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// MemberRepository, ReadStateRepository
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

function b2ScenarioMembersBulk(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new MemberRepository($db);
    $a = $db->user(['username' => 'anna', 'email' => 'anna@example.test', 'display_name' => 'Anna A.', 'role' => 'editor']);
    $b = $db->user(['status' => 'inactive']);
    $db->log = [];
    $accounts = $repo->accounts([$b, 99999, $a, $b, 0]);
    T::same([$a, $b], array_keys($accounts), 'accounts: je vorhandenes Konto, aufsteigend; unbekannte fehlen');
    T::same(['id' => $a, 'username' => 'anna', 'email' => 'anna@example.test', 'display_name' => 'Anna A.', 'status' => 'active', 'role' => 'editor'], $accounts[$a] ?? null, 'accounts: Form mit Typen');
    T::same('inactive', $accounts[$b]['status'] ?? null, 'accounts: jeder Status (die Entscheidung trifft der Dienst)');
    T::same(1, count($db->log), 'accounts: eine Abfrage');
    T::check(str_starts_with($db->log[0] ?? '', 'SELECT id, username, email, display_name, status, role FROM ') && stripos($db->log[0], 'password') === false, 'accounts liest weder Passwort noch weitere Spalten', $db->log[0] ?? '');
    $db->log = [];
    T::same([], $repo->accounts([]), 'accounts ohne IDs: []');
    T::same(0, count($db->log), 'accounts ohne IDs: keine Abfrage');
    $ids = [];
    for ($i = 0; $i < 120; $i++) {
        $ids[] = $db->user();
    }
    $db->log = [];
    T::same(120, count($repo->accounts(array_reverse($ids))), 'accounts: 120 Konten');
    T::same(2, count($db->log), 'accounts: Stücke zu höchstens 100 IDs');

    // metaMany
    $meta = $db->getPrefix() . 'user_meta';
    foreach ([[$a, 'email_verified_at', rt(-100)], [$a, 'notification_preferences', '{"email_notifications":true}'], [$a, 'mfa_secret', 'GEHEIM'], [$b, 'notification_preferences', null]] as [$user, $key, $value]) {
        $db->execute("INSERT INTO {$meta} (user_id, meta_key, meta_value) VALUES (?, ?, ?)", [$user, $key, $value]);
    }
    $db->log = [];
    $values = $repo->metaMany([$b, $a, 99999], ['notification_preferences', 'email_verified_at']);
    T::same([$a => ['email_verified_at' => rt(-100), 'notification_preferences' => '{"email_notifications":true}'], $b => ['notification_preferences' => '']], $values, 'metaMany: nur die genannten Schlüssel je Mitglied; NULL → leerer Text; ohne Zeile fehlt');
    T::same([$a, $b], array_keys($values), 'metaMany: Mitglieder aufsteigend');
    T::same(1, count($db->log), 'metaMany: eine Abfrage');
    T::check(!str_contains(json_encode($values) ?: '', 'GEHEIM'), 'metaMany liefert keine anderen Schlüssel');
    T::same([$a => ['email_verified_at' => rt(-100)]], $repo->metaMany([$a, $b], ['email_verified_at']), 'metaMany: ein Schlüssel');
    b2Throws(static fn () => $repo->metaMany([$a], ['mfa_secret']), 'metaMany: Schlüssel außerhalb von META_KEYS → InvalidArgumentException');
    b2Throws(static fn () => $repo->metaMany([$a], ['email_verified_at', 'password_reset_token']), 'metaMany: ein unerlaubter Schlüssel genügt für die Ablehnung');
    $db->log = [];
    T::same([[], []], [$repo->metaMany([], ['email_verified_at']), $repo->metaMany([$a], [])], 'metaMany ohne IDs oder Schlüssel: []');
    T::same(0, count($db->log), 'metaMany ohne IDs oder Schlüssel: keine Abfrage');
    T::same(['email_verified_at', 'notification_preferences'], MemberRepository::META_KEYS, 'META_KEYS: Bestätigung der Adresse und Core-Präferenzen');
}

function b2ScenarioReadStateBulk(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new ReadStateRepository($db);
    [$topic, $other] = [501, 502];
    $repo->advance(1, $topic, 10, rt(10));
    $repo->advance(2, $topic, 20, rt(20));
    $repo->advance(1, $other, 99, rt(30));
    $repo->advance(3, $other, 5, rt(5));
    $db->log = [];
    T::same([1 => ['last_read_post_id' => 10, 'updated_at' => rt(10)], 2 => ['last_read_post_id' => 20, 'updated_at' => rt(20)]], $repo->forUsers($topic, [3, 2, 1, 2, 0]), 'forUsers: nur dieses Thema, je Mitglied mit Lesestatus, aufsteigend');
    T::same(1, count($db->log), 'forUsers: eine Abfrage');
    T::check(str_starts_with($db->log[0] ?? '', 'SELECT user_id, last_read_post_id, updated_at FROM ') && str_contains($db->log[0], ' WHERE topic_id = ? AND user_id IN ('), 'forUsers: Thema und Mitgliederliste (Index idx_community_read_states_topic)', $db->log[0] ?? '');
    $db->log = [];
    T::same([[], []], [$repo->forUsers($topic, []), $repo->forUsers(0, [1])], 'forUsers ohne Mitglieder oder Thema: []');
    T::same(0, count($db->log), 'forUsers ohne Mitglieder oder Thema: keine Abfrage');
    $users = range(3001, 3150);
    foreach ([3001, 3150] as $user) {
        $repo->advance($user, $topic, 7, rt(7));
    }
    $db->log = [];
    T::same([3001, 3150], array_keys($repo->forUsers($topic, $users)), 'forUsers über zwei Stücke');
    T::same(2, count($db->log), 'forUsers: Stücke zu höchstens 100 Mitgliedern');

    $repo->setMark(1, 0, rt(100));
    $repo->setMark(1, 7, rt(200));
    $repo->setMark(2, 7, rt(50));
    $repo->setMark(2, 8, rt(300));
    $repo->setMark(3, 9, rt(400));
    $repo->setMark(4, 0, rt(10));
    $db->log = [];
    T::same([1 => rt(200), 2 => rt(50), 4 => rt(10)], $repo->latestMarksFor([4, 3, 2, 1], [7]), 'latestMarksFor: jüngste Marke über die Foren und die globale; Marken anderer Foren zählen nicht; ohne Marke fehlt');
    T::same(1, count($db->log), 'latestMarksFor: eine Abfrage');
    T::check(str_starts_with($db->log[0] ?? '', 'SELECT user_id, MAX(marked_at) AS marked_at FROM ') && str_ends_with($db->log[0], ' GROUP BY user_id'), 'latestMarksFor: MAX je Mitglied', $db->log[0] ?? '');
    T::same([2 => rt(300)], $repo->latestMarksFor([2], [7, 8]), 'latestMarksFor: mehrere Foren der Kette');
    T::same([1 => rt(100), 4 => rt(10)], $repo->latestMarksFor([1, 2, 4], []), 'latestMarksFor ohne Foren: nur die globale Marke');
    $db->log = [];
    T::same([], $repo->latestMarksFor([], [7]), 'latestMarksFor ohne Mitglieder: []');
    T::same(0, count($db->log), 'latestMarksFor ohne Mitglieder: keine Abfrage');
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// BanRepository
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

function b2ScenarioBans(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new BanRepository($db);
    $user = $db->user();
    $mod = $db->user(['role' => 'moderator']);
    $other = $db->user();
    $now = rt(100);

    $write = $repo->insert(['user_id' => $user, 'scope' => 'write', 'reason' => 'Spam', 'internal_note' => 'GEHEIM-7', 'issued_by' => $mod, 'starts_at' => rt(0), 'ends_at' => rt(3600), 'created_at' => rt(0)]);
    $row = $db->row('community_bans', ['id' => $write]);
    T::same([$user, 'write', 'Spam', 'GEHEIM-7', $mod, rt(0), rt(3600), null, null, rt(0)], [(int) $row['user_id'], $row['scope'], $row['reason'], $row['internal_note'], (int) $row['issued_by'], $row['starts_at'], $row['ends_at'], $row['lifted_at'], $row['lifted_by'], $row['created_at']], 'insert: alle Spalten');
    $full = $repo->insert(['user_id' => $user, 'scope' => 'full', 'reason' => 'Dauerhaft', 'internal_note' => '', 'issued_by' => null, 'starts_at' => rt(10), 'ends_at' => null, 'created_at' => rt(10)]);
    $row = $db->row('community_bans', ['id' => $full]);
    T::same([null, null, null], [$row['internal_note'], $row['issued_by'], $row['ends_at']], 'insert: leere Notiz wird NULL, ohne Aussteller, unbefristet');

    $count = $db->count('community_bans');
    $base = ['user_id' => $user, 'scope' => 'write', 'reason' => 'x', 'starts_at' => rt(0), 'created_at' => rt(0)];
    foreach ([
        'fremde Spalte lifted_at' => ['lifted_at' => rt(1)],
        'fremde Spalte id' => ['id' => 99],
        'unbekannter Umfang' => ['scope' => 'seltsam'],
        'leerer Grund' => ['reason' => ''],
        'Grund über 255 Zeichen' => ['reason' => str_repeat('g', 256)],
        'user_id 0' => ['user_id' => 0],
        'user_id als Text' => ['user_id' => '5'],
        'Aussteller 0' => ['issued_by' => 0],
        'Beginn ohne UTC-Form' => ['starts_at' => '15.11.2026'],
        'Ende vor dem Beginn' => ['ends_at' => rt(-1)],
        'Ende gleich Beginn' => ['ends_at' => rt(0)],
        'Notiz mit ungültigem UTF-8' => ['internal_note' => "\xc3"],
    ] as $label => $change) {
        b2Throws(static fn () => $repo->insert(array_merge($base, $change)), 'insert lehnt ab: ' . $label);
    }
    $missing = $base;
    unset($missing['created_at']);
    b2Throws(static fn () => $repo->insert($missing), 'insert lehnt ab: created_at fehlt');
    T::same($count, $db->count('community_bans'), 'abgelehnte Sperren schreiben nichts');

    // find: ohne Notiz, außer auf Wunsch
    $db->log = [];
    T::same(['id' => $write, 'user_id' => $user, 'scope' => 'write', 'reason' => 'Spam', 'issued_by' => $mod, 'starts_at' => rt(0), 'ends_at' => rt(3600), 'lifted_at' => null, 'lifted_by' => null, 'created_at' => rt(0)], $repo->find($write), 'find: Spalten ohne interne Notiz, Ganzzahlen');
    T::check(stripos($db->log[0] ?? '', 'internal_note') === false, 'find ohne Notiz selektiert sie nicht', $db->log[0] ?? '');
    T::same('GEHEIM-7', $repo->find($write, true)['internal_note'] ?? null, 'find mit Notiz');
    T::same(null, $repo->find(99999), 'find: unbekannt → null');

    // Zustände: aufgehoben, abgelaufen (ends_at = jetzt), geplant, unbekannter Umfang
    $lifted = $db->ban(['user' => $user, 'starts_at' => rt(0), 'lifted_at' => rt(50), 'lifted_by' => $mod]);
    $expired = $db->ban(['user' => $user, 'starts_at' => rt(0), 'ends_at' => $now]);
    $planned = $db->ban(['user' => $user, 'starts_at' => rt(200)]);
    $weird = $db->ban(['user' => $user, 'scope' => 'seltsam', 'reason' => 'Unbekannt', 'issued_by' => $mod, 'starts_at' => rt(20), 'created_at' => rt(20)]);
    $foreign = $db->ban(['user' => $other, 'starts_at' => rt(0)]);
    T::same('full', $repo->find($weird)['scope'] ?? null, 'find: unbekannter Umfang → full');

    $db->log = [];
    $active = $repo->activeList($user, $now);
    T::same([$write, $full, $weird], rIds($active), 'activeList: nur wirksame Sperren des Mitglieds, aufsteigend (ohne aufgehobene, abgelaufene, geplante, fremde)');
    T::same(['id' => $write, 'scope' => 'write', 'ends_at' => rt(3600), 'issued_by' => $mod, 'created_at' => rt(0)], $active[0] ?? null, 'activeList: Form');
    T::same(['full', null], [$active[2]['scope'] ?? null, b2Field($active[1] ?? null, 'issued_by')], 'activeList: unbekannter Umfang → full, fehlender Aussteller → null');
    T::check(stripos($db->log[0] ?? '', 'internal_note') === false, 'activeList selektiert keine Notiz');
    T::same($weird, $repo->activeFor($user, $now)['id'] ?? 0, 'activeFor unverändert: die strengste (alles außer write zuerst, unbekannt zählt als full), dann die jüngste');

    // lift
    T::same(true, $repo->lift($write, $mod, rt(150)), 'lift: offene Sperre aufgehoben');
    $row = $db->row('community_bans', ['id' => $write]);
    T::same([rt(150), $mod], [$row['lifted_at'], (int) $row['lifted_by']], 'lift: Zeit und Aufhebender');
    T::same(false, $repo->lift($write, $other, rt(160)), 'lift zweimal: false');
    $row = $db->row('community_bans', ['id' => $write]);
    T::same([rt(150), $mod], [$row['lifted_at'], (int) $row['lifted_by']], 'zweites lift ändert nichts');
    T::same(false, $repo->lift(99999, $mod, rt(160)), 'lift: unbekannt → false');
    T::same([$full, $weird], rIds($repo->activeList($user, rt(170))), 'aufgehobene Sperre fehlt in activeList');

    // forUser: alle Zustände, jüngste zuerst, Notiz nur auf Wunsch
    $db->log = [];
    $history = $repo->forUser($user);
    T::same([$weird, $planned, $expired, $lifted, $full, $write], rIds($history), 'forUser: alle Sperren des Mitglieds, jüngste zuerst');
    T::check(!array_key_exists('internal_note', $history[0] ?? []) && stripos($db->log[0] ?? '', 'internal_note') === false, 'forUser ohne Notiz');
    T::same(['full', 'write'], [$history[0]['scope'] ?? null, $history[1]['scope'] ?? null], 'forUser: unbekannter Umfang → full');
    T::same([$weird, $planned], rIds($repo->forUser($user, 2)), 'forUser: Grenze');
    T::same('GEHEIM-7', $repo->forUser($user, 50, true)[5]['internal_note'] ?? null, 'forUser mit Notiz');
    T::same([$foreign], rIds($repo->forUser($other)), 'forUser: nur Sperren des Mitglieds');
    T::same(1, count($repo->forUser($user, 0)), 'forUser: Grenze mindestens 1');

    // listActive: alle Mitglieder, id absteigend, Keyset id < before
    $third = $db->user();
    $t1 = $db->ban(['user' => $third, 'scope' => 'full', 'starts_at' => rt(0), 'reason' => 'Drei']);
    $db->log = [];
    $list = $repo->listActive(rt(170), 0, 10);
    T::same([$t1, $foreign, $weird, $full], rIds($list), 'listActive: wirksame Sperren aller Mitglieder, id absteigend; before 0 = erste Seite');
    T::same(['id', 'user_id', 'scope', 'reason', 'issued_by', 'starts_at', 'ends_at', 'created_at'], array_keys($list[0] ?? []), 'listActive: Spalten ohne Notiz und Aufhebung');
    T::same(['full', $third, null], [$list[2]['scope'] ?? null, $list[0]['user_id'] ?? null, b2Field($list[0] ?? null, 'ends_at')], 'listActive: Umfang normalisiert, Ganzzahlen');
    T::same([$weird, $full], rIds($repo->listActive(rt(170), $foreign, 10)), 'listActive: Keyset id < before');
    T::same([$t1, $foreign], rIds($repo->listActive(rt(170), 0, 2)), 'listActive: Grenze');
    T::same([$t1, $foreign, $weird, $planned, $full], rIds($repo->listActive(rt(250), 0, 10)), 'listActive: geplante Sperre wirkt ab starts_at');
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// ReportRepository
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Gemeinsame Daten der Meldungsfälle: zwei Foren, ein Thema mit drei Beiträgen, Mitglieder. */
function b2ReportWorld(Database $db): array
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $f1 = $db->forum(['title' => 'Eins']);
    $f2 = $db->forum(['title' => 'Zwei']);
    $author = $db->user();
    $topic = $db->topic(['forum' => $f1, 'user' => $author]);
    $first = $db->post(['topic' => $topic, 'user' => $author, 'first' => true]);
    $p2 = $db->post(['topic' => $topic, 'user' => $author]);
    $p3 = $db->post(['topic' => $topic, 'user' => $author]);
    $topic2 = $db->topic(['forum' => $f2, 'user' => $author]);
    $q1 = $db->post(['topic' => $topic2, 'user' => $author, 'first' => true]);

    return ['f1' => $f1, 'f2' => $f2, 'author' => $author, 'topic' => $topic, 'first' => $first, 'p2' => $p2, 'p3' => $p3, 'topic2' => $topic2, 'q1' => $q1,
        'u1' => $db->user(), 'u2' => $db->user(), 'u3' => $db->user()];
}

function b2ScenarioReports(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new ReportRepository($db);
    $w = b2ReportWorld($db);

    // insert und find
    $r1 = $repo->insert(['target_type' => 'post', 'target_id' => $w['p2'], 'forum_id' => $w['f1'], 'reporter_id' => $w['u1'], 'reason' => 'spam', 'message' => '', 'status' => 'open', 'notify_reporter' => true, 'created_at' => rt(10)]);
    T::same(['id' => $r1, 'target_type' => 'post', 'target_id' => $w['p2'], 'forum_id' => $w['f1'], 'reporter_id' => $w['u1'], 'reason' => 'spam', 'message' => null, 'status' => 'open', 'resolution_note' => null, 'notify_reporter' => 1, 'handled_by' => null, 'handled_at' => null, 'created_at' => rt(10)], $repo->find($r1), 'insert/find: alle Spalten mit Typen; leerer Text wird NULL');
    $r2 = $repo->insert(['target_type' => 'post', 'target_id' => $w['p2'], 'forum_id' => $w['f1'], 'reporter_id' => $w['u2'], 'reason' => 'abuse', 'message' => 'Beleidigung', 'notify_reporter' => 0, 'created_at' => rt(20)]);
    T::same(['open', 0, 'Beleidigung'], [$repo->find($r2)['status'] ?? null, $repo->find($r2)['notify_reporter'] ?? null, $repo->find($r2)['message'] ?? null], 'insert: Standardstatus open, Rückmeldung aus');
    $r3 = $repo->insert(['target_type' => 'post', 'target_id' => $w['p2'], 'forum_id' => $w['f1'], 'reporter_id' => null, 'reason' => 'other', 'message' => 'Ohne Konto', 'created_at' => rt(30)]);
    T::same(null, b2Field($repo->find($r3), 'reporter_id'), 'insert: Melder NULL (gelöschtes Konto)');
    T::same(null, $repo->find(99999), 'find: unbekannt → null');
    $count = $db->count('community_reports');
    $base = ['target_type' => 'post', 'target_id' => $w['p3'], 'reason' => 'spam', 'created_at' => rt()];
    foreach ([
        'unbekannte Zielart' => ['target_type' => 'report'],
        'unbekannter Grund' => ['reason' => 'langweilig'],
        'unbekannter Status' => ['status' => 'done'],
        'Ziel 0' => ['target_id' => 0],
        'Forum 0' => ['forum_id' => 0],
        'Melder als Text' => ['reporter_id' => '5'],
        'fremde Spalte handled_by' => ['handled_by' => 3],
        'Zeit ohne UTC-Form' => ['created_at' => 'gestern'],
        'Text über 65535 Byte' => ['message' => str_repeat('ä', 32768)],
        'Text mit ungültigem UTF-8' => ['message' => "\xff"],
        'Rückmeldung 2' => ['notify_reporter' => 2],
    ] as $label => $change) {
        b2Throws(static fn () => $repo->insert(array_merge($base, $change)), 'insert lehnt ab: ' . $label);
    }
    T::same($count, $db->count('community_reports'), 'abgelehnte Meldungen schreiben nichts');

    // openOfReporter, recentByReporter, appendMessage
    T::same(['id' => $r1, 'message' => null], $repo->openOfReporter($w['u1'], 'post', $w['p2']), 'openOfReporter: offene Meldung desselben Melders zum Ziel');
    T::same(null, $repo->openOfReporter($w['u1'], 'post', $w['p3']), 'openOfReporter: anderes Ziel → null');
    T::same(null, $repo->openOfReporter($w['u1'], 'topic', $w['p2']), 'openOfReporter: andere Zielart → null');
    $closedOwn = $db->report(['target' => $w['p3'], 'reporter' => $w['u1'], 'status' => 'valid', 'created_at' => rt(5)]);
    T::same(null, $repo->openOfReporter($w['u1'], 'post', $w['p3']), 'openOfReporter: erledigte Meldung zählt nicht');
    T::same(['count' => 2, 'oldest' => rt(5)], $repo->recentByReporter($w['u1'], rt(5)), 'recentByReporter: alle Meldungen im Fenster (auch erledigte), älteste als UTC-Text; Grenze einschließlich');
    T::same(['count' => 1, 'oldest' => rt(10)], $repo->recentByReporter($w['u1'], rt(6)), 'recentByReporter: eine Sekunde später fällt die älteste heraus');
    T::same(['count' => 0, 'oldest' => null], $repo->recentByReporter($w['u3'], rt(0)), 'recentByReporter: ohne Meldung');
    T::same(true, $repo->appendMessage($r1, 'Nachtrag'), 'appendMessage: offene Meldung');
    T::same('Nachtrag', $repo->find($r1)['message'] ?? null, 'appendMessage: Text gesetzt');
    T::same(false, $repo->appendMessage($closedOwn, 'zu spät'), 'appendMessage: erledigte Meldung → false');
    T::same(null, b2Field($repo->find($closedOwn), 'message'), 'appendMessage ändert erledigte Meldungen nicht');
    b2Throws(static fn () => $repo->appendMessage($r1, "\xff"), 'appendMessage: ungültiges UTF-8 → InvalidArgumentException');

    // openReporters
    $db->report(['target' => $w['p2'], 'reporter' => $w['u1'], 'status' => 'invalid', 'created_at' => rt(40)]);
    $r5 = $repo->insert(['target_type' => 'post', 'target_id' => $w['p2'], 'forum_id' => $w['f1'], 'reporter_id' => $w['u3'], 'reason' => 'spam', 'created_at' => rt(50)]);
    T::same([$w['u1'], $w['u2'], $w['u3']], $repo->openReporters('post', $w['p2'], 10), 'openReporters: verschiedene Melder offener Meldungen, aufsteigend, ohne NULL');
    T::same([$w['u1']], $repo->openReporters('post', $w['p2'], 1), 'openReporters: Grenze');
    T::same([$w['u1']], $repo->openReporters('post', $w['p2'], 0), 'openReporters: Grenze mindestens 1');
    T::same([], $repo->openReporters('topic', $w['topic'], 10), 'openReporters: ohne Meldung []');

    // openIdsForTarget (Autocommit, Index idx_community_reports_target) und lockOpenByIds (PRIMARY)
    $db->log = [];
    T::same([$r1, $r2, $r3, $r5], $repo->openIdsForTarget('post', $w['p2'], null, 10), 'openIdsForTarget: offene Meldungen des Ziels, aufsteigend');
    T::check(stripos($db->log[0] ?? '', 'FOR UPDATE') === false, 'openIdsForTarget sperrt nicht (Autocommit vor BEGIN)', $db->log[0] ?? '');
    T::same([$r1, $r2, $r3], $repo->openIdsForTarget('post', $w['p2'], $r3, 10), 'openIdsForTarget: upTo einschließlich');
    T::same([$r1], $repo->openIdsForTarget('post', $w['p2'], $r1, 10), 'openIdsForTarget: upTo = erste Meldung');
    T::same([$r1, $r2], $repo->openIdsForTarget('post', $w['p2'], null, 2), 'openIdsForTarget: Grenze');
    T::same([$r1], $repo->openIdsForTarget('post', $w['p2'], null, -5), 'openIdsForTarget: Grenze mindestens 1');
    T::same([], $repo->openIdsForTarget('post', $w['p3'], null, 10), 'openIdsForTarget: nur offene (die erledigte Meldung fehlt)');

    $db->log = [];
    $locked = $repo->lockOpenByIds([$r5, $r1, $closedOwn, $r3, $r1, 0, 99999]);
    T::same([['id' => $r1, 'reporter_id' => $w['u1'], 'forum_id' => $w['f1']], ['id' => $r3, 'reporter_id' => null, 'forum_id' => $w['f1']], ['id' => $r5, 'reporter_id' => $w['u3'], 'forum_id' => $w['f1']]], $locked, 'lockOpenByIds: nur offene Meldungen der IDs, aufsteigend, Form');
    T::same(1, count($db->log), 'lockOpenByIds: eine Anweisung');
    T::check(str_contains($db->log[0] ?? '', ' WHERE id IN (') && str_ends_with($db->log[0], ' ORDER BY id ASC FOR UPDATE') && !str_contains($db->log[0], 'target_id'), 'lockOpenByIds: Sperre über PRIMARY (IDs), nicht über das Ziel', $db->log[0] ?? '');
    T::same([$r1, $r3, $closedOwn, $r5, 99999], $db->paramsAt(0), 'lockOpenByIds: IDs eindeutig und aufsteigend gebunden');
    $db->log = [];
    T::same([], $repo->lockOpenByIds([]), 'lockOpenByIds ohne IDs: []');
    T::same(0, count($db->log), 'lockOpenByIds ohne IDs: keine Anweisung');

    // close
    $db->log = [];
    T::same(2, $repo->close([$r2, $r1, $closedOwn], 'valid', 'Erledigt', $w['u3'], rt(60)), 'close: Zahl geschlossener offener Meldungen');
    $row = $repo->find($r1);
    T::same(['valid', 'Erledigt', $w['u3'], rt(60)], [$row['status'] ?? null, $row['resolution_note'] ?? null, $row['handled_by'] ?? null, $row['handled_at'] ?? null], 'close: Status, Notiz, Bearbeiter, Zeit');
    T::same(['valid', null, null], [$repo->find($closedOwn)['status'] ?? null, b2Field($repo->find($closedOwn), 'handled_by'), b2Field($repo->find($closedOwn), 'handled_at')], 'close ändert erledigte Meldungen nicht');
    T::same(0, $repo->close([$r1], 'invalid', null, $w['u3'], rt(61)), 'close zweimal: 0');
    T::same('valid', $repo->find($r1)['status'] ?? null, 'zweites close ändert den Status nicht');
    T::same(1, $repo->close([$r3], 'resolved', '', $w['u3'], rt(62)), 'close mit leerer Notiz');
    T::same(null, b2Field($repo->find($r3), 'resolution_note'), 'close: leere Notiz wird NULL');
    b2Throws(static fn () => $repo->close([$r5], 'open', null, $w['u3'], rt()), 'close mit Status open → InvalidArgumentException');
    b2Throws(static fn () => $repo->close([$r5], 'bogus', null, $w['u3'], rt()), 'close mit unbekanntem Status → InvalidArgumentException');
    b2Throws(static fn () => $repo->close([$r5], 'valid', null, 0, rt()), 'close ohne Bearbeiter → InvalidArgumentException');
    T::same('open', $repo->find($r5)['status'] ?? null, 'abgelehntes close schreibt nichts');
    $db->log = [];
    T::same(0, $repo->close([], 'valid', null, $w['u3'], rt()), 'close ohne IDs: 0');
    T::same(0, count($db->log), 'close ohne IDs: keine Anweisung');

    // hasOpen
    T::same(true, $repo->hasOpen('post', $w['p2']), 'hasOpen: offene Meldung vorhanden');
    $repo->close([$r5], 'invalid', null, $w['u3'], rt(63));
    T::same(false, $repo->hasOpen('post', $w['p2']), 'hasOpen: nur erledigte → false');

    // Bündel, Gründe, Einzelliste, Zähler
    $t1 = $repo->insert(['target_type' => 'topic', 'target_id' => $w['topic'], 'forum_id' => $w['f1'], 'reporter_id' => $w['u1'], 'reason' => 'offtopic', 'created_at' => rt(100)]);
    $q1 = $repo->insert(['target_type' => 'post', 'target_id' => $w['q1'], 'forum_id' => $w['f2'], 'reporter_id' => $w['u1'], 'reason' => 'spam', 'created_at' => rt(110)]);
    $m1 = $repo->insert(['target_type' => 'user', 'target_id' => $w['author'], 'forum_id' => null, 'reporter_id' => $w['u2'], 'reason' => 'abuse', 'created_at' => rt(120)]);
    $t2 = $repo->insert(['target_type' => 'topic', 'target_id' => $w['topic'], 'forum_id' => $w['f1'], 'reporter_id' => $w['u2'], 'reason' => 'spam', 'created_at' => rt(130)]);
    $q2 = $repo->insert(['target_type' => 'post', 'target_id' => $w['q1'], 'forum_id' => $w['f2'], 'reporter_id' => null, 'reason' => 'spam', 'created_at' => rt(140)]);
    $t3 = $repo->insert(['target_type' => 'topic', 'target_id' => $w['topic'], 'forum_id' => $w['f1'], 'reporter_id' => $w['u2'], 'reason' => 'spam', 'created_at' => rt(150)]);

    $bundles = $repo->openBundles(null, null, 0, 10);
    T::same([[ 'topic', $w['topic']], ['post', $w['q1']], ['user', $w['author']]], array_map(static fn (array $b): array => [$b['target_type'], $b['target_id']], $bundles), 'openBundles global: alle offenen Bündel nach ältester Meldung, auch Ziele ohne Forum');
    T::same(['target_type' => 'topic', 'target_id' => $w['topic'], 'forum_id' => $w['f1'], 'first_id' => $t1, 'last_id' => $t3, 'reports' => 3, 'reporters' => 2, 'first_at' => rt(100), 'last_at' => rt(150)], $bundles[0] ?? null, 'openBundles: Form mit Zählungen (Melder verschieden)');
    T::same([1, null], [$bundles[1]['reporters'] ?? null, b2Field($bundles[2] ?? null, 'forum_id')], 'openBundles: Melder NULL zählt nicht; Ziel user ohne Forum');
    T::same([['post', $w['q1']], ['user', $w['author']]], array_map(static fn (array $b): array => [$b['target_type'], $b['target_id']], $repo->openBundles(null, null, $t1, 10)), 'openBundles: Keyset first_id > after');
    T::same([['topic', $w['topic']]], array_map(static fn (array $b): array => [$b['target_type'], $b['target_id']], $repo->openBundles(null, null, 0, 1)), 'openBundles: Grenze');
    T::same([['topic', $w['topic']]], array_map(static fn (array $b): array => [$b['target_type'], $b['target_id']], $repo->openBundles([$w['f1']], null, 0, 10)), 'openBundles mit Forenfilter: nur diese Foren, Ziele ohne Forum fehlen');
    T::same([['post', $w['q1']]], array_map(static fn (array $b): array => [$b['target_type'], $b['target_id']], $repo->openBundles([$w['f2'], $w['f1']], 'post', 0, 10)), 'openBundles: Filter Zielart');
    $db->log = [];
    T::same([], $repo->openBundles([], null, 0, 10), 'openBundles mit leerer Forenliste: []');
    T::same(0, count($db->log), 'openBundles mit leerer Forenliste: keine Abfrage');

    T::same(['topic:' . $w['topic'] => ['spam' => 2, 'offtopic' => 1], 'post:' . $w['q1'] => ['spam' => 2], 'user:' . $w['author'] => ['abuse' => 1]], $repo->reasonCounts([['topic', $w['topic']], ['post', $w['q1']], ['user', $w['author']], ['post', $w['p2']], ['topic', $w['topic']]]), 'reasonCounts: Gründe offener Meldungen je Ziel in der Reihenfolge der Ziele, Gründe in der Reihenfolge von REASONS; Ziel ohne offene Meldung fehlt');
    T::same([], $repo->reasonCounts([]), 'reasonCounts ohne Ziele: []');

    $items = $repo->openForTarget('topic', $w['topic'], 10);
    T::same([$t1, $t2, $t3], rIds($items), 'openForTarget: offene Meldungen des Ziels, aufsteigend');
    T::same(['id' => $t1, 'reporter_id' => $w['u1'], 'reason' => 'offtopic', 'message' => null, 'notify_reporter' => true, 'forum_id' => $w['f1'], 'created_at' => rt(100)], $items[0] ?? null, 'openForTarget: Form (notify_reporter als bool)');
    T::same([$t1, $t2], rIds($repo->openForTarget('topic', $w['topic'], 2)), 'openForTarget: Grenze');

    T::same(6, $repo->countOpen(null, 100), 'countOpen global: Zeilen, nicht Bündel');
    T::same(4, $repo->countOpen(null, 4), 'countOpen: Deckel');
    T::same(3, $repo->countOpen([$w['f1']], 100), 'countOpen mit Forenfilter');
    $db->log = [];
    T::same(0, $repo->countOpen([], 100), 'countOpen mit leerer Forenliste: 0');
    T::same(0, count($db->log), 'countOpen mit leerer Forenliste: keine Abfrage');

    foreach ([
        'openOfReporter' => static fn () => $repo->openOfReporter($w['u1'], 'forum', 1),
        'openReporters' => static fn () => $repo->openReporters('forum', 1, 10),
        'openIdsForTarget' => static fn () => $repo->openIdsForTarget('forum', 1, null, 10),
        'hasOpen' => static fn () => $repo->hasOpen('forum', 1),
        'openBundles' => static fn () => $repo->openBundles(null, 'forum', 0, 10),
        'reasonCounts' => static fn () => $repo->reasonCounts([['forum', 1]]),
        'openForTarget' => static fn () => $repo->openForTarget('forum', 1, 10),
    ] as $method => $call) {
        b2Throws($call, $method . ': unbekannte Zielart → InvalidArgumentException');
    }
}

function b2ScenarioReportsReassign(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new ReportRepository($db);
    $w = b2ReportWorld($db);
    $open = static fn (string $type, int $target, ?int $forum, string $status = 'open'): int => $db->report(['target_type' => $type, 'target' => $target, 'forum' => $forum, 'reporter' => $w['u1'], 'status' => $status]);
    $a = $open('post', $w['p2'], $w['f1']);
    $closed = $open('post', $w['p2'], $w['f1'], 'valid');
    $b = $open('post', $w['p3'], $w['f1']);
    $c = $open('topic', $w['topic'], $w['f1']);
    $closedTopic = $open('topic', $w['topic'], $w['f1'], 'resolved');
    $foreignPost = $open('post', $w['q1'], $w['f2']);
    $foreignTopic = $open('topic', $w['topic2'], $w['f2']);
    $member = $open('user', $w['author'], null);
    $nullForum = $open('post', $w['first'], null);
    $forumOf = static fn (int $id): ?int => ($row = $db->row('community_reports', ['id' => $id])) === null || $row['forum_id'] === null ? null : (int) $row['forum_id'];

    $db->log = [];
    T::same(4, $repo->reassignTopic($w['topic'], $w['f2']), 'reassignTopic: offene Meldungen zu Beiträgen des Themas und zum Thema selbst (auch mit forum_id NULL)');
    $verbs = b2Verbs($db);
    $log = $db->log;
    T::same([$w['f2'], $w['f2'], $w['f2'], $w['f2']], [$forumOf($a), $forumOf($b), $forumOf($c), $forumOf($nullForum)], 'reassignTopic: neues Forum');
    T::same([$w['f1'], $w['f1'], $w['f2'], $w['f2'], null], [$forumOf($closed), $forumOf($closedTopic), $forumOf($foreignPost), $forumOf($foreignTopic), $forumOf($member)], 'reassignTopic: erledigte Meldungen und fremde Ziele bleiben');
    T::same(['SELECT', 'UPDATE', 'UPDATE'], $verbs, 'reassignTopic: Beitragsmeldungen lesen, über IDs umhängen, Themenmeldungen umhängen');
    T::check(str_contains($log[0] ?? '', ' JOIN ') && str_contains($log[0], 'p.topic_id = ?'), 'reassignTopic: Beitragsmeldungen über den Join auf das Thema (idx_community_posts_topic)', $log[0] ?? '');
    T::check(str_contains($log[1] ?? '', ' WHERE id IN (') && str_contains($log[1], "status = 'open'"), 'reassignTopic: Umhängen über PRIMARY, nur offene', $log[1] ?? '');
    T::same(0, $repo->reassignTopic($w['topic'], $w['f2']), 'reassignTopic zweimal: 0 (nichts umgehängt)');
    $empty = $db->topic(['forum' => $w['f1'], 'user' => $w['author']]);
    $db->log = [];
    T::same(0, $repo->reassignTopic($empty, $w['f2']), 'reassignTopic ohne Meldungen: 0');
    T::same(['SELECT', 'UPDATE'], b2Verbs($db), 'reassignTopic ohne Beitragsmeldungen: keine Anweisung über IDs');

    T::same(2, $repo->reassignPosts([$w['p3'], $w['q1'], $w['p3'], 0], $w['f1']), 'reassignPosts: offene Meldungen der Beiträge');
    T::same([$w['f1'], $w['f1'], $w['f2'], $w['f1']], [$forumOf($b), $forumOf($foreignPost), $forumOf($a), $forumOf($closed)], 'reassignPosts: nur die genannten Beiträge und nur offene Meldungen');
    T::same(0, $repo->reassignPosts([$w['p3']], $w['f1']), 'reassignPosts zweimal: 0');
    T::same(null, $forumOf($member), 'reassignPosts ändert keine Mitgliedsmeldungen');
    $db->log = [];
    T::same(0, $repo->reassignPosts([], $w['f1']), 'reassignPosts ohne IDs: 0');
    T::same(0, count($db->log), 'reassignPosts ohne IDs: keine Anweisung');
    b2Throws(static fn () => $repo->reassignPosts([$w['p3']], 0), 'reassignPosts ohne Forum → InvalidArgumentException');
    b2Throws(static fn () => $repo->reassignTopic($w['topic'], 0), 'reassignTopic ohne Forum → InvalidArgumentException');

    // Stücke zu BATCH
    $ids = [];
    for ($i = 0; $i < ReportRepository::BATCH + 1; $i++) {
        $ids[] = $open('post', $w['p2'], $w['f2']);
    }
    $db->log = [];
    T::same(ReportRepository::BATCH + 4, $repo->reassignTopic($w['topic'], $w['f1']), 'reassignTopic: 501 neue Beitragsmeldungen, zwei bisherige und die Themenmeldung (eine liegt schon im Ziel)');
    T::same(['SELECT', 'UPDATE', 'UPDATE', 'UPDATE'], b2Verbs($db), 'reassignTopic: Umhängen in Stücken zu BATCH IDs');
    $db->log = [];
    T::same(ReportRepository::BATCH + 1, $repo->close($ids, 'valid', null, $w['u2'], rt()), 'close über BATCH IDs');
    T::same(2, count($db->log), 'close: Stücke zu BATCH IDs');
    $db->log = [];
    $repo->lockOpenByIds([...$ids, $a]);
    T::same(2, count($db->log), 'lockOpenByIds: Stücke zu BATCH IDs');
    T::same([$a], array_slice($db->paramsAt(0), 0, 1), 'lockOpenByIds: das erste Stück beginnt mit der kleinsten ID');
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Läufe
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

$b2Backends = ['SQLite-Double' => static fn (): Database => CommunityTestDatabase::create()];
if (MariaDbTestDatabase::configured()) {
    $b2Backends['MariaDB'] = static fn (): Database => MariaDbTestDatabase::create();
}
$b2Scenarios = [
    'Benutzerstatistik (PR #2b): ensureMany, lockMany in Stücken, bump mit Lösungen, bumpLocked, statsMany' => 'b2ScenarioStatsSets',
    'Benutzerstatistik (PR #2b): settingsMany, settingsForUpdate, saveSettings, touchSeen, bumpReactions, setRank' => 'b2ScenarioStatsSettings',
    'Benutzerstatistik (PR #2b): Ränge und Marke der Grundausstattung' => 'b2ScenarioRanks',
    'Mitglieder (PR #2b): accounts, metaMany' => 'b2ScenarioMembersBulk',
    'Lesestatus (PR #2b): forUsers, latestMarksFor' => 'b2ScenarioReadStateBulk',
    'Sperren (PR #2b): insert, find, activeList, lift, forUser, listActive' => 'b2ScenarioBans',
    'Meldungen (PR #2b): alle Methoden, Sperre über IDs, Bündel' => 'b2ScenarioReports',
    'Meldungen (PR #2b): Umhängen beim Verschieben, Stücke zu BATCH' => 'b2ScenarioReportsReassign',
];
foreach ($b2Backends as $label => $make) {
    foreach ($b2Scenarios as $title => $function) {
        T::run($title . ' [' . $label . ']', static function () use ($make, $function): void {
            $db = $make();
            try {
                $function($db);
            } finally {
                if ($db instanceof MariaDbTestDatabase) {
                    $db->dispose();
                }
            }
        });
    }
}

T::run('Mitglieder-Repositories (PR #2b): Spaltenlisten, Konstanten, Pflichtschreibweisen', static function (): void {
    $db = CommunityTestDatabase::create();
    $schema = static fn (string $table): array => array_map(static fn (object $c): string => (string) $c->name, $db->get_results('PRAGMA table_info(cms_' . $table . ')'));
    $constant = static fn (string $class, string $name): mixed => (new \ReflectionClassConstant($class, $name))->getValue();

    T::same(implode(', ', $schema('community_reports')), $constant(ReportRepository::class, 'COLUMNS'), 'ReportRepository::COLUMNS = Spalten von community_reports in Schemareihenfolge');
    T::same(['post', 'topic', 'user'], ReportRepository::TARGET_TYPES, 'ReportRepository::TARGET_TYPES');
    T::same(['spam', 'abuse', 'offtopic', 'illegal', 'other'], ReportRepository::REASONS, 'ReportRepository::REASONS (sanktionen §1.2)');
    T::same(['open', 'valid', 'invalid', 'resolved'], ReportRepository::STATUSES, 'ReportRepository::STATUSES (sanktionen §1.2)');
    T::same(500, ReportRepository::BATCH, 'ReportRepository::BATCH = 500');

    $banColumns = array_values(array_diff($schema('community_bans'), ['internal_note']));
    T::same(implode(', ', $banColumns), $constant(BanRepository::class, 'COLUMNS'), 'BanRepository::COLUMNS = Spalten von community_bans ohne internal_note');
    T::same(['write', 'full'], BanRepository::SCOPES, 'BanRepository::SCOPES');
    T::same(['user_id', 'scope', 'reason', 'internal_note', 'issued_by', 'starts_at', 'ends_at', 'created_at'], $constant(BanRepository::class, 'INSERTABLE'), 'BanRepository::INSERTABLE (ohne Aufhebung)');
    $bans = rSource('core/Services/Community/Repository/BanRepository.php');
    T::same(1, substr_count($bans, "'lifted_at IS NULL AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?)'"), 'BanRepository: ACTIVE ist die einzige Stelle der Wirksamkeitsregel');

    T::same(100, $constant(UserStatsRepository::class, 'CHUNK'), 'UserStatsRepository: Stücke zu 100');

    $catalog = require dirname(__DIR__) . '/community-mutants/catalog-2b-repo-members.php';
    foreach ($catalog as $name => $row) {
        T::same(1, substr_count(rSource($row[0]), $row[1]), 'Pflichtschreibweise der Mutante ' . $name . ' steht genau einmal in ' . basename($row[0]));
    }
    // Suchtexte aus catalog.php, die auf die Dateien der Einheit B2 zielen, bleiben eindeutig (sanktionen §5: die #2a-Texte wirken auf alle Lesemethoden)
    $own = ['UserStatsRepository.php', 'MemberRepository.php', 'ReadStateRepository.php', 'BanRepository.php', 'ReportRepository.php'];
    $legacy = require dirname(__DIR__) . '/community-mutants/catalog.php';
    foreach ($legacy as $name => $row) {
        if (in_array(basename((string) $row[0]), $own, true)) {
            T::same(1, substr_count(rSource($row[0]), $row[1]), 'catalog.php: Suchtext von ' . $name . ' steht genau einmal in ' . basename($row[0]));
        }
    }
});

if (MariaDbTestDatabase::configured()) {
    T::run('MariaDB (PR #2b): echte Zeilensperren der Mitglieder-Repositories, keine Lückensperre beim Erledigen, EXPLAIN', static function (): void {
        $db = MariaDbTestDatabase::create();
        try {
            $stats = new UserStatsRepository($db);
            $reports = new ReportRepository($db);
            $other = $db->connection();
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $blocked = static function (string $sql, array $params) use ($other): bool {
                try {
                    $other->prepare($sql)->execute($params);

                    return false;
                } catch (\PDOException $e) {
                    return in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true);
                }
            };

            $stats->ensureMany(range(1, 400), rt());
            $pdo = $db->getPdo();
            $pdo->beginTransaction();
            $stats->lockMany([3, 1], rt());
            T::check($blocked('SELECT user_id FROM cms_community_user_stats WHERE user_id = ? FOR UPDATE', [3]), 'lockMany hält die Zeilensperre bis zum Ende der Transaktion (zweite Sitzung wartet)');
            T::check(!$blocked('SELECT user_id FROM cms_community_user_stats WHERE user_id = ? FOR UPDATE', [2]), 'lockMany sperrt keine Zeile außerhalb der Liste (2 liegt zwischen 1 und 3)');
            $pdo->commit();
            $other->exec('COMMIT');

            $pdo->beginTransaction();
            $stats->settingsForUpdate(4);
            T::check($blocked('UPDATE cms_community_user_stats SET settings_json = ? WHERE user_id = ?', ['{}', 4]), 'settingsForUpdate sperrt die Zeile des Empfängers');
            $pdo->commit();

            $forum = $db->forum();
            $user = $db->user();
            $topic = $db->topic(['forum' => $forum, 'user' => $user]);
            $post = $db->post(['topic' => $topic, 'user' => $user]);
            $r1 = $db->report(['target' => $post, 'forum' => $forum, 'reporter' => $user, 'created_at' => rt(1)]);
            $r2 = $db->report(['target' => $post, 'forum' => $forum, 'reporter' => $user, 'created_at' => rt(2)]);
            for ($i = 0; $i < 300; $i++) {
                $db->report(['target_type' => 'user', 'target' => $user, 'reporter' => $user, 'created_at' => rt(3)]);
            }
            $insert = 'INSERT INTO cms_community_reports (target_type, target_id, forum_id, reporter_id, reason, status, notify_reporter, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
            $insertParams = ['post', $post, $forum, $user, 'spam', 'open', 1, rt(4)];

            $ids = $reports->openIdsForTarget('post', $post, null, 10);
            $pdo->beginTransaction();
            T::same([$r1, $r2], array_map(static fn (array $row): int => $row['id'], $reports->lockOpenByIds($ids)), 'lockOpenByIds sperrt die offenen Meldungen');
            T::check($blocked('UPDATE cms_community_reports SET status = ? WHERE id = ?', ['valid', $r1]), 'lockOpenByIds: gesperrte Meldung wartet');
            T::check(!$blocked($insert, $insertParams), 'lockOpenByIds: eine neue Meldung zum selben Ziel wartet nicht (keine Lückensperre auf idx_community_reports_target)');
            $pdo->commit();
            $other->exec('COMMIT');

            // Gegenprobe: eine Sperre über das Ziel (früher lockOpenForTarget) blockiert das Einfügen
            $pdo->beginTransaction();
            $db->execute("SELECT id FROM cms_community_reports WHERE target_type = ? AND target_id = ? AND status = 'open' ORDER BY id ASC LIMIT 10 FOR UPDATE", ['post', $post]);
            T::check($blocked($insert, $insertParams), 'Gegenprobe: Sperre über idx_community_reports_target blockiert neue Meldungen zum Ziel (Lückensperre)');
            $pdo->commit();
            $other->exec('COMMIT');

            $explain = static function (string $sql, array $params) use ($db): array {
                $rows = $db->execute('EXPLAIN ' . $sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

                return array_map(static fn (array $row): string => (string) ($row['key'] ?? ''), $rows);
            };
            $sqlOf = static function (\Closure $call) use ($db): array {
                $db->log = [];
                $db->captureParams = true;
                $db->captured = [];
                $call();
                $db->captureParams = false;

                return $db->captured[0] ?? ['sql' => '', 'params' => []];
            };
            foreach ([
                'lockOpenByIds' => [static fn () => $reports->lockOpenByIds([$r1, $r2]), 'PRIMARY'],
                'openIdsForTarget' => [static fn () => $reports->openIdsForTarget('post', $post, $r2, 10), 'idx_community_reports_target'],
                'lockMany' => [static fn () => $stats->lockMany([1, 3], rt()), 'PRIMARY'],
                'settingsForUpdate' => [static fn () => $stats->settingsForUpdate(4), 'PRIMARY'],
                'statsMany' => [static fn () => $stats->statsMany([1, 2, 3]), 'PRIMARY'],
            ] as $label => [$call, $key]) {
                $captured = $sqlOf($call);
                $sql = (string) preg_replace('/ FOR UPDATE$/', '', (string) $captured['sql']);
                T::same([$key], $explain($sql, $captured['params']), 'EXPLAIN ' . $label . ': ' . $key);
            }
        } finally {
            $db->dispose();
        }
    });
}
