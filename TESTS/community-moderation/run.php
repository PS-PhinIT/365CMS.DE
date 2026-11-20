<?php
/**
 * Community-Moderation (PR #2b; Vertrag DESIGN-2b §3.10, §3.11, §4.4, §7.1; Entwurf moderation §6.1): `Service\ModerationService`
 * (Schließen, Anheften, Ankündigen, Umbenennen, Verschieben, Ausblenden, Wiederherstellen, Freigeben, Ablehnen, Lösung, Sammelaktion,
 * Warteschlange, Protokoll, Ablauf, Systemaktionen, `ipHashes`) und `Service\CounterBook` (Zählerbuchung S1).
 *
 * Orakel: `TESTS/lib/CommunityAudit.php` (`communityAudit()`, mit den Zusatzprüfungen aus §3.11: `has_attachments`, Reaktionszähler)
 * nach jedem Schritt. Prüfkürzel SECURITY-PRIVACY §1 (Vertrag §7.1b): M1 Freigabe, M2 Ablehnung, M3 Ausblenden/Wiederherstellen,
 * M4 Verschieben, M5 Anheften, M6 Pflichtgrund, M7 Protokoll; E3 404-Regel.
 *
 * Abschnitte (Besitzer): diese Datei M (W2); die „#2a-Pfade“ (Löschen über CounterBook, Protokoll `post.delete`/`post.edit`) und der
 * Lecktest der Warteschlange mit echtem `RenderService` ergänzt T in W3 (`part-services.php`, ohne „Wirkung:“). Die Warteschlange läuft hier
 * ohne Renderer (`presentable()` mit „alles verborgen“, fail-closed).
 * DB-frei (SQLite-Double), Zone Europe/Berlin. Wettläufe gegen MariaDB: `TESTS/community-db` (`sections-moderation.php`).
 *
 *   php TESTS/community-moderation/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

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
require_once dirname(__DIR__) . '/lib/CommunityAudit.php';

use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\ModerationLogRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\CounterBook;
use CMS\Services\Community\Service\ModerationService;
use CMS\Services\Community\Service\QuoteService;
use CMS\Services\Community\Support\Codes;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\Settings;

T::$verbose = in_array('-v', $argv ?? [], true);

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Hilfen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Unix-Zeit der Suite: 2026-11-15 10:00:00 UTC plus Sekunden. */
function mdAt(int $seconds = 0): int
{
    return gmmktime(10, 0, 0, 11, 15, 2026) + $seconds;
}

/** UTC-Text der Suite-Zeit. */
function mdUtc(int $seconds = 0): string
{
    return gmdate('Y-m-d H:i:s', mdAt($seconds));
}

/** Rechte der Rollen in der Welt mit echter `CommunityAccess` (`admin` nimmt die Klasse selbst aus). */
const MD_ROLE_CAPS = [
    'member' => ['community.view', 'community.participate'],
    'moderator' => ['community.view', 'community.participate', 'community.moderate'],
];

/**
 * Testwelt: SQLite-Double, steuerbare Uhr (mit Lesezähler), Rechteprüfung als Closure oder echte `CommunityAccess`, Moderationsbereich
 * als Closure, Hook-Protokoll mit Transaktionszustand und der Dienst (ohne Renderer).
 */
final class MdWorld
{
    public CommunityTestDatabase $db;
    public \ArrayObject $time;
    /** @var list<array{user: ?int, action: string, context: array<string, mixed>}> */
    public array $canLog = [];
    /** @var list<array{hook: string, args: list<mixed>, in_transaction: bool}> */
    public array $hooks = [];
    public int $clockReads = 0;
    /** @var (\Closure(?int, string, array<string, mixed>): ?AccessDecision)|null null oder Rückgabe null = erlaubt */
    public ?\Closure $rule = null;
    /** @var (\Closure(?int, array<string, mixed>): array<string, mixed>)|null Moderationsbereich; null = global mit allen Foren */
    public ?\Closure $scopeRule = null;
    /** @var list<array{user: ?int, context: array<string, mixed>}> */
    public array $scopeLog = [];
    public Settings $settings;
    public ModerationService $mod;
    public ?CommunityAccess $access = null;

    /**
     * @param array<string, mixed> $settings Rohwerte für Settings::fromArray()
     * @param list<int>|null $verified mit Liste: echte CommunityAccess, nur diese Konten gelten als bestätigt (null = Closure-Rechte)
     */
    public function __construct(array $settings = [], ?array $verified = null)
    {
        $this->db = CommunityTestDatabase::create();
        $this->time = new \ArrayObject(['now' => mdAt()]);
        $this->settings = Settings::fromArray($settings);
        if ($verified !== null) {
            $this->access = new CommunityAccess(
                $this->db,
                $this->settings,
                static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, MD_ROLE_CAPS[$role] ?? [], true),
                static fn (): bool => true,
                fn (): int => (int) $this->time['now'],
                static fn (int $userId): bool => in_array($userId, $verified, true) || $verified === ['*']
            );
        }
        $this->build();
    }

    /** Baut den Dienst neu (Zwischenspeicher der echten CommunityAccess bleiben; `flush()` leert sie). */
    public function build(): void
    {
        $clock = function (): int {
            $this->clockReads++;

            return (int) $this->time['now'];
        };
        $can = function (?int $userId, string $action, array $context): AccessDecision {
            $this->canLog[] = ['user' => $userId, 'action' => $action, 'context' => $context];
            if ($this->access !== null) {
                return $this->access->can($userId, $action, $context);
            }

            return ($this->rule !== null ? ($this->rule)($userId, $action, $context) : null) ?? AccessDecision::allow();
        };
        $dispatch = function (string $hook, mixed ...$args): void {
            $this->hooks[] = ['hook' => $hook, 'args' => $args, 'in_transaction' => $this->db->getPdo()->inTransaction()];
        };
        $scope = function (?int $userId, array $context = []): array {
            $this->scopeLog[] = ['user' => $userId, 'context' => $context];
            if ($this->scopeRule !== null) {
                return ($this->scopeRule)($userId, $context);
            }
            if ($this->access !== null) {
                return $this->access->moderationScope($userId, $context);
            }

            return ['global' => true, 'forum_ids' => array_map('intval', array_column($this->db->rows('community_forums'), 'id'))];
        };
        $this->mod = new ModerationService($this->db, $this->settings, $clock, $can, $dispatch, $scope, null);
    }

    public function at(int $seconds): void
    {
        $this->time['now'] = mdAt($seconds);
    }

    public function now(): string
    {
        return gmdate('Y-m-d H:i:s', (int) $this->time['now']);
    }

    /** @return array<string, mixed>|null */
    public function topic(int $id): ?array
    {
        return $this->db->row('community_topics', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function post(int $id): ?array
    {
        return $this->db->row('community_posts', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function forum(int $id): ?array
    {
        return $this->db->row('community_forums', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function stats(int $userId): ?array
    {
        return $this->db->row('community_user_stats', ['user_id' => $userId]);
    }

    /** `[topic_count, post_count, solutions_count]` eines Mitglieds (ohne Zeile: null). */
    public function counts(int $userId): ?array
    {
        $s = $this->stats($userId);

        return $s === null ? null : [(int) $s['topic_count'], (int) $s['post_count'], (int) $s['solutions_count']];
    }

    /** @return list<string> */
    public function hookNames(): array
    {
        return array_map(static fn (array $h): string => $h['hook'], $this->hooks);
    }

    /** @return list<string> Abweichungen des Orakels */
    public function audit(): array
    {
        return communityAudit($this->db, $this->now());
    }

    public function recount(): void
    {
        communityRecount($this->db, $this->now());
    }

    /** @return list<array<string, mixed>> Protokollzeilen nach ID */
    public function logRows(): array
    {
        return $this->db->rows('community_moderation_log', [], 'id');
    }

    /**
     * Inhalt aller Tabellen, die Moderation schreibt (für „nichts geschrieben“).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function snapshot(): array
    {
        $out = [];
        foreach (['community_forums', 'community_topics', 'community_posts', 'community_user_stats', 'community_moderation_log', 'community_reports'] as $table) {
            $out[$table] = $this->db->rows($table);
        }

        return $out;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->db->execute($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }
}

/** Oracle nach einem Schritt als eine Prüfung. */
function mdAudit(MdWorld $w, string $step): void
{
    T::same([], $w->audit(), 'Orakel nach „' . $step . '“: Zähler und Invarianten stimmen');
}

/**
 * Thema mit erstem Beitrag und Antworten `[[user, status], …]` (je 60 s Abstand ab `$o['at']`), direkt über die Fixtures. Optionen:
 * `status` (Thema, Standard `published`), `first` (Status des ersten Beitrags), `at` (Sekunden), `title`.
 *
 * @param list<array{0: int|null, 1: string}> $replies
 * @param array<string, mixed> $o
 * @return array{0: int, 1: list<int>} Thema-ID und Beitrags-IDs (erster Beitrag zuerst)
 */
function mdSeed(MdWorld $w, int $forum, ?int $starter, array $replies = [], array $o = []): array
{
    $at = (int) ($o['at'] ?? 0);
    $topic = $w->db->topic(['forum' => $forum, 'user' => $starter, 'status' => $o['status'] ?? 'published', 'created_at' => mdUtc($at)] + (isset($o['title']) ? ['title' => $o['title']] : []));
    $ids = [$w->db->post(['topic' => $topic, 'user' => $starter, 'first' => true, 'status' => $o['first'] ?? 'published', 'created_at' => mdUtc($at), 'body' => 'Themenstart ' . $topic])];
    foreach ($replies as $i => [$user, $status]) {
        $ids[] = $w->db->post(['topic' => $topic, 'user' => $user, 'status' => $status, 'created_at' => mdUtc($at + 60 * ($i + 1)), 'body' => 'Antwort ' . ($i + 1) . ' in ' . $topic]);
    }
    $w->db->execute('UPDATE cms_community_topics SET first_post_id = ? WHERE id = ?', [$ids[0], $topic]);

    return [$topic, $ids];
}

/** Lösung direkt setzen (alle drei Spalten). */
function mdSolve(MdWorld $w, int $topic, int $post, int $by): void
{
    $w->db->execute('UPDATE cms_community_topics SET solution_post_id = ?, solved_at = ?, solved_by = ? WHERE id = ?', [$post, mdUtc(), $by, $topic]);
}

/**
 * Protokoll der Anweisungen ab Index `$from`: Art (`lock` = FOR UPDATE, `read`, `write`), Tabelle ohne Präfix `cms_community_`,
 * Transaktionszustand und gebundene Parameter.
 *
 * @return list<array{tx: bool, kind: string, table: string, sql: string, params: list<mixed>, index: int}>
 */
function mdTrace(MdWorld $w, int $from = 0): array
{
    $trace = [];
    for ($i = $from, $n = count($w->db->log); $i < $n; $i++) {
        $sql = $w->db->log[$i];
        $kind = match (true) {
            str_contains($sql, ' FOR UPDATE') => 'lock',
            preg_match('/^\s*SELECT\b/i', $sql) === 1 => 'read',
            default => 'write',
        };
        preg_match('/\b(?:FROM|INTO|UPDATE)\s+cms_(\w+)/i', $sql, $m);
        $trace[] = ['tx' => $w->db->txAt($i), 'kind' => $kind, 'table' => str_replace('community_', '', $m[1] ?? '?'), 'sql' => $sql, 'params' => $w->db->paramsAt($i), 'index' => $i];
    }

    return $trace;
}

/**
 * Sperren in der Transaktion und Verstöße gegen „alle Sperren vor der ersten nicht sperrenden Anweisung“.
 *
 * @param list<array{tx: bool, kind: string, table: string, sql: string, params: list<mixed>, index: int}> $trace
 * @return array{locks: list<string>, problems: list<string>}
 */
function mdTxOrder(array $trace): array
{
    $inside = array_values(array_filter($trace, static fn (array $s): bool => $s['tx']));
    if ($inside === []) {
        return ['locks' => [], 'problems' => ['keine Anweisung innerhalb einer Transaktion']];
    }
    $problems = [];
    if ($inside[0]['kind'] !== 'lock') {
        $problems[] = 'erste Anweisung der Transaktion ist keine Sperre: ' . substr($inside[0]['sql'], 0, 80);
    }
    $firstOther = null;
    $locks = [];
    foreach ($inside as $position => $statement) {
        if ($statement['kind'] === 'lock') {
            if ($firstOther !== null) {
                $problems[] = 'Sperre auf ' . $statement['table'] . ' nach: ' . substr($inside[$firstOther]['sql'], 0, 80);
            }
            $locks[] = $statement['table'];
        } elseif ($firstOther === null) {
            $firstOther = $position;
        }
    }

    return ['locks' => $locks, 'problems' => $problems];
}

/** Schreibanweisungen ab `$from` (ohne das wirkungslose `INSERT IGNORE` der Statistikzeile im Autocommit). */
function mdWrites(MdWorld $w, int $from): array
{
    return array_values(array_filter(
        array_slice($w->db->log, $from),
        static fn (string $sql): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql) === 1 && preg_match('/^\s*INSERT IGNORE INTO cms_community_user_stats/i', $sql) !== 1
    ));
}

/** Wert eines Schlüssels oder die Markierung „fehlt“ (unterscheidet fehlend von null). */
function mdGet(array $array, string $key): mixed
{
    return array_key_exists($key, $array) ? $array[$key] : '<fehlt>';
}

/** Ergebnis als Status, `unchanged` oder Fehlercode (für knappe Vergleiche). */
function mdOutcome(array $result): string
{
    if (empty($result['success'])) {
        return (string) ($result['error'] ?? '?');
    }

    return array_key_exists('changed', $result) && $result['changed'] === false ? 'unchanged' : 'ok';
}

/** Quelltext ohne Kommentare (Zeilen bleiben erhalten). */
function mdCode(string $file): string
{
    $out = '';
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        $out .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($token[1], "\n")) : $token[1]) : $token;
    }

    return $out;
}

/** Forum-Moderator eintragen (Konto, mit Unterforen). */
function mdModerator(MdWorld $w, int $forum, int $user): void
{
    (new ForumRepository($w->db))->saveModerators($forum, [['subject_type' => 'user', 'subject_id' => $user, 'include_children' => 1]]);
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Wächter
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Wächter: Konstruktor ohne Datenbank und Uhr; Codes der Klassen bekannt; neue Fehlercodes erzeugt; Grenzen wie im Repository', static function (): void {
    $w = new MdWorld();
    T::same([0, 0], [count($w->db->log), $w->clockReads], 'Konstruieren liest weder Datenbank noch Uhr');
    $book = new CounterBook($w->db);
    T::same(0, count($w->db->log), 'CounterBook: Konstruieren ohne Datenbankanweisung');

    $known = array_merge(Codes::ERRORS, array_keys(Codes::ACCESS_REASONS));
    $unknown = [];
    foreach (['Service/ModerationService.php', 'Service/CounterBook.php'] as $file) {
        $code = mdCode(ABSPATH . 'core/Services/Community/' . $file);
        preg_match_all("/(?:failure\\('|'error' => '|=> '(?=topic_)|AccessDecision::deny\\(')([a-z_]+)'/", $code, $m);
        foreach ($m[1] as $error) {
            if (!in_array($error, $known, true)) {
                $unknown[] = $file . ': ' . $error;
            }
        }
    }
    T::same([], $unknown, 'jeder Fehlercode von ModerationService und CounterBook steht in Codes::ERRORS oder ACCESS_REASONS');
    $source = mdCode(ABSPATH . 'core/Services/Community/Service/ModerationService.php');
    foreach (['pin_limit', 'reason_required', 'invalid_state', 'bulk_limit'] as $code) {
        T::check(in_array($code, Codes::ERRORS, true) && str_contains($source, "'" . $code . "'"), 'Code ' . $code . ' ist registriert und wird von ModerationService erzeugt');
    }
    T::check(in_array('invalid_target', $known, true) && str_contains($source, "'invalid_target'"), 'invalid_target wird von ModerationService erzeugt (Beitrag oder Zielthema ungeeignet)');
    T::same(TopicRepository::MAX_PINNED, COMMUNITY_AUDIT_MAX_PINNED, 'Orakel und Repository haben dieselbe Pin-Grenze');
    T::same(0, preg_match_all('/(?<![\w>:$])time\s*\(/', mdCode(ABSPATH . 'core/Services/Community/Service/CounterBook.php')), 'CounterBook hat keine Uhr');
    T::same(1, preg_match_all('/(?<![\w>:$])time\s*\(/', $source), 'ModerationService: time( genau einmal (Standard der Uhr-Closure)');
    T::same(0, preg_match_all('/new\s+(?:PostService|TopicService)\s*\(/', preg_replace('/public function bulk\(.*?\n    }\n/s', '', $source) ?? ''), 'Post- und TopicService werden nur in bulk() gebaut (Löschen, Vertrag §1 Nr. 1)');
});

T::run('Wächter: die Uhr wird je Schreibmethode genau einmal gelesen, in bulk() einmal für alle, in Lesemethoden nie', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$t1, $p1] = mdSeed($w, $f1, $s, [[$a, 'published'], [$a, 'pending'], [$a, 'published'], [$a, 'pending']]);
    [$t2] = mdSeed($w, $f1, $s, [], ['status' => 'pending', 'first' => 'pending']);
    [$t3] = mdSeed($w, $f1, $s, [[$a, 'published']]);
    $w->recount();
    $calls = [
        'lock' => static fn () => $w->mod->lock($mod, $t1, mdAt(3600)),
        'unlock' => static fn () => $w->mod->unlock($mod, $t1),
        'pin' => static fn () => $w->mod->pin($mod, $t1),
        'unpin' => static fn () => $w->mod->unpin($mod, $t1),
        'announce' => static fn () => $w->mod->announce($mod, $t1),
        'unannounce' => static fn () => $w->mod->unannounce($mod, $t1),
        'rename' => static fn () => $w->mod->rename($mod, $t1, 'Neuer Titel für die Uhr'),
        'approvePost' => static fn () => $w->mod->approvePost($mod, $p1[2]),
        'hidePost' => static fn () => $w->mod->hidePost($mod, $p1[3], 'Grund'),
        'restorePost' => static fn () => $w->mod->restorePost($mod, $p1[3]),
        'rejectPost' => static fn () => $w->mod->rejectPost($mod, $p1[4], 'Grund'),
        'approveTopic' => static fn () => $w->mod->approveTopic($mod, $t2),
        'hideTopic' => static fn () => $w->mod->hideTopic($mod, $t2, 'Grund'),
        'restoreTopic' => static fn () => $w->mod->restoreTopic($mod, $t2),
        'rejectTopic' => static fn () => $w->mod->rejectTopic($mod, $t2, 'Grund'),
        'markSolution' => static fn () => $w->mod->markSolution($mod, $t1, $p1[1]),
        'unmarkSolution' => static fn () => $w->mod->unmarkSolution($mod, $t1),
        'movePosts' => static fn () => $w->mod->movePosts($mod, [$p1[1]], $t3),
        'moveTopic' => static fn () => $w->mod->moveTopic($mod, $t3, $f2),
        'expire' => static fn () => $w->mod->expire(),
        'ipHashes' => static fn () => $w->mod->ipHashes($mod, [$p1[0]]),
        'systemAction' => static fn () => $w->mod->systemAction('hide', 'post', $p1[3], ['reports' => 3]),
    ];
    $reads = [];
    foreach ($calls as $name => $call) {
        $before = $w->clockReads;
        $result = $call();
        $reads[$name] = [$w->clockReads - $before, mdOutcome($result)];
    }
    $expected = array_map(static fn (): array => [1, 'ok'], $calls);
    $expected['rejectTopic'] = [1, 'invalid_state'];   // nach hide/restore ist das Thema veröffentlicht, nicht mehr wartend
    T::same($expected, $reads, 'jede Schreibmethode, expire, ipHashes und systemAction lesen die Uhr genau einmal');
    $before = $w->clockReads;
    $bulk = $w->mod->bulk($mod, 'topic.lock', [$t1, $t2, $t3]);
    T::same([1, 3], [$w->clockReads - $before, $bulk['done'] ?? null], 'bulk(): eine Uhr für drei Elemente');
    $before = $w->clockReads;
    $w->mod->queue($mod);
    $w->mod->queueSummary($mod);
    $w->mod->log($mod);
    T::same(0, $w->clockReads - $before, 'queue, queueSummary und log lesen keine Uhr');
    $before = $w->clockReads;
    $w->mod->hideTopic($mod, $t1, '');
    $w->mod->bulk($mod, 'nichts', [1]);
    T::same(0, $w->clockReads - $before, 'Eingabefehler (Pflichtgrund, unbekannte Operation) lesen keine Uhr');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Zählerbuchung (S1)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Zählerbuchung: Freigabe älterer und neuerer wartender Antworten, last_* neu bestimmt, Freigabe ist keine Aktivität', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $b = $w->db->user();
    $f = $w->db->forum();
    [$t, $p] = mdSeed($w, $f, $s, [[$a, 'pending'], [$b, 'published'], [$a, 'pending']]);
    $w->recount();
    mdAudit($w, 'Ausgangszustand');
    $w->at(600);
    $older = $w->mod->approvePost($mod, $p[1]);
    T::same(['ok', 'published'], [mdOutcome($older), $older['status'] ?? null], 'ältere wartende Antwort (ID < last_post_id) freigegeben');
    $topic = $w->topic($t);
    T::same([2, $p[2], mdUtc(120), $b], [(int) $topic['reply_count'], (int) $topic['last_post_id'], (string) $topic['last_post_at'], (int) $topic['last_poster_id']], 'Thema: reply_count + 1, last_* bleibt der jüngste veröffentlichte Beitrag');
    T::same([1, 3], [(int) $w->forum($f)['topic_count'], (int) $w->forum($f)['post_count']], 'Forum: post_count + 1');
    T::same([0, 1, 0], $w->counts($a), 'Autor: post_count + 1');
    mdAudit($w, 'Freigabe der älteren Antwort');
    $newer = $w->mod->approvePost($mod, $p[3]);
    $topic = $w->topic($t);
    T::same(['ok', $p[3], mdUtc(180), $a], [mdOutcome($newer), (int) $topic['last_post_id'], (string) $topic['last_post_at'], (int) $topic['last_poster_id']], 'neuere wartende Antwort: last_* = sie, last_post_at = ihre Anlagezeit (nicht die Freigabezeit)');
    T::same([$p[3], mdUtc(180), $t], [(int) $w->forum($f)['last_post_id'], (string) $w->forum($f)['last_post_at'], (int) $w->forum($f)['last_topic_id']], 'Forum: last_* neu bestimmt');
    mdAudit($w, 'Freigabe der neueren Antwort');
});

