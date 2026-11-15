<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Zahlungen – Entry Point (4.1.0, Bereich 5a)
 * Route: /admin/payments
 *
 * Anbieter (Stripe, Mollie inkl. PayPal), Testmodus, Schlüssel und Webhook-Geheimnisse (verschlüsselt, leere Felder
 * behalten den Wert), automatische Verlängerung, Webhook-URLs zum Kopieren, letzte Anbieter-Ereignisse.
 * POST-Aktion `save`. Capability `payments.manage`.
 */

use CMS\Auth;
use CMS\Services\Payments\PaymentService;
use CMS\Services\Payments\PaymentSettings;

function cms_admin_payments_can_access(): bool
{
    // Release-Prüfung 4.1.0 (FUN-410-59): wie Bestellungen/Pakete nur bei aktivem Kernmodul (Menü blendet die Seite sonst aus).
    return Auth::instance()->hasCapability('payments.manage') && \CMS\Services\AdminMenu\DelegatedAdminAccess::canAccess('payments', 'payments.manage')
        && \CMS\Services\CoreModuleService::getInstance()->isAdminPageEnabled('payments');
}

if (!cms_admin_payments_can_access()) {
    header('Location: /');
    exit;
}

$sectionPageConfig = [
    'section' => 'payments',
    'route_path' => '/admin/payments',
    'view_file' => __DIR__ . '/views/subscriptions/payments.php',
    'page_title' => 'Zahlungen',
    'active_page' => 'payments',
    'csrf_action' => 'admin_payments',
    'module_factory' => static fn (): PaymentService => PaymentService::getInstance(),
    'data_loader' => static fn (PaymentService $service): array => [
        'flags' => $service->settings()->flags(),
        'secrets' => $service->settings()->secretStatus(),
        'missing' => [
            'stripe' => $service->settings()->missing('stripe'),
            'mollie' => $service->settings()->missing('mollie'),
        ],
        'webhook_base' => rtrim((string) SITE_URL, '/') . PaymentService::WEBHOOK_PATH,
        'events' => $service->recentEvents(50),
    ],
    'access_checker' => static fn (): bool => cms_admin_payments_can_access(),
    'access_denied_route' => '/',
    'redirect_path_resolver' => static fn (): string => '/admin/payments',
    'post_handler' => static function (PaymentService $service, string $section, array $post): array {
        if (($post['action'] ?? '') !== 'save') {
            return ['success' => false, 'error' => 'Unbekannte Aktion.'];
        }
        $result = PaymentSettings::save($post);
        if (!empty($result['details'])) {
            $result['error'] = (string) $result['error'] . ' ' . implode(' ', $result['details']);
        }

        return $result;
    },
];

require __DIR__ . '/partials/section-page-shell.php';
