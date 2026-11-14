<?php
/**
 * Texte in einer bestimmten Sprache (für Mails an Abonnenten in deren Sprache) und sprachabhängige Pfade.
 * Nutzt dieselbe Katalog-Logik wie der Feed-Aggregator.
 *
 * @package CMS\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\Services\Feed\FeedI18n;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterI18n
{
    public static function t(string $key, string $locale, string|int|float ...$args): string
    {
        return FeedI18n::text($key, $locale, ...$args);
    }

    public static function locale(?string $locale): string
    {
        return FeedI18n::normalizeLocale($locale);
    }

    public static function requestLocale(): string
    {
        return FeedI18n::requestLocale();
    }

    public static function path(string $path, string $locale): string
    {
        return FeedI18n::path($path, $locale);
    }

    public static function url(string $path, string $locale): string
    {
        return rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/') . self::path($path, $locale);
    }

    public static function date(?string $value, string $locale): string
    {
        return FeedI18n::formatDate($value, $locale);
    }
}
