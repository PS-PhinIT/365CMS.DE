<?php
/**
 * Konto-Zuordnung für Entra-Anmeldungen (4.1.0, Bereich 1c; Schutz gegen Kontoübernahme, GHSA-6hhv-gc4g-f4r7).
 *
 * Reihenfolge (01-IDENTITY §3.3):
 * 1. Bestehende Bindung `(entra, tid, oid)` → dieses Konto; gesperrt/inaktiv → Ablehnung (nie reaktivieren).
 * 2. Automatische Verknüpfung nur, wenn alles gilt: Einstellung an, Single-Tenant, `email` mit `xms_edov = true`,
 *    Domain in der Allowlist, genau ein aktives lokales Konto mit lokal bestätigter Adresse, keine privilegierte
 *    Rolle (admin, editor, author) und keine Rolle aus dem MFA-Zwang. `preferred_username`/`upn` nie.
 * 3. Kein lokales Konto mit dieser Adresse → Auto-Provisioning (Einstellung), Rolle aus dem Mapping.
 * 4. Sonst Ablehnung „Kein Konto verknüpft“ – ein Konto mit gleicher Adresse wird nie still übernommen
 *    (Audit `sso_link_conflict`), die Verknüpfung erfolgt dann aktiv nach lokalem Login.
 *
 * Rollen-Mapping (§3.5): erste passende Regel (App-Rolle aus `roles` oder Gruppe aus `groups`), `admin` nur mit
 * `allow_admin_mapping`; bei bestehenden Konten nur mit `sync_roles` und nur für über SSO angelegte Konten.
 *
 * @package CMSv2\Core\Auth\SSO
 */

declare(strict_types=1);

namespace CMS\Auth\SSO;

if (!defined('ABSPATH')) {
    exit;
}

final class IdentityLinker
{
    /** Rollen, die nie automatisch per E-Mail verknüpft werden (zusätzlich zu den Rollen mit MFA-Zwang). */
    public const PRIVILEGED_ROLES = ['admin', 'editor', 'author'];

    public function __construct(private readonly IdentityStoreInterface $store)
    {
    }

