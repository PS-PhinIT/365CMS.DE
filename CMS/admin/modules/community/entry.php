<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – gemeinsame Einstiegslogik der Seiten `/admin/community*` (PR #3).
 *
 * Die Einstiegsdatei jeder Seite definiert `CMS_COMMUNITY_ADMIN_SECTION` und `cms_admin_community_can_access()`
 * (Capability-Gate, vom Test `TESTS/admin-menu` mit dem Katalog abgeglichen) und bindet diese Datei ein. Ist das Modul
 * `community` aus, führt jede Seite zur Modulverwaltung.
 */

use CMS\Auth;
use CMS\Services\Community\Support\CommunityModule;

require_once __DIR__ . '/CommunityAdminModule.php';

if (!CommunityModule::isEnabled()) {
    $_SESSION['admin_alert'] = ['type' => 'warning', 'message' => 'Das Community-Modul ist ausgeschaltet. Unter „Module“ lässt es sich einschalten.'];
    header('Location: /admin/modules', true, 303);
    exit;
}

$communitySection = (string) CMS_COMMUNITY_ADMIN_SECTION;
[$communityPath, $communityTitle] = CommunityAdminModule::SECTIONS[$communitySection];

$sectionPageConfig = [
    'section' => $communitySection,
    'route_path' => $communityPath,
    'view_file' => dirname(__DIR__, 2) . '/views/community/index.php',
    'page_title' => $communityTitle,
    'active_page' => $communityPath === '/admin/community' ? 'community' : substr($communityPath, strlen('/admin/')),
    'csrf_action' => 'admin_community',
    'module_factory' => static fn (): CommunityAdminModule => new CommunityAdminModule($communitySection),
    'access_checker' => static fn (): bool => cms_admin_community_can_access(),
    'access_denied_route' => '/admin',
    'request_context_resolver' => static function (CommunityAdminModule $module) use ($communitySection, $communityTitle): array {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            return ['section' => $communitySection, 'data' => []];
        }

        return [
            'section' => $communitySection,
            'page_title' => $communityTitle,
            'data' => $module->getData((int) (Auth::instance()->currentUser()->id ?? 0), $_GET),
        ];
    },
    'redirect_path_resolver' => static function ($module, string $section, mixed $result = null) use ($communityPath): string {
        // Zurück zur selben Ansicht (Filter und Seite bleiben): nur Pfad und Abfrage der eigenen Seite.
        $query = is_string($_POST['return'] ?? null) ? (string) $_POST['return'] : '';
        parse_str($query, $params);
        $params = array_intersect_key($params, array_flip(['cursor', 'type', 'forum_id', 'status', 'action', 'actor_id']));

        return $communityPath . ($params !== [] ? '?' . http_build_query($params) : '');
    },
    'post_handler' => static function (CommunityAdminModule $module, string $section, array $post): array {
        $uid = (int) (Auth::instance()->currentUser()->id ?? 0);
        // Capability je Aktion: Einstellungen, Ränge nur mit community.manage; alles andere mit community.moderate.
        $manage = in_array((string) ($post['action'] ?? ''), ['save_settings', 'save_rank', 'delete_rank'], true);
        $allowed = Auth::instance()->hasCapability($manage ? 'community.manage' : 'community.moderate');
        if (!$allowed) {
            return ['success' => false, 'error' => 'Dafür fehlt die Berechtigung.'];
        }

        return $module->handlePost($post, $uid);
    },
    'invalid_token_message' => 'Sicherheitstoken ungültig. Bitte die Seite neu laden.',
    'unknown_action_message' => 'Unbekannte Aktion.',
];

require dirname(__DIR__, 1) . '/../partials/section-page-shell.php';
