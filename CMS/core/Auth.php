<?php
/**
 * Authentication Class
 * 
 * Handles user login, registration, session management
 * 
 * @package CMSv2\Core
 */

declare(strict_types=1);
namespace CMS;

use CMS\Auth\MFA\TotpAdapter;
use CMS\Services\EmailVerificationService;
use CMS\Services\UserService;

if (!defined('ABSPATH')) {
    exit;
}

class Auth
{
    private const PASSWORD_POLICY_MIN_LENGTH = 12;
    private const PASSWORD_POLICY_PATTERNS = [
        'uppercase' => '/[A-Z]/',
        'lowercase' => '/[a-z]/',
        'digit' => '/\d/',
        'special' => '/[^a-zA-Z0-9]/',
    ];

    private const DEVICE_COOKIE_NAME = 'cms_device';
    /** bcrypt-Hash (Kosten wie Security::hashPassword) eines festen Werts – nur für Timing-Angleichung. */
    private const LOGIN_TIMING_DUMMY_HASH = '$2y$12$Ka0ROJ/q3DghtbJ2sUBc9eIT4fFu2DpKB2mzGWtsGrJL/X7ZZmwDe';
    private const DEVICE_COOKIE_TTL = 7200;
    private const SESSION_LIFETIME_OPTION_ADMIN = 'perf_session_timeout_admin';
    private const SESSION_LIFETIME_OPTION_MEMBER = 'perf_session_timeout_member';
    private const DEFAULT_SESSION_LIFETIME_ADMIN = 28_800;
    private const DEFAULT_SESSION_LIFETIME_MEMBER = 2_592_000;
    /** user_meta-Schlüssel der Token-Version: erhöhen beendet alle Sitzungen und API-Tokens. */
    public const TOKEN_VERSION_META_KEY = 'auth_token_version';
    /** login_attempts.action für Fehlversuche je Konto (Folgeaudit OFFEN-25). */
    private const ACCOUNT_FAILURE_ACTION = 'login_account';
    private const ACCOUNT_FAILURE_WINDOW = 900;
    private const ACCOUNT_FAILURE_FREE_ATTEMPTS = 5;
    private const ACCOUNT_FAILURE_MAX_DELAY = 30;
    private const REGISTRATION_NOTICE_LIMIT = 3;
    private const REGISTRATION_NOTICE_WINDOW = 3600;
    private const USERNAME_MIN_LENGTH = 3;
    private const USERNAME_MAX_LENGTH = 50;
    private const EMAIL_MAX_LENGTH = 100;
    private const DISPLAY_NAME_MAX_LENGTH = 100;

    private static ?self $instance = null;
    private ?object $currentUser = null;

    /** true, wenn der aktuelle Request per JWT-Bearer-Token (statt Session) angemeldet ist */
    private bool $bearerAuthenticated = false;
    /** @var array{admin:int,member:int}|null */
    private ?array $sessionLifetimes = null;
    
    /**
     * Singleton instance
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->checkSession();
    }
    
    /**
     * Check if user is logged in via session
     * M-17: Session-Lifetime nach Rolle prüfen.
     */
    private function checkSession(): void
    {
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->forceExpireSession();
            return;
        }

        // M-17: Maximale Lebensdauer je Rolle
        $role = (string)($_SESSION['user_role'] ?? 'member');
        $maxLifetime = $this->getConfiguredSessionLifetime($role);

        $sessionStart = (int)($_SESSION['session_start_time'] ?? 0);

        if ($sessionStart === 0 || (time() - $sessionStart) > $maxLifetime) {
            // Session abgelaufen oder kein Startzeit-Stempel → sauber beenden
            $this->forceExpireSession();
            return;
        }

        if (!$this->hasValidDeviceCookie($userId)) {
            $this->bindCurrentSessionToDeviceCookie($userId);
        }

        $this->currentUser = $this->getUserById((int)$_SESSION['user_id']);
        if ($this->currentUser === null) {
            $this->forceExpireSession();
            return;
        }

        // Passwortwechsel (Reset, Profil, Admin) beendet alle anderen Sitzungen: Wer eine
        // gestohlene Session hält, bleibt sonst auch nach dem Zurücksetzen angemeldet.
        $fingerprint = (string) ($this->currentUser->password_fingerprint ?? '');
        $sessionFingerprint = (string) ($_SESSION['auth_password_fingerprint'] ?? '');
        if ($sessionFingerprint === '') {
            $_SESSION['auth_password_fingerprint'] = $fingerprint;
        } elseif ($fingerprint !== '' && !hash_equals($sessionFingerprint, $fingerprint)) {
            $this->forceExpireSession();
        }
        unset($this->currentUser->password_fingerprint);

