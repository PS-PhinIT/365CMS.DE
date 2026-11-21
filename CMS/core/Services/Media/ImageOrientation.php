<?php
/**
 * EXIF-Ausrichtung für die GD-Bildverarbeitung.
 *
 * Smartphones speichern Hochformat-Fotos oft quer und setzen nur das EXIF-Tag `Orientation`. GD verwirft beim Kodieren
 * alle Metadaten: Ohne Korrektur kämen neu kodierte Originale, WebP-, AVIF- und Vorschaubilder quer oder gespiegelt
 * heraus. Deshalb wird die Ausrichtung nach dem Laden auf die Pixel angewendet; die GD-Ausgabe ist danach aufrecht und
 * trägt kein Ausrichtungs-Tag mehr.
 *
 * - `read()` liest das Tag nur aus JPEG-Dateien (`exif_read_data`; PHP 8.3 liest weder PNG-`eXIf` noch WebP-EXIF).
 *   Ohne `ext-exif` gilt jede Datei als aufrecht (Wert 1).
 * - `apply()` bildet alle acht Werte ab; `imagerotate()` dreht gegen den Uhrzeigersinn.
 * - `fromTiff()` und `tiffWithOrientation()` lesen bzw. schreiben das Tag in einem TIFF-Block (EXIF-Nutzdaten). Sie
 *   dienen `ImageMetadataStripper`, der beim verlustfreien Entfernen der Metadaten nur die Ausrichtung stehen lässt.
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageOrientation
{
    public const NORMAL = 1;

    private const TAG_ORIENTATION = 0x0112;
    private const TYPE_SHORT = 3;

    /** EXIF-Ausrichtung (1–8) einer JPEG-Datei; 1, wenn keine gesetzt, lesbar oder unterstützt ist. */
    public static function read(string $path): int
    {
        if (!function_exists('exif_read_data') || !is_file($path) || !is_readable($path)) {
            return self::NORMAL;
        }

        set_error_handler(static fn (): bool => true);
        try {
            // Signatur statt Endung: auch temporäre Upload-Dateien (ohne Endung) werden erkannt.
            $exif = file_get_contents($path, false, null, 0, 3) === "\xFF\xD8\xFF" ? exif_read_data($path) : false;
        } catch (\Throwable) {
            $exif = false;
        } finally {
            restore_error_handler();
        }

        $value = is_array($exif) ? ($exif['Orientation'] ?? null) : null;
        if (is_array($value)) {
            $value = reset($value);
        }

        return self::normalize(is_numeric($value) ? (int) $value : self::NORMAL);
    }

    /**
     * Ausrichtung auf die Pixel anwenden. Liefert das (ggf. neue) Bild; schlägt das Drehen fehl, bleibt es unverändert.
     * Der Alphakanal bleibt erhalten; `imagesavealpha()` setzt der Aufrufer vor dem Speichern.
     */
    public static function apply(\GdImage $image, int $orientation): \GdImage
    {
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if (!$rotated instanceof \GdImage) {
                return $image;
            }
            $image = $rotated;
        }

        $flip = match ($orientation) {
            2, 5, 7 => IMG_FLIP_HORIZONTAL,
            4 => IMG_FLIP_VERTICAL,
            default => 0,
        };
        if ($flip !== 0) {
            imageflip($image, $flip);
        }

        return $image;
    }

    /** `read()` und `apply()` in einem Schritt (nach dem Laden einer Datei mit GD). */
    public static function orient(\GdImage $image, string $path): \GdImage
    {
        return self::apply($image, self::read($path));
    }

    /** Ausrichtungen 5–8 vertauschen Breite und Höhe. */
    public static function swapsDimensions(int $orientation): bool
    {
        return $orientation >= 5 && $orientation <= 8;
    }

    /** Ausrichtung aus einem TIFF-Block (EXIF-Nutzdaten, wahlweise mit Präfix `Exif\0\0`) lesen. */
    public static function fromTiff(string $tiff): int
    {
        if (str_starts_with($tiff, "Exif\0\0")) {
            $tiff = substr($tiff, 6);
        }
        $length = strlen($tiff);
        $order = substr($tiff, 0, 2);
        if ($length < 8 || ($order !== 'II' && $order !== 'MM')) {
            return self::NORMAL;
        }
        [$short, $long] = $order === 'II' ? ['v', 'V'] : ['n', 'N'];
        if (unpack($short, substr($tiff, 2, 2))[1] !== 42) {
            return self::NORMAL;
        }

        $ifd = (int) unpack($long, substr($tiff, 4, 4))[1];
        if ($ifd < 8 || $ifd > $length - 2) {
            return self::NORMAL;
        }
        $count = (int) unpack($short, substr($tiff, $ifd, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > $length) {
                break;
            }
            if (unpack($short, substr($tiff, $entry, 2))[1] !== self::TAG_ORIENTATION) {
                continue;
            }
            if (unpack($short, substr($tiff, $entry + 2, 2))[1] !== self::TYPE_SHORT) {
                return self::NORMAL;
            }

            return self::normalize((int) unpack($short, substr($tiff, $entry + 8, 2))[1]);
        }

        return self::NORMAL;
    }

    /** Minimaler TIFF-Block (Big Endian) mit genau einem Eintrag: der Ausrichtung. */
    public static function tiffWithOrientation(int $orientation): string
    {
        return "MM\x00\x2A" . pack('N', 8)
            . pack('n', 1)
            . pack('nnN', self::TAG_ORIENTATION, self::TYPE_SHORT, 1) . pack('n', self::normalize($orientation)) . "\x00\x00"
            . pack('N', 0);
    }

    private static function normalize(int $orientation): int
    {
        return $orientation >= 1 && $orientation <= 8 ? $orientation : self::NORMAL;
    }
}
