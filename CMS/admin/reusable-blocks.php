<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EditorJS Block Vorlagen – Entry Point (4.1.0, Bereich 3b)
 * Route: /admin/reusable-blocks
 *
 * Ansichten: Liste (?q=…) und Bearbeiten (?action=edit[&id=…], ohne ID neue Vorlage).
 * POST-Aktionen (Feld `action`): save, duplicate, delete (confirmed=1 bei verwendeten Vorlagen), restore_revision.
 * Capability `blocks.reusable`.
 */

use CMS\Auth;

const CMS_ADMIN_REUSABLE_BLOCKS_CAPABILITY = 'blocks.reusable';

function cms_admin_reusable_blocks_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_REUSABLE_BLOCKS_CAPABILITY);
}

if (!cms_admin_reusable_blocks_can_access()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/reusable-blocks/ReusableBlocksModule.php';

/**
 * @return array<string, mixed>
 */
function cms_admin_reusable_blocks_request_context(ReusableBlocksModule $module): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        return ['section' => 'list', 'data' => []];
    }
    if (($_GET['action'] ?? '') === 'edit') {
        $data = $module->getEditData(max(0, (int) ($_GET['id'] ?? 0)));
        if ($data === null) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => 'Diese Vorlage gibt es nicht mehr.'];
            header('Location: ' . ReusableBlocksModule::url(), true, 303);
            exit;
        }

        return [
            'section' => 'edit',
            'page_title' => !empty($data['is_new']) ? 'Neue Vorlage' : 'Vorlage bearbeiten',
            'data' => $data,
        ];
    }

    $search = is_string($_GET['q'] ?? null) ? mb_substr(trim((string) $_GET['q']), 0, 100) : '';

    return ['section' => 'list', 'data' => $module->getListData($search)];
}

$sectionPageConfig = [
    'section' => 'list',
    'route_path' => ReusableBlocksModule::ROUTE,
    'view_file' => __DIR__ . '/views/reusable-blocks/index.php',
    'page_title' => 'EditorJS Block Vorlagen',
    'active_page' => 'reusable-blocks',
    'csrf_action' => 'admin_reusable_blocks',
    'module_factory' => static fn (): ReusableBlocksModule => new ReusableBlocksModule(),
    'access_checker' => static fn (): bool => cms_admin_reusable_blocks_can_access(),
    'access_denied_route' => '/',
    'request_context_resolver' => static fn (ReusableBlocksModule $module): array => cms_admin_reusable_blocks_request_context($module),
    'redirect_path_resolver' => static function ($module, string $section, mixed $result = null): string {
        if (is_array($result) && is_string($result['__redirect'] ?? null)) {
            return (string) $result['__redirect'];
        }

        return ReusableBlocksModule::url();
    },
    'post_handler' => static function (ReusableBlocksModule $module, string $section, array $post): array {
        return $module->handlePost($post, (int) (Auth::instance()->currentUser()->id ?? 0));
    },
];

require __DIR__ . '/partials/section-page-shell.php';
