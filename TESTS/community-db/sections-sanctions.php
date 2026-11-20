<?php
/**
 * Abschnitte der Einheit K (PR #2b, W2) in `TESTS/community-db`: Meldungen und Sperren gegen MariaDB (Vertrag DESIGN-2b §3.10, §4.4,
 * §7.3; Entwurf sanktionen §6.4). Worker-Aktionen in `actions-sanctions.php`.
 *
 *  race-report-limit    Melder mit 9 Meldungen, 8 Prozesse melden 8 Beiträge in 8 Themen gleichzeitig: genau 1 Erfolg, 7 rate_limited,
 *                       10 Zeilen (5 Runden). Die Grenze zählt unter der Statistiksperre des Melders.
 *  race-report-merge    8 Prozesse, derselbe Melder, dasselbe Ziel: 1 Zeile, 7 zusammengeführt, 1 Hook.
 *  race-ban             8 Moderatoren sperren dasselbe Mitglied (write, 7 d): 1 Zeile, 7 already_banned; derselbe Moderator 8×: 1 Zeile,
 *                       7 duplicate; write und full gleichzeitig: 2 Zeilen, wirksam ist full; kein Deadlock nach außen.
 *  race-ban-reply       Sperre hält die Statistikzeile: die Antwort des Mitglieds wartet und endet mit banned_write; umgekehrt hält die
 *                       Antwort, die Sperre wartet, die Antwort ist veröffentlicht und die Sperre danach angelegt.
 *  race-lift-ban        Aufheben hält; eine Sperre, die die aufzuhebende deckt, wartet und wird danach angelegt (nicht already_banned).
 *  race-resolve         4 Moderatoren erledigen dasselbe Bündel mit verschiedenen Status: genau 1 Erfolg, 3 report_closed, Hooks = Meldungen.
 *  race-resolve-report  Erledigen hält die Meldungen (Sperre über IDs): eine neue Meldung zum Ziel wartet nicht und bleibt offen.
 *  inject-sanctions     Fehler an jeder Anweisung von create, resolve, ban, lift: db_error bzw. Ablehnung, nichts geschrieben, kein Hook,
 *                       keine offene Transaktion, keine hängende Sperre; danach gelingt dieselbe Aktion.
 *  utc-sanctions        Sitzung +01:00, PHP Berlin (Sommer +02:00): created_at, starts_at, ends_at, handled_at, lifted_at = UTC der Uhr;
 *                       eine Sperre bis jetzt + 1 h wirkt bis genau dahin.
 *  explain-sanctions    20.000 Meldungen (2.000 offen), 5.000 Sperren: jede Leseanweisung der Dienste nutzt ihren Index, kein Tabellenscan.
 *
 * `race-report-threshold` und `race-report-move` brauchen den echten `ModerationService` und folgen in W4. Geladen von `run.php` am
 * Ende; nutzt `cdbSection()`, `cdbSpawn()`, `cdbFinish()`, `cdbRace()`, `cdbProcessProblems()`, `cdbProbeLock()`, `cdbMeasure()`,
 * `cdbPlanProblems()`, `cdbScalar()`, `cdbOpenForum()`, `cdbDeadlocks()`, `CdbWorld` und die Konstanten `CDB_*`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Services\Community\Repository\BanRepository;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\ReportRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\BanService;
use CMS\Services\Community\Service\ReportService;

/** Einstellungen der Sanktionswettläufe: ohne Freigabepflicht, Abstände und Zählgrenzen (nur die Regeln der Dienste wirken). */
const CDB_SN_SETTINGS = CDB_NOLIMIT_SETTINGS;

/** UTC-Text der echten Zeit plus Sekunden (Worker lesen die echte Uhr). */
function cdbSnReal(int $offset = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offset);
}

/**
 * Lädt die Klassen der Einheit im Hauptprozess. Die Wettläufe rufen die Dienste nur in den Workern auf; die Gegenprobe
 * (`TESTS/community-mutants`) verlangt, dass eine Mutante auch im Hauptprozess der Suite angewendet wird.
 */
function cdbSnClasses(): void
{
    foreach ([ReportService::class, BanService::class, ReportRepository::class] as $class) {
        if (!class_exists($class)) {
            throw new \LogicException('Klasse fehlt: ' . $class);
        }
    }
}

/**
 * Thema mit Beitrag eines Autors im Forum; liefert die Beitrags-ID.
 */
function cdbSnPost(MariaDbTestDatabase $db, int $forum, int $author): int
{
    $topic = $db->topic(['forum' => $forum, 'user' => $author]);
    $db->post(['topic' => $topic, 'user' => $author, 'first' => true]);

    return $db->post(['topic' => $topic, 'user' => $author]);
}

/**
 * Ein Auftrag hält seine Sperren (in can(fresh) bzw. nach der Sperre der Meldungen), ein zweiter startet; nach einer Sekunde wird
 * geprüft, ob er noch läuft (wartet), dann gibt der erste frei.
 *
 * @param array<string, mixed> $holder
 * @param array<string, mixed> $waiter
 * @return array{locked: bool, waiting: bool, holder: array<string, mixed>, waiter: array<string, mixed>}
 */
function cdbSnHoldThen(MariaDbTestDatabase $db, array $holder, array $waiter, string $holdKey = 'hold'): array
{
    $dir = sys_get_temp_dir() . '/cms-hold-sn-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);
    $hold = ['locked' => $dir . '/locked', 'release' => $dir . '/release'];
    $first = cdbSpawn($db, [$holder + [$holdKey => $hold, 'settings' => CDB_SN_SETTINGS]]);
    touch($first['dir'] . '/start');
    $deadline = microtime(true) + 20;
    while (!is_file($hold['locked']) && microtime(true) < $deadline) {
        usleep(2000);
    }
    $locked = is_file($hold['locked']);
    $second = cdbSpawn($db, [$waiter + ['settings' => CDB_SN_SETTINGS]]);
    touch($second['dir'] . '/start');
    usleep(1000000);
    $waiting = (bool) (proc_get_status($second['procs'][0])['running'] ?? false);
    touch($hold['release']);
    $holderResult = cdbFinish($first, false)[0];
    $waiterResult = cdbFinish($second, false)[0];
    @unlink($hold['locked']);
    @unlink($hold['release']);
    @rmdir($dir);

    return ['locked' => $locked, 'waiting' => $waiting, 'holder' => $holderResult, 'waiter' => $waiterResult];
}

