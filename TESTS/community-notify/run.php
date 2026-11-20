<?php
/**
 * Community-Interaktion (PR #2b; Vertrag DESIGN-2b §3.6, §3.9, §3.10, §3.13, §4.5, §7.1; Entwurf interaktion §6.1): Abos und Präferenzen
 * (`SubscriptionService`), Abmeldelink, Zustellung (`NotificationDispatcher`), Reaktionen (`ReactionService`), Ränge (`RankService`),
 * `ReadStateService::touchSeen()` und die Core-Teile (`NotificationService::notifyGrouped()`, `MailQueueService::enqueuePlainStrict()`).
 *
 * Prüfkürzel SECURITY-PRIVACY §1 (Vertrag §7.1b): N1 Rechte je Empfänger (Empfänger, Lecktest), N2 Tor je Abo (Tor Thema/Forum),
 * N3 Ignorieren, N4 nur bestätigte Adressen (Empfänger, Sperrhinweis), N5 Mail-Kontingent je Tag, N6 Abmeldelink (Token);
 * D3 Reaktionen und `react_notify` (Drossel). Hinweis- und Mailtexte (§7.1a): HTML bleibt Text, CR/LF werden Leerzeichen, Mails
 * entschärft (`defang()`).
 *
 * DB-frei (SQLite-Double), Zone Europe/Berlin, echte `CommunityAccess`, echte `NotificationService` per Reflection auf dem Double
 * (Bündelung real), Mail-Recorder (schreibt über dieselbe Verbindung in `cms_mail_queue`), Drossel, Zufall, Restzeit und `flush` als
 * Recorder. Die Wettläufe gegen MariaDB stehen in `TESTS/community-db/sections-notify.php`; die Listener-Anmeldung (`part-hooks.php`)
 * schreibt H in W3.
 *
 *   php TESTS/community-notify/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-notify-' . getmypid() . '/');
define('SITE_URL', 'https://forum.example.test');

// PHP-Zone der Suite: Europe/Berlin, damit jede Verwendung von date() statt gmdate() auffällt.
date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

// Gegenprobe-Haken (TESTS/lib/Mutant.php): mit CMS_TEST_MUTANT=<name> lädt der Autoloader eine Mutante statt des Originals.
require_once dirname(__DIR__) . '/lib/Mutant.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        Mutant::load($file);
    }
});

require_once ABSPATH . 'assets/autoload.php';
require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/lib/CommunityTestDatabase.php';

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\ReactionRepository;
use CMS\Services\Community\Repository\SubscriptionRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\NotificationDispatcher;
use CMS\Services\Community\Service\RankService;
use CMS\Services\Community\Service\ReactionService;
use CMS\Services\Community\Service\ReadStateService;
use CMS\Services\Community\Service\SubscriptionService;
use CMS\Services\Community\Support\CommunityModule;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Settings;
use CMS\Services\MailQueueService;
use CMS\Services\NotificationService;
use CMS\Services\OptionStore;
use CMS\Services\SettingsService;

T::$verbose = in_array('-v', $argv ?? [], true);

register_shutdown_function(static function (): void {
    foreach (glob(LOG_PATH . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir(LOG_PATH);
});

CommunityModule::setGate(static fn (): bool => true);
I18n::reset('de', 'de', 'Europe/Berlin');

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Hilfen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Uhr der Suite: 2026-11-20 12:00:00 UTC (Berlin +01:00). */
function cnAt(int $offset = 0): int
{
    return gmmktime(12, 0, 0, 11, 20, 2026) + $offset;
}

function cnUtc(int $offset = 0): string
{
    return gmdate('Y-m-d H:i:s', cnAt($offset));
}

/** Rechte der Rollen (`admin` hat alles). */
const CN_ROLE_CAPS = [
    'member' => ['community.view', 'community.participate'],
    'moderator' => ['community.view', 'community.participate', 'community.moderate'],
    'manager' => ['community.view', 'community.participate', 'community.manage'],
];

/** Geheimnis der Testwelt (≥ 16 Byte, ohne Platzhalter). */
const CN_SECRET = 'cn-test-secret-0123456789abcdef';

/** Warnungen dieses Prozesses (Kanal community). */
function cnLog(): string
{
    return implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), glob(LOG_PATH . 'community-*.log') ?: []));
}

function cnLogReset(): void
{
    foreach (glob(LOG_PATH . 'community-*.log') ?: [] as $file) {
        @unlink($file);
    }
}

/** Neue Instanz einer Klasse mit privatem Konstruktor, Eigenschaften gesetzt (Muster perf-batch). */
function cnInstance(string $class, array $properties): object
{
    $object = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    \Closure::bind(function () use ($properties): void {
        foreach ($properties as $name => $value) {
            $this->{$name} = $value;
        }
    }, $object, $class)();

    return $object;
}

/** Setzt die statische Instanz einer Singleton-Klasse. */
function cnSingleton(string $class, ?object $instance): void
{
    $property = new \ReflectionProperty($class, 'instance');
    $property->setValue(null, $instance);
}

/** Aufzeichnung von `cms_member_notification_created` (Hook des Core) mit `inTransaction()` der Welt. */
final class CnAnnounced
{
    /** @var list<array{id: int, user: int, type: string, in_transaction: bool}> */
    public static array $calls = [];
    public static ?\PDO $pdo = null;
}

Hooks::addAction('cms_member_notification_created', static function (int $id, int $userId, string $type): void {
    CnAnnounced::$calls[] = ['id' => $id, 'user' => $userId, 'type' => $type, 'in_transaction' => CnAnnounced::$pdo?->inTransaction() ?? false];
});

/**
 * Testwelt: SQLite-Double, echte `CommunityAccess`, echte `NotificationService` auf dem Double, Uhr mit Zähler, Recorder für Hooks,
 * Drossel, Mail, Zufall, Restzeit und `flush`. Grundbestand: Forum F, Thema T des Autors A mit erstem Beitrag P0.
 */
final class CnWorld
{
    public CommunityTestDatabase $db;
    public \ArrayObject $time;
    public int $clockReads = 0;
    public Settings $settings;
    public CommunityAccess $access;
    public NotificationService $core;
    public SubscriptionService $subs;
    public ReactionService $reactions;
    public RankService $ranks;
    public ReadStateService $reads;
    public NotificationDispatcher $dispatcher;
    /** @var list<array{hook: string, args: list<mixed>, in_transaction: bool}> */
    public array $hooks = [];
    /** @var list<array{action: string, user: ?int}> */
    public array $throttleLog = [];
    /** @var array<string, int> Aktion => noch erlaubte Aufrufe (fehlt = unbegrenzt) */
    public array $throttleBudget = [];
    /** @var list<array{to: string, subject: string, body: string, headers: array<string, string>, in_transaction: bool}> */
    public array $mails = [];
    public bool $mailResult = true;
    public bool $queueOn = true;
    public ?string $secret = CN_SECRET;
    public int $flushes = 0;
    /** @var \Closure(): ?float */
    public \Closure $remaining;
    public string $contact = 'hilfe@example.test';
    public string $requestLocale = 'de';
    /** @var array<int, true> */
    public array $unverifiedAccess = [];
    public int $tokens = 0;
    public bool $module = true;

    public int $forum;
    public int $author;
    public int $topic;
    public int $first;

    /** @param array<string, mixed> $settings Rohwerte für Settings::fromArray() */
    public function __construct(array $settings = [])
    {
        $this->db = CommunityTestDatabase::create();
        $this->time = new \ArrayObject(['now' => cnAt()]);
        $this->settings = Settings::fromArray($settings);
        Settings::setInstance($this->settings);
        $this->remaining = static fn (): ?float => null;
        $this->core = cnInstance(NotificationService::class, ['db' => $this->db, 'prefix' => 'cms_']);
        cnSingleton(NotificationService::class, $this->core);
        CnAnnounced::$pdo = $this->db->getPdo();
        CnAnnounced::$calls = [];
        $this->rebuild();

        $this->forum = $this->db->forum(['title' => 'Allgemein', 'slug' => 'allgemein']);
        $this->author = $this->member();
        [$this->topic, $this->first] = $this->thread($this->forum, $this->author, 'Erstes Thema');
    }

    /** Baut Zugriff und Dienste neu (leere Zwischenspeicher). */
    public function rebuild(): void
    {
        $this->access = new CommunityAccess(
            $this->db,
            $this->settings,
            static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, CN_ROLE_CAPS[$role] ?? [], true),
            fn (): bool => $this->module,
            fn (): int => (int) $this->time['now'],
            fn (int $userId): bool => !isset($this->unverifiedAccess[$userId])
        );
        $clock = function (): int {
            $this->clockReads++;

            return (int) $this->time['now'];
        };
        $can = fn (?int $userId, string $action, array $context): AccessDecision => $this->access->can($userId, $action, $context);
        $dispatch = function (string $hook, mixed ...$args): void {
            $this->hooks[] = ['hook' => $hook, 'args' => $args, 'in_transaction' => $this->db->getPdo()->inTransaction()];
        };
        $throttle = function (string $action, ?int $userId): array {
            $this->throttleLog[] = ['action' => $action, 'user' => $userId];
            if (isset($this->throttleBudget[$action])) {
                if ($this->throttleBudget[$action] <= 0) {
                    return ['allowed' => false, 'retry_after' => 60];
                }
                $this->throttleBudget[$action]--;
            }

            return ['allowed' => true, 'retry_after' => 0];
        };
        $this->subs = new SubscriptionService($this->db, $this->settings, $clock, $can, $dispatch, $throttle, fn (): ?string => $this->secret, fn (): string => $this->requestLocale);
        $this->reactions = new ReactionService($this->db, $this->settings, $clock, $can, $dispatch, $throttle);
        $this->ranks = new RankService($this->db, $this->settings, $clock, $can);
        $this->reads = new ReadStateService($this->db, $this->settings, $clock, $can);
        $mail = function (string $to, string $subject, string $body, array $headers): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers, 'in_transaction' => $this->db->getPdo()->inTransaction()];
            $this->db->execute(
                "INSERT INTO cms_mail_queue (recipient, subject, body, headers, content_type, source) VALUES (?, ?, ?, ?, 'plain', 'community')",
                [$to, $subject, $body, json_encode($headers)]
            );

            return $this->mailResult;
        };
        $this->dispatcher = new NotificationDispatcher(
            $this->db,
            $this->settings,
            $clock,
            $can,
            null,
            $mail,
            fn (): bool => $this->queueOn,
            fn (): string => sprintf('%032x', ++$this->tokens),
            fn (): ?float => ($this->remaining)(),
            function (): void {
                $this->flushes++;
                $this->access->flush();
            },
            $this->subs,
            null,
            fn (): string => $this->contact,
            $throttle
        );
    }

    /** Konto mit Rolle, Statistikzeile (Stufe 1) und bestätigter Adresse (`verified` false: kein `email_verified_at`). */
    public function member(string $role = 'member', bool $verified = true, string $status = 'active', array $o = []): int
    {
        $id = $this->db->user(['role' => $role, 'status' => $status] + $o);
        $this->db->stats($id, ['trust_level' => 1, 'trust_level_locked' => true, 'post_count' => 10]);
        if ($verified) {
            $this->db->execute("INSERT INTO cms_user_meta (user_id, meta_key, meta_value) VALUES (?, 'email_verified_at', ?)", [$id, '2026-01-01 00:00:00']);
        }

        return $id;
    }

    /** Thema mit erstem Beitrag. @return array{0: int, 1: int} */
    public function thread(int $forumId, ?int $authorId, string $title = 'Thema', string $body = 'Erster Beitrag', string $status = 'published'): array
    {
        $topic = $this->db->topic(['forum' => $forumId, 'user' => $authorId, 'title' => $title, 'status' => $status, 'created_at' => cnUtc(-3600)]);
        $first = $this->db->post(['topic' => $topic, 'user' => $authorId, 'first' => true, 'body' => $body, 'status' => $status, 'created_at' => cnUtc(-3600)]);
        $this->db->execute('UPDATE cms_community_topics SET first_post_id = ?, last_post_id = ? WHERE id = ?', [$first, $first, $topic]);

        return [$topic, $first];
    }

    /** Antwort (veröffentlicht, vor 60 s) ohne Auftrag. */
    public function post(int $topicId, ?int $authorId, string $body = 'Antwort', string $status = 'published', int $age = 60): int
    {
        $id = $this->db->post(['topic' => $topicId, 'user' => $authorId, 'body' => $body, 'status' => $status, 'created_at' => gmdate('Y-m-d H:i:s', (int) $this->time['now'] - $age)]);
        $this->db->execute('UPDATE cms_community_topics SET last_post_id = ? WHERE id = ?', [$id, $topicId]);

        return $id;
    }

    /** Antwort samt Auftrag `reply` über den Listener. */
    public function reply(?int $authorId, string $body = 'Antwort', ?int $topicId = null): int
    {
        $topicId ??= $this->topic;
        $id = $this->post($topicId, $authorId, $body);
        $this->dispatcher->onPostCreated($id, $topicId, (int) $authorId, 'published');

        return $id;
    }

    /** Abo (Thema, Standard `instant`). */
    public function subscribe(int $userId, ?int $target = null, string $type = 'topic', string $mode = 'instant', ?string $notifiedAt = null): void
    {
        $this->db->subscription(['user' => $userId, 'target' => $target ?? $this->topic, 'target_type' => $type, 'mode' => $mode, 'notified_at' => $notifiedAt]);
    }

    /** @return array<string, int|string> */
    public function run(): array
    {
        return $this->dispatcher->runQueue();
    }

    /** Hinweise eines Mitglieds. @return list<array<string, mixed>> */
    public function notes(int $userId): array
    {
        return $this->db->rows('notifications', ['user_id' => $userId], 'id');
    }

    /** Mails an ein Mitglied (Adresse aus users). @return list<array<string, mixed>> */
    public function mailsTo(int $userId): array
    {
        $email = (string) ($this->db->row('users', ['id' => $userId])['email'] ?? '');

        return array_values(array_filter($this->mails, static fn (array $m): bool => $m['to'] === $email));
    }

    public function at(int $seconds): void
    {
        $this->time['now'] = cnAt($seconds);
    }

    public function mark(): int
    {
        return count($this->db->log);
    }

    /** @return list<string> */
    public function since(int $mark): array
    {
        return array_slice($this->db->log, $mark);
    }

    /** @return list<string> */
    public function hookNames(): array
    {
        return array_map(static fn (array $h): string => $h['hook'], $this->hooks);
    }

    /** @return array<string, mixed> */
    public function settingsOf(int $userId): array
    {
        $raw = $this->db->row('community_user_stats', ['user_id' => $userId])['settings_json'] ?? null;

        return is_string($raw) && $raw !== '' ? (array) json_decode($raw, true) : [];
    }

    public function queueCount(): int
    {
        return $this->db->count('community_notify_queue');
    }
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Abos
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Abo: anlegen, Modus wechseln, zweimal (Hook nur bei Änderung, nach dem Commit), ungültige Eingaben, Rechte, Drossel', static function (): void {
    $w = new CnWorld();
    $m = $w->member();
    T::same(['success' => true, 'changed' => true, 'mode' => 'instant'], $w->subs->subscribe($m, 'topic', $w->topic), 'neues Abo');
    T::same(['success' => true, 'changed' => false, 'mode' => 'instant'], $w->subs->subscribe($m, 'topic', $w->topic), 'zweimal: keine Änderung');
    T::same(['success' => true, 'changed' => true, 'mode' => 'none'], $w->subs->subscribe($m, 'topic', $w->topic, 'none'), 'Moduswechsel');
    T::same([Events::SUBSCRIPTION_CHANGED, Events::SUBSCRIPTION_CHANGED], $w->hookNames(), 'Hook nur bei Änderung');
    T::same([[$m, 'topic', $w->topic, 'instant'], [$m, 'topic', $w->topic, 'none']], array_map(static fn (array $h): array => $h['args'], $w->hooks), 'Hook-Argumente');
    T::same([false, false], array_map(static fn (array $h): bool => $h['in_transaction'], $w->hooks), 'Hooks außerhalb einer Transaktion');
    T::same('none', $w->subs->status($m, 'topic', $w->topic)['mode'] ?? null, 'Status liest den Modus');

    $mark = $w->mark();
    foreach ([['topic', $w->topic, 'daily'], ['topic', $w->topic, 'weekly'], ['topic', $w->topic, 'xyz'], ['post', $w->topic, 'instant'], ['topic', 0, 'instant']] as [$type, $id, $mode]) {
        T::same(['success' => false, 'error' => 'invalid_input'], $w->subs->subscribe($m, $type, $id, $mode), "{$type}/{$id}/{$mode}: invalid_input");
    }
    T::same([], $w->since($mark), 'ungültige Eingaben: keine Anweisung');

    T::same('invalid_input', $w->subs->subscribe(0, 'topic', $w->topic)['error'] ?? null, 'Gast (Konto 0): invalid_input vor jeder Anweisung (§2.1 Schritt 1)');
    $banned = $w->member();
    $w->db->ban(['user' => $banned, 'scope' => 'write']);
    T::same('banned_write', $w->subs->subscribe($banned, 'topic', $w->topic)['error'] ?? null, 'Schreibsperre: banned_write');
    $internal = $w->db->forum(['title' => 'Intern', 'status' => 'hidden']);
    T::same('not_found', $w->subs->subscribe($m, 'forum', $internal)['error'] ?? null, 'internes Forum: not_found');
    $archived = $w->db->forum(['title' => 'Archiv', 'status' => 'archived']);
    T::same('forum_archived', $w->subs->subscribe($m, 'forum', $archived)['error'] ?? null, 'archiviertes Forum: forum_archived');
    $unverified = $w->member();
    $w->unverifiedAccess[$unverified] = true;
    $w->settings = Settings::fromArray(['require_verified_email' => true]);
    $w->rebuild();
    T::check(($w->subs->subscribe($unverified, 'topic', $w->topic)['success'] ?? true) === false, 'unbestätigte Adresse bei Pflicht: abgelehnt');

    $w = new CnWorld();
    $m = $w->member();
    $w->throttleBudget['subscribe'] = 0;
    $mark = $w->mark();
    T::same(['success' => false, 'error' => 'rate_limited', 'retry_after' => 60], $w->subs->subscribe($m, 'topic', $w->topic), 'Drossel: rate_limited mit retry_after');
    T::same([], array_values(array_filter($w->since($mark), static fn (string $sql): bool => str_contains($sql, 'community_subscriptions'))), 'Drossel: kein Schreibzugriff auf Abos');
    T::same(['subscribe'], array_column($w->throttleLog, 'action'), 'Drossel mit Aktion subscribe');
});

