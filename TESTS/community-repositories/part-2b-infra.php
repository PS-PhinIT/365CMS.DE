<?php
/**
 * Teil der Suite `community-repositories` (Besitzer R0, PR #2b Welle 0): Testinfrastruktur der Bereiche.
 *
 *  - SQLite-Double: `ENUM(…)` wird `TEXT CHECK (… IN (…))` (Vertrag DESIGN-2b §6 W0, GK3), Core-Tabellen `notifications` und
 *    `mail_queue`, Parameter je Protokolleintrag (`$logParams`, `paramsAt()`).
 *  - Fixtures aus PR #2b (`category`, `report`, `ban`, `subscription`, `reaction`, `rank`, `queueJob`, `attachment`, `moderationLog`)
 *    gegen das Double und, mit `CMS_TEST_DB_HOST`, gegen MariaDB.
 *  - Präfix der Test-Datenbanken (`CMS_TEST_DB_PREFIX`, `TESTS/lib/TestDatabasePrefix.php`): Prüfung der Variable ohne Datenbank,
 *    mit MariaDB zwei Präfixe nebeneinander (Aufräumen des einen lässt den anderen stehen).
 *
 * Geladen von `run.php` vor `T::finish()`; nutzt dessen Hilfen (`rt()`) und Klassen.
 */

declare(strict_types=1);

use CMS\Database;

require_once dirname(__DIR__) . '/lib/TestDatabasePrefix.php';

