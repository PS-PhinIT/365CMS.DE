<?php
declare(strict_types=1);

namespace CMS\Services;

use CMS\Auth;
use CMS\Database;
use CMS\Logger;
use CMS\Security;

if (!defined('ABSPATH')) {
    exit;
}

final class CmsAuthPageService
{
    private const SETTING_PREFIX = 'cms_loginpage_';
    private const FORGOT_PASSWORD_REQUEST_RATE_LIMIT_ATTEMPTS = 5;
    private const FORGOT_PASSWORD_REQUEST_RATE_LIMIT_WINDOW = 900;
    private const FORGOT_PASSWORD_REQUEST_MIN_RESPONSE_MICROSECONDS = 350000;
    private const FORGOT_PASSWORD_RESET_RATE_LIMIT_ATTEMPTS = 10;
    private const FORGOT_PASSWORD_RESET_RATE_LIMIT_WINDOW = 900;
    private const FORGOT_PASSWORD_RESET_TOKEN_RATE_LIMIT_ATTEMPTS = 5;
    private const FORGOT_PASSWORD_RESET_TOKEN_RATE_LIMIT_WINDOW = 900;
    private const AUTH_CANONICAL_PATHS = [
        'login' => '/cms-login',
        'register' => '/cms-register',
        'forgot-password' => '/cms-password-forgot',
    ];
    private const AUTH_LEGACY_PATHS = [
        'login' => '/login',
        'register' => '/register',
        'forgot-password' => '/forgot-password',
    ];
    private const ALLOWED_LAYOUT_VARIANTS = ['centered', 'split'];
    private const ALLOWED_SLUG_MODES = ['cms', 'legacy'];

    /** @var array<string, string> */
    private const SHARED_SETTING_DEFAULTS = [
        'registration_enabled' => '1',
        'member_registration_enabled' => '1',
        'privacy_page_id' => '0',
        'terms_page_id' => '0',
        'imprint_page_id' => '0',
    ];

    private static ?self $instance = null;

    private Database $db;
    private Logger $logger;
    private string $prefix;

    /** @var array<string,string>|null */
    private ?array $settingsCache = null;

