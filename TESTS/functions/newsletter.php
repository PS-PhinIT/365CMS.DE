<?php
/**
 * Newsletter (Prüfbereich „Fehlende Funktionen“): Einwilligungszeitpunkt, erneute Anmeldung unbestätigter
 * Adressen, eingefrorener Vorlageninhalt, gesperrte gestoppte Kampagnen, Adressprüfung der Einstellungen und
 * Meldungstext der Admin-Shell. Die Datenbank ist eine SQLite-Attrappe im Speicher.
 * Wird von TESTS/functions/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Database;
use CMS\Services\Newsletter\NewsletterDispatcher;
use CMS\Services\Newsletter\NewsletterRepository;
use CMS\Services\Newsletter\NewsletterSettings;

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

/** Datenbank-Attrappe: SQLite im Speicher, die wenigen MySQL-Eigenheiten der geprüften Abfragen übersetzt. */
function newsletterTestDatabase(): Database
{
    $db = new class () extends Database {
        public PDO $sqlite;

        public function __construct()
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec('CREATE TABLE cms_newsletter_subscribers (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, first_name TEXT, last_name TEXT,
                status TEXT, source TEXT, segment_slug TEXT, locale TEXT, user_id INTEGER, optin_token TEXT, optin_sent_at TEXT, consent_at TEXT,
                consent_text TEXT, confirmed_at TEXT, unsubscribed_at TEXT, last_sent_at TEXT, created_at TEXT)');
            $this->sqlite->exec('CREATE TABLE cms_newsletter_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, subject TEXT, content_html TEXT, content_text TEXT, status TEXT)');
            $this->sqlite->exec("CREATE TABLE cms_newsletter_campaigns (id INTEGER PRIMARY KEY AUTOINCREMENT, template_id INTEGER, name TEXT, subject TEXT, preview_text TEXT,
                content_html TEXT, content_text TEXT, segment_slug TEXT DEFAULT '', locale TEXT DEFAULT '', status TEXT, is_public INTEGER DEFAULT 0,
                scheduled_at TEXT, started_at TEXT, sent_at TEXT, recipient_count INTEGER DEFAULT 0)");
            $this->sqlite->exec('CREATE TABLE cms_newsletter_sends (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, subscriber_id INTEGER, status TEXT, UNIQUE (campaign_id, subscriber_id))');
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $sql = str_replace(['INSERT IGNORE', 'NOW()'], ['INSERT OR IGNORE', "datetime('now')"], $sql);
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

    // Dispatcher und Einstellungen holen die Datenbank über das Singleton.
    (new ReflectionProperty(Database::class, 'instance'))->setValue(null, $db);
    $repo = (new ReflectionClass(NewsletterRepository::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(NewsletterRepository::class, 'db'))->setValue($repo, $db);
    (new ReflectionProperty(NewsletterRepository::class, 'p'))->setValue($repo, 'cms_');
    (new ReflectionProperty(NewsletterRepository::class, 'instance'))->setValue(null, $repo);
    NewsletterSettings::reset();

    return $db;
}

/** @return array<string, mixed> */
function newsletterTestRow(Database $db, string $table, int $id): array
{
    return $db->execute("SELECT * FROM cms_newsletter_{$table} WHERE id = ?", [$id])->fetch(PDO::FETCH_ASSOC) ?: [];
}

T::run('Newsletter: Einwilligungszeitpunkt bleibt bei unveränderter Einwilligung erhalten', static function (): void {
    $db = newsletterTestDatabase();
    $repo = NewsletterRepository::instance();
    $db->execute("INSERT INTO cms_newsletter_subscribers (email, status, segment_slug, locale, consent_text, consent_at, optin_token)
        VALUES ('a@example.org', 'unsubscribed', 'general', 'de', 'Ich willige ein.', '2026-03-01 10:00:00', 'tok')");

    // Sammelaktion „Aktivieren“ und Mitgliederbereich reichen die komplette gespeicherte Zeile durch.
    T::same(1, $repo->bulkSubscribers('activate', [1]), 'Sammelaktion aktiviert den Abonnenten');
    $row = newsletterTestRow($db, 'subscribers', 1);
    T::same('active', $row['status'], 'Status ist aktiv');
    T::same('2026-03-01 10:00:00', $row['consent_at'], 'consent_at bleibt nach „Aktivieren“ unverändert');

    $repo->saveSubscriber(['locale' => 'en'] + $row, 1);
    T::same('2026-03-01 10:00:00', newsletterTestRow($db, 'subscribers', 1)['consent_at'], 'consent_at bleibt bei reiner Sprachänderung unverändert');

    $repo->saveSubscriber(['consent_text' => 'Neue Einwilligung.'] + $row, 1);
    $row = newsletterTestRow($db, 'subscribers', 1);
    T::same('Neue Einwilligung.', $row['consent_text'], 'Geänderter Einwilligungstext wird gespeichert');
    T::check($row['consent_at'] !== '2026-03-01 10:00:00', 'Geänderter Einwilligungstext setzt consent_at neu');

    $created = $repo->saveSubscriber(['email' => 'neu@example.org', 'status' => 'active', 'consent_text' => 'Import'], 0);
    T::check(!empty($created['success']) && (string) newsletterTestRow($db, 'subscribers', (int) $created['id'])['consent_at'] !== '', 'Neuanlage mit Einwilligungstext setzt consent_at');
});

T::run('Newsletter: erneute Anmeldung einer unbestätigten Adresse', static function (): void {
    $db = newsletterTestDatabase();
    $repo = NewsletterRepository::instance();
    $db->execute("INSERT INTO cms_newsletter_subscribers (email, status, segment_slug, locale, optin_token) VALUES ('p@example.org', 'pending', 'general', 'de', 'tok')");

    $result = $repo->subscribe(['email' => 'p@example.org', 'consent_text' => 'Ja'], true);
    T::same('pending', newsletterTestRow($db, 'subscribers', 1)['status'], 'Mit Double-Opt-In bleibt der Eintrag unbestätigt');
    T::same(true, $result['send_confirmation'] ?? null, 'Mit Double-Opt-In wird die Bestätigungsmail erneut angefordert');

    $result = $repo->subscribe(['email' => 'p@example.org', 'consent_text' => 'Ja'], false);
    $row = newsletterTestRow($db, 'subscribers', 1);
    T::same('active', $row['status'], 'Ohne Double-Opt-In wird der unbestätigte Eintrag aktiviert');
    T::check((string) $row['confirmed_at'] !== '' && (string) $row['consent_at'] !== '', 'Aktivierung setzt confirmed_at und consent_at');
    T::same(false, $result['send_confirmation'] ?? null, 'Ohne Double-Opt-In wird keine Bestätigungsmail angefordert');
    T::same(1, (int) $db->get_var('SELECT COUNT(*) FROM cms_newsletter_subscribers'), 'Es entsteht kein zweiter Eintrag');
});

T::run('Newsletter: Vorlageninhalt wird beim Versandstart in die Kampagne übernommen', static function (): void {
    $db = newsletterTestDatabase();
    $db->execute("INSERT INTO cms_newsletter_subscribers (email, status, segment_slug, locale, optin_token) VALUES ('a@example.org', 'active', 'general', 'de', 'tok')");
    $db->execute("INSERT INTO cms_newsletter_templates (name, subject, content_html, content_text, status) VALUES ('V', 'S', '<p>Vorlage</p>', 'Vorlage', 'active')");
    $db->execute("INSERT INTO cms_newsletter_campaigns (template_id, name, subject, content_html, content_text, status) VALUES (1, 'Ohne Inhalt', 'S', '', '', 'draft')");
    $db->execute("INSERT INTO cms_newsletter_campaigns (template_id, name, subject, content_html, content_text, status) VALUES (1, 'Mit Inhalt', 'S', '<p>Eigen</p>', '', 'draft')");

    T::same(true, NewsletterDispatcher::start(1)['success'], 'Kampagne ohne eigenen Inhalt startet mit dem Vorlageninhalt');
    $row = newsletterTestRow($db, 'campaigns', 1);
    T::same(['sending', '<p>Vorlage</p>', 'Vorlage'], [$row['status'], $row['content_html'], $row['content_text']], 'Vorlageninhalt steht nach dem Start in der Kampagne');

    $db->execute("UPDATE cms_newsletter_templates SET content_html = '<p>Geändert</p>', content_text = 'Geändert' WHERE id = 1");
    T::same('<p>Vorlage</p>', \CMS\Services\Newsletter\NewsletterMailer::content(newsletterTestRow($db, 'campaigns', 1))['html'], 'Spätere Vorlagenänderung verändert die gestartete Ausgabe nicht');

    T::same(true, NewsletterDispatcher::start(2)['success'], 'Kampagne mit eigenem Inhalt startet');
    T::same('<p>Eigen</p>', newsletterTestRow($db, 'campaigns', 2)['content_html'], 'Eigener Inhalt wird nicht überschrieben');
});

T::run('Newsletter: gestoppte Kampagne ist wie eine versendete gesperrt', static function (): void {
    $db = newsletterTestDatabase();
    $db->execute("INSERT INTO cms_newsletter_campaigns (name, subject, content_html, content_text, status, recipient_count) VALUES ('Alt', 'Betreff', '<p>Alt</p>', '', 'cancelled', 7)");

    $result = NewsletterRepository::instance()->saveCampaign(['name' => 'Neu', 'subject' => 'Neu', 'content_html' => '<p>Neu</p>', 'is_public' => '1'], 1);
    $row = newsletterTestRow($db, 'campaigns', 1);
    T::same(true, $result['success'], 'Speichern gelingt');
    T::same(['Alt', 'Betreff', '<p>Alt</p>', 7, 'cancelled'], [$row['name'], $row['subject'], $row['content_html'], (int) $row['recipient_count'], $row['status']], 'Inhalt, Empfängerzahl und Status bleiben unverändert');
    T::same(1, (int) $row['is_public'], 'Nur die Sichtbarkeit im Archiv wird übernommen');

    $view = (string) file_get_contents(ABSPATH . 'admin/views/newsletter/campaign.php');
    T::check(preg_match('/\$nlEditable\s*=[^;]*cancelled/', $view) !== 1, 'Die Kampagnenansicht behandelt „cancelled“ nicht als bearbeitbar');
});

T::run('Newsletter: ungültige Absender-/Antwortadresse wird erkannt', static function (): void {
    $keys = ['sender_name', 'sender_email', 'reply_to_email'];
    T::same(['sender_email'], NewsletterSettings::invalidEmailKeys(['sender_email' => 'news@firma', 'reply_to_email' => ''], $keys), 'Tippfehler in der Absenderadresse wird gemeldet, leere Antwortadresse nicht');
    T::same(['sender_email', 'reply_to_email'], NewsletterSettings::invalidEmailKeys(['sender_email' => 'x', 'reply_to_email' => ' a@b '], $keys), 'Beide Felder werden geprüft');
    T::same([], NewsletterSettings::invalidEmailKeys(['sender_email' => ' news@example.org ', 'reply_to_email' => 'antwort@example.org'], $keys), 'Gültige Adressen (auch mit Leerraum) gelten als gültig');
    T::same([], NewsletterSettings::invalidEmailKeys(['sender_email' => 'kaputt'], ['batch_size']), 'Felder anderer Tabs werden nicht geprüft');
});

T::run('Admin-Shell: Fehlschlag zeigt den Fehlertext, nicht den Erfolgstext', static function (): void {
    // Die Shell führt beim Einbinden Seitenlogik aus – nur die reine Funktion aus dem Quelltext laden.
    $source = (string) file_get_contents(ABSPATH . 'admin/partials/section-page-shell.php');
    if (!function_exists('cms_admin_section_shell_result_message')) {
        T::check(preg_match('/^function cms_admin_section_shell_result_message\(.*?^}/ms', $source, $match) === 1, 'Funktion cms_admin_section_shell_result_message ist vorhanden');
        eval($match[0] ?? '');
    }
    $message = 'cms_admin_section_shell_result_message';

    T::same('nicht gefunden', $message(['success' => false, 'message' => 'gelöscht', 'error' => 'nicht gefunden'], 'unbekannt'), 'Fehlschlag mit beiden Schlüsseln: error gewinnt');
    T::same('gelöscht', $message(['success' => true, 'message' => 'gelöscht', 'error' => 'nicht gefunden'], 'unbekannt'), 'Erfolg mit beiden Schlüsseln: message gewinnt');
    T::same('nur message', $message(['success' => false, 'message' => 'nur message'], 'unbekannt'), 'Fehlschlag ohne error: message bleibt Rückfall');
    T::same('nur message', $message(['success' => false, 'message' => 'nur message', 'error' => null], 'unbekannt'), 'Fehlschlag mit leerem error: message bleibt Rückfall');
    T::same('nur error', $message(['success' => true, 'error' => 'nur error'], 'unbekannt'), 'Ergebnis ohne message: error wie bisher');
    T::same('unbekannt', $message(['success' => false], 'unbekannt'), 'Ohne Text greift die Standardmeldung');
    T::same(2, substr_count($source, 'cms_admin_section_shell_result_message($result, $unknownActionMessage)'), 'Inline- und Flash-Meldung nutzen die Funktion');
});
