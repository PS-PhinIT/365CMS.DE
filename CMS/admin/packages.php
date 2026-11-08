<?php
declare(strict_types=1);

/**
 * Abo-Pakete – Entry Point
 * Route: /admin/packages
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Services\CoreModuleService;

const CMS_ADMIN_PACKAGES_CAPABILITY = 'manage_settings';

const CMS_ADMIN_PACKAGES_ALLOWED_ACTIONS = [
    'save',
    'seed_defaults',
    'delete',
    'toggle',
    'save_package_settings',
];

function cms_admin_packages_normalize_action(mixed $action): string
{
    $normalizedAction = strtolower(trim((string)$action));

    return in_array($normalizedAction, CMS_ADMIN_PACKAGES_ALLOWED_ACTIONS, true) ? $normalizedAction : '';
}

function cms_admin_packages_normalize_positive_id(mixed $id): int
{
    $normalizedId = (int)$id;

    return $normalizedId > 0 ? $normalizedId : 0;
}

/**
 * @return array<string, callable(array): array>
 */
function cms_admin_packages_action_handlers(PackagesModule $module, SubscriptionSettingsModule $settingsModule): array
{
    return [
        'save' => static fn (array $post): array => $module->save($post),
        'seed_defaults' => static fn (array $post): array => $module->seedDefaults(),
        'delete' => static fn (array $post): array => $module->delete(cms_admin_packages_normalize_positive_id($post['id'] ?? 0)),
        'toggle' => static fn (array $post): array => $module->toggleStatus(cms_admin_packages_normalize_positive_id($post['id'] ?? 0)),
        'save_package_settings' => static fn (array $post): array => $settingsModule->savePackageSettings($post)->toArray(),
    ];
}

if (!Auth::instance()->isAdmin()
    || !Auth::instance()->hasCapability(CMS_ADMIN_PACKAGES_CAPABILITY)
    || !CoreModuleService::getInstance()->isAdminPageEnabled('packages')) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/subscriptions/PackagesModule.php';
require_once __DIR__ . '/modules/subscriptions/SubscriptionSettingsModule.php';

$sectionPageConfig = [
    'route_path' => '/admin/packages',
    'view_file' => __DIR__ . '/views/subscriptions/packages.php',
    'page_title' => 'Pakete & Abo-Einstellungen',
    'active_page' => 'packages',
    'csrf_action' => 'admin_packages',
    'module_file' => __DIR__ . '/modules/subscriptions/PackagesModule.php',
    'module_factory' => static fn (): PackagesModule => new PackagesModule(),
    'access_checker' => static fn (): bool => Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_PACKAGES_CAPABILITY)
        && CoreModuleService::getInstance()->isAdminPageEnabled('packages'),
    'request_context_resolver' => static function (PackagesModule $module): array {
        $settingsModule = new SubscriptionSettingsModule();

        return [
            'data' => array_merge($module->getData(), $settingsModule->getPackageData()->toArray()),
        ];
    },
    'post_handler' => static function (PackagesModule $module, string $section, array $post): array {
        $settingsModule = new SubscriptionSettingsModule();
        $action = cms_admin_packages_normalize_action($post['action'] ?? '');
        $actionHandlers = cms_admin_packages_action_handlers($module, $settingsModule);

        if ($action === '' || !isset($actionHandlers[$action])) {
            return ['success' => false, 'error' => 'Unbekannte Aktion.'];
        }

        if (in_array($action, ['delete', 'toggle'], true) && cms_admin_packages_normalize_positive_id($post['id'] ?? 0) <= 0) {
            return ['success' => false, 'error' => 'Ungültige Paket-ID.'];
        }

        return $actionHandlers[$action]($post);
    },
];

require __DIR__ . '/partials/section-page-shell.php';
