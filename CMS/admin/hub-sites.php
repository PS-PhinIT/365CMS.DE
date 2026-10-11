<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hub / Routing Sites – Entry Point
 * Route: /admin/hub-sites
 */

use CMS\Auth;
use CMS\Security;
use CMS\Services\CoreModuleService;
use CMS\Services\EditorJsService;

const CMS_ADMIN_HUB_SITES_READ_CAPABILITY = 'manage_settings';
const CMS_ADMIN_HUB_SITES_WRITE_CAPABILITY = 'manage_settings';
const CMS_ADMIN_HUB_SITES_FORM_SESSION_KEY = 'admin_hub_sites_form';

function cms_admin_hub_sites_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_HUB_SITES_READ_CAPABILITY);
}

function cms_admin_hub_sites_can_mutate(): bool
{
    return cms_admin_hub_sites_can_access()
        && Auth::instance()->hasCapability(CMS_ADMIN_HUB_SITES_WRITE_CAPABILITY);
}

if (!cms_admin_hub_sites_can_access()) {
    header('Location: /');
    exit;
}

function cms_admin_hub_sites_default_url(): string
{
    return '/admin/hub-sites';
}

/**
 * @return list<string>
 */
function cms_admin_hub_sites_allowed_actions(): array
{
    return ['save', 'save-template', 'duplicate-template', 'delete-template', 'delete', 'duplicate'];
}

/**
 * @return list<string>
 */
function cms_admin_hub_sites_allowed_views(): array
{
    return ['list', 'edit', 'template-edit', 'templates'];
}

function cms_admin_hub_sites_post_redirect(string $action, array $result, array $post): string
{
    if ($action === 'save') {
        if (!empty($result['success'])) {
            if (!empty($post['open_public_after_save']) && !empty($result['slug'])) {
                return '/' . ltrim((string) $result['slug'], '/');
            }

            return '/admin/hub-sites?action=edit&id=' . (int) ($result['id'] ?? 0);
        }

        $editId = max(0, (int) ($post['id'] ?? 0));

        return $editId > 0
            ? '/admin/hub-sites?action=edit&id=' . $editId
            : '/admin/hub-sites?action=edit';
    }

    if ($action === 'save-template' || $action === 'duplicate-template') {
        if (!empty($result['success'])) {
            return '/admin/hub-sites?action=template-edit&key=' . rawurlencode((string) ($result['key'] ?? ''));
        }

        return '/admin/hub-sites?action=templates';
    }

    if ($action === 'delete-template') {
        return '/admin/hub-sites?action=templates';
    }

    return cms_admin_hub_sites_default_url();
}

function cms_admin_hub_sites_view_path(string $viewAction, array $query = []): string
{
    $viewAction = cms_admin_hub_sites_normalize_view($viewAction);
    $basePath = cms_admin_hub_sites_default_url();

    if ($viewAction !== 'list') {
        $query = array_merge(['action' => $viewAction], $query);
    }

    $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
    if ($query === []) {
        return $basePath;
    }

    return $basePath . '?' . http_build_query($query);
}

function cms_admin_hub_sites_normalize_view(string $viewAction): string
{
    return in_array($viewAction, cms_admin_hub_sites_allowed_views(), true) ? $viewAction : 'list';
}

function cms_admin_hub_sites_normalize_action(mixed $action): string
{
    $normalizedAction = strtolower(trim((string) $action));

    return in_array($normalizedAction, cms_admin_hub_sites_allowed_actions(), true) ? $normalizedAction : '';
}

function cms_admin_hub_sites_normalize_id(array $post): int
{
    $id = (int) ($post['id'] ?? 0);

    return $id > 0 ? $id : 0;
}

function cms_admin_hub_sites_normalize_key(array $post): string
{
    return trim((string) ($post['key'] ?? ''));
}

/**
 * @return array{action:string,id:int,key:string,post:array<string,mixed>}
 */
function cms_admin_hub_sites_normalize_payload(array $post): array
{
    return [
        'action' => cms_admin_hub_sites_normalize_action($post['action'] ?? ''),
        'id' => cms_admin_hub_sites_normalize_id($post),
        'key' => cms_admin_hub_sites_normalize_key($post),
        'post' => $post,
    ];
}

function cms_admin_hub_sites_store_form_state(array $post): void
{
    unset($post['csrf_token']);

    $_SESSION[CMS_ADMIN_HUB_SITES_FORM_SESSION_KEY] = [
        'values' => $post,
    ];
}