T::run('Zählerbuchung: Freigabe in wartendem oder ausgeblendetem Thema bucht nur das Thema; Ausblenden der letzten Antwort bis created_at', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $f = $w->db->forum();
    [$pendingTopic, $pp] = mdSeed($w, $f, $s, [[$mod, 'published'], [$a, 'pending']], ['status' => 'pending', 'first' => 'pending']);
    [$hiddenTopic, $hp] = mdSeed($w, $f, $s, [[$a, 'pending']], ['status' => 'hidden', 'at' => 1000]);
    $w->recount();
    $forumBefore = [(int) $w->forum($f)['topic_count'], (int) $w->forum($f)['post_count']];
    T::same(['ok', 'ok'], [mdOutcome($w->mod->approvePost($mod, $pp[2])), mdOutcome($w->mod->approvePost($mod, $hp[1]))], 'Freigabe in wartendem und in ausgeblendetem Thema');
    T::same([2, 1], [(int) $w->topic($pendingTopic)['reply_count'], (int) $w->topic($hiddenTopic)['reply_count']], 'Thema: reply_count + 1 (zählt unabhängig vom Themenstatus)');
    T::same($forumBefore, [(int) $w->forum($f)['topic_count'], (int) $w->forum($f)['post_count']], 'Forum unverändert (S1)');
    T::same([0, 0, 0], $w->counts($a), 'Autor unverändert (S1: Beitrag zählt nur in veröffentlichtem Thema)');
    mdAudit($w, 'Freigabe in nicht veröffentlichten Themen');

    // Ausblenden der einzigen veröffentlichten Antwort eines Themas mit wartendem Themenstart: last_* auf NULL, last_post_at = created_at
    T::same('ok', mdOutcome($w->mod->hidePost($mod, $pp[2], 'Grund')), 'zweite Antwort wieder ausgeblendet');
    T::same('ok', mdOutcome($w->mod->hidePost($mod, $pp[1], 'Grund')), 'einzige veröffentlichte Antwort ausgeblendet');
    $topic = $w->topic($pendingTopic);
    T::same([0, null, mdUtc(0), null], [(int) $topic['reply_count'], $topic['last_post_id'], (string) $topic['last_post_at'], $topic['last_poster_id']], 'ohne veröffentlichten Beitrag: last_post_id NULL, last_post_at = Anlagezeit des Themas');
    mdAudit($w, 'Ausblenden bis zur Anlagezeit');

    // Ausblenden der letzten Antwort eines veröffentlichten Themas
    [$t, $p] = mdSeed($w, $f, $s, [[$a, 'published'], [$mod, 'published']], ['at' => 2000]);
    $w->recount();
    T::same('ok', mdOutcome($w->mod->hidePost($mod, $p[2], 'Grund')), 'letzte Antwort ausgeblendet');
    $topic = $w->topic($t);
    T::same([1, $p[1], mdUtc(2060), $a], [(int) $topic['reply_count'], (int) $topic['last_post_id'], (string) $topic['last_post_at'], (int) $topic['last_poster_id']], 'last_* zurück auf die vorige Antwort');
    T::same([$p[1], $t], [(int) $w->forum($f)['last_post_id'], (int) $w->forum($f)['last_topic_id']], 'Forum: last_* neu bestimmt');
    mdAudit($w, 'Ausblenden der letzten Antwort');
});

T::run('Zählerbuchung: Freigabe eines Themas mit zwei Moderationsantworten bucht jeden genau einmal (keine Doppelbuchung)', static function (): void {
    $w = new MdWorld();
    $m1 = $w->db->user(['role' => 'moderator']);
    $m2 = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $f = $w->db->forum();
    mdSeed($w, $f, $s, [], ['at' => -5000]);
    [$t, $p] = mdSeed($w, $f, $s, [[$m1, 'published'], [$m2, 'published']], ['status' => 'pending', 'first' => 'pending']);
    $w->recount();
    T::same([[1, 1], 2, [1, 1, 0]], [[(int) $w->forum($f)['topic_count'], (int) $w->forum($f)['post_count']], (int) $w->topic($t)['reply_count'], $w->counts($s)], 'vorher: Forum zählt nur das andere Thema, reply_count 2');
    $result = $w->mod->approveTopic($m1, $t);
    T::same(['ok', 'published', $p[0]], [mdOutcome($result), $result['status'] ?? null, $result['post_id'] ?? null], 'Thema freigegeben (erster Beitrag)');
    T::same([2, 4], [(int) $w->forum($f)['topic_count'], (int) $w->forum($f)['post_count']], 'Forum: +1 Thema, +3 Beiträge (Themenstart und zwei Antworten)');
    T::same([2, [2, 2, 0], [0, 1, 0], [0, 1, 0]], [(int) $w->topic($t)['reply_count'], $w->counts($s), $w->counts($m1), $w->counts($m2)], 'reply_count bleibt 2; Starter +1/+1, Moderatoren je +1');
    T::same([Events::TOPIC_APPROVED], $w->hookNames(), 'Hook topic_approved');
    T::same([$t, $f, $m1, $p[0]], $w->hooks[0]['args'], 'Argumente: Thema, Forum, Moderator, erster Beitrag');
    mdAudit($w, 'Freigabe des Themas');
    $again = $w->mod->approveTopic($m1, $t);
    T::same('unchanged', mdOutcome($again), 'zweite Freigabe: changed = false');
    mdAudit($w, 'zweite Freigabe');
});

T::run('Zählerbuchung: Ablehnen bucht nichts; Ausblenden und Wiederherstellen eines Themas mit drei Autoren und Lösung', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $b = $w->db->user();
    $f = $w->db->forum();
    [$t, $p] = mdSeed($w, $f, $s, [[$a, 'published'], [$b, 'published'], [$a, 'published'], [$b, 'pending']]);
    mdSolve($w, $t, $p[1], $s);
    [$other] = mdSeed($w, $f, $a, [], ['at' => -9000]);
    $w->recount();
    $before = $w->snapshot();
    T::same(['ok', 'rejected'], [mdOutcome($r = $w->mod->rejectPost($mod, $p[4], 'Doppelt')), $r['status'] ?? null], 'wartende Antwort abgelehnt');
    $after = $w->snapshot();
    T::same([$before['community_forums'], $before['community_user_stats']], [$after['community_forums'], $after['community_user_stats']], 'Ablehnen: Foren- und Mitgliederzähler unverändert');
    T::same(['rejected', 'Doppelt'], [$w->post($p[4])['status'], $w->post($p[4])['status_reason']], 'abgelehnt mit status_reason = Grund');
    mdAudit($w, 'Ablehnen');

    T::same([[1, 1, 0], [1, 3, 1], [0, 1, 0]], [$w->counts($s), $w->counts($a), $w->counts($b)], 'vorher: Starter, Autor A (mit Lösung und eigenem Thema), Autor B');
    $w->hooks = [];
    $hidden = $w->mod->hideTopic($mod, $t, 'Spam');
    T::same(['ok', 'hidden'], [mdOutcome($hidden), $hidden['status'] ?? null], 'Thema ausgeblendet');
    T::same([[0, 0, 0], [1, 1, 0], [0, 0, 0]], [$w->counts($s), $w->counts($a), $w->counts($b)], 'alle Autoren gebucht, Lösungsautor solutions − 1');
    T::same([1, 1, $other], [(int) $w->forum($f)['topic_count'], (int) $w->forum($f)['post_count'], (int) $w->forum($f)['last_topic_id']], 'Forum: −1 Thema, −4 Beiträge, last_* auf das andere Thema');
    T::same([$p[1], 'published'], [(int) $w->topic($t)['solution_post_id'], $w->topic($t)['previous_status']], 'Lösung bleibt am Thema; previous_status published');
    mdAudit($w, 'Thema ausgeblendet');
    $restored = $w->mod->restoreTopic($mod, $t);
    T::same(['ok', 'published'], [mdOutcome($restored), $restored['status'] ?? null], 'Thema wiederhergestellt');
    T::same([[1, 1, 0], [1, 3, 1], [0, 1, 0]], [$w->counts($s), $w->counts($a), $w->counts($b)], 'alle Autoren zurückgebucht');
    T::same([2, 5, $t], [(int) $w->forum($f)['topic_count'], (int) $w->forum($f)['post_count'], (int) $w->forum($f)['last_topic_id']], 'Forum zurück');
    mdAudit($w, 'Thema wiederhergestellt');
    T::same([Events::TOPIC_HIDDEN, Events::TOPIC_RESTORED], $w->hookNames(), 'Hooks topic_hidden, topic_restored');
    T::same([$t, $f, $mod, 'published'], $w->hooks[1]['args'], 'topic_restored: Thema, Forum, Akteur, Status');
});

T::run('Zählerbuchung: ein nie freigegebenes Thema kehrt nach pending zurück; Verschieben veröffentlicht und ausgeblendet', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$never] = mdSeed($w, $f1, $s, [[$mod, 'published']], ['status' => 'hidden', 'first' => 'pending']);
    $w->recount();
    $result = $w->mod->restoreTopic($mod, $never);
    T::same(['ok', 'pending', 'pending'], [mdOutcome($result), $result['status'] ?? null, $w->topic($never)['status']], 'Wiederherstellen eines nie freigegebenen Themas: pending');
    T::same([[0, 0], [0, 0, 0]], [[(int) $w->forum($f1)['topic_count'], (int) $w->forum($f1)['post_count']], $w->counts($s)], 'nichts gebucht (Thema zählt nicht)');
    mdAudit($w, 'Wiederherstellen nach pending');

    [$t, $p] = mdSeed($w, $f1, $s, [[$a, 'published'], [$a, 'hidden']], ['at' => 100]);
    [$h] = mdSeed($w, $f1, $a, [[$s, 'published']], ['status' => 'hidden', 'at' => 200]);
    $w->recount();
    $moved = $w->mod->moveTopic($mod, $t, $f2, false);
    T::same(['ok', $f1, $f2, null], [mdOutcome($moved), $moved['from_forum_id'] ?? null, $moved['to_forum_id'] ?? null, mdGet($moved, 'stub_topic_id')], 'veröffentlichtes Thema verschoben (ohne Verweis)');
    T::same([[0, 0], [1, 2]], [[(int) $w->forum($f1)['topic_count'], (int) $w->forum($f1)['post_count']], [(int) $w->forum($f2)['topic_count'], (int) $w->forum($f2)['post_count']]], 'Quelle −1/−2, Ziel +1/+2 (die ausgeblendete Antwort zählt nicht)');
    T::same([$f2, $f2, $f2], array_map(static fn (int $id): int => (int) $w->post($id)['forum_id'], $p), 'alle Beiträge tragen das neue Forum');
    mdAudit($w, 'veröffentlichtes Thema verschoben');
    $movedHidden = $w->mod->moveTopic($mod, $h, $f2, true);
    T::same(['ok', null], [mdOutcome($movedHidden), mdGet($movedHidden, 'stub_topic_id')], 'ausgeblendetes Thema verschoben: kein Verweis');
    T::same([[0, 0], [1, 2]], [[(int) $w->forum($f1)['topic_count'], (int) $w->forum($f1)['post_count']], [(int) $w->forum($f2)['topic_count'], (int) $w->forum($f2)['post_count']]], 'Zähler unverändert (Thema zählt nicht)');
    mdAudit($w, 'ausgeblendetes Thema verschoben');
});

