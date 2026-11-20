<?php
/**
 * Abschnitte der Einheit S (PR #2b, W2) in `TESTS/community-db`: Schutz gegen MariaDB (Vertrag DESIGN-2b §3.13, §7.3; Entwurf schutz §6.3).
 *
 *  race-throttle   Request-Grenze unter Gleichzeitigkeit (Zählweise Fassung 3): 20 Prozesse gegen Grenze 10 erlauben je Runde nie mehr
 *                  als 10; nacheinander mit 5 ms Abstand genau 10 bei genau 10 Zeilen; 10 fremde Einträge zwischen Vorprüfung und
 *                  eigenem Eintrag (nachgestellt, ohne Zufall) ergeben eine Ablehnung; danach entfernt `Security::pruneDbRateLimitAttempts()`
 *                  nur Zeilen mit Aktion `community_…`, die älter als 7.200 s sind (`_` maskiert), und `pruneThrottle()` wirft nicht.
 *  explain-spam    Spam-Dublette: `PostRepository::recentWithBody()` liest über `idx_community_posts_user` (`range`), wenige Lesezugriffe.
 *  utc-spam-guard  Spam-Dublette in `ContentGuard::screen()` mit Sitzungszone +01:00 und PHP-Zone Europe/Berlin (Sommer, +02:00): ein Beitrag
 *                  von vor 23:30 h (UTC) ist eine Dublette, einer von vor 24:30 h nicht.
 *
 * Die Wettläufe über die Dienste (`race-review-cap`, `e2e-ip-hash`, `utc-spam`) brauchen T und folgen in W4. Die Worker-Aktion
 * `spam.throttle` liegt in `actions-spam.php`. Geladen von `run.php` am Ende; nutzt `cdbSection()`, `cdbRace()`, `cdbProcessProblems()`,
 * `cdbMeasure()`, `cdbPlanProblems()`, `cdbScalar()` und `cdbAt()`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Database;
use CMS\SchemaManager;
use CMS\Security;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Service\ContentGuard;
use CMS\Services\Community\Support\IpHash;
use CMS\Services\Community\Support\Settings;

/**
 * Testdatenbank in der Sitzungszone von PHP (wie `Database::connect()`; die Request-Grenzen des Cores vergleichen `attempted_at` mit
 * `date()`) samt Core-Tabelle `login_attempts` aus der echten DDL.
 */
function cdbSpamDatabase(): MariaDbTestDatabase
{
    $db = MariaDbTestDatabase::create(date('P'));
    $db->getPdo()->exec(SchemaManager::getSchemaQueries('cms_', 'utf8mb4')['login_attempts']);

    return $db;
}

/** Setzt die Core-Verbindung (`Database::instance()`, von `Security` genutzt) und liefert die vorige. */
function cdbSpamSwap(?Database $db): ?Database
{
    $property = new \ReflectionProperty(Database::class, 'instance');
    $previous = $property->getValue();
    $property->setValue(null, $db);

    return $previous;
}

