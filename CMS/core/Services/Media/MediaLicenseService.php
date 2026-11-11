<?php
/**
 * Urheber- und Lizenzangaben für Medien (ROADMAP #11).
 *
 * Die Angaben liegen im Feld `license` der Medien-Metadaten (config/media-meta.json). Geschrieben
 * wird ausschließlich über MediaRepository::mergeFileMeta()/updateMeta() unter Dateisperre; das
 * Frontend liest die Datei ohne Sperre (sie wird atomar per Umbenennen ersetzt).
 *
 * Felder: creator, license (siehe LICENSES), license_custom, license_url, source_url, credit,
 * copyright, ai_generated, ai_tool. Leere Felder werden nicht gespeichert.
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

use CMS\Json;
use CMS\Logger;
use CMS\Services\SettingsService;
use CMS\WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaLicenseService
{
    public const META_KEY = 'license';
    public const SETTINGS_GROUP = 'media_license';

    public const LICENSES = [
        'all_rights_reserved',
        'cc_by',
        'cc_by_sa',
        'cc_by_nd',
        'cc_by_nc',
        'cc_by_nc_sa',
        'cc_by_nc_nd',
        'cc0',
        'public_domain',
        'custom',
    ];

    public const FIELD_LIMITS = [
        'creator' => 200,
        'license_custom' => 120,
        'license_url' => 500,
        'source_url' => 500,
        'credit' => 300,
        'copyright' => 300,
        'ai_tool' => 120,
    ];

    public const MAX_BULK_PATHS = 500;

    private const CC_SLUGS = [
        'cc_by' => 'by',
        'cc_by_sa' => 'by-sa',
        'cc_by_nd' => 'by-nd',
        'cc_by_nc' => 'by-nc',
        'cc_by_nc_sa' => 'by-nc-sa',
        'cc_by_nc_nd' => 'by-nc-nd',
    ];

    /** Namenssuffixe der von ImageProcessor erzeugten Größen (`<name>-<größe>.<ext>`). */
    private const DERIVATIVE_SUFFIXES = ['small', 'medium', 'large', 'banner'];
    private const WEBP_SOURCE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];
    /** Entspricht den Systemordnern von MediaService (für MediaRepository::ensureSystemCategories). */
    private const SYSTEM_FOLDERS = ['themes', 'plugins', 'assets', 'fonts', 'dl-manager', 'form-uploads', 'member'];

    private static ?self $instance = null;

    private ?MediaRepository $repository = null;

    /** @var array<string, array<string, mixed>>|null Pfad => normalisierte Lizenz (nur Einträge mit Angaben) */
    private ?array $index = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Normalisierung und Darstellung (statisch, ohne Datenbank) ─────────────────────────────────

    /**
     * Bereinigt Eingaben (Formular, Metadatei, IPTC/EXIF-Vorbelegung). Liefert `[]`, wenn keine
     * Angabe übrig bleibt.
     *
     * @return array<string, mixed>
     */
    public static function normalize(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $result = [];

        $creator = self::cleanText($input['creator'] ?? '', self::FIELD_LIMITS['creator']);
        if ($creator !== '') {
            $result['creator'] = $creator;
        }

        $license = strtolower(trim((string) (is_scalar($input['license'] ?? null) ? $input['license'] : '')));
        if (in_array($license, self::LICENSES, true)) {
            $result['license'] = $license;
        }

        if ($license === 'custom') {
            $custom = self::cleanText($input['license_custom'] ?? '', self::FIELD_LIMITS['license_custom']);
            if ($custom !== '') {
                $result['license_custom'] = $custom;
            }
        }

        $licenseUrl = self::cleanUrl($input['license_url'] ?? '', self::FIELD_LIMITS['license_url']);
        if ($licenseUrl !== '') {
            $result['license_url'] = $licenseUrl;
        }

        $sourceUrl = self::cleanUrl($input['source_url'] ?? '', self::FIELD_LIMITS['source_url']);
        if ($sourceUrl !== '') {
            $result['source_url'] = $sourceUrl;
        }

        $credit = self::cleanText($input['credit'] ?? '', self::FIELD_LIMITS['credit']);
        if ($credit !== '') {
            $result['credit'] = $credit;
        }

        $copyright = self::cleanText($input['copyright'] ?? '', self::FIELD_LIMITS['copyright']);
        if ($copyright !== '') {
            $result['copyright'] = $copyright;
        }

        if (self::toBool($input['ai_generated'] ?? false)) {
            $result['ai_generated'] = true;
            $tool = self::cleanText($input['ai_tool'] ?? '', self::FIELD_LIMITS['ai_tool']);
            if ($tool !== '') {
                $result['ai_tool'] = $tool;
            }
        }

        return $result;
    }

    /**
     * @return array<string, string> Lizenzschlüssel => Bezeichnung (für Auswahllisten)
     */
    public static function licenseOptions(): array
    {
        $options = [];
        foreach (self::LICENSES as $key) {
            $options[$key] = self::licenseLabel(['license' => $key]);
        }

        return $options;
    }

    /** Lesbare Lizenzbezeichnung, z. B. „CC BY-SA 4.0“; leer, wenn keine Lizenz gewählt ist. */
    public static function licenseLabel(array $license): string
    {
        $key = (string) ($license['license'] ?? '');
        $url = (string) ($license['license_url'] ?? '');

        if (isset(self::CC_SLUGS[$key])) {
            return 'CC ' . strtoupper(self::CC_SLUGS[$key]) . ' ' . self::ccVersion($url);
        }

        return match ($key) {
            'all_rights_reserved' => self::t('media_license.license.all_rights_reserved', 'Alle Rechte vorbehalten'),
            'cc0' => 'CC0 1.0',
            'public_domain' => self::t('media_license.license.public_domain', 'Gemeinfrei (Public Domain)'),
            'custom' => (string) ($license['license_custom'] ?? '') !== ''
                ? (string) $license['license_custom']
                : self::t('media_license.license.custom', 'Eigene Lizenz'),
            default => '',
        };
    }

    /** Lizenz-URL: eigene Angabe oder die kanonische Creative-Commons-Adresse. */
    public static function licenseUrl(array $license): string
    {
        $url = (string) ($license['license_url'] ?? '');
        if ($url !== '') {
            return $url;
        }

        $key = (string) ($license['license'] ?? '');
        if (isset(self::CC_SLUGS[$key])) {
            return 'https://creativecommons.org/licenses/' . self::CC_SLUGS[$key] . '/4.0/';
        }

        return match ($key) {
            'cc0' => 'https://creativecommons.org/publicdomain/zero/1.0/',
            'public_domain' => 'https://creativecommons.org/publicdomain/mark/1.0/',
            default => '',
        };
    }

    /** Lizenzschlüssel zu einer Creative-Commons-URL (für die Vorbelegung), sonst leer. */
    public static function licenseKeyFromUrl(string $url): string
    {
        if (preg_match('#creativecommons\.org/licenses/(by(?:-nc)?(?:-sa|-nd)?)/#i', $url, $match) === 1) {
            $key = array_search(strtolower($match[1]), self::CC_SLUGS, true);

            return is_string($key) ? $key : '';
        }
        if (preg_match('#creativecommons\.org/publicdomain/zero/#i', $url) === 1) {
            return 'cc0';
        }
        if (preg_match('#creativecommons\.org/publicdomain/mark/#i', $url) === 1) {
            return 'public_domain';
        }

        return '';
    }

    /** Lizenzschlüssel aus Freitext wie „CC BY-SA 4.0“ oder „Alle Rechte vorbehalten“, sonst leer. */
    public static function licenseKeyFromText(string $text): string
    {
        if (preg_match('/\bCC[\s-]?0\b/i', $text) === 1) {
            return 'cc0';
        }
        if (preg_match('/\bCC[\s-]+(BY(?:[\s-]+NC)?(?:[\s-]+(?:SA|ND))?)\b/i', $text, $match) === 1) {
            $slug = strtolower((string) preg_replace('/[\s-]+/', '-', $match[1]));
            $key = array_search($slug, self::CC_SLUGS, true);

            return is_string($key) ? $key : '';
        }
        if (preg_match('/public\s+domain|gemeinfrei/i', $text) === 1) {
            return 'public_domain';
        }
        if (preg_match('/all\s+rights\s+reserved|alle\s+rechte\s+vorbehalten/i', $text) === 1) {
            return 'all_rights_reserved';
        }

        return '';
    }

    /** Kurzfassung für die Spalte der Medienliste, z. B. „Max Muster · CC BY 4.0 · KI“. */
    public static function summary(array $license): string
    {
        $parts = [];
        if ((string) ($license['creator'] ?? '') !== '') {
            $parts[] = (string) $license['creator'];
        }
        $label = self::licenseLabel($license);
        if ($label !== '') {
            $parts[] = $label;
        }
        if (!empty($license['ai_generated'])) {
            $parts[] = self::t('media_license.ai_short', 'KI');
        }
        if ($parts === [] && (string) ($license['credit'] ?? '') !== '') {
            $parts[] = (string) $license['credit'];
        }

        return implode(' · ', $parts);
    }

    /**
     * Credit-Zeile als Klartext (auch Schema.org `creditText`): eigener Credit-Text hat Vorrang,
     * sonst „© Urheber“ bzw. „Bild: Urheber, Lizenz“; KI-Hinweis wird angehängt.
     */
    public static function creditText(array $license): string
    {
        $text = (string) ($license['credit'] ?? '');
        if ($text === '') {
            $identity = static fn(string $value): string => $value;
            $text = self::composeCredit($license, $identity, $identity, $identity);
        }

        $aiNote = self::aiNote($license);
        if ($aiNote !== '') {
            $text = $text !== '' ? $text . ' · ' . $aiNote : $aiNote;
        }

        return $text;
    }

    /**
     * Credit-Zeile als HTML (`<small class="cms-media-credit">`), leer ohne Angaben. Urheber wird
     * mit der Quell-URL verlinkt, die Lizenz mit ihrer URL (`rel="license"`).
     */
    public static function creditHtml(array $license): string
    {
        $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $credit = (string) ($license['credit'] ?? '');
        $sourceUrl = (string) ($license['source_url'] ?? '');

        if ($credit !== '') {
            $inner = $sourceUrl !== ''
                ? '<a href="' . $esc($sourceUrl) . '" rel="noopener noreferrer nofollow" target="_blank">' . $esc($credit) . '</a>'
                : $esc($credit);
        } else {
            $licenseUrl = self::licenseUrl($license);
            $inner = self::composeCredit(
                $license,
                static fn(string $creator): string => $sourceUrl !== ''
                    ? '<a href="' . $esc($sourceUrl) . '" rel="noopener noreferrer nofollow" target="_blank">' . $esc($creator) . '</a>'
                    : $esc($creator),
                static fn(string $label): string => $licenseUrl !== ''
                    ? '<a href="' . $esc($licenseUrl) . '" rel="license noopener noreferrer" target="_blank">' . $esc($label) . '</a>'
                    : $esc($label),
                $esc
            );
        }

        $aiNote = self::aiNote($license);
        if ($aiNote !== '') {
            $aiHtml = '<span class="cms-media-credit__ai">' . $esc($aiNote) . '</span>';
            $inner = $inner !== '' ? $inner . ' · ' . $aiHtml : $aiHtml;
        }

        return $inner !== '' ? '<small class="cms-media-credit">' . $inner . '</small>' : '';
    }

    /**
     * Schema.org-ImageObject (creator, license, copyrightNotice, creditText); leer ohne Angaben.
     *
     * @return array<string, mixed>
     */
    public static function schemaImageObject(array $license, string $contentUrl): array
    {
        $creator = (string) ($license['creator'] ?? '');
        $key = (string) ($license['license'] ?? '');
        $licenseUrl = self::licenseUrl($license);
        $creditText = self::creditText($license);
        $copyright = (string) ($license['copyright'] ?? '');
        if ($copyright === '' && $creator !== '' && !in_array($key, ['cc0', 'public_domain'], true)) {
            $copyright = '© ' . $creator;
        }

        if ($creator === '' && $licenseUrl === '' && $copyright === '' && $creditText === '') {
            return [];
        }

        $object = ['@type' => 'ImageObject', 'contentUrl' => $contentUrl];
        if ($creator !== '') {
            $object['creator'] = ['@type' => 'Person', 'name' => $creator];
        }
        if ($licenseUrl !== '') {
            $object['license'] = $licenseUrl;
        }
        if ($copyright !== '') {
            $object['copyrightNotice'] = $copyright;
        }
        if ($creditText !== '') {
            $object['creditText'] = $creditText;
        }

        return $object;
    }

    /**
     * Relativer Upload-Pfad zu einer Bild-URL aus Inhalten: `UPLOAD_URL/…`, `/uploads/…` und
     * `/media-file?path=…`. Leer, wenn die URL nicht in die Mediathek zeigt.
     */
    public static function relativePathFromUrl(string $url, string $uploadUrl, string $siteUrl): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, 'data:')) {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== '') {
            $allowedHosts = array_filter([
                strtolower((string) parse_url($siteUrl, PHP_URL_HOST)),
                strtolower((string) parse_url($uploadUrl, PHP_URL_HOST)),
            ]);
            if (!in_array($host, $allowedHosts, true)) {
                return '';
            }
        }

        $path = (string) ($parts['path'] ?? '');
        $sitePath = rtrim((string) parse_url($siteUrl, PHP_URL_PATH), '/');

        if ($path === $sitePath . '/media-file') {
            parse_str((string) ($parts['query'] ?? ''), $query);

            return self::cleanRelativePath((string) ($query['path'] ?? ''));
        }

        $uploadPath = rtrim((string) (parse_url($uploadUrl, PHP_URL_PATH) ?? ''), '/');
        if ($uploadPath === '' || !str_starts_with($path, $uploadPath . '/')) {
            return '';
        }

        $relative = substr($path, strlen($uploadPath) + 1);

        return self::cleanRelativePath(implode('/', array_map('rawurldecode', explode('/', $relative))));
    }

    /**
     * Pfadkandidaten für eine Bilddatei: die Datei selbst, das Original einer erzeugten Größe
     * (`bild-medium.jpg` → `bild.jpg`) und bei WebP-Derivaten das gleichnamige JPG/PNG/GIF.
     *
     * @return list<string>
     */
    public static function candidatePaths(string $relativePath): array
    {
        if ($relativePath === '') {
            return [];
        }

        $candidates = [$relativePath];
        $dir = str_contains($relativePath, '/') ? substr($relativePath, 0, (int) strrpos($relativePath, '/') + 1) : '';
        $file = basename($relativePath);
        $dot = strrpos($file, '.');
        $name = $dot === false ? $file : substr($file, 0, $dot);
        $ext = $dot === false ? '' : strtolower(substr($file, $dot + 1));

        $names = [$name];
        foreach (self::DERIVATIVE_SUFFIXES as $suffix) {
            if (str_ends_with($name, '-' . $suffix) && strlen($name) > strlen($suffix) + 1) {
                $names[] = substr($name, 0, -strlen($suffix) - 1);
                break;
            }
        }

        $extensions = [$ext];
        if ($ext === 'webp') {
            array_push($extensions, ...self::WEBP_SOURCE_EXTENSIONS);
        }

        foreach ($names as $candidateName) {
            foreach ($extensions as $candidateExt) {
                $candidates[] = $dir . $candidateName . ($candidateExt !== '' ? '.' . $candidateExt : '');
            }
        }

        return array_values(array_unique($candidates));
    }

    // ── Lesen und Schreiben ──────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> normalisierte Angaben einer Datei, leer ohne Angaben */
    public function get(string $relativePath): array
    {
        return self::normalize($this->repository()->getFileMeta($relativePath)[self::META_KEY] ?? []);
    }

    /**
     * Speichert die Angaben einer Datei; leere Angaben entfernen das Feld.
     *
     * @return array<string, mixed>|WP_Error gespeicherter Stand
     */
    public function save(string $relativePath, array $input): array|WP_Error
    {
        $path = $this->validatePath($relativePath);
        if ($path instanceof WP_Error) {
            return $path;
        }

        $license = self::normalize($input);
        if (!$this->repository()->mergeFileMeta($path, [self::META_KEY => $license !== [] ? $license : null])) {
            return new WP_Error('license_save_failed', self::t('media_license.error.save_failed', 'Die Lizenzangaben konnten nicht gespeichert werden.'));
        }
        $this->index = null;

        return $license;
    }

    /**
     * Setzt Angaben für mehrere Dateien in einem gesperrten Schreibvorgang.
     * Modus `merge`: nur ausgefüllte Felder werden übernommen; `replace`: ersetzt alle Angaben.
     *
     * @param list<string> $paths
     * @return int|WP_Error Anzahl geänderter Dateien
     */
    public function bulkSave(array $paths, array $input, string $mode = 'merge'): int|WP_Error
    {
        if (count($paths) > self::MAX_BULK_PATHS) {
            return new WP_Error('license_too_many', self::t('media_license.error.too_many', 'Zu viele Dateien in einem Schritt.'));
        }
        $validPaths = array_fill_keys($this->existingFilePaths($paths), true);
        if ($validPaths === []) {
            return new WP_Error('license_no_files', self::t('media_license.error.no_files', 'Keine gültigen Dateien ausgewählt.'));
        }

        $replace = $mode === 'replace';
        $patch = self::normalize($input);
        if (!$replace && $patch === []) {
            return new WP_Error('license_empty_patch', self::t('media_license.error.empty_patch', 'Bitte mindestens ein Feld ausfüllen.'));
        }

        $changed = 0;
        $result = $this->repository()->updateMeta(static function (array $meta) use ($validPaths, $patch, $replace, &$changed): ?array {
            foreach (array_keys($validPaths) as $path) {
                $entry = is_array($meta['files'][$path] ?? null) ? $meta['files'][$path] : [];
                $current = self::normalize($entry[self::META_KEY] ?? []);
                if (!$replace && isset($patch['license']) && !isset($patch['license_url']) && ($current['license'] ?? '') !== $patch['license']) {
                    // Eine abweichende Lizenz-URL gehört zur bisherigen Lizenz und würde sonst falsch verlinken.
                    unset($current['license_url']);
                }
                $next = $replace ? $patch : self::normalize(array_merge($current, $patch));
                if ($next === $current) {
                    continue;
                }
                if ($next === []) {
                    unset($entry[self::META_KEY]);
                } else {
                    $entry[self::META_KEY] = $next;
                }
                $meta['files'][$path] = $entry;
                $changed++;
            }

            return $changed > 0 ? $meta : null;
        });

        if ($result !== true) {
            return $result instanceof WP_Error
                ? $result
                : new WP_Error('license_save_failed', self::t('media_license.error.save_failed', 'Die Lizenzangaben konnten nicht gespeichert werden.'));
        }
        $this->index = null;

        return $changed;
    }

    /**
     * Bereinigte, existierende Dateipfade (ohne Ordner, ohne Duplikate) in Eingabereihenfolge.
     *
     * @param array<int, mixed> $paths
     * @return list<string>
     */
    public function existingFilePaths(array $paths): array
    {
        $result = [];
        foreach ($paths as $path) {
            $valid = is_string($path) ? $this->validatePath($path) : null;
            if (is_string($valid) && !in_array($valid, $result, true)) {
                $result[] = $valid;
            }
        }

        return $result;
    }

    /**
     * Angaben zu einer Bild-URL aus Inhalten (inkl. erzeugter Größen und WebP-Derivate).
     *
     * @return array{path: string, license: array<string, mixed>}|null
     */
    public function findByUrl(string $url): ?array
    {
        $relative = self::relativePathFromUrl($url, (string) UPLOAD_URL, (string) SITE_URL);
        if ($relative === '') {
            return null;
        }

        $index = $this->loadIndex();
        if ($index === []) {
            return null;
        }

        foreach (self::candidatePaths($relative) as $candidate) {
            if (isset($index[$candidate])) {
                return ['path' => $candidate, 'license' => $index[$candidate]];
            }
        }

        return null;
    }

    public function isCreditLineEnabled(): bool
    {
        return $this->setting('credit_line', true);
    }

    public function isSchemaEnabled(): bool
    {
        return $this->setting('schema', true);
    }

    public function isPrefillEnabled(): bool
    {
        return $this->setting('prefill', true);
    }

    /** @return array{credit_line: bool, schema: bool, prefill: bool} */
    public function getSettings(): array
    {
        return [
            'credit_line' => $this->isCreditLineEnabled(),
            'schema' => $this->isSchemaEnabled(),
            'prefill' => $this->isPrefillEnabled(),
        ];
    }

    /** Speichert die Ausgabe-Schalter; autoload, weil das Frontend sie bei jedem Bild liest. */
    public function saveSettings(array $input): bool
    {
        return SettingsService::getInstance()->setMany(self::SETTINGS_GROUP, [
            'credit_line' => self::toBool($input['credit_line'] ?? false) ? '1' : '0',
            'schema' => self::toBool($input['schema'] ?? false) ? '1' : '0',
            'prefill' => self::toBool($input['prefill'] ?? false) ? '1' : '0',
        ], [], 1);
    }

    // ── intern ────────────────────────────────────────────────────────────────────────────────────

    private function repository(): MediaRepository
    {
        return $this->repository ??= new MediaRepository(
            rtrim((string) UPLOAD_PATH, '/\\'),
            rtrim((string) UPLOAD_URL, '/\\'),
            self::metaFile(),
            self::SYSTEM_FOLDERS
        );
    }

    private static function metaFile(): string
    {
        return dirname(__DIR__, 3) . '/config/media-meta.json';
    }

    private function validatePath(string $relativePath): string|WP_Error
    {
        $path = self::cleanRelativePath($relativePath);
        $repository = $this->repository();
        if ($path === '' || !$repository->pathExists($path) || $repository->isDirectory($path)) {
            return new WP_Error('license_invalid_path', self::t('media_license.error.invalid_path', 'Die Datei wurde nicht gefunden.'));
        }

        return $path;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];
        $file = self::metaFile();
        if (!is_file($file) || !is_readable($file)) {
            return $this->index;
        }

        $content = @file_get_contents($file);
        if (!is_string($content) || !str_contains($content, '"' . self::META_KEY . '"')) {
            return $this->index;
        }

        $data = Json::decodeArray($content, []);
        foreach ((array) ($data['files'] ?? []) as $path => $entry) {
            if (!is_array($entry) || !isset($entry[self::META_KEY])) {
                continue;
            }
            $license = self::normalize($entry[self::META_KEY]);
            if ($license !== []) {
                $this->index[(string) $path] = $license;
            }
        }

        return $this->index;
    }

    private function setting(string $key, bool $default): bool
    {
        try {
            return SettingsService::getInstance()->getBool(self::SETTINGS_GROUP, $key, $default);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('media.license')->warning('Einstellung konnte nicht gelesen werden.', [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * @param callable(string): string $creatorFormatter
     * @param callable(string): string $licenseFormatter
     * @param callable(string): string $textFormatter für feste Textteile und den Copyright-Vermerk
     */
    private static function composeCredit(array $license, callable $creatorFormatter, callable $licenseFormatter, callable $textFormatter): string
    {
        $creator = (string) ($license['creator'] ?? '');
        $copyright = (string) ($license['copyright'] ?? '');
        $key = (string) ($license['license'] ?? '');

        if ($key === 'all_rights_reserved' || ($key === '' && $creator !== '' && $copyright === '')) {
            return $creator !== '' ? $textFormatter('© ') . $creatorFormatter($creator) : $textFormatter($copyright);
        }

        if ($key === '') {
            return $textFormatter($copyright);
        }

        $prefix = $textFormatter(self::t('media_license.credit_prefix', 'Bild') . ': ');
        $label = $licenseFormatter(self::licenseLabel($license));

        return $creator !== ''
            ? $prefix . $creatorFormatter($creator) . $textFormatter(', ') . $label
            : $prefix . $label;
    }

    private static function aiNote(array $license): string
    {
        if (empty($license['ai_generated'])) {
            return '';
        }

        $note = self::t('media_license.ai_generated', 'KI-generiert');
        $tool = (string) ($license['ai_tool'] ?? '');

        return $tool !== '' ? $note . ' (' . $tool . ')' : $note;
    }

    private static function ccVersion(string $url): string
    {
        if (preg_match('#creativecommons\.org/licenses/[a-z-]+/(\d\.\d)#i', $url, $match) === 1) {
            return $match[1];
        }

        return '4.0';
    }

    private static function cleanText(mixed $value, int $limit): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $text = strip_tags((string) $value);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || preg_match('//u', $text) !== 1) {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
    }

    private static function cleanUrl(mixed $value, int $limit): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $url = trim((string) $value);
        if ($url === '' || strlen($url) > $limit || preg_match('/[\s\x00-\x1F\x7F<>"]/', $url) === 1) {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || (string) parse_url($url, PHP_URL_HOST) === '') {
            return '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
    }

    private static function cleanRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $path = (string) preg_replace('#/+#', '/', $path);
        if ($path === '' || str_contains($path, '..') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }

        return $path;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) (is_scalar($value) ? $value : ''))), ['1', 'true', 'on', 'yes'], true);
    }

    private static function t(string $key, string $fallback): string
    {
        if (!function_exists('__')) {
            return $fallback;
        }

        $translated = (string) __($key);

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }
}
