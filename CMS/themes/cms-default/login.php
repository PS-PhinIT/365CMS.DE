<?php
/**
 * Login Template
 *
 * Kein header/footer-wrap nötig – wird durch ThemeManager::render() automatisch eingebunden.
 * POST /login wird vom CMS Router (Router::handleLogin) verarbeitet.
 * Fehler/Erfolg kommen via $_SESSION['error'] / $_SESSION['success'] zurück.
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

// Flash-Messages analog zum funktionierenden 365Network-Theme beziehen
$themeGetFlashAvailable = function_exists('theme_get_flash');
$themeFlash = $themeGetFlashAvailable ? (theme_get_flash() ?? []) : [];
$loginError = $themeGetFlashAvailable
    ? (trim((string) ($themeFlash['type'] ?? '')) === 'error' ? trim((string) ($themeFlash['message'] ?? '')) : '')
    : trim((string) ($_SESSION['error'] ?? ''));
$loginSuccess = $themeGetFlashAvailable
    ? (trim((string) ($themeFlash['type'] ?? '')) === 'success' ? trim((string) ($themeFlash['message'] ?? '')) : '')
    : trim((string) ($_SESSION['success'] ?? ''));

if (!$themeGetFlashAvailable) {
    unset($_SESSION['error'], $_SESSION['success']);
}

// CSRF-Token für das Formular
$csrfToken = '';
if (class_exists('\CMS\Security')) {
    $csrfToken = \CMS\Security::instance()->generateToken('login');
}

$siteUrl   = SITE_URL;
$siteTitle = defined('SITE_NAME') ? SITE_NAME : '365CMS';
$loginAction = function_exists('meridian_auth_url') ? meridian_auth_url('login') : rtrim((string) $siteUrl, '/') . '/cms-login';
$forgotPasswordUrl = function_exists('meridian_auth_url') ? meridian_auth_url('forgot-password') : rtrim((string) $siteUrl, '/') . '/cms-password-forgot';
$registerUrl = function_exists('meridian_auth_url') ? meridian_auth_url('register') : rtrim((string) $siteUrl, '/') . '/cms-register';
$homeUrl = rtrim((string) $siteUrl, '/') . '/';
$loginRedirect = trim((string)($login_redirect ?? ''));
$loginValue = trim((string)($_POST['username'] ?? $_POST['email'] ?? ''));
?>

<div class="auth-main" style="background:linear-gradient(135deg,#e3f2fd 0%,#f5f9fc 100%);min-height:calc(100vh - 200px);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2rem;padding:2rem 1.5rem;">
    <div style="width:100%;max-width:440px;margin:0 auto;">

        <!-- Auth Card -->
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
                <h1><?php echo htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p>Melde dich mit deinem Konto an</p>
            </div>

            <!-- Flash Messages -->
            <?php if ($loginError && trim($loginError) !== '') : ?>
                <div class="alert alert-error" role="alert">
                    <?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($loginSuccess && trim($loginSuccess) !== '') : ?>
                <div class="alert alert-success" role="alert">
                    <?php echo htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <!-- Login Form – Verarbeitung durch CMS Router POST /login -->
            <form method="POST" action="<?php echo htmlspecialchars($loginAction, ENT_QUOTES, 'UTF-8'); ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($loginRedirect !== '') : ?>
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($loginRedirect, ENT_QUOTES, 'UTF-8'); ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label" for="username">Benutzername oder E-Mail</label>
                    <input class="form-control"
                           type="text"
                           id="username"
                           name="username"
                           autocomplete="username"
                           required
                           autofocus
                           value="<?php echo htmlspecialchars($loginValue, ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">
                        Passwort
                        <a href="<?php echo htmlspecialchars($forgotPasswordUrl, ENT_QUOTES, 'UTF-8'); ?>" class="form-label-link">Vergessen?</a>
                    </label>
                    <div class="form-control-wrap form-control-wrap--password">
                        <input class="form-control"
                               type="password"
                               id="password"
                               name="password"
                               autocomplete="current-password"
                               placeholder="Dein Passwort"
                               required>
                        <button type="button" class="btn-icon form-password-toggle" aria-label="Passwort anzeigen">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-group form-group--checkbox">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" value="1">
                        Angemeldet bleiben
                    </label>
                </div>

                <button type="submit" class="btn-solid btn-solid--full auth-submit">Anmelden</button>
            </form>

            <!-- Footer Links -->
            <div class="auth-footer">
                <p>Noch kein Konto?
                    <a href="<?php echo htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8'); ?>">Jetzt registrieren</a>
                </p>
                <p style="margin-top:0.5rem;">
                    <a href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>">← Zurück zur Startseite</a>
                </p>
            </div>

        </div>
    </div>

    <?php
    // Login-Showcase (LS-15): Daten aus CmsAuthPageService::getShowcase('login'), Markup aus dem Core-Partial
    // bzw. einem Theme-Override auth/showcase.php; Gestaltung in style.css (.auth-main .cms-auth-showcase).
    if (!empty($showcase) && is_array($showcase) && class_exists('\CMS\Services\CmsAuthPageService')) {
        \CMS\Services\CmsAuthPageService::getInstance()->renderShowcase($showcase, 'login', 'legacy');
    }
    ?>
</div>
