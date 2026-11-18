<?php
/**
 * Beiträge der Community (PR #2a): Antworten, Bearbeiten mit Verlauf, weiches Löschen und die Beitragsliste eines Themas.
 *
 * Grundsätze (Entwurfsvertrag PR #2a, §1; Karte §3.0 bis §3.6):
 * - Jede Schreibaktion ruft `can()` selbst auf (Closure `$can`): einmal vorab und noch einmal unter den Sperren mit
 *   `fresh = true`. Rechte und Zustand werden also erst entschieden, wenn niemand mehr dazwischenschreiben kann.
 * - Sperrreihenfolge: Forum → Thema → Beitrag → Mitglied (`community_user_stats`). Zähler ändern sich nur atomar
 *   (`x = x + 1` in den Repositories), nie durch Lesen, Rechnen und Schreiben. Wechselt ein Thema zwischen dem Lesen und
 *   dem Sperren das Forum (Verschieben), beginnt die Aktion von vorn.
 * - Doppeltes Absenden: Inhaltsvergleich im Fenster `duplicate_window_seconds` unter der Sperre des Themas; ein Treffer
 *   liefert dasselbe Ergebnis mit `duplicate = true`, schreibt nichts und löst keinen Hook aus.
 * - Zähler (Matrix §3.6): veröffentlichte Beiträge zählen, wartende nirgends. Forumzähler zählen nur Beiträge in
 *   veröffentlichten Themen (die Freigabe eines wartenden Themas bucht in PR #2b nach).
 * - Hooks laufen nach dem Commit über `Events::fire()`; eine Ausnahme im Hook bricht nichts ab.
 * - Zeit: UTC (`gmdate`), die Uhr wird je Aufruf einmal gelesen. `body_html` bleibt NULL (`rendered_version` 0) und `ip_hash`
 *   NULL; es gibt kein Auto-Abo.
 * - Ergebnisse sind Arrays `{success, error?, retry_after?, …}`; `error` ist ein Code aus `Support\Codes`. Datenbankfehler
 *   rollen die Transaktion zurück, werden protokolliert und ergeben `error = db_error`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\ReadStateRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Slugger;
use CMS\Services\Community\Support\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

final class PostService
{
    /** Format der Beiträge in PR #2a (Markdown-Quelle). */
    public const FORMAT = 'markdown';
    /** Längster Grund einer Bearbeitung in Zeichen (Spalte `edit_reason`). */
    public const MAX_REASON = 255;
    /** Versuche, wenn ein Thema zwischen Lesen und Sperren das Forum wechselt. */
    private const MAX_ATTEMPTS = 3;

    /** Spalten, die nie an Leser gehen. */
    private const HIDDEN_COLUMNS = ['ip_hash'];
    /** Ganzzahlspalten einer Beitragszeile. */
    private const INT_COLUMNS = ['id', 'topic_id', 'forum_id', 'user_id', 'is_first_post', 'rendered_version', 'quote_of_post_id', 'edit_count', 'edited_by', 'reaction_count'];

    private static ?self $instance = null;

    private Database $db;
    private Settings $settings;
    /** @var \Closure(): int Unix-Zeit */
    private \Closure $clock;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision Mitglied, Aktion, Kontext */
    private \Closure $can;
    /** @var \Closure(string, mixed...): void Hook-Auslösung */
    private \Closure $dispatch;
    private ForumRepository $forums;
    private TopicRepository $topics;
    private PostRepository $posts;
    private ReadStateRepository $readStates;
    private UserStatsRepository $stats;
    private QuoteService $quotes;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard: `CommunityAccess::instance()->can()`; ohne diese Klasse wird alles abgelehnt
     * @param \Closure(string, mixed...): void|null $dispatch Standard: `Events::fire()`
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $clock = null,
        ?\Closure $can = null,
        ?\Closure $dispatch = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->can = $can ?? static function (?int $userId, string $action, array $context): AccessDecision {
            return class_exists(CommunityAccess::class)
                ? CommunityAccess::instance()->can($userId, $action, $context)
                : AccessDecision::deny('no_permission');
        };
        $this->dispatch = $dispatch ?? static function (string $hook, mixed ...$args): void {
            Events::fire($hook, ...$args);
        };
        $this->forums = new ForumRepository($this->db);
        $this->topics = new TopicRepository($this->db);
        $this->posts = new PostRepository($this->db);
        $this->readStates = new ReadStateRepository($this->db);
        $this->stats = new UserStatsRepository($this->db);
        $this->quotes = new QuoteService($this->db, $this->settings, $this->clock, $this->can);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Reine Regeln ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Text eines Beitrags: Zeilenenden vereinheitlicht (`\n`), Steuerzeichen außer Zeilenumbruch und Tabulator entfernt,
     * Rand getrimmt. `null` bei ungültigem UTF-8.
     */
    public static function normalizeBody(string $body): ?string
    {
        if (!mb_check_encoding($body, 'UTF-8')) {
            return null;
        }
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        return trim((string) preg_replace('/[^\P{Cc}\n\t]/u', '', $body));
    }

    /** Grund einer Bearbeitung: eine Zeile ohne Steuerzeichen, höchstens MAX_REASON Zeichen; leer ergibt `null`. */
    public static function normalizeReason(?string $reason): ?string
    {
        if ($reason === null || !mb_check_encoding($reason, 'UTF-8')) {
            return null;
        }
        $reason = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\P{Cc}\s]/u', '', $reason)));
        $reason = trim(mb_substr($reason, 0, self::MAX_REASON, 'UTF-8'));

        return $reason === '' ? null : $reason;
    }

    // ── Antworten ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Antwort auf ein Thema.
     *
     * `$options`: `quote_of_post_id` (Beitrag, für den „Zitieren“ benutzt wurde; muss zitierbar sein, sonst
     * `quote_unavailable`), `seen_last_post_id` (letzter Beitrag, den das Mitglied beim Öffnen des Formulars kannte) und `force`
     * (trotz neuer Beiträge senden). Ist `topic.last_post_id > seen_last_post_id` und `force` nicht gesetzt, wird nichts
     * gespeichert: `error = new_posts` mit `new_posts` (Zahl der neuen Beiträge). Mit `force` bleibt der Lesestatus des Autors
     * bei `seen_last_post_id`, damit die ungelesenen Fremdbeiträge ungelesen bleiben.
     *
     * Ergebnis bei Erfolg: `post_id`, `topic_id`, `forum_id`, `status` (`published`/`pending`), `position` und `page`
     * (Stelle in der Liste der veröffentlichten Beiträge und Seite nach `posts_per_page`; bei `pending` `null`), bei
     * doppeltem Absenden zusätzlich `duplicate = true`.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function reply(int $userId, int $topicId, string $body, array $options = []): array
    {
        if ($topicId < 1) {
            return self::failure('invalid_input');
        }
        $body = self::normalizeBody($body);
        if ($body === null) {
            return self::failure('invalid_input');
        }
        $error = $this->bodyError($body);
        if ($error !== null) {
            return self::failure($error);
        }
        $quoteOf = max(0, (int) ($options['quote_of_post_id'] ?? 0));
        $seen = max(0, (int) ($options['seen_last_post_id'] ?? 0));
        $force = !empty($options['force']);

        $nowTs = ($this->clock)();
        $decision = $this->decide($userId, 'reply', ['topic_id' => $topicId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        if ($userId < 1) {
            return self::failure('invalid_input');
        }
        if ($quoteOf > 0 && !$this->quotes->isQuotable($quoteOf, $userId)) {
            return self::failure('quote_unavailable');
        }

        $now = gmdate('Y-m-d H:i:s', $nowTs);
        $since = gmdate('Y-m-d H:i:s', $nowTs - $this->settings->int('duplicate_window_seconds'));
        try {
            $result = $this->retrying($topicId, function (array $topic) use ($userId, $topicId, $body, $quoteOf, $seen, $force, $nowTs, $now, $since): ?array {
                return $this->replyLocked($userId, $topicId, (int) $topic['forum_id'], $body, $quoteOf, $seen, $force, $nowTs, $now, $since);
            });
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('post.reply', $e, ['topic_id' => $topicId]);
        }
        if (!empty($result['success']) && empty($result['duplicate'])) {
            $this->fire(Events::POST_CREATED, (int) $result['post_id'], $topicId, $userId, (string) $result['status']);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null null: das Thema hat das Forum gewechselt, der Aufrufer beginnt von vorn
     */
    private function replyLocked(int $userId, int $topicId, int $forumId, string $body, int $quoteOf, int $seen, bool $force, int $nowTs, string $now, string $since): ?array
    {
        // Sperrreihenfolge: Forum → Thema → Beitrag → Mitglied.
        $forum = $this->forums->lockForUpdate($forumId);
        if ($forum === null) {
            return self::failure('forum_not_found');
        }
        $topic = $this->topics->lockForUpdate($topicId);
        if ($topic === null) {
            return self::failure('topic_not_found');
        }
        if ((int) $topic['forum_id'] !== $forumId) {
            return null;
        }
        $closed = self::closedTopicError((string) $topic['status']);
        if ($closed !== null) {
            return self::failure($closed);
        }

        // Doppeltes Absenden zuerst: wer ein zweites Mal absendet, bekommt das Ergebnis des ersten Mals, nicht „zu schnell“.
        $duplicate = $this->posts->findRecentDuplicate($userId, $topicId, $body, $since);
        if ($duplicate !== null && $duplicate['is_first_post'] === 0) {
            return $this->duplicateResult($duplicate, $forumId, (string) $topic['status']);
        }

        $this->stats->lockForUpdate($userId, $now);
        $decision = $this->decide($userId, 'reply', ['topic_id' => $topicId, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        $status = self::initialStatus($decision);

        $lastPostId = (int) ($topic['last_post_id'] ?? 0);
        $conflict = $seen > 0 && $lastPostId > $seen;
        if ($conflict && !$force) {
            return [
                'success' => false,
                'error' => 'new_posts',
                'new_posts' => $this->posts->positionOf($topicId, PHP_INT_MAX) - $this->posts->positionOf($topicId, $seen + 1),
                'last_post_id' => $lastPostId,
            ];
        }

        $postId = $this->posts->insert([
            'topic_id' => $topicId,
            'forum_id' => $forumId,
            'user_id' => $userId,
            'is_first_post' => 0,
            'status' => $status,
            'format' => self::FORMAT,
            'body' => $body,
            'body_html' => null,
            'rendered_version' => 0,
            'quote_of_post_id' => $quoteOf > 0 ? $quoteOf : null,
            'ip_hash' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($status === 'published') {
            $this->topics->bumpCounters($topicId, 1, $now, $postId, $userId, $now);
            if ($topic['status'] === 'published') {
                $this->forums->bumpCounters($forumId, 0, 1, $now, $postId, $topicId, $userId, $now);
            }
            $this->stats->bump($userId, 0, 1, $now);
        }
        $this->stats->touchLastPost($userId, $now);
        $this->readStates->advance($userId, $topicId, $conflict ? $seen : $postId, $now);

        $position = null;
        if ($status === 'published') {
            // Das Thema ist gesperrt: der neue Beitrag ist der letzte veröffentlichte. Ohne veröffentlichten Themenstart zählt die Datenbank.
            $position = $topic['status'] === 'published' ? (int) $topic['reply_count'] + 2 : $this->posts->positionOf($topicId, $postId);
        }

        return [
            'success' => true,
            'post_id' => $postId,
            'topic_id' => $topicId,
            'forum_id' => $forumId,
            'status' => $status,
            'position' => $position,
            'page' => $position === null ? null : $this->pageFor($position),
        ];
    }

    // ── Bearbeiten ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Beitrag bearbeiten. Die ersetzte Fassung geht in den Verlauf (`community_post_revisions`, beim ersten Beitrag mit dem
     * alten Titel), `edit_count` steigt atomar. `$title` gilt nur für den ersten Beitrag (neuer Titel und neuer Slug des
     * Themas; sonst `invalid_input`). Ändert sich weder Text noch Titel, bleibt alles unverändert (`changed = false`, keine
     * Revision, kein Hook). `$expectedEditCount`: der Stand, den der Bearbeiter beim Öffnen sah; weicht er ab, ist
     * `error = edit_conflict` (mit dem aktuellen `edit_count`).
     *
     * @return array<string, mixed> bei Erfolg `post_id`, `topic_id`, `forum_id`, `changed`, `revision_id`, `edit_count`, `title_changed`, `slug`
     */
    public function edit(int $userId, int $postId, string $body, ?string $title = null, string $reason = '', ?int $expectedEditCount = null): array
    {
        if ($postId < 1) {
            return self::failure('invalid_input');
        }
        $body = self::normalizeBody($body);
        if ($body === null) {
            return self::failure('invalid_input');
        }
        $error = $this->bodyError($body);
        if ($error !== null) {
            return self::failure($error);
        }
        if ($title !== null) {
            $title = TopicService::normalizeTitle($title);
            if ($title === null) {
                return self::failure('invalid_input');
            }
            $length = mb_strlen($title, 'UTF-8');
            if ($length < $this->settings->int('title_min')) {
                return self::failure('title_too_short');
            }
            if ($length > $this->settings->int('title_max')) {
                return self::failure('title_too_long');
            }
        }
        $reasonText = self::normalizeReason($reason);

        $nowTs = ($this->clock)();
        $decision = $this->decide($userId, 'edit', ['post_id' => $postId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        if ($userId < 1) {
            return self::failure('invalid_input');
        }
        $now = gmdate('Y-m-d H:i:s', $nowTs);

        try {
            $result = null;
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS && $result === null; $attempt++) {
                $post = $this->posts->find($postId);
                if ($post === null) {
                    return self::failure('post_not_found');
                }
                if ($title !== null && (int) $post['is_first_post'] !== 1) {
                    return self::failure('invalid_input');
                }
                $result = Transaction::run($this->db, fn (): ?array => $this->editLocked(
                    $userId, $postId, (int) $post['topic_id'], (int) $post['forum_id'], $body, $title, $reasonText, $expectedEditCount, $nowTs, $now
                ));
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('post.edit', $e, ['post_id' => $postId]);
        }
        if ($result === null) {
            return $this->databaseError('post.edit', new \RuntimeException('Beitrag wechselte wiederholt Thema oder Forum.'), ['post_id' => $postId]);
        }
        if (!empty($result['success']) && !empty($result['changed'])) {
            $this->fire(Events::POST_UPDATED, $postId, (int) $result['topic_id'], $userId, (int) $result['revision_id']);
            if ($result['changed_topic_fields'] !== []) {
                $this->fire(Events::TOPIC_UPDATED, (int) $result['topic_id'], (int) $result['forum_id'], $userId, $result['changed_topic_fields']);
            }
        }
        unset($result['changed_topic_fields']);

        return $result;
    }

    /**
     * @return array<string, mixed>|null null: Beitrag oder Thema wechselte Thema/Forum, der Aufrufer beginnt von vorn
     */
    private function editLocked(int $userId, int $postId, int $topicId, int $forumId, string $body, ?string $title, ?string $reason, ?int $expectedEditCount, int $nowTs, string $now): ?array
    {
        // Sperrreihenfolge: Forum (nur wenn der Titel geändert werden kann, die Forum-Version steigt) → Thema → Beitrag.
        if ($title !== null && $this->forums->lockForUpdate($forumId) === null) {
            return self::failure('forum_not_found');
        }
        $topic = $this->topics->lockForUpdate($topicId);
        if ($topic === null) {
            return self::failure('topic_not_found');
        }
        $post = $this->posts->lockForUpdate($postId);
        if ($post === null) {
            return self::failure('post_not_found');
        }
        if ((int) $post['topic_id'] !== $topicId || (int) $topic['forum_id'] !== $forumId) {
            return null;
        }

        $decision = $this->decide($userId, 'edit', ['post_id' => $postId, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        if (in_array((string) $post['status'], ['deleted', 'rejected'], true)) {
            return self::failure('post_not_editable');
        }
        $editCount = (int) $post['edit_count'];
        if ($expectedEditCount !== null && $expectedEditCount !== $editCount) {
            return ['success' => false, 'error' => 'edit_conflict', 'edit_count' => $editCount];
        }

        $first = (int) $post['is_first_post'] === 1;
        $titleChanged = $title !== null && $title !== (string) $topic['title'];
        if ($body === (string) ($post['body'] ?? '') && !$titleChanged) {
            return [
                'success' => true,
                'changed' => false,
                'post_id' => $postId,
                'topic_id' => $topicId,
                'forum_id' => $forumId,
                'revision_id' => null,
                'edit_count' => $editCount,
                'title_changed' => false,
                'slug' => $first ? (string) $topic['slug'] : null,
            ];
        }

        $revisionId = $this->posts->revisionInsert([
            'post_id' => $postId,
            'title' => $first ? (string) $topic['title'] : null,
            'body' => (string) ($post['body'] ?? ''),
            'format' => (string) ($post['format'] ?? self::FORMAT),
            'edited_by' => $userId,
            'edit_reason' => $reason,
            'created_at' => $now,
        ]);
        $this->posts->applyEdit($postId, $body, $userId, $reason, $now);

        $changedFields = [];
        $slug = $first ? (string) $topic['slug'] : null;
        if ($titleChanged) {
            $newSlug = Slugger::topicSlug((string) $title);
            $this->topics->update($topicId, ['title' => $title, 'slug' => $newSlug, 'updated_at' => $now]);
            $this->forums->applyCounters($forumId, 0, 0, $now);
            $changedFields = $newSlug !== $slug ? ['title', 'slug'] : ['title'];
            $slug = $newSlug;
        }

        return [
            'success' => true,
            'changed' => true,
            'post_id' => $postId,
            'topic_id' => $topicId,
            'forum_id' => $forumId,
            'revision_id' => $revisionId,
            'edit_count' => $editCount + 1,
            'title_changed' => $titleChanged,
            'slug' => $slug,
            'changed_topic_fields' => $changedFields,
        ];
    }

    // ── Löschen ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Beitrag weich löschen (`status = deleted`, `status_reason` = `author` oder `moderator`; der Text bleibt für Wiederherstellung
     * und Verlauf erhalten). Der erste Beitrag wird abgelehnt (`first_post`): das Thema löscht `TopicService::delete()`.
     * Wiederholtes Löschen ist wirkungslos (`already_deleted = true`). War der Beitrag veröffentlicht, sinken Antwortzähler
     * des Themas, Beitragszähler von Forum und Autor; „letzter Beitrag“ von Thema und Forum werden neu bestimmt, falls sie
     * betroffen waren.
     *
     * @return array<string, mixed> bei Erfolg `post_id`, `topic_id`, `forum_id`, `previous_status`, `mode`
     */
    public function delete(int $userId, int $postId): array
    {
        if ($postId < 1) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        $decision = $this->decide($userId, 'delete', ['post_id' => $postId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        if ($userId < 1) {
            return self::failure('invalid_input');
        }
        $now = gmdate('Y-m-d H:i:s', $nowTs);

        try {
            $result = null;
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS && $result === null; $attempt++) {
                $post = $this->posts->find($postId);
                if ($post === null) {
                    return self::failure('post_not_found');
                }
                if ((int) $post['is_first_post'] === 1) {
                    return self::failure('first_post');
                }
                $result = Transaction::run($this->db, fn (): ?array => $this->deleteLocked(
                    $userId, $postId, (int) $post['topic_id'], (int) $post['forum_id'], $nowTs, $now
                ));
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('post.delete', $e, ['post_id' => $postId]);
        }
        if ($result === null) {
            return $this->databaseError('post.delete', new \RuntimeException('Beitrag wechselte wiederholt Thema oder Forum.'), ['post_id' => $postId]);
        }
        if (!empty($result['success']) && empty($result['already_deleted'])) {
            $this->fire(Events::POST_DELETED, $postId, (int) $result['topic_id'], $userId, (string) $result['mode']);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null null: Beitrag oder Thema wechselte Thema/Forum, der Aufrufer beginnt von vorn
     */
    private function deleteLocked(int $userId, int $postId, int $topicId, int $forumId, int $nowTs, string $now): ?array
    {
        // Sperrreihenfolge: Forum → Thema → Beitrag → Mitglied (der Autor, dessen Zähler sinkt).
        $forum = $this->forums->lockForUpdate($forumId);
        if ($forum === null) {
            return self::failure('forum_not_found');
        }
        $topic = $this->topics->lockForUpdate($topicId);
        if ($topic === null) {
            return self::failure('topic_not_found');
        }
        $post = $this->posts->lockForUpdate($postId);
        if ($post === null) {
            return self::failure('post_not_found');
        }
        if ((int) $post['topic_id'] !== $topicId || (int) $topic['forum_id'] !== $forumId) {
            return null;
        }
        $authorId = $post['user_id'] === null ? null : (int) $post['user_id'];
        if ($authorId !== null) {
            $this->stats->lockForUpdate($authorId, $now);
        }

        $decision = $this->decide($userId, 'delete', ['post_id' => $postId, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        if ((int) $post['is_first_post'] === 1) {
            return self::failure('first_post');
        }
        $previous = (string) $post['status'];
        $done = ['success' => true, 'post_id' => $postId, 'topic_id' => $topicId, 'forum_id' => $forumId, 'previous_status' => $previous, 'mode' => 'deleted'];
        if ($previous === 'deleted') {
            return $done + ['already_deleted' => true];
        }

        $this->posts->update($postId, ['status' => 'deleted', 'status_reason' => $authorId === $userId ? 'author' : 'moderator', 'updated_at' => $now]);
        if ($previous !== 'published') {
            return $done;
        }

        // Thema: Antwortzähler und „letzter Beitrag“ (falls dieser Beitrag es war; der Status ist schon geändert).
        $lastOfTopic = null;
        if ((int) ($topic['last_post_id'] ?? 0) === $postId) {
            $latest = $this->posts->lastPublishedInTopic($topicId);
            $lastOfTopic = ['post_id' => $latest['id'] ?? null, 'post_at' => $latest['created_at'] ?? null, 'poster_id' => $latest['user_id'] ?? null];
        }
        $this->topics->applyCounters($topicId, -1, $now, $lastOfTopic);

        // Forum: nur Themen, die im Forum zählen (veröffentlicht), tragen zu den Forumzählern bei.
        if ($topic['status'] === 'published') {
            $lastOfForum = null;
            if ((int) ($forum['last_post_id'] ?? 0) === $postId) {
                $latest = $this->topics->lastPublished($forumId);
                $lastOfForum = [
                    'post_id' => $latest['last_post_id'] ?? null,
                    'post_at' => $latest['last_post_at'] ?? null,
                    'topic_id' => $latest['id'] ?? null,
                    'poster_id' => $latest['last_poster_id'] ?? null,
                ];
            }
            $this->forums->applyCounters($forumId, 0, -1, $now, $lastOfForum);
        }
        if ($authorId !== null) {
            $this->stats->bump($authorId, 0, -1, $now);
        }

        return $done;
    }

    // ── Lesen ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Eine Seite der Beiträge eines Themas (Keyset nach ID): bis zu `posts_per_page` veröffentlichte Beiträge nach `$afterId`
     * (ausschließlich), dazwischen die Platzhalter ausgeblendeter und gelöschter Beiträge. Veröffentlichte Beiträge kommen
     * aus einer Abfrage mit Gleichheit auf den Status, Platzhalter aus einer zweiten über den ID-Bereich der Seite (nach
     * dem letzten Beitrag der Seite bis zur nächsten Seite; auf der letzten Seite bis zum Ende).
     *
     * Jeder Beitrag trägt `view`: `full` (mit Text) oder `placeholder` (ohne Text, nur Status und Grund), entschieden
     * über `can('read', Beitrag)`; Autor und Moderation sehen ausgeblendete Beiträge voll.
     *
     * @return array{success: bool, error?: string, retry_after?: int, items?: list<array<string, mixed>>, next?: int|null, has_more?: bool}
     */
    public function listPage(?int $viewerId, int $topicId, ?int $afterId = null, ?int $limit = null): array
    {
        if ($topicId < 1) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        $viewer = $viewerId !== null && $viewerId > 0 ? $viewerId : null;
        $decision = ($this->can)($viewer, 'read', ['topic_id' => $topicId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        $after = max(0, (int) $afterId);
        $limit = max(1, min(PostRepository::MAX_LIMIT, $limit ?? $this->settings->int('posts_per_page')));

        try {
            $page = $this->posts->listPublished($topicId, $after, $limit);
            // Platzhalter zwischen dem Ende der vorigen und dem Ende dieser Seite; die letzte Seite reicht bis zum Ende.
            $to = $page->next === null ? PHP_INT_MAX : (int) $page->next;
            $rows = array_merge($page->items, $this->posts->placeholdersInRange($topicId, $after + 1, $to));
            usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

            $items = [];
            foreach ($rows as $row) {
                $items[] = $this->viewItem($viewer, $row, $nowTs);
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('post.list', $e, ['topic_id' => $topicId]);
        }

        return ['success' => true, 'items' => $items, 'next' => $page->next === null ? null : (int) $page->next, 'has_more' => $page->next !== null];
    }

    /**
     * Stelle eines Beitrags unter den veröffentlichten des Themas (1 = Themenstart). Keine Rechteprüfung: nur Zahlen für
     * lesbare Themen weitergeben.
     */
    public function positionOf(int $topicId, int $postId): int
    {
        return $this->posts->positionOf($topicId, $postId);
    }

    /** Seite eines Beitrags: `ceil(Position / posts_per_page)`, mindestens 1. */
    public function pageOf(int $topicId, int $postId): int
    {
        return $this->pageFor($this->posts->positionOf($topicId, $postId));
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Liest das Thema ohne Sperre (für die Forum-ID) und führt `$work` in einer Transaktion aus; liefert `$work` null (das
     * Thema hat das Forum gewechselt), beginnt es von vorn.
     *
     * @param \Closure(array<string, mixed>): ?array<string, mixed> $work
     * @return array<string, mixed>
     */
    private function retrying(int $topicId, \Closure $work): array
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $topic = $this->topics->find($topicId);
            if ($topic === null) {
                return self::failure('topic_not_found');
            }
            $result = Transaction::run($this->db, static fn (): ?array => $work($topic));
            if ($result !== null) {
                return $result;
            }
        }

        throw new \RuntimeException('Thema wechselte wiederholt das Forum.');
    }

    /**
     * @param array<string, mixed> $row Beitragszeile oder Platzhalterzeile
     * @return array<string, mixed>
     */
    private function viewItem(?int $viewer, array $row, int $nowTs): array
    {
        $decision = ($this->can)($viewer, 'read', ['post_id' => (int) $row['id'], 'post' => $row, 'now' => $nowTs]);
        $full = $decision->allowed && $decision->view !== 'placeholder';
        if ($full && !array_key_exists('body', $row)) {
            // Platzhalterzeilen haben keinen Text: wer den Inhalt sehen darf (Autor, Moderation), lädt ihn.
            $row = $this->posts->find((int) $row['id']) ?? $row;
        }
        if (!$full) {
            return [
                'view' => 'placeholder',
                'id' => (int) $row['id'],
                'topic_id' => (int) $row['topic_id'],
                'forum_id' => (int) $row['forum_id'],
                'is_first_post' => (int) $row['is_first_post'],
                'status' => (string) $row['status'],
                'status_reason' => $row['status_reason'] ?? null,
                'created_at' => (string) $row['created_at'],
            ];
        }
        $item = array_diff_key($row, array_flip(self::HIDDEN_COLUMNS));
        foreach (self::INT_COLUMNS as $column) {
            if (array_key_exists($column, $item) && $item[$column] !== null) {
                $item[$column] = (int) $item[$column];
            }
        }
        $item['view'] = 'full';

        return $item;
    }

    private function pageFor(int $position): int
    {
        return max(1, (int) ceil($position / $this->settings->int('posts_per_page')));
    }

    /** Fehlercode zur Länge des Textes (`post_min`, `post_max`); leer zählt als zu kurz. */
    private function bodyError(string $body): ?string
    {
        $length = mb_strlen($body, 'UTF-8');
        if ($length < $this->settings->int('post_min')) {
            return 'body_too_short';
        }

        return $length > $this->settings->int('post_max') ? 'body_too_long' : null;
    }

    /**
     * Doppelt abgesendeter Beitrag: dasselbe Ergebnis wie beim ersten Mal.
     *
     * @param array{id: int, topic_id: int, status: string, is_first_post: int} $duplicate
     * @return array<string, mixed>
     */
    private function duplicateResult(array $duplicate, int $forumId, string $topicStatus): array
    {
        $position = null;
        if ($duplicate['status'] === 'published') {
            $position = $this->posts->positionOf($duplicate['topic_id'], $duplicate['id']);
        }

        return [
            'success' => true,
            'duplicate' => true,
            'post_id' => $duplicate['id'],
            'topic_id' => $duplicate['topic_id'],
            'forum_id' => $forumId,
            'status' => $duplicate['status'],
            'position' => $position,
            'page' => $position === null ? null : $this->pageFor($position),
        ];
    }

    /** Fehlercode, wenn ein Thema in einem Endzustand keine Antworten mehr annimmt (Schutz zusätzlich zu `can()`). */
    private static function closedTopicError(string $status): ?string
    {
        return match ($status) {
            'deleted', 'trash' => 'topic_deleted',
            'moved' => 'topic_moved',
            default => null,
        };
    }

    /** Anfangsstatus laut Entscheidung; ein unbekannter Wert gilt vorsichtshalber als wartend. */
    private static function initialStatus(AccessDecision $decision): string
    {
        return match ($decision->initialStatus) {
            null, 'published' => 'published',
            default => 'pending',
        };
    }

    /** @param array<string, mixed> $context */
    private function decide(int $userId, string $action, array $context): AccessDecision
    {
        return ($this->can)($userId > 0 ? $userId : null, $action, $context);
    }

    /** @return array{success: false, error: string, retry_after?: int} */
    private static function denied(AccessDecision $decision): array
    {
        $result = self::failure($decision->reason !== '' ? $decision->reason : 'no_permission');
        if ($decision->retryAfter !== null) {
            $result['retry_after'] = $decision->retryAfter;
        }

        return $result;
    }

    /** @return array{success: false, error: string} */
    private static function failure(string $error): array
    {
        return ['success' => false, 'error' => $error];
    }

    /**
     * @param array<string, int|string> $context
     * @return array{success: false, error: string}
     */
    private function databaseError(string $operation, \Throwable $e, array $context): array
    {
        Logger::instance()->withChannel('community')->warning('Community-Dienst fehlgeschlagen ({operation}): {exception}', $context + [
            'operation' => $operation,
            'exception' => $e,
        ]);

        return self::failure('db_error');
    }

    private function fire(string $hook, mixed ...$args): void
    {
        try {
            ($this->dispatch)($hook, ...$args);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('community')->warning('Community-Hook {hook} fehlgeschlagen: {exception}', [
                'hook' => $hook,
                'exception' => $e,
            ]);
        }
    }
}
