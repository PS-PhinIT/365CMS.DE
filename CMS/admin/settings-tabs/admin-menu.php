<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Einstellungen › Admin-Menü – Erweiterungs-Tab
 *
 * Routen (ohne Router-Änderung, KLARSTELLUNGEN §7):
 *   /admin/settings?tab=admin-menu
 *       Profilwahl mit Live-Vorschau, Layouts je Site, Import und Export
 *   /admin/settings?tab=admin-menu&view=editor[&scope=<site-key>][&start=classic|structured|empty]
 *       Admin-Sidebar-Editor für ein eigenes Layout (global oder je Site)
 *   /admin/settings?tab=admin-menu&action=export[&scope=<site-key>]
 *       JSON-Export als Download
 *
 * admin/settings.php bindet diese Datei vor seinem manage_settings-Gate ein; das isAdmin-Gate und
 * manage_admin_menu prüft der Tab selbst. Änderungen nur per POST mit CSRF-Token. Profilwechsel,
 * Speichern, Import und Zurücksetzen protokolliert AdminMenuService im Audit-Log. Die Live-Vorschau
 * (POST action=preview) antwortet mit JSON und nutzt ein eigenes, wiederverwendbares Token.
 */

use CMS\Auth;
use CMS\Security;
use CMS\Services\AdminMenu\AdminMenuService;
use CMS\Services\AdminMenu\CoreCatalog;
use CMS\Services\AdminMenu\LayoutRepository;
use CMS\Services\AdminMenu\LayoutValidator;
use CMS\Services\AdminMenu\MenuBuilder;
use CMS\Services\AdminMenu\MenuRegistry;
use CMS\Services\AdminMenu\ProfileLayouts;
use CMS\Services\AdminNavigationService;
use CMS\Services\UserService;
use CMS\Version;

const CMS_ADMIN_MENU_SETTINGS_CAPABILITY = 'manage_admin_menu';
const CMS_ADMIN_MENU_SETTINGS_CSRF_ACTION = 'admin_menu_settings';
const CMS_ADMIN_MENU_SETTINGS_PREVIEW_CSRF_ACTION = 'admin_menu_preview';

function cms_admin_menu_settings_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_MENU_SETTINGS_CAPABILITY);
}

/**
 * @param array<mixed> $source $_GET oder $_POST
 */
function cms_admin_menu_settings_input(array $source, string $key): string
{
    $value = $source[$key] ?? '';

    return is_string($value) ? $value : '';
}

/**
 * @param array<string, string> $query
 */
function cms_admin_menu_settings_url(array $query = []): string
{
    $query = array_filter($query, static fn (mixed $value): bool => is_string($value) && $value !== '');

    return '/admin/settings?' . http_build_query(['tab' => 'admin-menu'] + $query, '', '&', PHP_QUERY_RFC3986);
}

/**
 * @param array<string, string>     $query
 * @param array<string, mixed>|null $alert
 */
function cms_admin_menu_settings_redirect(array $query = [], ?array $alert = null): never
{
    if ($alert !== null) {
        $_SESSION['admin_alert'] = $alert;
    }
    header('Location: ' . cms_admin_menu_settings_url($query));
    exit;
}

/**
 * Hinweis für flash-alert.php aus einem Ergebnis von AdminMenuService; Fehler und Warnungen als Liste.
 *
 * @param array{success:bool, message:string, errors?:list<string>, warnings?:list<string>} $result
 * @return array{type:string, message:string, details:list<string>}
 */
