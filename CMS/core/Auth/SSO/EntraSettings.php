<?php
/**
 * Konfiguration der Entra-ID-Anmeldung (4.1.0, Bereich 1c) – Einstellungsgruppe `auth_entra`.
 *
 * `normalize()` ist rein (ohne Datenbank) und wird von `load()`, `save()` und `TESTS/sso-entra` genutzt. Das
 * Client-Secret liegt nur verschlüsselt in den Einstellungen (`SettingsService::set(…, encrypted: true)` →
 * `CryptoService`, Bindung an den Optionsnamen) und wird nie ins Formular zurückgegeben.
 *
 * @package CMSv2\Core\Auth\SSO
 */

declare(strict_types=1);

namespace CMS\Auth\SSO;

use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class EntraSettings
{
    public const GROUP = 'auth_entra';
    public const PROVIDER = 'entra';

    public const CALLBACK_PATH = '/cms-login/sso/entra/callback';
    public const START_PATH = '/cms-login/sso/entra/start';

    public const LOCAL_LOGIN_ALL = 'all';
    public const LOCAL_LOGIN_ADMINS_ONLY = 'admins_only';
    public const LOCAL_LOGIN_DISABLED_FOR_LINKED = 'disabled_for_linked';
    public const LOCAL_LOGIN_MODES = [self::LOCAL_LOGIN_ALL, self::LOCAL_LOGIN_ADMINS_ONLY, self::LOCAL_LOGIN_DISABLED_FOR_LINKED];

    /** Wirksamer Modus der lokalen Anmeldung (autoload), für die Prüfung laufender Sitzungen. */
    public const ENFORCED_LOCAL_LOGIN_KEY = 'local_login_enforced';

    /** Feste Scopes: kein Zugriffstoken für Graph, nur Identität. */
    public const SCOPES = 'openid profile email';

    public const MAX_RULES = 50;
    public const MAX_TENANTS = 20;
    public const MAX_DOMAINS = 50;

    private const GUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const DOMAIN_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';
    private const APP_ROLE_PATTERN = '/^[A-Za-z0-9._:-]{1,120}$/';
    private const CMS_ROLE_PATTERN = '/^[a-z0-9_-]{1,50}$/';

    /**
     * @return array{
     *   enabled:bool, tenant_id:string, allowed_tenants:list<string>, client_id:string, client_secret_set:bool,
     *   client_secret_expires:string, auto_link:bool, allowed_domains:list<string>, auto_provision:bool,
     *   default_role:string, role_mapping:list<array{type:string,value:string,role:string}>, sync_roles:bool,
     *   allow_admin_mapping:bool, mfa_from_amr:bool, local_login:string, button_label:string
     * }
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'tenant_id' => '',
            'allowed_tenants' => [],
            'client_id' => '',
            'client_secret_set' => false,
            'client_secret_expires' => '',
            'auto_link' => false,
            'allowed_domains' => [],
            'auto_provision' => false,
            'default_role' => 'member',
            'role_mapping' => [],
            'sync_roles' => false,
            'allow_admin_mapping' => false,
            'mfa_from_amr' => true,
            'local_login' => self::LOCAL_LOGIN_ALL,
            'button_label' => 'Mit Microsoft anmelden',
        ];
    }

    /**
     * Bereinigt Rohwerte (Einstellungen oder Formular). Unbekannte Schlüssel fallen weg, das Secret selbst ist
     * nie Teil des Ergebnisses (nur `client_secret_set`).
     *
     * @param array<string, mixed> $raw
     * @param list<string>|null $knownRoles bekannte CMS-Rollen; null = nur Formatprüfung
     * @return array<string, mixed> Struktur wie defaults()
     */
    public static function normalize(array $raw, ?array $knownRoles = null): array
    {
        $d = self::defaults();
        $bool = static function (mixed $value, bool $default): bool {
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value)) {
                return $value === 1;
            }
            if (is_string($value)) {
                $value = strtolower(trim($value));
                if ($value === '') {
                    return $default;
                }

                return in_array($value, ['1', 'true', 'yes', 'on'], true);
            }

            return $default;
        };
        $roleOk = static fn (string $role): bool => preg_match(self::CMS_ROLE_PATTERN, $role) === 1
            && ($knownRoles === null || in_array($role, $knownRoles, true));

        $tenant = self::normalizeGuid($raw['tenant_id'] ?? '');
        $tenants = [];
        foreach (self::listValue($raw['allowed_tenants'] ?? []) as $candidate) {
            $guid = self::normalizeGuid($candidate);
            if ($guid !== '') {
                $tenants[$guid] = true;
            }
        }
        // Multi-Tenant nur mit Allowlist; der eigene Tenant gehört immer dazu.
        if ($tenants !== [] && $tenant !== '') {
            $tenants = [$tenant => true] + $tenants;
        }

        $domains = [];
        foreach (self::listValue($raw['allowed_domains'] ?? []) as $candidate) {
            $domain = strtolower(trim(ltrim((string) $candidate, '@')));
            if (preg_match(self::DOMAIN_PATTERN, $domain) === 1) {
                $domains[$domain] = true;
            }
        }

        $defaultRole = strtolower(trim((string) ($raw['default_role'] ?? $d['default_role'])));
        // Die Standardrolle ist nie `admin`: Admin nur über eine ausdrückliche Mapping-Regel.
        if ($defaultRole === 'admin' || !$roleOk($defaultRole)) {
            $defaultRole = $knownRoles === null || in_array('member', $knownRoles, true) ? 'member' : ($knownRoles[0] ?? 'member');
            if ($defaultRole === 'admin') {
                $defaultRole = 'member';
            }
        }

        $rules = [];
        $rawRules = $raw['role_mapping'] ?? [];
        if (is_string($rawRules)) {
            $decoded = json_decode($rawRules, true);
            $rawRules = is_array($decoded) ? $decoded : [];
        }
        foreach (is_array($rawRules) ? $rawRules : [] as $rule) {
            if (!is_array($rule) || count($rules) >= self::MAX_RULES) {
                continue;
            }
            $type = (string) ($rule['type'] ?? '');
            $value = trim((string) ($rule['value'] ?? ''));
            $role = strtolower(trim((string) ($rule['role'] ?? '')));
            if ($type === 'group') {
                $value = self::normalizeGuid($value);
            } elseif ($type !== 'role' || preg_match(self::APP_ROLE_PATTERN, $value) !== 1) {
                $value = '';
            }
            if ($value === '' || !$roleOk($role)) {
                continue;
            }
            $rules[] = ['type' => $type, 'value' => $value, 'role' => $role];
        }

        $expires = trim((string) ($raw['client_secret_expires'] ?? ''));
        $expiresDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
        if ($expiresDate === false || $expiresDate->format('Y-m-d') !== $expires) {
            $expires = '';
        }

        $localLogin = (string) ($raw['local_login'] ?? $d['local_login']);
        $label = trim(strip_tags((string) ($raw['button_label'] ?? '')));

        return [
            'enabled' => $bool($raw['enabled'] ?? null, $d['enabled']),
            'tenant_id' => $tenant,
            'allowed_tenants' => array_keys($tenants),
            'client_id' => self::normalizeGuid($raw['client_id'] ?? ''),
            'client_secret_set' => $bool($raw['client_secret_set'] ?? null, false),
            'client_secret_expires' => $expires,
            'auto_link' => $bool($raw['auto_link'] ?? null, $d['auto_link']),
            'allowed_domains' => array_slice(array_keys($domains), 0, self::MAX_DOMAINS),
            'auto_provision' => $bool($raw['auto_provision'] ?? null, $d['auto_provision']),
            'default_role' => $defaultRole,
            'role_mapping' => $rules,
            'sync_roles' => $bool($raw['sync_roles'] ?? null, $d['sync_roles']),
            'allow_admin_mapping' => $bool($raw['allow_admin_mapping'] ?? null, $d['allow_admin_mapping']),
            'mfa_from_amr' => $bool($raw['mfa_from_amr'] ?? null, $d['mfa_from_amr']),
            'local_login' => in_array($localLogin, self::LOCAL_LOGIN_MODES, true) ? $localLogin : $d['local_login'],
            'button_label' => $label !== '' ? mb_substr($label, 0, 60) : $d['button_label'],
        ];
    }

    public static function normalizeGuid(mixed $value): string
    {
        $value = strtolower(trim(is_scalar($value) ? (string) $value : ''));

        return preg_match(self::GUID_PATTERN, $value) === 1 ? $value : '';
    }

    public static function isGuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::GUID_PATTERN, $value) === 1;
    }

    /**
     * @param array<string, mixed> $settings Ergebnis von normalize()/load()
     */
    public static function isMultiTenant(array $settings): bool
    {
        return count((array) ($settings['allowed_tenants'] ?? [])) > 1;
    }

    /**
     * Anmeldung möglich: eingeschaltet, Tenant, Client-ID und Secret vorhanden.
     *
     * @param array<string, mixed> $settings
     */
    public static function isReady(array $settings): bool
    {
        return !empty($settings['enabled'])
            && (string) ($settings['tenant_id'] ?? '') !== ''
            && (string) ($settings['client_id'] ?? '') !== ''
            && !empty($settings['client_secret_set']);
    }

    /**
     * Darf sich ein Konto lokal anmelden – mit Passwort, Passkey oder LDAP, also an Microsoft vorbei? Wirkt nur,
     * solange die Entra-Anmeldung bereitsteht – sonst könnte eine halbe Konfiguration alle aussperren.
     *
     * - `all`: lokale Anmeldung für alle Konten.
     * - `disabled_for_linked`: verknüpfte Konten nur über Microsoft; Admins behalten den Notfallzugang.
     * - `admins_only`: lokale Anmeldung nur noch für Admins (Notfallzugang), alle anderen über Microsoft.
     *
     * @param array<string, mixed> $settings
     */
    public static function localLoginAllowed(array $settings, bool $isAdmin, bool $isLinked): bool
    {
        return self::localLoginAllowedForMode(self::enforcedLocalLogin($settings), $isAdmin, $isLinked);
    }

    /**
     * Wirksamer Modus: der eingestellte, solange die Entra-Anmeldung bereitsteht, sonst `all`.
     *
     * @param array<string, mixed> $settings
     */
    public static function enforcedLocalLogin(array $settings): string
    {
        $mode = (string) ($settings['local_login'] ?? self::LOCAL_LOGIN_ALL);

        return self::isReady($settings) && in_array($mode, self::LOCAL_LOGIN_MODES, true) ? $mode : self::LOCAL_LOGIN_ALL;
    }

    public static function localLoginAllowedForMode(string $mode, bool $isAdmin, bool $isLinked): bool
    {
        if ($isAdmin) {
            return true;
        }

        return match ($mode) {
            self::LOCAL_LOGIN_ADMINS_ONLY => false,
            self::LOCAL_LOGIN_DISABLED_FOR_LINKED => !$isLinked,
            default => true,
        };
    }

    /**
     * Darf eine bestehende Sitzung weiterlaufen? Über Microsoft begonnene Sitzungen ja; lokal begonnene (Passwort,
     * Passkey, LDAP) nur, solange das Konto lokal anmelden dürfte. So enden lokale Sitzungen betroffener Konten mit
     * der nächsten Anfrage, sobald die Sperre eingeschaltet wird. Rein (Sitzung und Verknüpfung werden übergeben).
     *
     * @param array<string, mixed> $session
     * @param \Closure(int): bool $isLinked wird nur bei `disabled_for_linked` und Nicht-Admins aufgerufen
     */
    public static function sessionMayContinue(int $userId, string $role, string $mode, array $session, \Closure $isLinked): bool
    {
        if ($mode === self::LOCAL_LOGIN_ALL || $role === 'admin') {
            return true;
        }
        if ($userId > 0 && (int) ($session['auth_sso_user_id'] ?? 0) === $userId) {
            return true;
        }

        return self::localLoginAllowedForMode($mode, false, $mode === self::LOCAL_LOGIN_DISABLED_FOR_LINKED && $isLinked($userId));
    }

    public static function redirectUri(): string
    {
        $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';

        return $base . self::CALLBACK_PATH;
    }

    /**
     * Wirksame Einstellungen aus der Datenbank.
     *
     * @param list<string>|null $knownRoles
     * @return array<string, mixed>
     */
    public static function load(?array $knownRoles = null): array
    {
        try {
            $raw = SettingsService::getInstance()->getGroup(self::GROUP);
        } catch (\Throwable) {
            $raw = [];
        }
        $raw['client_secret_set'] = trim((string) ($raw['client_secret'] ?? '')) !== '';
        unset($raw['client_secret']);

        return self::normalize($raw, $knownRoles);
    }

    /**
     * Entschlüsseltes Client-Secret – nur für den Token-Endpunkt, nie für Ausgabe oder Logs.
     */
    public static function clientSecret(): string
    {
        try {
            return trim(SettingsService::getInstance()->getString(self::GROUP, 'client_secret'));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Speichert das Formular. Ein leeres Secret-Feld behält das gespeicherte Secret; `clear_secret` löscht es.
     *
     * @param array<string, mixed> $input
     * @param list<string> $knownRoles
     * @return array<string, mixed> gespeicherte Einstellungen (ohne Secret)
     */
    public static function save(array $input, array $knownRoles): array
    {
        $settings = SettingsService::getInstance();
        $secret = trim((string) ($input['client_secret'] ?? ''));
        $secretSet = trim((string) $settings->getString(self::GROUP, 'client_secret')) !== '';

        if (!empty($input['clear_secret'])) {
            $settings->forget(self::GROUP, 'client_secret');
            $secretSet = false;
        } elseif ($secret !== '') {
            if (mb_strlen($secret) > 512 || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1) {
                throw new \InvalidArgumentException('client_secret');
            }
            $settings->set(self::GROUP, 'client_secret', $secret, true);
            $secretSet = true;
        }

        $input['client_secret_set'] = $secretSet;
        $clean = self::normalize($input, $knownRoles);

        $settings->setMany(self::GROUP, [
            'enabled' => $clean['enabled'] ? '1' : '0',
            'tenant_id' => $clean['tenant_id'],
            'allowed_tenants' => (string) json_encode($clean['allowed_tenants']),
            'client_id' => $clean['client_id'],
            'client_secret_expires' => $clean['client_secret_expires'],
            'auto_link' => $clean['auto_link'] ? '1' : '0',
            'allowed_domains' => (string) json_encode($clean['allowed_domains']),
            'auto_provision' => $clean['auto_provision'] ? '1' : '0',
            'default_role' => $clean['default_role'],
            'role_mapping' => (string) json_encode($clean['role_mapping'], JSON_UNESCAPED_SLASHES),
            'sync_roles' => $clean['sync_roles'] ? '1' : '0',
            'allow_admin_mapping' => $clean['allow_admin_mapping'] ? '1' : '0',
            'mfa_from_amr' => $clean['mfa_from_amr'] ? '1' : '0',
            'local_login' => $clean['local_login'],
            'button_label' => $clean['button_label'],
        ]);
        // Wirksamer Modus als automatisch geladene Option: Die Sitzungsprüfung (Auth::checkSession) liest ihn bei
        // jeder Anfrage aus dem Options-Cache, ohne zusätzliche Datenbankabfrage.
        $settings->set(self::GROUP, self::ENFORCED_LOCAL_LOGIN_KEY, self::enforcedLocalLogin($clean), false, 1);

        return $clean;
    }

    /**
     * Tage bis zum Ablauf des Client-Secrets (negativ = abgelaufen), null = kein Datum hinterlegt.
     *
     * @param array<string, mixed> $settings
     */
    public static function secretDaysLeft(array $settings, ?int $now = null): ?int
    {
        $expires = (string) ($settings['client_secret_expires'] ?? '');
        if ($expires === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
        if ($date === false) {
            return null;
        }

        return (int) floor(($date->getTimestamp() - ($now ?? time())) / 86400);
    }

    /**
     * Systemhinweis (AdminNoticeService) 30 Tage vor Ablauf des Client-Secrets; nur bei eingeschalteter Anmeldung.
     *
     * @param array<string, mixed> $settings
     * @return array{id:string,severity:string,title:string,text:string,url:string,link_label:string}|null
     */
    public static function secretExpiryNotice(array $settings, ?int $now = null): ?array
    {
        $days = self::secretDaysLeft($settings, $now);
        if (empty($settings['enabled']) || $days === null || $days > 30) {
            return null;
        }

        return \CMS\Services\AdminNoticeService::makeNotice(
            'sso_entra_secret',
            $days < 0 ? 'danger' : 'warning',
            'Entra-ID-Anmeldung',
            $days < 0
                ? 'Das Client-Secret ist abgelaufen – die Anmeldung mit Microsoft funktioniert nicht mehr.'
                : 'Das Client-Secret läuft in ' . $days . ' Tag' . ($days === 1 ? '' : 'en') . ' ab. Bitte rechtzeitig ein neues Secret hinterlegen.',
            '/admin/settings?tab=sso',
            'SSO-Einstellungen'
        );
    }

    /**
     * Listenfelder aus JSON, Array oder Text (eine Angabe je Zeile, Komma oder Leerzeichen).
     *
     * @return list<string>
     */
    private static function listValue(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && $trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                $value = is_array($decoded) ? $decoded : [];
            } else {
                $value = preg_split('/[\s,;]+/', $trimmed) ?: [];
            }
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value
        ), static fn (string $item): bool => $item !== ''));
    }
}
