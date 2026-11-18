<?php
/**
 * Community-Dienste für Inhalte (PR #2a): Themen anlegen und löschen, Antworten, Bearbeiten mit Verlauf, weiches Löschen,
 * Zähler nach jedem Schritt (Abgleich mit Neuberechnung per COUNT), doppeltes Absenden, Konflikte, Position und Seite,
 * Keyset-Listen, Lesestatus, Zitate, Rollback bei Fehlerinjektion, Hooks nach dem Commit, Sperrreihenfolge, dazu ein
 * Quelltext-Wächter über `CMS/core/Services/Community/**` (Vertrag §1) und ein Wirkungstest je Einstellung der Dienste.
 *
 * DB-frei: SQLite-Double (`TESTS/lib/CommunityTestDatabase.php`, Tabellen aus den echten CREATE-Texten), die Rechteprüfung
 * (`can`) ist eine Closure. Läuft in der CI ohne Datenbankserver; Wettlauf und echte Sperren prüft `TESTS/community-db`.
 *
 *   php TESTS/community-content/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

// PHP-Zone der Suite: Europe/Berlin, damit jede Verwendung von date() statt gmdate() auffällt (Entwurfsvertrag §6).
date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/lib/CommunityTestDatabase.php';

use CMS\Hooks;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\PostService;
use CMS\Services\Community\Service\QuoteService;
use CMS\Services\Community\Service\ReadStateService;
use CMS\Services\Community\Service\TopicService;
use CMS\Services\Community\Support\Codes;
use CMS\Services\Community\Support\Cursor;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\Settings;

T::$verbose = in_array('-v', $argv ?? [], true);

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Hilfen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Unix-Zeit der Suite: 2026-11-15 10:00:00 UTC plus Sekunden. */
function ccAt(int $seconds = 0): int
{
    return gmmktime(10, 0, 0, 11, 15, 2026) + $seconds;
}

/** UTC-Text der Suite-Zeit. */
function ccUtc(int $seconds = 0): string
{
    return gmdate('Y-m-d H:i:s', ccAt($seconds));
}

/**
 * Testumgebung: SQLite-Double, steuerbare Uhr, Rechteprüfung als Closure (mit Protokoll), Hook-Protokoll und alle Dienste.
 */
final class CcWorld
{
    public CommunityTestDatabase $db;
    public \ArrayObject $time;
    /** @var list<array{user: ?int, action: string, context: array<string, mixed>, queries: int}> */
    public array $canLog = [];
    /** @var list<array{hook: string, args: list<mixed>, in_transaction: bool}> */
    public array $hooks = [];
    public int $clockReads = 0;
    /** @var (\Closure(?int, string, array<string, mixed>): ?AccessDecision)|null null oder Rückgabe null = erlaubt */
    public ?\Closure $rule;
    public bool $hookThrows = false;
    /** Ob `can('moderate')` erlaubt ist (Standard: nein; Moderationsfälle setzen es). */
    public bool $moderator = false;
    public Settings $settings;
    public TopicService $topics;
    public PostService $posts;
    public QuoteService $quotes;
    public ReadStateService $reads;

    /**
     * @param array<string, mixed> $settings Rohwerte für Settings::fromArray()
     * @param (\Closure(?int, string, array<string, mixed>): ?AccessDecision)|null $rule
     */
    public function __construct(array $settings = [], ?\Closure $rule = null)
    {
        $this->db = CommunityTestDatabase::create();
        $this->time = new \ArrayObject(['now' => ccAt()]);
        $this->rule = $rule;
        $this->settings = Settings::fromArray($settings);

        $clock = function (): int {
            $this->clockReads++;

            return (int) $this->time['now'];
        };
        $can = function (?int $userId, string $action, array $context): AccessDecision {
            $this->canLog[] = ['user' => $userId, 'action' => $action, 'context' => $context, 'queries' => count($this->db->log)];

            $decision = $this->rule !== null ? ($this->rule)($userId, $action, $context) : null;
            if ($decision === null && $action === 'moderate' && !$this->moderator) {
                $decision = AccessDecision::deny('no_permission');
            }

            return $decision ?? AccessDecision::allow();
        };
        $dispatch = function (string $hook, mixed ...$args): void {
            $this->hooks[] = ['hook' => $hook, 'args' => $args, 'in_transaction' => $this->db->getPdo()->inTransaction()];
            if ($this->hookThrows) {
                throw new \RuntimeException('Testausnahme im Hook');
            }
        };
        $this->topics = new TopicService($this->db, $this->settings, $clock, $can, $dispatch);
        $this->posts = new PostService($this->db, $this->settings, $clock, $can, $dispatch);
        $this->quotes = new QuoteService($this->db, $this->settings, $clock, $can);
        $this->reads = new ReadStateService($this->db, $this->settings, $clock, $can);
    }

    /** Setzt die Uhr auf `ccAt($seconds)`. */
    public function at(int $seconds): void
    {
        $this->time['now'] = ccAt($seconds);
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

    /** @return list<string> Namen der Hooks in Auslösereihenfolge */
    public function hookNames(): array
    {
        return array_map(static fn (array $h): string => $h['hook'], $this->hooks);
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->db->execute($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** Alle `FOR UPDATE`-Abfragen des Protokolls ab Index `$from` als Tabellennamen (ohne Präfix). */
    public function lockOrder(int $from = 0): array
    {
        $tables = [];
        foreach (array_slice($this->db->log, $from) as $sql) {
            if (str_contains($sql, ' FOR UPDATE') && preg_match('/FROM cms_(\w+)/', $sql, $m) === 1) {
                $tables[] = str_replace('community_', '', $m[1]);
            }
        }

        return $tables;
    }
}

/** Wert eines Schlüssels oder die Markierung „fehlt“ (unterscheidet fehlend von null). */
function ccGet(array $array, string $key): mixed
{
    return array_key_exists($key, $array) ? $array[$key] : '<fehlt>';
}

/** Rechtenprüfung, die nur bestimmte Aktionen ablehnt. */
function ccDenyActions(array $actions, string $reason, ?int $retryAfter = null): \Closure
{
    return static fn (?int $userId, string $action, array $context): ?AccessDecision => in_array($action, $actions, true) ? AccessDecision::deny($reason, $retryAfter) : null;
}

/** Rechtenprüfung, die für Anlegen und Antworten „Freigabe nötig“ meldet. */
function ccPending(): \Closure
{
    return static fn (?int $userId, string $action, array $context): ?AccessDecision => in_array($action, ['create_topic', 'reply'], true) ? AccessDecision::allow('pending') : null;
}

/**
 * Thema mit `$count` veröffentlichten Beiträgen (der erste ist der Themenstart), je 60 Sekunden Abstand, direkt über die
 * Fixtures geschrieben (unabhängig von den Diensten). Setzt Themenzähler und „letzter Beitrag“.
 *
 * @param array<string, mixed> $o `authors` (Liste der Autoren je Beitrag), `title`, `status`
 * @return array{0: int, 1: list<int>} Thema-ID und Beitrags-IDs
 */
function ccSeedTopic(CcWorld $w, int $forum, int $user, int $count, int $at = 0, array $o = []): array
{
    $topic = $w->db->topic(['forum' => $forum, 'user' => $user, 'created_at' => ccUtc($at), 'status' => $o['status'] ?? 'published'] + (isset($o['title']) ? ['title' => $o['title']] : []));
    $ids = [];
    $authors = $o['authors'] ?? [];
    for ($i = 0; $i < $count; $i++) {
        $ids[] = $w->db->post(['topic' => $topic, 'user' => $authors[$i] ?? $user, 'first' => $i === 0, 'created_at' => ccUtc($at + $i * 60), 'body' => 'Beitrag ' . ($i + 1) . ' in Thema ' . $topic]);
    }
    $lastAt = ccUtc($at + max(0, $count - 1) * 60);
    $w->db->execute(
        'UPDATE cms_community_topics SET first_post_id = ?, last_post_id = ?, last_post_at = ?, last_poster_id = ?, reply_count = ? WHERE id = ?',
        [$ids[0] ?? null, $ids[$count - 1] ?? null, $lastAt, $authors[$count - 1] ?? $user, max(0, $count - 1), $topic]
    );

    return [$topic, $ids];
}

/**
 * Neuberechnung aller Zähler per COUNT und Abgleich mit den gespeicherten Werten (Tabelle §3.6).
 *
 * Forum: `topic_count` = veröffentlichte Themen, `post_count` = veröffentlichte Beiträge in veröffentlichten Themen,
 * `last_*` = das veröffentlichte Thema mit der jüngsten Aktivität. Thema: `reply_count` = veröffentlichte Antworten,
 * `last_*` = der jüngste veröffentlichte Beitrag. Mitglied (mit `$users`): `topic_count` und `post_count` wie beim Forum.
 *
 * @return list<string> Abweichungen, leer = alles stimmt
 */
function ccAudit(CcWorld $w, bool $users = true): array
{
    $bad = [];
    foreach ($w->db->rows('community_forums') as $f) {
        $id = (int) $f['id'];
        $topics = $w->db->count('community_topics', ['forum_id' => $id, 'status' => 'published']);
        $posts = (int) $w->scalar(
            "SELECT COUNT(*) FROM cms_community_posts p JOIN cms_community_topics t ON t.id = p.topic_id WHERE p.forum_id = ? AND p.status = 'published' AND t.status = 'published'",
            [$id]
        );
        if ((int) $f['topic_count'] !== $topics) {
            $bad[] = "Forum {$id}: topic_count {$f['topic_count']} statt {$topics}";
        }
        if ((int) $f['post_count'] !== $posts) {
            $bad[] = "Forum {$id}: post_count {$f['post_count']} statt {$posts}";
        }
        $last = $w->db->execute(
            "SELECT id, last_post_id, last_post_at, last_poster_id FROM cms_community_topics WHERE forum_id = ? AND status = 'published' ORDER BY last_post_at DESC, id DESC LIMIT 1",
            [$id]
        )->fetch(\PDO::FETCH_ASSOC);
        $expected = $last === false ? [null, null, null, null] : [(int) $last['last_post_id'], (string) $last['last_post_at'], (int) $last['id'], (int) $last['last_poster_id']];
        $actual = [
            $f['last_post_id'] === null ? null : (int) $f['last_post_id'],
            $f['last_post_at'] === null ? null : (string) $f['last_post_at'],
            $f['last_topic_id'] === null ? null : (int) $f['last_topic_id'],
            $f['last_poster_id'] === null ? null : (int) $f['last_poster_id'],
        ];
        if ($expected !== $actual) {
            $bad[] = "Forum {$id}: last_* " . json_encode($actual) . ' statt ' . json_encode($expected);
        }
    }
    foreach ($w->db->rows('community_topics') as $t) {
        $id = (int) $t['id'];
        $replies = $w->db->count('community_posts', ['topic_id' => $id, 'status' => 'published', 'is_first_post' => 0]);
        if ((int) $t['reply_count'] !== $replies) {
            $bad[] = "Thema {$id}: reply_count {$t['reply_count']} statt {$replies}";
        }
        $last = $w->db->execute("SELECT id, user_id, created_at FROM cms_community_posts WHERE topic_id = ? AND status = 'published' ORDER BY id DESC LIMIT 1", [$id])->fetch(\PDO::FETCH_ASSOC);
        if ($last === false) {
            if ($t['last_post_id'] !== null) {
                $bad[] = "Thema {$id}: last_post_id {$t['last_post_id']} statt NULL";
            }
        } elseif ([(int) $t['last_post_id'], (string) $t['last_post_at'], (int) $t['last_poster_id']] !== [(int) $last['id'], (string) $last['created_at'], (int) $last['user_id']]) {
            $bad[] = "Thema {$id}: last_* " . json_encode([$t['last_post_id'], $t['last_post_at'], $t['last_poster_id']]) . ' statt ' . json_encode([$last['id'], $last['created_at'], $last['user_id']]);
        }
        if ($t['last_post_at'] === null) {
            $bad[] = "Thema {$id}: last_post_at ist NULL";
        }
    }
    if ($users) {
        foreach ($w->db->rows('community_user_stats') as $s) {
            $id = (int) $s['user_id'];
            $topics = $w->db->count('community_topics', ['user_id' => $id, 'status' => 'published']);
            $posts = (int) $w->scalar(
                "SELECT COUNT(*) FROM cms_community_posts p JOIN cms_community_topics t ON t.id = p.topic_id WHERE p.user_id = ? AND p.status = 'published' AND t.status = 'published'",
                [$id]
            );
            if ((int) $s['topic_count'] !== $topics || (int) $s['post_count'] !== $posts) {
                $bad[] = "Mitglied {$id}: topic_count/post_count {$s['topic_count']}/{$s['post_count']} statt {$topics}/{$posts}";
            }
        }
    }

    return $bad;
}

/** Zähler prüfen: eine Prüfung je Schritt, mit den Abweichungen im Text. */
function ccAssertCounters(CcWorld $w, string $step, bool $users = true): void
{
    T::same([], ccAudit($w, $users), 'Zähler nach „' . $step . '“ stimmen mit der Neuberechnung überein');
}

/**
 * Inhalt aller beteiligten Tabellen als ein Wert (für „nichts geschrieben“).
 *
 * @return array<string, list<array<string, mixed>>>
 */
function ccSnapshot(CcWorld $w): array
{
    $snapshot = [];
    foreach (['community_forums', 'community_topics', 'community_posts', 'community_post_revisions', 'community_user_stats', 'community_read_states', 'community_forum_read_marks'] as $table) {
        $snapshot[$table] = $w->db->rows($table);
    }

    return $snapshot;
}

/**
 * Verstöße gegen die Vorgaben für Community-Quelltext (Entwurfsvertrag §1): Zeit und Dialekt der Datenbank, Schreibhelfer der
 * Datenbankklasse, Singleton-Zugriff, Zeilensperren außerhalb der Hilfsmethode, Zeilenkonstruktoren.
 *
 * @return list<string> Namen der getroffenen Muster
 */
function ccGuardHits(string $source, bool $isAbstractRepository = false): array
{
    $patterns = [
        'NOW()' => '/\bNOW\s*\(/i',
        'CURRENT_TIMESTAMP' => '/\bCURRENT_TIMESTAMP\b/i',
        'CURDATE()' => '/\bCURDATE\s*\(/i',
        'UTC_TIMESTAMP' => '/\bUTC_TIMESTAMP\b/i',
        'date(' => '/(?<![\w$>:])(?<!function )date\s*\(/',
        'OFFSET' => '/\bOFFSET\b/i',
        'ON DUPLICATE KEY' => '/\bON\s+DUPLICATE\s+KEY\b/i',
        'GREATEST' => '/\bGREATEST\s*\(/i',
        'LEAST' => '/\bLEAST\s*\(/i',
        'FIELD' => '/\bFIELD\s*\(/i',
        'JSON_*' => '/\bJSON_[A-Z_]+\s*\(/',
        'UNION' => '/\bUNION\b/i',
        'Zeilenkonstruktor' => '/\(\s*[\w.]+\s*,\s*[\w.]+\s*\)\s*(?:<=|>=|<>|<|>|=)\s*\(/',
        'insert/update/delete der Datenbank' => '/\$(?:this->)?(?:db|database)->(?:insert|update|delete)\s*\(/',
        'affected_rows' => '/\baffected_rows\b/',
        'Database::instance() außerhalb des Konstruktor-Standards' => '/(?<!\?\? )Database::instance\s*\(/',
    ];
    if (!$isAbstractRepository) {
        $patterns['FOR UPDATE außerhalb von AbstractRepository'] = '/\bFOR\s+UPDATE\b/i';
    }
    $hits = [];
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $source) === 1) {
            $hits[] = $name;
        }
    }

    return $hits;
}

/** @return list<string> alle PHP-Dateien unter `CMS/core/Services/Community/` (absolut) */
function ccCommunityFiles(): array
{
    $files = [];
    $root = ABSPATH . 'core/Services/Community';
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Reine Regeln
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Titel normalisieren: Leerraum, Steuerzeichen, unsichtbare Zeichen, ungültiges UTF-8', static function (): void {
    T::same('Mein Thema', TopicService::normalizeTitle("  Mein \t\n  Thema  "), 'Rand getrimmt, jede Leerraumfolge wird ein Leerzeichen');
    T::same('AB', TopicService::normalizeTitle("A\x00B"), 'Steuerzeichen entfallen');
    T::same('A B', TopicService::normalizeTitle("A\u{00A0}\u{2003}B"), 'geschützte und Unicode-Leerzeichen werden ein Leerzeichen');
    T::same('AB', TopicService::normalizeTitle("A\u{200B}B\u{202E}"), 'Nullbreitenzeichen und Richtungsumschalter entfallen');
    T::same('Zeile eins Zeile zwei', TopicService::normalizeTitle("Zeile eins\r\nZeile zwei"), 'Zeilenumbrüche werden Leerzeichen');
    T::same('Ünïcödé 😀', TopicService::normalizeTitle('Ünïcödé 😀'), 'Umlaute und Emoji bleiben');
    T::same('', TopicService::normalizeTitle("   \t "), 'nur Leerraum ergibt leer');
    T::same(null, TopicService::normalizeTitle("Titel \xC3\x28"), 'ungültiges UTF-8 ergibt null');
});

T::run('Text normalisieren: Zeilenenden, Steuerzeichen, Trimmen; Grund einer Bearbeitung', static function (): void {
    T::same("a\nb\nc", PostService::normalizeBody("  a\r\nb\rc  "), 'Zeilenenden werden \\n, Rand getrimmt');
    T::same("a\tb\n\nc", PostService::normalizeBody("a\tb\n\x07\nc"), 'Tabulator und Zeilenumbruch bleiben, andere Steuerzeichen entfallen');
    T::same('', PostService::normalizeBody(" \n\t "), 'nur Leerraum ergibt leer');
    T::same(null, PostService::normalizeBody("Text \xFF"), 'ungültiges UTF-8 ergibt null');
    T::same('Tippfehler behoben', PostService::normalizeReason("  Tippfehler\n behoben "), 'Grund: eine Zeile');
    T::same(null, PostService::normalizeReason('   '), 'Grund: leer ergibt null');
    T::same(null, PostService::normalizeReason(null), 'Grund: null bleibt null');
    T::same('AB', PostService::normalizeReason("A\x00B"), 'Grund: Steuerzeichen entfallen');
    T::same(str_repeat('ä', PostService::MAX_REASON), PostService::normalizeReason(str_repeat('ä', 300)), 'Grund: auf 255 Zeichen (nicht Bytes) gekürzt');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Thema anlegen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Thema anlegen: veröffentlicht (Zeilen, UTC-Zeiten, Zähler, Lesestatus, Hook)', static function (): void {
    T::check(date('H', ccAt()) !== gmdate('H', ccAt()), 'Vorbedingung: die PHP-Zone der Suite weicht von UTC ab (date() würde auffallen)');
    $w = new CcWorld();
    $user = $w->db->user();
    $forum = $w->db->forum(['title' => 'Allgemein']);

    $r = $w->topics->create($user, $forum, "  Mein   erstes\tThema  ", "Hallo Welt\r\nZweite Zeile  ");
    T::same(true, $r['success'] ?? null, 'create gelingt');
    T::same('published', $r['status'] ?? null, 'Status published');
    T::same('mein-erstes-thema', $r['slug'] ?? null, 'Slug aus dem normalisierten Titel');
    T::same($forum, $r['forum_id'] ?? null, 'Ergebnis nennt das Forum');
    $topicId = (int) ($r['topic_id'] ?? 0);
    $postId = (int) ($r['post_id'] ?? 0);

    $topic = $w->topic($topicId);
    T::same(
        ['Mein erstes Thema', 'mein-erstes-thema', 'published', $user, $forum, $postId, $postId, $user, 0],
        [$topic['title'] ?? null, $topic['slug'] ?? null, $topic['status'] ?? null, (int) ($topic['user_id'] ?? 0), (int) ($topic['forum_id'] ?? 0), (int) ($topic['first_post_id'] ?? 0), (int) ($topic['last_post_id'] ?? 0), (int) ($topic['last_poster_id'] ?? 0), (int) ($topic['reply_count'] ?? -1)],
        'Thema: Titel, Slug, Status, Besitzer, erster und letzter Beitrag, keine Antworten'
    );
    T::same([ccUtc(), ccUtc(), ccUtc()], [$topic['created_at'] ?? null, $topic['updated_at'] ?? null, $topic['last_post_at'] ?? null], 'Thema: alle Zeiten sind die UTC-Zeit der Uhr (nicht Ortszeit)');
    T::same(2, (int) ($topic['version'] ?? 0), 'Thema: version 2 (Anlegen und Verknüpfen des ersten Beitrags)');

    $post = $w->post($postId);
    T::same(
        [$topicId, $forum, $user, 1, 'published', 'markdown', "Hallo Welt\nZweite Zeile", null, 0, null, 0],
        [(int) ($post['topic_id'] ?? 0), (int) ($post['forum_id'] ?? 0), (int) ($post['user_id'] ?? 0), (int) ($post['is_first_post'] ?? -1), $post['status'] ?? null, $post['format'] ?? null, $post['body'] ?? null, array_key_exists('body_html', $post ?? []) ? $post['body_html'] : 'fehlt', (int) ($post['rendered_version'] ?? -1), array_key_exists('ip_hash', $post ?? []) ? $post['ip_hash'] : 'fehlt', (int) ($post['edit_count'] ?? -1)],
        'Beitrag: erster Beitrag, normalisierter Text, body_html NULL, rendered_version 0, ip_hash NULL'
    );
    T::same([ccUtc(), ccUtc()], [$post['created_at'] ?? null, $post['updated_at'] ?? null], 'Beitrag: Zeiten in UTC');

    $f = $w->forum($forum);
    T::same(
        [1, 1, $postId, $topicId, $user, ccUtc(), 2],
        [(int) $f['topic_count'], (int) $f['post_count'], (int) $f['last_post_id'], (int) $f['last_topic_id'], (int) $f['last_poster_id'], $f['last_post_at'], (int) $f['version']],
        'Forum: Zähler +1/+1, „letzter Beitrag“ gesetzt, version erhöht'
    );
    $s = $w->stats($user);
    T::same([1, 1, ccUtc()], [(int) ($s['topic_count'] ?? -1), (int) ($s['post_count'] ?? -1), $s['last_post_at'] ?? null], 'Mitglied: Themen- und Beitragszähler +1, last_post_at gesetzt');
    T::same($postId, (int) ($w->db->row('community_read_states', ['user_id' => $user, 'topic_id' => $topicId])['last_read_post_id'] ?? 0), 'Lesestatus des Autors steht auf dem ersten Beitrag');
    T::same(
        [[Events::TOPIC_CREATED, [$topicId, $forum, $user, 'published', $postId], false]],
        array_map(static fn (array $h): array => [$h['hook'], $h['args'], $h['in_transaction']], $w->hooks),
        'Hook cms_community_topic_created genau einmal, nach dem Commit, mit IDs und Status'
    );
    T::same(1, $w->clockReads, 'die Uhr wird genau einmal gelesen');
    ccAssertCounters($w, 'Thema angelegt (published)');
});