T::run('Zählerbuchung: Beiträge zwischen Foren und im selben Forum verschieben (letzter Beitrag, ausgeblendete zählen nicht)', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $b = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$src, $sp] = mdSeed($w, $f1, $s, [[$a, 'published'], [$b, 'hidden'], [$a, 'published']]);
    [$dst, $dp] = mdSeed($w, $f2, $b, [[$s, 'published']], ['at' => -3000]);
    [$same] = mdSeed($w, $f1, $b, [], ['at' => -6000]);
    $w->recount();
    $result = $w->mod->movePosts($mod, [$sp[3], $sp[2]], $dst);
    T::same(['ok', $src, $dst, [$sp[2], $sp[3]], false], [mdOutcome($result), $result['from_topic_id'] ?? null, $result['to_topic_id'] ?? null, $result['moved'] ?? null, $result['solution_cleared'] ?? null], 'zwei Antworten (eine ausgeblendet, eine die letzte) verschoben, IDs aufsteigend');
    T::same([[1, $sp[1]], [2, $dp[1]]], [[(int) $w->topic($src)['reply_count'], (int) $w->topic($src)['last_post_id']], [(int) $w->topic($dst)['reply_count'], (int) $w->topic($dst)['last_post_id']]], 'Quelle −1 (last_* zurück), Ziel +1 (last_* nach ID-Ordnung: der jüngere Beitrag des Ziels)');
    T::same([[2, 3], [1, 3]], [[(int) $w->forum($f1)['topic_count'], (int) $w->forum($f1)['post_count']], [(int) $w->forum($f2)['topic_count'], (int) $w->forum($f2)['post_count']]], 'Foren: Quelle −1, Ziel +1 Beitrag');
    T::same([[0, 2, 0], [2, 2, 0]], [$w->counts($a), $w->counts($b)], 'Mitglieder unverändert (beide Themen veröffentlicht)');
    mdAudit($w, 'zwischen Foren verschoben');
    $result = $w->mod->movePosts($mod, [$sp[1]], $same);
    T::same(['ok', [$sp[1]]], [mdOutcome($result), $result['moved'] ?? null], 'Antwort im selben Forum verschoben');
    T::same([2, 3], [(int) $w->forum($f1)['topic_count'], (int) $w->forum($f1)['post_count']], 'gleiches Forum: Zähler unverändert');
    mdAudit($w, 'im selben Forum verschoben');
    T::same(array_fill(0, 3, Events::POST_MOVED), $w->hookNames(), 'post_moved je Beitrag');
    T::same([$sp[2], $src, $dst, $mod], $w->hooks[0]['args'], 'post_moved: Beitrag, Quelle, Ziel, Akteur (aufsteigend)');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Sperren (Vertrag §3.10, moderation §2.0)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Sperren: Reihenfolge je Muster, alle Sperren vor der ersten nicht sperrenden Anweisung, INSERT IGNORE nur vor BEGIN', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$t, $p] = mdSeed($w, $f1, $s, [[$a, 'published'], [$a, 'pending'], [$a, 'pending'], [$a, 'published']]);
    $n = $w->db->user();
    [$pt] = mdSeed($w, $f1, $n, [], ['status' => 'pending', 'first' => 'pending', 'at' => 500]);
    [$rt] = mdSeed($w, $f1, $s, [], ['status' => 'pending', 'first' => 'pending', 'at' => 600]);
    [$dst] = mdSeed($w, $f2, $a, [], ['at' => 700]);
    $w->recount();
    // Der Starter des wartenden Themas hat noch keine Statistikzeile: die Freigabe legt sie vor BEGIN an.
    $w->db->execute('DELETE FROM cms_community_user_stats WHERE user_id = ?', [$n]);
    $check = static function (string $label, \Closure $call, array $locks) use ($w): array {
        $from = count($w->db->log);
        $result = $call();
        $trace = mdTrace($w, $from);
        $order = mdTxOrder($trace);
        T::same(['ok', $locks, []], [mdOutcome($result), $order['locks'], $order['problems']], $label . ': Sperren ' . implode(' → ', $locks) . ', danach erst lesen und schreiben');
        $inserts = array_values(array_filter($trace, static fn (array $s): bool => preg_match('/^\s*INSERT IGNORE INTO cms_community_user_stats/i', $s['sql']) === 1));
        T::same([], array_values(array_filter($inserts, static fn (array $s): bool => $s['tx'])), $label . ': INSERT IGNORE der Statistikzeilen nur vor BEGIN');
        $writes = array_values(array_filter($trace, static fn (array $s): bool => $s['tx'] && $s['kind'] === 'write'));
        T::check($writes !== [] && str_starts_with((string) end($writes)['sql'], 'INSERT INTO cms_community_moderation_log'), $label . ': Protokoll ist die letzte Schreibanweisung der Transaktion');
        $lastLock = max(array_merge([-1], array_map(static fn (array $s): int => $s['index'], array_filter($trace, static fn (array $s): bool => $s['kind'] === 'lock'))));
        $reassign = array_values(array_filter($trace, static fn (array $s): bool => str_starts_with($s['sql'], 'UPDATE cms_community_reports')));
        T::check($reassign === [] || min(array_column($reassign, 'index')) > $lastLock, $label . ': Meldungen erst nach allen Sperren umgehängt');
        T::same([false], array_values(array_unique(array_column(array_slice($w->hooks, -1), 'in_transaction'))), $label . ': Hook nach dem Commit');

        return $trace;
    };
    $check('lock', static fn () => $w->mod->lock($mod, $t), ['forums', 'topics']);
    $check('pin', static fn () => $w->mod->pin($mod, $t), ['forums', 'topics']);
    $check('rename', static fn () => $w->mod->rename($mod, $t, 'Umbenanntes Thema'), ['forums', 'topics']);
    $check('approvePost', static fn () => $w->mod->approvePost($mod, $p[2]), ['forums', 'topics', 'posts', 'user_stats']);
    $check('rejectPost', static fn () => $w->mod->rejectPost($mod, $p[3], 'Grund'), ['forums', 'topics', 'posts']);
    $check('hidePost', static fn () => $w->mod->hidePost($mod, $p[4], 'Grund'), ['forums', 'topics', 'posts', 'user_stats']);
    $check('restorePost', static fn () => $w->mod->restorePost($mod, $p[4]), ['forums', 'topics', 'posts', 'user_stats']);
    $check('markSolution', static fn () => $w->mod->markSolution($mod, $t, $p[1]), ['forums', 'topics', 'posts', 'user_stats']);
    $check('unmarkSolution', static fn () => $w->mod->unmarkSolution($mod, $t), ['forums', 'topics', 'user_stats']);
    $from = count($w->db->log);
    $check('approveTopic', static fn () => $w->mod->approveTopic($mod, $pt), ['forums', 'topics', 'posts', 'user_stats']);
    $inserts = array_values(array_filter(mdTrace($w, $from), static fn (array $st): bool => str_starts_with($st['sql'], 'INSERT IGNORE INTO cms_community_user_stats')));
    T::same([[false], [1, 1, 0]], [array_column($inserts, 'tx'), $w->counts($n)], 'approveTopic: fehlende Statistikzeile des Starters entsteht im Autocommit (CounterBook::prepare), gebucht unter der Sperre');
    $check('rejectTopic', static fn () => $w->mod->rejectTopic($mod, $rt, 'Grund'), ['forums', 'topics', 'posts']);
    $check('hideTopic', static fn () => $w->mod->hideTopic($mod, $t, 'Grund'), ['forums', 'topics', 'user_stats']);
    $check('restoreTopic', static fn () => $w->mod->restoreTopic($mod, $t), ['forums', 'topics', 'posts', 'user_stats']);
    mdSolve($w, $t, $p[1], $s);
    $w->recount();
    $trace = $check('movePosts', static fn () => $w->mod->movePosts($mod, [$p[4], $p[1]], $dst), ['forums', 'forums', 'topics', 'topics', 'posts', 'user_stats']);
    $locks = array_values(array_filter($trace, static fn (array $s): bool => $s['kind'] === 'lock'));
    T::same([[$f1], [$f2], [$t], [$dst], [$p[1], $p[4]], [$a]], array_map(static fn (array $s): array => $s['params'], $locks), 'movePosts: Foren aufsteigend, Themen aufsteigend, Beiträge aufsteigend, Statistik des Lösungsautors');
    $lastLock = max(array_map(static fn (array $s): int => $s['index'], $locks));
    $reassign = array_values(array_filter($trace, static fn (array $s): bool => str_starts_with($s['sql'], 'UPDATE cms_community_reports')));
    T::check($reassign !== [] && min(array_map(static fn (array $s): int => $s['index'], $reassign)) > $lastLock, 'movePosts: reassignPosts erst nach allen Sperren der Ebenen 1 bis 5 (auch nach der Statistiksperre)');
    $log = array_values(array_filter($trace, static fn (array $s): bool => $s['tx'] && $s['kind'] !== 'read'));
    T::check(str_starts_with((string) end($log)['sql'], 'INSERT INTO cms_community_moderation_log'), 'movePosts: Protokoll ist die letzte Schreibanweisung');

    foreach ([[$dst, $f1, 'Ziel kleiner als Quelle'], [$dst, $f2, 'zurück']] as [$topic, $target, $label]) {
        $from = count($w->db->log);
        $result = $w->mod->moveTopic($mod, $topic, $target, false);
        $locks = array_values(array_filter(mdTrace($w, $from), static fn (array $s): bool => $s['kind'] === 'lock'));
        T::same(['ok', ['forums', 'forums', 'topics'], [[$f1], [$f2], [$topic]]], [mdOutcome($result), array_column($locks, 'table'), array_column($locks, 'params')], 'moveTopic (' . $label . '): beide Foren aufsteigend, dann das Thema');
        $trace = mdTrace($w, $from);
        $reassign = array_values(array_filter($trace, static fn (array $st): bool => str_starts_with($st['sql'], 'UPDATE cms_community_reports')));
        T::check($reassign !== [] && min(array_column($reassign, 'index')) > max(array_column($locks, 'index')), 'moveTopic (' . $label . '): reassignTopic nach allen Sperren');
    }
});

T::run('Sperren: mehr als 100 Autoren werden in Stücken aufsteigend gesperrt; keine Statistiksperre bei rejectPost, lock, pin', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $f = $w->db->forum();
    $authors = [];
    for ($i = 0; $i < 120; $i++) {
        $authors[] = $w->db->user();
    }
    [$t] = mdSeed($w, $f, $s, array_map(static fn (int $u): array => [$u, 'published'], array_reverse($authors)));
    $w->recount();
    $from = count($w->db->log);
    T::same('ok', mdOutcome($w->mod->hideTopic($mod, $t, 'Grund')), 'Thema mit 121 Autoren ausgeblendet');
    $locks = array_values(array_filter(mdTrace($w, $from), static fn (array $st): bool => $st['kind'] === 'lock' && $st['table'] === 'user_stats'));
    $ids = array_merge(...array_column($locks, 'params'));
    $all = array_merge([$s], $authors);
    sort($all);
    T::same([2, $all], [count($locks), $ids], 'zwei Sperranweisungen (100 + 21), IDs eindeutig und aufsteigend über alle Stücke');
    mdAudit($w, 'Ausblenden mit 121 Autoren');
});

T::run('Sperren: Verschieben schreibt Verweis-Themen erst nach dem Umhängen der Meldungen, das Protokoll zuletzt (Vertrag §3.10)', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$t, $p] = mdSeed($w, $f1, $s, [[$s, 'published']]);
    $w->recount();
    $out = $w->mod->moveTopic($mod, $t, $f2);
    $oldStub = (int) ($out['stub_topic_id'] ?? 0);
    $w->db->report(['target' => $t, 'target_type' => 'topic', 'forum' => $f2]);
    $w->db->report(['target' => $p[1], 'forum' => $f2]);
    $from = count($w->db->log);
    $back = $w->mod->moveTopic($mod, $t, $f1);
    $classes = [];
    foreach (mdTrace($w, $from) as $st) {
        if (!$st['tx'] || $st['kind'] !== 'write') {
            continue;
        }
        $class = match (true) {
            str_starts_with($st['sql'], 'UPDATE cms_community_reports') => 'Meldungen',
            str_starts_with($st['sql'], 'DELETE FROM cms_community_topics'), str_starts_with($st['sql'], 'INSERT INTO cms_community_topics') => 'Verweis',
            str_starts_with($st['sql'], 'INSERT INTO cms_community_moderation_log') => 'Protokoll',
            default => null,
        };
        if ($class !== null && end($classes) !== $class) {
            $classes[] = $class;
        }
    }
    T::same(['ok', null, ['Meldungen', 'Verweis', 'Protokoll']], [mdOutcome($back), $w->topic($oldStub), $classes], 'zurückverschoben: erst Meldungen umgehängt, dann alter Verweis gelöscht und neuer angelegt, zuletzt das Protokoll');
    T::same([$f1, $f1], [(int) $w->db->row('community_reports', ['target_id' => $t, 'target_type' => 'topic'])['forum_id'], (int) $w->db->row('community_reports', ['target_id' => $p[1]])['forum_id']], 'offene Meldungen im neuen Forum');
    mdAudit($w, 'Zurückverschieben mit Meldungen');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Wiederholung zwischen Vorprüfung und Sperre
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Muster der ersten Forensperre (Beginn jeder Transaktion der Moderation). */
const MD_FORUM_LOCK = '/^SELECT \* FROM cms_community_forums WHERE id = \? LIMIT 1 FOR UPDATE$/';

T::run('Wiederholung: Forumswechsel, neuer Autor und Lösungswechsel zwischen Plan und Sperre ⇒ zweiter Versuch; dreimal ⇒ db_error', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $n = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$t, $p] = mdSeed($w, $f1, $s, [[$a, 'published'], [$a, 'published']]);
    $w->recount();

    // Thema wechselt das Forum: die Sperre trifft das alte Forum, `null`, zweiter Versuch im neuen Forum
    $w->db->beforeExecute[MD_FORUM_LOCK] = static function () use ($w, $t, $f2): void {
        $w->db->execute('UPDATE cms_community_topics SET forum_id = ? WHERE id = ?', [$f2, $t]);
        $w->db->execute('UPDATE cms_community_posts SET forum_id = ? WHERE topic_id = ?', [$f2, $t]);
        $w->recount();
    };
    $from = count($w->db->log);
    $result = $w->mod->hideTopic($mod, $t, 'Grund');
    $forumLocks = array_values(array_filter(mdTrace($w, $from), static fn (array $st): bool => $st['kind'] === 'lock' && $st['table'] === 'forums'));
    T::same(['ok', $f2, [[$f1], [$f2]]], [mdOutcome($result), $result['forum_id'] ?? null, array_column($forumLocks, 'params')], 'Forumswechsel: erst das alte Forum gesperrt, dann das neue');
    mdAudit($w, 'Ausblenden nach Forumswechsel');
    T::same([$f2], array_column(array_filter($w->logRows(), static fn (array $r): bool => $r['action'] === 'topic.hide'), 'forum_id'), 'Protokoll mit dem Forum des zweiten Versuchs');

    // Neuer Autor zwischen Plan und Sperre: covers() scheitert, zweiter Versuch mit neuem Plan
    $w->mod->restoreTopic($mod, $t);
    $w->db->beforeExecute[MD_FORUM_LOCK] = static function () use ($w, $t, $n): void {
        $w->db->post(['topic' => $t, 'user' => $n, 'created_at' => mdUtc(900), 'body' => 'Neuer Autor']);
        $w->recount();
    };
    $from = count($w->db->log);
    $result = $w->mod->hideTopic($mod, $t, 'Grund');
    $statsLocks = array_values(array_filter(mdTrace($w, $from), static fn (array $st): bool => $st['kind'] === 'lock' && $st['table'] === 'user_stats'));
    T::same(['ok', [[$s, $a], [$s, $a, $n]], [0, 0, 0]], [mdOutcome($result), array_column($statsLocks, 'params'), $w->counts($n)], 'neuer Autor: zweiter Versuch sperrt ihn mit und bucht ihn');
    mdAudit($w, 'Ausblenden mit neuem Autor');
    $w->mod->restoreTopic($mod, $t);

    // Lösung wechselt zwischen Plan und Sperre
    mdSolve($w, $t, $p[1], $s);
    $w->recount();
    $w->db->beforeExecute[MD_FORUM_LOCK] = static function () use ($w, $t, $p, $s): void {
        mdSolve($w, $t, $p[2], $s);
        $w->recount();
    };
    $result = $w->mod->markSolution($mod, $t, $p[1]);
    T::same(['ok', $p[1], $p[2]], [mdOutcome($result), (int) $w->topic($t)['solution_post_id'], $result['previous_post_id'] ?? null], 'Lösungswechsel: zweiter Versuch mit der neuen alten Lösung');
    mdAudit($w, 'Lösung nach Wechsel');

    // Dreimal: das Thema wandert bei jedem Versuch weiter
    $flip = null;
    $flip = static function () use ($w, $t, $f1, $f2, &$flip): void {
        $to = (int) $w->topic($t)['forum_id'] === $f1 ? $f2 : $f1;
        $w->db->execute('UPDATE cms_community_topics SET forum_id = ? WHERE id = ?', [$to, $t]);
        $w->db->execute('UPDATE cms_community_posts SET forum_id = ? WHERE topic_id = ?', [$to, $t]);
        $w->recount();
        $w->db->beforeExecute[MD_FORUM_LOCK] = $flip;
    };
    $w->db->beforeExecute[MD_FORUM_LOCK] = $flip;
    $w->hooks = [];
    $logs = count($w->logRows());
    $result = $w->mod->hideTopic($mod, $t, 'Grund');
    unset($w->db->beforeExecute[MD_FORUM_LOCK]);
    T::same(['db_error', 'published', [], $logs], [mdOutcome($result), $w->topic($t)['status'], $w->hooks, count($w->logRows())], 'drei Planabweichungen: db_error, nichts geschrieben, kein Hook, kein Protokoll');
    mdAudit($w, 'nach drei Abweichungen');
});

/** Parameter der Forensperren ab Index `$from` (je Sperre die Forum-ID). @return list<list<mixed>> */
function mdForumLocks(MdWorld $w, int $from): array
{
    return array_column(array_values(array_filter(mdTrace($w, $from), static fn (array $st): bool => $st['kind'] === 'lock' && $st['table'] === 'forums')), 'params');
}

