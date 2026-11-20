<?php
/**
 * Community-Dienste gegen eine echte MariaDB/MySQL (PR #2a, T6): echte `CommunityAccess`, echte Repositories, echte Dienste
 * auf einer frischen Datenbank aus der echten DDL (`TESTS/lib/MariaDbTestDatabase.php`, Datenbank `pr2a_*`, Sitzungszone +01:00,
 * PHP-Zone Europe/Berlin: jede Ortszeit statt UTC fällt auf).
 *
 *  (a) Anlegen, Antworten, Bearbeiten, Löschen Ende-zu-Ende; Zähler per COUNT nachgerechnet; UTC-Zeitstempel trotz Ortszeit,
 *      kein Zeitwert aus Spaltendefaults (CURRENT_TIMESTAMP der Sitzung); Grenzfälle der Spalten (200 Umlaute, 4-Byte-Zeichen).
 *  (b) Rücknahme per Fehlerinjektion an jeder Anweisung von Anlegen, Antworten, Bearbeiten und Löschen: nichts geschrieben,
 *      Zähler und `version` unverändert, kein Hook, keine offene Transaktion, keine hängende Sperre; dazu: fällt irgendeine
 *      Anweisung der Rechteprüfung aus, bleibt eine Ablehnung eine Ablehnung (kein Fail-open).
 *  (c) Wettlauf mit 8 Prozessen (`proc_open`, eigene Verbindung je Prozess, Startsignal per Datei): dasselbe Thema 8x gesendet
 *      (Idempotenz B), 8 Mitglieder antworten gleichzeitig (kein verlorenes Update), dasselbe Mitglied in zwei Foren, neue
 *      Mitglieder in verschiedenen Foren, gemischte Last; keine Deadlock-Fehler nach außen (Wiederholung in
 *      `Transaction::run` ist erlaubt, ein durchgereichtes 1213 ist ein Fehler).
 *  (d) EXPLAIN-Gate mit 24.000 Themen und 100.000 Beiträgen: Themenliste (Strom als ID-Abfrage, Folgeseite, Pins ohne Frist,
 *      mit abgelaufener und mit künftiger Frist, auch in einem großen älteren Forum), Beitragsliste, Platzhalter, Position,
 *      Dubletten- und Zählabfragen, die globale Liste „Unbeantwortet“ (ohne Forenfilter und mit `forum_id IN …`, jeweils als
 *      ID-Abfrage, auch auf Folgeseiten) und der Filter „ungelöst“ je Forum nutzen ihre Indizes ohne Tabellenscan und ohne
 *      Filesort (außer Platzhalter, Dubletten, `lastOfUser()` und abgelaufene Pins über wenige Zeilen); Prüfung über EXPLAIN und
 *      Handler-Zähler, nicht über Zeit. Abschnitt `explain-unanswered`: die globale Liste „Unbeantwortet“ mit 60.000 Themen und
 *      einem Cursor im ersten Viertel (dort wählte MariaDB 10.11 für `SELECT *` `ref` statt `range`; die ID-Form bleibt `range`).
 *      Abschnitt `explain-forum-large`: die Forenliste (`listForum()`) eines Forums mit 15.000 Themen, Cursor bis Position 6000
 *      (ebenso: `SELECT *` las dort mit der Position wachsend, die ID-Abfrage des Stroms bleibt `range`).
 *  (e) Echte `FOR UPDATE`-Sperren: solange ein Dienstaufruf seine Zeilen hält, läuft eine zweite Verbindung in den Lock-Timeout.
 *  (f) UNIQUE-Wettlauf bei Forum-Slugs: genau einer gewinnt, die anderen bekommen `slug_taken`.
 *
 * Regressionen: Die Suite hat fünf Fehler gefunden, die die DB-freien Suiten nicht sehen können; alle sind behoben und die Prüfungen
 * dazu sind gewöhnliche Prüfungen (kein Sonderstatus, keine Zusatzvariable):
 *   B1  doppeltes Absenden nacheinander: das vorhandene Thema bzw. die vorhandene Antwort mit `duplicate = true`, nicht `rate_limited`
 *       (Abschnitt `dup`; Wettlauf `race-dup`, `race-dup-reply`)
 *   B2  Abstand `rate_post_seconds` hält auch bei gleichzeitigen Anfragen desselben Mitglieds in verschiedenen Foren (Abschnitt
 *       `race-two-rate`, REPEATABLE READ; Regel „Mitgliedersperre vor der ersten nicht sperrenden Leseanweisung“)
 *   B3  erstmals schreibende Mitglieder in verschiedenen Foren: kein `db_error` nach außen, kaum Deadlocks (Abschnitt `race-new`);
 *       die Statistikzeile entsteht vor der Transaktion, in der Transaktion gibt es keine Lückensperre (Abschnitt `locks`)
 *   B4  Forum-Slug gleich `slug_en` eines anderen Forums: im Wettlauf entsteht nur eines (Abschnitt `slug`, Mutex-Zeile in `settings`)
 *   B5  `TopicRepository::lastPublished()` liest auch in einem ruhigen Forum nur wenige Index-Einträge (Abschnitt `explain`)
 *
 * Abschnitte der Bereiche aus PR #2b stehen in `sections-*.php` (je Datei ein Besitzer, am Ende vor `T::finish()` geladen), ihre
 * Worker-Aktionen in `actions-*.php` (geladen von `worker.php`).
 *
 * Optional: läuft nur mit CMS_TEST_DB_HOST und CMS_TEST_DB_USER (Recht CREATE/DROP DATABASE), sonst Hinweis (Exit 0), wie
 * `TESTS/schema-upgrade/run.php`. Gegenprobe: `TESTS/community-mutants` (Mutanten „db-…“ laufen nur mit Datenbank und lassen mit
 * `CMS_TEST_DB_SECTIONS=<Schlüsselanfänge>` nur den zuständigen Abschnitt laufen, z. B. `locks`, `race`, `explain`).
 *
 *   CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/community-db/run.php [-v]
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-db-' . getmypid() . '/');

// PHP-Zone der Suite: Europe/Berlin (die Sitzung läuft auf +01:00), damit jede Verwendung von date()/NOW() statt UTC auffällt.
date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

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
require_once dirname(__DIR__) . '/lib/MariaDbTestDatabase.php';

use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\ReadStateRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\PostService;
use CMS\Services\Community\Service\QuoteService;
use CMS\Services\Community\Service\ReadStateService;
use CMS\Services\Community\Service\TopicService;
use CMS\Services\Community\Support\Cursor;
use CMS\Services\Community\Support\Settings;

T::$verbose = in_array('-v', $argv ?? [], true);

register_shutdown_function(static function (): void {
    foreach (glob(LOG_PATH . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir(LOG_PATH);
});

if (!MariaDbTestDatabase::configured()) {
    T::run('Community-Dienste gegen MariaDB/MySQL', static function (): void {
        T::info(MariaDbTestDatabase::skipNotice());
    });
    T::finish();
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Hilfen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Führt einen Abschnitt aus (`T::run`), sofern die Umgebungsvariable `CMS_TEST_DB_SECTIONS` (Liste von Schlüsselanfängen, durch
 * Komma getrennt, z. B. `locks,race`) ihn nicht ausschließt. Die Gegenprobe (`TESTS/community-mutants`) lässt für Mutanten
 * der Datenbankschicht nur den zuständigen Abschnitt laufen; ohne die Variable laufen alle.
 */
function cdbSection(string $key, string $title, \Closure $test): void
{
    $only = array_values(array_filter(array_map('trim', explode(',', (string) getenv('CMS_TEST_DB_SECTIONS')))));
    if ($only !== [] && array_filter($only, static fn (string $prefix): bool => str_starts_with($key, $prefix)) === []) {
        return;
    }
    T::run($title, $test);
}

/** Rechte der Rollen in der Testwelt (`admin` nimmt die Klasse selbst aus). */
const CDB_ROLE_CAPS = [
    'member' => ['community.view', 'community.participate'],
    'moderator' => ['community.view', 'community.participate', 'community.moderate'],
];

/** Einstellungen der Wettläufe: Freigabepflicht aus (sonst zählt nichts), sonst Standards (Abstände und Grenzen bleiben wirksam). */
const CDB_RACE_SETTINGS = ['new_member_approval_posts' => 0, 'edit_window_minutes' => 0];

/** Alle sechs Grenzen einer Vertrauensstufe auf -1 (unbegrenzt; Vertrag DESIGN-2b §3.1). */
const CDB_UNLIMITED_LEVEL = [
    'posts_per_day' => -1, 'topics_per_hour' => -1, 'links_per_post' => -1, 'images_per_post' => -1, 'attachments_per_post' => -1, 'attachments_per_day' => -1,
];

/**
 * Wie CDB_RACE_SETTINGS, aber ohne Abstände und Zählgrenzen: nur die Idempotenz (Inhaltsvergleich unter der Sperre) hält
 * doppelte Absendungen auf. Die Standardgrenzen (Abstand, Themen je Stunde) lehnen eine Wiederholung ohnehin ab und verdecken sie.
 */
const CDB_NOLIMIT_SETTINGS = CDB_RACE_SETTINGS + [
    'rate_post_seconds' => 0,
    'rate_topic_seconds' => 0,
    // -1 = unbegrenzt in allen sechs Feldern (0 hieße ab PR #2b „keine“)
    'trust_limits' => [
        0 => CDB_UNLIMITED_LEVEL, 1 => CDB_UNLIMITED_LEVEL, 2 => CDB_UNLIMITED_LEVEL, 3 => CDB_UNLIMITED_LEVEL, 4 => CDB_UNLIMITED_LEVEL,
    ],
];

/**
 * Obergrenze der Deadlocks des Servers in `race-new` (fünf Läufe, acht und 16 gleichzeitige neue Mitglieder, zusammen 56 Anfragen).
 * Vor der Behebung von B3 waren es 43 bis 58 (und 3 bis 4 durchgereichte `db_error`); mit der Statistikzeile vor der Transaktion
 * allein noch 10 bis 17 (Lesestatus: UPDATE auf eine fehlende Zeile vor dem INSERT); gemessen nach der Behebung: 0 in 20 Läufen.
 * Ein Deadlock darf nur ausnahmsweise vorkommen und wird von `Transaction::run` wiederholt (nach außen nie ein `db_error`: das
 * prüft die Suite hart); die Grenze liegt weit unter dem, was jede der Ursachen einzeln erzeugt.
 */
const CDB_DEADLOCK_BUDGET = 2;

/** Uhr der Ende-zu-Ende-Abläufe: 2036-06-15 10:00:00 UTC (Sommer: Berlin +02:00, Sitzung +01:00, UTC: alle drei verschieden; weit entfernt von der echten Zeit, damit Werte aus Spaltendefaults auffallen). */
function cdbAt(int $seconds = 0): int
{
    return gmmktime(10, 0, 0, 6, 15, 2036) + $seconds;
}

function cdbUtc(int $seconds = 0): string
{
    return gmdate('Y-m-d H:i:s', cdbAt($seconds));
}

/**
 * Testwelt: frische Datenbank, steuerbare Uhr, echte `CommunityAccess` (Rechte aus Rollen, Zeilen der Datenbank), Hook-Protokoll
 * und alle Dienste auf derselben Verbindung. `rebuild()` baut Zugriff und Dienste mit leeren Zwischenspeichern neu.
 */
final class CdbWorld
{
    public MariaDbTestDatabase $db;
    public \ArrayObject $time;
    /** Startzeit der Uhr (Unix-Zeit); `at()` zählt davon aus. */
    public int $start;
    public Settings $settings;
    /** @var list<array{hook: string, args: list<mixed>, in_transaction: bool}> */
    public array $hooks = [];
    public CommunityAccess $access;
    public TopicService $topics;
    public PostService $posts;
    public ReadStateService $reads;
    public QuoteService $quotes;

    /** @param array<string, mixed> $settings Rohwerte für Settings::fromArray() */
    public function __construct(array $settings = [], ?int $start = null, ?MariaDbTestDatabase $db = null)
    {
        $this->db = $db ?? MariaDbTestDatabase::create();
        $this->start = $start ?? cdbAt();
        $this->time = new \ArrayObject(['now' => $this->start]);
        $this->settings = Settings::fromArray($settings);
        $this->rebuild();
    }

    public function rebuild(): void
    {
        $clock = fn (): int => (int) $this->time['now'];
        $this->access = new CommunityAccess(
            $this->db,
            $this->settings,
            static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, CDB_ROLE_CAPS[$role] ?? [], true),
            static fn (): bool => true,
            $clock,
            static fn (int $userId): bool => true
        );
        $can = fn (?int $userId, string $action, array $context) => $this->access->can($userId, $action, $context);
        $dispatch = function (string $hook, mixed ...$args): void {
            $this->hooks[] = ['hook' => $hook, 'args' => $args, 'in_transaction' => $this->db->getPdo()->inTransaction()];
        };
        $this->topics = new TopicService($this->db, $this->settings, $clock, $can, $dispatch);
        $this->posts = new PostService($this->db, $this->settings, $clock, $can, $dispatch);
        $this->reads = new ReadStateService($this->db, $this->settings, $clock, $can);
        $this->quotes = new QuoteService($this->db, $this->settings, $clock, $can);
    }

    /** Stellt die Uhr auf Start plus `$seconds`. */
    public function at(int $seconds): void
    {
        $this->time['now'] = $this->start + $seconds;
    }

    /** UTC-Text von Start plus `$seconds`. */
    public function utc(int $seconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', $this->start + $seconds);
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

    /** @return list<string> */
    public function hookNames(): array
    {
        return array_map(static fn (array $h): string => $h['hook'], $this->hooks);
    }
}

/** Wert einer Abfrage (erste Spalte der ersten Zeile) oder null. */
function cdbScalar(\CMS\Database $db, string $sql, array $params = []): mixed
{
    $value = $db->execute($sql, $params)->fetchColumn();

    return $value === false ? null : $value;
}

/** Wert eines Schlüssels oder die Markierung „fehlt“. */
function cdbGet(array $array, string $key): mixed
{
    return array_key_exists($key, $array) ? $array[$key] : '<fehlt>';
}

/**
 * Neuberechnung aller Zähler per COUNT und Abgleich mit den gespeicherten Werten (Forum: topic_count, post_count, last_*; Thema:
 * reply_count, last_*; Mitglied: topic_count, post_count).
 *
 * @return list<string> Abweichungen, leer = alles stimmt
 */
function cdbAudit(\CMS\Database $db): array
{
    $bad = [];
    $one = static fn (string $sql, array $params = []): ?array => ($row = $db->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC)) === false ? null : $row;
    foreach ($db->execute('SELECT * FROM cms_community_forums ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $f) {
        $id = (int) $f['id'];
        $topics = (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_topics WHERE forum_id = ? AND status = 'published'", [$id]);
        $posts = (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_posts p JOIN cms_community_topics t ON t.id = p.topic_id WHERE p.forum_id = ? AND p.status = 'published' AND t.status = 'published'", [$id]);
        if ((int) $f['topic_count'] !== $topics) {
            $bad[] = "Forum {$id}: topic_count {$f['topic_count']} statt {$topics}";
        }
        if ((int) $f['post_count'] !== $posts) {
            $bad[] = "Forum {$id}: post_count {$f['post_count']} statt {$posts}";
        }
        // „Letzter Beitrag“ des Forums: das Thema mit der jüngsten Aktivität (so bestimmt es das Löschen neu) oder – bei gleicher
        // Sekunde – der Beitrag mit der größten ID (so setzt es das Schreiben); beide Lesarten sind in sich stimmig.
        $last = $one("SELECT id, last_post_id, last_post_at, last_poster_id FROM cms_community_topics WHERE forum_id = ? AND status = 'published' ORDER BY last_post_at DESC, id DESC LIMIT 1", [$id]);
        $byTopic = $last === null ? [null, null, null, null] : [(int) $last['last_post_id'], (string) $last['last_post_at'], (int) $last['id'], (int) $last['last_poster_id']];
        $newest = $one("SELECT p.id, p.topic_id, p.user_id, t.last_post_at FROM cms_community_posts p JOIN cms_community_topics t ON t.id = p.topic_id WHERE p.forum_id = ? AND p.status = 'published' AND t.status = 'published' ORDER BY p.id DESC LIMIT 1", [$id]);
        $byPost = $newest === null ? [null, null, null, null] : [(int) $newest['id'], null, (int) $newest['topic_id'], (int) $newest['user_id']];
        $actual = [
            $f['last_post_id'] === null ? null : (int) $f['last_post_id'],
            $f['last_post_at'] === null ? null : (string) $f['last_post_at'],
            $f['last_topic_id'] === null ? null : (int) $f['last_topic_id'],
            $f['last_poster_id'] === null ? null : (int) $f['last_poster_id'],
        ];
        $expected = $byTopic;
        if ($actual !== $byTopic && $newest !== null && [$actual[0], $actual[2], $actual[3]] === [$byPost[0], $byPost[2], $byPost[3]]) {
            $expected = $actual;
        }
        if ($expected !== $actual) {
            $bad[] = "Forum {$id}: last_* " . json_encode($actual) . ' statt ' . json_encode($expected);
        }
    }
    foreach ($db->execute('SELECT * FROM cms_community_topics ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $t) {
        $id = (int) $t['id'];
        $replies = (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_posts WHERE topic_id = ? AND status = 'published' AND is_first_post = 0", [$id]);
        if ((int) $t['reply_count'] !== $replies) {
            $bad[] = "Thema {$id}: reply_count {$t['reply_count']} statt {$replies}";
        }
        $last = $one("SELECT id, user_id, created_at FROM cms_community_posts WHERE topic_id = ? AND status = 'published' ORDER BY id DESC LIMIT 1", [$id]);
        if ($last === null) {
            if ($t['last_post_id'] !== null) {
                $bad[] = "Thema {$id}: last_post_id {$t['last_post_id']} statt NULL";
            }
        } elseif ([(int) $t['last_post_id'], (int) $t['last_poster_id']] !== [(int) $last['id'], (int) $last['user_id']]) {
            $bad[] = "Thema {$id}: last_* " . json_encode([$t['last_post_id'], $t['last_poster_id']]) . ' statt ' . json_encode([$last['id'], $last['user_id']]);
        }
        if ($t['last_post_at'] === null) {
            $bad[] = "Thema {$id}: last_post_at ist NULL";
        }
    }
    foreach ($db->execute('SELECT * FROM cms_community_user_stats ORDER BY user_id')->fetchAll(\PDO::FETCH_ASSOC) as $s) {
        $id = (int) $s['user_id'];
        $topics = (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_topics WHERE user_id = ? AND status = 'published'", [$id]);
        $posts = (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_posts p JOIN cms_community_topics t ON t.id = p.topic_id WHERE p.user_id = ? AND p.status = 'published' AND t.status = 'published'", [$id]);
        if ((int) $s['topic_count'] !== $topics || (int) $s['post_count'] !== $posts) {
            $bad[] = "Mitglied {$id}: topic_count/post_count {$s['topic_count']}/{$s['post_count']} statt {$topics}/{$posts}";
        }
    }

    return $bad;
}

/**
 * Alle Zeilen von `community_user_stats` nach Mitglied.
 *
 * @return array<int, array<string, mixed>>
 */
function cdbStatsRows(\CMS\Database $db): array
{
    $rows = [];
    foreach ($db->execute('SELECT * FROM cms_community_user_stats ORDER BY user_id')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $rows[(int) $row['user_id']] = $row;
    }

    return $rows;
}

/**
 * Statistikzeilen nach einer Rücknahme: bestehende unverändert, neue leer (nichts gezählt).
 *
 * @param array<int, array<string, mixed>> $before
 * @param array<int, array<string, mixed>> $after
 * @return list<string>
 */
function cdbStatsProblems(array $before, array $after): array
{
    $problems = [];
    foreach ($before as $id => $row) {
        if (($after[$id] ?? null) !== $row) {
            $problems[] = 'Statistikzeile ' . $id . ' verändert';
        }
    }
    foreach (array_diff_key($after, $before) as $id => $row) {
        if ((int) $row['topic_count'] !== 0 || (int) $row['post_count'] !== 0 || $row['last_post_at'] !== null) {
            $problems[] = 'neue Statistikzeile ' . $id . ' ist nicht leer';
        }
    }

    return $problems;
}

/** Tabellen der Zustandsprüfung („nichts geschrieben“). */
const CDB_TABLES = [
    'community_forums', 'community_topics', 'community_posts', 'community_post_revisions', 'community_user_stats',
    'community_read_states', 'community_forum_read_marks',
];

/**
 * Inhalt aller beteiligten Tabellen als ein Hash (für „nichts geschrieben“). Ohne `$withStats` bleibt `community_user_stats`
 * außen vor (eine leere Statistikzeile unter der Sperre ist laut Vertrag zulässig, wenn die Rechteprüfung unter der Sperre ablehnt).
 */
function cdbFingerprint(\CMS\Database $db, bool $withStats = true): string
{
    $parts = [];
    foreach (CDB_TABLES as $table) {
        if (!$withStats && $table === 'community_user_stats') {
            continue;
        }
        $parts[$table] = $db->execute("SELECT * FROM cms_{$table} ORDER BY 1, 2")->fetchAll(\PDO::FETCH_ASSOC);
    }

    return md5((string) json_encode($parts));
}

/**
 * Alle DATETIME-Spalten der Community-Tabellen: Zeilen mit einem Wert nahe der echten Zeit. Die Uhr der Dienste steht in den
 * Ende-zu-Ende-Abläufen im Jahr 2036; ein Wert um „jetzt“ stammt aus einem Spaltendefault (`CURRENT_TIMESTAMP` der Sitzung,
 * Ortszeit +01:00) oder aus `NOW()`, nicht von der Uhr der Dienste. Geprüft wird das Fenster von zehn Minuten vor UTC-Jetzt bis
 * zehn Minuten nach Jetzt in Sitzungszeit.
 *
 * @return list<string> Spalten mit Werten im Fenster (mit Zahl der Zeilen)
 */
function cdbRealTimeColumns(MariaDbTestDatabase $db): array
{
    $found = [];
    $from = gmdate('Y-m-d H:i:s', time() - 600);
    $to = gmdate('Y-m-d H:i:s', time() + 3600 + 600);
    $columns = $db->execute(
        "SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = ? AND table_name LIKE 'cms\\_community\\_%' AND data_type IN ('datetime', 'timestamp')",
        [$db->databaseName]
    )->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        $rows = (int) cdbScalar($db, 'SELECT COUNT(*) FROM `' . $column['t'] . '` WHERE `' . $column['c'] . '` BETWEEN ? AND ?', [$from, $to]);
        if ($rows > 0) {
            $found[] = $column['t'] . '.' . $column['c'] . ' (' . $rows . ' Zeilen)';
        }
    }

    return $found;
}

/** Inhalt der Logdatei dieses Prozesses (Warnungen der Dienste), danach geleert. */
function cdbLogs(): string
{
    $text = '';
    foreach (glob(LOG_PATH . '*') ?: [] as $file) {
        $text .= (string) file_get_contents($file);
        @unlink($file);
    }

    return $text;
}

/** Zahl der Deadlocks des Servers seit Start (globaler Zähler). */
function cdbDeadlocks(): int
{
    $pdo = new \PDO(
        'mysql:host=' . getenv('CMS_TEST_DB_HOST') . ';charset=utf8mb4',
        (string) getenv('CMS_TEST_DB_USER'),
        (string) getenv('CMS_TEST_DB_PASS'),
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
    $row = $pdo->query("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")->fetch(\PDO::FETCH_NUM);

    return $row === false ? 0 : (int) $row[1];
}

/**
 * Startet Worker (`worker.php`) und wartet, bis sich alle bereit gemeldet haben. Jeder Auftrag: `action`, `args`, `settings`,
 * optional `hold`, `lockTimeout`, `isolation` (siehe worker.php).
 *
 * @param list<array<string, mixed>> $jobs
 * @return array{dir: string, procs: list<resource>, pipes: list<array<int, resource>>, count: int}
 */
function cdbSpawn(MariaDbTestDatabase $db, array $jobs): array
{
    $dir = sys_get_temp_dir() . '/cms-race-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);
    $procs = [];
    $pipes = [];
    foreach ($jobs as $i => $job) {
        $job += ['db' => $db->databaseName, 'ready' => $dir . '/ready' . $i, 'start' => $dir . '/start'];
        file_put_contents($dir . '/job' . $i . '.json', json_encode($job));
        $procs[$i] = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $dir . '/job' . $i . '.json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, null);
        stream_set_blocking($pipes[$i][1], false);
        stream_set_blocking($pipes[$i][2], false);
    }
    $deadline = microtime(true) + 30;
    while (count(glob($dir . '/ready*') ?: []) < count($jobs) && microtime(true) < $deadline) {
        usleep(2000);
    }

    return ['dir' => $dir, 'procs' => $procs, 'pipes' => $pipes, 'count' => count($jobs)];
}

/**
 * Gibt das Startsignal (alle gleichzeitig), wartet auf das Ende aller Prozesse und liefert je Auftrag die dekodierte JSON-Zeile
 * (`result`, `exception`, `ms`, `log`, …) plus `exit` und `stderr`.
 *
 * @param array{dir: string, procs: list<resource>, pipes: list<array<int, resource>>, count: int} $handle
 * @return list<array<string, mixed>>
 */
function cdbFinish(array $handle, bool $start = true, int $timeout = 90): array
{
    ['dir' => $dir, 'procs' => $procs, 'pipes' => $pipes, 'count' => $count] = $handle;
    if ($start) {
        touch($dir . '/start');
    }
    $out = array_fill(0, $count, '');
    $err = array_fill(0, $count, '');
    $exit = array_fill(0, $count, null);
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $running = 0;
        foreach ($procs as $i => $proc) {
            $out[$i] .= (string) stream_get_contents($pipes[$i][1]);
            $err[$i] .= (string) stream_get_contents($pipes[$i][2]);
            $status = proc_get_status($proc);
            if ($status['running']) {
                $running++;
            } elseif ($exit[$i] === null) {
                $exit[$i] = $status['exitcode'];
                $out[$i] .= (string) stream_get_contents($pipes[$i][1]);
                $err[$i] .= (string) stream_get_contents($pipes[$i][2]);
            }
        }
        if ($running === 0) {
            break;
        }
        usleep(2000);
    }
    $results = [];
    foreach ($procs as $i => $proc) {
        if ($exit[$i] === null) {
            proc_terminate($proc);
        }
        proc_close($proc);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out[$i]))));
        $decoded = json_decode((string) end($lines), true);
        $results[] = (is_array($decoded) ? $decoded : ['decode_error' => substr($out[$i], -300)]) + ['exit' => $exit[$i], 'stderr' => trim($err[$i])];
    }
    array_map('unlink', glob($dir . '/*') ?: []);
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
        array_map('unlink', glob($sub . '/*') ?: []);
        @rmdir($sub);
    }
    @rmdir($dir);

    return $results;
}

