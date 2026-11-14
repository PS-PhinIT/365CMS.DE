<?php
/**
 * Rollenbasierter MFA-Zwang (Release 4.1.0, Bereich 1b) und Konto-Weiche mit Passwort-Ablauf (1a).
 *
 * Ohne Datenbank: MfaEnforcementService bekommt Einstellungen, TOTP-Status und Passkey-Zähler als Closures.
 * Geprüft werden die Matrix Rolle × Faktor × Pfad, der Selbstaussperr-Schutz, das Entfernen des letzten Faktors,
 * die SSO-Sitzungsregel und – statisch – die Einbindung in Router, PublicRouter, Mitgliederbereich, Passkeys und Admin.
 *
 * Aufruf: php TESTS/mfa-enforcement/run.php [-v]
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';
T::$verbose = in_array('-v', $argv ?? [], true);

$root = dirname(__DIR__, 2) . '/CMS/';
if (!defined('ABSPATH')) {
    define('ABSPATH', $root);
}

if (!class_exists('CMS\\Hooks')) {
    eval('namespace CMS; final class Hooks {
        public static array $filters = [];
        public static function applyFilters(string $tag, $value, ...$args): mixed { return isset(self::$filters[$tag]) ? (self::$filters[$tag])($value, ...$args) : $value; }
        public static function doAction(string $tag, ...$args): void {}
    }');
}
require_once $root . 'core/Services/Security/MfaEnforcementService.php';

use CMS\Services\Security\MfaEnforcementService as Mfa;

$_SESSION = [];

/**
 * @param list<string> $roles erzwungene Rollen
 * @param array<int, bool> $totp
 * @param array<int, array<int, ?int>> $passkeys userId => [credentialId => user_verified]
 */
$make = static function (array $roles, array $totp = [], array $passkeys = []): Mfa {
    return new Mfa([
        'settings' => static fn(): array => [Mfa::SETTINGS_KEY_ROLES => json_encode($roles)],
        'totp' => static fn(int $userId): bool => $totp[$userId] ?? false,
        'verifiedPasskeys' => static fn(int $userId): int => count(array_filter($passkeys[$userId] ?? [], static fn(?int $uv): bool => $uv === 1)),
        'passkeyVerified' => static fn(int $credentialId, int $userId): bool => ($passkeys[$userId][$credentialId] ?? null) === 1,
    ]);
};
$user = static fn(int $id, string $role): object => (object) ['id' => $id, 'role' => $role];

T::run('Einstellungen: Rollenliste bereinigen', static function () use ($make): void {
    T::same(['admin', 'editor'], $make(['Admin', ' editor ', 'admin', '<script>', ''])->enforcedRoles(), 'klein, getrimmt, ohne Duplikate und ohne ungültige Namen');
    T::same([], $make([])->enforcedRoles(), 'Standard: kein Zwang');
    $broken = new Mfa(['settings' => static fn(): array => [Mfa::SETTINGS_KEY_ROLES => '{kaputt']]);
    T::same([], $broken->enforcedRoles(), 'ungültiges JSON → kein Zwang (kein Fatal)');
    $asArray = new Mfa(['settings' => static fn(): array => [Mfa::SETTINGS_KEY_ROLES => ['editor']]]);
    T::same(['editor'], $asArray->enforcedRoles(), 'bereits dekodierter Wert wird akzeptiert');
});

T::run('Zwang je Rolle und Filter', static function () use ($make, $user): void {
    $service = $make(['admin', 'editor']);
    T::check($service->isRequiredFor($user(1, 'admin')), 'admin betroffen');
    T::check($service->isRequiredFor($user(2, 'EDITOR')), 'Rollenvergleich ohne Groß-/Kleinschreibung');
    T::check(!$service->isRequiredFor($user(3, 'member')), 'member nicht betroffen');
    T::check(!$service->isRequiredFor((object) ['id' => 4]), 'ohne Rolle nicht betroffen');

    \CMS\Hooks::$filters['cms_mfa_enforcement_required'] = static fn(bool $required, object $u): bool => $u->id === 3;
    T::check($service->isRequiredFor($user(3, 'member')), 'Filter kann Zwang zusätzlich einschalten');
    \CMS\Hooks::$filters['cms_mfa_enforcement_required'] = static fn(): bool => false;
    T::check($service->isRequiredFor($user(1, 'admin')), 'Filter kann konfigurierte Rolle nicht freistellen');
    unset(\CMS\Hooks::$filters['cms_mfa_enforcement_required']);
});