function cms_admin_menu_settings_alert(array $result): array
{
    $warnings = array_values($result['warnings'] ?? []);
    $details = array_merge(array_values($result['errors'] ?? []), $warnings);

    return [
        'type' => !$result['success'] ? 'danger' : ($warnings !== [] ? 'warning' : 'success'),
        'message' => $result['message'],
        'details' => array_slice(array_map('strval', $details), 0, 25),
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function cms_admin_menu_settings_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Seitenleiste als HTML für eine Vorschau: ohne Zähler, alle Gruppen aufgeklappt, eigene DOM-IDs.
 *
 * @param array<string, mixed> $tree Ausgabe von MenuBuilder::build()
 */
function cms_admin_menu_settings_preview_html(array $tree, MenuRegistry $registry, string $domPrefix, string $siteUrl): string
{
    $navModel = AdminNavigationService::getInstance()->present($tree, $registry, '', [
        'dom_prefix' => $domPrefix,
        'expand_all' => true,
        'badges' => false,
        'site_url' => $siteUrl,
    ]);
    $navPreview = true;

    ob_start();
    require dirname(__DIR__) . '/partials/sidebar-nav.php';

    return (string) ob_get_clean();
}

/**
 * Anzahl der Hauptpunkte (Einträge und Gruppen der ersten Ebene) eines Menübaums.
 *
 * @param array<string, mixed> $tree
 */
function cms_admin_menu_settings_top_level_count(array $tree): int
{
    $count = 0;
    foreach ((array) ($tree['sections'] ?? []) as $section) {
        $count += count((array) ($section['nodes'] ?? []));
    }

    return $count;
}

/**
 * Inhalt einer hochgeladenen JSON-Datei; null mit Fehlermeldung, wenn nichts Brauchbares hochgeladen wurde.
 */
function cms_admin_menu_settings_uploaded_json(string $field, ?string &$error): ?string
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || !isset($file['error']) || is_array($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Bitte eine JSON-Datei auswählen.';

        return null;
    }

    $code = (int) $file['error'];
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > LayoutValidator::MAX_JSON_BYTES) {
        $error = sprintf('Die Datei ist größer als %d KB.', (int) (LayoutValidator::MAX_JSON_BYTES / 1024));

        return null;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($code !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
        $error = 'Die Datei konnte nicht hochgeladen werden.';

        return null;
    }

    $content = file_get_contents($tmpName, false, null, 0, LayoutValidator::MAX_JSON_BYTES + 1);
    if (!is_string($content)) {
        $error = 'Die Datei konnte nicht gelesen werden.';

        return null;
    }

    return $content;
}

/**
 * Anzeigename eines Benutzers für „zuletzt geändert von“.
 */
function cms_admin_menu_settings_user_name(?int $userId): string
{
    if ($userId === null || $userId <= 0) {
        return '';
    }

    try {
        $user = UserService::getInstance()->getUserById($userId);
    } catch (\Throwable) {
        $user = null;
    }
    if (!is_object($user)) {
        return 'Benutzer #' . $userId;
    }

    $name = trim((string) ($user->display_name ?? ''));

    return $name !== '' ? $name : (string) ($user->username ?? ('Benutzer #' . $userId));
}

$adminMenuIsPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$adminMenuAction = cms_admin_menu_settings_input($adminMenuIsPost ? $_POST : $_GET, 'action');

if (!cms_admin_menu_settings_can_access()) {
    if ($adminMenuIsPost && $adminMenuAction === 'preview') {
        cms_admin_menu_settings_json(['success' => false, 'message' => 'Keine Berechtigung.'], 403);
    }
    header('Location: /');
    exit;
}

$adminMenu = AdminMenuService::getInstance();
$adminMenuUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$adminMenuSiteUrl = rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/');
$adminMenuView = cms_admin_menu_settings_input($_GET, 'view') === 'editor' ? 'editor' : 'overview';
$adminMenuScope = strtolower(trim(cms_admin_menu_settings_input($adminMenuIsPost ? $_POST : $_GET, 'scope')));
$adminMenuScopeValid = $adminMenu->isValidScopeKey($adminMenuScope);
$adminMenuUnknownScope = [
    'type' => 'danger',
    'message' => 'Unbekannter Bereich: Bitte „Global“ oder eine unter „MultiSites“ gespeicherte Site wählen.',
];

// ── Live-Vorschau (JSON) ───────────────────────────────────────────────────
if ($adminMenuIsPost && $adminMenuAction === 'preview') {
    if (!Security::instance()->verifyPersistentToken(cms_admin_menu_settings_input($_POST, 'csrf_token'), CMS_ADMIN_MENU_SETTINGS_PREVIEW_CSRF_ACTION)) {
        cms_admin_menu_settings_json(['success' => false, 'message' => 'Sicherheitstoken ungültig. Bitte die Seite neu laden.'], 403);
    }
    if (!$adminMenuScopeValid) {
        cms_admin_menu_settings_json(['success' => false, 'message' => $adminMenuUnknownScope['message']], 422);
    }

    $adminMenuValidation = LayoutValidator::validateJson(
        cms_admin_menu_settings_input($_POST, 'layout'),
        AdminMenuService::catalogIds(),
        CoreCatalog::lockedIds()
    );
    if (!$adminMenuValidation['valid']) {
        cms_admin_menu_settings_json([
            'success' => false,
            'message' => 'Das Layout ist ungültig.',
            'errors' => $adminMenuValidation['errors'],
            'warnings' => $adminMenuValidation['warnings'],
        ], 422);
    }

    $adminMenuRegistry = $adminMenu->registry($adminMenu->registeredPluginMenus(), $adminMenuScope, $adminMenuSiteUrl);
    $adminMenuTree = $adminMenu->preview($adminMenuRegistry, ProfileLayouts::CUSTOM, $adminMenuValidation['layout']);

    cms_admin_menu_settings_json([
        'success' => true,
        'html' => cms_admin_menu_settings_preview_html($adminMenuTree, $adminMenuRegistry, 'cms-nav-pv-editor-', $adminMenuSiteUrl),
        'layout' => $adminMenuValidation['layout'],
        'warnings' => $adminMenuValidation['warnings'],
        'top_level' => cms_admin_menu_settings_top_level_count($adminMenuTree),
    ]);
}

// ── Änderungen (POST, danach Weiterleitung) ────────────────────────────────
$adminMenuAlert = null;
$adminMenuDraftJson = null;

if ($adminMenuIsPost) {
    $adminMenuReturnEditor = cms_admin_menu_settings_input($_POST, 'return') === 'editor';
    $adminMenuReturnQuery = static fn (string $scope): array => $adminMenuReturnEditor ? ['view' => 'editor', 'scope' => $scope] : [];

    if (!Security::instance()->verifyToken(cms_admin_menu_settings_input($_POST, 'csrf_token'), CMS_ADMIN_MENU_SETTINGS_CSRF_ACTION)) {
        cms_admin_menu_settings_redirect($adminMenuScopeValid ? $adminMenuReturnQuery($adminMenuScope) : [], [
            'type' => 'danger',
            'message' => 'Sicherheitstoken ungültig. Bitte die Seite neu laden und erneut speichern.',
        ]);
    }
    if (!$adminMenuScopeValid) {
        cms_admin_menu_settings_redirect([], $adminMenuUnknownScope);
    }

    switch ($adminMenuAction) {
        case 'profile':
            $adminMenuResult = $adminMenu->setProfile(cms_admin_menu_settings_input($_POST, 'profile'), $adminMenuUserId);
            cms_admin_menu_settings_redirect([], cms_admin_menu_settings_alert($adminMenuResult));

            // no break – redirect() beendet den Request
        case 'save':
            $adminMenuRaw = cms_admin_menu_settings_input($_POST, 'layout');
            if (strlen($adminMenuRaw) > LayoutValidator::MAX_JSON_BYTES) {
                $adminMenuResult = [
                    'success' => false,
                    'message' => 'Das Layout wurde nicht gespeichert.',
                    'errors' => [sprintf('Das Layout ist größer als %d KB.', (int) (LayoutValidator::MAX_JSON_BYTES / 1024))],
                    'warnings' => [],
                ];
            } elseif (cms_admin_menu_settings_input($_POST, 'origin') === 'import') {
                $adminMenuResult = $adminMenu->importJson($adminMenuScope, $adminMenuRaw, $adminMenuUserId);
            } else {
                try {
                    $adminMenuDecoded = json_decode($adminMenuRaw, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $adminMenuDecoded = null;
                }
                $adminMenuResult = $adminMenu->saveLayout($adminMenuScope, $adminMenuDecoded, $adminMenuUserId);
            }

            if ($adminMenuResult['success']) {
                cms_admin_menu_settings_redirect(['view' => 'editor', 'scope' => $adminMenuScope], cms_admin_menu_settings_alert($adminMenuResult));
            }

            // Nicht gespeichert: Editor mit dem übermittelten Stand erneut anzeigen, nichts geht verloren.
            $adminMenuAlert = cms_admin_menu_settings_alert($adminMenuResult);
            $adminMenuDraftJson = strlen($adminMenuRaw) <= LayoutValidator::MAX_JSON_BYTES ? $adminMenuRaw : null;
            $adminMenuView = 'editor';
            break;

        case 'import':
            $adminMenuUploadError = null;
            $adminMenuJson = cms_admin_menu_settings_uploaded_json('layout_file', $adminMenuUploadError);
            if ($adminMenuJson === null) {
                cms_admin_menu_settings_redirect($adminMenuReturnQuery($adminMenuScope), ['type' => 'danger', 'message' => (string) $adminMenuUploadError]);
            }
            $adminMenuResult = $adminMenu->importJson($adminMenuScope, $adminMenuJson, $adminMenuUserId);
            cms_admin_menu_settings_redirect($adminMenuReturnQuery($adminMenuScope), cms_admin_menu_settings_alert($adminMenuResult));

            // no break – redirect() beendet den Request
        case 'reset':
            $adminMenuResult = $adminMenu->reset($adminMenuScope, $adminMenuUserId);
            cms_admin_menu_settings_redirect($adminMenuReturnQuery($adminMenuScope), cms_admin_menu_settings_alert($adminMenuResult));

            // no break – redirect() beendet den Request
        default:
            cms_admin_menu_settings_redirect([], ['type' => 'danger', 'message' => 'Unbekannte Aktion.']);
    }
}

// ── Export (GET, Download) ─────────────────────────────────────────────────
if (!$adminMenuIsPost && $adminMenuAction === 'export') {
    if (!$adminMenuScopeValid) {
        cms_admin_menu_settings_redirect([], $adminMenuUnknownScope);
    }

    $adminMenuExport = $adminMenu->exportJson($adminMenuScope);
    $adminMenuFileName = '365cms-admin-menu-' . ($adminMenuScope === LayoutRepository::GLOBAL_SCOPE ? 'global' : $adminMenuScope) . '-' . date('Y-m-d') . '.json';

    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $adminMenuFileName . '"');
    header('Content-Length: ' . strlen($adminMenuExport));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $adminMenuExport;
    exit;
}

if ($adminMenuView === 'editor' && !$adminMenuScopeValid) {
    cms_admin_menu_settings_redirect([], $adminMenuUnknownScope);
}

if ($adminMenuAlert === null) {
    $adminMenuAlert = $_SESSION['admin_alert'] ?? null;
    unset($_SESSION['admin_alert']);
    $adminMenuAlert = is_array($adminMenuAlert) ? $adminMenuAlert : null;
}

$adminMenuCsrfToken = Security::instance()->generateToken(CMS_ADMIN_MENU_SETTINGS_CSRF_ACTION);
$adminMenuProfileSetting = $adminMenu->getProfileSetting();
$adminMenuScopes = $adminMenu->scopeOverview();
foreach ($adminMenuScopes as $adminMenuScopeIndex => $adminMenuScopeRow) {
    $adminMenuScopes[$adminMenuScopeIndex]['updated_by_name'] = cms_admin_menu_settings_user_name($adminMenuScopeRow['updated_by']);
}

$activePage = 'settings-admin-menu';
$pageAssets = [
    'css' => [cms_asset_url('css/admin-sidebar-editor.css')],
    'js' => [cms_asset_url('js/admin-sidebar-editor.js')],
];

// ── Menü-Editor ────────────────────────────────────────────────────────────
if ($adminMenuView === 'editor') {
    $adminMenuRegistry = $adminMenu->registry($adminMenu->registeredPluginMenus(), $adminMenuScope, $adminMenuSiteUrl);
    $adminMenuBuilder = $adminMenu->builder();
    $adminMenuCatalogIds = AdminMenuService::catalogIds();
    $adminMenuLockedIds = CoreCatalog::lockedIds();
    $adminMenuValidated = static fn (array $layout): array => LayoutValidator::validate($layout, $adminMenuCatalogIds, $adminMenuLockedIds)['layout'];

    $adminMenuStored = $adminMenu->storedLayout($adminMenuScope);
    $adminMenuActive = $adminMenu->resolveActive($adminMenuScope);
    $adminMenuEffective = $adminMenuValidated($adminMenu->layoutFor($adminMenuActive));
    $adminMenuBase = (string) ($adminMenuEffective['base'] ?? ProfileLayouts::CLASSIC);

    $adminMenuStarts = [
        ProfileLayouts::CLASSIC => $adminMenuValidated(ProfileLayouts::get(ProfileLayouts::CLASSIC)),
        ProfileLayouts::STRUCTURED => $adminMenuValidated(ProfileLayouts::get(ProfileLayouts::STRUCTURED)),
        'empty' => $adminMenuValidated(LayoutValidator::emptyLayout($adminMenuBase)),
    ];
    $adminMenuStart = cms_admin_menu_settings_input($_GET, 'start');
    if (!isset($adminMenuStarts[$adminMenuStart])) {
        $adminMenuStart = '';
    }

    $adminMenuPluginMenuLabels = [];
    foreach ($adminMenuRegistry->pluginMenus() as $adminMenuPluginMenu) {
        foreach ($adminMenuPluginMenu['items'] as $adminMenuPluginItemId) {
            $adminMenuPluginMenuLabels[$adminMenuPluginItemId] = (string) $adminMenuPluginMenu['label'];
        }
    }

    $adminMenuItems = [];
    $adminMenuIconSet = [];
    foreach ($adminMenuRegistry->items() as $adminMenuItem) {
        $adminMenuIsPlugin = $adminMenuItem->isPlugin();
        $adminMenuArea = $adminMenuIsPlugin ? 'plugin:' . MenuRegistry::pluginMenuKeyOf($adminMenuItem->id) : CoreCatalog::areaOf($adminMenuItem->id);
        $adminMenuItems[] = [
            'id' => $adminMenuItem->id,
            'label' => $adminMenuItem->label,
            'icon' => $adminMenuItem->icon,
            'route' => $adminMenuItem->route,
            'locked' => $adminMenuItem->locked,
            'plugin' => $adminMenuIsPlugin,
            'external' => $adminMenuItem->isExternal(),
            'area' => $adminMenuArea,
            'area_label' => $adminMenuIsPlugin
                ? ($adminMenuPluginMenuLabels[$adminMenuItem->id] ?? 'Plugin')
                : (CoreCatalog::AREA_LABELS[$adminMenuArea] ?? CoreCatalog::AREA_LABELS['other']),
            'reason' => (string) ($adminMenuBuilder->hiddenReason($adminMenuItem) ?? ''),
        ];
        if (($adminMenuItem->icon['type'] ?? '') === 'tabler' && isset($adminMenuItem->icon['name'])) {
            $adminMenuIconSet[(string) $adminMenuItem->icon['name']] = true;
        }
    }
    foreach ($adminMenuStarts as $adminMenuStartLayout) {
        foreach ($adminMenuStartLayout['nodes'] as $adminMenuStartNode) {
            if (($adminMenuStartNode['type'] ?? '') === 'group') {
                $adminMenuIconSet[(string) $adminMenuStartNode['icon']] = true;
            }
        }
    }
    $adminMenuIconSet = array_keys($adminMenuIconSet);
    sort($adminMenuIconSet);

    $adminMenuScopeRow = null;
    foreach ($adminMenuScopes as $adminMenuScopeCandidate) {
        if ($adminMenuScopeCandidate['key'] === $adminMenuScope) {
            $adminMenuScopeRow = $adminMenuScopeCandidate;
            break;
        }
    }

    $adminMenuEditorData = [
        'scope' => $adminMenuScope,
        'scopeLabel' => $adminMenuScope === LayoutRepository::GLOBAL_SCOPE ? 'Global (alle Sites)' : $adminMenu->scopeLabel($adminMenuScope),
        'endpoint' => cms_admin_menu_settings_url(['view' => 'editor', 'scope' => $adminMenuScope]),
        'previewToken' => Security::instance()->generateToken(CMS_ADMIN_MENU_SETTINGS_PREVIEW_CSRF_ACTION),
        'exportName' => '365cms-admin-menu-' . ($adminMenuScope === LayoutRepository::GLOBAL_SCOPE ? 'global' : $adminMenuScope),
        'cmsVersion' => Version::CURRENT,
        'stored' => $adminMenuStored['layout'] ?? null,
        'storedVersion' => (int) ($adminMenuStored['version'] ?? 0),
        'effective' => $adminMenuEffective,
        'effectiveProfile' => $adminMenuActive['profile'],
        'start' => $adminMenuStart,
        'starts' => $adminMenuStarts,
        'draft' => $adminMenuDraftJson,
        'items' => $adminMenuItems,
        'locked' => $adminMenuLockedIds,
        'icons' => $adminMenuIconSet,
        'reasons' => [
            MenuBuilder::HIDDEN_FILE => 'Seite noch nicht vorhanden',
            MenuBuilder::HIDDEN_MODULE => 'Modul inaktiv',
            MenuBuilder::HIDDEN_MARKETPLACE => 'Marketplace deaktiviert',
            MenuBuilder::HIDDEN_CAPABILITY => 'Keine Berechtigung',
        ],
        'limits' => [
            'nodes' => LayoutValidator::MAX_NODES,
            'label' => LayoutValidator::MAX_LABEL_LENGTH,
            'bytes' => LayoutValidator::MAX_JSON_BYTES,
        ],
        'patterns' => [
            'icon' => trim(LayoutValidator::ICON_PATTERN, '/'),
            'node' => trim(LayoutValidator::NODE_ID_PATTERN, '/'),
        ],
    ];

    $pageTitle = 'Menü-Editor';

    require dirname(__DIR__) . '/partials/header.php';
    require dirname(__DIR__) . '/partials/sidebar.php';
    require dirname(__DIR__) . '/views/settings/admin-sidebar-editor.php';
    require dirname(__DIR__) . '/partials/footer.php';

    return;
}

// ── Übersicht: Profilwahl mit Vorschau ─────────────────────────────────────
$adminMenuRegistry = $adminMenu->registry($adminMenu->registeredPluginMenus(), LayoutRepository::GLOBAL_SCOPE, $adminMenuSiteUrl);
$adminMenuGlobalStored = $adminMenu->storedLayout(LayoutRepository::GLOBAL_SCOPE);

$adminMenuProfiles = [];
foreach (ProfileLayouts::ALL_PROFILES as $adminMenuProfile) {
    $adminMenuTree = null;
    if ($adminMenuProfile !== ProfileLayouts::CUSTOM) {
        $adminMenuTree = $adminMenu->preview($adminMenuRegistry, $adminMenuProfile);
    } elseif ($adminMenuGlobalStored !== null) {
        $adminMenuTree = $adminMenu->preview($adminMenuRegistry, ProfileLayouts::CUSTOM, $adminMenuGlobalStored['layout']);
    }

    $adminMenuProfiles[$adminMenuProfile] = [
        'key' => $adminMenuProfile,
        'label' => $adminMenu->profileLabel($adminMenuProfile),
        'available' => $adminMenuTree !== null,
        'top_level' => $adminMenuTree !== null ? cms_admin_menu_settings_top_level_count($adminMenuTree) : 0,
        'preview' => $adminMenuTree !== null
            ? cms_admin_menu_settings_preview_html($adminMenuTree, $adminMenuRegistry, 'cms-nav-pv-' . $adminMenuProfile . '-', $adminMenuSiteUrl)
            : '',
    ];
}

$adminMenuLockedLabels = [];
foreach (CoreCatalog::lockedIds() as $adminMenuLockedId) {
    $adminMenuLockedItem = $adminMenuRegistry->get($adminMenuLockedId);
    if ($adminMenuLockedItem !== null) {
        $adminMenuLockedLabels[] = $adminMenuLockedItem->label;
    }
}

$adminMenuGlobalRow = $adminMenuScopes[0];
$adminMenuSiteScopesEnabled = $adminMenu->siteScopesEnabled();
$adminMenuSiteOverrides = array_values(array_filter(
    array_slice($adminMenuScopes, 1),
    static fn (array $row): bool => $row['has_layout']
));
$adminMenuMaxUploadKb = (int) (LayoutValidator::MAX_JSON_BYTES / 1024);

$pageTitle = 'Admin-Menü';

require dirname(__DIR__) . '/partials/header.php';
require dirname(__DIR__) . '/partials/sidebar.php';
require dirname(__DIR__) . '/views/settings/admin-menu.php';
require dirname(__DIR__) . '/partials/footer.php';
