<?php
/**
 * Liest Urheber- und Lizenzhinweise aus eingebetteten Bild-Metadaten (IPTC, EXIF, XMP) für die
 * Vorbelegung beim Upload (ROADMAP #11). Wirft nie: fehlende Erweiterungen oder kaputte
 * Metadaten ergeben einfach keine Hinweise.
 *
 * Quellen und Vorrang (erster Treffer gewinnt):
 * - Urheber:    IPTC 2#080 (By-line) → XMP dc:creator → EXIF Artist
 * - Credit:     IPTC 2#110 (Credit)  → XMP photoshop:Credit
 * - Copyright:  IPTC 2#116           → XMP dc:rights → EXIF Copyright
 * - Quelle:     IPTC 2#115 (nur URL) → XMP xmpRights:WebStatement (sofern keine Lizenz-URL)
 * - Lizenz:     XMP cc:license / xmpRights:WebStatement (Creative-Commons-URL) → Freitext im Copyright
 * - KI:         XMP Iptc4xmpExt:DigitalSourceType (trainedAlgorithmicMedia, compositeWithTrainedAlgorithmicMedia),
 *               Werkzeug aus Iptc4xmpExt:AISystemUsed
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageMetadataReader
{
    /** XMP steht praktisch immer am Dateianfang (JPEG APP1, PNG iTXt, WebP-Chunk). */
    private const XMP_SCAN_BYTES = 262144;
    private const AI_SOURCE_TYPES = ['trainedalgorithmicmedia', 'compositewithtrainedalgorithmicmedia'];
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff'];

    /**
     * Rohwerte im Format von MediaLicenseService::normalize(); leer ohne Hinweise.
     *
     * @return array<string, string|bool>
     */
    public static function readLicenseHints(string $path): array
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_EXTENSIONS, true) || !is_file($path) || !is_readable($path)) {
            return [];
        }

        try {
            $iptc = self::readIptc($path);
            $xmp = self::readXmp($path);
            $exif = self::readExif($path, $extension);
        } catch (\Throwable) {
            return [];
        }

        $hints = [];
        $creator = self::first($iptc['creator'] ?? '', $xmp['creator'] ?? '', $exif['creator'] ?? '');
        if ($creator !== '') {
            $hints['creator'] = $creator;
        }

        $credit = self::first($iptc['credit'] ?? '', $xmp['credit'] ?? '');
        if ($credit !== '') {
            $hints['credit'] = $credit;
        }

        $copyright = self::first($iptc['copyright'] ?? '', $xmp['copyright'] ?? '', $exif['copyright'] ?? '');
        if ($copyright !== '') {
            $hints['copyright'] = $copyright;
        }

        $licenseUrl = '';
        foreach ([$xmp['license'] ?? '', $xmp['web_statement'] ?? ''] as $candidateUrl) {
            if ($candidateUrl !== '' && MediaLicenseService::licenseKeyFromUrl($candidateUrl) !== '') {
                $licenseUrl = $candidateUrl;
                break;
            }
        }
        if ($licenseUrl !== '') {
            $hints['license'] = MediaLicenseService::licenseKeyFromUrl($licenseUrl);
            $hints['license_url'] = $licenseUrl;
        } elseif ($copyright !== '' && ($textKey = MediaLicenseService::licenseKeyFromText($copyright)) !== '') {
            $hints['license'] = $textKey;
        }

        $sourceUrl = self::first(
            self::isUrl($iptc['source'] ?? '') ? (string) $iptc['source'] : '',
            ($xmp['web_statement'] ?? '') !== $licenseUrl && self::isUrl($xmp['web_statement'] ?? '') ? (string) $xmp['web_statement'] : ''
        );
        if ($sourceUrl !== '') {
            $hints['source_url'] = $sourceUrl;
        }

        if (!empty($xmp['ai_generated'])) {
            $hints['ai_generated'] = true;
            if (($xmp['ai_tool'] ?? '') !== '') {
                $hints['ai_tool'] = (string) $xmp['ai_tool'];
            }
        }

        return $hints;
    }

    /** @return array<string, string> */
    private static function readIptc(string $path): array
    {
        if (!function_exists('iptcparse')) {
            return [];
        }

        $info = [];
        if (@getimagesize($path, $info) === false || !isset($info['APP13'])) {
            return [];
        }

        $data = @iptcparse($info['APP13']);
        if (!is_array($data)) {
            return [];
        }

        $value = static function (string $tag) use ($data): string {
            $values = is_array($data[$tag] ?? null) ? $data[$tag] : [];
            $parts = [];
            foreach ($values as $item) {
                $text = self::toUtf8((string) $item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return implode(', ', array_unique($parts));
        };

        return [
            'creator' => $value('2#080'),
            'credit' => $value('2#110'),
            'source' => $value('2#115'),
            'copyright' => $value('2#116'),
        ];
    }

    /** @return array<string, string> */
    private static function readExif(string $path, string $extension): array
    {
        if (!function_exists('exif_read_data') || !in_array($extension, ['jpg', 'jpeg', 'tif', 'tiff', 'webp'], true)) {
            return [];
        }

        $data = @exif_read_data($path, 'IFD0', false);
        if (!is_array($data)) {
            return [];
        }

        return [
            'creator' => self::toUtf8((string) (is_scalar($data['Artist'] ?? null) ? $data['Artist'] : '')),
            'copyright' => self::toUtf8((string) (is_scalar($data['Copyright'] ?? null) ? $data['Copyright'] : '')),
        ];
    }

    /** @return array<string, string|bool> */
    private static function readXmp(string $path): array
    {
        $head = @file_get_contents($path, false, null, 0, self::XMP_SCAN_BYTES);
        if (!is_string($head)) {
            return [];
        }

        $start = strpos($head, '<x:xmpmeta');
        $end = $start === false ? false : strpos($head, '</x:xmpmeta>', $start);
        if ($start === false || $end === false) {
            return [];
        }

        $xmp = substr($head, $start, $end - $start);
        $digitalSourceType = strtolower(self::xmpValue($xmp, 'Iptc4xmpExt:DigitalSourceType'));
        $aiGenerated = false;
        foreach (self::AI_SOURCE_TYPES as $type) {
            if ($digitalSourceType === $type || str_ends_with($digitalSourceType, '/' . $type)) {
                $aiGenerated = true;
            }
        }

        return [
            'creator' => self::xmpListValue($xmp, 'dc:creator'),
            'copyright' => self::xmpListValue($xmp, 'dc:rights'),
            'credit' => self::xmpValue($xmp, 'photoshop:Credit'),
            'license' => self::xmpValue($xmp, 'cc:license'),
            'web_statement' => self::xmpValue($xmp, 'xmpRights:WebStatement'),
            'ai_generated' => $aiGenerated,
            'ai_tool' => $aiGenerated ? self::xmpValue($xmp, 'Iptc4xmpExt:AISystemUsed') : '',
        ];
    }

    /** Einfacher Wert als Attribut (`name="…"`), Ressource (`<name rdf:resource="…"/>`) oder Element. */
    private static function xmpValue(string $xmp, string $name): string
    {
        $quoted = preg_quote($name, '/');
        if (preg_match('/\s' . $quoted . '\s*=\s*"([^"]*)"/', $xmp, $match) === 1
            || preg_match('/<' . $quoted . '\b[^>]*\srdf:resource\s*=\s*"([^"]*)"/', $xmp, $match) === 1
            || preg_match('/<' . $quoted . '(?:\s[^>]*)?>([^<]*)<\/' . $quoted . '>/', $xmp, $match) === 1
        ) {
            return self::decodeXml($match[1]);
        }

        return self::xmpListValue($xmp, $name);
    }

    /** Erster Eintrag einer rdf:Seq/rdf:Alt/rdf:Bag-Liste (bei Alt bevorzugt x-default). */
    private static function xmpListValue(string $xmp, string $name): string
    {
        $quoted = preg_quote($name, '/');
        if (preg_match('/<' . $quoted . '(?:\s[^>]*)?>(.*?)<\/' . $quoted . '>/s', $xmp, $block) !== 1) {
            return '';
        }

        if (preg_match('/<rdf:li\b[^>]*xml:lang\s*=\s*"x-default"[^>]*>([^<]*)<\/rdf:li>/', $block[1], $match) === 1
            || preg_match('/<rdf:li\b[^>]*>([^<]*)<\/rdf:li>/', $block[1], $match) === 1
        ) {
            return self::decodeXml($match[1]);
        }

        return '';
    }

    private static function decodeXml(string $value): string
    {
        return self::toUtf8(html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /** Ungültiges UTF-8 (ältere IPTC-Daten) wird als ISO-8859-1 gelesen; Steuerzeichen werden entfernt. */
    private static function toUtf8(string $value): string
    {
        $value = str_replace("\0", ' ', $value);
        if (preg_match('//u', $value) !== 1) {
            $value = function_exists('mb_convert_encoding')
                ? (string) mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1')
                : (string) @iconv('ISO-8859-1', 'UTF-8//IGNORE', $value);
        }

        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function isUrl(mixed $value): bool
    {
        return is_string($value) && preg_match('#^https?://[^\s]+$#i', $value) === 1;
    }

    private static function first(string ...$values): string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
