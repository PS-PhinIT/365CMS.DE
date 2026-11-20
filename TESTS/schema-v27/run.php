<?php
/**
 * Schema v27 (Release 4.2.0, Community-Modul): Neuinstallation, Update und Laufzeitprüfung legen dieselben 27 Tabellen an,
 * und der einmalige Update-Schritt `capabilities_v27` vergibt die Community-Rechte, ohne etwas zu überschreiben.
 *
 * Ohne Datenbank: prüft die Definitionen in SchemaManager::getSchemaQueries(), die Liste FEATURE_TABLES_V27, die Einbindung
 * in MigrationManager/ensureFeatureSchema/DatabaseUpdateRunner, die Strict-Mode-Regeln (keine NOT-NULL-Spalte ohne Default),
 * die Indizes und die Rechte-Migration gegen eine SQLite-Attrappe. Den Lauf gegen eine echte MariaDB/MySQL (Neuinstallation =
 * Update von v25 und v26, Strict-Mode, Idempotenz) macht TESTS/schema-upgrade (optional, CMS_TEST_DB_HOST).
 *
 * Aufruf: php TESTS/schema-v27/run.php [-v]
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once __DIR__ . '/lib.php';
T::$verbose = in_array('-v', $argv ?? [], true);

$root = dirname(__DIR__, 2) . '/CMS/';
if (!defined('ABSPATH')) {
    define('ABSPATH', $root);
}
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CMS\\')) {
        $file = $root . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use CMS\Database;
use CMS\SchemaManager;
use CMS\Services\Community\Support\CommunityCapabilities as Caps;

require_once $root . 'includes/functions/roles.php';

$src = static fn (string $file): string => (string) file_get_contents($root . $file);
$schemaSource = $src('core/SchemaManager.php');
$migrationSource = $src('core/MigrationManager.php');
$queries = SchemaManager::getSchemaQueries('cms_', 'utf8mb4');

const V27_TABLES = [
    'community_categories', 'community_forums', 'community_forum_permissions', 'community_forum_moderators',
    'community_topics', 'community_posts', 'community_post_revisions', 'community_attachments', 'community_drafts',
    'community_reactions', 'community_subscriptions', 'community_read_states', 'community_forum_read_marks',
    'community_polls', 'community_poll_options', 'community_poll_votes', 'community_ignores',
    'community_user_stats', 'community_ranks', 'community_badges', 'community_user_badges',
    'community_reports', 'community_warnings', 'community_bans', 'community_moderation_log',
    'community_stats_daily', 'community_redirects',
];

/**
 * Spaltendefinitionen einer CREATE-TABLE-Zeichenkette (eine Spalte je Zeile, ohne Schlüssel).
 *
 * @return array<string, string> Spaltenname => Definition
 */
function v27Columns(string $sql): array
{
    $columns = [];
    foreach (explode("\n", $sql) as $line) {
        $line = trim($line);
        if (preg_match('/^([a-z_][a-z0-9_]*)\s+(.+?),?$/', $line, $m) !== 1) {
            continue;
        }
        if (in_array(strtoupper($m[1]), ['CREATE', 'PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'ENGINE'], true)) {
            continue;
        }
        $columns[$m[1]] = $m[2];
    }

    return $columns;
}

/**
 * Schlüssel einer CREATE-TABLE-Zeichenkette.
 *
 * @return array<string, array{unique: bool, columns: list<string>}> Name => Eigenschaften
 */
function v27Indexes(string $sql): array
{
    $indexes = [];
    preg_match_all('/^\s*(UNIQUE KEY|KEY|INDEX)\s+([a-z0-9_]+)\s*\((.+)\),?\s*$/mi', $sql, $m, PREG_SET_ORDER);
    foreach ($m as $match) {
        $indexes[$match[2]] = ['unique' => stripos($match[1], 'UNIQUE') === 0, 'columns' => array_map('trim', explode(',', $match[3]))];
    }

    return $indexes;
}

/** Byte-Länge einer indizierten Spalte (utf8mb4: 4 Byte je Zeichen). */
function v27ColumnBytes(string $definition, ?int $prefix): int
{
    if ($prefix !== null) {
        return $prefix * 4;
    }
    if (preg_match('/^(?:VAR)?CHAR\((\d+)\)/i', $definition, $m) === 1) {
        return (int) $m[1] * 4;
    }
    $fixed = ['BIGINT' => 8, 'INT' => 4, 'SMALLINT' => 2, 'TINYINT' => 1, 'DATETIME' => 8, 'DATE' => 3];
    foreach ($fixed as $type => $bytes) {
        if (preg_match('/^' . $type . '\b/i', $definition) === 1) {
            return $bytes;
        }
    }

    return 1024;
}

// ── Dummy-Datenbank für die Rechte-Migration ──────────────────────────────────────────────────────────────────────────
/** SQLite-Attrappe mit den Tabellen, die CommunityCapabilities::migrate() liest und schreibt (MySQL-Syntax wird übersetzt). */
function v27Database(): Database
{
    return new class () extends Database {
        public PDO $sqlite;

        public function __construct()
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec('CREATE TABLE cms_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload INTEGER DEFAULT 1)');
            $this->sqlite->exec('CREATE TABLE cms_users (id INTEGER PRIMARY KEY, username TEXT, role TEXT)');
            $this->sqlite->exec('CREATE TABLE cms_role_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, role TEXT, capability TEXT, granted INTEGER DEFAULT 0, UNIQUE (role, capability))');
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            if (preg_match('/^\s*CREATE TABLE IF NOT EXISTS (cms_role_permissions) \(/', $sql) === 1) {
                // MySQL-Definition der Tabelle als SQLite-Äquivalent (die MySQL-Syntax prüft schema-upgrade gegen MariaDB).
                $sql = 'CREATE TABLE IF NOT EXISTS cms_role_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, role TEXT NOT NULL, capability TEXT NOT NULL, granted INTEGER NOT NULL DEFAULT 0, UNIQUE (role, capability))';
            }
            $sql = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $sql);
            $sql = str_replace('ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)', 'ON CONFLICT(option_name) DO UPDATE SET option_value = excluded.option_value', $sql);
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute(array_values($params));

            return $stmt;
        }

        public function get_var(string $query, array $params = []): mixed
        {
            $value = $this->execute($query, $params)->fetchColumn();

            return $value === false ? null : $value;
        }

        public function getPrefix(): string
        {
            return 'cms_';
        }

        public function tableExists(string $table): bool
        {
            return (bool) $this->sqlite->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $this->sqlite->quote($table))->fetchColumn();
        }
    };
}

/** @return array<string, array<string, int>> */
function v27Granted(Database $db): array
{
    $out = [];
    foreach ($db->sqlite->query("SELECT role, capability, granted FROM cms_role_permissions ORDER BY role, capability")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['role']][$row['capability']] = (int) $row['granted'];
    }

    return $out;
}

T::run('Versionsstand', static function () use ($migrationSource, $root): void {
    $version = SchemaManager::SCHEMA_VERSION;
    T::check((int) substr($version, 1) >= 27, 'SchemaManager::SCHEMA_VERSION ist mindestens v27 (Community-Modul)');
    T::check(str_contains($migrationSource, "public const SCHEMA_VERSION = '{$version}';"), 'MigrationManager nennt dieselbe Marke');
    $manifest = json_decode((string) file_get_contents($root . 'marketplace/core/365cms/update.json'), true);
    T::check((int) substr((string) ($manifest['schema_version'] ?? 'v0'), 1) <= (int) substr($version, 1), 'das Manifest des veröffentlichten Pakets eilt der Entwicklung nicht voraus');
});

