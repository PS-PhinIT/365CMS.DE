<?php
/**
 * Ablauf der Entra-ID-Anmeldung (4.1.0, Bereich 1c): Start, Callback, Verknüpfen und Trennen.
 *
 * Callback (nach dem Rücksprung über `SsoRouter`):
 * Rate-Limit `sso_callback` je IP → `state` einlösen (einmalig, 10 min) → Code gegen ID-Token tauschen (PKCE) →
 * ID-Token prüfen → Konto zuordnen (`IdentityLinker`) → Sitzung über `Auth::completeLoginForUserId()` (neue
 * Session-ID). `amr` mit MFA-Nachweis zählt für die Sitzung als zweiter Faktor (Bereich 1b); ohne Nachweis und mit
 * lokalem TOTP folgt die lokale Code-Abfrage wie beim Passwort-Login.
 *
 * Gespeichert werden keine Tokens, nur `tid`/`oid`, Zeitpunkte und für die Sitzung das MFA-Ergebnis.
 *
 * @package CMSv2\Core\Auth\SSO
 */

declare(strict_types=1);

namespace CMS\Auth\SSO;

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Hooks;
use CMS\Security;
use CMS\Services\Security\MfaEnforcementService;
use CMS\Services\UserService;

if (!defined('ABSPATH')) {
    exit;
}

final class EntraSsoService
{
    /** Sitzung: Konto, das sich per SSO angemeldet hat, und Zeitpunkt (Re-Auth „frische SSO-Anmeldung“, §3.4). */
    public const SESSION_SSO_USER = 'auth_sso_user_id';
    public const SESSION_SSO_AT = 'auth_sso_at';
    public const FRESH_SECONDS = 600;

    public const RATE_LIMIT_ACTION = 'sso_callback';
    public const RATE_LIMIT_MAX = 20;
    public const RATE_LIMIT_WINDOW = 900;

    public const MESSAGES = [
        'generic' => 'Die Anmeldung mit Microsoft ist fehlgeschlagen. Bitte erneut versuchen.',
        'unavailable' => 'Die Anmeldung mit Microsoft ist derzeit nicht verfügbar.',
        'state' => 'Die Anmeldung mit Microsoft ist abgelaufen oder ungültig. Bitte erneut starten.',
        'cancelled' => 'Die Anmeldung mit Microsoft wurde abgebrochen.',
        'not_linked' => 'Mit diesem Microsoft-Konto ist kein Konto verknüpft. Melde dich mit deinem Passwort an und verknüpfe Microsoft unter „Sicherheit“.',
        'account_inactive' => 'Ihr Konto ist deaktiviert.',
        'rate_limited' => 'Zu viele Anmeldeversuche. Bitte in einigen Minuten erneut versuchen.',
        'provision_failed' => 'Das Konto konnte nicht angelegt werden. Bitte wende dich an die Administration.',
        'linked' => 'Dein Konto ist jetzt mit Microsoft verknüpft.',
        'already_linked' => 'Dieses Microsoft-Konto ist bereits mit deinem Konto verknüpft.',
        'identity_taken' => 'Dieses Microsoft-Konto ist bereits mit einem anderen Konto verknüpft.',
        'user_has_identity' => 'Dein Konto ist bereits mit einem anderen Microsoft-Konto verknüpft. Trenne die Verknüpfung zuerst.',
        'link_session' => 'Bitte melde dich erneut an und starte die Verknüpfung noch einmal.',
    ];

    private static ?self $instance = null;

    /** @var array<string, mixed>|null */
    private ?array $settings = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        if ($this->settings === null) {
            $roles = [];
            try {
                $roles = array_map('strval', array_keys(UserService::getInstance()->getAvailableRoles()));
            } catch (\Throwable) {
            }
            $this->settings = EntraSettings::load($roles !== [] ? $roles : null);
        }

