<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site Tables – Entry Point
 * Route: /admin/site-tables
 */

use CMS\Auth;
use CMS\Security;

const CMS_ADMIN_SITE_TABLES_CAPABILITY = 'manage_settings';
const CMS_ADMIN_SITE_TABLES_MAX_COLUMNS_JSON_LENGTH = 100000;
const CMS_ADMIN_SITE_TABLES_MAX_ROWS_JSON_LENGTH = 500000;
const CMS_ADMIN_SITE_TABLES_FORM_SESSION_KEY = 'admin_site_tables_form';

function cms_admin_site_tables_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_SITE_TABLES_CAPABILITY);
}

if (!cms_admin_site_tables_can_access()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/tables/TablesModule.php';
$module    = new TablesModule();
$alert     = null;

/**
 * @return array<string, string>
 */
function cms_admin_site_tables_allowed_actions(): array
{
    return [
        'save_settings' => 'save_settings',
        'save' => 'save',
        'delete' => 'delete',
        'duplicate' => 'duplicate',
    ];
}

/**
 * @return array<string, string>
 */
function cms_admin_site_tables_allowed_views(): array
{
    return [
        'list' => 'list',
        'settings' => 'settings',
        'edit' => 'edit',
    ];
}

function cms_admin_site_tables_redirect(string $path = '/admin/site-tables'): never
{
    header('Location: ' . $path);
    exit;
}

function cms_admin_site_tables_flash(array $result): void
{
    $_SESSION['admin_alert'] = [
        'type' => !empty($result['success']) ? 'success' : 'danger',
        'message' => trim((string) ($result['message'] ?? $result['error'] ?? 'Unbekannte Antwort.')),
    ];
}

function cms_admin_site_tables_store_form_state(array $post): void
{
    unset($post['csrf_token']);

    $_SESSION[CMS_ADMIN_SITE_TABLES_FORM_SESSION_KEY] = [
        'values' => $post,
    ];
}

function cms_admin_site_tables_pull_form_state(): array
{
    $state = $_SESSION[CMS_ADMIN_SITE_TABLES_FORM_SESSION_KEY] ?? [];
    unset($_SESSION[CMS_ADMIN_SITE_TABLES_FORM_SESSION_KEY]);

    return is_array($state['values'] ?? null) ? $state['values'] : [];
}

function cms_admin_site_tables_normalize_action(mixed $action): string
{
    $action = is_string($action) ? trim($action) : '';
    $allowedActions = cms_admin_site_tables_allowed_actions();

    return $allowedActions[$action] ?? '';
}

function cms_admin_site_tables_normalize_view_action(mixed $action): string
{
    $action = is_string($action) ? trim($action) : '';
    $allowedViews = cms_admin_site_tables_allowed_views();

    return $allowedViews[$action] ?? 'list';
}

function cms_admin_site_tables_normalize_search(mixed $value): string
{
    $value = is_scalar($value) ? (string) $value : '';
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

    return trim($value);
}

function cms_admin_site_tables_normalize_positive_id(mixed $value): int
{
    if (is_int($value)) {
        return $value > 0 ? $value : 0;
    }

    if (!is_scalar($value)) {
        return 0;
    }

    $value = trim((string) $value);
    if ($value === '' || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        return 0;
    }

    return (int) $value;
}

function cms_admin_site_tables_edit_redirect_path(int $id = 0): string
{
    return $id > 0
        ? '/admin/site-tables?action=edit&id=' . $id
        : '/admin/site-tables?action=edit';
}

/**
 * @return array{action:string,id:int,error:string,post:array<string,mixed>}
 */
function cms_admin_site_tables_normalize_payload(array $post): array
{
    $action = cms_admin_site_tables_normalize_action($post['action'] ?? '');
    $id = cms_admin_site_tables_normalize_positive_id($post['id'] ?? 0);
    $error = '';
    $columnsJson = (string) ($post['columns_json'] ?? '[]');
    $rowsJson = (string) ($post['rows_json'] ?? '[]');

    if ($action === '') {
        $error = 'Unbekannte oder nicht erlaubte Aktion.';
    } elseif ($action === 'save' && strlen($columnsJson) > CMS_ADMIN_SITE_TABLES_MAX_COLUMNS_JSON_LENGTH) {
        $error = 'Die Spalten-Konfiguration ist zu groß.';
    } elseif ($action === 'save' && strlen($rowsJson) > CMS_ADMIN_SITE_TABLES_MAX_ROWS_JSON_LENGTH) {
        $error = 'Die Zeilen-Konfiguration ist zu groß.';
    }

    return [
        'action' => $action,
        'id' => $id,
        'error' => $error,
        'post' => $post,
    ];
}

function cms_admin_site_tables_handle_action(TablesModule $module, array $payload): array
{
    return match ($payload['action']) {
        'save_settings' => $module->saveDisplaySettings($payload['post']),
        'save' => $module->save($payload['post']),
        'delete' => $module->delete($payload['id']),
        'duplicate' => $module->duplicate($payload['id']),
        default => ['success' => false, 'error' => 'Unbekannte oder nicht erlaubte Aktion.'],
    };
}

function cms_admin_site_tables_page_assets(string $viewAction): array
{
    if (!in_array($viewAction, ['list', 'edit'], true)) {
        return [];
    }

    return [
        'css' => [
            cms_asset_url('css/admin-site-tables.css'),
        ],
        'js' => [
            cms_asset_url('js/admin-site-tables.js'),
        ],
    ];
}

// ─── POST-Handling ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload   = cms_admin_site_tables_normalize_payload($_POST);
    $postToken = (string) ($_POST['csrf_token'] ?? '');

    if (!Security::instance()->verifyToken($postToken, 'admin_tables')) {
        cms_admin_site_tables_flash(['success' => false, 'error' => 'Sicherheitstoken ungültig.']);
        cms_admin_site_tables_redirect();
    }

    if ($payload['error'] !== '') {
        cms_admin_site_tables_flash(['success' => false, 'error' => $payload['error']]);
        if ($payload['action'] === 'save') {
            cms_admin_site_tables_store_form_state($payload['post']);
        }
        cms_admin_site_tables_redirect($payload['action'] === 'save' ? cms_admin_site_tables_edit_redirect_path($payload['id']) : '/admin/site-tables');
    }

    if (in_array($payload['action'], ['delete', 'duplicate'], true) && $payload['id'] <= 0) {
        cms_admin_site_tables_flash(['success' => false, 'error' => 'Ungültige Tabellen-ID.']);
        cms_admin_site_tables_redirect();
    }

    $result = cms_admin_site_tables_handle_action($module, $payload);
    cms_admin_site_tables_flash($result);

    if ($payload['action'] === 'save_settings') {
        cms_admin_site_tables_redirect('/admin/site-tables?action=settings');
    }

    if ($payload['action'] === 'save') {
        if (!empty($result['success'])) {
            $resultId = cms_admin_site_tables_normalize_positive_id($result['id'] ?? 0);
            cms_admin_site_tables_redirect(cms_admin_site_tables_edit_redirect_path($resultId));
        }

        cms_admin_site_tables_store_form_state($payload['post']);
        cms_admin_site_tables_redirect(cms_admin_site_tables_edit_redirect_path($payload['id']));
    }

    cms_admin_site_tables_redirect();
}

