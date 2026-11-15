<?php
/**
 * Ablauf- und Prüfdaten (4.1.0, Bereich 3c, erster Lauf): Eingaben, Ablauf (zurückstufen/markieren, Batch,
 * idempotent), Wiedervorlage (einmal melden, „Geprüft“ mit Intervall, Monatsende), Speichern der Editor-Felder und
 * Quelltext-Prüfungen der Einbindung. Ohne Datenbank: SQLite-Attrappe im Speicher.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/content-expiry/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';

use CMS\Database;
use CMS\Services\Content\ContentExpiryService as E;

T::$verbose = in_array('-v', $argv ?? [], true);

function exSource(string $relative): string
{
    return (string) file_get_contents(ABSPATH . $relative);
}

function exDatabase(): Database
{
    return new class () extends Database {
        public PDO $sqlite;

        public function __construct()
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach (['posts', 'pages'] as $table) {
                $this->sqlite->exec("CREATE TABLE cms_{$table} (id INTEGER PRIMARY KEY, title TEXT, status TEXT, author_id INTEGER, updated_at TEXT,
                    expires_at TEXT, review_due_at TEXT, expiry_notified_at TEXT, review_notified_at TEXT)");
            }
            $this->sqlite->exec("CREATE TABLE cms_kb_entries (id INTEGER PRIMARY KEY, title TEXT, is_active INTEGER, trashed_at TEXT, updated_at TEXT,
                expires_at TEXT, review_due_at TEXT, expiry_notified_at TEXT, review_notified_at TEXT)");
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $stmt = $this->sqlite->prepare(str_replace('<=>', 'IS', $sql));
            $stmt->execute(array_values($params));

            return $stmt;
        }

        public function get_results(string $query, array $params = []): array
        {
            return $this->execute($query, $params)->fetchAll(PDO::FETCH_OBJ);
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

/**
 * @return array{db: Database, service: E, sent: \ArrayObject, settings: \ArrayObject, hooks: \ArrayObject}
 */
