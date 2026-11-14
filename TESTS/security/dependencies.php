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
    T::check(is_file($depsRoot . '/CMS_ASSETS/suneditor-' . $version . '.zip'), 'Quellarchiv CMS_ASSETS/suneditor-' . $version . '.zip liegt vor');

    $service = $depsRead('CMS/core/Services/EditorService.php');
    T::check(str_contains($service, "elementBlacklist: 'iframe'"), 'EditorService sperrt iframe-Elemente');
    T::check(str_contains($service, 'data-se-iframe-holder-attrs'), 'EditorService sperrt die iframe-Platzhalter-Attribute');
    T::check(preg_match('/attributeWhitelist:\s*\{[^}]*iframe:/', $service) !== 1, 'attributeWhitelist enthält keinen iframe-Eintrag mehr');
});