/**
 * Dienste einer Testwelt mit eigener Uhr, Hook-Protokoll in `$w->hooks` und `autoModerate` ohne Wirkung.
 *
 * @return array{0: ReportService, 1: BanService}
 */
function cdbSnServices(CdbWorld $w): array
{
    $clock = fn (): int => (int) $w->time['now'];
    $can = static fn (?int $userId, string $action, array $context): AccessDecision => $w->access->can($userId, $action, $context);
    $dispatch = static function (string $hook, mixed ...$args) use ($w): void {
        $w->hooks[] = ['hook' => $hook, 'args' => $args, 'in_transaction' => $w->db->getPdo()->inTransaction()];
    };
    $auto = static fn (string $op, string $type, int $id, array $details): array => ['success' => true, 'changed' => false];

    return [
        new ReportService($w->db, $w->settings, $clock, $can, $dispatch, $w->access, $auto),
        new BanService($w->db, $w->settings, $clock, $can, $dispatch, $w->access),
    ];
}

/** Inhalt der Sanktionstabellen und der Inhalte als Hash (ohne `community_user_stats`: ensure() legt leere Zeilen vor BEGIN an). */
function cdbSnFingerprint(\CMS\Database $db): string
{
    $parts = [];
    foreach (['community_reports', 'community_bans', 'community_moderation_log', 'community_topics', 'community_posts', 'community_forums'] as $table) {
        $parts[$table] = $db->execute("SELECT * FROM cms_{$table} ORDER BY 1")->fetchAll(\PDO::FETCH_ASSOC);
    }

    return md5((string) json_encode($parts));
}

/**
 * Fehler an jeder Anweisung einer Aktion (Nummer 1 = erste Anweisung, auch der Rechteprüfung): Schreib- und Sperranweisungen
 * ergeben `db_error`, Leseanweisungen eine Ablehnung oder `db_error` (oder, wenn unerheblich, denselben Erfolg mit denselben Hooks);
 * nie ein Hook nach einem Fehler, nie eine offene Transaktion, keine hängende Sperre, der Zustand bleibt (außer leeren
 * Statistikzeilen aus ensure()). Danach gelingt dieselbe Aktion.
 *
 * @param \Closure(CdbWorld): array{0: \Closure(CdbWorld): array<string, mixed>, 1: list<array{0: string, 1: string, 2: int}>} $scenario
 * @return array{problems: list<string>, statements: int, tolerated: list<string>}
 */
function cdbSnInject(\Closure $scenario): array
{
    $base = new CdbWorld(CDB_SN_SETTINGS);
    [$act] = $scenario($base);
    $base->hooks = [];
    $base->rebuild();
    $mark = count($base->db->log);
    $ok = $act($base);
    $statements = array_slice($base->db->log, $mark);
    $baseHooks = $base->hookNames();
    $base->db->dispose();
    if (empty($ok['success'])) {
        return ['problems' => ['ungestörter Lauf scheitert: ' . json_encode($ok)], 'statements' => count($statements), 'tolerated' => []];
    }
    $problems = [];
    $tolerated = [];
    $w = null;
    $probes = [];
    $probe = null;
    foreach ($statements as $index => $sql) {
        $number = $index + 1;
        $kind = cdbKind($sql);
        $label = '#' . $number . ' [' . $kind . '] ' . preg_replace('/\s+/', ' ', substr($sql, 0, 70));
        if ($w === null) {
            $w = new CdbWorld(CDB_SN_SETTINGS);
            [$act, $probes] = $scenario($w);
            $probe = $w->db->connection();
        }
        $w->rebuild();
        $w->hooks = [];
        $before = cdbSnFingerprint($w->db);
        $w->db->statementCount = 0;
        $w->db->failAtStatement = $number;
        $result = [];
        try {
            $result = $act($w);
        } catch (\Throwable $e) {
            $problems[] = $label . ': Ausnahme erreicht den Aufrufer: ' . $e::class . ': ' . $e->getMessage();
        }
        $w->db->failAtStatement = null;
        if ($w->db->statementCount < $number) {
            $problems[] = $label . ': die Anweisung wurde nicht erreicht (' . $w->db->statementCount . ' Anweisungen)';
        } elseif ($kind === 'read' && !empty($result['success'])) {
            $tolerated[] = $label;
            if ($w->hookNames() !== $baseHooks) {
                $problems[] = $label . ': Erfolg trotz Ausfall, aber andere Hooks: ' . json_encode($w->hookNames());
            }
        } else {
            if ($kind !== 'read' && ($result['error'] ?? null) !== 'db_error') {
                $problems[] = $label . ': erwartet db_error, ist ' . json_encode($result);
            }
            if (!empty($result['success'])) {
                $problems[] = $label . ': Erfolg trotz Ausfall';
            }
            if ($w->hooks !== []) {
                $problems[] = $label . ': Hook trotz Fehler: ' . json_encode($w->hookNames());
            }
            if (cdbSnFingerprint($w->db) !== $before) {
                $problems[] = $label . ': Zustand nach dem Fehler verändert (Rücknahme fehlt)';
            }
        }
        if ($w->db->getPdo()->inTransaction()) {
            $problems[] = $label . ': Transaktion bleibt offen';
            $w->db->getPdo()->rollBack();
        }
        foreach ($probes as [$table, $key, $id]) {
            if (cdbProbeLock($probe, $table, $key, $id) !== 'free') {
                $problems[] = $label . ': Sperre auf ' . $table . '.' . $key . ' = ' . $id . ' hängt';
            }
        }
        if (cdbSnFingerprint($w->db) !== $before) {
            $w->db->dispose();
            $w = null;
        }
    }
    if ($w === null) {
        $w = new CdbWorld(CDB_SN_SETTINGS);
        [$act] = $scenario($w);
    }
    $w->rebuild();
    $w->hooks = [];
    $again = $act($w);
    if (empty($again['success'])) {
        $problems[] = 'nach allen Fehlern scheitert dieselbe Aktion: ' . json_encode($again);
    }
    $w->db->dispose();

    return ['problems' => $problems, 'statements' => count($statements), 'tolerated' => $tolerated];
}

