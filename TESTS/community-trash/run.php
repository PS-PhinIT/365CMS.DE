<?php
/**
 * Community-Papierkorb (PR #5): `TopicTrashService` (auflisten, wiederherstellen, endgültig löschen, Fristablauf), `CommunityContentProvider`
 * (Anpassung an den `TrashProvider` des Core) und die Zählerbuchung beim Löschen (`TopicService::delete()` über `CounterBook`, Semantik S1).
 * DB-frei (SQLite-Double). Die Dateien gelöschter Anhänge und der Suchindex sind Closures (hier aufgezeichnet).
 *
 *   php TESTS/community-trash/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-trash-' . getmypid() . '/');
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

use CMS\Services\Community\CommunityContentProvider;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\PostService;
use CMS\Services\Community\Service\TopicService;
use CMS\Services\Community\Service\TopicTrashService;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\Settings;
use CMS\Services\ContentTypes\TrashItem;
use CMS\Services\ContentTypes\TrashResult;

const NOW = 1800000000; // 2027-01-15 08:00:00 UTC

function utc(int $offset = 0): string
{
    return gmdate('Y-m-d H:i:s', NOW + $offset);
}

/**
 * Testumgebung: Dienste für Anlegen und Löschen (Rechte: alles erlaubt, auch Moderation), Papierkorbdienst mit aufgezeichneten
 * Hooks, Dateien und Index-Aufrufen.
 */
final class TrashWorld
{
    public CommunityTestDatabase $db;
    public \ArrayObject $time;
    public TopicService $topics;
    public PostService $posts;
    public TopicTrashService $trash;
    /** @var list<array{hook: string, args: list<mixed>}> */
    public array $hooks = [];
    /** @var list<list<array<string, mixed>>> */
    public array $files = [];
    /** @var list<list<int>> */
    public array $unindexed = [];
    public bool $filesThrow = false;
    public bool $indexThrows = false;

    public function __construct()
    {
        $this->db = CommunityTestDatabase::create();
        $this->time = new \ArrayObject(['now' => NOW]);
        $clock = fn (): int => (int) $this->time['now'];
        $can = static fn (?int $userId, string $action, array $context): AccessDecision => AccessDecision::allow();
        $dispatch = function (string $hook, mixed ...$args): void {
            $this->hooks[] = ['hook' => $hook, 'args' => $args];
        };
        $settings = Settings::fromArray([]);
        $this->topics = new TopicService($this->db, $settings, $clock, $can, $dispatch);
        $this->posts = new PostService($this->db, $settings, $clock, $can, $dispatch);
        $this->trash = new TopicTrashService(
            $this->db,
            $clock,
            $dispatch,
            function (array $rows): void {
                $this->files[] = $rows;
                if ($this->filesThrow) {
                    throw new \RuntimeException('Testausnahme beim Entfernen der Dateien');
                }
            },
            function (array $postIds): void {
                $this->unindexed[] = $postIds;
                if ($this->indexThrows) {
                    throw new \RuntimeException('Testausnahme im Suchindex');
                }
            }
        );
    }

    public function at(int $offset): void
    {
        $this->time['now'] = NOW + $offset;
    }

    /**
     * Thema mit Antworten über die Dienste (Zähler stimmen, wie in der Praxis).
     *
     * @param list<int> $replyAuthors
     * @return array{0: int, 1: list<int>} Thema-ID und Beitrags-IDs (erster Beitrag zuerst)
     */
    public function seed(int $forum, int $starter, array $replyAuthors = [], string $title = 'Thema', int $start = 0): array
    {
        $this->at($start);
        $created = $this->topics->create($starter, $forum, $title, 'Themenstart');
        if (empty($created['success'])) {
            throw new \RuntimeException('Thema nicht angelegt: ' . json_encode($created));
        }
        $topic = (int) $created['topic_id'];
        $ids = [(int) $created['post_id']];
        foreach ($replyAuthors as $i => $author) {
            $this->at($start + ($i + 1) * 60);
            $reply = $this->posts->reply($author, $topic, 'Antwort ' . ($i + 1));
            if (empty($reply['success'])) {
                throw new \RuntimeException('Antwort nicht angelegt: ' . json_encode($reply));
            }
            $ids[] = (int) $reply['post_id'];
        }

        return [$topic, $ids];
    }

    /** Zustand aller Zähler zum Vergleichen. */
    public function counters(): array
    {
        $forums = [];
        foreach ($this->db->rows('community_forums', [], 'id') as $f) {
            $forums[(int) $f['id']] = [(int) $f['topic_count'], (int) $f['post_count'], $f['last_topic_id'] === null ? null : (int) $f['last_topic_id'], $f['last_post_id'] === null ? null : (int) $f['last_post_id']];
        }
        $users = [];
        foreach ($this->db->rows('community_user_stats', [], 'user_id') as $s) {
            $users[(int) $s['user_id']] = [(int) $s['topic_count'], (int) $s['post_count'], (int) $s['reactions_received'], (int) $s['reactions_given']];
        }
        $topics = [];
        foreach ($this->db->rows('community_topics', [], 'id') as $t) {
            $topics[(int) $t['id']] = [(string) $t['status'], (int) $t['reply_count'], $t['last_post_id'] === null ? null : (int) $t['last_post_id']];
        }

        return ['forums' => $forums, 'users' => $users, 'topics' => $topics];
    }

