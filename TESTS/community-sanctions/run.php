<?php
/**
 * Community-Sanktionen (PR #2b; Vertrag DESIGN-2b §3.6, §3.10, §4.4, §7.1; Entwurf sanktionen §6.1): `Service\ReportService`
 * (Melden, Zusammenführen, Meldegrenze, Schwelle, Erledigen, Listen) und `Service\BanService` (Sperren, Aufheben, Verlauf, Lesen).
 *
 * Prüfkürzel SECURITY-PRIVACY §1 (Vertrag §7.1b): M8 Melden/Zusammenführen (C bis F), M9 Meldegrenze (G), M10 Schwelle (I),
 * M11 Sperren und Rang (L bis N), M12 Erledigen (J); E3 404-Regel der Sanktionsdienste (Abschnitt „Unberechtigter sieht keinen
 * Unterschied“). Meldungsfreitext und Sperrgrund werden als normalisierter Klartext gespeichert (§7.1a).
 *
 * Abschnitte A bis P laut Entwurf; Vertrag §4.4 geht vor: `create` sperrt bei `post`/`topic` zuerst das Thema, dann die Statistik des
 * Melders (K43), `resolve` sperrt Meldungen über ihre IDs, `resolve`/`bundle`/`listOpen`/`lift` prüfen Rechte vor fachlichen Codes,
 * außerhalb des Moderationsbereichs = fehlend, `lift` mit Rang gegen Betroffenen und Aussteller, `active()` mit Betrachter.
 *
 * DB-frei (SQLite-Double), Zone Europe/Berlin, echte `CommunityAccess`; `autoModerate` ist ein Protokoll (die echte
 * `ModerationService::systemAction()` prüft J2). Die Wettläufe gegen MariaDB stehen in `TESTS/community-db/sections-sanctions.php`.
 *
 *   php TESTS/community-sanctions/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-sanctions-' . getmypid() . '/');

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

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/lib/CommunityTestDatabase.php';

use CMS\Services\Community\Repository\BanRepository;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\ReportRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\BanService;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\ReportService;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\Settings;

T::$verbose = in_array('-v', $argv ?? [], true);

register_shutdown_function(static function (): void {
    foreach (glob(LOG_PATH . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir(LOG_PATH);
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Hilfen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Uhr der Suite: 2026-11-20 12:00:00 UTC (Berlin +01:00). */
function csnAt(int $offset = 0): int
{
    return gmmktime(12, 0, 0, 11, 20, 2026) + $offset;
}

function csnUtc(int $offset = 0): string
{
    return gmdate('Y-m-d H:i:s', csnAt($offset));
}

/** Rechte der Rollen (`admin` hat alles). `reader` darf nur lesen, `manager` hat `community.ban` ohne `community.moderate`. */
const CSN_ROLE_CAPS = [
    'member' => ['community.view', 'community.participate'],
    'reader' => ['community.view'],
    'moderator' => ['community.view', 'community.participate', 'community.moderate'],
    'manager' => ['community.view', 'community.participate', 'community.ban'],
];

/** Warnungen dieses Prozesses (Kanal community). */
function csnLog(): string
{
    return implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), glob(LOG_PATH . 'community-*.log') ?: []));
}

