<?php
/**
 * Release-Prüfung 4.1.0, Performance-Befunde Cluster „batch“ (batch:0, batch:1, batch:2, batch:3, batch:4, batch:8, batch:9).
 *
 * Eingebunden von TESTS/release-4.1.0/run.php (ABSPATH, CMS-Autoloader und $src sind dort definiert).
 * Ohne MySQL: SQLite-Attrappen von CMS\Database für Sortierung, Revisionen und Webhooks, sonst reine Funktionen
 * und Quelltext-Verträge.
 */

declare(strict_types=1);

use CMS\Database;

/**
 * SQLite-Attrappe für CMS\Database (nur die Methoden, die die getesteten Dienste nutzen).
 */
$perfBatchSqliteDb = static function (array $schema): Database {
    return new class ($schema) extends Database {
        public PDO $sqlite;

        public function __construct(array $schema)
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach ($schema as $sql) {
                $this->sqlite->exec($sql);
            }
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute(array_values($params));

            return $stmt;
        }

        public function get_var(string $query, array $params = []): mixed
        {
            $value = $this->execute($query, $params)->fetchColumn();

            return $value === false ? null : $value;
        }

        public function get_results(string $query, array $params = []): array
        {
            return $this->execute($query, $params)->fetchAll(PDO::FETCH_OBJ);
        }

        public function get_row(string $query, array $params = []): ?object
        {
            return $this->execute($query, $params)->fetch(PDO::FETCH_OBJ) ?: null;
        }

        public function getPrefix(): string
        {
            return 'cms_';
        }
    };
};

/** Objekt ohne Konstruktor anlegen und private Eigenschaften im Klassenkontext setzen. */
$perfBatchInstance = static function (string $class, array $properties): object {
    $object = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    \Closure::bind(function () use ($properties): void {
        foreach ($properties as $name => $value) {
            $this->{$name} = $value;
        }
    }, $object, $class)();

    return $object;
};

/** Private Methode im Klassenkontext aufrufen. */
$perfBatchCall = static function (object $object, string $method, array $args = []): mixed {
    return \Closure::bind(fn () => $this->{$method}(...$args), $object, get_class($object))();
};

T::run('PERF batch:0: Aufbewahrung für Mail-Queue und Mail-Protokoll', static function () use ($src): void {
    T::same(30, \CMS\Services\MailQueueService::RETENTION_DAYS, 'Mail-Queue: Standardfrist 30 Tage');
    T::same(['mail_queue' => 30, 'mail_log' => 90], \CMS\Services\Retention\MailRetention::normalize([]), 'Standardfristen 30 und 90 Tage');
    T::same(['mail_queue' => 0, 'mail_log' => 7], \CMS\Services\Retention\MailRetention::normalize(['mail_queue' => 0, 'mail_log' => 2]), '0 = aus, sonst mindestens 7 Tage');
    T::same(['mail_queue' => 3650, 'mail_log' => 90], \CMS\Services\Retention\MailRetention::normalize(['mail_queue' => 99999, 'mail_log' => 'x']), 'Obergrenze 3650, ungültig = Standard');

    $rules = \CMS\Services\Retention\MailRetention::rules();
    $keys = array_map(static fn (\CMS\Services\Retention\RetentionRule $rule): string => $rule->key, $rules);
    T::same(['mail_queue', 'mail_log'], $keys, 'zwei Regeln mail_queue und mail_log');
    T::same([30, 90], array_map(static fn ($rule): int => $rule->days(), $rules), 'Regeln liefern die Standardfristen');
    foreach ($keys as $key) {
        T::check(!isset(\CMS\Services\DataRetentionService::DEFAULT_RETENTION_DAYS[$key]), "{$key}: kollidiert nicht mit Core-Regeln");
    }

    $queue = $src('core/Services/MailQueueService.php');
    T::check(str_contains($queue, "PRUNABLE_WHERE = \"status IN ('sent', 'failed') AND created_at < (NOW() - INTERVAL ? DAY)\""), 'nur versendete/fehlgeschlagene Jobs, über indiziertes created_at');
    T::check(str_contains($queue, "deleteInBatches('mail_queue', self::PRUNABLE_WHERE, [\$retentionDays])"), 'Mail-Queue wird blockweise gelöscht');
    $retention = $src('core/Services/Retention/MailRetention.php');
    T::check(str_contains($retention, "deleteInBatches(\n                    'mail_log',"), 'Mail-Protokoll wird blockweise gelöscht');
    $hooks = $src('core/Services/FeatureHooks/SystemFeatureHooks.php');
    T::check(str_contains($hooks, 'foreach (MailRetention::rules() as $rule) {') && str_contains($hooks, 'DataRetentionService::registerRule($rule);'), 'Regeln werden von SystemFeatureHooks registriert');
});

