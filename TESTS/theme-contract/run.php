<?php
/**
 * 365CMS 3.4 – Theme-Vertrag (CSP, Layout-Hooks, Versionen)
 *
 * Aufruf: php TESTS/theme-contract/run.php [weitere Theme-Wurzelverzeichnisse …]
 * Ohne Argumente werden die mitgelieferten Themes unter CMS/themes geprüft.
 */
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
require ABSPATH . 'core/Hooks.php';

$failures = [];
$fail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
};

// ── 1) Hooks: before_footer/body_end nur einmal pro Request ──────────────────
$calls = [];
\CMS\Hooks::addAction('before_footer', static function () use (&$calls): void { $calls[] = 'before_footer'; });
\CMS\Hooks::addAction('body_end', static function () use (&$calls): void { $calls[] = 'body_end'; });
\CMS\Hooks::addAction('head', static function () use (&$calls): void { $calls[] = 'head'; });
\CMS\Hooks::addAction('head', static function () use (&$calls): void { $calls[] = 'head-first'; }, PHP_INT_MIN);
foreach (['head', 'head', 'before_footer', 'before_footer', 'body_end', 'body_end'] as $tag) {
    \CMS\Hooks::doAction($tag);
}
if ($calls !== ['head-first', 'head', 'head-first', 'head', 'before_footer', 'body_end']) {
    $fail('Hooks: unerwartete Reihenfolge/Anzahl: ' . implode(',', $calls));
}
if (\CMS\Hooks::didAction('body_end') !== 1 || \CMS\Hooks::didAction('unbekannt') !== 0) {
    $fail('Hooks::didAction() liefert falsche Werte.');
}

// ── 2) Themes statisch prüfen ────────────────────────────────────────────────
$roots = array_slice($argv, 1);
if ($roots === []) {
    $roots = [ABSPATH . 'themes'];
}

$read = static fn(string $file): string => is_file($file) ? str_replace("\r\n", "\n", (string) file_get_contents($file)) : '';
$themeCount = 0;

foreach ($roots as $root) {
    foreach (glob(rtrim($root, '/\\') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!is_file($dir . '/theme.json') || !is_file($dir . '/header.php')) {
            continue;
        }
        $themeCount++;
        $slug = basename($dir);
        $header = $read($dir . '/header.php');
        $footer = $read($dir . '/footer.php');

        if (!str_contains($header, 'cms_csp_runtime_tags')) {
            $fail("$slug: header.php gibt die CSP-Runtime (cms_csp_runtime_tags) nicht aus.");
        }
        if (!str_contains($footer, "doAction('body_end')")) {
            $fail("$slug: footer.php löst body_end nicht vor </body> aus.");
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = (string) $file;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['php', 'js'], true)) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $path), strlen(str_replace('\\', '/', $dir)) + 1);
            $code = $read($path);

            if ($ext === 'php') {
                // Rahmen: Der Core rendert header/footer; nur error.php (Fatal-Handler) bindet selbst ein.
                if (!str_contains($relative, '/') && !in_array($relative, ['functions.php', 'header.php', 'footer.php', 'error.php'], true)
                    && preg_match('/^\s*(?:<\?php\s+)?get_(?:header|footer)\(\)\s*;/m', $code) === 1) {
                    $fail("$slug/$relative: Template ruft get_header()/get_footer() selbst auf (doppelter Seitenrahmen).");
                }
                if (preg_match_all('/<(style|script)\b(?![^>]*\bsrc\s*=)/i', $code, $tags, PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($tags[0] as [$tag, $offset]) {
                        $lineStart = strrpos(substr($code, 0, $offset), "\n");
                        $line = ltrim(substr($code, $lineStart === false ? 0 : $lineStart + 1, 200));
                        if (preg_match('#^(\*|//|/\*|\#)#', $line) === 1) {
                            continue; // Kommentar/Doku
                        }
                        $window = substr($code, $offset, 260);
                        $tagEnd = preg_match('/^<(?:style|script)\b(?:\?>|->|<\?|"[^"]*"|\'[^\']*\'|[^>"\'])*?>/i', $window, $tm) === 1 ? $tm[0] : $window;
                        if (stripos($tagEnd, 'nonce') === false && preg_match('/type\s*=\s*["\']?(?:application\/(?:ld\+)?json|text\/template)/i', $tagEnd) !== 1
                            && preg_match('/^<(style|script)\b[^>]*>\s*[\'"]?\s*[,)\]]/i', $window) !== 1) {
                            $fail("$slug/$relative: Inline-Tag ohne CSP-Nonce: " . strtok($window, "\n"));
                        }
                    }
                }
                if (preg_match('/<[a-z][^>]*\son(?:click|change|input|submit|load|error|mouse\w*|key\w*|focus|blur)\s*=\s*["\']/i', $code, $m) === 1) {
                    $fail("$slug/$relative: Inline-Event-Handler (CSP): " . substr($m[0], 0, 80));
                }
                if (preg_match('/href\s*=\s*["\']javascript:/i', $code) === 1) {
                    $fail("$slug/$relative: javascript:-URL (CSP).");
                }
                foreach (['renderAdminSidebar', 'renderAdminSidebarStyles'] as $legacy) {
                    if (preg_match('/(?<!function )\b' . $legacy . '\s*\(/', $code) === 1 && !str_contains($code, "function_exists('$legacy')")) {
                        $fail("$slug/$relative: Aufruf der entfernten Legacy-Admin-API $legacy().");
                    }
                }
            }
            if ($ext === 'js' && preg_match('/\beval\s*\(|new\s+Function\s*\(/', $code) === 1) {
                $fail("$slug/$relative: eval()/new Function() ist unter der CSP blockiert.");
            }
        }

        // Versionen synchron
        $themeJson = json_decode($read($dir . '/theme.json'), true);
        $version = is_array($themeJson) ? (string) ($themeJson['version'] ?? '') : '';
        if ($version === '') {
            $fail("$slug: theme.json ohne Version.");
            continue;
        }
        if (is_file($dir . '/update.json')) {
            $updateJson = json_decode($read($dir . '/update.json'), true);
            if (!is_array($updateJson)) {
                $fail("$slug: update.json ist kein gültiges JSON.");
            } else {
                if ((string) ($updateJson['version'] ?? '') !== $version) {
                    $fail("$slug: update.json-Version " . ($updateJson['version'] ?? '?') . " ≠ theme.json $version.");
                }
                $requires = (string) ($updateJson['requires_cms'] ?? $updateJson['min_cms_version'] ?? '');
                if ($requires === '' || version_compare($requires, '3.4.00', '<')) {
                    $fail("$slug: update.json verlangt nicht mindestens 365CMS 3.4.00 ('$requires').");
                }
            }
        }
        if (preg_match('/^\s*Version:\s*(\S+)/mi', $read($dir . '/style.css'), $m) === 1 && $m[1] !== $version) {
            $fail("$slug: style.css-Version {$m[1]} ≠ theme.json $version.");
        }
        if (preg_match("/define\\('[A-Z0-9_]+_(?:THEME_)?VERSION',\\s*'([^']+)'/", $read($dir . '/functions.php'), $m) === 1 && $m[1] !== $version) {
            $fail("$slug: functions.php-Versionskonstante {$m[1]} ≠ theme.json $version.");
        }
    }
}

if ($themeCount === 0) {
    $fail('Keine Themes gefunden in: ' . implode(', ', $roots));
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    fwrite(STDERR, count($failures) . " Verstoß/Verstöße in $themeCount Theme(s)." . PHP_EOL);
    exit(1);
}

echo "Theme contract tests passed ($themeCount themes)." . PHP_EOL;
