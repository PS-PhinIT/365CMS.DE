<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Medien-Werkzeuge – Entry Point
 * Route: /admin/media-tools
 *
 * - Vorschaubilder neu erzeugen (#13): Auftrag über admin/media-tools-api.php (BatchJobService +
 *   admin-batch-runner.js); ohne geöffnete Seite setzt der stündliche Cron ihn fort.
 * - Urheberangaben (#11): Ausgabe-Schalter für Credit-Zeile, Schema.org und Vorbelegung aus Bildmetadaten.
 */

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Security;
use CMS\Services\BatchJobService;
use CMS\Services\Media\MediaLicenseService;
use CMS\Services\Media\AvifDerivativeService;
use CMS\Services\Media\AvifRegenerationService;
use CMS\Services\Media\ThumbnailRegenerationService;

const CMS_ADMIN_MEDIA_TOOLS_CAPABILITY = 'manage_media';
const CMS_ADMIN_MEDIA_TOOLS_API_TOKEN_ACTION = 'admin_media_tools_api';

function cms_admin_media_tools_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_MEDIA_TOOLS_CAPABILITY);
}

if (!cms_admin_media_tools_can_access()) {
    header('Location: /');
    exit;
}

/** @return array<string, mixed> */
function cms_admin_media_tools_load_data(): array
{
    $thumbs = ThumbnailRegenerationService::getInstance();
    $jobs = BatchJobService::getInstance();
    $public = static fn (?array $job): ?array => $job !== null ? $jobs->toPublic($job) : null;
    $latest = $thumbs->latestJob();

    return [
        'sizes' => $thumbs->sizes(),
        'image_count' => $thumbs->countImages(),
        'max_images' => ThumbnailRegenerationService::MAX_IMAGES,
        'active_job' => $public($thumbs->activeJob()),
        'latest_job' => $public($latest),
        'latest_webp' => !empty($latest['state']['webp']),
        'image_library' => \CMS\Services\ImageService::getInstance()->isAvailable(),
        // 4.1.0 (2a): AVIF nachgenerieren
        'avif' => [
            'supported' => AvifDerivativeService::getInstance()->isSupported(),
            'enabled' => AvifDerivativeService::getInstance()->settings()['enabled'],
            'image_count' => AvifRegenerationService::getInstance()->countImages(),
            'active_job' => $public(AvifRegenerationService::getInstance()->activeJob()),
            'latest_job' => $public(AvifRegenerationService::getInstance()->latestJob()),
        ],
        'license' => MediaLicenseService::getInstance()->getSettings(),
        'api' => [
            'endpoint' => '/admin/media-tools-api',
            'token' => Security::instance()->generateToken(CMS_ADMIN_MEDIA_TOOLS_API_TOKEN_ACTION),
        ],
    ];
}

$sectionPageConfig = [
    'section' => 'media-tools',
    'route_path' => '/admin/media-tools',
    'view_file' => __DIR__ . '/views/media/tools.php',
    'page_title' => (string) __('media_tools.title'),
    'active_page' => 'media-tools',
    'csrf_action' => 'admin_media_tools',
    'module_factory' => static fn (): array => [],
    'access_checker' => static fn (): bool => cms_admin_media_tools_can_access(),
    'access_denied_route' => '/',
    'page_assets' => [
        'js' => [cms_asset_url('js/admin-batch-runner.js'), cms_asset_url('js/admin-media-tools.js')],
    ],
    'data_loader' => static fn (): array => ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? [] : cms_admin_media_tools_load_data(),
    'post_handler' => static function ($module, string $section, array $post): array {
        if (!cms_admin_media_tools_can_access()) {
            return ['success' => false, 'error' => (string) __('media_tools.error.forbidden')];
        }
        if ((string) ($post['action'] ?? '') !== 'save_license_output') {
            return ['success' => false, 'error' => (string) __('media_tools.error.unknown_action')];
        }
        $input = [
            'credit_line' => !empty($post['credit_line']),
            'schema' => !empty($post['schema']),
            'prefill' => !empty($post['prefill']),
        ];
        if (!MediaLicenseService::getInstance()->saveSettings($input)) {
            return ['success' => false, 'error' => (string) __('media_tools.license.save_failed')];
        }
        AuditLogger::instance()->log(AuditLogger::CAT_SETTING, 'media.license.output_saved', 'Ausgabe der Urheberangaben gespeichert', 'media_license', null, $input);

        return ['success' => true, 'message' => (string) __('media_tools.license.saved')];
    },
    'redirect_path_resolver' => static fn (): string => '/admin/media-tools',
    'invalid_token_message' => (string) __('media_tools.error.token'),
    'unknown_action_message' => (string) __('media_tools.error.unknown_action'),
];

require __DIR__ . '/partials/section-page-shell.php';
