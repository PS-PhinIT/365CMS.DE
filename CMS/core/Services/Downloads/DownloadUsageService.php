<?php
/**
 * „Verwendet in“-Index der Downloads.
 *
 * Wird über die Inhalts-Hooks (`cms_after_post_save`, `cms_after_page_save`, `post_deleted`, `page_deleted`)
 * beim Speichern aktuell gehalten und kann jederzeit vollständig neu aufgebaut werden (Admin, täglicher Cron).
 * Erfasst werden Download-Blöcke (`type: download`, `data.downloadIds`) auch in verschachtelten Blöcken
 * (z. B. Spalten) in `content` und `content_en`.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Downloads;

use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class DownloadUsageService
{
    /** @var array<string,array{table:string,edit:string}> */
    private const CONTENT_TYPES = [
        'post' => ['table' => 'posts', 'edit' => '/admin/posts?action=edit&id='],
        'page' => ['table' => 'pages', 'edit' => '/admin/pages?action=edit&id='],
    ];

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * IDs aller referenzierten Downloads in einem Inhalt (Editor.js-JSON).
     *
     * @return list<int>
     */
    public static function extractIds(string $content): array
    {
        $content = trim($content);
        if ($content === '' || !str_contains($content, 'download') || ($content[0] !== '{' && $content[0] !== '[')) {
            return [];
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }
        $ids = [];
        self::walk($decoded, $ids, 0);

        return array_keys($ids);
    }

    /**
     * @param array<mixed> $node
     * @param array<int,bool> $ids
     */
    private static function walk(array $node, array &$ids, int $depth): void
    {
        if ($depth > 16) {
            return;
        }
        if (($node['type'] ?? null) === 'download' && is_array($node['data'] ?? null)) {
            foreach ((array) ($node['data']['downloadIds'] ?? []) as $id) {
                if (is_numeric($id) && (int) $id > 0) {
                    $ids[(int) $id] = true;
                }
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::walk($value, $ids, $depth + 1);
            }
        }
    }

    /**
     * Index für einen Beitrag/eine Seite aus der Datenbank aktualisieren.
     */
    public function syncContent(string $type, int $contentId): void
    {
        $repo = DownloadRepository::getInstance();
        if ($contentId <= 0 || !isset(self::CONTENT_TYPES[$type]) || !$repo->isAvailable()) {
            return;
        }

        try {
            $db = $repo->db();
            $row = $repo->fetchOne(
                'SELECT content, content_en FROM ' . $db->getPrefix() . self::CONTENT_TYPES[$type]['table'] . ' WHERE id = ? LIMIT 1',
                [$contentId]
            );
            if ($row === null) {
                $this->removeContent($type, $contentId);

                return;
            }
            $ids = array_values(array_unique(array_merge(
                self::extractIds((string) ($row['content'] ?? '')),
                self::extractIds((string) ($row['content_en'] ?? ''))
            )));
            $this->writeIndex($type, $contentId, $ids);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('downloads')->warning('Verwendet-in-Index konnte nicht aktualisiert werden.', [
                'type' => $type,
                'id' => $contentId,
                'exception' => $e::class,
            ]);
        }
    }

    public function removeContent(string $type, int $contentId): void
    {
        $repo = DownloadRepository::getInstance();
        if (!isset(self::CONTENT_TYPES[$type]) || !$repo->isAvailable()) {
            return;
        }
        try {
            $repo->db()->execute('DELETE FROM ' . $repo->table('downloads_usage') . ' WHERE content_type = ? AND content_id = ?', [$type, $contentId]);
        } catch (\Throwable) {
        }
    }

    /**
     * Gesamten Index aus allen Beiträgen und Seiten neu aufbauen.
     *
     * @return int Anzahl der Einträge
     */
    public function rebuild(): int
    {
        $repo = DownloadRepository::getInstance();
        if (!$repo->isAvailable()) {
            return 0;
        }
        $db = $repo->db();
        $entries = [];
        foreach (self::CONTENT_TYPES as $type => $config) {
            $table = $db->getPrefix() . $config['table'];
            if (!$db->tableExists($table)) {
                continue;
            }
            $lastId = 0;
            do {
                $rows = $repo->fetchAll(
                    "SELECT id, content, content_en FROM {$table} WHERE id > ? AND (content LIKE ? OR content_en LIKE ?) ORDER BY id ASC LIMIT 200",
                    [$lastId, '%"download"%', '%"download"%']
                );
                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    $ids = array_unique(array_merge(self::extractIds((string) ($row['content'] ?? '')), self::extractIds((string) ($row['content_en'] ?? ''))));
                    foreach ($ids as $downloadId) {
                        $entries[] = [$downloadId, $type, $lastId];
                    }
                }
            } while (count($rows) === 200);
        }

        $existing = array_keys($repo->findItemsByIds(array_column($entries, 0)));
        $pdo = $db->getPdo();
        try {
            $pdo->beginTransaction();
            $db->execute('DELETE FROM ' . $repo->table('downloads_usage'));
            $count = 0;
            foreach ($entries as [$downloadId, $type, $contentId]) {
                if (!in_array($downloadId, $existing, true)) {
                    continue;
                }
                $db->execute(
                    'INSERT IGNORE INTO ' . $repo->table('downloads_usage') . ' (download_id, content_type, content_id) VALUES (?, ?, ?)',
                    [$downloadId, $type, $contentId]
                );
                $count++;
            }
            $pdo->commit();

            return $count;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::instance()->withChannel('downloads')->error('Verwendet-in-Index konnte nicht neu aufgebaut werden.', ['exception' => $e::class]);

            return 0;
        }
    }

    /**
     * @return list<array{content_type:string,content_id:int,title:string,status:string,edit_url:string}>
     */
    public function usagesFor(int $downloadId): array
    {
        $repo = DownloadRepository::getInstance();
        if ($downloadId <= 0 || !$repo->isAvailable()) {
            return [];
        }
        $db = $repo->db();
        $result = [];
        foreach (self::CONTENT_TYPES as $type => $config) {
            $table = $db->getPrefix() . $config['table'];
            try {
                $rows = $repo->fetchAll(
                    'SELECT c.id, c.title, c.status FROM ' . $repo->table('downloads_usage') . " u JOIN {$table} c ON c.id = u.content_id
                     WHERE u.download_id = ? AND u.content_type = ? ORDER BY c.title ASC LIMIT 500",
                    [$downloadId, $type]
                );
            } catch (\Throwable) {
                $rows = [];
            }
            foreach ($rows as $row) {
                $result[] = [
                    'content_type' => $type,
                    'content_id' => (int) $row['id'],
                    'title' => (string) ($row['title'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'edit_url' => $config['edit'] . (int) $row['id'],
                ];
            }
        }

        return $result;
    }

    /**
     * @param list<int> $downloadIds
     * @return array<int,int>
     */
    public function usageCounts(array $downloadIds): array
    {
        $repo = DownloadRepository::getInstance();
        $ids = DownloadRepository::intList($downloadIds);
        if ($ids === [] || !$repo->isAvailable()) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $counts = [];
        foreach ($repo->fetchAll(
            'SELECT download_id, COUNT(*) AS total FROM ' . $repo->table('downloads_usage') . " WHERE download_id IN ({$placeholders}) GROUP BY download_id",
            $ids
        ) as $row) {
            $counts[(int) $row['download_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Nicht verfügbare Downloads in Inhalten (fehlend, Entwurf, archiviert, Papierkorb, ohne aktuelle Version).
     *
     * @param list<string> $contents
     * @return list<array{id:int,label:string,reason:string}>
     */
    public function unavailableReferences(array $contents): array
    {
        $ids = [];
        foreach ($contents as $content) {
            foreach (self::extractIds((string) $content) as $id) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $repo = DownloadRepository::getInstance();
        if (!$repo->isAvailable()) {
            return [];
        }

        $items = $repo->findItemsByIds(array_keys($ids));
        $result = [];
        foreach (array_keys($ids) as $id) {
            $row = $items[$id] ?? null;
            if ($row === null) {
                $result[] = ['id' => $id, 'label' => '#' . $id, 'reason' => 'missing'];
                continue;
            }
            $status = (string) $row['status'];
            if ($status !== 'published') {
                $result[] = ['id' => $id, 'label' => (string) $row['title'], 'reason' => $status];
            } elseif (empty($row['current_version_id'])) {
                $result[] = ['id' => $id, 'label' => (string) $row['title'], 'reason' => 'no_version'];
            }
        }

        return $result;
    }

    /**
     * @param list<int> $downloadIds
     */
    private function writeIndex(string $type, int $contentId, array $downloadIds): void
    {
        $repo = DownloadRepository::getInstance();
        $db = $repo->db();
        $existing = $downloadIds !== [] ? array_keys($repo->findItemsByIds($downloadIds)) : [];
        $db->execute('DELETE FROM ' . $repo->table('downloads_usage') . ' WHERE content_type = ? AND content_id = ?', [$type, $contentId]);
        foreach ($existing as $downloadId) {
            $db->execute(
                'INSERT IGNORE INTO ' . $repo->table('downloads_usage') . ' (download_id, content_type, content_id) VALUES (?, ?, ?)',
                [$downloadId, $type, $contentId]
            );
        }
    }
}
