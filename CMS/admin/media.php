<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Media – Entry Point
 * Route: /admin/media
 *
 * Tabs: library (Standard), featured, check, categories, settings
 */

use CMS\Auth;
use CMS\Security;

const CMS_ADMIN_MEDIA_ALLOWED_ACTIONS = [
    'upload',
    'replace_item',
    'replace_items',
    'create_folder',
    'delete_item',
    'rename_item',
    'move_item',
    'bulk_items',
    'assign_category',
    'add_category',
    'delete_category',
    'save_settings',
    'start_media_processing_job',
    'process_media_processing_job',
    'cancel_media_processing_job',
];

const CMS_ADMIN_MEDIA_WRITE_CAPABILITY = 'manage_media';
const CMS_ADMIN_MEDIA_MAX_UPLOAD_FILES = 20;
const CMS_ADMIN_MEDIA_MAX_UPLOAD_FILENAME_LENGTH = 180;
const CMS_ADMIN_MEDIA_MAX_UPLOAD_BATCH_BYTES = 104857600;
const CMS_ADMIN_MEDIA_ROUTE_PATH = '/admin/media';

const CMS_ADMIN_MEDIA_SETTINGS_INT_FIELDS = [
    'jpeg_quality',
    'avif_quality',
    'avif_speed',
    'max_width',
    'max_height',
    'thumbnail_small_w',
    'thumbnail_small_h',
    'thumbnail_medium_w',
    'thumbnail_medium_h',
    'thumbnail_large_w',
    'thumbnail_large_h',
    'thumbnail_banner_w',
    'thumbnail_banner_h',
];

const CMS_ADMIN_MEDIA_SETTINGS_SIZE_FIELDS = [
    'max_upload_size',
    'member_max_upload_size',
];

const CMS_ADMIN_MEDIA_SETTINGS_BOOLEAN_FIELDS = [
    'auto_webp',
    'auto_avif',
    'strip_exif',
    'organize_month_year',
    'sanitize_filename',
    'unique_filename',
    'lowercase_filename',
    'member_uploads_enabled',
    'member_delete_own',
    'generate_thumbnails',
    'block_dangerous_types',
    'validate_image_content',
    'require_login_for_upload',
    'protect_uploads_dir',
];

const CMS_ADMIN_MEDIA_SETTINGS_EXTENSION_LIST_FIELDS = [
    'allowed_types',
    'member_allowed_types',
];

function cms_admin_media_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_MEDIA_WRITE_CAPABILITY);
}

if (!cms_admin_media_can_access()) {
    header('Location: /');
    exit;
}

function cms_admin_media_normalize_action(mixed $action): string
{
    $normalizedAction = strtolower(trim((string) $action));

    return in_array($normalizedAction, CMS_ADMIN_MEDIA_ALLOWED_ACTIONS, true) ? $normalizedAction : '';
}

function cms_admin_media_can_run_action(string $action): bool
{
    return $action !== '' && Auth::instance()->hasCapability(CMS_ADMIN_MEDIA_WRITE_CAPABILITY);
}

/**
 * @param list<string> $details
 * @param list<string> $errorDetails
 * @param array<string, mixed> $reportPayload
 * @return array<string, mixed>
 */
function cms_admin_media_build_failure_result(
    string $message,
    array $details = [],
    array $errorDetails = [],
    array $reportPayload = []
): array {
    return [
        'success' => false,
        'error' => $message,
        'details' => array_values(array_filter(array_map(
            static fn (mixed $detail): string => trim((string) $detail),
            $details
        ), static fn (string $detail): bool => $detail !== '')),
        'error_details' => array_values(array_filter(array_map(
            static fn (mixed $detail): string => trim((string) $detail),
            $errorDetails
        ), static fn (string $detail): bool => $detail !== '')),
        'report_payload' => $reportPayload,
    ];
}

function cms_admin_media_resolve_path(MediaModule $module, array $post): string
{
    $path = (string)($post['parent_path'] ?? $post['target_path'] ?? '');

    if ($path !== '') {
        return $module->normalizePath($path);
    }

    $actionPath = (string)($post['item_path'] ?? $post['old_path'] ?? $post['file_path'] ?? '');
    if ($actionPath === '') {
        return '';
    }

    return $module->resolveParentPathFromActionPath($actionPath);
}

function cms_admin_media_normalize_upload_error_label(mixed $value): string
{
    $label = trim((string) $value);
    $label = preg_replace('/[\x00-\x1F\x7F]+/u', '', $label) ?? '';

    if ($label === '') {
        return 'Datei';
    }

    return function_exists('mb_substr') ? mb_substr($label, 0, 120) : substr($label, 0, 120);
}

function cms_admin_media_normalize_text(mixed $value, int $maxLength = 120): string
{
    $normalizedValue = trim((string) $value);
    $normalizedValue = preg_replace('/[\x00-\x1F\x7F]+/u', '', $normalizedValue) ?? '';

    return function_exists('mb_substr')
        ? mb_substr($normalizedValue, 0, $maxLength)
        : substr($normalizedValue, 0, $maxLength);
}