// ─── Session-Alert abholen ───────────────────────────────
if (!empty($_SESSION['admin_alert'])) {
    $alert = $_SESSION['admin_alert'];
    unset($_SESSION['admin_alert']);
}

$csrfToken = Security::instance()->generateToken('admin_tables');
$formValues = cms_admin_site_tables_pull_form_state();

// ─── View-Routing ────────────────────────────────────────
$viewAction = cms_admin_site_tables_normalize_view_action($_GET['action'] ?? 'list');

if ($viewAction === 'settings') {
    $data       = $module->getSettingsData();
    $pageTitle  = 'Tabellen-Einstellungen';
    $activePage = 'site-tables';
    $pageAssets = cms_admin_site_tables_page_assets($viewAction);

    require __DIR__ . '/partials/header.php';
    require __DIR__ . '/partials/sidebar.php';
    require __DIR__ . '/views/tables/settings.php';
    require __DIR__ . '/partials/footer.php';
} elseif ($viewAction === 'edit') {
    $normalizedId = cms_admin_site_tables_normalize_positive_id($_GET['id'] ?? 0);
    $id        = $normalizedId > 0 ? $normalizedId : null;
    $data      = $module->getEditData($id, $formValues);

    if (!empty($data['missing'])) {
        cms_admin_site_tables_flash(['success' => false, 'error' => 'Angeforderte Tabelle wurde nicht gefunden.']);
        cms_admin_site_tables_redirect();
    }

    $pageTitle = $data['isNew'] ? 'Neue Tabelle' : 'Tabelle bearbeiten';
    $activePage = 'site-tables';
    $pageAssets = cms_admin_site_tables_page_assets($viewAction);

    require __DIR__ . '/partials/header.php';
    require __DIR__ . '/partials/sidebar.php';
    require __DIR__ . '/views/tables/edit.php';
    require __DIR__ . '/partials/footer.php';
} else {
    $data       = $module->getListData(cms_admin_site_tables_normalize_search($_GET['q'] ?? ''));
    $pageTitle  = 'Tabellen';
    $activePage = 'site-tables';
    $pageAssets = cms_admin_site_tables_page_assets($viewAction);

    require __DIR__ . '/partials/header.php';
    require __DIR__ . '/partials/sidebar.php';
    require __DIR__ . '/views/tables/list.php';
    require __DIR__ . '/partials/footer.php';
}