/** Liest eine Zeile als Array (beide Backends liefern Objekte). */
function r0Row(Database $db, string $sql, array $params = []): ?array
{
    $row = $db->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** Die Fixtures aus PR #2b gegen ein Backend. */
function r0Fixtures(Database $db, string $label): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $category = $db->category();
    $row = $db->row('community_categories', ['id' => $category]);
    T::same([1, 0], [(int) $row['is_visible'], (int) $row['sort_order']], "[{$label}] category(): sichtbar, Reihenfolge 0");
    $hidden = $db->category(['title' => 'Intern', 'slug' => 'intern', 'visible' => false, 'sort' => 5, 'created_at' => rt(5)]);
    $row = $db->row('community_categories', ['id' => $hidden]);
    T::same(['Intern', 'intern', 0, 5, rt(5)], [$row['title'], $row['slug'], (int) $row['is_visible'], (int) $row['sort_order'], $row['created_at']], "[{$label}] category(): Optionen title, slug, visible, sort, created_at");

    $user = $db->user();
    $moderator = $db->user(['role' => 'moderator']);
    $forum = $db->forum(['category' => $category]);
    $topic = $db->topic(['forum' => $forum, 'user' => $user]);
    $post = $db->post(['topic' => $topic, 'user' => $user]);

    $report = $db->report(['target' => $post]);
    $row = $db->row('community_reports', ['id' => $report]);
    T::same(['post', $post, null, null, 'spam', 'open', 1, null, null, CommunityTestDatabase::FIXTURE_TIME], [$row['target_type'], (int) $row['target_id'], $row['forum_id'], $row['reporter_id'], $row['reason'], $row['status'], (int) $row['notify_reporter'], $row['handled_by'], $row['handled_at'], $row['created_at']], "[{$label}] report(): Standard post, spam, open, Rückmeldung an");
    $closed = $db->report(['target_type' => 'topic', 'target' => $topic, 'forum' => $forum, 'reporter' => $user, 'reason' => 'other', 'message' => 'Text', 'status' => 'valid', 'notify' => 0, 'handled_by' => $moderator, 'handled_at' => rt(60), 'created_at' => rt(30)]);
    $row = $db->row('community_reports', ['id' => $closed]);
    T::same(['topic', $topic, $forum, $user, 'other', 'Text', 'valid', 0, $moderator, rt(60), rt(30)], [$row['target_type'], (int) $row['target_id'], (int) $row['forum_id'], (int) $row['reporter_id'], $row['reason'], $row['message'], $row['status'], (int) $row['notify_reporter'], (int) $row['handled_by'], $row['handled_at'], $row['created_at']], "[{$label}] report(): alle Optionen");

    $ban = $db->ban(['user' => $user, 'issued_by' => $moderator, 'created_at' => rt(-10)]);
    $row = $db->row('community_bans', ['id' => $ban]);
    T::same([$moderator, rt(-10), '2026-01-01 00:00:00'], [(int) $row['issued_by'], $row['created_at'], $row['starts_at']], "[{$label}] ban(): issued_by und created_at");
    $plain = $db->row('community_bans', ['id' => $db->ban(['user' => $moderator, 'starts_at' => rt(-3)])]);
    T::same([null, rt(-3)], [$plain['issued_by'], $plain['created_at']], "[{$label}] ban(): ohne Angabe kein Aussteller, created_at = starts_at");

    $db->subscription(['user' => $user, 'target' => $topic]);
    $row = $db->row('community_subscriptions', ['user_id' => $user, 'target_type' => 'topic', 'target_id' => $topic]);
    T::same(['instant', null, CommunityTestDatabase::FIXTURE_TIME], [$row['mode'], $row['notified_at'], $row['created_at']], "[{$label}] subscription(): Standard topic, instant");
    $db->subscription(['user' => $user, 'target_type' => 'forum', 'target' => $forum, 'mode' => 'none', 'notified_at' => rt(9), 'created_at' => rt(8)]);
    $row = $db->row('community_subscriptions', ['user_id' => $user, 'target_type' => 'forum', 'target_id' => $forum]);
    T::same(['none', rt(9), rt(8)], [$row['mode'], $row['notified_at'], $row['created_at']], "[{$label}] subscription(): Optionen");

    $db->reaction(['post' => $post, 'user' => $moderator]);
    $db->reaction(['post' => $post, 'user' => $user, 'reaction' => 'love', 'created_at' => rt(2)]);
    T::same(
        [[$moderator, 'like', CommunityTestDatabase::FIXTURE_TIME], [$user, 'love', rt(2)]],
        array_map(static fn (array $r): array => [(int) $r['user_id'], $r['reaction'], $r['created_at']], $db->rows('community_reactions', ['post_id' => $post], 'reaction')),
        "[{$label}] reaction(): Standard like, Optionen reaction und created_at"
    );

    $rank = $db->rank(['title' => 'Aktiv', 'min_posts' => 50, 'sort' => 30]);
    $row = $db->row('community_ranks', ['id' => $rank]);
    T::same(['Aktiv', null, 50, null, null, 30], [$row['title'], $row['title_en'], (int) $row['min_posts'], $row['color'], $row['icon'], (int) $row['sort_order']], "[{$label}] rank(): Optionen");
    $manual = $db->row('community_ranks', ['id' => $db->rank(['min_posts' => null, 'title_en' => 'Team', 'color' => '#336699', 'icon' => 'star'])]);
    T::same([null, 'Team', '#336699', 'star'], [$manual['min_posts'], $manual['title_en'], $manual['color'], $manual['icon']], "[{$label}] rank(): manueller Rang ohne min_posts");

    $attachment = $db->attachment(['post' => $post, 'user' => $user]);
    $row = $db->row('community_attachments', ['id' => $attachment]);
    T::same([$post, $user, 'txt', 0, null, CommunityTestDatabase::FIXTURE_TIME], [(int) $row['post_id'], (int) $row['user_id'], $row['extension'], (int) $row['download_count'], $row['upload_token'], $row['created_at']], "[{$label}] attachment(): zugeordnet, Standard txt");
    T::check(preg_match('/^[0-9a-f]{64}$/', (string) $row['sha256']) === 1 && $row['storage_path'] !== '', "[{$label}] attachment(): Prüfsumme und Ablagepfad gesetzt");
    $pending = $db->row('community_attachments', ['id' => $db->attachment(['user' => $user, 'token' => str_repeat('a', 32), 'extension' => 'png', 'mime_type' => 'image/png', 'width' => 20, 'height' => 10, 'storage_path' => 'x/y.png', 'created_at' => rt(4)])]);
    T::same([null, str_repeat('a', 32), 'png', 'image/png', 20, 10, 'x/y.png', rt(4)], [$pending['post_id'], $pending['upload_token'], $pending['extension'], $pending['mime_type'], (int) $pending['width'], (int) $pending['height'], $pending['storage_path'], $pending['created_at']], "[{$label}] attachment(): noch nicht zugeordnet (Token), Bildmaße");

    $log = $db->moderationLog(['actor' => $moderator, 'action' => 'post.hide', 'target' => $post, 'forum' => $forum, 'reason' => 'Spam', 'details' => ['from' => 'published', 'auto' => false]]);
    $row = $db->row('community_moderation_log', ['id' => $log]);
    T::same([$moderator, 'post.hide', 'post', $post, $forum, 'Spam', '{"from":"published","auto":false}', CommunityTestDatabase::FIXTURE_TIME], [(int) $row['actor_id'], $row['action'], $row['target_type'], (int) $row['target_id'], (int) $row['forum_id'], $row['reason'], $row['details_json'], $row['created_at']], "[{$label}] moderationLog(): Optionen, details als JSON");
    $system = $db->row('community_moderation_log', ['id' => $db->moderationLog(['action' => 'user.ban', 'target_type' => 'user', 'target' => $user, 'created_at' => rt(7)])]);
    T::same([null, 'user', null, null, null, rt(7)], [$system['actor_id'], $system['target_type'], $system['forum_id'], $system['reason'], $system['details_json'], $system['created_at']], "[{$label}] moderationLog(): Systemaktion ohne Akteur, ohne details");

    if ($db->tableExists($db->getPrefix() . 'community_notify_queue')) {
        $job = $db->row('community_notify_queue', ['id' => $db->queueJob(['kind' => 'reply', 'topic' => $topic, 'post' => $post, 'actor' => $user])]);
        T::same(['reply', $topic, $post, $user, null, 0, 0, null], [$job['kind'], (int) $job['topic_id'], (int) $job['post_id'], (int) $job['actor_id'], $job['detail'], (int) $job['phase'], (int) $job['cursor_user_id'], $job['locked_until']], "[{$label}] queueJob(): Auftrag ohne Lease");
    } else {
        T::info("[{$label}] queueJob(): Tabelle community_notify_queue folgt in Welle 2 (Einheit N)");
    }

    foreach (['report' => ['target_type' => 'post'], 'subscription' => ['user' => $user], 'reaction' => ['post' => $post], 'moderationLog' => ['target' => 1]] as $fixture => $incomplete) {
        $thrown = false;
        try {
            $db->{$fixture}($incomplete);
        } catch (\InvalidArgumentException) {
            $thrown = true;
        }
        T::check($thrown, "[{$label}] {$fixture}(): Pflichtangabe fehlt → InvalidArgumentException");
    }
}

