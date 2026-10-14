<?php
declare(strict_types=1);

/**
 * 404-Errors & Weiterleitung – Entry Point
 * Route: /admin/redirect-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Security;
use CMS\Services\CoreModuleService;

const CMS_ADMIN_REDIRECT_MANAGER_ALLOWED_ACTIONS = [
    'save_redirect',
    'delete_redirect',
    'delete_redirects_by_slug',
    'toggle_redirect',
    'clear_logs',
    'import_redirects',
];

const CMS_ADMIN_REDIRECT_MANAGER_ACTION_CAPABILITIES = [
    'save_redirect' => 'manage_settings',
    'delete_redirect' => 'manage_settings',
    'delete_redirects_by_slug' => 'manage_settings',
    'toggle_redirect' => 'manage_settings',
    'clear_logs' => 'manage_settings',
    'import_redirects' => 'manage_settings',
];

function cms_admin_redirect_manager_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability('manage_settings')
        && (!class_exists(CoreModuleService::class) || CoreModuleService::getInstance()->isAdminPageEnabled('redirect-manager'));
}

function cms_admin_redirect_manager_target_url(): string
{
    return '/admin/redirect-manager';
}

function cms_admin_redirect_manager_redirect(string $redirectUrl): never
{
    header('Location: ' . $redirectUrl);
    exit;
}

function cms_admin_redirect_manager_flash(array $payload): void
{
    $_SESSION['admin_alert'] = [
        'type' => ($payload['type'] ?? 'danger') === 'success' ? 'success' : 'danger',
        'message' => trim((string) ($payload['message'] ?? '')),
        'details' => is_array($payload['details'] ?? null) ? $payload['details'] : [],
    ];
}

function cms_admin_redirect_manager_flash_result(array $result): void
{
    cms_admin_redirect_manager_flash([
        'type' => !empty($result['success']) ? 'success' : 'danger',
        'message' => (string) ($result['message'] ?? $result['error'] ?? ''),
        'details' => $result['details'] ?? [],
    ]);
}

function cms_admin_redirect_manager_pull_alert(): ?array
{
    $alert = $_SESSION['admin_alert'] ?? null;
    unset($_SESSION['admin_alert']);

    return is_array($alert) ? $alert : null;
}

function cms_admin_redirect_manager_normalize_action(mixed $action): string
{
    $normalizedAction = trim((string) $action);

    return in_array($normalizedAction, CMS_ADMIN_REDIRECT_MANAGER_ALLOWED_ACTIONS, true) ? $normalizedAction : '';
}

function cms_admin_redirect_manager_normalize_positive_id(mixed $id): int
{
    $normalizedId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $normalizedId === false ? 0 : (int) $normalizedId;
}

function cms_admin_redirect_manager_normalize_slug_filter(mixed $slugFilter): string
{
    return trim((string) $slugFilter);
}

function cms_admin_redirect_manager_can_run_action(string $action): bool
{
    $requiredCapability = CMS_ADMIN_REDIRECT_MANAGER_ACTION_CAPABILITIES[$action] ?? null;

    return is_string($requiredCapability)
        && $requiredCapability !== ''
        && Auth::instance()->hasCapability($requiredCapability);
}

if (!cms_admin_redirect_manager_can_access()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/seo/RedirectManagerModule.php';
$module = new RedirectManagerModule();

function cms_admin_redirect_manager_handle_action(RedirectManagerModule $module, string $action, array $post): array
{
    return match ($action) {
        'save_redirect' => $module->saveRedirect($post),
        'delete_redirect' => $module->deleteRedirect(cms_admin_redirect_manager_normalize_positive_id($post['id'] ?? 0)),
        'delete_redirects_by_slug' => $module->deleteRedirectsBySlug(cms_admin_redirect_manager_normalize_slug_filter($post['slug_filter'] ?? '')),
        'toggle_redirect' => $module->toggleRedirect(cms_admin_redirect_manager_normalize_positive_id($post['id'] ?? 0)),
        'clear_logs' => $module->clearLogs(),
        'import_redirects' => $module->importRedirectsCsv($_FILES['redirect_csv'] ?? null),
        default => ['success' => false, 'error' => 'Unbekannte Aktion.'],
    };
}

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['export'] ?? null) === 'csv') {
    try {
        $module->streamRedirectsCsvExport();
    } catch (\Throwable $e) {
        \CMS\Logger::instance()->withChannel('seo')->warning('Weiterleitungs-CSV-Export fehlgeschlagen.', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
        cms_admin_redirect_manager_flash(['type' => 'danger', 'message' => 'Der CSV-Export konnte nicht erstellt werden.']);
        cms_admin_redirect_manager_redirect(cms_admin_redirect_manager_target_url());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::instance()->verifyToken($_POST['csrf_token'] ?? '', 'admin_redirect_manager')) {
        cms_admin_redirect_manager_flash(['type' => 'danger', 'message' => 'Sicherheitstoken ungültig.']);
        cms_admin_redirect_manager_redirect(cms_admin_redirect_manager_target_url());
    }

    $action = cms_admin_redirect_manager_normalize_action($_POST['action'] ?? '');
    if ($action === '') {
        cms_admin_redirect_manager_flash(['type' => 'danger', 'message' => 'Unbekannte Aktion.']);
        cms_admin_redirect_manager_redirect(cms_admin_redirect_manager_target_url());
    }

    if (!cms_admin_redirect_manager_can_run_action($action)) {
        cms_admin_redirect_manager_flash(['type' => 'danger', 'message' => 'Keine Berechtigung für diese Aktion.']);
        cms_admin_redirect_manager_redirect(cms_admin_redirect_manager_target_url());
    }

    if (in_array($action, ['delete_redirect', 'toggle_redirect'], true)
        && cms_admin_redirect_manager_normalize_positive_id($_POST['id'] ?? 0) < 1
    ) {
        cms_admin_redirect_manager_flash(['type' => 'danger', 'message' => 'Ungültige Weiterleitungs-ID.']);
        cms_admin_redirect_manager_redirect(cms_admin_redirect_manager_target_url());
    }

    if ($action === 'delete_redirects_by_slug' && cms_admin_redirect_manager_normalize_slug_filter($_POST['slug_filter'] ?? '') === '') {
        cms_admin_redirect_manager_flash(['type' => 'danger', 'message' => 'Bitte einen gültigen Slug angeben.']);
        cms_admin_redirect_manager_redirect(cms_admin_redirect_manager_target_url());
    }

    $result = cms_admin_redirect_manager_handle_action($module, $action, $_POST);

    cms_admin_redirect_manager_flash_result($result);
    cms_admin_redirect_manager_redirect(cms_admin_redirect_manager_target_url());
}

$alert = cms_admin_redirect_manager_pull_alert();

$csrfToken = Security::instance()->generateToken('admin_redirect_manager');
$pageTitle = 'Weiterleitungen';
$activePage = 'redirect-manager';
$pageAssets = [
    'js' => [
        cms_asset_url('js/admin-seo-redirects.js'),
    ],
];
$data = $module->getRedirectManagerData();

require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/sidebar.php';
defined('CMS_ADMIN_SEO_VIEW') || define('CMS_ADMIN_SEO_VIEW', true);
require_once __DIR__ . '/views/seo/redirects.php';
require_once __DIR__ . '/partials/footer.php';
