<?php
/**
 * Reaktionen auf Beiträge (`community_reactions`, eine je Mitglied und Beitrag; PR #2b, Entwurf interaktion §1.2, §2.5).
 *
 * Regeln: Schreiber sperren vorher Thema und Beitrag (`ReactionService`, Ebenen 2 und 3), deshalb genügen hier Einzelanweisungen
 * über PRIMARY (post_id, user_id) ohne eigene Sperre. Mengenabfragen in Stücken zu höchstens CHUNK IDs. Keine Uhr, keine Transaktion.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class ReactionRepository extends AbstractRepository
{
    /** IDs je Mengenabfrage. */
    public const CHUNK = 100;
    /** Größte Seite von `forPost()`. */
    public const MAX_LIMIT = 500;

    private const TABLE = 'community_reactions';
    /** Länge der Spalte `reaction`. */
    private const KEY_MAX = 20;
    private const KEY_PATTERN = '/^[a-z][a-z_]{0,19}$/D';
    private const MAX_UINT = 4294967295;

    /** Reaktion des Mitglieds auf den Beitrag oder `null`. */
    public function find(int $postId, int $userId): ?string
    {
        $value = $this->fetchValue('SELECT reaction FROM ' . $this->table(self::TABLE) . ' WHERE post_id = ? AND user_id = ? LIMIT 1', [$postId, $userId]);

        return $value === null ? null : (string) $value;
    }

    /** Legt die Reaktion an (`INSERT IGNORE`, PRIMARY). `true` = neu. */
    public function insert(int $postId, int $userId, string $reaction, string $nowUtc): bool
    {
        self::pair($postId, $userId);
        self::key($reaction);

        return $this->run(
            'INSERT IGNORE INTO ' . $this->table(self::TABLE) . ' (post_id, user_id, reaction, created_at) VALUES (?, ?, ?, ?)',
            [$postId, $userId, $reaction, $nowUtc]
        ) > 0;
    }

    /** Wechselt die Art einer vorhandenen Reaktion. `true` = geändert (gleiche Art oder keine Zeile: `false`). */
    public function change(int $postId, int $userId, string $reaction, string $nowUtc): bool
    {
        self::pair($postId, $userId);
        self::key($reaction);

        return $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET reaction = ?, created_at = ? WHERE post_id = ? AND user_id = ? AND reaction <> ?',
            [$reaction, $nowUtc, $postId, $userId, $reaction]
        ) > 0;
    }

    /** Entfernt die Reaktion. `true` = entfernt. */
    public function delete(int $postId, int $userId): bool
    {
        self::pair($postId, $userId);

        return $this->run('DELETE FROM ' . $this->table(self::TABLE) . ' WHERE post_id = ? AND user_id = ?', [$postId, $userId]) > 0;
    }

    /**
     * Zahl je Art und Beitrag (Beiträge ohne Reaktion fehlen), Arten alphabetisch.
     *
     * @param array<array-key, mixed> $postIds
     * @return array<int, array<string, int>>
     */
    public function counts(array $postIds): array
    {
        $map = [];
        foreach (array_chunk(self::ids($postIds), self::CHUNK) as $chunk) {
            $rows = $this->fetchAll(
                'SELECT post_id, reaction, COUNT(*) AS n FROM ' . $this->table(self::TABLE) . ' WHERE post_id IN (' . self::placeholders(count($chunk)) . ') GROUP BY post_id, reaction',
                $chunk
            );
            foreach ($rows as $row) {
                $map[(int) $row['post_id']][(string) $row['reaction']] = (int) $row['n'];
            }
        }
        foreach ($map as &$counts) {
            ksort($counts);
        }
        unset($counts);

        return $map;
    }

    /**
     * Eigene Reaktionen eines Mitglieds auf die Beiträge.
     *
     * @param array<array-key, mixed> $postIds
     * @return array<int, string>
     */
    public function mine(int $userId, array $postIds): array
    {
        if ($userId < 1) {
            return [];
        }
        $map = [];
        foreach (array_chunk(self::ids($postIds), self::CHUNK) as $chunk) {
            $rows = $this->fetchAll(
                'SELECT post_id, reaction FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND post_id IN (' . self::placeholders(count($chunk)) . ')',
                [$userId, ...$chunk]
            );
            foreach ($rows as $row) {
                $map[(int) $row['post_id']] = (string) $row['reaction'];
            }
        }

        return $map;
    }

    /**
     * Reagierende eines Beitrags, aufsteigend nach Mitglied ab `$afterUserId` (ausschließlich), PRIMARY.
     *
     * @return list<array{user_id: int, reaction: string, created_at: string}>
     */
    public function forPost(int $postId, int $afterUserId, int $limit): array
    {
        if ($postId < 1 || $afterUserId < 0 || $afterUserId > self::MAX_UINT || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('Ungültige Abfrage der Reaktionen.');
        }
        $rows = $this->fetchAll(
            'SELECT user_id, reaction, created_at FROM ' . $this->table(self::TABLE) . ' WHERE post_id = ? AND user_id > ? ORDER BY user_id ASC LIMIT ?',
            [$postId, $afterUserId, $limit]
        );

        return array_map(static fn (array $row): array => ['user_id' => (int) $row['user_id'], 'reaction' => (string) $row['reaction'], 'created_at' => (string) $row['created_at']], $rows);
    }

    private static function pair(int $postId, int $userId): void
    {
        if ($postId < 1 || $userId < 1 || $userId > self::MAX_UINT) {
            throw new \InvalidArgumentException('Ungültiger Beitrag oder ungültiges Mitglied.');
        }
    }

    private static function key(string $reaction): void
    {
        if (strlen($reaction) > self::KEY_MAX || preg_match(self::KEY_PATTERN, $reaction) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Reaktionsschlüssel.');
        }
    }
}
