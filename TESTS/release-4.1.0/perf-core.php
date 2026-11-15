<?php
/**
 * Release-Prüfung 4.1.0, Performance-Befunde Cluster „core“ (db:3, db:4, db:5, db:7, db:8, db:9, batch:10).
 *
 * Eingebunden von TESTS/release-4.1.0/run.php (ABSPATH, CMS-Autoloader und $src sind dort definiert).
 * Ohne Datenbank: Attrappen von CMS\Database zählen Abfragen, der Rest sind Quelltext-Verträge.
 */

declare(strict_types=1);

use CMS\Database;

/**
 * Attrappe für Database: protokolliert SQL und liefert vorbereitete Antworten.
 *
 * @param \Closure(string, array): mixed $answer
 */
$perfCoreFakeDb = static function (\Closure $answer): Database {
    return new class ($answer) extends Database {
        /** @var list<string> */
        public array $log = [];

        public function __construct(private \Closure $answer)
        {
        }

        public function get_var(string $query, array $params = []): mixed
        {
            $this->log[] = $query;

            return ($this->answer)($query, $params);
        }

        public function get_results(string $query, array $params = []): array
        {
            $this->log[] = $query;
            $rows = ($this->answer)($query, $params);

            return is_array($rows) ? $rows : [];
        }
    };
};

T::run('PERF db:3: Request-Cache für tableExists()/columnExists()', static function () use ($perfCoreFakeDb): void {
    $existing = ['cms_forms' => true];
    $db = $perfCoreFakeDb(static function (string $sql, array $params) use (&$existing): int {
        if (str_contains($sql, 'information_schema.COLUMNS')) {
            return ($params[0] ?? '') === 'cms_forms' && ($params[1] ?? '') === 'title' ? 1 : 0;
        }

        return isset($existing[$params[0] ?? '']) ? 1 : 0;
    });
    $noteWrite = new \ReflectionMethod(Database::class, 'noteWrite');

    T::check($db->tableExists('cms_forms') && $db->tableExists('cms_forms'), 'vorhandene Tabelle wird erkannt');
    T::same(1, count($db->log), 'zweite Prüfung derselben Tabelle ohne information_schema-Abfrage');
    T::check($db->columnExists('cms_forms', 'title') && $db->columnExists('cms_forms', 'TITLE'), 'vorhandene Spalte wird erkannt');
    T::same(2, count($db->log), 'Spaltenprüfung wird ebenfalls gemerkt');

    T::check(!$db->tableExists('cms_neu') && !$db->tableExists('cms_neu'), 'fehlende Tabelle bleibt fehlend');
    T::same(4, count($db->log), 'negative Ergebnisse werden nicht gemerkt (Anlage über rohes PDO bleibt sichtbar)');
    $existing['cms_neu'] = true;
    T::check($db->tableExists('cms_neu'), 'nachträglich angelegte Tabelle wird ohne Cache-Leerung erkannt');

    $before = count($db->log);
    $noteWrite->invoke($db, "SELECT 1");
    $noteWrite->invoke($db, "INSERT INTO cms_forms (title) VALUES ('x')");
    $db->tableExists('cms_forms');
    T::same($before, count($db->log), 'SELECT/INSERT leeren den Schema-Cache nicht');

    foreach (['DROP TABLE cms_forms', "  alter table cms_forms ADD COLUMN x INT", 'CREATE TABLE IF NOT EXISTS cms_x (id INT)', 'RENAME TABLE a TO b'] as $ddl) {
        $before = count($db->log);
        $noteWrite->invoke($db, $ddl);
        $db->tableExists('cms_forms');
        T::same($before + 1, count($db->log), 'Cache nach DDL verworfen: ' . trim(substr($ddl, 0, 12)));
    }

    $before = count($db->log);
    $db->flushSchemaCache();
    $db->columnExists('cms_forms', 'title');
    T::same($before + 1, count($db->log), 'flushSchemaCache() verwirft den Cache');
});