T::run('PERF batch:1: Transaktionsmails vor Massenversand', static function () use ($src, $perfBatchSqliteDb, $perfBatchInstance, $perfBatchCall): void {
    T::same(['newsletter', 'cms-feed-digest', 'community'], \CMS\Services\MailQueueService::BULK_SOURCES, 'Newsletter, Feed-Digest und (ab 4.2.0) Community-Benachrichtigungen gelten als Massenversand');
    T::check(str_contains($src('core/Services/Newsletter/NewsletterMailer.php'), "MAIL_SOURCE = 'newsletter';"), 'Quelle des Newsletters stimmt');
    T::check(str_contains($src('core/Services/Feed/DigestMailer.php'), "MAIL_SOURCE = 'cms-feed-digest';"), 'Quelle des Feed-Digests stimmt');
    T::check(str_contains($src('core/Services/Community/Service/NotificationDispatcher.php'), "MAIL_SOURCE = 'community';"), 'Quelle der Community-Benachrichtigungen stimmt (Vertrag DESIGN-2b §3.8)');

    $db = $perfBatchSqliteDb(["CREATE TABLE cms_mail_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, status TEXT, available_at TEXT, created_at TEXT, recipient TEXT)"]);
    $rows = [
        ['newsletter', '2020-01-01 08:00:00'],
        ['newsletter', '2020-01-01 08:00:01'],
        ['cms-feed-digest', '2020-01-01 08:00:02'],
        ['cms_mail_helper', '2020-01-01 09:00:00'],
        ['forms', '2020-01-01 09:30:00'],
        ['newsletter', '2020-01-01 07:00:00'],
        ['community', '2020-01-01 09:45:00'],
    ];
    foreach ($rows as [$source, $at]) {
        $db->execute("INSERT INTO cms_mail_queue (source, status, available_at, created_at) VALUES (?, 'pending', ?, ?)", [$source, $at, $at]);
    }
    $db->execute("INSERT INTO cms_mail_queue (source, status, available_at, created_at) VALUES ('cms_mail_helper', 'pending', '2099-01-01 00:00:00', '2026-11-15 06:00:00')");
    $queue = $perfBatchInstance(\CMS\Services\MailQueueService::class, ['db' => $db, 'prefix' => 'cms_']);
    $jobs = $perfBatchCall($queue, 'getDueJobs', [3]);
    T::same(['cms_mail_helper', 'forms', 'newsletter'], array_map(static fn (object $job): string => (string) $job->source, $jobs), 'Passwort-Reset (cms_mail_helper) und Formulare vor dem Newsletter');
    T::same(6, (int) ($jobs[2]->id ?? 0), 'innerhalb des Massenversands weiter FIFO (ältester zuerst)');
    T::same(7, count($perfBatchCall($queue, 'getDueJobs', [100])), 'nicht fällige Jobs bleiben außen vor');
    T::same(['cms_mail_helper', 'forms', 'newsletter', 'newsletter', 'newsletter', 'cms-feed-digest', 'community'], array_map(static fn (object $job): string => (string) $job->source, $perfBatchCall($queue, 'getDueJobs', [100])), 'Community-Mails wie Massenversand hinter den Transaktionsmails, darin FIFO');
});

