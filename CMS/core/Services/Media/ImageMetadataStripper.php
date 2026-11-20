<?php
/**
 * Entfernt Metadaten aus JPEG, PNG und WebP, ohne die Bilddaten neu zu kodieren (Einstellung `strip_exif`).
 *
 * Genutzt für originalerhaltende Uploads (Mediathek, Mitgliederbereich, Bildersatz, Bilder im Editor) und für WebP in
 * den übrigen Upload-Wegen. Die komprimierten Bilddaten bleiben Byte für Byte erhalten; Qualität, Farbtiefe und Größe
 * des Bildes ändern sich nicht. Entfernt werden:
 *
 * - JPEG: APP1 (EXIF samt GPS und Vorschaubild, XMP), APP13 (IPTC/Photoshop), APP2 außer dem ICC-Profil (z. B. MPF),
 *   die übrigen APPn außer JFIF (APP0, ohne dessen Vorschaubild) und Adobe (APP14, nötig für die Farbumwandlung),
 *   Kommentare (COM) und alles nach EOI (angehängte Bilder, Bewegtbilder).
 * - PNG: `eXIf`, `tEXt`, `zTXt`, `iTXt`, `tIME` und alles nach `IEND`.
 * - WebP: `EXIF` und `XMP ` (die Flags im `VP8X`-Kopf werden angepasst) und alles nach dem RIFF-Block.
 *
 * Darstellungsrelevantes bleibt: ICC-Profil, Gamma und Farbraum, Pixeldichte, Alphakanal, Animation. War eine
 * Ausrichtung ungleich 1 gesetzt, bleibt genau dieses Tag als minimaler EXIF-Block stehen, damit Browser und die
 * GD-Verarbeitung (`ImageOrientation`) das Bild weiter aufrecht zeigen.
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageMetadataStripper
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Metadaten entfernt und Datei ersetzt. */
    public const STATUS_STRIPPED = 'stripped';
    /** Keine Metadaten gefunden, Datei unverändert. */
    public const STATUS_CLEAN = 'clean';
    /** Kein JPEG, PNG oder WebP (Signatur), Datei unverändert. */
    public const STATUS_UNSUPPORTED = 'unsupported';
    /** Datei nicht les-/schreibbar oder Aufbau ungültig, Datei unverändert. */
    public const STATUS_FAILED = 'failed';

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";
    private const PNG_METADATA_CHUNKS = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];
    private const WEBP_FLAG_EXIF = 0x08;
    private const WEBP_FLAG_XMP = 0x04;

    /** Datei an Ort und Stelle bereinigen (temporäre Datei im selben Verzeichnis, dann `rename()`). */
    public static function stripFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path) || !is_writable($path) || !is_writable(dirname($path))) {
            return self::STATUS_FAILED;
        }

        set_error_handler(static fn (): bool => true);
        try {
            $data = file_get_contents($path);
            if (!is_string($data) || $data === '') {
                return self::STATUS_FAILED;
            }
            if (self::format($data) === null) {
                return self::STATUS_UNSUPPORTED;
            }
            $stripped = self::strip($data);
            if ($stripped === null) {
                return self::STATUS_FAILED;
            }
            if ($stripped === $data) {
                return self::STATUS_CLEAN;
            }

            $temporary = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.strip-' . bin2hex(random_bytes(4));
            if (file_put_contents($temporary, $stripped, LOCK_EX) !== strlen($stripped)) {
                @unlink($temporary);

                return self::STATUS_FAILED;
            }
            $mode = fileperms($path);
            if (is_int($mode)) {
                chmod($temporary, $mode & 0777);
            }
            if (!rename($temporary, $path)) {
                @unlink($temporary);

                return self::STATUS_FAILED;
            }
            clearstatcache(true, $path);

            return self::STATUS_STRIPPED;
        } finally {
            restore_error_handler();
        }
    }

    /** Bereinigte Daten; dieselbe Zeichenkette, wenn nichts zu entfernen ist; null bei unbekanntem Format oder Aufbau. */
    public static function strip(string $data): ?string
    {
        return match (self::format($data)) {
            'jpeg' => self::stripJpeg($data),
            'png' => self::stripPng($data),
            'webp' => self::stripWebp($data),
            default => null,
        };
    }

    /** Format anhand der Signatur: `jpeg`, `png`, `webp` oder null. */
    public static function format(string $data): ?string
    {
        return match (true) {
            str_starts_with($data, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($data, self::PNG_SIGNATURE) => 'png',
            strlen($data) >= 12 && str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }

    // ── JPEG ────────────────────────────────────────────────────────────────

    private static function stripJpeg(string $data): ?string
    {
        $length = strlen($data);
        $out = "\xFF\xD8";
        $pos = 2;
        $orientationKept = false;

        while ($pos < $length) {
            if ($data[$pos] !== "\xFF") {
                return null;
            }
            while ($pos < $length && $data[$pos] === "\xFF") {
                $pos++; // Füllbytes vor dem Marker
            }
            if ($pos >= $length) {
                return null;
            }
            $marker = ord($data[$pos]);
            $pos++;

            if ($marker === 0xD9) {
                return $out . "\xFF\xD9"; // EOI: angehängte Daten entfallen
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= "\xFF" . chr($marker); // Marker ohne Länge
                continue;
            }
            if ($pos + 2 > $length) {
                return null;
            }
            $segmentLength = (int) unpack('n', substr($data, $pos, 2))[1];
            if ($segmentLength < 2 || $pos + $segmentLength > $length) {
                return null;
            }
            $payload = substr($data, $pos + 2, $segmentLength - 2);
            $segment = "\xFF" . chr($marker) . substr($data, $pos, $segmentLength);
            $pos += $segmentLength;

            if ($marker === 0xDA) {
                // SOS: Kopf und kodierte Daten unverändert bis zum nächsten Marker; ohne EOI bleibt der Rest stehen.
                $next = self::nextJpegMarker($data, $pos);
                $out .= $segment . substr($data, $pos, ($next ?? $length) - $pos);
                if ($next === null) {
                    return $out;
                }
                $pos = $next;
                continue;
            }

            if ($marker === 0xE0 && str_starts_with($payload, "JFIF\0")) {
                // JFIF behalten (Pixeldichte), ein eingebettetes Vorschaubild nicht.
                $out .= strlen($payload) > 14
                    ? "\xFF\xE0" . pack('n', 16) . substr($payload, 0, 12) . "\x00\x00"
                    : $segment;
                continue;
            }
            if (self::keepsJpegSegment($marker, $payload)) {
                $out .= $segment;
                continue;
            }
            if (!$orientationKept && $marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
                $orientation = ImageOrientation::fromTiff(substr($payload, 6));
                if ($orientation !== ImageOrientation::NORMAL) {
                    $tiff = "Exif\0\0" . ImageOrientation::tiffWithOrientation($orientation);
                    $out .= "\xFF\xE1" . pack('n', strlen($tiff) + 2) . $tiff;
                    $orientationKept = true;
                }
            }
        }

        return null;
    }

    private static function keepsJpegSegment(int $marker, string $payload): bool
    {
        return match (true) {
            $marker === 0xE2 => str_starts_with($payload, "ICC_PROFILE\0"),
            $marker === 0xEE => str_starts_with($payload, 'Adobe'),
            ($marker >= 0xE0 && $marker <= 0xEF) || $marker === 0xFE => false,
            default => true,
        };
    }

    /** Position des nächsten Markers nach kodierten Daten (überspringt `FF00`, `RSTn` und Füllbytes). */
    private static function nextJpegMarker(string $data, int $pos): ?int
    {
        $length = strlen($data);
        while (($pos = strpos($data, "\xFF", $pos)) !== false && $pos + 1 < $length) {
            $byte = ord($data[$pos + 1]);
            if ($byte === 0xFF) {
                $pos++;
                continue;
            }
            if ($byte === 0x00 || ($byte >= 0xD0 && $byte <= 0xD7)) {
                $pos += 2;
                continue;
            }

            return $pos;
        }

        return null;
    }

    // ── PNG ─────────────────────────────────────────────────────────────────

    private static function stripPng(string $data): ?string
    {
        $length = strlen($data);
        $out = self::PNG_SIGNATURE;
        $pos = strlen(self::PNG_SIGNATURE);
        $orientationKept = false;

        while ($pos + 12 <= $length) {
            $chunkLength = (int) unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            if ($chunkLength > $length - $pos - 12 || preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
                return null;
            }
            $chunk = substr($data, $pos, $chunkLength + 12);
            $pos += $chunkLength + 12;

            if (in_array($type, self::PNG_METADATA_CHUNKS, true)) {
                if ($type === 'eXIf' && !$orientationKept) {
                    $orientation = ImageOrientation::fromTiff(substr($chunk, 8, $chunkLength));
                    if ($orientation !== ImageOrientation::NORMAL) {
                        $tiff = ImageOrientation::tiffWithOrientation($orientation);
                        $out .= pack('N', strlen($tiff)) . 'eXIf' . $tiff . pack('N', crc32('eXIf' . $tiff));
                        $orientationKept = true;
                    }
                }
                continue;
            }

            $out .= $chunk;
            if ($type === 'IEND') {
                return $out; // angehängte Daten entfallen
            }
        }

        return null;
    }

    // ── WebP ────────────────────────────────────────────────────────────────

    private static function stripWebp(string $data): ?string
    {
        $end = min(strlen($data), 8 + (int) unpack('V', substr($data, 4, 4))[1]);
        $pos = 12;
        $out = '';
        $vp8x = null;
        $orientationKept = false;

        while ($pos + 8 <= $end) {
            $fourcc = substr($data, $pos, 4);
            $size = (int) unpack('V', substr($data, $pos + 4, 4))[1];
            if ($size > $end - $pos - 8) {
                return null;
            }
            $chunk = substr($data, $pos, $size + 8) . ($size % 2 === 1 ? "\x00" : '');
            $pos += $size + 8 + $size % 2;

            if ($fourcc === 'EXIF') {
                if (!$orientationKept) {
                    $orientation = ImageOrientation::fromTiff(substr($chunk, 8, $size));
                    if ($orientation !== ImageOrientation::NORMAL) {
                        $tiff = ImageOrientation::tiffWithOrientation($orientation);
                        $out .= 'EXIF' . pack('V', strlen($tiff)) . $tiff . (strlen($tiff) % 2 === 1 ? "\x00" : '');
                        $orientationKept = true;
                    }
                }
                continue;
            }
            if ($fourcc === 'XMP ') {
                continue;
            }
            if ($fourcc === 'VP8X') {
                $vp8x = strlen($out);
            }
            $out .= $chunk;
        }
        if ($out === '') {
            return null;
        }

        if ($vp8x !== null && strlen($out) > $vp8x + 8) {
            $flags = ord($out[$vp8x + 8]) & ~(self::WEBP_FLAG_EXIF | self::WEBP_FLAG_XMP);
            $out[$vp8x + 8] = chr($flags | ($orientationKept ? self::WEBP_FLAG_EXIF : 0));
        }

        return 'RIFF' . pack('V', strlen($out) + 4) . 'WEBP' . $out;
    }
}