function cms_admin_media_normalize_int(mixed $value, int $min, int $max, int $fallback): int
{
    $normalized = filter_var($value, FILTER_VALIDATE_INT);
    if ($normalized === false) {
        return $fallback;
    }

    return max($min, min($max, (int) $normalized));
}

/**
 * @param mixed $extensions
 * @return list<string>
 */
function cms_admin_media_normalize_extensions(mixed $extensions): array
{
    if (!is_array($extensions)) {
        return [];
    }

    $normalizedExtensions = [];

    foreach ($extensions as $extension) {
        $normalizedExtension = strtolower(trim((string) $extension));
        if ($normalizedExtension === '' || preg_match('/^[a-z0-9]{1,10}$/', $normalizedExtension) !== 1) {
            continue;
        }

        $normalizedExtensions[$normalizedExtension] = true;
    }

    return array_keys($normalizedExtensions);
}

/**
 * @param mixed $paths
 * @return list<string>
 */
function cms_admin_media_normalize_paths(MediaModule $module, mixed $paths): array
{
    if (!is_array($paths)) {
        return [];
    }

    $normalizedPaths = [];

    foreach ($paths as $path) {
        $normalizedPath = $module->normalizePath((string) $path);
        if ($normalizedPath === '') {
            continue;
        }

        $normalizedPaths[$normalizedPath] = true;
    }

    return array_keys($normalizedPaths);
}

/**
 * @param mixed $paths
 * @return list<string>
 */
function cms_admin_media_normalize_ordered_paths(MediaModule $module, mixed $paths): array
{
    if (!is_array($paths)) {
        return [];
    }

    $normalizedPaths = [];

    foreach ($paths as $path) {
        $normalizedPaths[] = $module->normalizePath((string) $path);
    }

    return $normalizedPaths;
}

/**
 * @param mixed $altTexts
 * @return array<string, string>
 */
function cms_admin_media_normalize_alt_text_map(MediaModule $module, mixed $altTexts): array
{
    if (!is_array($altTexts)) {
        return [];
    }

    $normalized = [];

    foreach ($altTexts as $path => $value) {
        $normalizedPath = $module->normalizePath((string) $path);
        if ($normalizedPath === '') {
            continue;
        }

        $normalized[$normalizedPath] = cms_admin_media_normalize_text($value, 255);
    }

    return $normalized;
}

/**
 * @return array<string, mixed>
 */
function cms_admin_media_normalize_settings_payload(array $post): array
{
    $normalizedSettings = [];

    foreach (CMS_ADMIN_MEDIA_SETTINGS_SIZE_FIELDS as $field) {
        if (!array_key_exists($field, $post)) {
            continue;
        }

        $normalizedSettings[$field] = cms_admin_media_normalize_text($post[$field] ?? '', 16);
    }

    foreach (CMS_ADMIN_MEDIA_SETTINGS_INT_FIELDS as $field) {
        if (!array_key_exists($field, $post)) {
            continue;
        }

        $normalizedSettings[$field] = match ($field) {
            'jpeg_quality' => cms_admin_media_normalize_int($post[$field] ?? 85, 60, 100, 85),
            'avif_quality' => cms_admin_media_normalize_int($post[$field] ?? 50, 1, 100, 50),
            'avif_speed' => cms_admin_media_normalize_int($post[$field] ?? 6, 0, 10, 6),
            'max_width', 'max_height' => cms_admin_media_normalize_int($post[$field] ?? 2560, 1, 8000, 2560),
            'thumbnail_large_w', 'thumbnail_large_h', 'thumbnail_banner_w', 'thumbnail_banner_h' => cms_admin_media_normalize_int($post[$field] ?? 1024, 50, 6000, 1024),
            default => cms_admin_media_normalize_int($post[$field] ?? 150, 50, 4000, 150),
        };
    }

    foreach (CMS_ADMIN_MEDIA_SETTINGS_BOOLEAN_FIELDS as $field) {
        if (!array_key_exists($field, $post)) {
            continue;
        }

        $normalizedSettings[$field] = '1';
    }

    foreach (CMS_ADMIN_MEDIA_SETTINGS_EXTENSION_LIST_FIELDS as $field) {
        $normalizedSettings[$field] = cms_admin_media_normalize_extensions($post[$field] ?? []);
    }

    return $normalizedSettings;
}

/**
 * @return array<string, mixed>
 */