T::run('Thema anlegen: Freigabepflicht (pending) zählt nirgends, last_post_at nie NULL', static function (): void {
    $w = new CcWorld([], ccPending());
    $user = $w->db->user();
    $forum = $w->db->forum();

    $r = $w->topics->create($user, $forum, 'Wartendes Thema', 'Text des wartenden Themas');
    T::same([true, 'pending'], [$r['success'] ?? null, $r['status'] ?? null], 'create liefert status pending');
    $topicId = (int) $r['topic_id'];
    $postId = (int) $r['post_id'];
    $topic = $w->topic($topicId);
    T::same(['pending', $postId, null, null, ccUtc()], [$topic['status'], (int) $topic['first_post_id'], $topic['last_post_id'], $topic['last_poster_id'], $topic['last_post_at']], 'Thema pending: first_post_id gesetzt, last_post_at gesetzt, last_post_id/last_poster_id offen');
    T::same('pending', $w->post($postId)['status'] ?? null, 'erster Beitrag pending');
    $f = $w->forum($forum);
    T::same([0, 0, null, null, 1], [(int) $f['topic_count'], (int) $f['post_count'], $f['last_post_id'], $f['last_topic_id'], (int) $f['version']], 'Forum unverändert (Zähler 0, last_* NULL, version 1)');
    $s = $w->stats($user);
    T::same([0, 0, ccUtc()], [(int) $s['topic_count'], (int) $s['post_count'], $s['last_post_at']], 'Mitglied: Zähler 0, last_post_at gesetzt (Abstand und Tageslimit zählen wartende Beiträge)');
    T::same($postId, (int) ($w->db->row('community_read_states', ['user_id' => $user, 'topic_id' => $topicId])['last_read_post_id'] ?? 0), 'Lesestatus des Autors gesetzt (eigener Beitrag)');
    T::same([$topicId, $forum, $user, 'pending', $postId], $w->hooks[0]['args'] ?? null, 'Hook mit Status pending');
    ccAssertCounters($w, 'Thema angelegt (pending)');
    T::same([], $w->topics->listForum($user, $forum)['items'] ?? ['x'], 'wartendes Thema steht nicht in der Themenliste');

    $w2 = new CcWorld([], static fn (?int $u, string $a, array $c): ?AccessDecision => $a === 'create_topic' ? AccessDecision::allow('weird') : null);
    $u2 = $w2->db->user();
    $f2 = $w2->db->forum();
    T::same('pending', $w2->topics->create($u2, $f2, 'Unbekannter Status', 'Text')['status'] ?? null, 'ein unbekannter initialStatus gilt vorsichtshalber als pending');
});

T::run('Thema anlegen: Eingaben prüfen, Forum unbekannt, Gast', static function (): void {
    $w = new CcWorld();
    $user = $w->db->user();
    $forum = $w->db->forum();
    $before = ccSnapshot($w);

    T::same(['success' => false, 'error' => 'title_too_short'], $w->topics->create($user, $forum, 'Kurz', 'Text'), 'zu kurzer Titel');
    T::same(['success' => false, 'error' => 'title_too_long'], $w->topics->create($user, $forum, str_repeat('x', 201), 'Text'), 'zu langer Titel');
    T::same(['success' => false, 'error' => 'body_too_short'], $w->topics->create($user, $forum, 'Ein Titel', '  '), 'leerer Text');
    T::same(['success' => false, 'error' => 'body_too_long'], $w->topics->create($user, $forum, 'Ein Titel', str_repeat('x', 50001)), 'zu langer Text');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->topics->create($user, $forum, "Titel \xC3\x28", 'Text'), 'ungültiges UTF-8 im Titel');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->topics->create($user, $forum, 'Ein Titel', "Text \xFF"), 'ungültiges UTF-8 im Text');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->topics->create($user, 0, 'Ein Titel', 'Text'), 'Forum-ID 0');
    T::same(['success' => false, 'error' => 'forum_not_found'], $w->topics->create($user, 9999, 'Ein Titel', 'Text'), 'unbekanntes Forum');
    T::same($before, ccSnapshot($w), 'bei Eingabefehlern wird nichts geschrieben');
    T::same([], $w->hooks, 'bei Fehlern kein Hook');

    $guest = $w->topics->create(0, $forum, 'Ein Titel', 'Text');
    T::same(['success' => false, 'error' => 'invalid_input'], $guest, 'Gast (Benutzer 0): auch wenn die Rechteprüfung erlaubt, wird nichts angelegt');
    $lastCall = $w->canLog[count($w->canLog) - 1] ?? ['user' => 'fehlt'];
    T::same(null, $lastCall['user'], 'die Rechteprüfung bekommt für Benutzer 0 null (Gast)');
    T::same($before, ccSnapshot($w), 'Gast: nichts geschrieben');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Antworten
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Antwort: veröffentlicht (Zeilen, Zähler, Position, Lesestatus, Hook)', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $topic = (int) $w->topics->create($a, $forum, 'Thema für Antworten', 'Erster Beitrag')['topic_id'];
    $first = (int) $w->topic($topic)['first_post_id'];
    $w->hooks = [];
    $w->clockReads = 0;

    $w->at(60);
    $r = $w->posts->reply($b, $topic, "Erste Antwort\r\nmit Umbruch ");
    T::same([true, 'published', 2, 1], [$r['success'] ?? null, $r['status'] ?? null, $r['position'] ?? null, $r['page'] ?? null], 'reply gelingt, Position 2 (Themenstart zählt mit), Seite 1');
    $postId = (int) $r['post_id'];
    $post = $w->post($postId);
    T::same(
        [$topic, $forum, $b, 0, 'published', "Erste Antwort\nmit Umbruch", null, 0, null, null],
        [(int) $post['topic_id'], (int) $post['forum_id'], (int) $post['user_id'], (int) $post['is_first_post'], $post['status'], $post['body'], $post['body_html'], (int) $post['rendered_version'], $post['ip_hash'], $post['quote_of_post_id']],
        'Beitrag: Thema, Forum des Themas, Autor, keine Themenstart, normalisierter Text, body_html NULL, ip_hash NULL, kein Zitat'
    );
    T::same([ccUtc(60), ccUtc(60)], [$post['created_at'], $post['updated_at']], 'Beitrag: Zeiten in UTC');

    $t = $w->topic($topic);
    T::same([1, $postId, $b, ccUtc(60), 3], [(int) $t['reply_count'], (int) $t['last_post_id'], (int) $t['last_poster_id'], $t['last_post_at'], (int) $t['version']], 'Thema: reply_count +1, „letzter Beitrag“ gesetzt, version erhöht');
    $f = $w->forum($forum);
    T::same([1, 2, $postId, $topic, $b, 3], [(int) $f['topic_count'], (int) $f['post_count'], (int) $f['last_post_id'], (int) $f['last_topic_id'], (int) $f['last_poster_id'], (int) $f['version']], 'Forum: post_count +1, topic_count unverändert, „letzter Beitrag“ gesetzt');
    $s = $w->stats($b);
    T::same([0, 1, ccUtc(60)], [(int) $s['topic_count'], (int) $s['post_count'], $s['last_post_at']], 'Mitglied B: post_count 1, last_post_at');
    T::same($postId, (int) ($w->db->row('community_read_states', ['user_id' => $b, 'topic_id' => $topic])['last_read_post_id'] ?? 0), 'Lesestatus des Antwortenden');
    T::same([[Events::POST_CREATED, [$postId, $topic, $b, 'published'], false]], array_map(static fn (array $h): array => [$h['hook'], $h['args'], $h['in_transaction']], $w->hooks), 'Hook cms_community_post_created genau einmal, nach dem Commit (nicht für den Themenstart)');
    T::same(1, $w->clockReads, 'die Uhr wird genau einmal gelesen');
    T::same(['success' => false, 'error' => 'topic_not_found'], $w->posts->reply($b, 9999, 'Antwort ins Leere'), 'unbekanntes Thema');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->posts->reply($b, 0, 'Antwort ins Leere'), 'Thema-ID 0');
    T::same(['success' => false, 'error' => 'body_too_short'], $w->posts->reply($b, $topic, ' x '), 'zu kurzer Text');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->posts->reply(0, $topic, 'Text vom Gast'), 'Gast: nichts angelegt');
    T::same(1, $w->db->count('community_posts', ['topic_id' => $topic, 'is_first_post' => 0]), 'nach den Fehlversuchen weiterhin eine Antwort');
    ccAssertCounters($w, 'Antwort (published)');
    T::check($first > 0, 'Vorbedingung: erster Beitrag vorhanden');
});

T::run('Antwort: Freigabepflicht (pending) und Antwort in einem wartenden Thema', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $topic = (int) $w->topics->create($a, $forum, 'Thema mit wartender Antwort', 'Erster Beitrag')['topic_id'];
    $w->rule = ccPending();
    $w->at(60);
    $before = $w->forum($forum);

    $r = $w->posts->reply($b, $topic, 'Wartende Antwort');
    T::same([true, 'pending', null, null], [$r['success'] ?? null, $r['status'] ?? null, $r['position'], $r['page']], 'reply pending: keine öffentliche Position');
    $t = $w->topic($topic);
    T::same(0, (int) $t['reply_count'], 'Thema: reply_count unverändert');
    $f = $w->forum($forum);
    T::same([(int) $before['post_count'], $before['last_post_id'], (int) $before['version']], [(int) $f['post_count'], $f['last_post_id'], (int) $f['version']], 'Forum: Zähler, last_* und version unverändert');
    T::same([0, 0, ccUtc(60)], [(int) $w->stats($b)['topic_count'], (int) $w->stats($b)['post_count'], $w->stats($b)['last_post_at']], 'Mitglied: Zähler 0, last_post_at gesetzt');
    T::same([Events::POST_CREATED, 'pending'], [$w->hooks[count($w->hooks) - 1]['hook'], $w->hooks[count($w->hooks) - 1]['args'][3]], 'Hook mit Status pending');
    ccAssertCounters($w, 'Antwort (pending)');

    // Moderation antwortet (die Rechteprüfung erlaubt) in einem wartenden Thema: das Forum zählt den Beitrag noch nicht.
    $w2 = new CcWorld();
    $author = $w2->db->user();
    $moderator = $w2->db->user();
    $forum2 = $w2->db->forum();
    $w2->rule = ccPending();
    $pendingTopic = (int) $w2->topics->create($author, $forum2, 'Wartendes Thema', 'Text')['topic_id'];
    $w2->rule = null;
    $w2->at(60);
    $r2 = $w2->posts->reply($moderator, $pendingTopic, 'Antwort der Moderation');
    T::same([true, 'published', 1, 1], [$r2['success'] ?? null, $r2['status'] ?? null, $r2['position'] ?? null, $r2['page'] ?? null], 'Antwort der Moderation: veröffentlicht, Position 1 (der Themenstart wartet noch)');
    T::same(1, (int) $w2->topic($pendingTopic)['reply_count'], 'Thema: reply_count 1');
    $f2 = $w2->forum($forum2);
    T::same([0, 0, null], [(int) $f2['topic_count'], (int) $f2['post_count'], $f2['last_post_id']], 'Forum zählt den Beitrag eines wartenden Themas nicht (die Freigabe bucht nach)');
    ccAssertCounters($w2, 'Antwort in wartendem Thema', false);

    $w3 = new CcWorld();
    $u3 = $w3->db->user();
    $f3 = $w3->db->forum();
    foreach (['deleted' => 'topic_deleted', 'trash' => 'topic_deleted', 'moved' => 'topic_moved'] as $status => $error) {
        $t3 = $w3->db->topic(['forum' => $f3, 'user' => $u3, 'status' => $status]);
        T::same(['success' => false, 'error' => $error], $w3->posts->reply($u3, $t3, 'Antwort in beendetem Thema'), 'Thema ' . $status . ': Antwort wird abgelehnt (' . $error . '), auch wenn die Rechteprüfung erlaubt');
    }
});

T::run('Zähler-Matrix: Abgleich mit Neuberechnung nach jedem Schritt (§3.6)', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $c = $w->db->user();
    $forum = $w->db->forum();
    $forum2 = $w->db->forum();

    $t1 = $w->topics->create($a, $forum, 'Erstes Thema der Matrix', 'Themenstart A');
    $topic1 = (int) $t1['topic_id'];
    $first1 = (int) $t1['post_id'];
    ccAssertCounters($w, '1 Thema angelegt');

    $w->at(60);
    $r1 = $w->posts->reply($b, $topic1, 'Antwort von B');
    ccAssertCounters($w, '2 Antwort B (published)');

    $w->at(120);
    $w->rule = ccPending();
    $rPending = $w->posts->reply($c, $topic1, 'Wartende Antwort von C');
    $w->rule = null;
    ccAssertCounters($w, '3 Antwort C (pending)');

    $w->at(180);
    $r2 = $w->posts->reply($a, $topic1, 'Zweite Antwort von A');
    ccAssertCounters($w, '4 Antwort A (published)');
    T::same([2, 3], [(int) $w->topic($topic1)['reply_count'], (int) $w->forum($forum)['post_count']], 'Zwischenstand: 2 Antworten, 3 Beiträge im Forum');

    $w->at(240);
    $w->posts->edit($b, (int) $r1['post_id'], 'Antwort von B, überarbeitet');
    ccAssertCounters($w, '5 Text bearbeitet');
    $versionBefore = [(int) $w->topic($topic1)['version'], (int) $w->forum($forum)['version']];
    $w->posts->edit($a, $first1, 'Themenstart A, überarbeitet', 'Erstes Thema, neu betitelt');
    ccAssertCounters($w, '6 Titel bearbeitet');
    T::same([$versionBefore[0] + 1, $versionBefore[1] + 1], [(int) $w->topic($topic1)['version'], (int) $w->forum($forum)['version']], 'Titeländerung: version von Thema und Forum +1 (Text allein ändert sie nicht)');

    $w->at(300);
    $w->posts->delete($b, (int) $r1['post_id']);
    ccAssertCounters($w, '7 Antwort (nicht die letzte) gelöscht');
    $w->at(360);
    $w->posts->delete($a, (int) $r2['post_id']);
    ccAssertCounters($w, '8 letzte Antwort gelöscht');
    T::same([0, 1], [(int) $w->topic($topic1)['reply_count'], (int) $w->forum($forum)['post_count']], 'Zwischenstand: keine Antwort, ein Beitrag');
    $w->posts->delete($c, (int) $rPending['post_id']);
    ccAssertCounters($w, '9 wartende Antwort gelöscht (zählt nirgends)');

    $w->at(420);
    $t2 = $w->topics->create($b, $forum, 'Zweites Thema der Matrix', 'Themenstart B');
    $topic2 = (int) $t2['topic_id'];
    $w->at(480);
    $t3 = $w->topics->create($c, $forum2, 'Thema im anderen Forum', 'Themenstart C');
    ccAssertCounters($w, '10 weitere Themen in zwei Foren');
    $w->at(540);
    $w->topics->delete($b, $topic2);
    ccAssertCounters($w, '11 antwortloses Thema vom Autor gelöscht');
    T::same([1, 1], [(int) $w->forum($forum)['topic_count'], (int) $w->forum($forum)['post_count']], 'Zwischenstand Forum 1: ein Thema, ein Beitrag');

    // Moderation löscht ein Thema mit Antworten anderer Autoren: Forum und Starter stimmen, die Zähler der anderen Autoren driften (Reparatur-Job in PR #5).
    $w->at(600);
    $w->posts->reply($b, $topic1, 'Antwort B im ersten Thema');
    $w->at(660);
    $w->posts->reply($c, $topic1, 'Antwort C im ersten Thema');
    ccAssertCounters($w, '12 zwei Antworten');
    $w->at(720);
    $moderator = $w->db->user();
    $w->moderator = true;
    $deleted = $w->topics->delete($moderator, $topic1);
    T::same(true, $deleted['success'] ?? null, 'Moderation löscht das Thema mit Antworten');
    T::same([], ccAudit($w, false), 'Forum und Themen stimmen nach dem Löschen eines Themas mit Antworten (ohne Mitgliederzähler)');
    T::same([0, 0, null], [(int) $w->forum($forum)['topic_count'], (int) $w->forum($forum)['post_count'], $w->forum($forum)['last_post_id']], 'Forum: alle Zähler 0, „letzter Beitrag“ leer');
    T::same([0, 0], [(int) $w->stats($a)['topic_count'], (int) $w->stats($a)['post_count']], 'Themenstarter A: Themen- und Beitragszähler −1');
    T::check((int) $w->stats($b)['post_count'] >= 1 && (int) $w->stats($c)['post_count'] >= 1, 'bekannte Drift: die Beitragszähler der anderen Autoren werden nicht einzeln abgezogen');
    T::check($t3['success'] && $r1['success'], 'Vorbedingung: Hilfsaufrufe gelungen');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Doppeltes Absenden (Variante B)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Idempotenz: doppeltes Absenden eines Themas liefert dasselbe Ergebnis, ohne zweite Zeile und ohne Hook', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $other = $w->db->forum();

    $first = $w->topics->create($a, $forum, 'Doppelt abgeschickt', 'Gleicher Text');
    $w->at(10);
    $second = $w->topics->create($a, $forum, '  Doppelt   abgeschickt ', "Gleicher Text\r\n");
    T::same(true, $second['success'] ?? null, 'zweiter Aufruf gelingt');
    T::same(true, $second['duplicate'] ?? null, 'zweiter Aufruf ist als duplicate gekennzeichnet');
    T::same([$first['topic_id'], $first['post_id'], 'published', $first['slug']], [$second['topic_id'] ?? null, $second['post_id'] ?? null, $second['status'] ?? null, $second['slug'] ?? null], 'dieselbe Thema- und Beitrags-ID, derselbe Status');
    T::same([1, 1], [$w->db->count('community_topics'), $w->db->count('community_posts')], 'genau ein Thema und ein Beitrag');
    T::same([1, 1], [(int) $w->forum($forum)['topic_count'], (int) $w->forum($forum)['post_count']], 'Forumzähler nur einmal erhöht');
    T::same([Events::TOPIC_CREATED], $w->hookNames(), 'kein zweiter Hook');
    ccAssertCounters($w, 'Thema doppelt abgesendet');

    $w->at(20);
    $changedBody = $w->topics->create($a, $forum, 'Doppelt abgeschickt', 'Anderer Text');
    T::check(empty($changedBody['duplicate']) && ($changedBody['topic_id'] ?? 0) !== $first['topic_id'], 'gleicher Titel, anderer Text: neues Thema');
    $w->at(30);
    T::check(empty($w->topics->create($b, $forum, 'Doppelt abgeschickt', 'Gleicher Text')['duplicate']), 'anderes Mitglied: neues Thema');
    T::check(empty($w->topics->create($a, $other, 'Doppelt abgeschickt', 'Gleicher Text')['duplicate']), 'anderes Forum: neues Thema');
    T::same(4, $w->db->count('community_topics'), 'vier verschiedene Themen');

    // Das zweite Absenden bekommt das Ergebnis des ersten, auch wenn die Rechteprüfung unter der Sperre „zu schnell“ sagen würde.
    $w2 = new CcWorld();
    $u = $w2->db->user();
    $f = $w2->db->forum();
    $one = $w2->topics->create($u, $f, 'Schnell zweimal', 'Text');
    $w2->rule = static fn (?int $user, string $action, array $ctx): ?AccessDecision => !empty($ctx['fresh']) ? AccessDecision::deny('rate_limited', 30) : null;
    $w2->at(1);
    $dup = $w2->topics->create($u, $f, 'Schnell zweimal', 'Text');
    T::same([true, true, $one['topic_id']], [$dup['success'] ?? null, $dup['duplicate'] ?? null, $dup['topic_id'] ?? null], 'Treffer vor der erneuten Rechteprüfung unter der Sperre: Ergebnis des ersten Mals statt rate_limited');
    $new = $w2->topics->create($u, $f, 'Anderes Thema', 'Text');
    T::same(['success' => false, 'error' => 'rate_limited', 'retry_after' => 30], $new, 'ein anderer Inhalt läuft in die Ablehnung unter der Sperre');
    T::same(1, $w2->db->count('community_topics'), 'abgelehnt: kein weiteres Thema');
});