/**
 * Wie `cdbPlanProblems()`, mit zwei Lockerungen für kleine Ergebnismengen: `temporary` erlaubt temporäre Tabelle und Filesort
 * (DISTINCT, GROUP BY oder Sortierung über wenige Treffer eines Index), `maxScan` die Leseschritte über eine abgeleitete bzw.
 * temporäre Tabelle (Handler_read_rnd_next zählt sie mit).
 *
 * @param array<string, mixed> $measure
 * @param array<string, mixed> $rule
 * @return list<string>
 */
function cdbSnPlanProblems(array $measure, array $rule): array
{
    $problems = cdbPlanProblems($measure, $rule);
    if (!empty($rule['temporary'])) {
        $problems = array_values(array_filter($problems, static fn (string $p): bool => !str_starts_with($p, 'temporäre Tabelle') && !str_starts_with($p, 'Filesort')));
    }

    return $problems;
}

cdbSection('race-report-limit', 'race-report-limit: Meldegrenze unter der Statistiksperre des Melders (8 Prozesse am Limit, 5 Runden)', static function (): void {
    cdbSnClasses();
    $db = MariaDbTestDatabase::create();
    try {
        $forum = cdbOpenForum($db);
        $author = $db->user();
        $summary = [];
        $problems = [];
        for ($round = 1; $round <= 5; $round++) {
            $reporter = $db->user();
            $old = [];
            for ($i = 0; $i < 9; $i++) {
                $old[] = cdbSnPost($db, $forum, $author);
                $db->report(['target' => end($old), 'forum' => $forum, 'reporter' => $reporter, 'created_at' => cdbSnReal(-120)]);
            }
            $jobs = [];
            for ($i = 0; $i < 8; $i++) {
                $jobs[] = ['action' => 'report.create', 'args' => ['user' => $reporter, 'type' => 'post', 'target' => cdbSnPost($db, $forum, $author)], 'settings' => CDB_SN_SETTINGS];
            }
            $results = cdbRace($db, $jobs);
            array_push($problems, ...cdbProcessProblems($results));
            $ok = count(array_filter($results, static fn (array $r): bool => !empty($r['result']['success'])));
            $limited = count(array_filter($results, static fn (array $r): bool => ($r['result']['error'] ?? null) === 'rate_limited'));
            $rows = $db->count('community_reports', ['reporter_id' => $reporter]);
            $summary[] = $ok . '/' . $limited . '/' . $rows;
            T::same([1, 7, 10], [$ok, $limited, $rows], 'Runde ' . $round . ': genau 1 Erfolg, 7 rate_limited, 10 Zeilen des Melders');
        }
        T::same([], $problems, 'kein db_error, keine Ausnahme, keine offene Transaktion');
        T::info('race-report-limit (Erfolg/rate_limited/Zeilen je Runde): ' . implode(', ', $summary));
    } finally {
        $db->dispose();
    }
});

cdbSection('race-report-merge', 'race-report-merge: 8 gleichzeitige Meldungen desselben Melders zum selben Ziel ergeben eine Zeile', static function (): void {
    cdbSnClasses();
    $db = MariaDbTestDatabase::create();
    try {
        $forum = cdbOpenForum($db);
        $post = cdbSnPost($db, $forum, $db->user());
        $reporter = $db->user();
        $results = cdbRace($db, array_fill(0, 8, ['action' => 'report.create', 'args' => ['user' => $reporter, 'type' => 'post', 'target' => $post, 'message' => 'gleich'], 'settings' => CDB_SN_SETTINGS]));
        T::same([], cdbProcessProblems($results), 'kein db_error, keine Ausnahme');
        T::same(1, $db->count('community_reports'), 'genau eine Zeile');
        T::same([1, 7], [
            count(array_filter($results, static fn (array $r): bool => ($r['result']['merged'] ?? null) === false)),
            count(array_filter($results, static fn (array $r): bool => ($r['result']['merged'] ?? null) === true)),
        ], '1 neue Meldung, 7 zusammengeführt');
        T::same(1, array_sum(array_map(static fn (array $r): int => count($r['result']['hooks'] ?? []), $results)), 'genau ein Hook REPORT_CREATED');
        T::same(1, count(array_unique(array_map(static fn (array $r): int => (int) ($r['result']['report_id'] ?? 0), $results))), 'alle mit derselben report_id');
    } finally {
        $db->dispose();
    }
});

