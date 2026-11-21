<?php
/**
 * Datenzugriff der Community-Themen (`community_topics`).
 *
 * - Schreibzugriffe laufen über `Database::execute()` und werfen bei Fehlern, damit eine umgebende Transaktion
 *   zurückrollt. Spalten kommen aus Allowlisten; unbekannte Spalten werfen `InvalidArgumentException`.
 * - Zeiten setzt der Aufrufer (UTC, `Y-m-d H:i:s`); dieses Repository hat keine Uhr.
 * - `reply_count`, `reaction_count` und `version` ändern sich nur atomar (`applyCounters()`, `bumpCounters()`, `bumpReactions()`,
 *   `update()`).
 * - Listen sind Keyset-Listen (Zeit absteigend, ID als Tiebreaker), ohne Zeilenversatz: jede Zeile erscheint genau einmal.
 * - Befristete Zustände (PR #2b): `expiredIds()` und `clearExpired()` nutzen je Art dieselbe Bedingung (EXPIRY); das Aufräumen
 *   prüft sie erneut, damit eine inzwischen verlängerte Frist bleibt.
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
    /**
     * Höchstzahl wirksam angepinnter Themen je Forum und Status auf der ersten Seite einer Liste. Das ist zugleich die Invariante
     * für das Anheften (PR #2b): Der Strom einer Liste fragt `is_pinned = 0`; ein angepinntes Thema jenseits dieser Grenze stünde
     * in keiner Seite (weder in `pinned()` noch im Strom, Befund B24). Beide in den Strom einzumischen ginge nur mit
     * `is_pinned IN (0, 1)` und damit ohne den Index `idx_community_topics_forum_list` für die Sortierung (Filesort über alle
     * Themen des Forums, siehe Befund B5): zu teuer für einen Randfall. Der Anheft-Dienst muss deshalb beim Anheften ablehnen,
     * wenn das Forum schon MAX_PINNED wirksame Pins hat; ein Ablauf von `pinned_until` gibt Platz frei, ohne dass die Liste
     * etwas verliert (abgelaufene Pins gehören zum Strom).
     */
    public const MAX_PINNED = 50;
    /** Höchstzahl der Ankündigungen je Abfrage (`listAnnouncements()`). */
    public const MAX_ANNOUNCEMENTS = 20;
    /** Höchstzahl der IDs je Aufruf von `expiredIds()` (Cron-Stapel). */
    public const EXPIRE_MAX = 1000;
    /** Höchstzahl der IDs je Anweisung (`IN (…)`); größere Mengen laufen in Stücken. */
    public const CHUNK = 100;

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

    /**
     * Abgelaufene befristete Zustände je Art: Bedingung (ein Parameter: jetzt, UTC), Sortierspalte und Zuweisung beim Aufräumen
     * (`null` = Verweis-Thema löschen). Indizes `idx_community_topics_locked`, `idx_community_topics_pinned`, `idx_community_topics_moved`.
     * Die Grenze ist einschließlich: eine Frist gleich „jetzt“ ist abgelaufen (wie `withEffectiveState()`).
     */
    private const EXPIRY = [
        'lock' => ['is_locked = 1 AND locked_until IS NOT NULL AND locked_until <= ?', 'locked_until', 'is_locked = 0, locked_until = NULL'],
        'pin' => ['is_pinned = 1 AND pinned_until IS NOT NULL AND pinned_until <= ?', 'pinned_until', 'is_pinned = 0, pinned_until = NULL'],
        'moved' => ["status = 'moved' AND moved_expires_at IS NOT NULL AND moved_expires_at <= ?", 'moved_expires_at', null],
    ];

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
     * - Ohne Cursor (erste Seite) stehen zuerst die angepinnten Themen (höchstens MAX_PINNED, nach Aktivität sortiert; mehr Pins
     *   sind unerreichbar, das Anheften erzwingt die Grenze, siehe MAX_PINNED),
     *   danach bis zu `$limit` Themen des Stroms. Mit Cursor kommen nur Themen des Stroms.
     * - Ein Thema, dessen `pinned_until` abgelaufen ist, gehört zum Strom (Sortierung nach Aktivität); `is_pinned` und
     *   `is_locked` der Zeilen sind die wirksamen Werte (`withEffectiveState()`).
     * - Keyset: `Page::$next` ist der Cursor (`last_post_at`, `id`) der letzten Stromzeile oder null auf der letzten Seite.
     *   Abfrage als `(zeit < ? OR (zeit = ? AND id < ?))`: mit gleichen Zeitstempeln geht keine Zeile verloren und keine erscheint doppelt.
     * - `$status` ist genau ein Status (Standard `published`); unbekannte Werte fallen auf `published`.
     * - Der Strom läuft in zwei Schritten: erst `id, last_post_at` über den Keyset (nur Indexzugriff auf
     *   `idx_community_topics_forum_list`), danach die Zeilen über `id IN (…)`. Mit `SELECT *` wählte MariaDB 10.11 in großen
     *   Foren bei einem Cursor in der oberen Hälfte der Liste `ref` statt `range`; der Keyset wirkte nur noch als Filter, und die
     *   Lesezugriffe wuchsen mit der Position (15.000 Themen: Position 6000 mit 6021, die ID-Abfrage mit 22; HANDOFF §2 Nr. 10).
     *   Außer `idx_community_topics_forum_list` überdeckt kein Index, der mit `forum_id` beginnt, die ID-Abfrage (TESTS/schema-v27):
     *   Mit `idx_community_topics_solved` in der Form (`forum_id`, `solved_at`, …) nahm MariaDB 10.11 in kleinen Foren auf
     *   Folgeseiten `ref` über `forum_id` mit Filesort und las das ganze Forum (HANDOFF §2 Nr. 9).
     *   Gehört eine Zeile beim zweiten Schritt nicht mehr zur Liste (anderes Forum, anderer Status, angeheftet, gelöscht), fehlt
     *   sie auf dieser Seite; der Cursor bleibt der des ersten Schritts.
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
            "SELECT id, last_post_at FROM {$table} WHERE forum_id = ? AND status = ? AND is_pinned = 0{$keyset}{$order}",
            [$forumId, $status, ...$keysetParams, $limit + 1]
        );
        // Abgelaufene Anheftung: gehört zum Strom und wird mit ihm nach (Zeit, ID) zusammengeführt. Beide Teillisten sind auf
        // $limit + 1 begrenzt, die ersten $limit + 1 der Vereinigung sind damit vollständig.
        $expired = [];
        foreach ($this->fetchAll(
            "SELECT * FROM {$table} WHERE forum_id = ? AND status = ? AND is_pinned = 1 AND pinned_until IS NOT NULL AND pinned_until <= ?{$keyset}{$order}",
            [$forumId, $status, $nowUtc, ...$keysetParams, $limit + 1]
        ) as $row) {
            $expired[(int) $row['id']] = $row;
            $stream[] = ['id' => $row['id'], 'last_post_at' => $row['last_post_at']];
        }
        if ($expired !== []) {
            usort($stream, static fn (array $a, array $b): int => [(string) $b['last_post_at'], (int) $b['id']] <=> [(string) $a['last_post_at'], (int) $a['id']]);
        }
        $more = count($stream) > $limit;
        $stream = array_slice($stream, 0, $limit);

        $last = $stream === [] ? null : $stream[count($stream) - 1];
        $next = $more && $last !== null && (string) ($last['last_post_at'] ?? '') !== ''
            ? (new Cursor((string) $last['last_post_at'], (int) $last['id']))->encode()
            : null;

        // Zweiter Schritt: die Zeilen über den Primärschlüssel, nur solange sie noch zur Liste gehören.
        $rows = $expired;
        $ids = array_values(array_diff(array_map(static fn (array $entry): int => (int) $entry['id'], $stream), array_keys($expired)));
        if ($ids !== []) {
            foreach ($this->fetchAll("SELECT * FROM {$table} WHERE id IN (" . self::placeholders(count($ids)) . ')', $ids) as $row) {
                if ((int) $row['forum_id'] === $forumId && (string) $row['status'] === $status && (int) $row['is_pinned'] === 0) {
                    $rows[(int) $row['id']] = $row;
                }
            }
        }

        $items = $after === null ? $this->pinned($forumId, $nowUtc, $status) : [];
        foreach ($stream as $entry) {
            if (isset($rows[(int) $entry['id']])) {
                $items[] = self::withEffectiveState($rows[(int) $entry['id']], $nowUtc);
            }
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
     * Ankündigungen (PR #2b, G6): veröffentlichte Themen mit `is_announcement = 1` aus den genannten Foren, jüngste Aktivität zuerst
     * (`idx_community_topics_announce`, `last_post_at` und der Primärschlüssel ordnen ohne Sortierschritt). Leere Forenliste: `[]`
     * ohne Abfrage. `$limit` 1 bis MAX_ANNOUNCEMENTS. Zeilen wie die Rohzeilen von `listForum()` (alle Spalten); den wirksamen Zustand
     * (`withEffectiveState()`) setzt der Dienst mit seiner Uhr. Welche Foren der Betrachter lesen darf, entscheidet der Aufrufer.
     *
     * @param array<array-key, mixed> $forumIds
     * @return list<array<string, mixed>>
     */
    public function listAnnouncements(array $forumIds, int $limit = 10): array
    {
        $ids = self::ids($forumIds);
        if ($ids === []) {
            return [];
        }
        sort($ids);

        return $this->fetchAll(
            'SELECT * FROM ' . $this->table(self::TABLE) . " WHERE is_announcement = 1 AND status = 'published' AND forum_id IN ("
            . self::placeholders(count($ids)) . ') ORDER BY last_post_at DESC, id DESC LIMIT ?',
            [...$ids, max(1, min(self::MAX_ANNOUNCEMENTS, $limit))]
        );
    }

    /**
     * Wirksam angeheftete Themen eines Forums in allen Status, die ohne erneutes Anheften wieder veröffentlicht werden können
     * (`published`, `pending`, `hidden`), für die Grenze MAX_PINNED beim Anheften (`idx_community_topics_forum_list`, Bereich).
     */
    public function countPinned(int $forumId, string $nowUtc): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->table(self::TABLE)
            . " WHERE forum_id = ? AND status IN ('published', 'pending', 'hidden') AND is_pinned = 1 AND (pinned_until IS NULL OR pinned_until > ?)",
            [$forumId, $nowUtc]
        );
    }

    /**
     * Wirksame Verweis-Themen eines Forums (Status `moved`, `moved_expires_at` leer oder in der Zukunft), jüngste Aktivität zuerst
     * (`idx_community_topics_forum_list`). `$limit` 1 bis MAX_LIMIT.
     *
     * @return list<array<string, mixed>>
     */
    public function listMoved(int $forumId, string $nowUtc, int $limit = 20): array
    {
        return $this->fetchAll(
            'SELECT * FROM ' . $this->table(self::TABLE) . " WHERE forum_id = ? AND status = 'moved' AND is_pinned = 0"
            . ' AND (moved_expires_at IS NULL OR moved_expires_at > ?) ORDER BY last_post_at DESC, id DESC LIMIT ?',
            [$forumId, $nowUtc, max(1, min(self::MAX_LIMIT, $limit))]
        );
    }

    /**
     * Verweis-Themen in `$forumId`, die auf `$topicId` zeigen (beim Zurückverschieben löscht der Dienst sie), aufsteigend
     * (`idx_community_topics_forum_list`, sortiert in PHP: der Index ordnet nach `last_post_at`).
     *
     * @return list<int>
     */
    public function stubIdsFor(int $forumId, int $topicId): array
    {
        $ids = array_map('intval', array_column($this->fetchAll(
            'SELECT id FROM ' . $this->table(self::TABLE) . " WHERE forum_id = ? AND status = 'moved' AND is_pinned = 0 AND moved_to_topic_id = ?",
            [$forumId, $topicId]
        ), 'id'));
        sort($ids);

        return $ids;
    }

    /** Löscht ein Verweis-Thema; jede andere Zeile bleibt (PRIMARY). Liefert `true`, wenn eine Zeile gelöscht wurde. */
    public function deleteStub(int $id): bool
    {
        return $this->run('DELETE FROM ' . $this->table(self::TABLE) . " WHERE id = ? AND status = 'moved'", [$id]) === 1;
    }

    /**
     * Themen, deren befristeter Zustand abgelaufen ist (Grenze einschließlich), früheste Frist zuerst. `$kind`: `lock`, `pin` oder
     * `moved`; sonst `InvalidArgumentException`. `$limit` 1 bis EXPIRE_MAX.
     *
     * @return list<array{id: int, forum_id: int}>
     */
    public function expiredIds(string $kind, string $nowUtc, int $limit): array
    {
        [$condition, $order] = self::expiry($kind);
        $rows = $this->fetchAll(
            'SELECT id, forum_id FROM ' . $this->table(self::TABLE) . ' WHERE ' . $condition . ' ORDER BY ' . $order . ' ASC, id ASC LIMIT ?',
            [$nowUtc, max(1, min(self::EXPIRE_MAX, $limit))]
        );

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'forum_id' => (int) $row['forum_id']], $rows);
    }

    /**
     * Räumt abgelaufene Zustände der genannten Themen auf (Autocommit, je Stück eine Anweisung): `lock`/`pin` setzen Schalter und
     * Frist zurück (`version + 1`, `updated_at`), `moved` löscht die Verweis-Themen. Die Bedingung wird erneut geprüft: eine
     * inzwischen verlängerte Frist bleibt. Liefert die Zahl betroffener Zeilen; leere Liste: 0 ohne Anweisung.
     *
     * @param array<array-key, mixed> $ids
     */
    public function clearExpired(string $kind, array $ids, string $nowUtc): int
    {
        [$condition, , $assign] = self::expiry($kind);
        $ids = self::ids($ids);
        sort($ids);
        $table = $this->table(self::TABLE);
        $affected = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $in = 'id IN (' . self::placeholders(count($chunk)) . ')';
            $affected += $assign === null
                ? $this->run("DELETE FROM {$table} WHERE {$in} AND " . $condition, [...$chunk, $nowUtc])
                : $this->run("UPDATE {$table} SET {$assign}, version = version + 1, updated_at = ? WHERE {$in} AND " . $condition, [$nowUtc, ...$chunk, $nowUtc]);
        }

        return $affected;
    }

    /**
     * Reaktionszähler des Themas atomar ändern (nie unter 0) und `version` erhöhen (Cache der Listen). Mit `$nowUtc` auch
     * `updated_at` (CounterBook beim Verschieben von Beiträgen).
     */
    public function bumpReactions(int $id, int $delta, ?string $nowUtc = null): void
    {
        $params = self::counterParams($delta);
        $touch = '';
        if ($nowUtc !== null) {
            $touch = ', updated_at = ?';
            $params[] = $nowUtc;
        }
        $params[] = $id;
        $this->run('UPDATE ' . $this->table(self::TABLE) . ' SET ' . self::counter('reaction_count') . ', version = version + 1' . $touch . ' WHERE id = ?', $params);
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
     * Anzahl der Themen eines Mitglieds seit `$sinceUtc` (angelegt am oder nach dem Zeitpunkt) für Grenzen je Stunde. Ohne
     * `$statuses` zählen veröffentlichte, wartende und ausgeblendete Themen; mit Liste genau diese Status (die Rechteprüfung
     * zählt auch gelöschte, damit Löschen kein Kontingent freigibt). Index `idx_community_topics_user`.
     *
     * @param list<string>|null $statuses Status aus STATUSES
     */
    public function countSince(int $userId, string $sinceUtc, ?array $statuses = null): int
    {
        $statuses = self::statuses($statuses, self::COUNTED);

        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND status IN (' . self::placeholders(count($statuses))
            . ') AND created_at >= ?',
            [$userId, ...$statuses, $sinceUtc]
        );
    }

    /**
     * Jüngstes Thema eines Mitglieds seit `$sinceUtc` (Status wie bei `countSince()`) für den Mindestabstand zwischen Themen.
     *
     * @param list<string>|null $statuses Status aus STATUSES
     * @return array{id: int, forum_id: int, created_at: string}|null
     */
    public function lastOfUser(int $userId, string $sinceUtc, ?array $statuses = null): ?array
    {
        $statuses = self::statuses($statuses, self::COUNTED);
        $row = $this->fetchOne(
            'SELECT id, forum_id, created_at FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND status IN (' . self::placeholders(count($statuses))
            . ') AND created_at >= ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$userId, ...$statuses, $sinceUtc]
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'forum_id' => (int) $row['forum_id'], 'created_at' => (string) $row['created_at']];
    }

    /**
     * Bedingung, Sortierspalte und Zuweisung einer Art aus EXPIRY.
     *
     * @return array{0: string, 1: string, 2: string|null}
     */
    private static function expiry(string $kind): array
    {
        if (!isset(self::EXPIRY[$kind])) {
            throw new \InvalidArgumentException('Unbekannte Art befristeter Zustände: ' . $kind);
        }

        return self::EXPIRY[$kind];
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
            throw new \InvalidArgumentException('Ungültige Themenstatus: ' . implode(', ', $unknown));
        }

        return $statuses;
    }
}
