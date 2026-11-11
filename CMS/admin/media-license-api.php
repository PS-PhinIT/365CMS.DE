<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Urheber & Lizenz der Medien (JSON) – Route: /admin/media-license-api
 *
 * POST action=save       path, Felder (creator, license, license_custom, license_url, source_url,
 *                        credit, copyright, ai_generated, ai_tool) – leere Angaben entfernen das Feld
 * POST action=bulk_save  paths[] (max. 500), mode=merge|replace, Felder wie oben
 *
 * Capability manage_media, CSRF-Aktion admin_media_license (wiederverwendbar), Audit-Log je Änderung.
 */

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Logger;
use CMS\Security;
use CMS\Services\Media\MediaLicenseService;
use CMS\WP_Error;

const CMS_ADMIN_MEDIA_LICENSE_CAPABILITY = 'manage_media';
const CMS_ADMIN_MEDIA_LICENSE_CSRF_ACTION = 'admin_media_license';
const CMS_ADMIN_MEDIA_LICENSE_FIELDS = ['creator', 'license', 'license_custom', 'license_url', 'source_url', 'credit', 'copyright', 'ai_generated', 'ai_tool'];

\CMS\CacheManager::instance()->sendResponseHeaders('private');
header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$cmsMediaLicenseRespond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$auth = Auth::instance();
if (!$auth->isLoggedIn() || !$auth->isAdmin() || !$auth->hasCapability(CMS_ADMIN_MEDIA_LICENSE_CAPABILITY)) {
    $cmsMediaLicenseRespond(403, ['success' => false, 'error' => __('media_license.error.forbidden')]);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $cmsMediaLicenseRespond(405, ['success' => false, 'error' => __('media_license.error.post_only')]);
}

if (!Security::instance()->verifyPersistentToken((string) ($_POST['csrf_token'] ?? ''), CMS_ADMIN_MEDIA_LICENSE_CSRF_ACTION)) {
    $cmsMediaLicenseRespond(403, ['success' => false, 'code' => 'csrf', 'error' => __('media_license.error.csrf')]);
}

$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$input = [];
foreach (CMS_ADMIN_MEDIA_LICENSE_FIELDS as $field) {
    $value = $_POST[$field] ?? '';
    $input[$field] = is_scalar($value) ? (string) $value : '';
}

$service = MediaLicenseService::getInstance();
$present = static function (array $license): array {
    return [
        'license' => $license,
        'summary' => MediaLicenseService::summary($license),
        'credit_text' => MediaLicenseService::creditText($license),
    ];
};

try {
    switch ($action) {
        case 'save':
            $path = is_string($_POST['path'] ?? null) ? (string) $_POST['path'] : '';
            $result = $service->save($path, $input);
            if ($result instanceof WP_Error) {
                $cmsMediaLicenseRespond(422, ['success' => false, 'error' => $result->get_error_message()]);
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_MEDIA,
                'media.license_updated',
                'Urheber-/Lizenzangaben einer Datei geändert',
                'media',
                null,
                ['path' => $path, 'license' => (string) ($result['license'] ?? ''), 'cleared' => $result === []],
                'info'
            );

            $cmsMediaLicenseRespond(200, ['success' => true, 'path' => $path, 'message' => __('media_license.saved')] + $present($result));

        case 'bulk_save':
            $paths = $_POST['paths'] ?? [];
            if (!is_array($paths)) {
                $paths = [];
            }
            $paths = array_values(array_filter(array_map(static fn(mixed $p): string => is_string($p) ? $p : '', $paths)));
            $mode = (string) ($_POST['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';

            $changed = $service->bulkSave($paths, $input, $mode);
            if ($changed instanceof WP_Error) {
                $cmsMediaLicenseRespond(422, ['success' => false, 'error' => $changed->get_error_message()]);
            }

            $validPaths = $service->existingFilePaths($paths);
            $licenses = [];
            foreach ($validPaths as $path) {
                $licenses[$path] = $present($service->get($path));
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_MEDIA,
                'media.license_bulk_updated',
                'Urheber-/Lizenzangaben per Sammelaktion geändert',
                'media',
                null,
                ['files' => count($validPaths), 'changed' => $changed, 'mode' => $mode],
                'info'
            );

            $cmsMediaLicenseRespond(200, [
                'success' => true,
                'changed' => $changed,
                'message' => $changed === 1 ? __('media_license.bulk_saved_one') : sprintf(__('media_license.bulk_saved'), $changed),
                'items' => $licenses,
            ]);

        default:
            $cmsMediaLicenseRespond(400, ['success' => false, 'error' => __('media_license.error.unknown_action')]);
    }
} catch (\Throwable $e) {
    Logger::instance()->withChannel('admin.media')->error('Lizenzangaben konnten nicht gespeichert werden.', [
        'action' => $action,
        'exception' => $e,
    ]);
    $cmsMediaLicenseRespond(500, ['success' => false, 'error' => __('media_license.error.save_failed')]);
}
