<?php
/**
 * Findet Verweise auf Mediathek-Dateien in beliebigen Werten (Editor.js-JSON, HTML, URLs, CSS `url()`) und führt sie
 * auf den Originalpfad relativ zu `uploads/` zurück (4.1.0, Bereich 2b).
 *
 * Erkannt werden: `/uploads/…` (auch unter einem Unterverzeichnis der Site), absolute URLs der eigenen Domain(s),
 * `/media-file?path=…` (geschützte Auslieferung) und `srcset`-Listen. Fremde Domains zählen nie.
 *
 * Rückführung auf das Original: erzeugte Größen (`-small`, `-medium`, `-large`, `-banner`, `-300x200`) sowie
 * WebP/AVIF-Fassungen zählen für das Original – aber nur, wenn das Original existiert (`$exists`); sonst ist die
 * Datei selbst ein Original (z. B. ein hochgeladenes `team-small.jpg` oder `logo.webp`).
 *
 * Rein (ohne Datenbank und Dateisystem): Domains, Pfade und die Existenzprüfung werden übergeben.
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaPathNormalizer
{
    public const SIZE_SUFFIXES = ['small', 'medium', 'large', 'banner'];
    private const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];
    private const MAX_DEPTH = 12;

    /** @var list<string> */
    private array $hosts;
    /** @var list<string> */
    private array $uploadPrefixes;
    /** @var list<string> */
    private array $deliveryPaths;
    /** @var \Closure(string): bool relativer Pfad existiert in uploads/? */
    private \Closure $exists;

    /**
     * @param list<string> $hosts eigene Hostnamen (klein)
     * @param list<string> $uploadPrefixes URL-Pfade von uploads/ (z. B. `/uploads/`, `/cms/uploads/`)
     * @param list<string> $deliveryPaths Pfade der geschützten Auslieferung (z. B. `/media-file`)
     * @param \Closure(string): bool $exists
     */
    public function __construct(array $hosts, array $uploadPrefixes, array $deliveryPaths, \Closure $exists)
    {
        $this->hosts = array_values(array_filter(array_map(static fn (string $h): string => strtolower(trim($h)), $hosts)));
        $this->uploadPrefixes = array_values(array_unique(array_map(static fn (string $p): string => '/' . trim($p, '/') . '/', $uploadPrefixes)));
        $this->deliveryPaths = array_values(array_unique(array_map(static fn (string $p): string => '/' . trim($p, '/'), $deliveryPaths)));
        $this->exists = $exists;
    }

    /**
     * Normalisierer für diese Installation (SITE_URL, UPLOAD_URL, UPLOAD_PATH).
     */
    public static function forSite(): self
    {
        $siteUrl = defined('SITE_URL') ? (string) SITE_URL : '';
        $uploadUrl = defined('UPLOAD_URL') ? (string) UPLOAD_URL : $siteUrl . '/uploads';
        $uploadPath = rtrim(defined('UPLOAD_PATH') ? (string) UPLOAD_PATH : ABSPATH . 'uploads/', '/\\') . '/';
        $sitePath = trim((string) (parse_url($siteUrl, PHP_URL_PATH) ?? ''), '/');
        $uploadUrlPath = trim((string) (parse_url($uploadUrl, PHP_URL_PATH) ?? ''), '/');

        return new self(
            array_values(array_filter([
                (string) (parse_url($siteUrl, PHP_URL_HOST) ?? ''),
                (string) (parse_url($uploadUrl, PHP_URL_HOST) ?? ''),
            ])),
            array_values(array_filter(['uploads', $uploadUrlPath])),
            array_values(array_filter(['media-file', $sitePath !== '' ? $sitePath . '/media-file' : ''])),
            static fn (string $relative): bool => is_file($uploadPath . $relative)
        );
    }

    /**
     * Alle Medienpfade (Originale) in einem Wert.
     *
     * @return list<string>
     */
    public function extract(mixed $value): array
    {
        $raw = [];
        $this->collect($value, $raw, 0);
        $originals = [];
        foreach (array_keys($raw) as $path) {
            $originals[$this->toOriginal($path)] = true;
        }

        return array_keys($originals);
    }

    /**
     * @param array<string, true> $found
     */
    private function collect(mixed $value, array &$found, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->collect($nested, $found, $depth + 1);
            }

            return;
        }
        if (is_object($value)) {
            $this->collect(get_object_vars($value), $found, $depth + 1);

            return;
        }
        if (!is_string($value)) {
            return;
        }
        $value = trim($value);
        if ($value === '') {
            return;
        }
        if (($value[0] === '{' || $value[0] === '[') && strlen($value) < 5_000_000) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $this->collect($decoded, $found, $depth + 1);

                return;
            }
        }
        foreach ($this->candidates($value) as $candidate) {
            $relative = $this->toRelative($candidate);
            if ($relative !== '') {
                $found[$relative] = true;
            }
        }
    }

    /**
     * Mögliche URL-/Pfadangaben in einem Text (HTML-Attribute, srcset, CSS url(), nackter Wert).
     *
     * @return list<string>
     */
    public function candidates(string $value): array
    {
        $decoded = html_entity_decode(str_replace('\/', '/', $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $candidates = [$decoded];
        if (preg_match_all('#https?://[^\s<>"\'),]+|//[A-Za-z0-9.-]+/[^\s<>"\'),]+|/[^\s<>"\'(),]*?(?:uploads/|media-file\?)[^\s<>"\'),]+#i', $decoded, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $candidates[] = (string) $match;
            }
        }
        if (preg_match_all('#url\(\s*([^)]+)\)#i', $decoded, $urlMatches) > 0) {
            foreach ($urlMatches[1] as $match) {
                $candidates[] = trim((string) $match, " \t\n\r\"'");
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $candidates), static fn (string $c): bool => $c !== '')));
    }

    /**
     * Eine Angabe (URL, Pfad) → relativer Pfad in uploads/ oder ''.
     */
    public function toRelative(string $candidate): string
    {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\"'");
        if ($candidate === '' || strlen($candidate) > 2048) {
            return '';
        }
        // srcset-Eintrag „datei.jpg 2x“ / „datei.jpg 800w“
        $candidate = (string) preg_replace('/\s+\d+(?:\.\d+)?[wx]$/i', '', $candidate);
        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:' . $candidate;
        }
        if (str_starts_with($candidate, 'media-file?')) {
            $candidate = '/' . $candidate;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== '' && !in_array($host, $this->hosts, true)) {
            return '';
        }
        $path = '/' . ltrim((string) preg_replace('#/+#', '/', str_replace('\\', '/', (string) ($parts['path'] ?? ''))), '/');

        foreach ($this->deliveryPaths as $delivery) {
            if ($path === $delivery) {
                parse_str((string) ($parts['query'] ?? ''), $query);

                return self::cleanRelative(rawurldecode((string) ($query['path'] ?? '')));
            }
        }
        foreach ($this->uploadPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return self::cleanRelative(rawurldecode(substr($path, strlen($prefix))));
            }
        }
        // Nackte Angabe „uploads/2026/bild.jpg“ (ohne führenden Schrägstrich, ohne Host)
        if ($host === '' && $scheme === '' && preg_match('#^uploads/#i', (string) ($parts['path'] ?? '')) === 1) {
            return self::cleanRelative(rawurldecode(substr((string) $parts['path'], 8)));
        }

        return '';
    }

    /**
     * Derivat → Original, wenn das Original existiert.
     */
    public function toOriginal(string $relative): string
    {
        $relative = self::cleanRelative($relative);
        if ($relative === '') {
            return '';
        }
        $info = pathinfo($relative);
        $dir = ($info['dirname'] ?? '.') !== '.' ? $info['dirname'] . '/' : '';
        $name = (string) ($info['filename'] ?? '');
        $extension = strtolower((string) ($info['extension'] ?? ''));
        if ($name === '' || $extension === '') {
            return $relative;
        }

        $baseNames = [$name];
        if (preg_match('/^(.+)-(?:' . implode('|', self::SIZE_SUFFIXES) . '|\d{1,5}x\d{1,5})$/i', $name, $m) === 1) {
            array_unshift($baseNames, $m[1]);
        }
        $extensions = in_array($extension, ['webp', 'avif'], true)
            ? array_merge(self::RASTER_EXTENSIONS, $extension === 'avif' ? ['webp'] : [])
            : [$extension];

        foreach ($baseNames as $base) {
            foreach ($extensions as $candidateExtension) {
                $candidate = $dir . $base . '.' . $candidateExtension;
                if ($candidate !== $relative && ($this->exists)($candidate)) {
                    return $candidate;
                }
            }
        }
        // Größe eines WebP/AVIF-Originals (bild-small.webp → bild.webp)
        if ($baseNames[0] !== $name && ($this->exists)($dir . $baseNames[0] . '.' . $extension)) {
            return $dir . $baseNames[0] . '.' . $extension;
        }

        return $relative;
    }

    /**
     * Pfade, unter denen ein Medium im Index stehen kann: der Pfad selbst und – für Größen/Fassungen – das Original.
     *
     * @return list<string>
     */
    public function lookupPaths(string $relative): array
    {
        $relative = self::cleanRelative($relative);
        if ($relative === '') {
            return [];
        }

        return array_values(array_unique([$relative, $this->toOriginal($relative)]));
    }

    public static function cleanRelative(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = trim((string) preg_replace('#/+#', '/', $path), '/');
        if ($path === '' || strlen($path) > 500 || str_contains($path, '..') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }

        return preg_match('#^[A-Za-z0-9._\-/ ]+$#', $path) === 1 ? $path : '';
    }
}
