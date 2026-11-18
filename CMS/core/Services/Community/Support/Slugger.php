<?php
/**
 * Slugs für Foren und Themen: reines ASCII (a-z, 0-9, Bindestrich), unabhängig von Server und Locale.
 *
 * Umlaute werden ausgeschrieben (ä → ae, ö → oe, ü → ue, ß → ss), weitere lateinische Buchstaben über eine feste
 * Tabelle auf ihren Grundbuchstaben gelegt; alles andere wird zum Trennstrich. Forum-Slugs sind über beide Spalten
 * (`slug`, `slug_en`) eindeutig und meiden die reservierten Pfadsegmente (DE und EN), damit sie nie eine feste Adresse
 * unterhalb des Community-Pfads verdecken. Der Slug eines Themas ist nur Zierde: die Adresse löst über die ID auf.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

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

    private const TRANSLITERATION = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'þ' => 'th',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ğ' => 'g', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ı' => 'i',
        'ł' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o',
        'ř' => 'r', 'ś' => 's', 'š' => 's', 'ş' => 's', 'ť' => 't', 'ţ' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    /** Text → Slug aus a-z, 0-9 und einzelnen Bindestrichen, höchstens `$max` Zeichen, ohne Strich an den Rändern. */
    public static function slugify(string $text, int $max = 100): string
    {
        $slug = strtr(mb_strtolower($text, 'UTF-8'), self::TRANSLITERATION);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

        return rtrim(substr($slug, 0, max(0, $max)), '-');
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
