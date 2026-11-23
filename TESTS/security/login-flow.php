<?php
/**
 * Audit 4.2.0 (AP-01, Nachprüfung der offenen Punkte 01.2 und 01.10): Anmeldung ohne Konto-Orakel und mit
 * ausschließlich lokalen Rücksprungzielen. Ohne Datenbank. Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://example.test');
}

T::run('AP-01 / 01.2: Vergleichs-Hash für unbekannte Konten kostet so viel wie ein echter Passwort-Hash', static function (): void {
    // Unbekannter Benutzername und falsches Passwort laufen gleich lang, solange der Dummy-Hash dieselben Kosten hat
    // wie neu erzeugte Hashes. Hebt man die Kosten an und vergisst den Dummy, entsteht ein Zeit-Orakel für Benutzernamen.
    $dummy = (string) (new ReflectionClassConstant(\CMS\Auth::class, 'LOGIN_TIMING_DUMMY_HASH'))->getValue();
    $real = \CMS\Security::hashPassword('Beispiel-Passwort-1');

    $dummyInfo = password_get_info($dummy);
    $realInfo = password_get_info($real);
    T::same($realInfo['algoName'], $dummyInfo['algoName'], 'gleiches Verfahren');
    T::same($realInfo['options']['cost'] ?? null, $dummyInfo['options']['cost'] ?? 'fehlt', 'gleiche Kosten');
    T::check(!\CMS\Security::passwordNeedsRehash($dummy), 'Dummy-Hash entspricht dem aktuellen Soll der Hash-Parameter');
    T::check(!password_verify('Beispiel-Passwort-1', $dummy), 'Dummy-Hash passt auf kein übliches Passwort');
});

T::run('AP-01 / 01.10: Ziel nach der Anmeldung ist immer ein lokaler Pfad dieser Website', static function (): void {
    $class = new ReflectionClass(\CMS\Routing\PublicRouter::class);
    $router = $class->newInstanceWithoutConstructor();
    $normalize = $class->getMethod('normalizeAllowedRedirectTarget');

    $hostile = [
        '//evil.test/x', '///evil.test', 'https://evil.test/admin', '/\\evil.test', '/admin/\\evil.test',
        '/member?next=//evil.test', "/member\r\nSet-Cookie: x=1", '/member/%0d%0aSet-Cookie:x=1', '/admin/../../etc',
        'javascript:alert(1)', 'data:text/html,x', 'https://example.test.evil.test/member', "\x00/member",
    ];
    foreach ($hostile as $input) {
        $target = (string) $normalize->invoke($router, $input);
        $label = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . ' → ' . $target;
        T::check(str_starts_with($target, '/') && !str_starts_with($target, '//'), 'beginnt mit genau einem „/“: ' . $label);
        T::check(parse_url($target, PHP_URL_HOST) === null && parse_url($target, PHP_URL_SCHEME) === null, 'ohne Host und Schema: ' . $label);
        T::check(preg_match('/[\x00-\x1F\x7F\\\\]/', $target) !== 1, 'ohne Steuerzeichen und Rückwärtsstrich: ' . $label);
    }

    T::same('/member/profile?tab=1', (string) $normalize->invoke($router, 'https://example.test/member/profile?tab=1'), 'eigene absolute Adresse wird auf den Pfad gekürzt');
    T::same('/member', (string) $normalize->invoke($router, 'https://evil.test/member'), 'fremder Host fällt auf den Mitgliederbereich zurück');
    T::same('/member', (string) $normalize->invoke($router, ''), 'leeres Ziel: Mitgliederbereich');
});
