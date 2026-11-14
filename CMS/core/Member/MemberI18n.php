<?php
/**
 * Sprachhilfen für den Mitgliederbereich.
 *
 * `__()` übersetzt in die Website-Sprache. Der Mitgliederbereich wird auch unter `/en/member/…` ausgeliefert;
 * `t()` liefert `member.*`-Schlüssel deshalb in der Sprache der Anfrage: für die Website-Sprache über `__()`,
 * sonst direkt aus `lang/<locale>.yaml` (Muster wie `Forms\FormI18n`). Fehlt ein Schlüssel, wird der
 * deutsche Katalog und zuletzt der Schlüssel selbst verwendet.
 *
 * @package CMS\Member
 */

declare(strict_types=1);

namespace CMS\Member;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberI18n
{
    public const LOCALES = ['de', 'en'];

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    private static ?string $siteLocale = null;

    private static ?string $requestLocale = null;

    public static function normalizeLocale(mixed $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        return str_starts_with($locale, 'en') ? 'en' : 'de';
    }

    public static function siteLocale(): string
    {
        if (self::$siteLocale !== null) {
            return self::$siteLocale;
        }

        $locale = 'de';
        try {
            if (class_exists(\CMS\Services\TranslationService::class) && defined('DB_NAME')) {
                $locale = \CMS\Services\TranslationService::getInstance()->getLocale();
            }
        } catch (\Throwable) {
            $locale = 'de';
        }

        return self::$siteLocale = self::normalizeLocale($locale);
    }

    /** Sprache der aktuellen Anfrage (`/en/member/…` → `en`), sonst Website-Sprache. */
    public static function locale(): string
    {
        if (self::$requestLocale !== null) {
            return self::$requestLocale;
        }

        try {
            if (class_exists(\CMS\Router::class) && defined('DB_NAME')) {
                return self::$requestLocale = self::normalizeLocale(\CMS\Router::instance()->getRequestLocale());
            }
        } catch (\Throwable) {
        }

        return self::$requestLocale = self::siteLocale();
    }

    /**
     * Oberflächentext; `{name}`-Platzhalter werden aus `$params` ersetzt (Werte werden nicht escaped).
     *
     * @param array<string, string|int|float> $params
     */
    public static function t(string $key, array $params = [], ?string $locale = null): string
    {
        $locale = $locale === null ? self::locale() : self::normalizeLocale($locale);
        $text = null;

        if ($locale === self::siteLocale() && function_exists('__')) {
            $translated = __($key);
            if ($translated !== $key) {
                $text = $translated;
            }
        }

        $text ??= self::catalog($locale)[$key] ?? self::catalog('de')[$key] ?? $key;

        if ($params !== []) {
            $replace = [];
            foreach ($params as $name => $value) {
                $replace['{' . $name . '}'] = (string) $value;
            }
            $text = strtr($text, $replace);
        }

        return $text;
    }

    /**
     * Datum/Uhrzeit für die Anzeige. Nutzt `date_format`/`time_format` der Website, wenn gesetzt.
     */
    public static function formatDate(mixed $value, bool $withTime = true): string
    {
        $raw = trim((string) $value);
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return '–';
        }

        $timestamp = ctype_digit($raw) ? (int) $raw : strtotime($raw);
        if ($timestamp === false || $timestamp <= 0) {
            return '–';
        }

        [$dateFormat, $timeFormat] = self::formats();

        return date($withTime ? $dateFormat . ' ' . $timeFormat : $dateFormat, $timestamp);
    }

    /** Relative Zeitangabe („vor 5 Min.“ / „5 min ago“), ab 30 Tagen das Datum. */
    public static function relative(mixed $value, ?int $now = null): string
    {
        $raw = trim((string) $value);
        $timestamp = $raw === '' ? false : (ctype_digit($raw) ? (int) $raw : strtotime($raw));
        if ($timestamp === false || $timestamp <= 0) {
            return '–';
        }

        $diff = max(0, ($now ?? time()) - $timestamp);
        if ($diff < 60) {
            return self::t('member.time.just_now');
        }
        if ($diff < 3600) {
            return self::t('member.time.minutes_ago', ['count' => intdiv($diff, 60)]);
        }
        if ($diff < 86400) {
            return self::t('member.time.hours_ago', ['count' => intdiv($diff, 3600)]);
        }
        if ($diff < 30 * 86400) {
            return self::t('member.time.days_ago', ['count' => intdiv($diff, 86400)]);
        }

        return self::formatDate((string) $timestamp, false);
    }

    /** Nur für Tests: zwischengespeicherte Kataloge und Sprachen zurücksetzen. */
    public static function reset(?string $siteLocale = null, ?string $requestLocale = null): void
    {
        self::$catalogs = [];
        self::$siteLocale = $siteLocale !== null ? self::normalizeLocale($siteLocale) : null;
        self::$requestLocale = $requestLocale !== null ? self::normalizeLocale($requestLocale) : null;
    }

    /** @return array{0: string, 1: string} */
    private static function formats(): array
    {
        $locale = self::locale();
        $dateFormat = $locale === 'en' ? 'Y-m-d' : 'd.m.Y';
        $timeFormat = 'H:i';

        try {
            if (class_exists(\CMS\Services\OptionStore::class) && defined('DB_NAME')) {
                $options = \CMS\Services\OptionStore::getInstance();
                $storedDate = (string) ($options->get('date_format') ?? '');
                $storedTime = (string) ($options->get('time_format') ?? '');
                if (in_array($storedDate, ['d.m.Y', 'Y-m-d', 'm/d/Y', 'd/m/Y'], true)) {
                    $dateFormat = $storedDate;
                }
                if (preg_match('/^[HhGgisaA:. ]{1,12}$/', $storedTime) === 1) {
                    $timeFormat = $storedTime;
                }
            }
        } catch (\Throwable) {
        }

        return [$dateFormat, $timeFormat];
    }

    /**
     * Liest nur die `member.*`-Schlüssel einer Sprachdatei (Zeilen `  "schluessel": "Wert"`).
     *
     * @return array<string, string>
     */
    private static function catalog(string $locale): array
    {
        if (isset(self::$catalogs[$locale])) {
            return self::$catalogs[$locale];
        }

        $catalog = [];
        $file = rtrim((string) ABSPATH, '/\\') . '/lang/' . $locale . '.yaml';
        $lines = is_readable($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
        foreach ($lines as $line) {
            if (!str_contains($line, '"member.')) {
                continue;
            }
            if (preg_match('/^\s+"(member\.[A-Za-z0-9_.-]+)":\s*"((?:[^"\\\\]|\\\\.)*)"\s*$/', $line, $match) === 1) {
                $catalog[$match[1]] = strtr($match[2], ['\\"' => '"', '\\\\' => '\\', '\\n' => "\n", '\\t' => "\t"]);
            }
        }

        return self::$catalogs[$locale] = $catalog;
    }
}
