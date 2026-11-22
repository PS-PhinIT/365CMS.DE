<?php
/**
 * Moderation der Community (PR #2b; Vertrag DESIGN-2b §3.10, §3.11, §4.4; Entwurf moderation §1.3, §2; Schnittstelle sanktionen §1.7):
 * Schließen, Anheften, Ankündigen, Umbenennen, Verschieben (Thema und Beiträge), Ausblenden, Wiederherstellen, Freigeben, Ablehnen,
 * Lösung, Sammelaktion, Warteschlange, Protokoll lesen, Ablauf befristeter Zustände (Cron), Systemaktionen der Meldeschwelle und `ip_hash`
 * für die Moderation.
 *
 * Grundsätze:
 * - Feste Prüfreihenfolge (Vertrag §1 Nr. 8): Eingaben ohne Datenbank → Uhr (genau einmal je Schreibmethode, `bulk()` einmal für alle)
 *   → Rechte-Tor (`can()` vorab bzw. `moderationScope()`) → erst danach fachliche Codes (`first_post`, `invalid_target`, `changed: false`).
 *   Nicht sichtbare Ziele ergeben dasselbe wie fehlende; fehlende oder gemischte Beiträge laufen beim Verschieben als Köder-ID durch `can()`.
 * - Ablauf jeder Schreibmethode: Ziel im Autocommit lesen, Plan bilden, Statistikzeilen des Plans anlegen; dann `Transaction::run()` mit
 *   allen Sperren in der Reihenfolge des Musters (Forum → Thema → Beitrag → Statistik, je Ebene aufsteigend), danach erst lesen:
 *   Planprüfung (Abweichung ⇒ `null`, neuer Versuch, höchstens drei) → `can(…, fresh)` mit den gesperrten Zeilen → Zustand und
 *   Idempotenz → Schreiben → `CounterBook` → `reassign*` der Meldungen → Protokoll als letzte Anweisung. Hooks nach dem Commit, nur bei
 *   `changed = true`, Akteur 0 bei Systemaktionen.
 * - `changed = false`: der Zustand bestand schon (kein Schreiben, kein Protokoll, kein Hook, Erfolg).
 * - Pflichtgrund (`REASON_REQUIRED`, Einstellung `moderation_reason_required`) vor Uhr und `can()`, ohne Datenbankanweisung.
 * - Lese-APIs geben weder `ip_hash` noch `body_html` noch Rohtext fremder Zitate aus (§1 Nr. 9): die Warteschlange läuft über
 *   `RenderService::present()`, ohne Renderer über `QuoteService::presentable()` mit „alles verborgen“ (fail-closed). `ip_hash` liest nur
 *   `ipHashes()` mit `community.view_private_data` und Moderationsbereich.
 * - Datenbankfehler und drei Planabweichungen ergeben `db_error` (Rücknahme, Warnung Kanal `community`). Programmfehler
 *   (`LogicException`, auch ein ungültiger Protokolleintrag) werden nicht gefangen.
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
use CMS\Services\Community\Repository\ReportRepository;
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

final class ModerationService
{
    /** Höchstzahl der Ziele je Sammelaktion und je Beitragsverschiebung. */
    public const MAX_BULK = 100;
    /** Aktionen mit Pflichtgrund, wenn `moderation_reason_required` an ist (Sperren verlangen den Grund immer, BanService). */
    public const REASON_REQUIRED = ['post.hide', 'post.reject', 'topic.hide', 'topic.reject'];
    /** Operationen der Sammelaktion. */
    public const BULK_OPERATIONS = [
        'post.approve', 'post.reject', 'post.hide', 'post.restore', 'post.delete', 'topic.approve', 'topic.reject',
        'topic.lock', 'topic.unlock', 'topic.pin', 'topic.unpin', 'topic.hide', 'topic.restore', 'topic.move', 'topic.delete',
    ];
    /** Operationen von `systemAction()` (Meldeschwelle, erledigte Meldung `invalid`). */
    public const SYSTEM_OPERATIONS = ['hide', 'restore'];
    /** Standardgröße der Warteschlange. */
    public const QUEUE_LIMIT = 20;
    /** Standardgröße einer Protokollseite. */
    public const LOG_LIMIT = 50;
    /** Längste Frist für Schließen und Anheften in Tagen. */
    public const MAX_HORIZON_DAYS = 3650;
    /** Längstes Präfix in Zeichen (`community_topics.prefix` VARCHAR(30)). */
    public const PREFIX_MAX = 30;
    /** Höchstzahl der Beiträge je Aufruf von `ipHashes()`. */
    public const MAX_IP_HASHES = 100;
    /** Standardgröße je Art und Lauf von `expire()`. */
    public const EXPIRE_LIMIT = 500;

    /** Versuche, wenn der gesperrte Zustand vom Plan abweicht. */
    private const MAX_ATTEMPTS = 3;
    /** `status_reason` und Detailwert automatisch ausgeblendeter Inhalte. */
    private const AUTO_REASON = 'reports';
    /** Detailwert automatisch wiederhergestellter Inhalte (Meldungen als ungültig erledigt). */
    private const AUTO_RESTORE = 'reports_invalid';
    /** Status, aus denen ein Thema verschoben werden kann. */
    private const MOVABLE_TOPIC = ['published', 'pending', 'hidden'];
    /** Status, aus denen Beiträge verschoben werden können. */
    private const MOVABLE_POST = ['published', 'pending', 'hidden'];
    /** Filter von `log()`. */
    private const LOG_FILTERS = ['forum_id', 'actor_id', 'action', 'target_type', 'target_id', 'since', 'until'];
    /** Ganzzahlspalten einer Beitragszeile der Warteschlange. */
    private const INT_COLUMNS = ['id', 'topic_id', 'forum_id', 'user_id', 'is_first_post', 'rendered_version', 'quote_of_post_id', 'edit_count', 'edited_by', 'reaction_count'];
    /** Größte ID einer INT-UNSIGNED-Spalte. */
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
    /** @var \Closure(?int, array<string, mixed>): array{global: bool, forum_ids: list<int>} */
    private \Closure $scope;
    private ?RenderService $render;
    private ForumRepository $forums;
    private TopicRepository $topics;
    private PostRepository $posts;
    private UserStatsRepository $stats;
    private ModerationLogRepository $log;
    private ReportRepository $reports;
    private CounterBook $book;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard `CommunityAccess::instance()->can()`; ohne Klasse alles abgelehnt
     * @param \Closure(string, mixed...): void|null $dispatch Standard `Events::fire()`
     * @param \Closure(?int, array<string, mixed>): array{global: bool, forum_ids: list<int>}|null $scope Standard `CommunityAccess::instance()->moderationScope()`
     * @param RenderService|null $render Anzeige der Warteschlange; `null`: `QuoteService::presentable()` mit „alles verborgen“
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $clock = null,
        ?\Closure $can = null,
        ?\Closure $dispatch = null,
        ?\Closure $scope = null,
        ?RenderService $render = null
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
        $this->scope = $scope ?? static function (?int $userId, array $context = []): array {
            return class_exists(CommunityAccess::class)
                ? CommunityAccess::instance()->moderationScope($userId, $context)
                : ['global' => false, 'forum_ids' => []];
        };
        $this->render = $render;
        $this->forums = new ForumRepository($this->db);
        $this->topics = new TopicRepository($this->db);
        $this->posts = new PostRepository($this->db);
        $this->stats = new UserStatsRepository($this->db);
        $this->log = new ModerationLogRepository($this->db);
        $this->reports = new ReportRepository($this->db);
        $this->book = new CounterBook($this->db);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(
            scope: static fn (?int $userId, array $context = []): array => CommunityAccess::instance()->moderationScope($userId, $context),
            render: RenderService::instance()
        );
    }

    // ── Themen: Schließen, Anheften, Ankündigen (Muster T) ──────────────────────────────────────────────────────

    /**
     * Thema schließen, unbefristet oder bis `$until` (Unix-Zeit, UTC; `now < until ≤ now + MAX_HORIZON_DAYS`).
     *
     * @return array<string, mixed> Erfolg: `topic_id`, `forum_id`, `locked_until`, `changed`, `log_id`
     */
    public function lock(int $actorId, int $topicId, ?int $until = null, string $reason = ''): array
    {
        return $this->flag('topic.lock', $actorId, $topicId, $until, $reason);
    }

    /** @return array<string, mixed> Erfolg: `topic_id`, `forum_id`, `changed`, `log_id` */
    public function unlock(int $actorId, int $topicId, string $reason = ''): array
    {
        return $this->flag('topic.unlock', $actorId, $topicId, null, $reason);
    }

    /**
     * Thema anheften (je Forum höchstens `TopicRepository::MAX_PINNED` wirksame Pins in `published|pending|hidden`, sonst `pin_limit`).
     *
     * @return array<string, mixed> Erfolg: `topic_id`, `forum_id`, `pinned_until`, `changed`, `log_id`
     */
    public function pin(int $actorId, int $topicId, ?int $until = null, string $reason = ''): array
    {
        return $this->flag('topic.pin', $actorId, $topicId, $until, $reason);
    }

    /** @return array<string, mixed> */
    public function unpin(int $actorId, int $topicId, string $reason = ''): array
    {
        return $this->flag('topic.unpin', $actorId, $topicId, null, $reason);
    }

    /** Ankündigung (nur globale Moderation, `can()`). @return array<string, mixed> */
    public function announce(int $actorId, int $topicId, string $reason = ''): array
    {
        return $this->flag('topic.announce', $actorId, $topicId, null, $reason);
    }

    /** @return array<string, mixed> */
    public function unannounce(int $actorId, int $topicId, string $reason = ''): array
    {
        return $this->flag('topic.unannounce', $actorId, $topicId, null, $reason);
    }

    /**
     * Titel und optional Präfix ändern (`$prefix`: `null` unverändert, `''` entfernen). Kein Verlaufseintrag, der Titelverlauf steht im
     * Protokoll; die Adresse löst über die ID auf.
     *
     * @return array<string, mixed> Erfolg: `topic_id`, `forum_id`, `title`, `slug`, `prefix`, `changed`, `log_id`
     */
    public function rename(int $actorId, int $topicId, string $title, ?string $prefix = null, string $reason = ''): array
    {
        if ($topicId < 1) {
            return self::failure('invalid_input');
        }
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
        $keepPrefix = $prefix === null;
        $newPrefix = null;
        if ($prefix !== null && $prefix !== '') {
            $newPrefix = TopicService::normalizeTitle($prefix);
            if ($newPrefix === null || $newPrefix === '' || mb_strlen($newPrefix, 'UTF-8') > self::PREFIX_MAX) {
                return self::failure('invalid_input');
            }
        }
        [$reasonText, $error] = $this->reasonFor('topic.rename', $reason);
        if ($error !== null) {
            return self::failure($error);
        }
        $nowTs = ($this->clock)();
        $decision = $this->decide($actorId, 'topic.rename', ['topic_id' => $topicId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }

        return $this->run('topic.rename', ['topic_id' => $topicId], function () use ($actorId, $topicId, $title, $keepPrefix, $newPrefix, $reasonText, $nowTs): ?array {
            $topic = $this->topics->find($topicId);
            if ($topic === null) {
                return self::failure('topic_not_found');
            }
            $forumId = (int) $topic['forum_id'];

            return Transaction::run($this->db, fn (): ?array => $this->renameLocked($actorId, $topicId, $forumId, $title, $keepPrefix, $newPrefix, $reasonText, $nowTs));
        });
    }

    // ── Themen: Verschieben (Muster M) ──────────────────────────────────────────────────────────────────────────

    /**
     * Thema in ein anderes Forum verschieben. `$stub`: Verweis-Thema im Quellforum (nur für veröffentlichte Themen, Ablauf
     * `moved_stub_days`). Ein volles Zielforum löst die Anheftung (`unpinned`). Wiederholtes Absenden ergibt `changed = false`.
     *
     * @return array<string, mixed> Erfolg: `topic_id`, `from_forum_id`, `to_forum_id`, `stub_topic_id`, `unpinned`, `changed`, `log_id`
     */
    public function moveTopic(int $actorId, int $topicId, int $targetForumId, bool $stub = true, string $reason = ''): array
    {
        if ($topicId < 1 || $targetForumId < 1) {
            return self::failure('invalid_input');
        }
        [$reasonText, $error] = $this->reasonFor('topic.move', $reason);
        if ($error !== null) {
            return self::failure($error);
        }

        return $this->moveTopicAt($actorId, $topicId, $targetForumId, $stub, $reasonText, ($this->clock)());
    }

    // ── Themen: Ausblenden, Wiederherstellen, Freigeben, Ablehnen (Muster TA) ───────────────────────────────────

    /** @return array<string, mixed> Erfolg: `topic_id`, `forum_id`, `status`, `changed`, `log_id` */
    public function hideTopic(int $actorId, int $topicId, string $reason = ''): array
    {
        return $this->topicStatus('topic.hide', $actorId, $topicId, $reason);
    }

    /** Nur aus `hidden`; ein nie freigegebenes Thema kehrt nach `pending` zurück. @return array<string, mixed> */
    public function restoreTopic(int $actorId, int $topicId, string $reason = ''): array
    {
        return $this->topicStatus('topic.restore', $actorId, $topicId, $reason);
    }

    /** Wartendes Thema mit wartendem erstem Beitrag freigeben. @return array<string, mixed> Erfolg zusätzlich `post_id` */
    public function approveTopic(int $actorId, int $topicId, string $reason = ''): array
    {
        return $this->topicStatus('topic.approve', $actorId, $topicId, $reason);
    }

    /** Wartendes Thema ablehnen: erster Beitrag `rejected`, Thema `deleted` (`previous_status` pending). @return array<string, mixed> */
    public function rejectTopic(int $actorId, int $topicId, string $reason = ''): array
    {
        return $this->topicStatus('topic.reject', $actorId, $topicId, $reason);
    }

    // ── Beiträge (Muster P) ─────────────────────────────────────────────────────────────────────────────────────

    /** Wartende Antwort freigeben (erster Beitrag: wie `approveTopic`). @return array<string, mixed> */
    public function approvePost(int $actorId, int $postId, string $reason = ''): array
    {
        return $this->postStatus('post.approve', $actorId, $postId, $reason);
    }

    /** Wartende Antwort ablehnen (erster Beitrag: wie `rejectTopic`). @return array<string, mixed> */
    public function rejectPost(int $actorId, int $postId, string $reason = ''): array
    {
        return $this->postStatus('post.reject', $actorId, $postId, $reason);
    }

    /** Veröffentlichte Antwort ausblenden; eine Lösung wird aufgehoben. @return array<string, mixed> Erfolg zusätzlich `solution_cleared` */
    public function hidePost(int $actorId, int $postId, string $reason = ''): array
    {
        return $this->postStatus('post.hide', $actorId, $postId, $reason);
    }

    /** Ausgeblendete oder von der Moderation gelöschte Antwort wiederherstellen. @return array<string, mixed> */
    public function restorePost(int $actorId, int $postId, string $reason = ''): array
    {
        return $this->postStatus('post.restore', $actorId, $postId, $reason);
    }

    // ── Beiträge verschieben (Muster PM, Vertrag §4.4) ──────────────────────────────────────────────────────────

    /**
     * Antworten eines Themas in ein anderes veröffentlichtes Thema verschieben (1 bis MAX_BULK IDs, eindeutig).
     *
     * @param list<int> $postIds
     * @return array<string, mixed> Erfolg: `from_topic_id`, `to_topic_id`, `moved`, `solution_cleared`, `changed`
     */
    public function movePosts(int $actorId, array $postIds, int $targetTopicId, string $reason = ''): array
    {
        if ($postIds === [] || $targetTopicId < 1) {
            return self::failure('invalid_input');
        }
        if (count($postIds) > self::MAX_BULK) {
            return self::failure('bulk_limit');
        }
        $ids = self::idList($postIds);
        if ($ids === null) {
            return self::failure('invalid_input');
        }
        [$reasonText, $error] = $this->reasonFor('post.move', $reason);
        if ($error !== null) {
            return self::failure($error);
        }
        $nowTs = ($this->clock)();
        sort($ids);

        try {
            $rows = $this->posts->findMany($ids);
            $target = $this->topics->find($targetTopicId);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('post.move', $e, ['topic_id' => $targetTopicId]);
        }
        $sources = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['topic_id'], $rows)));
        $mixed = count($rows) !== count($ids) || count($sources) !== 1;
        $sourceId = $sources[0] ?? 0;
        $sourceId = $mixed ? CommunityAccess::DECOY_ID : $sourceId;
        $targetForumId = $target === null ? CommunityAccess::DECOY_ID : (int) $target['forum_id'];
        $decision = $this->decide($actorId, 'post.move', ['topic_id' => $sourceId, 'target_forum_id' => $targetForumId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        foreach ($rows as $row) {
            if ((int) $row['is_first_post'] === 1) {
                return self::failure('first_post');
            }
        }
        if ($sourceId === $targetTopicId) {
            return self::unchanged(['from_topic_id' => $sourceId, 'to_topic_id' => $targetTopicId, 'moved' => [], 'solution_cleared' => false]);
        }

        return $this->run('post.move', ['topic_id' => $targetTopicId], function () use ($actorId, $ids, $targetTopicId, $reasonText, $nowTs): ?array {
            $rows = $this->posts->findMany($ids);
            $topicIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['topic_id'], $rows)));
            if (count($rows) !== count($ids) || count($topicIds) !== 1) {
                return self::failure('invalid_state');
            }
            $source = $this->topics->find($topicIds[0]);
            $target = $this->topics->find($targetTopicId);
            if ($source === null || $target === null) {
                return self::failure('topic_not_found');
            }
            if ((int) $source['id'] === $targetTopicId) {
                return self::unchanged(['from_topic_id' => $targetTopicId, 'to_topic_id' => $targetTopicId, 'moved' => [], 'solution_cleared' => false]);
            }
            $solutionPlan = [];
            $solutionId = (int) ($source['solution_post_id'] ?? 0);
            if ($solutionId > 0 && isset($rows[$solutionId]) && $rows[$solutionId]['user_id'] !== null) {
                $solutionPlan = [(int) $rows[$solutionId]['user_id']];
            }
            $now = self::utc($nowTs);
            $this->stats->ensureMany($solutionPlan, $now);
            $plan = ['source' => (int) $source['id'], 'source_forum' => (int) $source['forum_id'], 'target_forum' => (int) $target['forum_id'], 'solution' => $solutionId];

            return Transaction::run($this->db, fn (): ?array => $this->movePostsLocked($actorId, $ids, $targetTopicId, $plan, $solutionPlan, $reasonText, $nowTs));
        });
    }

    // ── Lösung (Muster S) ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * Lösung markieren (Themenstarter mit `mark_solution` oder Moderation, `can()`): ein veröffentlichter Nicht-Erstbeitrag desselben
     * Themas, sonst `invalid_target`. Protokoll nur, wenn der Handelnde nicht der Themenstarter ist.
     *
     * @return array<string, mixed> Erfolg: `topic_id`, `post_id`, `previous_post_id`, `changed`, `log_id`
     */
    public function markSolution(int $actorId, int $topicId, int $postId): array
    {
        if ($topicId < 1 || $postId < 1) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        $decision = $this->decide($actorId, 'mark_solution', ['topic_id' => $topicId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }

        return $this->run('topic.solve', ['topic_id' => $topicId], function () use ($actorId, $topicId, $postId, $nowTs): ?array {
            $topic = $this->topics->find($topicId);
            if ($topic === null) {
                return self::failure('topic_not_found');
            }
            $post = $this->posts->findMany([$postId])[$postId] ?? null;
            if ($post === null || (int) $post['topic_id'] !== $topicId || (int) $post['is_first_post'] === 1 || (string) $post['status'] !== 'published') {
                return self::failure('invalid_target');
            }
            $oldId = (int) ($topic['solution_post_id'] ?? 0);
            $oldUser = $oldId > 0 ? $this->authorOf($oldId) : null;
            $newUser = $post['user_id'] === null ? null : (int) $post['user_id'];
            $plan = self::userList([$oldUser, $newUser]);
            $now = self::utc($nowTs);
            $this->book->prepare($plan, $now);

            return Transaction::run($this->db, fn (): ?array => $this->solutionLocked($actorId, $topicId, (int) $topic['forum_id'], $postId, $oldId, $oldUser, $plan, $nowTs));
        });
    }

    /** Lösung aufheben. @return array<string, mixed> Erfolg: `topic_id`, `post_id` (die aufgehobene oder null), `changed`, `log_id` */
    public function unmarkSolution(int $actorId, int $topicId): array
    {
        if ($topicId < 1) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        $decision = $this->decide($actorId, 'mark_solution', ['topic_id' => $topicId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }

        return $this->run('topic.unsolve', ['topic_id' => $topicId], function () use ($actorId, $topicId, $nowTs): ?array {
            $topic = $this->topics->find($topicId);
            if ($topic === null) {
                return self::failure('topic_not_found');
            }
            $oldId = (int) ($topic['solution_post_id'] ?? 0);
            $oldUser = $oldId > 0 ? $this->authorOf($oldId) : null;
            $plan = self::userList([$oldUser]);
            $this->book->prepare($plan, self::utc($nowTs));

            return Transaction::run($this->db, fn (): ?array => $this->solutionLocked($actorId, $topicId, (int) $topic['forum_id'], 0, $oldId, $oldUser, $plan, $nowTs));
        });
    }

    // ── Sammelaktion ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Eine Operation auf bis zu MAX_BULK Ziele, je Ziel eine eigene Transaktion, eigene Rechteprüfung, eigenes Protokoll und Hooks nach
     * dessen Commit; ein Fehler bricht nicht ab. Die Uhr wird einmal für alle gelesen. `$params`: `reason` (string), `until` (int,
     * `topic.lock`/`topic.pin`), `target_forum_id` (int, `topic.move`), `stub` (bool). Löschen läuft über `PostService::delete()` bzw.
     * `TopicService::delete()` mit derselben Verbindung, denselben Closures und eingefrorener Uhr.
     *
     * @param list<int> $ids
     * @param array<string, mixed> $params
     * @return array<string, mixed> Erfolg: `operation`, `items` (je Ziel `id`, `success`, `error?`, `changed?`), `done`, `failed`
     */
    public function bulk(int $actorId, string $operation, array $ids, array $params = []): array
    {
        if (!in_array($operation, self::BULK_OPERATIONS, true) || $ids === []) {
            return self::failure('invalid_input');
        }
        if (count($ids) > self::MAX_BULK) {
            return self::failure('bulk_limit');
        }
        $ids = self::idList($ids);
        if ($ids === null || array_diff(array_keys($params), ['reason', 'until', 'target_forum_id', 'stub']) !== []) {
            return self::failure('invalid_input');
        }
        $reasonRaw = $params['reason'] ?? '';
        $until = $params['until'] ?? null;
        $target = $params['target_forum_id'] ?? null;
        $stub = $params['stub'] ?? true;
        if (!is_string($reasonRaw) || ($until !== null && !is_int($until)) || !is_bool($stub)
            || ($operation === 'topic.move' && (!is_int($target) || $target < 1))
        ) {
            return self::failure('invalid_input');
        }
        [$reasonText, $error] = $this->reasonFor($operation, $reasonRaw);
        if ($error !== null) {
            return self::failure($error);
        }
        $nowTs = ($this->clock)();
        $frozen = static fn (): int => $nowTs;
        $deletePosts = $operation === 'post.delete' ? new PostService($this->db, $this->settings, $frozen, $this->can, $this->dispatch) : null;
        $deleteTopics = $operation === 'topic.delete' ? new TopicService($this->db, $this->settings, $frozen, $this->can, $this->dispatch) : null;

        $items = [];
        $done = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $result = match ($operation) {
                'post.approve', 'post.reject', 'post.hide', 'post.restore' => $this->postStatusAt($operation, $actorId, $id, $reasonText, $nowTs),
                'topic.approve', 'topic.reject', 'topic.hide', 'topic.restore' => $this->topicStatusAt($operation, $actorId, $id, $reasonText, $nowTs),
                'topic.lock', 'topic.pin' => $this->flagAt($operation, $actorId, $id, $until, $reasonText, $nowTs),
                'topic.unlock', 'topic.unpin' => $this->flagAt($operation, $actorId, $id, null, $reasonText, $nowTs),
                'topic.move' => $this->moveTopicAt($actorId, $id, (int) $target, $stub, $reasonText, $nowTs),
                'post.delete' => self::deleted($deletePosts?->delete($actorId, $id, $reasonText ?? '') ?? []),
                'topic.delete' => self::deleted($deleteTopics?->delete($actorId, $id, $reasonText ?? '') ?? []),
            };
            $item = ['id' => $id, 'success' => !empty($result['success'])];
            if (!empty($result['success'])) {
                $item['changed'] = !empty($result['changed']);
                $done++;
            } else {
                $item['error'] = (string) ($result['error'] ?? 'db_error');
                $failed++;
            }
            $items[] = $item;
        }

        return ['success' => true, 'operation' => $operation, 'items' => $items, 'done' => $done, 'failed' => $failed];
    }

    // ── Lesen: Warteschlange und Protokoll ──────────────────────────────────────────────────────────────────────

    /**
     * Wartende Beiträge der moderierten Foren, älteste zuerst (Keyset). `$forumId` außerhalb des Bereichs: `not_found`. Einträge ohne
     * `ip_hash` und `body_html`, `body` neutralisiert (§1 Nr. 9). Keine Uhr.
     *
     * @return array<string, mixed> Erfolg: `items`, `next`, `has_more`
     */
    public function queue(int $actorId, ?string $cursor = null, ?int $limit = null, ?int $forumId = null): array
    {
        if ($forumId !== null && $forumId < 1) {
            return self::failure('invalid_input');
        }
        $limit = max(1, min(PostRepository::MAX_LIMIT, $limit ?? self::QUEUE_LIMIT));
        $scope = $this->scopeOf($actorId, []);
        if (!$scope['global'] && $scope['forum_ids'] === []) {
            return self::failure('no_permission');
        }
        if ($forumId !== null && !in_array($forumId, $scope['forum_ids'], true)) {
            return self::failure('not_found');
        }
        $forumIds = $scope['global'] ? null : $scope['forum_ids'];
        if ($forumId !== null) {
            $forumIds = [$forumId];
        }

        try {
            $page = $this->posts->queue($forumIds, Cursor::decode($cursor), $limit);
            $items = [];
            foreach ($page->items as $row) {
                $items[] = self::queueRow($row);
            }
            $items = $this->presentQueue($actorId, $items);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('moderation.queue', $e, ['actor_id' => $actorId]);
        }

        return ['success' => true, 'items' => $items, 'next' => $page->next, 'has_more' => $page->next !== null];
    }

    /**
     * Größe der Warteschlange der moderierten Foren (höchstens `PostRepository::QUEUE_COUNT_MAX`) und ältester wartender Beitrag.
     *
     * @return array<string, mixed> Erfolg: `count`, `oldest_at`
     */
    public function queueSummary(int $actorId): array
    {
        $scope = $this->scopeOf($actorId, []);
        if (!$scope['global'] && $scope['forum_ids'] === []) {
            return self::failure('no_permission');
        }
        try {
            $summary = $this->posts->queueSummary($scope['global'] ? null : $scope['forum_ids']);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('moderation.queue_summary', $e, ['actor_id' => $actorId]);
        }

        return ['success' => true, 'count' => $summary['count'], 'oldest_at' => $summary['oldest_at']];
    }

    /**
     * Protokoll der moderierten Foren, jüngste zuerst (Keyset). Forum-Moderatoren sehen nur ihre Foren, Einträge ohne Forum nur die
     * globale Moderation. Filter: `forum_id` (außerhalb des Bereichs `not_found`), `actor_id`, `action`, `target_type`, `target_id`
     * (nur mit `target_type`), `since` (einschließlich) und `until` (ausschließlich), UTC `Y-m-d H:i:s`. Keine Uhr.
     *
     * @param array<string, mixed> $filter
     * @return array<string, mixed> Erfolg: `items`, `next`, `has_more`
     */
    public function log(int $actorId, array $filter = [], ?string $cursor = null, ?int $limit = null): array
    {
        $repositoryFilter = self::logFilter($filter);
        if ($repositoryFilter === null) {
            return self::failure('invalid_input');
        }
        $limit = max(1, min(ModerationLogRepository::MAX_LIMIT, $limit ?? self::LOG_LIMIT));
        $scope = $this->scopeOf($actorId, []);
        if (!$scope['global'] && $scope['forum_ids'] === []) {
            return self::failure('no_permission');
        }
        $forumId = $filter['forum_id'] ?? null;
        if ($forumId !== null && !in_array($forumId, $scope['forum_ids'], true)) {
            return self::failure('not_found');
        }
        $visibleForums = $scope['global'] ? null : $scope['forum_ids'];
        $repositoryFilter['forum_ids'] = $forumId !== null ? [$forumId] : $visibleForums;

        try {
            $page = $this->log->list($repositoryFilter, Cursor::decode($cursor), $limit);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('moderation.log', $e, ['actor_id' => $actorId]);
        }

        return ['success' => true, 'items' => $page->items, 'next' => $page->next, 'has_more' => $page->next !== null];
    }

    // ── Cron ────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Abgelaufene befristete Zustände aufräumen (Cron, ohne Akteur, hinter dem Modul-Gate in `CommunityHooks`): Schließen und Anheften
     * zurücksetzen, Verweis-Themen löschen, je Art höchstens `$limit` Themen, je Anweisung Autocommit; danach `version` der betroffenen
     * Foren. Kein Protokoll, keine Hooks (der wirksame Zustand galt schon zur Abfragezeit).
     *
     * @return array<string, mixed> Erfolg: `locks`, `pins`, `stubs`
     */
    public function expire(int $limit = self::EXPIRE_LIMIT): array
    {
        $limit = max(1, min(TopicRepository::EXPIRE_MAX, $limit));
        $now = self::utc(($this->clock)());
        $counts = ['lock' => 0, 'pin' => 0, 'moved' => 0];
        try {
            $forumIds = [];
            foreach (array_keys($counts) as $kind) {
                $rows = $this->topics->expiredIds($kind, $now, $limit);
                if ($rows === []) {
                    continue;
                }
                $counts[$kind] = $this->topics->clearExpired($kind, array_column($rows, 'id'), $now);
                foreach ($rows as $row) {
                    $forumIds[$row['forum_id']] = $row['forum_id'];
                }
            }
            $forumIds = array_values($forumIds);
            sort($forumIds);
            $this->forums->touch($forumIds, $now);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('moderation.expire', $e, []);
        }

        return ['success' => true, 'locks' => $counts['lock'], 'pins' => $counts['pin'], 'stubs' => $counts['moved']];
    }

    // ── Systemaktionen (Meldungen) ──────────────────────────────────────────────────────────────────────────────

    /**
     * Aktion des Systems ohne `can()` (Schnittstelle sanktionen §1.7, Vertrag K8): eigene Transaktion, nie innerhalb einer Transaktion
     * aufrufen; Protokoll mit `actor_id` NULL, Hooks mit Akteur 0.
     * - `hide`: Antwort `published → hidden` wie `post.hide`; Thema oder erster Beitrag: Thema `published → hidden` wie `topic.hide`;
     *   `status_reason = 'reports'`, Protokoll `details {auto: 'reports', reports: n}` (n aus `$details['reports']`).
     * - `restore`: nur wenn das Ziel `hidden` ist und der jüngste `post.hide`/`topic.hide`-Eintrag des Ziels vom System mit
     *   `auto = 'reports'` stammt; dann wie `post.restore`/`topic.restore` mit `details {auto: 'reports_invalid'}`.
     * Jeder andere Ausgangszustand: `{success: true, changed: false}`.
     *
     * @param array<string, mixed> $details nur `reports` (int ≥ 0)
     * @return array{success: bool, changed: bool, error?: string}
     */
    public function systemAction(string $op, string $targetType, int $targetId, array $details = []): array
    {
        $reports = $details['reports'] ?? 0;
        if (!in_array($op, self::SYSTEM_OPERATIONS, true) || !in_array($targetType, ['post', 'topic'], true) || $targetId < 1
            || array_diff(array_keys($details), ['reports']) !== [] || !is_int($reports) || $reports < 0
        ) {
            return ['success' => false, 'changed' => false, 'error' => 'invalid_input'];
        }
        if ($this->db->getPdo()->inTransaction()) {
            Logger::instance()->withChannel('community')->warning('Systemaktion {op} innerhalb einer Transaktion abgelehnt.', ['op' => $op]);

            return ['success' => false, 'changed' => false, 'error' => 'db_error'];
        }
        $nowTs = ($this->clock)();
        $auto = $op === 'hide' ? ['auto' => self::AUTO_REASON, 'reports' => $reports] : ['auto' => self::AUTO_RESTORE];
        $result = $targetType === 'post'
            ? $this->postStatusAt('post.' . $op, null, $targetId, $op === 'hide' ? self::AUTO_REASON : null, $nowTs, $auto)
            : $this->topicStatusAt('topic.' . $op, null, $targetId, $op === 'hide' ? self::AUTO_REASON : null, $nowTs, $auto);
        if (empty($result['success'])) {
            return ['success' => false, 'changed' => false, 'error' => (string) ($result['error'] ?? 'db_error')];
        }

        return ['success' => true, 'changed' => !empty($result['changed'])];
    }

    // ── ip_hash ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * `ip_hash` von Beiträgen für die Moderation (Vertrag §4.4, G15): `can('view_private_data')`, dann nur Beiträge in Foren des
     * Moderationsbereichs; alle übrigen (auch fehlende) fehlen in `items`. Kein Protokoll, kein Hook.
     *
     * @param list<int> $postIds 1 bis MAX_IP_HASHES
     * @return array<string, mixed> Erfolg: `items` (Beitrags-ID => ip_hash oder null)
     */
    public function ipHashes(int $actorId, array $postIds): array
    {
        if ($postIds === [] || count($postIds) > self::MAX_IP_HASHES) {
            return self::failure('invalid_input');
        }
        $ids = self::idList($postIds);
        if ($ids === null) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        $gate = $this->decide($actorId, 'view_private_data', ['now' => $nowTs]);
        if (!$gate->allowed) { // view_private_data
            return self::denied($gate);
        }
        try {
            $rows = $this->posts->findMany($ids);
            $scope = $this->scopeOf($actorId, ['now' => $nowTs]);
            $allowed = [];
            foreach ($rows as $id => $row) {
                if (in_array((int) $row['forum_id'], $scope['forum_ids'], true)) {
                    $allowed[] = (int) $id;
                }
            }
            $items = $allowed === [] ? [] : $this->posts->ipHashes($allowed);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('moderation.ip_hashes', $e, ['actor_id' => $actorId]);
        }

        return ['success' => true, 'items' => $items];
    }

    // ── Abläufe je Muster ───────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function flag(string $action, int $actorId, int $topicId, ?int $until, string $reason): array
    {
        if ($topicId < 1) {
            return self::failure('invalid_input');
        }
        [$reasonText, $error] = $this->reasonFor($action, $reason);
        if ($error !== null) {
            return self::failure($error);
        }

        return $this->flagAt($action, $actorId, $topicId, $until, $reasonText, ($this->clock)());
    }

    /** @return array<string, mixed> */
    private function flagAt(string $action, int $actorId, int $topicId, ?int $until, ?string $reason, int $nowTs): array
    {
        if ($until !== null && ($until <= $nowTs || $until > $nowTs + self::MAX_HORIZON_DAYS * 86400)) {
            return self::failure('invalid_input');
        }
        $decision = $this->decide($actorId, $action, ['topic_id' => $topicId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }

        return $this->run($action, ['topic_id' => $topicId], function () use ($action, $actorId, $topicId, $until, $reason, $nowTs): ?array {
            $topic = $this->topics->find($topicId);
            if ($topic === null) {
                return self::failure('topic_not_found');
            }
            $forumId = (int) $topic['forum_id'];

            return Transaction::run($this->db, fn (): ?array => $this->flagLocked($action, $actorId, $topicId, $forumId, $until, $reason, $nowTs));
        });
    }

    /** @return array<string, mixed>|null */
    private function flagLocked(string $action, int $actorId, int $topicId, int $forumId, ?int $until, ?string $reason, int $nowTs): ?array
    {
        $now = self::utc($nowTs);
        $locked = $this->lockTopicInForum($forumId, $topicId);
        if (!isset($locked['topic'])) {
            return $locked;
        }
        ['forum' => $forum, 'topic' => $topic] = $locked;
        $decision = $this->decide($actorId, $action, ['topic' => $topic, 'forum' => $forum, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        $untilUtc = $until === null ? null : self::utc($until);
        [$columns, $result, $details] = match ($action) {
            'topic.lock' => [['is_locked' => 1, 'locked_until' => $untilUtc], ['locked_until' => $untilUtc], ['until' => $untilUtc]],
            'topic.unlock' => [['is_locked' => 0, 'locked_until' => null], [], null],
            'topic.pin' => [['is_pinned' => 1, 'pinned_until' => $untilUtc], ['pinned_until' => $untilUtc], ['until' => $untilUtc]],
            'topic.unpin' => [['is_pinned' => 0, 'pinned_until' => null], [], null],
            'topic.announce' => [['is_announcement' => 1], [], null],
            default => [['is_announcement' => 0], [], null],
        };
        $result += ['topic_id' => $topicId, 'forum_id' => $forumId];
        if (self::sameColumns($topic, $columns)) {
            return self::unchanged($result);
        }
        if ($action === 'topic.pin' && (int) TopicRepository::withEffectiveState($topic, $now)['is_pinned'] !== 1 && !$this->pinRoom($forumId, $now)) {
            return self::failure('pin_limit');
        }
        $this->topics->update($topicId, $columns + ['updated_at' => $now]);
        $this->forums->applyCounters($forumId, 0, 0, $now);
        $logId = $this->writeLog([
            'actor_id' => $actorId, 'action' => $action, 'target_type' => 'topic', 'target_id' => $topicId, 'forum_id' => $forumId,
            'reason' => $reason, 'details' => $details, 'created_at' => $now,
        ]);

        return self::done($result, $logId, [[Events::TOPIC_UPDATED, $topicId, $forumId, $actorId, array_keys($columns)]]);
    }

    /** @return array<string, mixed>|null */
    private function renameLocked(int $actorId, int $topicId, int $forumId, string $title, bool $keepPrefix, ?string $newPrefix, ?string $reason, int $nowTs): ?array
    {
        $now = self::utc($nowTs);
        $locked = $this->lockTopicInForum($forumId, $topicId);
        if (!isset($locked['topic'])) {
            return $locked;
        }
        ['forum' => $forum, 'topic' => $topic] = $locked;
        $decision = $this->decide($actorId, 'topic.rename', ['topic' => $topic, 'forum' => $forum, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        $oldTitle = (string) $topic['title'];
        $oldPrefix = $topic['prefix'] === null ? null : (string) $topic['prefix'];
        $prefix = $keepPrefix ? $oldPrefix : $newPrefix;
        $slug = $title === $oldTitle ? (string) $topic['slug'] : Slugger::topicSlug($title);
        $result = ['topic_id' => $topicId, 'forum_id' => $forumId, 'title' => $title, 'slug' => $slug, 'prefix' => $prefix];
        $columns = [];
        if ($title !== $oldTitle) {
            $columns['title'] = $title;
        }
        if ($slug !== (string) $topic['slug']) {
            $columns['slug'] = $slug;
        }
        if ($prefix !== $oldPrefix) {
            $columns['prefix'] = $prefix;
        }
        if ($columns === []) {
            return self::unchanged($result);
        }
        $this->topics->update($topicId, $columns + ['updated_at' => $now]);
        $this->forums->applyCounters($forumId, 0, 0, $now);
        $logId = $this->writeLog([
            'actor_id' => $actorId, 'action' => 'topic.rename', 'target_type' => 'topic', 'target_id' => $topicId, 'forum_id' => $forumId,
            'reason' => $reason, 'details' => ['from' => $oldTitle, 'to' => $title, 'prefix_from' => $oldPrefix, 'prefix_to' => $prefix], 'created_at' => $now,
        ]);

        return self::done($result, $logId, [[Events::TOPIC_UPDATED, $topicId, $forumId, $actorId, array_keys($columns)]]);
    }

    /** @return array<string, mixed> */
    private function moveTopicAt(int $actorId, int $topicId, int $targetForumId, bool $stub, ?string $reason, int $nowTs): array
    {
        if ($targetForumId < 1) {
            return self::failure('invalid_input');
        }
        $decision = $this->decide($actorId, 'topic.move', ['topic_id' => $topicId, 'target_forum_id' => $targetForumId, 'now' => $nowTs]);
        if (!$decision->allowed) {
            // Wiederholtes Absenden: das Thema steht schon im Ziel (`invalid_target` kommt erst nach den Moderationsrechten).
            if ($decision->reason === 'invalid_target') {
                try {
                    $topic = $this->topics->find($topicId);
                } catch (\PDOException | \RuntimeException $e) {
                    return $this->databaseError('topic.move', $e, ['topic_id' => $topicId]);
                }
                if ($topic !== null && (int) $topic['forum_id'] === $targetForumId) {
                    return self::unchanged(['topic_id' => $topicId, 'from_forum_id' => $targetForumId, 'to_forum_id' => $targetForumId, 'stub_topic_id' => null, 'unpinned' => false]);
                }
            }

            return self::denied($decision);
        }

        // Ablauf des Verweises vor der Transaktion: kein Lesen der Einstellungen unter Sperren (Vertrag §1 Nr. 2).
        $stubExpires = $nowTs + $this->settings->int('moved_stub_days') * 86400;

        return $this->run('topic.move', ['topic_id' => $topicId], function () use ($actorId, $topicId, $targetForumId, $stub, $stubExpires, $reason, $nowTs): ?array {
            $topic = $this->topics->find($topicId);
            if ($topic === null) {
                return self::failure('topic_not_found');
            }
            $sourceId = (int) $topic['forum_id'];
            if ($sourceId === $targetForumId) {
                return self::unchanged(['topic_id' => $topicId, 'from_forum_id' => $sourceId, 'to_forum_id' => $targetForumId, 'stub_topic_id' => null, 'unpinned' => false]);
            }

            return Transaction::run($this->db, fn (): ?array => $this->moveTopicLocked($actorId, $topicId, $sourceId, $targetForumId, $stub, $stubExpires, $reason, $nowTs));
        });
    }

    /** @return array<string, mixed>|null */
    private function moveTopicLocked(int $actorId, int $topicId, int $sourceId, int $targetId, bool $stub, int $stubExpires, ?string $reason, int $nowTs): ?array
    {
        $now = self::utc($nowTs);
        // Sperrfolge M: beide Foren aufsteigend nach ID, dann das Thema.
        [$first, $second] = self::ascending($sourceId, $targetId);
        $forums = [$first => $this->forums->lockForUpdate($first), $second => $this->forums->lockForUpdate($second)];
        if ($forums[$sourceId] === null || $forums[$targetId] === null) {
            return self::failure('forum_not_found');
        }
        $topic = $this->topics->lockForUpdate($topicId);
        if ($topic === null) {
            return self::failure('topic_not_found');
        }
        if ((int) $topic['forum_id'] !== $sourceId) {
            return null;
        }
        $decision = $this->decide($actorId, 'topic.move', ['topic' => $topic, 'forum' => $forums[$sourceId], 'target_forum_id' => $targetId, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        if (!in_array((string) $topic['status'], self::MOVABLE_TOPIC, true)) {
            return self::failure('invalid_state');
        }
        $columns = ['forum_id' => $targetId, 'updated_at' => $now];
        $unpinned = false;
        if ((int) TopicRepository::withEffectiveState($topic, $now)['is_pinned'] === 1 && !$this->pinRoom($targetId, $now)) {
            $columns += ['is_pinned' => 0, 'pinned_until' => null];
            $unpinned = true;
        }
        $this->topics->update($topicId, $columns);
        $this->posts->setTopicForum($topicId, $targetId);
        $this->reports->reassignTopic($topicId, $targetId);
        // Verweis-Themen erst nach allen Sperren und dem Umhängen der Meldungen (Vertrag §3.10): alte Verweise im Ziel auf dieses
        // Thema löschen, einen neuen im Quellforum anlegen.
        foreach ($this->topics->stubIdsFor($targetId, $topicId) as $oldStub) {
            $this->topics->deleteStub($oldStub);
        }
        $stubId = 0;
        if ($stub && $topic['status'] === 'published') {
            $stubId = $this->topics->insert([
                'forum_id' => $sourceId,
                'user_id' => $topic['user_id'] === null ? null : (int) $topic['user_id'],
                'title' => (string) $topic['title'],
                'slug' => (string) $topic['slug'],
                'status' => 'moved',
                'moved_to_topic_id' => $topicId,
                'moved_expires_at' => self::utc($stubExpires),
                'last_post_at' => $topic['last_post_at'] ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->book->topicMoved($forums[$sourceId], $forums[$targetId], ['forum_id' => $targetId] + $topic, $now);
        $logId = $this->writeLog([
            'actor_id' => $actorId, 'action' => 'topic.move', 'target_type' => 'topic', 'target_id' => $topicId, 'forum_id' => $sourceId,
            'reason' => $reason, 'details' => ['from_forum' => $sourceId, 'to_forum' => $targetId, 'stub' => $stubId, 'unpinned' => $unpinned], 'created_at' => $now,
        ]);

        return self::done(
            ['topic_id' => $topicId, 'from_forum_id' => $sourceId, 'to_forum_id' => $targetId, 'stub_topic_id' => $stubId > 0 ? $stubId : null, 'unpinned' => $unpinned],
            $logId,
            [[Events::TOPIC_MOVED, $topicId, $sourceId, $targetId, $actorId, $stubId]]
        );
    }

    /** @return array<string, mixed> */
    private function topicStatus(string $action, int $actorId, int $topicId, string $reason): array
    {
        if ($topicId < 1) {
            return self::failure('invalid_input');
        }
        [$reasonText, $error] = $this->reasonFor($action, $reason);
        if ($error !== null) {
            return self::failure($error);
        }

        return $this->topicStatusAt($action, $actorId, $topicId, $reasonText, ($this->clock)());
    }

    /**
     * Statuswechsel eines Themas (Muster TA; `topic.reject` ohne Statistiksperre). `$actorId` null = System (kein `can()`).
     *
     * @param array<string, mixed> $auto Detailwerte der Systemaktion
     * @return array<string, mixed>
     */
    private function topicStatusAt(string $action, ?int $actorId, int $topicId, ?string $reason, int $nowTs, array $auto = []): array
    {
        if ($actorId !== null) {
            $decision = $this->decide($actorId, $action, ['topic_id' => $topicId, 'now' => $nowTs]);
            if (!$decision->allowed) {
                if ($action === 'topic.reject' && $decision->reason === 'topic_deleted') {
                    $repeat = $this->repeatedReject($actorId, $topicId, $nowTs);
                    if ($repeat !== null) {
                        return $repeat;
                    }
                }

                return self::denied($decision);
            }
        }

        return $this->run($action, ['topic_id' => $topicId], function () use ($action, $actorId, $topicId, $reason, $nowTs, $auto): ?array {
            $topic = $this->topics->find($topicId);
            if ($topic === null) {
                return self::failure('topic_not_found');
            }
            $plan = [];
            if ($action !== 'topic.reject') {
                $plan = $this->book->plan($topicId, $topic['user_id'] === null ? null : (int) $topic['user_id']);
                $this->book->prepare($plan, self::utc($nowTs));
            }

            return Transaction::run($this->db, fn (): ?array => $this->topicStatusLocked($action, $actorId, $topicId, (int) $topic['forum_id'], $plan, $reason, $nowTs, $auto));
        });
    }

    /**
     * @param list<int> $plan
     * @param array<string, mixed> $auto
     * @return array<string, mixed>|null
     */
    private function topicStatusLocked(string $action, ?int $actorId, int $topicId, int $forumId, array $plan, ?string $reason, int $nowTs, array $auto): ?array
    {
        $now = self::utc($nowTs);
        // Sperrfolge TA: Forum → Thema → [erster Beitrag] → Statistik des Plans (aufsteigend).
        $locked = $this->lockTopicInForum($forumId, $topicId);
        if (!isset($locked['topic'])) {
            return $locked;
        }
        ['forum' => $forum, 'topic' => $topic] = $locked;
        $first = null;
        if ($action !== 'topic.hide') {
            $first = (int) ($topic['first_post_id'] ?? 0) > 0 ? $this->posts->lockForUpdate((int) $topic['first_post_id']) : null;
            if ($first === null) {
                return self::failure('invalid_state');
            }
        }
        if ($action !== 'topic.reject') {
            $this->book->lock($plan, $now);
            if (!$this->book->covers($topicId, $plan)) {
                return null;
            }
        }
        if ($actorId !== null) {
            $decision = $this->decide($actorId, $action, ['topic' => $topic, 'forum' => $forum, 'now' => $nowTs, 'fresh' => true]);
            if (!$decision->allowed) {
                return self::denied($decision);
            }
        }
        $status = (string) $topic['status'];
        $result = ['topic_id' => $topicId, 'forum_id' => $forumId, 'status' => $status];
        $actor = $actorId ?? 0;

        if ($action === 'topic.hide') {
            if ($status !== 'published') {
                return $status === 'hidden' || $actorId === null ? self::unchanged($result) : self::failure('invalid_state');
            }
            $this->topics->update($topicId, ['status' => 'hidden', 'previous_status' => 'published', 'updated_at' => $now]);
            $this->book->topicUnpublished($forum, ['status' => 'hidden'] + $topic, $plan, $now);
            $logId = $this->writeLog(self::entry($actorId, 'topic.hide', 'topic', $topicId, $forumId, $reason, ['from' => 'published'] + $auto, $now));

            return self::done(['status' => 'hidden'] + $result, $logId, [[Events::TOPIC_HIDDEN, $topicId, $forumId, $actor, $logId]]);
        }
        if ($action === 'topic.restore') {
            if ($status !== 'hidden' || ($actorId === null && !$this->autoHidden('topic', $topicId))) {
                return $status === 'published' || $actorId === null ? self::unchanged($result) : self::failure('invalid_state');
            }
            $target = (string) $first['status'] === 'pending' ? 'pending' : 'published';
            $this->topics->update($topicId, ['status' => $target, 'previous_status' => null, 'updated_at' => $now]);
            if ($target === 'published') {
                $this->book->topicPublished($forum, ['status' => 'published'] + $topic, $plan, $now);
            }
            $logId = $this->writeLog(self::entry($actorId, 'topic.restore', 'topic', $topicId, $forumId, $reason, ['from' => 'hidden', 'to' => $target] + $auto, $now));

            return self::done(['status' => $target] + $result, $logId, [[Events::TOPIC_RESTORED, $topicId, $forumId, $actor, $target]]);
        }
        $firstId = (int) $first['id'];
        $result['post_id'] = $firstId;
        if ($action === 'topic.approve') {
            if ($status === 'published') {
                return self::unchanged($result);
            }
            if ($status !== 'pending' || (string) $first['status'] !== 'pending') {
                return self::failure('invalid_state');
            }
            $this->posts->update($firstId, ['status' => 'published', 'status_reason' => null, 'updated_at' => $now]);
            $this->topics->update($topicId, ['status' => 'published', 'updated_at' => $now]);
            $this->book->topicPublished($forum, ['status' => 'published'] + $topic, $plan, $now, ['status' => 'published'] + $first);
            $logId = $this->writeLog(self::entry($actorId, 'topic.approve', 'topic', $topicId, $forumId, $reason, ['first_post' => $firstId], $now));

            return self::done(['status' => 'published'] + $result, $logId, [[Events::TOPIC_APPROVED, $topicId, $forumId, $actor, $firstId]]);
        }
        // topic.reject
        if (self::rejectedState($topic, $first)) {
            return self::unchanged($result);
        }
        if ($status !== 'pending' || (string) $first['status'] !== 'pending') {
            return self::failure('invalid_state');
        }
        $this->posts->update($firstId, ['status' => 'rejected', 'status_reason' => $reason, 'updated_at' => $now]);
        $this->topics->update($topicId, ['status' => 'deleted', 'previous_status' => 'pending', 'trashed_at' => $now, 'trashed_by' => $actorId, 'updated_at' => $now]);
        $logId = $this->writeLog(self::entry($actorId, 'topic.reject', 'topic', $topicId, $forumId, $reason, ['first_post' => $firstId], $now));

        return self::done(['status' => 'deleted'] + $result, $logId, [[Events::TOPIC_REJECTED, $topicId, $forumId, $actor, $logId]]);
    }

    /**
     * Wiederholtes Ablehnen: `can()` lehnt das gelöschte Thema mit `topic_deleted` ab. Ist es schon abgelehnt (Thema `deleted` aus
     * `pending`, erster Beitrag `rejected`) und darf der Handelnde den ersten Beitrag ablehnen (`post.reject` gilt auch in gelöschten
     * Themen), ist das Ergebnis `changed = false`; sonst `null` (die Ablehnung gilt). Ohne Sperre, ohne Schreiben.
     *
     * @return array<string, mixed>|null
     */
    private function repeatedReject(int $actorId, int $topicId, int $nowTs): ?array
    {
        try {
            $topic = $this->topics->find($topicId);
            $first = $topic === null || (int) ($topic['first_post_id'] ?? 0) < 1 ? null : $this->posts->find((int) $topic['first_post_id']);
            if ($topic === null || $first === null || !self::rejectedState($topic, $first)) {
                return null;
            }
            if (!$this->decide($actorId, 'post.reject', ['post' => $first, 'topic' => $topic, 'now' => $nowTs])->allowed) {
                return null;
            }
        } catch (\PDOException | \RuntimeException $e) {
            $this->databaseError('topic.reject', $e, ['topic_id' => $topicId]);

            return null;
        }

        return self::unchanged(['topic_id' => $topicId, 'forum_id' => (int) $topic['forum_id'], 'status' => 'deleted', 'post_id' => (int) $first['id']]);
    }

    /** @return array<string, mixed> */
    private function postStatus(string $action, int $actorId, int $postId, string $reason): array
    {
        if ($postId < 1) {
            return self::failure('invalid_input');
        }
        [$reasonText, $error] = $this->reasonFor($action, $reason);
        if ($error !== null) {
            return self::failure($error);
        }

        return $this->postStatusAt($action, $actorId, $postId, $reasonText, ($this->clock)());
    }

    /**
     * Statuswechsel einer Antwort (Muster P). Erster Beitrag: Freigabe und Ablehnung wie beim Thema, Ausblenden und Wiederherstellen
     * `first_post` (System: Ausblenden und Wiederherstellen des Themas). `$actorId` null = System (kein `can()`).
     *
     * @param array<string, mixed> $auto Detailwerte der Systemaktion
     * @return array<string, mixed>
     */
    private function postStatusAt(string $action, ?int $actorId, int $postId, ?string $reason, int $nowTs, array $auto = []): array
    {
        if ($actorId !== null) {
            $decision = $this->decide($actorId, $action, ['post_id' => $postId, 'now' => $nowTs]);
            if (!$decision->allowed) {
                return self::denied($decision);
            }
        }
        try {
            $post = $this->posts->find($postId);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError($action, $e, ['post_id' => $postId]);
        }
        if ($post === null) {
            return self::failure('post_not_found');
        }
        if ((int) $post['is_first_post'] === 1) {
            $topicAction = match (true) {
                $action === 'post.approve' => 'topic.approve',
                $action === 'post.reject' => 'topic.reject',
                $actorId === null => $action === 'post.hide' ? 'topic.hide' : 'topic.restore',
                default => null,
            };

            return $topicAction === null
                ? self::failure('first_post')
                : $this->topicStatusAt($topicAction, $actorId, (int) $post['topic_id'], $reason, $nowTs, $auto);
        }
        $counting = $action !== 'post.reject';

        return $this->run($action, ['post_id' => $postId], function () use (&$post, $action, $actorId, $postId, $reason, $nowTs, $auto, $counting): ?array {
            $post ??= $this->posts->find($postId);
            if ($post === null) {
                return self::failure('post_not_found');
            }
            $author = $post['user_id'] === null ? null : (int) $post['user_id'];
            $topicId = (int) $post['topic_id'];
            $forumId = (int) $post['forum_id'];
            $post = null;
            if ($counting && $author !== null) {
                // Statistikzeile des Autors vor der Transaktion (Autocommit): die Sperre trifft eine vorhandene Zeile.
                $this->stats->ensure($author, self::utc($nowTs));
            }

            return Transaction::run($this->db, fn (): ?array => $this->postStatusLocked($action, $actorId, $postId, $topicId, $forumId, $counting ? $author : null, $reason, $nowTs, $auto));
        });
    }

    /**
     * @param array<string, mixed> $auto
     * @return array<string, mixed>|null
     */
    private function postStatusLocked(string $action, ?int $actorId, int $postId, int $topicId, int $forumId, ?int $author, ?string $reason, int $nowTs, array $auto): ?array
    {
        $now = self::utc($nowTs);
        // Sperrfolge P: Forum → Thema → Beitrag → [Statistik des Autors].
        $locked = $this->lockTopicInForum($forumId, $topicId);
        if (!isset($locked['topic'])) {
            return $locked;
        }
        ['forum' => $forum, 'topic' => $topic] = $locked;
        $post = $this->posts->lockForUpdate($postId);
        if ($post === null) {
            return self::failure('post_not_found');
        }
        $lockedAuthor = $post['user_id'] === null ? null : (int) $post['user_id'];
        if ((int) $post['topic_id'] !== $topicId || ($author !== null && $lockedAuthor !== $author)) {
            return null;
        }
        if ($author !== null) {
            $this->stats->lockForUpdate($author, $now);
        }
        if ($actorId !== null) {
            $decision = $this->decide($actorId, $action, ['post' => $post, 'topic' => $topic, 'forum' => $forum, 'now' => $nowTs, 'fresh' => true]);
            if (!$decision->allowed) {
                return self::denied($decision);
            }
        }
        $status = (string) $post['status'];
        $result = ['post_id' => $postId, 'topic_id' => $topicId, 'forum_id' => $forumId, 'status' => $status];
        $actor = $actorId ?? 0;

        if ($action === 'post.approve' || $action === 'post.restore') {
            if ($status === 'published') {
                return self::unchanged($result);
            }
            $restorable = $status === 'hidden' || ($status === 'deleted' && (string) $post['status_reason'] === 'moderator');
            $allowed = $action === 'post.approve' ? $status === 'pending' : $restorable && ($actorId !== null || $status === 'hidden');
            if ($allowed && $actorId === null && !$this->autoHidden('post', $postId)) {
                $allowed = false;
            }
            if (!$allowed) {
                return $actorId === null ? self::unchanged($result) : self::failure('invalid_state');
            }
            $this->posts->update($postId, ['status' => 'published', 'status_reason' => null, 'updated_at' => $now]);
            $this->book->postPublished($forum, $topic, ['status' => 'published'] + $post, $now);
            $logAction = $action === 'post.approve' ? 'post.approve' : 'post.restore';
            $logId = $this->writeLog(self::entry($actorId, $logAction, 'post', $postId, $forumId, $reason, $logAction === 'post.restore' ? ['from' => $status] + $auto : null, $now));
            $hook = $action === 'post.approve'
                ? [Events::POST_APPROVED, $postId, $topicId, $actor]
                : [Events::POST_RESTORED, $postId, $topicId, $actor, $status];

            return self::done(['status' => 'published'] + $result, $logId, [$hook]);
        }
        if ($action === 'post.reject') {
            if ($status === 'rejected') {
                return self::unchanged($result);
            }
            if ($status !== 'pending') {
                return self::failure('invalid_state');
            }
            $this->posts->update($postId, ['status' => 'rejected', 'status_reason' => $reason, 'updated_at' => $now]);
            $logId = $this->writeLog(self::entry($actorId, 'post.reject', 'post', $postId, $forumId, $reason, null, $now));

            return self::done(['status' => 'rejected'] + $result, $logId, [[Events::POST_REJECTED, $postId, $topicId, $actor, $logId]]);
        }
        // post.hide
        $result['solution_cleared'] = false;
        if ($status !== 'published') {
            return $status === 'hidden' || $actorId === null ? self::unchanged($result) : self::failure('invalid_state');
        }
        $this->posts->update($postId, ['status' => 'hidden', 'status_reason' => $reason, 'updated_at' => $now]);
        $cleared = $this->book->postUnpublished($forum, $topic, ['status' => 'hidden'] + $post, $now);
        $logId = $this->writeLog(self::entry($actorId, 'post.hide', 'post', $postId, $forumId, $reason, ['from' => 'published', 'solution_cleared' => $cleared] + $auto, $now));
        $hooks = [[Events::POST_HIDDEN, $postId, $topicId, $actor, $logId]];
        if ($cleared) {
            $hooks[] = [Events::SOLUTION_UNMARKED, $topicId, $postId, $actor];
        }

        return self::done(['status' => 'hidden', 'solution_cleared' => $cleared] + $result, $logId, $hooks);
    }

    /**
     * @param list<int> $ids aufsteigend
     * @param array{source: int, source_forum: int, target_forum: int, solution: int} $plan
     * @param list<int> $solutionPlan
     * @return array<string, mixed>|null
     */
    private function movePostsLocked(int $actorId, array $ids, int $targetTopicId, array $plan, array $solutionPlan, ?string $reason, int $nowTs): ?array
    {
        $now = self::utc($nowTs);
        $sourceForumId = $plan['source_forum'];
        $targetForumId = $plan['target_forum'];
        // Sperrfolge PM: Foren aufsteigend → Themen aufsteigend → Beiträge aufsteigend → [Statistik des Lösungsautors].
        [$firstForum, $secondForum] = self::ascending($sourceForumId, $targetForumId);
        $forums = [$firstForum => $this->forums->lockForUpdate($firstForum)];
        if ($secondForum !== $firstForum) {
            $forums[$secondForum] = $this->forums->lockForUpdate($secondForum);
        }
        if ($forums[$sourceForumId] === null || $forums[$targetForumId] === null) {
            return self::failure('forum_not_found');
        }
        [$firstTopic, $secondTopic] = self::ascending($plan['source'], $targetTopicId);
        $topics = [$firstTopic => $this->topics->lockForUpdate($firstTopic), $secondTopic => $this->topics->lockForUpdate($secondTopic)];
        $source = $topics[$plan['source']];
        $target = $topics[$targetTopicId];
        if ($source === null || $target === null) {
            return self::failure('topic_not_found');
        }
        $posts = $this->posts->lockMany($ids);
        $this->stats->lockMany($solutionPlan, $now);
        if ((int) $source['forum_id'] !== $sourceForumId || (int) $target['forum_id'] !== $targetForumId
            || (int) ($source['solution_post_id'] ?? 0) !== $plan['solution'] || count($posts) !== count($ids)
        ) {
            return null;
        }
        foreach ($posts as $post) {
            if ((int) $post['topic_id'] !== $plan['source']) {
                return null;
            }
        }
        $decision = $this->decide($actorId, 'post.move', ['topic' => $source, 'forum' => $forums[$sourceForumId], 'target_forum_id' => $targetForumId, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        foreach ($posts as $post) {
            if ((int) $post['is_first_post'] === 1) {
                return self::failure('first_post');
            }
            if (!in_array((string) $post['status'], self::MOVABLE_POST, true)) {
                return self::failure('invalid_state');
            }
        }
        if ((string) $source['status'] !== 'published') {
            return self::failure('invalid_state');
        }
        if ((string) $target['status'] !== 'published') {
            return self::failure('invalid_target');
        }
        $this->posts->moveToTopic($ids, $targetTopicId, $targetForumId);
        $this->reports->reassignPosts($ids, $targetForumId);
        $cleared = $this->book->postsMoved($forums[$sourceForumId], $forums[$targetForumId], $source, $target, array_values($posts), $now);
        $entries = [];
        foreach ($ids as $id) {
            $entries[] = self::entry($actorId, 'post.move', 'post', $id, $sourceForumId, $reason, ['from_topic' => $plan['source'], 'to_topic' => $targetTopicId], $now);
        }
        $this->log->addMany($entries);
        $hooks = [];
        foreach ($ids as $id) {
            $hooks[] = [Events::POST_MOVED, $id, $plan['source'], $targetTopicId, $actorId];
        }
        if ($cleared) {
            $hooks[] = [Events::SOLUTION_UNMARKED, $plan['source'], $plan['solution'], $actorId];
        }

        return self::done(['from_topic_id' => $plan['source'], 'to_topic_id' => $targetTopicId, 'moved' => $ids, 'solution_cleared' => $cleared], null, $hooks);
    }

    /**
     * Lösung setzen (`$postId` > 0) oder aufheben (`$postId` 0). Sperrfolge S: Forum → Thema → [neuer Lösungsbeitrag] → Statistik.
     *
     * @param list<int> $plan
     * @return array<string, mixed>|null
     */
    private function solutionLocked(int $actorId, int $topicId, int $forumId, int $postId, int $oldId, ?int $oldUser, array $plan, int $nowTs): ?array
    {
        $now = self::utc($nowTs);
        $locked = $this->lockTopicInForum($forumId, $topicId);
        if (!isset($locked['topic'])) {
            return $locked;
        }
        ['forum' => $forum, 'topic' => $topic] = $locked;
        $post = $postId > 0 ? $this->posts->lockForUpdate($postId) : null;
        $this->book->lock($plan, $now);
        if ((int) ($topic['solution_post_id'] ?? 0) !== $oldId) {
            return null;
        }
        $decision = $this->decide($actorId, 'mark_solution', ['topic' => $topic, 'forum' => $forum, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        $starter = $topic['user_id'] === null ? null : (int) $topic['user_id'];
        $foreign = $starter !== $actorId;
        if ($postId === 0) {
            $result = ['topic_id' => $topicId, 'post_id' => $oldId > 0 ? $oldId : null];
            if ($oldId === 0) {
                return self::unchanged($result);
            }
            $this->topics->update($topicId, ['solution_post_id' => null, 'solved_at' => null, 'solved_by' => null, 'updated_at' => $now]);
            $this->forums->applyCounters($forumId, 0, 0, $now);
            $this->book->solutionChanged($topic, $oldUser, null, $now);
            $logId = $foreign ? $this->writeLog(self::entry($actorId, 'topic.unsolve', 'topic', $topicId, $forumId, null, ['post' => $oldId], $now)) : null;

            return self::done($result, $logId, [[Events::SOLUTION_UNMARKED, $topicId, $oldId, $actorId]]);
        }
        $result = ['topic_id' => $topicId, 'post_id' => $postId, 'previous_post_id' => $oldId > 0 ? $oldId : null];
        if ($post === null || (int) $post['topic_id'] !== $topicId || (int) $post['is_first_post'] === 1 || (string) $post['status'] !== 'published') {
            return self::failure('invalid_target');
        }
        $newUser = $post['user_id'] === null ? null : (int) $post['user_id'];
        if ($newUser !== null && !in_array($newUser, $plan, true)) {
            return null;
        }
        if ($oldId === $postId) {
            return self::unchanged($result);
        }
        if ((string) $topic['status'] !== 'published') {
            return self::failure('invalid_state');
        }
        $this->topics->update($topicId, ['solution_post_id' => $postId, 'solved_at' => $now, 'solved_by' => $actorId, 'updated_at' => $now]);
        $this->forums->applyCounters($forumId, 0, 0, $now);
        $this->book->solutionChanged($topic, $oldId > 0 ? $oldUser : null, $newUser, $now);
        $logId = $foreign ? $this->writeLog(self::entry($actorId, 'topic.solve', 'topic', $topicId, $forumId, null, ['post' => $postId, 'previous' => $oldId > 0 ? $oldId : null], $now)) : null;
        $hooks = [];
        if ($oldId > 0) {
            $hooks[] = [Events::SOLUTION_UNMARKED, $topicId, $oldId, $actorId];
        }
        $hooks[] = [Events::SOLUTION_MARKED, $topicId, $postId, $actorId];

        return self::done($result, $logId, $hooks);
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Führt `$attempt` bis zu MAX_ATTEMPTS-mal aus (je Versuch Lesen im Autocommit und eine Transaktion; `null` = Planabweichung),
     * danach die Hooks des Ergebnisses (nur bei `changed = true`, nach dem Commit). Datenbankfehler und drei Abweichungen: `db_error`.
     *
     * @param array<string, int|string> $context
     * @param \Closure(): ?array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function run(string $operation, array $context, \Closure $attempt): array
    {
        try {
            $result = null;
            for ($try = 1; $try <= self::MAX_ATTEMPTS && $result === null; $try++) {
                $result = $attempt();
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError($operation, $e, $context);
        }
        if ($result === null) {
            return $this->databaseError($operation, new \RuntimeException('Zustand wechselte wiederholt zwischen Lesen und Sperren.'), $context);
        }
        $hooks = $result['_hooks'] ?? [];
        unset($result['_hooks']);
        if (!empty($result['success']) && !empty($result['changed'])) {
            foreach ($hooks as $hook) {
                $this->fire(...$hook);
            }
        }

        return $result;
    }

    /**
     * Sperrt Forum und Thema (Ebenen 1 und 2) und prüft, dass das Thema noch im geplanten Forum liegt. Liefert die Zeilen, einen
     * Fehler oder `null` (Forumswechsel: neuer Versuch).
     *
     * @return array<string, mixed>|null
     */
    private function lockTopicInForum(int $forumId, int $topicId): ?array
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

        return ['forum' => $forum, 'topic' => $topic];
    }

    /**
     * Freier Platz für ein weiteres Anheften im Forum (unter der Forensperre, `idx_community_topics_forum_list`).
     */
    private function pinRoom(int $forumId, string $nowUtc): bool
    {
        $pins = $this->topics->countPinned($forumId, $nowUtc);
        if ($pins >= TopicRepository::MAX_PINNED) {
            return false;
        }

        return true;
    }

    /**
     * Wurde das Ziel zuletzt vom System wegen Meldungen ausgeblendet? (jüngster `*.hide`-Eintrag mit Akteur NULL und `auto = reports`)
     */
    private function autoHidden(string $targetType, int $targetId): bool
    {
        $entry = $this->log->latestFor($targetType, $targetId, [$targetType . '.hide']);

        return $entry !== null && $entry['actor_id'] === null && ($entry['details']['auto'] ?? null) === self::AUTO_REASON;
    }

    /**
     * Normalisierter Grund (eine Zeile, höchstens 255 Zeichen, leer = null) und gegebenenfalls `reason_required`.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function reasonFor(string $action, string $raw): array
    {
        $reason = PostService::normalizeReason($raw);
        $required = in_array($action, self::REASON_REQUIRED, true) && $this->settings->bool('moderation_reason_required');
        if ($required && $reason === null) {
            return [null, 'reason_required'];
        }

        return [$reason, null];
    }

    /** @param array<string, mixed> $entry */
    private function writeLog(array $entry): int
    {
        $logId = $this->log->add($entry);

        return $logId;
    }

    /**
     * Protokolleintrag; `forum_id` ist das Forum des Ziels vor der Aktion.
     *
     * @param array<string, mixed>|null $details
     * @return array<string, mixed>
     */
    private static function entry(?int $actorId, string $action, string $targetType, int $targetId, int $forumId, ?string $reason, ?array $details, string $nowUtc): array
    {
        return [
            'actor_id' => $actorId !== null && $actorId > 0 ? $actorId : null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'forum_id' => $forumId > 0 ? $forumId : null,
            'reason' => $reason,
            'details' => $details,
            'created_at' => $nowUtc,
        ];
    }

    /**
     * Moderationsbereich über die Closure, fehlerhafte Rückgaben gelten als leer (fail-closed).
     *
     * @param array<string, mixed> $context
     * @return array{global: bool, forum_ids: list<int>}
     */
    private function scopeOf(int $actorId, array $context): array
    {
        try {
            $scope = ($this->scope)($actorId > 0 ? $actorId : null, $context);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('community')->warning('Moderationsbereich nicht ermittelbar: {exception}', ['exception' => $e]);

            return ['global' => false, 'forum_ids' => []];
        }
        if (!is_array($scope) || !is_array($scope['forum_ids'] ?? null)) {
            return ['global' => false, 'forum_ids' => []];
        }
        $forumIds = array_values(array_filter(array_map('intval', $scope['forum_ids']), static fn (int $id): bool => $id > 0));

        return ['global' => ($scope['global'] ?? false) === true, 'forum_ids' => $forumIds];
    }

    /**
     * Anzeige der Warteschlange: über den Renderer, sonst neutralisiert mit „alles verborgen“ (fail-closed, kein `html`).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function presentQueue(int $actorId, array $items): array
    {
        if ($this->render !== null) {
            return $this->render->present($actorId > 0 ? $actorId : null, $items);
        }
        $out = [];
        foreach ($items as $item) {
            $out[] = QuoteService::presentable($item, static fn (int $id): bool => false);
        }

        return $out;
    }

    /**
     * Zeile der Warteschlange: ohne `ip_hash`, Ganzzahlen typisiert, volle Ansicht.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function queueRow(array $row): array
    {
        $item = array_diff_key($row, array_flip(PostRepository::PRIVATE_COLUMNS));
        foreach (self::INT_COLUMNS as $column) {
            if (array_key_exists($column, $item) && $item[$column] !== null) {
                $item[$column] = (int) $item[$column];
            }
        }
        $item['view'] = 'full';

        return $item;
    }

    /**
     * Filter für `ModerationLogRepository::list()` aus den Filtern von `log()`; ungültig: `null`. `forum_id` prüft der Aufrufer gegen
     * den Bereich und setzt `forum_ids`.
     *
     * @param array<string, mixed> $filter
     * @return array<string, mixed>|null
     */
    private static function logFilter(array $filter): ?array
    {
        if (array_diff(array_map('strval', array_keys($filter)), self::LOG_FILTERS) !== []) {
            return null;
        }
        $out = [];
        foreach (['forum_id', 'actor_id'] as $key) {
            if (array_key_exists($key, $filter) && (!is_int($filter[$key]) || $filter[$key] < 1 || $filter[$key] > self::MAX_UINT)) {
                return null;
            }
        }
        if (isset($filter['actor_id'])) {
            $out['actor_id'] = $filter['actor_id'];
        }
        if (array_key_exists('action', $filter)) {
            if (!in_array($filter['action'], ModerationLogRepository::ACTIONS, true)) {
                return null;
            }
            $out['action'] = $filter['action'];
        }
        if (array_key_exists('target_type', $filter)) {
            if (!in_array($filter['target_type'], ModerationLogRepository::TARGET_TYPES, true)) {
                return null;
            }
            $out['target_type'] = $filter['target_type'];
        }
        if (array_key_exists('target_id', $filter)) {
            if (!isset($out['target_type']) || !is_int($filter['target_id']) || $filter['target_id'] < 1) {
                return null;
            }
            $out['target_id'] = $filter['target_id'];
        }
        foreach (['since', 'until'] as $key) {
            if (array_key_exists($key, $filter)) {
                $ts = is_string($filter[$key]) ? CommunityAccess::utcTimestamp($filter[$key]) : null;
                if ($ts === null || self::utc($ts) !== $filter[$key]) {
                    return null;
                }
                $out[$key] = $filter[$key];
            }
        }

        return $out;
    }

    /**
     * Eindeutige positive Ganzzahlen in Eingabereihenfolge; sonst `null`.
     *
     * @param array<array-key, mixed> $ids
     * @return list<int>|null
     */
    private static function idList(array $ids): ?array
    {
        $out = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || isset($out[$id])) {
                return null;
            }
            $out[$id] = $id;
        }

        return array_values($out);
    }

    /**
     * Mitglieder ohne `null`, eindeutig, aufsteigend.
     *
     * @param list<int|null> $ids
     * @return list<int>
     */
    private static function userList(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null && $id > 0)));
        sort($ids);

        return $ids;
    }

    /** Autor eines Beitrags (ohne Text, `findMany()`), `null` ohne Autor oder Beitrag. */
    private function authorOf(int $postId): ?int
    {
        $post = $this->posts->findMany([$postId])[$postId] ?? null;

        return $post === null || $post['user_id'] === null ? null : (int) $post['user_id'];
    }

    /**
     * Ist das Thema schon abgelehnt? (Thema `deleted` aus `pending`, erster Beitrag `rejected`)
     *
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $first
     */
    private static function rejectedState(array $topic, array $first): bool
    {
        return (string) $topic['status'] === 'deleted' && (string) ($topic['previous_status'] ?? '') === 'pending' && (string) $first['status'] === 'rejected';
    }

    /**
     * Haben die Spalten der Zeile schon die Zielwerte? (Ganzzahlen als Zahl, Zeiten und `null` exakt)
     *
     * @param array<string, mixed> $row
     * @param array<string, int|string|null> $columns
     */
    private static function sameColumns(array $row, array $columns): bool
    {
        foreach ($columns as $column => $value) {
            $stored = $row[$column] ?? null;
            $same = is_int($value) ? $stored !== null && (int) $stored === $value : ($value === null ? $stored === null : (string) $stored === $value);
            if (!$same) {
                return false;
            }
        }

        return true;
    }

    /** Ergebnis einer Sammelaktion aus `PostService::delete()`/`TopicService::delete()` (`already_deleted` = keine Änderung). */
    private static function deleted(array $result): array
    {
        if (!empty($result['success'])) {
            $result['changed'] = empty($result['already_deleted']);
        }

        return $result;
    }

    /** @return array{0: int, 1: int} */
    private static function ascending(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }

    private static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private function decide(?int $actorId, string $action, array $context): AccessDecision
    {
        return ($this->can)($actorId !== null && $actorId > 0 ? $actorId : null, $action, $context);
    }

    /**
     * @param array<string, mixed> $result
     * @param list<array<int, mixed>> $hooks
     * @return array<string, mixed>
     */
    private static function done(array $result, ?int $logId, array $hooks): array
    {
        return ['success' => true, 'changed' => true] + $result + ['log_id' => $logId, '_hooks' => $hooks];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function unchanged(array $result): array
    {
        return ['success' => true, 'changed' => false] + $result + ['log_id' => null];
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
