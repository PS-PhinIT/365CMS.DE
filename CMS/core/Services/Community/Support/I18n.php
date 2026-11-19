<?php
/**
 * Sprachhilfen des Community-Moduls: Texte (`community.<bereich>.<name>`, immer mindestens drei Segmente) und Zeitangaben.
 *
 * `t()` liefert den Text in der Sprache der Anfrage (`/en/forum/…` → `en`): für die Websitesprache über `__()`, sonst
 * direkt aus `lang/<locale>.yaml` (Muster wie `Member\MemberI18n`). Fehlt ein Schlüssel, gilt der deutsche Katalog und
 * zuletzt der Schlüssel selbst. `{name}`-Platzhalter werden aus `$vars` ersetzt.
 *
 * Die Datenbank speichert Zeiten in UTC; die Methode `I18n::date` rechnet für die Anzeige in die Zeitzone der Website um.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class I18n
{
    public const LOCALES = ['de', 'en'];

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    private static ?string $siteLocale = null;

    private static ?string $requestLocale = null;

    private static ?string $timezone = null;

    public static function normalizeLocale(mixed $locale): string
    {
        return str_starts_with(strtolower(trim((string) $locale)), 'en') ? 'en' : 'de';
    }

    /**
     * Oberflächentext; Werte in `$vars` werden nicht escaped.
     *
     * @param array<string, string|int|float> $vars
     */
    public static function t(string $key, array $vars = [], ?string $locale = null): string
    {
        $locale = $locale === null ? self::requestLocale() : self::normalizeLocale($locale);
        $text = null;

        if ($locale === self::siteLocale() && function_exists('__')) {
            $translated = __($key);
            if ($translated !== $key) {
                $text = $translated;
            }
        }

        $text ??= self::catalog($locale)[$key] ?? self::catalog('de')[$key] ?? $key;

        if ($vars !== []) {
            $replace = [];
            foreach ($vars as $name => $value) {
                $replace['{' . $name . '}'] = (string) $value;
            }
            $text = strtr($text, $replace);
        }

        return $text;
    }

    /**
     * Zeitangabe aus der Datenbank (`Y-m-d H:i:s`, UTC) im PHP-Format `$format`, in der Zeitzone der Website.
     * Ungültige oder leere Zeiten ergeben einen leeren Text.
     */
    public static function date(string $utc, string $format): string
    {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($utc), new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return '';
        }

        return $time->setTimezone(self::siteTimezone())->format($format);
    }

    /**
     * Nur für Tests: zwischengespeicherte Kataloge, Sprachen und Zeitzone setzen bzw. zurücksetzen. `$catalogs` (Sprache →
     * Schlüssel → Text) ersetzt die Sprachdateien, etwa um den Rückfall auf Deutsch mit einem Schlüssel zu prüfen, den nur der
     * deutsche Katalog kennt (die echten Dateien sind zeilenparallel).
     *
     * @param array<string, array<string, string>>|null $catalogs
     */
    public static function reset(?string $siteLocale = null, ?string $requestLocale = null, ?string $timezone = null, ?array $catalogs = null): void
    {
        self::$catalogs = $catalogs ?? [];
        self::$siteLocale = $siteLocale !== null ? self::normalizeLocale($siteLocale) : null;
        self::$requestLocale = $requestLocale !== null ? self::normalizeLocale($requestLocale) : null;
        self::$timezone = $timezone;
    }

    private static function siteLocale(): string
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
        }

        return self::$siteLocale = self::normalizeLocale($locale);
    }

    /** Sprache der aktuellen Anfrage, sonst Websitesprache. */
    private static function requestLocale(): string
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

    private static function siteTimezone(): \DateTimeZone
    {
        $name = self::$timezone;
        if ($name === null) {
            $name = '';
            try {
                $name = function_exists('get_option') ? (string) get_option('timezone', '') : '';
            } catch (\Throwable) {
            }
        }

        try {
            return new \DateTimeZone($name !== '' ? $name : date_default_timezone_get());
        } catch (\Throwable) {
            return new \DateTimeZone(date_default_timezone_get());
        }
    }

    /**
     * Liest nur die `community.*`-Schlüssel einer Sprachdatei (Zeilen `  "schluessel": "Wert"`).
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
            if (!str_contains($line, '"community.')) {
                continue;
            }
            if (preg_match('/^\s+"(community\.[A-Za-z0-9_.-]+)":\s*"((?:[^"\\\\]|\\\\.)*)"\s*$/', $line, $match) === 1) {
                $catalog[$match[1]] = strtr($match[2], ['\\"' => '"', '\\\\' => '\\', '\\n' => "\n", '\\t' => "\t"]);
            }
        }

        return self::$catalogs[$locale] = $catalog;
    }
}
