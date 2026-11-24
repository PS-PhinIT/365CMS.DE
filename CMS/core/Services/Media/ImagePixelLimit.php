<?php
/**
 * Pixelgrenze vor dem Dekodieren von Rasterbildern (Schutz vor Dekompressionsbomben).
 *
 * Ein kleines PNG oder JPEG kann auf viele Gigabyte Pixelspeicher aufgehen: 12 000 × 12 000 Pixel sind als
 * einfarbiges PNG nur rund 400 KB groß, belegen beim Dekodieren mit GD aber etwa 1 GB. GD reserviert diesen Speicher
 * außerhalb des PHP-`memory_limit`; ein Upload kann so den Arbeitsspeicher des Servers aufbrauchen. Die Grenze liest
 * nur den Dateikopf (`getimagesize()`) und entscheidet vor jedem `imagecreatefrom*`.
 *
 * Standard: 50 Megapixel (reicht für 8000 × 6000). Mit der Konstante `CMS_MAX_IMAGE_PIXELS` in `config/app.php`
 * lässt sich der Wert anheben oder senken (mindestens 1 000 000).
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

final class ImagePixelLimit
{
    public const DEFAULT_MAX_PIXELS = 50_000_000;

    public static function maxPixels(): int
    {
        return defined('CMS_MAX_IMAGE_PIXELS')
            ? max(1_000_000, (int) CMS_MAX_IMAGE_PIXELS)
            : self::DEFAULT_MAX_PIXELS;
    }

    public static function allows(int $width, int $height): bool
    {
        if ($width <= 0 || $height <= 0) {
            return true; // unbekannte Maße: die Lader lehnen ungültige Bilder selbst ab
        }

        return $width * $height <= self::maxPixels();
    }

    /**
     * false nur, wenn der Dateikopf Maße nennt, die über der Grenze liegen. Nicht lesbare oder keine Bilddateien
     * ergeben true; sie scheitern später beim Laden.
     */
    public static function allowsFile(string $path): bool
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return true;
        }

        $info = @getimagesize($path);
        if (!is_array($info)) {
            return true;
        }

        return self::allows((int) ($info[0] ?? 0), (int) ($info[1] ?? 0));
    }
}