T::run('PERF batch:2: Zeitbudget und Zeitstempel des Web-Crons', static function () use ($src): void {
    T::same(300, \CMS\Services\CronRunnerService::resolveWebTimeLimit(30), 'Standard 30 s wird auf 300 s angehoben');
    T::same(600, \CMS\Services\CronRunnerService::resolveWebTimeLimit(600), 'höheres Server-Limit bleibt');
    T::same(0, \CMS\Services\CronRunnerService::resolveWebTimeLimit(0), 'unbegrenzt bleibt unbegrenzt');
    T::check(\CMS\Services\CronRunnerService::remainingSeconds() === null, 'ohne Web-Lauf kein Budget (CLI)');

    $code = $src('core/Services/CronRunnerService.php');
    T::check(str_contains($code, '@ignore_user_abort(true);') && str_contains($code, '@set_time_limit($limit);'), 'Web-Cron setzt ignore_user_abort und set_time_limit');
    T::check(str_contains($code, 'if (PHP_SAPI === \'cli\') {'), 'CLI bleibt unverändert');
    foreach (['hourly' => 'cms_cron_hourly', 'daily' => 'cms_cron_daily'] as $kind => $hook) {
        $stamp = strpos($code, "\$settings->set('cron', '{$kind}_last_run', \$executedAt, false, 0);");
        $action = strpos($code, "Hooks::doAction('{$hook}');");
        T::check($stamp !== false && $action !== false && $stamp < $action, "{$kind}_last_run wird vor den Hooks gesetzt");
        T::same(1, substr_count($code, "'{$kind}_last_run', \$executedAt"), "{$kind}_last_run genau einmal gesetzt");
    }
    T::same(3, substr_count($code, '$this->extendTimeLimitForWeb();'), 'Budget bei Start, vor stündlichen und vor täglichen Hooks');
});

T::run('PERF batch:3: Datenbank-Backup ungepuffert gestreamt', static function () use ($src, $perfBatchSqliteDb, $perfBatchInstance, $perfBatchCall): void {
    $code = $src('core/Services/BackupService.php');
    $start = (int) strpos($code, 'private function writeDatabaseDumpToWriter(callable $writer): void');
    $body = substr($code, $start, (int) strpos($code, 'private function disableBufferedQueries(): \Closure', $start) - $start);
    $generated = strpos($body, '$generatedColumns = array_flip($this->getGeneratedColumns($table));');
    $unbuffered = strpos($body, '$restoreBuffering = $this->disableBufferedQueries();');
    $select = strpos($body, '$dataStmt = $this->db->query("SELECT * FROM {$quotedTable}");');
    T::check($generated !== false && $unbuffered !== false && $select !== false && $generated < $unbuffered && $unbuffered < $select, 'generierte Spalten vor der ungepufferten Datenabfrage ermittelt');
    T::check(str_contains($body, '$dataStmt->closeCursor();') && str_contains($body, '$restoreBuffering();') && strpos($body, '} finally {') !== false, 'Cursor und Pufferung im finally zurückgesetzt');
    T::check(str_contains($body, 'INSERT INTO {$quotedTable} ({$columns}) VALUES ('), 'Dump-Format (ein INSERT je Zeile) unverändert');
    T::check(str_contains($code, "\\CMS\\Database::mysqlAttribute('ATTR_USE_BUFFERED_QUERY')") && str_contains($code, "getAttribute(\\PDO::ATTR_DRIVER_NAME) !== 'mysql'"), 'nur auf MySQL/MariaDB umgeschaltet');

    $db = $perfBatchSqliteDb([]);
    $backup = $perfBatchInstance(\CMS\Services\BackupService::class, ['db' => $db]);
    $restore = $perfBatchCall($backup, 'disableBufferedQueries');
    T::check($restore instanceof \Closure, 'SQLite: neutrale Wiederherstellung');
    $restore();
});