/**
 * Startet Worker und lässt sie gleichzeitig loslaufen (Startsignal per Datei).
 *
 * @param list<array<string, mixed>> $jobs
 * @return list<array<string, mixed>>
 */
function cdbRace(MariaDbTestDatabase $db, array $jobs): array
{
    return cdbFinish(cdbSpawn($db, $jobs));
}

/**
 * Prüft, ob die Zeile `$table`.`$key` = `$id` von einer zweiten Verbindung gesperrt werden kann. Rückgabe: `blocked`
 * (Fehler 1205 Lock-Timeout, bei NOWAIT auch MySQL 3572), `free` oder `error:<Text>`. Die Verbindung (`$db->connection()`) gibt
 * der Aufrufer mit. Mit `$wait` wartet die Probe den echten Lock-Timeout von einer Sekunde ab (`innodb_lock_wait_timeout = 1`),
 * sonst bricht sie sofort ab (`NOWAIT`, MariaDB ab 10.3 und MySQL ab 8.0; ältere Server: Timeout).
 */
function cdbProbeLock(\PDO $pdo, string $table, string $key, int $id, bool $wait = false): string
{
    static $nowait = null;
    $nowait ??= (bool) preg_match('/MariaDB|^8\./', (string) $pdo->query('SELECT VERSION()')->fetchColumn());
    try {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdo->beginTransaction();
        $pdo->prepare("SELECT 1 FROM cms_{$table} WHERE {$key} = ? FOR UPDATE" . ($nowait && !$wait ? ' NOWAIT' : ''))->execute([$id]);
        $pdo->rollBack();

        return 'free';
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return in_array((int) ($e->errorInfo[1] ?? 0), [1205, 3572], true) ? 'blocked' : 'error:' . $e->getMessage();
    }
}

/**
 * Prüft, ob eine zweite Verbindung eine Statistikzeile für `$userId` einfügen kann (Lückensperre?). Eine Lückensperre eines
 * Dienstaufrufs, der auf eine fehlende Statistikzeile gesperrt hat, lässt das INSERT in den Lock-Timeout laufen (`blocked`).
 * Rückgabe: `blocked` (1205), `free` oder `error:<Text>`; die Zeile wird zurückgerollt.
 */
function cdbProbeInsert(\PDO $pdo, int $userId): string
{
    try {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO cms_community_user_stats (user_id, updated_at) VALUES (?, ?)')->execute([$userId, gmdate('Y-m-d H:i:s')]);
        $pdo->rollBack();

        return 'free';
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return (int) ($e->errorInfo[1] ?? 0) === 1205 ? 'blocked' : 'error:' . $e->getMessage();
    }
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// (a) Ende-zu-Ende: Anlegen, Antworten, Bearbeiten, Löschen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

cdbSection('e2e', 'Ende-zu-Ende (echte CommunityAccess, MariaDB): Anlegen, Antworten, Bearbeiten, Löschen mit Zähler-Abgleich und UTC', static function (): void {
    $w = new CdbWorld(['new_member_approval_posts' => 0]);
    $db = $w->db;
    $alice = $db->user(['username' => 'alice']);
    $bob = $db->user(['username' => 'bob']);
    $mod = $db->user(['username' => 'moda', 'role' => 'moderator']);
    $forum = $db->forum(['title' => 'Allgemein', 'slug' => 'allgemein']);
    // Rechte-Zeilen (Whitelist): Mitglieder lesen, schreiben, bearbeiten und löschen Eigenes; Gäste haben keine Zeile.
    (new ForumRepository($db))->savePermissions($forum, [
        ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1, 'create_topic' => 1, 'reply' => 1, 'edit_own' => 1, 'delete_own' => 1]],
    ], cdbUtc());
    $audit = static fn (string $step) => T::same([], cdbAudit($db), 'Zähler nach „' . $step . '“ stimmen mit der Neuberechnung per COUNT überein');

    // 1. Thema anlegen (t0)
    $created = $w->topics->create($alice, $forum, "  Erstes   Thema \n", "Hallo\r\nWelt");
    T::same(['success' => true, 'status' => 'published', 'slug' => 'erstes-thema'], array_intersect_key($created, ['success' => 1, 'status' => 1, 'slug' => 1]), 'Thema angelegt, veröffentlicht, Slug aus dem Titel');
    $topic = (int) ($created['topic_id'] ?? 0);
    $first = (int) ($created['post_id'] ?? 0);
    T::same(['Erstes Thema', cdbUtc(), cdbUtc(), $first, $first, $alice, 2], [$w->topic($topic)['title'], $w->topic($topic)['created_at'], $w->topic($topic)['last_post_at'], (int) $w->topic($topic)['first_post_id'], (int) $w->topic($topic)['last_post_id'], (int) $w->topic($topic)['last_poster_id'], (int) $w->topic($topic)['version']], 'Thema: Titel normalisiert, Zeiten UTC (nicht Ortszeit 12:00/11:00), first/last_post_id, version 2');
    T::same(["Hallo\nWelt", 1, cdbUtc(), 'markdown'], [$w->post($first)['body'], (int) $w->post($first)['is_first_post'], $w->post($first)['created_at'], $w->post($first)['format']], 'Beitrag: Zeilenenden normalisiert, Themenstart, UTC');
    $forumRow = $w->forum($forum);
    T::same([1, 1, $first, $topic, $alice, cdbUtc()], [(int) $forumRow['topic_count'], (int) $forumRow['post_count'], (int) $forumRow['last_post_id'], (int) $forumRow['last_topic_id'], (int) $forumRow['last_poster_id'], $forumRow['last_post_at']], 'Forum: Zähler und last_* nach dem Anlegen');
    T::same([1, 1, cdbUtc()], [(int) $w->stats($alice)['topic_count'], (int) $w->stats($alice)['post_count'], $w->stats($alice)['last_post_at']], 'Mitglied: Zähler und last_post_at');
    T::same($first, (int) cdbScalar($db, 'SELECT last_read_post_id FROM cms_community_read_states WHERE user_id = ? AND topic_id = ?', [$alice, $topic]), 'Lesestatus des Autors');
    T::same([['cms_community_topic_created', [$topic, $forum, $alice, 'published', $first], false]], array_map(static fn (array $h): array => [$h['hook'], $h['args'], $h['in_transaction']], $w->hooks), 'genau ein Hook, ausgelöst nach dem Commit');
    $audit('Thema anlegen');

    // 2. Antworten (bob, alice)
    $w->at(60);
    $replyBob = $w->posts->reply($bob, $topic, 'Antwort von Bob');
    T::same([true, 'published', 2, 1], [$replyBob['success'] ?? null, $replyBob['status'] ?? null, $replyBob['position'] ?? null, $replyBob['page'] ?? null], 'Antwort von Bob: veröffentlicht, Position 2, Seite 1');
    $bobPost = (int) ($replyBob['post_id'] ?? 0);
    T::same([cdbUtc(60), 1, $bobPost, $bob], [$w->post($bobPost)['created_at'], (int) $w->topic($topic)['reply_count'], (int) $w->topic($topic)['last_post_id'], (int) $w->topic($topic)['last_poster_id']], 'Antwort: UTC, reply_count 1, last_post_id/last_poster_id des Themas');
    T::same([0, 1], [(int) $w->stats($bob)['topic_count'], (int) $w->stats($bob)['post_count']], 'Mitglied Bob: 0 Themen, 1 Beitrag');
    $audit('Antwort Bob');
    $w->at(120);
    $replyAlice = $w->posts->reply($alice, $topic, 'Antwort von Alice');
    $alicePost = (int) ($replyAlice['post_id'] ?? 0);
    T::same([true, 3], [$replyAlice['success'] ?? null, $replyAlice['position'] ?? null], 'Antwort von Alice: Position 3');
    $w->at(121);
    $tooFast = $w->posts->reply($alice, $topic, 'Zu schnell hinterher');
    T::same(['rate_limited', 29], [$tooFast['error'] ?? null, $tooFast['retry_after'] ?? null], 'Abstand (rate_post_seconds 30): „zu schnell“ mit Restzeit 29 s');
    T::same(0, $db->count('community_posts', ['body' => 'Zu schnell hinterher']), 'abgelehnte Antwort schreibt nichts');
    $audit('Antwort Alice, Ablehnung');

    // 3. Bearbeiten (Autor im Fenster, fremder Beitrag, nach dem Fenster, Moderation)
    $w->at(180);
    $edit = $w->posts->edit($alice, $first, "Neuer Text\r\nmit Zeile", 'Zweiter Titel', 'Tippfehler');
    T::same([true, true, true, 1, 'zweiter-titel'], [$edit['success'] ?? null, $edit['changed'] ?? null, $edit['title_changed'] ?? null, $edit['edit_count'] ?? null, $edit['slug'] ?? null], 'Bearbeiten durch den Autor im Fenster: Text und Titel, edit_count 1');
    $revision = $db->row('community_post_revisions', ['post_id' => $first]);
    T::same(["Hallo\nWelt", 'Erstes Thema', $alice, 'Tippfehler', cdbUtc(180)], [$revision['body'] ?? null, $revision['title'] ?? null, (int) ($revision['edited_by'] ?? 0), $revision['edit_reason'] ?? null, $revision['created_at'] ?? null], 'Verlauf: ersetzte Fassung (alter Text und Titel), Bearbeiter, Grund, UTC');
    T::same(['Zweiter Titel', 'zweiter-titel', "Neuer Text\nmit Zeile", 1, null, 0, cdbUtc(180)], [$w->topic($topic)['title'], $w->topic($topic)['slug'], $w->post($first)['body'], (int) $w->post($first)['edit_count'], $w->post($first)['body_html'], (int) $w->post($first)['rendered_version'], $w->post($first)['edited_at']], 'Thema und Beitrag nach dem Bearbeiten (Titel, Slug, edit_count, Cache verworfen, edited_at UTC)');
    T::same(['no_permission'], [$w->posts->edit($bob, $first, 'Fremder Text')['error'] ?? null], 'ein anderes Mitglied darf nicht bearbeiten');
    $w->at(120 + 1801);
    T::same(['edit_window'], [$w->posts->edit($alice, $alicePost, 'Zu spät')['error'] ?? null], 'nach dem Fenster (30 Minuten ab Anlegen) nicht mehr bearbeitbar');
    $w->at(240);
    T::same([true, true], [$w->posts->edit($mod, $alicePost, 'Von der Moderation')['success'] ?? null, $w->post($alicePost)['body'] === 'Von der Moderation'], 'Moderation bearbeitet ohne Fenster');
    $audit('Bearbeiten');

    // 4. Löschen: Antwort (Autor), Thema ohne Antworten, Moderation
    $w->at(300);
    $deleted = $w->posts->delete($bob, $bobPost);
    T::same([true, 'deleted', 'published'], [$deleted['success'] ?? null, $deleted['mode'] ?? null, $deleted['previous_status'] ?? null], 'Antwort löschen (Autor): weich');
    T::same(['deleted', 'author', 1, 2, $alicePost, 0], [$w->post($bobPost)['status'], $w->post($bobPost)['status_reason'], (int) $w->topic($topic)['reply_count'], (int) $w->forum($forum)['post_count'], (int) $w->topic($topic)['last_post_id'], (int) $w->stats($bob)['post_count']], 'gelöschte Antwort: Status, Grund, reply_count 1, post_count 2, „letzter Beitrag“ neu bestimmt, Zähler des Autors');
    $audit('Antwort löschen');
    T::same(['first_post'], [$w->posts->delete($alice, $first)['error'] ?? null], 'der erste Beitrag wird über das Thema gelöscht');
    $w->at(360);
    $second = $w->topics->create($bob, $forum, 'Zweites Thema von Bob', 'Zweiter Text');
    $topic2 = (int) ($second['topic_id'] ?? 0);
    T::same([2, 3], [(int) $w->forum($forum)['topic_count'], (int) $w->forum($forum)['post_count']], 'zweites Thema: topic_count 2, post_count 3');
    $audit('zweites Thema');
    $w->at(420);
    $gone = $w->topics->delete($bob, $topic2);
    T::same([true, 'deleted', 'published'], [$gone['success'] ?? null, $gone['mode'] ?? null, $gone['previous_status'] ?? null], 'eigenes Thema ohne Antworten löschen');
    T::same([1, 2, $topic], [(int) $w->forum($forum)['topic_count'], (int) $w->forum($forum)['post_count'], (int) $w->forum($forum)['last_topic_id']], 'Forum nach dem Löschen: Zähler und letztes Thema neu bestimmt');
    T::same(['has_replies'], [$w->topics->delete($alice, $topic)['error'] ?? null], 'Thema mit Antworten: der Autor darf nicht löschen');
    $audit('Thema löschen');
    $w->at(480);
    T::same([true, 'moderator'], [$w->posts->delete($mod, $alicePost)['success'] ?? null, $w->post($alicePost)['status_reason']], 'Moderation löscht fremde Antwort (Grund moderator)');
    $audit('Moderation löscht');

    // 5. Listen mit echter CommunityAccess
    $page = $w->posts->listPage($bob, $topic);
    T::same([true, [$first, $bobPost, $alicePost], ['full', 'placeholder', 'placeholder']], [$page['success'] ?? null, array_map(static fn (array $p): int => (int) $p['id'], $page['items'] ?? []), array_map(static fn (array $p): string => (string) $p['view'], $page['items'] ?? [])], 'Beitragsliste: Themenstart voll, gelöschte Beiträge als Platzhalter');
    $list = $w->topics->listForum($bob, $forum);
    T::same([true, [$topic]], [$list['success'] ?? null, array_map(static fn (array $t): int => (int) $t['id'], $list['items'] ?? [])], 'Themenliste: nur das veröffentlichte Thema');
    T::same(['not_found'], [$w->topics->listForum(null, $forum)['error'] ?? null], 'Gast liest nicht (guests_can_read aus)');

    // 6. Hooks und Zeit
    T::same(
        ['cms_community_topic_created', 'cms_community_post_created', 'cms_community_post_created', 'cms_community_post_updated', 'cms_community_topic_updated', 'cms_community_post_updated', 'cms_community_post_deleted', 'cms_community_topic_created', 'cms_community_topic_deleted', 'cms_community_post_deleted'],
        $w->hookNames(),
        'Hooks in Reihenfolge der erfolgreichen Schreibaktionen'
    );
    T::same([], array_values(array_filter($w->hooks, static fn (array $h): bool => $h['in_transaction'])), 'kein Hook innerhalb einer Transaktion');
    T::same([], cdbRealTimeColumns($db), 'kein Zeitwert aus einem Spaltendefault (CURRENT_TIMESTAMP der Sitzung): alle DATETIME-Spalten der Community-Tabellen stammen von der Uhr der Dienste');
    T::same('', cdbLogs(), 'keine Warnungen im Protokoll');
});

