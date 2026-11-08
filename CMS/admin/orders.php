<?php
declare(strict_types=1);

/**
 * Bestellungen – Entry Point
 * Route: /admin/orders
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Services\CoreModuleService;

const CMS_ADMIN_ORDERS_CAPABILITY = 'manage_settings';
const CMS_ADMIN_ORDERS_ALLOWED_STATUSES = ['pending', 'paid', 'cancelled', 'refunded', 'failed'];
const CMS_ADMIN_ORDERS_STATUS_ALIASES = ['confirmed' => 'paid', 'completed' => 'paid'];
const CMS_ADMIN_ORDERS_ALLOWED_BILLING_CYCLES = ['monthly', 'yearly', 'lifetime'];
const CMS_ADMIN_ORDERS_ALLOWED_EXPORTS = ['orders', 'usage'];

/**
 * @return array<string, string>
 */
function cms_admin_orders_allowed_actions(): array
{
    return [
        'assign_subscription' => 'assign_subscription',
        'update_status' => 'update_status',
        'delete' => 'delete',
    ];
}

function cms_admin_orders_normalize_action(mixed $action): string
{
    $action = is_string($action) ? trim($action) : '';
    $allowedActions = cms_admin_orders_allowed_actions();

    return $allowedActions[$action] ?? '';
}

function cms_admin_orders_normalize_positive_id(mixed $value): int
{
    if (is_int($value)) {
        return $value > 0 ? $value : 0;
    }

    if (!is_scalar($value)) {
        return 0;
    }

    $value = trim((string) $value);
    if ($value === '' || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        return 0;
    }

    return (int) $value;
}

function cms_admin_orders_normalize_status_value(mixed $status): string
{
    $status = is_string($status) ? strtolower(trim($status)) : '';

    if (isset(CMS_ADMIN_ORDERS_STATUS_ALIASES[$status])) {
        $status = CMS_ADMIN_ORDERS_STATUS_ALIASES[$status];
    }

    return in_array($status, CMS_ADMIN_ORDERS_ALLOWED_STATUSES, true) ? $status : '';
}

function cms_admin_orders_normalize_billing_cycle(mixed $billingCycle): string
{
    $billingCycle = is_string($billingCycle) ? strtolower(trim($billingCycle)) : '';

    return in_array($billingCycle, CMS_ADMIN_ORDERS_ALLOWED_BILLING_CYCLES, true) ? $billingCycle : 'monthly';
}

function cms_admin_orders_redirect(): never
{
    header('Location: /admin/orders');
    exit;
}

function cms_admin_orders_redirect_to(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function cms_admin_orders_flash(string $type, string $message): void
{
    $_SESSION['admin_alert'] = [
        'type' => $type === 'success' ? 'success' : 'danger',
        'message' => trim($message),
    ];
}

function cms_admin_orders_normalize_status_filter(string $status): string
{
    return cms_admin_orders_normalize_status_value($status);
}

function cms_admin_orders_normalize_export(mixed $export): string
{
    $export = is_string($export) ? strtolower(trim($export)) : '';

    return in_array($export, CMS_ADMIN_ORDERS_ALLOWED_EXPORTS, true) ? $export : '';
}

function cms_admin_orders_build_redirect_path(string $statusFilter = ''): string
{
    return $statusFilter !== ''
        ? '/admin/orders?status=' . rawurlencode($statusFilter)
        : '/admin/orders';
}

function cms_admin_orders_handle_action(OrdersModule $module, string $action, array $post): ?OrdersActionResult
{
    return match ($action) {
        'assign_subscription' => $module->assignSubscription(
            cms_admin_orders_normalize_positive_id($post['user_id'] ?? 0),
            cms_admin_orders_normalize_positive_id($post['plan_id'] ?? 0),
            cms_admin_orders_normalize_billing_cycle($post['billing_cycle'] ?? 'monthly')
        ),
        'update_status' => $module->updateStatus(
            cms_admin_orders_normalize_positive_id($post['id'] ?? 0),
            cms_admin_orders_normalize_status_value($post['status'] ?? '')
        ),
        'delete' => $module->delete(cms_admin_orders_normalize_positive_id($post['id'] ?? 0)),
        default => null,
    };
}

if (!Auth::instance()->isAdmin()
    || !Auth::instance()->hasCapability(CMS_ADMIN_ORDERS_CAPABILITY)
    || !CoreModuleService::getInstance()->isAdminPageEnabled('orders')) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/subscriptions/OrdersModule.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    $statusFilter = cms_admin_orders_normalize_status_filter((string) ($_GET['status'] ?? ''));
    $redirectPath = cms_admin_orders_build_redirect_path($statusFilter);
    $exportType = cms_admin_orders_normalize_export($_GET['export'] ?? '');

    if ($exportType === '') {
        cms_admin_orders_flash('danger', 'Unbekannter Exporttyp.');
        cms_admin_orders_redirect_to($redirectPath);
    }

    try {
        (new OrdersModule())->streamCsvExport($exportType, $statusFilter);
    } catch (\RuntimeException $e) {
        \CMS\Logger::instance()->withChannel('orders')->warning(
            'CSV-Export konnte nicht vorbereitet werden.',
            [
                'export_type' => $exportType,
                'status_filter' => $statusFilter,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]
        );
        cms_admin_orders_flash('danger', 'Der Export konnte nicht vorbereitet werden.');
    } catch (\Throwable) {
        cms_admin_orders_flash('danger', 'Der Export konnte nicht vorbereitet werden.');
    }

    cms_admin_orders_redirect_to($redirectPath);
}

$sectionPageConfig = [
    'route_path' => '/admin/orders',
    'view_file' => __DIR__ . '/views/subscriptions/orders.php',
    'page_title' => 'Bestellungen & Zuweisung',
    'active_page' => 'orders',
    'csrf_action' => 'admin_orders',
    'module_file' => __DIR__ . '/modules/subscriptions/OrdersModule.php',
    'module_factory' => static fn (): OrdersModule => new OrdersModule(),
    'access_checker' => static fn (): bool => Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_ORDERS_CAPABILITY)
        && CoreModuleService::getInstance()->isAdminPageEnabled('orders'),
    'request_context_resolver' => static function (OrdersModule $module): array {
        $statusFilter = cms_admin_orders_normalize_status_filter((string) ($_GET['status'] ?? ''));

        return [
            'section' => $statusFilter,
            'data' => $module->getData($statusFilter)->toArray(),
        ];
    },
    'redirect_path_resolver' => static function (OrdersModule $module, string $section): string {
        $statusFilter = cms_admin_orders_normalize_status_filter($section);

        return cms_admin_orders_build_redirect_path($statusFilter);
    },
    'post_handler' => static function (OrdersModule $module, string $section, array $post): array {
        $action = cms_admin_orders_normalize_action($post['action'] ?? '');
        if ($action === '') {
            return ['success' => false, 'error' => 'Unbekannte Aktion.'];
        }

        $result = cms_admin_orders_handle_action($module, $action, $post);

        return $result instanceof OrdersActionResult
            ? $result->toArray()
            : ['success' => false, 'error' => 'Unbekannte Aktion.'];
    },
];

require __DIR__ . '/partials/section-page-shell.php';