T::run('PERF batch:4: getrennte Cron-Sperren und Webhook-Budget', static function () use ($src, $perfBatchSqliteDb): void {
    $code = $src('core/Services/CronRunnerService.php');
    T::check(str_contains($code, "RUN_LOCK_FILE = '.cron-run.lock';") && str_contains($code, "MAIL_LOCK_FILE = '.cron-mail.lock';"), 'zwei Sperrdateien');
    T::check(str_contains($code, 'return $this->runMailQueueOnly($task, $limit, $force, $mode, $source, $result);'), 'belegte Lauf-Sperre: Mail-Queue wird trotzdem verarbeitet');
    T::check(str_contains($code, "&& !str_starts_with(\$mode, 'admin')"), 'Admin-Direktlauf meldet weiter „bereits aktiv“');
    T::same(2, substr_count($code, '$this->processMailQueueLocked($queue, $limit, $force)'), 'beide Mail-Queue-Läufe unter der Mail-Sperre');
    $mailOnly = substr($code, (int) strpos($code, 'private function runMailQueueOnly('), 2200);
    T::check(!str_contains($mailOnly, "doAction('cms_cron_hourly')") && !str_contains($mailOnly, 'runDailyHooks'), 'Mail-only-Lauf startet keine stündlichen/täglichen Hooks');

    $db = $perfBatchSqliteDb([
        'CREATE TABLE cms_webhook_endpoints (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, url TEXT, events_json TEXT, secret_encrypted TEXT,
            is_active INTEGER DEFAULT 1, consecutive_failures INTEGER DEFAULT 0, disabled_reason TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)',
        "CREATE TABLE cms_webhook_deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT, endpoint_id INTEGER, delivery_uuid TEXT UNIQUE, event TEXT,
            payload_json TEXT, status TEXT DEFAULT 'pending', attempts INTEGER DEFAULT 0, next_attempt_at TEXT, response_status INTEGER, last_error TEXT,
            created_at TEXT, delivered_at TEXT)",
    ]);
    $calls = new \ArrayObject();
    $service = new \CMS\Services\Webhooks\WebhookService(
        $db,
        static fn (string $plain, int $id): string => $plain,
        static fn (string $value, int $id): string => $value,
        static function (string $url, array $headers, string $body) use ($calls): array {
            $calls[] = $url;
            $status = str_contains($url, 'gone') ? 410 : 200;

            return ['success' => $status === 200, 'status' => $status];
        },
        static fn (): int => strtotime('2026-11-15 10:00:00'),
        static fn (): string => bin2hex(random_bytes(8))
    );
    $db->execute("INSERT INTO cms_webhook_endpoints (id, name, url, events_json, secret_encrypted, is_active) VALUES (1, 'Weg', 'https://gone.example.com/h', '[]', 'geheim', 1), (2, 'Ok', 'https://ok.example.com/h', '[]', 'geheim', 1)");
    foreach ([1, 1, 2, 1] as $i => $endpointId) {
        $db->execute("INSERT INTO cms_webhook_deliveries (endpoint_id, delivery_uuid, event, payload_json, status, next_attempt_at, created_at) VALUES (?, ?, 'user.registered', '{}', 'pending', '2026-11-15 09:00:0{$i}', '2026-11-15 09:00:00')", [$endpointId, 'u' . $i]);
    }

    T::same(['delivered' => 0, 'failed' => 0, 'dead' => 0], $service->deliverDue(50, 0.0), 'Budget 0: keine Zustellung, alles bleibt fällig');
    T::same(0, count($calls), 'Budget 0: kein HTTP-Aufruf');
    T::same(['delivered' => 1, 'failed' => 0, 'dead' => 1], $service->deliverDue(), '410 deaktiviert den Empfänger, seine weiteren Zustellungen werden übersprungen');
    T::same(['https://gone.example.com/h', 'https://ok.example.com/h'], $calls->getArrayCopy(), 'nur ein Versuch an den deaktivierten Empfänger');
    T::same(2, (int) $db->get_var("SELECT COUNT(*) FROM cms_webhook_deliveries WHERE endpoint_id = 1 AND attempts = 0 AND status <> 'pending'"), 'übrige Zustellungen des Empfängers: aufgegeben (FUN-410-23), nicht erneut gesendet');
    T::same(20.0, \CMS\Services\Webhooks\WebhookService::DELIVER_TIME_BUDGET_SECONDS, 'Standardbudget 20 s je Lauf');
});

