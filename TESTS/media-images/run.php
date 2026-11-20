<?php
/**
 * Bildverarbeitung beim Upload: Transparenz, EXIF-Ausrichtung und `strip_exif`.
 *
 * Ohne Datenbank: Die Bilder entstehen zur Laufzeit mit GD (RGBA-PNG; JPEG mit eingefügtem APP1-EXIF-Block mit
 * Ausrichtung 6 und GPS, dazu XMP, IPTC, Kommentar und angehängten Daten). `MediaService` wird ohne Konstruktor
 * angelegt; geprüft werden die Schritte nach dem Speichern der Upload-Datei (`repairUploadedBrowserImage()` für Logos
 * und Editor-Dateien, `processManagedImageUpload()` für Mediathek und Mitgliederbereich, `writeReplacementImage()` für
 * den Bildersatz), `ImageProcessor`, `ImageOrientation` (alle acht Werte), `ImageMetadataStripper` (JPEG, PNG, WebP),
 * `ImageService` und – statisch – die Einbindung in die Upload-Wege und die Oberfläche.
 *
 * Ohne `ext-gd` werden die Prüfungen übersprungen (Hinweis), ohne `ext-exif` die Prüfungen der Ausrichtung.
 *
 * Aufruf: php TESTS/media-images/run.php [-v]
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';
T::$verbose = in_array('-v', $argv ?? [], true);

if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
    T::info('ext-gd fehlt: Bildprüfungen übersprungen');
    T::finish();
}

$root = dirname(__DIR__, 2) . '/CMS/';
if (!defined('ABSPATH')) {
    define('ABSPATH', $root);
}
if (!class_exists('CMS\\Hooks')) {
    eval('namespace CMS; final class Hooks {
        public static function applyFilters(string $tag, $value, ...$args): mixed { return $value; }
        public static function doAction(string $tag, ...$args): void {}
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

use CMS\Services\ImageService;
use CMS\Services\Media\ImageMetadataStripper as Stripper;
use CMS\Services\Media\ImageOrientation as Orientation;
use CMS\Services\Media\ImageProcessor;
use CMS\Services\MediaService;

$exif = function_exists('exif_read_data');
if (!$exif) {
    T::info('ext-exif fehlt: Prüfungen der EXIF-Ausrichtung übersprungen');
}

$tmp = sys_get_temp_dir() . '/cms-media-images-' . bin2hex(random_bytes(4));
mkdir($tmp . '/uploads', 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tmp);
});
$uploads = $tmp . '/uploads/';

// ── Hilfen: Objekte ohne Konstruktor, private Methoden ──────────────────────

/** Objekt ohne Konstruktor anlegen und private Eigenschaften im Klassenkontext setzen. */
$instance = static function (string $class, array $properties): object {
    $object = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    \Closure::bind(function () use ($properties): void {
        foreach ($properties as $name => $value) {
            $this->{$name} = $value;
        }
    }, $object, $class)();

    return $object;
};
/** Private Methode im Klassenkontext aufrufen. */
$call = static function (object $object, string $method, array $args = []): mixed {
    return \Closure::bind(fn () => $this->{$method}(...$args), $object, get_class($object))();
};

$logger = new class implements \CMS\Contracts\LoggerInterface {
    /** @var list<string> */
    public array $warnings = [];
    public function emergency(string $message, array $context = []): void { $this->warnings[] = $message; }
    public function alert(string $message, array $context = []): void { $this->warnings[] = $message; }
    public function critical(string $message, array $context = []): void { $this->warnings[] = $message; }
    public function error(string $message, array $context = []): void { $this->warnings[] = $message; }
    public function warning(string $message, array $context = []): void { $this->warnings[] = $message . ' ' . json_encode($context); }
    public function notice(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void {}
    public function debug(string $message, array $context = []): void {}
    public function log(string $level, string $message, array $context = []): void {}
};
$media = $instance(MediaService::class, [
    'uploadPath' => rtrim($uploads, '/'),
    'uploadUrl' => 'https://cms.example.test/uploads',
    'settingsFile' => $tmp . '/media-settings.json',
    'metaFile' => $tmp . '/media-meta.json',
    'logger' => $logger,
    'imageProcessor' => new ImageProcessor(),
]);
/** Wirksame Medien-Einstellungen (Standard + Abweichungen), wie sie `getSettings()` liefert. */
$settings = static function (array $overrides = []) use ($media, $call): array {
    return array_merge($call($media, 'getDefaultSettings'), ['auto_avif' => false], $overrides);
};

// ── Hilfen: Testbilder ──────────────────────────────────────────────────────

/** Kodierte Ausgabe einer GD-Schreibfunktion als Zeichenkette. */
$encode = static function (callable $writer): string {
    ob_start();
    $writer();

    return (string) ob_get_clean();
};

/**
 * RGBA-PNG 40×20: vollständig transparent, ein halbtransparenter Streifen (Alpha 63) und ein deckender Block oben links.
 */
$rgbaImage = static function (): \GdImage {
    $image = imagecreatetruecolor(40, 20);
    imagealphablending($image, false);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 255, 127));
    imagefilledrectangle($image, 20, 0, 39, 19, imagecolorallocatealpha($image, 0, 128, 0, 63));
    imagefilledrectangle($image, 0, 0, 5, 5, imagecolorallocatealpha($image, 255, 0, 0, 0));
    imagesavealpha($image, true);

    return $image;
};
$rgbaPng = static function () use ($rgbaImage, $encode): string {
    $image = $rgbaImage();

    return $encode(static fn () => imagepng($image));
};
/** Alpha (0 deckend … 127 transparent) eines Pixels. */
$alpha = static fn (\GdImage $image, int $x, int $y): int => (imagecolorat($image, $x, $y) >> 24) & 0x7F;
$loadPng = static fn (string $path): \GdImage => imagecreatefrompng($path);