T::run('Abo: abbestellen auch gesperrt, idempotent; Liste mit Keyset (120 Abos, Seiten zu 50)', static function (): void {
    $w = new CnWorld();
    $m = $w->member();
    $w->subscribe($m);
    $w->db->ban(['user' => $m, 'scope' => 'full']);
    T::same(['success' => true, 'changed' => true], $w->subs->unsubscribe($m, 'topic', $w->topic), 'gesperrt: abbestellen erlaubt');
    T::same(['success' => true, 'changed' => false], $w->subs->unsubscribe($m, 'topic', $w->topic), 'zweimal: idempotent');
    T::same([[$m, 'topic', $w->topic, '']], array_map(static fn (array $h): array => $h['args'], $w->hooks), 'Hook mit mode = \'\' nur einmal');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->subs->unsubscribe($m, 'board', 1), 'unbekannter Zieltyp');

    $u = $w->member();
    for ($i = 1; $i <= 60; $i++) {
        $w->subscribe($u, $i, 'topic');
        $w->subscribe($u, $i, 'forum');
    }
    $seen = [];
    $after = null;
    $pages = 0;
    do {
        $page = $w->subs->subscriptions($u, $after, 50);
        $pages++;
        foreach ($page['items'] as $item) {
            $seen[] = $item['target_type'] . ':' . $item['target_id'];
        }
        $after = $page['next'];
    } while ($after !== null && $pages < 10);
    T::same(3, $pages, '120 Abos ⇒ drei Seiten');
    T::same(120, count(array_unique($seen)), 'ohne Lücke und ohne Doppelung');
    T::same('forum:1', $seen[0], 'Reihenfolge: Zieltyp, dann Ziel');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->subs->subscriptions($u, 'topic:x'), 'ungültiger Cursor');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->subs->subscriptions($u, null, 101), 'Grenze über 100');
});

T::run('Auto-Abo: Thema (veröffentlicht und wartend), Antwort; auto_subscribe off/created/all; vorhandenes none bleibt; Ablehnung ohne Ausnahme; Sprache', static function (): void {
    $w = new CnWorld();
    $m = $w->member();
    $w->requestLocale = 'en';
    $w->subs->onTopicCreated($w->topic, $w->forum, $m, 'published', $w->first);
    T::same('instant', $w->subs->status($m, 'topic', $w->topic)['mode'] ?? null, 'Standard all: eigenes Thema abonniert');
    T::same('en', $w->settingsOf($m)['locale'] ?? null, 'Sprache der Anfrage gespeichert, weil sie fehlte');
    T::same([[$m, 'topic', $w->topic, 'instant']], array_map(static fn (array $h): array => $h['args'], $w->hooks), 'Hook für das Auto-Abo');
    $w->requestLocale = 'de';
    [$t2] = $w->thread($w->forum, $m, 'Wartend', 'x', 'pending');
    $w->subs->onTopicCreated($t2, $w->forum, $m, 'pending', 0);
    T::same('instant', $w->subs->status($m, 'topic', $t2)['mode'] ?? null, 'auch wartende Themen');
    T::same('en', $w->settingsOf($m)['locale'] ?? null, 'gespeicherte Sprache bleibt');

    $w->subs->savePreferences($m, ['auto_subscribe' => 'created']);
    $t3 = $w->thread($w->forum, $w->author, 'Fremd')[0];
    $w->subs->onPostCreated(1, $t3, $m, 'published');
    T::same(null, $w->subs->status($m, 'topic', $t3), 'created: Antworten abonnieren nicht');
    $w->subs->savePreferences($m, ['auto_subscribe' => 'all']);
    $w->subs->onPostCreated(1, $t3, $m, 'published');
    T::same('instant', $w->subs->status($m, 'topic', $t3)['mode'] ?? null, 'all: Antwort abonniert');

    $w->subs->subscribe($m, 'topic', $t3, 'none');
    $w->subs->onPostCreated(1, $t3, $m, 'published');
    T::same('none', $w->subs->status($m, 'topic', $t3)['mode'] ?? null, 'vorhandenes none bleibt');

    $off = $w->member();
    $w->subs->savePreferences($off, ['auto_subscribe' => 'off']);
    $w->subs->onTopicCreated($w->topic, $w->forum, $off, 'published', $w->first);
    T::same(null, $w->subs->status($off, 'topic', $w->topic), 'off: kein Auto-Abo');

    $banned = $w->member();
    $w->db->ban(['user' => $banned, 'scope' => 'write']);
    $w->hooks = [];
    $w->subs->onPostCreated(1, $w->topic, $banned, 'published');
    T::same([null, []], [$w->subs->status($banned, 'topic', $w->topic), $w->hooks], 'can ablehnend: kein Abo, kein Hook, keine Ausnahme');
    $w->db->failOn = ['/INSERT IGNORE INTO cms_community_subscriptions/'];
    cnLogReset();
    $w->subs->onPostCreated(1, $w->topic, $w->member(), 'published');
    T::check(str_contains(cnLog(), 'Auto-Abo'), 'Datenbankfehler: Warnung, keine Ausnahme');
});

T::run('Präferenzen: Standards, Speichern, ungültig ⇒ invalid_input ohne Schreiben, fremde Schlüssel bleiben, Sperre in der Transaktion', static function (): void {
    $w = new CnWorld();
    $m = $w->member();
    T::same(['auto_subscribe' => 'all', 'notify_mail' => true, 'notify_types' => ['community.reply' => true, 'community.new_topic' => true, 'community.quote' => true, 'community.reaction' => true, 'community.solution' => true], 'locale' => null], $w->subs->preferences($m), 'Standards');
    $w->db->execute('UPDATE cms_community_user_stats SET settings_json = ? WHERE user_id = ?', ['{"editor":"markdown","online":false}', $m]);
    $mark = $w->mark();
    $saved = $w->subs->savePreferences($m, ['notify_mail' => false, 'notify_types' => ['community.reaction' => false], 'locale' => 'en']);
    T::same(true, $saved['success'], 'gespeichert');
    T::same(['editor' => 'markdown', 'online' => false, 'notify_mail' => false, 'notify_types' => ['community.reaction' => false], 'locale' => 'en'], $w->settingsOf($m), 'fremde Schlüssel bleiben, neue ergänzt');
    T::same(false, $saved['preferences']['notify_types']['community.reaction'], 'Ergebnis enthält die neuen Präferenzen');
    $locked = array_values(array_filter(array_keys($w->since($mark)), static fn (int $i): bool => str_contains($w->db->log[$mark + $i], 'SELECT settings_json FROM cms_community_user_stats') && str_contains($w->db->log[$mark + $i], 'FOR UPDATE')));
    T::check($locked !== [] && $w->db->txAt($mark + $locked[0]), 'FOR UPDATE auf die Statistikzeile in der Transaktion');
    foreach ([['auto_subscribe' => 'immer'], ['notify_mail' => 'ja'], ['notify_types' => ['community.moderation' => false]], ['notify_types' => ['community.reply' => 1]], ['locale' => 'fr'], ['farbe' => 'blau'], []] as $bad) {
        $mark = $w->mark();
        T::same(['success' => false, 'error' => 'invalid_input'], $w->subs->savePreferences($m, $bad), 'ungültig: ' . json_encode($bad));
        T::same([], $w->since($mark), 'ungültig: keine Anweisung');
    }
});

