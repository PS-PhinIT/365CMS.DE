<?php
/**
 * Member Sanitizer
 *
 * Gemeinsame Prüf- und Bereinigungsregeln für den Mitgliederbereich (Farben, Links, Assets,
 * Texte, Profilfelder). Ersetzt die zuvor mehrfach kopierten Closures und privaten Methoden in
 * `member/dashboard.php`, `member/partials/header.php` und `MemberController` (MD-14).
 *
 * @package CMS\Member
 */

declare(strict_types=1);

namespace CMS\Member;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberSanitizer
{
    public const DEFAULT_COLOR = '#4f46e5';

    private function __construct()
    {
    }

    /** Hex-Farbe `#rrggbb`, sonst Fallback. */
    public static function color(mixed $value, string $fallback = self::DEFAULT_COLOR): string
    {
        $color = trim((string) $value);

        return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? $color : $fallback;
    }

    /**
     * Link für Navigation und Widgets: lokaler Pfad (`/…`, nicht `//…`) oder gültige http(s)-URL.
     */
    public static function link(mixed $value, string $fallback = '/member/dashboard'): string
    {
        $href = trim((string) $value);
        if ($href === '') {
            return $fallback;
        }

        if (str_starts_with($href, '/')) {
            return str_starts_with($href, '//') || str_contains($href, '\\') ? $fallback : $href;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            return filter_var($href, FILTER_VALIDATE_URL) ? $href : $fallback;
        }

        return $fallback;
    }

    public static function nullableLink(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $href = self::link($value, '');

        return $href !== '' ? $href : null;
    }

    /**
     * Bild-/Logo-Asset: lokaler absoluter Pfad oder gültige http(s)-URL; Laufwerkspfade und `//` werden verworfen.
     */
    public static function asset(mixed $value): string
    {
        $asset = trim((string) $value);
        if ($asset === '' || preg_match('~^[A-Za-z]:[\\\\/]~', $asset) === 1 || str_starts_with($asset, '//')) {
            return '';
        }

        return self::link($asset, '');
    }

    /**
     * Profil-URL (Website, Social): lokaler Pfad oder URL mit erlaubtem Schema.
     *
     * @param list<string> $allowedSchemes
     */
    public static function profileUrl(string $value, array $allowedSchemes = ['http', 'https']): string
    {
        $url = trim($value);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? '' : $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '' || !in_array($scheme, $allowedSchemes, true)) {
            return '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    /** Einzeiliger Klartext ohne Tags und Steuerzeichen, auf `$maxLength` Zeichen gekürzt. */
    public static function text(mixed $value, int $maxLength = 160, string $fallback = ''): string
    {
        $text = trim(strip_tags((string) $value));
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
        if ($text === '') {
            return $fallback;
        }

        return mb_substr($text, 0, max(1, $maxLength), 'UTF-8');
    }

    public static function nullableText(mixed $value, int $maxLength = 80): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = self::text($value, $maxLength);

        return $text !== '' ? $text : null;
    }

    /** Mehrzeiliger Klartext ohne Tags; Zeilenumbrüche bleiben erhalten. */
    public static function multiline(mixed $value, int $maxLength = 2000): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", strip_tags((string) $value));
        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));

        return mb_substr($text, 0, max(1, $maxLength), 'UTF-8');
    }

    /**
     * Wert aus einer festen Liste, sonst Standardwert.
     *
     * @param list<string> $allowed
     */
    public static function choice(mixed $value, array $allowed, string $default): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }

    /** Kalenderdatum `Y-m-d`; ungültige Daten (z. B. 2026-02-30) und Daten in der Zukunft ergeben ''. */
    public static function birthDate(mixed $value): string
    {
        $date = self::date($value);
        if ($date === '') {
            return '';
        }

        return $date <= date('Y-m-d') && $date >= '1900-01-01' ? $date : '';
    }

    /** Kalenderdatum `Y-m-d` streng geprüft, sonst ''. */
    public static function date(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return '';
        }

        return $parsed->format('Y-m-d') === $value ? $value : '';
    }

    /** Telefonnummer: Ziffern, Leerzeichen und `+()/-.`, max. 40 Zeichen; sonst ''. */
    public static function phone(mixed $value): string
    {
        $phone = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
        if ($phone === '') {
            return '';
        }

        return preg_match('~^[0-9+()/.\- ]{3,40}$~', $phone) === 1 ? $phone : '';
    }

    /** HTML-Anker-ID (Buchstaben, Ziffern, `_`, `-`). */
    public static function anchor(mixed $value): string
    {
        return substr((string) preg_replace('/[^a-z0-9_-]/i', '', (string) $value), 0, 80);
    }

    /**
     * Ziel-URL einer Benachrichtigung: lokaler Pfad oder http(s)-URL, max. 500 Zeichen (Spalte `notifications.url`).
     */
    public static function notificationUrl(mixed $value): string
    {
        $url = trim((string) $value);
        if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return '';
        }

        return self::link($url, '');
    }
}