cdbSection('race-ban', 'race-ban: Sperren serialisiert an der Statistikzeile des Betroffenen (8 Moderatoren, Doppelklick, write und full)', static function (): void {
    cdbSnClasses();
    $db = MariaDbTestDatabase::create();
    try {
        $deadlocks = cdbDeadlocks();
        $target = $db->user();
        $mods = [];
        for ($i = 0; $i < 8; $i++) {
            $mods[] = $db->user(['role' => 'moderator']);
        }
        $results = cdbRace($db, array_map(static fn (int $mod): array => ['action' => 'ban.create', 'args' => ['actor' => $mod, 'user' => $target, 'scope' => 'write', 'duration' => 7 * 86400], 'settings' => CDB_SN_SETTINGS], $mods));
        $problems = cdbProcessProblems($results);
        T::same([1, 7, 1], [
            count(array_filter($results, static fn (array $r): bool => !empty($r['result']['success']) && empty($r['result']['duplicate']))),
            count(array_filter($results, static fn (array $r): bool => ($r['result']['error'] ?? null) === 'already_banned')),
            $db->count('community_bans', ['user_id' => $target]),
        ], '8 Moderatoren: 1 Sperre, 7 already_banned, 1 Zeile');

        $second = $db->user();
        $results = cdbRace($db, array_fill(0, 8, ['action' => 'ban.create', 'args' => ['actor' => $mods[0], 'user' => $second, 'scope' => 'full', 'duration' => 3 * 86400], 'settings' => CDB_SN_SETTINGS]));
        array_push($problems, ...cdbProcessProblems($results));
        T::same([7, 1], [
            count(array_filter($results, static fn (array $r): bool => !empty($r['result']['duplicate']))),
            $db->count('community_bans', ['user_id' => $second]),
        ], 'derselbe Moderator 8×: 1 Zeile, 7 duplicate');
        T::same(1, array_sum(array_map(static fn (array $r): int => count($r['result']['hooks'] ?? []), $results)), 'Doppelklick: genau ein Hook USER_BANNED');

        $third = $db->user();
        $results = cdbRace($db, [
            ['action' => 'ban.create', 'args' => ['actor' => $mods[1], 'user' => $third, 'scope' => 'write', 'duration' => 86400], 'settings' => CDB_SN_SETTINGS],
            ['action' => 'ban.create', 'args' => ['actor' => $mods[2], 'user' => $third, 'scope' => 'full', 'duration' => 86400], 'settings' => CDB_SN_SETTINGS],
        ]);
        array_push($problems, ...cdbProcessProblems($results));
        $outcomes = array_map(static fn (array $r): string => !empty($r['result']['success']) ? 'ok' : (string) ($r['result']['error'] ?? '?'), $results);
        $active = (new BanRepository($db))->activeFor($third, cdbSnReal());
        T::same('full', $active['scope'] ?? null, 'write und full gleichzeitig: wirksam ist full');
        T::check(
            ($outcomes === ['ok', 'ok'] && $db->count('community_bans', ['user_id' => $third]) === 2) || ($outcomes === ['already_banned', 'ok'] && $db->count('community_bans', ['user_id' => $third]) === 1),
            'write und full: beide angelegt (write zuerst) oder full zuerst und write gedeckt',
            json_encode($outcomes)
        );
        T::same([], $problems, 'kein db_error, keine Ausnahme, keine offene Transaktion');
        T::info('race-ban: Deadlocks des Servers während des Abschnitts: ' . (cdbDeadlocks() - $deadlocks) . ' (Wiederholung in Transaction::run, nie nach außen)');
    } finally {
        $db->dispose();
    }
});

cdbSection('race-ban-reply', 'race-ban-reply: Sperre und Antwort desselben Mitglieds laufen nacheinander (Statistikzeile)', static function (): void {
    cdbSnClasses();
    $db = MariaDbTestDatabase::create();
    try {
        $forum = cdbOpenForum($db);
        $topic = $db->topic(['forum' => $forum, 'user' => $db->user()]);
        $db->post(['topic' => $topic, 'first' => true]);
        $member = $db->user();
        $mod = $db->user(['role' => 'moderator']);
        (new \CMS\Services\Community\Repository\UserStatsRepository($db))->ensure($member, cdbSnReal());

        $run = cdbSnHoldThen(
            $db,
            ['action' => 'ban.create', 'args' => ['actor' => $mod, 'user' => $member, 'scope' => 'write', 'duration' => 7 * 86400]],
            ['action' => 'post.reply', 'args' => ['user' => $member, 'topic' => $topic, 'body' => 'Antwort während der Sperre']]
        );
        T::check($run['locked'], 'Sperre hält die Statistikzeile (Rechteprüfung unter der Sperre erreicht)');
        T::check($run['waiting'], 'die Antwort des Mitglieds wartet nach 1 s noch');
        T::same(true, $run['holder']['result']['success'] ?? null, 'Sperre angelegt');
        T::same('banned_write', $run['waiter']['result']['error'] ?? null, 'Antwort danach: banned_write', json_encode($run['waiter']));
        T::same(0, $db->count('community_posts', ['user_id' => $member]), 'keine neue Antwort');

        $member2 = $db->user();
        (new \CMS\Services\Community\Repository\UserStatsRepository($db))->ensure($member2, cdbSnReal());
        $back = cdbSnHoldThen(
            $db,
            ['action' => 'post.reply', 'args' => ['user' => $member2, 'topic' => $topic, 'body' => 'Antwort vor der Sperre']],
            ['action' => 'ban.create', 'args' => ['actor' => $mod, 'user' => $member2, 'scope' => 'write', 'duration' => 7 * 86400]]
        );
        T::check($back['locked'] && $back['waiting'], 'umgekehrt: die Antwort hält, die Sperre wartet');
        T::same('published', $back['holder']['result']['status'] ?? null, 'Antwort veröffentlicht', json_encode($back['holder']));
        T::same(true, $back['waiter']['result']['success'] ?? null, 'Sperre danach angelegt', json_encode($back['waiter']));
        T::same([], cdbProcessProblems([$run['holder'], $run['waiter'], $back['holder'], $back['waiter']]), 'kein db_error, keine Ausnahme');
    } finally {
        $db->dispose();
    }
});

