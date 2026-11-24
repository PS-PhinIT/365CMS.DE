<?php
/**
 * Audit 4.2.0 (AP-14, Punkt 14.4): Farbwerte für Hilfstexte und Abzeichen erreichen auf hellen Flächen den
 * Mindestkontrast 4,5:1 (WCAG 2.1 AA), und die schmale Ansicht (320 px) der Mitgliederseiten scrollt nicht seitlich.
 * Quelltextverträge gegen die Stylesheets; ohne Datenbank und ohne Browser.
 * Wird von TESTS/functions/run.php eingebunden.
 */

declare(strict_types=1);

/** Relative Leuchtdichte nach WCAG 2.1 für #rrggbb. */
function a11yLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $channel = static function (int $value): float {
        $c = $value / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $channel((int) hexdec(substr($hex, 0, 2))) + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2))) + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));
}

function a11yContrast(string $foreground, string $background): float
{
    $a = a11yLuminance($foreground);
    $b = a11yLuminance($background);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

/** Mischt $hex zu $percent Prozent mit Schwarz (wie `color-mix(in srgb, X 55%, #000)`). */
function a11yMixWithBlack(string $hex, int $percent): string
{
    $hex = ltrim($hex, '#');
    $out = '#';
    foreach ([0, 2, 4] as $offset) {
        $out .= str_pad(dechex((int) round(hexdec(substr($hex, $offset, 2)) * $percent / 100)), 2, '0', STR_PAD_LEFT);
    }

    return $out;
}

T::run('AP-14 / 14.4: Hilfstexte im Admin erreichen 4,5:1 auf allen hellen Flächen', static function (): void {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/CMS/assets/css/admin.css'));
    $surfaces = ['#ffffff', '#fafafa', '#f7f6f4', '#f6f8f9', '#f1f0ee', '#f1f1f1']; // dunkelste gemessene Flächen im Admin

    foreach (['--color-text-secondary', '--color-text-tertiary'] as $token) {
        $found = preg_match('/' . preg_quote($token, '/') . ':\s*(#[0-9a-fA-F]{6})\s*;/', $css, $m) === 1;
        T::check($found, $token . ' ist in admin.css definiert (sonst gilt der zu helle Rückfallwert)');
        foreach ($found ? $surfaces : [] as $surface) {
            T::check(a11yContrast($m[1], $surface) >= 4.5, sprintf('%s %s auf %s: %.2f:1 (Soll 4,5:1)', $token, $m[1], $surface, a11yContrast($m[1], $surface)));
        }
    }

    $shared = (string) file_get_contents(dirname(__DIR__, 2) . '/CMS/assets/css/a11y-contrast.css');
    T::check(preg_match('/--cms-a11y-muted:\s*(#[0-9a-fA-F]{6})\s*;/', $shared, $m) === 1 && a11yContrast($m[1], '#f1f0ee') >= 4.5, '--cms-a11y-muted erreicht 4,5:1 auf #f1f0ee');
});

T::run('AP-14 / 14.4: Helle Abzeichen (bg-*-lt) haben dunklen Text, Admin und Mitgliederbereich laden die Datei', static function (): void {
    $root = dirname(__DIR__, 2) . '/CMS';
    $shared = str_replace("\r\n", "\n", (string) file_get_contents($root . '/assets/css/a11y-contrast.css'));

    // Tabler-Grundfarben (CMS/assets/tabler) und ihre 10-%-Tönung auf Weiß; das gemischte Ergebnis muss 4,5:1 erreichen.
    $base = ['blue' => '#066fd1', 'azure' => '#4299e1', 'green' => '#2fb344', 'success' => '#2fb344', 'warning' => '#f59f00', 'danger' => '#d63939', 'secondary' => '#737373', 'primary' => '#066fd1'];
    foreach ($base as $variant => $hex) {
        T::check(preg_match('/\.bg-' . $variant . '-lt\s*\{\s*color:\s*color-mix\(in srgb, var\(--tblr-' . $variant . '\) 55%, #000\) !important;/', $shared) === 1, '.bg-' . $variant . '-lt: Text mit 55 % der Variantenfarbe und Schwarz');
        $tint = a11yMixWithBlack('#ffffff', 100); // Weiß; die Tönung ist heller als jede gemessene Fläche (#e2ecf5 und heller)
        T::check(a11yContrast(a11yMixWithBlack($hex, 55), '#dfe8f1') >= 4.5, sprintf('%s: 55-%%-Mischung %s auf #dfe8f1 erreicht 4,5:1', $variant, a11yMixWithBlack($hex, 55)));
        unset($tint);
    }

    foreach (['admin/partials/header.php', 'member/partials/header.php'] as $header) {
        T::check(str_contains((string) file_get_contents($root . '/' . $header), 'css/a11y-contrast.css'), $header . ' lädt css/a11y-contrast.css');
    }
});

T::run('AP-14 / 14.4: Mitgliederseiten laufen bei 320 px nicht über den Containerrand (Reflow)', static function (): void {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/CMS/assets/css/member-dashboard.css'));
    $found = preg_match('/@media \(max-width: 575\.98px\) \{\s*\.container-xl > \.row\.g-4,\s*\.member-dashboard > \.row\.g-4 \{\s*--tblr-gutter-x: 1rem;\s*--bs-gutter-x: 1rem;/', $css) === 1;
    T::check($found, 'Zeilen mit g-4 nutzen auf schmalen Schirmen ein Gutter von 1 rem (= Containerrand), nicht 1,5 rem');
});

T::run('AP-14 / 14.4: Links der Kontaktseite nutzen die Textfarbe des Akzents (AA auf hellen Flächen)', static function (): void {
    $root = dirname(__DIR__, 2) . '/CMS/themes/cms-default';
    $contact = str_replace("\r\n", "\n", (string) file_get_contents($root . '/contact.php'));
    T::check(str_contains($contact, '.contact-aside dd a{color:var(--accent-text,var(--accent))'), 'contact.php: Links in der Seitenleiste mit --accent-text (nicht mit dem Akzent #c0862a, 2,8:1 auf #f2f1ec)');

    $style = (string) file_get_contents($root . '/style.css');
    T::check(preg_match('/--accent-text:\s*(#[0-9a-fA-F]{6})/', $style, $m) === 1, '--accent-text ist definiert');
    foreach (['#ffffff', '#f2f1ec', '#fdf3e3'] as $surface) {
        T::check(isset($m[1]) && a11yContrast($m[1], $surface) >= 4.5, sprintf('--accent-text %s auf %s: %.2f:1', $m[1] ?? '?', $surface, isset($m[1]) ? a11yContrast($m[1], $surface) : 0.0));
    }
});
