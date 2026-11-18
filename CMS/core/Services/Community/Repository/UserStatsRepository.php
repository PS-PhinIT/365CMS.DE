<?php
/**
 * Zähler und Vertrauensstufe je Mitglied (`community_user_stats`). Die Zeile kann fehlen (bis zum ersten Beitrag):
 * `get()` liefert dann `null`, Vertrauensstufe und Zähler gelten als 0.
 *
 * Schreiben: `ensure()` legt die Zeile an, `lockForUpdate()` legt an und sperrt (letzte Sperre in der Reihenfolge Forum,
 * Thema, Beitrag, Statistik), `bump()` ändert Zähler atomar (nie unter 0), `touchLastPost()` setzt den Zeitpunkt des
 * letzten Beitrags (auch bei wartenden Beiträgen).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class UserStatsRepository extends AbstractRepository
{
    private const TABLE = 'community_user_stats';

    /** Spalten, die `get()` liefert (ohne Signatur und Einstellungen). */
    private const COLUMNS = 'user_id, topic_count, post_count, reactions_received, reactions_given, solutions_count, reputation,'
        . ' trust_level, trust_level_locked, topics_read, days_visited, rank_id, custom_title, last_post_at, last_seen_at, updated_at';

    /**
     * Zeile des Mitglieds oder `null`.
     *
     * @return array<string, mixed>|null
     */
    public function get(int $userId): ?array
    {
        return $this->fetchOne('SELECT ' . self::COLUMNS . ' FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? LIMIT 1', [$userId]);
    }

    /** Legt die Zeile an, falls sie fehlt (ohne Wirkung, wenn sie existiert). */
    public function ensure(int $userId, string $nowUtc): void
    {
        $this->run('INSERT IGNORE INTO ' . $this->table(self::TABLE) . ' (user_id, updated_at) VALUES (?, ?)', [$userId, $nowUtc]);
    }

    /**
     * Zeile mit Sperre lesen und, falls sie fehlt, vorher anlegen. Liest zuerst (Sperre ohne Schreibzugriff, wenn die Zeile
     * existiert) und legt nur bei Bedarf an; das vermeidet Sperrkonflikte zwischen parallelen Aufrufern desselben Mitglieds.
     * Nur in einer Transaktion sinnvoll.
     *
     * @return array<string, mixed>
     */
    public function lockForUpdate(int $userId, string $nowUtc): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? LIMIT 1' . self::forUpdate();
        $row = $this->fetchOne($sql, [$userId]);
        if ($row === null) {
            $this->ensure($userId, $nowUtc);
            $row = $this->fetchOne($sql, [$userId]);
        }
        if ($row === null) {
            throw new \RuntimeException('community_user_stats: Zeile konnte nicht angelegt werden.');
        }

        return $row;
    }

    /**
     * Themen- und Beitragszähler atomar ändern (positiv oder negativ, nie unter 0). Legt die Zeile bei Bedarf an; in einer
     * Transaktion vorher `lockForUpdate()` aufrufen.
     */
    public function bump(int $userId, int $topics, int $posts, string $nowUtc): void
    {
        $this->ensure($userId, $nowUtc);
        $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET ' . self::counter('topic_count') . ', ' . self::counter('post_count')
            . ', updated_at = ? WHERE user_id = ?',
            [...self::counterParams($topics), ...self::counterParams($posts), $nowUtc, $userId]
        );
    }

    /** Zeitpunkt des letzten Beitrags setzen (auch für wartende Beiträge); legt die Zeile bei Bedarf an. */
    public function touchLastPost(int $userId, string $nowUtc): void
    {
        $this->ensure($userId, $nowUtc);
        $this->run('UPDATE ' . $this->table(self::TABLE) . ' SET last_post_at = ?, updated_at = ? WHERE user_id = ?', [$nowUtc, $nowUtc, $userId]);
    }
}
