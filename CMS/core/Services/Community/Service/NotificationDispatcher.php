<?php
/**
 * Benachrichtigungen der Community (PR #2b; Vertrag DESIGN-2b §3.6, §3.9, §3.10, §3.12, §4.5; Entwurf interaktion §1.6, §2.7 bis §2.10;
 * Entscheidung A2 des Auftraggebers: Mails ohne Textauszug, nur Thema, Forum, Autor und Link, nur an bestätigte Adressen, Abmeldelink).
 *
 * Ablauf:
 * - Listener legen nach dem Commit des Auslösers je genau einen Auftrag in `community_notify_queue` an (werfen nie); wartende Inhalte
 *   erzeugen keinen. Ausnahme `onUserBanned()`: synchron `notifyNow()` mit Transaktionsmail (ohne Warteschlange).
 * - `runQueue()` (Cron) stellt zu: Stufen TIERS (`moderation`, `report` → `reply`, `new_topic`, `solution` → `reaction`), darin nach ID.
 *   Je Auftrag Lease mit Token, Phasen (0 Zitierte, 1 Abos `instant`, 2 Abos `none`, 3 ein Empfänger) und Cursor; je Empfänger eine
 *   Transaktion in der Sperrfolge §3.10: Statistik des Empfängers (`settingsForUpdate`, Mutex) → Mail-Kontingent → `claimMail` (7) →
 *   Hinweis (`notifyGrouped`, 8) → Mail (`enqueuePlainStrict`, 9) → Kontingent → Cursor (10). Hinweis, Mail und Cursor committen
 *   gemeinsam: genau einmal je (Auftrag, Empfänger), höchstens eine Mail je Tor. Den Core-Hook feuert `announceCreated()` erst nach dem
 *   Commit und nur für neue Zeilen.
 * - Empfänger mit dem echten Konto: nicht der Akteur, Konto aktiv, kein Ignorieren (Moderation ausgenommen), `can(read)` mit
 *   `view = full` (Moderationshinweise sonst allgemein ohne Titel), Präferenz, noch nicht gelesen. Mail nur in Phase 1 bei `reply`/
 *   `new_topic`, mit Einstellung `notify_mail`, Queue, Geheimnis, Präferenz, Core-Schalter `email_notifications`, bestätigter und
 *   gültiger Adresse, offenem Tor und höchstens MAIL_PER_RECIPIENT_DAY Community-Mails je UTC-Tag.
 * - Mailtexte: Titel, Forum, Akteur und Name gehen über `mailVars()` (`defang()`: `://` und Domain-Punkte entschärft, S16);
 *   In-App-Texte bleiben unverändert. Kein Beitragstext.
 * - Nichts Langsames in der Empfänger-Transaktion: Parsen (Phase 0), Rechte und Mengenabfragen laufen vorher.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\BanRepository;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\MemberRepository;
use CMS\Services\Community\Repository\ModerationLogRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\ReactionRepository;
use CMS\Services\Community\Repository\ReadStateRepository;
use CMS\Services\Community\Repository\ReportRepository;
use CMS\Services\Community\Repository\SubscriptionRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\CommunityModule;
use CMS\Services\Community\Support\ContentRenderer;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Transaction;
use CMS\Services\Community\Support\Urls;
use CMS\Services\CronRunnerService;
use CMS\Services\MailQueueService;
use CMS\Services\NotificationService;
use CMS\Services\OptionStore;

if (!defined('ABSPATH')) {
    exit;
}

final class NotificationDispatcher
{
    /** Typ => Eigenschaften (`community.mention`: P2, reserviert, kein Erzeuger). */
    public const TYPES = [
        'community.reply' => ['mail' => true, 'switchable' => true],
        'community.new_topic' => ['mail' => true, 'switchable' => true],
        'community.quote' => ['mail' => false, 'switchable' => true],
        'community.reaction' => ['mail' => false, 'switchable' => true],
        'community.solution' => ['mail' => false, 'switchable' => true],
        'community.moderation' => ['mail' => false, 'switchable' => false],
    ];
    public const PHASE_QUOTES = 0, PHASE_INSTANT = 1, PHASE_INAPP = 2, PHASE_SINGLE = 3;
    public const BATCH = 100, MAX_QUOTES = 10, LEASE_SECONDS = 120, MAX_ATTEMPTS = 5, MAX_AGE_HOURS = 48;
    public const RUN_SECONDS = 20, RUN_JOBS = 50, RUN_RECIPIENTS = 5000, MIN_REMAINING = 15;
    public const MAIL_SOURCE = 'community';
    /** Code der internen \RuntimeException, wenn die Lease des Auftrags verloren ist. */
    public const LEASE_LOST = 4091;
    /** Community-Mails je Empfänger und UTC-Tag (G11); darüber nur In-App. */
    public const MAIL_PER_RECIPIENT_DAY = 50;
    /** Stufen der Zustellung: je Durchlauf die erste nicht leere (S8). */
    public const TIERS = [['moderation', 'report'], ['reply', 'new_topic', 'solution'], ['reaction']];

    /** Aufträge je Abfrage einer Stufe. */
    private const DUE_LIMIT = 10;
    private const TYPE_PATTERN = '/^community\.[a-z_]{1,30}$/D';
    private const MODERATION_DECISIONS = ['approved', 'rejected', 'hidden', 'moved'];

    private static ?self $instance = null;
    private static bool $warnedQueue = false;

    private Database $db;
    private Settings $settings;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision */
    private \Closure $can;
    /** @var \Closure(int, string, ?string, array<string, string>): array{id: int, inserted: bool} */
    private \Closure $notify;
    /** @var \Closure(string, string, string, array<string, string>): bool */
    private \Closure $mail;
    /** @var \Closure(): bool */
    private \Closure $mailReady;
    /** @var \Closure(): string */
    private \Closure $random;
    /** @var \Closure(): ?float */
    private \Closure $remaining;
    /** @var \Closure(): void */
    private \Closure $flush;
    private ?SubscriptionService $subscriptions;
    /** @var \Closure(int, string): int */
    private \Closure $markRead;
    /** @var \Closure(): string */
    private \Closure $contact;
    /** @var \Closure(string, ?int): array{allowed: bool, retry_after: int} */
    private \Closure $throttle;

    private SubscriptionRepository $queue;
    private UserStatsRepository $stats;
    private PostRepository $posts;
    private TopicRepository $topics;
    private ForumRepository $forums;
    private MemberRepository $members;
    private ReadStateRepository $reads;
    private ReactionRepository $reactions;
    private ReportRepository $reports;
    private BanRepository $bans;
    private ModerationLogRepository $log;
    private QuoteService $quotes;

    /** @var array{t0: int, now: int, mail: bool, result: array<string, int|string>} Zustand des laufenden `runQueue()` */
    private array $run = ['t0' => 0, 'now' => 0, 'mail' => false, 'result' => []];

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`); `runQueue()` liest sie am Anfang und je Stapel
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard `CommunityAccess::instance()->can()`
     * @param \Closure(int, string, ?string, array<string, string>): array{id: int, inserted: bool}|null $notify Standard `NotificationService::notifyGrouped()` (ohne Hook)
     * @param \Closure(string, string, string, array<string, string>): bool|null $mail Standard `MailQueueService::enqueuePlainStrict(…, 'community') > 0`
     * @param \Closure(): bool|null $mailReady Standard `MailQueueService::isEnabled()`
     * @param \Closure(): string|null $random Lease-Token (32 Hex), Standard `bin2hex(random_bytes(16))`
     * @param \Closure(): ?float|null $remaining Restzeit des Cron-Laufs, Standard `CronRunnerService::remainingSeconds()`
     * @param \Closure(): void|null $flush Standard `CommunityAccess::instance()->flush()`
     * @param SubscriptionService|null $subscriptions Abmeldelink und Geheimnis; Standard mit derselben Verbindung (erst bei Bedarf)
     * @param \Closure(int, string): int|null $markRead Standard `NotificationService::markGroupRead()`
     * @param \Closure(): string|null $contact Kontaktadresse im Sperrhinweis; Standard Option `contact_email`, dann `ADMIN_EMAIL`, sonst ''
     * @param \Closure(string, ?int): array{allowed: bool, retry_after: int}|null $throttle Standard `ContentGuard::instance()->throttle()`
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $clock = null,
        ?\Closure $can = null,
        ?\Closure $notify = null,
        ?\Closure $mail = null,
        ?\Closure $mailReady = null,
        ?\Closure $random = null,
        ?\Closure $remaining = null,
        ?\Closure $flush = null,
        ?SubscriptionService $subscriptions = null,
        ?\Closure $markRead = null,
        ?\Closure $contact = null,
        ?\Closure $throttle = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->can = $can ?? static fn (?int $userId, string $action, array $context): AccessDecision => CommunityAccess::instance()->can($userId, $action, $context);
        $this->notify = $notify ?? static fn (int $userId, string $type, ?string $groupKey, array $texts): array => NotificationService::getInstance()->notifyGrouped($userId, $type, $groupKey, $texts);
        $this->mail = $mail ?? static fn (string $to, string $subject, string $body, array $headers): bool => MailQueueService::getInstance()->enqueuePlainStrict($to, $subject, $body, $headers, self::MAIL_SOURCE) > 0;
        $this->mailReady = $mailReady ?? static fn (): bool => MailQueueService::getInstance()->isEnabled();
        $this->random = $random ?? static fn (): string => bin2hex(random_bytes(16));
        $this->remaining = $remaining ?? static fn (): ?float => CronRunnerService::remainingSeconds();
        $this->flush = $flush ?? static function (): void {
            CommunityAccess::instance()->flush();
        };
        $this->subscriptions = $subscriptions;
        $this->markRead = $markRead ?? static fn (int $userId, string $groupKey): int => NotificationService::getInstance()->markGroupRead($userId, $groupKey);
        $this->contact = $contact ?? static function (): string {
            $value = '';
            try {
                $value = (string) OptionStore::getInstance()->get('contact_email', '');
            } catch (\Throwable) {
            }
            if ($value === '' && defined('ADMIN_EMAIL')) {
                $value = (string) constant('ADMIN_EMAIL');
            }

            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : '';
        };
        // Ohne ContentGuard (Einheit S) entstehen keine Reaktionsaufträge (fail-closed).
        $this->throttle = $throttle ?? static function (string $action, ?int $userId): array {
            return class_exists(ContentGuard::class)
                ? ContentGuard::instance()->throttle($action, $userId)
                : ['allowed' => false, 'retry_after' => 3600];
        };
        $this->queue = new SubscriptionRepository($this->db);
        $this->stats = new UserStatsRepository($this->db);
        $this->posts = new PostRepository($this->db);
        $this->topics = new TopicRepository($this->db);
        $this->forums = new ForumRepository($this->db);
        $this->members = new MemberRepository($this->db);
        $this->reads = new ReadStateRepository($this->db);
        $this->reactions = new ReactionRepository($this->db);
        $this->reports = new ReportRepository($this->db);
        $this->bans = new BanRepository($this->db);
        $this->log = new ModerationLogRepository($this->db);
        $this->quotes = new QuoteService($this->db, $this->settings, $this->clock, $this->can);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Typen und Sprache ────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{mail: bool, switchable: bool}> TYPES plus Filter `cms_community_notification_types` */
    public function types(): array
    {
        return self::typeCatalog();
    }

    /**
     * TYPES plus Filter: zusätzliche Typen nur mit `community.<name>`, immer ohne Mail (Plugins lösen keine Community-Mails aus);
     * eingebaute Typen sind nicht entfern- oder änderbar; eine Ausnahme im Filter ergibt den Eingangswert.
     *
     * @return array<string, array{mail: bool, switchable: bool}>
     */
    public static function typeCatalog(): array
    {
        $types = self::TYPES;
        $filtered = Events::applyFilter(Events::NOTIFICATION_TYPES, self::TYPES);
        if (!is_array($filtered)) {
            return $types;
        }
        foreach ($filtered as $name => $properties) {
            if (!is_string($name) || isset(self::TYPES[$name]) || preg_match(self::TYPE_PATTERN, $name) !== 1) {
                continue;
            }
            $types[$name] = ['mail' => false, 'switchable' => !is_array($properties) || (bool) ($properties['switchable'] ?? true)];
        }

        return $types;
    }

    /** Sprache der Hinweise und Mails an ein Mitglied: `settings_json.locale`, sonst die Sprache der Website. */
    public function localeFor(int $userId): string
    {
        if ($userId > 0) {
            try {
                $locale = $this->stats->settingsMany([$userId])[$userId]['settings']['locale'] ?? null;
                if (is_string($locale) && in_array($locale, I18n::LOCALES, true)) {
                    return $locale;
                }
            } catch (\Throwable $e) {
                $this->warn('Community-Benachrichtigung: Sprache nicht lesbar: {exception}', ['exception' => $e]);
            }
        }

        return I18n::siteLocale();
    }

    // ── Listener (nach dem Commit des Auslösers; je höchstens ein INSERT, werfen nie) ───────────────────────────────

    public function onTopicCreated(int $topicId, int $forumId, int $userId, string $status, int $firstPostId): void
    {
        if ($status === 'published') {
            $this->enqueue('new_topic', $topicId, $firstPostId, $userId, null);
        }
    }

    public function onPostCreated(int $postId, int $topicId, int $userId, string $status): void
    {
        if ($status === 'published') {
            $this->enqueue('reply', $topicId, $postId, $userId, null);
        }
    }

    public function onTopicApproved(int $topicId, int $forumId, int $moderatorId, int $firstPostId): void
    {
        $this->enqueue('new_topic', $topicId, $firstPostId, 0, null);
        $this->enqueue('moderation', $topicId, $firstPostId, $moderatorId, 'approved');
    }

    public function onPostApproved(int $postId, int $topicId, int $moderatorId): void
    {
        $this->enqueue('reply', $topicId, $postId, 0, null);
        $this->enqueue('moderation', $topicId, $postId, $moderatorId, 'approved');
    }

    /** `$decision` `rejected` oder `hidden`; `$postId` 0 = das Thema. Akteur 0 = automatisch (Meldeschwelle). */
    public function onModerated(string $decision, int $topicId, int $postId, int $actorId): void
    {
        if (in_array($decision, ['rejected', 'hidden'], true)) {
            $this->enqueue('moderation', $topicId, $postId, $actorId, $decision);
        }
    }

    public function onSolutionMarked(int $topicId, int $postId, int $actorId): void
    {
        $this->enqueue('solution', $topicId, $postId, $actorId, null);
    }

    /** Nur neue Reaktionen (`previous = ''`) und nur, solange die Drossel `react_notify` des Akteurs erlaubt (S8). */
    public function onReactionChanged(int $postId, int $userId, string $reaction, string $previous): void
    {
        if ($previous !== '' || $reaction === '' || $userId < 1) {
            return;
        }
        try {
            if (!($this->throttle)('react_notify', $userId)['allowed']) {
                return;
            }
        } catch (\Throwable $e) {
            $this->warn('Community-Benachrichtigung: Drossel nicht lesbar: {exception}', ['exception' => $e]);

            return;
        }
        $this->enqueue('reaction', 0, $postId, $userId, $reaction);
    }

    /** Rückmeldung an den Melder (G1): Auftrag `report`; die Gültigkeit prüft der Cron. */
    public function onReportResolved(int $reportId, string $status, int $moderatorId): void
    {
        $this->enqueue('report', 0, $reportId, $moderatorId, $status, self::PHASE_SINGLE);
    }

    /** Hinweis an den Themenstarter (G3); Verweis-Themen erzeugen keinen eigenen. Den ersten Beitrag bestimmt der Cron. */
    public function onTopicMoved(int $topicId, int $fromForumId, int $toForumId, int $actorId, int $stubTopicId): void
    {
        $this->enqueue('moderation', $topicId, 0, $actorId, 'moved');
    }

    /**
     * Sperrhinweis (G2): synchron, ohne Warteschlange, ohne `can()`. In-App immer; Transaktionsmail nur an eine bestätigte, gültige
     * Adresse bei aktiver Queue, ohne Abmeldelink und ohne Kontingent (`notify_mail` und `email_notifications` gelten nicht).
     */
    public function onUserBanned(int $userId, int $banId, string $scope, int $actorId): void
    {
        try {
            $ban = $this->bans->find($banId);
            $account = $userId > 0 ? ($this->members->accounts([$userId])[$userId] ?? null) : null;
            if ($ban === null || $account === null || (int) $ban['user_id'] !== $userId || $account['status'] !== 'active') {
                return;
            }
            $meta = $this->members->metaMany([$userId], MemberRepository::META_KEYS)[$userId] ?? [];
            $locale = $this->localeFor($userId);
            $verified = ($meta['email_verified_at'] ?? '') !== '';
            $email = $account['email'];
            $until = $ban['ends_at'] === null
                ? I18n::t('community.notify.ban.permanent', [], $locale)
                : I18n::date((string) $ban['ends_at'], $locale === 'en' ? 'Y-m-d H:i' : 'd.m.Y H:i');
            $vars = ['scope' => I18n::t('community.ban.scope.' . $ban['scope'], [], $locale), 'until' => $until, 'reason' => self::plain((string) $ban['reason'])];
            $texts = [
                'title' => I18n::t('community.notify.ban.title', [], $locale),
                'message' => I18n::t('community.notify.ban.message', $vars, $locale),
                'url' => Urls::MEMBER_SECTION,
            ];
            $mailVars = $this->mailVars(['name' => self::displayName($account), 'reason' => $vars['reason']]);
            $lines = [
                I18n::t('community.mail.greeting', ['name' => $mailVars['name']], $locale),
                $texts['title'],
                I18n::t('community.notify.ban.message', ['reason' => $mailVars['reason']] + $vars, $locale),
            ];
            $contact = ($this->contact)();
            if ($contact !== '') {
                $lines[] = I18n::t('community.mail.ban.contact', ['contact' => $contact], $locale);
            }
            $mailAllowed = $verified && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            $this->sendNow($userId, $email, 'community.moderation', null, $texts, ['subject' => I18n::t('community.mail.ban.subject', [], $locale), 'lines' => $lines], $mailAllowed && ($this->mailReady)(), $locale);
        } catch (\Throwable $e) {
            $this->warn('Community-Sperrhinweis fehlgeschlagen: {exception}', ['exception' => $e, 'user_id' => $userId]);
        }
    }

    // ── Synchrone Hinweise (andere Bereiche, nach deren Commit) ──────────────────────────────────────────────────

    /**
     * Hinweis sofort (Autocommit), ohne `can()`: Typ aus `types()`, Konto aktiv, Präferenz des Typs (wenn schaltbar). `$mail`
     * `{subject, lines}` nur an eine bestätigte, gültige Adresse bei aktiver Queue (Transaktionsmail ohne Abmeldelink).
     * `$texts` `{title, message, url[, title_many, message_many, url_many]}` fertig übersetzt (`localeFor()`). `true` = Hinweis geschrieben.
     *
     * @param array<string, string> $texts
     * @param array{subject: string, lines: list<string>}|null $mail
     */
    public function notifyNow(int $recipientId, string $type, ?string $groupKey, array $texts, ?array $mail = null): bool
    {
        $types = $this->types();
        if ($recipientId < 1 || !isset($types[$type])) {
            return false;
        }
        try {
            $account = $this->members->accounts([$recipientId])[$recipientId] ?? null;
            if ($account === null || $account['status'] !== 'active') {
                return false;
            }
            $settings = $this->stats->settingsMany([$recipientId])[$recipientId]['settings'] ?? [];
            $preferences = SubscriptionService::normalizePreferences($settings, self::switchable($types));
            if ($types[$type]['switchable'] && !($preferences['notify_types'][$type] ?? true)) {
                return false;
            }
            $meta = $mail !== null ? ($this->members->metaMany([$recipientId], ['email_verified_at'])[$recipientId] ?? []) : [];
            $deliverable = $mail !== null && ($meta['email_verified_at'] ?? '') !== '' && filter_var($account['email'], FILTER_VALIDATE_EMAIL) !== false && ($this->mailReady)();

            return $this->sendNow($recipientId, $account['email'], $type, $groupKey, $texts, $mail, $deliverable, $this->localeFor($recipientId));
        } catch (\Throwable $e) {
            $this->warn('Community-Hinweis fehlgeschlagen: {exception}', ['exception' => $e, 'user_id' => $recipientId]);

            return false;
        }
    }

    /** Gruppen `community:topic:{id}` und `community:quote:{id}` gelesen (PR #4 beim Themenbesuch). */
    public function markTopicRead(int $userId, int $topicId): int
    {
        if ($userId < 1 || $topicId < 1) {
            return 0;
        }

        return ($this->markRead)($userId, 'community:topic:' . $topicId) + ($this->markRead)($userId, 'community:quote:' . $topicId);
    }

    // ── Cron ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Stellt fällige Aufträge zu, bis die Warteschlange leer ist oder ein Budget greift.
     *
     * @return array{jobs: int, recipients: int, notified: int, mailed: int, expired: int, failed: int, stop: string}
     */
    public function runQueue(): array
    {
        $result = ['jobs' => 0, 'recipients' => 0, 'notified' => 0, 'mailed' => 0, 'expired' => 0, 'failed' => 0, 'stop' => 'empty'];
        if (!CommunityModule::isEnabled()) {
            $result['stop'] = 'disabled';

            return $result;
        }
        $t0 = ($this->clock)();
        $this->run = ['t0' => $t0, 'now' => $t0, 'mail' => false, 'result' => $result];
        try {
            $this->run['result']['expired'] = $this->queue->purgeExpired(self::utc($t0 - self::MAX_AGE_HOURS * 3600), self::utc($t0));
            $this->run['mail'] = $this->mailEnabled();
            $stop = null;
            while ($stop === null) {
                $ids = $this->dueJobs(self::utc($this->run['now']));
                if ($ids === []) {
                    $stop = 'empty';
                    break;
                }
                foreach ($ids as $id) {
                    $stop = $this->budgetStop(true);
                    if ($stop !== null) {
                        break;
                    }
                    $stop = $this->runJob($id);
                    ($this->flush)();
                    if ($stop !== null) {
                        break;
                    }
                }
            }
            $this->run['result']['stop'] = $stop;
        } catch (\Throwable $e) {
            $this->warn('Community-Zustellung abgebrochen: {exception}', ['exception' => $e]);
            $this->run['result']['failed']++;
            $this->run['result']['stop'] = 'empty';
        }

        return $this->run['result'];
    }

    /** @return list<int> fällige Aufträge der ersten nicht leeren Stufe */
    private function dueJobs(string $nowUtc): array
    {
        foreach (self::TIERS as $kinds) {
            $ids = $this->queue->dueIds($nowUtc, self::DUE_LIMIT, $kinds);
            if ($ids !== []) {
                return $ids;
            }
        }

        return [];
    }

    /** Grund für den Abbruch des Laufs oder `null`; `$jobs` = vor einem neuen Auftrag (zählt RUN_JOBS mit). */
    private function budgetStop(bool $jobs): ?string
    {
        $result = $this->run['result'];
        if ($jobs && $result['jobs'] >= self::RUN_JOBS) {
            return 'jobs';
        }
        if ($result['recipients'] >= self::RUN_RECIPIENTS) {
            return 'recipients';
        }
        if ($this->run['now'] - $this->run['t0'] >= self::RUN_SECONDS) {
            return 'time';
        }
        $remaining = ($this->remaining)();

        return $remaining !== null && $remaining < self::MIN_REMAINING ? 'budget' : null;
    }

    /** Ein Auftrag: Lease, Verarbeitung, Fehlerbehandlung. Liefert einen Abbruchgrund (Budget) oder `null`. */
    private function runJob(int $id): ?string
    {
        $token = ($this->random)();
        $now = $this->run['now'];
        $job = $this->queue->lease($id, $token, self::utc($now + self::LEASE_SECONDS), self::utc($now));
        if ($job === null) {
            return null;
        }
        $this->run['result']['jobs']++;
        if ($job['attempts'] > self::MAX_ATTEMPTS) {
            $this->queue->finish($id, $token);
            $this->warn('Community-Zustellauftrag nach {attempts} Versuchen verworfen (ID {id}, Art {kind})', ['id' => $id, 'kind' => $job['kind'], 'attempts' => $job['attempts']]);
            $this->run['result']['failed']++;

            return null;
        }
        try {
            return $this->processJob($job, $token);
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && $e->getCode() === self::LEASE_LOST) {
                return null;
            }
            try {
                $this->queue->release($id, $token, true, self::utc($this->run['now']), self::utc($this->run['now'] + self::LEASE_SECONDS));
            } catch (\Throwable) {
            }
            $this->warn('Community-Zustellauftrag fehlgeschlagen (ID {id}, Art {kind}): {exception}', ['id' => $id, 'kind' => $job['kind'], 'exception' => $e]);
            $this->run['result']['failed']++;

            return null;
        }
    }

    /**
     * Phasen und Stapel eines Auftrags ab seinem Cursor.
     *
     * @param array<string, mixed> $job
     */
    private function processJob(array $job, string $token): ?string
    {
        $id = (int) $job['id'];
        $ctx = $this->context($job);
        if ($ctx === null) {
            $this->queue->finish($id, $token);

            return null;
        }
        $phases = $ctx['phases'];
        $phase = max((int) $job['phase'], $phases[0]);
        $cursor = $phase === (int) $job['phase'] ? (int) $job['cursor_user_id'] : 0;
        while (true) {
            $recipients = $this->recipientsFor($ctx, $phase, $cursor);
            if ($recipients === []) {
                $next = array_values(array_filter($phases, static fn (int $p): bool => $p > $phase));
                if ($next === []) {
                    $this->queue->finish($id, $token);

                    return null;
                }
                $phase = $next[0];
                $cursor = 0;
                if ($this->queue->advance($id, $token, $phase, 0, self::utc($this->run['now'])) === false) {
                    return null;
                }
                continue;
            }
            $tB = ($this->clock)();
            $this->run['now'] = $tB;
            $cursor = $this->deliverBatch($ctx, $id, $token, $phase, $recipients, $tB);
            if (!$this->queue->extend($id, $token, self::utc($tB + self::LEASE_SECONDS), self::utc($tB))) {
                return null;
            }
            ($this->flush)();
            $stop = $this->budgetStop(false);
            if ($stop !== null) {
                $this->queue->release($id, $token, false, self::utc($tB));

                return $stop;
            }
        }
    }

    /**
     * Zeilen und Regeln eines Auftrags zur Verarbeitungszeit; `null` = ungültig (Auftrag erledigt ohne Hinweis).
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>|null
     */
    private function context(array $job): ?array
    {
        $kind = (string) $job['kind'];
        $actor = (int) $job['actor_id'];
        $detail = $job['detail'];
        $ctx = ['kind' => $kind, 'detail' => $detail, 'body' => '', 'recipient' => 0, 'report' => null, 'topicLevel' => false];
        if ($kind === 'report') {
            $report = $this->reports->find((int) $job['post_id']);
            $reporter = $report['reporter_id'] ?? null;
            if ($report === null || $report['status'] === 'open' || !((int) $report['notify_reporter'] === 1) || $reporter === null || $reporter === $actor) {
                return null;
            }

            $ctx['recipient'] = $reporter;

            return $ctx + ['phases' => [self::PHASE_SINGLE], 'actor' => $actor, 'actorModerates' => true, 'post' => null, 'topic' => null, 'forum' => null, 'chain' => []];
        }

        $post = null;
        $topic = null;
        if (in_array($kind, ['reply', 'reaction'], true) || ($kind === 'moderation' && (int) $job['post_id'] > 0 && $detail !== 'moved')) {
            $post = $this->posts->find((int) $job['post_id']);
            $topic = $post === null ? null : $this->topics->find((int) $post['topic_id']);
        } else {
            $topic = $this->topics->find((int) $job['topic_id']);
            $firstId = $topic === null ? 0 : (int) ($topic['first_post_id'] ?? 0);
            $postId = $kind === 'solution' ? (int) $job['post_id'] : ($firstId > 0 ? $firstId : (int) $job['post_id']);
            $post = $postId > 0 ? $this->posts->find($postId) : null;
            $ctx['topicLevel'] = $kind === 'moderation';
        }
        if ($topic === null || ($post === null && $kind !== 'moderation')) {
            return null;
        }
        $forum = $this->forums->find((int) $topic['forum_id']);
        if ($forum === null) {
            return null;
        }
        $published = static fn (?array $row): bool => $row !== null && (string) $row['status'] === 'published';
        switch ($kind) {
            case 'reply':
                if (!$published($post) || !$published($topic) || (int) $post['is_first_post'] === 1) {
                    return null;
                }
                $actor = (int) ($post['user_id'] ?? 0);
                $phases = [self::PHASE_QUOTES, self::PHASE_INSTANT, self::PHASE_INAPP];
                break;
            case 'new_topic':
                if (!$published($post) || !$published($topic)) {
                    return null;
                }
                $actor = (int) ($post['user_id'] ?? 0);
                $phases = [self::PHASE_QUOTES, self::PHASE_INSTANT, self::PHASE_INAPP];
                break;
            case 'reaction':
                $current = $published($post) && $published($topic) && $actor > 0 ? $this->reactions->find((int) $post['id'], $actor) : null;
                if ($current === null) {
                    return null;
                }
                $ctx['detail'] = $current;
                $ctx['recipient'] = (int) ($post['user_id'] ?? 0);
                $phases = [self::PHASE_SINGLE];
                break;
            case 'solution':
                if ((int) ($topic['solution_post_id'] ?? 0) !== (int) $post['id']) {
                    return null;
                }
                $ctx['recipient'] = (int) ($post['user_id'] ?? 0);
                $phases = [self::PHASE_SINGLE];
                break;
            case 'moderation':
                if (!in_array($detail, self::MODERATION_DECISIONS, true)) {
                    return null;
                }
                $ctx['recipient'] = $ctx['topicLevel'] || $post === null ? (int) ($topic['user_id'] ?? 0) : (int) ($post['user_id'] ?? 0);
                $phases = [self::PHASE_SINGLE];
                break;
            default:
                return null;
        }
        if ($post !== null) {
            $ctx['body'] = (string) $post['body'];
            foreach ([...PostRepository::PRIVATE_COLUMNS, 'body', 'body_html'] as $column) {
                unset($post[$column]);
            }
        }
        $actorModerates = $actor > 0 && ($this->can)($actor, 'moderate', ['forum_id' => (int) $forum['id'], 'now' => $this->run['now']])->allowed;

        return $ctx + ['phases' => $phases, 'actor' => $actor, 'actorModerates' => $actorModerates, 'post' => $post, 'topic' => $topic,
            'forum' => $forum, 'chain' => $this->forums->ancestorIds((int) $forum['id'])];
    }

    /**
     * Empfänger einer Phase nach dem Cursor (aufsteigend, höchstens BATCH).
     *
     * @param array<string, mixed> $ctx
     * @return list<array{user_id: int, notified_at: ?string}>
     */
    private function recipientsFor(array $ctx, int $phase, int $cursor): array
    {
        if ($phase === self::PHASE_SINGLE) {
            $uid = (int) $ctx['recipient'];

            return $uid > $cursor ? [['user_id' => $uid, 'notified_at' => null]] : [];
        }
        if ($phase === self::PHASE_QUOTES) {
            $authors = array_values(array_filter($this->quotedAuthors($ctx), static fn (int $uid): bool => $uid > $cursor));

            return array_map(static fn (int $uid): array => ['user_id' => $uid, 'notified_at' => null], array_slice($authors, 0, self::BATCH));
        }
        [$type, $target] = $ctx['kind'] === 'reply' ? ['topic', (int) $ctx['topic']['id']] : ['forum', (int) $ctx['forum']['id']];

        return $this->queue->recipients($type, $target, $phase === self::PHASE_INAPP ? 'none' : 'instant', $cursor, self::BATCH);
    }

    /**
     * Autoren der zitierten Beiträge (Phase 0, S7): Zitat-Blöcke auf oberster Ebene aus `analyze()`, die ersten MAX_QUOTES, je ID
     * nur, wenn der Akteur die Quelle zur Verarbeitungszeit zitieren darf. Eindeutig, ohne Akteur, aufsteigend.
     *
     * @param array<string, mixed> $ctx
     * @return list<int>
     */
    private function quotedAuthors(array $ctx): array
    {
        $post = ['body' => (string) $ctx['body']];
        $refs = ContentRenderer::instance()->analyze((string) $post['body'])['stats']['quotes'];
        $actorId = (int) $ctx['actor'] > 0 ? (int) $ctx['actor'] : null;
        $quotable = [];
        foreach (array_slice($refs, 0, self::MAX_QUOTES) as $quotedId) {
            $quotedId = (int) $quotedId;
            if (!$this->quotes->isQuotable($quotedId, $actorId)) {
                continue;
            }
            $quotable[] = $quotedId;
        }
        $authors = [];
        foreach ($quotable === [] ? [] : $this->posts->findMany($quotable) as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid > 0 && $uid !== $actorId) {
                $authors[$uid] = $uid;
            }
        }
        ksort($authors);

        return array_values($authors);
    }

    /**
     * Ein Stapel: Mengenabfragen, dann je Empfänger Prüfung (ohne Datenbank außer `can()`) und Zustellung in einer Transaktion.
     * Liefert den neuen Cursor (größte Empfänger-ID des Stapels).
     *
     * @param array<string, mixed> $ctx
     * @param list<array{user_id: int, notified_at: ?string}> $recipients
     */
    private function deliverBatch(array $ctx, int $jobId, string $token, int $phase, array $recipients, int $tB): int
    {
        $nowUtc = self::utc($tB);
        $uids = array_map(static fn (array $r): int => $r['user_id'], $recipients);
        $actor = (int) $ctx['actor'];
        $accounts = $this->members->accounts($actor > 0 ? [...$uids, $actor] : $uids);
        $meta = $this->members->metaMany($uids, MemberRepository::META_KEYS);
        $ignoring = $actor > 0 ? array_flip($this->queue->ignoring($actor, $uids)) : [];
        $settings = $this->stats->settingsMany($uids);
        $topicId = $ctx['topic'] === null ? 0 : (int) $ctx['topic']['id'];
        $readStates = $topicId > 0 && in_array($ctx['kind'], ['reply', 'new_topic'], true) ? $this->reads->forUsers($topicId, $uids) : [];
        $marks = $ctx['chain'] === [] ? [] : $this->reads->latestMarksFor($uids, $ctx['chain']);
        $this->stats->ensureMany($uids, $nowUtc);
        $env = ['accounts' => $accounts, 'meta' => $meta, 'ignoring' => $ignoring, 'settings' => $settings, 'reads' => $readStates, 'marks' => $marks,
            'tB' => $tB, 'types' => $this->types()];

        $lastWritten = true;
        $last = 0;
        foreach ($recipients as $recipient) {
            $uid = $recipient['user_id'];
            $last = max($last, $uid);
            $this->run['result']['recipients']++;
            $plan = $ctx['kind'] === 'report' ? $this->reportPlan($ctx, $uid, $env) : $this->plan($ctx, $phase, $uid, $recipient['notified_at'], $env);
            if ($plan === null) {
                $lastWritten = false;
                continue;
            }
            [$note, $mailed] = $this->deliver($jobId, $token, $phase, $uid, $plan, $tB);
            $lastWritten = true;
            if ($note['id'] > 0) {
                $this->run['result']['notified']++;
            }
            if ($mailed) {
                $this->run['result']['mailed']++;
            }
            if ($note['inserted']) {
                $this->announce($note['id'], $uid, $plan['type']);
            }
        }
        if (!$lastWritten) {
            $this->advanceTail($jobId, $token, $phase, $last, $nowUtc);
        }

        return $last;
    }

    /** Cursor hinter den letzten (übersprungenen) Empfänger des Stapels; verlorene Lease ⇒ LEASE_LOST. */
    private function advanceTail(int $jobId, string $token, int $phase, int $last, string $nowUtc): void
    {
        $advanced = $this->queue->advance($jobId, $token, $phase, $last, $nowUtc);
        if (!$advanced) {
            throw new \RuntimeException('Lease des Zustellauftrags verloren', self::LEASE_LOST);
        }
    }

    /**
     * Prüfung eines Empfängers (Reihenfolge §2.8 Schritt 6) und Texte; `null` = übergehen (kein Schreibzugriff).
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $env
     * @return array<string, mixed>|null
     */
    private function plan(array $ctx, int $phase, int $uid, ?string $expected, array $env): ?array
    {
        $kind = (string) $ctx['kind'];
        $actorId = (int) $ctx['actor'] > 0 ? (int) $ctx['actor'] : null;
        if ($uid === $actorId) {
            return null;
        }
        $user = $env['accounts'][$uid] ?? null;
        if ($user === null || !((string) $user['status'] === 'active')) {
            return null;
        }
        $actorModerates = (bool) $ctx['actorModerates'];
        $ignoring = $env['ignoring'];
        if ($kind !== 'moderation' && !$actorModerates && isset($ignoring[$uid])) {
            return null;
        }
        // Ansicht gibt es nur auf Beitragsebene: Moderationshinweise prüfen den betroffenen Beitrag bzw. den ersten Beitrag des Themas.
        $decision = $ctx['post'] === null
            ? AccessDecision::deny('not_found')
            : ($this->can)($uid, 'read', ['post' => $ctx['post'], 'topic' => $ctx['topic'], 'forum' => $ctx['forum'], 'now' => $env['tB']]);
        $readable = $decision->allowed && $decision->view === 'full';
        if (!$readable && $kind !== 'moderation') {
            return null;
        }
        $stored = $env['settings'][$uid]['settings'] ?? [];
        $locale = is_string($stored['locale'] ?? null) && in_array($stored['locale'], I18n::LOCALES, true) ? $stored['locale'] : I18n::siteLocale();
        $type = match (true) {
            $phase === self::PHASE_QUOTES => 'community.quote',
            $kind === 'reply' => 'community.reply',
            $kind === 'new_topic' => 'community.new_topic',
            $kind === 'reaction' => 'community.reaction',
            $kind === 'solution' => 'community.solution',
            default => 'community.moderation',
        };
        $preferences = SubscriptionService::normalizePreferences($stored, self::switchable($env['types']));
        if (($env['types'][$type]['switchable'] ?? false) && !($preferences['notify_types'][$type] ?? true)) {
            return null;
        }
        $post = $ctx['post'];
        $topic = $ctx['topic'];
        $mark = (string) ($env['marks'][$uid] ?? '');
        if ($kind === 'reply' || $phase === self::PHASE_QUOTES) {
            $postId = (int) $post['id'];
            $lastRead = (int) ($env['reads'][$uid]['last_read_post_id'] ?? 0);
            if ($lastRead >= $postId || ($mark !== '' && $mark >= (string) $post['created_at'])) {
                return null;
            }
        } elseif ($kind === 'new_topic' && $mark !== '' && $mark >= (string) $topic['created_at']) {
            return null;
        }

        $actorName = $this->actorName($ctx, $env['accounts'], $locale);
        $texts = $this->texts($ctx, $type, $readable, $actorName, $locale);
        $plan = ['type' => $type, 'group' => $texts['group'], 'texts' => $texts['texts'], 'mail' => null, 'target' => null, 'expected' => $expected];
        if ($phase === self::PHASE_INSTANT && $this->run['mail'] && ($env['types'][$type]['mail'] ?? false) && $preferences['notify_mail']
            && $this->coreMailEnabled($env['meta'][$uid] ?? []) && ($env['meta'][$uid]['email_verified_at'] ?? '') !== ''
            && filter_var($user['email'], FILTER_VALIDATE_EMAIL) !== false && $this->gateOpen($ctx, $uid, $expected, $env)
        ) {
            $plan['target'] = $kind === 'reply' ? ['topic', (int) $topic['id']] : ['forum', (int) $ctx['forum']['id']];
            $plan['mail'] = $this->composeMail($ctx, $uid, $user, $actorName, $locale);
            if ($plan['mail'] === null) {
                $plan['target'] = null;
            }
        }

        return $plan;
    }

    /**
     * Rückmeldung an den Melder (G1): neutral, ohne Titel, Status, Link und Mail; kein `can()` (kein Inhalt). Konto aktiv.
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $env
     * @return array<string, mixed>|null
     */
    private function reportPlan(array $ctx, int $uid, array $env): ?array
    {
        $user = $env['accounts'][$uid] ?? null;
        if ($user === null || $user['status'] !== 'active') {
            return null;
        }
        $stored = $env['settings'][$uid]['settings'] ?? [];
        $locale = is_string($stored['locale'] ?? null) && in_array($stored['locale'], I18n::LOCALES, true) ? $stored['locale'] : I18n::siteLocale();
        $message = I18n::t('community.notify.report.message', [], $locale);

        return ['type' => 'community.moderation', 'group' => 'community:report', 'mail' => null, 'target' => null, 'expected' => null, 'texts' => [
            'title' => I18n::t('community.notify.report.title', [], $locale),
            'title_many' => I18n::t('community.notify.report.title_many', [], $locale),
            'message' => $message,
            'message_many' => $message,
            'url' => '',
        ]];
    }

    /**
     * Tor „eine Mail je Ziel bis zum Besuch“ (§2.9): offen ohne vorherige Mail, sonst nach dem Besuch des Themas (Lesestatus,
     * Marke) bzw. der Community (`last_seen_at`, Marke).
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $env
     */
    private function gateOpen(array $ctx, int $uid, ?string $expected, array $env): bool
    {
        if ($expected === null) {
            return true;
        }
        $mark = (string) ($env['marks'][$uid] ?? '');
        if ($ctx['kind'] === 'reply') {
            $readAt = (string) ($env['reads'][$uid]['updated_at'] ?? '');
            $seen = $readAt > $mark ? $readAt : $mark;

            return $seen !== '' && $seen >= $expected;
        }
        $lastSeen = (string) ($env['settings'][$uid]['last_seen_at'] ?? '');

        return ($lastSeen !== '' && $lastSeen >= $expected) || ($mark !== '' && $mark >= $expected);
    }

    /** Core-Hauptschalter `email_notifications` (`user_meta.notification_preferences`, Standard an). @param array<string, string> $meta */
    private function coreMailEnabled(array $meta): bool
    {
        $raw = $meta['notification_preferences'] ?? '';
        $preferences = $raw === '' ? null : json_decode($raw, true);

        return !is_array($preferences) || !array_key_exists('email_notifications', $preferences) || !empty($preferences['email_notifications']);
    }

    /**
     * Gruppe und Texte eines Hinweises (fertig übersetzt; `{count}` setzt der Core beim Bündeln).
     *
     * @param array<string, mixed> $ctx
     * @return array{group: ?string, texts: array<string, string>}
     */
    private function texts(array $ctx, string $type, bool $readable, string $actorName, string $locale): array
    {
        $post = $ctx['post'];
        $topic = $ctx['topic'];
        $forum = $ctx['forum'];
        $topicId = (int) $topic['id'];
        $vars = ['actor' => $actorName, 'topic' => self::plain((string) $topic['title']), 'forum' => self::plain(self::forumTitle($forum, $locale))];
        $t = static fn (string $key, array $with = []): string => I18n::t('community.notify.' . $key, $with, $locale);
        switch ($type) {
            case 'community.quote':
                return ['group' => 'community:quote:' . $topicId, 'texts' => [
                    'title' => $t('quote.title', $vars), 'title_many' => $t('quote.title_many', $vars),
                    'message' => $t('quote.message'), 'message_many' => $t('quote.message_many', $vars),
                    'url' => Urls::post((int) $post['id'], $locale), 'url_many' => Urls::topicUnread($topicId, $locale),
                ]];
            case 'community.reply':
                return ['group' => 'community:topic:' . $topicId, 'texts' => [
                    'title' => $t('reply.title', $vars), 'title_many' => $t('reply.title_many', $vars),
                    'message' => $t('reply.message', $vars), 'message_many' => $t('reply.message_many', $vars),
                    'url' => Urls::post((int) $post['id'], $locale), 'url_many' => Urls::topicUnread($topicId, $locale),
                ]];
            case 'community.new_topic':
                return ['group' => 'community:forum:' . (int) $forum['id'], 'texts' => [
                    'title' => $t('new_topic.title', $vars), 'title_many' => $t('new_topic.title_many', $vars),
                    'message' => $t('new_topic.message', $vars), 'message_many' => $t('new_topic.message_many', $vars),
                    'url' => Urls::topic($topicId, (string) $topic['slug'], $locale),
                    'url_many' => Urls::forum(Urls::forumSlugFor((string) $forum['slug'], $forum['slug_en'] ?? null, $locale), $locale),
                ]];
            case 'community.reaction':
                return ['group' => 'community:reaction:' . (int) $post['id'], 'texts' => [
                    'title' => $t('reaction.title', $vars), 'title_many' => $t('reaction.title_many', $vars),
                    'message' => $t('reaction.message', ['reaction' => ReactionService::EMOJI[(string) $ctx['detail']] ?? (string) $ctx['detail']]),
                    'message_many' => $t('reaction.message_many', $vars), 'url' => Urls::post((int) $post['id'], $locale),
                ]];
            case 'community.solution':
                return ['group' => null, 'texts' => ['title' => $t('solution.title', $vars), 'message' => $t('solution.message', $vars), 'url' => Urls::post((int) $post['id'], $locale)]];
        }

        return ['group' => null, 'texts' => $this->moderationTexts($ctx, $readable, $vars, $locale)];
    }

    /**
     * Moderationshinweis an den Autor: mit Titel nur bei Leserecht (`view = full`), sonst allgemein ohne Titel, Link in den
     * Mitgliederbereich (E18). Abgelehnt verlinkt den eigenen Beitrag im Mitgliederbereich (G5).
     *
     * @param array<string, mixed> $ctx
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    private function moderationTexts(array $ctx, bool $readable, array $vars, string $locale): array
    {
        $detail = (string) $ctx['detail'];
        $topic = $ctx['topic'];
        $post = $ctx['post'];
        $postId = $post === null ? (int) ($topic['first_post_id'] ?? 0) : (int) $post['id'];
        if ($detail === 'moved') {
            $key = $readable ? 'community.notify.moderation.moved' : 'community.notify.moderation.generic';

            return ['title' => I18n::t($key, $vars, $locale), 'message' => '', 'url' => $readable ? Urls::topic((int) $topic['id'], (string) $topic['slug'], $locale) : Urls::MEMBER_SECTION];
        }
        if (!$readable) {
            return ['title' => I18n::t('community.notify.moderation.generic', [], $locale), 'message' => '', 'url' => Urls::MEMBER_SECTION];
        }
        $topicLevel = (bool) $ctx['topicLevel'] || ($post !== null && (int) ($post['is_first_post'] ?? 0) === 1 && $detail === 'approved');
        $title = I18n::t('community.notify.moderation.' . $detail . ($topicLevel ? '_topic' : ''), $vars, $locale);
        $message = '';
        if ($detail !== 'approved') {
            $entry = $this->log->latestFor($topicLevel ? 'topic' : 'post', $topicLevel ? (int) $topic['id'] : $postId, [($topicLevel ? 'topic.' : 'post.') . ($detail === 'rejected' ? 'reject' : 'hide')]);
            $reason = self::plain((string) ($entry['reason'] ?? ''));
            $message = $reason !== '' ? I18n::t('community.notify.moderation.reason', ['reason' => $reason], $locale) : I18n::t('community.notify.moderation.no_reason', [], $locale);
        }
        $url = match (true) {
            $detail === 'rejected' => Urls::MEMBER_SECTION . '?rejected=' . $postId,
            $topicLevel || $postId < 1 => Urls::topic((int) $topic['id'], (string) $topic['slug'], $locale),
            default => Urls::post($postId, $locale),
        };

        return ['title' => $title, 'message' => $message, 'url' => $url];
    }

    /**
     * Klartext-Mail einer Antwort oder eines neuen Themas: Gruß, Text, Link, Tor-Hinweis, Abmelden (Ziel, alle), Einstellungen;
     * kein Beitragstext. `null` ohne Abmeldelink (kein Geheimnis).
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $user
     * @return array{to: string, subject: string, body: string, headers: array<string, string>}|null
     */
    private function composeMail(array $ctx, int $uid, array $user, string $actorName, string $locale): ?array
    {
        $topic = $ctx['topic'];
        $forum = $ctx['forum'];
        $reply = $ctx['kind'] === 'reply';
        $subscriptions = $this->subscriptions();
        $target = $reply ? 'topic:' . (int) $topic['id'] : 'forum:' . (int) $forum['id'];
        $targetToken = $subscriptions->token($uid, $target);
        $allToken = $subscriptions->token($uid, 'all');
        if ($targetToken === null || $allToken === null) {
            return null;
        }
        $vars = $this->mailVars([
            'topic' => self::plain((string) $topic['title']),
            'forum' => self::plain(self::forumTitle($forum, $locale)),
            'actor' => $actorName,
            'name' => self::displayName($user),
        ]);
        $t = static fn (string $key, array $with = []): string => I18n::t('community.mail.' . $key, $with, $locale);
        $kind = $reply ? 'reply' : 'new_topic';
        $link = $reply ? Urls::post((int) $ctx['post']['id'], $locale) : Urls::topic((int) $topic['id'], (string) $topic['slug'], $locale);
        $unsubscribe = Urls::absolute(Urls::unsubscribe($targetToken));
        $lines = [
            $t('greeting', ['name' => $vars['name']]),
            $t($kind . '.body', $vars),
            $t('link', ['url' => Urls::absolute($link)]),
            $t($kind . '.gate'),
            $t($reply ? 'unsubscribe_topic' : 'unsubscribe_forum', ['url' => $unsubscribe]),
            $t('unsubscribe_all', ['url' => Urls::absolute(Urls::unsubscribe($allToken))]),
            $t('settings', ['url' => Urls::absolute(Urls::MEMBER_SECTION)]),
        ];

        return [
            'to' => (string) $user['email'],
            'subject' => $t($kind . '.subject', $vars),
            'body' => implode("\n\n", $lines) . "\n",
            'headers' => ['List-Unsubscribe' => '<' . $unsubscribe . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click', 'Auto-Submitted' => 'auto-generated'],
        ];
    }

    /**
     * Empfänger-Transaktion (§4.5): Mutex, Kontingent, Tor, Hinweis, Mail, Kontingent, Cursor. Ein Datenbankfehler nimmt alles
     * zurück (`Transaction::run()` wiederholt Deadlocks); eine fachlich abgelehnte Mail gibt das Tor frei.
     *
     * @param array<string, mixed> $plan
     * @return array{0: array{id: int, inserted: bool}, 1: bool}
     */
    private function deliver(int $jobId, string $token, int $phase, int $uid, array $plan, int $tB): array
    {
        $nowUtc = self::utc($tB);
        $day = gmdate('Y-m-d', $tB);

        return Transaction::run($this->db, function () use ($jobId, $token, $phase, $uid, $plan, $nowUtc, $day): array {
            $settings = $this->stats->settingsForUpdate($uid);
            $quota = is_array($settings['mail_quota'] ?? null) ? $settings['mail_quota'] : [];
            $sent = ($quota['day'] ?? null) === $day ? max(0, (int) ($quota['count'] ?? 0)) : 0;
            $wantsMail = $plan['mail'] !== null && $sent < self::MAIL_PER_RECIPIENT_DAY;
            $claimed = $wantsMail && $this->claim($uid, $plan['target'], $plan['expected'], $nowUtc);
            $note = ($this->notify)($uid, $plan['type'], $plan['group'], $plan['texts']);
            $mailed = false;
            if ($claimed) {
                $mail = $plan['mail'];
                if (($this->mail)($mail['to'], $mail['subject'], $mail['body'], $mail['headers'])) {
                    $mailed = true;
                    $settings['mail_quota'] = ['day' => $day, 'count' => $sent + 1];
                    $this->stats->saveSettings($uid, $settings, $nowUtc);
                } else {
                    $this->queue->releaseMail($uid, $plan['target'][0], $plan['target'][1], $nowUtc, $plan['expected']);
                }
            }
            if (!$this->queue->advance($jobId, $token, $phase, $uid, $nowUtc)) {
                throw new \RuntimeException('Lease des Zustellauftrags verloren', self::LEASE_LOST);
            }

            return [['id' => (int) ($note['id'] ?? 0), 'inserted' => !empty($note['inserted'])], $mailed];
        });
    }

    /** @param array{0: string, 1: int} $target */
    private function claim(int $uid, array $target, ?string $expected, string $nowUtc): bool
    {
        return $this->queue->claimMail($uid, $target[0], $target[1], $expected, $nowUtc);
    }

    /**
     * Hinweis und Transaktionsmail sofort (Autocommit), danach `announceCreated()` für eine neue Zeile.
     *
     * @param array<string, string> $texts
     * @param array{subject: string, lines: list<string>}|null $mail
     */
    private function sendNow(int $uid, string $email, string $type, ?string $groupKey, array $texts, ?array $mail, bool $mailAllowed, string $locale): bool
    {
        $written = ($this->notify)($uid, $type, $groupKey, $texts);
        if ($mail !== null && $mailAllowed) {
            $lines = [...$mail['lines'], I18n::t('community.mail.settings', ['url' => Urls::absolute(Urls::MEMBER_SECTION)], $locale)];
            ($this->mail)($email, $mail['subject'], implode("\n\n", $lines) . "\n", ['Auto-Submitted' => 'auto-generated']);
        }
        if (!empty($written['inserted'])) {
            $this->announce((int) $written['id'], $uid, $type);
        }

        return (int) ($written['id'] ?? 0) > 0;
    }

    /** Core-Hook `cms_member_notification_created` nach dem Commit (nur neue Zeilen). */
    private function announce(int $id, int $uid, string $type): void
    {
        try {
            NotificationService::getInstance()->announceCreated($id, $uid, $type);
        } catch (\Throwable $e) {
            $this->warn('Community-Hinweis nicht angekündigt: {exception}', ['exception' => $e]);
        }
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** Mails nur mit Einstellung, aktiver Queue und Geheimnis für den Abmeldelink (einmal je Lauf). */
    private function mailEnabled(): bool
    {
        if (!$this->settings->bool('notify_mail')) {
            return false;
        }
        if (!($this->mailReady)()) {
            if (!self::$warnedQueue) {
                self::$warnedQueue = true;
                $this->warn('Community-Mails ausgesetzt: die Mail-Queue ist aus', []);
            }

            return false;
        }

        return $this->subscriptions()->canSign();
    }

    /**
     * Platzhalter für Betreff und Mailzeilen: alle Werte entschärft (S16). Eigene URLs gehen nicht hier hindurch.
     *
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    private function mailVars(array $vars): array
    {
        return array_map(self::defang(...), $vars);
    }

    /** `://` ⇒ `[:]//`, Punkt zwischen Buchstabe/Ziffer und mindestens zwei Buchstaben ⇒ `[.]` (Version 4.2.0, z.B. bleiben). */
    private static function defang(string $text): string
    {
        return (string) preg_replace('~(?<=[\p{L}\p{N}])\.(?=\p{L}{2,63}(?![\p{L}\p{N}]))~u', '[.]', str_replace('://', '[:]//', $text));
    }

    /** Titel und Namen ohne Steuerzeichen (Zeilenumbrüche werden zu Leerzeichen; keine Kopfzeileninjektion). */
    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
    }

    /** @param array<string, mixed> $account */
    private static function displayName(array $account): string
    {
        $name = self::plain((string) ($account['display_name'] ?? ''));

        return $name !== '' ? $name : self::plain((string) ($account['username'] ?? ''));
    }

    /**
     * Name des Akteurs; gelöschtes oder fehlendes Konto ⇒ `community.notify.deleted_member`.
     *
     * @param array<string, mixed> $ctx
     * @param array<int, array<string, mixed>> $accounts
     */
    private function actorName(array $ctx, array $accounts, string $locale): string
    {
        $actor = (int) $ctx['actor'];
        $name = $actor > 0 && isset($accounts[$actor]) ? self::displayName($accounts[$actor]) : '';

        return $name !== '' ? $name : I18n::t('community.notify.deleted_member', [], $locale);
    }

    /** @param array<string, mixed> $forum */
    private static function forumTitle(array $forum, string $locale): string
    {
        $english = $forum['title_en'] ?? null;

        return $locale === 'en' && is_string($english) && $english !== '' ? $english : (string) $forum['title'];
    }

    /**
     * @param array<string, array{mail: bool, switchable: bool}> $types
     * @return list<string>
     */
    private static function switchable(array $types): array
    {
        return array_keys(array_filter($types, static fn (array $type): bool => $type['switchable']));
    }

    private function subscriptions(): SubscriptionService
    {
        return $this->subscriptions ??= new SubscriptionService($this->db, $this->settings, $this->clock, $this->can);
    }

    /** Listener: genau ein INSERT, Fehler nur protokolliert. */
    private function enqueue(string $kind, int $topicId, int $postId, int $actorId, ?string $detail, int $phase = 0): void
    {
        try {
            $this->queue->enqueue($kind, max(0, $topicId), max(0, $postId), max(0, $actorId), $detail, self::utc(($this->clock)()), $phase);
        } catch (\Throwable $e) {
            $this->warn('Community-Zustellauftrag nicht angelegt ({kind}): {exception}', ['kind' => $kind, 'exception' => $e]);
        }
    }

    private static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /** @param array<string, mixed> $context */
    private function warn(string $message, array $context): void
    {
        Logger::instance()->withChannel('community')->warning($message, $context);
    }
}
