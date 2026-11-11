<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kachelseiten des Admin-Menüs – Entry Point
 * Route: /admin/configuration[?group=<id>]
 *
 * Gruppen im Anzeigemodus „tiles“ (Einstellungen bzw. Konfiguration, Website-Zustand bzw. Berichte,
 * Mitgliederbereich, eigene Kachelgruppen) stehen in der Sidebar als ein Link; ihre Einträge zeigt
 * diese Seite als Kacheln, gegliedert nach Kategorie. Ohne `group` gilt die Wurzelgruppe des aktiven
 * Layouts (`root_group`). Ein ungültiger oder unbekannter group-Wert sowie Gruppen, die im aktiven
 * Layout keine Kachelseite sind oder keine sichtbaren Einträge haben, liefern 404 – kein stiller
 * Fallback (KLARSTELLUNGEN §7). Sichtbarkeit, Reihenfolge und Badges stammen aus demselben Menübaum
 * wie die Sidebar; Zugriffsrechte prüft jede Zielseite selbst.
 */

use CMS\Auth;
use CMS\Services\AdminMenu\AdminMenuService;
use CMS\Services\AdminMenu\LayoutValidator;
use CMS\Services\AdminNavigationService;
use CMS\Services\AdminSiteContextService;

if (!Auth::instance()->isAdmin()) {
    header('Location: /');
    exit;
}

$configurationSiteUrl = rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/');
try {
    $configurationSiteKey = (string) (AdminSiteContextService::getInstance()->getCurrentSite()['key'] ?? '');
} catch (\Throwable) {
    $configurationSiteKey = '';
}

$configurationMenu = AdminMenuService::getInstance();
$configurationTree = $configurationMenu->tree(
    $configurationMenu->registeredPluginMenus(),
    $configurationSiteKey,
    $configurationSiteUrl
)['tree'];
$configurationRootGroup = (string) ($configurationTree['root_group'] ?? '');

$configurationGroupParam = $_GET['group'] ?? null;
$configurationGroupId = '';
if ($configurationGroupParam === null) {
    $configurationGroupId = $configurationRootGroup;
} elseif (is_string($configurationGroupParam) && preg_match(LayoutValidator::NODE_ID_PATTERN, $configurationGroupParam) === 1) {
    $configurationGroupId = $configurationGroupParam;
}

$configurationContext = [
    'site_url' => $configurationSiteUrl,
    'logs_badge' => max(0, (int) ($_SESSION['admin_unread_notifications'] ?? 0)),
];
$configurationNavigation = AdminNavigationService::getInstance();
$configurationPage = $configurationGroupId !== ''
    ? $configurationNavigation->tilesPage($configurationTree, $configurationGroupId, $configurationContext)
    : null;

$activePage = 'configuration';
$pageAssets = [
    'css' => [cms_asset_url('css/admin-configuration.css')],
];

if ($configurationPage === null) {
    http_response_code(404);
    $configurationRootPage = $configurationRootGroup !== '' && $configurationRootGroup !== $configurationGroupId
        ? $configurationNavigation->tilesPage($configurationTree, $configurationRootGroup, ['site_url' => $configurationSiteUrl, 'badges' => false])
        : null;
    $pageTitle = 'Seite nicht gefunden';

    require __DIR__ . '/partials/header.php';
    require __DIR__ . '/partials/sidebar.php';
    require __DIR__ . '/views/configuration/not-found.php';
    require __DIR__ . '/partials/footer.php';

    return;
}

$pageTitle = $configurationPage['label'];

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/views/configuration/tiles.php';
require __DIR__ . '/partials/footer.php';