/**
 * Gespeichertes (quer liegendes) Foto 160×80: weißer Grund, roter Block oben links (0–39, 0–39). Mit Ausrichtung 6
 * erscheint es im Browser als 80×160-Hochformat mit dem roten Block oben rechts.
 */
$photoJpeg = static function () use ($encode): string {
    $image = imagecreatetruecolor(160, 80);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagefilledrectangle($image, 0, 0, 39, 39, imagecolorallocate($image, 255, 0, 0));

    return $encode(static fn () => imagejpeg($image, null, 95));
};
/** Ist das Pixel an der relativen Position (Anteil von Breite/Höhe) deutlich rot? JPEG verschiebt die Werte leicht. */
$redAt = static function (\GdImage $image, float $fx, float $fy): bool {
    $rgb = imagecolorat($image, (int) (imagesx($image) * $fx), (int) (imagesy($image) * $fy));
    if (!imageistruecolor($image)) {
        $c = imagecolorsforindex($image, $rgb);
        $rgb = ($c['red'] << 16) | ($c['green'] << 8) | $c['blue'];
    }

    return (($rgb >> 16) & 0xFF) > 180 && (($rgb >> 8) & 0xFF) < 90 && ($rgb & 0xFF) < 90;
};
/** Rot oben rechts, Weiß oben links und unten rechts: aufrecht dargestelltes Hochformat. */
$uprightPortrait = static function (\GdImage $image) use ($redAt): bool {
    return imagesy($image) === 2 * imagesx($image)
        && $redAt($image, 0.75, 0.125) && !$redAt($image, 0.25, 0.125) && !$redAt($image, 0.75, 0.875);
};

/**
 * TIFF-Block (Little Endian) mit Make, Orientation und GPS-IFD (Breite/Länge).
 */
$tiffWithGps = static function (int $orientation): string {
    $entry = static fn (int $tag, int $type, int $count, string $value): string => pack('vvV', $tag, $type, $count) . $value;
    $rational = static fn (array $values): string => implode('', array_map(static fn (array $r): string => pack('VV', $r[0], $r[1]), $values));
    // IFD0 bei 8: 3 Einträge → 2 + 36 + 4 = 42 Bytes, Make bei 50, GPS-IFD bei 58 (5 Einträge → 66 Bytes), Daten ab 124.
    $ifd0 = pack('v', 3)
        . $entry(0x010F, 2, 8, pack('V', 50))
        . $entry(0x0112, 3, 1, pack('v', $orientation) . "\x00\x00")
        . $entry(0x8825, 4, 1, pack('V', 58))
        . pack('V', 0);
    $gps = pack('v', 5)
        . $entry(0x0000, 1, 4, "\x02\x03\x00\x00")
        . $entry(0x0001, 2, 2, "N\x00\x00\x00")
        . $entry(0x0002, 5, 3, pack('V', 124))
        . $entry(0x0003, 2, 2, "E\x00\x00\x00")
        . $entry(0x0004, 5, 3, pack('V', 148))
        . pack('V', 0);

    return "II\x2A\x00" . pack('V', 8) . $ifd0 . "TestCam\x00" . $gps
        . $rational([[48, 1], [7, 1], [3000, 100]]) . $rational([[8, 1], [12, 1], [4500, 100]]);
};
/** JPEG-Segment (Marker + Länge + Nutzdaten). */
$segment = static fn (int $marker, string $payload): string => "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
/**
 * Foto mit APP1-EXIF (Ausrichtung, GPS), XMP, ICC-Profil (APP2), IPTC (APP13), Kommentar und angehängten Daten.
 */
$photoWithMetadata = static function (int $orientation) use ($photoJpeg, $tiffWithGps, $segment): string {
    $jpeg = $photoJpeg();
    $app0End = 4 + (int) unpack('n', substr($jpeg, 4, 2))[1];
    $metadata = $segment(0xE1, "Exif\0\0" . $tiffWithGps($orientation))
        . $segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta><exif:GPSLatitude>48,7.5N</exif:GPSLatitude></x:xmpmeta>")
        . $segment(0xE2, "ICC_PROFILE\0\x01\x01TEST-ICC-DATA")
        . $segment(0xED, "Photoshop 3.0\08BIM\x04\x04\x00\x00\x00\x00\x00\x0C\x1C\x02\x50\x00\x07Fotograf")
        . $segment(0xFE, 'geheimer Kommentar');

    return substr($jpeg, 0, $app0End) . $metadata . substr($jpeg, $app0End) . 'ANGEHAENGT:GPS=48.1,8.2';
};
/** Komprimierte Bilddaten eines JPEG (ab dem ersten SOS bis einschließlich EOI). */
$scanData = static function (string $jpeg): string {
    $start = strpos($jpeg, "\xFF\xDA");
    $end = strrpos($jpeg, "\xFF\xD9");

    return $start === false || $end === false ? '' : substr($jpeg, $start, $end + 2 - $start);
};
/** EXIF-Daten einer Datei ohne Warnungen (leer, wenn keine). */
$readExif = static function (string $path): array {
    set_error_handler(static fn (): bool => true);
    try {
        $data = exif_read_data($path);
    } finally {
        restore_error_handler();
    }

    return is_array($data) ? $data : [];
};
$gpsKeys = static fn (array $exifData): array => array_values(array_filter(array_keys($exifData), static fn (string $key): bool => str_starts_with($key, 'GPS')));

