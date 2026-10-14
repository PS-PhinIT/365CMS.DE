<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Services\CoreModuleService;

/**
 * @return array<string, array{route_path: string, view_file: string, page_title: string, active_page: string, actions: list<string>}>
 */
function cms_admin_seo_allowed_page_configs(): array
{
    return [
        'dashboard' => [
            'route_path' => '/admin/seo-dashboard',
            'view_file' => __DIR__ . '/views/seo/dashboard.php',
            'page_title' => 'SEO Dashboard',
            'active_page' => 'seo-dashboard',
            'actions' => ['regenerate_sitemap_bundle', 'save_robots'],
        ],
        'audit' => [
            'route_path' => '/admin/seo-audit',
            'view_file' => __DIR__ . '/views/seo/audit.php',
            'page_title' => 'SEO Audit',
            'active_page' => 'seo-audit',
            'actions' => ['save_audit_item', 'regenerate_sitemap_bundle', 'save_robots'],
        ],
        'meta' => [
            'route_path' => '/admin/seo-meta',
            'view_file' => __DIR__ . '/views/seo/meta.php',
            'page_title' => 'SEO Meta-Daten',
            'active_page' => 'seo-meta',
            'actions' => ['save_meta_defaults', 'save_templates', 'import_post_meta_csv', 'regenerate_sitemap_bundle', 'save_robots'],
        ],
        'social' => [
            'route_path' => '/admin/seo-social',
            'view_file' => __DIR__ . '/views/seo/social.php',
            'page_title' => 'SEO Social',
            'active_page' => 'seo-social',
            'actions' => ['save_social_defaults', 'regenerate_sitemap_bundle', 'save_robots'],
        ],
        'schema' => [
            'route_path' => '/admin/seo-schema',
            'view_file' => __DIR__ . '/views/seo/schema.php',
            'page_title' => 'SEO Strukturierte Daten',
            'active_page' => 'seo-schema',
            'actions' => ['save_schema_defaults', 'regenerate_sitemap_bundle', 'save_robots'],
        ],
        'sitemap' => [
            'route_path' => '/admin/seo-sitemap',
            'view_file' => __DIR__ . '/views/seo/sitemap.php',
            'page_title' => 'SEO Sitemap',
            'active_page' => 'seo-sitemap',
            'actions' => ['save_sitemap_settings', 'submit_indexing_urls', 'submit_recent_content_indexnow', 'delete_google_url', 'save_google_access_token', 'clear_google_access_token', 'regenerate_sitemap_bundle', 'save_robots'],
        ],
        'technical' => [
            'route_path' => '/admin/seo-technical',
            'view_file' => __DIR__ . '/views/seo/technical.php',
            'page_title' => 'Technisches SEO',
            'active_page' => 'seo-technical',
            'actions' => ['save_technical_settings', 'run_broken_link_scan', 'ignore_broken_link_target', 'unignore_broken_link_target', 'regenerate_sitemap_bundle', 'save_robots'],
        ],
        'analytics' => [
            'route_path' => '/admin/analytics',
            'view_file' => __DIR__ . '/views/seo/analytics.php',
            'page_title' => 'SEO Analytics',
            'active_page' => 'analytics',
            'actions' => ['save_analytics_settings', 'regenerate_sitemap_bundle', 'save_robots'],
        ],
    ];
}

/**
 * @return array<string, list<string>>
 */
function cms_admin_seo_read_capabilities_by_section(): array
{
    return [
        'dashboard' => ['manage_settings'],
        'audit' => ['manage_settings'],
        'meta' => ['manage_settings'],
        'social' => ['manage_settings'],
        'schema' => ['manage_settings'],
        'sitemap' => ['manage_settings'],
        'technical' => ['manage_settings'],
        'analytics' => ['manage_settings', 'view_analytics'],
    ];
}

function cms_admin_seo_write_capability_by_section(string $section): string
{
    return 'manage_settings';
}

function cms_admin_seo_has_any_capability(array $capabilities): bool
{
    foreach ($capabilities as $capability) {
        if (is_string($capability) && $capability !== '' && Auth::instance()->hasCapability($capability)) {
            return true;
        }
    }

    return false;
}

function cms_admin_seo_can_access_section(string $section): bool
{
    $capabilities = cms_admin_seo_read_capabilities_by_section()[$section] ?? ['manage_settings'];

    return Auth::instance()->isAdmin() && cms_admin_seo_has_any_capability($capabilities);
}

function cms_admin_seo_can_mutate_section(string $section): bool
{
    return cms_admin_seo_can_access_section($section)
        && Auth::instance()->hasCapability(cms_admin_seo_write_capability_by_section($section));
}

/**
 * @param array<string, mixed> $pageConfig
 * @return array{
 *     section: string,
 *     route_path: string,
 *     view_file: string,
 *     page_title: string,
 *     active_page: string
 * }
 */
function cms_admin_seo_normalize_page_config(array $pageConfig): array
{
    $allowedConfigs = cms_admin_seo_allowed_page_configs();
    $section = strtolower(trim((string)($pageConfig['section'] ?? 'dashboard')));

    if (!isset($allowedConfigs[$section])) {
        $section = 'dashboard';
    }

    $normalized = $allowedConfigs[$section];

    return [
        'section' => $section,
        'route_path' => (string) $normalized['route_path'],
        'view_file' => (string) $normalized['view_file'],
        'page_title' => (string) $normalized['page_title'],
        'active_page' => (string) $normalized['active_page'],
    ];
}

