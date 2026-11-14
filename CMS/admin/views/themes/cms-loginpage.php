<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
$pageOptions = is_array($data['page_options'] ?? null) ? $data['page_options'] : [];
$registrationEnabled = !empty($data['registration_enabled']);
$passkeyEnabled = !empty($data['passkey_enabled']);
$previewUrls = is_array($data['preview_urls'] ?? null) ? $data['preview_urls'] : [];
$themeColors = is_array($data['theme_colors'] ?? null) ? $data['theme_colors'] : [];
$activeTheme = (string) ($data['active_theme'] ?? '');
$showcaseVisible = !empty($data['showcase_visible']);
$colorLabels = [
    'background_start' => 'Background Start',
    'background_end' => 'Background Ende',
    'card_background' => 'Card Hintergrund',
    'text_color' => 'Textfarbe',
    'muted_color' => 'Muted Text',
    'link_color' => 'Linkfarbe',
    'primary_color' => 'Primary Button',
    'primary_text_color' => 'Primary Text',
    'input_background' => 'Input Hintergrund',
    'input_border' => 'Input Border',
];
$showcaseImageUrl = (string) ($settings['showcase_image_url'] ?? '');
$showcaseImageWidth = (int) ($settings['showcase_image_width'] ?? 0);
$showcaseImageHeight = (int) ($settings['showcase_image_height'] ?? 0);
$showcaseFeaturesValue = (string) ($settings['showcase_features'] ?? '');
$showcaseFeatureCount = count(array_filter(
    preg_split('/\R/u', $showcaseFeaturesValue) ?: [],
    static fn (string $line): bool => trim($line) !== ''
));
$mediaApiUrl = '/api/media';
$editorMediaToken = \CMS\Security::instance()->generateToken('editorjs_media');
$themeColorsToken = $themeColors !== [] ? \CMS\Security::instance()->generateToken('admin_cms_loginpage') : '';
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
// Nach einem abgelehnten Speichern direkt den betroffenen Tab öffnen.
$initialTab = 'layout';
foreach ((is_array($alert ?? null) && is_array($alert['details'] ?? null)) ? $alert['details'] : [] as $alertDetail) {
    if (str_starts_with((string) $alertDetail, 'Showcase')) {
        $initialTab = 'showcase';
        break;
    }
}

