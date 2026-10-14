<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @var array $data Einstellungsdaten
 * @var string $csrfToken CSRF-Token
 */

$s          = $data['settings'];
$mail       = $data['mail'] ?? [];
$timezones  = $data['timezones'];
$languages  = $data['languages'];
$currentTab = ($currentTab ?? 'general') === 'content' ? 'content' : 'general';
$settingsBaseUrl = '/admin/settings';
$mailSettingsUrl = '/admin/mail-settings';
$userSettingsUrl = '/admin/user-settings';
$mediaApiUrl = '/api/media';
$hideSettingsTabs = $hideSettingsTabs ?? false;
$editorMediaToken = $editorMediaToken ?? '';
$marketplaceEndpointDefaults = \CMS\Services\MarketplaceEndpoints::defaults();
$marketplaceEndpointLabels = \CMS\Services\MarketplaceEndpoints::LABELS;
$marketplaceEndpointHints = [
    'marketplace_public_url' => 'Öffentliche Marketplace-Übersicht; wird im Plugin- und Theme-Marketplace verlinkt.',
    'plugin_registry_url' => 'Zentraler JSON-Feed für den Plugin-Marketplace und Plugin-Updates.',
    'theme_registry_url' => 'Zentraler JSON-Feed für den Theme-Marketplace und Theme-Updates.',
    'plugin_marketplace_base_url' => 'Basis für relative Plugin-Manifeste, Pakete und Assets aus dem Plugins-Index.',
    'theme_marketplace_url' => 'Basis für relative Theme-Manifeste, Pakete und Screenshots aus dem Themes-Index.',
    'marketplace_submit_url' => 'Öffentliches Formular, über das Plugins und Themes für den Marketplace eingereicht werden.',
    'core_update_url' => 'Expliziter Feed für 365CMS-Core-Updates (GitHub-Releases bleiben Fallback).',
];
$formValues = is_array($formValues ?? null) ? $formValues : [];
$invalidFields = array_values(array_filter(array_map(
    static fn ($field): string => trim((string) $field),
    is_array($invalidFields ?? null) ? $invalidFields : []
), static fn (string $field): bool => $field !== ''));
$invalidFieldLookup = array_fill_keys($invalidFields, true);
$hasInvalidField = static fn (string $field): bool => isset($invalidFieldLookup[$field]);
$controlClass = static fn (string $field, string $base = 'form-control'): string => $base . ($hasInvalidField($field) ? ' is-invalid' : '');
$controlDescribedBy = static function (string $field, array $ids = []) use ($hasInvalidField): string {
    if ($hasInvalidField($field)) {
        $ids[] = 'settings-alert-region';
    }

    return implode(' ', array_values(array_unique(array_filter($ids, static fn ($id): bool => trim((string) $id) !== ''))));
};

if ($currentTab === 'content' && $formValues !== []) {
    foreach (['editor_type', 'post_default_status', 'page_default_status', 'page_editor_width', 'post_editor_width', 'post_permalink_preset', 'post_permalink_custom', 'category_base_de', 'category_base_en', 'tag_base_de', 'tag_base_en'] as $key) {
        if (array_key_exists($key, $formValues)) {
            $s[$key] = (string) $formValues[$key];
        }
    }

    $preset = (string)($s['post_permalink_preset'] ?? 'blog');
    $custom = trim((string)($s['post_permalink_custom'] ?? ''));
    $structure = match ($preset) {
        'dated' => class_exists('\CMS\Services\PermalinkService') ? \CMS\Services\PermalinkService::PRESET_DATED : '/%year%/%monthnum%/%day%/%postname%',
        'slug' => class_exists('\CMS\Services\PermalinkService') ? \CMS\Services\PermalinkService::PRESET_SLUG : '/%postname%',
        'year' => class_exists('\CMS\Services\PermalinkService') ? \CMS\Services\PermalinkService::PRESET_YEAR : '/%year%/%postname%',
        'custom' => $custom,
        default => class_exists('\CMS\Services\PermalinkService') ? \CMS\Services\PermalinkService::PRESET_BLOG : '/blog/%postname%',
    };

    if (class_exists('\CMS\Services\PermalinkService')) {
        $s['post_permalink_structure'] = \CMS\Services\PermalinkService::normalizePostStructure($structure);
        $s['post_permalink_example'] = \CMS\Services\PermalinkService::buildExamplePath((string)$s['post_permalink_structure']);
    } else {
        $s['post_permalink_structure'] = $structure;
        $s['post_permalink_example'] = '/blog/beispielbeitrag';
    }
}

$settingsPretitle = $currentTab === 'content' ? 'Seiten & Beiträge' : 'System';
$settingsHeading = $currentTab === 'content' ? 'Beiträge & Sites – Einstellungen' : 'Allgemeine Einstellungen';
?>