/** PNG-Chunk. */
$pngChunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
/** PNG-Chunks als Liste [Typ, Daten]. */
$pngChunks = static function (string $png): array {
    $chunks = [];
    for ($pos = 8; $pos + 12 <= strlen($png);) {
        $length = (int) unpack('N', substr($png, $pos, 4))[1];
        $chunks[] = [substr($png, $pos + 4, 4), substr($png, $pos + 8, $length)];
        $pos += $length + 12;
    }

    return $chunks;
};
/** RGBA-PNG mit eXIf (GPS), tEXt, zTXt, iTXt (XMP), tIME direkt nach IHDR und angehängten Daten. */
$pngWithMetadata = static function (int $orientation) use ($rgbaPng, $pngChunk, $tiffWithGps): string {
    $png = $rgbaPng();
    $ihdrEnd = 8 + 12 + 13;
    $metadata = $pngChunk('eXIf', $tiffWithGps($orientation))
        . $pngChunk('tEXt', "Author\0Max Mustermann")
        . $pngChunk('zTXt', "Comment\0\0" . (string) gzcompress('Standort: Schwarzwald'))
        . $pngChunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0<x:xmpmeta>GPS 48.1</x:xmpmeta>")
        . $pngChunk('tIME', pack('nCCCCC', 2026, 11, 20, 12, 0, 0));

    return substr($png, 0, $ihdrEnd) . $metadata . substr($png, $ihdrEnd) . 'ANGEHAENGT';
};

/** WebP-Chunks als Liste [FourCC, Daten]. */
$webpChunks = static function (string $webp): array {
    $chunks = [];
    for ($pos = 12; $pos + 8 <= strlen($webp);) {
        $size = (int) unpack('V', substr($webp, $pos + 4, 4))[1];
        $chunks[] = [substr($webp, $pos, 4), substr($webp, $pos + 8, $size)];
        $pos += 8 + $size + $size % 2;
    }

    return $chunks;
};
/** RGBA-WebP (erweitertes Format) mit EXIF- und XMP-Chunk und gesetzten VP8X-Flags. */
$webpWithMetadata = static function (int $orientation) use ($rgbaImage, $encode, $webpChunks, $tiffWithGps): string {
    $image = $rgbaImage();
    $webp = $encode(static fn () => imagewebp($image, null, 90));
    $chunks = $webpChunks($webp);
    if ($chunks[0][0] !== 'VP8X') {
        // Einfaches Format: VP8X-Kopf voranstellen (Flags, 3 Byte reserviert, Breite-1 und Höhe-1 je 24 Bit).
        array_unshift($chunks, ['VP8X', "\x00\x00\x00\x00" . substr(pack('V', 39), 0, 3) . substr(pack('V', 19), 0, 3)]);
    }
    $chunks[0][1][0] = chr(ord($chunks[0][1][0]) | 0x08 | 0x04);
    $chunks[] = ['EXIF', $tiffWithGps($orientation)];
    $chunks[] = ['XMP ', '<x:xmpmeta>GPS 48.1</x:xmpmeta>'];
    $body = '';
    foreach ($chunks as [$fourcc, $data]) {
        $body .= $fourcc . pack('V', strlen($data)) . $data . (strlen($data) % 2 === 1 ? "\x00" : '');
    }

    return 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body . 'ANGEHAENGT';
};

$file = static function (string $name, string $content) use ($uploads): string {
    $path = $uploads . $name;
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $content);
    clearstatcache(true, $path);

    return $path;
};

// ── Ausrichtung ─────────────────────────────────────────────────────────────

T::run('Ausrichtung: alle acht EXIF-Werte (gegen die Tabelle der EXIF-Spezifikation)', static function (): void {
    // Gespeichertes Bild 3×2 mit eindeutiger Farbe je Pixel.
    $stored = imagecreatetruecolor(3, 2);
    $colors = [];
    for ($y = 0; $y < 2; $y++) {
        for ($x = 0; $x < 3; $x++) {
            $colors[$y][$x] = 0x101010 * (1 + $x + 3 * $y);
            imagesetpixel($stored, $x, $y, $colors[$y][$x]);
        }
    }
    // Wo landet das gespeicherte Pixel (sx, sy) in der Anzeige? Zeile 0 / Spalte 0 laut EXIF-Tabelle (W=3, H=2).
    $map = [
        1 => static fn (int $sx, int $sy): array => [$sx, $sy],
        2 => static fn (int $sx, int $sy): array => [2 - $sx, $sy],
        3 => static fn (int $sx, int $sy): array => [2 - $sx, 1 - $sy],
        4 => static fn (int $sx, int $sy): array => [$sx, 1 - $sy],
        5 => static fn (int $sx, int $sy): array => [$sy, $sx],
        6 => static fn (int $sx, int $sy): array => [1 - $sy, $sx],
        7 => static fn (int $sx, int $sy): array => [1 - $sy, 2 - $sx],
        8 => static fn (int $sx, int $sy): array => [$sy, 2 - $sx],
    ];
    foreach ($map as $orientation => $target) {
        $copy = imagecreatetruecolor(3, 2);
        imagecopy($copy, $stored, 0, 0, 0, 0, 3, 2);
        $shown = Orientation::apply($copy, $orientation);
        $expectedSize = Orientation::swapsDimensions($orientation) ? [2, 3] : [3, 2];
        $ok = [imagesx($shown), imagesy($shown)] === $expectedSize;
        for ($y = 0; $y < 2 && $ok; $y++) {
            for ($x = 0; $x < 3 && $ok; $x++) {
                [$dx, $dy] = $target($x, $y);
                $ok = imagecolorat($shown, $dx, $dy) === $colors[$y][$x];
            }
        }
        T::check($ok, 'Ausrichtung ' . $orientation . ': Pixel und Maße wie in der Anzeige');
    }
    T::same(1, Orientation::fromTiff('kein TIFF'), 'kein TIFF → 1');
    T::same(1, Orientation::fromTiff(Orientation::tiffWithOrientation(9)), 'ungültiger Wert → 1');
    foreach ([2, 6, 8] as $value) {
        T::same($value, Orientation::fromTiff("Exif\0\0" . Orientation::tiffWithOrientation($value)), 'minimaler TIFF-Block hin und zurück: ' . $value);
    }
});

