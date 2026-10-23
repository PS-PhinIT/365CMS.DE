<?php
declare(strict_types=1);

/**
 * Comments Module – Moderationslogik für Kommentare
 *
 * Nutzt CMS\Services\CommentService für CRUD.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Database;
use CMS\Logger;
use CMS\Services\CommentService;
use CMS\Services\PermalinkService;

class CommentsModule
{
    private const LIST_STATUSES = ['all', 'pending', 'approved', 'spam', 'trash'];
    private const AUTHOR_SCOPES = ['all', 'registered', 'guest', 'anonymous'];
    private const LINK_SCOPES = ['all', 'linked', 'orphaned'];
    private const CONTENT_VIEWS = ['excerpt', 'full'];
    private const MODERATION_STATUSES = ['pending', 'approved', 'spam', 'trash'];
    private const SUPPORTED_ACTIONS = ['status', 'edit', 'delete', 'bulk', 'empty_trash'];
    private const BULK_ACTIONS = ['approve', 'spam', 'trash', 'delete'];
    private const MAX_BULK_IDS = 100;
    private const STATUS_META = [
        'all' => ['label' => 'Alle', 'count_key' => 'all', 'badge_class' => 'bg-primary'],
        'pending' => ['label' => 'Ausstehend', 'count_key' => 'pending', 'badge_class' => 'bg-warning'],
        'approved' => ['label' => 'Freigegeben', 'count_key' => 'approved', 'badge_class' => 'bg-secondary'],
        'spam' => ['label' => 'Spam', 'count_key' => 'spam', 'badge_class' => 'bg-danger'],
        'trash' => ['label' => 'Papierkorb', 'count_key' => 'trash', 'badge_class' => 'bg-secondary'],
    ];
    private const ROW_STATUS_META = [
        'approved' => ['label' => 'Freigegeben', 'badge_class' => 'bg-success-lt'],
        'pending' => ['label' => 'Ausstehend', 'badge_class' => 'bg-warning-lt'],
        'spam' => ['label' => 'Spam', 'badge_class' => 'bg-danger-lt'],
        'trash' => ['label' => 'Papierkorb', 'badge_class' => 'bg-secondary-lt'],
    ];
    private const SUMMARY_CARD_META = [
        ['count_key' => 'all', 'label' => 'Gesamt', 'icon' => 'comments', 'avatar_class' => 'bg-primary text-white'],
        ['count_key' => 'pending', 'label' => 'Ausstehend', 'icon' => 'alert', 'avatar_class' => 'bg-warning text-white'],
        ['count_key' => 'approved', 'label' => 'Freigegeben', 'icon' => 'check', 'avatar_class' => 'bg-success text-white'],
        ['count_key' => 'spam', 'label' => 'Spam', 'icon' => 'ban', 'avatar_class' => 'bg-danger text-white'],
    ];

    private CommentService $service;
    private Database $db;
    private string $prefix;

    public function __construct()
    {
        $this->service = CommentService::getInstance();
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function canView(): bool
    {
        return function_exists('current_user_can') && current_user_can('comments.view');
    }

    public function canModerate(): bool
    {
        return function_exists('current_user_can') && current_user_can('comments.moderate');
    }

    public function canDelete(): bool
    {
        return function_exists('current_user_can') && current_user_can('comments.delete');
    }

    /**
     * Inhalt und Autorname nachträglich bearbeiten (Recht: comments.moderate).
     */
    public function canEdit(): bool
    {
        return $this->canModerate();
    }

    public function isSupportedAction(string $action): bool
    {
        return in_array(trim($action), self::SUPPORTED_ACTIONS, true);
    }

    public function normalizeStatusFilter(string $status): string
    {
        $status = trim($status);

        if (!in_array($status, self::LIST_STATUSES, true)) {
            return 'all';
        }

        return $status;
    }

    public function buildListUrl(string $status = 'all'): string
    {
        return $this->buildListUrlWithFilters($status, []);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function buildListUrlWithFilters(string $status = 'all', array $filters = []): string
    {
        $status = $this->normalizeStatusFilter($status);
        $normalizedFilters = $this->normalizeFilters($filters);
        $query = [];

        if ($status !== 'all') {
            $query['status'] = $status;
        }

        if ($normalizedFilters['query'] !== '') {
            $query['q'] = $normalizedFilters['query'];
        }

        if ($normalizedFilters['author_scope'] !== 'all') {
            $query['author_scope'] = $normalizedFilters['author_scope'];
        }

        if ($normalizedFilters['link_scope'] !== 'all') {
            $query['link_scope'] = $normalizedFilters['link_scope'];
        }

        return '/admin/comments' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * Daten für die Listenansicht
     */
    public function getListData(string $status = 'all', array $filters = []): array
    {
        $counts = $this->service->getCounts();
        $status = $this->normalizeStatusFilter($status);
        $normalizedFilters = $this->normalizeFilters($filters);
        $comments = array_map(
            fn(mixed $comment): array => $this->normalizeComment($comment),
            $this->service->getComments($status, 200, 0, $normalizedFilters)
        );

        return [
            'comments' => $comments,
            'counts'   => $counts,
            'status'   => $status,
            'tabs' => $this->buildTabs($counts, $normalizedFilters),
            'summaryCards' => $this->buildSummaryCards($counts),
            'filters' => $normalizedFilters,
            'contentView' => (string) ($normalizedFilters['content_view'] ?? 'excerpt'),
            'filterMeta' => $this->buildFilterMeta($normalizedFilters),
            'canModerate' => $this->canModerate(),
            'canEdit' => $this->canEdit(),
            'canDelete' => $this->canDelete(),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{query:string,author_scope:string,link_scope:string,content_view:string}
     */
    public function normalizeFilters(array $filters): array
    {
        $normalized = $this->service->normalizeListFilters($filters);

        $contentView = strtolower(trim((string) ($filters['content_view'] ?? 'excerpt')));
        if (!in_array($contentView, self::CONTENT_VIEWS, true)) {
            $contentView = 'excerpt';
        }

        $normalized['content_view'] = $contentView;

        return $normalized;
    }

    /**
     * Status eines Kommentars ändern
     */
    public function updateStatus(int $id, string $status): array
    {
        if (!$this->canModerate()) {
            return $this->failResult('comments.status.denied', 'Sie dürfen Kommentare nicht moderieren.', [
                'comment_id' => $id,
                'status' => $status,
            ]);
        }

        $status = trim($status);
        if ($id <= 0) {
            return $this->failResult('comments.status.invalid_id', 'Ungültige Kommentar-ID.', [
                'comment_id' => $id,
                'status' => $status,
            ]);
        }

        if (!in_array($status, self::MODERATION_STATUSES, true)) {
            return $this->failResult('comments.status.invalid_status', 'Ungültiger Kommentarstatus.', [
                'comment_id' => $id,
                'status' => $status,
            ]);
        }

        if (!$this->commentExists($id)) {
            return $this->failResult('comments.status.missing', 'Kommentar wurde nicht gefunden.', [
                'comment_id' => $id,
                'status' => $status,
            ]);
        }

        if ($this->service->updateStatus($id, $status)) {
            $labels = [
                'approved' => 'Kommentar freigegeben.',
                'pending'  => 'Kommentar in Warteschlange verschoben.',
                'spam'     => 'Kommentar als Spam markiert.',
                'trash'    => 'Kommentar in den Papierkorb verschoben.',
            ];

            $this->logSuccess(
                'comments.status.updated',
                'Kommentarstatus aktualisiert.',
                ['comment_id' => $id, 'status' => $status],
                $id
            );

            return ['success' => true, 'message' => $labels[$status] ?? 'Status aktualisiert.'];
        }

        return $this->failResult('comments.status.failed', 'Fehler beim Ändern des Status.', [
            'comment_id' => $id,
            'status' => $status,
        ]);
    }

    /**
     * Kommentar nachträglich bearbeiten (z. B. sensible Angaben entfernen).
     * Im Frontend erscheint danach „Bearbeitet vom Administrator“.
     */
    public function edit(int $id, string $author, string $content): array
    {
        if (!$this->canEdit()) {
            return $this->failResult('comments.edit.denied', 'Sie dürfen Kommentare nicht bearbeiten.', [
                'comment_id' => $id,
            ]);
        }

        if ($id <= 0) {
            return $this->failResult('comments.edit.invalid_id', 'Ungültige Kommentar-ID.', [
                'comment_id' => $id,
            ]);
        }

        $editorId = (int) (Auth::getCurrentUser()->id ?? 0);
        $result = $this->service->updateByModerator($id, $author, $content, $editorId);

        if (empty($result['success'])) {
            return $this->failResult('comments.edit.failed', (string) ($result['error'] ?? 'Kommentar konnte nicht gespeichert werden.'), [
                'comment_id' => $id,
            ]);
        }

        if (empty($result['changed'])) {
            return ['success' => true, 'message' => 'Keine Änderungen am Kommentar.'];
        }

        $this->logSuccess(
            'comments.edit.completed',
            'Kommentar durch Moderation bearbeitet.',
            ['comment_id' => $id, 'editor_id' => $editorId],
            $id
        );

        return ['success' => true, 'message' => 'Kommentar gespeichert. Er wird als „Bearbeitet vom Administrator“ gekennzeichnet.'];
    }

    /**
     * Kommentar endgültig löschen
     */
    public function delete(int $id): array
    {
        if (!$this->canDelete()) {
            return $this->failResult('comments.delete.denied', 'Sie dürfen Kommentare nicht löschen.', [
                'comment_id' => $id,
            ]);
        }

        if ($id <= 0) {
            return $this->failResult('comments.delete.invalid_id', 'Ungültige Kommentar-ID.', [
                'comment_id' => $id,
            ]);
        }

        if (!$this->commentExists($id)) {
            return $this->failResult('comments.delete.missing', 'Kommentar wurde nicht gefunden.', [
                'comment_id' => $id,
            ]);
        }

        if ($this->service->delete($id)) {
            $this->logSuccess('comments.delete.completed', 'Kommentar gelöscht.', ['comment_id' => $id], $id);
            return ['success' => true, 'message' => 'Kommentar gelöscht.'];
        }

        return $this->failResult('comments.delete.failed', 'Fehler beim Löschen.', [
            'comment_id' => $id,
        ]);
    }

    /**
     * Bulk-Aktion
     */
    public function bulkAction(string $action, array $ids): array
    {
        $action = trim($action);
        if (!in_array($action, self::BULK_ACTIONS, true)) {
            return $this->failResult('comments.bulk.invalid_action', 'Unbekannte Aktion.', [
                'bulk_action' => $action,
            ]);
        }

        if ($action === 'delete') {
            if (!$this->canDelete()) {
                return $this->failResult('comments.bulk.delete.denied', 'Sie dürfen Kommentare nicht löschen.', [
                    'bulk_action' => $action,
                ]);
            }
        } elseif (!$this->canModerate()) {
            return $this->failResult('comments.bulk.moderation.denied', 'Sie dürfen Kommentare nicht moderieren.', [
                'bulk_action' => $action,
            ]);
        }

        $normalizedIds = $this->normalizeIds($ids);
        if ($normalizedIds === []) {
            return $this->failResult('comments.bulk.no_ids', 'Keine Einträge ausgewählt.', [
                'bulk_action' => $action,
            ]);
        }

        if (count($normalizedIds) > self::MAX_BULK_IDS) {
            return $this->failResult('comments.bulk.limit_exceeded', 'Zu viele Kommentare ausgewählt.', [
                'bulk_action' => $action,
                'requested_count' => count($normalizedIds),
            ]);
        }

        $existingIds = $this->getExistingCommentIds($normalizedIds);
        if ($existingIds === []) {
            return $this->failResult('comments.bulk.no_existing_ids', 'Es wurden keine gültigen Kommentare gefunden.', [
                'bulk_action' => $action,
                'requested_ids' => $normalizedIds,
            ]);
        }

        $processed = 0;
        $failed = 0;

        foreach ($existingIds as $id) {
            $operationSucceeded = false;

            switch ($action) {
                case 'approve':
                    $operationSucceeded = $this->service->updateStatus($id, 'approved');
                    break;
                case 'spam':
                    $operationSucceeded = $this->service->updateStatus($id, 'spam');
                    break;
                case 'trash':
                    $operationSucceeded = $this->service->updateStatus($id, 'trash');
                    break;
                case 'delete':
                    $operationSucceeded = $this->service->delete($id);
                    break;
            }

            if ($operationSucceeded) {
                $processed++;
            } else {
                $failed++;
            }
        }

        $labels = [
            'approve' => 'freigegeben',
            'spam'    => 'als Spam markiert',
            'trash'   => 'in Papierkorb verschoben',
            'delete'  => 'gelöscht',
        ];

        $metadata = [
            'bulk_action' => $action,
            'requested_count' => count($normalizedIds),
            'existing_count' => count($existingIds),
            'processed_count' => $processed,
            'failed_count' => $failed,
        ];

        if ($processed === 0) {
            return $this->failResult('comments.bulk.failed', 'Bulk-Aktion konnte nicht ausgeführt werden.', $metadata);
        }

        if ($failed > 0) {
            $this->logFailure('comments.bulk.partial', 'Kommentar-Bulk-Aktion nur teilweise erfolgreich.', $metadata);

            return [
                'success' => false,
                'error' => $processed . ' Kommentar(e) ' . ($labels[$action] ?? 'bearbeitet') . ', ' . $failed . ' fehlgeschlagen.',
            ];
        }

        $this->logSuccess('comments.bulk.completed', 'Kommentar-Bulk-Aktion abgeschlossen.', $metadata);

        return [
            'success' => true,
            'message' => $processed . ' Kommentar(e) ' . ($labels[$action] ?? 'bearbeitet') . '.',
        ];
    }

    /**
     * Alle Kommentare im Papierkorb endgültig löschen
     */
    public function emptyTrash(): array
    {
        if (!$this->canDelete()) {
            return $this->failResult('comments.trash.empty.denied', 'Sie dürfen Kommentare nicht löschen.');
        }

        $deletedCount = $this->service->deleteAllTrashed();

        if ($deletedCount === 0) {
            return ['success' => true, 'message' => 'Der Papierkorb ist bereits leer.'];
        }

        $this->logSuccess('comments.trash.empty.completed', 'Papierkorb geleert.', ['deleted_count' => $deletedCount]);

        return [
            'success' => true,
            'message' => $deletedCount . ' Kommentar(e) endgültig gelöscht. Der Papierkorb ist jetzt leer.',
        ];
    }

    private function normalizeComment(mixed $comment): array
    {
        $commentId = (int)$this->commentField($comment, 'id', 0);
        $author = trim((string)$this->commentField($comment, 'author', ''));
        $content = trim((string)$this->commentField($comment, 'content', ''));
        $status = trim((string)$this->commentField($comment, 'status', 'pending'));
        $userId = (int)$this->commentField($comment, 'user_id', 0);
        $postDate = (string)$this->commentField($comment, 'post_date', '');
        $postSlug = trim((string)$this->commentField($comment, 'post_slug', ''));
        $postPublishedAt = trim((string)$this->commentField($comment, 'post_published_at', ''));
        $postCreatedAt = trim((string)$this->commentField($comment, 'post_created_at', ''));
        $hasLinkedPost = (int)$this->commentField($comment, 'linked_post_id', 0) > 0;
        $postUrl = $this->buildPostUrl($postSlug, $postPublishedAt, $postCreatedAt);
        $statusMeta = self::ROW_STATUS_META[$status] ?? ['label' => $status, 'badge_class' => 'bg-secondary-lt'];
        $authorScope = $this->determineAuthorScope($userId, $author);
        $editedAt = trim((string)$this->commentField($comment, 'edited_at', ''));

        return [
            'id' => $commentId,
            'post_id' => (int)$this->commentField($comment, 'post_id', 0),
            'user_id' => $userId,
            'author' => $author,
            'author_email' => trim((string)$this->commentField($comment, 'author_email', '')),
            'author_initials' => $this->buildAuthorInitials($author),
            'author_scope' => $authorScope,
            'author_scope_label' => $this->authorScopeLabel($authorScope),
            'content' => $content,
            'excerpt' => $this->buildExcerpt($content),
            'status' => $status,
            'status_label' => (string)($statusMeta['label'] ?? $status),
            'status_badge' => (string)($statusMeta['badge_class'] ?? 'bg-secondary-lt'),
            'post_date' => $postDate,
            'formatted_date' => $this->formatCommentDate($postDate),
            'is_edited' => $editedAt !== '',
            'formatted_edited_at' => $editedAt !== '' ? $this->formatCommentDate($editedAt) : '',
            'post_title' => trim((string)$this->commentField($comment, 'post_title', '')),
            'post_slug' => preg_replace('/[^a-zA-Z0-9\-_\/]/', '', $postSlug) ?? '',
            'post_url' => $postUrl,
            'has_post_link' => $postUrl !== '',
            'has_linked_post' => $hasLinkedPost,
            'actions' => $this->buildRowActions($commentId, $status),
        ];
    }

    /**
     * @param array<string, mixed> $counts
     * @return array<int, array<string, mixed>>
     */
    private function buildSummaryCards(array $counts): array
    {
        $cards = [];

        foreach (self::SUMMARY_CARD_META as $meta) {
            $cards[] = [
                'count' => (int)($counts[$meta['count_key']] ?? 0),
                'label' => (string)($meta['label'] ?? ''),
                'icon' => (string)($meta['icon'] ?? 'comments'),
                'avatar_class' => (string)($meta['avatar_class'] ?? 'bg-secondary text-white'),
            ];
        }

        return $cards;
    }

    /**
     * @param array<string, mixed> $counts
     * @return array<int, array<string, mixed>>
     */
    private function buildTabs(array $counts, array $filters = []): array
    {
        $tabs = [];

        foreach (self::STATUS_META as $status => $meta) {
            $countKey = (string)($meta['count_key'] ?? $status);
            $tabs[] = [
                'status' => $status,
                'label' => (string)($meta['label'] ?? $status),
                'count' => (int)($counts[$countKey] ?? 0),
                'badge_class' => (string)($meta['badge_class'] ?? 'bg-secondary'),
                'url' => $this->buildListUrlWithFilters($status, $filters),
            ];
        }

        return $tabs;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildFilterMeta(array $filters): array
    {
        $activeFilters = [];

        if ((string) ($filters['query'] ?? '') !== '') {
            $activeFilters[] = [
                'label' => 'Suche',
                'value' => (string) $filters['query'],
            ];
        }

        if (($filters['author_scope'] ?? 'all') !== 'all') {
            $activeFilters[] = [
                'label' => 'Autorentyp',
                'value' => $this->authorScopeLabel((string) $filters['author_scope']),
            ];
        }

        if (($filters['link_scope'] ?? 'all') !== 'all') {
            $activeFilters[] = [
                'label' => 'Beitragsbezug',
                'value' => $this->linkScopeLabel((string) $filters['link_scope']),
            ];
        }

        return [
            'author_scope_options' => [
                'all' => 'Alle',
                'registered' => 'Registrierte Nutzer',
                'guest' => 'Gäste',
                'anonymous' => 'Anonyme Mitglieder',
            ],
            'link_scope_options' => [
                'all' => 'Alle',
                'linked' => 'Nur verknüpfte Beiträge',
                'orphaned' => 'Nur verwaiste Kommentare',
            ],
            'content_view_options' => [
                'excerpt' => 'Ausschnitt',
                'full' => 'Volltext',
            ],
            'active_filters' => $activeFilters,
            'active_filter_count' => count($activeFilters),
        ];
    }

    private function determineAuthorScope(int $userId, string $author): string
    {
        if ($userId > 0 && trim($author) === 'Anonym') {
            return 'anonymous';
        }

        if ($userId > 0) {
            return 'registered';
        }

        return 'guest';
    }

    private function authorScopeLabel(string $scope): string
    {
        return match ($scope) {
            'registered' => 'Registriert',
            'guest' => 'Gast',
            'anonymous' => 'Anonymes Mitglied',
            default => 'Alle',
        };
    }

    private function linkScopeLabel(string $scope): string
    {
        return match ($scope) {
            'linked' => 'Verknüpfte Beiträge',
            'orphaned' => 'Verwaiste Kommentare',
            default => 'Alle',
        };
    }

    private function commentField(mixed $comment, string $key, mixed $default = ''): mixed
    {
        if (is_array($comment)) {
            return $comment[$key] ?? $default;
        }

        if (is_object($comment) && isset($comment->{$key})) {
            return $comment->{$key};
        }

        return $default;
    }

    private function buildPostUrl(string $slug, ?string $publishedAt = null, ?string $createdAt = null): string
    {
        $slug = trim($slug, "/ \t\n\r\0\x0B");
        if ($slug === '') {
            return '';
        }

        return PermalinkService::getInstance()->buildPostUrlFromValues($slug, $publishedAt, $createdAt);
    }

    private function formatCommentDate(string $date): string
    {
        $timestamp = strtotime($date);

        return $timestamp !== false ? date('d.m.Y H:i', $timestamp) : '–';
    }

    private function buildExcerpt(string $content): string
    {
        $plainText = trim(preg_replace('/\s+/u', ' ', strip_tags($content)) ?? '');

        return cms_truncate_text($plainText, 120, '');
    }

    private function buildAuthorInitials(string $author): string
    {
        $initialsSource = cms_truncate_text(trim($author), 2, '');
        $initials = function_exists('mb_strtoupper')
            ? mb_strtoupper($initialsSource, 'UTF-8')
            : strtoupper($initialsSource);

        return $initials !== '' ? $initials : 'KO';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRowActions(int $commentId, string $status): array
    {
        $actions = [];

        if ($this->canEdit()) {
            $actions[] = [
                'type' => 'edit',
                'comment_id' => $commentId,
                'label' => 'Bearbeiten',
                'icon' => 'edit',
                'variant' => 'default',
            ];
        }

        if ($this->canModerate()) {
            $statusActions = [
                'approved' => ['label' => 'Freigeben', 'icon' => 'check', 'variant' => 'default'],
                'pending' => ['label' => 'Ausstehend', 'icon' => 'alert', 'variant' => 'default'],
                'spam' => ['label' => 'Spam', 'icon' => 'ban', 'variant' => 'warning'],
                'trash' => ['label' => 'Papierkorb', 'icon' => 'trash', 'variant' => 'danger'],
            ];

            foreach ($statusActions as $targetStatus => $meta) {
                if ($targetStatus === $status) {
                    continue;
                }

                $actions[] = [
                    'type' => 'status',
                    'comment_id' => $commentId,
                    'status' => $targetStatus,
                    'label' => (string)($meta['label'] ?? $targetStatus),
                    'icon' => (string)($meta['icon'] ?? 'check'),
                    'variant' => (string)($meta['variant'] ?? 'default'),
                ];
            }
        }

        if ($this->canDelete()) {
            $actions[] = [
                'type' => 'delete',
                'comment_id' => $commentId,
                'label' => $status === 'trash' ? 'Endgültig löschen' : 'Löschen',
                'icon' => 'trash',
                'variant' => 'danger',
            ];
        }

        return $actions;
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            $value = (int)$id;
            if ($value <= 0) {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    private function commentExists(int $id): bool
    {
        return $this->getExistingCommentIds([$id]) !== [];
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private function getExistingCommentIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->db->get_results(
            "SELECT id FROM {$this->prefix}comments WHERE id IN ({$placeholders})",
            $ids
        ) ?: [];

        $existing = [];
        foreach ($rows as $row) {
            $commentId = (int)($row->id ?? 0);
            if ($commentId > 0) {
                $existing[$commentId] = $commentId;
            }
        }

        return array_values($existing);
    }

    private function failResult(string $action, string $message, array $context = []): array
    {
        $this->logFailure($action, $message, $context);

        return ['success' => false, 'error' => $message];
    }

    private function logFailure(string $action, string $message, array $context = []): void
    {
        Logger::instance()->withChannel('admin.comments')->warning($message, $context);
        AuditLogger::instance()->log(
            AuditLogger::CAT_CONTENT,
            $action,
            $message,
            'comment',
            isset($context['comment_id']) ? (int)$context['comment_id'] : null,
            $context,
            'warning'
        );
    }

    private function logSuccess(string $action, string $message, array $context = [], ?int $commentId = null): void
    {
        Logger::instance()->withChannel('admin.comments')->info($message, $context);
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
}
