<?php
/**
 * Image Service – GD-basierte Bildbearbeitung
 *
 * Bietet Thumbnail-Generierung, Resize, Crop, WebP-Konvertierung
 * und Wasserzeichen-Unterstützung über die native PHP-GD-Extension.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageService
{
    private static ?self $instance = null;

    /** GD verfügbar? */
    private readonly bool $gdAvailable;

    /** WebP-Support in GD kompiliert? */
    private readonly bool $webpSupport;

    /** AVIF-Support in GD kompiliert? */
    private readonly bool $avifSupport;

    /** Standard-JPEG-/WebP-Qualität (0-100) */
    private int $defaultQuality = 82;

    /** Standard-Thumbnail-Größen (Breite × Höhe) */
    private array $thumbnailSizes = [
        'small'  => [150, 150],
        'medium' => [300, 300],
        'large'  => [1024, 1024],
    ];

    private Logger $logger;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->gdAvailable = extension_loaded('gd') && function_exists('imagecreatetruecolor');
        $this->webpSupport = function_exists('imagewebp');
        $this->avifSupport = function_exists('imageavif');
        $this->logger = Logger::instance()->withChannel('image');
    }

    // ──────────────────────────────────────────────────────────
    //  Öffentliche API
    // ──────────────────────────────────────────────────────────

    /**
     * Prüft ob GD verfügbar ist.
     */
    public function isAvailable(): bool
    {
        return $this->gdAvailable;
    }

    /**
     * Gibt Infos über die GD-Installation zurück.
     */
    public function getInfo(): array
    {
        if (!$this->gdAvailable) {
            return ['available' => false];
        }

        $info = gd_info();
        return [
            'available'    => true,
            'version'      => $info['GD Version'] ?? 'unbekannt',
            'jpeg_support' => $info['JPEG Support'] ?? false,
            'png_support'  => $info['PNG Support'] ?? false,
            'gif_support'  => ($info['GIF Read Support'] ?? false) && ($info['GIF Create Support'] ?? false),
            'webp_support' => $this->webpSupport,
            'avif_support' => $this->avifSupport,
        ];
    }

    /**
     * Bild proportional verkleinern (fit within box).
     *
     * @param string $sourcePath  Quell-Bildpfad
     * @param int    $maxWidth    Max. Breite
     * @param int    $maxHeight   Max. Höhe
     * @param string|null $destPath  Ziel-Pfad (null = überschreibt Quelle)
     * @param int    $quality     JPEG-/WebP-Qualität (0-100)
     *
     * @return string|null  Pfad zum verkleinerten Bild oder null bei Fehler
     */
    public function resize(
        string $sourcePath,
        int $maxWidth,
        int $maxHeight,
        ?string $destPath = null,
        int $quality = 0
    ): ?string {
        if (!$this->gdAvailable || !file_exists($sourcePath)) {
            return null;
        }

        $quality = $quality > 0 ? $quality : $this->defaultQuality;
        $destPath ??= $sourcePath;

        $image = $this->loadImage($sourcePath);
        if ($image === null) {
            return null;
        }

        $origWidth  = imagesx($image);
        $origHeight = imagesy($image);

        // Nichts tun wenn Bild bereits kleiner
        if ($origWidth <= $maxWidth && $origHeight <= $maxHeight) {
            if ($destPath !== $sourcePath) {
                copy($sourcePath, $destPath);
            }
            unset($image);
            return $destPath;
        }

        // Proportionale Berechnung
        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
        $newWidth  = (int)round($origWidth * $ratio);
        $newHeight = (int)round($origHeight * $ratio);

        $resized = $this->createCanvas($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        $saved = $this->saveImage($resized, $destPath, $quality);
        unset($image);
        unset($resized);

        return $saved ? $destPath : null;
    }

    /**
     * Quadratischen Thumbnail erstellen (Crop aus Mitte).
     *
     * @param string $sourcePath  Quell-Bildpfad
     * @param int    $size        Seitenlänge in Pixel
     * @param string|null $destPath  Ziel-Pfad (null = leitet aus Quelle ab)
     *
     * @return string|null  Pfad zum Thumbnail oder null bei Fehler
     */
    public function createSquareThumbnail(
        string $sourcePath,
        int $size = 150,
        ?string $destPath = null
    ): ?string {
        return $this->createThumbnail($sourcePath, $size, $size, $destPath);
    }

    /**
     * Thumbnail erstellen (Crop aus Mitte, beliebige Proportion).
     *
     * @param string $sourcePath  Quell-Bildpfad
     * @param int    $width       Ziel-Breite
     * @param int    $height      Ziel-Höhe
     * @param string|null $destPath  Ziel-Pfad (null = leitet aus Quelle ab)
     * @param int    $quality     JPEG-/WebP-Qualität (0-100)
     *
     * @return string|null  Pfad zum Thumbnail oder null bei Fehler
     */
    public function createThumbnail(
        string $sourcePath,
        int $width,
        int $height,
        ?string $destPath = null,
        int $quality = 0
    ): ?string {
        if (!$this->gdAvailable || !file_exists($sourcePath)) {
            return null;
        }

        $quality = $quality > 0 ? $quality : $this->defaultQuality;

        // Ziel-Pfad ableiten: /path/to/image.jpg → /path/to/image-150x150.jpg
        if ($destPath === null) {
            $info = pathinfo($sourcePath);
            $destPath = $info['dirname'] . '/' . $info['filename'] . "-{$width}x{$height}." . ($info['extension'] ?? 'jpg');
        }

        $image = $this->loadImage($sourcePath);
        if ($image === null) {
            return null;
        }

        $origWidth  = imagesx($image);
        $origHeight = imagesy($image);

        // Center-Crop berechnen
        $srcRatio = $origWidth / $origHeight;
        $dstRatio = $width / $height;

        if ($srcRatio > $dstRatio) {
            // Bild ist breiter → links/rechts beschneiden
            $cropHeight = $origHeight;
            $cropWidth  = (int)round($origHeight * $dstRatio);
            $srcX = (int)round(($origWidth - $cropWidth) / 2);
            $srcY = 0;
        } else {
            // Bild ist höher → oben/unten beschneiden
            $cropWidth  = $origWidth;
            $cropHeight = (int)round($origWidth / $dstRatio);
            $srcX = 0;
            $srcY = (int)round(($origHeight - $cropHeight) / 2);
        }

        $thumb = $this->createCanvas($width, $height);
        imagecopyresampled($thumb, $image, 0, 0, $srcX, $srcY, $width, $height, $cropWidth, $cropHeight);

        $saved = $this->saveImage($thumb, $destPath, $quality);
        unset($image);
        unset($thumb);

        return $saved ? $destPath : null;
    }

    /**
     * Bild zuschneiden (Crop mit beliebiger Position).
     *
     * @param string $sourcePath  Quell-Bildpfad
     * @param int    $x           Start-X
     * @param int    $y           Start-Y
     * @param int    $width       Crop-Breite
     * @param int    $height      Crop-Höhe
     * @param string|null $destPath  Ziel-Pfad
     *
     * @return string|null
     */
    public function crop(
        string $sourcePath,
        int $x,
        int $y,
        int $width,
        int $height,
        ?string $destPath = null
    ): ?string {
        if (!$this->gdAvailable || !file_exists($sourcePath)) {
            return null;
        }

        $destPath ??= $sourcePath;

        $image = $this->loadImage($sourcePath);
        if ($image === null) {
            return null;
        }

        $cropped = imagecrop($image, [
            'x'      => max(0, $x),
            'y'      => max(0, $y),
            'width'  => $width,
            'height' => $height,
        ]);

        if ($cropped === false) {
            unset($image);
            return null;
        }

        $saved = $this->saveImage($cropped, $destPath, $this->defaultQuality);
        unset($image);
        unset($cropped);

        return $saved ? $destPath : null;
    }

    /**
     * Bild in WebP konvertieren.
     *
     * @param string $sourcePath  Quell-Bildpfad (JPEG/PNG/GIF)
     * @param int    $quality     WebP-Qualität (0-100)
     * @param bool   $deleteOriginal  Original nach Konvertierung löschen?
     *
     * @return string|null  Pfad zur WebP-Datei oder null bei Fehler
     */
    public function convertToWebP(
        string $sourcePath,
        int $quality = 0,
        bool $deleteOriginal = false
    ): ?string {
        if (!$this->gdAvailable || !$this->webpSupport || !file_exists($sourcePath)) {
            return null;
        }

        $quality = $quality > 0 ? $quality : $this->defaultQuality;

        $image = $this->loadImage($sourcePath);
        if ($image === null) {
            return null;
        }

        // Transparenz erhalten
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $webpPath = preg_replace('/\.[a-z]+$/i', '.webp', $sourcePath);
        if ($webpPath === null || $webpPath === $sourcePath) {
            unset($image);
            return null;
        }

        $success = imagewebp($image, $webpPath, $quality);
        unset($image);

        if ($success && $deleteOriginal && $webpPath !== $sourcePath) {
            $this->deleteFile($sourcePath);
        }

        return $success ? $webpPath : null;
    }

    /**
     * Bild in AVIF konvertieren (falls unterstützt).
     *
     * @param string $sourcePath  Quell-Bildpfad
     * @param int    $quality     AVIF-Qualität (0-100)
     * @param int    $speed       Encoding-Geschwindigkeit (0=langsam/besser, 10=schnell/schlechter)
     *
     * @return string|null
     */
    public function convertToAvif(
        string $sourcePath,
        int $quality = 0,
        int $speed = 6
    ): ?string {
        if (!$this->gdAvailable || !$this->avifSupport || !file_exists($sourcePath)) {
            return null;
        }

        $quality = $quality > 0 ? $quality : $this->defaultQuality;

        $image = $this->loadImage($sourcePath);
        if ($image === null) {
            return null;
        }

        $avifPath = preg_replace('/\.[a-z]+$/i', '.avif', $sourcePath);
        if ($avifPath === null || $avifPath === $sourcePath) {
            unset($image);
            return null;
        }

        $success = imageavif($image, $avifPath, $quality, $speed);
        unset($image);

        return $success ? $avifPath : null;
    }

    /**
     * Bild drehen.
     *
     * @param string  $sourcePath  Quell-Bildpfad
     * @param float   $angle       Drehwinkel in Grad (gegen Uhrzeigersinn)
     * @param string|null $destPath
     *
     * @return string|null
     */
    public function rotate(
        string $sourcePath,
        float $angle,
        ?string $destPath = null
    ): ?string {
        if (!$this->gdAvailable || !file_exists($sourcePath)) {
            return null;
        }

        $destPath ??= $sourcePath;

        $image = $this->loadImage($sourcePath);
        if ($image === null) {
            return null;
        }

        // GD dreht im Uhrzeigersinn, daher negieren
        $rotated = imagerotate($image, -$angle, 0);
        if ($rotated === false) {
            unset($image);
            return null;
        }

        $saved = $this->saveImage($rotated, $destPath, $this->defaultQuality);
        unset($image);
        unset($rotated);

        return $saved ? $destPath : null;
    }

    /**
     * EXIF-Orientierung automatisch korrigieren (z. B. Smartphone-Fotos).
     *
     * @param string $sourcePath  Bildpfad (JPEG)
     *
     * @return bool  true wenn korrigiert, false wenn nichts zu tun
     */
    public function autoOrient(string $sourcePath): bool
    {
        if (!$this->gdAvailable || !function_exists('exif_read_data')) {
            return false;
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg'], true)) {
            return false;
        }

        $exif = $this->readExifData($sourcePath);
        if ($exif === false || !isset($exif['Orientation'])) {
            return false;
        }

        $orientation = (int)$exif['Orientation'];
        $angle = match ($orientation) {
            3 => 180,
            6 => 90,
            8 => -90,
            default => 0,
        };

        if ($angle === 0) {
            return false;
        }

        return $this->rotate($sourcePath, (float)$angle) !== null;
    }

    /**
     * Alle konfigurierten Thumbnail-Größen für ein Bild erstellen.
     *
     * @param string $sourcePath  Quell-Bildpfad
     * @param array|null $sizes   Eigene Größen ['name' => [w, h], ...] (null = Standard)
     *
     * @return array<string, string|null>  Map: Size-Name → Thumb-Pfad (null bei Fehler)
     */
    public function createAllThumbnails(string $sourcePath, ?array $sizes = null): array
    {
        $sizes ??= $this->thumbnailSizes;
        $results = [];

        foreach ($sizes as $name => [$width, $height]) {
            $info = pathinfo($sourcePath);
            $destPath = $info['dirname'] . '/' . $info['filename'] . "-{$name}." . ($info['extension'] ?? 'jpg');
            $results[$name] = $this->createThumbnail($sourcePath, $width, $height, $destPath);
        }

        return $results;
    }

    /**
     * Bildabmessungen lesen.
     *
     * @return array{width: int, height: int, type: int, mime: string}|null
     */
    public function getDimensions(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $info = $this->readImageSize($path);
        if ($info === false) {
            return null;
        }

        return [
            'width'  => $info[0],
            'height' => $info[1],
            'type'   => $info[2],
            'mime'   => $info['mime'],
        ];
    }

    /**
     * Wasserzeichen auf ein Bild setzen (Text oder Bild).
     *
     * @param string $sourcePath   Quell-Bildpfad
     * @param string $text         Wasserzeichen-Text (leer → kein Text)
     * @param string|null $watermarkImage  Pfad zu einem Wasserzeichen-Bild (PNG mit Transparenz)
     * @param string $position     Position: 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'center'
     * @param int    $opacity      Deckkraft (0-100, nur für Bild-Wasserzeichen)
     *
     * @return string|null
     */
    public function addWatermark(
        string $sourcePath,
        string $text = '',
        ?string $watermarkImage = null,
        string $position = 'bottom-right',
        int $opacity = 50
    ): ?string {
        if (!$this->gdAvailable || !file_exists($sourcePath)) {
            return null;
        }

        $image = $this->loadImage($sourcePath);
        if ($image === null) {
            return null;
        }

        $imgWidth  = imagesx($image);
        $imgHeight = imagesy($image);

        if (!empty($text)) {
            // Text-Wasserzeichen
            $fontSize = max(12, (int)($imgWidth / 40));
            $color = imagecolorallocatealpha($image, 255, 255, 255, (int)(127 * (100 - $opacity) / 100));
            $shadow = imagecolorallocatealpha($image, 0, 0, 0, (int)(127 * (100 - $opacity) / 100));

            $bbox = imagettfbbox($fontSize, 0, '', $text);
            $textWidth  = abs($bbox[4] - $bbox[0]);
            $textHeight = abs($bbox[5] - $bbox[1]);

            [$x, $y] = $this->calculatePosition($imgWidth, $imgHeight, $textWidth, $textHeight, $position, 10);

            // Font-Fallback: wenn kein TTF → imagestring()
            if (function_exists('imagettftext') && file_exists('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')) {
                $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
                imagettftext($image, $fontSize, 0, $x + 1, $y + $textHeight + 1, $shadow, $font, $text);
                imagettftext($image, $fontSize, 0, $x, $y + $textHeight, $color, $font, $text);
            } else {
                imagestring($image, 5, $x + 1, $y + 1, $text, $shadow);
                imagestring($image, 5, $x, $y, $text, $color);
            }
        }

        if ($watermarkImage !== null && file_exists($watermarkImage)) {
            $wm = $this->loadImage($watermarkImage);
            if ($wm !== null) {
                $wmWidth  = imagesx($wm);
                $wmHeight = imagesy($wm);

                // Wasserzeichen max. 20% der Bildbreite
                $maxWmWidth = (int)($imgWidth * 0.2);
                if ($wmWidth > $maxWmWidth) {
                    $ratio = $maxWmWidth / $wmWidth;
                    $newWmWidth  = $maxWmWidth;
                    $newWmHeight = (int)round($wmHeight * $ratio);
                    $resizedWm = $this->createCanvas($newWmWidth, $newWmHeight);
                    imagecopyresampled($resizedWm, $wm, 0, 0, 0, 0, $newWmWidth, $newWmHeight, $wmWidth, $wmHeight);
                    unset($wm);
                    $wm = $resizedWm;
                    $wmWidth  = $newWmWidth;
                    $wmHeight = $newWmHeight;
                }

                [$x, $y] = $this->calculatePosition($imgWidth, $imgHeight, $wmWidth, $wmHeight, $position, 10);
                imagecopymerge($image, $wm, $x, $y, 0, 0, $wmWidth, $wmHeight, $opacity);
                unset($wm);
            }
        }

        $saved = $this->saveImage($image, $sourcePath, $this->defaultQuality);
        unset($image);

        return $saved ? $sourcePath : null;
    }

    /**
     * Standard-Qualität setzen.
     */
    public function setDefaultQuality(int $quality): void
    {
        $this->defaultQuality = max(1, min(100, $quality));
    }

    /**
     * Eigene Thumbnail-Größen definieren.
     *
     * @param array<string, array{0: int, 1: int}> $sizes  ['name' => [width, height], ...]
     */
    public function setThumbnailSizes(array $sizes): void
    {
        $this->thumbnailSizes = $sizes;
    }

    // ──────────────────────────────────────────────────────────
    //  Interne Helfer
    // ──────────────────────────────────────────────────────────

    /**
     * Bild anhand des Dateityps laden.
     */
    private function loadImage(string $path): ?\GdImage
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $image = match ($ext) {
            'jpg', 'jpeg' => $this->loadImageViaFunction('imagecreatefromjpeg', $path),
            'png'         => $this->loadImageViaFunction('imagecreatefrompng', $path),
            'gif'         => $this->loadImageViaFunction('imagecreatefromgif', $path),
            'webp'        => $this->webpSupport ? $this->loadImageViaFunction('imagecreatefromwebp', $path) : false,
            'bmp'         => function_exists('imagecreatefrombmp') ? $this->loadImageViaFunction('imagecreatefrombmp', $path) : false,
            'avif'        => $this->avifSupport ? $this->loadImageViaFunction('imagecreatefromavif', $path) : false,
            default       => false,
        };

        return ($image instanceof \GdImage) ? $image : null;
    }

    /**
     * Leeres Canvas mit Transparenz erstellen.
     */
    private function createCanvas(int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagealphablending($canvas, true);

        return $canvas;
    }

    /**
     * Bild im richtigen Format speichern.
     */
    private function saveImage(\GdImage $image, string $path, int $quality): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Verzeichnis sicherstellen
        $dir = dirname($path);
        if (!is_dir($dir) && !$this->ensureDirectoryExists($dir)) {
            return false;
        }

        return match ($ext) {
            'jpg', 'jpeg' => imagejpeg($image, $path, $quality),
            'png'         => imagepng($image, $path, (int)round(9 - ($quality / 100 * 9))),
            'gif'         => imagegif($image, $path),
            'webp'        => $this->webpSupport ? imagewebp($image, $path, $quality) : false,
            'bmp'         => function_exists('imagebmp') ? imagebmp($image, $path) : false,
            'avif'        => $this->avifSupport ? imageavif($image, $path, $quality) : false,
            default       => imagejpeg($image, $path, $quality),
        };
    }

    /**
     * Position für Wasserzeichen berechnen.
     *
     * @return array{0: int, 1: int}  [x, y]
     */
    private function calculatePosition(
        int $imgWidth,
        int $imgHeight,
        int $wmWidth,
        int $wmHeight,
        string $position,
        int $padding
    ): array {
        return match ($position) {
            'top-left'     => [$padding, $padding],
            'top-right'    => [$imgWidth - $wmWidth - $padding, $padding],
            'bottom-left'  => [$padding, $imgHeight - $wmHeight - $padding],
            'bottom-right' => [$imgWidth - $wmWidth - $padding, $imgHeight - $wmHeight - $padding],
            'center'       => [(int)(($imgWidth - $wmWidth) / 2), (int)(($imgHeight - $wmHeight) / 2)],
            default        => [$imgWidth - $wmWidth - $padding, $imgHeight - $wmHeight - $padding],
        };
    }

    private function deleteFile(string $path): bool
    {
        if (!file_exists($path)) {
            return true;
        }

        if (!is_writable($path) && !is_writable(dirname($path))) {
            $this->logger->warning('Quelldatei konnte nicht gelöscht werden.', [
                'operation' => 'unlink_preflight',
                'path' => $path,
            ]);

            return false;
        }

        $result = $this->runFilesystemOperation('unlink', $path, static fn (): bool => unlink($path));
        return $result === true;
    }

    private function readExifData(string $path): array|false
    {
        return $this->runFilesystemOperation('exif_read_data', $path, static fn (): array|false => exif_read_data($path));
    }

    private function readImageSize(string $path): array|false
    {
        return $this->runFilesystemOperation('getimagesize', $path, static fn (): array|false => getimagesize($path));
    }

    private function loadImageViaFunction(string $function, string $path): \GdImage|false
    {
        if (!function_exists($function) || !is_file($path) || !is_readable($path)) {
            return false;
        }

        $result = $this->runFilesystemOperation($function, $path, static fn () => $function($path));
        return $result instanceof \GdImage ? $result : false;
    }

    private function ensureDirectoryExists(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        $parentDir = dirname($directory);
        if ($parentDir === $directory || $parentDir === '') {
            return false;
        }

        if (!is_dir($parentDir) && !$this->ensureDirectoryExists($parentDir)) {
            return false;
        }

        if (!is_writable($parentDir)) {
            $this->logger->warning('Bild-Zielverzeichnis ist nicht beschreibbar.', [
                'operation' => 'mkdir_preflight',
                'path' => $directory,
                'parent' => $parentDir,
            ]);

            return false;
        }

        $result = $this->runFilesystemOperation('mkdir', $directory, static fn (): bool => mkdir($directory, 0755));
        return $result === true || is_dir($directory);
    }

    private function runFilesystemOperation(string $operation, string $path, callable $callback): mixed
    {
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        if (($result === false || $result === null) && $warning !== null) {
            $this->logger->warning('Bildoperation fehlgeschlagen.', [
                'operation' => $operation,
                'path' => $path,
                'warning' => $warning,
            ]);
        }

        return $result;
    }
}
