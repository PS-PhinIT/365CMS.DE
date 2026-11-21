<?php
/**
 * Einzige Zählerbuchung der Community bei Status- und Ortswechseln bestehender Inhalte (PR #2b; Vertrag DESIGN-2b §3.11, Entwurf
 * moderation §1.1 und §2.1). Neuanlage (`create`, `reply`) bucht weiter `bumpCounters()` in Topic- und PostService.
 *
 * Semantik S1: Ein Beitrag zählt im Forum und für sein Mitglied genau dann, wenn Beitrag und Thema `published` sind (`counts()`).
 * `reply_count` und `last_*` eines Themas zählen veröffentlichte Beiträge unabhängig vom Themenstatus. `last_*` von Thema und Forum
 * werden nach jedem Wechsel neu bestimmt; die Freigabe ist keine Aktivität (`last_post_at` = Anlagezeit des jüngsten veröffentlichten
 * Beitrags, ohne Beitrag die Anlagezeit des Themas).
 *
 * Regeln:
 * - Die Methoden arbeiten auf Zeilen, die der Aufrufer gesperrt und deren neuen Zustand er schon geschrieben hat (übergebene Zeilen =
 *   Zustand nach dem Schreiben). Keine Uhr, keine Transaktion, keine Hooks; gesperrt wird nur in `lock()`, angelegt nur in `prepare()`
 *   (Autocommit vor der Transaktion).
 * - Mitglieder bucht die Klasse nur über `UserStatsRepository::bumpLocked()`: kein INSERT unter Sperren, die Zeilen sind gesperrt und
 *   existieren. Ein Autor außerhalb der gesperrten Menge ist ein Programmfehler (`LogicException`, die Transaktion rollt zurück).
 * - Reihenfolge: Thema vor Forum (das Forum liest `last_*` der Themen), dann Mitglieder aufsteigend.
 * - `has_attachments` (es gibt einen veröffentlichten Beitrag mit Anhang im Thema) als Delta: eine Abfrage über die betroffenen Beiträge.
 *   Gewinnt das Thema einen solchen Beitrag, setzt die Klasse das Kennzeichen ohne weitere Abfrage; verliert es einen, bestimmt
 *   `topicHasPublishedAttachments()` es neu. Themenstatuswechsel ändern es nicht (Ausnahme: Freigabe des Themas mit dem ersten Beitrag).
 * - Reaktionen beim Verschieben von Beiträgen: `Σ reaction_count` der verschobenen Beiträge (alle Status) wandert von der Quelle zum Ziel.
 * - `solutions_count`: veröffentlichte Themen, deren Lösung vom Mitglied stammt.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class CounterBook
{
    private ForumRepository $forums;
    private TopicRepository $topics;
    private PostRepository $posts;
    private UserStatsRepository $stats;

    public function __construct(?Database $db = null)
    {
        $database = $db ?? Database::instance();
        $this->forums = new ForumRepository($database);
        $this->topics = new TopicRepository($database);
        $this->posts = new PostRepository($database);
        $this->stats = new UserStatsRepository($database);
    }

    /**
     * Zählt das Thema im Forum und für seine Mitglieder? (S1, einzige Stelle der Regel)
     *
     * @param array<string, mixed> $topic
     */
    public static function counts(array $topic): bool
    {
        return (string) $topic['status'] === 'published';
    }

    // ── Plan und Sperren ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Mitglieder, deren Statistikzeile eine Aktion am ganzen Thema sperren muss: Autoren veröffentlichter Beiträge und der
     * Themenstarter (ohne Konto entfällt er), aufsteigend. Lesen im Autocommit vor der Transaktion.
     *
     * @return list<int>
     */
    public function plan(int $topicId, ?int $starterId): array
    {
        $ids = array_keys($this->posts->publishedByAuthor($topicId)['users']);
        if ($starterId !== null && $starterId > 0) {
            $ids[] = $starterId;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * Legt fehlende Statistikzeilen an (`INSERT IGNORE`). Nur im Autocommit vor `Transaction::run()`: die Sperre in `lock()` trifft dann
     * vorhandene Zeilen (keine Lückensperre).
     *
     * @param list<int> $userIds
     */
    public function prepare(array $userIds, string $nowUtc): void
    {
        $this->stats->ensureMany($userIds, $nowUtc);
    }

    /**
     * Sperrt die Statistikzeilen (Ebene 4, aufsteigend, Stücke zu höchstens 100). Letzte Sperre der Transaktion.
     *
     * @param list<int> $userIds
     */
    public function lock(array $userIds, string $nowUtc): void
    {
        $this->stats->lockMany($userIds, $nowUtc);
    }

    /**
     * Unter den Sperren: sind alle Autoren veröffentlichter Beiträge gesperrt? `false` heißt, seit dem Plan hat jemand Neues
     * veröffentlicht; der Aufrufer beginnt von vorn.
     *
     * @param list<int> $lockedUserIds
     */
    public function covers(int $topicId, array $lockedUserIds): bool
    {
        $authors = array_keys($this->posts->publishedByAuthor($topicId)['users']);

        return array_diff(array_map('intval', $authors), array_map('intval', $lockedUserIds)) === [];
    }

    /**
     * Veröffentlichte Beiträge des Themas je Autor (`total` auch ohne Autor) und der Autor der Lösung.
     *
     * @param array<string, mixed> $topic
     * @return array{total: int, users: array<int, int>, solution_user: int|null}
     */
    public function topicAuthors(array $topic): array
    {
        $authors = $this->posts->publishedByAuthor((int) $topic['id']);
        $solutionUser = null;
        $solutionId = (int) ($topic['solution_post_id'] ?? 0);
        if ($solutionId > 0) {
            $solution = $this->posts->findMany([$solutionId])[$solutionId] ?? null;
            $solutionUser = $solution === null || $solution['user_id'] === null ? null : (int) $solution['user_id'];
        }

        return ['total' => $authors['total'], 'users' => $authors['users'], 'solution_user' => $solutionUser];
    }

    // ── Beiträge ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Eine Antwort wurde veröffentlicht (Freigabe, Wiederherstellen). Thema `reply_count + 1`; Forum und Autor nur, wenn das Thema zählt.
     * Gesperrt sein müssen Forum, Thema, Beitrag und (bei zählendem Thema) die Statistik des Autors.
     *
     * @param array<string, mixed> $forum
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $post Zustand nach dem Schreiben
     */
    public function postPublished(array $forum, array $topic, array $post, string $nowUtc): void
    {
        $this->refreshTopic($topic, (int) $post['is_first_post'] === 1 ? 0 : 1, $nowUtc);
        $this->gainAttachment($topic, $this->attached([(int) $post['id']]), $nowUtc);
        if (!self::counts($topic)) {
            return;
        }
        $this->refreshForum((int) $forum['id'], 0, 1, $nowUtc);
        if ($post['user_id'] !== null) {
            $this->bookMember((int) $post['user_id'], 0, 1, 0, $nowUtc);
        }
    }

    /**
     * Eine veröffentlichte Antwort verliert die Veröffentlichung (Ausblenden, Löschen). Ist sie die Lösung des Themas, wird die Lösung
     * aufgehoben (alle drei Spalten). Thema `reply_count − 1`; Forum und Autor (Beitrag und gegebenenfalls Lösung) nur bei zählendem Thema.
     *
     * @param array<string, mixed> $forum
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $post Zustand nach dem Schreiben
     * @return bool true = die Lösung wurde aufgehoben
     */
    public function postUnpublished(array $forum, array $topic, array $post, string $nowUtc): bool
    {
        $cleared = (int) ($topic['solution_post_id'] ?? 0) === (int) $post['id'];
        if ($cleared) {
            $this->clearSolution((int) $topic['id'], $nowUtc);
        }
        $this->refreshTopic($topic, (int) $post['is_first_post'] === 1 ? 0 : -1, $nowUtc);
        $this->loseAttachment($topic, $this->attached([(int) $post['id']]), $nowUtc);
        if (self::counts($topic)) {
            $this->refreshForum((int) $forum['id'], 0, -1, $nowUtc);
            if ($post['user_id'] !== null) {
                $this->bookMember((int) $post['user_id'], 0, -1, $cleared ? -1 : 0, $nowUtc);
            }
        }

        return $cleared;
    }

    // ── Themen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Ein Thema wurde veröffentlicht (Freigabe, Wiederherstellen). Thema nur `last_*`; Forum `topic_count + 1`, `post_count + total`;
     * Starter `topics + 1`, jeder Autor `posts + n`, Lösungsautor `solutions + 1`. `$publishedFirst`: der erste Beitrag, wenn er mit
     * der Freigabe veröffentlicht wurde (sein Anhang setzt `has_attachments`).
     *
     * @param array<string, mixed> $forum
     * @param array<string, mixed> $topic Zustand nach dem Schreiben (`published`)
     * @param list<int> $lockedUserIds gesperrte Statistikzeilen (Plan nach `covers()`)
     * @param array<string, mixed>|null $publishedFirst
     */
    public function topicPublished(array $forum, array $topic, array $lockedUserIds, string $nowUtc, ?array $publishedFirst = null): void
    {
        if (!self::counts($topic)) {
            throw new \LogicException('topicPublished: das Thema ist nicht veröffentlicht.');
        }
        $authors = $this->topicAuthors($topic);
        $this->refreshTopic($topic, 0, $nowUtc);
        if ($publishedFirst !== null) {
            $this->gainAttachment($topic, $this->attached([(int) $publishedFirst['id']]), $nowUtc);
        }
        $this->refreshForum((int) $forum['id'], 1, $authors['total'], $nowUtc);
        $this->bookAuthors($topic, $authors, 1, $lockedUserIds, $nowUtc);
    }

    /**
     * Ein veröffentlichtes Thema verliert die Veröffentlichung (Ausblenden, Löschen). Forum `topic_count − 1`, `post_count − total`;
     * Starter `topics − 1`, jeder Autor `posts − n`, Lösungsautor `solutions − 1`. Das Thema selbst ändert sich nicht.
     *
     * @param array<string, mixed> $forum
     * @param array<string, mixed> $topic Zustand nach dem Schreiben
     * @param list<int> $lockedUserIds
     */
    public function topicUnpublished(array $forum, array $topic, array $lockedUserIds, string $nowUtc): void
    {
        $authors = $this->topicAuthors($topic);
        $this->refreshForum((int) $forum['id'], -1, -$authors['total'], $nowUtc);
        $this->bookAuthors($topic, $authors, -1, $lockedUserIds, $nowUtc);
    }

    /**
     * Ein Thema hat das Forum gewechselt (`topic.forum_id` ist geschrieben). Zählt es, wandern Thema und `total` von der Quelle zum Ziel;
     * sonst ändern sich in beiden Foren nur `version` und `last_*`. Mitglieder bleiben unverändert.
     *
     * @param array<string, mixed> $fromForum
     * @param array<string, mixed> $toForum
     * @param array<string, mixed> $topic
     */
    public function topicMoved(array $fromForum, array $toForum, array $topic, string $nowUtc): void
    {
        $topics = self::counts($topic) ? 1 : 0;
        $total = $topics === 1 ? $this->posts->publishedByAuthor((int) $topic['id'])['total'] : 0;
        $this->refreshForum((int) $fromForum['id'], -$topics, -$total, $nowUtc);
        $this->refreshForum((int) $toForum['id'], $topics, $total, $nowUtc);
    }

    /**
     * Beiträge wurden von `$fromTopic` nach `$toTopic` verschoben (`topic_id` und `forum_id` der Beiträge sind geschrieben). Beide Themen
     * sind veröffentlicht (Vorbedingung von `movePosts`, sonst `LogicException`). Quelle `reply_count − k`, Ziel `+ k` (k = veröffentlichte
     * unter den verschobenen); verschiedene Foren `post_count ∓ k`; Reaktionen der verschobenen Beiträge wandern mit; `has_attachments`
     * beider Themen. Ist die Lösung der Quelle darunter, wird sie aufgehoben (Lösungsautor `solutions − 1`).
     *
     * @param array<string, mixed> $fromForum
     * @param array<string, mixed> $toForum
     * @param array<string, mixed> $fromTopic
     * @param array<string, mixed> $toTopic
     * @param list<array<string, mixed>> $posts gesperrte Zeilen der verschobenen Beiträge
     * @return bool true = die Lösung der Quelle wurde aufgehoben
     */
    public function postsMoved(array $fromForum, array $toForum, array $fromTopic, array $toTopic, array $posts, string $nowUtc): bool
    {
        if (!self::counts($fromTopic) || !self::counts($toTopic)) {
            throw new \LogicException('postsMoved: Quelle und Ziel müssen veröffentlicht sein.');
        }
        $published = array_values(array_filter($posts, static fn (array $p): bool => (string) $p['status'] === 'published'));
        $moved = count($published);
        $solutionId = (int) ($fromTopic['solution_post_id'] ?? 0);
        $solution = null;
        foreach ($posts as $post) {
            if ($solutionId > 0 && (int) $post['id'] === $solutionId) {
                $solution = $post;
            }
        }
        if ($solution !== null) {
            $this->clearSolution((int) $fromTopic['id'], $nowUtc);
        }
        $this->refreshTopic($fromTopic, -$moved, $nowUtc);
        $this->refreshTopic($toTopic, $moved, $nowUtc);
        $reactions = array_sum(array_map(static fn (array $p): int => (int) ($p['reaction_count'] ?? 0), $posts));
        if ($reactions > 0) {
            $this->topics->bumpReactions((int) $fromTopic['id'], -$reactions, $nowUtc);
            $this->topics->bumpReactions((int) $toTopic['id'], $reactions, $nowUtc);
        }
        $attached = $this->attached(array_map(static fn (array $p): int => (int) $p['id'], $published));
        $this->gainAttachment($toTopic, $attached, $nowUtc);
        $this->loseAttachment($fromTopic, $attached, $nowUtc);
        if ((int) $fromForum['id'] !== (int) $toForum['id']) {
            $this->refreshForum((int) $fromForum['id'], 0, -$moved, $nowUtc);
            $this->refreshForum((int) $toForum['id'], 0, $moved, $nowUtc);
        } else {
            $this->refreshForum((int) $fromForum['id'], 0, 0, $nowUtc);
        }
        if ($solution !== null && $solution['user_id'] !== null) {
            $this->bookMember((int) $solution['user_id'], 0, 0, -1, $nowUtc);
        }

        return $solution !== null;
    }

    /**
     * Die Lösung eines Themas wechselt (markieren, aufheben). Nur bei zählendem Thema: alter Lösungsautor `solutions − 1`, neuer `+ 1`.
     * Beide Zeilen sind gesperrt.
     *
     * @param array<string, mixed> $topic
     */
    public function solutionChanged(array $topic, ?int $oldUserId, ?int $newUserId, string $nowUtc): void
    {
        if (!self::counts($topic) || $oldUserId === $newUserId) {
            return;
        }
        if ($oldUserId !== null) {
            $this->bookMember($oldUserId, 0, 0, -1, $nowUtc);
        }
        if ($newUserId !== null) {
            $this->bookMember($newUserId, 0, 0, 1, $nowUtc);
        }
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Antwortzähler des Themas um `$replies` ändern und `last_*` neu bestimmen (ohne veröffentlichten Beitrag: Anlagezeit des Themas).
     *
     * @param array<string, mixed> $topic
     */
    private function refreshTopic(array $topic, int $replies, string $nowUtc): void
    {
        $last = $this->posts->lastPublishedInTopic((int) $topic['id']);
        $this->topics->applyCounters((int) $topic['id'], $replies, $nowUtc, [
            'post_id' => $last['id'] ?? null,
            'post_at' => $last['created_at'] ?? ($topic['created_at'] ?? null),
            'poster_id' => $last['user_id'] ?? null,
        ]);
    }

    /** Zähler des Forums ändern und `last_*` aus dem veröffentlichten Thema mit der jüngsten Aktivität neu bestimmen. */
    private function refreshForum(int $forumId, int $topics, int $posts, string $nowUtc): void
    {
        $latest = $this->topics->lastPublished($forumId);
        $this->forums->applyCounters($forumId, $topics, $posts, $nowUtc, [
            'post_id' => $latest['last_post_id'] ?? null,
            'post_at' => $latest['last_post_at'] ?? null,
            'topic_id' => $latest['id'] ?? null,
            'poster_id' => $latest['last_poster_id'] ?? null,
        ]);
    }

    /**
     * Bucht Starter, Autoren und Lösungsautor eines Themas mit Vorzeichen `$sign` (eine Schleife für Freigabe und Entzug), aufsteigend.
     *
     * @param array<string, mixed> $topic
     * @param array{total: int, users: array<int, int>, solution_user: int|null} $authors
     * @param list<int> $lockedUserIds
     */
    private function bookAuthors(array $topic, array $authors, int $sign, array $lockedUserIds, string $nowUtc): void
    {
        $starter = $topic['user_id'] === null ? null : (int) $topic['user_id'];
        $members = array_map('intval', array_keys($authors['users']));
        if ($starter !== null) {
            $members[] = $starter;
        }
        $members = array_values(array_unique($members));
        sort($members);
        $locked = array_flip(array_map('intval', $lockedUserIds));
        foreach ($members as $userId) {
            if (!isset($locked[$userId])) {
                throw new \LogicException('CounterBook: Statistikzeile von Mitglied ' . $userId . ' ist nicht gesperrt.');
            }
            $this->bookMember(
                $userId,
                $userId === $starter ? $sign : 0,
                $sign * ($authors['users'][$userId] ?? 0),
                $userId === $authors['solution_user'] ? $sign : 0,
                $nowUtc
            );
        }
    }

    /** Eine Statistikzeile buchen (gesperrt, vorhanden; ohne Änderung keine Anweisung). */
    private function bookMember(int $userId, int $topics, int $posts, int $solutions, string $nowUtc): void
    {
        if ($topics === 0 && $posts === 0 && $solutions === 0) {
            return;
        }
        $this->stats->bumpLocked($userId, $topics, $posts, $nowUtc, $solutions);
    }

    /** Lösung des Themas aufheben: `solution_post_id`, `solved_at` und `solved_by` gemeinsam NULL. */
    private function clearSolution(int $topicId, string $nowUtc): void
    {
        $this->topics->update($topicId, ['solution_post_id' => null, 'solved_at' => null, 'solved_by' => null, 'updated_at' => $nowUtc]);
    }

    /**
     * Hat einer der Beiträge einen Anhang? Eine Abfrage (`idx_community_attachments_post`); leere Liste ohne Abfrage.
     *
     * @param list<int> $postIds
     */
    private function attached(array $postIds): bool
    {
        return $postIds !== [] && $this->posts->withAttachments($postIds) !== [];
    }

    /**
     * Ein veröffentlichter Beitrag mit Anhang kam ins Thema: Kennzeichen setzen, ohne weitere Abfrage.
     *
     * @param array<string, mixed> $topic
     */
    private function gainAttachment(array $topic, bool $gained, string $nowUtc): void
    {
        if ($gained && (int) ($topic['has_attachments'] ?? 0) !== 1) {
            $this->topics->update((int) $topic['id'], ['has_attachments' => 1, 'updated_at' => $nowUtc]);
        }
    }

    /**
     * Ein veröffentlichter Beitrag mit Anhang verließ das Thema oder die Veröffentlichung: Kennzeichen neu bestimmen.
     *
     * @param array<string, mixed> $topic
     */
    private function loseAttachment(array $topic, bool $lostAttachment, string $nowUtc): void
    {
        if ($lostAttachment) {
            $has = $this->posts->topicHasPublishedAttachments((int) $topic['id']) ? 1 : 0;
            if ($has !== (int) ($topic['has_attachments'] ?? 0)) {
                $this->topics->update((int) $topic['id'], ['has_attachments' => $has, 'updated_at' => $nowUtc]);
            }
        }
    }
}