    /**
     * @param array<string, mixed> $claims geprüfte ID-Token-Claims
     * @param array<string, mixed> $settings EntraSettings::normalize()
     * @param list<string> $mfaRoles Rollen mit MFA-Zwang
     * @param list<string> $knownRoles bekannte CMS-Rollen
     * @return array{status:string, reason?:string, user_id?:int, identity_id?:int, linked?:string, provisioned?:bool, role_change?:array{from:string,to:string}, overage?:bool}
     *   status `login` oder `rejected`; `linked`: existing|auto|provisioned
     */
    public function resolveLogin(array $claims, array $settings, array $mfaRoles = [], array $knownRoles = []): array
    {
        $tid = (string) ($claims['tid'] ?? '');
        $oid = (string) ($claims['oid'] ?? '');
        if (!EntraSettings::isGuid($tid) || !EntraSettings::isGuid($oid)) {
            return ['status' => 'rejected', 'reason' => 'subject'];
        }
        $overage = self::hasGroupOverage($claims);

        // 1. Bestehende Bindung
        $identity = $this->store->findIdentity(EntraSettings::PROVIDER, $tid, $oid);
        if ($identity !== null) {
            $user = $this->store->findUser($identity['user_id']);
            if ($user === null) {
                return ['status' => 'rejected', 'reason' => 'not_linked'];
            }
            if ((string) ($user->status ?? '') !== 'active') {
                return ['status' => 'rejected', 'reason' => 'account_inactive', 'user_id' => (int) $user->id];
            }

            $result = ['status' => 'login', 'user_id' => (int) $user->id, 'identity_id' => $identity['id'], 'linked' => 'existing', 'provisioned' => $identity['provisioned'], 'overage' => $overage];
            if (!empty($settings['sync_roles']) && $identity['provisioned']) {
                $currentRole = (string) ($user->role ?? '');
                $target = self::syncTarget($currentRole, self::mapRole($claims, $settings, $knownRoles), $settings);
                if ($target !== null && $this->store->updateRole((int) $user->id, $target)) {
                    $result['role_change'] = ['from' => $currentRole, 'to' => $target];
                }
            }

            return $result;
        }

        $claimEmail = EntraIdProvider::claimEmail($claims);
        $candidates = $claimEmail !== '' ? $this->store->findUsersByEmail($claimEmail) : [];

        // 2. Automatische Verknüpfung (alle Bedingungen)
        if ($candidates !== []) {
            $autoUser = $this->autoLinkCandidate($claims, $settings, $candidates, $mfaRoles);
            if ($autoUser === null) {
                return ['status' => 'rejected', 'reason' => 'link_conflict'];
            }
            if ($this->store->findIdentityForUser((int) $autoUser->id, EntraSettings::PROVIDER) !== null
                || !$this->store->createIdentity((int) $autoUser->id, EntraSettings::PROVIDER, $tid, $oid, $claimEmail, false)) {
                return ['status' => 'rejected', 'reason' => 'link_conflict', 'user_id' => (int) $autoUser->id];
            }
            $identity = $this->store->findIdentity(EntraSettings::PROVIDER, $tid, $oid);

            return ['status' => 'login', 'user_id' => (int) $autoUser->id, 'identity_id' => (int) ($identity['id'] ?? 0), 'linked' => 'auto', 'provisioned' => false, 'overage' => $overage];
        }

        // 3. Auto-Provisioning
        if (empty($settings['auto_provision'])) {
            return ['status' => 'rejected', 'reason' => 'not_linked'];
        }
        if ($claimEmail === '') {
            return ['status' => 'rejected', 'reason' => 'email_missing'];
        }

        $role = self::mapRole($claims, $settings, $knownRoles) ?? (string) ($settings['default_role'] ?? 'member');
        if ($role === 'admin' && empty($settings['allow_admin_mapping'])) {
            $role = 'member';
        }
        $username = $this->uniqueUsername((string) ($claims['name'] ?? ''), $claimEmail);
        if ($username === '') {
            return ['status' => 'rejected', 'reason' => 'provision_failed'];
        }
        $displayName = self::displayName($claims, $username);
        $userId = $this->store->createUser($username, $claimEmail, $displayName, $role, EntraIdProvider::verifiedEmail($claims) !== '');
        if ($userId <= 0) {
            return ['status' => 'rejected', 'reason' => 'provision_failed'];
        }
        if (!$this->store->createIdentity($userId, EntraSettings::PROVIDER, $tid, $oid, $claimEmail, true)) {
            // Gleichzeitige Anmeldung derselben Identität: Konto wieder entfernen, keine zweite Bindung.
            $this->store->deleteUser($userId);

            return ['status' => 'rejected', 'reason' => 'provision_failed'];
        }
        $identity = $this->store->findIdentity(EntraSettings::PROVIDER, $tid, $oid);

        return ['status' => 'login', 'user_id' => $userId, 'identity_id' => (int) ($identity['id'] ?? 0), 'linked' => 'provisioned', 'provisioned' => true, 'overage' => $overage];
    }

    /**
     * Aktive Verknüpfung durch das angemeldete Konto (nach Re-Auth gestartet).
     *
     * @param array<string, mixed> $claims
     * @return array{status:string, reason?:string, identity_id?:int}
     *   status `linked`, `already_linked` (gleiche Identität) oder `rejected`
     */
    public function link(array $claims, int $userId): array
    {
        $tid = (string) ($claims['tid'] ?? '');
        $oid = (string) ($claims['oid'] ?? '');
        if ($userId <= 0 || !EntraSettings::isGuid($tid) || !EntraSettings::isGuid($oid)) {
            return ['status' => 'rejected', 'reason' => 'subject'];
        }
        $user = $this->store->findUser($userId);
        if ($user === null || (string) ($user->status ?? '') !== 'active') {
            return ['status' => 'rejected', 'reason' => 'account_inactive'];
        }

        $identity = $this->store->findIdentity(EntraSettings::PROVIDER, $tid, $oid);
        if ($identity !== null) {
            return $identity['user_id'] === $userId
                ? ['status' => 'already_linked', 'identity_id' => $identity['id']]
                : ['status' => 'rejected', 'reason' => 'identity_taken'];
        }
        if ($this->store->findIdentityForUser($userId, EntraSettings::PROVIDER) !== null) {
            return ['status' => 'rejected', 'reason' => 'user_has_identity'];
        }
        if (!$this->store->createIdentity($userId, EntraSettings::PROVIDER, $tid, $oid, EntraIdProvider::claimEmail($claims), false)) {
            return ['status' => 'rejected', 'reason' => 'identity_taken'];
        }
        $identity = $this->store->findIdentity(EntraSettings::PROVIDER, $tid, $oid);

        return ['status' => 'linked', 'identity_id' => (int) ($identity['id'] ?? 0)];
    }

