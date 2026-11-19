<?php
/**
 * Die einzige Entscheidungsstelle für Zugriffe im Community-Modul: `can()` beantwortet „darf dieses Mitglied das?“ für
 * Lesen, Schreiben und Moderation, `filterReadable()`/`readableForumIds()` liefern die lesbaren Foren für Listen, Suche
 * und Startseite, `limitsFor()` die Grenzen der Vertrauensstufe. Dienste rufen `can()` selbst; Controller dürfen sich
 * nicht darauf verlassen, dass ein Dienst es getan hat (SECURITY-PRIVACY §Rechte).
 *
 * Prüfreihenfolge (Entwurfsvertrag D1, Karte §1.3); jeder Schritt lehnt nur ab oder lässt durch:
 *   0 Aktion bekannt · 1 Modul aktiv (auch für `admin`) · 2 Konto (nicht aktiv = Gast, Schreibaktion als Gast =
 *   `login_required`, E-Mail-Pflicht außer `admin`) · 3 Sperre (außer `admin`) · 4 globale Capability (`community.view`,
 *   `community.participate`; globale Moderation = `admin` oder `community.moderate` springt über 6 und 7) · 5 Forum-Moderator
 *   (Mitglied oder aktive Gruppe, `include_children` über den Pfad; nur Moderationsaktionen) · 6 Forum-Recht (Rechte-Zeilen
 *   je Subjekt, Vereinigung, Vererbung; ohne Zeilen die Standards) · 7 Vertrauensstufe (nur Zeit- und Zählgrenzen) ·
 *   8 Zustand von Forum, Thema und Beitrag, Bearbeitungsfenster, Freigabepflicht · 9 Filter `cms_community_can`.
 * Die Abfragen, die Zeit oder Zahlen zählen (Abstände, Langsamer Modus, Tages- und Stundenlimit, Warteschlange), laufen
 * zuletzt.
 *
 * Festlegungen, die der Plan offenlässt (Bericht zu PR #2a):
 * - Wer ein Forum moderiert (globale Moderation oder Forum-Moderator), sieht alles in diesem Bereich, auch ohne `read` in
 *   der Rechtematrix, und unterliegt weder Bearbeitungsfenster, Langsamem Modus, Freigabepflicht noch den Abständen
 *   `rate_post_seconds`/`rate_topic_seconds` (D8). Die Rechtematrix und das Tages-/Stundenlimit der Vertrauensstufe gelten
 *   für Forum-Moderatoren bei gewöhnlichen Schreibaktionen weiter; globale Moderation überspringt beides (Schritt 4 springt
 *   über 6 und 7).
 * - Ein Forum unter einem `hidden`-Elternforum gilt als verborgen (nur Moderation sieht es).
 * - Wer ein Forum, Thema oder einen Beitrag nicht sehen darf, bekommt für jede Aktion `not_found`, auch bei Schreibaktionen.
 *   Bei unbekannten und unsichtbaren Zielen läuft dieselbe Abfragefolge (Platzhalter-Forum statt Abbruch), damit die Antwortzeit
 *   keinen Unterschied verrät.
 *
 * - Ein Forum unter einem `readonly`- oder `archived`-Elternforum bleibt beschreibbar (bewusste Entscheidung, PR #2a): nur
 *   `hidden` wirkt auf die Unterforen. Den Zustand eines Forums setzt die Forenverwaltung (PR #3) je Forum, auch beim
 *   Archivieren eines Teilbaums; `can()` leitet ihn nicht aus den Vorfahren ab.
 * - Fail-closed bei beschädigten Forendaten: ein unbekannter Forumstatus gilt wie `hidden`, ebenso ein Forum mit Elternforum,
 *   dessen `path` leer oder beschädigt ist oder nicht zum Elternforum passt (nur Moderation sieht es).
 * - Das Kontingent (Tageslimit, Stundenlimit, Abstände, Langsamer Modus) zählt jeden im Fenster angelegten Beitrag und jedes
 *   Thema, gleich welchen Status er heute hat: eigenes Löschen und Löschen durch die Moderation geben nichts frei.
 *
 * Kontext (`$context` von `can()`): kommt ausschließlich aus Dienst- oder Controller-Code, nie aus Anfragedaten. `now`, `fresh`
 * und übergebene Zeilen (`forum`, `topic`, `post`) werden vertraut; ein Controller gibt nur IDs weiter und prüft sie vorher als
 * Zahl. Eine ID unter `*_id` ist nie eine Zeile (ein Array dort gilt als unbekanntes Ziel). Ohne jedes Ziel sind `read` und
 * `search` nur mit `scope => 'global'` erlaubt (Frage „darf dieses Konto die Community betreten“); ein vergessenes oder leeres
 * Ziel (`forum_id => null`) ergibt `not_found`.
 *
 * Filter `cms_community_can`: jeder Filter läuft einzeln, eine Ablehnung (auch durch eine Ausnahme im Filter) steht fest und
 * wird von keinem späteren Filter aufgehoben; bei `read` und `search` wird sie zu `not_found` (kein Existenzkanal).
 *
 * Zwischenspeicher nur je Instanz und Request (Konto, Gruppen, Rollenrechte, E-Mail-Bestätigung, Forum-Moderatoren,
 * Rechte-Zeilen, Sperre je Sekunde, Vertrauensstufe für lesende Entscheidungen); Zähler, die Statistik für Schreibaktionen
 * und die Zeilen von Thema und Beitrag (ohne übergebene Zeile) werden bei jedem Aufruf gelesen. Wer Konto, Rolle, Gruppen,
 * Sperren oder Moderatoren ändert, ruft `forgetUser()` bzw. `flush()`; Dienste unter Zeilensperre übergeben `fresh` im Kontext.
 * Liefert der Aufrufer Beitrag, Thema und Forum als Zeilen mit, kostet `read` je Beitrag keine Abfrage mehr.
 *
 * Alle Zeiten sind UTC aus der Uhr (`clock`, ein Aufruf je Entscheidung).
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
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\CommunityCapabilities;
use CMS\Services\Community\Support\CommunityModule;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\Settings;
use CMS\Services\EmailVerificationService;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunityAccess
{
    /** Lesende Aktionen: Sperre `write` lässt sie durch, Gäste brauchen kein Konto. */
    public const READ_ACTIONS = ['read', 'search'];

    /** Aktionen, die Inhalt schreiben; Schlüssel der Rechtematrix siehe PERMISSION_KEYS. */
    public const CONTENT_ACTIONS = ['create_topic', 'reply', 'edit', 'delete', 'upload', 'poll', 'vote', 'react', 'mark_solution'];

    /** Aktionen ohne Matrix-Schlüssel: angemeldet, sichtbar und nicht schreibgesperrt genügt. */
    public const META_ACTIONS = ['report', 'subscribe'];

    /** Moderationsaktionen (Namen wie die Protokolleinträge) und die Sammelabfrage `moderate`. */
    public const MODERATION_ACTIONS = [
        'moderate', 'topic.lock', 'topic.unlock', 'topic.pin', 'topic.unpin', 'topic.rename', 'topic.announce',
        'topic.move', 'topic.merge', 'topic.split', 'topic.approve', 'post.hide', 'post.restore', 'post.move',
        'post.approve', 'post.reject',
    ];

    /** Aktionen, die nur eine globale Capability brauchen: Aktion => Capability. */
    public const CAPABILITY_ACTIONS = [
        'ban' => CommunityCapabilities::BAN,
        'manage' => CommunityCapabilities::MANAGE,
        'view_private_data' => CommunityCapabilities::VIEW_PRIVATE_DATA,
    ];

    /** Schlüssel der Rechtematrix je Inhaltsaktion (`edit`/`delete` gelten für eigene Beiträge). */
    public const PERMISSION_KEYS = [
        'create_topic' => 'create_topic', 'reply' => 'reply', 'edit' => 'edit_own', 'delete' => 'delete_own',
        'upload' => 'upload', 'poll' => 'poll', 'vote' => 'vote', 'react' => 'react', 'mark_solution' => 'mark_solution',
    ];

    /** Moderationsaktionen mit Ziel-Forum (`target_forum_id`): die Moderation muss in beiden Foren gelten. */
    private const MOVING_ACTIONS = ['topic.move', 'topic.merge', 'topic.split'];

    /** Stufe, ab der `edit_window_minutes_trusted` gilt. */
    private const TRUSTED_LEVEL = 3;

    /** Kennung eines nicht vorhandenen Forums: Abfragen bei unbekanntem Ziel laufen mit ihr genauso wie bei bekanntem. */
    private const DECOY_ID = 4294967295;

    /** Kontextschlüssel, die ein Ziel nennen (Zeile oder ID). */
    private const TARGET_KEYS = ['post', 'post_id', 'topic', 'topic_id', 'forum', 'forum_id'];

    /** Forumstatus, die ein Forum öffnen. Die Forenverwaltung setzt nur diese und `hidden`; die Spalte ist ein VARCHAR(20), andere Werte (Import, Eingriff in die Datenbank) gelten wie `hidden`. */
    private const OPEN_FORUM_STATUSES = ['open', 'readonly', 'archived'];

    /** Beitragsstatus, die für Tageslimit und Abstände zählen: jeder angelegte Beitrag, auch ein gelöschter oder abgelehnter. */
    private const QUOTA_POST_STATUSES = PostRepository::STATUSES;

    /** Themenstatus, die für Stundenlimit und Themenabstand zählen (`moved` ist nur der Verweis auf ein verschobenes Thema). */
    private const QUOTA_TOPIC_STATUSES = ['published', 'pending', 'hidden', 'deleted', 'trash'];

    private static ?self $instance = null;

    private Settings $settings;
    private TrustLevelPolicy $trust;
    private ForumRepository $forums;
    private TopicRepository $topics;
    private PostRepository $posts;
    private MemberRepository $members;
    private BanRepository $bans;
    private UserStatsRepository $stats;

    /** @var \Closure(string, string): bool Rolle, Capability */
    private \Closure $roleCan;
    /** @var \Closure(): bool */
    private \Closure $moduleEnabled;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(int): bool */
    private \Closure $emailVerified;

    /** @var array<int, array{id: int, member: bool, role: string, admin: bool}> */
    private array $accounts = [];
    /** @var array<int, list<int>> */
    private array $groups = [];
    /** @var array<int, bool> */
    private array $verified = [];
    /** @var array<string, bool> */
    private array $roleRights = [];
    /** @var array<string, bool> `<Konto>:<Forum>` => Forum-Moderator */
    private array $moderators = [];
    /** @var array<string, array{rows: list<array<string, mixed>>, has: bool}> `<Konto>:<Forum>:<Stufe>` */
    private array $permissionRows = [];
    /** @var array<int, array{at: int, ban: array{id: int, scope: string, reason: string, ends_at: string|null}|null}> */
    private array $banCache = [];
    /** @var array<int, int> Vertrauensstufe je Mitglied, nur für lesende Entscheidungen (Schreibaktionen lesen die Statistik jedes Mal) */
    private array $levels = [];

    /**
     * @param \Closure(string, string): bool|null $roleCan Rolle und Capability; Standard: `admin` immer, sonst `cms_load_role_capabilities()`
     * @param \Closure(): bool|null $moduleEnabled Standard: `CommunityModule::isEnabled()`
     * @param \Closure(): int|null $clock Unix-Zeit, Standard `time()`
     * @param \Closure(int): bool|null $emailVerified Standard: `EmailVerificationService::isEmailVerified()` (bei Ausnahme: nicht bestätigt)
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $roleCan = null,
        ?\Closure $moduleEnabled = null,
        ?\Closure $clock = null,
        ?\Closure $emailVerified = null,
        ?TrustLevelPolicy $trust = null
    ) {
        $database = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->trust = $trust ?? new BasicTrustPolicy($this->settings);
        $this->roleCan = $roleCan ?? static function (string $role, string $capability): bool {
            if ($role === 'admin') {
                return true;
            }

            return function_exists('cms_load_role_capabilities') && !empty(cms_load_role_capabilities($role)[$capability]);
        };
        $this->moduleEnabled = $moduleEnabled ?? static fn (): bool => CommunityModule::isEnabled();
        $this->clock = $clock ?? static fn (): int => time();
        $this->emailVerified = $emailVerified ?? static fn (int $userId): bool => EmailVerificationService::getInstance()->isEmailVerified($userId);

        $this->forums = new ForumRepository($database);
        $this->topics = new TopicRepository($database);
        $this->posts = new PostRepository($database);
        $this->members = new MemberRepository($database);
        $this->bans = new BanRepository($database);
        $this->stats = new UserStatsRepository($database);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Nur für Tests: ersetzt die gemeinsame Instanz (null = beim nächsten Zugriff neu aufbauen). */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Alle bekannten Aktionen (Lesen, Inhalt, Melden/Abonnieren, Moderation, Capability-Aktionen).
     *
     * @return list<string>
     */
    public static function actions(): array
    {
        return array_merge(
            self::READ_ACTIONS,
            self::CONTENT_ACTIONS,
            self::META_ACTIONS,
            self::MODERATION_ACTIONS,
            array_keys(self::CAPABILITY_ACTIONS)
        );
    }

    // ── Öffentliche Schnittstelle ───────────────────────────────────────────────────────────────────────────────

    /**
     * Entscheidung über eine Aktion.
     *
     * `$context` (alle Schlüssel optional, nur aus Dienst- oder Controller-Code, nie aus Anfragedaten): `forum_id|forum`,
     * `topic_id|topic`, `post_id|post` (ID oder, unter dem Namen ohne `_id`, bereits geladene Zeile aus dem Repository; Beitrag
     * → Thema → Forum, das Forum kommt nie aus der Eingabe, wenn Thema oder Beitrag übergeben wurde), `target_forum_id` (Ziel
     * bei `topic.move|merge|split`), `scope` (`global` erlaubt `read`/`search` ohne Ziel), `now` (Unix-Zeit; Dienste geben
     * ihre Operationszeit mit, Tests die Testzeit), `fresh` (Zwischenspeicher des Mitglieds verwerfen, für die Wiederholung
     * unter Zeilensperre). Ohne jedes Ziel und ohne `scope => 'global'` ist `read`/`search` `not_found`; ein Zielschlüssel
     * mit `null` zählt als unbekanntes Ziel.
     *
     * @param array<string, mixed> $context
     */
    public function can(?int $userId, string $action, array $context = []): AccessDecision
    {
        $uid = $userId !== null && $userId > 0 ? $userId : 0;
        try {
            $decision = $this->decide($uid, $action, $context);
        } catch (\Throwable $e) {
            // Im Zweifel abgelehnt: ein Fehler beim Lesen darf nie Rechte vergeben.
            $this->log('Zugriffsprüfung {action} fehlgeschlagen: {exception}', ['action' => $action, 'exception' => $e]);

            return AccessDecision::deny($action === 'read' ? 'not_found' : 'no_permission');
        }

        return $decision->allowed ? $this->applyFilter($decision, $uid, $action, $context) : $decision;
    }

    /**
     * Die lesbaren unter den übergebenen Foren (Reihenfolge der Eingabe, ohne Doppelte und unbekannte IDs). Dieselben Regeln
     * wie `can('read', ['forum_id' => …])`, mit gemeinsam geladenen Daten für viele Foren.
     *
     * @param list<int> $forumIds
     * @return list<int>
     */
    public function filterReadable(?int $userId, array $forumIds): array
    {
        $wanted = [];
        foreach ($forumIds as $forumId) {
            $forumId = (int) $forumId;
            if ($forumId > 0) {
                $wanted[$forumId] = $forumId;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $uid = $userId !== null && $userId > 0 ? $userId : 0;
        try {
            return $this->readableAmong($uid, array_values($wanted));
        } catch (\Throwable $e) {
            $this->log('Lesbare Foren nicht ermittelbar: {exception}', ['exception' => $e]);

            return [];
        }
    }

    /**
     * IDs aller Foren, die das Mitglied lesen darf (Reihenfolge des Forenbaums: Kategorie, Tiefe, Sortierung, ID).
     *
     * @return list<int>
     */
    public function readableForumIds(?int $userId): array
    {
        try {
            $ids = array_keys($this->forums->tree());
        } catch (\Throwable $e) {
            $this->log('Forenbaum nicht lesbar: {exception}', ['exception' => $e]);

            return [];
        }

        return $this->filterReadable($userId, $ids);
    }

    /**
     * Zeit- und Zählgrenzen der Vertrauensstufe des Mitglieds (Gäste und Unbekannte: Stufe 0).
     *
     * @return array{level: int, posts_per_day: int, topics_per_hour: int, edit_window_minutes: int}
     */
    public function limitsFor(?int $userId): array
    {
        $uid = $userId !== null && $userId > 0 ? $userId : 0;
        $level = 0;
        $account = $this->account($uid);
        if ($account['member']) {
            $level = $this->levelOf($account['id'], $this->stats->get($account['id']));
        }
        $limits = $this->settings->limitsForLevel($level);

        return [
            'level' => $level,
            'posts_per_day' => $limits['posts_per_day'],
            'topics_per_hour' => $limits['topics_per_hour'],
            'edit_window_minutes' => $this->editWindowMinutes($level),
        ];
    }

    /** Verwirft alles, was über dieses Mitglied zwischengespeichert ist (nach Rollen-, Gruppen-, Sperren- oder Moderatorenänderung). */
    public function forgetUser(int $userId): void
    {
        unset($this->accounts[$userId], $this->groups[$userId], $this->verified[$userId], $this->banCache[$userId], $this->levels[$userId]);
        $prefix = $userId . ':';
        foreach (array_keys($this->moderators) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($this->moderators[$key]);
            }
        }
        foreach (array_keys($this->permissionRows) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($this->permissionRows[$key]);
            }
        }
        $this->roleRights = [];
    }

    /** Verwirft alle Zwischenspeicher einschließlich des Forenbaums. */
    public function flush(): void
    {
        $this->accounts = [];
        $this->groups = [];
        $this->verified = [];
        $this->roleRights = [];
        $this->moderators = [];
        $this->permissionRows = [];
        $this->banCache = [];
        $this->levels = [];
        $this->forums->forgetTree();
    }

    // ── Entscheidung ────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $ctx */
    private function decide(int $uid, string $action, array $ctx): AccessDecision
    {
        // 0 Aktion bekannt
        if (!in_array($action, self::actions(), true)) {
            return AccessDecision::deny('unknown_action');
        }
        // 1 Modul aktiv (gilt für alle, auch admin)
        if (($this->moduleEnabled)() !== true) {
            return AccessDecision::deny('module_disabled');
        }

        if (!empty($ctx['fresh'])) {
            $this->forgetUser($uid);
            $this->forums->forgetTree();
        }
        $now = isset($ctx['now']) && is_int($ctx['now']) ? $ctx['now'] : ($this->clock)();
        $account = $this->account($uid);
        $uid = $account['id'];
        $reading = in_array($action, self::READ_ACTIONS, true);

        // 2 Konto
        if (!$account['member']) {
            if (!$reading) {
                return AccessDecision::deny('login_required');
            }
        } elseif (!$reading && !$account['admin'] && $this->settings->bool('require_verified_email') && !$this->isEmailVerified($uid)) {
            return AccessDecision::deny('email_unverified');
        }

        // 3 Sperre (die Administration ist ausgenommen)
        if ($account['member'] && !$account['admin']) {
            $ban = $this->activeBan($uid, $now);
            if ($ban !== null) {
                if ($ban['scope'] !== 'write') {
                    return AccessDecision::deny('banned_full');
                }
                if (!$reading) {
                    return AccessDecision::deny('banned_write');
                }
            }
        }

        // 4 globale Capability
        if (isset(self::CAPABILITY_ACTIONS[$action])) {
            return $account['member'] && $this->roleHas($account['role'], self::CAPABILITY_ACTIONS[$action])
                ? AccessDecision::allow()
                : AccessDecision::deny('no_capability');
        }
        $globalModerator = $account['member'] && $this->roleHas($account['role'], CommunityCapabilities::MODERATE);
        if (!$globalModerator) {
            $closed = $action === 'read' ? 'not_found' : 'no_capability';
            if (!$account['member']) {
                if (!$this->settings->bool('guests_can_read')) {
                    return AccessDecision::deny($closed);
                }
            } else {
                if (!$this->roleHas($account['role'], CommunityCapabilities::VIEW)) {
                    return AccessDecision::deny($closed);
                }
                if (!$reading && !in_array($action, self::MODERATION_ACTIONS, true)
                    && !$this->roleHas($account['role'], CommunityCapabilities::PARTICIPATE)
                ) {
                    return AccessDecision::deny('no_capability');
                }
            }
        }

        // Ziel auflösen (Beitrag → Thema → Forum)
        $target = $this->resolve($ctx);
        if (!$target['requested']) {
            return $this->decideWithoutTarget($action, $globalModerator, $ctx);
        }

        // Forum-Moderator, Rechtematrix und Stufe laufen auch bei unbekanntem Ziel (gleiche Abfragefolge)
        $forum = $target['forum'];
        // Die Statistik (Beitragszahl) brauchen nur Schreibaktionen; Lesen genügt die zwischengespeicherte Stufe (eine Abfrage je Instanz)
        $stats = null;
        $level = -1;
        if ($account['member']) {
            if ($reading) {
                $level = $this->readLevel($uid);
            } else {
                $stats = $this->stats->get($uid);
                $level = $this->levels[$uid] = $this->levelOf($uid, $stats);
            }
        }
        $matrix = $this->matrixFor($account, $forum, $level);
        $forumModerator = $this->isForumModerator($account, $forum);
        if ($target['missing'] || $forum === null) {
            return AccessDecision::deny('not_found');
        }

        $moderating = $globalModerator || $forumModerator;
        if (!self::forumVisible($matrix['read'], $this->hiddenInChain($forum), $moderating)) {
            return AccessDecision::deny('not_found');
        }
        $topic = $target['topic'];
        if ($topic !== null && !self::topicVisible($topic, $uid, $moderating, $account['admin'])) {
            return AccessDecision::deny('not_found');
        }
        $view = null;
        if ($target['post'] !== null) {
            $view = $this->postView($target['post'], $account, $uid, $moderating);
            if ($view === null) {
                return AccessDecision::deny('not_found');
            }
        }

        if ($reading) {
            return AccessDecision::allow(null, $action === 'read' ? $view : null);
        }
        if (in_array($action, self::MODERATION_ACTIONS, true)) {
            return $this->decideModeration($action, $ctx, $account, $forum, $globalModerator, $moderating);
        }
        if (in_array($action, self::META_ACTIONS, true)) {
            return $this->decideMeta($action, $uid, $forum, $topic, $target['post']);
        }

        return $this->decideContent(
            $action,
            $account,
            $target,
            $matrix,
            ['global' => $globalModerator, 'moderating' => $moderating],
            ['stats' => $stats, 'level' => $level, 'now' => $now]
        );
    }

    /**
     * Entscheidung ohne Forum, Thema und Beitrag: nur die globalen Schritte zählen. `read` und `search` brauchen dafür den
     * ausdrücklichen Kontext `scope => 'global'`; ein vergessenes oder falsch geschriebenes Ziel ist kein Freibrief (`not_found`).
     *
     * @param array<string, mixed> $ctx
     */
    private function decideWithoutTarget(string $action, bool $globalModerator, array $ctx): AccessDecision
    {
        if (in_array($action, self::READ_ACTIONS, true)) {
            return ($ctx['scope'] ?? null) === 'global' ? AccessDecision::allow() : AccessDecision::deny('not_found');
        }
        if ($action === 'moderate') {
            return $globalModerator ? AccessDecision::allow() : AccessDecision::deny('no_permission');
        }

        return AccessDecision::deny('not_found');
    }

    /**
     * Moderationsaktionen: nur Moderation des Forums (`topic.announce` nur global, Verschieben in Quelle und Ziel).
     * Ein archiviertes Forum sperrt auch die Moderation.
     *
     * @param array<string, mixed> $ctx
     * @param array{id: int, member: bool, role: string, admin: bool} $account
     * @param array<string, mixed> $forum
     */
    private function decideModeration(string $action, array $ctx, array $account, array $forum, bool $global, bool $moderating): AccessDecision
    {
        if (!$moderating || ($action === 'topic.announce' && !$global)) {
            return AccessDecision::deny('no_permission');
        }
        if (in_array($action, self::MOVING_ACTIONS, true) && isset($ctx['target_forum_id'])) {
            $targetId = (int) $ctx['target_forum_id'];
            $targetForum = $this->forums->find($targetId > 0 ? $targetId : self::DECOY_ID);
            if ($targetForum === null) {
                return AccessDecision::deny('not_found');
            }
            if (!$global && !$this->isForumModerator($account, $targetForum)) {
                return AccessDecision::deny('no_permission');
            }
            if ((string) $targetForum['status'] === 'archived') {
                return AccessDecision::deny('forum_archived');
            }
        }
        if ((string) $forum['status'] === 'archived') {
            return AccessDecision::deny('forum_archived');
        }

        return AccessDecision::allow();
    }

    /**
     * Melden und Abonnieren: angemeldet, sichtbar, nicht schreibgesperrt (alles davor); nicht auf eigene Inhalte melden,
     * nicht in archivierten Foren.
     *
     * @param array<string, mixed> $forum
     * @param array<string, mixed>|null $topic
     * @param array<string, mixed>|null $post
     */
    private function decideMeta(string $action, int $uid, array $forum, ?array $topic, ?array $post): AccessDecision
    {
        if ((string) $forum['status'] === 'archived') {
            return AccessDecision::deny('forum_archived');
        }
        $owner = $post !== null ? (int) $post['user_id'] : ($topic !== null ? (int) $topic['user_id'] : 0);
        if ($action === 'report' && $owner > 0 && $owner === $uid) {
            return AccessDecision::deny('no_permission');
        }

        return AccessDecision::allow();
    }

    /**
     * Inhaltsaktionen: 6 Forum-Recht, 8 Zustand, Bearbeitungsfenster, 7 Zähl- und Zeitgrenzen, Freigabepflicht.
     *
     * @param array{id: int, member: bool, role: string, admin: bool} $account
     * @param array{forum: array<string, mixed>|null, topic: array<string, mixed>|null, post: array<string, mixed>|null} $target
     * @param array<string, bool> $matrix
     * @param array{global: bool, moderating: bool} $mod
     * @param array{stats: array<string, mixed>|null, level: int, now: int} $run
     */
    private function decideContent(string $action, array $account, array $target, array $matrix, array $mod, array $run): AccessDecision
    {
        $forum = (array) $target['forum'];
        $topic = $target['topic'];
        $post = $target['post'];
        $uid = $account['id'];
        $moderating = $mod['moderating'];
        $key = self::PERMISSION_KEYS[$action];

        // Welches Ziel die Aktion braucht (sonst gibt es nichts, worauf sie wirken könnte)
        $needsPost = in_array($action, ['edit', 'react'], true);
        $needsTopic = in_array($action, ['reply', 'vote', 'mark_solution'], true);
        $entity = $post ?? $topic;
        if (($needsPost && $post === null) || ($needsTopic && $topic === null) || ($action === 'delete' && $entity === null)) {
            return AccessDecision::deny('not_found');
        }
        $own = $entity !== null && (int) ($entity['user_id'] ?? 0) === $uid;

        // 6 Forum-Recht
        $type = (string) $forum['type'];
        if ($action === 'create_topic' && $type === 'announcements') {
            $granted = $moderating;
        } elseif (in_array($action, ['edit', 'delete'], true)) {
            $granted = $moderating || ($own && $matrix[$key]);
        } else {
            $granted = $mod['global'] || $matrix[$key];
        }
        if (!$granted) {
            return AccessDecision::deny('no_permission');
        }
        if ($action === 'react' && $own) {
            return AccessDecision::deny('no_permission');
        }
        if ($action === 'mark_solution' && !$moderating && ((int) ($topic['user_id'] ?? 0) !== $uid || $type !== 'qa')) {
            return AccessDecision::deny('no_permission');
        }

        // 8 Zustand: Forum
        $forumStatus = (string) $forum['status'];
        if ($forumStatus === 'archived') {
            return AccessDecision::deny('forum_archived');
        }
        if ($action === 'delete' && $post !== null && (string) $post['status'] === 'deleted') {
            return AccessDecision::allow();
        }
        if ($forumStatus === 'readonly' && !$moderating) {
            return AccessDecision::deny('forum_readonly');
        }
        if ($type === 'link' && in_array($action, ['create_topic', 'reply'], true)) {
            return AccessDecision::deny('forum_link');
        }

        // 8 Zustand: Thema und Beitrag
        $nowUtc = gmdate('Y-m-d H:i:s', $run['now']);
        if ($topic !== null) {
            $reason = $this->topicStateReason($action, $topic, $nowUtc, $moderating);
            if ($reason !== null) {
                return AccessDecision::deny($reason);
            }
        }
        if ($post !== null && in_array($action, ['edit', 'delete'], true)) {
            $status = (string) $post['status'];
            $editable = $status === 'published' || $status === 'pending' || ($status === 'hidden' && $moderating);
            if (!$editable) {
                return AccessDecision::deny('post_not_editable');
            }
        }

        // 7/8 Bearbeitungsfenster (Löschen gilt dasselbe Fenster, Moderation hat keins)
        if (in_array($action, ['edit', 'delete'], true) && !$moderating) {
            $minutes = $this->editWindowMinutes($run['level']);
            if ($minutes > 0) {
                $created = self::utcTimestamp($entity['created_at'] ?? null);
                if ($created === null || $run['now'] - $created > $minutes * 60) {
                    return AccessDecision::deny('edit_window');
                }
            }
        }

        if (!in_array($action, ['create_topic', 'reply'], true)) {
            return AccessDecision::allow();
        }

        // 8 Freigabepflicht
        $initial = $this->initialStatus($forum, $matrix, $run['stats'], $moderating);

        // 7 Zeit- und Zählgrenzen (zuletzt: sie fragen die Datenbank); globale Moderation überspringt sie
        if (!$mod['global']) {
            $limited = $this->limitDecision($action, $uid, $forum, $topic, $matrix, $moderating, $run['level'], $run['stats'], $run['now'], $initial);
            if ($limited !== null) {
                return $limited;
            }
        }

        return AccessDecision::allow($initial);
    }

    /**
     * Grund, aus dem der Zustand des Themas die Aktion verbietet; null = erlaubt. Ein Thema in `deleted`, `trash` oder `moved`
     * lässt auch Moderation nichts schreiben. `locked_until` wird zur Abfragezeit ausgewertet.
     *
     * @param array<string, mixed> $topic
     */
    private function topicStateReason(string $action, array $topic, string $nowUtc, bool $moderating): ?string
    {
        if ($action === 'create_topic') {
            return null;
        }
        $editing = in_array($action, ['edit', 'delete'], true);
        $reason = match ((string) $topic['status']) {
            'published' => null,
            'pending' => $moderating || $editing ? null : 'topic_pending',
            'hidden' => $moderating ? null : ($editing ? 'post_not_editable' : 'no_permission'),
            'deleted', 'trash' => 'topic_deleted',
            'moved' => 'topic_moved',
            default => 'no_permission',
        };
        if ($reason !== null) {
            return $reason;
        }

        return !$moderating && !empty(TopicRepository::withEffectiveState($topic, $nowUtc)['is_locked']) ? 'topic_locked' : null;
    }

    /**
     * Freigabepflicht: `pending`, wenn das Forum sie verlangt oder das Mitglied weniger als `new_member_approval_posts`
     * veröffentlichte Beiträge hat; `bypass_approval` und Moderation sind ausgenommen.
     *
     * @param array<string, mixed> $forum
     * @param array<string, bool> $matrix
     * @param array<string, mixed>|null $stats
     */
    private function initialStatus(array $forum, array $matrix, ?array $stats, bool $moderating): string
    {
        if ($moderating || $matrix['bypass_approval']) {
            return 'published';
        }
        if ((int) ($forum['require_approval'] ?? 0) === 1) {
            return 'pending';
        }
        $needed = $this->settings->int('new_member_approval_posts');

        return $needed > 0 && (int) ($stats['post_count'] ?? 0) < $needed ? 'pending' : 'published';
    }

    /**
     * Zeit- und Zählgrenzen für `create_topic` und `reply`: größte Restzeit aus Abstand (`rate_limited`) und Langsamem Modus
     * (`slow_mode`) mit `retry_after` (entfällt für Forum-Moderatoren im eigenen Forum), danach Tages- und Stundenlimit der
     * Stufe (`trust_limit`) und der Deckel für wartende Beiträge (`pending_limit`). Gezählt wird, was das Mitglied im Fenster
     * angelegt hat, auch Gelöschtes (`QUOTA_*_STATUSES`): Löschen gibt kein Kontingent frei, weder das eigene noch das durch die
     * Moderation. Die Statuslisten gehen als zusätzlicher Parameter an `countSince()`/`lastOfUser()` der Repositories.
     *
     * @param array<string, mixed> $forum
     * @param array<string, mixed>|null $topic
     * @param array<string, bool> $matrix
     * @param array<string, mixed>|null $stats
     */
    private function limitDecision(string $action, int $uid, array $forum, ?array $topic, array $matrix, bool $moderating, int $level, ?array $stats, int $now, string $initial): ?AccessDecision
    {
        $creating = $action === 'create_topic';
        $waits = [];

        $ratePost = $moderating ? 0 : $this->settings->int('rate_post_seconds');
        if ($ratePost > 0) {
            $last = self::utcTimestamp($stats['last_post_at'] ?? null) ?? 0;
            if ($now - $last >= $ratePost) {
                $recent = $this->posts->lastOfUser($uid, gmdate('Y-m-d H:i:s', $now - $ratePost), null, null, self::QUOTA_POST_STATUSES);
                $last = self::utcTimestamp($recent['created_at'] ?? null) ?? 0;
            }
            $this->collectWait($waits, 'rate_limited', $last, $ratePost, $now);
        }
        $rateTopic = $moderating || !$creating ? 0 : $this->settings->int('rate_topic_seconds');
        if ($rateTopic > 0) {
            $recent = $this->topics->lastOfUser($uid, gmdate('Y-m-d H:i:s', $now - $rateTopic), self::QUOTA_TOPIC_STATUSES);
            $this->collectWait($waits, 'rate_limited', self::utcTimestamp($recent['created_at'] ?? null) ?? 0, $rateTopic, $now);
        }
        if (!$moderating && !$matrix['bypass_slow_mode']) {
            $forumSlow = (int) ($forum['slow_mode_seconds'] ?? 0);
            if ($forumSlow > 0) {
                $recent = $this->posts->lastOfUser($uid, gmdate('Y-m-d H:i:s', $now - $forumSlow), (int) $forum['id'], null, self::QUOTA_POST_STATUSES);
                $this->collectWait($waits, 'slow_mode', self::utcTimestamp($recent['created_at'] ?? null) ?? 0, $forumSlow, $now);
            }
            $topicSlow = $topic !== null ? (int) ($topic['slow_mode_seconds'] ?? 0) : 0;
            if (!$creating && $topicSlow > 0) {
                $recent = $this->posts->lastOfUser($uid, gmdate('Y-m-d H:i:s', $now - $topicSlow), null, (int) $topic['id'], self::QUOTA_POST_STATUSES);
                $this->collectWait($waits, 'slow_mode', self::utcTimestamp($recent['created_at'] ?? null) ?? 0, $topicSlow, $now);
            }
        }
        if ($waits !== []) {
            usort($waits, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

            return AccessDecision::deny($waits[0][0], $waits[0][1]);
        }

        $limits = $this->settings->limitsForLevel($level);
        if ($limits['posts_per_day'] > 0 && $this->posts->countSince($uid, gmdate('Y-m-d H:i:s', $now - 86400), self::QUOTA_POST_STATUSES) >= $limits['posts_per_day']) {
            return AccessDecision::deny('trust_limit');
        }
        if ($creating && $limits['topics_per_hour'] > 0 && $this->topics->countSince($uid, gmdate('Y-m-d H:i:s', $now - 3600), self::QUOTA_TOPIC_STATUSES) >= $limits['topics_per_hour']) {
            return AccessDecision::deny('trust_limit');
        }
        if ($initial === 'pending' && $this->posts->countSince($uid, '1970-01-01 00:00:00', ['pending']) >= $this->settings->int('max_pending_per_user')) {
            return AccessDecision::deny('pending_limit');
        }

        return null;
    }

    /**
     * Merkt eine Wartezeit vor: `$last` ist die Zeit des letzten Beitrags oder Themas (0 = keiner im Fenster). Eine Zeit in der
     * Zukunft (Uhrenabweichung) zählt höchstens als volle Grenze.
     *
     * @param list<array{0: string, 1: int}> $waits
     */
    private function collectWait(array &$waits, string $reason, int $last, int $limit, int $now): void
    {
        if ($last <= 0) {
            return;
        }
        $rest = min($limit, $last + $limit - $now);
        if ($rest > 0) {
            $waits[] = [$reason, $rest];
        }
    }

    // ── Auflösen, Sichtbarkeit ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Beitrag → Thema → Forum. Wird eine Ebene verlangt, aber nicht gefunden, läuft die Abfragefolge mit der Platzhalter-ID weiter
     * (`missing`): „gibt es nicht“ und „darfst du nicht sehen“ kosten gleich viele Abfragen.
     *
     * @param array<string, mixed> $ctx
     * @return array{requested: bool, missing: bool, forum: array<string, mixed>|null, topic: array<string, mixed>|null, post: array<string, mixed>|null}
     */
    private function resolve(array $ctx): array
    {
        $postRef = self::reference($ctx, 'post');
        $topicRef = self::reference($ctx, 'topic');
        $forumRef = self::reference($ctx, 'forum');
        $result = ['requested' => false, 'missing' => false, 'forum' => null, 'topic' => null, 'post' => null];
        if ($postRef === null && $topicRef === null && $forumRef === null) {
            if (array_intersect(self::TARGET_KEYS, array_keys($ctx)) === []) {
                return $result;
            }
            // Ein Zielschlüssel ist da, aber leer (`forum_id => null`): der Aufrufer wollte ein Ziel angeben und hat keines.
            $forumRef = self::DECOY_ID;
        }
        $result['requested'] = true;

        $post = null;
        if ($postRef !== null) {
            $post = $this->entity($postRef, ['status', 'topic_id', 'user_id', 'created_at'], fn (int $id): ?array => $this->posts->find($id));
            $result['post'] = $post;
            $result['missing'] = $post === null;
            // Das Thema kommt aus dem Beitrag; eine abweichend übergebene Thema-ID zählt nicht.
            $topicRef = $post !== null ? (int) $post['topic_id'] : self::DECOY_ID;
            if ($post !== null && is_array($ctx['topic'] ?? null) && (int) ($ctx['topic']['id'] ?? 0) === $topicRef) {
                $topicRef = $ctx['topic'];
            }
        }

        $topic = null;
        $forumId = null;
        if ($topicRef !== null) {
            $topic = $this->entity($topicRef, ['status', 'forum_id', 'user_id', 'created_at', 'is_locked', 'locked_until', 'slow_mode_seconds'], fn (int $id): ?array => $this->topics->find($id));
            $result['topic'] = $topic;
            $result['missing'] = $result['missing'] || $topic === null;
            $forumId = $topic !== null ? (int) $topic['forum_id'] : self::DECOY_ID;
            if ($topic !== null && is_array($ctx['forum'] ?? null) && (int) ($ctx['forum']['id'] ?? 0) === $forumId) {
                $forumRef = $ctx['forum'];
            } else {
                $forumRef = $forumId;
            }
        }

        $forum = $this->entity(
            $forumRef,
            ['status', 'type', 'path', 'parent_id', 'require_approval', 'slow_mode_seconds', 'inherit_permissions'],
            fn (int $id): ?array => $this->forums->find($id)
        );
        $result['forum'] = $forum;
        $result['missing'] = $result['missing'] || $forum === null;
        // Der Forenbaum wird in jedem Pfad gelesen (zwischengespeichert), auch wenn das Ziel fehlt.
        $this->forums->tree();

        return $result;
    }

    /**
     * Ziel einer Ebene aus dem Kontext: die Zeile oder ID unter `<name>`, sonst die ID unter `<name>_id`. Eine ID ist nie eine
     * Zeile: ein Array unter `<name>_id` (aus `?topic_id[id]=…`) zählt als unbekanntes Ziel.
     *
     * @param array<string, mixed> $ctx
     */
    private static function reference(array $ctx, string $name): mixed
    {
        $ref = $ctx[$name] ?? null;
        if ($ref !== null) {
            return $ref;
        }
        $id = $ctx[$name . '_id'] ?? null;

        return is_array($id) ? self::DECOY_ID : $id;
    }

    /**
     * ID oder geladene Zeile → Zeile. Fehlen einer übergebenen Zeile Spalten, wird neu geladen; ungültige IDs laden die
     * Platzhalter-ID (Ergebnis: nicht gefunden, aber dieselbe Abfrage).
     *
     * @param list<string> $required
     * @param \Closure(int): (array<string, mixed>|null) $loader
     * @return array<string, mixed>|null
     */
    private function entity(mixed $ref, array $required, \Closure $loader): ?array
    {
        if (is_array($ref)) {
            $id = (int) ($ref['id'] ?? 0);
            if ($id <= 0) {
                return $loader(self::DECOY_ID);
            }
            foreach ($required as $column) {
                if (!array_key_exists($column, $ref)) {
                    return $loader($id);
                }
            }

            return $ref;
        }
        $id = is_int($ref) ? $ref : (is_string($ref) && ctype_digit($ref) ? (int) $ref : 0);

        return $loader($id > 0 ? $id : self::DECOY_ID);
    }

    /** Ein Forum ist sichtbar, wenn die Matrix `read` gewährt und es nicht verborgen ist; Moderation sieht alles. */
    private static function forumVisible(bool $matrixRead, bool $hidden, bool $moderating): bool
    {
        return $moderating || ($matrixRead && !$hidden);
    }

    /**
     * Sichtbarkeit eines Themas: veröffentlicht und verschoben für alle; wartend und ausgeblendet für den Autor und die
     * Moderation; gelöscht nur für die Moderation; im Papierkorb nur für `admin`.
     *
     * @param array<string, mixed> $topic
     */
    private static function topicVisible(array $topic, int $uid, bool $moderating, bool $admin): bool
    {
        $own = $uid > 0 && (int) ($topic['user_id'] ?? 0) === $uid;

        return match ((string) $topic['status']) {
            'published', 'moved' => true,
            'pending', 'hidden' => $moderating || $own,
            'deleted' => $moderating,
            'trash' => $admin,
            default => false,
        };
    }

    /**
     * Ansicht eines Beitrags: `full`, `placeholder` (ausgeblendet, gelöscht) oder null = nicht sichtbar. Wartende und abgelehnte
     * Beiträge sehen nur Autor und Moderation; ausgeblendete der Autor und die Moderation voll; gelöschte nur die Moderation mit
     * `community.view_private_data` (Verlauf), alle anderen den Platzhalter.
     *
     * @param array<string, mixed> $post
     * @param array{id: int, member: bool, role: string, admin: bool} $account
     */
    private function postView(array $post, array $account, int $uid, bool $moderating): ?string
    {
        $own = $uid > 0 && (int) ($post['user_id'] ?? 0) === $uid;

        return match ((string) $post['status']) {
            'published' => 'full',
            'pending', 'rejected' => $own || $moderating ? 'full' : null,
            'hidden' => $own || $moderating ? 'full' : 'placeholder',
            'deleted' => $moderating && $account['member'] && $this->roleHas($account['role'], CommunityCapabilities::VIEW_PRIVATE_DATA) ? 'full' : 'placeholder',
            default => null,
        };
    }

    /**
     * Gilt das Forum als verborgen (nur Moderation sieht es)? Ja bei `hidden`, bei einem unbekannten Status, bei einem
     * beschädigten Pfad und wenn eines seiner Elternforen aus demselben Grund verborgen ist (fail-closed: Importe oder
     * Eingriffe in die Datenbank dürfen ein Forum nie öffnen).
     *
     * @param array<string, mixed> $forum
     */
    private function hiddenInChain(array $forum): bool
    {
        if (!in_array((string) $forum['status'], self::OPEN_FORUM_STATUSES, true) || self::pathBroken($forum)) {
            return true;
        }
        $tree = $this->forums->tree();
        $forumId = (int) $forum['id'];
        foreach ($this->ancestorIds($forumId) as $ancestorId) {
            if ($ancestorId !== $forumId && !in_array((string) ($tree[$ancestorId]['status'] ?? ''), self::OPEN_FORUM_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ist der Pfad eines Forums mit Elternforum leer oder beschädigt? Der Pfad `/3/17/` muss aus positiven IDs bestehen, mit der
     * eigenen ID enden und davor das Elternforum nennen; sonst ließen sich Sichtbarkeit und Rechte der Vorfahren nicht mehr
     * bestimmen. Oberste Foren ohne Elternforum sind nicht betroffen (ihre Kette ist das Forum selbst).
     *
     * @param array<string, mixed> $forum
     */
    private static function pathBroken(array $forum): bool
    {
        $parent = (int) ($forum['parent_id'] ?? 0);
        if ($parent < 1) {
            return false;
        }
        $ids = [];
        foreach (explode('/', trim((string) ($forum['path'] ?? ''), '/')) as $segment) {
            if (preg_match('/^[1-9]\d*$/', $segment) !== 1) {
                return true;
            }
            $ids[] = (int) $segment;
        }
        $last = count($ids) - 1;

        return $last < 1 || $ids[$last] !== (int) $forum['id'] || $ids[$last - 1] !== $parent;
    }

    // ── Rechtematrix, Moderator, Stufe ──────────────────────────────────────────────────────────────────────────

    /**
     * Rechtematrix des Mitglieds im Forum. Ohne Rechte-Zeilen in der ganzen Vererbungskette gelten die Standards
     * (Gast: `guest`; Mitglied: `guest` und `member`); sonst gelten nur die Zeilen, vereinigt über Gast, Rolle, aktive Gruppen
     * und Stufe (`trust:N` = ab Stufe N). Mit `$forum` = null (unbekanntes Ziel) laufen dieselben Abfragen auf die Platzhalter-ID.
     * `$lean` (nur die Mengen-API): erst fragen, ob die Kette Zeilen hat, und die Rechte-Zeilen nur dann lesen; `can()` liest immer
     * beides, damit „gibt es nicht“ und „darfst du nicht sehen“ dieselbe Abfragefolge haben.
     *
     * @param array{id: int, member: bool, role: string, admin: bool} $account
     * @param array<string, mixed>|null $forum
     * @return array<string, bool>
     */
    private function matrixFor(array $account, ?array $forum, int $level, bool $lean = false): array
    {
        $forumId = $forum !== null ? (int) $forum['id'] : self::DECOY_ID;
        $cacheKey = $account['id'] . ':' . $forumId . ':' . $level;
        if (!isset($this->permissionRows[$cacheKey])) {
            $chain = $forum !== null ? $this->chainIds($forumId) : [self::DECOY_ID];
            $load = fn (): array => $this->forums->permissionRows(
                $chain,
                $account['member'] ? $account['role'] : '',
                $account['member'] ? $this->groupIds($account['id']) : [],
                $account['member'] ? $level : -1
            );
            if ($lean) {
                $has = $this->forums->hasPermissionRows($chain);
                $this->permissionRows[$cacheKey] = ['rows' => $has ? $load() : [], 'has' => $has];
            } else {
                $this->permissionRows[$cacheKey] = ['rows' => $load(), 'has' => $this->forums->hasPermissionRows($chain)];
            }
        }
        ['rows' => $rows, 'has' => $has] = $this->permissionRows[$cacheKey];

        return $this->mergeMatrix($account['member'], $rows, $has);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, bool>
     */
    private function mergeMatrix(bool $member, array $rows, bool $hasRows): array
    {
        $matrix = array_fill_keys(Settings::PERMISSION_KEYS, false);
        if (!$hasRows) {
            $sources = [$this->settings->permissionDefaults('guest')];
            if ($member) {
                $sources[] = $this->settings->permissionDefaults('member');
            }
            foreach ($sources as $source) {
                foreach (Settings::PERMISSION_KEYS as $permission) {
                    $matrix[$permission] = $matrix[$permission] || !empty($source[$permission]);
                }
            }

            return $matrix;
        }
        foreach ($rows as $row) {
            foreach (ForumRepository::decodePermissions($row['permissions_json'] === null ? null : (string) $row['permissions_json']) as $permission => $granted) {
                if ($granted === 1) {
                    $matrix[$permission] = true;
                }
            }
        }

        return $matrix;
    }

    /**
     * Ist das Mitglied Moderator des Forums (selbst oder über eine aktive Gruppe, auf dem Forum oder über `include_children`
     * eines Elternforums)? Mit `$forum` = null läuft die Abfrage auf die Platzhalter-ID.
     *
     * @param array{id: int, member: bool, role: string, admin: bool} $account
     * @param array<string, mixed>|null $forum
     */
    private function isForumModerator(array $account, ?array $forum): bool
    {
        if (!$account['member']) {
            return false;
        }
        $forumId = $forum !== null ? (int) $forum['id'] : self::DECOY_ID;
        $cacheKey = $account['id'] . ':' . $forumId;
        if (!isset($this->moderators[$cacheKey])) {
            $ancestors = $forum !== null ? $this->ancestorIds($forumId) : [self::DECOY_ID];
            $this->moderators[$cacheKey] = $this->forums->moderatorRows($ancestors, $account['id'], $this->groupIds($account['id']), $forumId) !== [];
        }

        return $this->moderators[$cacheKey];
    }

    /**
     * Vertrauensstufe 0 bis 4 aus Statistik-Zeile (kann fehlen) und E-Mail-Bestätigung über die Regel.
     *
     * @param array<string, mixed>|null $stats
     */
    private function levelOf(int $uid, ?array $stats): int
    {
        $level = $this->trust->levelFor(
            $uid,
            $this->isEmailVerified($uid),
            (int) ($stats['post_count'] ?? 0),
            (int) ($stats['trust_level'] ?? 0),
            !empty($stats['trust_level_locked'])
        );

        return max(TrustLevelPolicy::MIN_LEVEL, min(TrustLevelPolicy::MAX_LEVEL, $level));
    }

    /**
     * Vertrauensstufe für lesende Entscheidungen: je Instanz einmal aus der Statistik (die Stufe entscheidet hier nur über
     * `trust:N`-Zeilen). Schreibaktionen lesen die Statistik jedes Mal und frischen diesen Wert auf; `forgetUser()` verwirft ihn.
     */
    private function readLevel(int $uid): int
    {
        return $this->levels[$uid] ??= $this->levelOf($uid, $this->stats->get($uid));
    }

    /** Bearbeitungsfenster in Minuten (0 = unbegrenzt): ab Stufe 3 das vertraute, sonst das normale. */
    private function editWindowMinutes(int $level): int
    {
        return $level >= self::TRUSTED_LEVEL
            ? $this->settings->int('edit_window_minutes_trusted')
            : $this->settings->int('edit_window_minutes');
    }

    // ── Konto, Rechte, Sperre ───────────────────────────────────────────────────────────────────────────────────

    /**
     * Konto des Mitglieds; Gäste, unbekannte und nicht aktive Konten (`status` ≠ `active`) sind Gäste (ID 0), ohne den
     * Status zu verraten.
     *
     * @return array{id: int, member: bool, role: string, admin: bool}
     */
    private function account(int $uid): array
    {
        $guest = ['id' => 0, 'member' => false, 'role' => '', 'admin' => false];
        if ($uid <= 0) {
            return $guest;
        }
        if (!isset($this->accounts[$uid])) {
            $row = $this->members->find($uid);
            $this->accounts[$uid] = $row !== null && $row['status'] === 'active'
                ? ['id' => $uid, 'member' => true, 'role' => $row['role'], 'admin' => $row['role'] === 'admin']
                : $guest;
        }

        return $this->accounts[$uid];
    }

    /** Recht einer Rolle; `admin` hat alles, ein Fehler der Rollenabfrage gilt als „nicht erlaubt“. */
    private function roleHas(string $role, string $capability): bool
    {
        if ($role === 'admin') {
            return true;
        }
        $key = $role . '|' . $capability;
        if (!isset($this->roleRights[$key])) {
            try {
                $this->roleRights[$key] = ($this->roleCan)($role, $capability) === true;
            } catch (\Throwable $e) {
                $this->log('Rollenrecht {capability} nicht lesbar: {exception}', ['capability' => $capability, 'exception' => $e]);
                $this->roleRights[$key] = false;
            }
        }

        return $this->roleRights[$key];
    }

    private function isEmailVerified(int $uid): bool
    {
        if (!isset($this->verified[$uid])) {
            try {
                $this->verified[$uid] = ($this->emailVerified)($uid) === true;
            } catch (\Throwable $e) {
                $this->log('E-Mail-Bestätigung nicht lesbar: {exception}', ['exception' => $e]);
                $this->verified[$uid] = false;
            }
        }

        return $this->verified[$uid];
    }

    /** @return list<int> */
    private function groupIds(int $uid): array
    {
        return $this->groups[$uid] ??= $this->members->groupIds($uid);
    }

    /**
     * Wirksame Sperre zum Zeitpunkt `$now` (je Sekunde zwischengespeichert; ein unbekannter Umfang kommt als `full`).
     *
     * @return array{id: int, scope: string, reason: string, ends_at: string|null}|null
     */
    private function activeBan(int $uid, int $now): ?array
    {
        if (!isset($this->banCache[$uid]) || $this->banCache[$uid]['at'] !== $now) {
            $this->banCache[$uid] = ['at' => $now, 'ban' => $this->bans->activeFor($uid, gmdate('Y-m-d H:i:s', $now))];
        }

        return $this->banCache[$uid]['ban'];
    }

    /**
     * Forum und alle Vorfahren (Forum zuerst). Ein bekanntes Forum mit leerer Liste heißt: der zwischengespeicherte Baum ist
     * veraltet; er wird einmal neu gelesen.
     *
     * @return list<int>
     */
    private function ancestorIds(int $forumId): array
    {
        $ids = $this->forums->ancestorIds($forumId);
        if ($ids === []) {
            $this->forums->forgetTree();
            $ids = $this->forums->ancestorIds($forumId);
        }

        return $ids;
    }

    /**
     * Forum und die Vorfahren, deren Rechte es erbt (siehe `ForumRepository::chainIds()`), mit demselben Neuladen wie oben.
     *
     * @return list<int>
     */
    private function chainIds(int $forumId): array
    {
        $ids = $this->forums->chainIds($forumId);
        if ($ids === []) {
            $this->forums->forgetTree();
            $ids = $this->forums->chainIds($forumId);
        }

        return $ids;
    }

    // ── Lesbare Foren (Mengen-API) ──────────────────────────────────────────────────────────────────────────────

    /**
     * @param list<int> $wanted
     * @return list<int>
     */
    private function readableAmong(int $uid, array $wanted): array
    {
        if (($this->moduleEnabled)() !== true) {
            return [];
        }
        $now = ($this->clock)();
        $account = $this->account($uid);
        if ($account['member'] && !$account['admin']) {
            $ban = $this->activeBan($account['id'], $now);
            if ($ban !== null && $ban['scope'] !== 'write') {
                return [];
            }
        }
        $globalModerator = $account['member'] && $this->roleHas($account['role'], CommunityCapabilities::MODERATE);
        if (!$globalModerator) {
            if (!$account['member']) {
                if (!$this->settings->bool('guests_can_read')) {
                    return [];
                }
            } elseif (!$this->roleHas($account['role'], CommunityCapabilities::VIEW)) {
                return [];
            }
        }

        $tree = $this->forums->tree();
        $level = $account['member'] ? $this->readLevel($account['id']) : -1;
        $forums = [];
        foreach ($wanted as $forumId) {
            if (isset($tree[$forumId])) {
                $forums[$forumId] = $tree[$forumId];
            }
        }
        // Eine Abfrage für alle Foren: gibt es in ihren Ketten überhaupt Rechte-Zeilen? Wenn nicht, gelten überall die
        // Standards und je Forum ist keine Abfrage nötig; sonst fragt `matrixFor()` je Forum nur noch die Rechte-Zeilen der
        // Ketten ab, die welche haben.
        $anyRows = false;
        if ($forums !== [] && !$globalModerator) {
            $chainIds = [];
            foreach (array_keys($forums) as $forumId) {
                foreach ($this->chainIds($forumId) as $id) {
                    $chainIds[$id] = $id;
                }
            }
            $anyRows = $this->forums->hasPermissionRows(array_values($chainIds));
        }
        $defaults = $anyRows ? [] : $this->mergeMatrix($account['member'], [], false);
        $readable = [];
        foreach ($forums as $forumId => $forum) {
            // Globale Moderation sieht jedes vorhandene Forum (wie in `can()`): weder Matrix noch Moderatorenabfrage nötig
            $visible = $globalModerator;
            if (!$visible) {
                $matrix = $anyRows ? $this->matrixFor($account, $forum, $level, true) : $defaults;
                $visible = ($matrix['read'] && !$this->hiddenInChain($forum)) || $this->isForumModerator($account, $forum);
            }
            if ($visible && $this->applyFilter(AccessDecision::allow(), $account['id'], 'read', ['forum_id' => $forumId])->allowed) {
                $readable[] = $forumId;
            }
        }

        return $readable;
    }

    // ── Filter cms_community_can ────────────────────────────────────────────────────────────────────────────────

    /**
     * Schritt 9: Plugins dürfen nur ablehnen oder verschärfen. Die Filter laufen einzeln in Ausführungsreihenfolge, jeder gegen
     * die Entscheidung, die der vorige übrig gelassen hat:
     * - Eine Ablehnung steht fest: die Kette endet, kein späterer Filter hebt sie auf. Die Ablehnung heißt `filter_denied` (der
     *   Grund des Plugins bleibt verborgen, seine Wartezeit bleibt); bei `read` und `search` heißt sie `not_found`, damit ein
     *   Plugin, das Foren verbirgt, ihre Existenz nicht verrät.
     * - Eine Ausnahme im Filter ist eine Ablehnung (fail-closed), kein Eingangswert.
     * - Ein Ergebnis, das erweitert (kein `AccessDecision`, wartend → veröffentlicht, Platzhalter → voll, neuer Wert, wo keiner
     *   war), wird verworfen und protokolliert; der Wert des vorigen Filters gilt weiter.
     *
     * @param array<string, mixed> $context
     */
    private function applyFilter(AccessDecision $decision, int $uid, string $action, array $context): AccessDecision
    {
        $reading = in_array($action, self::READ_ACTIONS, true);
        $current = $decision;
        foreach (Events::filters(Events::CAN) as $filter) {
            try {
                $result = call_user_func($filter, $current, $uid, $action, $context);
            } catch (\Throwable $e) {
                $this->log('Filter {hook} warf eine Ausnahme und lehnt ab: {exception}', ['hook' => Events::CAN, 'exception' => $e]);

                return $reading ? AccessDecision::deny('not_found') : AccessDecision::deny('filter_denied');
            }
            if (!$result instanceof AccessDecision) {
                $this->log('Filter {hook} lieferte keine Entscheidung und wurde ignoriert.', ['hook' => Events::CAN]);

                continue;
            }
            if (!$result->allowed) {
                if ($reading) {
                    Logger::instance()->withChannel('community')->debug('Filter {hook} lehnt {action} ab: {reason}', [
                        'hook' => Events::CAN,
                        'action' => $action,
                        'reason' => $result->reason,
                    ]);

                    return AccessDecision::deny('not_found');
                }

                return AccessDecision::deny('filter_denied', $result->retryAfter);
            }

            $status = self::tightened($current->initialStatus, $result->initialStatus, ['published', 'pending']);
            $view = self::tightened($current->view, $result->view, ['full', 'placeholder']);
            if ($status === false || $view === false) {
                $this->log('Filter {hook} wollte eine Entscheidung erweitern und wurde ignoriert.', ['hook' => Events::CAN]);

                continue;
            }
            $current = $current->withInitialStatus($status)->withView($view);
        }

        return $current;
    }

    /**
     * Ist `$new` gleich `$old` oder strenger (spätere Position in `$order`)? Liefert den Wert, sonst false. Wo es keinen
     * Ausgangswert gibt, darf auch kein neuer entstehen.
     *
     * @param list<string> $order von locker nach streng
     */
    private static function tightened(?string $old, ?string $new, array $order): string|false|null
    {
        if ($new === $old) {
            return $new;
        }
        if ($old === null || $new === null) {
            return false;
        }
        $from = array_search($old, $order, true);
        $to = array_search($new, $order, true);

        return $from !== false && $to !== false && $to >= $from ? $new : false;
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * UTC-Text `Y-m-d H:i:s` → Unix-Zeit; null bei fehlendem oder ungültigem Wert. Auch ein Datum, das PHP nur durch Überlauf
     * auflöst (`2026-13-45`, `0000-00-00 00:00:00`), ist ungültig: sonst entstünde ein Zeitpunkt in der Zukunft, der ein Fenster offen hält.
     */
    private static function utcTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $problems = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            return null;
        }

        return $date->getTimestamp();
    }

    /** @param array<string, mixed> $context */
    private function log(string $message, array $context): void
    {
        Logger::instance()->withChannel('community')->warning($message, $context);
    }
}
