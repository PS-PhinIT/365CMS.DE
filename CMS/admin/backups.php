<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backup & Restore – Entry Point
 * Route: /admin/backups
 */

use CMS\Auth;

const CMS_ADMIN_BACKUPS_READ_CAPABILITIES = ['manage_settings', 'manage_system'];
const CMS_ADMIN_BACKUPS_WRITE_CAPABILITIES = ['manage_settings', 'manage_system'];

function cms_admin_backups_has_any_capability(array $capabilities): bool
{
    foreach ($capabilities as $capability) {
        if (is_string($capability) && $capability !== '' && Auth::instance()->hasCapability($capability)) {
            return true;
        }
    }

    return false;
}

function cms_admin_backups_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && cms_admin_backups_has_any_capability(CMS_ADMIN_BACKUPS_READ_CAPABILITIES);
}

function cms_admin_backups_can_mutate(): bool
{
    return cms_admin_backups_can_access()
    && cms_admin_backups_has_any_capability(CMS_ADMIN_BACKUPS_WRITE_CAPABILITIES);
}

require_once __DIR__ . '/modules/system/BackupsModule.php';

/** @return array<string, true> */
function cms_admin_backups_allowed_actions(): array
{
    return [
        'create_full' => true,
        'create_db' => true,
        'validate' => true,
        'download' => true,
        'restore' => true,
        'delete' => true,
        'set_encryption' => true,
    ];
}

function cms_admin_backups_normalize_action(mixed $value): ?string
{
    $action = strtolower(trim((string) $value));

    return isset(cms_admin_backups_allowed_actions()[$action]) ? $action : null;
}

function cms_admin_backups_normalize_backup_name(array $post): string
{
    $name = trim(basename((string) ($post['backup_name'] ?? '')));

    return preg_match('/^[a-z0-9][a-z0-9._-]{2,120}$/i', $name) === 1 ? $name : '';
}

function cms_admin_backups_normalize_backup_name_value(mixed $value): string
{
    $name = trim(basename((string) $value));

    return preg_match('/^[a-z0-9][a-z0-9._-]{2,120}$/i', $name) === 1 ? $name : '';
}

function cms_admin_backups_normalize_download_part(mixed $value): string
{
    $part = strtolower(trim((string) $value));

    return in_array($part, ['database', 'files'], true) ? $part : 'database';
}

function cms_admin_backups_flash(array $payload): void
{
    $_SESSION['admin_alert'] = [
        'type' => ($payload['type'] ?? 'danger') === 'success' ? 'success' : 'danger',
        'message' => trim((string) ($payload['message'] ?? '')),
    ];
}

function cms_admin_backups_redirect(string $path = '/admin/backups'): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function cms_admin_backups_create_download_token(string $backupName, string $part): string
{
    $backupName = cms_admin_backups_normalize_backup_name_value($backupName);
    $part = cms_admin_backups_normalize_download_part($part);
    if ($backupName === '') {
        return '';
    }

    if (empty($_SESSION['admin_backup_downloads']) || !is_array($_SESSION['admin_backup_downloads'])) {
        $_SESSION['admin_backup_downloads'] = [];
    }

    $token = bin2hex(random_bytes(24));
    $_SESSION['admin_backup_downloads'][$token] = [
        'name' => $backupName,
        'part' => $part,
        'expires' => time() + 600,
    ];

    return $token;
}

/** @return array{name:string,part:string}|null */
function cms_admin_backups_consume_download_token(mixed $value): ?array
{
    $token = trim((string) $value);
    if ($token === '' || preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
        return null;
    }

    $downloads = $_SESSION['admin_backup_downloads'] ?? [];
    if (!is_array($downloads) || !isset($downloads[$token]) || !is_array($downloads[$token])) {
        return null;
    }

    $payload = $downloads[$token];
    unset($_SESSION['admin_backup_downloads'][$token]);

    if ((int) ($payload['expires'] ?? 0) < time()) {
        return null;
    }

    $backupName = cms_admin_backups_normalize_backup_name_value($payload['name'] ?? '');
    if ($backupName === '') {
        return null;
    }

    return [
        'name' => $backupName,
        'part' => cms_admin_backups_normalize_download_part($payload['part'] ?? 'database'),
    ];
}

function cms_admin_backups_resolve_safe_download_path(string $path): ?string
{
    $resolvedPath = realpath($path);
    // Gleicher Root wie BackupService (CMS_BACKUP_PATH oder CMS/backups/).
    $backupRoot = realpath(\CMS\Services\BackupService::resolveConfiguredBackupRoot());

    if (!is_string($resolvedPath) || !is_string($backupRoot)) {
        return null;
    }

    $backupRoot = rtrim($backupRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedPath);
    $normalizedRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $backupRoot);

    if (!(is_file($normalizedPath)
        && is_readable($normalizedPath)
        && str_starts_with($normalizedPath, $normalizedRoot))) {
        return null;
    }

    return $normalizedPath;
}