function cms_admin_hub_sites_pull_form_state(): array
{
    $state = $_SESSION[CMS_ADMIN_HUB_SITES_FORM_SESSION_KEY] ?? [];
    unset($_SESSION[CMS_ADMIN_HUB_SITES_FORM_SESSION_KEY]);

    return is_array($state['values'] ?? null) ? $state['values'] : [];
}

function cms_admin_hub_sites_handle_action(HubSitesModule $module, array $payload): array
{
    return match ($payload['action']) {
        'save' => [
            'result' => $module->save($payload['post']),
            'fallback' => 'Hub-Site konnte nicht gespeichert werden.',
        ],
        'save-template' => [
            'result' => $module->saveTemplate($payload['post']),
            'fallback' => 'Hub-Template konnte nicht gespeichert werden.',
        ],
        'duplicate-template' => [
            'result' => $module->duplicateTemplate($payload['key']),
            'fallback' => 'Hub-Template konnte nicht dupliziert werden.',
        ],
        'delete-template' => [
            'result' => $module->deleteTemplate($payload['key']),
            'fallback' => 'Hub-Template konnte nicht gelöscht werden.',
        ],
        'delete' => [
            'result' => $module->delete($payload['id']),
            'fallback' => 'Hub-Site konnte nicht gelöscht werden.',
        ],
        'duplicate' => [
            'result' => $module->duplicate($payload['id']),
            'fallback' => 'Hub-Site konnte nicht dupliziert werden.',
        ],
        default => [
            'result' => ['success' => false, 'error' => 'Unbekannte Hub-Sites-Aktion.'],
            'fallback' => 'Unbekannte Hub-Sites-Aktion.',
        ],
    };
}

/**
 * @return array{data: array<string, mixed>, pageTitle: string, activePage: string, pageAssets?: array<string, array<int, string>>, view: string}
 */
function cms_admin_hub_sites_view_config(HubSitesModule $module, string $viewAction): array
{
    $hubSiteListAssets = [
        'js' => [
            cms_asset_url('js/admin-hub-sites.js'),
        ],
    ];

    return match ($viewAction) {
        'edit' => (function () use ($module): array {
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            $data = $module->getEditData($id, cms_admin_hub_sites_pull_form_state());
            $aiTranslationEnabled = !class_exists(CoreModuleService::class)
                || CoreModuleService::getInstance()->isModuleEnabled('ai_services');
            $pageAssets = EditorJsService::getInstance()->getPageAssets();
            $pageAssets['css'] = $pageAssets['css'] ?? [];
            $pageAssets['js'] = $pageAssets['js'] ?? [];
            $pageAssets['css'][] = cms_asset_url('css/admin-hub-site-edit.css');
            $pageAssets['js'][] = cms_asset_url('js/admin-hub-site-edit.js');

            return [
                'data' => $data,
                'pageTitle' => !empty($data['isNew']) ? 'Neue Hub-Site' : 'Hub-Site bearbeiten',
                'activePage' => 'hub-sites',
                'template_vars' => [
                    'editorMediaToken' => Security::instance()->generateToken('editorjs_media'),
                    'aiTranslationEnabled' => $aiTranslationEnabled,
                    'aiTranslationToken' => $aiTranslationEnabled
                        ? Security::instance()->generateToken('admin_ai_editorjs_translation')
                        : '',
                    'aiTranslationUrl' => $aiTranslationEnabled
                        ? '/admin/ai-translate-editorjs'
                        : '',
                ],
                'pageAssets' => $pageAssets,
                'view' => __DIR__ . '/views/hub/edit.php',
            ];
        })(),
        'template-edit' => (function () use ($module): array {
            $key = isset($_GET['key']) ? (string)$_GET['key'] : null;
            $data = $module->getTemplateEditData($key);
            $pageAssets = ['css' => [], 'js' => []];

            $hubTemplateCssPath = rtrim((string)ASSETS_PATH, '/\\') . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'admin-hub-template-editor.css';
            if (is_file($hubTemplateCssPath)) {
                $pageAssets['css'][] = cms_asset_url('css/admin-hub-template-editor.css');
            }

            $hubTemplateJsPath = rtrim((string)ASSETS_PATH, '/\\') . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'admin-hub-template-editor.js';
            if (is_file($hubTemplateJsPath)) {
                $pageAssets['js'][] = cms_asset_url('js/admin-hub-template-editor.js');
            }

            return [
                'data' => $data,
                'pageTitle' => !empty($data['isNew']) ? 'Neues Hub-Template' : 'Hub-Template bearbeiten',
                'activePage' => 'hub-sites',
                'pageAssets' => $pageAssets,
                'view' => __DIR__ . '/views/hub/template-edit.php',
            ];
        })(),
        'templates' => [
            'data' => $module->getTemplateListData(),
            'pageTitle' => 'Hub-Site Templates',
            'activePage' => 'hub-sites',
            'pageAssets' => $hubSiteListAssets,
            'view' => __DIR__ . '/views/hub/templates.php',
        ],
        default => [
            'data' => $module->getListData(),
            'pageTitle' => 'Hub-Sites',
            'activePage' => 'hub-sites',
            'pageAssets' => $hubSiteListAssets,
            'view' => __DIR__ . '/views/hub/list.php',
        ],
    };
}