T::run('Idempotenz: doppeltes Absenden einer Antwort', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $topic = (int) $w->topics->create($a, $forum, 'Thema für doppelte Antworten', 'Erster Beitrag')['topic_id'];
    $w->at(60);
    $first = $w->posts->reply($b, $topic, 'Gleiche Antwort');
    $w->hooks = [];
    $w->at(70);
    $second = $w->posts->reply($b, $topic, "Gleiche Antwort \r\n");
    T::same([true, true, $first['post_id'], 'published', 2, 1], [$second['success'] ?? null, $second['duplicate'] ?? null, $second['post_id'] ?? null, $second['status'] ?? null, $second['position'] ?? null, $second['page'] ?? null], 'zweiter Aufruf: dasselbe Ergebnis (post_id, Status, Position, Seite), duplicate');
    T::same(1, $w->db->count('community_posts', ['topic_id' => $topic, 'is_first_post' => 0]), 'genau eine Antwort');
    T::same(1, (int) $w->topic($topic)['reply_count'], 'reply_count nur einmal erhöht');
    T::same([], $w->hooks, 'kein zweiter Hook');
    ccAssertCounters($w, 'Antwort doppelt abgesendet');

    $w->at(80);
    $r = $w->posts->reply($b, $topic, 'Andere Antwort');
    T::check(empty($r['duplicate']) && ($r['position'] ?? 0) === 3, 'anderer Text: neue Antwort (Position 3)');
    $w->at(90);
    $sameAsStart = $w->posts->reply($a, $topic, 'Erster Beitrag');
    T::check(empty($sameAsStart['duplicate']) && ($sameAsStart['post_id'] ?? 0) !== (int) $w->topic($topic)['first_post_id'], 'derselbe Text wie der eigene Themenstart ist keine doppelte Antwort');
    $w->at(100);
    $otherTopic = (int) $w->topics->create($a, $forum, 'Anderes Thema', 'Anderer Start')['topic_id'];
    $w->at(110);
    T::check(empty($w->posts->reply($b, $otherTopic, 'Gleiche Antwort')['duplicate']), 'gleicher Text in einem anderen Thema: keine doppelte Antwort');

    // Reihenfolge: der Treffer kommt vor der Rechteprüfung unter der Sperre.
    $w->at(120);
    $w->rule = static fn (?int $user, string $action, array $ctx): ?AccessDecision => !empty($ctx['fresh']) ? AccessDecision::deny('slow_mode', 20) : null;
    $dup = $w->posts->reply($b, $otherTopic, 'Gleiche Antwort');
    T::same([true, true], [$dup['success'] ?? null, $dup['duplicate'] ?? null], 'Treffer vor der Rechteprüfung unter der Sperre');
    T::same(['success' => false, 'error' => 'slow_mode', 'retry_after' => 20], $w->posts->reply($b, $otherTopic, 'Neuer Text'), 'anderer Text: Ablehnung unter der Sperre mit retry_after');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Bearbeiten
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Bearbeiten: Verlauf (ersetzte Fassung), edit_count, Hook; unverändert schreibt nichts', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $m = $w->db->user();
    $forum = $w->db->forum();
    $topic = (int) $w->topics->create($a, $forum, 'Thema zum Bearbeiten', 'Erster Text')['topic_id'];
    $w->at(60);
    $post = (int) $w->posts->reply($b, $topic, 'Alter Text')['post_id'];
    $w->hooks = [];
    $w->at(100);
    $w->clockReads = 0;

    $r = $w->posts->edit($b, $post, "Neuer Text\r\n", null, "  Tippfehler\n behoben ");
    T::same([true, true, 1, false], [$r['success'] ?? null, $r['changed'] ?? null, $r['edit_count'] ?? null, $r['title_changed'] ?? null], 'edit gelingt: geändert, edit_count 1, Titel unverändert');
    $row = $w->post($post);
    T::same(
        ['Neuer Text', null, 0, 1, ccUtc(100), $b, 'Tippfehler behoben', ccUtc(100)],
        [$row['body'], $row['body_html'], (int) $row['rendered_version'], (int) $row['edit_count'], $row['edited_at'], (int) $row['edited_by'], $row['edit_reason'], $row['updated_at']],
        'Beitrag: neuer Text, body_html NULL, rendered_version 0, edit_count, edited_at/by/reason (eine Zeile), updated_at'
    );
    T::same(ccUtc(60), $row['created_at'], 'created_at bleibt');
    $revisions = $w->db->rows('community_post_revisions', ['post_id' => $post]);
    T::same(1, count($revisions), 'eine Revision');
    T::same(
        ['Alter Text', null, 'markdown', $b, 'Tippfehler behoben', ccUtc(100), (int) $r['revision_id']],
        [$revisions[0]['body'], $revisions[0]['title'], $revisions[0]['format'], (int) $revisions[0]['edited_by'], $revisions[0]['edit_reason'], $revisions[0]['created_at'], (int) $revisions[0]['id']],
        'Revision: die ERSETZTE Fassung (alter Text, kein Titel bei Antworten), Bearbeiter, Grund, Zeit der Bearbeitung'
    );
    T::same([[Events::POST_UPDATED, [$post, $topic, $b, (int) $r['revision_id']], false]], array_map(static fn (array $h): array => [$h['hook'], $h['args'], $h['in_transaction']], $w->hooks), 'Hook cms_community_post_updated genau einmal nach dem Commit, kein topic_updated ohne Titeländerung');
    T::same(1, $w->clockReads, 'die Uhr wird genau einmal gelesen');
    $topicVersion = (int) $w->topic($topic)['version'];
    $forumVersion = (int) $w->forum($forum)['version'];

    $w->at(160);
    $w->hooks = [];
    $unchanged = $w->posts->edit($b, $post, 'Neuer Text', null, 'egal');
    T::same([true, false, 1, null], [$unchanged['success'] ?? null, $unchanged['changed'] ?? null, $unchanged['edit_count'] ?? null, array_key_exists('revision_id', $unchanged) ? $unchanged['revision_id'] : 'fehlt'], 'unveränderter Text: ok ohne Änderung');
    T::same([1, 1, []], [$w->db->count('community_post_revisions', ['post_id' => $post]), (int) $w->post($post)['edit_count'], $w->hooks], 'keine neue Revision, kein neuer Zähler, kein Hook');

    $w->at(200);
    $second = $w->posts->edit($m, $post, 'Dritter Text', null, '');
    T::same([true, 2], [$second['changed'] ?? null, $second['edit_count'] ?? null], 'zweite Bearbeitung (durch die Moderation): edit_count 2');
    $revisions = $w->db->rows('community_post_revisions', ['post_id' => $post], 'id');
    T::same(['Alter Text', 'Neuer Text'], array_map(static fn (array $r): string => $r['body'], $revisions), 'Verlauf: jeweils die ersetzte Fassung');
    T::same([$b, $m, null], [(int) $revisions[0]['edited_by'], (int) $revisions[1]['edited_by'], $revisions[1]['edit_reason']], 'Revision: Bearbeiter dieser Bearbeitung; leerer Grund wird NULL');
    T::same([$m, null], [(int) $w->post($post)['edited_by'], $w->post($post)['edit_reason']], 'Beitrag: edited_by = Moderation, edit_reason NULL');
    T::same([$topicVersion, $forumVersion], [(int) $w->topic($topic)['version'], (int) $w->forum($forum)['version']], 'Textänderungen lassen version von Thema und Forum unberührt');
    T::same(str_repeat('x', 255), $w->posts->edit($b, $post, 'Vierter Text', null, str_repeat('x', 400)) ? $w->post($post)['edit_reason'] : null, 'Grund wird auf 255 Zeichen gekürzt');
    ccAssertCounters($w, 'Beiträge bearbeitet');

    T::same(['success' => false, 'error' => 'post_not_found'], $w->posts->edit($b, 9999, 'Text'), 'unbekannter Beitrag');
    T::same(['success' => false, 'error' => 'body_too_short'], $w->posts->edit($b, $post, 'x'), 'zu kurzer Text');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->posts->edit($b, $post, 'Text', 'Titel für eine Antwort'), 'Titel nur beim ersten Beitrag');
    $w->posts->delete($b, $post);
    T::same(['success' => false, 'error' => 'post_not_editable'], $w->posts->edit($b, $post, 'Text nach dem Löschen'), 'gelöschter Beitrag ist nicht bearbeitbar (auch wenn die Rechteprüfung erlaubt)');
});

T::run('Bearbeiten: Titel des Themas (Slug, version, Verlauf mit altem Titel, Hooks)', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $forum = $w->db->forum();
    $created = $w->topics->create($a, $forum, 'Alter Titel mit Ümlaut', 'Themenstart');
    $topic = (int) $created['topic_id'];
    $first = (int) $created['post_id'];
    T::same('alter-titel-mit-uemlaut', $created['slug'], 'Slug mit ü → ue');
    $w->hooks = [];
    $w->at(120);
    $topicVersion = (int) $w->topic($topic)['version'];
    $forumVersion = (int) $w->forum($forum)['version'];

    $r = $w->posts->edit($a, $first, 'Themenstart', "  Neuer   Titel\tFür Größe ", 'Titel geschärft');
    T::same([true, true, true, 'neuer-titel-fuer-groesse'], [$r['success'] ?? null, $r['changed'] ?? null, $r['title_changed'] ?? null, $r['slug'] ?? null], 'nur der Titel ändert sich: geändert, Slug neu (ß → ss)');
    $t = $w->topic($topic);
    T::same(['Neuer Titel Für Größe', 'neuer-titel-fuer-groesse', $topicVersion + 1, ccUtc(120)], [$t['title'], $t['slug'], (int) $t['version'], $t['updated_at']], 'Thema: normalisierter Titel, Slug, version +1, updated_at');
    T::same($forumVersion + 1, (int) $w->forum($forum)['version'], 'Forum: version +1 (Listen zeigen den Titel)');
    $revision = $w->db->rows('community_post_revisions', ['post_id' => $first])[0] ?? [];
    T::same(['Themenstart', 'Alter Titel mit Ümlaut', 'Titel geschärft'], [$revision['body'] ?? null, $revision['title'] ?? null, $revision['edit_reason'] ?? null], 'Revision des ersten Beitrags trägt den alten Titel');
    T::same(1, (int) $w->post($first)['edit_count'], 'edit_count 1 (auch bei reiner Titeländerung)');
    T::same(
        [Events::POST_UPDATED, Events::TOPIC_UPDATED],
        $w->hookNames(),
        'Hooks: post_updated und topic_updated'
    );
    T::same([$topic, $forum, $a, ['title', 'slug']], $w->hooks[1]['args'], 'topic_updated(Thema, Forum, Bearbeiter, [title, slug])');
    T::same([false, false], [$w->hooks[0]['in_transaction'], $w->hooks[1]['in_transaction']], 'beide Hooks nach dem Commit');

    $w->hooks = [];
    $w->at(180);
    $sameSlug = $w->posts->edit($a, $first, 'Themenstart', 'Neuer Titel für Grösse!');
    T::same(['title'], $w->hooks[1]['args'][3] ?? null, 'gleicher Slug trotz neuem Titel: topic_updated nennt nur title');
    T::same('neuer-titel-fuer-groesse', $sameSlug['slug'] ?? null, 'Slug bleibt');

    $w->hooks = [];
    $unchanged = $w->posts->edit($a, $first, 'Themenstart', 'Neuer Titel für Grösse!');
    T::same([true, false, []], [$unchanged['success'] ?? null, $unchanged['changed'] ?? null, $w->hooks], 'gleicher Titel und Text: nichts');
    T::same(['success' => false, 'error' => 'title_too_short'], $w->posts->edit($a, $first, 'Themenstart', 'Kurz'), 'zu kurzer Titel');
    T::same(['success' => false, 'error' => 'title_too_long'], $w->posts->edit($a, $first, 'Themenstart', str_repeat('y', 201)), 'zu langer Titel');
    ccAssertCounters($w, 'Titel bearbeitet');
});

T::run('Konflikte: expectedEditCount (edit_conflict) und seen_last_post_id (new_posts, force)', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $c = $w->db->user();
    $forum = $w->db->forum();
    $topic = (int) $w->topics->create($a, $forum, 'Thema für Konflikte', 'Themenstart')['topic_id'];
    $w->at(60);
    $p1 = (int) $w->posts->reply($b, $topic, 'Antwort eins')['post_id'];

    $w->at(100);
    $w->posts->edit($b, $p1, 'Antwort eins, erweitert');
    $before = ccSnapshot($w);
    $conflict = $w->posts->edit($b, $p1, 'Antwort eins, meine Fassung', null, '', 0);
    T::same(['success' => false, 'error' => 'edit_conflict', 'edit_count' => 1], $conflict, 'erwartet edit_count 0, ist 1: edit_conflict mit dem aktuellen Stand');
    T::same($before, ccSnapshot($w), 'bei edit_conflict wird nichts geschrieben');
    $ok = $w->posts->edit($b, $p1, 'Antwort eins, meine Fassung', null, '', 1);
    T::same([true, 2], [$ok['success'] ?? null, $ok['edit_count'] ?? null], 'passender Stand: Bearbeitung gelingt');

    // Antworten: B öffnete das Formular, als der letzte Beitrag $p1 war; C antwortet dazwischen.
    $w->at(200);
    $p2 = (int) $w->posts->reply($c, $topic, 'Dazwischen von C')['post_id'];
    $w->at(260);
    $w->hooks = [];
    $before = ccSnapshot($w);
    $new = $w->posts->reply($b, $topic, 'Meine Antwort nach C', ['seen_last_post_id' => $p1]);
    T::same(['success' => false, 'error' => 'new_posts', 'new_posts' => 1, 'last_post_id' => $p2], $new, 'neue Beiträge seit dem Öffnen: new_posts mit Anzahl, nichts gespeichert');
    T::same([$before, []], [ccSnapshot($w), $w->hooks], 'bei new_posts wird nichts geschrieben, kein Hook');

    $w->at(300);
    $p3 = (int) $w->posts->reply($c, $topic, 'Noch eine von C')['post_id'];
    $w->at(320);
    T::same(2, $w->posts->reply($b, $topic, 'Meine Antwort nach C', ['seen_last_post_id' => $p1])['new_posts'] ?? null, 'Anzahl der neuen Beiträge wächst (2)');
    $forced = $w->posts->reply($b, $topic, 'Meine Antwort nach C', ['seen_last_post_id' => $p1, 'force' => true]);
    T::same([true, 5], [$forced['success'] ?? null, $forced['position'] ?? null], 'mit force wird gespeichert (Position 5)');
    T::same($p1, (int) $w->db->row('community_read_states', ['user_id' => $b, 'topic_id' => $topic])['last_read_post_id'], 'Lesestatus von B bleibt bei seen_last_post_id: die Beiträge von C bleiben ungelesen');
    T::same($p2, $w->reads->firstUnreadPostId($b, $topic), 'erster ungelesener Beitrag für B ist der von C');

    $w->at(400);
    $caught = $w->posts->reply($b, $topic, 'Nach Kenntnis aller', ['seen_last_post_id' => (int) $w->topic($topic)['last_post_id']]);
    T::same(true, $caught['success'] ?? null, 'seen_last_post_id = letzter Beitrag: kein Konflikt');
    T::same((int) $caught['post_id'], (int) $w->db->row('community_read_states', ['user_id' => $b, 'topic_id' => $topic])['last_read_post_id'], 'ohne Konflikt steht der Lesestatus auf dem eigenen Beitrag');
    T::check($p3 > $p2, 'Vorbedingung: IDs aufsteigend');
    ccAssertCounters($w, 'Konflikte');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Löschen (weich)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Weiches Löschen einer Antwort: Status, Zähler, „letzter Beitrag“ neu bestimmt, idempotent, Hook', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $moderator = $w->db->user();
    $forum = $w->db->forum();
    $topic = (int) $w->topics->create($a, $forum, 'Thema zum Löschen', 'Themenstart')['topic_id'];
    $w->at(60);
    $p1 = (int) $w->posts->reply($b, $topic, 'Antwort eins')['post_id'];
    $w->at(120);
    $p2 = (int) $w->posts->reply($a, $topic, 'Antwort zwei')['post_id'];
    $w->at(180);
    $p3 = (int) $w->posts->reply($b, $topic, 'Antwort drei')['post_id'];
    $w->hooks = [];
    $w->at(240);
    $w->clockReads = 0;

    $r = $w->posts->delete($b, $p1);
    T::same([true, 'published', 'deleted'], [$r['success'] ?? null, $r['previous_status'] ?? null, $r['mode'] ?? null], 'Antwort eins (nicht die letzte) gelöscht');
    $row = $w->post($p1);
    T::same(['deleted', 'author', ccUtc(240), 'Antwort eins'], [$row['status'], $row['status_reason'], $row['updated_at'], $row['body']], 'Status deleted, Grund author, Text bleibt erhalten (Wiederherstellung, Verlauf)');
    T::same([2, $p3], [(int) $w->topic($topic)['reply_count'], (int) $w->topic($topic)['last_post_id']], 'Thema: reply_count −1, „letzter Beitrag“ bleibt der dritte');
    T::same([3, $p3], [(int) $w->forum($forum)['post_count'], (int) $w->forum($forum)['last_post_id']], 'Forum: post_count −1');
    T::same(1, (int) $w->stats($b)['post_count'], 'Autor B: post_count −1');
    T::same([[Events::POST_DELETED, [$p1, $topic, $b, 'deleted'], false]], array_map(static fn (array $h): array => [$h['hook'], $h['args'], $h['in_transaction']], $w->hooks), 'Hook cms_community_post_deleted genau einmal nach dem Commit');
    T::same(1, $w->clockReads, 'die Uhr wird genau einmal gelesen');
    ccAssertCounters($w, 'Antwort gelöscht');

    $w->hooks = [];
    $snapshot = ccSnapshot($w);
    $again = $w->posts->delete($b, $p1);
    T::same([true, true], [$again['success'] ?? null, $again['already_deleted'] ?? null], 'wiederholtes Löschen: ok, already_deleted');
    T::same([$snapshot, []], [ccSnapshot($w), $w->hooks], 'wiederholtes Löschen schreibt nichts und löst keinen Hook aus');

    $w->at(300);
    $w->posts->delete($moderator, $p3);
    T::same('moderator', $w->post($p3)['status_reason'], 'fremder Beitrag: Grund moderator');
    T::same([$p2, ccUtc(120), $a, 1], [(int) $w->topic($topic)['last_post_id'], $w->topic($topic)['last_post_at'], (int) $w->topic($topic)['last_poster_id'], (int) $w->topic($topic)['reply_count']], 'war der Beitrag der letzte: Thema zeigt den vorherigen Beitrag');
    T::same([$p2, $topic, $a, ccUtc(120)], [(int) $w->forum($forum)['last_post_id'], (int) $w->forum($forum)['last_topic_id'], (int) $w->forum($forum)['last_poster_id'], $w->forum($forum)['last_post_at']], 'Forum zeigt den vorherigen Beitrag');
    ccAssertCounters($w, 'letzten Beitrag gelöscht');

    $w->at(360);
    $w->posts->delete($a, $p2);
    $first = (int) $w->topic($topic)['first_post_id'];
    T::same([0, $first, ccUtc()], [(int) $w->topic($topic)['reply_count'], (int) $w->topic($topic)['last_post_id'], $w->topic($topic)['last_post_at']], 'ohne Antworten zeigt „letzter Beitrag“ den Themenstart');
    ccAssertCounters($w, 'alle Antworten gelöscht');

    T::same(['success' => false, 'error' => 'first_post'], $w->posts->delete($a, $first), 'der erste Beitrag wird abgelehnt (first_post)');
    T::same('published', $w->post($first)['status'], 'erster Beitrag unverändert');
    T::same(['success' => false, 'error' => 'post_not_found'], $w->posts->delete($a, 9999), 'unbekannter Beitrag');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->posts->delete($a, 0), 'Beitrag-ID 0');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->posts->delete(0, $p2), 'Gast: nichts gelöscht');
});