T::run('Ausrichtung: Lesen aus JPEG (exif_read_data), nicht aus PNG/WebP oder fremden Dateien', static function () use ($exif, $file, $photoWithMetadata, $photoJpeg, $rgbaPng, $tiffWithGps): void {
    if (!$exif) {
        T::info('ext-exif fehlt');

        return;
    }
    T::same(6, Orientation::read($file('lesen/hoch.jpg', $photoWithMetadata(6))), 'JPEG mit APP1-EXIF: 6');
    T::same(6, Orientation::read($file('lesen/upload-tmp', $photoWithMetadata(6))), 'temporäre Upload-Datei ohne Endung: Signatur zählt');
    T::same(3, Orientation::read($file('lesen/kopf.jpg', $photoWithMetadata(3))), 'JPEG mit APP1-EXIF: 3');
    T::same(1, Orientation::read($file('lesen/ohne.jpg', $photoJpeg())), 'JPEG ohne EXIF: 1');
    T::same(1, Orientation::read($file('lesen/bild.png', $rgbaPng())), 'PNG: 1');
    T::same(1, Orientation::read($file('lesen/kaputt.jpg', "\xFF\xD8\xFF\xE1\x00\x10Exif\0\0kaputt")), 'beschädigtes EXIF: 1, ohne Warnung');
    T::same(1, Orientation::read($file('lesen/leer.jpg', '')), 'leere Datei: 1');
    T::same(1, Orientation::read($file('lesen/fehlt.jpg', 'x') . '-fehlt'), 'fehlende Datei: 1');
    T::same(6, Orientation::fromTiff($tiffWithGps(6)), 'fromTiff liest Little Endian');
});

// ── Transparenz ─────────────────────────────────────────────────────────────

T::run('PNG-Transparenz: neu kodiertes Original (Theme-Logo, Editor-Datei)', static function () use ($media, $call, $settings, $file, $rgbaPng, $loadPng, $alpha, $logger): void {
    $path = $file('theme-logos/logo.png', $rgbaPng());
    $before = (string) file_get_contents($path);
    $call($media, 'repairUploadedBrowserImage', [$path, 'logo.png', $settings()]);
    clearstatcache(true, $path);
    T::check((string) file_get_contents($path) !== $before, 'strip_exif an: das Original wurde neu kodiert');
    $image = $loadPng($path);
    T::same(127, $alpha($image, 10, 15), 'transparenter Hintergrund bleibt transparent (Alpha 127)');
    T::same(63, $alpha($image, 30, 10), 'halbtransparenter Bereich bleibt halbtransparent (Alpha 63)');
    T::same(0, $alpha($image, 2, 2), 'deckender Bereich bleibt deckend');

    // Palettenbild mit transparentem Index.
    $palette = imagecreate(8, 8);
    $transparent = imagecolorallocate($palette, 0, 255, 0);
    imagecolortransparent($palette, $transparent);
    imagefilledrectangle($palette, 0, 0, 3, 3, imagecolorallocate($palette, 255, 0, 0));
    ob_start();
    imagepng($palette);
    $palettePath = $file('theme-logos/palette.png', (string) ob_get_clean());
    $call($media, 'repairUploadedBrowserImage', [$palettePath, 'palette.png', $settings()]);
    $reloaded = $loadPng($palettePath);
    $index = imagecolortransparent($reloaded);
    T::check($index >= 0 && imagecolorat($reloaded, 6, 6) === $index, 'Palettenbild: transparenter Index bleibt erhalten');
    T::same([], $logger->warnings, 'keine Warnungen');
});

T::run('PNG-Transparenz: Skalieren, Vorschaubilder, WebP und Bildersatz', static function () use ($media, $call, $settings, $file, $rgbaPng, $rgbaImage, $encode, $loadPng, $alpha, $uploads, $logger): void {
    $path = $file('verwaltet/logo.png', $rgbaPng());
    $call($media, 'processManagedImageUpload', [$path, 'logo.png', $settings([
        'max_width' => 20, 'max_height' => 20, 'generate_thumbnails' => true, 'thumb_small_w' => 10, 'thumb_small_h' => 10,
    ]), false]);
    $image = $loadPng($path);
    T::same([20, 10], [imagesx($image), imagesy($image)], 'auf Maximalmaße skaliert');
    T::check($alpha($image, 5, 8) >= 120 && $alpha($image, 1, 1) === 0, 'skaliertes Original: transparent und deckend wie vorher');
    $small = $uploads . 'verwaltet/logo-small.png';
    T::check(is_file($small), 'Vorschaubild erzeugt');
    $thumb = $loadPng($small);
    T::check($alpha($thumb, 3, 4) >= 120, 'Vorschaubild: transparent');
    $webp = $uploads . 'verwaltet/logo.webp';
    if (is_file($webp)) {
        T::check($alpha(imagecreatefromwebp($webp), 5, 8) >= 120, 'WebP-Begleitdatei: transparent');
    } else {
        T::info('WebP-Begleitdatei nicht kleiner als das PNG und daher verworfen');
    }

    // Bildersatz mit Formatwechsel (WebP hochgeladen, Ziel bleibt .png).
    $source = $file('ersatz/upload-tmp', $encode(static fn () => imagewebp($rgbaImage(), null, 100)));
    $target = $uploads . 'ersatz/ziel.png';
    T::check($call($media, 'writeReplacementImage', [['tmp_name' => $source], 'webp', $target, 'png', 85]) === true, 'Bildersatz WebP → PNG gespeichert');
    $replaced = $loadPng($target);
    T::check($alpha($replaced, 10, 15) >= 120 && $alpha($replaced, 2, 2) === 0, 'Bildersatz WebP → PNG: Transparenz bleibt');
    T::same([], $logger->warnings, 'keine Warnungen');
});

// ── JPEG: Ausrichtung und Metadaten beim Neukodieren ────────────────────────

