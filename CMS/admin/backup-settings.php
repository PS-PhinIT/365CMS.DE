<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backup-Einstellungen – Entry Point (4.1.0, Bereich 4a)
 * Route: /admin/backup-settings
 *
 * Verschlüsselung der Backups und Offsite-Ziel (S3-kompatibel: AWS S3, Cloudflare R2, Hetzner, MinIO).
 * POST-Aktionen (Feld `action`): set_encryption, save_offsite, test_offsite, upload_now.
 * Lesen: manage_settings oder manage_system; Offsite ändern/prüfen/hochladen zusätzlich backups.offsite.
 */

use CMS\Auth;
use CMS\Services\Backup\OffsiteBackupService;
use CMS\Services\BackupService;

function cms_admin_backup_settings_can_access(): bool
{
    $auth = Auth::instance();

    return $auth->isAdmin() && ($auth->hasCapability('manage_settings') || $auth->hasCapability('manage_system'));
}

if (!cms_admin_backup_settings_can_access()) {
    header('Location: /');
    exit;
}

/**
 * @return array<string, mixed>
 */
function cms_admin_backup_settings_data(): array
{
    $offsite = OffsiteBackupService::getInstance();
    $backups = BackupService::getInstance();
    $settings = $offsite->settings();
    unset($settings['secret_key']);

    return [
        'encryption' => $backups->isEncryptionEnabled(),
        'settings' => $settings,
        'has_secret' => trim((string) (\CMS\Services\SettingsService::getInstance()->get(OffsiteBackupService::SETTINGS_GROUP, 'secret_key', '') ?? '')) !== '',
        'configured' => $offsite->isConfigured(),
        'status' => $offsite->statusMap(),
        'backups' => array_slice($backups->listBackups(), 0, 10),
        'can_offsite' => Auth::instance()->hasCapability('backups.offsite'),
    ];
}

$sectionPageConfig = [
    'section' => 'settings',
    'route_path' => '/admin/backup-settings',
    'view_file' => __DIR__ . '/views/system/backup-settings.php',
    'page_title' => 'Backup-Einstellungen',
    'active_page' => 'backup-settings',
    'csrf_action' => 'admin_backup_settings',
    'module_factory' => static fn (): OffsiteBackupService => OffsiteBackupService::getInstance(),
    'data_loader' => static fn (): array => cms_admin_backup_settings_data(),
    'access_checker' => static fn (): bool => cms_admin_backup_settings_can_access(),
    'access_denied_route' => '/',
    'redirect_path_resolver' => static fn (): string => '/admin/backup-settings',
    'post_handler' => static function (OffsiteBackupService $offsite, string $section, array $post): array {
        $action = is_string($post['action'] ?? null) ? (string) $post['action'] : '';
        if ($action === 'set_encryption') {
            // Gleicher Weg wie /admin/backups (Rechteprüfung, Audit-Eintrag).
            require_once __DIR__ . '/modules/system/BackupsModule.php';
            // SEC-410-12: Die Shell hat das Token dieser Seite (`admin_backup_settings`) bereits eingelöst; ohne diese
            // Markierung verlangt BackupsModule sein eigenes Token und lehnt die Umstellung immer ab.
            if (function_exists('cms_admin_section_shell_was_csrf_verified') && cms_admin_section_shell_was_csrf_verified('admin_backup_settings')) {
                cms_admin_section_shell_mark_csrf_verified('admin_backups');
            }

            return (new BackupsModule())->setEncryption((string) ($post['backup_encryption'] ?? '0') === '1');
        }
        if (!in_array($action, ['save_offsite', 'test_offsite', 'upload_now'], true)) {
            return ['success' => false, 'error' => 'Unbekannte Aktion.'];
        }
        if (!Auth::instance()->hasCapability('backups.offsite')) {
            return ['success' => false, 'error' => 'Für das Offsite-Ziel fehlt das Recht backups.offsite.'];
        }

        return match ($action) {
            'save_offsite' => OffsiteBackupService::saveSettings($post),
            'test_offsite' => $offsite->testConnection(),
            default => (static function () use ($offsite, $post): array {
                $name = trim(basename((string) ($post['backup_name'] ?? '')));
                $result = $offsite->uploadBackup($name);
                if (!empty($result['success'])) {
                    $offsite->applyRetention();
                    $result['message'] = sprintf('„%s“ hochgeladen (%d Dateien).', $name, (int) ($result['files'] ?? 0));
                }

                return $result;
            })(),
        };
    },
];

require __DIR__ . '/partials/section-page-shell.php';