T::run('Weiches Löschen: wartende Antwort zählt nirgends; Antwort im wartenden Thema; Forum-last mit mehreren Themen', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $topic1 = (int) $w->topics->create($a, $forum, 'Erstes Thema im Forum', 'Themenstart eins')['topic_id'];
    $w->at(60);
    $w->rule = ccPending();
    $pending = (int) $w->posts->reply($b, $topic1, 'Wartende Antwort')['post_id'];
    $w->rule = null;
    $before = [$w->forum($forum), $w->topic($topic1), $w->stats($b)];
    $w->at(120);
    $w->posts->delete($b, $pending);
    T::same('deleted', $w->post($pending)['status'], 'wartende Antwort gelöscht');
    $after = [$w->forum($forum), $w->topic($topic1), $w->stats($b)];
    T::same([$before[0]['post_count'], $before[0]['version'], $before[1]['reply_count'], $before[1]['version'], $before[2]['post_count']], [$after[0]['post_count'], $after[0]['version'], $after[1]['reply_count'], $after[1]['version'], $after[2]['post_count']], 'Zähler und version von Forum, Thema und Autor unverändert');
    ccAssertCounters($w, 'wartende Antwort gelöscht');

    // Zwei Themen: wird der letzte Beitrag des Forums gelöscht, wandert „letzter Beitrag“ zum anderen Thema.
    $w->at(200);
    $topic2 = (int) $w->topics->create($a, $forum, 'Zweites Thema im Forum', 'Themenstart zwei')['topic_id'];
    $w->at(260);
    $reply = (int) $w->posts->reply($b, $topic2, 'Antwort im zweiten Thema')['post_id'];
    T::same([$reply, $topic2], [(int) $w->forum($forum)['last_post_id'], (int) $w->forum($forum)['last_topic_id']], 'Vorbedingung: Forum zeigt die Antwort im zweiten Thema');
    $w->at(320);
    $w->posts->delete($b, $reply);
    $first2 = (int) $w->topic($topic2)['first_post_id'];
    T::same([$first2, $topic2, ccUtc(200)], [(int) $w->forum($forum)['last_post_id'], (int) $w->forum($forum)['last_topic_id'], $w->forum($forum)['last_post_at']], 'Forum zeigt jetzt den Themenstart des zweiten Themas (das Thema mit der jüngsten Aktivität)');
    ccAssertCounters($w, 'letzter Forumsbeitrag gelöscht');
});

T::run('Weiches Löschen eines Themas: antwortlos (Autor), has_replies, Moderation, wartend, Zähler und last_*', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $moderator = $w->db->user();
    $forum = $w->db->forum();
    $older = (int) $w->topics->create($b, $forum, 'Älteres Thema', 'Themenstart älter')['topic_id'];
    $w->at(60);
    $topic = (int) $w->topics->create($a, $forum, 'Jüngeres Thema', 'Themenstart jünger')['topic_id'];
    $w->hooks = [];
    $w->at(120);
    $w->clockReads = 0;

    $r = $w->topics->delete($a, $topic);
    T::same([true, 'published', 'deleted', $topic, $forum], [$r['success'] ?? null, $r['previous_status'] ?? null, $r['mode'] ?? null, $r['topic_id'] ?? null, $r['forum_id'] ?? null], 'Autor löscht sein antwortloses Thema');
    $t = $w->topic($topic);
    T::same(['deleted', 'published', ccUtc(120)], [$t['status'], $t['previous_status'], $t['updated_at']], 'Thema: Status deleted, previous_status published, updated_at');
    T::same('published', $w->post((int) $t['first_post_id'])['status'], 'die Beiträge des Themas bleiben unverändert');
    $f = $w->forum($forum);
    T::same([1, 1, $older], [(int) $f['topic_count'], (int) $f['post_count'], (int) $f['last_topic_id']], 'Forum: topic_count −1, post_count −1, „letztes Thema“ ist wieder das ältere');
    T::same([0, 0], [(int) $w->stats($a)['topic_count'], (int) $w->stats($a)['post_count']], 'Themenstarter: Themen- und Beitragszähler −1');
    T::same([[Events::TOPIC_DELETED, [$topic, $forum, $a, 'deleted'], false]], array_map(static fn (array $h): array => [$h['hook'], $h['args'], $h['in_transaction']], $w->hooks), 'Hook cms_community_topic_deleted genau einmal nach dem Commit');
    T::same(1, $w->clockReads, 'die Uhr wird genau einmal gelesen');
    ccAssertCounters($w, 'Thema gelöscht');
    T::same('deleted', $w->topics->get($a, $topic)['status'] ?? null, 'get liefert das gelöschte Thema (ob es lesbar ist, entscheidet die Rechteprüfung)');

    $w->hooks = [];
    $snapshot = ccSnapshot($w);
    $again = $w->topics->delete($a, $topic);
    T::same([true, true, $snapshot, []], [$again['success'] ?? null, $again['already_deleted'] ?? null, ccSnapshot($w), $w->hooks], 'wiederholtes Löschen: wirkungslos, kein Hook');

    // Mit Antworten: der Autor darf nicht (has_replies), die Moderation schon.
    $w->at(200);
    $busy = (int) $w->topics->create($a, $forum, 'Thema mit Antworten', 'Themenstart mit Antworten')['topic_id'];
    $w->at(260);
    $w->posts->reply($b, $busy, 'Antwort eins');
    $w->at(320);
    $w->posts->reply($b, $busy, 'Antwort zwei');
    $snapshot = ccSnapshot($w);
    T::same(['success' => false, 'error' => 'has_replies'], $w->topics->delete($a, $busy), 'Autor, keine Moderation: has_replies');
    T::same($snapshot, ccSnapshot($w), 'has_replies schreibt nichts');
    $w->moderator = true;
    $w->at(380);
    $mod = $w->topics->delete($moderator, $busy);
    T::same(true, $mod['success'] ?? null, 'Moderation löscht das Thema mit Antworten');
    $w->moderator = false;
    T::same([1, 1], [(int) $w->forum($forum)['topic_count'], (int) $w->forum($forum)['post_count']], 'Forum: topic_count −1, post_count −(Antworten + 1)');
    T::same($older, (int) $w->forum($forum)['last_topic_id'], 'Forum zeigt wieder das ältere Thema');
    T::same([], ccAudit($w, false), 'Forum- und Themenzähler stimmen');

    // Wartendes Thema: kein Zähler, previous_status pending.
    $w->at(440);
    $w->rule = ccPending();
    $pendingTopic = (int) $w->topics->create($b, $forum, 'Wartendes Thema', 'Wartender Start')['topic_id'];
    $w->rule = null;
    $counters = [$w->forum($forum)['topic_count'], $w->forum($forum)['post_count'], $w->forum($forum)['version'], $w->stats($b)['topic_count']];
    $w->at(500);
    $p = $w->topics->delete($b, $pendingTopic);
    T::same([true, 'pending'], [$p['success'] ?? null, $p['previous_status'] ?? null], 'wartendes Thema gelöscht, previous_status pending');
    T::same($counters, [$w->forum($forum)['topic_count'], $w->forum($forum)['post_count'], $w->forum($forum)['version'], $w->stats($b)['topic_count']], 'wartendes Thema ändert keine Zähler');

    T::same(['success' => false, 'error' => 'topic_not_found'], $w->topics->delete($a, 9999), 'unbekanntes Thema');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->topics->delete($a, 0), 'Thema-ID 0');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->topics->delete(0, $older), 'Gast: nichts gelöscht');
    T::same('published', $w->topic($older)['status'], 'älteres Thema unberührt');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Position und Seite
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Position und Seite: Beitrag 31 bei 15 je Seite liegt auf Seite 3', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $forum = $w->db->forum();
    $created = $w->topics->create($a, $forum, 'Langes Thema', 'Themenstart');
    $topic = (int) $created['topic_id'];
    $ids = [(int) $created['post_id']];
    $positions = [];
    for ($i = 2; $i <= 31; $i++) {
        $w->at($i * 60);
        $r = $w->posts->reply($a, $topic, 'Antwort Nummer ' . $i);
        $ids[] = (int) $r['post_id'];
        $positions[$i] = [$r['position'], $r['page']];
    }
    T::same([31, 3], $positions[31], 'Antwort 31: position 31, page 3');
    T::same([2, 1], $positions[2], 'Antwort 2: position 2, page 1');
    T::same([15, 1], $positions[15], 'Antwort 15: position 15, page 1 (Seitenende)');
    T::same([16, 2], $positions[16], 'Antwort 16: page 2 (Seitenanfang)');
    T::same([30, 2], $positions[30], 'Antwort 30: page 2');
    $consistent = true;
    foreach ($ids as $index => $id) {
        $consistent = $consistent && $w->posts->positionOf($topic, $id) === $index + 1;
    }
    T::same(true, $consistent, 'positionOf (COUNT) stimmt für alle 31 Beiträge mit der Zählung beim Antworten überein');
    T::same([31, 3, 1, 2, 2], [$w->posts->positionOf($topic, $ids[30]), $w->posts->pageOf($topic, $ids[30]), $w->posts->pageOf($topic, $ids[14]), $w->posts->pageOf($topic, $ids[15]), $w->posts->pageOf($topic, $ids[29])], 'pageOf = ceil(position / 15)');

    // Ein gelöschter Beitrag verschiebt die Positionen der späteren (nur veröffentlichte zählen).
    $w->at(2000);
    $w->posts->delete($a, $ids[4]);
    T::same([30, 2], [$w->posts->positionOf($topic, $ids[30]), $w->posts->pageOf($topic, $ids[30])], 'nach dem Löschen eines früheren Beitrags: Position 30, Seite 2');
});

T::run('Wirkung: posts_per_page (Seitengröße der Beitragsliste, Seite eines Beitrags)', static function (): void {
    foreach ([[null, 15, 3, 31, 3], ['5', 5, 7, 31, 7], [10, 10, 4, 31, 4], [100, 31, 1, 31, 1], [3, 5, 7, 31, 7]] as [$setting, $pageSize, $page31, $position, $expected]) {
        $w = new CcWorld($setting === null ? [] : ['posts_per_page' => $setting]);
        $a = $w->db->user();
        $forum = $w->db->forum();
        [$topic, $ids] = ccSeedTopic($w, $forum, $a, 31);
        $list = $w->posts->listPage($a, $topic);
        T::same($pageSize, count($list['items'] ?? []), 'posts_per_page ' . var_export($setting, true) . ': ' . $pageSize . ' Beiträge je Seite');
        T::same($expected, $w->posts->pageOf($topic, $ids[30]), 'posts_per_page ' . var_export($setting, true) . ': Beitrag 31 liegt auf Seite ' . $expected);
        T::same($position, $w->posts->positionOf($topic, $ids[30]), 'die Position hängt nicht von der Seitengröße ab');
    }
    $w = new CcWorld(['posts_per_page' => 10]);
    $a = $w->db->user();
    $forum = $w->db->forum();
    $topic = (int) $w->topics->create($a, $forum, 'Thema mit zehn je Seite', 'Themenstart')['topic_id'];
    $page = null;
    for ($i = 2; $i <= 11; $i++) {
        $w->at($i * 60);
        $page = $w->posts->reply($a, $topic, 'Antwort ' . $i)['page'] ?? null;
    }
    T::same(2, $page, 'reply liefert die Seite nach posts_per_page (Position 11 bei 10 je Seite: Seite 2)');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Keyset-Listen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * @param list<array<string, mixed>> $rows
 * @return list<int>
 */
function ccIds(array $rows): array
{
    return array_map(static fn (array $row): int => (int) $row['id'], $rows);
}

T::run('Themenliste (Keyset): Cursor, has_more, Gleichstände, Pins nur auf der ersten Seite, nur Veröffentlichtes', static function (): void {
    $w = new CcWorld();
    $u = $w->db->user();
    $forum = $w->db->forum();
    $other = $w->db->forum();
    $w->at(5000);
    // Strom: sieben Themen mit nur drei verschiedenen Zeitstempeln (Gleichstände), dazu ein Thema mit abgelaufener Anheftung.
    $times = [0, 0, 60, 60, 60, 120, 120];
    $stream = [];
    foreach ($times as $offset) {
        $stream[] = $w->db->topic(['forum' => $forum, 'user' => $u, 'created_at' => ccUtc($offset), 'last_post_at' => ccUtc($offset)]);
    }
    $expired = $w->db->topic(['forum' => $forum, 'user' => $u, 'created_at' => ccUtc(0), 'last_post_at' => ccUtc(30), 'pinned' => true, 'pinned_until' => ccUtc(10)]);
    $pinned = $w->db->topic(['forum' => $forum, 'user' => $u, 'created_at' => ccUtc(0), 'last_post_at' => ccUtc(-1000), 'pinned' => true]);
    $w->db->topic(['forum' => $forum, 'user' => $u, 'status' => 'hidden', 'last_post_at' => ccUtc(200)]);
    $w->db->topic(['forum' => $forum, 'user' => $u, 'status' => 'pending', 'last_post_at' => ccUtc(200)]);
    $w->db->topic(['forum' => $forum, 'user' => $u, 'status' => 'deleted', 'last_post_at' => ccUtc(200)]);
    $w->db->topic(['forum' => $other, 'user' => $u, 'last_post_at' => ccUtc(200)]);
    $expectedStream = [$stream[6], $stream[5], $stream[4], $stream[3], $stream[2], $expired, $stream[1], $stream[0]];

    $page = $w->topics->listForum($u, $forum, null, 3);
    T::same(true, $page['success'] ?? null, 'listForum gelingt');
    T::same([$pinned, $stream[6], $stream[5], $stream[4]], ccIds($page['items'] ?? []), 'erste Seite: angepinnte Themen zuerst, dann drei Themen des Stroms');
    T::same(true, $page['has_more'] ?? null, 'has_more auf der ersten Seite');
    T::check(is_string($page['next'] ?? null) && Cursor::decode($page['next']) !== null, 'next ist ein gültiger Cursor');
    $collected = ccIds(array_slice($page['items'], 1));
    $pages = 1;
    $cursor = $page['next'];
    while ($cursor !== null && $pages < 10) {
        $p = $w->topics->listForum($u, $forum, $cursor, 3);
        T::check(!in_array($pinned, ccIds($p['items'] ?? []), true), 'Folgeseite ' . ($pages + 1) . ' enthält keine angepinnten Themen');
        $collected = array_merge($collected, ccIds($p['items'] ?? []));
        $cursor = $p['next'] ?? null;
        T::same($cursor !== null, $p['has_more'] ?? null, 'has_more entspricht next (Seite ' . ($pages + 1) . ')');
        $pages++;
    }
    T::same(3, $pages, 'drei Seiten bei Seitengröße 3 (8 Themen im Strom)');
    T::same($expectedStream, $collected, 'jede Zeile genau einmal, Reihenfolge = Gesamtliste (trotz gleicher Zeitstempel); abgelaufene Anheftung gehört zum Strom');
    $items = $page['items'];
    T::same([1, 0], [$items[0]['is_pinned'], $items[1]['is_pinned']], 'is_pinned ist ein Ganzzahlwert (wirksamer Zustand)');
    T::same([0], [$w->topics->listForum($u, $forum, $page['next'], 3)['items'][2]['is_pinned'] ?? 'x'], 'abgelaufen angeheftet: is_pinned 0');

    T::same(ccIds($page['items']), ccIds($w->topics->listForum($u, $forum, 'kein-gueltiger-cursor!', 3)['items']), 'ungültiger Cursor: erste Seite');
    $forged = (new Cursor(ccUtc(-5000), 1))->encode();
    $end = $w->topics->listForum($u, $forum, $forged, 3);
    T::same([[], null, false], [ccGet($end, 'items'), ccGet($end, 'next'), ccGet($end, 'has_more')], 'Cursor hinter dem Ende: leer, kein weiterer Cursor');
    T::same(2, count(array_filter($w->topics->listForum($u, $forum, null, 2)['items'], static fn (array $t): bool => (int) $t['is_pinned'] === 0)), 'limit 2: zwei Themen des Stroms');
    T::same(1, count(array_filter($w->topics->listForum($u, $forum, null, 0)['items'], static fn (array $t): bool => (int) $t['is_pinned'] === 0)), 'limit 0 wird auf 1 geklemmt');
    T::same(['success' => false, 'error' => 'forum_not_found'], $w->topics->listForum($u, 9999), 'unbekanntes Forum');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->topics->listForum($u, 0), 'Forum-ID 0');
});

T::run('Wirkung: topics_per_page (Seitengröße der Themenliste, höchstens 100)', static function (): void {
    foreach ([[null, 20, true], [5, 5, true], [7, 7, true], [100, 25, false], [1, 5, true]] as [$setting, $expected, $more]) {
        $w = new CcWorld($setting === null ? [] : ['topics_per_page' => $setting]);
        $u = $w->db->user();
        $forum = $w->db->forum();
        for ($i = 0; $i < 25; $i++) {
            $w->db->topic(['forum' => $forum, 'user' => $u, 'last_post_at' => ccUtc($i)]);
        }
        $page = $w->topics->listForum($u, $forum);
        T::same([$expected, $more], [count($page['items'] ?? []), $page['has_more'] ?? null], 'topics_per_page ' . var_export($setting, true) . ': ' . $expected . ' Themen' . ($more ? ' und weitere Seite' : ', keine weitere Seite'));
    }
    $w = new CcWorld(['topics_per_page' => 5]);
    $u = $w->db->user();
    $forum = $w->db->forum();
    for ($i = 0; $i < 105; $i++) {
        $w->db->topic(['forum' => $forum, 'user' => $u, 'last_post_at' => ccUtc($i)]);
    }
    T::same(9, count($w->topics->listForum($u, $forum, null, 9)['items'] ?? []), 'ein ausdrückliches limit geht der Einstellung vor');
    $big = $w->topics->listForum($u, $forum, null, 5000);
    T::same([100, true], [count($big['items'] ?? []), $big['has_more'] ?? null], 'ein übergroßes limit wird auf 100 geklemmt');
});

T::run('Beitragsliste (Keyset nach ID): Seiten, Platzhalter, Ansicht full/placeholder, nichts Internes', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $c = $w->db->user();
    $forum = $w->db->forum();
    $topic = $w->db->topic(['forum' => $forum, 'user' => $a]);
    $p = [];
    foreach ([
        'p1' => [$a, 'published', null], 'p2' => [$b, 'hidden', 'Werbung'], 'p3' => [$a, 'published', null], 'p4' => [$a, 'deleted', 'author'],
        'p5' => [$b, 'published', null], 'p6' => [$a, 'published', null], 'p7' => [$b, 'deleted', 'moderator'], 'p8' => [$a, 'pending', null], 'p9' => [$a, 'rejected', 'Spam'],
    ] as $key => [$user, $status, $reason]) {
        $p[$key] = $w->db->post(['topic' => $topic, 'user' => $user, 'status' => $status, 'status_reason' => $reason, 'first' => $key === 'p1', 'body' => 'Text von ' . $key, 'created_at' => ccUtc((int) substr($key, 1))]);
    }
    $w->db->execute('UPDATE cms_community_posts SET ip_hash = ? WHERE id = ?', [str_repeat('a', 64), $p['p1']]);
    $w->rule = static function (?int $viewer, string $action, array $ctx): ?AccessDecision {
        if ($action !== 'read' || !isset($ctx['post'])) {
            return null;
        }
        $post = $ctx['post'];
        $full = $post['status'] === 'published' || ($post['status'] === 'hidden' && $viewer === (int) $post['user_id']);

        return AccessDecision::allow(null, $full ? 'full' : 'placeholder');
    };

    $page1 = $w->posts->listPage($c, $topic, null, 2);
    T::same(true, $page1['success'] ?? null, 'listPage gelingt');
    T::same([$p['p1'], $p['p2'], $p['p3']], ccIds($page1['items'] ?? []), 'Seite 1: zwei veröffentlichte Beiträge und der Platzhalter dazwischen');
    T::same([$p['p3'], true], [$page1['next'] ?? null, $page1['has_more'] ?? null], 'next = ID des letzten veröffentlichten Beitrags der Seite, has_more');
    $page2 = $w->posts->listPage($c, $topic, $page1['next'], 2);
    T::same([$p['p4'], $p['p5'], $p['p6'], $p['p7']], ccIds($page2['items'] ?? []), 'Seite 2 (letzte): Platzhalter dazwischen und am Ende, wartende und abgelehnte Beiträge fehlen');
    T::same([null, false], [ccGet($page2, 'next'), ccGet($page2, 'has_more')], 'letzte Seite: next null, has_more false');
    T::same(ccIds(array_merge($page1['items'], $page2['items'])), array_values(array_unique(ccIds(array_merge($page1['items'], $page2['items'])))), 'jeder Beitrag genau einmal');

    $views = array_column($page1['items'], 'view', 'id') + array_column($page2['items'], 'view', 'id');
    T::same(
        [$p['p1'] => 'full', $p['p2'] => 'placeholder', $p['p3'] => 'full', $p['p4'] => 'placeholder', $p['p5'] => 'full', $p['p6'] => 'full', $p['p7'] => 'placeholder'],
        $views,
        'Ansicht für ein fremdes Mitglied: veröffentlicht full, ausgeblendet/gelöscht placeholder'
    );
    $placeholder = $page1['items'][1];
    T::same(['view', 'id', 'topic_id', 'forum_id', 'is_first_post', 'status', 'status_reason', 'created_at'], array_keys($placeholder), 'Platzhalter hat nur Status und Grund, keinen Text und keinen Autor');
    T::same(['hidden', 'Werbung'], [$placeholder['status'], $placeholder['status_reason']], 'Platzhalter nennt Status und Grund');
    $full = $page1['items'][0];
    T::check(!array_key_exists('ip_hash', $full) && ($full['body'] ?? '') === 'Text von p1' && $full['id'] === $p['p1'] && $full['is_first_post'] === 1, 'volle Ansicht: Text vorhanden, ip_hash nie dabei, Ganzzahlfelder typisiert');

    $own = $w->posts->listPage($b, $topic, null, 2);
    $ownItem = $own['items'][1] ?? [];
    T::same(['full', 'Text von p2', 'hidden'], [$ownItem['view'] ?? null, $ownItem['body'] ?? null, $ownItem['status'] ?? null], 'der Autor sieht seinen ausgeblendeten Beitrag voll (Text wird nachgeladen)');
    T::same('placeholder', $w->posts->listPage($b, $topic, $page1['next'], 2)['items'][0]['view'] ?? null, 'gelöschter Beitrag: Platzhalter');
    T::same([$p['p1'], $p['p2'], $p['p3'], $p['p4'], $p['p5'], $p['p6'], $p['p7']], ccIds($w->posts->listPage(null, $topic, null, 100)['items'] ?? []), 'limit 100: alles auf einer Seite, Gast darf lesen (Rechteprüfung erlaubt)');
    T::same([], $w->posts->listPage($c, $topic, $p['p7'])['items'] ?? 'x', 'Cursor hinter dem Ende: leer');

    $w->rule = ccDenyActions(['read'], 'not_found');
    T::same(['success' => false, 'error' => 'not_found'], $w->posts->listPage($c, $topic), 'ohne Leserecht: Ablehnung der Rechteprüfung, keine Daten');
    $w->db->log = [];
    $w->posts->listPage($c, $topic);
    T::same([], array_filter($w->db->log, static fn (string $sql): bool => str_contains($sql, 'community_posts')), 'ohne Leserecht wird die Beitragstabelle nicht gelesen');
    T::same(['success' => false, 'error' => 'invalid_input'], $w->posts->listPage($c, 0), 'Thema-ID 0');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Lesestatus
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Lesestatus: nur steigend, Klemmung, Gäste und fehlendes Leserecht schreiben nichts', static function (): void {
    $w = new CcWorld();
    $author = $w->db->user();
    $r = $w->db->user();
    $forum = $w->db->forum();
    [$topic, $p] = ccSeedTopic($w, $forum, $author, 4);
    $state = static fn (int $user, int $t): ?array => $w->db->row('community_read_states', ['user_id' => $user, 'topic_id' => $t]);

    T::same(true, $w->reads->advance($r, $topic, $p[1]), 'advance legt die Zeile an');
    T::same([$p[1], ccUtc()], [(int) $state($r, $topic)['last_read_post_id'], $state($r, $topic)['updated_at']], 'Lesestatus auf Beitrag 2, Zeit UTC');
    $w->at(500);
    T::same(false, $w->reads->advance($r, $topic, $p[1]), 'gleicher Wert: false');
    T::same(false, $w->reads->advance($r, $topic, $p[0]), 'kleinerer Wert: false (nur steigend)');
    T::same([$p[1], ccUtc()], [(int) $state($r, $topic)['last_read_post_id'], $state($r, $topic)['updated_at']], 'Wert und updated_at unverändert (Wiederbesuch schreibt nichts)');
    T::same(true, $w->reads->advance($r, $topic, $p[3]), 'größerer Wert: true');
    T::same([$p[3], ccUtc(500)], [(int) $state($r, $topic)['last_read_post_id'], $state($r, $topic)['updated_at']], 'Lesestatus steigt');
    T::same(false, $w->reads->advance($r, $topic, $p[2]), 'danach wieder kleiner: false');
    T::same($p[3], (int) $state($r, $topic)['last_read_post_id'], 'bleibt auf dem höchsten Wert');

    [$topic2, $q] = ccSeedTopic($w, $forum, $author, 2, 1000);
    T::same(true, $w->reads->advance($r, $topic2, 99999999), 'eine zu große Beitrags-ID wird angenommen, aber geklemmt');
    T::same($q[1], (int) $state($r, $topic2)['last_read_post_id'], 'Klemmung auf den letzten veröffentlichten Beitrag des Themas');

    $before = $w->db->count('community_read_states');
    $asked = count($w->canLog);
    T::same([false, false, false], [$w->reads->advance(0, $topic, $p[3]), $w->reads->markForumRead(0, $forum), $w->reads->markTopicPageRead(0, $topic, [$p[3]])], 'Gast: nichts geschrieben');
    T::same($asked, count($w->canLog), 'Gäste lösen nicht einmal eine Rechteprüfung aus');
    T::same([false, false, false], [$w->reads->advance($r, 0, $p[3]), $w->reads->advance($r, $topic, 0), $w->reads->advance($r, 9999, 5)], 'Thema 0, Beitrag 0, unbekanntes Thema: nichts');
    $emptyTopic = $w->db->topic(['forum' => $forum, 'user' => $author, 'status' => 'pending']);
    T::same(false, $w->reads->advance($r, $emptyTopic, 5), 'Thema ohne veröffentlichten Beitrag: nichts');
    $w->rule = static fn (?int $u, string $action, array $ctx): ?AccessDecision => $action === 'read' ? AccessDecision::deny('not_found') : null;
    T::same(false, $w->reads->advance($r, $topic2, $q[1] + 1), 'ohne Leserecht: nichts');
    T::same(false, $w->reads->markForumRead($r, $forum), 'ohne Leserecht: keine Foren-Marke');
    $w->rule = null;
    T::same($before, $w->db->count('community_read_states'), 'keine neuen Zeilen');
    T::same(0, $w->db->count('community_read_states', ['user_id' => 0]), 'für Gäste gibt es keine Lesestatuszeile');

    [$topic3, $s] = ccSeedTopic($w, $forum, $author, 4, 2000);
    T::same(true, $w->reads->markTopicPageRead($r, $topic3, [$s[1], $s[3], $s[2]]), 'markTopicPageRead: Maximum der sichtbaren IDs');
    T::same($s[3], (int) $state($r, $topic3)['last_read_post_id'], 'Lesestatus = größte ID der Seite');
    T::same(false, $w->reads->markTopicPageRead($r, $topic3, []), 'leere Seite: nichts');
    T::same(false, $w->reads->markTopicPageRead($r, $topic3, [0, -4]), 'ungültige IDs: nichts');
});

