<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-Oberfläche (JSON) – Route: /admin/admin-ui
 *
 * GET  ?action=notices        Systemhinweise für den Sidebar-Footer (Capability manage_system, 5 Minuten Cache)
 * GET  ?action=token          frisches CSRF-Token (nach Ablauf)
 * POST action=prefs           Sidebar-Zustand speichern: sidebar_collapsed (0/1) und/oder groups (JSON {id: bool})
 * POST action=switch_site     Site-Kontext wechseln: site (Schlüssel aus AdminSiteContextService)
 * POST action=cache_clear     Cache leeren: scope = all|pages|assets|opcache (Capability manage_system)
 * POST action=maintenance     Wartungsmodus schalten: active = 0|1 (Capability manage_system)
 */

use CMS\Auth;
use CMS\Json;
use CMS\Logger;
use CMS\Security;
use CMS\Services\AdminNoticeService;
use CMS\Services\AdminSiteContextService;
use CMS\Services\AdminUiPreferenceService;

\CMS\CacheManager::instance()->sendResponseHeaders('private');
header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$cmsAdminUiRespond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$auth = Auth::instance();
$security = Security::instance();
$csrfAction = 'admin_ui';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''))));

if (!$auth->isLoggedIn() || !$auth->isAdmin() || !$auth->hasCapability('read')) {
    $cmsAdminUiRespond(403, ['success' => false, 'error' => 'Keine Berechtigung.']);
}

$userId = (int) ($auth->getCurrentUser()->id ?? 0);

switch ($action) {
    case 'notices':
        if ($method !== 'GET') {
            $cmsAdminUiRespond(405, ['success' => false, 'error' => 'Diese Aktion erlaubt nur GET.']);
        }
        if (!$auth->hasCapability('manage_system')) {
            $cmsAdminUiRespond(403, ['success' => false, 'error' => 'Systemhinweise sind nur für Administratoren sichtbar.']);
        }

        try {
            $result = AdminNoticeService::getInstance()->getNotices();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.ui')->warning('Systemhinweise konnten nicht ermittelt werden.', ['exception' => $e->getMessage()]);
            $cmsAdminUiRespond(500, ['success' => false, 'error' => 'Systemhinweise konnten nicht geladen werden.']);
        }

        $siteBase = rtrim((string) SITE_URL, '/');
        foreach ($result['notices'] as $noticeIndex => $notice) {
            $noticeUrl = (string) ($notice['url'] ?? '');
            $result['notices'][$noticeIndex]['url'] = str_starts_with($noticeUrl, '/') ? $siteBase . $noticeUrl : $noticeUrl;
        }

        header('Cache-Control: private, max-age=' . AdminNoticeService::CACHE_TTL);
        $cmsAdminUiRespond(200, ['success' => true] + $result);

    case 'token':
        if ($method !== 'GET') {
            $cmsAdminUiRespond(405, ['success' => false, 'error' => 'Diese Aktion erlaubt nur GET.']);
        }
        $fetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
            $cmsAdminUiRespond(403, ['success' => false, 'error' => 'Token nur für Anfragen derselben Herkunft.']);
        }

        $cmsAdminUiRespond(200, ['success' => true, 'token' => $security->generateToken($csrfAction)]);

    case 'prefs':
    case 'switch_site':
    case 'cache_clear':
    case 'maintenance':
        if ($method !== 'POST') {
            $cmsAdminUiRespond(405, ['success' => false, 'error' => 'Diese Aktion erlaubt nur POST.']);
        }
        if (!$security->verifyPersistentToken((string) ($_POST['csrf_token'] ?? ''), $csrfAction)) {
            $cmsAdminUiRespond(403, ['success' => false, 'code' => 'csrf', 'error' => 'Sicherheitstoken ist abgelaufen. Bitte erneut versuchen.']);
        }
        break;

    default:
        $cmsAdminUiRespond(400, ['success' => false, 'error' => 'Unbekannte Aktion.']);
}

if ($action === 'cache_clear' || $action === 'maintenance') {
    if (!$auth->hasCapability(\CMS\Services\AdminToolbarService::CAPABILITY)) {
        $cmsAdminUiRespond(403, ['success' => false, 'error' => 'Keine Berechtigung.']);
    }
    $toolbar = \CMS\Services\AdminToolbarService::getInstance();
    if ($action === 'cache_clear') {
        $scope = strtolower(trim((string) ($_POST['scope'] ?? '')));
        if (!in_array($scope, \CMS\Services\AdminToolbarService::CACHE_SCOPES, true)) {
            $cmsAdminUiRespond(422, ['success' => false, 'error' => (string) __('toolbar.cache.error_scope')]);
        }
        $result = $toolbar->clearCache($scope, $userId);
        $cmsAdminUiRespond($result['success'] ? 200 : 500, $result['success']
            ? ['success' => true, 'message' => $result['message'], 'details' => $result['details']]
            : ['success' => false, 'error' => $result['message'], 'details' => $result['details']]);
    }

    $result = $toolbar->setMaintenance((string) ($_POST['active'] ?? '') === '1', $userId);
    $cmsAdminUiRespond($result['success'] ? 200 : 500, $result['success']
        ? ['success' => true, 'message' => $result['message'], 'active' => $result['active']]
        : ['success' => false, 'error' => $result['message'], 'active' => $result['active']]);
}

if ($action === 'switch_site') {
    $siteContext = AdminSiteContextService::getInstance();
    $siteKey = strtolower(trim((string) ($_POST['site'] ?? '')));
    if (!$siteContext->isValidSiteKey($siteKey)) {
        $cmsAdminUiRespond(422, ['success' => false, 'error' => 'Unbekannte Site.']);
    }
    if (!$siteContext->switchSite($userId, $siteKey)) {
        $cmsAdminUiRespond(500, ['success' => false, 'error' => 'Die Site konnte nicht gewechselt werden.']);
    }

    $site = $siteContext->getSite($siteKey) ?? [];
    $cmsAdminUiRespond(200, [
        'success' => true,
        'site' => $siteKey,
        'message' => 'Admin arbeitet jetzt im Kontext von ' . (string) ($site['label'] ?? $siteKey) . '.',
    ]);
}

$changes = [];
if (array_key_exists('sidebar_collapsed', $_POST)) {
    $changes['sidebar_collapsed'] = (string) $_POST['sidebar_collapsed'] === '1';
}
if (isset($_POST['groups']) && is_string($_POST['groups'])) {
    $groups = Json::decodeArray($_POST['groups'], []);
    if (count($groups) > 50) {
        $cmsAdminUiRespond(422, ['success' => false, 'error' => 'Zu viele Gruppen in einer Anfrage.']);
    }
    $changes['groups'] = $groups;
}
if ($changes === []) {
    $cmsAdminUiRespond(422, ['success' => false, 'error' => 'Keine Änderungen übermittelt.']);
}

$prefs = AdminUiPreferenceService::getInstance()->update($userId, $changes);
if ($prefs === null) {
    $cmsAdminUiRespond(500, ['success' => false, 'error' => 'Einstellung konnte nicht gespeichert werden.']);
}

$cmsAdminUiRespond(200, [
    'success' => true,
    'prefs' => [
        'sidebar_collapsed' => (bool) ($prefs['sidebar_collapsed'] ?? false),
        'groups' => $prefs['groups'] ?? [],
    ],
]);