    /** Neuberechnung (S1): Forum und Mitglieder zählen nur veröffentlichte Beiträge in veröffentlichten Themen. @return list<string> */
    public function audit(): array
    {
        $bad = [];
        foreach ($this->db->rows('community_forums') as $f) {
            $id = (int) $f['id'];
            $topics = $this->db->count('community_topics', ['forum_id' => $id, 'status' => 'published']);
            $posts = (int) $this->db->execute("SELECT COUNT(*) FROM cms_community_posts p JOIN cms_community_topics t ON t.id = p.topic_id WHERE t.forum_id = ? AND t.status = 'published' AND p.status = 'published'", [$id])->fetchColumn();
            if ([(int) $f['topic_count'], (int) $f['post_count']] !== [$topics, $posts]) {
                $bad[] = "Forum {$id}: {$f['topic_count']}/{$f['post_count']} statt {$topics}/{$posts}";
            }
        }
        foreach ($this->db->rows('community_user_stats') as $s) {
            $id = (int) $s['user_id'];
            $topics = $this->db->count('community_topics', ['user_id' => $id, 'status' => 'published']);
            $posts = (int) $this->db->execute("SELECT COUNT(*) FROM cms_community_posts p JOIN cms_community_topics t ON t.id = p.topic_id WHERE p.user_id = ? AND p.status = 'published' AND t.status = 'published'", [$id])->fetchColumn();
            if ([(int) $s['topic_count'], (int) $s['post_count']] !== [$topics, $posts]) {
                $bad[] = "Mitglied {$id}: {$s['topic_count']}/{$s['post_count']} statt {$topics}/{$posts}";
            }
        }

        return $bad;
    }

    public function provider(): CommunityContentProvider
    {
        return new CommunityContentProvider(fn (): TopicTrashService => $this->trash);
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): void
    {
        $this->db->execute('INSERT INTO cms_' . $table . ' (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
    }

    public function stamp(int $topic, string $trashedAt, ?int $by = null): void
    {
        $this->db->execute('UPDATE cms_community_topics SET trashed_at = ?, trashed_by = ? WHERE id = ?', [$trashedAt, $by, $topic]);
    }
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Löschen: Zeitstempel und Zähler
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Löschen: Zeitstempel und Zähler nach Semantik S1 (keine Drift bei Antwortautoren)', static function (): void {
    $w = new TrashWorld();
    [$a, $b, $c] = [$w->db->user(), $w->db->user(), $w->db->user()];
    $forum = $w->db->forum();
    [$topic] = $w->seed($forum, $a, [$b, $c, $b]);
    T::same([], $w->audit(), 'Ausgangslage: Zähler stimmen');
    T::same([1, 4], [$w->counters()['forums'][$forum][0], $w->counters()['forums'][$forum][1]], 'Forum zählt ein Thema und vier Beiträge');
    $w->at(900);
    $result = $w->topics->delete($a, $topic);
    T::same(true, $result['success'] ?? null, 'Löschen gelingt');
    $row = $w->db->row('community_topics', ['id' => $topic]);
    T::same(['deleted', 'published', utc(900), $a], [$row['status'], $row['previous_status'], $row['trashed_at'], (int) $row['trashed_by']], 'Status, vorheriger Status, Zeitpunkt und Handelnder stehen im Thema');
    T::same([], $w->audit(), 'Zähler stimmen nach dem Löschen (Forum, Starter und beide Antwortautoren)');
    T::same([0, 0, 0], [$w->counters()['users'][$b][1], $w->counters()['users'][$c][1], $w->counters()['users'][$a][0]], 'B und C verlieren ihre Antworten, A sein Thema');
    $again = $w->topics->delete($a, $topic);
    T::same(true, $again['already_deleted'] ?? null, 'zweites Löschen ist wirkungslos');
    T::same(utc(900), $w->db->row('community_topics', ['id' => $topic])['trashed_at'], 'der Zeitstempel bleibt der erste');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Wiederherstellen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Wiederherstellen: veröffentlichtes Thema – Zähler wie vor dem Löschen (auch wenn es das jüngste Thema des Forums war)', static function (): void {
    foreach (['älteres Thema' => 0, 'jüngstes Thema' => 1] as $label => $index) {
        $w = new TrashWorld();
        [$a, $b, $c] = [$w->db->user(), $w->db->user(), $w->db->user()];
        $forum = $w->db->forum();
        [$older] = $w->seed($forum, $a, [$b, $c], 'Älteres Thema');
        [$newer] = $w->seed($forum, $b, [$a], 'Jüngeres Thema', 600);
        $topic = [$older, $newer][$index];
        $actor = [$a, $b][$index];
        $w->at(700);
        T::same([], $w->audit(), $label . ': Ausgangslage stimmt');
        $before = $w->counters();
        $versionBefore = (int) $w->db->row('community_topics', ['id' => $topic])['version'];
        $w->at(1000);
        $w->topics->delete($actor, $topic);
        T::check($w->counters() !== $before, $label . ': Vorbedingung: Löschen hat Zähler geändert');
        T::same([], $w->audit(), $label . ': Zähler stimmen im Papierkorb');
        $w->at(2000);
        $w->hooks = [];
        $result = $w->trash->restore($topic, $actor);
        T::same([true, $topic, $forum, 'published'], [$result['success'] ?? null, $result['topic_id'] ?? null, $result['forum_id'] ?? null, $result['status'] ?? null], $label . ': Ergebnis');
        T::same($before, $w->counters(), $label . ': alle Zähler (Forum, Mitglieder, Themen, letzter Beitrag) sind wie vor dem Löschen');
        T::same([], $w->audit(), $label . ': Neuberechnung stimmt');
        $row = $w->db->row('community_topics', ['id' => $topic]);
        T::same(['published', null, null, null], [$row['status'], $row['previous_status'], $row['trashed_at'], $row['trashed_by']], $label . ': Papierkorbfelder sind leer');
        T::check((int) $row['version'] > $versionBefore + 1, $label . ': version steigt (Listen-Cache)');
        $log = $w->db->rows('community_moderation_log', ['target_id' => $topic, 'action' => 'topic.restore']);
        T::same([1, $actor, 'topic', $forum, '{"from":"deleted","to":"published"}'], [count($log), (int) ($log[0]['actor_id'] ?? 0), $log[0]['target_type'] ?? null, (int) ($log[0]['forum_id'] ?? 0), $log[0]['details_json'] ?? null], $label . ': Protokoll topic.restore mit Handelndem und Übergang');
        T::same([[Events::TOPIC_RESTORED, [$topic, $forum, $actor, 'published']]], array_map(static fn (array $h): array => [$h['hook'], $h['args']], $w->hooks), $label . ': Hook cms_community_topic_restored (Thema, Forum, Handelnder, Status)');
    }
});

