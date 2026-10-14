<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$pageAssets = $pageAssets ?? [];
$pageAssets['js'] = is_array($pageAssets['js'] ?? null) ? $pageAssets['js'] : [];
$pageAssets['js'][] = cms_asset_url('js/admin-subscriptions.js');

$settings = $data['settings'] ?? [];
$plans    = $data['plans'] ?? [];
$alertData = is_array($alert ?? null) ? $alert : [];
$alertMarginClass = 'mb-4';
$defaultPlanId = (int) ($settings['subscription_default_plan_id'] ?? 0);
$disabledNotice = (string) ($settings['subscription_disabled_notice'] ?? '');
$isChecked = static fn (string $key, string $default = '1'): string => (($settings[$key] ?? $default) === '1') ? 'checked' : '';
$isSelectedPlan = static fn (int $planId): string => $defaultPlanId === $planId ? 'selected' : '';
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Aboverwaltung</div>
                <h2 class="page-title">Einstellungen</h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl cms-settings-page">

        <?php require __DIR__ . '/../partials/flash-alert.php'; ?>

        <div class="cms-admin-info-box mb-4" role="note">
            <div class="cms-admin-info-box__head">
                <h3 class="cms-admin-info-box__title">Abo-Steuerung und Standardzuweisung</h3>
                <div class="cms-admin-info-box__actions">
                    <a href="/admin/packages" class="btn btn-sm btn-outline-secondary">Pakete</a>
                    <a href="/admin/orders" class="btn btn-sm btn-outline-secondary">Bestellungen</a>
                </div>
            </div>
            <p class="cms-admin-info-box__text">Hier werden nur globale Schalter und das Standardverhalten der Aboverwaltung gepflegt. Tarifinhalte und Bestellprozesse bleiben in den Fachbereichen.</p>
        </div>

        <form method="post" data-subscription-submit-lock="1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="cms-settings-actions">
                <span class="text-secondary small me-auto">Globale Abo-Steuerung und Standardzuweisung verwalten.</span>
                <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
            </div>

            <div class="row g-4">
                <div class="col-12">
                    <h3 class="cms-settings-section-heading">Kernsteuerung</h3>
                </div>
                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Betriebsmodus</h3></div>
                        <div class="card-body">
                            <label class="form-check form-switch mb-3">
                                <input type="checkbox" name="subscription_limits_enabled" class="form-check-input" <?= $isChecked('subscription_limits_enabled') ?>>
                                <span class="form-check-label">Abo-Limits systemweit durchsetzen</span>
                            </label>
                            <div class="form-hint mb-3">Wenn deaktiviert, gibt es im gesamten CMS keine Paket-Limits mehr – unabhängig von Zuweisungen oder Planstufen.</div>

                            <label class="form-check form-switch mb-3">
                                <input type="checkbox" name="subscription_member_area_enabled" class="form-check-input" <?= $isChecked('subscription_member_area_enabled') ?>>
                                <span class="form-check-label">Abo-Bereich im Member-Dashboard anzeigen</span>
                            </label>

                            <label class="form-check form-switch mb-3">
                                <input type="checkbox" name="subscription_ordering_enabled" class="form-check-input" <?= $isChecked('subscription_ordering_enabled') ?>>
                                <span class="form-check-label">Bestell- und Upgrade-Prozesse zulassen</span>
                            </label>

                            <label class="form-check form-switch mb-0">
                                <input type="checkbox" name="subscription_public_pricing_enabled" class="form-check-input" <?= $isChecked('subscription_public_pricing_enabled') ?>>
                                <span class="form-check-label">Pakete öffentlich kommunizieren</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Standard-Zuweisung</h3></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="fld-subscription_default_plan_id" class="form-label">Standardpaket für neue Mitglieder</label>
                                <select id="fld-subscription_default_plan_id" name="subscription_default_plan_id" class="form-select">
                                    <option value="0">– Keine automatische Zuweisung –</option>
                                    <?php foreach ($plans as $plan): ?>
                                        <option value="<?= (int)$plan['id'] ?>" <?= $isSelectedPlan((int) $plan['id']) ?>>
                                            <?= htmlspecialchars($plan['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="cms-admin-info-box cms-admin-info-box--warning mb-0" role="note">
                                <div class="cms-admin-info-box__head">
                                    <h3 class="cms-admin-info-box__title">Fachbereiche bleiben getrennt</h3>
                                </div>
                                <p class="cms-admin-info-box__text">Paketdetails, Preise, Testphase, Steuern und Bestellprozesse werden weiterhin direkt in den Bereichen Pakete &amp; Abo-Einstellungen sowie Bestellungen &amp; Zuweisung gepflegt.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Hinweis bei Deaktivierung</h3></div>
                        <div class="card-body">
                            <label for="fld-subscription_disabled_notice" class="form-label">Text für Admin-/Member-Hinweis</label>
                            <textarea id="fld-subscription_disabled_notice" name="subscription_disabled_notice" class="form-control" rows="5"><?= htmlspecialchars($disabledNotice) ?></textarea>
                            <div class="form-hint mt-2">Dieser Text kann für interne Hinweise oder spätere Frontend-Ausgaben verwendet werden, wenn die Aboverwaltung vollständig deaktiviert ist.</div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Orientierung</h3></div>
                        <div class="card-body">
                            <ul class="mb-0 text-secondary">
                                <li><strong>Pakete &amp; Abo-Einstellungen</strong>: Preise, Limits, Trial, Steuern, AGB-Links.</li>
                                <li><strong>Bestellungen &amp; Zuweisung</strong>: Bestellstatus und manuelle Paketzuweisung.</li>
                                <li><strong>Einstellungen</strong>: globaler Schalter, Standardverhalten und komplette Deaktivierung der Limitlogik.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cms-settings-actions cms-settings-actions-bottom">
                <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
            </div>
        </form>

    </div>
</div>
