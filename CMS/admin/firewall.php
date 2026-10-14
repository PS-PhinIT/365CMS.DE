<?php
declare(strict_types=1);

/**
 * Firewall – Entry Point
 * Route: /admin/firewall
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Services\CoreModuleService;

const CMS_ADMIN_FIREWALL_ALLOWED_ACTIONS = [
    'save_settings',
    'apply_baseline_profile',
    'add_rule',
    'delete_rule',
    'toggle_rule',
    'set_rule_mode',
    'save_proxy_settings',
    'trust_proxy_candidate',
    'dismiss_proxy_candidate',
    'clear_proxy_candidates',
];

const CMS_ADMIN_FIREWALL_ACTION_CAPABILITIES = [
    'save_settings' => 'manage_settings',
    'apply_baseline_profile' => 'manage_settings',
    'add_rule' => 'manage_settings',
    'delete_rule' => 'manage_settings',
    'toggle_rule' => 'manage_settings',
    'set_rule_mode' => 'manage_settings',
    'save_proxy_settings' => 'manage_settings',
    'trust_proxy_candidate' => 'manage_settings',
    'dismiss_proxy_candidate' => 'manage_settings',
    'clear_proxy_candidates' => 'manage_settings',
];

function cms_admin_firewall_normalize_action(mixed $action): string
{
    $normalizedAction = strtolower(trim((string)$action));

    return in_array($normalizedAction, CMS_ADMIN_FIREWALL_ALLOWED_ACTIONS, true) ? $normalizedAction : '';
}

function cms_admin_firewall_normalize_positive_id(mixed $id): int
{
    $normalizedId = (int)$id;

    return $normalizedId > 0 ? $normalizedId : 0;
}

function cms_admin_firewall_can_run_action(string $action): bool
{
    $requiredCapability = CMS_ADMIN_FIREWALL_ACTION_CAPABILITIES[$action] ?? '';
    if ($requiredCapability === '') {
        return false;
    }

    return Auth::instance()->hasCapability($requiredCapability);
}

function cms_admin_firewall_handle_action(FirewallModule $module, string $action, array $post): array
{
    return match ($action) {
        'save_settings' => $module->saveSettings($post),
        'apply_baseline_profile' => $module->applyBaselineProfile((string)($post['baseline_profile'] ?? '')),
        'add_rule' => $module->addRule($post),
        'delete_rule' => $module->deleteRule(cms_admin_firewall_normalize_positive_id($post['id'] ?? 0)),
        'toggle_rule' => $module->toggleRule(cms_admin_firewall_normalize_positive_id($post['id'] ?? 0)),
        'set_rule_mode' => $module->setRuleMode(cms_admin_firewall_normalize_positive_id($post['id'] ?? 0), (string)($post['target_mode'] ?? '')),
        'save_proxy_settings' => $module->saveProxySettings($post),
        'trust_proxy_candidate' => $module->trustProxyCandidate((string)($post['network'] ?? '')),
        'dismiss_proxy_candidate' => $module->dismissProxyCandidate((string)($post['network'] ?? '')),
        'clear_proxy_candidates' => $module->clearProxyCandidates(),
        default => ['success' => false, 'error' => 'Firewall-Aktion konnte nicht verarbeitet werden.'],
    };
}

$sectionPageConfig = [
    'section' => 'firewall',
    'route_path' => '/admin/firewall',
    'view_file' => __DIR__ . '/views/security/firewall.php',
    'page_title' => 'Firewall',
    'active_page' => 'firewall',
    'page_assets' => [],
    'csrf_action' => 'admin_firewall',
    'module_file' => __DIR__ . '/modules/security/FirewallModule.php',
    'module_factory' => static fn (): FirewallModule => new FirewallModule(),
    'data_loader' => static fn (FirewallModule $module): array => $module->getData(),
    'access_checker' => static fn (): bool => Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability('manage_settings')
        && (!class_exists(CoreModuleService::class) || CoreModuleService::getInstance()->isAdminPageEnabled('firewall')),
    'invalid_token_message' => 'Sicherheitstoken ungültig.',
    'unknown_action_message' => 'Firewall-Aktion konnte nicht verarbeitet werden.',
    'post_handler' => static function (FirewallModule $module, string $section, array $post): array {
        $action = cms_admin_firewall_normalize_action($post['action'] ?? '');

        if ($action === '' || !$module->isSupportedAction($action)) {
            return ['success' => false, 'error' => 'Unbekannte Firewall-Aktion.'];
        }

        if (!cms_admin_firewall_can_run_action($action)) {
            return ['success' => false, 'error' => 'Keine Berechtigung für diese Firewall-Aktion.'];
        }

        if (in_array($action, ['delete_rule', 'toggle_rule', 'set_rule_mode'], true) && cms_admin_firewall_normalize_positive_id($post['id'] ?? 0) <= 0) {
            return ['success' => false, 'error' => 'Ungültige Firewall-Regel-ID.'];
        }

        return cms_admin_firewall_handle_action($module, $action, $post);
    },
];

require __DIR__ . '/partials/section-page-shell.php';