function cms_admin_media_normalize_action_payload(MediaModule $module, string $action, array $post): array
{
    return match ($action) {
        'upload' => [
            'target_path' => $module->normalizePath((string) ($post['target_path'] ?? '')),
        ],
        'replace_item' => [
            'item_path' => $module->normalizePath((string) ($post['item_path'] ?? '')),
        ],
        'replace_items' => [
            'item_paths' => cms_admin_media_normalize_ordered_paths($module, $post['item_paths'] ?? []),
        ],
        'create_folder' => [
            'parent_path' => $module->normalizePath((string) ($post['parent_path'] ?? '')),
            'folder_name' => cms_admin_media_normalize_text($post['folder_name'] ?? '', 120),
        ],
        'delete_item' => [
            'item_path' => $module->normalizePath((string) ($post['item_path'] ?? '')),
        ],
        'rename_item' => [
            'old_path' => $module->normalizePath((string) ($post['old_path'] ?? '')),
            'new_name' => cms_admin_media_normalize_text($post['new_name'] ?? '', 120),
        ],
        'move_item' => [
            'old_path' => $module->normalizePath((string) ($post['old_path'] ?? '')),
            'target_parent_path' => $module->normalizePath((string) ($post['target_parent_path'] ?? '')),
        ],
        'bulk_items' => [
            'bulk_action' => cms_admin_media_normalize_text($post['bulk_action'] ?? '', 40),
            'item_paths' => cms_admin_media_normalize_paths($module, $post['item_paths'] ?? []),
            'target_parent_path' => $module->normalizePath((string) ($post['target_parent_path'] ?? '')),
            'category_slug' => $module->normalizeCategory((string) ($post['category_slug'] ?? '')),
            'tag_list' => $module->normalizeTags($post['tag_list'] ?? ''),
            'alt_texts' => cms_admin_media_normalize_alt_text_map($module, $post['alt_texts'] ?? []),
        ],
        'assign_category' => [
            'file_path' => $module->normalizePath((string) ($post['file_path'] ?? '')),
            'category_slug' => $module->normalizeCategory((string) ($post['category_slug'] ?? '')),
        ],
        'add_category' => [
            'name' => cms_admin_media_normalize_text($post['name'] ?? '', 80),
            'slug' => $module->normalizeCategory((string) ($post['slug'] ?? '')),
        ],
        'delete_category' => [
            'slug' => $module->normalizeCategory((string) ($post['slug'] ?? '')),
        ],
        'start_media_processing_job' => [
            'processing_mode' => $module->normalizeProcessingMode((string) ($post['processing_mode'] ?? 'all')),
        ],
        'process_media_processing_job', 'cancel_media_processing_job' => [],
        'save_settings' => cms_admin_media_normalize_settings_payload($post),
        default => [],
    };
}

function cms_admin_media_validate_action_payload(string $action, array $payload): ?string
{
    return match ($action) {
        'replace_item' => ($payload['item_path'] ?? '') === '' ? 'Ungültiger Bildpfad.' : null,
        'replace_items' => array_values(array_filter((array) ($payload['item_paths'] ?? []), static fn (mixed $path): bool => trim((string) $path) !== '')) === [] ? 'Bitte mindestens einen gültigen Bildpfad übermitteln.' : null,
        'create_folder' => ($payload['folder_name'] ?? '') === '' ? 'Bitte einen gültigen Ordnernamen angeben.' : null,
        'delete_item' => ($payload['item_path'] ?? '') === '' ? 'Ungültiger Elementpfad.' : null,
        'rename_item' => ($payload['old_path'] ?? '') === ''
            ? 'Ungültiger Elementpfad.'
            : ((($payload['new_name'] ?? '') === '') ? 'Bitte einen gültigen Namen angeben.' : null),
        'move_item' => ($payload['old_path'] ?? '') === '' ? 'Ungültiger Elementpfad.' : null,
        'bulk_items' => ($payload['item_paths'] ?? []) === []
            ? 'Bitte mindestens ein Element auswählen.'
            : (function () use ($payload): ?string {
                $bulkAction = strtolower((string) ($payload['bulk_action'] ?? ''));
                if (!in_array($bulkAction, ['delete', 'move', 'assign_category', 'tag_add', 'tag_replace', 'tag_remove', 'tag_clear', 'alt_text_update'], true)) {
                    return 'Bitte eine gültige Bulk-Aktion wählen.';
                }

                if (in_array($bulkAction, ['tag_add', 'tag_replace', 'tag_remove'], true) && ($payload['tag_list'] ?? []) === []) {
                    return 'Bitte mindestens einen gültigen Tag angeben.';
                }

                if ($bulkAction === 'alt_text_update' && ($payload['alt_texts'] ?? []) === []) {
                    return 'Bitte mindestens einen Alt-Text-Wert angeben.';
                }

                return null;
            })(),
        'assign_category' => ($payload['file_path'] ?? '') === '' ? 'Ungültiger Dateipfad.' : null,
        'add_category' => ($payload['name'] ?? '') === '' ? 'Bitte einen gültigen Kategorienamen angeben.' : null,
        'delete_category' => ($payload['slug'] ?? '') === '' ? 'Bitte eine gültige Kategorie angeben.' : null,
        default => null,
    };
}

/**
 * @return array<string, string>
 */
