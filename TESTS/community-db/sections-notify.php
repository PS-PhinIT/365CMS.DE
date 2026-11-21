<?php
/**
 * Abschnitte der Einheit N (PR #2b, W2) in `TESTS/community-db`: Zustellung, Bündelung und Reaktionen gegen MariaDB (Vertrag DESIGN-2b
 * §3.10, §4.5, §7.3; Entwurf interaktion §6.3). Worker-Aktionen in `actions-notify.php`.
 *
 *  notify-race-bundle    6 Prozesse `runQueue()`, je ein Antwortauftrag im selben Thema, 40 Abonnenten mit offenem Tor: je Abonnent genau
 *                        eine ungelesene Zeile mit event_count 6, genau eine Mail, notified_at gesetzt, Warteschlange leer.
 *  notify-race-lease     4 Prozesse auf 3 Aufträge mit je 300 Abonnenten: Summe event_count 900, je Empfänger 3, 300 Mails, kein Auftrag
 *                        und kein Empfänger doppelt.
 *  notify-inject         Fehler an jeder Anweisung eines Laufs, danach Wiederholung: je (Auftrag, Empfänger) genau ein Hinweis, Mails genau
 *                        einmal; dazu ein Deadlock beim Einfügen in mail_queue: `Transaction::run()` wiederholt, ein Hinweis, eine Mail.
 *  notify-core-cas       6 Prozesse `notifyGrouped()` ohne Mutex auf dieselbe Gruppe: Summe event_count exakt (Zeilen ≥ 1 zulässig).
 *  reaction-race         8 Prozesse × 25 Mitglieder auf denselben Beitrag (setzen, wechseln, entfernen): Zähler = Zeilen, kein Fehler.
 *  reaction-same-user    4 Prozesse × 50 set/remove desselben Paares: Zähler ∈ {0, 1} und gleich der Zeile.
 *  notify-utc            Sitzungen +01:00 und +05:30: notified_at, created_at der Warteschlange und der Abos, last_seen_at = UTC der Uhr.
 *  explain-interaction   Empfänger über idx_community_subscriptions_target ohne Filesort, Tor über PRIMARY, Ignorieren, Bündelung
 *                        (idx_user_group), Reaktionen (PRIMARY), Lesestatus, `dueIds()` je Stufe über idx_community_notify_queue_kind.
 *
 * Geladen von `run.php` am Ende; nutzt `cdbSection()`, `cdbRace()`, `cdbProcessProblems()`, `cdbMeasure()`, `cdbPlanProblems()`,
 * `cdbScalar()`, `cdbAt()`, `cdbUtc()`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Logger;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\ReactionRepository;
use CMS\Services\Community\Repository\ReadStateRepository;
use CMS\Services\Community\Repository\SubscriptionRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\NotificationDispatcher;
use CMS\Services\Community\Service\ReactionService;
use CMS\Services\Community\Service\ReadStateService;
use CMS\Services\Community\Service\SubscriptionService;
use CMS\Services\Community\Support\CommunityModule;
use CMS\Services\Community\Support\Settings;
use CMS\Services\MailQueueService;
use CMS\Services\NotificationService;
use CMS\Services\OptionStore;
use CMS\Services\SettingsService;

/** Geheimnis des Abmeldelinks in den Abschnitten der Einheit (≥ 16 Byte). */
const CDB_NT_SECRET = 'cdb-notify-secret-0123456789abcdef';

/** UTC-Text der echten Zeit plus Sekunden (Worker lesen die echte Uhr). */
function cdbNtReal(int $offset = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offset);
}

/** Objekt einer Klasse mit privatem Konstruktor, Eigenschaften gesetzt. */
function cdbNtInstance(string $class, array $properties): object
{
    $object = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    \Closure::bind(function () use ($properties): void {
        foreach ($properties as $name => $value) {
            $this->{$name} = $value;
        }
    }, $object, $class)();

    return $object;
}

/**
 * Echte `NotificationService` (als Singleton) und `MailQueueService` auf der Testverbindung; der Optionsspeicher ist vorbelegt.
 *
 * @return array{0: NotificationService, 1: MailQueueService}
 */
function cdbNtCore(MariaDbTestDatabase $db): array
{
    $core = cdbNtInstance(NotificationService::class, ['db' => $db, 'prefix' => 'cms_']);
    (new \ReflectionProperty(NotificationService::class, 'instance'))->setValue(null, $core);
    \Closure::bind(function (): void {
        $this->values = ['mail.queue_cron_token' => 'cdb-token'];
        $this->autoloaded = true;
        $this->complete = true;
    }, OptionStore::getInstance(), OptionStore::class)();
    $queue = cdbNtInstance(MailQueueService::class, ['db' => $db, 'prefix' => 'cms_', 'settings' => cdbNtInstance(SettingsService::class, []), 'logger' => Logger::instance()->withChannel('mail.queue')]);

    return [$core, $queue];
}