T::run('Token: hin und zurück, verändert, anderes Geheimnis, ohne Geheimnis, HKDF-Domäne; unsubscribeByToken', static function (): void {
    $w = new CnWorld();
    $m = $w->member();
    $token = (string) $w->subs->token($m, 'topic:' . $w->topic);
    T::same(1, preg_match(SubscriptionService::TOKEN_PATTERN, $token), 'Format {uid}.t{id}.{sig}');
    T::same(['user_id' => $m, 'scope' => 'topic', 'target_id' => $w->topic], $w->subs->verifyToken($token), 'hin und zurück');
    T::same(['user_id' => $m, 'scope' => 'all', 'target_id' => 0], $w->subs->verifyToken((string) $w->subs->token($m, 'all')), 'Bereich all');
    [$uid, $scope, $sig] = explode('.', $token);
    $other = $w->member();
    $flip = substr($sig, 0, -1) . (substr($sig, -1) === '0' ? '1' : '0');
    foreach ([
        'Signatur geändert' => "{$uid}.{$scope}.{$flip}",
        'Benutzer vertauscht' => "{$other}.{$scope}.{$sig}",
        'Bereich geändert' => "{$uid}.f{$w->topic}.{$sig}",
        'Bereich all' => "{$uid}.a.{$sig}",
        'Format falsch' => "{$uid}.{$scope}",
        'Großbuchstaben' => strtoupper($token),
    ] as $label => $bad) {
        T::same(null, $w->subs->verifyToken($bad), $label . ' ⇒ null');
    }
    $key = hash_hkdf('sha256', CN_SECRET, 32, SubscriptionService::TOKEN_INFO);
    T::same(substr(hash_hmac('sha256', "v1|{$m}|t{$w->topic}", $key), 0, 32), $sig, 'Signatur = HMAC mit HKDF-Schlüssel (Domäne TOKEN_INFO)');
    T::check($sig !== substr(hash_hmac('sha256', "v1|{$m}|t{$w->topic}", CN_SECRET), 0, 32), 'Signatur ≠ HMAC mit rohem Geheimnis');
    $w->secret = 'anderes-geheimnis-0123456789abcdef';
    $w->rebuild();
    T::check($w->subs->token($m, 'topic:' . $w->topic) !== $token && $w->subs->verifyToken($token) === null, 'anderes Geheimnis ⇒ anderes Token, altes ungültig');
    $w->secret = 'REPLACE_VIA_INSTALLER';
    $w->rebuild();
    T::same([null, null, false], [$w->subs->token($m, 'all'), $w->subs->verifyToken($token), $w->subs->canSign()], 'ohne brauchbares Geheimnis: kein Token');

    $w = new CnWorld();
    $m = $w->member();
    $w->subscribe($m);
    $w->db->ban(['user' => $m, 'scope' => 'full']);
    $calls = 0;
    $w->subs = new SubscriptionService($w->db, $w->settings, static fn (): int => cnAt(), static function () use (&$calls): AccessDecision {
        $calls++;

        return AccessDecision::deny('no_permission');
    }, static function (): void {
    }, null, static fn (): string => CN_SECRET);
    $topicToken = (string) $w->subs->token($m, 'topic:' . $w->topic);
    $result = $w->subs->unsubscribeByToken($topicToken);
    T::same(['success' => true, 'user_id' => $m, 'scope' => 'topic', 'target_id' => $w->topic, 'changed' => true], $result, 'Thema: Modus none (auch gesperrt)');
    T::same('none', $w->subs->status($m, 'topic', $w->topic)['mode'] ?? null, 'Abo bleibt mit none (In-App bleibt)');
    T::same(false, $w->subs->unsubscribeByToken($topicToken)['changed'], 'idempotent');
    T::same(true, $w->subs->unsubscribeByToken((string) $w->subs->token($m, 'forum:' . $w->forum))['success'], 'fehlendes Forum-Abo: Erfolg');
    T::same(true, $w->subs->unsubscribeByToken((string) $w->subs->token($m, 'all'))['changed'], 'alle: notify_mail aus');
    T::same(false, $w->subs->preferences($m)['notify_mail'], 'notify_mail = false gespeichert');
    T::same(['success' => false, 'error' => 'invalid_token'], $w->subs->unsubscribeByToken('1.a.' . str_repeat('0', 32)), 'ungültig ⇒ invalid_token');
    T::same(0, $calls, 'kein can()-Aufruf');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Aufträge und Empfänger
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Aufträge: je Hook genau ein INSERT, wartend keiner, Freigabe zwei; Fehler beim INSERT ⇒ Warnung', static function (): void {
    $w = new CnWorld();
    $d = $w->dispatcher;
    $kinds = static fn (CnWorld $w): array => array_map(static fn (array $r): string => $r['kind'] . ':' . ($r['detail'] ?? '-'), $w->db->rows('community_notify_queue', [], 'id'));
    $mark = $w->mark();
    $d->onTopicCreated($w->topic, $w->forum, $w->author, 'published', $w->first);
    T::same(1, count(array_filter($w->since($mark), static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO cms_community_notify_queue'))), 'TOPIC_CREATED: genau ein INSERT');
    $d->onTopicCreated($w->topic, $w->forum, $w->author, 'pending', $w->first);
    $d->onPostCreated(5, $w->topic, $w->author, 'pending');
    T::same(['new_topic:-'], $kinds($w), 'wartende Inhalte: kein Auftrag');
    $d->onPostCreated(5, $w->topic, $w->author, 'published');
    $d->onTopicApproved($w->topic, $w->forum, 9, $w->first);
    $d->onPostApproved(5, $w->topic, 9);
    $d->onModerated('rejected', $w->topic, 5, 9);
    $d->onModerated('hidden', $w->topic, 0, 0);
    $d->onModerated('approved', $w->topic, 5, 9);
    $d->onSolutionMarked($w->topic, 5, 9);
    $d->onReportResolved(7, 'valid', 9);
    $d->onTopicMoved($w->topic, 1, 2, 9, 0);
    T::same(['new_topic:-', 'reply:-', 'new_topic:-', 'moderation:approved', 'reply:-', 'moderation:approved', 'moderation:rejected', 'moderation:hidden', 'solution:-', 'report:valid', 'moderation:moved'], $kinds($w), 'Freigabe: Auftrag plus moderation(approved); onModerated nur rejected|hidden');
    T::same([cnUtc(), 3, 7], [$w->db->row('community_notify_queue', ['kind' => 'report'])['created_at'], (int) $w->db->row('community_notify_queue', ['kind' => 'report'])['phase'], (int) $w->db->row('community_notify_queue', ['kind' => 'report'])['post_id']], 'report: Meldungs-ID in post_id, Phase 3, created_at = UTC der Uhr');
    $w->db->failOn = ['/INSERT INTO cms_community_notify_queue/'];
    cnLogReset();
    $d->onPostCreated(6, $w->topic, $w->author, 'published');
    T::check(str_contains(cnLog(), 'Zustellauftrag nicht angelegt'), 'Fehler beim INSERT: Warnung, keine Ausnahme');
});

T::run('Empfänger: 6 Abonnenten (bestätigt, unbestätigt, none, inaktiv, ohne Leserecht, Akteur) ⇒ In-App, Mail und Nichts wie §2.8', static function (): void {
    $w = new CnWorld();
    $actor = $w->member();
    $ok = $w->member();
    $unverified = $w->member('member', false);
    $none = $w->member();
    $inactive = $w->member('member', true, 'inactive');
    $banned = $w->member();
    $w->db->ban(['user' => $banned, 'scope' => 'full']);
    foreach ([$actor, $ok, $unverified, $inactive, $banned] as $u) {
        $w->subscribe($u);
    }
    $w->subscribe($none, null, 'topic', 'none');
    $post = $w->reply($actor, 'Hallo');
    $result = $w->run();
    T::same(1, count($w->notes($ok)), 'bestätigt: Hinweis');
    T::same(1, count($w->mailsTo($ok)), 'bestätigt: Mail');
    T::same([1, 0], [count($w->notes($unverified)), count($w->mailsTo($unverified))], 'unbestätigt: nur In-App');
    T::same([1, 0], [count($w->notes($none)), count($w->mailsTo($none))], 'Modus none: nur In-App');
    T::same([0, 0], [count($w->notes($inactive)), count($w->mailsTo($inactive))], 'inaktiv: nichts');
    T::same([0, 0], [count($w->notes($banned)), count($w->mailsTo($banned))], 'Sperre full (ohne Leserecht): nichts');
    T::same([0, 0], [count($w->notes($actor)), count($w->mailsTo($actor))], 'Akteur: nichts');
    T::same(['jobs' => 1, 'recipients' => 6, 'notified' => 3, 'mailed' => 1, 'expired' => 0, 'failed' => 0, 'stop' => 'empty'], $result, 'Ergebnis des Laufs');
    T::same(0, $w->queueCount(), 'Auftrag erledigt (gelöscht)');
    $note = $w->notes($ok)[0];
    T::same(['community.reply', 'community:topic:' . $w->topic, 'Neue Antwort in „Erstes Thema“', '/forum/beitrag/' . $post], [$note['type'], $note['group_key'], $note['title'], $note['url']], 'Typ, Gruppe, Titel, Link');
    T::same(cnUtc(), $w->db->row('community_subscriptions', ['user_id' => $ok])['notified_at'], 'notified_at = Stapelzeit (UTC)');
    T::same(null, $w->db->row('community_subscriptions', ['user_id' => $unverified])['notified_at'], 'ohne Mail bleibt das Tor unbeansprucht');
});

T::run('Lecktest: Forum mit Gruppen-Rechtezeile, Sperre full, guests_can_read + inaktives Konto, Thema in internes Forum verschoben ⇒ nichts', static function (): void {
    $w = new CnWorld(['guests_can_read' => true]);
    $group = $w->db->group('Team');
    $teamForum = $w->db->forum(['title' => 'Team', 'inherit' => false]);
    (new ForumRepository($w->db))->savePermissions($teamForum, [['subject_type' => 'group', 'subject_key' => (string) $group, 'permissions' => ['read' => 1, 'reply' => 1, 'create_topic' => 1]], ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 0]]], cnUtc());
    $w->access->flush();
    [$t] = $w->thread($teamForum, $w->author, 'Teamthema');
    $insider = $w->member();
    $w->db->join($insider, $group);
    $outsider = $w->member();
    $inactive = $w->member('member', true, 'inactive');
    $full = $w->member();
    $w->db->ban(['user' => $full, 'scope' => 'full']);
    foreach ([$insider, $outsider, $inactive, $full] as $u) {
        $w->subscribe($u, $t);
    }
    $w->reply($w->author, 'Antwort', $t);
    $w->run();
    T::same([1, 0, 0, 0], array_map(static fn (int $u): int => count($w->notes($u)), [$insider, $outsider, $inactive, $full]), 'nur das Gruppenmitglied erhält den Hinweis');
    T::same(1, count($w->mails), 'eine Mail (Gruppenmitglied)');
    // Öffentliches Forum mit guests_can_read: can() behandelt ein inaktives Konto wie einen Gast und erlaubt das Lesen.
    T::same(true, $w->access->can($inactive, 'read', ['topic_id' => $w->topic])->allowed, 'Gegenprobe: das inaktive Konto darf als Gast lesen');
    $w->subscribe($inactive);
    $w->reply($w->author);
    $w->run();
    T::same([0, 0], [count($w->notes($inactive)), count($w->mailsTo($inactive))], 'guests_can_read + inaktives Konto: keine Zeile, keine Mail');

    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub);
    $w->reply($w->member());
    $internal = $w->db->forum(['title' => 'Intern', 'status' => 'hidden']);
    $w->db->execute('UPDATE cms_community_topics SET forum_id = ? WHERE id = ?', [$internal, $w->topic]);
    $w->db->execute('UPDATE cms_community_posts SET forum_id = ? WHERE topic_id = ?', [$internal, $w->topic]);
    $w->run();
    T::same([0, 0], [count($w->notes($sub)), count($w->mails)], 'nach dem Anlegen in ein internes Forum verschoben: nichts');
});

T::run('Ignorieren: ignoriert den Akteur ⇒ nichts; Akteur moderiert das Forum ⇒ zugestellt; moderation ignoriert die Liste nicht', static function (): void {
    $w = new CnWorld();
    $actor = $w->member();
    $sub = $w->member();
    $w->subscribe($sub);
    $w->db->execute('INSERT INTO cms_community_ignores (user_id, ignored_user_id, created_at) VALUES (?, ?, ?)', [$sub, $actor, cnUtc()]);
    $w->reply($actor);
    $w->run();
    T::same(0, count($w->notes($sub)), 'ignoriert den Akteur: nichts');

    $mod = $w->member('moderator');
    $w->db->execute('INSERT INTO cms_community_ignores (user_id, ignored_user_id, created_at) VALUES (?, ?, ?)', [$sub, $mod, cnUtc()]);
    $w->reply($mod);
    $w->run();
    T::same(1, count($w->notes($sub)), 'Akteur moderiert das Forum: zugestellt');

    // Ein früherer Forum-Moderator, dem die Zuständigkeit inzwischen entzogen ist: der Autor ignoriert ihn, der Hinweis kommt trotzdem.
    $former = $w->member();
    $post = $w->post($w->topic, $sub, 'Antwort des Abonnenten');
    $w->db->execute('UPDATE cms_community_posts SET status = ? WHERE id = ?', ['rejected', $post]);
    $w->db->execute('INSERT INTO cms_community_ignores (user_id, ignored_user_id, created_at) VALUES (?, ?, ?)', [$sub, $former, cnUtc()]);
    $w->dispatcher->onModerated('rejected', $w->topic, $post, $former);
    $w->run();
    $notes = array_values(array_filter($w->notes($sub), static fn (array $n): bool => $n['type'] === 'community.moderation'));
    T::same(1, count($notes), 'moderation: Ignorieren gilt nicht (auch wenn der Akteur nicht mehr moderiert)');
});

T::run('Wirkung: notify_mail (aus: In-App wie bisher, keine Mail, notified_at bleibt NULL, kein Token); Präferenzen notify_types und Core email_notifications', static function (): void {
    $w = new CnWorld(['notify_mail' => false]);
    $sub = $w->member();
    $w->subscribe($sub);
    $w->reply($w->member());
    $tokensBefore = $w->tokens;
    $w->run();
    T::same([1, 0], [count($w->notes($sub)), count($w->mails)], 'notify_mail aus: In-App, keine Mail');
    T::same(null, $w->db->row('community_subscriptions', ['user_id' => $sub])['notified_at'], 'notified_at bleibt NULL');
    T::check(!str_contains(implode("\n", $w->db->log), 'cms_mail_queue'), 'kein Eintrag in mail_queue');

    $w = new CnWorld();
    $off = $w->member();
    $core = $w->member();
    $types = $w->member();
    foreach ([$off, $core, $types] as $u) {
        $w->subscribe($u);
    }
    $w->subs->savePreferences($off, ['notify_mail' => false]);
    $w->db->execute("INSERT INTO cms_user_meta (user_id, meta_key, meta_value) VALUES (?, 'notification_preferences', ?)", [$core, json_encode(['email_notifications' => false])]);
    $w->subs->savePreferences($types, ['notify_types' => ['community.reply' => false]]);
    $w->reply($w->member());
    $w->run();
    T::same([1, 0], [count($w->notes($off)), count($w->mailsTo($off))], 'Präferenz notify_mail aus: nur In-App');
    T::same([1, 0], [count($w->notes($core)), count($w->mailsTo($core))], 'Core email_notifications aus: nur In-App');
    T::same([0, 0], [count($w->notes($types)), count($w->mailsTo($types))], 'notify_types.reply aus: nichts');
    T::same(true, $w->subs->savePreferences($types, ['notify_types' => ['community.reply' => true]])['success'], 'wieder an');
});

T::run('Schon gelesen: last_read_post_id ≥ Beitrag oder Marke ≥ created_at ⇒ nichts', static function (): void {
    $w = new CnWorld();
    $read = $w->member();
    $marked = $w->member();
    $global = $w->member();
    foreach ([$read, $marked, $global] as $u) {
        $w->subscribe($u);
    }
    $post = $w->reply($w->member());
    $w->db->execute('INSERT INTO cms_community_read_states (user_id, topic_id, last_read_post_id, updated_at) VALUES (?, ?, ?, ?)', [$read, $w->topic, $post, cnUtc(-10)]);
    $w->db->execute('INSERT INTO cms_community_forum_read_marks (user_id, forum_id, marked_at) VALUES (?, ?, ?)', [$marked, $w->forum, cnUtc(-30)]);
    $w->db->execute('INSERT INTO cms_community_forum_read_marks (user_id, forum_id, marked_at) VALUES (?, 0, ?)', [$global, cnUtc(-60)]);
    $w->run();
    T::same([0, 0, 0], [count($w->notes($read)), count($w->notes($marked)), count($w->notes($global))], 'gelesen, Forum-Marke, globale Marke (gleich created_at): nichts');
});

