<?php
/**
 * Einstellungen des Feed-Aggregators (Gruppe `feeds_aggregator`, ehemals Tabelle `feed_settings` des Plugins cms-feed).
 *
 * Getrennt von der Gruppe `feeds` des ausgehenden RSS-Feeds (`/feed`). Alle Werte werden beim Lesen
 * normalisiert, damit Templates, Cron und Mails nie ungeprüfte Werte erhalten.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class AggregatorSettings
{
    public const GROUP = 'feeds_aggregator';

    /** Slugs, die das Archiv nicht belegen darf (Core-Routen und `/feed` für den RSS-Ausgang). */
    public const RESERVED_SLUGS = [
        'feed', 'admin', 'member', 'api', 'login', 'logout', 'register', 'search', 'suche', 'sitemap',
        'assets', 'uploads', 'cms-login', 'cms-register', 'cookie-einstellungen', 'en', 'de', 'blog', 'tag', 'category',
        'kategorie', 'author', 'autor', 'page', 'seite', 'cron', 'install', 'core', 'includes', 'themes', 'plugins',
    ];

    public const LAYOUTS = ['grid', 'list', 'magazine'];
    public const GRID_COLUMNS = ['auto', '2', '3', '4'];

    /** @var array<string, bool|int|string> */
    public const DEFAULTS = [
        'aggregator_enabled' => false,
        'require_consent' => true,
        'archive_title' => 'Feed-Übersicht',
        'archive_title_en' => 'Feed overview',
        'archive_description' => 'Aktuelle Nachrichten und Beiträge aus verschiedenen Quellen',
        'archive_description_en' => 'Latest news and posts from different sources',
        'archive_slug' => 'feeds',
        'per_page' => 20,
        'excerpt_length' => 160,
        'show_source' => true,
        'show_date' => true,
        'show_image' => true,
        'show_excerpt' => true,
        'open_in_new_tab' => true,
        'noise_exclude_keywords' => '',
        'noise_exclude_authors' => '',
        'noise_exclude_domains' => '',
        'color_primary' => '#0891b2',
        'color_accent' => '#e0f2fe',
        'color_hdr_from' => '#0c4a6e',
        'color_hdr_to' => '#0891b2',
        'color_hdr_title' => '#ffffff',
        'color_card_bg' => '#ffffff',
        'color_card_border' => '#e2e8f0',
        'border_radius' => 10,
        'grid_columns' => 'auto',
        'digest_from_name' => '365 CMS Feed Digest',
        'digest_from_email' => '',
        'digest_subject' => 'Dein Feed-Digest – {date}',
        'digest_subject_en' => 'Your feed digest – {date}',
        'digest_max_items' => 20,
        'item_retention_days' => 7,
        'log_retention_days' => 7,
        'cron_batch_size' => 5,
    ];

    /** Felder je Formular der Admin-Unteransichten. */
    public const SECTIONS = [
        'general' => [
            'aggregator_enabled', 'require_consent', 'archive_title', 'archive_title_en', 'archive_description',
            'archive_description_en', 'archive_slug', 'per_page', 'excerpt_length', 'show_source', 'show_date',
            'show_image', 'show_excerpt', 'open_in_new_tab', 'noise_exclude_keywords', 'noise_exclude_authors',
            'noise_exclude_domains', 'item_retention_days', 'log_retention_days', 'cron_batch_size',
        ],
        'design' => [
            'color_primary', 'color_accent', 'color_hdr_from', 'color_hdr_to', 'color_hdr_title', 'color_card_bg',
            'color_card_border', 'border_radius', 'grid_columns',
        ],
        'digest' => ['digest_from_name', 'digest_from_email', 'digest_subject', 'digest_subject_en', 'digest_max_items'],
    ];

    /** @var array<string, bool|int|string>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, bool|int|string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $stored = SettingsService::getInstance()->getGroup(self::GROUP);
        } catch (\Throwable) {
            $stored = [];
        }

        return self::$cache = self::normalize($stored);
    }

    public static function get(string $key): bool|int|string
    {
        return self::all()[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    public static function isEnabled(): bool
    {
        return (bool) self::get('aggregator_enabled');
    }

    /**
     * Speichert die übergebenen Werte (werden normalisiert; unbekannte Schlüssel werden verworfen).
     *
     * @param array<string, mixed> $values
     */
    public static function save(array $values): bool
    {
        $values = array_intersect_key(self::normalize($values + self::all()), $values, self::DEFAULTS);
        $ok = SettingsService::getInstance()->setMany(self::GROUP, $values);
        self::reset();

        return $ok;
    }

    /**
     * Normalisiert rohe Werte (Formular, gespeicherte Optionen, Plugin-Tabelle) auf Typ und Wertebereich.
     *
     * @param array<string, mixed> $input
     * @return array<string, bool|int|string>
     */
    public static function normalize(array $input): array
    {
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $raw = array_key_exists($key, $input) ? $input[$key] : $default;
            $values[$key] = match (true) {
                is_bool($default) => self::toBool($raw),
                str_starts_with($key, 'color_') => self::color($raw, (string) $default),
                is_int($default) => self::intInRange($key, $raw, $default),
                default => self::text($key, $raw, (string) $default),
            };
        }

        return $values;
    }

    /**
     * Bereinigter Archiv-Slug: nur `[a-z0-9-]`, leer/reserviert → `feeds`.
     */
    public static function sanitizeSlug(mixed $value): string
    {
        $slug = strtolower(trim(is_scalar($value) ? (string) $value : ''));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim(preg_replace('/-{2,}/', '-', $slug) ?? '', '-');
        $slug = trim(substr($slug, 0, 60), '-');

        return ($slug === '' || in_array($slug, self::RESERVED_SLUGS, true)) ? 'feeds' : $slug;
    }

    public static function archiveSlug(): string
    {
        return self::sanitizeSlug(self::get('archive_slug'));
    }

    /**
     * Lokalisierter Text: für `en` zuerst `<key>_en`, sonst deutscher Wert.
     */
    public static function localized(string $key, string $lang): string
    {
        $value = (string) self::get($key);
        if ($lang === 'en' && array_key_exists($key . '_en', self::DEFAULTS)) {
            $english = trim((string) self::get($key . '_en'));
            if ($english !== '') {
                return $english;
            }
        }

        return $value;
    }

    /**
     * CSS-Variablen der öffentlichen Feed-Seiten (`:root{--fd-…}`), Farben streng geprüft.
     *
     * @param array<string, mixed>|null $settings
     */
    public static function tokenCss(?array $settings = null): string
    {
        $s = self::normalize($settings ?? self::all());
        $columns = (string) $s['grid_columns'];
        $tokens = [
            '--fd-primary' => (string) $s['color_primary'],
            '--fd-accent' => (string) $s['color_accent'],
            '--fd-hdr-from' => (string) $s['color_hdr_from'],
            '--fd-hdr-to' => (string) $s['color_hdr_to'],
            '--fd-hdr-title' => (string) $s['color_hdr_title'],
            '--fd-card-bg' => (string) $s['color_card_bg'],
            '--fd-card-border' => (string) $s['color_card_border'],
            '--fd-radius' => (int) $s['border_radius'] . 'px',
            '--fd-grid-columns' => $columns === 'auto'
                ? 'repeat(auto-fill, minmax(300px, 1fr))'
                : 'repeat(' . (int) $columns . ', minmax(0, 1fr))',
        ];

        $css = ':root{';
        foreach ($tokens as $name => $value) {
            $css .= $name . ':' . $value . ';';
        }

        return $css . '}';
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function color(mixed $value, string $default): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? strtolower($value) : $default;
    }

    private static function intInRange(string $key, mixed $value, int $default): int
    {
        [$min, $max] = match ($key) {
            'per_page' => [4, 100],
            'excerpt_length' => [50, 500],
            'border_radius' => [0, 24],
            'digest_max_items' => [5, 100],
            'item_retention_days' => [0, 365],
            'log_retention_days' => [1, 365],
            'cron_batch_size' => [1, 25],
            default => [0, PHP_INT_MAX],
        };
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private static function text(string $key, mixed $value, string $default): string
    {
        $value = is_scalar($value) ? (string) $value : $default;

        return match ($key) {
            'archive_slug' => self::sanitizeSlug($value),
            'grid_columns' => in_array($value, self::GRID_COLUMNS, true) ? $value : 'auto',
            'digest_from_email' => filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false ? trim($value) : '',
            'noise_exclude_keywords', 'noise_exclude_authors' => self::listText($value, 4000),
            'noise_exclude_domains' => self::listText($value, 2000),
            'digest_from_name' => self::line(str_replace(['"', '<', '>'], '', $value), 120),
            'archive_description', 'archive_description_en' => self::line($value, 500),
            default => self::line($value, 190),
        };
    }

    private static function line(string $value, int $max): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        return mb_substr(trim(preg_replace('/\s{2,}/u', ' ', $value) ?? ''), 0, $max);
    }

    private static function listText(string $value, int $max): string
    {
        $lines = preg_split('/\R/u', strip_tags($value)) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $line) ?? '');
            if ($line !== '') {
                $clean[] = $line;
            }
        }

        return mb_substr(implode("\n", array_values(array_unique($clean))), 0, $max);
    }
}
