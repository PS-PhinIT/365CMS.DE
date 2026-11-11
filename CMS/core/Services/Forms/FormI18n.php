<?php
/**
 * Sprachhilfen für Formulare (Oberflächentexte und zweisprachige Inhaltstexte).
 *
 * `__()` übersetzt nur in die Sprache der Website. Formulare werden aber auch unter `/en/…` ausgeliefert und
 * Bestätigungsmails gehen in der Sprache des Absenders hinaus. `t()` liefert deshalb einen `forms.*`-Schlüssel in
 * einer beliebigen Sprache: für die Website-Sprache über `__()`, sonst direkt aus `lang/<locale>.yaml`.
 * Inhaltstexte (Labels, Hilfetexte, Erfolgsmeldung …) liegen als `['de' => …, 'en' => …]` in der Definition.
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

if (!defined('ABSPATH')) {
    exit;
}

final class FormI18n
{
    public const LOCALES = ['de', 'en'];

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    private static ?string $siteLocale = null;

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

    /** Sprache der aktuellen Anfrage (`/en/…` → `en`). */
    public static function requestLocale(): string
    {
        try {
            if (class_exists(\CMS\Router::class) && defined('DB_NAME')) {
                return self::normalizeLocale(\CMS\Router::instance()->getRequestLocale());
            }
        } catch (\Throwable) {
        }

        return self::siteLocale();
    }

    /**
     * Oberflächentext in der gewünschten Sprache; ohne Sprache in der Website-Sprache.
     */
    public static function t(string $key, ?string $locale = null): string
    {
        $locale = $locale === null ? self::siteLocale() : self::normalizeLocale($locale);

        if ($locale === self::siteLocale() && function_exists('__')) {
            $translated = __($key);
            if ($translated !== $key) {
                return $translated;
            }
        }

        $catalog = self::catalog($locale);
        if (isset($catalog[$key])) {
            return $catalog[$key];
        }

        return function_exists('__') ? __($key) : $key;
    }

    /**
     * Zweisprachigen Inhaltstext auswählen (Fallback: andere Sprache).
     */
    public static function pick(mixed $value, string $locale): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }

        $locale = self::normalizeLocale($locale);
        if (trim((string) ($value[$locale] ?? '')) !== '') {
            return (string) $value[$locale];
        }

        foreach (self::LOCALES as $fallback) {
            if (trim((string) ($value[$fallback] ?? '')) !== '') {
                return (string) $value[$fallback];
            }
        }

        return '';
    }

    /**
     * Normalisiert einen Inhaltstext auf `['de' => …, 'en' => …]`.
     *
     * @return array{de: string, en: string}
     */
    public static function texts(mixed $value, int $maxLength = 500, bool $multiline = false): array
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            $value = ['de' => (string) $value, 'en' => ''];
        }
        $value = is_array($value) ? $value : [];

        $result = ['de' => '', 'en' => ''];
        foreach (self::LOCALES as $locale) {
            $result[$locale] = self::cleanText((string) ($value[$locale] ?? ''), $maxLength, $multiline);
        }

        return $result;
    }

    public static function cleanText(string $value, int $maxLength = 500, bool $multiline = false): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = $multiline
            ? (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value)
            : (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
    }

    /** Nur für Tests: zwischengespeicherte Kataloge und Website-Sprache zurücksetzen. */
    public static function reset(?string $siteLocale = null): void
    {
        self::$catalogs = [];
        self::$siteLocale = $siteLocale !== null ? self::normalizeLocale($siteLocale) : null;
    }

    /**
     * Liest nur die `forms.*`-Schlüssel einer Sprachdatei (Zeilen `  "schluessel": "Wert"`).
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
            if (!str_contains($line, '"forms.')) {
                continue;
            }
            if (preg_match('/^\s+"(forms\.[A-Za-z0-9_.-]+)":\s*"((?:[^"\\\\]|\\\\.)*)"\s*$/', $line, $match) === 1) {
                $catalog[$match[1]] = strtr($match[2], ['\\"' => '"', '\\\\' => '\\', '\\n' => "\n", '\\t' => "\t"]);
            }
        }

        return self::$catalogs[$locale] = $catalog;
    }
}