T::run('JPEG mit Ausrichtung 6 und GPS: neu kodiertes Original ist aufrecht und ohne EXIF', static function () use ($exif, $media, $call, $settings, $file, $photoWithMetadata, $uprightPortrait, $readExif, $logger): void {
    if (!$exif) {
        T::info('ext-exif fehlt');

        return;
    }
    $path = $file('editorjs/foto.jpg', $photoWithMetadata(6));
    T::same('N', $readExif($path)['GPSLatitudeRef'] ?? null, 'Ausgangsbild trägt GPS (Gegenprobe)');
    $call($media, 'repairUploadedBrowserImage', [$path, 'foto.jpg', $settings()]);
    $data = (string) file_get_contents($path);
    $image = imagecreatefromjpeg($path);
    T::same([80, 160], [imagesx($image), imagesy($image)], 'Maße getauscht (Hochformat 80×160)');
    T::check($uprightPortrait($image), 'roter Block oben rechts: aufrecht gedreht');
    $exifData = $readExif($path);
    T::check(!isset($exifData['Orientation']) && !isset($exifData['GPSLatitude']) && !isset($exifData['Make']), 'kein EXIF mehr (Ausrichtung, GPS, Kamera)');
    T::check(!str_contains($data, "Exif\0\0") && !str_contains($data, 'xmpmeta') && !str_contains($data, 'Photoshop 3.0'), 'kein APP1/XMP/IPTC-Block');
    T::check(!str_contains($data, 'geheimer Kommentar') && !str_contains($data, 'ANGEHAENGT'), 'kein Kommentar, keine angehängten Daten');
    T::same([], $logger->warnings, 'keine Warnungen');
});

T::run('JPEG mit Ausrichtung 6: Skalieren, Vorschaubilder, WebP und AVIF sind aufrecht', static function () use ($exif, $media, $call, $settings, $file, $photoWithMetadata, $uprightPortrait, $uploads, $logger): void {
    if (!$exif) {
        T::info('ext-exif fehlt');

        return;
    }
    // strip_exif aus: Das Original behält EXIF, jede GD-Ausgabe muss die Ausrichtung selbst anwenden.
    $path = $file('derivate/foto.jpg', $photoWithMetadata(6));
    $call($media, 'processManagedImageUpload', [$path, 'foto.jpg', $settings([
        'strip_exif' => false, 'max_width' => 180, 'max_height' => 120, 'generate_thumbnails' => true,
        'thumb_small_w' => 40, 'thumb_small_h' => 40, 'auto_webp' => true,
    ]), false]);
    $image = imagecreatefromjpeg($path);
    T::same([60, 120], [imagesx($image), imagesy($image)], 'Maximalmaße 180×120 gelten für das Hochformat (160×80 gespeichert, 80×160 angezeigt → 60×120)');
    T::check($uprightPortrait($image), 'skaliertes Original: aufrecht');
    $thumb = imagecreatefromjpeg($uploads . 'derivate/foto-small.jpg');
    T::check($uprightPortrait($thumb), 'Vorschaubild: aufrecht (' . imagesx($thumb) . '×' . imagesy($thumb) . ')');

    // Originalerhaltender Upload (Mediathek) mit strip_exif aus: Original unverändert, Derivate aufrecht.
    $original = $photoWithMetadata(6);
    $kept = $file('derivate/mediathek.jpg', $original);
    $call($media, 'processManagedImageUpload', [$kept, 'mediathek.jpg', $settings(['strip_exif' => false, 'auto_webp' => true]), true]);
    T::same(sha1($original), sha1((string) file_get_contents($kept)), 'strip_exif aus: Original bleibt Byte für Byte erhalten');
    $webp = $uploads . 'derivate/mediathek.webp';
    if (is_file($webp)) {
        T::check($uprightPortrait(imagecreatefromwebp($webp)), 'WebP-Begleitdatei: aufrecht');
    } else {
        T::check(false, 'WebP-Begleitdatei erzeugt');
    }

    $processor = new ImageProcessor();
    $direct = $file('derivate/direkt.jpg', $original);
    T::same(true, $processor->convertToWebP($direct, 90), 'ImageProcessor::convertToWebP()');
    T::check($uprightPortrait(imagecreatefromwebp($uploads . 'derivate/direkt.webp')), 'convertToWebP: aufrecht');
    $thumbs = $processor->generateThumbnails($direct, ['medium' => [40, 120]], 90);
    T::check(is_array($thumbs) && count($thumbs) === 1, 'generateThumbnails()');
    $medium = imagecreatefromjpeg($uploads . 'derivate/direkt-medium.jpg');
    T::same([40, 80], [imagesx($medium), imagesy($medium)], 'Vorschaubild im Rahmen 40×120: 40×80 (Hochformat)');
    T::check($uprightPortrait($medium), 'generateThumbnails: aufrecht');
    $within = $file('derivate/passt.jpg', $original);
    T::same(true, $processor->resizeToFit($within, 100, 180, 90), 'resizeToFit(): 80×160 passt in 100×180');
    T::same(sha1($original), sha1((string) file_get_contents($within)), 'passt hochkant in die Maße → unverändert (gespeichert 160×80 wäre zu breit)');

    if (function_exists('imageavif')) {
        $avifService = new \CMS\Services\Media\AvifDerivativeService([
            'optionGet' => static fn (): ?string => '{"avif":true}',
            'optionSet' => static function (string $json): void {
            },
            'settings' => static fn (): array => [],
            'uploadPath' => $uploads,
        ]);
        $avifSource = $file('derivate/avif.jpg', $original);
        $status = $avifService->generateOne($avifSource, 60, 10);
        $avifPath = $uploads . 'derivate/avif.avif';
        if ($status === \CMS\Services\Media\AvifDerivativeService::STATUS_CREATED && is_file($avifPath) && function_exists('imagecreatefromavif')) {
            T::check($uprightPortrait(imagecreatefromavif($avifPath)), 'AVIF-Fassung: aufrecht');
        } else {
            T::info('AVIF nicht geprüft (Status ' . $status . ')');
        }
    } else {
        T::info('GD ohne AVIF: AVIF-Fassung nur statisch geprüft');
    }
    T::same([], $logger->warnings, 'keine Warnungen');
});