T::run('Wiederholung: Thema, Beiträge und Antwort wechseln zwischen Plan und Sperre den Ort ⇒ zweiter Versuch mit neuem Plan', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    $f3 = $w->db->forum();
    [$t] = mdSeed($w, $f1, $s, [[$a, 'published'], [$a, 'published']]);
    [$dst] = mdSeed($w, $f2, $s, [], ['at' => 300]);
    [$src, $sp] = mdSeed($w, $f1, $s, [[$a, 'published'], [$a, 'published'], [$a, 'published'], [$a, 'published']], ['at' => 600]);
    [$side, $xp] = mdSeed($w, $f1, $s, [[$a, 'pending']], ['at' => 900]);
    $w->recount();
    $relocate = static function (int $topic, int $forum) use ($w): void {
        $w->db->execute('UPDATE cms_community_topics SET forum_id = ? WHERE id = ?', [$forum, $topic]);
        $w->db->execute('UPDATE cms_community_posts SET forum_id = ? WHERE topic_id = ?', [$forum, $topic]);
        $w->recount();
    };

    // moveTopic: das Thema liegt beim Sperren schon in f3 ⇒ zweiter Versuch mit der Quelle f3 (Foren erneut aufsteigend)
    $w->db->beforeExecute[MD_FORUM_LOCK] = static fn () => $relocate($t, $f3);
    $from = count($w->db->log);
    $moved = $w->mod->moveTopic($mod, $t, $f2, false);
    T::same(['ok', $f3, $f2, [[$f1], [$f2], [$f2], [$f3]]], [mdOutcome($moved), $moved['from_forum_id'] ?? null, $moved['to_forum_id'] ?? null, mdForumLocks($w, $from)], 'moveTopic: Forumswechsel vor der Sperre, zweiter Versuch verschiebt aus dem neuen Forum');
    $rows = $w->logRows();
    T::same([$f3, ['from_forum' => $f3, 'to_forum' => $f2, 'stub' => 0, 'unpinned' => false]], [(int) end($rows)['forum_id'], json_decode((string) end($rows)['details_json'], true)], 'moveTopic: Protokoll mit dem Quellforum des zweiten Versuchs');
    mdAudit($w, 'moveTopic nach Forumswechsel');

    // movePosts: das Quellthema wechselt das Forum ⇒ zweiter Versuch mit neuem Plan
    $w->db->beforeExecute[MD_FORUM_LOCK] = static fn () => $relocate($src, $f3);
    $from = count($w->db->log);
    $result = $w->mod->movePosts($mod, [$sp[1]], $dst);
    T::same(['ok', [[$f1], [$f2], [$f2], [$f3]], $f2], [mdOutcome($result), mdForumLocks($w, $from), (int) $w->post($sp[1])['forum_id']], 'movePosts: Forumswechsel der Quelle, zweiter Versuch sperrt f2 und f3');
    mdAudit($w, 'movePosts nach Forumswechsel');

    // movePosts: zwischen Plan und Sperre wird ein verschobener Beitrag zur Lösung der Quelle ⇒ zweiter Versuch sperrt den Lösungsautor
    $w->db->beforeExecute[MD_FORUM_LOCK] = static function () use ($w, $src, $sp, $s): void {
        mdSolve($w, $src, $sp[2], $s);
        $w->recount();
    };
    $from = count($w->db->log);
    $result = $w->mod->movePosts($mod, [$sp[2]], $dst);
    $statsLocks = array_column(array_values(array_filter(mdTrace($w, $from), static fn (array $st): bool => $st['kind'] === 'lock' && $st['table'] === 'user_stats')), 'params');
    T::same(['ok', true, [[$a]], null], [mdOutcome($result), $result['solution_cleared'] ?? null, $statsLocks, $w->topic($src)['solution_post_id']], 'movePosts: Lösungswechsel vor der Sperre, zweiter Versuch hebt die neue Lösung auf und bucht ihren Autor');
    mdAudit($w, 'movePosts nach Lösungswechsel');

    // movePosts: ein Beitrag verlässt die Quelle zwischen Plan und Sperre ⇒ zweiter Versuch sieht gemischte Themen (invalid_state)
    $w->db->beforeExecute[MD_FORUM_LOCK] = static function () use ($w, $sp, $side, $f1): void {
        $w->db->execute('UPDATE cms_community_posts SET topic_id = ?, forum_id = ? WHERE id = ?', [$side, $f1, $sp[3]]);
        $w->recount();
    };
    $logs = count($w->logRows());
    $result = $w->mod->movePosts($mod, [$sp[3], $sp[4]], $dst);
    T::same(['invalid_state', $side, $logs], [mdOutcome($result), (int) $w->post($sp[3])['topic_id'], count($w->logRows())], 'movePosts: Beitrag nicht mehr in der Quelle ⇒ kein Verschieben, kein Protokoll');
    mdAudit($w, 'movePosts nach Wegzug eines Beitrags');

    // approvePost: die wartende Antwort wandert in ein Thema eines anderen Forums ⇒ zweiter Versuch im neuen Thema
    $w->db->beforeExecute[MD_FORUM_LOCK] = static function () use ($w, $xp, $dst, $f2): void {
        $w->db->execute('UPDATE cms_community_posts SET topic_id = ?, forum_id = ? WHERE id = ?', [$dst, $f2, $xp[1]]);
        $w->recount();
    };
    $from = count($w->db->log);
    $approved = $w->mod->approvePost($mod, $xp[1]);
    T::same(['ok', $dst, $f2, [[$f1], [$f2]]], [mdOutcome($approved), $approved['topic_id'] ?? null, $approved['forum_id'] ?? null, mdForumLocks($w, $from)], 'approvePost: Antwort vor der Sperre verschoben, zweiter Versuch bucht im neuen Thema');
    mdAudit($w, 'approvePost nach Ortswechsel');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Idempotenz
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Idempotenz: jede Methode zweimal ⇒ changed = false, kein Protokoll, kein Hook, keine Schreibanweisung', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$t, $p] = mdSeed($w, $f1, $s, [[$a, 'published'], [$a, 'pending'], [$a, 'pending'], [$a, 'published'], [$a, 'published']]);
    [$pt] = mdSeed($w, $f1, $s, [], ['status' => 'pending', 'first' => 'pending', 'at' => 500]);
    [$rt] = mdSeed($w, $f1, $s, [], ['status' => 'pending', 'first' => 'pending', 'at' => 600]);
    [$ht] = mdSeed($w, $f1, $s, [[$a, 'published']], ['at' => 700]);
    [$dst] = mdSeed($w, $f2, $a, [], ['at' => 800]);
    [$mt] = mdSeed($w, $f1, $a, [], ['at' => 900]);
    $w->recount();
    $calls = [
        'lock' => static fn () => $w->mod->lock($mod, $t, mdAt(3600)),
        'unlock' => static fn () => $w->mod->unlock($mod, $t),
        'pin' => static fn () => $w->mod->pin($mod, $t),
        'unpin' => static fn () => $w->mod->unpin($mod, $t),
        'announce' => static fn () => $w->mod->announce($mod, $t),
        'unannounce' => static fn () => $w->mod->unannounce($mod, $t),
        'rename' => static fn () => $w->mod->rename($mod, $t, 'Zweimal derselbe Titel', 'Info'),
        'approvePost' => static fn () => $w->mod->approvePost($mod, $p[2]),
        'rejectPost' => static fn () => $w->mod->rejectPost($mod, $p[3], 'Grund'),
        'hidePost' => static fn () => $w->mod->hidePost($mod, $p[4], 'Grund'),
        'restorePost' => static fn () => $w->mod->restorePost($mod, $p[4]),
        'markSolution' => static fn () => $w->mod->markSolution($mod, $t, $p[1]),
        'unmarkSolution' => static fn () => $w->mod->unmarkSolution($mod, $t),
        'approveTopic' => static fn () => $w->mod->approveTopic($mod, $pt),
        'rejectTopic' => static fn () => $w->mod->rejectTopic($mod, $rt, 'Grund'),
        'hideTopic' => static fn () => $w->mod->hideTopic($mod, $ht, 'Grund'),
        'restoreTopic' => static fn () => $w->mod->restoreTopic($mod, $ht),
        'movePosts' => static fn () => $w->mod->movePosts($mod, [$p[5]], $dst),
        'moveTopic' => static fn () => $w->mod->moveTopic($mod, $mt, $f2),
    ];
    foreach ($calls as $name => $call) {
        $first = $call();
        $w->hooks = [];
        $logs = count($w->logRows());
        $snapshot = $w->snapshot();
        $from = count($w->db->log);
        $second = $call();
        T::same(['ok', 'unchanged', [], [], $logs, null], [mdOutcome($first), mdOutcome($second), mdWrites($w, $from), $w->hooks, count($w->logRows()), mdGet($second, 'log_id')], $name . ': zweiter Aufruf ohne Wirkung');
        T::same($snapshot, $w->snapshot(), $name . ': Tabellen unverändert');
    }
    mdAudit($w, 'Idempotenz');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Pflichtgrund (M6)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Pflichtgrund: vier Aktionen ohne Grund ⇒ reason_required ohne Datenbankanweisung; Grund normalisiert', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $f = $w->db->forum();
    [$t, $p] = mdSeed($w, $f, $s, [[$s, 'published'], [$s, 'pending']]);
    [$pt] = mdSeed($w, $f, $s, [], ['status' => 'pending', 'first' => 'pending']);
    $w->recount();
    $from = count($w->db->log);
    $calls = [
        'post.hide' => static fn () => $w->mod->hidePost($mod, $p[1], " \n\t "),
        'post.reject' => static fn () => $w->mod->rejectPost($mod, $p[2]),
        'topic.hide' => static fn () => $w->mod->hideTopic($mod, $t, "\u{200B}"),
        'topic.reject' => static fn () => $w->mod->rejectTopic($mod, $pt),
        'bulk post.hide' => static fn () => $w->mod->bulk($mod, 'post.hide', [$p[1]]),
        'bulk topic.reject' => static fn () => $w->mod->bulk($mod, 'topic.reject', [$pt], ['reason' => '']),
    ];
    $outcomes = array_map(static fn (\Closure $c): string => mdOutcome($c()), $calls);
    T::same(array_fill_keys(array_keys($calls), 'reason_required'), $outcomes, 'ohne Grund (leer, Leerraum, Nullbreite): reason_required');
    T::same([0, [], 0], [count($w->db->log) - $from, $w->canLog, $w->clockReads], 'keine Datenbankanweisung, keine Rechteprüfung, keine Uhr');

    $long = "Zeile\x07 eins\nzwei " . str_repeat('ä', 300);
    T::same('ok', mdOutcome($w->mod->hidePost($mod, $p[1], $long)), 'Grund mit Steuerzeichen und 300 Zeichen');
    $reason = (string) $w->post($p[1])['status_reason'];
    T::same([255, 'Zeile eins zwei ä'], [mb_strlen($reason), mb_substr($reason, 0, 17)], 'status_reason: eine Zeile ohne Steuerzeichen, höchstens 255 Zeichen');
    $rows = $w->logRows();
    T::same($reason, (string) end($rows)['reason'], 'Protokoll: derselbe normalisierte Grund');
    T::same('ok', mdOutcome($w->mod->lock($mod, $t)), 'Schließen braucht keinen Grund');
    $rows = $w->logRows();
    T::same(null, end($rows)['reason'], 'ohne Grund: reason NULL');
});

T::run('Wirkung: moderation_reason_required (an: reason_required ohne Anweisung; aus: Erfolg mit status_reason NULL)', static function (): void {
    foreach ([true, false] as $required) {
        $w = new MdWorld(['moderation_reason_required' => $required]);
        $mod = $w->db->user(['role' => 'moderator']);
        $s = $w->db->user();
        $f = $w->db->forum();
        [$t, $p] = mdSeed($w, $f, $s, [[$s, 'published'], [$s, 'pending']]);
        $w->recount();
        $from = count($w->db->log);
        $hide = $w->mod->hidePost($mod, $p[1]);
        $reject = $w->mod->rejectPost($mod, $p[2]);
        if ($required) {
            T::same(['reason_required', 'reason_required', 0], [mdOutcome($hide), mdOutcome($reject), count($w->db->log) - $from], 'an: ohne Grund abgelehnt, keine Anweisung');
        } else {
            T::same(['ok', 'ok', null, null], [mdOutcome($hide), mdOutcome($reject), $w->post($p[1])['status_reason'], $w->post($p[2])['status_reason']], 'aus: ohne Grund erfolgreich, status_reason NULL');
            T::same([null, null], array_column(array_slice($w->logRows(), -2), 'reason'), 'aus: Protokoll ohne Grund');
            mdAudit($w, 'ohne Pflichtgrund');
        }
    }
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Anheften (M5)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Forum mit `$count` angehefteten Themen im Status `$status` (Frist `$until`). @return list<int> */
function mdPins(MdWorld $w, int $forum, int $count, string $status = 'published', ?string $until = null, int $at = 0): array
{
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $ids[] = $w->db->topic(['forum' => $forum, 'status' => $status, 'pinned' => true, 'pinned_until' => $until, 'created_at' => mdUtc($at + $i)]);
    }

    return $ids;
}

T::run('Anheften: 50 wirksame Pins ⇒ pin_limit; abgelaufene zählen nicht, wartende und ausgeblendete schon, gelöschte nicht; neue Frist am Limit erlaubt', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $f = $w->db->forum();
    $pins = array_merge(mdPins($w, $f, 46), mdPins($w, $f, 2, 'pending', null, 100), mdPins($w, $f, 2, 'hidden', mdUtc(3600), 200));
    mdPins($w, $f, 3, 'deleted', null, 300);
    $expired = mdPins($w, $f, 1, 'published', mdUtc(0), 400);
    [$t] = mdSeed($w, $f, $s, [], ['at' => 500]);
    $w->recount();
    $from = count($w->db->log);
    $result = $w->mod->pin($mod, $t);
    T::same(['pin_limit', [], 0], [mdOutcome($result), mdWrites($w, $from), (int) $w->topic($t)['is_pinned']], '50 wirksame Pins (46 veröffentlicht, 2 wartend, 2 ausgeblendet): 51. abgelehnt, nichts geschrieben');
    T::same('ok', mdOutcome($w->mod->pin($mod, $pins[0], mdAt(7200))), 'neue Frist an einem angehefteten Thema am Limit erlaubt');
    T::same(mdUtc(7200), $w->topic($pins[0])['pinned_until'], 'Frist gespeichert (UTC)');
    T::same('pin_limit', mdOutcome($w->mod->pin($mod, $expired[0], mdAt(60))), 'abgelaufener Pin ist nicht wirksam: neu anheften braucht Platz');
    $w->db->execute("UPDATE cms_community_topics SET status = 'deleted' WHERE id = ?", [$pins[1]]);
    $w->recount();
    T::same('ok', mdOutcome($w->mod->pin($mod, $t)), 'ein gelöschtes angeheftetes Thema gibt Platz frei');
    T::same([], communityAudit($w->db, $w->now()), 'Orakel: höchstens 50 wirksame Pins');

    $w2 = new MdWorld();
    $mod2 = $w2->db->user(['role' => 'moderator']);
    $f2 = $w2->db->forum();
    mdPins($w2, $f2, 49);
    mdPins($w2, $f2, 3, 'published', mdUtc(-1), 100);
    [$free] = mdSeed($w2, $f2, $mod2, [], ['at' => 200]);
    $w2->recount();
    T::same('ok', mdOutcome($w2->mod->pin($mod2, $free)), '49 wirksame und 3 abgelaufene Pins: das 50. Anheften gelingt');
    T::same('pin_limit', mdOutcome($w2->mod->pin($mod2, $w2->db->topic(['forum' => $f2]))), 'danach ist das Forum voll');
});

T::run('Anheften: Verschieben in ein volles Forum löst die Anheftung (unpinned)', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $full = $w->db->forum();
    $src = $w->db->forum();
    mdPins($w, $full, 50);
    [$t] = mdSeed($w, $src, $s, [], ['at' => 500]);
    $w->db->execute('UPDATE cms_community_topics SET is_pinned = 1, pinned_until = ? WHERE id = ?', [mdUtc(7200), $t]);
    $w->recount();
    $result = $w->mod->moveTopic($mod, $t, $full, false);
    T::same(['ok', true, 0, null], [mdOutcome($result), $result['unpinned'] ?? null, (int) $w->topic($t)['is_pinned'], $w->topic($t)['pinned_until']], 'Ziel voll: verschoben, Anheftung gelöst');
    $rows = $w->logRows();
    $details = json_decode((string) end($rows)['details_json'], true);
    T::same(['from_forum' => $src, 'to_forum' => $full, 'stub' => 0, 'unpinned' => true], $details, 'Protokoll topic.move mit unpinned');
    mdAudit($w, 'Verschieben in volles Forum');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Verschieben (M4)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Verschieben (echte CommunityAccess): Verweis-Thema, alter Verweis gelöscht, Ziel gleich Quelle, link, archiviert, nicht moderiert, unsichtbar', static function (): void {
    $w = new MdWorld([], ['*']);
    $gm = $w->db->user(['role' => 'moderator']);
    $fm = $w->db->user();
    $s = $w->db->user();
    $src = $w->db->forum();
    $dst = $w->db->forum();
    $link = $w->db->forum(['type' => 'link']);
    $arch = $w->db->forum(['status' => 'archived']);
    $hid = $w->db->forum(['status' => 'hidden']);
    mdModerator($w, $src, $fm);
    [$t, $p] = mdSeed($w, $src, $s, [[$s, 'published']]);
    [$h] = mdSeed($w, $src, $s, [], ['status' => 'hidden', 'at' => 300]);
    $w->recount();

    $moved = $w->mod->moveTopic($gm, $t, $dst);
    $stubId = (int) ($moved['stub_topic_id'] ?? 0);
    $stub = $w->topic($stubId);
    T::same(['ok', $src, $dst, false], [mdOutcome($moved), $moved['from_forum_id'] ?? null, $moved['to_forum_id'] ?? null, $moved['unpinned'] ?? null], 'Thema verschoben');
    T::same(['moved', $src, $t, mdUtc(7 * 86400), $s, (string) $w->topic($t)['title'], 0, null, (string) $w->topic($t)['last_post_at']],
        [$stub['status'], (int) $stub['forum_id'], (int) $stub['moved_to_topic_id'], $stub['moved_expires_at'], (int) $stub['user_id'], (string) $stub['title'], (int) $stub['reply_count'], $stub['last_post_id'], (string) $stub['last_post_at']],
        'Verweis-Thema im Quellforum: Status moved, Ziel, Ablauf nach moved_stub_days (7), Starter, Titel, ohne Beiträge');
    T::same([$stubId], array_map(static fn (array $r): int => (int) $r['id'], (new TopicRepository($w->db))->listMoved($src, $w->now())), 'listMoved zeigt den Verweis');
    T::same([[0, 0], [1, 2]], [[(int) $w->forum($src)['topic_count'], (int) $w->forum($src)['post_count']], [(int) $w->forum($dst)['topic_count'], (int) $w->forum($dst)['post_count']]], 'der Verweis zählt nirgends');
    T::same([Events::TOPIC_MOVED], $w->hookNames(), 'Hook topic_moved');
    T::same([$t, $src, $dst, $gm, $stubId], $w->hooks[0]['args'], 'topic_moved: Thema, Quelle, Ziel, Akteur, Verweis');
    mdAudit($w, 'Verschieben mit Verweis');

    $back = $w->mod->moveTopic($gm, $t, $src);
    T::same(['ok', null], [mdOutcome($back), $w->topic($stubId)], 'zurück: der alte Verweis im Ziel auf dieses Thema ist gelöscht');
    T::same([(int) $back['stub_topic_id']], array_map(static fn (array $r): int => (int) $r['id'], (new TopicRepository($w->db))->listMoved($dst, $w->now())), 'neuer Verweis im verlassenen Forum');
    mdAudit($w, 'Zurückverschieben');

    $w->hooks = [];
    T::same(['unchanged', []], [mdOutcome($w->mod->moveTopic($gm, $t, $src)), $w->hookNames()], 'Ziel = aktuelles Forum (wiederholtes Absenden): changed = false nach erlaubtem Rechte-Tor');
    T::same('forum_link', mdOutcome($w->mod->moveTopic($gm, $t, $link)), 'Ziel vom Typ link: forum_link');
    T::same('forum_archived', mdOutcome($w->mod->moveTopic($gm, $t, $arch)), 'Ziel archiviert: forum_archived');
    T::same('no_permission', mdOutcome($w->mod->moveTopic($fm, $t, $dst)), 'Forum-Moderator, Ziel sichtbar, aber nicht moderiert: no_permission');
    T::same(['not_found', 'not_found'], [mdOutcome($w->mod->moveTopic($fm, $t, $hid)), mdOutcome($w->mod->moveTopic($fm, $t, 999999))], 'Forum-Moderator: unsichtbares und fehlendes Zielforum ergeben dasselbe (not_found)');
    T::same('no_permission', mdOutcome($w->mod->moveTopic($fm, $h, $dst)), 'ausgeblendetes Thema: Ziel nicht moderiert');
    $hiddenMove = $w->mod->moveTopic($gm, $h, $dst, true);
    T::same(['ok', null], [mdOutcome($hiddenMove), $hiddenMove['stub_topic_id'] ?? null], 'ausgeblendetes Thema: kein Verweis, auch mit stub = true');
    T::same([$dst], array_values(array_unique(array_map(static fn (array $r): int => (int) $r['forum_id'], $w->db->rows('community_posts', ['topic_id' => $h])))), 'alle Beiträge des Themas tragen das Zielforum');
    T::same($src, (int) $w->topic($t)['forum_id'], 'Thema bleibt nach den Ablehnungen im Quellforum');
    mdAudit($w, 'Verschieben, Ablehnungen');
    $reports = $w->db->report(['target' => $t, 'target_type' => 'topic', 'forum' => $src]);
    $closed = $w->db->report(['target' => $p[1], 'forum' => $src, 'status' => 'valid']);
    $open = $w->db->report(['target' => $p[1], 'forum' => $src]);
    $w->mod->moveTopic($gm, $t, $dst, false);
    T::same([$dst, $src, $dst], [(int) $w->db->row('community_reports', ['id' => $reports])['forum_id'], (int) $w->db->row('community_reports', ['id' => $closed])['forum_id'], (int) $w->db->row('community_reports', ['id' => $open])['forum_id']], 'offene Meldungen zu Thema und Beiträgen folgen dem Thema, erledigte behalten ihr Forum');
});

