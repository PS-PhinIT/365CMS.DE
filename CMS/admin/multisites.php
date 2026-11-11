<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Einstellungen › MultiSites – Entry Point
 * Route: /admin/multisites
 *
 * Verwaltet die Sites der Admin-Oberfläche (Name, URL, Akzentfarbe, weitere Hostnamen) in der
 * Option `admin_sites`. Ohne gespeicherte Sites gilt die Haupt-URL der Installation (SITE_URL) als
 * einzige Site. Die erste Site ist die Standard-Site.
 */

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Security;
use CMS\Services\AdminMenu\AdminMenuService;
use CMS\Services\AdminSiteContextService;
use CMS\Services\PluginSiteAssignmentService;

const CMS_ADMIN_MULTISITES_CAPABILITY = 'manage_settings';
const CMS_ADMIN_MULTISITES_CSRF_ACTION = 'admin_multisites';

if (!Auth::instance()->isAdmin() || !Auth::instance()->hasCapability(CMS_ADMIN_MULTISITES_CAPABILITY)) {
    header('Location: /');
    exit;
}

$siteContext = AdminSiteContextService::getInstance();
$multisitesPath = AdminSiteContextService::ADMIN_PATH;

$alert = null;
$formErrors = [];
$formValues = null;
$formKey = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::instance()->verifyToken((string) ($_POST['csrf_token'] ?? ''), CMS_ADMIN_MULTISITES_CSRF_ACTION)) {
        $_SESSION['admin_alert'] = [
            'type' => 'danger',
            'message' => 'Sicherheitstoken ungültig. Bitte die Seite neu laden und erneut speichern.',
        ];
        header('Location: ' . $multisitesPath);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $postedKey = strtolower(trim((string) ($_POST['site_key'] ?? '')));
    $before = $siteContext->getSite($postedKey);
    $result = null;

    switch ($action) {
        case 'save':
            $formValues = [
                'label' => (string) ($_POST['label'] ?? ''),
                'url' => (string) ($_POST['url'] ?? ''),
                'accent' => (string) ($_POST['accent'] ?? ''),
                'hosts' => (string) ($_POST['hosts'] ?? ''),
            ];
            $result = $siteContext->saveSite($formValues, $postedKey !== '' ? $postedKey : null);
            if ($result['success']) {
                $saved = $siteContext->getSite($result['key']);
                AuditLogger::instance()->log(
                    AuditLogger::CAT_SETTING,
                    $postedKey === '' ? 'multisite.create' : 'multisite.update',
                    $result['message'],
                    'site',
                    null,
                    ['site' => $result['key'], 'before' => $before, 'after' => $saved]
                );
            } else {
                $formErrors = $result['errors'];
                $formKey = $postedKey;
            }
            break;

        case 'delete':
            $result = $siteContext->deleteSite($postedKey);
            if ($result['success']) {
                PluginSiteAssignmentService::getInstance()->pruneUnknownSites();
                AdminMenuService::getInstance()->pruneUnknownSites(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
                AuditLogger::instance()->log(
                    AuditLogger::CAT_SETTING,
                    'multisite.delete',
                    $result['message'],
                    'site',
                    null,
                    ['site' => $postedKey, 'before' => $before],
                    'warning'
                );
            }
            break;

        case 'move':
            $direction = (string) ($_POST['direction'] ?? '');
            $result = $siteContext->moveSite($postedKey, $direction);
            if ($result['success']) {
                AuditLogger::instance()->log(
                    AuditLogger::CAT_SETTING,
                    'multisite.reorder',
                    $result['message'],
                    'site',
                    null,
                    ['site' => $postedKey, 'direction' => $direction, 'order' => array_keys($siteContext->getSites())]
                );
            }
            break;

        default:
            $result = ['success' => false, 'message' => 'Unbekannte Aktion.', 'errors' => [], 'key' => ''];
    }

    if ($result['success'] || $formErrors === []) {
        $_SESSION['admin_alert'] = [
            'type' => $result['success'] ? 'success' : 'danger',
            'message' => $result['message'],
        ];
        header('Location: ' . $multisitesPath);
        exit;
    }

    // Validierungsfehler: Formular mit den eingegebenen Werten erneut anzeigen.
    $alert = ['type' => 'danger', 'message' => $result['message']];
}

if ($alert === null) {
    $alert = $_SESSION['admin_alert'] ?? null;
    unset($_SESSION['admin_alert']);
    $alert = is_array($alert) ? $alert : null;
}

$multisitesSites = $siteContext->getSites();

if ($formValues === null) {
    $editKey = strtolower(trim((string) ($_GET['edit'] ?? '')));
    $editSite = $editKey !== '' ? ($multisitesSites[$editKey] ?? null) : null;
    if ($editSite !== null) {
        $formKey = $editKey;
        $formValues = [
            'label' => $editSite['label'],
            'url' => $editSite['url'],
            'accent' => $editSite['accent'],
            'hosts' => implode("\n", $siteContext->getExtraHosts($editSite)),
        ];
    } else {
        $formValues = ['label' => '', 'url' => '', 'accent' => AdminSiteContextService::DEFAULT_ACCENT, 'hosts' => ''];
    }
}

$multisitesConfigured = $siteContext->hasConfiguredSites();
$multisitesCurrentKey = $siteContext->getCurrentSiteKey();
$multisitesCsrfToken = Security::instance()->generateToken(CMS_ADMIN_MULTISITES_CSRF_ACTION);

$pageTitle = 'MultiSites';
$activePage = 'multisites';
$pageAssets = [
    'css' => [cms_asset_url('css/admin-multisites.css')],
];

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/views/settings/multisites.php';
require __DIR__ . '/partials/footer.php';
