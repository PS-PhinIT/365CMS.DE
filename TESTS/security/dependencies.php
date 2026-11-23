<?php
/**
 * Abhängigkeitsüberwachung (ohne Datenbank, ohne Netzwerk): Die Bibliotheken liegen als Kopie unter CMS/assets/
 * bzw. CMS/vendor/dompdf/. Dependabot und Trivy sehen sie nur über die Spiegel-Manifeste in .github/deps/.
 * Diese Prüfungen halten Manifest, Lockdatei, Bundle und DOC/assets/README.md zusammen und sichern die
 * CI-Konfiguration ab. Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

$depsRoot = dirname(ABSPATH);
$depsRead = static fn (string $relative): string => (string) @file_get_contents($depsRoot . '/' . $relative);
$depsJson = static function (string $relative) use ($depsRead): array {
    $data = json_decode($depsRead($relative), true);

    return is_array($data) ? $data : [];
};
$depsNormalize = static fn (string $version): string => ltrim($version, 'v');

/**
 * Liest aus den Paket-Tabellen in DOC/assets/README.md die Spalte „Runtime-Stand“ (Paket => Version).
 * Zeilen ohne führende Versionsnummer (lokal gebaute Plugins) werden ausgelassen.
 */
$depsDocVersions = static function () use ($depsRead): array {
    $versions = [];
    foreach (preg_split('/\R/', $depsRead('DOC/assets/README.md')) ?: [] as $line) {
        if (preg_match('/^\| [^|]+ \| `([^` ]+)[^`]*` \| `CMS\/(?:assets|vendor)\/[^|]*\| (\d+(?:\.\d+)+)[^|]*\|/', $line, $m) === 1) {
            $versions[$m[1]] = $m[2];
        }
    }

    return $versions;
};

T::run('Abhängigkeiten: Dependabot überwacht nur vorhandene Manifeste', static function () use ($depsRoot, $depsRead): void {
    $config = $depsRead('.github/dependabot.yml');
    $count = preg_match_all('/package-ecosystem:\s*"([a-z-]+)"\s*\R\s*directory:\s*"([^"]+)"/', $config, $entries, PREG_SET_ORDER);
    T::check($count >= 3, 'dependabot.yml enthält Composer, npm und GitHub Actions', 'gefunden: ' . $count);

    $manifest = ['composer' => 'composer.json', 'npm' => 'package.json', 'github-actions' => '.github/workflows'];
    $seen = [];
    foreach ($entries as [, $ecosystem, $directory]) {
        $seen[] = $ecosystem;
        $path = $depsRoot . rtrim($directory, '/') . '/' . ($manifest[$ecosystem] ?? '???');
        T::check(file_exists($path), $ecosystem . ' → ' . $directory . ' hat ein Manifest', $path);
    }
    foreach (array_keys($manifest) as $ecosystem) {
        T::check(in_array($ecosystem, $seen, true), 'Ökosystem ' . $ecosystem . ' ist konfiguriert');
    }
    T::check(
        str_contains($depsRead('.gitignore'), '!.github/deps/package-lock.json'),
        '.gitignore nimmt .github/deps/package-lock.json von der package-lock-Regel aus'
    );
});

