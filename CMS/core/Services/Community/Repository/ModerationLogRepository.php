<?php
/**
 * Datenzugriff des Moderationsprotokolls (`community_moderation_log`, PR #2b).
 *
 * - Schreiben (`add()`, `addMany()`) läuft in der Transaktion der Aktion als letzte Anweisung (Vertrag DESIGN-2b §3.10): eine
 *   reine Einfügung ohne Sperre. Eine Rücknahme nimmt den Eintrag mit; es gibt keinen Eintrag ohne Wirkung.
 * - Keine IP, kein User-Agent: die Tabelle hat keine solche Spalte, `details` erlaubt je Aktion nur die Schlüssel aus DETAIL_KEYS
 *   (IDs, Status, Zeiten, Titel bei `topic.rename`). Werte: Skalare, `null` und Listen von Skalaren; leere Details werden NULL.
 *   JSON kodiert PHP (`JSON_*`-Funktionen der Datenbank sind verboten).
 * - Ein Verstoß gegen die Form (unbekannte Aktion, Zielart oder Detailschlüssel, ungültige ID oder Zeit, Grund über 255 Zeichen,
 *   Details über MAX_DETAILS_BYTES) ist ein Programmfehler: `InvalidArgumentException`, die umgebende Transaktion rollt zurück.
 * - Lesen (`list()`, `latestFor()`) ohne Uhr, Keyset (`created_at`, `id`) jüngste zuerst. Zeilen mit Ganzzahlen und dekodierten
 *   `details` (Array oder `null`).
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

final class ModerationLogRepository extends AbstractRepository
{
    /** Protokollaktionen (Moderation, Meldungen, Sperren; `topic.merge`, `topic.split`, `user.warn` sind P2). */
    public const ACTIONS = [
        'topic.lock', 'topic.unlock', 'topic.pin', 'topic.unpin', 'topic.announce', 'topic.unannounce', 'topic.move', 'topic.merge',
        'topic.split', 'topic.rename', 'topic.hide', 'topic.restore', 'topic.approve', 'topic.reject', 'topic.delete', 'topic.solve',
        'topic.unsolve', 'post.hide', 'post.restore', 'post.edit', 'post.move', 'post.approve', 'post.reject', 'post.delete',
        'report.resolve', 'user.warn', 'user.ban', 'user.unban',
    ];
    /** Zielarten, die PR #2b schreibt (`report.resolve` protokolliert das gemeldete Ziel, nicht die Meldung). */
    public const TARGET_TYPES = ['topic', 'post', 'user'];
    /** Größte Seite von `list()`. */
    public const MAX_LIMIT = 100;
    /** Bis zu so vielen Foren liest `list()` je Forum eine Abfrage (Index ohne Sortierschritt) und mischt in PHP, darüber eine `IN`-Liste. */
    public const PER_FORUM_MERGE = 20;
    /** Größe von `details_json` in Byte (UTF-8). */
    public const MAX_DETAILS_BYTES = 4000;
    /** Größte Länge des Grundes in Zeichen (`reason` VARCHAR(255)). */
    public const MAX_REASON_CHARS = 255;
    /** Einträge je mehrzeiligem INSERT in `addMany()`. */
    public const CHUNK = 100;

    private const TABLE = 'community_moderation_log';

    /** Spalten in der Reihenfolge der Einfügung. */
    private const COLUMNS = ['actor_id', 'action', 'target_type', 'target_id', 'forum_id', 'reason', 'details_json', 'created_at'];

    /** Schlüssel eines Eintrags für `add()`; Pflicht sind `action`, `target_type`, `target_id` und `created_at`. */
    private const ENTRY_KEYS = ['actor_id', 'action', 'target_type', 'target_id', 'forum_id', 'reason', 'details', 'created_at'];

    /** Filter von `list()`. */
    private const FILTER_KEYS = ['forum_ids', 'actor_id', 'action', 'target_type', 'target_id', 'since', 'until'];

    /** Erlaubte Schlüssel von `details` je Aktion; alle übrigen Aktionen haben keine Details. */
    private const DETAIL_KEYS = [
        'topic.lock' => ['until'],
        'topic.pin' => ['until'],
        'topic.rename' => ['from', 'to', 'prefix_from', 'prefix_to'],
        'topic.move' => ['from_forum', 'to_forum', 'stub', 'unpinned'],
        'topic.hide' => ['from', 'auto', 'reports'],
        'topic.restore' => ['from', 'to', 'auto'],
        'topic.approve' => ['first_post'],
        'topic.reject' => ['first_post'],
        'topic.delete' => ['from'],
        'topic.solve' => ['post', 'previous'],
        'topic.unsolve' => ['post'],
        'post.hide' => ['from', 'solution_cleared', 'auto', 'reports'],
        'post.restore' => ['from', 'auto'],
        'post.move' => ['from_topic', 'to_topic'],
        'post.delete' => ['from', 'solution_cleared'],
        'post.edit' => ['revision', 'title'],
        'report.resolve' => ['status', 'reports', 'first_report_id', 'last_report_id'],
        'user.ban' => ['ban_id', 'scope', 'ends_at', 'duration'],
        'user.unban' => ['ban_id', 'scope'],
    ];

    /** Größte ID einer INT-UNSIGNED-Spalte (`actor_id`, `forum_id`). */
    private const MAX_UINT = 4294967295;

    /**
     * Schreibt einen Eintrag und liefert seine ID.
     *
     * Form: `{actor_id: ?int (null = System, nie 0), action, target_type, target_id: int >= 1, forum_id: ?int (Forum des Ziels vor der
     * Aktion), reason: ?string (normalisiert, '' wird NULL), details: array|null, created_at: string (UTC, Y-m-d H:i:s)}`.
     *
     * @param array<string, mixed> $entry
     */
    public function add(array $entry): int
    {
        $values = self::values($entry);
        $this->run(
            'INSERT INTO ' . $this->table(self::TABLE) . ' (' . implode(', ', self::COLUMNS) . ') VALUES (' . self::placeholders(count(self::COLUMNS)) . ')',
            $values
        );

        return $this->db->lastInsertId();
    }

    /**
     * Schreibt mehrere Einträge (z. B. `post.move` je Beitrag), je CHUNK Einträge ein mehrzeiliges INSERT. Alle Einträge werden vor der
     * ersten Anweisung geprüft; leere Liste: keine Anweisung.
     *
     * @param list<array<string, mixed>> $entries
     */
    public function addMany(array $entries): void
    {
        $rows = array_map(static fn (mixed $entry): array => self::values(is_array($entry) ? $entry : throw new \InvalidArgumentException('Protokolleintrag ist kein Array.')), array_values($entries));
        $row = '(' . self::placeholders(count(self::COLUMNS)) . ')';
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->run(
                'INSERT INTO ' . $this->table(self::TABLE) . ' (' . implode(', ', self::COLUMNS) . ') VALUES ' . implode(', ', array_fill(0, count($chunk), $row)),
                array_merge(...$chunk)
            );
        }
    }

    /**
     * Seite des Protokolls, jüngste zuerst, Keyset vor `$before` (ausschließlich). `$limit` 1 bis MAX_LIMIT.
     *
     * Filter (alle optional, unbekannte Schlüssel und ungültige Werte: `InvalidArgumentException`):
     * - `forum_ids`: `null` = alle Einträge (globale Moderation, auch ohne Forum); Liste = nur Einträge dieser Foren (Einträge ohne
     *   Forum fehlen), leere Liste = leere Seite ohne Abfrage. Bis PER_FORUM_MERGE Foren je Forum eine Abfrage über
     *   `idx_community_moderation_log_forum`, gemischt in PHP; darüber eine `IN`-Liste.
     * - `actor_id` (int), `action` (aus ACTIONS), `target_type` (aus TARGET_TYPES) und `target_id` (nur mit `target_type`);
     * - `since` (einschließlich) und `until` (ausschließlich), UTC `Y-m-d H:i:s`.
     *
     * `Page::$next` ist der Cursor (`created_at`, `id`) der letzten Zeile oder null auf der letzten Seite.
     *
     * @param array<string, mixed> $filter
     */
    public function list(array $filter, ?Cursor $before, int $limit): Page
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        [$where, $params] = self::filterConditions($filter);
        if ($before !== null) {
            $where[] = self::keyset();
            array_push($params, $before->at, $before->at, $before->id);
        }
        $forumIds = self::forumFilter($filter);
        if ($forumIds === []) {
            return new Page([], null);
        }

        $rows = [];
        if ($forumIds !== null && count($forumIds) <= self::PER_FORUM_MERGE) {
            foreach ($forumIds as $forumId) {
                array_push($rows, ...$this->select(['forum_id = ?', ...$where], [$forumId, ...$params], $limit + 1));
            }
            usort($rows, static fn (array $a, array $b): int => [(string) $b['created_at'], (int) $b['id']] <=> [(string) $a['created_at'], (int) $a['id']]);
            $rows = array_slice($rows, 0, $limit + 1);
        } elseif ($forumIds !== null) {
            $rows = $this->select(['forum_id IN (' . self::placeholders(count($forumIds)) . ')', ...$where], [...$forumIds, ...$params], $limit + 1);
        } else {
            $rows = $this->select($where, $params, $limit + 1);
        }

        $more = count($rows) > $limit;
        $items = array_map(self::decode(...), array_slice($rows, 0, $limit));
        $last = $items === [] ? null : $items[count($items) - 1];

        return new Page($items, $more && $last !== null ? (new Cursor($last['created_at'], $last['id']))->encode() : null);
    }

    /**
     * Jüngster Eintrag eines Ziels unter den genannten Aktionen (z. B. `systemAction(restore)`: wurde das Ziel automatisch ausgeblendet?),
     * Index `idx_community_moderation_log_target`. Unbekannte Zielart oder Aktion, leere Aktionsliste: `InvalidArgumentException`.
     *
     * @param list<string> $actions
     * @return array{id: int, actor_id: ?int, action: string, target_type: string, target_id: int, forum_id: ?int, reason: ?string, details: ?array, created_at: string}|null
     */
    public function latestFor(string $targetType, int $targetId, array $actions): ?array
    {
        if (!in_array($targetType, self::TARGET_TYPES, true)) {
            throw new \InvalidArgumentException('Unbekannte Zielart: ' . $targetType);
        }
        $actions = array_values(array_unique($actions));
        if ($actions === [] || array_diff($actions, self::ACTIONS) !== []) {
            throw new \InvalidArgumentException('Ungültige Protokollaktionen: ' . implode(', ', array_map('strval', $actions)));
        }
        $row = $this->fetchOne(
            'SELECT ' . self::selectColumns() . ' FROM ' . $this->table(self::TABLE) . ' WHERE target_type = ? AND target_id = ? AND action IN ('
            . self::placeholders(count($actions)) . ') ORDER BY created_at DESC, id DESC LIMIT 1',
            [$targetType, $targetId, ...$actions]
        );

        return $row === null ? null : self::decode($row);
    }

    /**
     * @param list<string> $where
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function select(array $where, array $params, int $limit): array
    {
        return $this->fetchAll(
            'SELECT ' . self::selectColumns() . ' FROM ' . $this->table(self::TABLE) . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY created_at DESC, id DESC LIMIT ?',
            [...$params, $limit]
        );
    }

    /** Keyset jüngste zuerst: mit gleichen Zeitstempeln geht keine Zeile verloren und keine erscheint doppelt. */
    private static function keyset(): string
    {
        return '(created_at < ? OR (created_at = ? AND id < ?))';
    }

    private static function selectColumns(): string
    {
        return 'id, ' . implode(', ', self::COLUMNS);
    }

    /**
     * Bedingungen aus den Filtern außer `forum_ids`.
     *
     * @param array<string, mixed> $filter
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private static function filterConditions(array $filter): array
    {
        $unknown = array_diff(array_map('strval', array_keys($filter)), self::FILTER_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unbekannte Filter: ' . implode(', ', $unknown));
        }
        $where = [];
        $params = [];
        if (isset($filter['actor_id'])) {
            $where[] = 'actor_id = ?';
            $params[] = self::id($filter['actor_id'], self::MAX_UINT, 'actor_id');
        }
        if (isset($filter['action'])) {
            if (!in_array($filter['action'], self::ACTIONS, true)) {
                throw new \InvalidArgumentException('Unbekannte Protokollaktion im Filter.');
            }
            $where[] = 'action = ?';
            $params[] = $filter['action'];
        }
        if (isset($filter['target_type'])) {
            if (!in_array($filter['target_type'], self::TARGET_TYPES, true)) {
                throw new \InvalidArgumentException('Unbekannte Zielart im Filter.');
            }
            $where[] = 'target_type = ?';
            $params[] = $filter['target_type'];
        }
        if (isset($filter['target_id'])) {
            if (!isset($filter['target_type'])) {
                throw new \InvalidArgumentException('Filter target_id nur mit target_type (Index idx_community_moderation_log_target).');
            }
            $where[] = 'target_id = ?';
            $params[] = self::id($filter['target_id'], PHP_INT_MAX, 'target_id');
        }
        foreach (['since' => 'created_at >= ?', 'until' => 'created_at < ?'] as $key => $condition) {
            if (isset($filter[$key])) {
                $where[] = $condition;
                $params[] = self::utcText($filter[$key], $key);
            }
        }

        return [$where, $params];
    }

    /**
     * Forenfilter: `null` = alle Einträge, sonst die Foren aufsteigend (leer = keine Zeile).
     *
     * @param array<string, mixed> $filter
     * @return list<int>|null
     */
    private static function forumFilter(array $filter): ?array
    {
        $forumIds = $filter['forum_ids'] ?? null;
        if ($forumIds === null) {
            return null;
        }
        if (!is_array($forumIds)) {
            throw new \InvalidArgumentException('Filter forum_ids ist keine Liste.');
        }
        $ids = self::ids($forumIds);
        sort($ids);

        return $ids;
    }

    /**
     * Prüft einen Eintrag und liefert die Werte in der Reihenfolge von COLUMNS.
     *
     * @param array<array-key, mixed> $entry
     * @return list<mixed>
     */
    private static function values(array $entry): array
    {
        $unknown = array_diff(array_map('strval', array_keys($entry)), self::ENTRY_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unbekannte Felder im Protokolleintrag: ' . implode(', ', $unknown));
        }
        foreach (['action', 'target_type', 'target_id', 'created_at'] as $key) {
            if (!array_key_exists($key, $entry)) {
                throw new \InvalidArgumentException('Protokolleintrag ohne ' . $key . '.');
            }
        }
        if (!in_array($entry['action'], self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unbekannte Protokollaktion.');
        }
        if (!in_array($entry['target_type'], self::TARGET_TYPES, true)) {
            throw new \InvalidArgumentException('Unbekannte Zielart.');
        }
        $actor = $entry['actor_id'] ?? null;
        $forum = $entry['forum_id'] ?? null;
        $reason = $entry['reason'] ?? null;
        if ($reason === '') {
            $reason = null;
        }
        if ($reason !== null && (!is_string($reason) || self::exceeds(['reason' => $reason], ['reason' => ['chars', self::MAX_REASON_CHARS, true]]))) {
            throw new \InvalidArgumentException('Grund ungültig oder länger als ' . self::MAX_REASON_CHARS . ' Zeichen.');
        }

        return [
            $actor === null ? null : self::id($actor, self::MAX_UINT, 'actor_id'),
            $entry['action'],
            $entry['target_type'],
            self::id($entry['target_id'], PHP_INT_MAX, 'target_id'),
            $forum === null ? null : self::id($forum, self::MAX_UINT, 'forum_id'),
            $reason,
            self::encodeDetails((string) $entry['action'], $entry['details'] ?? null),
            self::utcText($entry['created_at'], 'created_at'),
        ];
    }

    /** Ganzzahl von 1 bis `$max`. */
    private static function id(mixed $value, int $max, string $field): int
    {
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new \InvalidArgumentException('Ungültige ID in ' . $field . '.');
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

    /** `details` prüfen und als JSON kodieren; leer bzw. `null` ergibt NULL. */
    private static function encodeDetails(string $action, mixed $details): ?string
    {
        if ($details === null || $details === []) {
            return null;
        }
        if (!is_array($details) || array_is_list($details)) {
            throw new \InvalidArgumentException('details ist keine Zuordnung von Schlüsseln.');
        }
        $allowed = self::DETAIL_KEYS[$action] ?? [];
        foreach ($details as $key => $value) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new \InvalidArgumentException('Detailschlüssel ' . $key . ' ist für ' . $action . ' nicht erlaubt.');
            }
            if (!self::detailValue($value)) {
                throw new \InvalidArgumentException('Detailwert für ' . $key . ' ist kein Skalar, null oder Liste von Skalaren.');
            }
        }
        try {
            $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('details lässt sich nicht als JSON kodieren: ' . $e->getMessage(), 0, $e);
        }
        if (strlen($json) > self::MAX_DETAILS_BYTES) {
            throw new \InvalidArgumentException('details größer als ' . self::MAX_DETAILS_BYTES . ' Byte.');
        }

        return $json;
    }

    /** Skalar (endlich), `null` oder Liste solcher Werte. */
    private static function detailValue(mixed $value, bool $nested = false): bool
    {
        if ($value === null || is_int($value) || is_string($value) || is_bool($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if ($nested || !is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!self::detailValue($item, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Zeile mit Ganzzahlen und dekodierten `details` (beschädigtes JSON: `null`).
     *
     * @param array<string, mixed> $row
     * @return array{id: int, actor_id: ?int, action: string, target_type: string, target_id: int, forum_id: ?int, reason: ?string, details: ?array, created_at: string}
     */
    private static function decode(array $row): array
    {
        $details = null;
        if ($row['details_json'] !== null && $row['details_json'] !== '') {
            $decoded = json_decode((string) $row['details_json'], true);
            $details = is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => (int) $row['id'],
            'actor_id' => $row['actor_id'] === null ? null : (int) $row['actor_id'],
            'action' => (string) $row['action'],
            'target_type' => (string) $row['target_type'],
            'target_id' => (int) $row['target_id'],
            'forum_id' => $row['forum_id'] === null ? null : (int) $row['forum_id'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            'details' => $details,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
