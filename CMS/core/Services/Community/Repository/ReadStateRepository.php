<?php
/**
 * Lesestatus der Community: `community_read_states` (je Mitglied und Thema die zuletzt gelesene Beitrags-ID) und
 * `community_forum_read_marks` (je Mitglied und Forum der Zeitpunkt „alles gelesen“; `forum_id` 0 = gesamte Community).
 *
 * Beide Werte steigen nur. Das geschieht ohne Sonderfunktionen der Datenbank in drei Schritten: Erhöhen einer
 * bestehenden Zeile (`UPDATE … WHERE wert < neu`), Anlegen einer fehlenden (`INSERT IGNORE`), erneutes Erhöhen
 * (falls ein paralleler Aufruf die Zeile zwischen den Schritten mit einem kleineren Wert angelegt hat). Wiederbesuche
 * ohne neue Beiträge schreiben nichts. Beide Tabellen haben einen zusammengesetzten Schlüssel ohne AUTO_INCREMENT.
 *
 * Mengenabfragen für die Zustellung (PR #2b): `forUsers()` (Lesestatus mehrerer Mitglieder in einem Thema) und `latestMarksFor()`
 * (jüngste Marke je Mitglied über eine Forenkette), je Stück von höchstens 100 Mitgliedern eine Abfrage.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class ReadStateRepository extends AbstractRepository
{
    private const STATES = 'community_read_states';
    private const MARKS = 'community_forum_read_marks';
    private const TOPICS = 'community_topics';
    private const POSTS = 'community_posts';

    /** Obergrenze für IDs je Abfrage (Platzhalter in `IN (…)`). */
    private const MAX_IDS = 500;

    /** Mitglieder je Mengenabfrage (`forUsers()`, `latestMarksFor()`). */
    private const USER_CHUNK = 100;

    /**
     * Setzt die zuletzt gelesene Beitrags-ID eines Themas, aber nur nach oben. Liefert `true`, wenn die Marke angelegt oder
     * erhöht wurde, `false`, wenn sie schon gleich hoch oder höher war (oder `$postId` kleiner 1 ist).
     *
     * Reihenfolge: zuerst `INSERT IGNORE` (neue Marke), dann `UPDATE … WHERE last_read_post_id < ?` (bestehende Marke erhöhen).
     * Umgekehrt (erst UPDATE, dann INSERT) sperrt das UPDATE auf eine fehlende Zeile die Lücke im Index; zwei Transaktionen mit
     * Lückensperren auf derselben Lücke geraten beim anschließenden Einfügen in einen Deadlock. Das betraf jedes Anlegen eines
     * Themas durch ein neues Mitglied (Lesestatus des Autors, Befund B3). Eine vorhandene gleich hohe Marke wird dabei nicht
     * geschrieben (`updated_at` bleibt).
     */
    public function advance(int $userId, int $topicId, int $postId, string $nowUtc): bool
    {
        if ($userId < 1 || $topicId < 1 || $postId < 1) {
            return false;
        }
        $table = $this->table(self::STATES);
        $inserted = $this->run(
            "INSERT IGNORE INTO {$table} (user_id, topic_id, last_read_post_id, updated_at) VALUES (?, ?, ?, ?)",
            [$userId, $topicId, $postId, $nowUtc]
        );
        if ($inserted > 0) {
            return true;
        }
        $raise = "UPDATE {$table} SET last_read_post_id = ?, updated_at = ? WHERE user_id = ? AND topic_id = ? AND last_read_post_id < ?";

        return $this->run($raise, [$postId, $nowUtc, $userId, $topicId, $postId]) > 0;
    }

    /**
     * Zuletzt gelesene Beitrags-ID je Thema; Themen ohne Zeile fehlen im Ergebnis (gelten als 0).
     *
     * @param list<int> $topicIds höchstens 500
     * @return array<int, int> Thema-ID => Beitrags-ID
     */
    public function forTopics(int $userId, array $topicIds): array
    {
        $topicIds = array_slice(self::ids($topicIds), 0, self::MAX_IDS);
        if ($userId < 1 || $topicIds === []) {
            return [];
        }
        $rows = $this->fetchAll(
            'SELECT topic_id, last_read_post_id FROM ' . $this->table(self::STATES)
            . ' WHERE user_id = ? AND topic_id IN (' . self::placeholders(count($topicIds)) . ')',
            [$userId, ...$topicIds]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['topic_id']] = (int) $row['last_read_post_id'];
        }

        return $map;
    }

    /**
     * Lesestatus mehrerer Mitglieder in einem Thema (Empfänger eines Stapels: „schon gelesen“ und Tor der Themen-Abos). Mitglieder ohne
     * Zeile fehlen; aufsteigend. Je Stück von höchstens USER_CHUNK Mitgliedern eine Abfrage über `idx_community_read_states_topic`.
     *
     * @param array<array-key, mixed> $userIds
     * @return array<int, array{last_read_post_id: int, updated_at: string}>
     */
    public function forUsers(int $topicId, array $userIds): array
    {
        if ($topicId < 1) {
            return [];
        }
        $map = [];
        foreach (self::userBatches($userIds) as $batch) {
            $rows = $this->fetchAll(
                'SELECT user_id, last_read_post_id, updated_at FROM ' . $this->table(self::STATES)
                . ' WHERE topic_id = ? AND user_id IN (' . self::placeholders(count($batch)) . ') ORDER BY user_id ASC',
                [$topicId, ...$batch]
            );
            foreach ($rows as $row) {
                $map[(int) $row['user_id']] = ['last_read_post_id' => (int) $row['last_read_post_id'], 'updated_at' => (string) $row['updated_at']];
            }
        }
        ksort($map);

        return $map;
    }

    /**
     * Jüngste Marke „alles gelesen“ je Mitglied über die angegebenen Foren (Forum und Vorfahren) und immer über `0` (gesamte
     * Community). Mitglieder ohne Marke fehlen; aufsteigend. Je Stück von höchstens USER_CHUNK Mitgliedern eine Abfrage über PRIMARY.
     *
     * @param array<array-key, mixed> $userIds
     * @param list<int> $forumIds
     * @return array<int, string> Mitglied => Zeitpunkt UTC
     */
    public function latestMarksFor(array $userIds, array $forumIds): array
    {
        $chain = [0, ...array_slice(self::ids($forumIds), 0, self::MAX_IDS)];
        $map = [];
        foreach (self::userBatches($userIds) as $batch) {
            $rows = $this->fetchAll(
                'SELECT user_id, MAX(marked_at) AS marked_at FROM ' . $this->table(self::MARKS) . ' WHERE user_id IN (' . self::placeholders(count($batch)) . ')'
                . ' AND forum_id IN (' . self::placeholders(count($chain)) . ') GROUP BY user_id',
                [...$batch, ...$chain]
            );
            foreach ($rows as $row) {
                $map[(int) $row['user_id']] = (string) $row['marked_at'];
            }
        }
        ksort($map);

        return $map;
    }

    /**
     * Foren-Marken eines Mitglieds für die angegebenen Foren und immer für `0` (gesamte Community); fehlende Marken
     * fehlen im Ergebnis. Für ein Unterforum übergibt der Aufrufer das Forum und seine Vorfahren (`ForumRepository::ancestorIds()`).
     *
     * @param list<int> $forumIds
     * @return array<int, string> Forum-ID (0 = global) => Zeitpunkt UTC
     */
    public function marks(int $userId, array $forumIds): array
    {
        if ($userId < 1) {
            return [];
        }
        $forumIds = [0, ...array_slice(self::ids($forumIds), 0, self::MAX_IDS)];
        $rows = $this->fetchAll(
            'SELECT forum_id, marked_at FROM ' . $this->table(self::MARKS) . ' WHERE user_id = ? AND forum_id IN (' . self::placeholders(count($forumIds)) . ')',
            [$userId, ...$forumIds]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['forum_id']] = (string) $row['marked_at'];
        }

        return $map;
    }

    /**
     * Jüngste Marke über die angegebenen Foren und die globale: ab diesem Zeitpunkt gilt Aktivität als ungelesen.
     * `null`, wenn keine Marke gesetzt ist.
     *
     * @param list<int> $forumIds
     */
    public function latestMark(int $userId, array $forumIds): ?string
    {
        $marks = $this->marks($userId, $forumIds);

        return $marks === [] ? null : max($marks);
    }

    /**
     * Setzt die Marke „alles gelesen“ eines Forums (`0` = gesamte Community), nur nach oben. Liefert `true`, wenn sie
     * angelegt oder erhöht wurde.
     */
    public function setMark(int $userId, int $forumId, string $nowUtc): bool
    {
        if ($userId < 1 || $forumId < 0) {
            return false;
        }
        $table = $this->table(self::MARKS);
        $raise = "UPDATE {$table} SET marked_at = ? WHERE user_id = ? AND forum_id = ? AND marked_at < ?";
        $raiseParams = [$nowUtc, $userId, $forumId, $nowUtc];

        if ($this->run($raise, $raiseParams) > 0) {
            return true;
        }
        $inserted = $this->run("INSERT IGNORE INTO {$table} (user_id, forum_id, marked_at) VALUES (?, ?, ?)", [$userId, $forumId, $nowUtc]);

        return $inserted > 0 || $this->run($raise, $raiseParams) > 0;
    }

    /**
     * ID des ersten veröffentlichten Beitrags eines Themas nach `$afterPostId` („zum ersten ungelesenen Beitrag“); null,
     * wenn es keinen gibt. Index `idx_community_posts_topic`.
     */
    public function firstUnread(int $topicId, int $afterPostId): ?int
    {
        $id = $this->fetchValue(
            'SELECT id FROM ' . $this->table(self::POSTS) . " WHERE topic_id = ? AND status = 'published' AND id > ? ORDER BY id ASC LIMIT 1",
            [$topicId, max(0, $afterPostId)]
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Zahl ungelesener Themen in den angegebenen Foren, höchstens `$cap` (die Anzeige zeigt dann „99+“): veröffentlichte
     * Themen mit Aktivität nach `$sinceUtc` und einer Beitrags-ID über der gelesenen. `$sinceUtc` ist die Untergrenze,
     * die der Aufrufer aus Marken und Horizont bildet (Aktivität bis einschließlich dieses Zeitpunkts gilt als gelesen).
     *
     * `is_pinned IN (0, 1)` überspringt den Index `idx_community_topics_forum_list` (forum_id, status, is_pinned, last_post_at) nicht:
     * ohne diese Bedingung wählt der Optimierer in einem Forum mit alten Themen neben einer aktiven Tabelle den Index
     * `idx_community_topics_activity` und liest Zehntausende Einträge anderer Foren (gemessen: 100.000 statt 2 Lesezugriffe).
     * Alle Werte von `is_pinned` (0 und 1) zählen, die Bedingung filtert nichts.
     *
     * @param list<int> $forumIds Forum und, wenn gewünscht, seine Unterforen
     */
    public function unreadCount(int $userId, array $forumIds, string $sinceUtc, int $cap = 100): int
    {
        $forumIds = array_slice(self::ids($forumIds), 0, self::MAX_IDS);
        if ($userId < 1 || $forumIds === []) {
            return 0;
        }

        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM (SELECT t.id FROM ' . $this->table(self::TOPICS) . ' t LEFT JOIN ' . $this->table(self::STATES)
            . ' rs ON rs.user_id = ? AND rs.topic_id = t.id WHERE t.forum_id IN (' . self::placeholders(count($forumIds)) . ")"
            . " AND t.status = 'published' AND t.is_pinned IN (0, 1) AND t.last_post_at > ? AND (rs.last_read_post_id IS NULL OR rs.last_read_post_id < t.last_post_id) LIMIT ?) unread",
            [$userId, ...$forumIds, $sinceUtc, max(1, $cap)]
        );
    }

    /**
     * Mitglieder-IDs eindeutig, aufsteigend, in Stücken zu höchstens USER_CHUNK.
     *
     * @param array<array-key, mixed> $userIds
     * @return list<list<int>>
     */
    private static function userBatches(array $userIds): array
    {
        $ids = self::ids($userIds);
        sort($ids, SORT_NUMERIC);

        return array_chunk($ids, self::USER_CHUNK);
    }
}
