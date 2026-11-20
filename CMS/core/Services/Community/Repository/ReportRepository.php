<?php
/**
 * Meldungen (`community_reports`, PR #2b): Anlegen, Zusammenführen, Grenze je Melder, Erledigen, Bündel für die Moderation und
 * Umhängen beim Verschieben.
 *
 * - Keine Uhr: Zeiten sind UTC-Texte vom Aufrufer; `recentByReporter()` liefert die älteste Meldung als UTC-Text, die Umrechnung
 *   übernimmt der Dienst (`CommunityAccess::utcTimestamp()`, Vertrag DESIGN-2b §1 Nr. 10).
 * - Erledigen in zwei Schritten (Vertrag §3.7, §4.4, Kritik R11): `openIdsForTarget()` liest die IDs im Autocommit vor der Transaktion
 *   (`idx_community_reports_target`), `lockOpenByIds()` sperrt sie als erste Anweisung über PRIMARY und prüft dabei erneut, dass sie
 *   offen sind. Eine Sperre über PRIMARY trifft nur vorhandene Zeilen (keine Lückensperre): neue Meldungen zum selben Ziel warten nicht.
 * - Umhängen (`reassignTopic()`, `reassignPosts()`) ruft die Moderation in ihrer Transaktion nach allen Sperren der Ebenen 1 bis 5 auf;
 *   es ändert nur offene Meldungen, erledigte behalten ihr Forum.
 * - Zielarten, Gründe und Status kommen aus TARGET_TYPES, REASONS und STATUSES; ein anderer Wert ist ein Programmfehler
 *   (`InvalidArgumentException`). `$forumIds = null` heißt alle Foren (globale Moderation, auch Ziele `user` ohne Forum), eine leere
 *   Liste ergibt ein leeres Ergebnis ohne Abfrage.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class ReportRepository extends AbstractRepository
{
    /** Zielarten (`ReportService::TARGET_TYPES`). */
    public const TARGET_TYPES = ['post', 'topic', 'user'];
    /** Gründe in Anzeigereihenfolge (`ReportService::REASONS`). */
    public const REASONS = ['spam', 'abuse', 'offtopic', 'illegal', 'other'];
    /** Status (`ReportService::STATUSES`); erledigt ist jeder Status außer `open`. */
    public const STATUSES = ['open', 'valid', 'invalid', 'resolved'];
    /** IDs je `IN`-Liste und Sperrstapel; Listen mit `$limit` höchstens BATCH + 1. */
    public const BATCH = 500;
    /** Größter Deckel von `countOpen()`. */
    public const COUNT_MAX = 1000;

    private const TABLE = 'community_reports';
    private const POSTS = 'community_posts';

    /** Alle Spalten in Schemareihenfolge. */
    private const COLUMNS = 'id, target_type, target_id, forum_id, reporter_id, reason, message, status, resolution_note, notify_reporter, handled_by, handled_at, created_at';

    /** Spalten beim Anlegen. */
    private const INSERTABLE = ['target_type', 'target_id', 'forum_id', 'reporter_id', 'reason', 'message', 'status', 'notify_reporter', 'created_at'];

    /** Offene Meldungen eines Ziels (Parameter: Zielart, Ziel-ID), Index `idx_community_reports_target`. */
    private const OPEN_TARGET = "target_type = ? AND target_id = ? AND status = 'open'";

    /** Umhängen: nur offene Meldungen, die noch nicht im Zielforum liegen (Parameter: Zielforum; Zahl = wirklich umgehängte Zeilen). */
    private const REASSIGN = " AND status = 'open' AND (forum_id IS NULL OR forum_id <> ?)";

    /** Größte Länge von `message` und `resolution_note` in Byte (TEXT). */
    private const MAX_TEXT_BYTES = 65535;

    /** Größte ID einer INT-UNSIGNED-Spalte. */
    private const MAX_UINT = 4294967295;

    /**
     * Eine Meldung mit allen Spalten oder `null`; IDs und `notify_reporter` als Ganzzahl, NULL bleibt `null`.
     *
     * @return array{id: int, target_type: string, target_id: int, forum_id: int|null, reporter_id: int|null, reason: string, message: string|null, status: string, resolution_note: string|null, notify_reporter: int, handled_by: int|null, handled_at: string|null, created_at: string}|null
     */
    public function find(int $id): ?array
    {
        $row = $this->fetchOne('SELECT ' . self::COLUMNS . ' FROM ' . $this->table(self::TABLE) . ' WHERE id = ? LIMIT 1', [$id]);

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'target_type' => (string) $row['target_type'],
            'target_id' => (int) $row['target_id'],
            'forum_id' => self::nullableInt($row['forum_id']),
            'reporter_id' => self::nullableInt($row['reporter_id']),
            'reason' => (string) $row['reason'],
            'message' => self::nullableText($row['message']),
            'status' => (string) $row['status'],
            'resolution_note' => self::nullableText($row['resolution_note']),
            'notify_reporter' => (int) $row['notify_reporter'],
            'handled_by' => self::nullableInt($row['handled_by']),
            'handled_at' => self::nullableText($row['handled_at']),
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * Offene Meldung desselben Melders zum selben Ziel (Zusammenführen), Index `idx_community_reports_reporter`.
     *
     * @return array{id: int, message: string|null}|null
     */
    public function openOfReporter(int $reporterId, string $targetType, int $targetId): ?array
    {
        self::assertTargetType($targetType);
        $row = $this->fetchOne(
            'SELECT id, message FROM ' . $this->table(self::TABLE) . " WHERE reporter_id = ? AND target_type = ? AND target_id = ? AND status = 'open' ORDER BY id ASC LIMIT 1",
            [$reporterId, $targetType, $targetId]
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'message' => self::nullableText($row['message'])];
    }

    /**
     * Meldungen eines Melders seit `$sinceUtc` einschließlich (jeder Status) und die älteste davon als UTC-Text (Meldegrenze unter der
     * Statistiksperre des Melders). Index `idx_community_reports_reporter`.
     *
     * @return array{count: int, oldest: string|null}
     */
    public function recentByReporter(int $reporterId, string $sinceUtc): array
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM ' . $this->table(self::TABLE) . ' WHERE reporter_id = ? AND created_at >= ?',
            [$reporterId, $sinceUtc]
        );

        return ['count' => (int) ($row['n'] ?? 0), 'oldest' => self::nullableText($row['oldest'] ?? null)];
    }

    /**
     * Legt eine Meldung an und liefert die ID. Pflicht: `target_type`, `target_id`, `reason`, `created_at`; `status` Standard `open`,
     * `notify_reporter` Standard 1, `message` `''` wird NULL. Verstoß: `InvalidArgumentException`, nichts geschrieben.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $data = self::pick($data, self::INSERTABLE);
        foreach (['target_type', 'target_id', 'reason', 'created_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \InvalidArgumentException('Meldung ohne ' . $key . '.');
            }
        }
        self::assertTargetType($data['target_type']);
        if (!in_array($data['reason'], self::REASONS, true)) {
            throw new \InvalidArgumentException('Unbekannter Meldegrund.');
        }
        $status = $data['status'] ?? 'open';
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unbekannter Meldestatus.');
        }
        $notify = $data['notify_reporter'] ?? 1;
        if (!in_array($notify, [true, false, 0, 1], true)) {
            throw new \InvalidArgumentException('notify_reporter ist weder 0 noch 1.');
        }

        return $this->insertRow(self::TABLE, [
            'target_type' => $data['target_type'],
            'target_id' => self::id($data['target_id'], PHP_INT_MAX, 'target_id'),
            'forum_id' => ($data['forum_id'] ?? null) === null ? null : self::id($data['forum_id'], self::MAX_UINT, 'forum_id'),
            'reporter_id' => ($data['reporter_id'] ?? null) === null ? null : self::id($data['reporter_id'], self::MAX_UINT, 'reporter_id'),
            'reason' => $data['reason'],
            'message' => self::text($data['message'] ?? null, 'message'),
            'status' => $status,
            'notify_reporter' => $notify ? 1 : 0,
            'created_at' => self::utcText($data['created_at'], 'created_at'),
        ], self::INSERTABLE);
    }

    /** Setzt den Text einer offenen Meldung (der Dienst hängt an). `true` = geändert; `false`, wenn die Meldung erledigt ist oder fehlt. */
    public function appendMessage(int $id, string $message): bool
    {
        return $this->run(
            'UPDATE ' . $this->table(self::TABLE) . " SET message = ? WHERE id = ? AND status = 'open'",
            [self::text($message, 'message'), $id]
        ) > 0;
    }

    /**
     * Verschiedene Melder offener Meldungen eines Ziels, aufsteigend, ohne NULL (gelöschte Konten), höchstens `$limit` (1 bis BATCH + 1).
     *
     * @return list<int>
     */
    public function openReporters(string $targetType, int $targetId, int $limit): array
    {
        self::assertTargetType($targetType);
        $rows = $this->fetchAll(
            'SELECT DISTINCT reporter_id FROM ' . $this->table(self::TABLE) . ' WHERE ' . self::OPEN_TARGET . ' AND reporter_id IS NOT NULL ORDER BY reporter_id ASC LIMIT ?',
            [$targetType, $targetId, self::limit($limit)]
        );

        return array_map(static fn (array $row): int => (int) $row['reporter_id'], $rows);
    }

    /**
     * IDs offener Meldungen eines Ziels bis `$upToId` einschließlich (`null` = alle), aufsteigend, höchstens `$limit` (1 bis BATCH + 1).
     * Im Autocommit vor der Transaktion, die sie mit `lockOpenByIds()` sperrt; Index `idx_community_reports_target`.
     *
     * @return list<int>
     */
    public function openIdsForTarget(string $targetType, int $targetId, ?int $upToId, int $limit): array
    {
        self::assertTargetType($targetType);
        $params = [$targetType, $targetId];
        if ($upToId !== null) {
            $params[] = $upToId;
        }
        $params[] = self::limit($limit);
        $rows = $this->fetchAll(
            'SELECT id FROM ' . $this->table(self::TABLE) . ' WHERE ' . self::OPEN_TARGET . ($upToId === null ? '' : ' AND id <= ?') . ' ORDER BY id ASC LIMIT ?',
            $params
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Sperrt die genannten Meldungen über PRIMARY, soweit sie noch offen sind (erste Anweisung der Transaktion, Ebene 6): IDs eindeutig
     * und aufsteigend, je Stück von höchstens BATCH IDs eine Anweisung. Erledigte und fehlende IDs fehlen im Ergebnis.
     *
     * @param array<array-key, mixed> $ids
     * @return list<array{id: int, reporter_id: int|null, forum_id: int|null}>
     */
    public function lockOpenByIds(array $ids): array
    {
        $locked = [];
        foreach (self::batches($ids) as $batch) {
            $rows = $this->fetchAll(
                'SELECT id, reporter_id, forum_id FROM ' . $this->table(self::TABLE) . ' WHERE id IN (' . self::placeholders(count($batch)) . ")"
                . " AND status = 'open' ORDER BY id ASC" . self::forUpdate(),
                $batch
            );
            foreach ($rows as $row) {
                $locked[] = ['id' => (int) $row['id'], 'reporter_id' => self::nullableInt($row['reporter_id']), 'forum_id' => self::nullableInt($row['forum_id'])];
            }
        }

        return $locked;
    }

    /**
     * Schließt die genannten offenen Meldungen (Status außer `open`, Notiz `''` wird NULL). Liefert die Zahl geschlossener Meldungen;
     * erledigte bleiben unverändert. Je Stück von höchstens BATCH IDs eine Anweisung über PRIMARY.
     *
     * @param array<array-key, mixed> $ids
     */
    public function close(array $ids, string $status, ?string $note, int $handledBy, string $nowUtc): int
    {
        if ($status === 'open' || !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Ungültiger Abschlussstatus.');
        }
        $handledBy = self::id($handledBy, self::MAX_UINT, 'handled_by');
        $note = self::text($note, 'resolution_note');
        $closed = 0;
        foreach (self::batches($ids) as $batch) {
            $closed += $this->run(
                'UPDATE ' . $this->table(self::TABLE) . ' SET status = ?, resolution_note = ?, handled_by = ?, handled_at = ?'
                . ' WHERE id IN (' . self::placeholders(count($batch)) . ") AND status = 'open'",
                [$status, $note, $handledBy, $nowUtc, ...$batch]
            );
        }

        return $closed;
    }

    /** Gibt es offene Meldungen zum Ziel? Index `idx_community_reports_target`. */
    public function hasOpen(string $targetType, int $targetId): bool
    {
        self::assertTargetType($targetType);

        return $this->fetchValue('SELECT id FROM ' . $this->table(self::TABLE) . ' WHERE ' . self::OPEN_TARGET . ' LIMIT 1', [$targetType, $targetId]) !== null;
    }

    /**
     * Offene Bündel (je Ziel) nach ältester Meldung, Keyset `first_id > $afterFirstId`, höchstens `$limit` (1 bis BATCH + 1).
     * `$forumIds`: `null` = alle (auch Ziele ohne Forum), sonst nur Meldungen dieser Foren. `$targetType`: `null` = alle Zielarten.
     *
     * @param list<int>|null $forumIds
     * @return list<array{target_type: string, target_id: int, forum_id: int|null, first_id: int, last_id: int, reports: int, reporters: int, first_at: string, last_at: string}>
     */
    public function openBundles(?array $forumIds, ?string $targetType, int $afterFirstId, int $limit): array
    {
        if ($targetType !== null) {
            self::assertTargetType($targetType);
        }
        [$where, $params] = self::forumCondition($forumIds);
        if ($where === null) {
            return [];
        }
        if ($targetType !== null) {
            $where .= ' AND target_type = ?';
            $params[] = $targetType;
        }
        $rows = $this->fetchAll(
            'SELECT target_type, target_id, MAX(forum_id) AS forum_id, MIN(id) AS first_id, MAX(id) AS last_id, COUNT(*) AS reports,'
            . ' COUNT(DISTINCT reporter_id) AS reporters, MIN(created_at) AS first_at, MAX(created_at) AS last_at FROM ' . $this->table(self::TABLE)
            . " WHERE status = 'open'" . $where . ' GROUP BY target_type, target_id HAVING MIN(id) > ? ORDER BY first_id ASC LIMIT ?',
            [...$params, max(0, $afterFirstId), self::limit($limit)]
        );

        return array_map(static fn (array $row): array => [
            'target_type' => (string) $row['target_type'],
            'target_id' => (int) $row['target_id'],
            'forum_id' => self::nullableInt($row['forum_id']),
            'first_id' => (int) $row['first_id'],
            'last_id' => (int) $row['last_id'],
            'reports' => (int) $row['reports'],
            'reporters' => (int) $row['reporters'],
            'first_at' => (string) $row['first_at'],
            'last_at' => (string) $row['last_at'],
        ], $rows);
    }

    /**
     * Gründe offener Meldungen je Ziel: `"<typ>:<id>" => [Grund => Zahl]`, Ziele in der Reihenfolge von `$targets` (ohne offene Meldung
     * fehlen sie), Gründe in der Reihenfolge von REASONS. Je Zielart und Stück von höchstens BATCH IDs eine Abfrage.
     *
     * @param list<array{0: string, 1: int}> $targets
     * @return array<string, array<string, int>>
     */
    public function reasonCounts(array $targets): array
    {
        $byType = [];
        $order = [];
        foreach ($targets as $target) {
            $type = $target[0] ?? null;
            self::assertTargetType($type);
            $id = (int) ($target[1] ?? 0);
            if ($id > 0) {
                $byType[$type][] = $id;
                $order[$type . ':' . $id] = true;
            }
        }
        $counts = [];
        foreach ($byType as $type => $ids) {
            foreach (self::batches($ids) as $batch) {
                $rows = $this->fetchAll(
                    'SELECT target_id, reason, COUNT(*) AS n FROM ' . $this->table(self::TABLE) . " WHERE target_type = ? AND target_id IN ("
                    . self::placeholders(count($batch)) . ") AND status = 'open' GROUP BY target_id, reason",
                    [$type, ...$batch]
                );
                foreach ($rows as $row) {
                    $counts[$type . ':' . (int) $row['target_id']][(string) $row['reason']] = (int) $row['n'];
                }
            }
        }
        $result = [];
        foreach (array_keys($order) as $key) {
            if (!isset($counts[$key])) {
                continue;
            }
            $known = array_intersect_key(array_flip(self::REASONS), $counts[$key]);
            $result[$key] = array_replace(array_map(static fn (): int => 0, $known), $counts[$key]);
        }

        return $result;
    }

    /**
     * Offene Meldungen eines Ziels mit Melder, Grund und Text (Bündel für die Moderation), aufsteigend, höchstens `$limit`
     * (1 bis BATCH + 1). Index `idx_community_reports_target`.
     *
     * @return list<array{id: int, reporter_id: int|null, reason: string, message: string|null, notify_reporter: bool, forum_id: int|null, created_at: string}>
     */
    public function openForTarget(string $targetType, int $targetId, int $limit): array
    {
        self::assertTargetType($targetType);
        $rows = $this->fetchAll(
            'SELECT id, reporter_id, reason, message, notify_reporter, forum_id, created_at FROM ' . $this->table(self::TABLE)
            . ' WHERE ' . self::OPEN_TARGET . ' ORDER BY id ASC LIMIT ?',
            [$targetType, $targetId, self::limit($limit)]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'reporter_id' => self::nullableInt($row['reporter_id']),
            'reason' => (string) $row['reason'],
            'message' => self::nullableText($row['message']),
            'notify_reporter' => (int) $row['notify_reporter'] === 1,
            'forum_id' => self::nullableInt($row['forum_id']),
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * Zahl offener Meldungen (Zeilen, nicht Bündel), höchstens `$cap` (1 bis COUNT_MAX). Index `idx_community_reports_open` bzw. `_forum`.
     *
     * @param list<int>|null $forumIds
     */
    public function countOpen(?array $forumIds, int $cap): int
    {
        [$where, $params] = self::forumCondition($forumIds);
        if ($where === null) {
            return 0;
        }

        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM (SELECT id FROM ' . $this->table(self::TABLE) . " WHERE status = 'open'" . $where . ' LIMIT ?) b',
            [...$params, max(1, min(self::COUNT_MAX, $cap))]
        );
    }

    /**
     * Hängt die offenen Meldungen eines Themas und seiner Beiträge in ein anderes Forum um (Thema verschoben). Liefert die Zahl
     * umgehängter Meldungen; erledigte bleiben. Erst die IDs der Beitragsmeldungen über den Join auf das Thema
     * (`idx_community_posts_topic`, dann `idx_community_reports_target`), dann je Stück von höchstens BATCH IDs ein `UPDATE` über PRIMARY,
     * zuletzt die Meldungen zum Thema selbst. Aufruf in der Transaktion der Moderation nach allen Sperren der Ebenen 1 bis 5.
     */
    public function reassignTopic(int $topicId, int $forumId): int
    {
        $forumId = self::id($forumId, self::MAX_UINT, 'forum_id');
        $rows = $this->fetchAll(
            'SELECT r.id FROM ' . $this->table(self::TABLE) . ' r JOIN ' . $this->table(self::POSTS) . ' p ON p.id = r.target_id'
            . " WHERE p.topic_id = ? AND r.target_type = 'post' AND r.status = 'open' ORDER BY r.id ASC",
            [$topicId]
        );
        $moved = 0;
        foreach (array_chunk(array_map(static fn (array $row): int => (int) $row['id'], $rows), self::BATCH) as $batch) {
            $moved += $this->run(
                'UPDATE ' . $this->table(self::TABLE) . ' SET forum_id = ? WHERE id IN (' . self::placeholders(count($batch)) . ')' . self::REASSIGN,
                [$forumId, ...$batch, $forumId]
            );
        }

        return $moved + $this->run(
            'UPDATE ' . $this->table(self::TABLE) . " SET forum_id = ? WHERE target_type = 'topic' AND target_id = ?" . self::REASSIGN,
            [$forumId, $topicId, $forumId]
        );
    }

    /**
     * Hängt die offenen Meldungen der genannten Beiträge in ein anderes Forum um (Beiträge verschoben). Liefert die Zahl umgehängter
     * Meldungen; erledigte bleiben. Je Stück von höchstens BATCH IDs ein `UPDATE` über `idx_community_reports_target`.
     *
     * @param array<array-key, mixed> $postIds
     */
    public function reassignPosts(array $postIds, int $forumId): int
    {
        $forumId = self::id($forumId, self::MAX_UINT, 'forum_id');
        $moved = 0;
        foreach (self::batches($postIds) as $batch) {
            $moved += $this->run(
                'UPDATE ' . $this->table(self::TABLE) . " SET forum_id = ? WHERE target_type = 'post' AND target_id IN (" . self::placeholders(count($batch)) . ')' . self::REASSIGN,
                [$forumId, ...$batch, $forumId]
            );
        }

        return $moved;
    }

    /**
     * Forenfilter: `[null, []]` bei leerer Liste (kein Ergebnis), `['', []]` ohne Filter, sonst die Bedingung mit IDs aufsteigend.
     *
     * @param list<int>|null $forumIds
     * @return array{0: string|null, 1: list<int>}
     */
    private static function forumCondition(?array $forumIds): array
    {
        if ($forumIds === null) {
            return ['', []];
        }
        $ids = self::ids($forumIds);
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return [null, []];
        }

        return [' AND forum_id IN (' . self::placeholders(count($ids)) . ')', $ids];
    }

    /**
     * IDs eindeutig, aufsteigend, in Stücken zu höchstens BATCH.
     *
     * @param array<array-key, mixed> $ids
     * @return list<list<int>>
     */
    private static function batches(array $ids): array
    {
        $ids = self::ids($ids);
        sort($ids, SORT_NUMERIC);

        return array_chunk($ids, self::BATCH);
    }

    /** Grenze einer Liste: 1 bis BATCH + 1. */
    private static function limit(int $limit): int
    {
        return max(1, min(self::BATCH + 1, $limit));
    }

    private static function assertTargetType(mixed $targetType): void
    {
        if (!in_array($targetType, self::TARGET_TYPES, true)) {
            throw new \InvalidArgumentException('Unbekannte Zielart einer Meldung.');
        }
    }

    /** Ganzzahl von 1 bis `$max`. */
    private static function id(mixed $value, int $max, string $field): int
    {
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new \InvalidArgumentException('Ungültige ID in ' . $field . '.');
        }

        return $value;
    }

    /** Freitext für eine TEXT-Spalte: `null` und `''` werden NULL; ungültiges UTF-8 oder zu lang: `InvalidArgumentException`. */
    private static function text(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (self::exceeds([$field => $value], [$field => ['bytes', self::MAX_TEXT_BYTES, true]])) {
            throw new \InvalidArgumentException($field . ' ungültig oder länger als ' . self::MAX_TEXT_BYTES . ' Byte.');
        }

        return $value;
    }

    /** UTC-Zeit `Y-m-d H:i:s`. */
    private static function utcText(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Ungültige Zeit in ' . $field . ' (erwartet UTC Y-m-d H:i:s).');
        }

        return $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableText(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
