<?php
/**
 * Normalisiert Werte aus den Tabellen des früheren Plugins cms-feed (3.x) für die Core-Tabellen.
 *
 * Reine Funktionen ohne Datenbankzugriff – genutzt vom FeedPluginMigrator und von den Tests.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

if (!defined('ABSPATH')) {
    exit;
}

final class LegacyNormalizer
{
    /**
     * Abrufintervall in Minuten (5–1440). Werte über 1440, die durch 60 teilbar sind, stammen aus
     * Installationen, die Sekunden gespeichert haben (z. B. 3600 → 60 Minuten).
     */
    public static function fetchInterval(mixed $value): int
    {
        $minutes = is_numeric($value) ? (int) $value : 60;
        if ($minutes > 1440 && $minutes % 60 === 0) {
            $minutes = intdiv($minutes, 60);
        }
        if ($minutes <= 0) {
            $minutes = 60;
        }

        return max(5, min(1440, $minutes));
    }

    public static function maxItems(mixed $value): int
    {
        return max(10, min(500, is_numeric($value) ? (int) $value : 50));
    }

    public static function itemsPerPage(mixed $value, int $fallback = 20): int
    {
        $value = is_numeric($value) ? (int) $value : 0;

        return $value > 0 ? max(4, min(100, $value)) : max(4, min(100, $fallback));
    }

    public static function layout(mixed $value): string
    {
        $value = strtolower(trim(is_scalar($value) ? (string) $value : ''));

        return in_array($value, AggregatorSettings::LAYOUTS, true) ? $value : 'grid';
    }

    /**
     * Versandzeitpunkt „täglich“: `09`, `15` oder `09_15`. Akzeptiert auch Klartext (`morning`, `afternoon`,
     * `both`) und Uhrzeiten (`08:00` → vormittags = `09`, ab 12 Uhr `15`).
     */
    public static function dailyMode(mixed $value): string
    {
        $value = strtolower(trim(is_scalar($value) ? (string) $value : ''));
        if (in_array($value, ['09', '15', '09_15'], true)) {
            return $value;
        }
        if (in_array($value, ['both', 'twice', 'morning_afternoon', '9_15', '09,15', '9,15', 'all'], true)) {
            return '09_15';
        }
        if (in_array($value, ['afternoon', 'evening', 'pm', 'nachmittags', 'abends'], true)) {
            return '15';
        }
        if (in_array($value, ['morning', 'am', 'vormittags', 'morgens'], true)) {
            return '09';
        }

        $hour = self::hour($value);

        return $hour !== null && $hour >= 12 ? '15' : '09';
    }

    /**
     * Versandzeit „wöchentlich“: `09` oder `15`.
     */
    public static function weeklyTime(mixed $value): string
    {
        $value = strtolower(trim(is_scalar($value) ? (string) $value : ''));
        if (in_array($value, ['09', '15'], true)) {
            return $value;
        }
        if (in_array($value, ['afternoon', 'evening', 'pm'], true)) {
            return '15';
        }
        $hour = self::hour($value);

        return $hour !== null && $hour >= 12 ? '15' : '09';
    }

    /** Wochentag 1 (Montag) bis 7 (Sonntag); 0 = Sonntag (PHP `w`) wird zu 7. */
    public static function weeklyDay(mixed $value): int
    {
        $day = is_numeric($value) ? (int) $value : 1;
        if ($day === 0) {
            return 7;
        }

        return max(1, min(7, $day));
    }

    public static function frequency(mixed $value): string
    {
        return strtolower(trim(is_scalar($value) ? (string) $value : '')) === 'weekly' ? 'weekly' : 'daily';
    }

    /**
     * Digest-Frequenz: 1–4 Mal täglich oder 7 = wöchentlich.
     */
    public static function digestFrequency(mixed $value): int
    {
        $value = is_numeric($value) ? (int) $value : 1;
        if ($value >= 7) {
            return 7;
        }

        return max(1, min(4, $value));
    }

    /**
     * ID-Liste aus JSON (`[1,2]`, `["1","2"]`), Komma-Liste oder Array; nur positive, eindeutige Ganzzahlen.
     *
     * @return list<int>
     */
    public static function idList(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            $decoded = $trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{') ? json_decode($trimmed, true) : null;
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $trimmed);
        } elseif (is_int($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach (is_array($value) ? $value : [] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Ordnet IDs über eine Zuordnung (alte ID → neue ID) zu; unbekannte IDs entfallen.
     *
     * @param list<int> $ids
     * @param array<int, int> $map
     * @return list<int>
     */
    public static function mapIds(array $ids, array $map): array
    {
        $result = [];
        foreach ($ids as $id) {
            if (isset($map[$id])) {
                $result[$map[$id]] = $map[$id];
            }
        }

        return array_values($result);
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Datum als `Y-m-d H:i:s` oder null; ungültige/0000-Werte werden null.
     */
    public static function dateTime(mixed $value): ?string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    /**
     * Kategorie-Slug: Kleinbuchstaben, Ziffern, Bindestriche; `feed` ist für den RSS-Ausgang reserviert.
     */
    public static function categorySlug(mixed $value, string $fallbackName = ''): string
    {
        $slug = strtolower(trim(is_scalar($value) ? (string) $value : ''));
        if ($slug === '') {
            $slug = self::slugify($fallbackName);
        }
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim(preg_replace('/-{2,}/', '-', $slug) ?? '', '-');
        $slug = trim(substr($slug, 0, 120), '-');
        if ($slug === '' || $slug === 'feed') {
            $slug = $slug === 'feed' ? 'feed-bereich' : 'bereich';
        }

        return $slug;
    }

    public static function slugify(string $text): string
    {
        $text = strtr(mb_strtolower(trim($text), 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($converted)) {
                $text = strtolower($converted);
            }
        }
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

        return trim($text, '-');
    }

    public static function icon(mixed $value): string
    {
        $value = trim(strip_tags(is_scalar($value) ? (string) $value : ''));

        return $value !== '' ? mb_substr($value, 0, 10) : '📰';
    }

    private static function hour(string $value): ?int
    {
        if (preg_match('/^(\d{1,2})(?::\d{2})?(?::\d{2})?$/', $value, $match) !== 1) {
            return null;
        }
        $hour = (int) $match[1];

        return $hour >= 0 && $hour <= 23 ? $hour : null;
    }
}