T::run('Bündelung: zwei Antworten ⇒ eine ungelesene Zeile, event_count 2, title_many mit 2, url_many; markTopicRead ⇒ nächste Antwort neue Zeile', static function (): void {
    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub);
    $w->reply($w->member());
    $w->reply($w->member());
    $w->run();
    $notes = $w->notes($sub);
    T::same(1, count($notes), 'eine Zeile');
    T::same([2, '2 neue Antworten in „Erstes Thema“', '/forum/thema/' . $w->topic . '/ungelesen'], [(int) $notes[0]['event_count'], $notes[0]['title'], $notes[0]['url']], 'event_count, title_many, url_many');
    T::same(2, $w->dispatcher->markTopicRead($sub, $w->topic) + 1, 'markTopicRead markiert die Themengruppe');
    $w->reply($w->member());
    $w->run();
    T::same(2, count($w->notes($sub)), 'nach dem Lesen: neue Zeile');
    T::same(0, $w->dispatcher->markTopicRead(0, $w->topic), 'Gast: 0');
});

T::run('Tor Thema: erste Mail, ohne Besuch nur In-App, advance öffnet, Marke öffnet, Gleichheit öffnet, abgelehnte Mail gibt das Tor frei', static function (): void {
    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub);
    $w->reply($w->member());
    $w->run();
    T::same(1, count($w->mailsTo($sub)), '1. Mail');
    $w->at(60);
    $w->reply($w->member());
    $w->run();
    T::same([1, 2], [count($w->mailsTo($sub)), (int) $w->notes($sub)[0]['event_count']], 'ohne Besuch: nur In-App');
    $w->at(120);
    $w->reads->advance($sub, $w->topic, 999999);
    $w->at(180);
    $w->reply($w->member());
    $w->run();
    T::same(2, count($w->mailsTo($sub)), 'Lesestatus nach der Mail öffnet');
    $w->at(300);
    $w->db->execute('INSERT INTO cms_community_forum_read_marks (user_id, forum_id, marked_at) VALUES (?, ?, ?)', [$sub, $w->forum, cnUtc(180)]);
    $w->reply($w->member());
    $w->run();
    T::same(3, count($w->mailsTo($sub)), 'Marke gleich der letzten Mail (>=) öffnet');

    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub);
    $w->mailResult = false;
    $w->reply($w->member());
    $w->run();
    T::same(null, $w->db->row('community_subscriptions', ['user_id' => $sub])['notified_at'], 'Mail-Closure false: notified_at zurück');
    $w->mailResult = true;
    $w->at(60);
    $w->reply($w->member());
    $w->run();
    T::same(2, count($w->mailsTo($sub)), 'nächste Antwort mailt wieder (zwei Versuche aufgezeichnet)');
    T::same(cnUtc(60), $w->db->row('community_subscriptions', ['user_id' => $sub])['notified_at'], 'notified_at = Zeit der erfolgreichen Mail');
});

T::run('Tor: zwischen Lesen und Beanspruchen von einem anderen Lauf beansprucht ⇒ keine zweite Mail (CAS auf notified_at)', static function (): void {
    // Tor nie beansprucht (NULL): ein anderer Lauf setzt notified_at zwischen Empfängerabfrage und claimMail.
    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub);
    $w->reply($w->member());
    $w->db->beforeExecute['/^UPDATE cms_community_subscriptions SET notified_at/'] = static function () use ($w, $sub): void {
        $w->db->execute('UPDATE cms_community_subscriptions SET notified_at = ? WHERE user_id = ?', [cnUtc(-5), $sub]);
    };
    $w->run();
    T::same([1, 0, cnUtc(-5)], [count($w->notes($sub)), count($w->mails), $w->db->row('community_subscriptions', ['user_id' => $sub])['notified_at']], 'NULL gelesen, inzwischen beansprucht: nur In-App');

    // Tor offen nach Besuch: der gelesene Zeitpunkt ändert sich vor dem Beanspruchen.
    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub, null, 'topic', 'instant', cnUtc(-600));
    $w->db->execute('INSERT INTO cms_community_read_states (user_id, topic_id, last_read_post_id, updated_at) VALUES (?, ?, ?, ?)', [$sub, $w->topic, $w->first, cnUtc(-300)]);
    $w->reply($w->member());
    $w->db->beforeExecute['/^UPDATE cms_community_subscriptions SET notified_at/'] = static function () use ($w, $sub): void {
        $w->db->execute('UPDATE cms_community_subscriptions SET notified_at = ? WHERE user_id = ?', [cnUtc(-5), $sub]);
    };
    $w->run();
    T::same([1, 0], [count($w->notes($sub)), count($w->mails)], 'Zeitpunkt gelesen, inzwischen erneut beansprucht: keine zweite Mail');
});

T::run('Tor Forum: new_topic mailt, zweites nicht, touchSeen() öffnet, globale Marke öffnet', static function (): void {
    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub, $w->forum, 'forum');
    $starter = $w->member();
    $newTopic = static function (CnWorld $w, int $starter, string $title): void {
        [$t, $p] = $w->thread($w->forum, $starter, $title);
        $w->db->execute('UPDATE cms_community_topics SET created_at = ? WHERE id = ?', [cnUtc((int) $w->time['now'] - cnAt() - 5), $t]);
        $w->dispatcher->onTopicCreated($t, $w->forum, $starter, 'published', $p);
    };
    $newTopic($w, $starter, 'Neu 1');
    $w->run();
    T::same(1, count($w->mailsTo($sub)), 'new_topic mailt');
    T::same(['community.new_topic', 'community:forum:' . $w->forum, 'Neues Thema in „Allgemein“: Neu 1'], [$w->notes($sub)[0]['type'], $w->notes($sub)[0]['group_key'], $w->notes($sub)[0]['title']], 'Typ, Gruppe, Titel');
    $w->at(60);
    $newTopic($w, $starter, 'Neu 2');
    $w->run();
    T::same(1, count($w->mailsTo($sub)), 'zweites ohne Besuch: keine Mail');
    $w->at(120);
    T::same(true, $w->reads->touchSeen($sub), 'touchSeen schreibt');
    T::same(false, $w->reads->touchSeen($sub), 'touchSeen: höchstens einmal je 5 Minuten');
    $w->at(180);
    $newTopic($w, $starter, 'Neu 3');
    $w->run();
    T::same(2, count($w->mailsTo($sub)), 'touchSeen öffnet');
    $w->at(240);
    $w->db->execute('INSERT INTO cms_community_forum_read_marks (user_id, forum_id, marked_at) VALUES (?, 0, ?)', [$sub, cnUtc(200)]);
    $newTopic($w, $starter, 'Neu 4');
    $w->run();
    T::same(3, count($w->mailsTo($sub)), 'globale Marke öffnet');
});

T::run('touchSeen: UTC, Fenster 300 s, ohne Statistikzeile nichts, Gast nichts', static function (): void {
    $w = new CnWorld();
    $m = $w->member();
    T::same(true, $w->reads->touchSeen($m), 'erster Besuch');
    T::same(cnUtc(), $w->db->row('community_user_stats', ['user_id' => $m])['last_seen_at'], 'last_seen_at = UTC der Uhr');
    $w->at(299);
    T::same(false, $w->reads->touchSeen($m), 'nach 299 s nicht');
    $w->at(301);
    T::same(true, $w->reads->touchSeen($m), 'nach 301 s wieder');
    T::same([false, false], [$w->reads->touchSeen(0), $w->reads->touchSeen($w->db->user())], 'Gast, Konto ohne Statistikzeile: nichts');
});

T::run('Zitate: Zitierte X (auch Abonnent) und Y; eigenes Zitat nichts; 12 Verweise ⇒ 10; Marker im Codeblock und nicht zitierbare Quelle ⇒ niemand; ohne Mail', static function (): void {
    $w = new CnWorld();
    $actor = $w->member();
    $x = $w->member();
    $y = $w->member();
    $w->subscribe($x);
    $px = $w->post($w->topic, $x, 'Text X');
    $py = $w->post($w->topic, $y, 'Text Y');
    $own = $w->post($w->topic, $actor, 'eigener Text');
    $body = "> [quote=post:{$px}]\n> X\n\n> [quote=post:{$py}]\n> Y\n\n> [quote=post:{$own}]\n> eigen\n\nAntwort";
    $post = $w->reply($actor, $body);
    $w->run();
    $types = static fn (CnWorld $w, int $u): array => array_map(static fn (array $n): string => $n['type'], $w->notes($u));
    T::same(['community.quote', 'community.reply'], $types($w, $x), 'X: Zitat und Antwort (Abonnent)');
    T::same(['community.quote'], $types($w, $y), 'Y: Zitat');
    T::same([], $types($w, $actor), 'eigenes Zitat: nichts');
    T::same(['community:quote:' . $w->topic, 'Zum Beitrag mit dem Zitat.', '/forum/beitrag/' . $post], [$w->notes($y)[0]['group_key'], $w->notes($y)[0]['message'], $w->notes($y)[0]['url']], 'Gruppe, Text, Link des Zitat-Hinweises');
    T::same([0, 1], [count($w->mailsTo($y)), count($w->mailsTo($x))], 'Zitat-Hinweis ohne Mail (X mailt nur als Abonnent)');

    $w = new CnWorld();
    $actor = $w->member();
    $authors = [];
    $lines = [];
    for ($i = 0; $i < 12; $i++) {
        $authors[$i] = $w->member();
        $source = $w->post($w->topic, $authors[$i], 'Quelle ' . $i);
        $lines[] = "> [quote=post:{$source}]\n> Zitat {$i}\n";
    }
    $w->reply($actor, implode("\n", $lines) . "\nAntwort");
    $w->run();
    T::same(array_merge(array_fill(0, 10, 1), [0, 0]), array_map(static fn (int $u): int => count($w->notes($u)), $authors), '12 Verweise ⇒ die ersten 10');

    $w = new CnWorld();
    $actor = $w->member();
    $coded = $w->member();
    $hiddenAuthor = $w->member();
    $source = $w->post($w->topic, $coded, 'Quelle');
    $internal = $w->db->forum(['title' => 'Intern', 'status' => 'hidden']);
    [$it] = $w->thread($internal, $hiddenAuthor, 'Intern');
    $hiddenSource = $w->post($it, $hiddenAuthor, 'Geheim');
    $w->reply($actor, "```\n> [quote=post:{$source}]\n> im Code\n```\n\n> [quote=post:{$hiddenSource}]\n> fremd\n\nAntwort");
    $w->run();
    T::same([0, 0], [count($w->notes($coded)), count($w->notes($hiddenAuthor))], 'Marker im Codeblock und nicht zitierbare Quelle: kein Empfänger');
});

T::run('Stapel/Cursor: 250 Abonnenten, Restzeit erzwingt Abbruch nach einem Stapel ⇒ drei Läufe, jeder genau einmal, Lease frei nach Abbruch', static function (): void {
    $w = new CnWorld(['notify_mail' => false]);
    $subs = [];
    for ($i = 0; $i < 250; $i++) {
        $subs[] = $u = $w->member();
        $w->subscribe($u);
    }
    $w->reply($w->member());
    // Vor jedem Auftrag ist Zeit übrig, nach jedem Stapel nicht mehr: genau ein Stapel je Lauf.
    $checks = 0;
    $w->remaining = static function () use (&$checks): ?float {
        return ++$checks % 2 === 0 ? 5.0 : null;
    };
    $first = $w->run();
    T::same('budget', $first['stop'], 'Abbruch durch Restzeit nach einem Stapel');
    $job = $w->db->row('community_notify_queue', []);
    T::same([null, null, 1, $subs[99]], [$job['lease_token'], $job['locked_until'], (int) $job['phase'], (int) $job['cursor_user_id']], 'Lease frei, Cursor nach 100 Empfängern');
    T::same(100, count(array_filter($subs, static fn (int $u): bool => count($w->notes($u)) === 1)), 'erster Lauf: 100 Empfänger');
    $second = $w->run();
    $third = $w->run();
    T::same(['budget', 'budget'], [$second['stop'], $third['stop']], 'zweiter und dritter Lauf je ein Stapel');
    $w->remaining = static fn (): ?float => null;
    $fourth = $w->run();
    T::same(['empty', 0], [$fourth['stop'], $fourth['notified']], 'vierter Lauf: Phase 2 ohne Empfänger, erledigt');
    T::same(250, count(array_filter($subs, static fn (int $u): bool => count($w->notes($u)) === 1 && (int) $w->notes($u)[0]['event_count'] === 1)), 'jeder Empfänger genau einmal (event_count 1)');
    T::same(0, $w->queueCount(), 'Auftrag erledigt');
    T::check($w->flushes >= 4, 'flush je Stapel und Auftrag (' . $w->flushes . ')');
});

