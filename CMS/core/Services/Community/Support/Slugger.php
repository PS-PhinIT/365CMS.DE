<?php
/**
 * Slugs für Foren und Themen: reines ASCII (a-z, 0-9, Bindestrich), unabhängig von Server und Locale.
 *
 * Umlaute werden ausgeschrieben (ä → ae, ö → oe, ü → ue, ß → ss), Akzente entfernt (über `CMS\Slug`); alles andere wird
 * zum Trennstrich. Forum-Slugs sind über beide Spalten
 * (`slug`, `slug_en`) eindeutig und meiden die reservierten Pfadsegmente (DE und EN), damit sie nie eine feste Adresse
 * unterhalb des Community-Pfads verdecken. Der Slug eines Themas ist nur Zierde: die Adresse löst über die ID auf.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

use CMS\Slug;

if (!defined('ABSPATH')) {
    exit;
}

final class Slugger
{
    /** Fester Slug, wenn der Titel keine verwertbaren Zeichen enthält. */
    public const TOPIC_FALLBACK = 'thema';
    public const FORUM_FALLBACK = 'forum';

    /** Obergrenze der Versuche für einen freien Forum-Slug (`-2`, `-3`, …). */
    public const MAX_ATTEMPTS = 1000;

    /**
     * Reservierte Pfadsegmente unterhalb des Community-Pfads (deutsch und englisch). Zusätzlich reserviert:
     * `seite-<n>`, `page-<n>` und `en`.
     *
     * @var list<string>
     */
    public const RESERVED = [
        'neu', 'aktiv', 'unbeantwortet', 'suche', 'feed', 'thema', 'beitrag', 'anhang', 'api', 'mitglieder',
        'neues-thema', 'antworten', 'bearbeiten', 'ungelesen',
        'new', 'active', 'unanswered', 'search', 'topic', 'post', 'attachment', 'members',
        'new-topic', 'reply', 'edit', 'unread',
        'en',
    ];

    /**
     * Text → Slug aus a-z, 0-9 und einzelnen Bindestrichen, höchstens `$max` Zeichen, ohne Strich an den Rändern.
     * Die Umschrift (Umlaute, Akzente) liefert der gemeinsame Helfer `CMS\Slug`, damit Foren, Themen, Beiträge, Seiten und
     * Kategorien dieselben Slugs aus demselben Text bilden.
     */
    public static function slugify(string $text, int $max = 100): string
    {
        return $max > 0 ? Slug::fromText($text, $max) : '';
    }

    /** Slug eines Themas: nie leer. */
    public static function topicSlug(string $title): string
    {
        $slug = self::slugify($title);

        return $slug !== '' ? $slug : self::TOPIC_FALLBACK;
    }

    /**
     * Freier Slug für ein Forum: Grundform aus dem Titel, bei Sperrliste oder Belegung mit `-2`, `-3`, … angehängt.
     *
     * @param \Closure(string, ?int): bool $exists prüft beide Spalten (`slug`, `slug_en`); `$ignoreId` = das Forum selbst
     */
    public static function forumSlug(string $title, \Closure $exists, ?int $ignoreId = null): string
    {
        $base = self::slugify($title);
        if ($base === '') {
            $base = self::FORUM_FALLBACK;
        }

        $candidate = $base;
        for ($attempt = 2; $attempt <= self::MAX_ATTEMPTS + 1; $attempt++) {
            if (!self::isReserved($candidate) && !$exists($candidate, $ignoreId)) {
                return $candidate;
            }
            $candidate = $base . '-' . $attempt;
        }

        throw new \RuntimeException('Kein freier Forum-Slug für „' . $base . '“.');
    }

    /** Reservierte Segmente (DE und EN), `seite-<n>`, `page-<n>` und `en`. */
    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true) || preg_match('/^(?:seite|page)-\d+$/', $slug) === 1;
    }
}
