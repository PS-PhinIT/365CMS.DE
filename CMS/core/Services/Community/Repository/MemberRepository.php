<?php
/**
 * Lesezugriff auf Mitglieder für die Community: Konto (`users`) und aktive Gruppen (`user_group_members` + `user_groups`).
 *
 * Nur die Spalten, die Rechte und Vertrauensstufen brauchen. Schreibzugriffe auf Konten gehören `UserService`.
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
}
