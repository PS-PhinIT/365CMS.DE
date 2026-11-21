<?php
/**
 * Abos (`community_subscriptions`), Ignorieren-Liste (`community_ignores`, nur lesend) und Zustell-Warteschlange der
 * Benachrichtigungen (`community_notify_queue`, PR #2b; Vertrag DESIGN-2b §3.7, §3.9, §4.5; Entwurf interaktion §1.1, §2.7 bis §2.9).
 *
 * Regeln:
 * - Abos: Schlüssel (user_id, target_type, target_id). `notified_at` ist das Tor „eine Mail je Ziel bis zum Besuch“: `claimMail()`
 *   setzt es per bedingtem UPDATE (CAS auf den gelesenen Wert), `releaseMail()` nimmt die Beanspruchung zurück. Empfänger eines
 *   Ziels liest `recipients()` per Keyset über `idx_community_subscriptions_target` (InnoDB hängt den Primärschlüssel an, also kein
 *   Filesort).
 * - Warteschlange: eine Zeile je Ereignis. Ein Lauf belegt einen Auftrag mit Token und Frist (`lease()`), schreibt nur mit
 *   seinem Token (`advance()`, `extend()`, `release()`, `finish()`); jede Schreibanweisung erhöht `steps`, damit `rowCount()` auch
 *   unter MariaDB (zählt geänderte Zeilen) verlässlich ist. Fällige Aufträge liest `dueIds()` je Stufe über
 *   `idx_community_notify_queue_kind`.
 * - Eingaben außerhalb der Allowlists (Zieltyp, Modus, Art, Grenze, IDs) sind Programmierfehler: `InvalidArgumentException`.
 * - Keine Uhr: Zeiten kommen als UTC-Texte vom Aufrufer. Keine Transaktion: die Dienste rahmen.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class SubscriptionRepository extends AbstractRepository
{
    public const TARGET_TYPES = ['topic', 'forum'];
    /** Modi laut Schema; P1 schreibt nur `instant` und `none` (`SubscriptionService::P1_MODES`). */
    public const MODES = ['instant', 'daily', 'weekly', 'none'];
    /** Arten der Zustellaufträge (Spalte `kind`). */
    public const KINDS = ['reply', 'new_topic', 'reaction', 'solution', 'moderation', 'report'];
    /** Größte Seite je Abfrage. */
    public const MAX_LIMIT = 500;

    private const SUBSCRIPTIONS = 'community_subscriptions';
    private const IGNORES = 'community_ignores';
    private const QUEUE = 'community_notify_queue';
    /** IDs je Mengenabfrage. */
    private const MAX_IDS = 500;
    /** Größte ID einer INT-UNSIGNED-Spalte. */
    private const MAX_UINT = 4294967295;
    /** Längen der Spalten `detail` und `lease_token`. */
    private const DETAIL_MAX = 20;
    private const TOKEN_PATTERN = '/^[0-9a-f]{32}$/D';

    // ── Abos ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{mode: string, notified_at: ?string, created_at: string}|null */
    public function find(int $userId, string $targetType, int $targetId): ?array
    {
        self::target($userId, $targetType, $targetId);
        $row = $this->fetchOne(
            'SELECT mode, notified_at, created_at FROM ' . $this->table(self::SUBSCRIPTIONS) . ' WHERE user_id = ? AND target_type = ? AND target_id = ? LIMIT 1',
            [$userId, $targetType, $targetId]
        );

        return $row === null ? null : ['mode' => (string) $row['mode'], 'notified_at' => self::text($row['notified_at']), 'created_at' => (string) $row['created_at']];
    }

    /** Legt ein Abo an (`INSERT IGNORE`, PRIMARY); vorhandene Abos bleiben unverändert. `true` = neu angelegt. */
    public function insert(int $userId, string $targetType, int $targetId, string $mode, string $nowUtc): bool
    {
        self::target($userId, $targetType, $targetId);
        self::mode($mode);

        return $this->run(
            'INSERT IGNORE INTO ' . $this->table(self::SUBSCRIPTIONS) . ' (user_id, target_type, target_id, mode, notified_at, created_at) VALUES (?, ?, ?, ?, NULL, ?)',
            [$userId, $targetType, $targetId, $mode, $nowUtc]
        ) > 0;
    }

    /** Ändert den Modus (`notified_at` bleibt). `true` = geändert; ein fehlendes Abo oder derselbe Modus ergeben `false`. */
    public function setMode(int $userId, string $targetType, int $targetId, string $mode): bool
    {
        self::target($userId, $targetType, $targetId);
        self::mode($mode);

        return $this->run(
            'UPDATE ' . $this->table(self::SUBSCRIPTIONS) . ' SET mode = ? WHERE user_id = ? AND target_type = ? AND target_id = ? AND mode <> ?',
            [$mode, $userId, $targetType, $targetId, $mode]
        ) > 0;
    }

    /** Entfernt ein Abo. `true` = entfernt. */
    public function delete(int $userId, string $targetType, int $targetId): bool
    {
        self::target($userId, $targetType, $targetId);

        return $this->run(
            'DELETE FROM ' . $this->table(self::SUBSCRIPTIONS) . ' WHERE user_id = ? AND target_type = ? AND target_id = ?',
            [$userId, $targetType, $targetId]
        ) > 0;
    }

    /**
     * Abos eines Mitglieds, nach Zieltyp und Ziel aufsteigend, Keyset über PRIMARY. `$after` = [Zieltyp, Ziel] der letzten Zeile.
     *
     * @param array{0: string, 1: int}|null $after
     * @return list<array{target_type: string, target_id: int, mode: string, notified_at: ?string, created_at: string}>
     */
    public function forUser(int $userId, ?array $after, int $limit): array
    {
        self::id($userId);
        self::limit($limit);
        $sql = 'SELECT target_type, target_id, mode, notified_at, created_at FROM ' . $this->table(self::SUBSCRIPTIONS) . ' WHERE user_id = ?';
        $params = [$userId];
        if ($after !== null) {
            $type = (string) ($after[0] ?? '');
            $id = (int) ($after[1] ?? 0);
            if (!in_array($type, self::TARGET_TYPES, true) || $id < 0 || $id > self::MAX_UINT) {
                throw new \InvalidArgumentException('Ungültiger Cursor der Abos.');
            }
            $sql .= ' AND (target_type > ? OR (target_type = ? AND target_id > ?))';
            array_push($params, $type, $type, $id);
        }
        $rows = $this->fetchAll($sql . ' ORDER BY target_type ASC, target_id ASC LIMIT ?', [...$params, $limit]);

        return array_map(static fn (array $row): array => [
            'target_type' => (string) $row['target_type'],
            'target_id' => (int) $row['target_id'],
            'mode' => (string) $row['mode'],
            'notified_at' => self::text($row['notified_at']),
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * Abonnenten eines Ziels mit Modus, aufsteigend nach Mitglied ab `$afterUserId` (ausschließlich), Index
     * `idx_community_subscriptions_target` samt Primärschlüssel (kein Filesort).
     *
     * @return list<array{user_id: int, notified_at: ?string}>
     */
    public function recipients(string $targetType, int $targetId, string $mode, int $afterUserId, int $limit): array
    {
        self::targetType($targetType);
        self::id($targetId);
        self::mode($mode);
        self::limit($limit);
        if ($afterUserId < 0 || $afterUserId > self::MAX_UINT) {
            throw new \InvalidArgumentException('Ungültiger Cursor der Empfänger.');
        }
        $rows = $this->fetchAll(
            'SELECT user_id, notified_at FROM ' . $this->table(self::SUBSCRIPTIONS)
            . ' WHERE target_type = ? AND target_id = ? AND mode = ? AND user_id > ? ORDER BY user_id ASC LIMIT ?',
            [$targetType, $targetId, $mode, $afterUserId, $limit]
        );

        return array_map(static fn (array $row): array => ['user_id' => (int) $row['user_id'], 'notified_at' => self::text($row['notified_at'])], $rows);
    }

    /**
     * Beansprucht das Mail-Tor (CAS auf `notified_at`, PRIMARY): gelingt nur, wenn der gelesene Wert noch gilt. Ein neuer Zeitpunkt,
     * der den gelesenen nicht übersteigt, kann nichts ändern (gleiche Sekunde ⇒ nicht beansprucht, auf jedem Backend gleich).
     */
    public function claimMail(int $userId, string $targetType, int $targetId, ?string $expected, string $nowUtc): bool
    {
        self::target($userId, $targetType, $targetId);
        if ($expected !== null && $expected >= $nowUtc) {
            return false;
        }
        $params = [$nowUtc, $userId, $targetType, $targetId];
        if ($expected !== null) {
            $params[] = $expected;
        }

        return $this->run(
            'UPDATE ' . $this->table(self::SUBSCRIPTIONS) . ' SET notified_at = ? WHERE user_id = ? AND target_type = ? AND target_id = ?'
            . ($expected === null ? ' AND notified_at IS NULL' : ' AND notified_at = ?'),
            $params
        ) > 0;
    }

    /** Nimmt eine Beanspruchung zurück (fachlich abgelehnte Mail): `notified_at` zurück auf `$previous`, nur wenn es noch `$claimedUtc` ist. */
    public function releaseMail(int $userId, string $targetType, int $targetId, string $claimedUtc, ?string $previous): bool
    {
        self::target($userId, $targetType, $targetId);

        return $this->run(
            'UPDATE ' . $this->table(self::SUBSCRIPTIONS) . ' SET notified_at = ? WHERE user_id = ? AND target_type = ? AND target_id = ? AND notified_at = ?',
            [$previous, $userId, $targetType, $targetId, $claimedUtc]
        ) > 0;
    }

    /**
     * Wer von `$userIds` ignoriert `$actorId`? Je Stück eine Abfrage über `idx_community_ignores_ignored`. Aufsteigend.
     *
     * @param array<array-key, mixed> $userIds
     * @return list<int>
     */
    public function ignoring(int $actorId, array $userIds): array
    {
        if ($actorId < 1) {
            return [];
        }
        $ids = self::ids($userIds);
        sort($ids, SORT_NUMERIC);
        $found = [];
        foreach (array_chunk($ids, self::MAX_IDS) as $chunk) {
            $rows = $this->fetchAll(
                'SELECT user_id FROM ' . $this->table(self::IGNORES) . ' WHERE ignored_user_id = ? AND user_id IN (' . self::placeholders(count($chunk)) . ')',
                [$actorId, ...$chunk]
            );
            foreach ($rows as $row) {
                $found[(int) $row['user_id']] = (int) $row['user_id'];
            }
        }
        ksort($found);

        return array_values($found);
    }

    // ── Zustell-Warteschlange ────────────────────────────────────────────────────────────────────────────────────

    /** Legt einen Auftrag an (Phase 0, ohne Lease, `$phase` nur für Aufträge mit genau einem Empfänger) und liefert die ID. */
    public function enqueue(string $kind, int $topicId, int $postId, int $actorId, ?string $detail, string $nowUtc, int $phase = 0): int
    {
        self::kind($kind);
        if ($topicId < 0 || $topicId > self::MAX_UINT || $postId < 0 || $actorId < 0 || $actorId > self::MAX_UINT || $phase < 0 || $phase > 3
            || ($detail !== null && ($detail === '' || mb_strlen($detail, 'UTF-8') > self::DETAIL_MAX))
        ) {
            throw new \InvalidArgumentException('Ungültiger Zustellauftrag.');
        }
        $this->run(
            'INSERT INTO ' . $this->table(self::QUEUE) . ' (kind, topic_id, post_id, actor_id, detail, phase, cursor_user_id, attempts, steps, lease_token, locked_until, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, NULL, NULL, ?, ?)',
            [$kind, $topicId, $postId, $actorId, $detail, $phase, $nowUtc, $nowUtc]
        );

        return $this->db->lastInsertId();
    }

    /**
     * Fällige Aufträge einer Stufe (Arten `$kinds`), älteste zuerst; Index `idx_community_notify_queue_kind` (Vertrag §3.7). Je Art eine
     * Anweisung, gemischt in PHP: Mit mehreren Arten in einer IN-Liste wählte MariaDB 10.11 für `ORDER BY id LIMIT` den Primärschlüssel
     * und las bei 1.500 älteren Reaktionsaufträgen 1.510 Zeilen; je Art liefert der Index die IDs schon sortiert (InnoDB hängt `id` an).
     *
     * @param list<string> $kinds
     * @return list<int>
     */
    public function dueIds(string $nowUtc, int $limit, array $kinds): array
    {
        self::limit($limit);
        $kinds = array_values(array_unique($kinds));
        if ($kinds === []) {
            throw new \InvalidArgumentException('Keine Art angegeben.');
        }
        array_map(self::kind(...), $kinds);
        $ids = [];
        foreach ($kinds as $kind) {
            $rows = $this->fetchAll(
                'SELECT id FROM ' . $this->table(self::QUEUE) . ' WHERE kind IN (?) AND (locked_until IS NULL OR locked_until < ?) ORDER BY id ASC LIMIT ?',
                [$kind, $nowUtc, $limit]
            );
            foreach ($rows as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        sort($ids, SORT_NUMERIC);

        return array_slice($ids, 0, $limit);
    }

    /**
     * Belegt einen fälligen Auftrag mit Token und Frist. Eine abgelaufene fremde Lease zählt als Versuch (`attempts + 1`; die
     * Zuweisung steht vor `lease_token`, weil MariaDB die Zuweisungen von links nach rechts auswertet). `null` = belegt oder fehlt.
     *
     * @return array<string, mixed>|null die Zeile nach dem Belegen (IDs und Zähler als int)
     */
    public function lease(int $id, string $token, string $untilUtc, string $nowUtc): ?array
    {
        self::id($id);
        self::token($token);
        $leased = $this->run(
            'UPDATE ' . $this->table(self::QUEUE) . ' SET attempts = attempts + CASE WHEN lease_token IS NULL THEN 0 ELSE 1 END, lease_token = ?, locked_until = ?,'
            . ' steps = steps + 1, updated_at = ? WHERE id = ? AND (locked_until IS NULL OR locked_until < ?)',
            [$token, $untilUtc, $nowUtc, $id, $nowUtc]
        );
        if ($leased < 1) {
            return null;
        }
        $row = $this->fetchOne('SELECT * FROM ' . $this->table(self::QUEUE) . ' WHERE id = ? AND lease_token = ? LIMIT 1', [$id, $token]);
        if ($row === null) {
            return null;
        }
        foreach (['id', 'topic_id', 'post_id', 'actor_id', 'phase', 'cursor_user_id', 'attempts', 'steps'] as $column) {
            $row[$column] = (int) $row[$column];
        }
        $row['kind'] = (string) $row['kind'];
        $row['detail'] = self::text($row['detail']);

        return $row;
    }

    /** Cursor weiterschieben (Phase, letzter bearbeiteter Empfänger); `false` = Lease verloren. */
    public function advance(int $id, string $token, int $phase, int $cursorUserId, string $nowUtc): bool
    {
        if ($phase < 0 || $phase > 3 || $cursorUserId < 0 || $cursorUserId > self::MAX_UINT) {
            throw new \InvalidArgumentException('Ungültiger Cursor des Auftrags.');
        }

        return $this->run(
            'UPDATE ' . $this->table(self::QUEUE) . ' SET phase = ?, cursor_user_id = ?, steps = steps + 1, updated_at = ? WHERE id = ? AND lease_token = ?',
            [$phase, $cursorUserId, $nowUtc, $id, $token]
        ) > 0;
    }

    /** Frist der eigenen Lease verlängern; `false` = Lease verloren. */
    public function extend(int $id, string $token, string $untilUtc, string $nowUtc): bool
    {
        return $this->run(
            'UPDATE ' . $this->table(self::QUEUE) . ' SET locked_until = ?, steps = steps + 1, updated_at = ? WHERE id = ? AND lease_token = ?',
            [$untilUtc, $nowUtc, $id, $token]
        ) > 0;
    }

    /**
     * Gibt die eigene Lease frei. `$failed`: `attempts + 1`, frühestens wieder fällig ab `$retryAfterUtc` (Fehlerpause, kein Neustart
     * im selben Lauf). Sonst (Budget erschöpft) sofort wieder fällig.
     */
    public function release(int $id, string $token, bool $failed, string $nowUtc, ?string $retryAfterUtc = null): bool
    {
        return $this->run(
            'UPDATE ' . $this->table(self::QUEUE) . ' SET attempts = attempts + ?, lease_token = NULL, locked_until = ?, steps = steps + 1, updated_at = ? WHERE id = ? AND lease_token = ?',
            [$failed ? 1 : 0, $failed ? $retryAfterUtc : null, $nowUtc, $id, $token]
        ) > 0;
    }

    /** Löscht den erledigten Auftrag (nur mit eigenem Token). */
    public function finish(int $id, string $token): bool
    {
        return $this->run('DELETE FROM ' . $this->table(self::QUEUE) . ' WHERE id = ? AND lease_token = ?', [$id, $token]) > 0;
    }

    /** Löscht verfallene Aufträge (angelegt vor `$createdBeforeUtc`, ohne gültige Lease); Tabelle klein, Vollscan zulässig. */
    public function purgeExpired(string $createdBeforeUtc, string $nowUtc): int
    {
        return $this->run(
            'DELETE FROM ' . $this->table(self::QUEUE) . ' WHERE created_at < ? AND (locked_until IS NULL OR locked_until < ?)',
            [$createdBeforeUtc, $nowUtc]
        );
    }

    // ── Prüfungen ────────────────────────────────────────────────────────────────────────────────────────────────

    private static function target(int $userId, string $targetType, int $targetId): void
    {
        self::id($userId);
        self::targetType($targetType);
        self::id($targetId);
    }

    private static function targetType(string $targetType): void
    {
        if (!in_array($targetType, self::TARGET_TYPES, true)) {
            throw new \InvalidArgumentException('Unbekannter Zieltyp: ' . $targetType);
        }
    }

    private static function mode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Unbekannter Modus: ' . $mode);
        }
    }

    private static function kind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unbekannte Art: ' . $kind);
        }
    }

    private static function id(int $id): void
    {
        if ($id < 1 || $id > PHP_INT_MAX) {
            throw new \InvalidArgumentException('Ungültige ID.');
        }
    }

    private static function limit(int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('Grenze außerhalb 1 bis ' . self::MAX_LIMIT . '.');
        }
    }

    private static function token(string $token): void
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException('Ungültiges Lease-Token.');
        }
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
