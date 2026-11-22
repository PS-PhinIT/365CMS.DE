<?php
/**
 * Themen der Community (PR #2a): anlegen (Thema und erster Beitrag), weich löschen, Themenliste eines Forums, Thema lesen.
 *
 * Grundsätze (Entwurfsvertrag PR #2a, §1; Karte §3.0, §3.1, §3.5, §3.6) wie bei `PostService`:
 * - `can()` vorab und unter den Sperren erneut mit `fresh = true`; Sperrreihenfolge Forum → Thema → Beitrag → Mitglied;
 *   Zähler nur atomar; Hooks nach dem Commit über `Events::fire()`; Zeit UTC (`gmdate`), Uhr je Aufruf einmal.
 * - Mitgliedersperre vor der ersten nicht sperrenden Leseanweisung der Transaktion, sonst sieht `can(fresh)` einen veralteten
 *   Schnappschuss (REPEATABLE READ legt ihn bei der ersten nicht sperrenden Abfrage an; ein gleichzeitig committeter Beitrag
 *   desselben Mitglieds in einem anderen Forum bliebe unsichtbar und der Abstand `rate_post_seconds` ließe sich umgehen).
 *   Reihenfolge in der Transaktion von `create()`: Forum sperren → Mitglied sperren → Dublettenprüfung → `can(fresh)` → Schreiben.
 * - Die Statistikzeile des Mitglieds entsteht VOR der Transaktion im Autocommit (`UserStatsRepository::ensure()`), damit die
 *   Mitgliedersperre in der Transaktion eine vorhandene Zeile trifft (reine Zeilensperre, keine Lückensperre, keine Deadlocks
 *   bei erstmals schreibenden Mitgliedern). Abgelehnte Aufrufe legen nichts an.
 * - `create()` schreibt Thema, ersten Beitrag, Zähler von Forum und Mitglied und den Lesestatus des Autors in einer
 *   Transaktion. Doppeltes Absenden (gleicher Titel und Text im selben Forum innerhalb von `duplicate_window_seconds`)
 *   liefert dasselbe Ergebnis mit `duplicate = true`. Lehnt die erste Rechteprüfung mit einem Grenzgrund (`LIMIT_REASONS`:
 *   `rate_limited`, `slow_mode`, `trust_limit`, `pending_limit`) ab, ist die Ablehnung vorläufig: eine Dublettenprüfung ohne
 *   Sperre (dieselbe Logik wie unter der Sperre) liefert bei Treffer das vorhandene Ergebnis, sonst wird die Ablehnung sofort
 *   zurückgegeben (ohne Transaktion, ohne Sperren). Alle anderen Gründe (Recht, Sperre, Zustand, Modul) sind endgültig und
 *   werden nie von einer Dublette überstimmt. Wirklich gleichzeitige Dubletten erkennt die Prüfung unter der Forum-Sperre.
 *   `last_post_at` ist nie NULL (auch bei wartenden Themen), `last_post_id` und `last_poster_id` erst, wenn das Thema
 *   veröffentlicht ist.
 * - Wartende Themen zählen nirgends (Forum, Mitglied); die Freigabe in PR #2b bucht nach, einschließlich „letzter Beitrag“.
 * - `delete()` löscht weich (`status = deleted`, `previous_status`, `trashed_at`, `trashed_by`); die Beiträge des Themas bleiben
 *   unverändert. Zähler bucht `CounterBook::topicUnpublished()` (Semantik S1): Forum `topic_count − 1` und `post_count − Beiträge`,
 *   jeder Autor veröffentlichter Beiträge `post_count − n`, der Themenstarter zusätzlich `topic_count − 1`. Das Wiederherstellen
 *   aus dem Papierkorb (`TopicTrashService`) bucht `topicPublished()` als genaue Umkehrung.
 * - Schutzschichten (nur mit Guard, `WriteGate`): Wortfilter und Zählung vor `can()` (`content` im Kontext), `screen()` nach erlaubtem `can()` und vor
 *   `ensure()` und der Transaktion (`word_blocked`, `spam_rejected`; `review` ⇒ `pending` über `can(fresh)`), `ip_hash` am ersten Beitrag, Sicherheitsprotokoll nach dem Vorgang.
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

    /**
     * Zeit- und Zählgrenzen von `can()`: Eine Ablehnung aus diesen Gründen ist vorläufig. Wer dasselbe zum zweiten Mal absendet,
     * bekommt statt der Ablehnung das vorhandene Ergebnis (`duplicate = true`), weil der erste Absender die Grenze selbst
     * ausgelöst hat. Alle anderen Ablehnungsgründe sind endgültig.
     */
    public const LIMIT_REASONS = ['rate_limited', 'slow_mode', 'trust_limit', 'pending_limit'];

    /** Ablehnungsgründe von `can('delete')` für ein bereits gelöschtes Thema (Moderation: `topic_deleted`, Autor: `not_found`). */
    private const REPEAT_REASONS = ['not_found', 'topic_deleted'];

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
    private CounterBook $book;
    private WriteGate $gate;
    private ?AttachmentService $attachmentService = null;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard: `CommunityAccess::instance()->can()`; ohne diese Klasse wird alles abgelehnt
     * @param \Closure(string, mixed...): void|null $dispatch Standard: `Events::fire()`
     * @param \Closure(): ?ContentGuard|null $guard Inhaltsprüfung vor dem Schreiben (`WriteGate`); Standard: keine (inaktiv). `instance()` übergibt `ContentGuard::instance()`
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $clock = null,
        ?\Closure $can = null,
        ?\Closure $dispatch = null,
        ?\Closure $guard = null
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
        $this->book = new CounterBook($this->db);
        $this->gate = new WriteGate($guard);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(guard: static fn (): ContentGuard => ContentGuard::instance());
    }

    // ── Reine Regeln ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Titel: Steuerzeichen entfernt, jede Folge von Leerraum (auch Tabulator, Zeilenumbruch, geschützte Leerzeichen) ein
     * Leerzeichen, Rand getrimmt; unsichtbare Zeichen, die Titel fälschen (Formatzeichen der Kategorie Cf: Nullbreitenzeichen,
     * Richtungsumschalter, weiche Trennstriche, BOM, Tag-Zeichen; Näheres bei `PostService::stripFormatCharacters()`), entfallen.
     * Bleibt kein sichtbares Zeichen übrig (Leerraum, Hangul-Füller, Braille-Leerzeichen, nur Akzentzeichen), ist der Titel leer
     * und damit `title_too_short`. `null` bei ungültigem UTF-8. Die Länge prüft der Aufrufer gegen `title_min`/`title_max`.
     */
    public static function normalizeTitle(string $title): ?string
    {
        if (!mb_check_encoding($title, 'UTF-8')) {
            return null;
        }
        $title = PostService::stripFormatCharacters($title);
        $title = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $title);
        $title = trim((string) preg_replace('/\p{Cc}/u', '', $title));

        return PostService::hasVisibleText($title) ? $title : '';
    }

    // ── Anlegen ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Thema mit erstem Beitrag anlegen. `$options`: `attachments` (Liste der IDs hochgeladener, noch nicht zugeordneter eigener
     * Anhänge aus dem Formular; im Text eingebettete `attachment:ID` werden zusätzlich zugeordnet). Die Zuordnung plant
     * `AttachmentService::prepareClaim()` vor der Transaktion; darin sperrt der Dienst die Anhänge nach der Statistikzeile, prüft sie
     * nach `can(fresh)` erneut und ordnet sie nach dem Schreiben des Beitrags zu (Fehler `attachment_not_found`, `attachment_limit`).
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
        $error = $this->lengthError($title, $body);
        if ($error !== null) {
            return self::failure($error);
        }
        // Wortfilter, Zählung und Hosts (nur mit Guard, nur für Mitglieder); ersetzte Wörter ändern die Längen.
        $check = $userId > 0 ? $this->gate->inspect($title, $body) : null;
        if ($check !== null && $check['replaced']) {
            $title = (string) $check['title'];
            $body = $check['body'];
            $error = $this->lengthError($title, $body);
            if ($error !== null) {
                return self::failure($error);
            }
        }
        $listed = AttachmentService::claimIds($options['attachments'] ?? []);
        if ($listed === null) {
            return self::failure('invalid_input');
        }
        $referenced = $check['attachment_ids'] ?? [];
        $check = WriteGate::withAttachmentCount($check, count(array_unique([...$listed, ...$referenced])));

        $nowTs = ($this->clock)();
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        $since = gmdate('Y-m-d H:i:s', $nowTs - $this->settings->int('duplicate_window_seconds'));
        $decision = $this->decide($userId, 'create_topic', ['forum_id' => $forumId, 'now' => $nowTs] + $this->gate->context($check));
        if (!$decision->allowed) {
            $this->gate->reject($check, $decision->reason, $userId);
            if ($userId < 1 || !in_array($decision->reason, self::LIMIT_REASONS, true)) {
                return self::denied($decision);
            }
            // Grenzgrund: vorläufig. Eine Dublette ohne Sperre liefert das vorhandene Thema, sonst gilt die Ablehnung (ohne Transaktion).
            try {
                $duplicate = $this->topics->findRecentDuplicate($userId, $forumId, $title, $since, $body);
            } catch (\PDOException | \RuntimeException $e) {
                return $this->databaseError('topic.create', $e, ['forum_id' => $forumId]);
            }

            return $duplicate !== null ? self::duplicateResult($duplicate, $forumId) : self::denied($decision);
        }
        if ($userId < 1) {
            return self::failure('invalid_input');
        }

        try {
            // Schutzschichten nach erlaubtem can(), vor ensure() und der Transaktion (Vertrag §4.1 Schritt 11).
            $screen = null;
            if ($check !== null) {
                $screen = $this->gate->screen($check, $userId, 'create_topic', ['forum_id' => $forumId], $nowTs);
                if (!$screen['ok']) {
                    $this->gate->reject($check, $screen['error'], $userId);

                    return self::failure($screen['error']);
                }
            }
            if ($this->forums->find($forumId) === null) {
                return self::failure('forum_not_found');
            }
            $plan = AttachmentService::emptyPlan();
            if ($listed !== [] || $referenced !== []) {
                $prepared = $this->attachments()->prepareClaim($userId, $listed, $referenced);
                if (!$prepared['ok']) {
                    return self::failure($prepared['error']);
                }
                $plan = $prepared['plan'];
            }
            // Statistikzeile vor der Transaktion (Autocommit): die Mitgliedersperre trifft dann eine vorhandene Zeile.
            $this->stats->ensure($userId, $now);
            $result = Transaction::run($this->db, fn (): array => $this->createLocked($userId, $forumId, $title, $body, $nowTs, $now, $since, $check, $screen, $plan));
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('topic.create', $e, ['forum_id' => $forumId]);
        }
        if (empty($result['success'])) {
            $this->gate->reject($check, (string) ($result['error'] ?? ''), $userId); // nach dem Ende der Transaktion
        }
        if (!empty($result['success']) && empty($result['duplicate'])) {
            $this->fire(Events::TOPIC_CREATED, (int) $result['topic_id'], $forumId, $userId, (string) $result['status'], (int) $result['post_id']);
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|null $check Ergebnis von `WriteGate::inspect()`
     * @param array<string, mixed>|null $screen Ergebnis von `WriteGate::screen()` (`ok = true`)
     * @param array<string, mixed>|null $plan Zuordnungsplan aus `AttachmentService::prepareClaim()`
     * @return array<string, mixed>
     */
    private function createLocked(int $userId, int $forumId, string $title, string $body, int $nowTs, string $now, string $since, ?array $check = null, ?array $screen = null, ?array $plan = null): array
    {
        $plan ??= AttachmentService::emptyPlan();
        // Sperrreihenfolge: Forum → (Thema, Beitrag: neu) → Mitglied. Mitgliedersperre vor der ersten nicht sperrenden
        // Leseanweisung der Transaktion (Dublettenprüfung, `can(fresh)`): der Schnappschuss von REPEATABLE READ entsteht erst
        // danach und sieht jede Antwort, die ein gleichzeitiger Aufruf desselben Mitglieds in einem anderen Forum committet hat.
        if ($this->forums->lockForUpdate($forumId) === null) {
            return self::failure('forum_not_found');
        }
        $this->stats->lockForUpdate($userId, $now);

        // Doppeltes Absenden zuerst: wer ein zweites Mal absendet, bekommt das Ergebnis des ersten Mals, nicht „zu schnell“.
        $duplicate = $this->topics->findRecentDuplicate($userId, $forumId, $title, $since, $body);
        if ($duplicate !== null) {
            return self::duplicateResult($duplicate, $forumId);
        }
        // Anhänge: Sperrebene 5 nach der Statistik.
        $lockedAttachments = $plan['lock'] === [] ? [] : $this->attachments()->lock($plan);

        $decision = $this->decide($userId, 'create_topic', ['forum_id' => $forumId, 'now' => $nowTs, 'fresh' => true] + $this->gate->context($check, $screen));
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        $attachmentError = $plan['lock'] === [] ? null : AttachmentService::verify($plan, $lockedAttachments, $userId, null);
        if ($attachmentError !== null) {
            return self::failure($attachmentError);
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
            'ip_hash' => $screen['ip_hash'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->topics->update($topicId, $published
            ? ['first_post_id' => $postId, 'last_post_id' => $postId, 'last_poster_id' => $userId]
            : ['first_post_id' => $postId]);
        if ($plan['ids'] !== []) {
            $this->attachments()->apply($plan, $postId, $userId);
            $this->attachments()->syncTopicFlag(['id' => $topicId, 'has_attachments' => 0], $published, $plan, $now);
        }
        if ($published) {
            // Nur veröffentlichte Themen zählen; die Freigabe (PR #2b) bucht später nach.
            $this->forums->bumpCounters($forumId, 1, 1, $now, $postId, $topicId, $userId, $now);
            $this->stats->bump($userId, 1, 1, $now);
        }
        $this->stats->touchLastPost($userId, $now);
        $this->readStates->advance($userId, $topicId, $postId, $now);

        return ['success' => true, 'topic_id' => $topicId, 'post_id' => $postId, 'forum_id' => $forumId, 'status' => $status, 'slug' => $slug];
    }

    /** Anhangsdienst auf derselben Verbindung, erst bei Bedarf (Themen ohne Anhänge berühren ihn nie). */
    private function attachments(): AttachmentService
    {
        return $this->attachmentService ??= new AttachmentService($this->db, $this->settings);
    }

    /** Fehlercode zu den Längen von Titel und Text (`title_min`/`title_max`, `post_min`/`post_max`), sonst null. */
    private function lengthError(string $title, string $body): ?string
    {
        $length = mb_strlen($title, 'UTF-8');
        if ($length < $this->settings->int('title_min')) {
            return 'title_too_short';
        }
        if ($length > $this->settings->int('title_max')) {
            return 'title_too_long';
        }
        $length = mb_strlen($body, 'UTF-8');
        if ($length < $this->settings->int('post_min')) {
            return 'body_too_short';
        }

        return $length > $this->settings->int('post_max') ? 'body_too_long' : null;
    }

    /**
     * Ergebnis für ein doppelt abgesendetes Thema: dasselbe wie beim ersten Mal, dazu `duplicate = true` (nichts geschrieben).
     *
     * @param array{id: int, first_post_id: int|null, status: string, forum_id: int, title: string} $duplicate
     * @return array<string, mixed>
     */
    private static function duplicateResult(array $duplicate, int $forumId): array
    {
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

    // ── Löschen ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Thema weich löschen (`status = deleted`, `previous_status` merkt den Vorwert). Der Autor darf nur ein Thema ohne
     * Antworten löschen (`has_replies`); die Moderation (`can('moderate')`) auch eines mit Antworten. War das Thema
     * veröffentlicht, sinken die Zähler laut Matrix und „letztes Thema/letzter Beitrag“ des Forums werden neu bestimmt,
     * falls das Thema es war. Wiederholtes Löschen ist wirkungslos (`already_deleted = true`): ein bereits gelöschtes Thema
     * lehnt `can('delete')` ab (`topic_deleted` für die Moderation, `not_found` für den Autor, dem ein gelöschtes Thema nicht
     * mehr sichtbar ist). Beides gilt hier nicht als Fehler, wenn der Handelnde der Themenstarter ist oder das Forum moderiert
     * (`repeatedDelete()`), damit ein Doppelklick auf „Thema löschen“ nach dem Erfolg keine Fehlermeldung zeigt; jede andere
     * Ablehnung (Sperre, Modul aus, kein Konto, fremdes Thema) bleibt eine Ablehnung.
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
            if ($userId > 0 && in_array($decision->reason, self::REPEAT_REASONS, true)) {
                $repeat = $this->repeatedDelete($userId, $topicId, $nowTs);
                if ($repeat !== null) {
                    return $repeat;
                }
            }

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
                // Statistikzeilen der Autoren veröffentlichter Beiträge und des Themenstarters vor der Transaktion (Autocommit): die
                // Mitgliedersperre trifft dann vorhandene Zeilen (keine Lückensperre).
                $plan = $this->book->plan($topicId, $topic['user_id'] === null ? null : (int) $topic['user_id']);
                $this->book->prepare($plan, $now);
                $result = Transaction::run($this->db, fn (): ?array => $this->deleteLocked($userId, $topicId, $forumId, $moderates, $nowTs, $now, $plan));
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
     * Wiederholtes Löschen: das Thema ist schon gelöscht und der Handelnde ist der Themenstarter oder moderiert das Forum.
     * `null`, wenn es etwas anderes ist (dann gilt die ursprüngliche Ablehnung). Ohne Sperre, ohne Schreiben, ohne Hook.
     *
     * @return array<string, mixed>|null
     */
    private function repeatedDelete(int $userId, int $topicId, int $nowTs): ?array
    {
        try {
            $topic = $this->topics->find($topicId);
            if ($topic === null || (string) $topic['status'] !== 'deleted') {
                return null;
            }
            $forumId = (int) $topic['forum_id'];
            if ($topic['user_id'] === null || (int) $topic['user_id'] !== $userId) {
                if (!($this->can)($userId, 'moderate', ['forum_id' => $forumId, 'now' => $nowTs])->allowed) {
                    return null;
                }
            }
        } catch (\PDOException | \RuntimeException $e) {
            $this->databaseError('topic.delete', $e, ['topic_id' => $topicId]);

            return null;
        }

        return ['success' => true, 'topic_id' => $topicId, 'forum_id' => $forumId, 'previous_status' => 'deleted', 'mode' => 'deleted', 'already_deleted' => true];
    }

    /**
     * @param list<int> $plan gesperrte Statistikzeilen (`CounterBook::plan()`)
     * @return array<string, mixed>|null null: das Thema hat das Forum gewechselt oder ein neuer Autor ist hinzugekommen, der Aufrufer beginnt von vorn
     */
    private function deleteLocked(int $userId, int $topicId, int $forumId, bool $moderates, int $nowTs, string $now, array $plan): ?array
    {
        // Sperrreihenfolge: Forum → Thema → Statistik der Mitglieder (aufsteigend), alles vor der ersten nicht sperrenden Leseanweisung.
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
        $this->book->lock($plan, $now);
        if (!$this->book->covers($topicId, $plan)) {
            return null;
        }

        $decision = $this->decide($userId, 'delete', ['topic_id' => $topicId, 'now' => $nowTs, 'fresh' => true]);
        if (!$decision->allowed) {
            // Ein gleichzeitiger zweiter Aufruf hat das Thema zwischen Vorprüfung und Sperre gelöscht: auch das ist ein Wiederholen.
            if (in_array($decision->reason, self::REPEAT_REASONS, true) && (string) $topic['status'] === 'deleted'
                && (($topic['user_id'] !== null && (int) $topic['user_id'] === $userId) || $moderates)) {
                return ['success' => true, 'topic_id' => $topicId, 'forum_id' => $forumId, 'previous_status' => 'deleted', 'mode' => 'deleted', 'already_deleted' => true];
            }

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

        $this->topics->update($topicId, ['status' => 'deleted', 'previous_status' => $previous, 'trashed_at' => $now, 'trashed_by' => $userId, 'updated_at' => $now]);
        if ($previous === 'published') {
            $this->book->topicUnpublished($forum, ['status' => 'deleted'] + $topic, $plan, $now);
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
     * Globale Liste über alle für das Mitglied lesbaren Foren: `new` (neueste Themen), `active` (jüngste Aktivität) oder `unanswered`
     * (ohne Antwort). Keyset wie `listForum()`; ohne lesbares Forum eine leere Seite. Fehlerfrei bei ungültigem Cursor (erste Seite).
     *
     * @return array{success: bool, error?: string, items?: list<array<string, mixed>>, next?: string|null, has_more?: bool}
     */
    public function listGlobal(?int $viewerId, string $kind, ?string $cursor = null, ?int $limit = null): array
    {
        if (!in_array($kind, TopicRepository::GLOBAL_KINDS, true)) {
            return self::failure('invalid_input');
        }
        $viewer = $viewerId !== null && $viewerId > 0 ? $viewerId : null;
        $limit = max(1, min(TopicRepository::MAX_LIMIT, $limit ?? $this->settings->int('topics_per_page')));

        try {
            $forumIds = CommunityAccess::instance()->readableForumIds($viewer);
            $page = $this->topics->listGlobal($kind, $forumIds, Cursor::decode($cursor), $limit);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('topic.list_global', $e, ['kind' => $kind]);
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
