<?php
/**
 * AVIF-Derivate (Release 4.1.0, Bereich 2a).
 *
 * Ohne Datenbank und ohne AVIF-fähiges GD: Kodierer, Probe, Abmessungen und Optionen werden als Closures übergeben,
 * Dateien liegen in einem temporären Upload-Verzeichnis. Geprüft werden Erkennung mit/ohne Unterstützung, „nur wenn
 * kleiner“, Pixel-Budget, Idempotenz, `<picture>`-Markup mit und ohne AVIF, Löschen der Derivate, Batch-Schritte und
 * – statisch – die Einbindung in Upload, Einstellungen, Medien-Werkzeuge, Renderer, Lightbox und Theme.
 *
 * Aufruf: php TESTS/media-avif/run.php [-v]
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
        public static array $actions = [];
        public static function applyFilters(string $tag, $value, ...$args): mixed { return $value; }
        public static function doAction(string $tag, ...$args): void { self::$actions[] = [$tag, $args]; }
    }');
}
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CMS\\')) {
        $file = $root . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use CMS\Services\Media\AvifDerivativeService as Avif;
use CMS\Services\Media\AvifRegenerationService;

$tmp = sys_get_temp_dir() . '/cms-avif-' . bin2hex(random_bytes(4));
mkdir($tmp . '/uploads/2026', 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tmp);
});
$uploads = $tmp . '/uploads/';

/** Datei mit fester Größe und optionalem Zeitstempel. */
$put = static function (string $path, int $bytes, ?int $mtime = null): void {
    file_put_contents($path, str_repeat('x', $bytes));
    if ($mtime !== null) {
        touch($path, $mtime);
    }
    clearstatcache(true, $path);
};

/**
 * @param array<string, int> $sizes Zielpfad-Endung => Bytes, die der Kodierer schreibt ('' = Standard)
 */
$make = static function (array $overrides = []) use ($uploads): Avif {
    $state = $overrides['state'] ?? new ArrayObject(['option' => null, 'calls' => 0]);

    return new Avif([
        'encoder' => $overrides['encoder'] ?? static function (string $source, string $target, int $q, int $s) use ($state): bool {
            $state['calls']++;
            file_put_contents($target, str_repeat('a', (int) ($state['bytes'] ?? 100)));

            return true;
        },
        'probe' => $overrides['probe'] ?? static fn (): bool => true,
        'dimensions' => $overrides['dimensions'] ?? static fn (string $path): ?array => [800, 600],
        'settings' => $overrides['settings'] ?? static fn (): array => ['auto_avif' => true, 'avif_quality' => 50, 'avif_speed' => 6],
        'optionGet' => static fn (): ?string => $state['option'],
        'optionSet' => static function (string $json) use ($state): void {
            $state['option'] = $json;
        },
        'uploadPath' => $uploads,
        'uploadUrl' => 'https://cms.example.test/uploads',
    ]);
};