    /**
     * Erste passende Mapping-Regel (Reihenfolge = Priorität). `admin` nur mit `allow_admin_mapping`.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $settings
     * @param list<string> $knownRoles leer = keine Prüfung
     */
    public static function mapRole(array $claims, array $settings, array $knownRoles = []): ?string
    {
        $appRoles = array_values(array_filter((array) ($claims['roles'] ?? []), 'is_string'));
        $groups = array_map('strtolower', array_values(array_filter((array) ($claims['groups'] ?? []), 'is_string')));

        foreach ((array) ($settings['role_mapping'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $role = (string) ($rule['role'] ?? '');
            if ($role === '' || ($knownRoles !== [] && !in_array($role, $knownRoles, true))) {
                continue;
            }
            if ($role === 'admin' && empty($settings['allow_admin_mapping'])) {
                continue;
            }
            $value = (string) ($rule['value'] ?? '');
            $matches = match ((string) ($rule['type'] ?? '')) {
                'role' => in_array($value, $appRoles, true),
                'group' => in_array(strtolower($value), $groups, true),
                default => false,
            };
            if ($matches) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Zielrolle bei der Synchronisation: Mapping oder Standardrolle; null = keine Änderung.
     * Herabstufen ist erlaubt, `admin` nur mit `allow_admin_mapping`.
     *
     * @param array<string, mixed> $settings
     */
    public static function syncTarget(string $currentRole, ?string $mappedRole, array $settings): ?string
    {
        $target = $mappedRole ?? (string) ($settings['default_role'] ?? 'member');
        if ($target === '' || $target === $currentRole) {
            return null;
        }
        if ($target === 'admin' && empty($settings['allow_admin_mapping'])) {
            return null;
        }

        return $target;
    }

    /**
     * Mehr als 200 Gruppen: Entra liefert statt `groups` einen Verweis (`_claim_names.groups`). Dann greifen nur
     * App-Rollen; ein Audit-Hinweis empfiehlt App-Rollen.
     *
     * @param array<string, mixed> $claims
     */
    public static function hasGroupOverage(array $claims): bool
    {
        $names = $claims['_claim_names'] ?? null;

        return (is_array($names) && isset($names['groups'])) || !empty($claims['hasgroups']);
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $settings
     * @param list<object> $candidates
     * @param list<string> $mfaRoles
     */
    private function autoLinkCandidate(array $claims, array $settings, array $candidates, array $mfaRoles): ?object
    {
        if (empty($settings['auto_link']) || EntraSettings::isMultiTenant($settings)) {
            return null;
        }
        $email = EntraIdProvider::verifiedEmail($claims);
        if ($email === '') {
            return null;
        }
        $domain = substr((string) strrchr($email, '@'), 1);
        if ($domain === '' || !in_array($domain, (array) ($settings['allowed_domains'] ?? []), true)) {
            return null;
        }
        if (count($candidates) !== 1) {
            return null;
        }

        $user = $candidates[0];
        $role = strtolower((string) ($user->role ?? ''));
        if ((string) ($user->status ?? '') !== 'active'
            || strtolower((string) ($user->email ?? '')) !== $email
            || in_array($role, self::PRIVILEGED_ROLES, true)
            || in_array($role, array_map('strtolower', $mfaRoles), true)
            || !$this->store->isEmailVerified((int) $user->id)) {
            return null;
        }

        return $user;
    }

    private function uniqueUsername(string $name, string $email): string
    {
        $base = self::usernameBase($name !== '' ? $name : (string) strstr($email, '@', true));
        for ($i = 0; $i < 6; $i++) {
            $candidate = $base . '-' . bin2hex(random_bytes(2));
            if (!$this->store->usernameExists($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    public static function usernameBase(string $name): string
    {
        $ascii = $name;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($converted)) {
                $ascii = $converted;
            }
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '.', strtolower($ascii)), '.');
        $slug = substr($slug, 0, 40);

        return $slug !== '' ? rtrim($slug, '.') : 'sso';
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function displayName(array $claims, string $fallback): string
    {
        $name = trim(strip_tags(is_string($claims['name'] ?? null) ? $claims['name'] : ''));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return $name !== '' ? mb_substr($name, 0, 100) : $fallback;
    }
}
