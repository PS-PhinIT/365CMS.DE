<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Einstellungen › Plugins › Plugin-Zuweisung – Entry Point
 * Route: /admin/plugin-assignment
 *
 * Legt fest, in welcher Site-Administration die Menüeinträge eines Plugins in der Seitenleiste
 * erscheinen. Reiner Darstellungsfilter: Die Plugin-Menüregistrierung (cms_admin_menu,
 * add_menu_page(), add_submenu_page()) bleibt unverändert, Plugin-Seiten bleiben erreichbar.
 */

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Security;
use CMS\Services\AdminSiteContextService;
use CMS\Services\PluginSiteAssignmentService;

const CMS_ADMIN_PLUGIN_ASSIGNMENT_CAPABILITY = 'manage_settings';
const CMS_ADMIN_PLUGIN_ASSIGNMENT_CSRF_ACTION = 'admin_plugin_assignment';

if (!Auth::instance()->isAdmin() || !Auth::instance()->hasCapability(CMS_ADMIN_PLUGIN_ASSIGNMENT_CAPABILITY)) {
    header('Location: /');
    exit;
}

$assignmentService = PluginSiteAssignmentService::getInstance();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::instance()->verifyToken((string) ($_POST['csrf_token'] ?? ''), CMS_ADMIN_PLUGIN_ASSIGNMENT_CSRF_ACTION)) {
        $_SESSION['admin_alert'] = [
            'type' => 'danger',
            'message' => 'Sicherheitstoken ungültig. Bitte die Seite neu laden und erneut speichern.',
        ];
        header('Location: ' . PluginSiteAssignmentService::ADMIN_PATH);
        exit;
    }

    $postedPlugins = array_values(array_filter(
        is_array($_POST['plugins'] ?? null) ? $_POST['plugins'] : [],
        static fn (mixed $slug): bool => is_string($slug) && $slug !== ''
    ));
    $postedMatrix = is_array($_POST['assign'] ?? null) ? $_POST['assign'] : [];

    $before = $assignmentService->getStoredAssignments();
    if ($assignmentService->saveAssignments($postedMatrix, $postedPlugins)) {
        $after = $assignmentService->getStoredAssignments();
        $changed = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $slug) {
            if (($before[$slug] ?? null) !== ($after[$slug] ?? null)) {
                $changed[] = ['plugin' => (string) $slug, 'sites' => $after[$slug] ?? 'alle'];
            }
        }

        if ($changed !== []) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'plugin.assignment.update',
                'Plugin-Zuweisung je Site geändert (' . count($changed) . ' Plugin(s))',
                'plugin',
                null,
                ['changed' => $changed]
            );
        }

        $_SESSION['admin_alert'] = [
            'type' => 'success',
            'message' => $changed === [] ? 'Plugin-Zuweisung gespeichert – keine Änderungen.' : 'Plugin-Zuweisung gespeichert.',
            'details' => $changed === [] ? [] : ['Die Seitenleiste zeigt die Menüs der geänderten Plugins jetzt nur noch in den zugewiesenen Sites.'],
        ];
    } else {
        $_SESSION['admin_alert'] = [
            'type' => 'danger',
            'message' => 'Die Plugin-Zuweisung konnte nicht gespeichert werden.',
        ];
    }

    header('Location: ' . PluginSiteAssignmentService::ADMIN_PATH);
    exit;
}

$alert = $_SESSION['admin_alert'] ?? null;
unset($_SESSION['admin_alert']);
$alert = is_array($alert) ? $alert : null;

$assignmentSites = AdminSiteContextService::getInstance()->getSites();
$assignmentPlugins = $assignmentService->getInstalledPlugins();
$assignmentCsrfToken = Security::instance()->generateToken(CMS_ADMIN_PLUGIN_ASSIGNMENT_CSRF_ACTION);

$pageTitle = 'Plugin-Zuweisung';
$activePage = 'plugin-assignment';
$pageAssets = [
    'css' => [cms_asset_url('css/admin-plugin-assignment.css')],
    'modules' => [cms_asset_url('js/admin-plugin-assignment.js')],
];

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/views/settings/plugin-assignment.php';
require __DIR__ . '/partials/footer.php';