T::run('PERF batch:8: Aufbewahrung mit Zeitbudget statt fester 200 000 Zeilen', static function () use ($perfBatchInstance): void {
    $continue = [\CMS\Services\DataRetentionService::class, 'shouldContinueBatches'];
    T::check($continue(1, 999.0), 'die ersten 40 Blöcke laufen immer (wie bisher)');
    T::check($continue(39, 999.0), 'Block 39 von 40 garantiert');
    T::check($continue(40, 5.0), 'nach 40 Blöcken weiter, solange das Budget reicht');
    T::check(!$continue(40, 20.0), 'Budget (20 s) verbraucht: Schluss');
    T::check(!$continue(2000, 0.0), 'harte Obergrenze 2000 Blöcke');

    // Attrappe: jede DELETE-Anweisung meldet einen vollen Block, bis 100 Blöcke erreicht sind.
    $statement = static fn (int $rows): \PDOStatement => new class ($rows) extends \PDOStatement {
        public function __construct(private int $rows)
        {
        }

        public function rowCount(): int
        {
            return $this->rows;
        }
    };
    $db = new class ($statement) extends Database {
        /** @var list<array{0:string,1:array}> */
        public array $log = [];

        public function __construct(private \Closure $statement)
        {
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $this->log[] = [$sql, $params];

            return ($this->statement)(count($this->log) < 100 ? \CMS\Services\DataRetentionService::DELETE_BATCH_SIZE : 17);
        }
    };
    $service = $perfBatchInstance(\CMS\Services\DataRetentionService::class, ['db' => $db, 'prefix' => 'cms_']);
    T::same(99 * 5000 + 17, $service->deleteInBatches('page_views', 'visited_at < ?', ['x']), 'Rückstand über 200 000 Zeilen wird innerhalb des Budgets aufgeholt');
    T::same(100, count($db->log), 'bis zum ersten unvollständigen Block');
    T::same(['DELETE FROM cms_page_views WHERE visited_at < ? LIMIT 5000', ['x']], $db->log[0], 'Blöcke zu 5000 Zeilen mit Platzhaltern');
    $db->log = [];
    T::same(40 * 5000, $service->deleteInBatches('page_views', 'visited_at < ?', ['x'], 0.0), 'ohne Budget: wie bisher höchstens 40 Blöcke');
    $threw = false;
    try {
        $service->deleteInBatches('page_views; DROP', '1 = 1');
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    T::check($threw, 'ungültiger Tabellenname wird abgelehnt');
});

T::run('PERF batch:9: Seitenrevisionen auf 30 je Seite begrenzt', static function () use ($perfBatchSqliteDb, $perfBatchInstance, $perfBatchCall, $src): void {
    T::same(\CMS\Services\PostRevisionService::MAX_REVISIONS_PER_POST, \CMS\PageManager::MAX_REVISIONS_PER_PAGE, 'gleiche Grenze wie bei Beiträgen');
    $db = $perfBatchSqliteDb(['CREATE TABLE cms_page_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, title TEXT)']);
    for ($i = 1; $i <= 35; $i++) {
        $db->execute('INSERT INTO cms_page_revisions (page_id, title) VALUES (?, ?)', [7, 'r' . $i]);
    }
    for ($i = 1; $i <= 3; $i++) {
        $db->execute('INSERT INTO cms_page_revisions (page_id, title) VALUES (?, ?)', [8, 'x' . $i]);
    }
    $manager = $perfBatchInstance(\CMS\PageManager::class, ['db' => $db, 'prefix' => 'cms_']);
    $perfBatchCall($manager, 'pruneRevisions', [7]);
    T::same(30, (int) $db->get_var('SELECT COUNT(*) FROM cms_page_revisions WHERE page_id = 7'), '35 Revisionen: 30 bleiben');
    T::same('r6', (string) $db->get_var('SELECT title FROM cms_page_revisions WHERE page_id = 7 ORDER BY id ASC LIMIT 1'), 'die ältesten fünf wurden entfernt');
    T::same(3, (int) $db->get_var('SELECT COUNT(*) FROM cms_page_revisions WHERE page_id = 8'), 'andere Seiten unberührt');
    $perfBatchCall($manager, 'pruneRevisions', [8]);
    T::same(3, (int) $db->get_var('SELECT COUNT(*) FROM cms_page_revisions WHERE page_id = 8'), 'unter der Grenze wird nichts gelöscht');

    $code = $src('core/PageManager.php');
    $snapshot = substr($code, (int) strpos($code, 'private function storeRevisionSnapshot(array $page): bool'), 3000);
    T::check(str_contains($snapshot, "if (!\$stmt->execute(\$params)) {\n            return false;\n        }\n\n        \$this->pruneRevisions(\$pageId);"), 'nach jedem gespeicherten Snapshot wird bereinigt');
});
