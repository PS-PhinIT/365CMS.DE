<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulare – Entry Point
 * Route: /admin/forms
 *
 * Tabs: forms (Liste, Baukasten über ?action=edit&id=…), settings (globale Standards für alle Formulare).
 * Eingänge liegen unter /admin/form-submissions.
 */

use CMS\Auth;

const CMS_ADMIN_FORMS_CAPABILITY = 'manage_forms';

function cms_admin_forms_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_FORMS_CAPABILITY);
}

if (!cms_admin_forms_can_access()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/forms/FormsModule.php';

function cms_admin_forms_normalize_tab(mixed $tab): string
{
    $tab = strtolower(trim(is_scalar($tab) ? (string) $tab : ''));

    return in_array($tab, FormsModule::TABS, true) ? $tab : 'forms';
}

/**
 * @return array<string, mixed>
 */
function cms_admin_forms_request_context(FormsModule $module): array
{
    $tab = cms_admin_forms_normalize_tab($_GET['tab'] ?? 'forms');
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $action = strtolower(trim(is_scalar($_GET['action'] ?? null) ? (string) $_GET['action'] : ''));

    if ($isPost) {
        return ['section' => $tab, 'data' => []];
    }

    if ($tab === 'settings') {
        return ['section' => 'settings', 'data' => $module->getSettingsData()];
    }

    if ($action === 'edit') {
        $data = $module->getEditData((int) ($_GET['id'] ?? 0));
        if ($data === null) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('forms.admin.error_not_found')];
            header('Location: /admin/forms', true, 303);
            exit;
        }

        return [
            'section' => 'forms',
            'page_title' => sprintf(__('forms.admin.edit_title'), (string) $data['form']['title']),
            'page_assets' => [
                'css' => [cms_asset_url('css/admin-forms.css')],
                'js' => [cms_asset_url('js/admin-form-builder.js')],
            ],
            'data' => $data,
        ];
    }

    return [
        'section' => 'forms',
        'page_assets' => ['css' => [cms_asset_url('css/admin-forms.css')], 'js' => []],
        'data' => $module->getListData($_GET),
    ];
}

$sectionPageConfig = [
    'section' => 'forms',
    'route_path' => '/admin/forms',
    'view_file' => __DIR__ . '/views/forms/index.php',
    'page_title' => __('forms.admin.page_title'),
    'active_page' => 'forms',
    'csrf_action' => 'admin_forms',
    'module_factory' => static fn (): FormsModule => new FormsModule(),
    'access_checker' => static fn (): bool => cms_admin_forms_can_access(),
    'access_denied_route' => '/',
    'request_context_resolver' => static fn (FormsModule $module): array => cms_admin_forms_request_context($module),
    'redirect_path_resolver' => static function ($module, string $section, mixed $result = null): string {
        if (is_array($result) && is_string($result['__redirect'] ?? null)) {
            return (string) $result['__redirect'];
        }

        return FormsModule::url(['tab' => $section === 'settings' ? 'settings' : null]);
    },
    'post_handler' => static function (FormsModule $module, string $section, array $post): array {
        if (!cms_admin_forms_can_access()) {
            return ['success' => false, 'error' => __('forms.admin.error_forbidden')];
        }

        return $module->handlePost($post, (int) (Auth::instance()->currentUser()->id ?? 0));
    },
    'invalid_token_message' => __('forms.admin.error_token'),
    'unknown_action_message' => __('forms.admin.error_unknown_action'),
];

require __DIR__ . '/partials/section-page-shell.php';
