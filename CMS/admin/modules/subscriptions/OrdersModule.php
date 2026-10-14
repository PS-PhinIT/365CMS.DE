<?php
declare(strict_types=1);

/**
 * OrdersModule – Bestellungen verwalten
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Logger;

final class OrdersDashboardData
{
    public function __construct(
        private array $orders,
        private array $stats,
        private string $filter,
        private array $assignments,
        private array $plans,
        private array $users,
        private array $renewalInsights,
        private array $history,
    ) {
    }

    public function toArray(): array
    {
        return [
            'orders' => $this->orders,
            'stats' => $this->stats,
            'filter' => $this->filter,
            'assignments' => $this->assignments,
            'plans' => $this->plans,
            'users' => $this->users,
            'renewal_insights' => $this->renewalInsights,
            'history' => $this->history,
        ];
    }
}

final class OrdersActionResult
{
    private function __construct(
        private bool $success,
        private string $message,
    ) {
    }

    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    public function toArray(): array
    {
        return $this->success
            ? ['success' => true, 'message' => $this->message]
            : ['success' => false, 'error' => $this->message];
    }
}

class OrdersModule
{
    private const string REQUIRED_CAPABILITY = 'manage_settings';
    private const array ALLOWED_STATUSES = ['pending', 'paid', 'cancelled', 'refunded', 'failed'];
    private const array STATUS_ALIASES = [
        'confirmed' => 'paid',
        'completed' => 'paid',
    ];
    private const array STATUS_TRANSITIONS = [
        'pending' => ['paid', 'cancelled', 'failed'],
        'paid' => ['refunded', 'cancelled'],
        'failed' => ['pending', 'paid', 'cancelled'],
        'cancelled' => ['pending'],
        'refunded' => [],
    ];
    private const array ALLOWED_BILLING_CYCLES = ['monthly', 'yearly', 'lifetime'];
    private const int MAX_ORDERS = 200;
    private const int MAX_ASSIGNMENTS = 150;
    private const int MAX_USERS = 500;
    private const int MAX_EXPORT_ROWS = 5000;
    private const int MAX_HISTORY_EVENTS = 30;
    private const string CSV_DELIMITER = ';';
    private const array USAGE_LIMIT_FIELDS = [
        'experts' => 'limit_experts',
        'companies' => 'limit_companies',
        'events' => 'limit_events',
        'speakers' => 'limit_speakers',
        'storage' => 'limit_storage_mb',
    ];
    private const array USAGE_RESOURCE_LABELS = [
        'experts' => 'Experten',
        'companies' => 'Unternehmen',
        'events' => 'Events',
        'speakers' => 'Speaker',
        'storage' => 'Speicher',
    ];

    private readonly \CMS\Database $db;
    private readonly string $prefix;

    public function __construct()
    {
        $this->db     = \CMS\Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $table = $this->prefix . 'orders';
        $exists = $this->db->tableExists($table);
        if (!$exists) {
            $this->db->getPdo()->exec("CREATE TABLE {$table} (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_number  VARCHAR(50) NOT NULL,
                user_id       INT UNSIGNED DEFAULT NULL,
                plan_id       INT UNSIGNED DEFAULT NULL,
                customer_name VARCHAR(200) DEFAULT NULL,
                customer_email VARCHAR(200) DEFAULT NULL,
                amount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                tax_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                currency      VARCHAR(3) NOT NULL DEFAULT 'EUR',
                status        VARCHAR(20) NOT NULL DEFAULT 'pending',
                payment_method VARCHAR(30) DEFAULT NULL,
                billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
                payment_ref   VARCHAR(200) DEFAULT NULL,
                notes         TEXT DEFAULT NULL,
                contact_data  LONGTEXT DEFAULT NULL,
                forename      VARCHAR(100) DEFAULT NULL,
                lastname      VARCHAR(100) DEFAULT NULL,
                company       VARCHAR(100) DEFAULT NULL,
                email         VARCHAR(200) DEFAULT NULL,
                phone         VARCHAR(50) DEFAULT NULL,
                street        VARCHAR(255) DEFAULT NULL,
                zip           VARCHAR(20) DEFAULT NULL,
                city          VARCHAR(100) DEFAULT NULL,
                country       VARCHAR(100) DEFAULT NULL,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $this->ensureColumn($table, 'plan_id', "ALTER TABLE {$table} ADD COLUMN plan_id INT UNSIGNED DEFAULT NULL AFTER user_id");
        $this->ensureColumn($table, 'customer_name', "ALTER TABLE {$table} ADD COLUMN customer_name VARCHAR(200) DEFAULT NULL AFTER plan_id");
        $this->ensureColumn($table, 'customer_email', "ALTER TABLE {$table} ADD COLUMN customer_email VARCHAR(200) DEFAULT NULL AFTER customer_name");
        $this->ensureColumn($table, 'amount', "ALTER TABLE {$table} ADD COLUMN amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER customer_email");
        $this->ensureColumn($table, 'tax_amount', "ALTER TABLE {$table} ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER amount");
        $this->ensureColumn($table, 'total_amount', "ALTER TABLE {$table} ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_amount");
        $this->ensureColumn($table, 'currency', "ALTER TABLE {$table} ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'EUR' AFTER total_amount");
        $this->ensureColumn($table, 'payment_method', "ALTER TABLE {$table} ADD COLUMN payment_method VARCHAR(30) DEFAULT NULL AFTER status");
        $this->ensureColumn($table, 'billing_cycle', "ALTER TABLE {$table} ADD COLUMN billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly' AFTER payment_method");
        $this->ensureColumn($table, 'payment_ref', "ALTER TABLE {$table} ADD COLUMN payment_ref VARCHAR(200) DEFAULT NULL AFTER billing_cycle");
        $this->ensureColumn($table, 'notes', "ALTER TABLE {$table} ADD COLUMN notes TEXT DEFAULT NULL AFTER payment_ref");
        $this->ensureColumn($table, 'contact_data', "ALTER TABLE {$table} ADD COLUMN contact_data LONGTEXT DEFAULT NULL AFTER notes");
        $this->ensureColumn($table, 'forename', "ALTER TABLE {$table} ADD COLUMN forename VARCHAR(100) DEFAULT NULL AFTER contact_data");
        $this->ensureColumn($table, 'lastname', "ALTER TABLE {$table} ADD COLUMN lastname VARCHAR(100) DEFAULT NULL AFTER forename");
        $this->ensureColumn($table, 'company', "ALTER TABLE {$table} ADD COLUMN company VARCHAR(100) DEFAULT NULL AFTER lastname");
        $this->ensureColumn($table, 'email', "ALTER TABLE {$table} ADD COLUMN email VARCHAR(200) DEFAULT NULL AFTER company");
        $this->ensureColumn($table, 'phone', "ALTER TABLE {$table} ADD COLUMN phone VARCHAR(50) DEFAULT NULL AFTER email");
        $this->ensureColumn($table, 'street', "ALTER TABLE {$table} ADD COLUMN street VARCHAR(255) DEFAULT NULL AFTER phone");
        $this->ensureColumn($table, 'zip', "ALTER TABLE {$table} ADD COLUMN zip VARCHAR(20) DEFAULT NULL AFTER street");
        $this->ensureColumn($table, 'city', "ALTER TABLE {$table} ADD COLUMN city VARCHAR(100) DEFAULT NULL AFTER zip");
        $this->ensureColumn($table, 'country', "ALTER TABLE {$table} ADD COLUMN country VARCHAR(100) DEFAULT NULL AFTER city");
        $this->ensureColumn($table, 'updated_at', "ALTER TABLE {$table} ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");

        try {
            $this->db->getPdo()->exec("ALTER TABLE {$table} MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'");
        } catch (\Throwable) {
        }
    }

    private function ensureColumn(string $table, string $column, string $sql): void
    {
        if ($this->hasColumn($table, $column)) {
            return;
        }

        try {
            $this->db->getPdo()->exec($sql);
        } catch (\Throwable) {
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return $this->db->get_var("SHOW COLUMNS FROM {$table} LIKE '{$column}'") !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function getOrderPlanColumn(string $table): ?string
    {
        if ($this->hasColumn($table, 'plan_id')) {
            return 'plan_id';
        }

        if ($this->hasColumn($table, 'package_id')) {
            return 'package_id';
        }

        return null;
    }

    public function getData(string $statusFilter = ''): OrdersDashboardData
    {
        if (!$this->canAccess()) {
            return new OrdersDashboardData([], $this->defaultStats(), '', [], [], [], $this->defaultRenewalInsights(), $this->defaultHistoryState());
        }

        $table = $this->prefix . 'orders';
        $planColumn = $this->getOrderPlanColumn($table);
        $statusFilter = $this->normalizeStatus($statusFilter);
        $orders = $this->mapOrderRows($this->fetchOrders($table, $planColumn, $statusFilter));
        $assignments = $this->mapRows($this->fetchAssignments());

        return new OrdersDashboardData(
            $orders,
            $this->buildStats($table),
            $statusFilter,
            $assignments,
            $this->mapRows($this->fetchPlans()),
            $this->mapRows($this->fetchUsers()),
            $this->buildRenewalInsights(),
            $this->buildHistoryState($orders, $assignments),
        );
    }

    public function streamCsvExport(string $exportType, string $statusFilter = ''): never
    {
        if (!$this->canAccess()) {
            throw new \RuntimeException('Zugriff verweigert.');
        }

        $exportType = strtolower(trim($exportType));

        try {
            match ($exportType) {
                'orders' => $this->streamOrdersCsvExport($statusFilter),
                'usage' => $this->streamUsageCsvExport(),
                default => throw new \RuntimeException('Unbekannter Exporttyp.'),
            };
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Logger::instance()->error('Abo-Export konnte nicht erstellt werden.', [
                'module' => 'OrdersModule',
                'action' => 'orders.export_failed',
                'export_type' => $exportType,
                'status_filter' => $this->normalizeStatus($statusFilter),
                'exception' => $e::class,
            ]);

            AuditLogger::instance()->log(
                AuditLogger::CAT_SYSTEM,
                'orders.export.failed',
                'CSV-Export für Bestellungen oder Paketnutzung fehlgeschlagen.',
                'order',
                null,
                [
                    'export_type' => $exportType,
                    'format' => 'csv',
                    'status_filter' => $this->normalizeStatus($statusFilter),
                    'exception' => $e::class,
                ],
                'error'
            );

            throw new \RuntimeException('Der Export konnte nicht erstellt werden. Bitte Logs prüfen.');
        }
    }

    public function assignSubscription(int $userId, int $planId, string $billingCycle): OrdersActionResult
    {
        if (!$this->canAccess()) {
            return OrdersActionResult::failure('Zugriff verweigert.');
        }

        if ($userId <= 0 || $planId <= 0) {
            return OrdersActionResult::failure('Benutzer und Paket sind erforderlich.');
        }

        $billingCycle = $this->normalizeBillingCycle($billingCycle);

        try {
            $userExists = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}users WHERE id = ?", [$userId]);
            $planExists = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}subscription_plans WHERE id = ?", [$planId]);
            if ($userExists === 0 || $planExists === 0) {
                return OrdersActionResult::failure('Benutzer oder Paket wurde nicht gefunden.');
            }

            $success = \CMS\SubscriptionManager::instance()->assignSubscription($userId, $planId, $billingCycle);

            AuditLogger::instance()->log(
                AuditLogger::CAT_SYSTEM,
                $success ? 'subscription.assignment.created' : 'subscription.assignment.failed',
                $success
                    ? "Abonnement {$billingCycle} für Benutzer #{$userId} erstellt."
                    : "Abonnement {$billingCycle} für Benutzer #{$userId} konnte nicht erstellt werden.",
                'subscription',
                $planId,
                [
                    'user_id' => $userId,
                    'plan_id' => $planId,
                    'billing_cycle' => $billingCycle,
                ],
                $success ? 'info' : 'warning'
            );

            return $success
                ? OrdersActionResult::success('Paket wurde dem Benutzer zugewiesen.')
                : OrdersActionResult::failure('Die Zuweisung konnte nicht gespeichert werden.');
        } catch (\Throwable $e) {
            return $this->failResult('orders.assign_subscription_failed', 'Die Zuweisung konnte nicht gespeichert werden.', $e, [
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
            ]);
        }
    }

    public function updateStatus(int $id, string $status): OrdersActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        if ($id <= 0) {
            return $this->invalidIdResult();
        }

        $status = $this->normalizeStatus($status);
        if ($status === '') {
            return OrdersActionResult::failure('Ungültiger Status.');
        }

        try {
            $order = $this->requireOrderSnapshot($id);
            if ($order instanceof OrdersActionResult) {
                return $order;
            }

            $currentStatusRaw = strtolower(trim((string) ($order['status_raw'] ?? $order['status'] ?? '')));

            if (($order['status'] ?? '') === $status) {
                return OrdersActionResult::success('Status war bereits gesetzt.');
            }

            if (!$this->canTransitionStatus((string) ($order['status'] ?? ''), $status)) {
                return OrdersActionResult::failure('Dieser Statuswechsel ist für die Bestellung nicht zulässig.');
            }

            $statement = $this->db->execute(
                "UPDATE {$this->prefix}orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND LOWER(TRIM(status)) = ? LIMIT 1",
                [$status, $id, $currentStatusRaw]
            );

            if ($statement->rowCount() < 1) {
                return OrdersActionResult::failure('Bestellung wurde zwischenzeitlich geändert. Bitte Liste neu laden und erneut versuchen.');
            }

            if (!$statement) {
                return OrdersActionResult::failure('Status konnte nicht aktualisiert werden.');
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_SYSTEM,
                'order.status.updated',
                "Bestellstatus für Bestellung #{$id} geändert.",
                'order',
                $id,
                $this->buildOrderAuditContext($order, [
                    'from_status' => (string)($order['status'] ?? ''),
                    'to_status' => $status,
                ]),
                'info'
            );

            return OrdersActionResult::success('Status aktualisiert.');
        } catch (\Throwable $e) {
            return $this->failResult('orders.update_status_failed', 'Status konnte nicht aktualisiert werden.', $e, [
                'order_id' => $id,
                'status' => $status,
            ]);
        }
    }

    public function delete(int $id): OrdersActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        if ($id <= 0) {
            return $this->invalidIdResult();
        }

        try {
            $order = $this->requireOrderSnapshot($id);
            if ($order instanceof OrdersActionResult) {
                return $order;
            }

            $deleted = $this->db->delete('orders', ['id' => $id]);
            if (!$deleted) {
                return OrdersActionResult::failure('Bestellung konnte nicht gelöscht werden.');
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_SYSTEM,
                'order.deleted',
                "Bestellung #{$id} gelöscht.",
                'order',
                $id,
                $this->buildOrderAuditContext($order, [
                    'status' => (string)($order['status'] ?? ''),
                ]),
                'warning'
            );

            return OrdersActionResult::success('Bestellung gelöscht.');
        } catch (\Throwable $e) {
            return $this->failResult('orders.delete_failed', 'Bestellung konnte nicht gelöscht werden.', $e, [
                'order_id' => $id,
            ]);
        }
    }

    /**
     * @return list<object>
     */
    private function fetchOrders(string $table, ?string $planColumn, string $statusFilter): array
    {
        $where = '';
        $params = [];

        if ($statusFilter !== '') {
            $filterStatuses = $this->getDatabaseStatusesForFilter($statusFilter);
            $where = ' WHERE o.status IN (' . implode(', ', array_fill(0, count($filterStatuses), '?')) . ')';
            $params = $filterStatuses;
        }

        $planSelect = $planColumn !== null
            ? "o.{$planColumn} AS linked_plan_id, o.{$planColumn} AS plan_id, o.{$planColumn} AS package_id,"
            : "NULL AS linked_plan_id, NULL AS plan_id, NULL AS package_id,";
        $planJoin = $planColumn !== null
            ? "LEFT JOIN {$this->prefix}subscription_plans sp ON o.{$planColumn} = sp.id"
            : '';

        return $this->db->get_results(
            "SELECT o.*, {$planSelect} u.username, u.email as user_email, sp.name AS plan_name
             FROM {$table} o
             LEFT JOIN {$this->prefix}users u ON o.user_id = u.id
             {$planJoin}
             {$where}
             ORDER BY o.created_at DESC
             LIMIT " . self::MAX_ORDERS,
            $params
        ) ?: [];
    }

    /**
     * @return list<object>
     */
    private function fetchAssignments(): array
    {
        return $this->db->get_results(
            "SELECT us.*, u.username, u.email, sp.name AS plan_name
             FROM {$this->prefix}user_subscriptions us
             LEFT JOIN {$this->prefix}users u ON us.user_id = u.id
             LEFT JOIN {$this->prefix}subscription_plans sp ON us.plan_id = sp.id
             ORDER BY us.created_at DESC
               LIMIT " . self::MAX_ASSIGNMENTS
        ) ?: [];
    }

    /**
     * @return list<object>
     */
    private function fetchPlans(): array
    {
        return $this->db->get_results(
            "SELECT id, name, price_monthly, price_yearly, is_active FROM {$this->prefix}subscription_plans WHERE is_active = 1 ORDER BY sort_order ASC, price_monthly ASC"
        ) ?: [];
    }

    /**
     * @return list<object>
     */
    private function fetchUsers(): array
    {
        return $this->db->get_results(
            "SELECT id, username, email, display_name FROM {$this->prefix}users ORDER BY username ASC LIMIT " . self::MAX_USERS
        ) ?: [];
    }

    private function buildStats(string $table): array
    {
        $stats = $this->db->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN LOWER(TRIM(status)) IN ('paid', 'confirmed', 'completed') THEN 1 ELSE 0 END) AS paid,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'refunded' THEN 1 ELSE 0 END) AS refunded,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN LOWER(TRIM(status)) IN ('paid', 'confirmed', 'completed') THEN COALESCE(NULLIF(total_amount, 0), amount, 0) ELSE 0 END) AS revenue
             FROM {$table}"
        );

        return [
            'total'     => (int) ($stats->total ?? 0),
            'pending'   => (int) ($stats->pending ?? 0),
            'paid'      => (int) ($stats->paid ?? 0),
            'revenue'   => (float) ($stats->revenue ?? 0),
            'cancelled' => (int) ($stats->cancelled ?? 0),
            'refunded'  => (int) ($stats->refunded ?? 0),
            'failed'    => (int) ($stats->failed ?? 0),
        ];
    }

    private function defaultStats(): array
    {
        return [
            'total' => 0,
            'pending' => 0,
            'paid' => 0,
            'revenue' => 0.0,
            'cancelled' => 0,
            'refunded' => 0,
            'failed' => 0,
        ];
    }

    private function defaultRenewalInsights(): array
    {
        return [
            'warning_days' => 0,
            'auto_renewal_enabled' => false,
            'grace_period_days' => 0,
            'counts' => [
                'total' => 0,
                'overdue' => 0,
                'upcoming' => 0,
            ],
            'items' => [],
            'basis_note' => '',
        ];
    }

    private function defaultHistoryState(): array
    {
        return [
            'events' => [],
            'unavailable' => false,
        ];
    }

    /**
     * Baut eine kompakte, read-only Historie für sichtbare Bestellungen und Paketzuweisungen.
     *
     * Der Pfad nutzt ausschließlich das vorhandene Audit-Log, gibt keine rohen Metadaten aus
     * und fällt bei Altinstallationen ohne Audit-Tabelle fail-soft auf einen Hinweis zurück.
     *
     * @param list<array<string,mixed>> $orders
     * @param list<array<string,mixed>> $assignments
     * @return array{events:list<array<string,mixed>>,unavailable:bool}
     */
    private function buildHistoryState(array $orders, array $assignments): array
    {
        $orderIds = [];
        $orderLabels = [];
        $planIds = [];
        $planLabels = [];

        foreach ($orders as $order) {
            $orderId = (int) ($order['id'] ?? 0);
            if ($orderId > 0) {
                $orderIds[$orderId] = $orderId;
                $orderLabels[$orderId] = (string) ($order['order_number'] ?? ('#' . $orderId));
            }

            $planId = (int) ($order['linked_plan_id'] ?? $order['plan_id'] ?? 0);
            if ($planId > 0) {
                $planIds[$planId] = $planId;
                $planLabels[$planId] = (string) ($order['plan_name'] ?? ('Paket #' . $planId));
            }
        }

        foreach ($assignments as $assignment) {
            $planId = (int) ($assignment['plan_id'] ?? 0);
            if ($planId > 0) {
                $planIds[$planId] = $planId;
                $planLabels[$planId] = (string) ($assignment['plan_name'] ?? ('Paket #' . $planId));
            }
        }

        $clauses = [];
        $params = [];

        if ($orderIds !== []) {
            $clauses[] = "(entity_type = 'order' AND entity_id IN (" . implode(', ', array_fill(0, count($orderIds), '?')) . '))';
            array_push($params, ...array_values($orderIds));
        }

        if ($planIds !== []) {
            $clauses[] = "(entity_type = 'subscription' AND entity_id IN (" . implode(', ', array_fill(0, count($planIds), '?')) . "))";
            array_push($params, ...array_values($planIds));
        }

        $clauses[] = "action IN ('orders.export.csv', 'subscription.usage.export.csv', 'orders.export.failed')";

        try {
            $stmt = $this->db->prepare(
                "SELECT id, user_id, category, action, entity_type, entity_id, description, severity, metadata, created_at
                   FROM {$this->prefix}audit_log
                  WHERE (" . implode(' OR ', $clauses) . ")
                  ORDER BY created_at DESC, id DESC
                  LIMIT " . self::MAX_HISTORY_EVENTS
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            return [
                'events' => array_map(fn (array $row): array => $this->mapHistoryEvent($row, $orderLabels, $planLabels), $rows),
                'unavailable' => false,
            ];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.orders')->warning('Bestellhistorie konnte nicht geladen werden.', [
                'exception' => $e::class,
            ]);

            return ['events' => [], 'unavailable' => true];
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $orderLabels
     * @param array<int,string> $planLabels
     * @return array<string,mixed>
     */
    private function mapHistoryEvent(array $row, array $orderLabels, array $planLabels): array
    {
        $entityType = (string) ($row['entity_type'] ?? '');
        $entityId = (int) ($row['entity_id'] ?? 0);
        $metadata = $this->decodeHistoryMetadata((string) ($row['metadata'] ?? ''));
        $action = (string) ($row['action'] ?? '');

        $subject = 'Aboverwaltung';
        if ($entityType === 'order' && $entityId > 0) {
            $subject = 'Bestellung ' . ($orderLabels[$entityId] ?? ('#' . $entityId));
        } elseif ($entityType === 'subscription' && $entityId > 0) {
            $subject = $planLabels[$entityId] ?? ('Paket #' . $entityId);
        } elseif (isset($metadata['status_filter'])) {
            $subject = 'Export ' . ((string) $metadata['status_filter'] !== 'all' ? '(' . (string) $metadata['status_filter'] . ')' : '(alle)');
        }

        return [
            'action' => $this->labelHistoryAction($action),
            'subject' => $subject,
            'description' => (string) ($row['description'] ?? ''),
            'severity' => $this->normalizeHistorySeverity((string) ($row['severity'] ?? 'info')),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function decodeHistoryMetadata(string $metadata): array
    {
        if ($metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function labelHistoryAction(string $action): string
    {
        return match ($action) {
            'order.status.updated' => 'Status geändert',
            'order.deleted' => 'Bestellung gelöscht',
            'subscription.assignment.created' => 'Paket zugewiesen',
            'subscription.assignment.failed' => 'Zuweisung fehlgeschlagen',
            'orders.export.csv' => 'Orders exportiert',
            'subscription.usage.export.csv' => 'Paketnutzung exportiert',
            'orders.export.failed' => 'Export fehlgeschlagen',
            default => $action !== '' ? $action : 'Ereignis',
        };
    }

    private function normalizeHistorySeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));

        return in_array($severity, ['info', 'warning', 'error', 'critical'], true) ? $severity : 'info';
    }

    private function buildRenewalInsights(): array
    {
        if (!class_exists(\CMS\SubscriptionManager::class)) {
            return $this->defaultRenewalInsights();
        }

        try {
            $overview = \CMS\SubscriptionManager::instance()->getRenewalOverview(10);

            return is_array($overview) ? array_replace_recursive($this->defaultRenewalInsights(), $overview) : $this->defaultRenewalInsights();
        } catch (\Throwable) {
            return $this->defaultRenewalInsights();
        }
    }

    private function streamOrdersCsvExport(string $statusFilter): never
    {
        $statusFilter = $this->normalizeStatus($statusFilter);
        $rowCount = $this->countOrdersForExport($statusFilter);

        if ($rowCount > self::MAX_EXPORT_ROWS) {
            throw new \RuntimeException('Zu viele Bestellungen für einen einzelnen Export. Bitte Statusfilter verwenden.');
        }

        $rows = $this->fetchOrdersForExport($statusFilter);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'orders.export.csv',
            'Bestellungen wurden als CSV exportiert.',
            'order',
            null,
            [
                'format' => 'csv',
                'status_filter' => $statusFilter !== '' ? $statusFilter : 'all',
                'rows' => $rowCount,
            ],
            'info'
        );

        $this->sendCsvHeaders($this->buildExportFileName('orders' . ($statusFilter !== '' ? '-' . $statusFilter : '')));
        $output = $this->openCsvOutput();

        $this->writeCsvRow($output, [
            'Bestell-ID',
            'Bestellnummer',
            'Status',
            'Benutzer-ID',
            'Benutzername',
            'Kundenname',
            'Kunden-E-Mail',
            'Paket-ID',
            'Paketname',
            'Abrechnungsintervall',
            'Zahlungsmethode',
            'Betrag netto',
            'Steuer',
            'Betrag gesamt',
            'Währung',
            'Erstellt am',
            'Aktualisiert am',
        ]);

        foreach ($rows as $row) {
            $this->writeCsvRow($output, [
                (int) ($row['id'] ?? 0),
                (string) ($row['order_number'] ?? ''),
                (string) ($row['status'] ?? ''),
                isset($row['user_id']) ? (int) $row['user_id'] : '',
                (string) ($row['username'] ?? ''),
                (string) ($row['customer_name'] ?? ''),
                (string) ($row['customer_email'] ?? ''),
                isset($row['linked_plan_id']) ? (int) $row['linked_plan_id'] : (isset($row['plan_id']) ? (int) $row['plan_id'] : ''),
                (string) ($row['plan_name'] ?? ''),
                (string) ($row['billing_cycle'] ?? ''),
                (string) ($row['payment_method'] ?? ''),
                isset($row['amount']) ? number_format((float) $row['amount'], 2, '.', '') : '',
                isset($row['tax_amount']) ? number_format((float) $row['tax_amount'], 2, '.', '') : '',
                isset($row['total_amount']) ? number_format((float) $row['total_amount'], 2, '.', '') : '',
                (string) ($row['currency'] ?? 'EUR'),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['updated_at'] ?? ''),
            ]);
        }

        fclose($output);
        exit;
    }

    private function streamUsageCsvExport(): never
    {
        $rowCount = $this->countUsageRowsForExport();

        if ($rowCount > self::MAX_EXPORT_ROWS) {
            throw new \RuntimeException('Zu viele Nutzungsdatensätze für einen einzelnen Export. Bitte Bestand eingrenzen und erneut versuchen.');
        }

        $rows = $this->fetchUsageRowsForExport();

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'subscription.usage.export.csv',
            'Paketnutzung wurde als CSV exportiert.',
            'subscription',
            null,
            [
                'format' => 'csv',
                'rows' => $rowCount,
            ],
            'info'
        );

        $this->sendCsvHeaders($this->buildExportFileName('package-usage'));
        $output = $this->openCsvOutput();

        $this->writeCsvRow($output, [
            'Benutzer-ID',
            'Benutzername',
            'E-Mail',
            'Subscription-ID',
            'Subscription-Status',
            'Paket-ID',
            'Paketname',
            'Abrechnungsintervall',
            'Ressourcentyp',
            'Ressourcenlabel',
            'Aktuelle Nutzung',
            'Limitwert',
            'Verbleibend',
            'Limitstatus',
            'Zuletzt aktualisiert',
            'Startdatum',
            'Enddatum',
            'Nächste Verlängerung',
        ]);

        foreach ($rows as $row) {
            $limit = $this->resolveUsageLimit($row);
            $currentCount = (int) ($row['current_count'] ?? 0);

            $this->writeCsvRow($output, [
                (int) ($row['user_id'] ?? 0),
                (string) ($row['username'] ?? $row['display_name'] ?? ''),
                (string) ($row['email'] ?? ''),
                isset($row['subscription_id']) ? (int) $row['subscription_id'] : '',
                (string) ($row['subscription_status'] ?? ''),
                isset($row['plan_id']) ? (int) $row['plan_id'] : '',
                (string) ($row['plan_name'] ?? ''),
                (string) ($row['billing_cycle'] ?? ''),
                (string) ($row['resource_type'] ?? ''),
                $this->getUsageResourceLabel((string) ($row['resource_type'] ?? '')),
                $currentCount,
                $limit ?? '',
                $this->calculateUsageRemaining($limit, $currentCount),
                $this->describeUsageLimitState($limit, $currentCount),
                (string) ($row['last_updated'] ?? ''),
                (string) ($row['start_date'] ?? ''),
                (string) ($row['end_date'] ?? ''),
                (string) ($row['next_billing_date'] ?? ''),
            ]);
        }

        fclose($output);
        exit;
    }

    private function countOrdersForExport(string $statusFilter): int
    {
        $where = '';
        $params = [];

        if ($statusFilter !== '') {
            $statuses = $this->getDatabaseStatusesForFilter($statusFilter);
            $where = ' WHERE status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            $params = $statuses;
        }

        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}orders{$where}",
            $params
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchOrdersForExport(string $statusFilter): array
    {
        $table = $this->prefix . 'orders';
        $planColumn = $this->getOrderPlanColumn($table);
        $where = '';
        $params = [];

        if ($statusFilter !== '') {
            $statuses = $this->getDatabaseStatusesForFilter($statusFilter);
            $where = ' WHERE o.status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            $params = $statuses;
        }

        $planSelect = $planColumn !== null
            ? "o.{$planColumn} AS linked_plan_id, o.{$planColumn} AS plan_id, o.{$planColumn} AS package_id,"
            : "NULL AS linked_plan_id, NULL AS plan_id, NULL AS package_id,";
        $planJoin = $planColumn !== null
            ? "LEFT JOIN {$this->prefix}subscription_plans sp ON o.{$planColumn} = sp.id"
            : '';

        $rows = $this->db->get_results(
            "SELECT o.*, {$planSelect} u.username, u.email AS user_email, sp.name AS plan_name
             FROM {$table} o
             LEFT JOIN {$this->prefix}users u ON o.user_id = u.id
             {$planJoin}
             {$where}
             ORDER BY o.created_at DESC
             LIMIT " . self::MAX_EXPORT_ROWS,
            $params
        ) ?: [];

        return $this->mapOrderRows($rows);
    }

    private function countUsageRowsForExport(): int
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}subscription_usage");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchUsageRowsForExport(): array
    {
        $rows = $this->db->get_results(
            "SELECT su.user_id,
                    su.resource_type,
                    su.current_count,
                    su.last_updated,
                    u.username,
                    u.display_name,
                    u.email,
                    us.id AS subscription_id,
                    us.status AS subscription_status,
                    us.billing_cycle,
                    us.start_date,
                    us.end_date,
                    us.next_billing_date,
                    us.plan_id,
                    sp.name AS plan_name,
                    sp.limit_experts,
                    sp.limit_companies,
                    sp.limit_events,
                    sp.limit_speakers,
                    sp.limit_storage_mb
             FROM {$this->prefix}subscription_usage su
             LEFT JOIN {$this->prefix}users u ON u.id = su.user_id
             LEFT JOIN (
                 SELECT latest.*
                 FROM {$this->prefix}user_subscriptions latest
                 INNER JOIN (
                     SELECT user_id, MAX(id) AS latest_id
                     FROM {$this->prefix}user_subscriptions
                     WHERE status IN ('active', 'trial')
                     GROUP BY user_id
                 ) current_subscription ON current_subscription.latest_id = latest.id
             ) us ON us.user_id = su.user_id
             LEFT JOIN {$this->prefix}subscription_plans sp ON sp.id = us.plan_id
             ORDER BY su.user_id ASC, su.resource_type ASC
             LIMIT " . self::MAX_EXPORT_ROWS
        ) ?: [];

        return $this->mapRows($rows);
    }

    private function sendCsvHeaders(string $filename): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    /**
     * @return resource
     */
    private function openCsvOutput()
    {
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new \RuntimeException('CSV-Ausgabe konnte nicht initialisiert werden.');
        }

        fwrite($output, "\xEF\xBB\xBF");

        return $output;
    }

    /**
     * @param resource $output
     */
    private function writeCsvRow($output, array $row): void
    {
        $sanitizedRow = array_map(fn (mixed $value): string => $this->sanitizeCsvCell($value), $row);

        if (fputcsv($output, $sanitizedRow, self::CSV_DELIMITER, '"', '') === false) {
            throw new \RuntimeException('CSV-Datensatz konnte nicht geschrieben werden.');
        }
    }

    private function sanitizeCsvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);

        if (preg_match('/^\s*([=\+\-@\t\r\n]|[＝＋－＠])/u', $text) === 1) {
            $text = "\t" . $text;
        }

        return $text;
    }

    private function buildExportFileName(string $baseName): string
    {
        $baseName = strtolower(trim($baseName));
        $baseName = preg_replace('/[^a-z0-9_-]+/i', '-', $baseName) ?? 'export';
        $baseName = trim($baseName, '-_');

        if ($baseName === '') {
            $baseName = 'export';
        }

        return $baseName . '-' . date('Ymd-His') . '.csv';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveUsageLimit(array $row): ?int
    {
        $resourceType = strtolower(trim((string) ($row['resource_type'] ?? '')));
        $limitField = self::USAGE_LIMIT_FIELDS[$resourceType] ?? null;

        if ($limitField === null || !array_key_exists($limitField, $row) || $row[$limitField] === null || $row[$limitField] === '') {
            return null;
        }

        return (int) $row[$limitField];
    }

    private function getUsageResourceLabel(string $resourceType): string
    {
        $resourceType = strtolower(trim($resourceType));

        return self::USAGE_RESOURCE_LABELS[$resourceType] ?? $resourceType;
    }

    private function calculateUsageRemaining(?int $limit, int $currentCount): string
    {
        if ($limit === null || $limit < 0) {
            return '';
        }

        return (string) max(0, $limit - $currentCount);
    }

    private function describeUsageLimitState(?int $limit, int $currentCount): string
    {
        if ($limit === null) {
            return 'kein_aktives_paket';
        }

        if ($limit < 0) {
            return 'unbegrenzt';
        }

        if ($limit === 0) {
            return 'deaktiviert';
        }

        if ($currentCount > $limit) {
            return 'ueber_limit';
        }

        if ($currentCount === $limit) {
            return 'limit_erreicht';
        }

        return 'innerhalb_limit';
    }

    /**
     * @param list<object> $rows
     * @return list<array<string, mixed>>
     */
    private function mapRows(array $rows): array
    {
        return array_map(static fn($row) => (array)$row, $rows);
    }

    /**
     * @param list<object> $rows
     * @return list<array<string, mixed>>
     */
    private function mapOrderRows(array $rows): array
    {
        return array_map(fn ($row): array => $this->mapOrderRow((array) $row), $rows);
    }

    private function canAccess(): bool
    {
        return class_exists(Auth::class)
            && Auth::instance()->isAdmin()
            && Auth::instance()->hasCapability(self::REQUIRED_CAPABILITY);
    }

    private function failResult(string $action, string $message, \Throwable $e, array $context = []): OrdersActionResult
    {
        Logger::instance()->error($message, [
            'module' => 'OrdersModule',
            'action' => $action,
            'exception' => $e::class,
            'context' => $context,
        ]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            $action,
            $message,
            'order',
            isset($context['order_id']) ? (int)$context['order_id'] : null,
            $context + ['exception' => $e::class],
            'error'
        );

        return OrdersActionResult::failure($message . ' Bitte Logs prüfen.');
    }

    private function denyResult(): OrdersActionResult
    {
        return OrdersActionResult::failure('Zugriff verweigert.');
    }

    private function invalidIdResult(): OrdersActionResult
    {
        return OrdersActionResult::failure('Ungültige ID.');
    }

    private function getOrderSnapshot(int $id): ?array
    {
        $row = $this->db->get_row(
            "SELECT * FROM {$this->prefix}orders WHERE id = ? LIMIT 1",
            [$id]
        );

        return $row ? $this->mapOrderRow((array) $row) : null;
    }

    private function requireOrderSnapshot(int $id): array|OrdersActionResult
    {
        $order = $this->getOrderSnapshot($id);

        return $order ?? OrdersActionResult::failure('Bestellung wurde nicht gefunden.');
    }

    private function buildOrderAuditContext(array $order, array $extra = []): array
    {
        return $extra + [
            'order_number' => $this->maskOrderNumber((string) ($order['order_number'] ?? '')),
            'customer_email' => $this->maskEmail((string) ($order['customer_email'] ?? '')),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        if (isset(self::STATUS_ALIASES[$status])) {
            $status = self::STATUS_ALIASES[$status];
        }

        return in_array($status, self::ALLOWED_STATUSES, true) ? $status : '';
    }

    /**
     * @return list<string>
     */
    private function getDatabaseStatusesForFilter(string $status): array
    {
        $status = $this->normalizeStatus($status);
        if ($status === '') {
            return [];
        }

        return match ($status) {
            'paid' => ['paid', 'confirmed', 'completed'],
            default => [$status],
        };
    }

    /**
     * @return list<string>
     */
    private function getAvailableTransitions(string $status): array
    {
        $status = $this->normalizeStatus($status);
        if ($status === '') {
            return [];
        }

        return self::STATUS_TRANSITIONS[$status] ?? [];
    }

    private function canTransitionStatus(string $currentStatus, string $nextStatus): bool
    {
        $currentStatus = $this->normalizeStatus($currentStatus);
        $nextStatus = $this->normalizeStatus($nextStatus);

        if ($currentStatus === '' || $nextStatus === '' || $currentStatus === $nextStatus) {
            return false;
        }

        return in_array($nextStatus, $this->getAvailableTransitions($currentStatus), true);
    }

    private function normalizeBillingCycle(string $billingCycle): string
    {
        $billingCycle = strtolower(trim($billingCycle));

        return in_array($billingCycle, self::ALLOWED_BILLING_CYCLES, true) ? $billingCycle : 'monthly';
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function mapOrderRow(array $order): array
    {
        $contactData = $this->decodeContactData((string) ($order['contact_data'] ?? ''));
        $status = $this->normalizeStatus((string) ($order['status'] ?? ''));
        $rawStatus = strtolower(trim((string) ($order['status'] ?? '')));
        $resolvedStatus = $status !== '' ? $status : $rawStatus;

        $customerEmail = $this->resolveOrderCustomerEmail($order, $contactData);
        $customerName = $this->resolveOrderCustomerName($order, $contactData, $customerEmail);
        $amount = isset($order['amount']) ? (float) $order['amount'] : 0.0;
        $totalAmount = isset($order['total_amount']) ? (float) $order['total_amount'] : 0.0;
        if ($totalAmount <= 0.0 && $amount > 0.0) {
            $totalAmount = $amount;
        }

        $linkedPlanId = (int) ($order['linked_plan_id'] ?? $order['plan_id'] ?? $order['package_id'] ?? 0);

        $order['status'] = $resolvedStatus !== '' ? $resolvedStatus : 'pending';
        $order['status_raw'] = $rawStatus !== '' ? $rawStatus : 'pending';
        $order['customer_email'] = $customerEmail;
        $order['customer_name'] = $customerName;
        $order['amount'] = $amount;
        $order['total_amount'] = $totalAmount;
        $order['currency'] = (string) ($order['currency'] ?? 'EUR');
        $order['plan_id'] = $linkedPlanId > 0 ? $linkedPlanId : (int) ($order['plan_id'] ?? 0);
        $order['linked_plan_id'] = $linkedPlanId;
        $order['available_transitions'] = $resolvedStatus !== '' ? $this->getAvailableTransitions($resolvedStatus) : [];

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContactData(string $contactData): array
    {
        if ($contactData === '') {
            return [];
        }

        $decoded = json_decode($contactData, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $contactData
     */
    private function resolveOrderCustomerEmail(array $order, array $contactData): string
    {
        $candidates = [
            (string) ($order['customer_email'] ?? ''),
            (string) ($order['email'] ?? ''),
            (string) ($contactData['email'] ?? ''),
            (string) ($order['user_email'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $contactData
     */
    private function resolveOrderCustomerName(array $order, array $contactData, string $fallbackEmail): string
    {
        $contactName = trim(((string) ($contactData['first_name'] ?? '')) . ' ' . ((string) ($contactData['last_name'] ?? '')));
        $legacyName = trim(((string) ($order['forename'] ?? '')) . ' ' . ((string) ($order['lastname'] ?? '')));
        $candidates = [
            (string) ($order['customer_name'] ?? ''),
            $contactName,
            $legacyName,
            (string) ($order['display_name'] ?? ''),
            (string) ($order['username'] ?? ''),
            $fallbackEmail,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Gast';
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || strpos($email, '@') === false) {
            return '';
        }

        [$local, $domain] = explode('@', $email, 2);
        $local = strlen($local) <= 2 ? str_repeat('*', max(1, strlen($local))) : substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2));

        return $local . '@' . $domain;
    }

    private function maskOrderNumber(string $orderNumber): string
    {
        $orderNumber = trim($orderNumber);
        $length = strlen($orderNumber);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($orderNumber, 0, 2) . str_repeat('*', max(1, $length - 4)) . substr($orderNumber, -2);
    }
}
