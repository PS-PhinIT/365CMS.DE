<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rechnungen – Entry Point (4.1.0, Bereich 5b)
 * Route: /admin/invoices
 *
 * Liste und Download (`?download=<id>`), Rechnung für eine bezahlte Bestellung manuell erstellen (z. B. Rechnungskauf),
 * Storno-Rechnung erstellen, Mail erneut senden, Einstellungen (Nummernkreis, Absender, Steuermodus).
 * POST-Aktionen (Feld `action`): create, cancel, resend, settings. Capability `invoices.view`.
 */

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Services\FeatureHooks\InvoiceHooks;
use CMS\Services\Payments\InvoiceService;

function cms_admin_invoices_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability('invoices.view');
}

if (!cms_admin_invoices_can_access()) {
    header('Location: /');
    exit;
}

// DSGVO-Auskunft: Export-Datei (Name streng geprüft, liegt im privaten Speicher).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['privacy_export'])) {
    $cmsInvoiceExport = is_string($_GET['privacy_export']) ? \CMS\Services\Payments\InvoicePrivacy::exportPath($_GET['privacy_export']) : null;
    if ($cmsInvoiceExport !== null) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($cmsInvoiceExport) . '"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($cmsInvoiceExport);
        exit;
    }
    header('Location: /admin/invoices', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['download'])) {
    $cmsInvoiceDownloadId = is_string($_GET['download']) && ctype_digit($_GET['download']) ? (int) $_GET['download'] : 0;
    $cmsInvoiceFile = $cmsInvoiceDownloadId > 0
        ? InvoiceService::getInstance()->downloadFor($cmsInvoiceDownloadId, (int) (Auth::instance()->currentUser()->id ?? 0), true)
        : null;
    if ($cmsInvoiceFile !== null) {
        InvoiceHooks::sendPdf($cmsInvoiceFile['path'], $cmsInvoiceFile['filename']);
    }
    $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => 'Rechnung nicht gefunden oder PDF beschädigt (Prüfsumme stimmt nicht).', 'details' => [], 'error_details' => [], 'report_payload' => []];
    header('Location: /admin/invoices', true, 303);
    exit;
}

/**
 * @param array<string, mixed> $result
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function cms_admin_invoices_audit(array $result, string $action, string $description, array $context = []): array
{
    if (!empty($result['success']) && empty($result['existing'])) {
        try {
            AuditLogger::instance()->log(AuditLogger::CAT_SETTING, 'invoices.' . $action, $description, 'invoice', isset($result['invoice']['id']) ? (int) $result['invoice']['id'] : null, $context, 'info');
        } catch (\Throwable) {
        }
    }

    return $result;
}

$sectionPageConfig = [
    'section' => 'invoices',
    'route_path' => '/admin/invoices',
    'view_file' => __DIR__ . '/views/subscriptions/invoices.php',
    'page_title' => 'Rechnungen',
    'active_page' => 'invoices',
    'csrf_action' => 'admin_invoices',
    'module_factory' => static fn (): InvoiceService => InvoiceService::getInstance(),
    'data_loader' => static function (InvoiceService $service): array {
        $query = is_string($_GET['q'] ?? null) ? mb_substr(trim((string) $_GET['q']), 0, 40) : '';
        $settings = $service->settings();
        $seller = $service->seller($settings);

        return [
            'invoices' => $service->listInvoices(['q' => $query], 200),
            'orders' => $service->ordersWithoutInvoice(50),
            'settings' => $settings,
            'seller' => $seller,
            'seller_missing' => array_values(array_filter(
                InvoiceService::missingMandatoryFields(['seller' => $seller['data'], 'small_amount' => true]),
                static fn (string $label): bool => str_contains($label, 'Leistenden')
            )),
            'tax_id_missing' => $seller['data']['tax_number'] === '' && $seller['data']['vat_id'] === '',
            'next_numbers' => $service->nextNumbers(),
            'query' => $query,
            'privacy_exports' => \CMS\Services\Payments\InvoicePrivacy::listExports(),
        ];
    },
    'access_checker' => static fn (): bool => cms_admin_invoices_can_access(),
    'access_denied_route' => '/',
    'redirect_path_resolver' => static fn (): string => '/admin/invoices',
    'post_handler' => static function (InvoiceService $service, string $section, array $post): array {
        $action = is_string($post['action'] ?? null) ? (string) $post['action'] : '';
        $text = static fn (string $key): string => is_string($post[$key] ?? null) ? trim((string) $post[$key]) : '';
        $actorId = (int) (Auth::instance()->currentUser()->id ?? 0);
        $withNumber = static fn (array $result, string $message): array => !empty($result['success'])
            ? ['success' => true, 'message' => (!empty($result['existing']) ? 'Für diese Bestellung besteht bereits die Rechnung ' : $message) . (string) ($result['invoice']['invoice_number'] ?? '') . '.'] + $result
            : $result;

        return match ($action) {
            'create' => cms_admin_invoices_audit($withNumber($service->createForOrder(max(0, (int) ($post['order_id'] ?? 0)), [
                'tax_mode' => $text('tax_mode'),
                'customer_vat_id' => $text('customer_vat_id'),
                'send_mail' => !empty($post['send_mail']),
                'created_by' => $actorId,
                'source' => 'admin',
            ]), 'Rechnung erstellt: '), 'create', 'Rechnung manuell erstellt', ['order_id' => (int) ($post['order_id'] ?? 0)]),
            'cancel' => cms_admin_invoices_audit(
                $withNumber($service->createCancellation(max(0, (int) ($post['invoice_id'] ?? 0)), $text('reason'), $actorId, !empty($post['send_mail'])), 'Storno-Rechnung erstellt: '),
                'cancel',
                'Storno-Rechnung erstellt',
                ['cancels_invoice_id' => (int) ($post['invoice_id'] ?? 0)]
            ),
            'resend' => $service->resendMail(max(0, (int) ($post['invoice_id'] ?? 0))),
            'settings' => cms_admin_invoices_audit($service->saveSettings($post), 'settings', 'Rechnungseinstellungen gespeichert'),
            default => ['success' => false, 'error' => 'Unbekannte Aktion.'],
        };
    },
];

require __DIR__ . '/partials/section-page-shell.php';