        return $this->settings;
    }

    public function isAvailable(): bool
    {
        return extension_loaded('openssl') && EntraSettings::isReady($this->settings());
    }

    public function provider(): EntraIdProvider
    {
        return new EntraIdProvider($this->settings() + ['client_secret' => EntraSettings::clientSecret()]);
    }

    /**
     * Startet einen Fluss und liefert die Autorisierungs-URL bei Microsoft.
     */
    public function startUrl(string $purpose, string $redirect, int $linkUserId = 0): string
    {
        $flow = (new OidcFlowState())->begin($purpose, $redirect, $linkUserId);
        $extra = $purpose === OidcFlowState::PURPOSE_LINK ? ['prompt' => 'select_account'] : [];

        return $this->provider()->authorizationUrl($flow['state'], $flow['nonce'], $flow['code_challenge'], EntraSettings::redirectUri(), $extra);
    }

    /**
     * Verarbeitet den Rücksprung von Microsoft.
     *
     * @param array<string, mixed> $query
     * @return array{type:string, purpose:string, target:string, message:string, status?:int, alert?:string}
     *   type: `login` (Sitzung steht), `mfa` (lokale Code-Abfrage), `linked`, `error`
     */
    public function handleCallback(array $query): array
    {
        $ip = Security::getClientIp();
        if (!Security::checkDbRateLimit($ip, self::RATE_LIMIT_ACTION, self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW)) {
            $this->audit('sso_login_blocked', 'SSO-Callback: Rate-Limit erreicht.', null, ['ip' => $ip], 'warning');

            return $this->error('login', 'rate_limited', 429);
        }

        $flowState = new OidcFlowState();
        if (!$flowState->hasPending()) {
            // Kein eigener Start in dieser Sitzung (z. B. fremd eingebettete Callback-URL): weder zählen noch
            // protokollieren – sonst könnte eine fremde Seite das Limit einer geteilten IP ausschöpfen.
            return $this->error('login', 'state', 400);
        }
        $consumed = $flowState->consume($query['state'] ?? null);
        if (!$consumed['ok']) {
            $this->fail('sso_state_invalid', 'SSO-Callback ohne gültigen state.', null, ['reason' => $consumed['reason']]);

            return $this->error('login', 'state', 400);
        }
        $flow = $consumed['flow'];
        $purpose = $flow['purpose'];

        if (!$this->isAvailable() || $flow['provider'] !== EntraSettings::PROVIDER) {
            return $this->error($purpose, 'unavailable', 503);
        }

        if (isset($query['error'])) {
            $error = preg_match('/^[a-z_]{1,60}$/', (string) $query['error']) === 1 ? (string) $query['error'] : 'error';
            $this->fail('sso_login_failed', 'Entra-Anmeldung von Microsoft abgelehnt oder abgebrochen.', null, ['error' => $error]);

            return $this->error($purpose, $error === 'access_denied' ? 'cancelled' : 'generic', 400);
        }

        $provider = $this->provider();
        $exchange = $provider->exchangeCode((string) ($query['code'] ?? ''), $flow['verifier'], EntraSettings::redirectUri());
        if (!$exchange['ok']) {
            $this->fail('sso_login_failed', 'Token-Abruf bei Entra fehlgeschlagen.', null, [
                'error' => (string) ($exchange['error'] ?? ''),
                'description' => (string) ($exchange['description'] ?? ''),
            ]);

            return $this->error($purpose, 'generic', 400);
        }

        $verified = $provider->verifyIdToken((string) $exchange['id_token'], $flow['nonce']);
        if (!$verified['ok']) {
            $this->fail('sso_token_invalid', 'ID-Token von Entra abgelehnt.', null, ['error' => (string) ($verified['error'] ?? '')], 'warning');

            return $this->error($purpose, 'generic', 400);
        }
        $claims = (array) $verified['claims'];
        $settings = $this->settings();
        $linker = new IdentityLinker(new DatabaseIdentityStore());

        if ($purpose === OidcFlowState::PURPOSE_LINK) {
            return $this->finishLink($linker, $claims, $flow);
        }

        $mfaRoles = [];
        try {
            $mfaRoles = MfaEnforcementService::getInstance()->enforcedRoles();
        } catch (\Throwable) {
        }
        $knownRoles = array_map('strval', array_keys(UserService::getInstance()->getAvailableRoles()));
        $resolved = $linker->resolveLogin($claims, $settings, $mfaRoles, $knownRoles);

        if ($resolved['status'] !== 'login') {
            $reason = (string) ($resolved['reason'] ?? 'not_linked');
            $this->fail(
                $reason === 'link_conflict' ? 'sso_link_conflict' : 'sso_login_failed',
                $reason === 'link_conflict'
                    ? 'Entra-Anmeldung: lokales Konto mit gleicher Adresse, keine automatische Verknüpfung.'
                    : 'Entra-Anmeldung ohne zugeordnetes Konto.',
                isset($resolved['user_id']) ? (int) $resolved['user_id'] : null,
                ['reason' => $reason, 'tid' => (string) $claims['tid'], 'oid' => (string) $claims['oid']],
                'warning'
            );
            $message = match ($reason) {
                'account_inactive' => 'account_inactive',
                'provision_failed' => 'provision_failed',
                default => 'not_linked',
            };

            return $this->error('login', $message, 403);
        }

        return $this->finishLogin($claims, $resolved, $flow, $settings);
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $flow
     * @param array<string, mixed> $settings
     * @return array{type:string, purpose:string, target:string, message:string}
     */
    private function finishLogin(array $claims, array $resolved, array $flow, array $settings): array
    {
        $userId = (int) $resolved['user_id'];
        $auth = Auth::instance();
        $store = new DatabaseIdentityStore();
        $publicClaims = EntraIdProvider::publicClaims($claims);
        $mfaProof = !empty($settings['mfa_from_amr']) && EntraIdProvider::mfaFromClaims($claims);

        if ((int) ($resolved['identity_id'] ?? 0) > 0) {
            $store->touchIdentity((int) $resolved['identity_id']);
        }
        $this->reportProvisioning($userId, $resolved, $publicClaims);

        // Ohne MFA-Nachweis aus Entra: lokales TOTP wie beim Passwort-Login abfragen.
        if (!$mfaProof && $auth->isMfaEnabled($userId)) {
            $_SESSION['mfa_pending_user_id'] = $userId;
            $_SESSION['mfa_pending_remember'] = '0';
            $_SESSION['mfa_pending_since'] = time();
            $_SESSION['mfa_failed_attempts'] = 0;
            // Wie beim Passwort-Login: Standardziel für Admins ist das Dashboard.
            $pendingTarget = (string) $flow['redirect'];
            if ($pendingTarget === '/member' && (string) ($store->findUser($userId)->role ?? '') === 'admin') {
                $pendingTarget = '/admin';
            }
            $_SESSION['auth_redirect_after_login'] = $pendingTarget;
            $this->audit('sso_login_mfa_pending', 'Entra-Anmeldung ohne MFA-Nachweis: lokale Code-Abfrage.', $userId);

            return ['type' => 'mfa', 'purpose' => 'login', 'target' => '/mfa-challenge', 'message' => ''];
        }

        if (!$auth->completeLoginForUserId($userId)) {
            $this->fail('sso_login_failed', 'Entra-Anmeldung konnte nicht abgeschlossen werden.', $userId, ['reason' => 'session']);

            return $this->error('login', 'account_inactive', 403);
        }

        // SSO-Anmeldungen setzen kein Passwort-Ablauf-Flag (1a); MFA-Nachweis nur für diese Sitzung (1b).
        unset($_SESSION[MfaEnforcementService::SESSION_PASSWORD_CHANGE]);
        if ($mfaProof) {
            $_SESSION[MfaEnforcementService::SESSION_SSO_MFA] = $userId;
        }
        $_SESSION[self::SESSION_SSO_USER] = $userId;
        $_SESSION[self::SESSION_SSO_AT] = time();

        $this->audit('sso_login_success', 'Entra-Anmeldung erfolgreich.', $userId, [
            'linked' => (string) ($resolved['linked'] ?? 'existing'),
            'mfa' => $mfaProof,
            'tid' => (string) $claims['tid'],
        ]);
        Hooks::doAction('cms_sso_login', $userId, EntraSettings::PROVIDER, $publicClaims);

        return ['type' => 'login', 'purpose' => 'login', 'target' => $flow['redirect'], 'message' => ''];
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $publicClaims
     */
    private function reportProvisioning(int $userId, array $resolved, array $publicClaims): void
    {
        if (($resolved['linked'] ?? '') === 'provisioned') {
            $this->audit('sso_user_provisioned', 'Konto über Entra-Anmeldung angelegt.', $userId, ['tid' => (string) ($publicClaims['tid'] ?? '')]);
            Hooks::doAction('cms_sso_user_provisioned', $userId, EntraSettings::PROVIDER, $publicClaims);
        }
        if (($resolved['linked'] ?? '') === 'auto') {
            $this->audit('sso_linked', 'Konto automatisch mit Entra verknüpft (bestätigte Adresse, erlaubte Domain).', $userId, ['mode' => 'auto']);
        }
        if (isset($resolved['role_change']) && is_array($resolved['role_change'])) {
            $from = (string) $resolved['role_change']['from'];
            $to = (string) $resolved['role_change']['to'];
            $this->audit('sso_role_changed', 'Rolle über Entra-Mapping geändert.', $userId, ['from' => $from, 'to' => $to], 'warning');
            Hooks::doAction('user_role_changed', $userId, $from, $to, 'sso');
        }
        if (!empty($resolved['overage'])) {
            $this->audit('sso_groups_overage', 'Entra liefert zu viele Gruppen (Overage); nur App-Rollen werden ausgewertet.', $userId, [], 'warning');
        }
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $flow
     * @return array{type:string, purpose:string, target:string, message:string, alert?:string}
     */
    private function finishLink(IdentityLinker $linker, array $claims, array $flow): array
    {
        $current = Auth::instance()->currentUser();
        $userId = (int) ($current->id ?? 0);
        if ($userId <= 0 || $userId !== (int) $flow['user_id']) {
            $this->fail('sso_link_conflict', 'Verknüpfung: Sitzung passt nicht zum gestarteten Vorgang.', $userId > 0 ? $userId : null, ['reason' => 'session'], 'warning');

            return $this->error('link', 'link_session', 403);
        }

        $result = $linker->link($claims, $userId);
        if ($result['status'] === 'rejected') {
            $reason = (string) ($result['reason'] ?? 'identity_taken');
            $this->audit('sso_link_conflict', 'Verknüpfung mit Entra abgelehnt.', $userId, ['reason' => $reason, 'tid' => (string) $claims['tid']], 'warning');

            return $this->error('link', isset(self::MESSAGES[$reason]) ? $reason : 'identity_taken', 409);
        }
        if ($result['status'] === 'linked') {
            $this->audit('sso_linked', 'Konto aktiv mit Entra verknüpft.', $userId, ['mode' => 'manual', 'tid' => (string) $claims['tid']]);
        }

        return [
            'type' => 'linked',
            'purpose' => 'link',
            'target' => '/member/security',
            'message' => self::MESSAGES[$result['status'] === 'linked' ? 'linked' : 'already_linked'],
            'alert' => $result['status'] === 'linked' ? 'success' : 'info',
        ];
    }

    /**
     * @return array{id:int,user_id:int,tenant_id:string,subject:string,provisioned:bool,email_at_link:string,linked_at:string,last_login_at:string}|null
     */
    public function identityForUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            return (new DatabaseIdentityStore())->findIdentityForUser($userId, EntraSettings::PROVIDER);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isLinked(int $userId): bool
    {
        return $this->identityForUser($userId) !== null;
    }

    public function unlink(int $userId): bool
    {
        try {
            $removed = (new DatabaseIdentityStore())->deleteIdentity($userId, EntraSettings::PROVIDER);
        } catch (\Throwable) {
            return false;
        }
        if ($removed) {
            $this->audit('sso_unlinked', 'Verknüpfung mit Entra getrennt.', $userId);
        }

        return $removed;
    }

    /**
     * Frische SSO-Anmeldung dieses Kontos in dieser Sitzung (≤ 10 min) – ersetzt bei SSO-Sitzungen das Passwort
     * als Re-Auth beim Hinzufügen eines zweiten Faktors (01-IDENTITY §3.4).
     */
    public static function isFreshSsoSession(int $userId, ?int $now = null): bool
    {
        $at = (int) ($_SESSION[self::SESSION_SSO_AT] ?? 0);

        return $userId > 0
            && (int) ($_SESSION[self::SESSION_SSO_USER] ?? 0) === $userId
            && $at > 0
            && $at >= ($now ?? time()) - self::FRESH_SECONDS;
    }

    /**
     * @return array{type:string, purpose:string, target:string, message:string, reason:string, status:int}
     */
    private function error(string $purpose, string $messageKey, int $status): array
    {
        return [
            'type' => 'error',
            'purpose' => $purpose === OidcFlowState::PURPOSE_LINK ? 'link' : 'login',
            'target' => $purpose === OidcFlowState::PURPOSE_LINK ? '/member/security' : '',
            'message' => self::MESSAGES[$messageKey] ?? self::MESSAGES['generic'],
            'reason' => $messageKey,
            'status' => $status,
        ];
    }

    /**
     * Fehlschlag protokollieren und für das Rate-Limit zählen.
     *
     * @param array<string, mixed> $details
     */
    private function fail(string $action, string $message, ?int $userId, array $details = [], string $severity = 'info'): void
    {
        Security::recordDbRateLimitAttempt(Security::getClientIp(), self::RATE_LIMIT_ACTION, 'sso');
        $this->audit($action, $message, $userId, $details, $severity);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function audit(string $action, string $message, ?int $userId, array $details = [], string $severity = 'info'): void
    {
        try {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                $action,
                $message,
                $userId !== null ? 'user' : 'ip',
                $userId,
                $details,
                $severity
            );
        } catch (\Throwable) {
        }
    }
}
