<?php
/**
 * Übernahme der Daten des Plugins cms-downloads in die Core-Downloads.
 *
 * Quelle: `download_categories`, `downloads`, `download_settings`. Die Plugin-Tabellen bleiben unverändert.
 * Idempotent über `legacy_id` (Kategorien, Downloads, Versionen) und einen gespeicherten Fortschritt.
 *
 * - Je Plugin-Download entsteht ein Download mit genau einer (aktuellen) Version; SHA-256 und Größe werden aus der
 *   Datei berechnet, der bisherige Zähler bleibt als `download_count_base` erhalten.
 * - Öffentliche Dateien bleiben an ihrem Ort unter `uploads/downloads/` (Bereich `@uploads`), damit bestehende
 *   Links nicht brechen. Dateien von Downloads mit Anmeldepflicht werden in den privaten Speicher verschoben,
 *   weil sie unter `/uploads/` direkt abrufbar wären (realpath-geprüft, Original erst nach erfolgreicher Kopie
 *   gelöscht).
 * - Der Plugin-Typ (`download_type`) wird zum Schlagwort.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\PluginMigration;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Downloads\DownloadRepository;
use CMS\Services\Downloads\DownloadSecurity;
use CMS\Services\Downloads\DownloadService;
use CMS\Services\Downloads\DownloadSettings;

if (!defined('ABSPATH')) {
    exit;
}

final class DownloadsPluginMigrator implements PluginMigrator
{
    private const STATE_OPTION = 'downloads_plugin_migration';
    private const BATCH = 100;

    private const TYPE_LABELS = [
        'powershell' => 'PowerShell', 'webproject' => 'Webprojekt', 'document' => 'Dokument',
        'ebook' => 'eBook', 'archive' => 'Archiv/Toolkit',
    ];

    private Database $db;
    private string $prefix;

    /** @var array<string, int> */
    private array $report = ['categories' => 0, 'downloads' => 0, 'versions' => 0, 'moved_files' => 0, 'missing_files' => 0];

    public function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function pluginSlug(): string
    {
        return 'cms-downloads';
    }

    public function hasSourceData(): bool
    {
        try {
            return $this->db->tableExists($this->prefix . 'downloads');
        } catch (\Throwable) {
            return false;
        }
    }

    public function migrate(): bool
    {
        $repo = DownloadRepository::getInstance();
        $repo->resetAvailability();
        if (!$repo->isAvailable()) {
            return false;
        }

        $state = $this->state();
        foreach ($this->report as $key => $value) {
            $this->report[$key] = (int) ($state['report'][$key] ?? 0);
        }

        if (empty($state['settings_done'])) {
            $this->migrateSettings();
            $state['settings_done'] = true;
        }
        if (empty($state['categories_done'])) {
            $this->migrateCategories();
            $state['categories_done'] = true;
        }

        $next = $this->migrateDownloads((int) ($state['cursor'] ?? 0));
        $state['cursor'] = $next ?? (int) ($state['cursor'] ?? 0);
        $state['report'] = $this->report;
        if ($next === null) {
            $state['done'] = true;
        }
        $this->saveState($state);

        return $next === null;
    }

    public function report(): array
    {
        return $this->report;
    }

    private function migrateSettings(): void
    {
        if (!$this->db->tableExists($this->prefix . 'download_settings')) {
            return;
        }
        $plugin = [];
        foreach ($this->db->get_results("SELECT setting_key, setting_value FROM {$this->prefix}download_settings") ?: [] as $row) {
            $plugin[(string) $row->setting_key] = (string) ($row->setting_value ?? '');
        }
        if ($plugin === []) {
            return;
        }

        $settings = DownloadSettings::getInstance();
        $current = $settings->all();
        $values = [];
        $map = [
            'archive_title' => 'archive_title', 'archive_title_en' => 'archive_title_en',
            'archive_description' => 'archive_description', 'archive_description_en' => 'archive_description_en',
            'downloads_per_page' => 'per_page', 'show_search' => 'show_search', 'show_category_overview' => 'show_category_overview',
            'show_external_notice' => 'show_external_notice', 'external_allowed_domains' => 'external_allowed_domains',
            'show_nav_link' => 'show_nav_link', 'nav_label' => 'nav_label', 'nav_label_en' => 'nav_label_en',
            'rate_limit_enabled' => 'rate_limit_enabled', 'rate_limit_max' => 'rate_limit_max', 'rate_limit_window' => 'rate_limit_window',
        ];
        foreach ($map as $from => $to) {
            // Nur übernehmen, solange der Core-Wert noch dem Standard entspricht (erneuter Lauf überschreibt nichts).
            if (array_key_exists($from, $plugin) && $current[$to] == DownloadSettings::DEFAULTS[$to]) {
                $values[$to] = $plugin[$from];
            }
        }
        if ($values !== []) {
            $settings->save($values);
        }
    }

    private function migrateCategories(): void
    {
        if (!$this->db->tableExists($this->prefix . 'download_categories')) {
            return;
        }
        $repo = DownloadRepository::getInstance();
        foreach ($this->db->get_results("SELECT * FROM {$this->prefix}download_categories ORDER BY id ASC") ?: [] as $row) {
            $legacyId = (int) $row->id;
            if ($repo->findTermByLegacyId('category', $legacyId) !== null) {
                continue;
            }
            $slug = DownloadSecurity::slugify((string) ($row->slug ?? '') !== '' ? (string) $row->slug : (string) $row->name);
            $existing = $slug !== '' ? $repo->findTermBySlug('category', $slug) : null;
            if ($existing !== null) {
                // Gleichnamige Core-Kategorie übernimmt die Zuordnung.
                $repo->updateTerm((int) $existing['id'], ['legacy_id' => $legacyId]);
                continue;
            }
            $id = $repo->insertTerm([
                'term_type' => 'category',
                'name' => DownloadService::plainText((string) $row->name, 190),
                'slug' => $slug !== '' ? $slug : 'kategorie-' . $legacyId,
                'description' => DownloadService::plainText((string) ($row->description ?? ''), 2000, true),
                'icon' => mb_substr((string) ($row->icon ?? ''), 0, 64),
                'status' => (string) ($row->status ?? 'active') === 'inactive' ? 'inactive' : 'active',
                'sort_order' => (int) ($row->sort_order ?? 0),
                'legacy_id' => $legacyId,
            ]);
            if ($id > 0) {
                $this->report['categories']++;
            }
        }
    }

    /**
     * Nächster Block. Liefert den neuen Cursor oder null, wenn alles übernommen ist.
     */
    private function migrateDownloads(int $cursor): ?int
    {
        $rows = $this->db->get_results(
            "SELECT * FROM {$this->prefix}downloads WHERE id > ? ORDER BY id ASC LIMIT " . self::BATCH,
            [$cursor]
        ) ?: [];
        if ($rows === []) {
            return null;
        }

        $repo = DownloadRepository::getInstance();
        $service = DownloadService::getInstance();
        foreach ($rows as $row) {
            $legacyId = (int) $row->id;
            $cursor = $legacyId;
            if ($repo->findItemByLegacyId($legacyId) !== null) {
                continue;
            }
            try {
                $this->migrateDownload($row, $repo, $service);
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('plugins.integration')->warning('cms-downloads: Download konnte nicht übernommen werden.', [
                    'legacy_id' => $legacyId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return count($rows) < self::BATCH ? null : $cursor;
    }

    private function migrateDownload(object $row, DownloadRepository $repo, DownloadService $service): void
    {
        $legacyId = (int) $row->id;
        $category = (int) ($row->category_id ?? 0) > 0 ? $repo->findTermByLegacyId('category', (int) $row->category_id) : null;
        $restricted = !empty($row->requires_login);
        $slug = DownloadSecurity::slugify((string) ($row->slug ?? '') !== '' ? (string) $row->slug : (string) $row->title);
        if ($slug === '' || in_array($slug, DownloadService::RESERVED_SLUGS, true) || $repo->slugExists($slug)) {
            $slug = ($slug !== '' ? $slug : 'download') . '-' . $legacyId;
        }
        $count = max(0, (int) ($row->download_count ?? 0));
        $created = (string) ($row->created_at ?? '') !== '' ? (string) $row->created_at : date('Y-m-d H:i:s');
        $published = (string) ($row->status ?? 'active') === 'active';

        $id = $repo->insertItem([
            'title' => DownloadService::plainText((string) $row->title, 255) ?: 'Download ' . $legacyId,
            'slug' => $slug,
            'summary' => DownloadService::plainText((string) ($row->summary ?? ''), 1000, true),
            'description' => (string) ($row->description ?? ''),
            'category_id' => $category !== null ? (int) $category['id'] : null,
            'file_type' => '',
            'access_level' => $restricted ? 'logged_in' : 'public',
            'status' => $published ? 'published' : 'draft',
            'is_featured' => !empty($row->is_featured) ? 1 : 0,
            'sort_order' => (int) ($row->sort_order ?? 0),
            'download_count' => $count,
            'download_count_base' => $count,
            'legacy_id' => $legacyId,
            'published_at' => $published ? $created : null,
            'created_at' => $created,
            'updated_at' => (string) ($row->updated_at ?? '') !== '' ? (string) $row->updated_at : $created,
        ]);
        if ($id <= 0) {
            return;
        }
        $this->report['downloads']++;

        $type = (string) ($row->download_type ?? '');
        if (isset(self::TYPE_LABELS[$type])) {
            $tagSlug = DownloadSecurity::slugify(self::TYPE_LABELS[$type]);
            $tag = $repo->findTermBySlug('tag', $tagSlug);
            $tagId = $tag !== null ? (int) $tag['id'] : $repo->insertTerm(['term_type' => 'tag', 'name' => self::TYPE_LABELS[$type], 'slug' => $tagSlug, 'status' => 'active']);
            if ($tagId > 0) {
                $repo->setItemTags($id, [$tagId]);
            }
        }

        $version = DownloadSecurity::normalizeVersion((string) ($row->version_label ?? ''));
        $meta = [
            'version' => DownloadSecurity::isValidVersion($version) ? $version : '1.0',
            'release_date' => substr((string) ($row->updated_at ?? $created), 0, 10),
            'changelog' => '',
        ];

        // Auch unveröffentlichte Downloads gehören in den privaten Speicher: Unter uploads/downloads wären sie
        // ohne Zugriffsprüfung direkt abrufbar.
        $fileInfo = $this->legacyFile((string) ($row->file_path ?? ''), (string) ($row->file_name ?? ''), $restricted || !$published);
        $externalUrl = trim((string) ($row->external_url ?? ''));
        if ($fileInfo === null && $externalUrl === '') {
            if (trim((string) ($row->file_path ?? '')) !== '') {
                $this->report['missing_files']++;
                Logger::instance()->withChannel('plugins.integration')->warning('cms-downloads: Datei nicht gefunden – Download ohne Version übernommen.', ['legacy_id' => $legacyId]);
            }

            return;
        }

        $result = $service->insertVersionRecord($id, $meta, $fileInfo, $fileInfo === null ? $externalUrl : '', true, 0, [
            'download_count' => $count,
            'legacy_id' => $legacyId,
            'legacy_path' => $fileInfo !== null ? (string) ($row->file_path ?? '') : null,
            'created_at' => $created,
        ]);
        if (!empty($result['success'])) {
            $this->report['versions']++;
        }
    }

    /**
     * Datei eines Plugin-Downloads: bleibt unter uploads/downloads (öffentlich) oder wird in den privaten Speicher
     * verschoben (Anmeldepflicht).
     *
     * @return array{area:string,path:string,name:string,ext:string,mime:string,size:int,sha256:string}|null
     */
    private function legacyFile(string $filePath, string $fileName, bool $restricted): ?array
    {
        $relative = ltrim(str_replace('\\', '/', $filePath), '/');
        if ($relative === '' || !defined('UPLOAD_PATH')) {
            return null;
        }
        if (str_starts_with($relative, 'downloads/')) {
            $relative = substr($relative, strlen('downloads/'));
        }
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            return null;
        }

        $base = realpath(rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'downloads');
        $path = $base !== false ? realpath($base . DIRECTORY_SEPARATOR . $relative) : false;
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }

        $name = DownloadSecurity::safeFilename($fileName !== '' ? $fileName : basename($path));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }
        $info = [
            'area' => DownloadService::LEGACY_UPLOADS_AREA,
            'path' => $relative,
            'name' => $name,
            'ext' => $ext,
            'mime' => DownloadService::detectMime($path, $ext),
            'size' => (int) filesize($path),
            'sha256' => (string) hash_file('sha256', $path),
        ];

        if (!$restricted) {
            return $info;
        }

        $target = DownloadService::getInstance()->moveToPrivateStorage($path, $ext);
        if ($target === null) {
            // Bleibt vorerst unter uploads/downloads; DownloadService verschiebt beim nächsten Speichern erneut.
            Logger::instance()->withChannel('plugins.integration')->warning('cms-downloads: Datei konnte nicht in den privaten Speicher verschoben werden.', ['file' => $name]);

            return $info;
        }
        $this->report['moved_files']++;

        return $target + $info;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        try {
            $raw = $this->db->get_var("SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1", [self::STATE_OPTION]);
            $state = is_string($raw) ? json_decode($raw, true) : null;
        } catch (\Throwable) {
            $state = null;
        }

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(array $state): void
    {
        $this->db->execute(
            "INSERT INTO {$this->prefix}settings (option_name, option_value, autoload) VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            [self::STATE_OPTION, (string) json_encode($state)]
        );
    }
}