T::run('Erkennung mit und ohne Unterstützung', static function () use ($make): void {
    $state = new ArrayObject(['option' => null]);
    $with = $make(['state' => $state, 'probe' => static fn (): bool => true]);
    T::check(!$with->isSupported(), 'ohne gespeicherte Probe: nicht unterstützt');
    $caps = $with->refreshCapabilities();
    T::same(true, $caps['avif'], 'Probe erfolgreich');
    T::check($with->isSupported(), 'Ergebnis in media_capabilities gespeichert');
    T::check($with->isEnabled(), 'Schalter an + Probe ok → aktiv');

    $state = new ArrayObject(['option' => null]);
    $without = $make(['state' => $state, 'probe' => static fn (): bool => false]);
    $without->refreshCapabilities();
    T::check(!$without->isSupported(), 'GD meldet imageavif, Probe scheitert → nicht unterstützt');
    T::check(!$without->isEnabled(), 'ohne Unterstützung nie aktiv, auch wenn der Schalter an ist');

    $throwing = $make(['state' => new ArrayObject(['option' => null]), 'probe' => static function (): bool {
        throw new RuntimeException('GD-Fehler');
    }]);
    T::same(false, $throwing->refreshCapabilities()['avif'], 'Ausnahme in der Probe → nicht unterstützt');
    T::check(!$make(['state' => new ArrayObject(['option' => '{kaputt'])])->isSupported(), 'beschädigte Option → nicht unterstützt');
    T::check(!$make(['state' => new ArrayObject(['option' => '{"avif":"1"}'])])->isSupported(), 'nur echtes true zählt');

    $off = $make(['state' => new ArrayObject(['option' => '{"avif":true}']), 'settings' => static fn (): array => []]);
    T::check(!$off->isEnabled(), 'Standard: Schalter aus');
    T::same(['enabled' => false, 'quality' => 50, 'speed' => 6], Avif::normalizeSettings([]), 'Standardwerte 50/6');
    T::same(['enabled' => true, 'quality' => 100, 'speed' => 0], Avif::normalizeSettings(['auto_avif' => '1', 'avif_quality' => 500, 'avif_speed' => -3]), 'Grenzen 1–100 und 0–10');
});

T::run('Erzeugung: nur behalten, wenn kleiner', static function () use ($make, $put, $uploads): void {
    $src = $uploads . '2026/bild.jpg';
    $webp = $uploads . '2026/bild.webp';
    $avifPath = $uploads . '2026/bild.avif';
    $put($src, 5000, time() - 100);
    $put($webp, 3000, time() - 50);

    $state = new ArrayObject(['option' => '{"avif":true}', 'calls' => 0, 'bytes' => 2000]);
    $service = $make(['state' => $state]);
    \CMS\Hooks::$actions = [];
    T::same(Avif::STATUS_CREATED, $service->generateOne($src, 50, 6), 'kleiner als WebP → erzeugt');
    T::check(is_file($avifPath) && filesize($avifPath) === 2000, 'AVIF-Datei liegt neben dem Original');
    T::same([['cms_media_derivative_created', [$avifPath, 'avif', 2000]]], \CMS\Hooks::$actions, 'Hook mit Pfad, Format, Bytes');
    T::same([], glob($uploads . '2026/*.tmp-*') ?: [], 'keine temporären Dateien');

    T::same(Avif::STATUS_EXISTS, $service->generateOne($src, 50, 6), 'zweiter Lauf: vorhanden (idempotent)');
    T::same(1, $state['calls'], 'kein zweites Kodieren');

    // Quelle neuer als AVIF → neu kodieren; jetzt größer als WebP → verworfen, alte AVIF-Datei entfernt.
    touch($src, time() + 10);
    clearstatcache();
    $state['bytes'] = 3500;
    T::same(Avif::STATUS_LARGER, $service->generateOne($src, 50, 6), 'größer als WebP → verworfen');
    T::check(!is_file($avifPath), 'veraltete AVIF-Datei entfernt');
    T::same([], glob($uploads . '2026/*.tmp-*') ?: [], 'temporäre Datei entfernt');

    $state['bytes'] = 3000;
    T::same(Avif::STATUS_LARGER, $service->generateOne($src, 50, 6), 'gleich groß wie WebP → verworfen');

    // Ohne WebP: Vergleich mit dem Ausgangsbild.
    unlink($webp);
    $state['bytes'] = 4000;
    T::same(Avif::STATUS_CREATED, $service->generateOne($src, 50, 6), 'ohne WebP: kleiner als das Original → erzeugt');
    unlink($avifPath);
    $state['bytes'] = 6000;
    T::same(Avif::STATUS_LARGER, $service->generateOne($src, 50, 6), 'ohne WebP: größer als das Original → verworfen');

    T::check(Avif::isWorthKeeping(1, 2) && !Avif::isWorthKeeping(2, 2) && !Avif::isWorthKeeping(0, 2) && !Avif::isWorthKeeping(1, 0), 'isWorthKeeping: echt kleiner, beide > 0');
});

