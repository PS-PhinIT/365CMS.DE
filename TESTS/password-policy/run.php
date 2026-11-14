<?php
/**
 * Passwort-Richtlinie (Release 4.1.0, Bereich 1a): Grenzen, Zeichenklassen, Historie, Ablauf, Filter, Einbindung.
 *
 * Ohne Datenbank: PasswordPolicyService bekommt Einstellungen, Historie und Uhr als Closures. Die Einbindung in Auth,
 * UserService, MemberService und CmsAuthPageService wird statisch geprüft.
 *
 * Aufruf: php TESTS/password-policy/run.php [-v]
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';
T::$verbose = in_array('-v', $argv ?? [], true);

$root = dirname(__DIR__, 2) . '/CMS/';
if (!defined('ABSPATH')) {
    define('ABSPATH', $root);
}

// Minimaler Hook-Ersatz: Filter cms_password_policy und Aktion cms_password_changed beobachtbar machen.
if (!class_exists('CMS\\Hooks')) {
    eval('namespace CMS; final class Hooks {
        public static array $filters = []; public static array $actions = [];
        public static function applyFilters(string $tag, $value, ...$args): mixed { return isset(self::$filters[$tag]) ? (self::$filters[$tag])($value, ...$args) : $value; }
        public static function doAction(string $tag, ...$args): void { self::$actions[] = [$tag, $args]; }
    }');
}
require_once $root . 'core/Services/Security/PasswordPolicyService.php';

use CMS\Services\Security\PasswordPolicyService;

/** Testinstanz mit Speicher im Arbeitsspeicher. */
$make = static function (array $settings = [], array &$store = [], ?int $now = null): PasswordPolicyService {
    $store += ['history' => [], 'current' => [], 'changed' => []];

    return new PasswordPolicyService([
        'settings' => static fn(): array => $settings,
        'history' => static function (int $userId, int $limit) use (&$store): array {
            return array_slice(array_reverse($store['history'][$userId] ?? []), 0, $limit);
        },
        'currentHash' => static fn(int $userId): ?string => $store['current'][$userId] ?? null,
        'changedAt' => static fn(int $userId): ?string => $store['changed'][$userId] ?? null,
        'historyWriter' => static function (int $userId, string $hash, int $keep) use (&$store): void {
            $store['changed'][$userId] = gmdate('Y-m-d H:i:s', $now ?? time());
            $store['current'][$userId] = $hash;
            if ($keep < 1) {
                $store['history'][$userId] = [];
                return;
            }
            $store['history'][$userId][] = $hash;
            $store['history'][$userId] = array_slice($store['history'][$userId], -$keep);
        },
        'clock' => static fn(): int => $now ?? time(),
    ]);
};
$hash = static fn(string $password): string => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);

T::run('Standard = bisheriges Verhalten', static function () use ($make): void {
    $store = [];
    $policy = $make([], $store)->definition();
    T::same(12, $policy['min_length'], 'Mindestlänge 12');
    T::check($policy['require_uppercase'] && $policy['require_lowercase'] && $policy['require_digit'] && $policy['require_special'], 'alle vier Zeichenklassen');
    T::same(0, $policy['max_age_days'], 'kein Ablauf');
    T::same(5, $policy['history'], 'Historie 5');
    T::same(true, $make([], $store)->validate('Sicheres-Passwort1'), 'gültiges Passwort');
    T::same('Das Passwort muss mindestens 12 Zeichen lang sein.', $make([], $store)->validate('Kurz1!'), 'Meldung Mindestlänge');
});

T::run('Grenzen und Normalisierung', static function (): void {
    $n = PasswordPolicyService::normalize(['min_length' => '4', 'history' => '99', 'max_age_days' => '-3']);
    T::same(8, $n['min_length'], 'Mindestlänge unter 8 → 8');
    T::same(24, $n['history'], 'Historie über 24 → 24');
    T::same(0, $n['max_age_days'], 'negatives Alter → 0');
    T::same(128, PasswordPolicyService::normalize(['min_length' => 1000])['min_length'], 'Mindestlänge über 128 → 128');
    T::same(12, PasswordPolicyService::normalize(['min_length' => 'abc'])['min_length'], 'ungültige Zahl → Standard');
    T::same(false, PasswordPolicyService::normalize(['require_special' => '0'])['require_special'], '"0" schaltet Klasse ab');
});

