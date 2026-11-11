<?php
/**
 * Slug-Regeln des Papierkorbs (ohne Datenbankzugriff, daher direkt testbar).
 *
 * Beim Verschieben in den Papierkorb gibt ein Inhalt seinen Slug frei: Der Originalwert wandert in
 * `trash_slug`/`trash_slug_en`, die Slug-Spalte erhält einen geparkten Platzhalter `__trashed-<id>`.
 * Beim Wiederherstellen gilt der Originalwert, sofern frei – sonst der erste freie Kandidat
 * `<slug>-2`, `<slug>-3`, … (auf die Spaltenlänge gekürzt).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Trash;

if (!defined('ABSPATH')) {
    exit;
}

final class TrashSlug
{
    public const PARKED_PREFIX = '__trashed-';

    private const MAX_ATTEMPTS = 500;

    public static function parked(int $id): string
    {
        return self::PARKED_PREFIX . max(0, $id);
    }

    public static function isParked(string $slug): bool
    {
        return str_starts_with($slug, self::PARKED_PREFIX);
    }

    /**
     * Erster freier Slug ab `$desired` (Original, dann `-2`, `-3`, …).
     *
     * @param callable(string): bool $isTaken
     */
    public static function resolve(string $desired, callable $isTaken, int $maxLength, string $fallbackSuffix = ''): string
    {
        $desired = trim($desired);
        $maxLength = max(8, $maxLength);
        if ($desired === '' || self::isParked($desired)) {
            $desired = $fallbackSuffix !== '' ? 'restored-' . $fallbackSuffix : 'restored';
        }
        $desired = self::truncate($desired, $maxLength);

        if (!$isTaken($desired)) {
            return $desired;
        }

        for ($counter = 2; $counter <= self::MAX_ATTEMPTS; $counter++) {
            $suffix = '-' . $counter;
            $candidate = rtrim(self::truncate($desired, $maxLength - strlen($suffix)), '-') . $suffix;
            if (!$isTaken($candidate)) {
                return $candidate;
            }
        }

        $suffix = '-' . bin2hex(random_bytes(4));

        return rtrim(self::truncate($desired, $maxLength - strlen($suffix)), '-') . $suffix;
    }

    private static function truncate(string $value, int $length): string
    {
        return strlen($value) > $length ? substr($value, 0, max(1, $length)) : $value;
    }
}
