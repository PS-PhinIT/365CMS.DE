<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Zahlungen: Anbieter, Schlüssel (nie angezeigt, nur „gesetzt“), Webhook-URLs, letzte Ereignisse.
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$pyData = is_array($data ?? null) ? $data : [];
$pyFlags = (array) ($pyData['flags'] ?? []);
$pySecrets = (array) ($pyData['secrets'] ?? []);
$pyMissing = (array) ($pyData['missing'] ?? []);
$pyEvents = (array) ($pyData['events'] ?? []);
$pyBase = (string) ($pyData['webhook_base'] ?? '');
$pyE = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$pyStatus = [
    'processed' => ['Verarbeitet', 'bg-green-lt text-green'],
    'ignored' => ['Ignoriert', 'bg-secondary-lt'],
    'failed' => ['Fehler', 'bg-red-lt text-red'],
    'received' => ['Empfangen', 'bg-azure-lt text-azure'],
];
$pySecret = static function (string $key, string $label) use ($pySecrets, $pyE): string {
    $set = !empty($pySecrets[$key]);

    return '<div class="mb-3"><label class="form-label" for="py_' . $pyE($key) . '">' . $pyE($label) . '</label>'
        . '<input type="password" class="form-control" id="py_' . $pyE($key) . '" name="' . $pyE($key) . '" autocomplete="off" placeholder="'
        . ($set ? 'gesetzt – leer lassen, um ihn zu behalten' : 'nicht gesetzt') . '"></div>';
};
$pyCheck = static function (string $key, string $label, string $hint = '') use ($pyFlags, $pyE): string {
    return '<label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="' . $pyE($key) . '" value="1"'
        . (!empty($pyFlags[$key]) ? ' checked' : '') . '><span class="form-check-label">' . $pyE($label) . '</span>'
        . ($hint !== '' ? '<span class="form-check-description">' . $pyE($hint) . '</span>' : '') . '</label>';
};
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="page-pretitle">Abos</div>
        <h2 class="page-title">Zahlungen</h2>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <?php if (!empty($pyFlags['test_mode'])): ?>
            <div class="alert alert-warning mb-4" role="status"><strong>Testmodus aktiv.</strong> Es werden die Test-Schlüssel verwendet; echte Zahlungen finden nicht statt.</div>
        <?php endif; ?>

        <form method="post" action="/admin/payments">
            <input type="hidden" name="csrf_token" value="<?= $pyE($csrfToken) ?>">
            <input type="hidden" name="action" value="save">
            <div class="row g-4">
                <div class="col-xl-4">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Allgemein</h3></div>
                        <div class="card-body">
                            <?= $pyCheck('test_mode', 'Testmodus', 'Test-Schlüssel verwenden (sk_test_…, test_…).') ?>
                            <?= $pyCheck('recurring', 'Abos automatisch verlängern', 'Nur für angemeldete Kunden; monatlich bzw. jährlich beim Anbieter abbuchen.') ?>
                            <p class="small text-secondary mb-0">Kartendaten erreichen den Server nie: Kunden bezahlen auf der Seite des Anbieters. Bezahlt ist eine Bestellung erst, wenn der Anbieter es per Webhook bestätigt.</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Stripe</h3></div>
                        <div class="card-body">
                            <?= $pyCheck('stripe_enabled', 'Stripe anbieten') ?>
                            <?php if (!empty($pyFlags['stripe_enabled']) && !empty($pyMissing['stripe'])): ?>
                                <div class="alert alert-danger small" role="status">Fehlt: <?= $pyE(implode(', ', (array) $pyMissing['stripe'])) ?></div>
                            <?php endif; ?>
                            <?= $pySecret('stripe_secret_test', 'Secret Key (Test)') ?>
                            <?= $pySecret('stripe_webhook_secret_test', 'Webhook-Geheimnis (Test, whsec_…)') ?>
                            <?= $pySecret('stripe_secret_live', 'Secret Key (Live)') ?>
                            <?= $pySecret('stripe_webhook_secret_live', 'Webhook-Geheimnis (Live, whsec_…)') ?>
                            <label class="form-label" for="pyStripeHook">Webhook-URL</label>
                            <input type="text" class="form-control" id="pyStripeHook" readonly value="<?= $pyE($pyBase . 'stripe') ?>">
                            <div class="form-hint">Ereignisse: checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, invoice.paid, invoice.payment_failed, customer.subscription.deleted, charge.refunded, charge.dispute.created.</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Mollie (auch PayPal)</h3></div>
                        <div class="card-body">
                            <?= $pyCheck('mollie_enabled', 'Mollie anbieten', 'Zahlart „PayPal“ läuft über Mollie.') ?>
                            <?php if (!empty($pyFlags['mollie_enabled']) && !empty($pyMissing['mollie'])): ?>
                                <div class="alert alert-danger small" role="status">Fehlt: <?= $pyE(implode(', ', (array) $pyMissing['mollie'])) ?></div>
                            <?php endif; ?>
                            <?= $pySecret('mollie_key_test', 'API-Key (Test, test_…)') ?>
                            <?= $pySecret('mollie_key_live', 'API-Key (Live, live_…)') ?>
                            <label class="form-label" for="pyMollieHook">Webhook-URL</label>
                            <input type="text" class="form-control" id="pyMollieHook" readonly value="<?= $pyE($pyBase . 'mollie') ?>">
                            <div class="form-hint">Wird bei jeder Zahlung automatisch übergeben; im Mollie-Dashboard ist nichts einzutragen.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3 mb-4"><button type="submit" class="btn btn-primary">Speichern</button>
                <span class="small text-secondary ms-2">Welche Zahlarten im Checkout erscheinen, legst du unter <a href="/admin/packages">Abos → Pakete</a> fest; nicht eingerichtete Anbieter werden ausgeblendet.</span></div>
        </form>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Letzte Anbieter-Ereignisse</h3></div>
            <?php if ($pyEvents === []): ?>
                <div class="card-body"><p class="text-secondary mb-0">Noch keine Ereignisse.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th scope="col">Empfangen (UTC)</th><th scope="col">Anbieter</th><th scope="col">Typ</th><th scope="col">Bestellung</th><th scope="col">Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($pyEvents as $pyEvent): ?>
                                <?php [$pyLabel, $pyClass] = $pyStatus[$pyEvent['status']] ?? [$pyEvent['status'], 'bg-secondary-lt']; ?>
                                <tr>
                                    <td class="text-nowrap"><?= $pyE($pyEvent['received_at']) ?></td>
                                    <td><?= $pyE($pyEvent['provider']) ?></td>
                                    <td><code><?= $pyE($pyEvent['type']) ?></code></td>
                                    <td><?= (int) ($pyEvent['order_id'] ?? 0) > 0 ? '#' . (int) $pyEvent['order_id'] : '–' ?></td>
                                    <td><span class="badge <?= $pyE($pyClass) ?>"><?= $pyE($pyLabel) ?></span>
                                        <?php if ((string) ($pyEvent['error'] ?? '') !== ''): ?><div class="small text-danger"><?= $pyE($pyEvent['error']) ?></div><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
