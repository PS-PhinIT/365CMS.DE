<?php
/**
 * Vier-Augen-Prinzip (4.1.0, Bereich 3a): Statuswerte, Freigaberecht (auch „zweite Person“ unter Admins),
 * Durchsetzung beim Speichern, Statusübergänge, Benachrichtigungsempfänger, Verlauf, Vorschau-Links (Hash, Ablauf,
 * Widerruf), Rechte-Migration sowie Quelltext-Prüfungen der Einbindung (Module, Controller, Route, Schema, Menü).
 * Ohne Datenbank: Der Dienst läuft gegen eine SQLite-Attrappe im Speicher.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/content-workflow/run.php [-v]
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

use CMS\Database;
use CMS\Services\Content\ContentStatus;
use CMS\Services\Content\ContentWorkflow as W;

T::$verbose = in_array('-v', $argv ?? [], true);

function wfSource(string $relative): string
{
    return (string) file_get_contents(ABSPATH . $relative);
}

/** SQLite-Attrappe mit den Tabellen, die ContentWorkflow liest und schreibt. */
function wfDatabase(): Database
{
    return new class () extends Database {
        public PDO $sqlite;

        public function __construct()
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec("CREATE TABLE cms_users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, display_name TEXT, role TEXT, status TEXT DEFAULT 'active')");
            foreach (['posts', 'pages'] as $table) {
                $this->sqlite->exec("CREATE TABLE cms_{$table} (id INTEGER PRIMARY KEY, title TEXT, status TEXT, author_id INTEGER, published_at TEXT, updated_at TEXT)");
            }
            $this->sqlite->exec('CREATE TABLE cms_content_workflow_events (id INTEGER PRIMARY KEY AUTOINCREMENT, content_type TEXT, content_id INTEGER, locale TEXT,
                action TEXT, from_status TEXT, to_status TEXT, user_id INTEGER, comment TEXT, created_at TEXT)');
            $this->sqlite->exec('CREATE TABLE cms_content_preview_links (id INTEGER PRIMARY KEY AUTOINCREMENT, content_type TEXT, content_id INTEGER,
                token_hash TEXT UNIQUE, expires_at TEXT, created_by INTEGER, created_at TEXT)');
            $this->sqlite->exec('CREATE TABLE cms_role_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, role TEXT, capability TEXT, granted INTEGER, UNIQUE (role, capability))');
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
}

/**
 * Konten: 1 = Admin Anna (Autorin), 2 = Admin Ben, 3 = Redakteur Carla, 4 = Autor Dirk (ohne Recht), 5 = inaktiver Admin.
 *
 * @return array{db: Database, workflow: W, sent: \ArrayObject, clock: \ArrayObject, second: \ArrayObject}
 */
function wfFixture(bool $secondPerson = true): array
{
    $db = wfDatabase();
    $db->execute("INSERT INTO cms_users (id, username, email, display_name, role, status) VALUES
        (1, 'anna', 'anna@example.test', 'Anna', 'admin', 'active'),
        (2, 'ben', 'ben@example.test', 'Ben', 'admin', 'active'),
        (3, 'carla', 'carla@example.test', 'Carla', 'editor', 'active'),
        (4, 'dirk', 'dirk@example.test', 'Dirk', 'author', 'active'),
        (5, 'eva', 'eva@example.test', 'Eva', 'admin', 'inactive')");
    $db->execute("INSERT INTO cms_posts (id, title, status, author_id, published_at, updated_at) VALUES
        (10, 'Entwurf Anna', 'draft', 1, NULL, '2026-11-01 08:00:00'),
        (11, 'Geplant Anna', 'draft', 1, '2026-12-24 09:00:00', '2026-11-01 08:00:00'),
        (12, 'Entwurf Dirk', 'draft', 4, NULL, '2026-11-01 08:00:00'),
        (13, 'Live', 'published', 1, '2026-10-01 08:00:00', '2026-10-01 08:00:00'),
        (14, 'Papierkorb', 'trash', 1, NULL, '2026-10-01 08:00:00')");
    $db->execute("INSERT INTO cms_pages (id, title, status, author_id, published_at, updated_at) VALUES (20, 'Seite ohne Autor', 'draft', NULL, NULL, '2026-11-01 08:00:00')");

    $sent = new \ArrayObject();
    $clock = new \ArrayObject(['now' => strtotime('2026-11-15 10:00:00')]);
    $second = new \ArrayObject(['on' => $secondPerson]);
    $roleCaps = [
        'editor' => ['posts.publish' => true, 'pages.publish' => true],
        'author' => [],
    ];
    $workflow = new W(
        $db,
        static fn (): bool => (bool) $second['on'],
        static fn (string $role, string $cap): bool => $role === 'admin' || !empty($roleCaps[$role][$cap]),
        static fn (): int => (int) $clock['now'],
        static function (array $recipients, string $subject, string $message, string $url) use ($sent): void {
            $sent[] = ['ids' => array_map(static fn (object $u): int => (int) $u->id, $recipients), 'subject' => $subject, 'message' => $message, 'url' => $url];
        }
    );

    return ['db' => $db, 'workflow' => $workflow, 'sent' => $sent, 'clock' => $clock, 'second' => $second];
}

/** Capability-Abfrage für ein Konto der Fixture. */
function wfCan(int $userId): \Closure
{
    return static fn (string $cap): bool => match ($userId) {
        1, 2 => true,
        3 => in_array($cap, ['posts.publish', 'pages.publish'], true),
        default => false,
    };
}

T::run('ContentStatus: Werte und Bedeutung', static function (): void {
    T::check(ContentStatus::isPublishing('published') && ContentStatus::isPublishing('private'), 'published und private gelten als Veröffentlichung');
    T::check(!ContentStatus::isPublishing('pending') && !ContentStatus::isPublishing('draft'), 'pending und draft sind keine Veröffentlichung');
    T::check(ContentStatus::isPublic('published') && !ContentStatus::isPublic('pending') && !ContentStatus::isPublic('private'), 'öffentlich ist nur published');
    T::check(ContentStatus::isEditable('pending') && !ContentStatus::isEditable('trash') && !ContentStatus::isEditable('scheduled'), 'pending speicherbar, trash/scheduled nicht');
    T::same('Zur Prüfung', ContentStatus::label('pending'), 'Bezeichnung „Zur Prüfung“');
    T::same(['draft', 'pending', 'published', 'private', 'scheduled'], array_keys(ContentStatus::badgeMap()), 'Badge-Map für die Editoren inkl. pending und scheduled');
});

T::run('Freigaberecht (canPublishWith)', static function (): void {
    $all = static fn (string $cap): bool => true;
    $none = static fn (string $cap): bool => false;
    $postsOnly = static fn (string $cap): bool => $cap === 'posts.publish';
    T::check(!W::canPublishWith('post', 4, 4, $none, false), 'ohne posts.publish kein Veröffentlichen');
    T::check(W::canPublishWith('post', 1, 1, $all, false), 'mit Recht und ohne Einstellung: eigene Inhalte veröffentlichen');
    T::check(!W::canPublishWith('post', 1, 1, $all, true), 'Einstellung „zweite Person“: eigene Inhalte nicht – auch nicht als Admin');
    T::check(W::canPublishWith('post', 2, 1, $all, true), 'Einstellung „zweite Person“: fremde Inhalte freigeben');
    T::check(!W::canPublishWith('post', 2, 1, $all, true, 2), 'Einstellung „zweite Person“: selbst eingereichte fremde Inhalte nicht');
    T::check(W::canPublishWith('page', 2, 0, $all, true, 1), 'Seite ohne Autor: Freigabe durch andere Person als die Einreichende');
    T::check(!W::canPublishWith('page', 1, 0, $postsOnly, false), 'Seiten verlangen pages.publish');
    T::check(!W::canPublishWith('post', 0, 1, $all, false), 'ohne Konto kein Veröffentlichen');
    T::check(!W::canPublishWith('download', 1, 2, $all, false), 'unbekannter Typ: nie');
    T::same('posts.publish', W::capabilityFor('post'), 'Recht für Beiträge');
    T::same('pages.publish', W::capabilityFor('page'), 'Recht für Seiten');
});

T::run('Durchsetzung beim Speichern (resolveSaveStatus)', static function (): void {
    T::same(['status' => 'pending', 'downgraded' => true], W::resolveSaveStatus('published', 'draft', false), 'Entwurf → veröffentlichen ohne Recht = Einreichung');
    T::same(['status' => 'pending', 'downgraded' => true], W::resolveSaveStatus('private', '', false), 'neu → privat ohne Recht = Einreichung');
    T::same(['status' => 'pending', 'downgraded' => true], W::resolveSaveStatus('published', 'pending', false), 'eingereicht → veröffentlichen ohne Recht bleibt eingereicht');
    T::same(['status' => 'published', 'downgraded' => false], W::resolveSaveStatus('published', 'draft', true), 'mit Recht: veröffentlichen');
    T::same(['status' => 'published', 'downgraded' => false], W::resolveSaveStatus('published', 'published', false), 'bereits veröffentlicht: Bearbeiten bleibt veröffentlicht');
    T::same(['status' => 'private', 'downgraded' => false], W::resolveSaveStatus('private', 'published', false), 'veröffentlicht → privat: keine neue Freigabe');
    T::same(['status' => 'draft', 'downgraded' => false], W::resolveSaveStatus('draft', 'published', false), 'zurück auf Entwurf ist immer erlaubt');
    T::same(['status' => 'pending', 'downgraded' => false], W::resolveSaveStatus('pending', 'draft', false), 'Einreichen ist immer erlaubt');
});

T::run('Statusübergänge (transitionAction)', static function (): void {
    T::same(W::ACTION_SUBMITTED, W::transitionAction('draft', 'pending', true), 'draft → pending = eingereicht');
    T::same(W::ACTION_SUBMITTED, W::transitionAction('', 'pending', true), 'neu → pending = eingereicht');
    T::same(W::ACTION_APPROVED, W::transitionAction('pending', 'published', false), 'pending → published = freigegeben');
    T::same(W::ACTION_APPROVED, W::transitionAction('pending', 'private', false), 'pending → private = freigegeben');
    T::same(W::ACTION_RETURNED, W::transitionAction('pending', 'draft', false), 'pending → draft durch Prüfende = zurückgegeben');
    T::same(W::ACTION_WITHDRAWN, W::transitionAction('pending', 'draft', true), 'pending → draft durch Autor = zurückgezogen');
    T::same(null, W::transitionAction('pending', 'pending', true), 'kein Wechsel = kein Schritt');
    T::same(null, W::transitionAction('draft', 'published', true), 'direktes Veröffentlichen ohne Prüfung ist kein Schritt des Ablaufs');
});

T::run('Benachrichtigungsempfänger (recipientIds)', static function (): void {
    $users = [
        (object) ['id' => 1, 'role' => 'admin'], (object) ['id' => 2, 'role' => 'admin'],
        (object) ['id' => 3, 'role' => 'editor'], (object) ['id' => 4, 'role' => 'author'],
    ];
    $roleCan = static fn (string $role, string $cap): bool => $role === 'admin' || ($role === 'editor' && $cap === 'pages.publish');
    T::same([2], W::recipientIds(W::ACTION_SUBMITTED, 'post', $users, $roleCan, 1, 1, 1), 'Einreichung eines Beitrags: andere Konten mit posts.publish, ohne Autor/Handelnde');
    T::same([2, 3], W::recipientIds(W::ACTION_SUBMITTED, 'page', $users, $roleCan, 1, 1, 1), 'Einreichung einer Seite: auch Redaktion mit pages.publish');
    T::same([1, 2], W::recipientIds(W::ACTION_SUBMITTED, 'post', $users, $roleCan, 4, 4, 4), 'Einreichung durch Autor ohne Recht: alle Admins');
    T::same([3], W::recipientIds(W::ACTION_SUBMITTED, 'page', $users, $roleCan, 2, 1, 2), 'Einreichung fremder Inhalte: Autor (darf eigene nicht freigeben) und Handelnde ausgenommen');
    T::same([4, 1], W::recipientIds(W::ACTION_RETURNED, 'post', $users, $roleCan, 2, 4, 1), 'Rückgabe: Autor und einreichende Person');
    T::same([1], W::recipientIds(W::ACTION_APPROVED, 'post', $users, $roleCan, 2, 1, 2), 'Freigabe: Autor, ohne die freigebende Person selbst');
    T::same([], W::recipientIds(W::ACTION_COMMENT, 'post', $users, $roleCan, 2, 1, 1), 'Kommentar: keine Benachrichtigung');
});

T::run('Einreichen und Freigeben (Datenbank-Attrappe)', static function (): void {
    $f = wfFixture(true);
    $w = $f['workflow'];

    $result = $w->submit('post', 10, 1, 'Bitte prüfen');
    T::check(!empty($result['success']), 'Anna reicht ihren Entwurf ein');
    T::same('pending', (string) $f['db']->get_var('SELECT status FROM cms_posts WHERE id = 10'), 'Status ist pending');
    T::same(1, count($f['sent']), 'eine Benachrichtigung');
    T::same([2, 3], $f['sent'][0]['ids'], 'an Ben (Admin) und Carla (Redaktion mit posts.publish), nicht an Anna, Dirk oder die inaktive Eva');
    T::check(str_contains($f['sent'][0]['message'], 'Bitte prüfen'), 'Kommentar in der Benachrichtigung');
    T::same('/admin/posts?action=edit&id=10', $f['sent'][0]['url'], 'Link zum Editor');

    $own = $w->approve('post', 10, 1, '', wfCan(1));
    T::check(empty($own['success']) && str_contains((string) $own['error'], 'zweite Person'), 'Anna kann den eigenen Beitrag nicht freigeben');
    T::same('pending', (string) $f['db']->get_var('SELECT status FROM cms_posts WHERE id = 10'), 'Status bleibt pending');
    T::check(!$w->canReview('post', 10, 1, wfCan(1)) && $w->canReview('post', 10, 2, wfCan(2)), 'canReview: Anna nein, Ben ja');

    $approved = $w->approve('post', 10, 2, 'Passt', wfCan(2));
    T::check(!empty($approved['success']), 'Ben gibt frei');
    $row = $f['db']->get_row('SELECT status, published_at FROM cms_posts WHERE id = 10');
    T::same('published', (string) $row->status, 'Status published');
    T::same('2026-11-15 10:00:00', (string) $row->published_at, 'ohne Datum: jetzt veröffentlicht');
    T::same([1], $f['sent'][1]['ids'], 'Freigabe benachrichtigt die Autorin');

    $again = $w->approve('post', 10, 2, '', wfCan(2));
    T::check(empty($again['success']), 'zweite Freigabe desselben Beitrags wird abgelehnt (wartet nicht mehr)');

    $w->submit('post', 11, 1);
    $scheduled = $w->approve('post', 11, 2, '', wfCan(2));
    T::same('2026-12-24 09:00:00', (string) $f['db']->get_var('SELECT published_at FROM cms_posts WHERE id = 11'), 'künftiges Datum bleibt erhalten (geplant)');
    T::check(str_contains((string) ($scheduled['message'] ?? ''), 'geplanten Termin'), 'Meldung nennt den geplanten Termin');

    T::check(empty($w->submit('post', 13, 1)['success']), 'veröffentlichte Beiträge lassen sich nicht einreichen');
});

T::run('Zurückgeben und Verlauf', static function (): void {
    $f = wfFixture(true);
    $w = $f['workflow'];
    $w->submit('post', 12, 4);
    T::same([1, 2, 3], $f['sent'][0]['ids'], 'Einreichung von Dirk (ohne Recht) geht an alle aktiven Konten mit Freigaberecht');

    $noComment = $w->returnToAuthor('post', 12, 2, '   ', wfCan(2));
    T::check(empty($noComment['success']) && str_contains((string) $noComment['error'], 'Begründung'), 'Rückgabe ohne Begründung wird abgelehnt');
    $byAuthor = $w->returnToAuthor('post', 12, 4, 'selbst', wfCan(4));
    T::check(empty($byAuthor['success']), 'ohne Freigaberecht kein Zurückgeben');

    $f['clock']['now'] += 60;
    $returned = $w->returnToAuthor('post', 12, 2, "Quelle fehlt.\nBitte ergänzen.", wfCan(2));
    T::check(!empty($returned['success']), 'Ben gibt mit Begründung zurück');
    T::same('draft', (string) $f['db']->get_var('SELECT status FROM cms_posts WHERE id = 12'), 'Status wieder draft');
    T::same([4], $f['sent'][1]['ids'], 'Rückgabe benachrichtigt den Autor');

    $f['clock']['now'] += 60;
    $w->addComment('post', 12, 4, 'Erledigt');
    $history = $w->history('post', 12);
    T::same(['comment', 'returned', 'submitted'], array_column($history, 'action'), 'Verlauf neueste zuerst');
    T::same('Ben', $history[1]['user_name'], 'Verlauf nennt die handelnde Person');
    T::same("Quelle fehlt.\nBitte ergänzen.", $history[1]['comment'], 'Begründung im Verlauf (Zeilenumbruch bleibt)');
    T::same(['pending', 'draft'], [$history[1]['from_status'], $history[1]['to_status']], 'Verlauf hält Von/Nach fest');
    T::check(empty($w->addComment('post', 12, 4, '')['success']), 'leerer Kommentar wird abgelehnt');
    T::same(W::COMMENT_MAX, mb_strlen(W::cleanComment(str_repeat('ä', W::COMMENT_MAX + 50))), 'Kommentar wird auf die Höchstlänge gekürzt');
    T::same('ab', W::cleanComment("a\x00b"), 'Steuerzeichen werden entfernt');
});

T::run('Speichern-Pfad: recordTransition', static function (): void {
    $f = wfFixture(false);
    $w = $f['workflow'];
    T::same(W::ACTION_SUBMITTED, $w->recordTransition('post', 12, 'draft', 'pending', 4, ''), 'Speichern als pending = eingereicht');
    T::same(W::ACTION_APPROVED, $w->recordTransition('post', 12, 'pending', 'published', 3, ''), 'Speichern als published durch Redaktion = freigegeben');
    T::same([4], $f['sent'][1]['ids'], 'Freigabe beim Speichern benachrichtigt Autor');
    T::same(null, $w->recordTransition('post', 13, 'published', 'published', 1, ''), 'Bearbeiten eines veröffentlichten Beitrags ist kein Schritt');
    T::same(2, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_content_workflow_events'), 'nur die zwei Schritte im Verlauf');
    T::same(4, $w->lastSubmitterId('post', 12), 'letzte einreichende Person wird gemerkt');
});

T::run('Wartende Inhalte und Badge-Zahl', static function (): void {
    $f = wfFixture(true);
    $w = $f['workflow'];
    $w->submit('post', 10, 1);
    $f['clock']['now'] += 60;
    $w->submit('post', 12, 4);
    $f['clock']['now'] += 60;
    $w->submit('page', 20, 2);

    T::same(3, count($w->pendingItems(10)), 'ohne Prüfende-Filter: alle drei');
    $forAnna = $w->pendingItems(10, 1, wfCan(1));
    T::same(['post:12', 'page:20'], array_map(static fn (array $i): string => $i['type'] . ':' . $i['id'], $forAnna), 'Anna sieht nicht ihren eigenen Beitrag; älteste Einreichung zuerst');
    $forBen = $w->pendingItems(10, 2, wfCan(2));
    T::same(['post:10', 'post:12'], array_map(static fn (array $i): string => $i['type'] . ':' . $i['id'], $forBen), 'Ben sieht die von ihm eingereichte Seite nicht');
    T::same(0, $w->pendingCount(2, wfCan(2), 'page'), 'Badge Seiten für Ben: 0 (selbst eingereicht)');
    T::same(1, $w->pendingCount(1, wfCan(1), 'page'), 'Badge Seiten für Anna: 1');
    T::same(1, $w->pendingCount(1, wfCan(1), 'post'), 'Badge Beiträge für Anna: 1 (nur Dirks Beitrag)');
    T::same(0, $w->pendingCount(4, wfCan(4)), 'ohne Recht: Badge 0');
    T::same('Dirk', $forAnna[0]['author_name'], 'Autor im Widget');
    T::check($forAnna[0]['submitted_at'] !== '', 'Einreichungszeitpunkt im Widget');
});

T::run('Sammelaktion: Aufteilen nach Freigaberecht', static function (): void {
    $f = wfFixture(true);
    $w = $f['workflow'];
    $w->submit('post', 12, 4);
    [$allowed, $submit] = $w->partitionByPublishRight('post', [10, 12, 13], 1, wfCan(1));
    T::same([12, 13], $allowed, 'Anna darf Dirks eingereichten und den bereits veröffentlichten Beitrag veröffentlichen');
    T::same([10], $submit, 'ihren eigenen Entwurf kann sie nur einreichen');
    [$allowedDirk, $submitDirk] = $w->partitionByPublishRight('post', [12, 13], 4, wfCan(4));
    T::same([13], $allowedDirk, 'ohne Recht: nur bereits Veröffentlichtes zählt als erlaubt');
    T::same([], $submitDirk, 'bereits eingereichte Beiträge werden nicht erneut eingereicht');
    T::same(1, $w->submitMany('post', $submit, 1), 'submitMany reicht ein');
    T::same([10 => 'pending', 13 => 'published'], $w->statusMap('post', [10, 13]), 'statusMap');
});

T::run('Vorschau-Links', static function (): void {
    $f = wfFixture(true);
    $w = $f['workflow'];
    $link = $w->createPreviewLink('post', 10, 1);
    T::check(!empty($link['success']) && W::isWellFormedPreviewToken((string) $link['token']), 'Link mit 64-stelligem Hex-Token');
    T::same('https://cms.test/preview/' . $link['token'], $link['url'], 'URL /preview/<token>');
    T::same('2026-11-22 10:00:00', $link['expires_at'], 'Ablauf nach 7 Tagen');
    $stored = (string) $f['db']->get_var('SELECT token_hash FROM cms_content_preview_links');
    T::same(hash('sha256', (string) $link['token']), $stored, 'gespeichert wird nur der SHA-256-Hash');
    T::check(!str_contains((string) $f['db']->get_var("SELECT group_concat(token_hash || comment) FROM cms_content_preview_links, cms_content_workflow_events"), (string) $link['token']), 'der Token selbst steht nirgends in der Datenbank');
    T::same(['type' => 'post', 'id' => 10], $w->resolvePreviewToken((string) $link['token']), 'gültiger Token → Beitrag');
    T::same(null, $w->resolvePreviewToken(strtoupper((string) $link['token'])), 'abweichende Schreibweise ungültig');
    T::same(null, $w->resolvePreviewToken('../../etc/passwd'), 'fehlgeformter Token ungültig');
    T::same(null, $w->resolvePreviewToken(str_repeat('a', 64)), 'unbekannter Token ungültig');
    T::same(1, count($w->activePreviewLinks('post', 10)), 'ein aktiver Link');

    $f['clock']['now'] += W::PREVIEW_TTL_SECONDS + 1;
    T::same(null, $w->resolvePreviewToken((string) $link['token']), 'nach Ablauf ungültig');
    T::same([], $w->activePreviewLinks('post', 10), 'abgelaufene Links sind nicht mehr aktiv');
    T::same(1, $w->purgeExpiredPreviewLinks(), 'Aufräumen entfernt den abgelaufenen Link');

    $second = $w->createPreviewLink('post', 10, 1);
    T::same(1, $w->revokePreviewLinks('post', 10, 1), 'Widerruf entfernt den Link');
    T::same(null, $w->resolvePreviewToken((string) $second['token']), 'nach Widerruf ungültig');

    T::check(empty($w->createPreviewLink('post', 14, 1)['success']), 'kein Link für Inhalte im Papierkorb');
    $third = $w->createPreviewLink('post', 12, 1);
    $f['db']->execute("UPDATE cms_posts SET status = 'trash' WHERE id = 12");
    T::same(null, $w->resolvePreviewToken((string) $third['token']), 'Link wird ungültig, sobald der Inhalt im Papierkorb liegt');
    T::check(in_array(W::ACTION_PREVIEW_CREATED, array_column($w->history('post', 10), 'action'), true), 'Erstellen steht im Verlauf');
});

T::run('Rechte-Migration v26', static function (): void {
    $db = wfDatabase();
    $db->execute("INSERT INTO cms_role_permissions (role, capability, granted) VALUES ('author', 'posts.publish', 0)");
    $granted = W::migrateCapabilities($db);
    T::same([['editor', 'posts.publish'], ['editor', 'pages.publish']], $granted, 'Redaktion erhält beide Rechte; bewusst entzogenes Autorenrecht bleibt');
    T::same('0', (string) $db->get_var("SELECT granted FROM cms_role_permissions WHERE role = 'author' AND capability = 'posts.publish'"), 'entzogenes Recht unverändert');
    T::same([], W::migrateCapabilities($db), 'zweiter Lauf ändert nichts (idempotent)');
    $fresh = wfDatabase();
    T::same([['editor', 'posts.publish'], ['editor', 'pages.publish'], ['author', 'posts.publish']], W::migrateCapabilities($fresh), 'ohne Einträge: auch Autoren behalten das Veröffentlichen');
    T::same(null, $fresh->get_var("SELECT granted FROM cms_role_permissions WHERE role = 'author' AND capability = 'pages.publish'"), 'Autoren erhalten kein pages.publish');
});

T::run('Einbindung: Speichern, Sammelaktionen, Autosave', static function (): void {
    $posts = wfSource('admin/modules/posts/PostsModule.php');
    $pages = wfSource('admin/modules/pages/PagesModule.php');
    foreach (['PostsModule' => $posts, 'PagesModule' => $pages] as $name => $source) {
        T::check(substr_count($source, 'ContentWorkflow::resolveSaveStatus(') >= 2, $name . ': Status wird vor und nach dem Payload-Filter geprüft');
        T::check(str_contains($source, "\$savePayload['status'] = \$filteredDecision['status'];"), $name . ': ein Filter kann das Freigaberecht nicht umgehen');
        T::check(str_contains($source, 'partitionByPublishRight('), $name . ': Sammelaktion „Veröffentlichen“ prüft das Freigaberecht');
        T::check(substr_count($source, '->recordTransition(') >= 2, $name . ': Speichern schreibt den Verlauf (Anlegen und Aktualisieren)');
        T::check(str_contains($source, 'public function workflowAction('), $name . ': Freigabe-Aktionen');
        T::check(str_contains($source, "'publish_intent'") && str_contains($source, "'submit'"), $name . ': „Zur Prüfung einreichen“ (publish_intent=submit)');
    }
    $resolvePos = strpos($posts, '$statusDecision = ContentWorkflow::resolveSaveStatus(');
    $checklistPos = strpos($posts, '$checklistError = $this->checkPublishChecklist(');
    T::check($resolvePos !== false && $checklistPos !== false && $resolvePos < $checklistPos, 'PostsModule: Statusentscheidung vor der Veröffentlichungs-Checkliste');
    T::check(str_contains($posts, "if (\$status === 'draft' || \$status === ContentStatus::PENDING) {"), 'Autosave speichert eingereichte Beiträge direkt (nicht öffentlich)');
    $api = wfSource('admin/post-editor-api.php');
    T::check(str_contains($api, '$module->autosave($post, $userId)'), 'post-editor-api.php speichert nur über PostsModule::autosave() (→ save() mit Durchsetzung)');
    foreach (['admin/posts.php', 'admin/pages.php'] as $controller) {
        $source = wfSource($controller);
        T::check(str_contains($source, "'workflow'") && str_contains($source, "WORKFLOW_ACTIONS = ['approve', 'return', 'submit', 'comment', 'preview_link', 'preview_revoke']"), $controller . ': Aktion „workflow“ mit fester Liste');
        T::check(str_contains($source, "'pending'"), $controller . ': Listenfilter „Zur Prüfung“');
    }
    $calendar = wfSource('core/Services/EditorialCalendarService.php');
    T::check(str_contains($calendar, "\$status === 'draft' || \$status === 'pending'"), 'Redaktionskalender zeigt eingereichte Inhalte mit geplantem Datum');
    foreach (['core/Services/Trash/PostTrashProvider.php', 'core/Services/Trash/PageTrashProvider.php'] as $provider) {
        T::check(str_contains(wfSource($provider), "['draft', 'pending', 'published', 'private']"), basename($provider) . ': Wiederherstellen behält „Zur Prüfung“');
    }
});

T::run('Einbindung: Vorschau-Route, Schema, Menü, Einstellung', static function (): void {
    $router = wfSource('core/Routing/ThemeRouter.php');
    T::check(str_contains($router, "addRoute('GET', '/preview/:token', [\$this, 'renderPreview'])"), 'Route /preview/:token');
    $start = strpos($router, 'public function renderPreview(');
    $body = $start !== false ? substr($router, $start, 2600) : '';
    T::check(str_contains($body, 'resolvePreviewToken(') && str_contains($body, 'render404()'), 'ungültiger Token → 404');
    T::check(str_contains($body, '$this->sendDraftPreviewHeaders();') && str_contains($body, 'Referrer-Policy: no-referrer'), 'noindex, kein Cache, kein Referrer');
    T::check(str_contains($body, "p.status <> 'trash'"), 'kein Beitrag aus dem Papierkorb');

    $schema = wfSource('core/SchemaManager.php');
    $queries = \CMS\SchemaManager::getSchemaQueries('cms_', 'utf8mb4');
    T::check(in_array('content_workflow_events', \CMS\SchemaManager::FEATURE_TABLES_V26, true) && in_array('content_preview_links', \CMS\SchemaManager::FEATURE_TABLES_V26, true), 'beide Tabellen in FEATURE_TABLES_V26');
    T::check(str_contains((string) $queries['content_preview_links'], 'token_hash CHAR(64) NOT NULL') && str_contains((string) $queries['content_preview_links'], 'UNIQUE KEY uq_content_preview_token (token_hash)'), 'Vorschau-Links: nur Hash, eindeutig');
    T::check(str_contains((string) $queries['content_workflow_events'], 'KEY idx_content_workflow_content (content_type, content_id, created_at)'), 'Verlauf: Index je Inhalt');
    T::check(str_contains($schema, 'ContentWorkflow::migrateCapabilities($this->db)'), 'Laufzeitprüfung führt die Rechte-Migration aus');

    T::check(in_array('content_pending_posts', \CMS\Services\AdminMenu\BadgeProvider::KEYS, true), 'Badge-Schlüssel content_pending_posts');
    $catalog = wfSource('core/Services/AdminMenu/CoreCatalog.php');
    T::check(str_contains($catalog, "'page' => 'posts', 'badge' => 'content_pending_posts'") && str_contains($catalog, "'page' => 'pages', 'badge' => 'content_pending_pages'"), 'Menüeinträge Beiträge/Seiten tragen das Badge');
    T::check(str_contains(wfSource('core/Services/FeatureHooks/Registry.php'), 'ContentWorkflowHooks::class'), 'Hooks registriert');

    $settings = wfSource('admin/modules/settings/SettingsModule.php');
    T::check(str_contains($settings, "'setting_content_review_second_person' => \$isContentSettingsSave"), 'Einstellung nur aus dem Inhalts-Formular gespeichert');
    T::check(str_contains(wfSource('admin/views/settings/general.php'), 'Veröffentlichung braucht eine zweite Person'), 'Schalter in den Einstellungen');
    T::same(W::SETTING_SECOND_PERSON, 'setting_content_review_second_person', 'Schlüssel der Einstellung');

    $panel = wfSource('admin/views/partials/content-workflow-panel.php') . wfSource('admin/views/partials/content-workflow-form.php');
    T::check(!preg_match('/<script|\son[a-z]+=/i', $panel), 'Freigabe-Bereich ohne Inline-Skripte und Inline-Handler (CSP)');
    T::check(str_contains($panel, 'name="csrf_token"'), 'Freigabe-Formular mit CSRF-Token');
    foreach (['admin/views/posts/edit.php', 'admin/views/pages/edit.php'] as $editor) {
        $source = wfSource($editor);
        T::check(str_contains($source, "partials/content-workflow-panel.php") && str_contains($source, "partials/content-workflow-form.php"), $editor . ': Freigabe-Bereich eingebunden');
        T::check(str_contains($source, 'ContentStatus::badgeMap()'), $editor . ': Status-Badges aus ContentStatus');
    }
});

T::finish();