T::run('Wiederherstellen: ausgeblendetes und wartendes Thema – Zähler bleiben, Status kehrt zurück', static function (): void {
    $w = new TrashWorld();
    [$a, $b] = [$w->db->user(), $w->db->user()];
    $forum = $w->db->forum();
    foreach (['hidden', 'pending'] as $status) {
        $topic = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => $status]);
        $first = $w->db->post(['topic' => $topic, 'user' => $a, 'first' => true, 'status' => $status === 'pending' ? 'pending' : 'published']);
        $w->db->execute('UPDATE cms_community_topics SET first_post_id = ?, last_post_id = ? WHERE id = ?', [$first, $status === 'pending' ? null : $first, $topic]);
        $w->at(100);
        $w->topics->delete($b, $topic);
        T::same('deleted', $w->db->row('community_topics', ['id' => $topic])['status'], $status . ': gelöscht');
        T::same($status, $w->db->row('community_topics', ['id' => $topic])['previous_status'], $status . ': previous_status gemerkt');
        $counters = $w->counters()['forums'];
        $result = $w->trash->restore($topic, $b);
        T::same([true, $status], [$result['success'] ?? null, $result['status'] ?? null], $status . ': wiederhergestellt in den alten Status');
        T::same([$status, null, null], [$w->db->row('community_topics', ['id' => $topic])['status'], $w->db->row('community_topics', ['id' => $topic])['previous_status'], $w->db->row('community_topics', ['id' => $topic])['trashed_at']], $status . ': die Zeile trägt den alten Status, keine Papierkorbfelder');
        T::same($counters, $w->counters()['forums'], $status . ': kein Forumszähler ändert sich');
        T::same(0, $w->db->count('community_user_stats', ['topic_count' => 1]), $status . ': kein Mitgliederzähler zählt das Thema');
    }
});

T::run('Wiederherstellen: abgelehntes Thema, falscher Zustand, fehlendes Forum, ungültige Eingabe', static function (): void {
    $w = new TrashWorld();
    [$a, $b] = [$w->db->user(), $w->db->user()];
    $forum = $w->db->forum();
    $topic = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => 'deleted']);
    $first = $w->db->post(['topic' => $topic, 'user' => $a, 'first' => true, 'status' => 'rejected']);
    $w->db->execute("UPDATE cms_community_topics SET first_post_id = ?, previous_status = 'pending', trashed_at = ? WHERE id = ?", [$first, utc(), $topic]);
    $before = [$w->counters(), $w->db->rows('community_moderation_log')];
    $rejected = $w->trash->restore($topic, $b);
    T::same([false, 'invalid_state', 'rejected'], [$rejected['success'] ?? null, $rejected['error'] ?? null, $rejected['reason'] ?? null], 'abgelehnt: invalid_state mit Grund rejected');
    T::same($before, [$w->counters(), $w->db->rows('community_moderation_log')], 'abgelehnt: nichts geschrieben');

    $published = $w->db->topic(['forum' => $forum, 'user' => $a]);
    T::same(['success' => false, 'error' => 'invalid_state'], $w->trash->restore($published, $b), 'nicht im Papierkorb: invalid_state');
    T::same(['success' => false, 'error' => 'topic_not_found'], $w->trash->restore(9999, $b), 'unbekanntes Thema');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->trash->restore(0, $b), 'ID 0');

    $other = $w->db->forum();
    $orphan = $w->db->topic(['forum' => $other, 'user' => $a, 'status' => 'deleted']);
    $w->db->execute("UPDATE cms_community_topics SET previous_status = 'published', trashed_at = ? WHERE id = ?", [utc(), $orphan]);
    $w->db->execute('DELETE FROM cms_community_forums WHERE id = ?', [$other]);
    T::same(['success' => false, 'error' => 'forum_not_found'], $w->trash->restore($orphan, $b), 'Forum gelöscht: forum_not_found');
    T::same('deleted', $w->db->row('community_topics', ['id' => $orphan])['status'], 'Forum gelöscht: das Thema bleibt im Papierkorb');

    $unknown = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => 'deleted']);
    $w->db->execute("UPDATE cms_community_topics SET previous_status = 'trash' WHERE id = ?", [$unknown]);
    T::same(['success' => false, 'error' => 'invalid_state'], $w->trash->restore($unknown, $b), 'unbekannter vorheriger Status: invalid_state');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Liste
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Liste: Reihenfolge, Seiten ohne OFFSET, Suche, Namen, abgelehnte Themen', static function (): void {
    $w = new TrashWorld();
    $admin = $w->db->user(['display_name' => 'Anna Admin']);
    $plain = $w->db->user(['display_name' => '', 'username' => 'bob']);
    $forum = $w->db->forum(['title' => 'Allgemein']);
    $ids = [];
    foreach ([['Alpha Thema', 10, $admin], ['Rabatt 100% sicher', 20, $plain], ['Gamma_eins', 30, null], ['Delta Thema', 40, $admin]] as [$title, $day, $by]) {
        $topic = $w->db->topic(['forum' => $forum, 'user' => $admin, 'title' => $title, 'status' => 'deleted']);
        $w->db->execute("UPDATE cms_community_topics SET previous_status = 'published', reply_count = 2 WHERE id = ?", [$topic]);
        $w->stamp($topic, gmdate('Y-m-d H:i:s', NOW - $day * 86400), $by);
        $ids[$title] = $topic;
    }
    $unstamped = $w->db->topic(['forum' => $forum, 'user' => $admin, 'title' => 'Ohne Zeitstempel', 'status' => 'deleted']);
    $w->db->topic(['forum' => $forum, 'user' => $admin, 'title' => 'Veröffentlicht Thema']);

    T::same(5, $w->trash->count(), 'count: fünf gelöschte Themen (das veröffentlichte nicht)');
    $all = $w->trash->list(0, 10);
    T::same([$ids['Alpha Thema'], $ids['Rabatt 100% sicher'], $ids['Gamma_eins'], $ids['Delta Thema'], $unstamped], array_column($all, 'id'), 'zuletzt gelöscht zuerst, ohne Zeitstempel zuletzt');
    T::same([$ids['Rabatt 100% sicher'], $ids['Gamma_eins']], array_column($w->trash->list(1, 2), 'id'), 'Seite: Versatz 1, zwei Einträge');
    T::same([], $w->trash->list(10, 5), 'Versatz hinter dem Ende: leer');
    T::same(500, TopicTrashService::PAGE_MAX, 'größte Seite 500');
    $first = $all[0];
    T::same(['Alpha Thema', 'Allgemein', 'published', 'Anna Admin', 2, false, true], [$first['title'], $first['forum_title'], $first['previous_status'], $first['trashed_by_name'], $first['reply_count'], $first['rejected'], $first['restorable']], 'Zeile: Titel, Forum, vorheriger Status, Name, Antworten, Flags');
    T::same('bob', $all[1]['trashed_by_name'], 'ohne Anzeigenamen gilt der Benutzername');
    T::same(['', null], [$all[2]['trashed_by_name'], $all[2]['trashed_by']], 'ohne Handelnden leerer Name');
    T::same([$ids['Rabatt 100% sicher']], array_column($w->trash->list(0, 10, '100%'), 'id'), 'Suche: % ist ein Zeichen, kein Platzhalter');
    T::same([$ids['Gamma_eins']], array_column($w->trash->list(0, 10, 'a_e'), 'id'), 'Suche: _ ist ein Zeichen, kein Platzhalter');
    T::same([[], []], [array_column($w->trash->list(0, 10, 'Alp%Thema'), 'id'), array_column($w->trash->list(0, 10, 'Gam_a'), 'id')], 'Suche: auch in der Mitte sind % und _ keine Platzhalter');
    T::same(1, $w->trash->count('Alpha'), 'count mit Suche nach einem Titelteil');
    T::same(5, $w->trash->count('thema-'), 'die Suche trifft auch die Adresse (alle fünf gelöschten Themen, nicht das veröffentlichte)') ;
    T::same(0, $w->trash->count('Veröffentlicht'), 'ein veröffentlichtes Thema kommt nie vor');

    // Abgelehnt: Thema deleted aus pending, erster Beitrag rejected.
    $rejected = $w->db->topic(['forum' => $forum, 'user' => $admin, 'title' => 'Abgelehntes', 'status' => 'deleted']);
    $post = $w->db->post(['topic' => $rejected, 'user' => $admin, 'first' => true, 'status' => 'rejected']);
    $w->db->execute("UPDATE cms_community_topics SET first_post_id = ?, previous_status = 'pending' WHERE id = ?", [$post, $rejected]);
    $w->stamp($rejected, utc(), $admin);
    $row = array_values(array_filter($w->trash->list(0, 10), static fn (array $r): bool => $r['id'] === $rejected))[0];
    T::same([true, false, 'pending'], [$row['rejected'], $row['restorable'], $row['previous_status']], 'abgelehnt: rejected, nicht wiederherstellbar');
});