function cms_admin_media_redirect_params(MediaModule $module, string $tab, string $path): array
{
    $redirectParams = [];

    if ($tab !== 'library') {
        $redirectParams['tab'] = $tab;
    }

    if ($path !== '') {
        $redirectParams['path'] = $path;
    } else {
        $normalizedGetPath = $module->normalizePath((string)($_GET['path'] ?? ''));
        if ($normalizedGetPath !== '') {
            $redirectParams['path'] = $normalizedGetPath;
        }
    }

    $normalizedView = $module->normalizeView((string)($_GET['view'] ?? 'list'));
    $normalizedCategory = $module->normalizeCategory((string)($_GET['category'] ?? ''));
    $normalizedSearch = $module->normalizeSearch((string)($_GET['q'] ?? ''));

    if ($normalizedView !== 'list') {
        $redirectParams['view'] = $normalizedView;
    }
    if ($normalizedCategory !== '') {
        $redirectParams['category'] = $normalizedCategory;
    }
    if ($normalizedSearch !== '') {
        $redirectParams['q'] = $normalizedSearch;
    }
    $normalizedUsageFilter = $module->normalizeUsageFilter((string)($_GET['usage_filter'] ?? 'all'));
    if ($normalizedUsageFilter !== 'all') {
        $redirectParams['usage_filter'] = $normalizedUsageFilter;
    }
    $normalizedFileType = $module->normalizeFileTypeFilter((string)($_GET['file_type'] ?? 'all'));
    if ($normalizedFileType !== 'all') {
        $redirectParams['file_type'] = $normalizedFileType;
    }
    $normalizedExtension = $module->normalizeExtensionFilter((string)($_GET['extension'] ?? ''));
    if ($normalizedExtension !== '') {
        $redirectParams['extension'] = $normalizedExtension;
    }
    $normalizedSizeFilter = $module->normalizeSizeFilter((string)($_GET['size_filter'] ?? 'all'));
    if ($normalizedSizeFilter !== 'all') {
        $redirectParams['size_filter'] = $normalizedSizeFilter;
    }
    $normalizedModifiedFilter = $module->normalizeModifiedFilter((string)($_GET['modified_filter'] ?? 'all'));
    if ($normalizedModifiedFilter !== 'all') {
        $redirectParams['modified_filter'] = $normalizedModifiedFilter;
    }
    $normalizedOrphanDays = $module->normalizeOrphanDays($_GET['orphan_days'] ?? 0);
    if ($normalizedOrphanDays > 0) {
        $redirectParams['orphan_days'] = (string) $normalizedOrphanDays;
    }
    $normalizedUsageScope = strtolower(trim((string) ($_GET['usage_scope'] ?? 'all')));
    if (in_array($normalizedUsageScope, ['posts', 'pages'], true)) {
        $redirectParams['usage_scope'] = $normalizedUsageScope;
    }
    if ((string)($_GET['confirm_member'] ?? '') === '1') {
        $redirectParams['confirm_member'] = '1';
    }

    return $redirectParams;
}

function cms_admin_media_build_featured_redirect_url(MediaModule $module, string $highlightPath = '', bool $markAsReplaced = false): string
{
    $redirectParams = cms_admin_media_redirect_params($module, 'featured', '');
    unset($redirectParams['path'], $redirectParams['confirm_member']);

    if ($highlightPath !== '') {
        $redirectParams['highlight'] = $module->normalizePath($highlightPath);
    }

    if ($markAsReplaced && $highlightPath !== '') {
        $redirectParams['replaced'] = '1';
    }

    return CMS_ADMIN_MEDIA_ROUTE_PATH . '?' . http_build_query($redirectParams);
}

function cms_admin_media_build_redirect_url(MediaModule $module, string $tab, string $path): string
{
    $redirectParams = cms_admin_media_redirect_params($module, $tab, $path);

    return CMS_ADMIN_MEDIA_ROUTE_PATH . (!empty($redirectParams) ? '?' . http_build_query($redirectParams) : '');
}

function cms_admin_media_action_redirect_path(MediaModule $module, string $action, string $tab, string $path): string
{
    if (in_array($action, ['replace_item', 'replace_items'], true) && $module->normalizeTab($tab) === 'featured') {
        return cms_admin_media_build_featured_redirect_url($module);
    }

    return match ($action) {
        'add_category', 'delete_category' => CMS_ADMIN_MEDIA_ROUTE_PATH . '?tab=categories',
        'save_settings', 'start_media_processing_job', 'process_media_processing_job', 'cancel_media_processing_job' => CMS_ADMIN_MEDIA_ROUTE_PATH . '?tab=settings',
        default => cms_admin_media_build_redirect_url($module, $tab, $path),
    };
}

/**
 * @return array{files:list<array{name:string,type:string,tmp_name:string,error:int,size:int}>, error?:string}
 */
