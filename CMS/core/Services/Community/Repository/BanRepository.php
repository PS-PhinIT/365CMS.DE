<?php
/**
 * Lesezugriff auf Community-Sperren (`community_bans`). Anlegen und Aufheben gehört dem Moderationsdienst (PR #2b).
 *
 * Interne Notizen der Moderation stehen nie im Ergebnis: Die Abfrage wählt nur die Spalten, die ein Mitglied sehen darf.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class BanRepository extends AbstractRepository
{
    private const TABLE = 'community_bans';

    /**
     * Wirksame Sperre eines Mitglieds zum Zeitpunkt `$nowUtc`: nicht aufgehoben, begonnen und nicht abgelaufen
     * (`starts_at <= $nowUtc`, `ends_at` leer oder später). Bei mehreren gilt die strengste: alles außer `write` zuerst,
     * dann die jüngste. Ein unbekannter Umfang wird als `full` zurückgegeben (im Zweifel gesperrt).
     * Index `idx_community_bans_user`.
     *
     * @return array{id: int, scope: string, reason: string, ends_at: string|null}|null `scope` ist `write` oder `full`
     */
    public function activeFor(int $userId, string $nowUtc): ?array
    {
        $row = $this->fetchOne(
            'SELECT id, scope, reason, ends_at FROM ' . $this->table(self::TABLE)
            . ' WHERE user_id = ? AND lifted_at IS NULL AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?)'
            . " ORDER BY CASE WHEN scope = 'write' THEN 0 ELSE 1 END DESC, id DESC LIMIT 1",
            [$userId, $nowUtc, $nowUtc]
        );

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'scope' => (string) $row['scope'] === 'write' ? 'write' : 'full',
            'reason' => (string) $row['reason'],
            'ends_at' => $row['ends_at'] === null ? null : (string) $row['ends_at'],
        ];
    }
}
