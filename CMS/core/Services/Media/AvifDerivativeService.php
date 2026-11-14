<?php
/**
 * AVIF-Derivate (4.1.0, Bereich 2a): Erzeugung neben WebP, Erkennung der Unterstützung, Auslieferung über
 * `<picture>`-Quellen und Aufräumen beim Löschen.
 *
 * - Erkennung: `imageavif()` vorhanden **und** eine Probe-Kodierung eines 8×8-Bildes gelingt (manche GD-Builds
 *   melden die Funktion, scheitern aber). Ergebnis in der Option `media_capabilities`, aufgefrischt beim Speichern
 *   der Medien-Einstellungen.
 * - Erzeugung je Bildgröße (Original und erzeugte Größen `-small`, `-medium`, …): erst in eine temporäre Datei,
 *   behalten nur, wenn sie **kleiner** ist als die WebP-Fassung derselben Größe (sonst als das Ausgangsbild).
 *   Bilder über dem Pixel-Budget werden übersprungen; `set_time_limit` bleibt unverändert.
 * - Auslieferung: `pictureSources()` liefert AVIF/WebP-Quellen nur für vorhandene Dateien unter `UPLOAD_URL`.
 * - Hook `cms_media_derivative_created` (Pfad, Format, Bytes).
 *
 * Kodierer, Dateigröße/Abmessungen, Einstellungen, Optionen und Upload-Pfade sind für `TESTS/media-avif`
 * austauschbar (Konstruktor-Parameter `$deps`).
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

use CMS\Hooks;

if (!defined('ABSPATH')) {
    exit;
}

final class AvifDerivativeService
{
    public const OPTION_CAPABILITIES = 'media_capabilities';
    public const DEFAULT_QUALITY = 50;
    public const DEFAULT_SPEED = 6;
    /** Pixel-Budget je Bild (Breite × Höhe): AVIF-Kodierung braucht deutlich mehr Speicher und Zeit als WebP. */
    public const MAX_PIXELS = 25_000_000;
    public const SOURCE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    public const SIZE_SUFFIXES = ['small', 'medium', 'large', 'banner'];

    public const STATUS_CREATED = 'created';
    public const STATUS_EXISTS = 'exists';
    public const STATUS_LARGER = 'discarded_larger';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    private static ?self $instance = null;

    /** @var \Closure(string, string, int, int): bool Quelle → Ziel kodieren */
    private \Closure $encoder;
    /** @var \Closure(): bool Probe-Kodierung */
    private \Closure $probe;
    /** @var \Closure(string): ?array{0:int,1:int} Abmessungen */
    private \Closure $dimensions;
    /** @var \Closure(): array<string, mixed> Medien-Einstellungen */
    private \Closure $settingsReader;
    /** @var \Closure(): ?string Option lesen (JSON) */
    private \Closure $optionReader;
    /** @var \Closure(string): void Option schreiben (JSON) */
    private \Closure $optionWriter;
    private string $uploadPath;
    private string $uploadUrl;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @param array<string, mixed> $deps encoder, probe, dimensions, settings, optionGet, optionSet, uploadPath, uploadUrl
     */
    public function __construct(array $deps = [])
    {
        $this->encoder = $deps['encoder'] ?? static fn (string $source, string $target, int $quality, int $speed): bool
            => self::gdEncode($source, $target, $quality, $speed);
        $this->probe = $deps['probe'] ?? static fn (): bool => self::gdProbe();
        $this->dimensions = $deps['dimensions'] ?? static function (string $path): ?array {
            $info = @getimagesize($path);

            return is_array($info) && (int) $info[0] > 0 && (int) $info[1] > 0 ? [(int) $info[0], (int) $info[1]] : null;
        };
        $this->settingsReader = $deps['settings'] ?? static function (): array {
            try {
                return \CMS\Services\MediaService::getInstance()->getSettings();
            } catch (\Throwable) {
                return [];
            }
        };
        $this->optionReader = $deps['optionGet'] ?? static function (): ?string {
            try {
                return \CMS\Services\OptionStore::getInstance()->get(self::OPTION_CAPABILITIES);
            } catch (\Throwable) {
                return null;
            }
        };
        $this->optionWriter = $deps['optionSet'] ?? static function (string $json): void {
            if (function_exists('update_option')) {
                \update_option(self::OPTION_CAPABILITIES, $json);
            }
        };
        $this->uploadPath = rtrim((string) ($deps['uploadPath'] ?? (defined('UPLOAD_PATH') ? UPLOAD_PATH : ABSPATH . 'uploads/')), '/\\') . '/';
        $this->uploadUrl = rtrim((string) ($deps['uploadUrl'] ?? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads')), '/');
    }

    // ── Erkennung und Einstellungen ─────────────────────────────────────────

    /**
     * Unterstützung neu prüfen (Probe-Kodierung) und in `media_capabilities` speichern.
     *
     * @return array{avif:bool, checked_at:int}
     */
    public function refreshCapabilities(): array
    {
        try {
            $avif = ($this->probe)();
        } catch (\Throwable) {
            $avif = false;
        }
        $capabilities = ['avif' => $avif, 'checked_at' => time()];
        ($this->optionWriter)((string) json_encode($capabilities));

        return $capabilities;
    }

    /** Gespeichertes Ergebnis der Probe (ohne Probe: nicht unterstützt). */
    public function isSupported(): bool
    {
        $raw = ($this->optionReader)();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) && ($decoded['avif'] ?? false) === true;
    }

    /**
     * Wirksame AVIF-Einstellungen aus den Medien-Einstellungen.
     *
     * @return array{enabled:bool, quality:int, speed:int}
     */
    public function settings(): array
    {
        $settings = ($this->settingsReader)();

        return self::normalizeSettings($settings);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{enabled:bool, quality:int, speed:int}
     */
    public static function normalizeSettings(array $settings): array
    {
        $enabled = $settings['auto_avif'] ?? false;

        return [
            'enabled' => $enabled === true || $enabled === 1 || $enabled === '1',
            'quality' => max(1, min(100, (int) ($settings['avif_quality'] ?? self::DEFAULT_QUALITY))),
            'speed' => max(0, min(10, (int) ($settings['avif_speed'] ?? self::DEFAULT_SPEED))),
        ];
    }

    /** Erzeugung beim Upload aktiv? (Schalter an und Probe erfolgreich) */
    public function isEnabled(): bool
    {
        return $this->settings()['enabled'] && $this->isSupported();
    }

    // ── Erzeugung ───────────────────────────────────────────────────────────

    /**
     * AVIF für ein Original und seine erzeugten Größen.
     *
     * @return array<string, string> absoluter Pfad => Status
     */
    public function generateForImage(string $absolutePath): array
    {
        $settings = $this->settings();
        $results = [];
        foreach ($this->sizePaths($absolutePath, true) as $path) {
            $results[$path] = $this->generateOne($path, $settings['quality'], $settings['speed']);
        }

        return $results;
    }

    /**
     * AVIF für genau eine Datei. Idempotent: eine aktuelle AVIF-Datei (nicht älter als die Quelle) bleibt stehen.
     */
    public function generateOne(string $sourcePath, int $quality, int $speed): string
    {
        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SOURCE_EXTENSIONS, true) || !is_file($sourcePath) || !is_readable($sourcePath)) {
            return self::STATUS_SKIPPED;
        }
        $target = self::derivativePath($sourcePath, 'avif');
        if ($target === '') {
            return self::STATUS_SKIPPED;
        }

        clearstatcache(true, $sourcePath);
        if (is_file($target)) {
            clearstatcache(true, $target);
            if ((int) filemtime($target) >= (int) filemtime($sourcePath)) {
                return self::STATUS_EXISTS;
            }
        }

        $dimensions = ($this->dimensions)($sourcePath);
        if ($dimensions === null || !self::withinPixelBudget($dimensions[0], $dimensions[1])) {
            return self::STATUS_SKIPPED;
        }

        $reference = self::derivativePath($sourcePath, 'webp');
        $referencePath = $extension !== 'webp' && $reference !== '' && is_file($reference) ? $reference : $sourcePath;
        $referenceBytes = (int) (filesize($referencePath) ?: 0);

        $temporary = $target . '.tmp-' . bin2hex(random_bytes(4));
        try {
            $encoded = ($this->encoder)($sourcePath, $temporary, $quality, $speed);
        } catch (\Throwable) {
            $encoded = false;
        }
        clearstatcache(true, $temporary);
        $bytes = $encoded && is_file($temporary) ? (int) (filesize($temporary) ?: 0) : 0;
        if ($bytes <= 0) {
            @unlink($temporary);

            return self::STATUS_FAILED;
        }

        // Nur behalten, wenn kleiner als WebP derselben Größe (sonst als das Ausgangsbild).
        if (!self::isWorthKeeping($bytes, $referenceBytes)) {
            @unlink($temporary);
            if (is_file($target)) {
                @unlink($target);
            }

            return self::STATUS_LARGER;
        }

        if (!@rename($temporary, $target)) {
            @unlink($temporary);

            return self::STATUS_FAILED;
        }

        Hooks::doAction('cms_media_derivative_created', $target, 'avif', $bytes);

        return self::STATUS_CREATED;
    }

    public static function isWorthKeeping(int $avifBytes, int $referenceBytes): bool
    {
        return $avifBytes > 0 && $referenceBytes > 0 && $avifBytes < $referenceBytes;
    }

    public static function withinPixelBudget(int $width, int $height): bool
    {
        return $width > 0 && $height > 0 && $width * $height <= self::MAX_PIXELS;
    }

    /**
     * Original und vorhandene erzeugte Größen (`name-small.jpg`, …) eines Bildes.
     *
     * @return list<string>
     */
    public function sizePaths(string $absolutePath, bool $existingOnly): array
    {
        $info = pathinfo($absolutePath);
        $extension = (string) ($info['extension'] ?? '');
        if ($extension === '') {
            return [];
        }
        $paths = [$absolutePath];
        foreach (self::SIZE_SUFFIXES as $suffix) {
            $paths[] = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '-' . $suffix . '.' . $extension;
        }

        return array_values(array_filter($paths, static fn (string $path): bool => !$existingOnly || is_file($path)));
    }

    public static function derivativePath(string $path, string $format): string
    {
        $target = preg_replace('/\.[A-Za-z0-9]+$/', '.' . $format, $path);

        return is_string($target) && $target !== $path ? $target : '';
    }

    /** Ist die Datei (dem Namen nach) eine erzeugte Größe `-small`, `-medium`, …? */
    public static function isSizeVariant(string $path): bool
    {
        $name = (string) pathinfo($path, PATHINFO_FILENAME);
        foreach (self::SIZE_SUFFIXES as $suffix) {
            if (str_ends_with($name, '-' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    // ── Aufräumen ───────────────────────────────────────────────────────────

    /**
     * Erzeugte Dateien eines Bildes, die beim Löschen mit entfernt werden: WebP/AVIF des Originals, die erzeugten
     * Größen und deren AVIF/WebP. Eine Begleitdatei zählt nur, wenn sie nicht selbst hochgeladen wurde
     * (`$isUploadedOriginal`) und nicht älter als die Quelle ist.
     *
     * @param \Closure(string): bool $isUploadedOriginal absoluter Pfad → eigenständig hochgeladene Datei?
     * @return list<string>
     */
    public function derivativesToDelete(string $absolutePath, \Closure $isUploadedOriginal): array
    {
        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
        if (!in_array($extension, array_merge(self::SOURCE_EXTENSIONS, ['bmp']), true) || self::isSizeVariant($absolutePath)) {
            return [];
        }
        clearstatcache(true, $absolutePath);
        $sourceTime = is_file($absolutePath) ? (int) filemtime($absolutePath) : 0;

        $candidates = [];
        foreach ($this->sizePaths($absolutePath, false) as $index => $sizePath) {
            if ($index > 0) {
                $candidates[] = $sizePath;
            }
            foreach (['avif', 'webp'] as $format) {
                $derivative = self::derivativePath($sizePath, $format);
                if ($derivative !== '' && $derivative !== $absolutePath) {
                    $candidates[] = $derivative;
                }
            }
        }

        $delete = [];
        foreach (array_unique($candidates) as $candidate) {
            if (!is_file($candidate) || $isUploadedOriginal($candidate)) {
                continue;
            }
            clearstatcache(true, $candidate);
            if ($sourceTime > 0 && (int) filemtime($candidate) < $sourceTime) {
                continue;
            }
            $delete[] = $candidate;
        }

        return $delete;
    }

    // ── Auslieferung ────────────────────────────────────────────────────────

    /**
     * `<source>`-Angaben für eine Bild-URL: AVIF vor WebP, nur für vorhandene Dateien unter `UPLOAD_URL`.
     *
     * @return list<array{type:string, srcset:string}>
     */
    public function pictureSources(string $url): array
    {
        $local = $this->urlToLocalPath($url);
        if ($local === null) {
            return [];
        }
        [$path, $publicUrl] = $local;
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SOURCE_EXTENSIONS, true) || !is_file($path)) {
            return [];
        }

        $sources = [];
        foreach (['avif' => 'image/avif', 'webp' => 'image/webp'] as $format => $type) {
            if ($format === $extension) {
                continue;
            }
            $derivative = self::derivativePath($path, $format);
            if ($derivative !== '' && is_file($derivative)) {
                $sources[] = ['type' => $type, 'srcset' => (string) self::derivativePath($publicUrl, $format)];
            }
        }

        return $sources;
    }

    /**
     * Umschließt ein `<img>` mit `<picture>` und den vorhandenen Quellen; ohne Quellen bleibt das `<img>` unverändert.
     */
    public function pictureHtml(string $url, string $imgHtml): string
    {
        $sources = $this->pictureSources($url);
        if ($sources === []) {
            return $imgHtml;
        }
        $html = '<picture>';
        foreach ($sources as $source) {
            $html .= '<source type="' . htmlspecialchars($source['type'], ENT_QUOTES, 'UTF-8') . '" srcset="'
                . htmlspecialchars($source['srcset'], ENT_QUOTES, 'UTF-8') . '">';
        }

        return $html . $imgHtml . '</picture>';
    }

    /**
     * Öffentliche Upload-URL (absolut, gleicher Host, oder relativ) → [lokaler Pfad, URL ohne Query].
     *
     * @return array{0:string, 1:string}|null
     */
    public function urlToLocalPath(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F"<>\\\\]/', $url) === 1) {
            return null;
        }
        $clean = (string) preg_replace('/[?#].*$/', '', $url);
        $base = $this->uploadUrl;
        $basePath = (string) (parse_url($base, PHP_URL_PATH) ?? '');
        $basePath = '/' . trim($basePath, '/');

        if (str_starts_with($clean, $base . '/')) {
            $relative = substr($clean, strlen($base) + 1);
        } elseif (str_starts_with($clean, $basePath . '/')) {
            $relative = substr($clean, strlen($basePath) + 1);
        } else {
            return null;
        }

        $relative = rawurldecode($relative);
        if ($relative === '' || str_contains($relative, '..') || preg_match('#(^|/)\.#', $relative) === 1
            || preg_match('#^[A-Za-z0-9._\-/ ]+$#', $relative) !== 1) {
            return null;
        }

        return [$this->uploadPath . $relative, $clean];
    }

    // ── GD ──────────────────────────────────────────────────────────────────

    private static function gdEncode(string $source, string $target, int $quality, int $speed): bool
    {
        if (!function_exists('imageavif')) {
            return false;
        }
        $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        $loader = match ($extension) {
            'jpg', 'jpeg' => 'imagecreatefromjpeg',
            'png' => 'imagecreatefrompng',
            'gif' => 'imagecreatefromgif',
            'webp' => 'imagecreatefromwebp',
            default => '',
        };
        if ($loader === '' || !function_exists($loader)) {
            return false;
        }
        $image = @$loader($source);
        if (!$image instanceof \GdImage) {
            return false;
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        $ok = @imageavif($image, $target, $quality, $speed);
        unset($image);

        return $ok === true;
    }

    /** Kodiert ein 8×8-Bild in den Speicher und prüft die AVIF-Signatur (`ftyp` + `avif`). */
    private static function gdProbe(): bool
    {
        if (!function_exists('imageavif') || !function_exists('imagecreatetruecolor')) {
            return false;
        }
        $image = @imagecreatetruecolor(8, 8);
        if (!$image instanceof \GdImage) {
            return false;
        }
        ob_start();
        try {
            $ok = @imageavif($image, null, self::DEFAULT_QUALITY, 10);
        } catch (\Throwable) {
            $ok = false;
        }
        $data = (string) ob_get_clean();
        unset($image);

        return $ok === true && strlen($data) > 12 && substr($data, 4, 4) === 'ftyp' && str_contains(substr($data, 8, 24), 'avif');
    }
}