function cms_admin_media_normalize_upload_batch(array $files, string $field = 'files'): array
{
    $payload = $files[$field] ?? null;
    if (!is_array($payload)) {
        return ['files' => []];
    }

    $requiredKeys = ['name', 'type', 'tmp_name', 'error', 'size'];
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $payload)) {
            return ['files' => [], 'error' => 'Upload-Daten sind unvollständig.'];
        }
    }

    $names = $payload['name'];

    if (!is_array($names)) {
        $singleName = cms_admin_media_normalize_text($payload['name'] ?? '', CMS_ADMIN_MEDIA_MAX_UPLOAD_FILENAME_LENGTH);
        if ($singleName === '') {
            return ['files' => [], 'error' => 'Der Upload-Dateiname ist ungültig.'];
        }

        return ['files' => [[
            'name' => $singleName,
            'type' => (string) $payload['type'],
            'tmp_name' => (string) $payload['tmp_name'],
            'error' => (int) $payload['error'],
            'size' => max(0, (int) $payload['size']),
        ]]];
    }

    $count = count($names);
    foreach ($requiredKeys as $key) {
        if (!is_array($payload[$key]) || count($payload[$key]) !== $count) {
            return ['files' => [], 'error' => 'Upload-Daten sind inkonsistent.'];
        }
    }

    $normalizedFiles = [];
    $totalBytes = 0;

    for ($index = 0; $index < $count; $index++) {
        $file = [
            'name' => cms_admin_media_normalize_text($payload['name'][$index] ?? '', CMS_ADMIN_MEDIA_MAX_UPLOAD_FILENAME_LENGTH),
            'type' => (string) $payload['type'][$index],
            'tmp_name' => (string) $payload['tmp_name'][$index],
            'error' => (int) $payload['error'][$index],
            'size' => max(0, (int) $payload['size'][$index]),
        ];

        if ($file['name'] === '' && $file['tmp_name'] === '' && $file['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($file['name'] === '') {
            return ['files' => [], 'error' => 'Mindestens ein Upload-Dateiname ist ungültig.'];
        }

        $totalBytes += $file['size'];
        $normalizedFiles[] = $file;
    }

    if (count($normalizedFiles) > CMS_ADMIN_MEDIA_MAX_UPLOAD_FILES) {
        return ['files' => [], 'error' => 'Es können maximal ' . CMS_ADMIN_MEDIA_MAX_UPLOAD_FILES . ' Dateien pro Upload verarbeitet werden.'];
    }

    if ($totalBytes > CMS_ADMIN_MEDIA_MAX_UPLOAD_BATCH_BYTES) {
        return ['files' => [], 'error' => 'Das Upload-Paket ist insgesamt zu groß.'];
    }

    return ['files' => $normalizedFiles];
}

/** @param list<string> $errors */
function cms_admin_media_format_upload_errors(array $errors): string
{
    if ($errors === []) {
        return '';
    }

    $visibleErrors = array_slice($errors, 0, 5);
    $message = implode(', ', $visibleErrors);
    $hiddenCount = count($errors) - count($visibleErrors);

    if ($hiddenCount > 0) {
        $message .= ' +' . $hiddenCount . ' weitere(s) Problem(e)';
    }

    return $message;
}

function cms_admin_media_handle_upload(MediaModule $module, string $path): array
{
    $uploadBatch = cms_admin_media_normalize_upload_batch($_FILES);
    if (isset($uploadBatch['error'])) {
        return [
            'type' => 'danger',
            'message' => (string) $uploadBatch['error'],
            'details' => ['Bitte Upload-Auswahl und Dateigrößen prüfen.'],
        ];
    }

    $files = $uploadBatch['files'];
    $uploaded = 0;
    $errors   = [];
    $reportPayload = [];
    $redirectTargetPath = $path;

    foreach ($files as $file) {
        $result = $module->uploadFile($file, $path);
        if (!empty($result['success'])) {
            $uploaded++;

            if (is_string($result['stored_parent_path'] ?? null)) {
                $redirectTargetPath = (string) $result['stored_parent_path'];
            }

            continue;
        }

        $errors[] = cms_admin_media_normalize_upload_error_label($file['name'] ?? 'Datei') . ': ' . trim((string) ($result['error'] ?? 'Fehler'));

        if ($reportPayload === [] && is_array($result['report_payload'] ?? null)) {
            $reportPayload = $result['report_payload'];
        }
    }

    if ($uploaded === 0 && $errors === []) {
        return [
            'type' => 'danger',
            'message' => 'Es wurden keine gültigen Upload-Dateien übermittelt.',
            'details' => ['Bitte mindestens eine gültige Upload-Datei auswählen.'],
        ];
    }

    $formattedErrors = cms_admin_media_format_upload_errors($errors);
    $detailErrors = array_slice($errors, 0, 5);

    if ($uploaded > 0) {
        return [
            'type' => 'success',
            'message' => $uploaded . ' Datei(en) hochgeladen.' . ($formattedErrors !== '' ? ' Fehler: ' . $formattedErrors : ''),
            'details' => $detailErrors,
            'report_payload' => $reportPayload,
            'redirect_path' => cms_admin_media_build_redirect_url($module, 'library', $redirectTargetPath),
        ];
    }

    return [
        'type' => 'danger',
        'message' => 'Upload fehlgeschlagen.' . ($formattedErrors !== '' ? ' ' . $formattedErrors : ''),
        'details' => $detailErrors,
        'report_payload' => $reportPayload,
    ];
}

