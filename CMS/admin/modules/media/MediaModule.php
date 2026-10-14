<?php
declare(strict_types=1);

/**
 * Media Module – Medienverwaltung (Bibliothek, Kategorien, Einstellungen)
 *
 * Nutzt CMS\Services\MediaService für alle Operationen.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Database;
use CMS\Logger;
use CMS\Services\MediaDeliveryService;
use CMS\Services\MediaService;
use CMS\Services\MediaUsageService;
use CMS\Services\ErrorReportService;
use CMS\WP_Error;

if (!function_exists('cms_admin_media_module_load_core_dependencies')) {
    function cms_admin_media_module_load_core_dependencies(): void
    {
        $corePath = defined('CORE_PATH')
            ? rtrim((string) CORE_PATH, '/\\') . DIRECTORY_SEPARATOR
            : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR;

        $dependencies = [
            CMS\Contracts\LoggerInterface::class => $corePath . 'Contracts' . DIRECTORY_SEPARATOR . 'LoggerInterface.php',
            CMS\WP_Error::class => $corePath . 'WP_Error.php',
            CMS\Json::class => $corePath . 'Json.php',
            CMS\Logger::class => $corePath . 'Logger.php',
            CMS\AuditLogger::class => $corePath . 'AuditLogger.php',
            CMS\CacheManager::class => $corePath . 'CacheManager.php',
            CMS\Database::class => $corePath . 'Database.php',
            CMS\Auth::class => $corePath . 'Auth.php',
            MediaDeliveryService::class => $corePath . 'Services' . DIRECTORY_SEPARATOR . 'MediaDeliveryService.php',
            ErrorReportService::class => $corePath . 'Services' . DIRECTORY_SEPARATOR . 'ErrorReportService.php',
            CMS\Services\Media\ImageProcessor::class => $corePath . 'Services' . DIRECTORY_SEPARATOR . 'Media' . DIRECTORY_SEPARATOR . 'ImageProcessor.php',
            CMS\Services\Media\MediaRepository::class => $corePath . 'Services' . DIRECTORY_SEPARATOR . 'Media' . DIRECTORY_SEPARATOR . 'MediaRepository.php',
            CMS\Services\Media\UploadHandler::class => $corePath . 'Services' . DIRECTORY_SEPARATOR . 'Media' . DIRECTORY_SEPARATOR . 'UploadHandler.php',
            MediaUsageService::class => $corePath . 'Services' . DIRECTORY_SEPARATOR . 'MediaUsageService.php',
            MediaService::class => $corePath . 'Services' . DIRECTORY_SEPARATOR . 'MediaService.php',
        ];

        foreach ($dependencies as $className => $filePath) {
            if (class_exists($className, false) || interface_exists($className, false)) {
                continue;
            }

            if (is_file($filePath)) {
                require_once $filePath;
            }
        }

        if (!class_exists(MediaService::class, false) || !class_exists(MediaUsageService::class, false)) {
            throw new RuntimeException('Medien-Services konnten nicht geladen werden. Bitte Core-Dateien und OPcache prüfen.');
        }
    }
}

cms_admin_media_module_load_core_dependencies();

class MediaModule
{
    private const MAX_UPLOAD_FILENAME_LENGTH = 180;
    private const MEMBER_FOLDER_CONFIRM_MESSAGE = 'Der Member-Bereich enthält sensible Uploads. Möchten Sie den Ordner wirklich öffnen?';
    private const MAX_UPLOAD_BATCH_FILES = 20;
    private const MAX_UPLOAD_BATCH_BYTES = 104857600;
    private const MAX_UPLOAD_SIZE_MB = 256;
    private const MIN_UPLOAD_SIZE_MB = 1;
    private const MEDIA_PROCESSING_JOB_BATCH_SIZE = 5;
    private const MEDIA_PROCESSING_JOB_MAX_CANDIDATES = 1000;
    private const MEDIA_PROCESSING_JOB_MAX_FILE_BYTES = 1048576;
    private const SEARCH_MAX_LENGTH = 120;
    private const FILTER_PRESET_NAME_MAX_LENGTH = 60;
    private const FILTER_PRESET_MAX_COUNT = 8;
    private const TAG_NAME_MAX_LENGTH = 40;
    private const MAX_BULK_TAGS = 20;
    private const MAX_ALT_TEXT_LENGTH = 255;
    private const ORPHAN_SCAN_MAX_FILES = 5000;
    private const ORPHAN_LIST_LIMIT = 25;
    private const FOLDER_NAME_MAX_LENGTH = 120;
    private const CATEGORY_NAME_MAX_LENGTH = 80;
    private const CATEGORY_SLUG_MAX_LENGTH = 80;
    private const MOVE_TARGET_MAX_NODES = 1000;

    private const SETTINGS_DEFAULTS = [
        'max_upload_size' => '64M',
        'allowed_types' => ['image', 'document', 'archive', 'video', 'audio'],
        'organize_month_year' => false,
        'sanitize_filenames' => true,
        'unique_filenames' => true,
        'lowercase_filenames' => false,
        'auto_webp' => true,
        'strip_exif' => true,
        'jpeg_quality' => 85,
        'max_width' => 2560,
        'max_height' => 2560,
        'generate_thumbnails' => false,
        'thumb_small_w' => 150,
        'thumb_small_h' => 150,
        'thumb_medium_w' => 300,
        'thumb_medium_h' => 300,
        'thumb_large_w' => 1024,
        'thumb_large_h' => 1024,
        'thumb_banner_w' => 1200,
        'thumb_banner_h' => 400,
        'block_dangerous_types' => true,
        'validate_image_content' => true,
        'require_login_for_upload' => true,
        'protect_uploads_dir' => true,
        'member_uploads_enabled' => false,
        'member_max_upload_size' => '5M',
        'member_allowed_types' => ['image', 'document'],
        'member_delete_own' => false,
    ];

    private MediaService $service;
    private MediaUsageService $usageService;
    private Database $db;
    private ?bool $mediaTableExistsCache = null;
    private string $prefix;
    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $categoriesCache = null;

    private const ALLOWED_VIEWS = ['list', 'grid'];
    private const ALLOWED_TABS = ['library', 'featured', 'check', 'categories', 'settings'];
    private const ALLOWED_USAGE_FILTERS = ['all', 'used', 'unused'];
    private const ALLOWED_FILE_TYPE_FILTERS = ['all', 'image', 'document', 'video', 'audio', 'archive', 'other'];
    private const ALLOWED_SIZE_FILTERS = ['all', 'tiny', 'small', 'medium', 'large', 'huge'];
    private const ALLOWED_MODIFIED_FILTERS = ['all', 'today', '7d', '30d', 'year'];
    private const ALLOWED_ORPHAN_DAYS = [0, 30, 90, 180, 365];
    private const ALLOWED_PROCESSING_MODES = ['all', 'webp', 'thumbnails'];
    private const EXTENSION_FILTER_MAX_LENGTH = 16;
    private const SYSTEM_CATEGORY_SLUGS = ['themes', 'plugins', 'assets', 'fonts', 'dl-manager', 'form-uploads', 'member'];

    /**
     * @return array<string, array<int, string>>
     */
    private function getTypeMap(): array
    {
        return [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico'],
            'video' => ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'],
            'audio' => ['mp3', 'wav', 'aac', 'flac', 'm4a'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'csv'],
            'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
        ];
    }

    /**
     * @param array<int, string> $groups
     * @return array<int, string>
     */
    private function expandTypeGroups(array $groups): array
    {
        $typeMap = $this->getTypeMap();
        $extensions = [];

        foreach ($groups as $group) {
            foreach ($typeMap[$group] ?? [] as $extension) {
                $extensions[$extension] = true;
            }
        }

        return array_keys($extensions);
    }

    /**
     * @param array<int, string> $values
     * @param array<int, string> $allowedExtensions
     * @return array<int, string>
     */
    private function normalizeSettingTypeSelection(array $values, array $allowedExtensions): array
    {
        $typeMap = $this->getTypeMap();
        $allowedLookup = array_fill_keys($allowedExtensions, true);
        $normalized = [];

        foreach ($values as $value) {
            $value = strtolower(trim((string) $value));
            if ($value === '') {
                continue;
            }

            if (isset($allowedLookup[$value])) {
                $normalized[$value] = true;
                continue;
            }

            foreach ($typeMap[$value] ?? [] as $extension) {
                if (isset($allowedLookup[$extension])) {
                    $normalized[$extension] = true;
                }
            }
        }

        return array_keys($normalized);
    }

    /**
     * @param array<int, string> $values
     * @param array<int, string> $allowedExtensions
     * @return array<int, string>
     */
    private function expandStoredTypeSelection(array $values, array $allowedExtensions): array
    {
        return $this->normalizeSettingTypeSelection($values, $allowedExtensions);
    }

    private function convertSizeSettingToMegabytes(mixed $value, int $defaultMegabytes): int
    {
        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '') {
            return $defaultMegabytes;
        }

        if (preg_match('/^(\d+)(?:\.\d+)?\s*M$/i', $normalizedValue, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        if (preg_match('/^\d+$/', $normalizedValue) === 1) {
            return max(1, (int) $normalizedValue);
        }

        return $defaultMegabytes;
    }

    /** @return array<string, mixed> */
    private function buildSettingsOptions(): array
    {
        return [
            'allowed_types' => $this->expandTypeGroups(['image', 'document', 'archive', 'video', 'audio']),
            'member_allowed_types' => $this->expandTypeGroups(['image', 'document']),
            'thumbnail_sizes' => [
                ['label' => 'Small', 'width_field' => 'thumbnail_small_w', 'height_field' => 'thumbnail_small_h'],
                ['label' => 'Medium', 'width_field' => 'thumbnail_medium_w', 'height_field' => 'thumbnail_medium_h'],
                ['label' => 'Large', 'width_field' => 'thumbnail_large_w', 'height_field' => 'thumbnail_large_h'],
                ['label' => 'Banner', 'width_field' => 'thumbnail_banner_w', 'height_field' => 'thumbnail_banner_h'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildSettingsViewModel(array $settings): array
    {
        $settings = array_merge(self::SETTINGS_DEFAULTS, $settings);
        $options = $this->buildSettingsOptions();

        return array_merge($settings, [
            'allowed_types' => $this->expandStoredTypeSelection(
                array_map('strval', (array) ($settings['allowed_types'] ?? [])),
                array_map('strval', (array) ($options['allowed_types'] ?? []))
            ),
            'member_allowed_types' => $this->expandStoredTypeSelection(
                array_map('strval', (array) ($settings['member_allowed_types'] ?? [])),
                array_map('strval', (array) ($options['member_allowed_types'] ?? []))
            ),
            'max_upload_size' => $this->convertSizeSettingToMegabytes($settings['max_upload_size'] ?? null, 64),
            'member_max_upload_size' => $this->convertSizeSettingToMegabytes($settings['member_max_upload_size'] ?? null, 5),
            'sanitize_filename' => (bool) ($settings['sanitize_filenames'] ?? false),
            'unique_filename' => (bool) ($settings['unique_filenames'] ?? false),
            'lowercase_filename' => (bool) ($settings['lowercase_filenames'] ?? false),
            'thumbnail_small_w' => (int) ($settings['thumb_small_w'] ?? 150),
            'thumbnail_small_h' => (int) ($settings['thumb_small_h'] ?? 150),
            'thumbnail_medium_w' => (int) ($settings['thumb_medium_w'] ?? 300),
            'thumbnail_medium_h' => (int) ($settings['thumb_medium_h'] ?? 300),
            'thumbnail_large_w' => (int) ($settings['thumb_large_w'] ?? 1024),
            'thumbnail_large_h' => (int) ($settings['thumb_large_h'] ?? 1024),
            'thumbnail_banner_w' => (int) ($settings['thumb_banner_w'] ?? 1200),
            'thumbnail_banner_h' => (int) ($settings['thumb_banner_h'] ?? 400),
        ]);
    }

    public function __construct()
    {
        $this->service = MediaService::getInstance();
        $this->usageService = MediaUsageService::getInstance();
        $this->db = Database::instance();
        $this->prefix = $this->db->prefix();
    }

    // ─── Bibliothek ──────────────────────────────────────

    /**
     * Daten für die Medien-Bibliothek
     */
    public function getLibraryData(): array
    {
        $path     = $this->normalizeRelativePath((string)($_GET['path'] ?? ''));
        $category = $this->normalizeCategorySlug((string)($_GET['category'] ?? ''));
        $view     = $this->normalizeView((string)($_GET['view'] ?? 'list'));
        $search   = $this->sanitizeSearch((string)($_GET['q'] ?? ''));
        $usageFilter = $this->normalizeUsageFilter((string)($_GET['usage_filter'] ?? 'all'));
        $advancedFilters = [
            'file_type' => $this->normalizeFileTypeFilter((string)($_GET['file_type'] ?? 'all')),
            'extension' => $this->normalizeExtensionFilter((string)($_GET['extension'] ?? '')),
            'size' => $this->normalizeSizeFilter((string)($_GET['size_filter'] ?? 'all')),
            'modified' => $this->normalizeModifiedFilter((string)($_GET['modified_filter'] ?? 'all')),
        ];
        $orphanDays = $this->normalizeOrphanDays($_GET['orphan_days'] ?? 0);
        $confirmMember = (string)($_GET['confirm_member'] ?? '') === '1';

        if ($category !== '' && !$this->categoryExists($category)) {
            $category = '';
        }

        $currentFilterPresetState = $this->normalizeLibraryFilterPresetState([
            'view' => $view,
            'category' => $category,
            'search' => $search,
            'usage_filter' => $usageFilter,
            'file_type' => $advancedFilters['file_type'],
            'extension' => $advancedFilters['extension'],
            'size' => $advancedFilters['size'],
            'modified' => $advancedFilters['modified'],
            'orphan_days' => $orphanDays,
        ]);
        $hasFilterPresetState = $this->hasMeaningfulLibraryPresetState($currentFilterPresetState);
        $filterPresets = $this->buildLibraryFilterPresetViewModels(
            $this->loadLibraryFilterPresets(),
            $currentFilterPresetState
        );

        $items = $this->service->getItems($path);
        if ($items instanceof WP_Error) {
            $this->logWpError('media.library.items_failed', $items, [
                'operation' => 'list_items',
                'path' => $path,
            ]);
            $items = ['folders' => [], 'files' => []];
        }

        // Kategorie-Filter anwenden
        if ($category !== '' && !empty($items['files'])) {
            $items['files'] = array_filter($items['files'], function ($f) use ($category) {
                return ($f['category'] ?? '') === $category;
            });
            $items['files'] = array_values($items['files']);
        }

        if ($search !== '') {
            $items['folders'] = array_values(array_filter($items['folders'] ?? [], static function (array $folder) use ($search): bool {
                $haystack = strtolower((string)($folder['name'] ?? $folder['path'] ?? ''));
                return str_contains($haystack, strtolower($search));
            }));

            $items['files'] = array_values(array_filter($items['files'] ?? [], static function (array $file) use ($search): bool {
                $haystack = strtolower((string)($file['name'] ?? $file['path'] ?? ''));
                return str_contains($haystack, strtolower($search));
            }));
        }

        $extensionOptions = $this->buildExtensionFilterOptions($items['files'] ?? [], $advancedFilters['extension']);
        if ($this->hasActiveAdvancedMediaFilters($advancedFilters)) {
            $items['files'] = $this->applyAdvancedMediaFilters($items['files'] ?? [], $advancedFilters);
        }

        $categories = $this->getCategories();
        $diskUsage  = $this->service->getDiskUsage();
        $stateParams = $this->buildLibraryStateParams($path, $view, $category, $search, $usageFilter, $confirmMember, $advancedFilters, $orphanDays);
        $rootStateParams = $this->buildLibraryStateParams('', $view, $category, $search, $usageFilter, $confirmMember, $advancedFilters, $orphanDays);

        $usageMap = $this->usageService->buildUsageMap(array_map(
            static fn (array $file): string => (string) ($file['path'] ?? ''),
            is_array($items['files'] ?? null) ? $items['files'] : []
        ));

        if ($usageFilter !== 'all' && !empty($items['files'])) {
            $items['files'] = array_values(array_filter($items['files'], function (array $file) use ($usageMap, $usageFilter): bool {
                $filePath = (string) ($file['path'] ?? '');
                $usageCount = count(is_array($usageMap[$filePath] ?? null) ? $usageMap[$filePath] : []);

                return $usageFilter === 'used' ? $usageCount > 0 : $usageCount === 0;
            }));
        }

        $duplicateMap = $this->service->buildDuplicateHashMap(array_map(
            static fn (array $file): string => (string) ($file['path'] ?? ''),
            is_array($items['files'] ?? null) ? $items['files'] : []
        ));
        $stats = $this->buildLibraryStats($items, $categories, $diskUsage, $duplicateMap, $usageMap);
        $orphanMedia = $this->buildOrphanMediaData($orphanDays);
        $altTextBulkAvailable = $this->mediaTableExists();

        return [
            'folders'    => $this->buildFolderViewModels($items['folders'] ?? [], $path, $view, $category, $search, $usageFilter, $confirmMember, $advancedFilters, $orphanDays),
            'files'      => $this->buildFileViewModels($items['files'] ?? [], $path, $usageMap, $duplicateMap),
            'categories' => $categories,
            'diskUsage'  => $diskUsage,
            'path'       => $path,
            'category'   => $category,
            'view'       => $view,
            'search'     => $search,
            'usage_filter' => $usageFilter,
            'file_type_filter' => $advancedFilters['file_type'],
            'extension_filter' => $advancedFilters['extension'],
            'size_filter' => $advancedFilters['size'],
            'modified_filter' => $advancedFilters['modified'],
            'orphan_days' => $orphanDays,
            'confirm_member' => $confirmMember,
            'breadcrumbs' => $this->buildBreadcrumbs($path, $view, $category, $search, $usageFilter, $confirmMember, $advancedFilters, $orphanDays),
            'stats' => $stats,
            'base_url' => $this->buildAdminUrl(),
            'list_url' => $this->buildAdminUrl($this->buildLibraryStateParams($path, 'list', $category, $search, $usageFilter, $confirmMember, $advancedFilters, $orphanDays)),
            'grid_url' => $this->buildAdminUrl($this->buildLibraryStateParams($path, 'grid', $category, $search, $usageFilter, $confirmMember, $advancedFilters, $orphanDays)),
            'root_url' => $this->buildAdminUrl($rootStateParams),
            'reset_filter_url' => $this->buildAdminUrl($this->buildLibraryStateParams($path, $view, '', '', 'all', $confirmMember, [], $orphanDays)),
            'current_filter_permalink' => $this->buildAdminUrl($stateParams),
            'filter_state' => [
                'path' => $path,
                'view' => $view,
                'category' => $category,
                'search' => $search,
                'usage_filter' => $usageFilter,
                'file_type' => $advancedFilters['file_type'],
                'extension' => $advancedFilters['extension'],
                'size' => $advancedFilters['size'],
                'modified' => $advancedFilters['modified'],
            ],
            'filter_presets' => $filterPresets,
            'current_filter_preset_state' => $currentFilterPresetState,
            'has_filter_preset_state' => $hasFilterPresetState,
            'filter_preset_constraints' => [
                'max_presets' => self::FILTER_PRESET_MAX_COUNT,
                'preset_name_max_length' => self::FILTER_PRESET_NAME_MAX_LENGTH,
            ],
            'category_options' => $this->buildCategoryOptions($categories),
            'usage_filter_options' => [
                ['value' => 'all', 'label' => 'Alle Medien'],
                ['value' => 'unused', 'label' => 'Nur ungenutzte'],
                ['value' => 'used', 'label' => 'Nur eingebundene'],
            ],
            'file_type_filter_options' => $this->buildFileTypeFilterOptions(),
            'extension_filter_options' => $extensionOptions,
            'size_filter_options' => $this->buildSizeFilterOptions(),
            'modified_filter_options' => $this->buildModifiedFilterOptions(),
            'orphan_day_options' => $this->buildOrphanDayOptions(),
            'orphan_media' => $orphanMedia,
            'move_targets' => $this->buildMoveTargetOptions(),
            'bulk_actions' => array_values(array_filter([
                ['value' => 'delete', 'label' => 'Auswahl löschen'],
                ['value' => 'move', 'label' => 'Auswahl verschieben'],
                ['value' => 'assign_category', 'label' => 'Kategorie setzen/entfernen'],
                ['value' => 'tag_add', 'label' => 'Tags hinzufügen'],
                ['value' => 'tag_replace', 'label' => 'Tags ersetzen'],
                ['value' => 'tag_remove', 'label' => 'Tags entfernen'],
                ['value' => 'tag_clear', 'label' => 'Alle Tags entfernen'],
                $altTextBulkAvailable ? ['value' => 'alt_text_update', 'label' => 'Alt-Texte aktualisieren'] : null,
            ])),
            'alt_text_bulk_available' => $altTextBulkAvailable,
            'member_folder_confirm_message' => self::MEMBER_FOLDER_CONFIRM_MESSAGE,
            'constraints' => [
                'max_upload_files' => self::MAX_UPLOAD_BATCH_FILES,
                'max_upload_batch_bytes' => self::MAX_UPLOAD_BATCH_BYTES,
                'max_upload_batch_label' => $this->formatBytes(self::MAX_UPLOAD_BATCH_BYTES),
                'search_max_length' => self::SEARCH_MAX_LENGTH,
                'folder_name_max_length' => self::FOLDER_NAME_MAX_LENGTH,
                'tag_name_max_length' => self::TAG_NAME_MAX_LENGTH,
                'max_bulk_tags' => self::MAX_BULK_TAGS,
                'alt_text_max_length' => self::MAX_ALT_TEXT_LENGTH,
            ],
            'empty_state' => [
                'title' => 'Dieser Ordner ist leer',
                'subtitle' => 'Legen Sie einen Ordner an oder laden Sie Dateien hoch.',
            ],
        ];
    }

    /**
     * Daten für die fokussierte Ansicht aller in Beiträgen und Seiten verwendeten Titelbilder.
     */
    public function getFeaturedMediaData(): array
    {
        $search = $this->sanitizeSearch((string) ($_GET['q'] ?? ''));
        $usageScope = $this->normalizeFeaturedUsageScope((string) ($_GET['usage_scope'] ?? 'all'));
        $highlightPath = $this->normalizeRelativePath((string) ($_GET['highlight'] ?? ''));
        $highlightActive = ((string) ($_GET['replaced'] ?? '') === '1') && $highlightPath !== '';
        $featuredUsageMap = $this->usageService->buildFeaturedImageMap();
        $items = [];
        $totalReferences = 0;
        $postReferences = 0;
        $pageReferences = 0;
        $missingFiles = 0;

        foreach ($featuredUsageMap as $relativePath => $usageItems) {
            $normalizedPath = $this->normalizeRelativePath($relativePath);
            if ($normalizedPath === '') {
                continue;
            }

            $usageItems = $this->filterFeaturedUsageItemsByScope($usageItems, $usageScope);
            if ($usageItems === []) {
                continue;
            }

            if ($search !== '' && !$this->featuredMediaMatchesSearch($normalizedPath, $usageItems, $search)) {
                continue;
            }

            $usageCount = count($usageItems);
            $postCount = count(array_filter($usageItems, static fn (array $usageItem): bool => (string) ($usageItem['content_type'] ?? '') === 'post'));
            $pageCount = count(array_filter($usageItems, static fn (array $usageItem): bool => (string) ($usageItem['content_type'] ?? '') === 'page'));
            $exists = $this->service->pathExists($normalizedPath);

            $totalReferences += $usageCount;
            $postReferences += $postCount;
            $pageReferences += $pageCount;
            if (!$exists) {
                $missingFiles++;
            }

            $items[] = [
                'name' => basename($normalizedPath),
                'path' => $normalizedPath,
                'exists' => $exists,
                'preview_url' => $exists ? MediaDeliveryService::getInstance()->buildPreviewUrl($normalizedPath) : '',
                'access_url' => $exists ? MediaDeliveryService::getInstance()->buildAccessUrl($normalizedPath, true) : '',
                'usage_items' => $usageItems,
                'usage_count' => $usageCount,
                'post_count' => $postCount,
                'page_count' => $pageCount,
                'usage_count_label' => $usageCount === 1 ? '1 Verknüpfung' : $usageCount . ' Verknüpfungen',
                'is_highlighted' => $highlightActive && $normalizedPath === $highlightPath,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $usageCompare = (int) ($right['usage_count'] ?? 0) <=> (int) ($left['usage_count'] ?? 0);
            if ($usageCompare !== 0) {
                return $usageCompare;
            }

            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return [
            'items' => $items,
            'search' => $search,
            'usage_scope' => $usageScope,
            'base_url' => '/admin/media',
            'stats' => [
                'image_count' => count($items),
                'reference_count' => $totalReferences,
                'post_reference_count' => $postReferences,
                'page_reference_count' => $pageReferences,
                'missing_count' => $missingFiles,
            ],
            'constraints' => [
                'search_max_length' => self::SEARCH_MAX_LENGTH,
            ],
            'usage_scope_options' => $this->buildFeaturedUsageScopeOptions(),
            'empty_state' => [
                'title' => $search !== '' ? 'Keine passenden Beitrags- oder Seitenbilder gefunden' : 'Noch keine Beitrags- oder Seitenbilder hinterlegt',
                'subtitle' => $search !== ''
                    ? 'Bitte Suchbegriff anpassen oder zurücksetzen.'
                    : 'Sobald Beiträge oder Seiten ein Titelbild haben, erscheinen sie hier gesammelt zur schnellen Pflege.',
            ],
            'help_text' => 'Hier sehen Sie ausschließlich die Bilder, die aktuell als Beitragsbild oder Seitenbild verwendet werden. Beim Ersetzen bleibt die gleiche Medien-Referenz bestehen – alle verknüpften Beiträge und Seiten ziehen also automatisch das neue Bild. Für fehlende oder defekte Zuordnungen steht jetzt der Unterpunkt „Medien Check“ bereit.',
            'highlight_path' => $highlightPath,
            'highlight_active' => $highlightActive,
            'check_url' => $this->buildMediaTabUrl('check', $search, $usageScope),
        ];
    }

    /**
     * Daten für den read-only Medien-Check rund um fehlende oder defekte Featured-Image-Referenzen.
     */
    public function getMediaCheckData(): array
    {
        $search = $this->sanitizeSearch((string) ($_GET['q'] ?? ''));
        $usageScope = $this->normalizeFeaturedUsageScope((string) ($_GET['usage_scope'] ?? 'all'));
        $featuredUsageMap = $this->usageService->buildFeaturedImageMap();
        $consistencyData = $this->buildFeaturedConsistencyData($search, $usageScope, $featuredUsageMap);

        return [
            'search' => $search,
            'usage_scope' => $usageScope,
            'base_url' => '/admin/media',
            'stats' => is_array($consistencyData['stats'] ?? null) ? $consistencyData['stats'] : [],
            'constraints' => [
                'search_max_length' => self::SEARCH_MAX_LENGTH,
            ],
            'usage_scope_options' => $this->buildFeaturedUsageScopeOptions(),
            'help_text' => 'Hier prüfen Sie Beiträge und Seiten ohne Featured Image oder mit defekter Medienreferenz. Der Bereich bleibt bewusst read-only und führt nur in bestehende Editor- und Replace-Flows.',
            'featured_url' => $this->buildMediaTabUrl('featured', $search, $usageScope),
            'consistency' => $consistencyData,
        ];
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function buildFeaturedUsageScopeOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'Beiträge & Seiten'],
            ['value' => 'posts', 'label' => 'Nur Beiträge'],
            ['value' => 'pages', 'label' => 'Nur Seiten'],
        ];
    }

    private function buildMediaTabUrl(string $tab, string $search = '', string $usageScope = 'all'): string
    {
        $params = ['tab' => $this->normalizeTab($tab)];
        $normalizedSearch = $this->sanitizeSearch($search);
        $normalizedUsageScope = $this->normalizeFeaturedUsageScope($usageScope);

        if ($normalizedSearch !== '') {
            $params['q'] = $normalizedSearch;
        }

        if ($normalizedUsageScope !== 'all') {
            $params['usage_scope'] = $normalizedUsageScope;
        }

        return $this->buildAdminUrl($params);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $featuredUsageMap
     * @return array<string, mixed>
     */
    private function buildFeaturedConsistencyData(string $search, string $usageScope, array $featuredUsageMap): array
    {
        $items = [];
        $missingAssignments = 0;
        $brokenReferences = 0;
        $pathExistsCache = [];

        foreach ($this->usageService->buildFeaturedImageContentList() as $contentItem) {
            if (!$this->featuredConsistencyMatchesScope($contentItem, $usageScope)) {
                continue;
            }

            $rawReference = trim((string) ($contentItem['featured_image'] ?? ''));
            $normalizedPath = $this->normalizeRelativePath((string) ($contentItem['featured_image_path'] ?? ''));
            $hasFeaturedImage = !empty($contentItem['has_featured_image']);

            $status = '';
            $statusLabel = '';
            $statusClass = '';
            $statusTextClass = '';
            $referenceDisplay = '';
            $recommendation = '';
            $primaryActionLabel = '';
            $replaceUrl = '';
            $replaceLabel = '';
            $sharedUsageCount = 0;

            if (!$hasFeaturedImage) {
                $status = 'missing';
                $statusLabel = 'Kein Bild hinterlegt';
                $statusClass = 'bg-warning-lt';
                $statusTextClass = 'text-warning';
                $referenceDisplay = 'Keine Referenz gespeichert';
                $recommendation = 'Öffnen Sie den Editor und wählen Sie über den bestehenden Featured-Image-Picker ein Bild direkt aus der Medienbibliothek aus.';
                $primaryActionLabel = 'Im Editor auswählen';
            } else {
                $pathExists = false;

                if ($normalizedPath !== '') {
                    if (!array_key_exists($normalizedPath, $pathExistsCache)) {
                        $pathExistsCache[$normalizedPath] = $this->service->pathExists($normalizedPath);
                    }

                    $pathExists = (bool) $pathExistsCache[$normalizedPath];
                }

                if ($normalizedPath === '' || !$pathExists) {
                    $status = 'broken';
                    $statusLabel = 'Defekte Referenz';
                    $statusClass = 'bg-danger-lt';
                    $statusTextClass = 'text-danger';
                    $referenceDisplay = $normalizedPath !== '' ? $normalizedPath : $rawReference;
                    $primaryActionLabel = 'Im Editor neu wählen';

                    $sharedUsageItems = $normalizedPath !== ''
                        ? $this->filterFeaturedUsageItemsByScope($featuredUsageMap[$normalizedPath] ?? [], $usageScope)
                        : [];
                    $sharedUsageCount = count($sharedUsageItems);

                    $recommendation = $sharedUsageCount > 1
                        ? 'Die defekte Referenz wird von ' . $sharedUsageCount . ' Inhalten geteilt. Sie können sie zentral im Featured-Medien-Tab ersetzen oder pro Inhalt im Editor neu auswählen.'
                        : 'Öffnen Sie den Editor und wählen Sie über den bestehenden Featured-Image-Picker ein neues Bild aus der Medienbibliothek. Bei identischer Referenz können Sie die Datei auch zentral im Featured-Medien-Tab ersetzen.';

                    if ($normalizedPath !== '' && isset($featuredUsageMap[$normalizedPath])) {
                        $replaceQuery = http_build_query([
                            'tab' => 'featured',
                            'q' => $normalizedPath,
                            'usage_scope' => $usageScope,
                        ]);
                        $replaceUrl = '/admin/media?' . $replaceQuery . '#featured-replacements';
                        $replaceLabel = 'Zentral ersetzen';
                    }
                }
            }

            if ($status === '') {
                continue;
            }

            if ($search !== '' && !$this->featuredConsistencyMatchesSearch($contentItem, $statusLabel, $referenceDisplay, $search)) {
                continue;
            }

            if ($status === 'missing') {
                $missingAssignments++;
            } elseif ($status === 'broken') {
                $brokenReferences++;
            }

            $items[] = [
                'content_type' => (string) ($contentItem['content_type'] ?? ''),
                'content_type_label' => (string) ($contentItem['content_type_label'] ?? 'Inhalt'),
                'title' => (string) ($contentItem['title'] ?? 'Ohne Titel'),
                'edit_url' => (string) ($contentItem['edit_url'] ?? '#'),
                'status' => $status,
                'status_label' => $statusLabel,
                'status_class' => $statusClass,
                'status_text_class' => $statusTextClass,
                'reference_display' => $referenceDisplay,
                'recommendation' => $recommendation,
                'primary_action_label' => $primaryActionLabel,
                'replace_url' => $replaceUrl,
                'replace_label' => $replaceLabel,
                'shared_usage_count' => $sharedUsageCount,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $statusRank = ['broken' => 0, 'missing' => 1];
            $leftRank = $statusRank[(string) ($left['status'] ?? '')] ?? 99;
            $rightRank = $statusRank[(string) ($right['status'] ?? '')] ?? 99;

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            $typeCompare = strcasecmp((string) ($left['content_type_label'] ?? ''), (string) ($right['content_type_label'] ?? ''));
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        return [
            'items' => $items,
            'stats' => [
                'issue_count' => count($items),
                'missing_assignment_count' => $missingAssignments,
                'broken_reference_count' => $brokenReferences,
            ],
            'empty_state' => [
                'title' => $search !== ''
                    ? 'Keine offenen Featured-Image-Probleme zum Filter gefunden'
                    : 'Keine offenen Featured-Image-Probleme gefunden',
                'subtitle' => $search !== ''
                    ? 'Bitte Suchbegriff anpassen oder zurücksetzen.'
                    : 'Alle aktuell gefilterten Beiträge und Seiten besitzen eine funktionierende Featured-Image-Referenz.',
            ],
            'help_text' => 'Die Liste bleibt read-only: Sie zeigt Inhalte ohne Bild oder mit defekter Referenz und führt direkt in den bestehenden Editor-Pfad mit Medienbibliothek bzw. in den zentralen Ersetzen-Flow.',
        ];
    }

    public function requiresMemberConfirmation(string $path): bool
    {
        $normalizedPath = $this->normalizeRelativePath($path);

        return $normalizedPath !== ''
            && ($normalizedPath === 'member' || str_starts_with($normalizedPath, 'member/'));
    }

    /**
     * Ordner erstellen
     */
    public function createFolder(string $name, string $parentPath): array
    {
        $normalizedParentPath = $this->normalizeRelativePath($parentPath);
        $sanitizedName = $this->sanitizeFolderName($name);
        if ($sanitizedName === '') {
            return ['success' => false, 'error' => 'Ordnername ist ungültig.'];
        }

        $result = $this->service->createFolder($sanitizedName, $normalizedParentPath);
        if ($result instanceof WP_Error) {
            return $this->buildGenericFailureFromWpError($result, [
                'title' => 'Ordner konnte nicht erstellt werden',
                'source' => '/admin/media',
                'module' => 'media',
                'operation' => 'create_folder',
                'path' => $normalizedParentPath,
                'folder_name' => $sanitizedName,
            ]);
        }
        return [
            'success' => true,
            'message' => 'Ordner erstellt.',
            'details' => [
                'Ordner: ' . $sanitizedName,
                'Zielpfad: ' . ($normalizedParentPath !== '' ? $normalizedParentPath : '/'),
            ],
        ];
    }

    /**
     * Datei hochladen
     */
    public function uploadFile(array $file, string $targetPath): array
    {
        $normalizedTargetPath = $this->normalizeRelativePath($targetPath);
        $normalizedFile = $this->normalizeUploadFile($file);

        if ($normalizedFile === null) {
            return ['success' => false, 'error' => 'Upload-Datei ist ungültig.'];
        }

        if ($normalizedTargetPath !== '' && !$this->directoryExists($normalizedTargetPath)) {
            return ['success' => false, 'error' => 'Der Zielordner für den Upload existiert nicht mehr.'];
        }

        $result = $this->service->uploadManagedFile($normalizedFile, $normalizedTargetPath);
        if ($result instanceof WP_Error) {
            return $this->buildGenericFailureFromWpError($result, [
                'title' => 'Datei konnte nicht hochgeladen werden',
                'source' => '/admin/media',
                'module' => 'media',
                'operation' => 'upload',
                'path' => $normalizedTargetPath,
                'filename' => (string)($normalizedFile['name'] ?? ''),
            ]);
        }

        $storedPath = trim((string) ($result['path'] ?? ''), '/');
        $storedParentPath = $storedPath !== '' ? trim(str_replace('\\', '/', dirname($storedPath)), '/.') : '';

        return [
            'success' => true,
            'message' => 'Datei hochgeladen.',
            'details' => [
                'Datei: ' . (string)($normalizedFile['name'] ?? 'Unbekannt'),
                'Zielpfad: ' . ($storedParentPath !== '' ? $storedParentPath : '/'),
            ],
            'stored_path' => $storedPath,
            'stored_parent_path' => $storedParentPath,
        ];
    }

    /**
     * Bereits eingebundene Bilddatei durch eine neue Datei ersetzen.
     */
    public function replaceItem(string $path): array
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        if ($normalizedPath === '') {
            return ['success' => false, 'error' => 'Ungültiger Bildpfad.'];
        }

        $featuredUsageMap = $this->usageService->buildFeaturedImageMap();
        if (!array_key_exists($normalizedPath, $featuredUsageMap)) {
            return ['success' => false, 'error' => 'Dieses Bild ist aktuell nicht als Beitrags- oder Seitenbild registriert. Bitte die Medienansicht neu laden.'];
        }

        $replacementFile = is_array($_FILES['replacement_file'] ?? null) ? $_FILES['replacement_file'] : null;
        if ($replacementFile === null || (int) ($replacementFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['success' => false, 'error' => 'Bitte eine neue Bilddatei auswählen.'];
        }

        return $this->replaceFeaturedItemWithFile($normalizedPath, $replacementFile, $featuredUsageMap);
    }

    /**
     * Mehrere Beitrags-/Seitenbilder in einem Request ersetzen.
     *
     * @param list<string> $paths
     * @param list<array{name:string,type:string,tmp_name:string,error:int,size:int}> $replacementFiles
     */
    public function replaceItems(array $paths, array $replacementFiles): array
    {
        if ($paths === [] || $replacementFiles === []) {
            return ['success' => false, 'error' => 'Bitte mindestens eine neue Bilddatei auswählen.'];
        }

        $featuredUsageMap = $this->usageService->buildFeaturedImageMap();
        $successCount = 0;
        $details = [];
        $errorDetails = [];
        $highlightPath = '';
        $seenPaths = [];
        $maxPairs = max(count($paths), count($replacementFiles));

        for ($index = 0; $index < $maxPairs; $index++) {
            $normalizedPath = $this->normalizeRelativePath((string) ($paths[$index] ?? ''));
            $replacementFile = is_array($replacementFiles[$index] ?? null) ? $replacementFiles[$index] : null;

            if ($normalizedPath === '') {
                $errorDetails[] = 'Ein Bildpfad im Mehrfach-Request ist ungültig.';
                continue;
            }

            if (isset($seenPaths[$normalizedPath])) {
                $errorDetails[] = basename($normalizedPath) . ': Diese Referenz wurde im Request doppelt übermittelt und übersprungen.';
                continue;
            }
            $seenPaths[$normalizedPath] = true;

            if ($replacementFile === null || (int) ($replacementFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $errorDetails[] = basename($normalizedPath) . ': Keine Ersatzdatei übermittelt.';
                continue;
            }

            $result = $this->replaceFeaturedItemWithFile($normalizedPath, $replacementFile, $featuredUsageMap);
            if (!empty($result['success'])) {
                $successCount++;
                $highlightPath = $highlightPath === '' ? $normalizedPath : $highlightPath;
                foreach ((array) ($result['details'] ?? []) as $detail) {
                    $details[] = (string) $detail;
                }
                continue;
            }

            $errorDetails[] = basename($normalizedPath) . ': ' . trim((string) ($result['error'] ?? 'Bild konnte nicht ersetzt werden.'));
        }

        if ($successCount === 0) {
            return [
                'success' => false,
                'error' => 'Keines der vorbereiteten Bilder konnte ersetzt werden.',
                'details' => [],
                'error_details' => array_slice($errorDetails, 0, 8),
            ];
        }

        return [
            'success' => true,
            'message' => $successCount === 1 ? '1 Bild ersetzt.' : $successCount . ' Bilder ersetzt.',
            'highlight_path' => $highlightPath,
            'details' => array_slice($details, 0, 8),
            'error_details' => array_slice($errorDetails, 0, 8),
        ];
    }

    /**
     * @param array<string,mixed> $replacementFile
     * @param array<string, list<array<string, mixed>>> $featuredUsageMap
     */
    private function replaceFeaturedItemWithFile(string $normalizedPath, array $replacementFile, array $featuredUsageMap): array
    {
        if (!array_key_exists($normalizedPath, $featuredUsageMap)) {
            return ['success' => false, 'error' => 'Dieses Bild ist aktuell nicht als Beitrags- oder Seitenbild registriert. Bitte die Medienansicht neu laden.'];
        }

        $result = $this->service->replaceManagedFile($replacementFile, $normalizedPath);
        if ($result instanceof WP_Error) {
            return $this->buildGenericFailureFromWpError($result, [
                'title' => 'Bild konnte nicht ersetzt werden',
                'source' => '/admin/media?tab=featured',
                'module' => 'media',
                'operation' => 'replace_item',
                'path' => $normalizedPath,
            ]);
        }

        $usageItems = $featuredUsageMap[$normalizedPath] ?? [];
        $usageCount = count($usageItems);

        return [
            'success' => true,
            'message' => 'Bild ersetzt.',
            'highlight_path' => $normalizedPath,
            'details' => [
                'Datei: ' . basename($normalizedPath),
                $usageCount > 0
                    ? 'Aktualisiert in ' . $usageCount . ' Beitrags-/Seiten-Verknüpfung' . ($usageCount === 1 ? '' : 'en') . '.'
                    : 'Die bestehende Medien-Referenz wurde beibehalten.',
            ],
        ];
    }

    /**
     * Datei/Ordner löschen
     */
    public function deleteItem(string $path): array
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        if ($normalizedPath === '') {
            return ['success' => false, 'error' => 'Elementpfad ist ungültig.'];
        }

        if ($this->isProtectedPath($normalizedPath)) {
            return ['success' => false, 'error' => 'Geschützte Systemordner können nicht gelöscht werden.'];
        }

        if (!$this->hasItem($normalizedPath)) {
            return ['success' => false, 'error' => 'Das gewählte Element existiert nicht mehr.'];
        }

        $result = $this->service->deleteItem($normalizedPath);
        if ($result instanceof WP_Error) {
            return $this->buildGenericFailureFromWpError($result, [
                'title' => 'Element konnte nicht gelöscht werden',
                'source' => '/admin/media',
                'module' => 'media',
                'operation' => 'delete',
                'path' => $normalizedPath,
            ]);
        }

        $this->deleteMediaTableEntriesForPath($normalizedPath);

        return [
            'success' => true,
            'message' => 'Element gelöscht.',
            'details' => ['Pfad: ' . $normalizedPath],
        ];
    }

    /**
     * Datei/Ordner umbenennen
     */
    public function renameItem(string $oldPath, string $newName): array
    {
        $normalizedPath = $this->normalizeRelativePath($oldPath);
        $sanitizedName = $this->sanitizeItemName($newName);
        if ($normalizedPath === '' || $sanitizedName === '') {
            return ['success' => false, 'error' => 'Umbenennen mit diesen Angaben ist nicht möglich.'];
        }

        if ($this->isProtectedPath($normalizedPath)) {
            return ['success' => false, 'error' => 'Geschützte Systemordner können nicht umbenannt werden.'];
        }

        if (!$this->hasItem($normalizedPath)) {
            return ['success' => false, 'error' => 'Das gewählte Element existiert nicht mehr.'];
        }

        $parentPath = $this->resolveParentPathFromActionPath($normalizedPath);
        $targetPath = ltrim(($parentPath !== '' ? $parentPath . '/' : '') . $sanitizedName, '/');

        $result = $this->service->renameItem($normalizedPath, $sanitizedName);
        if ($result instanceof WP_Error) {
            return $this->buildGenericFailureFromWpError($result, [
                'title' => 'Element konnte nicht umbenannt werden',
                'source' => '/admin/media',
                'module' => 'media',
                'operation' => 'rename',
                'path' => $normalizedPath,
                'new_name' => $sanitizedName,
            ]);
        }

        if ($targetPath !== '' && $targetPath !== $normalizedPath) {
            $this->renameMediaTablePaths($normalizedPath, $targetPath);
        }

        return [
            'success' => true,
            'message' => 'Element umbenannt.',
            'details' => [
                'Pfad: ' . $normalizedPath,
                'Neuer Name: ' . $sanitizedName,
            ],
        ];
    }

    /**
     * Datei/Ordner verschieben
     */
    public function moveItem(string $oldPath, string $targetParentPath): array
    {
        $normalizedPath = $this->normalizeRelativePath($oldPath);
        $normalizedTargetParentPath = $this->normalizeRelativePath($targetParentPath);

        if ($normalizedPath === '') {
            return ['success' => false, 'error' => 'Verschieben mit diesen Angaben ist nicht möglich.'];
        }

        if ($this->isProtectedPath($normalizedPath)) {
            return ['success' => false, 'error' => 'Geschützte Systemordner können nicht verschoben werden.'];
        }

        if (!$this->hasItem($normalizedPath)) {
            return ['success' => false, 'error' => 'Das gewählte Element existiert nicht mehr.'];
        }

        if ($normalizedTargetParentPath !== '' && !$this->directoryExists($normalizedTargetParentPath)) {
            return ['success' => false, 'error' => 'Der gewählte Zielordner existiert nicht mehr.'];
        }

        $currentParentPath = $this->resolveParentPathFromActionPath($normalizedPath);
        if ($normalizedTargetParentPath === $currentParentPath) {
            return [
                'success' => true,
                'message' => 'Element befindet sich bereits in diesem Ordner.',
                'details' => [
                    'Pfad: ' . $normalizedPath,
                    'Ordner: ' . ($normalizedTargetParentPath !== '' ? $normalizedTargetParentPath : '/'),
                ],
            ];
        }

        if ($normalizedTargetParentPath === $normalizedPath || str_starts_with($normalizedTargetParentPath, $normalizedPath . '/')) {
            return ['success' => false, 'error' => 'Ein Ordner kann nicht in sich selbst oder einen Unterordner verschoben werden.'];
        }

        $targetPath = ltrim(($normalizedTargetParentPath !== '' ? $normalizedTargetParentPath . '/' : '') . basename($normalizedPath), '/');
        if ($targetPath === $normalizedPath) {
            return [
                'success' => true,
                'message' => 'Element befindet sich bereits am gewünschten Ziel.',
                'details' => ['Pfad: ' . $normalizedPath],
            ];
        }

        $result = $this->service->moveFile($normalizedPath, $targetPath);
        if ($result instanceof WP_Error) {
            return $this->buildGenericFailureFromWpError($result, [
                'title' => 'Element konnte nicht verschoben werden',
                'source' => '/admin/media',
                'module' => 'media',
                'operation' => 'move',
                'path' => $normalizedPath,
                'target_parent_path' => $normalizedTargetParentPath,
                'target_path' => $targetPath,
            ]);
        }

        if ($targetPath !== $normalizedPath) {
            $this->renameMediaTablePaths($normalizedPath, $targetPath);
        }

        return [
            'success' => true,
            'message' => 'Element verschoben.',
            'details' => [
                'Quelle: ' . $normalizedPath,
                'Ziel: ' . $targetPath,
            ],
        ];
    }

    /**
     * @param array<int, string> $paths
     */
    public function bulkItems(array $paths, string $bulkAction, string $targetParentPath = '', string $categorySlug = '', mixed $tagList = '', mixed $altTextMap = []): array
    {
        $normalizedBulkAction = strtolower(trim($bulkAction));
        if (!in_array($normalizedBulkAction, ['delete', 'move', 'assign_category', 'tag_add', 'tag_replace', 'tag_remove', 'tag_clear', 'alt_text_update'], true)) {
            return ['success' => false, 'error' => 'Die gewählte Bulk-Aktion ist nicht erlaubt.'];
        }

        $selectedPaths = $this->normalizeBulkPaths($paths);
        if ($selectedPaths === []) {
            return ['success' => false, 'error' => 'Für die Bulk-Aktion wurden keine gültigen Elemente übermittelt.'];
        }

        $normalizedTargetParentPath = $this->normalizeRelativePath($targetParentPath);
        $normalizedCategory = $this->normalizeCategorySlug($categorySlug);
        $normalizedTags = $this->normalizeTags($tagList);
        $normalizedAltTexts = $this->normalizeAltTextMap($altTextMap);

        if ($normalizedBulkAction === 'assign_category' && $normalizedCategory !== '' && !$this->categoryExists($normalizedCategory)) {
            return ['success' => false, 'error' => 'Die gewählte Kategorie existiert nicht mehr.'];
        }

        if (in_array($normalizedBulkAction, ['tag_add', 'tag_replace', 'tag_remove'], true) && $normalizedTags === []) {
            return ['success' => false, 'error' => 'Bitte mindestens einen gültigen Tag angeben.'];
        }

        if ($normalizedBulkAction === 'alt_text_update') {
            if (!$this->mediaTableExists()) {
                return ['success' => false, 'error' => 'Die Alt-Text-Verwaltung ist auf dieser Installation derzeit nicht verfügbar.'];
            }

            if ($normalizedAltTexts === []) {
                return ['success' => false, 'error' => 'Bitte mindestens einen Alt-Text-Wert übermitteln.'];
            }
        }

        $missingPaths = $this->collectMissingPaths($selectedPaths);
        if ($missingPaths !== []) {
            $this->auditBulkMediaAction($normalizedBulkAction, count($selectedPaths), 0, 0, count($missingPaths), ['status' => 'failed_stale_selection']);

            return [
                'success' => false,
                'error' => 'Die Auswahl enthält veraltete oder bereits gelöschte Medien. Bitte die Liste aktualisieren.',
                'details' => array_map(static fn (string $path): string => 'Fehlend: ' . $path, array_slice($missingPaths, 0, 8)),
            ];
        }

        $protectedPaths = $this->collectProtectedPaths($selectedPaths);
        if (in_array($normalizedBulkAction, ['delete', 'move'], true) && $protectedPaths !== []) {
            $this->auditBulkMediaAction($normalizedBulkAction, count($selectedPaths), 0, 0, count($protectedPaths), ['status' => 'failed_protected_selection']);

            return [
                'success' => false,
                'error' => 'Geschützte Systemordner dürfen nicht per Bulk-Aktion verändert werden.',
                'details' => array_map(static fn (string $path): string => 'Geschützt: ' . $path, array_slice($protectedPaths, 0, 8)),
            ];
        }

        if ($normalizedBulkAction === 'move' && $normalizedTargetParentPath !== '' && !$this->directoryExists($normalizedTargetParentPath)) {
            return ['success' => false, 'error' => 'Der gewählte Zielordner existiert nicht mehr.'];
        }

        $successCount = 0;
        $skippedCount = 0;
        $details = [];
        $errorDetails = [];
        $reportPayload = [];

        foreach ($selectedPaths as $selectedPath) {
            $actionResult = null;

            if ($normalizedBulkAction === 'move') {
                $actionResult = $this->executeBulkMove($selectedPath, $normalizedTargetParentPath);
            } elseif ($normalizedBulkAction === 'delete') {
                $deleteResult = $this->deleteItem($selectedPath);
                $actionResult = !empty($deleteResult['success'])
                    ? ['status' => 'success', 'detail' => 'Gelöscht: ' . $selectedPath]
                    : [
                        'status' => 'error',
                        'detail' => $selectedPath . ': ' . trim((string) ($deleteResult['error'] ?? 'Fehler')),
                        'report_payload' => is_array($deleteResult['report_payload'] ?? null) ? $deleteResult['report_payload'] : [],
                    ];
            } elseif ($normalizedBulkAction === 'assign_category') {
                $actionResult = $this->executeBulkCategoryAssignment($selectedPath, $normalizedCategory);
            } elseif ($normalizedBulkAction === 'alt_text_update') {
                $actionResult = $this->executeBulkAltTextUpdate($selectedPath, $normalizedAltTexts);
            } elseif (str_starts_with($normalizedBulkAction, 'tag_')) {
                $actionResult = $this->executeBulkTagAssignment($selectedPath, $normalizedBulkAction, $normalizedTags);
            }

            if (!is_array($actionResult)) {
                $errorDetails[] = $selectedPath . ': Unbekannte Bulk-Aktion.';
                continue;
            }

            if (($actionResult['status'] ?? '') === 'success') {
                $successCount++;
                $details[] = (string) ($actionResult['detail'] ?? ('Bearbeitet: ' . $selectedPath));
                continue;
            }

            if (($actionResult['status'] ?? '') === 'skipped') {
                $skippedCount++;
                $details[] = (string) ($actionResult['detail'] ?? ('Übersprungen: ' . $selectedPath));
                continue;
            }

            $errorDetails[] = (string) ($actionResult['detail'] ?? ('Fehlgeschlagen: ' . $selectedPath));
            if ($reportPayload === [] && is_array($actionResult['report_payload'] ?? null)) {
                $reportPayload = $actionResult['report_payload'];
            }
        }

        $verb = match ($normalizedBulkAction) {
            'move' => 'verschoben',
            'delete' => 'gelöscht',
            'assign_category' => $normalizedCategory !== '' ? 'kategorisiert' : 'von Kategorien befreit',
            'tag_add' => 'mit Tags ergänzt',
            'tag_replace' => 'mit Tags ersetzt',
            'tag_remove' => 'von Tags bereinigt',
            'tag_clear' => 'von allen Tags bereinigt',
            'alt_text_update' => 'mit Alt-Texten aktualisiert',
            default => 'bearbeitet',
        };

        $this->auditBulkMediaAction($normalizedBulkAction, count($selectedPaths), $successCount, $skippedCount, count($errorDetails), [
            'target_parent_path' => $normalizedBulkAction === 'move' ? $normalizedTargetParentPath : null,
            'category' => $normalizedBulkAction === 'assign_category' && $normalizedCategory !== '' ? $normalizedCategory : null,
            'tag_count' => str_starts_with($normalizedBulkAction, 'tag_') ? count($normalizedTags) : null,
            'alt_text_count' => $normalizedBulkAction === 'alt_text_update' ? count($normalizedAltTexts) : null,
        ]);

        $summaryParts = [];
        if ($successCount > 0) {
            $summaryParts[] = $successCount . ' Element(e) ' . $verb;
        }
        if ($skippedCount > 0) {
            $summaryParts[] = $skippedCount . ' übersprungen';
        }
        if ($errorDetails !== []) {
            $summaryParts[] = count($errorDetails) . ' fehlgeschlagen';
        }

        if ($summaryParts === []) {
            return [
                'success' => false,
                'error' => 'Die Bulk-Aktion konnte nicht ausgeführt werden.',
                'details' => [],
                'error_details' => array_slice($errorDetails, 0, 8),
                'report_payload' => $reportPayload,
            ];
        }

        if ($successCount === 0 && $skippedCount === 0) {
            return [
                'success' => false,
                'error' => 'Die Bulk-Aktion ist vollständig fehlgeschlagen. ' . implode(', ', $summaryParts) . '.',
                'details' => [],
                'error_details' => array_slice($errorDetails, 0, 8),
                'report_payload' => $reportPayload,
            ];
        }

        return [
            'success' => true,
            'message' => 'Bulk-Aktion abgeschlossen: ' . implode(', ', $summaryParts) . '.',
            'details' => array_slice($details, 0, 8),
            'error_details' => array_slice($errorDetails, 0, 8),
            'report_payload' => $reportPayload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeBulkCategoryAssignment(string $selectedPath, string $categorySlug): array
    {
        if (!$this->isFileItem($selectedPath)) {
            return ['status' => 'skipped', 'detail' => $selectedPath . ': Kategorien werden nur auf Dateien angewendet.'];
        }

        $result = $this->assignCategory($selectedPath, $categorySlug);
        if (empty($result['success'])) {
            return [
                'status' => 'error',
                'detail' => $selectedPath . ': ' . trim((string) ($result['error'] ?? 'Fehler')),
                'report_payload' => is_array($result['report_payload'] ?? null) ? $result['report_payload'] : [],
            ];
        }

        return ['status' => 'success', 'detail' => 'Kategorie gesetzt: ' . $selectedPath];
    }

    /**
     * @param array<int, string> $tags
     * @return array<string, mixed>
     */
    private function executeBulkTagAssignment(string $selectedPath, string $bulkAction, array $tags): array
    {
        if (!$this->isFileItem($selectedPath)) {
            return ['status' => 'skipped', 'detail' => $selectedPath . ': Tags werden nur auf Dateien angewendet.'];
        }

        $mode = match ($bulkAction) {
            'tag_add' => 'add',
            'tag_replace' => 'replace',
            'tag_remove' => 'remove',
            'tag_clear' => 'clear',
            default => '',
        };

        if ($mode === '') {
            return ['status' => 'error', 'detail' => $selectedPath . ': Ungültige Tag-Aktion.'];
        }

        $result = $this->service->assignTags($selectedPath, $tags, $mode);
        if ($result instanceof WP_Error) {
            $failure = $this->buildGenericFailureFromWpError($result, [
                'title' => 'Bulk-Tagging fehlgeschlagen',
                'source' => '/admin/media',
                'module' => 'media',
                'operation' => 'bulk_' . $bulkAction,
                'path' => $selectedPath,
                'tag_count' => count($tags),
            ]);

            return [
                'status' => 'error',
                'detail' => $selectedPath . ': ' . trim((string) ($failure['error'] ?? 'Fehler')),
                'report_payload' => $failure['report_payload'] ?? [],
            ];
        }

        return ['status' => 'success', 'detail' => 'Tags aktualisiert: ' . $selectedPath];
    }

    /**
     * @param array<string, string> $altTexts
     * @return array<string, mixed>
     */
    private function executeBulkAltTextUpdate(string $selectedPath, array $altTexts): array
    {
        if (!$this->isFileItem($selectedPath)) {
            return ['status' => 'skipped', 'detail' => $selectedPath . ': Alt-Texte werden nur auf Dateien angewendet.'];
        }

        if (!array_key_exists($selectedPath, $altTexts)) {
            return ['status' => 'skipped', 'detail' => $selectedPath . ': Kein Alt-Text-Feld übermittelt.'];
        }

        $altText = $this->normalizeAltText($altTexts[$selectedPath]);
        if (!$this->upsertMediaAltText($selectedPath, $altText)) {
            return [
                'status' => 'error',
                'detail' => $selectedPath . ': Alt-Text konnte nicht gespeichert werden.',
                'report_payload' => [
                    'title' => 'Bulk-Alt-Text fehlgeschlagen',
                    'source' => '/admin/media',
                    'status' => 'warning',
                    'context' => [
                        'module' => 'media',
                        'operation' => 'bulk_alt_text_update',
                        'path' => $selectedPath,
                    ],
                ],
            ];
        }

        return [
            'status' => 'success',
            'detail' => ($altText === '' ? 'Alt-Text geleert: ' : 'Alt-Text aktualisiert: ') . $selectedPath,
        ];
    }

    /**
     * Kategorie zuweisen
     */
    public function assignCategory(string $filePath, string $categorySlug): array
    {
        $normalizedPath = $this->normalizeRelativePath($filePath);
        $normalizedCategory = $this->normalizeCategorySlug($categorySlug);

        if ($normalizedPath === '') {
            return ['success' => false, 'error' => 'Dateipfad ist ungültig.'];
        }

        if ($normalizedCategory !== '' && !$this->categoryExists($normalizedCategory)) {
            return ['success' => false, 'error' => 'Kategorie existiert nicht.'];
        }

        $result = $this->service->assignCategory($normalizedPath, $normalizedCategory);
        if ($result instanceof WP_Error) {
            return $this->buildGenericFailureFromWpError($result, [
                'title' => 'Kategorie konnte nicht zugewiesen werden',
                'source' => '/admin/media/categories',
                'module' => 'media',
                'operation' => 'assign_category',
                'path' => $normalizedPath,
                'category' => $normalizedCategory,
            ]);
        }
        return [
            'success' => true,
            'message' => 'Kategorie zugewiesen.',
            'details' => [
                'Datei: ' . $normalizedPath,
                'Kategorie: ' . ($normalizedCategory !== '' ? $normalizedCategory : 'Ohne Kategorie'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function saveFilterPreset(string $label, array $state): array
    {
        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            return ['success' => false, 'error' => 'Filter-Presets können ohne gültigen Benutzer nicht gespeichert werden.'];
        }

        $normalizedLabel = $this->normalizeFilterPresetLabel($label);
        if ($normalizedLabel === '') {
            return ['success' => false, 'error' => 'Bitte einen Namen für das Filter-Preset angeben.'];
        }

        $normalizedState = $this->normalizeLibraryFilterPresetState($state);
        if (!$this->hasMeaningfulLibraryPresetState($normalizedState)) {
            return ['success' => false, 'error' => 'Bitte zuerst mindestens einen aktiven Such- oder Filterwert setzen.'];
        }

        $existingPresets = $this->loadLibraryFilterPresets();
        $baseSlug = $this->normalizeFilterPresetSlug($normalizedLabel);
        $matchedPreset = null;
        $remainingPresets = [];

        foreach ($existingPresets as $preset) {
            $presetSlug = (string) ($preset['slug'] ?? '');
            $presetState = is_array($preset['state'] ?? null) ? $preset['state'] : [];

            if ($matchedPreset === null && ($this->libraryFilterPresetStatesMatch($presetState, $normalizedState) || ($baseSlug !== '' && $presetSlug === $baseSlug))) {
                $matchedPreset = $preset;
                continue;
            }

            $remainingPresets[] = $preset;
        }

        if ($matchedPreset === null && count($remainingPresets) >= self::FILTER_PRESET_MAX_COUNT) {
            return [
                'success' => false,
                'error' => 'Es können maximal ' . self::FILTER_PRESET_MAX_COUNT . ' Filter-Presets pro Admin gespeichert werden.',
            ];
        }

        $existingSlugs = array_map(static fn (array $preset): string => (string) ($preset['slug'] ?? ''), $remainingPresets);
        $resolvedSlug = $matchedPreset !== null
            ? (string) ($matchedPreset['slug'] ?? '')
            : $this->generateUniqueLibraryFilterPresetSlug($normalizedLabel, $existingSlugs);
        $timestamp = date('c');
        $presetPayload = [
            'slug' => $resolvedSlug,
            'label' => $normalizedLabel,
            'state' => $normalizedState,
            'created_at' => (string) ($matchedPreset['created_at'] ?? $timestamp),
            'updated_at' => $timestamp,
        ];

        array_unshift($remainingPresets, $presetPayload);
        $remainingPresets = array_slice(array_values($remainingPresets), 0, self::FILTER_PRESET_MAX_COUNT);

        if (!$this->persistLibraryFilterPresets($userId, $remainingPresets)) {
            return ['success' => false, 'error' => 'Das Filter-Preset konnte nicht gespeichert werden.'];
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_MEDIA,
            'media.filter_preset.save',
            'Filter-Preset gespeichert',
            'media',
            $userId,
            [
                'preset_slug' => $resolvedSlug,
                'preset_label' => $normalizedLabel,
                'state' => $normalizedState,
            ],
            'info'
        );

        return [
            'success' => true,
            'message' => $matchedPreset !== null ? 'Filter-Preset aktualisiert.' : 'Filter-Preset gespeichert.',
            'details' => [
                'Preset: ' . $normalizedLabel,
                'Aktive Filter: ' . $this->describeLibraryFilterPresetState($normalizedState),
            ],
        ];
    }

    public function deleteFilterPreset(string $slug): array
    {
        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            return ['success' => false, 'error' => 'Filter-Presets können ohne gültigen Benutzer nicht gelöscht werden.'];
        }

        $normalizedSlug = $this->normalizeFilterPresetSlug($slug);
        if ($normalizedSlug === '') {
            return ['success' => false, 'error' => 'Ungültiges Filter-Preset.'];
        }

        $presets = $this->loadLibraryFilterPresets();
        $remainingPresets = [];
        $deletedPreset = null;

        foreach ($presets as $preset) {
            if ($deletedPreset === null && (string) ($preset['slug'] ?? '') === $normalizedSlug) {
                $deletedPreset = $preset;
                continue;
            }

            $remainingPresets[] = $preset;
        }

        if ($deletedPreset === null) {
            return ['success' => true, 'message' => 'Das Filter-Preset war bereits entfernt.'];
        }

        if (!$this->persistLibraryFilterPresets($userId, $remainingPresets)) {
            return ['success' => false, 'error' => 'Das Filter-Preset konnte nicht gelöscht werden.'];
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_MEDIA,
            'media.filter_preset.delete',
            'Filter-Preset gelöscht',
            'media',
            $userId,
            [
                'preset_slug' => $normalizedSlug,
                'preset_label' => (string) ($deletedPreset['label'] ?? $normalizedSlug),
            ],
            'info'
        );

        return [
            'success' => true,
            'message' => 'Filter-Preset gelöscht.',
            'details' => ['Preset: ' . (string) ($deletedPreset['label'] ?? $normalizedSlug)],
        ];
    }

    // ─── Kategorien ──────────────────────────────────────

    /**
     * Kategorien-Übersicht
     */
    public function getCategoriesData(): array
    {
        return [
            'categories' => $this->getCategories(),
            'system_slugs' => self::SYSTEM_CATEGORY_SLUGS,
            'constraints' => [
                'category_name_max_length' => self::CATEGORY_NAME_MAX_LENGTH,
                'category_slug_max_length' => self::CATEGORY_SLUG_MAX_LENGTH,
            ],
        ];
    }

    /**
     * Kategorie hinzufügen
     */
    public function addCategory(string $name, string $slug = ''): array
    {
        $sanitizedName = $this->sanitizeCategoryName($name);
        $normalizedSlug = $this->normalizeCategorySlug($slug !== '' ? $slug : $sanitizedName);

        if ($sanitizedName === '' || $normalizedSlug === '' || in_array($normalizedSlug, self::SYSTEM_CATEGORY_SLUGS, true)) {
            return ['success' => false, 'error' => 'Kategorie konnte mit diesen Angaben nicht erstellt werden.'];
        }

        $result = $this->service->addCategory($sanitizedName, $normalizedSlug);
        if ($result instanceof WP_Error) {
            return $this->buildGenericFailureFromWpError($result, [
                'title' => 'Kategorie konnte nicht erstellt werden',
                'source' => '/admin/media/categories',
                'module' => 'media',
                'operation' => 'add_category',
                'category' => $normalizedSlug,
            ]);
        }

        $this->resetCategoriesCache();

        return [
            'success' => true,
            'message' => 'Kategorie erstellt.',
            'details' => [
                'Name: ' . $sanitizedName,
                'Slug: ' . $normalizedSlug,
            ],
        ];
    }

    /**
     * Kategorie löschen
     */
    public function deleteCategory(string $slug): array
    {
        $normalizedSlug = $this->normalizeCategorySlug($slug);
        if ($normalizedSlug === '' || in_array($normalizedSlug, self::SYSTEM_CATEGORY_SLUGS, true)) {
            return ['success' => false, 'error' => 'Kategorie kann nicht gelöscht werden.'];
        }

        if (!$this->categoryExists($normalizedSlug)) {
            return ['success' => false, 'error' => 'Die gewählte Kategorie existiert nicht mehr.'];
        }

        $result = $this->service->deleteCategory($normalizedSlug);
        if ($result instanceof WP_Error) {
            return $this->buildGenericFailureFromWpError($result, [
                'title' => 'Kategorie konnte nicht gelöscht werden',
                'source' => '/admin/media/categories',
                'module' => 'media',
                'operation' => 'delete_category',
                'category' => $normalizedSlug,
            ]);
        }

        $this->resetCategoriesCache();

        return [
            'success' => true,
            'message' => 'Kategorie gelöscht.',
            'details' => ['Slug: ' . $normalizedSlug],
        ];
    }

    // ─── Einstellungen ───────────────────────────────────

    /**
     * Aktuelle Einstellungen laden
     */
    public function getSettingsData(): array
    {
        $settings = $this->service->getSettings();

        return [
            'settings'  => $this->buildSettingsViewModel($settings),
            'diskUsage' => $this->service->getDiskUsage(),
            'options' => $this->buildSettingsOptions(),
            'processing_job' => $this->getMediaProcessingJobViewData(),
            'constraints' => [
                'min_upload_size_mb' => self::MIN_UPLOAD_SIZE_MB,
                'max_upload_size_mb' => self::MAX_UPLOAD_SIZE_MB,
                'jpeg_quality_min' => 60,
                'jpeg_quality_max' => 100,
                'dimension_min' => 1,
                'dimension_max' => 8000,
                'thumbnail_min' => 50,
                'thumbnail_max' => 6000,
                'processing_batch_size' => self::MEDIA_PROCESSING_JOB_BATCH_SIZE,
                'processing_max_candidates' => self::MEDIA_PROCESSING_JOB_MAX_CANDIDATES,
            ],
        ];
    }

    public function normalizeProcessingMode(string $mode): string
    {
        $normalizedMode = strtolower(trim($mode));

        return in_array($normalizedMode, self::ALLOWED_PROCESSING_MODES, true) ? $normalizedMode : 'all';
    }

    public function startMediaProcessingJob(string $mode): array
    {
        $mode = $this->normalizeProcessingMode($mode);
        $paths = $this->service->collectImageDerivativeProcessingCandidates(self::MEDIA_PROCESSING_JOB_MAX_CANDIDATES);

        if ($paths === []) {
            $this->clearMediaProcessingJob();

            return [
                'success' => true,
                'message' => 'Kein Medienjob gestartet: Es wurden keine geeigneten Bilddateien gefunden.',
                'details' => ['Es werden nur JPG, PNG, GIF, WebP und BMP verarbeitet; bereits erzeugte Thumbnail-Derivate werden übersprungen.'],
            ];
        }

        $now = date('c');
        $job = [
            'id' => 'media-derivatives-' . date('Ymd-His'),
            'status' => 'queued',
            'mode' => $mode,
            'created_at' => $now,
            'updated_at' => $now,
            'total' => count($paths),
            'cursor' => 0,
            'processed' => 0,
            'succeeded' => 0,
            'skipped' => 0,
            'failed' => 0,
            'batch_size' => self::MEDIA_PROCESSING_JOB_BATCH_SIZE,
            'paths' => $paths,
            'last_details' => [],
            'last_errors' => [],
        ];

        if (!$this->saveMediaProcessingJob($job)) {
            return [
                'success' => false,
                'error' => 'Der Medienjob konnte nicht gespeichert werden.',
                'details' => ['Bitte Schreibrechte für das CMS-Konfigurationsverzeichnis prüfen.'],
            ];
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_MEDIA,
            'media.derivative_job.started',
            'Medien-Derivat-Job gestartet',
            'media',
            null,
            ['mode' => $mode, 'total' => count($paths)],
            'info'
        );

        return [
            'success' => true,
            'message' => 'Medienjob vorbereitet. Starte die Verarbeitung in kleinen Schritten, um Timeouts zu vermeiden.',
            'details' => [
                count($paths) . ' Bilddatei(en) in die Warteschlange gelegt.',
                'Batchgröße: ' . self::MEDIA_PROCESSING_JOB_BATCH_SIZE . ' Datei(en) pro Schritt.',
            ],
        ];
    }

    public function processMediaProcessingJob(): array
    {
        $job = $this->loadMediaProcessingJob();
        if ($job === []) {
            return [
                'success' => false,
                'error' => 'Es ist kein Medienjob vorbereitet.',
                'details' => ['Bitte zuerst einen neuen WebP-/Thumbnail-Job starten.'],
            ];
        }

        $status = (string) ($job['status'] ?? 'queued');
        if (in_array($status, ['completed', 'cancelled'], true)) {
            return [
                'success' => true,
                'message' => $status === 'completed' ? 'Der Medienjob ist bereits abgeschlossen.' : 'Der Medienjob wurde abgebrochen.',
                'details' => $this->buildMediaProcessingJobSummaryDetails($job),
            ];
        }

        $paths = array_values(array_filter(array_map('strval', (array) ($job['paths'] ?? []))));
        $total = count($paths);
        $cursor = max(0, min($total, (int) ($job['cursor'] ?? 0)));
        $batchSize = max(1, min(20, (int) ($job['batch_size'] ?? self::MEDIA_PROCESSING_JOB_BATCH_SIZE)));
        $mode = $this->normalizeProcessingMode((string) ($job['mode'] ?? 'all'));
        $generateWebp = in_array($mode, ['all', 'webp'], true);
        $generateThumbnails = in_array($mode, ['all', 'thumbnails'], true);
        $batch = array_slice($paths, $cursor, $batchSize);
        $details = [];
        $errors = [];

        foreach ($batch as $path) {
            try {
                $result = $this->service->processImageDerivativeJobItem($path, $generateWebp, $generateThumbnails);
            } catch (\Throwable $exception) {
                $result = [
                    'path' => $path,
                    'status' => 'failed',
                    'detail' => 'Unerwarteter Fehler bei der Bildverarbeitung. Details wurden protokolliert.',
                    'webp' => 'failed',
                    'thumbnails' => 'failed',
                ];

                Logger::instance()->withChannel('admin.media')->warning('Medien-Derivat-Job-Schritt fehlgeschlagen.', [
                    'path' => $path,
                    'exception_class' => $exception::class,
                ]);
            }

            $job['processed'] = (int) ($job['processed'] ?? 0) + 1;
            $cursor++;
            $resultPath = (string) ($result['path'] ?? $path);
            $resultDetail = trim((string) ($result['detail'] ?? ''));
            $resultStatus = (string) ($result['status'] ?? 'failed');

            if ($resultStatus === 'processed') {
                $job['succeeded'] = (int) ($job['succeeded'] ?? 0) + 1;
                $details[] = 'Erzeugt: ' . $resultPath;
            } elseif ($resultStatus === 'skipped') {
                $job['skipped'] = (int) ($job['skipped'] ?? 0) + 1;
                $details[] = 'Übersprungen: ' . $resultPath . ($resultDetail !== '' ? ' (' . $resultDetail . ')' : '');
            } else {
                $job['failed'] = (int) ($job['failed'] ?? 0) + 1;
                $errors[] = $resultPath . ($resultDetail !== '' ? ': ' . $resultDetail : ': Verarbeitung fehlgeschlagen');
            }
        }

        $job['cursor'] = $cursor;
        $job['updated_at'] = date('c');
        $job['status'] = $cursor >= $total ? 'completed' : 'running';
        $job['last_details'] = array_slice($details, 0, 8);
        $job['last_errors'] = array_slice($errors, 0, 8);

        if (!$this->saveMediaProcessingJob($job)) {
            return [
                'success' => false,
                'error' => 'Der Medienjob-Fortschritt konnte nicht gespeichert werden.',
                'details' => ['Die Verarbeitung wurde gestoppt, damit kein inkonsistenter Fortschritt angezeigt wird.'],
                'error_details' => array_slice($errors, 0, 8),
            ];
        }

        return [
            'success' => true,
            'message' => $job['status'] === 'completed'
                ? 'Medienjob abgeschlossen.'
                : 'Medienjob-Schritt verarbeitet. Weitere Dateien stehen noch aus.',
            'details' => array_merge($this->buildMediaProcessingJobSummaryDetails($job), array_slice($details, 0, 4)),
            'error_details' => array_slice($errors, 0, 8),
        ];
    }

    public function cancelMediaProcessingJob(): array
    {
        $job = $this->loadMediaProcessingJob();
        if ($job === []) {
            return [
                'success' => true,
                'message' => 'Es gibt keinen aktiven Medienjob.',
            ];
        }

        $job['status'] = 'cancelled';
        $job['updated_at'] = date('c');
        $this->saveMediaProcessingJob($job);

        AuditLogger::instance()->log(
            AuditLogger::CAT_MEDIA,
            'media.derivative_job.cancelled',
            'Medien-Derivat-Job abgebrochen',
            'media',
            null,
            ['id' => (string) ($job['id'] ?? ''), 'processed' => (int) ($job['processed'] ?? 0)],
            'info'
        );

        return [
            'success' => true,
            'message' => 'Medienjob abgebrochen.',
            'details' => $this->buildMediaProcessingJobSummaryDetails($job),
        ];
    }

    /**
     * Einstellungen speichern
     */
    public function saveSettings(array $input): array
    {
        $settings = $this->service->getSettings();
        $originalSettings = $settings;
        $options = $this->buildSettingsOptions();
        $allTypes = array_map('strval', (array) ($options['allowed_types'] ?? []));
        $memberTypes = array_map('strval', (array) ($options['member_allowed_types'] ?? []));

        // Size fields: form sends a plain number (MB); append 'M' if no unit suffix
        foreach (['max_upload_size', 'member_max_upload_size'] as $key) {
            if (isset($input[$key])) {
                $val = trim((string)$input[$key]);
                if (preg_match('/^\d+(?:\.\d+)?$/', $val)) {
                    $val .= 'M';
                }
                if (preg_match('/^(\d+)(?:\.\d+)?M$/i', $val, $matches) === 1) {
                    $mb = max(1, min(256, (int)$matches[1]));
                    $val = $mb . 'M';
                }
                $settings[$key] = $val;
            }
        }

        // Integers
        foreach ([
            'jpeg_quality' => 'jpeg_quality',
            'max_width' => 'max_width',
            'max_height' => 'max_height',
            'thumbnail_small_w' => 'thumb_small_w',
            'thumbnail_small_h' => 'thumb_small_h',
            'thumbnail_medium_w' => 'thumb_medium_w',
            'thumbnail_medium_h' => 'thumb_medium_h',
            'thumbnail_large_w' => 'thumb_large_w',
            'thumbnail_large_h' => 'thumb_large_h',
            'thumbnail_banner_w' => 'thumb_banner_w',
            'thumbnail_banner_h' => 'thumb_banner_h',
        ] as $inputKey => $settingsKey) {
            if (isset($input[$inputKey])) {
                $value = (int)$input[$inputKey];
                $settings[$settingsKey] = match ($settingsKey) {
                    'jpeg_quality' => max(60, min(100, $value)),
                    'max_width', 'max_height' => max(1, min(8000, $value)),
                    'thumb_large_w', 'thumb_large_h', 'thumb_banner_w', 'thumb_banner_h' => max(50, min(6000, $value)),
                    default => max(50, min(4000, $value)),
                };
            }
        }

        // Booleans
        foreach ([
            'auto_webp' => 'auto_webp',
            'strip_exif' => 'strip_exif',
            'organize_month_year' => 'organize_month_year',
            'sanitize_filename' => 'sanitize_filenames',
            'unique_filename' => 'unique_filenames',
            'lowercase_filename' => 'lowercase_filenames',
            'member_uploads_enabled' => 'member_uploads_enabled',
            'member_delete_own' => 'member_delete_own',
            'generate_thumbnails' => 'generate_thumbnails',
            'block_dangerous_types' => 'block_dangerous_types',
            'validate_image_content' => 'validate_image_content',
            'protect_uploads_dir' => 'protect_uploads_dir',
        ] as $inputKey => $settingsKey) {
            $settings[$settingsKey] = isset($input[$inputKey]);
        }

        // Interne Upload-Endpunkte bleiben bewusst authentifiziert; die Einstellung
        // wird fail-closed auf true gehalten, statt einen nicht existierenden
        // anonymen Upload-Modus im UI vorzutäuschen.
        $settings['require_login_for_upload'] = true;

        // Arrays
        $settings['allowed_types'] = $this->normalizeSettingTypeSelection(
            array_values(array_unique(array_map('strval', (array)($input['allowed_types'] ?? $this->expandTypeGroups(['image']))))),
            $allTypes
        );
        if ($settings['allowed_types'] === []) {
            $settings['allowed_types'] = $this->expandTypeGroups(['image']);
        }

        $settings['member_allowed_types'] = $this->normalizeSettingTypeSelection(
            array_values(array_unique(array_map('strval', (array)($input['member_allowed_types'] ?? $this->expandTypeGroups(['image']))))),
            $memberTypes
        );
        if ($settings['member_allowed_types'] === []) {
            $settings['member_allowed_types'] = array_values(array_intersect(
                $this->expandTypeGroups(['image']),
                $memberTypes
            ));
        }

        $result = $this->service->saveSettings($settings);
        if ($result instanceof WP_Error) {
            return $this->buildGenericFailureFromWpError($result, [
                'title' => 'Medien-Einstellungen konnten nicht gespeichert werden',
                'source' => '/admin/media/settings',
                'module' => 'media',
                'operation' => 'save_settings',
                'changed_fields' => $this->collectChangedSettingKeys($originalSettings, $settings),
            ]);
        }
        return [
            'success' => true,
            'message' => 'Einstellungen gespeichert.',
            'details' => [
                $this->buildSettingsChangeSummary($originalSettings, $settings),
            ],
        ];
    }

    public function normalizeTab(string $tab): string
    {
        $tab = strtolower(trim($tab));

        return in_array($tab, self::ALLOWED_TABS, true) ? $tab : 'library';
    }

    public function normalizePath(string $path): string
    {
        return $this->normalizeRelativePath($path);
    }

    public function resolveParentPathFromActionPath(string $path): string
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        if ($normalizedPath === '') {
            return '';
        }

        $parentPath = dirname($normalizedPath);

        if ($parentPath === '.' || $parentPath === '/' || $parentPath === '\\') {
            return '';
        }

        return $this->normalizeRelativePath($parentPath);
    }

    public function normalizeView(string $view): string
    {
        $view = strtolower(trim($view));

        return in_array($view, self::ALLOWED_VIEWS, true) ? $view : 'list';
    }

    public function normalizeUsageFilter(string $usageFilter): string
    {
        $usageFilter = strtolower(trim($usageFilter));

        return in_array($usageFilter, self::ALLOWED_USAGE_FILTERS, true) ? $usageFilter : 'all';
    }

    public function normalizeFileTypeFilter(string $fileTypeFilter): string
    {
        $fileTypeFilter = strtolower(trim($fileTypeFilter));

        return in_array($fileTypeFilter, self::ALLOWED_FILE_TYPE_FILTERS, true) ? $fileTypeFilter : 'all';
    }

    public function normalizeSizeFilter(string $sizeFilter): string
    {
        $sizeFilter = strtolower(trim($sizeFilter));

        return in_array($sizeFilter, self::ALLOWED_SIZE_FILTERS, true) ? $sizeFilter : 'all';
    }

    public function normalizeModifiedFilter(string $modifiedFilter): string
    {
        $modifiedFilter = strtolower(trim($modifiedFilter));

        return in_array($modifiedFilter, self::ALLOWED_MODIFIED_FILTERS, true) ? $modifiedFilter : 'all';
    }

    public function normalizeOrphanDays(mixed $days): int
    {
        $normalizedDays = is_numeric($days) ? (int) $days : 0;

        return in_array($normalizedDays, self::ALLOWED_ORPHAN_DAYS, true) ? $normalizedDays : 0;
    }

    public function normalizeCategory(string $slug): string
    {
        return $this->normalizeCategorySlug($slug);
    }

    public function normalizeSearch(string $search): string
    {
        return $this->sanitizeSearch($search);
    }

    public function normalizeExtensionFilter(string $extension): string
    {
        $extension = strtolower(trim(strip_tags($extension)));
        $extension = ltrim($extension, '.');
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?? '';

        return function_exists('mb_substr') ? mb_substr($extension, 0, self::EXTENSION_FILTER_MAX_LENGTH) : substr($extension, 0, self::EXTENSION_FILTER_MAX_LENGTH);
    }

    public function normalizeFilterPresetLabel(string $label): string
    {
        $label = trim(strip_tags($label));

        return function_exists('mb_substr') ? mb_substr($label, 0, self::FILTER_PRESET_NAME_MAX_LENGTH) : substr($label, 0, self::FILTER_PRESET_NAME_MAX_LENGTH);
    }

    public function normalizeFilterPresetSlug(string $slug): string
    {
        $slug = strtolower(trim(strip_tags($slug)));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        $slug = function_exists('mb_substr') ? mb_substr($slug, 0, self::FILTER_PRESET_NAME_MAX_LENGTH) : substr($slug, 0, self::FILTER_PRESET_NAME_MAX_LENGTH);

        return trim($slug, '-');
    }

    /**
     * @return list<string>
     */
    public function normalizeTags(mixed $tags): array
    {
        $rawTags = is_array($tags)
            ? $tags
            : preg_split('/[,;\n]+/u', (string) $tags);

        if (!is_array($rawTags)) {
            return [];
        }

        $normalized = [];

        foreach ($rawTags as $tag) {
            $normalizedTag = trim(strip_tags((string) $tag));
            $normalizedTag = preg_replace('/[\x00-\x1F\x7F]+/u', '', $normalizedTag) ?? '';
            $normalizedTag = preg_replace('/\s+/u', ' ', $normalizedTag) ?? '';

            if ($normalizedTag === '') {
                continue;
            }

            $normalizedTag = function_exists('mb_substr')
                ? mb_substr($normalizedTag, 0, self::TAG_NAME_MAX_LENGTH)
                : substr($normalizedTag, 0, self::TAG_NAME_MAX_LENGTH);

            $normalized[$normalizedTag] = true;

            if (count($normalized) >= self::MAX_BULK_TAGS) {
                break;
            }
        }

        return array_keys($normalized);
    }

    public function getMemberFolderConfirmMessage(): string
    {
        return self::MEMBER_FOLDER_CONFIRM_MESSAGE;
    }

    public function hasItem(string $path): bool
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        if ($normalizedPath === '') {
            return false;
        }

        return $this->service->pathExists($normalizedPath);
    }

    public function directoryExists(string $path): bool
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        if ($normalizedPath === '') {
            return trim($path) === '' && $this->service->directoryExists('');
        }

        return $this->service->directoryExists($normalizedPath);
    }

    private function isFileItem(string $path): bool
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        if ($normalizedPath === '') {
            return false;
        }

        return $this->service->pathExists($normalizedPath) && !$this->service->directoryExists($normalizedPath);
    }

    private function isProtectedPath(string $path): bool
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        if ($normalizedPath === '') {
            return false;
        }

        return $this->service->isProtectedPath($normalizedPath);
    }

    /**
     * @param array<int, string> $paths
     * @return list<string>
     */
    private function collectMissingPaths(array $paths): array
    {
        $missing = [];

        foreach ($paths as $path) {
            if (!$this->hasItem($path)) {
                $missing[] = $path;
            }
        }

        return $missing;
    }

    /**
     * @param array<int, string> $paths
     * @return list<string>
     */
    private function collectProtectedPaths(array $paths): array
    {
        $protected = [];

        foreach ($paths as $path) {
            if ($this->isProtectedPath($path)) {
                $protected[] = $path;
            }
        }

        return $protected;
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

    private function normalizeCategorySlug(string $slug): string
    {
        $slug = strtolower(trim(strip_tags($slug)));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    private function sanitizeCategoryName(string $name): string
    {
        $name = trim(strip_tags($name));

        return function_exists('mb_substr') ? mb_substr($name, 0, self::CATEGORY_NAME_MAX_LENGTH) : substr($name, 0, self::CATEGORY_NAME_MAX_LENGTH);
    }

    private function sanitizeFolderName(string $name): string
    {
        $name = trim(strip_tags($name));
        if ($name === '' || preg_match('#[\\/:*?"<>|]#', $name) === 1) {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($name, 0, self::FOLDER_NAME_MAX_LENGTH) : substr($name, 0, self::FOLDER_NAME_MAX_LENGTH);
    }

    private function sanitizeItemName(string $name): string
    {
        return $this->sanitizeFolderName($name);
    }

    private function sanitizeSearch(string $search): string
    {
        $search = trim(strip_tags($search));

        return function_exists('mb_substr') ? mb_substr($search, 0, self::SEARCH_MAX_LENGTH) : substr($search, 0, self::SEARCH_MAX_LENGTH);
    }

    /**
     * @param array<int, string> $paths
     * @return list<string>
     */
    private function normalizeBulkPaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $normalizedPath = $this->normalizeRelativePath((string) $path);
            if ($normalizedPath === '') {
                continue;
            }

            $normalized[$normalizedPath] = true;
        }

        $sortedPaths = array_keys($normalized);
        usort($sortedPaths, static function (string $left, string $right): int {
            $lengthCompare = strlen($left) <=> strlen($right);
            return $lengthCompare !== 0 ? $lengthCompare : strcmp($left, $right);
        });

        $filteredPaths = [];

        foreach ($sortedPaths as $path) {
            $hasAncestor = false;

            foreach ($filteredPaths as $keptPath) {
                if (str_starts_with($path, $keptPath . '/')) {
                    $hasAncestor = true;
                    break;
                }
            }

            if ($hasAncestor) {
                continue;
            }

            $filteredPaths[] = $path;
        }

        return $filteredPaths;
    }

    /**
     * @return array<string, mixed>
     */
    private function executeBulkMove(string $selectedPath, string $targetParentPath): array
    {
        if ($this->isProtectedPath($selectedPath)) {
            return [
                'status' => 'error',
                'detail' => $selectedPath . ': Geschützte Systemordner können nicht verschoben werden.',
            ];
        }

        if (!$this->hasItem($selectedPath)) {
            return [
                'status' => 'error',
                'detail' => $selectedPath . ': Das Element existiert nicht mehr.',
            ];
        }

        if ($targetParentPath !== '' && !$this->directoryExists($targetParentPath)) {
            return [
                'status' => 'error',
                'detail' => $selectedPath . ': Der Zielordner existiert nicht mehr.',
            ];
        }

        $currentParentPath = $this->resolveParentPathFromActionPath($selectedPath);

        if ($targetParentPath === $currentParentPath) {
            return [
                'status' => 'skipped',
                'detail' => $selectedPath . ' befindet sich bereits im Zielordner.',
            ];
        }

        if ($targetParentPath === $selectedPath || str_starts_with($targetParentPath, $selectedPath . '/')) {
            return [
                'status' => 'error',
                'detail' => $selectedPath . ': Ein Ordner kann nicht in sich selbst oder einen Unterordner verschoben werden.',
            ];
        }

        $targetPath = ltrim(($targetParentPath !== '' ? $targetParentPath . '/' : '') . basename($selectedPath), '/');
        if ($targetPath === $selectedPath) {
            return [
                'status' => 'skipped',
                'detail' => $selectedPath . ' ist bereits am gewünschten Ziel.',
            ];
        }

        $result = $this->service->moveFile($selectedPath, $targetPath);
        if ($result instanceof WP_Error) {
            $failure = $this->buildGenericFailureFromWpError($result, [
                'title' => 'Bulk-Verschieben fehlgeschlagen',
                'source' => '/admin/media',
                'module' => 'media',
                'operation' => 'bulk_move',
                'path' => $selectedPath,
                'target_parent_path' => $targetParentPath,
                'target_path' => $targetPath,
            ]);

            return [
                'status' => 'error',
                'detail' => $selectedPath . ': ' . trim((string) ($failure['error'] ?? 'Fehler')), 
                'report_payload' => $failure['report_payload'] ?? [],
            ];
        }

        return [
            'status' => 'success',
            'detail' => 'Verschoben: ' . $selectedPath . ' → ' . $targetPath,
        ];
    }

    /**
     * @return list<array{path:string,label:string,depth:int}>
     */
    private function buildMoveTargetOptions(): array
    {
        $options = [[
            'path' => '',
            'label' => 'Uploads',
            'depth' => 0,
        ]];

        $visited = ['' => true];
        $this->appendMoveTargetOptions($options, '', 0, $visited);

        return $options;
    }

    /**
     * @param array<int, array{path:string,label:string,depth:int}> $options
     * @param array<string, bool> $visited
     */
    private function appendMoveTargetOptions(array &$options, string $path, int $depth, array &$visited): void
    {
        if (count($visited) >= self::MOVE_TARGET_MAX_NODES) {
            return;
        }

        $items = $this->service->getItems($path);
        if ($items instanceof WP_Error) {
            return;
        }

        $folders = is_array($items['folders'] ?? null) ? $items['folders'] : [];
        usort($folders, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        foreach ($folders as $folder) {
            $folderPath = $this->normalizeRelativePath((string) ($folder['path'] ?? ''));
            if ($folderPath === '' || isset($visited[$folderPath])) {
                continue;
            }

            $visited[$folderPath] = true;
            $options[] = [
                'path' => $folderPath,
                'label' => str_repeat('— ', min($depth + 1, 8)) . (string) ($folder['name'] ?? $folderPath),
                'depth' => $depth + 1,
            ];

            $this->appendMoveTargetOptions($options, $folderPath, $depth + 1, $visited);
        }
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $updated
     * @return list<string>
     */
    private function collectChangedSettingKeys(array $original, array $updated): array
    {
        $changed = [];

        foreach ($updated as $key => $value) {
            if (($original[$key] ?? null) !== $value) {
                $changed[] = (string) $key;
            }
        }

        return $changed === [] ? ['keine expliziten Wertänderungen erkannt'] : $changed;
    }

    private function buildSettingsChangeSummary(array $original, array $updated): string
    {
        $changedKeys = $this->collectChangedSettingKeys($original, $updated);

        if ($changedKeys === ['keine expliziten Wertänderungen erkannt']) {
            return 'Geänderte Felder: keine expliziten Wertänderungen erkannt – bestehende Einstellungen wurden unverändert bestätigt.';
        }

        return 'Geänderte Felder (' . count($changedKeys) . '): ' . implode(', ', $changedKeys);
    }

    private function categoryExists(string $slug): bool
    {
        foreach ($this->getCategories() as $category) {
            if (($category['slug'] ?? '') === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCategories(): array
    {
        if ($this->categoriesCache !== null) {
            return $this->categoriesCache;
        }

        $categories = $this->service->getCategories();
        $this->categoriesCache = is_array($categories) ? $categories : [];

        return $this->categoriesCache;
    }

    private function resetCategoriesCache(): void
    {
        $this->categoriesCache = null;
    }

    private function normalizeUploadFile(array $file): ?array
    {
        $requiredKeys = ['name', 'type', 'tmp_name', 'error', 'size'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $file)) {
                return null;
            }
        }

        $name = trim((string)$file['name']);
        $tmpName = (string)$file['tmp_name'];
        if ($name === '' || $tmpName === '') {
            return null;
        }

        $name = function_exists('mb_substr')
            ? mb_substr($name, 0, self::MAX_UPLOAD_FILENAME_LENGTH)
            : substr($name, 0, self::MAX_UPLOAD_FILENAME_LENGTH);

        if (!is_uploaded_file($tmpName)) {
            return null;
        }

        return [
            'name' => $name,
            'type' => (string)$file['type'],
            'tmp_name' => $tmpName,
            'error' => (int)$file['error'],
            'size' => max(0, (int)$file['size']),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildLibraryStateParams(string $path, string $view, string $category, string $search, string $usageFilter, bool $confirmMember, array $advancedFilters = [], int $orphanDays = 0): array
    {
        $params = [];

        if ($path !== '') {
            $params['path'] = $path;
        }

        if ($view !== 'list') {
            $params['view'] = $view;
        }

        if ($category !== '') {
            $params['category'] = $category;
        }

        if ($search !== '') {
            $params['q'] = $search;
        }

        if ($usageFilter !== 'all') {
            $params['usage_filter'] = $usageFilter;
        }

        $fileTypeFilter = $this->normalizeFileTypeFilter((string)($advancedFilters['file_type'] ?? 'all'));
        if ($fileTypeFilter !== 'all') {
            $params['file_type'] = $fileTypeFilter;
        }

        $extensionFilter = $this->normalizeExtensionFilter((string)($advancedFilters['extension'] ?? ''));
        if ($extensionFilter !== '') {
            $params['extension'] = $extensionFilter;
        }

        $sizeFilter = $this->normalizeSizeFilter((string)($advancedFilters['size'] ?? 'all'));
        if ($sizeFilter !== 'all') {
            $params['size_filter'] = $sizeFilter;
        }

        $modifiedFilter = $this->normalizeModifiedFilter((string)($advancedFilters['modified'] ?? 'all'));
        if ($modifiedFilter !== 'all') {
            $params['modified_filter'] = $modifiedFilter;
        }

        $normalizedOrphanDays = $this->normalizeOrphanDays($orphanDays);
        if ($normalizedOrphanDays > 0) {
            $params['orphan_days'] = (string) $normalizedOrphanDays;
        }

        if ($confirmMember) {
            $params['confirm_member'] = '1';
        }

        return $params;
    }

    private function buildAdminUrl(array $params = []): string
    {
        $baseUrl = '/admin/media';
        if ($params === []) {
            return $baseUrl;
        }

        $normalizedParams = array_filter($params, static fn (mixed $value): bool => $value !== '' && $value !== null);

        return $normalizedParams === [] ? $baseUrl : $baseUrl . '?' . http_build_query($normalizedParams);
    }

    /**
     * @return list<array<string, string>>
     */
    private function buildBreadcrumbs(string $path, string $view, string $category, string $search, string $usageFilter, bool $confirmMember, array $advancedFilters = [], int $orphanDays = 0): array
    {
        if ($path === '') {
            return [];
        }

        $breadcrumbs = [];
        $parts = explode('/', trim($path, '/'));
        $cumulative = '';

        foreach ($parts as $index => $part) {
            $cumulative .= ($cumulative !== '' ? '/' : '') . $part;
            $isLast = $index === count($parts) - 1;
            $breadcrumbs[] = [
                'label' => $part,
                'path' => $cumulative,
                'url' => $isLast ? '' : $this->buildAdminUrl($this->buildLibraryStateParams($cumulative, $view, $category, $search, $usageFilter, $confirmMember, $advancedFilters, $orphanDays)),
            ];
        }

        return $breadcrumbs;
    }

    /**
     * @param array<int, array<string, mixed>> $folders
     * @return list<array<string, mixed>>
     */
    private function buildFolderViewModels(array $folders, string $path, string $view, string $category, string $search, string $usageFilter, bool $confirmMember, array $advancedFilters = [], int $orphanDays = 0): array
    {
        $viewModels = [];

        foreach ($folders as $folder) {
            $folderName = (string)($folder['name'] ?? '');
            $folderPath = (string)($folder['path'] ?? trim(($path !== '' ? $path . '/' : '') . $folderName, '/'));
            $requiresConfirmation = $this->requiresMemberConfirmation($folderPath) && !$confirmMember;

            $viewModels[] = [
                'name' => $folderName,
                'path' => $folderPath,
                'items_count' => (int)($folder['items_count'] ?? 0),
                'category' => (string)($folder['category'] ?? ''),
                'modified_label' => !empty($folder['modified']) ? date('d.m.Y H:i', (int)$folder['modified']) : '—',
                'is_system' => !empty($folder['is_system']),
                'url' => $this->buildAdminUrl($this->buildLibraryStateParams($folderPath, $view, $category, $search, $usageFilter, $confirmMember, $advancedFilters, $orphanDays)),
                'confirm_url' => $this->buildAdminUrl($this->buildLibraryStateParams($folderPath, $view, $category, $search, $usageFilter, true, $advancedFilters, $orphanDays)),
                'requires_confirmation' => $requiresConfirmation,
            ];
        }

        return $viewModels;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return list<array<string, mixed>>
     */
    private function buildFileViewModels(array $files, string $path, array $usageMap = [], array $duplicateMap = []): array
    {
        $viewModels = [];
        $altTextMap = $this->loadMediaAltTextMap(array_map(
            static fn (array $file): string => (string) ($file['path'] ?? ''),
            $files
        ));

        foreach ($files as $file) {
            $fileName = (string)($file['name'] ?? '');
            $filePath = (string)($file['path'] ?? trim(($path !== '' ? $path . '/' : '') . $fileName, '/'));
            $mediaDelivery = MediaDeliveryService::getInstance();
            $fileUrl = $mediaDelivery->buildDeliveryUrl($filePath, 'attachment');
            $previewUrl = $mediaDelivery->buildPreviewUrl($filePath);
            $fileType = $this->detectFileType($fileName);
            $usageItems = array_values(array_filter(
                is_array($usageMap[$filePath] ?? null) ? $usageMap[$filePath] : [],
                static fn (mixed $usage): bool => is_array($usage)
            ));
            $usageCount = count($usageItems);
            $usageSummary = $this->buildMediaUsageSummary($usageItems);
            $duplicateInfo = is_array($duplicateMap[$filePath] ?? null) ? $duplicateMap[$filePath] : [];
            $altText = $this->normalizeAltText($altTextMap[$filePath] ?? '');

            $viewModels[] = [
                'name' => $fileName,
                'path' => $filePath,
                'url' => $fileUrl,
                'preview_url' => $previewUrl,
                'category' => (string)($file['category'] ?? ''),
                'category_label' => (string)($file['category'] ?? 'Ohne Kategorie'),
                'tags' => $this->normalizeTags($file['tags'] ?? []),
                'modified_label' => !empty($file['modified']) ? date('d.m.Y H:i', (int)$file['modified']) : '—',
                'file_type' => $fileType,
                'is_image' => $fileType === 'image',
                'formatted_size' => $this->formatBytes(isset($file['size']) ? (int)$file['size'] : null),
                'usage_items' => $usageItems,
                'usage_count' => $usageCount,
                'usage_count_label' => $usageCount === 1 ? '1 Verwendung' : $usageCount . ' Verwendungen',
                'usage_summary' => $usageSummary,
                'duplicate_info' => $duplicateInfo,
                'duplicate_count' => (int) ($duplicateInfo['duplicate_count'] ?? 0),
                'duplicate_paths' => is_array($duplicateInfo['duplicate_paths'] ?? null) ? array_values($duplicateInfo['duplicate_paths']) : [],
                'duplicate_short_hash' => (string) ($duplicateInfo['short_hash'] ?? ''),
                'alt_text' => $altText,
                'alt_text_missing' => $altText === '',
            ] + $file;
        }

        return $viewModels;
    }

    /**
     * @param mixed $altTextMap
     * @return array<string, string>
     */
    public function normalizeAltTextMap(mixed $altTextMap): array
    {
        if (!is_array($altTextMap)) {
            return [];
        }

        $normalized = [];

        foreach ($altTextMap as $path => $value) {
            $normalizedPath = $this->normalizeRelativePath((string) $path);
            if ($normalizedPath === '') {
                continue;
            }

            $normalized[$normalizedPath] = $this->normalizeAltText($value);
        }

        return $normalized;
    }

    private function normalizeAltText(mixed $value): string
    {
        $normalizedValue = trim((string) $value);
        $normalizedValue = preg_replace('/[\x00-\x1F\x7F]+/u', '', $normalizedValue) ?? '';

        return function_exists('mb_substr')
            ? mb_substr($normalizedValue, 0, self::MAX_ALT_TEXT_LENGTH)
            : substr($normalizedValue, 0, self::MAX_ALT_TEXT_LENGTH);
    }

    private function mediaTableExists(): bool
    {
        if ($this->mediaTableExistsCache !== null) {
            return $this->mediaTableExistsCache;
        }

        try {
            $table = $this->prefix . 'media';
            $quotedTable = $this->db->getPdo()->quote($table);
            if (!is_string($quotedTable)) {
                $this->mediaTableExistsCache = false;
                return false;
            }

            $result = $this->db->getPdo()->query('SHOW TABLES LIKE ' . $quotedTable);
            $this->mediaTableExistsCache = $result !== false && $result->fetchColumn() !== false;
        } catch (\Throwable) {
            $this->mediaTableExistsCache = false;
        }

        return $this->mediaTableExistsCache;
    }

    /**
     * @param array<int, string> $paths
     * @return array<string, string>
     */
    private function loadMediaAltTextMap(array $paths): array
    {
        if ($paths === [] || !$this->mediaTableExists()) {
            return [];
        }

        $normalizedPaths = [];

        foreach ($paths as $path) {
            $normalizedPath = $this->normalizeRelativePath($path);
            if ($normalizedPath === '') {
                continue;
            }

            $normalizedPaths[$normalizedPath] = true;
        }

        if ($normalizedPaths === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($normalizedPaths), '?'));

        try {
            $rows = $this->db->get_results(
                "SELECT filepath, alt_text FROM {$this->prefix}media WHERE filepath IN ({$placeholders})",
                array_keys($normalizedPaths)
            );
        } catch (\Throwable) {
            return [];
        }

        $altTextMap = [];
        foreach ($rows as $row) {
            $filePath = $this->normalizeRelativePath((string) ($row->filepath ?? ''));
            if ($filePath === '') {
                continue;
            }

            $altTextMap[$filePath] = $this->normalizeAltText($row->alt_text ?? '');
        }

        return $altTextMap;
    }

    private function upsertMediaAltText(string $relativePath, string $altText): bool
    {
        if (!$this->mediaTableExists()) {
            return false;
        }

        $normalizedPath = $this->normalizeRelativePath($relativePath);
        if ($normalizedPath === '') {
            return false;
        }

        try {
            $existingId = $this->db->get_var(
                "SELECT id FROM {$this->prefix}media WHERE filepath = ? LIMIT 1",
                [$normalizedPath]
            );
        } catch (\Throwable) {
            return false;
        }

        if ($existingId !== null) {
            return $this->db->update('media', ['alt_text' => $altText], ['id' => (int) $existingId]);
        }

        $absolutePath = rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
        if (!is_file($absolutePath)) {
            return false;
        }

        $currentUser = Auth::getCurrentUser();
        $uploadedBy = isset($currentUser->id) ? (int) $currentUser->id : 0;
        $filename = basename($normalizedPath);
        $mimeType = function_exists('mime_content_type') ? (string) (mime_content_type($absolutePath) ?: '') : '';
        if ($mimeType === '') {
            $mimeType = 'application/octet-stream';
        }

        return $this->db->insert('media', [
            'filename' => $filename,
            'filepath' => $normalizedPath,
            'filetype' => $mimeType,
            'filesize' => max(0, (int) (filesize($absolutePath) ?: 0)),
            'title' => $this->buildMediaRecordTitle($filename),
            'alt_text' => $altText,
            'caption' => '',
            'uploaded_by' => $uploadedBy,
        ]) !== false;
    }

    private function buildMediaRecordTitle(string $filename): string
    {
        $title = (string) pathinfo($filename, PATHINFO_FILENAME);
        $title = str_replace(['-', '_'], ' ', $title);
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        if ($title === '') {
            $title = $filename;
        }

        return function_exists('mb_substr')
            ? mb_substr($title, 0, 255)
            : substr($title, 0, 255);
    }

    private function deleteMediaTableEntriesForPath(string $relativePath): void
    {
        if (!$this->mediaTableExists()) {
            return;
        }

        $normalizedPath = $this->normalizeRelativePath($relativePath);
        if ($normalizedPath === '') {
            return;
        }

        try {
            $statement = $this->db->prepare("DELETE FROM {$this->prefix}media WHERE filepath = ? OR filepath LIKE ?");
            if ($statement !== false) {
                $statement->execute([$normalizedPath, $normalizedPath . '/%']);
            }
        } catch (\Throwable) {
            // fail-soft: optionaler Alt-Text-Store darf Medienoperationen nicht abbrechen.
        }
    }

    private function renameMediaTablePaths(string $oldRelativePath, string $newRelativePath): void
    {
        if (!$this->mediaTableExists()) {
            return;
        }

        $normalizedOldPath = $this->normalizeRelativePath($oldRelativePath);
        $normalizedNewPath = $this->normalizeRelativePath($newRelativePath);
        if ($normalizedOldPath === '' || $normalizedNewPath === '' || $normalizedOldPath === $normalizedNewPath) {
            return;
        }

        try {
            $rows = $this->db->get_results(
                "SELECT id, filepath FROM {$this->prefix}media WHERE filepath = ? OR filepath LIKE ?",
                [$normalizedOldPath, $normalizedOldPath . '/%']
            );
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $row) {
            $currentPath = $this->normalizeRelativePath((string) ($row->filepath ?? ''));
            if ($currentPath === '') {
                continue;
            }

            $suffix = substr($currentPath, strlen($normalizedOldPath));
            $updatedPath = $currentPath === $normalizedOldPath
                ? $normalizedNewPath
                : rtrim($normalizedNewPath, '/') . '/' . ltrim((string) $suffix, '/');
            $updatedPath = preg_replace('#/+#', '/', $updatedPath) ?? $updatedPath;
            $updatedPath = trim($updatedPath, '/');

            if ($updatedPath === '') {
                continue;
            }

            $this->db->update('media', [
                'filepath' => $updatedPath,
                'filename' => basename($updatedPath),
            ], [
                'id' => (int) ($row->id ?? 0),
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $usageItems
     * @return array<string, mixed>
     */
    private function buildMediaUsageSummary(array $usageItems): array
    {
        $summary = [
            'post_count' => 0,
            'page_count' => 0,
            'featured_count' => 0,
            'content_count' => 0,
            'content_en_count' => 0,
            'field_labels' => [],
        ];

        foreach ($usageItems as $usageItem) {
            if (!is_array($usageItem)) {
                continue;
            }

            $contentType = (string) ($usageItem['content_type'] ?? '');
            if ($contentType === 'post') {
                $summary['post_count']++;
            } elseif ($contentType === 'page') {
                $summary['page_count']++;
            }

            $field = (string) ($usageItem['field'] ?? '');
            if ($field === 'featured_image') {
                $summary['featured_count']++;
            } elseif ($field === 'content') {
                $summary['content_count']++;
            } elseif ($field === 'content_en') {
                $summary['content_en_count']++;
            }

            $fieldLabel = trim((string) ($usageItem['field_label'] ?? 'Verwendung'));
            if ($fieldLabel !== '') {
                $summary['field_labels'][$fieldLabel] = ((int) ($summary['field_labels'][$fieldLabel] ?? 0)) + 1;
            }
        }

        ksort($summary['field_labels'], SORT_NATURAL | SORT_FLAG_CASE);

        return $summary;
    }

    /**
     * @param array<string, string> $advancedFilters
     */
    private function hasActiveAdvancedMediaFilters(array $advancedFilters): bool
    {
        return $this->normalizeFileTypeFilter((string)($advancedFilters['file_type'] ?? 'all')) !== 'all'
            || $this->normalizeExtensionFilter((string)($advancedFilters['extension'] ?? '')) !== ''
            || $this->normalizeSizeFilter((string)($advancedFilters['size'] ?? 'all')) !== 'all'
            || $this->normalizeModifiedFilter((string)($advancedFilters['modified'] ?? 'all')) !== 'all';
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<string, string> $advancedFilters
     * @return list<array<string, mixed>>
     */
    private function applyAdvancedMediaFilters(array $files, array $advancedFilters): array
    {
        $fileTypeFilter = $this->normalizeFileTypeFilter((string)($advancedFilters['file_type'] ?? 'all'));
        $extensionFilter = $this->normalizeExtensionFilter((string)($advancedFilters['extension'] ?? ''));
        $sizeFilter = $this->normalizeSizeFilter((string)($advancedFilters['size'] ?? 'all'));
        $modifiedFilter = $this->normalizeModifiedFilter((string)($advancedFilters['modified'] ?? 'all'));

        return array_values(array_filter($files, function (array $file) use ($fileTypeFilter, $extensionFilter, $sizeFilter, $modifiedFilter): bool {
            $fileName = (string)($file['name'] ?? $file['path'] ?? '');
            $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
            $fileType = $this->detectFileType($fileName);

            if ($fileTypeFilter !== 'all' && $fileType !== $fileTypeFilter) {
                return false;
            }

            if ($extensionFilter !== '' && $extension !== $extensionFilter) {
                return false;
            }

            if (!$this->fileMatchesSizeFilter(isset($file['size']) ? (int)$file['size'] : null, $sizeFilter)) {
                return false;
            }

            return $this->fileMatchesModifiedFilter(isset($file['modified']) ? (int)$file['modified'] : null, $modifiedFilter);
        }));
    }

    private function fileMatchesSizeFilter(?int $bytes, string $sizeFilter): bool
    {
        $sizeFilter = $this->normalizeSizeFilter($sizeFilter);
        if ($sizeFilter === 'all') {
            return true;
        }

        if ($bytes === null || $bytes < 0) {
            return false;
        }

        return match ($sizeFilter) {
            'tiny' => $bytes < 102400,
            'small' => $bytes >= 102400 && $bytes < 1048576,
            'medium' => $bytes >= 1048576 && $bytes < 5242880,
            'large' => $bytes >= 5242880 && $bytes < 52428800,
            'huge' => $bytes >= 52428800,
            default => true,
        };
    }

    private function fileMatchesModifiedFilter(?int $modifiedTimestamp, string $modifiedFilter): bool
    {
        $modifiedFilter = $this->normalizeModifiedFilter($modifiedFilter);
        if ($modifiedFilter === 'all') {
            return true;
        }

        if ($modifiedTimestamp === null || $modifiedTimestamp <= 0) {
            return false;
        }

        $now = time();
        $startOfToday = strtotime('today') ?: ($now - 86400);

        return match ($modifiedFilter) {
            'today' => $modifiedTimestamp >= $startOfToday,
            '7d' => $modifiedTimestamp >= ($now - 7 * 86400),
            '30d' => $modifiedTimestamp >= ($now - 30 * 86400),
            'year' => $modifiedTimestamp >= ($now - 365 * 86400),
            default => true,
        };
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function buildFileTypeFilterOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'Alle Dateitypen'],
            ['value' => 'image', 'label' => 'Nur Bilder'],
            ['value' => 'document', 'label' => 'Nur Dokumente'],
            ['value' => 'video', 'label' => 'Nur Videos'],
            ['value' => 'audio', 'label' => 'Nur Audio'],
            ['value' => 'archive', 'label' => 'Nur Archive'],
            ['value' => 'other', 'label' => 'Sonstige Dateien'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return list<array{value:string,label:string,count:int}>
     */
    private function buildExtensionFilterOptions(array $files, string $selectedExtension = ''): array
    {
        $counts = [];
        foreach ($files as $file) {
            $extension = $this->normalizeExtensionFilter((string)pathinfo((string)($file['name'] ?? $file['path'] ?? ''), PATHINFO_EXTENSION));
            if ($extension === '') {
                continue;
            }

            $counts[$extension] = ($counts[$extension] ?? 0) + 1;
        }

        $selectedExtension = $this->normalizeExtensionFilter($selectedExtension);
        if ($selectedExtension !== '' && !isset($counts[$selectedExtension])) {
            $counts[$selectedExtension] = 0;
        }

        ksort($counts, SORT_NATURAL);
        $options = [];
        foreach ($counts as $extension => $count) {
            $options[] = [
                'value' => (string)$extension,
                'label' => strtoupper((string)$extension),
                'count' => (int)$count,
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function buildSizeFilterOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'Alle Größen'],
            ['value' => 'tiny', 'label' => 'Unter 100 KB'],
            ['value' => 'small', 'label' => '100 KB bis 1 MB'],
            ['value' => 'medium', 'label' => '1 MB bis 5 MB'],
            ['value' => 'large', 'label' => '5 MB bis 50 MB'],
            ['value' => 'huge', 'label' => 'Über 50 MB'],
        ];
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function buildModifiedFilterOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'Alle Änderungsdaten'],
            ['value' => 'today', 'label' => 'Heute geändert'],
            ['value' => '7d', 'label' => 'Letzte 7 Tage'],
            ['value' => '30d', 'label' => 'Letzte 30 Tage'],
            ['value' => 'year', 'label' => 'Letztes Jahr'],
        ];
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function buildOrphanDayOptions(): array
    {
        return [
            ['value' => '0', 'label' => 'Keine Orphan-Prüfung'],
            ['value' => '30', 'label' => 'Ungenutzt seit 30 Tagen'],
            ['value' => '90', 'label' => 'Ungenutzt seit 90 Tagen'],
            ['value' => '180', 'label' => 'Ungenutzt seit 180 Tagen'],
            ['value' => '365', 'label' => 'Ungenutzt seit 365 Tagen'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @return list<array<string, mixed>>
     */
    private function buildCategoryOptions(array $categories): array
    {
        $options = [];

        foreach ($categories as $category) {
            $options[] = [
                'slug' => (string)($category['slug'] ?? ''),
                'name' => (string)($category['name'] ?? ''),
                'count' => (int)($category['count'] ?? 0),
            ];
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $items
     * @param array<int, array<string, mixed>> $categories
     * @param array<string, mixed> $diskUsage
     * @return array<string, string|int>
     */
    private function buildLibraryStats(array $items, array $categories, array $diskUsage, array $duplicateMap = [], array $usageMap = []): array
    {
        $duplicateGroupIds = [];
        foreach ($duplicateMap as $duplicateInfo) {
            $groupId = (string) ($duplicateInfo['group_id'] ?? '');
            if ($groupId !== '') {
                $duplicateGroupIds[$groupId] = true;
            }
        }

        $usedFileCount = 0;
        $visibleUsageReferenceCount = 0;
        foreach (($items['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }

            $filePath = (string) ($file['path'] ?? '');
            $usageCount = count(is_array($usageMap[$filePath] ?? null) ? $usageMap[$filePath] : []);
            if ($usageCount > 0) {
                $usedFileCount++;
                $visibleUsageReferenceCount += $usageCount;
            }
        }

        $visibleFileCount = count($items['files'] ?? []);

        return [
            'file_count' => (int)($diskUsage['count'] ?? count($items['files'] ?? [])),
            'storage_label' => (string)($diskUsage['formatted'] ?? '0 B'),
            'folder_count' => count($items['folders'] ?? []),
            'category_count' => count($categories),
            'visible_file_count' => $visibleFileCount,
            'used_file_count' => $usedFileCount,
            'unused_file_count' => max(0, $visibleFileCount - $usedFileCount),
            'visible_usage_reference_count' => $visibleUsageReferenceCount,
            'duplicate_file_count' => count($duplicateMap),
            'duplicate_group_count' => count($duplicateGroupIds),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrphanMediaData(int $orphanDays): array
    {
        $orphanDays = $this->normalizeOrphanDays($orphanDays);
        if ($orphanDays <= 0) {
            return [
                'enabled' => false,
                'days' => 0,
                'items' => [],
                'candidate_count' => 0,
                'scanned_file_count' => 0,
                'eligible_file_count' => 0,
                'is_truncated' => false,
            ];
        }

        $inventory = $this->service->collectManagedFileInventory(self::ORPHAN_SCAN_MAX_FILES);
        $eligibleFiles = array_values(array_filter($inventory, fn (array $file): bool => $this->isEligibleForOrphanDetection((string) ($file['path'] ?? ''))));
        $usageMap = $this->usageService->buildUsageMap(array_map(
            static fn (array $file): string => (string) ($file['path'] ?? ''),
            $eligibleFiles
        ));

        $thresholdTimestamp = time() - ($orphanDays * 86400);
        $candidates = [];

        foreach ($eligibleFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '' || !empty($usageMap[$path])) {
                continue;
            }

            $reference = $this->resolveOrphanReferenceTimestamp($file);
            if ($reference === null || $reference['timestamp'] > $thresholdTimestamp) {
                continue;
            }

            $candidates[] = $this->buildOrphanMediaItemViewModel($file, $reference);
        }

        usort($candidates, static function (array $left, array $right): int {
            $timeCompare = ((int) ($left['reference_timestamp'] ?? 0)) <=> ((int) ($right['reference_timestamp'] ?? 0));
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return strcasecmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''));
        });

        return [
            'enabled' => true,
            'days' => $orphanDays,
            'items' => array_slice($candidates, 0, self::ORPHAN_LIST_LIMIT),
            'candidate_count' => count($candidates),
            'scanned_file_count' => count($inventory),
            'eligible_file_count' => count($eligibleFiles),
            'is_truncated' => count($inventory) >= self::ORPHAN_SCAN_MAX_FILES,
        ];
    }

    private function isEligibleForOrphanDetection(string $path): bool
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        if ($normalizedPath === '') {
            return false;
        }

        foreach (self::SYSTEM_CATEGORY_SLUGS as $systemSlug) {
            if ($normalizedPath === $systemSlug || str_starts_with($normalizedPath, $systemSlug . '/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $file
     * @return array{timestamp:int,source:string,label:string}|null
     */
    private function resolveOrphanReferenceTimestamp(array $file): ?array
    {
        $uploadedAt = trim((string) ($file['uploaded_at'] ?? ''));
        $uploadedTimestamp = $uploadedAt !== '' ? strtotime($uploadedAt) : false;
        if (is_int($uploadedTimestamp) && $uploadedTimestamp > 0) {
            return [
                'timestamp' => $uploadedTimestamp,
                'source' => 'uploaded_at',
                'label' => 'Upload: ' . date('d.m.Y H:i', $uploadedTimestamp),
            ];
        }

        $modifiedTimestamp = isset($file['modified']) ? (int) $file['modified'] : 0;
        if ($modifiedTimestamp > 0) {
            return [
                'timestamp' => $modifiedTimestamp,
                'source' => 'modified',
                'label' => 'Datei geändert: ' . date('d.m.Y H:i', $modifiedTimestamp),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $file
     * @param array{timestamp:int,source:string,label:string} $reference
     * @return array<string, mixed>
     */
    private function buildOrphanMediaItemViewModel(array $file, array $reference): array
    {
        $path = (string) ($file['path'] ?? '');
        $parentPath = $this->resolveParentPathFromActionPath($path);
        $referenceTimestamp = (int) ($reference['timestamp'] ?? 0);
        $ageInDays = $referenceTimestamp > 0 ? max(0, (int) floor((time() - $referenceTimestamp) / 86400)) : 0;
        $fileType = $this->detectFileType((string) ($file['name'] ?? basename($path)));

        return [
            'name' => (string) ($file['name'] ?? basename($path)),
            'path' => $path,
            'parent_path' => $parentPath,
            'review_url' => $this->buildAdminUrl($this->buildLibraryStateParams($parentPath, 'list', '', '', 'all', false)),
            'url' => (string) ($file['url'] ?? ''),
            'preview_url' => (string) ($file['preview_url'] ?? ''),
            'category_label' => (string) ($file['category'] ?? 'Ohne Kategorie'),
            'formatted_size' => $this->formatBytes(isset($file['size']) ? (int) $file['size'] : null),
            'reference_label' => (string) ($reference['label'] ?? ''),
            'reference_timestamp' => $referenceTimestamp,
            'age_label' => $ageInDays === 1 ? '1 Tag ohne erkannte Verwendung' : $ageInDays . ' Tage ohne erkannte Verwendung',
            'uploaded_by' => trim((string) ($file['uploaded_by'] ?? '')),
            'original_name' => trim((string) ($file['original_name'] ?? '')),
            'tags' => array_values(array_filter(array_map('strval', (array) ($file['tags'] ?? [])))),
            'is_image' => $fileType === 'image',
            'file_type' => $fileType,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{view:string,category:string,search:string,usage_filter:string,file_type:string,extension:string,size:string,modified:string,orphan_days:int}
     */
    private function normalizeLibraryFilterPresetState(array $state): array
    {
        return [
            'view' => $this->normalizeView((string) ($state['view'] ?? 'list')),
            'category' => $this->normalizeCategorySlug((string) ($state['category'] ?? '')),
            'search' => $this->sanitizeSearch((string) ($state['search'] ?? $state['q'] ?? '')),
            'usage_filter' => $this->normalizeUsageFilter((string) ($state['usage_filter'] ?? 'all')),
            'file_type' => $this->normalizeFileTypeFilter((string) ($state['file_type'] ?? 'all')),
            'extension' => $this->normalizeExtensionFilter((string) ($state['extension'] ?? '')),
            'size' => $this->normalizeSizeFilter((string) ($state['size'] ?? $state['size_filter'] ?? 'all')),
            'modified' => $this->normalizeModifiedFilter((string) ($state['modified'] ?? $state['modified_filter'] ?? 'all')),
            'orphan_days' => $this->normalizeOrphanDays($state['orphan_days'] ?? 0),
        ];
    }

    /**
     * @param array{view:string,category:string,search:string,usage_filter:string,file_type:string,extension:string,size:string,modified:string,orphan_days:int} $state
     */
    private function hasMeaningfulLibraryPresetState(array $state): bool
    {
        return $state['category'] !== ''
            || $state['search'] !== ''
            || $state['usage_filter'] !== 'all'
            || $state['file_type'] !== 'all'
            || $state['extension'] !== ''
            || $state['size'] !== 'all'
            || $state['modified'] !== 'all'
            || $state['orphan_days'] > 0;
    }

    /**
     * @param array{view:string,category:string,search:string,usage_filter:string,file_type:string,extension:string,size:string,modified:string,orphan_days:int} $state
     * @return array<string, string>
     */
    private function buildLibraryPresetStateParams(array $state): array
    {
        return $this->buildLibraryStateParams(
            '',
            $state['view'],
            $state['category'],
            $state['search'],
            $state['usage_filter'],
            false,
            [
                'file_type' => $state['file_type'],
                'extension' => $state['extension'],
                'size' => $state['size'],
                'modified' => $state['modified'],
            ],
            $state['orphan_days']
        );
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function libraryFilterPresetStatesMatch(array $left, array $right): bool
    {
        return $this->normalizeLibraryFilterPresetState($left) === $this->normalizeLibraryFilterPresetState($right);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadLibraryFilterPresets(): array
    {
        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            return [];
        }

        try {
            $optionValue = $this->db->get_var(
                "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ?",
                [$this->getLibraryFilterPresetsOptionName($userId)]
            );
        } catch (\Throwable) {
            return [];
        }

        if (!is_string($optionValue) || trim($optionValue) === '') {
            return [];
        }

        $decoded = json_decode($optionValue, true);
        $rawPresets = is_array($decoded['presets'] ?? null)
            ? $decoded['presets']
            : (is_array($decoded) ? $decoded : []);

        return $this->normalizeLoadedLibraryFilterPresets($rawPresets);
    }

    /**
     * @param mixed $rawPresets
     * @return list<array<string, mixed>>
     */
    private function normalizeLoadedLibraryFilterPresets(mixed $rawPresets): array
    {
        if (!is_array($rawPresets)) {
            return [];
        }

        $presets = [];

        foreach ($rawPresets as $preset) {
            if (!is_array($preset)) {
                continue;
            }

            $label = $this->normalizeFilterPresetLabel((string) ($preset['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $state = $this->normalizeLibraryFilterPresetState(is_array($preset['state'] ?? null) ? $preset['state'] : $preset);
            if (!$this->hasMeaningfulLibraryPresetState($state)) {
                continue;
            }

            $slug = $this->normalizeFilterPresetSlug((string) ($preset['slug'] ?? $label));
            if ($slug === '') {
                $slug = $this->generateUniqueLibraryFilterPresetSlug($label, array_keys($presets));
            }

            if ($slug === '' || isset($presets[$slug])) {
                continue;
            }

            $presets[$slug] = [
                'slug' => $slug,
                'label' => $label,
                'state' => $state,
                'created_at' => trim((string) ($preset['created_at'] ?? '')),
                'updated_at' => trim((string) ($preset['updated_at'] ?? '')),
            ];

            if (count($presets) >= self::FILTER_PRESET_MAX_COUNT) {
                break;
            }
        }

        return array_values($presets);
    }

    /**
     * @param list<array<string, mixed>> $presets
     */
    private function persistLibraryFilterPresets(int $userId, array $presets): bool
    {
        $optionName = $this->getLibraryFilterPresetsOptionName($userId);

        if ($presets === []) {
            try {
                $this->db->execute(
                    "DELETE FROM {$this->prefix}settings WHERE option_name = ?",
                    [$optionName]
                );
            } catch (\Throwable) {
                return false;
            }

            return true;
        }

        $payload = json_encode([
            'presets' => array_values($presets),
            'updated_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            return false;
        }

        try {
            $exists = (int) ($this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?",
                [$optionName]
            ) ?? 0);

            return $exists > 0
                ? (bool) $this->db->update('settings', ['option_value' => $payload, 'autoload' => 0], ['option_name' => $optionName])
                : $this->db->insert('settings', ['option_name' => $optionName, 'option_value' => $payload, 'autoload' => 0]) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $existingSlugs
     */
    private function generateUniqueLibraryFilterPresetSlug(string $label, array $existingSlugs): string
    {
        $baseSlug = $this->normalizeFilterPresetSlug($label);
        if ($baseSlug === '') {
            $baseSlug = 'preset';
        }

        if (!in_array($baseSlug, $existingSlugs, true)) {
            return $baseSlug;
        }

        $suffix = 2;
        while ($suffix <= 99) {
            $candidate = $this->normalizeFilterPresetSlug($baseSlug . '-' . $suffix);
            if ($candidate !== '' && !in_array($candidate, $existingSlugs, true)) {
                return $candidate;
            }
            $suffix++;
        }

        return $baseSlug;
    }

    private function getLibraryFilterPresetsOptionName(int $userId): string
    {
        return 'admin_media_filter_presets_user_' . max(0, $userId);
    }

    private function getCurrentUserId(): int
    {
        $user = Auth::instance()->currentUser();

        return (int) ($user->id ?? $_SESSION['user_id'] ?? 0);
    }

    /**
     * @param list<array<string, mixed>> $presets
    * @param array{view:string,category:string,search:string,usage_filter:string,file_type:string,extension:string,size:string,modified:string,orphan_days:int} $currentState
     * @return list<array<string, mixed>>
     */
    private function buildLibraryFilterPresetViewModels(array $presets, array $currentState): array
    {
        $viewModels = [];

        foreach ($presets as $preset) {
            $state = $this->normalizeLibraryFilterPresetState(is_array($preset['state'] ?? null) ? $preset['state'] : []);

            $viewModels[] = [
                'slug' => (string) ($preset['slug'] ?? ''),
                'label' => (string) ($preset['label'] ?? 'Preset'),
                'state' => $state,
                'is_active' => $this->libraryFilterPresetStatesMatch($state, $currentState),
                'url' => $this->buildAdminUrl($this->buildLibraryPresetStateParams($state)),
                'state_label' => $this->describeLibraryFilterPresetState($state),
            ];
        }

        return $viewModels;
    }

    /**
     * @param array{view:string,category:string,search:string,usage_filter:string,file_type:string,extension:string,size:string,modified:string,orphan_days:int} $state
     */
    private function describeLibraryFilterPresetState(array $state): string
    {
        $parts = [];

        if ($state['search'] !== '') {
            $parts[] = 'Suche';
        }
        if ($state['category'] !== '') {
            $parts[] = 'Kategorie';
        }
        if ($state['usage_filter'] !== 'all') {
            $parts[] = 'Verwendung';
        }
        if ($state['file_type'] !== 'all') {
            $parts[] = 'Dateityp';
        }
        if ($state['extension'] !== '') {
            $parts[] = 'Endung';
        }
        if ($state['size'] !== 'all') {
            $parts[] = 'Größe';
        }
        if ($state['modified'] !== 'all') {
            $parts[] = 'Änderungsdatum';
        }
        if ($state['orphan_days'] > 0) {
            $parts[] = 'Verwaist ≥ ' . $state['orphan_days'] . ' Tage';
        }

        return $parts !== [] ? implode(', ', $parts) : 'Standardansicht';
    }

    private function detectFileType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'svg'], true)) {
            return 'image';
        }

        if (in_array($extension, ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'], true)) {
            return 'video';
        }

        if (in_array($extension, ['mp3', 'wav', 'aac', 'flac', 'm4a'], true)) {
            return 'audio';
        }

        if (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'csv'], true)) {
            return 'document';
        }

        if (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz'], true)) {
            return 'archive';
        }

        return 'other';
    }

    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * @param list<array<string, mixed>> $usageItems
     */
    private function featuredMediaMatchesSearch(string $path, array $usageItems, string $search): bool
    {
        $needle = strtolower(trim($search));
        if ($needle === '') {
            return true;
        }

        $haystacks = [
            strtolower($path),
            strtolower(basename($path)),
        ];

        foreach ($usageItems as $usageItem) {
            $haystacks[] = strtolower((string) ($usageItem['title'] ?? ''));
            $haystacks[] = strtolower((string) ($usageItem['content_type_label'] ?? ''));
            $haystacks[] = strtolower((string) ($usageItem['field_label'] ?? ''));
        }

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $contentItem
     */
    private function featuredConsistencyMatchesScope(array $contentItem, string $usageScope): bool
    {
        if ($usageScope === 'all') {
            return true;
        }

        $targetType = $usageScope === 'posts' ? 'post' : 'page';

        return (string) ($contentItem['content_type'] ?? '') === $targetType;
    }

    /**
     * @param array<string, mixed> $contentItem
     */
    private function featuredConsistencyMatchesSearch(array $contentItem, string $statusLabel, string $referenceDisplay, string $search): bool
    {
        $needle = strtolower(trim($search));
        if ($needle === '') {
            return true;
        }

        $haystacks = [
            strtolower((string) ($contentItem['title'] ?? '')),
            strtolower((string) ($contentItem['content_type_label'] ?? '')),
            strtolower($statusLabel),
            strtolower($referenceDisplay),
        ];

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeFeaturedUsageScope(string $usageScope): string
    {
        $usageScope = strtolower(trim($usageScope));

        return in_array($usageScope, ['all', 'posts', 'pages'], true) ? $usageScope : 'all';
    }

    /**
     * @param list<array<string, mixed>> $usageItems
     * @return list<array<string, mixed>>
     */
    private function filterFeaturedUsageItemsByScope(array $usageItems, string $usageScope): array
    {
        if ($usageScope === 'all') {
            return array_values(array_filter($usageItems, static fn (mixed $usageItem): bool => is_array($usageItem)));
        }

        $targetType = $usageScope === 'posts' ? 'post' : 'page';

        return array_values(array_filter(
            $usageItems,
            static fn (mixed $usageItem): bool => is_array($usageItem) && (string) ($usageItem['content_type'] ?? '') === $targetType
        ));
    }

    private function buildGenericFailureFromWpError(WP_Error $error, array $context): array
    {
        $payload = ErrorReportService::buildFailureResultFromWpError($error, $context);
        $operation = (string)($context['operation'] ?? 'media_operation');
        $title = (string)($context['title'] ?? 'Medien-Aktion fehlgeschlagen');

        $this->logWpError('media.' . $operation . '.failed', $error, $context + [
            'report_payload' => $payload['report_payload'] ?? [],
            'error_details' => $payload['error_details'] ?? [],
        ]);

        return [
            'success' => false,
            'error' => $title . '. Bitte Logs prüfen.',
            'details' => is_array($payload['details'] ?? null) ? $payload['details'] : [],
            'error_details' => is_array($payload['error_details'] ?? null) ? $payload['error_details'] : [],
            'report_payload' => $payload['report_payload'] ?? [],
        ];
    }

    private function getMediaProcessingJobPath(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'media-processing-job.json';
    }

    /** @return array<string,mixed> */
    private function loadMediaProcessingJob(): array
    {
        $path = $this->getMediaProcessingJobPath();
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $fileSize = @filesize($path);
        if ($fileSize === false || $fileSize <= 0 || $fileSize > self::MEDIA_PROCESSING_JOB_MAX_FILE_BYTES) {
            Logger::instance()->withChannel('admin.media')->warning('Medien-Derivat-Jobdatei wurde wegen ungültiger Größe ignoriert.', [
                'job_file' => 'media-processing-job.json',
                'size' => $fileSize,
            ]);

            return [];
        }

        $content = @file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $this->normalizeLoadedMediaProcessingJob($decoded) : [];
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function normalizeLoadedMediaProcessingJob(array $job): array
    {
        $paths = [];
        foreach ((array) ($job['paths'] ?? []) as $path) {
            $normalizedPath = $this->normalizeRelativePath((string) $path);
            if ($normalizedPath === '') {
                continue;
            }

            $paths[$normalizedPath] = true;
            if (count($paths) >= self::MEDIA_PROCESSING_JOB_MAX_CANDIDATES) {
                break;
            }
        }

        if ($paths === []) {
            return [];
        }

        $total = count($paths);
        $cursor = max(0, min($total, (int) ($job['cursor'] ?? 0)));
        $processed = max(0, min($total, (int) ($job['processed'] ?? $cursor)));
        $status = strtolower(trim((string) ($job['status'] ?? 'queued')));
        if (!in_array($status, ['queued', 'running', 'completed', 'cancelled'], true)) {
            $status = 'queued';
        }

        $limitStrings = static function (mixed $values, int $limit): array {
            $items = [];
            foreach ((array) $values as $value) {
                $text = trim((string) $value);
                if ($text === '') {
                    continue;
                }

                $items[] = function_exists('mb_substr') ? mb_substr($text, 0, 240) : substr($text, 0, 240);
                if (count($items) >= $limit) {
                    break;
                }
            }

            return $items;
        };

        return [
            'id' => preg_replace('/[^A-Za-z0-9._:-]/', '', (string) ($job['id'] ?? 'media-derivatives')) ?: 'media-derivatives',
            'status' => $status,
            'mode' => $this->normalizeProcessingMode((string) ($job['mode'] ?? 'all')),
            'created_at' => trim((string) ($job['created_at'] ?? date('c'))),
            'updated_at' => trim((string) ($job['updated_at'] ?? date('c'))),
            'total' => $total,
            'cursor' => $cursor,
            'processed' => $processed,
            'succeeded' => max(0, min($total, (int) ($job['succeeded'] ?? 0))),
            'skipped' => max(0, min($total, (int) ($job['skipped'] ?? 0))),
            'failed' => max(0, min($total, (int) ($job['failed'] ?? 0))),
            'batch_size' => max(1, min(20, (int) ($job['batch_size'] ?? self::MEDIA_PROCESSING_JOB_BATCH_SIZE))),
            'paths' => array_keys($paths),
            'last_details' => $limitStrings($job['last_details'] ?? [], 8),
            'last_errors' => $limitStrings($job['last_errors'] ?? [], 8),
        ];
    }

    /** @param array<string,mixed> $job */
    private function saveMediaProcessingJob(array $job): bool
    {
        $path = $this->getMediaProcessingJobPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        $job = $this->normalizeLoadedMediaProcessingJob($job);
        if ($job === []) {
            return false;
        }

        $encoded = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return false;
        }

        $temporaryPath = $path . '.tmp.' . str_replace('.', '', uniqid('', true));
        if (@file_put_contents($temporaryPath, $encoded . PHP_EOL, LOCK_EX) === false) {
            return false;
        }

        @chmod($temporaryPath, 0640);

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            return false;
        }

        return true;
    }

    private function clearMediaProcessingJob(): void
    {
        $path = $this->getMediaProcessingJobPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
    private function getMediaProcessingJobViewData(): array
    {
        $job = $this->loadMediaProcessingJob();
        if ($job === []) {
            return [
                'exists' => false,
                'is_active' => false,
                'status' => 'none',
                'percent' => 0,
            ];
        }

        $total = max(0, (int) ($job['total'] ?? 0));
        $processed = max(0, min($total, (int) ($job['processed'] ?? $job['cursor'] ?? 0)));
        $status = (string) ($job['status'] ?? 'queued');

        return array_merge($job, [
            'exists' => true,
            'is_active' => in_array($status, ['queued', 'running'], true) && $processed < $total,
            'status' => $status,
            'total' => $total,
            'processed' => $processed,
            'percent' => $total > 0 ? (int) floor(($processed / $total) * 100) : 0,
            'succeeded' => max(0, (int) ($job['succeeded'] ?? 0)),
            'skipped' => max(0, (int) ($job['skipped'] ?? 0)),
            'failed' => max(0, (int) ($job['failed'] ?? 0)),
            'last_details' => is_array($job['last_details'] ?? null) ? array_slice($job['last_details'], 0, 8) : [],
            'last_errors' => is_array($job['last_errors'] ?? null) ? array_slice($job['last_errors'], 0, 8) : [],
        ]);
    }

    /**
     * @param array<string,mixed> $job
     * @return list<string>
     */
    private function buildMediaProcessingJobSummaryDetails(array $job): array
    {
        $total = max(0, (int) ($job['total'] ?? 0));
        $processed = max(0, min($total, (int) ($job['processed'] ?? $job['cursor'] ?? 0)));

        return [
            'Fortschritt: ' . $processed . ' / ' . $total . ' Datei(en).',
            'Erzeugt: ' . max(0, (int) ($job['succeeded'] ?? 0))
                . ', übersprungen: ' . max(0, (int) ($job['skipped'] ?? 0))
                . ', fehlgeschlagen: ' . max(0, (int) ($job['failed'] ?? 0)) . '.',
        ];
    }

    private function logWpError(string $action, WP_Error $error, array $context = []): void
    {
        $logContext = [
            'error_code' => $error->get_error_code(),
            'error_message' => $error->get_error_message(),
            'error_data' => is_array($error->get_error_data()) ? $error->get_error_data() : [],
            'context' => $context,
        ];

        Logger::instance()->withChannel('admin.media')->warning('Media-Aktion fehlgeschlagen.', $logContext);
        AuditLogger::instance()->log(
            AuditLogger::CAT_MEDIA,
            $action,
            'Media-Aktion fehlgeschlagen',
            'media',
            null,
            [
                'error_code' => $error->get_error_code(),
                'context' => $context,
            ],
            'warning'
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function auditBulkMediaAction(string $action, int $selectedCount, int $successCount, int $skippedCount, int $failedCount, array $metadata = []): void
    {
        $payload = [
            'bulk_action' => $action,
            'selected_count' => max(0, $selectedCount),
            'success_count' => max(0, $successCount),
            'skipped_count' => max(0, $skippedCount),
            'failed_count' => max(0, $failedCount),
        ];

        foreach ($metadata as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $payload[(string) $key] = is_scalar($value) ? $value : '[complex]';
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_MEDIA,
            'media.bulk.' . $action,
            'Medien-Bulk-Aktion ausgeführt',
            'media',
            null,
            $payload,
            $failedCount > 0 ? 'warning' : 'info'
        );
    }
}