/** Zeilen in `login_attempts` für Kennung und Aktion. */
function cdbSpamRows(MariaDbTestDatabase $db, string $subject, string $action): int
{
    return (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_login_attempts WHERE username = ? AND action = ?', [$subject, $action]);
}

cdbSection('race-throttle', 'race-throttle: Request-Grenze zählt nach dem Eintrag (20 Prozesse, Grenze 10), Aufräumen nur community_ älter als 7.200 s', static function (): void {
    $db = cdbSpamDatabase();
    $previous = cdbSpamSwap($db);
    try {
        $problems = [];
        $summary = [];
        foreach ([201, 202, 203] as $user) {
            $results = cdbRace($db, array_fill(0, 20, ['action' => 'spam.throttle', 'args' => ['action' => 'preview', 'user' => $user]]));
            array_push($problems, ...cdbProcessProblems($results));
            $allowed = count(array_filter($results, static fn (array $r): bool => ($r['result']['allowed'] ?? null) === true));
            $denied = array_filter($results, static fn (array $r): bool => ($r['result']['allowed'] ?? null) === false);
            $rows = cdbSpamRows($db, 'community:u:' . $user, 'community_preview');
            $summary[] = $allowed . ' erlaubt/' . $rows . ' Zeilen';
            T::check($allowed <= 10, 'Runde ' . $user . ': von 20 gleichzeitigen Vorschauen (Grenze 10) sind höchstens 10 erlaubt', $allowed . ' erlaubt');
            T::same(20, $allowed + count($denied), 'Runde ' . $user . ': jede Anfrage hat ein Ergebnis');
            T::check($rows >= $allowed && $rows <= 20, 'Runde ' . $user . ': jede erlaubte Anfrage steht in login_attempts, höchstens eine Zeile je Anfrage', $rows . ' Zeilen');
            if ($denied !== []) {
                T::same([60], array_values(array_unique(array_map(static fn (array $r): int => (int) ($r['result']['retry_after'] ?? -1), $denied))), 'Runde ' . $user . ': abgelehnt mit retry_after = Fenster (60 s)');
            }
        }
        T::same([], $problems, 'keine Ausnahme und keine offene Transaktion in den Prozessen');
        T::info('race-throttle (20 Prozesse, Grenze 10): ' . implode(', ', $summary));

        $guard = new ContentGuard($db, Settings::fromArray([]));
        $sequence = [];
        for ($i = 0; $i < 20; $i++) {
            $sequence[] = $guard->throttle('preview', 300)['allowed'];
            usleep(5000);
        }
        T::same(array_merge(array_fill(0, 10, true), array_fill(0, 10, false)), $sequence, 'nacheinander mit 5 ms Abstand: genau die ersten 10 erlaubt');
        T::same(10, cdbSpamRows($db, 'community:u:300', 'community_preview'), 'genau 10 Zeilen: eine Ablehnung an der Grenze trägt nichts ein (Dauerbeschuss lässt die Tabelle nicht wachsen)');
        T::same(['0.0.0.0'], array_map('strval', $db->execute('SELECT DISTINCT ip_address FROM cms_login_attempts')->fetchAll(\PDO::FETCH_COLUMN)), 'keine Klar-IP: ip_address ist immer 0.0.0.0');

        // Gleichzeitigkeit ohne Zufall: zwischen Vorprüfung und Eintrag erscheinen 10 Einträge anderer Anfragen desselben Eimers.
        // Die Nachzählung (11) liegt über der Grenze; der eigene Eintrag bleibt.
        $interleaved = new class ($db->getPdo()) extends Database {
            public ?\Closure $afterCount = null;

            public function __construct(\PDO $pdo)
            {
                (new \ReflectionProperty(Database::class, 'pdo'))->setValue($this, $pdo);
            }

            public function get_var(string $query, array $params = []): mixed
            {
                $value = parent::get_var($query, $params);
                $hook = $this->afterCount;
                $this->afterCount = null;
                if ($hook !== null) {
                    $hook();
                }

                return $value;
            }
        };
        $interleaved->afterCount = static function () use ($db): void {
            for ($i = 0; $i < 10; $i++) {
                $db->execute('INSERT INTO cms_login_attempts (username, ip_address, action, attempted_at) VALUES (?, ?, ?, NOW())', ['community:u:310', '0.0.0.0', 'community_preview']);
            }
        };
        cdbSpamSwap($interleaved);
        T::same(['allowed' => false, 'retry_after' => 60], $guard->throttle('preview', 310), 'Vorprüfung bestanden, 10 gleichzeitige Einträge vor dem eigenen: Nachzählung 11 > 10, abgelehnt');
        T::same(11, cdbSpamRows($db, 'community:u:310', 'community_preview'), 'der eigene Eintrag der abgelehnten Anfrage bleibt (kein Löschen, Vertrag §3.13)');
        cdbSpamSwap($db);

        $old = date('Y-m-d H:i:s', time() - 7300);
        $young = date('Y-m-d H:i:s', time() - 7000);
        foreach ([['community:u:400', 'community_preview', $old], ['community:g:abc', 'community_search', $old], ['community:u:400', 'community_preview', $young], ['anna', 'login', $old], ['x', 'communityXsearch', $old]] as [$subject, $action, $at]) {
            $db->execute('INSERT INTO cms_login_attempts (username, ip_address, action, attempted_at) VALUES (?, ?, ?, ?)', [$subject, '0.0.0.0', $action, $at]);
        }
        $before = (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_login_attempts');
        T::same(2, Security::pruneDbRateLimitAttempts('community_', 7200), 'pruneDbRateLimitAttempts: genau die zwei community_-Zeilen älter als 7.200 s');
        T::same(
            [1, 1, 1, $before - 2],
            [cdbSpamRows($db, 'community:u:400', 'community_preview'), cdbSpamRows($db, 'anna', 'login'), cdbSpamRows($db, 'x', 'communityXsearch'), (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_login_attempts')],
            'bleiben: jüngere community_-Zeile, Anmeldeversuch, communityXsearch (das _ des Präfixes ist maskiert), alle Zeilen der Wettläufe'
        );
        $guard->pruneThrottle();
        T::same($before - 2, (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_login_attempts'), 'pruneThrottle(): nichts mehr zu löschen, keine Ausnahme');
    } finally {
        cdbSpamSwap($previous);
        $db->dispose();
    }
});

cdbSection('explain-spam', 'EXPLAIN-Gate Spam-Dublette: recentWithBody() über idx_community_posts_user (range), 105.000 Beiträge', static function (): void {
    $db = MariaDbTestDatabase::create();
    $pdo = $db->getPdo();
    $pdo->exec('CREATE TABLE cdb_spam_digits (d TINYINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_spam_digits (d) VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)');
    $pdo->exec('CREATE TABLE cdb_spam_numbers (n INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_spam_numbers (n) SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d FROM cdb_spam_digits a, cdb_spam_digits b, cdb_spam_digits c, cdb_spam_digits e, cdb_spam_digits f');
    // 100.000 Beiträge von 2.000 Mitgliedern (jeder zehnte gelöscht, einige wartend und abgelehnt), dazu 5.000 eines Vielschreibers (77).
    $pdo->exec(
        "INSERT INTO cms_community_posts (topic_id, forum_id, user_id, status, body, created_at, updated_at)
         SELECT (n MOD 5000) + 1, (n MOD 40) + 1, (n MOD 2000) + 1000,
                CASE WHEN n MOD 10 = 0 THEN 'deleted' WHEN n MOD 37 = 0 THEN 'pending' WHEN n MOD 41 = 0 THEN 'rejected' ELSE 'published' END,
                CONCAT('Text ', n), TIMESTAMP('2026-11-01 00:00:00') + INTERVAL (n * 7) SECOND, '2026-11-01 00:00:00'
         FROM cdb_spam_numbers ORDER BY n"
    );
    $pdo->exec(
        "INSERT INTO cms_community_posts (topic_id, forum_id, user_id, status, body, created_at, updated_at)
         SELECT n + 1, 1, 77, 'published', CONCAT('Viel ', n), TIMESTAMP('2026-10-01 00:00:00') + INTERVAL (n * 600) SECOND, '2026-10-01 00:00:00'
         FROM cdb_spam_numbers WHERE n < 5000 ORDER BY n"
    );
    $pdo->query('ANALYZE TABLE cms_community_posts')->fetchAll();
    $posts = new PostRepository($db);
    $measure = cdbMeasure($db, static fn (): array => $posts->recentWithBody(77, '2026-11-03 00:00:00', ContentGuard::DUPLICATE_SCAN));
    $plan = $measure['plans'][0]['rows'][0] ?? [];
    T::same(['idx_community_posts_user', 'range'], [$plan['key'] ?? null, $plan['type'] ?? null], 'recentWithBody: key idx_community_posts_user, type range (Statusliste vollständig im IN)', json_encode($plan));
    T::same([], cdbPlanProblems($measure, ['keys' => ['idx_community_posts_user'], 'filesort' => true, 'maxReads' => 600, 'statements' => 1]), 'recentWithBody: kein Tabellenscan, wenige Lesezugriffe (kleiner Filesort über die Treffer des Mitglieds erlaubt)');
    T::same(ContentGuard::DUPLICATE_SCAN, count($measure['result']), 'höchstens DUPLICATE_SCAN Zeilen');
    T::info('explain-spam: ' . $measure['reads'] . ' Lesezugriffe; ' . ($plan['Extra'] ?? ''));
    $db->dispose();
});

cdbSection('utc-spam-guard', 'utc-spam-guard: Spam-Dublette mit Sitzungszone +01:00 und PHP-Zone Europe/Berlin (+02:00) rechnet in UTC', static function (): void {
    $text = 'Kaufen Sie jetzt die besten Uhren zum halben Preis bei uns im Laden';
    $now = cdbAt();
    $check = static function (int $age) use ($text, $now): array {
        $db = MariaDbTestDatabase::create();
        $user = $db->user();
        $forum = $db->forum();
        $first = $db->topic(['forum' => $forum, 'user' => $user]);
        $second = $db->topic(['forum' => $forum, 'user' => $user]);
        $db->post(['topic' => $first, 'user' => $user, 'body' => $text, 'created_at' => gmdate('Y-m-d H:i:s', $now - $age)]);
        $guard = new ContentGuard(
            $db,
            Settings::fromArray([]),
            static fn (string $markdown): array => ['links' => 0, 'images' => 0],
            static fn (array $context): array => ['rejected' => false, 'reason' => ''],
            static fn (): array => ['web' => false, 'ip' => '', 'user_agent' => ''],
            static fn (string $name, string $subject, int $max, int $window): bool => true,
            static function (array $entry): void {
            },
            new IpHash(static fn (): string => str_repeat('k', 32))
        );
        $result = $guard->screen($user, 'reply', [
            'forum_id' => 0, 'topic_id' => $second, 'post_id' => 0, 'title' => null, 'body' => $text, 'verdict' => ContentGuard::ALLOW,
            'content' => ['links' => 0, 'images' => 0, 'attachments' => 0, 'failed' => false], 'hosts' => [],
        ], $now);
        $db->dispose();

        return $result['signals'] ?? ['<' . json_encode($result) . '>'];
    };
    T::same(['duplicate'], $check(23 * 3600 + 1800), 'Beitrag von vor 23:30 h (UTC): Dublette');
    T::same([], $check(24 * 3600 + 1800), 'Beitrag von vor 24:30 h (UTC): keine Dublette');
});
