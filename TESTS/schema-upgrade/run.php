<?php
/**
 * Upgrade-Pfad gegen eine echte MySQL/MariaDB (Release-Prüfung 4.1.0, PR #2): Neuinstallation v26 und Updates von
 * 4.0.0 (Schema v25, Paket RELEASE/365CMS-4.0.0-full.zip) sowie – wenn der Git-Tag V3.5.00 vorhanden ist – von 3.5.x
 * (Schema v23) müssen dieselbe Struktur ergeben (Tabellen, Spalten, Typen, Defaults, Indizes, Fremdschlüssel), Daten
 * erhalten, idempotent laufen und beim ersten Request nur eine Verbindung öffnen (DB-410-02).
 * v27 (4.2.0): zusätzlich Update von 4.1.8 (v26), Kommentar und Kollation von notifications.group_key/event_count auf allen Wegen
 * (Neuinstallation, Updates, nur Laufzeitprüfung, Reparatur, Entwicklungsinstallation aus PR #1 nach Update von 4.0.0 oder 4.1.8)
 * und der Weg „Entwicklungsinstallation aus PR #1“ (nach PR #1 ergänzte Indizes und Spalten sowie abweichende notifications-Spalten
 * kommen über ensureFeatureSchema(), ein zweiter Lauf ändert nichts), dazu ein gescheiterter Nachtrag der Laufzeitprüfung (die
 * Integritätsprüfung meldet ihn, das Datenbank-Update holt ihn nach) und ein Eintrag in REQUIRED_INDEXES für eine weitere Tabelle
 * (Kopie des Codes: ensureFeatureSchema() legt ihn an, auch auf einer im selben Lauf nachgetragenen Spalte). Nach Neuinstallation
 * und jedem Update ist jeder Index aus REQUIRED_INDEXES vorhanden.
 *
 * Optional: läuft nur mit CMS_TEST_DB_HOST und CMS_TEST_DB_USER (Recht CREATE/DROP DATABASE), sonst Hinweis. Datenbanken heißen
 * `cmsup<pid>_…` bzw. mit CMS_TEST_DB_PREFIX `<präfix><pid>_…`; aufgeräumt werden am Ende nur die mit diesem Anfang.
 *   CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/schema-upgrade/run.php [-v]
 * Optional CMS_TEST_SQL_MODE (z. B. MySQL-8-Standard mit ONLY_FULL_GROUP_BY) für die Sitzungen der Prüfung.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/schema-v27/lib.php';
require_once dirname(__DIR__) . '/lib/TestDatabasePrefix.php';
T::$verbose = in_array('-v', $argv ?? [], true);

$repo = dirname(__DIR__, 2);
$host = (string) getenv('CMS_TEST_DB_HOST');
$user = (string) getenv('CMS_TEST_DB_USER');
$pass = (string) getenv('CMS_TEST_DB_PASS');

if ($host === '' || $user === '' || !extension_loaded('pdo_mysql') || !class_exists(ZipArchive::class)) {
    T::run('Upgrade-Pfad gegen MySQL/MariaDB', static function (): void {
        T::info('übersprungen: CMS_TEST_DB_HOST/CMS_TEST_DB_USER nicht gesetzt (oder pdo_mysql/zip fehlt)');
    });
    T::finish();
}

$pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// Zielmarke = Stand des Codes (Release 4.2.0, Entwicklungsstand: v27); Ausgangsstände sind die ausgelieferten Pakete.
$target = preg_match("/public const SCHEMA_VERSION = '(v\d+)';/", (string) file_get_contents($repo . '/CMS/core/SchemaManager.php'), $targetMatch) === 1 ? $targetMatch[1] : 'v?';
$tmp = sys_get_temp_dir() . '/cms-schema-upgrade-' . getmypid();
$prefix = TestDatabasePrefix::resolve('cmsup') . getmypid() . '_';

$copyTree = static function (string $from, string $to): void {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    @mkdir($to, 0755, true);
    foreach ($iterator as $item) {
        $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
        if ($item->isDir()) {
            @mkdir($target, 0755, true);
        } elseif (!str_contains($target, '/uploads/') && !str_contains($target, '/cache/')) {
            copy($item->getPathname(), $target);
        }
    }
};
$removeTree = static function (string $dir) use (&$removeTree): void {
    foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            is_dir($dir . '/' . $entry) && !is_link($dir . '/' . $entry) ? $removeTree($dir . '/' . $entry) : @unlink($dir . '/' . $entry);
        }
    }
    @rmdir($dir);
};
$step = static function (string $root, string $db, string $mode): array {
    $env = '';
    foreach (['CMS_TEST_DB_HOST', 'CMS_TEST_DB_USER', 'CMS_TEST_DB_PASS'] as $name) {
        $env .= $name . '=' . escapeshellarg((string) getenv($name)) . ' ';
    }
    $line = (string) shell_exec($env . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/harness.php') . ' '
        . escapeshellarg($root) . ' ' . escapeshellarg($db) . ' ' . escapeshellarg($mode) . ' 2>&1');
    $decoded = json_decode(trim((string) strrchr("\n" . trim($line), "\n")), true);

    return is_array($decoded) ? $decoded : ['errors' => [trim($line)]];
};
$createDb = static function (string $name) use ($pdo): void {
    $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
    $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
};
$structure = static function (string $db) use ($pdo): array {
    $rows = [];
    $q = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $s = $pdo->quote($db);
    array_push($rows, ...$q("SELECT CONCAT('T ', TABLE_NAME, ' ', ENGINE) FROM information_schema.TABLES WHERE TABLE_SCHEMA = {$s}"));
    array_push($rows, ...$q("SELECT CONCAT('C ', TABLE_NAME, '.', COLUMN_NAME, ' ', COLUMN_TYPE, ' null=', IS_NULLABLE, ' def=', IFNULL(COLUMN_DEFAULT, '<NULL>'), ' ', EXTRA) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = {$s}"));
    array_push($rows, ...$q("SELECT CONCAT('I ', TABLE_NAME, '.', INDEX_NAME, ' uniq=', 1 - NON_UNIQUE, ' (', GROUP_CONCAT(CONCAT(COLUMN_NAME, IFNULL(CONCAT('(', SUB_PART, ')'), '')) ORDER BY SEQ_IN_INDEX), ')') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = {$s} GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE"));
    array_push($rows, ...$q("SELECT CONCAT('F ', TABLE_NAME, '.', CONSTRAINT_NAME, ' ', COLUMN_NAME, '->', REFERENCED_TABLE_NAME, '.', REFERENCED_COLUMN_NAME) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = {$s} AND REFERENCED_TABLE_NAME IS NOT NULL"));
    sort($rows);

    return $rows;
};
// Snapshot der 28 Community-Tabellen (TESTS/schema-v27/golden.json): die Datenbank muss ihm entsprechen (Spalten mit Typ, NULL,
// Default, Primärschlüssel, Indizes), nicht nur der Neuinstallation.
$golden = json_decode((string) file_get_contents(__DIR__ . '/../schema-v27/golden.json'), true) ?: [];
$compareGolden = static function (PDO $pdo, string $db) use ($golden): void {
    $actual = v27ReadStructure($pdo, $db, 'cms_', array_keys($golden));
    T::same(28, count($golden), 'Snapshot golden.json enthält 28 Tabellen');
    foreach ($golden as $table => $expected) {
        T::same($expected['columns'], $actual[$table]['columns'], "{$table}: Spalten entsprechen dem Snapshot");
        T::same($expected['primary'], $actual[$table]['primary'], "{$table}: Primärschlüssel entspricht dem Snapshot");
        T::same($expected['indexes'], $actual[$table]['indexes'], "{$table}: Indizes entsprechen dem Snapshot");
    }
};
// notifications.group_key/event_count: auf jedem Weg (Neuinstallation, Update, Laufzeitprüfung, Reparatur) dieselbe Definition wie
// im CREATE, auch Spaltenkommentar und Kollation. $structure liest beides nicht (die Spaltenposition bleibt bewusst außen vor:
// ADD COLUMN ohne AFTER hängt an, wie bei den v26-Spalten von orders und posts). Tabellen aus 4.0.0 tragen die Standardkollation
// des Servers (MariaDB und MySQL 5.7: utf8mb4_general_ci, MySQL 8: utf8mb4_0900_ai_ci); group_key erhält trotzdem utf8mb4_unicode_ci.
$expectedNotificationColumns = [
    'event_count' => ['int unsigned', 'NO', '1', null, null, 'Anzahl gebündelter Ereignisse'],
    'group_key' => ['varchar(120)', 'YES', null, 'utf8mb4', 'utf8mb4_unicode_ci', 'Bündelung gleichartiger Hinweise, z. B. community:topic:12'],
];
$notificationColumns = static function (string $db) use ($pdo): array {
    $statement = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'cms_notifications' AND COLUMN_NAME IN ('group_key', 'event_count') ORDER BY COLUMN_NAME");
    $statement->execute([$db]);
    $out = [];
    foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$name, $type, $nullable, $default, $charset, $collation, $comment]) {
        $out[(string) $name] = [v27CanonType((string) $type), (string) $nullable, v27CanonDefault($default), $charset, $collation, (string) $comment];
    }

    return $out;
};
// Indizes aus SchemaManager::REQUIRED_INDEXES in der Datenbank (Name => Spalten in Indexreihenfolge, leer = fehlt).
if (!defined('ABSPATH')) {
    define('ABSPATH', $repo . '/CMS/');
}
require_once $repo . '/CMS/core/SchemaManager.php';
$requiredIndexes = static function (string $db, array $required = \CMS\SchemaManager::REQUIRED_INDEXES) use ($pdo): array {
    $statement = $pdo->prepare('SELECT LOWER(COLUMN_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX');
    $out = [];
    foreach ($required as $table => $indexes) {
        foreach (array_keys($indexes) as $name) {
            $statement->execute([$db, 'cms_' . $table, $name]);
            $out[$table][$name] = $statement->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    return $out;
};
$connections = static fn (): int => (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Connections'")->fetch(PDO::FETCH_NUM)[1];

$sqlMode = (string) getenv('CMS_TEST_SQL_MODE');
$previousMode = null;
if ($sqlMode !== '') {
    $previousMode = (string) $pdo->query('SELECT @@GLOBAL.sql_mode')->fetchColumn();
    $pdo->exec('SET GLOBAL sql_mode = ' . $pdo->quote($sqlMode));
}

try {
    // Quellen: aktueller Stand, ausgeliefertes 4.0.0, optional 3.5.x aus dem Git-Tag.
    $copyTree($repo . '/CMS', $tmp . '/v41');
    $zip = new ZipArchive();
    $sources = [];
    if ($zip->open($repo . '/RELEASE/365CMS-4.0.0-full.zip') === true) {
        $zip->extractTo($tmp . '/v40');
        $zip->close();
        $sources['4.0.0 (v25)'] = $tmp . '/v40/365CMS-4.0.0/CMS';
    } elseif (trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse -q --verify V4.0.0 2>/dev/null')) !== '') {
        // Das Paket liegt nicht mehr unter RELEASE/ (nur der aktuelle Stand wird dort gehalten): Ausgangsstand aus dem Git-Tag.
        @mkdir($tmp . '/v40tag', 0755, true);
        shell_exec('git -C ' . escapeshellarg($repo) . ' archive V4.0.0 CMS | tar -x -C ' . escapeshellarg($tmp . '/v40tag'));
        if (is_dir($tmp . '/v40tag/CMS')) {
            $sources['4.0.0 (v25)'] = $tmp . '/v40tag/CMS';
        }
    }
    // Schritt v26 → v27 (Community-Modul): das zuletzt ausgelieferte Paket vor der Schema-Anhebung.
    if ($zip->open($repo . '/RELEASE/365CMS-4.1.8-full.zip') === true) {
        $zip->extractTo($tmp . '/v418');
        $zip->close();
        $sources['4.1.8 (v26)'] = $tmp . '/v418/365CMS-4.1.8/CMS';
    }
    $tagCheck = (string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse -q --verify V3.5.00 2>/dev/null');
    if (trim($tagCheck) !== '') {
        @mkdir($tmp . '/v35', 0755, true);
        shell_exec('git -C ' . escapeshellarg($repo) . ' archive V3.5.00 CMS | tar -x -C ' . escapeshellarg($tmp . '/v35'));
        $sources['3.5.x (v23)'] = $tmp . '/v35/CMS';
    }

    $fresh = $prefix . 'fresh';
    // Erwartete Rechte nach capabilities_v27 (CommunityCapabilities::DEFAULT_GRANTS); „admin“ hat alles implizit.
    $communityGrants = ['member' => ['community.view', 'community.participate'], 'editor' => ['community.view', 'community.participate'], 'author' => ['community.view', 'community.participate'], 'moderator' => ['community.view', 'community.participate', 'community.moderate']];
    $communityCapabilities = static function (PDO $pdo, string $db): array {
        $rows = $pdo->query("SELECT role, capability, granted FROM `{$db}`.cms_role_permissions WHERE capability LIKE 'community.%' ORDER BY role, capability")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[$row['role']][$row['capability']] = (int) $row['granted'];
        }

        return $out;
    };

    T::run('Neuinstallation ' . $target, static function () use ($createDb, $step, $tmp, $fresh, $connections, $target, $pdo, $communityGrants, $communityCapabilities, $compareGolden, $expectedNotificationColumns, $notificationColumns, $requiredIndexes): void {
        $createDb($fresh);
        $result = $step($tmp . '/v41', $fresh, 'install');
        T::same([], $result['install_errors'] ?? ['?'], 'alle CREATE-Statements ohne Fehler');
        T::same($target, $result['schema'] ?? '', 'Schema-Marke ' . $target);
        T::same([], $result['errors'] ?? ['?'], 'keine Fehler im Log');
        $before = $connections();
        $boot = $step($tmp . '/v41', $fresh, 'boot');
        T::check($connections() - $before <= 2, 'DB-410-02: erster Request öffnet nur eine Verbindung (keine Rekursion im Konstruktor)', (string) ($connections() - $before));
        T::same([], $boot['errors'] ?? ['?'], 'erster Request ohne Fehler');
        T::same([], $step($tmp . '/v41', $fresh, 'status')['missing'] ?? ['?'], 'Integritätsprüfung: nichts fehlt');
        $compareGolden($pdo, $fresh);
        T::same($expectedNotificationColumns, $notificationColumns($fresh), 'notifications.group_key/event_count: Typ, NULL, Default, Zeichensatz, Kollation und Kommentar wie im CREATE');
        T::same(\CMS\SchemaManager::REQUIRED_INDEXES, $requiredIndexes($fresh), 'jeder Index aus REQUIRED_INDEXES vorhanden, mit den Spalten aus REQUIRED_INDEXES');

        // capabilities_v27: Marker, Standardrechte und Rolle „moderator“ nach dem ersten Request der Neuinstallation.
        T::same(1, (int) $pdo->query("SELECT COUNT(*) FROM `{$fresh}`.cms_settings WHERE option_name = 'capabilities_v27'")->fetchColumn(), 'Marker capabilities_v27 gesetzt');
        $granted = $communityCapabilities($pdo, $fresh);
        foreach ($communityGrants as $role => $capabilities) {
            foreach ($capabilities as $capability) {
                T::same(1, $granted[$role][$capability] ?? null, "{$role}: {$capability} vergeben");
            }
        }
        T::same([], array_values(array_intersect(array_keys($granted['member'] ?? []), ['community.moderate', 'community.ban', 'community.manage', 'community.view_private_data'])), 'Mitglieder erhalten keine Moderations- oder Verwaltungsrechte');
        T::check(isset($granted['moderator']) && !isset($granted['moderator']['community.ban']) && !isset($granted['moderator']['community.manage']), 'moderator erhält kein ban/manage');
        // Keine Zeilen außerhalb des Rollen-Katalogs: sonst erschienen read/edit_profile/view_content im Rollen-Editor als
        // „eigene Rechte“, und das Speichern der Matrix entzöge sie Mitglied, Editor und Autor.
        T::same(['author:posts.publish', 'editor:pages.publish', 'editor:posts.publish'], $pdo->query("SELECT CONCAT(role, ':', capability) FROM `{$fresh}`.cms_role_permissions WHERE capability NOT LIKE 'community.%' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN), 'außer community.* nur die drei Rechte aus capabilities_v26 (Grundrechte der Rolle moderator kommen aus roles.php)');
        T::same(9, (int) $pdo->query("SELECT COUNT(*) FROM `{$fresh}`.cms_role_permissions WHERE capability LIKE 'community.%'")->fetchColumn(), 'genau 9 Zeilen community.*: member 2, editor 2, author 2, moderator 3');
    });
    $freshStructure = $structure($fresh);

    foreach ($sources as $label => $oldRoot) {
        $db = $prefix . preg_replace('/[^a-z0-9]/', '', strtolower($label));
        T::run('Upgrade ' . $label . ' → ' . $target, static function () use ($createDb, $step, $tmp, $db, $oldRoot, $pdo, $structure, $freshStructure, $label, $target, $communityGrants, $communityCapabilities, $compareGolden, $expectedNotificationColumns, $notificationColumns, $requiredIndexes): void {
            $createDb($db);
            $old = $step($oldRoot, $db, 'install');
            T::same([], $old['install_errors'] ?? ['?'], $label . ': Ausgangsstand installiert');
            $pdo->exec("INSERT INTO `{$db}`.cms_posts (title, slug, content, status, author_id, created_at, published_at)
                        VALUES ('Alt veröffentlicht', 'alt-1', '{}', 'published', 1, NOW(), NULL), ('Alt Papierkorb', 'alt-2', '{}', 'trash', 1, NOW(), NULL)");

            // v27 ergänzt notifications um group_key und event_count: eine vorhandene Zeile bekommt keinen Schlüssel und den
            // Zähler 1 (Fremdschlüssel ausgeschaltet, weil der Ausgangsstand keinen Benutzer mit dieser ID hat).
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->exec("INSERT INTO `{$db}`.cms_notifications (user_id, type, title, message) VALUES (1, 'system', 'Alt-Hinweis', 'vor v27')");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            // capabilities_v27 überschreibt nie: ein vorher ausdrücklich entzogenes Recht und eine bereits vorhandene
            // Rolle „moderator“ bleiben, wie sie sind.
            // role_permissions legt erst der Core der jeweiligen Version an (4.0.0: lazy); gleiche Definition wie roles.php.
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.cms_role_permissions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, role VARCHAR(50) NOT NULL, capability VARCHAR(100) NOT NULL, granted TINYINT(1) NOT NULL DEFAULT 0, UNIQUE KEY uk_role_cap (role, capability)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("INSERT INTO `{$db}`.cms_role_permissions (role, capability, granted) VALUES ('member', 'community.participate', 0), ('moderator', 'community.moderate', 0), ('moderator', 'comments.view', 1)");

            $boot = $step($tmp . '/v41', $db, 'boot');
            T::same($target, $boot['schema'] ?? '', 'erster Request hebt die Schema-Marke auf ' . $target);
            T::same([], $boot['errors'] ?? ['?'], 'automatische Migration ohne Fehler');
            $update = $step($tmp . '/v41', $db, 'update');
            T::check(!empty($update['success']), 'Datenbank-Update meldet Erfolg', (string) ($update['message'] ?? ''));
            T::same([], $step($tmp . '/v41', $db, 'status')['missing'] ?? ['?'], 'Integritätsprüfung: nichts fehlt');

            T::same(1, (int) $pdo->query("SELECT COUNT(*) FROM `{$db}`.cms_settings WHERE option_name = 'capabilities_v27'")->fetchColumn(), 'Marker capabilities_v27 gesetzt');
            $granted = $communityCapabilities($pdo, $db);
            T::same(0, $granted['member']['community.participate'] ?? null, 'entzogenes Recht bleibt entzogen (granted = 0)');
            T::same(1, $granted['member']['community.view'] ?? null, 'member: community.view vergeben');
            T::same(0, $granted['moderator']['community.moderate'] ?? null, 'vorhandene Rolle moderator: Entzug bleibt');
            T::same(1, $granted['editor']['community.participate'] ?? null, 'editor: community.participate vergeben');
            T::same(0, (int) $pdo->query("SELECT COUNT(*) FROM `{$db}`.cms_role_permissions WHERE role = 'moderator' AND capability = 'read'")->fetchColumn(), 'vorhandene Rolle moderator: keine Zeilen für Grundrechte (sie kommen aus den Code-Standards)');

            $notification = $pdo->query("SELECT group_key, event_count FROM `{$db}`.cms_notifications WHERE title = 'Alt-Hinweis'")->fetch(PDO::FETCH_ASSOC);
            T::same(['group_key' => null, 'event_count' => '1'], is_array($notification) ? array_map(static fn ($v) => $v === null ? null : (string) $v, $notification) : null, 'notifications: Bestandszeile ohne Gruppenschlüssel, Zähler 1');
            T::same(3, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = " . $pdo->quote($db) . " AND TABLE_NAME = 'cms_notifications' AND INDEX_NAME = 'idx_user_group'")->fetchColumn(), 'notifications: Index idx_user_group (user_id, group_key, is_read) vorhanden');
            T::same($expectedNotificationColumns, $notificationColumns($db), 'notifications.group_key/event_count: Kommentar und Kollation (utf8mb4_unicode_ci) wie bei der Neuinstallation');
            T::same(\CMS\SchemaManager::REQUIRED_INDEXES, $requiredIndexes($db), 'jeder Index aus REQUIRED_INDEXES vorhanden, mit den Spalten aus REQUIRED_INDEXES');

            $compareGolden($pdo, $db);
            $upgraded = $structure($db);
            T::same([], array_values(array_diff($freshStructure, $upgraded)), 'nichts fehlt gegenüber der Neuinstallation');
            T::same([], array_values(array_diff($upgraded, $freshStructure)), 'nichts Zusätzliches gegenüber der Neuinstallation');

            $posts = $pdo->query("SELECT slug, status, published_at IS NOT NULL AS pub FROM `{$db}`.cms_posts WHERE slug LIKE 'alt-%' ORDER BY slug")->fetchAll(PDO::FETCH_ASSOC);
            T::same([['slug' => 'alt-1', 'status' => 'published', 'pub' => 1], ['slug' => 'alt-2', 'status' => 'trash', 'pub' => 0]], array_map(static fn (array $r): array => ['slug' => $r['slug'], 'status' => $r['status'], 'pub' => (int) $r['pub']], $posts), 'Beiträge erhalten, published_at nachgezogen');
            $pdo->exec("INSERT INTO `{$db}`.cms_posts (title, slug, content, status, author_id) VALUES ('Privat', 'neu-privat', '{}', 'private', 1)");
            T::same('private', (string) $pdo->query("SELECT status FROM `{$db}`.cms_posts WHERE slug = 'neu-privat'")->fetchColumn(), 'posts.status nimmt „private“ an (VARCHAR statt ENUM)');

            $before = $communityCapabilities($pdo, $db);
            $step($tmp . '/v41', $db, 'update');
            T::same($upgraded, $structure($db), 'zweites Datenbank-Update ändert nichts (idempotent)');
            T::same($before, $communityCapabilities($pdo, $db), 'zweites Datenbank-Update ändert die Rechte nicht (einmalig)');
        });
    }

    // Nur die Laufzeitprüfung (ensureFeatureSchema): Tabelle notifications aus 4.0.0 (Standardkollation des Servers), die Marke steht schon
    // auf der Zielmarke, MigrationManager::run() überspringt den Schritt also.
    T::run('Laufzeitprüfung: notifications aus 4.0.0, Marke schon ' . $target, static function () use ($createDb, $step, $tmp, $prefix, $pdo, $sources, $target, $expectedNotificationColumns, $notificationColumns): void {
        if (!isset($sources['4.0.0 (v25)'])) {
            T::info('übersprungen: Ausgangsstand 4.0.0 steht weder als Paket unter RELEASE/ noch als Git-Tag V4.0.0 zur Verfügung');

            return;
        }
        $db = $prefix . 'runtime';
        $createDb($db);
        $old = $step($sources['4.0.0 (v25)'], $db, 'install');
        T::same([], $old['install_errors'] ?? ['?'], '4.0.0: Ausgangsstand installiert');
        $tableCollation = (string) $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($db) . " AND TABLE_NAME = 'cms_notifications'")->fetchColumn();
        if ($tableCollation === 'utf8mb4_unicode_ci') {
            // Der Git-Tag V4.0.0 legt die Tabelle schon mit utf8mb4_unicode_ci an; die Ausgangslage des ausgelieferten Pakets (Standardkollation
            // des Servers) lässt sich daraus nicht herstellen. Ohne diese Voraussetzung sagt der Rest dieser Prüfung nichts aus.
            T::info('übersprungen: Ausgangslage mit Standardkollation nicht herstellbar (Quelle ist nicht das ausgelieferte Paket 4.0.0)');

            return;
        }
        T::check(str_starts_with($tableCollation, 'utf8mb4_') && $tableCollation !== 'utf8mb4_unicode_ci', 'Ausgangslage: notifications aus 4.0.0 mit der Standardkollation des Servers (MariaDB utf8mb4_general_ci, MySQL 8 utf8mb4_0900_ai_ci), nicht utf8mb4_unicode_ci', $tableCollation);
        $pdo->exec("UPDATE `{$db}`.cms_settings SET option_value = " . $pdo->quote($target) . " WHERE option_name = 'db_schema_version'");
        $boot = $step($tmp . '/v41', $db, 'boot');
        T::same($target, $boot['schema'] ?? '', 'Marke unverändert ' . $target . ' (MigrationManager lief nicht)');
        T::same($expectedNotificationColumns, $notificationColumns($db), 'ensureFeatureSchema(): group_key/event_count mit Kommentar, group_key mit utf8mb4_unicode_ci in einer Tabelle mit ' . $tableCollation);
        T::same(3, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = " . $pdo->quote($db) . " AND TABLE_NAME = 'cms_notifications' AND INDEX_NAME = 'idx_user_group'")->fetchColumn(), 'Index idx_user_group ergänzt');
    });

    // Entwicklungsstand aus PR #1: Marke v27, community_topics ohne idx_community_topics_unanswered_all, community_badges ohne
    // description_en; idx_community_topics_solved in einer der beiden früheren Formen des Nachlaufs: (forum_id, status, is_pinned,
    // solved_at, last_post_at) oder (forum_id, solved_at, status, is_pinned, last_post_at) aus Commit 5a400cf (HANDOFF §2 Nr. 9).
    // Der MigrationManager läuft dort nicht mehr; ensureFeatureSchema() muss nachtragen bzw. ersetzen, ein zweiter Lauf ändert nichts.
    foreach (['a' => ['forum_id', 'status', 'is_pinned', 'solved_at', 'last_post_at'], 'b' => ['forum_id', 'solved_at', 'status', 'is_pinned', 'last_post_at']] as $form => $oldSolved) {
        T::run('Entwicklungsinstallation ' . $target . ' aus PR #1 (idx_community_topics_solved als ' . implode(', ', $oldSolved) . '): Laufzeitprüfung ergänzt Indizes und Spalte', static function () use ($createDb, $step, $tmp, $prefix, $pdo, $structure, $freshStructure, $target, $compareGolden, $form, $oldSolved): void {
            $db = $prefix . 'devpr1' . $form;
            $createDb($db);
            $install = $step($tmp . '/v41', $db, 'install');
            T::same([], $install['install_errors'] ?? ['?'], 'Neuinstallation ' . $target);
            $quoted = $pdo->quote($db);
            $late = ['idx_community_topics_unanswered_all' => ['status', 'reply_count', 'created_at'], 'idx_community_topics_solved' => ['solved_at', 'forum_id', 'status', 'is_pinned', 'last_post_at']];
            T::same($late['idx_community_topics_solved'], \CMS\SchemaManager::REQUIRED_INDEXES['community_topics']['idx_community_topics_solved'] ?? null, 'Zielform von idx_community_topics_solved wie in REQUIRED_INDEXES');
            $topicIndexes = static function () use ($pdo, $quoted, $late): array {
                $out = [];
                foreach (array_keys($late) as $name) {
                    $out[$name] = $pdo->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = {$quoted} AND TABLE_NAME = 'cms_community_topics' AND INDEX_NAME = " . $pdo->quote($name) . ' ORDER BY SEQ_IN_INDEX')->fetchAll(PDO::FETCH_COLUMN);
                }

                return $out;
            };
            $badgeColumns = static fn (): array => $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = {$quoted} AND TABLE_NAME = 'cms_community_badges' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
            // Stand aus PR #1 herstellen (nur entfernen, was vorhanden ist: ohne IF EXISTS, das MySQL bei DROP INDEX/COLUMN nicht kennt).
            foreach ($topicIndexes() as $name => $columns) {
                if ($columns !== []) {
                    $pdo->exec("ALTER TABLE `{$db}`.cms_community_topics DROP INDEX {$name}");
                }
            }
            if (in_array('description_en', $badgeColumns(), true)) {
                $pdo->exec("ALTER TABLE `{$db}`.cms_community_badges DROP COLUMN description_en");
            }
            $pdo->exec("ALTER TABLE `{$db}`.cms_community_topics ADD INDEX idx_community_topics_solved (" . implode(', ', $oldSolved) . ')');
            T::same(['idx_community_topics_unanswered_all' => [], 'idx_community_topics_solved' => $oldSolved], $topicIndexes(), 'Ausgangslage: ohne idx_community_topics_unanswered_all, idx_community_topics_solved in der früheren Form ' . $form);
            $status = $step($tmp . '/v41', $db, 'status');
            T::same(['column:community_badges.description_en', 'index:community_topics.idx_community_topics_unanswered_all', 'index:community_topics.idx_community_topics_solved'], $status['missing'] ?? ['?'], 'Integritätsprüfung meldet die fehlende Spalte (REQUIRED_COLUMNS), den fehlenden Index und den Index mit anderen Spalten (REQUIRED_INDEXES)');
            T::same(true, $status['update_required'] ?? null, 'Updates-Seite bietet das Datenbank-Update an (schema_update_required)');

            $boot = $step($tmp . '/v41', $db, 'boot');
            T::same($target, $boot['schema'] ?? '', 'Marke bleibt ' . $target . ' (MigrationManager lief nicht)');
            T::same([], $boot['errors'] ?? ['?'], 'Laufzeitprüfung ohne Fehler im Log');
            T::same($late, $topicIndexes(), 'ensureFeatureSchema(): idx_community_topics_unanswered_all angelegt, idx_community_topics_solved ersetzt, beide mit den Spalten aus dem CREATE');
            $columns = $badgeColumns();
            T::same('description', $columns[(int) array_search('description_en', $columns, true) - 1] ?? null, 'ensureFeatureSchema(): community_badges.description_en direkt nach description (Reihenfolge wie bei der Neuinstallation)');
            T::same([], $step($tmp . '/v41', $db, 'status')['missing'] ?? ['?'], 'Integritätsprüfung: nichts fehlt');
            $compareGolden($pdo, $db);
            $repaired = $structure($db);
            T::same([], array_values(array_diff($freshStructure, $repaired)), 'nichts fehlt gegenüber der Neuinstallation');
            T::same([], array_values(array_diff($repaired, $freshStructure)), 'nichts Zusätzliches gegenüber der Neuinstallation');

            $again = $step($tmp . '/v41', $db, 'boot');
            T::same([], $again['errors'] ?? ['?'], 'zweiter Lauf ohne Fehler im Log');
            T::same($repaired, $structure($db), 'zweiter Lauf der Laufzeitprüfung ändert nichts');
        });
    }

    // Entwicklungsinstallation, die v27 mit dem Code aus PR #1 (5ab828e) per Update von 4.0.0 bzw. 4.1.8 erreicht hat: group_key
    // und event_count stammen aus den damaligen ALTER-Anweisungen (ohne Kommentar, group_key mit der Kollation der Tabelle). Die
    // Integritätsprüfung meldet die Abweichung (REQUIRED_COLUMN_DEFINITIONS); die Laufzeitprüfung gleicht beide an, ein zweiter Lauf
    // ändert nichts.
    foreach (['4.0.0 (v25)', '4.1.8 (v26)'] as $label) {
        if (!isset($sources[$label])) {
            continue;
        }
        T::run('Entwicklungsinstallation ' . $target . ' aus PR #1 nach Update von ' . $label . ': notifications-Spalten angeglichen', static function () use ($createDb, $step, $tmp, $prefix, $pdo, $sources, $label, $target, $structure, $freshStructure, $expectedNotificationColumns, $notificationColumns): void {
            $db = $prefix . 'devnotif' . preg_replace('/[^0-9]/', '', strstr($label, ' ', true) ?: $label);
            $createDb($db);
            $quoted = $pdo->quote($db);
            $old = $step($sources[$label], $db, 'install');
            T::same([], $old['install_errors'] ?? ['?'], $label . ': Ausgangsstand installiert');
            T::same($target, $step($tmp . '/v41', $db, 'boot')['schema'] ?? '', 'Update auf ' . $target);
            // Stand aus PR #1 herstellen: Spalten und Index mit den ALTER-Anweisungen von 5ab828e (MigrationManager v26 → v27).
            $pdo->exec("ALTER TABLE `{$db}`.cms_notifications DROP INDEX idx_user_group, DROP COLUMN group_key, DROP COLUMN event_count");
            foreach (['ADD COLUMN `group_key` VARCHAR(120) DEFAULT NULL', 'ADD COLUMN `event_count` INT UNSIGNED NOT NULL DEFAULT 1', 'ADD INDEX `idx_user_group` (`user_id`, `group_key`, `is_read`)'] as $alter) {
                $pdo->exec("ALTER TABLE `{$db}`.`cms_notifications` {$alter}");
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->exec("INSERT INTO `{$db}`.cms_notifications (user_id, type, title, message, group_key, event_count) VALUES (1, 'system', 'PR1-Hinweis', 'gebündelt', 'community:topic:7', 3)");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $tableCollation = (string) $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = {$quoted} AND TABLE_NAME = 'cms_notifications'")->fetchColumn();
            $before = $notificationColumns($db);
            T::same(['', ''], [$before['group_key'][5] ?? '?', $before['event_count'][5] ?? '?'], 'Ausgangslage: beide Spalten ohne Kommentar (ALTER aus PR #1)');
            T::same($tableCollation, $before['group_key'][4] ?? '?', 'Ausgangslage: group_key mit der Kollation der Tabelle (' . $tableCollation . ')');
            $status = $step($tmp . '/v41', $db, 'status');
            T::same(['column:notifications.group_key (Kommentar/Kollation)', 'column:notifications.event_count (Kommentar/Kollation)'], $status['missing'] ?? ['?'], 'Integritätsprüfung meldet abweichenden Kommentar bzw. abweichende Kollation (REQUIRED_COLUMN_DEFINITIONS)');
            T::same(true, $status['update_required'] ?? null, 'Updates-Seite bietet das Datenbank-Update an');

            $boot = $step($tmp . '/v41', $db, 'boot');
            T::same($target, $boot['schema'] ?? '', 'Marke bleibt ' . $target . ' (MigrationManager lief nicht)');
            T::same([], $boot['errors'] ?? ['?'], 'Laufzeitprüfung ohne Fehler im Log');
            T::same($expectedNotificationColumns, $notificationColumns($db), 'ensureFeatureSchema(): group_key/event_count mit Kommentar, group_key mit utf8mb4_unicode_ci, wie bei Neuinstallation und Update');
            $row = $pdo->query("SELECT group_key, event_count FROM `{$db}`.cms_notifications WHERE title = 'PR1-Hinweis'")->fetch(PDO::FETCH_NUM);
            T::same(['community:topic:7', '3'], is_array($row) ? array_map('strval', $row) : null, 'Bestandszeile unverändert (Gruppenschlüssel und Zähler)');
            T::same(3, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = {$quoted} AND TABLE_NAME = 'cms_notifications' AND INDEX_NAME = 'idx_user_group'")->fetchColumn(), 'Index idx_user_group (user_id, group_key, is_read) bleibt');
            $aligned = $structure($db);
            T::same([], array_values(array_diff($freshStructure, $aligned)), 'nichts fehlt gegenüber der Neuinstallation');
            T::same([], array_values(array_diff($aligned, $freshStructure)), 'nichts Zusätzliches gegenüber der Neuinstallation');

            $again = $step($tmp . '/v41', $db, 'boot');
            T::same([], $again['errors'] ?? ['?'], 'zweiter Lauf ohne Fehler im Log');
            T::same($aligned, $structure($db), 'zweiter Lauf ändert die Struktur nicht');
            T::same($expectedNotificationColumns, $notificationColumns($db), 'zweiter Lauf: Kommentar und Kollation unverändert');
            $update = $step($tmp . '/v41', $db, 'update');
            T::check(!empty($update['success']), 'Datenbank-Update meldet Erfolg', (string) ($update['message'] ?? ''));
            T::same($expectedNotificationColumns, $notificationColumns($db), 'nach dem Datenbank-Update unverändert');
            T::same([], $step($tmp . '/v41', $db, 'status')['missing'] ?? ['?'], 'Integritätsprüfung: nichts fehlt');
        });
    }

    // Gescheiterter Nachtrag: Ein ALTER der Laufzeitprüfung scheitert (hier echt: community_topics hat schon 64 Indizes, Fehler 1069;
    // ebenso Lock-Timeout oder KILL QUERY). Die Laufzeit-Flag wird trotzdem geschrieben, weitere Requests versuchen es nicht erneut.
    // Die Integritätsprüfung meldet die fehlenden Indizes, die Updates-Seite bietet das Datenbank-Update an; es meldet keinen Erfolg,
    // solange der Index nicht angelegt werden kann, und holt ihn danach nach.
    T::run('Gescheiterter Nachtrag der Laufzeitprüfung: Integritätsprüfung meldet ihn, das Datenbank-Update holt ihn nach', static function () use ($createDb, $step, $tmp, $prefix, $pdo, $structure, $freshStructure, $target, $compareGolden): void {
        $db = $prefix . 'failedalter';
        $createDb($db);
        $install = $step($tmp . '/v41', $db, 'install');
        T::same([], $install['install_errors'] ?? ['?'], 'Neuinstallation ' . $target);
        $quoted = $pdo->quote($db);
        $pdo->exec("ALTER TABLE `{$db}`.cms_community_topics DROP INDEX idx_community_topics_unanswered_all, DROP INDEX idx_community_topics_solved");
        $count = static fn (): int => (int) $pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = {$quoted} AND TABLE_NAME = 'cms_community_topics'")->fetchColumn();
        $fill = [];
        for ($i = $count(); $i < 64; $i++) {
            $fill[] = 'ADD INDEX idx_fill_' . $i . ' (slug(' . $i . '))';
        }
        $pdo->exec("ALTER TABLE `{$db}`.cms_community_topics " . implode(', ', $fill));
        T::same(64, $count(), 'Ausgangslage: community_topics mit 64 Indizes (Höchstzahl), ohne die beiden Nachträge');

        $boot = $step($tmp . '/v41', $db, 'boot');
        $failed = array_values(array_filter($boot['errors'] ?? [], static fn (string $line): bool => str_contains($line, 'SchemaManager::ensureFeatureSchema(community_topics.idx_community_topics_')));
        T::same(2, count($failed), 'Laufzeitprüfung protokolliert beide gescheiterten ALTER (error_log)', implode(' | ', $failed));
        T::check(glob($tmp . '/v41/cache/db_runtime_schema_' . $target . '_*.flag') !== [], 'Laufzeit-Flag trotzdem geschrieben (weitere Requests versuchen es nicht erneut)');
        $status = $step($tmp . '/v41', $db, 'status');
        T::same(['index:community_topics.idx_community_topics_unanswered_all', 'index:community_topics.idx_community_topics_solved'], $status['missing'] ?? ['?'], 'Integritätsprüfung meldet beide Indizes (REQUIRED_INDEXES)');
        T::same(true, $status['update_required'] ?? null, 'Updates-Seite bietet das Datenbank-Update an');
        $update = $step($tmp . '/v41', $db, 'update');
        T::same(false, (bool) ($update['success'] ?? true), 'Datenbank-Update meldet keinen Erfolg, solange der Index nicht angelegt werden kann', (string) ($update['message'] ?? ''));

        $pdo->exec("ALTER TABLE `{$db}`.cms_community_topics " . implode(', ', array_map(static fn (string $add): string => 'DROP INDEX ' . explode(' ', $add)[2], $fill)));
        $update = $step($tmp . '/v41', $db, 'update');
        T::check(!empty($update['success']), 'Datenbank-Update holt die Indizes nach und meldet Erfolg', (string) ($update['message'] ?? ''));
        T::same([], $step($tmp . '/v41', $db, 'status')['missing'] ?? ['?'], 'Integritätsprüfung: nichts fehlt');
        $compareGolden($pdo, $db);
        T::same([], array_values(array_diff($freshStructure, $structure($db))), 'nichts fehlt gegenüber der Neuinstallation');
    });

    // REQUIRED_INDEXES für eine weitere Tabelle (HANDOFF §2 Nr. 5): Der Eintrag genügt. ensureFeatureSchema() legt den Index an, auch
    // auf einer Spalte, die derselbe Lauf erst nachträgt, und das Datenbank-Update findet danach nichts mehr. Bisher behandelte
    // ensureFeatureSchema() nur notifications und community_topics: Der Index fehlte nach jedem Lauf, das Datenbank-Update scheiterte
    // dauerhaft. Aufbau: Kopie des Codes mit einem Eintrag für community_badges (nicht im CREATE), Entwicklungsinstallation aus PR #1
    // (ohne description_en).
    T::run('REQUIRED_INDEXES: Eintrag für eine weitere Tabelle, Index auf einer nachgetragenen Spalte', static function () use ($createDb, $step, $tmp, $prefix, $pdo, $copyTree, $structure, $requiredIndexes): void {
        $root = $tmp . '/v41idx';
        $copyTree($tmp . '/v41', $root);
        $file = $root . '/core/SchemaManager.php';
        $source = (string) file_get_contents($file);
        $anchor = "    public const REQUIRED_INDEXES = [\n";
        T::same(1, substr_count($source, $anchor), 'Aufbau: REQUIRED_INDEXES in der Kopie gefunden');
        file_put_contents($file, str_replace($anchor, $anchor . "        'community_badges' => ['idx_community_badges_probe' => ['description_en']],\n", $source));
        $probe = ['community_badges' => ['idx_community_badges_probe' => ['description_en']]];

        $db = $prefix . 'reqidx';
        $createDb($db);
        $install = $step($root, $db, 'install');
        T::same([], $install['install_errors'] ?? ['?'], 'Neuinstallation mit der Kopie (das CREATE kennt den Index nicht)');
        $pdo->exec("ALTER TABLE `{$db}`.cms_community_badges DROP COLUMN description_en");
        T::same(['community_badges' => ['idx_community_badges_probe' => []]], $requiredIndexes($db, $probe), 'Ausgangslage: ohne den Index und ohne description_en (Stand aus PR #1)');
        $status = $step($root, $db, 'status');
        T::same(['column:community_badges.description_en', 'index:community_badges.idx_community_badges_probe'], $status['missing'] ?? ['?'], 'Integritätsprüfung meldet Spalte und Index');

        $boot = $step($root, $db, 'boot');
        T::same([], $boot['errors'] ?? ['?'], 'Laufzeitprüfung ohne Fehler im Log');
        T::same($probe, $requiredIndexes($db, $probe), 'ensureFeatureSchema(): Index für community_badges angelegt, auf der im selben Lauf nachgetragenen Spalte');
        T::same([], $step($root, $db, 'status')['missing'] ?? ['?'], 'Integritätsprüfung: nichts fehlt');
        $after = $structure($db);
        $again = $step($root, $db, 'boot');
        T::same([], $again['errors'] ?? ['?'], 'zweiter Lauf ohne Fehler im Log');
        T::same($after, $structure($db), 'zweiter Lauf der Laufzeitprüfung ändert nichts');
        $update = $step($root, $db, 'update');
        T::check(!empty($update['success']), 'Datenbank-Update meldet Erfolg', (string) ($update['message'] ?? ''));
        T::same($after, $structure($db), 'Datenbank-Update ändert danach nichts');
    });

    T::run('Reparatur fehlender Tabellen und Spalten (DB-410-05, v27)', static function () use ($pdo, $step, $tmp, $fresh, $structure, $freshStructure, $expectedNotificationColumns, $notificationColumns): void {
        foreach (['cms_login_attempts DROP INDEX idx_ip_action, DROP INDEX idx_action, DROP COLUMN action', 'cms_mail_queue DROP INDEX idx_locked_at, DROP COLUMN locked_at', 'cms_post_revisions DROP COLUMN fingerprint', 'cms_orders DROP INDEX idx_provider_checkout, DROP COLUMN provider', 'cms_notifications DROP INDEX idx_user_group, DROP COLUMN group_key, DROP COLUMN event_count'] as $alter) {
            $pdo->exec("ALTER TABLE `{$fresh}`.{$alter}");
        }
        $pdo->exec("DROP TABLE `{$fresh}`.cms_webhook_deliveries");
        $pdo->exec("DROP TABLE `{$fresh}`.cms_community_reports");
        $missing = $step($tmp . '/v41', $fresh, 'status')['missing'] ?? [];
        T::check(in_array('table:webhook_deliveries', $missing, true) && in_array('column:login_attempts.action', $missing, true), 'DB-410-04: Integritätsprüfung erkennt fehlende Tabelle und Spalte');
        T::check(in_array('table:community_reports', $missing, true), 'v27: Integritätsprüfung erkennt eine fehlende Community-Tabelle');
        T::check(in_array('column:notifications.group_key', $missing, true) && in_array('column:notifications.event_count', $missing, true), 'v27: Integritätsprüfung erkennt die fehlenden Spalten an notifications');
        $pdo->exec("DELETE FROM `{$fresh}`.cms_settings WHERE option_name = 'capabilities_v27'");
        $pdo->exec("DROP TABLE `{$fresh}`.cms_role_permissions");
        $missing = $step($tmp . '/v41', $fresh, 'status')['missing'] ?? [];
        T::check(in_array('option:capabilities_v27', $missing, true), 'v27: Integritätsprüfung erkennt den fehlenden Rechte-Marker capabilities_v27');
        $update = $step($tmp . '/v41', $fresh, 'update');
        T::check(!empty($update['success']), 'Datenbank-Update repariert', (string) ($update['message'] ?? ''));
        T::same(9, (int) $pdo->query("SELECT COUNT(*) FROM `{$fresh}`.cms_role_permissions WHERE capability LIKE 'community.%'")->fetchColumn(), 'Reparatur: role_permissions angelegt, Standardrechte community.* wieder eingetragen');
        T::same(1, (int) $pdo->query("SELECT COUNT(*) FROM `{$fresh}`.cms_settings WHERE option_name = 'capabilities_v27'")->fetchColumn(), 'Reparatur: Marker capabilities_v27 gesetzt');
        T::same([], $step($tmp . '/v41', $fresh, 'status')['missing'] ?? ['?'], 'danach vollständig');
        $repaired = $structure($fresh);
        T::same([], array_values(array_diff($freshStructure, $repaired)), 'Tabellen, Spalten und Indizes wiederhergestellt');
        T::same($expectedNotificationColumns, $notificationColumns($fresh), 'Reparatur: notifications.group_key/event_count mit Kommentar und Kollation wie im CREATE');
    });
    // Updater ohne Funktionsdateien (CMS/update.php lädt includes/functions nicht), role_permissions fehlt (4.0.0 legt sie erst
    // beim ersten Lesen an): Beide Rechte-Schritte (v26 und v27) legen die Tabelle selbst an und laufen ohne Fehler im Log durch.
    T::run('Update ohne Funktionsdateien und ohne role_permissions (CMS/update.php)', static function () use ($createDb, $step, $tmp, $prefix, $pdo, $sources, $compareGolden, $communityCapabilities, $expectedNotificationColumns, $notificationColumns): void {
        if (!isset($sources['4.0.0 (v25)'])) {
            T::info('übersprungen: Ausgangsstand 4.0.0 steht weder als Paket unter RELEASE/ noch als Git-Tag V4.0.0 zur Verfügung');

            return;
        }
        $db = $prefix . 'nofn';
        $createDb($db);
        $old = $step($sources['4.0.0 (v25)'], $db, 'install');
        T::same([], $old['install_errors'] ?? ['?'], '4.0.0: Ausgangsstand installiert');
        $pdo->exec("DROP TABLE IF EXISTS `{$db}`.cms_role_permissions");
        $update = $step($tmp . '/v41', $db, 'update_nofn');
        T::check(!empty($update['success']), 'Datenbank-Update ohne includes/functions meldet Erfolg', (string) ($update['message'] ?? ''));
        T::same([], $update['errors'] ?? ['?'], 'keine Fehlerzeilen im Log (Tabelle wird vor dem v26-Schritt angelegt)');
        T::same(1, (int) $pdo->query("SELECT COUNT(*) FROM `{$db}`.cms_settings WHERE option_name = 'capabilities_v27'")->fetchColumn(), 'Marker capabilities_v27 gesetzt');
        T::same(9, (int) $pdo->query("SELECT COUNT(*) FROM `{$db}`.cms_role_permissions WHERE capability LIKE 'community.%'")->fetchColumn(), '9 Zeilen community.*');
        T::same(['author:posts.publish', 'editor:pages.publish', 'editor:posts.publish'], $pdo->query("SELECT CONCAT(role, ':', capability) FROM `{$db}`.cms_role_permissions WHERE capability NOT LIKE 'community.%' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN), 'der v26-Schritt (Veröffentlichen) lief ebenfalls');
        T::same([], $step($tmp . '/v41', $db, 'status')['missing'] ?? ['?'], 'Integritätsprüfung: nichts fehlt');
        $compareGolden($pdo, $db);
        T::same($expectedNotificationColumns, $notificationColumns($db), 'notifications.group_key/event_count mit Kommentar und Kollation wie im CREATE');
    });

    // Kollation: die Community-Tabellen tragen utf8mb4_unicode_ci unabhängig vom Standard der Datenbank (utf8mb4_general_ci hier).
    T::run('Kollation der Community-Tabellen unabhängig vom Standard der Datenbank', static function () use ($pdo, $prefix, $repo): void {
        if (!defined('ABSPATH')) {
            define('ABSPATH', $repo . '/CMS/');
        }
        require_once $repo . '/CMS/core/SchemaManager.php';
        $db = $prefix . 'collation';
        $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
        $pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $pdo->exec("USE `{$db}`");
        $queries = \CMS\SchemaManager::getSchemaQueries('cms_', 'utf8mb4');
        foreach (\CMS\SchemaManager::FEATURE_TABLES_V27 as $table) {
            $pdo->exec($queries[$table]);
        }
        $quoted = $pdo->quote($db);
        T::same([], $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = {$quoted} AND TABLE_NAME LIKE 'cms\\_community\\_%' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'")->fetchAll(PDO::FETCH_COLUMN), 'alle 28 Tabellen: utf8mb4_unicode_ci (nicht der Standard der Datenbank)');
        T::same([], $pdo->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = {$quoted} AND TABLE_NAME LIKE 'cms\\_community\\_%' AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> 'utf8mb4_unicode_ci'")->fetchAll(PDO::FETCH_COLUMN), 'alle Textspalten: utf8mb4_unicode_ci');
        T::same(28, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = {$quoted} AND TABLE_NAME LIKE 'cms\\_community\\_%'")->fetchColumn(), 'alle 28 Tabellen angelegt');
    });
} finally {
    foreach ($pdo->query("SHOW DATABASES LIKE " . $pdo->quote(str_replace('_', '\\_', $prefix) . '%'))->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $pdo->exec("DROP DATABASE `{$name}`");
    }
    if ($previousMode !== null) {
        $pdo->exec('SET GLOBAL sql_mode = ' . $pdo->quote($previousMode));
    }
    $removeTree($tmp);
}

T::finish();
