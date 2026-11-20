<?php
/**
 * Lese-SQL für Suche, Indexpflege, Sitemap und Medienreferenzen der Community (PR #2b; Vertrag DESIGN-2b §4.8, Entwurf medien §1.2,
 * §2.5 bis §2.7). Keine Schreibzugriffe, nie `ip_hash`, Spalten immer ausdrücklich.
 *
 * - Rechte über `t.forum_id` (autoritativ; `p.forum_id` ist denormalisiert und nach einem Verschieben kurz veraltet): jeder Treffer
 *   ist ein veröffentlichter Beitrag in einem veröffentlichten Thema eines übergebenen Forums (RIGHTS, gemeinsam für Prüfung und LIKE).
 * - Zeitfilter sind UTC-Texte vom Aufrufer; keine Uhr.
 * - Leere Foren- oder ID-Listen ergeben ein leeres Ergebnis ohne Abfrage.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class SearchRepository extends AbstractRepository
{
    /** IDs je `IN`-Liste der Prüfung (`verify()`). */
    public const VERIFY_CHUNK = 500;
    /** IDs je `IN`-Liste von `bodies()`. */
    public const BODIES_CHUNK = 100;
    /** IDs je `IN`-Liste von `indexRows()`. */
    public const INDEX_CHUNK = 200;

    private const POSTS = 'community_posts';
    private const TOPICS = 'community_topics';

    /** Gemeinsame Rechtebedingung von Prüfung und LIKE-Rückfall; danach folgen die Platzhalter der lesbaren Foren und `)`. */
    private const RIGHTS = " AND p.status = 'published' AND t.status = 'published' AND t.forum_id IN (";

    /** Spalten eines Treffers. */
    private const HIT_COLUMNS = 'p.id, p.topic_id, p.user_id, p.is_first_post, p.created_at, t.forum_id, t.title, t.slug, t.solution_post_id';

    /** Spalten für die Indexpflege (Beitrag mit Themenstatus und Titel; Thema fehlt ⇒ NULL). */
    private const INDEX_COLUMNS = 'p.id, p.topic_id, p.is_first_post, p.status, p.body, t.status AS topic_status, t.title';

    /**
     * Prüfung der Kandidaten aus dem Index: welche Beiträge sind veröffentlicht, in einem veröffentlichten Thema eines der Foren und passen
     * zu den Filtern? Primärschlüssel-Bereich plus `eq_ref` auf das Thema. Stücke zu VERIFY_CHUNK IDs; Reihenfolge nach ID.
     *
     * @param array<array-key, mixed> $postIds
     * @param list<int> $forumIds
     * @param array{user_id?: int, since?: string, until?: string, first_only?: bool, solved?: bool} $filters
     * @return list<array{id: int, topic_id: int, user_id: ?int, is_first_post: int, created_at: string, forum_id: int, title: string, slug: string, solution_post_id: ?int}>
     */
    public function verify(array $postIds, array $forumIds, array $filters): array
    {
        $forumIds = self::ids($forumIds);
        $postIds = self::ids($postIds);
        if ($forumIds === [] || $postIds === []) {
            return [];
        }
        sort($postIds);
        [$where, $params] = self::filterSql($filters);
        $rows = [];
        foreach (array_chunk($postIds, self::VERIFY_CHUNK) as $chunk) {
            $sql = 'SELECT ' . self::HIT_COLUMNS . ' FROM ' . $this->table(self::POSTS) . ' p INNER JOIN ' . $this->table(self::TOPICS) . ' t ON t.id = p.topic_id'
                . ' WHERE p.id IN (' . self::placeholders(count($chunk)) . ')' . self::RIGHTS . self::placeholders(count($forumIds)) . ')' . $where
                . ' ORDER BY p.id ASC';
            foreach ($this->fetchAll($sql, [...$chunk, ...$forumIds, ...$params]) as $row) {
                $rows[] = self::hit($row);
            }
        }

        return $rows;
    }

    /**
     * LIKE-Rückfall ohne Index (nur für Mitglieder): Beiträge mit `$minPostId < id ≤ $maxPostId`, je Muster `body` oder (erster Beitrag)
     * Titel, alle Muster mit UND, absteigend nach ID. Muster aus `likePattern()` (Escape `!`). Form wie `verify()` plus `body`.
     * `$limit` 1 bis 1000. Der Dienst fragt das Fenster in Stücken ab: Bei einem schmalen ID-Bereich liest MariaDB über PRIMARY; bei einem
     * breiten wählte sie in der Messung zuerst die Themen der lesbaren Foren (alle, unabhängig vom Fenster).
     *
     * @param list<string> $patterns
     * @param list<int> $forumIds
     * @param array{user_id?: int, since?: string, until?: string, first_only?: bool, solved?: bool} $filters
     * @return list<array<string, mixed>>
     */
    public function likeCandidates(array $patterns, array $forumIds, array $filters, int $minPostId, int $limit, ?int $maxPostId = null): array
    {
        $forumIds = self::ids($forumIds);
        $patterns = array_values(array_filter($patterns, static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== ''));
        if ($forumIds === [] || $patterns === []) {
            return [];
        }
        [$where, $params] = self::filterSql($filters);
        $match = '';
        $matchParams = [];
        foreach ($patterns as $pattern) {
            $match .= " AND (p.body LIKE ? ESCAPE '!' OR (p.is_first_post = 1 AND t.title LIKE ? ESCAPE '!'))";
            array_push($matchParams, $pattern, $pattern);
        }
        $sql = 'SELECT ' . self::HIT_COLUMNS . ', p.body FROM ' . $this->table(self::POSTS) . ' p INNER JOIN ' . $this->table(self::TOPICS) . ' t ON t.id = p.topic_id'
            . ' WHERE p.id > ?' . ($maxPostId !== null ? ' AND p.id <= ?' : '') . self::RIGHTS . self::placeholders(count($forumIds)) . ')' . $where . $match
            . ' ORDER BY p.id DESC LIMIT ?';
        $bounds = $maxPostId !== null ? [max(0, $minPostId), $maxPostId] : [max(0, $minPostId)];
        $rows = [];
        foreach ($this->fetchAll($sql, [...$bounds, ...$forumIds, ...$params, ...$matchParams, max(1, min(1000, $limit))]) as $row) {
            $rows[] = self::hit($row) + ['body' => (string) $row['body']];
        }

        return $rows;
    }

    /**
     * Texte der Beiträge (Auszüge der Ergebnisseite), Schlüssel = ID (Primärschlüssel, Stücke zu BODIES_CHUNK).
     *
     * @param array<array-key, mixed> $postIds
     * @return array<int, string>
     */
    public function bodies(array $postIds): array
    {
        $ids = self::ids($postIds);
        sort($ids);
        $bodies = [];
        foreach (array_chunk($ids, self::BODIES_CHUNK) as $chunk) {
            foreach ($this->fetchAll(
                'SELECT id, body FROM ' . $this->table(self::POSTS) . ' WHERE id IN (' . self::placeholders(count($chunk)) . ')',
                $chunk
            ) as $row) {
                $bodies[(int) $row['id']] = (string) $row['body'];
            }
        }

        return $bodies;
    }

    /**
     * Datenbankstand der Beiträge für den Index (Beitrag mit Themenstatus und Titel), aufsteigend; fehlende IDs fehlen
     * (Primärschlüssel plus `eq_ref`, Stücke zu INDEX_CHUNK).
     *
     * @param array<array-key, mixed> $postIds
     * @return list<array{id: int, topic_id: int, is_first_post: int, status: string, body: string, topic_status: ?string, title: ?string}>
     */
    public function indexRows(array $postIds): array
    {
        $ids = self::ids($postIds);
        sort($ids);
        $rows = [];
        foreach (array_chunk($ids, self::INDEX_CHUNK) as $chunk) {
            foreach ($this->fetchAll(
                'SELECT ' . self::INDEX_COLUMNS . ' FROM ' . $this->table(self::POSTS) . ' p LEFT JOIN ' . $this->table(self::TOPICS) . ' t ON t.id = p.topic_id'
                . ' WHERE p.id IN (' . self::placeholders(count($chunk)) . ') ORDER BY p.id ASC',
                $chunk
            ) as $row) {
                $rows[] = self::indexRow($row);
            }
        }

        return $rows;
    }

    /**
     * Wie `indexRows()` für das ID-Fenster `$afterId < id ≤ $untilId`, aufsteigend, höchstens `$limit` (1 bis 1000) Zeilen
     * (Primärschlüssel-Bereich; Neuaufbau und Abgleich).
     *
     * @return list<array{id: int, topic_id: int, is_first_post: int, status: string, body: string, topic_status: ?string, title: ?string}>
     */
    public function indexWindow(int $afterId, int $untilId, int $limit): array
    {
        if ($untilId <= $afterId) {
            return [];
        }

        return array_map(self::indexRow(...), $this->fetchAll(
            'SELECT ' . self::INDEX_COLUMNS . ' FROM ' . $this->table(self::POSTS) . ' p LEFT JOIN ' . $this->table(self::TOPICS) . ' t ON t.id = p.topic_id'
            . ' WHERE p.id > ? AND p.id <= ? ORDER BY p.id ASC LIMIT ?',
            [max(0, $afterId), $untilId, max(1, min(1000, $limit))]
        ));
    }

    /**
     * Beitrags-IDs eines Themas nach `$afterId`, aufsteigend (`idx_community_posts_topic`, kleiner Filesort je Thema). `$limit` 1 bis 1000.
     *
     * @return list<int>
     */
    public function topicPostIds(int $topicId, int $afterId, int $limit): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $this->fetchAll(
            'SELECT id FROM ' . $this->table(self::POSTS) . ' WHERE topic_id = ? AND id > ? ORDER BY id ASC LIMIT ?',
            [$topicId, max(0, $afterId), max(1, min(1000, $limit))]
        ));
    }

    /** Erster Beitrag eines Themas (`null`: Thema fehlt oder ohne ersten Beitrag). */
    public function firstPostId(int $topicId): ?int
    {
        $value = $this->fetchValue('SELECT first_post_id FROM ' . $this->table(self::TOPICS) . ' WHERE id = ? LIMIT 1', [$topicId]);

        return $value === null ? null : (int) $value;
    }

    /**
     * Veröffentlichte Themen der Foren nach Aktivität (`idx_community_topics_activity`, ohne Filesort), höchstens `$limit` (1 bis 5000).
     *
     * @param list<int> $forumIds
     * @return list<array{id: int, forum_id: int, slug: string, last_post_at: string}>
     */
    public function sitemapTopics(array $forumIds, int $limit): array
    {
        $forumIds = self::ids($forumIds);
        if ($forumIds === [] || $limit < 1) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'forum_id' => (int) $row['forum_id'],
            'slug' => (string) $row['slug'],
            'last_post_at' => (string) $row['last_post_at'],
        ], $this->fetchAll(
            'SELECT id, forum_id, slug, last_post_at FROM ' . $this->table(self::TOPICS)
            . " WHERE status = 'published' AND forum_id IN (" . self::placeholders(count($forumIds)) . ') ORDER BY last_post_at DESC, id DESC LIMIT ?',
            [...$forumIds, min(5000, $limit)]
        ));
    }

    /**
     * Beitrags-IDs mit einem der Status nach `$afterId`, aufsteigend (Primärschlüssel-Bereich; Medienreferenzen). `$limit` 1 bis 1000.
     *
     * @param list<string> $statuses Werte aus `PostRepository::STATUSES`
     * @return list<int>
     */
    public function mediaIds(int $afterId, int $limit, array $statuses): array
    {
        $statuses = array_values(array_unique(array_filter($statuses, static fn (mixed $status): bool => in_array($status, PostRepository::STATUSES, true))));
        if ($statuses === []) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['id'], $this->fetchAll(
            'SELECT id FROM ' . $this->table(self::POSTS) . ' WHERE id > ? AND status IN (' . self::placeholders(count($statuses)) . ') ORDER BY id ASC LIMIT ?',
            [max(0, $afterId), ...$statuses, max(1, min(1000, $limit))]
        ));
    }

    /**
     * Ein Beitrag für die Medienreferenzen (Text, Status, Thementitel und -slug; Thema fehlt ⇒ NULL).
     *
     * @return array{id: int, topic_id: int, is_first_post: int, status: string, body: string, title: ?string, slug: ?string}|null
     */
    public function mediaPost(int $id): ?array
    {
        $row = $this->fetchOne(
            'SELECT p.id, p.topic_id, p.is_first_post, p.status, p.body, t.title, t.slug FROM ' . $this->table(self::POSTS) . ' p LEFT JOIN '
            . $this->table(self::TOPICS) . ' t ON t.id = p.topic_id WHERE p.id = ? LIMIT 1',
            [$id]
        );
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'topic_id' => (int) $row['topic_id'],
            'is_first_post' => (int) $row['is_first_post'],
            'status' => (string) $row['status'],
            'body' => (string) $row['body'],
            'title' => $row['title'] === null ? null : (string) $row['title'],
            'slug' => $row['slug'] === null ? null : (string) $row['slug'],
        ];
    }

    /** LIKE-Muster eines Wortes mit Escape-Zeichen `!` (`%`, `_` und `!` maskiert). */
    public static function likePattern(string $word): string
    {
        return '%' . strtr($word, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    /**
     * Filterbedingungen und Parameter (Reihenfolge der Platzhalter: user_id, since, until).
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function filterSql(array $filters): array
    {
        $where = '';
        $params = [];
        if (isset($filters['user_id'])) {
            $where .= ' AND p.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (isset($filters['since'])) {
            $where .= ' AND p.created_at >= ?';
            $params[] = (string) $filters['since'];
        }
        if (isset($filters['until'])) {
            $where .= ' AND p.created_at < ?';
            $params[] = (string) $filters['until'];
        }
        if (!empty($filters['first_only'])) {
            $where .= ' AND p.is_first_post = 1';
        }
        if (!empty($filters['solved'])) {
            $where .= ' AND t.solution_post_id IS NOT NULL';
        }

        return [$where, $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, topic_id: int, user_id: ?int, is_first_post: int, created_at: string, forum_id: int, title: string, slug: string, solution_post_id: ?int}
     */
    private static function hit(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'topic_id' => (int) $row['topic_id'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
            'is_first_post' => (int) $row['is_first_post'],
            'created_at' => (string) $row['created_at'],
            'forum_id' => (int) $row['forum_id'],
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
            'solution_post_id' => $row['solution_post_id'] === null ? null : (int) $row['solution_post_id'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, topic_id: int, is_first_post: int, status: string, body: string, topic_status: ?string, title: ?string}
     */
    private static function indexRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'topic_id' => (int) $row['topic_id'],
            'is_first_post' => (int) $row['is_first_post'],
            'status' => (string) $row['status'],
            'body' => (string) $row['body'],
            'topic_status' => $row['topic_status'] === null ? null : (string) $row['topic_status'],
            'title' => $row['title'] === null ? null : (string) $row['title'],
        ];
    }
}