T::run('Fristablauf: expiredIds stempelt fehlende Zeitpunkte und liefert die Älteren', static function (): void {
    $w = new TrashWorld();
    $a = $w->db->user();
    $forum = $w->db->forum();
    $old = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => 'deleted']);
    $young = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => 'deleted']);
    $legacy = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => 'deleted']);
    $live = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => 'published']);
    $w->stamp($old, utc(-40 * 86400));
    $w->stamp($young, utc(-5 * 86400));
    $w->stamp($live, utc(-90 * 86400));
    T::same([$old], $w->trash->expiredIds(utc(-30 * 86400), 10), 'nur das gelöschte Thema vor der Grenze (weder jung noch veröffentlicht noch ohne Zeitstempel)');
    T::same(utc(), $w->db->row('community_topics', ['id' => $legacy])['trashed_at'], 'ohne Zeitstempel: bekommt jetzt den aktuellen Zeitpunkt');
    T::same([$old], $w->trash->expiredIds(utc(-30 * 86400), 10), 'zweiter Lauf: unverändert');
    T::same([$old, $young, $legacy], $w->trash->expiredIds(utc(86400), 10), 'spätere Grenze: alle drei, älteste zuerst');
    T::same([$old], $w->trash->expiredIds(utc(86400), 1), 'Limit 1');
    $w->stamp($young, utc(-40 * 86400));
    T::same([$old, $young], $w->trash->expiredIds(utc(-30 * 86400), 10), 'gleicher Zeitpunkt: nach ID geordnet');
    T::same([], $w->trash->expiredIds(utc(-40 * 86400), 10), 'Grenze ist ausschließend (trashed_at < Grenze)');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Endgültig löschen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Thema mit allem, was dranhängen kann, und ein zweites Thema mit denselben Arten Daten (muss unberührt bleiben).
 *
 * @return array<string, mixed>
 */