T::run('Zeichenklassen einzeln', static function () use ($make): void {
    $store = [];
    $onlyLength = ['min_length' => 10, 'require_uppercase' => '0', 'require_lowercase' => '0', 'require_digit' => '0', 'require_special' => '0'];
    T::same(true, $make($onlyLength, $store)->validate('aaaaaaaaaa'), 'nur Länge: 10 × a gültig');
    T::check($make(array_merge($onlyLength, ['require_digit' => '1']), $store)->validate('aaaaaaaaaa') !== true, 'mit Ziffer-Pflicht ungültig');
    T::same(true, $make(['min_length' => 8], $store)->validate('Ab1!Ab1!'), 'Unicode-unabhängig: 8 Zeichen');
    T::same(true, $make(['min_length' => 8, 'require_special' => '0'], $store)->validate('Äb1Öb1üß'), 'Umlaute zählen als ein Zeichen und als Groß-/Kleinbuchstaben');
    T::check($make(['min_length' => 8], $store)->validate('Äbcdefg1') !== true, 'Umlaut ist kein Sonderzeichen');
    T::same(true, $make(['min_length' => 8], $store)->validate('Äbcdef1!'), 'Ä als Großbuchstabe + ! als Sonderzeichen');
    T::check($make(['min_length' => 8], $store)->validate("Abc1!\xff\xfe") !== true, 'ungültiges UTF-8 wird abgelehnt (keine Zeichenklasse erkannt)');
    $evaluation = $make(['require_special' => '0'], $store)->evaluate('');
    T::same(['uppercase', 'lowercase', 'digit'], array_keys($evaluation['definition']['patterns']), 'Definition nennt nur geforderte Klassen');
    T::same('\\p{Lu}', $evaluation['definition']['patterns']['uppercase'], 'Muster für den Browser ohne Begrenzer (new RegExp(source, "u"))');
});

T::run('Historie (keine Wiederverwendung)', static function () use ($make, $hash): void {
    $store = [];
    $service = $make(['history' => 3], $store);
    $passwords = ['Erstes-Passwort1', 'Zweites-Passwort2', 'Drittes-Passwort3', 'Viertes-Passwort4'];
    foreach ($passwords as $password) {
        $service->recordChange(7, $hash($password), 'test');
    }
    T::same(3, count($store['history'][7]), 'Historie auf 3 gekürzt');
    T::check($service->isReused(7, 'Viertes-Passwort4'), 'aktuelles Passwort gilt als verwendet');
    T::check($service->isReused(7, 'Zweites-Passwort2'), 'Passwort innerhalb der letzten 3 abgelehnt');
    T::check(!$service->isReused(7, 'Erstes-Passwort1'), 'älter als 3 → wieder erlaubt');
    T::same('Dieses Passwort wurde kürzlich verwendet. Bitte wähle ein anderes (keines der letzten 3).', $service->validate('Drittes-Passwort3', 7), 'Meldung bei Wiederverwendung');
    T::same(true, $service->validate('Drittes-Passwort3'), 'ohne Benutzer-ID keine Historienprüfung (Neuanlage)');

    $current = ['history' => [], 'current' => [8 => $hash('Altes-Passwort9')], 'changed' => []];
    T::check($make(['history' => 2], $current)->isReused(8, 'Altes-Passwort9'), 'Bestandskonto: aktueller Hash zählt auch ohne Historie');

    $off = [];
    $offService = $make(['history' => 0], $off);
    $offService->recordChange(9, $hash('Neues-Passwort9'), 'test');
    T::same([], $off['history'][9], 'Historie 0: nichts gespeichert');
    T::check(!$offService->isReused(9, 'Neues-Passwort9'), 'Historie 0: keine Prüfung');
});

T::run('Ablauf (Höchstalter)', static function () use ($make): void {
    $now = strtotime('2026-11-14 12:00:00 UTC');
    $store = ['changed' => [1 => '2026-08-01 12:00:00', 2 => '2026-11-01 12:00:00']];
    $service = $make(['max_age_days' => 90], $store, $now);
    T::check($service->isExpired(1), '105 Tage alt bei 90 → abgelaufen');
    T::check(!$service->isExpired(2), '13 Tage alt → gültig');
    T::check(!$service->isExpired(3), 'unbekanntes Konto → nicht abgelaufen');
    T::check(!$make(['max_age_days' => 0], $store, $now)->isExpired(1), 'Höchstalter 0 → nie abgelaufen');
});