function cms_admin_backups_send_download(string $path, string $filename, string $contentType = 'application/octet-stream'): never
{
    $safePath = cms_admin_backups_resolve_safe_download_path($path);
    if ($safePath === null) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . (string) filesize($safePath));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    readfile($safePath);
    exit;
}

function cms_admin_backups_handle_download(BackupsModule $module, mixed $token): never
{
    $downloadRequest = cms_admin_backups_consume_download_token($token);
    if ($downloadRequest === null) {
        cms_admin_backups_flash(['type' => 'danger', 'message' => 'Download-Token ist ungültig oder abgelaufen.']);
        cms_admin_backups_redirect();
    }

    $download = $module->getDownloadableBackupFile(
        $downloadRequest['name'],
        $downloadRequest['part']
    );

    if (!is_array($download) || empty($download['path'])) {
        cms_admin_backups_flash(['type' => 'danger', 'message' => 'Backup-Datei konnte nicht zum Download vorbereitet werden.']);
        cms_admin_backups_redirect();
    }

    $safePath = cms_admin_backups_resolve_safe_download_path((string) $download['path']);
    if ($safePath === null) {
        cms_admin_backups_flash(['type' => 'danger', 'message' => 'Backup-Datei konnte nicht sicher zum Download vorbereitet werden.']);
        cms_admin_backups_redirect();
    }

    cms_admin_backups_send_download(
        $safePath,
        str_replace('"', '', (string) ($download['filename'] ?? 'backup.bin')),
        (string) ($download['content_type'] ?? 'application/octet-stream')
    );
}

/**
 * @return array<string, callable(array): array>
 */
function cms_admin_backups_action_handlers(BackupsModule $module): array
{
    return [
        'create_full' => static fn (array $post): array => $module->createFullBackup(),
        'create_db' => static fn (array $post): array => $module->createDatabaseBackup(),
        'validate' => static fn (array $post): array => $module->validateBackup(
            cms_admin_backups_normalize_backup_name($post),
            !empty($post['include_restore_dry_run'])
        ),
        'restore' => static fn (array $post): array => $module->restoreBackup(cms_admin_backups_normalize_backup_name($post)),
        'delete' => static fn (array $post): array => $module->deleteBackup(cms_admin_backups_normalize_backup_name($post)),
        'set_encryption' => static fn (array $post): array => $module->setEncryption((string) ($post['backup_encryption'] ?? '0') === '1'),
    ];
}

$sectionPageConfig = [
    'section' => 'overview',
    'route_path' => '/admin/backups',
    'view_file' => __DIR__ . '/views/system/backups.php',
    'page_title' => 'Backup & Restore',
    'active_page' => 'backups',
    'page_assets' => [
        'css' => [],
        'js' => [],
    ],
    'csrf_action' => 'admin_backups',
    'guard_constant' => 'CMS_ADMIN_SYSTEM_VIEW',
    'module_factory' => static function (): BackupsModule {
        return new BackupsModule();
    },
    'data_loader' => static function ($module): array {
        return $module instanceof BackupsModule ? $module->getData() : [];
    },
    'access_checker' => static function (): bool {
        return cms_admin_backups_can_access();
    },
    'access_denied_route' => '/',
    'post_handler' => static function ($module, string $section, array $postData): array {
        if (!$module instanceof BackupsModule) {
            return ['success' => false, 'error' => 'Backup-Modul konnte nicht initialisiert werden.'];
        }

        $action = cms_admin_backups_normalize_action($postData['action'] ?? null);
        if ($action === null) {
            return ['success' => false, 'error' => 'Unbekannte Aktion.'];
        }

        if ($action === 'download') {
            if (!cms_admin_backups_can_access()) {
                return ['success' => false, 'error' => 'Keine Berechtigung für Backup-Downloads.'];
            }

            cms_admin_backups_handle_download($module, $postData['download_token'] ?? '');
        }

        if (!cms_admin_backups_can_mutate()) {
            return ['success' => false, 'error' => 'Keine Berechtigung für Backup-Mutationen.'];
        }

        if (in_array($action, ['delete', 'restore'], true) && cms_admin_backups_normalize_backup_name($postData) === '') {
            return ['success' => false, 'error' => 'Ungültiger Backup-Name.'];
        }

        $handler = cms_admin_backups_action_handlers($module)[$action] ?? null;

        return is_callable($handler)
            ? $handler($postData)
            : ['success' => false, 'error' => 'Unbekannte Aktion.'];
    },
    'unknown_action_message' => 'Unbekannte Aktion.',
];

require __DIR__ . '/partials/section-page-shell.php';
