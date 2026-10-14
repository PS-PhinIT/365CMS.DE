<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

/** @var array $data */
$d         = $data ?? [];
$legalSitesAdminBaseUrl = '/admin/legal-sites';
$pages     = $d['pages'] ?? [];
$assigned  = $d['assigned_pages'] ?? [];
$allPages  = $d['all_pages'] ?? [];
$tabKeys   = ['legal_imprint', 'legal_privacy', 'legal_terms', 'legal_revocation'];
$tabIcons  = [
    'legal_imprint'    => '<svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M9 9l1 0"/><path d="M9 13l6 0"/><path d="M9 17l6 0"/></svg>',
    'legal_privacy'    => '<svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"/></svg>',
    'legal_terms'      => '<svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M5 8v-3a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2h-5"/><path d="M6 14m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0"/><path d="M4.5 17l-1.5 5l3 -1.5l3 1.5l-1.5 -5"/></svg>',
    'legal_revocation' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 14l-4 -4l4 -4"/><path d="M5 10h11a4 4 0 1 1 0 8h-1"/></svg>',
];
$pageIdKeys = ['legal_imprint' => 'imprint_page_id', 'legal_privacy' => 'privacy_page_id', 'legal_terms' => 'terms_page_id', 'legal_revocation' => 'revocation_page_id'];
$templateTypes = ['legal_imprint' => 'imprint', 'legal_privacy' => 'privacy', 'legal_terms' => 'terms', 'legal_revocation' => 'revocation'];
$templateDefaults = $d['templates'] ?? [];
$templateProfiles = is_array($d['template_profiles'] ?? null) ? $d['template_profiles'] : [];
$templateMeta = is_array($d['template_meta'] ?? null) ? $d['template_meta'] : [];
$profile = $d['profile'] ?? [];
$activeTemplateProfile = (string)($profile['legal_template_profile'] ?? 'dach_de');
$stats = is_array($d['stats'] ?? null) ? $d['stats'] : [];
$constraints = is_array($d['constraints'] ?? null) ? $d['constraints'] : [];
$allowedTemplateTypes = array_values(array_filter(array_map('strval', is_array($constraints['allowed_template_types'] ?? null) ? $constraints['allowed_template_types'] : [])));
$legalSitesConfig = [
    'insertTemplateTitle' => 'Vorlage einfügen?',
    'insertTemplateMessage' => 'Vorhandener Inhalt wird überschrieben.',
    'insertTemplateConfirmText' => 'Einfügen',
    'companyHint' => 'Firmenprofil aktiv: Firma und Rechtsform sind Pflichtfelder. Register- und USt.-Angaben sind für das Impressum empfohlen.',
    'privateHint' => 'Privatprofil aktiv: Vor- und Nachname ist Pflicht. Firmenbezogene Felder bleiben optional und können leer bleiben.',
];
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Recht</div>
                <h2 class="page-title mb-1">Legal Sites</h2>
                <div class="content-listing-header__meta">
                    <span>Bereiche: <?php echo (int) ($stats['areas'] ?? count($tabKeys)); ?></span>
                    <span>Zugewiesen: <?php echo (int) ($stats['assigned_pages'] ?? count(array_filter($assigned))); ?></span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <a href="/admin/cookie-manager" class="btn btn-outline-secondary btn-sm">Cookie-Manager</a>
                <a href="/admin/data-requests" class="btn btn-outline-primary btn-sm">Auskunft &amp; Löschen</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-legal-sites-page admin-redesign-page">
    <div class="container-xl admin-legal-sites-layout admin-redesign-shell">
        <div class="admin-legal-sites-global-save mb-3">
            <button type="button" class="btn btn-primary" id="js-legal-sites-save-all">
                Alle Standardwerte speichern &amp; Dokumente aktualisieren
            </button>
        </div>

        <div class="row row-deck row-cards mb-4 admin-metric-grid">
            <div class="col-md-4">
                <div class="card"><div class="card-body"><div class="subheader">Bereiche</div><div class="h1 mb-0"><?php echo (int) ($stats['areas'] ?? count($tabKeys)); ?></div></div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body"><div class="subheader">Zugewiesene Seiten</div><div class="h1 mb-0"><?php echo (int) ($stats['assigned_pages'] ?? count(array_filter($assigned))); ?></div></div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body"><div class="subheader">Veröffentlicht</div><div class="text-secondary">Impressum, Datenschutz, AGB und Widerruf zentral pflegen und vorhandenen Seiten zuordnen.</div><div class="small text-muted mt-2"><?php echo (int) ($stats['published_pages'] ?? count($allPages)); ?> veröffentlichte Zielseiten · <?php echo (int) ($stats['filled_areas'] ?? 0); ?> Bereiche mit Inhalt</div></div></div>
            </div>
        </div>

        <?php
        $alertData = [
            'type' => 'info',
            'message' => 'Hinterlege einmal die Standardwerte deines Unternehmens und erzeuge daraus passende Rechtstext-Seiten.',
            'details' => [
                'Das spart Klicks und vermeidet Platzhalter-Chaos.',
                'Impressum und Datenschutz landen damit deutlich näher bei „fertig statt fragwürdig“.',
            ],
        ];
        $alertDismissible = false;
        $alertMarginClass = 'mb-4';
        require __DIR__ . '/../partials/flash-alert.php';
        ?>
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <?php if (!empty($constraints['max_legal_html_length']) || !empty($constraints['max_profile_value_length'])): ?>
            <?php
            $alertData = [
                'type' => 'secondary',
                'message' => 'Legal-Sites nutzt sichere Eingabegrenzen für Text- und HTML-Felder.',
                'details' => array_values(array_filter([
                    !empty($constraints['max_legal_html_length']) ? 'HTML pro Bereich: max. ' . number_format((int) $constraints['max_legal_html_length'], 0, ',', '.') . ' Zeichen' : '',
                    !empty($constraints['max_profile_value_length']) ? 'Kurze Profilfelder: max. ' . number_format((int) $constraints['max_profile_value_length'], 0, ',', '.') . ' Zeichen' : '',
                    !empty($constraints['max_profile_textarea_length']) ? 'Längere Profilfelder: max. ' . number_format((int) $constraints['max_profile_textarea_length'], 0, ',', '.') . ' Zeichen' : '',
                    !empty($constraints['template_version']) ? 'Vorlagenversion: ' . htmlspecialchars((string) $constraints['template_version']) : '',
                    !empty($constraints['template_profile_count']) ? 'DACH-Profile: ' . (int) $constraints['template_profile_count'] : '',
                    !empty($constraints['template_area_count']) ? 'Generator-Bereiche: ' . (int) $constraints['template_area_count'] : '',
                    $allowedTemplateTypes !== [] ? 'Vorlagentypen: ' . htmlspecialchars(implode(', ', $allowedTemplateTypes)) : '',
                    !empty($constraints['profile_toggle_count']) ? 'Feature-Toggles: ' . (int) $constraints['profile_toggle_count'] : '',
                ])),
            ];
            $alertDismissible = false;
            $alertMarginClass = 'mb-4';
            require __DIR__ . '/../partials/flash-alert.php';
            ?>
        <?php endif; ?>

        <div class="card mb-4 admin-content-card">
            <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div>
                    <h3 class="card-title mb-1">Standardwerte & Generator</h3>
                    <div class="text-secondary small">Diese Angaben werden für Impressum, Datenschutz, AGB und Widerrufsbelehrung verwendet.</div>
                    <?php if (!empty($constraints['template_area_count']) || $allowedTemplateTypes !== []): ?>
                        <div class="text-muted small mt-1">
                            <?php if (!empty($constraints['template_area_count'])): ?>
                                <?php echo (int) $constraints['template_area_count']; ?> Generator-Bereiche
                            <?php endif; ?>
                            <?php if (!empty($constraints['template_area_count']) && $allowedTemplateTypes !== []): ?>
                                ·
                            <?php endif; ?>
                            <?php if ($allowedTemplateTypes !== []): ?>
                                Typen: <?php echo htmlspecialchars(implode(', ', $allowedTemplateTypes)); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?php echo htmlspecialchars($legalSitesAdminBaseUrl, ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                    <input type="hidden" name="action" value="create_all_pages">
                    <button type="submit" class="btn btn-primary text-wrap">Alle Rechtstext-Seiten erstellen/aktualisieren</button>
                </form>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo htmlspecialchars($legalSitesAdminBaseUrl, ENT_QUOTES, 'UTF-8'); ?>" data-legal-bulk-save-part="profile">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                    <input type="hidden" name="action" value="save_profile">

                    <ul class="nav nav-tabs card-header-tabs legal-sites-profile-tabs mb-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" type="button" data-legal-profile-tab="profile">Unternehmensprofil</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" type="button" data-legal-profile-tab="contact">Adresse &amp; Kontakt</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" type="button" data-legal-profile-tab="register">Register &amp; Pflichtangaben</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" type="button" data-legal-profile-tab="privacy">Datenschutz-Setup</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" type="button" data-legal-profile-tab="features">Website-Funktionen</button>
                        </li>
                    </ul>

                    <div class="row g-3 mb-4 legal-sites-profile-sections">
                        <div class="col-12" data-legal-profile-section="profile">
                            <div class="border rounded p-3 bg-light-subtle">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-4">
                                        <label for="legalProfileEntityType" class="form-label">Profiltyp</label>
                                        <select name="legal_profile_entity_type" id="legalProfileEntityType" class="form-select">
                                            <option value="company" <?php echo ($profile['legal_profile_entity_type'] ?? 'company') === 'company' ? 'selected' : ''; ?>>Firma</option>
                                            <option value="private" <?php echo ($profile['legal_profile_entity_type'] ?? 'company') === 'private' ? 'selected' : ''; ?>>Privat</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_template_profile" class="form-label">DACH-Vorlagenprofil</label>
                                        <select id="fld-legal_template_profile" name="legal_template_profile" class="form-select">
                                            <?php foreach ($templateProfiles as $profileKey => $templateProfile): ?>
                                                <option value="<?php echo htmlspecialchars((string) $profileKey); ?>" <?php echo $activeTemplateProfile === (string) $profileKey ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string)($templateProfile['label'] ?? $profileKey)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if ($templateProfiles === []): ?>
                                                <option value="dach_de" selected>Deutschland · DACH-Basis</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-secondary small pt-md-4" id="legalProfileEntityHint">Wähle aus, ob die Rechtstexte für eine Firma oder eine Privatperson erstellt werden. Die passenden Pflichtfelder werden automatisch gesetzt.</div>
                                    </div>
                                    <div class="col-12">
                                        <div class="text-secondary small">DACH-Profile liefern versionierte, technische Grundgerüste für Impressum, Datenschutz, Widerruf und AGB-Skelett. Sie ersetzen keine rechtliche Prüfung – sie verhindern nur das berüchtigte „leeres Textfeld und viel Hoffnung“-Problem.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6" data-legal-profile-section="profile">
                            <div class="border rounded p-3 h-100 bg-light-subtle">
                                <div class="subheader mb-2">Unternehmen</div>
                                <div class="row g-3">
                                    <div class="col-md-6" data-required-for="company">
                                        <label for="fld-legal_profile_company_name" class="form-label">Firma / Name <span class="text-danger js-required-indicator">*</span></label>
                                        <input id="fld-legal_profile_company_name" type="text" name="legal_profile_company_name" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_company_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6" data-required-for="company">
                                        <label for="fld-legal_profile_legal_form" class="form-label">Rechtsform <span class="text-danger js-required-indicator">*</span></label>
                                        <input id="fld-legal_profile_legal_form" type="text" name="legal_profile_legal_form" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_legal_form'] ?? ''); ?>" placeholder="z. B. GmbH, e.K., Einzelunternehmen">
                                    </div>
                                    <div class="col-md-6" data-required-for="private">
                                        <label for="fld-legal_profile_owner_name" class="form-label">Vor- und Nachname <span class="text-danger js-required-indicator">*</span></label>
                                        <input id="fld-legal_profile_owner_name" type="text" name="legal_profile_owner_name" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_owner_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="fld-legal_profile_managing_director" class="form-label">Geschäftsführung</label>
                                        <input id="fld-legal_profile_managing_director" type="text" name="legal_profile_managing_director" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_managing_director'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label for="fld-legal_profile_content_responsible" class="form-label">Inhaltlich verantwortlich</label>
                                        <input id="fld-legal_profile_content_responsible" type="text" name="legal_profile_content_responsible" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_content_responsible'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6" data-legal-profile-section="contact">
                            <div class="border rounded p-3 h-100 bg-light-subtle">
                                <div class="subheader mb-2">Adresse & Kontakt</div>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label for="fld-legal_profile_street" class="form-label">Straße / Hausnummer <span class="text-danger">*</span></label>
                                        <input id="fld-legal_profile_street" type="text" name="legal_profile_street" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_street'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_postal_code" class="form-label">PLZ <span class="text-danger">*</span></label>
                                        <input id="fld-legal_profile_postal_code" type="text" name="legal_profile_postal_code" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_postal_code'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_city" class="form-label">Ort <span class="text-danger">*</span></label>
                                        <input id="fld-legal_profile_city" type="text" name="legal_profile_city" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_city'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_country" class="form-label">Land <span class="text-danger">*</span></label>
                                        <input id="fld-legal_profile_country" type="text" name="legal_profile_country" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_country'] ?? 'Deutschland'); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_email" class="form-label">E-Mail <span class="text-danger">*</span></label>
                                        <input id="fld-legal_profile_email" type="email" name="legal_profile_email" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_email'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_phone" class="form-label">Telefon</label>
                                        <input id="fld-legal_profile_phone" type="text" name="legal_profile_phone" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_phone'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_website" class="form-label">Website</label>
                                        <input id="fld-legal_profile_website" type="url" name="legal_profile_website" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_website'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6" data-legal-profile-section="register">
                            <div class="border rounded p-3 h-100 bg-light-subtle">
                                <div class="subheader mb-2">Register & Pflichtangaben</div>
                                <div class="row g-3">
                                    <div class="col-md-6" data-recommended-for="company">
                                        <label for="fld-legal_profile_register_court" class="form-label">Registergericht</label>
                                        <input id="fld-legal_profile_register_court" type="text" name="legal_profile_register_court" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_register_court'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6" data-recommended-for="company">
                                        <label for="fld-legal_profile_register_number" class="form-label">Registernummer</label>
                                        <input id="fld-legal_profile_register_number" type="text" name="legal_profile_register_number" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_register_number'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6" data-recommended-for="company">
                                        <label for="fld-legal_profile_vat_id" class="form-label">USt.-ID</label>
                                        <input id="fld-legal_profile_vat_id" type="text" name="legal_profile_vat_id" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_vat_id'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6" data-legal-profile-section="privacy">
                            <div class="border rounded p-3 h-100 bg-light-subtle">
                                <div class="subheader mb-2">Datenschutz-Setup</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="fld-legal_profile_hosting_provider" class="form-label">Hosting-Anbieter</label>
                                        <input id="fld-legal_profile_hosting_provider" type="text" name="legal_profile_hosting_provider" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_hosting_provider'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="fld-legal_profile_hosting_address" class="form-label">Hosting-Adresse</label>
                                        <input id="fld-legal_profile_hosting_address" type="text" name="legal_profile_hosting_address" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_hosting_address'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="fld-legal_profile_privacy_contact_name" class="form-label">Datenschutz-Kontakt</label>
                                        <input id="fld-legal_profile_privacy_contact_name" type="text" name="legal_profile_privacy_contact_name" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_privacy_contact_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="fld-legal_profile_privacy_contact_email" class="form-label">Datenschutz-E-Mail</label>
                                        <input id="fld-legal_profile_privacy_contact_email" type="email" name="legal_profile_privacy_contact_email" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_privacy_contact_email'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="fld-legal_profile_analytics_name" class="form-label">Analyse-Tool</label>
                                        <input id="fld-legal_profile_analytics_name" type="text" name="legal_profile_analytics_name" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_analytics_name'] ?? ''); ?>" placeholder="z. B. Matomo, GA4">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="fld-legal_profile_payment_providers" class="form-label">Zahlungsanbieter</label>
                                        <input id="fld-legal_profile_payment_providers" type="text" name="legal_profile_payment_providers" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_payment_providers'] ?? ''); ?>" placeholder="z. B. Stripe, PayPal">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-check mb-2">
                                            <input type="checkbox" class="form-check-input" name="legal_profile_minimal_privacy_mode" value="1" <?php echo ($profile['legal_profile_minimal_privacy_mode'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                            <span class="form-check-label">Reduziertes Datenschutz-/Cookie-Setup verwenden</span>
                                        </label>
                                        <div class="text-secondary small">Geeignet, wenn kein aktiver Cookie-Manager benötigt wird und nur technisch notwendige Cookies bzw. sehr wenige technische Verbindungen eingesetzt werden.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_essential_cookie_name" class="form-label">Notwendiges Cookie</label>
                                        <input id="fld-legal_profile_essential_cookie_name" type="text" name="legal_profile_essential_cookie_name" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_essential_cookie_name'] ?? 'PHPSESSID'); ?>" placeholder="z. B. PHPSESSID">
                                    </div>
                                    <div class="col-md-8">
                                        <label for="fld-legal_profile_essential_cookie_purpose" class="form-label">Zweck des Cookies</label>
                                        <input id="fld-legal_profile_essential_cookie_purpose" type="text" name="legal_profile_essential_cookie_purpose" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_essential_cookie_purpose'] ?? ''); ?>" placeholder="z. B. Speichert Sitzungs- und Sicherheitseinstellungen">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_additional_service_name" class="form-label">Zusätzlicher technischer Dienst</label>
                                        <input id="fld-legal_profile_additional_service_name" type="text" name="legal_profile_additional_service_name" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_additional_service_name'] ?? ''); ?>" placeholder="z. B. CDN, Karten-API, Video-Dienst">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_additional_service_provider" class="form-label">Anbieter / Zielsystem</label>
                                        <input id="fld-legal_profile_additional_service_provider" type="text" name="legal_profile_additional_service_provider" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_additional_service_provider'] ?? ''); ?>" placeholder="z. B. eigener Server, Cloudflare, Vimeo">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_additional_service_purpose" class="form-label">Zweck der Verbindung</label>
                                        <input id="fld-legal_profile_additional_service_purpose" type="text" name="legal_profile_additional_service_purpose" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_additional_service_purpose'] ?? ''); ?>" placeholder="z. B. Auslieferung statischer Assets oder Video-Embed">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12" data-legal-profile-section="features">
                            <div class="border rounded p-3 bg-light-subtle">
                                <div class="subheader mb-2">Funktionsbezogene Datenschutz- &amp; Impressum-Angaben</div>
                                <div class="text-secondary small mb-3">Sobald du unter „Aktive Website-Funktionen“ etwas aktivierst, erscheinen hier passende Datenschutz- und Impressum-Bausteine sowie Zusatzangaben.</div>

                                <div class="row g-3" id="legalPrivacyFeatureDetails">
                                    <div class="col-12 d-none" data-privacy-feature="legal_profile_has_cookies">
                                        <div class="border rounded p-3 bg-white">
                                            <div class="fw-semibold mb-2">Cookie-/Consent-Banner</div>
                                            <div class="text-secondary small">Wenn optionale Cookies oder Dienste eingesetzt werden, sollte der Datenschutztext auf Einwilligungsverwaltung und Widerrufsmöglichkeit verweisen. Für rein technisch notwendige Cookies kannst du alternativ das reduzierte Datenschutz-Setup oben verwenden.</div>
                                        </div>
                                    </div>

                                    <div class="col-12 d-none" data-privacy-feature="legal_profile_has_contact_form">
                                        <?php
                                        $alertData = [
                                            'type' => 'secondary',
                                            'message' => 'Für das Kontaktformular wird im Datenschutztext automatisch ein Eintrag zur Anfragebearbeitung aufgenommen.',
                                        ];
                                        $alertDismissible = false;
                                        $alertMarginClass = 'mb-0';
                                        require __DIR__ . '/../partials/flash-alert.php';
                                        ?>
                                    </div>

                                    <div class="col-12 d-none" data-privacy-feature="legal_profile_has_registration">
                                        <?php
                                        $alertData = [
                                            'type' => 'secondary',
                                            'message' => 'Für Registrierung und Benutzerkonten wird automatisch ein Datenschutz-Abschnitt zur Kontoverwaltung erzeugt.',
                                        ];
                                        $alertDismissible = false;
                                        $alertMarginClass = 'mb-0';
                                        require __DIR__ . '/../partials/flash-alert.php';
                                        ?>
                                    </div>

                                    <div class="col-12 d-none" data-privacy-feature="legal_profile_has_comments">
                                        <?php
                                        $alertData = [
                                            'type' => 'secondary',
                                            'message' => 'Für Kommentare wird automatisch ein Hinweis zu Inhaltsdaten, Zeitstempeln und Missbrauchsschutz aufgenommen.',
                                        ];
                                        $alertDismissible = false;
                                        $alertMarginClass = 'mb-0';
                                        require __DIR__ . '/../partials/flash-alert.php';
                                        ?>
                                    </div>

                                    <div class="col-12 d-none" data-privacy-feature="legal_profile_has_newsletter">
                                        <div class="border rounded p-3 bg-white">
                                            <div class="fw-semibold mb-2">Newsletter</div>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label for="fld-legal_profile_newsletter_provider" class="form-label">Newsletter-Anbieter</label>
                                                    <input id="fld-legal_profile_newsletter_provider" type="text" name="legal_profile_newsletter_provider" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_newsletter_provider'] ?? ''); ?>" placeholder="z. B. Brevo, Mailchimp, CleverReach">
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="text-secondary small pt-md-4">Wenn gesetzt, wird der Anbieter direkt im Datenschutztext genannt.</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 d-none" data-privacy-feature="legal_profile_has_analytics">
                                        <div class="border rounded p-3 bg-white">
                                            <div class="fw-semibold mb-2">Analytics / Reichweitenmessung</div>
                                            <div class="row g-3 align-items-end">
                                                <div class="col-md-4">
                                                    <label for="fld-legal_profile_analytics_self_hosted" class="form-label">Self-Hosted Analytics?</label>
                                                    <select id="fld-legal_profile_analytics_self_hosted" name="legal_profile_analytics_self_hosted" class="form-select">
                                                        <option value="0" <?php echo ($profile['legal_profile_analytics_self_hosted'] ?? '0') === '0' ? 'selected' : ''; ?>>Nein</option>
                                                        <option value="1" <?php echo ($profile['legal_profile_analytics_self_hosted'] ?? '0') === '1' ? 'selected' : ''; ?>>Ja</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-8">
                                                    <div class="text-secondary small">Bei selbst gehosteter, cookieloser Reichweitenmessung ohne Drittanbieter-Transfer kann eine Einwilligung in der Regel entbehrlich sein. Sobald Cookies oder externe Empfänger beteiligt sind, bleibt eine Consent-Abfrage erforderlich.</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 d-none" data-privacy-feature="legal_profile_has_external_media">
                                        <div class="border rounded p-3 bg-white">
                                            <div class="fw-semibold mb-2">Externe Medien / Drittinhalte</div>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label for="fld-legal_profile_external_media_providers" class="form-label">Verwendete Anbieter</label>
                                                    <input id="fld-legal_profile_external_media_providers" type="text" name="legal_profile_external_media_providers" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_external_media_providers'] ?? ''); ?>" placeholder="z. B. YouTube, Vimeo, Google Maps">
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="text-secondary small pt-md-4">Diese Anbieter werden im Datenschutztext bei den externen Inhalten erwähnt.</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 d-none" data-privacy-feature="legal_profile_has_webfonts">
                                        <div class="border rounded p-3 bg-white">
                                            <div class="fw-semibold mb-2">Webfonts</div>
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label for="fld-legal_profile_webfonts_source" class="form-label">Bereitstellung</label>
                                                    <select id="fld-legal_profile_webfonts_source" name="legal_profile_webfonts_source" class="form-select">
                                                        <option value="local" <?php echo ($profile['legal_profile_webfonts_source'] ?? 'local') === 'local' ? 'selected' : ''; ?>>Lokal</option>
                                                        <option value="external" <?php echo ($profile['legal_profile_webfonts_source'] ?? 'local') === 'external' ? 'selected' : ''; ?>>Externer Anbieter</option>
                                                        <option value="mixed" <?php echo ($profile['legal_profile_webfonts_source'] ?? 'local') === 'mixed' ? 'selected' : ''; ?>>Gemischt</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-8">
                                                    <label for="fld-legal_profile_webfonts_provider" class="form-label">Anbieter / Quelle</label>
                                                    <input id="fld-legal_profile_webfonts_provider" type="text" name="legal_profile_webfonts_provider" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_webfonts_provider'] ?? ''); ?>" placeholder="z. B. Google Fonts, Bunny Fonts">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 d-none" data-privacy-feature="legal_profile_has_shop">
                                        <?php
                                        $alertData = [
                                            'type' => 'secondary',
                                            'message' => 'Für Shop, Buchung oder Zahlungsabwicklung wird der Datenschutztext um Vertrags- und Zahlungsinformationen ergänzt.',
                                            'details' => [
                                                'Zahlungsanbieter kannst du oben im Datenschutz-Setup hinterlegen.',
                                            ],
                                        ];
                                        $alertDismissible = false;
                                        $alertMarginClass = 'mb-0';
                                        require __DIR__ . '/../partials/flash-alert.php';
                                        ?>
                                    </div>

                                    <div class="col-12 d-none" data-privacy-feature="legal_profile_has_editorial_content">
                                        <div class="border rounded p-3 bg-white">
                                            <div class="fw-semibold mb-2">Verantwortlicher nach § 18 Abs. 2 Medienstaatsvertrag (MStV)</div>
                                            <div class="text-secondary small mb-2">Bei journalistisch-redaktionell gestalteten Angeboten (Magazin, Blog, News) muss im Impressum zusätzlich ein Verantwortlicher mit Namen und Anschrift benannt werden.</div>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label for="fld-legal_profile_mstv_responsible_name" class="form-label">Name (Vor- und Nachname)</label>
                                                    <input id="fld-legal_profile_mstv_responsible_name" type="text" name="legal_profile_mstv_responsible_name" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_mstv_responsible_name'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="fld-legal_profile_mstv_responsible_address" class="form-label">Anschrift</label>
                                                    <input id="fld-legal_profile_mstv_responsible_address" type="text" name="legal_profile_mstv_responsible_address" class="form-control" value="<?php echo htmlspecialchars($profile['legal_profile_mstv_responsible_address'] ?? ''); ?>" placeholder="z. B. Musterstraße 1, 12345 Musterstadt">
                                                </div>
                                                <div class="col-12">
                                                    <div class="text-secondary small">Bleiben Name oder Anschrift leer, greift das Impressum ersatzweise auf „Inhaltlich verantwortlich“ bzw. die oben hinterlegte Geschäftsanschrift zurück.</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12" id="legalPrivacyFeatureEmptyState">
                                        <div class="text-secondary small">Aktuell sind keine zusätzlichen Datenschutz-Bausteine aktiv.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6" data-legal-profile-section="features">
                            <div class="border rounded p-3 h-100 bg-light-subtle">
                                <div class="subheader mb-2">AGB & Widerruf</div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_terms_scope" class="form-label">Zielgruppe AGB</label>
                                        <select id="fld-legal_profile_terms_scope" name="legal_profile_terms_scope" class="form-select">
                                            <option value="b2c" <?php echo ($profile['legal_profile_terms_scope'] ?? 'b2c') === 'b2c' ? 'selected' : ''; ?>>B2C</option>
                                            <option value="b2b" <?php echo ($profile['legal_profile_terms_scope'] ?? 'b2c') === 'b2b' ? 'selected' : ''; ?>>B2B</option>
                                            <option value="mixed" <?php echo ($profile['legal_profile_terms_scope'] ?? 'b2c') === 'mixed' ? 'selected' : ''; ?>>B2C + B2B</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_contract_type" class="form-label">Vertragsart</label>
                                        <select id="fld-legal_profile_contract_type" name="legal_profile_contract_type" class="form-select">
                                            <option value="services" <?php echo ($profile['legal_profile_contract_type'] ?? 'services') === 'services' ? 'selected' : ''; ?>>Dienstleistung</option>
                                            <option value="goods" <?php echo ($profile['legal_profile_contract_type'] ?? 'services') === 'goods' ? 'selected' : ''; ?>>Warenverkauf</option>
                                            <option value="digital" <?php echo ($profile['legal_profile_contract_type'] ?? 'services') === 'digital' ? 'selected' : ''; ?>>Digitale Inhalte</option>
                                            <option value="mixed" <?php echo ($profile['legal_profile_contract_type'] ?? 'services') === 'mixed' ? 'selected' : ''; ?>>Gemischt</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="fld-legal_profile_return_costs" class="form-label">Rücksendekosten</label>
                                        <select id="fld-legal_profile_return_costs" name="legal_profile_return_costs" class="form-select">
                                            <option value="customer" <?php echo ($profile['legal_profile_return_costs'] ?? 'customer') === 'customer' ? 'selected' : ''; ?>>Kunde trägt Kosten</option>
                                            <option value="merchant" <?php echo ($profile['legal_profile_return_costs'] ?? 'customer') === 'merchant' ? 'selected' : ''; ?>>Unternehmen trägt Kosten</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-check">
                                            <input type="checkbox" class="form-check-input" name="legal_profile_service_start_notice" value="1" <?php echo ($profile['legal_profile_service_start_notice'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                            <span class="form-check-label">Hinweis auf vorzeitigen Leistungsbeginn / Erlöschen des Widerrufsrechts einbauen</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12" data-legal-profile-section="features">
                            <div class="border rounded p-3 bg-light-subtle">
                                <div class="subheader mb-2">Aktive Website-Funktionen</div>
                                <div class="row g-3">
                                    <?php
                                    $toggleFields = [
                                        'legal_profile_has_cookies' => 'Cookie-/Consent-Banner',
                                        'legal_profile_has_contact_form' => 'Kontaktformular',
                                        'legal_profile_has_registration' => 'Registrierung / Benutzerkonten',
                                        'legal_profile_has_comments' => 'Kommentare / Beiträge',
                                        'legal_profile_has_newsletter' => 'Newsletter',
                                        'legal_profile_has_analytics' => 'Analyse / Tracking',
                                        'legal_profile_has_external_media' => 'Externe Medien / Drittinhalte',
                                        'legal_profile_has_webfonts' => 'Externe oder besondere Webfonts',
                                        'legal_profile_has_shop' => 'Shop / Buchung / Zahlungsabwicklung',
                                        'legal_profile_has_editorial_content' => 'Magazin / Blog / News (redaktionell-journalistische Inhalte)',
                                    ];
                                    foreach ($toggleFields as $fieldName => $label):
                                    ?>
                                        <div class="col-sm-6 col-lg-4">
                                            <label class="form-check">
                                                <input type="checkbox" class="form-check-input" name="<?php echo htmlspecialchars($fieldName); ?>" value="1" <?php echo ($profile[$fieldName] ?? '0') === '1' ? 'checked' : ''; ?>>
                                                <span class="form-check-label"><?php echo htmlspecialchars($label); ?></span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="legal-sites-tab-save" data-legal-profile-save="profile">
                        <button type="submit" class="btn btn-primary">Standardwerte speichern</button>
                    </div>
                    <div class="legal-sites-tab-save d-none" data-legal-profile-save="contact">
                        <button type="submit" class="btn btn-primary">Standardwerte speichern</button>
                    </div>
                    <div class="legal-sites-tab-save d-none" data-legal-profile-save="register">
                        <button type="submit" class="btn btn-primary">Standardwerte speichern</button>
                    </div>
                    <div class="legal-sites-tab-save d-none" data-legal-profile-save="privacy">
                        <button type="submit" class="btn btn-primary">Standardwerte speichern</button>
                    </div>
                    <div class="legal-sites-tab-save d-none" data-legal-profile-save="features">
                        <button type="submit" class="btn btn-primary">Standardwerte speichern</button>
                    </div>
                    <div class="text-secondary small mt-2">
                        Hinweis: Die Texte sind eine technische Vorlage und ersetzen keine rechtliche Prüfung.
                    </div>
                </form>
            </div>
        </div>

        <div class="row row-cards admin-legal-panels">
        <?php foreach ($tabKeys as $i => $key): $p = $pages[$key] ?? []; ?>
        <div class="col-12 col-xxl-6">
            <?php $templateType = $templateTypes[$key] ?? ''; ?>
            <?php $defaultTemplate = $templateDefaults[$templateType] ?? ''; ?>
            <?php $meta = is_array($templateMeta[$templateType] ?? null) ? $templateMeta[$templateType] : []; ?>
            <div class="card mb-3 admin-content-card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <?php echo $tabIcons[$key] ?? ''; ?>
                        <h3 class="card-title mb-0"><?php echo htmlspecialchars($p['label'] ?? ''); ?></h3>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-primary btn-sm js-insert-template" data-target="legal-<?php echo htmlspecialchars($key); ?>" data-template="<?php echo htmlspecialchars($defaultTemplate, ENT_QUOTES); ?>">Vorlage einfügen</button>
                        <form method="post" action="<?php echo htmlspecialchars($legalSitesAdminBaseUrl, ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                            <input type="hidden" name="action" value="generate">
                            <input type="hidden" name="template_type" value="<?php echo htmlspecialchars($templateType); ?>">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Aus Standardwerten generieren</button>
                        </form>
                        <form method="post" action="<?php echo htmlspecialchars($legalSitesAdminBaseUrl, ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                            <input type="hidden" name="action" value="create_page">
                            <input type="hidden" name="template_type" value="<?php echo htmlspecialchars($templateType); ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Seite erstellen/aktualisieren</button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100 bg-light-subtle">
                                <div class="subheader">Status</div>
                                <div class="fw-bold mb-2"><?php echo !empty($p['content']) ? 'Inhalt vorhanden' : 'Noch leer'; ?></div>
                                <div class="text-secondary small"><?php echo !empty($p['content']) ? 'Der Text ist gepflegt und kann einer Seite zugeordnet werden.' : 'Mit einem Klick auf „Vorlage generieren“ wird ein DSGVO-/TMG-Grundgerüst eingefügt.'; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100 bg-light-subtle">
                                <div class="subheader">Zugeordnete Seite</div>
                                <div class="fw-bold mb-2"><?php echo !empty($assigned[$pageIdKeys[$key] ?? '']) ? 'Verknüpft' : 'Nicht verknüpft'; ?></div>
                                <div class="text-secondary small">Wähle unten eine veröffentlichte Seite, damit der Inhalt öffentlich an der richtigen Stelle ausgespielt wird.</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100 bg-light-subtle">
                                <div class="subheader">Vorlage</div>
                                <div class="fw-bold mb-2"><?php echo $defaultTemplate !== '' ? 'Standardtext verfügbar' : 'Keine Vorlage'; ?></div>
                                <div class="text-secondary small">Der Generator nutzt deine Standardwerte für Name, Anschrift, Kontakt, Hosting und aktive Website-Funktionen.</div>
                                <?php if (!empty($meta['version']) || !empty($meta['profile']) || !empty($meta['applied_at'])): ?>
                                    <div class="small text-muted mt-2">
                                        <?php if (!empty($meta['profile'])): ?>Profil: <?php echo htmlspecialchars((string) $meta['profile']); ?><?php endif; ?>
                                        <?php if (!empty($meta['version'])): ?> · Version: <?php echo htmlspecialchars((string) $meta['version']); ?><?php endif; ?>
                                        <?php if (!empty($meta['applied_at'])): ?> · angewendet: <?php echo htmlspecialchars((string) $meta['applied_at']); ?><?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="small text-muted mt-2">Noch keine versionierte Vorlage angewendet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <form method="post" action="<?php echo htmlspecialchars($legalSitesAdminBaseUrl, ENT_QUOTES, 'UTF-8'); ?>" data-legal-bulk-save-part="document">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                        <input type="hidden" name="action" value="save">
                        <div class="row g-3">
                            <div class="col-12 col-xl-8">
                                <div class="legal-sites-editor-toggle mb-2" role="tablist" aria-label="Editor-Ansicht">
                                    <button type="button" class="btn btn-sm btn-outline-primary active" data-legal-view-mode="edit" data-legal-view-target="legal-<?php echo htmlspecialchars($key); ?>">HTML bearbeiten</button>
                                    <span class="text-secondary small">|</span>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-legal-view-mode="preview" data-legal-view-target="legal-<?php echo htmlspecialchars($key); ?>">Vorschau</button>
                                </div>
                                <label for="legal-<?php echo htmlspecialchars($key); ?>" class="form-label">Inhalt (HTML)</label>
                                <textarea name="<?php echo htmlspecialchars($key); ?>" id="legal-<?php echo htmlspecialchars($key); ?>" class="form-control" rows="12" data-legal-html-source><?php echo htmlspecialchars($p['content'] ?? ''); ?></textarea>
                                <div class="legal-sites-html-preview d-none" data-legal-html-preview></div>
                                <?php if (!empty($constraints['max_legal_html_length']) || $templateType !== ''): ?>
                                    <small class="form-hint">
                                        <?php if (!empty($constraints['max_legal_html_length'])): ?>
                                            Max. <?php echo number_format((int) $constraints['max_legal_html_length'], 0, ',', '.'); ?> HTML-Zeichen
                                        <?php endif; ?>
                                        <?php if (!empty($constraints['max_legal_html_length']) && $templateType !== ''): ?>
                                            ·
                                        <?php endif; ?>
                                        <?php if ($templateType !== ''): ?>
                                            Generator-Typ: <?php echo htmlspecialchars($templateType); ?>
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <div class="col-12 col-xl-4">
                                <label for="legal-page-<?php echo htmlspecialchars($key, ENT_QUOTES); ?>" class="form-label">Zugewiesene Seite</label>
                                <select id="legal-page-<?php echo htmlspecialchars($key, ENT_QUOTES); ?>" name="<?php echo htmlspecialchars($pageIdKeys[$key] ?? ''); ?>" class="form-select">
                                    <option value="0">– Keine Seite –</option>
                                    <?php foreach ($allPages as $pg): ?>
                                        <option value="<?php echo (int)$pg['id']; ?>" <?php echo ($assigned[$pageIdKeys[$key] ?? ''] ?? '') == $pg['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($pg['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-hint">Ordne eine bestehende Seite zu, die diesen Rechtstext anzeigt.</small>
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap mt-3">
                            <button type="submit" class="btn btn-outline-primary">Bereich speichern</button>
                            <?php if (empty($p['content']) && $defaultTemplate !== ''): ?>
                                <span class="text-secondary small align-self-center">Tipp: Erst Vorlage generieren, dann anpassen.</span>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

    </div>
</div>

<script type="application/json" id="legal-sites-config">
<?php echo json_encode($legalSitesConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>