T::run('SQLite-Double (PR #2b): ENUM als TEXT CHECK, Core-Tabellen notifications und mail_queue', static function (): void {
    $sql = CommunityTestSchema::sqlite("CREATE TABLE IF NOT EXISTS cms_t (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, status ENUM('a','b') NOT NULL DEFAULT 'a', kind ENUM( 'x' , 'y-z' ) NULL) ENGINE=InnoDB")[0];
    T::check(str_contains($sql, "status TEXT CHECK (status IN ('a','b')) NOT NULL DEFAULT 'a'"), 'ENUM(…) → TEXT CHECK (Spalte IN (…)), Standard und NOT NULL bleiben', $sql);
    T::check(str_contains($sql, "kind TEXT CHECK (kind IN ( 'x' , 'y-z' )) NULL"), 'ENUM mit Leerraum und Bindestrich', $sql);
    T::check(!preg_match('/\bENUM\b/i', $sql), 'kein ENUM im SQLite-Text');

    foreach (['notifications', 'mail_queue'] as $table) {
        T::check(in_array($table, CommunityTestSchema::CORE_TABLES, true), 'CORE_TABLES enthält ' . $table);
    }
    T::same(1, array_search('notifications', CommunityTestSchema::CORE_TABLES, true) - array_search('users', CommunityTestSchema::CORE_TABLES, true), 'notifications steht direkt nach users (Fremdschlüssel in MariaDB)');

    $db = CommunityTestDatabase::create();
    $user = $db->user();
    // Gegenprobe (Probe f31/enum2.php): Tabelle legt an, Standard pending greift, das INSERT der Mail-Warteschlange läuft, bogus scheitert.
    $insert = 'INSERT INTO cms_mail_queue (recipient, subject, body, headers, content_type, source, status, attempts, max_attempts, available_at, sent_at, locked_at, last_attempt_at, attachment_path, attachment_name, attachment_mime, error_category, last_error) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $db->execute($insert, ['a@example.test', 'Betreff', 'Text', '{}', 'plain', 'community', 'pending', 0, 5, null, null, null, null, null, null, null, null, null]);
    $db->execute('INSERT INTO cms_mail_queue (recipient, subject, body) VALUES (?, ?, ?)', ['b@example.test', 'Ohne Status', 'Text']);
    T::same(['pending', 'pending'], array_column($db->rows('mail_queue', [], 'id'), 'status'), 'mail_queue: INSERT der Warteschlange läuft, Standard pending greift');
    $rejected = false;
    try {
        $db->execute('INSERT INTO cms_mail_queue (recipient, subject, status) VALUES (?, ?, ?)', ['c@example.test', 'x', 'bogus']);
    } catch (\PDOException $e) {
        $rejected = str_contains($e->getMessage(), 'CHECK');
    }
    T::check($rejected, "mail_queue: status = 'bogus' scheitert am CHECK (wie am ENUM der Datenbank)");

    $db->execute('INSERT INTO cms_notifications (user_id, type, title, message, url, group_key, event_count) VALUES (?, ?, ?, ?, ?, ?, ?)', [$user, 'community.reply', 'T', 'M', '/x', 'community:topic:1', 2]);
    $row = $db->row('notifications', ['user_id' => $user]);
    T::same(['community.reply', 'community:topic:1', 2, 0], [$row['type'], $row['group_key'], (int) $row['event_count'], (int) $row['is_read']], 'notifications: Zeile mit group_key, event_count, is_read 0');
});