// ── strip_exif bei originalerhaltenden Uploads ──────────────────────────────

T::run('strip_exif in der Mediathek: JPEG ohne Neukodierung bereinigt', static function () use ($exif, $media, $call, $settings, $file, $photoWithMetadata, $scanData, $readExif, $gpsKeys, $uprightPortrait, $uploads, $logger): void {
    $original = $photoWithMetadata(6);
    $path = $file('2026/11/foto.jpg', $original);
    $call($media, 'processManagedImageUpload', [$path, 'foto.jpg', $settings(['auto_webp' => true]), true]);
    $data = (string) file_get_contents($path);

    T::check($scanData($data) !== '' && $scanData($data) === $scanData($original), 'komprimierte Bilddaten Byte für Byte unverändert');
    $before = imagecreatefromstring($original);
    $after = imagecreatefromstring($data);
    $same = imagesx($before) === imagesx($after) && imagesy($before) === imagesy($after);
    for ($y = 0; $y < imagesy($before) && $same; $y++) {
        for ($x = 0; $x < imagesx($before) && $same; $x++) {
            $same = imagecolorat($before, $x, $y) === imagecolorat($after, $x, $y);
        }
    }
    T::check($same, 'dekodierte Pixel identisch');
    T::check(strlen($data) < strlen($original), 'Datei kleiner (' . strlen($original) . ' → ' . strlen($data) . ' Bytes)');
    T::check(!str_contains($data, 'TestCam') && !str_contains($data, 'xmpmeta') && !str_contains($data, 'Photoshop 3.0'), 'EXIF (Kamera), XMP und IPTC entfernt');
    T::check(!str_contains($data, 'geheimer Kommentar') && !str_contains($data, 'ANGEHAENGT'), 'Kommentar und angehängte Daten entfernt');
    T::check(str_contains($data, "ICC_PROFILE\0") && str_contains($data, "JFIF\0"), 'ICC-Profil und JFIF bleiben');
    T::check(str_ends_with($data, "\xFF\xD9"), 'endet mit EOI');
    if ($exif) {
        $exifData = $readExif($path);
        T::same([], $gpsKeys($exifData), 'kein GPS mehr');
        T::check(!isset($exifData['Make']), 'keine Kamera-Angaben mehr');
        T::same(6, (int) ($exifData['Orientation'] ?? 0), 'Ausrichtung bleibt als einziges Tag stehen (Browser zeigen das Original aufrecht)');
        $webp = $uploads . '2026/11/foto.webp';
        T::check(is_file($webp) && $uprightPortrait(imagecreatefromwebp($webp)), 'WebP-Begleitdatei aus dem bereinigten Original: aufrecht');
    }

    // Ohne Ausrichtung bleibt kein EXIF-Block.
    $flat = $file('2026/11/flach.jpg', $photoWithMetadata(1));
    $call($media, 'processManagedImageUpload', [$flat, 'flach.jpg', $settings(['auto_webp' => false]), true]);
    T::check(!str_contains((string) file_get_contents($flat), "Exif\0\0"), 'Ausrichtung 1: kein EXIF-Block mehr');

    // Zweiter Lauf ändert nichts.
    T::same(Stripper::STATUS_CLEAN, Stripper::stripFile($path), 'zweiter Lauf: nichts mehr zu entfernen');

    // strip_exif aus → unverändert.
    $off = $file('2026/11/aus.jpg', $original);
    $call($media, 'processManagedImageUpload', [$off, 'aus.jpg', $settings(['strip_exif' => false, 'auto_webp' => false]), true]);
    T::same(sha1($original), sha1((string) file_get_contents($off)), 'strip_exif aus: Datei unverändert');
    T::same([], $logger->warnings, 'keine Warnungen');
});