T::run('Lesestatus: Foren-Marke (gilt für Unterforen, nur steigend) und Ungelesen-Regel', static function (): void {
    $w = new CcWorld();
    $author = $w->db->user();
    $r = $w->db->user();
    $forum = $w->db->forum();
    $child = $w->db->forum(['parent' => $forum]);
    $other = $w->db->forum();
    $w->at(0);
    [$tForum] = ccSeedTopic($w, $forum, $author, 2, -864000);
    [$tChild] = ccSeedTopic($w, $child, $author, 1, -432000);
    [$tOther] = ccSeedTopic($w, $other, $author, 1, -432000);
    [$tOld] = ccSeedTopic($w, $forum, $author, 1, -100 * 86400);
    [$tPending] = ccSeedTopic($w, $forum, $author, 1, -3600, ['status' => 'pending']);
    $rows = static fn (): array => $w->db->rows('community_topics', [], 'id');

    $map = $w->reads->unreadMap($r, $rows());
    T::same([$tForum => true, $tChild => true, $tOther => true, $tOld => false, $tPending => false], $map, 'ohne Marken: neue Themen ungelesen, Thema hinter dem Horizont (100 Tage) und wartendes Thema gelesen');
    T::same(array_fill_keys(array_keys($map), false), $w->reads->unreadMap(0, $rows()), 'Gäste: nichts ungelesen');
    T::same([], $w->reads->unreadMap($r, []), 'leere Liste: leere Karte');

    $topicRow = $w->topic($tForum);
    $w->reads->advance($r, $tForum, (int) $topicRow['last_post_id']);
    T::same(false, $w->reads->unreadMap($r, [$topicRow])[$tForum], 'Thema bis zum letzten Beitrag gelesen: gelesen');
    $w->db->execute('UPDATE cms_community_topics SET last_post_id = last_post_id + 1000 WHERE id = ?', [$tForum]);
    T::same(true, $w->reads->unreadMap($r, [$w->topic($tForum)])[$tForum], 'neuer Beitrag nach dem Lesen: wieder ungelesen');
    $w->db->execute('UPDATE cms_community_topics SET last_post_id = last_post_id - 1000 WHERE id = ?', [$tForum]);

    T::same(true, $w->reads->markForumRead($r, $forum), 'Forum als gelesen markieren');
    T::same([ccUtc()], [$w->db->row('community_forum_read_marks', ['user_id' => $r, 'forum_id' => $forum])['marked_at'] ?? null], 'Marke = UTC-Zeit der Uhr');
    $after = $w->reads->unreadMap($r, $rows());
    T::same([$tForum => false, $tChild => false, $tOther => true, $tOld => false, $tPending => false], $after, 'Marke gilt für das Forum und sein Unterforum, nicht für andere Foren');
    T::same(false, $w->reads->markForumRead($r, $forum), 'dieselbe Sekunde: Marke steigt nicht (false)');
    $w->at(100);
    [$tNew] = ccSeedTopic($w, $forum, $author, 1, 50);
    T::same(true, $w->reads->unreadMap($r, [$w->topic($tNew)])[$tNew], 'Thema mit Aktivität nach der Marke: ungelesen');
    T::same(true, $w->reads->markForumRead($r, $forum), 'später erneut markiert: steigt');
    T::same([ccUtc(100)], [$w->db->row('community_forum_read_marks', ['user_id' => $r, 'forum_id' => $forum])['marked_at'] ?? null], 'Marke steigt auf die neue Zeit');
    T::same(false, $w->reads->unreadMap($r, [$w->topic($tNew)])[$tNew], 'jetzt gelesen');

    T::same(true, $w->reads->markForumRead($r, 0), 'globale Marke (forum_id 0)');
    T::same(array_fill_keys(ccIds($rows()), false), $w->reads->unreadMap($r, $rows()), 'globale Marke deckt alle Foren ab');
    T::same([false, false, false], [$w->reads->markForumRead(0, $forum), $w->reads->markForumRead($r, 9999), $w->reads->markForumRead($r, -1)], 'Gast, unbekanntes Forum, negative ID: nichts');
});

T::run('Wirkung: unread_horizon_days (Aktivität vor dem Horizont gilt als gelesen; Grenze exakt)', static function (): void {
    foreach ([[null, 90], [30, 30], [180, 180], [1, 1], [500, 180]] as [$setting, $effective]) {
        $w = new CcWorld($setting === null ? [] : ['unread_horizon_days' => $setting]);
        $author = $w->db->user();
        $r = $w->db->user();
        $forum = $w->db->forum();
        // Thema genau am Horizont (gilt als gelesen: nicht „später als“), eine Sekunde danach (ungelesen), eine Sekunde davor (gelesen).
        [$edge] = ccSeedTopic($w, $forum, $author, 1, -$effective * 86400);
        [$inside] = ccSeedTopic($w, $forum, $author, 1, -$effective * 86400 + 1);
        [$outside] = ccSeedTopic($w, $forum, $author, 1, -$effective * 86400 - 1);
        $rows = $w->db->rows('community_topics', [], 'id');
        T::same([$edge => false, $inside => true, $outside => false], $w->reads->unreadMap($r, $rows), 'unread_horizon_days ' . var_export($setting, true) . ' (wirksam ' . $effective . '): Grenze exakt bei jetzt − ' . $effective . ' Tagen');
        T::same(1, $w->reads->unreadTopicCount($r, $forum), 'unreadTopicCount folgt demselben Horizont (' . $effective . ' Tage)');
        T::same([null, null, true], [$w->reads->firstUnreadPostId($r, $edge), $w->reads->firstUnreadPostId($r, $outside), $w->reads->firstUnreadPostId($r, $inside) !== null], 'firstUnreadPostId folgt demselben Horizont');
    }
});

T::run('Lesestatus: erster ungelesener Beitrag und Ungelesen-Zähler eines Forums', static function (): void {
    $w = new CcWorld();
    $author = $w->db->user();
    $r = $w->db->user();
    $forum = $w->db->forum();
    $child = $w->db->forum(['parent' => $forum]);
    $w->at(0);
    [$topic, $p] = ccSeedTopic($w, $forum, $author, 5, -5000);
    $w->db->execute("UPDATE cms_community_posts SET status = 'hidden' WHERE id = ?", [$p[2]]);

    T::same($p[0], $w->reads->firstUnreadPostId($r, $topic), 'nie geöffnet: der Themenstart');
    $w->reads->advance($r, $topic, $p[1]);
    T::same($p[3], $w->reads->firstUnreadPostId($r, $topic), 'bis Beitrag 2 gelesen: Beitrag 3 ist ausgeblendet, der erste ungelesene ist Beitrag 4');
    $w->reads->advance($r, $topic, $p[4]);
    T::same(null, $w->reads->firstUnreadPostId($r, $topic), 'alles gelesen: null');
    T::same([null, null, null], [$w->reads->firstUnreadPostId(0, $topic), $w->reads->firstUnreadPostId($r, 9999), $w->reads->firstUnreadPostId($r, 0)], 'Gast, unbekanntes Thema, Thema 0: null');
    $w->rule = static fn (?int $u, string $action, array $ctx): ?AccessDecision => $action === 'read' ? AccessDecision::deny('not_found') : null;
    T::same(null, $w->reads->firstUnreadPostId($r, ccSeedTopic($w, $forum, $author, 2, -100)[0]), 'ohne Leserecht: null');
    T::same(0, $w->reads->unreadTopicCount($r, $forum), 'ohne Leserecht: Zähler 0');
    $w->rule = null;

    // Zähler: zwei weitere ungelesene Themen, eines im Unterforum (zählt nicht mit), eines gelesen.
    [$t2] = ccSeedTopic($w, $forum, $author, 2, -4000);
    [$t3] = ccSeedTopic($w, $forum, $author, 1, -3000);
    ccSeedTopic($w, $child, $author, 1, -3000);
    T::same(3, $w->reads->unreadTopicCount($r, $forum), 'drei ungelesene Themen im Forum (das Unterforum zählt nicht mit)');
    T::same(1, $w->reads->unreadTopicCount($r, $forum, 1), 'Obergrenze cap 1');
    T::same(2, $w->reads->unreadTopicCount($r, $forum, 2), 'Obergrenze cap 2');
    T::same(1, $w->reads->unreadTopicCount($r, $child), 'das Unterforum zählt für sich');
    $w->reads->advance($r, $t3, (int) $w->topic($t3)['last_post_id']);
    T::same(2, $w->reads->unreadTopicCount($r, $forum), 'ein Thema gelesen: zwei bleiben');
    $w->reads->markForumRead($r, $forum);
    T::same([0, 0], [$w->reads->unreadTopicCount($r, $forum), $w->reads->unreadTopicCount($r, $child)], 'Foren-Marke: Forum und Unterforum ohne Ungelesenes');
    T::same([0, 0, 0], [$w->reads->unreadTopicCount(0, $forum), $w->reads->unreadTopicCount($r, 0), $w->reads->unreadTopicCount($r, 9999)], 'Gast, Forum 0, unbekanntes Forum: 0');
    T::check($t2 > 0, 'Vorbedingung');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Zitate
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Zitate: build (Format, Auswahl, verschachtelt, Länge, Rechte und Status)', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $topic = $w->db->topic(['forum' => $forum, 'user' => $a]);
    $post = static fn (string $body, string $status = 'published'): int => $w->db->post(['topic' => $topic, 'user' => $a, 'body' => $body, 'status' => $status]);

    $simple = $post("Zeile eins\r\nZeile zwei");
    T::same("> [quote=post:{$simple}]\n> Zeile eins\n> Zeile zwei", $w->quotes->build($simple, $b), 'Format: Markerzeile, dann der Text mit „> “ je Zeile, kein Umbruch am Ende');
    T::same("> [quote=post:{$simple}]\n> Nur dies", $w->quotes->build($simple, $b, "  Nur dies \r\n"), 'die Auswahl ersetzt den Beitragstext');
    T::same("> [quote=post:{$simple}]\n> Zeile eins\n> Zeile zwei", $w->quotes->build($simple, $b, '   '), 'leere Auswahl: ganzer Beitrag');
    $blank = $post("A\n\nB");
    T::same("> [quote=post:{$blank}]\n> A\n>\n> B", $w->quotes->build($blank, $b), 'Leerzeile im Text bleibt Teil des Zitats');
    $nested = $post("> [quote=post:7]\n> Alter Text\n\nEigener Text");
    T::same("> [quote=post:{$nested}]\n> Eigener Text", $w->quotes->build($nested, $b), 'verschachtelte Zitate der Quelle entfallen (Tiefe 1)');
    T::same(null, $w->quotes->build($post("> [quote=post:7]\n> nur ein Zitat"), $b), 'Beitrag nur aus einem Zitat: nichts zu zitieren');
    $control = $post("a\x07b\tc");
    T::same("> [quote=post:{$control}]\n> ab\tc", $w->quotes->build($control, $b), 'Steuerzeichen entfallen');
    $long = $post(str_repeat('x', 2500));
    T::same("> [quote=post:{$long}]\n> " . str_repeat('x', QuoteService::MAX_LENGTH) . "\n> " . QuoteService::OMISSION, $w->quotes->build($long, $b), 'lange Texte: auf 2000 Zeichen gekürzt, Auslassung „[…]“');
    $exact = $post(str_repeat('y', 2000));
    T::same("> [quote=post:{$exact}]\n> " . str_repeat('y', 2000), $w->quotes->build($exact, $b), 'genau 2000 Zeichen: nicht gekürzt');
    T::same(true, str_contains((string) $w->quotes->build($simple, $b, str_repeat('ä', 3000)), QuoteService::OMISSION), 'auch eine lange Auswahl wird gekürzt');

    T::same([null, null], [$w->quotes->build(0, $b), $w->quotes->build(9999, $b)], 'Beitrag 0 und unbekannter Beitrag: null');
    foreach (['pending', 'deleted', 'rejected', 'hidden'] as $status) {
        T::same(null, $w->quotes->build($post('Text im Status ' . $status, $status), $b), 'Status ' . $status . ': nicht zitierbar');
    }
    $hidden = $post('Ausgeblendeter Text', 'hidden');
    $w->moderator = true;
    T::same("> [quote=post:{$hidden}]\n> Ausgeblendeter Text", $w->quotes->build($hidden, $b), 'ausgeblendet: nur für die Moderation zitierbar');
    T::same(null, $w->quotes->build($post('Gelöscht', 'deleted'), $b), 'gelöscht bleibt für alle tabu');
    $w->moderator = false;
    $w->rule = ccDenyActions(['read'], 'not_found');
    T::same(null, $w->quotes->build($simple, $b), 'ohne Leserecht: nicht zitierbar');
    $w->rule = static fn (?int $u, string $action, array $ctx): ?AccessDecision => $action === 'read' ? AccessDecision::allow(null, 'placeholder') : null;
    T::same(null, $w->quotes->build($simple, $b), 'nur als Platzhalter sichtbar: nicht zitierbar');
    $w->rule = null;
    T::same(true, $w->quotes->isQuotable($simple, $b), 'isQuotable: dieselbe Regel');
    T::same(false, $w->quotes->isQuotable($post('Wartend', 'pending'), $b), 'isQuotable: wartender Beitrag nicht');
    $lastCall = array_values(array_filter($w->canLog, static fn (array $c): bool => $c['action'] === 'read'))[0] ?? null;
    T::same([$b, $simple], [$lastCall['user'] ?? null, $lastCall['context']['post_id'] ?? null], 'die Rechteprüfung bekommt Betrachter und Beitrag');
});

