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
        T::check(!preg_match('/\bJSON\b(?!_)/', $sql), "{$table}: kein Spaltentyp JSON (LONGTEXT *_json, MariaDB)");
        T::check(!preg_match('/FOREIGN\s+KEY|REFERENCES\s/i', $sql), "{$table}: keine Fremdschlüssel (Konsistenz über die Dienste, wie der übrige Core)");
        T::check(!preg_match('/legacy_|bbcode/i', $sql), "{$table}: keine legacy_*-Spalten und kein BBCode (E6: keine Datenübernahme aus cms-forum)");
        T::check(str_contains($sql, "COMMENT='Community: "), "{$table}: Tabellenkommentar");
    }
    T::check(str_contains($migrationSource, 'array_merge(SchemaManager::FEATURE_TABLES, SchemaManager::FEATURE_TABLES_V26, SchemaManager::FEATURE_TABLES_V27)'), 'Update legt die v27-Tabellen über dieselben Definitionen an');
    T::check(str_contains($schemaSource, 'array_merge(self::FEATURE_TABLES, self::FEATURE_TABLES_V26, self::FEATURE_TABLES_V27)'), 'Laufzeitprüfung legt die v27-Tabellen über dieselben Definitionen an');
    T::check(str_contains($schemaSource, 'FEATURE_TABLES_V27 = ['), 'Liste ist eine Konstante des SchemaManagers');
    // v27 ändert keine bestehende Tabelle: keine ALTER-Schritte für die Community im Update.
    T::check(!preg_match('/ALTER TABLE[^\n]*community_/i', $migrationSource . $schemaSource), 'keine ALTER-Schritte: die Tabellen entstehen vollständig über CREATE');
});

