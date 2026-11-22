<?php
/**
 * Datenzugriff der Community-Beiträge (`community_posts`) und ihres Bearbeitungsverlaufs (`community_post_revisions`).
 *
 * - Schreibzugriffe werfen bei Fehlern (siehe `AbstractRepository`); Zeiten setzt der Aufrufer (UTC).
 * - Beitragslisten laufen je Abfrage mit genau einem Status (Gleichheit auf `status` bedient `idx_community_posts_topic`
 *   ohne Sortierschritt); ausgeblendete und gelöschte Beiträge einer Seite kommen als Platzhalter aus einer zweiten
 *   Abfrage über den ID-Bereich der Seite (`placeholdersInRange()`).
 * - Text (`body`) und Cache (`body_html`, `rendered_version`) schreiben nur `insert()`, `applyEdit()`, `storeRendered()` und
 *   `clearRendered()`. Invariante (PR #2b, Wächter des Nachrenderns): jede Änderung des Texts oder der Anhangsmenge erhöht
 *   `edit_count` atomar; `storeRendered()` schreibt nur bei unverändertem `edit_count`.
 * - `ip_hash` (PRIVATE_COLUMNS) liest nur `ipHashes()`. `find()`, `lockForUpdate()` und `listPublished()` liefern ganze Zeilen;
 *   Lese-APIs der Dienste entfernen PRIVATE_COLUMNS. Mengenlesen (`findMany()`, `lockMany()`) liefert weder `ip_hash` noch Text.
 * - Mengenabfragen über IDs laufen aufsteigend in Stücken zu höchstens CHUNK IDs (Sperrfolge, Vertrag DESIGN-2b §3.10).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

use CMS\Services\Community\Support\Cursor;
use CMS\Services\Community\Support\Page;

if (!defined('ABSPATH')) {
    exit;
}

final class PostRepository extends AbstractRepository
{
    public const STATUSES = ['published', 'pending', 'hidden', 'deleted', 'rejected'];
    /** Größte Seite von `listPublished()`, `queue()` und `renderBatch()`. */
    public const MAX_LIMIT = 100;
    /** Spalten, die keine Lese-API eines Dienstes ausgibt; einzige Lesestelle ist `ipHashes()` (Vertrag DESIGN-2b K16, K35). */
    public const PRIVATE_COLUMNS = ['ip_hash'];
    /** Obergrenze der Zählung in `queueSummary()` (Badge „1000+“; die Zählung liest höchstens so viele Indexeinträge). */
    public const QUEUE_COUNT_MAX = 1000;
    /** Höchstzahl der IDs je Mengenabfrage (`IN (…)`); größere Mengen laufen in Stücken, aufsteigend. */
    public const CHUNK = 100;
    /** Größte Version des HTML-Caches (`rendered_version` ist SMALLINT UNSIGNED). */
    public const MAX_RENDERED_VERSION = 65535;

    private const TABLE = 'community_posts';
    private const REVISIONS = 'community_post_revisions';
    private const TOPICS = 'community_topics';
    private const ATTACHMENTS = 'community_attachments';

    /** Alle Spalten von `community_posts` in Schemareihenfolge (Wächter in `community-repositories` vergleicht mit dem Schema). */
    private const COLUMNS = [
        'id', 'topic_id', 'forum_id', 'user_id', 'is_first_post', 'status', 'status_reason', 'format', 'body', 'body_html',
        'rendered_version', 'quote_of_post_id', 'edit_count', 'edited_at', 'edited_by', 'edit_reason', 'reaction_count',
        'ip_hash', 'created_at', 'updated_at',
    ];
    /** Text und Cache: Mengenlesen und Platzhalter kommen ohne sie aus. */
    private const TEXT_COLUMNS = ['body', 'body_html'];

    /** Spalten für `insert()` (alles außer `id`). */
    private const INSERTABLE = [
        'topic_id', 'forum_id', 'user_id', 'is_first_post', 'status', 'status_reason', 'format', 'body', 'body_html',
        'rendered_version', 'quote_of_post_id', 'edit_count', 'edited_at', 'edited_by', 'edit_reason', 'reaction_count',
        'ip_hash', 'created_at', 'updated_at',
    ];

    /**
     * Spalten für `update()`: kein Besitzer, keine Zähler, keine Anlagezeit, kein Text und kein Cache (Text über `applyEdit()`,
     * Cache über `storeRendered()`/`clearRendered()`; sonst bliebe `edit_count` stehen und der Wächter des Nachrenderns griffe nicht).
     */
    private const UPDATABLE = [
        'topic_id', 'forum_id', 'status', 'status_reason', 'edited_at', 'edited_by', 'edit_reason', 'updated_at',
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
     * Bearbeitung festschreiben: neuer Text, Cache neu (`$bodyHtml` mit `$renderedVersion`; ohne HTML `body_html` NULL und
     * `rendered_version` 0, Verhalten #2a), `edit_count` atomar plus 1, Bearbeiter, Grund und Zeit, eine Anweisung (PRIMARY).
     * Liefert `true`, wenn der Beitrag existiert. Version außerhalb 1 bis MAX_RENDERED_VERSION bei vorhandenem HTML:
     * `InvalidArgumentException`.
     */
    public function applyEdit(int $id, string $body, ?int $editorId, ?string $reason, string $nowUtc, ?string $bodyHtml = null, int $renderedVersion = 0): bool
    {
        $version = $bodyHtml === null ? 0 : self::version($renderedVersion);

        return $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET body = ?, body_html = ?, rendered_version = ?, edited_at = ?, edited_by = ?, edit_reason = ?,'
            . ' updated_at = ?, edit_count = edit_count + 1 WHERE id = ?',
            [$body, $bodyHtml, $version, $nowUtc, $editorId, $reason, $nowUtc, $id]
        ) === 1;
    }

    /**
     * Gerendertes HTML ablegen (Nachrendern beim Lesen und Job, Autocommit, PRIMARY, `updated_at` bleibt). Wächter: nur wenn
     * `edit_count` noch dem gelesenen Wert entspricht (keine Bearbeitung dazwischen) und die gespeicherte Version kleiner ist;
     * `$replaceCurrent` erneuert auch eine Zeile mit derselben Version (Job „alles neu“). Liefert die Zahl geänderter Zeilen
     * (0 = Wächter griff oder nichts geändert; MariaDB zählt unveränderte Zeilen nicht mit).
     */
    public function storeRendered(int $id, int $editCount, string $html, int $version, bool $replaceCurrent = false): int
    {
        $version = self::version($version);

        return $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET body_html = ?, rendered_version = ? WHERE id = ? AND edit_count = ? AND rendered_version < ?',
            [$html, $version, $id, $editCount, $replaceCurrent ? $version + 1 : $version]
        );
    }

    /**
     * Cache verwerfen, weil sich die Anhangsmenge ohne Textänderung geändert hat: `body_html` NULL, `rendered_version` 0 und
     * `edit_count` plus 1 (Invariante des Wächters, Vertrag DESIGN-2b R7). Liefert `true`, wenn der Beitrag existiert.
     */
    public function clearRendered(int $id, string $nowUtc): bool
    {
        return $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET body_html = NULL, rendered_version = 0, edit_count = edit_count + 1, updated_at = ? WHERE id = ?',
            [$nowUtc, $id]
        ) === 1;
    }

    /**
     * Fenster des Neu-Render-Jobs: Beiträge mit `$afterId < id <= $untilId` und `rendered_version < $belowVersion`, aufsteigend
     * (PRIMARY-Bereich; je Schritt liest der Job höchstens das Fenster). `$limit` 1 bis MAX_LIMIT.
     *
     * @return list<array{id: int, body: ?string, edit_count: int, user_id: ?int}>
     */
    public function renderBatch(int $afterId, int $untilId, int $belowVersion, int $limit): array
    {
        $rows = $this->fetchAll(
            'SELECT id, body, edit_count, user_id FROM ' . $this->table(self::TABLE) . ' WHERE id > ? AND id <= ? AND rendered_version < ? ORDER BY id ASC LIMIT ?',
            [$afterId, $untilId, $belowVersion, max(1, min(self::MAX_LIMIT, $limit))]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'body' => $row['body'] === null ? null : (string) $row['body'],
            'edit_count' => (int) $row['edit_count'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
        ], $rows);
    }

    /** Größte Beitrags-ID (0 bei leerer Tabelle; Ende des Job-Fensters und des Suchindex-Neuaufbaus). */
    public function maxId(): int
    {
        return (int) $this->fetchValue('SELECT MAX(id) FROM ' . $this->table(self::TABLE));
    }

    /**
     * Beiträge nach ID ohne Sperre: alle Spalten außer `ip_hash`, `body` und `body_html`, nach ID aufsteigend, Schlüssel = ID.
     * Fehlende IDs fehlen im Ergebnis. Stücke zu höchstens CHUNK IDs (PRIMARY).
     *
     * @param array<array-key, mixed> $ids
     * @return array<int, array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        return $this->many($ids, false);
    }

    /**
     * Wie `findMany()`, mit Zeilensperre (nur in einer Transaktion sinnvoll): je Stück eine sperrende `IN`-Abfrage mit `ORDER BY id ASC`
     * (`forUpdate()`), Stücke aufsteigend; InnoDB sperrt damit in Schlüsselfolge (Ebene 3 der Sperrfolge, Vertrag DESIGN-2b §3.10).
     *
     * @param array<array-key, mixed> $ids
     * @return array<int, array<string, mixed>>
     */
    public function lockMany(array $ids): array
    {
        return $this->many($ids, true);
    }

    /**
     * `ip_hash` je Beitrag; einzige Lesestelle der Spalte (nur für `ModerationService::ipHashes()` mit `view_private_data`).
     * Fehlende IDs fehlen im Ergebnis, aufsteigend nach ID (PRIMARY, Stücke zu CHUNK).
     *
     * @param array<array-key, mixed> $ids
     * @return array<int, ?string>
     */
    public function ipHashes(array $ids): array
    {
        $map = [];
        foreach ($this->chunks($ids) as $chunk) {
            foreach ($this->fetchAll(
                'SELECT id, ip_hash FROM ' . $this->table(self::TABLE) . ' WHERE id IN (' . self::placeholders(count($chunk)) . ') ORDER BY id ASC',
                $chunk
            ) as $row) {
                $map[(int) $row['id']] = $row['ip_hash'] === null ? null : (string) $row['ip_hash'];
            }
        }

        return $map;
    }

    /**
     * Welche der Beiträge haben mindestens einen Anhang? Aufsteigend, eine Abfrage je Stück über `idx_community_attachments_post`
     * (Delta von `topics.has_attachments`, Vertrag DESIGN-2b §3.11).
     *
     * @param array<array-key, mixed> $postIds
     * @return list<int>
     */
    public function withAttachments(array $postIds): array
    {
        $found = [];
        foreach ($this->chunks($postIds) as $chunk) {
            foreach ($this->fetchAll(
                'SELECT DISTINCT post_id FROM ' . $this->table(self::ATTACHMENTS) . ' WHERE post_id IN (' . self::placeholders(count($chunk)) . ')',
                $chunk
            ) as $row) {
                $found[] = (int) $row['post_id'];
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Gibt es im Thema einen veröffentlichten Beitrag mit Anhang? (Neubestimmung von `topics.has_attachments`, wenn ein Beitrag
     * mit Anhang die Veröffentlichung verliert oder das Thema verlässt; `idx_community_posts_topic`, dann `idx_community_attachments_post`.)
     */
    public function topicHasPublishedAttachments(int $topicId): bool
    {
        return $this->fetchValue(
            'SELECT p.id FROM ' . $this->table(self::TABLE) . ' p INNER JOIN ' . $this->table(self::ATTACHMENTS) . ' a ON a.post_id = p.id'
            . " WHERE p.topic_id = ? AND p.status = 'published' LIMIT 1",
            [$topicId]
        ) !== null;
    }

    /**
     * Jüngste Beiträge eines Mitglieds seit `$sinceUtc` mit Text (Spam-Dublette), alle Status, jüngste zuerst. Die vollständige
     * Statusliste ist Pflicht: nur mit ihr wählt der Optimierer `idx_community_posts_user` (Bereich) statt `idx_community_posts_created`.
     * `$limit` 1 bis 50.
     *
     * @return list<array{id: int, topic_id: int, status: string, status_reason: ?string, body: string}>
     */
    public function recentWithBody(int $userId, string $sinceUtc, int $limit = 20): array
    {
        $rows = $this->fetchAll(
            'SELECT id, topic_id, status, status_reason, body FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND status IN ('
            . self::placeholders(count(self::STATUSES)) . ') AND created_at >= ? ORDER BY created_at DESC, id DESC LIMIT ?',
            [$userId, ...self::STATUSES, $sinceUtc, max(1, min(50, $limit))]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'topic_id' => (int) $row['topic_id'],
            'status' => (string) $row['status'],
            'status_reason' => $row['status_reason'] === null ? null : (string) $row['status_reason'],
            'body' => (string) ($row['body'] ?? ''),
        ], $rows);
    }

    /**
     * Veröffentlichte Beiträge eines Themas je Autor (`idx_community_posts_topic`). `total` zählt auch Beiträge ohne Autor
     * (`user_id` NULL, gelöschtes Konto); `users` ist nach Mitglieds-ID aufsteigend.
     *
     * @return array{total: int, users: array<int, int>}
     */
    public function publishedByAuthor(int $topicId): array
    {
        $total = 0;
        $users = [];
        foreach ($this->fetchAll(
            'SELECT user_id, COUNT(*) AS posts FROM ' . $this->table(self::TABLE) . " WHERE topic_id = ? AND status = 'published' GROUP BY user_id",
            [$topicId]
        ) as $row) {
            $total += (int) $row['posts'];
            if ($row['user_id'] !== null) {
                $users[(int) $row['user_id']] = (int) $row['posts'];
            }
        }
        ksort($users);

        return ['total' => $total, 'users' => $users];
    }

    /** Alle Beiträge eines Themas in ein Forum umhängen (Thema verschieben; `idx_community_posts_topic`). Liefert `rowCount()`. */
    public function setTopicForum(int $topicId, int $forumId): int
    {
        return $this->run('UPDATE ' . $this->table(self::TABLE) . ' SET forum_id = ? WHERE topic_id = ?', [$forumId, $topicId]);
    }

    /**
     * Beiträge in ein anderes Thema verschieben (`topic_id` und `forum_id` des Ziels; PRIMARY, Stücke aufsteigend). Der erste Beitrag
     * eines Themas wandert nie. Liefert die Zahl verschobener Zeilen.
     *
     * @param array<array-key, mixed> $ids
     */
    public function moveToTopic(array $ids, int $topicId, int $forumId): int
    {
        $moved = 0;
        foreach ($this->chunks($ids) as $chunk) {
            $moved += $this->run(
                'UPDATE ' . $this->table(self::TABLE) . ' SET topic_id = ?, forum_id = ? WHERE id IN (' . self::placeholders(count($chunk)) . ') AND is_first_post = 0',
                [$topicId, $forumId, ...$chunk]
            );
        }

        return $moved;
    }

    /** Reaktionszähler atomar ändern (nie unter 0; PRIMARY). */
    public function bumpReactions(int $id, int $delta): void
    {
        $this->run('UPDATE ' . $this->table(self::TABLE) . ' SET ' . self::counter('reaction_count') . ' WHERE id = ?', [...self::counterParams($delta), $id]);
    }

    /**
     * Warteschlange: wartende Beiträge (auch erste Beiträge wartender Themen), älteste zuerst, Keyset (`created_at`, `id`).
     * `$forumIds` `null` = alle Foren, `[]` = keine (leere Seite ohne Abfrage). Zeilen: alle Spalten außer PRIVATE_COLUMNS, dazu
     * `topic_title` und `topic_status`. `Page::$next` ist der Cursor der letzten Zeile oder null. Index `idx_community_posts_queue`.
     *
     * @param array<array-key, mixed>|null $forumIds
     */
    public function queue(?array $forumIds, ?Cursor $after, int $limit): Page
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        [$filter, $params] = $this->queueFilter($forumIds, 'p.');
        if ($filter === null) {
            return new Page([], null);
        }
        if ($after !== null) {
            $filter .= ' AND (p.created_at > ? OR (p.created_at = ? AND p.id > ?))';
            array_push($params, $after->at, $after->at, $after->id);
        }
        $rows = $this->fetchAll(
            'SELECT ' . self::columnList(self::PRIVATE_COLUMNS, 'p.') . ', t.title AS topic_title, t.status AS topic_status FROM ' . $this->table(self::TABLE) . ' p'
            . ' INNER JOIN ' . $this->table(self::TOPICS) . " t ON t.id = p.topic_id WHERE p.status = 'pending'{$filter}"
            . ' ORDER BY p.created_at ASC, p.id ASC LIMIT ?',
            [...$params, $limit + 1]
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new Page($rows, $more && $last !== null ? (new Cursor((string) $last['created_at'], (int) $last['id']))->encode() : null);
    }

    /**
     * Größe der Warteschlange (höchstens QUEUE_COUNT_MAX) und Zeit des ältesten wartenden Beitrags; `$forumIds` wie bei `queue()`.
     *
     * @param array<array-key, mixed>|null $forumIds
     * @return array{count: int, oldest_at: ?string}
     */
    public function queueSummary(?array $forumIds): array
    {
        [$filter, $params] = $this->queueFilter($forumIds, '');
        if ($filter === null) {
            return ['count' => 0, 'oldest_at' => null];
        }
        $table = $this->table(self::TABLE);
        $count = (int) $this->fetchValue(
            "SELECT COUNT(*) FROM (SELECT id FROM {$table} WHERE status = 'pending'{$filter} LIMIT ?) q",
            [...$params, self::QUEUE_COUNT_MAX]
        );
        $oldest = $this->fetchValue("SELECT created_at FROM {$table} WHERE status = 'pending'{$filter} ORDER BY created_at ASC, id ASC LIMIT 1", $params);

        return ['count' => $count, 'oldest_at' => $oldest === null ? null : (string) $oldest];
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
     * Eigene Antworten eines Mitglieds (ohne Themenstart) mit genau einem Status, jüngste zuerst (Keyset über `created_at`, `id`; Index
     * `idx_community_posts_user`). Zeilen mit Text, aber ohne `ip_hash`; die Rechtefilterung nach Forum übernimmt der Aufrufer.
     */
    public function listRepliesByUser(int $userId, string $status = 'published', ?Cursor $after = null, int $limit = 20): Page
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $status = in_array($status, ['published', 'pending'], true) ? $status : 'published';
        $sql = 'SELECT ' . self::columnList(self::PRIVATE_COLUMNS) . ' FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND status = ? AND is_first_post = 0';
        $params = [$userId, $status];
        if ($after !== null) {
            $sql .= ' AND (created_at < ? OR (created_at = ? AND id < ?))';
            array_push($params, $after->at, $after->at, $after->id);
        }
        $rows = $this->fetchAll($sql . ' ORDER BY created_at DESC, id DESC LIMIT ?', [...$params, $limit + 1]);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new Page($rows, $more && $last !== null ? (new Cursor((string) $last['created_at'], (int) $last['id']))->encode() : null);
    }

    /** Größte Position, die `idAtPosition()` auflöst (Tiefe der Seitenadresse `seite-N` eines Themas). */
    public const POSITION_MAX = 5000;

    /**
     * ID des `$position`-ten veröffentlichten Beitrags eines Themas (1 = Themenstart), `null` hinter dem Ende oder über POSITION_MAX.
     * Für die Seitenadresse `seite-N`: liest höchstens POSITION_MAX IDs eines einzigen Themas aus dem Index (`topic_id`, `status`, `id`)
     * ohne Zeilenversatz; die Kosten wachsen nur mit der Seitentiefe dieses Themas, nicht mit der Größe des Forums.
     */
    public function idAtPosition(int $topicId, int $position): ?int
    {
        if ($position < 1 || $position > self::POSITION_MAX) {
            return null;
        }
        $rows = $this->fetchAll(
            'SELECT id FROM ' . $this->table(self::TABLE) . " WHERE topic_id = ? AND status = 'published' ORDER BY id ASC LIMIT ?",
            [$topicId, $position]
        );
        if (count($rows) < $position) {
            return null;
        }

        return (int) $rows[$position - 1]['id'];
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
     * Gemeinsamer Teil von `findMany()` und `lockMany()`.
     *
     * @param array<array-key, mixed> $ids
     * @return array<int, array<string, mixed>>
     */
    private function many(array $ids, bool $lock): array
    {
        $rows = [];
        foreach ($this->chunks($ids) as $chunk) {
            foreach ($this->fetchAll(
                'SELECT ' . self::columnList([...self::PRIVATE_COLUMNS, ...self::TEXT_COLUMNS]) . ' FROM ' . $this->table(self::TABLE)
                . ' WHERE id IN (' . self::placeholders(count($chunk)) . ') ORDER BY id ASC' . ($lock ? self::forUpdate() : ''),
                $chunk
            ) as $row) {
                $rows[(int) $row['id']] = $row;
            }
        }

        return $rows;
    }

    /**
     * IDs normalisieren (positiv, eindeutig), aufsteigend sortieren und in Stücke zu höchstens CHUNK teilen.
     *
     * @param array<array-key, mixed> $ids
     * @return list<list<int>>
     */
    private function chunks(array $ids): array
    {
        $ids = self::ids($ids);
        sort($ids);

        return array_chunk($ids, self::CHUNK);
    }

    /**
     * Spaltenliste ohne die genannten Spalten (Allowlist COLUMNS, optional mit Tabellenalias).
     *
     * @param list<string> $without
     */
    private static function columnList(array $without, string $alias = ''): string
    {
        return implode(', ', array_map(static fn (string $column): string => $alias . $column, array_values(array_diff(self::COLUMNS, $without))));
    }

    /**
     * Forenfilter der Warteschlange: `null` = alle Foren (kein Filter), leere Liste = keine Zeile (Filter `null`).
     *
     * @param array<array-key, mixed>|null $forumIds
     * @return array{0: string|null, 1: list<int>}
     */
    private function queueFilter(?array $forumIds, string $alias): array
    {
        if ($forumIds === null) {
            return ['', []];
        }
        $ids = self::ids($forumIds);
        sort($ids);

        return $ids === [] ? [null, []] : [' AND ' . $alias . 'forum_id IN (' . self::placeholders(count($ids)) . ')', $ids];
    }

    /** Version des HTML-Caches prüfen (1 bis MAX_RENDERED_VERSION). */
    private static function version(int $version): int
    {
        if ($version < 1 || $version > self::MAX_RENDERED_VERSION) {
            throw new \InvalidArgumentException('Ungültige Render-Version: ' . $version);
        }

        return $version;
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
