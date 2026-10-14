<?php
/**
 * Subscription Manager
 * 
 * Verwaltet Abo-Pakete, Benutzer-Zuweisungen und Limits
 * 
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

class SubscriptionManager
{
    private static ?self $instance = null;
    private Database $db;
    private array $cache = [];

    /** @var array<string, string> */
    private array $settingsCache = [];
    
    /**
     * Singleton instance
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->db = Database::instance();
        // Only create tables when not already verified
        $flagFile = ABSPATH . 'cache/db_schema_subscriptions.flag';
        if (!file_exists($flagFile)) {
            $this->createTables();
            $cacheDir = ABSPATH . 'cache/';
            if (!is_dir($cacheDir)) {
                if (!mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                    error_log('SubscriptionManager: Cache-Verzeichnis konnte nicht erstellt werden: ' . $cacheDir);
                }
            }
            if (file_put_contents($flagFile, date('Y-m-d H:i:s')) === false) {
                error_log('SubscriptionManager: Flag-Datei konnte nicht geschrieben werden: ' . $flagFile);
            }
        }
    }
    
    /**
     * Erstellt Datenbank-Tabellen
     */
    public function createTables(): void
    {
        $prefix = $this->db->getPrefix();
        
        // Subscription Plans
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$prefix}subscription_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                description TEXT,
                price_monthly DECIMAL(10,2) DEFAULT 0.00,
                price_yearly DECIMAL(10,2) DEFAULT 0.00,
                
                -- Feature Limits
                limit_experts INT DEFAULT -1 COMMENT '-1 = unlimited, 0 = disabled',
                limit_companies INT DEFAULT -1,
                limit_events INT DEFAULT -1,
                limit_speakers INT DEFAULT -1,
                limit_storage_mb INT DEFAULT 1000,
                
                -- Plugin Access
                plugin_experts BOOLEAN DEFAULT 1,
                plugin_companies BOOLEAN DEFAULT 1,
                plugin_events BOOLEAN DEFAULT 1,
                plugin_speakers BOOLEAN DEFAULT 1,
                
                -- Premium Features
                feature_analytics BOOLEAN DEFAULT 0,
                feature_advanced_search BOOLEAN DEFAULT 0,
                feature_api_access BOOLEAN DEFAULT 0,
                feature_custom_branding BOOLEAN DEFAULT 0,
                feature_priority_support BOOLEAN DEFAULT 0,
                feature_export_data BOOLEAN DEFAULT 0,
                feature_integrations BOOLEAN DEFAULT 0,
                feature_custom_domains BOOLEAN DEFAULT 0,
                
                -- Meta
                is_active BOOLEAN DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // User Subscriptions
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$prefix}user_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                plan_id INT NOT NULL,
                
                -- Billing
                status ENUM('active', 'cancelled', 'expired', 'trial', 'suspended') DEFAULT 'active',
                billing_cycle ENUM('monthly', 'yearly', 'lifetime') DEFAULT 'monthly',
                
                -- Dates
                start_date DATETIME NOT NULL,
                end_date DATETIME,
                next_billing_date DATETIME,
                cancelled_at DATETIME,
                
                -- Tracking
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (plan_id) REFERENCES {$prefix}subscription_plans(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_dates (start_date, end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // User Groups
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$prefix}user_groups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                description TEXT,
                plan_id INT,
                is_active BOOLEAN DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (plan_id) REFERENCES {$prefix}subscription_plans(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // User-Group Assignments
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$prefix}user_group_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                group_id INT NOT NULL,
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (group_id) REFERENCES {$prefix}user_groups(id) ON DELETE CASCADE,
                UNIQUE KEY unique_user_group (user_id, group_id),
                INDEX idx_group_id (group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Usage Tracking
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$prefix}subscription_usage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                resource_type VARCHAR(50) NOT NULL COMMENT 'experts, companies, events, speakers, storage',
                current_count INT DEFAULT 0,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                UNIQUE KEY unique_user_resource (user_id, resource_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    
    /**
     * Holt aktives Abo eines Benutzers
     */
    public function getUserSubscription(int $userId): ?object
    {
        $cacheKey = 'user_subscription_' . $userId;
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        $stmt = $this->db->prepare("
            SELECT us.*, sp.*,
                   us.id as subscription_id,
                   sp.id as plan_id
            FROM {$this->db->prefix()}user_subscriptions us
            JOIN {$this->db->prefix()}subscription_plans sp ON us.plan_id = sp.id
            WHERE us.user_id = ?
              AND us.status = 'active'
              AND (us.end_date IS NULL OR us.end_date > NOW())
            ORDER BY us.created_at DESC
            LIMIT 1
        ");
        
        $stmt->execute([$userId]);
        $subscription = $stmt->fetch();
        
        // Fallback: Check Group Subscription
        if (!$subscription) {
            $subscription = $this->getUserGroupSubscription($userId);
        }
        
        // Fallback: Free Plan
        if (!$subscription) {
            $subscription = $this->getFreePlan();
        }
        
        $this->cache[$cacheKey] = $subscription ?: null;
        return $this->cache[$cacheKey];
    }

    /**
     * Prüft, ob die systemweite Limit-/Abo-Durchsetzung aktiv ist.
     */
    public function isLimitEnforcementEnabled(): bool
    {
        if (class_exists('\CMS\Services\CoreModuleService')) {
            return \CMS\Services\CoreModuleService::getInstance()->isModuleEnabled('subscription_limits');
        }

        return $this->getSetting('subscription_limits_enabled', '1') === '1';
    }
    
    /**
     * Holt Abo durch Gruppen-Mitgliedschaft
     */
    private function getUserGroupSubscription(int $userId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT ug.*, sp.*,
                   ug.id as group_id,
                   sp.id as plan_id
            FROM {$this->db->getPrefix()}user_group_members ugm
            JOIN {$this->db->getPrefix()}user_groups ug ON ugm.group_id = ug.id
            JOIN {$this->db->getPrefix()}subscription_plans sp ON ug.plan_id = sp.id
            WHERE ugm.user_id = ?
              AND ug.is_active = 1
              AND sp.is_active = 1
            ORDER BY sp.price_monthly DESC
            LIMIT 1
        ");
        
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Holt Free Plan
     */
    private function getFreePlan(): ?object
    {
        // Deterministisch: zuerst ein aktiver Plan mit Slug „free“, sonst der
        // günstigste aktive Plan (bisher: erster Datensatz ohne Sortierung).
        $stmt = $this->db->query("
            SELECT * FROM {$this->db->getPrefix()}subscription_plans
            WHERE is_active = 1
            ORDER BY (slug = 'free') DESC, price_monthly ASC, sort_order ASC, id ASC
            LIMIT 1
        ");
        
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Prüft ob Benutzer Zugriff auf Plugin hat
     */
    public function canAccessPlugin(int $userId, string $pluginSlug): bool
    {
        if (!$this->isLimitEnforcementEnabled()) {
            return true;
        }

        $subscription = $this->getUserSubscription($userId);
        
        if (!$subscription) {
            return false;
        }
        
        $fieldName = 'plugin_' . str_replace('-', '_', $pluginSlug);
        
        return !empty($subscription->{$fieldName});
    }
    
    /**
     * Prüft ob Limit erreicht ist
     */
    public function checkLimit(int $userId, string $resourceType): bool
    {
        if (!$this->isLimitEnforcementEnabled()) {
            return true;
        }

        $subscription = $this->getUserSubscription($userId);
        
        if (!$subscription) {
            return false;
        }
        
        $limitKey = str_replace('-', '_', $resourceType);
        // Spalte heißt limit_storage_mb – Ressourcenname „storage“ darauf abbilden.
        if ($limitKey === 'storage') {
            $limitKey = 'storage_mb';
        }
        $limitField = 'limit_' . $limitKey;
        $limit = (int) ($subscription->{$limitField} ?? 0);
        
        // -1 = unlimited
        if ($limit === -1) {
            return true;
        }
        
        // 0 = disabled
        if ($limit === 0) {
            return false;
        }
        
        // Check current usage
        $currentCount = $this->getCurrentUsage($userId, $resourceType);
        
        return $currentCount < $limit;
    }

    /**
     * Liest ein Setting aus der DB mit kleinem Runtime-Cache.
     */
    private function getSetting(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $this->settingsCache)) {
            return $this->settingsCache[$key];
        }

        $this->settingsCache[$key] = Services\OptionStore::getInstance()->get($key) ?? $default;

        return $this->settingsCache[$key];
    }

    private function getIntSetting(string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, (int) $this->getSetting($key, (string) $default)));
    }

    /**
     * @return array{warning_days:int,auto_renewal_enabled:bool,grace_period_days:int,notification_email_configured:bool}
     */
    public function getRenewalSettings(): array
    {
        return [
            'warning_days' => $this->getIntSetting('notification_before_expiry', 7, 0, 365),
            'auto_renewal_enabled' => $this->getSetting('auto_renewal', '1') === '1',
            'grace_period_days' => $this->getIntSetting('grace_period_days', 3, 0, 365),
            'notification_email_configured' => trim($this->getSetting('notification_email', '')) !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSubscriptionData(object|array|null $subscription): array
    {
        if (is_object($subscription)) {
            $subscription = (array) $subscription;
        }

        if (!is_array($subscription)) {
            return [];
        }

        return $subscription;
    }

    private function parseDateValue(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveSubscriptionDueDate(array $subscription): ?\DateTimeImmutable
    {
        $candidates = [
            (string) ($subscription['next_billing_date'] ?? ''),
            (string) ($subscription['end_date'] ?? ''),
            (string) ($subscription['expires_at'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $parsed = $this->parseDateValue($candidate);
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed;
            }
        }

        return null;
    }

    private function calculateDayDifference(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $from->setTime(0, 0)->diff($to->setTime(0, 0))->format('%r%a');
    }

    private function formatDueLabel(\DateTimeImmutable $dueAt): string
    {
        return $dueAt->format('d.m.Y H:i');
    }

    /**
     * @param array<string, mixed> $notice
     */
    private function buildRenewalAdminSummary(array $notice): string
    {
        $daysUntilDue = isset($notice['days_until_due']) ? (int) $notice['days_until_due'] : null;
        $isAutoRenewal = !empty($notice['is_auto_renewal']);

        if ($daysUntilDue === null) {
            return '';
        }

        if ($daysUntilDue < 0) {
            $days = abs($daysUntilDue);
            return $isAutoRenewal
                ? 'Verlängerung seit ' . $days . ' Tag' . ($days === 1 ? '' : 'en') . ' fällig'
                : 'Seit ' . $days . ' Tag' . ($days === 1 ? '' : 'en') . ' abgelaufen';
        }

        if ($daysUntilDue === 0) {
            return $isAutoRenewal ? 'Verlängerung heute fällig' : 'Läuft heute aus';
        }

        return $isAutoRenewal
            ? 'Verlängerung in ' . $daysUntilDue . ' Tagen'
            : 'Läuft in ' . $daysUntilDue . ' Tagen aus';
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscriptionRenewalNotice(object|array|null $subscription): array
    {
        $settings = $this->getRenewalSettings();
        $data = $this->normalizeSubscriptionData($subscription);

        $defaults = [
            'has_notice' => false,
            'is_highlighted' => false,
            'is_overdue' => false,
            'is_due_today' => false,
            'kind' => 'none',
            'severity' => 'info',
            'title' => '',
            'message' => '',
            'due_at' => '',
            'due_label' => '',
            'days_until_due' => null,
            'warning_days' => $settings['warning_days'],
            'auto_renewal_enabled' => $settings['auto_renewal_enabled'],
            'grace_period_days' => $settings['grace_period_days'],
            'is_auto_renewal' => false,
            'status' => '',
        ];

        if ($data === []) {
            return $defaults;
        }

        $dueAt = $this->resolveSubscriptionDueDate($data);
        if (!$dueAt instanceof \DateTimeImmutable) {
            return $defaults;
        }

        $now = new \DateTimeImmutable('now');
        $daysUntilDue = $this->calculateDayDifference($now, $dueAt);
        $hasNextBillingDate = trim((string) ($data['next_billing_date'] ?? '')) !== '';
        $billingCycle = strtolower(trim((string) ($data['billing_cycle'] ?? '')));
        $status = strtolower(trim((string) ($data['status'] ?? '')));
        $isAutoRenewal = $settings['auto_renewal_enabled'] && $hasNextBillingDate && $billingCycle !== 'lifetime';
        $isOverdue = $daysUntilDue < 0;
        $isDueToday = $daysUntilDue === 0;
        $isHighlighted = $isOverdue || $isDueToday || ($settings['warning_days'] > 0 && $daysUntilDue > 0 && $daysUntilDue <= $settings['warning_days']);
        $dueLabel = $this->formatDueLabel($dueAt);

        $title = $isAutoRenewal ? 'Nächste Verlängerung' : 'Aktive Laufzeit';
        $message = $isAutoRenewal
            ? 'Die nächste Verlängerung ist am ' . $dueLabel . ' vorgesehen.'
            : 'Die aktuelle Laufzeit endet am ' . $dueLabel . '.';
        $kind = $isAutoRenewal ? 'renewal_scheduled' : 'active_until';
        $severity = 'info';

        if ($isOverdue) {
            $kind = $isAutoRenewal ? 'renewal_overdue' : 'expired';
            $severity = 'danger';
            $title = $isAutoRenewal ? 'Verlängerung überfällig' : 'Abo abgelaufen';
            $message = $isAutoRenewal
                ? 'Die nächste Verlängerung war für ' . $dueLabel . ' vorgesehen. Zahlungs- und Vertragsstatus prüfen.'
                : 'Die Laufzeit endete am ' . $dueLabel . '.';

            if (!$isAutoRenewal && $settings['grace_period_days'] > 0) {
                $message .= ' Konfigurierte Kulanzzeit: ' . $settings['grace_period_days'] . ' Tage.';
            }
        } elseif ($isDueToday) {
            $kind = $isAutoRenewal ? 'renewal_due' : 'expires_today';
            $severity = 'warning';
            $title = $isAutoRenewal ? 'Verlängerung heute fällig' : 'Abo läuft heute aus';
            $message = $isAutoRenewal
                ? 'Die nächste Verlängerung ist heute fällig.'
                : 'Die aktuelle Laufzeit endet heute.';
        } elseif ($isHighlighted) {
            $kind = $isAutoRenewal ? 'renewal_soon' : 'expiring_soon';
            $severity = 'warning';
            $title = $isAutoRenewal ? 'Verlängerung bald fällig' : 'Abo läuft bald aus';
            $message = $isAutoRenewal
                ? 'Die nächste Verlängerung ist in ' . $daysUntilDue . ' Tagen fällig.'
                : 'Die aktuelle Laufzeit endet in ' . $daysUntilDue . ' Tagen.';
        }

        return [
            'has_notice' => true,
            'is_highlighted' => $isHighlighted,
            'is_overdue' => $isOverdue,
            'is_due_today' => $isDueToday,
            'kind' => $kind,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'due_label' => $dueLabel,
            'days_until_due' => $daysUntilDue,
            'warning_days' => $settings['warning_days'],
            'auto_renewal_enabled' => $settings['auto_renewal_enabled'],
            'grace_period_days' => $settings['grace_period_days'],
            'is_auto_renewal' => $isAutoRenewal,
            'status' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRenewalOverview(int $limit = 10): array
    {
        $settings = $this->getRenewalSettings();
        $limit = max(1, min(25, $limit));
        $now = new \DateTimeImmutable('now');
        $cutoff = $now->modify('+' . $settings['warning_days'] . ' days');
        $defaultOverview = [
            'warning_days' => $settings['warning_days'],
            'auto_renewal_enabled' => $settings['auto_renewal_enabled'],
            'grace_period_days' => $settings['grace_period_days'],
            'counts' => [
                'total' => 0,
                'overdue' => 0,
                'upcoming' => 0,
            ],
            'items' => [],
            'basis_note' => $settings['warning_days'] > 0
                ? 'Die Hinweise basieren auf dem globalen Ablaufhinweis von ' . $settings['warning_days'] . ' Tagen. Bei aktiver Auto-Verlängerung wird das Fälligkeitsdatum als Renewal-Termin interpretiert.'
                : 'Die Hinweise zeigen nur heute fällige oder bereits überfällige Abos. Bei aktiver Auto-Verlängerung wird das Fälligkeitsdatum als Renewal-Termin interpretiert.',
        ];

        try {
            $countRow = $this->db->get_row(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN COALESCE(us.next_billing_date, us.end_date) < ? THEN 1 ELSE 0 END) AS overdue
                 FROM {$this->db->getPrefix()}user_subscriptions us
                 WHERE us.status IN ('active', 'trial')
                   AND COALESCE(us.next_billing_date, us.end_date) IS NOT NULL
                   AND COALESCE(us.next_billing_date, us.end_date) <= ?",
                [$now->format('Y-m-d H:i:s'), $cutoff->format('Y-m-d H:i:s')]
            );

            $rows = $this->db->get_results(
                "SELECT us.id AS subscription_id,
                        us.user_id,
                        us.plan_id,
                        us.status,
                        us.billing_cycle,
                        us.start_date,
                        us.end_date,
                        us.next_billing_date,
                        us.cancelled_at,
                        u.username,
                        u.email,
                        sp.name AS plan_name
                 FROM {$this->db->getPrefix()}user_subscriptions us
                 LEFT JOIN {$this->db->getPrefix()}users u ON us.user_id = u.id
                 LEFT JOIN {$this->db->getPrefix()}subscription_plans sp ON us.plan_id = sp.id
                 WHERE us.status IN ('active', 'trial')
                   AND COALESCE(us.next_billing_date, us.end_date) IS NOT NULL
                   AND COALESCE(us.next_billing_date, us.end_date) <= ?
                 ORDER BY COALESCE(us.next_billing_date, us.end_date) ASC
                 LIMIT {$limit}",
                [$cutoff->format('Y-m-d H:i:s')]
            ) ?: [];
        } catch (\Throwable) {
            return $defaultOverview;
        }

        $total = (int) ($countRow->total ?? 0);
        $overdue = (int) ($countRow->overdue ?? 0);
        $items = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $notice = $this->getSubscriptionRenewalNotice($data);
            if (empty($notice['has_notice'])) {
                continue;
            }

            $items[] = [
                'subscription_id' => (int) ($data['subscription_id'] ?? 0),
                'user_id' => (int) ($data['user_id'] ?? 0),
                'username' => trim((string) ($data['username'] ?? '')),
                'email' => trim((string) ($data['email'] ?? '')),
                'plan_name' => trim((string) ($data['plan_name'] ?? '')),
                'status' => trim((string) ($data['status'] ?? 'active')),
                'billing_cycle' => trim((string) ($data['billing_cycle'] ?? 'monthly')),
                'due_at' => (string) ($notice['due_at'] ?? ''),
                'due_label' => (string) ($notice['due_label'] ?? ''),
                'days_until_due' => $notice['days_until_due'],
                'severity' => (string) ($notice['severity'] ?? 'info'),
                'kind' => (string) ($notice['kind'] ?? 'none'),
                'summary' => $this->buildRenewalAdminSummary($notice),
                'is_auto_renewal' => !empty($notice['is_auto_renewal']),
            ];
        }

        $defaultOverview['counts'] = [
            'total' => $total,
            'overdue' => $overdue,
            'upcoming' => max(0, $total - $overdue),
        ];
        $defaultOverview['items'] = $items;

        return $defaultOverview;
    }
    
    /**
     * Holt aktuelle Nutzung
     */
    public function getCurrentUsage(int $userId, string $resourceType): int
    {
        $stmt = $this->db->prepare("
            SELECT current_count
            FROM {$this->db->getPrefix()}subscription_usage
            WHERE user_id = ? AND resource_type = ?
        ");
        
        $stmt->execute([$userId, $resourceType]);
        $result = $stmt->fetch();
        
        return $result ? (int)$result->current_count : 0;
    }
    
    /**
     * Aktualisiert Nutzungszähler
     */
    public function updateUsage(int $userId, string $resourceType, int $count): bool
    {
        $existing = $this->db->prepare("
            SELECT id FROM {$this->db->getPrefix()}subscription_usage
            WHERE user_id = ? AND resource_type = ?
        ");
        $existing->execute([$userId, $resourceType]);
        
        if ($existing->fetch()) {
            return $this->db->update('subscription_usage', 
                ['current_count' => $count],
                ['user_id' => $userId, 'resource_type' => $resourceType]
            );
        } else {
            return $this->db->insert('subscription_usage', [
                'user_id' => $userId,
                'resource_type' => $resourceType,
                'current_count' => $count
            ]) > 0;
        }
    }
    
    /**
     * Weist Benutzer ein Abo zu
     */
    public function assignSubscription(int $userId, int $planId, string $billingCycle = 'monthly'): bool
    {
        // Deaktiviere alte Abos
        $this->db->update('user_subscriptions',
            ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')],
            ['user_id' => $userId, 'status' => 'active']
        );
        
        // Erstelle neues Abo
        $startDate = date('Y-m-d H:i:s');
        $endDate = null;
        $nextBilling = null;
        
        if ($billingCycle === 'monthly') {
            $nextBilling = date('Y-m-d H:i:s', strtotime('+1 month'));
            $endDate = $nextBilling;
        } elseif ($billingCycle === 'yearly') {
            $nextBilling = date('Y-m-d H:i:s', strtotime('+1 year'));
            $endDate = $nextBilling;
        }
        
        $result = $this->db->insert('user_subscriptions', [
            'user_id' => $userId,
            'plan_id' => $planId,
            'status' => 'active',
            'billing_cycle' => $billingCycle,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'next_billing_date' => $nextBilling
        ]);
        
        // Clear cache
        unset($this->cache['user_subscription_' . $userId]);
        
        Hooks::doAction('subscription_assigned', $userId, $planId);
        
        return $result > 0;
    }

    /**
     * Weist einem neuen Benutzer das konfigurierte Standardpaket zu, sofern eines aktiv hinterlegt ist.
     */
    public function assignConfiguredDefaultPlan(int $userId, string $billingCycle = 'monthly'): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $planId = (int) $this->getSetting('subscription_default_plan_id', '0');
        if ($planId <= 0) {
            return true;
        }

        $plan = $this->getPlan($planId);
        if ($plan === null || (int) ($plan->is_active ?? 0) !== 1) {
            return false;
        }

        $billingCycle = in_array($billingCycle, ['monthly', 'yearly', 'lifetime'], true)
            ? $billingCycle
            : 'monthly';

        $existing = $this->db->prepare("\n            SELECT id\n            FROM {$this->db->getPrefix()}user_subscriptions\n            WHERE user_id = ?\n              AND status IN ('active', 'trial')\n            LIMIT 1\n        ");
        $existing->execute([$userId]);

        if ($existing->fetch()) {
            return true;
        }

        return $this->assignSubscription($userId, $planId, $billingCycle);
    }
    
    /**
     * Holt alle Pläne
     */
    public function getAllPlans(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM {$this->db->getPrefix()}subscription_plans
            WHERE is_active = 1
            ORDER BY sort_order ASC, price_monthly ASC
        ");
        
        return $stmt->fetchAll() ?: [];
    }
    
    /**
     * Holt Plan nach ID
     */
    public function getPlan(int $planId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->db->getPrefix()}subscription_plans
            WHERE id = ?
        ");
        
        $stmt->execute([$planId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Erstellt Standard-Pakete
     */
    public function seedDefaultPlans(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Kostenloser Basis-Zugang mit eingeschränkten Features',
                'price_monthly' => 0.00,
                'price_yearly' => 0.00,
                'limit_experts' => 1,
                'limit_companies' => 1,
                'limit_events' => 5,
                'limit_speakers' => 1,
                'limit_storage_mb' => 100,
                'plugin_experts' => 1,
                'plugin_companies' => 0,
                'plugin_events' => 1,
                'plugin_speakers' => 0,
                'feature_analytics' => 0,
                'feature_advanced_search' => 0,
                'feature_api_access' => 0,
                'feature_custom_branding' => 0,
                'feature_priority_support' => 0,
                'feature_export_data' => 0,
                'feature_integrations' => 0,
                'feature_custom_domains' => 0,
                'sort_order' => 1
            ],
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'description' => 'Ideal für Einzelpersonen und kleine Projekte',
                'price_monthly' => 9.99,
                'price_yearly' => 99.00,
                'limit_experts' => 5,
                'limit_companies' => 3,
                'limit_events' => 20,
                'limit_speakers' => 5,
                'limit_storage_mb' => 500,
                'plugin_experts' => 1,
                'plugin_companies' => 1,
                'plugin_events' => 1,
                'plugin_speakers' => 1,
                'feature_analytics' => 0,
                'feature_advanced_search' => 1,
                'feature_api_access' => 0,
                'feature_custom_branding' => 0,
                'feature_priority_support' => 0,
                'feature_export_data' => 1,
                'feature_integrations' => 0,
                'feature_custom_domains' => 0,
                'sort_order' => 2
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'Für professionelle Anwender und wachsende Teams',
                'price_monthly' => 29.99,
                'price_yearly' => 299.00,
                'limit_experts' => 20,
                'limit_companies' => 10,
                'limit_events' => 100,
                'limit_speakers' => 20,
                'limit_storage_mb' => 2000,
                'plugin_experts' => 1,
                'plugin_companies' => 1,
                'plugin_events' => 1,
                'plugin_speakers' => 1,
                'feature_analytics' => 1,
                'feature_advanced_search' => 1,
                'feature_api_access' => 1,
                'feature_custom_branding' => 1,
                'feature_priority_support' => 0,
                'feature_export_data' => 1,
                'feature_integrations' => 1,
                'feature_custom_domains' => 0,
                'sort_order' => 3
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'Für Unternehmen mit erweiterten Anforderungen',
                'price_monthly' => 79.99,
                'price_yearly' => 799.00,
                'limit_experts' => 100,
                'limit_companies' => 50,
                'limit_events' => 500,
                'limit_speakers' => 100,
                'limit_storage_mb' => 10000,
                'plugin_experts' => 1,
                'plugin_companies' => 1,
                'plugin_events' => 1,
                'plugin_speakers' => 1,
                'feature_analytics' => 1,
                'feature_advanced_search' => 1,
                'feature_api_access' => 1,
                'feature_custom_branding' => 1,
                'feature_priority_support' => 1,
                'feature_export_data' => 1,
                'feature_integrations' => 1,
                'feature_custom_domains' => 1,
                'sort_order' => 4
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'description' => 'Premium-Paket mit allen Features und hohen Limits',
                'price_monthly' => 149.99,
                'price_yearly' => 1499.00,
                'limit_experts' => 500,
                'limit_companies' => 200,
                'limit_events' => 2000,
                'limit_speakers' => 500,
                'limit_storage_mb' => 50000,
                'plugin_experts' => 1,
                'plugin_companies' => 1,
                'plugin_events' => 1,
                'plugin_speakers' => 1,
                'feature_analytics' => 1,
                'feature_advanced_search' => 1,
                'feature_api_access' => 1,
                'feature_custom_branding' => 1,
                'feature_priority_support' => 1,
                'feature_export_data' => 1,
                'feature_integrations' => 1,
                'feature_custom_domains' => 1,
                'sort_order' => 5
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Unbegrenzte Möglichkeiten für große Organisationen',
                'price_monthly' => 499.99,
                'price_yearly' => 4999.00,
                'limit_experts' => -1,
                'limit_companies' => -1,
                'limit_events' => -1,
                'limit_speakers' => -1,
                'limit_storage_mb' => 200000,
                'plugin_experts' => 1,
                'plugin_companies' => 1,
                'plugin_events' => 1,
                'plugin_speakers' => 1,
                'feature_analytics' => 1,
                'feature_advanced_search' => 1,
                'feature_api_access' => 1,
                'feature_custom_branding' => 1,
                'feature_priority_support' => 1,
                'feature_export_data' => 1,
                'feature_integrations' => 1,
                'feature_custom_domains' => 1,
                'sort_order' => 6
            ]
        ];
        
        foreach ($plans as $plan) {
            // Check if exists
            $stmt = $this->db->prepare("SELECT id FROM {$this->db->getPrefix()}subscription_plans WHERE slug = ?");
            $stmt->execute([$plan['slug']]);
            
            if (!$stmt->fetch()) {
                $this->db->insert('subscription_plans', $plan);
            }
        }
    }
}
