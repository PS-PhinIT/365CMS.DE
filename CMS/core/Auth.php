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
    private const DEVICE_COOKIE_TTL = 7200;
    private const SESSION_LIFETIME_OPTION_ADMIN = 'perf_session_timeout_admin';
    private const SESSION_LIFETIME_OPTION_MEMBER = 'perf_session_timeout_member';
    private const DEFAULT_SESSION_LIFETIME_ADMIN = 28_800;
    private const DEFAULT_SESSION_LIFETIME_MEMBER = 2_592_000;

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

        $hash = Database::instance()->get_var(
            "SELECT password FROM " . Database::instance()->getPrefix() . "users WHERE id = ? LIMIT 1",
            [$userId]
        );
        $_SESSION['auth_password_fingerprint'] = self::passwordFingerprint((string) $hash);
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

    private static function passwordFingerprint(string $passwordHash): string
    {
        return $passwordHash === '' ? '' : hash_hmac('sha256', $passwordHash, 'cms-session-password-binding');
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
        
        if (!$user) {
            $this->logLoginAttempt($username);
            // H-01: Fehlgeschlagenen Login protokollieren
            AuditLogger::instance()->loginFailed($username);
            if (function_exists('cms_log')) {
                cms_log('notice', "Login fehlgeschlagen: Benutzer '{$username}' nicht gefunden", ['category' => 'auth', 'username' => $username]);
            }
            return 'Ungültige Anmeldedaten.';
        }
        
        if (!$security->verifyPassword($password, $user->password)) {
            $this->logLoginAttempt($username);
            // H-01: Fehlgeschlagenen Login protokollieren
            AuditLogger::instance()->loginFailed($username);
            if (function_exists('cms_log')) {
                cms_log('notice', "Login fehlgeschlagen: Falsches Passwort für '{$username}'", ['category' => 'auth', 'username' => $username]);
            }
            return 'Ungültige Anmeldedaten.';
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
        
        // Check if email exists
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM {$db->getPrefix()}users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()->count > 0) {
            return 'E-Mail-Adresse bereits registriert.';
        }
        
        // Create user
        $userId = $db->insert('users', [
            'username' => $username,
            'email' => $email,
            'password' => $security->hashPassword($password),
            'display_name' => $displayName,
            'role' => $registrationRole,
            'status' => 'active'
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

            Hooks::doAction('user_registered', (int) $userId);
            return true;
        }
        
        return 'Registrierung fehlgeschlagen.';
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
        $displayName = $this->normalizeScalarString($value, 120);
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

        $user = $this->getUserById((int) $payload->sub);
        if ($user === null) {
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
     * Get user by ID
     */
    private function getUserById(int $id): ?object
    {
        $db = Database::instance();
        $stmt = $db->prepare("SELECT id, username, email, display_name, role, status, password FROM {$db->getPrefix()}users WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$id]);

        $user = $stmt->fetch() ?: null;
        if (is_object($user)) {
            // Der Hash selbst verlässt diese Methode nicht.
            $user->password_fingerprint = self::passwordFingerprint((string) ($user->password ?? ''));
            unset($user->password);
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

    /**
     * Prüft ob MFA für den Nutzer aktiv ist.
     * MFA-Status wird in user_meta gespeichert (Key: mfa_enabled).
     */
    public function isMfaEnabled(int $userId): bool
    {
        return $this->getUserMeta($userId, 'mfa_enabled') === '1';
    }

    /**
     * Beginnt den MFA-Einrichtungsflow: erzeugt ein neues TOTP-Secret,
     * speichert es als "pending" (noch nicht aktiv) in user_meta und
     * gibt die Setup-Daten für das Frontend zurück.
     *
     * @return array{secret: string, qr_url: string, otp_uri: string, qr_data_uri: string}
     */
    public function setupMfaSecret(int $userId): array
    {
        // Account-Label: Benutzername oder E-Mail
        $user  = $this->getUserById($userId);
        $label = $user ? $user->email : (string)$userId;

        return \CMS\Auth\MFA\TotpAdapter::instance()->startSetup($userId, $label);
    }

    /**
     * Bestätigt die MFA-Einrichtung: prüft den ersten TOTP-Code gegen das
     * Pending-Secret und aktiviert MFA, wenn der Code korrekt ist.
     */
    public function confirmMfaSetup(int $userId, string $code): bool
    {
        $pendingSecret = $this->getUserMeta($userId, 'mfa_pending_secret');
        if (!$pendingSecret) {
            return false;
        }

        if (!Totp::instance()->verifyCode($pendingSecret, $code)) {
            return false;
        }

        // MFA aktivieren: Active-Secret setzen + Pending-Secret löschen
        $this->setUserMeta($userId, 'mfa_secret', $pendingSecret);
        $this->setUserMeta($userId, 'mfa_enabled', '1');
        $this->deleteUserMeta($userId, 'mfa_pending_secret');

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'mfa_enabled',
            'MFA (TOTP) aktiviert.',
            'user',
            $userId,
            [],
            'info'
        );

        return true;
    }

    /**
     * Verifiziert einen TOTP-Code gegen das gespeicherte Secret.
     * Wird beim Login-Challenge aufgerufen.
     */
    public function verifyMfaCode(int $userId, string $code): bool
    {
        $secret = $this->getUserMeta($userId, 'mfa_secret');
        if (!$secret) {
            return false;
        }

        return Totp::instance()->verifyCode($secret, $code);
    }

    /**
     * Deaktiviert MFA für einen Nutzer und löscht alle MFA-Meta-Keys.
     */
    public function disableMfa(int $userId): void
    {
        $this->deleteUserMeta($userId, 'mfa_secret');
        $this->deleteUserMeta($userId, 'mfa_enabled');
        $this->deleteUserMeta($userId, 'mfa_pending_secret');

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'mfa_disabled',
            'MFA (TOTP) deaktiviert.',
            'user',
            $userId,
            [],
            'warning'
        );
    }

    /**
     * Liest einen einzelnen user_meta-Wert.
     */
    private function getUserMeta(int $userId, string $key): ?string
    {
        $db   = Database::instance();
        $stmt = $db->prepare(
            "SELECT meta_value FROM {$db->getPrefix()}user_meta WHERE user_id = ? AND meta_key = ? LIMIT 1"
        );
        $stmt->execute([$userId, $key]);
        $row = $stmt->fetch();
        return $row ? (string)$row->meta_value : null;
    }

    /**
     * Setzt (INSERT OR UPDATE) einen user_meta-Wert.
     */
    private function setUserMeta(int $userId, string $key, string $value): void
    {
        $db = Database::instance();
        $db->execute(
            "INSERT INTO {$db->getPrefix()}user_meta (user_id, meta_key, meta_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            [$userId, $key, $value]
        );
    }

    /**
     * Löscht einen user_meta-Eintrag.
     */
    private function deleteUserMeta(int $userId, string $key): void
    {
        $db = Database::instance();
        $db->execute(
            "DELETE FROM {$db->getPrefix()}user_meta WHERE user_id = ? AND meta_key = ?",
            [$userId, $key]
        );
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
        $passwordHash = (string) ($user->password ?? '');
        if ($passwordHash === '') {
            $passwordHash = (string) Database::instance()->get_var(
                "SELECT password FROM " . Database::instance()->getPrefix() . "users WHERE id = ? LIMIT 1",
                [(int) $user->id]
            );
        }
        unset($user->password, $user->password_fingerprint);

        session_regenerate_id(true);
        $_SESSION['auth_password_fingerprint'] = self::passwordFingerprint($passwordHash);
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