    private function __construct()
    {
        $this->db = Database::instance();
        $this->logger = Logger::instance()->withChannel('service.cms-auth-page');
        $this->prefix = $this->db->getPrefix();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getPath(string $page): string
    {
        $normalizedPage = $this->normalizePageKey($page);

        return self::AUTH_CANONICAL_PATHS[$normalizedPage] ?? self::AUTH_CANONICAL_PATHS['login'];
    }

    public function getLegacyPath(string $page): string
    {
        $normalizedPage = $this->normalizePageKey($page);

        return self::AUTH_LEGACY_PATHS[$normalizedPage] ?? self::AUTH_LEGACY_PATHS['login'];
    }

    public function getPublicPath(string $page, ?string $locale = null, ?array $settings = null): string
    {
        $settings ??= $this->getSettings();
        $normalizedPage = $this->normalizePageKey($page);
        $slugMode = $this->sanitizeEnum((string) ($settings['auth_slug_mode'] ?? 'cms'), self::ALLOWED_SLUG_MODES, 'cms');
        $path = $slugMode === 'legacy'
            ? $this->getLegacyPath($normalizedPage)
            : $this->getPath($normalizedPage);

        $resolvedLocale = trim((string) $locale);
        if ($resolvedLocale === '') {
            return $path;
        }

        try {
            return ContentLocalizationService::getInstance()->buildLocalizedPath($path, $resolvedLocale);
        } catch (\Throwable) {
            return $path;
        }
    }

    public function getPublicUrl(string $page, ?string $locale = null, array $query = []): string
    {
        $path = $this->getPublicPath($page, $locale);

        if ($query !== []) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $siteUrl = rtrim((string) (defined('SITE_URL') ? SITE_URL : ''), '/');
        if ($siteUrl === '') {
            return $path;
        }

        return $siteUrl . $path;
    }

    public function buildPath(string $page, array $query = []): string
    {
        $path = $this->getPath($page);
        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string, string> */
    public function getSettings(): array
    {
        // Wird pro Request mehrfach aufgerufen (Router, Theme, Auth-Seiten) – Ergebnis merken.
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $settings = array_merge($this->getDefaultSettings(), self::SHARED_SETTING_DEFAULTS);

        try {
            // Über den Options-Cache statt zweier eigener Abfragen (LIKE-Scan auf settings) je Request.
            $store = OptionStore::getInstance();
            $prefixedNames = array_map(static fn(string $key): string => self::SETTING_PREFIX . $key, array_keys($settings));
            foreach ($store->getMany($prefixedNames) as $optionName => $value) {
                $settingKey = substr((string) $optionName, strlen(self::SETTING_PREFIX));
                if ($value !== null && $settingKey !== '' && array_key_exists($settingKey, $settings)) {
                    $settings[$settingKey] = $value;
                }
            }

            // Gemeinsam genutzte Einstellungen (ohne Präfix) haben Vorrang.
            foreach ($store->getMany(array_keys(self::SHARED_SETTING_DEFAULTS)) as $optionName => $value) {
                if ($value !== null && array_key_exists((string) $optionName, $settings)) {
                    $settings[(string) $optionName] = $value;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('CMS-Auth-Settings konnten nicht geladen werden.', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $this->settingsCache = $settings;
    }

    /** @return array<int, array{id:int,title:string,slug:string,url:string}> */
    public function getPageOptions(): array
    {
        try {
            $rows = $this->db->get_results(
                "SELECT id, title, slug FROM {$this->prefix}pages WHERE status = 'published' ORDER BY title ASC"
            ) ?: [];

            $options = [];
            foreach ($rows as $row) {
                $id = (int) ($row->id ?? 0);
                $slug = trim((string) ($row->slug ?? ''));
                if ($id <= 0 || $slug === '') {
                    continue;
                }

                $options[] = [
                    'id' => $id,
                    'title' => trim((string) ($row->title ?? $slug)) !== '' ? (string) ($row->title ?? $slug) : $slug,
                    'slug' => $slug,
                    'url' => '/' . ltrim($slug, '/'),
                ];
            }

            return $options;
        } catch (\Throwable $e) {
            $this->logger->error('CMS-Auth-Seitenoptionen konnten nicht geladen werden.', [
                'exception' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** @return array{success:bool,message?:string,error?:string} */
    public function saveSettings(array $input): array
    {
        $this->settingsCache = null;

        try {
            $values = $this->normalizeSettingsInput($input);

            foreach ($values as $key => $value) {
                $optionName = array_key_exists($key, self::SHARED_SETTING_DEFAULTS)
                    ? $key
                    : self::SETTING_PREFIX . $key;
                if (!$this->upsertSetting($optionName, $value)) {
                    throw new \RuntimeException('Einstellung „' . $optionName . '“ konnte nicht persistiert werden.');
                }
            }

            return ['success' => true, 'message' => 'CMS Loginpage gespeichert.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'CMS Loginpage konnte nicht gespeichert werden: ' . $e->getMessage()];
        } finally {
            $this->settingsCache = null;
        }
    }

    public function isRegistrationEnabled(?array $settings = null): bool
    {
        $settings ??= $this->getSettings();

        return ($settings['registration_enabled'] ?? '1') === '1'
            && ($settings['member_registration_enabled'] ?? '1') === '1';
    }

    public function isPasskeyLoginEnabled(?array $settings = null): bool
    {
        $settings ??= $this->getSettings();

        return ($settings['login_show_passkey'] ?? '1') === '1';
    }

    public function render(string $page, array $context = []): void
    {
        $settings = $this->getSettings();
        $siteName = defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
        $pageType = in_array($page, ['login', 'register', 'forgot-password'], true) ? $page : 'login';
        $pageTitle = match ($pageType) {
            'register' => 'Registrieren',
            'forgot-password' => 'Passwort zurücksetzen',
            default => 'Anmelden',
        };

        $pageTitleText = $pageTitle . ' – ' . $siteName;
        $flashError = trim((string) ($_SESSION['error'] ?? ''));
        $flashSuccess = trim((string) ($_SESSION['success'] ?? ''));
        unset($_SESSION['error'], $_SESSION['success']);

        if ($flashError === '' && $pageType === 'login') {
            $flashError = $this->resolveLoginErrorFallback((string) ($_GET['login_error'] ?? ''));
        }

        $oldInputBag = is_array($_SESSION['auth_form_old'] ?? null) ? $_SESSION['auth_form_old'] : [];
        $oldInput = is_array($oldInputBag[$pageType] ?? null) ? $oldInputBag[$pageType] : [];
        unset($_SESSION['auth_form_old'][$pageType]);

        $csrfAction = match ($pageType) {
            'register' => 'register',
            'forgot-password' => 'forgot_password',
            default => 'login',
        };
        $csrfToken = Security::instance()->generateToken($csrfAction);

        $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $requestLocale = 'de';
        try {
            $requestContext = ContentLocalizationService::getInstance()->resolveRequestContext($requestPath);
            $requestLocale = (string) ($requestContext['locale'] ?? 'de');
        } catch (\Throwable) {
            $requestLocale = 'de';
        }
        $documentLanguage = $this->normalizeHtmlLang($requestLocale);

        $loginUrl = $this->getPublicPath('login', $requestLocale, $settings);
        $registerUrl = $this->getPublicPath('register', $requestLocale, $settings);
        $forgotUrl = $this->getPublicPath('forgot-password', $requestLocale, $settings);
        try {
            $homeUrl = ContentLocalizationService::getInstance()->buildLocalizedPath('/', $requestLocale);
        } catch (\Throwable) {
            $homeUrl = '/';
        }
        $loginRedirect = trim((string) ($context['login_redirect'] ?? ($_GET['redirect'] ?? '')));
        $forgotStep = (string) ($context['forgot_step'] ?? ($_GET['step'] ?? 'request'));
        $forgotStep = in_array($forgotStep, ['request', 'reset', 'done'], true) ? $forgotStep : 'request';
        $resetToken = trim((string) ($context['reset_token'] ?? ($_GET['token'] ?? '')));
        if ($pageType === 'forgot-password' && $forgotStep === 'reset' && $resetToken !== '' && !headers_sent()) {
            header('Referrer-Policy: no-referrer', true);
        }
        $passkeyPayload = is_array($context['passkey_payload'] ?? null) ? $context['passkey_payload'] : ['available' => false, 'options_json' => '{}'];
        if (!$this->isPasskeyLoginEnabled($settings)) {
            $passkeyPayload = ['available' => false, 'options_json' => '{}'];
        }

        $registerEnabled = $this->isRegistrationEnabled($settings);
        $legalLinks = $this->resolveLegalLinks($settings);

        $viewFile = ABSPATH . 'views/auth/cms-auth.php';
        if (!is_file($viewFile)) {
            throw new \RuntimeException('CMS Auth View fehlt: ' . $viewFile);
        }

        require $viewFile;
        exit;
    }

    private function resolveLoginErrorFallback(string $code): string
    {
        return match ($code) {
            'security' => 'Sicherheitsüberprüfung fehlgeschlagen. Bitte die Loginseite neu laden und erneut versuchen.',
            'passkey' => 'Die Passkey-Anmeldung konnte nicht abgeschlossen werden. Bitte erneut versuchen oder Passwort-Login nutzen.',
            'session_required' => 'Bitte melden Sie sich an, um den geschützten Bereich zu öffnen.',
            'invalid' => 'Anmeldung fehlgeschlagen. Bitte Zugangsdaten prüfen.',
            default => '',
        };
    }

    /** @return array{success:bool,message:string} */
    public function requestPasswordReset(string $email, ?string $locale = null): array
    {
        $email = trim($email);
        if ($email === '' || !Security::validateEmail($email)) {
            return ['success' => false, 'message' => 'Bitte eine gültige E-Mail-Adresse eingeben.'];
        }

        $requestStartedAt = microtime(true);

        $settings = $this->getSettings();
        $expiryMinutes = max(5, min(1440, (int) ($settings['password_reset_expiry_minutes'] ?? '60')));
        $security = Security::instance();
        $clientIp = $security->getClientIp();
        $normalizedEmail = strtolower($email);

        if (!$security->checkDbRateLimit(
            $clientIp,
            'forgot_password_request',
            self::FORGOT_PASSWORD_REQUEST_RATE_LIMIT_ATTEMPTS,
            self::FORGOT_PASSWORD_REQUEST_RATE_LIMIT_WINDOW
        )) {
            $this->logger->warning('Passwort-Reset-Anfrage wegen Rate-Limit geblockt.', [
                'ip' => $clientIp,
                'email' => $email,
            ]);

            return $this->withMinimumForgotPasswordRequestDuration([
                'success' => false,
                'message' => 'Zu viele Reset-Anfragen. Bitte warte einige Minuten und versuche es erneut.',
            ], $requestStartedAt);
        }

        if (!$this->checkPasswordResetIdentifierRateLimit(
            $normalizedEmail,
            'forgot_password_request_account',
            self::FORGOT_PASSWORD_REQUEST_RATE_LIMIT_ATTEMPTS,
            self::FORGOT_PASSWORD_REQUEST_RATE_LIMIT_WINDOW
        )) {
            $this->logger->warning('Passwort-Reset-Anfrage wegen konto-bezogenem Rate-Limit geblockt.', [
                'ip' => $clientIp,
                'email' => $normalizedEmail,
            ]);

            return $this->withMinimumForgotPasswordRequestDuration([
                'success' => true,
                'message' => (string) ($settings['forgot_request_success_message'] ?? 'Falls ein Konto mit dieser E-Mail-Adresse existiert, haben wir einen Reset-Link versendet.'),
            ], $requestStartedAt);
        }

        Security::recordDbRateLimitAttempt($clientIp, 'forgot_password_request', $normalizedEmail);
        Security::recordDbRateLimitAttempt($clientIp, 'forgot_password_request_account', $normalizedEmail);

        try {
            $user = $this->db->get_row(
                "SELECT id, email FROM {$this->prefix}users WHERE email = ? AND status = 'active' LIMIT 1",
                [$email]
            );

            if ($user !== null && !empty($user->email)) {
                $token = bin2hex(random_bytes(32));
                $hashedToken = hash('sha256', $token);
                $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));

                $this->db->execute("DELETE FROM {$this->prefix}password_resets WHERE email = ?", [$email]);
                $this->db->execute(
                    "INSERT INTO {$this->prefix}password_resets (email, token, expires_at, created_at) VALUES (?, ?, ?, NOW())",
                    [$email, $hashedToken, $expiresAt]
                );

                $resetUrl = $this->getPublicUrl('forgot-password', $locale, [
                    'step' => 'reset',
                    'token' => $token,
                ]);

                $subject = $this->renderTemplateText(
                    (string) ($settings['password_reset_email_subject'] ?? ''),
                    [
                        '{site_name}' => defined('SITE_NAME') ? (string) SITE_NAME : '365CMS',
                        '{brand_name}' => (string) ($settings['brand_name'] ?? '365CMS'),
                        '{expires_minutes}' => (string) $expiryMinutes,
                        '{reset_url}' => $resetUrl,
                    ]
                );
                $body = $this->renderTemplateText(
                    (string) ($settings['password_reset_email_body'] ?? ''),
                    [
                        '{site_name}' => defined('SITE_NAME') ? (string) SITE_NAME : '365CMS',
                        '{brand_name}' => (string) ($settings['brand_name'] ?? '365CMS'),
                        '{expires_minutes}' => (string) $expiryMinutes,
                        '{reset_url}' => $resetUrl,
                    ]
                );

                if (function_exists('cms_mail')) {
                    \cms_mail($email, $subject, $body, ['Content-Type' => 'text/plain; charset=UTF-8']);
                }
            }

            return $this->withMinimumForgotPasswordRequestDuration([
                'success' => true,
                'message' => (string) ($settings['forgot_request_success_message'] ?? 'Falls ein Konto mit dieser E-Mail-Adresse existiert, haben wir einen Reset-Link versendet.'),
            ], $requestStartedAt);
        } catch (\Throwable $e) {
            $this->logger->error('Passwort-Reset-Anfrage konnte nicht verarbeitet werden.', [
                'email' => $email,
                'exception' => $e->getMessage(),
            ]);
            return $this->withMinimumForgotPasswordRequestDuration([
                'success' => false,
                'message' => 'Die Passwort-Zurücksetzung konnte gerade nicht verarbeitet werden.',
            ], $requestStartedAt);
        }
    }

    /** @return array{success:bool,message:string} */
    public function resetPassword(string $token, string $password, string $passwordConfirmation): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['success' => false, 'message' => 'Ungültiger Reset-Link.'];
        }

        $security = Security::instance();
        $clientIp = $security->getClientIp();
        $tokenRateLimitKey = hash('sha256', $token);

        if (!$security->checkDbRateLimit(
            $clientIp,
            'forgot_password_reset',
            self::FORGOT_PASSWORD_RESET_RATE_LIMIT_ATTEMPTS,
            self::FORGOT_PASSWORD_RESET_RATE_LIMIT_WINDOW
        )) {
            $this->logger->warning('Passwort-Reset wegen Rate-Limit geblockt.', [
                'ip' => $clientIp,
            ]);

            return ['success' => false, 'message' => 'Zu viele Reset-Versuche. Bitte warte einige Minuten und versuche es erneut.'];
        }

        if (!$this->checkPasswordResetIdentifierRateLimit(
            $tokenRateLimitKey,
            'forgot_password_reset_token',
            self::FORGOT_PASSWORD_RESET_TOKEN_RATE_LIMIT_ATTEMPTS,
            self::FORGOT_PASSWORD_RESET_TOKEN_RATE_LIMIT_WINDOW
        )) {
            $this->logger->warning('Passwort-Reset wegen token-bezogenem Rate-Limit geblockt.', [
                'ip' => $clientIp,
                'token_hash' => $tokenRateLimitKey,
            ]);

            return ['success' => false, 'message' => 'Zu viele Reset-Versuche. Bitte warte einige Minuten und versuche es erneut.'];
        }

        Security::recordDbRateLimitAttempt($clientIp, 'forgot_password_reset');
        Security::recordDbRateLimitAttempt($clientIp, 'forgot_password_reset_token', $tokenRateLimitKey);

        if ($password !== $passwordConfirmation) {
            return ['success' => false, 'message' => 'Die Passwörter stimmen nicht überein.'];
        }

        $policyResult = Auth::validatePasswordPolicy($password);
        if ($policyResult !== true) {
            return ['success' => false, 'message' => (string) $policyResult];
        }

        $settings = $this->getSettings();

        try {
            $row = $this->db->get_row(
                "SELECT email, expires_at FROM {$this->prefix}password_resets WHERE token = ? LIMIT 1",
                [hash('sha256', $token)]
            );

            if ($row === null || empty($row->email)) {
                return ['success' => false, 'message' => 'Ungültiger oder bereits verwendeter Reset-Link.'];
            }

            if (strtotime((string) $row->expires_at) < time()) {
                return ['success' => false, 'message' => 'Dieser Reset-Link ist abgelaufen. Bitte fordere einen neuen Link an.'];
            }

            $passwordHash = Security::instance()->hashPassword($password);
            $this->db->execute("UPDATE {$this->prefix}users SET password = ? WHERE email = ?", [$passwordHash, (string) $row->email]);
            $this->db->execute("DELETE FROM {$this->prefix}password_resets WHERE email = ?", [(string) $row->email]);
            // Bestehende Sitzungen werden über den Passwort-Fingerprint ungültig (Auth::checkSession());
            // die Einträge der Sitzungsübersicht gleich mit entfernen.
            $this->db->execute(
                "DELETE s FROM {$this->prefix}sessions s INNER JOIN {$this->prefix}users u ON u.id = s.user_id WHERE u.email = ?",
                [(string) $row->email]
            );

            return ['success' => true, 'message' => (string) ($settings['forgot_reset_success_message'] ?? 'Dein Passwort wurde erfolgreich geändert.')];
        } catch (\Throwable $e) {
            $this->logger->error('Passwort konnte nicht zurückgesetzt werden.', [
                'exception' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Das Passwort konnte nicht zurückgesetzt werden.'];
        }
    }

    private function checkPasswordResetIdentifierRateLimit(string $identifier, string $action, int $maxAttempts, int $timeWindow): bool
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return false;
        }

        try {
            $since = date('Y-m-d H:i:s', time() - $timeWindow);
            $row = $this->db->get_row(
                "SELECT COUNT(*) AS attempt_count FROM {$this->prefix}login_attempts WHERE username = ? AND action = ? AND attempted_at >= ?",
                [Security::normalizeRateLimitIdentifier($identifier), Security::normalizeRateLimitAction($action), $since]
            );

            return (int) ($row->attempt_count ?? 0) < $maxAttempts;
        } catch (\Throwable $e) {
            $this->logger->warning('Passwort-Reset Identifier-Rate-Limit konnte nicht geprüft werden.', [
                'action' => $action,
                'exception' => $e->getMessage(),
            ]);

            return Security::checkRateLimit('forgot_identifier_' . $action . '_' . hash('sha256', $identifier), $maxAttempts, $timeWindow);
        }
    }

    /** @param array{success:bool,message:string} $result */
    private function withMinimumForgotPasswordRequestDuration(array $result, float $startedAt): array
    {
        $elapsedMicroseconds = (int) ((microtime(true) - $startedAt) * 1000000);
        $remainingMicroseconds = self::FORGOT_PASSWORD_REQUEST_MIN_RESPONSE_MICROSECONDS - $elapsedMicroseconds;
        if ($remainingMicroseconds > 0) {
            usleep($remainingMicroseconds);
        }

        return $result;
    }

    /** @return array<string, string> */
    private function getDefaultSettings(): array
    {
        return [
            'brand_name' => defined('SITE_NAME') ? (string) SITE_NAME : '365CMS',
            'logo_url' => '',
            'card_width' => '520',
            'layout_variant' => 'centered',
            'auth_slug_mode' => 'cms',
            'background_start' => '#0f172a',
            'background_end' => '#1d4ed8',
            'card_background' => '#ffffff',
            'text_color' => '#0f172a',
            'muted_color' => '#475569',
            'primary_color' => '#2563eb',
            'primary_text_color' => '#ffffff',
            'link_color' => '#1d4ed8',
            'input_background' => '#f8fafc',
            'input_border' => '#cbd5e1',
            'headline_login' => 'Willkommen zurück',
            'headline_register' => 'Neues Konto erstellen',
            'headline_forgot' => 'Passwort zurücksetzen',
            'subheadline_login' => 'Melde dich direkt im CMS an – komplett unabhängig vom aktiven Frontend-Theme.',
            'subheadline_register' => 'Registriere dein Konto über die CMS-eigene Anmeldestrecke.',
            'subheadline_forgot' => 'Fordere einen sicheren Reset-Link direkt über die CMS-Loginpage an.',
            'footer_note' => '365CMS Core Login',
            'login_label_identifier' => 'Benutzername oder E-Mail',
            'login_label_password' => 'Passwort',
            'login_button_text' => 'Anmelden',
            'login_forgot_link_text' => 'Vergessen?',
            'login_identifier_placeholder' => 'name@example.de',
            'login_password_placeholder' => 'Dein Passwort',
            'login_remember_label' => 'Angemeldet bleiben',
            'login_show_remember' => '1',
            'login_show_passkey' => '1',
            'login_passkey_button_text' => 'Mit Passkey anmelden',
            'register_label_email' => 'E-Mail-Adresse',
            'register_label_username' => 'Benutzername',
            'register_label_password' => 'Passwort',
            'register_label_password_confirm' => 'Passwort bestätigen',
            'register_button_text' => 'Konto erstellen',
            'register_email_placeholder' => 'deine@email.de',
            'register_username_placeholder' => 'mein_name',
            'register_require_terms' => '1',
            'register_terms_label' => 'Ich akzeptiere die Nutzungsbedingungen und die Datenschutzerklärung.',
            'register_disabled_message' => 'Die Registrierung ist aktuell deaktiviert. Bitte kontaktiere uns bei Fragen direkt.',
            'forgot_label_email' => 'E-Mail-Adresse',
            'forgot_email_placeholder' => 'deine@email.de',
            'forgot_request_button_text' => 'Reset-Link senden',
            'forgot_reset_button_text' => 'Passwort ändern',
            'forgot_done_button_text' => 'Jetzt anmelden',
            'forgot_request_success_message' => 'Falls ein Konto mit dieser E-Mail-Adresse existiert, haben wir einen Reset-Link versendet.',
            'forgot_reset_success_message' => 'Dein Passwort wurde erfolgreich geändert.',
            'footer_link_login' => 'Zur Anmeldung',
            'footer_link_register' => 'Registrieren',
            'footer_link_forgot' => 'Passwort vergessen',
            'footer_link_home' => 'Zur Startseite',
            'password_reset_expiry_minutes' => '60',
            'password_reset_email_subject' => '[{site_name}] Passwort zurücksetzen',
            'password_reset_email_body' => "Hallo,\n\ndu hast eine Anfrage zum Zurücksetzen deines Passworts gestellt.\n\nKlicke auf den folgenden Link (gültig für {expires_minutes} Minuten):\n{reset_url}\n\nFalls du diese Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren.\n\nViele Grüße\n{brand_name}",
        ];
    }

    /** @return array<string, string> */
    private function normalizeSettingsInput(array $input): array
    {
        $publishedPageIds = $this->getPublishedPageIdMap();

        return [
            'brand_name' => $this->sanitizeText($input['brand_name'] ?? $this->getDefaultSettings()['brand_name'], 80),
            'logo_url' => $this->sanitizeUrl($input['logo_url'] ?? ''),
            'card_width' => (string) max(380, min(960, (int) ($input['card_width'] ?? 520))),
            'layout_variant' => $this->sanitizeEnum((string) ($input['layout_variant'] ?? 'centered'), self::ALLOWED_LAYOUT_VARIANTS, 'centered'),
            'auth_slug_mode' => $this->sanitizeEnum((string) ($input['auth_slug_mode'] ?? 'cms'), self::ALLOWED_SLUG_MODES, 'cms'),
            'background_start' => $this->sanitizeColor($input['background_start'] ?? '#0f172a', '#0f172a'),
            'background_end' => $this->sanitizeColor($input['background_end'] ?? '#1d4ed8', '#1d4ed8'),
            'card_background' => $this->sanitizeColor($input['card_background'] ?? '#ffffff', '#ffffff'),
            'text_color' => $this->sanitizeColor($input['text_color'] ?? '#0f172a', '#0f172a'),
            'muted_color' => $this->sanitizeColor($input['muted_color'] ?? '#475569', '#475569'),
            'primary_color' => $this->sanitizeColor($input['primary_color'] ?? '#2563eb', '#2563eb'),
            'primary_text_color' => $this->sanitizeColor($input['primary_text_color'] ?? '#ffffff', '#ffffff'),
            'link_color' => $this->sanitizeColor($input['link_color'] ?? '#1d4ed8', '#1d4ed8'),
            'input_background' => $this->sanitizeColor($input['input_background'] ?? '#f8fafc', '#f8fafc'),
            'input_border' => $this->sanitizeColor($input['input_border'] ?? '#cbd5e1', '#cbd5e1'),
            'headline_login' => $this->sanitizeText($input['headline_login'] ?? '', 120),
            'headline_register' => $this->sanitizeText($input['headline_register'] ?? '', 120),
            'headline_forgot' => $this->sanitizeText($input['headline_forgot'] ?? '', 120),
            'subheadline_login' => $this->sanitizeTextarea($input['subheadline_login'] ?? '', 220),
            'subheadline_register' => $this->sanitizeTextarea($input['subheadline_register'] ?? '', 220),
            'subheadline_forgot' => $this->sanitizeTextarea($input['subheadline_forgot'] ?? '', 220),
            'footer_note' => $this->sanitizeText($input['footer_note'] ?? '', 120),
            'login_label_identifier' => $this->sanitizeText($input['login_label_identifier'] ?? '', 120),
            'login_label_password' => $this->sanitizeText($input['login_label_password'] ?? '', 120),
            'login_button_text' => $this->sanitizeText($input['login_button_text'] ?? '', 80),
            'login_forgot_link_text' => $this->sanitizeText($input['login_forgot_link_text'] ?? '', 80),
            'login_identifier_placeholder' => $this->sanitizeText($input['login_identifier_placeholder'] ?? '', 120),
            'login_password_placeholder' => $this->sanitizeText($input['login_password_placeholder'] ?? '', 120),
            'login_remember_label' => $this->sanitizeText($input['login_remember_label'] ?? '', 120),
            'login_show_remember' => !empty($input['login_show_remember']) ? '1' : '0',
            'login_show_passkey' => !empty($input['login_show_passkey']) ? '1' : '0',
            'login_passkey_button_text' => $this->sanitizeText($input['login_passkey_button_text'] ?? '', 120),
            'register_label_email' => $this->sanitizeText($input['register_label_email'] ?? '', 120),
            'register_label_username' => $this->sanitizeText($input['register_label_username'] ?? '', 120),
            'register_label_password' => $this->sanitizeText($input['register_label_password'] ?? '', 120),
            'register_label_password_confirm' => $this->sanitizeText($input['register_label_password_confirm'] ?? '', 120),
            'register_button_text' => $this->sanitizeText($input['register_button_text'] ?? '', 80),
            'register_email_placeholder' => $this->sanitizeText($input['register_email_placeholder'] ?? '', 120),
            'register_username_placeholder' => $this->sanitizeText($input['register_username_placeholder'] ?? '', 120),
            'register_require_terms' => !empty($input['register_require_terms']) ? '1' : '0',
            'register_terms_label' => $this->sanitizeTextarea($input['register_terms_label'] ?? '', 220),
            'register_disabled_message' => $this->sanitizeTextarea($input['register_disabled_message'] ?? '', 220),
            'forgot_label_email' => $this->sanitizeText($input['forgot_label_email'] ?? '', 120),
            'forgot_email_placeholder' => $this->sanitizeText($input['forgot_email_placeholder'] ?? '', 120),
            'forgot_request_button_text' => $this->sanitizeText($input['forgot_request_button_text'] ?? '', 80),
            'forgot_reset_button_text' => $this->sanitizeText($input['forgot_reset_button_text'] ?? '', 80),
            'forgot_done_button_text' => $this->sanitizeText($input['forgot_done_button_text'] ?? '', 80),
            'forgot_request_success_message' => $this->sanitizeTextarea($input['forgot_request_success_message'] ?? '', 220),
            'forgot_reset_success_message' => $this->sanitizeTextarea($input['forgot_reset_success_message'] ?? '', 220),
            'footer_link_login' => $this->sanitizeText($input['footer_link_login'] ?? '', 80),
            'footer_link_register' => $this->sanitizeText($input['footer_link_register'] ?? '', 80),
            'footer_link_forgot' => $this->sanitizeText($input['footer_link_forgot'] ?? '', 80),
            'footer_link_home' => $this->sanitizeText($input['footer_link_home'] ?? '', 80),
            'password_reset_expiry_minutes' => (string) max(5, min(1440, (int) ($input['password_reset_expiry_minutes'] ?? 60))),
            'password_reset_email_subject' => $this->sanitizeMultilineText($input['password_reset_email_subject'] ?? '', 180),
            'password_reset_email_body' => $this->sanitizeMultilineText($input['password_reset_email_body'] ?? '', 4000),
            'registration_enabled' => !empty($input['registration_enabled']) ? '1' : '0',
            'member_registration_enabled' => !empty($input['member_registration_enabled']) ? '1' : '0',
            'privacy_page_id' => $this->sanitizeExistingId($input['privacy_page_id'] ?? 0, $publishedPageIds),
            'terms_page_id' => $this->sanitizeExistingId($input['terms_page_id'] ?? 0, $publishedPageIds),
            'imprint_page_id' => $this->sanitizeExistingId($input['imprint_page_id'] ?? 0, $publishedPageIds),
        ];
    }

    private function renderTemplateText(string $template, array $variables): string
    {
        $template = str_replace(["\r\n", "\r"], "\n", $template);

        return strtr($template, $variables);
    }

    /** @return array<string, array{url:string,title:string}> */
    private function resolveLegalLinks(array $settings): array
    {
        return [
            'privacy' => $this->resolvePageLink((int) ($settings['privacy_page_id'] ?? 0), 'Datenschutzerklärung'),
            'terms' => $this->resolvePageLink((int) ($settings['terms_page_id'] ?? 0), 'Nutzungsbedingungen'),
            'imprint' => $this->resolvePageLink((int) ($settings['imprint_page_id'] ?? 0), 'Impressum'),
        ];
    }

    /** @return array{url:string,title:string} */
    private function resolvePageLink(int $pageId, string $fallbackTitle): array
    {
        if ($pageId <= 0) {
            return ['url' => '', 'title' => $fallbackTitle];
        }

        try {
            $page = $this->db->get_row(
                "SELECT slug, title FROM {$this->prefix}pages WHERE id = ? AND status = 'published' LIMIT 1",
                [$pageId]
            );

            $slug = trim((string) ($page->slug ?? ''));
            if ($slug === '') {
                return ['url' => '', 'title' => $fallbackTitle];
            }

            $title = trim((string) ($page->title ?? ''));

            return [
                'url' => '/' . ltrim($slug, '/'),
                'title' => $title !== '' ? $title : $fallbackTitle,
            ];
        } catch (\Throwable $e) {
            return ['url' => '', 'title' => $fallbackTitle];
        }
    }

    /** @return array<int, true> */
    private function getPublishedPageIdMap(): array
    {
        $map = [];
        foreach ($this->getPageOptions() as $page) {
            $map[(int) ($page['id'] ?? 0)] = true;
        }

        unset($map[0]);

        return $map;
    }

    private function sanitizeExistingId(mixed $value, array $allowedIds): string
    {
        $id = (int) $value;

        return isset($allowedIds[$id]) ? (string) $id : '0';
    }

    private function upsertSetting(string $key, string $value): bool
    {
        $exists = (int) ($this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?",
            [$key]
        ) ?? 0);

        if ($exists > 0) {
            return $this->db->update('settings', ['option_value' => $value], ['option_name' => $key]);
        }

        $insertId = $this->db->insert('settings', ['option_name' => $key, 'option_value' => $value]);

        return is_int($insertId) && $insertId > 0;
    }

    private function sanitizeText(mixed $value, int $maxLength): string
    {
        $text = trim(strip_tags((string) $value));
        if (function_exists('mb_substr')) {
            return (string) mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function sanitizeTextarea(mixed $value, int $maxLength): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
        if (function_exists('mb_substr')) {
            return (string) mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function sanitizeMultilineText(mixed $value, int $maxLength): string
    {
        $text = (string) $value;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $text) ?? '';
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if (function_exists('mb_substr')) {
            return (string) mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function sanitizeUrl(mixed $value): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $url) === 1 || preg_match('#^(?:javascript|data|vbscript):#i', $url) === 1) {
            return '';
        }

        if (str_starts_with($url, '//')) {
            return '';
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            $relativePath = preg_replace('#^(?:\./)+#', '', str_replace('\\', '/', $url)) ?? '';
            $relativePath = ltrim($relativePath, '/');
            if ($relativePath === '' || str_contains($relativePath, '..')) {
                return '';
            }

            return '/' . $relativePath;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    private function sanitizeColor(mixed $value, string $fallback): string
    {
        $color = trim((string) $value);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
            return strtolower($color);
        }

        return $fallback;
    }

    private function sanitizeEnum(string $value, array $allowedValues, string $fallback): string
    {
        return in_array($value, $allowedValues, true) ? $value : $fallback;
    }

    private function normalizeHtmlLang(string $locale): string
    {
        $normalizedLocale = strtolower(str_replace('_', '-', trim($locale)));
        if ($normalizedLocale === '') {
            return 'de';
        }

        return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i', $normalizedLocale) === 1
            ? $normalizedLocale
            : 'de';
    }

    private function normalizePageKey(string $page): string
    {
        $normalizedPage = trim(strtolower(str_replace('_', '-', $page)));

        return array_key_exists($normalizedPage, self::AUTH_CANONICAL_PATHS) ? $normalizedPage : 'login';
    }
}
