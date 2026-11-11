<?php
/**
 * Einstellungen des Funktionsbereichs „Downloads“ (Gruppe `downloads` im SettingsService).
 *
 * Liefert normalisierte Werte mit Standardwerten und Grenzen. Die Normalisierung ist rein
 * (ohne Datenbank) und wird auch beim Speichern im Admin und in der Plugin-Übernahme genutzt.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Downloads;

use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class DownloadSettings
{
    public const GROUP = 'downloads';

    public const TEMPLATES = ['compact', 'card', 'featured'];
    public const ACCESS_LEVELS = ['public', 'logged_in', 'roles', 'member'];
    public const STATUSES = ['draft', 'published', 'archived', 'trash'];
    public const UPLOAD_STATUSES = ['published', 'draft'];
    public const PROPERTY_TYPES = ['text', 'textarea', 'number', 'select', 'url', 'boolean', 'date'];

    /** Dateitypen, die für Downloads wählbar sind (Teilmenge der MediaService-Typkarte). */
    public const FILE_TYPE_GROUPS = [
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'csv'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'audio' => ['mp3', 'wav', 'aac', 'flac', 'm4a'],
        'video' => ['mp4', 'webm', 'mov', 'mkv'],
    ];

    public const DEFAULT_ALLOWED_TYPES = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'csv',
        'zip', 'rar', '7z', 'tar', 'gz',
    ];

    /** Standardwerte; Schlüssel der Plugin-Version (cms-downloads 3.x) sind gleich benannt (Ausnahme: per_page). */
    public const DEFAULTS = [
        'archive_title' => 'Downloads',
        'archive_title_en' => 'Downloads',
        'archive_description' => '',
        'archive_description_en' => '',
        'per_page' => 24,
        'show_search' => true,
        'show_category_overview' => true,
        'show_external_notice' => true,
        'external_allowed_domains' => '',
        'show_nav_link' => false,
        'nav_label' => 'Downloads',
        'nav_label_en' => 'Downloads',
        'rate_limit_enabled' => true,
        'rate_limit_max' => 30,
        'rate_limit_window' => 60,
        'default_template' => 'card',
        'default_access' => 'public',
        'storage_area' => 'downloads',
        'allowed_types' => self::DEFAULT_ALLOWED_TYPES,
        'max_size_mb' => 256,
        'upload_status' => 'published',
        'dedupe_window_minutes' => 30,
        'count_staff' => false,
        'bot_filter' => true,
        'stats_retention_days' => 90,
        'property_fields' => [],
    ];

    private const INT_LIMITS = [
        'per_page' => [6, 120],
        'rate_limit_max' => [5, 500],
        'rate_limit_window' => [30, 3600],
        'max_size_mb' => [1, 4096],
        'dedupe_window_minutes' => [0, 1440],
        'stats_retention_days' => [7, 3650],
    ];

    private const BOOL_KEYS = [
        'show_search', 'show_category_overview', 'show_external_notice', 'show_nav_link',
        'rate_limit_enabled', 'count_staff', 'bot_filter',
    ];

    private const TEXT_LIMITS = [
        'archive_title' => 190,
        'archive_title_en' => 190,
        'archive_description' => 2000,
        'archive_description_en' => 2000,
        'nav_label' => 60,
        'nav_label_en' => 60,
    ];

    private static ?self $instance = null;

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            try {
                $stored = SettingsService::getInstance()->getGroup(self::GROUP);
            } catch (\Throwable) {
                $stored = [];
            }
            $this->cache = self::normalize($stored);
        }

        return $this->cache;
    }

    public function get(string $key): mixed
    {
        $all = $this->all();

        return $all[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * Speichert die übergebenen Werte (werden normalisiert). Nicht übergebene Schlüssel bleiben erhalten.
     *
     * @param array<string,mixed> $values
     */
    public function save(array $values): bool
    {
        $merged = self::normalize(array_merge($this->all(), $values));
        $stored = [];
        foreach (array_keys($values) as $key) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $value = $merged[$key];
            $stored[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }
        if ($stored === []) {
            return true;
        }

        $ok = SettingsService::getInstance()->setMany(self::GROUP, $stored);
        $this->cache = null;

        return $ok;
    }

    public function invalidate(): void
    {
        $this->cache = null;
    }

    /**
     * @return list<array{key:string,label:string,type:string,options:list<string>}>
     */
    public function propertyFields(): array
    {
        $fields = $this->get('property_fields');

        return is_array($fields) ? array_values($fields) : [];
    }

    /**
     * @return array<string,array{key:string,label:string,type:string,options:list<string>}>
     */
    public function propertyFieldMap(): array
    {
        $map = [];
        foreach ($this->propertyFields() as $field) {
            $map[$field['key']] = $field;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $types = $this->get('allowed_types');

        return is_array($types) && $types !== [] ? array_values($types) : self::DEFAULT_ALLOWED_TYPES;
    }

    /**
     * Wirksame Maximalgröße in Bytes: Einstellung, begrenzt durch die Server-Grenzen (upload_max_filesize/post_max_size).
     */
    public function maxSizeBytes(): int
    {
        $configured = $this->int('max_size_mb') * 1024 * 1024;
        $server = self::serverUploadLimit();

        return $server > 0 ? min($configured, $server) : $configured;
    }

    public static function serverUploadLimit(): int
    {
        $limits = [];
        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = self::iniBytes((string) ini_get($directive));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return $limits === [] ? 0 : min($limits);
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]?)b?$/i', $value, $m) !== 1) {
            return 0;
        }
        $factor = match (strtolower($m[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            't' => 1024 ** 4,
            default => 1,
        };

        return (int) round((float) $m[1] * $factor);
    }

    /**
     * @return list<string>
     */
    public static function allFileTypes(): array
    {
        $all = [];
        foreach (self::FILE_TYPE_GROUPS as $extensions) {
            foreach ($extensions as $extension) {
                $all[$extension] = true;
            }
        }

        return array_keys($all);
    }

    public static function fileTypeGroup(string $extension): string
    {
        $extension = strtolower($extension);
        foreach (self::FILE_TYPE_GROUPS as $group => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $group;
            }
        }

        return $extension === 'link' ? 'link' : 'file';
    }

    /**
     * Normalisiert gespeicherte oder übermittelte Werte (rein, ohne Datenbank).
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalize(array $raw): array
    {
        $result = self::DEFAULTS;

        foreach (self::TEXT_LIMITS as $key => $max) {
            if (array_key_exists($key, $raw) && is_scalar($raw[$key])) {
                $result[$key] = self::cut(trim(str_replace("\0", '', (string) $raw[$key])), $max);
            }
        }
        foreach (['archive_title', 'nav_label'] as $key) {
            if ($result[$key] === '') {
                $result[$key] = self::DEFAULTS[$key];
            }
        }

        foreach (self::INT_LIMITS as $key => [$min, $max]) {
            if (array_key_exists($key, $raw) && is_numeric($raw[$key])) {
                $result[$key] = max($min, min($max, (int) $raw[$key]));
            }
        }

        foreach (self::BOOL_KEYS as $key) {
            if (array_key_exists($key, $raw)) {
                $result[$key] = self::toBool($raw[$key]);
            }
        }

        if (array_key_exists('external_allowed_domains', $raw) && is_scalar($raw['external_allowed_domains'])) {
            $result['external_allowed_domains'] = implode("\n", self::parseDomainList((string) $raw['external_allowed_domains']));
        }

        $template = (string) ($raw['default_template'] ?? '');
        $result['default_template'] = in_array($template, self::TEMPLATES, true) ? $template : self::DEFAULTS['default_template'];

        $access = (string) ($raw['default_access'] ?? '');
        $result['default_access'] = in_array($access, self::ACCESS_LEVELS, true) ? $access : self::DEFAULTS['default_access'];

        $uploadStatus = (string) ($raw['upload_status'] ?? '');
        $result['upload_status'] = in_array($uploadStatus, self::UPLOAD_STATUSES, true) ? $uploadStatus : self::DEFAULTS['upload_status'];

        $area = strtolower(trim((string) (is_scalar($raw['storage_area'] ?? null) ? $raw['storage_area'] : '')));
        $result['storage_area'] = preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $area) === 1 ? $area : self::DEFAULTS['storage_area'];

        if (array_key_exists('allowed_types', $raw)) {
            $known = self::allFileTypes();
            $types = array_values(array_unique(array_filter(
                array_map(static fn (string $type): string => strtolower(trim($type, " .\t")), self::toList($raw['allowed_types'])),
                static fn (string $type): bool => in_array($type, $known, true)
            )));
            $result['allowed_types'] = $types !== [] ? $types : self::DEFAULT_ALLOWED_TYPES;
        }

        if (array_key_exists('property_fields', $raw)) {
            $result['property_fields'] = self::normalizePropertyFields($raw['property_fields']);
        }

        return $result;
    }

    /**
     * @return list<array{key:string,label:string,type:string,options:list<string>}>
     */
    public static function normalizePropertyFields(mixed $fields): array
    {
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            $fields = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($fields)) {
            return [];
        }

        $result = [];
        $seen = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($field['key'] ?? '')))), '_');
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1 || isset($seen[$key])) {
                continue;
            }
            $label = self::cut(trim((string) ($field['label'] ?? '')), 120);
            $type = (string) ($field['type'] ?? 'text');
            $type = in_array($type, self::PROPERTY_TYPES, true) ? $type : 'text';
            $options = [];
            if ($type === 'select') {
                foreach (self::toList($field['options'] ?? []) as $option) {
                    $option = self::cut(trim($option), 120);
                    if ($option !== '' && !in_array($option, $options, true)) {
                        $options[] = $option;
                    }
                }
                $options = array_slice($options, 0, 100);
            }
            $seen[$key] = true;
            $result[] = ['key' => $key, 'label' => $label !== '' ? $label : $key, 'type' => $type, 'options' => $options];
            if (count($result) >= 50) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public static function parseDomainList(string $value): array
    {
        $domains = [];
        foreach (preg_split('/[\s,;]+/', strtolower($value)) ?: [] as $domain) {
            $domain = trim($domain, " .\t\r\n");
            if ($domain === '' || preg_match('/^[a-z0-9.-]+$/', $domain) !== 1 || !str_contains($domain, '.')) {
                continue;
            }
            $domains[$domain] = true;
        }

        return array_slice(array_keys($domains), 0, 200);
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private static function cut(string $text, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    /**
     * @return list<string>
     */
    private static function toList(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && $trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }
        }
        if (is_string($value)) {
            $value = preg_split('/[\r\n,;]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($item): string => is_scalar($item) ? (string) $item : '', $value));
    }
}
