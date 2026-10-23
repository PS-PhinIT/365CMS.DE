<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Comments – Entry Point
 * Route: /admin/comments
 */

use CMS\Auth;

const CMS_ADMIN_COMMENTS_ALLOWED_ACTIONS = ['status', 'edit', 'delete', 'bulk', 'empty_trash'];
const CMS_ADMIN_COMMENTS_ALLOWED_STATUSES = ['pending', 'approved', 'spam', 'trash'];
const CMS_ADMIN_COMMENTS_ALLOWED_BULK_ACTIONS = ['approve', 'spam', 'trash', 'delete'];
const CMS_ADMIN_COMMENTS_MAX_BULK_IDS = 100;

function cms_admin_comments_normalize_action(mixed $action): string
{
    $normalizedAction = strtolower(trim((string)$action));

    return in_array($normalizedAction, CMS_ADMIN_COMMENTS_ALLOWED_ACTIONS, true) ? $normalizedAction : '';
}

function cms_admin_comments_normalize_positive_id(mixed $id): int
{
    $normalizedId = (int)$id;

    return $normalizedId > 0 ? $normalizedId : 0;
}

function cms_admin_comments_normalize_status_value(mixed $status): string
{
    $normalizedStatus = strtolower(trim((string)$status));

    return in_array($normalizedStatus, CMS_ADMIN_COMMENTS_ALLOWED_STATUSES, true) ? $normalizedStatus : '';
}

function cms_admin_comments_normalize_bulk_action(mixed $action): string
{
    $normalizedAction = strtolower(trim((string)$action));

    return in_array($normalizedAction, CMS_ADMIN_COMMENTS_ALLOWED_BULK_ACTIONS, true) ? $normalizedAction : '';
}

/** @return array{query:string,author_scope:string,link_scope:string,content_view:string} */
function cms_admin_comments_normalize_filters(CommentsModule $module, array $source): array
{
    return $module->normalizeFilters([
        'q' => $source['q'] ?? '',
        'author_scope' => $source['author_scope'] ?? 'all',
        'link_scope' => $source['link_scope'] ?? 'all',
        'content_view' => $source['content_view'] ?? 'excerpt',
    ]);
}

/**
 * @return array{action:string,id:int,new_status:string,bulk_action:string,ids:list<int>,author:string,content:string}
 */
function cms_admin_comments_normalize_payload(array $post): array
{
    return [
        'action' => cms_admin_comments_normalize_action($post['action'] ?? ''),
        'id' => cms_admin_comments_normalize_positive_id($post['id'] ?? 0),
        'new_status' => cms_admin_comments_normalize_status_value($post['new_status'] ?? ''),
        'bulk_action' => cms_admin_comments_normalize_bulk_action($post['bulk_action'] ?? ''),
        'ids' => cms_admin_comments_normalize_bulk_ids($post['ids'] ?? []),
        'author' => is_string($post['author'] ?? null) ? $post['author'] : '',
        'content' => is_string($post['content'] ?? null) ? $post['content'] : '',
    ];
}

/**
 * @return list<int>
 */
function cms_admin_comments_normalize_bulk_ids(mixed $ids): array
{
    if (!is_array($ids)) {
        return [];
    }

    $normalized = [];

    foreach ($ids as $id) {
        $value = cms_admin_comments_normalize_positive_id($id);
        if ($value <= 0) {
            continue;
        }

        $normalized[$value] = $value;
        if (count($normalized) >= CMS_ADMIN_COMMENTS_MAX_BULK_IDS) {
            break;
        }
    }

    return array_values($normalized);
}

function cms_admin_comments_handle_action(CommentsModule $module, array $payload): array
{
    switch ($payload['action']) {
        case 'status':
            return $module->updateStatus($payload['id'], $payload['new_status']);

        case 'edit':
            return $module->edit($payload['id'], $payload['author'], $payload['content']);

        case 'delete':
            return $module->delete($payload['id']);

        case 'bulk':
            return $module->bulkAction($payload['bulk_action'], $payload['ids']);

        case 'empty_trash':
            return $module->emptyTrash();
    }

    return ['success' => false, 'error' => 'Aktion konnte nicht verarbeitet werden.'];
}