T::run('Abhängigkeiten: Composer-Manifest, Lockdatei und Bundles stimmen überein', static function () use ($depsJson, $depsRead, $depsNormalize, $depsDocVersions): void {
    $pins = $depsJson('.github/deps/composer.json')['require'] ?? [];
    T::check(count($pins) >= 45, 'composer.json führt die gebündelten PHP-Pakete', 'Anzahl: ' . count($pins));

    foreach ($pins as $package => $version) {
        T::check(preg_match('/^\d+(\.\d+)+$/', (string) $version) === 1, $package . ' ist exakt gepinnt', (string) $version);
    }

    $locked = [];
    foreach ($depsJson('.github/deps/composer.lock')['packages'] ?? [] as $package) {
        $locked[(string) $package['name']] = $depsNormalize((string) $package['version']);
    }
    ksort($locked);
    $expected = array_map('strval', $pins);
    ksort($expected);
    T::same($expected, $locked, 'composer.lock enthält genau die gepinnten Pakete (keine nicht gebündelten Abhängigkeiten)');

    // Einziges Paketinventar mit Versionen in der Runtime: Dompdf-Vendor-Bundle.
    foreach ($depsJson('CMS/vendor/dompdf/composer/installed.json')['packages'] ?? [] as $package) {
        $name = (string) $package['name'];
        T::same($depsNormalize((string) $package['version']), (string) ($pins[$name] ?? ''), 'Dompdf-Bundle ' . $name . ' entspricht dem Manifest');
    }

    preg_match("/const VERSION = '([^']+)'/", $depsRead('CMS/assets/htmlpurifier/HTMLPurifier.php'), $purifier);
    T::same($purifier[1] ?? '', (string) ($pins['ezyang/htmlpurifier'] ?? ''), 'HTMLPurifier::VERSION entspricht dem Manifest');
    T::same(
        (string) ($depsJson('CMS/assets/cron/composer.json')['version'] ?? ''),
        (string) ($pins['poliander/cron'] ?? ''),
        'poliander/cron entspricht dem Manifest'
    );

    $documented = $depsDocVersions();
    foreach ($pins as $package => $version) {
        if ($package === 'symfony/contracts') {
            continue; // in der Doku als Sammelzeile mit Zusatztext geführt, siehe nächste Prüfung
        }
        T::same((string) $version, (string) ($documented[$package] ?? ''), 'DOC/assets/README.md nennt ' . $package . ' ' . $version);
    }
    T::check(
        preg_match('/`symfony\/contracts [^`]*` \| `CMS\/assets\/symfony-contracts\/` \| ' . preg_quote((string) ($pins['symfony/contracts'] ?? '?'), '/') . ' \|/', $depsRead('DOC/assets/README.md')) === 1,
        'DOC/assets/README.md nennt symfony/contracts ' . ($pins['symfony/contracts'] ?? '?')
    );
});

/** Pakete des CommonMark-Bündels (PR #2b, CMS/assets/commonmark/commonmark.php). */
const DEPS_COMMONMARK_PACKAGES = ['league/commonmark', 'league/config', 'dflydev/dot-access-data', 'nette/schema', 'nette/utils'];

