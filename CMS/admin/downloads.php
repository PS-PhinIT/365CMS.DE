<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Downloads – Entry Point
 * Route: /admin/downloads
 *
 * Tabs: list (Liste, ?action=new, ?action=edit&id=…&section=…), stats, categories, settings.
 * Verwalten (`manage_downloads`) umfasst Anlegen, Bearbeiten, Zugriffsregeln, Löschen und Einstellungen;
 * mit `view_download_stats` allein sind nur die Statistiken sichtbar.
 * ?export=csv[&period=…][&id=…] liefert die Statistik als CSV.
 */

use CMS\Auth;

const CMS_ADMIN_DOWNLOADS_MANAGE_CAPABILITY = 'manage_downloads';
const CMS_ADMIN_DOWNLOADS_STATS_CAPABILITY = 'view_download_stats';

function cms_admin_downloads_can_manage(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_DOWNLOADS_MANAGE_CAPABILITY);
}

function cms_admin_downloads_can_view_stats(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_DOWNLOADS_STATS_CAPABILITY);
}

function cms_admin_downloads_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && (Auth::instance()->hasCapability(CMS_ADMIN_DOWNLOADS_MANAGE_CAPABILITY)
            || Auth::instance()->hasCapability(CMS_ADMIN_DOWNLOADS_STATS_CAPABILITY));
}

if (!cms_admin_downloads_can_access()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/downloads/DownloadsModule.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['export'] ?? '') === 'csv') {
    $cmsDownloadsExport = new DownloadsModule(cms_admin_downloads_can_manage(), cms_admin_downloads_can_view_stats());
    if ($cmsDownloadsExport->isAvailable() && (cms_admin_downloads_can_view_stats() || cms_admin_downloads_can_manage())) {
        $cmsDownloadsExportId = (int) ($_GET['id'] ?? 0);
        $cmsDownloadsExport->streamStatsCsv((int) ($_GET['period'] ?? 30), $cmsDownloadsExportId > 0 ? $cmsDownloadsExportId : null);
    }
    unset($cmsDownloadsExport);
}

/**
 * @return array<string, mixed>
 */
function cms_admin_downloads_request_context(DownloadsModule $module): array
{
    $canManage = cms_admin_downloads_can_manage();
    $tab = in_array($_GET['tab'] ?? '', DownloadsModule::TABS, true) ? (string) $_GET['tab'] : 'list';
    if (!$canManage) {
        // Nur Statistik: Liste, Kategorien und Einstellungen sind nicht erreichbar.
        $tab = 'stats';
    }
    $assets = ['css' => [cms_asset_url('css/admin-downloads.css')], 'js' => [cms_asset_url('js/admin-downloads.js')]];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        return ['section' => $tab, 'data' => []];
    }

    $action = is_scalar($_GET['action'] ?? null) ? (string) $_GET['action'] : '';
    if ($tab === 'list' && $action === 'new') {
        return ['section' => 'list', 'page_title' => __('downloads.admin.new_title'), 'page_assets' => $assets, 'data' => $module->getNewData()];
    }
    if (($tab === 'list' || !$canManage) && $action === 'edit') {
        $data = $module->getEditData((int) ($_GET['id'] ?? 0), is_scalar($_GET['section'] ?? null) ? (string) $_GET['section'] : 'general', (int) ($_GET['period'] ?? 30));
        if ($data === null) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('downloads.error.not_found')];
            header('Location: /admin/downloads', true, 303);
            exit;
        }

        return [
            'section' => 'list',
            'page_title' => sprintf(__('downloads.admin.edit_title'), (string) $data['item']['title']),
            'page_assets' => $assets,
            'data' => $data,
        ];
    }

    $data = match ($tab) {
        'stats' => $module->getStatsData((int) ($_GET['period'] ?? 30)),
        'categories' => $module->getCategoriesData((int) ($_GET['edit'] ?? 0)),
        'settings' => $module->getSettingsData(),
        default => $module->getListData($_GET),
    };

    return ['section' => $tab, 'page_assets' => $assets, 'data' => $data];
}

$sectionPageConfig = [
    'section' => 'list',
    'route_path' => '/admin/downloads',
    'view_file' => __DIR__ . '/views/downloads/index.php',
    'page_title' => __('downloads.admin.page_title'),
    'active_page' => 'downloads',
    'csrf_action' => 'admin_downloads',
    'module_factory' => static fn (): DownloadsModule => new DownloadsModule(cms_admin_downloads_can_manage(), cms_admin_downloads_can_view_stats()),
    'access_checker' => static fn (): bool => cms_admin_downloads_can_access(),
    'access_denied_route' => '/',
    'request_context_resolver' => static fn (DownloadsModule $module): array => cms_admin_downloads_request_context($module),
    'redirect_path_resolver' => static function ($module, string $section, mixed $result = null): string {
        if (is_array($result) && is_string($result['__redirect'] ?? null)) {
            return (string) $result['__redirect'];
        }

        return DownloadsModule::url(['tab' => $section !== 'list' ? $section : null]);
    },
    'post_handler' => static function (DownloadsModule $module, string $section, array $post): array {
        if (!cms_admin_downloads_can_manage()) {
            return ['success' => false, 'error' => __('downloads.admin.error_forbidden')];
        }

        return $module->handlePost($post, $_FILES, (int) (Auth::instance()->currentUser()->id ?? 0));
    },
    'invalid_token_message' => __('downloads.admin.error_token'),
    'unknown_action_message' => __('downloads.admin.error_unknown_action'),
];

require __DIR__ . '/partials/section-page-shell.php';