function cms_admin_media_handle_action(MediaModule $module, string $action, string $tab, string $path, array $post): array
{
    $redirectPath = cms_admin_media_action_redirect_path($module, $action, $tab, $path);
    $uploadFlash = null;

    if ($action === 'upload') {
        $uploadFlash = cms_admin_media_handle_upload($module, $path);
        $redirectPath = is_string($uploadFlash['redirect_path'] ?? null)
            ? (string) $uploadFlash['redirect_path']
            : $redirectPath;
    }

    if ($action === 'replace_item') {
        $result = $module->replaceItem((string)($post['item_path'] ?? ''));
        if (!empty($result['success'])) {
            $redirectPath = cms_admin_media_build_featured_redirect_url(
                $module,
                (string) ($result['highlight_path'] ?? ($post['item_path'] ?? '')),
                true
            );
        }

        return [
            'result' => $result,
            'redirect_path' => $redirectPath,
        ];
    }

    if ($action === 'replace_items') {
        $uploadBatch = cms_admin_media_normalize_upload_batch($_FILES, 'replacement_files');
        if (isset($uploadBatch['error'])) {
            return [
                'result' => cms_admin_media_build_failure_result(
                    (string) $uploadBatch['error'],
                    ['Bitte die ausgewählten Ersatzbilder und Dateigrößen prüfen.'],
                    ['Der Mehrfach-Bildersatz enthielt ungültige Upload-Daten.'],
                    [
                        'title' => 'Mehrfach-Bildersatz fehlgeschlagen',
                        'source' => '/admin/media?tab=featured',
                        'status' => 'warning',
                        'context' => [
                            'module' => 'media',
                            'operation' => 'replace_items',
                        ],
                    ]
                ),
                'redirect_path' => $redirectPath,
            ];
        }

        $result = $module->replaceItems(
            is_array($post['item_paths'] ?? null) ? $post['item_paths'] : [],
            $uploadBatch['files']
        );

        if (!empty($result['success'])) {
            $redirectPath = cms_admin_media_build_featured_redirect_url(
                $module,
                (string) ($result['highlight_path'] ?? ''),
                (string) ($result['highlight_path'] ?? '') !== ''
            );
        }

        return [
            'result' => $result,
            'redirect_path' => $redirectPath,
        ];
    }

    return match ($action) {
        'upload' => [
            'flash' => $uploadFlash,
            'redirect_path' => $redirectPath,
        ],
        'create_folder' => [
            'result' => $module->createFolder(trim((string)($post['folder_name'] ?? '')), (string)($post['parent_path'] ?? '')),
            'redirect_path' => $redirectPath,
        ],
        'delete_item' => [
            'result' => $module->deleteItem((string)($post['item_path'] ?? '')),
            'redirect_path' => $redirectPath,
        ],
        'rename_item' => [
            'result' => $module->renameItem((string)($post['old_path'] ?? ''), trim((string)($post['new_name'] ?? ''))),
            'redirect_path' => $redirectPath,
        ],
        'move_item' => [
            'result' => $module->moveItem((string)($post['old_path'] ?? ''), (string)($post['target_parent_path'] ?? '')),
            'redirect_path' => $redirectPath,
        ],
        'bulk_items' => [
            'result' => $module->bulkItems(
                is_array($post['item_paths'] ?? null) ? $post['item_paths'] : [],
                (string)($post['bulk_action'] ?? ''),
                (string)($post['target_parent_path'] ?? ''),
                (string)($post['category_slug'] ?? ''),
                $post['tag_list'] ?? '',
                $post['alt_texts'] ?? []
            ),
            'redirect_path' => $redirectPath,
        ],
        'assign_category' => [
            'result' => $module->assignCategory((string)($post['file_path'] ?? ''), (string)($post['category_slug'] ?? '')),
            'redirect_path' => $redirectPath,
        ],
        'add_category' => [
            'result' => $module->addCategory(trim((string)($post['name'] ?? '')), trim((string)($post['slug'] ?? ''))),
            'redirect_path' => $redirectPath,
        ],
        'delete_category' => [
            'result' => $module->deleteCategory((string)($post['slug'] ?? '')),
            'redirect_path' => $redirectPath,
        ],
        'start_media_processing_job' => [
            'result' => $module->startMediaProcessingJob((string)($post['processing_mode'] ?? 'all')),
            'redirect_path' => $redirectPath,
        ],
        'process_media_processing_job' => [
            'result' => $module->processMediaProcessingJob(),
            'redirect_path' => $redirectPath,
        ],
        'cancel_media_processing_job' => [
            'result' => $module->cancelMediaProcessingJob(),
            'redirect_path' => $redirectPath,
        ],
        'save_settings' => [
            'result' => $module->saveSettings($post),
            'redirect_path' => $redirectPath,
        ],
        default => [
            'flash' => ['type' => 'danger', 'message' => 'Unbekannte Aktion.'],
            'redirect_path' => '/admin/media',
        ],
    };
}

