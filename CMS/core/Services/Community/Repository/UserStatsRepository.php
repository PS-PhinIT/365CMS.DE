<?php
/**
 * Zähler und Vertrauensstufe je Mitglied (`community_user_stats`). Die Zeile kann fehlen (bis zum ersten Beitrag):
 * `get()` liefert dann `null`, Vertrauensstufe und Zähler gelten als 0.
 *
 * Schreiben: `ensure()` legt die Zeile an, `lockForUpdate()` sperrt sie (letzte Sperre in der Reihenfolge Forum, Thema,
 * Beitrag, Statistik), `bump()` ändert Zähler atomar (nie unter 0), `touchLastPost()` setzt den Zeitpunkt des letzten
 * Beitrags (auch bei wartenden Beiträgen).
 *
 * Regeln der Dienste (PR #2a, Befunde B2 und B3 der Integrationssuite):
 * - Die Zeile entsteht VOR der Transaktion im Autocommit (`ensure()`). Dann trifft die Sperre in der Transaktion eine vorhandene
 *   Zeile (reine Zeilensperre). Eine Sperre auf eine fehlende Zeile ist unter REPEATABLE READ eine Lückensperre, und zwei
 *   Transaktionen mit solchen Lückensperren blockieren einander, sobald beide anschließend einfügen (Deadlock).
 * - Die Mitgliedersperre kommt vor der ersten nicht sperrenden Leseanweisung der Transaktion (sonst sieht die Rechteprüfung
 *   unter der Sperre einen veralteten Schnappschuss; siehe `Support\Transaction`). `lockForUpdate()` liest deshalb nie ohne Sperre.
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

    /**
     * Legt die Zeile an, falls sie fehlt (ohne Wirkung, wenn sie existiert). Die Dienste rufen das VOR `Transaction::run()` im
     * Autocommit auf (kein Lückensperrkonflikt, keine offene Transaktion); innerhalb einer Transaktion nur nach der Sperre.
     */
    public function ensure(int $userId, string $nowUtc): void
    {
        $this->run('INSERT IGNORE INTO ' . $this->table(self::TABLE) . ' (user_id, updated_at) VALUES (?, ?)', [$userId, $nowUtc]);
    }

    /**
     * Zeile mit Sperre lesen (nur in einer Transaktion sinnvoll). Normalfall: die Zeile existiert, weil der Dienst `ensure()` vor
     * der Transaktion aufgerufen hat; die Sperre ist dann eine reine Zeilensperre ohne Schreibzugriff (kein Konflikt zwischen
     * parallelen Aufrufern desselben Mitglieds). Nur als Absicherung für den Ausnahmefall einer fehlenden Zeile (etwa gelöscht
     * zwischen `ensure()` und Sperre) legt die Methode sie an und sperrt erneut; ein dabei möglicher Sperrkonflikt wird von
     * `Transaction::run()` wiederholt. Die Methode liest nie ohne Sperre: sie darf als erste Anweisung nach den Forum- und
     * Themensperren stehen, ohne den Schnappschuss der Transaktion anzulegen.
     *
     * @return array<string, mixed>
     */
    public function lockForUpdate(int $userId, string $nowUtc): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? LIMIT 1' . self::forUpdate();
        $row = $this->fetchOne($sql, [$userId]);
        if ($row === null) {
            // Ausnahmefall: die Zeile fehlt (Normalfall: ensure() lief vor der Transaktion).
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