T::run('strip_exif in der Mediathek: PNG und WebP ohne Neukodierung bereinigt', static function () use ($media, $call, $settings, $file, $pngWithMetadata, $pngChunks, $webpWithMetadata, $webpChunks, $alpha, $loadPng, $logger): void {
    $original = $pngWithMetadata(1);
    $path = $file('2026/11/grafik.png', $original);
    $call($media, 'processManagedImageUpload', [$path, 'grafik.png', $settings(['auto_webp' => false]), true]);
    $data = (string) file_get_contents($path);
    $types = array_column($pngChunks($data), 0);
    T::same([], array_values(array_intersect($types, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'])), 'eXIf, tEXt, zTXt, iTXt und tIME entfernt');
    T::same('IEND', end($types), 'endet mit IEND, angehängte Daten entfernt');
    $idat = static fn (string $png): array => array_column(array_filter($pngChunks($png), static fn (array $c): bool => $c[0] === 'IDAT'), 1);
    T::check($idat($data) !== [] && $idat($data) === $idat($original), 'IDAT-Daten Byte für Byte unverändert');
    $image = $loadPng($path);
    T::check($alpha($image, 10, 15) === 127 && $alpha($image, 30, 10) === 63 && $alpha($image, 2, 2) === 0, 'Transparenz unverändert');

    $rotated = $file('2026/11/hoch.png', $pngWithMetadata(6));
    $call($media, 'processManagedImageUpload', [$rotated, 'hoch.png', $settings(['auto_webp' => false]), true]);
    $exifChunks = array_values(array_filter($pngChunks((string) file_get_contents($rotated)), static fn (array $c): bool => $c[0] === 'eXIf'));
    T::check(count($exifChunks) === 1 && Orientation::fromTiff($exifChunks[0][1]) === 6 && !str_contains($exifChunks[0][1], 'TestCam'), 'PNG mit Ausrichtung 6: nur die Ausrichtung bleibt im eXIf');

    $webpOriginal = $webpWithMetadata(1);
    $webpPath = $file('2026/11/grafik.webp', $webpOriginal);
    $call($media, 'processManagedImageUpload', [$webpPath, 'grafik.webp', $settings(), true]);
    $webp = (string) file_get_contents($webpPath);
    $chunks = $webpChunks($webp);
    T::same([], array_values(array_intersect(array_column($chunks, 0), ['EXIF', 'XMP '])), 'WebP: EXIF und XMP entfernt');
    T::same(0, ord($chunks[0][1][0]) & 0x0C, 'WebP: Flags für EXIF/XMP im VP8X-Kopf gelöscht');
    T::same(strlen($webp) - 8, (int) unpack('V', substr($webp, 4, 4))[1], 'WebP: RIFF-Größe stimmt, angehängte Daten entfernt');
    $decoded = imagecreatefromwebp($webpPath);
    T::check($decoded instanceof \GdImage && $alpha($decoded, 10, 15) >= 120 && $alpha($decoded, 2, 2) === 0, 'WebP: dekodierbar, Transparenz unverändert');

    $webpRotated = $file('2026/11/hoch.webp', $webpWithMetadata(8));
    $call($media, 'processManagedImageUpload', [$webpRotated, 'hoch.webp', $settings(), true]);
    $rotatedChunks = $webpChunks((string) file_get_contents($webpRotated));
    $exifChunk = array_values(array_filter($rotatedChunks, static fn (array $c): bool => $c[0] === 'EXIF'));
    T::check(count($exifChunk) === 1 && Orientation::fromTiff($exifChunk[0][1]) === 8 && (ord($rotatedChunks[0][1][0]) & 0x0C) === 0x08, 'WebP mit Ausrichtung 8: nur die Ausrichtung bleibt, Flag EXIF gesetzt');

    // Logo-/Editor-Weg (neu kodierend) behandelt WebP verlustfrei.
    $logoWebp = $file('theme-logos/logo.webp', $webpWithMetadata(1));
    $call($media, 'repairUploadedBrowserImage', [$logoWebp, 'logo.webp', $settings()]);
    T::check(!str_contains((string) file_get_contents($logoWebp), 'xmpmeta'), 'Logo als WebP: Metadaten entfernt');
    T::same([], $logger->warnings, 'keine Warnungen');
});

T::run('Metadaten-Entferner: ungültige, fremde und saubere Dateien', static function () use ($file, $photoJpeg, $rgbaPng, $encode): void {
    T::same(null, Stripper::strip('kein Bild'), 'unbekanntes Format → null');
    T::same(null, Stripper::strip("\xFF\xD8\xFF\xE1\xFF\xFFExif"), 'JPEG mit Segmentlänge über das Dateiende → null');
    T::same(null, Stripper::strip("\x89PNG\r\n\x1A\n" . pack('N', 999) . 'IHDR'), 'PNG mit Chunklänge über das Dateiende → null');
    $jpeg = $photoJpeg();
    $truncated = substr($jpeg, 0, (int) strrpos($jpeg, "\xFF\xD9"));
    $strippedTruncated = Stripper::strip($truncated);
    T::check(is_string($strippedTruncated) && str_ends_with($truncated, substr((string) $strippedTruncated, -100)), 'JPEG ohne EOI: Bilddaten bleiben stehen');

    // Progressives JPEG: mehrere Scans mit Tabellen dazwischen, Metadaten vor dem ersten Scan.
    $progressive = imagecreatetruecolor(160, 80);
    imagefilledrectangle($progressive, 0, 0, 39, 39, imagecolorallocate($progressive, 255, 0, 0));
    imageinterlace($progressive, true);
    $progressiveJpeg = $encode(static fn () => imagejpeg($progressive, null, 90));
    $withMeta = substr($progressiveJpeg, 0, 2) . "\xFF\xFE" . pack('n', 8) . 'GPS:48' . substr($progressiveJpeg, 2);
    $strippedProgressive = (string) Stripper::strip($withMeta);
    $scanStart = static fn (string $data): string => substr($data, (int) strpos($data, "\xFF\xDA"));
    T::check(substr_count($progressiveJpeg, "\xFF\xDA") > 1 && $scanStart($strippedProgressive) === $scanStart($progressiveJpeg), 'progressives JPEG: alle Scans unverändert');
    T::check(!str_contains($strippedProgressive, 'GPS:48') && imagecreatefromstring($strippedProgressive) instanceof \GdImage, 'progressives JPEG: Kommentar entfernt, dekodierbar');

    $gif = imagecreatetruecolor(2, 2);
    T::same(Stripper::STATUS_UNSUPPORTED, Stripper::stripFile($file('fremd/bild.gif', $encode(static fn () => imagegif($gif)))), 'GIF: nicht unterstützt, unverändert');
    T::same(Stripper::STATUS_CLEAN, Stripper::stripFile($file('fremd/sauber.png', $rgbaPng())), 'PNG aus GD ohne Metadaten: unverändert');
    T::same(Stripper::STATUS_FAILED, Stripper::stripFile($file('fremd/kaputt.jpg', "\xFF\xD8\xFF\xE0\x00")), 'beschädigtes JPEG: Fehler, unverändert');
    T::same(Stripper::STATUS_FAILED, Stripper::stripFile($file('fremd/x.jpg', 'x') . '-fehlt'), 'fehlende Datei: Fehler');
    $path = $file('fremd/gd.jpg', $jpeg);
    T::same(Stripper::STATUS_STRIPPED, Stripper::stripFile($path), 'JPEG aus GD: Kommentar „CREATOR: gd-jpeg“ entfernt');
    T::same([], glob(dirname($path) . '/.*.strip-*') ?: [], 'keine temporären Dateien');
});

T::run('ImageService: autoOrient() für alle Werte, Transparenz beim Speichern', static function () use ($exif, $instance, $file, $photoWithMetadata, $rgbaPng, $loadPng, $alpha, $uprightPortrait, $redAt): void {
    $service = $instance(ImageService::class, ['gdAvailable' => true, 'webpSupport' => function_exists('imagewebp'), 'avifSupport' => false]);
    if ($exif) {
        $path = $file('service/foto.jpg', $photoWithMetadata(6));
        T::check($service->autoOrient($path), 'autoOrient(): korrigiert');
        T::check($uprightPortrait(imagecreatefromjpeg($path)), 'autoOrient(): aufrecht');
        T::check(!$service->autoOrient($path), 'zweiter Aufruf: nichts zu tun');
        $mirrored = $file('service/spiegel.jpg', $photoWithMetadata(2));
        T::check($service->autoOrient($mirrored), 'gespiegelte Ausrichtung 2 wird korrigiert (bisher nur 3/6/8)');
        $image = imagecreatefromjpeg($mirrored);
        T::check(imagesx($image) === 160 && $redAt($image, 0.875, 0.25) && !$redAt($image, 0.125, 0.25), 'Ausrichtung 2: Querformat, roter Block oben rechts');
    }
    $png = $file('service/logo.png', $rgbaPng());
    T::check($service->rotate($png, 90) !== null, 'rotate() auf ein PNG');
    T::same(127, $alpha($loadPng($png), 5, 5), 'rotate(): Transparenz bleibt (saveImage mit imagesavealpha)');
});

// ── Einbindung (statisch) ───────────────────────────────────────────────────

T::run('Einbindung in Upload-Wege, Oberfläche und Dokumentation', static function () use ($root): void {
    $read = static fn (string $relative): string => (string) file_get_contents($root . $relative);
    $slice = static function (string $source, string $from, string $to): string {
        $start = strpos($source, $from);
        if ($start === false) {
            return '';
        }
        $end = strpos($source, $to, $start + strlen($from));

        return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
    };
    $mediaService = $read('core/Services/MediaService.php');
    $uploadFile = $slice($mediaService, 'public function uploadFile(', 'public function uploadManagedFile(');
    T::check(str_contains($uploadFile, 'stripImageMetadataLosslessly(') && str_contains($uploadFile, 'repairUploadedBrowserImage('), 'uploadFile(): verlustfrei bei erhaltenem Original, sonst neu kodieren');
    $managed = $slice($mediaService, 'private function processManagedImageUpload(', 'private function generateUploadAvifDerivatives(');
    T::check(str_contains($managed, 'if ($preserveOriginalImage) {') && str_contains($managed, 'stripImageMetadataLosslessly('), 'processManagedImageUpload(): strip_exif auch für erhaltene Originale');
    T::check(str_contains($slice($mediaService, 'public function uploadManagedFile(', 'public function replaceManagedFile('), 'processManagedImageUpload('), 'uploadManagedFile() → processManagedImageUpload()');
    T::check(str_contains($slice($mediaService, 'public function replaceManagedFile(', 'public function ensureCategory('), 'processManagedImageUpload('), 'replaceManagedFile() → processManagedImageUpload()');
    T::check(str_contains($slice($mediaService, 'private function reEncodeImage(', 'private function repairUploadedBrowserImage('), 'prepareGdImageAlpha($image)'), 'reEncodeImage(): imagesavealpha vor dem Speichern');
    T::check(str_contains($slice($mediaService, 'private function saveReplacementGdImage(', 'private function restoreManagedReplacementBackup('), 'prepareGdImageAlpha($image)'), 'saveReplacementGdImage(): imagesavealpha vor dem Speichern');
    T::check(str_contains($slice($mediaService, 'private function loadGdImage(', 'private function runImageOperation('), 'ImageOrientation::orient('), 'loadGdImage(): Ausrichtung angewendet');
    T::check(str_contains($read('core/Services/Media/AvifDerivativeService.php'), 'ImageOrientation::orient($image, $source)'), 'AVIF-Kodierung: Ausrichtung angewendet');
    T::check(str_contains($read('core/Services/ImageService.php'), 'ImageOrientation::orient($image, $path)'), 'ImageService::loadImage(): Ausrichtung angewendet');

    foreach (['core/Services/MediaService.php', 'core/Services/ImageService.php', 'core/Services/Media/ImageProcessor.php',
        'core/Services/Media/ImageOrientation.php', 'core/Services/Media/ImageMetadataStripper.php', 'core/Services/Media/AvifDerivativeService.php'] as $relative) {
        T::check(preg_match('/\b(?:imagedestroy|finfo_close)\s*\(/', $read($relative)) !== 1, $relative . ': kein imagedestroy()/finfo_close() (veraltet in PHP 8.5)');
    }

    $view = $read('admin/views/media/settings.php');
    T::check(str_contains($view, 'name="strip_exif"') && str_contains($view, 'Metadaten entfernen (EXIF, GPS, XMP, IPTC)'), 'Medien-Einstellungen: Beschriftung nennt die entfernten Metadaten');
    T::check(str_contains($view, 'bleiben die Bilddaten unverändert') && str_contains($view, 'Logos und Editor-Dateianhänge werden neu kodiert'), 'Medien-Einstellungen: Hinweis zu erhaltenen und neu kodierten Originalen');
    T::check(str_contains($read('admin/views/performance/media.php'), 'Metadaten (EXIF, GPS) beim Upload entfernen'), 'Performance → Medien: Beschriftung angepasst');
    T::check(str_contains((string) file_get_contents(dirname($root) . '/DOC/admin/media/MEDIA.md'), 'ImageMetadataStripper'), 'MEDIA.md beschreibt das Verhalten von strip_exif');
});

T::finish();