function csnLogReset(): void
{
    foreach (glob(LOG_PATH . 'community-*.log') ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * Testwelt: SQLite-Double, echte `CommunityAccess` (Rollenrechte aus CSN_ROLE_CAPS, Modul-Schalter, E-Mail-Bestätigung), Uhr der
 * Dienste mit Zähler, Hook-Protokoll und Protokoll von `autoModerate` (je mit `inTransaction()`), dazu Grundbestand: Forum F mit
 * Unterforum F1, Forum F2, verborgenes und archiviertes Forum, Thema T (Autor A) mit erstem Beitrag P0 und Antwort P1.
 */
final class CsnWorld
{
    public CommunityTestDatabase $db;
    public \ArrayObject $time;
    public int $clockReads = 0;
    /** @var list<array{hook: string, args: list<mixed>, in_transaction: bool}> */
    public array $hooks = [];
    /** @var list<array{op: string, type: string, id: int, details: array<string, mixed>, in_transaction: bool}> */
    public array $auto = [];
    /** @var \Closure(string, string, int, array<string, mixed>): array<string, mixed> */
    public \Closure $autoResult;
    public Settings $settings;
    public CommunityAccess $access;
    public ReportService $reports;
    public BanService $bans;
    public bool $module = true;
    /** @var array<int, true> */
    public array $unverified = [];

    public int $forum;
    public int $child;
    public int $forum2;
    public int $hidden;
    public int $archived;
    public int $author;
    public int $topic;
    public int $first;
    public int $reply;

    /** @param array<string, mixed> $settings Rohwerte für Settings::fromArray() */
    public function __construct(array $settings = [])
    {
        $this->db = CommunityTestDatabase::create();
        $this->time = new \ArrayObject(['now' => csnAt()]);
        $this->settings = Settings::fromArray($settings);
        $this->autoResult = static fn (): array => ['success' => true, 'changed' => true];
        $this->rebuild();

        $this->forum = $this->db->forum(['title' => 'F']);
        $this->child = $this->db->forum(['title' => 'F1', 'parent' => $this->forum]);
        $this->forum2 = $this->db->forum(['title' => 'F2']);
        $this->hidden = $this->db->forum(['title' => 'Verborgen', 'status' => 'hidden']);
        $this->archived = $this->db->forum(['title' => 'Archiv', 'status' => 'archived']);
        $this->author = $this->member();
        [$this->topic, $this->first, $this->reply] = $this->thread($this->forum, $this->author);
    }

    /** Baut Zugriff und Dienste neu (leere Zwischenspeicher). */
    public function rebuild(): void
    {
        $accessClock = fn (): int => (int) $this->time['now'];
        $this->access = new CommunityAccess(
            $this->db,
            $this->settings,
            static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, CSN_ROLE_CAPS[$role] ?? [], true),
            fn (): bool => $this->module,
            $accessClock,
            fn (int $userId): bool => !isset($this->unverified[$userId])
        );
        $clock = function (): int {
            $this->clockReads++;

            return (int) $this->time['now'];
        };
        $can = fn (?int $userId, string $action, array $context): AccessDecision => $this->access->can($userId, $action, $context);
        $dispatch = function (string $hook, mixed ...$args): void {
            $this->hooks[] = ['hook' => $hook, 'args' => $args, 'in_transaction' => $this->db->getPdo()->inTransaction()];
        };
        $auto = function (string $op, string $type, int $id, array $details): array {
            $this->auto[] = ['op' => $op, 'type' => $type, 'id' => $id, 'details' => $details, 'in_transaction' => $this->db->getPdo()->inTransaction()];

            return ($this->autoResult)($op, $type, $id, $details);
        };
        $this->reports = new ReportService($this->db, $this->settings, $clock, $can, $dispatch, $this->access, $auto);
        $this->bans = new BanService($this->db, $this->settings, $clock, $can, $dispatch, $this->access);
    }

    /** Konto mit Rolle und fest eingestellter Vertrauensstufe (`level` null = keine Statistikzeile). */
    public function member(string $role = 'member', ?int $level = 0, string $status = 'active'): int
    {
        $id = $this->db->user(['role' => $role, 'status' => $status]);
        if ($level !== null) {
            $this->db->stats($id, ['trust_level' => $level, 'trust_level_locked' => true, 'post_count' => 10]);
        }

        return $id;
    }

    /**
     * Thema mit erstem Beitrag und einer Antwort, beide vom selben Autor.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function thread(int $forumId, ?int $authorId, string $status = 'published', string $postStatus = 'published'): array
    {
        $topic = $this->db->topic(['forum' => $forumId, 'user' => $authorId, 'status' => $status]);
        $first = $this->db->post(['topic' => $topic, 'user' => $authorId, 'first' => true]);
        $reply = $this->db->post(['topic' => $topic, 'user' => $authorId, 'status' => $postStatus]);
        $this->db->execute('UPDATE cms_community_topics SET first_post_id = ? WHERE id = ?', [$first, $topic]);

        return [$topic, $first, $reply];
    }

    /** Forum-Moderator (Mitglied) eines Forums, Standard mit Unterforen. */
    public function forumModerator(int $forumId, bool $children = true): int
    {
        $id = $this->member();
        (new ForumRepository($this->db))->saveModerators($forumId, [['subject_type' => 'user', 'subject_id' => $id, 'include_children' => $children ? 1 : 0]]);
        $this->access->flush();

        return $id;
    }

    /** Stellt die Uhr auf `csnAt($seconds)`. */
    public function at(int $seconds): void
    {
        $this->time['now'] = csnAt($seconds);
    }

    /** @return array<string, mixed>|null */
    public function report(int $id): ?array
    {
        return $this->db->row('community_reports', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function ban(int $id): ?array
    {
        return $this->db->row('community_bans', ['id' => $id]);
    }

    /** @return list<string> */
    public function hookNames(): array
    {
        return array_map(static fn (array $h): string => $h['hook'], $this->hooks);
    }

    /** Index des nächsten Eintrags im SQL-Protokoll. */
    public function mark(): int
    {
        return count($this->db->log);
    }

    /** SQL-Texte seit `$mark`. @return list<string> */
    public function since(int $mark): array
    {
        return array_slice($this->db->log, $mark);
    }

    /** Statements seit `$mark`, die eine Tabelle nennen. @return list<string> */
    public function touching(int $mark, string $table): array
    {
        return array_values(array_filter($this->since($mark), static fn (string $sql): bool => str_contains($sql, 'cms_' . $table . ' ') || str_ends_with($sql, 'cms_' . $table)));
    }

    /** Inhalt der Sanktionstabellen und der Inhalte als Hash (für „nichts geschrieben“). */
    public function snapshot(): string
    {
        $parts = [];
        foreach (['community_reports', 'community_bans', 'community_moderation_log', 'community_posts', 'community_topics'] as $table) {
            $parts[$table] = $this->db->rows($table, [], null, 100000);
        }

        return md5((string) json_encode($parts));
    }

    /**
     * Anweisungen ab `$mark` mit Index, Transaktionsflag und Art (`lock`, `write`, `read`).
     *
     * @return list<array{sql: string, tx: bool, kind: string, params: list<mixed>}>
     */
    public function statements(int $mark): array
    {
        $out = [];
        foreach (array_slice($this->db->log, $mark, null, true) as $i => $sql) {
            $kind = str_contains($sql, ' FOR UPDATE') ? 'lock' : (preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql) === 1 ? 'write' : 'read');
            $out[] = ['sql' => $sql, 'tx' => $this->db->txAt($i), 'kind' => $kind, 'params' => $this->db->paramsAt($i)];
        }

        return $out;
    }
}

/** Erste Anweisungen innerhalb der Transaktion (ab `$mark`), als `Art Tabelle`. @return list<string> */
function csnTxHead(CsnWorld $w, int $mark, int $count): array
{
    $head = [];
    foreach ($w->statements($mark) as $st) {
        if (!$st['tx']) {
            continue;
        }
        preg_match('/(?:FROM|INTO|UPDATE) cms_(\w+)/', $st['sql'], $m);
        $head[] = $st['kind'] . ' ' . ($m[1] ?? '?');
        if (count($head) === $count) {
            break;
        }
    }

    return $head;
}

/** Ergebnis ohne die Schlüssel, die je Aufruf verschieden sind. */
function csnShape(array $result): array
{
    $keys = array_keys($result);
    sort($keys);

    return $keys;
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// A Bau
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('A Bau: Klassen, Konstanten, Signaturen, kein Datenbankzugriff beim Konstruieren, eine Uhr je öffentlicher Methode', static function (): void {
    foreach ([ReportService::class, BanService::class] as $class) {
        $ref = new \ReflectionClass($class);
        T::check($ref->isFinal(), substr($class, strrpos($class, '\\') + 1) . ' ist final');
    }
    T::check(!is_file(ABSPATH . 'core/Services/Community/Service/WarningService.php'), 'WarningService.php entfällt (Vertrag §2.1: Vertrag in HANDOFF §2b)');
    T::same(ReportRepository::TARGET_TYPES, ReportService::TARGET_TYPES, 'ReportService::TARGET_TYPES = ReportRepository::TARGET_TYPES');
    T::same(['post', 'topic', 'user'], ReportService::TARGET_TYPES, 'Zielarten post, topic, user');
    T::same(['spam', 'abuse', 'offtopic', 'illegal', 'other'], ReportService::REASONS, 'Gründe in Anzeigereihenfolge');
    T::same(ReportRepository::REASONS, ReportService::REASONS, 'REASONS = ReportRepository::REASONS');
    T::same(['open', 'valid', 'invalid', 'resolved'], ReportService::STATUSES, 'Status');
    T::same(['valid', 'invalid', 'resolved'], ReportService::RESOLUTIONS, 'Abschlussstatus');
    T::same(
        [10, 3600, 1000, 2000, 2, 50, 20, 100],
        [ReportService::MAX_PER_HOUR, ReportService::LIMIT_WINDOW, ReportService::MESSAGE_MAX, ReportService::NOTE_MAX, ReportService::TRUST_MIN, ReportService::REPORTER_SCAN, ReportService::LIST_LIMIT, ReportService::COUNT_CAP],
        'ReportService: MAX_PER_HOUR, LIMIT_WINDOW, MESSAGE_MAX, NOTE_MAX, TRUST_MIN, REPORTER_SCAN, LIST_LIMIT, COUNT_CAP'
    );
    T::same(BanRepository::SCOPES, BanService::SCOPES, 'BanService::SCOPES = BanRepository::SCOPES');
    T::same(['write', 'full'], BanService::SCOPES, 'Umfänge write, full');
    T::same(
        [3600, 315360000, 2000, 300, 50],
        [BanService::MIN_DURATION, BanService::MAX_DURATION, BanService::NOTE_MAX, BanService::COVER_TOLERANCE, BanService::HISTORY_LIMIT],
        'BanService: MIN_DURATION, MAX_DURATION, NOTE_MAX, COVER_TOLERANCE, HISTORY_LIMIT'
    );

    $params = static fn (string $class, string $method): array => array_map(
        static fn (\ReflectionParameter $p): string => ($p->getType() !== null ? (string) $p->getType() . ' ' : '') . '$' . $p->getName() . ($p->isOptional() ? ' = ' . var_export($p->getDefaultValue(), true) : ''),
        (new \ReflectionMethod($class, $method))->getParameters()
    );
    T::same(
        ['?CMS\\Database $db = NULL', '?CMS\\Services\\Community\\Support\\Settings $settings = NULL', '?Closure $clock = NULL', '?Closure $can = NULL', '?Closure $dispatch = NULL', '?CMS\\Services\\Community\\Service\\CommunityAccess $access = NULL', '?Closure $autoModerate = NULL'],
        $params(ReportService::class, '__construct'),
        'ReportService-Konstruktor laut Vertrag §3.6'
    );
    T::same(
        ['?CMS\\Database $db = NULL', '?CMS\\Services\\Community\\Support\\Settings $settings = NULL', '?Closure $clock = NULL', '?Closure $can = NULL', '?Closure $dispatch = NULL', '?CMS\\Services\\Community\\Service\\CommunityAccess $access = NULL'],
        $params(BanService::class, '__construct'),
        'BanService-Konstruktor laut Vertrag §3.6'
    );
    T::same(['int $reporterId', 'string $targetType', 'int $targetId', 'string $reason', "string \$message = ''", 'bool $notify = true'], $params(ReportService::class, 'create'), 'create()');
    T::same(['int $moderatorId', 'int $reportId', 'string $status', "string \$note = ''", '?int $upToReportId = NULL'], $params(ReportService::class, 'resolve'), 'resolve()');
    T::same(['int $moderatorId', '?string $cursor = NULL', '?int $limit = NULL', 'array $filter = array (' . "\n" . ')'], $params(ReportService::class, 'listOpen'), 'listOpen()');
    T::same(['int $moderatorId', 'string $targetType', 'int $targetId'], $params(ReportService::class, 'bundle'), 'bundle()');
    T::same(['int $moderatorId'], $params(ReportService::class, 'countOpen'), 'countOpen()');
    T::same(['int $actorId', 'int $userId', 'string $scope', '?int $durationSeconds', 'string $reason', "string \$internalNote = ''"], $params(BanService::class, 'ban'), 'ban()');
    T::same(['int $actorId', 'int $banId', "string \$reason = ''"], $params(BanService::class, 'lift'), 'lift()');
    T::same(['int $viewerId', 'int $userId'], $params(BanService::class, 'active'), 'active(viewer, user) (Vertrag §3.6, Fassung 3)');
    T::same(['int $viewerId', 'int $userId', '?int $limit = NULL'], $params(BanService::class, 'history'), 'history()');
    T::same(['int $viewerId', '?string $cursor = NULL', '?int $limit = NULL'], $params(BanService::class, 'listActive'), 'listActive()');
    T::same('?array', (string) (new \ReflectionMethod(BanService::class, 'active'))->getReturnType(), 'active() liefert ?array');

    $db = CommunityTestDatabase::create();
    CommunityAccess::setInstance(null);
    $instance = new \ReflectionProperty(CommunityAccess::class, 'instance');
    $db->log = [];
    new ReportService($db, Settings::fromArray([]));
    new BanService($db, Settings::fromArray([]));
    T::same([], $db->log, 'Konstruieren ohne Datenbankzugriff');
    T::same(null, $instance->getValue(), 'Konstruieren löst CommunityAccess nicht auf (erst beim ersten Bedarf)');

    $w = new CsnWorld();
    $mod = $w->member('moderator', 2);
    $reporter = $w->member('member', 2);
    $calls = [
        'create' => static fn () => $w->reports->create($reporter, 'post', $w->reply, 'spam'),
        'listOpen' => static fn () => $w->reports->listOpen($mod),
        'bundle' => static fn () => $w->reports->bundle($mod, 'post', $w->reply),
        'countOpen' => static fn () => $w->reports->countOpen($mod),
        'resolve' => static fn () => $w->reports->resolve($mod, 1, 'valid'),
        'ban' => static fn () => $w->bans->ban($mod, $reporter, 'write', 7200, 'Grund'),
        'active' => static fn () => $w->bans->active($mod, $reporter),
        'history' => static fn () => $w->bans->history($mod, $reporter),
        'listActive' => static fn () => $w->bans->listActive($mod),
        'lift' => static fn () => $w->bans->lift($mod, 1),
    ];
    foreach ($calls as $name => $call) {
        $before = $w->clockReads;
        $call();
        T::same(1, $w->clockReads - $before, $name . '(): genau eine Uhrablesung');
    }
    $before = $w->clockReads;
    $w->reports->create($reporter, 'nope', 1, 'spam');
    $w->bans->ban($mod, $reporter, 'nope', null, 'Grund');
    T::same(0, $w->clockReads - $before, 'Eingabefehler: keine Uhrablesung');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// B bis H Melden
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('B Melden, Eingaben ohne Datenbank: invalid_input, message_required', static function (): void {
    $w = new CsnWorld();
    $r = $w->member();
    $mark = $w->mark();
    $cases = [
        ['nope', $w->reply, 'spam', '', 'invalid_input', 'unbekannte Zielart'],
        ['post', 0, 'spam', '', 'invalid_input', 'ID 0'],
        ['post', -3, 'spam', '', 'invalid_input', 'negative ID'],
        ['post', $w->reply, 'boese', '', 'invalid_input', 'unbekannter Grund'],
        ['post', $w->reply, 'spam', "ung\xC3ltig", 'invalid_input', 'ungültiges UTF-8'],
        ['post', $w->reply, 'spam', str_repeat('ä', 1001), 'invalid_input', '1001 Zeichen'],
        ['post', $w->reply, 'other', '', 'message_required', 'other ohne Text'],
        ['post', $w->reply, 'other', " \u{200B}\u{00AD} \n", 'message_required', 'other nur mit Formatzeichen'],
    ];
    foreach ($cases as [$type, $id, $reason, $message, $error, $label]) {
        T::same(['success' => false, 'error' => $error], $w->reports->create($r, $type, $id, $reason, $message), $label . ' ⇒ ' . $error);
    }
    T::same([], $w->since($mark), 'keine Datenbankanweisung bei Eingabefehlern');
    T::same(true, $w->reports->create($r, 'post', $w->reply, 'other', str_repeat('ä', 1000))['success'] ?? null, 'genau 1000 Zeichen erlaubt');
});

T::run('C Melden, Rechte (echte can()): Gast, Sperren, Capability, eigene Inhalte, Mitglieder, verborgen, archiviert, Moderation', static function (): void {
    $w = new CsnWorld();
    $member = $w->member();
    $writeBanned = $w->member();
    $fullBanned = $w->member();
    $reader = $w->member('reader');
    $inactive = $w->member('member', 0, 'inactive');
    $unverified = $w->member();
    $w->unverified[$unverified] = true;
    $w->db->ban(['user' => $writeBanned, 'scope' => 'write']);
    $w->db->ban(['user' => $fullBanned, 'scope' => 'full']);
    [$hiddenTopic, , $hiddenPost] = $w->thread($w->hidden, $w->author);
    [, , $archivedPost] = $w->thread($w->archived, $w->author);
    $mod = $w->member('moderator');

    $expect = [
        [0, 'post', $w->reply, 'login_required', 'Gast'],
        [$writeBanned, 'post', $w->reply, 'banned_write', 'Schreibsperre'],
        [$fullBanned, 'post', $w->reply, 'banned_full', 'Vollsperre'],
        [$reader, 'post', $w->reply, 'no_capability', 'ohne community.participate'],
        [$unverified, 'post', $w->reply, 'email_unverified', 'E-Mail nicht bestätigt'],
        [$w->author, 'post', $w->reply, 'no_permission', 'eigener Beitrag'],
        [$w->author, 'topic', $w->topic, 'no_permission', 'eigenes Thema'],
        [$member, 'user', $member, 'no_permission', 'sich selbst'],
        [$member, 'user', 999999, 'not_found', 'unbekanntes Mitglied'],
        [$member, 'user', $inactive, 'not_found', 'inaktives Mitglied'],
        [$member, 'post', $hiddenPost, 'not_found', 'Beitrag im verborgenen Forum'],
        [$member, 'topic', $hiddenTopic, 'not_found', 'Thema im verborgenen Forum'],
        [$member, 'post', 999999, 'not_found', 'unbekannter Beitrag'],
        [$member, 'post', $archivedPost, 'forum_archived', 'archiviertes Forum'],
    ];
    foreach ($expect as [$user, $type, $id, $error, $label]) {
        $result = $w->reports->create($user, $type, $id, 'spam');
        T::same($error, $result['error'] ?? 'success', $label . ' ⇒ ' . $error);
    }
    T::same(0, $w->db->count('community_reports'), 'abgelehnte Meldungen schreiben nichts');
    $w->module = false;
    T::same('module_disabled', $w->reports->create($member, 'post', $w->reply, 'spam')['error'] ?? null, 'Modul aus ⇒ module_disabled');
    $w->module = true;
    T::same(true, $w->reports->create($mod, 'post', $w->reply, 'spam')['success'] ?? null, 'Moderation darf melden');
    T::same(true, $w->reports->create($member, 'user', $w->author, 'abuse')['success'] ?? null, 'aktives Mitglied melden');
});

T::run('D Melden, Zustand: nur veröffentlichte Beiträge in veröffentlichten Themen sind meldbar', static function (): void {
    $w = new CsnWorld();
    $mod = $w->member('moderator');
    $member = $w->member();
    $hidden = $w->db->post(['topic' => $w->topic, 'user' => $w->author, 'status' => 'hidden']);
    $deleted = $w->db->post(['topic' => $w->topic, 'user' => $w->author, 'status' => 'deleted']);
    $pending = $w->db->post(['topic' => $w->topic, 'user' => $w->author, 'status' => 'pending']);
    [$hiddenTopic, , $inHiddenTopic] = $w->thread($w->forum, $w->author, 'hidden');
    [$pendingTopic] = $w->thread($w->forum, $w->author, 'pending');
    [$movedTopic] = $w->thread($w->forum, $w->author, 'moved');
    foreach ([
        ['post', $hidden, 'ausgeblendeter Beitrag'], ['post', $deleted, 'gelöschter Beitrag'], ['post', $pending, 'wartender Beitrag'],
        ['post', $inHiddenTopic, 'Beitrag in ausgeblendetem Thema'], ['topic', $hiddenTopic, 'ausgeblendetes Thema'],
        ['topic', $pendingTopic, 'wartendes Thema'], ['topic', $movedTopic, 'Verweis-Thema'],
    ] as [$type, $id, $label]) {
        T::same(['success' => false, 'error' => 'not_reportable'], $w->reports->create($mod, $type, $id, 'spam'), $label . ' ⇒ not_reportable (Moderation sieht ihn)');
    }
    $placeholder = $w->reports->create($member, 'post', $hidden, 'spam');
    T::check(in_array($placeholder['error'] ?? null, ['not_reportable', 'not_found'], true), 'Mitglied: ausgeblendeter Beitrag ⇒ not_reportable oder not_found, nie Erfolg', json_encode($placeholder));
    T::same(0, $w->db->count('community_reports'), 'keine Zeile');
    T::same([], $w->hooks, 'kein Hook');
});

T::run('E Melden, Schreiben: Zeile, UTC, Forum aus dem Thema, Hook nach dem Commit, Ergebnisform', static function (): void {
    $w = new CsnWorld();
    $r = $w->member();
    $result = $w->reports->create($r, 'post', $w->reply, 'abuse', "  Beleidigt\r\nmich  ", false);
    T::same(['auto_hidden', 'merged', 'report_id', 'success'], csnShape($result), 'Ergebnis: success, report_id, merged, auto_hidden');
    T::same([true, false, false], [$result['success'], $result['merged'], $result['auto_hidden']], 'neue Meldung, nicht zusammengeführt, nicht ausgeblendet');
    $row = $w->report((int) $result['report_id']);
    T::same(
        ['post', $w->reply, $w->forum, $r, 'abuse', "Beleidigt\nmich", 'open', 0, null, null, null, csnUtc()],
        [$row['target_type'], (int) $row['target_id'], (int) $row['forum_id'], (int) $row['reporter_id'], $row['reason'], $row['message'], $row['status'], (int) $row['notify_reporter'], $row['resolution_note'], $row['handled_by'], $row['handled_at'], $row['created_at']],
        'Zeile: Ziel, Forum des Themas, Melder, Grund, normalisierter Text, offen, notify 0, created_at UTC der Uhr (nicht Berlin)'
    );
    T::same([['hook' => Events::REPORT_CREATED, 'args' => [(int) $result['report_id'], 'post', $w->reply, $r], 'in_transaction' => false]], $w->hooks, 'REPORT_CREATED nach dem Commit mit genau vier Argumenten');

    $topicReport = $w->reports->create($r, 'topic', $w->topic, 'spam');
    $row = $w->report((int) $topicReport['report_id']);
    T::same(['topic', $w->topic, $w->forum, null, 1], [$row['target_type'], (int) $row['target_id'], (int) $row['forum_id'], $row['message'], (int) $row['notify_reporter']], 'Thema: Forum des Themas, Text leer ⇒ NULL, notify Standard 1');
    $userReport = $w->reports->create($r, 'user', $w->author, 'spam');
    $row = $w->report((int) $userReport['report_id']);
    T::same(['user', $w->author, null], [$row['target_type'], (int) $row['target_id'], $row['forum_id']], 'Mitglied: forum_id NULL');
    T::same([Events::REPORT_CREATED, Events::REPORT_CREATED, Events::REPORT_CREATED], $w->hookNames(), 'je neue Meldung ein Hook');

    // 7.1a: Meldungsfreitext und Sperrgrund als normalisierter Klartext (kein Markup erzeugt, Ausgabe escapt PR #4)
    $x = $w->reports->create($w->member(), 'post', $w->reply, 'other', "<script>alert(1)</script>\u{202E}\u{200B} \"x\"");
    T::same('<script>alert(1)</script> "x"', $w->report((int) $x['report_id'])['message'], 'Meldungstext: HTML bleibt Text, Formatzeichen entfernt');
    $b = $w->bans->ban($w->member('moderator'), $w->member(), 'write', 3600, "<b>Spam</b>\r\n\u{202E}Links");
    T::same('<b>Spam</b> Links', $w->ban((int) $b['ban_id'])['reason'], 'Sperrgrund: eine Zeile, HTML bleibt Text, Formatzeichen entfernt');
});

T::run('F Zusammenführen: gleiche ID, Text angehängt, Grund bleibt, nach Erledigung neue Meldung, andere Melder eigene Zeilen', static function (): void {
    $w = new CsnWorld();
    $r = $w->member();
    $other = $w->member();
    $first = $w->reports->create($r, 'post', $w->reply, 'spam', 'Erster Hinweis', true);
    $w->hooks = [];
    $second = $w->reports->create($r, 'post', $w->reply, 'abuse', 'Zweiter Hinweis', false);
    T::same(['success' => true, 'report_id' => $first['report_id'], 'merged' => true, 'auto_hidden' => false], $second, 'zweite Meldung ⇒ merged, gleiche ID');
    T::same(1, $w->db->count('community_reports'), 'keine neue Zeile');
    T::same([], $w->hooks, 'kein Hook beim Zusammenführen');
    $row = $w->report((int) $first['report_id']);
    T::same(["Erster Hinweis\n\nZweiter Hinweis", 'spam', 1], [$row['message'], $row['reason'], (int) $row['notify_reporter']], 'Text mit Leerzeile angehängt; Grund und notify der ersten Meldung bleiben');
    $w->reports->create($r, 'post', $w->reply, 'spam', 'Zweiter Hinweis');
    T::same("Erster Hinweis\n\nZweiter Hinweis", $w->report((int) $first['report_id'])['message'], 'schon enthaltener Text bleibt unverändert');
    $w->reports->create($r, 'post', $w->reply, 'spam', str_repeat('x', 980));
    T::same("Erster Hinweis\n\nZweiter Hinweis", $w->report((int) $first['report_id'])['message'], 'Überlänge (alt + neu > 1000) bleibt unverändert');
    $w->reports->create($r, 'post', $w->reply, 'spam');
    T::same("Erster Hinweis\n\nZweiter Hinweis", $w->report((int) $first['report_id'])['message'], 'leerer Text ändert nichts');

    $bare = $w->reports->create($r, 'topic', $w->topic, 'spam');
    $w->reports->create($r, 'topic', $w->topic, 'spam', 'Nachtrag');
    T::same('Nachtrag', $w->report((int) $bare['report_id'])['message'], 'ohne alten Text wird der neue Text gesetzt');

    $foreign = $w->reports->create($other, 'post', $w->reply, 'spam');
    T::same([false, 3], [$foreign['merged'], $w->db->count('community_reports')], 'anderer Melder ⇒ eigene Zeile');

    $w->db->execute("UPDATE cms_community_reports SET status = 'valid' WHERE id = ?", [(int) $first['report_id']]);
    $after = $w->reports->create($r, 'post', $w->reply, 'spam');
    T::check(($after['merged'] ?? true) === false && $after['report_id'] !== $first['report_id'], 'nach Erledigung ⇒ neue Meldung');
});

T::run('G Meldegrenze: 10 je 3600 s unter der Statistiksperre, Fenster einschließlich, erledigte zählen, Zusammenführen frei', static function (): void {
    $w = new CsnWorld();
    $r = $w->member();
    $posts = [];
    for ($i = 0; $i < 14; $i++) {
        $posts[] = $w->db->post(['topic' => $w->topic, 'user' => $w->author]);
    }
    $w->db->report(['target' => $posts[0], 'forum' => $w->forum, 'reporter' => $r, 'created_at' => csnUtc(-3000)]);
    for ($i = 1; $i < 10; $i++) {
        $w->db->report(['target' => $posts[$i], 'forum' => $w->forum, 'reporter' => $r, 'status' => $i % 2 === 0 ? 'valid' : 'open', 'created_at' => csnUtc(-100)]);
    }
    $mark = $w->mark();
    $before = $w->snapshot();
    T::same(['success' => false, 'error' => 'rate_limited', 'retry_after' => 600], $w->reports->create($r, 'post', $posts[10], 'spam'), '11. Meldung ⇒ rate_limited, retry_after = älteste + 3600 − jetzt (erledigte zählen mit)');
    T::same($before, $w->snapshot(), 'nichts geschrieben');
    T::same(1, count(array_filter($w->since($mark), static fn (string $sql): bool => str_starts_with($sql, 'SELECT COUNT(*) AS n, MIN(created_at) AS oldest'))), 'Grenze unter der Sperre gezählt');
    T::same(true, $w->reports->create($r, 'post', $posts[1], 'spam', 'Nachtrag')['merged'] ?? null, 'Zusammenführen am Limit erlaubt');
    $other = $w->member();
    T::same(true, $w->reports->create($other, 'post', $posts[10], 'spam')['success'] ?? null, 'fremde Melder zählen nicht');

    // Fensterrand: genau vor 3600 s zählt, vor 3601 s nicht
    $edge = new CsnWorld();
    $er = $edge->member();
    $ids = [];
    for ($i = 0; $i < 12; $i++) {
        $ids[] = $edge->db->post(['topic' => $edge->topic, 'user' => $edge->author]);
    }
    $oldest = $edge->db->report(['target' => $ids[0], 'forum' => $edge->forum, 'reporter' => $er, 'created_at' => csnUtc(-3600)]);
    for ($i = 1; $i < 10; $i++) {
        $edge->db->report(['target' => $ids[$i], 'forum' => $edge->forum, 'reporter' => $er, 'created_at' => csnUtc(-10)]);
    }
    T::same(['success' => false, 'error' => 'rate_limited', 'retry_after' => 1], $edge->reports->create($er, 'post', $ids[10], 'spam'), 'Meldung genau vor 3600 s zählt (retry_after mindestens 1)');
    $edge->db->execute('UPDATE cms_community_reports SET created_at = ? WHERE id = ?', [csnUtc(-3601), $oldest]);
    T::same(true, $edge->reports->create($er, 'post', $ids[10], 'spam')['success'] ?? null, 'Meldung vor 3601 s zählt nicht');
    T::same('rate_limited', $edge->reports->create($er, 'post', $ids[11], 'spam')['error'] ?? null, 'danach wieder 10 im Fenster ⇒ rate_limited');
});

T::run('H Sperrreihenfolge: ensure vor BEGIN, Thema → Statistik des Melders (K43), Mitglied nur Statistik, kein Lesen vor den Sperren', static function (): void {
    $w = new CsnWorld();
    $r = $w->member('member', null);
    $mark = $w->mark();
    T::same(true, $w->reports->create($r, 'post', $w->reply, 'spam')['success'] ?? null, 'Meldung angelegt');
    $statements = $w->statements($mark);
    $ensure = array_values(array_filter($statements, static fn (array $st): bool => str_starts_with($st['sql'], 'INSERT IGNORE INTO cms_community_user_stats')));
    T::same([false], array_map(static fn (array $st): bool => $st['tx'], $ensure), 'ensure() des Melders im Autocommit vor BEGIN');
    T::same(['lock community_topics', 'lock community_user_stats'], csnTxHead($w, $mark, 2), 'post: erste Anweisungen der Transaktion: Thema sperren, dann Statistik des Melders');
    $locks = array_values(array_filter($statements, static fn (array $st): bool => $st['tx'] && $st['kind'] === 'lock'));
    T::same([[$w->topic], [$r]], array_map(static fn (array $st): array => $st['params'], array_slice($locks, 0, 2)), 'gesperrt: Thema des Beitrags, Statistik des Melders');

    $mark = $w->mark();
    $w->reports->create($r, 'topic', $w->topic, 'spam');
    T::same(['lock community_topics', 'lock community_user_stats'], csnTxHead($w, $mark, 2), 'topic: Thema, dann Statistik');
    $mark = $w->mark();
    $w->reports->create($r, 'user', $w->author, 'spam');
    T::same(['lock community_user_stats'], csnTxHead($w, $mark, 1), 'user: nur die Statistik');
    T::same([], array_values(array_filter(csnTxHead($w, $mark, 10), static fn (string $s): bool => $s === 'lock community_topics')), 'user: kein Thema gesperrt');

    // Beitrag zwischen Lesen und Sperre verschoben ⇒ zweiter Versuch mit neuem forum_id
    $m = new CsnWorld();
    $mr = $m->member();
    [$target] = $m->thread($m->forum2, $m->author);
    $m->db->beforeExecute['/^SELECT .* FROM cms_community_topics WHERE id = \? LIMIT 1 FOR UPDATE$/'] = static function () use ($m, $target): void {
        $m->db->execute('UPDATE cms_community_posts SET topic_id = ?, forum_id = ? WHERE id = ?', [$target, $m->forum2, $m->reply]);
    };
    $mark = $m->mark();
    $moved = $m->reports->create($mr, 'post', $m->reply, 'spam');
    T::same(true, $moved['success'] ?? null, 'verschobener Beitrag: Meldung gelingt');
    T::same($m->forum2, (int) $m->report((int) $moved['report_id'])['forum_id'], 'forum_id = neues Forum (zweiter Versuch unter der Sperre des neuen Themas)');
    $topicLocks = array_values(array_filter($m->statements($mark), static fn (array $st): bool => $st['kind'] === 'lock' && str_contains($st['sql'], 'cms_community_topics')));
    T::same([[$m->topic], [$target]], array_map(static fn (array $st): array => $st['params'], $topicLocks), 'zwei Versuche: altes Thema, dann neues Thema gesperrt');

    // Sperre zwischen Vorprüfung und Sperre der Statistik: can(fresh) unter der Sperre sieht sie
    $b = new CsnWorld();
    $br = $b->member();
    $b->db->beforeExecute['/community_user_stats WHERE user_id = \? LIMIT 1 FOR UPDATE$/'] = static function () use ($b, $br): void {
        $b->db->ban(['user' => $br, 'scope' => 'write', 'starts_at' => csnUtc(-1)]);
    };
    T::same(['success' => false, 'error' => 'banned_write'], $b->reports->create($br, 'post', $b->reply, 'spam'), 'Sperre zwischen Vorprüfung und Sperre ⇒ banned_write (can fresh unter der Sperre)');
    T::same([0, []], [$b->db->count('community_reports'), $b->hooks], 'nichts geschrieben, kein Hook');

    // Wechselt der Beitrag bei jedem Versuch, endet die Meldung nach drei Versuchen mit db_error
    $flip = new CsnWorld();
    $fr = $flip->member();
    [$other] = $flip->thread($flip->forum2, $flip->author);
    $pattern = '/^SELECT .* FROM cms_community_topics WHERE id = \? LIMIT 1 FOR UPDATE$/';
    $toggle = static function () use (&$toggle, $flip, $other, $pattern): void {
        $now = (int) $flip->db->execute('SELECT topic_id FROM cms_community_posts WHERE id = ?', [$flip->reply])->fetchColumn();
        $to = $now === $flip->topic ? [$other, $flip->forum2] : [$flip->topic, $flip->forum];
        $flip->db->execute('UPDATE cms_community_posts SET topic_id = ?, forum_id = ? WHERE id = ?', [$to[0], $to[1], $flip->reply]);
        $flip->db->beforeExecute[$pattern] = $toggle;
    };
    $flip->db->beforeExecute[$pattern] = $toggle;
    csnLogReset();
    T::same(['success' => false, 'error' => 'db_error'], $flip->reports->create($fr, 'post', $flip->reply, 'spam'), 'nach drei Versuchen ⇒ db_error');
    $flip->db->beforeExecute = [];
    T::same(0, $flip->db->count('community_reports'), 'keine Zeile');
    T::check(str_contains(csnLog(), 'report.create'), 'Warnung im Kanal community');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// I Schwelle
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('I Schwelle: drei Melder ab Stufe 2 ⇒ systemAction(hide) nach dem Commit; Stufe 1, Moderation, Mitglieder und Fehler', static function (): void {
    $w = new CsnWorld();
    [$r1, $r2, $r3] = [$w->member('member', 2), $w->member('member', 2), $w->member('member', 3)];
    $low = $w->member('member', 1);
    T::same(false, $w->reports->create($r1, 'post', $w->reply, 'spam')['auto_hidden'], '1. Meldung: kein Ausblenden');
    $mark = $w->mark();
    $w->reports->create($low, 'post', $w->reply, 'spam');
    T::same([], array_values(array_filter($w->since($mark), static fn (string $s): bool => str_starts_with($s, 'SELECT DISTINCT reporter_id'))), 'Melder Stufe 1 löst keine Bewertung aus (keine Abfrage openReporters)');
    $w->reports->create($r2, 'post', $w->reply, 'spam');
    T::same([], $w->auto, 'zwei vertrauenswürdige + ein Melder Stufe 1 ⇒ kein Aufruf');
    $third = $w->reports->create($r3, 'post', $w->reply, 'spam');
    T::same([['op' => 'hide', 'type' => 'post', 'id' => $w->reply, 'details' => ['reports' => 3], 'in_transaction' => false]], $w->auto, 'dritter Melder ab Stufe 2 ⇒ genau ein Aufruf hide außerhalb der Transaktion');
    T::same(true, $third['auto_hidden'], 'auto_hidden = success && changed');
    $w->reports->create($r3, 'post', $w->reply, 'spam', 'noch einmal');
    T::same(1, count($w->auto), 'zusammengeführte Meldung löst nicht aus');

    $topic = $w->member('member', 2);
    foreach ([$r1, $r2, $topic] as $u) {
        $w->reports->create($u, 'topic', $w->topic, 'spam');
    }
    T::same(['hide', 'topic', $w->topic], [$w->auto[1]['op'] ?? null, $w->auto[1]['type'] ?? null, $w->auto[1]['id'] ?? null], 'Thema ⇒ hide auf das Thema');
    $w->auto = [];
    foreach ([$r1, $r2, $r3] as $u) {
        $w->reports->create($u, 'user', $w->author, 'abuse');
    }
    T::same([], $w->auto, 'Ziel user ⇒ nie');

    foreach (['moderator', 'admin', 'manager'] as $role) {
        $staff = $w->member($role);
        [, , $post] = $w->thread($w->forum, $staff);
        foreach ([$r1, $r2, $r3] as $u) {
            $w->reports->create($u, 'post', $post, 'spam');
        }
        T::same([], $w->auto, 'Autor mit Rolle ' . $role . ' ⇒ kein Ausblenden (Meldungen bleiben offen)');
        T::same(3, $w->db->count('community_reports', ['target_id' => $post, 'status' => 'open']), $role . ': drei offene Meldungen');
    }

    // Fehler in systemAction: create bleibt erfolgreich
    foreach (['wirft' => static function (): array {
        throw new \RuntimeException('systemAction kaputt');
    }, 'success false' => static fn (): array => ['success' => false, 'changed' => false, 'error' => 'db_error']] as $label => $result) {
        $e = new CsnWorld();
        $e->autoResult = $result;
        [$a, $b, $c] = [$e->member('member', 2), $e->member('member', 2), $e->member('member', 2)];
        $e->reports->create($a, 'post', $e->reply, 'spam');
        $e->reports->create($b, 'post', $e->reply, 'spam');
        csnLogReset();
        $res = $e->reports->create($c, 'post', $e->reply, 'spam');
        T::same([true, false, 1], [$res['success'] ?? null, $res['auto_hidden'] ?? null, count($e->auto)], 'systemAction ' . $label . ' ⇒ create erfolgreich, auto_hidden false');
        T::check(csnLog() !== '', 'systemAction ' . $label . ': Warnung im Kanal community');
    }

    // Gelöschte Melder (reporter_id NULL) und Melder ohne Stufe zählen nicht
    $g = new CsnWorld();
    $g->db->report(['target' => $g->reply, 'forum' => $g->forum, 'reporter' => null]);
    $g->db->report(['target' => $g->reply, 'forum' => $g->forum, 'reporter' => $g->member('member', 0)]);
    $g->reports->create($g->member('member', 2), 'post', $g->reply, 'spam');
    $g->reports->create($g->member('member', 2), 'post', $g->reply, 'spam');
    T::same([], $g->auto, 'gelöschte Melder und Stufe 0 zählen nicht');
});

T::run('Wirkung: report_hide_threshold (0 nie, 2 zweite Meldung ab Stufe 2, 3 dritte)', static function (): void {
    foreach ([0 => null, 1 => 1, 2 => 2, 3 => 3] as $threshold => $hiddenAt) {
        $w = new CsnWorld(['report_hide_threshold' => $threshold]);
        $at = null;
        for ($i = 1; $i <= 4; $i++) {
            $result = $w->reports->create($w->member('member', 2), 'post', $w->reply, 'spam');
            if ($result['auto_hidden'] && $at === null) {
                $at = $i;
            }
        }
        T::same($hiddenAt, $at, 'report_hide_threshold ' . $threshold . ': ausgeblendet bei Meldung ' . json_encode($hiddenAt));
        T::same($hiddenAt === null ? [] : [$hiddenAt], array_slice(array_map(static fn (array $a): int => (int) $a['details']['reports'], $w->auto), 0, 1), 'report_hide_threshold ' . $threshold . ': erster Aufruf mit details.reports = Zahl vertrauenswürdiger Melder');
    }
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// J Erledigen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('J Erledigen: Bündel bis upTo, Protokoll, Hooks je Meldung, Wiederherstellen nur bei invalid ohne Restmeldungen', static function (): void {
    $w = new CsnWorld();
    $mod = $w->member('moderator');
    $ids = [];
    foreach ([1, 2, 3] as $i) {
        $ids[] = (int) $w->reports->create($w->member(), 'post', $w->reply, 'spam')['report_id'];
    }
    $w->hooks = [];
    $w->at(60);
    $mark = $w->mark();
    $result = $w->reports->resolve($mod, $ids[0], 'valid', "  Erledigt \r\n ok ", $ids[1]);
    T::same(['success' => true, 'changed' => true, 'status' => 'valid', 'report_ids' => [$ids[0], $ids[1]], 'count' => 2, 'remaining' => true, 'restored' => false], $result, 'bis upTo erledigt, neuere bleiben offen (remaining)');
    foreach ([$ids[0], $ids[1]] as $id) {
        $row = $w->report($id);
        T::same(['valid', $mod, csnUtc(60), "Erledigt \n ok"], [$row['status'], (int) $row['handled_by'], $row['handled_at'], $row['resolution_note']], 'Meldung ' . $id . ': Status, handled_by, handled_at UTC, Notiz');
    }
    T::same('open', $w->report($ids[2])['status'], 'neuere Meldung bleibt offen');
    $log = $w->db->rows('community_moderation_log');
    T::same(1, count($log), 'ein Protokolleintrag');
    T::same(
        [$mod, 'report.resolve', 'post', $w->reply, $w->forum, null, ['status' => 'valid', 'reports' => 2, 'first_report_id' => $ids[0], 'last_report_id' => $ids[1]], csnUtc(60)],
        [(int) $log[0]['actor_id'], $log[0]['action'], $log[0]['target_type'], (int) $log[0]['target_id'], (int) $log[0]['forum_id'], $log[0]['reason'], json_decode((string) $log[0]['details_json'], true), $log[0]['created_at']],
        'Protokoll report.resolve ohne Notiz und Melder'
    );
    T::same(
        [['hook' => Events::REPORT_RESOLVED, 'args' => [$ids[0], 'valid', $mod], 'in_transaction' => false], ['hook' => Events::REPORT_RESOLVED, 'args' => [$ids[1], 'valid', $mod], 'in_transaction' => false]],
        $w->hooks,
        'REPORT_RESOLVED je Meldung aufsteigend nach dem Commit'
    );
    T::same(['lock community_reports'], csnTxHead($w, $mark, 1), 'erste Anweisung der Transaktion: Meldungen sperren');
    $lock = array_values(array_filter($w->statements($mark), static fn (array $st): bool => $st['kind'] === 'lock'))[0];
    T::check(str_contains($lock['sql'], 'WHERE id IN (') && !str_contains($lock['sql'], 'target_type'), 'Sperre über IDs (PRIMARY), keine Bereichssperre über das Ziel', $lock['sql']);
    T::same([$ids[0], $ids[1]], $lock['params'], 'gesperrt: genau die vorher gelesenen IDs');
    $preRead = array_values(array_filter($w->statements($mark), static fn (array $st): bool => !$st['tx'] && str_starts_with($st['sql'], 'SELECT id FROM cms_community_reports WHERE target_type = ?')));
    T::same(1, count($preRead), 'IDs im Autocommit vor BEGIN gelesen (openIdsForTarget)');
    T::same([], $w->auto, 'valid ⇒ kein Wiederherstellen');

    T::same(['success' => true, 'changed' => false], array_intersect_key($w->reports->resolve($mod, $ids[0], 'valid'), ['success' => 1, 'changed' => 1]), 'Doppelklick (gleicher Status, gleicher Moderator) ⇒ changed false');
    T::same(['success' => false, 'error' => 'report_closed', 'status' => 'valid'], $w->reports->resolve($mod, $ids[0], 'invalid'), 'anderer Status ⇒ report_closed mit status');
    $mod2 = $w->member('moderator');
    T::same(['success' => false, 'error' => 'report_closed', 'status' => 'valid'], $w->reports->resolve($mod2, $ids[0], 'valid'), 'anderer Moderator ⇒ report_closed');
    T::same(['success' => false, 'error' => 'report_not_found'], $w->reports->resolve($mod, 999999, 'valid'), 'unbekannte ID ⇒ report_not_found');

    $w->hooks = [];
    $invalid = $w->reports->resolve($mod, $ids[2], 'invalid');
    T::same([true, [$ids[2]], false, true], [$invalid['changed'], $invalid['report_ids'], $invalid['remaining'], $invalid['restored']], 'invalid ohne Restmeldungen ⇒ restore');
    T::same([['op' => 'restore', 'type' => 'post', 'id' => $w->reply, 'details' => [], 'in_transaction' => false]], $w->auto, 'systemAction(restore) nach dem Commit');

    // invalid mit Restmeldungen und resolved stellen nichts wieder her
    $x = new CsnWorld();
    $xm = $x->member('moderator');
    $a = (int) $x->reports->create($x->member(), 'topic', $x->topic, 'spam')['report_id'];
    $b = (int) $x->reports->create($x->member(), 'topic', $x->topic, 'spam')['report_id'];
    $part = $x->reports->resolve($xm, $a, 'invalid', '', $a);
    T::same([true, false], [$part['remaining'], $part['restored']], 'invalid mit offener Restmeldung ⇒ kein restore');
    $res = $x->reports->resolve($xm, $b, 'resolved');
    T::same([false, false], [$res['remaining'], $res['restored']], 'resolved ⇒ nie restore');
    T::same([], $x->auto, 'kein systemAction-Aufruf');

    // Fehler in systemAction(restore): resolve bleibt erfolgreich
    $y = new CsnWorld();
    $y->autoResult = static function (): array {
        throw new \RuntimeException('restore kaputt');
    };
    $ym = $y->member('moderator');
    $yr = (int) $y->reports->create($y->member(), 'post', $y->reply, 'spam')['report_id'];
    $yres = $y->reports->resolve($ym, $yr, 'invalid');
    T::same([true, false], [$yres['success'], $yres['restored']], 'systemAction(restore) wirft ⇒ resolve erfolgreich, restored false');

    // Eingaben
    foreach ([[$ids[2], 'open', '', null, 'Status open'], [$ids[2], 'bogus', '', null, 'unbekannter Status'], [0, 'valid', '', null, 'ID 0'],
        [$ids[2], 'valid', str_repeat('n', 2001), null, 'Notiz 2001 Zeichen'], [$ids[2], 'valid', "\xC3", null, 'Notiz ungültiges UTF-8'], [$ids[2], 'valid', '', $ids[2] - 1, 'upTo < reportId']] as [$id, $status, $note, $upTo, $label]) {
        T::same(['success' => false, 'error' => 'invalid_input'], $w->reports->resolve($mod, $id, $status, $note, $upTo), $label . ' ⇒ invalid_input');
    }
});

T::run('J Erledigen: Rechte (global, Forum-Moderator mit Unterforen, fremd, Mitglied, gesperrt), aktuelles Forum, eigenes Ziel, 501 Meldungen', static function (): void {
    $w = new CsnWorld();
    $global = $w->member('moderator');
    $fm = $w->forumModerator($w->forum);
    $fmNoChildren = $w->forumModerator($w->forum2, false);
    $member = $w->member();
    $bannedMod = $w->member('moderator');
    $w->db->ban(['user' => $bannedMod, 'scope' => 'write']);
    [, , $childPost] = $w->thread($w->child, $w->author);
    $report = static fn (string $type, int $id): int => (int) $w->reports->create($w->member(), $type, $id, 'spam')['report_id'];

    T::same(true, $w->reports->resolve($fm, $report('post', $w->reply), 'valid')['success'] ?? null, 'Forum-Moderator des Forums');
    T::same(true, $w->reports->resolve($fm, $report('post', $childPost), 'valid')['success'] ?? null, 'Forum-Moderator über include_children (Unterforum)');
    T::same(['success' => false, 'error' => 'report_not_found'], $w->reports->resolve($fmNoChildren, $report('post', $w->reply), 'valid'), 'fremder Forum-Moderator ⇒ report_not_found (außerhalb = fehlend)');
    T::same(['success' => false, 'error' => 'no_permission'], $w->reports->resolve($member, $report('post', $w->reply), 'valid'), 'Mitglied ⇒ no_permission');
    T::same(['success' => false, 'error' => 'no_permission'], $w->reports->resolve($bannedMod, $report('post', $w->reply), 'valid'), 'gesperrter Moderator ⇒ no_permission');
    $userReport = $report('user', $w->author);
    T::same(['success' => false, 'error' => 'report_not_found'], $w->reports->resolve($fm, $userReport, 'valid'), 'Ziel user: Forum-Moderator ⇒ report_not_found');
    T::same(true, $w->reports->resolve($global, $userReport, 'valid')['success'] ?? null, 'Ziel user: globale Moderation');
    T::same(true, $w->reports->resolve($global, $report('post', $w->reply), 'valid')['success'] ?? null, 'globale Moderation');

    // eigenes Ziel
    $ownMod = $w->member('moderator');
    [$ownTopic, , $ownPost] = $w->thread($w->forum, $ownMod);
    foreach ([['post', $ownPost], ['topic', $ownTopic], ['user', $ownMod]] as [$type, $id]) {
        T::same(['success' => false, 'error' => 'no_permission'], $w->reports->resolve($ownMod, $report($type, $id), 'invalid'), 'eigenes Ziel (' . $type . ') ⇒ no_permission');
    }

    // aktuelles Forum zählt (veraltetes reports.forum_id)
    $stale = $report('post', $w->reply);
    $w->db->execute('UPDATE cms_community_reports SET forum_id = ? WHERE id = ?', [$w->forum2, $stale]);
    T::same(['success' => false, 'error' => 'report_not_found'], $w->reports->resolve($fmNoChildren, $stale, 'valid'), 'Moderator des veralteten Forums ⇒ report_not_found');
    T::same(true, $w->reports->resolve($fm, $stale, 'valid')['success'] ?? null, 'Moderator des aktuellen Forums erledigt');
    T::same($w->forum, (int) $w->db->rows('community_moderation_log', ['target_id' => $w->reply], 'id')[0]['forum_id'], 'Protokoll mit aktuellem Forum');

    // Ziel fehlt: Forum der Meldung
    $gone = $w->db->report(['target' => 888888, 'forum' => $w->forum, 'reporter' => $member]);
    T::same(true, $w->reports->resolve($fm, $gone, 'resolved')['success'] ?? null, 'fehlendes Ziel: Forum der Meldung entscheidet');

    // 501 offene Meldungen
    $big = new CsnWorld();
    $bm = $big->member('moderator');
    $values = [];
    for ($i = 0; $i < 501; $i++) {
        $values[] = '(\'post\', ' . $big->reply . ', ' . $big->forum . ', NULL, \'spam\', \'open\', 1, \'' . csnUtc(-10) . '\')';
    }
    $big->db->execute('INSERT INTO cms_community_reports (target_type, target_id, forum_id, reporter_id, reason, status, notify_reporter, created_at) VALUES ' . implode(', ', $values));
    $firstId = (int) $big->db->execute('SELECT MIN(id) FROM cms_community_reports')->fetchColumn();
    $one = $big->reports->resolve($bm, $firstId, 'valid');
    T::same([500, true], [$one['count'], $one['remaining']], '501 offene ⇒ 500 erledigt, remaining');
    T::same(500, count($big->hooks), '500 Hooks');
    $two = $big->reports->resolve($bm, $firstId + 500, 'valid');
    T::same([1, false], [$two['count'], $two['remaining']], 'Rest im zweiten Aufruf');
});

T::run('J Erledigen unter der Sperre: Bereich frisch, keine offene Zeile ⇒ report_closed', static function (): void {
    $w = new CsnWorld();
    $fm = $w->forumModerator($w->forum);
    $id = (int) $w->reports->create($w->member(), 'post', $w->reply, 'spam')['report_id'];
    $w->db->beforeExecute['/FROM cms_community_reports WHERE id IN \(.*FOR UPDATE$/'] = static function () use ($w): void {
        $w->db->execute('DELETE FROM cms_community_forum_moderators');
    };
    T::same(['success' => false, 'error' => 'no_permission'], $w->reports->resolve($fm, $id, 'valid'), 'Forum-Moderator verliert den Bereich zwischen Vorprüfung und Sperre ⇒ no_permission');
    T::same('open', $w->report($id)['status'], 'Meldung bleibt offen');

    $c = new CsnWorld();
    $cm = $c->member('moderator');
    $cid = (int) $c->reports->create($c->member(), 'post', $c->reply, 'spam')['report_id'];
    $c->db->beforeExecute['/FROM cms_community_reports WHERE id IN \(.*FOR UPDATE$/'] = static function () use ($c, $cid): void {
        $c->db->execute("UPDATE cms_community_reports SET status = 'invalid', handled_by = 1 WHERE id = ?", [$cid]);
    };
    $c->hooks = [];
    T::same(['success' => false, 'error' => 'report_closed', 'status' => 'invalid'], $c->reports->resolve($cm, $cid, 'valid'), 'gleichzeitig erledigt ⇒ report_closed mit aktuellem Status');
    T::same([[], 0], [$c->hooks, $c->db->count('community_moderation_log')], 'kein Hook, kein Protokoll');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// K Listen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('K Listen: listOpen (Reihenfolge, Keyset 45/20, Forenbereich, Gründe, Melder), bundle, countOpen', static function (): void {
    $w = new CsnWorld();
    $mod = $w->member('moderator');
    $fm = $w->forumModerator($w->forum);
    $member = $w->member();
    $expected = [];
    for ($i = 0; $i < 45; $i++) {
        $forum = $i % 3 === 0 ? $w->forum2 : $w->forum;
        [, , $post] = $w->thread($forum, $w->author);
        $first = (int) $w->reports->create($w->member(), 'post', $post, $i % 2 === 0 ? 'spam' : 'abuse')['report_id'];
        if ($i % 5 === 0) {
            $w->reports->create($w->member(), 'post', $post, 'offtopic');
        }
        $expected[] = ['post', $post, $forum, $first];
    }
    $w->reports->create($w->member(), 'user', $w->author, 'abuse');

    $seen = [];
    $cursor = null;
    $pages = [];
    do {
        $page = $w->reports->listOpen($mod, $cursor, 20);
        T::same(true, $page['success'] ?? null, 'listOpen erfolgreich');
        $pages[] = count($page['items']);
        foreach ($page['items'] as $item) {
            $seen[] = [$item['target_type'], $item['target_id'], $item['forum_id'], $item['first_id']];
        }
        $cursor = $page['next'];
    } while ($page['has_more']);
    T::same([20, 20, 6], $pages, 'Seiten 20, 20, 6 (45 Bündel und ein Mitgliederbündel)');
    T::same([...$expected, ['user', $w->author, null, $seen[45][3] ?? 0]], $seen, 'Reihenfolge nach erster Meldung, ohne Verlust und Doppel');
    $firstPage = $w->reports->listOpen($mod, null, 20)['items'];
    T::same(['spam' => 1, 'offtopic' => 1], $firstPage[0]['reasons'], 'Gründe je Bündel in Reihenfolge von REASONS');
    T::same([2, 2], [$firstPage[0]['reports'], $firstPage[0]['reporters']], 'Zahl der Meldungen und Melder');
    T::same(['first_at', 'first_id', 'forum_id', 'last_at', 'last_id', 'reasons', 'reporters', 'reports', 'target_id', 'target_type'], csnShape($firstPage[0]), 'Bündel ohne Melder-IDs und ohne Texte');
    T::same($firstPage, $w->reports->listOpen($mod, 'abc', 20)['items'], 'ungültiger Cursor ⇒ erste Seite');
    T::same(20, count($w->reports->listOpen($mod)['items']), 'Standardgröße LIST_LIMIT 20');
    T::same(46, count($w->reports->listOpen($mod, null, 500)['items']), 'Seitengröße höchstens 100');

    $fmItems = $w->reports->listOpen($fm, null, 100)['items'];
    T::same(array_values(array_map(static fn (array $e): int => $e[1], array_filter($expected, static fn (array $e): bool => $e[2] === $w->forum))), array_map(static fn (array $i): int => $i['target_id'], $fmItems), 'Forum-Moderator sieht nur seine Foren, keine Mitgliedermeldungen');
    T::same(['post'], array_values(array_unique(array_map(static fn (array $i): string => $i['target_type'], $w->reports->listOpen($mod, null, 100, ['target_type' => 'post'])['items']))), 'Filter target_type');
    T::same(15, count($w->reports->listOpen($mod, null, 100, ['forum_id' => $w->forum2])['items']), 'Filter forum_id');
    T::same(['success' => false, 'error' => 'not_found'], $w->reports->listOpen($fm, null, 20, ['forum_id' => $w->forum2]), 'Filter forum_id außerhalb ⇒ not_found');
    T::same(['success' => false, 'error' => 'not_found'], $w->reports->listOpen($fm, null, 20, ['forum_id' => 999999]), 'Filter forum_id unbekannt ⇒ not_found (gleich)');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->reports->listOpen($mod, null, 20, ['target_type' => 'forum']), 'Filter target_type unbekannt ⇒ invalid_input');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->reports->listOpen($mod, null, 20, ['bogus' => 1]), 'unbekannter Filter ⇒ invalid_input');
    T::same(['success' => false, 'error' => 'no_permission'], $w->reports->listOpen($member), 'Mitglied ⇒ no_permission');

    // bundle
    $target = $expected[0][1];
    $bundle = $w->reports->bundle($mod, 'post', $target);
    T::same([true, 2, false], [$bundle['success'], count($bundle['items']), $bundle['truncated']], 'bundle: offene Meldungen des Ziels');
    T::same(['created_at', 'forum_id', 'id', 'message', 'notify_reporter', 'reason', 'reporter_id'], csnShape($bundle['items'][0]), 'bundle: Zeilen mit Melder und Text');
    T::same(['success' => false, 'error' => 'report_not_found'], $w->reports->bundle($fm, 'post', $target), 'bundle: fremdes Forum ⇒ report_not_found');
    T::same(['success' => false, 'error' => 'report_not_found'], $w->reports->bundle($mod, 'post', 777777), 'bundle: ohne offene Meldung ⇒ report_not_found');
    T::same(['success' => false, 'error' => 'no_permission'], $w->reports->bundle($member, 'post', $target), 'bundle: Mitglied ⇒ no_permission');
    T::same(['success' => false, 'error' => 'no_permission'], $w->reports->bundle($w->author, 'user', $w->author), 'bundle: ohne Bereich ⇒ no_permission');
    $ownMod = $w->member('moderator');
    $w->reports->create($member, 'user', $ownMod, 'abuse');
    T::same(['success' => false, 'error' => 'no_permission'], $w->reports->bundle($ownMod, 'user', $ownMod), 'bundle: eigenes Ziel ⇒ no_permission (Melder bleiben verborgen)');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->reports->bundle($mod, 'forum', 1), 'bundle: unbekannte Zielart ⇒ invalid_input');
    $values = [];
    for ($i = 0; $i < 101; $i++) {
        $values[] = "('topic', " . $w->topic . ', ' . $w->forum . ", NULL, 'spam', 'open', 1, '" . csnUtc() . "')";
    }
    $w->db->execute('INSERT INTO cms_community_reports (target_type, target_id, forum_id, reporter_id, reason, status, notify_reporter, created_at) VALUES ' . implode(', ', $values));
    $many = $w->reports->bundle($mod, 'topic', $w->topic);
    T::same([100, true], [count($many['items']), $many['truncated']], 'bundle: höchstens 100, truncated');

    T::same(100, $w->reports->countOpen($mod), 'countOpen höchstens COUNT_CAP 100');
    T::same(0, $w->reports->countOpen($member), 'countOpen ohne Rechte 0');
    $c = new CsnWorld();
    $cfm = $c->forumModerator($c->forum);
    $c->reports->create($c->member(), 'post', $c->reply, 'spam');
    $c->reports->create($c->member(), 'post', $c->reply, 'spam');
    [, , $p2] = $c->thread($c->forum2, $c->author);
    $c->reports->create($c->member(), 'post', $p2, 'spam');
    $c->reports->create($c->member(), 'user', $c->author, 'spam');
    T::same([2, 4], [$c->reports->countOpen($cfm), $c->reports->countOpen($c->member('moderator'))], 'countOpen zählt Zeilen im Bereich (Forum-Moderator 2, global 4)');
});

T::run('E3 Unberechtigter sieht keinen Unterschied: fehlend, unsichtbar und nicht zuständig ergeben dasselbe Ergebnis', static function (): void {
    $w = new CsnWorld();
    $member = $w->member();
    $fm = $w->forumModerator($w->forum2, false);
    $open = (int) $w->reports->create($w->member(), 'post', $w->reply, 'spam')['report_id'];
    $closed = $w->db->report(['target' => $w->reply, 'forum' => $w->forum, 'reporter' => $member, 'status' => 'valid', 'handled_by' => 1]);

    $mark = $w->mark();
    $results = [$w->reports->resolve($member, 999999, 'valid'), $w->reports->resolve($member, $open, 'valid'), $w->reports->resolve($member, $closed, 'valid')];
    T::same(array_fill(0, 3, ['success' => false, 'error' => 'no_permission']), $results, 'resolve, Mitglied ohne Bereich: fehlend, offen, geschlossen ⇒ no_permission ohne status');
    T::same([], $w->touching($mark, 'community_reports'), 'resolve ohne Bereich: keine Abfrage auf community_reports');
    T::same($w->reports->resolve($fm, 999999, 'valid'), $w->reports->resolve($fm, $open, 'valid'), 'resolve, Forum-Moderator: fehlende ID = Meldung im fremden Forum (report_not_found)');
    T::same($w->reports->resolve($fm, 999999, 'valid'), $w->reports->resolve($fm, $closed, 'invalid'), 'resolve, Forum-Moderator: geschlossene Meldung im fremden Forum verrät keinen Status');
    T::same($w->reports->bundle($fm, 'post', 999999), $w->reports->bundle($fm, 'post', $w->reply), 'bundle: fehlend = fremdes Forum');
    T::same($w->reports->listOpen($fm, null, 20, ['forum_id' => 999999]), $w->reports->listOpen($fm, null, 20, ['forum_id' => $w->forum]), 'listOpen: Forum fehlend = nicht zuständig');

    $ban = $w->db->ban(['user' => $w->author, 'issued_by' => $w->member('moderator')]);
    T::same($w->bans->lift($member, 999999), $w->bans->lift($member, $ban), 'lift, Mitglied: fehlende = vorhandene Sperre (Rechte-Tor zuerst)');
    T::same('no_capability', $w->bans->lift($member, $ban)['error'] ?? null, 'lift, Mitglied: no_capability');
    T::same($w->bans->ban($member, 999999, 'write', 7200, 'Grund'), $w->bans->ban($member, $w->author, 'write', 7200, 'Grund'), 'ban, Mitglied: unbekanntes = vorhandenes Konto');
    T::same(null, $w->bans->active($member, $w->author), 'active für Dritte ohne Recht ⇒ null');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// L bis O Sperren
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('L Sperren, Eingaben, Rechte und Dauer (Matrix), Rang', static function (): void {
    $w = new CsnWorld();
    $actors = [
        'Mitglied' => $w->member(), 'Moderator' => $w->member('moderator'), 'Manager' => $w->member('manager'), 'Admin' => $w->member('admin'),
        'gesperrter Moderator' => $w->member('moderator'), 'unbestätigter Moderator' => $w->member('moderator'),
    ];
    $w->db->ban(['user' => $actors['gesperrter Moderator'], 'scope' => 'write']);
    $w->unverified[$actors['unbestätigter Moderator']] = true;
    $durations = ['1 h' => 3600, '30 d' => 30 * 86400, '30 d + 1 s' => 30 * 86400 + 1, 'dauerhaft' => null];
    $expect = [
        'Mitglied' => ['no_capability', 'no_capability', 'no_capability', 'no_capability'],
        'Moderator' => ['ok', 'ok', 'ban_duration', 'ban_duration'],
        'Manager' => ['ok', 'ok', 'ok', 'ok'],
        'Admin' => ['ok', 'ok', 'ok', 'ok'],
        'gesperrter Moderator' => ['banned_write', 'banned_write', 'banned_write', 'banned_write'],
        'unbestätigter Moderator' => ['email_unverified', 'email_unverified', 'email_unverified', 'email_unverified'],
    ];
    foreach ($actors as $label => $actor) {
        $i = 0;
        foreach ($durations as $dLabel => $duration) {
            $target = $w->member();
            $result = $w->bans->ban($actor, $target, 'write', $duration, 'Spam');
            $got = !empty($result['success']) ? 'ok' : (string) $result['error'];
            T::same($expect[$label][$i], $got, $label . ' × ' . $dLabel . ' ⇒ ' . $expect[$label][$i]);
            if ($got === 'ban_duration') {
                T::same(30, $result['max_days'] ?? null, $label . ' × ' . $dLabel . ': max_days 30');
            }
            $i++;
        }
    }
    $w->module = false;
    T::same('module_disabled', $w->bans->ban($actors['Admin'], $w->member(), 'write', 3600, 'Spam')['error'] ?? null, 'Modul aus ⇒ module_disabled');
    $w->module = true;

    $mod = $actors['Moderator'];
    $manager = $actors['Manager'];
    $admin = $actors['Admin'];
    $inactiveMod = $w->member('moderator', 0, 'inactive');
    foreach ([
        [$mod, $w->member('moderator'), 'ban_forbidden', 'Moderator → Moderator'],
        [$mod, $w->member('manager'), 'ban_forbidden', 'Moderator → Manager'],
        [$mod, $w->member('admin'), 'ban_forbidden', 'Moderator → Admin'],
        [$manager, $w->member('admin'), 'ban_forbidden', 'Manager → Admin'],
        [$admin, $w->member('admin'), 'ban_forbidden', 'Admin → Admin'],
        [$admin, $w->member('manager'), 'ok', 'Admin → Manager'],
        [$mod, $inactiveMod, 'ban_forbidden', 'Moderator → inaktives Moderatorkonto'],
        [$mod, $mod, 'ban_forbidden', 'sich selbst'],
        [$mod, 999999, 'member_not_found', 'unbekanntes Konto'],
    ] as [$actor, $target, $error, $label]) {
        $result = $w->bans->ban($actor, $target, 'write', 3600, 'Spam');
        T::same($error, !empty($result['success']) ? 'ok' : $result['error'], $label . ' ⇒ ' . $error);
    }
    $target = $w->member();
    $mark = $w->mark();
    foreach ([
        [$mod, $target, 'write', 3600, '', '', 'reason_required', 'Grund leer'],
        [$mod, $target, 'write', 3600, " \u{200B}\u{200E} ", '', 'reason_required', 'Grund nur Formatzeichen'],
        [$mod, $target, 'write', 3600, 'Spam', str_repeat('n', 2001), 'invalid_input', 'Notiz 2001 Zeichen'],
        [$mod, $target, 'read', 3600, 'Spam', '', 'invalid_input', 'unbekannter Umfang'],
        [$mod, $target, 'write', 3599, 'Spam', '', 'invalid_input', 'Dauer unter 1 h'],
        [$mod, $target, 'write', BanService::MAX_DURATION + 1, 'Spam', '', 'invalid_input', 'Dauer über 3650 Tage'],
        [$mod, 0, 'write', 3600, 'Spam', '', 'invalid_input', 'Konto 0'],
        [0, $target, 'write', 3600, 'Spam', '', 'invalid_input', 'Akteur 0'],
    ] as [$actor, $user, $scope, $duration, $reason, $note, $error, $label]) {
        T::same(['success' => false, 'error' => $error], $w->bans->ban($actor, $user, $scope, $duration, $reason, $note), $label . ' ⇒ ' . $error);
    }
    T::same([], $w->since($mark), 'Eingabefehler ohne Datenbankanweisung');
    T::same(true, $w->bans->ban($mod, $target, 'write', 3600, 'Spam', str_repeat('n', 2000))['success'] ?? null, 'Notiz 2000 Zeichen erlaubt');
});

T::run('M Sperren, Schreiben: Zeile, Protokoll ohne Notiz, Hook, forgetUser, Deckung, Doppelklick', static function (): void {
    $w = new CsnWorld();
    $mod = $w->member('moderator');
    $target = $w->member();
    T::same(true, $w->access->can($target, 'reply', ['topic_id' => $w->topic])->allowed, 'vor der Sperre: Antworten erlaubt');
    $mark = $w->mark();
    $result = $w->bans->ban($mod, $target, 'write', 7 * 86400, "  Spam \n  Links ", 'Wiederholt aufgefallen');
    T::same(['success' => true, 'ban_id' => $result['ban_id'] ?? 0, 'user_id' => $target, 'scope' => 'write', 'starts_at' => csnUtc(), 'ends_at' => csnUtc(7 * 86400)], $result, 'Ergebnis mit UTC-Zeiten');
    $row = $w->ban((int) $result['ban_id']);
    T::same(
        [$target, 'write', 'Spam Links', 'Wiederholt aufgefallen', $mod, csnUtc(), csnUtc(7 * 86400), null, csnUtc()],
        [(int) $row['user_id'], $row['scope'], $row['reason'], $row['internal_note'], (int) $row['issued_by'], $row['starts_at'], $row['ends_at'], $row['lifted_at'], $row['created_at']],
        'Zeile: Grund normalisiert, Notiz, Aussteller, Beginn und Ende UTC'
    );
    $log = $w->db->rows('community_moderation_log');
    T::same(
        [[$mod, 'user.ban', 'user', $target, null, 'Spam Links', ['ban_id' => (int) $result['ban_id'], 'scope' => 'write', 'ends_at' => csnUtc(7 * 86400), 'duration' => 7 * 86400]]],
        array_map(static fn (array $r): array => [(int) $r['actor_id'], $r['action'], $r['target_type'], (int) $r['target_id'], $r['forum_id'], $r['reason'], json_decode((string) $r['details_json'], true)], $log),
        'Protokoll user.ban ohne Notiz'
    );
    T::check(!str_contains((string) json_encode($log), 'Wiederholt'), 'interne Notiz steht nicht im Protokoll');
    T::same([['hook' => Events::USER_BANNED, 'args' => [$target, (int) $result['ban_id'], 'write', $mod], 'in_transaction' => false]], $w->hooks, 'USER_BANNED nach dem Commit');
    T::same('banned_write', $w->access->can($target, 'reply', ['topic_id' => $w->topic])->reason, 'nach der Sperre in derselben Sekunde: banned_write (forgetUser)');
    T::same(['lock community_user_stats'], csnTxHead($w, $mark, 1), 'erste Anweisung: Statistik des Gesperrten');
    $lock = array_values(array_filter($w->statements($mark), static fn (array $st): bool => $st['kind'] === 'lock'))[0];
    T::same([$target], $lock['params'], 'gesperrt: Zeile des Betroffenen');
    $ensure = array_values(array_filter($w->statements($mark), static fn (array $st): bool => str_starts_with($st['sql'], 'INSERT IGNORE INTO cms_community_user_stats')));
    T::same([[false, $target]], array_map(static fn (array $st): array => [$st['tx'], $st['params'][0]], $ensure), 'ensure() des Betroffenen vor BEGIN');

    $w->hooks = [];
    $dup = $w->bans->ban($mod, $target, 'write', 7 * 86400, 'Spam');
    T::same([true, true, (int) $result['ban_id']], [$dup['success'], $dup['duplicate'] ?? false, $dup['ban_id']], 'Doppelklick desselben Akteurs ⇒ duplicate');
    T::same([[], 1], [$w->hooks, $w->db->count('community_bans')], 'kein Hook, keine Zeile');
    T::same(['success' => false, 'error' => 'already_banned', 'ban_id' => (int) $result['ban_id']], $w->bans->ban($w->member('moderator'), $target, 'write', 7 * 86400, 'Spam'), 'anderer Moderator, gleiche Sperre ⇒ already_banned');

    // Deckung gegenüber einer bestehenden write-Sperre über 7 Tage (älter als die Toleranz, anderer Aussteller)
    $cover = static function (array $existing, string $scope, ?int $duration) use ($mod): string {
        $c = new CsnWorld();
        $u = $c->member();
        $c->db->ban(['user' => $u, 'issued_by' => $c->member('admin')] + $existing);
        $res = $c->bans->ban($c->member('admin'), $u, $scope, $duration, 'Spam');

        return !empty($res['success']) ? (!empty($res['duplicate']) ? 'duplicate' : 'neu') : (string) $res['error'];
    };
    $week = ['scope' => 'write', 'starts_at' => csnUtc(-86400), 'ends_at' => csnUtc(7 * 86400), 'created_at' => csnUtc(-86400)];
    T::same('already_banned', $cover($week, 'write', 3 * 86400), 'write 7 d ⇒ write 3 d already_banned');
    T::same('already_banned', $cover($week, 'write', 7 * 86400 + 240), 'write 7 d ⇒ write 7 d + 4 min already_banned (Toleranz)');
    T::same('already_banned', $cover($week, 'write', 7 * 86400 - 240), 'write 7 d ⇒ write 7 d − 4 min already_banned');
    T::same('neu', $cover($week, 'write', 8 * 86400), 'write 7 d ⇒ write 8 d angelegt');
    T::same('neu', $cover($week, 'write', null), 'write 7 d ⇒ write dauerhaft angelegt');
    T::same('neu', $cover($week, 'full', 86400), 'write 7 d ⇒ full 1 d angelegt (strengerer Umfang)');
    $forever = ['scope' => 'full', 'starts_at' => csnUtc(-86400), 'ends_at' => null, 'created_at' => csnUtc(-86400)];
    foreach ([['write', 3600], ['write', null], ['full', 86400], ['full', null]] as [$scope, $duration]) {
        T::same('already_banned', $cover($forever, $scope, $duration), 'full dauerhaft ⇒ ' . $scope . ' ' . json_encode($duration) . ' already_banned');
    }
    T::same('neu', $cover(['scope' => 'write', 'ends_at' => null, 'starts_at' => csnUtc(-86400), 'created_at' => csnUtc(-86400)], 'full', 3600), 'write dauerhaft deckt full nicht');
    T::same('neu', $cover($week + ['lifted_at' => csnUtc(-60), 'lifted_by' => 1], 'write', 3600), 'aufgehobene Sperre deckt nicht');
    T::same('neu', $cover(['scope' => 'full', 'starts_at' => csnUtc(-86400 * 10), 'ends_at' => csnUtc(-60), 'created_at' => csnUtc(-86400 * 10)], 'write', 3600), 'abgelaufene Sperre deckt nicht');
    T::same('neu', $cover(['scope' => 'full', 'starts_at' => csnUtc(3600), 'ends_at' => null, 'created_at' => csnUtc(-60)], 'write', 3600), 'geplante Sperre deckt nicht');

    // Rechte und Rang frisch unter der Sperre
    $fr = new CsnWorld();
    $frm = $fr->member('moderator');
    $fru = $fr->member();
    $fr->db->beforeExecute['/community_user_stats WHERE user_id = \? LIMIT 1 FOR UPDATE$/'] = static function () use ($fr, $frm): void {
        $fr->db->ban(['user' => $frm, 'scope' => 'write', 'starts_at' => csnUtc(-1)]);
    };
    T::same(['success' => false, 'error' => 'banned_write'], $fr->bans->ban($frm, $fru, 'write', 3600, 'Spam'), 'Akteur wird zwischen Vorprüfung und Sperre gesperrt ⇒ banned_write (Rechte frisch)');
    $fru2 = $fr->member();
    $frm2 = $fr->member('moderator');
    $fr->db->beforeExecute['/community_user_stats WHERE user_id = \? LIMIT 1 FOR UPDATE$/'] = static function () use ($fr, $fru2): void {
        $fr->db->execute("UPDATE cms_users SET role = 'moderator' WHERE id = ?", [$fru2]);
    };
    T::same(['success' => false, 'error' => 'ban_forbidden'], $fr->bans->ban($frm2, $fru2, 'write', 3600, 'Spam'), 'Betroffener wird zwischen Vorprüfung und Sperre Moderator ⇒ ban_forbidden (Rang frisch)');
    T::same([0, []], [$fr->db->count('community_bans', ['user_id' => $fru2]), $fr->hooks], 'nichts geschrieben, kein Hook');

    // Doppelklick: Endzeit innerhalb der Toleranz, nach mehr als 5 min nicht mehr
    $d = new CsnWorld();
    $dm = $d->member('moderator');
    $du = $d->member();
    $d->bans->ban($dm, $du, 'full', 86400, 'Spam');
    $d->at(120);
    T::same(true, $d->bans->ban($dm, $du, 'full', 86400, 'Spam')['duplicate'] ?? false, 'gleicher Akteur nach 2 min ⇒ duplicate');
    T::same('already_banned', $d->bans->ban($dm, $du, 'write', 3600, 'Spam')['error'] ?? null, 'gleicher Akteur, anderer Umfang (gedeckt) ⇒ already_banned');
    $d->at(400);
    T::same('already_banned', $d->bans->ban($dm, $du, 'full', 86400 - 400, 'Spam')['error'] ?? null, 'gleicher Akteur nach mehr als 5 min ⇒ already_banned');
});

T::run('Wirkung: moderator_ban_max_days (10: 10 d erlaubt, 10 d + 1 s ban_duration mit max_days 10; mit community.ban erlaubt)', static function (): void {
    $w = new CsnWorld(['moderator_ban_max_days' => 10]);
    $mod = $w->member('moderator');
    $manager = $w->member('manager');
    T::same(true, $w->bans->ban($mod, $w->member(), 'write', 10 * 86400, 'Spam')['success'] ?? null, '10 Tage für Moderatoren erlaubt');
    T::same(['success' => false, 'error' => 'ban_duration', 'max_days' => 10], $w->bans->ban($mod, $w->member(), 'write', 10 * 86400 + 1, 'Spam'), '10 Tage + 1 s ⇒ ban_duration mit max_days 10');
    T::same(true, $w->bans->ban($manager, $w->member(), 'write', 10 * 86400 + 1, 'Spam')['success'] ?? null, 'mit community.ban erlaubt');
    $std = new CsnWorld();
    T::same(true, $std->bans->ban($std->member('moderator'), $std->member(), 'write', 30 * 86400, 'Spam')['success'] ?? null, 'Standard 30: 30 Tage erlaubt');
});

T::run('N Aufheben: eigene, fremde, Rang gegen Betroffenen und Aussteller (vorab und frisch), Wiederholung, Ablauf', static function (): void {
    $w = new CsnWorld();
    $mod = $w->member('moderator');
    $mod2 = $w->member('moderator');
    $manager = $w->member('manager');
    $target = $w->member();
    $own = (int) $w->bans->ban($mod, $target, 'write', 86400, 'Spam')['ban_id'];
    T::same('banned_write', $w->access->can($target, 'reply', ['topic_id' => $w->topic])->reason, 'gesperrt');
    T::same(['success' => false, 'error' => 'no_permission'], $w->bans->lift($mod2, $own), 'fremde Sperre ohne community.ban ⇒ no_permission');
    $w->hooks = [];
    $w->at(60);
    $mark = $w->mark();
    T::same(['success' => true, 'ban_id' => $own, 'user_id' => $target, 'changed' => true], $w->bans->lift($mod, $own, 'Versehen'), 'Moderator hebt eigene befristete Sperre auf');
    T::same(['lock community_user_stats'], csnTxHead($w, $mark, 1), 'erste Anweisung: Statistik des Betroffenen');
    T::same([csnUtc(60), $mod], [$w->ban($own)['lifted_at'], (int) $w->ban($own)['lifted_by']], 'lifted_at UTC, lifted_by');
    $log = array_values(array_filter($w->db->rows('community_moderation_log', [], 'id'), static fn (array $r): bool => $r['action'] === 'user.unban'));
    T::same([[$mod, 'user', $target, 'Versehen', ['ban_id' => $own, 'scope' => 'write']]], array_map(static fn (array $r): array => [(int) $r['actor_id'], $r['target_type'], (int) $r['target_id'], $r['reason'], json_decode((string) $r['details_json'], true)], $log), 'Protokoll user.unban');
    T::same([['hook' => Events::USER_UNBANNED, 'args' => [$target, $own, $mod], 'in_transaction' => false]], $w->hooks, 'USER_UNBANNED nach dem Commit');
    T::same(true, $w->access->can($target, 'reply', ['topic_id' => $w->topic])->allowed, 'danach can() wieder erlaubt (forgetUser)');
    $w->hooks = [];
    T::same(['success' => true, 'ban_id' => $own, 'user_id' => $target, 'changed' => false], $w->bans->lift($mod, $own), 'zweites Aufheben ⇒ changed false');
    T::same([[], 1], [$w->hooks, count(array_filter($w->db->rows('community_moderation_log'), static fn (array $r): bool => $r['action'] === 'user.unban'))], 'kein Hook, kein zweites Protokoll');
    T::same(['success' => false, 'error' => 'ban_not_found'], $w->bans->lift($mod, 999999), 'unbekannte Sperre ⇒ ban_not_found');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->bans->lift($mod, 0), 'ID 0 ⇒ invalid_input');

    $foreign = (int) $w->bans->ban($mod2, $target, 'write', 86400, 'Spam')['ban_id'];
    T::same(true, $w->bans->lift($manager, $foreign)['changed'] ?? null, 'Manager hebt fremde Sperre eines Moderators auf');
    $expired = $w->db->ban(['user' => $target, 'issued_by' => $mod, 'starts_at' => csnUtc(-7200), 'ends_at' => csnUtc(-60)]);
    T::same(['success' => true, 'ban_id' => $expired, 'user_id' => $target, 'changed' => false], $w->bans->lift($mod, $expired), 'abgelaufene Sperre ⇒ changed false');
    T::same(null, $w->ban($expired)['lifted_at'], 'abgelaufene Sperre bleibt unverändert');

    // Rang (Fassung 3, S11)
    $admin = $w->member('admin');
    $byAdmin = $w->db->ban(['user' => $w->member(), 'issued_by' => $admin]);
    T::same(['success' => false, 'error' => 'ban_forbidden'], $w->bans->lift($manager, $byAdmin), 'Manager hebt die Sperre eines Admins auf ⇒ ban_forbidden');
    [$managerB, $managerC] = [$w->member('manager'), $w->member('manager')];
    $peer = $w->db->ban(['user' => $managerC, 'issued_by' => $managerB]);
    T::same(['success' => false, 'error' => 'ban_forbidden'], $w->bans->lift($manager, $peer), 'Manager A hebt die Sperre von Manager B gegen Manager C auf ⇒ ban_forbidden');
    $modTarget = $w->db->ban(['user' => $w->member('moderator'), 'issued_by' => $mod]);
    T::same(['success' => false, 'error' => 'ban_forbidden'], $w->bans->lift($mod, $modTarget), 'Moderator hebt eigene Sperre gegen einen Gleichrangigen auf ⇒ ban_forbidden');
    $orphan = $w->db->ban(['user' => $w->member(), 'issued_by' => null]);
    T::same(true, $w->bans->lift($manager, $orphan)['changed'] ?? null, 'Aussteller NULL zählt 0');
    $deletedIssuer = $w->db->ban(['user' => $w->member(), 'issued_by' => 888888]);
    T::same(true, $w->bans->lift($manager, $deletedIssuer)['changed'] ?? null, 'gelöschter Aussteller zählt 0');
    T::same(true, $w->bans->lift($admin, $byAdmin)['changed'] ?? null, 'Admin hebt Sperre eines Admins auf (eigener Rang ≥ Aussteller)');

    // frisch unter der Sperre: Rolle zwischen Vorprüfung und Sperre geändert
    $f = new CsnWorld();
    $fm = $f->member('manager');
    $issuer = $f->member('manager');
    $victim = $f->member();
    $fresh = $f->db->ban(['user' => $victim, 'issued_by' => $issuer]);
    $f->db->beforeExecute['/community_user_stats WHERE user_id = \? LIMIT 1 FOR UPDATE$/'] = static function () use ($f, $issuer): void {
        $f->db->execute("UPDATE cms_users SET role = 'admin' WHERE id = ?", [$issuer]);
    };
    T::same(['success' => false, 'error' => 'ban_forbidden'], $f->bans->lift($fm, $fresh), 'Aussteller wird zwischen Vorprüfung und Sperre Admin ⇒ ban_forbidden (Rang frisch)');
    T::same(null, $f->ban($fresh)['lifted_at'], 'Sperre bleibt');
    $victim2 = $f->member();
    $fresh2 = $f->db->ban(['user' => $victim2, 'issued_by' => $fm]);
    $f->db->beforeExecute['/community_user_stats WHERE user_id = \? LIMIT 1 FOR UPDATE$/'] = static function () use ($f, $victim2): void {
        $f->db->execute("UPDATE cms_users SET role = 'manager' WHERE id = ?", [$victim2]);
    };
    T::same(['success' => false, 'error' => 'ban_forbidden'], $f->bans->lift($fm, $fresh2), 'Betroffener wird gleichrangig zwischen Vorprüfung und Sperre ⇒ ban_forbidden');
    $victim3 = $f->member();
    $fresh3 = $f->db->ban(['user' => $victim3, 'issued_by' => $f->member('moderator')]);
    $f->db->beforeExecute['/community_user_stats WHERE user_id = \? LIMIT 1 FOR UPDATE$/'] = static function () use ($f, $fm): void {
        $f->db->execute("UPDATE cms_users SET role = 'moderator' WHERE id = ?", [$fm]);
    };
    T::same(['success' => false, 'error' => 'no_permission'], $f->bans->lift($fm, $fresh3), 'Akteur verliert community.ban zwischen Vorprüfung und Sperre ⇒ no_permission (fremde Sperre, Rechte frisch)');
});

T::run('O Lesen: history (Notiz nur für Ranghöhere, vier Zustände), listActive (Keyset), active (ohne Notiz, mit Betrachter)', static function (): void {
    $w = new CsnWorld();
    $mod = $w->member('moderator');
    $mod2 = $w->member('moderator');
    $member = $w->member();
    $active = (int) $w->bans->ban($mod, $member, 'write', 86400, 'Aktiv', 'Notiz A')['ban_id'];
    $scheduled = $w->db->ban(['user' => $member, 'issued_by' => $mod, 'starts_at' => csnUtc(3600), 'internal_note' => 'Notiz B']);
    $expired = $w->db->ban(['user' => $member, 'issued_by' => $mod, 'starts_at' => csnUtc(-7200), 'ends_at' => csnUtc(-3600)]);
    $lifted = $w->db->ban(['user' => $member, 'issued_by' => $mod, 'starts_at' => csnUtc(-7200), 'lifted_at' => csnUtc(-60), 'lifted_by' => $mod]);

    $history = $w->bans->history($mod, $member);
    T::same(true, $history['success'] ?? null, 'history für Moderation');
    T::same([[$lifted, 'lifted'], [$expired, 'expired'], [$scheduled, 'scheduled'], [$active, 'active']], array_map(static fn (array $i): array => [$i['id'], $i['state']], $history['items']), 'jüngste zuerst, vier Zustände');
    T::same(['Notiz A', 'Notiz B'], [$history['items'][3]['internal_note'], $history['items'][2]['internal_note']], 'Moderator über Mitglied: Notiz sichtbar');
    T::same(['created_at', 'ends_at', 'id', 'internal_note', 'issued_by', 'lifted_at', 'lifted_by', 'reason', 'scope', 'starts_at', 'state'], csnShape($history['items'][0]), 'Felder des Verlaufs');
    $w->db->ban(['user' => $mod2, 'issued_by' => $w->member('admin'), 'internal_note' => 'Geheim']);
    T::same([null], array_map(static fn (array $i): ?string => $i['internal_note'], $w->bans->history($mod, $mod2)['items']), 'Moderator über Moderator: keine Notiz');
    $w->db->ban(['user' => $mod, 'issued_by' => $w->member('admin'), 'internal_note' => 'Eigen']);
    T::same([null], array_map(static fn (array $i): ?string => $i['internal_note'], $w->bans->history($mod, $mod)['items']), 'eigener Verlauf: nie die Notiz');
    T::same(['success' => false, 'error' => 'no_capability'], $w->bans->history($w->member(), $member), 'Mitglied ohne Recht ⇒ no_capability');
    T::same(['success' => false, 'error' => 'banned_write'], $w->bans->history($member, $member), 'gesperrtes Mitglied, eigener Verlauf ⇒ Grund der Rechteprüfung (banned_write)');
    T::same(2, count($w->bans->history($mod, $member, 2)['items']), 'Grenze limit');

    $lw = new CsnWorld();
    $lm = $lw->member('moderator');
    $ids = [];
    for ($i = 0; $i < 7; $i++) {
        $ids[] = $lw->db->ban(['user' => $lw->member(), 'issued_by' => $lm]);
    }
    $lw->db->ban(['user' => $lw->member(), 'lifted_at' => csnUtc(-1), 'lifted_by' => $lm]);
    $lw->db->ban(['user' => $lw->member(), 'ends_at' => csnUtc()]);
    $lw->db->ban(['user' => $lw->member(), 'starts_at' => csnUtc(10)]);
    $seen = [];
    $cursor = null;
    $pages = [];
    do {
        $page = $lw->bans->listActive($lm, $cursor, 3);
        $pages[] = count($page['items']);
        array_push($seen, ...array_map(static fn (array $i): int => $i['id'], $page['items']));
        $cursor = $page['next'];
    } while ($page['has_more']);
    T::same([3, 3, 1], $pages, 'listActive: Seiten 3, 3, 1');
    T::same(array_reverse($ids), $seen, 'listActive: neueste zuerst, ohne aufgehobene, abgelaufene (ends_at = jetzt) und geplante');
    T::check(!array_key_exists('internal_note', $lw->bans->listActive($lm)['items'][0]), 'listActive ohne Notiz');
    T::same('no_capability', $lw->bans->listActive($lw->member())['error'] ?? null, 'listActive ohne Recht ⇒ no_capability');

    T::same(['id' => $active, 'scope' => 'write', 'reason' => 'Aktiv', 'ends_at' => csnUtc(86400)], $w->bans->active($member, $member), 'active für das Mitglied selbst: Grund und Ende, keine Notiz');
    T::same(['id' => $active, 'scope' => 'write', 'reason' => 'Aktiv', 'ends_at' => csnUtc(86400)], $w->bans->active($w->member('moderator'), $member), 'active für Moderation');
    T::same(null, $w->bans->active($mod2, $member), 'active für gesperrte Moderation ⇒ null (Rechteprüfung lehnt ab)');
    T::same(['id' => $active, 'scope' => 'write', 'reason' => 'Aktiv', 'ends_at' => csnUtc(86400)], $w->bans->active($w->member('manager'), $member), 'active für community.ban');
    T::same(null, $w->bans->active($w->member(), $member), 'active für Dritte ohne Recht ⇒ null');
    T::same(null, $w->bans->active(0, $member), 'active für Gäste ⇒ null');
    T::same(null, $w->bans->active($mod, $mod2 + 1000), 'ohne Sperre ⇒ null');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// P Rücknahme
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('P Rücknahme: Fehler an der letzten Anweisung ⇒ db_error, nichts geschrieben, kein Hook, keine Folgeaktion, Wiederholung gelingt', static function (): void {
    $scenarios = [
        'create' => ['/^INSERT INTO cms_community_reports/', static function (CsnWorld $w): \Closure {
            $a = $w->member('member', 2);
            $b = $w->member('member', 2);
            $w->reports->create($a, 'post', $w->reply, 'spam');
            $w->reports->create($b, 'post', $w->reply, 'spam');
            $c = $w->member('member', 2);

            return static fn (): array => $w->reports->create($c, 'post', $w->reply, 'spam');
        }],
        'resolve' => ['/^INSERT INTO cms_community_moderation_log/', static function (CsnWorld $w): \Closure {
            $mod = $w->member('moderator');
            $id = (int) $w->reports->create($w->member(), 'post', $w->reply, 'spam')['report_id'];

            return static fn (): array => $w->reports->resolve($mod, $id, 'invalid');
        }],
        'ban' => ['/^INSERT INTO cms_community_moderation_log/', static function (CsnWorld $w): \Closure {
            $mod = $w->member('moderator');
            $target = $w->member();

            return static fn (): array => $w->bans->ban($mod, $target, 'full', 3600, 'Spam');
        }],
        'lift' => ['/^INSERT INTO cms_community_moderation_log/', static function (CsnWorld $w): \Closure {
            $mod = $w->member('moderator');
            $id = (int) $w->bans->ban($mod, $w->member(), 'write', 3600, 'Spam')['ban_id'];

            return static fn (): array => $w->bans->lift($mod, $id);
        }],
    ];
    foreach ($scenarios as $name => [$pattern, $setup]) {
        $w = new CsnWorld();
        $act = $setup($w);
        $w->hooks = [];
        $w->auto = [];
        $before = $w->snapshot();
        $w->db->failOn = [$pattern];
        $mark = $w->mark();
        csnLogReset();
        T::same(['success' => false, 'error' => 'db_error'], $act(), $name . ': Fehler an der letzten Anweisung ⇒ db_error');
        $last = array_values(array_filter($w->statements($mark), static fn (array $st): bool => $st['tx']));
        T::check(preg_match($pattern, (string) (end($last)['sql'] ?? '')) === 1, $name . ': die Einfügung ist die letzte Anweisung der Transaktion');
        T::same($before, $w->snapshot(), $name . ': nichts geschrieben');
        T::same([[], [], false], [$w->hooks, $w->auto, $w->db->getPdo()->inTransaction()], $name . ': kein Hook, keine Folgeaktion, keine offene Transaktion');
        T::check(str_contains(csnLog(), 'Testfehler'), $name . ': Warnung im Kanal community');
        $w->db->failOn = [];
        T::same(true, $act()['success'] ?? null, $name . ': Wiederholung gelingt');
    }
});

foreach (glob(__DIR__ . '/part-*.php') ?: [] as $part) {
    require $part;
}

T::finish();
