<?php
/**
 * Datenzugriff der Community-Beiträge (`community_posts`) und ihres Bearbeitungsverlaufs (`community_post_revisions`).
 *
 * - Schreibzugriffe werfen bei Fehlern (siehe `AbstractRepository`); Zeiten setzt der Aufrufer (UTC).
 * - Beitragslisten laufen je Abfrage mit genau einem Status (Gleichheit auf `status` bedient `idx_community_posts_topic`
 *   ohne Sortierschritt); ausgeblendete und gelöschte Beiträge einer Seite kommen als Platzhalter aus einer zweiten
 *   Abfrage über den ID-Bereich der Seite (`placeholdersInRange()`).
 * - `edit_count` ändert sich nur atomar über `applyEdit()`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

use CMS\Services\Community\Support\Page;

if (!defined('ABSPATH')) {
    exit;
}

final class PostRepository extends AbstractRepository
{
    public const STATUSES = ['published', 'pending', 'hidden', 'deleted', 'rejected'];
    /** Größte Seite von `listPublished()`. */
    public const MAX_LIMIT = 100;

    private const TABLE = 'community_posts';
    private const REVISIONS = 'community_post_revisions';

    /** Spalten für `insert()` (alles außer `id`). */
    private const INSERTABLE = [
        'topic_id', 'forum_id', 'user_id', 'is_first_post', 'status', 'status_reason', 'format', 'body', 'body_html',
        'rendered_version', 'quote_of_post_id', 'edit_count', 'edited_at', 'edited_by', 'edit_reason', 'reaction_count',
        'ip_hash', 'created_at', 'updated_at',
    ];

    /** Spalten für `update()`: kein Besitzer, keine Zähler, keine Anlagezeit (Bearbeitungen über `applyEdit()`). */
    private const UPDATABLE = [
        'topic_id', 'forum_id', 'status', 'status_reason', 'body', 'body_html', 'rendered_version', 'edited_at',
        'edited_by', 'edit_reason', 'updated_at',
    ];

    /** Spalten für `revisionInsert()`. */
    private const REVISION_COLUMNS = ['post_id', 'title', 'body', 'format', 'edited_by', 'edit_reason', 'created_at'];

    /** Spalten der Platzhalter: ohne Text, damit ein Platzhalter nie Inhalt ausliefert. */
    private const PLACEHOLDER_COLUMNS = 'id, topic_id, forum_id, user_id, is_first_post, status, status_reason, created_at, updated_at';

    /** Status, die für Grenzen je Tag zählen. */
    private const COUNTED = ['published', 'pending', 'hidden'];
    /** Status, die für Abstände (Langsamer Modus) zählen. */
    private const SPACED = ['published', 'pending'];

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . $this->table(self::TABLE) . ' WHERE id = ? LIMIT 1', [$id]);
    }

    /**
     * Zeile mit Sperre lesen (nur in einer Transaktion sinnvoll).
     *
     * @return array<string, mixed>|null
     */
    public function lockForUpdate(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . $this->table(self::TABLE) . ' WHERE id = ? LIMIT 1' . self::forUpdate(), [$id]);
    }

    /**
     * Legt einen Beitrag an und liefert die ID. `created_at` und `updated_at` setzt der Aufrufer. `body_html` bleibt in
     * PR #2a NULL, `ip_hash` ebenfalls.
     *
     * @param array<string, mixed> $data Spalten aus INSERTABLE
     */
    public function insert(array $data): int
    {
        return $this->insertRow(self::TABLE, $data, self::INSERTABLE);
    }

    /**
     * Ändert Spalten aus UPDATABLE. Liefert `true`, wenn die Zeile geändert wurde. Weil die Tabelle keine Versionsspalte
     * hat, zählt MariaDB hier geänderte, nicht gefundene Zeilen: ein Aufruf mit unveränderten Werten liefert `false`.
     * Dienste sperren die Zeile vorher mit `lockForUpdate()` und wissen daher, dass sie existiert. Leerer Satz: `false`.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $data = self::pick($data, self::UPDATABLE);
        if ($data === []) {
            return false;
        }
        $set = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($data)));

        return $this->run('UPDATE ' . $this->table(self::TABLE) . " SET {$set} WHERE id = ?", [...array_values($data), $id]) === 1;
    }

    /**
     * Bearbeitung festschreiben: neuer Text, abgeleiteter Cache verworfen (`body_html` NULL, `rendered_version` 0),
     * `edit_count` atomar plus 1, Bearbeiter, Grund und Zeit. Liefert `true`, wenn der Beitrag existiert.
     */
    public function applyEdit(int $id, string $body, ?int $editorId, ?string $reason, string $nowUtc): bool
    {
        return $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET body = ?, body_html = NULL, rendered_version = 0, edit_count = edit_count + 1,'
            . ' edited_at = ?, edited_by = ?, edit_reason = ?, updated_at = ? WHERE id = ?',
            [$body, $nowUtc, $editorId, $reason, $nowUtc, $id]
        ) === 1;
    }

    /**
     * Veröffentlichte Beiträge eines Themas in Reihenfolge der ID, ab der ID `$afterId` (ausschließlich). Genau ein
     * Status je Abfrage. `Page::$next` ist die ID des letzten Beitrags der Seite (als Text) oder null auf der letzten
     * Seite; die nächste Seite fragt mit `(int) $page->next`.
     *
     * @return Page Einträge sind Beitragszeilen (alle Spalten)
     */
    public function listPublished(int $topicId, int $afterId = 0, int $limit = 15): Page
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $rows = $this->fetchAll(
            'SELECT * FROM ' . $this->table(self::TABLE) . " WHERE topic_id = ? AND status = 'published' AND id > ? ORDER BY id ASC LIMIT ?",
            [$topicId, max(0, $afterId), $limit + 1]
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return new Page($rows, $more && $rows !== [] ? (string) $rows[count($rows) - 1]['id'] : null);
    }

    /**
     * Ausgeblendete und gelöschte Beiträge eines Themas im ID-Bereich einer Seite (Platzhalter), aufsteigend. Die Zeilen
     * haben keinen Text (`body`, `body_html`): wer den Inhalt sehen darf (Autor, Moderation), lädt ihn mit `find()`.
     *
     * @return list<array<string, mixed>>
     */
    public function placeholdersInRange(int $topicId, int $firstId, int $lastId): array
    {
        return $this->fetchAll(
            'SELECT ' . self::PLACEHOLDER_COLUMNS . ' FROM ' . $this->table(self::TABLE)
            . " WHERE topic_id = ? AND status IN ('hidden', 'deleted') AND id BETWEEN ? AND ? ORDER BY id ASC",
            [$topicId, $firstId, $lastId]
        );
    }

    /**
     * Position im Thema (1 = Themenstart): 1 plus die Zahl der veröffentlichten Beiträge mit kleinerer ID. Für einen
     * veröffentlichten Beitrag ist das seine Stelle in der Liste; für einen anderen die Stelle, an der er stünde.
     * Index `idx_community_posts_topic` (überdeckend, Bereichszugriff).
     */
    public function positionOf(int $topicId, int $postId): int
    {
        return 1 + (int) $this->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->table(self::TABLE) . " WHERE topic_id = ? AND status = 'published' AND id < ?",
            [$topicId, $postId]
        );
    }

    /**
     * Legt eine Revision an (die ersetzte Fassung) und liefert ihre ID.
     *
     * @param array<string, mixed> $data Spalten: post_id, title (nur beim ersten Beitrag), body, format, edited_by, edit_reason, created_at
     */
    public function revisionInsert(array $data): int
    {
        return $this->insertRow(self::REVISIONS, $data, self::REVISION_COLUMNS);
    }

    /**
     * Revisionen eines Beitrags, jüngste zuerst (`idx_community_post_revisions_post`).
     *
     * @return list<array<string, mixed>>
     */
    public function revisions(int $postId, int $limit = 50): array
    {
        return $this->fetchAll(
            'SELECT * FROM ' . $this->table(self::REVISIONS) . ' WHERE post_id = ? ORDER BY created_at DESC, id DESC LIMIT ?',
            [$postId, max(1, min(200, $limit))]
        );
    }

    /**
     * Jüngster veröffentlichter Beitrag eines Themas (nach ID; für „letzter Beitrag“ nach dem Löschen).
     *
     * @return array{id: int, topic_id: int, forum_id: int, user_id: int|null, created_at: string}|null
     */
    public function lastPublishedInTopic(int $topicId): ?array
    {
        return $this->summary($this->fetchOne(
            'SELECT id, topic_id, forum_id, user_id, created_at FROM ' . $this->table(self::TABLE)
            . " WHERE topic_id = ? AND status = 'published' ORDER BY id DESC LIMIT 1",
            [$topicId]
        ));
    }

    /**
     * Jüngster veröffentlichter Beitrag eines Forums (nach Zeit, dann ID; Index `idx_community_posts_forum_time`).
     * Beiträge in Unterforen zählen nicht.
     *
     * @return array{id: int, topic_id: int, forum_id: int, user_id: int|null, created_at: string}|null
     */
    public function lastPublishedInForum(int $forumId): ?array
    {
        return $this->summary($this->fetchOne(
            'SELECT id, topic_id, forum_id, user_id, created_at FROM ' . $this->table(self::TABLE)
            . " WHERE forum_id = ? AND status = 'published' ORDER BY created_at DESC, id DESC LIMIT 1",
            [$forumId]
        ));
    }

    /**
     * Anzahl der Beiträge eines Mitglieds seit `$sinceUtc` (angelegt am oder nach dem Zeitpunkt) für Grenzen je Tag.
     * Ohne `$statuses` zählen veröffentlichte, wartende und ausgeblendete Beiträge (wartende zählen mit, sonst ließe sich
     * die Warteschlange fluten); Themenstarts sind Beiträge und zählen mit. Index `idx_community_posts_user`.
     *
     * @param list<string>|null $statuses Status aus STATUSES
     */
    public function countSince(int $userId, string $sinceUtc, ?array $statuses = null): int
    {
        $statuses = self::statuses($statuses, self::COUNTED);

        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND status IN (' . self::placeholders(count($statuses)) . ') AND created_at >= ?',
            [$userId, ...$statuses, $sinceUtc]
        );
    }

    /**
     * Jüngster Beitrag eines Mitglieds seit `$sinceUtc`, optional nur in einem Forum und/oder Thema, für Mindestabstände und den
     * Langsamen Modus. Ohne `$statuses` zählen veröffentlichte und wartende Beiträge; mit Liste genau diese Status (die
     * Rechteprüfung zählt auch gelöschte, damit Löschen keinen Abstand freigibt).
     *
     * @param list<string>|null $statuses Status aus STATUSES
     * @return array{id: int, topic_id: int, forum_id: int, created_at: string}|null
     */
    public function lastOfUser(int $userId, string $sinceUtc, ?int $forumId = null, ?int $topicId = null, ?array $statuses = null): ?array
    {
        $statuses = self::statuses($statuses, self::SPACED);
        $sql = 'SELECT id, topic_id, forum_id, created_at FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND status IN ('
            . self::placeholders(count($statuses)) . ') AND created_at >= ?';
        $params = [$userId, ...$statuses, $sinceUtc];
        if ($forumId !== null) {
            $sql .= ' AND forum_id = ?';
            $params[] = $forumId;
        }
        if ($topicId !== null) {
            $sql .= ' AND topic_id = ?';
            $params[] = $topicId;
        }
        $row = $this->fetchOne($sql . ' ORDER BY created_at DESC, id DESC LIMIT 1', $params);

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'topic_id' => (int) $row['topic_id'],
            'forum_id' => (int) $row['forum_id'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * Doppeltes Absenden: ein Beitrag desselben Mitglieds im selben Thema seit `$sinceUtc` mit gleichem Text. Die Abfrage holt
     * die zehn jüngsten Beiträge des Mitglieds im Fenster (Index `idx_community_posts_user`, unabhängig von der Größe des
     * Themas); Thema und Text werden exakt in PHP verglichen.
     *
     * @return array{id: int, topic_id: int, status: string, is_first_post: int}|null
     */
    public function findRecentDuplicate(int $userId, int $topicId, string $body, string $sinceUtc): ?array
    {
        $rows = $this->fetchAll(
            'SELECT id, topic_id, status, is_first_post, body FROM ' . $this->table(self::TABLE)
            . " WHERE user_id = ? AND status IN ('published', 'pending') AND created_at >= ? ORDER BY created_at DESC, id DESC LIMIT 10",
            [$userId, $sinceUtc]
        );
        foreach ($rows as $row) {
            if ((int) $row['topic_id'] === $topicId && (string) ($row['body'] ?? '') === $body) {
                return [
                    'id' => (int) $row['id'],
                    'topic_id' => (int) $row['topic_id'],
                    'status' => (string) $row['status'],
                    'is_first_post' => (int) $row['is_first_post'],
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{id: int, topic_id: int, forum_id: int, user_id: int|null, created_at: string}|null
     */
    private function summary(?array $row): ?array
    {
        return $row === null ? null : [
            'id' => (int) $row['id'],
            'topic_id' => (int) $row['topic_id'],
            'forum_id' => (int) $row['forum_id'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param list<string>|null $statuses
     * @param list<string> $default
     * @return list<string>
     */
    private static function statuses(?array $statuses, array $default): array
    {
        if ($statuses === null) {
            return $default;
        }
        $statuses = array_values(array_unique($statuses));
        $unknown = array_diff($statuses, self::STATUSES);
        if ($unknown !== [] || $statuses === []) {
            throw new \InvalidArgumentException('Ungültige Beitragsstatus: ' . implode(', ', $unknown));
        }

        return $statuses;
    }
}