T::run('Strict-Mode: keine NOT-NULL-Spalte ohne Default', static function () use ($queries): void {
    $checked = 0;
    foreach (V27_TABLES as $table) {
        foreach (v27Columns((string) $queries[$table]) as $column => $definition) {
            $checked++;
            if (stripos($definition, 'AUTO_INCREMENT') !== false) {
                continue;
            }
            if (stripos($definition, 'NOT NULL') !== false) {
                T::check(stripos($definition, ' DEFAULT ') !== false, "{$table}.{$column}: NOT NULL hat einen Default", $definition);
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
        T::check(preg_match('/(PRIMARY KEY|AUTO_INCREMENT PRIMARY KEY)/', $sql) === 1, "{$table}: hat einen Primärschlüssel");
        foreach ($indexes as $name => $index) {
            T::check(strlen($name) <= 64, "{$table}.{$name}: Bezeichner höchstens 64 Zeichen");
            T::check(str_starts_with($name, $index['unique'] ? 'uq_' : 'idx_') && str_contains($name, substr($table, 10)), "{$table}.{$name}: Name nach Schema uq_/idx_ + Tabelle");
            T::check(!isset($names[$name]), "{$table}.{$name}: Indexname im ganzen Schema eindeutig (wie v26)");
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
        ],
        'community_posts' => [
            'idx_community_posts_topic' => ['topic_id', 'status', 'id'],
            'idx_community_posts_queue' => ['status', 'created_at'],
            'idx_community_posts_forum_time' => ['forum_id', 'status', 'created_at'],
            'idx_community_posts_created' => ['created_at'],
        ],
        'community_read_states' => ['idx_community_read_states_cleanup' => ['updated_at']],
        'community_attachments' => ['idx_community_attachments_post' => ['post_id', 'created_at']],
        'community_drafts' => ['idx_community_drafts_updated' => ['updated_at']],
        'community_reports' => ['idx_community_reports_open' => ['status', 'created_at'], 'idx_community_reports_forum' => ['forum_id', 'status', 'created_at']],
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
    foreach (['community_forums' => 'uq_community_forums_slug', 'community_polls' => 'uq_community_polls_topic', 'community_badges' => 'uq_community_badges_slug', 'community_forum_permissions' => 'uq_community_forum_permissions_subject'] as $table => $name) {
        T::check(v27Indexes((string) $queries[$table])[$name]['unique'] ?? false, "{$table}.{$name} ist eindeutig");
    }
    T::check(str_contains((string) $queries['community_posts'], 'PRIMARY KEY') && str_contains((string) $queries['community_posts'], 'ip_hash CHAR(64) NULL'), 'Beiträge: ip_hash nur als Hash (keine Klar-IP), NULL-fähig für die Löschfrist');
    T::check(!preg_match('/\bip(_address)?\s+(VAR)?CHAR/i', implode("\n", array_intersect_key($queries, array_flip(V27_TABLES)))), 'keine Klar-IP-Spalte in den Community-Tabellen (SECURITY-PRIVACY §5)');
});

T::run('Spaltenumfang (DATA-MODEL.md)', static function () use ($queries): void {
    $expected = [
        'community_topics' => ['id', 'forum_id', 'user_id', 'title', 'slug', 'status', 'moved_to_topic_id', 'moved_expires_at', 'is_pinned', 'pinned_until', 'is_announcement', 'is_locked', 'locked_until', 'slow_mode_seconds', 'prefix', 'first_post_id', 'solution_post_id', 'solved_at', 'solved_by', 'has_poll', 'has_attachments', 'reply_count', 'view_count', 'reaction_count', 'last_post_id', 'last_post_at', 'last_poster_id', 'version', 'trashed_at', 'previous_status', 'created_at', 'updated_at'],
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
    T::same(267, $total, 'insgesamt 267 Spalten in den 27 Tabellen');
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
    T::same(array_merge(Caps::MODERATOR_BASE, Caps::DEFAULT_GRANTS['moderator']), array_keys($loaded), 'moderator: genau Grundrechte und Community-Standards');
});

T::run('capabilities_v27: Vergabe, Marker, Idempotenz', static function (): void {
    $expectedAll = [];
    foreach (Caps::DEFAULT_GRANTS as $role => $capabilities) {
        if ($role === 'moderator') {
            $capabilities = array_merge(Caps::MODERATOR_BASE, $capabilities);
        }
        foreach ($capabilities as $capability) {
            $expectedAll[] = [$role, $capability];
        }
    }

    $db = v27Database();
    $granted = Caps::migrate($db);
    T::same($expectedAll, $granted, 'Neuinstallation: Standardrechte und neue Rolle moderator mit Grundrechten');
    T::same(2 + 2 + 2 + 10, count($granted), '16 Zeilen: member 2, editor 2, author 2, moderator 7 + 3');
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
    foreach (['member', 'editor', 'author'] as $role) {
        T::check(!isset($rows[$role]['community.moderate']), "{$role}: kein community.moderate");
    }

    // Läuft der Schritt erneut (Marker entfernt, z. B. „Datenbank reparieren“ nach manuellem Löschen), entsteht kein Duplikat.
    $db->execute("DELETE FROM cms_settings WHERE option_name = 'capabilities_v27'");
    T::same([], Caps::migrate($db), 'ohne Marker, aber mit vorhandenen Zeilen: nichts Neues, Marker wird wieder gesetzt');
    T::same(1, (int) $db->get_var("SELECT COUNT(*) FROM cms_settings WHERE option_name = 'capabilities_v27'"), 'Marker wieder gesetzt');
    T::same(16, (int) $db->get_var('SELECT COUNT(*) FROM cms_role_permissions'), 'weiterhin 16 Zeilen (INSERT IGNORE)');
});

T::run('capabilities_v27: überschreibt nie, nimmt nie zurück', static function (): void {
    // Ausdrücklich entzogenes Recht, vorhandene Rolle moderator (Grundrechte nur teilweise, ein Recht entzogen).
    $db = v27Database();
    $db->execute("INSERT INTO cms_role_permissions (role, capability, granted) VALUES ('member', 'community.participate', 0), ('moderator', 'community.moderate', 0), ('moderator', 'comments.view', 1), ('editor', 'community.view', 1)");
    $granted = Caps::migrate($db);
    $rows = v27Granted($db);
    T::same(0, $rows['member']['community.participate'], 'entzogenes Recht bleibt entzogen (granted = 0)');
    T::same(1, $rows['member']['community.view'], 'daneben wird das fehlende Standardrecht vergeben');
    T::same(0, $rows['moderator']['community.moderate'], 'vorhandene Rolle moderator: Entzug bleibt');
    T::same(1, $rows['moderator']['community.view'], 'vorhandene Rolle moderator: Standardrecht ergänzt');
    T::check(!isset($rows['moderator']['read']), 'vorhandene Rolle moderator erhält keine Grundrechte nachgetragen');
    T::check(!in_array(['editor', 'community.view'], $granted, true) && in_array(['editor', 'community.participate'], $granted, true), 'vorhandenes Recht wird nicht erneut gemeldet');

    // Konten mit der Rolle moderator, aber noch keine Zeilen: ebenfalls vorhandene Rolle.
    $withUsers = v27Database();
    $withUsers->execute("INSERT INTO cms_users (id, username, role) VALUES (1, 'mo', 'moderator')");
    Caps::migrate($withUsers);
    T::check(!isset(v27Granted($withUsers)['moderator']['read']), 'Konto mit Rolle moderator: Rolle gilt als vorhanden, keine Grundrechte nachgetragen');
    T::same(1, v27Granted($withUsers)['moderator']['community.moderate'] ?? null, 'Konto mit Rolle moderator: Community-Standards trotzdem vergeben');
});

T::run('capabilities_v27: Fehler setzen keinen Marker', static function (): void {
    $db = v27Database();
    $db->sqlite->exec('DROP TABLE cms_role_permissions');
    $threw = false;
    try {
        Caps::migrate($db);
    } catch (\Throwable) {
        $threw = true;
    }
    T::check($threw, 'Datenbankfehler wird an den Aufrufer gemeldet');
    T::same(0, (int) $db->get_var("SELECT COUNT(*) FROM cms_settings WHERE option_name = 'capabilities_v27'"), 'ohne erfolgreiche Vergabe kein Marker (der Schritt läuft beim nächsten Durchlauf erneut)');
});

T::run('Einbindung (Quelltext)', static function () use ($src, $migrationSource, $schemaSource): void {
    $caps = $src('core/Services/Community/Support/CommunityCapabilities.php');
    T::check(!preg_match('/Database::instance\(\)|Database::get_instance\(\)/', $caps), 'CommunityCapabilities ruft Database::instance() nicht auf (DB-410-02)');
    T::check(str_contains($caps, 'INSERT IGNORE INTO') && str_contains($caps, 'VALUES (?, ?, 1)'), 'INSERT IGNORE auf den eindeutigen Schlüssel uk_role_cap (kein SELECT-dann-INSERT-Race)');
    T::check(str_contains($caps, "public const MARKER = 'capabilities_v27';"), 'Marker heißt capabilities_v27');
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
