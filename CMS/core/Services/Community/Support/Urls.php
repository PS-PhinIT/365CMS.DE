<?php
/**
 * Adressen des Community-Moduls: `/<base_path>/…` (deutsch) und `/en/<base_path>/…` (englisch), Sprachpräfix wie
 * `Feed\FeedI18n::path()`. Den Basispfad liefert die Einstellung `base_path`.
 *
 * Themen und Beiträge lösen über die ID auf (`/forum/thema/12-titel`); ein abweichender Slug führt später per 301 auf die
 * kanonische Adresse. Foren tragen ihren Slug (`slug` bzw. `slug_en`) im Pfad.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

use CMS\Services\Feed\FeedI18n;

if (!defined('ABSPATH')) {
    exit;
}

final class Urls
{
    /** Pfadsegmente je Sprache. */
    private const SEGMENTS = [
        'topic' => ['de' => 'thema', 'en' => 'topic'],
        'post' => ['de' => 'beitrag', 'en' => 'post'],
        'page' => ['de' => 'seite', 'en' => 'page'],
    ];

    /** Startseite der Community. */
    public static function index(string $locale = 'de'): string
    {
        return FeedI18n::path(self::basePath(), $locale);
    }

    /** Forum; `$slug` ist der Slug der gewünschten Sprache (siehe forumSlugFor()). */
    public static function forum(string $slug, string $locale = 'de', int $page = 1): string
    {
        return FeedI18n::path(self::basePath() . '/' . rawurlencode($slug) . self::pageSuffix($page, $locale), $locale);
    }

    /** Thema mit Slug-Zierde: `/forum/thema/12-titel`, bei leerem Slug `/forum/thema/12`. */
    public static function topic(int $id, string $slug, string $locale = 'de', int $page = 1): string
    {
        $segment = $slug === '' ? (string) $id : $id . '-' . rawurlencode($slug);

        return FeedI18n::path(
            self::basePath() . '/' . self::segment('topic', $locale) . '/' . $segment . self::pageSuffix($page, $locale),
            $locale
        );
    }

    /** Dauerlink eines Beitrags (löst später auf Thema, Seite und Anker auf). */
    public static function post(int $id, string $locale = 'de'): string
    {
        return FeedI18n::path(self::basePath() . '/' . self::segment('post', $locale) . '/' . $id, $locale);
    }

    /** Slug des Forums in der Sprache der Adresse: `slug_en` fällt auf `slug` zurück. */
    public static function forumSlugFor(string $slug, ?string $slugEn, string $locale): string
    {
        return FeedI18n::normalizeLocale($locale) === 'en' && $slugEn !== null && $slugEn !== '' ? $slugEn : $slug;
    }

    /**
     * Zerlegt das Adresssegment `<id>-<slug>` (der Slug-Teil ist optional).
     *
     * @return array{id: int, slug: string}|null null bei ungültiger oder nicht positiver ID
     */
    public static function parseSegment(string $segment): ?array
    {
        if (preg_match('/^(\d+)(?:-(.*))?$/D', $segment, $m) !== 1 || strlen($m[1]) > 18 || (int) $m[1] < 1) {
            return null;
        }

        return ['id' => (int) $m[1], 'slug' => $m[2] ?? ''];
    }

    private static function basePath(): string
    {
        return '/' . Settings::getInstance()->string('base_path');
    }

    private static function segment(string $name, string $locale): string
    {
        return self::SEGMENTS[$name][FeedI18n::normalizeLocale($locale)];
    }

    private static function pageSuffix(int $page, string $locale): string
    {
        return $page > 1 ? '/' . self::segment('page', $locale) . '-' . $page : '';
    }
}
