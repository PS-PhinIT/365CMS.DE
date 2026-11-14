<?php
declare(strict_types=1);

/**
 * Users Module – Business-Logik für Benutzerverwaltung
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\Logger;
use CMS\Services\ErrorReportService;
use CMS\Services\UserService;

class UsersModule
{
    private Database $db;
    private UserService $userService;
    private string $prefix;

    public function __construct()
    {
        $this->db          = Database::instance();
        $this->prefix      = $this->db->getPrefix();
        $this->userService = UserService::getInstance();
    }

    /**
     * Daten für die Listenansicht
     */
    public function getListData(): array
    {
        $roleFilter   = \CMS\Http\Request::stringFromGet('role');
        $statusFilter = \CMS\Http\Request::stringFromGet('status');
        $search       = trim(\CMS\Http\Request::stringFromGet('q'));
        $page         = max(1, (int)($_GET['page'] ?? 1));
        $perPage      = 25;

        $result = $this->userService->getUsers([
            'role'    => $roleFilter,
            'status'  => $statusFilter,
            'search'  => $search,
            'limit'   => $perPage,
            'offset'  => ($page - 1) * $perPage,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        ]);
        $users = is_array($result['users'] ?? null) ? $result['users'] : [];
        $users = $this->attachUserSupportContexts($users);

        $stats = $this->userService->getStatistics();

        return [
            'users'          => $users,
            'total'          => $result['total'],
            'stats'          => $stats,
            'availableRoles' => $this->userService->getAvailableRoles(),
            'availableStatuses' => $this->userService->getAvailableStatuses(),
            'filter'         => ['role' => $roleFilter, 'status' => $statusFilter, 'search' => $search],
            'page'           => $page,
            'perPage'        => $perPage,
            'pages'          => (int)ceil($result['total'] / $perPage),
        ];
    }

    /**
     * Reichert die Benutzerliste um eine read-only Support-Zeile an.
     *
     * @param array<int,object> $users
     * @return array<int,object>
     */
    private function attachUserSupportContexts(array $users): array
    {
        $userIds = [];
        foreach ($users as $user) {
            $userId = (int)($user->id ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
        }

        if ($userIds === []) {
            return $users;
        }

        $directSubscriptions = $this->loadDirectSubscriptionSummaries(array_values($userIds));
        $groupSummaries = $this->loadUserGroupSupportSummaries(array_values($userIds));

        foreach ($users as $user) {
            $userId = (int)($user->id ?? 0);
            $role = (string)($user->role ?? 'member');
            $capabilities = $this->loadRoleCapabilityLookup($role);
            $memberAreas = $this->buildMemberAreaListForRole($role, $capabilities);
            $directSubscription = $directSubscriptions[$userId] ?? null;
            $groupPlans = $groupSummaries[$userId] ?? [];
            $moduleLabels = array_values(array_column($memberAreas, 'label'));

            $user->support_context = [
                'direct_package' => $directSubscription['label'] ?? '',
                'direct_package_status' => $directSubscription['status'] ?? '',
                'group_packages' => array_slice($groupPlans, 0, 3),
                'group_package_count' => count($groupPlans),
                'member_modules' => array_slice($moduleLabels, 0, 4),
                'member_module_count' => count($moduleLabels),
                'contract_label' => $directSubscription['contract_label'] ?? 'Keine aktive Frist',
                'contract_severity' => $directSubscription['contract_severity'] ?? 'secondary',
                'contract_due_at' => $directSubscription['due_at'] ?? '',
            ];
        }

        return $users;
    }

    /**
     * @param array<int,int> $userIds
     * @return array<int,array<string,string>>
     */
    private function loadDirectSubscriptionSummaries(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));

        try {
            $rows = $this->db->get_results(
                "SELECT us.user_id, us.status, us.billing_cycle, us.end_date, us.next_billing_date,
                        sp.name AS plan_name, sp.slug AS plan_slug
                   FROM {$this->prefix}user_subscriptions us
                   INNER JOIN {$this->prefix}subscription_plans sp ON sp.id = us.plan_id
                  WHERE us.user_id IN ({$placeholders})
                    AND us.status IN ('active', 'trial')
                    AND (us.end_date IS NULL OR us.end_date > NOW())
                  ORDER BY us.user_id ASC, us.created_at DESC",
                $userIds
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $summaries = [];
        foreach ($rows as $row) {
            $userId = (int)($row->user_id ?? 0);
            if ($userId <= 0 || isset($summaries[$userId])) {
                continue;
            }

            $dueAt = $this->safeUiText((string)(($row->next_billing_date ?? '') ?: ($row->end_date ?? '')), 40);
            $contractState = $this->buildContractState($dueAt);
            $summaries[$userId] = [
                'label' => $this->safeUiText((string)($row->plan_name ?? 'Aktives Paket'), 120, 'Aktives Paket'),
                'status' => $this->safeUiText((string)($row->status ?? 'active'), 30, 'active'),
                'billing_cycle' => $this->safeUiText((string)($row->billing_cycle ?? ''), 30),
                'due_at' => $dueAt,
                'contract_label' => $contractState['label'],
                'contract_severity' => $contractState['severity'],
            ];
        }

        return $summaries;
    }

    /**
     * @param array<int,int> $userIds
     * @return array<int,array<int,string>>
     */
    private function loadUserGroupSupportSummaries(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));

        try {
            $rows = $this->db->get_results(
                "SELECT ugm.user_id, ug.name AS group_name, ug.is_active, sp.name AS plan_name
                   FROM {$this->prefix}user_group_members ugm
                   INNER JOIN {$this->prefix}user_groups ug ON ug.id = ugm.group_id
                   LEFT JOIN {$this->prefix}subscription_plans sp ON sp.id = ug.plan_id
                  WHERE ugm.user_id IN ({$placeholders})
                  ORDER BY ug.is_active DESC, ug.name ASC",
                $userIds
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $summaries = [];
        foreach ($rows as $row) {
            $userId = (int)($row->user_id ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $groupName = $this->safeUiText((string)($row->group_name ?? 'Gruppe'), 80, 'Gruppe');
            $planName = $this->safeUiText((string)($row->plan_name ?? ''), 80);
            $inactiveSuffix = (int)($row->is_active ?? 0) === 1 ? '' : ' (inaktiv)';
            $summaries[$userId][] = $planName !== ''
                ? $groupName . ' → ' . $planName . $inactiveSuffix
                : $groupName . ' → kein Paket' . $inactiveSuffix;
        }

        return $summaries;
    }

    /** @return array{label:string,severity:string} */
    private function buildContractState(string $dueAt): array
    {
        $dueAt = trim($dueAt);
        if ($dueAt === '') {
            return ['label' => 'Keine Laufzeitfrist', 'severity' => 'secondary'];
        }

        try {
            $dueDate = new \DateTimeImmutable($dueAt);
            $today = new \DateTimeImmutable('today');
        } catch (\Throwable) {
            return ['label' => 'Frist unlesbar', 'severity' => 'secondary'];
        }

        $days = (int)$today->diff($dueDate->setTime(0, 0))->format('%r%a');
        if ($days < 0) {
            return ['label' => 'Überfällig seit ' . abs($days) . ' Tag' . (abs($days) === 1 ? '' : 'en'), 'severity' => 'danger'];
        }

        if ($days === 0) {
            return ['label' => 'Heute fällig', 'severity' => 'warning'];
        }

        if ($days <= 14) {
            return ['label' => 'Läuft in ' . $days . ' Tag' . ($days === 1 ? '' : 'en') . ' aus', 'severity' => 'warning'];
        }

        return ['label' => 'Läuft am ' . $dueDate->format('d.m.Y') . ' aus', 'severity' => 'info'];
    }

    /**
     * Daten für die Edit-Ansicht
     */
    public function getEditData(?int $id): array
    {
        $user = null;
        $availableRoles = $this->userService->getAvailableRoles();
        $securityEventsState = [
            'events' => [],
            'unavailable' => false,
        ];

        if ($id !== null) {
            $user = $this->userService->getUserById($id);
            $securityEventsState = $user !== null ? $this->getUserSecurityEvents($user) : $securityEventsState;
        }

        return [
            'user'              => $user,
            'isNew'             => $user === null,
            'availableRoles'    => $availableRoles,
            'availableStatuses' => $this->userService->getAvailableStatuses(),
            'securityEvents'    => $securityEventsState['events'],
            'securityEventsUnavailable' => (bool) $securityEventsState['unavailable'],
            'roleImpactPreview' => $this->buildRoleImpactPreview($user, $availableRoles),
            'pendingEmail'      => $user !== null ? \CMS\Services\EmailChangeService::getInstance()->getPending((int) $user->id) : null,
        ];
    }

    /**
     * Erstellt eine read-only Wirkungsvorschau für Rollenwechsel im Benutzerprofil.
     *
     * Der Pfad schreibt keine Daten, nutzt keine Token-URLs und fällt bei optionalen
     * Tabellen/Plugin-Registries kontrolliert auf Hinweise zurück.
     *
     * @param array<string,string> $availableRoles
     * @return array<string,mixed>
     */
    private function buildRoleImpactPreview(?object $user, array $availableRoles): array
    {
        if ($availableRoles === []) {
            return [
                'available' => false,
                'message' => 'Es sind aktuell keine Rollen verfügbar.',
                'roles' => [],
            ];
        }

        $currentRole = $this->normalizeKnownRole((string)($user->role ?? 'member'), $availableRoles);
        $currentCapabilities = $this->loadRoleCapabilityLookup($currentRole);
        $currentMemberAreas = $this->buildMemberAreaListForRole($currentRole, $currentCapabilities);
        $pluginWidgets = $this->getPluginWidgetDefinitions();
        $currentPluginWidgets = $this->filterPluginWidgetsForRole($pluginWidgets, $currentRole, $currentCapabilities);
        $packageContext = $this->getUserPackageContext((int)($user->id ?? 0));

        $roles = [];
        foreach ($availableRoles as $role => $label) {
            $targetRole = $this->normalizeKnownRole((string)$role, $availableRoles);
            if ($targetRole === '' || isset($roles[$targetRole])) {
                continue;
            }

            $targetCapabilities = $this->loadRoleCapabilityLookup($targetRole);
            $targetMemberAreas = $this->buildMemberAreaListForRole($targetRole, $targetCapabilities);
            $targetPluginWidgets = $this->filterPluginWidgetsForRole($pluginWidgets, $targetRole, $targetCapabilities);

            $gainedCapabilities = array_values(array_diff(array_keys($targetCapabilities), array_keys($currentCapabilities)));
            $lostCapabilities = array_values(array_diff(array_keys($currentCapabilities), array_keys($targetCapabilities)));
            sort($gainedCapabilities, SORT_NATURAL | SORT_FLAG_CASE);
            sort($lostCapabilities, SORT_NATURAL | SORT_FLAG_CASE);

            $roles[$targetRole] = [
                'role' => $targetRole,
                'label' => $this->safeUiText((string)$label, 80, $targetRole),
                'capability_count' => count($targetCapabilities),
                'gained_count' => count($gainedCapabilities),
                'lost_count' => count($lostCapabilities),
                'gained_capabilities' => $this->limitPreviewValues($gainedCapabilities),
                'lost_capabilities' => $this->limitPreviewValues($lostCapabilities),
                'member_areas' => array_values(array_column($targetMemberAreas, 'label')),
                'added_member_areas' => $this->diffLabeledPreviewItems($targetMemberAreas, $currentMemberAreas),
                'removed_member_areas' => $this->diffLabeledPreviewItems($currentMemberAreas, $targetMemberAreas),
                'plugin_widgets' => array_values(array_column($targetPluginWidgets, 'label')),
                'added_plugin_widgets' => $this->diffLabeledPreviewItems($targetPluginWidgets, $currentPluginWidgets),
                'removed_plugin_widgets' => $this->diffLabeledPreviewItems($currentPluginWidgets, $targetPluginWidgets),
                'package_summary' => $this->buildRolePackageImpact($currentRole, $targetRole, $packageContext, (int)($user->id ?? 0) > 0),
            ];
        }

        return [
            'available' => true,
            'current_role' => $currentRole,
            'current_label' => $this->safeUiText((string)($availableRoles[$currentRole] ?? $currentRole), 80, $currentRole),
            'is_existing_user' => (int)($user->id ?? 0) > 0,
            'plugin_widgets_unavailable' => $pluginWidgets === [],
            'roles' => $roles,
        ];
    }

    /** @param array<string,string> $availableRoles */
    private function normalizeKnownRole(string $role, array $availableRoles): string
    {
        $role = strtolower(trim($role));
        $role = preg_replace('/[^a-z0-9_-]+/', '-', $role) ?? '';
        $role = trim($role, '-_');

        if ($role !== '' && array_key_exists($role, $availableRoles)) {
            return $role;
        }

        if (array_key_exists('member', $availableRoles)) {
            return 'member';
        }

        $firstRole = array_key_first($availableRoles);
        return is_string($firstRole) ? $firstRole : '';
    }

    /** @return array<string,bool> */
    private function loadRoleCapabilityLookup(string $role): array
    {
        $capabilities = [];

        foreach ($this->userService->getRoleCapabilities($role) as $capability) {
            $normalized = $this->normalizeCapabilityName((string)$capability);
            if ($normalized !== '') {
                $capabilities[$normalized] = true;
            }
        }

        ksort($capabilities, SORT_NATURAL | SORT_FLAG_CASE);

        return $capabilities;
    }

    private function normalizeCapabilityName(string $capability): string
    {
        if (function_exists('cms_normalize_role_capability')) {
            return cms_normalize_role_capability($capability);
        }

        $capability = strtolower(trim($capability));
        $capability = str_replace(['\\', '/', ':'], '.', $capability);
        $capability = preg_replace('/\s+/', '.', $capability) ?? '';
        $capability = preg_replace('/[^a-z0-9._-]+/', '-', $capability) ?? '';
        $capability = preg_replace('/\.{2,}/', '.', $capability) ?? '';

        return trim($capability, '.-_');
    }

    /**
     * @param array<string,bool> $capabilities
     * @return array<int,array{slug:string,label:string}>
     */
    private function buildMemberAreaListForRole(string $role, array $capabilities): array
    {
        $areas = [];
        $dashboardEnabled = $this->isSettingEnabled('member_dashboard_enabled', true)
            && $this->isCoreModuleEnabled('member_dashboard', true);

        if ($dashboardEnabled) {
            $areas[] = ['slug' => 'dashboard', 'label' => 'Member-Dashboard'];
        }

        $areas[] = ['slug' => 'profile', 'label' => 'Profil'];
        $areas[] = ['slug' => 'security', 'label' => 'Sicherheit'];
        $areas[] = ['slug' => 'notifications', 'label' => 'Benachrichtigungen'];
        $areas[] = ['slug' => 'messages', 'label' => 'Nachrichten'];
        $areas[] = ['slug' => 'media', 'label' => 'Member-Dateien'];
        $areas[] = ['slug' => 'favorites', 'label' => 'Favoriten'];
        $areas[] = ['slug' => 'privacy', 'label' => 'Datenschutz'];

        if ($this->isSettingEnabled('member_subscription_visible', true)
            && $this->isCoreModuleEnabled('subscription_member_area', true)) {
            $areas[] = ['slug' => 'subscription', 'label' => 'Abo & Bestellungen'];
        }

        if ($role === 'admin'
            || !empty($capabilities['adminportal'])
            || !empty($capabilities['admin.portal'])
            || !empty($capabilities['admin-portal'])) {
            $areas[] = ['slug' => 'admin', 'label' => 'Adminmenü im Member-Bereich'];
        }

        return $areas;
    }

    private function isSettingEnabled(string $key, bool $default): bool
    {
        try {
            $value = $this->db->get_var(
                "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
                [$key]
            );
        } catch (\Throwable) {
            return $default;
        }

        if ($value === null) {
            return $default;
        }

        return !in_array(strtolower(trim((string)$value)), ['0', 'false', 'off', 'no'], true);
    }

    private function isCoreModuleEnabled(string $module, bool $default): bool
    {
        if (!class_exists('CMS\\Services\\CoreModuleService')) {
            return $default;
        }

        try {
            return \CMS\Services\CoreModuleService::getInstance()->isModuleEnabled($module);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @return array<int,array{slug:string,plugin:string,label:string,capability:string}>
     */
    private function getPluginWidgetDefinitions(): array
    {
        if (!class_exists('CMS\\Member\\PluginDashboardRegistry') && defined('ABSPATH')) {
            $registryFile = ABSPATH . 'core/Member/PluginDashboardRegistry.php';
            if (is_file($registryFile)) {
                require_once $registryFile;
            }
        }

        if (!class_exists('CMS\\Member\\PluginDashboardRegistry')) {
            return [];
        }

        try {
            $registry = \CMS\Member\PluginDashboardRegistry::instance();
            $registry->init();
            $sections = $registry->getAll();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.users')->warning('Plugin-Widget-Wirkungsvorschau konnte nicht geladen werden.', [
                'exception' => $e::class,
            ]);

            return [];
        }

        $widgets = [];
        foreach ($sections as $section) {
            if (!is_array($section) || !empty($section['parent_slug']) || ($section['dashboard_widget'] ?? null) === false) {
                continue;
            }

            $slug = $this->safeUiText((string)($section['slug'] ?? ''), 80);
            if ($slug === '') {
                continue;
            }

            $widgets[] = [
                'slug' => $slug,
                'plugin' => $this->safeUiText((string)($section['plugin'] ?? $slug), 100, $slug),
                'label' => $this->safeUiText((string)($section['label'] ?? $slug), 120, $slug),
                'capability' => $this->normalizeCapabilityName((string)($section['capability'] ?? '')),
            ];
        }

        return $widgets;
    }

    /**
     * @param array<int,array{slug:string,plugin:string,label:string,capability:string}> $widgets
     * @param array<string,bool> $capabilities
     * @return array<int,array{slug:string,label:string}>
     */
    private function filterPluginWidgetsForRole(array $widgets, string $role, array $capabilities): array
    {
        $visible = [];

        foreach ($widgets as $widget) {
            $requiredCapability = (string)($widget['capability'] ?? '');
            $isVisible = $requiredCapability === ''
                || $role === 'admin'
                || !empty($capabilities[$requiredCapability]);

            if (!$isVisible) {
                continue;
            }

            $visible[] = [
                'slug' => (string)$widget['slug'],
                'label' => (string)$widget['label'],
            ];
        }

        return $visible;
    }

    /**
     * @param array<int,array{slug:string,label:string}> $left
     * @param array<int,array{slug:string,label:string}> $right
     * @return array<int,string>
     */
    private function diffLabeledPreviewItems(array $left, array $right): array
    {
        $rightSlugs = array_fill_keys(array_values(array_map(static fn(array $item): string => (string)$item['slug'], $right)), true);
        $diff = [];

        foreach ($left as $item) {
            $slug = (string)$item['slug'];
            if ($slug !== '' && !isset($rightSlugs[$slug])) {
                $diff[] = (string)$item['label'];
            }
        }

        return $diff;
    }

    /** @return array<int,string> */
    private function limitPreviewValues(array $values, int $limit = 12): array
    {
        $values = array_values(array_filter(array_map(
            fn($value): string => $this->safeUiText((string)$value, 100),
            $values
        )));

        if (count($values) <= $limit) {
            return $values;
        }

        $remaining = count($values) - $limit;
        $values = array_slice($values, 0, $limit);
        $values[] = '+' . $remaining . ' weitere';

        return $values;
    }

    /** @return array<string,mixed> */
    private function getUserPackageContext(int $userId): array
    {
        return [
            'direct_subscription' => $userId > 0 ? $this->getDirectSubscriptionSummary($userId) : null,
            'group_plans' => $userId > 0 ? $this->getGroupPlanSummaries($userId) : [],
            'default_plan' => $this->getConfiguredDefaultPlanSummary(),
        ];
    }

    /** @return array<string,string>|null */
    private function getDirectSubscriptionSummary(int $userId): ?array
    {
        try {
            $row = $this->db->get_row(
                "SELECT us.status, us.billing_cycle, us.end_date, us.next_billing_date, sp.name AS plan_name
                   FROM {$this->prefix}user_subscriptions us
                   INNER JOIN {$this->prefix}subscription_plans sp ON sp.id = us.plan_id
                  WHERE us.user_id = ?
                    AND us.status IN ('active', 'trial')
                    AND (us.end_date IS NULL OR us.end_date > NOW())
                  ORDER BY us.created_at DESC
                  LIMIT 1",
                [$userId]
            );
        } catch (\Throwable) {
            return null;
        }

        if (!$row) {
            return null;
        }

        return [
            'label' => $this->safeUiText((string)($row->plan_name ?? 'Aktives Paket'), 120, 'Aktives Paket'),
            'status' => $this->safeUiText((string)($row->status ?? 'active'), 30, 'active'),
            'billing_cycle' => $this->safeUiText((string)($row->billing_cycle ?? ''), 30),
            'due_at' => $this->safeUiText((string)(($row->next_billing_date ?? '') ?: ($row->end_date ?? '')), 40),
        ];
    }

    /** @return array<int,string> */
    private function getGroupPlanSummaries(int $userId): array
    {
        try {
            $rows = $this->db->get_results(
                "SELECT ug.name AS group_name, ug.is_active, sp.name AS plan_name
                   FROM {$this->prefix}user_group_members ugm
                   INNER JOIN {$this->prefix}user_groups ug ON ug.id = ugm.group_id
                   LEFT JOIN {$this->prefix}subscription_plans sp ON sp.id = ug.plan_id
                  WHERE ugm.user_id = ?
                  ORDER BY ug.is_active DESC, ug.name ASC",
                [$userId]
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $summaries = [];
        foreach ($rows as $row) {
            $groupName = $this->safeUiText((string)($row->group_name ?? 'Gruppe'), 100, 'Gruppe');
            $planName = $this->safeUiText((string)($row->plan_name ?? ''), 100);
            $suffix = ((int)($row->is_active ?? 0) === 1) ? '' : ' (inaktiv)';
            $summaries[] = $planName !== ''
                ? $groupName . ' → ' . $planName . $suffix
                : $groupName . ' → kein Paket' . $suffix;
        }

        return $summaries;
    }

    /** @return array<string,string>|null */
    private function getConfiguredDefaultPlanSummary(): ?array
    {
        try {
            $planId = (int)($this->db->get_var(
                "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
                ['subscription_default_plan_id']
            ) ?? 0);

            if ($planId <= 0) {
                return null;
            }

            $row = $this->db->get_row(
                "SELECT name, slug FROM {$this->prefix}subscription_plans WHERE id = ? AND is_active = 1 LIMIT 1",
                [$planId]
            );
        } catch (\Throwable) {
            return null;
        }

        if (!$row) {
            return null;
        }

        return [
            'label' => $this->safeUiText((string)($row->name ?? 'Standardpaket'), 120, 'Standardpaket'),
            'slug' => $this->safeUiText((string)($row->slug ?? ''), 100),
        ];
    }

    /**
     * @param array<string,mixed> $packageContext
     * @return array<string,mixed>
     */
    private function buildRolePackageImpact(string $currentRole, string $targetRole, array $packageContext, bool $isExistingUser): array
    {
        $directSubscription = is_array($packageContext['direct_subscription'] ?? null) ? $packageContext['direct_subscription'] : null;
        $groupPlans = is_array($packageContext['group_plans'] ?? null) ? $packageContext['group_plans'] : [];
        $defaultPlan = is_array($packageContext['default_plan'] ?? null) ? $packageContext['default_plan'] : null;
        $currentPackage = $directSubscription['label'] ?? 'Kein direktes aktives Paket';

        if (!$isExistingUser) {
            if ($targetRole === 'member' && $defaultPlan !== null) {
                return [
                    'severity' => 'info',
                    'title' => 'Standardpaket bei Neuanlage',
                    'message' => 'Beim Erstellen mit Rolle „Mitglied“ wird automatisch das konfigurierte Standardpaket „' . $defaultPlan['label'] . '“ zugewiesen, sofern noch kein aktives Abo besteht.',
                    'current_package' => 'Neuer Benutzer',
                    'group_packages' => [],
                    'default_package' => $defaultPlan['label'],
                ];
            }

            return [
                'severity' => 'secondary',
                'title' => 'Keine automatische Paketzuweisung',
                'message' => $targetRole === 'member'
                    ? 'Für neue Mitglieder ist kein aktives Standardpaket konfiguriert oder verfügbar.'
                    : 'Automatische Standardpakete werden nur bei Neuanlage mit Zielrolle „Mitglied“ vergeben.',
                'current_package' => 'Neuer Benutzer',
                'group_packages' => [],
                'default_package' => $defaultPlan['label'] ?? '',
            ];
        }

        $message = 'Der Rollenwechsel ändert bestehende direkte Abos, Gruppenpakete oder Laufzeiten nicht automatisch.';
        $severity = 'secondary';
        if ($currentRole !== $targetRole && $targetRole === 'member' && $directSubscription === null && $defaultPlan !== null) {
            $message = 'Beim Bearbeiten bestehender Benutzer wird kein Standardpaket nachträglich automatisch vergeben. Falls gewünscht, Paket oder Gruppe separat prüfen.';
            $severity = 'warning';
        } elseif ($currentRole !== $targetRole && $currentRole === 'member' && $targetRole !== 'member') {
            $message = 'Bestehende Mitgliedspakete bleiben trotz Rollenwechsel erhalten. Falls der Zugriff enden soll, Abo oder Gruppenzuordnung separat anpassen.';
            $severity = 'warning';
        }

        return [
            'severity' => $severity,
            'title' => 'Paketwirkung',
            'message' => $message,
            'current_package' => $currentPackage,
            'group_packages' => array_values(array_map('strval', $groupPlans)),
            'default_package' => $defaultPlan['label'] ?? '',
        ];
    }

    private function safeUiText(string $value, int $maxLength = 160, string $fallback = ''): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';
        if ($value === '') {
            $value = $fallback;
        }

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength)
            : substr($value, 0, $maxLength);
    }

    /**
     * Letzte sicherheitsrelevante Ereignisse zum Benutzerprofil.
     *
     * Die Profilanzeige ist bewusst read-only und fail-soft: Audit-Log-Fehler dürfen
     * weder die Benutzerverwaltung blockieren noch einen 500er auslösen.
     *
     * @return array{events:array<int,object>,unavailable:bool}
     */
    private function getUserSecurityEvents(object $user, int $limit = 10): array
    {
        $userId = (int) ($user->id ?? 0);
        if ($userId <= 0) {
            return ['events' => [], 'unavailable' => false];
        }

        $limit = max(1, min(20, $limit));
        $clauses = [
            'user_id = ?',
            '(entity_type = ? AND entity_id = ?)',
        ];
        $params = [
            $userId,
            'user',
            $userId,
        ];

        $metadataIdentityClauses = [];
        foreach ([$user->username ?? '', $user->email ?? ''] as $identity) {
            $identity = trim((string) $identity);
            if ($identity === '') {
                continue;
            }

            $metadataIdentityClauses[] = "(category = ? AND metadata LIKE ? ESCAPE '!')";
            $params[] = 'auth';
            $params[] = '%"username":"' . $this->escapeSqlLike($identity) . '"%';
        }

        if ($metadataIdentityClauses !== []) {
            $clauses[] = '(' . implode(' OR ', $metadataIdentityClauses) . ')';
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT id, user_id, category, action, entity_type, entity_id,
                        description, ip_address, severity, created_at
                   FROM {$this->prefix}audit_log
                  WHERE (" . implode(' OR ', $clauses) . ")
                    AND (category IN ('auth', 'security', 'user') OR action LIKE 'auth.%' OR action LIKE 'login_%')
                  ORDER BY created_at DESC
                  LIMIT {$limit}"
            );
            $stmt->execute($params);

            return [
                'events' => $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [],
                'unavailable' => false,
            ];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.users')->warning('Benutzer-Sicherheitsereignisse konnten nicht geladen werden.', [
                'exception' => $e::class,
                'user_id' => $userId,
            ]);

            return ['events' => [], 'unavailable' => true];
        }
    }

    private function escapeSqlLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    public function hasUser(int $id): bool
    {
        return $id > 0 && $this->userService->getUserById($id) !== null;
    }

    /**
     * Benutzer speichern
     */
    public function save(array $post): array
    {
        $id = (int)($post['id'] ?? 0);

        $data = [
            'username'   => $this->normalizeScalarText($post['username'] ?? '', 50),
            // Nicht auf die Spaltenbreite kürzen: zu lange Adressen meldet die Validierung.
            'email'      => $this->normalizeScalarText($post['email'] ?? '', 254),
            'role'       => $this->normalizeScalarText($post['role'] ?? 'member', 50),
            'status'     => $this->normalizeScalarText($post['status'] ?? 'active', 20),
            'first_name' => $this->normalizeScalarText($post['first_name'] ?? '', 120),
            'last_name'  => $this->normalizeScalarText($post['last_name'] ?? '', 120),
        ];

        $password = (string) ($post['password'] ?? '');
        if ($password !== '') {
            $data['password'] = $password;
        }

        if ($id > 0 && !empty($post['email_skip_confirmation'])) {
            $data['email_skip_confirmation'] = true;
        }

        // Selbstaussperrung verhindern (analog zur Bulk-Aktion).
        $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
        if ($id > 0 && $id === $currentUserId && ($data['role'] !== 'admin' || $data['status'] !== 'active')) {
            return [
                'success' => false,
                'error' => 'Das eigene Konto kann nicht herabgestuft, deaktiviert oder gesperrt werden.',
            ];
        }

        try {
            if ($id > 0) {
                $result = $this->userService->updateUser($id, $data);
                if ($result instanceof \CMS\WP_Error) {
                    return ErrorReportService::buildFailureResultFromWpError($result, [
                        'title' => 'Benutzer konnte nicht aktualisiert werden',
                        'source' => '/admin/users?action=edit&id=' . $id,
                        'module' => 'users',
                        'operation' => 'update',
                        'user_id' => $id,
                    ]);
                }
                if ($result !== true) {
                    return [
                        'success' => false,
                        'error' => 'Benutzer konnte nicht aktualisiert werden.',
                        'error_details' => [
                            'Die Benutzerverwaltung hat keine erfolgreiche Aktualisierung bestätigt.',
                            'Bitte Eingaben und Datenbank-Logs prüfen.',
                        ],
                        'report_payload' => [
                            'title' => 'Benutzer-Update ohne Erfolgsmeldung',
                            'message' => 'Die Aktualisierung lieferte kein `true`-Ergebnis zurück.',
                            'error_code' => 'users_update_unconfirmed',
                            'source_url' => $this->buildUserEditSourceUrl($id),
                            'context' => [
                                'module' => 'users',
                                'operation' => 'update',
                                'user_id' => $id,
                            ],
                        ],
                    ];
                }
                $pendingEmail = \CMS\Services\EmailChangeService::getInstance()->getPending($id);
                if ($pendingEmail !== null && strcasecmp($pendingEmail['email'], $data['email']) === 0) {
                    return [
                        'success' => true,
                        'id' => $id,
                        'message' => 'Benutzer aktualisiert. Die neue E-Mail-Adresse ' . $pendingEmail['email']
                            . ' wird erst übernommen, wenn der Link in der Bestätigungs-E-Mail geöffnet wird.',
                    ];
                }

                return ['success' => true, 'id' => $id, 'message' => 'Benutzer aktualisiert.'];
            } else {
                if (empty($data['password'])) {
                    return ['success' => false, 'error' => 'Passwort ist Pflichtfeld bei neuen Benutzern.'];
                }
                $result = $this->userService->createUser($data);
                if ($result instanceof \CMS\WP_Error) {
                    return ErrorReportService::buildFailureResultFromWpError($result, [
                        'title' => 'Benutzer konnte nicht erstellt werden',
                        'source' => '/admin/users?action=edit',
                        'module' => 'users',
                        'operation' => 'create',
                    ]);
                }
                if (!is_int($result) || $result <= 0) {
                    return [
                        'success' => false,
                        'error' => 'Benutzer konnte nicht erstellt werden.',
                        'error_details' => [
                            'Die Benutzerverwaltung hat keine gültige Benutzer-ID zurückgegeben.',
                            'Bitte Eingaben und Datenbank-Logs prüfen.',
                        ],
                        'report_payload' => [
                            'title' => 'Benutzer-Erstellung ohne ID',
                            'message' => 'Die Benutzer-Erstellung lieferte keine gültige Benutzer-ID zurück.',
                            'error_code' => 'users_create_missing_id',
                            'source_url' => $this->buildUserEditSourceUrl(),
                            'context' => [
                                'module' => 'users',
                                'operation' => 'create',
                                'username' => $data['username'],
                                'email' => $data['email'],
                            ],
                        ],
                    ];
                }
                return ['success' => true, 'id' => $result, 'message' => 'Benutzer erstellt.'];
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.users')->error('Benutzer konnte nicht gespeichert werden.', [
                'exception_class' => $e::class,
                'user_id' => $id,
                'payload_keys' => array_keys($post),
            ]);

            return [
                'success' => false,
                'error' => 'Benutzer konnte nicht gespeichert werden.',
                'error_details' => [
                    'Die Benutzerverwaltung hat den Speichervorgang wegen eines internen Fehlers abgebrochen.',
                    'Details wurden im Server-Log protokolliert.',
                ],
                'report_payload' => [
                    'title' => 'Benutzer konnte nicht gespeichert werden',
                    'message' => 'Die Benutzerverwaltung hat den Speichervorgang wegen eines internen Fehlers abgebrochen. Details wurden im Server-Log protokolliert.',
                    'error_code' => 'users_save_exception',
                    'source_url' => $this->buildUserEditSourceUrl($id),
                    'context' => [
                        'module' => 'users',
                        'operation' => $id > 0 ? 'update' : 'create',
                        'user_id' => $id,
                        'payload_keys' => array_keys($post),
                        'exception_class' => $e::class,
                    ],
                ],
            ];
        }
    }

    private function normalizeScalarText(mixed $value, int $maxLength): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        $normalized = trim((string) $value);
        $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', '', $normalized) ?? '';

        return function_exists('mb_substr')
            ? mb_substr($normalized, 0, $maxLength)
            : substr($normalized, 0, $maxLength);
    }

    /**
     * Benutzer löschen
     */
    public function deleteUser(int $id): array
    {
        try {
            $result = $this->userService->deleteUser($id, true);
            if ($result instanceof \CMS\WP_Error) {
                return ErrorReportService::buildFailureResultFromWpError($result, [
                    'title' => 'Benutzer konnte nicht gelöscht werden',
                    'source' => '/admin/users',
                    'module' => 'users',
                    'operation' => 'delete',
                    'user_id' => $id,
                ]);
            }

            if ($result !== true) {
                return [
                    'success' => false,
                    'error' => 'Benutzer konnte nicht gelöscht werden.',
                    'error_details' => [
                        'Die Benutzerverwaltung hat keine erfolgreiche Löschbestätigung erhalten.',
                        'Bitte Benutzerbestand und Datenbank-Logs prüfen.',
                    ],
                    'report_payload' => [
                        'title' => 'Benutzer-Löschung ohne Erfolgsmeldung',
                        'message' => 'Die Benutzer-Löschung lieferte kein `true`-Ergebnis zurück.',
                        'error_code' => 'users_delete_unconfirmed',
                        'source_url' => '/admin/users',
                        'context' => [
                            'module' => 'users',
                            'operation' => 'delete',
                            'user_id' => $id,
                        ],
                    ],
                ];
            }

            return ['success' => true, 'message' => 'Benutzer dauerhaft gelöscht.'];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.users')->error('Benutzer konnte nicht gelöscht werden.', [
                'exception_class' => $e::class,
                'user_id' => $id,
            ]);

            return [
                'success' => false,
                'error' => 'Benutzer konnte nicht gelöscht werden.',
                'error_details' => [
                    'Die Benutzerverwaltung hat den Löschvorgang wegen eines internen Fehlers abgebrochen.',
                    'Details wurden im Server-Log protokolliert.',
                ],
                'report_payload' => [
                    'title' => 'Benutzer konnte nicht gelöscht werden',
                    'message' => 'Die Benutzerverwaltung hat den Löschvorgang wegen eines internen Fehlers abgebrochen. Details wurden im Server-Log protokolliert.',
                    'error_code' => 'users_delete_exception',
                    'source_url' => '/admin/users',
                    'context' => [
                        'module' => 'users',
                        'operation' => 'delete',
                        'user_id' => $id,
                        'exception_class' => $e::class,
                    ],
                ],
            ];
        }
    }

    /**
     * Beendet alle Sitzungen und API-Tokens eines Benutzers (Folgeaudit OFFEN-23).
     *
     * @return array{success:bool,message?:string,error?:string}
     */
    public function revokeSessions(int $id): array
    {
        if (!\CMS\Auth::instance()->revokeAllSessions($id, 'admin')) {
            return ['success' => false, 'error' => 'Die Sitzungen konnten nicht beendet werden. Details stehen im Server-Log.'];
        }

        return ['success' => true, 'message' => 'Alle Sitzungen und API-Tokens des Benutzers wurden beendet.'];
    }

    public function cancelEmailChange(int $id): array
    {
        if (!\CMS\Services\EmailChangeService::getInstance()->cancel($id, \CMS\Services\EmailChangeService::INITIATOR_ADMIN)) {
            return ['success' => false, 'error' => 'Für diesen Benutzer gibt es keine ausstehende Änderung der E-Mail-Adresse.'];
        }

        return ['success' => true, 'message' => 'Die ausstehende Änderung der E-Mail-Adresse wurde verworfen.'];
    }

    private function buildUserEditSourceUrl(int $id = 0): string
    {
        return '/admin/users?action=' . ($id > 0 ? 'edit&id=' . $id : 'edit');
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    private function getExistingUserIds(array $ids): array
    {
        $normalizedIds = [];
        foreach ($ids as $id) {
            $normalizedId = (int) $id;
            if ($normalizedId > 0) {
                $normalizedIds[$normalizedId] = $normalizedId;
            }
        }

        if ($normalizedIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
        $rows = $this->db->get_col(
            "SELECT id FROM {$this->prefix}users WHERE id IN ({$placeholders})",
            array_values($normalizedIds)
        );

        $existingIds = array_map('intval', $rows);
        sort($existingIds, SORT_NUMERIC);

        return array_values(array_unique(array_filter($existingIds, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Bulk-Aktionen
     */
    public function bulkAction(string $action, array $ids): array
    {
        if (empty($ids)) {
            return ['success' => false, 'message' => 'Keine Benutzer ausgewählt.'];
        }

        $normalizedIds = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($normalizedIds === []) {
            return ['success' => false, 'message' => 'Keine Benutzer ausgewählt.'];
        }

        $existingIds = $this->getExistingUserIds($normalizedIds);
        if ($existingIds === []) {
            return ['success' => false, 'message' => 'Die ausgewählten Benutzer existieren nicht mehr.'];
        }

        if (count($existingIds) !== count($normalizedIds)) {
            return [
                'success' => false,
                'message' => 'Mindestens ein ausgewählter Benutzer existiert nicht mehr.',
            ];
        }

        $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
        if ($currentUserId > 0 && in_array($currentUserId, $existingIds, true)) {
            return [
                'success' => false,
                'message' => 'Der eigene Benutzer darf nicht per Bulk-Aktion verändert werden.',
            ];
        }

        $result = $this->userService->bulkAction($action, $existingIds);
        $successCount = (int) ($result['success'] ?? 0);
        $failedCount = (int) ($result['failed'] ?? 0);
        $message = $successCount . ' Benutzer verarbeitet.' . ($failedCount > 0 ? ' ' . $failedCount . ' fehlgeschlagen.' : '');

        return [
            'success' => $failedCount === 0 && $successCount > 0,
            'message' => $message,
            'error_details' => $failedCount > 0 && is_array($result['errors'] ?? null)
                ? array_values($result['errors'])
                : [],
        ];
    }
}