T::run('Anerkannte Faktoren', static function () use ($make): void {
    $service = $make(['admin'], [10 => true], [11 => [1 => 1], 12 => [2 => null, 3 => 0], 13 => [4 => 0, 5 => 1]]);
    T::check($service->hasRecognisedFactor(10), 'TOTP aktiv');
    T::check($service->hasRecognisedFactor(11), 'Passkey mit UV = 1');
    T::check(!$service->hasRecognisedFactor(12), 'Passkey UV NULL/0 zählt nicht');
    T::check($service->hasRecognisedFactor(13), 'mindestens ein Passkey mit UV genügt');
    T::check(!$service->hasRecognisedFactor(14), 'kein Faktor');
    T::check(!$service->hasRecognisedFactor(0), 'ungültige ID');

    $_SESSION[Mfa::SESSION_SSO_MFA] = 14;
    T::check($service->hasRecognisedFactor(14), 'SSO-Anmeldung mit MFA-Nachweis gilt für die Sitzung');
    T::check(!$service->hasRecognisedFactor(12), 'SSO-Flag gilt nur für das eigene Konto');
    $_SESSION[Mfa::SESSION_SSO_MFA] = '14x';
    T::check(!$service->hasRecognisedFactor(15), 'manipulierter Flag-Wert zählt nicht für fremde Konten');
    $_SESSION = [];
    T::check(!$service->hasRecognisedFactor(14), 'nach Sitzungsende kein Faktor mehr');
});

// Matrix: Pfad × Methode → erwartete Entscheidung bei Zwang ohne Faktor
$paths = [
    ['/admin', 'GET', 'redirect'],
    ['/admin/posts', 'POST', 'redirect'],
    ['/member', 'GET', 'redirect'],
    ['/member/profile', 'POST', 'redirect'],
    ['/', 'GET', 'redirect'],
    ['/blog/beitrag', 'GET', 'redirect'],
    ['/mfa-setup', 'GET', null],
    ['/mfa-setup', 'POST', null],
    ['/mfa-setup/', 'GET', null],
    ['/member/security', 'GET', null],
    ['/member/security', 'POST', null],
    ['/logout', 'POST', null],
    ['/logout', 'GET', null],
    ['/assets/css/cms-mfa.css', 'GET', null],
    ['/assets/../admin', 'GET', 'redirect'],
    ['/mfa-disable', 'POST', 'redirect'],
    ['/member/security/../profile', 'GET', 'redirect'],
    ['/api/v1/posts', 'GET', 'json'],
    ['/api', 'POST', 'json'],
];

T::run('Matrix: Zwang ohne Faktor', static function () use ($paths): void {
    foreach ($paths as [$path, $method, $expected]) {
        $decision = Mfa::decide(['logged_in' => true, 'path' => $path, 'method' => $method, 'mfa_required' => true, 'has_factor' => false]);
        $actual = $decision['type'] ?? null;
        T::same($expected, $actual, "{$method} {$path}");
        if ($expected === 'redirect') {
            T::same('/mfa-setup', $decision['target'] ?? '', "{$method} {$path} → /mfa-setup");
        }
        if ($expected === 'json') {
            T::same([403, 'mfa_setup_required'], [$decision['status'] ?? 0, $decision['error'] ?? ''], "{$method} {$path} → 403 JSON");
        }
    }
});

T::run('Matrix: durchlassen', static function () use ($paths): void {
    foreach ($paths as [$path, $method]) {
        $base = ['path' => $path, 'method' => $method];
        T::same(null, Mfa::decide($base + ['logged_in' => true, 'mfa_required' => true, 'has_factor' => true]), "mit Faktor: {$method} {$path}");
        T::same(null, Mfa::decide($base + ['logged_in' => true, 'mfa_required' => false, 'has_factor' => false]), "ohne Zwang: {$method} {$path}");
        T::same(null, Mfa::decide($base + ['logged_in' => false, 'mfa_required' => true]), "nicht angemeldet: {$method} {$path}");
        T::same(null, Mfa::decide($base + ['logged_in' => true, 'bearer' => true, 'mfa_required' => true]), "Bearer-Token: {$method} {$path}");
    }
});