$renderSelect = static function (string $name, string $label, array $options, string $selectedValue): void {
    ?>
    <div class="col-md-4">
        <label class="form-label" for="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></label>
        <select class="form-select" id="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" name="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
            <option value="0">— Nicht verlinken —</option>
            <?php foreach ($options as $option): ?>
                <?php $value = (string) ($option['id'] ?? '0'); ?>
                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedValue === $value ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string) ($option['title'] ?? $value), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
};
?>
<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Themes</div>
                <h2 class="page-title mb-1">CMS Loginpage</h2>
                <div class="content-listing-header__meta">
                    <span>Registrierung: <?php echo $registrationEnabled ? 'aktiv' : 'deaktiviert'; ?></span>
                    <span>Passkey: <?php echo $passkeyEnabled ? 'sichtbar' : 'ausgeblendet'; ?></span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars((string) ($previewUrls['login'] ?? '/cms-login'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Login-Preview</a>
                <a class="btn btn-outline-primary btn-sm" href="/admin/themes">Themes</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
<div class="container-xl py-3">
    <div class="row g-4">
        <div class="col-12">
            <div class="card admin-content-card">
                <div class="card-header">
                    <h2 class="card-title mb-0">CMS Loginpage</h2>
                </div>
                <div class="card-body">
                    <?php if (!empty($alert)): ?>
                        <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
                    <?php endif; ?>

                    <p class="text-secondary mb-4">Diese Einstellungen steuern die neue CMS-eigene Authentifizierungsstrecke für <code>/cms-login</code>, <code>/cms-register</code> und <code>/cms-password-forgot</code> – komplett unabhängig vom aktiven Frontend-Theme.</p>

                    <div class="alert alert-info mb-4" role="alert">
                        <strong>Status:</strong>
                        Registrierung ist aktuell <strong><?php echo $registrationEnabled ? 'aktiv' : 'deaktiviert'; ?></strong>,
                        Passkey-Login ist <strong><?php echo $passkeyEnabled ? 'sichtbar' : 'ausgeblendet'; ?></strong>.
                    </div>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <ul class="nav nav-tabs card-header-tabs cms-loginpage-tabs" role="tablist" data-initial-tab="<?php echo $esc($initialTab); ?>">
                            <li class="nav-item"><a class="nav-link active" href="#cms-login-tab-layout" data-login-tab="layout">Layout &amp; Grunddaten</a></li>
                            <li class="nav-item"><a class="nav-link" href="#cms-login-tab-colors" data-login-tab="colors">Farben</a></li>
                            <li class="nav-item"><a class="nav-link" href="#cms-login-tab-texts" data-login-tab="texts">Texte &amp; Labels</a></li>
                            <li class="nav-item"><a class="nav-link" href="#cms-login-tab-registration" data-login-tab="registration">Registrierung</a></li>
                            <li class="nav-item"><a class="nav-link" href="#cms-login-tab-forgot" data-login-tab="forgot">Passwort-Reset</a></li>
                            <li class="nav-item"><a class="nav-link" href="#cms-login-tab-email" data-login-tab="email">E-Mail-Templates</a></li>
                            <li class="nav-item"><a class="nav-link" href="#cms-login-tab-showcase" data-login-tab="showcase">Showcase</a></li>
                        </ul>

                        <div class="cms-loginpage-tab-panel is-active" id="cms-login-tab-layout" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="brand_name">Brandname</label>
                                    <input class="form-control" id="brand_name" name="brand_name" type="text" maxlength="80" value="<?php echo htmlspecialchars((string) ($settings['brand_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="logo_url">Logo-URL</label>
                                    <input class="form-control" id="logo_url" name="logo_url" type="text" value="<?php echo htmlspecialchars((string) ($settings['logo_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://example.com/logo.svg oder /uploads/theme/logo.svg">
                                    <div class="form-hint">Erlaubt sind absolute HTTP(S)-URLs sowie interne Pfade wie <code>/uploads/...</code>.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="layout_variant">Layout</label>
                                    <select class="form-select" id="layout_variant" name="layout_variant">
                                        <option value="centered" <?php echo ($settings['layout_variant'] ?? 'centered') === 'centered' ? 'selected' : ''; ?>>Centered Card</option>
                                        <option value="split" <?php echo ($settings['layout_variant'] ?? 'centered') === 'split' ? 'selected' : ''; ?>>Split Layout</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="auth_slug_mode">Öffentliche Auth-Slugs</label>
                                    <select class="form-select" id="auth_slug_mode" name="auth_slug_mode">
                                        <option value="cms" <?php echo ($settings['auth_slug_mode'] ?? 'cms') === 'cms' ? 'selected' : ''; ?>>CMS-Slugs (/cms-login, /cms-register, ...)</option>
                                        <option value="legacy" <?php echo ($settings['auth_slug_mode'] ?? 'cms') === 'legacy' ? 'selected' : ''; ?>>Legacy-Slugs (/login, /register, ...)</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="card_width">Kartenbreite (px)</label>
                                    <input class="form-control" id="card_width" name="card_width" type="number" min="380" max="960" value="<?php echo htmlspecialchars((string) ($settings['card_width'] ?? '520'), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="footer_note">Footer-Hinweis</label>
                                    <input class="form-control" id="footer_note" name="footer_note" type="text" maxlength="120" value="<?php echo htmlspecialchars((string) ($settings['footer_note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>
                            <div class="cms-loginpage-tab-save d-flex flex-wrap gap-2">
                                <button class="btn btn-primary" type="submit">Speichern</button>
                                <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars((string) ($previewUrls['login'] ?? '/cms-login'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Login ansehen</a>
                                <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars((string) ($previewUrls['register'] ?? '/cms-register'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Register ansehen</a>
                                <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars((string) ($previewUrls['forgot_password'] ?? '/cms-password-forgot'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Reset ansehen</a>
                            </div>
                        </div>

                        <div class="cms-loginpage-tab-panel" id="cms-login-tab-colors" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label" for="background_start">Background Start</label><input class="form-control form-control-color" id="background_start" name="background_start" type="color" value="<?php echo htmlspecialchars((string) ($settings['background_start'] ?? '#0f172a'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="background_end">Background Ende</label><input class="form-control form-control-color" id="background_end" name="background_end" type="color" value="<?php echo htmlspecialchars((string) ($settings['background_end'] ?? '#1d4ed8'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="card_background">Card Hintergrund</label><input class="form-control form-control-color" id="card_background" name="card_background" type="color" value="<?php echo htmlspecialchars((string) ($settings['card_background'] ?? '#ffffff'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="text_color">Textfarbe</label><input class="form-control form-control-color" id="text_color" name="text_color" type="color" value="<?php echo htmlspecialchars((string) ($settings['text_color'] ?? '#0f172a'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="muted_color">Muted Text</label><input class="form-control form-control-color" id="muted_color" name="muted_color" type="color" value="<?php echo htmlspecialchars((string) ($settings['muted_color'] ?? '#475569'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="link_color">Linkfarbe</label><input class="form-control form-control-color" id="link_color" name="link_color" type="color" value="<?php echo htmlspecialchars((string) ($settings['link_color'] ?? '#1d4ed8'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="primary_color">Primary Button</label><input class="form-control form-control-color" id="primary_color" name="primary_color" type="color" value="<?php echo htmlspecialchars((string) ($settings['primary_color'] ?? '#2563eb'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="primary_text_color">Primary Text</label><input class="form-control form-control-color" id="primary_text_color" name="primary_text_color" type="color" value="<?php echo htmlspecialchars((string) ($settings['primary_text_color'] ?? '#ffffff'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="input_background">Input Hintergrund</label><input class="form-control form-control-color" id="input_background" name="input_background" type="color" value="<?php echo htmlspecialchars((string) ($settings['input_background'] ?? '#f8fafc'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="input_border">Input Border</label><input class="form-control form-control-color" id="input_border" name="input_border" type="color" value="<?php echo htmlspecialchars((string) ($settings['input_border'] ?? '#cbd5e1'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                            </div>
                            <?php if ($themeColors !== []): ?>
                                <div class="border rounded p-3 mt-4 cms-loginpage-theme-colors" aria-labelledby="cms-loginpage-theme-colors-title" role="group">
                                    <h4 class="h6 mb-2" id="cms-loginpage-theme-colors-title">Farben aus dem aktiven Theme übernehmen</h4>
                                    <p class="text-secondary mb-3">Das aktive Theme <code><?php echo $esc($activeTheme); ?></code> schlägt in seiner <code>theme.json</code> (<code>login_page.colors</code>) folgende Farben vor. Beim Übernehmen werden nur diese Farbwerte gespeichert; nicht gespeicherte Änderungen in anderen Feldern gehen dabei verloren.</p>
                                    <ul class="cms-loginpage-theme-colors__list list-unstyled mb-3">
                                        <?php foreach ($themeColors as $colorKey => $colorValue): ?>
                                            <li class="cms-loginpage-theme-colors__item">
                                                <svg class="cms-loginpage-theme-colors__swatch" aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 18 18"><rect x="0.5" y="0.5" width="17" height="17" rx="4" fill="<?php echo $esc($colorValue); ?>" stroke="currentColor" stroke-opacity="0.35"/></svg>
                                                <span><?php echo $esc($colorLabels[$colorKey] ?? $colorKey); ?></span>
                                                <code><?php echo $esc($colorValue); ?></code>
                                                <?php if ((string) ($settings[$colorKey] ?? '') === $colorValue): ?>
                                                    <span class="text-secondary small">(bereits gesetzt)</span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <button class="btn btn-outline-primary" type="submit" form="cms-loginpage-theme-colors-form">Farben aus dem aktiven Theme übernehmen</button>
                                </div>
                            <?php endif; ?>
                            <div class="cms-loginpage-tab-save d-flex flex-wrap gap-2">
                                <button class="btn btn-primary" type="submit">Speichern</button>
                                <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars((string) ($previewUrls['login'] ?? '/cms-login'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Login ansehen</a>
                            </div>
                        </div>

                        <div class="cms-loginpage-tab-panel" id="cms-login-tab-texts" role="tabpanel">
                            <div class="row g-3 mb-4">
                                <div class="col-12"><label class="form-label" for="headline_login">Headline Login</label><input class="form-control" id="headline_login" name="headline_login" type="text" maxlength="120" value="<?php echo htmlspecialchars((string) ($settings['headline_login'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-12"><label class="form-label" for="subheadline_login">Subheadline Login</label><textarea class="form-control" id="subheadline_login" name="subheadline_login" rows="2"><?php echo htmlspecialchars((string) ($settings['subheadline_login'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                                <div class="col-12"><label class="form-label" for="headline_register">Headline Register</label><input class="form-control" id="headline_register" name="headline_register" type="text" maxlength="120" value="<?php echo htmlspecialchars((string) ($settings['headline_register'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-12"><label class="form-label" for="subheadline_register">Subheadline Register</label><textarea class="form-control" id="subheadline_register" name="subheadline_register" rows="2"><?php echo htmlspecialchars((string) ($settings['subheadline_register'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                                <div class="col-12"><label class="form-label" for="headline_forgot">Headline Passwort-Reset</label><input class="form-control" id="headline_forgot" name="headline_forgot" type="text" maxlength="120" value="<?php echo htmlspecialchars((string) ($settings['headline_forgot'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-12"><label class="form-label" for="subheadline_forgot">Subheadline Passwort-Reset</label><textarea class="form-control" id="subheadline_forgot" name="subheadline_forgot" rows="2"><?php echo htmlspecialchars((string) ($settings['subheadline_forgot'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                            </div>
                            <div class="border rounded p-3 mb-4">
                                <h4 class="h6 mb-3">Login Labels</h4>
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label" for="login_label_identifier">Label Benutzerfeld</label><input class="form-control" id="login_label_identifier" name="login_label_identifier" type="text" value="<?php echo htmlspecialchars((string) ($settings['login_label_identifier'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="login_label_password">Label Passwortfeld</label><input class="form-control" id="login_label_password" name="login_label_password" type="text" value="<?php echo htmlspecialchars((string) ($settings['login_label_password'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="login_identifier_placeholder">Placeholder Benutzerfeld</label><input class="form-control" id="login_identifier_placeholder" name="login_identifier_placeholder" type="text" value="<?php echo htmlspecialchars((string) ($settings['login_identifier_placeholder'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="login_password_placeholder">Placeholder Passwortfeld</label><input class="form-control" id="login_password_placeholder" name="login_password_placeholder" type="text" value="<?php echo htmlspecialchars((string) ($settings['login_password_placeholder'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-4"><label class="form-label" for="login_button_text">Button Login</label><input class="form-control" id="login_button_text" name="login_button_text" type="text" value="<?php echo htmlspecialchars((string) ($settings['login_button_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-4"><label class="form-label" for="login_forgot_link_text">Link Passwort vergessen</label><input class="form-control" id="login_forgot_link_text" name="login_forgot_link_text" type="text" value="<?php echo htmlspecialchars((string) ($settings['login_forgot_link_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-4"><label class="form-label" for="login_remember_label">Label „Angemeldet bleiben“</label><input class="form-control" id="login_remember_label" name="login_remember_label" type="text" value="<?php echo htmlspecialchars((string) ($settings['login_remember_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="login_passkey_button_text">Passkey-Button</label><input class="form-control" id="login_passkey_button_text" name="login_passkey_button_text" type="text" value="<?php echo htmlspecialchars((string) ($settings['login_passkey_button_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" id="login_show_remember" name="login_show_remember" type="checkbox" value="1" <?php echo ($settings['login_show_remember'] ?? '1') === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="login_show_remember">„Angemeldet bleiben“ anzeigen</label></div></div>
                                    <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" id="login_show_passkey" name="login_show_passkey" type="checkbox" value="1" <?php echo ($settings['login_show_passkey'] ?? '1') === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="login_show_passkey">Passkey-Login anzeigen</label></div></div>
                                </div>
                            </div>
                            <div class="border rounded p-3">
                                <h4 class="h6 mb-3">Recht &amp; Footer-Links</h4>
                                <div class="row g-3">
                                    <?php $renderSelect('privacy_page_id', 'Datenschutzerklärung', $pageOptions, (string) ($settings['privacy_page_id'] ?? '0')); ?>
                                    <?php $renderSelect('terms_page_id', 'Nutzungsbedingungen', $pageOptions, (string) ($settings['terms_page_id'] ?? '0')); ?>
                                    <?php $renderSelect('imprint_page_id', 'Impressum', $pageOptions, (string) ($settings['imprint_page_id'] ?? '0')); ?>
                                    <div class="col-md-3"><label class="form-label" for="footer_link_login">Footer-Link Login</label><input class="form-control" id="footer_link_login" name="footer_link_login" type="text" value="<?php echo htmlspecialchars((string) ($settings['footer_link_login'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-3"><label class="form-label" for="footer_link_register">Footer-Link Registrierung</label><input class="form-control" id="footer_link_register" name="footer_link_register" type="text" value="<?php echo htmlspecialchars((string) ($settings['footer_link_register'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-3"><label class="form-label" for="footer_link_forgot">Footer-Link Reset</label><input class="form-control" id="footer_link_forgot" name="footer_link_forgot" type="text" value="<?php echo htmlspecialchars((string) ($settings['footer_link_forgot'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-3"><label class="form-label" for="footer_link_home">Footer-Link Startseite</label><input class="form-control" id="footer_link_home" name="footer_link_home" type="text" value="<?php echo htmlspecialchars((string) ($settings['footer_link_home'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                </div>
                            </div>
                            <div class="cms-loginpage-tab-save d-flex flex-wrap gap-2">
                                <button class="btn btn-primary" type="submit">Speichern</button>
                                <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars((string) ($previewUrls['login'] ?? '/cms-login'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Login ansehen</a>
                            </div>
                        </div>

                        <div class="cms-loginpage-tab-panel" id="cms-login-tab-registration" role="tabpanel">
                            <div class="border rounded p-3">
                                <h4 class="h6 mb-3">Registrierung</h4>
                                <div class="row g-3">
                                    <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" id="registration_enabled" name="registration_enabled" type="checkbox" value="1" <?php echo ($settings['registration_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="registration_enabled">Globale Registrierung aktivieren</label></div></div>
                                    <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" id="member_registration_enabled" name="member_registration_enabled" type="checkbox" value="1" <?php echo ($settings['member_registration_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="member_registration_enabled">Mitglieder-Registrierung erlauben</label></div></div>
                                    <div class="col-md-6"><label class="form-label" for="register_label_email">Label E-Mail</label><input class="form-control" id="register_label_email" name="register_label_email" type="text" value="<?php echo htmlspecialchars((string) ($settings['register_label_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="register_label_username">Label Benutzername</label><input class="form-control" id="register_label_username" name="register_label_username" type="text" value="<?php echo htmlspecialchars((string) ($settings['register_label_username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="register_label_password">Label Passwort</label><input class="form-control" id="register_label_password" name="register_label_password" type="text" value="<?php echo htmlspecialchars((string) ($settings['register_label_password'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="register_label_password_confirm">Label Passwort bestätigen</label><input class="form-control" id="register_label_password_confirm" name="register_label_password_confirm" type="text" value="<?php echo htmlspecialchars((string) ($settings['register_label_password_confirm'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="register_email_placeholder">Placeholder E-Mail</label><input class="form-control" id="register_email_placeholder" name="register_email_placeholder" type="text" value="<?php echo htmlspecialchars((string) ($settings['register_email_placeholder'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="register_username_placeholder">Placeholder Benutzername</label><input class="form-control" id="register_username_placeholder" name="register_username_placeholder" type="text" value="<?php echo htmlspecialchars((string) ($settings['register_username_placeholder'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="register_button_text">Button Registrierung</label><input class="form-control" id="register_button_text" name="register_button_text" type="text" value="<?php echo htmlspecialchars((string) ($settings['register_button_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" id="register_require_terms" name="register_require_terms" type="checkbox" value="1" <?php echo ($settings['register_require_terms'] ?? '1') === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="register_require_terms">Rechts-Häkchen erzwingen</label></div></div>
                                    <div class="col-12"><label class="form-label" for="register_terms_label">Text für Rechts-Häkchen</label><textarea class="form-control" id="register_terms_label" name="register_terms_label" rows="2"><?php echo htmlspecialchars((string) ($settings['register_terms_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                                    <div class="col-12"><label class="form-label" for="register_disabled_message">Hinweis bei deaktivierter Registrierung</label><textarea class="form-control" id="register_disabled_message" name="register_disabled_message" rows="2"><?php echo htmlspecialchars((string) ($settings['register_disabled_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                                </div>
                            </div>
                            <div class="cms-loginpage-tab-save d-flex flex-wrap gap-2">
                                <button class="btn btn-primary" type="submit">Speichern</button>
                                <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars((string) ($previewUrls['register'] ?? '/cms-register'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Register ansehen</a>
                            </div>
                        </div>

                        <div class="cms-loginpage-tab-panel" id="cms-login-tab-forgot" role="tabpanel">
                            <div class="border rounded p-3">
                                <h4 class="h6 mb-3">Passwort vergessen</h4>
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label" for="forgot_label_email">Label E-Mail</label><input class="form-control" id="forgot_label_email" name="forgot_label_email" type="text" value="<?php echo htmlspecialchars((string) ($settings['forgot_label_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="forgot_email_placeholder">Placeholder E-Mail</label><input class="form-control" id="forgot_email_placeholder" name="forgot_email_placeholder" type="text" value="<?php echo htmlspecialchars((string) ($settings['forgot_email_placeholder'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-4"><label class="form-label" for="forgot_request_button_text">Button Reset anfordern</label><input class="form-control" id="forgot_request_button_text" name="forgot_request_button_text" type="text" value="<?php echo htmlspecialchars((string) ($settings['forgot_request_button_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-4"><label class="form-label" for="forgot_reset_button_text">Button Passwort ändern</label><input class="form-control" id="forgot_reset_button_text" name="forgot_reset_button_text" type="text" value="<?php echo htmlspecialchars((string) ($settings['forgot_reset_button_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-md-4"><label class="form-label" for="forgot_done_button_text">Button nach Erfolg</label><input class="form-control" id="forgot_done_button_text" name="forgot_done_button_text" type="text" value="<?php echo htmlspecialchars((string) ($settings['forgot_done_button_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="col-12"><label class="form-label" for="forgot_request_success_message">Erfolgsmeldung Link-Anforderung</label><textarea class="form-control" id="forgot_request_success_message" name="forgot_request_success_message" rows="2"><?php echo htmlspecialchars((string) ($settings['forgot_request_success_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                                    <div class="col-12"><label class="form-label" for="forgot_reset_success_message">Erfolgsmeldung Passwort-Reset</label><textarea class="form-control" id="forgot_reset_success_message" name="forgot_reset_success_message" rows="2"><?php echo htmlspecialchars((string) ($settings['forgot_reset_success_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                                </div>
                            </div>
                            <div class="cms-loginpage-tab-save d-flex flex-wrap gap-2">
                                <button class="btn btn-primary" type="submit">Speichern</button>
                                <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars((string) ($previewUrls['forgot_password'] ?? '/cms-password-forgot'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Reset ansehen</a>
                            </div>
                        </div>

                        <div class="cms-loginpage-tab-panel" id="cms-login-tab-email" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label" for="password_reset_expiry_minutes">Link gültig (Minuten)</label><input class="form-control" id="password_reset_expiry_minutes" name="password_reset_expiry_minutes" type="number" min="5" max="1440" value="<?php echo htmlspecialchars((string) ($settings['password_reset_expiry_minutes'] ?? '60'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-12"><label class="form-label" for="password_reset_email_subject">Mail-Betreff</label><input class="form-control" id="password_reset_email_subject" name="password_reset_email_subject" type="text" value="<?php echo htmlspecialchars((string) ($settings['password_reset_email_subject'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <div class="col-12">
                                    <label class="form-label" for="password_reset_email_body">Mail-Text</label>
                                    <textarea class="form-control" id="password_reset_email_body" name="password_reset_email_body" rows="8"><?php echo htmlspecialchars((string) ($settings['password_reset_email_body'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    <div class="form-hint">Verfügbare Platzhalter: <code>{site_name}</code>, <code>{brand_name}</code>, <code>{expires_minutes}</code>, <code>{reset_url}</code></div>
                                </div>
                            </div>
                            <div class="cms-loginpage-tab-save d-flex flex-wrap gap-2">
                                <button class="btn btn-primary" type="submit">Speichern</button>
                                <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars((string) ($previewUrls['forgot_password'] ?? '/cms-password-forgot'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Reset ansehen</a>
                            </div>
                        </div>

                        <div class="cms-loginpage-tab-panel" id="cms-login-tab-showcase" role="tabpanel">
                            <p class="text-secondary mb-3">Der Showcase erscheint als eigener Bereich unter dem Anmeldeformular (Bild und Text, z. B. Vorteile der Mitgliedschaft). Er wird nur angezeigt, wenn er aktiviert ist und mindestens eine Überschrift oder ein Feature-Punkt gesetzt ist.</p>
                            <div class="row g-3">
                                <div class="col-md-6 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" id="showcase_enabled" name="showcase_enabled" type="checkbox" value="1" <?php echo ($settings['showcase_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="showcase_enabled">Showcase anzeigen</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="showcase_pages">Anzeigen auf</label>
                                    <select class="form-select" id="showcase_pages" name="showcase_pages">
                                        <option value="login" <?php echo ($settings['showcase_pages'] ?? 'login') === 'login' ? 'selected' : ''; ?>>Nur Anmeldung</option>
                                        <option value="login_register" <?php echo ($settings['showcase_pages'] ?? 'login') === 'login_register' ? 'selected' : ''; ?>>Anmeldung und Registrierung</option>
                                    </select>
                                </div>
                            </div>

                            <div class="border rounded p-3 mt-4">
                                <h4 class="h6 mb-3">Bild</h4>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label" for="showcase_image_url">Bild aus der Mediathek</label>
                                        <div class="input-group">
                                            <input class="form-control" id="showcase_image_url" name="showcase_image_url" type="text" value="<?php echo $esc($showcaseImageUrl); ?>" placeholder="/uploads/…/screenshot.webp" aria-describedby="showcase_image_url_hint" data-media-target-input>
                                            <button type="button" class="btn btn-outline-secondary" data-open-media-picker data-target-input="showcase_image_url" data-preview-id="showcase_image_preview" data-picker-title="Showcase-Bild auswählen">Medien</button>
                                            <button type="button" class="btn btn-outline-secondary" data-clear-media-input data-target-input="showcase_image_url" data-preview-id="showcase_image_preview">Leeren</button>
                                        </div>
                                        <div class="form-hint" id="showcase_image_url_hint">Bild zuerst in der <a href="/admin/media">Mediathek</a> hochladen und hier auswählen. Erlaubt sind nur Dateien unter <code>/uploads/</code> (JPG, PNG, WebP, AVIF); externe Adressen werden abgelehnt. Liegt neben einem JPG/PNG eine gleichnamige <code>.webp</code>-Datei, wird sie automatisch bevorzugt.</div>
                                        <div id="showcase_image_preview" class="mt-2 cms-loginpage-showcase-preview" data-media-preview data-preview-variant="screenshot" data-input-id="showcase_image_url" <?php echo $showcaseImageUrl === '' ? 'hidden' : ''; ?>>
                                            <?php if ($showcaseImageUrl !== ''): ?>
                                                <img class="cms-loginpage-showcase-preview__img" src="<?php echo $esc($showcaseImageUrl); ?>" alt="Bildvorschau">
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($showcaseImageWidth > 0 && $showcaseImageHeight > 0): ?>
                                            <div class="form-hint">Gespeicherte Abmessungen: <?php echo $showcaseImageWidth; ?> × <?php echo $showcaseImageHeight; ?> px (werden beim Speichern neu ermittelt).</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label" for="showcase_image_alt">Alternativtext</label>
                                        <input class="form-control" id="showcase_image_alt" name="showcase_image_alt" type="text" maxlength="160" value="<?php echo $esc($settings['showcase_image_alt'] ?? ''); ?>" aria-describedby="showcase_image_alt_hint">
                                        <div class="form-hint" id="showcase_image_alt_hint">Pflicht, wenn ein Bild gewählt ist: beschreibt den Bildinhalt für Screenreader (max. 160 Zeichen).</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="showcase_image_position">Bildposition (ab Tablet-Breite)</label>
                                        <select class="form-select" id="showcase_image_position" name="showcase_image_position">
                                            <option value="left" <?php echo ($settings['showcase_image_position'] ?? 'left') === 'left' ? 'selected' : ''; ?>>Bild links, Text rechts</option>
                                            <option value="right" <?php echo ($settings['showcase_image_position'] ?? 'left') === 'right' ? 'selected' : ''; ?>>Text links, Bild rechts</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="border rounded p-3 mt-4">
                                <h4 class="h6 mb-3">Text</h4>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label" for="showcase_eyebrow">Eyebrow (Dachzeile)</label>
                                        <input class="form-control" id="showcase_eyebrow" name="showcase_eyebrow" type="text" maxlength="60" value="<?php echo $esc($settings['showcase_eyebrow'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label" for="showcase_headline">Überschrift</label>
                                        <input class="form-control" id="showcase_headline" name="showcase_headline" type="text" maxlength="120" value="<?php echo $esc($settings['showcase_headline'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="showcase_text">Text</label>
                                        <textarea class="form-control" id="showcase_text" name="showcase_text" rows="4" maxlength="600" aria-describedby="showcase_text_hint"><?php echo $esc($settings['showcase_text'] ?? ''); ?></textarea>
                                        <div class="form-hint" id="showcase_text_hint">Kein HTML, max. 600 Zeichen. Jede Zeile wird ein eigener Absatz.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="showcase_features">Feature-Liste</label>
                                        <textarea class="form-control" id="showcase_features" name="showcase_features" rows="6" aria-describedby="showcase_features_hint showcase_features_count" data-showcase-feature-input data-max-lines="8" data-max-length="140"><?php echo $esc($showcaseFeaturesValue); ?></textarea>
                                        <div class="form-hint" id="showcase_features_hint">Ein Punkt pro Zeile, höchstens 8 Punkte mit je 140 Zeichen. Weitere Zeilen werden beim Speichern verworfen.</div>
                                        <div class="form-hint" id="showcase_features_count" data-showcase-feature-count aria-live="polite"><?php echo $showcaseFeatureCount; ?> von 8 Punkten</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="showcase_cta_label">Link-Text (optional)</label>
                                        <input class="form-control" id="showcase_cta_label" name="showcase_cta_label" type="text" maxlength="60" value="<?php echo $esc($settings['showcase_cta_label'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label" for="showcase_cta_url">Link-Ziel (optional)</label>
                                        <input class="form-control" id="showcase_cta_url" name="showcase_cta_url" type="text" value="<?php echo $esc($settings['showcase_cta_url'] ?? ''); ?>" placeholder="/mitgliedschaft oder https://example.com/" aria-describedby="showcase_cta_url_hint">
                                        <div class="form-hint" id="showcase_cta_url_hint">Interner Pfad oder http(s)-Adresse; Linktext und Ziel nur gemeinsam.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="cms-loginpage-tab-save d-flex flex-wrap gap-2 align-items-center">
                                <button class="btn btn-primary" type="submit">Speichern</button>
                                <a class="btn btn-outline-primary" href="<?php echo $esc($previewUrls['showcase'] ?? '/cms-login#cms-auth-showcase'); ?>" target="_blank" rel="noopener noreferrer">Showcase ansehen</a>
                                <?php if (!empty($previewUrls['showcase_register'])): ?>
                                    <a class="btn btn-outline-primary" href="<?php echo $esc($previewUrls['showcase_register']); ?>" target="_blank" rel="noopener noreferrer">Auf Registrierung ansehen</a>
                                <?php endif; ?>
                                <span class="text-secondary small"><?php echo $showcaseVisible ? 'Gespeicherter Stand: Showcase wird angezeigt.' : 'Gespeicherter Stand: Showcase wird nicht angezeigt.'; ?></span>
                            </div>
                        </div>
                    </form>

                    <?php if ($themeColors !== []): ?>
                        <form method="POST" id="cms-loginpage-theme-colors-form" hidden>
                            <input type="hidden" name="csrf_token" value="<?php echo $esc($themeColorsToken); ?>">
                            <input type="hidden" name="loginpage_action" value="import_theme_colors">
                        </form>
                    <?php endif; ?>

                    <div class="modal modal-blur fade" id="settingsMediaPickerModal" tabindex="-1" aria-hidden="true" aria-labelledby="cms-loginpage-media-picker-title">
                        <div class="modal-dialog modal-xl modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="cms-loginpage-media-picker-title" data-media-picker-title>Datei auswählen</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                                </div>
                                <div class="modal-body">
                                    <div data-media-picker-modal data-api-url="<?php echo $esc($mediaApiUrl); ?>" data-csrf-token="<?php echo $esc($editorMediaToken); ?>">
                                        <p class="text-secondary small mb-3">Ein Klick übernimmt das Bild in das Showcase-Feld. Neue Bilder zuerst in der <a href="/admin/media">Mediathek</a> hochladen.</p>
                                        <div class="row g-2 align-items-center mb-3">
                                            <div class="col-md-8">
                                                <input type="search" class="form-control" aria-label="Dateien filtern" placeholder="Dateien filtern …" data-media-picker-search>
                                            </div>
                                            <div class="col-md-4 text-secondary small" data-media-picker-status aria-live="polite">Lade Medien …</div>
                                        </div>
                                        <div class="row row-cards g-3" data-media-picker-grid></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">Hinweise</h3>
                </div>
                <div class="card-body text-secondary">
                    <ul class="mb-0 ps-3">
                        <li>Die Auth-Seiten laufen unabhängig vom aktiven Frontend-Theme.</li>
                        <li>Die kanonischen Core-Slugs bleiben <code>/cms-login</code>, <code>/cms-register</code> und <code>/cms-password-forgot</code>.</li>
                        <li>Per Auswahl oben kannst du entscheiden, ob Themes bevorzugt die CMS-Slugs oder die Legacy-Slugs <code>/login</code>, <code>/register</code> und <code>/forgot-password</code> verlinken sollen.</li>
                        <li>Bestehende alte Pfade bleiben als Alias funktionsfähig und leiten intern sicher weiter.</li>
                        <li>Die Register-Freigabe nutzt bewusst die vorhandenen globalen CMS-Einstellungen statt eines zweiten parallelen Schalters.</li>
                        <li>„Angemeldet bleiben“ ist jetzt ein echter Persistenz-Schalter für die Session – keine UI-Attrappe mehr.</li>
                        <li>Der Showcase steht unter dem Anmeldebereich. Themes können sein Markup mit <code>auth/showcase.php</code> überschreiben; im Legacy-Modus erhalten die Theme-Vorlagen dieselben Daten als <code>$showcase</code>.</li>
                        <li>Themes können in ihrer <code>theme.json</code> unter <code>login_page.colors</code> Farben für diese Seite vorschlagen; übernommen werden sie nur über die Schaltfläche im Tab „Farben“.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<script <?= \CMS\Security::instance()->nonceAttr() ?>>
(function () {
    var tabLinks = Array.prototype.slice.call(document.querySelectorAll('[data-login-tab]'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('.cms-loginpage-tab-panel'));

    function activateTab(tabKey) {
        tabLinks.forEach(function (link) {
            var isActive = link.getAttribute('data-login-tab') === tabKey;
            link.classList.toggle('active', isActive);
            link.setAttribute('aria-current', isActive ? 'page' : 'false');
        });

        panels.forEach(function (panel) {
            var panelKey = panel.id.replace('cms-login-tab-', '');
            panel.classList.toggle('is-active', panelKey === tabKey);
        });
    }

    tabLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var tabKey = link.getAttribute('data-login-tab') || 'layout';
            activateTab(tabKey);
        });
    });

    var tabList = document.querySelector('.cms-loginpage-tabs');
    var initialTab = tabList ? (tabList.getAttribute('data-initial-tab') || 'layout') : 'layout';
    var hashTab = (window.location.hash || '').replace('#cms-login-tab-', '');
    if (hashTab !== '' && document.getElementById('cms-login-tab-' + hashTab)) {
        initialTab = hashTab;
    }
    if (initialTab !== 'layout' && document.getElementById('cms-login-tab-' + initialTab)) {
        activateTab(initialTab);
    }

    // Zeilenzähler der Feature-Liste (Showcase): höchstens 8 Punkte à 140 Zeichen.
    var featureInput = document.querySelector('[data-showcase-feature-input]');
    var featureCount = document.querySelector('[data-showcase-feature-count]');
    if (featureInput && featureCount) {
        var maxLines = parseInt(featureInput.getAttribute('data-max-lines') || '8', 10);
        var maxLength = parseInt(featureInput.getAttribute('data-max-length') || '140', 10);
        var updateFeatureCount = function () {
            var lines = String(featureInput.value || '').split(/\r\n|\r|\n/).filter(function (line) {
                return line.trim() !== '';
            });
            var tooLong = lines.filter(function (line) {
                return line.trim().length > maxLength;
            }).length;
            var text = lines.length + ' von ' + maxLines + ' Punkten';
            if (lines.length > maxLines) {
                text += ' – ' + (lines.length - maxLines) + ' Zeile(n) zu viel';
            }
            if (tooLong > 0) {
                text += ' – ' + tooLong + ' Punkt(e) länger als ' + maxLength + ' Zeichen';
            }
            featureCount.textContent = text;
            featureCount.classList.toggle('text-danger', lines.length > maxLines || tooLong > 0);
        };
        featureInput.addEventListener('input', updateFeatureCount);
        updateFeatureCount();
    }
})();
</script>
