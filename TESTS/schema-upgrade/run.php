<?php
/**
 * Upgrade-Pfad gegen eine echte MySQL/MariaDB (Release-Prüfung 4.1.0, PR #2): Neuinstallation v26 und Updates von
 * 4.0.0 (Schema v25, Paket RELEASE/365CMS-4.0.0-full.zip) sowie – wenn der Git-Tag V3.5.00 vorhanden ist – von 3.5.x
 * (Schema v23) müssen dieselbe Struktur ergeben (Tabellen, Spalten, Typen, Defaults, Indizes, Fremdschlüssel), Daten
 * erhalten, idempotent laufen und beim ersten Request nur eine Verbindung öffnen (DB-410-02).
 *
 * Optional: läuft nur mit CMS_TEST_DB_HOST und CMS_TEST_DB_USER (Recht CREATE/DROP DATABASE), sonst Hinweis.
 *   CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/schema-upgrade/run.php [-v]
 * Optional CMS_TEST_SQL_MODE (z. B. MySQL-8-Standard mit ONLY_FULL_GROUP_BY) für die Sitzungen der Prüfung.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';
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
$prefix = 'cmsup' . getmypid() . '_';

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

    T::run('Neuinstallation ' . $target, static function () use ($createDb, $step, $tmp, $fresh, $connections, $target, $pdo, $communityGrants, $communityCapabilities): void {
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
        T::same(7, (int) $pdo->query("SELECT COUNT(*) FROM `{$fresh}`.cms_role_permissions WHERE role = 'moderator' AND capability NOT LIKE 'community.%' AND granted = 1")->fetchColumn(), 'neue Rolle moderator mit den sieben Grundrechten');
    });
    $freshStructure = $structure($fresh);

    foreach ($sources as $label => $oldRoot) {
        $db = $prefix . preg_replace('/[^a-z0-9]/', '', strtolower($label));
        T::run('Upgrade ' . $label . ' → ' . $target, static function () use ($createDb, $step, $tmp, $db, $oldRoot, $pdo, $structure, $freshStructure, $label, $target, $communityGrants, $communityCapabilities): void {
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
            T::same(0, (int) $pdo->query("SELECT COUNT(*) FROM `{$db}`.cms_role_permissions WHERE role = 'moderator' AND capability = 'read'")->fetchColumn(), 'vorhandene Rolle moderator erhält keine Grundrechte nachgetragen');

            $notification = $pdo->query("SELECT group_key, event_count FROM `{$db}`.cms_notifications WHERE title = 'Alt-Hinweis'")->fetch(PDO::FETCH_ASSOC);
            T::same(['group_key' => null, 'event_count' => '1'], is_array($notification) ? array_map(static fn ($v) => $v === null ? null : (string) $v, $notification) : null, 'notifications: Bestandszeile ohne Gruppenschlüssel, Zähler 1');
            T::same(3, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = " . $pdo->quote($db) . " AND TABLE_NAME = 'cms_notifications' AND INDEX_NAME = 'idx_user_group'")->fetchColumn(), 'notifications: Index idx_user_group (user_id, group_key, is_read) vorhanden');

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

    T::run('Reparatur fehlender Tabellen und Spalten (DB-410-05, v27)', static function () use ($pdo, $step, $tmp, $fresh, $structure, $freshStructure): void {
        foreach (['cms_login_attempts DROP INDEX idx_ip_action, DROP INDEX idx_action, DROP COLUMN action', 'cms_mail_queue DROP INDEX idx_locked_at, DROP COLUMN locked_at', 'cms_post_revisions DROP COLUMN fingerprint', 'cms_orders DROP INDEX idx_provider_checkout, DROP COLUMN provider', 'cms_notifications DROP INDEX idx_user_group, DROP COLUMN group_key, DROP COLUMN event_count'] as $alter) {
            $pdo->exec("ALTER TABLE `{$fresh}`.{$alter}");
        }
        $pdo->exec("DROP TABLE `{$fresh}`.cms_webhook_deliveries");
        $pdo->exec("DROP TABLE `{$fresh}`.cms_community_reports");
        $missing = $step($tmp . '/v41', $fresh, 'status')['missing'] ?? [];
        T::check(in_array('table:webhook_deliveries', $missing, true) && in_array('column:login_attempts.action', $missing, true), 'DB-410-04: Integritätsprüfung erkennt fehlende Tabelle und Spalte');
        T::check(in_array('table:community_reports', $missing, true), 'v27: Integritätsprüfung erkennt eine fehlende Community-Tabelle');
        T::check(in_array('column:notifications.group_key', $missing, true) && in_array('column:notifications.event_count', $missing, true), 'v27: Integritätsprüfung erkennt die fehlenden Spalten an notifications');
        $update = $step($tmp . '/v41', $fresh, 'update');
        T::check(!empty($update['success']), 'Datenbank-Update repariert', (string) ($update['message'] ?? ''));
        T::same([], $step($tmp . '/v41', $fresh, 'status')['missing'] ?? ['?'], 'danach vollständig');
        $repaired = $structure($fresh);
        T::same([], array_values(array_diff($freshStructure, $repaired)), 'Tabellen, Spalten und Indizes wiederhergestellt');
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