T::run('Erzeugung: Pixel-Budget, Fehler, nicht unterstützte Dateien', static function () use ($make, $put, $uploads): void {
    $src = $uploads . '2026/riesig.png';
    $put($src, 9000);
    $state = new ArrayObject(['option' => '{"avif":true}', 'calls' => 0, 'bytes' => 10]);
    $big = $make(['state' => $state, 'dimensions' => static fn (): array => [6000, 5000]]);
    T::same(Avif::STATUS_SKIPPED, $big->generateOne($src, 50, 6), '30 MP > Budget → übersprungen');
    T::same(0, $state['calls'], 'Kodierer nicht aufgerufen');
    T::check(Avif::withinPixelBudget(5000, 5000) && !Avif::withinPixelBudget(5001, 5000) && !Avif::withinPixelBudget(0, 10), 'Budget 25 MP');
    T::same(Avif::STATUS_SKIPPED, $make(['dimensions' => static fn (): ?array => null])->generateOne($src, 50, 6), 'unlesbare Abmessungen → übersprungen');

    $failing = $make(['encoder' => static function (string $s, string $target): bool {
        file_put_contents($target, 'teil');

        return false;
    }]);
    T::same(Avif::STATUS_FAILED, $failing->generateOne($src, 50, 6), 'Kodierer meldet Fehler → failed');
    T::same([], glob($uploads . '2026/*.tmp-*') ?: [], 'Teil-Datei entfernt');
    $throwing = $make(['encoder' => static function (): bool {
        throw new RuntimeException('Speicher');
    }]);
    T::same(Avif::STATUS_FAILED, $throwing->generateOne($src, 50, 6), 'Ausnahme im Kodierer → failed');

    $put($uploads . '2026/dok.pdf', 100);
    T::same(Avif::STATUS_SKIPPED, $make()->generateOne($uploads . '2026/dok.pdf', 50, 6), 'kein Bild → übersprungen');
    $put($uploads . '2026/schon.avif', 100);
    T::same(Avif::STATUS_SKIPPED, $make()->generateOne($uploads . '2026/schon.avif', 50, 6), 'AVIF-Upload → übersprungen');
    T::same(Avif::STATUS_SKIPPED, $make()->generateOne($uploads . '2026/fehlt.jpg', 50, 6), 'fehlende Datei → übersprungen');
});

T::run('Erzeugung je Bildgröße', static function () use ($make, $put, $uploads): void {
    $src = $uploads . '2026/foto.jpg';
    $put($src, 5000);
    $put($uploads . '2026/foto-small.jpg', 800);
    $put($uploads . '2026/foto-large.jpg', 3000);
    $state = new ArrayObject(['option' => '{"avif":true}', 'calls' => 0, 'bytes' => 500]);
    $results = $make(['state' => $state])->generateForImage($src);
    T::same([$src, $uploads . '2026/foto-small.jpg', $uploads . '2026/foto-large.jpg'], array_keys($results), 'Original und vorhandene Größen');
    T::same([Avif::STATUS_CREATED, Avif::STATUS_CREATED, Avif::STATUS_CREATED], array_values($results), 'je Größe eine Fassung');
    T::check(is_file($uploads . '2026/foto-small.avif') && is_file($uploads . '2026/foto-large.avif'), 'foto-small.avif, foto-large.avif');
    T::check(Avif::isSizeVariant('/x/foto-small.jpg') && !Avif::isSizeVariant('/x/foto.jpg') && !Avif::isSizeVariant('/x/smalltalk.jpg'), 'Größen am Suffix erkannt');
});