T::run('Zitate: extractRefs und neutralize (Anzeigezeit, wiederholbar, nur Marker am Zeilenanfang)', static function (): void {
    $text = "> [quote=post:5]\n> x\n\n> [quote=post:12]\n> y\n> [quote=post:5]\n> z\n> [quote=post:abc]\n> [quote=post:7] mit Text\n [quote=post:9]\nText > [quote=post:11]";
    T::same([5, 12], QuoteService::extractRefs($text), 'extractRefs: nur Markerzeilen, in Reihenfolge, ohne Doppelte');
    T::same([], QuoteService::extractRefs("Kein Zitat\n> normales Blockquote"), 'extractRefs: kein Marker');
    T::same([5], QuoteService::extractRefs("> [quote=post:5]\r\n> x"), 'extractRefs: Windows-Zeilenenden');

    $markdown = "Vorwort\n\n> [quote=post:5]\n> geheim\n> noch mehr\n\nMein Text\n\n> [quote=post:6]\n> sichtbar\n\n> [quote=post:7]\n> entfernt\n\n> [quote=post:5]\n> geheim, zweites Mal\n\n> normales Blockquote";
    $asked = [];
    $visible = static function (int $id) use (&$asked): bool|string {
        $asked[] = $id;

        return match ($id) {
            6 => true,
            7 => QuoteService::REMOVED,
            default => false,
        };
    };
    $expected = "Vorwort\n\n> [quote=post:5]\n> [Zitat aus einem nicht sichtbaren Bereich]\n\nMein Text\n\n> [quote=post:6]\n> sichtbar\n\n> [quote=post:7]\n> [Zitat entfernt]\n\n> [quote=post:5]\n> [Zitat aus einem nicht sichtbaren Bereich]\n\n> normales Blockquote";
    $out = QuoteService::neutralize($markdown, $visible);
    T::same($expected, $out, 'neutralize: nicht sichtbare Quellen durch Platzhalter ersetzt, sichtbare und fremde Blockquotes bleiben');
    T::same([5, 6, 7], $asked, 'je Beitrags-ID wird einmal gefragt (post:5 steht zweimal im Text)');
    T::same($out, QuoteService::neutralize($out, $visible), 'wiederholbar: neutralize(neutralize(x)) = neutralize(x)');
    T::same($markdown, QuoteService::neutralize($markdown, static fn (int $id): bool => true), 'alles sichtbar: Text unverändert');
    T::same("> [quote=post:5]\n> [Zitat aus einem nicht sichtbaren Bereich]\n> [quote=post:6]\n> b", QuoteService::neutralize("> [quote=post:5]\n> a\n> [quote=post:6]\n> b", static fn (int $id): bool => $id === 6), 'aufeinanderfolgende Zitate: ein Marker beendet den vorigen Block');
    T::same("> [quote=post:5]\n> [Hidden]", QuoteService::neutralize("> [quote=post:5]\r\n> a", static fn (int $id): bool => false, '[Hidden]'), 'eigener Platzhaltertext; Windows-Zeilenenden werden \\n');
    T::same("> [quote=post:5]\n> [Gone]", QuoteService::neutralize("> [quote=post:5]\n> a", static fn (int $id): string => QuoteService::REMOVED, '[Hidden]', '[Gone]'), 'eigener Text für entfernte Quellen');
    T::same("> [quote=post:5] mit Text\n> a", QuoteService::neutralize("> [quote=post:5] mit Text\n> a", static fn (int $id): bool => false), 'kein Marker, wenn Text in der Zeile folgt: unverändert');
    T::same("a\nb", QuoteService::stripQuotes("a\n> [quote=post:5]\n> x\nb"), 'stripQuotes entfernt den Block');
});