$sectionPageConfig = [
    'section' => 'comments',
    'route_path' => '/admin/comments',
    'view_file' => __DIR__ . '/views/comments/list.php',
    'page_title' => 'Kommentare',
    'active_page' => 'comments',
    'page_assets' => [
        'js' => [
            cms_asset_url('js/admin-comments.js'),
        ],
    ],
    'csrf_action' => 'admin_comments',
    'module_file' => __DIR__ . '/modules/comments/CommentsModule.php',
    'module_factory' => static fn (): CommentsModule => new CommentsModule(),
    'data_loader' => static fn (CommentsModule $module): array => $module->getListData(
        $module->normalizeStatusFilter((string) ($_GET['status'] ?? 'all')),
        cms_admin_comments_normalize_filters($module, $_GET)
    ),
    'access_checker' => static fn (): bool => Auth::isLoggedIn() && function_exists('current_user_can') && current_user_can('comments.view'),
    'redirect_path_resolver' => static function (CommentsModule $module): string {
        $status = $module->normalizeStatusFilter((string) ($_GET['status'] ?? 'all'));
        $target = $module->buildListUrlWithFilters($status, cms_admin_comments_normalize_filters($module, $_GET));

        return (string) parse_url($target, PHP_URL_PATH) . (($query = (string) parse_url($target, PHP_URL_QUERY)) !== '' ? '?' . $query : '');
    },
    'invalid_token_message' => 'Sicherheitstoken ungültig.',
    'unknown_action_message' => 'Aktion konnte nicht verarbeitet werden.',
    'post_handler' => static function (CommentsModule $module, string $section, array $post): array {
        if (!$module->canView()) {
            return ['success' => false, 'error' => 'Sie dürfen Kommentare nicht ansehen.'];
        }

        $payload = cms_admin_comments_normalize_payload($post);

        if ($payload['action'] === '' || !$module->isSupportedAction($payload['action'])) {
            return ['success' => false, 'error' => 'Unbekannte Kommentar-Aktion.'];
        }

        if ($payload['action'] === 'status' && ($payload['id'] <= 0 || $payload['new_status'] === '')) {
            return ['success' => false, 'error' => 'Ungültige Kommentar-Aktion.'];
        }

        if ($payload['action'] === 'edit' && $payload['id'] <= 0) {
            return ['success' => false, 'error' => 'Ungültige Kommentar-ID.'];
        }

        if ($payload['action'] === 'edit' && !$module->canEdit()) {
            return ['success' => false, 'error' => 'Sie dürfen Kommentare nicht bearbeiten.'];
        }

        if ($payload['action'] === 'delete' && $payload['id'] <= 0) {
            return ['success' => false, 'error' => 'Ungültige Kommentar-ID.'];
        }

        if ($payload['action'] === 'bulk' && ($payload['bulk_action'] === '' || $payload['ids'] === [])) {
            return ['success' => false, 'error' => 'Ungültige Bulk-Aktion.'];
        }

        if ($payload['action'] === 'status' && !$module->canModerate()) {
            return ['success' => false, 'error' => 'Sie dürfen Kommentare nicht moderieren.'];
        }

        if ($payload['action'] === 'delete' && !$module->canDelete()) {
            return ['success' => false, 'error' => 'Sie dürfen Kommentare nicht löschen.'];
        }

        if ($payload['action'] === 'empty_trash' && !$module->canDelete()) {
            return ['success' => false, 'error' => 'Sie dürfen Kommentare nicht löschen.'];
        }

        if ($payload['action'] === 'bulk') {
            if ($payload['bulk_action'] === 'delete' && !$module->canDelete()) {
                return ['success' => false, 'error' => 'Sie dürfen Kommentare nicht löschen.'];
            }

            if ($payload['bulk_action'] !== 'delete' && !$module->canModerate()) {
                return ['success' => false, 'error' => 'Sie dürfen Kommentare nicht moderieren.'];
            }
        }

        return cms_admin_comments_handle_action($module, $payload);
    },
];

require __DIR__ . '/partials/section-page-shell.php';
