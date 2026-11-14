<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$brandName = trim((string) ($settings['brand_name'] ?? '')) !== ''
    ? (string) $settings['brand_name']
    : (defined('SITE_NAME') ? (string) SITE_NAME : '365CMS');
$logoUrl = trim((string) ($settings['logo_url'] ?? ''));
$cardWidth = max(380, min(960, (int) ($settings['card_width'] ?? 520)));
$layoutVariant = in_array((string) ($settings['layout_variant'] ?? 'centered'), ['centered', 'split'], true)
    ? (string) ($settings['layout_variant'] ?? 'centered')
    : 'centered';
$headline = match ($pageType) {
    'confirm-email' => (string) ($confirmEmail['title'] ?? ''),
    'register' => (string) ($settings['headline_register'] ?? 'Neues Konto erstellen'),
    'forgot-password' => (string) ($settings['headline_forgot'] ?? 'Passwort zurücksetzen'),
    default => (string) ($settings['headline_login'] ?? 'Willkommen zurück'),
};
$subheadline = match ($pageType) {
    'confirm-email' => (string) ($confirmEmail['text'] ?? ''),
    'register' => (string) ($settings['subheadline_register'] ?? ''),
    'forgot-password' => (string) ($settings['subheadline_forgot'] ?? ''),
    default => (string) ($settings['subheadline_login'] ?? ''),
};
$formAction = match ($pageType) {
    'confirm-email' => (string) ($confirmEmail['action'] ?? ''),
    'register' => $registerUrl,
    'forgot-password' => $forgotUrl,
    default => $loginUrl,
};
$loginValue = trim((string) ($oldInput['username'] ?? $oldInput['email'] ?? ''));
$registerEmailValue = trim((string) ($oldInput['email'] ?? ''));
$registerUsernameValue = trim((string) ($oldInput['username'] ?? ''));
$forgotEmailValue = trim((string) ($oldInput['email'] ?? ''));
$termsChecked = !empty($oldInput['terms']);
$rememberChecked = !empty($oldInput['remember']);
$passkeyAvailable = !empty($passkeyPayload['available']);
$passkeyOptionsJson = (string) ($passkeyPayload['options_json'] ?? '{}');
$footerNote = trim((string) ($settings['footer_note'] ?? ''));
$showRemember = ($settings['login_show_remember'] ?? '1') === '1';
$loginIdentifierLabel = (string) ($settings['login_label_identifier'] ?? 'Benutzername oder E-Mail');
$loginPasswordLabel = (string) ($settings['login_label_password'] ?? 'Passwort');
$loginButtonText = (string) ($settings['login_button_text'] ?? 'Anmelden');
$loginForgotLinkText = (string) ($settings['login_forgot_link_text'] ?? 'Vergessen?');
$loginIdentifierPlaceholder = (string) ($settings['login_identifier_placeholder'] ?? 'name@example.de');
$loginPasswordPlaceholder = (string) ($settings['login_password_placeholder'] ?? 'Dein Passwort');
$loginRememberLabel = (string) ($settings['login_remember_label'] ?? 'Angemeldet bleiben');
$loginPasskeyButtonText = (string) ($settings['login_passkey_button_text'] ?? 'Mit Passkey anmelden');
$registerButtonText = (string) ($settings['register_button_text'] ?? 'Konto erstellen');
$registerEmailPlaceholder = (string) ($settings['register_email_placeholder'] ?? 'deine@email.de');
$registerUsernamePlaceholder = (string) ($settings['register_username_placeholder'] ?? 'mein_name');
$registerRequireTerms = ($settings['register_require_terms'] ?? '1') === '1';
$registerTermsLabel = (string) ($settings['register_terms_label'] ?? 'Ich akzeptiere die Nutzungsbedingungen und die Datenschutzerklärung.');
$registerDisabledMessage = (string) ($settings['register_disabled_message'] ?? 'Die Registrierung ist aktuell deaktiviert.');
$forgotLabelEmail = (string) ($settings['forgot_label_email'] ?? 'E-Mail-Adresse');
$forgotEmailPlaceholder = (string) ($settings['forgot_email_placeholder'] ?? 'deine@email.de');
$forgotRequestButtonText = (string) ($settings['forgot_request_button_text'] ?? 'Reset-Link senden');
$forgotResetButtonText = (string) ($settings['forgot_reset_button_text'] ?? 'Passwort ändern');
$forgotDoneButtonText = (string) ($settings['forgot_done_button_text'] ?? 'Jetzt anmelden');
$footerLoginText = (string) ($settings['footer_link_login'] ?? 'Zur Anmeldung');
$footerRegisterText = (string) ($settings['footer_link_register'] ?? 'Registrieren');
$footerForgotText = (string) ($settings['footer_link_forgot'] ?? 'Passwort vergessen');
$footerHomeText = (string) ($settings['footer_link_home'] ?? 'Zur Startseite');
$documentLanguage = trim((string) ($documentLanguage ?? $requestLocale ?? 'de'));
$passwordPolicyHint = 'Mindestens 12 Zeichen sowie Groß-/Kleinbuchstabe, Zahl und Sonderzeichen.';
$passkeyErrorText = trim((string) ($passkeyErrorText ?? '')) !== ''
    ? (string) $passkeyErrorText
    : 'Die Passkey-Anmeldung konnte nicht abgeschlossen werden. Bitte erneut versuchen oder Passwort-Login nutzen.';