T::run('SQLite-Double (PR #2b): Parameter je Protokolleintrag ($logParams, paramsAt)', static function (): void {
    $db = CommunityTestDatabase::create();
    $db->log = [];
    $db->logParams = [];
    $db->execute('SELECT ? AS a', [5]);
    $db->execute('SELECT 1');
    $db->execute('SELECT ?, ? AS b', ['x', null]);
    T::same([[5], [], ['x', null]], [$db->paramsAt(0), $db->paramsAt(1), $db->paramsAt(2)], 'paramsAt(i): gebundene Parameter des Eintrags $log[i]');
    T::same([], $db->paramsAt(9), 'paramsAt(): unbekannter Index → []');
    $db->failOn = ['/FEHLER/'];
    try {
        $db->execute('SELECT ? AS FEHLER', [7]);
    } catch (\PDOException) {
    }
    T::same([7], $db->paramsAt(3), 'auch eine fehlgeschlagene Anweisung hat ihre Parameter im Protokoll');
    $db->log = [];
    $db->execute('SELECT ? AS c', [8]);
    T::same([8], $db->paramsAt(0), 'nach $log = [] zählt der Index neu (wie txAt)');
});

T::run('Fixtures aus PR #2b (SQLite-Double)', static function (): void {
    r0Fixtures(CommunityTestDatabase::create(), 'SQLite-Double');
});

T::run('CMS_TEST_DB_PREFIX: Prüfung der Variable (ohne Datenbank)', static function (): void {
    T::same('CMS_TEST_DB_PREFIX', TestDatabasePrefix::ENV, 'Name der Umgebungsvariable');
    foreach (['a' => true, 'w0_' => true, 'abcdefghij12' => true, 'abcdefghij123' => false, 'W0_' => false, 'w0-' => false, 'w0.' => false, ' w0' => false, "w0\n" => false, 'ä' => false] as $value => $expected) {
        T::same($expected, TestDatabasePrefix::valid((string) $value), 'valid(' . T::export((string) $value) . ')');
    }
    $lib = dirname(__DIR__) . '/lib/TestDatabasePrefix.php';
    $probe = static function (?string $value) use ($lib): array {
        $env = getenv();
        unset($env[TestDatabasePrefix::ENV]);
        if ($value !== null) {
            $env[TestDatabasePrefix::ENV] = $value;
        }
        $code = 'require ' . var_export($lib, true) . '; echo TestDatabasePrefix::resolve("pr2a_"), "|ok";';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    };
    T::same([0, 'pr2a_|ok', ''], $probe(null), 'ohne Variable: der Standard der Suite');
    T::same([0, 'pr2a_|ok', ''], $probe(''), 'leere Variable: der Standard der Suite');
    T::same([0, 'w0_|ok', ''], $probe('w0_'), 'gültige Variable ersetzt das Präfix');
    foreach (['W0', 'w0-x', str_repeat('a', 13)] as $bad) {
        [$exit, $out, $err] = $probe($bad);
        T::same([2, ''], [$exit, $out], 'ungültige Variable ' . T::export($bad) . ': Abbruch mit Exit 2, keine Ausgabe');
        T::check(str_contains($err, 'CMS_TEST_DB_PREFIX') && str_contains($err, '[a-z0-9_]'), 'Meldung nennt Variable und Regel', $err);
    }
});