cdbSection('race-lift-ban', 'race-lift-ban: Aufheben hält, eine gedeckte Sperre wartet und wird danach angelegt', static function (): void {
    cdbSnClasses();
    $db = MariaDbTestDatabase::create();
    try {
        $member = $db->user();
        $mod = $db->user(['role' => 'moderator']);
        $ban = $db->ban(['user' => $member, 'scope' => 'write', 'issued_by' => $mod, 'starts_at' => cdbSnReal(-3600), 'ends_at' => cdbSnReal(14 * 86400), 'created_at' => cdbSnReal(-3600)]);
        (new \CMS\Services\Community\Repository\UserStatsRepository($db))->ensure($member, cdbSnReal());
        $run = cdbSnHoldThen(
            $db,
            ['action' => 'ban.lift', 'args' => ['actor' => $mod, 'ban' => $ban]],
            ['action' => 'ban.create', 'args' => ['actor' => $db->user(['role' => 'moderator']), 'user' => $member, 'scope' => 'write', 'duration' => 86400]]
        );
        T::check($run['locked'] && $run['waiting'], 'Aufheben hält, die Sperre wartet');
        T::same(true, $run['holder']['result']['changed'] ?? null, 'Sperre aufgehoben');
        T::same(true, $run['waiter']['result']['success'] ?? null, 'neue Sperre angelegt (nicht already_banned)', json_encode($run['waiter']));
        T::same([], cdbProcessProblems([$run['holder'], $run['waiter']]), 'kein db_error, keine Ausnahme');
    } finally {
        $db->dispose();
    }
});