T::run('Wirkung: moved_stub_days (1 und 30 Tage: Ablaufzeit, listMoved vor und nach Ablauf, expire löscht genau ab der Grenze)', static function (): void {
    foreach ([1, 30] as $days) {
        $w = new MdWorld(['moved_stub_days' => $days]);
        $mod = $w->db->user(['role' => 'moderator']);
        $src = $w->db->forum();
        $dst = $w->db->forum();
        [$t] = mdSeed($w, $src, $mod);
        $w->recount();
        $stub = (int) $w->mod->moveTopic($mod, $t, $dst)['stub_topic_id'];
        $expires = mdUtc($days * 86400);
        T::same($expires, $w->topic($stub)['moved_expires_at'], $days . ' Tage: moved_expires_at = jetzt + ' . $days . ' × 86400 s (UTC)');
        $topics = new TopicRepository($w->db);
        T::same([[$stub], []], [array_map(static fn (array $r): int => (int) $r['id'], $topics->listMoved($src, mdUtc($days * 86400 - 1))), $topics->listMoved($src, $expires)], $days . ' Tage: listMoved eine Sekunde vor Ablauf mit, ab Ablauf ohne Verweis');
        $w->at($days * 86400 - 1);
        T::same(0, $w->mod->expire()['stubs'] ?? null, $days . ' Tage: expire eine Sekunde vor Ablauf löscht nichts');
        $w->at($days * 86400);
        T::same([1, null], [$w->mod->expire()['stubs'] ?? null, $w->topic($stub)], $days . ' Tage: expire genau an der Grenze löscht den Verweis');
        mdAudit($w, 'Verweis abgelaufen (' . $days . ' Tage)');
    }
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Beiträge verschieben (Vertrag §4.4)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Beiträge verschieben: Eingaben, Köder-ID für fehlende und gemischte Beiträge, Reihenfolge Rechte → first_post → changed → Ziel', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $f = $w->db->forum();
    [$a, $ap] = mdSeed($w, $f, $s, [[$s, 'published'], [$s, 'published']]);
    [$b, $bp] = mdSeed($w, $f, $s, [[$s, 'published']], ['at' => 300]);
    [$pend] = mdSeed($w, $f, $s, [], ['status' => 'pending', 'first' => 'pending', 'at' => 600]);
    [$hid, $hp] = mdSeed($w, $f, $s, [[$s, 'published']], ['status' => 'hidden', 'at' => 900]);
    $w->recount();
    $from = count($w->db->log);
    T::same(['bulk_limit', 0], [mdOutcome($w->mod->movePosts($mod, range(1, 101), $b)), count($w->db->log) - $from], '101 IDs: bulk_limit ohne Anweisung');
    T::same(['invalid_input', 'invalid_input', 'invalid_input', 'invalid_input'], [
        mdOutcome($w->mod->movePosts($mod, [], $b)), mdOutcome($w->mod->movePosts($mod, [$ap[1], $ap[1]], $b)),
        mdOutcome($w->mod->movePosts($mod, ['3'], $b)), mdOutcome($w->mod->movePosts($mod, [$ap[1]], 0)),
    ], 'leer, doppelt, kein int, Ziel 0: invalid_input');

    $decoys = [];
    $w->rule = static function (?int $u, string $action, array $ctx) use (&$decoys): ?AccessDecision {
        if ($action === 'post.move' && empty($ctx['fresh'])) {
            $decoys[] = [$ctx['topic_id'] ?? null, $ctx['target_forum_id'] ?? null];
        }

        return null;
    };
    $w->mod->movePosts($mod, [$ap[1], 999999], $b);
    $w->mod->movePosts($mod, [$ap[1], $bp[1]], $b);
    $w->mod->movePosts($mod, [$ap[1]], 999999);
    T::same([[CommunityAccess::DECOY_ID, $f], [CommunityAccess::DECOY_ID, $f], [$a, CommunityAccess::DECOY_ID]], $decoys, 'fehlender oder gemischter Beitrag: Quelle = Köder-ID; fehlendes Zielthema: Zielforum = Köder-ID');
    $w->rule = static fn (?int $u, string $action, array $ctx): ?AccessDecision => ($ctx['topic_id'] ?? 0) === CommunityAccess::DECOY_ID || ($ctx['target_forum_id'] ?? 0) === CommunityAccess::DECOY_ID ? AccessDecision::deny('not_found') : null;
    T::same(['not_found', 'not_found', 'not_found'], [
        mdOutcome($w->mod->movePosts($mod, [$ap[1], 999999], $b)), mdOutcome($w->mod->movePosts($mod, [$ap[1], $bp[1]], $b)), mdOutcome($w->mod->movePosts($mod, [$ap[1]], 999999)),
    ], 'Köder-ID ergibt not_found (wie ein unsichtbares Ziel)');
    $w->rule = static fn (?int $u, string $action): ?AccessDecision => $action === 'post.move' ? AccessDecision::deny('no_permission') : null;
    T::same('no_permission', mdOutcome($w->mod->movePosts($mod, [$ap[0]], $b)), 'Rechte vor first_post: ein Unberechtigter erfährt nichts über den Themenstart');
    $w->rule = null;
    T::same('first_post', mdOutcome($w->mod->movePosts($mod, [$ap[0], $ap[1]], $b)), 'mit erstem Beitrag: first_post');
    T::same('unchanged', mdOutcome($w->mod->movePosts($mod, [$bp[1]], $b)), 'alle schon im Ziel: changed = false');
    T::same('invalid_target', mdOutcome($w->mod->movePosts($mod, [$ap[1]], $pend)), 'Ziel wartend: invalid_target');
    T::same('invalid_state', mdOutcome($w->mod->movePosts($mod, [$hp[1]], $b)), 'Quelle ausgeblendet: invalid_state');
    $stub = $w->db->topic(['forum' => $f, 'status' => 'moved']);
    T::same('invalid_target', mdOutcome($w->mod->movePosts($mod, [$ap[1]], $stub)), 'Ziel ist ein Verweis-Thema: invalid_target');
    $w->db->execute("UPDATE cms_community_posts SET status = 'deleted' WHERE id = ?", [$ap[2]]);
    $w->recount();
    T::same('invalid_state', mdOutcome($w->mod->movePosts($mod, [$ap[1], $ap[2]], $b)), 'gelöschter Beitrag: invalid_state');
    T::same([], $w->logRows(), 'kein Protokoll ohne Wirkung');
    mdAudit($w, 'Ablehnungen beim Verschieben');
});

T::run('Beiträge verschieben: Lösung aufgehoben samt Hook-Reihenfolge, ein Protokoll je Beitrag, Reaktionen und has_attachments beider Themen', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $r1 = $w->db->user();
    $r2 = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$src, $sp] = mdSeed($w, $f1, $s, [[$a, 'published'], [$a, 'published'], [$a, 'hidden']]);
    [$dst] = mdSeed($w, $f2, $s, [], ['at' => 1000]);
    mdSolve($w, $src, $sp[1], $s);
    foreach ([[$sp[1], $r1], [$sp[1], $r2], [$sp[3], $r1], [$sp[2], $r2]] as [$post, $user]) {
        $w->db->reaction(['post' => $post, 'user' => $user]);
    }
    $w->db->attachment(['post' => $sp[1], 'user' => $a]);
    $w->db->attachment(['post' => $sp[3], 'user' => $a]);
    $w->recount();
    T::same([1, 4, 0, 0], [(int) $w->topic($src)['has_attachments'], (int) $w->topic($src)['reaction_count'], (int) $w->topic($dst)['has_attachments'], (int) $w->topic($dst)['reaction_count']], 'vorher: Quelle mit Anhang und 4 Reaktionen');
    $result = $w->mod->movePosts($mod, [$sp[3], $sp[1]], $dst, 'Falsches Thema');
    T::same(['ok', true, [$sp[1], $sp[3]]], [mdOutcome($result), $result['solution_cleared'] ?? null, $result['moved'] ?? null], 'Lösung und ausgeblendete Antwort verschoben');
    T::same([null, null, null], [$w->topic($src)['solution_post_id'], $w->topic($src)['solved_at'], $w->topic($src)['solved_by']], 'Lösung der Quelle aufgehoben (alle drei Spalten)');
    T::same([0, 1], [(int) $w->counts($a)[2], 0 + count($w->db->rows('community_posts', ['topic_id' => $dst, 'status' => 'published'])) - 1], 'Lösungsautor solutions − 1; eine veröffentlichte Antwort im Ziel');
    T::same([0, 1, 1, 3], [(int) $w->topic($src)['has_attachments'], (int) $w->topic($src)['reaction_count'], (int) $w->topic($dst)['has_attachments'], (int) $w->topic($dst)['reaction_count']], 'Anhang und Reaktionen (alle Status) wandern zum Ziel');
    T::same([Events::POST_MOVED, Events::POST_MOVED, Events::SOLUTION_UNMARKED], $w->hookNames(), 'Hooks: post_moved je Beitrag aufsteigend, danach solution_unmarked');
    T::same([[$sp[1], $src, $dst, $mod], [$sp[3], $src, $dst, $mod], [$src, $sp[1], $mod]], array_column($w->hooks, 'args'), 'Hook-Argumente');
    T::same([[$sp[1], $f1, 'Falsches Thema', ['from_topic' => $src, 'to_topic' => $dst]], [$sp[3], $f1, 'Falsches Thema', ['from_topic' => $src, 'to_topic' => $dst]]],
        array_map(static fn (array $r): array => [(int) $r['target_id'], (int) $r['forum_id'], $r['reason'], json_decode((string) $r['details_json'], true)], $w->logRows()),
        'Protokoll post.move je Beitrag: Quellforum, Grund, Details');
    T::same(['post.move', 'post.move'], array_column($w->logRows(), 'action'), 'Aktion post.move');
    mdAudit($w, 'Verschieben mit Lösung, Reaktionen und Anhängen');

    // Anhang an einer ausgeblendeten Antwort zählt nicht; Verschieben einer Antwort ohne Anhang fragt nur einmal
    [$third, $tp] = mdSeed($w, $f2, $s, [[$a, 'published']], ['at' => 2000]);
    $w->recount();
    $from = count($w->db->log);
    $w->mod->movePosts($mod, [$tp[1]], $dst);
    $attachmentQueries = array_values(array_filter(array_slice($w->db->log, $from), static fn (string $q): bool => str_contains($q, 'community_attachments')));
    T::same(1, count($attachmentQueries), 'Antwort ohne Anhang: eine Abfrage (withAttachments), keine Neubestimmung');
    mdAudit($w, 'Verschieben ohne Anhang');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Freigabe, Ablehnung, Ausblenden, Wiederherstellen (M1 bis M3)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Freigabe und Ablehnung: erster Beitrag leitet auf das Thema um; status_reason; Ablehnen im gelöschten Thema erlaubt, Freigeben nicht', static function (): void {
    $w = new MdWorld([], ['*']);
    $gm = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $f = $w->db->forum();
    [$t1, $p1] = mdSeed($w, $f, $s, [], ['status' => 'pending', 'first' => 'pending']);
    [$t2, $p2] = mdSeed($w, $f, $s, [], ['status' => 'pending', 'first' => 'pending', 'at' => 100]);
    [$t3, $p3] = mdSeed($w, $f, $s, [[$s, 'pending'], [$s, 'pending']], ['status' => 'deleted', 'at' => 200]);
    $w->recount();
    $approved = $w->mod->approvePost($gm, $p1[0]);
    T::same(['ok', 'published', $p1[0], 'published', [Events::TOPIC_APPROVED]], [mdOutcome($approved), $approved['status'] ?? null, $approved['post_id'] ?? null, $w->topic($t1)['status'], $w->hookNames()], 'approvePost auf den Themenstart: Thema freigegeben (topic_approved)');
    $w->hooks = [];
    $rejected = $w->mod->rejectPost($gm, $p2[0], 'Kein Inhalt');
    T::same(['ok', 'deleted', 'pending', 'rejected', 'Kein Inhalt', [Events::TOPIC_REJECTED]], [mdOutcome($rejected), $w->topic($t2)['status'], $w->topic($t2)['previous_status'], $w->post($p2[0])['status'], $w->post($p2[0])['status_reason'], $w->hookNames()], 'rejectPost auf den Themenstart: Thema deleted (previous pending), erster Beitrag rejected mit Grund');
    $logId = (int) end($w->hooks)['args'][3];
    T::same(['topic.reject', 'Kein Inhalt', ['first_post' => $p2[0]]], [$w->db->row('community_moderation_log', ['id' => $logId])['action'], $w->db->row('community_moderation_log', ['id' => $logId])['reason'], json_decode((string) $w->db->row('community_moderation_log', ['id' => $logId])['details_json'], true)], 'topic_rejected trägt die Protokoll-ID');
    T::same('unchanged', mdOutcome($w->mod->rejectTopic($gm, $t2, 'Kein Inhalt')), 'zweites Ablehnen: changed = false (can() meldet topic_deleted, der Zustand ist schon erreicht)');
    T::same(['ok', 'rejected'], [mdOutcome($w->mod->rejectPost($gm, $p3[1], 'Alt')), $w->post($p3[1])['status']], 'wartende Antwort im gelöschten Thema ablehnen: erlaubt');
    T::same('topic_deleted', mdOutcome($w->mod->approvePost($gm, $p3[2])), 'wartende Antwort im gelöschten Thema freigeben: topic_deleted');
    mdAudit($w, 'Freigabe und Ablehnung');
});

T::run('Freigabe und Ablehnung: max_pending_per_user wird nach dem Ablehnen frei (echte CommunityAccess)', static function (): void {
    $unlimited = array_fill_keys(Settings::LIMIT_FIELDS, -1);
    $w = new MdWorld(['max_pending_per_user' => 1, 'new_member_approval_posts' => 5, 'rate_topic_seconds' => 0, 'rate_post_seconds' => 0, 'trust_limits' => array_fill(0, 5, $unlimited)], ['*']);
    $gm = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $f = $w->db->forum();
    [$t] = mdSeed($w, $f, $s, [], ['status' => 'pending', 'first' => 'pending']);
    $w->recount();
    $deny = $w->access->can($s, 'create_topic', ['forum_id' => $f, 'now' => mdAt()]);
    T::same([false, 'pending_limit'], [$deny->allowed, $deny->reason], 'vorher: Deckel erreicht');
    T::same('ok', mdOutcome($w->mod->rejectTopic($gm, $t, 'Spam')), 'Thema abgelehnt');
    $w->access->flush();
    $allow = $w->access->can($s, 'create_topic', ['forum_id' => $f, 'now' => mdAt()]);
    T::same([true, 'pending'], [$allow->allowed, $allow->initialStatus], 'danach: wieder ein wartendes Thema möglich');
});

