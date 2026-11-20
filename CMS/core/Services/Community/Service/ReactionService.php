<?php
/**
 * Reaktionen auf Beiträge (PR #2b; Vertrag DESIGN-2b §3.6, §3.10, §3.11, §4.5; Entwurf interaktion §1.4, §2.5).
 *
 * Grundsätze:
 * - Eine Reaktion je Mitglied und Beitrag, änderbar; nur Arten aus der Einstellung `reaction_set` (`[]` = Reaktionen aus).
 *   `set` braucht `can('react')` (nicht auf eigene, nur auf veröffentlichte Beiträge in veröffentlichten Themen, auch für die
 *   Moderation), `remove` nur `can('read')` (Zurücknehmen auch bei Schreibsperre). Drossel `react` für beide.
 * - Sperrfolge (§3.10, K19): Thema → Beitrag → Statistik von Autor und Reagierendem (aufsteigend, Zeilen vorher im Autocommit) →
 *   Reaktionszeile. Danach `can(fresh)`. Ein Beitrag, der zwischen Lesen und Sperre das Thema oder den Autor wechselt, ergibt einen
 *   neuen Versuch (höchstens MAX_ATTEMPTS).
 * - Zähler (§3.11): `posts.reaction_count`, `topics.reaction_count` (`version + 1`, Gastcache), `reactions_received`/`reactions_given`,
 *   unabhängig vom Beitragsstatus; Verschieben bucht `CounterBook::postsMoved`.
 * - Lese-APIs prüfen selbst (§4.5, S17): `summaries()` nur für Einträge mit `view = full`, `who()` nur mit `allowed` und `view = full`.
 * - Hook `REACTION_CHANGED` nach dem Commit, nur bei Änderung (`''` = keine Reaktion vorher bzw. nachher).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\ReactionRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

final class ReactionService
{
    /** Darstellung der Schlüssel (Anzeige in PR #4); Katalog = `Settings::REACTION_KEYS`. */
    public const EMOJI = ['like' => '👍', 'love' => '❤️', 'laugh' => '😂', 'idea' => '💡', 'thanks' => '🙏', 'wow' => '😮', 'sad' => '😢', 'celebrate' => '🎉'];
    /** Beiträge je `summaries()`-Aufruf. */
    public const MAX_IDS = 100;
    /** Standard und Obergrenze von `who()`. */
    public const WHO_LIMIT = 50;
    public const WHO_MAX = 100;

    /** Versuche, wenn sich der Plan zwischen Lesen und Sperren ändert. */
    private const MAX_ATTEMPTS = 3;
    private const MAX_UINT = 4294967295;

    private static ?self $instance = null;

    private Database $db;
    private Settings $settings;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision */
    private \Closure $can;
    /** @var \Closure(string, mixed...): void */
    private \Closure $dispatch;
    /** @var \Closure(string, ?int): array{allowed: bool, retry_after: int} */
    private \Closure $throttle;
    private PostRepository $posts;
    private TopicRepository $topics;
    private UserStatsRepository $stats;
    private ReactionRepository $reactions;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard `CommunityAccess::instance()->can()`
     * @param \Closure(string, mixed...): void|null $dispatch Standard `Events::fire()`
     * @param \Closure(string, ?int): array{allowed: bool, retry_after: int}|null $throttle Standard `ContentGuard::instance()->throttle()`
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $clock = null,
        ?\Closure $can = null,
        ?\Closure $dispatch = null,
        ?\Closure $throttle = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->can = $can ?? static fn (?int $userId, string $action, array $context): AccessDecision => CommunityAccess::instance()->can($userId, $action, $context);
        $this->dispatch = $dispatch ?? static function (string $hook, mixed ...$args): void {
            Events::fire($hook, ...$args);
        };
        // Ohne ContentGuard (Einheit S) bleiben Reaktionen zu (fail-closed).
        $this->throttle = $throttle ?? static function (string $action, ?int $userId): array {
            return class_exists(ContentGuard::class)
                ? ContentGuard::instance()->throttle($action, $userId)
                : ['allowed' => false, 'retry_after' => 60];
        };
        $this->posts = new PostRepository($this->db);
        $this->topics = new TopicRepository($this->db);
        $this->stats = new UserStatsRepository($this->db);
        $this->reactions = new ReactionRepository($this->db);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** @return list<string> Einstellung `reaction_set` (Reihenfolge = Anzeige), nur Schlüssel aus dem Katalog */
    public function available(): array
    {
        return array_values(array_filter($this->settings->map('reaction_set'), static fn (mixed $key): bool => is_string($key) && isset(self::EMOJI[$key])));
    }

    /**
     * Reagieren oder die Art wechseln.
     *
     * @return array{success: bool, changed?: bool, reaction?: string, count?: int, error?: string, retry_after?: int}
     */
    public function set(int $userId, int $postId, string $reaction): array
    {
        if ($userId < 1 || $userId > self::MAX_UINT || $postId < 1) {
            return self::failure('invalid_input');
        }
        if (!in_array($reaction, $this->available(), true)) {
            return self::failure('reaction_unknown');
        }

        return $this->apply($userId, $postId, $reaction);
    }

    /**
     * Reaktion zurücknehmen (auch bei Schreibsperre).
     *
     * @return array{success: bool, changed?: bool, count?: int, error?: string, retry_after?: int}
     */
    public function remove(int $userId, int $postId): array
    {
        if ($userId < 1 || $userId > self::MAX_UINT || $postId < 1) {
            return self::failure('invalid_input');
        }

        return $this->apply($userId, $postId, null);
    }

    /**
     * Zähler je Art und eigene Reaktion für Einträge aus `PostService::listPage()`; nur Einträge mit `view = full` (Platzhalter und
     * fremde Einträge ohne Ansicht fehlen), höchstens MAX_IDS. Datenbankfehler ⇒ `[]`.
     *
     * @param list<array<string, mixed>> $items
     * @return array<int, array{counts: array<string, int>, mine: ?string}>
     */
    public function summaries(?int $viewerId, array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['view'] ?? null) !== 'full') {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0 && count($ids) < self::MAX_IDS) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        try {
            $counts = $this->reactions->counts(array_values($ids));
            $mine = $viewerId !== null && $viewerId > 0 ? $this->reactions->mine($viewerId, array_values($ids)) : [];
        } catch (\PDOException | \RuntimeException $e) {
            $this->warn('Community-Dienst fehlgeschlagen ({operation}): {exception}', ['operation' => 'reaction.summaries', 'exception' => $e]);

            return [];
        }
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = ['counts' => $counts[$id] ?? [], 'mine' => $mine[$id] ?? null];
        }

        return $map;
    }

    /**
     * Reagierende eines Beitrags (Cursor = Mitglieds-ID), nur mit Leserecht und voller Ansicht, sonst `not_found`.
     *
     * @return array{success: bool, items?: list<array{user_id: int, reaction: string}>, counts?: array<string, int>, next?: ?string, error?: string}
     */
    public function who(?int $viewerId, int $postId, ?string $after = null, int $limit = self::WHO_LIMIT): array
    {
        if ($postId < 1 || $limit < 1 || $limit > self::WHO_MAX || ($after !== null && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $after) !== 1)
            || ($after !== null && (int) $after > self::MAX_UINT)
        ) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        try {
            $decision = ($this->can)($viewerId, 'read', ['post_id' => $postId, 'now' => $nowTs]);
            if (!$decision->allowed || $decision->view !== 'full') {
                return self::failure('not_found');
            }
            $rows = $this->reactions->forPost($postId, $after === null ? 0 : (int) $after, $limit + 1);
            $counts = $this->reactions->counts([$postId])[$postId] ?? [];
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('reaction.who', $e, ['post_id' => $postId]);
        }
        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $next = (string) $rows[$limit - 1]['user_id'];
        }

        return [
            'success' => true,
            'items' => array_map(static fn (array $row): array => ['user_id' => $row['user_id'], 'reaction' => $row['reaction']], $rows),
            'counts' => $counts,
            'next' => $next,
        ];
    }

    /**
     * Gemeinsamer Ablauf von `set` (`$reaction` gesetzt) und `remove` (`null`).
     *
     * @return array<string, mixed>
     */
    private function apply(int $userId, int $postId, ?string $reaction): array
    {
        $action = $reaction === null ? 'read' : 'react';
        $nowTs = ($this->clock)();
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        try {
            $decision = ($this->can)($userId, $action, ['post_id' => $postId, 'now' => $nowTs]);
            if (!$decision->allowed) {
                return self::denied($decision);
            }
            $gate = ($this->throttle)('react', $userId);
            if (!$gate['allowed']) {
                return ['success' => false, 'error' => 'rate_limited', 'retry_after' => (int) $gate['retry_after']];
            }
            $result = null;
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS && $result === null; $attempt++) {
                // Plan im Autocommit: Thema und Autor bestimmen die Sperrziele, die Statistikzeilen entstehen vor BEGIN.
                $planned = $this->posts->find($postId);
                if ($planned === null) {
                    return self::failure('post_not_found');
                }
                $topicId = (int) $planned['topic_id'];
                $authorId = $planned['user_id'] === null ? null : (int) $planned['user_id'];
                $members = array_values(array_filter([$authorId, $userId], static fn (?int $id): bool => $id !== null && $id > 0));
                $this->stats->ensureMany($members, $now);
                $result = Transaction::run($this->db, fn (): ?array => $this->applyLocked($userId, $postId, $topicId, $authorId, $members, $reaction, $action, $nowTs, $now));
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('reaction.' . ($reaction === null ? 'remove' : 'set'), $e, ['post_id' => $postId]);
        }
        if ($result === null) {
            return self::failure('post_not_found');
        }
        if (empty($result['success'])) {
            return $result;
        }
        if ($result['changed']) {
            $this->fire(Events::REACTION_CHANGED, $postId, $userId, $reaction ?? '', (string) $result['previous']);
        }
        unset($result['previous']);

        return $result;
    }

    /**
     * Transaktion: Sperren (Thema, Beitrag, Statistik), Rechte frisch, Reaktionszeile, Zähler. `null` = Plan veraltet (neuer Versuch).
     *
     * @param list<int> $members
     * @return array<string, mixed>|null
     */
    private function applyLocked(int $userId, int $postId, int $topicId, ?int $authorId, array $members, ?string $reaction, string $action, int $nowTs, string $now): ?array
    {
        $topic = $this->topics->lockForUpdate($topicId);
        $post = $this->posts->lockForUpdate($postId);
        if ($topic === null || $post === null) {
            return $post === null ? ['success' => false, 'error' => 'post_not_found'] : null;
        }
        if ((int) $post['topic_id'] !== $topicId || ($post['user_id'] === null ? null : (int) $post['user_id']) !== $authorId) {
            return null;
        }
        $this->stats->lockMany($members, $now);

        foreach (PostRepository::PRIVATE_COLUMNS as $column) {
            unset($post[$column]);
        }
        $decision = ($this->can)($userId, $action, ['post' => $post, 'topic' => $topic, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        $previous = $this->reactions->find($postId, $userId);
        $delta = 0;
        $changed = false;
        if ($reaction === null) {
            if ($previous !== null) {
                $changed = $this->reactions->delete($postId, $userId);
                $delta = $changed ? -1 : 0;
            }
        } elseif ($previous === null) {
            $changed = $this->reactions->insert($postId, $userId, $reaction, $now);
            $delta = $changed ? 1 : 0;
        } elseif ($previous !== $reaction) {
            $changed = $this->reactions->change($postId, $userId, $reaction, $now);
        }
        if ($delta !== 0) {
            $this->posts->bumpReactions($postId, $delta);
            $this->topics->bumpReactions($topicId, $delta);
            if ($authorId !== null && $authorId > 0) {
                $this->stats->bumpReactions($authorId, $delta, 0, $now);
            }
            $this->stats->bumpReactions($userId, 0, $delta, $now);
        }
        $result = ['success' => true, 'changed' => $changed];
        if ($reaction !== null) {
            $result['reaction'] = $reaction;
        }

        return $result + ['count' => max(0, (int) $post['reaction_count'] + $delta), 'previous' => $previous ?? ''];
    }

    /** @return array<string, mixed> */
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
        $this->warn('Community-Dienst fehlgeschlagen ({operation}): {exception}', $context + ['operation' => $operation, 'exception' => $e]);

        return self::failure('db_error');
    }

    /** @param array<string, mixed> $context */
    private function warn(string $message, array $context): void
    {
        Logger::instance()->withChannel('community')->warning($message, $context);
    }

    private function fire(string $hook, mixed ...$args): void
    {
        try {
            ($this->dispatch)($hook, ...$args);
        } catch (\Throwable $e) {
            $this->warn('Community-Hook {hook} fehlgeschlagen: {exception}', ['hook' => $hook, 'exception' => $e]);
        }
    }
}