cdbSection('e2e-approval', 'Ende-zu-Ende (MariaDB): Freigabepflicht, Sperre, Fenster und Lesestatus mit echter CommunityAccess', static function (): void {
    $w = new CdbWorld();   // new_member_approval_posts = 2 (Standard)
    $db = $w->db;
    $carol = $db->user();
    $dave = $db->user();
    $forum = $db->forum();
    $audit = static fn (string $step) => T::same([], cdbAudit($db), 'Zähler nach „' . $step . '“ stimmen mit der Neuberechnung überein');

    $a = $w->topics->create($carol, $forum, 'Wartendes Thema', 'Erster Beitrag eines neuen Mitglieds');
    $topicA = (int) ($a['topic_id'] ?? 0);
    T::same([true, 'pending'], [$a['success'] ?? null, $a['status'] ?? null], 'neues Mitglied (post_count 0 < 2): Thema wartet auf Freigabe');
    T::same([0, 0, 0, cdbUtc()], [(int) $w->forum($forum)['topic_count'], (int) $w->forum($forum)['post_count'], (int) ($w->stats($carol)['post_count'] ?? -1), $w->topic($topicA)['last_post_at']], 'wartend zählt nirgends; last_post_at des Themas nie NULL');
    T::same([null, null], [$w->topic($topicA)['last_post_id'], $w->forum($forum)['last_post_id']], 'wartend: last_post_id des Themas und des Forums bleiben leer');
    $audit('wartendes Thema');
    T::same('not_found', $w->posts->reply($dave, $topicA, 'Antwort auf wartendes Thema')['error'] ?? null, 'ein fremdes Mitglied sieht das wartende Thema nicht');

    // Eigene Themen der Moderation/Admin sind frei; ein Thema mit Freigabe über die Forum-Einstellung
    $w->at(120);
    $approvalForum = $db->forum(['require_approval' => true]);
    $b = $w->topics->create($dave, $approvalForum, 'Thema im Forum mit Freigabepflicht', 'Text');
    T::same('pending', $b['status'] ?? null, 'Forum mit Freigabepflicht: Thema wartet');
    $audit('Forum mit Freigabepflicht');

    // Sperre blockiert, Aufheben gibt frei
    $w->at(240);
    $erin = $db->user();
    $banId = $db->ban(['user' => $erin, 'scope' => 'write', 'starts_at' => cdbUtc(-3600)]);
    T::same('banned_write', $w->topics->create($erin, $forum, 'Gesperrtes Mitglied', 'Text')['error'] ?? null, 'Schreibsperre: Anlegen abgelehnt');
    $w->rebuild();
    T::same(true, $w->access->can($erin, 'read', ['forum_id' => $forum])->allowed, 'Schreibsperre lässt Lesen zu');
    $db->execute('UPDATE cms_community_bans SET lifted_at = ? WHERE id = ?', [cdbUtc(200), $banId]);
    $w->rebuild();
    $afterLift = $w->topics->create($erin, $forum, 'Wieder frei nach Aufhebung', 'Text');
    T::same([true, 'pending'], [$afterLift['success'] ?? null, $afterLift['status'] ?? ($afterLift['error'] ?? null)], 'aufgehobene Sperre: Anlegen geht wieder (wartend, da neues Mitglied)');
    $limited = $w->topics->create($dave, $forum, 'Zweites Thema in der ersten Stunde', 'Text');
    T::same('trust_limit', $limited['error'] ?? null, 'Stufe 0: höchstens ein Thema je Stunde (topics_per_hour 1; wartende Themen zählen mit)');
    $audit('Sperre aufgehoben');

    // Lesestatus
    $w->at(400);
    $author = $db->user();
    $published = $w->topics->create($author, $forum, 'Veröffentlichtes Thema zum Lesen', 'Text', []);
    T::same('pending', $published['status'] ?? null, 'Autor ohne Beiträge: wartend (Freigabe nötig)');
    $db->execute('UPDATE cms_community_user_stats SET post_count = 5 WHERE user_id = ?', [$author]);
    $w->at(520);
    $open = $w->topics->create($author, $forum, 'Thema eines erfahrenen Mitglieds', 'Text');
    T::same('published', $open['status'] ?? null, 'post_count 5: veröffentlicht');
    $openId = (int) $open['topic_id'];
    T::same(true, $w->reads->unreadMap($carol, [$w->topic($openId)])[$openId] ?? null, 'für ein anderes Mitglied ungelesen');
    T::same(true, $w->reads->advance($carol, $openId, (int) $open['post_id']), 'Lesestatus steigt');
    T::same(false, $w->reads->advance($carol, $openId, (int) $open['post_id']), 'derselbe Stand schreibt nichts');
    T::same(false, $w->reads->unreadMap($carol, [$w->topic($openId)])[$openId] ?? null, 'danach gelesen');
    T::same([], cdbRealTimeColumns($db), 'kein Zeitwert aus einem Spaltendefault');
});

cdbSection('e2e-review', 'Ende-zu-Ende (MariaDB): Befunde der Nachprüfung (B6 letzter Beitrag nie NULL, B21 wiederholtes Löschen, B22/B23 Cursor und afterId an den Grenzen)', static function (): void {
    $w = new CdbWorld();   // new_member_approval_posts = 2 (Standard): ein neues Mitglied wartet auf Freigabe
    $db = $w->db;
    $newbie = $db->user();
    $author = $db->user();
    $mod = $db->user(['role' => 'moderator']);
    $forum = $db->forum();
    (new ForumRepository($db))->savePermissions($forum, [
        ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1, 'create_topic' => 1, 'reply' => 1, 'edit_own' => 1, 'delete_own' => 1]],
    ], cdbUtc());
    $nulls = static fn (): int => (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_topics WHERE last_post_at IS NULL');

    // B6: die Moderation antwortet in einem wartenden Thema und löscht die einzige veröffentlichte Antwort
    $pending = $w->topics->create($newbie, $forum, 'Wartendes Thema', 'Wartender Themenstart');
    T::same('pending', $pending['status'] ?? null, 'Vorbedingung: das Thema des Neulings wartet auf Freigabe');
    $topic = (int) $pending['topic_id'];
    $w->at(60);
    $reply = $w->posts->reply($mod, $topic, 'Antwort der Moderation');
    T::same([true, 'published', cdbUtc(60)], [$reply['success'] ?? null, $reply['status'] ?? null, $w->topic($topic)['last_post_at']], 'die Antwort der Moderation ist veröffentlicht, last_post_at zeigt sie');
    $w->at(120);
    T::same(true, $w->posts->delete($mod, (int) $reply['post_id'])['success'] ?? null, 'die Moderation löscht die einzige veröffentlichte Antwort');
    T::same([0, null, null, cdbUtc(), 0], [(int) $w->topic($topic)['reply_count'], $w->topic($topic)['last_post_id'], $w->topic($topic)['last_poster_id'], $w->topic($topic)['last_post_at'], $nulls()], 'last_post_at fällt auf die Anlagezeit des Themas zurück (nie NULL), last_post_id/last_poster_id sind leer');
    T::same([], cdbAudit($db), 'Neuberechnung: Zähler stimmen, last_post_at ist nirgends NULL');

    // B21: Doppelklick auf „Thema löschen“
    $w->at(200);
    $own = (int) $w->topics->create($author, $forum, 'Thema zum zweimal Löschen', 'Text')['topic_id'];
    $w->at(260);
    T::same(true, $w->topics->delete($author, $own)['success'] ?? null, 'erstes Löschen durch den Autor');
    $fingerprint = cdbFingerprint($db);
    $hooks = count($w->hooks);
    $again = $w->topics->delete($author, $own);
    T::same([true, true], [$again['success'] ?? null, $again['already_deleted'] ?? null], 'zweites Löschen durch den Autor: Erfolg mit already_deleted');
    T::same([true, true], [$w->topics->delete($mod, $own)['success'] ?? null, $w->topics->delete($mod, $own)['already_deleted'] ?? null], 'zweites Löschen durch die Moderation: ebenfalls');
    T::same('not_found', $w->topics->delete($newbie, $own)['error'] ?? null, 'ein fremdes Mitglied bekommt weiter not_found');
    T::same([$fingerprint, $hooks], [cdbFingerprint($db), count($w->hooks)], 'wirkungslos: nichts geschrieben, kein weiterer Hook');
    T::same([], cdbAudit($db), 'Zähler stimmen nach dem wiederholten Löschen');

    // B22: ein unmöglicher Zeitpunkt oder eine zu große ID im Cursor ergibt die erste Seite (nie eine leere Seite, nie db_error)
    $w->at(300);
    $ids = [];
    for ($i = 0; $i < 6; $i++) {
        $w->at(400 + $i * 600);
        $ids[] = (int) $w->topics->create($mod, $forum, 'Thema ' . $i, 'Text ' . $i)['topic_id'];
    }
    $w->at(5000);
    $firstPage = array_map(static fn (array $t): int => (int) $t['id'], $w->topics->listForum($author, $forum, null, 3)['items'] ?? []);
    T::same(3, count($firstPage), 'Vorbedingung: erste Seite mit drei Themen');
    foreach (['9999-99-99 99:99:99|5', '0000-00-00 00:00:00|5', '2036-13-45 25:61:61|5', '2036-02-30 10:00:00|5', '2036-06-15 24:00:00|5', '2036-06-15 10:00:00|4294967296'] as $impossible) {
        $cursor = rtrim(strtr(base64_encode($impossible), '+/', '-_'), '=');
        $page = $w->topics->listForum($author, $forum, $cursor, 3);
        T::same([true, $firstPage], [$page['success'] ?? null, array_map(static fn (array $t): int => (int) $t['id'], $page['items'] ?? [])], 'Cursor ' . $impossible . ': erste Seite');
    }

    // B23: afterId an der Grenze von int
    [$listed] = [$w->posts->listPage($author, $ids[0], PHP_INT_MAX)];
    T::same([true, []], [$listed['success'] ?? null, $listed['items'] ?? null], 'listPage mit afterId PHP_INT_MAX: leere letzte Seite, kein TypeError');
});

cdbSection('e2e-edge', 'Ende-zu-Ende (MariaDB): Grenzfälle der echten Spalten (200 Umlaute im Titel, 4-Byte-Zeichen, 50.000 Zeichen Text, Grund mit 255 Zeichen)', static function (): void {
    $w = new CdbWorld(['new_member_approval_posts' => 0, 'rate_post_seconds' => 0, 'rate_topic_seconds' => 0, 'trust_limits' => CDB_NOLIMIT_SETTINGS['trust_limits']]);
    $db = $w->db;
    $user = $db->user();
    $forum = cdbOpenForum($db);
    $step = 0;
    $at = static function () use ($w, &$step): void {
        $w->at(++$step * 60);
    };

    $title = str_repeat('ä', 200);
    $created = $w->topics->create($user, $forum, $title, 'Text zum langen Titel');
    T::same(true, $created['success'] ?? null, '200 Umlaute als Titel (title_max): angelegt, nicht db_error ' . json_encode(array_intersect_key($created, ['error' => 1])));
    $row = $w->topic((int) ($created['topic_id'] ?? 0)) ?? [];
    T::same([$title, 200, 100], [$row['title'] ?? null, mb_strlen((string) ($row['title'] ?? ''), 'UTF-8'), strlen((string) ($row['slug'] ?? ''))], 'der Titel kommt unverändert zurück (utf8mb4, 200 Zeichen); der Slug hat höchstens 100 Zeichen (Spalte: 210)');

    $at();
    $emojiTitle = 'Thema mit 😀 und 🎉 im Titel';
    $emojiBody = "Text mit Emoji 😀🎉 und Familie 👨‍👩‍👧 und Umlauten äöüß\nzweite Zeile";
    $emoji = $w->topics->create($user, $forum, $emojiTitle, $emojiBody);
    T::same(true, $emoji['success'] ?? null, '4-Byte-Zeichen in Titel und Text: angelegt ' . json_encode(array_intersect_key($emoji, ['error' => 1])));
    T::same([$emojiTitle, $emojiBody], [$w->topic((int) ($emoji['topic_id'] ?? 0))['title'] ?? null, $w->post((int) ($emoji['post_id'] ?? 0))['body'] ?? null], '4-Byte-Zeichen kommen unverändert zurück');

    $at();
    $maxBody = str_repeat('ä', 50000);
    $big = $w->topics->create($user, $forum, 'Thema mit dem größten erlaubten Text', $maxBody);
    T::same(true, $big['success'] ?? null, '50.000 Zeichen (post_max, 100.000 Byte) Text: angelegt ' . json_encode(array_intersect_key($big, ['error' => 1])));
    T::same(50000, mb_strlen((string) ($w->post((int) ($big['post_id'] ?? 0))['body'] ?? ''), 'UTF-8'), 'der Text kommt vollständig zurück');
    $at();
    T::same('body_too_long', $w->topics->create($user, $forum, 'Thema mit zu langem Text', $maxBody . 'x')['error'] ?? null, '50.001 Zeichen werden vor der Datenbank abgelehnt');

    $at();
    $reply = $w->posts->reply($user, (int) $created['topic_id'], 'Antwort für den langen Grund');
    $at();
    $edit = $w->posts->edit($user, (int) ($reply['post_id'] ?? 0), 'Bearbeitet mit langem Grund', null, str_repeat('ü', 255));
    T::same([true, 255], [$edit['success'] ?? null, mb_strlen((string) ($w->post((int) $reply['post_id'])['edit_reason'] ?? ''), 'UTF-8')], 'Bearbeitungsgrund mit 255 Zeichen (VARCHAR(255)) wird gespeichert ' . json_encode(array_intersect_key($edit, ['error' => 1])));
    $at();
    $edit2 = $w->posts->edit($user, (int) $reply['post_id'], 'Noch einmal bearbeitet', null, str_repeat('ü', 400));
    T::same([true, 255], [$edit2['success'] ?? null, mb_strlen((string) ($w->post((int) $reply['post_id'])['edit_reason'] ?? ''), 'UTF-8')], 'ein längerer Grund wird auf 255 Zeichen gekürzt (kein db_error) ' . json_encode(array_intersect_key($edit2, ['error' => 1])));
    T::same([], cdbAudit($db), 'Zähler stimmen mit der Neuberechnung überein');
    T::same('', cdbLogs(), 'keine Warnungen im Protokoll');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// (b) Rücknahme per Fehlerinjektion an jeder Anweisung
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Forum mit Rechte-Zeilen: Mitglieder lesen, schreiben, bearbeiten und löschen Eigenes. */
function cdbOpenForum(MariaDbTestDatabase $db, array $o = []): int
{
    $forum = $db->forum($o);
    (new ForumRepository($db))->savePermissions($forum, [
        ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1, 'create_topic' => 1, 'reply' => 1, 'edit_own' => 1, 'delete_own' => 1]],
    ], cdbUtc());

    return $forum;
}

/** Art einer Anweisung: `lock` (… FOR UPDATE), `write` (INSERT/UPDATE/DELETE) oder `read`. */
function cdbKind(string $sql): string
{
    if (str_contains($sql, ' FOR UPDATE')) {
        return 'lock';
    }

    return preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) === 1 ? 'write' : 'read';
}

/**
 * Führt eine Aktion einmal ungestört aus (Protokoll der Anweisungen) und danach einmal je Anweisung mit einem Fehler genau an
 * dieser Anweisung (Nummer 1 = erste Anweisung der Aktion, auch der Rechteprüfung vorab).
 *
 * Erwartung je Anweisung:
 *  - Schreib- und Sperranweisung: Ergebnis `db_error`, Zustand (alle Tabellen) unverändert.
 *  - Leseanweisung: entweder abgelehnt/`db_error` und nichts geschrieben (eine leere Statistikzeile unter der Sperre ist laut
 *    Vertrag zulässig), oder – wenn die Anweisung für den Ausgang unerheblich ist (zweite Abfrage für „Moderation“) – derselbe
 *    Erfolg wie im ungestörten Lauf; solche Anweisungen werden als Hinweis gemeldet.
 *  - Immer: kein Hook (außer beim unerheblichen Erfolg: genau die Hooks des ungestörten Laufs), keine offene Transaktion,
 *    keine hängende Sperre (Zeilen aus `$probes`), keine Ausnahme beim Aufrufer.
 *  - Danach gelingt dieselbe Aktion, und die Zähler stimmen.
 *
 * @param \Closure(CdbWorld): array{0: \Closure(CdbWorld): array<string, mixed>, 1: list<array{0: string, 1: string, 2: int}>} $scenario
 *        Fixtures aufbauen; liefert die Aktion und die Zeilen (Tabelle, Schlüsselspalte, ID), deren Sperre nicht hängen darf
 * @param array<string, mixed> $settings
 * @return array{problems: list<string>, statements: int, counts: array<string, int>, tolerated: list<string>}
 */
function cdbInjectAll(\Closure $scenario, array $settings = ['new_member_approval_posts' => 0]): array
{
    $baseWorld = new CdbWorld($settings);
    [$act] = $scenario($baseWorld);
    $baseWorld->hooks = [];
    $mark = count($baseWorld->db->log);
    $baseWorld->rebuild();
    $base = $act($baseWorld);
    $statements = array_slice($baseWorld->db->log, $mark);
    $baseHooks = $baseWorld->hookNames();
    $baseWorld->db->dispose();
    if (empty($base['success'])) {
        return ['problems' => ['ungestörter Lauf scheitert: ' . json_encode($base)], 'statements' => count($statements), 'counts' => [], 'tolerated' => []];
    }

    $problems = [];
    $tolerated = [];
    $counts = ['lock' => 0, 'write' => 0, 'read' => 0];
    $w = null;
    $probe = null;
    foreach ($statements as $index => $sql) {
        $number = $index + 1;
        $kind = cdbKind($sql);
        $counts[$kind]++;
        $label = '#' . $number . ' [' . $kind . '] ' . preg_replace('/\s+/', ' ', substr($sql, 0, 60));
        if ($w === null) {
            $w = new CdbWorld($settings);
            [$act, $probes] = $scenario($w);
            $probe = $w->db->connection();
        }
        $w->rebuild();
        $w->hooks = [];
        cdbLogs();
        $before = cdbFingerprint($w->db);
        $beforeWithoutStats = cdbFingerprint($w->db, false);
        $statsBefore = cdbStatsRows($w->db);
        $w->db->statementCount = 0;
        $w->db->failAtStatement = $number;
        $result = [];
        try {
            $result = $act($w);
        } catch (\Throwable $e) {
            $problems[] = $label . ': Ausnahme erreicht den Aufrufer: ' . $e::class . ': ' . $e->getMessage();
        }
        $w->db->failAtStatement = null;
        if ((string) getenv('CDB_DUMP') === (string) $number) {
            fwrite(STDERR, "DUMP #{$number}:\n" . implode("\n", array_map(static fn (string $q): string => '  ' . preg_replace('/\s+/', ' ', substr($q, 0, 110)), array_slice($w->db->log, -$w->db->statementCount))) . "\n");
        }
        T::info($label . ' => ' . json_encode(array_intersect_key($result, ['success' => 1, 'error' => 1])) . ' (' . $w->db->statementCount . ' Anweisungen)');
        if ($w->db->statementCount < $number) {
            $problems[] = $label . ': die Anweisung wurde nicht erreicht (' . $w->db->statementCount . ' Anweisungen): der Ablauf hängt vom Zustand ab';
        } elseif ($kind === 'read' && !empty($result['success'])) {
            // Unerheblich für den Ausgang: derselbe Erfolg wie im ungestörten Lauf.
            $tolerated[] = $label;
            if ($w->hookNames() !== $baseHooks) {
                $problems[] = $label . ': Erfolg trotz Ausfall, aber andere Hooks als im ungestörten Lauf: ' . json_encode($w->hookNames());
            }
        } else {
            if ($kind !== 'read' && ($result['error'] ?? null) !== 'db_error') {
                $problems[] = $label . ': erwartet db_error, ist ' . json_encode($result);
            }
            if ($kind === 'read' && !empty($result['success'])) {
                $problems[] = $label . ': Erfolg trotz Ausfall';
            }
            if ($w->hooks !== []) {
                $problems[] = $label . ': Hook trotz Fehler ausgelöst: ' . json_encode($w->hookNames());
            }
            if (cdbFingerprint($w->db, false) !== $beforeWithoutStats) {
                $problems[] = $label . ': Zustand nach dem Fehler verändert (Rücknahme fehlt)';
            } elseif (cdbFingerprint($w->db) !== $before) {
                // Die Statistikzeile des Mitglieds entsteht vor der Transaktion (ensure im Autocommit, Befund B3) und bleibt nach
                // einer Rücknahme als leere Zeile stehen: bestehende Zeilen unverändert, neue leer; danach aufräumen.
                foreach (cdbStatsProblems($statsBefore, cdbStatsRows($w->db)) as $problem) {
                    $problems[] = $label . ': ' . $problem;
                }
                $keep = $statsBefore === [] ? '0' : implode(',', array_keys($statsBefore));
                $w->db->execute('DELETE FROM cms_community_user_stats WHERE user_id NOT IN (' . $keep . ')');
            }
        }
        if ($w->db->getPdo()->inTransaction()) {
            $problems[] = $label . ': Transaktion bleibt offen';
            $w->db->getPdo()->rollBack();
        }
        foreach ($probes as [$table, $key, $id]) {
            if (cdbProbeLock($probe, $table, $key, $id) !== 'free') {
                $problems[] = $label . ': Sperre auf ' . $table . '.' . $key . ' = ' . $id . ' hängt';
            }
        }
        if (cdbFingerprint($w->db) !== $before) {
            // Zustand weicht ab (unerheblicher Erfolg oder Fehler): frische Welt für die nächste Anweisung.
            $w->db->dispose();
            $w = null;
        }
    }
    // Danach gelingt dieselbe Aktion (Zwischenspeicher neu), und die Zähler stimmen.
    if ($w === null) {
        $w = new CdbWorld($settings);
        [$act] = $scenario($w);
    }
    $w->rebuild();
    $w->hooks = [];
    $again = $act($w);
    if (empty($again['success'])) {
        $problems[] = 'nach allen Fehlern scheitert dieselbe Aktion: ' . json_encode($again);
    }
    if (cdbAudit($w->db) !== []) {
        $problems[] = 'Zähler nach dem Erfolg: ' . json_encode(cdbAudit($w->db));
    }

    return ['problems' => $problems, 'statements' => count($statements), 'counts' => $counts, 'tolerated' => $tolerated];
}

