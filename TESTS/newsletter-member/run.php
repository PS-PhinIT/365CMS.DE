<?php
/**
 * Newsletter im Mitgliederbereich (MD-05 … MD-08, NL-01 … NL-19): Plugin-Registry (PRG, CSRF, Widget-Callback),
 * NewsletterMemberService (Einwilligung, Double-Opt-In, Kontobindung, Namen, Rate-Limit, erneutes Senden,
 * Abmelden, Audit-Log), Token-Rotation beim Bestätigen und die Widget-Vorlage (Escaping, Rollen, Anker).
 * Ohne Datenbank: SQLite-Attrappe im Speicher.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/newsletter-member/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');
define('SITE_URL', 'https://example.org');

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

T::$verbose = in_array('-v', $argv ?? [], true);

use CMS\Database;
use CMS\Member\PluginDashboardRegistry;
use CMS\Services\Newsletter\NewsletterConsentLog;
use CMS\Services\Newsletter\NewsletterMemberSection;
use CMS\Services\Newsletter\NewsletterMemberService;
use CMS\Services\Newsletter\NewsletterRepository;
use CMS\Services\Newsletter\NewsletterSettings;

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

$_SESSION = [];

/** Datenbank-Attrappe: SQLite im Speicher (Abonnenten, Versandzeilen, Einstellungen). */
function nlmDatabase(array $settings = []): Database
{
    $db = new class () extends Database {
        public PDO $sqlite;

        public function __construct()
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec("CREATE TABLE cms_newsletter_subscribers (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, first_name TEXT, last_name TEXT,
                status TEXT, source TEXT, segment_slug TEXT, locale TEXT, user_id INTEGER, optin_token TEXT, optin_sent_at TEXT, consent_at TEXT,
                consent_text TEXT, confirmed_at TEXT, unsubscribed_at TEXT, last_sent_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
            $this->sqlite->exec('CREATE TABLE cms_newsletter_sends (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, subscriber_id INTEGER, status TEXT)');
            $this->sqlite->exec('CREATE TABLE cms_newsletter_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $sql = str_replace(['INSERT IGNORE', 'NOW()'], ['INSERT OR IGNORE', "datetime('now', 'localtime')"], $sql);
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute(array_values($params));

            return $stmt;
        }

        public function get_var(string $query, array $params = []): mixed
        {
            $value = $this->execute($query, $params)->fetchColumn();

            return $value === false ? null : $value;
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
    foreach ($settings as $key => $value) {
        $db->execute('INSERT INTO cms_newsletter_settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
    }

    (new ReflectionProperty(Database::class, 'instance'))->setValue(null, $db);
    $repo = (new ReflectionClass(NewsletterRepository::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(NewsletterRepository::class, 'db'))->setValue($repo, $db);
    (new ReflectionProperty(NewsletterRepository::class, 'p'))->setValue($repo, 'cms_');
    (new ReflectionProperty(NewsletterRepository::class, 'instance'))->setValue(null, $repo);
    NewsletterSettings::reset();

    return $db;
}

/** @return array<string, mixed> */
function nlmRow(Database $db, string $email): array
{
    return $db->execute('SELECT * FROM cms_newsletter_subscribers WHERE email = ?', [$email])->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Rate-Limit-Speicher im Speicher. */
function nlmStore(): object
{
    return new class () {
        /** @var array<string, mixed> */
        public array $data = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->data[$key] ?? $default;
        }

        public function set(string $key, mixed $value, ?int $ttl = null): bool
        {
            $this->data[$key] = $value;

            return true;
        }
    };
}

/**
 * Service mit kontrollierten Abhängigkeiten.
 *
 * @param array<string, mixed> $opts verified (bool), meta (array), locales (list), now (int), sent (ArrayObject)
 */
function nlmService(array $opts = []): NewsletterMemberService
{
    $sent = $opts['sent'] ?? new ArrayObject();
    $clock = $opts['clock'] ?? new ArrayObject(['now' => time()]);

    $service = new NewsletterMemberService([
        'repository' => NewsletterRepository::instance(),
        'verified_email' => static fn (object $user): string => !empty($opts['verified']) ? (string) $user->email : '',
        'user_meta' => static fn (int $userId): array => $opts['meta'] ?? [],
        'send_confirmation' => static function (array $row) use ($sent): bool {
            $sent[] = (int) $row['id'];
            NewsletterRepository::instance()->markConfirmationSent((int) $row['id']);

            return true;
        },
        'content_locales' => static fn (): array => $opts['locales'] ?? ['en'],
        'ui_locale' => static fn (): string => 'de',
        'clock' => static fn (): int => (int) $clock['now'],
        'rate_store' => $opts['store'] ?? nlmStore(),
    ]);
    (new ReflectionProperty(NewsletterMemberService::class, 'instance'))->setValue(null, $service);

    return $service;
}

/** @return ArrayObject<int, array<string, mixed>> */
function nlmAudit(): ArrayObject
{
    $log = new ArrayObject();
    NewsletterConsentLog::useWriter(static function (string $category, string $action, string $description, string $entityType, ?int $entityId, array $metadata) use ($log): void {
        $log[] = ['category' => $category, 'action' => $action, 'entity' => $entityType, 'id' => $entityId, 'meta' => $metadata];
    });

    return $log;
}

function nlmUser(int $id = 7, string $email = 'mitglied@example.org'): object
{
    return (object) ['id' => $id, 'email' => $email, 'username' => 'mitglied'];
}

function nlmFreshRegistry(): PluginDashboardRegistry
{
    $registry = (new ReflectionClass(PluginDashboardRegistry::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(PluginDashboardRegistry::class, 'instance'))->setValue(null, $registry);
    (new ReflectionProperty(PluginDashboardRegistry::class, 'adminVisibility'))->setValue($registry, []);
    (new ReflectionProperty(PluginDashboardRegistry::class, 'widgetMetaOverrides'))->setValue($registry, []);

    return $registry;
}

// ── Registry ────────────────────────────────────────────────────────────────

T::run('Registry: CSRF-Standard „core“, „plugin“ nur ausdrücklich (MD-06)', static function (): void {
    $registry = nlmFreshRegistry();
    $registry->register(['slug' => 'a', 'render_callback' => static fn () => null]);
    $registry->register(['slug' => 'b', 'render_callback' => static fn () => null, 'csrf' => 'plugin']);
    $registry->register(['slug' => 'c', 'render_callback' => static fn () => null, 'csrf' => 'irgendwas']);
    T::same('core', $registry->getSection('a')['csrf'], 'ohne Angabe: core');
    T::same('plugin', $registry->getSection('b')['csrf'], 'ausdrücklich plugin bleibt plugin');
    T::same('core', $registry->getSection('c')['csrf'], 'unbekannter Wert: core');
});

T::run('Registry: Ergebnis des post_callback → Flash und 303-Ziel (MD-05)', static function (): void {
    $o = PluginDashboardRegistry::resolvePostOutcome(['type' => 'success', 'message' => '<b>Gespeichert</b>', 'redirect' => '/member/dashboard#newsletter', 'payload' => ['source' => 'x']], '', '/member/plugin/x');
    T::same('/member/dashboard#newsletter', $o['redirect'], 'Weiterleitung auf das angegebene lokale Ziel');
    T::same(['type' => 'success', 'message' => 'Gespeichert', 'payload' => ['source' => 'x']], $o['flash'], 'Flash ohne HTML');
    $o = PluginDashboardRegistry::resolvePostOutcome(['type' => 'evil', 'message' => 'Hallo', 'redirect' => '//evil.example/'], '', '/member/plugin/x');
    T::same('/member/plugin/x', $o['redirect'], 'protokollrelatives Ziel wird verworfen');
    T::same('info', $o['flash']['type'] ?? null, 'unbekannter Typ wird info');
    T::same('/member/plugin/x', PluginDashboardRegistry::resolvePostOutcome(['redirect' => 'https://evil.example/'], '', '/member/plugin/x')['redirect'], 'externes Ziel wird verworfen');
    T::same('/member/plugin/x', PluginDashboardRegistry::resolvePostOutcome(['redirect' => "/member\r\nX: y"], '', '/member/plugin/x')['redirect'], 'Steuerzeichen im Ziel werden verworfen');
    $o = PluginDashboardRegistry::resolvePostOutcome(null, '', '/member/plugin/x');
    T::same(['redirect' => '/member/plugin/x', 'flash' => null, 'output' => ''], $o, 'ohne Rückgabe und ohne Ausgabe: PRG auf den Bereich');
    $o = PluginDashboardRegistry::resolvePostOutcome(null, '<p>alt</p>', '/member/plugin/x');
    T::same(['redirect' => null, 'flash' => null, 'output' => '<p>alt</p>'], $o, 'altes Muster (Ausgabe ohne Rückgabe) bleibt darstellbar');
    T::same('/member/plugin/x?ok=1', PluginDashboardRegistry::resolvePostOutcome('/member/plugin/x?ok=1', '', '/member/plugin/x')['redirect'], 'String-Rückgabe als Ziel');
});

T::run('Registry: Route-Parameter und Anker (MD-08, NL-19)', static function (): void {
    T::same(['action' => 'edit', 'id' => '12'], PluginDashboardRegistry::normalizeRouteParams(['action' => 'Edit', 'id' => '12']), 'action und id übernommen');
    T::same(['action' => 'scriptalert1'], PluginDashboardRegistry::normalizeRouteParams(['action' => '<script>alert(1)', 'id' => '1x']), 'Sonderzeichen entfernt, id nur Ziffern');
    T::same([], PluginDashboardRegistry::normalizeRouteParams([]), 'leer bleibt leer');
    T::same('newsletter', PluginDashboardRegistry::sanitizeAnchor('Newsletter'), 'Anker klein geschrieben');
    T::same('fallback', PluginDashboardRegistry::sanitizeAnchor('12', 'fallback'), 'ungültiger Anker → Rückfall');
    $router = (string) file_get_contents(ABSPATH . 'core/Routing/MemberRouter.php');
    T::check(!str_contains($router, "\$_GET['action']") && !str_contains($router, "\$_GET['id']"), 'MemberRouter schreibt keine Route-Parameter mehr in $_GET');
    T::check(str_contains($router, '$registry->handleRoute($slug, $params);'), 'MemberRouter übergibt die Parameter an handleRoute()');
});

T::run('Registry: ein CSRF-Token je Bereich und Request (NL-17)', static function (): void {
    $_SESSION = [];
    $registry = nlmFreshRegistry();
    $first = $registry->csrfToken('newsletter');
    T::same($first, $registry->csrfToken('newsletter'), 'zweiter Aufruf liefert dasselbe Token');
    T::check(str_contains($registry->csrfField('newsletter'), htmlspecialchars($first, ENT_QUOTES, 'UTF-8')), 'csrfField() nutzt dasselbe Token');
    T::same(1, count($_SESSION['csrf_tokens']['member_plugin_newsletter']['tokens'] ?? []), 'nur ein Token im Puffer');
    T::check($registry->csrfToken('contact') !== $first, 'anderer Bereich: eigenes Token');
    T::same(true, \CMS\Security::instance()->verifyToken($first, 'member_plugin_newsletter'), 'Token besteht die Core-Prüfung');
});

T::run('Registry: Widget-render_callback liefert html und anchor, Fehler → Kachel (NL-15)', static function (): void {
    nlmDatabase();
    $_SESSION = [];
    $registry = nlmFreshRegistry();
    $seen = new ArrayObject();
    $registry->register(['slug' => 'ok', 'render_callback' => static fn () => null, 'dashboard_widget' => [
        'title' => 'OK', 'anchor' => 'Mein-Widget',
        'render_callback' => static function (object $user, array $context) use ($seen): string {
            $seen['context'] = $context;

            return '<section id="mein-widget">ok</section>';
        },
    ]]);
    $registry->register(['slug' => 'throws', 'render_callback' => static fn () => null, 'dashboard_widget' => [
        'render_callback' => static function (): string {
            throw new RuntimeException('kaputt');
        },
    ]]);
    $registry->register(['slug' => 'empty', 'render_callback' => static fn () => null, 'dashboard_widget' => ['render_callback' => static fn (): string => '']]);
    $registry->register(['slug' => 'echo', 'render_callback' => static fn () => null, 'dashboard_widget' => ['render_callback' => static function (): string {
        echo '<p>Ausgabe</p>';

        return '';
    }]]);
    $registry->register(['slug' => 'plain', 'render_callback' => static fn () => null, 'dashboard_widget' => ['title' => 'Plain']]);

    ob_start();
    $widgets = $registry->getDashboardWidgets(nlmUser());
    $leaked = (string) ob_get_clean();
    $bySlug = [];
    foreach ($widgets as $widget) {
        $bySlug[$widget['slug']] = $widget;
    }
    T::same('<section id="mein-widget">ok</section>', $bySlug['ok']['html'] ?? null, 'html aus dem Callback');
    T::same('mein-widget', $bySlug['ok']['anchor'] ?? null, 'anchor bereinigt');
    T::same('dashboard', $seen['context']['return_to'] ?? null, 'Kontext: return_to = dashboard');
    T::same('/member/plugin/ok', $seen['context']['action_url'] ?? null, 'Kontext: action_url');
    T::check(str_contains((string) ($seen['context']['csrf_field'] ?? ''), 'name="csrf_token"'), 'Kontext: csrf_field');
    T::check(!isset($bySlug['throws']['html']) && ($bySlug['throws']['title'] ?? '') !== '', 'Ausnahme → normale Kachel');
    T::check(!isset($bySlug['empty']['html']), 'leerer String → normale Kachel');
    T::check(!isset($bySlug['echo']['html']) && $leaked === '', 'echo-Ausgabe wird verworfen');
    T::check(!array_key_exists('html', $bySlug['plain']) && !array_key_exists('anchor', $bySlug['plain']), 'ohne render_callback unverändert');
});

T::run('Registry: Wrapper und Fehlerseite (MD-05, MD-07)', static function (): void {
    $wrapper = (string) file_get_contents(ABSPATH . 'member/plugin-section.php');
    T::check(!str_contains($wrapper, 'post_callback'), 'plugin-section.php ruft post_callback nicht mehr nach der Ausgabe auf');
    $error = (string) file_get_contents(ABSPATH . 'member/partials/plugin-not-found.php');
    T::check(str_contains($error, "include __DIR__ . '/header.php';") && str_contains($error, "include __DIR__ . '/footer.php';"), 'Fehlerseite mit Kopf und Fuß des Mitgliederbereichs');
    T::check(!str_contains($error, 'SITE_URL'), 'kein absoluter SITE_URL-Link');
    $registry = (string) file_get_contents(ABSPATH . 'core/Member/PluginDashboardRegistry.php');
    T::check(!str_contains($registry, '<div class="alert alert-danger">Sicherheitsüberprüfung'), 'kein HTML-Fragment ohne Layout für 403');
    $handle = substr($registry, (int) strpos($registry, 'public function handleRoute('), 3000);
    T::check(strpos($handle, 'verifyPostRequest') < strpos($handle, 'dispatchPost') && strpos($handle, 'dispatchPost') < strpos($handle, 'renderSection'), 'Prüfung → Verarbeitung → Darstellung');
    T::check(str_contains($registry, "header('Location: ' . \$path, true, 303);"), 'Weiterleitung mit 303');
});

T::run('Form- und Feed-Bereich liefern Meldungen statt Markup (MD-05)', static function (): void {
    foreach (['core/Services/Forms/FormMemberSection.php', 'core/Services/Feed/FeedMemberSection.php'] as $file) {
        $source = (string) file_get_contents(ABSPATH . $file);
        T::check(str_contains($source, 'public static function handlePost(object $user, array $params = []): ?array'), basename($file) . ': handlePost() gibt die Meldung zurück');
        T::check(str_contains($source, "return \$notice !== null ? ['type' => \$notice[0], 'message' => \$notice[1]] : null;"), basename($file) . ': Meldung als type/message');
    }
});

// ── Service ─────────────────────────────────────────────────────────────────

T::run('Service: Anmeldung nur mit Einwilligung, Namen aus user_meta (NL-01, NL-02)', static function (): void {
    $db = nlmDatabase(['require_double_opt_in' => '0']);
    $audit = nlmAudit();
    $service = nlmService(['verified' => true, 'meta' => ['first_name' => 'Erika', 'last_name' => 'Muster']]);
    $user = nlmUser();

    $r = $service->subscribeMember($user, ['segment' => '', 'locale' => 'de']);
    T::same('consent_required', $r['code'], 'ohne Haken: keine Anmeldung');
    T::same([], nlmRow($db, 'mitglied@example.org'), 'kein Eintrag angelegt');

    $r = $service->subscribeMember($user, ['consent' => '1', 'locale' => 'de']);
    $row = nlmRow($db, 'mitglied@example.org');
    T::same('subscribed', $r['code'], 'bestätigte Adresse ohne Double-Opt-In: sofort aktiv');
    T::same('active', $row['status'] ?? null, 'Status aktiv');
    T::same(7, (int) ($row['user_id'] ?? 0), 'an das Konto gebunden');
    T::same(['Erika', 'Muster'], [$row['first_name'] ?? null, $row['last_name'] ?? null], 'Namen aus user_meta');
    T::same('member', $row['source'] ?? null, 'Quelle member');
    T::same(NewsletterSettings::DEFAULTS['consent_text'], $row['consent_text'] ?? null, 'Einwilligungstext in der Sprache der Oberfläche gespeichert');
    T::same('newsletter.subscribe', $audit[0]['action'] ?? null, 'Audit-Log: subscribe');
    T::same('member', $audit[0]['meta']['source'] ?? null, 'Audit-Log: Quelle member');
    T::same(NewsletterSettings::DEFAULTS['consent_text'], $audit[0]['meta']['consent_text'] ?? null, 'Audit-Log: Einwilligungstext');
    T::check(!in_array('mitglied@example.org', array_map('strval', $audit[0]['meta']), true), 'Audit-Log ohne E-Mail-Adresse');
    T::same('active', $service->statusFor($user)['status'], 'statusFor(): active');
});

T::run('Service: Double-Opt-In laut Einstellung und bei unbestätigter Konto-Adresse (NL-03)', static function (): void {
    $db = nlmDatabase(['require_double_opt_in' => '1']);
    nlmAudit();
    $sent = new ArrayObject();
    $service = nlmService(['verified' => true, 'sent' => $sent]);
    $r = $service->subscribeMember(nlmUser(), ['consent' => 'on']);
    T::same('check_inbox', $r['code'], 'mit Double-Opt-In: Bestätigungsmail');
    T::same('pending', nlmRow($db, 'mitglied@example.org')['status'] ?? null, 'Status pending');
    T::same(1, count($sent), 'eine Bestätigungsmail');

    $db = nlmDatabase(['require_double_opt_in' => '0']);
    $sent = new ArrayObject();
    $service = nlmService(['verified' => false, 'sent' => $sent]);
    T::same('unverified', $service->statusFor(nlmUser(8, 'neu@example.org'))['status'], 'statusFor(): unverified');
    T::same(true, $service->statusFor(nlmUser(8, 'neu@example.org'))['double_opt_in'], 'Hinweis: Double-Opt-In erzwungen');
    $r = $service->subscribeMember(nlmUser(8, 'neu@example.org'), ['consent' => '1']);
    $row = nlmRow($db, 'neu@example.org');
    T::same('check_inbox', $r['code'], 'unbestätigte Konto-Adresse: immer Double-Opt-In');
    T::same('pending', $row['status'] ?? null, 'Status pending trotz abgeschaltetem Double-Opt-In');
    T::same(0, (int) ($row['user_id'] ?? 0), 'keine Bindung an das Konto');
    T::same(1, count($sent), 'Bestätigungsmail an die Adresse');

    // Fremdes aktives Abonnement derselben Adresse: gleiche Meldung, keine Einsicht, keine Bindung.
    $db->execute("INSERT INTO cms_newsletter_subscribers (email, status, segment_slug, locale, optin_token) VALUES ('fremd@example.org', 'active', 'general', 'de', 'abc')");
    $r = $service->subscribeMember(nlmUser(9, 'fremd@example.org'), ['consent' => '1']);
    T::same('check_inbox', $r['code'], 'bestehendes Abo: dieselbe Meldung');
    T::same('unverified', $service->statusFor(nlmUser(9, 'fremd@example.org'))['status'], 'Status des fremden Abos bleibt verborgen');
    T::same(0, (int) (nlmRow($db, 'fremd@example.org')['user_id'] ?? 0), 'fremdes Abo nicht gebunden');
    T::same('not_subscribed', $service->unsubscribeMember(nlmUser(9, 'fremd@example.org'))['code'], 'fremdes Abo nicht abmeldbar');
    T::same('active', nlmRow($db, 'fremd@example.org')['status'] ?? null, 'fremdes Abo bleibt aktiv');
});

T::run('Service: erneut senden mit 10-Minuten-Sperre (NL-06)', static function (): void {
    $db = nlmDatabase(['require_double_opt_in' => '1']);
    $audit = nlmAudit();
    $sent = new ArrayObject();
    $clock = new ArrayObject(['now' => time()]);
    $service = nlmService(['verified' => true, 'sent' => $sent, 'clock' => $clock]);
    $user = nlmUser();
    $service->subscribeMember($user, ['consent' => '1']);
    T::same(1, count($sent), 'erste Bestätigungsmail');
    $status = $service->statusFor($user);
    T::same([false, true], [$status['can_resend'], $status['resend_wait'] > 0], 'direkt danach gesperrt');
    $r = $service->resendConfirmation($user);
    T::same(['resend_locked', 'warning'], [$r['code'], $r['type']], 'Sperre greift');
    T::same(1, count($sent), 'keine zweite Mail');

    $clock['now'] = time() + NewsletterRepository::CONFIRMATION_RESEND_LOCK + 5;
    T::same(true, $service->statusFor($user)['can_resend'], 'nach 10 Minuten wieder möglich');
    $r = $service->resendConfirmation($user);
    T::same('resent', $r['code'], 'erneut gesendet');
    T::same(2, count($sent), 'zweite Mail');
    T::same('newsletter.resend', $audit[count($audit) - 1]['action'] ?? null, 'Audit-Log: resend');
    T::same('not_pending', $service->resendConfirmation(nlmUser(99, 'x@example.org'))['code'], 'ohne Anmeldung: nichts zu senden');
});

T::run('Service: Rate-Limit 10 Aktionen je Stunde und Konto (NL-07)', static function (): void {
    nlmDatabase();
    nlmAudit();
    $store = nlmStore();
    $service = nlmService(['verified' => true, 'store' => $store]);
    $codes = [];
    for ($i = 0; $i < NewsletterMemberService::RATE_MAX + 1; $i++) {
        $codes[] = $service->handle(nlmUser(), 'subscribe', [])['code'];
    }
    T::same(array_merge(array_fill(0, NewsletterMemberService::RATE_MAX, 'consent_required'), ['rate_limited']), $codes, '11. Aktion wird abgewiesen');
    T::same('consent_required', $service->handle(nlmUser(8, 'b@example.org'), 'subscribe', [])['code'], 'anderes Konto nicht betroffen');
    T::same('invalid_action', $service->handle(nlmUser(10, 'c@example.org'), 'drop_table', [])['code'], 'unbekannte Aktion');
});

T::run('Service: Thema/Sprache, show_segment_choice und Inhaltssprachen (NL-08)', static function (): void {
    $db = nlmDatabase(['require_double_opt_in' => '0', 'segments' => "general|Allgemein|General\nevents|Events|Events", 'show_segment_choice' => '0']);
    nlmAudit();
    $service = nlmService(['verified' => true, 'locales' => ['en', 'fr']]);
    T::same(['de' => 'Deutsch', 'en' => 'English'], $service->localeChoices(), 'Sprachen: Deutsch + unterstützte Inhaltssprachen');
    T::same([], $service->segmentChoices('de'), 'Themenauswahl abgeschaltet: keine Auswahl');
    $service->subscribeMember(nlmUser(), ['consent' => '1', 'segment' => 'events', 'locale' => 'fr']);
    $row = nlmRow($db, 'mitglied@example.org');
    T::same(['general', 'de'], [$row['segment_slug'] ?? null, $row['locale'] ?? null], 'Thema ignoriert, unbekannte Sprache → Oberfläche');

    $db->execute("UPDATE cms_newsletter_settings SET setting_value = '1' WHERE setting_key = 'show_segment_choice'");
    NewsletterSettings::reset();
    T::same(['general' => 'Allgemein', 'events' => 'Events'], $service->segmentChoices('de'), 'Themenauswahl aktiv');
    $r = $service->handle(nlmUser(), 'update', ['segment' => 'events', 'locale' => 'en']);
    $row = nlmRow($db, 'mitglied@example.org');
    T::same(['saved', 'events', 'en'], [$r['code'], $row['segment_slug'] ?? null, $row['locale'] ?? null], 'aktives Abo: Thema und Sprache geändert');
    $service2 = nlmService(['verified' => true, 'locales' => []]);
    T::same(['de' => 'Deutsch'], $service2->localeChoices(), 'ohne weitere Inhaltssprache nur Deutsch');
});

T::run('Service: Abmelden und Zurückziehen mit Audit-Log (NL-10, NL-11)', static function (): void {
    $db = nlmDatabase(['require_double_opt_in' => '0']);
    $audit = nlmAudit();
    $service = nlmService(['verified' => true]);
    $user = nlmUser();
    $service->subscribeMember($user, ['consent' => '1']);
    $r = $service->unsubscribeMember($user);
    T::same('unsubscribed', $r['code'], 'aktiv → abgemeldet');
    T::same('unsubscribed', nlmRow($db, 'mitglied@example.org')['status'] ?? null, 'Status abgemeldet');
    T::same(['newsletter.unsubscribe', 'active'], [$audit[count($audit) - 1]['action'] ?? null, $audit[count($audit) - 1]['meta']['previous_status'] ?? null], 'Audit-Log: unsubscribe mit vorherigem Status');
    T::same('unsubscribed', $service->statusFor($user)['status'], 'statusFor(): unsubscribed');
    T::same('consent_required', $service->subscribeMember($user, [])['code'], 'Wieder anmelden nur mit Einwilligung');
    T::same('subscribed', $service->subscribeMember($user, ['consent' => '1'])['code'], 'Wieder anmelden');

    $db = nlmDatabase(['require_double_opt_in' => '1']);
    nlmAudit();
    $service = nlmService(['verified' => true]);
    $service->subscribeMember($user, ['consent' => '1']);
    T::same('withdrawn', $service->unsubscribeMember($user)['code'], 'pending → zurückgezogen');
    T::same('not_subscribed', $service->unsubscribeMember($user)['code'], 'zweites Abmelden: nichts zu tun');

    $db->execute("UPDATE cms_newsletter_subscribers SET status = 'bounced'");
    $status = $service->statusFor($user);
    T::same(['bounced', true], [$status['status'], $status['double_opt_in']], 'unzustellbar: erneute Anmeldung per Double-Opt-In');
});

T::run('Service: Rücksprungziele nur aus der Whitelist (NL-04)', static function (): void {
    T::same('/member/dashboard#newsletter', NewsletterMemberService::returnTarget('dashboard'), 'dashboard');
    T::same('/member/plugin/newsletter', NewsletterMemberService::returnTarget('section'), 'section');
    T::same('/member/plugin/newsletter', NewsletterMemberService::returnTarget('https://evil.example/'), 'fremdes Ziel → Unterseite');
    T::same('/member/plugin/newsletter', NewsletterMemberService::returnTarget(['dashboard']), 'Array → Unterseite');
});

T::run('Bereich: handlePost liefert Flash, Ziel und Kennung', static function (): void {
    nlmDatabase(['require_double_opt_in' => '0']);
    nlmAudit();
    nlmService(['verified' => true]);
    $_POST = ['newsletter_action' => 'subscribe', 'newsletter_consent' => '1', 'return_to' => 'dashboard'];
    $r = NewsletterMemberSection::handlePost(nlmUser());
    T::same('/member/dashboard#newsletter', $r['redirect'], 'Rücksprung ins Dashboard');
    T::same(['success', 'newsletter', 'dashboard', 'subscribed'], [$r['type'], $r['payload']['source'], $r['payload']['return_to'], $r['payload']['code']], 'Flash mit Kennung des Widgets');
    T::same('Newsletter abonniert.', $r['message'], 'Meldung aus dem Sprachkatalog');
    $_POST = ['newsletter_action' => 'unsubscribe', 'return_to' => '/admin'];
    $r = NewsletterMemberSection::handlePost(nlmUser());
    T::same('/member/plugin/newsletter', $r['redirect'], 'ungültiges return_to → Unterseite');
    $_POST = [];
});

// ── Token-Rotation ──────────────────────────────────────────────────────────

T::run('Token: Rotation beim Bestätigen, Abmeldelinks späterer Ausgaben gültig (NL-12)', static function (): void {
    $db = nlmDatabase();
    $repo = NewsletterRepository::instance();
    $repo->subscribe(['email' => 'doi@example.org', 'consent_text' => 'Ja'], true);
    $old = (string) nlmRow($db, 'doi@example.org')['optin_token'];
    $confirmed = $repo->confirm($old);
    $new = (string) nlmRow($db, 'doi@example.org')['optin_token'];
    T::same('active', nlmRow($db, 'doi@example.org')['status'] ?? null, 'bestätigt');
    T::check($new !== '' && $new !== $old, 'Token nach dem Bestätigen neu');
    T::same(true, $confirmed['confirmed_now'] ?? null, 'Rückgabe kennzeichnet den Übergang');
    T::same($new, $confirmed['optin_token'] ?? null, 'Rückgabe trägt das neue Token');
    T::same(null, $repo->subscriberByToken($old), 'Token der Bestätigungsmail ist verbraucht');
    $again = $repo->confirm($new);
    T::check($again !== null && empty($again['confirmed_now']) && (string) nlmRow($db, 'doi@example.org')['optin_token'] === $new, 'erneutes Bestätigen eines aktiven Abos rotiert nicht');
    // Ausgaben gehen nur an aktive Abonnenten – also mit dem neuen Token.
    T::check(str_contains(\CMS\Services\Newsletter\NewsletterMailer::unsubscribeUrl($new, 'de'), $new), 'Abmeldelink der Ausgaben nutzt das aktuelle Token');
    T::check($repo->unsubscribe($new) !== null && nlmRow($db, 'doi@example.org')['status'] === 'unsubscribed', 'Abmeldung mit dem aktuellen Token funktioniert');
});

// ── Vorlage ─────────────────────────────────────────────────────────────────

/** @param array<string, mixed> $status */
function nlmRender(array $status, array $overrides = []): string
{
    $status += ['status' => 'none', 'email' => 'mitglied@example.org', 'segment' => 'general', 'segment_label' => 'Allgemein', 'locale' => 'de',
        'confirmed_at' => null, 'optin_sent_at' => null, 'can_resend' => false, 'resend_wait' => 0, 'verified' => true, 'double_opt_in' => true, 'subscriber_id' => 0];

    return NewsletterMemberSection::includeTemplate(ABSPATH . 'views/member/widgets/newsletter.php', $overrides + [
        'status' => $status,
        'csrfField' => '<input type="hidden" name="csrf_token" value="TOKEN">',
        'actionUrl' => '/member/plugin/newsletter',
        'segments' => [],
        'locales' => ['de' => 'Deutsch', 'en' => 'English'],
        'consentText' => 'Ich willige ein <script>alert(1)</script>',
        'notice' => null,
        'returnTo' => 'dashboard',
    ]);
}

T::run('Vorlage: Inhalt je Status, Escaping, Rollen, Anker (NL-16 … NL-19)', static function (): void {
    nlmDatabase();
    $html = nlmRender(['status' => 'none'], ['notice' => ['type' => 'success', 'message' => 'Fertig <img src=x onerror=alert(1)>']]);
    T::check(str_contains($html, 'id="newsletter"'), 'Anker id="newsletter"');
    T::check(str_contains($html, 'role="status"') && str_contains($html, 'Fertig &lt;img'), 'Erfolg mit role="status", escaped');
    T::check(!str_contains($html, '<script>') && str_contains($html, 'Ich willige ein &lt;script&gt;'), 'Einwilligungstext escaped');
    T::check(preg_match('/<input type="email"[^>]*readonly/', $html) === 1, 'Konto-Adresse schreibgeschützt');
    T::check(preg_match('/name="newsletter_consent"[^>]*required/', $html) === 1, 'Pflicht-Checkbox');
    T::check(str_contains($html, 'name="return_to" value="dashboard"') && str_contains($html, 'value="TOKEN"'), 'return_to und CSRF-Token');
    T::check(str_contains($html, '<select class="form-select" id="nl-dash-locale" name="locale">'), 'Sprachauswahl bei zwei Sprachen');
    T::check(!str_contains($html, 'name="segment"'), 'keine Themenauswahl ohne Themen');
    T::check(str_contains($html, 'Nicht abonniert'), 'Status als Text-Badge');
    T::check(preg_match('/\son[a-z]+=|\sstyle=/i', nlmRender(['status' => 'none']) . nlmRender(['status' => 'active']) . nlmRender(['status' => 'pending'])) === 0, 'keine Inline-Handler, keine style-Attribute');

    $html = nlmRender(['status' => 'active', 'confirmed_at' => '2026-03-01 10:00:00'], ['segments' => ['general' => 'Allgemein', 'events' => 'Events'], 'notice' => ['type' => 'danger', 'message' => 'Fehler']]);
    T::check(str_contains($html, 'role="alert"'), 'Fehler mit role="alert"');
    T::check(str_contains($html, '01.03.2026') && str_contains($html, 'Abonniert'), 'bestätigt seit + Badge');
    T::check(preg_match('/data-cms-confirm="[^"]+"[^>]*>\s*<input type="hidden" name="csrf_token" value="TOKEN"><input type="hidden" name="newsletter_action" value="unsubscribe">/', $html) === 1, 'Abmelden mit data-cms-confirm');
    T::check(str_contains($html, 'value="update"') && str_contains($html, 'name="segment"'), 'Thema/Sprache ändern');
    T::check(!str_contains($html, 'newsletter_consent'), 'aktiv: keine Einwilligungs-Checkbox');

    $html = nlmRender(['status' => 'pending', 'optin_sent_at' => '2026-03-01 10:00:00', 'can_resend' => false, 'resend_wait' => 300]);
    T::check(str_contains($html, 'value="resend"') && str_contains($html, ' disabled aria-describedby="nl-dash-resend-hint"'), 'Erneut senden gesperrt mit Hinweis');
    T::check(str_contains($html, 'in 5 Min.'), 'Sperrdauer in Minuten');
    T::check(str_contains($html, 'Anmeldung zurückziehen'), 'Zurückziehen angeboten');

    $html = nlmRender(['status' => 'unverified', 'verified' => false]);
    T::check(str_contains($html, 'noch nicht bestätigt') && str_contains($html, 'Bestätigungslink'), 'unbestätigte Konto-Adresse: Hinweis auf Double-Opt-In');

    $html = nlmRender(['status' => 'unsubscribed'], ['returnTo' => 'section', 'locales' => ['de' => 'Deutsch']]);
    T::check(str_contains($html, 'Wieder anmelden') && str_contains($html, 'name="return_to" value="section"'), 'abgemeldet: Wieder anmelden (Unterseite)');
    T::check(str_contains($html, '<input type="hidden" name="locale" value="de">') && !str_contains($html, '<section'), 'eine Sprache: verstecktes Feld; Unterseite ohne eigene Karte');

    $html = nlmRender(['status' => 'bounced']);
    T::check(str_contains($html, 'Unzustellbar') && str_contains($html, 'nicht zugestellt'), 'unzustellbar: Hinweis');
});

T::run('Widget: Flash des Bereichs wird im Widget gezeigt und verbraucht', static function (): void {
    nlmDatabase(['require_double_opt_in' => '0']);
    nlmAudit();
    nlmService(['verified' => true]);
    nlmFreshRegistry();
    $_SESSION = ['member_alert' => ['type' => 'success', 'message' => 'Newsletter abonniert.', 'payload' => ['source' => 'newsletter', 'return_to' => 'dashboard']]];
    $html = NewsletterMemberSection::renderWidget(nlmUser());
    T::check(str_contains($html, 'role="status"') && str_contains($html, 'Newsletter abonniert.'), 'Meldung im Widget');
    T::check(!isset($_SESSION['member_alert']), 'Flash verbraucht (keine doppelte Meldung oben)');
    $_SESSION['member_alert'] = ['type' => 'success', 'message' => 'Profil gespeichert.', 'payload' => []];
    NewsletterMemberSection::renderWidget(nlmUser());
    T::same('Profil gespeichert.', $_SESSION['member_alert']['message'] ?? null, 'fremde Flash-Meldung bleibt für den Kopf');
});

T::run('Vorlage: Theme-Override <theme>/member/widgets/newsletter.php', static function (): void {
    $dir = sys_get_temp_dir() . '/cms-nlm-theme-' . bin2hex(random_bytes(4)) . '/';
    @mkdir($dir . 'member/widgets', 0777, true);
    file_put_contents($dir . 'member/widgets/newsletter.php', '<?php echo "THEME:" . htmlspecialchars($returnTo) . ":" . count($locales);');
    $theme = (new ReflectionClass(\CMS\ThemeManager::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(\CMS\ThemeManager::class, 'themePath'))->setValue($theme, $dir);
    (new ReflectionProperty(\CMS\ThemeManager::class, 'instance'))->setValue(null, $theme);
    T::same($dir . 'member/widgets/newsletter.php', NewsletterMemberSection::templatePath(), 'Theme-Vorlage hat Vorrang');
    nlmDatabase();
    nlmService(['verified' => true]);
    T::same('THEME:section:2', NewsletterMemberSection::renderTemplate(nlmUser(), 'section', null), 'Override erhält die Vertragsvariablen');
    unlink($dir . 'member/widgets/newsletter.php');
    T::same(ABSPATH . 'views/member/widgets/newsletter.php', NewsletterMemberSection::templatePath(), 'ohne Override: Core-Vorlage');
});

T::run('Einstellungen und Admin (NL-13, NL-14)', static function (): void {
    T::same('1', NewsletterSettings::DEFAULTS['member_widget_enabled'] ?? null, 'member_widget_enabled standardmäßig an');
    $module = (string) file_get_contents(ABSPATH . 'admin/modules/newsletter/NewsletterModule.php');
    T::check(str_contains($module, "'show_name_fields', 'member_widget_enabled']"), 'Schalter im Tab Allgemein gespeichert');
    T::check(str_contains($module, "'unsubscribed_at', 'user_id'], ';', '\"', '');"), 'CSV-Export mit Spalte user_id');
    $view = (string) file_get_contents(ABSPATH . 'admin/views/newsletter/subscriber.php');
    T::check(str_contains($view, "'/admin/users?action=edit&id=' . (int) \$nlAccount['id']"), 'Detailansicht verlinkt das Benutzerkonto');
    $settings = (string) file_get_contents(ABSPATH . 'admin/views/newsletter/settings.php');
    T::check(str_contains($settings, "\$nlSwitch('member_widget_enabled'"), 'Schalter in den Einstellungen');
});

T::run('Sprachdateien: newsletter.member.* in de und en vollständig', static function (): void {
    $keys = static function (string $file): array {
        preg_match_all('/^\s*"(newsletter\.member\.[^"]+)"\s*:/m', (string) file_get_contents(ABSPATH . 'lang/' . $file), $m);

        return $m[1];
    };
    $de = $keys('de.yaml');
    $en = $keys('en.yaml');
    T::same($de, $en, 'gleiche Schlüssel in gleicher Reihenfolge');
    $used = [];
    foreach (['views/member/widgets/newsletter.php', 'admin/views/newsletter/subscriber.php', 'admin/views/newsletter/settings.php'] as $file) {
        preg_match_all("/\\\$nlT\\('([a-z_]+)'/", (string) file_get_contents(ABSPATH . $file), $m);
        foreach ($m[1] as $key) {
            $used[] = 'newsletter.member.' . $key;
        }
        preg_match_all("/'(newsletter\\.member\\.[a-z_]+)'/", (string) file_get_contents(ABSPATH . $file), $m);
        $used = array_merge($used, $m[1]);
    }
    preg_match_all("/\\\$this->result\\((?:true|false), '[a-z]+', '([a-z_]+)'/", (string) file_get_contents(ABSPATH . 'core/Services/Newsletter/NewsletterMemberService.php'), $m);
    foreach ($m[1] as $key) {
        $used[] = 'newsletter.member.' . $key;
    }
    $used = array_values(array_unique(array_filter($used, static fn (string $k): bool => str_starts_with($k, 'newsletter.member.') && !str_ends_with($k, '_') && $k !== 'newsletter.member.')));
    T::same([], array_values(array_diff($used, $de)), 'alle verwendeten Schlüssel sind übersetzt');
    foreach (NewsletterConsentLog::ACTIONS as $action) {
        T::check(in_array('newsletter.member.admin_log_' . $action, $de, true), 'Einwilligungsverlauf: Bezeichnung für ' . $action);
    }
});

NewsletterConsentLog::useWriter(null);
T::finish();