T::run('PERF db:3: Wartende Inhalte ohne Abfrage je Inhalt', static function (): void {
    if (!extension_loaded('pdo_sqlite')) {
        T::check(true, 'pdo_sqlite fehlt, nur Quelltext geprüft');

        return;
    }

    $db = new class () extends Database {
        public PDO $sqlite;
        public int $eventQueries = 0;

        public function __construct()
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec("CREATE TABLE cms_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT)");
            foreach (['posts', 'pages'] as $table) {
                $this->sqlite->exec("CREATE TABLE cms_{$table} (id INTEGER PRIMARY KEY, title TEXT, status TEXT, author_id INTEGER, updated_at TEXT)");
            }
            $this->sqlite->exec('CREATE TABLE cms_content_workflow_events (id INTEGER PRIMARY KEY AUTOINCREMENT, content_type TEXT, content_id INTEGER,
                action TEXT, user_id INTEGER, created_at TEXT)');
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            if (str_contains($sql, 'content_workflow_events')) {
                $this->eventQueries++;
            }
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute(array_values($params));

            return $stmt;
        }

        public function get_row(string $query, array $params = []): ?object
        {
            $row = $this->execute($query, $params)->fetch(PDO::FETCH_OBJ);

            return $row === false ? null : $row;
        }

        public function get_results(string $query, array $params = []): array
        {
            return $this->execute($query, $params)->fetchAll(PDO::FETCH_OBJ);
        }
    };
    $db->sqlite->exec("INSERT INTO cms_users (id, username, display_name) VALUES (1, 'anna', 'Anna'), (2, 'ben', 'Ben'), (3, 'carla', '')");
    $posts = [];
    for ($id = 1; $id <= 25; $id++) {
        $posts[] = "({$id}, 'Beitrag {$id}', 'pending', " . (($id % 3) + 1) . ", '2026-11-01 08:" . sprintf('%02d', $id) . ":00')";
    }
    $db->sqlite->exec('INSERT INTO cms_posts (id, title, status, author_id, updated_at) VALUES ' . implode(',', $posts));
    $db->sqlite->exec("INSERT INTO cms_posts (id, title, status, author_id, updated_at) VALUES (99, 'Entwurf', 'draft', 1, '2026-11-01 07:00:00')");
    $db->sqlite->exec("INSERT INTO cms_pages (id, title, status, author_id, updated_at) VALUES (5, 'Seite', 'pending', 2, '2026-11-02 09:00:00')");
    // Beitrag 1: zwei Einreichungen, die neuere zählt; Beitrag 2: gleicher Zeitpunkt, höhere ID zählt; Beitrag 3: nie eingereicht.
    $db->sqlite->exec("INSERT INTO cms_content_workflow_events (content_type, content_id, action, user_id, created_at) VALUES
        ('post', 1, 'submitted', 2, '2026-11-03 10:00:00'),
        ('post', 1, 'submitted', 3, '2026-11-04 10:00:00'),
        ('post', 1, 'comment', 1, '2026-11-05 10:00:00'),
        ('post', 2, 'submitted', 1, '2026-11-03 11:00:00'),
        ('post', 2, 'submitted', 3, '2026-11-03 11:00:00'),
        ('page', 1, 'submitted', 1, '2026-11-06 10:00:00'),
        ('page', 5, 'submitted', 2, '2026-11-02 09:30:00')");
    for ($id = 4; $id <= 25; $id++) {
        $db->sqlite->exec("INSERT INTO cms_content_workflow_events (content_type, content_id, action, user_id, created_at) VALUES ('post', {$id}, 'submitted', 1, '2026-11-02 10:" . sprintf('%02d', $id) . ":00')");
    }

    $workflow = new \CMS\Services\Content\ContentWorkflow($db, static fn (): bool => false, static fn (): bool => true, static fn (): int => time(), static function (): void {
    });

    $db->eventQueries = 0;
    $items = $workflow->pendingItems(500);
    T::same(26, count($items), '25 wartende Beiträge und eine wartende Seite');
    T::same(2, $db->eventQueries, 'eine Ereignis-Abfrage je Inhaltstyp statt je Inhalt');

    $byKey = [];
    foreach ($items as $item) {
        $byKey[$item['type'] . ':' . $item['id']] = $item;
    }
    T::same(3, $byKey['post:1']['submitted_by'] ?? null, 'neueste Einreichung zählt');
    T::same('2026-11-04 10:00:00', $byKey['post:1']['submitted_at'] ?? null, 'Zeitpunkt der neuesten Einreichung');
    T::same(3, $byKey['post:2']['submitted_by'] ?? null, 'Gleichstand: höhere Ereignis-ID zählt (wie lastSubmission)');
    T::same(0, $byKey['post:3']['submitted_by'] ?? null, 'nie eingereicht: 0');
    T::same('2026-11-01 08:03:00', $byKey['post:3']['submitted_at'] ?? null, 'nie eingereicht: Rückfall auf updated_at');
    T::same(2, $byKey['page:5']['submitted_by'] ?? null, 'Seiten-Einreichung nicht mit Beitrag gleicher ID verwechselt');
    T::same($workflow->lastSubmitterId('post', 1), $byKey['post:1']['submitted_by'] ?? null, 'gleiches Ergebnis wie lastSubmitterId()');
    T::same(26, $workflow->pendingCount(), 'pendingCount() zählt alle wartenden Inhalte');
});