/**
 * @return list<string>
 */
function cms_admin_seo_allowed_routes(): array
{
    $routes = [];

    foreach (cms_admin_seo_allowed_page_configs() as $config) {
        $routes[] = (string)$config['route_path'];
    }

    return array_values(array_unique($routes));
}

function cms_admin_seo_normalize_action(string $section, mixed $action): string
{
    $normalizedAction = strtolower(trim((string)$action));
    $allowedConfigs = cms_admin_seo_allowed_page_configs();
    $allowedActions = $allowedConfigs[$section]['actions'] ?? [];

    return in_array($normalizedAction, $allowedActions, true) ? $normalizedAction : '';
}

function cms_admin_seo_normalize_redirect_target(mixed $returnTo, string $fallback): string
{
    $target = trim((string)$returnTo);
    if ($target === '') {
        return $fallback;
    }

    $targetPath = (string)parse_url($target, PHP_URL_PATH);
    if ($targetPath === '') {
        return $fallback;
    }

    return in_array($targetPath, cms_admin_seo_allowed_routes(), true) ? $targetPath : $fallback;
}

$seoPageConfig = cms_admin_seo_normalize_page_config(array_merge(
    [
        'section' => $seoSection ?? 'dashboard',
        'route_path' => $seoRoutePath ?? '/admin/seo-dashboard',
        'view_file' => $seoViewFile ?? (__DIR__ . '/views/seo/dashboard.php'),
        'page_title' => $pageTitle ?? 'SEO',
        'active_page' => $activePage ?? 'seo-dashboard',
    ],
    is_array($seoPageConfig ?? null) ? $seoPageConfig : []
));

$seoSection = $seoPageConfig['section'];
$seoRoutePath = $seoPageConfig['route_path'];
$seoViewFile = $seoPageConfig['view_file'];
$pageTitle = $seoPageConfig['page_title'];
$activePage = $seoPageConfig['active_page'];

if ($seoSection === 'meta' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['export'] ?? null) === 'posts_csv') {
    if (!cms_admin_seo_can_access_section($seoSection)
        || (class_exists(CoreModuleService::class) && !CoreModuleService::getInstance()->isAdminPageEnabled($activePage))
    ) {
        header('Location: /');
        exit;
    }

    require_once __DIR__ . '/modules/seo/SeoSuiteModule.php';

    try {
        (new SeoSuiteModule())->streamPostMetaCsvExport();
    } catch (\Throwable $e) {
        \CMS\Logger::instance()->withChannel('seo')->warning('CSV-Export der Beitrags-SEO-Metadaten fehlgeschlagen.', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => 'Der CSV-Export konnte nicht erstellt werden.', 'details' => []];
        header('Location: ' . $seoRoutePath);
        exit;
    }
}

$sectionPageConfig = [
    'section' => $seoSection,
    'route_path' => $seoRoutePath,
    'view_file' => $seoViewFile,
    'page_title' => $pageTitle,
    'active_page' => $activePage,
    'csrf_action' => 'admin_seo_suite',
    'guard_constant' => 'CMS_ADMIN_SEO_VIEW',
    'module_file' => __DIR__ . '/modules/seo/SeoSuiteModule.php',
    'module_factory' => static fn (): SeoSuiteModule => new SeoSuiteModule(),
    'access_checker' => static fn (): bool => cms_admin_seo_can_access_section($seoSection)
        && (!class_exists(CoreModuleService::class) || CoreModuleService::getInstance()->isAdminPageEnabled($activePage)),
    'invalid_token_message' => 'Sicherheitstoken ungültig.',
    'unknown_action_message' => 'Unbekannte Antwort.',
    'redirect_path_resolver' => static function (SeoSuiteModule $module, string $section, ?array $result = null) use ($seoRoutePath): string {
        $fallback = $seoRoutePath;
        $requestedTarget = $_POST['return_to'] ?? ($result['redirect_path'] ?? '');

        return cms_admin_seo_normalize_redirect_target($requestedTarget, $fallback);
    },
    'data_loader' => static function (SeoSuiteModule $module) use ($seoSection): array {
        return method_exists($module, 'getSectionData')
            ? $module->getSectionData($seoSection)
            : $module->getData($seoSection);
    },
    'request_context_resolver' => static function (SeoSuiteModule $module, string $section): array {
        if ($section !== 'meta') {
            return [];
        }

        return [
            'page_assets' => [
                'js' => [cms_asset_url('js/admin-seo-meta.js')],
            ],
        ];
    },
    'post_handler' => static function (SeoSuiteModule $module, string $section, array $post): array {
        if (!cms_admin_seo_can_mutate_section($section)) {
            return [
                'success' => false,
                'error' => 'Keine Berechtigung für SEO-Mutationen in diesem Bereich.',
            ];
        }

        $action = cms_admin_seo_normalize_action($section, $post['action'] ?? '');
        if ($action === '') {
            return [
                'success' => false,
                'error' => 'Unbekannte oder für diese SEO-Seite unzulässige Aktion.',
            ];
        }

        $result = $action === 'import_post_meta_csv'
            ? $module->importPostMetaCsv($_FILES['post_meta_csv'] ?? null)
            : $module->handleAction($section, $action, $post);
        return is_array($result) ? $result : [
            'success' => false,
            'error' => 'Unbekannte Antwort.',
        ];
    },
];

require __DIR__ . '/partials/section-page-shell.php';
