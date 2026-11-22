<?php
/**
 * Öffentliches Community-Profil (`/forum/mitglieder/{id}`) und Signaturen.
 *
 * - `profile()`: Anzeigename, Zähler, Vertrauensstufe, Rang, Signatur und die jüngsten Themen des Mitglieds, aber nur in Foren, die der
 *   Betrachter lesen darf. Ein Profil gibt es nur für aktive Konten mit mindestens einem Thema oder Beitrag (keine Aufzählung von
 *   Konten über die ID); E-Mail-Adresse und Benutzername erscheinen nur, wenn kein Anzeigename gesetzt ist (Benutzername) bzw. nie.
 * - `saveSignature()`: höchstens SIGNATURE_MAX Zeichen und SIGNATURE_LINES Zeilen, Wortfilter (`ContentGuard::filterText()`; Treffer der
 *   Sperr- oder Prüfliste ⇒ `word_blocked`). Gespeichert werden der Text und ein vorberechnetes HTML (maskiert, Zeilenumbrüche, nur
 *   `http(s)`-Adressen als Link mit `rel="nofollow ugc noopener"`); kein Markdown, keine Bilder.
 * - `signatures()`: HTML mehrerer Mitglieder in einer Abfrage (Themenseite).
 *
 * Ergebnisse `{success, error?}` mit Codes aus `Support\Codes`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\MemberRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ProfileService
{
    /** Höchstlänge der Signatur in Zeichen. */
    public const SIGNATURE_MAX = 300;

    /** Höchstzahl der Zeilen einer Signatur. */
    public const SIGNATURE_LINES = 3;

    /** Jüngste Themen auf der Profilseite. */
    public const RECENT_TOPICS = 10;

    private static ?self $instance = null;

    private MemberRepository $members;
    private UserStatsRepository $stats;
    private TopicRepository $topics;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(?int, array<int, int>): list<int> */
    private \Closure $readable;
    /** @var \Closure(string): array{body: string, verdict: string} */
    private \Closure $filter;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, array<int, int>): list<int>|null $readable Standard `CommunityAccess::instance()->filterReadable()`
     * @param \Closure(string): array{body: string, verdict: string}|null $filter Standard `ContentGuard::instance()->filterText(null, …)`
     */
    public function __construct(?Database $db = null, ?\Closure $clock = null, ?\Closure $readable = null, ?\Closure $filter = null)
    {
        $db ??= Database::instance();
        $this->members = new MemberRepository($db);
        $this->stats = new UserStatsRepository($db);
        $this->topics = new TopicRepository($db);
        $this->clock = $clock ?? static fn (): int => time();
        $this->readable = $readable ?? static fn (?int $viewerId, array $forumIds): array => CommunityAccess::instance()->filterReadable($viewerId, $forumIds);
        $this->filter = $filter ?? static function (string $text): array {
            $result = ContentGuard::instance()->filterText(null, $text);

            return ['body' => $result['body'], 'verdict' => $result['verdict']];
        };
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Profil für einen Betrachter oder `null` (kein aktives Konto, keine Beiträge).
     *
     * @return array{id: int, name: string, stats: array<string, mixed>, rank: array<string, mixed>|null, signature_html: string, topics: list<array<string, mixed>>}|null
     */
    public function profile(?int $viewerId, int $userId, string $locale = 'de'): ?array
    {
        if ($userId < 1) {
            return null;
        }
        $account = $this->members->accounts([$userId])[$userId] ?? null;
        $stats = $this->stats->get($userId);
        if ($account === null || ($account['status'] ?? '') !== 'active' || $stats === null
            || (int) $stats['topic_count'] + (int) $stats['post_count'] === 0) {
            return null;
        }
        $page = $this->topics->listByUser($userId, 'published', null, self::RECENT_TOPICS);
        $forumIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['forum_id'], $page->items)));
        $allowed = array_flip(($this->readable)($viewerId, $forumIds));
        $topics = array_values(array_filter($page->items, static fn (array $row): bool => isset($allowed[(int) $row['forum_id']])));

        return [
            'id' => $userId,
            'name' => (string) (($account['display_name'] ?? '') !== '' ? $account['display_name'] : $account['username']),
            'stats' => [
                'topic_count' => (int) $stats['topic_count'],
                'post_count' => (int) $stats['post_count'],
                'reactions_received' => (int) $stats['reactions_received'],
                'solutions_count' => (int) $stats['solutions_count'],
                'trust_level' => (int) $stats['trust_level'],
                'last_post_at' => $stats['last_post_at'] === null ? null : (string) $stats['last_post_at'],
            ],
            'rank' => RankService::instance()->rankFor((int) $stats['post_count'], $stats['rank_id'] === null ? null : (int) $stats['rank_id'], $locale),
            'signature_html' => $this->signatures([$userId])[$userId] ?? '',
            'topics' => $topics,
        ];
    }

    /** Eingegebener Text der eigenen Signatur (`''` = keine). */
    public function signature(int $userId): string
    {
        try {
            return $userId > 0 ? $this->stats->signature($userId) : '';
        } catch (\Throwable $e) {
            $this->warn('signature.load', $e);

            return '';
        }
    }

    /**
     * HTML der Signaturen mehrerer Mitglieder (nur nicht leere). Fehler ergeben `[]`: die Seite bleibt ohne Signaturen lesbar.
     *
     * @param array<array-key, mixed> $userIds
     * @return array<int, string>
     */
    public function signatures(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        try {
            return $this->stats->signaturesMany($ids);
        } catch (\Throwable $e) {
            $this->warn('signature.many', $e);

            return [];
        }
    }

    /**
     * Signatur speichern; leerer Text löscht sie.
     *
     * @return array{success: bool, error?: string}
     */
    public function saveSignature(int $userId, string $text): array
    {
        if ($userId < 1) {
            return ['success' => false, 'error' => 'login_required'];
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if (preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            return ['success' => false, 'error' => 'invalid_input'];
        }
        if (mb_strlen($text, 'UTF-8') > self::SIGNATURE_MAX || substr_count($text, "\n") >= self::SIGNATURE_LINES) {
            return ['success' => false, 'error' => 'body_too_long'];
        }
        if ($text !== '') {
            $filtered = ($this->filter)($text);
            if ($filtered['verdict'] !== ContentGuard::ALLOW) {
                return ['success' => false, 'error' => 'word_blocked'];
            }
            $text = $filtered['body'];
        }
        try {
            $now = gmdate('Y-m-d H:i:s', ($this->clock)());
            $this->stats->ensure($userId, $now);
            $this->stats->saveSignature($userId, $text === '' ? null : $text, $text === '' ? null : self::signatureHtml($text), $now);
        } catch (\Throwable $e) {
            $this->warn('signature.save', $e);

            return ['success' => false, 'error' => 'db_error'];
        }

        return ['success' => true];
    }

    /** HTML einer Signatur: maskiert, Zeilenumbrüche als `<br>`, `http(s)`-Adressen als Link. Rein. */
    public static function signatureHtml(string $text): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = (string) preg_replace_callback(
            '~\bhttps?://[^\s<>"\']+~u',
            static function (array $m): string {
                $url = rtrim($m[0], '.,:!?)');
                $rest = substr($m[0], strlen($url));

                return '<a href="' . $url . '" rel="nofollow ugc noopener">' . $url . '</a>' . $rest;
            },
            $html
        );

        return nl2br($html, false);
    }

    private function warn(string $operation, \Throwable $e): void
    {
        try {
            Logger::instance()->withChannel('community')->warning('Profil: {operation} fehlgeschlagen: {message}', ['operation' => $operation, 'message' => $e->getMessage()]);
        } catch (\Throwable) {
        }
    }
}