T::run('Abhängigkeiten: CommonMark-Bündel entspricht Lockdatei, Prüfsumme und Lizenz', static function () use ($depsRoot, $depsJson, $depsRead, $depsNormalize): void {
    $manifest = $depsJson('.github/deps/composer.json');
    $pins = $manifest['require'] ?? [];
    $lock = [];
    foreach ($depsJson('.github/deps/composer.lock')['packages'] ?? [] as $package) {
        $lock[(string) $package['name']] = ['version' => $depsNormalize((string) $package['version']), 'ref' => (string) ($package['dist']['reference'] ?? '')];
    }
    foreach (DEPS_COMMONMARK_PACKAGES as $package) {
        T::check(isset($pins[$package]) && preg_match('/^\d+\.\d+\.\d+$/', (string) $pins[$package]) === 1, 'composer.json pinnt ' . $package, (string) ($pins[$package] ?? '–'));
        T::same((string) ($pins[$package] ?? ''), $lock[$package]['version'] ?? '', 'composer.lock führt ' . $package . ' im gepinnten Stand');
        T::check(preg_match('/^[0-9a-f]{40}$/', $lock[$package]['ref'] ?? '') === 1, 'composer.lock nennt die dist-Referenz von ' . $package);
    }
    T::check(isset($manifest['replace']['symfony/polyfill-php80']), 'symfony/polyfill-php80 ist per replace ausgeblendet (PHP ≥ 8.4)');
    foreach (['psr/event-dispatcher', 'symfony/contracts'] as $provided) {
        T::check(isset($pins[$provided]), $provided . ' ist gepinnt (Abhängigkeit von league/commonmark, liegt außerhalb des Bündels)');
    }

    $bundle = str_replace(["\r\n", "\r"], "\n", $depsRead('CMS/assets/commonmark/commonmark.php'));
    $license = str_replace(["\r\n", "\r"], "\n", $depsRead('CMS/assets/commonmark/LICENSE'));
    T::check($bundle !== '' && $license !== '', 'CMS/assets/commonmark/commonmark.php und LICENSE liegen vor');
    $entries = array_values(array_diff(scandir($depsRoot . '/CMS/assets/commonmark') ?: [], ['.', '..']));
    sort($entries);
    T::same(['LICENSE', 'commonmark.php'], $entries, 'CMS/assets/commonmark/ enthält genau diese zwei Dateien');

    // Kopf des Bündels: je Paket eine Zeile „Paket Version dist-Ref“ wie in der Lockdatei, in der Reihenfolge des Build-Skripts.
    $head = substr($bundle, 0, (int) strpos($bundle, '*/'));
    preg_match_all('/^ \*   (\S+)\s+(\d+\.\d+\.\d+)\s+([0-9a-f]{40})$/m', $head, $rows, PREG_SET_ORDER);
    $header = [];
    foreach ($rows as [, $package, $version, $ref]) {
        $header[$package] = ['version' => $version, 'ref' => $ref];
    }
    T::same(DEPS_COMMONMARK_PACKAGES, array_keys($header), 'Kopf des Bündels nennt genau die fünf Pakete');
    T::same(array_intersect_key($lock, array_flip(DEPS_COMMONMARK_PACKAGES)), array_replace(array_flip(array_keys(array_intersect_key($lock, array_flip(DEPS_COMMONMARK_PACKAGES)))), $header), 'Kopfzeilen des Bündels (Version, dist-Referenz) = composer.lock');
    T::check(str_contains($head, 'GENERIERT, nicht von Hand ändern') && str_contains($head, 'build-commonmark-bundle.php'), 'Kopf kennzeichnet die Datei als generiert und nennt das Build-Skript');
    T::check(str_starts_with(substr($bundle, strlen($head)), "*/\n\ndeclare(strict_types=1);\n\nnamespace {\n    if (!\\defined('ABSPATH')) {\n        return;\n    }\n}\n"), 'nach dem Kopf: declare(strict_types=1) und Wächter ohne ABSPATH');
    T::same(1, substr_count($bundle, 'declare(strict_types=1);'), 'genau ein declare(strict_types=1)');

    // Nur Namensräume unter CMS\Vendor\ (außer dem Wächter-Block `namespace {`).
    preg_match_all('/^namespace\b(.*)$/m', $bundle, $spaces);
    $foreign = array_values(array_filter($spaces[1], static fn (string $rest): bool => $rest !== ' {' && preg_match('/^ CMS\\\\Vendor\\\\(?:League\\\\CommonMark|League\\\\Config|Dflydev\\\\DotAccessData|Nette)(?:\\\\[A-Za-z0-9_]+)* \{$/', $rest) !== 1));
    T::check(count($spaces[1]) > 100, 'Bündel besteht aus geklammerten namespace-Blöcken (' . count($spaces[1]) . ')');
    T::same([], $foreign, 'keine namespace-Zeile außer „namespace {“ und „namespace CMS\\Vendor\\…“');
    // Einfuhren auf oberster Ebene (Spalte 0) und voll qualifizierte Namen tragen das Präfix; Trait-Einfuhren in Klassen lösen über diese Aliase auf.
    $unscoped = [];
    $closing = 0;
    foreach (token_get_all($bundle) as $token) {
        if (is_array($token) && $token[0] === T_NAME_FULLY_QUALIFIED && preg_match('/^\\\\(?:League|Nette|Dflydev)\\\\/', $token[1]) === 1) {
            $unscoped[] = $token[1];
        }
        $closing += is_array($token) && $token[0] === T_CLOSE_TAG ? 1 : 0;
    }
    preg_match_all('/^use\s+((?:League|Nette|Dflydev)\\\\[^;]*);/m', $bundle, $imports);
    T::same([], array_values(array_unique(array_merge($unscoped, $imports[1]))), 'keine ungescopten Einfuhren oder voll qualifizierten Namen League\\, Nette\\, Dflydev\\');
    T::same(0, $closing, 'kein schließendes ?> im Bündel');

    // Prüfsummen über LF-normalisierte Bytes = Werte in DOC/assets/commonmark/README.md.
    $readme = $depsRead('DOC/assets/commonmark/README.md');
    preg_match_all('/^\| `(commonmark\.php|LICENSE)` \| (\d+) \| `([0-9a-f]{64})` \|$/m', $readme, $sums, PREG_SET_ORDER);
    $documented = [];
    foreach ($sums as [, $file, $bytes, $sha]) {
        $documented[$file] = [(int) $bytes, $sha];
    }
    T::same(['commonmark.php' => [strlen($bundle), hash('sha256', $bundle)], 'LICENSE' => [strlen($license), hash('sha256', $license)]], $documented, 'Bytes und SHA-256 von commonmark.php und LICENSE = DOC/assets/commonmark/README.md');
    foreach (DEPS_COMMONMARK_PACKAGES as $package) {
        T::check(str_contains($readme, '| `' . $package . '` | ' . ($lock[$package]['version'] ?? '?') . ' | `' . ($lock[$package]['ref'] ?? '?') . '` |'), 'DOC/assets/commonmark/README.md nennt ' . $package . ' mit Version und dist-Referenz');
        T::check(preg_match('/^-{72}\n' . preg_quote($package . ' ' . ($lock[$package]['version'] ?? '?') . ' (' . ($lock[$package]['ref'] ?? '?') . ')', '/') . '\n-{72}\n\n\S/m', $license) === 1, 'LICENSE enthält den Lizenztext von ' . $package . ' ' . ($lock[$package]['version'] ?? '?'));
    }
    T::check(preg_match('#^CMS/assets/commonmark/\* -text$#m', $depsRead('.gitattributes')) === 1, '.gitattributes: CMS/assets/commonmark/* -text (Zeilenenden stabil)');

    // Reproduzierbarkeit: mit einem Vendor-Verzeichnis aus der Lockdatei baut das Skript byte-gleich (sonst nur ein Hinweis).
    $script = $depsRoot . '/.github/deps/build-commonmark-bundle.php';
    T::check(is_file($script) && str_contains((string) file_get_contents($script), "'verify'"), 'Build-Skript .github/deps/build-commonmark-bundle.php mit --verify vorhanden');
    $vendor = (string) getenv('CMS_TEST_COMMONMARK_VENDOR');
    if ($vendor === '') {
        T::info('CommonMark-Bündel: Neubau übersprungen (CMS_TEST_COMMONMARK_VENDOR=<vendor> setzt die byte-gleiche Prüfung in Gang, Ablauf in DOC/assets/commonmark/README.md)');

        return;
    }
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --vendor=' . escapeshellarg($vendor) . ' --lock=.github/deps/composer.lock'
        . ' --out=CMS/assets/commonmark/commonmark.php --license=CMS/assets/commonmark/LICENSE --prefix=' . escapeshellarg('CMS\\Vendor')
        . ' --guard --roots-from=CMS/core/Services/Community/Support/ContentRenderer.php --verify 2>&1';
    $cwd = getcwd();
    chdir($depsRoot);
    exec($command, $output, $exit);
    chdir((string) $cwd);
    T::same(0, $exit, 'Neubau aus der Lockdatei ist byte-gleich zum ausgelieferten Bündel (--verify)', implode(' | ', $output));
});

