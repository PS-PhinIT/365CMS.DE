<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Knowledgebase – Entry Point (ehemals Plugin cms-knowledgebase, seit 4.0.0 im Core)
 * Route: /admin/knowledgebase
 *
 * Bereiche (?section=…): dashboard (Übersicht), entries (Liste), edit (&id=… – ohne ID neuer Eintrag),
 * categories (&edit=…), settings (&tab=general|design|import|system).
 * ?download=csv-template liefert die Beispiel-CSV mit allen Feldern.
 */

use CMS\Auth;

const CMS_ADMIN_KNOWLEDGEBASE_CAPABILITY = 'manage_knowledgebase';

function cms_admin_knowledgebase_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_KNOWLEDGEBASE_CAPABILITY);
}

if (!cms_admin_knowledgebase_can_access()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/knowledgebase/KnowledgebaseModule.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['download'] ?? '') === 'csv-template') {
    if (!(new KnowledgebaseModule())->streamCsvTemplate()) {
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('knowledgebase.admin.error_template_missing')];
        header('Location: ' . KnowledgebaseModule::url(['section' => 'settings', 'tab' => 'import']), true, 303);
        exit;
    }
}

/**
 * @return array<string, mixed>
 */
function cms_admin_knowledgebase_request_context(KnowledgebaseModule $module): array
{
    $section = is_string($_GET['section'] ?? null) && in_array($_GET['section'], KnowledgebaseModule::SECTIONS, true)
        ? (string) $_GET['section']
        : 'dashboard';
    $assets = [
        'css' => [cms_asset_url('css/admin-knowledgebase.css')],
        'js' => [cms_asset_url('js/admin-knowledgebase.js')],
    ];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        return ['section' => $section, 'data' => []];
    }

    if ($section === 'edit') {
        $data = $module->getEditData((int) ($_GET['id'] ?? 0));
        if ($data === null) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('knowledgebase.admin.error_not_found')];
            header('Location: ' . KnowledgebaseModule::url(['section' => 'entries']), true, 303);
            exit;
        }

        return [
            'section' => 'edit',
            'page_title' => !empty($data['is_new'])
                ? __('knowledgebase.admin.new_title')
                : sprintf(__('knowledgebase.admin.edit_title'), (string) ($data['entry']['title'] ?? '')),
            'page_assets' => $assets,
            'data' => $data,
        ];
    }

    $tab = is_string($_GET['tab'] ?? null) && in_array($_GET['tab'], KnowledgebaseModule::SETTINGS_TABS, true) ? (string) $_GET['tab'] : 'general';
    $data = match ($section) {
        'entries' => $module->getEntriesData($_GET),
        'categories' => $module->getCategoriesData((int) ($_GET['edit'] ?? 0)),
        'settings' => $module->getSettingsData($tab),
        default => $module->getDashboardData(),
    };

    return ['section' => $section, 'page_assets' => $assets, 'data' => $data];
}

$sectionPageConfig = [
    'section' => 'dashboard',
    'route_path' => '/admin/knowledgebase',
    'view_file' => __DIR__ . '/views/knowledgebase/index.php',
    'page_title' => __('knowledgebase.admin.page_title'),
    'active_page' => 'knowledgebase',
    'csrf_action' => 'admin_knowledgebase',
    'module_factory' => static fn (): KnowledgebaseModule => new KnowledgebaseModule(),
    'access_checker' => static fn (): bool => cms_admin_knowledgebase_can_access(),
    'access_denied_route' => '/',
    'request_context_resolver' => static fn (KnowledgebaseModule $module): array => cms_admin_knowledgebase_request_context($module),
    'redirect_path_resolver' => static function ($module, string $section, mixed $result = null): string {
        if (is_array($result) && is_string($result['__redirect'] ?? null)) {
            return (string) $result['__redirect'];
        }

        return KnowledgebaseModule::url(['section' => $section !== 'dashboard' ? $section : null]);
    },
    'post_handler' => static function (KnowledgebaseModule $module, string $section, array $post): array {
        return $module->handlePost($post, (int) (Auth::instance()->currentUser()->id ?? 0));
    },
    'invalid_token_message' => __('knowledgebase.admin.error_token'),
    'unknown_action_message' => __('knowledgebase.admin.error_unknown_action'),
];

require __DIR__ . '/partials/section-page-shell.php';