// Initialen für das Logo-Fallback multibyte-sicher (Umlaute/Emoji nicht zerschneiden).
$brandInitials = function_exists('mb_substr') ? (string) mb_substr($brandName, 0, 2, 'UTF-8') : (string) substr($brandName, 0, 2);
$showcase = is_array($showcase ?? null) ? $showcase : null;
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($documentLanguage !== '' ? $documentLanguage : 'de', ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <?= function_exists('cms_csp_runtime_tags') ? cms_csp_runtime_tags() : '' ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitleText, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php if (class_exists('\CMS\ThemeManager')) { try { \CMS\ThemeManager::instance()->renderSiteFavicon(); } catch (\Throwable) {} } ?>
    <style <?= \CMS\Security::instance()->nonceAttr() ?>>
        :root {
            --cms-auth-bg-start: <?php echo htmlspecialchars((string) ($settings['background_start'] ?? '#0f172a'), ENT_QUOTES, 'UTF-8'); ?>;
            --cms-auth-bg-end: <?php echo htmlspecialchars((string) ($settings['background_end'] ?? '#1d4ed8'), ENT_QUOTES, 'UTF-8'); ?>;
            --cms-auth-card-bg: <?php echo htmlspecialchars((string) ($settings['card_background'] ?? '#ffffff'), ENT_QUOTES, 'UTF-8'); ?>;
            --cms-auth-text: <?php echo htmlspecialchars((string) ($settings['text_color'] ?? '#0f172a'), ENT_QUOTES, 'UTF-8'); ?>;
            --cms-auth-muted: <?php echo htmlspecialchars((string) ($settings['muted_color'] ?? '#475569'), ENT_QUOTES, 'UTF-8'); ?>;
            --cms-auth-primary: <?php echo htmlspecialchars((string) ($settings['primary_color'] ?? '#2563eb'), ENT_QUOTES, 'UTF-8'); ?>;
            --cms-auth-primary-text: <?php echo htmlspecialchars((string) ($settings['primary_text_color'] ?? '#ffffff'), ENT_QUOTES, 'UTF-8'); ?>;
            --cms-auth-link: <?php echo htmlspecialchars((string) ($settings['link_color'] ?? '#1d4ed8'), ENT_QUOTES, 'UTF-8'); ?>;
            --cms-auth-input-bg: <?php echo htmlspecialchars((string) ($settings['input_background'] ?? '#f8fafc'), ENT_QUOTES, 'UTF-8'); ?>;
            --cms-auth-input-border: <?php echo htmlspecialchars((string) ($settings['input_border'] ?? '#cbd5e1'), ENT_QUOTES, 'UTF-8'); ?>;
            --cms-auth-card-width: <?php echo $cardWidth; ?>px;
            --cms-auth-showcase-width: 1040px;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            color: var(--cms-auth-text);
            background-color: var(--cms-auth-bg-start);
            background-image: linear-gradient(160deg, var(--cms-auth-bg-start) 0%, var(--cms-auth-bg-end) 100%);
            background-attachment: fixed;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            padding: 2rem 1rem;
        }
        .cms-auth-shell {
            width: 100%;
            max-width: var(--cms-auth-card-width);
        }
        .cms-auth-shell--split {
            max-width: min(1180px, calc(var(--cms-auth-card-width) + 360px));
            display: grid;
            gap: 1.5rem;
            align-items: stretch;
        }
        @media (min-width: 960px) {
            .cms-auth-shell--split {
                grid-template-columns: minmax(280px, 1fr) minmax(380px, var(--cms-auth-card-width));
            }
        }
        .cms-auth-panel {
            position: relative;
            overflow: hidden;
            border-radius: 24px;
            padding: 2rem;
            color: #fff;
            background: linear-gradient(160deg, rgba(15, 23, 42, 0.92) 0%, rgba(29, 78, 216, 0.78) 100%);
            box-shadow: 0 30px 80px rgba(15, 23, 42, 0.22);
        }
        .cms-auth-panel::after {
            content: '';
            position: absolute;
            inset: auto -10% -20% auto;
            width: 240px;
            height: 240px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            filter: blur(10px);
            pointer-events: none;
        }
        .cms-auth-panel .cms-auth-brand {
            position: relative;
            z-index: 1;
            text-align: left;
            margin-bottom: 0;
        }
        .cms-auth-panel .cms-auth-logo {
            margin: 0 0 1rem;
            background: rgba(255,255,255,0.12);
        }
        .cms-auth-panel .cms-auth-logo-fallback,
        .cms-auth-panel .cms-auth-brand p,
        .cms-auth-panel .cms-auth-brand h1,
        .cms-auth-panel a {
            color: inherit;
        }
        .cms-auth-panel .cms-auth-brand .cms-auth-panel-headline {
            margin: 0.65rem 0 0;
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .cms-auth-panel-links {
            margin-top: 2rem;
            display: grid;
            gap: 0.75rem;
            font-size: 0.95rem;
        }
        .cms-auth-panel-links a {
            text-decoration: none;
            font-weight: 600;
        }
        .cms-auth-panel-links a:hover {
            text-decoration: underline;
        }
        .cms-auth-card {
            background: var(--cms-auth-card-bg);
            border-radius: 24px;
            padding: 2rem;
            box-shadow: 0 30px 80px rgba(15, 23, 42, 0.28);
            border: 1px solid rgba(255,255,255,0.15);
        }
        .cms-auth-brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .cms-auth-logo {
            width: 72px;
            height: 72px;
            margin: 0 auto 1rem;
            border-radius: 20px;
            background: rgba(37, 99, 235, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .cms-auth-logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .cms-auth-logo-fallback {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--cms-auth-primary);
        }
        .cms-auth-brand h1 {
            margin: 0;
            font-size: 1.8rem;
            line-height: 1.2;
        }
        .cms-auth-brand p {
            margin: 0.65rem 0 0;
            color: var(--cms-auth-muted);
            line-height: 1.5;
        }
        .cms-auth-alert {
            padding: 0.9rem 1rem;
            border-radius: 14px;
            margin-bottom: 1rem;
            font-size: 0.95rem;
            line-height: 1.45;
        }
        .cms-auth-alert--error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .cms-auth-alert--success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .cms-auth-form {
            display: grid;
            gap: 1rem;
        }
        .cms-auth-grid {
            display: grid;
            gap: 1rem;
        }
        @media (min-width: 640px) {
            .cms-auth-grid--2 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        .cms-auth-field label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.45rem;
            font-size: 0.95rem;
            font-weight: 600;
        }
        .cms-auth-label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.45rem;
            font-size: 0.95rem;
        }
        .cms-auth-label-row label {
            margin-bottom: 0;
        }
        .cms-auth-link-inline,
        .cms-auth-footer a {
            color: var(--cms-auth-link);
            text-decoration: none;
        }
        .cms-auth-link-inline:hover,
        .cms-auth-footer a:hover {
            text-decoration: underline;
        }
        .cms-auth-input,
        .cms-auth-button,
        .cms-auth-secondary-button {
            width: 100%;
            border-radius: 14px;
            font-size: 1rem;
        }
        .cms-auth-input {
            border: 1px solid var(--cms-auth-input-border);
            background: var(--cms-auth-input-bg);
            padding: 0.9rem 1rem;
            color: var(--cms-auth-text);
        }
        .cms-auth-input:focus {
            outline: 2px solid rgba(37, 99, 235, 0.2);
            border-color: var(--cms-auth-primary);
        }
        .cms-auth-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            font-size: 0.92rem;
            color: var(--cms-auth-muted);
            line-height: 1.45;
        }
        .cms-auth-checkbox input {
            margin-top: 0.2rem;
        }
        .cms-auth-button,
        .cms-auth-secondary-button {
            border: none;
            padding: 0.95rem 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
        }
        .cms-auth-button {
            background: var(--cms-auth-primary);
            color: var(--cms-auth-primary-text);
            box-shadow: 0 14px 30px rgba(37, 99, 235, 0.28);
        }
        .cms-auth-secondary-button {
            background: rgba(37, 99, 235, 0.08);
            color: var(--cms-auth-primary);
            border: 1px solid rgba(37, 99, 235, 0.18);
        }
        .cms-auth-button:hover,
        .cms-auth-secondary-button:hover {
            transform: translateY(-1px);
        }
        .cms-auth-footer {
            margin-top: 1.5rem;
            text-align: center;
            color: var(--cms-auth-muted);
            font-size: 0.93rem;
            line-height: 1.6;
        }
        .cms-auth-footer-note {
            margin-top: 1rem;
            font-size: 0.8rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.85;
        }
        .cms-auth-help {
            margin-top: 0.45rem;
            color: var(--cms-auth-muted);
            font-size: 0.85rem;
            line-height: 1.45;
        }
        .cms-auth-divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--cms-auth-muted);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin: 0.25rem 0;
        }
        .cms-auth-divider::before,
        .cms-auth-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(148, 163, 184, 0.4);
        }
        .cms-auth-card-title {
            margin-top: 0;
        }
        .cms-auth-card-subtitle {
            margin-top: -0.2rem;
            color: var(--cms-auth-muted);
            line-height: 1.5;
        }
        .cms-auth-passkey-message {
            margin: 0.75rem 0 0;
        }
        .cms-auth-passkey-message:empty {
            display: none;
        }
        /* Login-Showcase (LS-09/LS-10): Mobile-First einspaltig, Text vor Bild; ab 768 px zweispaltig. */
        .cms-auth-showcase {
            width: 100%;
            max-width: var(--cms-auth-showcase-width);
            display: grid;
            gap: 1.5rem;
            padding: 2rem;
            border-radius: 24px;
            background: var(--cms-auth-card-bg);
            color: var(--cms-auth-text);
            box-shadow: 0 30px 80px color-mix(in srgb, var(--cms-auth-text) 22%, transparent);
        }
        .cms-auth-shell--split ~ .cms-auth-showcase {
            max-width: max(var(--cms-auth-showcase-width), min(1180px, calc(var(--cms-auth-card-width) + 360px)));
        }
        .cms-auth-showcase__content {
            min-width: 0;
        }
        .cms-auth-showcase__eyebrow {
            margin: 0 0 0.5rem;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--cms-auth-link);
        }
        .cms-auth-showcase__title {
            margin: 0 0 0.75rem;
            font-size: 1.5rem;
            line-height: 1.25;
        }
        .cms-auth-showcase__text {
            margin: 0 0 0.75rem;
            color: var(--cms-auth-muted);
            line-height: 1.6;
        }
        .cms-auth-showcase__features {
            list-style: none;
            margin: 1rem 0 0;
            padding: 0;
            display: grid;
            gap: 0.6rem;
        }
        .cms-auth-showcase__feature {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            line-height: 1.5;
        }
        .cms-auth-showcase__check {
            flex: 0 0 auto;
            margin-top: 0.15rem;
            color: var(--cms-auth-primary);
        }
        .cms-auth-showcase__cta-row {
            margin: 1.5rem 0 0;
        }
        .cms-auth-showcase__cta {
            display: inline-block;
            padding: 0.75rem 1.25rem;
            border-radius: 14px;
            font-weight: 700;
            text-decoration: none;
            background: var(--cms-auth-primary);
            color: var(--cms-auth-primary-text);
        }
        .cms-auth-showcase__cta:hover {
            text-decoration: underline;
        }
        .cms-auth-showcase a:focus-visible {
            outline: 3px solid var(--cms-auth-link);
            outline-offset: 3px;
        }
        .cms-auth-showcase__media {
            margin: 0;
            min-width: 0;
        }
        .cms-auth-showcase__image {
            display: block;
            width: 100%;
            height: auto;
            border-radius: 16px;
            border: 1px solid var(--cms-auth-input-border);
            background: var(--cms-auth-input-bg);
        }
        @media (min-width: 768px) {
            .cms-auth-showcase {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                align-items: center;
                gap: 2rem;
                padding: 2.5rem;
            }
            .cms-auth-showcase--no-image {
                grid-template-columns: minmax(0, 1fr);
            }
            .cms-auth-showcase--image-left .cms-auth-showcase__media {
                order: -1;
            }
        }
    </style>
