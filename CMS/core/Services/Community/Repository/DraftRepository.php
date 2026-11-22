<?php
/**
 * Server-Entwürfe der Community: `community_drafts`, ein Entwurf je Mitglied und Ziel (`target_type` `forum` = neues Thema im Forum
 * `target_id`, `topic` = Antwort im Thema `target_id`). Zusammengesetzter Schlüssel ohne AUTO_INCREMENT; Speichern ist ein portables Upsert (UPDATE, dann INSERT IGNORE).
 * Die Löschfrist `community.drafts` (30 Tage nach `updated_at`) räumt `CommunityRetention` auf.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class DraftRepository extends AbstractRepository
{
    private const TABLE = 'community_drafts';

    /**
     * Entwurf eines Ziels oder null.
     *
     * @return array{title: ?string, body: string, updated_at: string}|null
     */
    public function find(int $userId, string $type, int $targetId): ?array
    {
        $row = $this->fetchOne(
            'SELECT title, body, updated_at FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND target_type = ? AND target_id = ?',
            [$userId, $type, $targetId]
        );
        if ($row === null) {
            return null;
        }

        return [
            'title' => $row['title'] === null ? null : (string) $row['title'],
            'body' => (string) ($row['body'] ?? ''),
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Speichert (anlegen oder überschreiben): erst `UPDATE`, bei 0 Zeilen `INSERT IGNORE` (portabel, ohne MariaDB-Sonderform). Zwei gleichzeitige
     * Erstspeicherungen desselben Ziels ergeben eine Zeile (die zweite wird ignoriert); ein Entwurf ist kein Zähler, ein verlorenes Überschreiben genügt.
     */
    public function save(int $userId, string $type, int $targetId, ?string $title, string $body, string $nowUtc): void
    {
        $table = $this->table(self::TABLE);
        $updated = $this->run(
            "UPDATE {$table} SET title = ?, body = ?, updated_at = ? WHERE user_id = ? AND target_type = ? AND target_id = ?",
            [$title, $body, $nowUtc, $userId, $type, $targetId]
        );
        if ($updated === 0) {
            $this->run(
                "INSERT IGNORE INTO {$table} (user_id, target_type, target_id, title, body, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $type, $targetId, $title, $body, $nowUtc]
            );
        }
    }

    /** Löscht den Entwurf eines Ziels; Zahl der gelöschten Zeilen (0 oder 1). */
    public function delete(int $userId, string $type, int $targetId): int
    {
        return $this->run(
            'DELETE FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND target_type = ? AND target_id = ?',
            [$userId, $type, $targetId]
        );
    }
}
