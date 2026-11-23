<?php
/**
 * Audit 4.2.0 (AP-05): Sicherheits-Header in zwei Schichten – Core (PHP) und Apache-Fallback (`CMS/.htaccess`).
 * Ohne Datenbank (Quelltext-Verträge). Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

/**
 * Globale Header-Direktiven der .htaccess (ohne `env=`-Bedingung): Name (klein) => [Direktive, Wert].
 *
 * @return array<string, array{0: string, 1: string}>
 */
$ap05Htaccess = static function (): array {
    $rules = [];
    foreach (file(ABSPATH . '.htaccess', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*Header\s+always\s+(set|setifempty)\s+([A-Za-z][A-Za-z-]*)\s+"([^"]*)"\s*$/', $line, $m) === 1) {
            $rules[strtolower($m[2])] = [$m[1], $m[3]];
        }
    }

    return $rules;
};

/**
 * Header, die der Core an mehreren Stellen mit unterschiedlichem oder berechnetem Wert setzt.
 *
 * @return array<string, list<string>> Name (klein) => Werte ('' = berechnet)
 */
$ap05PhpHeaderValues = static function (): array {
    $values = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php') || preg_match('#/(?:vendor|assets|uploads|cache|logs|backups)/#', $path) === 1) {
            continue;
        }
        $source = (string) file_get_contents($path);
        if (preg_match_all('/\bheader\(\s*([\'"])([A-Za-z][A-Za-z-]*):\s*([^\'"]*)/', $source, $matches, PREG_SET_ORDER) < 1) {
            continue;
        }
        foreach ($matches as $m) {
            $values[strtolower($m[2])][] = trim($m[3]);
        }
    }

    return $values;
};

T::run('AP-05 / 05.3: Apache-Fallback überschreibt keine Header, die der Core je Route anders setzt', static function () use ($ap05Htaccess, $ap05PhpHeaderValues): void {
    $rules = $ap05Htaccess();
    $php = $ap05PhpHeaderValues();

    // Feste Erwartung: Newsletter-Vorschau (SAMEORIGIN) und Seiten mit Token in der URL (no-referrer) hängen daran.
    foreach (['x-frame-options', 'referrer-policy'] as $name) {
        T::check(isset($rules[$name]), $name . ' ist in der .htaccess als Fallback gesetzt');
        T::same('setifempty', $rules[$name][0] ?? '', $name . ': Fallback ergänzt nur, überschreibt nicht (`setifempty`)');
        T::check(count(array_unique($php[$name] ?? [])) > 1, $name . ': der Core setzt je Route unterschiedliche Werte');
    }

    // Allgemein: jeder global gesetzte Header, den der Core mit mehreren Werten sendet, darf nicht mit `set` überschrieben werden.
    foreach ($rules as $name => [$directive, $value]) {
        $coreValues = array_values(array_unique($php[$name] ?? []));
        if (count($coreValues) > 1) {
            T::same('setifempty', $directive, $name . ': Core-Werte ' . implode(' | ', $coreValues) . ' brauchen `setifempty`');
        }
    }
});

T::run('AP-05 / 05.3: Core und Apache-Fallback senden dieselben festen Header', static function () use ($ap05Htaccess): void {
    $rules = $ap05Htaccess();
    $security = (string) file_get_contents(ABSPATH . 'core/Security.php');

    foreach (['X-Content-Type-Options' => 'nosniff', 'X-XSS-Protection' => '0', 'Cross-Origin-Opener-Policy' => 'same-origin', 'Cross-Origin-Resource-Policy' => 'same-site'] as $name => $expected) {
        T::same($expected, $rules[strtolower($name)][1] ?? '', $name . ' (.htaccess)');
        T::check(str_contains($security, "header('" . $name . ': ' . $expected . "');"), $name . ' (Security.php) = ' . $expected);
    }

    // Permissions-Policy: gleiche Verbote in beiden Schichten (Apache `always set` ersetzt sonst die Core-Werte).
    $directives = static function (string $value): array {
        $items = array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '');
        sort($items);

        return array_values($items);
    };
    $found = preg_match("/header\\('Permissions-Policy: ([^']+)'\\);/", $security, $m) === 1;
    T::check($found, 'Permissions-Policy wird im Core gesetzt');
    T::same($directives($rules['permissions-policy'][1] ?? ''), $directives($found ? $m[1] : ''), 'Permissions-Policy: Core und .htaccess stimmen überein');
});