/** Zugriff der Abschnitte: Mitglieder lesen und schreiben (`member`), alle Konten bestätigt. */
function cdbNtAccess(MariaDbTestDatabase $db, Settings $settings, \Closure $clock): CommunityAccess
{
    return new CommunityAccess(
        $db,
        $settings,
        static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, CDB_ROLE_CAPS[$role] ?? [], true),
        static fn (): bool => true,
        $clock,
        static fn (int $userId): bool => true
    );
}

/**
 * Dispatcher im Hauptprozess (Uhr der Welt, echte Core-Dienste, optional eigene Mail-Closure).
 *
 * @param \Closure(string, string, string, array<string, string>): bool|null $mail
 */
function cdbNtDispatcher(MariaDbTestDatabase $db, Settings $settings, \Closure $clock, ?\Closure $mail = null): NotificationDispatcher
{
    CommunityModule::setGate(static fn (): bool => true);
    [, $queue] = cdbNtCore($db);
    $access = cdbNtAccess($db, $settings, $clock);
    $can = static fn (?int $u, string $a, array $c): AccessDecision => $access->can($u, $a, $c);
    $subscriptions = new SubscriptionService($db, $settings, $clock, $can, static function (): void {
    }, static fn (): array => ['allowed' => true, 'retry_after' => 0], static fn (): string => CDB_NT_SECRET);
    $mail ??= static fn (string $to, string $subject, string $body, array $headers): bool => $queue->enqueuePlainStrict($to, $subject, $body, $headers, NotificationDispatcher::MAIL_SOURCE) > 0;

    return new NotificationDispatcher($db, $settings, $clock, $can, null, $mail, static fn (): bool => true, null, static fn (): ?float => null, static function () use ($access): void {
        $access->flush();
    }, $subscriptions);
}

/**
 * Mitglieder mit Statistikzeile und bestätigter Adresse (mehrzeilige INSERTs). Liefert die IDs aufsteigend.
 *
 * @return list<int>
 */
function cdbNtMembers(MariaDbTestDatabase $db, int $count, string $tag): array
{
    $pdo = $db->getPdo();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = $pdo->quote($tag . $i) . ', ' . $pdo->quote($tag . $i . '@example.test') . ", 'x', " . $pdo->quote('Mitglied ' . $tag . $i) . ", 'member', 'active'";
    }
    $first = 0;
    foreach (array_chunk($rows, 200) as $chunk) {
        $pdo->exec('INSERT INTO cms_users (username, email, password, display_name, role, status) VALUES (' . implode('), (', $chunk) . ')');
        $first = $first === 0 ? (int) $pdo->lastInsertId() : $first;
    }
    $ids = array_map('intval', $pdo->query('SELECT id FROM cms_users WHERE username LIKE ' . $pdo->quote($tag . '%') . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    foreach (array_chunk($ids, 200) as $chunk) {
        $pdo->exec('INSERT INTO cms_community_user_stats (user_id, trust_level, trust_level_locked, post_count, updated_at) VALUES ' . implode(', ', array_map(static fn (int $id): string => "({$id}, 1, 1, 10, '2026-01-01 00:00:00')", $chunk)));
        $pdo->exec('INSERT INTO cms_user_meta (user_id, meta_key, meta_value) VALUES ' . implode(', ', array_map(static fn (int $id): string => "({$id}, 'email_verified_at', '2026-01-01 00:00:00')", $chunk)));
    }

    return $ids;
}

/** Thema mit erstem Beitrag in einem offenen Forum. @return array{0: int, 1: int, 2: int} Forum, Thema, erster Beitrag */
function cdbNtThread(MariaDbTestDatabase $db, int $authorId, string $createdUtc): array
{
    $forum = $db->forum(['title' => 'Benachrichtigungen ' . bin2hex(random_bytes(2))]);
    (new ForumRepository($db))->savePermissions($forum, [
        ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1, 'create_topic' => 1, 'reply' => 1, 'react' => 1]],
    ], cdbUtc());
    $topic = $db->topic(['forum' => $forum, 'user' => $authorId, 'title' => 'Thema', 'created_at' => $createdUtc]);
    $first = $db->post(['topic' => $topic, 'user' => $authorId, 'first' => true, 'created_at' => $createdUtc]);
    $db->execute('UPDATE cms_community_topics SET first_post_id = ?, last_post_id = ? WHERE id = ?', [$first, $first, $topic]);

    return [$forum, $topic, $first];
}