T::run('PERF db:4/batch:10: Feature-Telemetrie ohne DDL je Request, mit Aufbewahrung', static function () use ($src): void {
    $code = $src('core/Services/FeatureUsageService.php');
    $constructor = substr($code, (int) strpos($code, 'private function __construct()'), 700);
    T::check(str_contains($constructor, "runSchemaOnce('feature_usage', __FILE__"), 'CREATE TABLE läuft über Database::runSchemaOnce()');
    T::check(preg_match('/^\s*\$this->createUsageTable\(\);\s*$/m', explode('runSchemaOnce', $constructor)[0]) !== 1, 'kein direkter createUsageTable()-Aufruf vor runSchemaOnce');
    T::check(str_contains($constructor, 'if (!$ran) {') && str_contains($constructor, '$this->tableReady = true;'), 'vorhandenes Schema-Flag gilt als Tabelle bereit');
    T::check(str_contains($code, 'if ($this->isMissingTableError($e)) {'), 'fehlende Tabelle trotz Flag wird beim Schreibfehler neu angelegt');

    $rule = \CMS\Services\FeatureUsageService::retentionRule();
    T::same('feature_usage', $rule->key, 'Regel-Schlüssel feature_usage');
    T::same(180, $rule->days(), 'Standardfrist 180 Tage');
    T::check(!isset(\CMS\Services\DataRetentionService::DEFAULT_RETENTION_DAYS[$rule->key]), 'Schlüssel kollidiert nicht mit Core-Regeln');
    T::check(str_contains($code, 'WHERE last_used_at < ? LIMIT {$block}'), 'Löschen blockweise über idx_last_used_at');
    T::check(str_contains($code, 'public static function registerRetentionRule(): void'), 'Registrierung für einen cms_init-Hook vorhanden');
});

T::run('PERF db:5: Diagnose-GET ohne COUNT(*) und CHECK TABLE', static function () use ($src): void {
    $code = $src('core/Services/SystemService.php');
    $start = (int) strpos($code, 'public function checkDatabaseTables(bool $deep = false): array {');
    T::check($start > 0, 'checkDatabaseTables() hat den Parameter $deep (Standard false)');
    $body = substr($code, $start, (int) strpos($code, 'return $table_status;', $start) - $start);
    T::check(str_contains($body, 'TABLE_ROWS AS table_rows, DATA_LENGTH + INDEX_LENGTH AS size') && str_contains($body, 'TABLE_NAME IN ('), 'eine information_schema-Abfrage für alle Tabellen');
    T::check(!str_contains($body, 'tableExists('), 'keine Existenzprüfung je Tabelle');
    $deepPos = strpos($body, 'if ($deep) {');
    T::check($deepPos !== false && strpos($body, '"CHECK TABLE') > $deepPos && strpos($body, '"SELECT COUNT(*)') > $deepPos, 'COUNT(*) und CHECK TABLE nur mit $deep');
    T::same(1, substr_count($body, '"CHECK TABLE'), 'genau ein CHECK TABLE (im $deep-Zweig)');
    T::check(str_contains($code, "'table_status' => \$this->checkDatabaseTables(true),"), 'runSystemCheck() prüft weiterhin vollständig');

    $view = $src('admin/views/system/diagnose.php');
    T::check(str_contains($view, 'Nicht geprüft') && str_contains($view, "rows_approximate"), 'Ansicht kennzeichnet Schätzwerte und ungeprüfte Tabellen');
    T::check(str_contains($view, '<input type="hidden" name="action" value="repair_tables">'), 'ausdrückliche Prüfung über „Tabellen reparieren“ bleibt');
});