T::run('Passwort-Ablauf hat Vorrang', static function (): void {
    $ctx = ['logged_in' => true, 'password_change_required' => true, 'mfa_required' => true, 'has_factor' => false];
    T::same(null, Mfa::decide($ctx + ['path' => '/member/security', 'method' => 'GET']), 'Sicherheitsseite lesbar');
    T::same(null, Mfa::decide($ctx + ['path' => '/member/security', 'method' => 'POST', 'action' => 'password_change']), 'Passwortänderung erlaubt');
    $other = Mfa::decide($ctx + ['path' => '/member/security', 'method' => 'POST', 'action' => 'totp_start']);
    T::same(['password', 'redirect', '/member/security'], [$other['gate'] ?? '', $other['type'] ?? '', $other['target'] ?? ''], 'andere Aktionen erst nach der Passwortänderung');
    $setup = Mfa::decide($ctx + ['path' => '/mfa-setup', 'method' => 'GET']);
    T::same('/member/security', $setup['target'] ?? '', '/mfa-setup erst nach der Passwortänderung');
    T::same(null, Mfa::decide($ctx + ['path' => '/logout', 'method' => 'POST']), 'Abmelden erlaubt');
    $api = Mfa::decide($ctx + ['path' => '/api/v1/me', 'method' => 'GET']);
    T::same([403, 'password_change_required'], [$api['status'] ?? 0, $api['error'] ?? ''], 'API: 403 JSON');
    T::same('redirect', Mfa::decide(['logged_in' => true, 'password_change_required' => true, 'path' => '/admin', 'method' => 'GET'])['type'] ?? null, 'gilt auch ohne MFA-Zwang');
});

T::run('Letzten Faktor nicht entfernen', static function () use ($make, $user): void {
    $service = $make(['admin'], [1 => true, 2 => true], [2 => [20 => 1], 3 => [30 => 1, 31 => 1], 4 => [40 => 1, 41 => null]]);
    T::check(!$service->canRemoveFactor($user(1, 'admin'), 'totp'), 'nur TOTP → Abschalten abgelehnt');
    T::check($service->canRemoveFactor($user(2, 'admin'), 'totp'), 'TOTP + Passkey UV → TOTP abschaltbar');
    T::check($service->canRemoveFactor($user(2, 'admin'), 'passkey', 20), 'TOTP + Passkey UV → Passkey löschbar');
    T::check($service->canRemoveFactor($user(3, 'admin'), 'passkey', 30), 'zwei Passkeys UV → einer löschbar');
    T::check(!$service->canRemoveFactor($user(4, 'admin'), 'passkey', 40), 'letzter Passkey UV → Löschen abgelehnt');
    T::check($service->canRemoveFactor($user(4, 'admin'), 'passkey', 41), 'Passkey ohne UV bleibt löschbar');
    T::check($service->canRemoveFactor($user(1, 'member'), 'totp'), 'ohne Zwang immer erlaubt');

    $_SESSION[Mfa::SESSION_SSO_MFA] = 1;
    T::check(!$service->canRemoveFactor($user(1, 'admin'), 'totp'), 'SSO-Sitzungsflag ersetzt keinen dauerhaften Faktor');
    $_SESSION = [];
});

T::run('Selbstaussperr-Schutz', static function (): void {
    T::check(Mfa::wouldLockOut(['admin'], 'admin', false), 'eigene Rolle ohne Faktor → abgelehnt');
    T::check(Mfa::wouldLockOut(['ADMIN '], 'Admin', false), 'auch bei abweichender Schreibweise');
    T::check(!Mfa::wouldLockOut(['admin'], 'admin', true), 'eigene Rolle mit Faktor → erlaubt');
    T::check(!Mfa::wouldLockOut(['editor'], 'admin', false), 'fremde Rolle → erlaubt');
    T::check(!Mfa::wouldLockOut([], 'admin', false), 'Zwang aufheben immer erlaubt');
});

T::run('Gegenprobe: Allowlist ist eng', static function (): void {
    // Präfix-Tricks dürfen die Einrichtungs-Pfade nicht erweitern.
    foreach (['/mfa-setup-evil', '/member/security-export', '/member/securityx', '/logout/../admin', '/CMS/assets/../../admin'] as $path) {
        $decision = Mfa::decide(['logged_in' => true, 'path' => $path, 'method' => 'GET', 'mfa_required' => true, 'has_factor' => false]);
        T::same('redirect', $decision['type'] ?? null, "{$path} bleibt gesperrt");
    }
});