T::run('<picture>-Markup mit und ohne AVIF', static function () use ($make, $put, $uploads): void {
    $put($uploads . '2026/kopf.jpg', 1000);
    $service = $make();
    $img = '<img src="https://cms.example.test/uploads/2026/kopf.jpg" alt="">';

    T::same([], $service->pictureSources('https://cms.example.test/uploads/2026/kopf.jpg'), 'ohne Fassungen keine Quellen');
    T::same($img, $service->pictureHtml('https://cms.example.test/uploads/2026/kopf.jpg', $img), 'ohne Fassungen bleibt das <img>');

    $put($uploads . '2026/kopf.webp', 800);
    T::same([['type' => 'image/webp', 'srcset' => 'https://cms.example.test/uploads/2026/kopf.webp']], $service->pictureSources('https://cms.example.test/uploads/2026/kopf.jpg'), 'nur WebP');

    $put($uploads . '2026/kopf.avif', 500);
    T::same(
        '<picture><source type="image/avif" srcset="https://cms.example.test/uploads/2026/kopf.avif"><source type="image/webp" srcset="https://cms.example.test/uploads/2026/kopf.webp">' . $img . '</picture>',
        $service->pictureHtml('https://cms.example.test/uploads/2026/kopf.jpg', $img),
        'AVIF vor WebP, <img> als letztes Kind'
    );
    T::same('/uploads/2026/kopf.avif', $service->pictureSources('/uploads/2026/kopf.jpg?v=3#x')[0]['srcset'] ?? null, 'relative URL, Query/Fragment entfernt');
    T::same([], $service->pictureSources('https://fremd.example/uploads/2026/kopf.jpg'), 'fremder Host → keine Quellen');
    T::same([], $service->pictureSources('https://cms.example.test/uploads/../config/kopf.jpg'), 'Pfad mit .. → keine Quellen');
    T::same([], $service->pictureSources('https://cms.example.test/uploads/2026/kopf.jpg"><script>'), 'Sonderzeichen → keine Quellen');
    T::same([], $service->pictureSources('https://cms.example.test/uploads/.private/kopf.jpg'), 'versteckter Ordner → keine Quellen');
    T::same([['type' => 'image/avif', 'srcset' => 'https://cms.example.test/uploads/2026/kopf.avif']], $service->pictureSources('https://cms.example.test/uploads/2026/kopf.webp'), 'WebP-Original: nur AVIF als Zusatzquelle');

    $put($uploads . '2026/a b.jpg', 1000);
    $put($uploads . '2026/a b.avif', 100);
    $html = $service->pictureHtml('/uploads/2026/a%20b.jpg', '<img src="/uploads/2026/a%20b.jpg">');
    T::check(str_contains($html, 'srcset="/uploads/2026/a%20b.avif"'), 'URL-kodierte Namen bleiben kodiert');
});

T::run('Löschen entfernt Derivate', static function () use ($make, $put, $uploads): void {
    $base = $uploads . '2026/del';
    $now = time();
    $put($base . '.jpg', 1000, $now - 100);
    foreach (['.webp', '.avif', '-small.jpg', '-small.avif', '-medium.jpg', '-medium.webp'] as $suffix) {
        $put($base . $suffix, 100, $now);
    }
    $put($uploads . '2026/del-small-eigen.jpg', 100, $now);
    $uploaded = [];
    $service = $make();
    $list = $service->derivativesToDelete($base . '.jpg', static fn (string $path): bool => in_array($path, $uploaded, true));
    sort($list);
    $expected = array_map(static fn (string $s): string => $base . $s, ['-medium.jpg', '-medium.webp', '-small.avif', '-small.jpg', '.avif', '.webp']);
    sort($expected);
    T::same($expected, $list, 'Größen, WebP und AVIF (auch der Größen)');

    $uploaded = [$base . '.webp'];
    $list = $service->derivativesToDelete($base . '.jpg', static fn (string $path): bool => in_array($path, $uploaded, true));
    T::check(!in_array($base . '.webp', $list, true), 'eigenständig hochgeladene Datei gleichen Namens bleibt');

    touch($base . '.avif', $now - 1000);
    clearstatcache();
    T::check(!in_array($base . '.avif', $service->derivativesToDelete($base . '.jpg', static fn (): bool => false), true), 'ältere Datei als das Original ist kein Derivat');
    T::same([], $service->derivativesToDelete($base . '-small.jpg', static fn (): bool => false), 'Löschen einer Größe löscht nichts mit');
    T::same([], $service->derivativesToDelete($uploads . '2026/dok.pdf', static fn (): bool => false), 'Nicht-Bild: nichts');
});