T::run('Filter darf nur verschärfen', static function () use ($make): void {
    $store = [];
    \CMS\Hooks::$filters['cms_password_policy'] = static fn(array $p): array => ['min_length' => 8, 'require_uppercase' => false, 'require_lowercase' => false, 'require_digit' => false, 'require_special' => false, 'max_age_days' => 0, 'history' => 0];
    $weak = $make(['min_length' => 14, 'max_age_days' => 30, 'history' => 4], $store)->definition();
    T::same(14, $weak['min_length'], 'Filter kann Mindestlänge nicht senken');
    T::check($weak['require_special'], 'Filter kann Klasse nicht abschalten');
    T::same(30, $weak['max_age_days'], 'Filter kann Ablauf nicht abschalten');
    T::same(4, $weak['history'], 'Filter kann Historie nicht kürzen');
    \CMS\Hooks::$filters['cms_password_policy'] = static fn(array $p): array => ['min_length' => 20, 'max_age_days' => 10, 'history' => 10] + $p;
    $strong = $make(['min_length' => 14, 'max_age_days' => 30, 'history' => 4], $store)->definition();
    T::same([20, 10, 10], [$strong['min_length'], $strong['max_age_days'], $strong['history']], 'Filter kann verschärfen');
    unset(\CMS\Hooks::$filters['cms_password_policy']);
});

T::run('Hook bei Änderung', static function () use ($make, $hash): void {
    $store = [];
    \CMS\Hooks::$actions = [];
    $make([], $store)->recordChange(11, $hash('Hook-Passwort11'), 'member');
    T::same([['cms_password_changed', [11, 'member']]], \CMS\Hooks::$actions, 'cms_password_changed mit Benutzer-ID und Quelle');
});

T::run('Einbindung (statisch)', static function () use ($root): void {
    $read = static fn(string $file): string => (string) file_get_contents($root . $file);
    $auth = $read('core/Auth.php');
    T::check(!str_contains($auth, 'PASSWORD_POLICY_MIN_LENGTH'), 'Auth: keine festen Policy-Konstanten mehr');
    T::check(str_contains($auth, 'PasswordPolicyService::getInstance()->validate($password, $userId)'), 'Auth::validatePasswordPolicy delegiert inkl. Benutzer-ID');
    T::check(str_contains($auth, "recordChange((int) \$userId, \$passwordHash, 'register')"), 'Registrierung schreibt Historie');
    $user = $read('core/Services/UserService.php');
    T::check(str_contains($user, "recordChange((int) \$user_id, \$passwordHash, 'admin_create')"), 'Admin legt Benutzer an → Historie');
    T::check(str_contains($user, "recordChange((int) \$user_id, (string) \$update_data['password'], 'admin_update')"), 'Admin ändert Passwort → Historie');
    T::check(str_contains($user, "\$this->validateUserData(\$data, 'update', (int) \$user_id)"), 'Admin-Änderung prüft Historie des Kontos');
    $member = $read('core/Services/MemberService.php');
    T::check(str_contains($member, 'validatePasswordPolicy($new, $userId)'), 'Mitglied: Prüfung mit Benutzer-ID');
    T::check(str_contains($member, "recordChange(\$userId, \$newHash, 'member')"), 'Mitglied: Historie');
    $reset = $read('core/Services/CmsAuthPageService.php');
    T::check(str_contains($reset, 'isReused($resetUserId, $password)'), 'Zurücksetzen prüft Wiederverwendung');
    T::check(str_contains($reset, "recordChange(\$resetUserId, \$passwordHash, 'reset')"), 'Zurücksetzen schreibt Historie');
    T::check(str_contains($read('admin/views/users/settings.php'), "new RegExp(source || fallback, 'u')"), 'Live-Tester im Admin prüft mit Unicode-Flag wie der Server');
    $tab = $read('admin/settings-tabs/security.php');
    T::check(str_contains($tab, "CMS_ADMIN_PASSWORD_POLICY_CSRF_ACTION = 'admin_password_policy'") && str_contains($tab, 'verifyToken((string) ($_POST[\'csrf_token\'] ?? \'\'), CMS_ADMIN_PASSWORD_POLICY_CSRF_ACTION)'), 'Admin-Formular mit eigenem CSRF-Token');
    T::check(str_contains($tab, "'password_policy_saved'"), 'Speichern im Audit-Log');
});

T::finish();
