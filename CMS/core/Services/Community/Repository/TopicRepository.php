<?php
/**
 * Datenzugriff der Community-Themen (`community_topics`).
 *
 * - Schreibzugriffe laufen über `Database::execute()` und werfen bei Fehlern, damit eine umgebende Transaktion
 *   zurückrollt. Spalten kommen aus Allowlisten; unbekannte Spalten werfen `InvalidArgumentException`.
 * - Zeiten setzt der Aufrufer (UTC, `Y-m-d H:i:s`); dieses Repository hat keine Uhr.
 * - `reply_count` und `version` ändern sich nur atomar (`applyCounters()`, `bumpCounters()`, `update()`).
 * - Listen sind Keyset-Listen (Zeit absteigend, ID als Tiebreaker), ohne Zeilenversatz: jede Zeile erscheint genau einmal.
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

final class TopicRepository extends AbstractRepository
{
    public const STATUSES = ['published', 'pending', 'hidden', 'deleted', 'trash', 'moved'];
    /** Größte Seite der Themenliste. */
    public const MAX_LIMIT = 100;
    /** Höchstzahl angepinnter Themen auf der ersten Seite einer Liste. */
    public const MAX_PINNED = 50;

    private const TABLE = 'community_topics';
    private const POSTS = 'community_posts';

    /** Spalten für `insert()` (alles außer `id` und `version`). */
    private const INSERTABLE = [
        'forum_id', 'user_id', 'title', 'slug', 'status', 'moved_to_topic_id', 'moved_expires_at', 'is_pinned', 'pinned_until',
        'is_announcement', 'is_locked', 'locked_until', 'slow_mode_seconds', 'prefix', 'first_post_id', 'solution_post_id',
        'solved_at', 'solved_by', 'has_poll', 'has_attachments', 'reply_count', 'last_post_id', 'last_post_at',
        'last_poster_id', 'trashed_at', 'trashed_by', 'previous_status', 'created_at', 'updated_at',
    ];

    /** Spalten für `update()`: kein Besitzer, keine Zähler, keine Anlagezeit, `version` steigt automatisch. */
    private const UPDATABLE = [
        'forum_id', 'title', 'slug', 'status', 'moved_to_topic_id', 'moved_expires_at', 'is_pinned', 'pinned_until',
        'is_announcement', 'is_locked', 'locked_until', 'slow_mode_seconds', 'prefix', 'first_post_id', 'solution_post_id',
        'solved_at', 'solved_by', 'has_poll', 'has_attachments', 'last_post_id', 'last_post_at', 'last_poster_id',
        'trashed_at', 'trashed_by', 'previous_status', 'updated_at',
    ];

    /** Status, die für Themen-Grenzen (je Stunde) zählen: nur gelöschte oder verschobene Themen geben den Platz frei. */
    private const COUNTED = ['published', 'pending', 'hidden'];

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
     * Legt ein Thema an und liefert die ID. `created_at`, `updated_at` und `last_post_at` setzt der Aufrufer
     * (`last_post_at` immer, auch bei `pending`: die Liste sortiert danach und ein Thema ohne Wert verschwindet ab Seite 2).
     *
     * @param array<string, mixed> $data Spalten aus INSERTABLE
     */
    public function insert(array $data): int
    {
        return $this->insertRow(self::TABLE, $data, self::INSERTABLE);
    }

    /**
     * Ändert Spalten aus UPDATABLE und erhöht `version` um 1. Weil `version` sich bei jedem Treffer ändert, heißt `true`
     * „Zeile gefunden“. Ein leerer Änderungssatz liefert `false`.
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

        return $this->run(
            'UPDATE ' . $this->table(self::TABLE) . " SET {$set}, version = version + 1 WHERE id = ?",
            [...array_values($data), $id]
        ) === 1;
    }

    /**
     * Antwortzähler atomar ändern (positiv oder negativ, nie unter 0), `version` erhöhen, `updated_at` setzen.
     * `$last`: `null` lässt „letzter Beitrag“ unverändert; ein Array setzt alle drei Felder (auch auf `null`).
     * Liefert `true`, wenn das Thema existiert.
     *
     * @param array{post_id: int|null, post_at: string|null, poster_id: int|null}|null $last
     */
    public function applyCounters(int $id, int $replies, string $nowUtc, ?array $last = null): bool
    {
        $set = [self::counter('reply_count')];
        $params = self::counterParams($replies);
        if ($last !== null) {
            $set[] = 'last_post_id = ?, last_post_at = ?, last_poster_id = ?';
            array_push($params, $last['post_id'] ?? null, $last['post_at'] ?? null, $last['poster_id'] ?? null);
        }
        $params[] = $nowUtc;
        $params[] = $id;

        return $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET ' . implode(', ', $set) . ', version = version + 1, updated_at = ? WHERE id = ?',
            $params
        ) === 1;
    }

    /** Neue Antwort: Antwortzähler erhöhen und „letzter Beitrag“ setzen (eine Anweisung). */
    public function bumpCounters(int $id, int $replies, string $lastPostAt, int $lastPostId, int $lastPosterId, string $nowUtc): bool
    {
        return $this->applyCounters($id, $replies, $nowUtc, ['post_id' => $lastPostId, 'post_at' => $lastPostAt, 'poster_id' => $lastPosterId]);
    }

    /**
     * Wirksamer Zustand einer Themenzeile zum Zeitpunkt `$nowUtc`: ein befristetes Anpinnen oder Schließen, dessen Frist
     * (`pinned_until`, `locked_until`) abgelaufen ist, gilt nicht mehr (der Aufräum-Job läuft später und nicht sofort).
     * Setzt `is_pinned` und `is_locked` auf die wirksamen Werte 0/1; alle anderen Spalten bleiben.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function withEffectiveState(array $row, string $nowUtc): array
    {
        foreach (['is_pinned' => 'pinned_until', 'is_locked' => 'locked_until'] as $flag => $until) {
            $active = !empty($row[$flag]) && (($row[$until] ?? null) === null || (string) $row[$until] > $nowUtc);
            $row[$flag] = $active ? 1 : 0;
        }

        return $row;
    }

    /**
     * Seite der Themenliste eines Forums, jüngste Aktivität zuerst (Index `idx_community_topics_forum_list`).
     *
     * - Ohne Cursor (erste Seite) stehen zuerst die angepinnten Themen (höchstens MAX_PINNED, nach Aktivität sortiert),
     *   danach bis zu `$limit` Themen des Stroms. Mit Cursor kommen nur Themen des Stroms.
     * - Ein Thema, dessen `pinned_until` abgelaufen ist, gehört zum Strom (Sortierung nach Aktivität); `is_pinned` und
     *   `is_locked` der Zeilen sind die wirksamen Werte (`withEffectiveState()`).
     * - Keyset: `Page::$next` ist der Cursor (`last_post_at`, `id`) der letzten Stromzeile oder null auf der letzten Seite.
     *   Abfrage als `(zeit < ? OR (zeit = ? AND id < ?))`: mit gleichen Zeitstempeln geht keine Zeile verloren und keine erscheint doppelt.
     * - `$status` ist genau ein Status (Standard `published`); unbekannte Werte fallen auf `published`.
     *
     * @return Page Einträge sind Themenzeilen (alle Spalten)
     */
    public function listForum(int $forumId, string $nowUtc, ?Cursor $after = null, int $limit = 20, string $status = 'published'): Page
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $status = in_array($status, self::STATUSES, true) ? $status : 'published';
        $table = $this->table(self::TABLE);

        $keyset = '';
        $keysetParams = [];
        if ($after !== null) {
            $keyset = ' AND (last_post_at < ? OR (last_post_at = ? AND id < ?))';
            $keysetParams = [$after->at, $after->at, $after->id];
        }
        $order = ' ORDER BY last_post_at DESC, id DESC LIMIT ?';

        $stream = $this->fetchAll(
            "SELECT * FROM {$table} WHERE forum_id = ? AND status = ? AND is_pinned = 0{$keyset}{$order}",
            [$forumId, $status, ...$keysetParams, $limit + 1]
        );
        // Abgelaufene Anheftung: gehört zum Strom und wird mit ihm nach (Zeit, ID) zusammengeführt. Beide Teillisten sind auf
        // $limit + 1 begrenzt, die ersten $limit + 1 der Vereinigung sind damit vollständig.
        $expired = $this->fetchAll(
            "SELECT * FROM {$table} WHERE forum_id = ? AND status = ? AND is_pinned = 1 AND pinned_until IS NOT NULL AND pinned_until <= ?{$keyset}{$order}",
            [$forumId, $status, $nowUtc, ...$keysetParams, $limit + 1]
        );
        if ($expired !== []) {
            $stream = array_merge($stream, $expired);
            usort($stream, static fn (array $a, array $b): int => [(string) $b['last_post_at'], (int) $b['id']] <=> [(string) $a['last_post_at'], (int) $a['id']]);
        }
        $more = count($stream) > $limit;
        $stream = array_slice($stream, 0, $limit);

        $last = $stream === [] ? null : $stream[count($stream) - 1];
        $next = $more && $last !== null && (string) ($last['last_post_at'] ?? '') !== ''
            ? (new Cursor((string) $last['last_post_at'], (int) $last['id']))->encode()
            : null;

        $items = $after === null ? $this->pinned($forumId, $nowUtc, $status) : [];
        foreach ($stream as $row) {
            $items[] = self::withEffectiveState($row, $nowUtc);
        }

        return new Page($items, $next);
    }

    /**
     * Wirksam angepinnte Themen eines Forums (`pinned_until` leer oder in der Zukunft), nach Aktivität sortiert.
     *
     * @return list<array<string, mixed>>
     */
    public function pinned(int $forumId, string $nowUtc, string $status = 'published'): array
    {
        $status = in_array($status, self::STATUSES, true) ? $status : 'published';
        $rows = $this->fetchAll(
            'SELECT * FROM ' . $this->table(self::TABLE) . ' WHERE forum_id = ? AND status = ? AND is_pinned = 1'
            . ' AND (pinned_until IS NULL OR pinned_until > ?) ORDER BY last_post_at DESC, id DESC LIMIT ?',
            [$forumId, $status, $nowUtc, self::MAX_PINNED]
        );

        return array_map(static fn (array $row): array => self::withEffectiveState($row, $nowUtc), $rows);
    }

    /**
     * Doppeltes Absenden: ein Thema desselben Mitglieds im selben Forum seit `$sinceUtc` mit gleichem Titel (und, wenn
     * angegeben, gleichem Text des ersten Beitrags). Die Abfrage holt die zehn jüngsten Themen des Mitglieds im Fenster
     * (Index `idx_community_topics_user`, unabhängig von der Größe des Forums); Forum, Titel und Text werden exakt in PHP
     * verglichen (die Datenbank-Collation ignoriert Groß-/Kleinschreibung).
     *
     * @return array{id: int, first_post_id: int|null, status: string, forum_id: int, title: string}|null
     */
    public function findRecentDuplicate(int $userId, int $forumId, string $title, string $sinceUtc, ?string $body = null): ?array
    {
        $rows = $this->fetchAll(
            'SELECT t.id, t.first_post_id, t.status, t.forum_id, t.title, p.body AS first_body FROM ' . $this->table(self::TABLE) . ' t'
            . ' LEFT JOIN ' . $this->table(self::POSTS) . ' p ON p.id = t.first_post_id'
            . " WHERE t.user_id = ? AND t.status IN ('published', 'pending') AND t.created_at >= ?"
            . ' ORDER BY t.created_at DESC, t.id DESC LIMIT 10',
            [$userId, $sinceUtc]
        );
        foreach ($rows as $row) {
            if ((int) $row['forum_id'] !== $forumId || (string) $row['title'] !== $title
                || ($body !== null && (string) ($row['first_body'] ?? '') !== $body)) {
                continue;
            }

            return [
                'id' => (int) $row['id'],
                'first_post_id' => $row['first_post_id'] === null ? null : (int) $row['first_post_id'],
                'status' => (string) $row['status'],
                'forum_id' => (int) $row['forum_id'],
                'title' => (string) $row['title'],
            ];
        }

        return null;
    }

    /**
     * Jüngstes veröffentlichtes Thema eines Forums nach Aktivität (für „letztes Thema“ nach dem Löschen).
     *
     * Zwei Abfragen statt einer: je eine mit Gleichheit auf `forum_id`, `status` UND `is_pinned` (0 und 1) und
     * `ORDER BY last_post_at DESC, id DESC LIMIT 1` laufen über `idx_community_topics_forum_list` (forum_id, status, is_pinned,
     * last_post_at) mit einem einzigen Index-Eintrag je Abfrage; danach wählt PHP das spätere Paar (`last_post_at`, `id`). Eine
     * einzige Abfrage ohne `is_pinned` passt zu keinem Index (der Index hat `is_pinned` zwischen Status und Zeit): der Optimierer
     * liest dann `idx_community_topics_activity` und filtert das Forum, in einem ruhigen Forum neben einer aktiven Tabelle mit
     * Zehntausenden Lesezugriffen (Befund B5). Dasselbe Muster gilt für jede Abfrage nach Forum und Status: `is_pinned` nicht
     * überspringen, sondern über beide Werte (0, 1) abfragen.
     *
     * @return array{id: int, user_id: int|null, last_post_id: int|null, last_post_at: string|null, last_poster_id: int|null}|null
     */
    public function lastPublished(int $forumId): ?array
    {
        $best = null;
        foreach ([0, 1] as $pinned) {
            $row = $this->fetchOne(
                'SELECT id, user_id, last_post_id, last_post_at, last_poster_id FROM ' . $this->table(self::TABLE)
                . " WHERE forum_id = ? AND status = 'published' AND is_pinned = ? ORDER BY last_post_at DESC, id DESC LIMIT 1",
                [$forumId, $pinned]
            );
            if ($row !== null && ($best === null || self::activityKey($row) > self::activityKey($best))) {
                $best = $row;
            }
        }

        return $best === null ? null : [
            'id' => (int) $best['id'],
            'user_id' => $best['user_id'] === null ? null : (int) $best['user_id'],
            'last_post_id' => $best['last_post_id'] === null ? null : (int) $best['last_post_id'],
            'last_post_at' => $best['last_post_at'] === null ? null : (string) $best['last_post_at'],
            'last_poster_id' => $best['last_poster_id'] === null ? null : (int) $best['last_poster_id'],
        ];
    }

    /**
     * Sortierschlüssel (`last_post_at`, `id`) wie `ORDER BY last_post_at DESC, id DESC`: Zeit als Text (UTC, `Y-m-d H:i:s`, NULL zuletzt), ID als Zahl.
     *
     * @param array<string, mixed> $row
     * @return array{0: string, 1: int}
     */
    private static function activityKey(array $row): array
    {
        return [(string) ($row['last_post_at'] ?? ''), (int) $row['id']];
    }

    /**
     * Anzahl der Themen eines Mitglieds seit `$sinceUtc` (angelegt am oder nach dem Zeitpunkt; Status veröffentlicht,
     * wartend, ausgeblendet) für Grenzen je Stunde. Index `idx_community_topics_user`.
     */
    public function countSince(int $userId, string $sinceUtc): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND status IN (' . self::placeholders(count(self::COUNTED))
            . ') AND created_at >= ?',
            [$userId, ...self::COUNTED, $sinceUtc]
        );
    }

    /**
     * Jüngstes Thema eines Mitglieds seit `$sinceUtc` (Status wie bei `countSince()`) für den Mindestabstand zwischen Themen.
     *
     * @return array{id: int, forum_id: int, created_at: string}|null
     */
    public function lastOfUser(int $userId, string $sinceUtc): ?array
    {
        $row = $this->fetchOne(
            'SELECT id, forum_id, created_at FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND status IN (' . self::placeholders(count(self::COUNTED))
            . ') AND created_at >= ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$userId, ...self::COUNTED, $sinceUtc]
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'forum_id' => (int) $row['forum_id'], 'created_at' => (string) $row['created_at']];
    }
}