T::run('Abhängigkeiten: npm-Manifest, Lockdatei und JS-Bundles stimmen überein', static function () use ($depsJson, $depsRead, $depsDocVersions): void {
    $pins = $depsJson('.github/deps/package.json')['dependencies'] ?? [];
    T::check(count($pins) >= 30, 'package.json führt die gebündelten JS-Pakete', 'Anzahl: ' . count($pins));

    $locked = [];
    foreach ($depsJson('.github/deps/package-lock.json')['packages'] ?? [] as $path => $package) {
        if ($path !== '') {
            $locked[substr((string) $path, strlen('node_modules/'))] = (string) ($package['version'] ?? '');
        }
    }
    ksort($locked);
    $expected = array_map('strval', $pins);
    ksort($expected);
    T::same($expected, $locked, 'package-lock.json enthält genau die gepinnten Pakete (nach prune-lock.mjs)');

    // Versionen, die sich aus den ausgelieferten Dateien selbst lesen lassen.
    $markers = [
        'dompurify' => ['CMS/assets/dompurify/purify.min.js', '/DOMPurify (\d+\.\d+\.\d+)/'],
        'photoswipe' => ['CMS/assets/photoswipe/photoswipe.esm.min.js', '/PhotoSwipe (\d+\.\d+\.\d+)/'],
        '@tabler/core' => ['CMS/assets/tabler/js/tabler.min.js', '/Tabler v(\d+\.\d+\.\d+)/'],
        'bootstrap' => ['CMS/assets/tabler/js/tabler.min.js', '/VERSION\(\)\{return"(\d+\.\d+\.\d+)"/'],
        '@tabler/icons-webfont' => ['CMS/assets/tabler-icons/tabler-icons.min.css', '/Tabler Icons (\d+\.\d+\.\d+)/'],
        '@editorjs/editorjs' => ['CMS/assets/editorjs/editorjs.umd.js', '/version:"(\d+\.\d+\.\d+)"/'],
    ];
    foreach ($markers as $package => [$file, $pattern]) {
        preg_match($pattern, $depsRead($file), $m);
        T::same($m[1] ?? '', (string) ($pins[$package] ?? ''), $package . ': Version im Bundle entspricht dem Manifest');
    }

    $documented = $depsDocVersions();
    $localBuilds = ['@editorjs/link', 'editorjs-hyperlink', 'editorjs-anchor', 'bootstrap', '@popperjs/core'];
    foreach ($pins as $package => $version) {
        if (in_array($package, $localBuilds, true)) {
            continue; // lokal gebaut bzw. in tabler.min.js enthalten: keine eigene Tabellenzeile mit exakter Version
        }
        T::same((string) $version, (string) ($documented[$package] ?? ''), 'DOC/assets/README.md nennt ' . $package . ' ' . $version);
    }
});