T::run('Einbindung (statisch)', static function () use ($root): void {
    $router = (string) file_get_contents($root . 'core/Router.php');
    $maintenance = strpos($router, '$this->renderMaintenancePage($routingUri);');
    $gate = strpos($router, '$this->enforceAccountGates($routingUri, $method)');
    $csrf = strpos($router, '$csrfBypassPrefixes = ');
    T::check($maintenance !== false && $gate !== false && $csrf !== false && $maintenance < $gate && $gate < $csrf, 'Router: Weiche nach Wartungsmodus und vor dem CSRF-Block');
    T::check(str_contains($router, 'MfaEnforcementService::decide('), 'Router entscheidet über decide()');
    T::check(str_contains($router, "'mfa_enforcement_redirect'"), 'Router: Audit-Eintrag');

    $public = (string) file_get_contents($root . 'core/Routing/PublicRouter.php');
    T::check(preg_match("/canRemoveFactor\\(\\\$currentUser, 'totp'\\)[\\s\\S]{0,400}return;[\\s\\S]{0,200}disableMfa/", $public) === 1, '/mfa-disable prüft den Zwang vor disableMfa()');

    $member = (string) file_get_contents($root . 'member/includes/class-member-controller.php');
    $guard = strpos($member, "canRemoveFactor(\$currentUser, \$factor");
    T::check($guard !== false && $guard < strpos($member, "if (\$action === 'totp_disable')") && $guard < strpos($member, "if (\$action === 'passkey_delete')"), 'Mitgliederbereich: Schutz vor totp_disable und passkey_delete');

    $webauthn = (string) file_get_contents($root . 'core/Auth/Passkey/WebAuthnAdapter.php');
    T::check(str_contains($webauthn, "\$row['user_verified'] = !empty(\$data->userVerified) ? 1 : 0;"), 'Passkey-Registrierung speichert user_verified');
    T::check(str_contains($webauthn, "user_verified   TINYINT(1)   NULL DEFAULT NULL"), 'Passkey-Tabelle (Adapter) kennt user_verified');
    T::check(str_contains($webauthn, "'user_verified' => \"ADD COLUMN user_verified"), 'Adapter gleicht user_verified ab');
    T::check(str_contains($webauthn, "WHERE user_id = ? AND user_verified = 1"), 'Zählung nur mit user_verified = 1');

    $authManager = (string) file_get_contents($root . 'core/Auth/AuthManager.php');
    T::check(str_contains($authManager, '($authenticatorFlags & 0x04) !== 0') && str_contains($authManager, 'markUserVerified('), 'Passkey-Login mit UV markiert Bestandspasskey');

    $tab = (string) file_get_contents($root . 'admin/settings-tabs/security.php');
    T::check(str_contains($tab, "CMS_ADMIN_MFA_POLICY_CSRF_ACTION = 'admin_mfa_policy'") && str_contains($tab, 'verifyToken((string) ($_POST[\'csrf_token\'] ?? \'\'), CMS_ADMIN_MFA_POLICY_CSRF_ACTION)'), 'Admin: eigenes CSRF-Token');
    T::check(str_contains($tab, 'MfaEnforcementService::wouldLockOut('), 'Admin: Selbstaussperr-Schutz');
    T::check(str_contains($tab, "'mfa_policy_saved'"), 'Admin: Audit-Eintrag');

    $view = (string) file_get_contents($root . 'admin/views/settings/security.php');
    T::check(str_contains($view, 'id="mfa-policy"') && !preg_match('/\son[a-z]+=|<script/i', $view), 'Admin-Ansicht ohne Inline-Skript/Handler');

    foreach (['de', 'en'] as $lang) {
        $yaml = (string) file_get_contents($root . "lang/{$lang}.yaml");
        foreach (['mfa_policy.heading', 'mfa_policy.error.self_lockout', 'member.security.mfa_enforced_last_factor', 'member.security.mfa_enforced_notice'] as $key) {
            T::check(str_contains($yaml, "\"{$key}\":"), "{$lang}: {$key}");
        }
    }
});

T::finish();