/**
 * Wie `cdbInjectAll()`, aber für eine Aktion, die der Zugriff ablehnt: Fällt irgendeine Anweisung aus, darf die Ablehnung nie
 * zum Erfolg werden (kein Fail-open, z. B. eine nicht lesbare Sperre gilt nicht als „keine Sperre“), und es wird nichts geschrieben.
 *
 * @param \Closure(CdbWorld): \Closure(CdbWorld): array<string, mixed> $scenario
 * @param array<string, mixed> $settings
 * @return array{problems: list<string>, statements: int, reason: string}
 */
function cdbInjectDenied(\Closure $scenario, string $reason, array $settings = ['new_member_approval_posts' => 0]): array
{
    $w = new CdbWorld($settings);
    $act = $scenario($w);
    $w->rebuild();
    $mark = count($w->db->log);
    $base = $act($w);
    $count = count($w->db->log) - $mark;
    $problems = [];
    if (($base['error'] ?? null) !== $reason) {
        return ['problems' => ['ungestörter Lauf liefert ' . json_encode($base) . ' statt ' . $reason], 'statements' => $count, 'reason' => $reason];
    }
    $before = cdbFingerprint($w->db, false);
    for ($number = 1; $number <= $count; $number++) {
        $w->rebuild();
        $w->hooks = [];
        $w->db->statementCount = 0;
        $w->db->failAtStatement = $number;
        $result = [];
        try {
            $result = $act($w);
        } catch (\Throwable $e) {
            $problems[] = '#' . $number . ': Ausnahme erreicht den Aufrufer: ' . $e->getMessage();
        }
        $w->db->failAtStatement = null;
        if (!empty($result['success'])) {
            $problems[] = '#' . $number . ' ' . preg_replace('/\s+/', ' ', substr($w->db->log[count($w->db->log) - $w->db->statementCount + $number - 1] ?? '', 0, 70)) . ': Fail-open, Ablehnung „' . $reason . '“ wird bei Ausfall dieser Anweisung zum Erfolg';
        }
        if ($w->hooks !== [] || cdbFingerprint($w->db, false) !== $before || $w->db->getPdo()->inTransaction()) {
            $problems[] = '#' . $number . ': Zustand, Hook oder Transaktion nach dem Ausfall nicht sauber';
            break;
        }
    }

    return ['problems' => $problems, 'statements' => $count, 'reason' => $reason];
}

/** Meldet das Ergebnis von `cdbInjectAll()` als eine Prüfung. */
function cdbReportInjection(string $what, array $report): void
{
    T::info($what . ': ' . $report['statements'] . ' Anweisungen (' . json_encode($report['counts']) . '); unerheblich für den Ausgang: ' . json_encode($report['tolerated']));
    T::check($report['statements'] >= 8 && $report['counts']['write'] >= 4 && $report['counts']['lock'] >= 2, $what . ': der Lauf enthält die erwarteten Schreib- und Sperranweisungen', json_encode($report['counts']));
    T::same([], $report['problems'], $what . ': an jeder Anweisung ausgefallen: Rücknahme, kein Hook, keine offene Transaktion, keine hängende Sperre');
}

cdbSection('inject-create', 'Rücknahme per Fehlerinjektion: Thema anlegen (jede Anweisung)', static function (): void {
    $report = cdbInjectAll(static function (CdbWorld $w): array {
        $author = $w->db->user();
        $forum = cdbOpenForum($w->db);

        return [
            static fn (CdbWorld $w): array => $w->topics->create($author, $forum, 'Thema mit Fehlerinjektion', 'Text des Themas'),
            [['community_forums', 'id', $forum], ['community_user_stats', 'user_id', $author]],
        ];
    });
    cdbReportInjection('create', $report);
});

cdbSection('inject-reply', 'Rücknahme per Fehlerinjektion: Antworten (jede Anweisung)', static function (): void {
    $report = cdbInjectAll(static function (CdbWorld $w): array {
        $author = $w->db->user();
        $replier = $w->db->user();
        $forum = cdbOpenForum($w->db);
        $topic = (int) $w->topics->create($author, $forum, 'Thema für die Antwort', 'Themenstart')['topic_id'];
        $w->at(120);

        return [
            static fn (CdbWorld $w): array => $w->posts->reply($replier, $topic, 'Antwort mit Fehlerinjektion'),
            [['community_forums', 'id', $forum], ['community_topics', 'id', $topic], ['community_user_stats', 'user_id', $replier]],
        ];
    });
    cdbReportInjection('reply', $report);
});

cdbSection('inject-edit', 'Rücknahme per Fehlerinjektion: Bearbeiten mit Titel (jede Anweisung)', static function (): void {
    $report = cdbInjectAll(static function (CdbWorld $w): array {
        $author = $w->db->user();
        $forum = cdbOpenForum($w->db);
        $post = (int) $w->topics->create($author, $forum, 'Titel vor dem Bearbeiten', 'Text vor dem Bearbeiten')['post_id'];
        $w->at(120);

        $topic = (int) $w->post($post)['topic_id'];

        return [
            static fn (CdbWorld $w): array => $w->posts->edit($author, $post, 'Text nach dem Bearbeiten', 'Titel nach dem Bearbeiten', 'Grund'),
            [['community_forums', 'id', $forum], ['community_topics', 'id', $topic], ['community_posts', 'id', $post]],
        ];
    });
    cdbReportInjection('edit', $report);
});

cdbSection('inject-post-delete', 'Rücknahme per Fehlerinjektion: Antwort löschen (jede Anweisung)', static function (): void {
    $report = cdbInjectAll(static function (CdbWorld $w): array {
        $author = $w->db->user();
        $replier = $w->db->user();
        $forum = cdbOpenForum($w->db);
        $topic = (int) $w->topics->create($author, $forum, 'Thema für die Löschung', 'Themenstart')['topic_id'];
        $w->at(120);
        $post = (int) $w->posts->reply($replier, $topic, 'Antwort, die gelöscht wird')['post_id'];
        $w->at(240);

        return [
            static fn (CdbWorld $w): array => $w->posts->delete($replier, $post),
            [['community_forums', 'id', $forum], ['community_topics', 'id', $topic], ['community_posts', 'id', $post], ['community_user_stats', 'user_id', $replier]],
        ];
    });
    cdbReportInjection('post.delete', $report);
});

cdbSection('inject-topic-delete', 'Rücknahme per Fehlerinjektion: Thema löschen (jede Anweisung)', static function (): void {
    $report = cdbInjectAll(static function (CdbWorld $w): array {
        $author = $w->db->user();
        $forum = cdbOpenForum($w->db);
        $topic = (int) $w->topics->create($author, $forum, 'Thema, das gelöscht wird', 'Themenstart')['topic_id'];
        $w->at(240);

        return [
            static fn (CdbWorld $w): array => $w->topics->delete($author, $topic),
            [['community_forums', 'id', $forum], ['community_topics', 'id', $topic], ['community_user_stats', 'user_id', $author]],
        ];
    });
    cdbReportInjection('topic.delete', $report);
});

cdbSection('denied', 'Ablehnung bleibt Ablehnung: fällt irgendeine Anweisung der Rechteprüfung aus, entsteht nie ein Erfolg (kein Fail-open)', static function (): void {
    $cases = [
        'banned_write' => static function (CdbWorld $w): \Closure {
            $user = $w->db->user();
            $forum = cdbOpenForum($w->db);
            $w->db->ban(['user' => $user, 'scope' => 'write', 'starts_at' => cdbUtc(-3600)]);

            return static fn (CdbWorld $w): array => $w->topics->create($user, $forum, 'Thema trotz Sperre', 'Text');
        },
        'banned_full' => static function (CdbWorld $w): \Closure {
            $author = $w->db->user();
            $user = $w->db->user();
            $forum = cdbOpenForum($w->db);
            $topic = (int) $w->topics->create($author, $forum, 'Thema für die vollsperre', 'Themenstart')['topic_id'];
            $w->db->ban(['user' => $user, 'scope' => 'full', 'starts_at' => cdbUtc(-3600)]);
            $w->at(300);

            return static fn (CdbWorld $w): array => $w->posts->reply($user, $topic, 'Antwort trotz Vollsperre');
        },
        'rate_limited' => static function (CdbWorld $w): \Closure {
            $author = $w->db->user();
            $forum = cdbOpenForum($w->db);
            $topic = (int) $w->topics->create($author, $forum, 'Thema für den Abstand', 'Themenstart')['topic_id'];
            $w->at(10);

            return static fn (CdbWorld $w): array => $w->posts->reply($author, $topic, 'Zu schnell hinterher');
        },
        'topic_locked' => static function (CdbWorld $w): \Closure {
            $author = $w->db->user();
            $user = $w->db->user();
            $forum = cdbOpenForum($w->db);
            $topic = (int) $w->topics->create($author, $forum, 'Thema ist geschlossen', 'Themenstart')['topic_id'];
            $w->db->execute('UPDATE cms_community_topics SET is_locked = 1 WHERE id = ?', [$topic]);
            $w->at(300);

            return static fn (CdbWorld $w): array => $w->posts->reply($user, $topic, 'Antwort im geschlossenen Thema');
        },
        'forum_archived' => static function (CdbWorld $w): \Closure {
            $user = $w->db->user();
            $forum = cdbOpenForum($w->db, ['status' => 'archived']);

            return static fn (CdbWorld $w): array => $w->topics->create($user, $forum, 'Thema im Archiv', 'Text');
        },
        'edit_window' => static function (CdbWorld $w): \Closure {
            $author = $w->db->user();
            $forum = cdbOpenForum($w->db);
            $post = (int) $w->topics->create($author, $forum, 'Thema mit abgelaufenem Fenster', 'Themenstart')['post_id'];
            $w->at(31 * 60);

            return static fn (CdbWorld $w): array => $w->posts->edit($author, $post, 'Zu spät bearbeitet');
        },
        'no_permission' => static function (CdbWorld $w): \Closure {
            $author = $w->db->user();
            $other = $w->db->user();
            $forum = cdbOpenForum($w->db);
            $post = (int) $w->topics->create($author, $forum, 'Fremder Beitrag', 'Themenstart')['post_id'];
            $w->at(300);

            return static fn (CdbWorld $w): array => $w->posts->edit($other, $post, 'Fremdes Bearbeiten');
        },
        'not_found' => static function (CdbWorld $w): \Closure {
            $user = $w->db->user();
            $forum = cdbOpenForum($w->db, ['status' => 'hidden']);

            return static fn (CdbWorld $w): array => $w->topics->create($user, $forum, 'Thema im verborgenen Forum', 'Text');
        },
    ];
    foreach ($cases as $reason => $scenario) {
        $report = cdbInjectDenied($scenario, $reason);
        T::info($reason . ': ' . $report['statements'] . ' Anweisungen');
        T::check($report['statements'] >= 1, $reason . ': die Aktion fragt die Datenbank', (string) $report['statements']);
        T::same([], $report['problems'], $reason . ': bei Ausfall jeder einzelnen Anweisung bleibt die Ablehnung eine Ablehnung (nichts geschrieben, kein Hook)');
    }
});


// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// (c) Wettlauf mit mehreren Prozessen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Probleme der Prozesse selbst: Ausnahme, Absturz, Ausgabe auf STDERR, offene Transaktion, `db_error` (ein durchgereichter
 * Deadlock 1213 ist ein Fehler, die Wiederholung in `Transaction::run` fängt ihn ab; ein Lock-Timeout 1205 wird nicht wiederholt und
 * erscheint als `db_error`).
 *
 * @param list<array<string, mixed>> $results
 * @return list<string>
 */
function cdbProcessProblems(array $results, bool $allowDbError = false): array
{
    $problems = [];
    foreach ($results as $i => $r) {
        $id = '#' . $i . ' (' . ($r['action'] ?? '?') . ')';
        if (isset($r['decode_error']) || ($r['exit'] ?? 1) !== 0) {
            $problems[] = $id . ': Prozess fehlgeschlagen (Exit ' . json_encode($r['exit'] ?? null) . ') ' . json_encode($r['decode_error'] ?? '') . ' ' . substr((string) ($r['stderr'] ?? ''), 0, 300);
        }
        if (isset($r['exception'])) {
            $problems[] = $id . ': Ausnahme erreicht den Aufrufer: ' . $r['exception'];
        }
        if (!$allowDbError && ($r['result']['error'] ?? null) === 'db_error') {
            $problems[] = $id . ': db_error (Datenbankfehler nach außen)' . (isset($r['log']) ? ' Log: ' . preg_replace('/\s+/', ' ', substr((string) $r['log'], -400)) : '');
        }
        if (!empty($r['in_transaction'])) {
            $problems[] = $id . ': Transaktion bleibt offen';
        }
    }

    return $problems;
}

/** Zeitstempel einer Zeile in Sekunden gegen die echte Zeit: UTC heißt höchstens 2 Minuten Abstand (Ortszeit wären 3600 oder 7200). */
function cdbNearNow(?string $utc): bool
{
    return $utc !== null && abs((int) strtotime($utc . ' UTC') - time()) < 120;
}

cdbSection('dup', 'Doppeltes Absenden nacheinander (echte CommunityAccess): dasselbe Ergebnis mit duplicate = true statt rate_limited', static function (): void {
    $w = new CdbWorld(['new_member_approval_posts' => 0]);
    $user = $w->db->user();
    $forum = cdbOpenForum($w->db);
    $first = $w->topics->create($user, $forum, 'Doppelt abgesendetes Thema', 'Gleicher Text');
    $w->at(2);
    $mark = count($w->db->log);
    $second = $w->topics->create($user, $forum, 'Doppelt abgesendetes Thema', 'Gleicher Text');
    T::check(
        ($second['duplicate'] ?? null) === true && ($second['topic_id'] ?? null) === $first['topic_id'],
        'B1: Thema zwei Sekunden später erneut abgesendet: das vorhandene Thema mit duplicate = true (Entwurf D16), nicht „rate_limited“',
        'Ergebnis ' . json_encode($second)
    );
    $inTransaction = array_filter(array_keys(array_slice($w->db->log, $mark, null, true)), static fn (int $i): bool => $w->db->txAt($i));
    T::same([], array_values($inTransaction), 'B1: die Dublette wird vorab erkannt, ohne Transaktion (keine Zeilensperren für einen abgelehnten Absender)');
    $topic = (int) $first['topic_id'];
    $w->at(100);
    $reply = $w->posts->reply($user, $topic, 'Gleiche Antwort');
    $w->at(102);
    $again = $w->posts->reply($user, $topic, 'Gleiche Antwort');
    T::check(
        ($again['duplicate'] ?? null) === true && ($again['post_id'] ?? null) === $reply['post_id'],
        'B1: Antwort zwei Sekunden später erneut abgesendet: die vorhandene Antwort mit duplicate = true, nicht „rate_limited“',
        'Ergebnis ' . json_encode($again)
    );
    T::same(['success' => false, 'error' => 'rate_limited', 'retry_after' => 28], $w->posts->reply($user, $topic, 'Eine andere Antwort'), 'ein anderer Text bleibt rate_limited (mit Restzeit)');
    T::same([1, 2], [$w->db->count('community_topics'), $w->db->count('community_posts')], 'in keinem Fall wird eine zweite Zeile geschrieben');
    T::same([], cdbAudit($w->db), 'Zähler stimmen mit der Neuberechnung überein');
});

cdbSection('race-dup', 'Wettlauf: acht Prozesse senden dasselbe Thema gleichzeitig (Idempotenz B: genau ein Thema), mehrere Runden', static function (): void {
    $deadlocks = cdbDeadlocks();
    for ($round = 1; $round <= 4; $round++) {
        // Runden 1 und 2 mit den Standardgrenzen (Abstand, eine Absendung je Stunde), Runden 3 und 4 ohne Grenzen: in beiden Fällen
        // bekommen alle sieben anderen das vorhandene Thema mit duplicate = true; eine Grenze verdeckt die Dublette nicht mehr (B1).
        $settings = $round > 2 ? CDB_NOLIMIT_SETTINGS : CDB_RACE_SETTINGS;
        $w = new CdbWorld($settings, time() - 7200);
        $user = $w->db->user();
        $forum = cdbOpenForum($w->db);
        if ($round % 2 === 0) {
            (new \CMS\Services\Community\Repository\UserStatsRepository($w->db))->ensure($user, $w->utc());   // Mitglied mit Vorgeschichte
        }
        $job = ['action' => 'topic.create', 'args' => ['user' => $user, 'forum' => $forum, 'title' => 'Wettlauf Thema', 'body' => 'gleicher Text'], 'settings' => $settings];
        $results = cdbRace($w->db, array_fill(0, 8, $job));
        T::same([], cdbProcessProblems($results), "Runde {$round}: keine Fehler in den Prozessen");
        $fresh = array_values(array_filter($results, static fn (array $r): bool => !empty($r['result']['success']) && empty($r['result']['duplicate'])));
        $duplicates = array_values(array_filter($results, static fn (array $r): bool => !empty($r['result']['success']) && !empty($r['result']['duplicate'])));
        $denied = array_values(array_filter($results, static fn (array $r): bool => in_array($r['result']['error'] ?? null, ['rate_limited', 'trust_limit'], true)));
        T::same([1, 7, 0], [count($fresh), count($duplicates), count($denied)], "Runde {$round}: ein neues Thema, sieben Wiederholungen mit duplicate = true, keine Ablehnung");
        T::info("Runde {$round}: " . count($duplicates) . ' x duplicate, ' . count($denied) . ' x abgelehnt vor der Sperre');
        foreach ($results as $r) {
            if (empty($r['result']['success']) && !in_array($r['result']['error'] ?? null, ['rate_limited', 'trust_limit'], true)) {
                T::info("Runde {$round}: Prozess ohne Erfolg: " . json_encode($r));
            }
        }
        T::same([1, 1], [$w->db->count('community_topics'), $w->db->count('community_posts')], "Runde {$round}: genau ein Thema und ein Beitrag in der Datenbank");
        $topicId = (int) ($fresh[0]['result']['topic_id'] ?? 0);
        T::same([$topicId], array_values(array_unique(array_map(static fn (array $r): int => (int) ($r['result']['topic_id'] ?? 0), array_merge($fresh, $duplicates)))), "Runde {$round}: alle erfolgreichen Prozesse bekommen dieselbe Thema-ID");
        T::same([1, 1, 1, 1], [(int) $w->forum($forum)['topic_count'], (int) $w->forum($forum)['post_count'], (int) $w->stats($user)['topic_count'], (int) $w->stats($user)['post_count']], "Runde {$round}: Zähler von Forum und Mitglied je 1");
        T::same([], cdbAudit($w->db), "Runde {$round}: Zähler stimmen mit der Neuberechnung überein");
        T::check(cdbNearNow($w->topic($topicId)['created_at'] ?? null) && cdbNearNow($w->post((int) $fresh[0]['result']['post_id'])['created_at'] ?? null), "Runde {$round}: Zeitstempel in UTC (echte Uhr, Sitzung +01:00, PHP Europe/Berlin)", (string) ($w->topic($topicId)['created_at'] ?? ''));
        $w->db->dispose();
    }
    T::info('Deadlocks des Servers während des Wettlaufs (von Transaction::run wiederholt): ' . (cdbDeadlocks() - $deadlocks));
});

cdbSection('race-dup-reply', 'Wettlauf: acht Prozesse senden dieselbe Antwort gleichzeitig (Idempotenz B bei Antworten: genau eine Antwort)', static function (): void {
    for ($round = 1; $round <= 3; $round++) {
        $w = new CdbWorld(CDB_NOLIMIT_SETTINGS, time() - 7200);
        $author = $w->db->user();
        $replier = $w->db->user();
        $forum = cdbOpenForum($w->db);
        $topic = (int) $w->topics->create($author, $forum, 'Thema für die doppelte Antwort', 'Themenstart')['topic_id'];
        $job = ['action' => 'post.reply', 'args' => ['user' => $replier, 'topic' => $topic, 'body' => 'Dieselbe Antwort achtmal'], 'settings' => CDB_NOLIMIT_SETTINGS];
        $results = cdbRace($w->db, array_fill(0, 8, $job));
        T::same([], cdbProcessProblems($results), "Runde {$round}: keine Fehler in den Prozessen");
        $fresh = array_filter($results, static fn (array $r): bool => !empty($r['result']['success']) && empty($r['result']['duplicate']));
        $duplicates = array_filter($results, static fn (array $r): bool => !empty($r['result']['success']) && !empty($r['result']['duplicate']));
        T::same([1, 7], [count($fresh), count($duplicates)], "Runde {$round}: eine neue Antwort, sieben Wiederholungen mit duplicate = true");
        T::same(1, $w->db->count('community_posts', ['topic_id' => $topic, 'is_first_post' => 0]), "Runde {$round}: genau eine Antwort in der Datenbank");
        T::same([1, 2], [(int) $w->topic($topic)['reply_count'], (int) $w->forum($forum)['post_count']], "Runde {$round}: reply_count 1, post_count 2");
        T::same(1, count(array_unique(array_map(static fn (array $r): int => (int) ($r['result']['post_id'] ?? 0), array_merge($fresh, $duplicates)))), "Runde {$round}: alle Prozesse bekommen dieselbe Beitrags-ID");
        T::same([], cdbAudit($w->db), "Runde {$round}: Zähler stimmen mit der Neuberechnung überein");
        $w->db->dispose();
    }
});

