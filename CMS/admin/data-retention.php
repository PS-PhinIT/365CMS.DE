<?php
declare(strict_types=1);

/**
 * Löschfristen – Übersicht aller Aufbewahrungsregeln
 * Route: /admin/data-retention
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;

const CMS_ADMIN_DATA_RETENTION_CAPABILITY = 'manage_settings';
const CMS_ADMIN_DATA_RETENTION_ACTIONS = ['run_rule', 'run_all', 'save_comment_ips'];

function cms_admin_data_retention_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_DATA_RETENTION_CAPABILITY);
}

if (!cms_admin_data_retention_can_access()) {
    header('Location: /');
    exit;
}

function cms_admin_data_retention_normalize_action(mixed $action): string
{
    $action = strtolower(trim((string) $action));

    return in_array($action, CMS_ADMIN_DATA_RETENTION_ACTIONS, true) ? $action : '';
}

$sectionPageConfig = [
    'section' => 'data-retention',
    'route_path' => '/admin/data-retention',
    'view_file' => __DIR__ . '/views/legal/data-retention.php',
    'page_title' => __('retention.page_title'),
    'active_page' => 'data-retention',
    'csrf_action' => 'admin_data_retention',
    'module_file' => __DIR__ . '/modules/legal/DataRetentionModule.php',
    'module_factory' => static fn (): DataRetentionModule => new DataRetentionModule(),
    'data_loader' => static fn (DataRetentionModule $module): array => $module->getData(),
    'access_checker' => static fn (): bool => cms_admin_data_retention_can_access(),
    'access_denied_route' => '/',
    'unknown_action_message' => __('retention.error.unknown_action'),
    'post_handler' => static function (DataRetentionModule $module, string $section, array $post): array {
        return match (cms_admin_data_retention_normalize_action($post['action'] ?? '')) {
            'run_rule' => $module->runRule((string) ($post['rule_key'] ?? '')),
            'run_all' => $module->runAll(),
            'save_comment_ips' => $module->saveCommentIpSettings($post),
            default => ['success' => false, 'error' => __('retention.error.unknown_action')],
        };
    },
];

require __DIR__ . '/partials/section-page-shell.php';
