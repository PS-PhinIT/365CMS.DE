<?php
/**
 * AuthManager – Zentraler Authentifizierungs-Dispatcher
 *
 * Koordiniert die verschiedenen Auth-Provider:
 *  - Session-basiert (Standard via CMS\Auth)
 *  - Passkey / WebAuthn (via WebAuthnAdapter)
 *  - LDAP (via LdapAuthProvider)
 *  - MFA: TOTP + Backup-Codes
 *  - JWT für API-Authentifizierung (via JwtService)
 *
 * @package CMSv2\Core\Auth
 */

declare(strict_types=1);

namespace CMS\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Auth\MFA\TotpAdapter;
use CMS\Auth\MFA\BackupCodesManager;
use CMS\Auth\Passkey\WebAuthnAdapter;
use CMS\Auth\LDAP\LdapAuthProvider;
use CMS\AuditLogger;
use CMS\Database;
use CMS\Security;

final class AuthManager
{
    /** Gültigkeit einer ausstehenden Zwei-Faktor-Prüfung nach dem Passwort-Login (Sekunden). */
    private const MFA_PENDING_TTL = 600;
    /** Fehlversuche je Passwort-Login, danach muss das Passwort erneut eingegeben werden. */
    private const MFA_MAX_ATTEMPTS_PER_LOGIN = 5;
    /** Fehlversuche je IP im Zeitfenster MFA_IP_WINDOW. */
    private const MFA_MAX_ATTEMPTS_PER_IP = 20;
    private const MFA_IP_WINDOW = 900;

    private static ?self $instance = null;