cdbSection('race-read', 'Wettlauf: Lesestatus steigt nur, auch wenn acht Prozesse gleichzeitig verschiedene Stände melden', static function (): void {
    for ($round = 1; $round <= 4; $round++) {
        $w = new CdbWorld(CDB_RACE_SETTINGS, time() - 7200);
        $author = $w->db->user();
        $reader = $w->db->user();
        $forum = cdbOpenForum($w->db);
        $topic = (int) $w->topics->create($author, $forum, 'Thema zum Lesen', 'Themenstart')['topic_id'];
        $posts = [(int) $w->db->rows('community_posts', ['topic_id' => $topic])[0]['id']];
        for ($i = 1; $i <= 8; $i++) {
            $w->at($i * 60);
            $posts[] = (int) $w->posts->reply($w->db->user(), $topic, 'Antwort ' . $i)['post_id'];
        }
        $order = [3, 8, 5, 1, 7, 8, 2, 6];
        if ($round % 2 === 0) {
            $order = array_reverse($order);
        }
        $jobs = [];
        foreach ($order as $index) {
            $jobs[] = ['action' => 'read.advance', 'args' => ['user' => $reader, 'topic' => $topic, 'post' => $posts[$index]], 'settings' => CDB_RACE_SETTINGS];
        }
        $results = cdbRace($w->db, $jobs);
        T::same([], cdbProcessProblems($results), "Runde {$round}: keine Fehler in den Prozessen");
        T::same($posts[8], (int) cdbScalar($w->db, 'SELECT last_read_post_id FROM cms_community_read_states WHERE user_id = ? AND topic_id = ?', [$reader, $topic]), "Runde {$round}: der höchste gemeldete Stand gilt (nie ein niedrigerer, auch nicht bei gleichzeitigem Schreiben)");
        T::same(1, $w->db->count('community_read_states', ['user_id' => $reader, 'topic_id' => $topic]), "Runde {$round}: genau eine Zeile (PRIMARY KEY user_id, topic_id)");
        T::check(count(array_filter($results, static fn (array $r): bool => !empty($r['result']['advanced']))) >= 1, "Runde {$round}: mindestens ein Prozess hat den Stand gesetzt oder erhöht");
        $w->db->dispose();
    }
});

cdbSection('race-reply', 'Wettlauf: acht verschiedene Mitglieder antworten gleichzeitig auf dasselbe Thema (kein verlorenes Update)', static function (): void {
    $deadlocks = cdbDeadlocks();
    for ($round = 1; $round <= 3; $round++) {
        $w = new CdbWorld(CDB_RACE_SETTINGS, time() - 7200);
        $author = $w->db->user();
        $forum = cdbOpenForum($w->db);
        $topicResult = $w->topics->create($author, $forum, 'Thema für gleichzeitige Antworten', 'Themenstart');
        $topic = (int) $topicResult['topic_id'];
        $members = [];
        for ($i = 0; $i < 8; $i++) {
            $members[$i] = $w->db->user();
            if ($round % 2 === 0) {
                (new \CMS\Services\Community\Repository\UserStatsRepository($w->db))->ensure($members[$i], $w->utc());   // Runde 2: Statistikzeilen vorhanden
            }
        }
        $jobs = [];
        foreach ($members as $i => $member) {
            $jobs[] = ['action' => 'post.reply', 'args' => ['user' => $member, 'topic' => $topic, 'body' => 'Gleichzeitige Antwort ' . $i], 'settings' => CDB_RACE_SETTINGS];
        }
        $results = cdbRace($w->db, $jobs);
        T::same([], cdbProcessProblems($results), "Runde {$round}: keine Fehler in den Prozessen (kein db_error, kein 1213 nach außen)");
        T::same(array_fill(0, 8, 'published'), array_map(static fn (array $r): mixed => $r['result']['status'] ?? ($r['result']['error'] ?? null), $results), "Runde {$round}: alle acht Antworten veröffentlicht");
        $positions = array_map(static fn (array $r): int => (int) ($r['result']['position'] ?? 0), $results);
        sort($positions);
        T::same([2, 3, 4, 5, 6, 7, 8, 9], $positions, "Runde {$round}: die Positionen 2 bis 9 sind eindeutig vergeben");
        $topicRow = $w->topic($topic);
        $maxPost = (int) cdbScalar($w->db, "SELECT MAX(id) FROM cms_community_posts WHERE topic_id = ? AND status = 'published'", [$topic]);
        T::same([8, 9, $maxPost, $maxPost], [(int) $topicRow['reply_count'], (int) $w->forum($forum)['post_count'], (int) $topicRow['last_post_id'], (int) $w->forum($forum)['last_post_id']], "Runde {$round}: reply_count 8, post_count 9, last_post_id von Thema und Forum = jüngster Beitrag");
        $maxAuthor = (int) cdbScalar($w->db, 'SELECT user_id FROM cms_community_posts WHERE id = ?', [$maxPost]);
        T::same([$maxAuthor, $maxAuthor], [(int) $topicRow['last_poster_id'], (int) $w->forum($forum)['last_poster_id']], "Runde {$round}: last_poster_id gehört zum jüngsten Beitrag");
        T::same(array_fill(0, 8, [0, 1]), array_map(static fn (int $m): array => [(int) $w->stats($m)['topic_count'], (int) $w->stats($m)['post_count']], $members), "Runde {$round}: Zähler jedes Mitglieds 0 Themen, 1 Beitrag");
        T::same([], cdbAudit($w->db), "Runde {$round}: Zähler stimmen mit der Neuberechnung überein");
        $w->db->dispose();
    }
    T::info('Deadlocks des Servers während des Wettlaufs (von Transaction::run wiederholt): ' . (cdbDeadlocks() - $deadlocks));
});

/**
 * Zwei Foren mit je einem Thema (Autoren verschieden), dazu ein Mitglied, das gleichzeitig in beiden antwortet.
 *
 * @return array{w: CdbWorld, member: int, topics: array{0: int, 1: int}, forums: array{0: int, 1: int}}
 */
function cdbTwoForums(array $settings, bool $statsRow): array
{
    $w = new CdbWorld($settings, time() - 7200);
    $forums = [cdbOpenForum($w->db), cdbOpenForum($w->db)];
    $topics = [];
    foreach ($forums as $i => $forum) {
        $topics[$i] = (int) $w->topics->create($w->db->user(), $forum, 'Thema in Forum ' . ($i + 1), 'Themenstart')['topic_id'];
    }
    $member = $w->db->user();
    if ($statsRow) {
        (new \CMS\Services\Community\Repository\UserStatsRepository($w->db))->ensure($member, $w->utc());
    }

    return ['w' => $w, 'member' => $member, 'topics' => $topics, 'forums' => $forums];
}

cdbSection('race-two-free', 'Wettlauf: dasselbe Mitglied antwortet gleichzeitig in zwei Foren (Abstände aus, kein verlorenes Update)', static function (): void {
    $deadlocks = cdbDeadlocks();
    $settings = CDB_RACE_SETTINGS + ['rate_post_seconds' => 0];
    for ($round = 1; $round <= 4; $round++) {
        $f = cdbTwoForums($settings, $round % 2 === 0);
        $w = $f['w'];
        $jobs = [];
        foreach ($f['topics'] as $i => $topic) {
            $jobs[] = ['action' => 'post.reply', 'args' => ['user' => $f['member'], 'topic' => $topic, 'body' => 'Gleichzeitig in Forum ' . ($i + 1)], 'settings' => $settings];
        }
        $results = cdbRace($w->db, $jobs);
        T::same([], cdbProcessProblems($results), "Runde {$round}: keine Fehler in den Prozessen");
        T::same(['published', 'published'], array_map(static fn (array $r): mixed => $r['result']['status'] ?? ($r['result']['error'] ?? null), $results), "Runde {$round}: beide Antworten veröffentlicht");
        T::same([0, 2], [(int) $w->stats($f['member'])['topic_count'], (int) $w->stats($f['member'])['post_count']], "Runde {$round}: Zähler des Mitglieds 2 Beiträge (kein verlorenes Update)");
        T::same([2, 2], [(int) $w->forum($f['forums'][0])['post_count'], (int) $w->forum($f['forums'][1])['post_count']], "Runde {$round}: beide Foren je 2 Beiträge");
        T::same([], cdbAudit($w->db), "Runde {$round}: Zähler stimmen mit der Neuberechnung überein");
        $w->db->dispose();
    }
    T::info('Deadlocks des Servers während des Wettlaufs (von Transaction::run wiederholt): ' . (cdbDeadlocks() - $deadlocks));
});

cdbSection('race-two-rate', 'Wettlauf: dasselbe Mitglied antwortet gleichzeitig in zwei Foren (Abstand 30 s): die Rechteprüfung unter der Sperre sieht die gleichzeitige Antwort', static function (): void {
    $deadlocks = cdbDeadlocks();
    // Standard-Isolationsstufe (REPEATABLE READ): ein Rückfall auf READ COMMITTED wäre kein Ausweg. Die Mitgliedersperre kommt vor
    // der ersten nicht sperrenden Leseanweisung der Transaktion, der Schnappschuss entsteht erst danach und sieht die fremde Antwort.
    $both = 0;
    $rounds = 8;
    for ($round = 1; $round <= $rounds; $round++) {
        $f = cdbTwoForums(CDB_RACE_SETTINGS, $round % 2 === 0);
        $w = $f['w'];
        $jobs = [];
        foreach ($f['topics'] as $i => $topic) {
            $jobs[] = ['action' => 'post.reply', 'args' => ['user' => $f['member'], 'topic' => $topic, 'body' => 'Gleichzeitig mit Abstand ' . ($i + 1)], 'settings' => CDB_RACE_SETTINGS];
        }
        $results = cdbRace($w->db, $jobs);
        T::same([], cdbProcessProblems($results), "Runde {$round}: keine Fehler in den Prozessen");
        $ok = count(array_filter($results, static fn (array $r): bool => !empty($r['result']['success'])));
        $limited = count(array_filter($results, static fn (array $r): bool => ($r['result']['error'] ?? null) === 'rate_limited'));
        T::same([$ok, $ok], [$ok, (int) $w->stats($f['member'])['post_count']], "Runde {$round}: Zähler des Mitglieds = Zahl der gespeicherten Antworten");
        T::same([], cdbAudit($w->db), "Runde {$round}: Zähler stimmen mit der Neuberechnung überein");
        T::same([1, 1], [$ok, $limited], "Runde {$round}: eine Antwort angenommen, die andere rate_limited (Abstand 30 s gilt über Foren hinweg)");
        $both += $ok === 2 ? 1 : 0;
        T::info("Runde {$round}: " . $ok . ' x Erfolg, ' . $limited . ' x rate_limited');
        $w->db->dispose();
    }
    T::same(0, $both, "B2: der Abstand zwischen Beiträgen (rate_post_seconds 30) hält bei gleichzeitigen Absendungen desselben Mitglieds in verschiedenen Foren (in {$both} von {$rounds} Runden wurden beide Antworten angenommen)");
    T::info('Deadlocks des Servers während des Wettlaufs (von Transaction::run wiederholt): ' . (cdbDeadlocks() - $deadlocks));
});

cdbSection('race-new', 'Wettlauf: neue Mitglieder legen gleichzeitig Themen in verschiedenen Foren an (acht und 16 Prozesse; Sperrreihenfolge, Deadlocks)', static function (): void {
    $deadlocks = cdbDeadlocks();
    $surfaced = 0;
    foreach ([8 => 3, 16 => 2] as $processes => $rounds) {
        for ($round = 1; $round <= $rounds; $round++) {
            $w = new CdbWorld(CDB_RACE_SETTINGS, time() - 7200);
            $jobs = [];
            $members = [];
            for ($i = 0; $i < $processes; $i++) {
                $forum = cdbOpenForum($w->db);
                $members[$i] = $w->db->user();
                $jobs[] = ['action' => 'topic.create', 'args' => ['user' => $members[$i], 'forum' => $forum, 'title' => 'Thema von Mitglied ' . $i, 'body' => 'Text ' . $i], 'settings' => CDB_RACE_SETTINGS];
            }
            $results = cdbRace($w->db, $jobs);
            T::same([], cdbProcessProblems($results, true), "{$processes} Prozesse, Runde {$round}: keine Ausnahme, kein Absturz, keine offene Transaktion");
            $failed = 0;
            foreach ($results as $i => $r) {
                $status = $r['result']['status'] ?? null;
                $error = $r['result']['error'] ?? null;
                if ($status !== 'published') {
                    $failed++;
                    T::check($error === 'db_error', "{$processes} Prozesse, Runde {$round}: Prozess {$i} scheitert höchstens mit db_error", json_encode($r['result'] ?? null));
                }
                $stats = $w->stats($members[$i]);
                T::same($status === 'published' ? [1, 1] : [0, 0], [(int) ($stats['topic_count'] ?? 0), (int) ($stats['post_count'] ?? 0)], "{$processes} Prozesse, Runde {$round}: Mitglied {$i}: Zähler passen zum Ergebnis (nichts Halbes)");
            }
            $surfaced += $failed;
            T::same([], cdbAudit($w->db), "{$processes} Prozesse, Runde {$round}: Zähler stimmen mit der Neuberechnung überein");
            $w->db->dispose();
        }
    }
    $delta = cdbDeadlocks() - $deadlocks;
    T::info("Deadlocks des Servers: {$delta}, davon als db_error nach außen: {$surfaced}");
    T::same(0, $surfaced, 'B3: bei acht und 16 gleichzeitigen neuen Mitgliedern in verschiedenen Foren wird nie ein db_error nach außen gereicht');
    T::check($delta <= CDB_DEADLOCK_BUDGET, 'B3: erstmals schreibende Mitglieder erzeugen höchstens ausnahmsweise einen Deadlock (von Transaction::run wiederholt): höchstens ' . CDB_DEADLOCK_BUDGET . ' bei 5 Läufen mit 8 und 16 Prozessen', (string) $delta . ' Deadlocks des Servers (vorher 58: SELECT … FOR UPDATE auf eine fehlende Statistikzeile sperrt die Lücke, danach INSERT IGNORE; dazu UPDATE auf eine fehlende Lesestatus-Zeile vor dem INSERT)');
});

