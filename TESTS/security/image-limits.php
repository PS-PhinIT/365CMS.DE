<?php
/**
 * Audit 4.2.0 (AP-06, Punkt 06.2): Pixelgrenze gegen Dekompressionsbomben. Ohne Datenbank.
 * Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Services\Media\ImagePixelLimit;
use CMS\Services\Media\ImageProcessor;
use CMS\WP_Error;

/** Schreibt ein gültiges, einfarbiges RGB-PNG der Größe $width × $height (wenige hundert KB, riesig beim Dekodieren). */
$ap06Png = static function (string $path, int $width, int $height): void {
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $context = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    $row = str_repeat("\0", 1 + $width * 3);
    $stream = '';
    for ($y = 0; $y < $height; $y++) {
        $stream .= deflate_add($context, $row, $y === $height - 1 ? ZLIB_FINISH : ZLIB_NO_FLUSH);
    }
    file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)) . $chunk('IDAT', $stream) . $chunk('IEND', ''));
};

T::run('AP-06 / 06.2: Pixelgrenze entscheidet nach dem Dateikopf, ohne zu dekodieren', static function () use ($ap06Png): void {
    $dir = sys_get_temp_dir() . '/cms-ap06-' . getmypid() . '/';
    @mkdir($dir, 0777, true);
    $bomb = $dir . 'bombe.png';
    $normal = $dir . 'normal.png';
    $ap06Png($bomb, 8000, 7000);   // 56 Megapixel
    $ap06Png($normal, 1000, 1000); // 1 Megapixel

    T::check(filesize($bomb) < 1_000_000, 'Bombe ist als Datei klein (' . filesize($bomb) . ' Bytes)');
    T::check(!ImagePixelLimit::allowsFile($bomb), '56 Megapixel liegen über der Grenze');
    T::check(ImagePixelLimit::allowsFile($normal), '1 Megapixel ist erlaubt');
    T::check(ImagePixelLimit::allowsFile($dir . 'fehlt.png'), 'fehlende Datei: die Lader melden den Fehler');
    file_put_contents($dir . 'text.png', 'kein Bild');
    T::check(ImagePixelLimit::allowsFile($dir . 'text.png'), 'keine Bilddatei: die Lader melden den Fehler');

    $limit = ImagePixelLimit::maxPixels();
    T::check(ImagePixelLimit::allows(10_000, intdiv($limit, 10_000)), 'genau an der Grenze erlaubt');
    T::check(!ImagePixelLimit::allows(10_000, intdiv($limit, 10_000) + 1), 'ein Pixel darüber abgelehnt');
    T::check(ImagePixelLimit::allows(0, 0), 'unbekannte Maße blockieren nicht (Lader lehnen ab)');

    if (!extension_loaded('gd')) {
        T::info('GD fehlt: Prüfung von ImageProcessor übersprungen');
    } else {
        $processor = new ImageProcessor();
        $copy = $dir . 'kopie.png';
        copy($bomb, $copy);
        $thumbs = $processor->generateThumbnails($copy, ['thumb' => [150, 150]]);
        T::check($thumbs instanceof WP_Error && $thumbs->get_error_code() === 'image_too_large', 'Vorschaubilder: Bombe wird vor dem Dekodieren abgelehnt');
        $resized = $processor->resizeToFit($copy, 800, 800);
        T::check($resized instanceof WP_Error && $resized->get_error_code() === 'image_too_large', 'Verkleinern: Bombe wird vor dem Dekodieren abgelehnt');

        $okCopy = $dir . 'ok.png';
        copy($normal, $okCopy);
        $okThumbs = $processor->generateThumbnails($okCopy, ['thumb' => [150, 150]]);
        T::check(is_array($okThumbs) && count($okThumbs) === 1, 'normales Bild erzeugt weiterhin Vorschaubilder');
    }

    foreach (glob($dir . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
});

T::run('AP-06 / 06.2: Upload-Prüfung und Lader nennen die Pixelgrenze', static function (): void {
    $media = (string) file_get_contents(ABSPATH . 'core/Services/MediaService.php');
    $upload = strpos($media, 'private function validateFileTypeAndContent(');
    $guard = strpos($media, 'ImagePixelLimit::allowsFile($tmpName)', (int) $upload);
    T::check($upload !== false && $guard !== false && $guard > $upload, 'Upload-Validierung prüft die Pixelzahl');
    T::check(str_contains($media, 'if (!Media\ImagePixelLimit::allowsFile($path)) {'), 'MediaService::loadGdImage prüft vor dem Dekodieren');
    T::check(str_contains((string) file_get_contents(ABSPATH . 'core/Services/ImageService.php'), 'Media\ImagePixelLimit::allowsFile($path)'), 'ImageService::loadImage prüft vor dem Dekodieren');
    T::check(str_contains((string) file_get_contents(ABSPATH . 'core/Services/Media/ImageProcessor.php'), 'ImagePixelLimit::allowsFile($filePath)'), 'ImageProcessor::createImageResource prüft vor dem Dekodieren');
});
