<?php
/**
 * Mitgliederbereich (TODO-MEMBERDASHBOARD-V4, Paket 1/2, QA-02): Sanitizer, Sprachkatalog, Widget-Vertrag,
 * Layout-Einstellungen, Menübaum, CSRF-Token je Request (MD-04), Benachrichtigungen (MD-17/MD-18),
 * Favoriten (MD-16) sowie Quelltext-Prüfungen für Re-Auth, Landmarks und CSP. Ohne Datenbank: Die
 * Service-Tests nutzen eine SQLite-Attrappe im Speicher.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/member-area/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');
define('SITE_URL', 'https://cms.test');

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
require_once ABSPATH . 'member/includes/class-member-controller.php';

use CMS\Database;
use CMS\Member\MemberI18n;
use CMS\Member\MemberSanitizer;
use CMS\Member\PluginDashboardRegistry;
use CMS\MemberArea\MemberController;
use CMS\Services\MemberContentService;
use CMS\Services\MemberService;
use CMS\Services\NotificationService;

T::$verbose = in_array('-v', $argv ?? [], true);

/** Quelltext relativ zu CMS/. */
function memberSource(string $relative): string
{
    return (string) file_get_contents(ABSPATH . $relative);
}

/** SQLite-Attrappe mit den Tabellen, die die Member-Services lesen und schreiben. */
function memberTestDatabase(): Database
{
    $db = new class () extends Database {
        public PDO $sqlite;

        public function __construct()
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec('CREATE TABLE cms_user_meta (user_id INTEGER, meta_key TEXT, meta_value TEXT, UNIQUE (user_id, meta_key))');
            $this->sqlite->exec("CREATE TABLE cms_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT DEFAULT 'system', title TEXT,
                message TEXT, url TEXT, is_read INTEGER NOT NULL DEFAULT 0, read_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
            $this->sqlite->exec("CREATE TABLE cms_posts (id INTEGER PRIMARY KEY, title TEXT, title_en TEXT, slug TEXT, slug_en TEXT, status TEXT,
                author_id INTEGER, published_at TEXT, created_at TEXT, trashed_at TEXT)");
            $this->sqlite->exec('CREATE TABLE cms_favorites (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, post_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (user_id, post_id))');
            $this->sqlite->exec("CREATE TABLE cms_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, user_id INTEGER, content TEXT,
                status TEXT, post_date TEXT)");
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $sql = str_replace(['INSERT IGNORE', 'NOW()'], ['INSERT OR IGNORE', "datetime('now')"], $sql);
            $sql = (string) preg_replace('/ON DUPLICATE KEY UPDATE meta_value = VALUES\(meta_value\)/', 'ON CONFLICT (user_id, meta_key) DO UPDATE SET meta_value = excluded.meta_value', $sql);
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

        public function lastInsertId(): int
        {
            return (int) $this->sqlite->lastInsertId();
        }

        public function getPrefix(): string
        {
            return 'cms_';
        }
    };

    (new ReflectionProperty(Database::class, 'instance'))->setValue(null, $db);
    foreach ([MemberService::class, NotificationService::class, MemberContentService::class] as $class) {
        $service = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($class, 'db'))->setValue($service, $db);
        (new ReflectionProperty($class, 'prefix'))->setValue($service, 'cms_');
        (new ReflectionProperty($class, 'instance'))->setValue(null, $service);
    }

    return $db;
}