if (MariaDbTestDatabase::configured()) {
    T::run('Fixtures aus PR #2b und Core-Tabellen (MariaDB)', static function (): void {
        $db = MariaDbTestDatabase::create();
        try {
            r0Fixtures($db, 'MariaDB');
            $user = $db->user();
            $db->execute('INSERT INTO cms_notifications (user_id, type, title, message, group_key, event_count) VALUES (?, ?, ?, ?, ?, ?)', [$user, 'community.reply', 'T', 'M', 'community:topic:1', 2]);
            T::same('community:topic:1', $db->row('notifications', ['user_id' => $user])['group_key'] ?? null, 'MariaDB: notifications mit echtem Fremdschlüssel auf users');
            $db->log = [];
            $db->logParams = [];
            $db->execute('SELECT ? AS a', [11]);
            T::same([11], $db->paramsAt(0), 'MariaDB: paramsAt() wie beim Double');
        } finally {
            $db->dispose();
        }
    });

    T::run('CMS_TEST_DB_PREFIX mit MariaDB: zwei Präfixe nebeneinander, Aufräumen des einen lässt den anderen stehen', static function (): void {
        $admin = new \PDO('mysql:host=' . getenv('CMS_TEST_DB_HOST') . ';charset=utf8mb4', (string) getenv('CMS_TEST_DB_USER'), (string) getenv('CMS_TEST_DB_PASS'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $exists = static fn (string $name): bool => in_array($name, $admin->query('SHOW DATABASES')->fetchAll(\PDO::FETCH_COLUMN), true);
        $effective = MariaDbTestDatabase::databasePrefix();
        T::same(TestDatabasePrefix::resolve(MariaDbTestDatabase::DATABASE_PREFIX), $effective, 'wirksames Präfix: Variable, sonst DATABASE_PREFIX (' . $effective . ')');
        T::same('pr2a_', MariaDbTestDatabase::DATABASE_PREFIX, 'Standard bleibt pr2a_');

        $a = MariaDbTestDatabase::create('+01:00', 'r0ta_');
        $b = MariaDbTestDatabase::create('+01:00', 'r0tb_');
        $own = MariaDbTestDatabase::create();
        try {
            T::check(str_starts_with($a->databaseName, 'r0ta_' . getmypid() . '_') && str_starts_with($b->databaseName, 'r0tb_' . getmypid() . '_'), 'Datenbanknamen beginnen mit dem Präfix und der Prozessnummer', $a->databaseName . ', ' . $b->databaseName);
            T::check(str_starts_with($own->databaseName, $effective), 'ohne Angabe gilt das wirksame Präfix', $own->databaseName);
            T::same([true, true, true], [$exists($a->databaseName), $exists($b->databaseName), $exists($own->databaseName)], 'alle drei Datenbanken angelegt');
            MariaDbTestDatabase::dropAll('r0ta_');
            T::same([false, true, true], [$exists($a->databaseName), $exists($b->databaseName), $exists($own->databaseName)], 'dropAll(r0ta_) löscht nur die Datenbank dieses Präfixes');
            MariaDbTestDatabase::dropAll('r0tb_');
            T::same([false, true], [$exists($b->databaseName), $exists($own->databaseName)], 'dropAll(r0tb_) danach: die Datenbank mit dem wirksamen Präfix steht noch');
            $foreign = 'r0tc_' . getmypid() . '_fremd';
            $admin->exec("CREATE DATABASE `{$foreign}`");
            try {
                MariaDbTestDatabase::dropAll('r0tc_');
                MariaDbTestDatabase::dropAll();
                T::same(true, $exists($foreign), 'eine nicht von diesem Prozess angelegte Datenbank bleibt stehen, auch mit passendem Präfix');
            } finally {
                $admin->exec("DROP DATABASE IF EXISTS `{$foreign}`");
            }
            T::same(false, $exists($own->databaseName), 'dropAll() ohne Angabe löscht die übrigen eigenen Datenbanken (wie am Prozessende)');
            $thrown = false;
            try {
                MariaDbTestDatabase::create('+01:00', 'Bad-Prefix');
            } catch (\InvalidArgumentException) {
                $thrown = true;
            }
            T::check($thrown, 'create() mit ungültigem Präfix → InvalidArgumentException, keine Datenbank');
        } finally {
            foreach ([$a, $b, $own] as $db) {
                $db->dispose();
            }
        }
    });
}
