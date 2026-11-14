<?php
/**
 * Sicherheitsprüfungen für lokale Weiterleitungsziele (ohne Datenbank): zentraler Helfer
 * Security::safeLocalPath() sowie die Rücksprünge von Newsletter-Anmeldung und Formularen.
 * Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Security;
use CMS\Services\Forms\FormFrontendController;
use CMS\Services\Newsletter\NewsletterFrontendController;

/** Ziele, die ein Browser als fremden Host auflösen würde oder die keine lokalen Pfade sind. */
function cmsTestUnsafeRedirectTargets(): array
{
    return [
        'Tabulator' => "/\t/evil.example/login",
        'Zeilenumbruch' => "/\n/evil.example",
        'Wagenrücklauf' => "/\r/evil.example",
        'Vertikaltabulator' => "/\x0b/evil.example",
        'NUL' => "/\x00/evil.example",
        'DEL' => "/\x7f/evil.example",
        'Leerzeichen' => '/ /evil.example',
        'schemarelativ' => '//evil.example',
        'Backslash' => '/\\evil.example',
        'Backslash innen' => '/a\\b',
        'doppelter Schrägstrich im Pfad' => '/a//evil.example',
        'Schema' => 'https://evil.example',
        'javascript' => 'javascript:alert(1)',
        'relativ' => 'evil.example/x',
        'leer' => '',
        'überlang' => '/' . str_repeat('a', 1000),
        'kein String' => ['/x'],
    ];
}

T::run('Weiterleitung: Security::safeLocalPath() lässt nur lokale Pfade zu', static function (): void {
    foreach (cmsTestUnsafeRedirectTargets() as $label => $target) {
        T::same('', Security::safeLocalPath($target), $label . ' wird abgelehnt');
        T::same('/fallback', Security::safeLocalPath($target, '/fallback'), $label . ' fällt auf den Rückfallwert');
    }
    foreach (['/', '/blog/beitrag', '/blog/beitrag?x=1#y', '/suche?u=https://example.org//a', '/caf%C3%A9/%09x', '/en/kontakt/'] as $target) {
        T::same($target, Security::safeLocalPath($target), $target . ' bleibt erhalten');
    }
});

T::run('Weiterleitung: Newsletter-Rücksprung (return_to)', static function (): void {
    $safeReturn = new ReflectionMethod(NewsletterFrontendController::class, 'safeReturn');
    foreach (cmsTestUnsafeRedirectTargets() as $label => $target) {
        T::same('', $safeReturn->invoke(null, $target), $label . ' wird abgelehnt');
    }
    T::same('/blog/beitrag', $safeReturn->invoke(null, '/blog/beitrag?x=1#y'), 'Query und Fragment werden entfernt');
    T::same('/blog/beitrag', $safeReturn->invoke(null, ' /blog/beitrag '), 'Randleerzeichen werden entfernt');

    $source = (string) file_get_contents(ABSPATH . 'core/Services/Newsletter/NewsletterFrontendController.php');
    $start = (int) strpos($source, 'public static function subscribe(');
    $subscribe = substr($source, $start, (int) strpos($source, 'self::rateLimited()', $start) - $start);
    $csrf = strpos($subscribe, 'verifyToken(');
    $returnTo = strpos($subscribe, "\$_POST['return_to']");
    $honeypot = strpos($subscribe, "\$_POST['website']");
    T::check($csrf !== false && $returnTo !== false && $csrf < $returnTo, 'return_to wird erst nach der CSRF-Prüfung gelesen');
    T::check($csrf !== false && $honeypot !== false && $csrf < $honeypot, 'Honeypot-Zweig liegt hinter der CSRF-Prüfung');
    T::check(str_contains($subscribe, "self::redirect(\$locale, 'error');"), 'Token-Fehler leitet ohne return_to weiter');

    $start = (int) strpos($source, 'private static function redirect(');
    $redirect = substr($source, $start, (int) strpos($source, "header('Location", $start) - $start);
    T::check(str_contains($redirect, 'self::safeReturn($returnTo)'), 'redirect() prüft das Ziel vor dem Location-Header erneut');
});

T::run('Weiterleitung: Formular-Rücksprung (_return)', static function (): void {
    foreach (cmsTestUnsafeRedirectTargets() as $label => $target) {
        T::same('/contact/kontakt', FormFrontendController::safeReturnPath($target, '/contact/kontakt'), $label . ' wird abgelehnt');
    }
    foreach (['/admin', '/admin/users', '/Admin/users', '/api/v1/x', '/admin?x=1', '/api#x'] as $target) {
        T::same('/contact/kontakt', FormFrontendController::safeReturnPath($target, '/contact/kontakt'), $target . ' wird abgelehnt');
    }
    T::same('/seite?x=1', FormFrontendController::safeReturnPath('/seite?x=1#formular', '/'), 'Fragment wird entfernt, Query bleibt');
    T::same('/administration', FormFrontendController::safeReturnPath('/administration', '/'), '/administration ist kein Admin-Pfad');
});
