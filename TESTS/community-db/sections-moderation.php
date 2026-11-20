<?php
/**
 * Abschnitte der Einheit M (PR #2b, W2) in `TESTS/community-db`: Moderation und Zählerbuchung gegen MariaDB (Vertrag DESIGN-2b §3.10,
 * §3.11, §7.3; Entwurf moderation §6.3). Orakel jeweils `communityAudit()` (`TESTS/lib/CommunityAudit.php`) und `cdbAudit()`.
 *
 *  race-approve-delete  Freigabe einer wartenden Antwort, gleichzeitig löscht der Autor sie: Freigabe gelingt oder `invalid_state`,
 *                       Löschen gelingt, am Ende `deleted`, Zähler stimmen (5 Runden)
 *  race-approve-reply   Freigabe einer alten wartenden Antwort, gleichzeitig 7 neue Antworten: `reply_count`, `last_*` = jüngste
 *  race-move-reply      Thema A → B verschieben, gleichzeitig 7 Antworten: alle gelingen, alle `posts.forum_id = B`, Zähler A/B
 *  race-move-cross      Thema X A → B und Y B → A gleichzeitig, 20 Runden: kein `db_error` (Foren aufsteigend gesperrt)
 *  race-pin-limit       49 wirksame Pins, 8 Prozesse heften 8 Themen an: genau einer gelingt, 7 × `pin_limit`
 *  race-hide-reply      Thema ausblenden, gleichzeitig antworten 6 neue Autoren: Planprüfung wiederholt, Mitgliederzähler stimmen
 *  race-hide-overlap    8 Prozesse blenden je ein Thema mit 30 gemeinsamen Autoren dreimal aus und ein: kein `db_error`
 *  race-bulk            Massenfreigabe von 100 Antworten, gleichzeitig 7 Antworten in denselben Themen: kein `db_error`, Orakel
 *  inject-moderation    Fehler an jeder Anweisung von approveTopic, hideTopic, moveTopic, movePosts, markSolution: Zustand unverändert,
 *                       kein Hook, keine offene Transaktion, keine hängende Sperre, Wiederholung gelingt
 *  utc-moderation       Sitzung +01:00, PHP Europe/Berlin (Sommer): `locked_until`, `pinned_until`, `moved_expires_at`, Protokoll in UTC;
 *                       `expire()` genau an der UTC-Grenze
 *  explain-moderation   EXPLAIN-Gate (24.000 Themen, 100.000 Beiträge): Pin-Zählung, Autoren, Statistiksperre, Warteschlange global,
 *                       Protokoll je Forum und global, Verweise, Ablauf, Moderatoren, Meldungen beim Verschieben, Ankündigungen
 *
 * `race-solution-delete` braucht `PostService::delete` über CounterBook (T, W3) und folgt in W4; ebenso `perf-topic-authors` (§3.11).
 * Worker-Aktionen in `actions-moderation.php`. Geladen von `run.php` (nutzt `cdbSection()`, `CdbWorld`, `cdbRace()`, `cdbInjectAll()`,
 * `cdbMeasure()`, `cdbPlanProblems()`, `cdbBulkLoad()`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CommunityAudit.php';

use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\ModerationLogRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\ReportRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Service\ModerationService;

/** ModerationService der Testwelt: Uhr, echte CommunityAccess und Hook-Protokoll der Welt (auch nach `rebuild()`). */
function cdbModeration(CdbWorld $w): ModerationService
{
    return new ModerationService(
        $w->db,
        $w->settings,
        static fn (): int => (int) $w->time['now'],
        static fn (?int $userId, string $action, array $context) => $w->access->can($userId, $action, $context),
        static function (string $hook, mixed ...$args) use ($w): void {
            $w->hooks[] = ['hook' => $hook, 'args' => $args, 'in_transaction' => $w->db->getPdo()->inTransaction()];
        },
        static fn (?int $userId, array $context = []): array => $w->access->moderationScope($userId, $context),
        null
    );
}

/**
 * Lädt die Klassen der Moderation im Hauptprozess. Die Wettläufe rufen den Dienst nur in den Workern auf; ohne Laden gälte eine
 * Gegenprobe (`TESTS/community-mutants`) im Hauptprozess als nie angewendet (Exit 99).
 */
function cdbModPreload(): void
{
    foreach ([ModerationService::class, \CMS\Services\Community\Service\CounterBook::class, UserStatsRepository::class, ModerationLogRepository::class, ReportRepository::class] as $class) {
        class_exists($class);
    }
}

/** Ergebnis als `ok`, `unchanged` oder Fehlercode. */
function cdbModOutcome(array $result): string
{
    if (empty($result['success'])) {
        return (string) ($result['error'] ?? ($result['exception'] ?? '?'));
    }

    return ($result['changed'] ?? true) === false ? 'unchanged' : 'ok';
}

/**
 * Thema mit erstem Beitrag und Antworten `[[user, status], …]` über die Fixtures (Zeiten ab `$at`, je eine Sekunde Abstand).
 *
 * @param list<array{0: int, 1: string}> $replies
 * @return array{0: int, 1: list<int>}
 */
