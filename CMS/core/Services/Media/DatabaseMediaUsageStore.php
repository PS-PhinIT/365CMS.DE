<?php
/**
 * Datenbank-Umsetzung von `MediaUsageStoreInterface` (nur gebundene Parameter).
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class DatabaseMediaUsageStore implements MediaUsageStoreInterface
{
    private const CHUNK = 200;

    private Database $db;
    private string $table;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
        $this->table = $this->db->getPrefix() . 'media_usage';
    }

    public function replace(string $contentType, int $contentId, array $references, string $now): void
    {
        $this->delete($contentType, $contentId);
        if ($references === []) {
            return;
        }
        foreach (array_chunk($references, self::CHUNK) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $reference) {
                $placeholders[] = '(?, ?, ?, ?, ?)';
                array_push($params, $reference['path'], $contentType, $contentId, $reference['context'], $now);
            }
            $this->db->execute(
                "INSERT IGNORE INTO {$this->table} (media_path, content_type, content_id, context, updated_at) VALUES " . implode(', ', $placeholders),
                $params
            );
        }
    }

    public function delete(string $contentType, int $contentId): void
    {
        $this->db->execute("DELETE FROM {$this->table} WHERE content_type = ? AND content_id = ?", [$contentType, $contentId]);
    }

    public function rowsForPaths(array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths, static fn (string $p): bool => $p !== '')));
        $rows = [];
        foreach (array_chunk($paths, self::CHUNK) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->db->get_results(
                "SELECT media_path, content_type, content_id, context FROM {$this->table} WHERE media_path IN ({$placeholders})",
                $chunk
            ) as $row) {
                $rows[] = [
                    'media_path' => (string) $row->media_path,
                    'content_type' => (string) $row->content_type,
                    'content_id' => (int) $row->content_id,
                    'context' => (string) $row->context,
                ];
            }
        }

        return $rows;
    }

    public function pruneBefore(string $before): int
    {
        return $this->db->execute("DELETE FROM {$this->table} WHERE updated_at < ?", [$before])->rowCount();
    }
}