function cms_admin_media_flash(array $payload): void
{
    $_SESSION['admin_alert'] = [
        'type' => ($payload['type'] ?? 'danger') === 'success' ? 'success' : 'danger',
        'message' => trim((string) ($payload['message'] ?? '')),
    ];
}

function cms_admin_media_redirect(string $routePath): never
{
    header('Location: ' . $routePath);
    exit;
}

function cms_admin_media_view_config(MediaModule $module, string $tab): array
{
    $normalizedTab = $module->normalizeTab($tab);

    return match ($normalizedTab) {
        'featured' => [
            'section' => 'featured',
            'view_file' => __DIR__ . '/views/media/featured.php',
            'page_title' => 'Medien – Beitrags- & Seitenmedien',
            'active_page' => 'media-featured',
            'page_assets' => [
                'js' => [
                    cms_asset_url('js/admin-media-integrations.js'),
                ],
            ],
            'data' => $module->getFeaturedMediaData(),
        ],
        'check' => [
            'section' => 'check',
            'view_file' => __DIR__ . '/views/media/check.php',
            'page_title' => 'Medien – Medien Check',
            'active_page' => 'media-check',
            'data' => $module->getMediaCheckData(),
        ],
        'categories' => [
            'section' => 'categories',
            'view_file' => __DIR__ . '/views/media/categories.php',
            'page_title' => 'Medien – Kategorien',
            'active_page' => 'media-categories',
            'page_assets' => [
                'js' => [
                    cms_asset_url('js/admin-media-integrations.js'),
                ],
            ],
            'data' => $module->getCategoriesData(),
        ],
        'settings' => [
            'section' => 'settings',
            'view_file' => __DIR__ . '/views/media/settings.php',
            'page_title' => 'Medien – Einstellungen',
            'active_page' => 'media-settings',
            'data' => $module->getSettingsData(),
        ],
        default => [
            'section' => 'library',
            'view_file' => __DIR__ . '/views/media/library.php',
            'page_title' => 'Medien',
            'active_page' => 'media',
            'page_assets' => [
                'js' => [
                    cms_asset_url('js/admin-media-integrations.js'),
                    cms_asset_url('js/admin-media-license.js'),
                ],
            ],
            'data' => $module->getLibraryData(),
        ],
    };
}

require_once __DIR__ . '/modules/media/MediaModule.php';

$mediaPreflightModule = new MediaModule();
$requestedTab = $mediaPreflightModule->normalizeTab((string)($_GET['tab'] ?? 'library'));

if ($requestedTab === 'library') {
    $requestedPath = $mediaPreflightModule->normalizePath((string)($_GET['path'] ?? ''));
    $memberConfirmed = (string)($_GET['confirm_member'] ?? '') === '1';

    if ($requestedPath !== '' && !$mediaPreflightModule->directoryExists($requestedPath)) {
        $fallbackPath = $mediaPreflightModule->resolveParentPathFromActionPath($requestedPath);
        if ($fallbackPath !== '' && !$mediaPreflightModule->directoryExists($fallbackPath)) {
            $fallbackPath = '';
        }

        cms_admin_media_flash([
            'type' => 'danger',
            'message' => 'Der angeforderte Medienordner existiert nicht mehr.',
        ]);
        cms_admin_media_redirect(cms_admin_media_build_redirect_url($mediaPreflightModule, 'library', $fallbackPath));
    }

    if ($mediaPreflightModule->requiresMemberConfirmation($requestedPath) && !$memberConfirmed) {
        cms_admin_media_flash([
            'type' => 'danger',
            'message' => 'Der Member-Ordner kann erst nach einer zusätzlichen Bestätigung geöffnet werden.',
        ]);
        cms_admin_media_redirect(CMS_ADMIN_MEDIA_ROUTE_PATH);
    }
}

