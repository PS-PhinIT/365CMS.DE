<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Allgemeine Einstellungen – Entry Point
 * Route: /admin/settings
 */

use CMS\Auth;
use CMS\Security;

const CMS_ADMIN_SETTINGS_CAPABILITY = 'manage_settings';
const CMS_ADMIN_SETTINGS_FORM_STATE_SESSION_KEY = 'admin_settings_form_state';

/**
 * Eigenständige Einstellungsbereiche, die als Tab unter /admin/settings?tab=<schlüssel> erscheinen.
 * Jede Datei unter admin/settings-tabs/ ist ein vollständiger Controller (GET/POST, eigenes CSRF,
 * eigenes isAdmin- und Capability-Gate) und nutzt für die Tab-Leiste views/settings/partials-tabs.php.
 * Die Weiche steht deshalb vor dem manage_settings-Gate dieser Seite: Ein Tab kann eine andere
 * Capability verlangen (z. B. manage_feeds, manage_admin_menu).
 */
const CMS_ADMIN_SETTINGS_EXTENSION_TABS = [
    'feeds' => 'feeds.php',
    'security' => 'security.php',
    'admin-menu' => 'admin-menu.php',
];

$cmsSettingsExtensionTab = (string) ($_GET['tab'] ?? '');
if (isset(CMS_ADMIN_SETTINGS_EXTENSION_TABS[$cmsSettingsExtensionTab])
    && is_file(__DIR__ . '/settings-tabs/' . CMS_ADMIN_SETTINGS_EXTENSION_TABS[$cmsSettingsExtensionTab])
) {
    require __DIR__ . '/settings-tabs/' . CMS_ADMIN_SETTINGS_EXTENSION_TABS[$cmsSettingsExtensionTab];
    return;
}

function cms_admin_settings_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_SETTINGS_CAPABILITY);
}