T::run('Wiederaufnahme: Fehler beim Hinweis des 37. Empfängers ⇒ dieser zurückgenommen, 36 gebucht, attempts 1; nächster Lauf ab 37, alle genau einmal', static function (): void {
    $w = new CnWorld(['notify_mail' => false]);
    $subs = [];
    for ($i = 0; $i < 50; $i++) {
        $subs[] = $u = $w->member();
        $w->subscribe($u);
    }
    $w->reply($w->member());
    $target = $subs[36];
    $real = $w->core;
    $failures = 0;
    $w->dispatcher = new NotificationDispatcher(
        $w->db,
        $w->settings,
        fn (): int => (int) $w->time['now'],
        fn (?int $u, string $a, array $c): AccessDecision => $w->access->can($u, $a, $c),
        static function (int $userId, string $type, ?string $group, array $texts) use ($real, $target, &$failures): array {
            if ($userId === $target && $failures++ === 0) {
                throw new \PDOException('Testfehler beim Hinweis');
            }

            return $real->notifyGrouped($userId, $type, $group, $texts);
        },
        null,
        static fn (): bool => true,
        fn (): string => sprintf('%032x', ++$w->tokens),
        static fn (): ?float => null,
        static function (): void {
        },
        $w->subs
    );
    $first = $w->run();
    T::same(36, count(array_filter($subs, static fn (int $u): bool => count($w->notes($u)) === 1)), '36 gebucht');
    T::same(0, count($w->notes($target)), 'der 37. zurückgenommen');
    $job = $w->db->row('community_notify_queue', []);
    T::same([1, $subs[35], null], [(int) $job['attempts'], (int) $job['cursor_user_id'], $job['lease_token']], 'attempts 1, Cursor beim 36., Lease frei');
    T::same(1, $first['failed'], 'failed 1');
    $w->at(NotificationDispatcher::LEASE_SECONDS + 1);
    $w->run();
    T::same(50, count(array_filter($subs, static fn (int $u): bool => count($w->notes($u)) === 1 && (int) $w->notes($u)[0]['event_count'] === 1)), 'nächster Lauf: alle genau einmal');
    T::same(0, $w->queueCount(), 'erledigt');
});

T::run('Lease: fremde gültige übersprungen, abgelaufene übernommen (attempts + 1), verlorene Lease ⇒ Rücknahme, attempts > 5 ⇒ gelöscht + Warnung', static function (): void {
    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub);
    $post = $w->post($w->topic, $w->member());
    $id = $w->db->queueJob(['kind' => 'reply', 'topic' => $w->topic, 'post' => $post, 'lease_token' => str_repeat('f', 32), 'locked_until' => cnUtc(60), 'created_at' => cnUtc(-10)]);
    $r = $w->run();
    T::same([0, 0, 'empty'], [$r['jobs'], count($w->notes($sub)), $r['stop']], 'fremde gültige Lease: übersprungen');
    $w->at(61);
    $w->run();
    T::same(1, count($w->notes($sub)), 'abgelaufene Lease übernommen');

    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub);
    $post = $w->post($w->topic, $w->member());
    $id = $w->db->queueJob(['kind' => 'reply', 'topic' => $w->topic, 'post' => $post, 'lease_token' => str_repeat('e', 32), 'locked_until' => cnUtc(-1), 'attempts' => 2, 'created_at' => cnUtc(-10)]);
    $repo = new SubscriptionRepository($w->db);
    $row = $repo->lease($id, str_repeat('a', 32), cnUtc(120), cnUtc());
    T::same([3, str_repeat('a', 32)], [$row['attempts'], $row['lease_token']], 'abgelaufene fremde Lease: attempts + 1');
    T::same(null, $repo->lease($id, str_repeat('b', 32), cnUtc(120), cnUtc()), 'gültige Lease: kein zweites Belegen');
    T::same(false, $repo->advance($id, str_repeat('b', 32), 1, 5, cnUtc()), 'advance mit falschem Token: false');
    $w->db->execute('UPDATE cms_community_notify_queue SET lease_token = NULL, locked_until = NULL WHERE id = ?', [$id]);
    // Verlorene Lease mitten im Stapel: ein anderer Lauf übernimmt vor dem Cursor-Update.
    $w->db->beforeExecute['/^UPDATE cms_community_notify_queue SET phase = /'] = static function () use ($w, $id): void {
        $w->db->execute('UPDATE cms_community_notify_queue SET lease_token = ? WHERE id = ?', [str_repeat('c', 32), $id]);
    };
    $w->run();
    T::same(0, count($w->notes($sub)), 'Lease verloren: Hinweis zurückgenommen');
    T::same(str_repeat('c', 32), $w->db->row('community_notify_queue', ['id' => $id])['lease_token'], 'Auftrag bleibt dem anderen Lauf');

    $w = new CnWorld();
    $post = $w->post($w->topic, $w->member());
    $w->db->queueJob(['kind' => 'reply', 'topic' => $w->topic, 'post' => $post, 'lease_token' => str_repeat('d', 32), 'locked_until' => cnUtc(-1), 'attempts' => 5, 'created_at' => cnUtc(-10)]);
    cnLogReset();
    $r = $w->run();
    T::same([1, 0], [$r['failed'], $w->queueCount()], 'attempts > 5: gelöscht, failed 1');
    T::check(str_contains(cnLog(), 'verworfen'), 'Warnung');
});

T::run('Verfall: 48 h + 1 s gelöscht ohne Hinweis, 47 h 59 min verarbeitet', static function (): void {
    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub);
    $old = $w->post($w->topic, $w->member());
    $young = $w->post($w->topic, $w->member());
    $w->db->queueJob(['kind' => 'reply', 'topic' => $w->topic, 'post' => $old, 'created_at' => cnUtc(-48 * 3600 - 1)]);
    $w->db->queueJob(['kind' => 'reply', 'topic' => $w->topic, 'post' => $young, 'created_at' => cnUtc(-47 * 3600 - 59 * 60)]);
    $r = $w->run();
    T::same([1, 1, 1], [$r['expired'], $r['jobs'], count($w->notes($sub))], 'einer verfallen, einer verarbeitet');
});

T::run('Zustand: Beitrag/Thema vor der Verarbeitung gelöscht oder ausgeblendet ⇒ nichts; Lösung gewechselt ⇒ nichts; Reaktion zurückgenommen ⇒ nichts', static function (): void {
    foreach (['post deleted' => ['posts', 'deleted'], 'post hidden' => ['posts', 'hidden'], 'topic hidden' => ['topics', 'hidden']] as $label => [$table, $status]) {
        $w = new CnWorld();
        $sub = $w->member();
        $w->subscribe($sub);
        $post = $w->reply($w->member());
        $w->db->execute("UPDATE cms_community_{$table} SET status = ? WHERE id = ?", [$status, $table === 'posts' ? $post : $w->topic]);
        $w->run();
        T::same([0, 0], [count($w->notes($sub)), $w->queueCount()], $label . ': nichts, Auftrag erledigt');
    }
    $w = new CnWorld();
    $solver = $w->member();
    $p = $w->post($w->topic, $solver);
    $other = $w->post($w->topic, $w->member());
    $w->dispatcher->onSolutionMarked($w->topic, $p, $w->author);
    $w->db->execute('UPDATE cms_community_topics SET solution_post_id = ? WHERE id = ?', [$other, $w->topic]);
    $w->run();
    T::same(0, count($w->notes($solver)), 'Lösung gewechselt: nichts');
    $w->db->execute('UPDATE cms_community_topics SET solution_post_id = ? WHERE id = ?', [$p, $w->topic]);
    $w->dispatcher->onSolutionMarked($w->topic, $p, $w->author);
    $w->run();
    $solution = $w->notes($solver)[0] ?? ['type' => null, 'title' => null, 'group_key' => 'fehlt'];
    T::same(['community.solution', 'Dein Beitrag in „Erstes Thema“ ist die Lösung', null], [$solution['type'], $solution['title'], $solution['group_key']], 'Lösung: Hinweis ohne Gruppe');

    $w = new CnWorld();
    $author = $w->member();
    $p = $w->post($w->topic, $author);
    $reactor = $w->member();
    $w->dispatcher->onReactionChanged($p, $reactor, 'like', '');
    $w->run();
    T::same(0, count($w->notes($author)), 'Reaktion ohne Zeile (zurückgenommen): nichts');
    $w->db->reaction(['post' => $p, 'user' => $reactor, 'reaction' => 'love']);
    $w->dispatcher->onReactionChanged($p, $reactor, 'like', '');
    $w->run();
    T::same(['community.reaction', 'community:reaction:' . $p, 'Reaktion: ❤️'], [$w->notes($author)[0]['type'], $w->notes($author)[0]['group_key'], $w->notes($author)[0]['message']], 'aktuelle Art als detail');
});

T::run('Moderation: freigegeben, abgelehnt (mit Grund), ausgeblendet für Beitrag und Thema; Akteur = Autor nichts; Autor ohne Leserecht ⇒ allgemeiner Text', static function (): void {
    $w = new CnWorld();
    $mod = $w->member('moderator');
    $a = $w->member();
    $p = $w->post($w->topic, $a, 'Beitrag', 'published');
    $w->dispatcher->onPostApproved($p, $w->topic, $mod);
    $w->run();
    $moderation = static fn (CnWorld $w, int $u): array => array_values(array_filter($w->notes($u), static fn (array $n): bool => $n['type'] === 'community.moderation'));
    T::same(['Dein Beitrag in „Erstes Thema“ wurde freigegeben', '/forum/beitrag/' . $p], [$moderation($w, $a)[0]['title'], $moderation($w, $a)[0]['url']], 'Beitrag freigegeben');
    $rejected = $w->post($w->topic, $a, 'Abgelehnt', 'rejected');
    $w->db->moderationLog(['action' => 'post.reject', 'target' => $rejected, 'actor' => $mod, 'reason' => "Spam\nmit Umbruch", 'created_at' => cnUtc()]);
    $w->dispatcher->onModerated('rejected', $w->topic, $rejected, $mod);
    $hidden = $w->post($w->topic, $a, 'Versteckt', 'hidden');
    $w->dispatcher->onModerated('hidden', $w->topic, $hidden, $mod);
    $w->run();
    $notes = $moderation($w, $a);
    T::same(['Dein Beitrag in „Erstes Thema“ wurde abgelehnt', 'Grund: Spam mit Umbruch', '/member/plugin/community?rejected=' . $rejected], [$notes[1]['title'], $notes[1]['message'], $notes[1]['url']], 'abgelehnt: Grund aus dem Protokoll, Link in den Mitgliederbereich');
    T::same(['Dein Beitrag in „Erstes Thema“ wurde ausgeblendet', 'Ohne Angabe eines Grundes.'], [$notes[2]['title'], $notes[2]['message']], 'ausgeblendet ohne Grund');
    T::same(0, count($w->mailsTo($a)), 'Moderation: keine Mail');

    [$t2, $f2] = $w->thread($w->forum, $a, 'Neues Thema');
    $w->dispatcher->onTopicApproved($t2, $w->forum, $mod, $f2);
    $w->db->execute('UPDATE cms_community_topics SET status = ? WHERE id = ?', ['hidden', $t2]);
    $w->dispatcher->onModerated('hidden', $t2, 0, $mod);
    $w->run();
    $notes = $moderation($w, $a);
    T::same(['Dein Thema „Neues Thema“ wurde freigegeben', 'Dein Thema „Neues Thema“ wurde ausgeblendet'], [$notes[3]['title'] ?? null, $notes[4]['title'] ?? null], 'Thema freigegeben und ausgeblendet');
    $w->db->execute('UPDATE cms_community_posts SET status = ? WHERE id = ?', ['rejected', $f2]);
    $w->db->execute('UPDATE cms_community_topics SET status = ? WHERE id = ?', ['deleted', $t2]);
    $w->dispatcher->onModerated('rejected', $t2, 0, $mod);
    $w->run();
    T::same(['Die Moderation hat über einen deiner Beiträge entschieden.', '', '/member/plugin/community'], [$moderation($w, $a)[5]['title'] ?? null, $moderation($w, $a)[5]['message'] ?? null, $moderation($w, $a)[5]['url'] ?? null], 'abgelehntes Thema (gelöscht, nicht lesbar): allgemeiner Text ohne Titel');

    $self = $w->post($w->topic, $mod, 'eigener', 'hidden');
    $w->dispatcher->onModerated('hidden', $w->topic, $self, $mod);
    $w->run();
    T::same(0, count($moderation($w, $mod)), 'Akteur = Autor: nichts');

    $w = new CnWorld();
    $mod = $w->member('moderator');
    $a = $w->member();
    $p = $w->post($w->topic, $a, 'Beitrag', 'hidden');
    $w->dispatcher->onModerated('hidden', $w->topic, $p, $mod);
    $internal = $w->db->forum(['title' => 'Intern', 'status' => 'hidden']);
    $w->db->execute('UPDATE cms_community_topics SET forum_id = ? WHERE id = ?', [$internal, $w->topic]);
    $w->db->execute('UPDATE cms_community_posts SET forum_id = ? WHERE topic_id = ?', [$internal, $w->topic]);
    $w->run();
    T::same(['Die Moderation hat über einen deiner Beiträge entschieden.', '/member/plugin/community'], [$moderation($w, $a)[0]['title'] ?? null, $moderation($w, $a)[0]['url'] ?? null], 'Autor ohne Leserecht: allgemeiner Text ohne Titel, Link Mitgliederbereich');
});

T::run('Verschiebehinweis: Starter erhält Ziel mit Titel; nicht bei Akteur = Starter; internes Zielforum ⇒ generic', static function (): void {
    $w = new CnWorld();
    $mod = $w->member('moderator');
    $target = $w->db->forum(['title' => 'Ziel', 'slug' => 'ziel']);
    $w->db->execute('UPDATE cms_community_topics SET forum_id = ? WHERE id = ?', [$target, $w->topic]);
    $w->db->execute('UPDATE cms_community_posts SET forum_id = ? WHERE topic_id = ?', [$target, $w->topic]);
    $w->dispatcher->onTopicMoved($w->topic, $w->forum, $target, $mod, 0);
    $w->run();
    $note = $w->notes($w->author)[0] ?? [];
    T::same(['community.moderation', 'Dein Thema „Erstes Thema“ wurde nach „Ziel“ verschoben', '/forum/thema/' . $w->topic . '-' . $w->db->row('community_topics', ['id' => $w->topic])['slug']], [$note['type'] ?? null, $note['title'] ?? null, $note['url'] ?? null], 'Starter: Titel, Zielforum, Link aufs Thema');
    T::same(0, count($w->mailsTo($w->author)), 'keine Mail');
    $w->dispatcher->onTopicMoved($w->topic, $w->forum, $target, $w->author, 0);
    $w->run();
    T::same(1, count($w->notes($w->author)), 'Akteur = Starter: nichts');
    $internal = $w->db->forum(['title' => 'Intern', 'status' => 'hidden']);
    $w->db->execute('UPDATE cms_community_topics SET forum_id = ? WHERE id = ?', [$internal, $w->topic]);
    $w->db->execute('UPDATE cms_community_posts SET forum_id = ? WHERE topic_id = ?', [$internal, $w->topic]);
    $w->dispatcher->onTopicMoved($w->topic, $target, $internal, $mod, 0);
    $w->run();
    $note = $w->notes($w->author)[1] ?? [];
    T::same(['Die Moderation hat über einen deiner Beiträge entschieden.', '/member/plugin/community'], [$note['title'] ?? null, $note['url'] ?? null], 'internes Zielforum: generic ohne Titel und Link');
    T::check(!str_contains((string) ($note['title'] ?? '') . ($note['message'] ?? ''), 'Intern'), 'kein Name des internen Forums');
});