T::run('Batch „AVIF nachgenerieren“', static function (): void {
    $service = AvifRegenerationService::getInstance();
    $seen = [];
    $service->setDependencies(static function (string $path) use (&$seen): array {
        $seen[] = $path;
        if ($path === 'p13.jpg') {
            throw new RuntimeException('defekt');
        }

        return ['status' => $path === 'p2.jpg' ? 'skipped' : 'processed'];
    });
    $paths = array_map(static fn (int $i): string => 'p' . $i . '.jpg', range(0, 44));
    $job = ['state' => ['paths' => $paths, 'cursor' => 0, 'counts' => ['done' => 0, 'skipped' => 0, 'failed' => 0]]];

    $first = $service->runStep($job, 60.0);
    T::same([20, 20, false], [$first['processed'], $first['state']['cursor'], $first['done']], 'Schritt 1: 20 Bilder');
    T::same(['done' => 18, 'skipped' => 1, 'failed' => 1], $first['state']['counts'], 'Zähler (eine Ausnahme zählt als Fehler)');
    T::same(['p13.jpg: defekt'], $first['errors'], 'Fehlermeldung je Bild');

    $second = $service->runStep(['state' => $first['state']], 60.0);
    $third = $service->runStep(['state' => $second['state']], 60.0);
    T::same([20, 5, true], [$second['processed'], $third['processed'], $third['done']], 'Schritt 2 und 3, danach fertig');
    T::same($paths, $seen, 'jedes Bild genau einmal, in Reihenfolge');
    T::same([], $third['state']['paths'], 'Pfadliste nach dem Lauf geleert');

    $again = $service->runStep(['state' => $third['state']], 60.0);
    T::same([0, true], [$again['processed'], $again['done']], 'weiterer Schritt nach dem Ende: nichts doppelt');

    $slow = $service->runStep(['state' => ['paths' => ['a.jpg', 'b.jpg'], 'cursor' => 0]], 0.0);
    T::same(1, $slow['processed'], 'ohne Zeitbudget mindestens ein Bild je Schritt');
    $service->setDependencies(null);
});