function cdbModSeed(MariaDbTestDatabase $db, int $forum, int $starter, array $replies = [], string $status = 'published', string $first = 'published', string $at = '2036-01-01 00:00:00'): array
{
    $base = gmmktime((int) substr($at, 11, 2), (int) substr($at, 14, 2), (int) substr($at, 17, 2), (int) substr($at, 5, 2), (int) substr($at, 8, 2), (int) substr($at, 0, 4));
    $topic = $db->topic(['forum' => $forum, 'user' => $starter, 'status' => $status, 'created_at' => gmdate('Y-m-d H:i:s', $base)]);
    $ids = [$db->post(['topic' => $topic, 'user' => $starter, 'first' => true, 'status' => $first, 'created_at' => gmdate('Y-m-d H:i:s', $base)])];
    foreach ($replies as $i => [$user, $replyStatus]) {
        $ids[] = $db->post(['topic' => $topic, 'user' => $user, 'status' => $replyStatus, 'created_at' => gmdate('Y-m-d H:i:s', $base + $i + 1)]);
    }
    $db->execute('UPDATE cms_community_topics SET first_post_id = ? WHERE id = ?', [$ids[0], $topic]);

    return [$topic, $ids];
}

/** Orakel (S1 und Invarianten) und `cdbAudit()` als je eine Prüfung. */
function cdbModAudit(MariaDbTestDatabase $db, string $label): void
{
    T::same([], communityAudit($db, gmdate('Y-m-d H:i:s')), $label . ': communityAudit() grün');
    T::same([], cdbAudit($db), $label . ': cdbAudit() grün');
}

/** Ergebnisse der Prozesse als Liste `aktion:ergebnis` (für Hinweise). */
function cdbModSummary(array $results): string
{
    return implode(', ', array_map(static fn (array $r): string => ($r['action'] ?? '?') . ':' . cdbModOutcome((array) ($r['result'] ?? ['success' => false, 'error' => $r['exception'] ?? '?'])), $results));
}

cdbSection('race-approve-delete', 'race-approve-delete: Freigabe gegen Löschen durch den Autor (5 Runden)', static function (): void {
    cdbModPreload();
    $db = MariaDbTestDatabase::create();
    $mod = $db->user(['role' => 'moderator']);
    $s = $db->user();
    $a = $db->user();
    $forum = cdbOpenForum($db);
    [$topic] = cdbModSeed($db, $forum, $s, [[$s, 'published']]);
    communityRecount($db, gmdate('Y-m-d H:i:s'));
    $problems = [];
    $seen = [];
    for ($round = 0; $round < 5; $round++) {
        $post = $db->post(['topic' => $topic, 'user' => $a, 'status' => 'pending', 'created_at' => gmdate('Y-m-d H:i:s', time() - 60 + $round)]);
        $results = cdbRace($db, [
            ['action' => 'mod.approve-post', 'args' => ['user' => $mod, 'post' => $post], 'settings' => CDB_NOLIMIT_SETTINGS],
            ['action' => 'post.delete', 'args' => ['user' => $a, 'post' => $post], 'settings' => CDB_NOLIMIT_SETTINGS],
        ]);
        array_push($problems, ...cdbProcessProblems($results));
        $approve = cdbModOutcome((array) ($results[0]['result'] ?? []));
        $delete = cdbModOutcome((array) ($results[1]['result'] ?? []));
        $seen[] = $approve . '/' . $delete;
        T::check(in_array($approve, ['ok', 'invalid_state'], true) && $delete === 'ok', 'Runde ' . $round . ': Freigabe ok oder invalid_state, Löschen ok', $approve . '/' . $delete);
        T::same('deleted', (string) cdbScalar($db, 'SELECT status FROM cms_community_posts WHERE id = ?', [$post]), 'Runde ' . $round . ': am Ende gelöscht');
        cdbModAudit($db, 'Runde ' . $round);
    }
    T::same([], $problems, 'keine Ausnahme, kein db_error, keine offene Transaktion');
    T::info('race-approve-delete: ' . implode(', ', $seen));
    $db->dispose();
});