</head>
<body>
    <main class="cms-auth-shell cms-auth-shell--<?php echo htmlspecialchars($layoutVariant, ENT_QUOTES, 'UTF-8'); ?>" aria-labelledby="cms-auth-title">
        <?php if ($layoutVariant === 'split'): ?>
            <aside class="cms-auth-panel" aria-hidden="true">
                <div class="cms-auth-brand">
                    <div class="cms-auth-logo">
                        <?php if ($logoUrl !== ''): ?>
                            <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                        <?php else: ?>
                            <span class="cms-auth-logo-fallback"><?php echo htmlspecialchars($brandInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <p><?php echo htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="cms-auth-panel-headline"><?php echo htmlspecialchars($headline, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php if ($subheadline !== ''): ?>
                        <p><?php echo htmlspecialchars($subheadline, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <?php // Dekorativer Bereich (aria-hidden): keine Links oder Überschriften – sie stehen in der Karte. ?>
                    <?php if ($footerNote !== ''): ?>
                        <div class="cms-auth-panel-links">
                            <span><?php echo htmlspecialchars($footerNote, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </aside>
        <?php endif; ?>
        <section class="cms-auth-card">
            <?php if ($layoutVariant !== 'split'): ?>
                <div class="cms-auth-brand">
                    <div class="cms-auth-logo" aria-hidden="true">
                        <?php if ($logoUrl !== ''): ?>
                            <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                        <?php else: ?>
                            <span class="cms-auth-logo-fallback"><?php echo htmlspecialchars($brandInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <p><?php echo htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8'); ?></p>
                    <h1 id="cms-auth-title"><?php echo htmlspecialchars($headline, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <?php if ($subheadline !== ''): ?>
                        <p><?php echo htmlspecialchars($subheadline, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <h1 id="cms-auth-title" class="cms-auth-card-title"><?php echo htmlspecialchars($headline, ENT_QUOTES, 'UTF-8'); ?></h1>
                <?php if ($subheadline !== ''): ?>
                    <p class="cms-auth-card-subtitle"><?php echo htmlspecialchars($subheadline, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($flashError !== ''): ?>
                <div class="cms-auth-alert cms-auth-alert--error" role="alert"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($flashSuccess !== ''): ?>
                <div class="cms-auth-alert cms-auth-alert--success" role="status"><?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($pageType === 'login'): ?>
                <form class="cms-auth-form" method="POST" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($loginRedirect !== ''): ?>
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($loginRedirect, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>

                    <div class="cms-auth-field">
                        <label for="cms-login-username"><?php echo htmlspecialchars($loginIdentifierLabel, ENT_QUOTES, 'UTF-8'); ?></label>
                        <input class="cms-auth-input" type="text" id="cms-login-username" name="username" autocomplete="username" required autofocus placeholder="<?php echo htmlspecialchars($loginIdentifierPlaceholder, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($loginValue, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="cms-auth-field">
                        <div class="cms-auth-label-row">
                            <label for="cms-login-password">
                            <span><?php echo htmlspecialchars($loginPasswordLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                            <a class="cms-auth-link-inline" href="<?php echo htmlspecialchars($forgotUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($loginForgotLinkText, ENT_QUOTES, 'UTF-8'); ?></a>
                        </div>
                        <input class="cms-auth-input" type="password" id="cms-login-password" name="password" autocomplete="current-password" required placeholder="<?php echo htmlspecialchars($loginPasswordPlaceholder, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <?php if ($showRemember): ?>
                        <label class="cms-auth-checkbox">
                            <input type="checkbox" name="remember" value="1" <?php echo $rememberChecked ? 'checked' : ''; ?>>
                            <span><?php echo htmlspecialchars($loginRememberLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                    <?php endif; ?>

                    <button class="cms-auth-button" type="submit"><?php echo htmlspecialchars($loginButtonText, ENT_QUOTES, 'UTF-8'); ?></button>
                </form>

                <?php if ($passkeyAvailable): ?>
                    <div class="cms-auth-divider">oder</div>
                    <form id="cms-passkey-form" method="POST" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="passkey_login">
                        <?php if ($loginRedirect !== ''): ?>
                            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($loginRedirect, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php endif; ?>
                        <input type="hidden" name="client_data_json" value="">
                        <input type="hidden" name="authenticator_data" value="">
                        <input type="hidden" name="signature" value="">
                        <input type="hidden" name="credential_id" value="">
                        <button id="cms-passkey-button" class="cms-auth-secondary-button" type="button" aria-describedby="cms-passkey-message"><?php echo htmlspecialchars($loginPasskeyButtonText, ENT_QUOTES, 'UTF-8'); ?></button>
                        <div id="cms-passkey-message" class="cms-auth-passkey-message" role="alert" data-error-text="<?php echo htmlspecialchars($passkeyErrorText, ENT_QUOTES, 'UTF-8'); ?>"></div>
                    </form>
                    <script <?= \CMS\Security::instance()->nonceAttr() ?>>
                        (function () {
                            const button = document.getElementById('cms-passkey-button');
                            const form = document.getElementById('cms-passkey-form');
                            const messageBox = document.getElementById('cms-passkey-message');
                            const showError = function () {
                                if (!messageBox) {
                                    return;
                                }
                                messageBox.className = 'cms-auth-passkey-message cms-auth-alert cms-auth-alert--error';
                                messageBox.textContent = messageBox.dataset.errorText || 'Passkey-Anmeldung fehlgeschlagen.';
                            };
                            const clearError = function () {
                                if (messageBox) {
                                    messageBox.className = 'cms-auth-passkey-message';
                                    messageBox.textContent = '';
                                }
                            };
                            if (!button || !form || !window.PublicKeyCredential || !navigator.credentials) {
                                return;
                            }

                            const optionsJson = <?php echo $passkeyOptionsJson !== '' ? $passkeyOptionsJson : '{}'; ?>;
                            const toBase64Url = function (buffer) {
                                const bytes = new Uint8Array(buffer);
                                let binary = '';
                                bytes.forEach(function (byte) { binary += String.fromCharCode(byte); });
                                return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
                            };
                            const fromBase64Url = function (value) {
                                const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
                                const padded = normalized + '==='.slice((normalized.length + 3) % 4);
                                const binary = atob(padded);
                                const bytes = new Uint8Array(binary.length);
                                for (let i = 0; i < binary.length; i += 1) {
                                    bytes[i] = binary.charCodeAt(i);
                                }
                                return bytes.buffer;
                            };

                            let submitting = false;
                            const submitForm = function () {
                                if (typeof form.requestSubmit === 'function') {
                                    form.requestSubmit();
                                    return;
                                }

                                form.submit();
                            };

                            button.addEventListener('click', async function () {
                                if (submitting) {
                                    return;
                                }

                                submitting = true;
                                button.disabled = true;
                                clearError();

                                try {
                                    const publicKey = Object.assign({}, optionsJson);
                                    if (typeof publicKey.challenge === 'string') {
                                        publicKey.challenge = fromBase64Url(publicKey.challenge);
                                    }
                                    if (publicKey.allowCredentials && Array.isArray(publicKey.allowCredentials)) {
                                        publicKey.allowCredentials = publicKey.allowCredentials.map(function (credential) {
                                            const clone = Object.assign({}, credential);
                                            if (typeof clone.id === 'string') {
                                                clone.id = fromBase64Url(clone.id);
                                            }
                                            return clone;
                                        });
                                    }
                                    if (publicKey.user && typeof publicKey.user.id === 'string') {
                                        publicKey.user.id = fromBase64Url(publicKey.user.id);
                                    }

                                    const credential = await navigator.credentials.get({ publicKey: publicKey });
                                    if (!credential || !credential.response) {
                                        throw new Error('Keine gültige Passkey-Antwort erhalten.');
                                    }

                                    form.querySelector('[name="client_data_json"]').value = toBase64Url(credential.response.clientDataJSON);
                                    form.querySelector('[name="authenticator_data"]').value = toBase64Url(credential.response.authenticatorData);
                                    form.querySelector('[name="signature"]').value = toBase64Url(credential.response.signature);
                                    form.querySelector('[name="credential_id"]').value = credential.id || '';
                                    submitForm();
                                } catch (error) {
                                    submitting = false;
                                    button.disabled = false;
                                    // Browsermeldungen (z. B. NotAllowedError) sind technisch und oft englisch: feste Meldung inline zeigen.
                                    showError();
                                    button.focus();
                                }
                            });
                        }());
                    </script>
                <?php endif; ?>
            <?php elseif ($pageType === 'register'): ?>
                <?php if (!$registerEnabled): ?>
                    <div class="cms-auth-alert cms-auth-alert--error" role="status"><?php echo htmlspecialchars($registerDisabledMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                    <form class="cms-auth-form" method="POST" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="cms-auth-grid cms-auth-grid--2">
                            <div class="cms-auth-field">
                                <label for="cms-register-email"><?php echo htmlspecialchars((string) ($settings['register_label_email'] ?? 'E-Mail-Adresse'), ENT_QUOTES, 'UTF-8'); ?></label>
                                <input class="cms-auth-input" type="email" id="cms-register-email" name="email" autocomplete="email" required placeholder="<?php echo htmlspecialchars($registerEmailPlaceholder, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($registerEmailValue, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="cms-auth-field">
                                <label for="cms-register-username"><?php echo htmlspecialchars((string) ($settings['register_label_username'] ?? 'Benutzername'), ENT_QUOTES, 'UTF-8'); ?></label>
                                <input class="cms-auth-input" type="text" id="cms-register-username" name="username" autocomplete="username" required placeholder="<?php echo htmlspecialchars($registerUsernamePlaceholder, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($registerUsernameValue, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="cms-auth-grid cms-auth-grid--2">
                            <div class="cms-auth-field">
                                <label for="cms-register-password"><?php echo htmlspecialchars((string) ($settings['register_label_password'] ?? 'Passwort'), ENT_QUOTES, 'UTF-8'); ?></label>
                                <input class="cms-auth-input" type="password" id="cms-register-password" name="password" autocomplete="new-password" required minlength="12">
                                <div class="cms-auth-help"><?php echo htmlspecialchars($passwordPolicyHint, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="cms-auth-field">
                                <label for="cms-register-password2"><?php echo htmlspecialchars((string) ($settings['register_label_password_confirm'] ?? 'Passwort bestätigen'), ENT_QUOTES, 'UTF-8'); ?></label>
                                <input class="cms-auth-input" type="password" id="cms-register-password2" name="password2" autocomplete="new-password" required minlength="12">
                            </div>
                        </div>

                        <?php if ($registerRequireTerms): ?>
                            <label class="cms-auth-checkbox">
                                <input type="checkbox" name="terms" value="1" <?php echo $termsChecked ? 'checked' : ''; ?> required>
                                <span>
                                    <?php echo htmlspecialchars($registerTermsLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($legalLinks['terms']['url'])): ?>
                                        <a class="cms-auth-link-inline" href="<?php echo htmlspecialchars((string) $legalLinks['terms']['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $legalLinks['terms']['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php endif; ?>
                                    <?php if (!empty($legalLinks['privacy']['url'])): ?>
                                        <?php echo !empty($legalLinks['terms']['url']) ? ' · ' : ''; ?>
                                        <a class="cms-auth-link-inline" href="<?php echo htmlspecialchars((string) $legalLinks['privacy']['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $legalLinks['privacy']['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endif; ?>

                        <button class="cms-auth-button" type="submit"><?php echo htmlspecialchars($registerButtonText, ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                <?php endif; ?>
            <?php elseif ($pageType === 'confirm-email'): ?>
                <?php // Eingelöst wird erst mit dieser Schaltfläche (POST); uid und Token stehen im Formular, nicht in der Zieladresse. ?>
                <form class="cms-auth-form" method="POST" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="uid" value="<?php echo htmlspecialchars((string) ($confirmEmail['uid'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars((string) ($confirmEmail['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <button class="cms-auth-button" type="submit"><?php echo htmlspecialchars((string) ($confirmEmail['button'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></button>
                    <div class="cms-auth-help"><?php echo htmlspecialchars((string) ($confirmEmail['hint'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                </form>
            <?php else: ?>
                <?php if ($forgotStep === 'done'): ?>
                    <div class="cms-auth-alert cms-auth-alert--success" role="status">
                        <?php echo htmlspecialchars($flashSuccess !== '' ? $flashSuccess : 'Dein Passwort wurde erfolgreich geändert.', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <p class="cms-auth-footer"><a href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($forgotDoneButtonText, ENT_QUOTES, 'UTF-8'); ?></a></p>
                <?php elseif ($forgotStep === 'reset' && $resetToken !== ''): ?>
                    <form class="cms-auth-form" method="POST" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="forgot_password_action" value="reset_password">
                        <input type="hidden" name="reset_token" value="<?php echo htmlspecialchars($resetToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="cms-auth-field">
                            <label for="cms-reset-password">Neues Passwort</label>
                            <input class="cms-auth-input" type="password" id="cms-reset-password" name="new_password" autocomplete="new-password" required minlength="12">
                            <div class="cms-auth-help"><?php echo htmlspecialchars($passwordPolicyHint, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="cms-auth-field">
                            <label for="cms-reset-password2">Passwort wiederholen</label>
                            <input class="cms-auth-input" type="password" id="cms-reset-password2" name="new_password2" autocomplete="new-password" required minlength="12">
                        </div>

                        <button class="cms-auth-button" type="submit"><?php echo htmlspecialchars($forgotResetButtonText, ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                <?php else: ?>
                    <form class="cms-auth-form" method="POST" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="forgot_password_action" value="request_reset">

                        <div class="cms-auth-field">
                            <label for="cms-forgot-email"><?php echo htmlspecialchars($forgotLabelEmail, ENT_QUOTES, 'UTF-8'); ?></label>
                            <input class="cms-auth-input" type="email" id="cms-forgot-email" name="email" autocomplete="email" required placeholder="<?php echo htmlspecialchars($forgotEmailPlaceholder, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($forgotEmailValue, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <button class="cms-auth-button" type="submit"><?php echo htmlspecialchars($forgotRequestButtonText, ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <div class="cms-auth-footer">
                <?php if ($pageType !== 'login'): ?>
                    <p><a href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($footerLoginText, ENT_QUOTES, 'UTF-8'); ?></a></p>
                <?php endif; ?>
                <?php if ($pageType !== 'register' && $registerEnabled): ?>
                    <p><a href="<?php echo htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($footerRegisterText, ENT_QUOTES, 'UTF-8'); ?></a></p>
                <?php endif; ?>
                <?php if ($pageType !== 'forgot-password'): ?>
                    <p><a href="<?php echo htmlspecialchars($forgotUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($footerForgotText, ENT_QUOTES, 'UTF-8'); ?></a></p>
                <?php endif; ?>
                <p><a href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($footerHomeText, ENT_QUOTES, 'UTF-8'); ?></a></p>
                <?php if (!empty($legalLinks['imprint']['url']) || !empty($legalLinks['privacy']['url']) || !empty($legalLinks['terms']['url'])): ?>
                    <p>
                        <?php if (!empty($legalLinks['imprint']['url'])): ?>
                            <a href="<?php echo htmlspecialchars((string) $legalLinks['imprint']['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $legalLinks['imprint']['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endif; ?>
                        <?php if (!empty($legalLinks['privacy']['url'])): ?>
                            <?php echo !empty($legalLinks['imprint']['url']) ? ' · ' : ''; ?>
                            <a href="<?php echo htmlspecialchars((string) $legalLinks['privacy']['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $legalLinks['privacy']['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endif; ?>
                        <?php if (!empty($legalLinks['terms']['url'])): ?>
                            <?php echo (!empty($legalLinks['imprint']['url']) || !empty($legalLinks['privacy']['url'])) ? ' · ' : ''; ?>
                            <a href="<?php echo htmlspecialchars((string) $legalLinks['terms']['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $legalLinks['terms']['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ($footerNote !== ''): ?>
                    <div class="cms-auth-footer-note"><?php echo htmlspecialchars($footerNote, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <?php if ($showcase !== null): ?>
        <?php \CMS\Services\CmsAuthPageService::getInstance()->renderShowcase($showcase, (string) $pageType, 'cms'); ?>
    <?php endif; ?>
</body>
</html>