T::run('Einbindung (statisch)', static function () use ($root): void {
    $read = static fn (string $file): string => (string) file_get_contents($root . $file);

    $media = $read('core/Services/MediaService.php');
    T::same(2, substr_count($media, '$this->generateUploadAvifDerivatives('), 'Upload: beide Upload-Wege erzeugen AVIF nach WebP');
    T::check(preg_match('/generateUploadWebpDerivative\(\$absolutePath, \$quality\);\s*\}\s*\$this->generateUploadAvifDerivatives\(/', $media) === 1, 'AVIF erst nach WebP (Vergleichsgröße)');
    T::check(str_contains($media, "'auto_avif' => false,"), 'Standard: AVIF aus');
    T::check(str_contains($media, '$derivatives = $this->collectDerivativesForDeletion($path);') && str_contains($media, "getFileMeta(\$relative)['uploaded_at']"), 'Löschen: Derivate ermittelt, hochgeladene Dateien geschützt');
    T::check(str_contains($media, 'public function processAvifJobItem('), 'Auftrag: Verarbeitung je Bild');

    $module = $read('admin/modules/media/MediaModule.php');
    T::check(str_contains($module, 'AvifDerivativeService::getInstance()->refreshCapabilities();'), 'Speichern der Einstellungen prüft AVIF neu (Probe)');
    $mediaPhp = $read('admin/media.php');
    T::check(str_contains($mediaPhp, "'auto_avif',") && str_contains($mediaPhp, "'avif_quality' => cms_admin_media_normalize_int(\$post[\$field] ?? 50, 1, 100, 50)"), 'Formularfelder erlaubt und begrenzt');
    $settingsView = $read('admin/views/media/settings.php');
    T::check(str_contains($settingsView, 'name="auto_avif"') && str_contains($settingsView, 'name="avif_quality"') && str_contains($settingsView, 'name="avif_speed"'), 'Einstellungen: Schalter, Qualität, Geschwindigkeit');

    $api = $read('admin/media-tools-api.php');
    T::check(str_contains($api, "(\$storedJob['type'] ?? '') === AvifRegenerationService::JOB_TYPE"), 'API: Schritte nach gespeichertem Auftragstyp');
    T::check(str_contains($api, "AvifDerivativeService::getInstance()->isSupported()"), 'API: Start nur mit Unterstützung');
    T::check(str_contains($read('core/Services/FeatureHooks/MediaFeatureHooks.php'), 'AvifRegenerationService::getInstance()->continueInCron()'), 'Cron setzt den Auftrag fort');
    $toolsView = $read('admin/views/media/tools.php');
    T::check(str_contains($toolsView, "\$mtAvifConfig['job'] = 'avif';") && !preg_match('/<script|\son[a-z]+=/i', $toolsView), 'Medien-Werkzeuge: AVIF-Karte ohne Inline-Skript');
    $toolsJs = $read('assets/js/admin-media-tools.js');
    T::check(str_contains($toolsJs, "querySelectorAll('[data-media-tools]').forEach(initCard)") && str_contains($toolsJs, "extra: config.job ?"), 'JS: mehrere Karten, job als Zusatzfeld');

    $renderer = $read('core/Services/EditorJsRenderer.php');
    T::same(3, substr_count($renderer, '$this->wrapPicture('), 'Renderer: Bild, Galerie, Medien-Text in <picture>');
    T::check(str_contains($read('assets/js/photoswipe-init.js'), "img.parentElement.tagName === 'PICTURE' ? img.parentElement : img"), 'Lightbox umschließt <picture> als Ganzes');
    T::check(str_contains($read('assets/css/editorjs-content.css'), '.editorjs-image picture,'), 'CSS: <picture> ohne Layout-Einfluss');

    $themeFunctions = $read('themes/cms-default/functions.php');
    T::check(str_contains($themeFunctions, "\$result['avif_url'] = meridian_safe_public_media_url(") && !str_contains($themeFunctions, 'convertToAvif('), 'Theme: vorhandene AVIF-Datei, nie zur Laufzeit erzeugt');
    $avifSources = 0;
    foreach (glob($root . 'themes/cms-default/{*,partials/*}.php', GLOB_BRACE) ?: [] as $template) {
        $content = (string) file_get_contents($template);
        $avifSources += substr_count($content, 'type="image/avif"');
        if (preg_match_all('/type="image\/avif">\s*<\?php endif; \?>\s*<\?php if \(\$\w+\[\'webp_url\'\]/', $content) !== substr_count($content, 'type="image/avif"')) {
            T::check(false, basename($template) . ': AVIF-Quelle steht vor der WebP-Quelle');
        }
    }
    T::same(8, $avifSources, 'Theme: AVIF-Quelle in allen <picture>-Vorlagen');

    foreach (['de', 'en'] as $lang) {
        $yaml = $read("lang/{$lang}.yaml");
        foreach (['media_tools.avif.title', 'media_tools.avif.unsupported', 'media_tools.avif.summary'] as $key) {
            T::check(str_contains($yaml, "\"{$key}\":"), "{$lang}: {$key}");
        }
    }
});

T::finish();
