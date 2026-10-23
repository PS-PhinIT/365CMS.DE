<?php
/**
 * CommentService – Kommentarverwaltung (Frontend + Moderation)
 *
 * Verantwortlich für:
 * - Erstellen neuer Kommentare (pending)
 * - Laden freigegebener Kommentare je Beitrag
 * - Moderation im Admin (approve/spam/trash/delete)
 * - Benachrichtigung per Mail bei neuen wartenden Kommentaren
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\AuditLogger;
use CMS\Database;
use CMS\Logger;
use CMS\Security;

if (!defined('ABSPATH')) {
    exit;
}

class CommentService
{
    private const MAX_AUTHOR_LENGTH = 100;
    private const MAX_EMAIL_LENGTH = 150;
    private const MAX_CONTENT_LENGTH = 5000;
    private const MAX_LIST_LIMIT = 200;
    private const MAX_FILTER_SEARCH_LENGTH = 80;
    private const FLOOD_WINDOW_MINUTES = 15;
    private const MAX_COMMENTS_PER_WINDOW = 5;
    private const ANTISPAM_SETTING_KEYS = [
        'antispam_enabled',
        'antispam_honeypot',
        'antispam_min_time',
        'antispam_max_links',
        'antispam_block_empty_ua',
    ];

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
     * Freigegebene Kommentare für einen Beitrag.
     *
     * @return array<object>
     */
    public function getApprovedForPost(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        return $this->db->get_results(
            "SELECT id, user_id, author, content, post_date, edited_at,
                    CASE WHEN user_id IS NOT NULL AND author = 'Anonym' THEN 1 ELSE 0 END AS is_anonymous
             FROM {$this->prefix}comments
             WHERE post_id = ? AND status = 'approved'
             ORDER BY post_date ASC",
            [$postId]
        ) ?: [];
    }

    /**
     * Öffentliche Kommentar-Erstellung (immer als pending).
     */
    public function createPendingComment(
        int $postId,
        string $authorName,
        string $authorEmail,
        string $content,
        string $authorIp = '',
        ?int $userId = null,
        bool $isAnonymous = false
    ): int|false {
        [$resolvedAuthorName, $resolvedAuthorEmail] = $this->resolveCommentAuthorIdentity($userId, $authorName, $authorEmail);

        if (($userId ?? 0) > 0 && $isAnonymous) {
            $resolvedAuthorName = 'Anonym';
        }

        $authorName = $this->sanitizeAuthorName($resolvedAuthorName);

        $authorEmail = $this->substringUtf8(trim($resolvedAuthorEmail), 0, self::MAX_EMAIL_LENGTH);
        $validatedEmail = filter_var($authorEmail, FILTER_VALIDATE_EMAIL);

        $cleanContent = $this->sanitizeCommentContent($content);
        $normalizedIp = $this->normalizeIpAddress($authorIp);

        if ($postId <= 0 || $authorName === '' || $validatedEmail === false || $cleanContent === '') {
            $this->logFailure('comments.create.invalid_payload', 'Kommentar mit ungültigen Eingaben verworfen.', [
                'post_id' => $postId,
                'user_id' => $userId,
                'has_author' => $authorName !== '',
                'has_valid_email' => $validatedEmail !== false,
                'has_content' => $cleanContent !== '',
            ]);
            return false;
        }

        if (!$this->isCommentablePost($postId)) {
            $this->logFailure('comments.create.post_not_commentable', 'Kommentar für nicht kommentierbaren Beitrag verworfen.', [
                'post_id' => $postId,
                'user_id' => $userId,
            ]);
            return false;
        }

        if ($this->isRateLimited((string) $validatedEmail, $normalizedIp, $userId)) {
            $this->logFailure('comments.create.rate_limited', 'Kommentar wegen Kommentar-Flood-Limit verworfen.', [
                'post_id' => $postId,
                'user_id' => $userId,
                'email_masked' => $this->maskEmailForAudit((string) $validatedEmail),
                'ip' => $normalizedIp,
            ]);
            return false;
        }

        if ($this->isRejectedByAntispam((string) $validatedEmail, $normalizedIp, $authorName, $cleanContent)) {
            $this->logFailure('comments.create.antispam_rejected', 'Kommentar durch AntiSpam-Regel verworfen.', [
                'post_id' => $postId,
                'user_id' => $userId,
                'email_masked' => $this->maskEmailForAudit((string) $validatedEmail),
                'ip' => $normalizedIp,
            ]);
            return false;
        }

        $insertId = $this->db->insert('comments', [
            'post_id' => $postId,
            'user_id' => $userId,
            'author' => $authorName,
            'author_email' => (string) $validatedEmail,
            'author_ip' => $normalizedIp,
            'content' => $cleanContent,
            'status' => 'pending',
        ]);

        if ($insertId === false) {
            $this->logFailure('comments.create.persist_failed', 'Kommentar konnte nicht gespeichert werden.', [
                'post_id' => $postId,
                'user_id' => $userId,
            ]);
            return false;
        }

        $this->logSuccess('comments.create.pending', 'Kommentar gespeichert und zur Moderation vorgemerkt.', [
            'comment_id' => $insertId,
            'post_id' => $postId,
            'user_id' => $userId,
            'is_anonymous' => $isAnonymous,
        ], $insertId);

        $this->notifyAdminForPendingComment($postId, $authorName, (string) $validatedEmail);

        return $insertId;
    }

    /**
     * Nutzt bei eingeloggten Nutzern immer das hinterlegte Profil statt frei gesendeter Formularwerte.
     *
     * @return array{0:string,1:string}
     */
    private function resolveCommentAuthorIdentity(?int $userId, string $fallbackName, string $fallbackEmail): array
    {
        $fallbackName = trim($fallbackName);
        $fallbackEmail = trim($fallbackEmail);

        if (($userId ?? 0) <= 0) {
            return [$fallbackName, $fallbackEmail];
        }

        $user = $this->db->get_row(
            "SELECT username, email, display_name
             FROM {$this->prefix}users
             WHERE id = ?
             LIMIT 1",
            [(int) $userId]
        );

        if (!$user) {
            return [$fallbackName, $fallbackEmail];
        }

        $resolvedName = trim((string) ($user->display_name ?? ''));
        if ($resolvedName === '') {
            $resolvedName = trim((string) ($user->username ?? ''));
        }
        if ($resolvedName === '') {
            $resolvedName = $fallbackName;
        }

        $resolvedEmail = trim((string) ($user->email ?? ''));
        if ($resolvedEmail === '') {
            $resolvedEmail = $fallbackEmail;
        }

        return [$resolvedName, $resolvedEmail];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function getComments(string $status = 'all', int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $limit = max(1, min(self::MAX_LIST_LIMIT, $limit));
        $offset = max(0, $offset);

        $status = in_array($status, ['all', 'pending', 'approved', 'spam', 'trash'], true) ? $status : 'all';
        $normalizedFilters = $this->normalizeListFilters($filters);
        $where = [];
        $params = [];

        if (in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) {
            $where[] = 'c.status = ?';
            $params[] = $status;
        } elseif ($status === 'all') {
            $where[] = "c.status <> 'spam'";
        }

        if ($normalizedFilters['query'] !== '') {
            $like = '%' . $this->escapeLikeValue($normalizedFilters['query']) . '%';
            $where[] = '(c.author LIKE ? ESCAPE "\\" OR c.author_email LIKE ? ESCAPE "\\" OR c.content LIKE ? ESCAPE "\\" OR COALESCE(p.title, "") LIKE ? ESCAPE "\\")';
            array_push($params, $like, $like, $like, $like);
        }

        if ($normalizedFilters['author_scope'] === 'registered') {
            $where[] = 'c.user_id IS NOT NULL AND c.author <> ?';
            $params[] = 'Anonym';
        } elseif ($normalizedFilters['author_scope'] === 'guest') {
            $where[] = 'c.user_id IS NULL';
        } elseif ($normalizedFilters['author_scope'] === 'anonymous') {
            $where[] = 'c.user_id IS NOT NULL AND c.author = ?';
            $params[] = 'Anonym';
        }

        if ($normalizedFilters['link_scope'] === 'linked') {
            $where[] = 'p.id IS NOT NULL';
        } elseif ($normalizedFilters['link_scope'] === 'orphaned') {
            $where[] = 'p.id IS NULL';
        }

        $params[] = $limit;
        $params[] = $offset;

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        return $this->db->get_results(
            "SELECT c.id, c.post_id, c.author, c.author_email, c.content, c.status, c.post_date,
                c.user_id, c.edited_at, c.edited_by,
                p.id AS linked_post_id,
                p.title AS post_title, p.slug AS post_slug,
                p.published_at AS post_published_at, p.created_at AS post_created_at
             FROM {$this->prefix}comments c
             LEFT JOIN {$this->prefix}posts p ON p.id = c.post_id
             {$whereSql}
             ORDER BY c.post_date DESC
             LIMIT ? OFFSET ?",
            $params
        ) ?: [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{query:string,author_scope:string,link_scope:string}
     */
    public function normalizeListFilters(array $filters): array
    {
        $query = trim((string) ($filters['query'] ?? $filters['q'] ?? ''));
        $query = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $query) ?? '';
        $query = preg_replace('/\s+/u', ' ', $query) ?? '';
        $query = $this->substringUtf8($query, 0, self::MAX_FILTER_SEARCH_LENGTH);

        $authorScope = strtolower(trim((string) ($filters['author_scope'] ?? 'all')));
        if (!in_array($authorScope, ['all', 'registered', 'guest', 'anonymous'], true)) {
            $authorScope = 'all';
        }

        $linkScope = strtolower(trim((string) ($filters['link_scope'] ?? 'all')));
        if (!in_array($linkScope, ['all', 'linked', 'orphaned'], true)) {
            $linkScope = 'all';
        }

        return [
            'query' => $query,
            'author_scope' => $authorScope,
            'link_scope' => $linkScope,
        ];
    }

    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function getCounts(): array
    {
        $rows = $this->db->get_results(
            "SELECT status, COUNT(*) AS cnt
             FROM {$this->prefix}comments
             GROUP BY status"
        ) ?: [];

        $counts = [
            'all' => 0,
            'pending' => 0,
            'approved' => 0,
            'spam' => 0,
            'trash' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row->status ?? '');
            $cnt = (int) ($row->cnt ?? 0);
            if (isset($counts[$status])) {
                $counts[$status] = $cnt;
            }
            if ($status !== 'spam') {
                $counts['all'] += $cnt;
            }
        }

        return $counts;
    }

    public function updateStatus(int $commentId, string $status): bool
    {
        if ($commentId <= 0 || !in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) {
            return false;
        }

        return $this->db->update('comments', ['status' => $status], ['id' => $commentId]);
    }

    /**
     * Nachträgliche Bearbeitung durch die Moderation (z. B. sensible Angaben entfernen).
     * Markiert den Kommentar als „Bearbeitet vom Administrator“; Status und Datum bleiben unverändert.
     *
     * @return array{success:bool,changed:bool,error?:string}
     */
    public function updateByModerator(int $commentId, string $authorName, string $content, int $editorUserId): array
    {
        if ($commentId <= 0) {
            return ['success' => false, 'changed' => false, 'error' => 'Ungültige Kommentar-ID.'];
        }

        $current = $this->db->get_row(
            "SELECT id, author, content FROM {$this->prefix}comments WHERE id = ? LIMIT 1",
            [$commentId]
        );
        if (!$current) {
            return ['success' => false, 'changed' => false, 'error' => 'Kommentar wurde nicht gefunden.'];
        }

        $cleanAuthor = $this->sanitizeAuthorName($authorName);
        if ($cleanAuthor === '') {
            return ['success' => false, 'changed' => false, 'error' => 'Der Autorname darf nicht leer sein.'];
        }

        $cleanContent = trim(PurifierService::getInstance()->purify($content, 'strict'));
        if ($cleanContent === '') {
            return ['success' => false, 'changed' => false, 'error' => 'Der Kommentar darf nicht leer sein.'];
        }

        if ($this->lengthUtf8($cleanContent) > self::MAX_CONTENT_LENGTH) {
            return [
                'success' => false,
                'changed' => false,
                'error' => 'Der Kommentar darf höchstens ' . self::MAX_CONTENT_LENGTH . ' Zeichen lang sein.',
            ];
        }

        if ($cleanAuthor === (string) ($current->author ?? '') && $cleanContent === (string) ($current->content ?? '')) {
            return ['success' => true, 'changed' => false];
        }

        $updated = $this->db->update('comments', [
            'author' => $cleanAuthor,
            'content' => $cleanContent,
            'edited_at' => date('Y-m-d H:i:s'),
            'edited_by' => $editorUserId > 0 ? $editorUserId : null,
        ], ['id' => $commentId]);

        if (!$updated) {
            return ['success' => false, 'changed' => false, 'error' => 'Kommentar konnte nicht gespeichert werden.'];
        }

        return ['success' => true, 'changed' => true];
    }

    public function delete(int $commentId): bool
    {
        if ($commentId <= 0) {
            return false;
        }

        return $this->db->delete('comments', ['id' => $commentId]);
    }

    /**
     * Alle Kommentare im Papierkorb endgültig löschen
     */
    public function deleteAllTrashed(): int
    {
        $ids = $this->db->get_col(
            "SELECT id FROM {$this->prefix}comments WHERE status = 'trash'"
        ) ?: [];

        if ($ids === []) {
            return 0;
        }

        if (!$this->db->delete('comments', ['status' => 'trash'])) {
            return 0;
        }

        return count($ids);
    }

    private function sanitizeAuthorName(string $authorName): string
    {
        $authorName = trim(strip_tags($authorName));
        $authorName = preg_replace('/\s+/u', ' ', $authorName) ?? '';

        return $this->substringUtf8($authorName, 0, self::MAX_AUTHOR_LENGTH);
    }

    private function sanitizeCommentContent(string $content): string
    {
        $cleanContent = trim(PurifierService::getInstance()->purify($content, 'strict'));
        if ($cleanContent === '') {
            return '';
        }

        if ($this->lengthUtf8($cleanContent) > self::MAX_CONTENT_LENGTH) {
            return '';
        }

        return $cleanContent;
    }

    private function normalizeIpAddress(string $authorIp): string
    {
        $authorIp = trim($authorIp);
        if ($authorIp === '') {
            return '';
        }

        return filter_var($authorIp, FILTER_VALIDATE_IP) ? $this->substringUtf8($authorIp, 0, 45) : '';
    }

    private function maskEmailForAudit(string $email): string
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        [$local, $domain] = explode('@', $email, 2);
        $localLength = $this->lengthUtf8($local);

        if ($localLength <= 2) {
            $maskedLocal = $this->substringUtf8($local, 0, 1) . '*';
        } else {
            $maskedLocal = $this->substringUtf8($local, 0, 1)
                . str_repeat('*', max(1, $localLength - 2))
                . $this->substringUtf8($local, -1, 1);
        }

        return $maskedLocal . '@' . strtolower($domain);
    }

    private function substringUtf8(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($value, $start, null, 'UTF-8')
                : mb_substr($value, $start, $length, 'UTF-8');
        }

        return $length === null
            ? substr($value, $start)
            : substr($value, $start, $length);
    }

    private function lengthUtf8(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    /**
     * Globaler Schalter „Kommentare aktivieren“ (Einstellungen → Allgemein); Standard: an.
     */
    public function areCommentsEnabledGlobally(): bool
    {
        return (string) (OptionStore::getInstance()->get('comments_enabled') ?? '1') !== '0';
    }

    private function isCommentablePost(int $postId): bool
    {
        if (!$this->areCommentsEnabledGlobally()) {
            return false;
        }

        $row = $this->db->get_row(
            "SELECT id, status, allow_comments
             FROM {$this->prefix}posts
             WHERE id = ?
             LIMIT 1",
            [$postId]
        );

        if (!$row) {
            return false;
        }

        return (string) ($row->status ?? '') === 'published'
            && (int) ($row->allow_comments ?? 0) === 1;
    }

    private function isRateLimited(string $email, string $ipAddress, ?int $userId): bool
    {
        $conditions = [];
        $params = [];

        if ($ipAddress !== '') {
            $conditions[] = 'author_ip = ?';
            $params[] = $ipAddress;
        }

        $conditions[] = 'author_email = ?';
        $params[] = $email;

        if (($userId ?? 0) > 0) {
            $conditions[] = 'user_id = ?';
            $params[] = (int) $userId;
        }

        if ($conditions === []) {
            return false;
        }

        $query = implode(' OR ', $conditions);
        $count = (int) ($this->db->get_var(
            "SELECT COUNT(*)
             FROM {$this->prefix}comments
             WHERE ({$query})
               AND post_date >= (NOW() - INTERVAL " . self::FLOOD_WINDOW_MINUTES . " MINUTE)",
            $params
        ) ?? 0);

        return $count >= self::MAX_COMMENTS_PER_WINDOW;
    }

    private function isRejectedByAntispam(string $email, string $ipAddress, string $authorName, string $content): bool
    {
        $result = AntispamService::getInstance()->evaluate([
            'honeypot_value' => (string) ($_POST['comment_hp'] ?? ''),
            'started_at' => (int) ($_POST['comment_started_at'] ?? 0),
            'email' => $email,
            'ip_address' => $ipAddress,
            'author_name' => $authorName,
            'content' => $content,
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'source' => 'comment',
        ]);

        return !empty($result['rejected']);
    }




    private function lowerUtf8(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function containsUtf8(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return function_exists('mb_stripos')
            ? mb_stripos($haystack, $needle, 0, 'UTF-8') !== false
            : stripos($haystack, $needle) !== false;
    }

    private function notifyAdminForPendingComment(int $postId, string $author, string $email): void
    {
        $to = defined('ADMIN_EMAIL') ? (string) ADMIN_EMAIL : '';
        if ($to === '') {
            return;
        }

        $postTitle = (string) ($this->db->get_var(
            "SELECT title FROM {$this->prefix}posts WHERE id = ? LIMIT 1",
            [$postId]
        ) ?? ('Post #' . $postId));

        $subject = 'Neuer Kommentar wartet auf Freigabe';
        $body = '<p>Ein neuer Kommentar wurde eingereicht und wartet auf Moderation.</p>'
            . '<p><strong>Beitrag:</strong> ' . htmlspecialchars($postTitle, ENT_QUOTES) . '<br>'
            . '<strong>Autor:</strong> ' . htmlspecialchars($author, ENT_QUOTES) . '<br>'
            . '<strong>E-Mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES) . '</p>'
            . '<p><a href="' . htmlspecialchars((string) SITE_URL, ENT_QUOTES) . '/admin/comments">➡️ Zur Moderation</a></p>';

        $headers = [
            'X-365CMS-Test-Source' => 'comments-pending-moderation',
        ];

        if (MailQueueService::getInstance()->shouldQueue($headers)) {
            MailQueueService::getInstance()->enqueue(
                $to,
                $subject,
                $body,
                $headers,
                null,
                'comments-pending-moderation'
            );
            return;
        }

        MailService::getInstance()->send($to, $subject, $body, $headers);
    }

    private function logFailure(string $action, string $message, array $context = []): void
    {
        $context = $this->sanitizeLogContext($context);
        Logger::instance()->withChannel('comments')->warning($message, $context);
        AuditLogger::instance()->log(
            AuditLogger::CAT_CONTENT,
            $action,
            $message,
            'comment',
            isset($context['comment_id']) ? (int) $context['comment_id'] : null,
            $context,
            'warning'
        );
    }

    private function logSuccess(string $action, string $message, array $context = [], ?int $commentId = null): void
    {
        $context = $this->sanitizeLogContext($context);
        Logger::instance()->withChannel('comments')->info($message, $context);
        AuditLogger::instance()->log(
            AuditLogger::CAT_CONTENT,
            $action,
            $message,
            'comment',
            $commentId,
            $context,
            'info'
        );
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function sanitizeLogContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = Security::escape(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? ''));
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitizeLogContext($value);
            }
        }

        return $context;
    }
}