<div class="container-xl cms-settings-page">
    <div class="page-header d-print-none mb-4">
        <div class="row align-items-center">
            <div class="col-auto">
                <div class="page-pretitle"><?php echo htmlspecialchars($settingsPretitle, ENT_QUOTES, 'UTF-8'); ?></div>
                <h2 class="page-title">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-settings me-2" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.066 2.573c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.573 1.066c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.066 -2.573c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"/><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"/></svg>
                    <?php echo htmlspecialchars($settingsHeading, ENT_QUOTES, 'UTF-8'); ?>
                </h2>
            </div>
        </div>
    </div>

    <div id="settings-alert-region">
        <?php
        $alertData = is_array($alert ?? null) ? $alert : [];
        $alertMarginClass = 'mb-4';
        require dirname(__DIR__) . '/partials/flash-alert.php';
        ?>
    </div>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($currentTab); ?>">

        <div class="cms-settings-toolbar">
            <?php if (!$hideSettingsTabs): ?>
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentTab === 'general' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($settingsBaseUrl); ?>">
                        Allgemein
                    </a>
                </li>
                <li class="nav-item ms-auto">
                    <a class="nav-link <?php echo $currentTab === 'content' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($settingsBaseUrl); ?>?tab=content">
                        Beiträge &amp; Sites
                    </a>
                </li>
            </ul>
            <?php endif; ?>

            <div class="cms-settings-actions">
                <span class="text-secondary small me-auto">Änderungen werden zentral in den Systemeinstellungen gespeichert.</span>
            </div>
        </div>

        <?php if ($currentTab === 'general'): ?>
        <div class="row">
            <div class="col-12 mb-3">
                <ul class="nav nav-tabs" id="generalSettingsTabs" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active" id="tab-website-url" data-bs-toggle="tab" data-bs-target="#pane-website-url" type="button" role="tab" aria-controls="pane-website-url" aria-selected="true">Website &amp; URL</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-localization" data-bs-toggle="tab" data-bs-target="#pane-localization" type="button" role="tab" aria-controls="pane-localization" aria-selected="false">Lokalisierung</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-mail-system" data-bs-toggle="tab" data-bs-target="#pane-mail-system" type="button" role="tab" aria-controls="pane-mail-system" aria-selected="false">Mail-System</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-content-options" data-bs-toggle="tab" data-bs-target="#pane-content-options" type="button" role="tab" aria-controls="pane-content-options" aria-selected="false">Inhalte &amp; Optionen</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-marketplace-updates" data-bs-toggle="tab" data-bs-target="#pane-marketplace-updates" type="button" role="tab" aria-controls="pane-marketplace-updates" aria-selected="false">Marketplace &amp; Updates</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-advanced" data-bs-toggle="tab" data-bs-target="#pane-advanced" type="button" role="tab" aria-controls="pane-advanced" aria-selected="false">Erweitert</button></li>
                </ul>
            </div>
            <div class="col-12">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="pane-website-url" role="tabpanel" aria-labelledby="tab-website-url" tabindex="0">
                        <div class="card mb-4">
                            <div class="card-header"><h3 class="card-title">Website</h3></div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="fld-site_name" class="form-label required">Website-Name</label>
                                    <input id="fld-site_name" type="text" name="site_name" class="form-control" value="<?php echo htmlspecialchars($s['site_name']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="fld-site_description" class="form-label">Beschreibung</label>
                                    <textarea id="fld-site_description" name="site_description" class="form-control" rows="2"><?php echo htmlspecialchars($s['site_description']); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Website-Logo</label>
                                    <div class="input-group">
                                        <input
                                            type="text"
                                            id="siteLogoInput"
                                            name="site_logo"
                                            class="form-control"
                                            value="<?php echo htmlspecialchars($s['site_logo'] ?? ''); ?>"
                                            placeholder="/uploads/logo.svg oder https://..."
                                            data-media-target-input>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary"
                                            data-open-media-picker
                                            data-target-input="siteLogoInput"
                                            data-preview-id="siteLogoPreview"
                                            data-picker-title="Website-Logo auswählen">
                                            Medien
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary"
                                            data-clear-media-input
                                            data-target-input="siteLogoInput"
                                            data-preview-id="siteLogoPreview">
                                            Leeren
                                        </button>
                                    </div>
                                    <div class="form-hint">Theme-unabhängiger Logo-Pfad bzw. eine URL, die Themes optional laden können.</div>
                                    <div
                                        id="siteLogoPreview"
                                        class="mt-2"
                                        data-media-preview
                                        data-preview-variant="logo"
                                        data-input-id="siteLogoInput"
                                        <?php echo empty($s['site_logo']) ? 'hidden' : ''; ?>>
                                        <?php if (!empty($s['site_logo'])): ?>
                                            <img src="<?php echo htmlspecialchars($s['site_logo']); ?>" alt="Website-Logo Vorschau" style="max-height:48px; max-width:220px; border-radius:2px; border:1px solid var(--tblr-border-color); padding:6px; background:#fff;">
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Favicon</label>
                                    <div class="input-group">
                                        <input
                                            type="text"
                                            id="siteFaviconInput"
                                            name="site_favicon"
                                            class="form-control"
                                            value="<?php echo htmlspecialchars($s['site_favicon'] ?? ''); ?>"
                                            placeholder="/uploads/favicon.ico oder /uploads/favicon.png"
                                            data-media-target-input>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary"
                                            data-open-media-picker
                                            data-target-input="siteFaviconInput"
                                            data-preview-id="siteFaviconPreview"
                                            data-picker-title="Favicon auswählen">
                                            Medien
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary"
                                            data-clear-media-input
                                            data-target-input="siteFaviconInput"
                                            data-preview-id="siteFaviconPreview">
                                            Leeren
                                        </button>
                                    </div>
                                    <div class="form-hint">Globales Favicon für 365CMS. Unterstützt relative Pfade unterhalb der Website oder absolute HTTPS-URLs.</div>
                                    <div
                                        id="siteFaviconPreview"
                                        class="mt-2 d-flex align-items-center gap-2"
                                        data-media-preview
                                        data-preview-variant="favicon"
                                        data-input-id="siteFaviconInput"
                                        <?php echo empty($s['site_favicon']) ? 'hidden' : ''; ?>>
                                        <?php if (!empty($s['site_favicon'])): ?>
                                            <img src="<?php echo htmlspecialchars($s['site_favicon']); ?>" alt="Favicon Vorschau" width="32" height="32" style="border-radius:2px; border:1px solid var(--tblr-border-color); padding:4px; background:#fff; object-fit:contain;">
                                            <code><?php echo htmlspecialchars((string)$s['site_favicon']); ?></code>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="fld-site_url" class="form-label required">Website-URL</label>
                                    <input id="fld-site_url" type="url" name="site_url" class="form-control" value="<?php echo htmlspecialchars($s['site_url']); ?>" required>
                                    <div class="form-hint">Diese URL wird jetzt auch in der Runtime-Konfiguration (`config/app.php`) aktualisiert, damit `SITE_URL` zentral systemweit mitzieht.</div>
                                </div>
                                <div class="border rounded p-3 bg-light mb-0">
                                    <div class="fw-semibold mb-1">Zentrale URL-Umstellung</div>
                                    <div class="text-secondary small mb-3">
                                        Aktive Runtime-URL: <code><?php echo htmlspecialchars((string)($s['runtime_site_url'] ?? $s['site_url'])); ?></code><br>
                                        Beim Speichern kann 365CMS absolute Verweise von der alten Basis-URL auf die neue URL in Inhalten, Settings, Tabellen und Weiterleitungen mitmigrieren.
                                    </div>
                                    <div class="mb-3">
                                        <label for="fld-migrate_from_site_url" class="form-label">Alte Basis-URL für Nachmigration <span class="text-secondary small">(optional)</span></label>
                                        <input id="fld-migrate_from_site_url" type="url" class="form-control" name="migrate_from_site_url" value="" placeholder="https://alte-domain.tld">
                                        <div class="form-hint">Optional für bereits abgeschlossene Umzüge: Wenn hier eine alte Domain eingetragen wird, ersetzt 365CMS beim Speichern auch dann noch alte Bild-, Upload- und Medien-URLs zentral, selbst wenn die aktuelle Website-URL bereits korrekt gesetzt ist.</div>
                                    </div>
                                    <label class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input" name="migrate_site_url_references" value="1" checked>
                                        <span class="form-check-label">Alte absolute CMS-URLs zentral auf die neue Website-URL umstellen</span>
                                    </label>
                                    <div class="mt-3 d-flex flex-wrap gap-2 align-items-center">
                                        <button type="submit" class="btn btn-outline-warning" name="action" value="run_site_url_migration">
                                            Nur URL-Nachmigration ausführen
                                        </button>
                                        <span class="text-secondary small">Führt nur die URL-Ersetzung aus und speichert keine anderen Einstellungen neu.</span>
                                    </div>
                                </div>
                                <div>
                                    <label for="fld-admin_email" class="form-label required">Admin-E-Mail</label>
                                    <input id="fld-admin_email" type="email" name="admin_email" class="form-control" value="<?php echo htmlspecialchars($s['admin_email']); ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pane-localization" role="tabpanel" aria-labelledby="tab-localization" tabindex="0">
                        <div class="card mb-4">
                            <div class="card-header"><h3 class="card-title">Lokalisierung</h3></div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="fld-language" class="form-label">Sprache</label>
                                    <select id="fld-language" name="language" class="form-select">
                                        <?php foreach ($languages as $code => $label): ?>
                                            <option value="<?php echo $code; ?>" <?php echo $s['language'] === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="fld-timezone" class="form-label">Zeitzone</label>
                                    <select id="fld-timezone" name="timezone" class="form-select">
                                        <?php foreach ($timezones as $tz): ?>
                                            <option value="<?php echo $tz; ?>" <?php echo $s['timezone'] === $tz ? 'selected' : ''; ?>><?php echo htmlspecialchars($tz); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <label for="fld-date_format" class="form-label">Datumsformat</label>
                                        <select id="fld-date_format" name="date_format" class="form-select">
                                            <?php foreach (['d.m.Y' => '31.12.2025', 'Y-m-d' => '2025-12-31', 'm/d/Y' => '12/31/2025', 'd/m/Y' => '31/12/2025'] as $fmt => $example): ?>
                                                <option value="<?php echo $fmt; ?>" <?php echo $s['date_format'] === $fmt ? 'selected' : ''; ?>><?php echo $example; ?> (<?php echo $fmt; ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="fld-time_format" class="form-label">Zeitformat</label>
                                        <select id="fld-time_format" name="time_format" class="form-select">
                                            <?php foreach (['H:i' => '14:30', 'H:i:s' => '14:30:00', 'g:i A' => '2:30 PM'] as $fmt => $example): ?>
                                                <option value="<?php echo $fmt; ?>" <?php echo $s['time_format'] === $fmt ? 'selected' : ''; ?>><?php echo $example; ?> (<?php echo $fmt; ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pane-mail-system" role="tabpanel" aria-labelledby="tab-mail-system" tabindex="0">
                        <div class="card h-100 mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h3 class="card-title mb-0">Mail-System</h3>
                                <span class="text-secondary small"><?php echo htmlspecialchars((string)($mail['transport_label'] ?? 'Mailversand')); ?></span>
                            </div>
                            <div class="card-body">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Absender</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars((string)(($mail['from_name'] ?? '') !== '' ? ($mail['from_name'] . ' <' . ($mail['from_email'] ?? '') . '>') : ($mail['from_email'] ?? ''))); ?>" readonly>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">SMTP-Host</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars((string)($mail['host'] ?? '')); ?>" readonly>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Port / TLS</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars((string)(($mail['port'] ?? '') . ' / ' . ($mail['encryption'] ?? 'none'))); ?>" readonly>
                                    </div>
                                </div>

                                <div class="cms-admin-info-box mb-3" role="note">
                                    <div class="cms-admin-info-box__head">
                                        <h3 class="cms-admin-info-box__title">Mail- und OAuth2-Steuerung</h3>
                                    </div>
                                    <p class="cms-admin-info-box__text">
                                        Mail-Transport, Azure OAuth2, Microsoft Graph und Versand-Logs werden zentral unter
                                        <a href="<?php echo htmlspecialchars($mailSettingsUrl); ?>">System → Mail &amp; Azure OAuth2</a>
                                        verwaltet.
                                    </p>
                                </div>

                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <a href="<?php echo htmlspecialchars($mailSettingsUrl); ?>" class="btn btn-outline-primary">Mail-System öffnen</a>
                                    <span class="text-secondary small">Hier siehst du nur den aktiven Laufzeitstatus.</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pane-content-options" role="tabpanel" aria-labelledby="tab-content-options" tabindex="0">
                        <div class="card mb-4">
                            <div class="card-header"><h3 class="card-title">Inhalte</h3></div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="fld-posts_per_page" class="form-label">Beiträge pro Seite</label>
                                    <input id="fld-posts_per_page" type="number" name="posts_per_page" class="form-control" value="<?php echo (int)$s['posts_per_page']; ?>" min="1" max="100">
                                </div>
                                <div class="mb-3">
                                    <div class="cms-admin-info-box mb-0" role="note">
                                        <div class="cms-admin-info-box__head">
                                            <h3 class="cms-admin-info-box__title">Benutzer- und Auth-Optionen</h3>
                                        </div>
                                        <p class="cms-admin-info-box__text">
                                            Benutzer-, Registrierungs- und Authentifizierungsoptionen werden unter
                                            <a href="<?php echo htmlspecialchars($userSettingsUrl); ?>">Benutzer &amp; Gruppen → Einstellungen</a>
                                            verwaltet.
                                        </p>
                                    </div>
                                </div>
                                <div>
                                    <label class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input" name="comments_enabled" value="1" <?php echo $s['comments_enabled'] ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Kommentare aktivieren</span>
                                    </label>
                                </div>
                                <div class="mt-3">
                                    <label class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input" name="marketplace_enabled" value="1" <?php echo $s['marketplace_enabled'] ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Marketplace anzeigen (Theme- & Plugin-Marketplace)</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pane-marketplace-updates" role="tabpanel" aria-labelledby="tab-marketplace-updates" tabindex="0">
                        <div class="card h-100 mb-4">
                            <div class="card-header"><h3 class="card-title">Marketplace &amp; Updates</h3></div>
                            <div class="card-body">
                                <div class="cms-admin-info-box mb-3" role="note">
                                    <div class="cms-admin-info-box__head">
                                        <h3 class="cms-admin-info-box__title">Offizielle 365CMS-Endpunkte</h3>
                                    </div>
                                    <p class="cms-admin-info-box__text">Empfohlene produktive Standardwerte für Marketplace-Übersicht, Plugin- und Theme-Feeds, Einreichung und Core-Updates.</p>
                                </div>
                                <?php foreach ($marketplaceEndpointDefaults as $endpointKey => $endpointDefault): ?>
                                    <?php
                                    $endpointValue = (string)($s[$endpointKey] ?? $endpointDefault);
                                    $endpointInputId = 'setting-' . str_replace('_', '-', $endpointKey);
                                    ?>
                                    <div class="mb-3">
                                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                                            <label class="form-label mb-0" for="<?php echo htmlspecialchars($endpointInputId); ?>"><?php echo htmlspecialchars($marketplaceEndpointLabels[$endpointKey] ?? $endpointKey); ?></label>
                                            <?php if ($endpointValue === $endpointDefault): ?>
                                                <span class="text-secondary small">Produktiv-Standard aktiv</span>
                                            <?php else: ?>
                                                <span class="text-warning small fw-semibold">Individuell überschrieben</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="input-group">
                                            <input type="url" id="<?php echo htmlspecialchars($endpointInputId); ?>" name="<?php echo htmlspecialchars($endpointKey); ?>" class="form-control" value="<?php echo htmlspecialchars($endpointValue); ?>" placeholder="<?php echo htmlspecialchars($endpointDefault); ?>">
                                            <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars($endpointValue); ?>" target="_blank" rel="noopener noreferrer" title="Endpunkt öffnen">Öffnen</a>
                                        </div>
                                        <div class="form-hint"><?php echo htmlspecialchars($marketplaceEndpointHints[$endpointKey] ?? ''); ?> Offizieller Standard: <code><?php echo htmlspecialchars($endpointDefault); ?></code></div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="cms-admin-info-box mb-0" role="note">
                                    <div class="cms-admin-info-box__head">
                                        <h3 class="cms-admin-info-box__title">Fallback bleibt aktiv</h3>
                                    </div>
                                    <p class="cms-admin-info-box__text">Diese Felder zeigen die aktuell verwendeten zentralen Marketplace-Endpunkte. Leere oder ungültige Werte fallen beim Speichern automatisch auf die offiziellen 365CMS-Defaults zurück. Wird nur eine Basis- oder nur eine Index-URL angepasst, leitet das System das Gegenstück automatisch ab.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pane-advanced" role="tabpanel" aria-labelledby="tab-advanced" tabindex="0">
                        <div class="card mb-4">
                            <div class="card-header"><h3 class="card-title">Wartungsmodus</h3></div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input" name="maintenance_mode" value="1" <?php echo $s['maintenance_mode'] ? 'checked' : ''; ?>>
                                        <span class="form-check-label">Wartungsmodus aktivieren</span>
                                    </label>
                                    <small class="form-hint">Besucher sehen eine Wartungsseite. Administratoren haben weiterhin Zugriff.</small>
                                </div>
                                <div>
                                    <label for="fld-maintenance_message" class="form-label">Wartungsnachricht</label>
                                    <textarea id="fld-maintenance_message" name="maintenance_message" class="form-control" rows="3"><?php echo htmlspecialchars($s['maintenance_message']); ?></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="card mb-4">
                            <div class="card-header"><h3 class="card-title">Erweitert</h3></div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="fld-google_analytics" class="form-label">Google Analytics / Tag Manager ID</label>
                                    <input id="fld-google_analytics" type="text" name="google_analytics" class="form-control" value="<?php echo htmlspecialchars($s['google_analytics']); ?>" placeholder="G-XXXXXXXXXX oder UA-XXXXXX-X">
                                </div>
                                <div>
                                    <label for="fld-robots_txt" class="form-label">robots.txt</label>
                                    <textarea id="fld-robots_txt" name="robots_txt" class="form-control" rows="5" style="font-family: monospace; font-size: 13px;" placeholder="User-agent: *&#10;Allow: /"><?php echo htmlspecialchars($s['robots_txt']); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="cms-settings-actions cms-settings-actions-bottom sticky-footer">
                        <button type="submit" class="btn btn-primary" name="action" value="save">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-device-floppy me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 4h10l4 4v10a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2"/><path d="M12 14m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/><path d="M14 4l0 4l-6 0l0 -4"/></svg>
                            Einstellungen speichern
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="row">
            <div class="col-12 mb-4">
                <div class="cms-admin-info-box mb-0" role="status">
                    <div class="cms-admin-info-box__head">
                        <h3 class="cms-admin-info-box__title">Inhalts-Standardwerte</h3>
                    </div>
                    <p class="cms-admin-info-box__text">Diese Einstellungen steuern Standard-Editor, Veröffentlichungs-Defaults und die öffentlichen URL-Basen für neue Seiten, Beiträge und Archive. Bei Validierungsfehlern bleiben Eingaben nach dem Redirect erhalten.</p>
                </div>
            </div>
            <div class="col-12">
                <h3 class="cms-settings-section-heading">Kernoptionen für Inhalte</h3>
            </div>

            <div class="col-12 mb-4">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Editor</h3></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="settingsEditorType">Standard-Editor</label>
                            <select name="editor_type" id="settingsEditorType" class="<?php echo htmlspecialchars($controlClass('editor_type', 'form-select'), ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="<?php echo htmlspecialchars($controlDescribedBy('editor_type', ['settingsEditorTypeHint']), ENT_QUOTES, 'UTF-8'); ?>" aria-invalid="<?php echo $hasInvalidField('editor_type') ? 'true' : 'false'; ?>">
                                <option value="editorjs" <?php echo $s['editor_type'] === 'editorjs' ? 'selected' : ''; ?>>Editor.js</option>
                                <option value="suneditor" <?php echo $s['editor_type'] === 'suneditor' ? 'selected' : ''; ?>>SunEditor</option>
                            </select>
                            <small id="settingsEditorTypeHint" class="form-hint">Gilt für Seiten und Beiträge beim Erstellen und Bearbeiten.</small>
                        </div>
                        <div>
                            <label class="form-label" for="settingsPostEditorWidth">Beitrags-Editorbreite</label>
                            <div class="input-group">
                                <input type="number" name="post_editor_width" id="settingsPostEditorWidth" class="<?php echo htmlspecialchars($controlClass('post_editor_width'), ENT_QUOTES, 'UTF-8'); ?>" min="320" max="1600" step="10" value="<?php echo (int)$s['post_editor_width']; ?>" aria-describedby="<?php echo htmlspecialchars($controlDescribedBy('post_editor_width', ['settingsPostEditorWidthHint']), ENT_QUOTES, 'UTF-8'); ?>" aria-invalid="<?php echo $hasInvalidField('post_editor_width') ? 'true' : 'false'; ?>">
                                <span class="input-group-text">px</span>
                            </div>
                            <small id="settingsPostEditorWidthHint" class="form-hint">Wirkt direkt in der Beitragsbearbeitung. Zulässig sind 320 bis 1600&nbsp;px.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 mb-4">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Standard beim Speichern</h3></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="settingsPostDefaultStatus">Neue Beiträge</label>
                            <select name="post_default_status" id="settingsPostDefaultStatus" class="<?php echo htmlspecialchars($controlClass('post_default_status', 'form-select'), ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="<?php echo htmlspecialchars($controlDescribedBy('post_default_status', ['settingsPostDefaultStatusHint']), ENT_QUOTES, 'UTF-8'); ?>" aria-invalid="<?php echo $hasInvalidField('post_default_status') ? 'true' : 'false'; ?>">
                                <option value="draft" <?php echo $s['post_default_status'] === 'draft' ? 'selected' : ''; ?>>Als Entwurf speichern</option>
                                <option value="published" <?php echo $s['post_default_status'] === 'published' ? 'selected' : ''; ?>>Direkt veröffentlichen</option>
                            </select>
                            <small id="settingsPostDefaultStatusHint" class="form-hint">Bestehende Beiträge behalten ihren aktuellen Status.</small>
                        </div>
                        <div>
                            <label class="form-label" for="settingsPageDefaultStatus">Neue Seiten / Sites</label>
                            <select name="page_default_status" id="settingsPageDefaultStatus" class="<?php echo htmlspecialchars($controlClass('page_default_status', 'form-select'), ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="<?php echo htmlspecialchars($controlDescribedBy('page_default_status', ['settingsPageDefaultStatusHint']), ENT_QUOTES, 'UTF-8'); ?>" aria-invalid="<?php echo $hasInvalidField('page_default_status') ? 'true' : 'false'; ?>">
                                <option value="draft" <?php echo $s['page_default_status'] === 'draft' ? 'selected' : ''; ?>>Als Entwurf speichern</option>
                                <option value="published" <?php echo $s['page_default_status'] === 'published' ? 'selected' : ''; ?>>Direkt veröffentlichen</option>
                                <option value="private" <?php echo $s['page_default_status'] === 'private' ? 'selected' : ''; ?>>Privat anlegen</option>
                            </select>
                            <small id="settingsPageDefaultStatusHint" class="form-hint">Der Status gilt für neue Seiten und Site-nahe Inhaltseinträge.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 mb-4">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Seiten-Editor</h3></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="settingsPageEditorWidth">Seiten-Editorbreite</label>
                            <div class="input-group">
                                <input type="number" name="page_editor_width" id="settingsPageEditorWidth" class="<?php echo htmlspecialchars($controlClass('page_editor_width'), ENT_QUOTES, 'UTF-8'); ?>" min="320" max="1600" step="10" value="<?php echo (int)$s['page_editor_width']; ?>" aria-describedby="<?php echo htmlspecialchars($controlDescribedBy('page_editor_width', ['settingsPageEditorWidthHint']), ENT_QUOTES, 'UTF-8'); ?>" aria-invalid="<?php echo $hasInvalidField('page_editor_width') ? 'true' : 'false'; ?>">
                                <span class="input-group-text">px</span>
                            </div>
                            <small id="settingsPageEditorWidthHint" class="form-hint">Bestimmt die nutzbare Inhaltsbreite im Editor für Seiten. Zulässig sind 320 bis 1600&nbsp;px.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 mb-4">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">URL-Struktur &amp; Archive</h3></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="settingsPostPermalinkPreset">Beitrags-URL-Struktur</label>
                            <select name="post_permalink_preset" id="settingsPostPermalinkPreset" class="<?php echo htmlspecialchars($controlClass('post_permalink_preset', 'form-select'), ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="<?php echo htmlspecialchars($controlDescribedBy('post_permalink_preset', ['settingsPostPermalinkPresetHint']), ENT_QUOTES, 'UTF-8'); ?>" aria-invalid="<?php echo $hasInvalidField('post_permalink_preset') ? 'true' : 'false'; ?>" data-settings-permalink-preset>
                                <option value="blog" <?php echo ($s['post_permalink_preset'] ?? 'blog') === 'blog' ? 'selected' : ''; ?>>/blog/beitragsname</option>
                                <option value="dated" <?php echo ($s['post_permalink_preset'] ?? '') === 'dated' ? 'selected' : ''; ?>>/jahr/monat/tag/beitragsname</option>
                                <option value="slug" <?php echo ($s['post_permalink_preset'] ?? '') === 'slug' ? 'selected' : ''; ?>>/beitragsname</option>
                                <option value="year" <?php echo ($s['post_permalink_preset'] ?? '') === 'year' ? 'selected' : ''; ?>>/jahr/beitragsname</option>
                                <option value="custom" <?php echo ($s['post_permalink_preset'] ?? '') === 'custom' ? 'selected' : ''; ?>>Benutzerdefiniert</option>
                            </select>
                            <div id="settingsPostPermalinkPresetHint" class="form-hint">Erlaubte Platzhalter: <code>%year%</code>, <code>%monthnum%</code>, <code>%day%</code> und <code>%postname%</code>.</div>
                        </div>
                        <div class="mb-3" data-settings-permalink-custom-group>
                            <label class="form-label" for="settingsPostPermalinkCustom">Benutzerdefinierte Struktur</label>
                            <input type="text" name="post_permalink_custom" id="settingsPostPermalinkCustom" class="<?php echo htmlspecialchars($controlClass('post_permalink_custom'), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)($s['post_permalink_custom'] ?? '')); ?>" placeholder="/%year%/%monthnum%/%day%/%postname%" aria-describedby="<?php echo htmlspecialchars($controlDescribedBy('post_permalink_custom', ['settingsPostPermalinkCustomHint']), ENT_QUOTES, 'UTF-8'); ?>" aria-invalid="<?php echo $hasInvalidField('post_permalink_custom') ? 'true' : 'false'; ?>" data-settings-permalink-custom-input>
                            <div id="settingsPostPermalinkCustomHint" class="form-hint">Nur nötig bei „Benutzerdefiniert“. 365CMS ergänzt fehlende Slashes automatisch, erwartet aber trotzdem ein sinnvolles Beitragsmuster.</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="settingsCategoryBaseDe">Kategorie-Basis (DE)</label>
                                <div class="input-group">
                                    <span class="input-group-text">/</span>
                                    <input type="text" name="category_base_de" id="settingsCategoryBaseDe" class="<?php echo htmlspecialchars($controlClass('category_base_de'), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)($s['category_base_de'] ?? 'kategorie')); ?>" placeholder="kategorie" aria-describedby="<?php echo htmlspecialchars($controlDescribedBy('category_base_de', ['settingsArchiveBaseHint']), ENT_QUOTES, 'UTF-8'); ?>" aria-invalid="<?php echo $hasInvalidField('category_base_de') ? 'true' : 'false'; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="settingsCategoryBaseEn">Kategorie-Basis (EN)</label>
                                <div class="input-group">
                                    <span class="input-group-text">/</span>
                                    <input type="text" name="category_base_en" id="settingsCategoryBaseEn" class="<?php echo htmlspecialchars($controlClass('category_base_en'), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)($s['category_base_en'] ?? 'category')); ?>" placeholder="category" aria-describedby="<?php echo htmlspecialchars($controlDescribedBy('category_base_en', ['settingsArchiveBaseHint']), ENT_QUOTES, 'UTF-8'); ?>" aria-invalid="<?php echo $hasInvalidField('category_base_en') ? 'true' : 'false'; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="settingsTagBaseDe">Tag-Basis (DE)</label>
                                <div class="input-group">
                                    <span class="input-group-text">/</span>
                                    <input type="text" name="tag_base_de" id="settingsTagBaseDe" class="<?php echo htmlspecialchars($controlClass('tag_base_de'), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)($s['tag_base_de'] ?? 'tag')); ?>" placeholder="tag" aria-describedby="<?php echo htmlspecialchars($controlDescribedBy('tag_base_de', ['settingsArchiveBaseHint']), ENT_QUOTES, 'UTF-8'); ?>" aria-invalid="<?php echo $hasInvalidField('tag_base_de') ? 'true' : 'false'; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="settingsTagBaseEn">Tag-Basis (EN)</label>
                                <div class="input-group">
                                    <span class="input-group-text">/</span>
                                    <input type="text" name="tag_base_en" id="settingsTagBaseEn" class="<?php echo htmlspecialchars($controlClass('tag_base_en'), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)($s['tag_base_en'] ?? 'tag')); ?>" placeholder="tag" aria-describedby="<?php echo htmlspecialchars($controlDescribedBy('tag_base_en', ['settingsArchiveBaseHint']), ENT_QUOTES, 'UTF-8'); ?>" aria-invalid="<?php echo $hasInvalidField('tag_base_en') ? 'true' : 'false'; ?>">
                                </div>
                            </div>
                        </div>
                        <div id="settingsArchiveBaseHint" class="form-hint mb-3">Die Archive werden sprachabhängig auf dieselben Theme-Templates <code>category.php</code> und <code>tag.php</code> geleitet. Beispiel: <code>/<?php echo htmlspecialchars((string)($s['category_base_de'] ?? 'kategorie')); ?>/azure</code> bzw. <code>/en/<?php echo htmlspecialchars((string)($s['category_base_en'] ?? 'category')); ?>/azure</code>.</div>
                        <div class="cms-admin-info-box mb-3" role="status">
                            <div class="cms-admin-info-box__head">
                                <h3 class="cms-admin-info-box__title">Aktive Beispiel-URL</h3>
                            </div>
                            <p class="cms-admin-info-box__text">
                                <code><?php echo htmlspecialchars((string)($s['post_permalink_example'] ?? '/blog/beispielbeitrag')); ?></code><br>
                                <span class="small">Die Blog-Übersicht bleibt weiterhin unter <code>/blog</code> erreichbar.</span>
                            </p>
                        </div>
                        <div class="border rounded p-3 bg-light">
                            <div class="fw-semibold mb-1">Manuelle Nachkorrektur importierter Slugs</div>
                            <p class="text-secondary small mb-3">Prüft importierte Beiträge und Seiten aus dem WordPress-Importer und übernimmt – wenn möglich – den ursprünglichen Quell-Slug. Bereits verlinkte Pfade bleiben per Weiterleitung erreichbar.</p>
                            <button type="submit" class="btn btn-outline-warning" name="action" value="repair_imported_slugs">
                                Falsche Import-Slugs jetzt manuell korrigieren
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <h3 class="cms-settings-section-heading">Hinweise und Kontext</h3>
            </div>
            <div class="col-12 mb-4">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Hinweise</h3></div>
                    <div class="card-body">
                        <ul class="mb-0 text-secondary small ps-3">
                            <li class="mb-2">Die Editor-Auswahl greift global für Seiten und Beiträge.</li>
                            <li class="mb-2">Standard-Status gilt vor allem für neue Einträge; bestehende Inhalte behalten ihren Status.</li>
                            <li class="mb-2">Permalink- und Archiv-Basen sitzen jetzt direkt im Inhalts-Tab statt versteckt unter „Allgemein“.</li>
                            <li>Breiten werden direkt in den Editor-Ansichten übernommen.</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="cms-settings-actions cms-settings-actions-bottom sticky-footer">
                    <button type="submit" class="btn btn-primary" name="action" value="save">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-device-floppy me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 4h10l4 4v10a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2"/><path d="M12 14m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/><path d="M14 4l0 4l-6 0l0 -4"/></svg>
                        Einstellungen speichern
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($currentTab === 'general'): ?>
        <div class="modal modal-blur fade" id="settingsMediaPickerModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" data-media-picker-title>Datei auswählen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                    </div>
                    <div class="modal-body">
                        <div
                            data-media-picker-modal
                            data-api-url="<?php echo htmlspecialchars($mediaApiUrl, ENT_QUOTES); ?>"
                            data-csrf-token="<?php echo htmlspecialchars($editorMediaToken, ENT_QUOTES); ?>">
                            <p class="text-secondary small mb-3">Ein Klick übernimmt eine Datei direkt in das aktuell gewählte Feld. Angezeigt werden interne Bilddateien, Logos und Favicons.</p>
                            <div class="row g-2 align-items-center mb-3">
                                <div class="col-md-8">
                                    <input type="search" class="form-control" placeholder="Dateien filtern …" data-media-picker-search>
                                </div>
                                <div class="col-md-4 text-secondary small" data-media-picker-status>
                                    Lade Medien …
                                </div>
                            </div>
                            <div class="row row-cards g-3" data-media-picker-grid></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