T::run('Zitate: Auflösung aus den Rechten und Verwendung beim Antworten (quote_of_post_id)', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $topic = $w->db->topic(['forum' => $forum, 'user' => $a]);
    $published = $w->db->post(['topic' => $topic, 'user' => $a, 'body' => 'Veröffentlicht']);
    $pending = $w->db->post(['topic' => $topic, 'user' => $a, 'status' => 'pending']);
    $hidden = $w->db->post(['topic' => $topic, 'user' => $a, 'status' => 'hidden']);
    $deleted = $w->db->post(['topic' => $topic, 'user' => $a, 'status' => 'deleted']);
    $resolver = $w->quotes->resolver($b);
    T::same([true, false, QuoteService::REMOVED, QuoteService::REMOVED, false], [$resolver($published), $resolver($pending), $resolver($hidden), $resolver($deleted), $resolver(9999)], 'resolver: veröffentlicht sichtbar, wartend und unbekannt nicht, ausgeblendet/gelöscht entfernt');
    $w->rule = ccDenyActions(['read'], 'not_found');
    T::same(false, $w->quotes->resolver($b)($published), 'resolver: ohne Leserecht nicht sichtbar');
    $w->rule = static fn (?int $u, string $action, array $ctx): ?AccessDecision => $action === 'read' ? AccessDecision::allow(null, 'placeholder') : null;
    T::same(false, $w->quotes->resolver($b)($published), 'resolver: nur Platzhalter ist nicht sichtbar');
    $w->rule = null;

    $target = (int) $w->topics->create($b, $forum, 'Thema mit Zitat', 'Themenstart des Zitierenden')['topic_id'];
    $w->at(60);
    $r = $w->posts->reply($b, $target, "> [quote=post:{$published}]\n> Veröffentlicht\n\nMeine Antwort", ['quote_of_post_id' => $published]);
    T::same([true, $published], [$r['success'] ?? null, (int) $w->post((int) $r['post_id'])['quote_of_post_id']], 'quote_of_post_id wird gespeichert');
    $w->at(120);
    $plain = $w->posts->reply($b, $target, 'Antwort ohne Zitat');
    T::same(null, $w->post((int) $plain['post_id'])['quote_of_post_id'], 'ohne Option: NULL');
    $snapshot = ccSnapshot($w);
    $w->at(180);
    T::same(['success' => false, 'error' => 'quote_unavailable'], $w->posts->reply($b, $target, 'Antwort mit verbotenem Zitat', ['quote_of_post_id' => $pending]), 'nicht zitierbarer Beitrag: quote_unavailable');
    T::same(['success' => false, 'error' => 'quote_unavailable'], $w->posts->reply($b, $target, 'Antwort mit unbekanntem Zitat', ['quote_of_post_id' => 9999]), 'unbekannter Beitrag: quote_unavailable');
    T::same($snapshot, ccSnapshot($w), 'bei quote_unavailable wird nichts geschrieben');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Rollback bei Fehlerinjektion
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Fehlerinjektion: der Aufruf scheitert an einer Anweisung, die auf `$pattern` passt. Erwartet: error = db_error, nichts
 * geschrieben (alle Tabellen unverändert), kein Hook, keine offene Transaktion, und derselbe Aufruf gelingt danach.
 *
 * @param \Closure(CcWorld): \Closure(): array<string, mixed> $prepare baut die Welt auf und liefert den Aufruf
 */
function ccRollbackCase(string $label, string $pattern, \Closure $prepare): void
{
    $w = new CcWorld();
    $call = $prepare($w);
    $w->hooks = [];
    $before = ccSnapshot($w);
    $w->db->failOn = [$pattern];
    $failed = $call();
    T::same(['success' => false, 'error' => 'db_error'], $failed, $label . ': error = db_error');
    T::same($before, ccSnapshot($w), $label . ': nichts geschrieben (alle Tabellen unverändert)');
    T::same([[], false], [$w->hooks, $w->db->getPdo()->inTransaction()], $label . ': kein Hook, keine offene Transaktion');
    $w->db->failOn = [];
    $again = $call();
    T::same(true, $again['success'] ?? null, $label . ': derselbe Aufruf gelingt danach');
    T::same([], ccAudit($w, false), $label . ': Zähler nach dem erneuten Aufruf stimmen');
}

T::run('Rollback bei Fehlerinjektion (failOn): Anlegen, Antworten, Bearbeiten, Löschen schreiben nichts', static function (): void {
    $createSetup = static function (CcWorld $w): \Closure {
        $u = $w->db->user();
        $f = $w->db->forum();

        return static fn (): array => $w->topics->create($u, $f, 'Thema mit Fehlerinjektion', 'Text des Themas');
    };
    ccRollbackCase('create (letzter Schritt: Lesestatus)', '/INSERT\s+IGNORE\s+INTO\s+cms_community_read_states/', $createSetup);
    ccRollbackCase('create (nach dem Beitrag: Forumzähler)', '/UPDATE\s+cms_community_forums\s+SET/', $createSetup);
    ccRollbackCase('create (zweiter Schritt: Beitrag)', '/INSERT\s+INTO\s+cms_community_posts/', $createSetup);
    ccRollbackCase('create (erster Schritt: Thema)', '/INSERT\s+INTO\s+cms_community_topics/', $createSetup);

    $replySetup = static function (CcWorld $w): \Closure {
        $a = $w->db->user();
        $b = $w->db->user();
        $f = $w->db->forum();
        $topic = (int) $w->topics->create($a, $f, 'Thema für Antworten', 'Themenstart')['topic_id'];
        $w->at(60);

        return static fn (): array => $w->posts->reply($b, $topic, 'Antwort mit Fehlerinjektion');
    };
    ccRollbackCase('reply (Mitgliederzähler)', '/UPDATE\s+cms_community_user_stats\s+SET\s+topic_count/', $replySetup);
    ccRollbackCase('reply (Themenzähler)', '/UPDATE\s+cms_community_topics\s+SET/', $replySetup);
    ccRollbackCase('reply (Forumzähler)', '/UPDATE\s+cms_community_forums\s+SET/', $replySetup);
    ccRollbackCase('reply (Lesestatus)', '/INSERT\s+IGNORE\s+INTO\s+cms_community_read_states/', $replySetup);

    $editSetup = static function (CcWorld $w): \Closure {
        $a = $w->db->user();
        $f = $w->db->forum();
        $created = $w->topics->create($a, $f, 'Thema zum Bearbeiten', 'Alter Text');
        $w->at(60);

        return static fn (): array => $w->posts->edit($a, (int) $created['post_id'], 'Neuer Text', 'Neuer Titel des Themas', 'Grund');
    };
    ccRollbackCase('edit (Beitrag nach der Revision)', '/UPDATE\s+cms_community_posts\s+SET\s+body/', $editSetup);
    ccRollbackCase('edit (Thema nach der Revision)', '/UPDATE\s+cms_community_topics\s+SET/', $editSetup);
    ccRollbackCase('edit (Forum-Version nach der Titeländerung)', '/UPDATE\s+cms_community_forums\s+SET/', $editSetup);
    ccRollbackCase('edit (Revision)', '/INSERT\s+INTO\s+cms_community_post_revisions/', $editSetup);

    $deleteSetup = static function (CcWorld $w): \Closure {
        $a = $w->db->user();
        $b = $w->db->user();
        $f = $w->db->forum();
        $topic = (int) $w->topics->create($a, $f, 'Thema für Löschfälle', 'Themenstart')['topic_id'];
        $w->at(60);
        $post = (int) $w->posts->reply($b, $topic, 'Antwort zum Löschen')['post_id'];
        $w->at(120);

        return static fn (): array => $w->posts->delete($b, $post);
    };
    ccRollbackCase('delete Beitrag (Mitgliederzähler, letzter Schritt)', '/UPDATE\s+cms_community_user_stats\s+SET\s+topic_count/', $deleteSetup);
    ccRollbackCase('delete Beitrag (Status)', '/UPDATE\s+cms_community_posts\s+SET/', $deleteSetup);
    ccRollbackCase('delete Beitrag (Themenzähler)', '/UPDATE\s+cms_community_topics\s+SET/', $deleteSetup);

    $deleteTopicSetup = static function (CcWorld $w): \Closure {
        $a = $w->db->user();
        $f = $w->db->forum();
        $topic = (int) $w->topics->create($a, $f, 'Thema für Löschfälle', 'Themenstart')['topic_id'];
        $w->at(120);

        return static fn (): array => $w->topics->delete($a, $topic);
    };
    ccRollbackCase('delete Thema (Mitgliederzähler, letzter Schritt)', '/UPDATE\s+cms_community_user_stats\s+SET\s+topic_count/', $deleteTopicSetup);
    ccRollbackCase('delete Thema (Forumzähler)', '/UPDATE\s+cms_community_forums\s+SET/', $deleteTopicSetup);
    ccRollbackCase('delete Thema (Status)', '/UPDATE\s+cms_community_topics\s+SET/', $deleteTopicSetup);

    $w = new CcWorld();
    $u = $w->db->user();
    $f = $w->db->forum();
    [$t] = ccSeedTopic($w, $f, $u, 2);
    $w->db->failOn = ['/FROM\s+cms_community_topics/'];
    T::same(['success' => false, 'error' => 'db_error'], $w->topics->listForum($u, $f), 'Lesezugriff listForum: Datenbankfehler wird zu db_error, kein Absturz');
    T::same(null, $w->topics->get($u, $t), 'Lesezugriff get: Datenbankfehler ergibt null');
    $w->db->failOn = ['/FROM\s+cms_community_posts/'];
    T::same(['success' => false, 'error' => 'db_error'], $w->posts->listPage($u, $t), 'Lesezugriff listPage: Datenbankfehler wird zu db_error');
    $w->db->failOn = ['/cms_community_read_states/'];
    T::same([false, 0], [$w->reads->advance($u, $t, 1), $w->reads->unreadTopicCount($u, $f)], 'Lesestatus: Datenbankfehler ergibt den neutralen Wert');
});

T::run('Sperrkonflikt (Deadlock 1213): die Transaktion wird wiederholt, es entsteht genau ein Thema', static function (): void {
    $w = new CcWorld();
    $u = $w->db->user();
    $f = $w->db->forum();
    $attempts = 0;
    $w->rule = static function (?int $user, string $action, array $ctx) use (&$attempts): ?AccessDecision {
        if (!empty($ctx['fresh']) && ++$attempts === 1) {
            $e = new \PDOException('Deadlock found when trying to get lock');
            $e->errorInfo = ['40001', 1213, 'Deadlock found'];
            throw $e;
        }

        return null;
    };
    $r = $w->topics->create($u, $f, 'Thema nach Deadlock', 'Text nach Deadlock');
    T::same([true, 2], [$r['success'] ?? null, $attempts], 'zweiter Versuch gelingt');
    T::same([1, 1, [Events::TOPIC_CREATED]], [$w->db->count('community_topics'), $w->db->count('community_posts'), $w->hookNames()], 'ein Thema, ein Beitrag, ein Hook');
    ccAssertCounters($w, 'Thema nach Deadlock');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Hooks
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Hooks: Reihenfolge der Namen, nichts bei Fehlern; eine Ausnahme im Hook bricht nichts ab', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $created = $w->topics->create($a, $forum, 'Thema für alle Hooks', 'Themenstart');
    $topic = (int) $created['topic_id'];
    $w->at(60);
    $reply = $w->posts->reply($b, $topic, 'Antwort');
    $w->at(120);
    $w->posts->edit($b, (int) $reply['post_id'], 'Antwort, bearbeitet');
    $w->posts->edit($a, (int) $created['post_id'], 'Themenstart, bearbeitet', 'Thema für alle Hooks, neu');
    $w->at(180);
    $w->posts->delete($b, (int) $reply['post_id']);
    $other = (int) $w->topics->create($b, $forum, 'Anderes Thema zum Löschen', 'Text')['topic_id'];
    $w->topics->delete($b, $other);
    T::same(
        [Events::TOPIC_CREATED, Events::POST_CREATED, Events::POST_UPDATED, Events::POST_UPDATED, Events::TOPIC_UPDATED, Events::POST_DELETED, Events::TOPIC_CREATED, Events::TOPIC_DELETED],
        $w->hookNames(),
        'Hook-Namen und Reihenfolge über alle Aktionen'
    );
    T::same(array_fill(0, 8, false), array_column($w->hooks, 'in_transaction'), 'jeder Hook läuft nach dem Commit');
    foreach ($w->hooks as $h) {
        T::check(count(array_filter($h['args'], static fn (mixed $arg): bool => !is_int($arg) && !is_string($arg) && !is_array($arg))) === 0, $h['hook'] . ': nur IDs, Status und Feldnamen als Argumente');
    }

    $w->hooks = [];
    $w->topics->create($a, 9999, 'Thema ohne Forum', 'Text');
    $w->topics->create($a, $forum, 'x', 'Text');
    $w->posts->reply($a, 9999, 'Antwort ins Leere');
    $w->posts->edit($a, 9999, 'Text');
    $w->posts->delete($a, 9999);
    $w->topics->delete($a, 9999);
    $w->posts->delete($a, (int) $created['post_id']);
    $w->rule = ccDenyActions(['create_topic', 'reply', 'edit', 'delete'], 'no_permission');
    $w->topics->create($a, $forum, 'Verbotenes Thema', 'Text');
    $w->posts->reply($a, $topic, 'Verbotene Antwort');
    $w->posts->edit($a, (int) $created['post_id'], 'Verbotene Bearbeitung');
    T::same([], $w->hooks, 'Fehler, Ablehnungen und der abgelehnte erste Beitrag lösen keinen Hook aus');

    $w2 = new CcWorld();
    $w2->hookThrows = true;
    $u = $w2->db->user();
    $f = $w2->db->forum();
    $r = $w2->topics->create($u, $f, 'Thema mit werfendem Hook', 'Text');
    T::same([true, 1, 1], [$r['success'] ?? null, $w2->db->count('community_topics'), $w2->db->count('community_posts')], 'create: der Hook wirft, das Thema bleibt gespeichert und das Ergebnis ist Erfolg');
    $w2->at(60);
    $reply2 = $w2->posts->reply($u, (int) $r['topic_id'], 'Antwort mit werfendem Hook');
    T::same(true, $reply2['success'] ?? null, 'reply: Erfolg trotz werfendem Hook');
    $w2->at(120);
    T::same(true, $w2->posts->edit($u, (int) $reply2['post_id'], 'Antwort, bearbeitet')['success'] ?? null, 'edit: Erfolg trotz werfendem Hook');
    $w2->at(180);
    T::same(true, $w2->posts->delete($u, (int) $reply2['post_id'])['success'] ?? null, 'delete (Beitrag): Erfolg trotz werfendem Hook');
    T::same(true, $w2->topics->delete($u, (int) $r['topic_id'])['success'] ?? null, 'delete (Thema): Erfolg trotz werfendem Hook');
    T::same('deleted', $w2->topic((int) $r['topic_id'])['status'] ?? null, 'der Zustand ist gespeichert');
});

T::run('Hooks über Support\Events und CMS\Hooks (Standard): genau einmal, nach dem Commit; ein werfender Verbraucher bricht nichts ab', static function (): void {
    $w = new CcWorld();
    $u = $w->db->user();
    $forum = $w->db->forum();
    $allow = static fn (?int $user, string $action, array $ctx): AccessDecision => AccessDecision::allow();
    $topics = new TopicService($w->db, $w->settings, static fn (): int => ccAt(), $allow);
    $posts = new PostService($w->db, $w->settings, static fn (): int => ccAt(60), $allow);
    $seen = [];
    Hooks::addAction(Events::TOPIC_CREATED, static function (int $topicId, int $forumId, int $userId, string $status, int $postId) use (&$seen, $w): void {
        $seen[] = ['topic', $topicId, $forumId, $userId, $status, $postId, $w->db->getPdo()->inTransaction()];
    });
    Hooks::addAction(Events::TOPIC_CREATED, static function (): void {
        throw new \RuntimeException('Verbraucher kaputt');
    });
    Hooks::addAction(Events::POST_CREATED, static function (int $postId, int $topicId, int $userId, string $status) use (&$seen, $w): void {
        $seen[] = ['post', $postId, $topicId, $userId, $status, $w->db->getPdo()->inTransaction()];
    });

    $r = $topics->create($u, $forum, 'Thema über den Standard-Hook', 'Text');
    T::same(true, $r['success'] ?? null, 'create gelingt trotz werfendem Verbraucher');
    T::same([['topic', $r['topic_id'], $forum, $u, 'published', $r['post_id'], false]], $seen, 'Verbraucher genau einmal, nach dem Commit, mit den Argumenten aus Vertrag §5');
    $reply = $posts->reply($u, (int) $r['topic_id'], 'Antwort über den Standard-Hook');
    T::same(['post', $reply['post_id'], $r['topic_id'], $u, 'published', false], $seen[1] ?? null, 'post_created über den Standard-Hook');
    T::same(2, count($seen), 'insgesamt zwei Aufrufe');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Rechteprüfung (can), Sperrreihenfolge, Uhr
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Rechteprüfung: Ablehnungen (Grund, retry_after) schreiben nichts und nehmen keine Sperre', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $created = $w->topics->create($a, $forum, 'Thema für Ablehnungen', 'Themenstart');
    $topic = (int) $created['topic_id'];
    $w->at(60);
    $reply = (int) $w->posts->reply($b, $topic, 'Eine Antwort')['post_id'];
    $w->hooks = [];
    $snapshot = ccSnapshot($w);
    $w->db->log = [];

    $w->rule = ccDenyActions(['create_topic'], 'rate_limited', 30);
    T::same(['success' => false, 'error' => 'rate_limited', 'retry_after' => 30], $w->topics->create($a, $forum, 'Neues verbotenes Thema', 'Text'), 'create: Grund und retry_after der Rechteprüfung');
    $w->rule = ccDenyActions(['create_topic'], 'forum_readonly');
    T::same(['success' => false, 'error' => 'forum_readonly'], $w->topics->create($a, $forum, 'Neues verbotenes Thema', 'Text'), 'create: Grund ohne retry_after');
    $w->rule = ccDenyActions(['reply'], 'topic_locked');
    T::same(['success' => false, 'error' => 'topic_locked'], $w->posts->reply($b, $topic, 'Verbotene Antwort'), 'reply: topic_locked');
    $w->rule = ccDenyActions(['edit'], 'edit_window');
    T::same(['success' => false, 'error' => 'edit_window'], $w->posts->edit($b, $reply, 'Verbotene Bearbeitung'), 'edit: edit_window');
    $w->rule = ccDenyActions(['delete'], 'no_permission');
    T::same(['success' => false, 'error' => 'no_permission'], $w->posts->delete($b, $reply), 'delete (Beitrag): no_permission');
    T::same(['success' => false, 'error' => 'no_permission'], $w->topics->delete($a, $topic), 'delete (Thema): no_permission');
    $w->rule = ccDenyActions(['read'], 'not_found');
    T::same(['success' => false, 'error' => 'not_found'], $w->topics->listForum($a, $forum), 'listForum: not_found');
    T::same(['success' => false, 'error' => 'not_found'], $w->posts->listPage($a, $topic), 'listPage: not_found');
    T::same(null, $w->topics->get($a, $topic), 'get: null');
    $w->rule = static fn (?int $u, string $action, array $ctx): ?AccessDecision => AccessDecision::deny('');
    T::same(['success' => false, 'error' => 'no_permission'], $w->posts->reply($b, $topic, 'Antwort ohne Grund'), 'leerer Ablehnungsgrund wird no_permission');

    T::same($snapshot, ccSnapshot($w), 'nach allen Ablehnungen: nichts geschrieben');
    T::same([], $w->hooks, 'kein Hook');
    T::same([], $w->lockOrder(), 'abgelehnt vor den Sperren: keine Zeile wurde gesperrt');
    $w->rule = null;
    T::same('Thema für Ablehnungen', $w->topics->get($b, $topic)['title'] ?? null, 'get liefert das Thema, wenn die Rechteprüfung erlaubt');
    T::same(null, $w->topics->get($b, 9999), 'get: unbekanntes Thema null');
    T::same(null, $w->topics->get($b, 0), 'get: Thema 0 null');
});

T::run('Rechteprüfung: Aktion und Kontext je Dienst; erneut unter den Sperren mit fresh = true', static function (): void {
    $locks = static function (CcWorld $w, int $queries): array {
        $tables = [];
        foreach (array_slice($w->db->log, 0, $queries) as $sql) {
            if (str_contains($sql, ' FOR UPDATE') && preg_match('/FROM cms_(\w+)/', $sql, $m) === 1) {
                $tables[] = str_replace('community_', '', $m[1]);
            }
        }

        return array_values(array_unique($tables));
    };
    $calls = static fn (CcWorld $w): array => $w->canLog;

    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $w->db->stats($a);
    $w->db->stats($b);

    $w->db->log = [];
    $w->canLog = [];
    $created = $w->topics->create($a, $forum, 'Thema für Kontexte', 'Themenstart');
    $topic = (int) $created['topic_id'];
    T::same(
        [['create_topic', ['forum_id' => $forum, 'now' => ccAt()], []], ['create_topic', ['forum_id' => $forum, 'now' => ccAt(), 'fresh' => true], ['forums', 'user_stats']]],
        array_map(static fn (array $c): array => [$c['action'], $c['context'], $locks($w, $c['queries'])], $calls($w)),
        'create: vorab ohne Sperre, unter den Sperren (Forum, Mitglied) erneut mit fresh = true'
    );
    T::same([$a, $a], array_column($calls($w), 'user'), 'create: das Mitglied wird übergeben');

    $w->at(60);
    $w->db->log = [];
    $w->canLog = [];
    $reply = (int) $w->posts->reply($b, $topic, 'Antwort für Kontexte')['post_id'];
    T::same(
        [['reply', ['topic_id' => $topic, 'now' => ccAt(60)], []], ['reply', ['topic_id' => $topic, 'now' => ccAt(60), 'fresh' => true], ['forums', 'topics', 'user_stats']]],
        array_map(static fn (array $c): array => [$c['action'], $c['context'], $locks($w, $c['queries'])], $calls($w)),
        'reply: vorab ohne Sperre, unter den Sperren (Forum, Thema, Mitglied) erneut mit fresh = true'
    );

    $w->at(120);
    $w->db->log = [];
    $w->canLog = [];
    $w->posts->edit($b, $reply, 'Antwort für Kontexte, bearbeitet');
    T::same(
        [['edit', ['post_id' => $reply, 'now' => ccAt(120)], []], ['edit', ['post_id' => $reply, 'now' => ccAt(120), 'fresh' => true], ['topics', 'posts']]],
        array_map(static fn (array $c): array => [$c['action'], $c['context'], $locks($w, $c['queries'])], $calls($w)),
        'edit (Text): unter den Sperren Thema, Beitrag'
    );
    $w->at(180);
    $w->db->log = [];
    $w->canLog = [];
    $w->posts->edit($a, (int) $created['post_id'], 'Themenstart, bearbeitet', 'Thema für Kontexte, neuer Titel');
    T::same(['forums', 'topics', 'posts'], $locks($w, $w->canLog[1]['queries']), 'edit (mit Titel): unter den Sperren Forum, Thema, Beitrag');

    $w->at(240);
    $w->db->log = [];
    $w->canLog = [];
    $w->posts->delete($b, $reply);
    T::same(
        [['delete', ['post_id' => $reply, 'now' => ccAt(240)], []], ['delete', ['post_id' => $reply, 'now' => ccAt(240), 'fresh' => true], ['forums', 'topics', 'posts', 'user_stats']]],
        array_map(static fn (array $c): array => [$c['action'], $c['context'], $locks($w, $c['queries'])], $calls($w)),
        'delete (Beitrag): unter den Sperren Forum, Thema, Beitrag, Mitglied (Kontext: post_id)'
    );

    $w->at(300);
    $w->db->log = [];
    $w->canLog = [];
    $w->topics->delete($a, $topic);
    T::same(
        [['delete', ['topic_id' => $topic, 'now' => ccAt(300)], []], ['moderate', ['forum_id' => $forum, 'now' => ccAt(300)], []], ['delete', ['topic_id' => $topic, 'now' => ccAt(300), 'fresh' => true], ['forums', 'topics', 'user_stats']]],
        array_map(static fn (array $c): array => [$c['action'], $c['context'], $locks($w, $c['queries'])], $calls($w)),
        'delete (Thema): Kontext topic_id (kein post_id), Moderationsfrage, unter den Sperren Forum, Thema, Mitglied'
    );

    $w->canLog = [];
    $w->topics->listForum($b, $forum);
    $w->posts->listPage(null, $topic);
    $afterList = count($w->canLog);
    $w->topics->get(0, $topic);
    T::same(
        [['read', $b, ['forum_id' => $forum, 'now' => ccAt(300)]], ['read', null, ['topic_id' => $topic, 'now' => ccAt(300)]], ['read', null, ['topic_id' => $topic, 'now' => ccAt(300)]]],
        array_map(static fn (array $c): array => [$c['action'], $c['user'], $c['context']], [$calls($w)[0], $calls($w)[1], $calls($w)[$afterList]]),
        'Lesen: read mit forum_id bzw. topic_id; Gast (null und 0) als null'
    );
    T::same(['post_id', 'post', 'now'], array_keys($calls($w)[2]['context'] ?? []), 'listPage fragt je Beitrag read mit dem geladenen Beitrag (post_id, post)');
});

T::run('Sperrreihenfolge: Forum → Thema → Beitrag → Mitglied bei jeder Schreibaktion', static function (): void {
    $dedupe = static fn (array $tables): array => array_values(array_filter($tables, static fn (string $t, int $i): bool => $i === 0 || $tables[$i - 1] !== $t, ARRAY_FILTER_USE_BOTH));
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $from = count($w->db->log);
    $created = $w->topics->create($a, $forum, 'Thema für die Sperrreihenfolge', 'Themenstart');
    T::same(['forums', 'user_stats'], $dedupe($w->lockOrder($from)), 'create: Forum, Mitglied');
    $topic = (int) $created['topic_id'];
    $w->at(60);
    $from = count($w->db->log);
    $reply = (int) $w->posts->reply($b, $topic, 'Antwort für die Sperrreihenfolge')['post_id'];
    T::same(['forums', 'topics', 'user_stats'], $dedupe($w->lockOrder($from)), 'reply: Forum, Thema, Mitglied');
    $w->at(120);
    $from = count($w->db->log);
    $w->posts->edit($b, $reply, 'Antwort, bearbeitet');
    T::same(['topics', 'posts'], $dedupe($w->lockOrder($from)), 'edit (Text): Thema, Beitrag');
    $from = count($w->db->log);
    $w->posts->edit($a, (int) $created['post_id'], 'Themenstart, bearbeitet', 'Neuer Titel für die Reihenfolge');
    T::same(['forums', 'topics', 'posts'], $dedupe($w->lockOrder($from)), 'edit (mit Titel): Forum, Thema, Beitrag');
    $w->at(180);
    $from = count($w->db->log);
    $w->posts->delete($b, $reply);
    T::same(['forums', 'topics', 'posts', 'user_stats'], $dedupe($w->lockOrder($from)), 'delete (Beitrag): Forum, Thema, Beitrag, Mitglied');
    $from = count($w->db->log);
    $w->topics->delete($a, $topic);
    T::same(['forums', 'topics', 'user_stats'], $dedupe($w->lockOrder($from)), 'delete (Thema): Forum, Thema, Mitglied');
});

T::run('Uhr: jeder Aufruf liest die Uhr genau einmal (alle Zeiten UTC), Hilfsabfragen gar nicht', static function (): void {
    $w = new CcWorld();
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    [$topic, $ids] = ccSeedTopic($w, $forum, $a, 3);
    $reads = static function (\Closure $call) use ($w): int {
        $w->clockReads = 0;
        $call();

        return $w->clockReads;
    };
    $w->at(10);
    $counts = [
        'create' => $reads(static fn () => $w->topics->create($a, $forum, 'Thema für die Uhr', 'Text für die Uhr')),
        'reply' => $reads(static fn () => $w->posts->reply($b, $topic, 'Antwort für die Uhr')),
        'edit' => $reads(static fn () => $w->posts->edit($b, $ids[2], 'Bearbeitet für die Uhr')),
        'delete post' => $reads(static fn () => $w->posts->delete($b, $ids[2])),
        'delete topic' => $reads(static fn () => $w->topics->delete($a, $topic)),
        'listForum' => $reads(static fn () => $w->topics->listForum($a, $forum)),
        'listPage' => $reads(static fn () => $w->posts->listPage($a, $topic)),
        'get' => $reads(static fn () => $w->topics->get($a, $topic)),
        'advance' => $reads(static fn () => $w->reads->advance($b, $topic, $ids[1])),
        'markTopicPageRead' => $reads(static fn () => $w->reads->markTopicPageRead($b, $topic, [$ids[1]])),
        'markForumRead' => $reads(static fn () => $w->reads->markForumRead($b, $forum)),
        'unreadMap' => $reads(static fn () => $w->reads->unreadMap($b, [$w->topic($topic) ?? []])),
        'firstUnreadPostId' => $reads(static fn () => $w->reads->firstUnreadPostId($b, $topic)),
        'unreadTopicCount' => $reads(static fn () => $w->reads->unreadTopicCount($b, $forum)),
        'positionOf' => $reads(static fn () => $w->posts->positionOf($topic, $ids[1])),
        'pageOf' => $reads(static fn () => $w->posts->pageOf($topic, $ids[1])),
        'quote build' => $reads(static fn () => $w->quotes->build($ids[0], $b)),
    ];
    T::same(
        ['create' => 1, 'reply' => 1, 'edit' => 1, 'delete post' => 1, 'delete topic' => 1, 'listForum' => 1, 'listPage' => 1, 'get' => 1, 'advance' => 1, 'markTopicPageRead' => 1, 'markForumRead' => 1, 'unreadMap' => 1, 'firstUnreadPostId' => 1, 'unreadTopicCount' => 1, 'positionOf' => 0, 'pageOf' => 0, 'quote build' => 0],
        $counts,
        'Lesen der Uhr je öffentlichem Aufruf'
    );
});

T::run('Rechteprüfung: Standard ohne CommunityAccess lehnt alles ab (fail-closed)', static function (): void {
    if (class_exists(CommunityAccess::class)) {
        T::info('CommunityAccess ist vorhanden: der Standardweg ruft die echte Entscheidungsstelle (siehe TESTS/community-access); fail-closed hier nicht prüfbar');

        return;
    }
    $w = new CcWorld();
    $u = $w->db->user();
    $forum = $w->db->forum();
    $clock = static fn (): int => ccAt();
    $topics = new TopicService($w->db, $w->settings, $clock);
    $posts = new PostService($w->db, $w->settings, $clock);
    T::same(['success' => false, 'error' => 'no_permission'], $topics->create($u, $forum, 'Thema ohne Prüfstelle', 'Text'), 'TopicService ohne Prüfstelle: abgelehnt');
    T::same(['success' => false, 'error' => 'no_permission'], $posts->reply($u, 1, 'Antwort ohne Prüfstelle'), 'PostService ohne Prüfstelle: abgelehnt');
    T::same(0, $w->db->count('community_topics'), 'nichts geschrieben');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Wirkung der Einstellungen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Wirkung: title_min (zu kurze Titel; gezählt in Zeichen, nicht in Bytes; Anlegen und Bearbeiten)', static function (): void {
    foreach ([[null, 5], [8, 8], [1, 1], [100, 100]] as [$setting, $min]) {
        $w = new CcWorld($setting === null ? [] : ['title_min' => $setting]);
        $u = $w->db->user();
        $forum = $w->db->forum();
        $label = 'title_min ' . var_export($setting, true) . ' (wirksam ' . $min . ')';
        T::same(['success' => false, 'error' => 'title_too_short'], $w->topics->create($u, $forum, str_repeat('ä', $min - 1), 'Text'), $label . ': ' . ($min - 1) . ' Zeichen sind zu kurz');
        T::same(true, $w->topics->create($u, $forum, str_repeat('ä', $min), 'Text')['success'] ?? null, $label . ': ' . $min . ' Zeichen genügen (Umlaute zählen als ein Zeichen)');
        $first = (int) $w->db->row('community_topics', [])['first_post_id'];
        T::same(['success' => false, 'error' => 'title_too_short'], $w->posts->edit($u, $first, 'Neuer Text', str_repeat('ö', $min - 1)), $label . ': Bearbeiten: ' . ($min - 1) . ' Zeichen sind zu kurz');
        T::same(true, $w->posts->edit($u, $first, 'Neuer Text', str_repeat('ö', $min))['success'] ?? null, $label . ': Bearbeiten: ' . $min . ' Zeichen genügen');
    }
    $w = new CcWorld(['title_min' => 8]);
    $u = $w->db->user();
    $forum = $w->db->forum();
    T::same('title_too_short', $w->topics->create($u, $forum, '   abcdefg   ', 'Text')['error'] ?? null, 'Leerraum am Rand zählt nicht mit (7 Zeichen)');
    T::same(true, $w->topics->create($u, $forum, "ab  \t cd \n ef", 'Text')['success'] ?? null, 'Mehrfach-Leerraum zählt als ein Zeichen: „ab cd ef“ hat 8 Zeichen');
    T::same('title_too_short', $w->topics->create($u, $forum, "ab \t\t cd \n e", 'Text')['error'] ?? null, '„ab cd e“ hat 7 Zeichen, auch wenn der Rohtitel länger ist');
});

T::run('Wirkung: title_max (zu lange Titel; höchstens 200; Anlegen und Bearbeiten)', static function (): void {
    foreach ([[null, 200], [20, 20], [10, 10], [500, 200]] as [$setting, $max]) {
        $w = new CcWorld($setting === null ? [] : ['title_max' => $setting]);
        $u = $w->db->user();
        $forum = $w->db->forum();
        $label = 'title_max ' . var_export($setting, true) . ' (wirksam ' . $max . ')';
        T::same(['success' => false, 'error' => 'title_too_long'], $w->topics->create($u, $forum, str_repeat('ä', $max + 1), 'Text'), $label . ': ' . ($max + 1) . ' Zeichen sind zu lang');
        $ok = $w->topics->create($u, $forum, str_repeat('ä', $max), 'Text');
        T::same(true, $ok['success'] ?? null, $label . ': ' . $max . ' Zeichen genügen (Umlaute zählen als ein Zeichen)');
        $first = (int) $w->db->row('community_topics', [])['first_post_id'];
        T::same(['success' => false, 'error' => 'title_too_long'], $w->posts->edit($u, $first, 'Neuer Text', str_repeat('ö', $max + 1)), $label . ': Bearbeiten: zu lang');
        T::same(true, $w->posts->edit($u, $first, 'Neuer Text', str_repeat('ö', $max))['success'] ?? null, $label . ': Bearbeiten: genügt');
    }
});

T::run('Wirkung: post_min (zu kurze Texte beim Anlegen, Antworten und Bearbeiten; Zeichen, nicht Bytes)', static function (): void {
    foreach ([[null, 2], [10, 10], [1, 1], [1000, 1000]] as [$setting, $min]) {
        $w = new CcWorld($setting === null ? [] : ['post_min' => $setting]);
        $u = $w->db->user();
        $forum = $w->db->forum();
        $label = 'post_min ' . var_export($setting, true) . ' (wirksam ' . $min . ')';
        T::same(['success' => false, 'error' => 'body_too_short'], $w->topics->create($u, $forum, 'Titel eins', str_repeat('ä', $min - 1)), $label . ': Thema mit ' . ($min - 1) . ' Zeichen zu kurz');
        $created = $w->topics->create($u, $forum, 'Titel eins', str_repeat('ä', $min));
        T::same(true, $created['success'] ?? null, $label . ': Thema mit ' . $min . ' Zeichen genügt');
        $w->at(60);
        T::same(['success' => false, 'error' => 'body_too_short'], $w->posts->reply($u, (int) $created['topic_id'], str_repeat('ö', $min - 1)), $label . ': Antwort mit ' . ($min - 1) . ' Zeichen zu kurz');
        $reply = $w->posts->reply($u, (int) $created['topic_id'], str_repeat('ö', $min));
        T::same(true, $reply['success'] ?? null, $label . ': Antwort mit ' . $min . ' Zeichen genügt');
        T::same(['success' => false, 'error' => 'body_too_short'], $w->posts->edit($u, (int) $reply['post_id'], str_repeat('ü', $min - 1)), $label . ': Bearbeiten mit ' . ($min - 1) . ' Zeichen zu kurz');
        T::same(true, $w->posts->edit($u, (int) $reply['post_id'], str_repeat('ü', $min))['success'] ?? null, $label . ': Bearbeiten mit ' . $min . ' Zeichen genügt');
    }
    $w = new CcWorld();
    $u = $w->db->user();
    $forum = $w->db->forum();
    T::same('body_too_short', $w->topics->create($u, $forum, 'Titel zwei', " \n\t ")['error'] ?? null, 'ein Text aus Leerraum zählt als leer');
});

T::run('Wirkung: post_max (zu lange Texte beim Anlegen, Antworten und Bearbeiten; mindestens 100)', static function (): void {
    foreach ([[null, 50000], [100, 100], [99, 100], [250, 250]] as [$setting, $max]) {
        $w = new CcWorld($setting === null ? [] : ['post_max' => $setting]);
        $u = $w->db->user();
        $forum = $w->db->forum();
        $label = 'post_max ' . var_export($setting, true) . ' (wirksam ' . $max . ')';
        T::same(['success' => false, 'error' => 'body_too_long'], $w->topics->create($u, $forum, 'Titel eins', str_repeat('ä', $max + 1)), $label . ': Thema mit ' . ($max + 1) . ' Zeichen zu lang');
        $created = $w->topics->create($u, $forum, 'Titel eins', str_repeat('ä', $max));
        T::same(true, $created['success'] ?? null, $label . ': Thema mit ' . $max . ' Zeichen genügt (Umlaute zählen als ein Zeichen)');
        $w->at(60);
        T::same(['success' => false, 'error' => 'body_too_long'], $w->posts->reply($u, (int) $created['topic_id'], str_repeat('ö', $max + 1)), $label . ': Antwort zu lang');
        $reply = $w->posts->reply($u, (int) $created['topic_id'], str_repeat('ö', $max));
        T::same(true, $reply['success'] ?? null, $label . ': Antwort genügt');
        T::same(['success' => false, 'error' => 'body_too_long'], $w->posts->edit($u, (int) $reply['post_id'], str_repeat('ü', $max + 1)), $label . ': Bearbeiten zu lang');
        T::same(true, $w->posts->edit($u, (int) $reply['post_id'], str_repeat('ü', $max))['success'] ?? null, $label . ': Bearbeiten genügt');
    }
});

T::run('Wirkung: duplicate_window_seconds (Fenster für doppeltes Absenden; Grenze inklusive)', static function (): void {
    foreach ([[null, 120], [10, 10], [3600, 3600], [5, 10]] as [$setting, $window]) {
        $label = 'duplicate_window_seconds ' . var_export($setting, true) . ' (wirksam ' . $window . ')';
        $w = new CcWorld($setting === null ? [] : ['duplicate_window_seconds' => $setting]);
        $u = $w->db->user();
        $forum = $w->db->forum();
        $first = $w->topics->create($u, $forum, 'Thema im Fenster', 'Text im Fenster');
        $w->at($window);
        $inside = $w->topics->create($u, $forum, 'Thema im Fenster', 'Text im Fenster');
        T::same([true, $first['topic_id']], [$inside['duplicate'] ?? null, $inside['topic_id'] ?? null], $label . ': nach ' . $window . ' Sekunden (Rand des Fensters) noch dasselbe Thema');
        $w->at($window + 1);
        $outside = $w->topics->create($u, $forum, 'Thema im Fenster', 'Text im Fenster');
        T::same([null, true], [$outside['duplicate'] ?? null, ($outside['topic_id'] ?? 0) !== $first['topic_id']], $label . ': nach ' . ($window + 1) . ' Sekunden ein neues Thema');
        T::same(2, $w->db->count('community_topics'), $label . ': zwei Themen');

        $topic = (int) $first['topic_id'];
        $w->at(1000);
        $reply = $w->posts->reply($u, $topic, 'Antwort im Fenster');
        $w->at(1000 + $window);
        $replyInside = $w->posts->reply($u, $topic, 'Antwort im Fenster');
        T::same([true, $reply['post_id']], [$replyInside['duplicate'] ?? null, $replyInside['post_id'] ?? null], $label . ': Antwort nach ' . $window . ' Sekunden noch dieselbe');
        $w->at(1000 + $window + 1);
        $replyOutside = $w->posts->reply($u, $topic, 'Antwort im Fenster');
        T::same([null, true], [$replyOutside['duplicate'] ?? null, ($replyOutside['post_id'] ?? 0) !== $reply['post_id']], $label . ': Antwort nach ' . ($window + 1) . ' Sekunden neu');
    }
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Quelltext-Wächter
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Quelltext-Wächter: Services/Community/** ohne verbotene Muster (Vertrag §1), mit gebauter Gegenprobe', static function (): void {
    $bad = "SELECT NOW(), CURRENT_TIMESTAMP, CURDATE(), UTC_TIMESTAMP() FROM t WHERE (a, b) < (?, ?) LIMIT 5 OFFSET 10 ON DUPLICATE KEY UPDATE x = GREATEST(a, b), LEAST(a, b), FIELD(a, 'x'), JSON_EXTRACT(a, '\$.x') UNION SELECT 1 FOR UPDATE;\n"
        . "\$d = date('Y-m-d H:i:s'); \$this->db->insert('t', []); \$db->update('t', [], []); \$this->database->delete('t', []); \$n = \$x->affected_rows(); \$d = Database::instance();";
    $names = ['NOW()', 'CURRENT_TIMESTAMP', 'CURDATE()', 'UTC_TIMESTAMP', 'date(', 'OFFSET', 'ON DUPLICATE KEY', 'GREATEST', 'LEAST', 'FIELD', 'JSON_*', 'UNION', 'Zeilenkonstruktor', 'insert/update/delete der Datenbank', 'affected_rows', 'Database::instance() außerhalb des Konstruktor-Standards', 'FOR UPDATE außerhalb von AbstractRepository'];
    T::same($names, ccGuardHits($bad), 'Gegenprobe: ein schlechtes Beispiel trifft alle siebzehn Muster');
    $single = [
        'NOW()' => 'WHERE a < NOW()', 'CURRENT_TIMESTAMP' => 'DEFAULT CURRENT_TIMESTAMP', 'CURDATE()' => 'CURDATE()', 'UTC_TIMESTAMP' => 'UTC_TIMESTAMP()', 'date(' => '$x = date("Y");',
        'OFFSET' => 'LIMIT 1 offset 5', 'ON DUPLICATE KEY' => 'on duplicate key update', 'GREATEST' => 'GREATEST(a, 0)', 'LEAST' => 'LEAST(a, 0)', 'FIELD' => 'ORDER BY FIELD(x, 1)',
        'JSON_*' => 'JSON_EXTRACT(a, 1)', 'UNION' => 'SELECT 1 UNION ALL SELECT 2', 'Zeilenkonstruktor' => 'WHERE (a, id) < (?, ?)', 'insert/update/delete der Datenbank' => '$this->db->delete("t", [])',
        'affected_rows' => '$db->affected_rows()', 'Database::instance() außerhalb des Konstruktor-Standards' => '$x = Database::instance();', 'FOR UPDATE außerhalb von AbstractRepository' => "SELECT 1 FOR UPDATE",
    ];
    foreach ($single as $name => $sample) {
        T::same([$name], ccGuardHits($sample), 'Gegenprobe: Muster ' . $name . ' schlägt einzeln an');
    }
    $good = "gmdate('Y-m-d H:i:s', \$ts); I18n::date(\$utc, 'd.m.'); \$this->date(1); function date(string \$x) {} \$this->posts->update(1, []); \$repo->insert([]); validate(2); date_default_timezone_get(); getOffset(); "
        . "\$this->db = \$db ?? Database::instance(); JSON_UNESCAPED_UNICODE; ' FOR UPDATE'; (\$a, \$b) = [1, 2]; max(\$a, \$b) < 3;";
    T::same(['FOR UPDATE außerhalb von AbstractRepository'], ccGuardHits($good), 'Gegenprobe: erlaubte Schreibweisen schlagen nicht an (nur FOR UPDATE außerhalb der Hilfsmethode, wie gewollt)');
    T::same([], ccGuardHits($good, true), 'Gegenprobe: in AbstractRepository ist die Hilfsmethode für FOR UPDATE erlaubt');

    $files = ccCommunityFiles();
    // Einzige bekannte Ausnahme: der Installer-Pfad von PR #1 schreibt Optionen der Core-Tabelle `settings` mit
    // `ON DUPLICATE KEY` (läuft nur gegen eine echte Datenbank, nie gegen das SQLite-Double).
    $exempt = ['Support/CommunityCapabilities.php' => ['ON DUPLICATE KEY']];
    T::check(count($files) >= 15, 'Services/Community/** enthält mindestens die fünfzehn Dateien der Wellen 0 bis 2 (' . count($files) . ' gefunden)');
    foreach (['Service/TopicService.php', 'Service/PostService.php', 'Service/QuoteService.php', 'Service/ReadStateService.php'] as $own) {
        T::check(is_file(ABSPATH . 'core/Services/Community/' . $own), $own . ' ist vorhanden');
    }
    $offenders = [];
    foreach ($files as $file) {
        $code = ccCodeWithoutComments((string) file_get_contents($file));
        $relative = substr($file, strlen(ABSPATH . 'core/Services/Community/'));
        $hits = array_values(array_diff(ccGuardHits($code, basename($file) === 'AbstractRepository.php'), $exempt[$relative] ?? []));
        if ($hits !== []) {
            $offenders[] = $relative . ' (' . implode(', ', $hits) . ')';
        }
    }
    T::same([], $offenders, 'keine verbotenen Muster in Services/Community/** (Kommentare ausgenommen)');
    foreach ($exempt as $relative => $patterns) {
        $hits = ccGuardHits(ccCodeWithoutComments((string) file_get_contents(ABSPATH . 'core/Services/Community/' . $relative)));
        T::same($patterns, array_values(array_intersect($hits, $patterns)), 'Ausnahme ' . $relative . ' gilt noch (sonst streichen)');
    }

    // Uhr: nur der Standard `time()` der Closure; kein strtotime, microtime, mktime, new DateTime, date_create.
    $clockUse = [];
    foreach (['TopicService' => 1, 'PostService' => 1, 'ReadStateService' => 1, 'QuoteService' => 0] as $class => $expectedTime) {
        $code = ccCodeWithoutComments((string) file_get_contents(ABSPATH . 'core/Services/Community/Service/' . $class . '.php'));
        $time = preg_match_all('/(?<![\w>:$])(?<!function )time\s*\(/', $code);
        $other = preg_match('/(?<![\w>:$])(?:strtotime|microtime|hrtime|mktime|gmmktime|date_create)\s*\(|\bnew\s+\\\\?DateTime/', $code);
        if ($time !== $expectedTime || $other !== 0) {
            $clockUse[] = $class . ' (time(): ' . $time . ', andere Uhren: ' . $other . ')';
        }
    }
    T::same([], $clockUse, 'Dienste benutzen nur den Standard `time()` in der Closure der Uhr, sonst keine Uhr');
    T::same(
        [1, 0, 0],
        [preg_match_all('/(?<![\w>:$])(?<!function )time\s*\(/', "\$c = fn () => time();\n"), preg_match('/(?<![\w>:$])(?<!function )time\s*\(/', '$this->time(1); $x->time (2); function time() {}'), preg_match('/(?<![\w>:$])(?:strtotime|microtime)\s*\(/', 'mytime(); $d->strtotime(1)')],
        'Gegenprobe der Uhr-Erkennung: Aufruf trifft, Methoden und Namensanfänge nicht'
    );
    T::same("<?php\n\n\n\$a = 1;", ccCodeWithoutComments("<?php\n// NOW()\n/** date( */\n\$a = 1;"), 'Gegenprobe: Kommentare werden vor der Prüfung entfernt, die Zeilen bleiben');
});

/** PHP-Quelltext ohne Kommentare und Docblöcke (Zeilen bleiben erhalten). */
function ccCodeWithoutComments(string $source): string
{
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($token[1], "\n")) : $token[1];
        } else {
            $out .= $token;
        }
    }

    return $out;
}

