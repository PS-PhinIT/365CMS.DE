<?php
/**
 * Themen der Community (PR #2a): anlegen (Thema und erster Beitrag), weich löschen, Themenliste eines Forums, Thema lesen.
 *
 * Grundsätze (Entwurfsvertrag PR #2a, §1; Karte §3.0, §3.1, §3.5, §3.6) wie bei `PostService`:
 * - `can()` vorab und unter den Sperren erneut mit `fresh = true`; Sperrreihenfolge Forum → Thema → Beitrag → Mitglied;
 *   Zähler nur atomar; Hooks nach dem Commit über `Events::fire()`; Zeit UTC (`gmdate`), Uhr je Aufruf einmal.
 * - `create()` schreibt Thema, ersten Beitrag, Zähler von Forum und Mitglied und den Lesestatus des Autors in einer
 *   Transaktion. Doppeltes Absenden (gleicher Titel und Text im selben Forum innerhalb von `duplicate_window_seconds`)
 *   liefert unter der Forum-Sperre dasselbe Ergebnis mit `duplicate = true`. `last_post_at` ist nie NULL (auch bei
 *   wartenden Themen), `last_post_id` und `last_poster_id` erst, wenn das Thema veröffentlicht ist.
 * - Wartende Themen zählen nirgends (Forum, Mitglied); die Freigabe in PR #2b bucht nach, einschließlich „letzter Beitrag“.
 * - `delete()` löscht weich (`status = deleted`, `previous_status`); die Beiträge des Themas bleiben unverändert. Zähler
 *   laut Matrix: Forum `topic_count − 1` und `post_count − (reply_count + 1)`, Themenstarter je −1; die Beitragszähler
 *   anderer Autoren werden nicht einzeln abgezogen (Reparatur-Job in PR #5).
 * - Ergebnisse sind Arrays `{success, error?, retry_after?, …}`; `error` ist ein Code aus `Support\Codes`.
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
use CMS\Services\Community\Support\Cursor;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Slugger;
use CMS\Services\Community\Support\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

final class TopicService
{
    /** Versuche, wenn ein Thema zwischen Lesen und Sperren das Forum wechselt. */
    private const MAX_ATTEMPTS = 3;

    /** Ganzzahlspalten einer Themenzeile. */
    private const INT_COLUMNS = [
        'id', 'forum_id', 'user_id', 'moved_to_topic_id', 'is_pinned', 'is_announcement', 'is_locked', 'slow_mode_seconds',
        'first_post_id', 'solution_post_id', 'solved_by', 'has_poll', 'has_attachments', 'reply_count', 'view_count',
        'reaction_count', 'last_post_id', 'last_poster_id', 'version', 'trashed_by',
    ];

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
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Reine Regeln ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Titel: Steuerzeichen entfernt, jede Folge von Leerraum (auch Tabulator, Zeilenumbruch, geschützte Leerzeichen) ein
     * Leerzeichen, Rand getrimmt; unsichtbare Zeichen, die Titel fälschen (Nullbreitenzeichen, Richtungsumschalter, BOM),
     * entfallen. `null` bei ungültigem UTF-8. Die Länge prüft der Aufrufer gegen `title_min`/`title_max`.
     */
    public static function normalizeTitle(string $title): ?string
    {
        if (!mb_check_encoding($title, 'UTF-8')) {
            return null;
        }
        $title = (string) preg_replace('/[\x{200B}\x{FEFF}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $title);
        $title = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $title);

        return trim((string) preg_replace('/\p{Cc}/u', '', $title));
    }

    // ── Anlegen ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Thema mit erstem Beitrag anlegen. `$options` ist für spätere Erweiterungen reserviert (PR #2b: Präfix, Umfrage,
     * Entwurf) und in PR #2a ohne Schlüssel.
     *
     * Ergebnis bei Erfolg: `topic_id`, `post_id` (erster Beitrag), `forum_id`, `status` (`published`/`pending`), `slug`;
     * bei doppeltem Absenden zusätzlich `duplicate = true` (nichts geschrieben, kein Hook). Fehler: `title_too_short`,
     * `title_too_long`, `body_too_short`, `body_too_long`, `invalid_input`, `forum_not_found`, Ablehnungsgründe aus
     * `can()` (mit `retry_after`) und `db_error`.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function create(int $userId, int $forumId, string $title, string $body, array $options = []): array
    {
        if ($forumId < 1) {
            return self::failure('invalid_input');
        }
        $title = self::normalizeTitle($title);
        $body = PostService::normalizeBody($body);
        if ($title === null || $body === null) {
            return self::failure('invalid_input');
        }
        $length = mb_strlen($title, 'UTF-8');
        if ($length < $this->settings->int('title_min')) {
            return self::failure('title_too_short');
        }
        if ($length > $this->settings->int('title_max')) {
            return self::failure('title_too_long');
        }
        $length = mb_strlen($body, 'UTF-8');
        if ($length < $this->settings->int('post_min')) {
            return self::failure('body_too_short');
        }
        if ($length > $this->settings->int('post_max')) {
            return self::failure('body_too_long');
        }

        $nowTs = ($this->clock)();
        $decision = $this->decide($userId, 'create_topic', ['forum_id' => $forumId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        if ($userId < 1) {
            return self::failure('invalid_input');
        }
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        $since = gmdate('Y-m-d H:i:s', $nowTs - $this->settings->int('duplicate_window_seconds'));

        try {
            if ($this->forums->find($forumId) === null) {
                return self::failure('forum_not_found');
            }
            $result = Transaction::run($this->db, fn (): array => $this->createLocked($userId, $forumId, $title, $body, $nowTs, $now, $since));
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('topic.create', $e, ['forum_id' => $forumId]);
        }
        if (!empty($result['success']) && empty($result['duplicate'])) {
            $this->fire(Events::TOPIC_CREATED, (int) $result['topic_id'], $forumId, $userId, (string) $result['status'], (int) $result['post_id']);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function createLocked(int $userId, int $forumId, string $title, string $body, int $nowTs, string $now, string $since): array
    {
        // Sperrreihenfolge: Forum → (Thema, Beitrag: neu) → Mitglied.
        if ($this->forums->lockForUpdate($forumId) === null) {
            return self::failure('forum_not_found');
        }
        // Doppeltes Absenden zuerst: wer ein zweites Mal absendet, bekommt das Ergebnis des ersten Mals, nicht „zu schnell“.
        $duplicate = $this->topics->findRecentDuplicate($userId, $forumId, $title, $since, $body);
        if ($duplicate !== null) {
            return [
                'success' => true,
                'duplicate' => true,
                'topic_id' => $duplicate['id'],
                'post_id' => (int) $duplicate['first_post_id'],
                'forum_id' => $forumId,
                'status' => $duplicate['status'],
                'slug' => Slugger::topicSlug($duplicate['title']),
            ];
        }

        $this->stats->lockForUpdate($userId, $now);
        $decision = $this->decide($userId, 'create_topic', ['forum_id' => $forumId, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        $status = match ($decision->initialStatus) {
            null, 'published' => 'published',
            default => 'pending',
        };
        $published = $status === 'published';
        $slug = Slugger::topicSlug($title);

        $topicId = $this->topics->insert([
            'forum_id' => $forumId,
            'user_id' => $userId,
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'last_post_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $postId = $this->posts->insert([
            'topic_id' => $topicId,
            'forum_id' => $forumId,
            'user_id' => $userId,
            'is_first_post' => 1,
            'status' => $status,
            'format' => PostService::FORMAT,
            'body' => $body,
            'body_html' => null,
            'rendered_version' => 0,
            'ip_hash' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->topics->update($topicId, $published
            ? ['first_post_id' => $postId, 'last_post_id' => $postId, 'last_poster_id' => $userId]
            : ['first_post_id' => $postId]);
        if ($published) {
            // Nur veröffentlichte Themen zählen; die Freigabe (PR #2b) bucht später nach.
            $this->forums->bumpCounters($forumId, 1, 1, $now, $postId, $topicId, $userId, $now);
            $this->stats->bump($userId, 1, 1, $now);
        }
        $this->stats->touchLastPost($userId, $now);
        $this->readStates->advance($userId, $topicId, $postId, $now);

        return ['success' => true, 'topic_id' => $topicId, 'post_id' => $postId, 'forum_id' => $forumId, 'status' => $status, 'slug' => $slug];
    }

    // ── Löschen ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Thema weich löschen (`status = deleted`, `previous_status` merkt den Vorwert). Der Autor darf nur ein Thema ohne
     * Antworten löschen (`has_replies`); die Moderation (`can('moderate')`) auch eines mit Antworten. War das Thema
     * veröffentlicht, sinken die Zähler laut Matrix und „letztes Thema/letzter Beitrag“ des Forums werden neu bestimmt,
     * falls das Thema es war. Wiederholtes Löschen ist wirkungslos (`already_deleted = true`).
     *
     * @return array<string, mixed> bei Erfolg `topic_id`, `forum_id`, `previous_status`, `mode`
     */
    public function delete(int $userId, int $topicId): array
    {
        if ($topicId < 1) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        $decision = $this->decide($userId, 'delete', ['topic_id' => $topicId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        if ($userId < 1) {
            return self::failure('invalid_input');
        }
        $now = gmdate('Y-m-d H:i:s', $nowTs);

        try {
            $result = null;
            $forumId = 0;
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS && $result === null; $attempt++) {
                $topic = $this->topics->find($topicId);
                if ($topic === null) {
                    return self::failure('topic_not_found');
                }
                $forumId = (int) $topic['forum_id'];
                $moderates = ($this->can)($userId, 'moderate', ['forum_id' => $forumId, 'now' => $nowTs])->allowed;
                if ((int) $topic['reply_count'] > 0 && !$moderates) {
                    return self::failure('has_replies');
                }
                $result = Transaction::run($this->db, fn (): ?array => $this->deleteLocked($userId, $topicId, $forumId, $moderates, $nowTs, $now));
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('topic.delete', $e, ['topic_id' => $topicId]);
        }
        if ($result === null) {
            return $this->databaseError('topic.delete', new \RuntimeException('Thema wechselte wiederholt das Forum.'), ['topic_id' => $topicId]);
        }
        if (!empty($result['success']) && empty($result['already_deleted'])) {
            $this->fire(Events::TOPIC_DELETED, $topicId, $forumId, $userId, (string) $result['mode']);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null null: das Thema hat das Forum gewechselt, der Aufrufer beginnt von vorn
     */
    private function deleteLocked(int $userId, int $topicId, int $forumId, bool $moderates, int $nowTs, string $now): ?array
    {
        // Sperrreihenfolge: Forum → Thema → Mitglied (der Themenstarter, dessen Zähler sinkt).
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
        $starterId = $topic['user_id'] === null ? null : (int) $topic['user_id'];
        if ($starterId !== null) {
            $this->stats->lockForUpdate($starterId, $now);
        }

        $decision = $this->decide($userId, 'delete', ['topic_id' => $topicId, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        $replies = (int) $topic['reply_count'];
        if ($replies > 0 && !$moderates) {
            return self::failure('has_replies');
        }
        $previous = (string) $topic['status'];
        $done = ['success' => true, 'topic_id' => $topicId, 'forum_id' => $forumId, 'previous_status' => $previous, 'mode' => 'deleted'];
        if ($previous === 'deleted') {
            return $done + ['already_deleted' => true];
        }

        $this->topics->update($topicId, ['status' => 'deleted', 'previous_status' => $previous, 'updated_at' => $now]);
        if ($previous !== 'published') {
            return $done;
        }

        // „Letztes Thema/letzter Beitrag“ des Forums neu bestimmen, wenn es dieses Thema war (der Status ist schon geändert).
        $last = null;
        if ((int) ($forum['last_topic_id'] ?? 0) === $topicId) {
            $latest = $this->topics->lastPublished($forumId);
            $last = [
                'post_id' => $latest['last_post_id'] ?? null,
                'post_at' => $latest['last_post_at'] ?? null,
                'topic_id' => $latest['id'] ?? null,
                'poster_id' => $latest['last_poster_id'] ?? null,
            ];
        }
        $this->forums->applyCounters($forumId, -1, -($replies + 1), $now, $last);
        if ($starterId !== null) {
            $this->stats->bump($starterId, -1, -1, $now);
        }

        return $done;
    }

    // ── Lesen ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Eine Seite der Themenliste eines Forums, jüngste Aktivität zuerst (Keyset, kein Zeilenversatz). Die erste Seite
     * (`$cursor` leer oder ungültig) beginnt mit den angepinnten Themen, danach der Strom mit `$limit` Themen (Standard
     * `topics_per_page`, höchstens 100); Folgeseiten enthalten nur den Strom. Lesbar ist nur, was `can('read', Forum)` erlaubt.
     *
     * @return array{success: bool, error?: string, retry_after?: int, items?: list<array<string, mixed>>, next?: string|null, has_more?: bool}
     */
    public function listForum(?int $viewerId, int $forumId, ?string $cursor = null, ?int $limit = null): array
    {
        if ($forumId < 1) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        $decision = ($this->can)($viewerId !== null && $viewerId > 0 ? $viewerId : null, 'read', ['forum_id' => $forumId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        $limit = max(1, min(TopicRepository::MAX_LIMIT, $limit ?? $this->settings->int('topics_per_page')));

        try {
            if ($this->forums->find($forumId) === null) {
                return self::failure('forum_not_found');
            }
            $page = $this->topics->listForum($forumId, gmdate('Y-m-d H:i:s', $nowTs), Cursor::decode($cursor), $limit);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('topic.list', $e, ['forum_id' => $forumId]);
        }

        return [
            'success' => true,
            'items' => array_map(static fn (array $row): array => self::typed($row), $page->items),
            'next' => $page->next,
            'has_more' => $page->next !== null,
        ];
    }

    /**
     * Thema lesen: die Zeile mit wirksamem Zustand (`is_pinned`/`is_locked` nach Ablauf von `pinned_until`/`locked_until`).
     * `null`, wenn es das Thema nicht gibt oder das Mitglied es nicht lesen darf (beides ununterscheidbar).
     *
     * @return array<string, mixed>|null
     */
    public function get(?int $viewerId, int $topicId): ?array
    {
        if ($topicId < 1) {
            return null;
        }
        $nowTs = ($this->clock)();
        $decision = ($this->can)($viewerId !== null && $viewerId > 0 ? $viewerId : null, 'read', ['topic_id' => $topicId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return null;
        }
        try {
            $row = $this->topics->find($topicId);
        } catch (\PDOException | \RuntimeException $e) {
            $this->databaseError('topic.get', $e, ['topic_id' => $topicId]);

            return null;
        }

        return $row === null ? null : self::typed(TopicRepository::withEffectiveState($row, gmdate('Y-m-d H:i:s', $nowTs)));
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function typed(array $row): array
    {
        foreach (self::INT_COLUMNS as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (int) $row[$column];
            }
        }

        return $row;
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
