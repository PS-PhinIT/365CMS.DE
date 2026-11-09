<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Benutzer – Entry Point
 * Route: /admin/users
 */

use CMS\Auth;

const CMS_ADMIN_USERS_ALLOWED_ACTIONS = ['save', 'delete', 'revoke_sessions', 'cancel_email_change', 'bulk'];
const CMS_ADMIN_USERS_ALLOWED_VIEWS = ['list', 'edit'];
const CMS_ADMIN_USERS_ALLOWED_BULK_ACTIONS = ['activate', 'deactivate', 'delete', 'hard_delete'];
const CMS_ADMIN_USERS_WRITE_CAPABILITY = 'manage_users';

function cms_admin_users_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_USERS_WRITE_CAPABILITY);
}

function cms_admin_users_target_url(?int $id = null): string
{
    if ($id !== null && $id > 0) {
        return '/admin/users?action=edit&id=' . $id;
    }

    return '/admin/users';
}

function cms_admin_users_flash(string $type, string $message): void
{
    $_SESSION['admin_alert'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function cms_admin_users_redirect(?int $id = null): never
{
    header('Location: ' . cms_admin_users_target_url($id));
    exit;
}

function cms_admin_users_normalize_action(mixed $action): string
{
    $normalizedAction = trim((string) $action);

    return in_array($normalizedAction, CMS_ADMIN_USERS_ALLOWED_ACTIONS, true) ? $normalizedAction : '';
}

function cms_admin_users_normalize_view(mixed $view): string
{
    $normalizedView = trim((string) $view);

    return in_array($normalizedView, CMS_ADMIN_USERS_ALLOWED_VIEWS, true) ? $normalizedView : 'list';
}

function cms_admin_users_normalize_positive_id(mixed $id): int
{
    $normalizedId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $normalizedId === false ? 0 : (int) $normalizedId;
}

function cms_admin_users_normalize_bulk_action(mixed $bulkAction): string
{
    $normalizedBulkAction = trim((string) $bulkAction);

    return in_array($normalizedBulkAction, CMS_ADMIN_USERS_ALLOWED_BULK_ACTIONS, true) ? $normalizedBulkAction : '';
}

/**
 * @return array{action:string,id:int,bulk_action:string,ids:array<int,int>,post:array<string,mixed>}
 */
function cms_admin_users_normalize_payload(array $post): array
{
    return [
        'action' => cms_admin_users_normalize_action($post['action'] ?? ''),
        'id' => cms_admin_users_normalize_positive_id($post['id'] ?? 0),
        'bulk_action' => cms_admin_users_normalize_bulk_action($post['bulk_action'] ?? ''),
        'ids' => cms_admin_users_normalize_bulk_ids($post['ids'] ?? []),
        'post' => $post,
    ];
}

/**
 * @return array<int,int>
 */
function cms_admin_users_normalize_bulk_ids(mixed $ids): array
{
    $normalizedIds = [];

    foreach ((array) $ids as $id) {
        $normalizedId = cms_admin_users_normalize_positive_id($id);
        if ($normalizedId > 0) {
            $normalizedIds[$normalizedId] = $normalizedId;
        }

        if (count($normalizedIds) >= 200) {
            break;
        }
    }

    return array_values($normalizedIds);
}

/**
 * @param array<string,mixed>|null $formData
 * @return array<string,mixed>
 */
function cms_admin_users_view_config(UsersModule $module, string $view, ?int $editId = null, ?array $formData = null): array
{
    if ($view === 'edit') {
        $resolvedId = $editId !== null && $editId > 0 ? $editId : null;
        $data = $module->getEditData($resolvedId);

        if (is_array($formData) && $formData !== []) {
            $user = $data['user'] ?? null;
            if (!is_object($user)) {
                $user = new stdClass();
            }

            $user->id = $resolvedId ?? 0;
            $user->username = trim((string) ($formData['username'] ?? ($user->username ?? '')));
            $user->email = trim((string) ($formData['email'] ?? ($user->email ?? '')));
            $user->role = (string) ($formData['role'] ?? ($user->role ?? 'member'));
            $user->status = (string) ($formData['status'] ?? ($user->status ?? 'active'));
            $user->meta = array_merge(
                is_array($user->meta ?? null) ? $user->meta : [],
                [
                    'first_name' => trim((string) ($formData['first_name'] ?? '')),
                    'last_name' => trim((string) ($formData['last_name'] ?? '')),
                ]
            );

            $data['user'] = $user;
            $data['isNew'] = $resolvedId === null;
        }

        return [
            'view_file' => __DIR__ . '/views/users/edit.php',
            'page_title' => !empty($data['isNew']) ? 'Neuer Benutzer' : 'Benutzer bearbeiten',
            'active_page' => 'users',
            'page_assets' => [
                'js' => [
                    cms_asset_url('js/admin-users.js'),
                ],
            ],
            'data' => $data,
        ];
    }

    $data = $module->getListData();

    return [
        'view_file' => __DIR__ . '/views/users/list.php',
        'page_title' => 'Benutzer',
        'active_page' => 'users',
        'page_assets' => [
            'js' => [
                cms_asset_url('js/admin-users.js'),
            ],
        ],
        'data' => $data,
    ];
}

$sectionPageConfig = [
    'route_path' => '/admin/users',
    'view_file' => __DIR__ . '/views/users/list.php',
    'page_title' => 'Benutzer',
    'active_page' => 'users',
    'csrf_action' => 'admin_users',
    'module_file' => __DIR__ . '/modules/users/UsersModule.php',
    'module_factory' => static fn (): UsersModule => new UsersModule(),
    'access_checker' => static fn (): bool => cms_admin_users_can_access(),
    'request_context_resolver' => static function (UsersModule $module): array {
        $view = cms_admin_users_normalize_view($_GET['action'] ?? 'list');
        $id = cms_admin_users_normalize_positive_id($_GET['id'] ?? 0);

        if ($view === 'edit' && $id > 0 && !$module->hasUser($id)) {
            cms_admin_users_flash('danger', 'Der angeforderte Benutzer existiert nicht mehr.');
            cms_admin_users_redirect();
        }

        return cms_admin_users_view_config($module, $view, $id > 0 ? $id : null);
    },
    'redirect_path_resolver' => static function (UsersModule $module, string $section, ?array $result = null): string {
        $redirectPath = trim((string) ($result['redirect_path'] ?? ''));
        if ($redirectPath !== '') {
            return $redirectPath;
        }

        $view = cms_admin_users_normalize_view($_GET['action'] ?? 'list');
        $id = cms_admin_users_normalize_positive_id($_GET['id'] ?? 0);
        if ($view === 'edit' && $id > 0) {
            return cms_admin_users_target_url($id);
        }

        return cms_admin_users_target_url();
    },
    'post_handler' => static function (UsersModule $module, string $section, array $post): array {
        $payload = cms_admin_users_normalize_payload($post);

        if ($payload['action'] === '') {
            return ['success' => false, 'error' => 'Unbekannte Benutzer-Aktion.'];
        }

        if ($payload['action'] === 'save') {
            $result = $module->save($payload['post']);
            $savedId = cms_admin_users_normalize_positive_id($result['id'] ?? ($payload['post']['id'] ?? 0));

            if (!empty($result['success']) && $savedId > 0) {
                $result['redirect_path'] = cms_admin_users_target_url($savedId);

                return $result;
            }

            if ($savedId > 0 && !$module->hasUser($savedId)) {
                $result['error'] = $result['error'] ?? 'Der angeforderte Benutzer existiert nicht mehr.';
                $result['redirect_path'] = cms_admin_users_target_url();
                unset($result['render_inline'], $result['runtime_context']);

                return $result;
            }

            $result['render_inline'] = true;
            $result['runtime_context'] = cms_admin_users_view_config(
                $module,
                'edit',
                $savedId > 0 ? $savedId : null,
                $payload['post']
            );

            return $result;
        }

        if ($payload['action'] === 'delete') {
            if ($payload['id'] < 1) {
                return ['success' => false, 'error' => 'Ungültige Benutzer-ID.'];
            }

            $result = $module->deleteUser($payload['id']);
            if (!empty($result['success'])) {
                $result['redirect_path'] = cms_admin_users_target_url();
            }

            return $result;
        }

        if ($payload['action'] === 'revoke_sessions') {
            if ($payload['id'] < 1 || !$module->hasUser($payload['id'])) {
                return ['success' => false, 'error' => 'Ungültige Benutzer-ID.'];
            }

            $result = $module->revokeSessions($payload['id']);
            $result['redirect_path'] = cms_admin_users_target_url($payload['id']);

            return $result;
        }

        if ($payload['action'] === 'cancel_email_change') {
            if ($payload['id'] < 1 || !$module->hasUser($payload['id'])) {
                return ['success' => false, 'error' => 'Ungültige Benutzer-ID.'];
            }

            $result = $module->cancelEmailChange($payload['id']);
            $result['redirect_path'] = cms_admin_users_target_url($payload['id']);

            return $result;
        }

        if ($payload['bulk_action'] === '') {
            return ['success' => false, 'error' => 'Unbekannte Bulk-Aktion für Benutzer.'];
        }

        if ($payload['ids'] === []) {
            return ['success' => false, 'error' => 'Bitte mindestens einen gültigen Benutzer auswählen.'];
        }

        $result = $module->bulkAction($payload['bulk_action'], $payload['ids']);
        $result['redirect_path'] = cms_admin_users_target_url();

        return $result;
    },
];

require __DIR__ . '/partials/section-page-shell.php';