function fullTopic(TrashWorld $w, int $forum, int $a, int $b, int $c, string $title): array
{
    [$topic, $posts] = $w->seed($forum, $a, [$b, $c], $title);
    $db = $w->db;
    $att1 = $db->attachment(['post' => $posts[0], 'user' => $a, 'storage_path' => '2027/01/' . bin2hex(random_bytes(4)) . '.txt']);
    $att2 = $db->attachment(['post' => $posts[2], 'user' => $c, 'extension' => 'png', 'preview' => true, 'storage_path' => '2027/01/' . bin2hex(random_bytes(4)) . '.png']);
    // Reaktionen: B und C mögen den ersten Beitrag (A), A mag die Antwort von C; Zähler wie im Betrieb.
    $db->reaction(['post' => $posts[0], 'user' => $b]);
    $db->reaction(['post' => $posts[0], 'user' => $c]);
    $db->reaction(['post' => $posts[2], 'user' => $a]);
    $db->execute('UPDATE cms_community_user_stats SET reactions_received = reactions_received + 2, reactions_given = reactions_given + 1 WHERE user_id = ?', [$a]);
    $db->execute('UPDATE cms_community_user_stats SET reactions_given = reactions_given + 1 WHERE user_id = ?', [$b]);
    $db->execute('UPDATE cms_community_user_stats SET reactions_received = reactions_received + 1, reactions_given = reactions_given + 1 WHERE user_id = ?', [$c]);
    // Meldungen: offen auf einem Beitrag, erledigt auf dem Thema, offen auf einem fremden Beitrag
    $reportPost = $db->report(['target' => $posts[1], 'forum' => $forum, 'status' => 'open']);
    $reportTopic = $db->report(['target' => $topic, 'target_type' => 'topic', 'forum' => $forum, 'status' => 'valid', 'handled_at' => utc()]);
    // Lesemarken, Abos, Benachrichtigung, Umfrage, Verweis-Thema
    $db->execute('INSERT IGNORE INTO cms_community_read_states (user_id, topic_id, last_read_post_id, updated_at) VALUES (?, ?, ?, ?)', [$b, $topic, $posts[1], utc()]);
    $db->execute('INSERT IGNORE INTO cms_community_read_states (user_id, topic_id, last_read_post_id, updated_at) VALUES (?, ?, ?, ?)', [$c, $topic, $posts[2], utc()]);
    $db->subscription(['user' => $b, 'target' => $topic]);
    $db->subscription(['user' => $c, 'target' => $topic]);
    $db->subscription(['user' => $b, 'target' => $forum, 'target_type' => 'forum']);
    $queue = $db->queueJob(['topic' => $topic, 'post' => $posts[1], 'actor' => $b]);
    $w->insert('community_polls', ['topic_id' => $topic, 'question' => 'Frage?', 'max_choices' => 1, 'results_visibility' => 'always', 'allow_change' => 0, 'is_public_voters' => 0, 'voter_count' => 1, 'created_at' => utc()]);
    $poll = $db->lastInsertId();
    $w->insert('community_poll_options', ['poll_id' => $poll, 'label' => 'Ja', 'vote_count' => 1, 'sort_order' => 1]);
    $option = $db->lastInsertId();
    $w->insert('community_poll_votes', ['poll_id' => $poll, 'option_id' => $option, 'user_id' => $b, 'created_at' => utc()]);
    $otherForum = $db->forum();
    $stub = $db->topic(['forum' => $otherForum, 'user' => $a, 'status' => 'moved']);
    $db->execute('UPDATE cms_community_topics SET moved_to_topic_id = ? WHERE id = ?', [$topic, $stub]);

    return compact('topic', 'posts', 'att1', 'att2', 'reportPost', 'reportTopic', 'queue', 'poll', 'option', 'stub', 'otherForum');
}

/** Zeilen je Tabelle, die zu einem Thema gehören. @return array<string, int> */
function rowsOf(TrashWorld $w, array $t): array
{
    $db = $w->db;
    $in = implode(', ', $t['posts']);

    return [
        'topics' => $db->count('community_topics', ['id' => $t['topic']]),
        'posts' => $db->count('community_posts', ['topic_id' => $t['topic']]),
        'revisions' => (int) $db->execute("SELECT COUNT(*) FROM cms_community_post_revisions WHERE post_id IN ({$in})")->fetchColumn(),
        'attachments' => (int) $db->execute("SELECT COUNT(*) FROM cms_community_attachments WHERE post_id IN ({$in})")->fetchColumn(),
        'reactions' => (int) $db->execute("SELECT COUNT(*) FROM cms_community_reactions WHERE post_id IN ({$in})")->fetchColumn(),
        'reports' => (int) $db->execute("SELECT COUNT(*) FROM cms_community_reports WHERE (target_type = 'topic' AND target_id = ?) OR (target_type = 'post' AND target_id IN ({$in}))", [$t['topic']])->fetchColumn(),
        'read_states' => $db->count('community_read_states', ['topic_id' => $t['topic']]),
        'subscriptions' => (int) $db->execute("SELECT COUNT(*) FROM cms_community_subscriptions WHERE target_type = 'topic' AND target_id = ?", [$t['topic']])->fetchColumn(),
        'notify_queue' => $db->count('community_notify_queue', ['topic_id' => $t['topic']]),
        'polls' => $db->count('community_polls', ['topic_id' => $t['topic']]),
        'poll_options' => $db->count('community_poll_options', ['poll_id' => $t['poll']]),
        'poll_votes' => $db->count('community_poll_votes', ['poll_id' => $t['poll']]),
        'stubs' => $db->count('community_topics', ['moved_to_topic_id' => $t['topic'], 'status' => 'moved']),
    ];
}