T::run('PERF db:7: SettingsService::getGroup() mit Request-Cache', static function () use ($perfCoreFakeDb): void {
    $settingsClass = \CMS\Services\SettingsService::class;
    $reflection = new \ReflectionClass($settingsClass);
    $instanceProperty = $reflection->getProperty('instance');
    $previous = $instanceProperty->getValue();

    $value = 'eins';
    $db = $perfCoreFakeDb(static function (string $sql, array $params) use (&$value): array {
        return [(object) ['option_name' => 'feeds.enabled', 'option_value' => $value], (object) ['option_name' => 'feeds.limit', 'option_value' => 'json:20']];
    });
    $service = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('db')->setValue($service, $db);
    $reflection->getProperty('prefix')->setValue($service, 'cms_');

    try {
        $instanceProperty->setValue(null, $service);
        T::same(['enabled' => 'eins', 'limit' => 20], $service->getGroup('feeds'), 'Gruppe wird gelesen und dekodiert');
        $service->getGroup('feeds');
        $service->getGroup('FEEDS');
        T::same(1, count($db->log), 'wiederholter Aufruf ohne weitere LIKE-Abfrage');
        $service->getGroup('security_mfa');
        T::same(2, count($db->log), 'andere Gruppe wird getrennt geladen');

        $value = 'zwei';
        $noteWrite = new \ReflectionMethod(Database::class, 'noteWrite');
        $noteWrite->invoke($db, "UPDATE cms_settings SET option_value = 'zwei' WHERE option_name = 'feeds.enabled'");
        T::same('zwei', $service->getGroup('feeds')['enabled'] ?? null, 'Schreibzugriff auf settings über Database verwirft den Cache');
        $noteWrite->invoke($db, "UPDATE cms_settings_archive SET x = 1");
        $service->getGroup('feeds');
        T::same(3, count($db->log), 'andere Tabelle mit ähnlichem Namen verwirft nichts');

        \CMS\Services\SettingsService::flushGroupCache();
        $service->getGroup('feeds');
        T::same(4, count($db->log), 'flushGroupCache() verwirft den Cache');
    } finally {
        $instanceProperty->setValue(null, $previous);
    }
});

T::run('PERF db:8: Kategorie-/Schlagwort-Übersicht gecacht und bei Schreibzugriffen verworfen', static function () use ($src): void {
    $repo = $src('core/Routing/ThemeArchiveRepository.php');
    T::check(str_contains($repo, "return \$this->cachedOverview('category', \$locale, \$localeAvailabilityExpression,"), 'Kategorie-Übersicht über den Cache');
    T::check(str_contains($repo, "return \$this->cachedOverview('tag', \$locale, \$localeAvailabilityExpression,"), 'Schlagwort-Übersicht über den Cache');
    T::check(str_contains($repo, '$entryKey = $locale . \'|\' . md5($localeAvailabilityExpression);'), 'Cache-Eintrag je Locale und Sichtbarkeitsausdruck');
    T::check(str_contains($repo, '$until = min($until, $nextPublication);'), 'Eintrag endet spätestens bei der nächsten geplanten Veröffentlichung');
    T::check(!str_contains($repo, "'url' => \\cms_get_archive_url("), 'URLs werden nicht gecacht, sondern beim Lesen gebaut');
    T::check(\CMS\Routing\ThemeArchiveRepository::OVERVIEW_CACHE_TTL <= 600, 'Höchstalter höchstens 10 Minuten');

    $db = $src('core/Database.php');
    $feedPos = strpos($db, 'Services\\FeedService::invalidatePublicFeedCache();');
    $repoPos = strpos($db, 'Routing\\ThemeArchiveRepository::invalidateOverviewCache();');
    T::check($feedPos !== false && $repoPos !== false && $repoPos > $feedPos && $repoPos - $feedPos < 300, 'noteWrite() verwirft die Übersichten zusammen mit dem Feed-Cache');
});

T::run('PERF db:9: Beitragsbild-Ansichten ohne LONGTEXT-Spalten', static function () use ($perfCoreFakeDb): void {
    $db = $perfCoreFakeDb(static fn (): array => []);
    $reflection = new \ReflectionClass(\CMS\Services\MediaUsageService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('db')->setValue($service, $db);
    $reflection->getProperty('prefix')->setValue($service, 'cms_');

    T::same([], $service->buildFeaturedImageMap(), 'leere Map ohne Inhalte');
    T::same([], $service->buildFeaturedImageContentList(), 'leere Liste ohne Inhalte');
    T::same(4, count($db->log), 'je Ansicht eine Abfrage für Beiträge und eine für Seiten');
    foreach ($db->log as $sql) {
        T::check(!str_contains($sql, 'content'), 'keine content/content_en-Spalten: ' . preg_replace('/\s+/', ' ', trim($sql)));
    }
    T::check(str_contains($db->log[0], "WHERE featured_image IS NOT NULL AND featured_image <> ''") && str_contains($db->log[1], 'cms_pages'), 'Map lädt nur Zeilen mit Beitragsbild');
    T::check(!str_contains($db->log[2], 'WHERE') && !str_contains($db->log[3], 'WHERE'), 'Inhaltsliste behält Inhalte ohne Beitragsbild');
});
