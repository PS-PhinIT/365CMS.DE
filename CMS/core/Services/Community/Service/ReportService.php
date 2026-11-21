<?php
/**
 * Meldungen der Community (PR #2b): Melden mit Zusammenführen und Meldegrenze, Schwelle zum vorläufigen Ausblenden, Erledigen je
 * Ziel (Bündel), Listen für die Moderation.
 *
 * Grundsätze (Vertrag DESIGN-2b §1, §3.10, §4.4; Entwurf sanktionen §1.2, §2.1 bis §2.4):
 * - Feste Prüfreihenfolge: Eingaben ohne Datenbank → Uhr (genau eine Ablesung je öffentlicher Methode) → Rechte-Tor (`can('report')`
 *   bzw. Moderationsbereich) → fachliche Codes. Außerhalb des Moderationsbereichs ist eine Meldung wie eine fehlende
 *   (`report_not_found`); ohne Bereich gibt es keine Abfrage der Meldung (`no_permission`).
 * - `create`: Ein Beitrag wird ohne Sperre gelesen (Plan: Thema), die Statistikzeile des Melders entsteht im Autocommit. In der
 *   Transaktion sperrt der Dienst zuerst das Thema (Ebene 2), dann die Statistik des Melders (Ebene 4), erst danach liest er. Unter
 *   den Sperren prüft er `can(fresh)`, liest den Beitrag neu (anderes Thema ⇒ neuer Versuch, höchstens drei) und bestimmt Meldbarkeit
 *   und Forum aus der gesperrten Themenzeile. Eine Meldung trägt so immer das Forum, in dem das Thema beim Einfügen liegt; das
 *   Verschieben hält das Thema und hängt offene Meldungen um. Danach Zusammenführen (eine offene Meldung je Melder und Ziel), dann die
 *   Meldegrenze (MAX_PER_HOUR je LIMIT_WINDOW, alle Meldungen des Kontos, gleich welcher Status), dann die neue Zeile.
 * - Schwelle nach dem Commit, nie in einer Transaktion: verschiedene Melder ab Stufe TRUST_MIN mit offener Meldung zum Ziel. Erreicht
 *   ⇒ `$autoModerate('hide', …)` (System-Akteur, `ModerationService::systemAction()`). Beiträge und Themen der Moderation und
 *   Administration blendet nur die Moderation aus.
 * - `resolve`: die IDs offener Meldungen des Ziels (bis `upToReportId`) im Autocommit, in der Transaktion als erste Anweisung die
 *   Sperre über diese IDs (PRIMARY, keine Lückensperre: neue Meldungen warten nicht und bleiben offen), dann Bereich frisch,
 *   Schließen, Protokoll als letzte Anweisung. `invalid` ohne offene Restmeldung stellt eine automatische Ausblendung wieder her.
 * - Hooks nach dem Commit, nur bei Änderung; Argumente nur IDs und Status. Datenbankfehler: Rücknahme, Warnung, `db_error`.
 * - Zeit: UTC, einzige Umrechnung über `utc()`; UTC-Text → Unix nur über `CommunityAccess::utcTimestamp()`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\ModerationLogRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\ReportRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

final class ReportService
{
    /** Zielarten einer Meldung. */
    public const TARGET_TYPES = ReportRepository::TARGET_TYPES;
    /** Gründe in Anzeigereihenfolge (Sprachschlüssel `community.report.reason.<grund>`). */
    public const REASONS = ReportRepository::REASONS;
    /** Status (Sprachschlüssel `community.report.status.<status>`). */
    public const STATUSES = ReportRepository::STATUSES;
    /** Abschlussstatus beim Erledigen. */
    public const RESOLUTIONS = ['valid', 'invalid', 'resolved'];
    /** Neue Meldungen je Konto in LIMIT_WINDOW (PM §7, fest). */
    public const MAX_PER_HOUR = 10;
    /** Fenster der Meldegrenze in Sekunden. */
    public const LIMIT_WINDOW = 3600;
    /** Zeichen des Freitexts, auch nach dem Anhängen. */
    public const MESSAGE_MAX = 1000;
    /** Zeichen der Erledigungsnotiz. */
    public const NOTE_MAX = 2000;
    /** Mindeststufe der Melder für die Schwelle (PM §7, fest). */
    public const TRUST_MIN = 2;
    /** Höchstens so viele Melder je Ziel werden für die Schwelle bewertet. */
    public const REPORTER_SCAN = 50;
    /** Seitengröße von `listOpen()` (1 bis 100). */
    public const LIST_LIMIT = 20;
    /** Deckel von `countOpen()` (Badge). */
    public const COUNT_CAP = 100;

    /** Größte Seite von `listOpen()`. */
    private const LIST_MAX = 100;
    /** Zeilen eines Bündels in `bundle()`. */
    private const BUNDLE_MAX = 100;
    /** Versuche, wenn ein Beitrag zwischen Lesen und Sperren das Thema wechselt. */
    private const MAX_ATTEMPTS = 3;
    /** Größte ID einer INT-UNSIGNED-Spalte (Cursor). */
    private const MAX_UINT = 4294967295;
    /** Erlaubte Filter von `listOpen()`. */
    private const FILTER_KEYS = ['target_type', 'forum_id'];

    private static ?self $instance = null;

    private Database $db;
    private Settings $settings;
    /** @var \Closure(): int Unix-Zeit */
    private \Closure $clock;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision Mitglied, Aktion, Kontext */
    private \Closure $can;
    /** @var \Closure(string, mixed...): void Hook-Auslösung */
    private \Closure $dispatch;
    private ?CommunityAccess $access;
    /** @var \Closure(string, string, int, array<string, mixed>): array{success: bool, changed: bool, error?: string} */
    private \Closure $autoModerate;
    private ReportRepository $reports;
    private PostRepository $posts;
    private TopicRepository $topics;
    private UserStatsRepository $stats;
    private ModerationLogRepository $log;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`), genau eine Ablesung je öffentlicher Methode
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard: `CommunityAccess::can()` der Instanz aus `$access`
     * @param \Closure(string, mixed...): void|null $dispatch Standard: `Events::fire()`
     * @param CommunityAccess|null $access `authority()`, `limitsFor()`, `moderationScope()`; Standard `CommunityAccess::instance()` (erst beim ersten Bedarf)
     * @param \Closure(string, string, int, array<string, mixed>): array{success: bool, changed: bool, error?: string}|null $autoModerate
     *        System-Aktion (`hide`/`restore`); Standard `ModerationService::instance()->systemAction()`, ohne die Klasse `success: false`
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $clock = null,
        ?\Closure $can = null,
        ?\Closure $dispatch = null,
        ?CommunityAccess $access = null,
        ?\Closure $autoModerate = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->access = $access;
        $this->can = $can ?? fn (?int $userId, string $action, array $context): AccessDecision => $this->access()->can($userId, $action, $context);
        $this->dispatch = $dispatch ?? static function (string $hook, mixed ...$args): void {
            Events::fire($hook, ...$args);
        };
        $this->autoModerate = $autoModerate ?? static function (string $op, string $targetType, int $targetId, array $details): array {
            return class_exists(ModerationService::class)
                ? ModerationService::instance()->systemAction($op, $targetType, $targetId, $details)
                : ['success' => false, 'changed' => false];
        };
        $this->reports = new ReportRepository($this->db);
        $this->posts = new PostRepository($this->db);
        $this->topics = new TopicRepository($this->db);
        $this->stats = new UserStatsRepository($this->db);
        $this->log = new ModerationLogRepository($this->db);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Melden ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Meldet einen Beitrag, ein Thema oder ein Mitglied.
     *
     * Erfolg `{success: true, report_id, merged, auto_hidden}`: `merged` = offene Meldung desselben Melders zum selben Ziel (keine neue
     * Zeile, kein Hook; ein neuer Text wird mit Leerzeile angehängt, Grund und Rückmeldewunsch der ersten Meldung bleiben).
     * Fehler: `invalid_input`, `message_required`, `not_reportable`, `rate_limited` (mit `retry_after`), Gründe von `can('report')`,
     * `db_error`.
     *
     * @return array<string, mixed>
     */
    public function create(int $reporterId, string $targetType, int $targetId, string $reason, string $message = '', bool $notify = true): array
    {
        if (!in_array($targetType, self::TARGET_TYPES, true) || $targetId < 1 || !in_array($reason, self::REASONS, true)) {
            return self::failure('invalid_input');
        }
        $message = PostService::normalizeBody($message);
        if ($message === null || mb_strlen($message, 'UTF-8') > self::MESSAGE_MAX) {
            return self::failure('invalid_input');
        }
        if ($reason === 'other' && $message === '') {
            return self::failure('message_required');
        }

        $nowTs = ($this->clock)();
        $now = self::utc($nowTs);
        $context = match ($targetType) {
            'post' => ['post_id' => $targetId],
            'topic' => ['topic_id' => $targetId],
            default => ['member_id' => $targetId],
        } + ['now' => $nowTs];
        $decision = ($this->can)($reporterId > 0 ? $reporterId : null, 'report', $context);
        if (!$decision->allowed) {
            return self::denied($decision);
        }

        try {
            $result = null;
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS && $result === null; $attempt++) {
                $topicId = null;
                if ($targetType === 'post') {
                    $plan = $this->posts->find($targetId);
                    if ($plan === null) {
                        return self::failure('not_reportable');
                    }
                    $topicId = (int) $plan['topic_id'];
                } elseif ($targetType === 'topic') {
                    $topicId = $targetId;
                }
                if ($attempt === 1) {
                    // Statistikzeile des Melders im Autocommit: die Sperre trifft eine vorhandene Zeile (keine Lückensperre).
                    $this->stats->ensure($reporterId, $now);
                }
                $result = Transaction::run($this->db, fn (): ?array => $this->createLocked(
                    $reporterId, $targetType, $targetId, $topicId, $reason, $message, $notify, $context, $nowTs, $now
                ));
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('report.create', $e, ['target_type' => $targetType, 'target_id' => $targetId]);
        }
        if ($result === null) {
            return $this->databaseError('report.create', new \RuntimeException('Beitrag wechselte wiederholt das Thema.'), ['target_type' => $targetType, 'target_id' => $targetId]);
        }
        if (empty($result['success']) || !empty($result['merged'])) {
            return $result;
        }

        $reportId = (int) $result['report_id'];
        $this->fire(Events::REPORT_CREATED, $reportId, $targetType, $targetId, $reporterId);
        $result['auto_hidden'] = $this->threshold($reporterId, $targetType, $targetId, $result['author']);
        unset($result['author']);

        return $result;
    }

    /**
     * Transaktion von `create()`: erst alle Sperren (Thema, dann Statistik des Melders), dann Lesen und Schreiben.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null null: der Beitrag liegt inzwischen in einem anderen Thema (neuer Versuch)
     */
    private function createLocked(int $reporterId, string $targetType, int $targetId, ?int $topicId, string $reason, string $message, bool $notify, array $context, int $nowTs, string $now): ?array
    {
        $topic = null;
        if ($topicId !== null) {
            $topic = $this->topics->lockForUpdate($topicId);
        }
        $this->stats->lockForUpdate($reporterId, $now);

        $decision = ($this->can)($reporterId, 'report', $context + ['fresh' => true]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        $post = null;
        if ($targetType === 'post') {
            $post = $this->posts->find($targetId);
            if ($post === null) {
                return self::failure('not_reportable');
            }
            if ((int) $post['topic_id'] !== $topicId) {
                return null;
            }
        }
        if ($topicId !== null && $topic === null) {
            return self::failure('not_reportable');
        }
        if ($post !== null) {
            if ((string) $post['status'] !== 'published' || (string) $topic['status'] !== 'published') {
                return self::failure('not_reportable');
            }
        } elseif ($topic !== null && (string) $topic['status'] !== 'published') {
            return self::failure('not_reportable');
        }
        $forumId = $topic === null ? null : (int) $topic['forum_id'];
        $author = match ($targetType) {
            'post' => $post['user_id'] === null ? null : (int) $post['user_id'],
            'topic' => $topic['user_id'] === null ? null : (int) $topic['user_id'],
            default => $targetId,
        };

        $open = $this->reports->openOfReporter($reporterId, $targetType, $targetId);
        if ($open !== null) {
            $old = (string) ($open['message'] ?? '');
            $merged = $old === '' ? $message : $old . "\n\n" . $message;
            $append = $message !== '' && !str_contains($old, $message) && mb_strlen($merged, 'UTF-8') <= self::MESSAGE_MAX;
            if (!$append || $this->reports->appendMessage($open['id'], $merged)) {
                return ['success' => true, 'report_id' => $open['id'], 'merged' => true, 'auto_hidden' => false];
            }
            // Inzwischen erledigt: weiter als neue Meldung.
        }

        $recent = $this->reports->recentByReporter($reporterId, self::utc($nowTs - self::LIMIT_WINDOW));
        if ($recent['count'] >= self::MAX_PER_HOUR) {
            $oldest = CommunityAccess::utcTimestamp($recent['oldest']) ?? $nowTs;

            return ['success' => false, 'error' => 'rate_limited', 'retry_after' => max(1, $oldest + self::LIMIT_WINDOW - $nowTs)];
        }

        $reportId = $this->reports->insert([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'forum_id' => $forumId,
            'reporter_id' => $reporterId,
            'reason' => $reason,
            'message' => $message,
            'status' => 'open',
            'notify_reporter' => $notify ? 1 : 0,
            'created_at' => $now,
        ]);

        return ['success' => true, 'report_id' => $reportId, 'merged' => false, 'auto_hidden' => false, 'author' => $author];
    }

    /**
     * Schwelle nach dem Commit einer neuen Meldung: Beitrag oder Thema, Schwelle an, Melder ab TRUST_MIN, Autor unter der Moderation,
     * genug verschiedene vertrauenswürdige Melder mit offener Meldung ⇒ System-Aktion `hide`. Fehler ⇒ Warnung, kein Abbruch.
     */
    private function threshold(int $reporterId, string $targetType, int $targetId, ?int $authorId): bool
    {
        if ($targetType === 'user') {
            return false;
        }
        try {
            $threshold = $this->settings->int('report_hide_threshold');
            if (!($threshold > 0 && $this->trusted($reporterId))) {
                return false;
            }
            $authorAuthority = $this->access()->authority($authorId);
            if ($authorAuthority === null || !($authorAuthority < CommunityAccess::AUTHORITY_MODERATOR)) {
                return false;
            }
            $trusted = 1; // der Melder dieser Meldung
            foreach ($this->reports->openReporters($targetType, $targetId, self::REPORTER_SCAN) as $other) {
                if ($trusted === $threshold) {
                    break;
                }
                if ($other !== $reporterId && $this->trusted($other)) {
                    $trusted++;
                }
            }
            if (!($trusted >= $threshold)) {
                return false;
            }
            $result = ($this->autoModerate)('hide', $targetType, $targetId, ['reports' => $trusted]);
            if (empty($result['success'])) {
                $this->warn('Automatisches Ausblenden fehlgeschlagen ({target_type} {target_id}): {error}', [
                    'target_type' => $targetType, 'target_id' => $targetId, 'error' => (string) ($result['error'] ?? 'unbekannt'),
                ]);

                return false;
            }

            return !empty($result['changed']);
        } catch (\Throwable $e) {
            $this->warn('Automatisches Ausblenden fehlgeschlagen ({target_type} {target_id}): {exception}', [
                'target_type' => $targetType, 'target_id' => $targetId, 'exception' => $e,
            ]);

            return false;
        }
    }

    /** Zählt ein Melder für die Schwelle (Stufe ab TRUST_MIN; einzige Stelle der Regel)? */
    private function trusted(int $userId): bool
    {
        return $this->access()->limitsFor($userId)['level'] >= self::TRUST_MIN;
    }

    // ── Erledigen ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Erledigt alle offenen Meldungen zum Ziel der Meldung `$reportId` bis `$upToReportId` einschließlich (`null` = alle; höchstens
     * BATCH je Aufruf, der Rest bleibt offen und `remaining` ist `true`).
     *
     * Erfolg `{success: true, changed, status, report_ids, count, remaining, restored}`; `changed: false` beim wiederholten Absenden
     * (gleicher Status, gleicher Moderator). Fehler: `invalid_input`, `no_permission` (kein Bereich, eigenes Ziel), `report_not_found`
     * (fehlt oder außerhalb des Bereichs), `report_closed` (mit `status`), `db_error`.
     *
     * @return array<string, mixed>
     */
    public function resolve(int $moderatorId, int $reportId, string $status, string $note = '', ?int $upToReportId = null): array
    {
        if ($reportId < 1 || !in_array($status, self::RESOLUTIONS, true) || ($upToReportId !== null && $upToReportId < $reportId)) {
            return self::failure('invalid_input');
        }
        $note = PostService::normalizeBody($note);
        if ($note === null || mb_strlen($note, 'UTF-8') > self::NOTE_MAX) {
            return self::failure('invalid_input');
        }

        $nowTs = ($this->clock)();
        $now = self::utc($nowTs);
        try {
            $scope = $this->scopeFor($moderatorId, ['now' => $nowTs]);
            if ($scope === null) {
                return self::failure('no_permission');
            }
            $report = $this->reports->find($reportId);
            if ($report === null) {
                return self::failure('report_not_found');
            }
            $type = $report['target_type'];
            $targetId = $report['target_id'];
            $refusal = $this->refusal($scope, $type, $this->place($report), $moderatorId);
            if ($refusal !== null) {
                return $refusal;
            }
            if ($report['status'] !== 'open') {
                if ($report['status'] === $status && $report['handled_by'] === $moderatorId) {
                    return ['success' => true, 'changed' => false, 'status' => $status, 'report_ids' => [], 'count' => 0,
                        'remaining' => $this->reports->hasOpen($type, $targetId), 'restored' => false];
                }

                return ['success' => false, 'error' => 'report_closed', 'status' => $report['status']];
            }

            $ids = $this->reports->openIdsForTarget($type, $targetId, $upToReportId, ReportRepository::BATCH + 1);
            $result = Transaction::run($this->db, fn (): array => $this->resolveLocked($moderatorId, $report, $ids, $status, $note, $nowTs, $now));
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('report.resolve', $e, ['report_id' => $reportId]);
        }
        if (empty($result['success'])) {
            return $result;
        }

        foreach ($result['report_ids'] as $id) {
            $this->fire(Events::REPORT_RESOLVED, $id, $status, $moderatorId);
        }
        $remaining = (bool) $result['remaining'];
        if ($status === 'invalid' && !$remaining && $type !== 'user') {
            $result['restored'] = $this->restore($type, $targetId);
        }

        return $result;
    }

    /**
     * Transaktion von `resolve()`: Sperre über die vorher gelesenen IDs als erste Anweisung, dann Bereich frisch, Schließen,
     * Restmeldungen, Protokoll als letzte Anweisung.
     *
     * @param array<string, mixed> $report
     * @param list<int> $ids
     * @return array<string, mixed>
     */
    private function resolveLocked(int $moderatorId, array $report, array $ids, string $status, string $note, int $nowTs, string $now): array
    {
        $locked = $this->reports->lockOpenByIds($ids);

        $scope = $this->scopeFor($moderatorId, ['fresh' => true, 'now' => $nowTs]);
        if ($scope === null) {
            return self::failure('no_permission');
        }
        $type = (string) $report['target_type'];
        $targetId = (int) $report['target_id'];
        $place = $this->place($report);
        $refusal = $this->refusal($scope, $type, $place, $moderatorId);
        if ($refusal !== null) {
            return $refusal;
        }
        if ($locked === []) {
            $current = $this->reports->find((int) $report['id']);

            return ['success' => false, 'error' => 'report_closed', 'status' => (string) ($current['status'] ?? 'resolved')];
        }
        $remaining = count($locked) > ReportRepository::BATCH;
        $closeIds = array_slice(array_map(static fn (array $row): int => $row['id'], $locked), 0, ReportRepository::BATCH);
        $this->reports->close($closeIds, $status, $note, $moderatorId, $now);
        $remaining = $remaining || $this->reports->hasOpen($type, $targetId);
        $this->log->add([
            'actor_id' => $moderatorId,
            'action' => 'report.resolve',
            'target_type' => $type,
            'target_id' => $targetId,
            'forum_id' => $place['forum'],
            'reason' => null,
            'details' => ['status' => $status, 'reports' => count($closeIds), 'first_report_id' => min($closeIds), 'last_report_id' => max($closeIds)],
            'created_at' => $now,
        ]);

        return ['success' => true, 'changed' => true, 'status' => $status, 'report_ids' => $closeIds, 'count' => count($closeIds),
            'remaining' => $remaining, 'restored' => false];
    }

    /** `invalid` ohne Restmeldung: automatische Ausblendung zurücknehmen (System-Aktion nach dem Commit; Fehler ⇒ Warnung). */
    private function restore(string $targetType, int $targetId): bool
    {
        try {
            $result = ($this->autoModerate)('restore', $targetType, $targetId, []);
            if (empty($result['success'])) {
                $this->warn('Wiederherstellen nach Meldung fehlgeschlagen ({target_type} {target_id}): {error}', [
                    'target_type' => $targetType, 'target_id' => $targetId, 'error' => (string) ($result['error'] ?? 'unbekannt'),
                ]);

                return false;
            }

            return !empty($result['changed']);
        } catch (\Throwable $e) {
            $this->warn('Wiederherstellen nach Meldung fehlgeschlagen ({target_type} {target_id}): {exception}', [
                'target_type' => $targetType, 'target_id' => $targetId, 'exception' => $e,
            ]);

            return false;
        }
    }

    // ── Listen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Offene Bündel (je Ziel) im Moderationsbereich, älteste Meldung zuerst, Keyset über `first_id` (Cursor = Ziffernfolge,
     * ungültig ⇒ erste Seite). `$filter`: `target_type` (TARGET_TYPES), `forum_id` (Forum im Bereich, sonst `not_found`).
     * Ziele `user` (ohne Forum) sieht nur die globale Moderation. Bündel ohne Melder-IDs und Texte; Details nur über `bundle()`.
     *
     * @param array<string, mixed> $filter
     * @return array<string, mixed> `{success: true, items, next, has_more}`; Fehler `invalid_input`, `no_permission`, `not_found`, `db_error`
     */
    public function listOpen(int $moderatorId, ?string $cursor = null, ?int $limit = null, array $filter = []): array
    {
        if (array_diff(array_map('strval', array_keys($filter)), self::FILTER_KEYS) !== []) {
            return self::failure('invalid_input');
        }
        $type = $filter['target_type'] ?? null;
        $forumFilter = $filter['forum_id'] ?? null;
        if (($type !== null && !in_array($type, self::TARGET_TYPES, true)) || ($forumFilter !== null && (!is_int($forumFilter) || $forumFilter < 1))) {
            return self::failure('invalid_input');
        }
        $limit = max(1, min(self::LIST_MAX, $limit ?? self::LIST_LIMIT));
        $after = $cursor !== null && preg_match('/^[1-9][0-9]{0,9}$/D', $cursor) === 1 && (int) $cursor <= self::MAX_UINT ? (int) $cursor : 0;

        $nowTs = ($this->clock)();
        try {
            $scope = $this->scopeFor($moderatorId, ['now' => $nowTs]);
            if ($scope === null) {
                return self::failure('no_permission');
            }
            if ($forumFilter !== null && !in_array($forumFilter, $scope['forum_ids'], true)) {
                return self::failure('not_found');
            }
            $forums = $forumFilter !== null ? [$forumFilter] : ($scope['global'] ? null : $scope['forum_ids']);
            $rows = $this->reports->openBundles($forums, $type, $after, $limit + 1);
            $hasMore = count($rows) > $limit;
            $rows = array_slice($rows, 0, $limit);
            $reasons = $rows === [] ? [] : $this->reports->reasonCounts(array_map(static fn (array $row): array => [$row['target_type'], $row['target_id']], $rows));
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('report.list', $e, ['moderator_id' => $moderatorId]);
        }
        $items = array_map(static fn (array $row): array => $row + ['reasons' => $reasons[$row['target_type'] . ':' . $row['target_id']] ?? []], $rows);
        $last = end($rows);

        return ['success' => true, 'items' => $items, 'next' => $hasMore && $last !== false ? (string) $last['first_id'] : null, 'has_more' => $hasMore];
    }

    /**
     * Offene Meldungen eines Ziels mit Melder, Grund und Text (höchstens 100, `truncated` darüber). Rechte und Eigen-Ziel-Regel wie
     * `resolve()`; ein Ziel ohne offene Meldung oder außerhalb des Bereichs ist `report_not_found`.
     *
     * @return array<string, mixed>
     */
    public function bundle(int $moderatorId, string $targetType, int $targetId): array
    {
        if (!in_array($targetType, self::TARGET_TYPES, true) || $targetId < 1) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        try {
            $scope = $this->scopeFor($moderatorId, ['now' => $nowTs]);
            if ($scope === null) {
                return self::failure('no_permission');
            }
            $rows = $this->reports->openForTarget($targetType, $targetId, self::BUNDLE_MAX + 1);
            if ($rows === []) {
                return self::failure('report_not_found');
            }
            $place = $this->place(['target_type' => $targetType, 'target_id' => $targetId, 'forum_id' => $rows[0]['forum_id']]);
            $refusal = $this->refusal($scope, $targetType, $place, $moderatorId);
            if ($refusal !== null) {
                return $refusal;
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('report.bundle', $e, ['target_type' => $targetType, 'target_id' => $targetId]);
        }

        return ['success' => true, 'items' => array_slice($rows, 0, self::BUNDLE_MAX), 'truncated' => count($rows) > self::BUNDLE_MAX];
    }

    /** Zahl offener Meldungen (Zeilen) im Moderationsbereich, höchstens COUNT_CAP; 0 ohne Rechte oder bei Fehlern. */
    public function countOpen(int $moderatorId): int
    {
        $nowTs = ($this->clock)();
        try {
            $scope = $this->scopeFor($moderatorId, ['now' => $nowTs]);

            return $scope === null ? 0 : $this->reports->countOpen($scope['global'] ? null : $scope['forum_ids'], self::COUNT_CAP);
        } catch (\PDOException | \RuntimeException $e) {
            $this->warn('Zahl offener Meldungen nicht lesbar: {exception}', ['exception' => $e]);

            return 0;
        }
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Moderationsbereich oder `null` (kein Bereich: Modul aus, Gast, Sperre, E-Mail, kein Moderator).
     *
     * @param array<string, mixed> $context nur `now` und `fresh`
     * @return array{global: bool, forum_ids: list<int>}|null
     */
    private function scopeFor(int $moderatorId, array $context): ?array
    {
        $scope = $this->access()->moderationScope($moderatorId > 0 ? $moderatorId : null, $context);
        if (!$scope['global'] && $scope['forum_ids'] === []) {
            return null;
        }

        return $scope;
    }

    /**
     * Aktuelles Forum und Eigentümer des Ziels: Beitrag bzw. Thema mit ihrem heutigen Forum, ein fehlendes Ziel mit dem Forum der
     * Meldung, ein Mitglied ohne Forum.
     *
     * @param array<string, mixed> $report `target_type`, `target_id`, `forum_id`
     * @return array{forum: int|null, owner: int|null}
     */
    private function place(array $report): array
    {
        $targetId = (int) $report['target_id'];
        $row = match ((string) $report['target_type']) {
            'post' => $this->posts->find($targetId),
            'topic' => $this->topics->find($targetId),
            default => null,
        };
        if ((string) $report['target_type'] === 'user') {
            return ['forum' => null, 'owner' => $targetId];
        }
        if ($row === null) {
            return ['forum' => $report['forum_id'] === null ? null : (int) $report['forum_id'], 'owner' => null];
        }

        return ['forum' => (int) $row['forum_id'], 'owner' => $row['user_id'] === null ? null : (int) $row['user_id']];
    }

    /**
     * Darf der Moderator über die Meldungen zu diesem Ziel entscheiden? Außerhalb des Bereichs wie fehlend (`report_not_found`),
     * Ziele `user` nur global; über eigene Ziele nicht (`no_permission`: wer gemeldet ist, sieht die Melder nicht).
     *
     * @param array{global: bool, forum_ids: list<int>} $scope
     * @param array{forum: int|null, owner: int|null} $place
     * @return array{success: false, error: string}|null
     */
    private function refusal(array $scope, string $targetType, array $place, int $moderatorId): ?array
    {
        $forumId = $place['forum'];
        $forums = $scope['forum_ids'];
        if (!$scope['global'] && ($targetType === 'user' || $forumId === null || !in_array($forumId, $forums, true))) {
            return self::failure('report_not_found'); // außerhalb
        }
        $ownerId = $place['owner'];
        if ($ownerId !== null && $ownerId === $moderatorId) {
            return self::failure('no_permission');
        }

        return null;
    }

    private function access(): CommunityAccess
    {
        return $this->access ??= CommunityAccess::instance();
    }

    /** UTC-Text einer Unix-Zeit (einzige Umrechnung des Dienstes). */
    private static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
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