T::run('Dienste: jeder zurückgegebene Fehlercode ist in Support\Codes bekannt', static function (): void {
    $known = array_merge(Codes::ERRORS, array_keys(Codes::ACCESS_REASONS));
    $unknown = [];
    $found = 0;
    foreach (['TopicService', 'PostService', 'QuoteService', 'ReadStateService'] as $class) {
        $code = ccCodeWithoutComments((string) file_get_contents(ABSPATH . 'core/Services/Community/Service/' . $class . '.php'));
        preg_match_all("/(?:failure\\('|'error' => '|=> '(?=topic_)|AccessDecision::deny\\(')([a-z_]+)'/", $code, $m);
        foreach ($m[1] as $error) {
            $found++;
            if (!in_array($error, $known, true)) {
                $unknown[] = $class . ': ' . $error;
            }
        }
    }
    T::check($found >= 20, 'die Erkennung findet die Fehlercodes im Quelltext (' . $found . ' Stellen)');
    T::same([], $unknown, 'alle Fehlercodes der Dienste stehen in Codes::ERRORS oder Codes::ACCESS_REASONS (Sprachschlüssel dafür prüft community-settings)');
    foreach (['title_too_short', 'title_too_long', 'body_too_short', 'body_too_long', 'forum_not_found', 'topic_not_found', 'post_not_found', 'first_post', 'has_replies', 'edit_conflict', 'new_posts', 'quote_unavailable', 'db_error', 'invalid_input'] as $code) {
        T::check(in_array($code, Codes::ERRORS, true), 'Codes::ERRORS kennt ' . $code);
    }
});

T::run('Zustand unter der Sperre: Änderungen zwischen Lesen und Sperren werden erkannt (Löschen, Forumswechsel)', static function (): void {
    // Zwischen dem Lesen ohne Sperre und dem Sperren kommt eine Antwort: der Autor darf das Thema nicht mehr löschen.
    $w = new CcWorld();
    $a = $w->db->user();
    $forum = $w->db->forum();
    [$topic] = ccSeedTopic($w, $forum, $a, 1);
    $w->rule = static function (?int $user, string $action, array $ctx) use ($w, $topic): ?AccessDecision {
        if ($action === 'moderate') {
            $w->db->execute('UPDATE cms_community_topics SET reply_count = 1 WHERE id = ?', [$topic]);

            return AccessDecision::deny('no_permission');
        }

        return null;
    };
    $snapshot = ccSnapshot($w);
    $result = $w->topics->delete($a, $topic);
    T::same(['success' => false, 'error' => 'has_replies'], $result, 'Antwort kam nach dem ersten Lesen: unter der Sperre wird has_replies erkannt');
    $w->db->execute('UPDATE cms_community_topics SET reply_count = 0 WHERE id = ?', [$topic]);
    $after = ccSnapshot($w);
    $stats = $after['community_user_stats'];
    unset($snapshot['community_user_stats'], $after['community_user_stats']);
    T::same($snapshot, $after, 'nichts geschrieben (außer der leeren Zeile der Mitgliederstatistik, die das Sperren anlegt)');
    T::same([], array_filter($stats, static fn (array $row): bool => (int) $row['topic_count'] !== 0 || (int) $row['post_count'] !== 0), 'die angelegte Statistikzeile ist leer');

    // Ein Thema, das das Forum gewechselt hat: die Aktion beginnt von vorn (null), sie schreibt nichts in das alte Forum.
    $w = new CcWorld();
    $a = $w->db->user();
    $forum = $w->db->forum();
    $other = $w->db->forum();
    [$topic, $ids] = ccSeedTopic($w, $forum, $a, 2);
    $snapshot = ccSnapshot($w);
    $invoke = static function (object $service, string $method, array $args): mixed {
        return (new \ReflectionMethod($service, $method))->invoke($service, ...$args);
    };
    T::same(
        [null, null, null, null],
        [
            $invoke($w->posts, 'replyLocked', [$a, $topic, $other, 'Antwort ins falsche Forum', 0, 0, false, ccAt(), ccUtc(), ccUtc(-120)]),
            $invoke($w->posts, 'editLocked', [$a, $ids[1], $topic, $other, 'Neuer Text', null, null, null, ccAt(), ccUtc()]),
            $invoke($w->posts, 'deleteLocked', [$a, $ids[1], $topic, $other, ccAt(), ccUtc()]),
            $invoke($w->topics, 'deleteLocked', [$a, $topic, $other, false, ccAt(), ccUtc()]),
        ],
        'ein Thema in einem anderen Forum als gelesen: reply, edit, delete (Beitrag, Thema) liefern null (neu beginnen)'
    );
    T::same($snapshot, ccSnapshot($w), 'dabei wird nichts geschrieben');
    $attempts = 0;
    $result = $invoke($w->posts, 'retrying', [$topic, static function (array $row) use (&$attempts): ?array {
        return ++$attempts < 3 ? null : ['success' => true, 'forum_id' => (int) $row['forum_id']];
    }]);
    T::same([$forum, 3], [$result['forum_id'] ?? null, $attempts], 'retrying: wiederholt bis zum Erfolg (hier im dritten Versuch)');
    $attempts = 0;
    try {
        $invoke($w->posts, 'retrying', [$topic, static function (array $row) use (&$attempts): ?array {
            $attempts++;

            return null;
        }]);
        T::check(false, 'retrying: nach drei Fehlversuchen muss eine Ausnahme kommen');
    } catch (\RuntimeException) {
        T::same(3, $attempts, 'retrying: höchstens drei Versuche, dann RuntimeException (der Dienst meldet db_error)');
    }
});

T::run('Thema lesen: wirksamer Zustand (abgelaufenes Anheften und Schließen), Ganzzahlfelder', static function (): void {
    $w = new CcWorld();
    $u = $w->db->user();
    $forum = $w->db->forum();
    $w->at(1000);
    $expired = $w->db->topic(['forum' => $forum, 'user' => $u, 'pinned' => true, 'pinned_until' => ccUtc(10), 'locked' => true, 'locked_until' => ccUtc(20)]);
    $active = $w->db->topic(['forum' => $forum, 'user' => $u, 'pinned' => true, 'pinned_until' => ccUtc(5000), 'locked' => true, 'locked_until' => ccUtc(5000)]);
    $forever = $w->db->topic(['forum' => $forum, 'user' => $u, 'pinned' => true, 'locked' => true]);
    $plain = $w->db->topic(['forum' => $forum, 'user' => $u]);
    $state = static fn (int $id): array => [$w->topics->get($u, $id)['is_pinned'] ?? null, $w->topics->get($u, $id)['is_locked'] ?? null];
    T::same([0, 0], $state($expired), 'abgelaufene Fristen: weder angeheftet noch geschlossen');
    T::same([1, 1], $state($active), 'laufende Fristen: angeheftet und geschlossen');
    T::same([1, 1], $state($forever), 'ohne Frist: dauerhaft');
    T::same([0, 0], $state($plain), 'ohne Markierung: nichts');
    $row = $w->topics->get($u, $plain);
    T::same([$plain, $forum, $u, 0, 1], [$row['id'], $row['forum_id'], $row['user_id'], $row['reply_count'], $row['version']], 'Zeile mit Ganzzahlfeldern (id, forum_id, user_id, reply_count, version)');
});

T::run('Idempotenz bei wartenden Beiträgen; Löschen eines ausgeblendeten Beitrags durch die Moderation', static function (): void {
    $w = new CcWorld([], ccPending());
    $a = $w->db->user();
    $b = $w->db->user();
    $forum = $w->db->forum();
    $first = $w->topics->create($a, $forum, 'Wartendes Thema doppelt', 'Wartender Text');
    $w->at(5);
    $second = $w->topics->create($a, $forum, 'Wartendes Thema doppelt', 'Wartender Text');
    T::same([true, 'pending', $first['topic_id'], $first['post_id']], [$second['duplicate'] ?? null, $second['status'] ?? null, $second['topic_id'] ?? null, $second['post_id'] ?? null], 'wartendes Thema zweimal abgesendet: dasselbe Thema mit Status pending');
    $w->rule = null;
    $topic = (int) $w->topics->create($b, $forum, 'Thema für die wartende Antwort', 'Veröffentlichter Start')['topic_id'];
    $w->rule = ccPending();
    $w->at(60);
    $reply = $w->posts->reply($a, $topic, 'Wartende Antwort doppelt');
    $w->at(65);
    $again = $w->posts->reply($a, $topic, 'Wartende Antwort doppelt');
    T::same([true, 'pending', $reply['post_id'], null, null], [$again['duplicate'] ?? null, $again['status'] ?? null, $again['post_id'] ?? null, ccGet($again, 'position'), ccGet($again, 'page')], 'wartende Antwort zweimal abgesendet: dasselbe Ergebnis, keine Position');
    T::same(1, $w->db->count('community_posts', ['topic_id' => $topic, 'is_first_post' => 0]), 'nur eine Antwort');

    // Ausgeblendet (PR #2b setzt den Status): die Moderation löscht, keine Zähler ändern sich.
    $w2 = new CcWorld();
    $author = $w2->db->user();
    $moderator = $w2->db->user();
    $forum2 = $w2->db->forum();
    $topic2 = (int) $w2->topics->create($author, $forum2, 'Thema mit ausgeblendetem Beitrag', 'Themenstart')['topic_id'];
    $w2->at(60);
    $hiddenPost = (int) $w2->posts->reply($author, $topic2, 'Wird ausgeblendet')['post_id'];
    $w2->db->execute("UPDATE cms_community_posts SET status = 'hidden' WHERE id = ?", [$hiddenPost]);
    $w2->db->execute('UPDATE cms_community_topics SET reply_count = 0 WHERE id = ?', [$topic2]);
    $w2->db->execute('UPDATE cms_community_forums SET post_count = 1 WHERE id = ?', [$forum2]);
    $w2->db->execute('UPDATE cms_community_user_stats SET post_count = 1 WHERE user_id = ?', [$author]);
    $counters = [$w2->topic($topic2)['reply_count'], $w2->topic($topic2)['version'], $w2->forum($forum2)['post_count'], $w2->forum($forum2)['version'], $w2->stats($author)['post_count']];
    $w2->at(120);
    $r = $w2->posts->delete($moderator, $hiddenPost);
    T::same([true, 'hidden', 'deleted', 'moderator'], [$r['success'] ?? null, $r['previous_status'] ?? null, $w2->post($hiddenPost)['status'], $w2->post($hiddenPost)['status_reason']], 'ausgeblendeter Beitrag: gelöscht, previous_status hidden, Grund moderator');
    T::same($counters, [$w2->topic($topic2)['reply_count'], $w2->topic($topic2)['version'], $w2->forum($forum2)['post_count'], $w2->forum($forum2)['version'], $w2->stats($author)['post_count']], 'ausgeblendete Beiträge zählen nicht: Zähler und version unverändert');
});

T::run('Ungelesen-Karte: mehr als 500 Themen (Abfrage in Teilen)', static function (): void {
    $w = new CcWorld();
    $author = $w->db->user();
    $r = $w->db->user();
    $forum = $w->db->forum();
    $w->at(0);
    $ids = [];
    for ($i = 0; $i < 520; $i++) {
        [$ids[]] = ccSeedTopic($w, $forum, $author, 1, -3600 + $i);
    }
    $w->reads->advance($r, $ids[3], (int) $w->topic($ids[3])['last_post_id']);
    $w->reads->advance($r, $ids[510], (int) $w->topic($ids[510])['last_post_id']);
    $w->reads->advance($r, $ids[519], (int) $w->topic($ids[519])['last_post_id']);
    $map = $w->reads->unreadMap($r, $w->db->rows('community_topics', [], 'id'));
    T::same([520, 3, false, false, false, true, true], [count($map), count(array_filter($map)) - 514, $map[$ids[3]], $map[$ids[510]], $map[$ids[519]], $map[$ids[4]], $map[$ids[511]]], '520 Themen: gelesene (auch hinter Position 500) sind gelesen, alle anderen ungelesen');
});

T::finish();
