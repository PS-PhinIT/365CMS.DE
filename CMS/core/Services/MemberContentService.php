<?php
/**
 * Member Content Service
 *
 * Inhalte eines Mitglieds für den Mitgliederbereich und Themes: Zähler (Favoriten, Kommentare, Beiträge),
 * letzte Kommentare und die Merkliste (Tabelle `favorites`) inklusive Merken/Entfernen (MD-16).
 * Templates rufen diese Methoden auf, statt selbst SQL auszuführen.
 *
 * @package CMS\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Member\MemberI18n;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberContentService
{
    private const EXCERPT_LENGTH = 160;

    private static ?self $instance = null;

    private Database $db;
    private string $prefix;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    /**
     * @return array{favorites:int, comments:int, posts:int}
     */
    public function getContentStats(int $userId): array
    {
        if ($userId <= 0) {
            return ['favorites' => 0, 'comments' => 0, 'posts' => 0];
        }

        return [
            'favorites' => $this->count(
                "SELECT COUNT(*) FROM {$this->prefix}favorites f
                 INNER JOIN {$this->prefix}posts p ON p.id = f.post_id
                 WHERE f.user_id = ? AND p.status = 'published' AND p.trashed_at IS NULL",
                [$userId]
            ),
            'comments' => $this->count(
                "SELECT COUNT(*) FROM {$this->prefix}comments WHERE user_id = ? AND status IN ('approved', 'pending')",
                [$userId]
            ),
            'posts' => $this->count(
                "SELECT COUNT(*) FROM {$this->prefix}posts WHERE author_id = ? AND status = 'published' AND trashed_at IS NULL",
                [$userId]
            ),
        ];
    }

    /**
     * Letzte eigene Kommentare (freigegeben oder in Prüfung), neueste zuerst.
     *
     * @return list<array{id:int, post_id:int, post_title:string, post_url:string, content:string, status:string, created_at:string}>
     */
    public function getRecentComments(int $userId, int $limit = 5): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $rows = $this->db->get_results(
                "SELECT c.id, c.post_id, c.content, c.status, c.post_date,
                        p.title, p.title_en, p.slug, p.slug_en, p.published_at, p.created_at, p.status AS post_status
                 FROM {$this->prefix}comments c
                 LEFT JOIN {$this->prefix}posts p ON p.id = c.post_id
                 WHERE c.user_id = ? AND c.status IN ('approved', 'pending')
                 ORDER BY c.post_date DESC, c.id DESC
                 LIMIT ?",
                [$userId, max(1, min(50, $limit))]
            );
        } catch (\Throwable) {
            return [];
        }

        $comments = [];
        foreach ($rows as $row) {
            $isPublic = (string) ($row->post_status ?? '') === 'published' && (string) ($row->slug ?? '') !== '';
            $comments[] = [
                'id' => (int) ($row->id ?? 0),
                'post_id' => (int) ($row->post_id ?? 0),
                'post_title' => $this->localizedTitle($row),
                'post_url' => $isPublic ? $this->postPath($row) : '',
                'content' => $this->excerpt((string) ($row->content ?? '')),
                'status' => (string) ($row->status ?? 'pending'),
                'created_at' => (string) ($row->post_date ?? ''),
            ];
        }

        return $comments;
    }

    /**
     * Gemerkte, veröffentlichte Beiträge mit Titel und Permalink, zuletzt gemerkte zuerst.
     *
     * @return list<array{id:int, post_id:int, title:string, url:string, created_at:string}>
     */
    public function getFavoritePosts(int $userId, int $limit = 20): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $rows = $this->db->get_results(
                "SELECT f.id, f.post_id, f.created_at AS favorited_at,
                        p.title, p.title_en, p.slug, p.slug_en, p.published_at, p.created_at
                 FROM {$this->prefix}favorites f
                 INNER JOIN {$this->prefix}posts p ON p.id = f.post_id
                 WHERE f.user_id = ? AND p.status = 'published' AND p.trashed_at IS NULL
                 ORDER BY f.created_at DESC, f.id DESC
                 LIMIT ?",
                [$userId, max(1, min(200, $limit))]
            );
        } catch (\Throwable) {
            return [];
        }

        $favorites = [];
        foreach ($rows as $row) {
            $favorites[] = [
                'id' => (int) ($row->id ?? 0),
                'post_id' => (int) ($row->post_id ?? 0),
                'title' => $this->localizedTitle($row),
                'url' => $this->postPath($row),
                'created_at' => (string) ($row->favorited_at ?? ''),
            ];
        }

        return $favorites;
    }

    public function isFavorite(int $userId, int $postId): bool
    {
        if ($userId <= 0 || $postId <= 0) {
            return false;
        }

        return $this->count("SELECT COUNT(*) FROM {$this->prefix}favorites WHERE user_id = ? AND post_id = ?", [$userId, $postId]) > 0;
    }

    /**
     * Merkt einen veröffentlichten Beitrag bzw. entfernt ihn wieder.
     *
     * @return bool neuer Zustand (true = gemerkt)
     */
    public function toggleFavorite(int $userId, int $postId): bool
    {
        if ($userId <= 0 || $postId <= 0) {
            return false;
        }

        if ($this->isFavorite($userId, $postId)) {
            $this->removeFavorite($userId, $postId);

            return $this->isFavorite($userId, $postId);
        }

        if (!$this->isPublishedPost($postId)) {
            return false;
        }

        try {
            $this->db->execute(
                "INSERT IGNORE INTO {$this->prefix}favorites (user_id, post_id) VALUES (?, ?)",
                [$userId, $postId]
            );
        } catch (\Throwable) {
            return false;
        }

        return $this->isFavorite($userId, $postId);
    }

    /** @return bool true, wenn ein Eintrag entfernt wurde */
    public function removeFavorite(int $userId, int $postId): bool
    {
        if ($userId <= 0 || $postId <= 0) {
            return false;
        }

        try {
            $statement = $this->db->execute(
                "DELETE FROM {$this->prefix}favorites WHERE user_id = ? AND post_id = ?",
                [$userId, $postId]
            );

            return $statement->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isPublishedPost(int $postId): bool
    {
        return $this->count(
            "SELECT COUNT(*) FROM {$this->prefix}posts WHERE id = ? AND status = 'published' AND trashed_at IS NULL",
            [$postId]
        ) > 0;
    }

    /**
     * @param list<mixed> $params
     */
    private function count(string $sql, array $params): int
    {
        try {
            return (int) ($this->db->get_var($sql, $params) ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function localizedTitle(object $row): string
    {
        $locale = MemberI18n::locale();
        $title = $locale !== 'de' ? trim((string) ($row->title_en ?? '')) : '';
        if ($title === '') {
            $title = trim((string) ($row->title ?? ''));
        }

        return $title !== '' ? $title : MemberI18n::t('member.favorites.untitled');
    }

    private function postPath(object $row): string
    {
        try {
            return PermalinkService::getInstance()->buildPostPath($row, MemberI18n::locale());
        } catch (\Throwable) {
            return '';
        }
    }

    private function excerpt(string $content): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($text, 'UTF-8') <= self::EXCERPT_LENGTH) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::EXCERPT_LENGTH - 1, 'UTF-8')) . '…';
    }
}
