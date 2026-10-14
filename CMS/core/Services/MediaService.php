<?php
declare(strict_types=1);

namespace CMS\Services;

use CMS\Auth;
use CMS\Contracts\LoggerInterface;
use CMS\Json;
use CMS\Logger;
use CMS\Services\Media\ImageProcessor;
use CMS\Services\Media\MediaRepository;
use CMS\Services\Media\UploadHandler;
use CMS\WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

class MediaService {

    private const BLOCKED_UPLOAD_EXTENSIONS = ['svg'];
    private const FEATURED_REPLACEMENT_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico'];
    private const DERIVATIVE_PROCESSING_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    private const GENERATED_VARIANT_SUFFIXES = ['small', 'medium', 'large', 'banner'];
    private const DUPLICATE_HASH_MAX_BYTES = 268435456;
    private const DEFAULT_PUBLIC_UPLOAD_CACHE_TTL = 604800;
    private const PUBLIC_UPLOAD_CACHE_TTL_OPTIONS = [259200, 604800, 2678400];

    private static array $instances = [];
    private const ATOMIC_FILE_MODE = 0640;
    private const PUBLIC_MEDIA_FILE_MODE = 0644;
    private const PRIVATE_MEDIA_FILE_MODE = 0640;

    private string $uploadPath;
    private string $uploadUrl;
    private string $metaFile;
    private string $settingsFile;
    private LoggerInterface $logger;
    private MediaRepository $repository;
    private UploadHandler $uploadHandler;
    private ImageProcessor $imageProcessor;

    // Standard folders that cannot be deleted
    private $systemFolders = ['themes', 'plugins', 'assets', 'fonts', 'dl-manager', 'form-uploads', 'member'];

    public static function getInstance(string $customRoot = ''): self {
        $key = $customRoot !== '' ? $customRoot : '__default__';

        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($customRoot);
        }

