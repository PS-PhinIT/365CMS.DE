<?php
declare(strict_types=1);

/**
 * Downloads (Medien → Downloads) – Admin-Logik für Liste, Anlegen, Bearbeiten in Abschnitten
 * (Allgemein, Versionen, Eigenschaften, Zugriff, Statistik, Verwendung, SEO), Statistik, Kategorien
 * und Einstellungen.
 *
 * Alle Prüfungen und Normalisierungen liegen in den Services (`DownloadService`, `DownloadSettings`);
 * dieses Modul ordnet Formularfelder zu und prüft Aktionen und Rechte.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Services\Downloads\DownloadRenderer;
use CMS\Services\Downloads\DownloadSecurity;
use CMS\Services\Downloads\DownloadService;
use CMS\Services\Downloads\DownloadSettings;
use CMS\Services\Downloads\DownloadStatsService;
use CMS\Services\Downloads\DownloadUsageService;

class DownloadsModule
{
    public const TABS = ['list', 'stats', 'categories', 'settings'];
    public const SECTIONS = ['general', 'versions', 'properties', 'access', 'stats', 'usage', 'seo'];
    public const STATUS_FILTERS = ['all', 'published', 'draft', 'archived', 'trash'];
    public const BULK_ACTIONS = ['publish', 'draft', 'archive', 'category', 'access', 'trash', 'restore', 'delete'];
    public const PER_PAGE = 25;
    public const MAX_BULK = 200;

    private DownloadService $service;

    public function __construct(private readonly bool $canManage, private readonly bool $canViewStats)
    {
        $this->service = DownloadService::getInstance();

        // Nachlauf für Bestände: übernommene Dateien beschränkter oder unveröffentlichter Downloads liegen sonst
        // bis zum nächsten Speichern direkt abrufbar unter uploads/downloads. Nur bei Seitenaufrufen (GET) und
        // mit Zeitbudget; die Sitzung gilt erst als geprüft, wenn ein Lauf vollständig durchkam – sonst setzt
        // der nächste Aufruf fort.
        if ($canManage && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && session_status() === PHP_SESSION_ACTIVE
            && empty($_SESSION['downloads_legacy_files_checked'])) {
            if ($this->service->privatizeRestrictedLegacyFiles()['complete']) {
                $_SESSION['downloads_legacy_files_checked'] = 1;
            }
        }
    }

    public function isAvailable(): bool
    {
        return $this->service->isAvailable();
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function url(array $query = []): string
    {
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== 0 && $value !== 'all');

        return '/admin/downloads' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * @param array<string, mixed> $source
     * @return array{status: string, category: int, access: string, type: string, q: string, page: int}
     */
    public static function filters(array $source): array
    {
        $status = is_scalar($source['status'] ?? null) ? (string) $source['status'] : '';
        $access = is_scalar($source['access'] ?? null) ? (string) $source['access'] : '';
        $type = strtolower(is_scalar($source['type'] ?? null) ? (string) $source['type'] : '');

        return [
            'status' => in_array($status, self::STATUS_FILTERS, true) ? $status : 'all',
            'category' => max(0, (int) ($source['category'] ?? 0)),
            'access' => in_array($access, DownloadSettings::ACCESS_LEVELS, true) ? $access : '',
            'type' => preg_match('/^[a-z0-9]{1,20}$/', $type) === 1 ? $type : '',
            'q' => DownloadService::plainText(is_scalar($source['q'] ?? null) ? (string) $source['q'] : '', 100),
            'page' => max(1, (int) ($source['page'] ?? 1)),
        ];
    }

    // ── Daten für die Ansichten ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function getListData(array $query): array
    {
        $filters = self::filters($query);
        $base = ['tab' => 'list', 'view' => 'list', 'filters' => $filters] + $this->common();
        if (!$base['available']) {
            return $base;
        }

        $serviceFilters = ['search' => $filters['q'], 'access_level' => $filters['access'], 'file_type' => $filters['type']];
        if ($filters['status'] !== 'all') {
            $serviceFilters['status'] = $filters['status'];
        }
        if ($filters['category'] > 0) {
            $serviceFilters['category_id'] = $filters['category'];
        }
        $result = $this->service->list($serviceFilters, $filters['page'], self::PER_PAGE, 'updated');
        $ids = array_map(static fn (array $item): int => (int) $item['id'], $result['items']);
        $recent = $this->recentHits($ids, 30);
        $usage = DownloadUsageService::getInstance()->usageCounts($ids);

        $rows = [];
        foreach ($result['items'] as $item) {
            $id = (int) $item['id'];
            $rows[] = $item + [
                'hits_30' => (int) ($recent[$id] ?? 0),
                'usage_count' => (int) ($usage[$id] ?? 0),
                'public_url' => DownloadRenderer::singleUrl((string) $item['slug']),
            ];
        }

        $counts = $this->service->repository()->statusCounts();
        $counts['all'] = (int) ($counts['published'] ?? 0) + (int) ($counts['draft'] ?? 0) + (int) ($counts['archived'] ?? 0);

        return [
            'items' => $rows,
            'total' => $result['total'],
            'pages' => $result['pages'],
            'counts' => $counts,
            'file_types' => $this->service->repository()->usedFileTypes(),
        ] + $base;
    }

    /**
     * @return array<string, mixed>
     */
    public function getNewData(): array
    {
        $settings = $this->service->settings();

        return [
            'tab' => 'list',
            'view' => 'new',
            'max_size' => DownloadSecurity::formatBytes($settings->maxSizeBytes()),
            'allowed_types' => $settings->allowedExtensions(),
            'default_access' => $settings->string('default_access'),
        ] + $this->common();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEditData(int $id, string $section, int $period): ?array
    {
        $item = $this->isAvailable() ? $this->service->getFull($id) : null;
        if ($item === null) {
            return null;
        }
        $section = in_array($section, self::SECTIONS, true) ? $section : 'general';
        if (!$this->canManage && $section !== 'stats') {
            $section = 'stats';
        }

        $data = [
            'tab' => 'list',
            'view' => 'edit',
            'section' => $section,
            'item' => $item,
            'is_trash' => (string) $item['status'] === 'trash',
            'public_url' => DownloadRenderer::singleUrl((string) $item['slug']),
            'file_url' => DownloadRenderer::fileUrl((string) $item['slug']),
            'usage_count' => count(DownloadUsageService::getInstance()->usagesFor($id)),
        ] + $this->common();

        if ($section === 'versions') {
            $settings = $this->service->settings();
            $data['max_size'] = DownloadSecurity::formatBytes($settings->maxSizeBytes());
            $data['allowed_types'] = $settings->allowedExtensions();
        }
        if ($section === 'properties') {
            $data['property_fields'] = $this->service->settings()->propertyFields();
        }
        if ($section === 'stats') {
            $stats = DownloadStatsService::getInstance();
            $period = DownloadStatsService::normalizePeriod($period);
            $data['period'] = $period;
            $data['summary'] = $stats->summary($period, $id);
            $data['series'] = $stats->dailySeries($period, $id);
            $data['by_version'] = $stats->versionBreakdown($id, $period);
            $data['sources'] = $stats->topSources($period, $id, 10);
        }
        if ($section === 'usage') {
            $data['usages'] = DownloadUsageService::getInstance()->usagesFor($id);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatsData(int $period): array
    {
        $base = ['tab' => 'stats', 'view' => 'stats'] + $this->common();
        if (!$base['available']) {
            return $base;
        }
        $stats = DownloadStatsService::getInstance();
        $period = DownloadStatsService::normalizePeriod($period);

        return [
            'period' => $period,
            'summary' => $stats->summary($period),
            'series' => $stats->dailySeries($period),
            'top' => $stats->topDownloads($period, 10),
            'sources' => $stats->topSources($period, null, 10),
            'retention_days' => $stats->retentionDays(),
        ] + $base;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCategoriesData(int $editId): array
    {
        $base = ['tab' => 'categories', 'view' => 'categories'] + $this->common();
        $categories = $base['available'] ? $this->service->categories() : [];
        $edit = null;
        foreach ($categories as $category) {
            if ((int) $category['id'] === $editId) {
                $edit = $category;
            }
        }

        return ['list' => $categories, 'edit' => $edit] + $base;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettingsData(): array
    {
        $settings = $this->service->settings();

        return [
            'tab' => 'settings',
            'view' => 'settings',
            'settings' => $settings->all(),
            'file_type_groups' => DownloadSettings::FILE_TYPE_GROUPS,
            'server_limit' => DownloadSettings::serverUploadLimit() > 0 ? DownloadSecurity::formatBytes(DownloadSettings::serverUploadLimit()) : '',
            'property_lines' => self::propertyFieldsToText($settings->propertyFields()),
            'private_outside_webroot' => \CMS\Services\PrivateStorageService::getInstance()->isOutsideWebroot(),
        ] + $this->common();
    }

    /**
     * @return array<string, mixed>
     */
    private function common(): array
    {
        $available = $this->isAvailable();

        return [
            'available' => $available,
            'can_manage' => $this->canManage,
            'can_view_stats' => $this->canViewStats,
            'categories' => $available ? $this->service->categories() : [],
            'roles' => $available ? $this->service->availableRoles() : [],
            'plans' => $available ? $this->service->availablePlans() : [],
            'property_fields' => $this->service->settings()->propertyFields(),
        ];
    }

    /**
     * Abrufe der letzten Tage je Download (für die Liste).
     *
     * @param list<int> $ids
     * @return array<int, int>
     */
    private function recentHits(array $ids, int $days): array
    {
        if ($ids === []) {
            return [];
        }
        $repo = $this->service->repository();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $since = DownloadStatsService::dayBefore(time(), $days - 1);
        $hits = [];
        try {
            foreach ($repo->fetchAll(
                'SELECT download_id, SUM(hits) AS total FROM ' . $repo->table('downloads_stats_daily')
                . " WHERE stat_date >= ? AND download_id IN ({$placeholders}) GROUP BY download_id",
                array_merge([$since], $ids)
            ) as $row) {
                $hits[(int) $row['download_id']] = (int) $row['total'];
            }
            foreach ($repo->fetchAll(
                'SELECT download_id, COUNT(*) AS total FROM ' . $repo->table('downloads_events')
                . " WHERE aggregated = 0 AND created_at >= ? AND download_id IN ({$placeholders}) GROUP BY download_id",
                array_merge([$since . ' 00:00:00'], $ids)
            ) as $row) {
                $hits[(int) $row['download_id']] = ($hits[(int) $row['download_id']] ?? 0) + (int) $row['total'];
            }
        } catch (\Throwable) {
        }

        return $hits;
    }

    // ── Aktionen ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    public function handlePost(array $post, array $files, int $userId): array
    {
        $action = strtolower(trim(is_scalar($post['action'] ?? null) ? (string) $post['action'] : ''));
        if (!$this->canManage) {
            return ['success' => false, 'error' => __('downloads.admin.error_forbidden')];
        }
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => __('downloads.error.unavailable')];
        }

        return match ($action) {
            'create' => $this->create($post, $files, $userId),
            'save' => $this->save($post, $userId),
            'add_version' => $this->addVersion($post, $files, $userId),
            'update_version' => $this->updateVersion($post, $userId),
            'set_current' => $this->setCurrent($post, $userId),
            'delete_version' => $this->deleteVersion($post, $userId),
            'trash', 'restore', 'delete' => $this->single($action, $post, $userId),
            'bulk' => $this->bulk($post, $userId),
            'save_category' => $this->saveCategory($post),
            'delete_category' => $this->deleteCategory($post),
            'save_settings' => $this->saveSettings($post),
            'rebuild_usage' => $this->rebuildUsage(),
            default => ['success' => false, 'error' => __('downloads.admin.error_unknown_action')],
        };
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private function create(array $post, array $files, int $userId): array
    {
        $input = $this->itemInput($post, ['title', 'summary', 'category_id', 'status', 'access_level', 'access_roles', 'access_plans']);
        $file = is_array($files['file'] ?? null) ? $files['file'] : null;
        $hasFile = $file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $externalUrl = trim(is_scalar($post['external_url'] ?? null) ? (string) $post['external_url'] : '');
        $version = is_scalar($post['version'] ?? null) ? (string) $post['version'] : '';
        $back = self::url(['action' => 'new']);

        if ($hasFile) {
            $result = $this->service->createFromUpload($file, $input + ['version' => $version], $userId);
            if (!empty($result['success']) && isset($input['status']) && $input['status'] === 'archived') {
                $this->service->setStatus([(int) $result['id']], 'archived', $userId);
            }
        } else {
            if (trim((string) ($input['title'] ?? '')) === '') {
                return ['success' => false, 'error' => __('downloads.error.title_required'), '__redirect' => $back];
            }
            $result = $this->service->save($input, 0, $userId);
            if (!empty($result['success']) && $externalUrl !== '') {
                $versionResult = $this->service->addVersion((int) $result['id'], [
                    'version' => $version !== '' ? $version : '1.0',
                    'external_url' => $externalUrl,
                ], null, $userId);
                if (empty($versionResult['success'])) {
                    return [
                        'success' => true,
                        'type' => 'warning',
                        'message' => __('downloads.admin.created'),
                        'details' => [(string) ($versionResult['error'] ?? '')],
                        '__redirect' => self::url(['action' => 'edit', 'id' => (int) $result['id'], 'section' => 'versions']),
                    ];
                }
            }
        }

        if (empty($result['success'])) {
            return ['success' => false, 'error' => (string) ($result['error'] ?? __('downloads.error.save_failed')), '__redirect' => $back];
        }

        $id = (int) $result['id'];
        $hasVersion = $hasFile || $externalUrl !== '';

        return [
            'success' => true,
            'type' => $hasVersion ? 'success' : 'warning',
            'message' => __('downloads.admin.created'),
            'details' => $hasVersion ? [] : [__('downloads.admin.hint_no_version')],
            '__redirect' => self::url(['action' => 'edit', 'id' => $id, 'section' => $hasVersion ? 'general' : 'versions']),
        ];
    }

    /**
     * Speichert die Felder eines Abschnitts (nicht übermittelte Felder bleiben unverändert).
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function save(array $post, int $userId): array
    {
        $id = (int) ($post['id'] ?? 0);
        $section = in_array($post['section'] ?? '', self::SECTIONS, true) ? (string) $post['section'] : 'general';
        $back = self::url(['action' => 'edit', 'id' => $id, 'section' => $section]);
        $fields = match ($section) {
            'general' => ['title', 'slug', 'summary', 'description', 'category_id', 'tags', 'status', 'cover_media', 'author_name', 'license', 'requirements', 'external_links', 'is_signed', 'signature_info', 'is_featured', 'sort_order'],
            'properties' => ['properties'],
            'access' => ['access_level', 'access_roles', 'access_plans'],
            'seo' => ['seo_title', 'seo_description', 'seo_noindex'],
            default => [],
        };
        if ($fields === []) {
            return ['success' => false, 'error' => __('downloads.admin.error_unknown_action'), '__redirect' => $back];
        }

        $result = $this->service->save($this->itemInput($post, $fields), $id, $userId);
        if (empty($result['success'])) {
            return [
                'success' => false,
                'error' => (string) ($result['error'] ?? __('downloads.error.save_failed')),
                'details' => array_values(array_unique(array_map('strval', (array) ($result['errors'] ?? [])))),
                '__redirect' => $back,
            ];
        }

        return ['success' => true, 'message' => __('downloads.admin.saved'), '__redirect' => $back];
    }

    /**
     * Formularwerte → Service-Eingabe (nur die angegebenen Felder; Kontrollkästchen ohne Wert = 0).
     *
     * @param array<string, mixed> $post
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function itemInput(array $post, array $fields): array
    {
        $input = [];
        foreach ($fields as $field) {
            if (in_array($field, ['is_signed', 'is_featured', 'seo_noindex'], true)) {
                $input[$field] = !empty($post[$field]) ? 1 : 0;
                continue;
            }
            if (in_array($field, ['access_roles', 'access_plans'], true)) {
                $input[$field] = is_array($post[$field] ?? null) ? array_values(array_map('strval', $post[$field])) : [];
                continue;
            }
            if ($field === 'properties') {
                $input[$field] = is_array($post['properties'] ?? null) ? $post['properties'] : [];
                continue;
            }
            if (array_key_exists($field, $post) && is_scalar($post[$field])) {
                $input[$field] = (string) $post[$field];
            }
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private function addVersion(array $post, array $files, int $userId): array
    {
        $id = (int) ($post['id'] ?? 0);
        $back = self::url(['action' => 'edit', 'id' => $id, 'section' => 'versions']);
        $result = $this->service->addVersion($id, [
            'version' => is_scalar($post['version'] ?? null) ? (string) $post['version'] : '',
            'release_date' => is_scalar($post['release_date'] ?? null) ? (string) $post['release_date'] : '',
            'changelog' => is_scalar($post['changelog'] ?? null) ? (string) $post['changelog'] : '',
            'external_url' => is_scalar($post['external_url'] ?? null) ? (string) $post['external_url'] : '',
            'make_current' => !empty($post['make_current']),
        ], is_array($files['file'] ?? null) ? $files['file'] : null, $userId);

        return !empty($result['success'])
            ? ['success' => true, 'message' => __('downloads.admin.version_added'), '__redirect' => $back]
            : ['success' => false, 'error' => (string) ($result['error'] ?? __('downloads.error.save_failed')), '__redirect' => $back];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function updateVersion(array $post, int $userId): array
    {
        $id = (int) ($post['id'] ?? 0);
        $back = self::url(['action' => 'edit', 'id' => $id, 'section' => 'versions']);
        $version = $this->service->repository()->findVersion((int) ($post['version_id'] ?? 0));
        if ($version === null || (int) $version['download_id'] !== $id) {
            return ['success' => false, 'error' => __('downloads.error.version_not_found'), '__redirect' => $back];
        }
        $input = [];
        foreach (['version', 'release_date', 'changelog', 'external_url'] as $field) {
            if (array_key_exists($field, $post) && is_scalar($post[$field])) {
                $input[$field] = (string) $post[$field];
            }
        }
        $result = $this->service->updateVersion((int) $version['id'], $input, $userId);

        return !empty($result['success'])
            ? ['success' => true, 'message' => __('downloads.admin.version_saved'), '__redirect' => $back]
            : ['success' => false, 'error' => (string) ($result['error'] ?? __('downloads.error.save_failed')), '__redirect' => $back];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function setCurrent(array $post, int $userId): array
    {
        $id = (int) ($post['id'] ?? 0);
        $back = self::url(['action' => 'edit', 'id' => $id, 'section' => 'versions']);
        $result = $this->service->setCurrentVersion($id, (int) ($post['version_id'] ?? 0), $userId);

        return !empty($result['success'])
            ? ['success' => true, 'message' => __('downloads.admin.version_current'), '__redirect' => $back]
            : ['success' => false, 'error' => (string) ($result['error'] ?? __('downloads.error.save_failed')), '__redirect' => $back];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function deleteVersion(array $post, int $userId): array
    {
        $id = (int) ($post['id'] ?? 0);
        $back = self::url(['action' => 'edit', 'id' => $id, 'section' => 'versions']);
        $version = $this->service->repository()->findVersion((int) ($post['version_id'] ?? 0));
        if ($version === null || (int) $version['download_id'] !== $id) {
            return ['success' => false, 'error' => __('downloads.error.version_not_found'), '__redirect' => $back];
        }
        $result = $this->service->deleteVersion((int) $version['id'], $userId);

        return !empty($result['success'])
            ? ['success' => true, 'message' => __('downloads.admin.version_deleted'), '__redirect' => $back]
            : ['success' => false, 'error' => (string) ($result['error'] ?? __('downloads.error.save_failed')), '__redirect' => $back];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function single(string $action, array $post, int $userId): array
    {
        $id = (int) ($post['id'] ?? 0);
        $item = $this->service->get($id);
        $listUrl = self::url(['status' => $action === 'trash' ? null : 'trash']);
        if ($item === null) {
            return ['success' => false, 'error' => __('downloads.error.not_found'), '__redirect' => self::url()];
        }

        if ($action === 'trash') {
            $usages = count(DownloadUsageService::getInstance()->usagesFor($id));
            if (!$this->service->trash($id, $userId)) {
                return ['success' => false, 'error' => __('downloads.error.save_failed'), '__redirect' => self::url(['action' => 'edit', 'id' => $id])];
            }

            return [
                'success' => true,
                'type' => $usages > 0 ? 'warning' : 'success',
                'message' => sprintf(__('downloads.admin.trashed'), (string) $item['title']),
                'details' => $usages > 0 ? [sprintf(__('downloads.admin.trashed_in_use'), $usages)] : [],
                '__redirect' => self::url(),
            ];
        }
        if ($action === 'restore') {
            return $this->service->restore($id, $userId)
                ? ['success' => true, 'message' => __('downloads.admin.restored'), '__redirect' => self::url(['action' => 'edit', 'id' => $id])]
                : ['success' => false, 'error' => __('downloads.error.save_failed'), '__redirect' => $listUrl];
        }

        if ((string) $item['status'] !== 'trash') {
            return ['success' => false, 'error' => __('downloads.admin.error_delete_requires_trash'), '__redirect' => $listUrl];
        }

        return $this->service->deletePermanently($id, $userId)
            ? ['success' => true, 'message' => __('downloads.admin.deleted'), '__redirect' => $listUrl]
            : ['success' => false, 'error' => __('downloads.error.save_failed'), '__redirect' => $listUrl];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function bulk(array $post, int $userId): array
    {
        $filters = self::filters(is_array($post['filter'] ?? null) ? $post['filter'] : []);
        $listUrl = self::url(['status' => $filters['status'], 'category' => $filters['category'], 'access' => $filters['access'], 'type' => $filters['type'], 'q' => $filters['q'], 'page' => $filters['page'] > 1 ? $filters['page'] : null]);
        $bulk = is_scalar($post['bulk_action'] ?? null) ? (string) $post['bulk_action'] : '';
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($post['ids'] ?? null) ? $post['ids'] : [])))), 0, self::MAX_BULK);
        if (!in_array($bulk, self::BULK_ACTIONS, true)) {
            return ['success' => false, 'error' => __('downloads.admin.error_bulk_action'), '__redirect' => $listUrl];
        }
        if ($ids === []) {
            return ['success' => false, 'error' => __('downloads.admin.error_bulk_empty'), '__redirect' => $listUrl];
        }

        $count = 0;
        switch ($bulk) {
            case 'publish':
            case 'draft':
            case 'archive':
                $count = $this->service->setStatus($ids, ['publish' => 'published', 'draft' => 'draft', 'archive' => 'archived'][$bulk], $userId);
                break;
            case 'category':
                $count = $this->service->setCategory($ids, (int) ($post['bulk_category'] ?? 0), $userId);
                break;
            case 'access':
                $access = is_scalar($post['bulk_access'] ?? null) ? (string) $post['bulk_access'] : '';
                if ($access === 'roles') {
                    return ['success' => false, 'error' => __('downloads.admin.error_bulk_roles'), '__redirect' => $listUrl];
                }
                $count = $this->service->setAccess($ids, $access, $userId);
                break;
            default:
                foreach ($ids as $id) {
                    $ok = match ($bulk) {
                        'trash' => $this->service->trash($id, $userId),
                        'restore' => $this->service->restore($id, $userId),
                        'delete' => ($this->service->get($id)['status'] ?? '') === 'trash' && $this->service->deletePermanently($id, $userId),
                        default => false,
                    };
                    $count += $ok ? 1 : 0;
                }
        }

        return ['success' => true, 'message' => sprintf(__('downloads.admin.bulk_done'), $count), '__redirect' => $listUrl];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function saveCategory(array $post): array
    {
        $id = (int) ($post['category_id'] ?? 0);
        $result = $this->service->saveCategory([
            'name' => is_scalar($post['name'] ?? null) ? (string) $post['name'] : '',
            'slug' => is_scalar($post['slug'] ?? null) ? (string) $post['slug'] : '',
            'description' => is_scalar($post['description'] ?? null) ? (string) $post['description'] : '',
            'icon' => is_scalar($post['icon'] ?? null) ? (string) $post['icon'] : '',
            'status' => !empty($post['inactive']) ? 'inactive' : 'active',
            'sort_order' => (int) ($post['sort_order'] ?? 0),
        ], $id);
        $back = self::url(['tab' => 'categories', 'edit' => !empty($result['success']) ? null : ($id > 0 ? $id : null)]);
        if (!empty($result['success'])) {
            AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.category.save', 'Download-Kategorie gespeichert', 'download_category', (int) $result['id']);
        }

        return !empty($result['success'])
            ? ['success' => true, 'message' => __('downloads.admin.category_saved'), '__redirect' => $back]
            : ['success' => false, 'error' => (string) ($result['error'] ?? __('downloads.error.save_failed')), '__redirect' => $back];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function deleteCategory(array $post): array
    {
        $back = self::url(['tab' => 'categories']);

        return $this->service->deleteCategory((int) ($post['category_id'] ?? 0))
            ? ['success' => true, 'message' => __('downloads.admin.category_deleted'), '__redirect' => $back]
            : ['success' => false, 'error' => __('downloads.error.category_not_found'), '__redirect' => $back];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function saveSettings(array $post): array
    {
        $raw = is_array($post['settings'] ?? null) ? $post['settings'] : [];
        $values = [];
        foreach (DownloadSettings::DEFAULTS as $key => $default) {
            if ($key === 'property_fields' || $key === 'allowed_types') {
                continue;
            }
            if (is_bool($default)) {
                $values[$key] = !empty($raw[$key]);
            } elseif (array_key_exists($key, $raw) && is_scalar($raw[$key])) {
                $values[$key] = (string) $raw[$key];
            }
        }
        $values['allowed_types'] = is_array($raw['allowed_types'] ?? null) ? array_values(array_map('strval', $raw['allowed_types'])) : [];
        $values['property_fields'] = self::textToPropertyFields(is_scalar($post['property_lines'] ?? null) ? (string) $post['property_lines'] : '');

        $back = self::url(['tab' => 'settings']);
        if (!$this->service->settings()->save($values)) {
            return ['success' => false, 'error' => __('downloads.admin.settings_failed'), '__redirect' => $back];
        }
        AuditLogger::instance()->log(AuditLogger::CAT_SETTING, 'downloads.settings.save', 'Download-Einstellungen gespeichert', 'downloads', null, [
            'default_access' => $this->service->settings()->string('default_access'),
            'allowed_types' => $this->service->settings()->allowedExtensions(),
        ]);

        return ['success' => true, 'message' => __('downloads.admin.settings_saved'), '__redirect' => $back];
    }

    /**
     * @return array<string, mixed>
     */
    private function rebuildUsage(): array
    {
        $count = DownloadUsageService::getInstance()->rebuild();

        return ['success' => true, 'message' => sprintf(__('downloads.admin.usage_rebuilt'), $count), '__redirect' => self::url(['tab' => 'settings'])];
    }

    // ── Eigenschaftsfelder als Text („Schlüssel | Bezeichnung | Typ | Optionen“) ──

    /**
     * @param list<array{key:string,label:string,type:string,options:list<string>}> $fields
     */
    public static function propertyFieldsToText(array $fields): string
    {
        $lines = [];
        foreach ($fields as $field) {
            $line = $field['key'] . ' | ' . $field['label'] . ' | ' . $field['type'];
            if ($field['options'] !== []) {
                $line .= ' | ' . implode(', ', $field['options']);
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function textToPropertyFields(string $text): array
    {
        $fields = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 4));
            if (($parts[0] ?? '') === '') {
                continue;
            }
            $fields[] = [
                'key' => $parts[0],
                'label' => $parts[1] ?? '',
                'type' => strtolower($parts[2] ?? 'text'),
                'options' => isset($parts[3]) ? array_map('trim', explode(',', $parts[3])) : [],
            ];
        }

        return DownloadSettings::normalizePropertyFields($fields);
    }

    // ── Exporte ──────────────────────────────────────────────────────────────

    public function streamStatsCsv(int $period, ?int $downloadId): never
    {
        $rows = DownloadStatsService::getInstance()->csvRows(DownloadStatsService::normalizePeriod($period), $downloadId);
        AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.stats.export', 'Download-Statistik als CSV exportiert', 'download', $downloadId, ['period' => $period]);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="download-statistik-' . date('Ymd') . '.csv"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'wb');
        if ($out !== false) {
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, array_map('strval', $row), ';', '"', '');
            }
            fclose($out);
        }
        exit;
    }
}