T::run('Rückmeldung an Melder: notify_reporter 0/1, Melder = Moderator, gelöschtes Konto, offen; Bündel {count}, kein Titel, keine Mail', static function (): void {
    $w = new CnWorld();
    $mod = $w->member('moderator');
    $reporter = $w->member();
    $r1 = $w->db->report(['target' => $w->first, 'reporter' => $reporter, 'status' => 'valid', 'notify' => 1, 'forum' => $w->forum]);
    $r2 = $w->db->report(['target' => $w->first, 'reporter' => $reporter, 'status' => 'invalid', 'notify' => 1, 'forum' => $w->forum]);
    $quiet = $w->db->report(['target' => $w->first, 'reporter' => $reporter, 'status' => 'valid', 'notify' => 0]);
    $self = $w->db->report(['target' => $w->first, 'reporter' => $mod, 'status' => 'valid', 'notify' => 1]);
    $deleted = $w->db->report(['target' => $w->first, 'reporter' => null, 'status' => 'valid', 'notify' => 1]);
    $open = $w->db->report(['target' => $w->first, 'reporter' => $reporter, 'status' => 'open', 'notify' => 1]);
    foreach ([$r1, $r2, $quiet, $self, $deleted, $open] as $id) {
        $w->dispatcher->onReportResolved($id, 'valid', $mod);
    }
    $r = $w->run();
    $notes = $w->notes($reporter);
    T::same(1, count($notes), 'zwei gültige Rückmeldungen ⇒ eine gebündelte Zeile');
    T::same(['community.moderation', 'community:report', 2, 'Danke für deine 2 Meldungen', 'Die Moderation hat sich darum gekümmert.', null], [$notes[0]['type'], $notes[0]['group_key'], (int) $notes[0]['event_count'], $notes[0]['title'], $notes[0]['message'], $notes[0]['url']], 'neutral, gebündelt, ohne Link');
    T::check(!str_contains($notes[0]['title'] . $notes[0]['message'], 'Erstes Thema'), 'kein Titel des Ziels');
    T::same([0, 0], [count($w->notes($mod)), count($w->mails)], 'Melder = Moderator: nichts; keine Mail');
    T::same([6, 0], [$r['jobs'], $w->queueCount()], 'alle Aufträge erledigt');
});

T::run('Sperrhinweis: In-App und Mail (Umfang, Ende in Ortszeit bzw. unbefristet, Grund, Kontakt); unbestätigt ⇒ nur In-App; Mail trotz notify_mail aus; ohne Zeile nichts', static function (): void {
    $w = new CnWorld(['notify_mail' => false]);
    $m = $w->member('member', true, 'active', ['display_name' => 'Mia']);
    $w->subs->savePreferences($m, ['notify_mail' => false]);
    $w->db->execute("INSERT INTO cms_user_meta (user_id, meta_key, meta_value) VALUES (?, 'notification_preferences', ?)", [$m, json_encode(['email_notifications' => false])]);
    $ban = $w->db->ban(['user' => $m, 'scope' => 'write', 'reason' => 'Spam auf www.evil.com', 'ends_at' => '2026-12-01 09:30:00']);
    $w->dispatcher->onUserBanned($m, $ban, 'write', 1);
    $note = $w->notes($m)[0] ?? [];
    T::same(['community.moderation', 'Deine Teilnahme an der Community ist eingeschränkt', 'Umfang: Nur lesen. Bis: 01.12.2026 10:30. Grund: Spam auf www.evil.com'], [$note['type'] ?? null, $note['title'] ?? null, $note['message'] ?? null], 'In-App mit Umfang, Ende in Ortszeit (Berlin) und Grund');
    $mail = $w->mailsTo($m)[0] ?? ['subject' => '', 'body' => '', 'headers' => []];
    T::same('Deine Community-Sperre', $mail['subject'], 'Mail trotz notify_mail und email_notifications aus (Transaktionsmail)');
    T::check(str_contains($mail['body'], 'Hallo Mia,') && str_contains($mail['body'], 'Bei Fragen erreichst du uns unter hilfe@example.test.') && str_contains($mail['body'], 'www[.]evil[.]com'), 'Gruß, Kontaktzeile, Grund entschärft');
    T::check(!isset($mail['headers']['List-Unsubscribe']) && !str_contains($mail['body'], 'cms-community-unsubscribe'), 'ohne Abmeldelink');
    T::same([$m], array_map(static fn (array $c): int => $c['user'], CnAnnounced::$calls), 'cms_member_notification_created für den neuen Hinweis');

    $perm = $w->db->ban(['user' => $m, 'scope' => 'full', 'reason' => 'Dauer']);
    $w->contact = '';
    $w->dispatcher->onUserBanned($m, $perm, 'full', 1);
    T::same('Umfang: Kein Zugriff. Bis: unbefristet. Grund: Dauer', $w->notes($m)[1]['message'] ?? null, 'unbefristet');
    T::check(!str_contains($w->mailsTo($m)[1]['body'] ?? '', 'Bei Fragen'), 'ohne Kontaktadresse entfällt die Zeile');

    $u = $w->member('member', false);
    $b = $w->db->ban(['user' => $u, 'reason' => 'x']);
    $w->dispatcher->onUserBanned($u, $b, 'write', 1);
    T::same([1, 0], [count($w->notes($u)), count($w->mailsTo($u))], 'unbestätigte Adresse: nur In-App');
    $w->dispatcher->onUserBanned($u, 999999, 'write', 1);
    T::same(1, count($w->notes($u)), 'ohne Sperrzeile: nichts');
    $w->queueOn = false;
    $w->dispatcher->onUserBanned($m, $ban, 'write', 1);
    T::same(2, count($w->mailsTo($m)), 'Queue aus: keine Mail');
    $en = $w->member();
    $w->subs->savePreferences($en, ['locale' => 'en']);
    $w->queueOn = true;
    $w->dispatcher->onUserBanned($en, $w->db->ban(['user' => $en, 'reason' => 'x', 'ends_at' => '2026-12-01 09:30:00']), 'write', 1);
    T::same('Scope: Read only. Until: 2026-12-01 10:30. Reason: x', $w->notes($en)[0]['message'] ?? null, 'Sprache des Mitglieds (en)');
});

T::run('Mail-Kontingent: 51. Mail am selben UTC-Tag nur In-App, Folgetag wieder Mail', static function (): void {
    $w = new CnWorld();
    $sub = $w->member();
    for ($i = 1; $i <= 51; $i++) {
        [$t] = $w->thread($w->forum, $w->author, 'Thema ' . $i);
        $w->subscribe($sub, $t);
        $w->reply($w->member(), 'x', $t);
    }
    T::same('jobs', $w->run()['stop'], 'Lauf endet nach RUN_JOBS Aufträgen');
    $w->run();
    T::same([51, 50], [count($w->notes($sub)), count($w->mailsTo($sub))], '51 Hinweise, 50 Mails');
    T::same(['day' => '2026-11-20', 'count' => 50], $w->settingsOf($sub)['mail_quota'] ?? null, 'mail_quota im settings_json');
    $open = array_values(array_filter($w->db->rows('community_subscriptions', ['user_id' => $sub]), static fn (array $r): bool => $r['notified_at'] === null));
    T::same(1, count($open), 'das 51. Tor bleibt unbeansprucht');
    $w->at(86400);
    $w->reply($w->member(), 'y', (int) $open[0]['target_id']);
    $w->run();
    T::same(51, count($w->mailsTo($sub)), 'Folgetag: wieder Mail');
    T::same(['day' => '2026-11-21', 'count' => 1], $w->settingsOf($sub)['mail_quota'] ?? null, 'Zähler des neuen Tages');
});

T::run('Stufen: 1.000 Reaktionsaufträge und 1 Antwortauftrag ⇒ die Antwort im ersten Lauf; Moderation vor Antworten', static function (): void {
    $w = new CnWorld(['notify_mail' => false]);
    $author = $w->member();
    $p = $w->post($w->topic, $author);
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        $rows[] = "('reaction', 0, {$p}, " . (1000 + $i) . ", 'like', 0, 0, 0, 0, NULL, NULL, '" . cnUtc() . "', '" . cnUtc() . "')";
    }
    $w->db->execute('INSERT INTO cms_community_notify_queue (kind, topic_id, post_id, actor_id, detail, phase, cursor_user_id, attempts, steps, lease_token, locked_until, created_at, updated_at) VALUES ' . implode(', ', $rows));
    $sub = $w->member();
    $w->subscribe($sub);
    $w->reply($w->member());
    $mod = $w->member('moderator');
    $hidden = $w->post($w->topic, $sub, 'weg', 'hidden');
    $w->dispatcher->onModerated('hidden', $w->topic, $hidden, $mod);
    $mark = $w->mark();
    $r = $w->run();
    T::same('jobs', $r['stop'], 'Lauf endet am Auftragsbudget');
    T::same(['community.moderation', 'community.reply'], array_map(static fn (array $n): string => $n['type'], $w->notes($sub)), 'Moderation, dann Antwort im ersten Lauf');
    $due = array_values(array_filter($w->since($mark), static fn (string $sql): bool => str_starts_with($sql, 'SELECT id FROM cms_community_notify_queue WHERE kind IN')));
    T::check(count($due) >= 3, 'je Stufe eine Abfrage über kind');
});

T::run('react_notify: 11. neue Reaktion je Stunde ⇒ kein Auftrag; Wechsel und Entfernen ⇒ kein Auftrag', static function (): void {
    $w = new CnWorld();
    $w->throttleBudget['react_notify'] = 10;
    $actor = $w->member();
    for ($i = 0; $i < 11; $i++) {
        $w->dispatcher->onReactionChanged(100 + $i, $actor, 'like', '');
    }
    T::same(10, $w->queueCount(), '10 Aufträge, der 11. gedrosselt');
    $w->dispatcher->onReactionChanged(5, $actor, 'love', 'like');
    $w->dispatcher->onReactionChanged(5, $actor, '', 'love');
    T::same(10, $w->queueCount(), 'Wechsel und Entfernen: kein Auftrag');
    T::same(array_fill(0, 11, 'react_notify'), array_column($w->throttleLog, 'action'), 'Drossel react_notify je neuer Reaktion');
});

T::run('Mailinhalt: Betreff, kein Beitragstext, List-Unsubscribe absolut mit Thema-Token, One-Click, Auto-Submitted; en; Umbrüche entfernt; defang nur in Mails', static function (): void {
    $w = new CnWorld();
    $w->db->execute('UPDATE cms_community_topics SET title = ? WHERE id = ?', ["Lies https://evil.com/x und www.evil.com\nVersion 4.2.0 z.B. e.g. <b>fett</b> \"zitiert\"", $w->topic]);
    $sub = $w->member('member', true, 'active', ['display_name' => "Eve\r\nBcc: x@evil.example"]);
    $w->subscribe($sub);
    $en = $w->member();
    $w->subscribe($en);
    $w->subs->savePreferences($en, ['locale' => 'en']);
    $actor = $w->member('member', true, 'active', ['display_name' => 'Bob.example']);
    $post = $w->reply($actor, 'GEHEIMER BEITRAGSTEXT');
    $w->run();
    $mail = $w->mailsTo($sub)[0];
    T::same('Neue Antwort: Lies https[:]//evil[.]com/x und www[.]evil[.]com Version 4.2.0 z.B. e.g. <b>fett</b> "zitiert"', $mail['subject'], 'Betreff mit entschärftem Titel, ohne Umbruch');
    T::check(!str_contains($mail['body'], 'GEHEIMER'), 'kein Beitragstext');
    T::check(str_contains($mail['body'], "Hallo Eve Bcc: x@evil[.]example,\n\n"), 'Anzeigename ohne Umbruch (keine Kopfzeile)');
    T::check(str_contains($mail['body'], 'Bob[.]example hat im Thema'), 'Akteur entschärft');
    T::check(str_contains($mail['body'], 'Zum Beitrag: https://forum.example.test/forum/beitrag/' . $post), 'eigene URL unverändert');
    $token = (string) $w->subs->token($sub, 'topic:' . $w->topic);
    T::same('<https://forum.example.test/cms-community-unsubscribe/' . $token . '>', $mail['headers']['List-Unsubscribe'] ?? null, 'List-Unsubscribe absolut mit Thema-Token');
    T::same(['List-Unsubscribe=One-Click', 'auto-generated'], [$mail['headers']['List-Unsubscribe-Post'] ?? null, $mail['headers']['Auto-Submitted'] ?? null], 'One-Click und Auto-Submitted');
    T::check(str_contains($mail['body'], 'Alle E-Mails der Community abbestellen: https://forum.example.test/cms-community-unsubscribe/' . $w->subs->token($sub, 'all')), 'Abmelden aller Mails');
    T::check(str_contains($mail['body'], 'Benachrichtigungen einstellen: https://forum.example.test/member/plugin/community'), 'Einstellungen');
    T::check(str_contains($mail['body'], 'Weitere Antworten in diesem Thema melden wir dir erst wieder'), 'Tor-Hinweis');
    $note = $w->notes($sub)[0];
    T::same('Neue Antwort in „Lies https://evil.com/x und www.evil.com Version 4.2.0 z.B. e.g. fett "zitiert"“', $note['title'], 'In-App unverändert (HTML als Text entfernt, kein Entschärfen, keine Entitäten)');
    $enMail = $w->mailsTo($en)[0];
    T::check(str_starts_with($enMail['subject'], 'New reply: ') && str_contains($enMail['body'], 'replied in') && str_contains($enMail['body'], '/en/forum/post/' . $post), 'Empfänger en: englische Texte und Links');
    T::same('New reply in “Lies https://evil.com/x und www.evil.com Version 4.2.0 z.B. e.g. fett "zitiert"”', $w->notes($en)[0]['title'], 'In-App auf Englisch');
});