T::run('Endgültig löschen: Thema samt Beiträgen, Anhängen, Reaktionen, Meldungen und allem Übrigen; Fremdbestand bleibt', static function (): void {
    $w = new TrashWorld();
    [$a, $b, $c] = [$w->db->user(), $w->db->user(), $w->db->user()];
    $forum = $w->db->forum();
    $victim = fullTopic($w, $forum, $a, $b, $c, 'Zu löschen');
    $keep = fullTopic($w, $forum, $b, $a, $c, 'Bleibt');
    $w->at(1000);
    $w->topics->delete($a, $victim['topic']);
    $w->hooks = [];
    T::same(1, $w->trash->openReports($victim['topic']), 'eine offene Meldung (die erledigte zählt nicht)');
    $countersBefore = $w->counters();
    $keepBefore = rowsOf($w, $keep);
    $present = rowsOf($w, $victim);
    T::check($present['read_states'] >= 2, 'Vorbedingung: Lesemarken sind da');
    T::same(['topics' => 1, 'posts' => 3, 'revisions' => 0, 'attachments' => 2, 'reactions' => 3, 'reports' => 2, 'subscriptions' => 2, 'notify_queue' => 1, 'polls' => 1, 'poll_options' => 1, 'poll_votes' => 1, 'stubs' => 1], array_diff_key($present, ['read_states' => 0]), 'Vorbedingung: alles ist da');
    $w->db->execute('INSERT INTO cms_community_post_revisions (post_id, title, body, format, edited_by, edit_reason, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?)', [$victim['posts'][1], 'alt', 'markdown', $b, '', utc()]);

    $w->at(2000);
    $result = $w->trash->purge($victim['topic'], $a);
    T::same([true, $victim['topic'], $forum, 'Zu löschen'], [$result['success'] ?? null, $result['topic_id'] ?? null, $result['forum_id'] ?? null, $result['title'] ?? null], 'Ergebnis');
    T::same(array_fill_keys(array_keys(rowsOf($w, $keep)), 0), rowsOf($w, $victim), 'alle Zeilen des Themas sind weg');
    T::same($keepBefore, rowsOf($w, $keep), 'das zweite Thema ist unberührt');
    T::same(2, $w->db->count('community_subscriptions', ['target_type' => 'forum', 'target_id' => $forum]), 'die Forum-Abos (je Thema eines) bleiben');
    T::same(0, $w->db->count('community_topics', ['id' => $victim['stub']]), 'das Verweis-Thema in einem anderen Forum ist weg');
    T::same($result['removed']['posts'] ?? null, 3, 'removed: drei Beiträge');
    T::same([2, 3, 2, 1], [$result['removed']['attachments'] ?? null, $result['removed']['reactions'] ?? null, $result['removed']['reports'] ?? null, $result['removed']['revisions'] ?? null], 'removed: Anhänge, Reaktionen, Meldungen, Revision');
    T::same($countersBefore['forums'], $w->counters()['forums'], 'Forumszähler bleiben (das Löschen hat sie abgezogen)');
    T::same([], $w->audit(), 'Neuberechnung stimmt');

    // Reaktionszähler der Mitglieder sinken: A erhielt 2 (auf Beitrag 1), gab 1 (auf C); B gab 1 (auf A); C erhielt 1 (von A), gab 1 (auf A).
    $stats = $w->counters()['users'];
    T::same($countersBefore['users'][$a][2] - 2, $stats[$a][2], 'A: reactions_received −2');
    T::same($countersBefore['users'][$c][2] - 1, $stats[$c][2], 'C: reactions_received −1');
    T::same([$countersBefore['users'][$a][3] - 1, $countersBefore['users'][$b][3] - 1, $countersBefore['users'][$c][3] - 1], [$stats[$a][3], $stats[$b][3], $stats[$c][3]], 'reactions_given sinkt je Reagierendem um die Zahl seiner Reaktionen im Thema');

    T::same(1, count($w->files), 'Dateien: ein Aufruf nach dem Commit');
    T::same([$victim['att1'], $victim['att2']], array_column($w->files[0] ?? [], 'id'), 'Dateien: beide Anhänge mit Pfad');
    T::same([1, 0], [(int) ($w->files[0][1]['has_preview'] ?? 0), (int) ($w->files[0][0]['has_preview'] ?? 1)], 'Dateien: Vorschau-Kennzeichen wird mitgegeben');
    T::same([$victim['posts']], $w->unindexed, 'Suchindex: alle Beiträge des Themas');
    $log = $w->db->rows('community_moderation_log', ['target_id' => $victim['topic'], 'action' => 'topic.delete']);
    T::same([1, $a, 'topic', $forum, '{"from":"deleted"}'], [count($log), (int) ($log[0]['actor_id'] ?? 0), $log[0]['target_type'] ?? null, (int) ($log[0]['forum_id'] ?? 0), $log[0]['details_json'] ?? null], 'Protokoll topic.delete');
    T::check(trim((string) ($log[0]['reason'] ?? '')) !== '', 'Protokoll: Grund nennt den Papierkorb');
    T::same([[Events::TOPIC_DELETED, [$victim['topic'], $forum, $a, 'purged']]], array_map(static fn (array $h): array => [$h['hook'], $h['args']], $w->hooks), 'Hook topic_deleted im Modus purged');
    T::same(['success' => false, 'error' => 'topic_not_found'], $w->trash->purge($victim['topic'], $a), 'zweiter Aufruf: topic_not_found');
});

T::run('Endgültig löschen: nur im Papierkorb, ungültige Eingaben, Fehler in Dateien und Index sind nur Warnungen', static function (): void {
    $w = new TrashWorld();
    [$a, $b] = [$w->db->user(), $w->db->user()];
    $forum = $w->db->forum();
    [$topic, $posts] = $w->seed($forum, $a, [$b]);
    $before = [$w->db->rows('community_topics'), $w->db->rows('community_posts')];
    T::same(['success' => false, 'error' => 'invalid_state'], $w->trash->purge($topic, $a), 'veröffentlichtes Thema: invalid_state');
    T::same($before, [$w->db->rows('community_topics'), $w->db->rows('community_posts')], 'nichts gelöscht');
    T::same(['success' => false, 'error' => 'topic_not_found'], $w->trash->purge(9999, $a), 'unbekannt');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->trash->purge(0, $a), 'ID 0');

    $w->db->attachment(['post' => $posts[1], 'user' => $b]);
    $w->topics->delete($a, $topic);
    $w->filesThrow = true;
    $w->indexThrows = true;
    $result = $w->trash->purge($topic, 0);
    T::same(true, $result['success'] ?? null, 'Fehler beim Entfernen der Dateien und im Index verhindern das Löschen nicht');
    T::same([0, 0], [$w->db->count('community_topics', ['id' => $topic]), $w->db->count('community_posts', ['topic_id' => $topic])], 'Thema und Beiträge sind weg');
    $log = $w->db->rows('community_moderation_log', ['target_id' => $topic, 'action' => 'topic.delete']);
    T::same([1, null], [count($log), $log[0]['actor_id'] ?? null], 'System (ID 0): ein Eintrag ohne Konto im Protokoll');
});