function exFixture(): array
{
    $db = exDatabase();
    $db->execute("INSERT INTO cms_posts (id, title, status, author_id, expires_at, review_due_at) VALUES
        (1, 'Abgelaufen', 'published', 7, '2026-11-15 09:00:00', NULL),
        (2, 'Läuft noch', 'published', 7, '2026-11-16 09:00:00', NULL),
        (3, 'Entwurf abgelaufen', 'draft', 7, '2026-11-01 00:00:00', NULL),
        (4, 'Ohne Ablauf', 'published', 7, NULL, '2026-11-15 00:00:00'),
        (5, 'Papierkorb', 'trash', 7, NULL, '2026-11-01 00:00:00')");
    $db->execute("INSERT INTO cms_pages (id, title, status, author_id, expires_at) VALUES (9, 'Seite', 'published', 0, '2026-11-15 10:00:00')");
    $db->execute("INSERT INTO cms_kb_entries (id, title, is_active, trashed_at, expires_at, review_due_at) VALUES
        (30, 'KB abgelaufen', 1, NULL, '2026-11-10 00:00:00', NULL),
        (31, 'KB inaktiv', 0, NULL, '2026-11-10 00:00:00', NULL),
        (32, 'KB Papierkorb', 1, '2026-11-01 00:00:00', '2026-11-10 00:00:00', '2026-11-01 00:00:00')");
    $sent = new \ArrayObject();
    $settings = new \ArrayObject([E::SETTING_ACTION => 'draft', E::SETTING_REVIEW_INTERVAL => '12']);
    $service = new E(
        $db,
        static fn (): int => strtotime('2026-11-15 10:00:00'),
        static fn (string $key, string $default): string => (string) ($settings[$key] ?? $default),
        static function (int $userId, string $subject, string $message, string $url) use ($sent): void {
            $sent[] = ['user' => $userId, 'subject' => $subject, 'message' => $message, 'url' => $url];
        }
    );

    return ['db' => $db, 'service' => $service, 'sent' => $sent, 'settings' => $settings];
}

T::run('Eingaben und Regeln', static function (): void {
    T::same(['value' => '2026-12-24 18:30:00', 'error' => null], E::normalizeInput('2026-12-24', '18:30', 'Ablaufdatum'), 'Datum und Uhrzeit');
    T::same(['value' => '2026-12-24 00:00:00', 'error' => null], E::normalizeInput('2026-12-24', '', 'Ablaufdatum'), 'ohne Uhrzeit: 00:00');
    T::same(['value' => null, 'error' => null], E::normalizeInput('', '', 'Ablaufdatum'), 'leer: kein Datum');
    T::check(E::normalizeInput('2026-02-30', '', 'Ablaufdatum')['error'] !== null, '30. Februar ist ungültig');
    T::check(E::normalizeInput('', '10:00', 'Ablaufdatum')['error'] !== null, 'Uhrzeit ohne Datum ist ein Fehler');
    T::check(E::normalizeInput('24.12.2026', '', 'Ablaufdatum')['error'] !== null, 'falsches Format ist ein Fehler');
    T::check(E::isExpired('published', '2026-11-15 10:00:00', '2026-11-15 10:00:00'), 'genau zum Zeitpunkt abgelaufen');
    T::check(!E::isExpired('published', '2026-11-15 10:00:01', '2026-11-15 10:00:00'), 'eine Sekunde davor nicht');
    T::check(!E::isExpired('draft', '2026-01-01 00:00:00', '2026-11-15 10:00:00'), 'nur veröffentlichte Inhalte laufen ab');
    T::check(!E::isExpired('published', null, '2026-11-15 10:00:00'), 'ohne Datum nie');
    T::same('2027-11-15 10:00:00', E::nextReviewDate('2026-11-15 10:00:00', 12), 'Wiedervorlage + 12 Monate');
    T::same('2026-02-28 00:00:00', E::nextReviewDate('2026-01-31 00:00:00', 1), 'Monatsende bleibt Monatsende');
    T::same(1, E::normalizeInterval('0'), 'Intervall mindestens 1 Monat');
    T::same(60, E::normalizeInterval('999'), 'Intervall höchstens 60 Monate');
    T::same('draft', E::normalizeAction('irgendwas'), 'unbekannte Aktion → zurückstufen');
});

T::run('Ablauf: zurückstufen', static function (): void {
    $f = exFixture();
    $result = $f['service']->processExpired();
    T::same(['expired' => 3, 'marked' => 0], $result, 'Beitrag 1, Seite 9 und KB-Eintrag 30 zurückgestuft');
    T::same(0, (int) $f['db']->get_var('SELECT is_active FROM cms_kb_entries WHERE id = 30'), 'abgelaufener KB-Eintrag ist inaktiv');
    T::same(1, (int) $f['db']->get_var('SELECT is_active FROM cms_kb_entries WHERE id = 32'), 'KB-Eintrag im Papierkorb bleibt unberührt');
    T::same(null, $f['db']->get_var('SELECT expiry_notified_at FROM cms_kb_entries WHERE id = 31'), 'inaktiver KB-Eintrag wird nicht gemeldet');
    T::same('draft', (string) $f['db']->get_var('SELECT status FROM cms_posts WHERE id = 1'), 'abgelaufener Beitrag ist Entwurf');
    T::same('published', (string) $f['db']->get_var('SELECT status FROM cms_posts WHERE id = 2'), 'noch gültiger Beitrag bleibt');
    T::same('draft', (string) $f['db']->get_var('SELECT status FROM cms_pages WHERE id = 9'), 'abgelaufene Seite ist Entwurf');
    T::same(1, count($f['sent']), 'Hinweis nur an vorhandene Autoren (Seite ohne Autor: keiner)');
    T::same(7, $f['sent'][0]['user'], 'Hinweis an den Autor');
    T::same('/admin/posts?action=edit&id=1', $f['sent'][0]['url'], 'Link zum Editor');
    T::same(['expired' => 0, 'marked' => 0], $f['service']->processExpired(), 'zweiter Lauf ändert nichts (idempotent)');
});

T::run('Ablauf: nur markieren', static function (): void {
    $f = exFixture();
    $f['settings'][E::SETTING_ACTION] = 'mark';
    T::same(['expired' => 0, 'marked' => 3], $f['service']->processExpired(), 'Einstellung „nur markieren“');
    T::same('published', (string) $f['db']->get_var('SELECT status FROM cms_posts WHERE id = 1'), 'Inhalt bleibt sichtbar');
    T::same(['expired' => 0, 'marked' => 0], $f['service']->processExpired(), 'markierte Inhalte melden sich nur einmal');
    $f['service']->saveDates('post', 1, '2026-11-15 09:30:00', null);
    T::same(['expired' => 0, 'marked' => 1], $f['service']->processExpired(), 'neues Ablaufdatum setzt die Markierung zurück');
    $f['service']->saveDates('post', 1, '2026-11-15 09:30:00', null);
    T::same(null, $f['db']->get_var('SELECT review_due_at FROM cms_posts WHERE id = 1'), 'Speichern übernimmt auch leere Wiedervorlage');
    T::check($f['db']->get_var('SELECT expiry_notified_at FROM cms_posts WHERE id = 1') !== null, 'unverändertes Ablaufdatum behält die Markierung');
    // FUN-410-08: Modus „zurückstufen“ – abgelaufen, Entwurf, erneut veröffentlicht mit gleichem Datum → läuft wieder ab.
    $f['settings'][E::SETTING_ACTION] = 'draft';
    $f['db']->execute("UPDATE cms_posts SET status = 'draft', expiry_notified_at = '2026-11-15 09:31:00' WHERE id = 1");
    $f['service']->saveDates('post', 1, '2026-11-15 09:30:00', null);
    T::check($f['db']->get_var('SELECT expiry_notified_at FROM cms_posts WHERE id = 1') !== null, 'Entwurf: Markierung bleibt (410 möglich)');
    $f['db']->execute("UPDATE cms_posts SET status = 'published' WHERE id = 1");
    $f['service']->saveDates('post', 1, '2026-11-15 09:30:00', null);
    T::same(null, $f['db']->get_var('SELECT expiry_notified_at FROM cms_posts WHERE id = 1'), 'wieder veröffentlicht: Markierung zurückgesetzt, der nächste Lauf stuft erneut zurück');
    T::check(E::wasExpiredAndUnpublished(['is_active' => '0', 'expiry_notified_at' => '2026-11-15 09:31:00']), 'FUN-410-09: Wissensdatenbank (is_active = 0) gilt als abgelaufen und zurückgestuft');
});

T::run('Batch-Grenze', static function (): void {
    $f = exFixture();
    for ($i = 100; $i < 230; $i++) {
        $f['db']->execute("INSERT INTO cms_posts (id, title, status, author_id, expires_at) VALUES (?, 'x', 'published', 0, '2026-11-01 00:00:00')", [$i]);
    }
    $first = $f['service']->processExpired();
    T::same(E::BATCH + 2, $first['expired'], 'je Lauf höchstens 100 Beiträge (+ 1 Seite, 1 KB-Eintrag)');
    $second = $f['service']->processExpired();
    T::same(31, $second['expired'], 'der Rest im nächsten Lauf');
});

T::run('Antwort 404 oder 410', static function (): void {
    $f = exFixture();
    $f['service']->processExpired();
    $row = (object) ['status' => 'draft', 'expiry_notified_at' => '2026-11-15 10:00:00'];
    T::same(404, $f['service']->frontendStatusFor($row), 'Standard: 404');
    $f['settings'][E::SETTING_RESPONSE] = '410';
    T::same(410, $f['service']->frontendStatusFor($row), 'Einstellung 410: abgelaufene Inhalte melden „Gone“');
    T::same(404, $f['service']->frontendStatusFor(['status' => 'draft', 'expiry_notified_at' => null]), 'normaler Entwurf bleibt 404');
    T::same(404, $f['service']->frontendStatusFor(['status' => 'published', 'expiry_notified_at' => '2026-11-15 10:00:00']), 'nur markierte (sichtbare) Inhalte: kein 410');
});

T::run('Wiedervorlage', static function (): void {
    $f = exFixture();
    T::same(1, $f['service']->processDueReviews(), 'fälliger Beitrag gemeldet, Papierkorb nicht');
    T::check(str_contains($f['sent'][0]['message'], 'zur Prüfung fällig'), 'Hinweistext');
    T::same('published', (string) $f['db']->get_var('SELECT status FROM cms_posts WHERE id = 4'), 'Wiedervorlage ändert den Status nicht');
    T::same(0, $f['service']->processDueReviews(), 'nur einmal melden');
    T::same(['post:4'], array_map(static fn (array $i): string => $i['type'] . ':' . $i['id'], $f['service']->dueReviews()), 'Liste fälliger Prüfungen');
    $done = $f['service']->markReviewed('post', 4);
    T::same('2027-11-15 10:00:00', $done['review_due_at'], '„Geprüft“ setzt jetzt + Intervall');
    T::same(null, $f['db']->get_var('SELECT review_notified_at FROM cms_posts WHERE id = 4'), '„Geprüft“ setzt die Meldung zurück');
    T::same([], $f['service']->dueReviews(), 'danach nicht mehr fällig');
    $f['settings'][E::SETTING_REVIEW_INTERVAL] = '6';
    T::same('2027-05-15 10:00:00', $f['service']->markReviewed('post', 4)['review_due_at'], 'Intervall aus der Einstellung');
    T::check(empty($f['service']->markReviewed('download', 4)['success']), 'unbekannter Typ abgelehnt');
});

T::run('Einbindung (Quelltext)', static function (): void {
    $schema = \CMS\SchemaManager::getSchemaQueries('cms_', 'utf8mb4');
    foreach (['posts', 'pages'] as $table) {
        foreach (['expires_at DATETIME', 'review_due_at DATETIME', 'expiry_notified_at DATETIME', 'review_notified_at DATETIME', 'INDEX idx_expires (status, expires_at)'] as $needle) {
            T::check(str_contains((string) $schema[$table], $needle), $table . ': ' . $needle);
        }
    }
    T::check(str_contains(exSource('core/SchemaManager.php'), "['expires_at', 'review_due_at', 'expiry_notified_at', 'review_notified_at']"), 'Bestand erhält die Spalten über ensureFeatureSchema()');
    $hooks = exSource('core/Services/FeatureHooks/ContentExpiryHooks.php');
    T::check(str_contains($hooks, "addAction('cms_cron_hourly'") && str_contains($hooks, 'processExpired()'), 'Ablauf stündlich per Cron');
    T::check(str_contains($hooks, "addAction('cms_cron_daily'") && str_contains($hooks, 'processDueReviews()'), 'Wiedervorlage täglich per Cron');
    T::check(str_contains($hooks, "!array_key_exists('expires_date', \$post)"), 'ohne Editor-Felder bleiben gespeicherte Werte unverändert');
    T::check(str_contains(exSource('core/Services/FeatureHooks/Registry.php'), 'ContentExpiryHooks::class'), 'Hooks registriert');
    foreach (['admin/modules/posts/PostsModule.php', 'admin/modules/pages/PagesModule.php'] as $module) {
        T::check(substr_count(exSource($module), 'ContentExpiryService::normalizeInput(') === 2, basename($module) . ': Eingaben werden vor dem Speichern geprüft');
    }
    foreach (['admin/views/posts/edit.php', 'admin/views/pages/edit.php'] as $view) {
        T::check(str_contains(exSource($view), 'partials/content-expiry-fields.php'), basename(dirname($view)) . '-Editor zeigt die Felder');
    }
    T::check(!preg_match('/<script|\son[a-z]+=/i', exSource('admin/views/partials/content-expiry-fields.php')), 'Felder ohne Inline-Skripte (CSP)');
    $calendar = exSource('core/Services/EditorialCalendarService.php');
    T::check(str_contains($calendar, "case self::KIND_REVIEW:") && str_contains($calendar, 'review_due_at'), 'Redaktionskalender zeigt Wiedervorlagen');
    T::check(str_contains($calendar, '[self::KIND_PUBLISHED, self::KIND_REVIEW]'), 'Wiedervorlagen sind im Kalender nicht verschiebbar');
    T::check(str_contains(exSource('assets/js/admin-editorial-calendar.js'), "'review'"), 'Kalender-Skript kennt die Art „review“');
    T::check(str_contains(exSource('core/Services/DashboardService.php'), "dueReviews(8)") && str_contains(exSource('admin/views/dashboard/index.php'), 'Fällige Prüfungen'), 'Dashboard-Karte „Fällige Prüfungen“');
    T::check(str_contains(exSource('core/Routing/ThemeRouter.php'), 'frontendStatusFor($postRow) === 410') && str_contains(exSource('core/Router.php'), 'frontendStatusFor($page) === 410'), '410 für Beiträge und Seiten');
    T::check(str_contains(exSource('admin/modules/knowledgebase/KnowledgebaseModule.php'), "ContentExpiryHooks::persist('kb'"), 'Wissensdatenbank speichert die Felder');
    T::check(str_contains(exSource('admin/views/knowledgebase/edit.php'), 'partials/content-expiry-fields.php'), 'Wissensdatenbank-Editor zeigt die Felder');
    T::check(str_contains((string) \CMS\SchemaManager::getSchemaQueries('cms_', 'utf8mb4')['kb_entries'], 'KEY idx_kb_expires (is_active, expires_at)'), 'kb_entries: Spalten und Index');
    T::check(str_contains(exSource('core/Services/Content/ContentExpiryService.php'), "'cms_content_expired'") && str_contains(exSource('core/Services/Content/ContentExpiryService.php'), "'cms_content_review_due'"), 'Hooks cms_content_expired und cms_content_review_due');
});

T::finish();
