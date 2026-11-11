<?php
/**
 * Übersetzungen des Feed-Aggregators in einer frei wählbaren Sprache (de/en).
 *
 * `__()` übersetzt immer in die Systemsprache. Öffentliche Feed-Seiten folgen dagegen der Sprache des
 * Requests (`/en/feeds`), Digest-Mails der Sprache des Empfängers. Dafür werden die `feeds.*`-Schlüssel
 * bei Bedarf direkt aus `lang/<locale>.yaml` gelesen (gleiches Dateiformat wie der TranslationService).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

use CMS\Services\TranslationService;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedI18n
{
    public const LOCALES = ['de', 'en'];

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    public static function normalizeLocale(?string $locale): string
    {
        return str_starts_with(strtolower(trim((string) $locale)), 'en') ? 'en' : 'de';
    }

    /**
     * Übersetzt `$key` in `$locale`; `%s`/`%d`-Platzhalter werden mit `$args` ersetzt.
     */
    public static function text(string $key, string $locale, string|int|float ...$args): string
    {
        $locale = self::normalizeLocale($locale);
        $message = null;

        if (self::systemLocale() === $locale && function_exists('__')) {
            $translated = (string) __($key);
            if ($translated !== $key) {
                $message = $translated;
            }
        }

        $message ??= self::catalog($locale)[$key] ?? ($locale !== 'de' ? (self::catalog('de')[$key] ?? null) : null) ?? $key;
        if ($args === []) {
            return $message;
        }

        try {
            return vsprintf($message, $args);
        } catch (\ValueError) {
            return $message;
        }
    }

    /** Lokalisierter Pfad: `de` → `/pfad`, `en` → `/en/pfad`. */
    public static function path(string $path, string $locale): string
    {
        $path = '/' . ltrim($path, '/');

        return self::normalizeLocale($locale) === 'en' ? '/en' . ($path === '/' ? '' : $path) : $path;
    }

    /** Sprache des aktuellen Requests (`/en/…` → `en`), außerhalb des Routings `de`. */
    public static function requestLocale(): string
    {
        try {
            return self::normalizeLocale(\CMS\Router::instance()->getRequestLocale());
        } catch (\Throwable) {
            return 'de';
        }
    }

    public static function formatDate(?string $value, string $locale, bool $withTime = false): string
    {
        $timestamp = $value !== null && $value !== '' ? strtotime($value) : false;
        if ($timestamp === false) {
            return '';
        }

        $format = self::normalizeLocale($locale) === 'en'
            ? ($withTime ? 'M j, Y g:i A' : 'M j, Y')
            : ($withTime ? 'd.m.Y H:i' : 'd.m.Y');

        return date($format, $timestamp);
    }

    /** Übersetzung in der Systemsprache (Admin-Protokolle, Cron). */
    public static function admin(string $key, string|int|float ...$args): string
    {
        return self::text($key, self::systemLocale(), ...$args);
    }

    public static function systemLocale(): string
    {
        try {
            return self::normalizeLocale(TranslationService::getInstance()->getLocale());
        } catch (\Throwable) {
            return 'de';
        }
    }

    /**
     * @return array<string, string>
     */
    private static function catalog(string $locale): array
    {
        if (isset(self::$catalogs[$locale])) {
            return self::$catalogs[$locale];
        }

        $catalog = [];
        $file = ABSPATH . 'lang/' . $locale . '.yaml';
        $lines = is_file($file) ? (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*"(feeds\.[^"]+)"\s*:\s*"(.*)"\s*$/', $line, $match) === 1) {
                $catalog[$match[1]] = stripcslashes($match[2]);
            }
        }

        return self::$catalogs[$locale] = $catalog;
    }
}