T::run('Endgültig löschen: große Themen in Stücken, Fortsetzung nach Abbruch', static function (): void {
    $w = new TrashWorld();
    $a = $w->db->user();
    $forum = $w->db->forum();
    $topic = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => 'deleted']);
    $w->db->execute("UPDATE cms_community_topics SET previous_status = 'published', trashed_at = ? WHERE id = ?", [utc(), $topic]);
    $ids = [];
    for ($i = 0; $i < 230; $i++) {
        $ids[] = $w->db->post(['topic' => $topic, 'user' => $a, 'first' => $i === 0]);
    }
    $other = $w->db->topic(['forum' => $forum, 'user' => $a]);
    $otherPost = $w->db->post(['topic' => $other, 'user' => $a, 'first' => true]);
    // Abbruch nach dem ersten Stück: die ersten 100 Beiträge sind schon weg, das Thema liegt noch im Papierkorb.
    $w->db->execute('DELETE FROM cms_community_posts WHERE id <= ?', [$ids[99]]);
    $result = $w->trash->purge($topic, $a);
    T::same(true, $result['success'] ?? null, 'Fortsetzung gelingt');
    T::same([130], [$result['removed']['posts'] ?? null], 'es werden die restlichen 130 Beiträge gelöscht');
    T::same([0, 1], [$w->db->count('community_posts', ['topic_id' => $topic]), $w->db->count('community_posts', ['id' => $otherPost])], 'kein Beitrag mehr, das andere Thema bleibt');
    T::same([100, 30], array_map('count', $w->unindexed), 'Suchindex: zwei Stücke zu 100 und 30');

    // Volles Thema: 3 Stücke
    $big = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => 'deleted']);
    $w->db->execute("UPDATE cms_community_topics SET previous_status = 'published', trashed_at = ? WHERE id = ?", [utc(), $big]);
    for ($i = 0; $i < 205; $i++) {
        $w->db->post(['topic' => $big, 'user' => $a, 'first' => $i === 0]);
    }
    $w->unindexed = [];
    $full = $w->trash->purge($big, $a);
    T::same([205, [100, 100, 5]], [$full['removed']['posts'] ?? null, array_map('count', $w->unindexed)], '205 Beiträge in drei Stücken');
});

T::run('Endgültig löschen: „letztes Thema“ des Forums zeigt nie auf ein gelöschtes Thema', static function (): void {
    $w = new TrashWorld();
    $a = $w->db->user();
    $forum = $w->db->forum();
    [$older] = $w->seed($forum, $a, [], 'Älter');
    $w->at(300);
    $topic = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => 'deleted']);
    $w->db->execute("UPDATE cms_community_topics SET previous_status = 'hidden', trashed_at = ? WHERE id = ?", [utc(), $topic]);
    // Ein Forum, das (durch einen früheren Fehler) noch auf das gelöschte Thema zeigt.
    $w->db->execute('UPDATE cms_community_forums SET last_topic_id = ? WHERE id = ?', [$topic, $forum]);
    $result = $w->trash->purge($topic, $a);
    T::same(true, $result['success'] ?? null, 'Löschen gelingt');
    T::same($older, (int) $w->db->row('community_forums', ['id' => $forum])['last_topic_id'], 'last_topic_id zeigt wieder auf das jüngste veröffentlichte Thema');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Provider
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Provider: Vertrag, Liste, Wiederherstellen, Warnungen, Bestätigung, Fristablauf', static function (): void {
    $w = new TrashWorld();
    [$a, $b] = [$w->db->user(['display_name' => 'Anna']), $w->db->user()];
    $forum = $w->db->forum(['title' => 'Technik']);
    $p = $w->provider();
    T::same(['community_topic', 'community.manage'], [$p->type(), $p->capability()], 'Typ und Capability (nur Administratoren)');
    T::check($p->label() !== '' && $p->label() !== 'community.trash.label', 'Bezeichnung ist übersetzt');
    T::check($p instanceof \CMS\Services\ContentTypes\TrashProvider && !($p instanceof \CMS\Services\Trash\TrashableProvider), 'TrashProvider, aber nicht TrashableProvider (das Löschen geschieht in der Community)');

    [$topic, $posts] = $w->seed($forum, $a, [$b], 'Frage zum Router');
    $w->at(500);
    $w->topics->delete($a, $topic);
    T::same(1, $p->countTrashed(), 'countTrashed');
    $items = $p->listTrashed(0, 10);
    T::same(1, count($items), 'listTrashed: ein Eintrag');
    $item = $items[0];
    T::check($item instanceof TrashItem, 'TrashItem');
    T::same(['community_topic', $topic, 'Frage zum Router', utc(500), $a, 'published', '/admin/community-topics'], [$item->type, $item->id, $item->title, $item->trashedAt, $item->trashedBy, $item->previousStatus, $item->editUrl], 'Eintrag: Typ, ID, Titel, Zeitpunkt, Handelnder, vorheriger Status, Adresse');
    T::same(['Technik', 1, false, true, 'Anna'], [$item->meta['forum'], $item->meta['replies'], $item->meta['rejected'], $item->meta['restorable'], $item->meta['trashed_by_name']], 'Eintrag: Forum, Antworten, Flags, Name');
    T::same(1, count($p->listTrashed(0, 10, 'Router')) + count($p->listTrashed(0, 10, 'Nichts')), 'Suche trifft genau einmal');

    $restored = $p->restore($topic, $a);
    T::same([true, TrashResult::CODE_OK], [$restored->ok, $restored->code], 'restore: Erfolg');
    T::check(str_contains($restored->message, 'Frage zum Router'), 'restore: Meldung nennt den Titel');
    T::same('published', $restored->data['status'] ?? null, 'restore: Status in den Daten');
    $again = $p->restore($topic, $a);
    T::same([false, TrashResult::CODE_INVALID_STATE], [$again->ok, $again->code], 'restore: nicht mehr im Papierkorb → invalid_state');
    T::same(TrashResult::CODE_NOT_FOUND, $p->restore(9999, $a)->code, 'restore: unbekannt → not_found');

    // Abgelehnt
    $rejected = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => 'deleted']);
    $first = $w->db->post(['topic' => $rejected, 'user' => $a, 'first' => true, 'status' => 'rejected']);
    $w->db->execute("UPDATE cms_community_topics SET first_post_id = ?, previous_status = 'pending', trashed_at = ? WHERE id = ?", [$first, utc(), $rejected]);
    $r = $p->restore($rejected, $a);
    T::same([false, TrashResult::CODE_INVALID_STATE], [$r->ok, $r->code], 'restore (abgelehnt): invalid_state');
    T::check(str_contains(strtolower($r->message), 'abgelehnt') || $r->message !== '', 'restore (abgelehnt): erklärende Meldung');

    // Warnungen und Bestätigung
    $w->at(600);
    $w->topics->delete($a, $topic);
    $w->db->report(['target' => $posts[1], 'forum' => $forum, 'status' => 'open']);
    T::same(1, count($p->deletionWarnings($topic)), 'deletionWarnings: eine Warnung bei offener Meldung');
    T::same([], $p->deletionWarnings($rejected), 'deletionWarnings: keine ohne offene Meldung');
    $unconfirmed = $p->deletePermanently($topic, 0, false);
    T::same([false, TrashResult::CODE_CONFIRMATION_REQUIRED], [$unconfirmed->ok, $unconfirmed->code], 'deletePermanently ohne Bestätigung: confirmation_required (so bleibt es beim Fristlauf liegen)');
    T::same(['Frage zum Router', 1], [$unconfirmed->data['title'] ?? null, count((array) ($unconfirmed->data['warnings'] ?? []))], 'Bestätigung: Titel und Warnungen in den Daten');
    T::same(1, $w->db->count('community_topics', ['id' => $topic]), 'nichts gelöscht');
    $confirmed = $p->deletePermanently($topic, $a, true);
    T::same([true, 0], [$confirmed->ok, $w->db->count('community_topics', ['id' => $topic])], 'deletePermanently mit Bestätigung: gelöscht');
    T::check(str_contains($confirmed->message, 'Frage zum Router'), 'Meldung nennt den Titel');
    $auto = $p->deletePermanently($rejected, 0, false);
    T::same([true, 0], [$auto->ok, $w->db->count('community_topics', ['id' => $rejected])], 'ohne Warnung löscht auch der Fristlauf (ohne Bestätigung)');
    T::same(TrashResult::CODE_NOT_FOUND, $p->deletePermanently(9999, $a, true)->code, 'deletePermanently: unbekannt → not_found');
    $live = $w->db->topic(['forum' => $forum, 'user' => $a]);
    T::same(TrashResult::CODE_INVALID_STATE, $p->deletePermanently($live, $a, true)->code, 'deletePermanently: nicht im Papierkorb → invalid_state');

    // Fristablauf: die Grenze kommt in lokaler Zeit (Europe/Berlin), gespeichert wird UTC.
    $expiring = $w->db->topic(['forum' => $forum, 'user' => $a, 'status' => 'deleted']);
    $w->db->execute("UPDATE cms_community_topics SET previous_status = 'published', trashed_at = '2027-01-10 12:00:00' WHERE id = ?", [$expiring]);
    $berlin = new \DateTimeZone('Europe/Berlin');
    T::same([], $p->expiredIds(new \DateTimeImmutable('2027-01-10 13:00:00', $berlin), 10), 'Grenze 13:00 Berlin = 12:00 UTC: ausschließend, noch nicht abgelaufen');
    T::same([$expiring], $p->expiredIds(new \DateTimeImmutable('2027-01-10 13:00:01', $berlin), 10), 'Grenze 13:00:01 Berlin = 12:00:01 UTC: abgelaufen');
    T::same([$expiring], $p->expiredIds(new \DateTimeImmutable('2027-01-10 12:00:01', new \DateTimeZone('UTC')), 10), 'dieselbe Grenze in UTC');
});