        if ($this->currentUser !== null) {
            $this->touchSessionRegistry((int) $userId, $maxLifetime, $sessionStart);
        }
    }

    /**
     * Pflegt die Tabelle `sessions` (Mitgliederbereich „Aktive Sessions“, Dashboard-Statistik).
     * Gespeichert wird nur ein Hash der Session-ID, nie die ID selbst; aktualisiert höchstens
     * alle fünf Minuten, um Schreibzugriffe pro Request zu vermeiden.
     */
    private function touchSessionRegistry(int $userId, int $lifetime, int $sessionStart, bool $force = false): void
    {
        if ($userId <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $lastTouch = (int) ($_SESSION['session_registry_touched_at'] ?? 0);
        if (!$force && $lastTouch > 0 && (time() - $lastTouch) < 300) {
            return;
        }

        try {
            $db = Database::instance();
            $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $db->execute(
                "REPLACE INTO {$db->getPrefix()}sessions (id, user_id, ip_address, user_agent, payload, last_activity, expires_at)
                 VALUES (?, ?, ?, ?, NULL, ?, ?)",
                [
                    self::sessionRegistryId(),
                    $userId,
                    substr(Security::getClientIp(), 0, 45),
                    $userAgent,
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s', ($sessionStart > 0 ? $sessionStart : time()) + max(300, $lifetime)),
                ]
            );
            $_SESSION['session_registry_touched_at'] = time();
        } catch (\Throwable) {
            // Sitzungsverwaltung ist Komfort; Anmeldung darf daran nicht scheitern.
        }
    }

    private function removeSessionRegistryEntry(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
            return;
        }

        try {
            $db = Database::instance();
            $db->execute("DELETE FROM {$db->getPrefix()}sessions WHERE id = ?", [self::sessionRegistryId()]);
        } catch (\Throwable) {
        }
    }

    private static function sessionRegistryId(): string
    {
        return hash('sha256', session_id());
    }

    /**
     * Nach einer Passwortänderung durch den angemeldeten Benutzer selbst aufrufen, damit nur
     * die anderen Sitzungen enden und die aktuelle gültig bleibt.
     */
    public function refreshPasswordFingerprint(int $userId): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || (int) ($_SESSION['user_id'] ?? 0) !== $userId) {
            return;
        }

        $_SESSION['auth_password_fingerprint'] = $this->loadPasswordFingerprint($userId);
        $this->removeSessionRegistryEntry();
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
        try {
            // Andere Sitzungen sind mit dem neuen Passwort ungültig – Einträge entfernen.
            Database::instance()->execute(
                "DELETE FROM " . Database::instance()->getPrefix() . "sessions WHERE user_id = ?",
                [$userId]
            );
        } catch (\Throwable) {
        }
        $this->touchSessionRegistry(
            $userId,
            $this->getConfiguredSessionLifetime((string) ($_SESSION['user_role'] ?? 'member')),
            (int) ($_SESSION['session_start_time'] ?? time()),
            true
        );
    }

    /**
     * Beendet alle Sitzungen und macht alle API-Tokens (Access und Refresh) des Kontos ungültig,
     * ohne das Passwort zu ändern (Folgeaudit OFFEN-23). Gehört die aktuelle Sitzung zu diesem
     * Konto, bleibt sie angemeldet.
     */
    public function revokeAllSessions(int $userId, string $initiator = 'self'): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();

        try {
            $current = $db->get_var(
                "SELECT meta_value FROM {$prefix}user_meta WHERE user_id = ? AND meta_key = ? LIMIT 1",
                [$userId, self::TOKEN_VERSION_META_KEY]
            );
            $db->execute(
                "INSERT INTO {$prefix}user_meta (user_id, meta_key, meta_value) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                [$userId, self::TOKEN_VERSION_META_KEY, (string) (self::normalizeTokenVersion($current) + 1)]
            );
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('auth')->error('Sitzungen konnten nicht widerrufen werden.', [
                'user_id' => $userId,
                'exception' => $e::class,
            ]);

            return false;
        }

        try {
            $db->execute("DELETE FROM {$prefix}sessions WHERE user_id = ?", [$userId]);
        } catch (\Throwable) {
        }

        // Die eigene Sitzung auf die neue Token-Version heben, alle anderen enden beim nächsten Request.
        $this->refreshPasswordFingerprint($userId);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'sessions_revoked',
            'Alle Sitzungen und API-Tokens des Kontos wurden widerrufen.',
            'user',
            $userId,
            ['initiator' => $initiator],
            'warning'
        );
        Hooks::doAction('user_sessions_revoked', $userId, $initiator);

        return true;
    }

    /**
     * Bindungswert für Sitzungen und API-Tokens: Passwort-Hash plus Token-Version. Version 0
     * entspricht der Formel vor 4.0.0, damit bestehende Sitzungen das Update überstehen.
     */
    private static function passwordFingerprint(string $passwordHash, int $tokenVersion = 0): string
    {
        if ($passwordHash === '') {
            return '';
        }

        $material = $tokenVersion > 0 ? $passwordHash . '|token-version:' . $tokenVersion : $passwordHash;

        return hash_hmac('sha256', $material, 'cms-session-password-binding');
    }

    private static function normalizeTokenVersion(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function loadPasswordFingerprint(int $userId): string
    {
        $db = Database::instance();
        $prefix = $db->getPrefix();
        $row = $db->get_row(
            "SELECT u.password, tv.meta_value AS token_version
             FROM {$prefix}users u
             LEFT JOIN {$prefix}user_meta tv ON tv.user_id = u.id AND tv.meta_key = ?
             WHERE u.id = ? LIMIT 1",
            [self::TOKEN_VERSION_META_KEY, $userId]
        );

        return is_object($row)
            ? self::passwordFingerprint((string) ($row->password ?? ''), self::normalizeTokenVersion($row->token_version ?? null))
            : '';
    }

    /**
     * M-17: Session ohne Redirect sauber invalidieren
     * (kein header()-Aufruf – checkSession läuft im Konstruktor).
     * Nur aufrufen wenn session_start() bereits erfolgt ist.
     */
    private function forceExpireSession(): void
    {
        $this->clearDeviceCookie();
        $this->removeSessionRegistryEntry();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->currentUser = null;
            return;
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        $this->currentUser = null;
    }
    
    /**
     * Login user
     */
    public function login(string $username, string $password, bool $remember = false): bool|string
    {
        $security = Security::instance();
        $db = Database::instance();
        
        // H-05: DB-basiertes Rate-Limiting (schützt auch gegen verteilte Brute-Force)
        if (!$security->checkDbRateLimit($security->getClientIp(), 'login', MAX_LOGIN_ATTEMPTS, LOGIN_TIMEOUT)) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'login_blocked',
                'Brute-Force-Sperre für IP: ' . $security->getClientIp(),
                'ip',
                null,
                ['ip' => $security->getClientIp()],
                'warning'
            );
            return 'Zu viele Login-Versuche. Bitte warten Sie ' . (LOGIN_TIMEOUT / 60) . ' Minuten.';
        }
        
        $username = $this->normalizeLoginIdentifier($username);
        if ($username === '' || $password === '') {
            return 'Ungültige Anmeldedaten.';
        }
        
        $stmt = $db->prepare("SELECT id, username, email, password, display_name, role, status FROM {$db->getPrefix()}users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetchObject();

        // Folgeaudit OFFEN-25: zusätzlich zum IP-Limit wachsende Verzögerung je Konto. Keine harte
        // Sperre, damit Dritte ein Konto nicht aussperren können; unbekannte Namen verhalten sich gleich.
        $accountKey = $user ? 'uid:' . (int) $user->id : 'login:' . mb_strtolower($username, 'UTF-8');
        $this->delayRepeatedAccountFailures($accountKey, $username);
        
        if (!$user) {
            // Gleiche Laufzeit wie bei falschem Passwort, damit Benutzernamen nicht per Timing erkennbar sind.
            $security->verifyPassword($password, self::LOGIN_TIMING_DUMMY_HASH);
            $this->logLoginAttempt($username);
            $this->recordAccountLoginFailure($accountKey);
            // H-01: Fehlgeschlagenen Login protokollieren
            AuditLogger::instance()->loginFailed($username);
            if (function_exists('cms_log')) {
                cms_log('notice', "Login fehlgeschlagen: Benutzer '{$username}' nicht gefunden", ['category' => 'auth', 'username' => $username]);
            }
            return 'Ungültige Anmeldedaten.';
        }
        
        if (!$security->verifyPassword($password, $user->password)) {
            $this->logLoginAttempt($username);
            $this->recordAccountLoginFailure($accountKey);
            // H-01: Fehlgeschlagenen Login protokollieren
            AuditLogger::instance()->loginFailed($username);
            if (function_exists('cms_log')) {
                cms_log('notice', "Login fehlgeschlagen: Falsches Passwort für '{$username}'", ['category' => 'auth', 'username' => $username]);
            }
            return 'Ungültige Anmeldedaten.';
        }

        $this->clearAccountLoginFailures($accountKey);
        
        if ($user->status === EmailVerificationService::STATUS_UNVERIFIED) {
            // Erst nach korrektem Passwort erreichbar – verrät also nichts über fremde Konten.
            $resent = false;
            try {
                $resent = EmailVerificationService::getInstance()->resend((int) $user->id);
            } catch (\Throwable) {
            }

            return $resent
                ? 'Ihre E-Mail-Adresse ist noch nicht bestätigt. Wir haben Ihnen soeben einen neuen Bestätigungslink gesendet.'
                : 'Ihre E-Mail-Adresse ist noch nicht bestätigt. Bitte nutzen Sie den Link aus unserer Bestätigungs-E-Mail (auch im Spam-Ordner nachsehen).';
        }

        if ($user->status !== 'active') {
            return 'Ihr Konto ist deaktiviert.';
        }

        // H-02: MFA-Check – wenn TOTP aktiv, Pending-Session setzen statt direkt einloggen
        if ($this->isMfaEnabled((int)$user->id)) {
            $_SESSION['mfa_pending_user_id'] = (int)$user->id;
            $_SESSION['mfa_pending_remember'] = $remember ? '1' : '0';
            $_SESSION['mfa_pending_since'] = time();
            $_SESSION['mfa_failed_attempts'] = 0;
            return 'MFA_REQUIRED';
        }

        $this->completeAuthenticatedSession($user, $remember);

        // H-01: Erfolgreichen Login protokollieren
        AuditLogger::instance()->loginSuccess($username);
        
        return true;
    }

    /**
     * Check Capability (Advanced Support)
     */
    public function hasCapability(string $cap): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        $role = $this->currentUser->role;
        $capability = function_exists('cms_normalize_role_capability')
            ? cms_normalize_role_capability($cap)
            : strtolower(trim($cap));

        if ($capability === '') {
            return false;
        }

        // Admin has all caps
        if ($role === 'admin') {
            return true;
        }

        if (function_exists('cms_load_role_capabilities')) {
            $capabilities = cms_load_role_capabilities((string) $role);
            return !empty($capabilities[$capability]);
        }

        $db = Database::instance();

        try {
            $granted = $db->get_var(
                "SELECT granted FROM {$db->getPrefix()}role_permissions WHERE role = ? AND capability = ? LIMIT 1",
                [$role, $capability]
            );

            return $granted !== null ? (bool) $granted : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get Current User
     */
    public function currentUser(): ?object
    {
        return $this->currentUser;
    }

    public function completeLoginForUserId(int $userId, bool $remember = false): bool
    {
        $user = $this->getUserById($userId);
        if ($user === null) {
            return false;
        }

        $this->completeAuthenticatedSession($user, $remember);
        return true;
    }
    
    /**
     * Register new user
     */
    public function register(array $data): bool|string
    {
        $security = Security::instance();
        $db = Database::instance();
        $registrationRole = $this->resolveRegistrationRole($db);
        $username = $this->normalizeRegistrationUsername($data['username'] ?? '');
        $email = $security->sanitize($this->normalizeScalarString($data['email'] ?? '', 254), 'email');
        $password = $this->normalizeScalarString($data['password'] ?? '', 4096);
        $displayName = $this->normalizeDisplayName($data['display_name'] ?? $username, $username);
        
        // Validate required fields
        if ($username === '') {
            return "Feld 'username' ist erforderlich.";
        }

        if (strlen($username) < self::USERNAME_MIN_LENGTH || strlen($username) > self::USERNAME_MAX_LENGTH) {
            return 'Der Benutzername muss ' . self::USERNAME_MIN_LENGTH . ' bis ' . self::USERNAME_MAX_LENGTH
                . ' Zeichen lang sein (Buchstaben, Zahlen, Unterstrich).';
        }

        if ($email === '') {
            return "Feld 'email' ist erforderlich.";
        }

        if ($password === '') {
            return "Feld 'password' ist erforderlich.";
        }
        
        // Validate email
        if (!$security->validateEmail($email)) {
            return 'Ungültige E-Mail-Adresse.';
        }

        if (strlen($email) > self::EMAIL_MAX_LENGTH) {
            return 'Die E-Mail-Adresse darf höchstens ' . self::EMAIL_MAX_LENGTH . ' Zeichen lang sein.';
        }

        // M-18: Passwort-Policy prüfen (min. 12 Zeichen, Komplexität)
        $policyResult = self::validatePasswordPolicy($password);
        if ($policyResult !== true) {
            return $policyResult;
        }

        // Check if username exists
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM {$db->getPrefix()}users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()->count > 0) {
            return 'Benutzername bereits vergeben.';
        }
        
        // Folgeaudit OFFEN-24: Eine bereits registrierte Adresse wird nicht verraten. Der Inhaber
        // erhält einen Hinweis, die Antwort gleicht einer erfolgreichen Registrierung.
        $existingUserId = (int) $db->get_var(
            "SELECT id FROM {$db->getPrefix()}users WHERE email = ? LIMIT 1",
            [$email]
        );
        if ($existingUserId > 0) {
            // Gleicher Aufwand wie bei einer echten Registrierung (Passwort-Hash).
            $security->hashPassword($password);
            $this->notifyExistingAccountOfRegistration($existingUserId, $email);

            return true;
        }
        
        $requiresVerification = $this->isEmailVerificationRequired();

        // Create user
        $userId = $db->insert('users', [
            'username' => $username,
            'email' => $email,
            'password' => $security->hashPassword($password),
            'display_name' => $displayName,
            'role' => $registrationRole,
            'status' => $requiresVerification ? EmailVerificationService::STATUS_UNVERIFIED : 'active'
        ]);
        
        if ($userId) {
            try {
                SubscriptionManager::instance()->assignConfiguredDefaultPlan((int) $userId);
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('auth.register')->warning(
                    'Standardpaket konnte nach Registrierung nicht automatisch zugewiesen werden.',
                    [
                        'user_id' => (int) $userId,
                        'exception' => $e::class,
                    ]
                );
            }

            if ($requiresVerification) {
                try {
                    EmailVerificationService::getInstance()->sendVerificationMail((int) $userId);
                } catch (\Throwable $e) {
                    Logger::instance()->withChannel('auth.register')->warning(
                        'Bestätigungs-E-Mail konnte nach Registrierung nicht versendet werden.',
                        [
                            'user_id' => (int) $userId,
                            'exception' => $e::class,
                        ]
                    );
                }
            }

            Hooks::doAction('user_registered', (int) $userId);
            return true;
        }
        
        return 'Registrierung fehlgeschlagen.';
    }

    /**
     * true, wenn neue Registrierungen ihre E-Mail-Adresse bestätigen müssen
     * (Mitglieder → Einstellungen: „E-Mail-Verifizierung für neue Mitglieder erzwingen“).
     */
    public function isEmailVerificationRequired(): bool
    {
        try {
            return EmailVerificationService::getInstance()->isRequired();
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveRegistrationRole(Database $db): string
    {
        try {
            $configuredRole = (string)$db->get_var(
                "SELECT option_value FROM {$db->getPrefix()}settings WHERE option_name = ? LIMIT 1",
                ['member_default_role']
            );

            return UserService::getInstance()->resolveRegistrationRole($configuredRole);
        } catch (\Throwable) {
            return 'member';
        }
    }

    private function normalizeLoginIdentifier(string $value): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';

        return function_exists('mb_substr') ? mb_substr($value, 0, 254, 'UTF-8') : substr($value, 0, 254);
    }

    private function normalizeRegistrationUsername(mixed $value): string
    {
        $value = $this->normalizeScalarString($value, 80);
        $value = Security::sanitize($value, 'username');

        return $value !== '' ? $value : '';
    }

    private function normalizeDisplayName(mixed $value, string $fallback): string
    {
        $displayName = $this->normalizeScalarString($value, self::DISPLAY_NAME_MAX_LENGTH);
        $displayName = trim(strip_tags($displayName));

        return $displayName !== '' ? $displayName : $fallback;
    }

    private function normalizeScalarString(mixed $value, int $maxLength): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';

        return function_exists('mb_substr') ? mb_substr($value, 0, max(1, $maxLength), 'UTF-8') : substr($value, 0, max(1, $maxLength));
    }

    /**
     * Liefert die technische Passwort-Policy als strukturierte Definition.
     *
     * @return array<string, mixed>
     */
    public static function getPasswordPolicyDefinition(): array
    {
        return [
            'min_length' => self::PASSWORD_POLICY_MIN_LENGTH,
            'patterns' => [
                'uppercase' => '[A-Z]',
                'lowercase' => '[a-z]',
                'digit' => '\\d',
                'special' => '[^a-zA-Z0-9]',
            ],
        ];
    }

    /**
     * Bewertet ein Passwort gegen die aktuelle Runtime-Policy.
     *
     * @return array<string, mixed>
     */
    public static function evaluatePasswordPolicy(string $password): array
    {
        $length = self::countPasswordLength($password);

        $requirements = [
            [
                'key' => 'min_length',
                'label' => 'Mindestens ' . self::PASSWORD_POLICY_MIN_LENGTH . ' Zeichen',
                'message' => 'Das Passwort muss mindestens ' . self::PASSWORD_POLICY_MIN_LENGTH . ' Zeichen lang sein.',
                'passed' => $length >= self::PASSWORD_POLICY_MIN_LENGTH,
            ],
            [
                'key' => 'uppercase',
                'label' => 'Mindestens ein Großbuchstabe',
                'message' => 'Das Passwort muss mindestens einen Großbuchstaben enthalten.',
                'passed' => preg_match(self::PASSWORD_POLICY_PATTERNS['uppercase'], $password) === 1,
            ],
            [
                'key' => 'lowercase',
                'label' => 'Mindestens ein Kleinbuchstabe',
                'message' => 'Das Passwort muss mindestens einen Kleinbuchstaben enthalten.',
                'passed' => preg_match(self::PASSWORD_POLICY_PATTERNS['lowercase'], $password) === 1,
            ],
            [
                'key' => 'digit',
                'label' => 'Mindestens eine Ziffer',
                'message' => 'Das Passwort muss mindestens eine Ziffer (0–9) enthalten.',
                'passed' => preg_match(self::PASSWORD_POLICY_PATTERNS['digit'], $password) === 1,
            ],
            [
                'key' => 'special',
                'label' => 'Mindestens ein Sonderzeichen',
                'message' => 'Das Passwort muss mindestens ein Sonderzeichen enthalten (z. B. !@#$%^&*).',
                'passed' => preg_match(self::PASSWORD_POLICY_PATTERNS['special'], $password) === 1,
            ],
        ];

        $message = null;
        foreach ($requirements as $requirement) {
            if (!empty($requirement['passed'])) {
                continue;
            }

            $message = (string) ($requirement['message'] ?? 'Die Passwort-Policy ist nicht erfüllt.');
            break;
        }

        return [
            'valid' => $message === null,
            'message' => $message,
            'length' => $length,
            'min_length' => self::PASSWORD_POLICY_MIN_LENGTH,
            'requirements' => $requirements,
            'definition' => self::getPasswordPolicyDefinition(),
        ];
    }

    private static function countPasswordLength(string $password): int
    {
        if ($password === '') {
            return 0;
        }

        if (function_exists('mb_strlen')) {
            return mb_strlen($password, 'UTF-8');
        }

        $characterCount = preg_match_all('/./us', $password, $matches);
        if ($characterCount !== false) {
            return $characterCount;
        }

        return strlen($password);
    }

    /**
     * M-18: Passwort-Policy validieren.
     *
     * Anforderungen:
     *  - Mindestlänge: 12 Zeichen
     *  - Mindestens 1 Großbuchstabe (A-Z)
     *  - Mindestens 1 Kleinbuchstabe (a-z)
     *  - Mindestens 1 Ziffer (0-9)
     *  - Mindestens 1 Sonderzeichen (!@#$%^&* etc.)
     *
     * @param  string   $password Das zu prüfende Passwort (Klartext).
     * @return true|string        true wenn gültig, Fehlermeldung sonst.
     */
    public static function validatePasswordPolicy(string $password): true|string
    {
        $evaluation = self::evaluatePasswordPolicy($password);

        return !empty($evaluation['valid'])
            ? true
            : (string) ($evaluation['message'] ?? 'Die Passwort-Policy ist nicht erfüllt.');
    }

    /**
     * Logout user
     */
    public function logout(): void
    {
        $this->clearDeviceCookie();
        $this->removeSessionRegistryEntry();

        // Clear session data
        $_SESSION = [];
        
        // Expire the session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        
        // Destroy session
        session_destroy();
        $this->currentUser = null;
    }

    public function bindCurrentSessionToDeviceCookie(int $userId): void
    {
        if ($userId <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionId = session_id();
        if ($sessionId === '') {
            return;
        }

        $issuedAt = time();
        $expiresAt = $issuedAt + self::DEVICE_COOKIE_TTL;
        $payload = implode('|', [
            (string)$userId,
            (string)$issuedAt,
            (string)$expiresAt,
            hash('sha256', $sessionId),
        ]);
        $signature = hash_hmac('sha256', $payload, $this->getDeviceCookieSigningKey());
        $cookieValue = rtrim(strtr(base64_encode($payload . '|' . $signature), '+/', '-_'), '=');

        $this->setDeviceCookie($cookieValue, $expiresAt);
    }
    
    /**
     * Check if user is logged in (static wrapper)
     */
    public static function isLoggedIn(): bool
    {
        return self::instance()->currentUser !== null;
    }
    
    /**
     * Check if current user has role (static wrapper)
     */
    public static function hasRole(string $role): bool
    {
        $instance = self::instance();
        if (!$instance->currentUser) {
            return false;
        }
        
        return $instance->currentUser->role === $role;
    }
    
    /**
     * Check if current user is admin (static wrapper)
     */
    public static function isAdmin(): bool
    {
        return self::hasRole('admin');
    }
    
    /**
     * Get current user (static wrapper)
     */
    public static function getCurrentUser(): ?object
    {
        return self::instance()->currentUser;
    }
    
    /**
     * Meldet einen API-Request per `Authorization: Bearer <JWT>` an (ohne Session).
     * Nur wenn JWT aktiviert ist, kein Session-Benutzer vorliegt, das Token gültig und kein
     * Refresh-Token ist und der Benutzer aktiv ist.
     */
    public function authenticateBearerToken(): bool
    {
        if ($this->currentUser !== null || !Services\JwtService::isApiAuthEnabled()) {
            return false;
        }

        $token = Services\JwtService::extractBearerToken();
        if ($token === null) {
            return false;
        }

        $payload = Services\JwtService::getInstance()->validateToken($token);
        if ($payload === null || ($payload->type ?? '') === 'refresh' || !isset($payload->sub)) {
            return false;
        }

        if (self::isPreBindingApiToken($payload)) {
            // Tokens von vor 3.5.0 tragen keinen `pwf`-Claim (Folgeaudit SEC-30/OFFEN-19).
            if (!headers_sent()) {
                header('WWW-Authenticate: Bearer error="invalid_token", error_description="' . self::PRE_BINDING_TOKEN_HINT . '"');
            }
            return false;
        }

        $user = $this->getUserById((int) $payload->sub);
        if ($user === null || !self::matchesApiTokenBinding($user, $payload)) {
            return false;
        }

        unset($user->password_fingerprint);
        $this->currentUser = $user;
        $this->bearerAuthenticated = true;

        return true;
    }

    public function isBearerAuthenticated(): bool
    {
        return $this->bearerAuthenticated;
    }

    /**
     * Prüft, ob eine Benutzer-ID zu einem aktiven Konto gehört (für Refresh-Tokens).
     */
    public function isActiveUserId(int $userId): bool
    {
        return $userId > 0 && $this->getUserById($userId) !== null;
    }

    /**
     * Bindungswert (`pwf`-Claim) für API-Tokens: leitet sich aus dem Passwort-Hash ab.
     * Nach Passwortänderung oder -reset passen ausgestellte Access- und Refresh-Tokens
     * nicht mehr und werden abgelehnt. `null`, wenn das Konto nicht aktiv ist.
     */
    public function apiTokenBinding(int $userId): ?string
    {
        $user = $userId > 0 ? $this->getUserById($userId) : null;
        if ($user === null) {
            return null;
        }

        $fingerprint = (string) ($user->password_fingerprint ?? '');

        return $fingerprint === '' ? null : substr($fingerprint, 0, 32);
    }

    /**
     * Prüft ein Refresh-/Access-Token-Payload gegen das aktive Konto (Status + Passwort-Bindung).
     */
    public function isValidApiTokenSubject(object $payload): bool
    {
        $userId = (int) ($payload->sub ?? 0);
        $user = $userId > 0 ? $this->getUserById($userId) : null;

        return $user !== null && self::matchesApiTokenBinding($user, $payload);
    }

    /**
     * Hinweis für API-Clients mit Tokens ohne Passwort-Bindung (ASCII, da auch als HTTP-Header genutzt).
     */
    public const PRE_BINDING_TOKEN_HINT = 'Token issued before 365CMS 3.5.0 without password binding; request a new token via POST /api/v1/auth/token';

    /**
     * Gültig signiertes Token ohne `pwf`-Claim, also vor 3.5.0 ausgestellt.
     */
    public static function isPreBindingApiToken(object $payload): bool
    {
        return !isset($payload->pwf) || !is_string($payload->pwf) || $payload->pwf === '';
    }

    private static function matchesApiTokenBinding(object $user, object $payload): bool
    {
        $expected = substr((string) ($user->password_fingerprint ?? ''), 0, 32);
        $given = is_string($payload->pwf ?? null) ? $payload->pwf : '';

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    /**
     * Get user by ID
     */
    private function getUserById(int $id): ?object
    {
        $db = Database::instance();
        $prefix = $db->getPrefix();
        $stmt = $db->prepare(
            "SELECT u.id, u.username, u.email, u.display_name, u.role, u.status, u.password, tv.meta_value AS auth_token_version
             FROM {$prefix}users u
             LEFT JOIN {$prefix}user_meta tv ON tv.user_id = u.id AND tv.meta_key = ?
             WHERE u.id = ? AND u.status = 'active' LIMIT 1"
        );
        $stmt->execute([self::TOKEN_VERSION_META_KEY, $id]);

        $user = $stmt->fetch() ?: null;
        if (is_object($user)) {
            // Der Hash selbst verlässt diese Methode nicht.
            $user->password_fingerprint = self::passwordFingerprint(
                (string) ($user->password ?? ''),
                self::normalizeTokenVersion($user->auth_token_version ?? null)
            );
            unset($user->password, $user->auth_token_version);
        }

        return $user;
    }
    
    /**
     * Log login attempt (for security tracking)
     *
     * H-05: action-Feld für DB-basiertes Rate-Limiting mitschreiben
     */
    private function logLoginAttempt(string $username, string $action = 'login'): void
    {
        $db = Database::instance();
        $security = Security::instance();
        
        $db->insert('login_attempts', [
            // Auf die Spaltenbreite normalisiert: lange Kennungen ließen das INSERT scheitern.
            'username'     => Security::normalizeRateLimitIdentifier($username),
            'ip_address'   => $security->getClientIp(),
            'action'       => Security::normalizeRateLimitAction($action),
        ]);
    }

    /**
     * Wartezeit vor der Passwortprüfung nach wiederholten Fehlversuchen für ein Konto:
     * die ersten fünf Versuche ohne Verzögerung, danach 1, 2, 4, 8, 16 und höchstens 30 Sekunden.
     */
    public static function accountFailureDelaySeconds(int $failures): int
    {
        if ($failures < self::ACCOUNT_FAILURE_FREE_ATTEMPTS) {
            return 0;
        }

        return min(self::ACCOUNT_FAILURE_MAX_DELAY, 2 ** min(5, $failures - self::ACCOUNT_FAILURE_FREE_ATTEMPTS));
    }

    private function delayRepeatedAccountFailures(string $accountKey, string $loginIdentifier): void
    {
        $failures = Security::countDbIdentifierAttempts($accountKey, self::ACCOUNT_FAILURE_ACTION, self::ACCOUNT_FAILURE_WINDOW);
        $delay = self::accountFailureDelaySeconds($failures);
        if ($delay <= 0) {
            return;
        }

        if (function_exists('cms_log')) {
            cms_log('notice', "Login um {$delay} s verzögert: {$failures} Fehlversuche für '{$loginIdentifier}' in 15 Minuten", [
                'category' => 'auth',
                'username' => $loginIdentifier,
            ]);
        }

        sleep($delay);
    }

    private function recordAccountLoginFailure(string $accountKey): void
    {
        Security::recordDbRateLimitAttempt(Security::getClientIp(), self::ACCOUNT_FAILURE_ACTION, $accountKey);
    }

    private function clearAccountLoginFailures(string $accountKey): void
    {
        try {
            $db = Database::instance();
            $db->execute(
                "DELETE FROM {$db->getPrefix()}login_attempts WHERE username = ? AND action = ?",
                [Security::normalizeRateLimitIdentifier($accountKey), self::ACCOUNT_FAILURE_ACTION]
            );
        } catch (\Throwable) {
        }
    }

    /**
     * Hinweis an den Inhaber einer bereits registrierten Adresse (Folgeaudit OFFEN-24), höchstens
     * REGISTRATION_NOTICE_LIMIT Mails je Adresse und Stunde.
     */
    private function notifyExistingAccountOfRegistration(int $userId, string $email): void
    {
        $identifier = 'regnotice:' . strtolower($email);
        if (!Security::checkDbIdentifierRateLimit($identifier, 'register_existing', self::REGISTRATION_NOTICE_LIMIT, self::REGISTRATION_NOTICE_WINDOW)) {
            return;
        }
        Security::recordDbRateLimitAttempt(Security::getClientIp(), 'register_existing', $identifier);

        $siteName = defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
        $loginUrl = '';
        $forgotUrl = '';
        try {
            $authPages = Services\CmsAuthPageService::getInstance();
            $loginUrl = $authPages->getPublicUrl('login');
            $forgotUrl = $authPages->getPublicUrl('forgot-password');
        } catch (\Throwable) {
        }

        $body = "Hallo,\n\n"
            . "soeben wurde versucht, mit dieser E-Mail-Adresse ein neues Konto bei {$siteName} anzulegen. "
            . "Für diese Adresse besteht bereits ein Konto, deshalb wurde kein weiteres angelegt.\n\n"
            . "Warst du das? Dann melde dich mit deinem bestehenden Konto an"
            . ($loginUrl !== '' ? ":\n{$loginUrl}\n" : ".\n")
            . 'Passwort vergessen? Du kannst es hier zurücksetzen'
            . ($forgotUrl !== '' ? ":\n{$forgotUrl}\n\n" : ".\n\n")
            . "Warst du das nicht, kannst du diese E-Mail ignorieren. An deinem Konto wurde nichts geändert.\n\n"
            . "Viele Grüße\n{$siteName}";

        $sent = false;
        try {
            $sent = function_exists('cms_mail')
                && \cms_mail($email, '[' . $siteName . '] Registrierungsversuch mit deiner E-Mail-Adresse', $body, ['Content-Type' => 'text/plain; charset=UTF-8']);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('auth.register')->warning('Hinweis an bestehendes Konto konnte nicht versendet werden.', [
                'user_id' => $userId,
                'exception' => $e::class,
            ]);
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'register_existing_email',
            'Registrierung mit bereits verwendeter E-Mail-Adresse; Inhaber benachrichtigt.',
            'user',
            $userId,
            ['notice_sent' => $sent],
            'info'
        );
    }

    /**
     * Erneute Bestätigung mit dem aktuellen Passwort für sicherheitskritische Änderungen
     * (MFA deaktivieren, Backup-Codes erneuern, Passkey hinzufügen, E-Mail ändern).
     * Fehlversuche zählen je IP (10 in 15 Minuten), damit eine übernommene Sitzung das
     * Passwort nicht durchprobieren kann.
     */
    public function verifyCurrentPassword(int $userId, string $password): bool|string
    {
        if ($userId <= 0 || $password === '') {
            return 'Bitte das aktuelle Passwort eingeben.';
        }

        $clientIp = Security::getClientIp();
        if (!Security::checkDbRateLimit($clientIp, 'reauth', 10, 900)) {
            return 'Zu viele Fehlversuche. Bitte in einigen Minuten erneut versuchen.';
        }

        $db = Database::instance();
        $hash = (string)($db->get_var("SELECT password FROM {$db->getPrefix()}users WHERE id = ? LIMIT 1", [$userId]) ?? '');
        if ($hash !== '' && Security::verifyPassword($password, $hash)) {
            return true;
        }

        Security::recordDbRateLimitAttempt($clientIp, 'reauth', 'user:' . $userId);
        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'reauth_failed',
            'Passwortbestätigung fehlgeschlagen.',
            'user',
            $userId,
            [],
            'warning'
        );

        return 'Das aktuelle Passwort ist nicht korrekt.';
    }

    // ── H-02: TOTP / MFA ─────────────────────────────────────────────────────
    // Einzige Implementierung ist TotpAdapter (verschlüsselte Secrets, Audit-Log, Backup-Codes).

    /**
     * Prüft ob MFA für den Nutzer aktiv ist (user_meta mfa_enabled).
     */
    public function isMfaEnabled(int $userId): bool
    {
        return TotpAdapter::instance()->isEnabled($userId);
    }

    /**
     * Beginnt den MFA-Einrichtungsflow: erzeugt ein neues TOTP-Secret,
     * speichert es verschlüsselt als "pending" und gibt die Setup-Daten zurück.
     *
     * @return array{secret: string, qr_url: string, otp_uri: string, qr_data_uri: string}
     */
    public function setupMfaSecret(int $userId): array
    {
        $user  = $this->getUserById($userId);
        $label = $user ? $user->email : (string)$userId;

        return TotpAdapter::instance()->startSetup($userId, $label);
    }

    /**
     * Bestätigt die MFA-Einrichtung mit dem ersten TOTP-Code. Ein aktiver Authenticator
     * wird nie überschrieben; mit $expectedSecret nur das in dieser Sitzung angezeigte Secret.
     */
    public function confirmMfaSetup(int $userId, string $code, ?string $expectedSecret = null): bool
    {
        return TotpAdapter::instance()->confirmSetup($userId, $code, $expectedSecret);
    }

    /**
     * Verifiziert einen TOTP-Code gegen das gespeicherte Secret.
     */
    public function verifyMfaCode(int $userId, string $code): bool
    {
        return TotpAdapter::instance()->verifyCode($userId, $code);
    }

    /**
     * Deaktiviert MFA (Secrets, Status und Backup-Codes).
     */
    public function disableMfa(int $userId): void
    {
        TotpAdapter::instance()->disable($userId);
    }

    private function hasValidDeviceCookie(int $userId): bool
    {
        $rawCookie = trim((string)($_COOKIE[self::DEVICE_COOKIE_NAME] ?? ''));
        if ($rawCookie === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $normalizedCookie = strtr($rawCookie, '-_', '+/');
        $padding = strlen($normalizedCookie) % 4;
        if ($padding !== 0) {
            $normalizedCookie .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalizedCookie, true);
        if (!is_string($decoded) || $decoded === '') {
            return false;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 5) {
            return false;
        }

        [$cookieUserId, $issuedAt, $expiresAt, $sessionHash, $signature] = $parts;
        $payload = implode('|', [$cookieUserId, $issuedAt, $expiresAt, $sessionHash]);
        $expectedSignature = hash_hmac('sha256', $payload, $this->getDeviceCookieSigningKey());

        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        $now = time();
        if ((int)$cookieUserId !== $userId || (int)$expiresAt < $now || (int)$issuedAt > $now) {
            return false;
        }

        $sessionId = session_id();
        if ($sessionId === '') {
            return false;
        }

        return hash_equals($sessionHash, hash('sha256', $sessionId));
    }

    private function getDeviceCookieSigningKey(): string
    {
        foreach (['JWT_SECRET', 'SECURE_AUTH_KEY', 'AUTH_KEY'] as $constant) {
            $secret = $this->getConfiguredSecret($constant);
            if ($secret !== '') {
                return $secret;
            }
        }

        return hash('sha256', __FILE__ . PHP_VERSION);
    }

    private function getConfiguredSecret(string $constant): string
    {
        if (!defined($constant)) {
            return '';
        }

        $value = trim((string) constant($constant));
        if ($value === '' || str_contains($value, 'REPLACE_VIA_INSTALLER') || str_contains($value, 'YOUR_')) {
            return '';
        }

        return $value;
    }

    private function setDeviceCookie(string $value, int $expiresAt): void
    {
        $params = session_get_cookie_params();
        $cookieDomain = $this->resolveCookieDomain((string)($params['domain'] ?? ''));
        setcookie(self::DEVICE_COOKIE_NAME, $value, [
            'expires' => $expiresAt,
            'path' => $params['path'] ?? '/',
            'domain' => $cookieDomain,
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => (string)($params['samesite'] ?? 'Strict'),
        ]);
        $_COOKIE[self::DEVICE_COOKIE_NAME] = $value;
    }

    private function clearDeviceCookie(): void
    {
        $params = session_get_cookie_params();
        $cookieDomain = $this->resolveCookieDomain((string)($params['domain'] ?? ''));
        setcookie(self::DEVICE_COOKIE_NAME, '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $cookieDomain,
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => (string)($params['samesite'] ?? 'Strict'),
        ]);
        unset($_COOKIE[self::DEVICE_COOKIE_NAME]);
    }

    private function resolveCookieDomain(string $configuredDomain = ''): string
    {
        $domain = strtolower(trim($configuredDomain));
        if ($domain !== '') {
            return ltrim($domain, '.');
        }

        return '';
    }

    private function refreshSessionCookie(string $role, bool $remember): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || ini_get('session.use_cookies') === '0') {
            return;
        }

        $params = session_get_cookie_params();
        $cookieLifetime = 0;

        if ($remember) {
            $cookieLifetime = $this->getConfiguredSessionLifetime($role);
        }

        setcookie(session_name(), session_id(), [
            'expires' => $cookieLifetime > 0 ? time() + $cookieLifetime : 0,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => (string) ($params['samesite'] ?? 'Strict'),
        ]);
    }

    private function completeAuthenticatedSession(object $user, bool $remember): void
    {
        $fingerprint = $this->loadPasswordFingerprint((int) $user->id);
        unset($user->password, $user->password_fingerprint);

        session_regenerate_id(true);
        $_SESSION['auth_password_fingerprint'] = $fingerprint;
        $_SESSION['user_id'] = $user->id;
        $_SESSION['user_role'] = $user->role;
        $_SESSION['session_start_time'] = time();
        $this->touchSessionRegistry((int) $user->id, $this->getConfiguredSessionLifetime((string) ($user->role ?? 'member')), time(), true);
        unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_remember'], $_SESSION['mfa_pending_since'], $_SESSION['mfa_failed_attempts']);

        $this->refreshSessionCookie((string) ($user->role ?? 'member'), $remember);
        $this->bindCurrentSessionToDeviceCookie((int)$user->id);

        Database::instance()->update('users', ['last_login' => date('Y-m-d H:i:s')], ['id' => $user->id]);
        $this->currentUser = $user;
    }

    private function getConfiguredSessionLifetime(string $role): int
    {
        $lifetimes = $this->getConfiguredSessionLifetimes();

        return $role === 'admin'
            ? $lifetimes['admin']
            : $lifetimes['member'];
    }

    /** @return array{admin:int,member:int} */
    private function getConfiguredSessionLifetimes(): array
    {
        if ($this->sessionLifetimes !== null) {
            return $this->sessionLifetimes;
        }

        $lifetimes = [
            'admin' => self::DEFAULT_SESSION_LIFETIME_ADMIN,
            'member' => self::DEFAULT_SESSION_LIFETIME_MEMBER,
        ];

        try {
            $db = Database::instance();
            $rows = $db->get_results(
                "SELECT option_name, option_value FROM {$db->getPrefix()}settings WHERE option_name IN (?, ?)",
                [self::SESSION_LIFETIME_OPTION_ADMIN, self::SESSION_LIFETIME_OPTION_MEMBER]
            );

            foreach ($rows as $row) {
                $optionName = (string)($row->option_name ?? '');
                $optionValue = (int)($row->option_value ?? 0);

                if ($optionName === self::SESSION_LIFETIME_OPTION_ADMIN) {
                    $lifetimes['admin'] = max(300, min(604800, $optionValue));
                    continue;
                }

                if ($optionName === self::SESSION_LIFETIME_OPTION_MEMBER) {
                    $lifetimes['member'] = max(300, min(31536000, $optionValue));
                }
            }
        } catch (\Throwable) {
        }

        $this->sessionLifetimes = $lifetimes;

        return $this->sessionLifetimes;
    }
}
