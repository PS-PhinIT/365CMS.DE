<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

/** @var array $data */
$d               = $data ?? [];
$categories      = $d['categories'] ?? [];
$services        = $d['services'] ?? [];
$settings        = $d['settings'] ?? [];
$consentEnabled  = ($settings['cookie_consent_enabled'] ?? '0') === '1';
$scanResults     = $d['scan_results'] ?? [];
$curatedServices = $d['curated_services'] ?? [];
$cookieSettingsPublicUrl = '/cookie-einstellungen';
$cookieManagerConfig = [
    'categoryModalId' => 'categoryModal',
    'serviceModalId' => 'serviceModal',
];
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Recht &amp; Sicherheit</div>
                <h2 class="page-title mb-1">Cookie-Manager</h2>
                <div class="content-listing-header__meta">
                    <span>Kategorien: <?= count($categories) ?></span>
                    <span>Services: <?= count($services) ?></span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <a href="/admin/legal-sites" class="btn btn-outline-secondary btn-sm">Legal Sites</a>
                <a href="/cookie-einstellungen" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener noreferrer">Öffentliche Seite</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-cookie-manager-page admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php
        $alertData = $alert ?? [];
        $alertDismissible = false;
        $alertMarginClass = 'mb-4';
        require __DIR__ . '/../partials/flash-alert.php';
        ?>

        <div class="row row-deck row-cards mb-4 admin-metric-grid">
            <div class="col-sm-6 col-lg-3">
                <div class="card"><div class="card-body"><div class="subheader">Kategorien</div><div class="h1 mb-0"><?= count($categories) ?></div></div></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card"><div class="card-body"><div class="subheader">Services</div><div class="h1 mb-0"><?= count($services) ?></div></div></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card"><div class="card-body"><div class="subheader">Scanner-Treffer</div><div class="h1 mb-0"><?= count($scanResults) ?></div></div></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card admin-consent-kpi <?php echo $consentEnabled ? '' : 'admin-consent-kpi--inactive'; ?>">
                    <div class="card-body">
                        <div class="subheader">Consent aktiv</div>
                        <div class="h1 mb-0 <?php echo $consentEnabled ? '' : 'admin-consent-kpi__value--warning'; ?>">
                            <?php echo $consentEnabled ? 'Ja' : 'Nein'; ?>
                        </div>
                        <?php if (!$consentEnabled): ?>
                            <div class="admin-consent-kpi__subtitle">Cookie-Consent deaktiviert — DSGVO-Pflicht prüfen</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-deck row-cards mb-4">
            <div class="col-lg-6">
                <div class="card admin-content-card">
                    <div class="card-header">
                        <h3 class="card-title">Consent &amp; Banner</h3>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                            <input type="hidden" name="action" value="save_settings">

                            <label class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="cookie_banner_enabled" value="1" <?php echo ($settings['cookie_consent_enabled'] ?? $settings['cookie_banner_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <span class="form-check-label">Cookie-Consent aktivieren</span>
                            </label>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Position</label>
                                    <select name="cookie_banner_position" class="form-select">
                                        <?php foreach (['bottom' => 'Unten', 'top' => 'Oben', 'center' => 'Zentriert (Modal)'] as $v => $l): ?>
                                            <option value="<?php echo $v; ?>" <?php echo ($settings['cookie_banner_position'] ?? 'bottom') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Stil</label>
                                    <select name="cookie_banner_style" class="form-select">
                                        <?php foreach (['dark' => 'Dunkel', 'light' => 'Hell', 'custom' => 'Benutzerdefiniert'] as $v => $l): ?>
                                            <option value="<?php echo $v; ?>" <?php echo ($settings['cookie_banner_style'] ?? 'dark') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <?php $cookiePrimaryColor = preg_match('/^#[a-f0-9]{6}$/i', (string)($settings['cookie_primary_color'] ?? '')) === 1 ? (string)$settings['cookie_primary_color'] : '#3b82f6'; ?>
                                    <label class="form-label" for="cookie-primary-color">Akzentfarbe (Buttons)</label>
                                    <input type="color" id="cookie-primary-color" name="cookie_primary_color" class="form-control form-control-color" value="<?php echo htmlspecialchars($cookiePrimaryColor); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Akzeptieren-Button</label>
                                    <input type="text" name="cookie_accept_text" class="form-control" value="<?php echo htmlspecialchars($settings['cookie_accept_text'] ?? 'Akzeptieren'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Ablehnen-Button</label>
                                    <input type="text" name="cookie_reject_text" class="form-control" value="<?php echo htmlspecialchars($settings['cookie_reject_text'] ?? 'Ablehnen'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Nur Essenzielle-Button</label>
                                    <input type="text" name="cookie_essential_text" class="form-control" value="<?php echo htmlspecialchars($settings['cookie_essential_text'] ?? 'Nur Essenzielle'); ?>">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Datenschutz-URL</label>
                                    <input type="text" name="cookie_policy_url" class="form-control" value="<?php echo htmlspecialchars($settings['cookie_policy_url'] ?? '/datenschutz'); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Cookie-Laufzeit (Tage)</label>
                                    <input type="number" name="cookie_lifetime_days" class="form-control" min="1" max="365" value="<?php echo (int)($settings['cookie_lifetime_days'] ?: 30); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Banner-Text</label>
                                    <textarea name="cookie_banner_text" class="form-control" rows="3"><?php echo htmlspecialchars($settings['cookie_banner_text'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <div class="alert alert-info mb-0">
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                                            <div>
                                                <div class="fw-bold mb-1">Öffentliche Consent-Seite</div>
                                                <div class="small">
                                                    Nutzer können ihre Einwilligung öffentlich unter
                                                    <a href="<?php echo htmlspecialchars($cookieSettingsPublicUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($cookieSettingsPublicUrl, ENT_QUOTES, 'UTF-8'); ?></a>
                                                    einsehen und anpassen.
                                                </div>
                                            </div>
                                            <a href="<?php echo htmlspecialchars($cookieSettingsPublicUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
                                                Public Bereich öffnen
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <div class="row g-3">
                                <div class="col-12">
                                    <h4 class="card-title mb-1">Matomo Self-Hosted</h4>
                                    <div class="text-secondary small">Optional für DSGVO-Transparenz auf der öffentlichen Consent-Seite. Ideal, wenn Matomo auf eigener Infrastruktur betrieben wird.</div>
                                </div>
                                <div class="col-12">
                                    <div class="alert alert-azure mb-0">
                                        <div class="fw-bold mb-1">Mehr Kontrolle für Matomo</div>
                                        <div class="small text-secondary">
                                            Diese Werte werden auf der öffentlichen Seite <code>/cookie-einstellungen</code> angezeigt und helfen dabei, eure Matomo-Konfiguration für Besucher nachvollziehbarer zu dokumentieren.
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Matomo-URL</label>
                                    <input type="url" name="cookie_matomo_self_hosted_url" class="form-control" placeholder="https://analytics.deine-domain.de/" value="<?php echo htmlspecialchars($settings['cookie_matomo_self_hosted_url'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Site-ID</label>
                                    <input type="text" name="cookie_matomo_site_id" class="form-control" placeholder="z. B. 1" value="<?php echo htmlspecialchars(($settings['cookie_matomo_site_id'] ?? '') !== '' ? $settings['cookie_matomo_site_id'] : '1'); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Hosting-Region</label>
                                    <input type="text" name="cookie_matomo_hosting_region" class="form-control" value="<?php echo htmlspecialchars($settings['cookie_matomo_hosting_region'] ?? 'Deutschland / EU'); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-check form-switch mt-4">
                                        <input class="form-check-input" type="checkbox" name="cookie_matomo_ip_anonymization" value="1" <?php echo ($settings['cookie_matomo_ip_anonymization'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                        <span class="form-check-label">IP-Anonymisierung aktiv</span>
                                    </label>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-check form-switch mt-4">
                                        <input class="form-check-input" type="checkbox" name="cookie_matomo_respect_dnt" value="1" <?php echo ($settings['cookie_matomo_respect_dnt'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Browser-Do-Not-Track respektieren</span>
                                    </label>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-check form-switch mt-4">
                                        <input class="form-check-input" type="checkbox" name="cookie_matomo_disable_cookies" value="1" <?php echo ($settings['cookie_matomo_disable_cookies'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Matomo-Cookies deaktivieren / cookielos</span>
                                    </label>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Log-Löschung / Aufbewahrung (Tage)</label>
                                    <input type="number" name="cookie_matomo_log_retention_days" class="form-control" min="1" max="3650" value="<?php echo (int)(($settings['cookie_matomo_log_retention_days'] ?? '') !== '' ? $settings['cookie_matomo_log_retention_days'] : 180); ?>">
                                    <div class="form-hint">Beispiel: 180 für 6 Monate oder 365 für 1 Jahr.</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-light-lt h-100">
                                        <div class="card-body">
                                            <div class="fw-semibold mb-2">Empfohlene Transparenzangaben</div>
                                            <details class="admin-matomo-params">
                                                <summary>Dokumentierte Parameter ▸</summary>
                                                <ul class="mb-0 mt-2 text-secondary small ps-3">
                                                    <li>Self-Hosted-URL und Site-ID</li>
                                                    <li>Hosting-Region / EU-Bezug</li>
                                                    <li>IP-Anonymisierung</li>
                                                    <li>Do-Not-Track / cookieloser Betrieb</li>
                                                    <li>klare Log-Aufbewahrungsdauer</li>
                                                </ul>
                                            </details>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Zusätzlicher DSGVO-Hinweis</label>
                                    <textarea name="cookie_matomo_dsgvo_note" class="form-control" rows="4" placeholder="Optionaler Hinweis für Besucher, z. B. zur Auftragsverarbeitung oder internen Richtlinien."><?php echo htmlspecialchars($settings['cookie_matomo_dsgvo_note'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card h-100 admin-content-card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title">Cookie-Scanner</h3>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                            <input type="hidden" name="action" value="run_scan">
                            <button type="submit" class="btn btn-primary btn-sm">Scan starten</button>
                        </form>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary">Der Scanner durchsucht Theme-, Include- und JS-Dateien sowie ausgewählte Inhalte in Seiten und Einstellungen nach typischen Signaturen für Analyse-, Marketing- und Medien-Dienste.</p>
                        <div class="text-secondary small mb-3">
                            Letzter Lauf: <strong><?php echo htmlspecialchars($settings['cookie_scan_last_run'] ?? 'Noch nie'); ?></strong>
                        </div>
                        <?php if (empty($scanResults)): ?>
                            <div class="text-secondary">Noch keine Scanner-Ergebnisse vorhanden.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($scanResults as $scan): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($scan['name'] ?? $scan['slug'] ?? 'Service'); ?></div>
                                                <div class="text-secondary small"><?php echo htmlspecialchars($scan['provider'] ?? ''); ?> · Kategorie: <?php echo htmlspecialchars($scan['category_slug'] ?? ''); ?></div>
                                                <?php if (!empty($scan['sources'])): ?>
                                                    <div class="text-secondary small mt-1"><?php echo htmlspecialchars(implode(' · ', array_slice((array)$scan['sources'], 0, 3))); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                                                <input type="hidden" name="action" value="import_curated_service">
                                                <input type="hidden" name="service_slug" value="<?php echo htmlspecialchars((string)($scan['slug'] ?? '')); ?>">
                                                <input type="hidden" name="service_name" value="<?php echo htmlspecialchars((string)($scan['name'] ?? '')); ?>">
                                                <input type="hidden" name="service_provider" value="<?php echo htmlspecialchars((string)($scan['provider'] ?? '')); ?>">
                                                <input type="hidden" name="category_slug" value="<?php echo htmlspecialchars((string)($scan['category_slug'] ?? 'necessary')); ?>">
                                                <button type="submit" class="btn btn-outline-primary btn-sm">Übernehmen</button>
                                            </form>
                                            <?php if (!empty($scan['self_hostable']) && ($scan['slug'] ?? '') === 'matomo'): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                                                    <input type="hidden" name="action" value="import_curated_service">
                                                    <input type="hidden" name="service_slug" value="matomo">
                                                    <input type="hidden" name="service_name" value="<?php echo htmlspecialchars((string)($scan['name'] ?? 'Matomo')); ?>">
                                                    <input type="hidden" name="service_provider" value="<?php echo htmlspecialchars((string)($scan['provider'] ?? 'Matomo')); ?>">
                                                    <input type="hidden" name="category_slug" value="<?php echo htmlspecialchars((string)($scan['category_slug'] ?? 'analytics')); ?>">
                                                    <input type="hidden" name="self_hosted" value="1">
                                                    <button type="submit" class="btn btn-outline-success btn-sm">Self-Hosted → essenziell</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-deck row-cards mb-4">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title">Standard-Services</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>Kategorie</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($curatedServices as $slug => $service): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($service['name']); ?></div>
                                            <div class="text-secondary small"><?php echo htmlspecialchars($service['provider']); ?></div>
                                        </td>
                                        <td><span class="badge bg-azure-lt"><?php echo htmlspecialchars($service['category_slug']); ?></span></td>
                                        <td>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                                                <input type="hidden" name="action" value="import_curated_service">
                                                <input type="hidden" name="service_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Hinzufügen</button>
                                            </form>
                                            <?php if ($slug === 'matomo'): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                                                    <input type="hidden" name="action" value="import_curated_service">
                                                    <input type="hidden" name="service_slug" value="matomo">
                                                    <input type="hidden" name="self_hosted" value="1">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">Self-Hosted</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title">Kategorien</h3>
                        <button type="button" class="btn btn-primary btn-sm js-cookie-category-create">Kategorie hinzufügen</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Pflicht</th>
                                    <th>Status</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($cat['name']); ?></td>
                                        <td><code><?php echo htmlspecialchars($cat['slug']); ?></code></td>
                                        <td><?php echo (int)$cat['is_required'] === 1 ? '<span class="badge bg-blue">Pflicht</span>' : '<span class="badge bg-secondary">Optional</span>'; ?></td>
                                        <td><?php echo (int)$cat['is_active'] === 1 ? '<span class="badge bg-success">Aktiv</span>' : '<span class="badge bg-secondary">Inaktiv</span>'; ?></td>
                                        <td>
                                            <div class="dropdown">
                                                <button class="btn btn-ghost-secondary btn-icon btn-sm" data-bs-toggle="dropdown"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></svg></button>
                                                <div class="dropdown-menu dropdown-menu-end">
                                                    <button type="button" class="dropdown-item js-cookie-category-edit" data-cookie-category="<?php echo htmlspecialchars((string) json_encode($cat, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES); ?>">Bearbeiten</button>
                                                    <?php if (!(int)$cat['is_required']): ?>
                                                        <form method="post" class="d-inline" data-confirm-message="Wirklich löschen?" data-confirm-title="Kategorie löschen" data-confirm-text="Löschen" data-confirm-class="btn-danger" data-confirm-status-class="bg-danger">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                                                            <input type="hidden" name="action" value="delete_category">
                                                            <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                                            <button type="submit" class="dropdown-item text-danger">Löschen</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-cards admin-cookie-services-layout">
            <div class="col-12 col-xxl-9">
                <div class="card admin-content-card">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                        <h3 class="card-title">Konfigurierte Services</h3>
                        <button type="button" class="btn btn-primary btn-sm js-cookie-service-create">Service hinzufügen</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>Kategorie</th>
                                    <th>Status</th>
                                    <th>Cookie-Namen</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($services)): ?>
                                    <tr><td colspan="5" class="text-center text-secondary py-4">Noch keine Services konfiguriert.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($services as $service): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($service['name']); ?></div>
                                                <div class="text-secondary small"><?php echo htmlspecialchars($service['provider'] ?? ''); ?></div>
                                            </td>
                                            <td><span class="badge bg-azure-lt"><?php echo htmlspecialchars($service['category_slug']); ?></span></td>
                                            <td>
                                                <?php if ((int)$service['is_essential'] === 1): ?>
                                                    <span class="badge bg-blue">Essenziell</span>
                                                <?php elseif ((int)$service['is_active'] === 1): ?>
                                                    <span class="badge bg-success">Aktiv</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inaktiv</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-secondary small"><?php echo htmlspecialchars((string)($service['cookie_names'] ?? '')); ?></td>
                                            <td>
                                                <div class="dropdown">
                                                    <button class="btn btn-ghost-secondary btn-icon btn-sm" data-bs-toggle="dropdown"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></svg></button>
                                                    <div class="dropdown-menu dropdown-menu-end">
                                                        <button type="button" class="dropdown-item js-cookie-service-edit" data-cookie-service="<?php echo htmlspecialchars((string) json_encode($service, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES); ?>">Bearbeiten</button>
                                                        <?php if (!(int)$service['is_essential']): ?>
                                                            <form method="post" class="d-inline" data-confirm-message="Service wirklich löschen?" data-confirm-title="Service löschen" data-confirm-text="Löschen" data-confirm-class="btn-danger" data-confirm-status-class="bg-danger">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                                                                <input type="hidden" name="action" value="delete_service">
                                                                <input type="hidden" name="id" value="<?php echo (int)$service['id']; ?>">
                                                                <button type="submit" class="dropdown-item text-danger">Löschen</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xxl-3">
                <div class="card h-100 admin-content-card">
                    <div class="card-header">
                        <h3 class="card-title">Arbeitsweise</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-secondary small mb-3">
                            Pflege Kategorien zuerst und ordne Services danach eindeutig zu. Essenzielle Services bleiben immer aktiv.
                        </div>
                        <a href="<?php echo htmlspecialchars($cookieSettingsPublicUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm w-100">
                            Consent-Seite öffnen
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Kategorie Modal -->
<div class="modal modal-blur fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                <input type="hidden" name="action" value="save_category">
                <input type="hidden" name="category_id" id="catId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="catModalTitle">Kategorie hinzufügen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="category_name" id="catName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input type="text" name="category_slug" id="catSlug" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Beschreibung</label>
                        <textarea name="category_description" id="catDesc" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Scripts (werden bei Zustimmung geladen)</label>
                        <textarea name="category_scripts" id="catScripts" class="form-control" rows="3" placeholder="<script src='...'></script>"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reihenfolge</label>
                        <input type="number" name="sort_order" id="catOrder" class="form-control" value="0">
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <label class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_required" id="catRequired">
                                <span class="form-check-label">Pflicht</span>
                            </label>
                        </div>
                        <div class="col-6">
                            <label class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="catActive" checked>
                                <span class="form-check-label">Aktiv</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn me-auto" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="serviceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                <input type="hidden" name="action" value="save_service">
                <input type="hidden" name="service_id" id="serviceId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="serviceModalTitle">Service hinzufügen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name</label>
                            <input type="text" name="service_name" id="serviceName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Slug</label>
                            <input type="text" name="service_slug" id="serviceSlug" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Anbieter</label>
                            <input type="text" name="service_provider" id="serviceProvider" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kategorie</label>
                            <select name="category_slug" id="serviceCategory" class="form-select">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['slug']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Beschreibung</label>
                            <textarea name="service_description" id="serviceDescription" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Cookie-Namen</label>
                            <input type="text" name="cookie_names" id="serviceCookies" class="form-control" placeholder="_ga, _gid, li_fat_id">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Code-Snippet / Einbettung</label>
                            <textarea name="code_snippet" id="serviceCode" class="form-control" rows="4" placeholder="<script src='...'></script>"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_essential" id="serviceEssential">
                                <span class="form-check-label">Essenziell (nie blockieren)</span>
                            </label>
                        </div>
                        <div class="col-md-6">
                            <label class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="serviceActive" checked>
                                <span class="form-check-label">Aktiv</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn me-auto" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script type="application/json" id="cookie-manager-config"><?php echo json_encode($cookieManagerConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