        return self::$instances[$key];
    }

    /**
     * Parse ini size string to bytes
     */
    private function parseSize(string $size): int {
        $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
        $size = preg_replace('/[^0-9\.]/', '', $size);
        if ($unit) {
            return (int) round((float) $size * pow(1024, stripos('bkmgtpezy', $unit[0])));
        }
        return (int) round((float) $size);
    }

    public function __construct(string $customRoot = '') {
        $this->uploadPath = $customRoot ? rtrim($customRoot, '/\\') : rtrim(UPLOAD_PATH, '/\\');
        $this->uploadUrl = rtrim(UPLOAD_URL, '/\\');
        
        // Correct URL if custom root is inside uploads
        if ($customRoot && strpos($customRoot, UPLOAD_PATH) === 0) {
            $relativePath = str_replace(UPLOAD_PATH, '', $customRoot);
            $relativePath = str_replace('\\', '/', $relativePath); // ensuring forward slashes for URL
            $relativePath = ltrim($relativePath, '/');
            $this->uploadUrl .= '/' . $relativePath;
        }

        $this->settingsFile = dirname(dirname(__DIR__)) . '/config/media-settings.json';
        $this->metaFile = dirname(dirname(__DIR__)) . '/config/media-meta.json';
        $this->logger = Logger::instance()->withChannel('media.service');

        $this->repository = new MediaRepository($this->uploadPath, $this->uploadUrl, $this->metaFile, $this->systemFolders, $this->logger);
        $this->imageProcessor = new ImageProcessor();
        $this->uploadHandler = new UploadHandler(
            $this->uploadPath,
            $this->repository,
            $this->imageProcessor,
            \Closure::fromCallable([$this, 'validateUploadFile']),
            $this->logger
        );

        $settings = $this->getSettings();
        $protectionResult = $this->syncUploadsProtection((bool) ($settings['protect_uploads_dir'] ?? true));
        if ($protectionResult instanceof WP_Error) {
            $this->logger->warning('Upload-Verzeichnis-Schutz konnte beim Initialisieren nicht synchronisiert werden.', [
                'error_code' => $protectionResult->get_error_code(),
                'error_message' => $protectionResult->get_error_message(),
            ]);
        }
    }

    /**
     * Get All Categories
     */
    public function getCategories(): array {
        return $this->repository->getCategories();
    }

    /**
     * Add Category
     */
    public function addCategory(string $name, string $slug = ''): bool|WP_Error {
        return $this->repository->addCategory($name, $slug);
    }

    /**
     * Delete Category
     */
    public function deleteCategory(string $slug): bool|WP_Error {
        return $this->repository->deleteCategory($slug);
    }

    /**
     * Assign Category to File
     */
    public function assignCategory(string $filePath, string $categorySlug): bool|WP_Error {
        return $this->repository->assignCategory($filePath, $categorySlug);
    }

    /**
     * @param array<int, string> $tags
     */
    public function assignTags(string $filePath, array $tags, string $mode = 'replace'): bool|WP_Error {
        return $this->repository->assignTags($filePath, $tags, $mode);
    }

    private function getDefaultSettings(): array {
        return [
            'max_upload_size' => '64M',
            'allowed_types' => ['image', 'document', 'video', 'audio', 'archive'],
            'auto_webp' => true,
            'strip_exif' => true,
            'jpeg_quality' => 85,
            'max_width' => 2560,
            'max_height' => 2560,
            'organize_month_year' => false,
            'sanitize_filenames' => true,
            'unique_filenames' => true,
            'lowercase_filenames' => false,
            'member_uploads_enabled' => false,
            'member_max_upload_size' => '5M',
            'member_allowed_types' => ['image', 'document'],
            'member_delete_own' => false,
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
        ];
    }

    private function getTypeMap(): array {
        return [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico'],
            'video' => ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'],
            'audio' => ['mp3', 'wav', 'aac', 'flac', 'm4a'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'csv'],
            'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
            'plugin' => ['zip'],
            'theme' => ['zip']
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

    private function sanitizeSizeSetting(string $size, string $fallback): string {
        $normalized = strtoupper(str_replace(' ', '', trim($size)));
        // Bare number without unit → treat as MB (matches what the admin form sends)
        if (preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            $normalized .= 'M';
        }
        return preg_match('/^\d+(?:\.\d+)?(?:B|K|KB|M|MB|G|GB)$/', $normalized) ? $normalized : $fallback;
    }

    private function normalizeBooleanSetting(array $settings, string $key, bool $fallback): bool {
        if (!array_key_exists($key, $settings)) {
            return $fallback;
        }

        $value = $settings[$key];

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return !empty($value);
    }

    private function getSettingValue(array $settings, array $keys, mixed $fallback): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $settings)) {
                return $settings[$key];
            }
        }

        return $fallback;
    }

    /**
     * @param array<int,string> $rawValues
     * @param array<int,string> $allowedExtensions
     * @return array<int,string>
     */
    private function normalizeAllowedTypeSelection(array $rawValues, array $allowedExtensions): array
    {
        $typeMap = $this->getTypeMap();
        $allowedLookup = array_fill_keys($allowedExtensions, true);
        $normalized = [];

        foreach ($rawValues as $value) {
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

    private function normalizeSettings(array $settings): array {
        $defaults = $this->getDefaultSettings();
        $allTypes = $this->expandTypeGroups(['image', 'video', 'audio', 'document', 'archive']);
        $memberTypes = $this->expandTypeGroups(['image', 'document']);

        $allowedTypes = $this->normalizeAllowedTypeSelection(
            array_map('strval', (array) $this->getSettingValue($settings, ['allowed_types'], $defaults['allowed_types'])),
            $allTypes
        );
        if ($allowedTypes === []) {
            $allowedTypes = $defaults['allowed_types'];
        }

        $memberAllowedTypes = $this->normalizeAllowedTypeSelection(
            array_map('strval', (array) $this->getSettingValue($settings, ['member_allowed_types'], $defaults['member_allowed_types'])),
            $memberTypes
        );
        if ($memberAllowedTypes === []) {
            $memberAllowedTypes = $memberTypes;
        }

        return array_merge($defaults, [
            'max_upload_size' => $this->sanitizeSizeSetting((string) $this->getSettingValue($settings, ['max_upload_size'], $defaults['max_upload_size']), $defaults['max_upload_size']),
            'allowed_types' => $allowedTypes,
            'auto_webp' => $this->normalizeBooleanSetting($settings, 'auto_webp', (bool) $defaults['auto_webp']),
            'strip_exif' => $this->normalizeBooleanSetting($settings, 'strip_exif', (bool) $defaults['strip_exif']),
            'jpeg_quality' => max(60, min(100, (int) $this->getSettingValue($settings, ['jpeg_quality'], $defaults['jpeg_quality']))),
            'max_width' => max(1, min(8000, (int) $this->getSettingValue($settings, ['max_width'], $defaults['max_width']))),
            'max_height' => max(1, min(8000, (int) $this->getSettingValue($settings, ['max_height'], $defaults['max_height']))),
            'organize_month_year' => $this->normalizeBooleanSetting($settings, 'organize_month_year', (bool) $defaults['organize_month_year']),
            'sanitize_filenames' => $this->normalizeBooleanSetting($settings, array_key_exists('sanitize_filenames', $settings) ? 'sanitize_filenames' : 'sanitize_filename', (bool) $defaults['sanitize_filenames']),
            'unique_filenames' => $this->normalizeBooleanSetting($settings, array_key_exists('unique_filenames', $settings) ? 'unique_filenames' : 'unique_filename', (bool) $defaults['unique_filenames']),
            'lowercase_filenames' => $this->normalizeBooleanSetting($settings, array_key_exists('lowercase_filenames', $settings) ? 'lowercase_filenames' : 'lowercase_filename', (bool) $defaults['lowercase_filenames']),
            'member_uploads_enabled' => $this->normalizeBooleanSetting($settings, 'member_uploads_enabled', (bool) $defaults['member_uploads_enabled']),
            'member_max_upload_size' => $this->sanitizeSizeSetting((string) $this->getSettingValue($settings, ['member_max_upload_size'], $defaults['member_max_upload_size']), $defaults['member_max_upload_size']),
            'member_allowed_types' => $memberAllowedTypes,
            'member_delete_own' => $this->normalizeBooleanSetting($settings, 'member_delete_own', (bool) $defaults['member_delete_own']),
            'generate_thumbnails' => $this->normalizeBooleanSetting($settings, 'generate_thumbnails', (bool) $defaults['generate_thumbnails']),
            'thumb_small_w' => max(50, min(4000, (int) $this->getSettingValue($settings, ['thumb_small_w', 'thumbnail_small_w'], $defaults['thumb_small_w']))),
            'thumb_small_h' => max(50, min(4000, (int) $this->getSettingValue($settings, ['thumb_small_h', 'thumbnail_small_h'], $defaults['thumb_small_h']))),
            'thumb_medium_w' => max(50, min(4000, (int) $this->getSettingValue($settings, ['thumb_medium_w', 'thumbnail_medium_w'], $defaults['thumb_medium_w']))),
            'thumb_medium_h' => max(50, min(4000, (int) $this->getSettingValue($settings, ['thumb_medium_h', 'thumbnail_medium_h'], $defaults['thumb_medium_h']))),
            'thumb_large_w' => max(50, min(6000, (int) $this->getSettingValue($settings, ['thumb_large_w', 'thumbnail_large_w'], $defaults['thumb_large_w']))),
            'thumb_large_h' => max(50, min(6000, (int) $this->getSettingValue($settings, ['thumb_large_h', 'thumbnail_large_h'], $defaults['thumb_large_h']))),
            'thumb_banner_w' => max(50, min(6000, (int) $this->getSettingValue($settings, ['thumb_banner_w', 'thumbnail_banner_w'], $defaults['thumb_banner_w']))),
            'thumb_banner_h' => max(50, min(6000, (int) $this->getSettingValue($settings, ['thumb_banner_h', 'thumbnail_banner_h'], $defaults['thumb_banner_h']))),
            'block_dangerous_types' => $this->normalizeBooleanSetting($settings, 'block_dangerous_types', (bool) $defaults['block_dangerous_types']),
            'validate_image_content' => $this->normalizeBooleanSetting($settings, 'validate_image_content', (bool) $defaults['validate_image_content']),
            'require_login_for_upload' => $this->normalizeBooleanSetting($settings, 'require_login_for_upload', (bool) $defaults['require_login_for_upload']),
            'protect_uploads_dir' => $this->normalizeBooleanSetting($settings, 'protect_uploads_dir', (bool) $defaults['protect_uploads_dir']),
        ]);
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function buildUploadValidationSettings(array $settings, bool $memberContext = false): array
    {
        $normalized = $this->normalizeSettings($settings);

        if (!$memberContext) {
            return $normalized;
        }

        $normalized['max_upload_size'] = (string) ($normalized['member_max_upload_size'] ?? $normalized['max_upload_size']);
        $normalized['allowed_types'] = array_values(array_map('strval', (array) ($normalized['member_allowed_types'] ?? $normalized['allowed_types'])));

        return $normalized;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function buildFeaturedReplacementValidationSettings(array $settings): array
    {
        $normalized = $this->buildUploadValidationSettings($settings);
        $normalized['allowed_types'] = self::FEATURED_REPLACEMENT_IMAGE_EXTENSIONS;
        $normalized['block_dangerous_types'] = true;
        $normalized['validate_image_content'] = true;

        return $normalized;
    }

    private function getDangerousExtensions(): array {
        return [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'phpt', 'phtml', 'phar', 'shtml', 'htaccess', 'htpasswd',
            'exe', 'com', 'bat', 'cmd', 'ps1', 'sh', 'pl', 'py', 'cgi', 'jar', 'msi', 'vbs', 'scr', 'dll', 'asp', 'aspx', 'jsp', 'jspx'
        ];
    }

    private function getAllowedMimeMap(): array {
        return [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'bmp'  => ['image/bmp', 'image/x-bmp'],
            'ico'  => ['image/x-icon', 'image/vnd.microsoft.icon'],
            'mp4'  => ['video/mp4'],
            'webm' => ['video/webm'],
            'ogg'  => ['video/ogg', 'audio/ogg', 'application/ogg'],
            'mov'  => ['video/quicktime'],
            'avi'  => ['video/avi', 'video/x-msvideo'],
            'mkv'  => ['video/x-matroska'],
            'mp3'  => ['audio/mpeg', 'audio/mp3'],
            'wav'  => ['audio/wav', 'audio/x-wav'],
            'aac'  => ['audio/aac', 'audio/x-aac'],
            'flac' => ['audio/flac', 'audio/x-flac'],
            'm4a'  => ['audio/m4a', 'audio/x-m4a', 'audio/mp4'],
            'pdf'  => ['application/pdf'],
            'doc'  => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls'  => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt'  => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'txt'  => ['text/plain'],
            'rtf'  => ['text/rtf', 'application/rtf', 'text/richtext'],
            'csv'  => ['text/csv', 'text/plain', 'application/csv'],
            'zip'  => ['application/zip', 'application/x-zip-compressed', 'application/x-zip'],
            'rar'  => ['application/x-rar-compressed', 'application/vnd.rar'],
            '7z'   => ['application/x-7z-compressed'],
            'tar'  => ['application/x-tar'],
            'gz'   => ['application/gzip', 'application/x-gzip'],
        ];
    }

    private function detectUploadedMimeType(string $tmpName, bool $preferImageInspection = false): string
    {
        if ($tmpName === '' || !is_file($tmpName)) {
            return '';
        }

        if ($preferImageInspection) {
            $imageMime = $this->detectActualImageMimeType($tmpName);
            if ($imageMime !== '') {
                return $imageMime;
            }
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $realMime = finfo_file($finfo, $tmpName);
                finfo_close($finfo);

                if (is_string($realMime) && trim($realMime) !== '') {
                    return strtolower(trim($realMime));
                }
            }
        }

        return '';
    }

    private function detectActualImageMimeType(string $tmpName): string
    {
        if ($tmpName === '' || !is_file($tmpName)) {
            return '';
        }

        if (function_exists('exif_imagetype')) {
            $imageType = @exif_imagetype($tmpName);
            if (is_int($imageType)) {
                $mimeType = image_type_to_mime_type($imageType);
                if (is_string($mimeType) && trim($mimeType) !== '') {
                    return strtolower(trim($mimeType));
                }
            }
        }

        $imageInfo = @getimagesize($tmpName);
        if (is_array($imageInfo) && isset($imageInfo['mime']) && is_string($imageInfo['mime']) && trim($imageInfo['mime']) !== '') {
            return strtolower(trim($imageInfo['mime']));
        }

        return '';
    }

    /**
     * @return array{ext: string}|WP_Error
     */
    private function validateFile(array $file, array $settings): array|WP_Error {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'File upload error code: ' . (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE));
        }

        $fileName = trim((string) ($file['name'] ?? ''));
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $fileSize = (int) ($file['size'] ?? 0);

        if ($fileName === '' || $tmpName === '' || !is_file($tmpName)) {
            return new WP_Error('invalid_upload', 'Ungültige Upload-Datei.');
        }

        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmpName)) {
            return new WP_Error('invalid_upload_source', 'Upload-Quelle wurde nicht als HTTP-Dateiupload erkannt.');
        }

        $maxSize = $this->parseSize((string) ($settings['max_upload_size'] ?? '64M'));
        if ($fileSize > $maxSize) {
            return new WP_Error('size_limit', 'Datei ist zu groß. Maximum: ' . ($settings['max_upload_size'] ?? '64M'));
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext === '') {
            return new WP_Error('missing_extension', 'Die Datei hat keine erlaubte Dateiendung.');
        }

        if (in_array($ext, self::BLOCKED_UPLOAD_EXTENSIONS, true)) {
            return new WP_Error('type_blocked', 'SVG-Uploads sind aus Sicherheitsgründen deaktiviert.');
        }

        $allowedGroups = array_map('strval', (array) ($settings['allowed_types'] ?? $this->expandTypeGroups(['image', 'document'])));
        $typeMap = $this->getTypeMap();

        if (($settings['block_dangerous_types'] ?? false) && in_array($ext, $this->getDangerousExtensions(), true)) {
            return new WP_Error('dangerous_type', 'Gefährlicher Dateityp wurde blockiert: ' . $ext);
        }

        // Doppelendungen wie „shell.php.jpg“ blockieren: Apache-Setups mit AddHandler führen
        // solche Dateien sonst trotz harmloser Endung als PHP aus.
        if ($settings['block_dangerous_types'] ?? false) {
            $innerParts = array_slice(explode('.', strtolower(basename($fileName))), 1, -1);
            foreach ($innerParts as $innerPart) {
                if (in_array($innerPart, $this->getDangerousExtensions(), true)) {
                    return new WP_Error('dangerous_type', 'Gefährliche Doppelendung wurde blockiert: ' . $innerPart);
                }
            }
        }

        $isAllowed = in_array($ext, $allowedGroups, true);
        if (!$isAllowed) {
            foreach ($allowedGroups as $group) {
                if (isset($typeMap[$group]) && in_array($ext, $typeMap[$group], true)) {
                    $isAllowed = true;
                    break;
                }
            }
        }

        if (!$isAllowed) {
            return new WP_Error('type_not_allowed', 'Dateityp nicht erlaubt: ' . $ext);
        }

        $allowedMimeMap = $this->getAllowedMimeMap();
        if (isset($allowedMimeMap[$ext])) {
            $realMime = $this->detectUploadedMimeType($tmpName, $this->isImageExtension($ext));

            if ($realMime !== '' && !in_array($realMime, $allowedMimeMap[$ext], true)) {
                return new WP_Error('mime_mismatch', sprintf('MIME-Typ "%s" passt nicht zur Dateiendung ".%s". Upload abgebrochen.', $realMime, $ext));
            }
        }

        if (($settings['validate_image_content'] ?? false) && $this->isImageExtension($ext) && $this->readImageSize($tmpName, 'upload_validation') === null) {
            return new WP_Error('invalid_image', 'Die Datei enthält keine gültigen Bilddaten.');
        }

        return ['ext' => $ext];
    }

    /**
     * Öffentliche, zentrale Upload-Validierung für alle Datei-Upload-Endpunkte.
     *
     * @param array<string,mixed> $file
     * @param array<string,mixed>|null $settings
     * @return array{ext:string}|WP_Error
     */
    public function validateUploadFile(array $file, ?array $settings = null): array|WP_Error
    {
        $resolvedSettings = $settings !== null
            ? $this->normalizeSettings($settings)
            : $this->getSettings();

        return $this->validateFile($file, $resolvedSettings);
    }

    private function isImageExtension(string $ext): bool {
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico'], true);
    }

    private function buildThumbnailSizes(array $settings): array {
        return [
            'small' => [(int)$settings['thumb_small_w'], (int)$settings['thumb_small_h']],
            'medium' => [(int)$settings['thumb_medium_w'], (int)$settings['thumb_medium_h']],
            'large' => [(int)$settings['thumb_large_w'], (int)$settings['thumb_large_h']],
            'banner' => [(int)$settings['thumb_banner_w'], (int)$settings['thumb_banner_h']],
        ];
    }

    private function getGeneratedVariantPaths(string $fullPath): array {
        $variants = [];
        $info = pathinfo($fullPath);
        $extension = $info['extension'] ?? '';
        if ($extension === '') {
            return $variants;
        }

        foreach (array_keys($this->buildThumbnailSizes($this->getSettings())) as $name) {
            $variants[] = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '-' . $name . '.' . $extension;
        }

        return $variants;
    }

    private function normalizeManagedRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path) ?? '';
        $path = trim($path, '/');

        if ($path === '' || str_contains($path, '..') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }

        return preg_match('#^[A-Za-z0-9._\-/]+$#', $path) === 1 ? $path : '';
    }

    /**
     * @param array<int,string> $prefixes
     * @return list<string>
     */
    private function normalizeManagedRelativePrefixes(array $prefixes): array
    {
        $normalizedPrefixes = [];

        foreach ($prefixes as $prefix) {
            $normalizedPrefix = $this->normalizeManagedRelativePath((string) $prefix);
            if ($normalizedPrefix !== '') {
                $normalizedPrefixes[$normalizedPrefix] = true;
            }
        }

        return array_keys($normalizedPrefixes);
    }

    /**
     * @param array<int,string> $prefixes
     */
    private function pathMatchesManagedPrefixes(string $relativePath, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($relativePath === $prefix || str_starts_with($relativePath, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $prefixes
     * @return list<string>
     */
    private function normalizeFilenamePrefixes(array $prefixes): array
    {
        $normalizedPrefixes = [];

        foreach ($prefixes as $prefix) {
            $normalizedPrefix = trim((string) $prefix);
            if ($normalizedPrefix !== '' && strlen($normalizedPrefix) <= 120 && preg_match('/^[A-Za-z0-9._-]+$/', $normalizedPrefix) === 1) {
                $normalizedPrefixes[$normalizedPrefix] = true;
            }
        }

        return array_keys($normalizedPrefixes);
    }

    /**
     * @param array<int,string> $prefixes
     */
    private function filenameMatchesPrefixes(string $filename, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($filename, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function containsHiddenManagedSegment(string $relativePath): bool
    {
        foreach (explode('/', trim($relativePath, '/')) as $segment) {
            if ($segment !== '' && str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
    }

    private function resolveManagedMediaFileMode(string $relativePath): int
    {
        $normalizedPath = $this->normalizeManagedRelativePath($relativePath);
        if ($normalizedPath === '' || $this->containsHiddenManagedSegment($normalizedPath) || $this->pathMatchesManagedPrefixes($normalizedPath, ['member'])) {
            return self::PRIVATE_MEDIA_FILE_MODE;
        }

        return self::PUBLIC_MEDIA_FILE_MODE;
    }

    private function applyManagedMediaFileMode(string $absolutePath, string $relativePath): void
    {
        if (!is_file($absolutePath)) {
            return;
        }

        @chmod($absolutePath, $this->resolveManagedMediaFileMode($relativePath));
    }

    private function absoluteManagedPathToRelativePath(string $absolutePath): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->uploadPath), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $absolutePath);

        if (!str_starts_with($normalizedPath, $normalizedRoot)) {
            return '';
        }

        return $this->normalizeManagedRelativePath(substr($normalizedPath, strlen($normalizedRoot)));
    }

    private function applyManagedMediaFileModeByAbsolutePath(string $absolutePath): void
    {
        $relativePath = $this->absoluteManagedPathToRelativePath($absolutePath);
        if ($relativePath === '') {
            return;
        }

        $this->applyManagedMediaFileMode($absolutePath, $relativePath);
    }

    private function applyGeneratedImageFileModes(string $absolutePath): void
    {
        $paths = [$absolutePath];
        $webpPath = preg_replace('/\.[^.]+$/', '.webp', $absolutePath);
        if (is_string($webpPath) && $webpPath !== '' && $webpPath !== $absolutePath) {
            $paths[] = $webpPath;
        }

        foreach ($this->getGeneratedVariantPaths($absolutePath) as $variantPath) {
            $paths[] = $variantPath;
        }

        foreach (array_unique($paths) as $path) {
            $this->applyManagedMediaFileModeByAbsolutePath($path);
        }
    }

    private function resolveManagedAbsolutePath(string $relativePath): string|WP_Error
    {
        $normalizedRelativePath = $this->normalizeManagedRelativePath($relativePath);
        if ($normalizedRelativePath === '') {
            return new WP_Error('invalid_target_path', 'Der Medienpfad ist ungültig.');
        }

        $absolutePath = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedRelativePath);
        $normalizedRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->uploadPath), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedAbsolute = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolutePath);

        if (!str_starts_with($normalizedAbsolute, $normalizedRoot)) {
            return new WP_Error('invalid_target_path', 'Der Medienpfad liegt außerhalb des Upload-Verzeichnisses.');
        }

        return $absolutePath;
    }

    /**
     * @param array<string,mixed> $file
     */
    private function writeReplacementImage(array $file, string $sourceExtension, string $targetAbsolutePath, string $targetExtension, int $quality): bool
    {
        $sourcePath = (string) ($file['tmp_name'] ?? '');
        if ($sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath)) {
            return false;
        }

        if ($sourceExtension === $targetExtension) {
            return $this->moveIncomingReplacementFile($sourcePath, $targetAbsolutePath);
        }

        if (!in_array($targetExtension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return false;
        }

        $image = $this->loadGdImage($sourcePath, $sourceExtension);
        if (!$image instanceof \GdImage) {
            return false;
        }

        try {
            return $this->saveReplacementGdImage($image, $targetAbsolutePath, $targetExtension, $quality);
        } finally {
            imagedestroy($image);
        }
    }

    private function moveIncomingReplacementFile(string $sourcePath, string $targetPath): bool
    {
        if (is_uploaded_file($sourcePath)) {
            return move_uploaded_file($sourcePath, $targetPath);
        }

        if (PHP_SAPI !== 'cli') {
            return false;
        }

        if (@rename($sourcePath, $targetPath)) {
            return true;
        }

        if (!@copy($sourcePath, $targetPath)) {
            return false;
        }

        if (!@unlink($sourcePath)) {
            @unlink($targetPath);
            return false;
        }

        return true;
    }

    private function saveReplacementGdImage(\GdImage $image, string $targetPath, string $extension, int $quality): bool
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => imagejpeg($image, $targetPath, $quality),
            'png' => imagepng($image, $targetPath, max(0, min(9, (int) round((100 - $quality) / 10)))),
            'gif' => imagegif($image, $targetPath),
            'webp' => function_exists('imagewebp') ? imagewebp($image, $targetPath, $quality) : false,
            'bmp' => function_exists('imagebmp') ? imagebmp($image, $targetPath) : false,
            default => false,
        };
    }

    private function restoreManagedReplacementBackup(?string $backupPath, string $targetAbsolutePath): void
    {
        if ($backupPath === null || !is_file($backupPath)) {
            return;
        }

        if (is_file($targetAbsolutePath)) {
            @unlink($targetAbsolutePath);
        }

        @rename($backupPath, $targetAbsolutePath);
    }

    private function syncReplacedFileMeta(string $relativePath, string $originalName): void
    {
        $normalizedPath = $this->normalizeManagedRelativePath($relativePath);
        if ($normalizedPath === '') {
            return;
        }

        $meta = $this->repository->loadMeta();
        $existingMeta = is_array($meta['files'][$normalizedPath] ?? null) ? $meta['files'][$normalizedPath] : [];
        $currentUser = Auth::getCurrentUser();
        $uploadedBy = (string) ($currentUser->username ?? $currentUser->email ?? 'system');
        $uploaderId = isset($currentUser->id) ? (int) $currentUser->id : null;
        $category = (string) ($existingMeta['category'] ?? $this->repository->detectSystemCategory($normalizedPath) ?? '');

        $meta['files'][$normalizedPath] = array_merge($existingMeta, [
            'uploaded_at' => date('c'),
            'uploaded_by' => function_exists('mb_substr') ? mb_substr($uploadedBy, 0, 120, 'UTF-8') : substr($uploadedBy, 0, 120),
            'uploader_id' => $uploaderId,
            'original_name' => function_exists('mb_substr') ? mb_substr($originalName, 0, 180, 'UTF-8') : substr($originalName, 0, 180),
            'category' => $category,
        ]);

        $this->repository->saveMeta($meta);
    }


    public function syncUploadDirectoryPolicy(): bool|WP_Error
    {
        $settings = $this->getSettings();

        return $this->syncUploadsProtection((bool) ($settings['protect_uploads_dir'] ?? false));
    }

    private function syncUploadsProtection(bool $enabled): bool|WP_Error {
        $htaccessPath = $this->uploadPath . DIRECTORY_SEPARATOR . '.htaccess';

        if (!$enabled) {
            if (file_exists($htaccessPath) && !unlink($htaccessPath)) {
                return new WP_Error('protection_remove_failed', 'Upload-Verzeichnis-Schutz konnte nicht entfernt werden.');
            }

            return true;
        }

        $cachePolicy = $this->resolvePublicUploadCachePolicy();
        $cacheTtl = (int) ($cachePolicy['ttl'] ?? self::DEFAULT_PUBLIC_UPLOAD_CACHE_TTL);
        $cacheHeader = !empty($cachePolicy['enabled'])
            ? 'public, max-age=' . $cacheTtl . ', s-maxage=' . $cacheTtl . ', stale-while-revalidate=60, stale-if-error=300'
            : 'public, no-cache, must-revalidate, max-age=0';
        $expiresRule = !empty($cachePolicy['enabled'])
            ? 'access plus ' . $cacheTtl . ' seconds'
            : 'access plus 0 seconds';

        $content = "Options -Indexes\n"
            . "<IfModule mod_headers.c>\n"
            . "    Header always set X-Content-Type-Options \"nosniff\"\n"
            . "    Header always set Content-Disposition \"attachment\"\n"
            . "</IfModule>\n"
            . "<FilesMatch \"\\.(avif|bmp|gif|ico|jpe?g|png|webp)$\">\n"
            . "    <IfModule mod_headers.c>\n"
            . "        Header always unset Content-Disposition\n"
            . "        Header always set Content-Disposition \"inline\"\n"
            . "        Header always set Cache-Control \"" . $cacheHeader . "\"\n"
            . "        Header always set Vary \"Accept-Encoding\"\n"
            . "    </IfModule>\n"
            . "    <IfModule mod_expires.c>\n"
            . "        ExpiresActive On\n"
            . "        ExpiresDefault \"" . $expiresRule . "\"\n"
            . "    </IfModule>\n"
            . "</FilesMatch>\n"
            . "<FilesMatch \"\\.(php[0-9]?|phtml|phar|shtml|cgi|pl|py|sh|bash|ksh|zsh|asp|aspx|jsp|jspx|js[pm]|war)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n"
            . "Options -ExecCGI\n";
        if (!$this->writeFileAtomically($htaccessPath, $content)) {
            return new WP_Error('protection_write_failed', 'Upload-Verzeichnis-Schutz konnte nicht geschrieben werden.');
        }

        return true;
    }

    /** @return array{enabled:bool,ttl:int} */
    private function resolvePublicUploadCachePolicy(): array
    {
        $enabled = true;
        $ttl = self::DEFAULT_PUBLIC_UPLOAD_CACHE_TTL;

        try {
            $db = \CMS\Database::instance();
            $rows = $db->get_results(
                "SELECT option_name, option_value FROM {$db->getPrefix()}settings WHERE option_name IN ('perf_browser_cache', 'perf_browser_cache_ttl')"
            ) ?: [];

            foreach ($rows as $row) {
                $name = (string) ($row->option_name ?? '');
                $value = (string) ($row->option_value ?? '');

                if ($name === 'perf_browser_cache') {
                    $enabled = $value !== '0';
                }

                if ($name === 'perf_browser_cache_ttl') {
                    $configuredTtl = (int) $value;
                    if (in_array($configuredTtl, self::PUBLIC_UPLOAD_CACHE_TTL_OPTIONS, true)) {
                        $ttl = $configuredTtl;
                    }
                }
            }
        } catch (\Throwable) {
            return ['enabled' => $enabled, 'ttl' => $ttl];
        }

        return ['enabled' => $enabled, 'ttl' => $ttl];
    }

    private function reEncodeImage(string $path, string $ext, int $quality): bool {
        if (!function_exists('imagejpeg')) {
            return false;
        }

        $ext = strtolower($ext);
        $image = $this->loadGdImage($path, $ext);

        if ($image === false) {
            return false;
        }

        $saved = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($image, $path, $quality),
            'png' => imagepng($image, $path, max(0, min(9, (int)round((100 - $quality) / 10)))),
            'gif' => imagegif($image, $path),
            'webp' => function_exists('imagewebp') ? imagewebp($image, $path, $quality) : false,
            'bmp' => function_exists('imagebmp') ? imagebmp($image, $path) : false,
            default => false,
        };

        imagedestroy($image);
        return $saved;
    }

    /**
     * Bestimmte Clipboard-/Browser-Uploads lassen sich via GD laden und zu WebP wandeln,
     * hinterlassen aber trotzdem ein Original, das einzelne Browser nicht mehr sauber
     * rendern. Für klassische Browserformate schreiben wir das gespeicherte Original daher
     * einmal als saubere Datei neu.
     *
     * @param array<string,mixed> $settings
     */
    private function repairUploadedBrowserImage(string $absolutePath, string $storedName, array $settings): void
    {
        if (empty($settings['strip_exif'])) {
            return;
        }

        $extension = strtolower((string) pathinfo($storedName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return;
        }

        if (!is_file($absolutePath) || !is_readable($absolutePath) || !is_writable($absolutePath)) {
            return;
        }

        $quality = max(60, min(100, (int) ($settings['jpeg_quality'] ?? 85)));
        $this->reEncodeImage($absolutePath, $extension, $quality);
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function processManagedImageUpload(string $absolutePath, string $storedName, array $settings, bool $preserveOriginalImage = true): void
    {
        $extension = strtolower((string) pathinfo($storedName, PATHINFO_EXTENSION));
        if (!$this->isImageExtension($extension) || !is_file($absolutePath)) {
            return;
        }

        $quality = max(60, min(100, (int) ($settings['jpeg_quality'] ?? 85)));

        if (!$preserveOriginalImage) {
            $this->repairUploadedBrowserImage($absolutePath, $storedName, $settings);

            $maxWidth = max(1, (int) ($settings['max_width'] ?? 2560));
            $maxHeight = max(1, (int) ($settings['max_height'] ?? 2560));

            $resizeResult = $this->imageProcessor->resizeToFit($absolutePath, $maxWidth, $maxHeight, $quality);
            if ($resizeResult instanceof WP_Error) {
                $this->logger->warning('Originalbild konnte nach dem Upload nicht auf Maximalmaße skaliert werden.', [
                    'path' => $absolutePath,
                    'error_code' => $resizeResult->get_error_code(),
                    'error_message' => $resizeResult->get_error_message(),
                ]);
            }
        }

        if (!$preserveOriginalImage && !empty($settings['generate_thumbnails'])) {
            $thumbnailResult = $this->imageProcessor->generateThumbnails($absolutePath, $this->buildThumbnailSizes($settings), $quality);
            if ($thumbnailResult instanceof WP_Error) {
                $this->logger->warning('Bild-Thumbnails konnten nach dem Upload nicht erzeugt werden.', [
                    'path' => $absolutePath,
                    'error_code' => $thumbnailResult->get_error_code(),
                    'error_message' => $thumbnailResult->get_error_message(),
                ]);
            }
        }

        if (!empty($settings['auto_webp'])) {
            $this->generateUploadWebpDerivative($absolutePath, $quality);
        }

        $this->applyGeneratedImageFileModes($absolutePath);
    }

    private function generateUploadWebpDerivative(string $absolutePath, int $quality): void
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return;
        }

        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return;
        }

        $webpPath = preg_replace('/\.[^.]+$/', '.webp', $absolutePath);
        if (!is_string($webpPath) || $webpPath === '' || $webpPath === $absolutePath) {
            return;
        }

        clearstatcache(true, $absolutePath);
        $sourceSize = (int) (filesize($absolutePath) ?: 0);

        if (is_file($webpPath)) {
            clearstatcache(true, $webpPath);
            $webpTime = (int) (filemtime($webpPath) ?: 0);
            $sourceTime = (int) (filemtime($absolutePath) ?: 0);
            if ($webpTime >= $sourceTime) {
                $existingWebpSize = (int) (filesize($webpPath) ?: 0);
                if ($sourceSize > 0 && $existingWebpSize > 0 && $existingWebpSize >= $sourceSize) {
                    @unlink($webpPath);
                }

                return;
            }
        }

        $webpResult = $this->imageProcessor->convertToWebP($absolutePath, $quality);
        if ($webpResult instanceof WP_Error) {
            $this->logger->warning('WebP-Begleitdatei konnte nach dem Upload nicht erzeugt werden.', [
                'path' => $absolutePath,
                'error_code' => $webpResult->get_error_code(),
                'error_message' => $webpResult->get_error_message(),
            ]);

            return;
        }

        clearstatcache(true, $webpPath);
        $webpSize = is_file($webpPath) ? (int) (filesize($webpPath) ?: 0) : 0;
        if ($sourceSize > 0 && $webpSize > 0 && $webpSize >= $sourceSize) {
            @unlink($webpPath);
        }
    }

    private function readImageSize(string $path, string $context = 'image'): ?array
    {
        $result = $this->runImageOperation('getimagesize', $path, $context, static fn (): array|false => getimagesize($path));

        return is_array($result) ? $result : null;
    }

    private function loadGdImage(string $path, string $ext): \GdImage|false
    {
        $loader = match ($ext) {
            'jpg', 'jpeg' => 'imagecreatefromjpeg',
            'png' => 'imagecreatefrompng',
            'gif' => 'imagecreatefromgif',
            'webp' => 'imagecreatefromwebp',
            'bmp' => 'imagecreatefrombmp',
            default => null,
        };

        if ($loader === null || !function_exists($loader) || !is_file($path) || !is_readable($path)) {
            return false;
        }

        $result = $this->runImageOperation($loader, $path, 'reencode', static fn () => $loader($path));

        return $result instanceof \GdImage ? $result : false;
    }

    private function runImageOperation(string $operation, string $path, string $context, callable $callback): mixed
    {
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        if (($result === false || $result === null) && $warning !== null) {
            $this->logger->warning('Medien-Bildoperation fehlgeschlagen.', [
                'operation' => $operation,
                'path' => $path,
                'context' => $context,
                'warning' => $warning,
            ]);
        }

        return $result;
    }

    /**
     * Get Media Settings
     * 
     * @return array
     */
    public function getSettings(): array {
        $defaults = $this->getDefaultSettings();

        if (!file_exists($this->settingsFile)) {
            return $defaults;
        }

        $content = file_get_contents($this->settingsFile);
        if (!$content) {
            return $defaults;
        }

        $settings = Json::decodeArray($content, []);
        return $this->normalizeSettings(is_array($settings) ? $settings : []);
    }

    /**
     * Save Media Settings
     * 
     * @param array $settings
     * @return bool|WP_Error
     */
    public function saveSettings(array $settings): bool|WP_Error {
        $settings = $this->normalizeSettings($settings);
        $protectionResult = $this->syncUploadsProtection((bool)($settings['protect_uploads_dir'] ?? false));
        if ($protectionResult instanceof WP_Error) {
            return $protectionResult;
        }
        
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return new WP_Error('json_error', 'Invalid settings data');
        }

        if (!$this->writeFileAtomically($this->settingsFile, $json . "\n")) {
            return new WP_Error('write_error', 'Could not write settings file');
        }

        return true;
    }

    /**
     * List items in a directory
     * 
     * @param string $path Relative path from UPLOAD_PATH
     * @return array|WP_Error Array of items or WP_Error on failure
     */
    public function getItems(string $path = ''): array|WP_Error {
        return $this->repository->getItems($path);
    }

    /**
     * Read-only recursive inventory of managed media files.
     *
     * The result is intentionally capped so admin analysis views stay bounded
     * even on large installations.
     *
     * @return list<array<string,mixed>>
     */
    public function collectManagedFileInventory(int $limit = 5000): array
    {
        $limit = max(1, min(10000, $limit));
        $root = rtrim($this->uploadPath, '/\\');

        if (!is_dir($root) || !is_readable($root)) {
            return [];
        }

        $meta = $this->repository->loadMeta();
        $inventory = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Medien-Inventur konnte nicht initialisiert werden.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';

        foreach ($iterator as $item) {
            if (count($inventory) >= $limit) {
                break;
            }

            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }

            $absolutePath = $item->getPathname();
            if (!is_readable($absolutePath)) {
                continue;
            }

            $normalizedAbsolutePath = str_replace('\\', '/', $absolutePath);
            if (!str_starts_with($normalizedAbsolutePath, $normalizedRoot)) {
                continue;
            }

            $relativePath = ltrim(substr($normalizedAbsolutePath, strlen($normalizedRoot)), '/');
            $normalizedPath = $this->normalizeManagedRelativePath($relativePath);
            if ($normalizedPath === '') {
                continue;
            }

            $metaData = is_array($meta['files'][$normalizedPath] ?? null) ? $meta['files'][$normalizedPath] : [];
            $category = (string) ($metaData['category'] ?? $this->repository->detectSystemCategory($normalizedPath) ?? '');
            $size = $item->getSize();
            $modified = $item->getMTime();

            $inventory[] = [
                'name' => basename($normalizedPath),
                'path' => $normalizedPath,
                'url' => $this->repository->buildPublicUrl($normalizedPath),
                'preview_url' => $this->repository->buildPreviewUrl($normalizedPath),
                'type' => strtolower((string) pathinfo($normalizedPath, PATHINFO_EXTENSION)),
                'size' => $size >= 0 ? (int) $size : 0,
                'modified' => $modified > 0 ? (int) $modified : null,
                'category' => $category,
                'tags' => array_values(array_filter(array_map('strval', (array) ($metaData['tags'] ?? [])))),
                'uploaded_at' => trim((string) ($metaData['uploaded_at'] ?? '')),
                'uploaded_by' => trim((string) ($metaData['uploaded_by'] ?? '')),
                'original_name' => trim((string) ($metaData['original_name'] ?? '')),
                'is_system' => $this->repository->isSystemPath($normalizedPath),
            ];
        }

        return $inventory;
    }

    /**
     * Findet eine bereits verwaltete Datei mit identischem Inhalt zu einer eingehenden Upload-Datei.
     *
     * Der Pfad bleibt bewusst read-only: Es wird nichts verschoben, gelöscht oder umgeschrieben. Aufrufer
     * können die gefundene Medienreferenz direkt wiederverwenden und dadurch doppelte physische Dateien
     * vermeiden.
     *
     * @param array<string,mixed> $file
     * @param array<int,string> $candidatePrefixes Relative Ordnerpräfixe, die durchsucht werden sollen. Leer = alle verwalteten Dateien.
     * @param array<int,string> $excludedPrefixes Relative Ordnerpräfixe, die übersprungen werden sollen.
     * @param array<int,string> $candidateFilenamePrefixes Optionale Dateinamenspräfixe für zusätzliche Eingrenzung.
     * @return array{name:string,path:string,size:int,type:string}|null
     */
    public function findManagedDuplicateForUpload(array $file, array $candidatePrefixes = [], array $excludedPrefixes = [], array $candidateFilenamePrefixes = []): ?array
    {
        $sourcePath = (string) ($file['tmp_name'] ?? '');
        if ($sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath)) {
            return null;
        }

        $sourceSize = filesize($sourcePath);
        if ($sourceSize === false || $sourceSize <= 0 || $sourceSize > self::DUPLICATE_HASH_MAX_BYTES) {
            return null;
        }

        $sourceHash = @hash_file('sha256', $sourcePath);
        if (!is_string($sourceHash) || $sourceHash === '') {
            return null;
        }

        $root = rtrim($this->uploadPath, '/\\');
        if (!is_dir($root) || !is_readable($root)) {
            return null;
        }

        $candidatePrefixes = $this->normalizeManagedRelativePrefixes($candidatePrefixes);
        $excludedPrefixes = $this->normalizeManagedRelativePrefixes($excludedPrefixes);
        $candidateFilenamePrefixes = $this->normalizeFilenamePrefixes($candidateFilenamePrefixes);
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Medien-Duplikatsuche konnte nicht initialisiert werden.', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile() || !$item->isReadable()) {
                continue;
            }

            $absolutePath = $item->getPathname();
            $normalizedAbsolutePath = str_replace('\\', '/', $absolutePath);
            if (!str_starts_with($normalizedAbsolutePath, $normalizedRoot)) {
                continue;
            }

            $relativePath = ltrim(substr($normalizedAbsolutePath, strlen($normalizedRoot)), '/');
            $normalizedPath = $this->normalizeManagedRelativePath($relativePath);
            if ($normalizedPath === '' || $this->containsHiddenManagedSegment($normalizedPath)) {
                continue;
            }

            if ($this->pathMatchesManagedPrefixes($normalizedPath, $excludedPrefixes)) {
                continue;
            }

            if ($candidatePrefixes !== [] && !$this->pathMatchesManagedPrefixes($normalizedPath, $candidatePrefixes)) {
                continue;
            }

            if ($candidateFilenamePrefixes !== [] && !$this->filenameMatchesPrefixes(basename($normalizedPath), $candidateFilenamePrefixes)) {
                continue;
            }

            if ((int) $item->getSize() !== (int) $sourceSize) {
                continue;
            }

            $candidateHash = @hash_file('sha256', $absolutePath);
            if (!is_string($candidateHash) || !hash_equals($sourceHash, $candidateHash)) {
                continue;
            }

            $mimeType = function_exists('mime_content_type') ? @mime_content_type($absolutePath) : '';

            return [
                'name' => basename($normalizedPath),
                'path' => $normalizedPath,
                'size' => (int) $sourceSize,
                'type' => is_string($mimeType) ? $mimeType : '',
            ];
        }

        return null;
    }

    /**
     * Create a new folder
     */
    public function createFolder(string $name, string $parentPath = ''): bool|WP_Error {
        return $this->uploadHandler->createFolder($parentPath, $name);
    }

    /**
     * Upload a file
     */
    public function uploadFile(array $file, string $targetPath = '', ?array $validationSettings = null, bool $preserveOriginalImage = false): string|WP_Error {
        $settings = $this->getSettings();
        $result = $this->uploadHandler->uploadFile(
            $file,
            $targetPath,
            false,
            $validationSettings
        );
        if ($result instanceof WP_Error) {
            return $result;
        }

        $storedName = (string) ($result['name'] ?? '');
        $storedRelativePath = trim(($targetPath !== '' ? trim($targetPath, '/\\') . '/' : '') . $storedName, '/');
        $storedAbsolutePath = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedRelativePath);

        if (!$preserveOriginalImage) {
            $this->repairUploadedBrowserImage($storedAbsolutePath, $storedName, $settings);
        }

        if (!empty($settings['auto_webp'])) {
            $quality = max(60, min(100, (int) ($settings['jpeg_quality'] ?? 85)));
            $this->generateUploadWebpDerivative($storedAbsolutePath, $quality);
        }

        $this->applyGeneratedImageFileModes($storedAbsolutePath);

        return $storedName;
    }

    /**
     * Verwalteter Medien-Upload für Bibliothek und Member-Bereich.
     * Wendet Pfad-Organisation, Dateinamensregeln, Maximalmaße und Thumbnails an.
     *
     * @param array<string,mixed> $file
     * @return array<string,mixed>|WP_Error
     */
    public function uploadManagedFile(array $file, string $targetPath = '', bool $memberContext = false): array|WP_Error
    {
        $settings = $this->buildUploadValidationSettings($this->getSettings(), $memberContext);
        $result = $this->uploadHandler->uploadFile($file, $targetPath, false, $settings);

        if ($result instanceof WP_Error) {
            return $result;
        }

        $storedRelativePath = trim((string) ($result['path'] ?? ''), '/');
        if ($storedRelativePath === '') {
            $storedRelativePath = trim(($targetPath !== '' ? trim($targetPath, '/\\') . '/' : '') . (string) ($result['name'] ?? ''), '/');
            $result['path'] = $storedRelativePath;
        }

        $storedAbsolutePath = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedRelativePath);
        $this->processManagedImageUpload($storedAbsolutePath, (string) ($result['name'] ?? basename($storedRelativePath)), $settings, true);
        $this->applyManagedMediaFileMode($storedAbsolutePath, $storedRelativePath);

        return $result;
    }

    /**
     * Ersetzt eine bestehende Bilddatei unter derselben Medien-Referenz.
     * Dadurch bleiben alle Verwendungen in Beiträgen und Seiten unverändert gültig.
     *
     * @param array<string,mixed> $file
     * @return array<string,mixed>|WP_Error
     */
    public function replaceManagedFile(array $file, string $targetRelativePath): array|WP_Error
    {
        $normalizedTargetPath = $this->normalizeManagedRelativePath($targetRelativePath);
        if ($normalizedTargetPath === '') {
            return new WP_Error('invalid_target_path', 'Der Zielpfad für den Bildersatz ist ungültig.');
        }

        $targetExtension = strtolower((string) pathinfo($normalizedTargetPath, PATHINFO_EXTENSION));
        if (!$this->isImageExtension($targetExtension)) {
            return new WP_Error('invalid_target_type', 'Es können nur vorhandene Bilddateien ersetzt werden.');
        }

        $settings = $this->buildFeaturedReplacementValidationSettings($this->getSettings());
        $validation = $this->validateFile($file, $settings);
        if ($validation instanceof WP_Error) {
            return $validation;
        }

        $sourceExtension = strtolower((string) ($validation['ext'] ?? ''));
        if (!$this->isImageExtension($sourceExtension)) {
            return new WP_Error('invalid_replacement_type', 'Bitte eine gültige Bilddatei zum Ersetzen hochladen.');
        }

        $targetAbsolutePath = $this->resolveManagedAbsolutePath($normalizedTargetPath);
        if ($targetAbsolutePath instanceof WP_Error) {
            return $targetAbsolutePath;
        }

        $targetDirectory = dirname($targetAbsolutePath);
        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
            return new WP_Error('target_dir_missing', 'Das Zielverzeichnis für den Bildersatz konnte nicht erstellt werden.');
        }

        if (!is_writable($targetDirectory)) {
            return new WP_Error('target_dir_not_writable', 'Das Zielverzeichnis für den Bildersatz ist nicht beschreibbar.');
        }

        $backupPath = null;
        if (is_file($targetAbsolutePath)) {
            $backupPath = $targetAbsolutePath . '.replace.' . str_replace('.', '', uniqid('', true));
            if (!@rename($targetAbsolutePath, $backupPath)) {
                return new WP_Error('backup_failed', 'Die bestehende Bilddatei konnte vor dem Ersetzen nicht gesichert werden.');
            }
        }

        try {
            $quality = max(60, min(100, (int) ($settings['jpeg_quality'] ?? 85)));
            $replacementStored = $this->writeReplacementImage($file, $sourceExtension, $targetAbsolutePath, $targetExtension, $quality);
            if (!$replacementStored) {
                $this->restoreManagedReplacementBackup($backupPath, $targetAbsolutePath);

                return new WP_Error('replacement_write_failed', 'Die neue Bilddatei konnte nicht gespeichert werden.');
            }

            $this->applyManagedMediaFileMode($targetAbsolutePath, $normalizedTargetPath);
            $this->processManagedImageUpload($targetAbsolutePath, basename($normalizedTargetPath), $settings, true);
            $this->syncReplacedFileMeta($normalizedTargetPath, (string) ($file['name'] ?? basename($normalizedTargetPath)));

            if ($backupPath !== null && is_file($backupPath)) {
                @unlink($backupPath);
            }

            return [
                'path' => $normalizedTargetPath,
                'name' => basename($normalizedTargetPath),
                'url' => MediaDeliveryService::getInstance()->buildAccessUrl($normalizedTargetPath, true),
                'preview_url' => MediaDeliveryService::getInstance()->buildPreviewUrl($normalizedTargetPath),
            ];
        } catch (\Throwable $exception) {
            $this->restoreManagedReplacementBackup($backupPath, $targetAbsolutePath);
            $this->logger->warning('Bildersatz in der Medienverwaltung fehlgeschlagen.', [
                'path' => $normalizedTargetPath,
                'exception' => $exception->getMessage(),
            ]);

            return new WP_Error('replacement_failed', 'Die Bilddatei konnte nicht ersetzt werden.');
        }
    }

    /**
     * Ensure a custom category exists (creates it if missing).
     * Returns the slug on success.
     */
    public function ensureCategory(string $name, string $slug): string
    {
        return $this->repository->ensureCategory($name, $slug);
    }

    /**
     * Move a file to a new relative path inside the upload directory.
     * Updates meta (keeps category, uploader etc.) and updates thumbnail variants.
     * Returns the new relative path (forward slashes) or WP_Error.
     */
    public function moveFile(string $relativeOldPath, string $relativeNewPath): string|WP_Error
    {
        $result = $this->uploadHandler->moveFile($relativeOldPath, $relativeNewPath);
        return $result instanceof WP_Error ? $result : trim(str_replace('\\', '/', $relativeNewPath), '/');
    }

    public function pathExists(string $relativePath): bool
    {
        return $this->repository->pathExists($relativePath);
    }

    public function directoryExists(string $relativePath): bool
    {
        return $this->repository->isDirectory($relativePath);
    }

    public function isProtectedPath(string $relativePath): bool
    {
        return $this->repository->isSystemPath($relativePath);
    }

    /**
     * Delete an item (file or folder)
     */
    public function deleteItem(string $path): bool|WP_Error {
        return $this->uploadHandler->deleteItem($path);
    }

    /**
     * Rename an item
     */
    public function renameItem(string $oldPath, string $newName): bool|WP_Error {
        return $this->uploadHandler->renameItem($oldPath, $newName);
    }

    /**
     * Build a read-only duplicate map for the provided managed media paths.
     *
     * Files are grouped by byte size first and only same-sized candidates are
     * hashed with SHA-256. This keeps the library view deterministic without
     * hashing every visible file unnecessarily.
     *
     * @param array<int, string> $relativePaths
     * @return array<string, array{group_id:string,hash:string,short_hash:string,duplicate_count:int,duplicate_paths:list<string>,size:int}>
     */
    public function buildDuplicateHashMap(array $relativePaths): array
    {
        $candidatesBySize = [];
        $seenPaths = [];

        foreach ($relativePaths as $relativePath) {
            $normalizedPath = $this->normalizeManagedRelativePath((string) $relativePath);
            if ($normalizedPath === '' || isset($seenPaths[$normalizedPath])) {
                continue;
            }
            $seenPaths[$normalizedPath] = true;

            $absolutePath = $this->resolveManagedAbsolutePath($normalizedPath);
            if ($absolutePath instanceof WP_Error || !is_file($absolutePath) || !is_readable($absolutePath)) {
                continue;
            }

            $fileSize = filesize($absolutePath);
            if ($fileSize === false || $fileSize < 0) {
                continue;
            }

            if ($fileSize > self::DUPLICATE_HASH_MAX_BYTES) {
                continue;
            }

            $candidatesBySize[(string) $fileSize][] = [
                'path' => $normalizedPath,
                'absolute_path' => $absolutePath,
                'size' => (int) $fileSize,
            ];
        }

        $duplicates = [];
        $groupNumber = 0;

        foreach ($candidatesBySize as $candidates) {
            if (count($candidates) < 2) {
                continue;
            }

            $candidatesByHash = [];
            foreach ($candidates as $candidate) {
                $hash = @hash_file('sha256', (string) $candidate['absolute_path']);
                if (!is_string($hash) || $hash === '') {
                    continue;
                }

                $candidatesByHash[$hash][] = $candidate;
            }

            foreach ($candidatesByHash as $hash => $hashGroup) {
                if (count($hashGroup) < 2) {
                    continue;
                }

                $groupNumber++;
                $paths = array_map(
                    static fn (array $candidate): string => (string) $candidate['path'],
                    $hashGroup
                );

                foreach ($hashGroup as $candidate) {
                    $path = (string) $candidate['path'];
                    $duplicates[$path] = [
                        'group_id' => 'sha256-' . $groupNumber,
                        'hash' => $hash,
                        'short_hash' => substr($hash, 0, 12),
                        'duplicate_count' => count($hashGroup),
                        'duplicate_paths' => array_values(array_filter(
                            $paths,
                            static fn (string $otherPath): bool => $otherPath !== $path
                        )),
                        'size' => (int) $candidate['size'],
                    ];
                }
            }
        }

        return $duplicates;
    }

    /**
     * Collect managed source images that can be processed in a chunked derivative job.
     *
     * Generated thumbnail variants are skipped to avoid recursive `-small-small` files.
     * The result is intentionally capped so the job state stays small and predictable.
     *
     * @return list<string>
     */
    public function collectImageDerivativeProcessingCandidates(int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        $root = rtrim($this->uploadPath, '/\\');
        if (!is_dir($root) || !is_readable($root)) {
            return [];
        }

        $paths = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Medien-Derivat-Kandidaten konnten nicht gelesen werden.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }

        foreach ($iterator as $item) {
            if (count($paths) >= $limit) {
                break;
            }

            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }

            $absolutePath = $item->getPathname();
            if (!is_readable($absolutePath)) {
                continue;
            }

            $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
            if (!in_array($extension, self::DERIVATIVE_PROCESSING_IMAGE_EXTENSIONS, true) || $this->isGeneratedDerivativeVariant($absolutePath)) {
                continue;
            }

            $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
            $normalizedAbsolutePath = str_replace('\\', '/', $absolutePath);
            if (!str_starts_with($normalizedAbsolutePath, $normalizedRoot)) {
                continue;
            }

            $relativePath = ltrim(substr($normalizedAbsolutePath, strlen($normalizedRoot)), '/');
            $normalizedPath = $this->normalizeManagedRelativePath($relativePath);
            if ($normalizedPath === '') {
                continue;
            }

            $paths[] = $normalizedPath;
        }

        sort($paths, SORT_NATURAL);

        return array_values(array_unique($paths));
    }

    /**
     * Process one image derivative job item. Designed for small chunks to avoid timeouts.
     *
     * @return array{path:string,status:string,detail:string,webp:string,thumbnails:string}
     */
    public function processImageDerivativeJobItem(string $relativePath, bool $generateWebp, bool $generateThumbnails): array
    {
        $normalizedPath = $this->normalizeManagedRelativePath($relativePath);
        if ($normalizedPath === '') {
            return [
                'path' => '',
                'status' => 'failed',
                'detail' => 'Ungültiger Medienpfad.',
                'webp' => 'skipped',
                'thumbnails' => 'skipped',
            ];
        }

        if (!$generateWebp && !$generateThumbnails) {
            return [
                'path' => $normalizedPath,
                'status' => 'skipped',
                'detail' => 'Keine Derivat-Aktion ausgewählt.',
                'webp' => 'skipped',
                'thumbnails' => 'skipped',
            ];
        }

        $absolutePath = $this->resolveManagedAbsolutePath($normalizedPath);
        if ($absolutePath instanceof WP_Error || !is_file($absolutePath) || !is_readable($absolutePath)) {
            return [
                'path' => $normalizedPath,
                'status' => 'failed',
                'detail' => 'Datei nicht lesbar oder nicht vorhanden.',
                'webp' => 'skipped',
                'thumbnails' => 'skipped',
            ];
        }

        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::DERIVATIVE_PROCESSING_IMAGE_EXTENSIONS, true) || $this->isGeneratedDerivativeVariant($absolutePath)) {
            return [
                'path' => $normalizedPath,
                'status' => 'skipped',
                'detail' => 'Nicht unterstütztes Bild oder bereits erzeugtes Derivat.',
                'webp' => 'skipped',
                'thumbnails' => 'skipped',
            ];
        }

        $settings = $this->getSettings();
        $quality = max(60, min(100, (int) ($settings['jpeg_quality'] ?? 85)));
        $webpStatus = 'skipped';
        $thumbnailStatus = 'skipped';
        $errors = [];

        if ($generateThumbnails) {
            $thumbnailResult = $this->imageProcessor->generateThumbnails($absolutePath, $this->buildThumbnailSizes($settings), $quality);
            if ($thumbnailResult instanceof WP_Error) {
                $thumbnailStatus = 'failed';
                $errors[] = 'Thumbnails: ' . $thumbnailResult->get_error_message();
            } else {
                $thumbnailStatus = 'done';
            }
        }

        if ($generateWebp) {
            if ($extension === 'webp') {
                $webpStatus = 'skipped';
            } else {
                $webpResult = $this->imageProcessor->convertToWebP($absolutePath, $quality);
                if ($webpResult instanceof WP_Error) {
                    $webpStatus = 'failed';
                    $errors[] = 'WebP: ' . $webpResult->get_error_message();
                } else {
                    $webpStatus = 'done';
                }
            }
        }

        $this->applyGeneratedImageFileModes($absolutePath);

        if ($errors !== []) {
            $this->logger->warning('Medien-Derivat-Job konnte ein Bild nicht vollständig verarbeiten.', [
                'path' => $normalizedPath,
                'errors' => $errors,
            ]);

            return [
                'path' => $normalizedPath,
                'status' => 'failed',
                'detail' => implode('; ', array_slice($errors, 0, 2)),
                'webp' => $webpStatus,
                'thumbnails' => $thumbnailStatus,
            ];
        }

        $didWork = $webpStatus === 'done' || $thumbnailStatus === 'done';

        return [
            'path' => $normalizedPath,
            'status' => $didWork ? 'processed' : 'skipped',
            'detail' => $didWork ? 'Derivate erzeugt.' : 'Keine passende Derivat-Aktion notwendig.',
            'webp' => $webpStatus,
            'thumbnails' => $thumbnailStatus,
        ];
    }

    private function isGeneratedDerivativeVariant(string $path): bool
    {
        $filename = (string) pathinfo($path, PATHINFO_FILENAME);
        foreach (self::GENERATED_VARIANT_SUFFIXES as $suffix) {
            if (str_ends_with($filename, '-' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get disk usage
     */
    public function getDiskUsage(): array {
        return $this->repository->getDiskUsage();
    }

    public function formatSize(int $bytes): string {
        return $this->repository->formatSize($bytes);
    }

    /**
     * M-07: Konvertiert ein Bild (JPG/PNG/GIF) in WebP und speichert die neue Datei.
     *
     * Voraussetzungen: PHP-GD-Extension mit WebP-Support.
     * Gibt den Pfad zur WebP-Datei zurück oder null bei Fehler/nicht unterstützt.
     *
     * @param  string $sourcePath  Absoluter Pfad zur Quelldatei (bereits gespeichert)
     * @param  string $ext         Dateiendung (jpg|jpeg|png|gif)
     * @param  int    $quality     WebP-Qualität 0–100 (Standard: 82)
     * @return string|null         Absoluter Pfad zur .webp-Datei oder null
     */
    public function convertToWebP(string $sourcePath, string $ext, int $quality = 82): ?string
    {
        $result = $this->imageProcessor->convertToWebP($sourcePath, $quality);
        if ($result instanceof WP_Error) {
            return null;
        }

        $webpPath = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '.webp', $sourcePath);
        return is_string($webpPath) ? $webpPath : null;
    }

    private function writeFileAtomically(string $path, string $content): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        $tempPath = tempnam($directory, 'cmsmedia_');
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