cdbSection('race-mixed', 'Wettlauf: gemischte Last auf einem Forum (Antworten, Anlegen, Löschen durch die Moderation, Bearbeiten mit und ohne Titel)', static function (): void {
    $deadlocks = cdbDeadlocks();
    for ($round = 1; $round <= 3; $round++) {
        $w = new CdbWorld(CDB_RACE_SETTINGS, time() - 7200);
        $db = $w->db;
        $alice = $db->user();
        $b1 = $db->user();
        $b2 = $db->user();
        $mod = $db->user(['role' => 'moderator']);
        $forum = cdbOpenForum($db);
        $created = $w->topics->create($alice, $forum, 'Thema unter gemischter Last', 'Themenstart');
        $topic = (int) $created['topic_id'];
        $w->at(60);
        $p1 = (int) $w->posts->reply($b1, $topic, 'Antwort von b1')['post_id'];
        $w->at(120);
        $p2 = (int) $w->posts->reply($b2, $topic, 'Antwort von b2')['post_id'];
        $jobs = [];
        for ($i = 0; $i < 4; $i++) {
            $jobs[] = ['action' => 'post.reply', 'args' => ['user' => $db->user(), 'topic' => $topic, 'body' => 'Neue Antwort ' . $i], 'settings' => CDB_RACE_SETTINGS];
        }
        $jobs[] = ['action' => 'topic.create', 'args' => ['user' => $db->user(), 'forum' => $forum, 'title' => 'Neues Thema unter Last', 'body' => 'Text'], 'settings' => CDB_RACE_SETTINGS];
        $jobs[] = ['action' => 'post.delete', 'args' => ['user' => $mod, 'post' => $p1], 'settings' => CDB_RACE_SETTINGS];
        $jobs[] = ['action' => 'post.edit', 'args' => ['user' => $b2, 'post' => $p2, 'body' => 'Antwort von b2, bearbeitet'], 'settings' => CDB_RACE_SETTINGS];
        $jobs[] = ['action' => 'post.edit', 'args' => ['user' => $alice, 'post' => (int) $created['post_id'], 'body' => 'Themenstart, bearbeitet', 'title' => 'Thema unter gemischter Last, bearbeitet'], 'settings' => CDB_RACE_SETTINGS];
        $results = cdbRace($db, $jobs);
        T::same([], cdbProcessProblems($results), "Runde {$round}: keine Fehler in den Prozessen (kein db_error, kein 1213 nach außen)");
        T::same(array_fill(0, 8, true), array_map(static fn (array $r): mixed => $r['result']['success'] ?? ($r['result']['error'] ?? null), $results), "Runde {$round}: alle acht Aktionen erfolgreich");
        T::same([5, 7, 2], [(int) $w->topic($topic)['reply_count'], (int) $w->forum($forum)['post_count'], (int) $w->forum($forum)['topic_count']], "Runde {$round}: reply_count 5, post_count 7, topic_count 2");
        T::same(['deleted', 'Antwort von b2, bearbeitet', 'Themenstart, bearbeitet'], [$w->post($p1)['status'], $w->post($p2)['body'], $w->post((int) $created['post_id'])['body']], "Runde {$round}: Löschen und beide Bearbeitungen angekommen");
        T::same(2, $db->count('community_post_revisions'), "Runde {$round}: zwei Verlaufseinträge");
        T::same([], cdbAudit($db), "Runde {$round}: Zähler stimmen mit der Neuberechnung überein");
        $db->dispose();
    }
    T::info('Deadlocks des Servers während des Wettlaufs (von Transaction::run wiederholt): ' . (cdbDeadlocks() - $deadlocks));
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// (e) Echte FOR UPDATE-Sperren
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

cdbSection('locks', 'Echte Zeilensperren: solange ein Dienstaufruf seine Zeilen hält, läuft eine zweite Verbindung in den Lock-Timeout (1205)', static function (): void {
    $cases = [
        // Name => [Aktion, Zeilen, die gesperrt sind, Zeilen, die frei sind]
        'topic.create' => ['blocked' => ['forum', 'stats:actor'], 'free' => ['forum:other', 'topic', 'stats:other']],
        'post.reply' => ['blocked' => ['forum', 'topic', 'stats:actor'], 'free' => ['forum:other', 'stats:other']],
        'post.edit (mit Titel)' => ['blocked' => ['forum', 'topic', 'post'], 'free' => ['forum:other', 'stats:other']],
        'post.edit (nur Text)' => ['blocked' => ['topic', 'post'], 'free' => ['forum', 'stats:other']],
        'post.delete' => ['blocked' => ['forum', 'topic', 'post', 'stats:author'], 'free' => ['forum:other', 'stats:other']],
        'topic.delete' => ['blocked' => ['forum', 'topic', 'stats:actor'], 'free' => ['forum:other', 'stats:other']],
        // B3: ein erstmals schreibendes Mitglied hat seine Statistikzeile schon vor der Transaktion (ensure im Autocommit): die
        // Transaktion sperrt eine vorhandene Zeile (reine Zeilensperre) und hält keine Lückensperre, in die ein anderes neues
        // Mitglied einfügen müsste (vorher: SELECT … FOR UPDATE auf die fehlende Zeile, danach INSERT IGNORE: Deadlocks).
        'topic.create (neues Mitglied)' => ['blocked' => ['forum', 'stats:newbie'], 'free' => ['forum:other', 'stats:other', 'gap:behind-newbie']],
        'post.reply (neues Mitglied)' => ['blocked' => ['forum', 'topic', 'stats:newbie'], 'free' => ['forum:other', 'stats:other', 'gap:behind-newbie']],
    ];
    foreach ($cases as $name => $expect) {
        $w = new CdbWorld(CDB_RACE_SETTINGS, time() - 7200);
        $db = $w->db;
        $author = $db->user();
        $actor = $db->user();
        $other = $db->user();
        $mod = $db->user(['role' => 'moderator']);
        $forum = cdbOpenForum($db);
        $otherForum = cdbOpenForum($db);
        $created = $w->topics->create($author, $forum, 'Thema für die Sperrprobe', 'Themenstart');
        $topic = (int) $created['topic_id'];
        $firstPost = (int) $created['post_id'];
        $w->at(60);
        $reply = (int) $w->posts->reply($actor, $topic, 'Antwort des Handelnden')['post_id'];
        (new \CMS\Services\Community\Repository\UserStatsRepository($db))->ensure($other, $w->utc());
        $loner = $db->user();
        $alone = (int) $w->topics->create($loner, $forum, 'Thema ohne Antworten', 'Nur Themenstart')['topic_id'];
        $newbie = $db->user();
        $behindNewbie = $db->user();

        $job = match ($name) {
            'topic.create' => ['action' => 'topic.create', 'args' => ['user' => $actor, 'forum' => $forum, 'title' => 'Thema unter Sperre', 'body' => 'Text']],
            'post.reply' => ['action' => 'post.reply', 'args' => ['user' => $other, 'topic' => $topic, 'body' => 'Antwort unter Sperre']],
            'post.edit (mit Titel)' => ['action' => 'post.edit', 'args' => ['user' => $author, 'post' => $firstPost, 'body' => 'Neuer Text', 'title' => 'Neuer Titel unter Sperre']],
            'post.edit (nur Text)' => ['action' => 'post.edit', 'args' => ['user' => $actor, 'post' => $reply, 'body' => 'Neuer Text der Antwort']],
            'post.delete' => ['action' => 'post.delete', 'args' => ['user' => $mod, 'post' => $reply]],
            'topic.delete' => ['action' => 'topic.delete', 'args' => ['user' => $loner, 'topic' => $alone]],
            'topic.create (neues Mitglied)' => ['action' => 'topic.create', 'args' => ['user' => $newbie, 'forum' => $forum, 'title' => 'Thema des neuen Mitglieds unter Sperre', 'body' => 'Text']],
            'post.reply (neues Mitglied)' => ['action' => 'post.reply', 'args' => ['user' => $newbie, 'topic' => $topic, 'body' => 'Antwort des neuen Mitglieds unter Sperre']],
        };
        $subject = ['topic.create' => $actor, 'post.reply' => $other, 'post.delete' => $actor, 'topic.delete' => $loner][$name] ?? $actor;
        $topicOfAction = $name === 'topic.delete' ? $alone : $topic;
        $postOfAction = $name === 'post.edit (mit Titel)' ? $firstPost : $reply;
        $rows = [
            'forum' => ['community_forums', 'id', $forum],
            'forum:other' => ['community_forums', 'id', $otherForum],
            'topic' => ['community_topics', 'id', $topicOfAction],
            'post' => ['community_posts', 'id', $postOfAction],
            'stats:actor' => ['community_user_stats', 'user_id', $subject],
            'stats:author' => ['community_user_stats', 'user_id', $actor],
            'stats:other' => ['community_user_stats', 'user_id', $other === $subject ? $author : $other],
            'stats:newbie' => ['community_user_stats', 'user_id', $newbie],
            'gap:behind-newbie' => ['community_user_stats', 'user_id', $behindNewbie],
        ];
        $dir = sys_get_temp_dir() . '/cms-hold-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($dir, 0700, true);
        $hold = ['locked' => $dir . '/locked', 'release' => $dir . '/release'];
        $handle = cdbSpawn($db, [$job + ['settings' => CDB_RACE_SETTINGS, 'hold' => $hold]]);
        touch($handle['dir'] . '/start');
        $deadline = microtime(true) + 20;
        while (!is_file($hold['locked']) && microtime(true) < $deadline) {
            usleep(2000);
        }
        T::check(is_file($hold['locked']), "{$name}: der Dienstaufruf erreicht die Rechteprüfung unter den Sperren");
        $probe = $db->connection();
        $observed = [];
        foreach (['blocked', 'free'] as $state) {
            foreach ($expect[$state] as $key) {
                [$table, $column, $id] = $rows[$key];
                $observed[$key] = str_starts_with($key, 'gap:')
                    ? cdbProbeInsert($probe, $id)
                    : cdbProbeLock($probe, $table, $column, $id, $name === 'topic.create' && $state === 'blocked');
                T::same($state, $observed[$key], "{$name}: Zeile {$key} ist {$state} (zweite Verbindung, Lock-Timeout 1 s)");
            }
        }
        // Ein zweiter Dienstaufruf auf demselben Forum scheitert an derselben Sperre (Lock-Timeout 1205 ohne Wiederholung: db_error), schreibt nichts.
        if ($name === 'topic.create') {
            $second = new CdbWorld(CDB_RACE_SETTINGS, time() - 7200, $db);
            $second->db->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $before = cdbFingerprint($db);
            $started = microtime(true);
            $denied = $second->topics->create($other, $forum, 'Thema, das warten muss', 'Text');
            $waited = microtime(true) - $started;
            T::same('db_error', $denied['error'] ?? null, 'topic.create: ein zweiter Dienstaufruf auf demselben Forum scheitert an der Sperre (Lock-Timeout 1205, sofort db_error)');
            // B34: ein Lock-Timeout wird nicht wiederholt (sonst dreifache Wartezeit: bei 50 s Server-Standard etwa 150 s je Aufruf).
            T::check($waited >= 0.9, 'topic.create: der zweite Aufruf hat den Lock-Timeout (1 s) abgewartet', round($waited, 2) . ' s');
            T::check($waited < 1.9, 'topic.create: kein Wiederholen bei Lock-Timeout 1205 (genau ein Timeout, nicht drei)', round($waited, 2) . ' s');
            T::same($before, cdbFingerprint($db), 'topic.create: der gescheiterte zweite Aufruf schreibt nichts');
            $db->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = 50');
            cdbLogs();
        }
        touch($hold['release']);
        $results = cdbFinish($handle, false, 30);
        T::same([], cdbProcessProblems($results), "{$name}: der haltende Dienstaufruf endet ohne Fehler");
        T::same(true, $results[0]['result']['success'] ?? null, "{$name}: und ist erfolgreich", json_encode($results[0]['result'] ?? null));
        $after = [];
        foreach ($expect['blocked'] as $key) {
            [$table, $column, $id] = $rows[$key];
            $after[$key] = cdbProbeLock($probe, $table, $column, $id);
        }
        T::same(array_fill_keys($expect['blocked'], 'free'), $after, "{$name}: nach dem Commit sind alle Sperren frei");
        T::same([], cdbAudit($db), "{$name}: Zähler stimmen mit der Neuberechnung überein");
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
        $db->dispose();
    }
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// (f) UNIQUE-Wettlauf bei Forum-Slugs
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

cdbSection('slug', 'UNIQUE-Wettlauf: mehrere Prozesse legen dasselbe Forum mit demselben Slug an (genau einer gewinnt, die anderen bekommen slug_taken)', static function (): void {
    $w = new CdbWorld();
    for ($round = 1; $round <= 6; $round++) {
        $processes = $round <= 3 ? 2 : 4;
        $slug = 'wettlauf-' . $round;
        $jobs = [];
        for ($i = 0; $i < $processes; $i++) {
            $jobs[] = ['action' => 'forum.create', 'args' => ['data' => ['title' => 'Forum ' . $round . '/' . $i, 'slug' => $slug]]];
        }
        $results = cdbRace($w->db, $jobs);
        T::same([], cdbProcessProblems($results), "Runde {$round}: keine Fehler in den Prozessen");
        $winners = array_values(array_filter($results, static fn (array $r): bool => !empty($r['result']['success'])));
        $losers = array_values(array_filter($results, static fn (array $r): bool => ($r['result']['error'] ?? null) === 'slug_taken'));
        T::same([1, $processes - 1], [count($winners), count($losers)], "Runde {$round}: {$processes} Prozesse, genau ein Gewinner, alle anderen slug_taken");
        T::same(1, $w->db->count('community_forums', ['slug' => $slug]), "Runde {$round}: genau ein Forum mit dem Slug");
        $row = $w->db->row('community_forums', ['slug' => $slug]);
        T::same(['/' . ($winners[0]['result']['id'] ?? 0) . '/', 0], [$row['path'] ?? null, (int) ($row['depth'] ?? -1)], "Runde {$round}: Pfad und Tiefe des gewonnenen Forums vollständig (kein halbes Forum)");
    }
    // Dieselbe Spalte slug_en
    for ($round = 1; $round <= 3; $round++) {
        $jobs = [];
        for ($i = 0; $i < 2; $i++) {
            $jobs[] = ['action' => 'forum.create', 'args' => ['data' => ['title' => 'Englisch ' . $round . '/' . $i, 'slug' => 'de-' . $round . '-' . $i, 'slug_en' => 'en-' . $round]]];
        }
        $results = cdbRace($w->db, $jobs);
        T::same([1, 1], [count(array_filter($results, static fn (array $r): bool => !empty($r['result']['success']))), count(array_filter($results, static fn (array $r): bool => ($r['result']['error'] ?? null) === 'slug_taken'))], "slug_en Runde {$round}: genau ein Gewinner, ein slug_taken");
    }
    // Über Kreuz: slug des einen gleich slug_en des anderen (zwei verschiedene UNIQUE-Indizes greifen nicht)
    $violations = 0;
    for ($round = 1; $round <= 10; $round++) {
        $results = cdbRace($w->db, [
            ['action' => 'forum.create', 'args' => ['data' => ['title' => 'Kreuz A ' . $round, 'slug' => 'kreuz-' . $round]]],
            ['action' => 'forum.create', 'args' => ['data' => ['title' => 'Kreuz B ' . $round, 'slug' => 'andere-' . $round, 'slug_en' => 'kreuz-' . $round]]],
        ]);
        $violations += count(array_filter($results, static fn (array $r): bool => !empty($r['result']['success']))) === 2 ? 1 : 0;
    }
    T::same(0, $violations, "B4: ein Forum-Slug gleich slug_en eines anderen Forums entsteht auch im Wettlauf nicht (in {$violations} von 10 Runden wurden beide Foren angelegt)");
    // Umgekehrt: slug_en des einen gleich slug des anderen, in beiden Reihenfolgen der Auftragsliste
    $violations = 0;
    for ($round = 1; $round <= 6; $round++) {
        $jobs = [
            ['action' => 'forum.create', 'args' => ['data' => ['title' => 'Kreuz B ' . $round, 'slug' => 'andere-b-' . $round, 'slug_en' => 'umgekehrt-' . $round]]],
            ['action' => 'forum.create', 'args' => ['data' => ['title' => 'Kreuz A ' . $round, 'slug' => 'umgekehrt-' . $round]]],
        ];
        $results = cdbRace($w->db, $round % 2 === 0 ? array_reverse($jobs) : $jobs);
        T::same([], cdbProcessProblems($results), "Kreuz umgekehrt, Runde {$round}: keine Fehler in den Prozessen");
        $violations += count(array_filter($results, static fn (array $r): bool => !empty($r['result']['success']))) === 2 ? 1 : 0;
    }
    T::same(0, $violations, "B4: auch in der anderen Richtung höchstens ein Gewinner (in {$violations} von 6 Runden entstanden beide)");
    T::same(1, count($w->db->rows('settings', ['option_name' => 'community_lock_forum_slugs'])), 'B4: die Mutex-Zeile existiert genau einmal (INSERT IGNORE im Autocommit, kein Duplikat durch die Wettläufe)');
    T::same([], array_values(array_filter(array_map(static fn (array $row): ?string => (string) $row['option_name'], $w->db->rows('settings')), static fn (string $name): bool => str_starts_with($name, 'community.'))), 'B4: die Mutex-Zeile gehört nicht zur Einstellungsgruppe community (kein Punkt im Namen)');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// (d) EXPLAIN-Gate: 24.000 Themen, 100.000 Beiträge
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Füllt die Datenbank schnell per INSERT … SELECT: 40 Mitglieder, 20 Foren mit je 1000 Themen (5 % ausgeblendet, 2 % wartend,
 * wenige angepinnt, je fünf Themen mit gleichem Zeitstempel, etwa jedes siebte ohne Antwort), 100.000 Beiträge (je Thema fünf;
 * Themenstarts zuerst, 3 % ausgeblendet, 1,4 % gelöscht), ein Thema mit 5000 weiteren Beiträgen, ein ruhiges Forum mit 60 alten
 * Themen und ein großes älteres Forum mit 4000 Themen (16,6 % der Tabelle); das vierte Forum ist ein Q&A-Forum (alle Themen beantwortet, bis auf etwa 15 gelöst: `solution_post_id` und `solved_at`
 * gemeinsam, HANDOFF §2); danach ANALYZE TABLE.
 *
 * @return array{forums: list<int>, quiet: int, old: int, qa: int, users: list<int>, bigTopic: int, now: string, since: string}
 */
function cdbBulkLoad(MariaDbTestDatabase $db): array
{
    $pdo = $db->getPdo();
    $users = [];
    for ($i = 0; $i < 40; $i++) {
        $users[] = $db->user();
    }
    $forums = [];
    for ($i = 0; $i < 20; $i++) {
        $forums[] = $db->forum();
    }
    $pdo->exec('CREATE TABLE cdb_digits (d TINYINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_digits (d) VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)');
    $pdo->exec('CREATE TABLE cdb_numbers (n INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_numbers (n) SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d FROM cdb_digits a, cdb_digits b, cdb_digits c, cdb_digits e, cdb_digits f');
    $forumBase = $forums[0];
    $userBase = $users[0];
    $base = '2036-01-01 00:00:00';

    $pdo->exec(
        "INSERT INTO cms_community_topics (forum_id, user_id, title, slug, status, is_pinned, first_post_id, reply_count, last_post_id, last_post_at, last_poster_id, created_at, updated_at)
         SELECT {$forumBase} + (n MOD 20), {$userBase} + (n MOD 40), CONCAT('Thema ', n), CONCAT('thema-', n),
                CASE WHEN (n DIV 20) MOD 20 = 3 THEN 'hidden' WHEN (n DIV 20) MOD 50 = 7 THEN 'pending' ELSE 'published' END,
                CASE WHEN (n DIV 20) MOD 200 = 5 THEN 1 ELSE 0 END, n + 1, CASE WHEN n MOD 7 = 2 AND n MOD 20 <> 3 THEN 0 ELSE 4 END, n + 1 + 80000,
                TIMESTAMP('{$base}') + INTERVAL (n DIV 100) MINUTE, {$userBase} + (n MOD 40),
                TIMESTAMP('{$base}') + INTERVAL (n DIV 2) SECOND, TIMESTAMP('{$base}') + INTERVAL (n DIV 2) SECOND
         FROM cdb_numbers WHERE n < 20000 ORDER BY n"
    );
    $pdo->exec(
        "INSERT INTO cms_community_posts (topic_id, forum_id, user_id, is_first_post, status, body, created_at, updated_at)
         SELECT (n MOD 20000) + 1, {$forumBase} + ((n MOD 20000) MOD 20), {$userBase} + (n MOD 40), n < 20000,
                CASE WHEN n >= 20000 AND n MOD 33 = 4 THEN 'hidden' WHEN n >= 20000 AND n MOD 71 = 9 THEN 'deleted' ELSE 'published' END,
                CONCAT('Beitrag ', n), TIMESTAMP('{$base}') + INTERVAL (n DIV 5) SECOND, TIMESTAMP('{$base}') + INTERVAL (n DIV 5) SECOND
         FROM cdb_numbers WHERE n < 100000 ORDER BY n"
    );
    $bigTopic = 1;
    $pdo->exec(
        "INSERT INTO cms_community_posts (topic_id, forum_id, user_id, is_first_post, status, body, created_at, updated_at)
         SELECT {$bigTopic}, {$forumBase}, {$userBase} + (n MOD 40), 0, CASE WHEN n MOD 97 = 5 THEN 'hidden' ELSE 'published' END,
                CONCAT('Langer Strang ', n), TIMESTAMP('{$base}') + INTERVAL (20000 + n) SECOND, TIMESTAMP('{$base}') + INTERVAL (20000 + n) SECOND
         FROM cdb_numbers WHERE n < 5000 ORDER BY n"
    );
    // Ein ruhiges Forum: 60 Themen, die alle älter sind als jedes Thema der anderen Foren
    $quiet = $db->forum();
    $pdo->exec(
        "INSERT INTO cms_community_topics (forum_id, user_id, title, slug, status, first_post_id, reply_count, last_post_id, last_post_at, last_poster_id, created_at, updated_at)
         SELECT {$quiet}, {$userBase}, CONCAT('Altes Thema ', n), CONCAT('altes-thema-', n), 'published', 1, 0, 1,
                TIMESTAMP('2030-01-01 00:00:00') + INTERVAL n MINUTE, {$userBase}, '2030-01-01 00:00:00', '2030-01-01 00:00:00'
         FROM cdb_numbers WHERE n < 60 ORDER BY n"
    );
    // Großes älteres Forum (z. B. ein übernommenes Altforum): 4000 Themen, 16,6 % der Tabelle, letzte Aktivität vor der aller
    // übrigen Foren außer dem ruhigen. Weicht der Optimierer hier auf idx_community_topics_activity (status, last_post_at) aus,
    // liest er erst die 20.000 jüngeren Themen der anderen Foren (HANDOFF §2 Nr. 9).
    $old = $db->forum();
    $pdo->exec(
        "INSERT INTO cms_community_topics (forum_id, user_id, title, slug, status, first_post_id, reply_count, last_post_id, last_post_at, last_poster_id, created_at, updated_at)
         SELECT {$old}, {$userBase} + (n MOD 40), CONCAT('Altforum ', n), CONCAT('altforum-', n),
                CASE WHEN n MOD 20 = 3 THEN 'hidden' ELSE 'published' END, 1, 4, 1,
                TIMESTAMP('2035-09-01 00:00:00') + INTERVAL n MINUTE, {$userBase} + (n MOD 40),
                TIMESTAMP('2035-09-01 00:00:00') + INTERVAL n MINUTE, TIMESTAMP('2035-09-01 00:00:00') + INTERVAL n MINUTE
         FROM cdb_numbers WHERE n < 4000 ORDER BY n"
    );
    // Q&A-Forum (n MOD 20 = 3): jedes Thema hat Antworten; gelöst bis auf die IDs mit id MOD 61 = 5 (etwa 15 von 1000).
    $qa = $forums[3];
    $pdo->exec("UPDATE cms_community_topics SET solution_post_id = first_post_id + 1, solved_at = last_post_at, solved_by = user_id WHERE forum_id = {$qa} AND id MOD 61 <> 5");
    // Pins (5 je Forum): je Forum mindestens einer ohne Frist, einer abgelaufen (vor 'now') und einer mit Frist in der Zukunft.
    // Ohne Frist liefe die Abfrage nach abgelaufenen Anheftungen in listForum() immer leer, und ihr Plan bliebe ungeprüft.
    $pdo->exec("UPDATE cms_community_topics SET pinned_until = CASE id MOD 3 WHEN 0 THEN NULL WHEN 1 THEN TIMESTAMP('{$base}') + INTERVAL 10000 SECOND ELSE TIMESTAMP('{$base}') + INTERVAL 40000 SECOND END WHERE is_pinned = 1");
    $pdo->exec(
        "INSERT INTO cms_community_read_states (user_id, topic_id, last_read_post_id, updated_at)
         SELECT {$userBase}, n + 1, n + 1 + 40000, '{$base}' FROM cdb_numbers WHERE n < 20000 AND n MOD 2 = 0"
    );
    $pdo->exec('DROP TABLE cdb_numbers');
    $pdo->exec('DROP TABLE cdb_digits');
    $pdo->query('ANALYZE TABLE cms_community_topics, cms_community_posts, cms_community_read_states')->fetchAll();

    return ['forums' => $forums, 'quiet' => $quiet, 'old' => $old, 'qa' => $qa, 'users' => $users, 'bigTopic' => $bigTopic, 'now' => gmdate('Y-m-d H:i:s', strtotime($base . ' UTC') + 20000), 'since' => gmdate('Y-m-d H:i:s', strtotime($base . ' UTC') + 20000 - 120)];
}

/** Handler-Zähler der Sitzung (`Handler_read_*`) als Name => Wert. */
function cdbHandlers(\PDO $pdo): array
{
    $counters = [];
    foreach ($pdo->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAll(\PDO::FETCH_NUM) as [$name, $value]) {
        $counters[(string) $name] = (int) $value;
    }

    return $counters;
}

/** Bindet Parameter wie `Database::bindParams()` (Ganzzahlen als INT, null als NULL, sonst Text). */
function cdbBind(\PDOStatement $stmt, array $params): void
{
    foreach (array_values($params) as $i => $value) {
        if (is_int($value)) {
            $stmt->bindValue($i + 1, $value, \PDO::PARAM_INT);
        } elseif ($value === null) {
            $stmt->bindValue($i + 1, null, \PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($i + 1, (string) $value, \PDO::PARAM_STR);
        }
    }
}

/**
 * Ruft eine Repository-Methode auf und liefert: den Rückgabewert, die Handler-Zähler (Differenz), die gelesenen Zeilen insgesamt,
 * die Zahl der Tabellenscan-Schritte (`Handler_read_rnd_next`) und für jede abgesetzte SELECT-Anweisung den EXPLAIN-Plan (mit den
 * echten gebundenen Parametern).
 *
 * @return array{result: mixed, reads: int, scan: int, plans: list<array{sql: string, rows: list<array<string, mixed>>}>}
 */
function cdbMeasure(MariaDbTestDatabase $db, \Closure $call): array
{
    $pdo = $db->getPdo();
    $before = cdbHandlers($pdo);
    $db->captured = [];
    $db->captureParams = true;
    $result = $call();
    $db->captureParams = false;
    $after = cdbHandlers($pdo);
    $captured = $db->captured;
    $db->captured = [];
    $diff = [];
    foreach ($after as $name => $value) {
        $diff[$name] = $value - ($before[$name] ?? 0);
    }
    $plans = [];
    foreach ($captured as $statement) {
        if (preg_match('/^\s*SELECT\b/i', $statement['sql']) !== 1) {
            continue;
        }
        $stmt = $pdo->prepare('EXPLAIN ' . $statement['sql']);
        cdbBind($stmt, $statement['params']);
        $stmt->execute();
        $plans[] = ['sql' => preg_replace('/\s+/', ' ', $statement['sql']), 'rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    return ['result' => $result, 'reads' => array_sum($diff) - ($diff['Handler_read_rnd_next'] ?? 0), 'scan' => $diff['Handler_read_rnd_next'] ?? 0, 'plans' => $plans];
}

/**
 * Prüft die Pläne einer Messung gegen Regeln: kein Tabellenscan (`type` ALL), ein Index (`key`), erwarteter Index, kein Filesort
 * (außer erlaubt), keine temporäre Tabelle, wenige Handler-Lesezugriffe und kein Scan-Schritt. Der zweite Schritt einer
 * ID-Abfrage (`WHERE id IN (…)`) muss den Primärschlüssel nutzen; mit `coveringIds` lesen die ID-Abfragen (`SELECT id, …`) nur
 * den Index (`covering` verlangt das für jede Anweisung).
 *
 * @param array{keys?: list<string>, filesort?: bool, maxReads: int, maxScan?: int, covering?: bool, coveringIds?: bool, statements?: int} $rule
 * @return list<string> Verstöße
 */
function cdbPlanProblems(array $measure, array $rule): array
{
    $problems = [];
    if (isset($rule['statements']) && count($measure['plans']) !== $rule['statements']) {
        $problems[] = 'erwartet ' . $rule['statements'] . ' SELECT-Anweisungen, abgesetzt ' . count($measure['plans']);
    }
    foreach ($measure['plans'] as $plan) {
        // Abfrage nach abgelaufenen Anheftungen (höchstens MAX_PINNED Zeilen, meist keine): darf den Index der Fristen wählen und
        // eine Handvoll Zeilen sortieren, nie die Tabelle lesen.
        $expiredPins = str_contains($plan['sql'], 'pinned_until IS NOT NULL');
        $byIds = str_contains($plan['sql'], ' WHERE id IN (');
        $idQuery = str_starts_with($plan['sql'], 'SELECT id, ');
        foreach ($plan['rows'] as $position => $row) {
            if ($row['table'] === null || str_starts_with((string) $row['table'], '<')) {
                continue;   // abgeleitete Tabelle
            }
            $where = $row['table'] . ' [' . ($row['type'] ?? '?') . ', key ' . ($row['key'] ?? 'NULL') . ', rows ' . ($row['rows'] ?? '?') . ', ' . ($row['Extra'] ?? '') . ']';
            $keys = $byIds ? ['PRIMARY'] : ($rule['keys'] ?? null);
            if ($expiredPins && $keys !== null) {
                $keys[] = 'idx_community_topics_pinned';
            }
            if (($row['type'] ?? '') === 'ALL' || ($row['key'] ?? null) === null) {
                $problems[] = 'Tabellenscan: ' . $where . ' in ' . substr($plan['sql'], 0, 90);
            } elseif ($keys !== null && $position === 0 && !in_array($row['key'], $keys, true)) {
                $problems[] = 'anderer Index als ' . implode('|', $keys) . ': ' . $where . ' in ' . substr($plan['sql'], 0, 90);
            }
            $extra = (string) ($row['Extra'] ?? '');
            $sortAllowed = !empty($rule['filesort']) || ($expiredPins && (int) ($row['rows'] ?? 0) <= 200);
            if (!$sortAllowed && str_contains($extra, 'filesort')) {
                $problems[] = 'Filesort: ' . $where . ' in ' . substr($plan['sql'], 0, 90);
            }
            if (str_contains($extra, 'temporary')) {
                $problems[] = 'temporäre Tabelle: ' . $where . ' in ' . substr($plan['sql'], 0, 90);
            }
            if ((!empty($rule['covering']) || (!empty($rule['coveringIds']) && $idQuery)) && $position === 0 && preg_match('/Using index(?! condition)/', $extra) !== 1) {
                $problems[] = 'nicht überdeckend (Using index fehlt, „Using index condition“ zählt nicht): ' . $where;
            }
        }
    }
    if ($measure['reads'] > $rule['maxReads']) {
        $problems[] = 'Handler-Lesezugriffe ' . $measure['reads'] . ' > ' . $rule['maxReads'];
    }
    if ($measure['scan'] > ($rule['maxScan'] ?? 10)) {
        $problems[] = 'Tabellenscan-Schritte (Handler_read_rnd_next) ' . $measure['scan'] . ' > ' . ($rule['maxScan'] ?? 10);
    }

    return $problems;
}

cdbSection('explain', 'EXPLAIN-Gate: Listen, Position, Dubletten- und Zählabfragen nutzen ihre Indizes (24.000 Themen, 100.000 Beiträge)', static function (): void {
    $db = MariaDbTestDatabase::create();
    $started = microtime(true);
    $data = cdbBulkLoad($db);
    T::info('Daten erzeugt in ' . round(microtime(true) - $started, 1) . ' s');
    T::same([24060, 105000], [(int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_topics'), (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_posts')], 'Datenmenge: 20.000 Themen in 20 Foren, dazu 60 alte in einem ruhigen Forum und 4000 in einem großen älteren Forum, 105.000 Beiträge (100.000 verteilt, 5000 im langen Thema)');
    $topics = new TopicRepository($db);
    $posts = new PostRepository($db);
    $reads = new ReadStateRepository($db);
    $forum = $data['forums'][5];
    $user = $data['users'][11];
    $now = $data['now'];
    $since = $data['since'];
    $big = $data['bigTopic'];
    $forumList = ['idx_community_topics_forum_list'];

    // Cursor tief in der Liste (Position 700 von etwa 900 veröffentlichten, nicht angepinnten Themen)
    $deepRow = $db->execute("SELECT last_post_at, id FROM cms_community_topics WHERE forum_id = ? AND status = 'published' AND is_pinned = 0 ORDER BY last_post_at DESC, id DESC LIMIT 1 OFFSET 700", [$forum])->fetch(\PDO::FETCH_ASSOC);
    $midRow = $db->execute("SELECT last_post_at, id FROM cms_community_topics WHERE forum_id = ? AND status = 'published' AND is_pinned = 0 ORDER BY last_post_at DESC, id DESC LIMIT 1 OFFSET 40", [$forum])->fetch(\PDO::FETCH_ASSOC);
    $deep = new Cursor((string) $deepRow['last_post_at'], (int) $deepRow['id']);
    $mid = new Cursor((string) $midRow['last_post_at'], (int) $midRow['id']);
    $qaMidRow = $db->execute("SELECT last_post_at, id FROM cms_community_topics WHERE forum_id = ? AND status = 'published' AND is_pinned = 0 ORDER BY last_post_at DESC, id DESC LIMIT 1 OFFSET 40", [$data['qa']])->fetch(\PDO::FETCH_ASSOC);
    $qaMid = new Cursor((string) $qaMidRow['last_post_at'], (int) $qaMidRow['id']);
    $deepPost = (int) cdbScalar($db, "SELECT id FROM cms_community_posts WHERE topic_id = ? AND status = 'published' ORDER BY id DESC LIMIT 1 OFFSET 400", [$big]);
    $firstPost = (int) cdbScalar($db, "SELECT MIN(id) FROM cms_community_posts WHERE topic_id = ?", [$big]);
    $secondPost = (int) cdbScalar($db, "SELECT id FROM cms_community_posts WHERE topic_id = ? ORDER BY id LIMIT 1 OFFSET 1", [$big]);

    // Listen ohne Repository-Methode (PR #2b/#4), Abfrageform nach HANDOFF §2 Nr. 9 und 10: globale Liste „Unbeantwortet“
    // (Keyset created_at, id; mit und ohne Rechtefilter forum_id IN …) und Filter „ungelöst“ je Forum (solved_at IS NULL).
    // „Unbeantwortet“ in zwei Schritten wie „ungelöst“, mit und ohne Rechtefilter: erst die IDs (Keyset), dann die Zeilen über
    // `id IN (…)`. Mit SELECT * wich MariaDB 10.11 mit Rechtefilter auf Folgeseiten auf ref bzw. index_merge mit Filesort aus (755
    // Lesezugriffe an Position 1500), ohne Rechtefilter bei größeren Datenmengen und einem Cursor im ersten Viertel der Liste auf ref
    // (Abschnitt explain-unanswered; in diesen Daten tritt das nicht auf).
    $unansweredIds = 'SELECT id, created_at FROM cms_community_topics WHERE status = ? AND reply_count = 0';
    $byCreated = ' ORDER BY created_at DESC, id DESC LIMIT ?';
    $createdKeyset = ' AND (created_at < ? OR (created_at = ? AND id < ?))';
    $readable = array_slice($data['forums'], 0, 15);
    $readableIn = ' AND forum_id IN (' . implode(', ', array_fill(0, count($readable), '?')) . ')';
    $unansweredRow = $db->execute($unansweredIds . ' ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET 1500', ['published'])->fetch(\PDO::FETCH_ASSOC);
    $readableRow = $db->execute($unansweredIds . $readableIn . ' ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET 1500', ['published', ...$readable])->fetch(\PDO::FETCH_ASSOC);
    // „ungelöst“: erst die IDs über den überdeckenden Index (nur Indexzugriff), dann die Zeilen über den Primärschlüssel. Mit
    // SELECT * wich MariaDB 10.11 ohne Spaltenstatistik (nur ANALYZE TABLE) auf idx_community_topics_forum_list aus, die dieselbe
    // Sortierung liefert, und las das ganze Forum (926 Lesezugriffe für 14 Treffer).
    $unsolved = 'SELECT id, last_post_at FROM cms_community_topics WHERE forum_id = ? AND status = ? AND is_pinned = 0 AND solved_at IS NULL';
    $byActivity = ' ORDER BY last_post_at DESC, id DESC LIMIT ?';
    $activityKeyset = ' AND (last_post_at < ? OR (last_post_at = ? AND id < ?))';
    $unsolvedRow = $db->execute($unsolved . ' ORDER BY last_post_at DESC, id DESC LIMIT 1 OFFSET 5', [$data['qa'], 'published'])->fetch(\PDO::FETCH_ASSOC);
    $unansweredAll = ['idx_community_topics_unanswered_all'];
    $solvedIndex = ['idx_community_topics_solved'];

    $cases = [
        'Themenliste erste Seite (Pins und Strom)' => [static fn () => $topics->listForum($forum, $now, null, 20), ['keys' => $forumList, 'coveringIds' => true, 'maxReads' => 400, 'statements' => 4]],
        'Themenliste Folgeseite oben' => [static fn () => $topics->listForum($forum, $now, $mid, 20), ['keys' => $forumList, 'coveringIds' => true, 'maxReads' => 400, 'statements' => 3]],
        'Themenliste Folgeseite tief (Position 700)' => [static fn () => $topics->listForum($forum, $now, $deep, 20), ['keys' => $forumList, 'coveringIds' => true, 'maxReads' => 400, 'statements' => 3]],
        'Angepinnte Themen' => [static fn () => $topics->pinned($forum, $now), ['keys' => $forumList, 'maxReads' => 100, 'statements' => 1]],
        'Beitragsliste, erste Seite (published)' => [static fn () => $posts->listPublished($big, 0, 15), ['keys' => ['idx_community_posts_topic'], 'maxReads' => 100, 'statements' => 1]],
        'Beitragsliste, Seite am Ende des Themas (nach Beitrag 400 von 5000)' => [static fn () => $posts->listPublished($big, $deepPost, 15), ['keys' => ['idx_community_posts_topic', 'PRIMARY'], 'maxReads' => 100, 'statements' => 1]],
        'Beitragsliste, Seite am Anfang des Themas (nach dem zweiten Beitrag)' => [static fn () => $posts->listPublished($big, $secondPost, 15), ['keys' => ['idx_community_posts_topic'], 'maxReads' => 100, 'statements' => 1]],
        'Platzhalter im ID-Bereich einer Seite (hidden, deleted)' => [static fn () => $posts->placeholdersInRange($big, $deepPost, $deepPost + 60), ['keys' => ['idx_community_posts_topic'], 'filesort' => true, 'maxReads' => 200, 'statements' => 1]],
        'Position eines Beitrags (COUNT … id < ?)' => [static fn () => $posts->positionOf($big, $deepPost), ['keys' => ['idx_community_posts_topic'], 'covering' => true, 'maxReads' => 5200, 'statements' => 1]],
        'Dubletten Themen (findRecentDuplicate)' => [static fn () => $topics->findRecentDuplicate($user, $forum, 'Thema 0', $since, 'x'), ['keys' => ['idx_community_topics_user'], 'filesort' => true, 'maxReads' => 400, 'statements' => 1]],
        'Dubletten Beiträge (findRecentDuplicate)' => [static fn () => $posts->findRecentDuplicate($user, 5, 'x', $since), ['maxReads' => 400, 'filesort' => true, 'statements' => 1]],
        'Themen je Mitglied seit (countSince)' => [static fn () => $topics->countSince($user, $since), ['keys' => ['idx_community_topics_user'], 'maxReads' => 400, 'statements' => 1]],
        'Beiträge je Mitglied seit (countSince)' => [static fn () => $posts->countSince($user, $since), ['maxReads' => 600, 'statements' => 1]],
        'Letztes Thema des Mitglieds (lastOfUser)' => [static fn () => $topics->lastOfUser($user, $since), ['keys' => ['idx_community_topics_user'], 'filesort' => true, 'maxReads' => 100, 'statements' => 1]],
        'Letzter Beitrag des Mitglieds im Forum (lastOfUser)' => [static fn () => $posts->lastOfUser($user, $since, $forum, null), ['filesort' => true, 'maxReads' => 600, 'statements' => 1]],
        'Letzter Beitrag im Thema (lastPublishedInTopic)' => [static fn () => $posts->lastPublishedInTopic($big), ['keys' => ['idx_community_posts_topic'], 'maxReads' => 100, 'statements' => 1]],
        'Letzter Beitrag im Forum (lastPublishedInForum)' => [static fn () => $posts->lastPublishedInForum($forum), ['maxReads' => 400, 'statements' => 1]],
        'Erster ungelesener Beitrag (firstUnread)' => [static fn () => $reads->firstUnread($big, $deepPost), ['keys' => ['idx_community_posts_topic'], 'maxReads' => 100, 'statements' => 1]],
        'Ungelesene Themen (unreadCount, Obergrenze 100)' => [static fn () => $reads->unreadCount($data['users'][0], [$forum], '2036-01-01 00:00:00', 100), ['maxReads' => 3000, 'maxScan' => 150, 'statements' => 1]],
        // Dasselbe Muster wie B5: ohne `is_pinned IN (0, 1)` liest der Optimierer idx_community_topics_activity (status, last_post_at) und filtert das Forum
        'Ungelesene Themen im ruhigen Forum (unreadCount, 60 alte Themen, 24.000 jüngere anderer Foren)' => [static fn () => $reads->unreadCount($data['users'][0], [$data['quiet']], '2035-01-01 00:00:00', 100), ['maxReads' => 100, 'maxScan' => 150, 'statements' => 1]],
        // Vorher: idx_community_topics_activity mit Filesort über alle veröffentlichten Themen bzw. idx_community_topics_unanswered
        // mit Filesort über alle unbeantworteten Themen der lesbaren Foren.
        'Unbeantwortet global, erste Seite (IDs)' => [static fn () => $db->execute($unansweredIds . $byCreated, ['published', 21])->fetchAll(), ['keys' => $unansweredAll, 'covering' => true, 'maxReads' => 100, 'statements' => 1]],
        'Unbeantwortet global, Folgeseite (Keyset, Position 1500, IDs)' => [static fn () => $db->execute($unansweredIds . $createdKeyset . $byCreated, ['published', $unansweredRow['created_at'], $unansweredRow['created_at'], (int) $unansweredRow['id'], 21])->fetchAll(), ['keys' => $unansweredAll, 'covering' => true, 'maxReads' => 100, 'statements' => 1]],
        'Unbeantwortet in lesbaren Foren (forum_id IN, 15 von 20 Foren), erste Seite (IDs)' => [static fn () => $db->execute($unansweredIds . $readableIn . $byCreated, ['published', ...$readable, 21])->fetchAll(), ['keys' => $unansweredAll, 'maxReads' => 100, 'statements' => 1]],
        'Unbeantwortet in lesbaren Foren, Folgeseite (Keyset, Position 1500, IDs)' => [static fn () => $db->execute($unansweredIds . $readableIn . $createdKeyset . $byCreated, ['published', ...$readable, $readableRow['created_at'], $readableRow['created_at'], (int) $readableRow['id'], 21])->fetchAll(), ['keys' => $unansweredAll, 'maxReads' => 100, 'statements' => 1]],
        // Vorher: idx_community_topics_forum_list liest das ganze Forum (etwa 900 Einträge plus Zeilenzugriffe) für 15 Treffer.
        'Ungelöst im Q&A-Forum (etwa 15 von 900), erste Seite (IDs)' => [static fn () => $db->execute($unsolved . $byActivity, [$data['qa'], 'published', 21])->fetchAll(), ['keys' => $solvedIndex, 'covering' => true, 'maxReads' => 50, 'statements' => 1]],
        'Ungelöst im Q&A-Forum, Folgeseite (Keyset, IDs)' => [static fn () => $db->execute($unsolved . $activityKeyset . $byActivity, [$data['qa'], 'published', $unsolvedRow['last_post_at'], $unsolvedRow['last_post_at'], (int) $unsolvedRow['id'], 21])->fetchAll(), ['keys' => $solvedIndex, 'covering' => true, 'maxReads' => 50, 'statements' => 1]],
        // Diskussionsforum ohne gelöste Themen: jeder Eintrag ist ein Treffer, beide Indizes lesen nur eine Seite.
        'Ungelöst im Diskussionsforum (kein Thema gelöst, IDs)' => [static fn () => $db->execute($unsolved . $byActivity, [$forum, 'published', 21])->fetchAll(), ['keys' => [...$forumList, ...$solvedIndex], 'maxReads' => 100, 'statements' => 1]],
        // Die Forenliste (auch der zweite Strom von „ungelöste zuerst“) bleibt im Q&A-Forum auf idx_community_topics_forum_list; die
    // ID-Abfrage des Stroms liest nur den Index, die Zeilen kommen über den Primärschlüssel.
        'Themenliste im Q&A-Forum (Pins und Strom)' => [static fn () => $topics->listForum($data['qa'], $now, null, 20), ['keys' => $forumList, 'coveringIds' => true, 'maxReads' => 400, 'statements' => 4]],
        'Themenliste im Q&A-Forum, Folgeseite (Keyset)' => [static fn () => $topics->listForum($data['qa'], $now, $qaMid, 20), ['keys' => $forumList, 'coveringIds' => true, 'maxReads' => 400, 'statements' => 3]],
        'Letztes Thema im Q&A-Forum (lastPublished)' => [static fn () => $topics->lastPublished($data['qa']), ['keys' => $forumList, 'maxReads' => 100, 'statements' => 2]],
        // Großes älteres Forum: Strom und lastPublished() bleiben auf idx_community_topics_forum_list. Mit idx_community_topics_solved in
        // der Form (forum_id, status, is_pinned, solved_at, last_post_at) wich MariaDB 10.11 in einem solchen Forum je nach Seitenlayout
        // auf idx_community_topics_activity aus (40.060 Themen: 5955 statt 67 Lesezugriffe); in diesen Daten blieb der Plan auch mit
        // jener Form. Die Definition prüft deshalb zusätzlich TESTS/schema-v27 (HANDOFF §2 Nr. 9).
        'Themenliste im großen älteren Forum, erste Seite (Pins und Strom)' => [static fn () => $topics->listForum($data['old'], $now, null, 20), ['keys' => $forumList, 'coveringIds' => true, 'maxReads' => 400, 'statements' => 4]],
        'Themenliste im großen älteren Forum, erste Seite mit 100 Themen' => [static fn () => $topics->listForum($data['old'], $now, null, 100), ['keys' => $forumList, 'coveringIds' => true, 'maxReads' => 600, 'statements' => 4]],
        'Letztes Thema im großen älteren Forum (lastPublished)' => [static fn () => $topics->lastPublished($data['old']), ['keys' => $forumList, 'maxReads' => 100, 'statements' => 2]],
    ];
    $measured = [];
    foreach ($cases as $label => [$call, $rule]) {
        $measure = cdbMeasure($db, $call);
        $measured[$label] = $measure;
        T::info($label . ': ' . $measure['reads'] . ' Lesezugriffe, ' . $measure['scan'] . ' Scan-Schritte; ' . implode(' | ', array_map(static fn (array $plan): string => implode(', ', array_map(static fn (array $row): string => ($row['table'] ?? '-') . ':' . ($row['type'] ?? '-') . ':' . ($row['key'] ?? 'NULL') . ':' . ($row['rows'] ?? '?') . ':' . ($row['Extra'] ?? ''), $plan['rows'])), $measure['plans'])));
        T::same([], cdbPlanProblems($measure, $rule), $label . ': Index, ' . (!empty($rule['filesort']) ? 'Filesort erlaubt (wenige Zeilen)' : 'kein Filesort') . ', kein Tabellenscan, wenige Lesezugriffe');
    }

    // Letztes Thema eines Forums (nach dem Löschen): auch für ein ruhiges Forum neben einem lebhaften Rest der Tabelle
    foreach (['lebhaft' => $forum, 'ruhig (60 alte Themen, 24.000 jüngere Themen anderer Foren)' => $data['quiet']] as $kind => $forumId) {
        $measure = cdbMeasure($db, static fn () => $topics->lastPublished($forumId));
        T::info('lastPublished, Forum ' . $kind . ': ' . $measure['reads'] . ' Lesezugriffe; ' . implode(' | ', array_map(static fn (array $plan): string => implode(', ', array_map(static fn (array $row): string => ($row['table'] ?? '-') . ':' . ($row['type'] ?? '-') . ':' . ($row['key'] ?? 'NULL') . ':' . ($row['rows'] ?? '?') . ':' . ($row['Extra'] ?? ''), $plan['rows'])), $measure['plans'])));
        T::check($measure['result'] !== null, 'lastPublished, Forum ' . $kind . ': liefert das jüngste veröffentlichte Thema');
        $problems = cdbPlanProblems($measure, ['keys' => $forumList, 'maxReads' => 100, 'statements' => 2]);
        T::same([], $problems, 'B5: lastPublished() liest für ein Forum (' . $kind . ') nur wenige Index-Einträge: zwei Abfragen mit Gleichheit auf forum_id, status und is_pinned über idx_community_topics_forum_list (' . $measure['reads'] . ' Handler-Lesezugriffe, vorher 18601 im ruhigen Forum)');
    }
    $quietLast = $topics->lastPublished($data['quiet']);
    T::same('Altes Thema 59', (string) $db->get_var('SELECT title FROM cms_community_topics WHERE id = ?', [(int) ($quietLast['id'] ?? 0)]), 'lastPublished im ruhigen Forum: das jüngste der 60 alten Themen');

    // Ergebnisse inhaltlich plausibel (die Abfragen liefern, was sie sollen)
    $first = $measured['Themenliste erste Seite (Pins und Strom)']['result'];
    T::same([true, true], [count($first->items) > 20, $first->next !== null], 'erste Seite: angepinnte Themen vor 20 Stromthemen, weitere Seite vorhanden');
    $deepPage = $measured['Themenliste Folgeseite tief (Position 700)']['result'];
    T::same([20, true], [count($deepPage->items), $deepPage->next !== null], 'tiefe Seite: 20 Themen, weitere Seite vorhanden');
    // Zweiter Schritt der ID-Abfragen: Zeilen über den Primärschlüssel.
    $idPages = [
        'Unbeantwortet global, erste Seite (IDs)' => ['global, erste Seite', null, null],
        'Unbeantwortet global, Folgeseite (Keyset, Position 1500, IDs)' => ['global, Folgeseite', null, $unansweredRow],
        'Unbeantwortet in lesbaren Foren (forum_id IN, 15 von 20 Foren), erste Seite (IDs)' => ['in lesbaren Foren, erste Seite', $readable, null],
        'Unbeantwortet in lesbaren Foren, Folgeseite (Keyset, Position 1500, IDs)' => ['in lesbaren Foren, Folgeseite', $readable, $readableRow],
    ];
    foreach ($idPages as $label => [$kind, $forumIds, $cursorRow]) {
        $ids = array_map(static fn (object $row): int => (int) $row->id, $measured[$label]['result']);
        $unansweredPage = $ids === [] ? [] : $db->execute('SELECT * FROM cms_community_topics WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids)->fetchAll();
        T::same([21, []], [count($unansweredPage), array_values(array_filter($unansweredPage, static fn (object $row): bool => (int) $row->reply_count !== 0 || $row->status !== 'published' || ($forumIds !== null && !in_array((int) $row->forum_id, $forumIds, true))))], 'Unbeantwortet ' . $kind . ': 21 veröffentlichte Themen ohne Antwort' . ($forumIds !== null ? ', nur aus lesbaren Foren' : ''));
        if ($cursorRow !== null) {
            $belowCursor = array_filter($measured[$label]['result'], static fn (object $row): bool => [(string) $row->created_at, (int) $row->id] < [(string) $cursorRow['created_at'], (int) $cursorRow['id']]);
            T::same(21, count($belowCursor), 'Unbeantwortet ' . $kind . ': alle Zeilen liegen hinter dem Cursor');
        }
    }
    // Pins: je Forum ohne Frist, abgelaufen und mit Frist in der Zukunft; die Abfrage nach abgelaufenen Anheftungen der Folgeseite
    // findet Zeilen (der geprüfte Plan läuft nicht leer), die erste Seite zeigt oben nur die wirksamen Pins.
    $pinKinds = array_map('intval', $db->execute("SELECT SUM(pinned_until IS NULL), SUM(pinned_until <= ?), SUM(pinned_until > ?) FROM cms_community_topics WHERE forum_id = ? AND status = 'published' AND is_pinned = 1", [$now, $now, $forum])->fetch(\PDO::FETCH_NUM));
    T::check(min($pinKinds) >= 1, 'Testdaten: Pins ohne Frist, abgelaufen und mit Frist in der Zukunft', implode('/', $pinKinds));
    T::check((int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_topics WHERE forum_id = ? AND status = 'published' AND is_pinned = 1 AND pinned_until <= ? AND (last_post_at < ? OR (last_post_at = ? AND id < ?))", [$forum, $now, $mid->at, $mid->at, $mid->id]) >= 1, 'Folgeseite oben: abgelaufene Anheftungen hinter dem Cursor vorhanden');
    T::same($pinKinds[0] + $pinKinds[2], count(array_filter($first->items, static fn (array $row): bool => (int) $row['is_pinned'] === 1)), 'erste Seite: nur Pins ohne Frist oder mit künftiger Frist gelten als angeheftet');
    $unsolvedPage = $measured['Ungelöst im Q&A-Forum (etwa 15 von 900), erste Seite (IDs)']['result'];
    $unsolvedCount = (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_topics WHERE forum_id = ? AND status = 'published' AND is_pinned = 0 AND solved_at IS NULL", [$data['qa']]);
    T::check($unsolvedCount >= 8 && $unsolvedCount <= 21 && count($unsolvedPage) === $unsolvedCount, 'Ungelöst im Q&A-Forum: alle ' . $unsolvedCount . ' ungelösten Themen auf der ersten Seite (Daten wie im Befund: wenige ungelöste in einem großen Forum)');

    // Keyset in diesem Forum (rund 900 Themen): die tiefe Seite kostet nicht mehr als die erste (OFFSET 700 würde über 700 Einträge
    // lesen). Ob das auch in großen Foren gilt, hängt vom Plan ab: dort prüft der Abschnitt explain-forum-large Cursor bis Position 6000.
    $top = $measured['Themenliste erste Seite (Pins und Strom)']['reads'];
    $bottom = $measured['Themenliste Folgeseite tief (Position 700)']['reads'];
    T::check($bottom <= max(2 * $top, 120), 'Keyset (Forum mit rund 900 Themen): die tiefe Seite liest nicht mehr als doppelt so viel wie die erste (' . $bottom . ' gegen ' . $top . ' Lesezugriffe)');
    $listTop = $measured['Beitragsliste, erste Seite (published)']['reads'];
    $listDeep = $measured['Beitragsliste, Seite am Ende des Themas (nach Beitrag 400 von 5000)']['reads'];
    T::check($listDeep <= max(2 * $listTop, 120), 'Beitragsliste nach ID: die tiefe Seite liest nicht mehr als doppelt so viel wie die erste (' . $listDeep . ' gegen ' . $listTop . ')');
    T::same(true, $measured['Position eines Beitrags (COUNT … id < ?)']['result'] > 300, 'Position tief im langen Thema (> 300)');
    T::same($firstPost > 0, true, 'Daten des langen Themas vorhanden');
    $db->dispose();
});

cdbSection('explain-unanswered', 'EXPLAIN-Gate: globale Liste „Unbeantwortet“ bei 60.000 Themen, Cursor im ersten Viertel (die ID-Form bleibt range)', static function (): void {
    // Gegenfall zu HANDOFF §2 Nr. 10: Bei dieser Datenmenge (7544 Treffer) wählte MariaDB 10.11 für `SELECT *` mit einem Cursor im
    // ersten Viertel der Liste `ref` statt `range` auf idx_community_topics_unanswered_all; der Keyset wirkte nur noch als Filter, und
    // die Lesezugriffe wuchsen mit der Position (Position 1500: 1522). Die Daten des Abschnitts `explain` (rund 2600 Treffer) zeigen das
    // nicht. Geprüft wird die vorgeschriebene ID-Form; `SELECT *` steht nur als Hinweis daneben (sein Plan hängt von Version und
    // Statistik ab und ist nicht die Abfrageform).
    $db = MariaDbTestDatabase::create();
    $pdo = $db->getPdo();
    $started = microtime(true);
    $pdo->exec('CREATE TABLE cdb_digits (d TINYINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_digits (d) VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)');
    $pdo->exec('CREATE TABLE cdb_numbers (n INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_numbers (n) SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d FROM cdb_digits a, cdb_digits b, cdb_digits c, cdb_digits e, cdb_digits f');
    // 46 Foren, 12 % ausgeblendet, jedes siebte Thema ohne Antwort, ein Thema alle 1578 Sekunden (drei Jahre).
    $pdo->exec(
        "INSERT INTO cms_community_topics (forum_id, user_id, title, slug, status, reply_count, last_post_at, created_at, updated_at)
         SELECT 1 + (n * 7) MOD 46, 1 + n MOD 300, CONCAT('Thema ', n), CONCAT('thema-', n),
                CASE WHEN n MOD 50 IN (1, 2, 3, 4, 5, 6) THEN 'hidden' ELSE 'published' END,
                CASE WHEN n MOD 7 = 2 THEN 0 ELSE 1 + n MOD 9 END,
                TIMESTAMP('2023-01-01 00:00:00') + INTERVAL n * 1578 SECOND, TIMESTAMP('2023-01-01 00:00:00') + INTERVAL n * 1578 SECOND,
                TIMESTAMP('2023-01-01 00:00:00') + INTERVAL n * 1578 SECOND
         FROM cdb_numbers WHERE n < 60000 ORDER BY n"
    );
    $pdo->exec('DROP TABLE cdb_numbers');
    $pdo->exec('DROP TABLE cdb_digits');
    $pdo->query('ANALYZE TABLE cms_community_topics')->fetchAll();
    T::info('Daten erzeugt in ' . round(microtime(true) - $started, 1) . ' s');
    $ids = 'SELECT id, created_at FROM cms_community_topics WHERE status = ? AND reply_count = 0';
    $tail = ' AND (created_at < ? OR (created_at = ? AND id < ?)) ORDER BY created_at DESC, id DESC LIMIT ?';
    $hits = (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_topics WHERE status = 'published' AND reply_count = 0");
    T::same([60000, 7544], [(int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_topics'), $hits], 'Datenmenge: 60.000 Themen, 7544 veröffentlichte ohne Antwort');

    foreach ([250, 1500] as $position) {
        $row = $db->execute($ids . ' ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET ' . $position, ['published'])->fetch(\PDO::FETCH_ASSOC);
        $params = ['published', $row['created_at'], $row['created_at'], (int) $row['id'], 21];
        $label = 'Unbeantwortet global, Position ' . $position . ' von ' . $hits . ' (IDs)';
        $measure = cdbMeasure($db, static fn () => $db->execute($ids . $tail, $params)->fetchAll());
        $plan = $measure['plans'][0]['rows'][0] ?? [];
        T::info($label . ': ' . $measure['reads'] . ' Lesezugriffe; ' . ($plan['type'] ?? '-') . ':' . ($plan['key'] ?? 'NULL') . ':' . ($plan['Extra'] ?? ''));
        T::same([], cdbPlanProblems($measure, ['keys' => ['idx_community_topics_unanswered_all'], 'covering' => true, 'maxReads' => 100, 'statements' => 1]), $label . ': Index, nur Indexzugriff, kein Filesort, wenige Lesezugriffe');
        T::same('range', $plan['type'] ?? null, $label . ': range über den Keyset (nicht ref mit dem Keyset als Filter)');
        $star = cdbMeasure($db, static fn () => $db->execute(str_replace('SELECT id, created_at', 'SELECT *', $ids) . $tail, $params)->fetchAll());
        $starPlan = $star['plans'][0]['rows'][0] ?? [];
        T::info('zum Vergleich SELECT *, Position ' . $position . ': ' . $star['reads'] . ' Lesezugriffe; ' . ($starPlan['type'] ?? '-') . ':' . ($starPlan['key'] ?? 'NULL') . ':' . ($starPlan['Extra'] ?? ''));

        // Zweiter Schritt: die Zeilen über den Primärschlüssel; dieselben Themen in derselben Reihenfolge wie mit SELECT *.
        $pageIds = array_map(static fn (object $topic): int => (int) $topic->id, $measure['result']);
        $page = $pageIds === [] ? [] : $db->execute('SELECT * FROM cms_community_topics WHERE id IN (' . implode(', ', array_fill(0, count($pageIds), '?')) . ')', $pageIds)->fetchAll();
        T::same([21, []], [count($page), array_values(array_filter($page, static fn (object $topic): bool => (int) $topic->reply_count !== 0 || $topic->status !== 'published' || [(string) $topic->created_at, (int) $topic->id] >= [(string) $row['created_at'], (int) $row['id']]))], $label . ': 21 veröffentlichte Themen ohne Antwort hinter dem Cursor');
        T::same(array_map(static fn (object $topic): int => (int) $topic->id, $star['result']), $pageIds, $label . ': dieselben Themen in derselben Reihenfolge wie mit SELECT *');
    }
    $db->dispose();
});

cdbSection('explain-forum-large', 'EXPLAIN-Gate: Forenliste eines großen Forums (15.000 Themen), Cursor bis Position 6000 (der Strom bleibt range)', static function (): void {
    // Gegenfall zu HANDOFF §2 Nr. 10: In einem großen Forum wählte MariaDB 10.11 für den Strom der Forenliste mit `SELECT *` bei
    // einem Cursor in der oberen Hälfte `ref` statt `range` auf idx_community_topics_forum_list; der Keyset wirkte nur noch als
    // Filter, und die Lesezugriffe wuchsen mit der Position (Position 6000: 6021). Die Foren des Abschnitts `explain` (rund 900
    // Themen) zeigen das nicht. Geprüft wird listForum() selbst: die ID-Abfrage des Stroms (nur Indexzugriff), dann die Zeilen über
    // den Primärschlüssel; `SELECT *` steht nur als Hinweis daneben (sein Plan hängt von Version und Statistik ab).
    $db = MariaDbTestDatabase::create();
    $pdo = $db->getPdo();
    $started = microtime(true);
    $pdo->exec('CREATE TABLE cdb_digits (d TINYINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_digits (d) VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)');
    $pdo->exec('CREATE TABLE cdb_numbers (n INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_numbers (n) SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d FROM cdb_digits a, cdb_digits b, cdb_digits c, cdb_digits e, cdb_digits f');
    // 60.000 Themen: jedes vierte im großen Forum 1 (15.000), die übrigen in 45 Foren; 12 % ausgeblendet, einige angeheftet (ohne
    // Frist, abgelaufen, künftig), ein Thema alle 1578 Sekunden, letzte Aktivität bis 23 Tage nach dem Anlegen.
    $pdo->exec(
        "INSERT INTO cms_community_topics (forum_id, user_id, title, slug, status, is_pinned, pinned_until, reply_count, last_post_at, created_at, updated_at)
         SELECT CASE WHEN n MOD 4 = 0 THEN 1 ELSE 2 + (n * 7) MOD 45 END, 1 + n MOD 300, CONCAT('Thema ', n), CONCAT('thema-', n),
                CASE WHEN n MOD 50 IN (1, 2, 3, 4, 5, 6) THEN 'hidden' ELSE 'published' END,
                CASE WHEN n MOD 997 = 11 THEN 1 ELSE 0 END,
                CASE WHEN n MOD 997 <> 11 OR n MOD 3 = 0 THEN NULL WHEN n MOD 3 = 1 THEN '2024-06-01 00:00:00' ELSE '2027-06-01 00:00:00' END,
                CASE WHEN n MOD 7 = 2 THEN 0 ELSE 1 + n MOD 9 END,
                TIMESTAMP('2023-01-01 00:00:00') + INTERVAL n * 1578 + (CRC32(CONCAT('l', n)) MOD 2000000) SECOND,
                TIMESTAMP('2023-01-01 00:00:00') + INTERVAL n * 1578 SECOND, TIMESTAMP('2023-01-01 00:00:00') + INTERVAL n * 1578 SECOND
         FROM cdb_numbers WHERE n < 60000 ORDER BY n"
    );
    $pdo->exec('DROP TABLE cdb_numbers');
    $pdo->exec('DROP TABLE cdb_digits');
    $pdo->query('ANALYZE TABLE cms_community_topics')->fetchAll();
    T::info('Daten erzeugt in ' . round(microtime(true) - $started, 1) . ' s');
    $topics = new TopicRepository($db);
    $forum = 1;
    $now = '2026-03-01 00:00:00';
    $streamIds = 'SELECT id, last_post_at FROM cms_community_topics WHERE forum_id = ? AND status = ? AND is_pinned = 0';
    $stream = (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_topics WHERE forum_id = ? AND status = 'published' AND is_pinned = 0", [$forum]);
    T::same([60000, 15000, 13186], [(int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_topics'), (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_topics WHERE forum_id = ?', [$forum]), $stream], 'Datenmenge: 60.000 Themen, 15.000 im großen Forum, davon 13.186 im Strom');
    $pinKinds = array_map('intval', $db->execute("SELECT SUM(pinned_until IS NULL), SUM(pinned_until <= ?), SUM(pinned_until > ?) FROM cms_community_topics WHERE forum_id = ? AND status = 'published' AND is_pinned = 1", [$now, $now, $forum])->fetch(\PDO::FETCH_NUM));
    T::check(min($pinKinds) >= 1, 'Testdaten: Pins ohne Frist, abgelaufen und mit Frist in der Zukunft', implode('/', $pinKinds));

    foreach ([0, 250, 1500, 3000, 6000] as $position) {
        $cursor = null;
        if ($position > 0) {
            $row = $db->execute($streamIds . ' ORDER BY last_post_at DESC, id DESC LIMIT 1 OFFSET ' . ($position - 1), [$forum, 'published'])->fetch(\PDO::FETCH_ASSOC);
            $cursor = new Cursor((string) $row['last_post_at'], (int) $row['id']);
        }
        $label = $position === 0 ? 'Forenliste, erste Seite (Pins und Strom)' : 'Forenliste, Cursor an Position ' . $position . ' von ' . $stream;
        $measure = cdbMeasure($db, static fn () => $topics->listForum($forum, $now, $cursor, 20));
        T::info($label . ': ' . $measure['reads'] . ' Lesezugriffe; ' . implode(' | ', array_map(static fn (array $plan): string => implode(', ', array_map(static fn (array $r): string => ($r['type'] ?? '-') . ':' . ($r['key'] ?? 'NULL') . ':' . ($r['Extra'] ?? ''), $plan['rows'])), $measure['plans'])));
        T::same([], cdbPlanProblems($measure, ['keys' => ['idx_community_topics_forum_list'], 'coveringIds' => true, 'maxReads' => $position === 0 ? 150 : 100, 'statements' => $position === 0 ? 4 : 3]), $label . ': Strom als ID-Abfrage nur über den Index, Zeilen über den Primärschlüssel, kein Filesort, wenige Lesezugriffe');
        $idPlans = array_values(array_filter($measure['plans'], static fn (array $plan): bool => str_starts_with($plan['sql'], 'SELECT id, last_post_at ')));
        if ($cursor !== null) {
            T::same('range', $idPlans[0]['rows'][0]['type'] ?? null, $label . ': range über den Keyset (nicht ref mit dem Keyset als Filter)');
        }

        // Ergebnis: dieselben Themen in derselben Reihenfolge wie eine Abfrage über Strom und abgelaufene Pins zusammen.
        $keyset = $cursor === null ? '' : ' AND (last_post_at < ? OR (last_post_at = ? AND id < ?))';
        $keysetParams = $cursor === null ? [] : [$cursor->at, $cursor->at, $cursor->id];
        $expected = array_map('intval', array_column($db->execute(
            "SELECT id FROM cms_community_topics WHERE forum_id = ? AND status = 'published' AND (is_pinned = 0 OR (pinned_until IS NOT NULL AND pinned_until <= ?)){$keyset} ORDER BY last_post_at DESC, id DESC LIMIT 20",
            [$forum, $now, ...$keysetParams]
        )->fetchAll(\PDO::FETCH_ASSOC), 'id'));
        $page = $measure['result'];
        $streamItems = $position === 0 ? array_slice($page->items, count($topics->pinned($forum, $now))) : $page->items;
        T::same([20, $expected, true], [count($streamItems), array_map(static fn (array $topic): int => (int) $topic['id'], $streamItems), $page->next !== null], $label . ': 20 Themen in der Reihenfolge der Liste, weitere Seite vorhanden');

        $star = cdbMeasure($db, static fn () => $db->execute(str_replace('SELECT id, last_post_at', 'SELECT *', $streamIds) . $keyset . ' ORDER BY last_post_at DESC, id DESC LIMIT ?', [$forum, 'published', ...$keysetParams, 21])->fetchAll());
        $starPlan = $star['plans'][0]['rows'][0] ?? [];
        T::info('zum Vergleich der Strom mit SELECT *, Position ' . $position . ': ' . $star['reads'] . ' Lesezugriffe; ' . ($starPlan['type'] ?? '-') . ':' . ($starPlan['key'] ?? 'NULL') . ':' . ($starPlan['Extra'] ?? ''));
    }
    $db->dispose();
});

// Abschnitte der Bereiche aus PR #2b (Vertrag DESIGN-2b §1 Nr. 6; je Datei genau ein Besitzer), alphabetisch; sie nutzen
// cdbSection(), CdbWorld und die Hilfen dieser Datei. Worker-Aktionen dazu liegen in actions-*.php (worker.php).
foreach (glob(__DIR__ . '/sections-*.php') ?: [] as $part) {
    require $part;
}

T::finish();
