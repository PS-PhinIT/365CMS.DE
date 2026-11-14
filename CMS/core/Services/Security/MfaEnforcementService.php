<?php
/**
 * Rollenbasierter MFA-Zwang (4.1.0, Bereich 1b) und gemeinsame Konto-Weiche mit dem Passwort-Ablauf (1a).
 *
 * Gehört ein angemeldetes Konto zu einer Rolle aus „MFA erzwingen für Rollen“ und hat es keinen anerkannten zweiten
 * Faktor, lässt die Weiche in `Router::dispatch()` nur die Einrichtung zu (`/mfa-setup`, `/member/security`, Abmelden,
 * statische Assets). Anerkannt sind: aktives TOTP, ein Passkey mit User Verification (`user_verified = 1`) oder – nur
 * für die laufende Sitzung – eine SSO-Anmeldung, deren Token MFA belegt (Sitzungsflag, gesetzt von Bereich 1c).
 *
 * Die Entscheidung selbst (`decide()`) ist eine reine Funktion ohne Seiteneffekte und wird in `TESTS/mfa-enforcement`
 * als Matrix Rolle × Faktor × Pfad geprüft.
 *
 * @package CMSv2\Core\Services\Security
 */

declare(strict_types=1);

namespace CMS\Services\Security;

use CMS\Auth\MFA\TotpAdapter;
use CMS\Auth\Passkey\WebAuthnAdapter;
use CMS\Database;
use CMS\Hooks;
use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class MfaEnforcementService
{
    public const SETTINGS_GROUP = 'mfa_policy';
    public const SETTINGS_KEY_ROLES = 'enforced_roles';

    /** Sitzungsflag: SSO-Anmeldung mit MFA-Nachweis (`amr` enthält `mfa`), gilt nur für diese Sitzung (1c). */
    public const SESSION_SSO_MFA = 'auth_sso_mfa_user_id';

    /** Sitzungsflag aus `Auth::login()` (1a): Passwort abgelaufen. */
    public const SESSION_PASSWORD_CHANGE = 'password_change_required';

    public const SETUP_PATH = '/mfa-setup';
    public const SECURITY_PATH = '/member/security';

    /** Bei Passwort-Ablauf erlaubte Aktionen auf der Sicherheitsseite. */
    private const PASSWORD_ACTIONS = ['password_change'];

    /** Pfade, die vor dem Einrichten erreichbar bleiben (exakt). */
    private const SETUP_ALLOWED_EXACT = [self::SETUP_PATH, self::SECURITY_PATH, '/logout', '/favicon.ico', '/robots.txt'];

    /** Präfixe statischer Assets, die der Router ggf. selbst ausliefert. */
    private const ASSET_PREFIXES = ['/assets/', '/CMS/assets/'];

    private static ?self $instance = null;

    /** @var \Closure(): array<string, mixed> */
    private \Closure $settingsReader;

    /** @var \Closure(int): bool */
    private \Closure $totpReader;

    /** @var \Closure(int): int Anzahl Passkeys mit user_verified = 1 */
    private \Closure $verifiedPasskeyCounter;

    /** @var \Closure(int, int): bool Passkey (DB-ID) des Kontos hat user_verified = 1 */
    private \Closure $passkeyVerifiedReader;

    /** @var array<string, mixed>|null */
    private ?array $settingsCache = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @param array<string, \Closure> $overrides settings, totp, verifiedPasskeys, passkeyVerified (für Tests)
     */
    public function __construct(array $overrides = [])
    {
        $this->settingsReader = $overrides['settings'] ?? static function (): array {
            try {
                return SettingsService::getInstance()->getGroup(self::SETTINGS_GROUP);
            } catch (\Throwable) {
                return [];
            }
        };
        $this->totpReader = $overrides['totp'] ?? static fn(int $userId): bool => TotpAdapter::instance()->isEnabled($userId);
        $this->verifiedPasskeyCounter = $overrides['verifiedPasskeys']
            ?? static fn(int $userId): int => WebAuthnAdapter::instance()->countUserVerifiedCredentials($userId);
        $this->passkeyVerifiedReader = $overrides['passkeyVerified'] ?? static function (int $credentialId, int $userId): bool {
            $db = Database::instance();
            try {
                return (int) $db->get_var(
                    "SELECT user_verified FROM {$db->getPrefix()}passkey_credentials WHERE id = ? AND user_id = ? LIMIT 1",
                    [$credentialId, $userId]
                ) === 1;
            } catch (\Throwable) {
                return false;
            }
        };
    }

    /**
     * Rollen mit MFA-Zwang (bereinigt, ohne Duplikate).
     *
     * @return list<string>
     */
    public function enforcedRoles(): array
    {
        $this->settingsCache ??= ($this->settingsReader)();
        $raw = $this->settingsCache[self::SETTINGS_KEY_ROLES] ?? '[]';
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return self::normalizeRoles(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<mixed> $roles
     * @return list<string>
     */
    public static function normalizeRoles(array $roles): array
    {
        $clean = [];
        foreach ($roles as $role) {
            if (!is_string($role)) {
                continue;
            }
            $role = strtolower(trim($role));
            if ($role !== '' && preg_match('/^[a-z0-9_-]{1,50}$/', $role) === 1) {
                $clean[$role] = true;
            }
        }

        return array_keys($clean);
    }

    /**
     * Speichert die Rollenliste (JSON). Gibt die gespeicherte, bereinigte Liste zurück.
     *
     * @param array<mixed> $roles
     * @param list<string> $knownRoles nur bekannte Rollen werden übernommen
     * @return list<string>
     */
    public static function save(array $roles, array $knownRoles): array
    {
        $roles = array_values(array_intersect(self::normalizeRoles($roles), $knownRoles));
        SettingsService::getInstance()->set(self::SETTINGS_GROUP, self::SETTINGS_KEY_ROLES, (string) json_encode($roles));

        return $roles;
    }

    /**
     * Gilt der MFA-Zwang für dieses Konto? Filter `cms_mfa_enforcement_required` (bool, Benutzerobjekt) kann den
     * Zwang zusätzlich einschalten, aber nicht für eine konfigurierte Rolle aufheben.
     */
    public function isRequiredFor(object $user): bool
    {
        $role = strtolower((string) ($user->role ?? ''));
        $required = $role !== '' && in_array($role, $this->enforcedRoles(), true);
        if ($required) {
            return true;
        }

        return (bool) Hooks::applyFilters('cms_mfa_enforcement_required', false, $user);
    }

    /**
     * Anerkannter zweiter Faktor vorhanden? (TOTP, Passkey mit UV, SSO-MFA dieser Sitzung)
     */
    public function hasRecognisedFactor(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if ((int) ($_SESSION[self::SESSION_SSO_MFA] ?? 0) === $userId) {
            return true;
        }

        return ($this->totpReader)($userId) || ($this->verifiedPasskeyCounter)($userId) > 0;
    }

    /**
     * Darf ein Faktor entfernt werden? Bei Zwang nur, wenn danach noch ein dauerhafter Faktor bleibt
     * (das SSO-Sitzungsflag zählt hier bewusst nicht).
     *
     * @param 'totp'|'passkey' $factor
     */
    public function canRemoveFactor(object $user, string $factor, int $credentialId = 0): bool
    {
        if (!$this->isRequiredFor($user)) {
            return true;
        }
        $userId = (int) ($user->id ?? 0);
        $verifiedPasskeys = ($this->verifiedPasskeyCounter)($userId);

        if ($factor === 'totp') {
            return $verifiedPasskeys > 0;
        }

        $removesVerified = $credentialId > 0 && ($this->passkeyVerifiedReader)($credentialId, $userId);

        return ($this->totpReader)($userId) || ($verifiedPasskeys - ($removesVerified ? 1 : 0)) > 0;
    }

    /**
     * Selbstaussperr-Schutz beim Speichern: Ist die eigene Rolle betroffen und fehlt ein Faktor, wird abgelehnt.
     *
     * @param list<string> $newRoles
     */
    public static function wouldLockOut(array $newRoles, string $ownRole, bool $ownHasFactor): bool
    {
        return !$ownHasFactor && in_array(strtolower($ownRole), self::normalizeRoles($newRoles), true);
    }

    /**
     * Entscheidung der Konto-Weiche – rein, ohne Seiteneffekte.
     *
     * @param array{
     *   logged_in:bool, bearer?:bool, path:string, method?:string, action?:string, api?:bool,
     *   password_change_required?:bool, mfa_required?:bool, has_factor?:bool
     * } $context
     * @return array{gate:string, type:string, target?:string, status?:int, error?:string}|null null = durchlassen
     */
    public static function decide(array $context): ?array
    {
        if (empty($context['logged_in']) || !empty($context['bearer'])) {
            // Nicht angemeldet: Login-Wege entscheiden selbst. Bearer-Token (API-Clients) haben keine Sitzung zum Einrichten.
            return null;
        }

        $path = self::normalizePath((string) ($context['path'] ?? '/'));
        $method = strtoupper((string) ($context['method'] ?? 'GET'));
        $isApi = !empty($context['api']) || $path === '/api' || str_starts_with($path, '/api/');

        if ($path === '/logout' || self::isAssetPath($path)) {
            return null;
        }

        if (!empty($context['password_change_required'])) {
            $allowed = $path === self::SECURITY_PATH
                && ($method === 'GET' || $method === 'HEAD'
                    || in_array((string) ($context['action'] ?? ''), self::PASSWORD_ACTIONS, true));
            if (!$allowed) {
                return self::block('password', self::SECURITY_PATH, 'password_change_required', $isApi);
            }

            return null;
        }

        if (!empty($context['mfa_required']) && empty($context['has_factor'])) {
            if (in_array($path, self::SETUP_ALLOWED_EXACT, true)) {
                return null;
            }

            return self::block('mfa', self::SETUP_PATH, 'mfa_setup_required', $isApi);
        }

        return null;
    }

    /**
     * @return array{gate:string, type:string, target?:string, status?:int, error?:string}
     */
    private static function block(string $gate, string $target, string $error, bool $isApi): array
    {
        return $isApi
            ? ['gate' => $gate, 'type' => 'json', 'status' => 403, 'error' => $error]
            : ['gate' => $gate, 'type' => 'redirect', 'target' => $target];
    }

    private static function normalizePath(string $path): string
    {
        $path = (string) (parse_url($path, PHP_URL_PATH) ?? '/');
        $path = '/' . ltrim($path, '/');

        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    private static function isAssetPath(string $path): bool
    {
        if (str_contains($path, '..')) {
            return false;
        }
        foreach (self::ASSET_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