/** Abos `instant` mit offenem Tor. @param list<int> $users */
function cdbNtSubscribe(MariaDbTestDatabase $db, array $users, string $targetType, int $targetId, string $createdUtc): void
{
    foreach (array_chunk($users, 200) as $chunk) {
        $db->getPdo()->exec('INSERT INTO cms_community_subscriptions (user_id, target_type, target_id, mode, notified_at, created_at) VALUES '
            . implode(', ', array_map(static fn (int $id): string => "({$id}, '{$targetType}', {$targetId}, 'instant', NULL, '{$createdUtc}')", $chunk)));
    }
}

/** Antworten mit je einem Auftrag `reply`. @param list<int> $actors @return list<int> */
function cdbNtReplies(MariaDbTestDatabase $db, int $topicId, array $actors, string $createdUtc): array
{
    $posts = [];
    foreach ($actors as $actor) {
        $posts[] = $post = $db->post(['topic' => $topicId, 'user' => $actor, 'created_at' => $createdUtc]);
        $db->queueJob(['kind' => 'reply', 'topic' => $topicId, 'post' => $post, 'created_at' => $createdUtc]);
    }

    return $posts;
}

/**
 * Hinweise und Mails einer Empfängergruppe.
 *
 * @param list<int> $users
 * @return array{rows: array<int, int>, counts: array<int, int>, mails: array<int, int>, unread: int, sum: int}
 */
function cdbNtDelivered(MariaDbTestDatabase $db, array $users): array
{
    $in = implode(', ', $users);
    $rows = [];
    $counts = [];
    foreach ($db->getPdo()->query("SELECT user_id, COUNT(*) AS n, SUM(event_count) AS s FROM cms_notifications WHERE is_read = 0 AND user_id IN ({$in}) GROUP BY user_id")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $rows[(int) $row['user_id']] = (int) $row['n'];
        $counts[(int) $row['user_id']] = (int) $row['s'];
    }
    $mails = [];
    foreach ($db->getPdo()->query("SELECT u.id, COUNT(*) AS n FROM cms_mail_queue m JOIN cms_users u ON u.email = m.recipient WHERE u.id IN ({$in}) GROUP BY u.id")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $mails[(int) $row['id']] = (int) $row['n'];
    }

    return ['rows' => $rows, 'counts' => $counts, 'mails' => $mails, 'unread' => array_sum($rows), 'sum' => array_sum($counts)];
}

/** Lädt die Klassen der Einheit im Hauptprozess (die Gegenprobe verlangt eine Anwendung der Mutante auch hier). */
function cdbNtClasses(): void
{
    foreach ([NotificationDispatcher::class, SubscriptionRepository::class, ReactionService::class, ReactionRepository::class, NotificationService::class, MailQueueService::class] as $class) {
        if (!class_exists($class)) {
            throw new \LogicException('Klasse fehlt: ' . $class);
        }
    }
}

