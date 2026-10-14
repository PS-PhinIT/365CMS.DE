<?php
/**
 * Public Router Module
 *
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS\Routing;

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Database;
use CMS\Logger;
use CMS\Router;
use CMS\Security;
use CMS\Services;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class PublicRouter
{
    public function __construct(private readonly Router $router)
    {
    }

    public function registerRoutes(): void
    {
        $this->router->addRoute('GET', '/media-proxy.php', [$this, 'redirectLegacyMediaProxy']);
        $this->router->addRoute('POST', '/media-proxy.php', [$this, 'handleLegacyMediaProxyUpload']);
        $this->router->addRoute('GET', '/media-file', [$this, 'handleMediaDelivery']);

        $this->router->addRoute('GET', '/cms-login', [$this, 'renderLogin']);
        $this->router->addRoute('POST', '/cms-login', [$this, 'handleLogin']);
        $this->router->addRoute('GET', '/cms-register', [$this, 'renderRegister']);
        $this->router->addRoute('POST', '/cms-register', [$this, 'handleRegister']);
        $this->router->addRoute('GET', '/cms-password-forgot', [$this, 'renderForgotPassword']);
        $this->router->addRoute('POST', '/cms-password-forgot', [$this, 'handleForgotPassword']);

        $this->router->addRoute('GET', '/login', [$this, 'redirectLegacyLogin']);
        $this->router->addRoute('POST', '/login', [$this, 'handleLogin']);
        $this->router->addRoute('GET', '/register', [$this, 'redirectLegacyRegister']);
        $this->router->addRoute('POST', '/register', [$this, 'handleRegister']);
        $this->router->addRoute('GET', '/forgot-password', [$this, 'redirectLegacyForgotPassword']);
        $this->router->addRoute('POST', '/forgot-password', [$this, 'handleForgotPassword']);
        $this->router->addRoute('GET', '/logout', [$this, 'handleLogout']);
        $this->router->addRoute('POST', '/logout', [$this, 'handleLogout']);

        $this->router->addRoute('GET', '/mfa-challenge', [$this, 'renderMfaChallenge']);
        $this->router->addRoute('POST', '/mfa-challenge', [$this, 'handleMfaChallenge']);
        $this->router->addRoute('GET', '/mfa-setup', [$this, 'renderMfaSetup']);
        $this->router->addRoute('POST', '/mfa-setup', [$this, 'handleMfaSetup']);
        $this->router->addRoute('POST', '/mfa-disable', [$this, 'handleMfaDisable']);

        $this->router->addRoute('GET', '/order', [$this, 'renderOrder']);
        $this->router->addRoute('POST', '/order', [$this, 'handleOrder']);
        $this->router->addRoute('POST', '/comments/post', [$this, 'handleCommentPost']);
        $this->router->addRoute('GET', '/cookie-einstellungen', [$this, 'renderCookiePreferencesPage']);
        $this->router->addRoute('GET', '/health', [$this, 'renderHealth']);
    }

    /**
     * Minimaler Health-Endpunkt für Monitoring (Diagnose → Health-Check).
     * Nur aktiv, wenn `monitor_health_endpoint_enabled` = 1; gibt keine Systemdetails preis.
     */
    public function renderHealth(): void
    {
        $enabled = Services\OptionStore::getInstance()->get('monitor_health_endpoint_enabled', '0') === '1';
        $dbOk = false;
        try {
            // Echte DB-Probe – der Options-Cache allein sagt nichts über die Verbindung aus.
            $dbOk = (string) Database::instance()->get_var('SELECT 1') === '1';
        } catch (\Throwable) {
        }

        if (!$enabled && $dbOk) {
            $this->router->render404();
            return;
        }

        if (!headers_sent()) {
            http_response_code($dbOk ? 200 : 503);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex');
        }

        echo json_encode(['status' => $dbOk ? 'ok' : 'error', 'database' => $dbOk ? 'ok' : 'error']);
        exit;
    }

    public function redirectLegacyMediaProxy(): void
    {
        Logger::instance()->warning('Legacy media-proxy route used; redirecting to member media.', [
            'method' => 'GET',
            'path' => '/media-proxy.php',
            'remote_addr' => Security::getClientIp(),
        ]);

        $this->router->redirect('/member/media', 302);
    }

    public function handleLegacyMediaProxyUpload(): void
    {
        Logger::instance()->warning('Legacy media-proxy upload route used; delegating to FileUploadService.', [
            'method' => 'POST',
            'path' => '/media-proxy.php',
            'remote_addr' => Security::getClientIp(),
        ]);

        $result = Services\FileUploadService::getInstance()->handleUploadRequest();
        http_response_code((int)($result['status'] ?? 200));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result['data'] ?? ['error' => 'Unbekannter Fehler'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function handleMediaDelivery(): void
    {
        Services\MediaDeliveryService::getInstance()->handleRequest();
    }

    public function renderLogin(): void
    {
        $safeRedirectParts = $this->resolveAllowedRedirectParts($_GET['redirect'] ?? null);
        $safeRedirectTarget = $this->buildAllowedRedirectTarget($safeRedirectParts);

        if (Auth::instance()->isLoggedIn()) {
            $this->redirectToSafeTarget($safeRedirectParts);
            return;
        }

        $passkeyPayload = [
            'available' => false,
            'options_json' => '{}',
        ];

        try {
            $authManager = \CMS\Auth\AuthManager::instance();
            if ($authManager->isPasskeyAvailable()) {
                $options = $authManager->getPasskeyLoginOptions();
                $_SESSION['login_passkey_challenge'] = (string)($options['challenge'] ?? '');
                $passkeyPayload = [
                    'available' => true,
                    'options_json' => json_encode(
                        $options['options'] ?? new \stdClass(),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                    ) ?: '{}',
                ];
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('public-router')->warning('Passkey login payload could not be prepared.', [
                'exception' => $e,
            ]);
        }

        Services\CmsAuthPageService::getInstance()->render('login', [
            'login_redirect' => $safeRedirectTarget,
            'passkey_payload' => $passkeyPayload,
        ]);
    }

    public function handleLogin(): void
    {
        $security = Security::instance();
        $redirectTarget = $this->normalizeAllowedRedirectTarget($_POST['redirect'] ?? $_GET['redirect'] ?? null);
        $loginInputForRedirect = $this->requestString($_POST['username'] ?? $_POST['email'] ?? '', 254);
        $defaultPendingRedirect = $this->resolvePendingLoginRedirect($redirectTarget, $loginInputForRedirect);
        $loginPagePath = $this->buildLoginPagePath($redirectTarget);

        if (!$security->verifyToken($this->requestString($_POST['csrf_token'] ?? '', 128), 'login')) {
            $_SESSION['error'] = 'Sicherheitsüberprüfung fehlgeschlagen.';
            $this->router->redirect($this->appendLoginErrorCode($loginPagePath, 'security'));
            return;
        }

        $action = $this->requestString($_POST['action'] ?? 'password_login', 40);

        if ($action === 'passkey_login') {
            $challenge = (string)($_SESSION['login_passkey_challenge'] ?? '');
            unset($_SESSION['login_passkey_challenge']);

            if ($challenge === '') {
                $_SESSION['error'] = 'Die Passkey-Challenge ist abgelaufen. Bitte erneut versuchen.';
                $this->router->redirect($this->appendLoginErrorCode($loginPagePath, 'passkey'));
                return;
            }

            $clientDataJson = $this->base64UrlDecode($this->requestString($_POST['client_data_json'] ?? '', 8192));
            $authenticatorData = $this->base64UrlDecode($this->requestString($_POST['authenticator_data'] ?? '', 8192));
            $signature = $this->base64UrlDecode($this->requestString($_POST['signature'] ?? '', 8192));
            $credentialId = $this->requestString($_POST['credential_id'] ?? '', 2048);

            if ($clientDataJson === '' || $authenticatorData === '' || $signature === '' || $credentialId === '') {
                $_SESSION['error'] = 'Die Passkey-Antwort war unvollständig. Bitte erneut versuchen.';
                $this->router->redirect($this->appendLoginErrorCode($loginPagePath, 'passkey'));
                return;
            }

            $result = \CMS\Auth\AuthManager::instance()->authenticateViaPasskey(
                $clientDataJson,
                $authenticatorData,
                $signature,
                $credentialId,
                $challenge
            );

            if ($result === true) {
                unset($_SESSION['auth_redirect_after_login']);
                $this->redirectToSafeTarget($this->resolveAllowedRedirectParts($this->resolveSuccessfulLoginRedirect($redirectTarget)));
                return;
            }

            $_SESSION['error'] = (string)$result;
            $this->setAuthFormOldValues('login', [
                'username' => $this->requestString($_POST['username'] ?? '', 254),
                'email' => $this->requestString($_POST['email'] ?? '', 254),
            ]);
            $this->router->redirect($this->appendLoginErrorCode($loginPagePath, 'passkey'));
            return;
        }

        $loginInput = $this->requestString($_POST['username'] ?? $_POST['email'] ?? '', 254);
        $password = $this->requestString($_POST['password'] ?? '', 4096, false);
        $result = Auth::instance()->login($loginInput, $password, !empty($_POST['remember']));

        if ($result === true) {
            unset($_SESSION['auth_redirect_after_login']);
            $this->redirectToSafeTarget($this->resolveAllowedRedirectParts($this->resolveSuccessfulLoginRedirect($redirectTarget)));
        } elseif ($result === 'MFA_REQUIRED') {
            $_SESSION['auth_redirect_after_login'] = $defaultPendingRedirect;
            $this->router->redirect($this->getLocalizedPublicPath('/mfa-challenge'));
        } else {
            $_SESSION['error'] = $result;
            $this->setAuthFormOldValues('login', [
                'username' => (string)$loginInput,
                'remember' => !empty($_POST['remember']) ? '1' : '0',
            ]);
            $this->router->redirect($this->appendLoginErrorCode($loginPagePath, 'invalid'));
        }
    }

    private function renderMfaHeadAssets(): string
    {
        $css = function_exists('cms_asset_url') ? \cms_asset_url('css/cms-mfa.css') : '/assets/css/cms-mfa.css';
        $html = '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '">';

        if (class_exists(\CMS\ThemeManager::class)) {
            ob_start();
            try {
                \CMS\ThemeManager::instance()->renderSiteFavicon();
            } catch (\Throwable) {
            }
            $html .= (string) ob_get_clean();
        }

        return $html;
    }

    public function renderMfaChallenge(): void
    {
        if (empty($_SESSION['mfa_pending_user_id'])) {
            $this->router->redirect($this->getPublicAuthPath('login'));
            return;
        }

        $security = Security::instance();
        $csrfToken = $security->generateToken('mfa_challenge');
        $mfaChallengePath = $this->getLocalizedPublicPath('/mfa-challenge');
        $loginPath = $this->getPublicAuthPath('login');
        $documentLang = htmlspecialchars($this->getCurrentDocumentLang(), ENT_QUOTES, 'UTF-8');
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['error']);

        echo '<!DOCTYPE html><html lang="' . $documentLang . '"><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Zwei-Faktor-Authentifizierung – ' . htmlspecialchars(SITE_NAME) . '</title>'
            . $this->renderMfaHeadAssets()
            . '</head><body class="cms-mfa-body">'
            . '<div class="cms-mfa-card">'
            . '<h2 class="cms-mfa-title">🔐 Zwei-Faktor-Authentifizierung</h2>'
            . '<p class="cms-mfa-lead">Gib den 6-stelligen Code aus deiner Authenticator-App ein.</p>';

        if ($error) {
            echo '<div class="cms-mfa-alert cms-mfa-alert--error" role="alert">❌ '
                . htmlspecialchars((string)$error) . '</div>';
        }

        echo '<form method="POST" action="' . htmlspecialchars($mfaChallengePath, ENT_QUOTES, 'UTF-8') . '" autocomplete="off">'
            . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">'
            . '<div class="cms-mfa-field">'
            . '<label class="cms-mfa-label" for="cms-mfa-code">Authenticator-Code</label>'
            . '<input type="text" id="cms-mfa-code" name="totp_code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus autocomplete="one-time-code" '
            . 'class="cms-mfa-input" placeholder="000000">'
            . '</div>'
            . '<button type="submit" class="cms-mfa-submit">✅ Bestätigen</button>'
            . '</form>'
            . '<p class="cms-mfa-back"><a href="' . htmlspecialchars($loginPath, ENT_QUOTES, 'UTF-8') . '">← Zurück zum Login</a></p>'
            . '</div></body></html>';
    }

    public function handleMfaChallenge(): void
    {
        $security = Security::instance();

        if (!$security->verifyToken($_POST['csrf_token'] ?? '', 'mfa_challenge')) {
            $_SESSION['error'] = 'Sicherheitsüberprüfung fehlgeschlagen.';
            $this->router->redirect($this->getLocalizedPublicPath('/mfa-challenge'));
            return;
        }

        $pendingUserId = (int)($_SESSION['mfa_pending_user_id'] ?? 0);
        if ($pendingUserId === 0) {
            $this->router->redirect($this->getPublicAuthPath('login'));
            return;
        }

        $code = $this->requestString($_POST['totp_code'] ?? '', 12);
        $mfaResult = \CMS\Auth\AuthManager::instance()->verifyMfa($code);
        if ($mfaResult !== true) {
            $_SESSION['error'] = is_string($mfaResult) && $mfaResult !== ''
                ? $mfaResult
                : 'Ungültiger oder abgelaufener Code. Bitte erneut versuchen.';
            $this->router->redirect($this->getLocalizedPublicPath('/mfa-challenge'));
            return;
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'login_mfa_success',
            'Login mit MFA erfolgreich.',
            'user',
            $pendingUserId,
            ['ip' => $security->getClientIp()],
            'info'
        );

        $this->redirectToSafeTarget($this->resolveAllowedRedirectParts($this->consumePostLoginRedirect()));
    }

    public function renderMfaSetup(): void
    {
        if (!Auth::instance()->isLoggedIn()) {
            $this->router->redirect($this->getPublicAuthPath('login'));
            return;
        }

        $userId = (int)$_SESSION['user_id'];
        if (Auth::instance()->isMfaEnabled($userId)) {
            $_SESSION['success'] = '2FA ist bereits aktiv.';
            $this->router->redirect('/member/security');
            return;
        }

        $auth = Auth::instance();
        $security = Security::instance();
        $setup = $auth->setupMfaSecret($userId);
        $csrfToken = $security->generateToken('mfa_setup');
        $mfaSetupPath = $this->getLocalizedPublicPath('/mfa-setup');
        $documentLang = htmlspecialchars($this->getCurrentDocumentLang(), ENT_QUOTES, 'UTF-8');
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);

        echo '<!DOCTYPE html><html lang="' . $documentLang . '"><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>2FA einrichten – ' . htmlspecialchars(SITE_NAME) . '</title>'
            . $this->renderMfaHeadAssets()
            . '</head><body class="cms-mfa-body">'
            . '<div class="cms-mfa-card cms-mfa-card--wide">'
            . '<h2 class="cms-mfa-title">🔐 Zwei-Faktor-Authentifizierung einrichten</h2>'
            . '<p class="cms-mfa-lead">Scanne den QR-Code mit deiner Authenticator-App (z. B. Google Authenticator, Authy) und gib anschließend den 6-stelligen Code ein.</p>';

        if ($error) {
            echo '<div class="cms-mfa-alert cms-mfa-alert--error" role="alert">❌ '
                . htmlspecialchars((string)$error) . '</div>';
        }

        if ($success) {
            echo '<div class="cms-mfa-alert cms-mfa-alert--success" role="status">✅ '
                . htmlspecialchars((string)$success) . '</div>';
        }

        $qrDataUri = (string) ($setup['qr_data_uri'] ?? '');
        if (str_starts_with($qrDataUri, 'data:image/')) {
            echo '<div class="cms-mfa-qr">'
                . '<img src="' . htmlspecialchars($qrDataUri, ENT_QUOTES, 'UTF-8') . '" alt="QR-Code für Authenticator" width="200" height="200">'
                . '</div>';
        }

        echo '<div class="cms-mfa-secret">'
            . htmlspecialchars((string)$setup['secret'])
            . '</div>'
            . '<p class="cms-mfa-hint">Falls du den QR-Code nicht scannen kannst, gib den obigen Schlüssel manuell in deiner App ein.</p>'
            . '<form method="POST" action="' . htmlspecialchars($mfaSetupPath, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">'
            . '<div class="cms-mfa-field">'
            . '<label class="cms-mfa-label" for="cms-mfa-setup-code">Bestätigungscode aus der App</label>'
            . '<input type="text" id="cms-mfa-setup-code" name="totp_code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus autocomplete="one-time-code" '
            . 'class="cms-mfa-input" placeholder="000000">'
            . '</div>'
            . '<button type="submit" class="cms-mfa-submit">✅ 2FA aktivieren</button>'
            . '</form>'
            . '<p class="cms-mfa-back"><a href="/member/security">← Zurück zur Sicherheitsseite</a></p>'
            . '</div></body></html>';
    }

    public function handleMfaSetup(): void
    {
        if (!Auth::instance()->isLoggedIn()) {
            $this->router->redirect($this->getPublicAuthPath('login'));
            return;
        }

        $security = Security::instance();
        if (!$security->verifyToken($_POST['csrf_token'] ?? '', 'mfa_setup')) {
            $_SESSION['error'] = 'Sicherheitsüberprüfung fehlgeschlagen.';
            $this->router->redirect($this->getLocalizedPublicPath('/mfa-setup'));
            return;
        }

        $userId = (int)$_SESSION['user_id'];
        $code = $this->requestString($_POST['totp_code'] ?? '', 12);

        if (!Auth::instance()->confirmMfaSetup($userId, $code)) {
            $_SESSION['error'] = 'Ungültiger Code. Bitte erneut scannen und Code eingeben.';
            $this->router->redirect($this->getLocalizedPublicPath('/mfa-setup'));
            return;
        }

        $_SESSION['success'] = '2FA wurde erfolgreich aktiviert.';
        $this->router->redirect('/member/security');
    }

    public function handleMfaDisable(): void
    {
        if (!Auth::instance()->isLoggedIn()) {
            $this->router->redirect($this->getPublicAuthPath('login'));
            return;
        }

        $security = Security::instance();
        if (!$security->verifyToken($_POST['csrf_token'] ?? '', 'mfa_disable')) {
            $_SESSION['error'] = 'Sicherheitsüberprüfung fehlgeschlagen.';
            $this->router->redirect('/member/security');
            return;
        }

        $reauth = Auth::instance()->verifyCurrentPassword((int)$_SESSION['user_id'], (string)($_POST['current_password'] ?? ''));
        if ($reauth !== true) {
            $_SESSION['error'] = (string)$reauth;
            $this->router->redirect('/member/security');
            return;
        }

        Auth::instance()->disableMfa((int)$_SESSION['user_id']);
        $_SESSION['success'] = '2FA wurde deaktiviert.';
        $this->router->redirect('/member/security');
    }

    public function renderRegister(): void
    {
        if (Auth::instance()->isLoggedIn()) {
            $this->router->redirect('/member');
            return;
        }

        Services\CmsAuthPageService::getInstance()->render('register');
    }

    public function renderForgotPassword(): void
    {
        if (Auth::instance()->isLoggedIn()) {
            $this->router->redirect('/member');
            return;
        }

        Services\CmsAuthPageService::getInstance()->render('forgot-password');
    }

    public function handleForgotPassword(): void
    {
        if (Auth::instance()->isLoggedIn()) {
            $this->router->redirect('/member', 303);
            return;
        }

        $security = Security::instance();
        if (!$security->verifyToken($this->requestString($_POST['csrf_token'] ?? '', 128), 'forgot_password')) {
            $_SESSION['error'] = 'Sicherheitsüberprüfung fehlgeschlagen.';
            $this->router->redirect($this->getPublicAuthPath('forgot-password'), 303);
            return;
        }

        $action = $this->requestString($_POST['forgot_password_action'] ?? 'request_reset', 40);
        $authPageService = Services\CmsAuthPageService::getInstance();

        if ($action === 'reset_password') {
            $token = $this->requestString($_POST['reset_token'] ?? '', 200);
            $result = $authPageService->resetPassword(
                $token,
                $this->requestString($_POST['new_password'] ?? '', 4096, false),
                $this->requestString($_POST['new_password2'] ?? '', 4096, false)
            );

            if ($result['success'] ?? false) {
                $_SESSION['success'] = (string)($result['message'] ?? 'Dein Passwort wurde erfolgreich geändert.');
                $this->router->redirect($this->getPublicAuthPath('forgot-password') . '?step=done', 303);
                return;
            }

            $_SESSION['error'] = (string)($result['message'] ?? 'Das Passwort konnte nicht geändert werden.');
            $this->router->redirect($this->getPublicAuthPath('forgot-password') . '?step=reset&token=' . urlencode($token), 303);
            return;
        }

        $email = $this->requestString($_POST['email'] ?? '', 254);
        $result = $authPageService->requestPasswordReset($email, $this->router->getRequestLocale());
        if ($result['success'] ?? false) {
            $_SESSION['success'] = (string)($result['message'] ?? 'Falls das Konto existiert, wurde ein Reset-Link versendet.');
        } else {
            $_SESSION['error'] = (string)($result['message'] ?? 'Die Anfrage konnte nicht verarbeitet werden.');
            $this->setAuthFormOldValues('forgot-password', ['email' => $email]);
        }

        $this->router->redirect($this->getPublicAuthPath('forgot-password'), 303);
    }

    public function handleRegister(): void
    {
        $security = Security::instance();
        $authPageService = Services\CmsAuthPageService::getInstance();

        if (!$security->verifyToken($this->requestString($_POST['csrf_token'] ?? '', 128), 'register')) {
            $_SESSION['error'] = 'Sicherheitsüberprüfung fehlgeschlagen.';
            $this->router->redirect($this->getPublicAuthPath('register'));
            return;
        }

        if (!$authPageService->isRegistrationEnabled()) {
            $_SESSION['error'] = (string) ($authPageService->getSettings()['register_disabled_message'] ?? 'Die Registrierung ist aktuell deaktiviert.');
            $this->router->redirect($this->getPublicAuthPath('register'));
            return;
        }

        $settings = $authPageService->getSettings();

        // Massenregistrierung bremsen: max. 5 Versuche je IP in 15 Minuten.
        $clientIp = Security::getClientIp();
        if (!Security::checkDbRateLimit($clientIp, 'register', 5, 900)) {
            $_SESSION['error'] = 'Zu viele Registrierungsversuche. Bitte versuche es später erneut.';
            $this->router->redirect($this->getPublicAuthPath('register'));
            return;
        }
        Security::recordDbRateLimitAttempt($clientIp, 'register');

        $registerPassword = $this->requestString($_POST['password'] ?? '', 4096, false);
        $registerPassword2 = $this->requestString($_POST['password2'] ?? '', 4096, false);

        if (array_key_exists('password2', $_POST) && $registerPassword !== $registerPassword2) {
            $_SESSION['error'] = 'Die Passwörter stimmen nicht überein.';
            $this->setAuthFormOldValues('register', [
                'email' => $this->requestString($_POST['email'] ?? '', 254),
                'username' => $this->requestString($_POST['username'] ?? '', 80),
                'terms' => !empty($_POST['terms']) ? '1' : '0',
            ]);
            $this->router->redirect($this->getPublicAuthPath('register'));
            return;
        }

        if (($settings['register_require_terms'] ?? '1') === '1' && empty($_POST['terms'])) {
            $_SESSION['error'] = 'Bitte akzeptiere die Nutzungsbedingungen und die Datenschutzerklärung.';
            $this->setAuthFormOldValues('register', [
                'email' => $this->requestString($_POST['email'] ?? '', 254),
                'username' => $this->requestString($_POST['username'] ?? '', 80),
                'terms' => !empty($_POST['terms']) ? '1' : '0',
            ]);
            $this->router->redirect($this->getPublicAuthPath('register'));
            return;
        }

        $result = Auth::instance()->register($_POST);

        if ($result === true) {
            $_SESSION['success'] = 'Registrierung erfolgreich! Sie können sich nun anmelden.';
            $this->router->redirect($this->getPublicAuthPath('login'));
        } else {
            $_SESSION['error'] = $result;
            $this->setAuthFormOldValues('register', [
                'email' => $this->requestString($_POST['email'] ?? '', 254),
                'username' => $this->requestString($_POST['username'] ?? '', 80),
                'terms' => !empty($_POST['terms']) ? '1' : '0',
            ]);
            $this->router->redirect($this->getPublicAuthPath('register'));
        }
    }

    public function redirectLegacyLogin(): void
    {
        if ($this->shouldRenderLegacyThemeAuthPage()) {
            $safeRedirectParts = $this->resolveAllowedRedirectParts($_GET['redirect'] ?? null);
            $safeRedirectTarget = $this->buildAllowedRedirectTarget($safeRedirectParts);

            ThemeManager::instance()->render('login', [
                'login_redirect' => $safeRedirectTarget,
            ]);
            return;
        }

        $this->redirectLegacyAuthPath('login');
    }

    public function redirectLegacyRegister(): void
    {
        if ($this->shouldRenderLegacyThemeAuthPage()) {
            ThemeManager::instance()->render('register');
            return;
        }

        $this->redirectLegacyAuthPath('register');
    }

    public function redirectLegacyForgotPassword(): void
    {
        if ($this->shouldRenderLegacyThemeAuthPage()) {
            ThemeManager::instance()->render('forgot-password');
            return;
        }

        $this->redirectLegacyAuthPath('forgot-password');
    }

    public function handleLogout(): void
    {
        if (Auth::instance()->isLoggedIn()) {
            $token = $this->requestString($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '', 128);
            if (!Security::instance()->verifyToken($token, 'logout')) {
                $this->router->redirect('/');
                return;
            }
        }

        Auth::instance()->logout();
        $this->router->redirect('/');
    }

    public function renderOrder(): void
    {
        if (!$this->isPublicOrderingEnabled()) {
            $this->router->render404();
            return;
        }

        $GLOBALS['cms_form_guard_csrf'] = Security::instance()->generateToken('form_guard');

        $file = ABSPATH . 'member/order_public.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }

        // Der Checkout liegt in CMS/orders.php – /order dorthin weiterleiten statt 404.
        $this->redirectToCheckout(302);
    }

    public function handleOrder(): void
    {
        if (!$this->isPublicOrderingEnabled()) {
            $this->router->render404();
            return;
        }

        $file = ABSPATH . 'member/order_public.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }

        // 307 erhält Methode und POST-Daten.
        $this->redirectToCheckout(307);
    }

    private function redirectToCheckout(int $status): void
    {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $target = rtrim((string) SITE_URL, '/') . '/orders.php' . ($query !== '' ? '?' . $query : '');

        if (!headers_sent()) {
            header('Location: ' . $target, true, $status);
        }
        exit;
    }

    private function isPublicOrderingEnabled(): bool
    {
        if (!class_exists(Services\CoreModuleService::class)) {
            return true;
        }

        try {
            $modules = Services\CoreModuleService::getInstance();

            return $modules->isModuleEnabled('subscriptions')
                && $modules->isModuleEnabled('subscription_public_pricing')
                && $modules->isModuleEnabled('subscription_ordering');
        } catch (\Throwable) {
            return false;
        }
    }

    public function handleCommentPost(): void
    {
        $postId = (int)($_POST['post_id'] ?? 0);
        $redirectTarget = $this->resolveCommentRedirect($postId);
        $commentFormValues = [
            'author' => $this->requestString($_POST['author'] ?? '', 120),
            'email' => $this->requestString($_POST['email'] ?? '', 254),
            'comment' => $this->requestString($_POST['comment'] ?? '', 5000),
            'comment_anonymous' => !empty($_POST['comment_anonymous']),
        ];

        if ($postId <= 0 || !Security::instance()->verifyToken($this->requestString($_POST['csrf_token'] ?? '', 128), 'comment_' . $postId)) {
            $this->flashCommentFormState($postId, 'error', 'Sicherheitsüberprüfung fehlgeschlagen.', $commentFormValues);
            $this->router->redirect($redirectTarget);
            return;
        }

        $currentUser = Auth::getCurrentUser();
        $isAnonymousComment = isset($currentUser->id) && !empty($_POST['comment_anonymous']);
        $result = Services\CommentService::getInstance()->createPendingComment(
            $postId,
            $this->requestString($_POST['author'] ?? '', 120),
            $this->requestString($_POST['email'] ?? '', 254),
            $this->requestString($_POST['comment'] ?? '', 5000),
            Security::getClientIp(),
            isset($currentUser->id) ? (int)$currentUser->id : null,
            $isAnonymousComment
        );

        if ($result === false) {
            $this->flashCommentFormState($postId, 'error', 'Kommentar konnte nicht gespeichert werden. Bitte prüfe deine Eingaben.', $commentFormValues);
        } else {
            $this->flashCommentFormState($postId, 'success', 'Kommentar gespeichert und zur Moderation eingereicht.');
        }

        $this->router->redirect($redirectTarget);
    }

    public function renderCookiePreferencesPage(): void
    {
        if (!class_exists(Services\CookieConsentService::class)) {
            $this->router->render404();
            return;
        }

        if (class_exists(Services\CoreModuleService::class)) {
            try {
                if (!Services\CoreModuleService::getInstance()->isModuleEnabled('legal')) {
                    $this->router->render404();
                    return;
                }
            } catch (\Throwable) {
            }
        }

        ThemeManager::instance()->render('page', [
            'page' => Services\CookieConsentService::getInstance()->getPublicConsentPage(),
        ]);
    }

    private function base64UrlDecode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return (string)(base64_decode($value, true) ?: '');
    }

    private function getLocalizedPublicPath(string $path): string
    {
        return Services\ContentLocalizationService::getInstance()->buildLocalizedPath(
            $path,
            $this->router->getRequestLocale()
        );
    }

    private function getCurrentDocumentLang(): string
    {
        $locale = strtolower(str_replace('_', '-', trim($this->router->getRequestLocale())));
        if ($locale === '') {
            return 'de';
        }

        return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i', $locale) === 1
            ? $locale
            : 'de';
    }

    private function buildLoginPagePath(?string $redirectTarget = null): string
    {
        $path = $this->getPublicAuthPath('login');
        $safeRedirect = $this->normalizeAllowedRedirectTarget($redirectTarget);

        if ($safeRedirect === '/member') {
            return $path;
        }

        return $path . '?redirect=' . urlencode($safeRedirect);
    }

    private function appendLoginErrorCode(string $path, string $code): string
    {
        $code = match ($code) {
            'security', 'invalid', 'passkey', 'session_required' => $code,
            default => 'invalid',
        };

        $separator = str_contains($path, '?') ? '&' : '?';
        return $path . $separator . 'login_error=' . rawurlencode($code);
    }

    private function getPublicAuthPath(string $page): string
    {
        return Services\CmsAuthPageService::getInstance()->getPublicPath($page, $this->router->getRequestLocale());
    }

    private function redirectLegacyAuthPath(string $page): void
    {
        $target = $this->getPublicAuthPath($page);
        $queryString = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? '');
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
            if (is_array($queryParams) && $queryParams !== []) {
                $target .= '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
            }
        }

        $this->router->redirect($target, 302);
    }

    private function shouldRenderLegacyThemeAuthPage(): bool
    {
        $settings = Services\CmsAuthPageService::getInstance()->getSettings();

        return (($settings['auth_slug_mode'] ?? 'cms') === 'legacy');
    }

    private function setAuthFormOldValues(string $page, array $values): void
    {
        if (!isset($_SESSION['auth_form_old']) || !is_array($_SESSION['auth_form_old'])) {
            $_SESSION['auth_form_old'] = [];
        }

        $_SESSION['auth_form_old'][$page] = $values;
    }


    private function resolveSuccessfulLoginRedirect(string $redirectTarget): string
    {
        if ($redirectTarget !== '/member') {
            return $this->normalizeAllowedRedirectTarget($redirectTarget);
        }

        return Auth::instance()->isAdmin() ? '/admin' : '/member';
    }

    private function resolvePendingLoginRedirect(string $redirectTarget, string $loginInput): string
    {
        if ($redirectTarget !== '/member') {
            return $this->normalizeAllowedRedirectTarget($redirectTarget);
        }

        $user = $this->findUserForLoginInput($loginInput);
        if ($user !== null && (string)($user->role ?? '') === 'admin') {
            return '/admin';
        }

        return '/member';
    }

    private function findUserForLoginInput(string $loginInput): ?object
    {
        $loginInput = trim($loginInput);
        if ($loginInput === '') {
            return null;
        }

        try {
            $db = Database::instance();
            $stmt = $db->prepare("SELECT id, role FROM {$db->getPrefix()}users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$loginInput, $loginInput]);
            $user = $stmt->fetch();

            return is_object($user) ? $user : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function consumePostLoginRedirect(): string
    {
        $redirect = $this->normalizeAllowedRedirectTarget($_SESSION['auth_redirect_after_login'] ?? null);
        unset($_SESSION['auth_redirect_after_login']);
        return $redirect;
    }

    private function resolveCommentRedirect(int $postId): string
    {
        $refererTarget = $this->normalizeAllowedRedirectTarget($_SERVER['HTTP_REFERER'] ?? null);
        if (
            $refererTarget !== ''
            && !in_array($refererTarget, ['/member', '/admin', '/dashboard'], true)
        ) {
            return preg_replace('/#.*$/', '', $refererTarget) . '#comments';
        }

        if ($postId > 0) {
            $db = Database::instance();
            $post = $db->get_row("SELECT slug, published_at, created_at FROM {$db->getPrefix()}posts WHERE id = ? LIMIT 1", [$postId]);
            $slug = trim((string)($post->slug ?? ''));
            if ($slug !== '') {
                $path = class_exists('CMS\\Services\\PermalinkService')
                    ? \CMS\Services\PermalinkService::getInstance()->buildPostPathFromValues(
                        $slug,
                        (string)($post->published_at ?? ''),
                        (string)($post->created_at ?? '')
                    )
                    : '/blog/' . rawurlencode($slug);
                return $path . '#comments';
            }
        }

        return '/blog';
    }

    private function flashCommentFormState(int $postId, string $type, string $message, array $values = []): void
    {
        if ($postId <= 0) {
            return;
        }

        if (!isset($_SESSION['comment_form_state']) || !is_array($_SESSION['comment_form_state'])) {
            $_SESSION['comment_form_state'] = [];
        }

        $_SESSION['comment_form_state'][$postId] = [
            'type' => in_array($type, ['success', 'error', 'info'], true) ? $type : 'error',
            'message' => trim($message),
            'values' => $this->sanitizeCommentFormValues($values),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array{author:string,email:string,comment:string,comment_anonymous:bool}
     */
    private function sanitizeCommentFormValues(array $values): array
    {
        return [
            'author' => trim((string) ($values['author'] ?? '')),
            'email' => trim((string) ($values['email'] ?? '')),
            'comment' => trim((string) ($values['comment'] ?? '')),
            'comment_anonymous' => !empty($values['comment_anonymous']),
        ];
    }

    private function redirectToSafeTarget(array $targetParts): void
    {
        $this->router->redirect($this->buildAllowedRedirectTarget($targetParts));
    }

    private function resolveAllowedRedirectParts(mixed $candidate): array
    {
        if (!is_scalar($candidate)) {
            return ['route' => 'member', 'suffix' => '', 'query' => ''];
        }

        $rawValue = trim((string) $candidate);
        if ($rawValue === '') {
            return ['route' => 'member', 'suffix' => '', 'query' => ''];
        }

        $relativeValue = $this->toSameOriginRelativePath($rawValue);
        if ($relativeValue === '') {
            return ['route' => 'member', 'suffix' => '', 'query' => ''];
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $relativeValue) === 1 || str_starts_with($relativeValue, '//')) {
            return ['route' => 'member', 'suffix' => '', 'query' => ''];
        }

        $path = (string) parse_url($relativeValue, PHP_URL_PATH);
        $query = (string) parse_url($relativeValue, PHP_URL_QUERY);
        if ($path === '') {
            return ['route' => 'member', 'suffix' => '', 'query' => ''];
        }

        foreach (['admin' => '/admin', 'member' => '/member', 'dashboard' => '/dashboard'] as $route => $allowedPrefix) {
            if ($path === $allowedPrefix) {
                return [
                    'route' => $route,
                    'suffix' => '',
                    'query' => $this->sanitizeAllowedQueryString($query),
                ];
            }

            if (!str_starts_with($path, $allowedPrefix . '/')) {
                continue;
            }

            $suffix = substr($path, strlen($allowedPrefix));
            $safeSuffix = $this->sanitizeAllowedPathSuffix($suffix);

            return [
                'route' => $route,
                'suffix' => $safeSuffix ?? '',
                'query' => $this->sanitizeAllowedQueryString($query),
            ];
        }

        $safePublicPath = $this->sanitizeAllowedPublicRedirectPath($path);
        if ($safePublicPath !== null) {
            return [
                'route' => 'public',
                'path' => $safePublicPath,
                'query' => $this->sanitizeAllowedQueryString($query),
            ];
        }

        return ['route' => 'member', 'suffix' => '', 'query' => ''];
    }

    private function buildAllowedRedirectTarget(array $targetParts): string
    {
        $route = (string) ($targetParts['route'] ?? 'member');

        if ($route === 'public') {
            $safePublicPath = $this->sanitizeAllowedPublicRedirectPath((string) ($targetParts['path'] ?? ''));
            if ($safePublicPath === null) {
                return '/member';
            }

            $safeQuery = $this->sanitizeAllowedQueryString((string) ($targetParts['query'] ?? ''));

            return $safeQuery !== '' ? $safePublicPath . '?' . $safeQuery : $safePublicPath;
        }

        $suffix = (string) ($targetParts['suffix'] ?? '');
        $query = (string) ($targetParts['query'] ?? '');

        $basePath = match ($route) {
            'admin' => '/admin',
            'dashboard' => '/dashboard',
            default => '/member',
        };

        $safeSuffix = $this->sanitizeAllowedPathSuffix($suffix);
        if ($safeSuffix === null) {
            $safeSuffix = '';
        }

        $target = $basePath . $safeSuffix;
        $safeQuery = $this->sanitizeAllowedQueryString($query);

        return $safeQuery !== '' ? $target . '?' . $safeQuery : $target;
    }

    private function normalizeAllowedRedirectTarget(mixed $candidate): string
    {
        return $this->buildAllowedRedirectTarget($this->resolveAllowedRedirectParts($candidate));
    }

    private function toSameOriginRelativePath(string $candidate): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate) !== 1) {
            return str_starts_with($candidate, '/') ? $candidate : '/' . ltrim($candidate, '/');
        }

        $siteParts = parse_url((string) SITE_URL);
        $targetParts = parse_url($candidate);
        if (!is_array($siteParts) || !is_array($targetParts)) {
            return '';
        }

        $siteScheme = strtolower((string) ($siteParts['scheme'] ?? ''));
        $siteHost = strtolower((string) ($siteParts['host'] ?? ''));
        $targetScheme = strtolower((string) ($targetParts['scheme'] ?? ''));
        $targetHost = strtolower((string) ($targetParts['host'] ?? ''));

        if ($siteScheme !== $targetScheme || $siteHost !== $targetHost) {
            return '';
        }

        $path = (string) ($targetParts['path'] ?? '/');
        $query = isset($targetParts['query']) && $targetParts['query'] !== '' ? '?' . (string) $targetParts['query'] : '';

        return $path . $query;
    }

    private function sanitizeAllowedPathSuffix(string $suffix): ?string
    {
        if ($suffix === '') {
            return '';
        }

        if (preg_match('#^/(?:[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*)$#', $suffix) !== 1) {
            return null;
        }

        return $suffix;
    }

    private function sanitizeAllowedPublicRedirectPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || str_starts_with($path, '//')) {
            return null;
        }

        if (str_contains($path, '/../') || str_contains($path, '/./') || str_ends_with($path, '/..') || str_ends_with($path, '/.')) {
            return null;
        }

        if (preg_match('#^/(?:[A-Za-z0-9\-._~%!$&()*+,;=:@]+(?:/[A-Za-z0-9\-._~%!$&()*+,;=:@]+)*)?/?$#', $path) !== 1) {
            return null;
        }

        $basePath = $path;
        try {
            $requestContext = Services\ContentLocalizationService::getInstance()->resolveRequestContext($path);
            $basePath = (string) ($requestContext['base_uri'] ?? $path);
        } catch (\Throwable) {
            $basePath = $path;
        }

        foreach (['/api'] as $disallowedPrefix) {
            if ($basePath === $disallowedPrefix || str_starts_with($basePath, $disallowedPrefix . '/')) {
                return null;
            }
        }

        if (in_array($basePath, [
            '/login',
            '/register',
            '/forgot-password',
            '/cms-login',
            '/cms-register',
            '/cms-password-forgot',
            '/logout',
            '/mfa-challenge',
            '/mfa-setup',
            '/mfa-disable',
        ], true)) {
            return null;
        }

        return $path;
    }

    private function sanitizeAllowedQueryString(string $query): string
    {
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);
        if (!is_array($params) || $params === []) {
            return '';
        }

        $allowed = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $key) !== 1) {
                continue;
            }

            if (is_array($value)) {
                continue;
            }

            $stringValue = trim((string) $value);
            if ($stringValue === '' || preg_match('/^[a-zA-Z0-9_\-.,:@]{1,120}$/', $stringValue) !== 1) {
                continue;
            }

            $allowed[$key] = $stringValue;
        }

        return $allowed === [] ? '' : http_build_query($allowed, '', '&', PHP_QUERY_RFC3986);
    }

    private function requestString(mixed $value, int $maxLength, bool $stripTags = true): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';
        if ($stripTags) {
            $value = strip_tags($value);
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, max(1, $maxLength), 'UTF-8') : substr($value, 0, max(1, $maxLength));
    }
}
