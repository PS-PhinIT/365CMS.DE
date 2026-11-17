<?php
/**
 * Slug-Erzeugung (CMS\Slug): Umlaut-Umschrift, Akzente, reine ASCII-Titel, Kürzung und Einbindung in PostsModule
 * (Beiträge, Kategorien, Tags), PageManager und PagesModule. Ohne Datenbank.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/slugs/run.php [-v]
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';
T::$verbose = in_array('-v', $argv ?? [], true);
T::installErrorGuard();

$root = dirname(__DIR__, 2) . '/CMS/';
if (!defined('ABSPATH')) {
    define('ABSPATH', $root);
}
require_once $root . 'core/Slug.php';

use CMS\Slug;

T::run('Umlaute und Akzente', static function (): void {
    T::same('uebersicht-groesse-der-postfaecher-pruefen', Slug::fromText('Übersicht: Größe der Postfächer prüfen'), 'Übersicht: Größe der Postfächer prüfen');
    T::same('cafe-creme', Slug::fromText('Café & Crème'), 'Café & Crème');
    T::same('groessenaenderung-fuer-exchange-postfaecher', Slug::fromText('Größenänderung für Exchange Postfächer'), 'Titel aus der Fehlermeldung');
    T::same('aerger-oel-ueber-strasse', Slug::fromText('ÄRGER ÖL ÜBER STRASSE'), 'Großbuchstaben-Umlaute');
    T::same('strasse', Slug::fromText('STRAẞE'), 'großes ẞ');
    T::same('groesse', Slug::fromText("Gro\u{0308}\u{00DF}e"), 'zerlegter Umlaut (o + U+0308)');
    T::same('senor-francois-zuerich-dvorak', Slug::fromText('Señor François Zürich Dvořák'), 'weitere Akzente');
    T::same('', Slug::fromText('日本語'), 'Schrift ohne Umschrift ergibt leeren Slug');
});

T::run('Reine ASCII-Titel unverändert', static function (): void {
    // Erwartung = frühere Logik: Kleinschreibung, alles außerhalb [a-z0-9-] → „-“, Bindestriche zusammenfassen.
    $legacy = static function (string $text): string {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? $slug;
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    };
    $cases = [
        'Exchange Online PowerShell v3',
        'Hello World',
        '  --Mehrfach   Leerzeichen--  ',
        'C# & .NET 9: Was ist neu?',
        'already-a-slug-2026',
        'Release_4.1.9 (Patch)',
    ];
    foreach ($cases as $case) {
        T::same($legacy($case), Slug::fromText($case), 'ASCII: ' . $case);
    }
    T::same('exchange-online-powershell-v3', Slug::fromText('Exchange Online PowerShell v3'), 'Beispiel explizit');
    foreach (['uebersicht-groesse-der-postfaecher-pruefen', 'cafe-creme', 'gr-en-nderung-f-r-exchange-postf-cher'] as $stored) {
        T::same($stored, Slug::fromText($stored), 'gespeicherter Slug bleibt: ' . $stored);
    }
});

T::run('Kürzung', static function (): void {
    $long = Slug::fromText(str_repeat('Größe ', 40), 100);
    T::check(strlen($long) <= 100, 'auf 100 Zeichen gekürzt (' . strlen($long) . ')');
    T::check(!str_ends_with($long, '-') && !str_starts_with($long, '-'), 'kein Bindestrich am Rand nach dem Kürzen');
    T::same('abc', Slug::fromText('abc-def', 4), 'Bindestrich am Schnitt entfernt');
    T::same('abc-def', Slug::fromText('abc-def', 0), '0 = ungekürzt');
});

T::run('Ohne intl und mbstring (php -n)', static function () use ($root): void {
    $probe = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg('echo class_exists("Normalizer") ? "1" : "0";')));
    if ($probe !== '0') {
        T::info('php -n lädt intl weiterhin, Rückfallpfad nicht prüfbar');

        return;
    }
    $code = 'define("ABSPATH", ' . var_export($root, true) . '); require ABSPATH . "core/Slug.php";'
        . ' echo json_encode([CMS\\Slug::fromText($argv[1]), CMS\\Slug::fromText($argv[2]), CMS\\Slug::fromText($argv[3])]);';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($code) . ' -- '
        . escapeshellarg('Übersicht: Größe der Postfächer prüfen') . ' ' . escapeshellarg('Café & Crème') . ' '
        . escapeshellarg('Exchange Online PowerShell v3'));
    T::same(['uebersicht-groesse-der-postfaecher-pruefen', 'cafe-creme', 'exchange-online-powershell-v3'], json_decode($out, true), 'Rückfall ohne Normalizer');
});

T::run('Einbindung', static function () use ($root): void {
    $posts = (string) file_get_contents($root . 'admin/modules/posts/PostsModule.php');
    T::check(str_contains($posts, 'use CMS\\Slug;'), 'PostsModule importiert CMS\\Slug');
    T::check(preg_match('/function generateSlug\(string \$text\): string\s*\{\s*return Slug::fromText\(\$text\);/', $posts) === 1, 'PostsModule::generateSlug nutzt Slug::fromText');
    T::check(preg_match('/function normalizeSlug\(string \$slug, int \$maxLength = 0\): string\s*\{\s*return Slug::fromText\(\$slug, \$maxLength\);/', $posts) === 1, 'PostsModule::normalizeSlug nutzt Slug::fromText');
    T::check(substr_count($posts, '$this->normalizeSlug($this->generateSlug($name), 100)') >= 2, 'Kategorien und Tags erzeugen den Slug über den Helfer');

    $pageManager = (string) file_get_contents($root . 'core/PageManager.php');
    T::check(preg_match('/function generateSlug\(string \$title\): string\s*\{\s*\$slug = Slug::fromText\(\$title\);/', $pageManager) === 1, 'PageManager::generateSlug nutzt Slug::fromText');

    $pages = (string) file_get_contents($root . 'admin/modules/pages/PagesModule.php');
    T::check(preg_match('/function normalizeSlug\(string \$slug\): string\s*\{\s*return Slug::fromText\(\$slug\);/', $pages) === 1, 'PagesModule::normalizeSlug nutzt Slug::fromText');

    T::check(!str_contains($posts . $pageManager . $pages, "preg_replace('/[^a-z0-9\\-]/', '-'"), 'keine eigene Ersetzung ohne Umschrift mehr');
});

T::finish();
