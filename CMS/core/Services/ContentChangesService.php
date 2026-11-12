<?php
/**
 * Übersicht „Letzte Änderungen“ über Beiträge und Seiten (Revisionen und Anlage).
 *
 * Quellen:
 * - `post_revisions`: Zustand **nach** dem Speichern; Änderungsart aus `revision_type` (restore → wiederhergestellt,
 *   autosave → automatisch gesichert) bzw. Statuswechsel gegenüber der Vorgänger-Revision.
 * - `page_revisions`: Zustand **vor** dem Speichern; ein Statuswechsel liegt vor, wenn sich der Status zur
 *   nächsten Revision bzw. zum aktuellen Stand der Seite unterscheidet. Auslöser: `created_by` (ab v25).
 * - `posts`/`pages`: Anlage (`created_at`, Autor) als Ereignis „Erstellt“.
 *
 * Keine Site-Dimension (Klarstellungen); Hub-Sites haben keine Revisionen. Alle Werte sind gebunden.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentChangesService
{
    public const TYPES = ['post', 'page'];
    public const CHANGES = ['created', 'updated', 'status', 'restored', 'autosave'];
    public const PER_PAGE = 50;

    private static ?self $instance = null;
    private Database $db;
    private string $prefix;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    /**
     * @param array{types?:list<string>,type?:string,user_id?:int,change?:string,from?:string,to?:string} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function list(array $filters, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $types = array_values(array_intersect(self::TYPES, $filters['types'] ?? self::TYPES));
        if (!empty($filters['type']) && in_array($filters['type'], $types, true)) {
            $types = [(string) $filters['type']];
        }
        if ($types === []) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }

        [$union, $params] = $this->unionSql($types);
        $where = ['1 = 1'];
        if (!empty($filters['user_id'])) {
            $where[] = 'c.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['change']) && in_array($filters['change'], self::CHANGES, true)) {
            $where[] = 'c.change_type = ?';
            $params[] = (string) $filters['change'];
        }
        if (!empty($filters['from']) && ($from = self::date((string) $filters['from'])) !== null) {
            $where[] = 'c.changed_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if (!empty($filters['to']) && ($to = self::date((string) $filters['to'])) !== null) {
            $where[] = 'c.changed_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);

        try {
            $total = (int) $this->db->get_var("SELECT COUNT(*) FROM ({$union}) c WHERE {$whereSql}", $params);
            $pages = max(1, (int) ceil($total / max(1, $perPage)));
            $page = max(1, min($pages, $page));
            $rows = $total > 0
                ? $this->db->execute(
                    "SELECT c.*, u.username, u.display_name
                     FROM ({$union}) c
                     LEFT JOIN {$this->prefix}users u ON u.id = c.user_id
                     WHERE {$whereSql}
                     ORDER BY c.changed_at DESC, c.sort_id DESC
                     LIMIT ? OFFSET ?",
                    array_merge($params, [max(1, $perPage), ($page - 1) * $perPage])
                )->fetchAll(\PDO::FETCH_ASSOC)
                : [];
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('content.changes')->error('Letzte Änderungen konnten nicht geladen werden.', ['exception' => $e->getMessage()]);

            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }

        return ['items' => array_map(fn (array $row): array => $this->hydrate($row), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Benutzer, die in den Quellen vorkommen (für den Filter).
     *
     * @param list<string> $types
     * @return list<array{id:int,name:string}>
     */
    public function users(array $types = self::TYPES): array
    {
        $ids = [];
        try {
            if (in_array('post', $types, true)) {
                $ids = array_merge($ids, $this->db->get_col("SELECT DISTINCT created_by FROM {$this->prefix}post_revisions WHERE created_by IS NOT NULL"));
                $ids = array_merge($ids, $this->db->get_col("SELECT DISTINCT author_id FROM {$this->prefix}posts WHERE author_id IS NOT NULL"));
            }
            if (in_array('page', $types, true)) {
                if ($this->db->columnExists($this->prefix . 'page_revisions', 'created_by')) {
                    $ids = array_merge($ids, $this->db->get_col("SELECT DISTINCT created_by FROM {$this->prefix}page_revisions WHERE created_by IS NOT NULL"));
                }
                $ids = array_merge($ids, $this->db->get_col("SELECT DISTINCT author_id FROM {$this->prefix}pages WHERE author_id IS NOT NULL"));
            }
        } catch (\Throwable) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->execute(
            "SELECT id, username, display_name FROM {$this->prefix}users WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY display_name, username',
            $ids
        )->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => trim((string) ($row['display_name'] ?? '')) !== '' ? (string) $row['display_name'] : (string) $row['username'],
        ], $rows);
    }

    /**
     * @param list<string> $types
     * @return array{0:string,1:list<mixed>}
     */
    private function unionSql(array $types): array
    {
        $parts = [];
        $p = $this->prefix;
        if (in_array('post', $types, true)) {
            // Revisionen: Zustand nach dem Speichern, Vorgänger per LAG.
            $parts[] = "SELECT 'post' AS content_type, r.post_id AS content_id, r.id AS revision_id, r.id AS sort_id,
                    COALESCE(po.title, r.title) AS title, r.status AS status, r.created_by AS user_id, r.created_at AS changed_at,
                    CASE
                        WHEN r.revision_type = 'restore' THEN 'restored'
                        WHEN r.revision_type = 'autosave' THEN 'autosave'
                        WHEN r.prev_status IS NOT NULL AND r.prev_status <> r.status THEN 'status'
                        ELSE 'updated'
                    END AS change_type,
                    r.prev_status AS previous_status, po.trashed_at AS trashed_at, (po.id IS NULL) AS deleted
                FROM (
                    SELECT pr.*, LAG(pr.status) OVER (PARTITION BY pr.post_id ORDER BY pr.id) AS prev_status
                    FROM {$p}post_revisions pr
                    WHERE pr.revision_type <> 'baseline'
                ) r
                LEFT JOIN {$p}posts po ON po.id = r.post_id";
            $parts[] = "SELECT 'post' AS content_type, po.id AS content_id, 0 AS revision_id, 0 AS sort_id, po.title AS title,
                    po.status AS status, po.author_id AS user_id, po.created_at AS changed_at, 'created' AS change_type,
                    NULL AS previous_status, po.trashed_at AS trashed_at, 0 AS deleted
                FROM {$p}posts po";
        }
        if (in_array('page', $types, true)) {
            $createdBy = $this->db->columnExists($p . 'page_revisions', 'created_by') ? 'r.created_by' : 'NULL';
            // Revisionen: Zustand vor dem Speichern, der neue Status steht in der nächsten Revision bzw. der Seite.
            $parts[] = "SELECT 'page' AS content_type, r.page_id AS content_id, r.id AS revision_id, r.id AS sort_id,
                    COALESCE(pa.title, r.title) AS title, COALESCE(r.next_status, pa.status) AS status, {$createdBy} AS user_id,
                    r.created_at AS changed_at,
                    CASE WHEN r.status IS NOT NULL AND COALESCE(r.next_status, pa.status) IS NOT NULL AND r.status <> COALESCE(r.next_status, pa.status)
                         THEN 'status' ELSE 'updated' END AS change_type,
                    r.status AS previous_status, pa.trashed_at AS trashed_at, (pa.id IS NULL) AS deleted
                FROM (
                    SELECT pr.*, LEAD(pr.status) OVER (PARTITION BY pr.page_id ORDER BY pr.id) AS next_status
                    FROM {$p}page_revisions pr
                ) r
                LEFT JOIN {$p}pages pa ON pa.id = r.page_id";
            $parts[] = "SELECT 'page' AS content_type, pa.id AS content_id, 0 AS revision_id, 0 AS sort_id, pa.title AS title,
                    pa.status AS status, pa.author_id AS user_id, pa.created_at AS changed_at, 'created' AS change_type,
                    NULL AS previous_status, pa.trashed_at AS trashed_at, 0 AS deleted
                FROM {$p}pages pa";
        }

        return [implode("\nUNION ALL\n", $parts), []];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $type = (string) $row['content_type'];
        $id = (int) $row['content_id'];
        $revision = (int) $row['revision_id'];
        $deleted = (int) ($row['deleted'] ?? 0) === 1;
        $trashed = !empty($row['trashed_at']);
        $editUrl = '';
        if (!$deleted && !$trashed) {
            $editUrl = $type === 'post'
                ? '/admin/posts?action=edit&id=' . $id . ($revision > 0 ? '&revision=' . $revision : '')
                : '/admin/pages?action=edit&id=' . $id;
        }

        return [
            'type' => $type,
            'id' => $id,
            'revision_id' => $revision,
            'title' => (string) ($row['title'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'previous_status' => (string) ($row['previous_status'] ?? ''),
            'change' => (string) $row['change_type'],
            'changed_at' => (string) $row['changed_at'],
            'user_id' => (int) ($row['user_id'] ?? 0),
            'user' => trim((string) ($row['display_name'] ?? '')) !== '' ? (string) $row['display_name'] : (string) ($row['username'] ?? ''),
            'trashed' => $trashed,
            'deleted' => $deleted,
            'edit_url' => $editUrl,
            'compare' => $type === 'post' && $revision > 0 && $editUrl !== '',
        ];
    }

    private static function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