/** MemberController ohne Konstruktor (keine Auth-/DB-Abhängigkeiten) mit leerer Registry. */
function memberTestController(): MemberController
{
    $controller = (new ReflectionClass(MemberController::class))->newInstanceWithoutConstructor();
    $registry = (new ReflectionClass(PluginDashboardRegistry::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(MemberController::class, 'registry'))->setValue($controller, $registry);

    return $controller;
}

MemberI18n::reset('de', 'de');

T::run('Sanitizer: Farben, Links, Assets und Anker (MD-14)', static function (): void {
    T::same('#AABBCC', MemberSanitizer::color('#AABBCC'), 'gültige Hex-Farbe bleibt');
    T::same('#4f46e5', MemberSanitizer::color('red; background:url(x)'), 'ungültige Farbe → Standard');
    T::same('/member/profile', MemberSanitizer::link('/member/profile'), 'lokaler Pfad bleibt');
    T::same('/member/dashboard', MemberSanitizer::link('//evil.test/x'), 'protokollrelativer Link → Fallback');
    T::same('/member/dashboard', MemberSanitizer::link('javascript:alert(1)'), 'javascript: → Fallback');
    T::same('https://example.org/a', MemberSanitizer::link('https://example.org/a'), 'https-URL bleibt');
    T::same(null, MemberSanitizer::nullableLink(''), 'leerer Admin-Link → null');
    T::same('', MemberSanitizer::asset('C:\\logo.png'), 'Laufwerkspfad als Asset verworfen');
    T::same('newsletter-widget_1script', MemberSanitizer::anchor('newsletter-widget_1"><script>'), 'Anker auf [a-z0-9_-] reduziert');
    T::same('', MemberSanitizer::notificationUrl('data:text/html,x'), 'Benachrichtigungs-URL: data: verworfen');
    T::same('/member/messages?thread=3', MemberSanitizer::notificationUrl('/member/messages?thread=3'), 'Benachrichtigungs-URL: lokaler Pfad bleibt');
    T::same('Text', MemberSanitizer::text("<b>Text</b>\x07", 10), 'Text ohne Tags und Steuerzeichen');
    T::same('abc', MemberSanitizer::text('abcdef', 3), 'Text gekürzt');
});

T::run('Sanitizer: Profilfelder (MD-09)', static function (): void {
    T::same('1990-02-28', MemberSanitizer::birthDate('1990-02-28'), 'gültiges Geburtsdatum');
    T::same('', MemberSanitizer::birthDate('1990-02-30'), 'ungültiger Kalendertag');
    T::same('', MemberSanitizer::birthDate('28.02.1990'), 'falsches Format');
    T::same('', MemberSanitizer::birthDate(date('Y-m-d', strtotime('+2 days'))), 'Datum in der Zukunft');
    T::same('+49 (0)30 123-45', MemberSanitizer::phone('+49 (0)30 123-45'), 'Telefonnummer mit erlaubten Zeichen');
    T::same('', MemberSanitizer::phone('<script>'), 'Telefonnummer mit Fremdzeichen verworfen');
    T::same('weekly', MemberSanitizer::choice('weekly', MemberService::NOTIFICATION_FREQUENCIES, 'immediate'), 'erlaubte Frequenz');
    T::same('immediate', MemberSanitizer::choice('hourly', MemberService::NOTIFICATION_FREQUENCIES, 'immediate'), 'unbekannte Frequenz → Standard');
    T::same('', MemberSanitizer::profileUrl('ftp://example.org'), 'Profil-URL: ftp verworfen');
});

T::run('Sprachkatalog: jeder member.*-Schlüssel steht in de.yaml und en.yaml (MD-27)', static function (): void {
    $used = [];
    $files = array_merge(glob(ABSPATH . 'member/*.php') ?: [], glob(ABSPATH . 'member/*/*.php') ?: [], glob(ABSPATH . 'core/Member/*.php') ?: [],
        [ABSPATH . 'core/Services/MemberService.php', ABSPATH . 'core/Services/MemberContentService.php']);
    foreach ($files as $file) {
        preg_match_all("/'(member\\.[a-z0-9_.]+[a-z0-9_])'/", (string) file_get_contents($file), $matches);
        $used = array_merge($used, $matches[1]);
    }
    $dynamic = ['member.schluessel'];
    foreach (['notifications.frequency_' => MemberService::NOTIFICATION_FREQUENCIES, 'privacy.visibility_' => ['public', 'members', 'private'],
        'subscription.status_' => ['paid', 'pending', 'cancelled', 'refunded', 'failed']] as $prefix => $values) {
        foreach ($values as $value) {
            $used[] = 'member.' . $prefix . $value;
        }
    }
    $used = array_values(array_filter(array_diff(array_unique($used), $dynamic), static fn (string $key): bool => !str_ends_with($key, '_')));
    T::check(count($used) > 200, 'Templates nutzen member.*-Schlüssel', (string) count($used));

    $catalogs = [];
    foreach (['de', 'en'] as $locale) {
        preg_match_all('/^\s+"(member\.[A-Za-z0-9_.-]+)":\s*"((?:[^"\\\\]|\\\\.)*)"\s*$/m', memberSource('lang/' . $locale . '.yaml'), $matches);
        $catalogs[$locale] = array_combine($matches[1], $matches[2]);
        T::same([], array_values(array_diff($used, array_keys($catalogs[$locale]))), $locale . '.yaml enthält alle verwendeten Schlüssel');
    }
    T::same([], array_values(array_diff(array_keys($catalogs['de']), array_keys($catalogs['en']))), 'de.yaml und en.yaml haben dieselben member.*-Schlüssel');
    T::check(!in_array('', $catalogs['en'], true), 'en.yaml ohne leere Werte');
});

T::run('Sprachhelfer: Platzhalter, Sprache der Anfrage, Datumsformat', static function (): void {
    MemberI18n::reset('de', 'de');
    T::same('Willkommen zurück, Ada!', MemberI18n::t('member.dashboard.greeting_default', ['name' => 'Ada']), 'Deutsch mit Platzhalter');
    MemberI18n::reset('de', 'en');
    T::same('Welcome back, Ada!', MemberI18n::t('member.dashboard.greeting_default', ['name' => 'Ada']), 'Englisch über Katalog');
    T::same('en', MemberI18n::locale(), 'Sprache der Anfrage');
    T::same('member.unbekannt', MemberI18n::t('member.unbekannt'), 'unbekannter Schlüssel bleibt Schlüssel');
    MemberI18n::reset('de', 'de');
    T::same('05.03.2026 14:07', MemberI18n::formatDate('2026-03-05 14:07:00'), 'Datum de mit Uhrzeit');
    T::same('–', MemberI18n::formatDate('0000-00-00 00:00:00'), 'Nulldatum → –');
    T::same('vor 2 Std.', MemberI18n::relative((string) (time() - 7200)), 'relative Zeit');
    $header = memberSource('member/partials/header.php');
    T::check(str_contains($header, '<html lang="<?= $e($htmlLang) ?>">') && str_contains($header, '$htmlLang = MemberI18n::locale();'), '<html lang> aus der Sprache der Anfrage');
});

T::run('Widget-Vertrag: html und anchor aus der Registry (MD-20, NL-15)', static function (): void {
    $html = '<section class="card cms-nl-widget" id="newsletter"><h2>Newsletter</h2></section>';
    $widget = MemberController::sanitizeDashboardWidget([
        'plugin' => 'cms-newsletter', 'slug' => 'newsletter', 'title' => '<b>News</b>', 'html' => $html,
        'anchor' => 'news letter"<x>', 'badge' => 'Neu', 'admin_link' => '/admin/newsletter', 'color' => 'blue',
    ]);
    T::same($html, $widget['html'] ?? null, 'html wird unverändert durchgereicht');
    T::same('newsletterx', $widget['anchor'], 'anchor bereinigt');
    T::same('News', $widget['title'], 'Titel ohne Tags');
    T::same('Neu', $widget['badge'], 'badge übernommen');
    T::same('/admin/newsletter', $widget['admin_link'], 'admin_link übernommen');
    T::same('#4f46e5', $widget['color'], 'ungültige Farbe → Standard');
    T::check(!array_key_exists('html', MemberController::sanitizeDashboardWidget(['title' => 'A', 'html' => '  '])), 'leeres html entfällt');
    T::check(!array_key_exists('html', MemberController::sanitizeDashboardWidget(['title' => 'A', 'html' => ['x']])), 'html muss String sein');

    $dashboard = memberSource('member/dashboard.php');
    T::check(str_contains($dashboard, '<div class="member-widget member-widget--custom h-100">') && str_contains($dashboard, '<?= $widget[\'html\'] ?>'),
        'Dashboard gibt html ohne eigenes id-Attribut im Container aus');
    T::check(str_contains($dashboard, "\$widget['badge']") && str_contains($dashboard, "\$widget['admin_link']"), 'Kachel zeigt badge und admin_link');
    T::check(strpos($dashboard, '$controller->getDashboardData()') < strpos($dashboard, "include __DIR__ . '/partials/header.php'"),
        'Dashboard-Daten (inkl. Widget-Callbacks und deren Flash) vor header.php/alerts.php');
});

T::run('Layout-Einstellungen: section_order, dashboard_columns, widgets (MD-19)', static function (): void {
    T::same(['quick_start', 'plugins', 'stats', 'widgets'], MemberController::normalizeSectionOrder('plugins,stats,widgets'), 'Schnellstart vorne ergänzt');
    T::same(['quick_start', 'stats', 'widgets', 'plugins'], MemberController::normalizeSectionOrder('stats,foo,stats'), 'unbekannte und doppelte Werte entfallen');
    T::same('col-12', MemberController::widgetColumnClass(1), '1 Spalte');
    T::same('col-12 col-sm-6 col-xl-3', MemberController::widgetColumnClass(9), 'mehr als 4 → 4 Spalten');
    $dashboard = memberSource('member/dashboard.php');
    T::check(str_contains($dashboard, "\$widgetOn('quick_links')") && str_contains($dashboard, "\$widgetOn('statistics')"), 'Allowlist steuert Schnellzugriff und Kennzahlen');
    T::check(str_contains($dashboard, "foreach ((array) (\$layout['section_order'] ?? []) as \$section)"), 'Bereiche in gespeicherter Reihenfolge');
    $module = memberSource('admin/modules/member/MemberDashboardModule.php');
    T::check(str_contains($module, "array_key_exists('member_dashboard_widgets', \$settings)"), 'nie gespeicherte Allowlist = alle Kern-Widgets');
});

T::run('Menü: Gruppen, Priorität und Unterpunkte (MD-21)', static function (): void {
    $tree = memberTestController()->buildMenuTree([
        ['slug' => 'plugin_jobs', 'label' => 'Jobs', 'url' => '/member/plugin/jobs', 'category' => 'plugins', 'priority' => 20],
        ['slug' => 'admin', 'label' => 'Admin', 'url' => '/admin', 'category' => 'admin', 'priority' => 0],
        ['slug' => 'plugin_jobs-mine', 'label' => 'Meine Jobs', 'url' => '/member/plugin/jobs-mine', 'category' => 'plugins', 'parent_slug' => 'jobs'],
        ['slug' => 'plugin_alpha', 'label' => 'Alpha', 'url' => '/member/plugin/alpha', 'category' => 'plugins', 'priority' => 10],
        ['slug' => 'profile', 'label' => 'Profil', 'url' => '/member/profile', 'category' => 'core', 'priority' => 10],
        ['slug' => 'x', 'label' => 'Böse', 'url' => 'javascript:alert(1)', 'category' => 'core', 'priority' => 5],
    ], 'plugin_jobs-mine');
    T::same(['x', 'profile', 'plugin_alpha', 'plugin_jobs', 'admin'], array_column($tree, 'slug'), 'Reihenfolge: Gruppe, dann Priorität');
    T::same('#', $tree[0]['url'], 'unsicherer Menülink → #');
    $jobs = $tree[3];
    T::same(['plugin_jobs-mine'], array_column($jobs['children'], 'slug'), 'Unterpunkt über parent_slug');
    T::check(!empty($jobs['expanded']) && !empty($jobs['children'][0]['active']), 'aktiver Unterpunkt klappt Elterneintrag auf');
});

T::run('CSRF: ein Token je Aktion und Request (MD-04)', static function (): void {
    $_SESSION = [];
    $controller = memberTestController();
    $tokens = [];
    for ($i = 0; $i < 40; $i++) {
        $tokens[] = $controller->csrfToken('media_action');
    }
    T::same(1, count(array_unique($tokens)), '40 Formulare teilen sich ein Token');
    T::check(\CMS\Security::instance()->verifyToken($tokens[0], 'member_media_action'), 'Token der ersten Zeile bleibt gültig');
    T::check(!\CMS\Security::instance()->verifyToken($tokens[0], 'member_media_action'), 'Token ist danach verbraucht (Einmal-Token)');
    $media = memberSource('member/media.php');
    T::same(0, substr_count($media, "csrfToken('media_action')") - 1, 'media.php erzeugt das Token genau einmal');
});

T::run('Benachrichtigungen: anlegen, Einstellungen, gelesen markieren (MD-17, MD-18)', static function (): void {
    $db = memberTestDatabase();
    $service = NotificationService::getInstance();
    $first = $service->create(7, 'system', 'Willkommen', 'Hallo', '/member/profile');
    $second = $service->create(7, 'message', '<b>Neue Nachricht</b>', '', 'javascript:alert(1)');
    T::check($first > 0 && $second > $first, 'Benachrichtigungen angelegt');
    T::same(0, $service->create(7, 'spam', 'x'), 'unbekannter Typ abgelehnt');
    T::same(0, $service->create(7, 'event', ''), 'leerer Titel und Text abgelehnt');
    $row = $db->get_row('SELECT * FROM cms_notifications WHERE id = ?', [$second]);
    T::same(['Neue Nachricht', null], [$row->title ?? '', $row->url], 'Titel ohne Tags, unsichere URL verworfen');

    MemberService::getInstance()->updateNotificationPreferences(7, ['notify_messages' => false, 'notification_frequency' => 'hourly', 'evil' => 1]);
    MemberService::getInstance()->flushUserMetaCache();
    $prefs = MemberService::getInstance()->getNotificationPreferences(7);
    T::same([false, 'immediate', false], [$prefs['notify_messages'], $prefs['notification_frequency'], array_key_exists('evil', $prefs)], 'Einstellungen per Allowlist gespeichert');
    T::same(0, $service->create(7, 'message', 'Abbestellt'), 'abbestellter Typ wird nicht angelegt');
    T::check($service->create(7, 'system', 'System immer') > 0, 'Systemmeldung trotz Abbestellung');

    T::same(3, $service->countUnread(7), 'drei ungelesen');
    T::check($service->markRead(7, $first), 'eine als gelesen markiert');
    T::check(!$service->markRead(8, $second), 'fremde Benachrichtigung nicht markierbar');
    T::check(!$service->markRead(7, $first), 'bereits gelesene liefert false');
    T::same(2, $service->markAllRead(7), 'alle übrigen als gelesen markiert');
    T::same(0, $service->countUnread(7), 'keine ungelesen');
    $recent = $service->getRecent(7, 10);
    T::check(count($recent) === 3 && $recent[0]->is_read === true && is_string($recent[0]->created_at), 'getRecent liefert typisierte Objekte');
    T::same([], $service->getRecent(7, 10, true), 'unreadOnly liefert nur ungelesene');

    $page = memberSource('member/notifications.php');
    T::check(str_contains($page, 'notification_mark_read') && str_contains($page, 'notifications_mark_all_read') && str_contains($page, "csrfToken('notifications_read')"),
        'Seite bietet „gelesen“ und „alle gelesen“ mit CSRF-Token');
    T::check(str_contains(memberSource('member/includes/class-member-controller.php'), "verifyCsrf('notifications_read')"), 'Controller prüft das Token der Lese-Aktionen');
});

T::run('Favoriten: Titel, Permalink, merken und entfernen (MD-16)', static function (): void {
    $db = memberTestDatabase();
    $db->execute("INSERT INTO cms_posts (id, title, slug, status, author_id, published_at, created_at) VALUES
        (1, 'Erster Beitrag', 'erster-beitrag', 'published', 3, '2026-01-02 10:00:00', '2026-01-02 10:00:00'),
        (2, 'Entwurf', 'entwurf', 'draft', 3, NULL, '2026-01-03 10:00:00')");
    $service = MemberContentService::getInstance();
    T::check($service->toggleFavorite(5, 1), 'veröffentlichter Beitrag gemerkt');
    T::check(!$service->toggleFavorite(5, 2), 'Entwurf lässt sich nicht merken');
    $favorites = $service->getFavoritePosts(5);
    T::same(1, count($favorites), 'eine Favoritenzeile');
    T::same(['Erster Beitrag', '/blog/erster-beitrag', 1], [$favorites[0]['title'], $favorites[0]['url'], $favorites[0]['post_id']], 'Titel und Permalink aus posts');
    T::same(['favorites' => 1, 'comments' => 0, 'posts' => 0], $service->getContentStats(5), 'Zähler für Mitglied 5');
    T::same(1, $service->getContentStats(3)['posts'], 'Beitragszähler zählt nur veröffentlichte');
    T::check(!$service->toggleFavorite(5, 1), 'erneutes Umschalten entfernt');
    T::check(!$service->removeFavorite(5, 1), 'Entfernen ohne Eintrag liefert false');

    $db->execute("INSERT INTO cms_comments (post_id, user_id, content, status, post_date) VALUES (1, 5, '<p>Guter <b>Beitrag</b></p>', 'approved', '2026-02-01 08:00:00'),
        (1, 5, 'Spam', 'spam', '2026-02-02 08:00:00')");
    $comments = $service->getRecentComments(5);
    T::same(1, count($comments), 'nur freigegebene/offene Kommentare');
    T::same(['Guter Beitrag', 'Erster Beitrag', '/blog/erster-beitrag'], [$comments[0]['content'], $comments[0]['post_title'], $comments[0]['post_url']], 'Kommentar mit Beitragstitel und Link');

    $controller = memberSource('member/includes/class-member-controller.php');
    T::check(str_contains($controller, "verifyCsrf('favorites_action')") && str_contains($controller, "Security::safeLocalPath((string)(\$_POST['return_to']"), 'Merken/Entfernen mit CSRF und lokalem Rücksprung');
});

T::run('Sicherheit: Re-Auth, Login-Weiterleitung, kein SQL im Controller (MD-01, MD-10, MD-11, MD-12)', static function (): void {
    $controller = memberSource('member/includes/class-member-controller.php');
    T::check(preg_match("/in_array\\(\\\$action, \\['totp_start', 'totp_disable', 'backup_generate', 'passkey_register', 'passkey_delete', 'sessions_revoke_all', 'sso_link', 'sso_unlink'\\], true\\)\\)\\s*\\{\\s*\\\$currentPassword = [^;]+;\\s*\\\$reauth/", $controller) === 1,
        'passkey_delete und SSO-Verknüpfen/-Trennen verlangen das aktuelle Passwort');
    // 4.1.0 (1c): Die frische Microsoft-Anmeldung ersetzt das Passwort nur beim Hinzufügen eines Faktors.
    T::check(str_contains($controller, "\$currentPassword === '' && in_array(\$action, ['totp_start', 'passkey_register'], true) && EntraSsoService::isFreshSsoSession(\$userId)"),
        'SSO-Re-Auth nur für totp_start und passkey_register');
    T::check(preg_match("/privacy_delete_request'\\) \\{.*?verifyCurrentPassword/s", $controller) === 1, 'Löschantrag verlangt das aktuelle Passwort');
    T::check(str_contains(memberSource('member/privacy.php'), 'name="current_password"'), 'Löschformular hat ein Passwortfeld');
    T::check(str_contains($controller, "CmsAuthPageService::getInstance()->getPublicPath('login'") && !str_contains($controller, "'Location: /login"), 'Login-Weiterleitung über CmsAuthPageService');
    T::check(preg_match('/\b(SELECT|INSERT INTO|UPDATE \{|DELETE FROM)\b/', $controller) !== 1, 'kein SQL im MemberController');
    T::check(!str_contains(memberSource('core/Routing/MemberRouter.php'), 'CMSv2'), 'MemberRouter ohne @package CMSv2');
});

T::run('Oberfläche: Landmarks, Meldungen, CSP (UI-01, UI-08, UI-16, UI-12)', static function (): void {
    $header = memberSource('member/partials/header.php');
    T::check(strpos($header, 'class="member-skip-link" href="#main-content"') < strpos($header, "doAction('body_start')"), 'Skip-Link als erstes Element im body');
    T::check(str_contains($header, '<main id="main-content"') && str_contains($header, '<h1 class="page-title'), 'main-Landmark und Seitentitel als h1');
    T::check(str_contains($header, '<button type="button" class="nav-link d-flex lh-1 text-reset p-0 member-user-toggle" data-bs-toggle="dropdown" aria-expanded="false"'), 'Benutzermenü als Button');
    T::check(str_contains(memberSource('member/partials/sidebar.php'), '<nav class="member-nav" aria-label='), 'Sidebar-Navigation als nav');
    $alerts = memberSource('member/partials/alerts.php');
    T::check(str_contains($alerts, "? 'alert' : 'status'") && str_contains($alerts, '<button type="button" class="btn-close"'), 'Erfolg mit role=status, Schließen als Button');
    T::check(!str_contains(memberSource('member/partials/footer.php'), '$inlineJs'), 'kein ungenutzter Inline-Script-Block');

    foreach (array_merge(glob(ABSPATH . 'member/*.php') ?: [], glob(ABSPATH . 'member/partials/*.php') ?: []) as $file) {
        $source = (string) file_get_contents($file);
        $name = substr($file, strlen(ABSPATH));
        if (in_array($name, ['member/plugin-section.php', 'member/partials/plugin-not-found.php'], true)) {
            continue;
        }
        T::check(preg_match('/\sstyle="/', $source) !== 1, $name . ': keine style-Attribute');
        T::check(preg_match('/\son[a-z]+="/', $source) !== 1, $name . ': keine on*-Handler');
    }
    foreach (array_merge([ABSPATH . 'assets/js/member-dashboard.js'], glob(ABSPATH . 'assets/js/member/*.js') ?: []) as $file) {
        T::check(!str_contains((string) file_get_contents($file), 'window.alert'), basename($file) . ': keine window.alert-Meldungen');
    }
    $css = memberSource('assets/css/member-dashboard.css');
    T::check(str_contains($css, 'prefers-reduced-motion') && str_contains($css, ':focus-visible') && !str_contains($css, 'filepond'), 'CSS: Fokus, reduzierte Bewegung, tote Regel entfernt');
    T::check(preg_match('/rgb\(99 102 241/', $css) !== 1 && !str_contains($css, 'max-width: 1024px'), 'CSS: keine festen Indigo-Werte, Bootstrap-Breakpoints');
});

T::finish();