cdbSection('race-approve-reply', 'race-approve-reply: Freigabe einer alten wartenden Antwort, gleichzeitig 7 neue Antworten', static function (): void {
    cdbModPreload();
    $db = MariaDbTestDatabase::create();
    $mod = $db->user(['role' => 'moderator']);
    $s = $db->user();
    $forum = cdbOpenForum($db);
    $members = [];
    for ($i = 0; $i < 7; $i++) {
        $members[] = $db->user();
    }
    [$topic, $posts] = cdbModSeed($db, $forum, $s, [[$s, 'pending'], [$s, 'published']]);
    communityRecount($db, gmdate('Y-m-d H:i:s'));
    $jobs = [['action' => 'mod.approve-post', 'args' => ['user' => $mod, 'post' => $posts[1]], 'settings' => CDB_NOLIMIT_SETTINGS]];
    foreach ($members as $i => $member) {
        $jobs[] = ['action' => 'post.reply', 'args' => ['user' => $member, 'topic' => $topic, 'body' => 'Gleichzeitige Antwort ' . $i], 'settings' => CDB_NOLIMIT_SETTINGS];
    }
    $results = cdbRace($db, $jobs);
    T::same([], cdbProcessProblems($results), 'keine Ausnahme, kein db_error');
    T::same(array_fill(0, 8, 'ok'), array_map(static fn (array $r): string => cdbModOutcome((array) ($r['result'] ?? [])), $results), 'Freigabe und alle Antworten gelingen');
    $last = (int) cdbScalar($db, "SELECT MAX(id) FROM cms_community_posts WHERE topic_id = ? AND status = 'published'", [$topic]);
    T::same([9, $last], [(int) cdbScalar($db, 'SELECT reply_count FROM cms_community_topics WHERE id = ?', [$topic]), (int) cdbScalar($db, 'SELECT last_post_id FROM cms_community_topics WHERE id = ?', [$topic])], 'reply_count = 9, last_post_id = jüngste Antwort');
    cdbModAudit($db, 'race-approve-reply');
    $db->dispose();
});

