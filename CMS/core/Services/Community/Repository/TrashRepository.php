<?php
/**
 * Papierkorb der Community (PR #5): Abfragen und Löschanweisungen für gelöschte Themen (`status = deleted`) und alles, was an einem
 * Thema hängt. Gelesen wird über `TopicRepository`/`ForumRepository`; hier stehen nur die Anweisungen, die es dort nicht gibt.
 *
 * - Die Liste liefert nur IDs (`deletedIds()`), höchstens LIST_MAX; Zeilen, Foren und Namen laden die Folgeabfragen. Der Papierkorb
 *   des Core blättert mit Versatz: der Dienst schneidet die Seite in PHP aus der geordneten ID-Liste.
 * - Löschen je Stück (CHUNK): IDs eindeutig, aufsteigend, je Stück eine Anweisung mit `IN (…)`. Tabellen- und Spaltennamen stammen aus
 *   Konstanten, Werte sind gebunden. Kein `DELETE … LIMIT`: die IDs stehen vorher fest.
 * - Der Papierkorb ist der einzige Weg, ein Thema endgültig zu löschen (DATA-MODEL: weiches Löschen, endgültig nur über den Papierkorb,
 *   die Löschfristen oder die DSGVO).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class TrashRepository extends AbstractRepository
{
    /** Größte Zahl der IDs, die `deletedIds()` liefert (Seite des Core höchstens 500, dazu der Versatz). */
    public const LIST_MAX = 5000;

    /** Zeilen je Anweisung bei `IN (…)`. */
    public const CHUNK = 100;

    private const TOPICS = 'community_topics';
    private const POSTS = 'community_posts';
    private const FORUMS = 'community_forums';
    private const REVISIONS = 'community_post_revisions';
    private const REACTIONS = 'community_reactions';
    private const ATTACHMENTS = 'community_attachments';
    private const REPORTS = 'community_reports';
    private const READ_STATES = 'community_read_states';
    private const SUBSCRIPTIONS = 'community_subscriptions';
    private const QUEUE = 'community_notify_queue';
    private const POLLS = 'community_polls';
    private const POLL_OPTIONS = 'community_poll_options';
    private const POLL_VOTES = 'community_poll_votes';
    private const USERS = 'users';

    /** Spalten, die die Liste braucht. */
    private const LIST_COLUMNS = 'id, forum_id, user_id, title, slug, status, previous_status, trashed_at, trashed_by, reply_count, first_post_id';

    /** Tabellen mit der Spalte `post_id`, die vor den Beiträgen verschwinden (Name → Spalte). */
    private const BY_POST = [
        self::REVISIONS => 'post_id',
        self::REACTIONS => 'post_id',
        self::ATTACHMENTS => 'post_id',
    ];

    // ── Lesen ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * IDs gelöschter Themen, zuletzt gelöschte zuerst (ohne Zeitstempel zuletzt), höchstens `$limit` (1 bis LIST_MAX). Der Suchtext
     * trifft Titel und Adresse als Teilwort.
     *
     * @return list<int>
     */
    public function deletedIds(int $limit, string $search = ''): array
    {
        [$where, $params] = $this->filter($search);
        $rows = $this->fetchAll(
            'SELECT id FROM ' . $this->table(self::TOPICS) . " WHERE {$where} ORDER BY (trashed_at IS NULL) ASC, trashed_at DESC, id DESC LIMIT ?",
            [...$params, max(1, min(self::LIST_MAX, $limit))]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function countDeleted(string $search = ''): int
    {
        [$where, $params] = $this->filter($search);

        return (int) ($this->fetchValue('SELECT COUNT(*) FROM ' . $this->table(self::TOPICS) . " WHERE {$where}", $params) ?? 0);
    }

    /**
     * Zeilen der Themen mit den Spalten der Liste, nach ID.
     *
     * @param array<array-key, mixed> $ids
     * @return array<int, array<string, mixed>>
     */
    public function topicsByIds(array $ids): array
    {
        return $this->byIds(self::TOPICS, self::LIST_COLUMNS, 'id', $ids);
    }

    /**
     * Titel der Foren, nach ID.
     *
     * @param array<array-key, mixed> $ids
     * @return array<int, string>
     */
    public function forumTitles(array $ids): array
    {
        $titles = [];
        foreach ($this->byIds(self::FORUMS, 'id, title', 'id', $ids) as $id => $row) {
            $titles[$id] = (string) $row['title'];
        }

        return $titles;
    }

    /**
     * Anzeigenamen der Konten (`display_name`, sonst `username`), nach ID; fehlende Konten fehlen.
     *
     * @param array<array-key, mixed> $ids
     * @return array<int, string>
     */
    public function userNames(array $ids): array
    {
        $names = [];
        foreach ($this->byIds(self::USERS, 'id, display_name, username', 'id', $ids) as $id => $row) {
            $name = trim((string) ($row['display_name'] ?? ''));
            $names[$id] = $name !== '' ? $name : trim((string) ($row['username'] ?? ''));
        }

        return $names;
    }

    /**
     * Gelöschte Themen, deren Zeitstempel fehlt (vor diesem Stand gelöscht): sie bekommen beim ersten Fristlauf den aktuellen Zeitpunkt.
     */
    public function stampMissing(string $nowUtc): int
    {
        return $this->run('UPDATE ' . $this->table(self::TOPICS) . " SET trashed_at = ? WHERE status = 'deleted' AND trashed_at IS NULL", [$nowUtc]);
    }

    /**
     * IDs gelöschter Themen mit `trashed_at` vor der Grenze, älteste zuerst (`idx_community_topics_trashed`).
     *
     * @return list<int>
     */
    public function expiredIds(string $beforeUtc, int $limit): array
    {
        $rows = $this->fetchAll(
            'SELECT id FROM ' . $this->table(self::TOPICS) . " WHERE status = 'deleted' AND trashed_at < ? ORDER BY trashed_at ASC, id ASC LIMIT ?",
            [$beforeUtc, max(1, min(self::LIST_MAX, $limit))]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Offene Meldungen zum Thema und zu seinen Beiträgen (Hinweis vor dem endgültigen Löschen).
     */
    public function openReports(int $topicId): int
    {
        $reports = $this->table(self::REPORTS);
        $posts = $this->table(self::POSTS);

        return (int) ($this->fetchValue(
            "SELECT COUNT(*) FROM {$reports} WHERE status = 'open' AND ((target_type = 'topic' AND target_id = ?) "
            . "OR (target_type = 'post' AND target_id IN (SELECT id FROM {$posts} WHERE topic_id = ?)))",
            [$topicId, $topicId]
        ) ?? 0);
    }

    // ── Endgültig löschen ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * Beiträge des Themas ab `$afterId` (ausschließlich), aufsteigend nach ID (Keyset über den Index `idx_community_posts_topic`).
     *
     * @return list<int>
     */
    public function postIdsAfter(int $topicId, int $afterId, int $limit): array
    {
        $rows = $this->fetchAll(
            'SELECT id FROM ' . $this->table(self::POSTS) . ' WHERE topic_id = ? AND id > ? ORDER BY id ASC LIMIT ?',
            [$topicId, max(0, $afterId), max(1, min(self::CHUNK, $limit))]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Anhänge der Beiträge: ID, Beitrag, Ablagepfad und Vorschau (die Dateien entfernt der Aufrufer nach dem Commit).
     *
     * @param array<array-key, mixed> $postIds
     * @return list<array{id: int, post_id: int, storage_path: string, has_preview: int}>
     */
    public function attachmentsOfPosts(array $postIds): array
    {
        $rows = [];
        foreach (array_chunk(self::sorted($postIds), self::CHUNK) as $chunk) {
            foreach ($this->fetchAll(
                'SELECT id, post_id, storage_path, has_preview FROM ' . $this->table(self::ATTACHMENTS) . ' WHERE post_id IN (' . self::placeholders(count($chunk)) . ') ORDER BY id ASC',
                $chunk
            ) as $row) {
                $rows[] = [
                    'id' => (int) $row['id'],
                    'post_id' => (int) $row['post_id'],
                    'storage_path' => (string) $row['storage_path'],
                    'has_preview' => (int) $row['has_preview'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Reaktionen der Beiträge, zusammengezählt für die Statistik: `received` je Autor des Beitrags (ohne Konto entfällt), `given` je
     * Reagierendem. Die Zähler `reactions_received` und `reactions_given` sinken beim Löschen um diese Werte.
     *
     * @param array<array-key, mixed> $postIds
     * @return array{received: array<int, int>, given: array<int, int>}
     */
    public function reactionTotals(array $postIds): array
    {
        $received = [];
        $given = [];
        $reactions = $this->table(self::REACTIONS);
        $posts = $this->table(self::POSTS);
        foreach (array_chunk(self::sorted($postIds), self::CHUNK) as $chunk) {
            $in = self::placeholders(count($chunk));
            foreach ($this->fetchAll(
                "SELECT p.user_id AS member, COUNT(*) AS n FROM {$reactions} r INNER JOIN {$posts} p ON p.id = r.post_id WHERE r.post_id IN ({$in}) AND p.user_id IS NOT NULL GROUP BY p.user_id",
                $chunk
            ) as $row) {
                $received[(int) $row['member']] = ($received[(int) $row['member']] ?? 0) + (int) $row['n'];
            }
            foreach ($this->fetchAll(
                "SELECT user_id AS member, COUNT(*) AS n FROM {$reactions} WHERE post_id IN ({$in}) GROUP BY user_id",
                $chunk
            ) as $row) {
                $given[(int) $row['member']] = ($given[(int) $row['member']] ?? 0) + (int) $row['n'];
            }
        }
        ksort($received);
        ksort($given);

        return ['received' => $received, 'given' => $given];
    }

    /**
     * Löscht Revisionen, Reaktionen, Anhänge und Meldungen der Beiträge und danach die Beiträge selbst. Liefert die Zeilen je Tabelle.
     *
     * @param array<array-key, mixed> $postIds
     * @return array<string, int>
     */
    public function deletePosts(array $postIds): array
    {
        $removed = ['revisions' => 0, 'reactions' => 0, 'attachments' => 0, 'reports' => 0, 'posts' => 0];
        $keys = [self::REVISIONS => 'revisions', self::REACTIONS => 'reactions', self::ATTACHMENTS => 'attachments'];
        foreach (array_chunk(self::sorted($postIds), self::CHUNK) as $chunk) {
            $in = self::placeholders(count($chunk));
            foreach (self::BY_POST as $table => $column) {
                $removed[$keys[$table]] += $this->run('DELETE FROM ' . $this->table($table) . " WHERE {$column} IN ({$in})", $chunk);
            }
            $removed['reports'] += $this->run('DELETE FROM ' . $this->table(self::REPORTS) . " WHERE target_type = 'post' AND target_id IN ({$in})", $chunk);
            $removed['posts'] += $this->run('DELETE FROM ' . $this->table(self::POSTS) . " WHERE id IN ({$in})", $chunk);
        }

        return $removed;
    }

    /**
     * Verweis-Themen (`status = moved`), die auf das Thema zeigen, in allen Foren.
     *
     * @return list<int>
     */
    public function stubIds(int $topicId): array
    {
        $rows = $this->fetchAll(
            'SELECT id FROM ' . $this->table(self::TOPICS) . " WHERE status = 'moved' AND moved_to_topic_id = ? ORDER BY id ASC LIMIT ?",
            [$topicId, self::LIST_MAX]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Löscht, was nur am Thema hängt: Meldungen zum Thema, Lesemarken, Abos, Benachrichtigungsaufträge, Umfragen samt Optionen und Stimmen
     * und die Verweis-Themen. Das Thema selbst löscht `deleteTopic()`. Liefert die Zeilen je Art.
     *
     * @return array<string, int>
     */
    public function deleteTopicData(int $topicId): array
    {
        $polls = $this->table(self::POLLS);
        $pollIds = '(SELECT id FROM ' . $polls . ' WHERE topic_id = ?)';
        $removed = [];
        $removed['poll_votes'] = $this->run('DELETE FROM ' . $this->table(self::POLL_VOTES) . ' WHERE poll_id IN ' . $pollIds, [$topicId]);
        $removed['poll_options'] = $this->run('DELETE FROM ' . $this->table(self::POLL_OPTIONS) . ' WHERE poll_id IN ' . $pollIds, [$topicId]);
        $removed['polls'] = $this->run("DELETE FROM {$polls} WHERE topic_id = ?", [$topicId]);
        $removed['reports'] = $this->run('DELETE FROM ' . $this->table(self::REPORTS) . " WHERE target_type = 'topic' AND target_id = ?", [$topicId]);
        $removed['read_states'] = $this->run('DELETE FROM ' . $this->table(self::READ_STATES) . ' WHERE topic_id = ?', [$topicId]);
        $removed['subscriptions'] = $this->run('DELETE FROM ' . $this->table(self::SUBSCRIPTIONS) . " WHERE target_type = 'topic' AND target_id = ?", [$topicId]);
        $removed['notify_queue'] = $this->run('DELETE FROM ' . $this->table(self::QUEUE) . ' WHERE topic_id = ?', [$topicId]);
        $removed['stubs'] = 0;
        foreach (array_chunk($this->stubIds($topicId), self::CHUNK) as $chunk) {
            $removed['stubs'] += $this->run('DELETE FROM ' . $this->table(self::TOPICS) . " WHERE status = 'moved' AND id IN (" . self::placeholders(count($chunk)) . ')', $chunk);
        }

        return $removed;
    }

    /** Löscht die Themenzeile, nur wenn sie noch im Papierkorb liegt (`deleted`). `true`, wenn eine Zeile gelöscht wurde. */
    public function deleteTopic(int $topicId): bool
    {
        return $this->run('DELETE FROM ' . $this->table(self::TOPICS) . " WHERE id = ? AND status = 'deleted'", [$topicId]) === 1;
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function filter(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ["status = 'deleted'", []];
        }
        $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';

        return ["status = 'deleted' AND (title LIKE ? ESCAPE '!' OR slug LIKE ? ESCAPE '!')", [$like, $like]];
    }

    /**
     * @param array<array-key, mixed> $ids
     * @return array<int, array<string, mixed>>
     */
    private function byIds(string $table, string $columns, string $key, array $ids): array
    {
        $map = [];
        foreach (array_chunk(self::sorted($ids), self::CHUNK) as $chunk) {
            foreach ($this->fetchAll(
                "SELECT {$columns} FROM " . $this->table($table) . " WHERE {$key} IN (" . self::placeholders(count($chunk)) . ')',
                $chunk
            ) as $row) {
                $map[(int) $row[$key]] = $row;
            }
        }

        return $map;
    }

    /**
     * @param array<array-key, mixed> $ids
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        $ids = self::ids($ids);
        sort($ids);

        return $ids;
    }
}