T::run('Ausblenden und Wiederherstellen: first_post, gelöscht durch Moderation ja, durch den Autor nein, Grund im Platzhalter', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $f = $w->db->forum();
    [$t, $p] = mdSeed($w, $f, $s, [[$a, 'published'], [$a, 'deleted'], [$a, 'deleted']]);
    $w->db->execute("UPDATE cms_community_posts SET status_reason = 'moderator' WHERE id = ?", [$p[2]]);
    $w->db->execute("UPDATE cms_community_posts SET status_reason = 'author' WHERE id = ?", [$p[3]]);
    $w->recount();
    $rule = $w->rule;
    T::same(['first_post', 'first_post'], [mdOutcome($w->mod->hidePost($mod, $p[0], 'Grund')), mdOutcome($w->mod->restorePost($mod, $p[0]))], 'Themenstart: first_post');
    $w->rule = static fn (?int $u, string $action): ?AccessDecision => in_array($action, ['post.hide', 'post.restore'], true) ? AccessDecision::deny('no_permission') : null;
    T::same(['no_permission', 'no_permission'], [mdOutcome($w->mod->hidePost($mod, $p[0], 'Grund')), mdOutcome($w->mod->restorePost($mod, $p[0]))], 'Rechte vor first_post');
    $w->rule = $rule;
    $hidden = $w->mod->hidePost($mod, $p[1], 'Beleidigung');
    T::same(['ok', 'hidden', 'Beleidigung', false], [mdOutcome($hidden), $w->post($p[1])['status'], $w->post($p[1])['status_reason'], $hidden['solution_cleared'] ?? null], 'ausgeblendet, Grund in status_reason (Platzhalter zeigt ihn)');
    T::same([Events::POST_HIDDEN], $w->hookNames(), 'Hook post_hidden');
    T::same([$p[1], $t, $mod, (int) $hidden['log_id']], $w->hooks[0]['args'], 'post_hidden: Beitrag, Thema, Akteur, Protokoll-ID');
    $w->hooks = [];
    $restored = $w->mod->restorePost($mod, $p[1]);
    T::same(['ok', 'published', null, [$p[1], $t, $mod, 'hidden']], [mdOutcome($restored), $w->post($p[1])['status'], $w->post($p[1])['status_reason'], $w->hooks[0]['args'] ?? null], 'wiederhergestellt, status_reason NULL, post_restored mit fromStatus');
    T::same(['ok', 'published'], [mdOutcome($w->mod->restorePost($mod, $p[2])), $w->post($p[2])['status']], 'von der Moderation gelöscht: wiederherstellbar');
    T::same(['invalid_state', 'deleted'], [mdOutcome($w->mod->restorePost($mod, $p[3])), $w->post($p[3])['status']], 'vom Autor gelöscht: invalid_state (Wille des Mitglieds)');
    T::same('invalid_state', mdOutcome($w->mod->approvePost($mod, $p[3])), 'Freigabe eines gelöschten Beitrags: invalid_state');
    $rows = $w->logRows();
    T::same([['post.hide', 'Beleidigung', ['from' => 'published', 'solution_cleared' => false]], ['post.restore', null, ['from' => 'hidden']], ['post.restore', null, ['from' => 'deleted']]],
        array_map(static fn (array $r): array => [$r['action'], $r['reason'], json_decode((string) $r['details_json'], true)], $rows), 'Protokoll mit Details');
    mdAudit($w, 'Ausblenden und Wiederherstellen');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Lösung
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Lösung: markieren, wechseln, aufheben; Ziel ungeeignet ⇒ invalid_target; Protokoll nur bei fremdem Thema; Invariante nach Ausblenden', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $b = $w->db->user();
    $f = $w->db->forum(['type' => 'qa']);
    [$t, $p] = mdSeed($w, $f, $s, [[$a, 'published'], [$b, 'published'], [$a, 'pending']]);
    [$other, $op] = mdSeed($w, $f, $s, [[$a, 'published']], ['at' => 500]);
    $w->recount();
    T::same(['invalid_target', 'invalid_target', 'invalid_target', 'invalid_target'], [
        mdOutcome($w->mod->markSolution($s, $t, $op[1])), mdOutcome($w->mod->markSolution($s, $t, $p[0])),
        mdOutcome($w->mod->markSolution($s, $t, $p[3])), mdOutcome($w->mod->markSolution($s, $t, 999999)),
    ], 'Beitrag eines anderen Themas, Themenstart, wartend, fehlend: einheitlich invalid_target');
    $w->rule = static fn (?int $u, string $action): ?AccessDecision => $action === 'mark_solution' ? AccessDecision::deny('no_permission') : null;
    T::same('no_permission', mdOutcome($w->mod->markSolution($b, $t, 999999)), 'Rechte vor invalid_target');
    $w->rule = null;

    $mark = $w->mod->markSolution($s, $t, $p[1]);
    T::same(['ok', null, $p[1], $s], [mdOutcome($mark), mdGet($mark, 'previous_post_id'), (int) $w->topic($t)['solution_post_id'], (int) $w->topic($t)['solved_by']], 'Themenstarter markiert die Lösung');
    T::same([mdUtc(), [0, 2, 1], []], [$w->topic($t)['solved_at'], $w->counts($a), $w->logRows()], 'solved_at = jetzt (UTC), Lösungsautor solutions + 1, kein Protokoll (eigenes Thema)');
    T::same([Events::SOLUTION_MARKED], $w->hookNames(), 'Hook solution_marked');
    $w->hooks = [];
    $change = $w->mod->markSolution($mod, $t, $p[2]);
    T::same(['ok', $p[1], [0, 2, 0], [0, 1, 1]], [mdOutcome($change), $change['previous_post_id'] ?? null, $w->counts($a), $w->counts($b)], 'Moderation wechselt die Lösung: alt − 1, neu + 1');
    T::same([[Events::SOLUTION_UNMARKED, [$t, $p[1], $mod]], [Events::SOLUTION_MARKED, [$t, $p[2], $mod]]], array_map(static fn (array $h): array => [$h['hook'], $h['args']], $w->hooks), 'erst solution_unmarked der alten, dann solution_marked der neuen');
    $rows = $w->logRows();
    T::same([['topic.solve', $mod, ['post' => $p[2], 'previous' => $p[1]]]], array_map(static fn (array $r): array => [$r['action'], (int) $r['actor_id'], json_decode((string) $r['details_json'], true)], $rows), 'Protokoll topic.solve nur bei fremdem Thema');
    mdAudit($w, 'Lösung gewechselt');

    $w->hooks = [];
    $hide = $w->mod->hidePost($mod, $p[2], 'Falsch');
    T::same(['ok', true, null, null, null, [0, 0, 0]], [mdOutcome($hide), $hide['solution_cleared'] ?? null, $w->topic($t)['solution_post_id'], $w->topic($t)['solved_at'], $w->topic($t)['solved_by'], $w->counts($b)], 'Ausblenden der Lösung hebt sie auf (alle drei Spalten), solutions − 1');
    T::same([Events::POST_HIDDEN, Events::SOLUTION_UNMARKED], $w->hookNames(), 'post_hidden, danach solution_unmarked');
    T::same(['from' => 'published', 'solution_cleared' => true], json_decode((string) $w->db->row('community_moderation_log', ['id' => (int) $hide['log_id']])['details_json'], true), 'Protokoll post.hide mit solution_cleared');
    mdAudit($w, 'Lösung ausgeblendet');
    $w->mod->restorePost($mod, $p[2]);
    T::same(null, $w->topic($t)['solution_post_id'], 'Wiederherstellen setzt die Lösung nicht erneut');

    $w->mod->markSolution($s, $t, $p[1]);
    $w->hooks = [];
    $unmark = $w->mod->unmarkSolution($s, $t);
    T::same(['ok', $p[1], [0, 2, 0], [Events::SOLUTION_UNMARKED]], [mdOutcome($unmark), $unmark['post_id'] ?? null, $w->counts($a), $w->hookNames()], 'Starter hebt die Lösung auf (ohne Protokoll)');
    T::same('unchanged', mdOutcome($w->mod->unmarkSolution($s, $t)), 'ohne Lösung: changed = false');
    $w->mod->markSolution($s, $t, $p[1]);
    $logs = count($w->logRows());
    $w->mod->unmarkSolution($mod, $t);
    $rows = $w->logRows();
    T::same([$logs + 1, 'topic.unsolve', ['post' => $p[1]]], [count($rows), end($rows)['action'], json_decode((string) end($rows)['details_json'], true)], 'Moderation hebt die Lösung eines fremden Themas auf: Protokoll topic.unsolve');
    mdAudit($w, 'Lösung aufgehoben');
});

