<?php
declare(strict_types=1);

namespace CMS\Services\Media;

use CMS\Auth;
use CMS\Contracts\LoggerInterface;
use CMS\Json;
use CMS\Logger;
use CMS\Services\MediaDeliveryService;
use CMS\WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaRepository
{
    private const CATEGORY_NAME_MAX_LENGTH = 80;
    private const TAG_NAME_MAX_LENGTH = 40;
    private const MAX_TAGS_PER_FILE = 20;
    private const MAX_UPLOADED_BY_LENGTH = 120;
    private const MAX_ORIGINAL_NAME_LENGTH = 180;
    private const ATOMIC_FILE_MODE = 0640;
    private const META_LOCK_TIMEOUT_MS = 5000;
    private const META_LOCK_RETRY_MS = 25;

    /** System-Kategorien, die immer vorhanden sind (Slug => Name). */
    private const SYSTEM_CATEGORIES = [
        'themes' => 'Themes',
        'plugins' => 'Plugins',
        'assets' => 'Assets',
        'fonts' => 'Fonts',
        'dl-manager' => 'Downloads',
        'form-uploads' => 'Form Uploads',
        'member' => 'Member',
    ];

    private LoggerInterface $logger;

    public function __construct(
        private readonly string $uploadPath,
        private readonly string $uploadUrl,
        private readonly string $metaFile,
        private readonly array $systemFolders,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? Logger::instance()->withChannel('media.repository');
        $this->ensureSystemCategories();
    }

    public function ensureSystemCategories(): void
    {
        // Ohne Sperre prüfen: Der Normalfall (alles vorhanden) schreibt nicht und blockiert keine Requests.
        if ($this->missingSystemCategories($this->loadMeta()) === []) {
            return;
        }

        $this->updateMeta(function (array $meta): ?array {
            $missing = $this->missingSystemCategories($meta);
            if ($missing === []) {
                return null;
            }

            foreach ($missing as $slug => $name) {
                $meta['categories'][] = [
                    'name' => $name,
                    'slug' => $slug,
                    'count' => 0,
                    'is_system' => true,
                ];
            }

            return $meta;
        });
    }

    public function getCategories(): array
    {
        $meta = $this->loadMeta();
        $counts = [];

        foreach (($meta['files'] ?? []) as $fileMeta) {
            $category = $fileMeta['category'] ?? null;
            if ($category) {
                $counts[$category] = ($counts[$category] ?? 0) + 1;
            }
        }

        return array_map(static function (array $category) use ($counts): array {
            $slug = $category['slug'] ?? '';
            $category['count'] = $counts[$slug] ?? 0;
            return $category;
        }, $meta['categories'] ?? []);
    }

    public function addCategory(string $name, string $slug = ''): bool|WP_Error
    {
        $name = $this->normalizeCategoryName($name);
        $slug = $this->normalizeCategorySlug($slug !== '' ? $slug : $name);

        if ($name === '' || $slug === '') {
            return new WP_Error('invalid_category', 'Name und Slug sind erforderlich');
        }

        if (in_array($slug, $this->systemFolders, true)) {
            return new WP_Error('system_category', 'System-Kategorien können nicht überschrieben werden');
        }

        return $this->updateMeta(static function (array $meta) use ($name, $slug): array|WP_Error {
            foreach (($meta['categories'] ?? []) as $cat) {
                if (($cat['slug'] ?? '') === $slug) {
                    return new WP_Error('exists', 'Category already exists');
                }
            }

            $meta['categories'][] = [
                'name' => $name,
                'slug' => $slug,
                'count' => 0,
            ];

            return $meta;
        });
    }

    public function deleteCategory(string $slug): bool|WP_Error
    {
        $slug = $this->normalizeCategorySlug($slug);

        if ($slug === '') {
            return new WP_Error('missing_slug', 'Kategorie-Slug fehlt');
        }

        return $this->updateMeta(static function (array $meta) use ($slug): array|WP_Error {
            $categoryFound = false;

            foreach (($meta['categories'] ?? []) as $category) {
                if (($category['slug'] ?? '') !== $slug) {
                    continue;
                }

                $categoryFound = true;
                if (!empty($category['is_system'])) {
                    return new WP_Error('system_category', 'System-Kategorien können nicht gelöscht werden');
                }
            }

            if (!$categoryFound) {
                return new WP_Error('missing_category', 'Kategorie nicht gefunden');
            }

            $meta['categories'] = array_values(array_filter(
                $meta['categories'] ?? [],
                static fn(array $category): bool => ($category['slug'] ?? '') !== $slug
            ));

            foreach ($meta['files'] as &$fileMeta) {
                if (($fileMeta['category'] ?? null) === $slug) {
                    unset($fileMeta['category']);
                }
            }
            unset($fileMeta);

            return $meta;
        });
    }

    public function assignCategory(string $filePath, string $categorySlug): bool|WP_Error
    {
        $filePath = $this->normalizeRelativePath($filePath);

        if ($filePath === '') {
            return new WP_Error('invalid_path', 'Dateipfad ist ungültig');
        }

        $fullPath = $this->resolvePath($filePath);
        if ($fullPath instanceof WP_Error) {
            return $fullPath;
        }

        if (!is_file($fullPath)) {
            return new WP_Error('missing_file', 'Kategorie kann nur bestehenden Dateien zugewiesen werden');
        }

        $categorySlug = $this->normalizeCategorySlug($categorySlug);

        return $this->updateMeta(static function (array $meta) use ($filePath, $categorySlug): array|WP_Error {
            if ($categorySlug !== '') {
                $validSlugs = array_column($meta['categories'] ?? [], 'slug');
                if (!in_array($categorySlug, $validSlugs, true)) {
                    return new WP_Error('invalid_category', 'Kategorie existiert nicht');
                }
            }

            if (!isset($meta['files'][$filePath])) {
                $meta['files'][$filePath] = [];
            }

            if ($categorySlug === '') {
                unset($meta['files'][$filePath]['category']);
            } else {
                $meta['files'][$filePath]['category'] = $categorySlug;
            }

            return $meta;
        });
    }

    /**
     * @param array<int, string> $tags
     */
    public function assignTags(string $filePath, array $tags, string $mode = 'replace'): bool|WP_Error
    {
        $filePath = $this->normalizeRelativePath($filePath);

        if ($filePath === '') {
            return new WP_Error('invalid_path', 'Dateipfad ist ungültig');
        }

        $fullPath = $this->resolvePath($filePath);
        if ($fullPath instanceof WP_Error) {
            return $fullPath;
        }

        if (!is_file($fullPath)) {
            return new WP_Error('missing_file', 'Tags können nur bestehenden Dateien zugewiesen werden');
        }

        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['add', 'replace', 'remove', 'clear'], true)) {
            return new WP_Error('invalid_tag_mode', 'Tag-Aktion ist ungültig');
        }

        $normalizedTags = $this->normalizeTags($tags);
        if ($mode !== 'clear' && $normalizedTags === []) {
            return new WP_Error('missing_tags', 'Bitte mindestens einen gültigen Tag angeben');
        }

        return $this->updateMeta(function (array $meta) use ($filePath, $mode, $normalizedTags): array {
            if (!isset($meta['files'][$filePath])) {
                $meta['files'][$filePath] = [];
            }

            $currentTags = $this->normalizeTags((array) ($meta['files'][$filePath]['tags'] ?? []));
            $tagLookup = array_fill_keys($currentTags, true);

            if ($mode === 'clear') {
                $nextTags = [];
            } elseif ($mode === 'replace') {
                $nextTags = $normalizedTags;
            } elseif ($mode === 'remove') {
                $removeLookup = array_fill_keys($normalizedTags, true);
                $nextTags = array_values(array_filter(
                    $currentTags,
                    static fn (string $tag): bool => !isset($removeLookup[$tag])
                ));
            } else {
                foreach ($normalizedTags as $tag) {
                    $tagLookup[$tag] = true;
                }
                $nextTags = array_slice(array_keys($tagLookup), 0, self::MAX_TAGS_PER_FILE);
            }

            if ($nextTags === []) {
                unset($meta['files'][$filePath]['tags']);
            } else {
                $meta['files'][$filePath]['tags'] = $nextTags;
            }

            return $meta;
        });
    }

    public function loadMeta(): array
    {
        if (!file_exists($this->metaFile)) {
            return $this->emptyMetaState();
        }

        $content = file_get_contents($this->metaFile);
        if ($content === false) {
            $this->logger->warning('Media-Metadaten konnten nicht gelesen werden.', [
                'path' => $this->metaFile,
            ]);

            return $this->emptyMetaState();
        }

        $data = Json::decodeArray($content, []);

        return $this->normalizeMetaState(is_array($data) ? $data : []);
    }

    public function saveMeta(array $data): bool
    {
        $normalized = $this->normalizeMetaState($data);
        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        return $this->writeFileAtomically($this->metaFile, $json . "\n");
    }

    /**
     * Liest, ändert und schreibt die Metadaten unter einer exklusiven Sperre (`<Metadatei>.lock`).
     *
     * Der Callback erhält den aktuellen, normalisierten Stand und gibt zurück:
     * - den neuen Stand (wird normalisiert und atomar geschrieben),
     * - `null`, wenn nichts zu ändern ist (Ergebnis `true`), oder
     * - einen `WP_Error`, der ohne Schreiben durchgereicht wird.
     *
     * So überschreiben sich parallele Schreiber (Upload, Papierkorb, Import, Lizenzfelder) nicht
     * gegenseitig. Leser brauchen keine Sperre, weil die Datei per Umbenennen ersetzt wird.
     *
     * @param callable(array{categories: list<array<string, mixed>>, files: array<string, array<string, mixed>>}): (array<string, mixed>|WP_Error|null) $mutator
     * @return bool|WP_Error `false`, wenn die Sperre nicht erlangt oder die Datei nicht geschrieben werden konnte
     */
    public function updateMeta(callable $mutator): bool|WP_Error
    {
        $lock = $this->acquireMetaLock();
        if ($lock === null) {
            return false;
        }

        try {
            $next = $mutator($this->loadMeta());
            if ($next === null) {
                return true;
            }
            if ($next instanceof WP_Error) {
                return $next;
            }

            return $this->saveMeta($next);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Metadaten einer Datei (Pfad relativ zum Upload-Verzeichnis), leer, wenn keine vorhanden sind.
     *
     * @return array<string, mixed>
     */
    public function getFileMeta(string $relativePath): array
    {
        $path = $this->normalizeRelativePath($relativePath);
        if ($path === '') {
            return [];
        }

        $entry = $this->loadMeta()['files'][$path] ?? null;

        return is_array($entry) ? $entry : [];
    }

    /**
     * Ersetzt die Metadaten einer Datei vollständig; `null` entfernt den Eintrag. Für Papierkorb
     * (Sichern und Wiederherstellen) und Import. Unbekannte Felder verwirft die Normalisierung.
     *
     * @param array<string, mixed>|null $fileMeta
     */
    public function setFileMeta(string $relativePath, ?array $fileMeta): bool
    {
        $path = $this->normalizeRelativePath($relativePath);
        if ($path === '') {
            return false;
        }

        return $this->updateMeta(static function (array $meta) use ($path, $fileMeta): array {
            if ($fileMeta === null) {
                unset($meta['files'][$path]);
            } else {
                $meta['files'][$path] = $fileMeta;
            }

            return $meta;
        }) === true;
    }

    /**
     * Ergänzt einzelne Felder der Metadaten einer Datei; ein Wert `null` entfernt das Feld.
     *
     * @param array<string, mixed> $fields
     */
    public function mergeFileMeta(string $relativePath, array $fields): bool
    {
        $path = $this->normalizeRelativePath($relativePath);
        if ($path === '') {
            return false;
        }

        return $this->updateMeta(static function (array $meta) use ($path, $fields): array {
            $entry = is_array($meta['files'][$path] ?? null) ? $meta['files'][$path] : [];
            foreach ($fields as $key => $value) {
                if ($value === null) {
                    unset($entry[$key]);
                } else {
                    $entry[$key] = $value;
                }
            }
            $meta['files'][$path] = $entry;

            return $meta;
        }) === true;
    }

    public function buildPublicUrl(string $relativePath): string
    {
        return MediaDeliveryService::getInstance()->buildAccessUrl($relativePath, false);
    }

    public function buildPreviewUrl(string $relativePath): string
    {
        return MediaDeliveryService::getInstance()->buildPreviewUrl($relativePath);
    }

    public function detectSystemCategory(string $relativePath): ?string
    {
        $parts = explode('/', $relativePath);
        if ($parts !== [] && in_array($parts[0], $this->systemFolders, true)) {
            return $parts[0];
        }

        return null;
    }

    public function isSystemPath(string $relativePath): bool
    {
        $normalized = trim(str_replace('\\', '/', $relativePath), '/');
        $parts = array_values(array_filter(explode('/', $normalized)));

        if ($parts === []) {
            return false;
        }

        // Top-level system folders (e.g. 'member', 'themes', 'plugins')
        if (count($parts) === 1 && in_array($parts[0], $this->systemFolders, true)) {
            return true;
        }

        // Direct children of 'member' (member/user-X) are also protected
        if (count($parts) === 2 && $parts[0] === 'member') {
            return true;
        }

        return false;
    }

    public function pathExists(string $relativePath): bool
    {
        $normalizedPath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === '' || $normalizedPath === '') {
            return trim((string) $relativePath) === '' ? is_dir($this->uploadPath) : false;
        }

        $fullPath = $this->resolvePath($normalizedPath);
        if ($fullPath instanceof WP_Error) {
            return false;
        }

        return file_exists($fullPath);
    }

    public function isDirectory(string $relativePath): bool
    {
        $normalizedPath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === '' || $normalizedPath === '') {
            return trim((string) $relativePath) === '' ? is_dir($this->uploadPath) : false;
        }

        $fullPath = $this->resolvePath($normalizedPath);
        if ($fullPath instanceof WP_Error) {
            return false;
        }

        return is_dir($fullPath);
    }

    public function getItems(string $path = ''): array|WP_Error
    {
        $fullPath = $this->resolvePath($path);
        if ($fullPath instanceof WP_Error) {
            return $fullPath;
        }

        if (!is_dir($fullPath)) {
            return new WP_Error('invalid_path', 'Directory does not exist');
        }

        $items = ['folders' => [], 'files' => []];
        $meta = $this->loadMeta();

        try {
            foreach (scandir($fullPath) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                if (str_starts_with((string) $item, '.')) {
                    continue;
                }

                $itemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
                $relativePath = trim($path . '/' . $item, '/');
                $keyPath = str_replace('\\', '/', $relativePath);
                $metaData = $meta['files'][$keyPath] ?? [];
                $category = $metaData['category'] ?? $this->detectSystemCategory($relativePath);
                $uploaderId = $metaData['uploader_id'] ?? null;
                $uploadedBy = $metaData['uploaded_by'] ?? null;
                $isSystem = $this->isSystemPath($relativePath);

                if (is_dir($itemPath)) {
                    $items['folders'][] = [
                        'name' => $item,
                        'path' => $relativePath,
                        'type' => 'folder',
                        'modified' => filemtime($itemPath),
                        'items_count' => count(scandir($itemPath) ?: []) - 2,
                        'category' => $category,
                        'uploader_id' => $uploaderId,
                        'uploaded_by' => $uploadedBy,
                        'is_system' => $isSystem,
                    ];
                    continue;
                }

                $items['files'][] = [
                    'name' => $item,
                    'path' => $relativePath,
                    'url' => $this->buildPublicUrl($relativePath),
                    'preview_url' => $this->buildPreviewUrl($relativePath),
                    'type' => pathinfo($itemPath, PATHINFO_EXTENSION),
                    'size' => filesize($itemPath),
                    'modified' => filemtime($itemPath),
                    'mime_type' => mime_content_type($itemPath),
                    'category' => $category,
                    'tags' => $this->normalizeTags((array) ($metaData['tags'] ?? [])),
                    'license' => is_array($metaData['license'] ?? null) ? $metaData['license'] : [],
                    'uploader_id' => $uploaderId,
                    'uploaded_by' => $uploadedBy,
                    'is_system' => $isSystem,
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Medienverzeichnis konnte nicht vollständig gelesen werden.', [
                'path' => $fullPath,
                'exception' => $e->getMessage(),
            ]);

            return new WP_Error('scan_error', 'Verzeichnis konnte nicht gelesen werden');
        }

        return $items;
    }

    public function ensureCategory(string $name, string $slug): string
    {
        $name = $this->normalizeCategoryName($name);
        $slug = $this->normalizeCategorySlug($slug);

        if ($name === '' || $slug === '') {
            return '';
        }

        if (in_array($slug, array_column($this->loadMeta()['categories'] ?? [], 'slug'), true)) {
            return $slug;
        }

        $this->updateMeta(static function (array $meta) use ($name, $slug): ?array {
            if (in_array($slug, array_column($meta['categories'] ?? [], 'slug'), true)) {
                return null;
            }

            $meta['categories'][] = [
                'name' => $name,
                'slug' => $slug,
                'count' => 0,
                'is_system' => false,
            ];

            return $meta;
        });

        return $slug;
    }

    public function purgeMetaForPath(string $relativePath): void
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        $this->updateMeta(static function (array $meta) use ($relativePath): ?array {
            $changed = false;
            foreach (array_keys($meta['files']) as $path) {
                if ($path === $relativePath || str_starts_with($path, $relativePath . '/')) {
                    unset($meta['files'][$path]);
                    $changed = true;
                }
            }

            return $changed ? $meta : null;
        });
    }

    public function renameMetaPath(string $oldRelativePath, string $newRelativePath): void
    {
        $oldRelativePath = trim(str_replace('\\', '/', $oldRelativePath), '/');
        $newRelativePath = trim(str_replace('\\', '/', $newRelativePath), '/');

        $this->updateMeta(static function (array $meta) use ($oldRelativePath, $newRelativePath): ?array {
            $updated = [];

            foreach ($meta['files'] as $path => $data) {
                if ($path === $oldRelativePath || str_starts_with($path, $oldRelativePath . '/')) {
                    $suffix = ltrim(substr($path, strlen($oldRelativePath)), '/');
                    $newPath = $newRelativePath . ($suffix !== '' ? '/' . $suffix : '');
                    $updated[$newPath] = $data;
                    unset($meta['files'][$path]);
                }
            }

            if ($updated === []) {
                return null;
            }

            $meta['files'] = array_merge($meta['files'], $updated);

            return $meta;
        });
    }

    public function getDiskUsage(): array
    {
        $size = 0;
        $count = 0;

        if (!is_dir($this->uploadPath)) {
            return [
                'size' => 0,
                'count' => 0,
                'formatted' => $this->formatSize(0),
            ];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->uploadPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $size += max(0, (int) $file->getSize());
                $count++;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Medien-Disk-Usage konnte nicht vollständig berechnet werden.', [
                'path' => $this->uploadPath,
                'exception' => $e->getMessage(),
            ]);
        }

        return [
            'size' => $size,
            'count' => $count,
            'formatted' => $this->formatSize($size),
        ];
    }

    public function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = (int)min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function resolvePath(string $path): string|WP_Error
    {
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        if (strpos($path, '..') !== false) {
            return new WP_Error('security_violation', 'Invalid path');
        }

        $fullPath = $this->uploadPath;
        if ($path !== '') {
            $fullPath .= DIRECTORY_SEPARATOR . trim($path, DIRECTORY_SEPARATOR);
        }

        if (strpos($fullPath, $this->uploadPath) !== 0) {
            return new WP_Error('security_violation', 'Path traversal detected');
        }

        return $fullPath;
    }

    /**
     * @param array{categories?: list<array<string, mixed>>} $meta
     * @return array<string, string> fehlende System-Kategorien (Slug => Name)
     */
    private function missingSystemCategories(array $meta): array
    {
        $existingSlugs = array_column($meta['categories'] ?? [], 'slug');

        return array_filter(
            self::SYSTEM_CATEGORIES,
            static fn (string $slug): bool => !in_array($slug, $existingSlugs, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Exklusive Sperre für Schreibzugriffe auf die Metadatei; wartet höchstens META_LOCK_TIMEOUT_MS.
     *
     * @return resource|null
     */
    private function acquireMetaLock()
    {
        $directory = dirname($this->metaFile);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->logger->warning('Media-Metadaten: Verzeichnis für die Sperrdatei fehlt.', ['path' => $directory]);
            return null;
        }

        $lockFile = $this->metaFile . '.lock';
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            $this->logger->warning('Media-Metadaten: Sperrdatei konnte nicht geöffnet werden.', ['path' => $lockFile]);
            return null;
        }

        $waited = 0;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if ($waited >= self::META_LOCK_TIMEOUT_MS) {
                fclose($handle);
                $this->logger->warning('Media-Metadaten: Sperre nicht erhalten, Änderung verworfen.', [
                    'path' => $lockFile,
                    'waited_ms' => $waited,
                ]);
                return null;
            }
            usleep(self::META_LOCK_RETRY_MS * 1000);
            $waited += self::META_LOCK_RETRY_MS;
        }

        return $handle;
    }

    private function emptyMetaState(): array
    {
        return ['categories' => [], 'files' => []];
    }

    private function normalizeMetaState(array $data): array
    {
        $categories = [];
        $categoryLookup = [];

        foreach ((array) ($data['categories'] ?? []) as $category) {
            if (!is_array($category)) {
                continue;
            }

            $slug = $this->normalizeCategorySlug((string) ($category['slug'] ?? ''));
            $name = $this->normalizeCategoryName((string) ($category['name'] ?? ''));

            if ($slug === '' || $name === '') {
                continue;
            }

            if (isset($categoryLookup[$slug])) {
                continue;
            }

            $categoryLookup[$slug] = true;
            $categories[] = [
                'name' => $name,
                'slug' => $slug,
                'count' => max(0, (int) ($category['count'] ?? 0)),
                'is_system' => !empty($category['is_system']),
            ];
        }

        $files = [];
        foreach ((array) ($data['files'] ?? []) as $path => $fileMeta) {
            if (!is_array($fileMeta)) {
                continue;
            }

            $normalizedPath = $this->normalizeRelativePath((string) $path);
            if ($normalizedPath === '') {
                continue;
            }

            $normalizedMeta = [];
            $uploadedAt = trim((string) ($fileMeta['uploaded_at'] ?? ''));
            if ($uploadedAt !== '') {
                $normalizedMeta['uploaded_at'] = $uploadedAt;
            }

            $uploadedBy = trim((string) ($fileMeta['uploaded_by'] ?? ''));
            if ($uploadedBy !== '') {
                $normalizedMeta['uploaded_by'] = function_exists('mb_substr')
                    ? mb_substr($uploadedBy, 0, self::MAX_UPLOADED_BY_LENGTH, 'UTF-8')
                    : substr($uploadedBy, 0, self::MAX_UPLOADED_BY_LENGTH);
            }

            $uploaderId = $fileMeta['uploader_id'] ?? null;
            if ($uploaderId !== null && $uploaderId !== '') {
                $normalizedMeta['uploader_id'] = max(0, (int) $uploaderId);
            }

            $originalName = trim((string) ($fileMeta['original_name'] ?? ''));
            if ($originalName !== '') {
                $normalizedMeta['original_name'] = function_exists('mb_substr')
                    ? mb_substr($originalName, 0, self::MAX_ORIGINAL_NAME_LENGTH, 'UTF-8')
                    : substr($originalName, 0, self::MAX_ORIGINAL_NAME_LENGTH);
            }

            $category = $this->normalizeCategorySlug((string) ($fileMeta['category'] ?? ''));
            if ($category !== '' && isset($categoryLookup[$category])) {
                $normalizedMeta['category'] = $category;
            }

            $tags = $this->normalizeTags((array) ($fileMeta['tags'] ?? []));
            if ($tags !== []) {
                $normalizedMeta['tags'] = $tags;
            }

            $license = MediaLicenseService::normalize($fileMeta[MediaLicenseService::META_KEY] ?? []);
            if ($license !== []) {
                $normalizedMeta[MediaLicenseService::META_KEY] = $license;
            }

            $files[$normalizedPath] = $normalizedMeta;
        }

        return [
            'categories' => array_values($categories),
            'files' => $files,
        ];
    }

    private function normalizeCategorySlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    private function normalizeCategoryName(string $name): string
    {
        $name = trim(strip_tags($name));

        return function_exists('mb_substr')
            ? mb_substr($name, 0, self::CATEGORY_NAME_MAX_LENGTH, 'UTF-8')
            : substr($name, 0, self::CATEGORY_NAME_MAX_LENGTH);
    }

    /**
     * @param array<int, string> $tags
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            $normalizedTag = $this->normalizeTagName((string) $tag);
            if ($normalizedTag === '') {
                continue;
            }

            $normalized[$normalizedTag] = true;

            if (count($normalized) >= self::MAX_TAGS_PER_FILE) {
                break;
            }
        }

        return array_keys($normalized);
    }

    private function normalizeTagName(string $tag): string
    {
        $tag = trim(strip_tags($tag));
        $tag = preg_replace('/[\x00-\x1F\x7F]+/u', '', $tag) ?? '';
        $tag = preg_replace('/\s+/u', ' ', $tag) ?? '';

        return function_exists('mb_substr')
            ? mb_substr($tag, 0, self::TAG_NAME_MAX_LENGTH, 'UTF-8')
            : substr($tag, 0, self::TAG_NAME_MAX_LENGTH);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path) ?? '';
        $path = trim($path, '/');

        if ($path === '' || str_contains($path, '..') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }

        return preg_match('#^[A-Za-z0-9._\-/]+$#', $path) === 1 ? $path : '';
    }

    private function writeFileAtomically(string $path, string $content): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        $tempPath = tempnam($directory, 'cmsmeta_');
        if ($tempPath === false) {
            return false;
        }

        $expectedHash = hash('sha512', $content);

        if (file_put_contents($tempPath, $content, LOCK_EX) === false) {
            @unlink($tempPath);
            return false;
        }

        $tempHash = @hash_file('sha512', $tempPath);
        if ($tempHash === false || !hash_equals($expectedHash, $tempHash)) {
            @unlink($tempPath);
            return false;
        }

        if (@rename($tempPath, $path)) {
            @chmod($path, self::ATOMIC_FILE_MODE);
            $finalHash = @hash_file('sha512', $path);

            return $finalHash !== false && hash_equals($expectedHash, $finalHash);
        }

        $backupPath = null;
        if (is_file($path)) {
            $backupPath = $path . '.swap.' . str_replace('.', '', uniqid('', true));
            if (!@rename($path, $backupPath)) {
                @unlink($tempPath);
                return false;
            }
        }

        if (!@rename($tempPath, $path)) {
            if ($backupPath !== null && is_file($backupPath)) {
                @rename($backupPath, $path);
            }

            @unlink($tempPath);
            return false;
        }

        if ($backupPath !== null && is_file($backupPath)) {
            @unlink($backupPath);
        }

        @chmod($path, self::ATOMIC_FILE_MODE);
        $finalHash = @hash_file('sha512', $path);

        return $finalHash !== false && hash_equals($expectedHash, $finalHash);
    }
}
