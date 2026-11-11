<?php
/**
 * Datenzugriff der Downloads (Entitäten, Versionen, Kategorien/Schlagwörter, Eigenschaftswerte).
 *
 * Alle Abfragen sind gebunden; Bezeichner (Tabellen, Sortierung) stammen ausschließlich aus Whitelists.
 * Die Tabellen legt das Schema an – fehlen sie, meldet {@see isAvailable()} false und die Funktion
 * bleibt still deaktiviert (einmaliger Logeintrag).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Downloads;

use CMS\Database;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class DownloadRepository
{
    public const TABLES = [
        'downloads_terms',
        'downloads_items',
        'downloads_versions',
        'downloads_item_terms',
        'downloads_property_values',
        'downloads_usage',
        'downloads_events',
        'downloads_stats_daily',
        'downloads_rate_limits',
    ];

    private const SORTS = [
        'updated' => 'i.updated_at DESC, i.id DESC',
        'created' => 'i.created_at DESC, i.id DESC',
        'title' => 'i.title ASC, i.id ASC',
        'downloads' => 'i.download_count DESC, i.title ASC',
        'manual' => 'i.is_featured DESC, i.sort_order ASC, i.title ASC',
        'newest' => 'COALESCE(v.release_date, DATE(i.published_at), DATE(i.created_at)) DESC, i.id DESC',
    ];

    private const ITEM_COLUMNS = [
        'title', 'slug', 'summary', 'description', 'category_id', 'cover_media', 'file_type', 'author_name', 'license',
        'requirements', 'external_links', 'is_signed', 'signature_info', 'access_level', 'access_roles', 'access_plans',
        'status', 'previous_status', 'trashed_at', 'trashed_by', 'is_featured', 'sort_order', 'seo_title', 'seo_description',
        'seo_noindex', 'download_count', 'download_count_base', 'legacy_id', 'created_by', 'updated_by', 'published_at',
        'created_at', 'updated_at',
    ];

    private const VERSION_COLUMNS = [
        'download_id', 'version', 'status', 'current_flag', 'file_area', 'file_path', 'file_name', 'file_ext', 'mime_type',
        'file_size', 'sha256', 'external_url', 'release_date', 'changelog', 'download_count', 'legacy_id', 'legacy_path',
        'created_by', 'created_at',
    ];

    private const TERM_COLUMNS = ['term_type', 'name', 'slug', 'description', 'icon', 'status', 'sort_order', 'legacy_id'];

    private static ?self $instance = null;

    private ?bool $available = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public function db(): Database
    {
        return Database::instance();
    }

    public function table(string $name): string
    {
        if (!in_array($name, self::TABLES, true)) {
            throw new \InvalidArgumentException('Unbekannte Downloads-Tabelle.');
        }

        return $this->db()->getPrefix() . $name;
    }

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $db = $this->db();
            $names = array_map(static fn (string $t): string => $db->getPrefix() . $t, self::TABLES);
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $found = (int) $db->get_var(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
                $names
            );
            $this->available = $found === count($names);
        } catch (\Throwable $e) {
            $this->available = false;
        }

        if (!$this->available) {
            Logger::instance()->withChannel('downloads')->warning('Downloads-Tabellen fehlen – Funktion bleibt deaktiviert, bis das Schema angelegt ist.');
        }

        return $this->available;
    }

    /** Nur für Tests und nach Schema-Aktualisierung. */
    public function resetAvailability(): void
    {
        $this->available = null;
    }

    // ── Downloads ────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    public function findItem(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return $this->fetchOne($this->itemSelect() . ' WHERE i.id = ? LIMIT 1', [$id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findItemBySlug(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        return $this->fetchOne($this->itemSelect() . ' WHERE i.slug = ? LIMIT 1', [$slug]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findItemByLegacyId(int $legacyId): ?array
    {
        return $this->fetchOne($this->itemSelect() . ' WHERE i.legacy_id = ? LIMIT 1', [$legacyId]);
    }

    /**
     * @param list<int> $ids
     * @return array<int,array<string,mixed>>
     */
    public function findItemsByIds(array $ids): array
    {
        $ids = self::intList($ids);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->fetchAll($this->itemSelect() . " WHERE i.id IN ({$placeholders})", $ids);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = $row;
        }

        return $result;
    }

    public function slugExists(string $slug, int $exceptId = 0): bool
    {
        return (int) $this->db()->get_var(
            'SELECT COUNT(*) FROM ' . $this->table('downloads_items') . ' WHERE slug = ? AND id <> ?',
            [$slug, $exceptId]
        ) > 0;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insertItem(array $data): int
    {
        $id = $this->db()->insert('downloads_items', $this->filterColumns($data, self::ITEM_COLUMNS));

        return is_int($id) ? $id : 0;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function updateItem(int $id, array $data): bool
    {
        $data = $this->filterColumns($data, self::ITEM_COLUMNS);

        return $data === [] || $this->db()->update('downloads_items', $data, ['id' => $id]);
    }

    public function deleteItem(int $id): bool
    {
        return $this->db()->delete('downloads_items', ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function searchItems(array $filters, int $limit, int $offset, string $sort = 'updated'): array
    {
        [$where, $params] = $this->buildFilter($filters);
        $order = self::SORTS[$sort] ?? self::SORTS['updated'];
        $params[] = max(1, min(500, $limit));
        $params[] = max(0, $offset);

        return $this->fetchAll($this->itemSelect() . " WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?", $params);
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function countItems(array $filters): int
    {
        [$where, $params] = $this->buildFilter($filters);

        return (int) $this->db()->get_var(
            'SELECT COUNT(*) FROM ' . $this->table('downloads_items') . ' i LEFT JOIN ' . $this->table('downloads_versions')
            . " v ON v.download_id = i.id AND v.current_flag = 1 WHERE {$where}",
            $params
        );
    }

    /**
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(DownloadSettings::STATUSES, 0);
        foreach ($this->fetchAll('SELECT status, COUNT(*) AS total FROM ' . $this->table('downloads_items') . ' GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public function usedFileTypes(): array
    {
        return array_values(array_filter(array_map('strval', $this->db()->get_col(
            'SELECT DISTINCT file_type FROM ' . $this->table('downloads_items') . " WHERE file_type <> '' AND status <> 'trash' ORDER BY file_type"
        ))));
    }

    /**
     * @param list<int> $ids
     * @param array<string,mixed> $data
     */
    public function updateItems(array $ids, array $data): int
    {
        $ids = self::intList($ids);
        $data = $this->filterColumns($data, self::ITEM_COLUMNS);
        if ($ids === [] || $data === []) {
            return 0;
        }
        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = "`{$column}` = ?";
            $params[] = $value;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->db()->execute(
            'UPDATE ' . $this->table('downloads_items') . ' SET ' . implode(', ', $set) . " WHERE id IN ({$placeholders})",
            array_merge($params, $ids)
        )->rowCount();
    }

    // ── Versionen ────────────────────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function versionsFor(int $downloadId): array
    {
        return $this->fetchAll(
            'SELECT * FROM ' . $this->table('downloads_versions')
            . ' WHERE download_id = ? ORDER BY (current_flag IS NULL) ASC, release_date DESC, id DESC',
            [$downloadId]
        );
    }

    /**
     * @param list<int> $downloadIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function versionsForItems(array $downloadIds): array
    {
        $ids = self::intList($downloadIds);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = [];
        foreach ($this->fetchAll(
            'SELECT * FROM ' . $this->table('downloads_versions')
            . " WHERE download_id IN ({$placeholders}) ORDER BY (current_flag IS NULL) ASC, release_date DESC, id DESC",
            $ids
        ) as $row) {
            $result[(int) $row['download_id']][] = $row;
        }

        return $result;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findVersion(int $id): ?array
    {
        return $id > 0 ? $this->fetchOne('SELECT * FROM ' . $this->table('downloads_versions') . ' WHERE id = ? LIMIT 1', [$id]) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findVersionByNumber(int $downloadId, string $version): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM ' . $this->table('downloads_versions') . ' WHERE download_id = ? AND version = ? LIMIT 1',
            [$downloadId, $version]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function currentVersion(int $downloadId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM ' . $this->table('downloads_versions') . ' WHERE download_id = ? AND current_flag = 1 LIMIT 1',
            [$downloadId]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findVersionByLegacyPath(string $legacyPath): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM ' . $this->table('downloads_versions') . ' WHERE legacy_path = ? ORDER BY id ASC LIMIT 1',
            [$legacyPath]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findVersionByLegacyId(int $legacyId): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . $this->table('downloads_versions') . ' WHERE legacy_id = ? LIMIT 1', [$legacyId]);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insertVersion(array $data): int
    {
        $id = $this->db()->insert('downloads_versions', $this->filterColumns($data, self::VERSION_COLUMNS));

        return is_int($id) ? $id : 0;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function updateVersion(int $id, array $data): bool
    {
        $data = $this->filterColumns($data, self::VERSION_COLUMNS);

        return $data === [] || $this->db()->update('downloads_versions', $data, ['id' => $id]);
    }

    public function deleteVersion(int $id): bool
    {
        return $this->db()->delete('downloads_versions', ['id' => $id]);
    }

    /**
     * Setzt genau eine Version als aktuell (alle anderen werden archiviert).
     */
    public function makeCurrent(int $downloadId, int $versionId): bool
    {
        $pdo = $this->db()->getPdo();
        $ownTransaction = !$pdo->inTransaction();
        try {
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }
            $table = $this->table('downloads_versions');
            $this->db()->execute("UPDATE {$table} SET current_flag = NULL, status = 'archived' WHERE download_id = ? AND id <> ?", [$downloadId, $versionId]);
            $updated = $this->db()->execute("UPDATE {$table} SET current_flag = 1, status = 'current' WHERE id = ? AND download_id = ?", [$versionId, $downloadId])->rowCount();
            if ($updated < 1 && $this->currentVersion($downloadId) === null) {
                throw new \RuntimeException('Version gehört nicht zum Download.');
            }
            if ($ownTransaction) {
                $pdo->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::instance()->withChannel('downloads')->warning('Aktuelle Version konnte nicht gesetzt werden.', ['download_id' => $downloadId, 'version_id' => $versionId, 'exception' => $e::class]);

            return false;
        }
    }

    public function countVersions(int $downloadId): int
    {
        return (int) $this->db()->get_var('SELECT COUNT(*) FROM ' . $this->table('downloads_versions') . ' WHERE download_id = ?', [$downloadId]);
    }

    // ── Kategorien & Schlagwörter ────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function terms(string $type, bool $onlyActive = false): array
    {
        $sql = 'SELECT t.*, (SELECT COUNT(*) FROM ' . $this->table('downloads_items') . " i WHERE i.category_id = t.id AND i.status <> 'trash') AS item_count FROM "
            . $this->table('downloads_terms') . ' t WHERE t.term_type = ?';
        if ($type === 'tag') {
            $sql = 'SELECT t.*, (SELECT COUNT(*) FROM ' . $this->table('downloads_item_terms') . ' it WHERE it.term_id = t.id) AS item_count FROM '
                . $this->table('downloads_terms') . ' t WHERE t.term_type = ?';
        }
        if ($onlyActive) {
            $sql .= " AND t.status = 'active'";
        }

        return $this->fetchAll($sql . ' ORDER BY t.sort_order ASC, t.name ASC', [$type]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findTerm(int $id, string $type = 'category'): ?array
    {
        return $id > 0 ? $this->fetchOne('SELECT * FROM ' . $this->table('downloads_terms') . ' WHERE id = ? AND term_type = ? LIMIT 1', [$id, $type]) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findTermBySlug(string $type, string $slug): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . $this->table('downloads_terms') . ' WHERE term_type = ? AND slug = ? LIMIT 1', [$type, $slug]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findTermByLegacyId(string $type, int $legacyId): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . $this->table('downloads_terms') . ' WHERE term_type = ? AND legacy_id = ? LIMIT 1', [$type, $legacyId]);
    }

    public function termSlugExists(string $type, string $slug, int $exceptId = 0): bool
    {
        return (int) $this->db()->get_var(
            'SELECT COUNT(*) FROM ' . $this->table('downloads_terms') . ' WHERE term_type = ? AND slug = ? AND id <> ?',
            [$type, $slug, $exceptId]
        ) > 0;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insertTerm(array $data): int
    {
        $id = $this->db()->insert('downloads_terms', $this->filterColumns($data, self::TERM_COLUMNS));

        return is_int($id) ? $id : 0;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function updateTerm(int $id, array $data): bool
    {
        $data = $this->filterColumns($data, self::TERM_COLUMNS);

        return $data === [] || $this->db()->update('downloads_terms', $data, ['id' => $id]);
    }

    public function deleteTerm(int $id): bool
    {
        return $this->db()->delete('downloads_terms', ['id' => $id]);
    }

    /**
     * @param list<int> $downloadIds
     * @return array<int,list<array{id:int,name:string,slug:string}>>
     */
    public function tagsForItems(array $downloadIds): array
    {
        $ids = self::intList($downloadIds);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = [];
        foreach ($this->fetchAll(
            'SELECT it.download_id, t.id, t.name, t.slug FROM ' . $this->table('downloads_item_terms') . ' it JOIN '
            . $this->table('downloads_terms') . " t ON t.id = it.term_id AND t.term_type = 'tag' WHERE it.download_id IN ({$placeholders}) ORDER BY t.name ASC",
            $ids
        ) as $row) {
            $result[(int) $row['download_id']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'slug' => (string) $row['slug']];
        }

        return $result;
    }

    /**
     * @param list<int> $termIds
     */
    public function setItemTags(int $downloadId, array $termIds): void
    {
        $this->db()->execute('DELETE FROM ' . $this->table('downloads_item_terms') . ' WHERE download_id = ?', [$downloadId]);
        foreach (self::intList($termIds) as $termId) {
            $this->db()->execute(
                'INSERT IGNORE INTO ' . $this->table('downloads_item_terms') . ' (download_id, term_id) VALUES (?, ?)',
                [$downloadId, $termId]
            );
        }
    }

    // ── Eigenschaftswerte ────────────────────────────────────────────────────

    /**
     * @param list<int> $downloadIds
     * @return array<int,array<string,string>>
     */
    public function propertyValuesForItems(array $downloadIds): array
    {
        $ids = self::intList($downloadIds);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = [];
        foreach ($this->fetchAll(
            'SELECT download_id, field_key, value FROM ' . $this->table('downloads_property_values') . " WHERE download_id IN ({$placeholders})",
            $ids
        ) as $row) {
            $result[(int) $row['download_id']][(string) $row['field_key']] = (string) ($row['value'] ?? '');
        }

        return $result;
    }

    /**
     * @param array<string,string> $values
     */
    public function setPropertyValues(int $downloadId, array $values): void
    {
        $this->db()->execute('DELETE FROM ' . $this->table('downloads_property_values') . ' WHERE download_id = ?', [$downloadId]);
        foreach ($values as $key => $value) {
            $this->db()->execute(
                'INSERT INTO ' . $this->table('downloads_property_values') . ' (download_id, field_key, value) VALUES (?, ?, ?)',
                [$downloadId, (string) $key, (string) $value]
            );
        }
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->db()->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $rows = $this->db()->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    public static function intList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $result[(int) $value] = true;
            }
        }

        return array_keys($result);
    }

    private function itemSelect(): string
    {
        return 'SELECT i.*, c.name AS category_name, c.slug AS category_slug, '
            . 'v.id AS current_version_id, v.version AS current_version, v.file_size AS current_size, v.file_name AS current_file_name, '
            . 'v.file_ext AS current_ext, v.external_url AS current_external_url, v.sha256 AS current_sha256, '
            . 'v.release_date AS current_release_date, v.changelog AS current_changelog '
            . 'FROM ' . $this->table('downloads_items') . ' i '
            . 'LEFT JOIN ' . $this->table('downloads_terms') . " c ON c.id = i.category_id AND c.term_type = 'category' "
            . 'LEFT JOIN ' . $this->table('downloads_versions') . ' v ON v.download_id = i.id AND v.current_flag = 1';
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function buildFilter(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, DownloadSettings::STATUSES, true)) {
            $where[] = 'i.status = ?';
            $params[] = $status;
        } elseif ($status === 'public') {
            $where[] = "i.status = 'published'";
        } else {
            $where[] = "i.status <> 'trash'";
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $where[] = 'i.category_id = ?';
            $params[] = $categoryId;
        } elseif (($filters['category_id'] ?? null) === 'none') {
            $where[] = 'i.category_id IS NULL';
        }

        $access = (string) ($filters['access_level'] ?? '');
        if (in_array($access, DownloadSettings::ACCESS_LEVELS, true)) {
            $where[] = 'i.access_level = ?';
            $params[] = $access;
        }

        $fileType = strtolower((string) ($filters['file_type'] ?? ''));
        if ($fileType !== '' && preg_match('/^[a-z0-9]{1,20}$/', $fileType) === 1) {
            $where[] = 'i.file_type = ?';
            $params[] = $fileType;
        }

        $tagId = (int) ($filters['tag_id'] ?? 0);
        if ($tagId > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM ' . $this->table('downloads_item_terms') . ' it WHERE it.download_id = i.id AND it.term_id = ?)';
            $params[] = $tagId;
        }

        if (!empty($filters['featured'])) {
            $where[] = 'i.is_featured = 1';
        }

        if (isset($filters['ids']) && is_array($filters['ids'])) {
            $ids = self::intList($filters['ids']);
            if ($ids === []) {
                $where[] = '1=0';
            } else {
                $where[] = 'i.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...$ids);
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $search = function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100);
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(i.title LIKE ? OR i.slug LIKE ? OR i.summary LIKE ? OR v.file_name LIKE ? OR v.version LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $allowed
     * @return array<string,mixed>
     */
    private function filterColumns(array $data, array $allowed): array
    {
        return array_intersect_key($data, array_flip($allowed));
    }
}