T::run('Ablauf: Uhr je Stapel einmal; Reihenfolge FOR UPDATE → Tor → Hinweis → Mail → Kontingent → Cursor; Hinweis und Mail in der Transaktion; Core-Hook nach dem Commit', static function (): void {
    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub);
    $w->reply($w->member());
    $reads = $w->clockReads;
    $mark = $w->mark();
    $w->run();
    T::same(2, $w->clockReads - $reads, 'Uhr: einmal am Anfang und einmal je Stapel (Phase 0 ohne Empfänger liest sie nicht)');
    $order = [];
    foreach ($w->since($mark) as $i => $sql) {
        $kind = match (true) {
            str_starts_with($sql, 'SELECT settings_json FROM cms_community_user_stats') && str_contains($sql, 'FOR UPDATE') => 'mutex',
            str_starts_with($sql, 'UPDATE cms_community_subscriptions SET notified_at') => 'claim',
            str_starts_with($sql, 'INSERT INTO cms_notifications') => 'notify',
            str_starts_with($sql, 'INSERT INTO cms_mail_queue') => 'mail',
            str_starts_with($sql, 'UPDATE cms_community_user_stats SET settings_json') => 'quota',
            str_starts_with($sql, 'UPDATE cms_community_notify_queue SET phase') => 'cursor',
            default => null,
        };
        if ($kind !== null && $w->db->txAt($mark + $i)) {
            $order[] = $kind;
        }
    }
    T::same(['mutex', 'claim', 'notify', 'mail', 'quota', 'cursor'], $order, 'Sperrfolge in einer Transaktion (§4.5)');
    T::same([true], array_column($w->mails, 'in_transaction'), 'Mail in der Transaktion');
    T::same([false], array_column(CnAnnounced::$calls, 'in_transaction'), 'cms_member_notification_created nach dem Commit');

    $w = new CnWorld();
    $sub = $w->member();
    $w->subscribe($sub);
    $w->reply($w->member());
    $w->db->failOn = ['/^INSERT INTO cms_mail_queue/'];
    cnLogReset();
    $r = $w->run();
    $job = $w->db->row('community_notify_queue', []);
    T::same([0, null, 0, 1], [count($w->notes($sub)), $w->db->row('community_subscriptions', ['user_id' => $sub])['notified_at'], (int) $job['cursor_user_id'], $r['failed']], 'Fehler im INSERT der Mail: Hinweis, Tor und Cursor unverändert');
    T::same([], CnAnnounced::$calls, 'kein Core-Hook für zurückgenommene Zeilen');
    T::same(false, $w->db->getPdo()->inTransaction(), 'keine offene Transaktion');
});

T::run('notifyNow/types(): inaktiv, unbekannter Typ, Präferenz aus ⇒ false; moderation nicht abschaltbar; Mail nur bestätigt; Filter ergänzt, entfernt nicht, mail false, Ausnahme ⇒ Eingang', static function (): void {
    $w = new CnWorld();
    $m = $w->member();
    $texts = ['title' => 'Hinweis', 'message' => 'Text', 'url' => '/x'];
    T::same(false, $w->dispatcher->notifyNow($w->member('member', true, 'inactive'), 'community.solution', null, $texts), 'inaktiv: false');
    T::same(false, $w->dispatcher->notifyNow($m, 'community.unbekannt', null, $texts), 'unbekannter Typ: false');
    $w->subs->savePreferences($m, ['notify_types' => ['community.solution' => false]]);
    T::same(false, $w->dispatcher->notifyNow($m, 'community.solution', null, $texts), 'Präferenz aus: false');
    T::same(true, $w->dispatcher->notifyNow($m, 'community.moderation', null, $texts, ['subject' => 'Betreff', 'lines' => ['Zeile']]), 'moderation nicht abschaltbar');
    T::same(1, count($w->mailsTo($m)), 'Mail an bestätigte Adresse');
    $u = $w->member('member', false);
    $w->dispatcher->notifyNow($u, 'community.moderation', null, $texts, ['subject' => 'Betreff', 'lines' => ['Zeile']]);
    T::same([1, 0], [count($w->notes($u)), count($w->mailsTo($u))], 'unbestätigt: nur In-App');

    $add = static fn (array $types): array => $types + ['community.plugin_x' => ['mail' => true, 'switchable' => false], 'fremd' => ['mail' => true]];
    $remove = static fn (array $types): array => ['community.reply' => ['mail' => false, 'switchable' => false]];
    Hooks::addFilter(Events::NOTIFICATION_TYPES, $remove, 5);
    Hooks::addFilter(Events::NOTIFICATION_TYPES, $add);
    $types = $w->dispatcher->types();
    T::same(['mail' => false, 'switchable' => false], $types['community.plugin_x'] ?? null, 'Filter ergänzt Typ, mail erzwungen false');
    T::same(NotificationDispatcher::TYPES['community.reply'], $types['community.reply'], 'eingebauter Typ bleibt unverändert');
    T::check(!isset($types['fremd']), 'Schlüssel ohne community. verworfen');
    Hooks::removeFilter(Events::NOTIFICATION_TYPES, $add);
    Hooks::removeFilter(Events::NOTIFICATION_TYPES, $remove, 5);
    $throw = static function (array $types): array {
        throw new \RuntimeException('kaputt');
    };
    Hooks::addFilter(Events::NOTIFICATION_TYPES, $throw);
    T::same(NotificationDispatcher::TYPES, $w->dispatcher->types(), 'Ausnahme im Filter ⇒ Eingang');
    Hooks::removeFilter(Events::NOTIFICATION_TYPES, $throw);
    T::same('de', $w->dispatcher->localeFor($m), 'localeFor: Website-Sprache ohne gespeicherte');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Reaktionen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Reaktionen: set neu, gleich, Wechsel, remove, zweimal; Zähler Beitrag/Thema/version/received/given; Hooks', static function (): void {
    $w = new CnWorld();
    $author = $w->member();
    $p = $w->post($w->topic, $author);
    $u = $w->member();
    $counters = static function (CnWorld $w, int $p, int $author, int $u): array {
        return [
            (int) $w->db->row('community_posts', ['id' => $p])['reaction_count'],
            (int) $w->db->row('community_topics', ['id' => $w->topic])['reaction_count'],
            (int) $w->db->row('community_user_stats', ['user_id' => $author])['reactions_received'],
            (int) $w->db->row('community_user_stats', ['user_id' => $u])['reactions_given'],
        ];
    };
    $version = (int) $w->db->row('community_topics', ['id' => $w->topic])['version'];
    T::same(['success' => true, 'changed' => true, 'reaction' => 'like', 'count' => 1], $w->reactions->set($u, $p, 'like'), 'set neu');
    T::same([1, 1, 1, 1], $counters($w, $p, $author, $u), 'Zähler +1');
    T::same($version + 1, (int) $w->db->row('community_topics', ['id' => $w->topic])['version'], 'Thema version + 1 (Gastcache)');
    T::same(['success' => true, 'changed' => false, 'reaction' => 'like', 'count' => 1], $w->reactions->set($u, $p, 'like'), 'gleich: keine Änderung');
    T::same(['success' => true, 'changed' => true, 'reaction' => 'love', 'count' => 1], $w->reactions->set($u, $p, 'love'), 'Wechsel');
    T::same([1, 1, 1, 1], $counters($w, $p, $author, $u), 'Zähler gleich beim Wechsel');
    T::same(['success' => true, 'changed' => true, 'count' => 0], $w->reactions->remove($u, $p), 'remove');
    T::same(['success' => true, 'changed' => false, 'count' => 0], $w->reactions->remove($u, $p), 'zweimal remove');
    T::same([0, 0, 0, 0], $counters($w, $p, $author, $u), 'Zähler zurück');
    T::same([[$p, $u, 'like', ''], [$p, $u, 'love', 'like'], [$p, $u, '', 'love']], array_map(static fn (array $h): array => $h['args'], $w->hooks), 'Hooks nur bei Änderung, mit vorher/nachher');
    T::same([false, false, false], array_column($w->hooks, 'in_transaction'), 'Hooks nach dem Commit');
});

T::run('Reaktionen: eigener, ausgeblendeter, wartender Beitrag (echte CommunityAccess), Drossel, Sperrfolge, failOn, Planwechsel', static function (): void {
    $w = new CnWorld();
    $author = $w->member();
    $p = $w->post($w->topic, $author);
    T::same('no_permission', $w->reactions->set($author, $p, 'like')['error'] ?? null, 'eigener Beitrag: no_permission');
    $hidden = $w->post($w->topic, $author, 'x', 'hidden');
    $mod = $w->member('moderator');
    T::same('no_permission', $w->reactions->set($mod, $hidden, 'like')['error'] ?? null, 'ausgeblendet: auch Moderation no_permission');
    $u = $w->member();
    $w->throttleBudget['react'] = 0;
    T::same(['success' => false, 'error' => 'rate_limited', 'retry_after' => 60], $w->reactions->set($u, $p, 'like'), 'Drossel react');
    unset($w->throttleBudget['react']);
    T::same('reaction_unknown', $w->reactions->set($u, $p, 'thanks')['error'] ?? null, 'Art außerhalb reaction_set');

    $mark = $w->mark();
    $w->reactions->set($u, $p, 'like');
    $statements = $w->since($mark);
    $locks = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE')));
    T::same(['community_topics', 'community_posts', 'community_user_stats'], array_map(static fn (string $sql): string => (string) preg_replace('/^SELECT .*? FROM cms_(\w+) .*$/s', '$1', $sql), $locks), 'Sperrfolge Thema → Beitrag → Statistik');
    $ensure = array_search(true, array_map(static fn (string $sql): bool => str_starts_with($sql, 'INSERT IGNORE INTO cms_community_user_stats'), $statements), true);
    T::check($ensure !== false && !$w->db->txAt($mark + (int) $ensure), 'ensureMany vor BEGIN');
    $lockIndex = array_search($locks[2], $statements, true);
    T::same([min($author, $u), max($author, $u)], $w->db->paramsAt($mark + (int) $lockIndex), 'Statistik aufsteigend');

    $w = new CnWorld();
    $author = $w->member();
    $p = $w->post($w->topic, $author);
    $u = $w->member();
    $w->db->failOn = ['/^UPDATE cms_community_user_stats SET reactions_received/'];
    T::same(['success' => false, 'error' => 'db_error'], $w->reactions->set($u, $p, 'like'), 'failOn Statistik ⇒ db_error');
    T::same([0, 0, []], [$w->db->count('community_reactions'), (int) $w->db->row('community_posts', ['id' => $p])['reaction_count'], $w->hooks], 'nichts geändert, kein Hook');
    $w->db->failOn = [];
    [$t2] = $w->thread($w->forum, $w->author, 'Ziel');
    $w->db->beforeExecute['/^SELECT \* FROM cms_community_topics WHERE id = \? LIMIT 1 FOR UPDATE/'] = static function () use ($w, $p, $t2): void {
        $w->db->execute('UPDATE cms_community_posts SET topic_id = ? WHERE id = ?', [$t2, $p]);
    };
    T::same(true, $w->reactions->set($u, $p, 'like')['success'] ?? false, 'Beitrag zwischen Lesen und Sperre verschoben: zweiter Versuch gelingt');
    T::same(1, (int) $w->db->row('community_topics', ['id' => $t2])['reaction_count'], 'Zähler am neuen Thema');
});

T::run('Wirkung: reaction_set (Standard: like ok, thanks reaction_unknown; [thanks]: umgekehrt; []: jedes set reaction_unknown ohne Anweisung, remove geht)', static function (): void {
    $w = new CnWorld();
    $p = $w->post($w->topic, $w->member());
    $u = $w->member();
    T::same([true, 'reaction_unknown'], [$w->reactions->set($u, $p, 'like')['success'], $w->reactions->set($u, $p, 'thanks')['error'] ?? null], 'Standard');
    $w = new CnWorld(['reaction_set' => ['thanks']]);
    $p = $w->post($w->topic, $w->member());
    $u = $w->member();
    T::same(['reaction_unknown', true], [$w->reactions->set($u, $p, 'like')['error'] ?? null, $w->reactions->set($u, $p, 'thanks')['success']], '[thanks]: umgekehrt');
    T::same(['thanks'], $w->reactions->available(), 'available() = reaction_set');
    $w->settings = Settings::fromArray(['reaction_set' => []]);
    $w->rebuild();
    $mark = $w->mark();
    T::same(['success' => false, 'error' => 'reaction_unknown'], $w->reactions->set($u, $p, 'like'), '[]: reaction_unknown');
    T::same([], $w->since($mark), '[]: keine Anweisung');
    T::same(true, $w->reactions->remove($u, $p)['changed'] ?? false, '[]: remove geht');
});