T::run('Abhängigkeiten: Trivy-Job bricht bei behebbaren HIGH/CRITICAL-Funden ab', static function () use ($depsRead): void {
    $workflow = $depsRead('.github/workflows/security.yml');
    $start = strpos($workflow, '  trivy-fs:');
    $job = substr($workflow, (int) $start, (int) strpos($workflow, '  syntax-check:') - (int) $start);
    $steps = preg_split('/\R(?=      - )/', $job) ?: [];

    $blocking = array_values(array_filter($steps, static fn (string $step): bool => str_contains($step, "exit-code: '1'")));
    T::check($start !== false && count($blocking) === 1, 'genau ein blockierender Trivy-Schritt');
    $step = $blocking[0] ?? '';
    T::check(str_contains($step, 'scan-ref: .github/deps'), 'blockierender Schritt scannt die Spiegel-Manifeste');
    T::check(preg_match('/severity: .*HIGH.*CRITICAL/', $step) === 1, 'Schwelle HIGH/CRITICAL');
    T::check(str_contains($step, 'ignore-unfixed: true'), 'nur behebbare Funde blockieren');

    preg_match_all('/uses:\s*(\S+)/', $workflow, $uses);
    foreach ($uses[1] as $action) {
        T::check(preg_match('/@[0-9a-f]{40}$/', $action) === 1, 'Action ist per Commit-SHA gepinnt: ' . $action);
    }
});

T::run('Abhängigkeiten: SunEditor ist der korrigierte Stand und lässt keine iframes zu', static function () use ($depsJson, $depsRead, $depsRoot): void {
    $version = (string) ($depsJson('.github/deps/package.json')['dependencies']['suneditor'] ?? '0');
    T::check(version_compare($version, '3.4.3', '>='), 'Manifest führt SunEditor >= 3.4.3', $version);

    $bundle = $depsRead('CMS/assets/suneditor/suneditor.min.js');
    // Merkmale der korrigierten Fassung: Option allowedURLProtocols (ab 3.4.0) und srcdoc-Filter.
    T::check(str_contains($bundle, 'allowedURLProtocols'), 'Bundle kennt allowedURLProtocols (ab 3.4.0)');
    T::check(str_contains($bundle, 'srcdoc'), 'Bundle filtert srcdoc');
    // Das Quellarchiv liegt seit dem Umzug von CMS_ASSETS in einem privaten Repository und ist hier nicht mehr prüfbar. Die Herkunft
    // belegen die dokumentierten SHA-256-Werte der Runtime-Dateien (unverändert aus dem npm-Paket übernommen).
    $suneditorDoc = $depsRead('DOC/assets/suneditor/README.md');
    foreach (['suneditor.min.js' => 'CMS/assets/suneditor/suneditor.min.js', 'css/suneditor.min.css' => 'CMS/assets/suneditor/css/suneditor.min.css', 'lang/de.js' => 'CMS/assets/suneditor/lang/de.js'] as $label => $path) {
        $documented = preg_match('/`' . preg_quote($label, '/') . '` `([0-9a-f]{64})`/', $suneditorDoc, $hashMatch) === 1 ? $hashMatch[1] : '';
        T::same($documented, hash('sha256', $depsRead($path)), 'SHA-256 von ' . $label . ' = DOC/assets/suneditor/README.md');
    }

    $service = $depsRead('CMS/core/Services/EditorService.php');
    T::check(str_contains($service, "elementBlacklist: 'iframe'"), 'EditorService sperrt iframe-Elemente');
    T::check(str_contains($service, 'data-se-iframe-holder-attrs'), 'EditorService sperrt die iframe-Platzhalter-Attribute');
    T::check(preg_match('/attributeWhitelist:\s*\{[^}]*iframe:/', $service) !== 1, 'attributeWhitelist enthält keinen iframe-Eintrag mehr');
});