cdbSection('race-resolve', 'race-resolve: 4 Moderatoren erledigen dasselbe Bündel gleichzeitig: genau ein Erfolg', static function (): void {
    cdbSnClasses();
    $db = MariaDbTestDatabase::create();
    try {
        $forum = cdbOpenForum($db);
        $post = cdbSnPost($db, $forum, $db->user());
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = $db->report(['target' => $post, 'forum' => $forum, 'reporter' => $db->user(), 'created_at' => cdbSnReal(-60)]);
        }
        $jobs = [];
        foreach (['valid', 'invalid', 'resolved', 'valid'] as $status) {
            $jobs[] = ['action' => 'report.resolve', 'args' => ['moderator' => $db->user(['role' => 'moderator']), 'report' => $ids[0], 'status' => $status], 'settings' => CDB_SN_SETTINGS];
        }
        $results = cdbRace($db, $jobs);
        T::same([], cdbProcessProblems($results), 'kein db_error, keine Ausnahme');
        $winners = array_values(array_filter($results, static fn (array $r): bool => !empty($r['result']['success'])));
        T::same([1, 3], [count($winners), count(array_filter($results, static fn (array $r): bool => ($r['result']['error'] ?? null) === 'report_closed'))], 'genau 1 Erfolg, 3 report_closed');
        T::same([6, 6], [count($winners[0]['result']['report_ids'] ?? []), count($winners[0]['result']['hooks'] ?? [])], 'der Gewinner schließt alle 6 Meldungen, 6 Hooks');
        T::same(1, (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_moderation_log WHERE action = 'report.resolve'"), 'ein Protokolleintrag');
        T::same(0, $db->count('community_reports', ['status' => 'open']), 'keine offene Meldung');
    } finally {
        $db->dispose();
    }
});

cdbSection('race-resolve-report', 'race-resolve-report: Erledigen sperrt über IDs; eine neue Meldung zum Ziel wartet nicht und bleibt offen', static function (): void {
    cdbSnClasses();
    $db = MariaDbTestDatabase::create();
    try {
        $forum = cdbOpenForum($db);
        $post = cdbSnPost($db, $forum, $db->user());
        $first = $db->report(['target' => $post, 'forum' => $forum, 'reporter' => $db->user(), 'created_at' => cdbSnReal(-60)]);
        $db->report(['target' => $post, 'forum' => $forum, 'reporter' => $db->user(), 'created_at' => cdbSnReal(-30)]);
        $reporter = $db->user();
        $run = cdbSnHoldThen(
            $db,
            ['action' => 'report.resolve', 'args' => ['moderator' => $db->user(['role' => 'moderator']), 'report' => $first, 'status' => 'valid']],
            ['action' => 'report.create', 'args' => ['user' => $reporter, 'type' => 'post', 'target' => $post]],
            'resolveHold'
        );
        T::check($run['locked'], 'resolve hält die Sperre der Meldungen');
        T::check(!$run['waiting'], 'die neue Meldung wartet nicht (keine Lückensperre)');
        T::same([true, false], [$run['waiter']['result']['success'] ?? null, $run['waiter']['result']['merged'] ?? null], 'neue Meldung angelegt', json_encode($run['waiter']));
        T::same([true, 2], [$run['holder']['result']['success'] ?? null, $run['holder']['result']['count'] ?? null], 'resolve schließt nur die beiden vorher gelesenen Meldungen', json_encode($run['holder']));
        T::same('open', (string) cdbScalar($db, 'SELECT status FROM cms_community_reports WHERE id = ?', [(int) ($run['waiter']['result']['report_id'] ?? 0)]), 'die neue Meldung bleibt offen');
        T::same([], cdbProcessProblems([$run['holder'], $run['waiter']]), 'kein db_error, keine Ausnahme');
    } finally {
        $db->dispose();
    }
});

cdbSection('inject-sanctions', 'inject-sanctions: Fehler an jeder Anweisung von create, resolve, ban und lift', static function (): void {
    $scenarios = [
        'report.create' => static function (CdbWorld $w): array {
            $forum = cdbOpenForum($w->db);
            $author = $w->db->user();
            $post = cdbSnPost($w->db, $forum, $author);
            $topic = (int) cdbScalar($w->db, 'SELECT topic_id FROM cms_community_posts WHERE id = ?', [$post]);
            $reporter = $w->db->user();
            $w->db->stats($reporter, ['trust_level' => 2, 'trust_level_locked' => true]);
            $other = $w->db->user();
            $w->db->stats($other, ['trust_level' => 2, 'trust_level_locked' => true]);
            $w->db->report(['target' => $post, 'forum' => $forum, 'reporter' => $other, 'created_at' => $w->utc(-60)]);

            return [static fn (CdbWorld $x): array => cdbSnServices($x)[0]->create($reporter, 'post', $post, 'spam', 'Hinweis'), [['community_topics', 'id', $topic], ['community_user_stats', 'user_id', $reporter]]];
        },
        'report.resolve' => static function (CdbWorld $w): array {
            $forum = cdbOpenForum($w->db);
            $post = cdbSnPost($w->db, $forum, $w->db->user());
            $id = $w->db->report(['target' => $post, 'forum' => $forum, 'reporter' => $w->db->user(), 'created_at' => $w->utc(-60)]);
            $w->db->report(['target' => $post, 'forum' => $forum, 'reporter' => $w->db->user(), 'created_at' => $w->utc(-30)]);
            $mod = $w->db->user(['role' => 'moderator']);

            return [static fn (CdbWorld $x): array => cdbSnServices($x)[0]->resolve($mod, $id, 'invalid', 'Notiz'), [['community_reports', 'id', $id]]];
        },
        'ban' => static function (CdbWorld $w): array {
            $mod = $w->db->user(['role' => 'moderator']);
            $target = $w->db->user();
            $w->db->ban(['user' => $target, 'scope' => 'write', 'starts_at' => $w->utc(-7200), 'ends_at' => $w->utc(3600), 'created_at' => $w->utc(-7200)]);

            return [static fn (CdbWorld $x): array => cdbSnServices($x)[1]->ban($mod, $target, 'full', 86400, 'Spam', 'Notiz'), [['community_user_stats', 'user_id', $target]]];
        },
        'lift' => static function (CdbWorld $w): array {
            $mod = $w->db->user(['role' => 'moderator']);
            $target = $w->db->user();
            $ban = $w->db->ban(['user' => $target, 'scope' => 'write', 'issued_by' => $mod, 'starts_at' => $w->utc(-7200), 'ends_at' => $w->utc(86400), 'created_at' => $w->utc(-7200)]);

            return [static fn (CdbWorld $x): array => cdbSnServices($x)[1]->lift($mod, $ban, 'Versehen'), [['community_user_stats', 'user_id', $target]]];
        },
    ];
    foreach ($scenarios as $name => $scenario) {
        $report = cdbSnInject($scenario);
        T::check($report['statements'] >= 5, $name . ': ungestörter Lauf mit ' . $report['statements'] . ' Anweisungen');
        T::same([], $report['problems'], $name . ': Fehler an jeder Anweisung sauber zurückgenommen, danach gelingt die Aktion');
        T::info($name . ': ' . $report['statements'] . ' Anweisungen; unerheblich für den Ausgang: ' . json_encode($report['tolerated']));
    }
});

cdbSection('utc-sanctions', 'utc-sanctions: Zeiten der Meldungen und Sperren in UTC (Sitzung +01:00, PHP Berlin +02:00), Ende wirkt genau', static function (): void {
    $w = new CdbWorld(CDB_SN_SETTINGS);
    try {
        [$reports, $bans] = cdbSnServices($w);
        $db = $w->db;
        $forum = cdbOpenForum($db);
        $post = cdbSnPost($db, $forum, $db->user());
        $reporter = $db->user();
        $mod = $db->user(['role' => 'moderator']);
        $member = $db->user();
        $created = $reports->create($reporter, 'post', $post, 'spam');
        T::same($w->utc(), (string) cdbScalar($db, 'SELECT created_at FROM cms_community_reports WHERE id = ?', [(int) ($created['report_id'] ?? 0)]), 'reports.created_at = UTC der Uhr');
        $w->at(30);
        $reports->resolve($mod, (int) $created['report_id'], 'valid');
        T::same($w->utc(30), (string) cdbScalar($db, 'SELECT handled_at FROM cms_community_reports WHERE id = ?', [(int) $created['report_id']]), 'reports.handled_at = UTC der Uhr');
        $w->at(60);
        $ban = $bans->ban($mod, $member, 'write', 3600, 'Spam');
        $row = $db->row('community_bans', ['id' => (int) ($ban['ban_id'] ?? 0)]);
        T::same([$w->utc(60), $w->utc(3660), $w->utc(60)], [$row['starts_at'] ?? null, $row['ends_at'] ?? null, $row['created_at'] ?? null], 'bans.starts_at, ends_at (+1 h), created_at = UTC der Uhr');
        $repo = new BanRepository($db);
        T::same([true, false], [$repo->activeFor($member, $w->utc(3659)) !== null, $repo->activeFor($member, $w->utc(3660)) !== null], 'Sperre wirkt bis genau ends_at (ausschließlich)');
        $w->at(3659);
        $w->rebuild();
        T::same('banned_write', $w->access->can($member, 'reply', ['topic_id' => (int) cdbScalar($db, 'SELECT topic_id FROM cms_community_posts WHERE id = ?', [$post])])->reason, 'eine Sekunde vor dem Ende: banned_write');
        $w->at(3660);
        $w->rebuild();
        T::same(true, $w->access->can($member, 'reply', ['topic_id' => (int) cdbScalar($db, 'SELECT topic_id FROM cms_community_posts WHERE id = ?', [$post])])->allowed, 'zum Ende: wieder erlaubt');
        $w->at(100);
        $second = $bans->ban($db->user(['role' => 'admin']), $db->user(), 'full', null, 'Spam');
        T::same(true, $second['success'] ?? null, 'dauerhafte Sperre durch die Administration', json_encode($second));
        [, $bans] = cdbSnServices($w);
        $w->at(200);
        $bans->lift((int) cdbScalar($db, 'SELECT issued_by FROM cms_community_bans WHERE id = ?', [(int) ($second['ban_id'] ?? 0)]), (int) ($second['ban_id'] ?? 0));
        T::same($w->utc(200), (string) cdbScalar($db, 'SELECT lifted_at FROM cms_community_bans WHERE id = ?', [(int) ($second['ban_id'] ?? 0)]), 'bans.lifted_at = UTC der Uhr');
        T::same([], cdbRealTimeColumns($db), 'kein Zeitwert aus Spaltendefaults');
    } finally {
        $w->db->dispose();
    }
});

cdbSection('explain-sanctions', 'explain-sanctions: Leseanweisungen der Sanktionsdienste über ihre Indizes (20.000 Meldungen, 5.000 Sperren)', static function (): void {
    $db = MariaDbTestDatabase::create();
    try {
        $pdo = $db->getPdo();
        $pdo->exec('CREATE TABLE cdb_sn_digits (d TINYINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO cdb_sn_digits (d) VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)');
        $pdo->exec('CREATE TABLE cdb_sn_numbers (n INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO cdb_sn_numbers (n) SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d FROM cdb_sn_digits a, cdb_sn_digits b, cdb_sn_digits c, cdb_sn_digits e');
        // 20.000 Meldungen von 400 Meldern auf 4.000 Beiträge in 40 Foren, jede zehnte offen; 2.000 Mitgliedermeldungen.
        foreach ([0, 1] as $half) {
            $pdo->exec(
                "INSERT INTO cms_community_reports (target_type, target_id, forum_id, reporter_id, reason, message, status, notify_reporter, handled_by, handled_at, created_at)
                 SELECT 'post', (n MOD 4000) + 1 + {$half} * 4000, (n MOD 40) + 1, (n MOD 400) + 100, 'spam', NULL,
                        CASE WHEN n MOD 10 = 0 THEN 'open' ELSE 'valid' END, 1, CASE WHEN n MOD 10 = 0 THEN NULL ELSE 7 END, NULL,
                        TIMESTAMP('2026-01-01 00:00:00') + INTERVAL ((n + {$half} * 10000) * 600) SECOND
                 FROM cdb_sn_numbers ORDER BY n"
            );
        }
        $pdo->exec(
            "INSERT INTO cms_community_reports (target_type, target_id, forum_id, reporter_id, reason, status, notify_reporter, created_at)
             SELECT 'user', (n MOD 500) + 1, NULL, (n MOD 400) + 100, 'abuse', CASE WHEN n MOD 5 = 0 THEN 'open' ELSE 'invalid' END, 1,
                    TIMESTAMP('2026-06-01 00:00:00') + INTERVAL (n * 60) SECOND
             FROM cdb_sn_numbers WHERE n < 2000 ORDER BY n"
        );
        // 5.000 Sperren von 2.500 Mitgliedern: aufgehoben, abgelaufen, wirksam, geplant
        $pdo->exec(
            "INSERT INTO cms_community_bans (user_id, scope, reason, issued_by, starts_at, ends_at, lifted_at, lifted_by, created_at)
             SELECT (n MOD 2500) + 1, CASE WHEN n MOD 3 = 0 THEN 'full' ELSE 'write' END, 'Grund', 7,
                    TIMESTAMP('2026-01-01 00:00:00') + INTERVAL (n * 3000) SECOND,
                    CASE WHEN n MOD 4 = 1 THEN TIMESTAMP('2026-01-02 00:00:00') + INTERVAL (n * 3000) SECOND WHEN n MOD 4 = 2 THEN TIMESTAMP('2027-06-01 00:00:00') ELSE NULL END,
                    CASE WHEN n MOD 4 = 0 THEN TIMESTAMP('2026-02-01 00:00:00') + INTERVAL (n * 3000) SECOND ELSE NULL END,
                    CASE WHEN n MOD 4 = 0 THEN 7 ELSE NULL END,
                    TIMESTAMP('2026-01-01 00:00:00') + INTERVAL (n * 3000) SECOND
             FROM cdb_sn_numbers WHERE n < 5000 ORDER BY n"
        );
        // Beiträge zu einem Thema für reassignTopic (a); Forum-Moderatoren für moderatorAssignments
        $pdo->exec("INSERT INTO cms_community_posts (topic_id, forum_id, user_id, status, body, created_at, updated_at) SELECT (n MOD 400) + 1, (n MOD 40) + 1, 5, 'published', 'x', '2026-01-01 00:00:00', '2026-01-01 00:00:00' FROM cdb_sn_numbers WHERE n < 8000 ORDER BY n");
        $pdo->exec("INSERT INTO cms_community_forum_moderators (forum_id, subject_type, subject_id, include_children) SELECT n + 1, CASE WHEN n MOD 2 = 0 THEN 'user' ELSE 'group' END, (n MOD 300) + 1, 1 FROM cdb_sn_numbers WHERE n < 3000 ORDER BY n");
        foreach (['cms_community_reports', 'cms_community_bans', 'cms_community_posts', 'cms_community_forum_moderators'] as $table) {
            $pdo->query('ANALYZE TABLE ' . $table)->fetchAll();
        }
        T::same([22000, 5000], [(int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_reports'), (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_bans')], 'Datenmenge: 22.000 Meldungen (2.000 offene Beitragsmeldungen, 400 offene Mitgliedermeldungen), 5.000 Sperren');

        $reports = new ReportRepository($db);
        $bans = new BanRepository($db);
        $forums = new ForumRepository($db);
        $now = '2026-11-20 12:00:00';
        $target = 41; // Beitrag mit offenen Meldungen (n = 40, 4040, …)
        $cases = [
            'R2 openOfReporter' => [static fn () => $reports->openOfReporter(140, 'post', $target), ['keys' => ['idx_community_reports_reporter'], 'maxReads' => 60]],
            'R3 recentByReporter' => [static fn () => $reports->recentByReporter(140, '2026-03-01 00:00:00'), ['keys' => ['idx_community_reports_reporter'], 'maxReads' => 200]],
            'R6 openReporters' => [static fn () => $reports->openReporters('post', $target, 51), ['keys' => ['idx_community_reports_target'], 'temporary' => true, 'maxReads' => 60]],
            'R9 hasOpen' => [static fn () => $reports->hasOpen('post', $target), ['keys' => ['idx_community_reports_target'], 'maxReads' => 20]],
            'R12 openForTarget' => [static fn () => $reports->openForTarget('post', $target, 101), ['keys' => ['idx_community_reports_target'], 'maxReads' => 60]],
            'R13 countOpen global' => [static fn () => $reports->countOpen(null, 100), ['keys' => null, 'inner' => ['idx_community_reports_open', 'idx_community_reports_handled'], 'maxReads' => 250, 'maxScan' => 110]],
            'R13 countOpen Foren' => [static fn () => $reports->countOpen([1, 2, 3], 100), ['keys' => null, 'inner' => ['idx_community_reports_forum'], 'maxReads' => 250, 'maxScan' => 110]],
            'R14a reassignTopic' => [static function () use ($db, $reports): int {
                $db->getPdo()->beginTransaction();
                try {
                    return $reports->reassignTopic(7, 9);
                } finally {
                    $db->getPdo()->rollBack();
                }
            }, ['keys' => ['idx_community_posts_topic', 'idx_community_reports_target'], 'temporary' => true, 'maxReads' => 400]],
            'B3 activeList' => [static fn () => $bans->activeList(17, $now), ['keys' => ['idx_community_bans_user'], 'filesort' => true, 'maxReads' => 20]],
            'B6 forUser' => [static fn () => $bans->forUser(17, 50, true), ['keys' => ['idx_community_bans_user'], 'filesort' => true, 'maxReads' => 20]],
            'B7 listActive' => [static fn () => $bans->listActive($now, 0, 51), ['keys' => ['PRIMARY'], 'maxReads' => 400]],
            'F1 moderatorAssignments' => [static fn () => $forums->moderatorAssignments(12, [13, 14]), ['keys' => ['idx_community_forum_moderators_subject'], 'filesort' => true, 'maxReads' => 100]],
        ];
        foreach ($cases as $label => [$call, $rule]) {
            $measure = cdbMeasure($db, $call);
            $keys = $rule['keys'] ?? $rule['inner'];
            T::same([], cdbSnPlanProblems($measure, $rule), $label . ': Index ' . implode('|', $keys) . ', kein Tabellenscan, höchstens ' . $rule['maxReads'] . ' Lesezugriffe');
            if (isset($rule['inner'])) {
                // Zählung über eine abgeleitete Tabelle: die innere Abfrage liest über den Index
                $inner = array_values(array_filter($measure['plans'][0]['rows'] ?? [], static fn (array $row): bool => !str_starts_with((string) ($row['table'] ?? '<'), '<')));
                T::check($inner !== [] && in_array($inner[0]['key'] ?? null, $rule['inner'], true) && ($inner[0]['type'] ?? 'ALL') !== 'ALL', $label . ': innere Abfrage über ' . implode('|', $rule['inner']), json_encode($inner));
            }
            $plan = $measure['plans'][0]['rows'] ?? [];
            T::info($label . ': ' . $measure['reads'] . ' Lesezugriffe, ' . $measure['scan'] . ' Scan-Schritte; ' . implode(' / ', array_map(static fn (array $row): string => ($row['table'] ?? '-') . ':' . ($row['type'] ?? '-') . ':' . ($row['key'] ?? 'NULL') . ':' . ($row['Extra'] ?? ''), $plan)));
        }
        // R10 Bündel: GROUP BY über die Ziele (temporäre Tabelle über die offenen Zeilen erlaubt), nie ein Tabellenscan
        $openRows = (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_reports WHERE status = 'open'");
        $openForums = (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_reports WHERE status = 'open' AND forum_id IN (1, 2, 3)");
        foreach (['R10 openBundles global' => [null, ['idx_community_reports_open', 'idx_community_reports_target', 'idx_community_reports_handled'], $openRows], 'R10 openBundles Foren' => [[1, 2, 3], ['idx_community_reports_forum'], $openForums]] as $label => [$forumIds, $keys, $open]) {
            $measure = cdbMeasure($db, static fn () => $reports->openBundles($forumIds, null, 0, 21));
            $rows = $measure['plans'][0]['rows'] ?? [];
            $first = $rows[0] ?? [];
            T::check(($first['type'] ?? 'ALL') !== 'ALL' && in_array($first['key'] ?? null, $keys, true), $label . ': Index ' . implode('|', $keys) . ', kein Tabellenscan', json_encode($first));
            // Kosten wie im Entwurf gemessen (sanktionen §2.9 R10): die offenen Zeilen im Bereich einmal lesen, gruppieren, sortieren.
            T::check($measure['reads'] <= 2 * $open + 100 && $measure['scan'] <= $open + 100, $label . ': Lesezugriffe und Scan-Schritte höchstens etwa die offenen Zeilen im Bereich (' . $open . '): ' . $measure['reads'] . '/' . $measure['scan']);
            T::same(21, count($measure['result']), $label . ': volle Seite');
            T::info($label . ': ' . $measure['reads'] . ' Lesezugriffe; ' . ($first['type'] ?? '-') . ':' . ($first['key'] ?? 'NULL') . ':' . ($first['Extra'] ?? ''));
        }
        $pdo->exec('DROP TABLE cdb_sn_numbers');
        $pdo->exec('DROP TABLE cdb_sn_digits');
    } finally {
        $db->dispose();
    }
});
