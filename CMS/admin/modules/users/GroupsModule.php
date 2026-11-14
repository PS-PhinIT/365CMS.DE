<?php
declare(strict_types=1);

/**
 * Groups Module – Business-Logik für Benutzergruppen
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\AuditLogger;
use CMS\Logger;

class GroupsModule
{
    private Database $db;
    private string $prefix;

    /** @var string[] */
    private const ALLOWED_BULK_ACTIONS = ['activate', 'deactivate', 'delete', 'set_plan', 'clear_plan'];

    public function __construct()
    {
        $this->db     = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    /**
     * Alle Gruppen mit Mitgliederzahl laden
     */
    public function getData(): array
    {
        $groups = $this->db->get_results(
            "SELECT g.*, sp.name AS plan_name, sp.slug AS plan_slug,
                    (
                        SELECT COUNT(*)
                        FROM {$this->prefix}user_group_members ugm
                        WHERE ugm.group_id = g.id
                    ) AS member_count
             FROM {$this->prefix}user_groups g
             LEFT JOIN {$this->prefix}subscription_plans sp ON sp.id = g.plan_id
             ORDER BY g.is_active DESC, g.name ASC"
        ) ?: [];

        $groupIds = array_values(array_filter(array_map(
            static fn(object $group): int => (int)($group->id ?? 0),
            $groups
        ), static fn(int $groupId): bool => $groupId > 0));
        $expiringContractsByGroup = $this->loadExpiringContractsByGroup($groupIds);
        $planIds = array_values(array_unique(array_filter(array_map(
            static fn(object $group): int => (int)($group->plan_id ?? 0),
            $groups
        ), static fn(int $planId): bool => $planId > 0)));
        $planModulesById = $this->loadPlanModulesById($planIds);

        $memberRows = $this->db->get_results(
            "SELECT ugm.group_id, u.id, u.username, u.display_name, u.email, u.status
             FROM {$this->prefix}user_group_members ugm
             INNER JOIN {$this->prefix}users u ON u.id = ugm.user_id
             ORDER BY u.username ASC"
        ) ?: [];

        $membersByGroup = [];
        foreach ($memberRows as $row) {
            $groupId = (int)($row->group_id ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $membersByGroup[$groupId][] = [
                'id' => (int)($row->id ?? 0),
                'username' => (string)($row->username ?? ''),
                'display_name' => (string)($row->display_name ?? ''),
                'email' => (string)($row->email ?? ''),
                'status' => (string)($row->status ?? ''),
            ];
        }

        $normalizedGroups = [];
        foreach ($groups as $group) {
            $groupId = (int)($group->id ?? 0);
            $members = $membersByGroup[$groupId] ?? [];
            $group->slug = (string)($group->slug ?? '');
            $group->is_active = (int)($group->is_active ?? 1);
            $group->members = $members;
            $group->member_ids = array_values(array_map(static fn (array $member): int => (int)$member['id'], $members));
            $planId = (int)($group->plan_id ?? 0);
            $group->support_context = $this->buildGroupSupportContext($group, $expiringContractsByGroup[$groupId] ?? [], $planModulesById[$planId] ?? []);
            $normalizedGroups[] = $group;
        }

        return [
            'groups' => $normalizedGroups,
            'userOptions' => $this->getUserOptions(),
            'planOptions' => $this->getPlanOptions(),
        ];
    }

    /**
     * @param array<int,int> $groupIds
     * @return array<int,array<int,array<string,string>>>
     */
    private function loadExpiringContractsByGroup(array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn(int $id): bool => $id > 0)));
        if ($groupIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($groupIds), '?'));
        $cutoff = (new \DateTimeImmutable('today'))->modify('+14 days')->format('Y-m-d 23:59:59');

        try {
            $rows = $this->db->get_results(
                "SELECT ugm.group_id, us.user_id, us.status, us.end_date, us.next_billing_date,
                        u.username, u.display_name, sp.name AS plan_name
                   FROM {$this->prefix}user_group_members ugm
                   INNER JOIN {$this->prefix}user_subscriptions us ON us.user_id = ugm.user_id
                   INNER JOIN {$this->prefix}users u ON u.id = ugm.user_id
                   LEFT JOIN {$this->prefix}subscription_plans sp ON sp.id = us.plan_id
                  WHERE ugm.group_id IN ({$placeholders})
                    AND us.status IN ('active', 'trial')
                    AND COALESCE(us.next_billing_date, us.end_date) IS NOT NULL
                    AND COALESCE(us.next_billing_date, us.end_date) <= ?
                  ORDER BY COALESCE(us.next_billing_date, us.end_date) ASC",
                array_merge($groupIds, [$cutoff])
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $byGroup = [];
        foreach ($rows as $row) {
            $groupId = (int)($row->group_id ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $dueAt = $this->normalizeScalarText((string)(($row->next_billing_date ?? '') ?: ($row->end_date ?? '')), 40);
            $state = $this->buildContractState($dueAt);
            $displayName = $this->normalizeScalarText((string)($row->display_name ?? ''), 80);
            if ($displayName === '') {
                $displayName = $this->normalizeScalarText((string)($row->username ?? 'Benutzer'), 80);
            }

            $byGroup[$groupId][] = [
                'user_label' => $displayName !== '' ? $displayName : 'Benutzer',
                'plan_label' => $this->normalizeScalarText((string)($row->plan_name ?? 'Paket'), 80),
                'due_at' => $dueAt,
                'label' => $state['label'],
                'severity' => $state['severity'],
            ];
        }

        return $byGroup;
    }

    /**
     * @param array<int,array<string,string>> $expiringContracts
     * @param array<int,string> $planModules
     * @return array<string,mixed>
     */
    private function buildGroupSupportContext(object $group, array $expiringContracts, array $planModules): array
    {
        $planName = $this->normalizeScalarText((string)($group->plan_name ?? ''), 120);
        $memberAreaModules = $this->getGlobalMemberAreaLabels();
        $overdueCount = 0;

        foreach ($expiringContracts as $contract) {
            if (($contract['severity'] ?? '') === 'danger') {
                $overdueCount++;
            }
        }

        return [
            'plan_label' => $planName,
            'plan_modules' => array_slice($planModules, 0, 5),
            'plan_module_count' => count($planModules),
            'member_modules' => array_slice($memberAreaModules, 0, 5),
            'member_module_count' => count($memberAreaModules),
            'expiring_contracts' => array_slice($expiringContracts, 0, 3),
            'expiring_contract_count' => count($expiringContracts),
            'overdue_contract_count' => $overdueCount,
        ];
    }

    /**
     * @param array<int,int> $planIds
     * @return array<int,array<int,string>>
     */
    private function loadPlanModulesById(array $planIds): array
    {
        $planIds = array_values(array_unique(array_filter(array_map('intval', $planIds), static fn(int $id): bool => $id > 0)));
        if ($planIds === []) {
            return [];
        }

        $map = [
            'plugin_experts' => 'Experten',
            'plugin_companies' => 'Companies',
            'plugin_events' => 'Events',
            'plugin_speakers' => 'Speaker',
            'feature_analytics' => 'Analytics',
            'feature_api_access' => 'API-Zugriff',
            'feature_priority_support' => 'Priority Support',
            'feature_export_data' => 'Datenexport',
            'feature_integrations' => 'Integrationen',
            'feature_custom_branding' => 'Branding',
        ];

        $availableColumns = $this->getExistingColumns('subscription_plans', array_keys($map));
        if ($availableColumns === []) {
            return [];
        }

        $selectColumns = array_values(array_intersect(array_keys($map), $availableColumns));
        $placeholders = implode(', ', array_fill(0, count($planIds), '?'));

        try {
            $rows = $this->db->get_results(
                "SELECT id, " . implode(', ', $selectColumns) . "
                   FROM {$this->prefix}subscription_plans
                  WHERE id IN ({$placeholders})",
                $planIds
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $modulesById = [];
        foreach ($rows as $row) {
            $planId = (int)($row->id ?? 0);
            if ($planId <= 0) {
                continue;
            }

            foreach ($selectColumns as $field) {
                if (!empty($row->{$field})) {
                    $modulesById[$planId][] = $map[$field];
                }
            }
        }

        return $modulesById;
    }

    /**
     * @param array<int,string> $candidates
     * @return array<int,string>
     */
    private function getExistingColumns(string $table, array $candidates): array
    {
        $candidateLookup = array_fill_keys($candidates, true);
        if ($candidateLookup === []) {
            return [];
        }

        try {
            $rows = $this->db->get_results("SHOW COLUMNS FROM {$this->prefix}{$table}") ?: [];
        } catch (\Throwable) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            $field = (string)($row->Field ?? '');
            if ($field !== '' && isset($candidateLookup[$field])) {
                $columns[] = $field;
            }
        }

        return $columns;
    }

    /** @return array<int,string> */
    private function getGlobalMemberAreaLabels(): array
    {
        $labels = ['Profil', 'Sicherheit', 'Benachrichtigungen', 'Nachrichten', 'Dateien', 'Favoriten', 'Datenschutz'];

        if ($this->isSettingEnabled('member_dashboard_enabled', true)
            && $this->isCoreModuleEnabled('member_dashboard', true)) {
            array_unshift($labels, 'Dashboard');
        }

        if ($this->isSettingEnabled('member_subscription_visible', true)
            && $this->isCoreModuleEnabled('subscription_member_area', true)) {
            $labels[] = 'Abo & Bestellungen';
        }

        return array_values(array_unique($labels));
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

        return ['label' => 'Fällig in ' . $days . ' Tag' . ($days === 1 ? '' : 'en'), 'severity' => 'warning'];
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

    private function groupExists(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $count = $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}user_groups WHERE id = ?",
            [$id]
        );

        return $count !== null && (int) $count > 0;
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    private function getExistingGroupIds(array $ids): array
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
            "SELECT id FROM {$this->prefix}user_groups WHERE id IN ({$placeholders})",
            array_values($normalizedIds)
        );

        $existingIds = array_map('intval', $rows);
        sort($existingIds, SORT_NUMERIC);

        return array_values(array_unique(array_filter($existingIds, static fn (int $id): bool => $id > 0)));
    }

    private function getUserOptions(): array
    {
        $rows = $this->db->get_results(
            "SELECT id, username, display_name, email, status
             FROM {$this->prefix}users
             ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, username ASC"
        ) ?: [];

        return array_map(static function (object $row): array {
            return [
                'id' => (int)($row->id ?? 0),
                'username' => (string)($row->username ?? ''),
                'display_name' => (string)($row->display_name ?? ''),
                'email' => (string)($row->email ?? ''),
                'status' => (string)($row->status ?? 'inactive'),
            ];
        }, $rows);
    }

    private function getPlanOptions(): array
    {
        try {
            $rows = $this->db->get_results(
                "SELECT id, name, slug, is_active
                 FROM {$this->prefix}subscription_plans
                 ORDER BY is_active DESC, sort_order ASC, name ASC"
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }

        return array_map(static function (object $row): array {
            return [
                'id' => (int)($row->id ?? 0),
                'name' => (string)($row->name ?? ''),
                'slug' => (string)($row->slug ?? ''),
                'is_active' => (int)($row->is_active ?? 0),
            ];
        }, $rows);
    }

    private function sanitizeExistingPlanId(mixed $value): ?int
    {
        $planId = (int)$value;
        if ($planId <= 0) {
            return null;
        }

        try {
            $exists = (int)$this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}subscription_plans WHERE id = ?",
                [$planId]
            );
        } catch (\Throwable) {
            return null;
        }

        return $exists > 0 ? $planId : null;
    }

    private function normalizeSlug(mixed $value, string $fallback): string
    {
        $slug = $this->normalizeScalarText($value, 100);
        if ($slug === '') {
            $slug = $fallback;
        }

        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
        // user_groups.slug ist VARCHAR(100)
        $slug = trim(substr(trim($slug, '-'), 0, 100), '-');

        return $slug !== '' ? $slug : 'gruppe';
    }

    private function ensureUniqueSlug(string $slug, int $groupId = 0): string
    {
        $baseSlug = $slug;
        $suffix = 2;

        while ($this->slugExists($slug, $groupId)) {
            // Basis kürzen, damit Slug samt Zähler in VARCHAR(100) passt
            $suffixText = '-' . $suffix;
            $slug = rtrim(substr($baseSlug, 0, 100 - strlen($suffixText)), '-') . $suffixText;
            $suffix++;
        }

        return $slug;
    }

    private function slugExists(string $slug, int $groupId = 0): bool
    {
        if ($groupId > 0) {
            $count = $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}user_groups WHERE slug = ? AND id != ?",
                [$slug, $groupId]
            );
        } else {
            $count = $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}user_groups WHERE slug = ?",
                [$slug]
            );
        }

        return (int)$count > 0;
    }

    private function normalizeMemberIds(mixed $value): array
    {
        $ids = is_array($value) ? $value : [];
        $normalizedIds = [];

        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $normalizedIds[$id] = $id;
            }
        }

        if ($normalizedIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
        $rows = $this->db->get_results(
            "SELECT id FROM {$this->prefix}users WHERE id IN ({$placeholders})",
            array_values($normalizedIds)
        ) ?: [];

        return array_values(array_map(static fn (object $row): int => (int)$row->id, $rows));
    }

    private function syncGroupMembers(int $groupId, array $memberIds): void
    {
        $this->db->execute("DELETE FROM {$this->prefix}user_group_members WHERE group_id = ?", [$groupId]);

        foreach ($memberIds as $memberId) {
            $this->db->execute(
                "INSERT INTO {$this->prefix}user_group_members (user_id, group_id, joined_at) VALUES (?, ?, NOW())",
                [$memberId, $groupId]
            );
        }
    }

    /**
     * Gruppe erstellen oder aktualisieren
     */
    public function save(array $post): array
    {
        $id          = (int)($post['id'] ?? 0);
        $name        = $this->normalizeScalarText($post['name'] ?? '', 100);
        $slug        = $this->normalizeSlug($post['slug'] ?? '', $name);
        $description = $this->normalizeScalarText($post['description'] ?? '', 500);
        $memberIds   = $this->normalizeMemberIds($post['member_ids'] ?? []);
        $isActive    = !empty($post['is_active']) ? 1 : 0;
        $planId      = $this->sanitizeExistingPlanId($post['plan_id'] ?? 0);

        if ($name === '') {
            return ['success' => false, 'error' => 'Gruppenname darf nicht leer sein.'];
        }

        $slug = $this->ensureUniqueSlug($slug, $id);

        if ($id > 0 && !$this->groupExists($id)) {
            return ['success' => false, 'error' => 'Die angeforderte Gruppe existiert nicht mehr.'];
        }

        try {
            $pdo = $this->db->getPdo();
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            if ($id > 0) {
                $this->db->execute(
                    "UPDATE {$this->prefix}user_groups SET name = ?, slug = ?, description = ?, plan_id = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
                    [$name, $slug, $description, $planId, $isActive, $id]
                );
            } else {
                $this->db->execute(
                    "INSERT INTO {$this->prefix}user_groups (name, slug, description, plan_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                    [$name, $slug, $description, $planId, $isActive]
                );
                $id = (int)$pdo->lastInsertId();
            }

            $this->syncGroupMembers($id, $memberIds);

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_USER,
                !empty($post['id']) ? 'groups.update' : 'groups.create',
                !empty($post['id']) ? 'Benutzergruppe aktualisiert.' : 'Benutzergruppe erstellt.',
                'user_group',
                $id,
                [
                    'slug' => $slug,
                    'member_count' => count($memberIds),
                    'plan_id' => $planId,
                    'is_active' => $isActive,
                ],
                'info'
            );

            $memberLabel = count($memberIds) === 1 ? '1 Mitglied' : count($memberIds) . ' Mitglieder';
            $planLabel = $planId !== null ? ' Paket verknüpft.' : ' Kein Paket verknüpft.';

            return [
                'success' => true,
                'message' => $post['id'] ?? false
                    ? 'Gruppe aktualisiert · ' . $memberLabel . ' zugeordnet.' . $planLabel
                    : 'Gruppe erstellt · ' . $memberLabel . ' zugeordnet.' . $planLabel,
            ];
        } catch (\Throwable $e) {
            $pdo = $this->db->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::instance()->withChannel('admin.users.groups')->error('Benutzergruppe konnte nicht gespeichert werden.', [
                'group_id' => $id,
                'exception' => $e,
            ]);

            return ['success' => false, 'error' => 'Die Gruppe konnte nicht gespeichert werden.'];
        }
    }

    /**
     * Gruppe löschen
     */
    public function delete(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige Gruppen-ID.'];
        }

        if (!$this->groupExists($id)) {
            return ['success' => false, 'error' => 'Die angeforderte Gruppe existiert nicht mehr.'];
        }

        try {
            $pdo = $this->db->getPdo();
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            $this->db->execute("DELETE FROM {$this->prefix}user_group_members WHERE group_id = ?", [$id]);
            $deleteGroupStatement = $this->db->execute("DELETE FROM {$this->prefix}user_groups WHERE id = ? LIMIT 1", [$id]);

            if ($deleteGroupStatement->rowCount() < 1) {
                throw new \RuntimeException('Die Gruppen-Löschung konnte nicht bestätigt werden.');
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_USER,
                'groups.delete',
                'Benutzergruppe gelöscht.',
                'user_group',
                $id,
                [],
                'warning'
            );

            return ['success' => true, 'message' => 'Gruppe gelöscht.'];
        } catch (\Throwable $e) {
            $pdo = $this->db->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::instance()->withChannel('admin.users.groups')->error('Benutzergruppe konnte nicht gelöscht werden.', [
                'group_id' => $id,
                'exception' => $e,
            ]);

            return ['success' => false, 'error' => 'Die Gruppe konnte nicht gelöscht werden.'];
        }
    }

    public function bulkAction(string $action, array $ids, array $payload = []): array
    {
        $action = trim($action);
        if (!in_array($action, self::ALLOWED_BULK_ACTIONS, true)) {
            return ['success' => false, 'error' => 'Unbekannte Bulk-Aktion für Gruppen.'];
        }

        $normalizedIds = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($normalizedIds === []) {
            return ['success' => false, 'error' => 'Bitte mindestens eine Gruppe auswählen.'];
        }

        $existingIds = $this->getExistingGroupIds($normalizedIds);
        if ($existingIds === []) {
            return ['success' => false, 'error' => 'Die ausgewählten Gruppen existieren nicht mehr.'];
        }

        if (count($existingIds) !== count($normalizedIds)) {
            return ['success' => false, 'error' => 'Mindestens eine ausgewählte Gruppe existiert nicht mehr. Bitte Liste neu laden.'];
        }

        $placeholders = implode(', ', array_fill(0, count($existingIds), '?'));
        $processedCount = count($existingIds);

        try {
            switch ($action) {
                case 'activate':
                case 'deactivate':
                    $isActive = $action === 'activate' ? 1 : 0;
                    $params = array_merge([$isActive], $existingIds);
                    $this->db->execute(
                        "UPDATE {$this->prefix}user_groups SET is_active = ?, updated_at = NOW() WHERE id IN ({$placeholders})",
                        $params
                    );

                    $label = $isActive === 1 ? 'aktiviert' : 'deaktiviert';
                    AuditLogger::instance()->log(
                        AuditLogger::CAT_USER,
                        'groups.bulk.' . $action,
                        $processedCount . ' Benutzergruppe(n) ' . $label . '.',
                        'user_group',
                        null,
                        ['group_ids' => $existingIds, 'processed_count' => $processedCount, 'is_active' => $isActive],
                        'info'
                    );

                    return ['success' => true, 'message' => $processedCount . ' Gruppe(n) ' . $label . '.'];

                case 'set_plan':
                    $planId = $this->sanitizeExistingPlanId($payload['bulk_plan_id'] ?? 0);
                    if ($planId === null) {
                        return ['success' => false, 'error' => 'Bitte ein gültiges Paket für die Sammelaktion auswählen.'];
                    }

                    $params = array_merge([$planId], $existingIds);
                    $this->db->execute(
                        "UPDATE {$this->prefix}user_groups SET plan_id = ?, updated_at = NOW() WHERE id IN ({$placeholders})",
                        $params
                    );

                    AuditLogger::instance()->log(
                        AuditLogger::CAT_USER,
                        'groups.bulk.set_plan',
                        $processedCount . ' Benutzergruppe(n) einem Paket zugewiesen.',
                        'user_group',
                        null,
                        ['group_ids' => $existingIds, 'processed_count' => $processedCount, 'plan_id' => $planId],
                        'info'
                    );

                    return ['success' => true, 'message' => $processedCount . ' Gruppe(n) einem Paket zugewiesen.'];

                case 'clear_plan':
                    $this->db->execute(
                        "UPDATE {$this->prefix}user_groups SET plan_id = NULL, updated_at = NOW() WHERE id IN ({$placeholders})",
                        $existingIds
                    );

                    AuditLogger::instance()->log(
                        AuditLogger::CAT_USER,
                        'groups.bulk.clear_plan',
                        $processedCount . ' Benutzergruppe(n) vom Paket gelöst.',
                        'user_group',
                        null,
                        ['group_ids' => $existingIds, 'processed_count' => $processedCount],
                        'info'
                    );

                    return ['success' => true, 'message' => $processedCount . ' Gruppe(n) vom Paket gelöst.'];

                case 'delete':
                    $pdo = $this->db->getPdo();
                    $startedTransaction = !$pdo->inTransaction();
                    if ($startedTransaction) {
                        $pdo->beginTransaction();
                    }

                    $this->db->execute(
                        "DELETE FROM {$this->prefix}user_group_members WHERE group_id IN ({$placeholders})",
                        $existingIds
                    );

                    $deleteStatement = $this->db->execute(
                        "DELETE FROM {$this->prefix}user_groups WHERE id IN ({$placeholders})",
                        $existingIds
                    );

                    if (!$deleteStatement || $deleteStatement->rowCount() < $processedCount) {
                        throw new \RuntimeException('Mindestens eine Gruppe konnte nicht gelöscht werden.');
                    }

                    if ($startedTransaction && $pdo->inTransaction()) {
                        $pdo->commit();
                    }

                    AuditLogger::instance()->log(
                        AuditLogger::CAT_USER,
                        'groups.bulk.delete',
                        $processedCount . ' Benutzergruppe(n) gelöscht.',
                        'user_group',
                        null,
                        ['group_ids' => $existingIds, 'processed_count' => $processedCount],
                        'warning'
                    );

                    return ['success' => true, 'message' => $processedCount . ' Gruppe(n) gelöscht.'];
            }
        } catch (\Throwable $e) {
            $pdo = $this->db->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::instance()->withChannel('admin.users.groups')->error('Bulk-Aktion für Benutzergruppen fehlgeschlagen.', [
                'bulk_action' => $action,
                'group_ids' => $existingIds,
                'exception' => $e,
            ]);

            return ['success' => false, 'error' => 'Die Sammelaktion für Gruppen konnte nicht abgeschlossen werden.'];
        }

        return ['success' => false, 'error' => 'Unbekannte Bulk-Aktion für Gruppen.'];
    }
}
