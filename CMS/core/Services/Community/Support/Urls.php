<?php
/**
 * Adressen des Community-Moduls: `/<base_path>/…` (deutsch) und `/en/<base_path>/…` (englisch), Sprachpräfix wie
 * `Feed\FeedI18n::path()`. Den Basispfad liefert die Einstellung `base_path`.
 *
 * Themen und Beiträge lösen über die ID auf (`/forum/thema/12-titel`); ein abweichender Slug führt später per 301 auf die
 * kanonische Adresse. Foren tragen ihren Slug (`slug` bzw. `slug_en`) im Pfad. Der Abmeldelink der Mails liegt außerhalb von
 * `base_path` und ohne Sprachpräfix (`/cms-community-unsubscribe/<token>`); die Routen dazu folgen in PR #4.
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
    /** Community-Bereich im Mitgliederbereich (Einstellungen, abgelehnte Beiträge; Seite folgt in PR #4). */
    public const MEMBER_SECTION = '/member/plugin/community';

    /** Pfadsegmente je Sprache. */
    private const SEGMENTS = [
        'topic' => ['de' => 'thema', 'en' => 'topic'],
        'post' => ['de' => 'beitrag', 'en' => 'post'],
        'page' => ['de' => 'seite', 'en' => 'page'],
        'unread' => ['de' => 'ungelesen', 'en' => 'unread'],
        'attachment' => ['de' => 'anhang', 'en' => 'attachment'],
        'preview' => ['de' => 'vorschau', 'en' => 'preview'],
        'new_topic' => ['de' => 'neues-thema', 'en' => 'new-topic'],
        'reply' => ['de' => 'antworten', 'en' => 'reply'],
        'edit' => ['de' => 'bearbeiten', 'en' => 'edit'],
        'search' => ['de' => 'suche', 'en' => 'search'],
        'new' => ['de' => 'neu', 'en' => 'new'],
        'active' => ['de' => 'aktiv', 'en' => 'active'],
        'unanswered' => ['de' => 'unbeantwortet', 'en' => 'unanswered'],
        'member' => ['de' => 'mitglieder', 'en' => 'members'],
    ];

    /** Globale Listen (Pfadsegment = Schlüssel in SEGMENTS). */
    public const LISTINGS = ['new', 'active', 'unanswered'];

    /**
     * Routenmuster je Sprache für den Router (`:name` = ein Pfadsegment). Reihenfolge = Reihenfolge der Eintragung: feste Segmente
     * (`thema`, `beitrag`) vor dem Forum-Slug, sonst verdeckte `/forum/:slug/:page` die Themenadressen.
     *
     * @return array<string, string> Name => Muster
     */
    public static function routePatterns(string $locale = 'de'): array
    {
        $base = self::basePath();
        $topic = self::segment('topic', $locale);
        $post = self::segment('post', $locale);
        $path = static fn (string $suffix): string => FeedI18n::path($base . $suffix, $locale);

        return [
            // Schreibseiten zuerst: `/thema/12/antworten` und `/<forum>/neues-thema` passten sonst auf `topic_page` bzw. `forum_page`.
            'unread' => $path('/' . $topic . '/:id/' . self::segment('unread', $locale)),
            'attachment_preview' => $path('/' . self::segment('attachment', $locale) . '/:id/' . self::segment('preview', $locale)),
            'attachment' => $path('/' . self::segment('attachment', $locale) . '/:id'),
            'search' => $path('/' . self::segment('search', $locale)),
            'list_new' => $path('/' . self::segment('new', $locale)),
            'list_active' => $path('/' . self::segment('active', $locale)),
            'list_unanswered' => $path('/' . self::segment('unanswered', $locale)),
            'member' => $path('/' . self::segment('member', $locale) . '/:id'),
            'reply' => $path('/' . $topic . '/:id/' . self::segment('reply', $locale)),
            'new_topic' => $path('/:slug/' . self::segment('new_topic', $locale)),
            'edit' => $path('/' . $post . '/:id/' . self::segment('edit', $locale)),
            'topic' => $path('/' . $topic . '/:ref'),
            'topic_page' => $path('/' . $topic . '/:ref/:page'),
            'post' => $path('/' . $post . '/:id'),
            'index' => $path(''),
            'forum' => $path('/:slug'),
            'forum_page' => $path('/:slug/:page'),
        ];
    }

    /** Routenmuster der JSON-API (`/forum/api/:action`, `/en/forum/api/:action`); gleiche Adresse in beiden Sprachen. */
    public static function apiPattern(string $locale = 'de'): string
    {
        return FeedI18n::path(self::basePath() . '/api/:action', $locale);
    }

    /** Basisadresse der JSON-API mit Schrägstrich am Ende (`/forum/api/`), die Seite hängt die Aktion an. */
    public static function apiBase(string $locale = 'de'): string
    {
        return FeedI18n::path(self::basePath() . '/api/', $locale);
    }

    /** Ist `$uri` (Pfad ohne Abfrage, mit oder ohne `/en`) eine Adresse der JSON-API? */
    public static function isApiPath(string $uri): bool
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $api = self::basePath() . '/api/';

        return str_starts_with($path, $api) || str_starts_with($path, '/en' . $api);
    }

    /** Seitennummer aus `seite-3`/`page-3` (nur die Segmente der Sprache); sonst `null`. */
    public static function parsePageSegment(string $segment, string $locale = 'de'): ?int
    {
        $prefix = self::segment('page', $locale) . '-';
        if (!str_starts_with($segment, $prefix) || preg_match('/^[1-9]\d{0,5}$/D', substr($segment, strlen($prefix))) !== 1) {
            return null;
        }

        return (int) substr($segment, strlen($prefix));
    }

    /** Pfad des Abmeldelinks (ohne Sprachpräfix, außerhalb von `base_path`). */
    private const UNSUBSCRIBE_PATH = '/cms-community-unsubscribe/';

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

    /** Suche: `/forum/suche`, Abfrage als `?q=`. */
    public static function search(string $locale = 'de'): string
    {
        return FeedI18n::path(self::basePath() . '/' . self::segment('search', $locale), $locale);
    }

    /** Globale Liste `new`, `active` oder `unanswered`: `/forum/neu`, `/forum/aktiv`, `/forum/unbeantwortet`. */
    public static function listing(string $kind, string $locale = 'de'): string
    {
        if (!in_array($kind, self::LISTINGS, true)) {
            throw new \InvalidArgumentException('Unbekannte Liste: ' . $kind);
        }

        return FeedI18n::path(self::basePath() . '/' . self::segment($kind, $locale), $locale);
    }

    /** Formular „Neues Thema“ eines Forums: `/forum/<slug>/neues-thema`. */
    public static function newTopic(string $forumSlug, string $locale = 'de'): string
    {
        return FeedI18n::path(self::basePath() . '/' . rawurlencode($forumSlug) . '/' . self::segment('new_topic', $locale), $locale);
    }

    /** Antwortformular: `/forum/thema/12/antworten`, optional mit `?zitat=<Beitrags-ID>`. */
    public static function reply(int $topicId, string $locale = 'de', ?int $quotePostId = null): string
    {
        return FeedI18n::path(self::basePath() . '/' . self::segment('topic', $locale) . '/' . $topicId . '/' . self::segment('reply', $locale), $locale)
            . ($quotePostId !== null && $quotePostId > 0 ? '?zitat=' . $quotePostId : '');
    }

    /** Bearbeiten-Formular eines Beitrags: `/forum/beitrag/7/bearbeiten`. */
    public static function edit(int $postId, string $locale = 'de'): string
    {
        return FeedI18n::path(self::basePath() . '/' . self::segment('post', $locale) . '/' . $postId . '/' . self::segment('edit', $locale), $locale);
    }

    /** Dauerlink eines Beitrags (löst später auf Thema, Seite und Anker auf). */
    public static function post(int $id, string $locale = 'de'): string
    {
        return FeedI18n::path(self::basePath() . '/' . self::segment('post', $locale) . '/' . $id, $locale);
    }

    /** Öffentliches Profil eines Mitglieds: `/forum/mitglieder/12` (`/en/forum/members/12`). */
    public static function member(int $id, string $locale = 'de'): string
    {
        return FeedI18n::path(self::basePath() . '/' . self::segment('member', $locale) . '/' . $id, $locale);
    }

    /** Erster ungelesener Beitrag eines Themas: `/forum/thema/12/ungelesen`. */
    public static function topicUnread(int $id, string $locale = 'de'): string
    {
        return FeedI18n::path(
            self::basePath() . '/' . self::segment('topic', $locale) . '/' . $id . '/' . self::segment('unread', $locale),
            $locale
        );
    }

    /** Anhang (`/forum/anhang/12`) bzw. seine Vorschau (`/forum/anhang/12/vorschau`). */
    public static function attachment(int $id, bool $preview = false, string $locale = 'de'): string
    {
        return FeedI18n::path(
            self::basePath() . '/' . self::segment('attachment', $locale) . '/' . $id . ($preview ? '/' . self::segment('preview', $locale) : ''),
            $locale
        );
    }

    /** Abmeldelink einer Mail: `/cms-community-unsubscribe/<token>` (Token URL-kodiert). */
    public static function unsubscribe(string $token): string
    {
        return self::UNSUBSCRIBE_PATH . rawurlencode($token);
    }

    /**
     * Absolute Adresse für Mails: `SITE_URL` plus Pfad. Eine Adresse mit Schema bleibt unverändert; ohne `SITE_URL` bleibt der
     * Pfad relativ.
     */
    public static function absolute(string $path): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            return $path;
        }

        return (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '') . '/' . ltrim($path, '/');
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
