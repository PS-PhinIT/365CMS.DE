<?php
declare(strict_types=1);

/**
 * Rechnungen des eigenen Kontos (4.1.0, Bereich 5b). Routen `/member/subscription/invoices` und `/member/invoices`.
 * Download über `GET /member/invoices/{id}` (InvoiceHooks: Eigentumsprüfung, SHA-256-Prüfung, sonst 404).
 */

use CMS\Member\MemberI18n;
use CMS\Services\Payments\InvoiceService;

if (!defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/includes/bootstrap.php';

if (class_exists('\CMS\Services\CoreModuleService')
    && !\CMS\Services\CoreModuleService::getInstance()->isModuleEnabled('subscription_member_area')) {
    $controller->redirect($controller->getHomePath());
}

$pageTitle = $t('member.invoices.title');
$pageKey = 'invoices';
$pageAssets = [];
try {
    $invoices = InvoiceService::getInstance()->listForUser($controller->getUserId());
} catch (\Throwable) {
    $invoices = [];
}
$isEnglish = MemberI18n::locale() === 'en';
$money = static fn (float $amount, string $currency): string => number_format($amount, 2, $isEnglish ? '.' : ',', $isEnglish ? ',' : '.') . ' ' . ($currency === 'EUR' ? '€' : $currency);

include __DIR__ . '/partials/header.php';
?>
<section class="card" aria-labelledby="member-invoices-title">
    <div class="card-header">
        <h2 class="card-title" id="member-invoices-title"><?= $e($t('member.invoices.title')) ?></h2>
        <div class="card-actions"><a href="/member/subscription" class="btn btn-sm btn-outline-secondary"><?= $e($t('member.invoices.back')) ?></a></div>
    </div>
    <div class="table-responsive">
        <table class="table card-table table-vcenter">
            <caption class="visually-hidden"><?= $e($t('member.invoices.title')) ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?= $e($t('member.invoices.col_number')) ?></th>
                    <th scope="col"><?= $e($t('member.invoices.col_date')) ?></th>
                    <th scope="col"><?= $e($t('member.invoices.col_period')) ?></th>
                    <th scope="col"><?= $e($t('member.invoices.col_amount')) ?></th>
                    <th scope="col"><span class="visually-hidden"><?= $e($t('member.invoices.download')) ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($invoices === []): ?>
                    <tr><td colspan="5" class="text-secondary"><?= $e($t('member.invoices.empty')) ?></td></tr>
                <?php else: ?>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td>
                                <div class="fw-medium"><?= $e($invoice['invoice_number']) ?></div>
                                <?php if ($invoice['type'] === InvoiceService::TYPE_CANCELLATION): ?>
                                    <span class="badge bg-red-lt"><?= $e($t('member.invoices.cancellation', ['number' => $invoice['cancels_number']])) ?></span>
                                <?php elseif (!empty($invoice['is_cancelled'])): ?>
                                    <span class="badge bg-secondary-lt"><?= $e($t('member.invoices.cancelled')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $e(MemberI18n::formatDate($invoice['issued_at'], false)) ?></td>
                            <td><?= $e(MemberI18n::formatDate($invoice['service_period_start'], false)) ?><?= $invoice['service_period_end'] !== $invoice['service_period_start'] ? ' – ' . $e(MemberI18n::formatDate($invoice['service_period_end'], false)) : '' ?></td>
                            <td><?= $e($money((float) $invoice['gross_total'], (string) $invoice['currency'])) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-primary" href="/member/invoices/<?= (int) $invoice['id'] ?>"><?= $e($t('member.invoices.download')) ?><span class="visually-hidden"> <?= $e($invoice['invoice_number']) ?></span></a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer text-secondary small"><?= $e($t('member.invoices.retention_hint')) ?></div>
</section>
<?php include __DIR__ . '/partials/footer.php';