T::run('Lösung (echte CommunityAccess): Starter im Q&A-Forum ja, im geschlossenen Thema topic_locked, Fremde nein; ausgeblendetes Thema bucht nicht', static function (): void {
    $w = new MdWorld([], ['*']);
    $gm = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $qa = $w->db->forum(['type' => 'qa']);
    $disc = $w->db->forum();
    [$t, $p] = mdSeed($w, $qa, $s, [[$a, 'published']]);
    [$d, $dp] = mdSeed($w, $disc, $s, [[$a, 'published']], ['at' => 100]);
    [$h, $hp] = mdSeed($w, $qa, $s, [[$a, 'published']], ['at' => 200]);
    $w->recount();
    T::same('no_permission', mdOutcome($w->mod->markSolution($a, $t, $p[1])), 'Fremder (nicht Starter): no_permission');
    T::same('no_permission', mdOutcome($w->mod->markSolution($s, $d, $dp[1])), 'Starter außerhalb von Q&A: no_permission');
    T::same('ok', mdOutcome($w->mod->markSolution($gm, $d, $dp[1])), 'Moderation auch außerhalb von Q&A');
    T::same('ok', mdOutcome($w->mod->lock($gm, $t)), 'Thema geschlossen');
    T::same('topic_locked', mdOutcome($w->mod->markSolution($s, $t, $p[1])), 'Starter im geschlossenen Thema: topic_locked');
    T::same('ok', mdOutcome($w->mod->unlock($gm, $t)), 'wieder geöffnet');
    T::same('ok', mdOutcome($w->mod->markSolution($s, $t, $p[1])), 'Starter im Q&A-Forum');
    T::same('ok', mdOutcome($w->mod->hideTopic($gm, $h, 'Grund')), 'anderes Thema ausgeblendet');
    $before = $w->counts($a);
    T::same(['invalid_state', $before], [mdOutcome($w->mod->markSolution($gm, $h, $hp[1])), $w->counts($a)], 'im ausgeblendeten Thema: invalid_state, nichts gebucht');
    mdAudit($w, 'Lösung mit echter CommunityAccess');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Massenaktion
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Massenaktion: Grenzen, je Element eigene Transaktion und Hooks, ein Fehler bricht nicht ab, Uhr einmal, Löschen über die Inhaltsdienste', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$t, $p] = mdSeed($w, $f1, $s, [[$a, 'pending'], [$a, 'pending'], [$a, 'pending'], [$a, 'published'], [$a, 'published']]);
    [$t2] = mdSeed($w, $f1, $s, [], ['at' => 600]);
    [$t3] = mdSeed($w, $f1, $s, [], ['at' => 700]);
    $w->recount();
    $from = count($w->db->log);
    T::same(['bulk_limit', 0, 0], [mdOutcome($w->mod->bulk($mod, 'post.approve', range(1, 101))), count($w->db->log) - $from, $w->clockReads], '101 IDs: bulk_limit ohne Anweisung und ohne Uhr');
    T::same(['invalid_input', 'invalid_input', 'invalid_input', 'invalid_input', 'invalid_input'], [
        mdOutcome($w->mod->bulk($mod, 'post.edit', [$p[1]])), mdOutcome($w->mod->bulk($mod, 'post.approve', [])),
        mdOutcome($w->mod->bulk($mod, 'post.approve', [$p[1], $p[1]])), mdOutcome($w->mod->bulk($mod, 'topic.move', [$t2])),
        mdOutcome($w->mod->bulk($mod, 'topic.pin', [$t2], ['until' => 'morgen'])),
    ], 'unbekannte Operation, leer, doppelt, topic.move ohne Ziel, ungültiger Parameter: invalid_input');

    // Das zweite Element scheitert an einer Datenbankanweisung, die anderen gelingen in eigenen Transaktionen.
    $count = 0;
    $arm = null;
    $arm = static function () use ($w, &$count, &$arm): void {
        $count++;
        $w->db->failOn = $count === 2 ? ['/^UPDATE cms_community_posts SET status/'] : [];
        $w->db->beforeExecute['/^SELECT \* FROM cms_community_posts WHERE id = \? LIMIT 1 FOR UPDATE$/'] = $arm;
    };
    $w->db->beforeExecute['/^SELECT \* FROM cms_community_posts WHERE id = \? LIMIT 1 FOR UPDATE$/'] = $arm;
    $reads = $w->clockReads;
    $result = $w->mod->bulk($mod, 'post.approve', [$p[1], $p[2], $p[3], $p[4]]);
    unset($w->db->beforeExecute['/^SELECT \* FROM cms_community_posts WHERE id = \? LIMIT 1 FOR UPDATE$/']);
    $w->db->failOn = [];
    T::same([true, 'post.approve', 3, 1, 1], [$result['success'] ?? null, $result['operation'] ?? null, $result['done'] ?? null, $result['failed'] ?? null, $w->clockReads - $reads], 'drei gelungen, eines gescheitert, eine Uhr');
    T::same([['id' => $p[1], 'success' => true, 'changed' => true], ['id' => $p[2], 'success' => false, 'error' => 'db_error'], ['id' => $p[3], 'success' => true, 'changed' => true], ['id' => $p[4], 'success' => true, 'changed' => false]], $result['items'] ?? null, 'Ergebnis je Element in Eingabereihenfolge (das vierte war schon veröffentlicht)');
    T::same([['published', 'pending', 'published'], [false]], [[$w->post($p[1])['status'], $w->post($p[2])['status'], $w->post($p[3])['status']], array_values(array_unique(array_column($w->hooks, 'in_transaction')))], 'das gescheiterte Element blieb wartend; Hooks nach dem Commit des jeweiligen Elements');
    T::same([[Events::POST_APPROVED, $p[1]], [Events::POST_APPROVED, $p[3]]], array_map(static fn (array $h): array => [$h['hook'], $h['args'][0]], $w->hooks), 'Hooks nur für die geänderten Elemente');
    mdAudit($w, 'Massenfreigabe mit Fehler');

    $w->hooks = [];
    $pins = $w->mod->bulk($mod, 'topic.pin', [$t2, $t3], ['until' => mdAt(3600), 'reason' => 'Wichtig']);
    $rows = $w->logRows();
    T::same([2, mdUtc(3600), 'Wichtig'], [$pins['done'] ?? null, $w->topic($t3)['pinned_until'], end($rows)['reason'] ?? null], 'topic.pin mit Frist und Grund');
    $moves = $w->mod->bulk($mod, 'topic.move', [$t2, $t3], ['target_forum_id' => $f2, 'stub' => false]);
    T::same([2, $f2, $f2, 0], [$moves['done'] ?? null, (int) $w->topic($t2)['forum_id'], (int) $w->topic($t3)['forum_id'], $w->db->count('community_topics', ['status' => 'moved'])], 'topic.move ohne Verweis');
    $w->hooks = [];
    $deleted = $w->mod->bulk($mod, 'post.delete', [$p[4], $p[5]], ['reason' => 'Aufräumen']);
    T::same([2, 'deleted', 'moderator', [Events::POST_DELETED, Events::POST_DELETED]], [$deleted['done'] ?? null, $w->post($p[5])['status'], $w->post($p[5])['status_reason'], $w->hookNames()], 'post.delete über PostService (eingefrorene Uhr, gleiche Closures)');
    $again = $w->mod->bulk($mod, 'post.delete', [$p[5]]);
    T::same([['id' => $p[5], 'success' => true, 'changed' => false]], $again['items'] ?? null, 'wiederholtes Löschen: changed = false');
    $topicDelete = $w->mod->bulk($mod, 'topic.delete', [$t2]);
    T::same([1, 'deleted'], [$topicDelete['done'] ?? null, $w->topic($t2)['status']], 'topic.delete über TopicService');
    T::same('reason_required', mdOutcome($w->mod->bulk($mod, 'topic.hide', [$t3])), 'Pflichtgrund einmal vorab');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Protokoll (M7)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Protokoll: Eintrag je Aktion ohne IP; Fehler beim Schreiben ⇒ db_error ohne Wirkung; Lesen je Bereich, Filter, Keyset', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$t, $p] = mdSeed($w, $f1, $s, [[$s, 'published'], [$s, 'published']]);
    [$t2] = mdSeed($w, $f2, $s, [], ['at' => 300]);
    $w->recount();
    $columns = array_column($w->db->execute('PRAGMA table_info(cms_community_moderation_log)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
    T::same(['id', 'actor_id', 'action', 'target_type', 'target_id', 'forum_id', 'reason', 'details_json', 'created_at'], $columns, 'Tabelle ohne IP und User-Agent');

    $snapshot = $w->snapshot();
    $w->db->failOn = ['/^INSERT INTO cms_community_moderation_log/'];
    $failed = $w->mod->hidePost($mod, $p[1], 'Grund');
    $w->db->failOn = [];
    T::same(['db_error', [], false], [mdOutcome($failed), $w->hooks, $w->db->getPdo()->inTransaction()], 'Protokoll scheitert: db_error, kein Hook, keine offene Transaktion');
    T::same($snapshot, $w->snapshot(), 'Zustand unverändert (die Rücknahme nimmt alles mit)');
    $ok = $w->mod->hidePost($mod, $p[1], 'Grund');
    $row = $w->db->row('community_moderation_log', ['id' => (int) $ok['log_id']]);
    T::same(['ok', $mod, 'post.hide', 'post', $p[1], $f1, 'Grund', mdUtc()], [mdOutcome($ok), (int) $row['actor_id'], $row['action'], $row['target_type'], (int) $row['target_id'], (int) $row['forum_id'], $row['reason'], $row['created_at']], 'Wiederholung gelingt; Eintrag mit Akteur, Ziel, Forum, Grund, Zeit (UTC)');

    $w->mod->lock($mod, $t2);
    $w->db->moderationLog(['action' => 'user.ban', 'target_type' => 'user', 'target' => $s, 'actor' => $mod, 'forum' => null]);
    $w->scopeRule = static fn (?int $u, array $c): array => $u === $mod ? ['global' => true, 'forum_ids' => [$f1, $f2]] : ['global' => false, 'forum_ids' => [$f1]];
    $fm = $w->db->user();
    $global = $w->mod->log($mod);
    $local = $w->mod->log($fm);
    T::same([3, ['post.hide']], [count($global['items'] ?? []), array_column($local['items'] ?? [], 'action')], 'global: alle Einträge (auch ohne Forum); Forum-Moderator: nur seine Foren, keine ohne Forum');
    T::same(['not_found', 'not_found'], [mdOutcome($w->mod->log($fm, ['forum_id' => $f2])), mdOutcome($w->mod->log($fm, ['forum_id' => 999999]))], 'Forum außerhalb des Bereichs und fehlendes Forum: not_found');
    T::same(['topic.lock'], array_column($w->mod->log($mod, ['forum_id' => $f2])['items'] ?? [], 'action'), 'Filter forum_id');
    T::same(['user.ban'], array_column($w->mod->log($mod, ['target_type' => 'user', 'target_id' => $s])['items'] ?? [], 'action'), 'Filter Ziel');
    T::same(['post.hide'], array_column($w->mod->log($mod, ['action' => 'post.hide', 'actor_id' => $mod, 'since' => mdUtc(), 'until' => mdUtc(1)])['items'] ?? [], 'action'), 'Filter Aktion, Akteur, Zeitraum');
    T::same(array_fill(0, 7, 'invalid_input'), [
        mdOutcome($w->mod->log($mod, ['bogus' => 1])), mdOutcome($w->mod->log($mod, ['action' => 'post.burn'])), mdOutcome($w->mod->log($mod, ['target_id' => 3])),
        mdOutcome($w->mod->log($mod, ['since' => '2026-02-30 10:00:00'])), mdOutcome($w->mod->log($mod, ['actor_id' => '7'])), mdOutcome($w->mod->log($mod, ['forum_id' => 0])),
        mdOutcome($w->mod->log($mod, ['target_type' => 'report'])),
    ], 'ungültige Filter: invalid_input (vor dem Rechte-Tor, ohne Datenbank)');
    $w->scopeRule = static fn (): array => ['global' => false, 'forum_ids' => []];
    T::same('no_permission', mdOutcome($w->mod->log($fm)), 'ohne Moderationsbereich: no_permission');
    $w->scopeRule = null;

    // Keyset mit gleichen Zeitstempeln: keine Lücke, keine Doppelung
    for ($i = 0; $i < 6; $i++) {
        $w->db->moderationLog(['action' => 'topic.unlock', 'target_type' => 'topic', 'target' => $t, 'actor' => $mod, 'forum' => $f1, 'created_at' => mdUtc(50)]);
    }
    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $page = $w->mod->log($mod, ['action' => 'topic.unlock'], $cursor, 4);
        $seen = array_merge($seen, array_column($page['items'], 'id'));
        $cursor = $page['next'];
        $pages++;
    } while ($cursor !== null && $pages < 5);
    T::same([6, 6, 2], [count($seen), count(array_unique($seen)), $pages], 'sechs Einträge mit gleicher Zeit in zwei Seiten zu je höchstens 4: alle genau einmal');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Warteschlange
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Warteschlange: älteste zuerst, Keyset, nur moderierte Foren, ohne ip_hash, ohne body_html, Zitate neutralisiert (ohne Renderer)', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $fm = $w->db->user();
    $s = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$secretTopic, $sp] = mdSeed($w, $f2, $s);
    $w->db->execute('UPDATE cms_community_posts SET body = ? WHERE id = ?', ['GEHEIMTEXT der Quelle', $sp[0]]);
    [$t1, $p1] = mdSeed($w, $f1, $s, [[$s, 'pending'], [$s, 'pending']], ['at' => 100]);
    [$t2, $p2] = mdSeed($w, $f2, $s, [[$s, 'pending']], ['at' => 50]);
    $quote = (string) QuoteService::format($sp[0], 'GEHEIMTEXT der Quelle');
    $w->db->execute("UPDATE cms_community_posts SET body = ?, body_html = '<p>GEHEIMTEXT im Cache</p>', rendered_version = 1, ip_hash = ? WHERE id = ?", [$quote . "\n\nMeine Antwort", str_repeat('a', 64), $p1[1]]);
    $w->recount();
    $w->scopeRule = static fn (?int $u): array => $u === $mod ? ['global' => true, 'forum_ids' => [$f1, $f2]] : ($u === $fm ? ['global' => false, 'forum_ids' => [$f1]] : ['global' => false, 'forum_ids' => []]);
    $all = $w->mod->queue($mod);
    T::same([[$p2[1], $p1[1], $p1[2]], false], [array_column($all['items'] ?? [], 'id'), $all['has_more'] ?? null], 'global: älteste zuerst über alle Foren');
    $json = (string) json_encode($all);
    T::same([false, false, false], [str_contains($json, 'GEHEIMTEXT'), str_contains($json, 'ip_hash'), str_contains($json, 'body_html')], 'kein Rohtext fremder Zitate, kein ip_hash, kein body_html');
    T::check(str_contains((string) $all['items'][1]['body'], QuoteService::HIDDEN_NOTICE) && str_contains((string) $all['items'][1]['body'], 'Meine Antwort'), 'ohne Renderer: Zitat als „nicht sichtbarer Bereich“ (fail-closed), eigener Text bleibt');
    T::same(['full', $p1[1], $t1, 'published'], [$all['items'][1]['view'], $all['items'][1]['id'], $all['items'][1]['topic_id'], $all['items'][1]['topic_status']], 'Einträge typisiert, mit Thema und Themenstatus');
    $first = $w->mod->queue($mod, null, 2);
    $second = $w->mod->queue($mod, $first['next'] ?? null, 2);
    T::same([[$p2[1], $p1[1]], true, [$p1[2]], false], [array_column($first['items'], 'id'), $first['has_more'], array_column($second['items'], 'id'), $second['has_more']], 'Keyset über zwei Seiten');
    T::same([$p1[1], $p1[2]], array_column($w->mod->queue($fm)['items'] ?? [], 'id'), 'Forum-Moderator: nur seine Foren');
    T::same([[$p2[1]], 'not_found', 'not_found', 'no_permission', 'invalid_input'], [
        array_column($w->mod->queue($mod, null, null, $f2)['items'] ?? [], 'id'), mdOutcome($w->mod->queue($fm, null, null, $f2)),
        mdOutcome($w->mod->queue($fm, null, null, 999999)), mdOutcome($w->mod->queue($s)), mdOutcome($w->mod->queue($mod, null, null, 0)),
    ], 'Filter Forum; außerhalb und fehlend: not_found; ohne Bereich: no_permission');
    T::same([['success' => true, 'count' => 3, 'oldest_at' => mdUtc(110)], ['success' => true, 'count' => 2, 'oldest_at' => mdUtc(160)], 'no_permission'],
        [$w->mod->queueSummary($mod), $w->mod->queueSummary($fm), mdOutcome($w->mod->queueSummary($s))], 'queueSummary: Anzahl und ältester je Bereich');
    T::same([false], array_values(array_unique(array_map(static fn (array $l): bool => isset($l['context']['now']), $w->scopeLog))), 'Lesemethoden fragen den Bereich ohne Uhr');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Ablauf befristeter Zustände (Cron)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Ablauf: Grenze einschließlich, verlängerte Frist bleibt, Verweise gelöscht, Forum-Version + 1, zweiter Lauf 0', static function (): void {
    $w = new MdWorld();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    $lockNow = $w->db->topic(['forum' => $f1, 'locked' => true, 'locked_until' => mdUtc(0)]);
    $lockLater = $w->db->topic(['forum' => $f1, 'locked' => true, 'locked_until' => mdUtc(1)]);
    $lockForever = $w->db->topic(['forum' => $f1, 'locked' => true]);
    $pinPast = $w->db->topic(['forum' => $f2, 'pinned' => true, 'pinned_until' => mdUtc(-10)]);
    $pinLater = $w->db->topic(['forum' => $f2, 'pinned' => true, 'pinned_until' => mdUtc(10)]);
    $stubNow = $w->db->topic(['forum' => $f2, 'status' => 'moved']);
    $w->db->execute('UPDATE cms_community_topics SET moved_expires_at = ? WHERE id = ?', [mdUtc(0), $stubNow]);
    $stubLater = $w->db->topic(['forum' => $f2, 'status' => 'moved']);
    $w->db->execute('UPDATE cms_community_topics SET moved_expires_at = ? WHERE id = ?', [mdUtc(60), $stubLater]);
    $w->recount();
    $versions = [(int) $w->forum($f1)['version'], (int) $w->forum($f2)['version']];
    $hooks = count($w->hooks);
    $result = $w->mod->expire();
    T::same(['success' => true, 'locks' => 1, 'pins' => 1, 'stubs' => 1], $result, 'je Art ein abgelaufener Zustand (Grenze einschließlich)');
    T::same([[0, null], [1, mdUtc(1)], [1, null], [0, null], [1, mdUtc(10)], null, 'moved'],
        [[(int) $w->topic($lockNow)['is_locked'], $w->topic($lockNow)['locked_until']], [(int) $w->topic($lockLater)['is_locked'], $w->topic($lockLater)['locked_until']], [(int) $w->topic($lockForever)['is_locked'], $w->topic($lockForever)['locked_until']],
         [(int) $w->topic($pinPast)['is_pinned'], $w->topic($pinPast)['pinned_until']], [(int) $w->topic($pinLater)['is_pinned'], $w->topic($pinLater)['pinned_until']], $w->topic($stubNow), $w->topic($stubLater)['status']],
        'abgelaufen aufgeräumt; künftige und unbefristete Zustände bleiben');
    T::same([$versions[0] + 1, $versions[1] + 1, $hooks, []], [(int) $w->forum($f1)['version'], (int) $w->forum($f2)['version'], count($w->hooks), $w->logRows()], 'Forum-Version + 1, kein Hook, kein Protokoll');
    T::same(['success' => true, 'locks' => 0, 'pins' => 0, 'stubs' => 0], $w->mod->expire(), 'zweiter Lauf: nichts mehr');
    $w->db->beforeExecute['/^SELECT id, forum_id FROM cms_community_topics WHERE is_locked = 1/'] = static function () use ($w, $lockLater): void {
        $w->db->execute('UPDATE cms_community_topics SET locked_until = ? WHERE id = ?', [mdUtc(9999), $lockLater]);
    };
    $w->at(5);
    $w->mod->expire();
    T::same([1, mdUtc(9999)], [(int) $w->topic($lockLater)['is_locked'], $w->topic($lockLater)['locked_until']], 'zwischen Auswahl und Aufräumen verlängert: die Frist bleibt (Bedingung erneut geprüft)');
    $w->db->failOn = ['/^UPDATE cms_community_topics SET is_pinned = 0/'];
    $w->at(20);
    T::same('db_error', mdOutcome($w->mod->expire()), 'Datenbankfehler: db_error, wirft nicht');
    $w->db->failOn = [];
    mdAudit($w, 'Ablauf');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Rücknahme
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Rücknahme: Fehler an Protokoll oder Zähleranweisung jeder Schreibmethode ⇒ Zustand gleich, kein Hook, keine offene Transaktion', static function (): void {
    $scenarios = [
        'lock' => [static fn (MdWorld $w, array $x) => $w->mod->lock($x['mod'], $x['t']), '/^UPDATE cms_community_forums SET topic_count/'],
        'pin' => [static fn (MdWorld $w, array $x) => $w->mod->pin($x['mod'], $x['t']), '/^UPDATE cms_community_topics SET is_pinned/'],
        'rename' => [static fn (MdWorld $w, array $x) => $w->mod->rename($x['mod'], $x['t'], 'Neuer Titel'), '/^UPDATE cms_community_forums SET topic_count/'],
        'approvePost' => [static fn (MdWorld $w, array $x) => $w->mod->approvePost($x['mod'], $x['p'][1]), '/^UPDATE cms_community_user_stats SET topic_count/'],
        'rejectPost' => [static fn (MdWorld $w, array $x) => $w->mod->rejectPost($x['mod'], $x['p'][1], 'Grund'), '/^UPDATE cms_community_posts SET status/'],
        'hidePost' => [static fn (MdWorld $w, array $x) => $w->mod->hidePost($x['mod'], $x['p'][2], 'Grund'), '/^UPDATE cms_community_topics SET solution_post_id/'],
        'restorePost' => [static fn (MdWorld $w, array $x) => $w->mod->restorePost($x['mod'], $x['p'][3]), '/^UPDATE cms_community_forums SET topic_count/'],
        'hideTopic' => [static fn (MdWorld $w, array $x) => $w->mod->hideTopic($x['mod'], $x['t'], 'Grund'), '/^UPDATE cms_community_user_stats SET topic_count/'],
        'approveTopic' => [static fn (MdWorld $w, array $x) => $w->mod->approveTopic($x['mod'], $x['pt']), '/^UPDATE cms_community_topics SET has_attachments/'],
        'rejectTopic' => [static fn (MdWorld $w, array $x) => $w->mod->rejectTopic($x['mod'], $x['pt'], 'Grund'), '/^UPDATE cms_community_topics SET status/'],
        'moveTopic' => [static fn (MdWorld $w, array $x) => $w->mod->moveTopic($x['mod'], $x['t'], $x['f2']), '/^INSERT INTO cms_community_topics/'],
        'movePosts' => [static fn (MdWorld $w, array $x) => $w->mod->movePosts($x['mod'], [$x['p'][2]], $x['dst']), '/^UPDATE cms_community_topics SET reaction_count/'],
        'markSolution' => [static fn (MdWorld $w, array $x) => $w->mod->markSolution($x['mod'], $x['t'], $x['p'][4]), '/^UPDATE cms_community_user_stats SET topic_count/'],
        'systemAction' => [static fn (MdWorld $w, array $x) => $w->mod->systemAction('hide', 'post', $x['p'][2], ['reports' => 3]), '/^UPDATE cms_community_forums SET topic_count/'],
    ];
    foreach ($scenarios as $name => [$call, $counter]) {
        foreach (['/^INSERT INTO cms_community_moderation_log/' => 'Protokoll', $counter => 'Zähler/Zustand'] as $pattern => $label) {
            $w = new MdWorld();
            $x = ['mod' => $w->db->user(['role' => 'moderator']), 's' => $w->db->user(), 'a' => $w->db->user(), 'f1' => $w->db->forum(), 'f2' => $w->db->forum()];
            [$x['t'], $x['p']] = mdSeed($w, $x['f1'], $x['s'], [[$x['a'], 'pending'], [$x['a'], 'published'], [$x['a'], 'hidden'], [$x['s'], 'published']]);
            [$x['pt'], $x['pp']] = mdSeed($w, $x['f1'], $x['s'], [], ['status' => 'pending', 'first' => 'pending', 'at' => 500]);
            [$x['dst']] = mdSeed($w, $x['f2'], $x['s'], [], ['at' => 600]);
            mdSolve($w, $x['t'], $x['p'][2], $x['s']);
            $w->db->attachment(['post' => $x['pp'][0], 'user' => $x['s']]);
            $w->db->reaction(['post' => $x['p'][2], 'user' => $x['s']]);
            $w->recount();
            $snapshot = $w->snapshot();
            $w->db->failOn = [$pattern];
            $from = count($w->db->log);
            $result = $call($w, $x);
            $w->db->failOn = [];
            $hit = array_filter(array_slice($w->db->log, $from), static fn (string $sql): bool => preg_match($pattern, $sql) === 1) !== [];
            T::same([true, 'db_error', [], false], [$hit, mdOutcome($result), $w->hookNames(), $w->db->getPdo()->inTransaction()], $name . ' (' . $label . '): db_error, kein Hook, keine offene Transaktion');
            T::same($snapshot, $w->snapshot(), $name . ' (' . $label . '): Zustand unverändert');
            T::same('ok', mdOutcome($call($w, $x)), $name . ' (' . $label . '): Wiederholung gelingt');
            T::same([], $w->audit(), $name . ' (' . $label . '): Orakel nach der Wiederholung');
        }
    }
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Systemaktionen (Meldeschwelle, sanktionen §1.7)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('systemAction: Ausblenden mit Akteur 0 und Details, Themenstart blendet das Thema aus, andere Zustände changed = false, kein can()', static function (): void {
    $w = new MdWorld();
    $s = $w->db->user();
    $a = $w->db->user();
    $f = $w->db->forum();
    [$t, $p] = mdSeed($w, $f, $s, [[$a, 'published'], [$a, 'pending'], [$a, 'published']]);
    [$t2, $p2] = mdSeed($w, $f, $s, [[$a, 'published']], ['at' => 500]);
    mdSolve($w, $t, $p[1], $s);
    $w->recount();
    $result = $w->mod->systemAction('hide', 'post', $p[1], ['reports' => 3]);
    T::same(['success' => true, 'changed' => true], $result, 'Antwort automatisch ausgeblendet; Ergebnis nur success und changed');
    T::same(['hidden', 'reports', null], [$w->post($p[1])['status'], $w->post($p[1])['status_reason'], $w->topic($t)['solution_post_id']], 'status_reason = reports, Lösung aufgehoben');
    $rows = $w->logRows();
    $row = end($rows);
    T::same([null, 'post.hide', $f, ['from' => 'published', 'solution_cleared' => true, 'auto' => 'reports', 'reports' => 3]], [$row['actor_id'], $row['action'], (int) $row['forum_id'], json_decode((string) $row['details_json'], true)], 'Protokoll mit actor_id NULL und details {auto, reports}');
    T::same([[Events::POST_HIDDEN, [$p[1], $t, 0, (int) $row['id']]], [Events::SOLUTION_UNMARKED, [$t, $p[1], 0]]], array_map(static fn (array $h): array => [$h['hook'], $h['args']], $w->hooks), 'Hooks mit Akteur 0');
    T::same([], $w->canLog, 'kein can() für den System-Akteur');
    mdAudit($w, 'automatisch ausgeblendet');

    $w->hooks = [];
    T::same([['success' => true, 'changed' => false], ['success' => true, 'changed' => false], ['success' => true, 'changed' => false]],
        [$w->mod->systemAction('hide', 'post', $p[1], ['reports' => 4]), $w->mod->systemAction('hide', 'post', $p[2]), $w->mod->systemAction('restore', 'post', $p[3])],
        'schon ausgeblendet, wartend, veröffentlicht (restore): changed = false');
    $topic = $w->mod->systemAction('hide', 'post', $p2[0], ['reports' => 5]);
    $rows = $w->logRows();
    T::same([['success' => true, 'changed' => true], 'hidden', 'topic.hide', ['from' => 'published', 'auto' => 'reports', 'reports' => 5]], [$topic, $w->topic($t2)['status'], end($rows)['action'], json_decode((string) end($rows)['details_json'], true)], 'Themenstart: das Thema wird ausgeblendet (wie topic.hide)');
    T::same([Events::TOPIC_HIDDEN, [$t2, $f, 0, (int) end($rows)['id']]], [$w->hooks[0]['hook'], $w->hooks[0]['args']], 'topic_hidden mit Akteur 0');
    mdAudit($w, 'Thema automatisch ausgeblendet');
    T::same([['success' => false, 'changed' => false, 'error' => 'invalid_input'], ['success' => false, 'changed' => false, 'error' => 'invalid_input'], ['success' => false, 'changed' => false, 'error' => 'invalid_input'], ['success' => false, 'changed' => false, 'error' => 'invalid_input'], ['success' => false, 'changed' => false, 'error' => 'post_not_found']],
        [$w->mod->systemAction('delete', 'post', $p[3]), $w->mod->systemAction('hide', 'user', $s), $w->mod->systemAction('hide', 'post', 0), $w->mod->systemAction('hide', 'post', $p[3], ['reports' => '3']), $w->mod->systemAction('hide', 'post', 999999)],
        'ungültige Operation, Zielart, ID, Details; fehlender Beitrag');
    $w->db->getPdo()->beginTransaction();
    $inside = $w->mod->systemAction('hide', 'post', $p[3]);
    $w->db->getPdo()->rollBack();
    T::same([['success' => false, 'changed' => false, 'error' => 'db_error'], 'published'], [$inside, $w->post($p[3])['status']], 'innerhalb einer Transaktion abgelehnt (Hooks liefen sonst vor dem Commit)');
});

T::run('systemAction: Wiederherstellen nur nach automatischem Ausblenden (jüngster hide-Eintrag vom System mit auto = reports)', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $a = $w->db->user();
    $f = $w->db->forum();
    [$t, $p] = mdSeed($w, $f, $s, [[$a, 'published'], [$a, 'published'], [$a, 'published']]);
    [$t2, $p2] = mdSeed($w, $f, $s, [], ['at' => 500]);
    $w->recount();
    // 1: automatisch ausgeblendet ⇒ wiederhergestellt
    $w->mod->systemAction('hide', 'post', $p[1], ['reports' => 3]);
    $w->hooks = [];
    T::same(['success' => true, 'changed' => true], $w->mod->systemAction('restore', 'post', $p[1]), 'automatisch ausgeblendet: wiederhergestellt');
    $rows = $w->logRows();
    T::same(['published', null, 'post.restore', null, ['from' => 'hidden', 'auto' => 'reports_invalid'], [Events::POST_RESTORED, [$p[1], $t, 0, 'hidden']]],
        [$w->post($p[1])['status'], $w->post($p[1])['status_reason'], end($rows)['action'], end($rows)['actor_id'], json_decode((string) end($rows)['details_json'], true), [$w->hooks[0]['hook'], $w->hooks[0]['args']]],
        'post.restore mit auto = reports_invalid, Hook mit Akteur 0');
    // 2: von der Moderation ausgeblendet ⇒ bleibt
    $w->mod->hidePost($mod, $p[2], 'Moderation');
    T::same([['success' => true, 'changed' => false], 'hidden'], [$w->mod->systemAction('restore', 'post', $p[2]), $w->post($p[2])['status']], 'von der Moderation ausgeblendet: bleibt ausgeblendet');
    // 3: automatisch, danach von der Moderation erneut ausgeblendet ⇒ der jüngste Eintrag zählt
    $w->mod->systemAction('hide', 'post', $p[3], ['reports' => 3]);
    $w->mod->restorePost($mod, $p[3]);
    $w->mod->hidePost($mod, $p[3], 'Doch ausblenden');
    T::same([['success' => true, 'changed' => false], 'hidden'], [$w->mod->systemAction('restore', 'post', $p[3]), $w->post($p[3])['status']], 'jüngster hide-Eintrag von der Moderation: bleibt');
    // 4: Thema
    $w->mod->systemAction('hide', 'topic', $t2, ['reports' => 3]);
    T::same([['success' => true, 'changed' => true], 'published'], [$w->mod->systemAction('restore', 'topic', $t2), $w->topic($t2)['status']], 'Thema: automatisch ausgeblendet, wiederhergestellt');
    $w->mod->hideTopic($mod, $t2, 'Moderation');
    T::same([['success' => true, 'changed' => false], 'hidden'], [$w->mod->systemAction('restore', 'post', $p2[0]), $w->topic($t2)['status']], 'Thema von der Moderation ausgeblendet: Wiederherstellen über den Themenstart ändert nichts');
    // Fremde Einträge vom System ohne auto zählen nicht
    $w->db->moderationLog(['action' => 'post.hide', 'target' => $p[2], 'actor' => null, 'details' => ['from' => 'published'], 'created_at' => mdUtc(1)]);
    T::same(['success' => true, 'changed' => false], $w->mod->systemAction('restore', 'post', $p[2]), 'Systemeintrag ohne auto = reports: bleibt');
    mdAudit($w, 'automatisches Wiederherstellen');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// ip_hash für die Moderation (G15)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('ipHashes: nur mit view_private_data, nur Beiträge im Moderationsbereich, Eingabegrenzen; andere APIs ohne ip_hash', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $fm = $w->db->user();
    $s = $w->db->user();
    $f1 = $w->db->forum();
    $f2 = $w->db->forum();
    [$t1, $p1] = mdSeed($w, $f1, $s, [[$s, 'published']]);
    [$t2, $p2] = mdSeed($w, $f2, $s, [], ['at' => 100]);
    $w->db->execute('UPDATE cms_community_posts SET ip_hash = ? WHERE id = ?', [str_repeat('a', 64), $p1[0]]);
    $w->db->execute('UPDATE cms_community_posts SET ip_hash = ? WHERE id = ?', [str_repeat('b', 64), $p2[0]]);
    $w->recount();
    $w->scopeRule = static fn (?int $u): array => $u === $mod ? ['global' => true, 'forum_ids' => [$f1, $f2]] : ['global' => false, 'forum_ids' => [$f1]];
    $w->rule = static fn (?int $u, string $action): ?AccessDecision => $action === 'view_private_data' && $u !== $mod && $u !== $fm ? AccessDecision::deny('no_capability') : null;
    T::same(['success' => true, 'items' => [$p1[0] => str_repeat('a', 64), $p1[1] => null, $p2[0] => str_repeat('b', 64)]], $w->mod->ipHashes($mod, [$p2[0], $p1[1], $p1[0], 999999]), 'global: alle vorhandenen Beiträge, fehlende fehlen');
    T::same(['success' => true, 'items' => [$p1[0] => str_repeat('a', 64)]], $w->mod->ipHashes($fm, [$p1[0], $p2[0]]), 'Forum-Moderator: Beiträge fremder Foren fehlen wie fehlende');
    $from = count($w->db->log);
    T::same(['no_capability', 0], [mdOutcome($w->mod->ipHashes($s, [$p1[0]])), count($w->db->log) - $from], 'ohne Capability: Grund von can(), keine Abfrage');
    T::same(['view_private_data', ['now' => mdAt()]], [$w->canLog[count($w->canLog) - 1]['action'], $w->canLog[count($w->canLog) - 1]['context']], 'can() mit view_private_data und Uhr');
    T::same(array_fill(0, 4, 'invalid_input'), [mdOutcome($w->mod->ipHashes($mod, [])), mdOutcome($w->mod->ipHashes($mod, range(1, 101))), mdOutcome($w->mod->ipHashes($mod, ['1'])), mdOutcome($w->mod->ipHashes($mod, [$p1[0], $p1[0]]))], 'leer, mehr als 100, kein int, doppelt: invalid_input');
    $w->db->execute("UPDATE cms_community_posts SET status = 'pending' WHERE id = ?", [$p1[1]]);
    $w->recount();
    $queue = (string) json_encode($w->mod->queue($mod));
    T::same([false, false], [str_contains($queue, 'ip_hash'), str_contains($queue, str_repeat('a', 64))], 'Warteschlange ohne ip_hash');
    $reads = array_values(array_filter($w->db->log, static fn (string $sql): bool => str_contains($sql, 'ip_hash') && str_starts_with($sql, 'SELECT')));
    T::same(['SELECT id, ip_hash FROM cms_community_posts WHERE id IN (?, ?, ?) ORDER BY id ASC', 'SELECT id, ip_hash FROM cms_community_posts WHERE id IN (?) ORDER BY id ASC'],
        array_values(array_unique(array_filter($reads, static fn (string $sql): bool => str_contains($sql, 'id, ip_hash')))), 'einzige Lesestelle: PostRepository::ipHashes()');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Ende-zu-Ende mit echter CommunityAccess; 404-Regel (E3)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Ende-zu-Ende (echte CommunityAccess): Forum-Moderator, globale Moderation, Admin, Mitglied, Schreibsperre, unbestätigte Adresse', static function (): void {
    $fm = 0;
    $w = new MdWorld([], ['*']);
    $gm = $w->db->user(['role' => 'moderator']);
    $ad = $w->db->user(['role' => 'admin']);
    $fm = $w->db->user();
    $me = $w->db->user();
    $bw = $w->db->user(['role' => 'moderator']);
    $uv = $w->db->user(['role' => 'moderator']);
    $s = $w->db->user();
    $verified = [$gm, $ad, $fm, $me, $bw, $s];
    $w->access = new CommunityAccess(
        $w->db,
        $w->settings,
        static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, MD_ROLE_CAPS[$role] ?? [], true),
        static fn (): bool => true,
        static fn (): int => mdAt(),
        static fn (int $userId): bool => in_array($userId, $verified, true)
    );
    $w->db->ban(['user' => $bw, 'scope' => 'write']);
    $f = $w->db->forum();
    $other = $w->db->forum();
    mdModerator($w, $f, $fm);
    mdModerator($w, $other, $fm);
    $actors = ['FM' => $fm, 'GM' => $gm, 'AD' => $ad, 'ME' => $me, 'BW' => $bw, 'UV' => $uv];
    $groups = ['hidePost' => [], 'lock' => [], 'announce' => [], 'hideTopic' => [], 'moveTopic' => [], 'markSolution' => []];
    $queue = [];
    $round = 0;
    foreach ($actors as $label => $actor) {
        [$t, $p] = mdSeed($w, $f, $s, [[$s, 'published'], [$s, 'published']], ['at' => $round * 100]);
        [$t2] = mdSeed($w, $f, $s, [], ['at' => $round * 100 + 50]);
        [$t3] = mdSeed($w, $f, $s, [], ['at' => $round * 100 + 60]);
        $round++;
        $w->recount();
        $groups['hidePost'][$label] = mdOutcome($w->mod->hidePost($actor, $p[1], 'Grund'));
        $groups['lock'][$label] = mdOutcome($w->mod->lock($actor, $t));
        $groups['announce'][$label] = mdOutcome($w->mod->announce($actor, $t));
        $groups['hideTopic'][$label] = mdOutcome($w->mod->hideTopic($actor, $t2, 'Grund'));
        $groups['moveTopic'][$label] = mdOutcome($w->mod->moveTopic($actor, $t3, $other));
        $groups['markSolution'][$label] = mdOutcome($w->mod->markSolution($actor, $t, $p[2]));
        $queue[$label] = mdOutcome($w->mod->queue($actor));
    }
    $expected = ['FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok', 'ME' => 'no_permission', 'BW' => 'banned_write', 'UV' => 'email_unverified'];
    foreach (['hidePost', 'lock', 'hideTopic', 'moveTopic', 'markSolution'] as $group) {
        T::same($expected, $groups[$group], $group . ' je Rolle');
    }
    T::same(['FM' => 'no_permission'] + $expected, $groups['announce'], 'announce nur global');
    T::same(['FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok', 'ME' => 'no_permission', 'BW' => 'no_permission', 'UV' => 'no_permission'], $queue, 'Warteschlange: Bereich leer für Mitglied, Gesperrte und Unbestätigte');
    $fmScope = $w->access->moderationScope($fm, []);
    T::same([false, [$f, $other]], [$fmScope['global'], $fmScope['forum_ids']], 'Forum-Moderator: Bereich = seine Foren');
    T::same([true, [$f, $other]], array_values($w->access->moderationScope($gm, [])), 'globale Moderation: alle Foren');
    mdAudit($w, 'Ende-zu-Ende');
});