$sectionPageConfig = [
    'route_path' => CMS_ADMIN_MEDIA_ROUTE_PATH,
    'view_file' => __DIR__ . '/views/media/library.php',
    'page_title' => 'Medien',
    'active_page' => 'media',
    'csrf_action' => 'admin_media',
    'module_file' => __DIR__ . '/modules/media/MediaModule.php',
    'module_factory' => static fn (): MediaModule => new MediaModule(),
    'access_checker' => static fn (): bool => cms_admin_media_can_access(),
    'request_context_resolver' => static function (MediaModule $module): array {
        $tab = $module->normalizeTab((string) ($_GET['tab'] ?? 'library'));
        $viewConfig = cms_admin_media_view_config($module, $tab);

        $viewConfig['template_vars'] = [
            'mediaActionToken' => Security::instance()->generateToken('media_action'),
        ];

        return $viewConfig;
    },
    'redirect_path_resolver' => static function (MediaModule $module, string $section, mixed $result): string {
        if (is_array($result) && isset($result['redirect_path']) && is_string($result['redirect_path'])) {
            return $result['redirect_path'];
        }

        return match ($module->normalizeTab($section)) {
            'featured' => cms_admin_media_build_featured_redirect_url($module),
            'check' => cms_admin_media_build_redirect_url($module, 'check', ''),
            'categories' => CMS_ADMIN_MEDIA_ROUTE_PATH . '?tab=categories',
            'settings' => CMS_ADMIN_MEDIA_ROUTE_PATH . '?tab=settings',
            default => cms_admin_media_build_redirect_url($module, 'library', $module->normalizePath((string) ($_GET['path'] ?? ''))),
        };
    },
    'post_handler' => static function (MediaModule $module, string $section, array $post): array {
        $action = cms_admin_media_normalize_action($post['action'] ?? '');
        if ($action === '') {
            return cms_admin_media_build_failure_result(
                'Unbekannte Aktion.',
                ['Bitte nur erlaubte Medien-Aktionen aus dem Admin ausführen.'],
                ['Der Request enthielt keinen erlaubten `action`-Wert.'],
                [
                    'title' => 'Unbekannte Medien-Aktion',
                    'source' => CMS_ADMIN_MEDIA_ROUTE_PATH,
                    'status' => 'warning',
                    'context' => [
                        'module' => 'media',
                        'operation' => 'unknown_action',
                        'action' => trim((string) ($post['action'] ?? '')),
                    ],
                ]
            );
        }

        if (!cms_admin_media_can_run_action($action)) {
            return cms_admin_media_build_failure_result(
                'Keine Berechtigung für diese Aktion.',
                ['Die Medien-Aktion erfordert die Capability `manage_media`.'],
                ['Die angeforderte Aktion wurde vor dem Modul-Dispatch verworfen.'],
                [
                    'title' => 'Medien-Aktion verweigert',
                    'source' => CMS_ADMIN_MEDIA_ROUTE_PATH,
                    'status' => 'warning',
                    'context' => [
                        'module' => 'media',
                        'operation' => 'capability_denied',
                        'action' => $action,
                    ],
                ]
            );
        }

        $normalizedPost = cms_admin_media_normalize_action_payload($module, $action, $post);
        $validationError = cms_admin_media_validate_action_payload($action, $normalizedPost);

        if ($validationError !== null) {
            return cms_admin_media_build_failure_result(
                $validationError,
                ['Bitte Pfad-, Kategorie- oder Namensfelder prüfen und erneut speichern.'],
                ['Die Aktion wurde wegen unvollständiger oder ungültiger Payload-Daten nicht ausgeführt.'],
                [
                    'title' => 'Ungültige Medien-Payload',
                    'source' => CMS_ADMIN_MEDIA_ROUTE_PATH,
                    'status' => 'warning',
                    'context' => [
                        'module' => 'media',
                        'operation' => 'invalid_payload',
                        'action' => $action,
                        'tab' => $module->normalizeTab($section),
                        'keys' => array_keys($normalizedPost),
                    ],
                ]
            );
        }

        $tab = $module->normalizeTab($section);
        $path = cms_admin_media_resolve_path($module, $normalizedPost);
        $handledAction = cms_admin_media_handle_action($module, $action, $tab, $path, $normalizedPost);

        if (isset($handledAction['flash']) && is_array($handledAction['flash'])) {
            return [
                'success' => (($handledAction['flash']['type'] ?? 'danger') === 'success'),
                'message' => (string) ($handledAction['flash']['message'] ?? ''),
                'details' => is_array($handledAction['flash']['details'] ?? null) ? $handledAction['flash']['details'] : [],
                'error_details' => is_array($handledAction['flash']['error_details'] ?? null) ? $handledAction['flash']['error_details'] : [],
                'report_payload' => is_array($handledAction['flash']['report_payload'] ?? null) ? $handledAction['flash']['report_payload'] : [],
                'redirect_path' => (string) ($handledAction['redirect_path'] ?? CMS_ADMIN_MEDIA_ROUTE_PATH),
            ];
        }

        $result = is_array($handledAction['result'] ?? null)
            ? $handledAction['result']
            : cms_admin_media_build_failure_result('Unbekannte Aktion.');
        $result['redirect_path'] = (string) ($handledAction['redirect_path'] ?? CMS_ADMIN_MEDIA_ROUTE_PATH);

        return $result;
    },
];

require __DIR__ . '/partials/section-page-shell.php';
