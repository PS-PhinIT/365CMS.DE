<?php
/**
 * Meridian CMS Default – Registrierung Template
 *
 * @package CMSv2\Themes\CmsDefault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Bereits eingeloggt → weiterleiten
if (function_exists('theme_is_logged_in') && theme_is_logged_in()) {
    $loggedInRedirect = function_exists('theme_logged_in_redirect_path')
        ? theme_logged_in_redirect_path()
        : '/member';
    header('Location: ' . $loggedInRedirect);
    exit;
}

// Flash-Messages vom CMS Router lesen (POST /register wird vom Router verarbeitet)
$regError   = $_SESSION['error']   ?? '';
$regSuccess = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

// CSRF-Token für das Formular
$csrfToken = '';
if (class_exists('\CMS\Security')) {
    $csrfToken = \CMS\Security::instance()->generateToken('register');
}

$registerAction = function_exists('meridian_auth_url') ? meridian_auth_url('register') : rtrim((string) SITE_URL, '/') . '/register';
$loginUrl = function_exists('meridian_auth_url') ? meridian_auth_url('login') : rtrim((string) SITE_URL, '/') . '/login';
$homeUrl = rtrim((string) SITE_URL, '/') . '/';
$passwordPolicyHint = 'Mindestens 12 Zeichen sowie Groß-/Kleinbuchstabe, Zahl und Sonderzeichen.';
?>

<div class="auth-main" style="background:linear-gradient(135deg,#e3f2fd 0%,#f5f9fc 100%);min-height:calc(100vh - 200px);display:flex;align-items:center;padding:2rem 1.5rem;">
    <div style="width:100%;max-width:480px;margin:0 auto;">

        <div class="auth-card">

            <!-- Logo -->
            <div class="auth-logo">
                <svg class="network-icon" style="width:56px;height:56px;color:var(--accent);margin:0 auto 0.75rem;display:block;"
                     viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="30" cy="30" r="6" fill="currentColor"/>
                    <circle cx="15" cy="15" r="5" fill="currentColor"/>
                    <circle cx="45" cy="15" r="5" fill="currentColor"/>
                    <circle cx="15" cy="45" r="5" fill="currentColor"/>
                    <circle cx="45" cy="45" r="5" fill="currentColor"/>
                    <line x1="30" y1="30" x2="15" y2="15" stroke="currentColor" stroke-width="2"/>
                    <line x1="30" y1="30" x2="45" y2="15" stroke="currentColor" stroke-width="2"/>
                    <line x1="30" y1="30" x2="15" y2="45" stroke="currentColor" stroke-width="2"/>
                    <line x1="30" y1="30" x2="45" y2="45" stroke="currentColor" stroke-width="2"/>
                </svg>
                <h1><?php echo htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : '365CMS', ENT_QUOTES, 'UTF-8'); ?></h1>
                <p>Erstelle dein kostenloses Konto</p>
            </div>

            <!-- Flash Messages -->
            <?php if ($regError): ?>
            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($regError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <!-- Register Form – wird vom CMS Router (POST /register) verarbeitet -->
            <form class="auth-form" method="POST" action="<?php echo htmlspecialchars($registerAction, ENT_QUOTES, 'UTF-8'); ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                    <label class="form-label" for="regEmail">E-Mail-Adresse</label>
                    <input type="email" id="regEmail" name="email" class="form-control"
                           value="<?php echo htmlspecialchars((string) (filter_var($_GET['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: ''), ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="email" required autofocus
                           placeholder="deine@email.de">
                </div>

                <div class="form-group">
                    <label class="form-label" for="regUsername">Benutzername</label>
                    <input type="text" id="regUsername" name="username" class="form-control"
                           value=""
                           autocomplete="username" required minlength="3" maxlength="40"
                           pattern="[a-zA-Z0-9_\-.]+"
                           placeholder="mein_name">
                    <small class="form-text">Nur Buchstaben, Ziffern, Bindestrich, Punkt und Unterstrich</small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="regPassword">Passwort</label>
                    <div class="form-control-wrap form-control-wrap--password">
                        <input type="password" id="regPassword" name="password" class="form-control"
                               autocomplete="new-password" required minlength="12"
                               placeholder="Mindestens 12 Zeichen">
                        <button type="button" class="btn-icon form-password-toggle" aria-label="Passwort anzeigen">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <small class="form-text"><?php echo htmlspecialchars($passwordPolicyHint, ENT_QUOTES, 'UTF-8'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="regPassword2">Passwort bestätigen</label>
                    <input type="password" id="regPassword2" name="password2" class="form-control"
                           autocomplete="new-password" required minlength="12"
                           placeholder="Passwort wiederholen">
                </div>

                <div class="form-group form-group--checkbox">
                    <label class="checkbox-label">
                        <input type="checkbox" name="terms" value="1" required>
                        Ich akzeptiere die
                        <a href="<?php echo SITE_URL; ?>/agb" target="_blank">Nutzungsbedingungen</a>
                        und die
                        <a href="<?php echo SITE_URL; ?>/datenschutz" target="_blank">Datenschutzerklärung</a>.
                        <span style="color:#ef4444;">*</span>
                    </label>
                </div>

                <button type="submit" class="btn-solid btn-solid--full auth-submit">Kostenlos registrieren</button>
            </form>

            <!-- Footer Links -->
            <div class="auth-footer">
                <p>Bereits registriert?
                    <a href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>">Jetzt anmelden</a>
                </p>
                <p style="margin-top:0.5rem;">
                    <a href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>">← Zurück zur Startseite</a>
                </p>
            </div>

        </div><!-- /.auth-card -->
    </div>
</div>