$sectionPageConfig = [
    'route_path' => '/admin/hub-sites',
    'view_file' => __DIR__ . '/views/hub/list.php',
    'page_title' => 'Hub-Sites',
    'active_page' => 'hub-sites',
    'csrf_action' => 'admin_hub_sites',
    'module_file' => __DIR__ . '/modules/hub/HubSitesModule.php',
    'module_factory' => static fn (): HubSitesModule => new HubSitesModule(),
    'access_checker' => static fn (): bool => cms_admin_hub_sites_can_access(),
    'access_denied_route' => '/',
    'request_context_resolver' => static function (HubSitesModule $module): array {
        $viewAction = cms_admin_hub_sites_normalize_view((string) ($_GET['action'] ?? 'list'));
        $viewConfig = cms_admin_hub_sites_view_config($module, $viewAction);

        return [
            'section' => $viewAction,
            'data' => $viewConfig['data'] ?? [],
            'view_file' => $viewConfig['view'] ?? (__DIR__ . '/views/hub/list.php'),
            'page_title' => $viewConfig['pageTitle'] ?? 'Hub-Sites',
            'active_page' => $viewConfig['activePage'] ?? 'hub-sites',
            'page_assets' => $viewConfig['pageAssets'] ?? ['css' => [], 'js' => []],
            'template_vars' => $viewConfig['template_vars'] ?? [],
        ];
    },
    'redirect_path_resolver' => static function ($module, string $section, $result): string {
        if (is_array($result) && !empty($result['__redirect'])) {
            return (string) $result['__redirect'];
        }

        $query = [];
        if ($section === 'edit') {
            $query['id'] = isset($_GET['id']) ? (int) $_GET['id'] : null;
        }
        if ($section === 'template-edit') {
            $query['key'] = isset($_GET['key']) ? (string) $_GET['key'] : null;
        }

        return cms_admin_hub_sites_view_path($section, $query);
    },
    'unknown_action_message' => 'Unbekannte Hub-Sites-Aktion.',
    'post_handler' => static function (HubSitesModule $module, string $section, array $post): array {
        if (!cms_admin_hub_sites_can_mutate()) {
            return ['success' => false, 'error' => 'Keine Berechtigung für Hub-Sites-Änderungen.'];
        }

        $payload = cms_admin_hub_sites_normalize_payload($post);

        if ($payload['action'] === '') {
            return ['success' => false, 'error' => 'Unbekannte Hub-Sites-Aktion.'];
        }

        if (in_array($payload['action'], ['delete', 'duplicate'], true) && $payload['id'] <= 0) {
            return ['success' => false, 'error' => 'Ungültige Hub-Site-ID.'];
        }

        if (in_array($payload['action'], ['delete-template', 'duplicate-template'], true) && $payload['key'] === '') {
            return ['success' => false, 'error' => 'Ungültiger Template-Schlüssel.'];
        }

        $handledAction = cms_admin_hub_sites_handle_action($module, $payload);
        $result = is_array($handledAction['result'] ?? null)
            ? $handledAction['result']
            : ['success' => false, 'error' => 'Unbekannte Hub-Sites-Aktion.'];

        if ($payload['action'] === 'save' && empty($result['success'])) {
            cms_admin_hub_sites_store_form_state($payload['post']);
        }

        if (empty($result['message']) && !empty($handledAction['fallback'])) {
            $result['error'] = (string) ($result['error'] ?? $handledAction['fallback']);
        }

        $result['__redirect'] = cms_admin_hub_sites_post_redirect($payload['action'], $result, $payload['post']);

        return $result;
    },
];

require __DIR__ . '/partials/section-page-shell.php';
