<?php
/**
 * Papierkorb der Community-Themen (PR #5): auflisten, wiederherstellen, endgültig löschen, Fristablauf. Die Oberfläche (zentraler
 * Papierkorb des Core) erreicht den Dienst über `Community\CommunityContentProvider`; der Dienst prüft keine Rechte, das tun
 * `TrashService` (`manage_trash`) und die Capability des Providers vor jedem Aufruf.
 *
 * - Der Papierkorb zeigt alle Themen mit `status = deleted`: vom Autor oder von der Moderation gelöschte und abgelehnte (Thema
 *   `deleted` aus `pending`, erster Beitrag `rejected`). Der Status `trash` bleibt unbenutzt (Abweichung von WORKFLOWS: zwei Stufen
 *   wären eine zweite Aktion ohne Oberfläche). `TopicService::delete()` und die Ablehnung setzen `trashed_at`/`trashed_by`; Themen
 *   ohne Zeitstempel (vor diesem Stand gelöscht) bekommen ihn beim ersten Fristlauf (`expiredIds()`).
 * - `restore()` stellt `previous_status` wieder her (`published`, `hidden`, `pending`) und bucht bei `published` die Zähler als
 *   genaue Umkehrung des Löschens (`CounterBook::topicPublished()`, Semantik S1). Abgelehnte Themen sind nicht wiederherstellbar
 *   (`invalid_state`): die Moderation hat sie bewusst abgelehnt; die Freigabe bleibt ein Moderationsschritt. Sperrfolge wie die
 *   Freigabe: Forum → Thema → erster Beitrag → Statistik der Autoren (aufsteigend). Protokoll `topic.restore` (`from = deleted`).
 * - `purge()` löscht Beiträge, Revisionen, Reaktionen, Anhänge (Zeilen, danach Dateien), Meldungen, Abos, Lesemarken, Umfragen,
 *   Benachrichtigungsaufträge, Verweis-Themen und zuletzt das Thema. Je 100 Beiträge eine Transaktion (ein Thema kann Tausende
 *   Beiträge haben); bricht der Lauf ab, bleibt das Thema `deleted` mit den restlichen Beiträgen und der nächste Aufruf setzt
 *   fort. Die Reaktionszähler der Mitglieder (`reactions_received`/`reactions_given`) sinken mit; Forum- und Beitragszähler nicht
 *   (das Löschen hat sie schon abgezogen). Der Suchindex verliert die Beiträge sofort. Protokoll `topic.delete`; Hook
 *   `cms_community_topic_deleted` mit Modus `purged`.
 * - Kein Versatz in SQL: `list()` liest die geordneten IDs (höchstens `TrashRepository::LIST_MAX`) und schneidet die Seite in PHP.
 *
 * Ergebnisse: `{success, error?, …}` mit Codes aus `Support\Codes` (`topic_not_found`, `forum_not_found`, `invalid_state`, `db_error`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\ModerationLogRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\TrashRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

final class TopicTrashService
{
    /** Zustände, in die ein Thema zurückkehren darf (`previous_status`). */
    public const RESTORABLE = ['published', 'hidden', 'pending'];

    /** Größte Seite von `list()` (wie der Core-Papierkorb). */
    public const PAGE_MAX = 500;

    /** Versuche, wenn sich Forum oder Autorenkreis zwischen Lesen und Sperren ändern. */
    private const MAX_ATTEMPTS = 3;

    private static ?self $instance = null;

    private Database $db;
    /** @var \Closure(): int Unix-Zeit */
    private \Closure $clock;
    /** @var \Closure(string, mixed...): void */
    private \Closure $dispatch;
    /** @var \Closure(list<array<string, mixed>>): void Dateien gelöschter Anhänge entfernen */
    private \Closure $removeFiles;
    /** @var \Closure(list<int>): void Beiträge aus dem Suchindex nehmen */
    private \Closure $unindex;
    private ForumRepository $forums;
    private TopicRepository $topics;
    private PostRepository $posts;
    private UserStatsRepository $stats;
    private TrashRepository $trash;
    private ModerationLogRepository $log;
    private CounterBook $book;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(string, mixed...): void|null $dispatch Standard: `Events::fire()`
     * @param \Closure(list<array<string, mixed>>): void|null $removeFiles Standard: `AttachmentService::discardFiles()`
     * @param \Closure(list<int>): void|null $unindex Standard: `CommunitySearch::removePosts()`
     */
    public function __construct(?Database $db = null, ?\Closure $clock = null, ?\Closure $dispatch = null, ?\Closure $removeFiles = null, ?\Closure $unindex = null)
    {
        $this->db = $db ?? Database::instance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->dispatch = $dispatch ?? static function (string $hook, mixed ...$args): void {
            Events::fire($hook, ...$args);
        };
        $this->removeFiles = $removeFiles ?? static function (array $rows): void {
            AttachmentService::instance()->discardFiles($rows);
        };
        $this->unindex = $unindex ?? static function (array $postIds): void {
            CommunitySearch::instance()->removePosts($postIds);
        };
        $this->forums = new ForumRepository($this->db);
        $this->topics = new TopicRepository($this->db);
        $this->posts = new PostRepository($this->db);
        $this->stats = new UserStatsRepository($this->db);
        $this->trash = new TrashRepository($this->db);
        $this->log = new ModerationLogRepository($this->db);
        $this->book = new CounterBook($this->db);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Lesen ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** Gelöschte Themen, die zum Suchtext passen (Titel, Adresse). Wirft bei Datenbankfehlern. */
    public function count(string $search = ''): int
    {
        return $this->trash->countDeleted($search);
    }

    /**
     * Eine Seite des Papierkorbs, zuletzt gelöschte zuerst. Wirft bei Datenbankfehlern.
     *
     * @return list<array{id: int, title: string, forum_id: int, forum_title: string, previous_status: string, trashed_at: string, trashed_by: int|null, trashed_by_name: string, reply_count: int, rejected: bool, restorable: bool}>
     */
    public function list(int $skip, int $limit, string $search = ''): array
    {
        $skip = max(0, $skip);
        $limit = max(1, min(self::PAGE_MAX, $limit));
        $ids = array_slice($this->trash->deletedIds($skip + $limit, $search), $skip, $limit);
        if ($ids === []) {
            return [];
        }
        $topics = $this->trash->topicsByIds($ids);
        $firsts = $this->posts->findMany(array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['first_post_id'] ?? 0), $topics))));
        $forums = $this->trash->forumTitles(array_map(static fn (array $row): int => (int) $row['forum_id'], $topics));
        $names = $this->trash->userNames(array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['trashed_by'] ?? 0), $topics))));
        $items = [];
        foreach ($ids as $id) {
            $row = $topics[$id] ?? null;
            if ($row === null) {
                continue;
            }
            $previous = (string) ($row['previous_status'] ?? '');
            $first = $firsts[(int) ($row['first_post_id'] ?? 0)] ?? null;
            $rejected = self::isRejected($row, $first);
            $trashedBy = ($row['trashed_by'] ?? null) === null ? null : (int) $row['trashed_by'];
            $items[] = [
                'id' => $id,
                'title' => (string) $row['title'],
                'forum_id' => (int) $row['forum_id'],
                'forum_title' => $forums[(int) $row['forum_id']] ?? '',
                'previous_status' => $previous,
                'trashed_at' => (string) ($row['trashed_at'] ?? ''),
                'trashed_by' => $trashedBy,
                'trashed_by_name' => $trashedBy === null ? '' : ($names[$trashedBy] ?? ''),
                'reply_count' => (int) ($row['reply_count'] ?? 0),
                'rejected' => $rejected,
                'restorable' => !$rejected && in_array($previous, self::RESTORABLE, true),
            ];
        }

        return $items;
    }

    /**
     * Offene Meldungen zum Thema und seinen Beiträgen; solange es welche gibt, löscht der Papierkorb nicht ohne ausdrückliche
     * Bestätigung (und der Fristlauf gar nicht). Wirft bei Datenbankfehlern.
     */
    public function openReports(int $topicId): int
    {
        return $topicId < 1 ? 0 : $this->trash->openReports($topicId);
    }

    /** Titel des Themas, leer wenn es fehlt. Wirft bei Datenbankfehlern. */
    public function title(int $topicId): string
    {
        $topic = $topicId < 1 ? null : $this->topics->find($topicId);

        return $topic === null ? '' : (string) $topic['title'];
    }

    /**
     * IDs der Themen, die vor `$beforeUtc` in den Papierkorb kamen, älteste zuerst. Themen ohne Zeitstempel bekommen vorher den
     * aktuellen (die Frist beginnt erst, wenn der Papierkorb sie kennt). Wirft bei Datenbankfehlern.
     *
     * @return list<int>
     */
    public function expiredIds(string $beforeUtc, int $limit): array
    {
        $this->trash->stampMissing(gmdate('Y-m-d H:i:s', ($this->clock)()));

        return $this->trash->expiredIds($beforeUtc, $limit);
    }

    // ── Wiederherstellen ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Thema aus dem Papierkorb zurückholen. `$actorId` 0 = System (kein Konto im Protokoll).
     *
     * @return array<string, mixed> bei Erfolg `topic_id`, `forum_id`, `status`, `title`; `invalid_state` mit `reason = rejected` für abgelehnte Themen
     */
    public function restore(int $topicId, int $actorId): array
    {
        if ($topicId < 1) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        $now = gmdate('Y-m-d H:i:s', $nowTs);

        try {
            $result = null;
            $forumId = 0;
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS && $result === null; $attempt++) {
                $topic = $this->topics->find($topicId);
                if ($topic === null) {
                    return self::failure('topic_not_found');
                }
                if ((string) $topic['status'] !== 'deleted' || !in_array((string) ($topic['previous_status'] ?? ''), self::RESTORABLE, true)) {
                    return self::failure('invalid_state');
                }
                $firstId = (int) ($topic['first_post_id'] ?? 0);
                if (self::isRejected($topic, $firstId > 0 ? $this->posts->find($firstId) : null)) {
                    return self::failure('invalid_state') + ['reason' => 'rejected'];
                }
                $forumId = (int) $topic['forum_id'];
                $plan = $this->book->plan($topicId, $topic['user_id'] === null ? null : (int) $topic['user_id']);
                $this->book->prepare($plan, $now);
                $result = Transaction::run($this->db, fn (): ?array => $this->restoreLocked($topicId, $forumId, $actorId, $plan, $now));
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('trash.restore', $e, ['topic_id' => $topicId]);
        }
        if ($result === null) {
            return $this->databaseError('trash.restore', new \RuntimeException('Thema wechselte wiederholt Forum oder Autoren.'), ['topic_id' => $topicId]);
        }
        if (!empty($result['success'])) {
            $this->fire(Events::TOPIC_RESTORED, $topicId, $forumId, max(0, $actorId), (string) $result['status']);
        }

        return $result;
    }

    /**
     * @param list<int> $plan gesperrte Statistikzeilen (`CounterBook::plan()`)
     * @return array<string, mixed>|null null: Forum oder Autorenkreis haben sich geändert, der Aufrufer beginnt von vorn
     */
    private function restoreLocked(int $topicId, int $forumId, int $actorId, array $plan, string $now): ?array
    {
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
        $firstId = (int) ($topic['first_post_id'] ?? 0);
        $first = $firstId > 0 ? $this->posts->lockForUpdate($firstId) : null;
        $this->book->lock($plan, $now);
        if (!$this->book->covers($topicId, $plan)) {
            return null;
        }
        $previous = (string) ($topic['previous_status'] ?? '');
        if (self::isRejected($topic, $first)) {
            return self::failure('invalid_state') + ['reason' => 'rejected'];
        }
        if ((string) $topic['status'] !== 'deleted' || !in_array($previous, self::RESTORABLE, true)) {
            return self::failure('invalid_state');
        }

        $this->topics->update($topicId, ['status' => $previous, 'previous_status' => null, 'trashed_at' => null, 'trashed_by' => null, 'updated_at' => $now]);
        if ($previous === 'published') {
            $this->book->topicPublished($forum, ['status' => 'published'] + $topic, $plan, $now);
        }
        $this->log->add([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => 'topic.restore',
            'target_type' => 'topic',
            'target_id' => $topicId,
            'forum_id' => $forumId,
            'reason' => null,
            'details' => ['from' => 'deleted', 'to' => $previous],
            'created_at' => $now,
        ]);

        return ['success' => true, 'topic_id' => $topicId, 'forum_id' => $forumId, 'status' => $previous, 'title' => (string) $topic['title']];
    }

    // ── Endgültig löschen ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * Thema samt allem, was daran hängt, endgültig löschen. Nur Themen im Papierkorb (`deleted`). `$actorId` 0 = System (Fristlauf).
     *
     * @return array<string, mixed> bei Erfolg `topic_id`, `forum_id`, `title`, `removed` (Zeilen je Art)
     */
    public function purge(int $topicId, int $actorId): array
    {
        if ($topicId < 1) {
            return self::failure('invalid_input');
        }
        $now = gmdate('Y-m-d H:i:s', ($this->clock)());

        try {
            $topic = $this->topics->find($topicId);
            if ($topic === null) {
                return self::failure('topic_not_found');
            }
            if ((string) $topic['status'] !== 'deleted') {
                return self::failure('invalid_state');
            }
            $forumId = (int) $topic['forum_id'];
            $removed = [];
            $after = 0;
            while (($ids = $this->trash->postIdsAfter($topicId, $after, TrashRepository::CHUNK)) !== []) {
                $after = (int) max($ids);
                $chunk = $this->purgeChunk($topicId, $ids, $now);
                if ($chunk === null) {
                    return self::failure('invalid_state');
                }
                foreach ($chunk as $name => $count) {
                    $removed[$name] = ($removed[$name] ?? 0) + $count;
                }
            }
            $rest = Transaction::run($this->db, fn (): ?array => $this->purgeTopic($topicId, $forumId, $actorId, $now));
            if ($rest === null) {
                return self::failure('invalid_state');
            }
            foreach ($rest as $name => $count) {
                $removed[$name] = ($removed[$name] ?? 0) + $count;
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('trash.purge', $e, ['topic_id' => $topicId]);
        }
        $this->fire(Events::TOPIC_DELETED, $topicId, $forumId, max(0, $actorId), 'purged');

        return ['success' => true, 'topic_id' => $topicId, 'forum_id' => $forumId, 'title' => (string) $topic['title'], 'removed' => $removed];
    }

    /**
     * Ein Stück Beiträge: Statistik der Mitglieder (Reaktionen), Zeilen, danach Dateien und Suchindex. `null`: das Thema ist nicht mehr im
     * Papierkorb (ein gleichzeitiges Wiederherstellen oder Löschen war schneller).
     *
     * @param list<int> $postIds
     * @return array<string, int>|null
     */
    private function purgeChunk(int $topicId, array $postIds, string $now): ?array
    {
        $totals = $this->trash->reactionTotals($postIds);
        $members = array_values(array_unique([...array_keys($totals['received']), ...array_keys($totals['given'])]));
        sort($members);
        if ($members !== []) {
            $this->stats->ensureMany($members, $now);
        }
        $attachments = $this->trash->attachmentsOfPosts($postIds);
        $removed = Transaction::run($this->db, function () use ($topicId, $postIds, $members, $totals, $now): ?array {
            $topic = $this->topics->lockForUpdate($topicId);
            if ($topic === null || (string) $topic['status'] !== 'deleted') {
                return null;
            }
            if ($members !== []) {
                $this->stats->lockMany($members, $now);
                foreach ($members as $member) {
                    $this->stats->bumpReactions($member, -($totals['received'][$member] ?? 0), -($totals['given'][$member] ?? 0), $now);
                }
            }

            return $this->trash->deletePosts($postIds);
        });
        if ($removed === null) {
            return null;
        }
        if ($attachments !== []) {
            try {
                ($this->removeFiles)($attachments);
            } catch (\Throwable $e) {
                $this->warn('trash.files', $e, ['topic_id' => $topicId]);
            }
        }
        try {
            ($this->unindex)($postIds);
        } catch (\Throwable $e) {
            $this->warn('trash.unindex', $e, ['topic_id' => $topicId]);
        }

        return $removed;
    }

    /**
     * Letzte Transaktion: Forum → Thema, Daten des Themas, „letztes Thema“ des Forums (falls es das Thema war), Themenzeile, Protokoll.
     *
     * @return array<string, int>|null null: das Thema ist nicht mehr im Papierkorb
     */
    private function purgeTopic(int $topicId, int $forumId, int $actorId, string $now): ?array
    {
        $forum = $this->forums->lockForUpdate($forumId);
        $topic = $this->topics->lockForUpdate($topicId);
        if ($topic === null || (string) $topic['status'] !== 'deleted' || (int) $topic['forum_id'] !== $forumId) {
            return null;
        }
        $removed = $this->trash->deleteTopicData($topicId);
        if (!$this->trash->deleteTopic($topicId)) {
            return null;
        }
        $removed['topics'] = 1;
        if ($forum !== null && (int) ($forum['last_topic_id'] ?? 0) === $topicId) {
            $latest = $this->topics->lastPublished($forumId);
            $this->forums->applyCounters($forumId, 0, 0, $now, [
                'post_id' => $latest['last_post_id'] ?? null,
                'post_at' => $latest['last_post_at'] ?? null,
                'topic_id' => $latest['id'] ?? null,
                'poster_id' => $latest['last_poster_id'] ?? null,
            ]);
        }
        $this->log->add([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => 'topic.delete',
            'target_type' => 'topic',
            'target_id' => $topicId,
            'forum_id' => $forumId,
            'reason' => I18n::t('community.trash.purged'),
            'details' => ['from' => 'deleted'],
            'created_at' => $now,
        ]);

        return $removed;
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Abgelehnt: Thema `deleted` aus `pending`, erster Beitrag `rejected` (wie `ModerationService`).
     *
     * @param array<string, mixed> $topic
     * @param array<string, mixed>|null $first
     */
    private static function isRejected(array $topic, ?array $first): bool
    {
        return (string) ($topic['previous_status'] ?? '') === 'pending' && $first !== null && (string) $first['status'] === 'rejected';
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
        $this->warn($operation, $e, $context);

        return self::failure('db_error');
    }

    /** @param array<string, int|string> $context */
    private function warn(string $operation, \Throwable $e, array $context): void
    {
        Logger::instance()->withChannel('community')->warning('Community-Dienst fehlgeschlagen ({operation}): {exception}', $context + [
            'operation' => $operation,
            'exception' => $e,
        ]);
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
