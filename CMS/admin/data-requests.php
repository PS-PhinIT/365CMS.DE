<?php
declare(strict_types=1);

/**
 * Auskunft & Löschen – Entry Point
 * Route: /admin/data-requests
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Services\CoreModuleService;

const CMS_ADMIN_DATA_REQUESTS_READ_CAPABILITY = 'manage_settings';
const CMS_ADMIN_DATA_REQUESTS_WRITE_CAPABILITY = 'manage_settings';

function cms_admin_data_requests_can_access(): bool
{
    return Auth::instance()->isAdmin()
    && Auth::instance()->hasCapability(CMS_ADMIN_DATA_REQUESTS_READ_CAPABILITY)
    && (!class_exists(CoreModuleService::class) || CoreModuleService::getInstance()->isAdminPageEnabled('data-requests'));
}

function cms_admin_data_requests_can_mutate(): bool
{
    return cms_admin_data_requests_can_access()
        && Auth::instance()->hasCapability(CMS_ADMIN_DATA_REQUESTS_WRITE_CAPABILITY);
}

/** @return array<string, array<string, true>> */
function cms_admin_data_requests_allowed_actions_by_scope(): array
{
    return [
        'privacy' => [
            'process' => true,
            'complete' => true,
            'reject' => true,
            'escalate' => true,
            'delete' => true,
        ],
        'deletion' => [
            'process' => true,
            'execute' => true,
            'reject' => true,
            'escalate' => true,
            'delete' => true,
        ],
    ];
}

function cms_admin_data_requests_normalize_scope(mixed $value): ?string
{
    $scope = strtolower(trim((string) $value));
    $allowedScopes = cms_admin_data_requests_allowed_actions_by_scope();

    return isset($allowedScopes[$scope]) ? $scope : null;
}

function cms_admin_data_requests_normalize_action(?string $scope, mixed $value): ?string
{
    if ($scope === null) {
        return null;
    }

    $action = strtolower(trim((string) $value));
    $allowedActions = cms_admin_data_requests_allowed_actions_by_scope()[$scope] ?? [];

    return isset($allowedActions[$action]) ? $action : null;
}

function cms_admin_data_requests_normalize_id(array $post): int
{
    $id = (int) ($post['id'] ?? 0);

    return $id > 0 ? $id : 0;
}

function cms_admin_data_requests_normalize_reason(array $post): string
{
    $reason = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($post['reject_reason'] ?? ''));
    $reason = preg_replace('/\s+/u', ' ', (string) $reason);

    return trim((string) $reason);
}

function cms_admin_data_requests_handle_privacy_action(PrivacyRequestsModule $privacyModule, string $action, array $post): array
{
    $id = cms_admin_data_requests_normalize_id($post);
    $reason = cms_admin_data_requests_normalize_reason($post);

    return match ($action) {
        'process'  => $privacyModule->processRequest($id),
        'complete' => $privacyModule->completeRequest($id, !empty($post['include_email'])),
        'reject'   => $privacyModule->rejectRequest($id, $reason),
        'escalate' => $privacyModule->escalateRequest($id),
        'delete'   => $privacyModule->deleteRequest($id),
        default    => ['success' => false, 'error' => 'Unbekannte Auskunfts-Aktion.'],
    };
}

function cms_admin_data_requests_handle_deletion_action(DeletionRequestsModule $deletionModule, string $action, array $post): array
{
    $id = cms_admin_data_requests_normalize_id($post);
    $reason = cms_admin_data_requests_normalize_reason($post);

    return match ($action) {
        'process' => $deletionModule->processRequest($id),
        'execute' => $deletionModule->executeDeletion($id, !empty($post['include_email'])),
        'reject'  => $deletionModule->rejectRequest($id, $reason),
        'escalate' => $deletionModule->escalateRequest($id),
        'delete'  => $deletionModule->deleteRequest($id),
        default   => ['success' => false, 'error' => 'Unbekannte Lösch-Aktion.'],
    };
}

function cms_admin_data_requests_handle_scope_action(
    PrivacyRequestsModule $privacyModule,
    DeletionRequestsModule $deletionModule,
    string $scope,
    string $action,
    array $post
): array {
    return match ($scope) {
        'privacy' => cms_admin_data_requests_handle_privacy_action($privacyModule, $action, $post),
        'deletion' => cms_admin_data_requests_handle_deletion_action($deletionModule, $action, $post),
        default => ['success' => false, 'error' => 'Unbekannte Aktion.'],
    };
}

$sectionPageConfig = [
    'section' => 'data-requests',
    'route_path' => '/admin/data-requests',
    'view_file' => __DIR__ . '/views/legal/data-requests.php',
    'page_title' => 'Auskunft & Löschen',
    'active_page' => 'data-requests',
    'page_assets' => [
        'js' => [
            cms_asset_url('js/admin-data-requests.js'),
        ],
    ],
    'csrf_action' => 'admin_data_requests',
    'module_file' => __DIR__ . '/modules/legal/PrivacyRequestsModule.php',
    'module_factory' => static function (): array {
        require_once __DIR__ . '/modules/legal/DeletionRequestsModule.php';

        return [
            'privacy' => new PrivacyRequestsModule(),
            'deletion' => new DeletionRequestsModule(),
        ];
    },
    'data_loader' => static function (array $modules): array {
        return [
            'privacy' => $modules['privacy']->getData(),
            'deletion' => $modules['deletion']->getData(),
        ];
    },
    'access_checker' => static fn (): bool => cms_admin_data_requests_can_access(),
    'invalid_token_message' => 'Sicherheitstoken ungültig.',
    'unknown_action_message' => 'Unbekannte oder nicht erlaubte Aktion.',
    'post_handler' => static function (array $modules, string $section, array $post): array {
        if (!cms_admin_data_requests_can_mutate()) {
            return ['success' => false, 'error' => 'Keine Berechtigung für Datenschutzanfragen.'];
        }

        $scope = cms_admin_data_requests_normalize_scope($post['scope'] ?? null);
        $action = cms_admin_data_requests_normalize_action($scope, $post['action'] ?? null);

        if ($scope === null || $action === null) {
            return ['success' => false, 'error' => 'Unbekannte oder nicht erlaubte Aktion.'];
        }

        return cms_admin_data_requests_handle_scope_action($modules['privacy'], $modules['deletion'], $scope, $action, $post);
    },
];

require __DIR__ . '/partials/section-page-shell.php';