    private Auth $sessionAuth;
    private ?WebAuthnAdapter $passkeyAdapter = null;
    private ?LdapAuthProvider $ldapProvider = null;
    private ?TotpAdapter $totpAdapter = null;
    private ?BackupCodesManager $backupCodes = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->sessionAuth = Auth::instance();
    }

    // ── Provider-Zugriff (Lazy Loading) ──────────────────────────────────────

    public function passkey(): WebAuthnAdapter
    {
        return $this->passkeyAdapter ??= WebAuthnAdapter::instance();
    }

    public function ldap(): LdapAuthProvider
    {
        return $this->ldapProvider ??= LdapAuthProvider::instance();
    }

    public function totp(): TotpAdapter
    {
        return $this->totpAdapter ??= TotpAdapter::instance();
    }

    public function backupCodes(): BackupCodesManager
    {
        return $this->backupCodes ??= BackupCodesManager::instance();
    }

    // ── Standard-Login ───────────────────────────────────────────────────────

    /**
     * Standard-Login über Benutzername/Passwort.
     * Delegiert an CMS\Auth::login() – gibt true, 'MFA_REQUIRED' oder Fehlertext zurück.
     */
    public function login(string $username, string $password): bool|string
    {
        return $this->sessionAuth->login($username, $password);
    }

    // ── Passkey-Login ────────────────────────────────────────────────────────

    /**
     * Passkey-Challenge-Optionen generieren (für navigator.credentials.get).
     *
     * @param int|null $userId  Wenn bekannt, nur Credentials dieses Users
     * @return array{options: \stdClass, challenge: string}
     */
    public function getPasskeyLoginOptions(?int $userId = null): array
    {
        $adapter = $this->passkey();
        $credentialIds = $userId !== null
            ? $adapter->getCredentialIdsForUser($userId)
            : [];

        return $adapter->getAuthenticationOptions($credentialIds);
    }

    /**
     * Passkey-Antwort validieren und Session starten.
     *
     * @param string $clientDataJSON      Base64-dekodiert vom Client
     * @param string $authenticatorData   Base64-dekodiert vom Client
     * @param string $signature           Base64-dekodiert vom Client
     * @param string $credentialId        Credential-ID (Base64url)
     * @param string $challenge           Gespeicherte Challenge (hex)
     * @return bool|string true bei Erfolg, Fehlertext sonst
     */
    public function authenticateViaPasskey(
        string $clientDataJSON,
        string $authenticatorData,
        string $signature,
        string $credentialId,
        string $challenge
    ): bool|string {
        $adapter = $this->passkey();

        $credential = $adapter->findCredentialById($credentialId);
        if ($credential === null) {
            return 'Passkey nicht gefunden.';
        }

        $result = $adapter->processAuthentication(
            $clientDataJSON,
            $authenticatorData,
            $signature,
            $credential['public_key'],
            $challenge,
            (int)$credential['sign_count']
        );

        if ($result !== true) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'passkey_login_failed',
                'Passkey-Login fehlgeschlagen: ' . $result,
                'user',
                (int)$credential['user_id']
            );
            return $result;
        }

        // Sign-Counter aktualisieren
        $newCounter = $adapter->getLastSignatureCounter();
        if ($newCounter !== null) {
            $adapter->updateSignCount((int)$credential['id'], $newCounter);
        }

        // Session starten (ohne Passwort-Check, da Passkey verifiziert)
        if (!$this->sessionAuth->completeLoginForUserId((int)$credential['user_id'])) {
            return 'Passkey-Anmeldung konnte nicht abgeschlossen werden.';
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'passkey_login_success',
            'Passkey-Login erfolgreich.',
            'user',
            (int)$credential['user_id']
        );

        return true;
    }

    // ── LDAP-Login ───────────────────────────────────────────────────────────

    /**
     * Authentifizierung über LDAP-Server.
     * Bei Erfolg wird der Nutzer in der lokalen DB angelegt/synchronisiert
     * und eine Session gestartet.
     *
     * @return bool|string true bei Erfolg, Fehlertext sonst
     */
    public function authenticateViaLdap(string $username, string $password): bool|string
    {
        if (!$this->isLdapEnabled()) {
            return 'LDAP ist nicht konfiguriert.';
        }

        $ldap = $this->ldap();

        $ldapUser = $ldap->authenticate($username, $password);
        if ($ldapUser === null) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'ldap_login_failed',
                'LDAP-Login fehlgeschlagen für: ' . $username,
                'ip'
            );
            return 'LDAP-Anmeldung fehlgeschlagen.';
        }

        // Lokalen Benutzer anlegen oder synchronisieren
        $userId = $ldap->syncLocalUser($ldapUser);
        if ($userId === null) {
            return 'Lokale Benutzersynchronisation fehlgeschlagen.';
        }

        // MFA-Check
        if ($this->sessionAuth->isMfaEnabled($userId)) {
            $_SESSION['mfa_pending_user_id'] = $userId;
            $_SESSION['mfa_pending_remember'] = '0';
            $_SESSION['mfa_pending_since'] = time();
            $_SESSION['mfa_failed_attempts'] = 0;
            return 'MFA_REQUIRED';
        }

        if (!$this->sessionAuth->completeLoginForUserId($userId)) {
            return 'LDAP-Anmeldung konnte nicht abgeschlossen werden.';
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'ldap_login_success',
            'LDAP-Login erfolgreich für: ' . $username,
            'user',
            $userId
        );

        return true;
    }

    // ── MFA-Verifizierung ────────────────────────────────────────────────────

    /**
     * MFA-Code verifizieren (TOTP oder Backup-Code).
     * Muss nach einem Login mit 'MFA_REQUIRED'-Antwort aufgerufen werden.
     *
     * @return bool|string true bei Erfolg, Fehlertext sonst
     */
    public function verifyMfa(string $code): bool|string
    {
        $userId = (int)($_SESSION['mfa_pending_user_id'] ?? 0);
        if ($userId === 0) {
            return 'Keine MFA-Verifizierung ausstehend.';
        }

        // Ausstehende Zwei-Faktor-Prüfung läuft nach 10 Minuten ab.
        $pendingSince = (int)($_SESSION['mfa_pending_since'] ?? 0);
        if ($pendingSince <= 0 || $pendingSince < time() - self::MFA_PENDING_TTL) {
            $this->clearMfaPending();
            return 'Die Zwei-Faktor-Prüfung ist abgelaufen. Bitte erneut anmelden.';
        }

        $clientIp = Security::getClientIp();
        if (!Security::checkDbRateLimit($clientIp, 'mfa_verify', self::MFA_MAX_ATTEMPTS_PER_IP, self::MFA_IP_WINDOW)) {
            return 'Zu viele Versuche. Bitte in einigen Minuten erneut versuchen.';
        }

        // Zuerst TOTP prüfen (ein bereits verwendeter Code wird nicht erneut akzeptiert)
        if (preg_match('/^\d{6}$/', $code) === 1 && $this->totp()->verifyCode($userId, $code) && $this->claimTotpCode($userId, $code)) {
            $remember = (string)($_SESSION['mfa_pending_remember'] ?? '') === '1';
            if ($this->sessionAuth->completeLoginForUserId($userId, $remember)) {
                return true;
            }

            $this->clearMfaPending();
            return 'Anmeldung konnte nicht abgeschlossen werden.';
        }

        // Dann Backup-Code prüfen
        if ($this->backupCodes()->verify($userId, $code)) {
            $remember = (string)($_SESSION['mfa_pending_remember'] ?? '') === '1';
            if (!$this->sessionAuth->completeLoginForUserId($userId, $remember)) {
                $this->clearMfaPending();
                return 'Anmeldung konnte nicht abgeschlossen werden.';
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'mfa_backup_code_used',
                'Backup-Code verwendet. Verbleibend: ' . $this->backupCodes()->getRemainingCount($userId),
                'user',
                $userId,
                [],
                'warning'
            );

            return true;
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'mfa_verification_failed',
            'MFA-Verifizierung fehlgeschlagen.',
            'user',
            $userId,
            [],
            'warning'
        );

        Security::recordDbRateLimitAttempt($clientIp, 'mfa_verify', 'user:' . $userId);
        $failedAttempts = (int)($_SESSION['mfa_failed_attempts'] ?? 0) + 1;
        $_SESSION['mfa_failed_attempts'] = $failedAttempts;
        if ($failedAttempts >= self::MFA_MAX_ATTEMPTS_PER_LOGIN) {
            // Nach mehreren Fehlversuchen muss das Passwort erneut eingegeben werden
            // (dort greift das Login-Rate-Limit).
            $this->clearMfaPending();
            return 'Zu viele ungültige Codes. Bitte erneut anmelden.';
        }

        return 'Ungültiger Code.';
    }

    private function clearMfaPending(): void
    {
        unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_remember'], $_SESSION['mfa_pending_since'], $_SESSION['mfa_failed_attempts']);
    }

    /**
     * Merkt sich einen akzeptierten TOTP-Code für die Dauer des Prüffensters (±1 Schritt),
     * damit ein mitgelesener Code nicht erneut verwendet werden kann.
     */
    private function claimTotpCode(int $userId, string $code): bool
    {
        try {
            $db = Database::instance();
            $hash = hash_hmac('sha256', $userId . ':' . $code, defined('AUTH_KEY') ? (string)AUTH_KEY : 'cms-totp');
            $stored = (string)($db->get_var(
                "SELECT meta_value FROM {$db->getPrefix()}user_meta WHERE user_id = ? AND meta_key = 'mfa_last_totp' LIMIT 1",
                [$userId]
            ) ?? '');

            if ($stored !== '' && str_contains($stored, '|')) {
                [$storedHash, $storedAt] = explode('|', $stored, 2);
                if (hash_equals($storedHash, $hash) && (int)$storedAt > time() - 120) {
                    return false;
                }
            }

            $db->execute(
                "INSERT INTO {$db->getPrefix()}user_meta (user_id, meta_key, meta_value)
                 VALUES (?, 'mfa_last_totp', ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                [$userId, $hash . '|' . time()]
            );
        } catch (\Throwable) {
            // Ohne Speicher keine Replay-Sperre, Anmeldung aber nicht blockieren.
        }

        return true;
    }

    // ── Provider-Status ──────────────────────────────────────────────────────

    /**
     * Verfügbare Auth-Provider abfragen.
     *
     * @return array<string, bool>
     */
    public function getAvailableProviders(): array
    {
        return [
            'session'  => true,
            'passkey'  => $this->isPasskeyAvailable(),
            'ldap'     => $this->isLdapEnabled(),
            'totp'     => true,
            'backup'   => true,
        ];
    }

    public function isPasskeyAvailable(): bool
    {
        return function_exists('openssl_open')
            && defined('SITE_URL')
            && !empty(SITE_URL)
            && $this->passkey()->isAvailable();
    }

    public function isLdapEnabled(): bool
    {
        return defined('LDAP_HOST')
            && LDAP_HOST !== ''
            && extension_loaded('ldap');
    }

}