T::run('Die 27 Tabellen', static function () use ($queries, $migrationSource, $schemaSource): void {
    T::same(V27_TABLES, SchemaManager::FEATURE_TABLES_V27, 'FEATURE_TABLES_V27 enthält genau die 27 Tabellen in der Reihenfolge von DATA-MODEL.md');
    T::same(27, count(SchemaManager::FEATURE_TABLES_V27), 'Anzahl 27');
    T::same(117, count($queries), 'Gesamtstand: 117 Tabellendefinitionen (90 bis v26 + 27)');
    T::same(117, count(array_unique(array_keys($queries))), 'jede Tabelle genau einmal definiert');
    T::same(array_values(SchemaManager::FEATURE_TABLES_V27), array_slice(array_keys($queries), -27), 'Definitionen stehen am Ende von getSchemaQueries() in derselben Reihenfolge');
    T::same([], array_values(array_intersect(SchemaManager::FEATURE_TABLES_V27, array_merge(SchemaManager::FEATURE_TABLES, SchemaManager::FEATURE_TABLES_V26))), 'keine Überschneidung mit v25/v26');

    foreach (V27_TABLES as $table) {
        $sql = (string) ($queries[$table] ?? '');
        T::check($sql !== '', "Definition für {$table} vorhanden (Neuinstallation)");
        T::check(str_contains($sql, "CREATE TABLE IF NOT EXISTS cms_{$table} ("), "{$table}: idempotentes CREATE mit Präfix");
        T::check(str_contains($sql, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'), "{$table}: InnoDB, utf8mb4, feste Kollation utf8mb4_unicode_ci");
        T::check(!preg_match('/\bJSON\b(?!_)/i', $sql), "{$table}: kein Spaltentyp JSON (LONGTEXT *_json, MariaDB)");
        T::check(!preg_match('/FOREIGN\s+KEY|REFERENCES\s/i', $sql), "{$table}: keine Fremdschlüssel (Konsistenz über die Dienste, wie der übrige Core)");
        T::check(!preg_match('/legacy_|bbcode/i', $sql), "{$table}: keine legacy_*-Spalten und kein BBCode (E6: keine Datenübernahme aus cms-forum)");
        T::check(str_contains($sql, "COMMENT='Community: "), "{$table}: Tabellenkommentar");
    }
    T::check(str_contains($migrationSource, 'array_merge(SchemaManager::FEATURE_TABLES, SchemaManager::FEATURE_TABLES_V26, SchemaManager::FEATURE_TABLES_V27)'), 'Update legt die v27-Tabellen über dieselben Definitionen an');
    T::check(str_contains($schemaSource, 'array_merge(self::FEATURE_TABLES, self::FEATURE_TABLES_V26, self::FEATURE_TABLES_V27)'), 'Laufzeitprüfung legt die v27-Tabellen über dieselben Definitionen an');
    T::check(str_contains($schemaSource, 'FEATURE_TABLES_V27 = ['), 'Liste ist eine Konstante des SchemaManagers');
    // Das Update von v26 legt die Community-Tabellen vollständig über CREATE an: kein ALTER im MigrationManager. Die Laufzeitprüfung
    // hat genau die ALTER-Schritte für die Nachträge nach PR #1 (Entwicklungsinstallationen, Abschnitt „Nachträge nach PR #1“).
    T::check(!preg_match('/ALTER TABLE[^\n]*community_/i', $migrationSource), 'MigrationManager: keine ALTER-Schritte für community_* (die Tabellen entstehen vollständig über CREATE)');
    preg_match_all('/"ALTER TABLE[^"\n]*community_[^"\n]*"/', $schemaSource, $alters);
    T::same([
        '"ALTER TABLE {$this->prefix}community_badges ADD COLUMN description_en VARCHAR(255) NULL AFTER description"',
    ], $alters[0], 'SchemaManager: ALTER-Schritte für community_* nur für die Nachträge nach PR #1 (Indizes über REQUIRED_INDEXES und ensureRequiredIndexes())');
});

T::run('Strict-Mode: keine NOT-NULL-Spalte ohne Default', static function () use ($queries): void {
    $checked = 0;
    foreach (V27_TABLES as $table) {
        foreach (v27Columns((string) $queries[$table]) as $column => $definition) {
            $checked++;
            T::check(!preg_match("/DEFAULT\s+'0000-00-00/", $definition), "{$table}.{$column}: kein Null-Datum als Default (NO_ZERO_DATE)", $definition);
            T::check(!preg_match('/\bCOLLATE\b/i', $definition), "{$table}.{$column}: keine eigene Kollation an der Spalte (gilt die der Tabelle: utf8mb4_unicode_ci)", $definition);
            if (stripos($definition, 'AUTO_INCREMENT') !== false) {
                continue;
            }
            if (stripos($definition, 'NOT NULL') !== false) {
                // Ein Default im COMMENT zählt nicht.
                $withoutComment = (string) preg_replace("/\\s+COMMENT\\s+'(?:[^']|'')*'/i", '', $definition);
                T::check(stripos($withoutComment, ' DEFAULT ') !== false, "{$table}.{$column}: NOT NULL hat einen Default", $definition);
            }
            // MySQL < 8.0.13 kennt keinen Default für TEXT/BLOB: solche Spalten sind NULL-fähig.
            if (preg_match('/^(?:TINY|MEDIUM|LONG)?(?:TEXT|BLOB)\b/i', $definition) === 1) {
                T::check(stripos($definition, 'NOT NULL') === false && stripos($definition, 'DEFAULT') === false, "{$table}.{$column}: Textspalte ohne Default und NULL-fähig", $definition);
            }
            // DATETIME NOT NULL: Default CURRENT_TIMESTAMP als Sicherheitsnetz (die Dienste setzen UTC ausdrücklich).
            if (preg_match('/^DATETIME\s+NOT NULL/i', $definition) === 1) {
                T::check(stripos($definition, 'DEFAULT CURRENT_TIMESTAMP') !== false, "{$table}.{$column}: DATETIME NOT NULL mit Default CURRENT_TIMESTAMP", $definition);
            }
        }
    }
    T::check($checked >= 250, "alle Spalten geprüft ({$checked})");
});

T::run('Indizes', static function () use ($queries): void {
    $names = [];
    foreach (V27_TABLES as $table) {
        $sql = (string) $queries[$table];
        $columns = v27Columns($sql);
        $indexes = v27Indexes($sql);
        T::check(preg_match('/PRIMARY KEY/', $sql) === 1, "{$table}: hat einen Primärschlüssel");
        foreach ($indexes as $name => $index) {
            T::check(strlen($name) <= 64, "{$table}.{$name}: Bezeichner höchstens 64 Zeichen");
            T::check(str_starts_with($name, $index['unique'] ? 'uq_' : 'idx_') && str_contains($name, substr($table, 10)), "{$table}.{$name}: Name nach Schema uq_/idx_ + Tabelle");
            T::check(!isset($names[$name]), "{$table}.{$name}: Indexname unter den 27 Tabellen eindeutig (wie v26)");
            $names[$name] = true;
            $bytes = 0;
            foreach ($index['columns'] as $part) {
                preg_match('/^([a-z_0-9]+)(?:\((\d+)\))?$/', $part, $pm);
                $column = $pm[1] ?? '';
                T::check(isset($columns[$column]), "{$table}.{$name}: Spalte {$column} existiert");
                $columnBytes = v27ColumnBytes($columns[$column] ?? '', isset($pm[2]) ? (int) $pm[2] : null);
                // Konservativ für Server ohne DYNAMIC-Zeilenformat: jede Indexspalte höchstens 767 Byte, gesamt höchstens 3072.
                T::check($columnBytes <= 767, "{$table}.{$name}.{$column}: höchstens 767 Byte ({$columnBytes})");
                $bytes += $columnBytes;
            }
            T::check($bytes <= 3072, "{$table}.{$name}: Schlüssellänge höchstens 3072 Byte ({$bytes})");
        }
        // Primärschlüssel (zusammengesetzt) über Spalten, die existieren.
        if (preg_match('/PRIMARY KEY \(([^)]+)\)/', $sql, $pk) === 1) {
            foreach (array_map('trim', explode(',', $pk[1])) as $column) {
                T::check(isset($columns[$column]), "{$table}: Primärschlüsselspalte {$column} existiert");
            }
        }
    }

    // Listen-, Warteschlangen- und Löschfrist-Abfragen aus DATA-MODEL.md, ARCHITECTURE.md §8 und SECURITY-PRIVACY.md §5.
    $expected = [
        'community_forums' => ['uq_community_forums_slug' => ['slug'], 'uq_community_forums_slug_en' => ['slug_en'], 'idx_community_forums_tree' => ['category_id', 'parent_id', 'sort_order'], 'idx_community_forums_path' => ['path(191)']],
        'community_forum_permissions' => ['uq_community_forum_permissions_subject' => ['forum_id', 'subject_type', 'subject_key'], 'idx_community_forum_permissions_lookup' => ['subject_type', 'subject_key']],
        'community_topics' => [
            'idx_community_topics_forum_list' => ['forum_id', 'status', 'is_pinned', 'last_post_at'],
            'idx_community_topics_unanswered' => ['forum_id', 'status', 'reply_count', 'created_at'],
            'idx_community_topics_activity' => ['status', 'last_post_at'],
            'idx_community_topics_user' => ['user_id', 'status', 'created_at'],
            // Nachträge nach PR #1: globale Liste „Unbeantwortet“ und Filter „ungelöst“ (HANDOFF §2 Nr. 9 und 10).
            'idx_community_topics_unanswered_all' => ['status', 'reply_count', 'created_at'],
            'idx_community_topics_solved' => ['solved_at', 'forum_id', 'status', 'is_pinned', 'last_post_at'],
        ],
        'community_posts' => [
            'idx_community_posts_topic' => ['topic_id', 'status', 'id'],
            'idx_community_posts_queue' => ['status', 'created_at'],
            'idx_community_posts_forum_time' => ['forum_id', 'status', 'created_at'],
            'idx_community_posts_created' => ['created_at'],
        ],
        'community_read_states' => ['idx_community_read_states_topic' => ['topic_id'], 'idx_community_read_states_cleanup' => ['updated_at']],
        'community_attachments' => ['idx_community_attachments_post' => ['post_id', 'created_at']],
        'community_drafts' => ['idx_community_drafts_updated' => ['updated_at']],
        'community_reports' => ['idx_community_reports_open' => ['status', 'created_at'], 'idx_community_reports_handled' => ['status', 'handled_at'], 'idx_community_reports_forum' => ['forum_id', 'status', 'created_at']],
        'community_subscriptions' => ['idx_community_subscriptions_target' => ['target_type', 'target_id', 'mode'], 'idx_community_subscriptions_mode' => ['mode', 'user_id']],
        'community_warnings' => ['idx_community_warnings_user' => ['user_id', 'expires_at'], 'idx_community_warnings_expires' => ['expires_at']],
        'community_bans' => ['idx_community_bans_user' => ['user_id', 'lifted_at', 'ends_at']],
        'community_moderation_log' => ['idx_community_moderation_log_time' => ['created_at'], 'idx_community_moderation_log_actor' => ['actor_id', 'created_at']],
        'community_post_revisions' => ['idx_community_post_revisions_post' => ['post_id', 'created_at'], 'idx_community_post_revisions_created' => ['created_at']],
        'community_polls' => ['uq_community_polls_topic' => ['topic_id']],
        'community_badges' => ['uq_community_badges_slug' => ['slug']],
    ];
    foreach ($expected as $table => $wanted) {
        $indexes = v27Indexes((string) $queries[$table]);
        foreach ($wanted as $name => $columns) {
            T::same($columns, $indexes[$name]['columns'] ?? null, "{$table}.{$name}");
        }
    }
    // Kein zweiter Index beginnt wie idx_community_topics_forum_list mit (forum_id, status, is_pinned): Mit einem solchen Index
    // (idx_community_topics_solved in der ersten Form des Nachlaufs) wählte MariaDB 10.11 für Forenliste und lastPublished() je nach
    // Schätzung idx_community_topics_activity und las in einem großen älteren Forum die jüngeren Themen aller Foren mit
    // (HANDOFF §2 Nr. 9). Ob der Optimierer ausweicht, hängt vom Seitenlayout ab; die Regeln prüfen deshalb die Definition.
    $topicIndexDefinitions = v27Indexes((string) $queries['community_topics']);
    $sharing = array_keys(array_filter($topicIndexDefinitions, static fn (array $index): bool => array_slice($index['columns'], 0, 3) === ['forum_id', 'status', 'is_pinned']));
    T::same(['idx_community_topics_forum_list'], $sharing, 'community_topics: nur idx_community_topics_forum_list beginnt mit (forum_id, status, is_pinned)');
    // Außer idx_community_topics_forum_list überdeckt kein Index, der mit forum_id beginnt, die ID-Abfrage des Stroms von listForum()
    // (forum_id, status, is_pinned, last_post_at): Mit idx_community_topics_solved in der zweiten Form (forum_id, solved_at, status,
    // is_pinned, last_post_at) nahm MariaDB 10.11 in kleinen Foren (30 bis 1200 Themen) auf Folgeseiten ref über forum_id mit
    // Filesort und las das ganze Forum (800 Themen: 801 statt 12 Lesezugriffe). Seit dem Nachlauf beginnt er mit solved_at.
    $streamColumns = ['forum_id', 'status', 'is_pinned', 'last_post_at'];
    $covering = array_keys(array_filter($topicIndexDefinitions, static fn (array $index): bool => ($index['columns'][0] ?? null) === 'forum_id' && array_diff($streamColumns, $index['columns']) === []));
    T::same(['idx_community_topics_forum_list'], $covering, 'community_topics: nur idx_community_topics_forum_list beginnt mit forum_id und überdeckt die ID-Abfrage des Stroms (forum_id, status, is_pinned, last_post_at)');
    T::same('solved_at', $topicIndexDefinitions['idx_community_topics_solved']['columns'][0] ?? null, 'idx_community_topics_solved beginnt mit solved_at (nur Abfragen mit Bedingung auf solved_at können ihn nutzen)');
    foreach (['community_forums' => 'uq_community_forums_slug', 'community_polls' => 'uq_community_polls_topic', 'community_badges' => 'uq_community_badges_slug', 'community_forum_permissions' => 'uq_community_forum_permissions_subject'] as $table => $name) {
        T::check(v27Indexes((string) $queries[$table])[$name]['unique'] ?? false, "{$table}.{$name} ist eindeutig");
    }
    T::check(str_contains((string) $queries['community_posts'], 'ip_hash CHAR(64) NULL'), 'Beiträge: ip_hash nur als Hash (CHAR(64)), NULL-fähig für die Löschfrist');
    // Keine Klar-IP, kein User-Agent, keine E-Mail-Adresse (Name der Spalte; neue Spalten fallen außerdem im Snapshot auf).
    foreach (V27_TABLES as $table) {
        foreach (v27Columns((string) $queries[$table]) as $column => $definition) {
            if ($column === 'ip_hash') {
                continue;
            }
            T::check(preg_match('/(^|_)(ip|addr|address|user_agent|ua|email|mail)(_|$)/', $column) !== 1, "{$table}.{$column}: keine Klar-IP, kein User-Agent, keine E-Mail-Spalte (SECURITY-PRIVACY §5)");
        }
    }
});

T::run('Struktur entspricht dem Snapshot golden.json (Definition, Snapshot, Doku)', static function () use ($queries, $root): void {
    $golden = json_decode((string) @file_get_contents(__DIR__ . '/golden.json'), true);
    T::check(is_array($golden), 'golden.json lesbar (Neu erzeugen: CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/schema-v27/dump-golden.php)');
    $golden = is_array($golden) ? $golden : [];
    T::same(V27_TABLES, array_keys($golden), 'der Snapshot enthält genau die 27 Tabellen in der Reihenfolge von FEATURE_TABLES_V27');
    $handoff = (string) @file_get_contents(dirname($root) . '/DOC/workflow/specs/community/HANDOFF-PR2-PR5.md');
    $totalIndexes = 0;
    foreach (V27_TABLES as $table) {
        $expected = $golden[$table] ?? ['columns' => [], 'primary' => [], 'indexes' => []];
        $ddl = v27ParseCreate((string) $queries[$table]);

        // Definition in SchemaManager == Snapshot (Spalten mit Typ, NULL, Default, AUTO_INCREMENT; Primärschlüssel; Indizes).
        T::same($expected['columns'], $ddl['columns'], "{$table}: Spalten (Name, Typ, NULL, Default, AUTO_INCREMENT) entsprechen dem Snapshot");
        T::same($expected['primary'], $ddl['primary'], "{$table}: Primärschlüssel entspricht dem Snapshot");
        T::same($expected['indexes'], $ddl['indexes'], "{$table}: Indizes (Name, UNIQUE, Spalten in Reihenfolge) entsprechen dem Snapshot");
        T::same([], $ddl['columnCollations'], "{$table}: keine Kollation an Spalten");
        T::same([], $ddl['unclassified'], "{$table}: jede Zeile des Rumpfs ist Spalte, Primärschlüssel oder Index (kein CHECK, FULLTEXT, GENERATED, ON UPDATE-Sonderfall)");
        T::check(!preg_match('/ON UPDATE|\bCHECK\b|\bGENERATED\b|\bFULLTEXT\b|\bSPATIAL\b|`|\bCHARACTER SET\b|\bROW_FORMAT\b|\bPARTITION\b/i', (string) $queries[$table]), "{$table}: kein ON UPDATE, CHECK, GENERATED, FULLTEXT, Backtick, Zeichensatz an Spalten, ROW_FORMAT oder PARTITION");
        T::check(preg_match("/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Community: [^']+'\s*$/", (string) $queries[$table]) === 1, "{$table}: Tabellenkopf genau ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Community: …'");
        $totalIndexes += count($expected['indexes']);

        // Doku (HANDOFF §3) == Snapshot, in beide Richtungen und mit Typ, NULL, Default und Indexspalten.
        T::check(preg_match('/^#### `' . preg_quote($table, '/') . '`[^\n]*\n(.*?)(?=^#{2,4} |\z)/ms', $handoff, $section) === 1, "HANDOFF hat einen Abschnitt für {$table}");
        $doc = v27ParseHandoffSection($section[1] ?? '');
        T::same($expected['columns'], $doc['columns'], "HANDOFF {$table}: Spaltentabelle entspricht dem Snapshot (Name, Typ, NULL, Default, Reihenfolge)");
        T::same($expected['primary'], $doc['primary'], "HANDOFF {$table}: Primärschlüssel entspricht dem Snapshot");
        T::same($expected['indexes'], $doc['indexes'], "HANDOFF {$table}: Indextabelle entspricht dem Snapshot (Name, Art, Spalten)");
    }
    T::same(64, $totalIndexes, 'insgesamt 64 Nicht-Primär-Indizes in den 27 Tabellen (62 aus PR #1, zwei Nachträge an community_topics)');
});

T::run('Spaltenumfang (DATA-MODEL.md)', static function () use ($queries): void {
    $expected = [
        'community_topics' => ['id', 'forum_id', 'user_id', 'title', 'slug', 'status', 'moved_to_topic_id', 'moved_expires_at', 'is_pinned', 'pinned_until', 'is_announcement', 'is_locked', 'locked_until', 'slow_mode_seconds', 'prefix', 'first_post_id', 'solution_post_id', 'solved_at', 'solved_by', 'has_poll', 'has_attachments', 'reply_count', 'view_count', 'reaction_count', 'last_post_id', 'last_post_at', 'last_poster_id', 'version', 'trashed_at', 'trashed_by', 'previous_status', 'created_at', 'updated_at'],
        'community_posts' => ['id', 'topic_id', 'forum_id', 'user_id', 'is_first_post', 'status', 'status_reason', 'format', 'body', 'body_html', 'rendered_version', 'quote_of_post_id', 'edit_count', 'edited_at', 'edited_by', 'edit_reason', 'reaction_count', 'ip_hash', 'created_at', 'updated_at'],
        'community_bans' => ['id', 'user_id', 'scope', 'reason', 'internal_note', 'issued_by', 'starts_at', 'ends_at', 'lifted_at', 'lifted_by', 'created_at'],
        'community_redirects' => ['source_type', 'source_key', 'target_type', 'target_id', 'created_at'],
        'community_stats_daily' => ['day', 'forum_id', 'topics', 'posts', 'active_members', 'new_members', 'solved', 'median_first_reply_minutes'],
    ];
    foreach ($expected as $table => $columns) {
        T::same($columns, array_keys(v27Columns((string) $queries[$table])), "{$table}: Spalten und Reihenfolge");
    }
    $total = 0;
    foreach (V27_TABLES as $table) {
        $total += count(v27Columns((string) $queries[$table]));
    }
    T::same(['id', 'slug', 'title', 'title_en', 'description', 'description_en', 'icon', 'tier', 'rule_json', 'is_active'], array_keys(v27Columns((string) $queries['community_badges'])), 'community_badges: Spalten und Reihenfolge (description_en nach description, wie title_en nach title)');
    T::same(269, $total, 'insgesamt 269 Spalten in den 27 Tabellen (268 aus PR #1, dazu community_badges.description_en)');
});

T::run('Core-Tabelle notifications: Gruppenschlüssel und Zähler (Bündelung)', static function () use ($queries, $migrationSource, $schemaSource): void {
    $sql = (string) $queries['notifications'];
    T::check(preg_match('/\n\s+group_key VARCHAR\(120\) DEFAULT NULL/', $sql) === 1, 'notifications.group_key in der Neuinstallation (eigene Zeile)');
    T::check(preg_match('/\n\s+event_count INT UNSIGNED NOT NULL DEFAULT 1/', $sql) === 1, 'notifications.event_count in der Neuinstallation (NOT NULL mit Default)');
    T::check(str_contains($sql, 'INDEX idx_user_group (user_id, group_key, is_read)'), 'Index idx_user_group (user_id, group_key, is_read): ungelesener Hinweis eines Mitglieds mit Gruppenschlüssel');
    // Update und Laufzeitprüfung nutzen die Definition aus dem CREATE (mit Kommentar). group_key erhält im ALTER zusätzlich eine
    // ausdrückliche Kollation (ADD COLUMN erbt sonst die der Tabelle, aus 4.0.0 die Standardkollation des Servers); im CREATE bestimmt sie die
    // Tabelle (feste Kollation utf8mb4_unicode_ci, keine Spaltenkollation in CREATE-Texten).
    $definitions = v27Columns($sql);
    T::check(str_contains($sql, 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci') && !preg_match('/\b(CHARACTER SET|COLLATE)\s+\w/i', $definitions['group_key'] ?? ''), 'CREATE: group_key ohne eigene Kollation, die Tabelle trägt utf8mb4_unicode_ci');
    $expected = [
        'group_key' => (string) preg_replace('/^VARCHAR\(120\)/', 'VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $definitions['group_key'] ?? ''),
        'event_count' => $definitions['event_count'] ?? '',
    ];
    T::check(str_contains($expected['group_key'], " COMMENT '") && str_contains($expected['event_count'], " COMMENT '"), 'CREATE: group_key und event_count mit Spaltenkommentar');
    T::same($expected, SchemaManager::REQUIRED_COLUMN_DEFINITIONS['notifications'] ?? null, 'REQUIRED_COLUMN_DEFINITIONS: group_key/event_count mit der Definition aus dem CREATE (Laufzeitprüfung und Datenbank-Update)');
    // Erweiterung nur zusammen mit ensureFeatureSchema(): Es liest ausdrücklich den Schlüssel notifications. Eine weitere Tabelle
    // braucht dort einen eigenen ensureColumnExists()/alignColumnDefinitions()-Aufruf und einen Eintrag in REQUIRED_COLUMNS;
    // sonst gleicht die Laufzeitprüfung nie an und das Datenbank-Update scheitert dauerhaft bzw. prüft die Spalte nicht.
    T::same(['notifications'], array_keys(SchemaManager::REQUIRED_COLUMN_DEFINITIONS), 'REQUIRED_COLUMN_DEFINITIONS: nur notifications (eine weitere Tabelle braucht einen eigenen Schritt in ensureFeatureSchema() und einen Eintrag in REQUIRED_COLUMNS)');
    foreach ($expected as $column => $definition) {
        T::check(str_contains($migrationSource, '"ALTER TABLE `{$p}notifications` ADD COLUMN `' . $column . '` ' . $definition . '"'), "Update (MigrationManager): {$column} mit derselben Definition wie im CREATE", $definition);
    }
    T::check(str_contains($schemaSource, 'foreach (self::REQUIRED_COLUMN_DEFINITIONS[\'notifications\'] as $column => $definition) {')
        && str_contains($schemaSource, '$notificationColumns[$column] = "ALTER TABLE {$this->prefix}notifications ADD COLUMN {$column} {$definition}";'), 'Laufzeitprüfung (ensureFeatureSchema): ADD COLUMN mit der Definition aus REQUIRED_COLUMN_DEFINITIONS');
    T::check(str_contains($schemaSource, '$this->alignColumnDefinitions($this->prefix . \'notifications\', $notificationColumns);')
        && str_contains($schemaSource, "'ALTER TABLE ' . \$table . ' MODIFY COLUMN ' . \$column . ' ' . \$definition"), 'Laufzeitprüfung: vorhandene Spalten mit abweichendem Kommentar oder abweichender Kollation werden per MODIFY COLUMN mit derselben Definition angeglichen (Entwicklungsinstallationen aus PR #1)');
    T::same(['comment' => 'Bündelung gleichartiger Hinweise, z. B. community:topic:12', 'collation' => 'utf8mb4_unicode_ci'], SchemaManager::columnDefinitionAttributes($expected['group_key']), 'Kommentar und Kollation aus der Definition gelesen (Vergleich in Laufzeitprüfung und Datenbank-Update)');
    T::same(['comment' => 'Anzahl gebündelter Ereignisse', 'collation' => null], SchemaManager::columnDefinitionAttributes($expected['event_count']), 'event_count: Kommentar, keine eigene Kollation');
    T::check(!preg_match('/notifications`? ADD COLUMN `?(group_key|event_count)`?[^"\n]*\bAFTER\b/', $migrationSource . $schemaSource), 'ohne AFTER: beim Update hängen die Spalten an (wie die v26-Spalten von orders); verglichen werden Definition, Kommentar und Kollation, nicht die Position');
    T::check(str_contains($migrationSource, 'ALTER TABLE `{$p}notifications` ADD INDEX `idx_user_group` (`user_id`, `group_key`, `is_read`)'), 'Update ergänzt den Index (Bestandszeilen: kein Schlüssel, Zähler 1)');
    T::same(v27Indexes($sql)['idx_user_group']['columns'] ?? null, SchemaManager::REQUIRED_INDEXES['notifications']['idx_user_group'] ?? null, 'REQUIRED_INDEXES: idx_user_group mit den Spalten aus dem CREATE (Laufzeitprüfung und Datenbank-Update)');
    T::check(str_contains($schemaSource, 'foreach (array_keys(self::REQUIRED_INDEXES) as $tableName) {'), 'Laufzeitprüfung ergänzt den Index (ensureRequiredIndexes() für jede Tabelle aus REQUIRED_INDEXES)');
    T::same(['group_key', 'event_count'], SchemaManager::REQUIRED_COLUMNS['notifications'] ?? null, 'Datenbank-Update prüft die Spalten (REQUIRED_COLUMNS)');
    foreach (['title', 'message', 'url'] as $unchanged) {
        T::check(isset(v27Columns($sql)[$unchanged]), "notifications.{$unchanged} unverändert vorhanden");
    }
});

T::run('Nachträge nach PR #1: Indizes an community_topics, community_badges.description_en (Entwicklungsinstallationen)', static function () use ($queries, $migrationSource, $schemaSource): void {
    // Neuinstallation und Update von v26 erhalten sie über das CREATE, Entwicklungsinstallationen aus PR #1 über ensureFeatureSchema()
    // (HANDOFF §2 Nr. 9). Definition in ensureFeatureSchema() = Definition im CREATE.
    $topicIndexes = v27Indexes((string) $queries['community_topics']);
    T::same(['idx_community_topics_unanswered_all', 'idx_community_topics_solved'], array_keys(SchemaManager::REQUIRED_INDEXES['community_topics'] ?? []), 'REQUIRED_INDEXES: genau die beiden Nachträge an community_topics');
    foreach (['idx_community_topics_unanswered_all', 'idx_community_topics_solved'] as $name) {
        T::same($topicIndexes[$name]['columns'] ?? null, SchemaManager::REQUIRED_INDEXES['community_topics'][$name] ?? null, "REQUIRED_INDEXES: {$name} mit den Spalten aus dem CREATE (Laufzeitprüfung und Datenbank-Update)");
        T::check(!str_contains($migrationSource, $name), "MigrationManager: {$name} kommt über das CREATE (kein eigener Schritt)");
    }
    T::check(str_contains($schemaSource, 'foreach (array_keys(self::REQUIRED_INDEXES) as $tableName) {')
        && str_contains($schemaSource, "if ((\$existing[\$name]['columns'] ?? null) === \$columns) {")
        && str_contains($schemaSource, '$replace = isset($existing[$name]) ? "DROP INDEX {$name}, " : \'\';')
        && str_contains($schemaSource, "error_log(sprintf('SchemaManager::ensureFeatureSchema(%s.%s) failed: %s', \$tableName, \$name"), 'ensureFeatureSchema(): fehlende Indizes anlegen, einen gleichnamigen Index mit anderen Spalten in derselben Anweisung ersetzen, Fehler protokollieren');
    $runner = (string) file_get_contents(dirname(__DIR__, 2) . '/CMS/core/DatabaseUpdateRunner.php');
    T::check(str_contains($runner, 'foreach (SchemaManager::REQUIRED_INDEXES as $table => $required) {') && str_contains($runner, "\$missing[] = 'index:' . \$table . '.' . \$name;"), 'Datenbank-Update prüft die Indizes aus REQUIRED_INDEXES (Name und Spalten)');
    T::check(str_contains($runner, 'foreach (SchemaManager::REQUIRED_COLUMN_DEFINITIONS as $table => $definitions) {') && str_contains($runner, "' (Kommentar/Kollation)'"), 'Datenbank-Update prüft Kommentar und Kollation der Spalten aus REQUIRED_COLUMN_DEFINITIONS');
    $badge = v27Columns((string) $queries['community_badges']);
    T::same('VARCHAR(255) NULL', $badge['description_en'] ?? null, 'community_badges.description_en: gleicher Typ wie description, NULL-fähig');
    T::same($badge['description'] ?? null, $badge['description_en'] ?? null, 'description_en hat dieselbe Definition wie description');
    T::check(str_contains($schemaSource, "'description_en',\n            \"ALTER TABLE {\$this->prefix}community_badges ADD COLUMN description_en " . ($badge['description_en'] ?? '?') . ' AFTER description"'), 'ensureFeatureSchema(): ensureColumnExists() mit der Definition aus dem CREATE, nach description');
    T::same(['description_en'], SchemaManager::REQUIRED_COLUMNS['community_badges'] ?? null, 'Datenbank-Update prüft community_badges.description_en (REQUIRED_COLUMNS)');
    T::check(!str_contains($migrationSource, 'description_en'), 'MigrationManager: description_en kommt beim Update von v26 über das CREATE');
});

T::run('REQUIRED_INDEXES: jede Tabelle im CREATE, in der Laufzeitprüfung und im Datenbank-Update (Strukturregel)', static function () use ($queries, $schemaSource): void {
    // HANDOFF §2 Nr. 5: Ein nachträglicher Index braucht genau zwei Einträge, in getSchemaQueries() und in REQUIRED_INDEXES. Dafür
    // steht jeder Eintrag im CREATE (Neuinstallation, Update von v26), und ensureFeatureSchema() behandelt jede Tabelle aus
    // REQUIRED_INDEXES. Bisher rief es ensureRequiredIndexes() nur für notifications und community_topics auf: Ein Eintrag für eine
    // weitere Tabelle wurde nie angelegt, und das Datenbank-Update scheiterte dauerhaft (Verhalten: TESTS/schema-upgrade).
    foreach (SchemaManager::REQUIRED_INDEXES as $table => $required) {
        T::check(isset($queries[$table]), "REQUIRED_INDEXES: {$table} hat eine Definition in getSchemaQueries()");
        $indexes = v27Indexes((string) ($queries[$table] ?? ''));
        foreach ($required as $name => $columns) {
            T::same($columns, $indexes[$name]['columns'] ?? null, "REQUIRED_INDEXES: {$table}.{$name} steht mit denselben Spalten im CREATE");
            T::same(false, $indexes[$name]['unique'] ?? null, "REQUIRED_INDEXES: {$table}.{$name} ist ein gewöhnlicher Index (ensureRequiredIndexes() legt ADD INDEX an)");
            T::same([], array_values(array_filter($columns, static fn (string $column): bool => preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1)), "REQUIRED_INDEXES: {$table}.{$name} nur ganze Spalten in Kleinschreibung (Vergleich mit information_schema ohne Präfixlänge)");
        }
    }
    $redundant = (new ReflectionClassConstant(SchemaManager::class, 'REDUNDANT_INDEXES'))->getValue();
    foreach (SchemaManager::REQUIRED_INDEXES as $table => $required) {
        T::same([], array_values(array_intersect(array_keys($required), (array) ($redundant[$table] ?? []))), "REQUIRED_INDEXES: kein Index von {$table} steht in REDUNDANT_INDEXES (dropRedundantIndexes() läuft nach ensureFeatureSchema())");
    }

    T::check(preg_match('/private function ensureFeatureSchema\(\): void\n    \{\n(.*?)\n    \}\n/s', $schemaSource, $body) === 1, 'ensureFeatureSchema() im Quelltext gefunden');
    $body = $body[1] ?? '';
    $loop = "foreach (array_keys(self::REQUIRED_INDEXES) as \$tableName) {\n            \$this->ensureRequiredIndexes(\$tableName);\n        }";
    $loopAt = strpos($body, $loop);
    T::check($loopAt !== false, 'ensureFeatureSchema() ruft ensureRequiredIndexes() für jede Tabelle aus array_keys(self::REQUIRED_INDEXES) auf');
    T::same(0, preg_match_all("/ensureRequiredIndexes\\('/", $schemaSource), 'keine Aufrufe von ensureRequiredIndexes() für einzelne Tabellen (eine Schleife über alle Schlüssel)');
    T::check($loopAt !== false && $loopAt > (int) strrpos($body, 'ensureColumnExists(') && $loopAt > (int) strrpos($body, 'alignColumnDefinitions('), 'die Schleife steht nach allen Spaltennachträgen von ensureFeatureSchema() (Indizes auf Spalten, die derselbe Lauf nachträgt)');
    T::check(str_contains($schemaSource, "\$existing = \$this->loadIndexes(\$table);\n        if (\$existing === []) {\n            return;\n        }"), 'ensureRequiredIndexes(): eine fehlende Tabelle wird übersprungen');
});

T::run('Rechte: Katalog und Standards (CommunityCapabilities)', static function () use ($src): void {
    T::same(['community.view', 'community.participate', 'community.moderate', 'community.ban', 'community.manage', 'community.view_private_data'], Caps::ALL, 'sechs Capabilities in der Reihenfolge von PERMISSIONS-MODERATION.md §2');
    foreach (Caps::ALL as $capability) {
        T::check(preg_match('/^community\.[a-z_]+$/', $capability) === 1 && strlen($capability) <= 100, "{$capability}: Namensschema, höchstens 100 Zeichen (role_permissions.capability)");
        T::same($capability, cms_normalize_role_capability($capability), "{$capability}: bleibt bei der Normalisierung unverändert");
    }
    T::same(['member' => ['community.view', 'community.participate'], 'editor' => ['community.view', 'community.participate'], 'author' => ['community.view', 'community.participate'], 'moderator' => ['community.view', 'community.participate', 'community.moderate']], Caps::DEFAULT_GRANTS, 'Standardrechte wie in der Spezifikation (ban, manage, view_private_data nur admin)');
    $elevatedGranted = [];
    foreach (Caps::DEFAULT_GRANTS as $role => $grants) {
        foreach (array_values(array_intersect($grants, Caps::ELEVATED)) as $capability) {
            $elevatedGranted[] = [$role, $capability];
        }
    }
    T::same([['moderator', 'community.moderate']], $elevatedGranted, 'nur die Rolle moderator erhält ein erhöhtes Recht (community.moderate)');
    T::same([Caps::MODERATE, Caps::BAN, Caps::MANAGE, Caps::VIEW_PRIVATE_DATA], Caps::ELEVATED, 'erhöhte Rechte');
    T::same(array_values(array_diff(Caps::ALL, [Caps::VIEW, Caps::PARTICIPATE])), Caps::ELEVATED, 'alles außer view/participate ist erhöht');
    foreach (['member', 'editor', 'author'] as $role) {
        T::same([], array_values(array_intersect(Caps::ELEVATED, Caps::DEFAULT_GRANTS[$role])), "{$role}: keine erhöhten Rechte");
    }
    T::check(strlen(Caps::MODERATOR_ROLE) <= 20, 'Rollen-Slug höchstens 20 Zeichen (users.role VARCHAR(20))');

    // Rollen-Katalog, Standardrollen und Registrierungssperre verwenden dieselben Namen.
    $roles = $src('admin/modules/users/RolesModule.php');
    T::check(str_contains($roles, "'community' => ['community.view', 'community.participate', 'community.moderate', 'community.ban', 'community.manage', 'community.view_private_data']"), 'RolesModule::CAPABILITIES: Gruppe community mit denselben sechs Rechten');
    T::check(str_contains($roles, "private const ROLES = ['admin', 'editor', 'author', 'member', 'moderator'];"), 'moderator ist Systemrolle (nicht umbenenn-/löschbar)');
    T::check(str_contains($roles, "'moderator' => 'Moderator',"), 'Beschriftung der Rolle moderator');
    T::check(str_contains($roles, "foreach (['editor', 'author', 'member', 'moderator'] as \$role) {") && str_contains($roles, "'community.moderate'] as \$cap) {"), 'Matrix-Standards (getDefaultPermissions) entsprechen den Standardrechten');
    // Standardmatrix des Rollen-Editors („Standardrechte wiederherstellen“, Matrix-Anzeige) tatsächlich ausführen.
    require_once ABSPATH . 'admin/modules/users/RolesModule.php';
    $module = (new ReflectionClass('RolesModule'))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod('RolesModule', 'getDefaultPermissions');
    $method->setAccessible(true);
    /** @var array<string, array<string, bool>> $matrix */
    $matrix = $method->invoke($module);
    foreach (Caps::DEFAULT_GRANTS as $role => $grants) {
        foreach ($grants as $capability) {
            T::same(true, $matrix[$role][$capability] ?? null, "RolesModule::getDefaultPermissions: {$role} hat {$capability}");
        }
    }
    foreach (['member', 'editor', 'author', 'moderator'] as $role) {
        $allowedElevated = $role === 'moderator' ? [Caps::MODERATE] : [];
        T::same($allowedElevated, array_values(array_intersect(Caps::ELEVATED, array_keys(array_filter($matrix[$role] ?? [])))), "RolesModule::getDefaultPermissions: {$role} hat nur die vorgesehenen erhöhten Community-Rechte");
    }
    foreach (Caps::ALL as $capability) {
        T::same(true, $matrix['admin'][$capability] ?? null, "RolesModule::getDefaultPermissions: admin hat {$capability}");
    }
    // UserService::DEFAULT_ROLE_CAPABILITIES: letzter Rückfall; darf nie mehr Community-Rechte vergeben als vorgesehen.
    $fallback = (new ReflectionClass(\CMS\Services\UserService::class))->getConstant('DEFAULT_ROLE_CAPABILITIES');
    T::check(is_array($fallback), 'UserService::DEFAULT_ROLE_CAPABILITIES lesbar');
    foreach (['member', 'editor', 'author', 'moderator'] as $role) {
        $allowedElevated = $role === 'moderator' ? [Caps::MODERATE] : [];
        T::same($allowedElevated, array_values(array_intersect(Caps::ELEVATED, (array) ($fallback[$role] ?? []))), "UserService::DEFAULT_ROLE_CAPABILITIES: {$role} hat nur die vorgesehenen erhöhten Community-Rechte");
    }
    T::check(in_array('moderator', \CMS\Auth\SSO\IdentityLinker::PRIVILEGED_ROLES, true), 'IdentityLinker::PRIVILEGED_ROLES enthält moderator (keine automatische Entra-Verknüpfung per E-Mail)');
    // Zweite Sicherung neben EntraSettings::normalize(): auch nicht normalisierte Einstellungen machen moderator nicht zur Standardrolle.
    T::same('member', \CMS\Auth\SSO\IdentityLinker::syncTarget('subscriber', null, ['default_role' => 'moderator']), 'IdentityLinker::syncTarget: Standardrolle moderator fällt auf member zurück');
    T::same('editor', \CMS\Auth\SSO\IdentityLinker::syncTarget('subscriber', null, ['default_role' => 'editor']), 'IdentityLinker::syncTarget: andere Standardrollen bleiben');
    T::same('moderator', \CMS\Auth\SSO\IdentityLinker::syncTarget('member', 'moderator', ['default_role' => 'member']), 'ausdrückliches Mapping auf moderator bleibt möglich');
    $users = $src('core/Services/UserService.php');
    T::check(preg_match('/REGISTRATION_BLOCKED_CAPABILITIES = \[(.*?)\];/s', $users, $blocked) === 1, 'UserService::REGISTRATION_BLOCKED_CAPABILITIES lesbar');
    foreach (Caps::ELEVATED as $capability) {
        T::check(str_contains($blocked[1] ?? '', "'{$capability}',"), "UserService: {$capability} ist für die Registrierung gesperrt");
    }
    foreach ([Caps::VIEW, Caps::PARTICIPATE] as $capability) {
        T::check(!str_contains($blocked[1] ?? '', "'{$capability}'"), "UserService: {$capability} bleibt für Mitglieder erlaubt");
    }
    T::check(preg_match('/REGISTRATION_BLOCKED_PREFIXES = \[(.*?)\];/s', $users, $prefixes) === 1 && !str_contains($prefixes[1], 'community'), 'kein Präfix community. gesperrt (sonst fielen Mitglieder aus den Registrierungsrollen)');
    T::check(str_contains($users, "\$role === 'admin' || \$role === 'moderator'"), 'moderator ist nie als Registrierungsrolle wählbar');
});

T::run('Rechte: Standardrollen in roles.php', static function () use ($root): void {
    $definitions = cms_get_default_role_definitions();
    foreach (Caps::DEFAULT_GRANTS as $role => $grants) {
        foreach ($grants as $capability) {
            T::same(true, $definitions[$role]['capabilities'][$capability] ?? null, "{$role}: Standardrecht {$capability}");
        }
    }
    foreach (Caps::ALL as $capability) {
        T::same(true, $definitions['admin']['capabilities'][$capability] ?? null, "admin: {$capability} in der Matrix");
    }
    foreach (Caps::ELEVATED as $capability) {
        foreach (['member', 'editor', 'author', 'subscriber', 'contributor'] as $role) {
            T::check(empty($definitions[$role]['capabilities'][$capability]), "{$role}: kein {$capability} als Standard");
        }
    }
    T::same('Moderator', $definitions['moderator']['display_name'] ?? '', 'Rolle moderator mit Anzeigename');
    foreach (Caps::MODERATOR_BASE as $capability) {
        T::same(true, $definitions['moderator']['capabilities'][$capability] ?? null, "moderator: Grundrecht {$capability} (CommunityCapabilities::MODERATOR_BASE)");
    }
    T::check(empty($definitions['moderator']['capabilities']['manage_settings']) && empty($definitions['moderator']['capabilities']['manage_users']), 'moderator hat keine Verwaltungsrechte des Cores');
    $loaded = cms_get_default_role_definitions()['moderator']['capabilities'];
    T::same(array_merge(Caps::MODERATOR_BASE, Caps::DEFAULT_GRANTS['moderator']), array_keys($loaded), 'moderator: genau Grundrechte (Code-Standard, keine Zeilen) und Community-Standards');
});

T::run('capabilities_v27: Vergabe, Marker, Idempotenz', static function (): void {
    $expectedAll = [];
    foreach (Caps::DEFAULT_GRANTS as $role => $capabilities) {
        foreach ($capabilities as $capability) {
            $expectedAll[] = [$role, $capability];
        }
    }

    $db = v27Database();
    $granted = Caps::migrate($db);
    T::same($expectedAll, $granted, 'Neuinstallation: nur die Standardrechte community.* (Katalog-Rechte)');
    T::same(2 + 2 + 2 + 3, count($granted), '9 Zeilen: member 2, editor 2, author 2, moderator 3');
    T::same(1, (int) $db->get_var("SELECT COUNT(*) FROM cms_settings WHERE option_name = 'capabilities_v27'"), 'Marker capabilities_v27 gesetzt');
    T::same([], Caps::migrate($db), 'zweiter Lauf ändert nichts (einmalig)');
    $rows = v27Granted($db);
    foreach (['member', 'editor', 'author', 'moderator'] as $role) {
        T::same(1, $rows[$role]['community.view'] ?? null, "{$role}: community.view");
        T::same(1, $rows[$role]['community.participate'] ?? null, "{$role}: community.participate");
    }
    T::same(1, $rows['moderator']['community.moderate'] ?? null, 'moderator: community.moderate');
    foreach (['member', 'editor', 'author', 'moderator'] as $role) {
        foreach (['community.ban', 'community.manage', 'community.view_private_data'] as $capability) {
            T::check(!isset($rows[$role][$capability]), "{$role}: kein {$capability}");
        }
    }
    T::check(!isset($rows['admin']), 'admin erhält keine Zeilen (hat alle Rechte implizit)');
    // Zeilen außerhalb des Rollen-Katalogs (read, edit_profile, view_content, …) erschienen im Rollen-Editor als „eigene
    // Rechte“, und das Speichern der Matrix entzöge sie Mitglied, Editor und Autor: nur Katalog-Rechte eintragen.
    $outsideCatalog = [];
    foreach ($rows as $role => $capabilities) {
        foreach (array_keys($capabilities) as $capability) {
            if (!Caps::isCommunityCapability($capability)) {
                $outsideCatalog[] = "{$role}:{$capability}";
            }
        }
    }
    T::same([], $outsideCatalog, 'nur Rechte des Rollen-Katalogs in role_permissions (keine „eigenen Rechte“, keine Entzüge anderer Rollen beim Speichern)');
    foreach (['member', 'editor', 'author'] as $role) {
        T::check(!isset($rows[$role]['community.moderate']), "{$role}: kein community.moderate");
    }

    // Läuft der Schritt erneut (Marker entfernt, z. B. „Datenbank reparieren“ nach manuellem Löschen), entsteht kein Duplikat.
    $db->execute("DELETE FROM cms_settings WHERE option_name = 'capabilities_v27'");
    T::same([], Caps::migrate($db), 'ohne Marker, aber mit vorhandenen Zeilen: nichts Neues, Marker wird wieder gesetzt');
    T::same(1, (int) $db->get_var("SELECT COUNT(*) FROM cms_settings WHERE option_name = 'capabilities_v27'"), 'Marker wieder gesetzt');
    T::same(9, (int) $db->get_var('SELECT COUNT(*) FROM cms_role_permissions'), 'weiterhin 9 Zeilen (INSERT IGNORE)');
});

T::run('capabilities_v27: überschreibt nie, nimmt nie zurück', static function (): void {
    // Ausdrücklich entzogenes Recht, vorhandene Rolle moderator (ein Grundrecht gespeichert, ein Community-Recht entzogen).
    $db = v27Database();
    $db->execute("INSERT INTO cms_role_permissions (role, capability, granted) VALUES ('member', 'community.participate', 0), ('moderator', 'community.moderate', 0), ('moderator', 'comments.view', 1), ('editor', 'community.view', 1)");
    $granted = Caps::migrate($db);
    $rows = v27Granted($db);
    T::same(0, $rows['member']['community.participate'], 'entzogenes Recht bleibt entzogen (granted = 0)');
    T::same(1, $rows['member']['community.view'], 'daneben wird das fehlende Standardrecht vergeben');
    T::same(0, $rows['moderator']['community.moderate'], 'vorhandene Rolle moderator: Entzug bleibt');
    T::same(1, $rows['moderator']['community.view'], 'vorhandene Rolle moderator: Standardrecht ergänzt');
    T::check(!isset($rows['moderator']['read']), 'vorhandene Rolle moderator: keine Zeilen für Grundrechte (sie kommen aus den Code-Standards in roles.php)');
    T::check(!in_array(['editor', 'community.view'], $granted, true) && in_array(['editor', 'community.participate'], $granted, true), 'vorhandenes Recht wird nicht erneut gemeldet');

    // Konten mit der Rolle moderator, aber noch keine Zeilen: ebenfalls vorhandene Rolle.
    $withUsers = v27Database();
    $withUsers->execute("INSERT INTO cms_users (id, username, role) VALUES (1, 'mo', 'moderator')");
    Caps::migrate($withUsers);
    T::check(!isset(v27Granted($withUsers)['moderator']['read']), 'Konto mit Rolle moderator: keine Zeilen für Grundrechte');
    T::same(1, v27Granted($withUsers)['moderator']['community.moderate'] ?? null, 'Konto mit Rolle moderator: Community-Standards vergeben (wirksam ohnehin über roles.php)');
});

T::run('capabilities_v27: Fehler setzen keinen Marker', static function (): void {
    // Das Eintragen schlägt fehl, nachdem alles gelesen wurde (Trigger): der Marker darf nicht vorher gesetzt worden sein.
    $db = v27Database();
    $db->sqlite->exec("CREATE TRIGGER cms_role_permissions_abort BEFORE INSERT ON cms_role_permissions WHEN NEW.capability = 'community.moderate' BEGIN SELECT RAISE(ABORT, 'Schreibfehler'); END");
    $threwMidway = false;
    try {
        Caps::migrate($db);
    } catch (\Throwable) {
        $threwMidway = true;
    }
    T::check($threwMidway, 'Fehler beim Eintragen wird gemeldet');
    T::same(0, (int) $db->get_var("SELECT COUNT(*) FROM cms_settings WHERE option_name = 'capabilities_v27'"), 'Marker erst nach dem vollständigen Eintragen (Fehler mittendrin: kein Marker)');
    $db->sqlite->exec('DROP TRIGGER cms_role_permissions_abort');
    T::same(1, count(array_filter(Caps::migrate($db), static fn (array $pair): bool => $pair[1] === 'community.moderate')), 'Wiederholung (Datenbank-Update) trägt das fehlende Recht ein, ohne Vorhandenes zu verdoppeln');
    T::same(9, (int) $db->get_var('SELECT COUNT(*) FROM cms_role_permissions'), 'danach vollständig: 9 Zeilen');

    // Die Tabelle fehlt: der Schritt legt sie selbst an (update.php lädt roles.php nicht) und läuft durch.
    $db = v27Database();
    $db->sqlite->exec('DROP TABLE cms_role_permissions');
    T::same(9, count(Caps::migrate($db)), 'fehlende Tabelle role_permissions wird angelegt, danach 9 Zeilen');
    T::same(1, (int) $db->get_var("SELECT COUNT(*) FROM cms_settings WHERE option_name = 'capabilities_v27'"), 'Marker gesetzt, nachdem die Tabelle angelegt wurde');

    // Nicht lesbare/unpassende Tabelle: Fehler an den Aufrufer, kein Marker.
    $db = v27Database();
    $db->sqlite->exec('DROP TABLE cms_role_permissions');
    $db->sqlite->exec('CREATE TABLE cms_role_permissions (id INTEGER PRIMARY KEY)');
    $threw = false;
    try {
        Caps::migrate($db);
    } catch (\Throwable) {
        $threw = true;
    }
    T::check($threw, 'Datenbankfehler wird an den Aufrufer gemeldet');
    T::same(0, (int) $db->get_var("SELECT COUNT(*) FROM cms_settings WHERE option_name = 'capabilities_v27'"), 'ohne erfolgreiche Vergabe kein Marker (Datenbank-Update meldet ihn und wiederholt den Schritt)');
});

T::run('Einbindung (Quelltext)', static function () use ($src, $migrationSource, $schemaSource): void {
    $caps = $src('core/Services/Community/Support/CommunityCapabilities.php');
    T::check(!preg_match('/Database::instance\(\)|Database::get_instance\(\)/', $caps), 'CommunityCapabilities ruft Database::instance() nicht auf (DB-410-02)');
    T::check(str_contains($caps, 'INSERT IGNORE INTO') && str_contains($caps, 'VALUES (?, ?, 1)'), 'INSERT IGNORE auf den eindeutigen Schlüssel uk_role_cap (kein SELECT-dann-INSERT-Race)');
    T::check(str_contains($caps, "public const MARKER = 'capabilities_v27';"), 'Marker heißt capabilities_v27');
    // role_permissions: gleiche Definition wie cms_ensure_role_permissions_table() (update.php lädt roles.php nicht).
    $normalize = static fn (string $text): string => trim((string) preg_replace('/\s+/', ' ', $text));
    $rolesSource = $src('includes/functions/roles.php');
    T::check(preg_match('/CREATE TABLE IF NOT EXISTS \{\$db->prefix\(\)\}role_permissions \((.*?)\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/s', $rolesSource, $definitionA) === 1
        && preg_match('/CREATE TABLE IF NOT EXISTS \{\$table\} \((.*?)\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/s', $caps, $definitionB) === 1
        && $normalize($definitionA[1]) === $normalize($definitionB[1]), 'CommunityCapabilities legt role_permissions mit derselben Definition an wie roles.php');
    $runner = $src('core/DatabaseUpdateRunner.php');
    T::check(str_contains($runner, "'option:' . \\CMS\\Services\\Community\\Support\\CommunityCapabilities::MARKER"), 'Datenbank-Update meldet einen fehlenden Marker capabilities_v27 (option:capabilities_v27)');
    T::check(str_contains($src('core/Auth/SSO/EntraSettings.php'), "in_array(\$defaultRole, ['admin', 'moderator'], true)"), 'SSO: Standardrolle nie admin oder moderator');
    T::check(strpos($caps, 'INSERT IGNORE INTO') < strpos($caps, 'self::MARKER, gmdate'), 'der Marker wird erst nach der Vergabe geschrieben');
    T::check(str_contains($migrationSource, '\\CMS\\Services\\Community\\Support\\CommunityCapabilities::migrate($this->db);'), 'MigrationManager::run() führt capabilities_v27 aus (Upgrade)');
    T::check(str_contains($schemaSource, '\\CMS\\Services\\Community\\Support\\CommunityCapabilities::migrate($this->db);'), 'ensureFeatureSchema() führt capabilities_v27 aus (Neuinstallation, Laufzeit)');
    T::check(strpos($migrationSource, 'CommunityCapabilities::migrate') < strpos($migrationSource, '$this->saveVersion(self::SCHEMA_VERSION);'), 'MigrationManager: capabilities_v27 vor dem Schreiben der Marke');
    T::check(str_contains($migrationSource, "error_log('MigrationManager: capabilities_v27: '"), 'MigrationManager protokolliert Fehler des Schritts');
    $runner = $src('core/DatabaseUpdateRunner.php');
    T::check(str_contains($runner, 'SchemaManager::FEATURE_TABLES, SchemaManager::FEATURE_TABLES_V26, SchemaManager::FEATURE_TABLES_V27, array_keys(SchemaManager::REQUIRED_COLUMNS)'), 'Datenbank-Update prüft die Community-Tabellen');
    T::check(str_contains($src('includes/functions/roles.php'), "in_array(\$role, ['admin', 'editor', 'author', 'member', 'moderator'], true)"), 'remove_role() schützt die Systemrolle moderator');
});

T::run('Dokumentation stimmt mit dem Schema überein', static function () use ($root, $queries): void {
    $repo = dirname($root);
    $handoff = (string) @file_get_contents($repo . '/DOC/workflow/specs/community/HANDOFF-PR2-PR5.md');
    $schemaDoc = (string) @file_get_contents($repo . '/DOC/core/DATABASE-SCHEMA.md');
    T::check($handoff !== '', 'HANDOFF-PR2-PR5.md vorhanden');
    foreach (V27_TABLES as $table) {
        T::check(str_contains($schemaDoc, "`{$table}`"), "DATABASE-SCHEMA.md nennt {$table}");
        // Abschnitt der Tabelle im Briefing: „#### `community_x`“ bis zur nächsten Überschrift.
        $found = preg_match('/^#### `' . preg_quote($table, '/') . '`[^\n]*\n(.*?)(?=^#{2,4} |\z)/ms', $handoff, $section) === 1;
        T::check($found, "HANDOFF hat einen Abschnitt für {$table}");
        $text = $section[1] ?? '';
        foreach (array_keys(v27Columns((string) $queries[$table])) as $column) {
            T::check(str_contains($text, "`{$column}`"), "HANDOFF {$table}: Spalte {$column}");
        }
        foreach (v27Indexes((string) $queries[$table]) as $name => $index) {
            T::check(str_contains($text, "`{$name}`"), "HANDOFF {$table}: Index {$name}");
        }
    }
    foreach (Caps::ALL as $capability) {
        T::check(str_contains($handoff, "`{$capability}`"), "HANDOFF nennt {$capability}");
    }
    T::check(str_contains($handoff, '`' . Caps::MARKER . '`') && str_contains($handoff, '`moderator`'), 'HANDOFF nennt den Marker capabilities_v27 und die Rolle moderator');
    T::check(str_contains($schemaDoc, 'The base schema (117 tables)'), 'DATABASE-SCHEMA.md nennt 117 Tabellen');
    foreach (['FILELIST.md', 'FILESTRUCTUR.md'] as $inventory) {
        T::check(str_contains((string) file_get_contents($repo . '/DOC/' . $inventory), '`TESTS/schema-v27/`'), "DOC/{$inventory} nennt TESTS/schema-v27");
    }
});

T::finish();