cdbSection('notify-race-bundle', 'notify-race-bundle: 6 Prozesse, je ein Antwortauftrag im selben Thema, 40 Abonnenten: genau eine Zeile (event_count 6) und eine Mail je Abonnent', static function (): void {
    cdbNtClasses();
    $db = MariaDbTestDatabase::create();
    try {
        $now = cdbNtReal(-60);
        [$author] = cdbNtMembers($db, 1, 'ntba');
        [, $topic] = cdbNtThread($db, $author, cdbNtReal(-3600));
        $subscribers = cdbNtMembers($db, 40, 'ntbs');
        cdbNtSubscribe($db, $subscribers, 'topic', $topic, $now);
        cdbNtReplies($db, $topic, cdbNtMembers($db, 6, 'ntbr'), $now);
        $results = cdbRace($db, array_fill(0, 6, ['action' => 'notify.run', 'args' => [], 'settings' => CDB_NOLIMIT_SETTINGS]));
        T::same([], cdbProcessProblems($results), 'kein Prozessfehler, keine offene Transaktion');
        $delivered = cdbNtDelivered($db, $subscribers);
        T::same(array_fill_keys($subscribers, 1), $delivered['rows'], 'je Abonnent genau eine ungelesene Zeile');
        T::same(array_fill_keys($subscribers, 6), $delivered['counts'], 'je Abonnent event_count 6');
        T::same(array_fill_keys($subscribers, 1), $delivered['mails'], 'je Abonnent genau eine Mail');
        T::same(0, (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_subscriptions WHERE target_id = ? AND notified_at IS NULL", [$topic]), 'notified_at überall gesetzt');
        T::same(0, (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_notify_queue'), 'Warteschlange leer');
        T::same(6, array_sum(array_map(static fn (array $r): int => (int) ($r['result']['run']['jobs'] ?? 0), $results)), 'jeder Auftrag genau einmal belegt');
    } finally {
        $db->dispose();
    }
});

cdbSection('notify-race-lease', 'notify-race-lease: 4 Prozesse auf 3 Aufträge mit je 300 Abonnenten: Summe 900, je Empfänger 3, 300 Mails, nichts doppelt', static function (): void {
    cdbNtClasses();
    $db = MariaDbTestDatabase::create();
    try {
        $now = cdbNtReal(-60);
        [$author] = cdbNtMembers($db, 1, 'ntla');
        [, $topic] = cdbNtThread($db, $author, cdbNtReal(-3600));
        $subscribers = cdbNtMembers($db, 300, 'ntls');
        cdbNtSubscribe($db, $subscribers, 'topic', $topic, $now);
        cdbNtReplies($db, $topic, cdbNtMembers($db, 3, 'ntlr'), $now);
        $results = cdbRace($db, array_fill(0, 4, ['action' => 'notify.run', 'args' => [], 'settings' => CDB_NOLIMIT_SETTINGS]));
        T::same([], cdbProcessProblems($results), 'kein Prozessfehler');
        $delivered = cdbNtDelivered($db, $subscribers);
        T::same([900, 300], [$delivered['sum'], $delivered['unread']], 'Summe event_count 900 in 300 Zeilen');
        T::same([], array_keys(array_filter($delivered['counts'], static fn (int $n): bool => $n !== 3)), 'je Empfänger 3');
        T::same([300, []], [array_sum($delivered['mails']), array_keys(array_filter($delivered['mails'], static fn (int $n): bool => $n !== 1))], '300 Mails, je Empfänger eine');
        $runs = array_map(static fn (array $r): array => (array) ($r['result']['run'] ?? []), $results);
        T::same([3, 900], [array_sum(array_column($runs, 'jobs')), array_sum(array_column($runs, 'recipients'))], 'kein Auftrag und kein Empfänger doppelt bearbeitet');
        T::same(0, (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_notify_queue'), 'Warteschlange leer');
    } finally {
        $db->dispose();
    }
});

cdbSection('notify-inject', 'notify-inject: Fehler an jeder Anweisung eines Laufs, dann Wiederholung: je Empfänger genau ein Hinweis und höchstens eine Mail; Deadlock auf mail_queue', static function (): void {
    cdbNtClasses();
    $db = MariaDbTestDatabase::create();
    try {
        $time = new \ArrayObject(['now' => time()]);
        $clock = static fn (): int => (int) $time['now'];
        $settings = Settings::fromArray(CDB_NOLIMIT_SETTINGS);
        $dispatcher = cdbNtDispatcher($db, $settings, $clock);
        $scenario = static function () use ($db, $time): array {
            $now = gmdate('Y-m-d H:i:s', (int) $time['now'] - 30);
            [$author] = cdbNtMembers($db, 1, 'ntia' . bin2hex(random_bytes(3)));
            [, $topic] = cdbNtThread($db, $author, $now);
            $subs = cdbNtMembers($db, 3, 'ntis' . bin2hex(random_bytes(3)));
            cdbNtSubscribe($db, array_slice($subs, 0, 2), 'topic', $topic, $now);
            $db->subscription(['user' => $subs[2], 'target' => $topic, 'mode' => 'none', 'created_at' => $now]);
            cdbNtReplies($db, $topic, cdbNtMembers($db, 1, 'ntir' . bin2hex(random_bytes(3))), $now);

            return $subs;
        };
        // Zahl der Anweisungen eines sauberen Laufs
        $subs = $scenario();
        $db->statementCount = 0;
        $dispatcher->runQueue();
        $total = $db->statementCount;
        T::check($total >= 30, "ein Lauf hat {$total} Anweisungen");
        $problems = [];
        $failClosedCount = 0;
        cdbLogs();
        for ($k = 1; $k <= $total; $k++) {
            $subs = $scenario();
            $db->statementCount = 0;
            $db->failAtStatement = $k;
            try {
                $dispatcher->runQueue();
            } catch (\Throwable $e) {
                $problems[] = "#{$k}: Ausnahme nach außen: " . $e->getMessage();
            }
            $db->failAtStatement = null;
            $failedLog = cdbLogs();
            if ($db->getPdo()->inTransaction()) {
                $problems[] = "#{$k}: Transaktion offen";
                $db->getPdo()->rollBack();
            }
            $time['now'] += NotificationDispatcher::LEASE_SECONDS + 1;
            $dispatcher->runQueue();
            $dispatcher->runQueue();
            $delivered = cdbNtDelivered($db, $subs);
            // Eine Störung in can() lehnt ab (fail-closed, Vertrag §1): dieser Empfänger erhält höchstens einen Hinweis, nie zwei.
            $failClosed = str_contains($failedLog, 'Zugriffsprüfung');
            $failClosedCount += $failClosed ? 1 : 0;
            $exact = $delivered['rows'] === array_fill_keys($subs, 1) && $delivered['counts'] === array_fill_keys($subs, 1);
            $atMostOnce = array_filter($delivered['counts'], static fn (int $n): bool => $n !== 1) === [] && count($delivered['counts']) >= count($subs) - 1;
            if (!$exact && !($failClosed && $atMostOnce)) {
                $problems[] = "#{$k}: Hinweise " . json_encode($delivered['counts']);
            }
            if (array_filter($delivered['mails'], static fn (int $n): bool => $n > 1) !== [] || isset($delivered['mails'][$subs[2]])) {
                $problems[] = "#{$k}: Mails " . json_encode($delivered['mails']);
            }
            if ((int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_notify_queue') !== 0) {
                $problems[] = "#{$k}: Auftrag offen";
            }
        }
        T::same([], $problems, "jede der {$total} Anweisungen einmal gestört: genau einmal je Empfänger (Störung in can(): höchstens einmal), keine doppelte Mail");
        T::check($failClosedCount > 0 && $failClosedCount < $total, "davon {$failClosedCount} Störungen in can() (fail-closed)");

        // Deadlock beim Einfügen der Mail: Transaction::run() wiederholt die Empfänger-Transaktion vollständig.
        [, $queue] = cdbNtCore($db);
        $calls = 0;
        $deadlocking = static function (string $to, string $subject, string $body, array $headers) use ($queue, &$calls): bool {
            if ($calls++ === 0) {
                $e = new \PDOException('Deadlock found when trying to get lock (Test)');
                $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
                throw $e;
            }

            return $queue->enqueuePlainStrict($to, $subject, $body, $headers, NotificationDispatcher::MAIL_SOURCE) > 0;
        };
        $subs = $scenario();
        $result = cdbNtDispatcher($db, $settings, $clock, $deadlocking)->runQueue();
        $delivered = cdbNtDelivered($db, $subs);
        T::same([array_fill_keys($subs, 1), [$subs[0] => 1, $subs[1] => 1], 0, 0], [$delivered['counts'], $delivered['mails'], $result['failed'], (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_notify_queue')], 'Deadlock wiederholt: ein Hinweis (event_count 1) und eine Mail je Abonnent, Auftrag erledigt');
        T::same(3, $calls, 'Mail-Closure: ein Deadlock, dann zwei Mails');
    } finally {
        $db->dispose();
    }
});

cdbSection('notify-core-cas', 'notify-core-cas: 6 Prozesse notifyGrouped() ohne Mutex auf dieselbe Gruppe: Summe event_count exakt', static function (): void {
    cdbNtClasses();
    $db = MariaDbTestDatabase::create();
    try {
        [$user] = cdbNtMembers($db, 1, 'ntcc');
        $results = cdbRace($db, array_fill(0, 6, ['action' => 'notify.grouped', 'args' => ['user' => $user, 'group' => 'community:topic:999', 'times' => 20], 'settings' => []]));
        T::same([], cdbProcessProblems($results), 'kein Prozessfehler');
        T::same(120, (int) cdbScalar($db, 'SELECT SUM(event_count) FROM cms_notifications WHERE user_id = ?', [$user]), 'Summe event_count 120');
        $rows = (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_notifications WHERE user_id = ?', [$user]);
        T::check($rows >= 1, "Zeilen ≥ 1 ({$rows})");
        T::same($rows, array_sum(array_map(static fn (array $r): int => (int) ($r['result']['inserted'] ?? 0), $results)), 'jede Zeile entstand per INSERT, jede Erhöhung per CAS');
    } finally {
        $db->dispose();
    }
});

cdbSection('reaction-race', 'reaction-race: 8 Prozesse × 25 Mitglieder auf denselben Beitrag (setzen, wechseln, entfernen): Zähler = Zeilen', static function (): void {
    cdbNtClasses();
    $db = MariaDbTestDatabase::create();
    try {
        [$author] = cdbNtMembers($db, 1, 'ntra');
        [, $topic] = cdbNtThread($db, $author, cdbNtReal(-3600));
        $post = $db->post(['topic' => $topic, 'user' => $author, 'created_at' => cdbNtReal(-60)]);
        $members = cdbNtMembers($db, 200, 'ntrm');
        $jobs = [];
        foreach (array_chunk($members, 25) as $i => $chunk) {
            $jobs[] = ['action' => 'reaction.cycle', 'args' => ['users' => $chunk, 'post' => $post, 'steps' => $i % 2 === 0 ? ['love'] : ['love', 'remove']], 'settings' => CDB_NOLIMIT_SETTINGS];
        }
        $before = cdbDeadlocks();
        $results = cdbRace($db, $jobs);
        T::same([], cdbProcessProblems($results), 'kein Prozessfehler');
        T::same([], array_values(array_filter(array_map(static fn (array $r): array => (array) ($r['result']['errors'] ?? []), $results))), 'kein Fehler der Dienste (Deadlocks nur intern wiederholt)');
        $rows = (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_reactions WHERE post_id = ?', [$post]);
        T::same(100, $rows, '100 Reaktionen bleiben');
        T::same([$rows, $rows, $rows], [
            (int) cdbScalar($db, 'SELECT reaction_count FROM cms_community_posts WHERE id = ?', [$post]),
            (int) cdbScalar($db, 'SELECT reaction_count FROM cms_community_topics WHERE id = ?', [$topic]),
            (int) cdbScalar($db, 'SELECT reactions_received FROM cms_community_user_stats WHERE user_id = ?', [$author]),
        ], 'posts.reaction_count, topics.reaction_count und reactions_received = Zeilen');
        T::same(0, (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_user_stats s WHERE s.user_id IN (' . implode(', ', $members) . ') AND s.reactions_given <> (SELECT COUNT(*) FROM cms_community_reactions r WHERE r.user_id = s.user_id)'), 'reactions_given = eigene Zeilen');
        T::check(cdbDeadlocks() - $before <= 50, 'Deadlocks im Rahmen (' . (cdbDeadlocks() - $before) . ')');
    } finally {
        $db->dispose();
    }
});

cdbSection('reaction-same-user', 'reaction-same-user: 4 Prozesse × 50 set/remove desselben Paares: Zähler ∈ {0, 1} und gleich der Zeile', static function (): void {
    cdbNtClasses();
    $db = MariaDbTestDatabase::create();
    try {
        [$author, $user] = cdbNtMembers($db, 2, 'ntsu');
        [, $topic] = cdbNtThread($db, $author, cdbNtReal(-3600));
        $post = $db->post(['topic' => $topic, 'user' => $author, 'created_at' => cdbNtReal(-60)]);
        $steps = [];
        for ($i = 0; $i < 49; $i++) {
            $steps[] = $i % 2 === 0 ? 'remove' : 'like';
        }
        $results = cdbRace($db, array_fill(0, 4, ['action' => 'reaction.cycle', 'args' => ['users' => [$user], 'post' => $post, 'steps' => $steps], 'settings' => CDB_NOLIMIT_SETTINGS]));
        T::same([], cdbProcessProblems($results), 'kein Prozessfehler');
        $rows = (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_reactions WHERE post_id = ?', [$post]);
        $count = (int) cdbScalar($db, 'SELECT reaction_count FROM cms_community_posts WHERE id = ?', [$post]);
        T::check(in_array($count, [0, 1], true), "reaction_count ∈ {0, 1} ({$count})");
        T::same([$rows, $rows, $rows, $rows], [$count, (int) cdbScalar($db, 'SELECT reaction_count FROM cms_community_topics WHERE id = ?', [$topic]), (int) cdbScalar($db, 'SELECT reactions_received FROM cms_community_user_stats WHERE user_id = ?', [$author]), (int) cdbScalar($db, 'SELECT reactions_given FROM cms_community_user_stats WHERE user_id = ?', [$user])], 'alle Zähler gleich der Zeile');
    } finally {
        $db->dispose();
    }
});

cdbSection('notify-utc', 'notify-utc: Sitzungen +01:00 und +05:30: Zeiten der Abos, der Warteschlange, des Tors und von last_seen_at sind UTC der Uhr', static function (): void {
    cdbNtClasses();
    foreach (['+01:00', '+05:30'] as $zone) {
        $db = MariaDbTestDatabase::create($zone);
        try {
            $clock = static fn (): int => cdbAt();
            $settings = Settings::fromArray(CDB_NOLIMIT_SETTINGS);
            $access = cdbNtAccess($db, $settings, $clock);
            $can = static fn (?int $u, string $a, array $c): AccessDecision => $access->can($u, $a, $c);
            [$author, $sub, $actor] = cdbNtMembers($db, 3, 'ntut' . str_replace([':', '+'], '', $zone));
            [, $topic] = cdbNtThread($db, $author, cdbUtc(-3600));
            $subscriptions = new SubscriptionService($db, $settings, $clock, $can, static function (): void {
            }, static fn (): array => ['allowed' => true, 'retry_after' => 0], static fn (): string => CDB_NT_SECRET, static fn (): string => 'de');
            T::same(true, $subscriptions->subscribe($sub, 'topic', $topic)['success'] ?? false, $zone . ': abonniert');
            $dispatcher = cdbNtDispatcher($db, $settings, $clock);
            $post = $db->post(['topic' => $topic, 'user' => $actor, 'created_at' => cdbUtc(-60)]);
            $dispatcher->onPostCreated($post, $topic, $actor, 'published');
            T::same(cdbUtc(), cdbScalar($db, 'SELECT created_at FROM cms_community_notify_queue WHERE post_id = ?', [$post]), $zone . ': created_at des Auftrags');
            $dispatcher->runQueue();
            T::same([cdbUtc(), cdbUtc()], [cdbScalar($db, 'SELECT created_at FROM cms_community_subscriptions WHERE user_id = ?', [$sub]), cdbScalar($db, 'SELECT notified_at FROM cms_community_subscriptions WHERE user_id = ?', [$sub])], $zone . ': created_at und notified_at des Abos');
            $reads = new ReadStateService($db, $settings, $clock, $can);
            T::same(true, $reads->touchSeen($sub), $zone . ': touchSeen');
            T::same(cdbUtc(), cdbScalar($db, 'SELECT last_seen_at FROM cms_community_user_stats WHERE user_id = ?', [$sub]), $zone . ': last_seen_at');
            T::same(['day' => gmdate('Y-m-d', cdbAt()), 'count' => 1], json_decode((string) cdbScalar($db, 'SELECT settings_json FROM cms_community_user_stats WHERE user_id = ?', [$sub]), true)['mail_quota'] ?? null, $zone . ': Tag des Mail-Kontingents in UTC');
        } finally {
            $db->dispose();
        }
    }
});

cdbSection('explain-interaction', 'explain-interaction: Empfänger, Tor, Ignorieren, Bündelung, Reaktionen, Lesestatus und Stufen der Warteschlange über ihre Indizes', static function (): void {
    cdbNtClasses();
    $db = MariaDbTestDatabase::create();
    try {
        $pdo = $db->getPdo();
        $pdo->exec('CREATE TABLE cdb_nt_digits (d TINYINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO cdb_nt_digits (d) VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)');
        $pdo->exec('CREATE TABLE cdb_nt_numbers (n INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO cdb_nt_numbers (n) SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d FROM cdb_nt_digits a, cdb_nt_digits b, cdb_nt_digits c, cdb_nt_digits e, cdb_nt_digits f');
        // 2.000 Konten (Fremdschlüssel von notifications), 20.000 Abos (8.000 auf Thema 7), 5.000 Ignorieren, 20.000 Hinweise,
        // 20.000 Reaktionen, 10.000 Lesestatus, 2.000 Aufträge
        $pdo->exec("INSERT INTO cms_users (username, email, password, display_name, role, status) SELECT CONCAT('ntx', n), CONCAT('ntx', n, '@example.test'), 'x', 'X', 'member', 'active' FROM cdb_nt_numbers WHERE n < 2000 ORDER BY n");
        $pdo->exec("INSERT INTO cms_community_subscriptions (user_id, target_type, target_id, mode, notified_at, created_at)
            SELECT n + 1, CASE WHEN n MOD 5 = 0 THEN 'forum' ELSE 'topic' END, CASE WHEN n < 8000 THEN 7 ELSE (n MOD 300) + 8 END, CASE WHEN n MOD 7 = 0 THEN 'none' ELSE 'instant' END, NULL, '2026-01-01 00:00:00'
            FROM cdb_nt_numbers WHERE n < 20000");
        $pdo->exec('INSERT INTO cms_community_ignores (user_id, ignored_user_id, created_at) SELECT n + 1, (n MOD 50) + 1, \'2026-01-01 00:00:00\' FROM cdb_nt_numbers WHERE n < 5000');
        $pdo->exec("INSERT INTO cms_notifications (user_id, type, title, message, url, group_key, event_count, is_read, created_at)
            SELECT (n MOD 2000) + 1, 'community.reply', 'T', 'M', '/x', CONCAT('community:topic:', n MOD 400), 1, n MOD 2, '2026-01-01 00:00:00' FROM cdb_nt_numbers WHERE n < 20000");
        $pdo->exec("INSERT INTO cms_community_reactions (post_id, user_id, reaction, created_at) SELECT (n MOD 400) + 1, (n DIV 400) + 1, 'like', '2026-01-01 00:00:00' FROM cdb_nt_numbers WHERE n < 20000");
        $pdo->exec("INSERT INTO cms_community_read_states (user_id, topic_id, last_read_post_id, updated_at) SELECT n + 1, (n MOD 50) + 1, n + 1, '2026-01-01 00:00:00' FROM cdb_nt_numbers WHERE n < 10000");
        $pdo->exec("INSERT INTO cms_community_notify_queue (kind, topic_id, post_id, actor_id, detail, phase, cursor_user_id, attempts, steps, lease_token, locked_until, created_at, updated_at)
            SELECT CASE WHEN n < 1500 THEN 'reaction' WHEN n < 1900 THEN 'reply' WHEN n < 1950 THEN 'new_topic' WHEN n < 1980 THEN 'moderation' ELSE 'report' END,
                   7, n + 1, 3, NULL, 0, 0, 0, 0, NULL, NULL, '2026-01-01 00:00:00', '2026-01-01 00:00:00' FROM cdb_nt_numbers WHERE n < 2000");
        foreach (['cms_community_subscriptions', 'cms_community_ignores', 'cms_notifications', 'cms_community_reactions', 'cms_community_read_states', 'cms_community_notify_queue'] as $table) {
            $pdo->query('ANALYZE TABLE ' . $table)->fetchAll();
        }
        $subs = new SubscriptionRepository($db);
        $reactions = new ReactionRepository($db);
        $reads = new ReadStateRepository($db);
        [$core] = cdbNtCore($db);
        $users = range(1001, 1100);
        $now = '2026-11-20 12:00:00';
        $cases = [
            'Empfänger Thema (Folgeseite)' => [static fn () => $subs->recipients('topic', 7, 'instant', 4000, 100), ['keys' => ['idx_community_subscriptions_target'], 'maxReads' => 220]],
            'Empfänger none' => [static fn () => $subs->recipients('topic', 7, 'none', 0, 100), ['keys' => ['idx_community_subscriptions_target'], 'maxReads' => 220]],
            'Ignorieren' => [static fn () => $subs->ignoring(5, $users), ['keys' => ['idx_community_ignores_ignored', 'PRIMARY'], 'maxReads' => 250]],
            'Bündelung (idx_user_group)' => [static fn () => $core->notifyGrouped(17, 'community.reply', 'community:topic:17', ['title' => 'x']), ['keys' => ['idx_user_group'], 'maxReads' => 30]],
            'Reaktion find' => [static fn () => $reactions->find(7, 3), ['keys' => ['PRIMARY'], 'maxReads' => 5]],
            'Reaktionen mine' => [static fn () => $reactions->mine(3, range(1, 100)), ['keys' => ['PRIMARY', 'idx_community_reactions_user'], 'maxReads' => 250]],
            'Reaktionen forPost' => [static fn () => $reactions->forPost(7, 10, 50), ['keys' => ['PRIMARY'], 'maxReads' => 60]],
            'Lesestatus forUsers' => [static fn () => $reads->forUsers(7, range(1, 300)), ['keys' => ['idx_community_read_states_topic', 'PRIMARY'], 'maxReads' => 700]],
        ];
        foreach (NotificationDispatcher::TIERS as $kinds) {
            $cases['dueIds ' . implode('+', $kinds)] = [static fn () => $subs->dueIds($now, 10, $kinds), ['keys' => ['idx_community_notify_queue_kind'], 'statements' => count($kinds), 'maxReads' => 15 * count($kinds)]];
        }
        foreach ($cases as $label => [$call, $rule]) {
            T::same([], cdbPlanProblems(cdbMeasure($db, $call), $rule), $label);
        }
        // Tor (claimMail) über PRIMARY: EXPLAIN der UPDATE-Anweisung mit den echten Parametern.
        $db->captureParams = true;
        $db->captured = [];
        $row = $pdo->query('SELECT user_id, target_type, target_id FROM cms_community_subscriptions WHERE user_id = 9002')->fetch(\PDO::FETCH_ASSOC);
        $subs->claimMail((int) $row['user_id'], (string) $row['target_type'], (int) $row['target_id'], null, $now);
        $db->captureParams = false;
        $statement = $db->captured[0] ?? ['sql' => '', 'params' => []];
        $explain = $pdo->prepare('EXPLAIN ' . $statement['sql']);
        cdbBind($explain, $statement['params']);
        $explain->execute();
        T::same('PRIMARY', $explain->fetch(\PDO::FETCH_ASSOC)['key'] ?? null, 'Tor: claimMail über PRIMARY');
    } finally {
        $db->dispose();
    }
});
