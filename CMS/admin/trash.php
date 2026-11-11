<?php
declare(strict_types=1);

/**
 * Papierkorb – zentrale Übersicht aller Inhaltstypen
 * Route: /admin/trash[?type=<typ>][&q=…][&page=n]
 *
 * Zugriff: `manage_trash`; je Typ zusätzlich die Capability des Providers.
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;

const CMS_ADMIN_TRASH_CAPABILITY = 'manage_trash';

function cms_admin_trash_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_TRASH_CAPABILITY);
}

if (!cms_admin_trash_can_access()) {
    header('Location: /');
    exit;
}

$sectionPageConfig = [
    'section' => 'trash',
    'route_path' => '/admin/trash',
    'view_file' => __DIR__ . '/views/trash/index.php',
    'page_assets' => ['js' => [cms_asset_url('js/admin-trash.js')]],
    'page_title' => __('trash.central.page_title'),
    'active_page' => 'trash',
    'csrf_action' => 'admin_trash',
    'module_file' => __DIR__ . '/modules/trash/TrashModule.php',
    'module_factory' => static fn (): TrashModule => new TrashModule(),
    'data_loader' => static fn (TrashModule $module): array => $module->getData($_GET),
    'access_checker' => static fn (): bool => cms_admin_trash_can_access(),
    'access_denied_route' => '/',
    'redirect_path_resolver' => static function ($module, string $section, mixed $result = null): string {
        return is_array($result) && is_string($result['__redirect'] ?? null) ? (string) $result['__redirect'] : '/admin/trash';
    },
    'invalid_token_message' => __('trash.central.error_token'),
    'unknown_action_message' => __('trash.error.failed'),
    'post_handler' => static fn (TrashModule $module, string $section, array $post): array => $module->handlePost($post),
];

require __DIR__ . '/partials/section-page-shell.php';