T::run('summaries(): nur Einträge mit view = full; who(): Cursor, ausgeblendeter Beitrag ⇒ not_found, unsichtbar ⇒ not_found', static function (): void {
    $w = new CnWorld();
    $author = $w->member();
    $p = $w->post($w->topic, $author);
    $hidden = $w->post($w->topic, $author, 'x', 'hidden');
    $us = [];
    for ($i = 0; $i < 5; $i++) {
        $us[] = $u = $w->member();
        $w->db->reaction(['post' => $p, 'user' => $u, 'reaction' => $i % 2 === 0 ? 'like' : 'love']);
        $w->db->reaction(['post' => $hidden, 'user' => $u]);
    }
    $sum = $w->reactions->summaries($us[0], [['id' => $p, 'view' => 'full'], ['id' => $hidden, 'view' => 'placeholder'], ['id' => 77]]);
    T::same([$p => ['counts' => ['like' => 3, 'love' => 2], 'mine' => 'like']], $sum, 'nur der volle Eintrag, Zähler und eigene Reaktion');
    T::same([], $w->reactions->summaries(null, [['id' => $hidden, 'view' => 'placeholder']]), 'Platzhalter: nichts');
    $page = $w->reactions->who($author, $p, null, 2);
    T::same([2, (string) $us[1]], [count($page['items']), $page['next']], 'erste Seite mit Cursor');
    $rest = $w->reactions->who($author, $p, $page['next'], 10);
    T::same([3, null], [count($rest['items']), $rest['next']], 'Folgeseite ohne Lücke');
    T::same(['like' => 3, 'love' => 2], $rest['counts'], 'Zähler');
    T::same(['success' => false, 'error' => 'not_found'], $w->reactions->who($us[0], $hidden), 'ausgeblendet (Platzhalter) ⇒ not_found');
    $internal = $w->db->forum(['title' => 'Intern', 'status' => 'hidden']);
    [$it] = $w->thread($internal, $author, 'I');
    $ip = $w->post($it, $author);
    T::same(['success' => false, 'error' => 'not_found'], $w->reactions->who($us[0], $ip), 'internes Forum ⇒ not_found');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->reactions->who($us[0], $p, 'x'), 'ungültiger Cursor');
    // 404-Regel (Vertrag §1 Nr. 8): fehlend, unsichtbar und Platzhalter ergeben dasselbe Ergebnis.
    T::same([$w->reactions->who($us[0], 999999), $w->reactions->who($us[0], $ip)], [$w->reactions->who($us[0], $hidden), $w->reactions->who($us[0], $hidden)], 'who(): fehlend = unsichtbar = Platzhalter');
    T::same($w->reactions->set($us[0], 999999, 'like'), $w->reactions->set($us[0], $ip, 'like'), 'set(): fehlend = unsichtbar');
    T::same($w->subs->subscribe($us[0], 'forum', 999999), $w->subs->subscribe($us[0], 'forum', $internal), 'subscribe(): fehlend = unsichtbar');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Ränge
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Ränge: säen (leer ⇒ 5 + Marke; zweimal nichts; befüllt ⇒ nur Marke; geleert mit Marke nichts); rankFor; forMembers; assign/save/delete', static function (): void {
    $w = new CnWorld();
    T::same(5, count($w->ranks->all()), 'leer ⇒ 5 Standardränge');
    T::same(1, $w->db->count('settings', ['option_name' => RankService::SEED_MARKER]), 'Marke gesetzt');
    T::same(false, $w->ranks->seedDefaults(), 'zweimal: nichts');
    T::same(5, $w->db->count('community_ranks'), 'keine Doppelung');
    $w->db->execute('DELETE FROM cms_community_ranks');
    $fresh = new RankService($w->db, $w->settings, static fn (): int => cnAt(), static fn (): AccessDecision => AccessDecision::allow());
    T::same([[], 0], [$fresh->all(), $w->db->count('community_ranks')], 'geleert mit Marke: nichts gesät');

    $w = new CnWorld();
    $w->db->rank(['title' => 'Eigen', 'min_posts' => 3]);
    T::same(true, $w->ranks->seedDefaults(), 'befüllt: Marke');
    T::same(1, $w->db->count('community_ranks'), 'befüllt: nur die Marke, keine Standardränge');

    $w = new CnWorld();
    $titles = static fn (array $counts): array => array_map(static fn (int $n): ?string => $w->ranks->rankFor($n, null)['title'] ?? null, $counts);
    T::same(['Neu', 'Neu', 'Mitglied', 'Erfahren', 'Experte', 'Experte'], $titles([0, 9, 10, 499, 500, 10000]), 'rankFor an den Grenzen');
    $manual = $w->db->rank(['title' => 'Team', 'title_en' => 'Staff', 'min_posts' => null, 'color' => '#ff0000']);
    $w->ranks = new RankService($w->db, $w->settings, static fn (): int => cnAt(), fn (?int $u, string $a, array $c): AccessDecision => $w->access->can($u, $a, $c));
    T::same(['id' => $manual, 'title' => 'Staff', 'color' => '#ff0000', 'icon' => null, 'manual' => true], $w->ranks->rankFor(0, $manual, 'en'), 'manuell gewinnt, title_en');
    T::same('Neu', $w->ranks->rankFor(0, 99999)['title'] ?? null, 'unbekannte rank_id: automatisch');
    T::same('New', $w->ranks->rankFor(0, null, 'en')['title'] ?? null, 'englischer Titel');
    $a = $w->member();
    $b = $w->member();
    $w->db->execute('UPDATE cms_community_user_stats SET post_count = 60 WHERE user_id = ?', [$b]);
    $mark = $w->mark();
    $members = $w->ranks->forMembers([$a, $b, $w->db->user()]);
    T::same(1, count(array_filter($w->since($mark), static fn (string $sql): bool => str_contains($sql, 'community_user_stats'))), 'forMembers: eine Abfrage');
    T::same(['Mitglied', 'Aktiv', 'Neu'], array_map(static fn (?array $r): ?string => $r['title'] ?? null, array_values($members)), 'Ränge mehrerer Mitglieder');

    $admin = $w->member('admin');
    $m = $w->member();
    T::same('no_capability', $w->ranks->assign($m, $a, $manual)['error'] ?? null, 'assign ohne manage: Grund von can()');
    T::same(['success' => true, 'changed' => true], $w->ranks->assign($admin, $a, $manual), 'assign');
    T::same(['success' => true, 'changed' => false], $w->ranks->assign($admin, $a, $manual), 'assign zweimal');
    T::same(['success' => false, 'error' => 'not_found'], $w->ranks->assign($admin, $a, 99999), 'assign: Rang fehlt');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->ranks->assign($admin, 999999, $manual), 'assign: Konto fehlt');
    T::same(true, $w->ranks->save($admin, ['title' => 'Profi', 'min_posts' => 1000, 'sort_order' => 60, 'icon' => 'star'])['success'] ?? false, 'save neu');
    foreach ([['title' => ''], ['title' => str_repeat('x', 81)], ['title' => 'x', 'color' => '#FF0000'], ['title' => 'x', 'icon' => '-x'], ['title' => 'x', 'min_posts' => -1], ['title' => 'x', 'sort_order' => 70000], ['title' => 'x', 'farbe' => 1], ['min_posts' => 1]] as $bad) {
        T::same(['success' => false, 'error' => 'invalid_input'], $w->ranks->save($admin, $bad), 'save ungültig: ' . json_encode($bad));
    }
    T::same(['success' => false, 'error' => 'not_found'], $w->ranks->save($admin, ['title' => 'x'], 99999), 'save: Rang fehlt');
    T::same('no_capability', $w->ranks->delete($m, $manual)['error'] ?? null, 'delete ohne manage: Grund von can()');
    T::same(['success' => true, 'changed' => true], $w->ranks->delete($admin, $manual), 'delete');
    T::same(['success' => false, 'error' => 'not_found'], $w->ranks->delete($admin, $manual), 'delete zweimal: not_found');
    T::same('Mitglied', $w->ranks->forMembers([$a])[$a]['title'] ?? null, 'verwaiste rank_id: automatisch (10 Beiträge)');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Core
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Core: notifyGrouped (INSERT, Erhöhung, CAS, {count}, Typen), ohne Hook; markGroupRead, countUnreadByType, getRecent', static function (): void {
    $w = new CnWorld();
    $m = $w->member();
    $core = $w->core;
    $texts = ['title' => 'Eins', 'title_many' => '{count} Stück', 'message' => 'a', 'message_many' => 'b {count}', 'url' => '/a', 'url_many' => '/b'];
    CnAnnounced::$calls = [];
    $first = $core->notifyGrouped($m, 'community.x', 'community:topic:1', $texts);
    T::same(true, $first['inserted'], 'INSERT');
    T::same([], CnAnnounced::$calls, 'notifyGrouped löst keinen Hook aus');
    $second = $core->notifyGrouped($m, 'community.x', 'community:topic:1', $texts);
    T::same(['id' => $first['id'], 'inserted' => false], $second, 'Erhöhung derselben Zeile');
    $row = $w->db->row('notifications', ['id' => $first['id']]);
    T::same([2, '2 Stück', 'b 2', '/b'], [(int) $row['event_count'], $row['title'], $row['message'], $row['url']], '{count} und Mehrzahl');
    // CAS: der gelesene Zähler ändert sich zwischen SELECT und UPDATE (ein anderer Prozess) ⇒ zweiter Versuch.
    $w->db->beforeExecute['/^UPDATE cms_notifications SET event_count/'] = static function () use ($w, $first): void {
        $w->db->execute('UPDATE cms_notifications SET event_count = event_count + 1 WHERE id = ?', [$first['id']]);
    };
    $third = $core->notifyGrouped($m, 'community.x', 'community:topic:1', $texts);
    T::same([false, 4, '4 Stück'], [$third['inserted'], (int) $w->db->row('notifications', ['id' => $first['id']])['event_count'], $w->db->row('notifications', ['id' => $first['id']])['title']], 'CAS bei geändertem Zähler: zweiter Versuch, kein Verlust, Text zum neuen Zähler');
    T::same(['id' => 0, 'inserted' => false], $core->notifyGrouped($m, 'community.', null, $texts), 'community. ohne Rest abgelehnt');
    T::same(true, $core->notifyGrouped($m, 'community.y', null, $texts)['inserted'], 'ohne Gruppe: neue Zeile');
    T::same(['community.x' => 1, 'community.y' => 1], $core->countUnreadByType($m), 'countUnreadByType');
    T::same(1, $core->markGroupRead($m, 'community:topic:1'), 'markGroupRead');
    T::same(true, $core->notifyGrouped($m, 'community.x', 'community:topic:1', $texts)['inserted'], 'nach dem Lesen: neue Zeile');
    $recent = $core->getRecent($m, 10);
    T::same(['community.x', 'community:topic:1', 1], [$recent[0]->type, $recent[0]->group_key, $recent[0]->event_count], 'getRecent liefert community.* unverändert mit group_key und event_count');
    $w->db->failOn = ['/^INSERT INTO cms_notifications/'];
    $thrown = false;
    try {
        $core->notifyGrouped($m, 'community.z', 'community:z', $texts);
    } catch (\PDOException) {
        $thrown = true;
    }
    T::same(true, $thrown, 'Datenbankfehler werden nicht verschluckt');
    $w->db->failOn = [];
    $core->announceCreated(5, $m, 'community.x');
    T::same([['id' => 5, 'user' => $m, 'type' => 'community.x', 'in_transaction' => false]], CnAnnounced::$calls, 'announceCreated feuert den Core-Hook');
});

T::run('Core: enqueuePlainStrict (gleiche Prüfungen, PDOException durchgereicht, 0 nur fachlich), BULK_SOURCES', static function (): void {
    $w = new CnWorld();
    $store = OptionStore::getInstance();
    \Closure::bind(function (): void {
        $this->values = ['mail.queue_cron_token' => 'test-token'];
        $this->autoloaded = true;
        $this->complete = true;
    }, $store, OptionStore::class)();
    $queue = cnInstance(MailQueueService::class, ['db' => $w->db, 'prefix' => 'cms_', 'settings' => cnInstance(SettingsService::class, []), 'logger' => Logger::instance()->withChannel('mail.queue')]);
    $id = $queue->enqueuePlainStrict('a@example.test', "Betreff\r\nBcc: x", 'Text', ['Auto-Submitted' => 'auto-generated', 'X Bad' => 'v'], 'community');
    $row = $w->db->row('mail_queue', ['id' => $id]);
    T::same(['a@example.test', 'Betreff Bcc: x', 'plain', 'community', 'pending', 5], [$row['recipient'], $row['subject'], $row['content_type'], $row['source'], $row['status'], (int) $row['max_attempts']], 'Zeile wie enqueuePlain (Betreff bereinigt, Quelle community)');
    T::same(['Auto-Submitted' => 'auto-generated'], json_decode((string) $row['headers'], true), 'Kopfzeilen bereinigt');
    T::same([0, 0], [$queue->enqueuePlainStrict('keine-adresse', 'B', 'T'), $queue->enqueuePlainStrict('a@example.test', " \r\n", 'T')], 'fachliche Ablehnung: 0');
    $w->db->failOn = ['/^INSERT INTO cms_mail_queue/'];
    $thrown = false;
    try {
        $queue->enqueuePlainStrict('a@example.test', 'B', 'T');
    } catch (\PDOException) {
        $thrown = true;
    }
    T::same(true, $thrown, 'PDOException wird nicht gefangen');
    T::check(in_array('community', MailQueueService::BULK_SOURCES, true) && NotificationDispatcher::MAIL_SOURCE === 'community', 'Quelle community gilt als Massenversand');
    OptionStore::getInstance()->flush();
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Wächter
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Wächter: Repositories lehnen fremde Werte ab; Konstanten laut Vertrag', static function (): void {
    $w = new CnWorld();
    $repo = new SubscriptionRepository($w->db);
    $cases = [
        'Zieltyp' => static fn () => $repo->insert(1, 'post', 1, 'instant', cnUtc()),
        'Modus' => static fn () => $repo->insert(1, 'topic', 1, 'monthly', cnUtc()),
        'Art' => static fn () => $repo->enqueue('mention', 1, 1, 1, null, cnUtc()),
        'Grenze' => static fn () => $repo->dueIds(cnUtc(), 0, ['reply']),
        'Token' => static fn () => $repo->lease(1, 'kurz', cnUtc(), cnUtc()),
        'Reaktionsschlüssel' => static fn () => (new ReactionRepository($w->db))->insert(1, 1, 'Like!', cnUtc()),
    ];
    foreach ($cases as $label => $call) {
        $thrown = false;
        try {
            $call();
        } catch (\InvalidArgumentException) {
            $thrown = true;
        }
        T::same(true, $thrown, $label . ': InvalidArgumentException');
    }
    T::same([['moderation', 'report'], ['reply', 'new_topic', 'solution'], ['reaction']], NotificationDispatcher::TIERS, 'Stufen');
    T::same(50, NotificationDispatcher::MAIL_PER_RECIPIENT_DAY, 'Mail-Kontingent');
    T::same(Settings::REACTION_KEYS, array_keys(ReactionService::EMOJI), 'EMOJI deckt den Katalog');
    T::same(RankService::SEED_MARKER, \CMS\Services\Community\Repository\UserStatsRepository::RANK_SEED_MARKER, 'Marke der Ränge');
});

foreach (glob(__DIR__ . '/part-*.php') ?: [] as $part) {
    require $part;
}

T::finish();