T::run('404-Regel: fehlend und unsichtbar ergeben für Unberechtigte dasselbe (not_found), je Methode', static function (): void {
    $w = new MdWorld([], ['*']);
    $me = $w->db->user();
    $fm = $w->db->user();
    $s = $w->db->user();
    $open = $w->db->forum();
    $hidden = $w->db->forum(['status' => 'hidden']);
    $mine = $w->db->forum();
    mdModerator($w, $mine, $fm);
    [$ht, $hp] = mdSeed($w, $hidden, $s, [[$s, 'published'], [$s, 'pending']]);
    [$ot] = mdSeed($w, $open, $s, [[$s, 'published']], ['at' => 100]);
    $trash = $w->db->topic(['forum' => $mine, 'status' => 'trash']);
    $w->recount();
    $missingTopic = 999999;
    $missingPost = 999998;
    $methods = [
        'lock' => static fn (int $u, int $t, int $p): array => $w->mod->lock($u, $t),
        'unlock' => static fn (int $u, int $t, int $p): array => $w->mod->unlock($u, $t),
        'pin' => static fn (int $u, int $t, int $p): array => $w->mod->pin($u, $t),
        'unpin' => static fn (int $u, int $t, int $p): array => $w->mod->unpin($u, $t),
        'announce' => static fn (int $u, int $t, int $p): array => $w->mod->announce($u, $t),
        'unannounce' => static fn (int $u, int $t, int $p): array => $w->mod->unannounce($u, $t),
        'rename' => static fn (int $u, int $t, int $p): array => $w->mod->rename($u, $t, 'Ein neuer Titel'),
        'moveTopic' => static fn (int $u, int $t, int $p): array => $w->mod->moveTopic($u, $t, $open),
        'hideTopic' => static fn (int $u, int $t, int $p): array => $w->mod->hideTopic($u, $t, 'Grund'),
        'restoreTopic' => static fn (int $u, int $t, int $p): array => $w->mod->restoreTopic($u, $t),
        'approveTopic' => static fn (int $u, int $t, int $p): array => $w->mod->approveTopic($u, $t),
        'rejectTopic' => static fn (int $u, int $t, int $p): array => $w->mod->rejectTopic($u, $t, 'Grund'),
        'approvePost' => static fn (int $u, int $t, int $p): array => $w->mod->approvePost($u, $p),
        'rejectPost' => static fn (int $u, int $t, int $p): array => $w->mod->rejectPost($u, $p, 'Grund'),
        'hidePost' => static fn (int $u, int $t, int $p): array => $w->mod->hidePost($u, $p, 'Grund'),
        'restorePost' => static fn (int $u, int $t, int $p): array => $w->mod->restorePost($u, $p),
        'movePosts' => static fn (int $u, int $t, int $p): array => $w->mod->movePosts($u, [$p], $ot),
        'markSolution' => static fn (int $u, int $t, int $p): array => $w->mod->markSolution($u, $t, $p),
        'unmarkSolution' => static fn (int $u, int $t, int $p): array => $w->mod->unmarkSolution($u, $t),
    ];
    $snapshot = $w->snapshot();
    foreach (['Mitglied' => $me, 'Forum-Moderator eines anderen Forums' => $fm] as $label => $actor) {
        $results = [];
        foreach ($methods as $name => $call) {
            $results[$name] = [mdOutcome($call($actor, $missingTopic, $missingPost)), mdOutcome($call($actor, $ht, $hp[1]))];
        }
        T::same(array_fill_keys(array_keys($methods), ['not_found', 'not_found']), $results, $label . ': fehlendes und unsichtbares Ziel ergeben not_found');
    }
    T::same(['not_found', 'not_found'], [mdOutcome($w->mod->lock($fm, $trash)), mdOutcome($w->mod->lock($fm, $missingTopic))], 'Thema im Papierkorb (nur admin sieht es): wie fehlend');
    T::same(['not_found', 'not_found'], [mdOutcome($w->mod->movePosts($fm, [$hp[1], $missingPost], $ot)), mdOutcome($w->mod->movePosts($fm, [$hp[1]], $missingTopic))], 'movePosts: fehlender Beitrag in der Liste und fehlendes Zielthema: not_found');
    T::same(['no_permission', 'no_permission'], [mdOutcome($w->mod->lock($me, $ot)), mdOutcome($w->mod->lock($fm, $ot))], 'sichtbares, nicht moderiertes Ziel: no_permission (das Thema ist ohnehin sichtbar)');
    T::same(['not_found', 'not_found', 'not_found', 'not_found'], [mdOutcome($w->mod->queue($fm, null, null, $hidden)), mdOutcome($w->mod->queue($fm, null, null, 999999)), mdOutcome($w->mod->log($fm, ['forum_id' => $hidden])), mdOutcome($w->mod->log($fm, ['forum_id' => 999999]))], 'Warteschlange und Protokoll: Forum außerhalb des Bereichs wie fehlendes Forum');
    T::same($snapshot, $w->snapshot(), 'keine Ablehnung schreibt etwas');
});

// Teildateien weiterer Besitzer (T: part-services.php in W3; Vertrag §1 Nr. 6).
foreach (glob(__DIR__ . '/part-*.php') ?: [] as $part) {
    require $part;
}

T::finish();
