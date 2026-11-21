<?php
/**
 * Community-Sperren (PR #2b): Sperren, Aufheben, Verlauf, Liste und die wirksame Sperre eines Mitglieds.
 *
 * Grundsätze (Vertrag DESIGN-2b §1, §3.10, §4.4; Entwurf sanktionen §1.4, §2.5 bis §2.7):
 * - Rechte: `ban_temporary` (`community.moderate`) sperrt befristet bis `moderator_ban_max_days`; dauerhaft und länger nur `ban`
 *   (`community.ban`). Nie gegen Gleich- oder Höherrangige (`CommunityAccess::outranks()`), nie sich selbst. Eine Sperre braucht
 *   immer einen Grund; die interne Notiz sehen nur Ranghöhere, nie das Mitglied, nie Hooks oder Protokoll.
 * - Aufheben: eigene Sperre mit `ban_temporary` oder `ban`, fremde nur mit `ban`; zusätzlich Rang über dem Betroffenen und bei
 *   fremden Sperren mindestens der Rang des Ausstellers (fehlender Aussteller zählt 0). Beides vorab und unter der Sperre frisch.
 * - Feste Prüfreihenfolge: Eingaben ohne Datenbank → Uhr (genau eine Ablesung je öffentlicher Methode) → Rechte-Tor → fachliche
 *   Codes (`ban_duration`, `ban_forbidden`, `member_not_found`, `ban_not_found`, `already_banned`).
 * - Serialisierung an der Statistikzeile des **Betroffenen** (Ebene 4, angelegt im Autocommit vor `BEGIN`): zwei Moderatoren
 *   gleichzeitig ergeben genau eine Sperre, eine gleichzeitige Antwort des Mitglieds wartet und sieht danach die Sperre, Aufheben
 *   und Sperren desselben Mitglieds laufen nacheinander. In `community_bans` gibt es keine Sperre (keine Lückensperren).
 * - Deckung: eine wirksame Sperre mit mindestens gleichem Umfang und mindestens gleichem Ende (Toleranz COVER_TOLERANCE) ergibt
 *   `already_banned`; vom selben Akteur binnen der Toleranz `duplicate` (kein Schreiben, kein Hook). Eine strengere wird angelegt.
 * - Nach dem Commit: `forgetUser()` des Betroffenen (Zwischenspeicher der Rechte), dann der Hook. Datenbankfehler: Rücknahme,
 *   Warnung, `db_error`. Zeit: UTC, einzige Umrechnung über `utc()`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\BanRepository;
use CMS\Services\Community\Repository\MemberRepository;
use CMS\Services\Community\Repository\ModerationLogRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

final class BanService
{
    /** Umfänge (Sprachschlüssel `community.ban.scope.<umfang>`): `write` = nur lesen, `full` = kein Zugriff. */
    public const SCOPES = BanRepository::SCOPES;
    /** Kürzeste befristete Sperre in Sekunden (1 Stunde). */
    public const MIN_DURATION = 3600;
    /** Längste befristete Sperre in Sekunden (3650 Tage, mit `community.ban`). */
    public const MAX_DURATION = 315360000;
    /** Zeichen der internen Notiz. */
    public const NOTE_MAX = 2000;
    /** Sekunden: eine bestehende Sperre, die höchstens so viel früher endet, gilt als gleich (Uhrenabweichung, Doppelklick). */
    public const COVER_TOLERANCE = 300;
    /** Standard und Obergrenze von `history()` und `listActive()`. */
    public const HISTORY_LIMIT = 50;

    /** Strenge der Umfänge für die Deckung. */
    private const SCOPE_RANK = ['write' => 1, 'full' => 2];
    /** Größte ID einer INT-UNSIGNED-Spalte (Cursor). */
    private const MAX_UINT = 4294967295;

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
    private BanRepository $bans;
    private MemberRepository $members;
    private UserStatsRepository $stats;
    private ModerationLogRepository $log;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`), genau eine Ablesung je öffentlicher Methode
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard: `CommunityAccess::can()` der Instanz aus `$access`
     * @param \Closure(string, mixed...): void|null $dispatch Standard: `Events::fire()`
     * @param CommunityAccess|null $access `outranks()`, `authority()`, `forgetUser()`; Standard `CommunityAccess::instance()` (erst beim ersten Bedarf)
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $clock = null,
        ?\Closure $can = null,
        ?\Closure $dispatch = null,
        ?CommunityAccess $access = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->access = $access;
        $this->can = $can ?? fn (?int $userId, string $action, array $context): AccessDecision => $this->access()->can($userId, $action, $context);
        $this->dispatch = $dispatch ?? static function (string $hook, mixed ...$args): void {
            Events::fire($hook, ...$args);
        };
        $this->bans = new BanRepository($this->db);
        $this->members = new MemberRepository($this->db);
        $this->stats = new UserStatsRepository($this->db);
        $this->log = new ModerationLogRepository($this->db);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Sperren ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Sperrt ein Mitglied (`$durationSeconds` null = dauerhaft, sonst MIN_DURATION bis MAX_DURATION).
     *
     * Erfolg `{success: true, ban_id, user_id, scope, starts_at, ends_at}`, beim Doppelklick zusätzlich `duplicate: true` (bestehende
     * Sperre, kein Hook). Fehler: `invalid_input`, `reason_required`, Gründe von `can('ban_temporary')`, `ban_duration` (mit
     * `max_days`), `ban_forbidden`, `member_not_found`, `already_banned` (mit `ban_id`), `db_error`.
     *
     * @return array<string, mixed>
     */
    public function ban(int $actorId, int $userId, string $scope, ?int $durationSeconds, string $reason, string $internalNote = ''): array
    {
        if ($actorId < 1 || $userId < 1 || !in_array($scope, self::SCOPES, true)
            || ($durationSeconds !== null && ($durationSeconds < self::MIN_DURATION || $durationSeconds > self::MAX_DURATION))
        ) {
            return self::failure('invalid_input');
        }
        $reason = PostService::normalizeReason($reason);
        if ($reason === null) {
            return self::failure('reason_required');
        }
        $note = PostService::normalizeBody($internalNote);
        if ($note === null || mb_strlen($note, 'UTF-8') > self::NOTE_MAX) {
            return self::failure('invalid_input');
        }

        $nowTs = ($this->clock)();
        $now = self::utc($nowTs);
        try {
            $gate = $this->banGate($actorId, $durationSeconds, ['now' => $nowTs]);
            if ($gate !== null) {
                return $gate;
            }
            if ($actorId === $userId) {
                return self::failure('ban_forbidden');
            }
            if ($this->members->find($userId) === null) {
                return self::failure('member_not_found');
            }
            if (!$this->access()->outranks($actorId, $userId)) {
                return self::failure('ban_forbidden');
            }
            // Statistikzeile des Betroffenen im Autocommit: die Sperre trifft eine vorhandene Zeile (keine Lückensperre).
            $this->stats->ensure($userId, $now);
            $result = Transaction::run($this->db, fn (): array => $this->banLocked($actorId, $userId, $scope, $durationSeconds, $reason, $note, $nowTs, $now));
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('user.ban', $e, ['user_id' => $userId]);
        }
        if (empty($result['success']) || !empty($result['duplicate'])) {
            return $result;
        }

        $this->forget($userId);
        $this->fire(Events::USER_BANNED, $userId, (int) $result['ban_id'], $scope, $actorId);

        return $result;
    }

    /**
     * Transaktion von `ban()`: Statistik des Betroffenen sperren, Rechte und Rang frisch, Deckung, Anlegen, Protokoll.
     *
     * @return array<string, mixed>
     */
    private function banLocked(int $actorId, int $userId, string $scope, ?int $durationSeconds, string $reason, string $note, int $nowTs, string $now): array
    {
        $this->stats->lockForUpdate($userId, $now);

        $gate = $this->banGate($actorId, $durationSeconds, ['fresh' => true, 'now' => $nowTs]);
        if ($gate !== null) {
            return $gate;
        }
        if (!$this->access()->outranks($actorId, $userId)) {
            return self::failure('ban_forbidden');
        }
        $endsAt = $durationSeconds === null ? null : self::utc($nowTs + $durationSeconds);
        foreach ($this->bans->activeList($userId, $now) as $existing) {
            if (!$this->covers($existing, $scope, $durationSeconds, $nowTs)) {
                continue;
            }
            if ($this->duplicate($existing, $actorId, $scope, $endsAt, $nowTs)) {
                $row = $this->bans->find($existing['id']) ?? ['starts_at' => $existing['created_at'], 'ends_at' => $existing['ends_at']];

                return ['success' => true, 'ban_id' => $existing['id'], 'user_id' => $userId, 'scope' => $existing['scope'],
                    'starts_at' => (string) $row['starts_at'], 'ends_at' => $row['ends_at'], 'duplicate' => true];
            }

            return ['success' => false, 'error' => 'already_banned', 'ban_id' => $existing['id']];
        }

        $banId = $this->bans->insert([
            'user_id' => $userId,
            'scope' => $scope,
            'reason' => $reason,
            'internal_note' => $note,
            'issued_by' => $actorId,
            'starts_at' => $now,
            'ends_at' => $endsAt,
            'created_at' => $now,
        ]);
        $this->log->add([
            'actor_id' => $actorId,
            'action' => 'user.ban',
            'target_type' => 'user',
            'target_id' => $userId,
            'forum_id' => null,
            'reason' => $reason,
            'details' => ['ban_id' => $banId, 'scope' => $scope, 'ends_at' => $endsAt, 'duration' => $durationSeconds],
            'created_at' => $now,
        ]);

        return ['success' => true, 'ban_id' => $banId, 'user_id' => $userId, 'scope' => $scope, 'starts_at' => $now, 'ends_at' => $endsAt];
    }

    /**
     * Rechte-Tor von `ban()`: `ban_temporary` oder `ban`, sonst der Grund von `ban_temporary`; dauerhaft oder länger als
     * `moderator_ban_max_days` nur mit `ban` (`ban_duration` mit `max_days`). `null` = erlaubt.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function banGate(int $actorId, ?int $durationSeconds, array $context): ?array
    {
        [$temp, $full] = $this->rights($actorId, $context);
        if (!($temp->allowed || $full->allowed)) {
            return self::denied($temp);
        }
        $maxModerator = $this->settings->int('moderator_ban_max_days') * 86400;
        $needsBan = $durationSeconds === null || $durationSeconds > $maxModerator;
        if ($needsBan && !$full->allowed) {
            return ['success' => false, 'error' => 'ban_duration', 'max_days' => intdiv($maxModerator, 86400)];
        }

        return null;
    }

    /**
     * Deckt eine wirksame Sperre die neue? Umfang mindestens so streng und Ende mindestens so spät (Toleranz COVER_TOLERANCE;
     * dauerhaft deckt alles, befristet deckt keine dauerhafte). Vergleich der UTC-Texte.
     *
     * @param array{id: int, scope: string, ends_at: string|null, issued_by: int|null, created_at: string} $existing
     */
    private function covers(array $existing, string $scope, ?int $durationSeconds, int $nowTs): bool
    {
        if ((self::SCOPE_RANK[$existing['scope']] ?? 2) < self::SCOPE_RANK[$scope]) {
            return false;
        }
        if ($existing['ends_at'] === null) {
            return true;
        }

        return $durationSeconds !== null && $existing['ends_at'] >= self::utc($nowTs + $durationSeconds - self::COVER_TOLERANCE);
    }

    /**
     * Doppelklick: dieselbe Sperre desselben Akteurs (gleicher Umfang, gleiche Art, Ende innerhalb der Toleranz), angelegt vor
     * höchstens COVER_TOLERANCE Sekunden.
     *
     * @param array{id: int, scope: string, ends_at: string|null, issued_by: int|null, created_at: string} $existing
     */
    private function duplicate(array $existing, int $actorId, string $scope, ?string $endsAt, int $nowTs): bool
    {
        if ($existing['issued_by'] !== $actorId || $existing['scope'] !== $scope || ($existing['ends_at'] === null) !== ($endsAt === null)) {
            return false;
        }
        if ($endsAt !== null) {
            $end = CommunityAccess::utcTimestamp($existing['ends_at']);
            $wanted = CommunityAccess::utcTimestamp($endsAt);
            if ($end === null || $wanted === null || abs($end - $wanted) > self::COVER_TOLERANCE) {
                return false;
            }
        }

        return $existing['created_at'] >= self::utc($nowTs - self::COVER_TOLERANCE);
    }

    // ── Aufheben ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Hebt eine Sperre auf. Erfolg `{success: true, ban_id, user_id, changed}`; `changed: false` bei schon aufgehobener oder
     * abgelaufener Sperre (kein Protokoll, kein Hook). Fehler: `invalid_input`, Gründe von `can('ban_temporary')`, `ban_not_found`,
     * `no_permission` (fremde Sperre ohne `community.ban`), `ban_forbidden` (Rang), `db_error`.
     *
     * @return array<string, mixed>
     */
    public function lift(int $actorId, int $banId, string $reason = ''): array
    {
        if ($actorId < 1 || $banId < 1) {
            return self::failure('invalid_input');
        }
        $reason = PostService::normalizeReason($reason);

        $nowTs = ($this->clock)();
        $now = self::utc($nowTs);
        try {
            [$temp, $full] = $this->rights($actorId, ['now' => $nowTs]);
            if (!$temp->allowed && !$full->allowed) {
                return self::denied($temp);
            }
            $ban = $this->bans->find($banId);
            if ($ban === null) {
                return self::failure('ban_not_found');
            }
            $own = (int) ($ban['issued_by'] ?? 0) === $actorId;
            $refused = $this->liftRefused($actorId, $ban, $own, $full);
            if ($refused !== null) {
                return $refused;
            }
            $targetId = (int) $ban['user_id'];
            // Statistikzeile des Betroffenen im Autocommit (wie beim Sperren): Aufheben und Sperren laufen nacheinander.
            $this->stats->ensure($targetId, $now);
            $result = Transaction::run($this->db, fn (): array => $this->liftLocked($actorId, $banId, $targetId, $own, $reason, $nowTs, $now));
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('user.unban', $e, ['ban_id' => $banId]);
        }
        if (empty($result['success']) || empty($result['changed'])) {
            return $result;
        }

        $this->forget($targetId);
        $this->fire(Events::USER_UNBANNED, $targetId, $banId, $actorId);

        return $result;
    }

    /**
     * Transaktion von `lift()`: Statistik des Betroffenen sperren, Sperre neu lesen, Rechte und Rang frisch, Ablauf, Aufheben
     * (einzige Prüfung auf „schon aufgehoben“), Protokoll.
     *
     * @return array<string, mixed>
     */
    private function liftLocked(int $actorId, int $banId, int $targetId, bool $own, ?string $reason, int $nowTs, string $now): array
    {
        $this->stats->lockForUpdate($targetId, $now);

        $ban = $this->bans->find($banId);
        if ($ban === null) {
            return self::failure('ban_not_found');
        }
        [$temp, $full] = $this->rights($actorId, ['fresh' => true, 'now' => $nowTs]);
        if (!($temp->allowed || $full->allowed)) {
            return self::denied($temp);
        }
        $refused = $this->liftRefused($actorId, $ban, $own, $full);
        if ($refused !== null) {
            return $refused;
        }
        $unchanged = ['success' => true, 'ban_id' => $banId, 'user_id' => $targetId, 'changed' => false];
        if ($ban['ends_at'] !== null && (string) $ban['ends_at'] <= $now) {
            return $unchanged;
        }
        if (!$this->bans->lift($banId, $actorId, $now)) {
            return $unchanged;
        }
        $this->log->add([
            'actor_id' => $actorId,
            'action' => 'user.unban',
            'target_type' => 'user',
            'target_id' => $targetId,
            'forum_id' => null,
            'reason' => $reason,
            'details' => ['ban_id' => $banId, 'scope' => (string) $ban['scope']],
            'created_at' => $now,
        ]);

        return ['success' => true, 'ban_id' => $banId, 'user_id' => $targetId, 'changed' => true];
    }

    /**
     * Fachliche Ablehnung des Aufhebens: fremde Sperre ohne `community.ban` (`no_permission`), Rang (`ban_forbidden`). `null` = erlaubt.
     *
     * @param array<string, mixed> $ban
     * @return array{success: false, error: string}|null
     */
    private function liftRefused(int $actorId, array $ban, bool $own, AccessDecision $full): ?array
    {
        if (!$own && !$full->allowed) {
            return self::failure('no_permission');
        }

        return $this->liftDenied($actorId, $ban, $own) ? self::failure('ban_forbidden') : null;
    }

    /**
     * Rang beim Aufheben (Vertrag §4.4, S11): der Akteur steht über dem Betroffenen; bei fremden Sperren hat er mindestens den Rang
     * des Ausstellers (fehlender oder gelöschter Aussteller zählt 0, ein Lesefehler beim Akteur ebenso).
     *
     * @param array<string, mixed> $ban
     */
    private function liftDenied(int $actorId, array $ban, bool $own): bool
    {
        $foreign = !$own;
        $issuer = isset($ban['issued_by']) ? (int) $ban['issued_by'] : null;
        if (!$this->access()->outranks($actorId, (int) $ban['user_id'])) {
            return true;
        }
        if ($foreign && ($this->access()->authority($actorId) ?? 0) < ($this->access()->authority($issuer) ?? 0)) {
            return true;
        }

        return false;
    }

    // ── Lesen ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Wirksame Sperre eines Mitglieds `{id, scope, reason, ends_at}` (nie die interne Notiz) oder `null`. Grund und Ende nur für das
     * Mitglied selbst oder mit `ban_temporary`/`ban`; sonst `null`, ebenso bei Fehlern.
     *
     * @return array{id: int, scope: string, reason: string, ends_at: string|null}|null
     */
    public function active(int $viewerId, int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
        $nowTs = ($this->clock)();
        try {
            if ($viewerId !== $userId && !$this->mayViewBans($viewerId, $nowTs)) {
                return null;
            }
            $ban = $this->bans->activeFor($userId, self::utc($nowTs));
        } catch (\PDOException | \RuntimeException $e) {
            $this->warn('Sperre nicht lesbar: {exception}', ['exception' => $e]);

            return null;
        }

        return $ban === null ? null : ['id' => $ban['id'], 'scope' => $ban['scope'], 'reason' => $ban['reason'], 'ends_at' => $ban['ends_at']];
    }

    /**
     * Verlauf eines Mitglieds, jüngste zuerst (höchstens HISTORY_LIMIT), mit Zustand `active|scheduled|expired|lifted`. Die interne
     * Notiz nur für Ranghöhere (nie für das Mitglied selbst oder Gleichrangige), sonst `null`.
     *
     * @return array<string, mixed> `{success: true, items}`; Fehler `invalid_input`, Gründe von `can('ban_temporary')`, `db_error`
     */
    public function history(int $viewerId, int $userId, ?int $limit = null): array
    {
        if ($userId < 1) {
            return self::failure('invalid_input');
        }
        $limit = max(1, min(self::HISTORY_LIMIT, $limit ?? self::HISTORY_LIMIT));
        $nowTs = ($this->clock)();
        $now = self::utc($nowTs);
        try {
            [$temp, $full] = $this->rights($viewerId, ['now' => $nowTs]);
            $mayView = $temp->allowed || $full->allowed;
            if (!$mayView) {
                return self::denied($temp);
            }
            $withNote = $mayView && $this->access()->outranks($viewerId, $userId);
            $rows = $this->bans->forUser($userId, $limit, $withNote);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('ban.history', $e, ['user_id' => $userId]);
        }
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => $row['id'],
                'scope' => $row['scope'],
                'reason' => $row['reason'],
                'internal_note' => $withNote ? ($row['internal_note'] ?? null) : null,
                'issued_by' => $row['issued_by'],
                'starts_at' => $row['starts_at'],
                'ends_at' => $row['ends_at'],
                'lifted_at' => $row['lifted_at'],
                'lifted_by' => $row['lifted_by'],
                'created_at' => $row['created_at'],
                'state' => self::state($row, $now),
            ];
        }

        return ['success' => true, 'items' => $items];
    }

    /**
     * Wirksame Sperren aller Mitglieder, neueste zuerst, Keyset über die ID (Cursor = Ziffernfolge, ungültig ⇒ erste Seite).
     * Rechte wie `history()`; ohne interne Notiz.
     *
     * @return array<string, mixed> `{success: true, items, next, has_more}`
     */
    public function listActive(int $viewerId, ?string $cursor = null, ?int $limit = null): array
    {
        $limit = max(1, min(self::HISTORY_LIMIT, $limit ?? self::HISTORY_LIMIT));
        $before = $cursor !== null && preg_match('/^[1-9][0-9]{0,9}$/D', $cursor) === 1 && (int) $cursor <= self::MAX_UINT ? (int) $cursor : 0;
        $nowTs = ($this->clock)();
        try {
            [$temp, $full] = $this->rights($viewerId, ['now' => $nowTs]);
            if (!($temp->allowed || $full->allowed)) {
                return self::denied($temp);
            }
            $rows = $this->bans->listActive(self::utc($nowTs), $before, $limit + 1);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('ban.list', $e, ['viewer_id' => $viewerId]);
        }
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = end($rows);

        return ['success' => true, 'items' => $rows, 'next' => $hasMore && $last !== false ? (string) $last['id'] : null, 'has_more' => $hasMore];
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * `can('ban_temporary')` und `can('ban')` des Akteurs.
     *
     * @param array<string, mixed> $context
     * @return array{0: AccessDecision, 1: AccessDecision}
     */
    private function rights(int $actorId, array $context): array
    {
        $user = $actorId > 0 ? $actorId : null;

        return [($this->can)($user, 'ban_temporary', $context), ($this->can)($user, 'ban', $context)];
    }

    /** Darf der Betrachter Sperren anderer sehen (`ban_temporary` oder `ban`)? */
    private function mayViewBans(int $viewerId, int $nowTs): bool
    {
        [$temp, $full] = $this->rights($viewerId, ['now' => $nowTs]);

        return $temp->allowed || $full->allowed;
    }

    /** Zustand einer Sperre zum Zeitpunkt (Vergleich der UTC-Texte). @param array<string, mixed> $row */
    private static function state(array $row, string $now): string
    {
        if ($row['lifted_at'] !== null) {
            return 'lifted';
        }
        if ((string) $row['starts_at'] > $now) {
            return 'scheduled';
        }

        return $row['ends_at'] !== null && (string) $row['ends_at'] <= $now ? 'expired' : 'active';
    }

    /** Zwischenspeicher der Rechte des Betroffenen verwerfen (nach dem Commit; einzige Stelle). */
    private function forget(int $userId): void
    {
        $this->access()->forgetUser($userId);
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
