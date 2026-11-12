<?php
declare(strict_types=1);

/**
 * Import/Export – Inhalte als ZIP exportieren und in diese Installation importieren
 * Route: /admin/content-exchange[?token=<staging>][&download=<datei>]
 *
 * Export und Import laufen über admin/content-exchange-api.php (BatchJobService + admin-batch-runner.js).
 * Der Upload (POST action=upload) wird hier entgegengenommen, geprüft, entpackt und führt zur Vorschau (?token=…).
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Security;
use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\Exchange\ContentExchangeService;

const CMS_ADMIN_CONTENT_EXCHANGE_CAPABILITY = 'import_export';

function cms_admin_content_exchange_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_CONTENT_EXCHANGE_CAPABILITY);
}

if (!cms_admin_content_exchange_can_access()) {
    header('Location: /');
    exit;
}

// Download eines fertigen Exports (Dateiname aus dem Job-Ergebnis, realpath-geprüft im privaten Speicher).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && is_string($_GET['download'] ?? null)) {
    $cmsExchangeFile = ContentExchangeService::getInstance()->exportFile((string) $_GET['download']);
    if ($cmsExchangeFile === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($cmsExchangeFile) . '"');
    header('Content-Length: ' . (string) filesize($cmsExchangeFile));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($cmsExchangeFile);
    exit;
}

$sectionPageConfig = [
    'section' => 'content-exchange',
    'route_path' => '/admin/content-exchange',
    'view_file' => __DIR__ . '/views/system/content-exchange.php',
    'page_title' => __('exchange.title'),
    'active_page' => 'content-exchange',
    'csrf_action' => 'admin_content_exchange',
    'page_assets' => ['js' => [cms_asset_url('js/admin-batch-runner.js'), cms_asset_url('js/admin-content-exchange.js')]],
    'module_factory' => static fn (): ContentExchangeService => ContentExchangeService::getInstance(),
    'data_loader' => static function (ContentExchangeService $service): array {
        $token = is_string($_GET['token'] ?? null) && preg_match('/^[a-f0-9]{24}$/', (string) $_GET['token']) === 1 ? (string) $_GET['token'] : '';
        $manifest = $token !== '' ? $service->manifest($token) : null;
        $users = [];
        if ($manifest !== null) {
            $db = \CMS\Database::instance();
            foreach ($db->execute('SELECT id, username, display_name FROM ' . $db->getPrefix() . "users WHERE status = 'active' AND role IN ('admin','editor','author') ORDER BY display_name, username LIMIT 500")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $users[] = ['id' => (int) $row['id'], 'name' => trim((string) $row['display_name']) !== '' ? (string) $row['display_name'] : (string) $row['username']];
            }
        }
        $jobs = \CMS\Services\BatchJobService::getInstance();

        return [
            'types' => array_map(static fn ($provider): string => $provider->label(), $service->providers()),
            'token' => $manifest !== null ? $token : '',
            'manifest' => $manifest,
            'preview' => $manifest !== null ? $service->preview($token) : [],
            'users' => $users,
            'strategies' => ImportContext::STRATEGIES,
            'max_mb' => (int) (min(ContentExchangeService::MAX_UPLOAD_BYTES, cms_admin_content_exchange_upload_limit()) / 1048576),
            'api' => [
                'endpoint' => '/admin/content-exchange-api',
                'token' => Security::instance()->generateToken('admin_content_exchange_api'),
            ],
            'active_export' => ($job = $jobs->findActive(ContentExchangeService::JOB_EXPORT)) !== null ? $jobs->toPublic($job) : null,
            'active_import' => $token !== '' && ($job = $jobs->findActive(ContentExchangeService::JOB_IMPORT, $token)) !== null ? $jobs->toPublic($job) : null,
            'invalid_token' => $token === '' && is_string($_GET['token'] ?? null),
        ];
    },
    'access_checker' => static fn (): bool => cms_admin_content_exchange_can_access(),
    'access_denied_route' => '/',
    'redirect_path_resolver' => static function ($module, string $section, mixed $result = null): string {
        return is_array($result) && is_string($result['__redirect'] ?? null) ? (string) $result['__redirect'] : '/admin/content-exchange';
    },
    'invalid_token_message' => __('exchange.error.token'),
    'unknown_action_message' => __('exchange.error.action'),
    'post_handler' => static function (ContentExchangeService $service, string $section, array $post): array {
        if (($post['action'] ?? '') !== 'upload') {
            return ['success' => false, 'error' => __('exchange.error.action')];
        }
        $result = $service->stageUpload(is_array($_FILES['archive'] ?? null) ? $_FILES['archive'] : []);
        if (empty($result['success'])) {
            return ['success' => false, 'error' => (string) ($result['error'] ?? __('exchange.error.upload')), '__redirect' => '/admin/content-exchange#import'];
        }

        return ['success' => true, 'message' => __('exchange.uploaded'), '__redirect' => '/admin/content-exchange?token=' . $result['token'] . '#import'];
    },
];

/** Kleinster Wert aus upload_max_filesize und post_max_size in Bytes. */
function cms_admin_content_exchange_upload_limit(): int
{
    $parse = static function (string $value): int {
        $value = trim($value);
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    };
    $limits = array_filter([$parse((string) ini_get('upload_max_filesize')), $parse((string) ini_get('post_max_size'))], static fn (int $v): bool => $v > 0);

    return $limits !== [] ? min($limits) : ContentExchangeService::MAX_UPLOAD_BYTES;
}

require __DIR__ . '/partials/section-page-shell.php';
