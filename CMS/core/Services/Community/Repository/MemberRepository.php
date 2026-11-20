<?php
/**
 * Lesezugriff auf Mitglieder für die Community: Konto (`users`), aktive Gruppen (`user_group_members` + `user_groups`) und
 * ausgewählte Metadaten (`user_meta`).
 *
 * Nur die Spalten, die Rechte, Vertrauensstufen, Hinweise und Antispam brauchen; nie Passwort oder Geheimnisse. Mengenabfragen
 * (`accounts()`, `metaMany()`) laufen in Stücken zu höchstens CHUNK IDs über PRIMARY bzw. `uq_user_meta`. Metadaten nur für die
 * Schlüssel aus META_KEYS. Schreibzugriffe auf Konten gehören `UserService`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberRepository extends AbstractRepository
{
    /** Lesbare Metadaten: Nachweis der Adresse (`EmailVerificationService`) und Core-Präferenzen der Hinweise (`MemberService`). */
    public const META_KEYS = ['email_verified_at', 'notification_preferences'];

    /** IDs je Mengenabfrage. */
    private const CHUNK = 100;

    /**
     * Konto eines Mitglieds: `status` ist `active`, `inactive`, `banned` oder `unverified`; nur `active` zählt als Mitglied.
     *
     * @return array{id: int, role: string, status: string}|null
     */
    public function find(int $id): ?array
    {
        $row = $this->fetchOne('SELECT id, role, status FROM ' . $this->table('users') . ' WHERE id = ? LIMIT 1', [$id]);

        return $row === null ? null : ['id' => (int) $row['id'], 'role' => (string) $row['role'], 'status' => (string) $row['status']];
    }

    /**
     * IDs der Gruppen, in denen das Mitglied ist; Gruppen mit `is_active = 0` zählen nicht. Aufsteigend sortiert.
     *
     * @return list<int>
     */
    public function groupIds(int $id): array
    {
        $rows = $this->fetchAll(
            'SELECT ugm.group_id FROM ' . $this->table('user_group_members') . ' ugm JOIN ' . $this->table('user_groups')
            . ' ug ON ug.id = ugm.group_id WHERE ugm.user_id = ? AND ug.is_active = 1 ORDER BY ugm.group_id ASC',
            [$id]
        );

        return array_map(static fn (array $row): int => (int) $row['group_id'], $rows);
    }

    /**
     * Kontodaten mehrerer Mitglieder (Empfänger von Hinweisen und Mails, Name und Adresse für die Antispam-Prüfung). Jeder Status;
     * die Entscheidung trifft der Dienst. Unbekannte IDs fehlen; aufsteigend nach ID.
     *
     * @param array<array-key, mixed> $ids
     * @return array<int, array{id: int, username: string, email: string, display_name: string|null, status: string, role: string}>
     */
    public function accounts(array $ids): array
    {
        $map = [];
        foreach (self::batches($ids) as $batch) {
            $rows = $this->fetchAll(
                'SELECT id, username, email, display_name, status, role FROM ' . $this->table('users') . ' WHERE id IN (' . self::placeholders(count($batch)) . ') ORDER BY id ASC',
                $batch
            );
            foreach ($rows as $row) {
                $map[(int) $row['id']] = [
                    'id' => (int) $row['id'],
                    'username' => (string) $row['username'],
                    'email' => (string) $row['email'],
                    'display_name' => $row['display_name'] === null ? null : (string) $row['display_name'],
                    'status' => (string) $row['status'],
                    'role' => (string) $row['role'],
                ];
            }
        }
        ksort($map);

        return $map;
    }

    /**
     * Metadaten mehrerer Mitglieder für die genannten Schlüssel (nur aus META_KEYS, sonst `InvalidArgumentException`). Ergebnis je
     * Mitglied mit mindestens einem Wert, aufsteigend; je Mitglied die Schlüssel alphabetisch; NULL wird zu `''`.
     *
     * @param array<array-key, mixed> $ids
     * @param list<string> $keys
     * @return array<int, array<string, string>>
     */
    public function metaMany(array $ids, array $keys): array
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        $foreign = array_diff($keys, self::META_KEYS);
        if ($foreign !== []) {
            throw new \InvalidArgumentException('Nicht lesbare Metadaten: ' . implode(', ', $foreign));
        }
        if ($keys === []) {
            return [];
        }
        $map = [];
        foreach (self::batches($ids) as $batch) {
            $rows = $this->fetchAll(
                'SELECT user_id, meta_key, meta_value FROM ' . $this->table('user_meta') . ' WHERE user_id IN (' . self::placeholders(count($batch)) . ')'
                . ' AND meta_key IN (' . self::placeholders(count($keys)) . ') ORDER BY user_id ASC, meta_key ASC',
                [...$batch, ...$keys]
            );
            foreach ($rows as $row) {
                $map[(int) $row['user_id']][(string) $row['meta_key']] = (string) ($row['meta_value'] ?? '');
            }
        }
        ksort($map);
        foreach ($map as &$values) {
            ksort($values);
        }
        unset($values);

        return $map;
    }

    /**
     * IDs eindeutig, aufsteigend, in Stücken zu höchstens CHUNK.
     *
     * @param array<array-key, mixed> $ids
     * @return list<list<int>>
     */
    private static function batches(array $ids): array
    {
        $ids = self::ids($ids);
        sort($ids, SORT_NUMERIC);

        return array_chunk($ids, self::CHUNK);
    }
}