if (!cms_admin_settings_can_access()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/settings/SettingsModule.php';

function cms_admin_settings_normalize_tab(string $tab): string
{
    return $tab === 'content' ? 'content' : 'general';
}

function cms_admin_settings_redirect(string $tab): never
{
    header('Location: ' . cms_admin_settings_redirect_url($tab));
    exit;
}

function cms_admin_settings_redirect_url(string $tab): string
{
    return '/admin/settings?tab=' . cms_admin_settings_normalize_tab($tab);
}

function cms_admin_settings_flash(array $payload): void
{
    $_SESSION['admin_alert'] = [
        'type' => in_array(($payload['type'] ?? 'danger'), ['success', 'warning', 'info', 'secondary'], true)
            ? (string) ($payload['type'] ?? 'danger')
            : 'danger',
        'message' => trim((string) ($payload['message'] ?? '')),
        'details' => array_values(array_filter(array_map(
            static fn ($detail): string => trim((string) $detail),
            is_array($payload['details'] ?? null) ? $payload['details'] : []
        ), static fn (string $detail): bool => $detail !== '')),
        'error_details' => is_array($payload['error_details'] ?? null) ? $payload['error_details'] : [],
        'report_payload' => is_array($payload['report_payload'] ?? null) ? $payload['report_payload'] : [],
    ];
}

function cms_admin_settings_flash_result(array $result): void
{
    cms_admin_settings_flash([
        'type' => !empty($result['success']) ? 'success' : 'danger',
        'message' => (string) ($result['message'] ?? $result['error'] ?? ''),
        'details' => is_array($result['details'] ?? null) ? $result['details'] : [],
        'error_details' => is_array($result['error_details'] ?? null) ? $result['error_details'] : [],
        'report_payload' => is_array($result['report_payload'] ?? null) ? $result['report_payload'] : [],
    ]);
}

function cms_admin_settings_pull_alert(): ?array
{
    $alert = $_SESSION['admin_alert'] ?? null;
    unset($_SESSION['admin_alert']);

    return is_array($alert) ? $alert : null;
}

function cms_admin_settings_clear_form_state(): void
{
    unset($_SESSION[CMS_ADMIN_SETTINGS_FORM_STATE_SESSION_KEY]);
}

function cms_admin_settings_store_form_state(string $tab, array $post, array $invalidFields = []): void
{
    $values = [];
    foreach ($post as $key => $value) {
        if (in_array($key, ['csrf_token', 'action'], true)) {
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $values[(string) $key] = (string) $value;
        }
    }

    $_SESSION[CMS_ADMIN_SETTINGS_FORM_STATE_SESSION_KEY] = [
        'tab' => cms_admin_settings_normalize_tab($tab),
        'values' => $values,
        'invalid_fields' => array_values(array_filter(array_map(
            static fn ($field): string => trim((string) $field),
            $invalidFields
        ), static fn (string $field): bool => $field !== '')),
    ];
}

function cms_admin_settings_pull_form_state(string $expectedTab): array
{
    $state = $_SESSION[CMS_ADMIN_SETTINGS_FORM_STATE_SESSION_KEY] ?? null;
    unset($_SESSION[CMS_ADMIN_SETTINGS_FORM_STATE_SESSION_KEY]);

    if (!is_array($state)) {
        return [];
    }

    $tab = cms_admin_settings_normalize_tab((string) ($state['tab'] ?? 'general'));
    if ($tab !== cms_admin_settings_normalize_tab($expectedTab)) {
        return [];
    }

    return [
        'values' => is_array($state['values'] ?? null) ? $state['values'] : [],
        'invalid_fields' => is_array($state['invalid_fields'] ?? null) ? $state['invalid_fields'] : [],
    ];
}

/**
 * @return array<string, callable(array): array>
 */
function cms_admin_settings_action_handlers(SettingsModule $module): array
{
    return [
        'save' => static fn (array $post): array => $module->saveSettings($post),
        'run_site_url_migration' => static fn (array $post): array => $module->runSiteUrlMigration($post),
        'repair_imported_slugs' => static fn (array $post): array => $module->repairImportedSlugs(),
        'send_test_email' => static fn (array $post): array => $module->sendTestEmail($post),
    ];
}

$module = new SettingsModule();
$alert = null;
$currentTab = cms_admin_settings_normalize_tab((string) ($_GET['tab'] ?? 'general'));
$actionHandlers = cms_admin_settings_action_handlers($module);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postToken = (string) ($_POST['csrf_token'] ?? '');
    $redirectTab = cms_admin_settings_normalize_tab((string) ($_POST['tab'] ?? 'general'));
    $action = (string) ($_POST['action'] ?? '');

    if (!Security::instance()->verifyToken($postToken, 'admin_settings')) {
        cms_admin_settings_clear_form_state();
        cms_admin_settings_flash(['type' => 'danger', 'message' => 'Sicherheitstoken ungültig.']);
        cms_admin_settings_redirect($redirectTab);
    }

    $currentTab = $redirectTab;
    if (!isset($actionHandlers[$action])) {
        cms_admin_settings_clear_form_state();
        cms_admin_settings_flash(['type' => 'danger', 'message' => 'Unbekannte Aktion.']);
        cms_admin_settings_redirect($currentTab);
    }

    $result = $actionHandlers[$action]($_POST);
    if ($action === 'save' && empty($result['success'])) {
        cms_admin_settings_store_form_state(
            $currentTab,
            $_POST,
            is_array($result['invalid_fields'] ?? null) ? $result['invalid_fields'] : []
        );
    } else {
        cms_admin_settings_clear_form_state();
    }
    cms_admin_settings_flash_result($result);

    cms_admin_settings_redirect($currentTab);
}

$alert = cms_admin_settings_pull_alert();
$formState = cms_admin_settings_pull_form_state($currentTab);

$csrfToken  = Security::instance()->generateToken('admin_settings');
$editorMediaToken = Security::instance()->generateToken('editorjs_media');
$data       = $module->getData();
$pageTitle  = $currentTab === 'content' ? 'Beiträge & Sites' : 'Allgemeine Einstellungen';
$activePage = $currentTab === 'content' ? 'content-settings' : 'settings';
$pageAssets = [
    'js' => array_values(array_filter([
        cms_asset_url('js/admin-settings.js'),
        $currentTab === 'general' ? cms_asset_url('js/admin-media-integrations.js') : null,
    ])),
];
$formValues = is_array($formState['values'] ?? null) ? $formState['values'] : [];
$invalidFields = is_array($formState['invalid_fields'] ?? null) ? $formState['invalid_fields'] : [];

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/views/settings/general.php';
require __DIR__ . '/partials/footer.php';