T::run('Provider: Datenbankfehler führen zu leeren Listen und Fehlerergebnissen, nie zu Ausnahmen', static function (): void {
    $broken = new CommunityContentProvider(static function (): TopicTrashService {
        throw new \RuntimeException('keine Datenbank');
    });
    T::same(0, $broken->countTrashed(), 'countTrashed: 0');
    T::same([], $broken->listTrashed(0, 10), 'listTrashed: leer');
    T::same(TrashResult::CODE_ERROR, $broken->restore(1, 1)->code, 'restore: error');
    T::same(TrashResult::CODE_ERROR, $broken->deletePermanently(1, 1, true)->code, 'deletePermanently: error');
    $warnings = $broken->deletionWarnings(1);
    T::same(1, count($warnings), 'deletionWarnings: bei unbekanntem Zustand eine Warnung (lieber nachfragen)');
});

T::run('Einbindung (Quelltext): Anmeldung vor dem Modul-Gate, Dienst erst bei Gebrauch, Sprachschlüssel in beiden Katalogen', static function (): void {
    $hooks = (string) file_get_contents(ABSPATH . 'core/Services/FeatureHooks/CommunityHooks.php');
    $register = strpos($hooks, 'ContentTypeRegistry::REGISTER_ACTION');
    $gate = strpos($hooks, 'if (!CommunityModule::isEnabled())');
    T::check($register !== false && $gate !== false && $register < $gate, 'CommunityHooks meldet den Provider vor dem Modul-Gate an (der Papierkorb gilt auch bei abgeschaltetem Modul)');
    T::check(str_contains($hooks, 'new CommunityContentProvider()'), 'CommunityHooks erzeugt den Provider');
    $provider = new CommunityContentProvider();
    T::same('community_topic', $provider->type(), 'Provider ohne Datenbankzugriff erzeugbar');
    $registry = new \CMS\Services\ContentTypes\ContentTypeRegistry();
    T::same(true, $registry->register($provider), 'ContentTypeRegistry nimmt den Provider an');
    T::same($provider, $registry->trashProvider('community_topic'), 'als TrashProvider abrufbar');
    $search = $registry->searchProviders();
    T::same([], array_keys(array_filter($search, static fn ($p): bool => $p instanceof CommunityContentProvider)), 'kein Suchindex-Provider: interne Foren bleiben aus der Suche der Website');
    foreach (['de', 'en'] as $locale) {
        $yaml = (string) file_get_contents(ABSPATH . 'lang/' . $locale . '.yaml');
        $missing = [];
        foreach (['label', 'restored', 'deleted', 'purged', 'confirm', 'warning.reports', 'warning.unknown', 'error.not_found', 'error.forum_missing', 'error.rejected', 'error.state', 'error.failed'] as $key) {
            if (!str_contains($yaml, '"community.trash.' . $key . '"')) {
                $missing[] = $key;
            }
        }
        T::same([], $missing, 'Sprachkatalog ' . $locale . ': alle Papierkorb-Schlüssel vorhanden');
    }
    $service = (string) file_get_contents(ABSPATH . 'core/Services/Community/Service/TopicTrashService.php');
    T::same(0, preg_match('/\bOFFSET\b/i', (string) preg_replace('~/\*.*?\*/~s', '', $service)), 'der Dienst benutzt kein OFFSET');
});

T::finish();