cdbSection('race-move-reply', 'race-move-reply: Thema verschieben, gleichzeitig 7 Antworten', static function (): void {
    cdbModPreload();
    $db = MariaDbTestDatabase::create();
    $mod = $db->user(['role' => 'moderator']);
    $s = $db->user();
    $from = cdbOpenForum($db);
    $to = cdbOpenForum($db);
    $members = [];
    for ($i = 0; $i < 7; $i++) {
        $members[] = $db->user();
    }
    [$topic] = cdbModSeed($db, $from, $s, [[$s, 'published']]);
    communityRecount($db, gmdate('Y-m-d H:i:s'));
    $jobs = [['action' => 'mod.move-topic', 'args' => ['user' => $mod, 'topic' => $topic, 'forum' => $to, 'stub' => true], 'settings' => CDB_NOLIMIT_SETTINGS]];
    foreach ($members as $i => $member) {
        $jobs[] = ['action' => 'post.reply', 'args' => ['user' => $member, 'topic' => $topic, 'body' => 'Antwort beim Verschieben ' . $i], 'settings' => CDB_NOLIMIT_SETTINGS];
    }
    $results = cdbRace($db, $jobs);
    T::same([], cdbProcessProblems($results), 'keine Ausnahme, kein db_error');
    T::same(array_fill(0, 8, 'ok'), array_map(static fn (array $r): string => cdbModOutcome((array) ($r['result'] ?? [])), $results), 'Verschieben und alle Antworten gelingen (Wiederholung nach Forumswechsel)');
    T::same([[$to], 9], [array_map('intval', array_column($db->execute('SELECT DISTINCT forum_id FROM cms_community_posts WHERE topic_id = ?', [$topic])->fetchAll(\PDO::FETCH_ASSOC), 'forum_id')), (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_posts WHERE topic_id = ? AND status = 'published'", [$topic])], 'alle 9 Beiträge im Zielforum');
    T::same([0, 1, 9], [(int) cdbScalar($db, 'SELECT topic_count FROM cms_community_forums WHERE id = ?', [$from]), (int) cdbScalar($db, 'SELECT topic_count FROM cms_community_forums WHERE id = ?', [$to]), (int) cdbScalar($db, 'SELECT post_count FROM cms_community_forums WHERE id = ?', [$to])], 'Zähler der Foren');
    cdbModAudit($db, 'race-move-reply');
    $db->dispose();
});

cdbSection('race-move-cross', 'race-move-cross: zwei Themen über Kreuz verschieben, 20 Runden, kein db_error', static function (): void {
    cdbModPreload();
    $db = MariaDbTestDatabase::create();
    $mod = $db->user(['role' => 'moderator']);
    $s = $db->user();
    $a = cdbOpenForum($db);
    $b = cdbOpenForum($db);
    [$x] = cdbModSeed($db, $a, $s, [[$s, 'published']]);
    [$y] = cdbModSeed($db, $b, $s, [[$s, 'published']]);
    communityRecount($db, gmdate('Y-m-d H:i:s'));
    $problems = [];
    $outcomes = [];
    $before = cdbDeadlocks();
    for ($round = 0; $round < 20; $round++) {
        [$xTo, $yTo] = $round % 2 === 0 ? [$b, $a] : [$a, $b];
        $results = cdbRace($db, [
            ['action' => 'mod.move-topic', 'args' => ['user' => $mod, 'topic' => $x, 'forum' => $xTo], 'settings' => CDB_NOLIMIT_SETTINGS],
            ['action' => 'mod.move-topic', 'args' => ['user' => $mod, 'topic' => $y, 'forum' => $yTo], 'settings' => CDB_NOLIMIT_SETTINGS],
        ]);
        array_push($problems, ...cdbProcessProblems($results));
        foreach ($results as $r) {
            $outcomes[] = cdbModOutcome((array) ($r['result'] ?? []));
        }
    }
    T::same([], $problems, 'keine Ausnahme, kein db_error in 40 Verschiebungen');
    T::same(['ok'], array_values(array_unique($outcomes)), 'jede Verschiebung gelingt');
    T::info('race-move-cross: Deadlocks des Servers während des Abschnitts: ' . (cdbDeadlocks() - $before));
    cdbModAudit($db, 'race-move-cross');
    $db->dispose();
});

cdbSection('race-pin-limit', 'race-pin-limit: 49 wirksame Pins, 8 gleichzeitige Anheftungen ⇒ genau eine gelingt', static function (): void {
    cdbModPreload();
    $db = MariaDbTestDatabase::create();
    $mod = $db->user(['role' => 'moderator']);
    $s = $db->user();
    $forum = cdbOpenForum($db);
    for ($i = 0; $i < 49; $i++) {
        $db->topic(['forum' => $forum, 'user' => $s, 'pinned' => true, 'created_at' => '2036-01-01 00:00:00']);
    }
    $candidates = [];
    for ($i = 0; $i < 8; $i++) {
        [$candidates[]] = cdbModSeed($db, $forum, $s);
    }
    communityRecount($db, gmdate('Y-m-d H:i:s'));
    $results = cdbRace($db, array_map(static fn (int $t): array => ['action' => 'mod.pin', 'args' => ['user' => $mod, 'topic' => $t], 'settings' => CDB_NOLIMIT_SETTINGS], $candidates));
    T::same([], cdbProcessProblems($results), 'keine Ausnahme, kein db_error');
    $outcomes = array_count_values(array_map(static fn (array $r): string => cdbModOutcome((array) ($r['result'] ?? [])), $results));
    ksort($outcomes);
    T::same(['ok' => 1, 'pin_limit' => 7], $outcomes, 'genau eine Anheftung gelingt, sieben erhalten pin_limit');
    T::same(50, (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_topics WHERE forum_id = ? AND is_pinned = 1', [$forum]), 'genau 50 Pins');
    cdbModAudit($db, 'race-pin-limit');
    $db->dispose();
});

cdbSection('race-hide-reply', 'race-hide-reply: Thema ausblenden, gleichzeitig 6 neue Autoren', static function (): void {
    cdbModPreload();
    $db = MariaDbTestDatabase::create();
    $mod = $db->user(['role' => 'moderator']);
    $s = $db->user();
    $a = $db->user();
    $forum = cdbOpenForum($db);
    $members = [];
    for ($i = 0; $i < 6; $i++) {
        $members[] = $db->user();
    }
    [$topic] = cdbModSeed($db, $forum, $s, [[$a, 'published'], [$a, 'published']]);
    communityRecount($db, gmdate('Y-m-d H:i:s'));
    $jobs = [['action' => 'mod.hide-topic', 'args' => ['user' => $mod, 'topic' => $topic], 'settings' => CDB_NOLIMIT_SETTINGS]];
    foreach ($members as $i => $member) {
        $jobs[] = ['action' => 'post.reply', 'args' => ['user' => $member, 'topic' => $topic, 'body' => 'Antwort beim Ausblenden ' . $i], 'settings' => CDB_NOLIMIT_SETTINGS];
    }
    $results = cdbRace($db, $jobs);
    T::same([], cdbProcessProblems($results), 'keine Ausnahme, kein db_error');
    $replies = array_map(static fn (array $r): string => cdbModOutcome((array) ($r['result'] ?? [])), array_slice($results, 1));
    T::same('ok', cdbModOutcome((array) ($results[0]['result'] ?? [])), 'Ausblenden gelingt');
    T::same([], array_values(array_diff(array_unique($replies), ['ok', 'not_found'])), 'Antworten: vor dem Ausblenden ok, danach not_found (Thema unsichtbar)');
    T::same(array_fill(0, 6, [0, 0]), array_map(static fn (int $m): array => [(int) cdbScalar($db, 'SELECT topic_count FROM cms_community_user_stats WHERE user_id = ?', [$m]), (int) cdbScalar($db, 'SELECT post_count FROM cms_community_user_stats WHERE user_id = ?', [$m])], $members), 'neue Autoren: nichts gezählt (ihre Beiträge stehen im ausgeblendeten Thema)');
    T::info('race-hide-reply: ' . cdbModSummary($results));
    cdbModAudit($db, 'race-hide-reply');
    $db->dispose();
});

cdbSection('race-hide-overlap', 'race-hide-overlap: 8 Themen mit 30 gemeinsamen Autoren gleichzeitig aus- und einblenden', static function (): void {
    cdbModPreload();
    $db = MariaDbTestDatabase::create();
    $mod = $db->user(['role' => 'moderator']);
    $s = $db->user();
    $forum = cdbOpenForum($db);
    $authors = [];
    for ($i = 0; $i < 30; $i++) {
        $authors[] = $db->user();
    }
    $topics = [];
    for ($i = 0; $i < 8; $i++) {
        $order = $authors;
        shuffle($order);
        [$topics[]] = cdbModSeed($db, $forum, $s, array_map(static fn (int $u): array => [$u, 'published'], $order));
    }
    communityRecount($db, gmdate('Y-m-d H:i:s'));
    $before = cdbDeadlocks();
    $results = cdbRace($db, array_map(static fn (int $t): array => ['action' => 'mod.hide-toggle', 'args' => ['user' => $mod, 'topic' => $t, 'rounds' => 3], 'settings' => CDB_NOLIMIT_SETTINGS], $topics));
    T::same([], cdbProcessProblems($results), 'keine Ausnahme, kein db_error');
    T::same(array_fill(0, 8, 'ok'), array_map(static fn (array $r): string => cdbModOutcome((array) ($r['result'] ?? [])), $results), 'alle 48 Statuswechsel gelingen');
    T::info('race-hide-overlap: Deadlocks des Servers während des Abschnitts: ' . (cdbDeadlocks() - $before));
    cdbModAudit($db, 'race-hide-overlap');
    $db->dispose();
});

cdbSection('race-bulk', 'race-bulk: Massenfreigabe von 100 Antworten, gleichzeitig 7 Antworten in denselben Themen', static function (): void {
    cdbModPreload();
    $db = MariaDbTestDatabase::create();
    $mod = $db->user(['role' => 'moderator']);
    $s = $db->user();
    $forum = cdbOpenForum($db);
    $authors = [];
    for ($i = 0; $i < 10; $i++) {
        $authors[] = $db->user();
    }
    $topics = [];
    $pending = [];
    for ($t = 0; $t < 4; $t++) {
        $replies = [];
        for ($i = 0; $i < 25; $i++) {
            $replies[] = [$authors[($t * 25 + $i) % 10], 'pending'];
        }
        [$topic, $ids] = cdbModSeed($db, $forum, $s, $replies);
        $topics[] = $topic;
        array_push($pending, ...array_slice($ids, 1));
    }
    $members = [];
    for ($i = 0; $i < 7; $i++) {
        $members[] = $db->user();
    }
    communityRecount($db, gmdate('Y-m-d H:i:s'));
    $jobs = [['action' => 'mod.bulk', 'args' => ['user' => $mod, 'operation' => 'post.approve', 'ids' => $pending], 'settings' => CDB_NOLIMIT_SETTINGS]];
    foreach ($members as $i => $member) {
        $jobs[] = ['action' => 'post.reply', 'args' => ['user' => $member, 'topic' => $topics[$i % 4], 'body' => 'Antwort bei der Massenfreigabe ' . $i], 'settings' => CDB_NOLIMIT_SETTINGS];
    }
    $results = cdbRace($db, $jobs);
    T::same([], cdbProcessProblems($results), 'keine Ausnahme, kein db_error');
    T::same([true, 100, 0], [$results[0]['result']['success'] ?? null, $results[0]['result']['done'] ?? null, $results[0]['result']['failed'] ?? null], 'Massenfreigabe: 100 gelungen, keine gescheitert (Deadlocks wiederholt)');
    T::same(array_fill(0, 7, 'ok'), array_map(static fn (array $r): string => cdbModOutcome((array) ($r['result'] ?? [])), array_slice($results, 1)), 'alle Antworten gelingen');
    cdbModAudit($db, 'race-bulk');
    $db->dispose();
});

cdbSection('inject-moderation', 'inject-moderation: Fehler an jeder Anweisung von approveTopic, hideTopic, moveTopic, movePosts, markSolution', static function (): void {
    $scenarios = [
        'approveTopic' => static function (CdbWorld $w): array {
            $mod = $w->db->user(['role' => 'moderator']);
            $s = $w->db->user();
            $forum = cdbOpenForum($w->db);
            [$topic] = cdbModSeed($w->db, $forum, $s, [[$mod, 'published']], 'pending', 'pending');
            communityRecount($w->db, $w->utc());

            return [static fn (CdbWorld $w): array => cdbModeration($w)->approveTopic($mod, $topic), [['community_forums', 'id', $forum], ['community_topics', 'id', $topic], ['community_user_stats', 'user_id', $s]]];
        },
        'hideTopic' => static function (CdbWorld $w): array {
            $mod = $w->db->user(['role' => 'moderator']);
            $s = $w->db->user();
            $a = $w->db->user();
            $forum = cdbOpenForum($w->db);
            [$topic, $posts] = cdbModSeed($w->db, $forum, $s, [[$a, 'published'], [$a, 'published']]);
            $w->db->execute('UPDATE cms_community_topics SET solution_post_id = ?, solved_at = ?, solved_by = ? WHERE id = ?', [$posts[1], $w->utc(), $s, $topic]);
            communityRecount($w->db, $w->utc());

            return [static fn (CdbWorld $w): array => cdbModeration($w)->hideTopic($mod, $topic, 'Grund'), [['community_forums', 'id', $forum], ['community_topics', 'id', $topic], ['community_user_stats', 'user_id', $a]]];
        },
        'moveTopic' => static function (CdbWorld $w): array {
            $mod = $w->db->user(['role' => 'moderator']);
            $s = $w->db->user();
            $from = cdbOpenForum($w->db);
            $to = cdbOpenForum($w->db);
            [$topic, $posts] = cdbModSeed($w->db, $from, $s, [[$s, 'published']]);
            $w->db->report(['target' => $posts[1], 'forum' => $from]);
            communityRecount($w->db, $w->utc());

            return [static fn (CdbWorld $w): array => cdbModeration($w)->moveTopic($mod, $topic, $to, true), [['community_forums', 'id', $from], ['community_forums', 'id', $to], ['community_topics', 'id', $topic]]];
        },
        'movePosts' => static function (CdbWorld $w): array {
            $mod = $w->db->user(['role' => 'moderator']);
            $s = $w->db->user();
            $a = $w->db->user();
            $from = cdbOpenForum($w->db);
            $to = cdbOpenForum($w->db);
            [$source, $posts] = cdbModSeed($w->db, $from, $s, [[$a, 'published'], [$a, 'published']]);
            [$target] = cdbModSeed($w->db, $to, $s);
            $w->db->execute('UPDATE cms_community_topics SET solution_post_id = ?, solved_at = ?, solved_by = ? WHERE id = ?', [$posts[1], $w->utc(), $s, $source]);
            $w->db->reaction(['post' => $posts[1], 'user' => $s]);
            $w->db->attachment(['post' => $posts[1], 'user' => $a]);
            communityRecount($w->db, $w->utc());

            return [static fn (CdbWorld $w): array => cdbModeration($w)->movePosts($mod, [$posts[1]], $target), [['community_topics', 'id', $source], ['community_topics', 'id', $target], ['community_posts', 'id', $posts[1]], ['community_user_stats', 'user_id', $a]]];
        },
        'markSolution' => static function (CdbWorld $w): array {
            $mod = $w->db->user(['role' => 'moderator']);
            $s = $w->db->user();
            $a = $w->db->user();
            $b = $w->db->user();
            $forum = cdbOpenForum($w->db, ['type' => 'qa']);
            [$topic, $posts] = cdbModSeed($w->db, $forum, $s, [[$a, 'published'], [$b, 'published']]);
            $w->db->execute('UPDATE cms_community_topics SET solution_post_id = ?, solved_at = ?, solved_by = ? WHERE id = ?', [$posts[1], $w->utc(), $s, $topic]);
            communityRecount($w->db, $w->utc());

            return [static fn (CdbWorld $w): array => cdbModeration($w)->markSolution($mod, $topic, $posts[2]), [['community_topics', 'id', $topic], ['community_posts', 'id', $posts[2]], ['community_user_stats', 'user_id', $a], ['community_user_stats', 'user_id', $b]]];
        },
    ];
    foreach ($scenarios as $name => $scenario) {
        $report = cdbInjectAll($scenario);
        // Die Warnungen der ausgefallenen Anweisungen sind gewollt; nicht an spätere Abschnitte weitergeben (die prüfen „keine Warnungen“).
        cdbLogs();
        cdbReportInjection($name, $report);
        T::same([], $report['problems'], $name . ': jede ausgefallene Anweisung ⇒ kein Teilzustand, kein Hook, keine offene Transaktion, Wiederholung gelingt (' . $report['statements'] . ' Anweisungen)');
        T::check($report['counts']['lock'] >= 2 && $report['counts']['write'] >= 2, $name . ': Sperren und Schreibanweisungen erreicht', json_encode($report['counts']));
    }
});

cdbSection('utc-moderation', 'utc-moderation: Fristen und Protokoll in UTC trotz Sitzung +01:00 und PHP Europe/Berlin; expire an der UTC-Grenze', static function (): void {
    $w = new CdbWorld(CDB_NOLIMIT_SETTINGS);
    $db = $w->db;
    $mod = $db->user(['role' => 'moderator']);
    $s = $db->user();
    $from = cdbOpenForum($db);
    $to = cdbOpenForum($db);
    [$t1] = cdbModSeed($db, $from, $s);
    [$t2] = cdbModSeed($db, $from, $s);
    communityRecount($db, $w->utc());
    $service = cdbModeration($w);
    T::same(['ok', 'ok', 'ok'], [cdbModOutcome($service->lock($mod, $t1, cdbAt(3600))), cdbModOutcome($service->pin($mod, $t1, cdbAt(7200))), cdbModOutcome($moved = $service->moveTopic($mod, $t2, $to, true))], 'schließen, anheften, verschieben');
    $topic = $w->topic($t1);
    $stub = $w->topic((int) ($moved['stub_topic_id'] ?? 0));
    T::same([cdbUtc(3600), cdbUtc(7200), cdbUtc(7 * 86400)], [$topic['locked_until'], $topic['pinned_until'], $stub['moved_expires_at'] ?? null], 'Fristen = Uhr des Dienstes in UTC');
    T::same([cdbUtc(), cdbUtc(), cdbUtc()], array_column($db->execute('SELECT created_at FROM cms_community_moderation_log ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC), 'created_at'), 'Protokoll created_at in UTC');
    T::same([], cdbRealTimeColumns($db), 'kein Wert aus Spaltendefaults oder NOW()');
    $w->at(3599);
    T::same(0, cdbModeration($w)->expire()['locks'] ?? null, 'eine Sekunde vor der Frist: nichts aufgeräumt');
    $w->at(3600);
    T::same([1, 0], [cdbModeration($w)->expire()['locks'] ?? null, (int) $w->topic($t1)['is_locked']], 'genau an der UTC-Grenze: geöffnet');
    $w->at(7 * 86400);
    T::same([1, 1, null], [($e = cdbModeration($w)->expire())['pins'] ?? null, $e['stubs'] ?? null, $w->topic((int) $moved['stub_topic_id'])], 'Pin und Verweis an der Grenze aufgeräumt');
    cdbModAudit($db, 'utc-moderation');
    $db->dispose();
});

cdbSection('explain-moderation', 'explain-moderation: Abfragen der Moderation nutzen ihre Indizes (24.000 Themen, 100.000 Beiträge)', static function (): void {
    $db = MariaDbTestDatabase::create();
    $data = cdbBulkLoad($db);
    $pdo = $db->getPdo();
    $forum = $data['forums'][2];
    $now = $data['now'];
    $pdo->exec("UPDATE cms_community_posts SET status = 'pending' WHERE id MOD 97 = 3 AND is_first_post = 0");
    $pdo->exec("UPDATE cms_community_topics SET is_locked = 1, locked_until = TIMESTAMP('2036-01-01 00:00:00') + INTERVAL (id MOD 40000) SECOND WHERE id MOD 50 = 1");
    $pdo->exec("UPDATE cms_community_topics SET is_announcement = 1 WHERE id MOD 500 = 7 AND status = 'published'");
    $pdo->exec("INSERT INTO cms_community_topics (forum_id, user_id, title, slug, status, moved_to_topic_id, moved_expires_at, last_post_at, created_at, updated_at)
        SELECT forum_id, user_id, title, slug, 'moved', id, TIMESTAMP('2036-01-01 00:00:00') + INTERVAL (id MOD 40000) SECOND, last_post_at, created_at, updated_at FROM cms_community_topics WHERE id MOD 97 = 3 AND status = 'published'");
    $pdo->exec("INSERT INTO cms_community_moderation_log (actor_id, action, target_type, target_id, forum_id, reason, details_json, created_at)
        SELECT user_id, 'topic.lock', 'topic', id, forum_id, NULL, NULL, created_at FROM cms_community_topics");
    $pdo->exec("INSERT INTO cms_community_reports (target_type, target_id, forum_id, reporter_id, reason, status, notify_reporter, created_at)
        SELECT 'post', id, forum_id, user_id, 'spam', CASE WHEN id MOD 3 = 0 THEN 'open' ELSE 'valid' END, 1, created_at FROM cms_community_posts WHERE id MOD 20 = 0");
    $moderator = $data['users'][5];
    $group = $db->group('Moderation');
    $db->join($moderator, $group);
    $forums = new ForumRepository($db);
    // Viele Zuordnungen (40 Konten × 6 Foren, dazu eine Gruppe): sonst liest der Optimierer die winzige Tabelle über PRIMARY.
    foreach (array_slice($data['forums'], 0, 6) as $i => $f) {
        $rows = array_map(static fn (int $u): array => ['subject_type' => 'user', 'subject_id' => $u], $data['users']);
        if ($i % 2 === 1) {
            $rows[] = ['subject_type' => 'group', 'subject_id' => $group];
        }
        $forums->saveModerators($f, $rows);
    }
    for ($u = 0; $u < 40; $u++) {
        $db->execute('INSERT IGNORE INTO cms_community_user_stats (user_id, updated_at) VALUES (?, ?)', [$data['users'][$u], $now]);
    }
    $pdo->query('ANALYZE TABLE cms_community_topics, cms_community_posts, cms_community_moderation_log, cms_community_reports, cms_community_user_stats, cms_community_forum_moderators')->fetchAll();

    $topics = new TopicRepository($db);
    $posts = new PostRepository($db);
    $stats = new UserStatsRepository($db);
    $log = new ModerationLogRepository($db);
    $reports = new ReportRepository($db);
    $someTopic = (int) cdbScalar($db, "SELECT id FROM cms_community_topics WHERE forum_id = ? AND status = 'published' AND reply_count > 0 ORDER BY id LIMIT 1", [$forum]);
    $moved = (int) cdbScalar($db, "SELECT moved_to_topic_id FROM cms_community_topics WHERE status = 'moved' AND forum_id = ? LIMIT 1", [$forum]);
    $postIds = array_map('intval', array_column($db->execute('SELECT id FROM cms_community_posts WHERE topic_id = ? ORDER BY id', [$someTopic])->fetchAll(\PDO::FETCH_ASSOC), 'id'));

    $gates = [
        'Pin-Zählung (countPinned)' => [static fn () => $topics->countPinned($forum, $now), ['keys' => ['idx_community_topics_forum_list'], 'maxReads' => 60]],
        'Autoren eines Themas (publishedByAuthor)' => [static fn () => $posts->publishedByAuthor($someTopic), ['keys' => ['idx_community_posts_topic'], 'maxReads' => 20, 'temporary' => true, 'filesort' => true]],
        'Statistiksperre (lockMany, 40 Mitglieder)' => [static fn () => $stats->lockMany($data['users'], $now), ['keys' => ['PRIMARY'], 'maxReads' => 60]],
        'Warteschlange global (queue)' => [static fn () => $posts->queue(null, null, 20), ['keys' => ['idx_community_posts_queue'], 'maxReads' => 60]],
        'Warteschlange Zählung (queueSummary)' => [static fn () => $posts->queueSummary(null), ['keys' => ['idx_community_posts_queue'], 'maxReads' => 1100, 'maxScan' => PostRepository::QUEUE_COUNT_MAX + 10]],
        'Protokoll eines Forums (list)' => [static fn () => $log->list(['forum_ids' => [$forum]], null, 50), ['keys' => ['idx_community_moderation_log_forum'], 'maxReads' => 60]],
        'Protokoll global (list)' => [static fn () => $log->list([], null, 50), ['keys' => ['idx_community_moderation_log_time'], 'maxReads' => 60]],
        'Verweise auf ein Thema (stubIdsFor)' => [static fn () => $topics->stubIdsFor($forum, $moved), ['keys' => ['idx_community_topics_forum_list', 'idx_community_topics_moved'], 'maxReads' => 200]],
        'Verweise eines Forums (listMoved)' => [static fn () => $topics->listMoved($forum, $now, 20), ['keys' => ['idx_community_topics_forum_list', 'idx_community_topics_moved'], 'maxReads' => 400, 'filesort' => true]],
        'Ablauf Schließen (expiredIds lock)' => [static fn () => $topics->expiredIds('lock', $now, 500), ['keys' => ['idx_community_topics_locked'], 'maxReads' => 520]],
        'Ablauf Anheften (expiredIds pin)' => [static fn () => $topics->expiredIds('pin', $now, 500), ['keys' => ['idx_community_topics_pinned'], 'maxReads' => 520]],
        'Ablauf Verweise (expiredIds moved)' => [static fn () => $topics->expiredIds('moved', $now, 500), ['keys' => ['idx_community_topics_moved'], 'maxReads' => 520]],
        'Moderatoren (moderatorAssignments)' => [static fn () => $forums->moderatorAssignments($moderator, [$group]), ['keys' => ['idx_community_forum_moderators_subject'], 'maxReads' => 20, 'filesort' => true]],
        'Meldungen eines Themas (reassignTopic, Auswahl)' => [static fn () => $reports->reassignTopic($someTopic, $data['forums'][3]), ['keys' => ['idx_community_posts_topic', 'idx_community_reports_target'], 'maxReads' => 60, 'filesort' => true, 'temporary' => true]],
        'Ankündigungen (listAnnouncements)' => [static fn () => $topics->listAnnouncements($data['forums'], 10), ['keys' => ['idx_community_topics_announce'], 'maxReads' => 60]],
    ];
    foreach ($gates as $label => [$call, $rule]) {
        $measure = cdbMeasure($db, $call);
        $problems = cdbPlanProblems($measure, $rule);
        if (!empty($rule['temporary'])) {
            // GROUP BY je Autor bzw. ORDER BY r.id über den Join: temporäre Tabelle über die Beiträge eines Themas (wenige Zeilen, Entwurf moderation §2.1)
            $problems = array_values(array_filter($problems, static fn (string $p): bool => !str_starts_with($p, 'temporäre Tabelle')));
        }
        $plan = $measure['plans'][0]['rows'][0] ?? [];
        T::info($label . ': ' . $measure['reads'] . ' Lesezugriffe; ' . ($plan['type'] ?? '-') . ':' . ($plan['key'] ?? 'NULL') . ':' . ($plan['Extra'] ?? ''));
        T::same([], $problems, $label . ': Index ' . implode('|', $rule['keys']) . ', kein Tabellenscan');
        $used = [];
        foreach ($measure['plans'] as $p) {
            foreach ($p['rows'] as $r) {
                $used[] = (string) ($r['key'] ?? '');
            }
        }
        T::check(array_intersect($rule['keys'], $used) !== [], $label . ': der Plan nutzt ' . implode('|', $rule['keys']) . ' (auch in abgeleiteten Tabellen)', implode(',', $used));
    }

    // Meldungen der verschobenen Beiträge: UPDATE über idx_community_reports_target (kein Vollscan von PRIMARY)
    $db->captureParams = true;
    $db->captured = [];
    $reports->reassignPosts($postIds, $data['forums'][4]);
    $db->captureParams = false;
    foreach ($db->captured as $statement) {
        $stmt = $pdo->prepare('EXPLAIN ' . $statement['sql']);
        cdbBind($stmt, $statement['params']);
        $stmt->execute();
        $row = $stmt->fetchAll(\PDO::FETCH_ASSOC)[0] ?? [];
        T::same(['range', 'idx_community_reports_target'], [$row['type'] ?? null, $row['key'] ?? null], 'reassignPosts: UPDATE über idx_community_reports_target (Bereich), kein Vollscan');
    }
    $db->dispose();
});
