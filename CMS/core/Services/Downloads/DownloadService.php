<?php
/**
 * Fachlogik der Downloads: Validierung, Speichern, Versionen, Dateien im privaten Speicher,
 * Zugriffsprüfung, Papierkorb und endgültiges Löschen.
 *
 * Dateien liegen ausschließlich im privaten Speicher ({@see PrivateStorageService}) und werden
 * nur über die Download-Routen ausgeliefert; SHA-256 und Größe werden serverseitig berechnet.
 *
 * Hooks: `cms_after_download_save` ($id, $item), `download_deleted` ($id),
 *        Filter `cms_download_access` ($state, $item, $user).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Downloads;

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Hooks;
use CMS\Logger;
use CMS\Services\MediaService;
use CMS\Services\PrivateStorageService;
use CMS\Services\PurifierService;
use CMS\WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class DownloadService
{
    /** Bereich für übernommene Plugin-Dateien, die (noch) im Upload-Verzeichnis liegen. */
    public const LEGACY_UPLOADS_AREA = '@uploads';

    /** Reservierte Slugs (kollidieren mit Archiv-, Alt- und Hilfsrouten). */
    public const RESERVED_SLUGS = ['category', 'file', 'legacy-file', 'feed', 'page', 'search', 'tag', 'kategorie', 'en'];

    public const ACCESS_GRANTED = 'granted';
    public const ACCESS_LOGIN = 'login';
    public const ACCESS_ROLE = 'role';
    public const ACCESS_MEMBERSHIP = 'membership';

    private static ?self $instance = null;

    private DownloadRepository $repo;

    /** @var array<int,list<int>> */
    private array $planCache = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->repo = DownloadRepository::getInstance();
    }

    public function repository(): DownloadRepository
    {
        return $this->repo;
    }

    public function settings(): DownloadSettings
    {
        return DownloadSettings::getInstance();
    }

    public function isAvailable(): bool
    {
        return $this->repo->isAvailable();
    }

    // ── Lesen ────────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $row = $this->repo->findItem($id);

        return $row !== null ? self::hydrate($row) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getBySlug(string $slug): ?array
    {
        if (!$this->isAvailable() || !DownloadSecurity::isValidSlug($slug)) {
            return null;
        }
        $row = $this->repo->findItemBySlug($slug);

        return $row !== null ? self::hydrate($row) : null;
    }

    /**
     * @param list<int> $ids
     * @return array<int,array<string,mixed>>
     */
    public function getMany(array $ids): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        return array_map([self::class, 'hydrate'], $this->repo->findItemsByIds($ids));
    }

    /**
     * Download inkl. Versionen, Schlagwörtern und Eigenschaftswerten.
     *
     * @return array<string,mixed>|null
     */
    public function getFull(int $id): ?array
    {
        $item = $this->get($id);
        if ($item === null) {
            return null;
        }

        return $this->withDetails([$id => $item])[$id];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public function withDetails(array $items): array
    {
        $ids = array_keys($items);
        $versions = $this->repo->versionsForItems($ids);
        $tags = $this->repo->tagsForItems($ids);
        $properties = $this->repo->propertyValuesForItems($ids);
        foreach ($items as $id => $item) {
            $items[$id]['versions'] = array_map([self::class, 'hydrateVersion'], $versions[$id] ?? []);
            $items[$id]['tags'] = $tags[$id] ?? [];
            $items[$id]['properties'] = $properties[$id] ?? [];
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function list(array $filters, int $page = 1, int $perPage = 25, string $sort = 'updated'): array
    {
        $empty = ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $perPage];
        if (!$this->isAvailable()) {
            return $empty;
        }
        $perPage = max(1, min(200, $perPage));
        $total = $this->repo->countItems($filters);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $rows = $this->repo->searchItems($filters, $perPage, ($page - 1) * $perPage, $sort);

        return [
            'items' => array_map([self::class, 'hydrate'], $rows),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function versions(int $downloadId): array
    {
        return $this->isAvailable() ? array_map([self::class, 'hydrateVersion'], $this->repo->versionsFor($downloadId)) : [];
    }

    /**
     * Version eines Downloads: „latest“ (oder leer) = aktuelle Version, sonst die gepinnte Versionsnummer.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    public function resolveVersion(array $item, string $version = 'latest'): ?array
    {
        $downloadId = (int) ($item['id'] ?? 0);
        if ($downloadId <= 0) {
            return null;
        }
        $version = trim($version);
        if ($version === '' || $version === 'latest') {
            $row = $this->repo->currentVersion($downloadId);
        } elseif (DownloadSecurity::isValidVersion($version)) {
            $row = $this->repo->findVersionByNumber($downloadId, $version);
        } else {
            $row = null;
        }

        return $row !== null ? self::hydrateVersion($row) : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function categories(bool $onlyActive = false): array
    {
        return $this->isAvailable() ? $this->repo->terms('category', $onlyActive) : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function tags(): array
    {
        return $this->isAvailable() ? $this->repo->terms('tag') : [];
    }

    // ── Speichern ────────────────────────────────────────────────────────────

    /**
     * Legt einen Download an oder aktualisiert ihn (gleiche Validierung für Admin, Block-Upload und Import).
     *
     * @param array<string,mixed> $input
     * @return array{success:bool,id?:int,error?:string,errors?:array<string,string>}
     */
    public function save(array $input, int $id = 0, int $userId = 0): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => __('downloads.error.unavailable')];
        }

        $existing = null;
        if ($id > 0) {
            $existing = $this->repo->findItem($id);
            if ($existing === null) {
                return ['success' => false, 'error' => __('downloads.error.not_found')];
            }
            if ((string) $existing['status'] === 'trash') {
                return ['success' => false, 'error' => __('downloads.error.in_trash')];
            }
        }

        [$data, $errors] = $this->validateItem($input, $existing);
        if ($errors !== []) {
            return ['success' => false, 'error' => (string) reset($errors), 'errors' => $errors];
        }

        $tagIds = array_key_exists('tags', $input) ? $this->resolveTagIds($input['tags']) : null;
        $properties = array_key_exists('properties', $input) ? $this->validateProperties(is_array($input['properties']) ? $input['properties'] : []) : null;
        if (is_array($properties) && isset($properties['__error'])) {
            return ['success' => false, 'error' => $properties['__error'], 'errors' => ['properties' => $properties['__error']]];
        }

        $now = date('Y-m-d H:i:s');
        if ($userId > 0) {
            $data['updated_by'] = $userId;
        }
        if ($data['status'] === 'published' && ($existing === null || empty($existing['published_at']))) {
            $data['published_at'] = $now;
        }

        try {
            if ($existing === null) {
                $data['created_by'] = $userId > 0 ? $userId : null;
                $data['file_type'] = '';
                $id = $this->repo->insertItem($data);
                if ($id <= 0) {
                    return ['success' => false, 'error' => __('downloads.error.save_failed')];
                }
            } elseif (!$this->repo->updateItem($id, $data)) {
                return ['success' => false, 'error' => __('downloads.error.save_failed')];
            }
            if ($tagIds !== null) {
                $this->repo->setItemTags($id, $tagIds);
            }
            if ($properties !== null) {
                $this->repo->setPropertyValues($id, $properties);
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('downloads')->error('Download konnte nicht gespeichert werden.', ['id' => $id, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return ['success' => false, 'error' => __('downloads.error.save_failed')];
        }

        $this->afterSave($id, $existing === null ? 'create' : 'update', $userId);

        return ['success' => true, 'id' => $id];
    }

    /**
     * Legt aus einer hochgeladenen Datei einen vollständigen Download (inkl. Version 1) an.
     *
     * @param array<string,mixed> $file   Eintrag aus $_FILES
     * @param array<string,mixed> $input  optionale Felder (title, summary, category_id, access_level, version, status)
     * @return array{success:bool,id?:int,error?:string}
     */
    public function createFromUpload(array $file, array $input, int $userId): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => __('downloads.error.unavailable')];
        }

        $stored = $this->storeUpload($file);
        if (!$stored['success']) {
            return ['success' => false, 'error' => (string) $stored['error']];
        }
        $fileInfo = $stored['file'];

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = self::titleFromFilename((string) $fileInfo['name']);
        }
        $status = (string) ($input['status'] ?? $this->settings()->string('upload_status'));
        $itemInput = [
            'title' => $title,
            'summary' => (string) ($input['summary'] ?? ''),
            'category_id' => (int) ($input['category_id'] ?? 0),
            'access_level' => (string) ($input['access_level'] ?? $this->settings()->string('default_access')),
            'access_roles' => $input['access_roles'] ?? [],
            'access_plans' => $input['access_plans'] ?? [],
            'status' => in_array($status, DownloadSettings::UPLOAD_STATUSES, true) ? $status : $this->settings()->string('upload_status'),
        ];

        $result = $this->save($itemInput, 0, $userId);
        if (!$result['success']) {
            $this->deleteFile(['file_area' => $fileInfo['area'], 'file_path' => $fileInfo['path']]);

            return $result;
        }
        $id = (int) $result['id'];

        $version = DownloadSecurity::normalizeVersion((string) ($input['version'] ?? ''));
        $versionResult = $this->insertVersionRecord($id, [
            'version' => $version !== '' ? $version : '1.0',
            'release_date' => date('Y-m-d'),
            'changelog' => '',
        ], $fileInfo, '', true, $userId);
        if (!$versionResult['success']) {
            $this->deleteFile(['file_area' => $fileInfo['area'], 'file_path' => $fileInfo['path']]);
            $this->repo->deleteItem($id);

            return ['success' => false, 'error' => (string) $versionResult['error']];
        }

        AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.upload', 'Download per Upload angelegt', 'download', $id, [
            'file_ext' => $fileInfo['ext'],
            'size' => $fileInfo['size'],
        ]);

        return ['success' => true, 'id' => $id];
    }

    /**
     * Neue Version mit Datei-Upload oder externem Link anlegen.
     *
     * @param array<string,mixed> $input  version, release_date, changelog, external_url, make_current
     * @param array<string,mixed>|null $file
     * @return array{success:bool,id?:int,error?:string}
     */
    public function addVersion(int $downloadId, array $input, ?array $file, int $userId): array
    {
        $item = $this->isAvailable() ? $this->repo->findItem($downloadId) : null;
        if ($item === null) {
            return ['success' => false, 'error' => __('downloads.error.not_found')];
        }
        if ((string) $item['status'] === 'trash') {
            return ['success' => false, 'error' => __('downloads.error.in_trash')];
        }

        $version = DownloadSecurity::normalizeVersion((string) ($input['version'] ?? ''));
        if (!DownloadSecurity::isValidVersion($version)) {
            return ['success' => false, 'error' => __('downloads.error.version_invalid')];
        }
        if ($this->repo->findVersionByNumber($downloadId, $version) !== null) {
            return ['success' => false, 'error' => __('downloads.error.version_exists')];
        }

        $externalUrl = trim((string) ($input['external_url'] ?? ''));
        $hasFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $fileInfo = null;
        if ($hasFile) {
            $stored = $this->storeUpload($file);
            if (!$stored['success']) {
                return ['success' => false, 'error' => (string) $stored['error']];
            }
            $fileInfo = $stored['file'];
            $externalUrl = '';
        } elseif ($externalUrl !== '') {
            if (!DownloadSecurity::isAllowedExternalUrl($externalUrl, $this->settings()->string('external_allowed_domains'))) {
                return ['success' => false, 'error' => __('downloads.error.external_invalid')];
            }
        } else {
            return ['success' => false, 'error' => __('downloads.error.version_source_missing')];
        }

        $makeCurrent = !array_key_exists('make_current', $input) || DownloadSettings::toBool($input['make_current'])
            || $this->repo->currentVersion($downloadId) === null;

        $result = $this->insertVersionRecord($downloadId, [
            'version' => $version,
            'release_date' => (string) ($input['release_date'] ?? ''),
            'changelog' => (string) ($input['changelog'] ?? ''),
        ], $fileInfo, $externalUrl, $makeCurrent, $userId);
        if (!$result['success'] && $fileInfo !== null) {
            $this->deleteFile(['file_area' => $fileInfo['area'], 'file_path' => $fileInfo['path']]);
        }
        if ($result['success']) {
            AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.version.create', 'Download-Version angelegt', 'download', $downloadId, [
                'version' => $version,
                'external' => $externalUrl !== '',
            ]);
            $this->afterSave($downloadId, 'version', $userId, false);
        }

        return $result;
    }

    /**
     * Metadaten einer Version ändern (Datei bleibt; für neue Dateien eine neue Version anlegen).
     *
     * @param array<string,mixed> $input
     * @return array{success:bool,error?:string}
     */
    public function updateVersion(int $versionId, array $input, int $userId): array
    {
        $version = $this->isAvailable() ? $this->repo->findVersion($versionId) : null;
        if ($version === null) {
            return ['success' => false, 'error' => __('downloads.error.version_not_found')];
        }
        $downloadId = (int) $version['download_id'];

        $number = DownloadSecurity::normalizeVersion((string) ($input['version'] ?? $version['version']));
        if (!DownloadSecurity::isValidVersion($number)) {
            return ['success' => false, 'error' => __('downloads.error.version_invalid')];
        }
        $other = $this->repo->findVersionByNumber($downloadId, $number);
        if ($other !== null && (int) $other['id'] !== $versionId) {
            return ['success' => false, 'error' => __('downloads.error.version_exists')];
        }

        $data = [
            'version' => $number,
            'release_date' => self::normalizeDate((string) ($input['release_date'] ?? (string) $version['release_date'])),
            'changelog' => self::plainText((string) ($input['changelog'] ?? (string) ($version['changelog'] ?? '')), 20000, true),
        ];
        if ((string) $version['external_url'] !== '' && array_key_exists('external_url', $input)) {
            $url = trim((string) $input['external_url']);
            if (!DownloadSecurity::isAllowedExternalUrl($url, $this->settings()->string('external_allowed_domains'))) {
                return ['success' => false, 'error' => __('downloads.error.external_invalid')];
            }
            $data['external_url'] = $url;
        }

        if (!$this->repo->updateVersion($versionId, $data)) {
            return ['success' => false, 'error' => __('downloads.error.save_failed')];
        }
        $this->afterSave($downloadId, 'version', $userId, false);

        return ['success' => true];
    }

    /**
     * @return array{success:bool,error?:string}
     */
    public function setCurrentVersion(int $downloadId, int $versionId, int $userId): array
    {
        $version = $this->isAvailable() ? $this->repo->findVersion($versionId) : null;
        if ($version === null || (int) $version['download_id'] !== $downloadId) {
            return ['success' => false, 'error' => __('downloads.error.version_not_found')];
        }
        if (!$this->repo->makeCurrent($downloadId, $versionId)) {
            return ['success' => false, 'error' => __('downloads.error.save_failed')];
        }
        $this->syncFileType($downloadId);
        AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.version.current', 'Aktuelle Download-Version gesetzt', 'download', $downloadId, [
            'version' => (string) $version['version'],
        ]);
        $this->afterSave($downloadId, 'version', $userId, false);

        return ['success' => true];
    }

    /**
     * Archivierte Version samt Datei löschen (die aktuelle Version ist geschützt).
     *
     * @return array{success:bool,error?:string}
     */
    public function deleteVersion(int $versionId, int $userId): array
    {
        $version = $this->isAvailable() ? $this->repo->findVersion($versionId) : null;
        if ($version === null) {
            return ['success' => false, 'error' => __('downloads.error.version_not_found')];
        }
        if ((int) ($version['current_flag'] ?? 0) === 1) {
            return ['success' => false, 'error' => __('downloads.error.version_current')];
        }

        $this->deleteFile($version);
        if (!$this->repo->deleteVersion($versionId)) {
            return ['success' => false, 'error' => __('downloads.error.save_failed')];
        }
        AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.version.delete', 'Download-Version gelöscht', 'download', (int) $version['download_id'], [
            'version' => (string) $version['version'],
            'sha256' => (string) $version['sha256'],
        ], 'warning');
        $this->afterSave((int) $version['download_id'], 'version', $userId, false);

        return ['success' => true];
    }

    // ── Status, Papierkorb, Löschen ──────────────────────────────────────────

    /**
     * @param list<int> $ids
     */
    public function setStatus(array $ids, string $status, int $userId): int
    {
        if (!$this->isAvailable() || !in_array($status, ['draft', 'published', 'archived'], true)) {
            return 0;
        }
        $changed = 0;
        foreach ($this->repo->findItemsByIds($ids) as $id => $row) {
            if ((string) $row['status'] === 'trash' || (string) $row['status'] === $status) {
                continue;
            }
            $data = ['status' => $status, 'updated_by' => $userId > 0 ? $userId : null];
            if ($status === 'published' && empty($row['published_at'])) {
                $data['published_at'] = date('Y-m-d H:i:s');
            }
            if ($this->repo->updateItem($id, $data)) {
                $changed++;
                $this->afterSave($id, 'status', $userId, false);
            }
        }
        if ($changed > 0) {
            AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.status', 'Status von Downloads geändert', 'download', null, [
                'status' => $status,
                'count' => $changed,
            ]);
        }

        return $changed;
    }

    /**
     * @param list<int> $ids
     */
    public function setCategory(array $ids, int $categoryId, int $userId): int
    {
        if (!$this->isAvailable() || ($categoryId > 0 && $this->repo->findTerm($categoryId) === null)) {
            return 0;
        }
        $ids = array_keys(array_filter($this->repo->findItemsByIds($ids), static fn (array $row): bool => (string) $row['status'] !== 'trash'));
        $count = $this->repo->updateItems($ids, ['category_id' => $categoryId > 0 ? $categoryId : null, 'updated_by' => $userId > 0 ? $userId : null]);
        foreach ($ids as $id) {
            $this->afterSave($id, 'category', $userId, false);
        }

        return $count;
    }

    /**
     * @param list<int> $ids
     * @param list<string> $roles
     * @param list<int> $plans
     */
    public function setAccess(array $ids, string $access, int $userId, array $roles = [], array $plans = []): int
    {
        if (!$this->isAvailable() || !in_array($access, DownloadSettings::ACCESS_LEVELS, true)) {
            return 0;
        }
        $roles = $access === 'roles' ? $this->validRoles($roles) : [];
        if ($access === 'roles' && $roles === []) {
            return 0;
        }
        $plans = $access === 'member' ? $this->validPlans($plans) : [];
        $ids = array_keys(array_filter($this->repo->findItemsByIds($ids), static fn (array $row): bool => (string) $row['status'] !== 'trash'));
        $count = $this->repo->updateItems($ids, [
            'access_level' => $access,
            'access_roles' => self::encodeList($roles),
            'access_plans' => self::encodeList($plans),
            'updated_by' => $userId > 0 ? $userId : null,
        ]);
        foreach ($ids as $id) {
            $this->afterSave($id, 'access', $userId, false);
        }
        if ($count > 0) {
            AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.access', 'Zugriff von Downloads geändert', 'download', null, [
                'access' => $access,
                'count' => $count,
            ]);
        }

        return $count;
    }

    public function trash(int $id, int $userId): bool
    {
        $row = $this->isAvailable() ? $this->repo->findItem($id) : null;
        if ($row === null || (string) $row['status'] === 'trash') {
            return false;
        }
        $ok = $this->repo->updateItem($id, [
            'previous_status' => (string) $row['status'],
            'status' => 'trash',
            'trashed_at' => date('Y-m-d H:i:s'),
            'trashed_by' => $userId > 0 ? $userId : null,
        ]);
        if ($ok) {
            AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.trash', 'Download in den Papierkorb verschoben', 'download', $id, [
                'title' => (string) $row['title'],
            ], 'warning');
            Hooks::doAction('cms_download_trashed', $id);
        }

        return $ok;
    }

    public function restore(int $id, int $userId): bool
    {
        $row = $this->isAvailable() ? $this->repo->findItem($id) : null;
        if ($row === null || (string) $row['status'] !== 'trash') {
            return false;
        }
        $previous = (string) ($row['previous_status'] ?? '');
        $ok = $this->repo->updateItem($id, [
            'status' => in_array($previous, ['draft', 'published', 'archived'], true) ? $previous : 'draft',
            'previous_status' => null,
            'trashed_at' => null,
            'trashed_by' => null,
            'updated_by' => $userId > 0 ? $userId : null,
        ]);
        if ($ok) {
            AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.restore', 'Download aus dem Papierkorb wiederhergestellt', 'download', $id, [
                'title' => (string) $row['title'],
            ]);
            $this->afterSave($id, 'restore', $userId, false);
        }

        return $ok;
    }

    /**
     * Endgültig löschen: Dateien aller Versionen, Eigenschaften, Index, Statistik.
     */
    public function deletePermanently(int $id, int $userId): bool
    {
        $row = $this->isAvailable() ? $this->repo->findItem($id) : null;
        if ($row === null) {
            return false;
        }
        $versions = $this->repo->versionsFor($id);
        try {
            $db = $this->repo->db();
            $db->execute('DELETE FROM ' . $this->repo->table('downloads_events') . ' WHERE download_id = ?', [$id]);
            $db->execute('DELETE FROM ' . $this->repo->table('downloads_stats_daily') . ' WHERE download_id = ?', [$id]);
            if (!$this->repo->deleteItem($id)) {
                return false;
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('downloads')->error('Download konnte nicht gelöscht werden.', ['id' => $id, 'exception' => $e::class]);

            return false;
        }
        foreach ($versions as $version) {
            $this->deleteFile($version);
        }

        AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.delete', 'Download endgültig gelöscht', 'download', $id, [
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
            'versions' => count($versions),
            'user_id' => $userId,
        ], 'warning');
        Hooks::doAction('download_deleted', $id);

        return true;
    }

    // ── Kategorien ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $input name, slug, description, icon, status, sort_order
     * @return array{success:bool,id?:int,error?:string}
     */
    public function saveCategory(array $input, int $id = 0): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => __('downloads.error.unavailable')];
        }
        if ($id > 0 && $this->repo->findTerm($id) === null) {
            return ['success' => false, 'error' => __('downloads.error.category_not_found')];
        }
        $name = self::plainText((string) ($input['name'] ?? ''), 190);
        if ($name === '') {
            return ['success' => false, 'error' => __('downloads.error.category_name_required')];
        }
        $slug = DownloadSecurity::slugify((string) ($input['slug'] ?? '') !== '' ? (string) $input['slug'] : $name);
        if ($slug === '' || !DownloadSecurity::isValidSlug($slug)) {
            return ['success' => false, 'error' => __('downloads.error.slug_invalid')];
        }
        if ($this->repo->termSlugExists('category', $slug, $id)) {
            return ['success' => false, 'error' => __('downloads.error.slug_taken')];
        }
        $icon = preg_replace('/[^a-z0-9 _-]/i', '', (string) ($input['icon'] ?? '')) ?? '';
        $data = [
            'term_type' => 'category',
            'name' => $name,
            'slug' => $slug,
            'description' => self::plainText((string) ($input['description'] ?? ''), 2000, true),
            'icon' => substr(trim($icon), 0, 64),
            'status' => (string) ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'sort_order' => max(-100000, min(100000, (int) ($input['sort_order'] ?? 0))),
        ];
        if ($id > 0) {
            if (!$this->repo->updateTerm($id, $data)) {
                return ['success' => false, 'error' => __('downloads.error.save_failed')];
            }
        } else {
            $id = $this->repo->insertTerm($data);
            if ($id <= 0) {
                return ['success' => false, 'error' => __('downloads.error.save_failed')];
            }
        }

        return ['success' => true, 'id' => $id];
    }

    public function deleteCategory(int $id): bool
    {
        if (!$this->isAvailable() || $this->repo->findTerm($id) === null) {
            return false;
        }
        $ok = $this->repo->deleteTerm($id);
        if ($ok) {
            AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.category.delete', 'Download-Kategorie gelöscht', 'download_category', $id, [], 'warning');
        }

        return $ok;
    }

    // ── Zugriff ──────────────────────────────────────────────────────────────

    public function isStaff(): bool
    {
        try {
            return Auth::instance()->hasCapability('manage_downloads');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Zugriffsstatus für den aktuellen (oder übergebenen) Benutzer.
     *
     * @param array<string,mixed> $item
     */
    public function accessState(array $item, ?object $user = null): string
    {
        if ($user === null) {
            try {
                $user = Auth::instance()->currentUser();
            } catch (\Throwable) {
                $user = null;
            }
        }

        $state = $this->computeAccessState($item, $user);
        $filtered = Hooks::applyFilters('cms_download_access', $state, $item, $user);

        return in_array($filtered, [self::ACCESS_GRANTED, self::ACCESS_LOGIN, self::ACCESS_ROLE, self::ACCESS_MEMBERSHIP], true) ? $filtered : $state;
    }

    /**
     * @param array<string,mixed> $item
     */
    public function canAccess(array $item, ?object $user = null): bool
    {
        return $this->accessState($item, $user) === self::ACCESS_GRANTED;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function computeAccessState(array $item, ?object $user): string
    {
        $level = (string) ($item['access_level'] ?? 'public');
        if ($level === 'public' || !in_array($level, DownloadSettings::ACCESS_LEVELS, true)) {
            return self::ACCESS_GRANTED;
        }
        if ($user === null || (int) ($user->id ?? 0) <= 0) {
            return self::ACCESS_LOGIN;
        }
        $role = (string) ($user->role ?? '');
        if ($role === 'admin' || $this->isStaffUser($user)) {
            return self::ACCESS_GRANTED;
        }

        return match ($level) {
            'logged_in' => self::ACCESS_GRANTED,
            'roles' => in_array($role, (array) ($item['access_roles'] ?? []), true) ? self::ACCESS_GRANTED : self::ACCESS_ROLE,
            'member' => $this->hasMembership((int) $user->id, (array) ($item['access_plans'] ?? [])) ? self::ACCESS_GRANTED : self::ACCESS_MEMBERSHIP,
            default => self::ACCESS_LOGIN,
        };
    }

    private function isStaffUser(object $user): bool
    {
        try {
            $current = Auth::instance()->currentUser();
            if ($current !== null && (int) ($current->id ?? 0) === (int) ($user->id ?? -1)) {
                return Auth::instance()->hasCapability('manage_downloads');
            }
        } catch (\Throwable) {
        }
        if (function_exists('cms_load_role_capabilities')) {
            $caps = cms_load_role_capabilities((string) ($user->role ?? ''));

            return !empty($caps['manage_downloads']);
        }

        return false;
    }

    /**
     * Aktive Mitgliedschaft (Abo „active“/„trial“, nicht abgelaufen, oder aktive Gruppe mit Plan).
     * Ohne Planliste genügt jede solche Mitgliedschaft, sonst muss der Plan enthalten sein.
     *
     * @param list<int|string> $requiredPlans
     */
    public function hasMembership(int $userId, array $requiredPlans): bool
    {
        $plans = $this->activePlanIds($userId);
        if ($plans === []) {
            return false;
        }
        $required = DownloadRepository::intList($requiredPlans);

        return $required === [] || array_intersect($plans, $required) !== [];
    }

    /**
     * @return list<int>
     */
    private function activePlanIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        if (isset($this->planCache[$userId])) {
            return $this->planCache[$userId];
        }

        $plans = [];
        $db = $this->repo->db();
        $p = $db->getPrefix();
        try {
            if ($db->tableExists($p . 'user_subscriptions')) {
                foreach ($db->get_col(
                    "SELECT plan_id FROM {$p}user_subscriptions WHERE user_id = ? AND status IN ('active', 'trial') AND (end_date IS NULL OR end_date > NOW())",
                    [$userId]
                ) as $planId) {
                    $plans[] = (int) $planId;
                }
            }
            if ($db->tableExists($p . 'user_group_members') && $db->tableExists($p . 'user_groups')) {
                foreach ($db->get_col(
                    "SELECT ug.plan_id FROM {$p}user_group_members ugm JOIN {$p}user_groups ug ON ug.id = ugm.group_id
                     WHERE ugm.user_id = ? AND ug.is_active = 1 AND ug.plan_id IS NOT NULL",
                    [$userId]
                ) as $planId) {
                    $plans[] = (int) $planId;
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('downloads')->warning('Mitgliedschaft konnte nicht geprüft werden.', ['exception' => $e::class]);
        }

        return $this->planCache[$userId] = array_values(array_unique(array_filter($plans, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return array<string,string> slug => Bezeichnung
     */
    public function availableRoles(): array
    {
        try {
            $roles = \CMS\Services\UserService::getInstance()->getAvailableRoles();
        } catch (\Throwable) {
            $roles = [];
        }

        return is_array($roles) ? array_map('strval', $roles) : [];
    }

    /**
     * @return array<int,string> id => Name
     */
    public function availablePlans(): array
    {
        $plans = [];
        try {
            $db = $this->repo->db();
            if ($db->tableExists($db->getPrefix() . 'subscription_plans')) {
                foreach ($db->get_results('SELECT id, name FROM ' . $db->getPrefix() . 'subscription_plans WHERE is_active = 1 ORDER BY sort_order ASC, name ASC') as $plan) {
                    $plans[(int) $plan->id] = (string) $plan->name;
                }
            }
        } catch (\Throwable) {
        }

        return $plans;
    }

    // ── Dateien ──────────────────────────────────────────────────────────────

    /**
     * Prüft und speichert eine hochgeladene Datei im privaten Speicher.
     *
     * @param array<string,mixed> $file
     * @return array{success:bool,error?:string,file?:array{area:string,path:string,name:string,ext:string,mime:string,size:int,sha256:string}}
     */
    public function storeUpload(array $file): array
    {
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $maxBytes = $this->settings()->maxSizeBytes();
        if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
            return ['success' => false, 'error' => sprintf(__('downloads.error.too_large'), DownloadSecurity::formatBytes($maxBytes))];
        }
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return ['success' => false, 'error' => __('downloads.error.no_file')];
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => __('downloads.error.upload_failed')];
        }

        $originalName = DownloadSecurity::safeFilename((string) ($file['name'] ?? ''));
        $size = (int) ($file['size'] ?? 0);
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($size <= 0 || $tmp === '' || !is_file($tmp)) {
            return ['success' => false, 'error' => __('downloads.error.upload_failed')];
        }
        if ($size > $maxBytes) {
            return ['success' => false, 'error' => sprintf(__('downloads.error.too_large'), DownloadSecurity::formatBytes($maxBytes))];
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = $this->settings()->allowedExtensions();
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            return ['success' => false, 'error' => sprintf(__('downloads.error.type_not_allowed'), implode(', ', $allowed))];
        }

        $validation = MediaService::getInstance()->validateUploadFile(
            ['name' => $originalName, 'tmp_name' => $tmp, 'size' => $size, 'error' => UPLOAD_ERR_OK, 'type' => (string) ($file['type'] ?? '')],
            ['max_upload_size' => (string) max(1, (int) ceil($maxBytes / 1048576)) . 'M', 'allowed_types' => $allowed, 'block_dangerous_types' => true]
        );
        if ($validation instanceof WP_Error) {
            return ['success' => false, 'error' => $this->mediaErrorMessage((string) $validation->get_error_code(), $allowed, $maxBytes)];
        }

        try {
            $storage = PrivateStorageService::getInstance();
            $area = $this->settings()->string('storage_area');
            $target = $storage->path($area);
            $name = $storage->randomFilename($ext);
            $destination = $target . $name;
            $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $destination) : (PHP_SAPI === 'cli' && copy($tmp, $destination));
            if (!$moved || !is_file($destination)) {
                return ['success' => false, 'error' => __('downloads.error.storage_failed')];
            }
            @chmod($destination, 0640);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('downloads')->error('Download-Datei konnte nicht gespeichert werden.', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return ['success' => false, 'error' => __('downloads.error.storage_failed')];
        }

        return ['success' => true, 'file' => [
            'area' => $area,
            'path' => $name,
            'name' => $originalName,
            'ext' => $ext,
            'mime' => self::detectMime($destination, $ext),
            'size' => (int) filesize($destination),
            'sha256' => (string) hash_file('sha256', $destination),
        ]];
    }

    /**
     * Absoluter, geprüfter Pfad der Datei einer Version (realpath innerhalb des Speicherbereichs) oder null.
     *
     * @param array<string,mixed> $version
     */
    public function absolutePath(array $version): ?string
    {
        $area = (string) ($version['file_area'] ?? '');
        $path = (string) ($version['file_path'] ?? '');
        if ($area === '' || $path === '' || str_contains($path, "\0")) {
            return null;
        }

        if ($area === self::LEGACY_UPLOADS_AREA) {
            if (!defined('UPLOAD_PATH')) {
                return null;
            }
            $base = realpath(rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'downloads');
            $relative = ltrim(str_replace('\\', '/', $path), '/');
            if ($base === false || $relative === '' || str_contains($relative, '..')) {
                return null;
            }
            $candidate = realpath($base . DIRECTORY_SEPARATOR . $relative);

            return $candidate !== false && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR) && is_file($candidate) ? $candidate : null;
        }

        try {
            $resolved = PrivateStorageService::getInstance()->resolve($area, $path);
        } catch (\Throwable) {
            return null;
        }

        return $resolved !== null && is_file($resolved) ? $resolved : null;
    }

    /**
     * @param array<string,mixed> $version
     */
    public function deleteFile(array $version): void
    {
        if ($version === []) {
            return;
        }
        $path = $this->absolutePath($version);
        if ($path !== null && !@unlink($path)) {
            Logger::instance()->withChannel('downloads')->warning('Download-Datei konnte nicht gelöscht werden.', ['area' => (string) ($version['file_area'] ?? '')]);
        }
    }

    // ── Interna ──────────────────────────────────────────────────────────────

    /**
     * @param array{version:string,release_date:string,changelog:string} $meta
     * @param array{area:string,path:string,name:string,ext:string,mime:string,size:int,sha256:string}|null $fileInfo
     * @return array{success:bool,id?:int,error?:string}
     */
    public function insertVersionRecord(int $downloadId, array $meta, ?array $fileInfo, string $externalUrl, bool $makeCurrent, int $userId, array $extra = []): array
    {
        $data = array_merge([
            'download_id' => $downloadId,
            'version' => $meta['version'],
            'status' => 'archived',
            'current_flag' => null,
            'file_area' => $fileInfo['area'] ?? '',
            'file_path' => $fileInfo['path'] ?? '',
            'file_name' => $fileInfo['name'] ?? '',
            'file_ext' => $fileInfo['ext'] ?? '',
            'mime_type' => $fileInfo['mime'] ?? '',
            'file_size' => (int) ($fileInfo['size'] ?? 0),
            'sha256' => $fileInfo['sha256'] ?? '',
            'external_url' => $fileInfo === null ? $externalUrl : '',
            'release_date' => self::normalizeDate($meta['release_date']),
            'changelog' => self::plainText($meta['changelog'], 20000, true),
            'created_by' => $userId > 0 ? $userId : null,
        ], $extra);

        $versionId = $this->repo->insertVersion($data);
        if ($versionId <= 0) {
            return ['success' => false, 'error' => __('downloads.error.save_failed')];
        }
        if ($makeCurrent && !$this->repo->makeCurrent($downloadId, $versionId)) {
            $this->repo->deleteVersion($versionId);

            return ['success' => false, 'error' => __('downloads.error.save_failed')];
        }
        $this->syncFileType($downloadId);

        return ['success' => true, 'id' => $versionId];
    }

    /**
     * Dateityp des Downloads aus der aktuellen Version übernehmen (Endung bzw. „link“).
     */
    public function syncFileType(int $downloadId): void
    {
        $current = $this->repo->currentVersion($downloadId);
        $type = '';
        if ($current !== null) {
            $type = (string) $current['external_url'] !== '' && (string) $current['file_path'] === '' ? 'link' : strtolower((string) $current['file_ext']);
        }
        $this->repo->updateItem($downloadId, ['file_type' => substr($type, 0, 20)]);
    }

    private function afterSave(int $id, string $action, int $userId, bool $audit = true): void
    {
        if ($audit) {
            AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'downloads.' . $action, $action === 'create' ? 'Download angelegt' : 'Download aktualisiert', 'download', $id, [
                'user_id' => $userId,
            ]);
        }
        $item = $this->get($id);
        if ($item !== null) {
            Hooks::doAction('cms_after_download_save', $id, $item);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    private function validateItem(array $input, ?array $existing): array
    {
        $errors = [];
        $current = $existing !== null ? self::hydrate($existing) : [];
        $value = static fn (string $key, mixed $default = '') => array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? $default);

        $title = self::plainText((string) $value('title'), 255);
        if ($title === '') {
            $errors['title'] = __('downloads.error.title_required');
        }

        $explicitSlug = array_key_exists('slug', $input) && trim((string) $input['slug']) !== '';
        $slug = $explicitSlug ? strtolower(trim((string) $input['slug'])) : (string) ($current['slug'] ?? '');
        $id = (int) ($current['id'] ?? 0);
        if ($slug === '') {
            $slug = $this->uniqueSlug(DownloadSecurity::slugify($title) ?: 'download', $id);
        } elseif ($explicitSlug && (!DownloadSecurity::isValidSlug($slug) || in_array($slug, self::RESERVED_SLUGS, true))) {
            $errors['slug'] = __('downloads.error.slug_invalid');
        } elseif ($explicitSlug && $this->repo->slugExists($slug, $id)) {
            $errors['slug'] = __('downloads.error.slug_taken');
        }

        $categoryId = (int) $value('category_id', 0);
        if ($categoryId > 0 && $this->repo->findTerm($categoryId) === null) {
            $errors['category_id'] = __('downloads.error.category_not_found');
        }

        $status = (string) $value('status', 'draft');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            $status = 'draft';
        }

        $access = (string) $value('access_level', $this->settings()->string('default_access'));
        if (!in_array($access, DownloadSettings::ACCESS_LEVELS, true)) {
            $access = 'public';
        }
        $roles = $access === 'roles' ? $this->validRoles(self::listInput($value('access_roles', []))) : [];
        if ($access === 'roles' && $roles === []) {
            $errors['access_roles'] = __('downloads.error.roles_required');
        }
        $plans = $access === 'member' ? $this->validPlans(self::listInput($value('access_plans', []))) : [];

        $links = self::normalizeLinks($value('external_links', []));
        if ($links === null) {
            $errors['external_links'] = __('downloads.error.links_invalid');
            $links = [];
        }

        $cover = self::normalizeCover((string) $value('cover_media'));
        if ($cover === null) {
            $errors['cover_media'] = __('downloads.error.cover_invalid');
            $cover = '';
        }

        $description = (string) $value('description');
        if ($description !== '') {
            try {
                $description = PurifierService::getInstance()->purify($description, 'default');
            } catch (\Throwable) {
                $description = self::plainText($description, 200000, true);
            }
        }

        $data = [
            'title' => $title,
            'slug' => $slug,
            'summary' => self::plainText((string) $value('summary'), 1000, true),
            'description' => substr($description, 0, 200000),
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'cover_media' => $cover,
            'author_name' => self::plainText((string) $value('author_name'), 190),
            'license' => self::plainText((string) $value('license'), 190),
            'requirements' => self::plainText((string) $value('requirements'), 500),
            'external_links' => $links === [] ? null : (string) json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'is_signed' => DownloadSettings::toBool($value('is_signed', 0)) ? 1 : 0,
            'signature_info' => self::plainText((string) $value('signature_info'), 255),
            'access_level' => $access,
            'access_roles' => self::encodeList($roles),
            'access_plans' => self::encodeList($plans),
            'status' => $status,
            'is_featured' => DownloadSettings::toBool($value('is_featured', 0)) ? 1 : 0,
            'sort_order' => max(-100000, min(100000, (int) $value('sort_order', 0))),
            'seo_title' => self::plainText((string) $value('seo_title'), 255),
            'seo_description' => self::plainText((string) $value('seo_description'), 500),
            'seo_noindex' => DownloadSettings::toBool($value('seo_noindex', 0)) ? 1 : 0,
        ];

        return [$data, $errors];
    }

    private function uniqueSlug(string $base, int $exceptId): string
    {
        $base = $base !== '' ? substr($base, 0, 170) : 'download';
        if (in_array($base, self::RESERVED_SLUGS, true)) {
            $base .= '-download';
        }
        $slug = $base;
        $suffix = 2;
        while ($this->repo->slugExists($slug, $exceptId)) {
            $slug = $base . '-' . $suffix++;
            if ($suffix > 500) {
                return $base . '-' . bin2hex(random_bytes(3));
            }
        }

        return $slug;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    private function validateProperties(array $input): array
    {
        $values = [];
        foreach ($this->settings()->propertyFields() as $field) {
            $key = $field['key'];
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $raw = $input[$key];
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            if ($value === '') {
                continue;
            }
            switch ($field['type']) {
                case 'number':
                    if (!is_numeric(str_replace(',', '.', $value))) {
                        return ['__error' => sprintf(__('downloads.error.property_invalid'), $field['label'])];
                    }
                    $value = (string) (0 + str_replace(',', '.', $value));
                    break;
                case 'select':
                    if (!in_array($value, $field['options'], true)) {
                        return ['__error' => sprintf(__('downloads.error.property_invalid'), $field['label'])];
                    }
                    break;
                case 'url':
                    if (filter_var($value, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $value) !== 1) {
                        return ['__error' => sprintf(__('downloads.error.property_invalid'), $field['label'])];
                    }
                    $value = substr($value, 0, 500);
                    break;
                case 'boolean':
                    $value = DownloadSettings::toBool($value) ? '1' : '0';
                    break;
                case 'date':
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if ($date === false || $date->format('Y-m-d') !== $value) {
                        return ['__error' => sprintf(__('downloads.error.property_invalid'), $field['label'])];
                    }
                    break;
                case 'textarea':
                    $value = self::plainText($value, 5000, true);
                    break;
                default:
                    $value = self::plainText($value, 500);
            }
            if ($value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * @return list<int>
     */
    private function resolveTagIds(mixed $tags): array
    {
        $names = [];
        foreach (self::listInput($tags) as $name) {
            $name = self::plainText($name, 80);
            if ($name !== '') {
                $names[mb_strtolower($name)] = $name;
            }
        }
        $ids = [];
        foreach (array_slice($names, 0, 30) as $name) {
            $slug = DownloadSecurity::slugify($name);
            if ($slug === '') {
                continue;
            }
            $term = $this->repo->findTermBySlug('tag', $slug);
            $ids[] = $term !== null
                ? (int) $term['id']
                : $this->repo->insertTerm(['term_type' => 'tag', 'name' => $name, 'slug' => $slug, 'status' => 'active']);
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    private function validRoles(array $roles): array
    {
        $available = $this->availableRoles();

        return array_values(array_unique(array_filter($roles, static fn (string $role): bool => isset($available[$role]))));
    }

    /**
     * @param list<int|string> $plans
     * @return list<int>
     */
    private function validPlans(array $plans): array
    {
        $available = $this->availablePlans();

        return array_values(array_filter(DownloadRepository::intList($plans), static fn (int $id): bool => isset($available[$id])));
    }

    private function mediaErrorMessage(string $code, array $allowed, int $maxBytes): string
    {
        return match ($code) {
            'size_limit' => sprintf(__('downloads.error.too_large'), DownloadSecurity::formatBytes($maxBytes)),
            'type_not_allowed', 'missing_extension', 'type_blocked' => sprintf(__('downloads.error.type_not_allowed'), implode(', ', $allowed)),
            'dangerous_type' => __('downloads.error.dangerous_type'),
            'mime_mismatch' => __('downloads.error.mime_mismatch'),
            default => __('downloads.error.upload_failed'),
        };
    }

    // ── Statische Helfer ─────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function hydrate(array $row): array
    {
        $item = $row;
        foreach (['id', 'is_signed', 'is_featured', 'sort_order', 'seo_noindex', 'download_count', 'download_count_base', 'current_size'] as $key) {
            if (array_key_exists($key, $item)) {
                $item[$key] = (int) ($item[$key] ?? 0);
            }
        }
        foreach (['category_id', 'legacy_id', 'created_by', 'updated_by', 'trashed_by', 'current_version_id'] as $key) {
            if (array_key_exists($key, $item)) {
                $item[$key] = $item[$key] === null ? null : (int) $item[$key];
            }
        }
        foreach (['title', 'slug', 'summary', 'description', 'status', 'access_level', 'file_type'] as $key) {
            $item[$key] = (string) ($item[$key] ?? '');
        }
        $item['external_links'] = self::normalizeLinks((string) ($row['external_links'] ?? '')) ?? [];
        $item['access_roles'] = array_values(array_filter(array_map('strval', self::decodeList($row['access_roles'] ?? null))));
        $item['access_plans'] = DownloadRepository::intList(self::decodeList($row['access_plans'] ?? null));

        return $item;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function hydrateVersion(array $row): array
    {
        $version = $row;
        foreach (['id', 'download_id', 'file_size', 'download_count'] as $key) {
            $version[$key] = (int) ($row[$key] ?? 0);
        }
        $version['is_current'] = (int) ($row['current_flag'] ?? 0) === 1;
        $version['is_external'] = (string) ($row['external_url'] ?? '') !== '' && (string) ($row['file_path'] ?? '') === '';
        foreach (['version', 'file_name', 'file_ext', 'mime_type', 'sha256', 'external_url', 'changelog', 'file_area', 'file_path', 'status'] as $key) {
            $version[$key] = (string) ($row[$key] ?? '');
        }
        $version['release_date'] = (string) ($row['release_date'] ?? '');

        return $version;
    }

    public static function titleFromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[_\s]+/', ' ', str_replace('-', ' ', $base)));

        return $base !== '' ? (function_exists('mb_substr') ? mb_substr($base, 0, 200) : substr($base, 0, 200)) : 'Download';
    }

    public static function plainText(string $value, int $max, bool $multiline = false): string
    {
        $value = str_replace("\0", '', $value);
        $value = strip_tags($value);
        if ($multiline) {
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        } else {
            $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        }
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    public static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return date('Y-m-d');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $date !== false ? $date->format('Y-m-d') : date('Y-m-d');
    }

    /**
     * Externe Links: Liste aus Arrays {label,url} oder Text „Bezeichnung | URL“ je Zeile.
     *
     * @return list<array{label:string,url:string}>|null null = ungültige URL enthalten
     */
    public static function normalizeLinks(mixed $value): ?array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            $decoded = $trimmed[0] === '[' ? json_decode($trimmed, true) : null;
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = [];
                foreach (preg_split('/\R/', $trimmed) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $parts = array_map('trim', explode('|', $line, 2));
                    $value[] = count($parts) === 2 ? ['label' => $parts[0], 'url' => $parts[1]] : ['label' => '', 'url' => $parts[0]];
                }
            }
        }
        if (!is_array($value)) {
            return [];
        }

        $links = [];
        foreach ($value as $link) {
            if (!is_array($link)) {
                continue;
            }
            $url = trim((string) ($link['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $url) !== 1) {
                return null;
            }
            $label = self::plainText((string) ($link['label'] ?? ''), 120);
            $links[] = ['label' => $label !== '' ? $label : (string) parse_url($url, PHP_URL_HOST), 'url' => $url];
            if (count($links) >= 10) {
                break;
            }
        }

        return $links;
    }

    /**
     * Titelbild: Pfad unter /uploads/ oder http(s)-URL; null = ungültig.
     */
    public static function normalizeCover(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 500 || preg_match('/[\s"\'<>\\\\]/', $value) === 1 || str_contains($value, '..')) {
            return null;
        }
        if (str_starts_with($value, '/uploads/')) {
            return $value;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function listInput(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            $decoded = $trimmed !== '' && $trimmed[0] === '[' ? json_decode($trimmed, true) : null;
            $value = is_array($decoded) ? $decoded : (preg_split('/[,;\r\n]+/', $trimmed) ?: []);
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $value), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @return list<mixed>
     */
    private static function decodeList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param list<int|string> $values
     */
    private static function encodeList(array $values): ?string
    {
        return $values === [] ? null : (string) json_encode(array_values($values), JSON_UNESCAPED_UNICODE);
    }

    public static function detectMime(string $path, string $ext): string
    {
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $path);
            }
        }
        if ($mime === '' || $mime === 'application/octet-stream' || preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $mime) !== 1) {
            $mime = self::mimeForExtension($ext);
        }

        return substr($mime, 0, 127);
    }

    public static function mimeForExtension(string $ext): string
    {
        return match (strtolower($ext)) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'rtf' => 'application/rtf',
            'csv' => 'text/csv',
            'zip' => 'application/zip',
            'rar' => 'application/vnd.rar',
            '7z' => 'application/x-7z-compressed',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            default => 'application/octet-stream',
        };
    }
}
