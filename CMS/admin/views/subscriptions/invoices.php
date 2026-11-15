<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rechnungen: Liste mit Download, Storno und erneutem Versand; manuelle Rechnung für bezahlte Bestellungen;
 * Einstellungen (Nummernkreis, Steuermodus, Absender – leere Felder übernehmen das Rechtstexte-Profil).
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

use CMS\Services\Payments\InvoiceService;

$invData = is_array($data ?? null) ? $data : [];
$invList = (array) ($invData['invoices'] ?? []);
$invOrders = (array) ($invData['orders'] ?? []);
$invSettings = (array) ($invData['settings'] ?? InvoiceService::SETTING_DEFAULTS);
$invSeller = (array) ($invData['seller']['data'] ?? []);
$invSources = (array) ($invData['seller']['sources'] ?? []);
$invSellerMissing = (array) ($invData['seller_missing'] ?? []);
$invNext = (array) ($invData['next_numbers'] ?? []);
$invQuery = (string) ($invData['query'] ?? '');
$invE = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$invMoney = static fn (float $amount, string $currency): string => InvoiceService::formatMoney($amount, $currency);
$invSenderFields = [
    'sender_name' => ['Name / Firma', 'name', 150],
    'sender_street' => ['Straße und Hausnummer', 'street', 150],
    'sender_zip' => ['PLZ', 'zip', 20],
    'sender_city' => ['Ort', 'city', 100],
    'sender_country' => ['Land', 'country', 100],
    'sender_email' => ['E-Mail', 'email', 150],
    'tax_number' => ['Steuernummer', 'tax_number', 40],
    'vat_id' => ['USt-IdNr.', 'vat_id', 20],
    'bank_name' => ['Bank', 'bank_name', 100],
    'iban' => ['IBAN', 'iban', 40],
    'bic' => ['BIC', 'bic', 11],
];
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="page-pretitle">Abos</div>
        <h2 class="page-title">Rechnungen</h2>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <?php if ($invSellerMissing !== [] || !empty($invData['tax_id_missing'])): ?>
            <div class="alert alert-warning mb-4" role="status">
                <div class="fw-semibold mb-1">Absenderdaten unvollständig – Rechnungen werden erst mit allen Pflichtangaben erstellt.</div>
                Es fehlen: <?= $invE(implode(', ', array_merge($invSellerMissing, !empty($invData['tax_id_missing']) ? ['Steuernummer oder USt-IdNr.'] : []))) ?>.
                Ergänze sie unten oder im Rechtstexte-Profil (<a href="/admin/legal-sites">Recht &amp; Datenschutz → Rechtstexte</a>).
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title">Rechnungen</h3>
                        <div class="card-actions">
                            <form method="get" action="/admin/invoices" class="d-flex gap-2" role="search">
                                <label class="visually-hidden" for="invSearch">Rechnungsnummer suchen</label>
                                <input type="search" class="form-control form-control-sm" id="invSearch" name="q" maxlength="40" value="<?= $invE($invQuery) ?>" placeholder="Nummer suchen">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Suchen</button>
                            </form>
                        </div>
                    </div>
                    <?php if ($invList === []): ?>
                        <div class="card-body"><p class="text-secondary mb-0"><?= $invQuery !== '' ? 'Keine Rechnung mit dieser Nummer.' : 'Noch keine Rechnungen. Sie entstehen automatisch beim Zahlungseingang oder rechts für eine bezahlte Bestellung.' ?></p></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table">
                                <thead><tr><th scope="col">Nummer</th><th scope="col">Datum</th><th scope="col">Empfänger</th><th scope="col" class="text-end">Brutto</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead>
                                <tbody>
                                    <?php foreach ($invList as $invItem): ?>
                                        <?php $invId = (int) $invItem['id']; $invIsCancellation = $invItem['type'] === InvoiceService::TYPE_CANCELLATION; ?>
                                        <tr>
                                            <td>
                                                <div class="fw-medium"><?= $invE($invItem['invoice_number']) ?></div>
                                                <div class="small text-secondary"><?= $invItem['order_id'] > 0 ? 'Bestellung #' . (int) $invItem['order_id'] : 'ohne Bestellung' ?> · <?= $invE(InvoiceService::TAX_MODES[$invItem['tax_mode']] ?? $invItem['tax_mode']) ?></div>
                                            </td>
                                            <td class="text-nowrap"><?= $invE(InvoiceService::formatDate($invItem['issued_at'])) ?></td>
                                            <td><?= $invE($invItem['customer_name']) ?></td>
                                            <td class="text-end text-nowrap"><?= $invE($invMoney((float) $invItem['gross_total'], (string) $invItem['currency'])) ?></td>
                                            <td>
                                                <?php if ($invIsCancellation): ?>
                                                    <span class="badge bg-red-lt text-red">Storno zu <?= $invE($invItem['cancels_number']) ?></span>
                                                <?php elseif (!empty($invItem['is_cancelled'])): ?>
                                                    <span class="badge bg-secondary-lt">Storniert</span>
                                                <?php else: ?>
                                                    <span class="badge bg-green-lt text-green">Gültig</span>
                                                <?php endif; ?>
                                                <?php if ($invItem['restricted_at'] !== ''): ?>
                                                    <span class="badge bg-yellow-lt text-yellow" title="DSGVO-Löschung: nur noch zur Erfüllung der Aufbewahrungspflicht">Gesperrt</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex flex-wrap gap-1 justify-content-end">
                                                    <a class="btn btn-sm btn-outline-primary" href="/admin/invoices?download=<?= $invId ?>">PDF</a>
                                                    <form method="post" action="/admin/invoices">
                                                        <input type="hidden" name="csrf_token" value="<?= $invE($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="resend">
                                                        <input type="hidden" name="invoice_id" value="<?= $invId ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Mail</button>
                                                    </form>
                                                </div>
                                                <?php if (!$invIsCancellation && empty($invItem['is_cancelled'])): ?>
                                                    <details class="mt-1 text-start">
                                                        <summary class="small text-danger">Stornieren …</summary>
                                                        <form method="post" action="/admin/invoices" class="mt-2">
                                                            <input type="hidden" name="csrf_token" value="<?= $invE($csrfToken) ?>">
                                                            <input type="hidden" name="action" value="cancel">
                                                            <input type="hidden" name="invoice_id" value="<?= $invId ?>">
                                                            <label class="form-label small" for="invReason<?= $invId ?>">Grund (erscheint auf der Storno-Rechnung)</label>
                                                            <input type="text" class="form-control form-control-sm mb-2" id="invReason<?= $invId ?>" name="reason" maxlength="300">
                                                            <label class="form-check small"><input type="checkbox" class="form-check-input" name="send_mail" value="1"<?= $invSettings['send_mail'] === '1' ? ' checked' : '' ?>><span class="form-check-label">Storno-Rechnung per Mail senden</span></label>
                                                            <button type="submit" class="btn btn-sm btn-danger mt-1">Storno-Rechnung erstellen</button>
                                                        </form>
                                                    </details>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <div class="card-footer small text-secondary">Rechnungen sind unveränderlich (PDF und Daten festgeschrieben, SHA-256 geprüft). Korrekturen nur per Storno-Rechnung und neuer Rechnung. Aufbewahrung 10 Jahre; DSGVO-Löschungen setzen einen Sperrvermerk.</div>
                </div>
            </div>

            <div class="col-xl-4">
                <form method="post" action="/admin/invoices" class="card mb-4">
                    <input type="hidden" name="csrf_token" value="<?= $invE($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="card-header"><h3 class="card-title">Rechnung für Bestellung erstellen</h3></div>
                    <div class="card-body">
                        <?php if ($invOrders === []): ?>
                            <p class="text-secondary mb-0">Keine bezahlte Bestellung ohne gültige Rechnung.</p>
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label" for="invOrder">Bezahlte Bestellung</label>
                                <select class="form-select" id="invOrder" name="order_id" required>
                                    <?php foreach ($invOrders as $invOrder): ?>
                                        <option value="<?= (int) $invOrder['id'] ?>"><?= $invE($invOrder['order_number'] . ' · ' . $invOrder['customer'] . ' · ' . $invMoney((float) $invOrder['total'], (string) $invOrder['currency'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="invTaxMode">Steuermodus</label>
                                <select class="form-select" id="invTaxMode" name="tax_mode">
                                    <option value="">Wie eingestellt (<?= $invE(InvoiceService::TAX_MODES[$invSettings['tax_mode']] ?? '') ?>)</option>
                                    <?php foreach (InvoiceService::TAX_MODES as $invMode => $invLabel): ?>
                                        <option value="<?= $invE($invMode) ?>"><?= $invE($invLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="invCustomerVat">USt-IdNr. des Empfängers (optional)</label>
                                <input type="text" class="form-control" id="invCustomerVat" name="customer_vat_id" maxlength="20" placeholder="z. B. ATU12345678">
                                <div class="form-hint">Pflicht bei Reverse Charge.</div>
                            </div>
                            <label class="form-check"><input type="checkbox" class="form-check-input" name="send_mail" value="1"<?= $invSettings['send_mail'] === '1' ? ' checked' : '' ?>><span class="form-check-label">Rechnung per Mail senden</span></label>
                        <?php endif; ?>
                    </div>
                    <?php if ($invOrders !== []): ?>
                        <div class="card-footer"><button type="submit" class="btn btn-primary">Rechnung erstellen</button></div>
                    <?php endif; ?>
                </form>

                <form method="post" action="/admin/invoices" class="card">
                    <input type="hidden" name="csrf_token" value="<?= $invE($csrfToken) ?>">
                    <input type="hidden" name="action" value="settings">
                    <div class="card-header"><h3 class="card-title">Einstellungen</h3></div>
                    <div class="card-body">
                        <fieldset class="mb-3">
                            <legend class="form-label">Nummernkreis</legend>
                            <label class="form-label" for="invFormat">Format Rechnungen</label>
                            <input type="text" class="form-control mb-1" id="invFormat" name="number_format" maxlength="40" required value="<?= $invE($invSettings['number_format']) ?>">
                            <div class="form-hint mb-2">Nächste Nummer: <code><?= $invE($invNext['invoice'] ?? '') ?></code></div>
                            <label class="form-label" for="invCancelFormat">Format Storno-Rechnungen</label>
                            <input type="text" class="form-control mb-1" id="invCancelFormat" name="cancellation_format" maxlength="40" required value="<?= $invE($invSettings['cancellation_format']) ?>">
                            <div class="form-hint mb-2">Nächste Nummer: <code><?= $invE($invNext['cancellation'] ?? '') ?></code>. Gleiches Format = gemeinsamer Nummernkreis.</div>
                            <label class="form-label" for="invStart">Startnummer neuer Nummernkreise</label>
                            <input type="number" class="form-control" id="invStart" name="start_number" min="1" max="999999999" value="<?= (int) $invSettings['start_number'] ?>">
                            <div class="form-hint">Platzhalter: <code>{YYYY}</code>, <code>{YY}</code>, <code>{MM}</code> und genau ein Zähler <code>{NNNNN}</code> (3–10 Stellen). Ein laufender Kreis zählt nie zurück.</div>
                        </fieldset>
                        <fieldset class="mb-3">
                            <legend class="form-label">Steuer</legend>
                            <label class="form-label" for="invMode">Steuermodus</label>
                            <select class="form-select mb-2" id="invMode" name="tax_mode">
                                <?php foreach (InvoiceService::TAX_MODES as $invMode => $invLabel): ?>
                                    <option value="<?= $invE($invMode) ?>"<?= $invSettings['tax_mode'] === $invMode ? ' selected' : '' ?>><?= $invE($invLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="form-label" for="invExempt">Hinweis bei 0 % Umsatzsteuer (Regelbesteuerung)</label>
                            <input type="text" class="form-control" id="invExempt" name="exempt_note" maxlength="300" value="<?= $invE($invSettings['exempt_note']) ?>" placeholder="z. B. Steuerfreie Leistung nach § 4 Nr. … UStG">
                            <div class="form-hint">Reverse Charge wird bei EU-Kunden mit USt-IdNr. und 0 % Steuer automatisch gewählt.</div>
                        </fieldset>
                        <fieldset class="mb-3">
                            <legend class="form-label">Absender</legend>
                            <p class="form-hint">Leere Felder übernehmen das Rechtstexte-Profil. Aktuell wirksam steht jeweils darunter.</p>
                            <?php foreach ($invSenderFields as $invKey => [$invLabel, $invField, $invMax]): ?>
                                <label class="form-label" for="inv_<?= $invE($invKey) ?>"><?= $invE($invLabel) ?></label>
                                <input type="text" class="form-control" id="inv_<?= $invE($invKey) ?>" name="<?= $invE($invKey) ?>" maxlength="<?= (int) $invMax ?>" value="<?= $invE($invSettings[$invKey] ?? '') ?>">
                                <div class="form-hint mb-2">
                                    <?php if (($invSources[$invField] ?? '') === 'legal_profile'): ?>
                                        aus Rechtstexte-Profil: <?= $invE($invSeller[$invField] ?? '') ?>
                                    <?php elseif (($invSeller[$invField] ?? '') === ''): ?>
                                        nicht gesetzt
                                    <?php else: ?>
                                        &nbsp;
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <label class="form-label" for="invFooter">Fußzeile (optional)</label>
                            <input type="text" class="form-control" id="invFooter" name="footer_note" maxlength="300" value="<?= $invE($invSettings['footer_note']) ?>">
                        </fieldset>
                        <fieldset>
                            <legend class="form-label">Automatik</legend>
                            <label class="form-check"><input type="checkbox" class="form-check-input" name="auto_create" value="1"<?= $invSettings['auto_create'] === '1' ? ' checked' : '' ?>><span class="form-check-label">Rechnung bei Zahlungseingang und Verlängerung erstellen</span></label>
                            <label class="form-check"><input type="checkbox" class="form-check-input" name="send_mail" value="1"<?= $invSettings['send_mail'] === '1' ? ' checked' : '' ?>><span class="form-check-label">Rechnung per Mail (PDF-Anhang, Mail-Warteschlange) senden</span></label>
                        </fieldset>
                    </div>
                    <div class="card-footer"><button type="submit" class="btn btn-primary">Speichern</button></div>
                </form>

                <div class="card mt-4">
                    <div class="card-header"><h3 class="card-title">DSGVO-Auskünfte (Rechnungen)</h3></div>
                    <div class="card-body small">
                        <p class="text-secondary">Entstehen beim Abschließen einer Auskunftsanfrage unter <a href="/admin/data-requests">Auskunft &amp; Löschen</a>; werden nach 30 Tagen gelöscht. Rechnungen selbst werden wegen der Aufbewahrungspflicht nicht gelöscht, sondern gesperrt.</p>
                        <?php $invExports = (array) ($data['privacy_exports'] ?? []); ?>
                        <?php if ($invExports === []): ?>
                            <p class="mb-0 text-secondary">Keine Exporte vorhanden.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($invExports as $invExport): ?>
                                    <li><a href="/admin/invoices?privacy_export=<?= rawurlencode((string) $invExport['name']) ?>"><?= $invE($invExport['name']) ?></a> <span class="text-secondary">(<?= $invE($invExport['created_at']) ?>)</span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
