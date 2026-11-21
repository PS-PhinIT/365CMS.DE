<?php
/**
 * Community-Zugriff (4.2.0, PR #2a und #2b): `CommunityAccess::can()` mit allen neun Prüfschritten, die Mengen-API
 * (`filterReadable()`, `readableForumIds()`), `limitsFor()`, der Zwischenspeicher (`forgetUser()`, `flush()`, `fresh`) und die
 * Vertrauensstufen (`BasicTrustPolicy`). PR #2b (Vertrag DESIGN-2b §3.5): Inhaltsgrenzen (`content`, `content_before`), Prüfpflicht
 * (`review`), neue Moderationsaktionen und Zustände, Ziel des Verschiebens (unsichtbar = `not_found`), `ban_temporary`, Melden eines
 * Mitglieds (`member_id`), `authority()`/`outranks()`, `moderationScope()`, Reagieren nur auf Veröffentlichtes, Mengen-API mit
 * höchstens zehn Abfragen, Kategorie-Regel.
 *
 * - Matrix: 18 Nutzerklassen (Gast, nicht aktives Konto, unbestätigtes Mitglied, Mitglied Stufe 0/1/2/3, Autor, Gruppenmitglied,
 *   Forum-Moderator, globaler Moderator, `admin`, Sperre `write`/`full`, abgelaufene und aufgehobene Sperre, Konto ohne
 *   Community-Recht, Konto nur mit Leserecht) × Aktionen × Zustände von Forum, Thema und Beitrag als Tabelle.
 * - Pflichtfälle aus der Karte (Anhang B): Leck im internen Forum, `trust:0`, Vererbung (Ersetzen je Subjekt,
 *   `inherit_permissions = 0`), Filter darf nur ablehnen, Modul aus auch für `admin`, inaktive Gruppen, `retry_after`,
 *   Fenster nach Stufe, Freigabepflicht, Deckel für wartende Beiträge, Tageslimit, unbekannte Aktion, gleiche Abfragefolge bei
 *   „gibt es nicht“ und „darfst du nicht sehen“.
 * - Je Einstellung mit Leser in `CommunityAccess`/`BasicTrustPolicy` ein Abschnitt „Wirkung: <schlüssel>“ (die Suite
 *   `community-settings` sucht danach).
 *
 * DB-frei: SQLite-Double (`TESTS/lib/CommunityTestDatabase.php`), läuft in der CI. Mit CMS_TEST_DB_HOST und CMS_TEST_DB_USER
 * laufen dieselben Abläufe gegen eine frische MariaDB (`pr2a_*`, Sitzungszone +01:00, `TESTS/lib/MariaDbTestDatabase.php`):
 * Typen, Sortierung und Platzhalter-ID der echten Datenbank. Die Suite läuft in der PHP-Zone Europe/Berlin, damit jede
 * Ortszeit statt UTC auffällt.
 *
 *   php TESTS/community-access/run.php [-v]
 *   CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/community-access/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-access-' . getmypid() . '/');

date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

// Gegenprobe-Haken (TESTS/lib/Mutant.php): mit CMS_TEST_MUTANT=<name> lädt der Autoloader eine Mutante statt des Originals.
require_once dirname(__DIR__) . '/lib/Mutant.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        Mutant::load($file);
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/lib/CommunityTestDatabase.php';
require_once dirname(__DIR__) . '/lib/MariaDbTestDatabase.php';

use CMS\Database;
use CMS\Hooks;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\BasicTrustPolicy;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\TrustLevelPolicy;
use CMS\Services\Community\Support\Codes;
use CMS\Services\Community\Support\CommunityCapabilities;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\Settings;

T::$verbose = in_array('-v', $argv ?? [], true);

register_shutdown_function(static function (): void {
    foreach (glob(LOG_PATH . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir(LOG_PATH);
});

const AC_ROOT = ABSPATH . 'core/Services/Community/';

/** Rollen und ihre Community-Rechte; `admin` fehlt absichtlich: die Klasse muss ihn selbst ausnehmen. */
const AC_ROLE_CAPS = [
    'member' => ['community.view', 'community.participate'],
    'editor' => ['community.view', 'community.participate'],
    'moderator' => ['community.view', 'community.participate', 'community.moderate'],
    'viewer' => ['community.view'],
    'nocap' => [],
    // PR #2b: Rangstufe 3 (community.ban bzw. nur community.manage)
    'manager' => ['community.view', 'community.participate', 'community.moderate', 'community.ban'],
    'manageronly' => ['community.view', 'community.participate', 'community.manage'],
];

/** Nutzerklassen der Matrix: Spaltenkürzel => Name in der Testwelt. */
const AC_COLS = [
    'G' => 'guest', 'IA' => 'inactive', 'UV' => 'unverified', 'M0' => 'm0', 'M1' => 'm1', 'M2' => 'm2', 'M3' => 'm3', 'OWN' => 'own',
    'GRP' => 'grp', 'FM' => 'fm', 'GM' => 'gm', 'AD' => 'admin', 'BW' => 'bw', 'BF' => 'bf', 'BX' => 'bold', 'BL' => 'blift', 'NC' => 'nc',
    'VW' => 'vw',
];

function acRead(string $path): string
{
    return (string) file_get_contents($path);
}

/** PHP-Code ohne Kommentare. */
function acCode(string $php): string
{
    $code = '';
    foreach (token_get_all($php) as $token) {
        if (is_array($token)) {
            if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                $code .= $token[1];
            }
        } else {
            $code .= $token;
        }
    }

    return $code;
}

/**
 * Warteliste (Vertrag DESIGN-2b §3.2): Ablehnungsgründe aus PR #2b, die W0 registriert, aber erst `CommunityAccess` in W1 liefert.
 * Die Prüfung „jeder Grund wird geliefert“ überspringt sie. J1 hat die fünf Gründe von W1 gestrichen
 * (`too_many_links`, `too_many_images`, `too_many_attachments`, `content_review`, `invalid_target`); J4 entfernt Konstante und Ausnahme.
 */
const AC_PENDING_REASONS_2B = [];

/** Alle bisher beobachteten Ablehnungsgründe (Gegenprobe: genau die Codes aus Codes::ACCESS_REASONS). */
final class AcSeen
{
    /** @var array<string, true> */
    public static array $reasons = [];
}

/** Entscheidung als Text: `ok`, `ok:published`, `ok:full`, `<reason>`, `<reason>@<retry>`. */
function acCell(AccessDecision $decision): string
{
    if (!$decision->allowed) {
        AcSeen::$reasons[$decision->reason] = true;

        return $decision->reason . ($decision->retryAfter !== null ? '@' . $decision->retryAfter : '');
    }

    return 'ok' . ($decision->initialStatus !== null ? ':' . $decision->initialStatus : '') . ($decision->view !== null ? ':' . $decision->view : '');
}

/**
 * Form einer Entscheidung: Ablehnungen haben einen Grund und weder Anfangsstatus noch Ansicht; Erlaubnisse haben keinen Grund und
 * keine Wartezeit; der Anfangsstatus (`published`/`pending`) gehört genau zu `create_topic` und `reply`, die Ansicht nur zum Lesen
 * eines Beitrags.
 *
 * @param array<string, mixed> $ctx
 */
function acShapeIsValid(AccessDecision $decision, string $action, array $ctx): bool
{
    if (!$decision->allowed) {
        return $decision->reason !== '' && $decision->initialStatus === null && $decision->view === null;
    }
    $writes = in_array($action, ['create_topic', 'reply'], true);
    $statusOk = $writes ? in_array($decision->initialStatus, ['published', 'pending'], true) : $decision->initialStatus === null;
    $viewOk = $action === 'read' && isset($ctx['post_id']) ? in_array($decision->view, ['full', 'placeholder'], true) : $decision->view === null;

    return $decision->reason === '' && $decision->retryAfter === null && $statusOk && $viewOk;
}

/**
 * Testwelt: SQLite-Double, Uhr, Rollenrechte und E-Mail-Bestätigung als veränderbarer Zustand; benannte Konten, Foren, Themen
 * und Beiträge.
 */
final class AcWorld
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase */
    public Database $db;
    public CommunityAccess $access;
    public Settings $settings;
    public int $now;
    public bool $moduleOn = true;
    public bool $verifyFails = false;
    public bool $rolesFail = false;
    /** @var array<int, bool> */
    public array $verified = [];
    /** @var array<string, int|null> */
    public array $u = ['guest' => null];
    /** @var array<string, int> */
    public array $f = [];
    /** @var array<string, int> */
    public array $t = [];
    /** @var array<string, int> */
    public array $p = [];
    /** @var array<string, int> */
    public array $g = [];

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings = [], ?TrustLevelPolicy $trust = null)
    {
        if (MariaDbTestDatabase::configured()) {
            // Jede Welt hält eine Verbindung und eine Datenbank; die Welten zeigen über ihre Closures auf sich selbst und werden erst
            // vom Zyklensammler freigegeben. Ohne diesen Aufruf erschöpft die Suite max_connections (151) der MariaDB.
            gc_collect_cycles();
        }
        $this->db = MariaDbTestDatabase::configured() ? MariaDbTestDatabase::create() : CommunityTestDatabase::create();
        $this->now = gmmktime(12, 0, 0, 11, 15, 2026);
        $this->settings = Settings::fromArray($settings);
        $this->access = new CommunityAccess(
            $this->db,
            $this->settings,
            function (string $role, string $capability): bool {
                if ($this->rolesFail) {
                    throw new \RuntimeException('Rollenabfrage nicht erreichbar');
                }

                return in_array($capability, AC_ROLE_CAPS[$role] ?? [], true);
            },
            fn (): bool => $this->moduleOn,
            fn (): int => $this->now,
            function (int $userId): bool {
                if ($this->verifyFails) {
                    throw new \RuntimeException('Bestätigungsdienst nicht erreichbar');
                }

                return $this->verified[$userId] ?? false;
            },
            $trust
        );
    }

    /** UTC-Text `Y-m-d H:i:s` zum Zeitpunkt „jetzt“ plus Sekunden. */
    public function utc(int $delta = 0): string
    {
        return gmdate('Y-m-d H:i:s', $this->now + $delta);
    }

    /** @param array<string, mixed> $o role, status, verified (Standard true), posts, level, locked, groups */
    public function member(string $name, array $o = []): int
    {
        $id = $this->db->user(['username' => $name, 'email' => $name . '@example.test', 'role' => $o['role'] ?? 'member', 'status' => $o['status'] ?? 'active']);
        $this->u[$name] = $id;
        $this->verified[$id] = $o['verified'] ?? true;
        if (isset($o['posts']) || isset($o['level'])) {
            $this->db->stats($id, [
                'post_count' => $o['posts'] ?? 0,
                'trust_level' => $o['level'] ?? 0,
                'trust_level_locked' => !empty($o['locked']),
            ]);
        }
        foreach ($o['groups'] ?? [] as $groupId) {
            $this->db->join($id, (int) $groupId);
        }

        return $id;
    }

    /** @param array<string, mixed> $o Optionen von `CommunityFixtures::forum()` */
    public function forum(string $key, array $o = []): int
    {
        if (isset($o['parent']) && is_string($o['parent'])) {
            $o['parent'] = $this->f[$o['parent']];
        }

        return $this->f[$key] = $this->db->forum($o + ['title' => $key, 'slug' => $key]);
    }

    /**
     * @param array<string, mixed> $o by (Konto, Standard own), age (Sekunden her, Standard 3 Stunden), activity (Sekunden her), sonst
     *                                Optionen von `CommunityFixtures::topic()`
     */
    public function topic(string $key, string $forum, array $o = []): int
    {
        $by = (string) ($o['by'] ?? 'own');
        $age = (int) ($o['age'] ?? 10800);
        $activity = (int) ($o['activity'] ?? 600);
        unset($o['by'], $o['age'], $o['activity']);

        return $this->t[$key] = $this->db->topic($o + [
            'forum' => $this->f[$forum], 'user' => $this->u[$by], 'created_at' => $this->utc(-$age), 'last_post_at' => $this->utc(-$activity),
        ]);
    }

    /** @param array<string, mixed> $o by (Konto, Standard own), age (Sekunden her, Standard 10 Minuten), sonst Optionen von `CommunityFixtures::post()` */
    public function post(string $key, string $topic, array $o = []): int
    {
        $by = (string) ($o['by'] ?? 'own');
        $age = (int) ($o['age'] ?? 600);
        unset($o['by'], $o['age']);

        return $this->p[$key] = $this->db->post($o + ['topic' => $this->t[$topic], 'user' => $this->u[$by], 'created_at' => $this->utc(-$age)]);
    }

    /** @param list<mixed> $params */
    public function sql(string $sql, array $params = []): void
    {
        $this->db->execute($sql, $params);
    }

    /** @param list<array<string, mixed>> $rows Zeilen für `ForumRepository::savePermissions()` */
    public function permissions(string $forum, array $rows): void
    {
        (new ForumRepository($this->db))->savePermissions($this->f[$forum], $rows, $this->utc());
        $this->access->flush();
    }

    /** @param list<array<string, mixed>> $rows Zeilen für `ForumRepository::saveModerators()` */
    public function moderators(string $forum, array $rows): void
    {
        (new ForumRepository($this->db))->saveModerators($this->f[$forum], $rows);
        $this->access->flush();
    }

    /** @param array<string, mixed> $context */
    public function can(string $user, string $action, array $context = []): AccessDecision
    {
        return $this->access->can($this->u[$user], $action, $context);
    }

    /** @param array<string, mixed> $context */
    public function cell(string $user, string $action, array $context = []): string
    {
        return acCell($this->can($user, $action, $context));
    }
}

/**
 * Standardwelt der Matrix: Konten aller Klassen, Foren in allen Zuständen, Themen und Beiträge in allen Zuständen.
 *
 * @param array<string, mixed> $settings überschreibt die Einstellungen (Standard der Welt: `guests_can_read` an)
 */
function acStandardWorld(array $settings = [], ?TrustLevelPolicy $trust = null): AcWorld
{
    $w = new AcWorld(array_merge(['guests_can_read' => true], $settings), $trust);
    $w->g['team'] = $w->db->group('Team');
    $w->g['mods'] = $w->db->group('Moderatoren');

    $w->member('inactive', ['status' => 'inactive', 'posts' => 5]);
    $w->member('unverified', ['verified' => false, 'posts' => 5]);
    $w->member('m0');
    $w->member('m1', ['posts' => 5]);
    $w->member('m2', ['posts' => 25]);
    $w->member('m3', ['posts' => 100, 'level' => 3, 'locked' => true]);
    $w->member('own', ['posts' => 5]);
    $w->member('grp', ['posts' => 5, 'groups' => [$w->g['team']]]);
    $w->member('fm', ['posts' => 5]);
    $w->member('fmg', ['posts' => 5, 'groups' => [$w->g['mods']]]);
    $w->member('gm', ['role' => 'moderator', 'posts' => 5]);
    $w->member('admin', ['role' => 'admin']);
    $w->member('bw', ['posts' => 5]);
    $w->member('bf', ['posts' => 5]);
    $w->member('nc', ['role' => 'nocap', 'posts' => 5]);
    $w->member('vw', ['role' => 'viewer', 'posts' => 5]);
    $w->member('editor', ['role' => 'editor', 'posts' => 5]);
    $w->member('bold', ['posts' => 5]);
    $w->member('blift', ['posts' => 5]);
    $w->db->ban(['user' => $w->u['bw'], 'scope' => 'write']);
    $w->db->ban(['user' => $w->u['bf'], 'scope' => 'full']);
    $w->db->ban(['user' => $w->u['bold'], 'scope' => 'full', 'starts_at' => $w->utc(-86400), 'ends_at' => $w->utc(-1)]);
    $w->db->ban(['user' => $w->u['blift'], 'scope' => 'full', 'starts_at' => $w->utc(-86400), 'lifted_at' => $w->utc(-60)]);

    $w->forum('fo');
    $w->forum('fr', ['status' => 'readonly']);
    $w->forum('fa', ['status' => 'archived']);
    $w->forum('fh', ['status' => 'hidden']);
    $w->forum('fl', ['type' => 'link']);
    $w->forum('fn', ['type' => 'announcements']);
    $w->forum('fq', ['type' => 'qa']);
    $w->forum('fi');
    $w->permissions('fi', [
        ['subject_type' => 'guest', 'permissions' => ['read' => 0]],
        ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 0]],
        ['subject_type' => 'group', 'subject_key' => (string) $w->g['team'], 'permissions' => ['read' => 1, 'reply' => 1, 'create_topic' => 1, 'edit_own' => 1]],
    ]);
    foreach (['fo', 'fr', 'fa', 'fh', 'fl', 'fn', 'fq'] as $forum) {
        $rows = [['subject_type' => 'user', 'subject_id' => $w->u['fm'], 'include_children' => 0]];
        if ($forum === 'fo') {
            $rows[] = ['subject_type' => 'group', 'subject_id' => $w->g['mods'], 'include_children' => 0];
        }
        $w->moderators($forum, $rows);
    }

    $w->topic('t', 'fo');
    $w->topic('tl', 'fo', ['locked' => true]);
    $w->topic('tle', 'fo', ['locked' => true, 'locked_until' => $w->utc(-3600)]);
    $w->topic('tlf', 'fo', ['locked' => true, 'locked_until' => $w->utc(3600)]);
    $w->topic('tp', 'fo', ['status' => 'pending']);
    $w->topic('th', 'fo', ['status' => 'hidden']);
    $w->topic('td', 'fo', ['status' => 'deleted']);
    $w->topic('tm', 'fo', ['status' => 'moved']);
    $w->topic('ttr', 'fo', ['status' => 'trash']);
    $w->topic('tnew', 'fo', ['age' => 300, 'activity' => 300]);
    $w->topic('tr', 'fr');
    $w->topic('ta', 'fa');
    $w->topic('thf', 'fh');
    $w->topic('tfi', 'fi');
    $w->topic('tq', 'fq');

    $w->post('p', 't', ['first' => false]);
    $w->post('pold', 't', ['age' => 10800]);
    $w->post('pp', 't', ['status' => 'pending']);
    $w->post('ph', 't', ['status' => 'hidden', 'status_reason' => 'moderator']);
    $w->post('pd', 't', ['status' => 'deleted', 'status_reason' => 'author']);
    $w->post('pr', 't', ['status' => 'rejected']);
    $w->post('pf', 't', ['first' => true]);
    $w->post('pl', 'tl');
    $w->post('ptp', 'tp');
    $w->post('ptd', 'td');
    $w->post('ptr', 'tr');
    $w->post('pta', 'ta');
    $w->post('pth', 'thf');
    $w->post('pfi', 'tfi');
    $w->post('pnew', 'tnew', ['age' => 300, 'first' => true]);

    $w->access->flush();

    return $w;
}

/**
 * Prüft eine Aktion für alle Nutzerklassen. `$expect`: Spaltenkürzel => erwartete Zelle; `'*'` gilt für alle nicht genannten.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, string> $expect
 */
function acMatrix(AcWorld $w, string $label, string $action, array $ctx, array $expect): void
{
    $w->access->flush();
    $violations = [];
    foreach (AC_COLS as $column => $name) {
        $want = $expect[$column] ?? ($expect['*'] ?? null);
        if ($want === null) {
            continue;
        }
        $decision = $w->can($name, $action, $ctx);
        T::same($want, acCell($decision), $label . ' · ' . $column . ' (' . $name . ')');
        if (!acShapeIsValid($decision, $action, $ctx)) {
            $violations[] = $column;
        }
    }
    T::same([], $violations, $label . ': Form der Entscheidungen (Grund nur bei Ablehnung, initialStatus nur bei create_topic/reply, Ansicht nur beim Lesen eines Beitrags)');
}

/** Zugangsschranken vor jeder Schreibaktion (Schritte 2 bis 4). */
function acW(array $cells = []): array
{
    return $cells + [
        'G' => 'login_required', 'IA' => 'login_required', 'UV' => 'email_unverified', 'BW' => 'banned_write', 'BF' => 'banned_full',
        'NC' => 'no_capability', 'VW' => 'no_capability',
    ];
}

/** Schranken vor Lesen: Sperre `full`, kein Community-Recht. */
function acR(array $cells = []): array
{
    return $cells + ['BF' => 'banned_full', 'NC' => 'not_found'];
}

/** Schranken vor Moderationsaktionen: wer nicht moderiert, bekommt `no_permission`. */
function acM(array $cells = []): array
{
    return $cells + [
        '*' => 'no_permission', 'G' => 'login_required', 'IA' => 'login_required', 'UV' => 'email_unverified', 'BW' => 'banned_write',
        'BF' => 'banned_full', 'NC' => 'no_capability',
    ];
}

/** Ergebnis für alle drei Moderationsklassen. */
const AC_MODS_OK = ['FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok'];

/** Kontext der Frage „darf dieses Konto die Community betreten/durchsuchen“ (ohne Ziel); ohne ihn ist read/search ohne Ziel `not_found`. */
const AC_GLOBAL = ['scope' => 'global'];

// ---------------------------------------------------------------------------------------------------------------------

T::run('Dateikonventionen, Aktionsvokabular und Quelltext-Wächter', static function (): void {
    T::info(MariaDbTestDatabase::configured() ? 'Datenbank: echte MariaDB (pr2a_*, Sitzungszone +01:00)' : 'Datenbank: SQLite-Double (MariaDB nicht konfiguriert)');
    $files = [
        'Service/CommunityAccess.php' => 'final class CommunityAccess',
        'Service/BasicTrustPolicy.php' => 'final class BasicTrustPolicy',
        'Service/TrustLevelPolicy.php' => 'interface TrustLevelPolicy',
    ];
    foreach ($files as $relative => $declaration) {
        $source = acRead(AC_ROOT . $relative);
        T::check(str_contains($source, 'declare(strict_types=1);'), $relative . ': strict_types');
        T::check(str_contains($source, 'namespace CMS\\Services\\Community\\Service;'), $relative . ': Namespace entspricht dem Ordner');
        T::check(preg_match('/^' . preg_quote($declaration, '/') . '\b/m', $source) === 1, $relative . ': ' . $declaration);
        T::check(str_contains($source, "if (!defined('ABSPATH')) {\n    exit;\n}"), $relative . ': ABSPATH-Schutz');
        T::check(str_contains($source, '@package CMSv2\\Core\\Services\\Community'), $relative . ': Paket im Kopfkommentar');
    }
    T::check(is_subclass_of(BasicTrustPolicy::class, TrustLevelPolicy::class), 'BasicTrustPolicy setzt TrustLevelPolicy um');

    // Aktionsvokabular laut Karte §1.2: eine Liste, ein Test
    $vocabulary = [
        'read', 'search', 'create_topic', 'reply', 'edit', 'delete', 'upload', 'poll', 'vote', 'react', 'mark_solution', 'report', 'subscribe',
        'topic.lock', 'topic.unlock', 'topic.pin', 'topic.unpin', 'topic.rename', 'topic.announce', 'topic.move', 'topic.merge', 'topic.split',
        'topic.approve', 'post.hide', 'post.restore', 'post.move', 'post.approve', 'post.reject', 'moderate', 'ban', 'manage', 'view_private_data',
        // PR #2b (Vertrag §3.5)
        'topic.unannounce', 'topic.hide', 'topic.restore', 'topic.reject', 'ban_temporary',
    ];
    $actions = CommunityAccess::actions();
    sort($actions);
    sort($vocabulary);
    T::same($vocabulary, $actions, 'Aktionsvokabular entspricht der Karte §1.2');
    T::same(count($actions), count(array_unique($actions)), 'jede Aktion kommt genau einmal vor');
    T::same(CommunityCapabilities::MODERATE, CommunityAccess::CAPABILITY_ACTIONS['ban_temporary'] ?? null, 'ban_temporary braucht community.moderate (befristete Sperren, Sperrlisten)');

    $code = acCode(acRead(AC_ROOT . 'Service/CommunityAccess.php'));
    T::same(1, substr_count($code, 'Database::instance()'), 'Database::instance() nur einmal (Konstruktor-Standardwert)');
    T::check(str_contains($code, '$db ?? Database::instance()'), 'Database::instance() steht im Konstruktor-Standardwert');
    T::same(1, substr_count($code, 'time()'), 'time() nur im Standardwert der Uhr');
    foreach (['strtotime(', 'mktime(', 'date_default_timezone', 'Hooks::applyFilters', 'Hooks::doAction', '$_SESSION', '$_GET', '$_POST', '$_REQUEST'] as $forbidden) {
        T::check(!str_contains($code, $forbidden), 'CommunityAccess enthält nicht: ' . $forbidden);
    }
    T::same(1, substr_count($code, 'Events::filters('), 'der Filter cms_community_can läuft an genau einer Stelle (Events::filters: jeder Filter einzeln, B1/B8)');
    T::check(!str_contains($code, 'Events::applyFilter('), 'cms_community_can läuft nicht über Events::applyFilter (dort hebt ein späterer Filter eine Ablehnung auf)');
    $policy = acCode(acRead(AC_ROOT . 'Service/BasicTrustPolicy.php'));
    T::check(!str_contains($policy, 'Database') && !str_contains($policy, 'execute('), 'BasicTrustPolicy liest keine Datenbank');
});

T::run('BasicTrustPolicy: Stufen aus E-Mail-Bestätigung, Beitragszahl und gespeicherter Stufe', static function (): void {
    $policy = new BasicTrustPolicy(Settings::fromArray([]));
    // [Beschreibung, bestätigt, Beiträge, gespeicherte Stufe, festgesetzt, erwartet]
    $cases = [
        ['neu: keine Beiträge', true, 0, 0, false, 0],
        ['erster Beitrag, bestätigt: Stufe 1', true, 1, 0, false, 1],
        ['19 Beiträge: noch Stufe 1', true, 19, 0, false, 1],
        ['20 Beiträge: Stufe 2', true, 20, 0, false, 2],
        ['viele Beiträge, unbestätigt: Stufe 0', false, 500, 0, false, 0],
        ['gespeicherte Stufe 1 wird nicht unterschritten (unbestätigt)', false, 0, 1, false, 1],
        ['gespeicherte Stufe 2 wird nicht unterschritten', true, 0, 2, false, 2],
        ['gespeicherte Stufe 3 (nicht festgesetzt) bleibt', true, 0, 3, false, 3],
        ['gespeicherte Stufe 3 vor der berechneten 2', true, 50, 3, false, 3],
        ['festgesetzt Stufe 3, keine Beiträge, unbestätigt', false, 0, 3, true, 3],
        ['festgesetzt Stufe 4', true, 0, 4, true, 4],
        ['festgesetzt Stufe 0 trotz 500 Beiträgen', true, 500, 0, true, 0],
        ['festgesetzt Stufe 1 trotz 500 Beiträgen', true, 500, 1, true, 1],
        ['gespeicherte Stufe 9 wird auf 4 geklemmt', true, 0, 9, true, 4],
        ['gespeicherte Stufe -2 wird auf 0 geklemmt', true, 3, -2, true, 0],
        ['negative Beitragszahl zählt wie 0', true, -5, 0, false, 0],
    ];
    foreach ($cases as [$label, $verified, $posts, $stored, $locked, $expected]) {
        T::same($expected, $policy->levelFor(7, $verified, $posts, $stored, $locked), $label);
    }

    // Stufe 2 setzt Stufe 1 voraus: liegt die Schwelle für Stufe 1 über der für Stufe 2, entscheidet die höhere.
    $inverted = new BasicTrustPolicy(Settings::fromArray(['trust_level1_posts' => 30, 'trust_level2_posts' => 20]));
    T::same(0, $inverted->levelFor(7, true, 25, 0, false), 'Stufe 2 braucht erst Stufe 1 (25 Beiträge, Schwelle 1 bei 30)');
    T::same(2, $inverted->levelFor(7, true, 30, 0, false), 'ab der Schwelle für Stufe 1 gilt auch die für Stufe 2');
    // Ohne übergebene Einstellungen gilt die gemeinsame Instanz von Settings
    Settings::setInstance(Settings::fromArray(['trust_level1_posts' => 3, 'trust_level2_posts' => 4]));
    try {
        $shared = new BasicTrustPolicy();
        T::same([0, 1, 2], [$shared->levelFor(7, true, 2, 0, false), $shared->levelFor(7, true, 3, 0, false), $shared->levelFor(7, true, 4, 0, false)], 'ohne übergebene Einstellungen gilt die gemeinsame Instanz');
    } finally {
        Settings::setInstance(null);
    }
});

// ── Matrix ──────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Matrix Lesen · Foren in allen Zuständen', static function (): void {
    $w = acStandardWorld();
    $everyone = acR(['*' => 'ok']);
    acMatrix($w, 'Forum offen', 'read', ['forum_id' => $w->f['fo']], $everyone);
    acMatrix($w, 'Forum readonly', 'read', ['forum_id' => $w->f['fr']], $everyone);
    acMatrix($w, 'Forum archived', 'read', ['forum_id' => $w->f['fa']], $everyone);
    acMatrix($w, 'Forum Typ link', 'read', ['forum_id' => $w->f['fl']], $everyone);
    acMatrix($w, 'Forum Typ announcements', 'read', ['forum_id' => $w->f['fn']], $everyone);
    acMatrix($w, 'Forum hidden: nur Moderation', 'read', ['forum_id' => $w->f['fh']], acR(['*' => 'not_found', 'FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix(
        $w,
        'Forum mit Rechte-Zeilen (nur Gruppe Team liest)',
        'read',
        ['forum_id' => $w->f['fi']],
        acR(['*' => 'not_found', 'GRP' => 'ok', 'GM' => 'ok', 'AD' => 'ok'])
    );
    acMatrix($w, 'Forum als geladene Zeile', 'read', ['forum' => (new ForumRepository($w->db))->find($w->f['fh'])], acR(['*' => 'not_found', 'FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'ohne Ziel, aber ausdrücklich scope = global: nur die globalen Schritte', 'read', AC_GLOBAL, acR(['*' => 'ok']));
    acMatrix($w, 'Suche (scope = global)', 'search', AC_GLOBAL, acR(['*' => 'ok', 'NC' => 'no_capability']));
    acMatrix($w, 'ohne Ziel und ohne scope: kein Freibrief (B2)', 'read', [], acR(['*' => 'not_found', 'BF' => 'banned_full']));
    acMatrix($w, 'Suche ohne Ziel und ohne scope (B2)', 'search', [], acR(['*' => 'not_found', 'BF' => 'banned_full', 'NC' => 'no_capability']));
});

T::run('Matrix Lesen · Themen in allen Zuständen', static function (): void {
    $w = acStandardWorld();
    $all = acR(['*' => 'ok']);
    $authorAndMods = acR(['*' => 'not_found', 'OWN' => 'ok', 'FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok']);
    acMatrix($w, 'Thema published', 'read', ['topic_id' => $w->t['t']], $all);
    acMatrix($w, 'Thema locked', 'read', ['topic_id' => $w->t['tl']], $all);
    acMatrix($w, 'Thema pending: Autor und Moderation', 'read', ['topic_id' => $w->t['tp']], $authorAndMods);
    acMatrix($w, 'Thema hidden: Autor und Moderation', 'read', ['topic_id' => $w->t['th']], $authorAndMods);
    acMatrix($w, 'Thema deleted: nur Moderation', 'read', ['topic_id' => $w->t['td']], acR(['*' => 'not_found', 'FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'Thema trash: nur admin', 'read', ['topic_id' => $w->t['ttr']], acR(['*' => 'not_found', 'AD' => 'ok']));
    acMatrix($w, 'Thema moved: lesbar als Verweis', 'read', ['topic_id' => $w->t['tm']], $all);
    acMatrix($w, 'Thema im Forum hidden', 'read', ['topic_id' => $w->t['thf']], acR(['*' => 'not_found', 'FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'Thema im Forum mit Rechte-Zeilen', 'read', ['topic_id' => $w->t['tfi']], acR(['*' => 'not_found', 'GRP' => 'ok', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'Thema in readonly/archived Foren bleibt lesbar', 'read', ['topic_id' => $w->t['ta']], $all);
});

T::run('Matrix Lesen · Beiträge in allen Zuständen (Ansicht full/placeholder)', static function (): void {
    $w = acStandardWorld();
    acMatrix($w, 'Beitrag published', 'read', ['post_id' => $w->p['p']], acR(['*' => 'ok:full']));
    acMatrix($w, 'Beitrag pending: Autor und Moderation', 'read', ['post_id' => $w->p['pp']], acR(['*' => 'not_found', 'OWN' => 'ok:full', 'FM' => 'ok:full', 'GM' => 'ok:full', 'AD' => 'ok:full']));
    acMatrix($w, 'Beitrag hidden: Platzhalter, Autor und Moderation sehen alles', 'read', ['post_id' => $w->p['ph']], acR(['*' => 'ok:placeholder', 'OWN' => 'ok:full', 'FM' => 'ok:full', 'GM' => 'ok:full', 'AD' => 'ok:full']));
    acMatrix(
        $w,
        'Beitrag deleted: Platzhalter, voll nur mit community.view_private_data (admin)',
        'read',
        ['post_id' => $w->p['pd']],
        acR(['*' => 'ok:placeholder', 'AD' => 'ok:full'])
    );
    acMatrix($w, 'Beitrag rejected: Autor und Moderation', 'read', ['post_id' => $w->p['pr']], acR(['*' => 'not_found', 'OWN' => 'ok:full', 'FM' => 'ok:full', 'GM' => 'ok:full', 'AD' => 'ok:full']));
    acMatrix($w, 'Beitrag in gelöschtem Thema: nur Moderation (Platzhalter/Ansicht folgt dem Beitrag)', 'read', ['post_id' => $w->p['ptd']], acR(['*' => 'not_found', 'FM' => 'ok:full', 'GM' => 'ok:full', 'AD' => 'ok:full']));
    acMatrix($w, 'Beitrag im Forum mit Rechte-Zeilen', 'read', ['post_id' => $w->p['pfi']], acR(['*' => 'not_found', 'GRP' => 'ok:full', 'GM' => 'ok:full', 'AD' => 'ok:full']));
    // Die Ansicht gibt es nur bei `read`; bei Schreibaktionen bleibt sie leer.
    T::same(null, $w->can('own', 'edit', ['post_id' => $w->p['p']])->view, 'edit liefert keine Ansicht');
    T::same(null, $w->can('m1', 'read', ['topic_id' => $w->t['t']])->view, 'read auf ein Thema liefert keine Ansicht');
});

T::run('Matrix Thema anlegen (create_topic) · Forenzustände und -typen', static function (): void {
    $w = acStandardWorld();
    acMatrix($w, 'Forum offen', 'create_topic', ['forum_id' => $w->f['fo']], acW(['*' => 'ok:published', 'M0' => 'ok:pending']));
    acMatrix($w, 'Forum readonly: nur Moderation', 'create_topic', ['forum_id' => $w->f['fr']], acW(['*' => 'forum_readonly', 'FM' => 'ok:published', 'GM' => 'ok:published', 'AD' => 'ok:published']));
    acMatrix($w, 'Forum archived: auch Moderation gesperrt', 'create_topic', ['forum_id' => $w->f['fa']], acW(['*' => 'forum_archived']));
    acMatrix($w, 'Forum hidden: unsichtbar, nur Moderation', 'create_topic', ['forum_id' => $w->f['fh']], acW(['*' => 'not_found', 'FM' => 'ok:published', 'GM' => 'ok:published', 'AD' => 'ok:published']));
    acMatrix($w, 'Forum Typ link: immer forum_link', 'create_topic', ['forum_id' => $w->f['fl']], acW(['*' => 'forum_link']));
    acMatrix($w, 'Forum Typ announcements: nur Moderation', 'create_topic', ['forum_id' => $w->f['fn']], acW(['*' => 'no_permission', 'FM' => 'ok:published', 'GM' => 'ok:published', 'AD' => 'ok:published']));
    acMatrix($w, 'Forum mit Rechte-Zeilen: nur Gruppe Team', 'create_topic', ['forum_id' => $w->f['fi']], acW(['*' => 'not_found', 'GRP' => 'ok:published', 'GM' => 'ok:published', 'AD' => 'ok:published']));
    acMatrix($w, 'unbekanntes Forum', 'create_topic', ['forum_id' => 99999], acW(['*' => 'not_found']));
    acMatrix($w, 'ohne Ziel', 'create_topic', [], acW(['*' => 'not_found']));
});

T::run('Matrix Antworten (reply) · Themenzustände', static function (): void {
    $w = acStandardWorld();
    $mods = ['FM' => 'ok:published', 'GM' => 'ok:published', 'AD' => 'ok:published'];
    acMatrix($w, 'Thema published', 'reply', ['topic_id' => $w->t['t']], acW(['*' => 'ok:published', 'M0' => 'ok:pending']));
    acMatrix($w, 'Thema locked: nur Moderation', 'reply', ['topic_id' => $w->t['tl']], acW(['*' => 'topic_locked'] + $mods));
    acMatrix($w, 'Thema locked, locked_until abgelaufen: gilt als offen', 'reply', ['topic_id' => $w->t['tle']], acW(['*' => 'ok:published', 'M0' => 'ok:pending']));
    acMatrix($w, 'Thema locked, locked_until in der Zukunft', 'reply', ['topic_id' => $w->t['tlf']], acW(['*' => 'topic_locked'] + $mods));
    acMatrix($w, 'Thema pending: Autor sieht es, darf nicht antworten', 'reply', ['topic_id' => $w->t['tp']], acW(['*' => 'not_found', 'OWN' => 'topic_pending'] + $mods));
    acMatrix($w, 'Thema hidden: Autor sieht es, darf nicht antworten', 'reply', ['topic_id' => $w->t['th']], acW(['*' => 'not_found', 'OWN' => 'no_permission'] + $mods));
    acMatrix($w, 'Thema deleted: Moderation sieht es, niemand antwortet', 'reply', ['topic_id' => $w->t['td']], acW(['*' => 'not_found', 'FM' => 'topic_deleted', 'GM' => 'topic_deleted', 'AD' => 'topic_deleted']));
    acMatrix($w, 'Thema trash: nur admin sieht es', 'reply', ['topic_id' => $w->t['ttr']], acW(['*' => 'not_found', 'AD' => 'topic_deleted']));
    acMatrix($w, 'Thema moved: Verweis, niemand antwortet', 'reply', ['topic_id' => $w->t['tm']], acW(['*' => 'topic_moved']));
    acMatrix($w, 'Thema im Forum readonly', 'reply', ['topic_id' => $w->t['tr']], acW(['*' => 'forum_readonly'] + $mods));
    acMatrix($w, 'Thema im Forum archived: auch Moderation gesperrt', 'reply', ['topic_id' => $w->t['ta']], acW(['*' => 'forum_archived']));
    acMatrix($w, 'Thema im Forum hidden', 'reply', ['topic_id' => $w->t['thf']], acW(['*' => 'not_found'] + $mods));
    acMatrix($w, 'Thema im Forum mit Rechte-Zeilen', 'reply', ['topic_id' => $w->t['tfi']], acW(['*' => 'not_found', 'GRP' => 'ok:published', 'GM' => 'ok:published', 'AD' => 'ok:published']));
    acMatrix($w, 'unbekanntes Thema', 'reply', ['topic_id' => 99999], acW(['*' => 'not_found']));
    acMatrix($w, 'nur ein Forum, kein Thema', 'reply', ['forum_id' => $w->f['fo']], acW(['*' => 'not_found']));
    // Beitrag statt Thema: das Thema kommt aus dem Beitrag
    acMatrix($w, 'Beitrag als Ziel (Thema locked)', 'reply', ['post_id' => $w->p['pl']], acW(['*' => 'topic_locked'] + $mods));
});

T::run('Matrix Bearbeiten (edit) · Beitrags-, Themen- und Forenzustände', static function (): void {
    $w = acStandardWorld();
    $mods = ['FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok'];
    acMatrix($w, 'eigener Beitrag im Fenster', 'edit', ['post_id' => $w->p['p']], acW(['*' => 'no_permission', 'OWN' => 'ok'] + $mods));
    acMatrix($w, 'eigener Beitrag, Fenster abgelaufen (3 Stunden)', 'edit', ['post_id' => $w->p['pold']], acW(['*' => 'no_permission', 'OWN' => 'edit_window'] + $mods));
    acMatrix($w, 'Beitrag pending: Autor darf (bleibt pending), Fremde sehen ihn nicht', 'edit', ['post_id' => $w->p['pp']], acW(['*' => 'not_found', 'OWN' => 'ok'] + $mods));
    acMatrix($w, 'Beitrag hidden: Autor nein, Moderation ja', 'edit', ['post_id' => $w->p['ph']], acW(['*' => 'no_permission', 'OWN' => 'post_not_editable'] + $mods));
    acMatrix($w, 'Beitrag deleted: niemand', 'edit', ['post_id' => $w->p['pd']], acW(['*' => 'no_permission', 'OWN' => 'post_not_editable', 'FM' => 'post_not_editable', 'GM' => 'post_not_editable', 'AD' => 'post_not_editable']));
    acMatrix($w, 'Beitrag rejected: niemand', 'edit', ['post_id' => $w->p['pr']], acW(['*' => 'not_found', 'OWN' => 'post_not_editable', 'FM' => 'post_not_editable', 'GM' => 'post_not_editable', 'AD' => 'post_not_editable']));
    acMatrix($w, 'erster Beitrag (Titel) im Fenster', 'edit', ['post_id' => $w->p['pf']], acW(['*' => 'no_permission', 'OWN' => 'ok'] + $mods));
    acMatrix($w, 'Thema locked: Autor nein, Moderation ja', 'edit', ['post_id' => $w->p['pl']], acW(['*' => 'no_permission', 'OWN' => 'topic_locked'] + $mods));
    acMatrix($w, 'Thema pending: der Autor darf seinen Beitrag bearbeiten, Fremde sehen das Thema nicht', 'edit', ['post_id' => $w->p['ptp']], acW(['*' => 'not_found', 'OWN' => 'ok'] + $mods));
    acMatrix($w, 'Thema deleted: nur Moderation sieht es, niemand bearbeitet', 'edit', ['post_id' => ($w->p['ptd'])], acW(['*' => 'not_found', 'FM' => 'topic_deleted', 'GM' => 'topic_deleted', 'AD' => 'topic_deleted']));
    acMatrix($w, 'Forum readonly: Autor nein, Moderation ja', 'edit', ['post_id' => $w->p['ptr']], acW(['*' => 'no_permission', 'OWN' => 'forum_readonly'] + $mods));
    acMatrix($w, 'Forum archived: auch Moderation gesperrt', 'edit', ['post_id' => $w->p['pta']], acW(['*' => 'no_permission', 'OWN' => 'forum_archived', 'FM' => 'forum_archived', 'GM' => 'forum_archived', 'AD' => 'forum_archived']));
    acMatrix($w, 'Forum hidden', 'edit', ['post_id' => $w->p['pth']], acW(['*' => 'not_found', 'FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'unbekannter Beitrag', 'edit', ['post_id' => 99999], acW(['*' => 'not_found']));
    acMatrix($w, 'nur ein Thema, kein Beitrag', 'edit', ['topic_id' => $w->t['t']], acW(['*' => 'not_found']));
});

T::run('Matrix Löschen (delete) · Standard: delete_own aus; mit delete_own an', static function (): void {
    $default = acStandardWorld();
    $mods = ['FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok'];
    acMatrix($default, 'Standard: Autor darf nicht löschen', 'delete', ['post_id' => $default->p['p']], acW(['*' => 'no_permission'] + $mods));

    $w = acStandardWorld(['default_permissions' => ['member' => ['delete_own' => 1]]]);
    acMatrix($w, 'eigener Beitrag im Fenster', 'delete', ['post_id' => $w->p['p']], acW(['*' => 'no_permission', 'OWN' => 'ok'] + $mods));
    acMatrix($w, 'Fenster abgelaufen', 'delete', ['post_id' => $w->p['pold']], acW(['*' => 'no_permission', 'OWN' => 'edit_window'] + $mods));
    acMatrix($w, 'Beitrag pending', 'delete', ['post_id' => $w->p['pp']], acW(['*' => 'not_found', 'OWN' => 'ok'] + $mods));
    acMatrix($w, 'Beitrag hidden: Autor nein', 'delete', ['post_id' => $w->p['ph']], acW(['*' => 'no_permission', 'OWN' => 'post_not_editable'] + $mods));
    acMatrix($w, 'Beitrag deleted: wiederholtes Löschen ist idempotent erlaubt', 'delete', ['post_id' => $w->p['pd']], acW(['*' => 'no_permission', 'OWN' => 'ok'] + $mods));
    acMatrix($w, 'Beitrag rejected: niemand', 'delete', ['post_id' => $w->p['pr']], acW(['*' => 'not_found', 'OWN' => 'post_not_editable', 'FM' => 'post_not_editable', 'GM' => 'post_not_editable', 'AD' => 'post_not_editable']));
    acMatrix($w, 'Thema locked', 'delete', ['post_id' => $w->p['pl']], acW(['*' => 'no_permission', 'OWN' => 'topic_locked'] + $mods));
    acMatrix($w, 'Thema pending: der Autor darf seinen Beitrag löschen', 'delete', ['post_id' => $w->p['ptp']], acW(['*' => 'not_found', 'OWN' => 'ok'] + $mods));
    $w->topic('tpn', 'fo', ['status' => 'pending', 'age' => 300, 'activity' => 300]);
    acMatrix($w, 'Thema pending (fünf Minuten alt): der Autor darf es löschen', 'delete', ['topic_id' => $w->t['tpn']], acW(['*' => 'not_found', 'OWN' => 'ok'] + $mods));
    acMatrix($w, 'Forum archived: auch das wiederholte Löschen ist gesperrt', 'delete', ['post_id' => $w->p['pta']], acW(['*' => 'no_permission', 'OWN' => 'forum_archived', 'FM' => 'forum_archived', 'GM' => 'forum_archived', 'AD' => 'forum_archived']));
    // Thema löschen (ohne Beitrag): Autor im Fenster, Moderation immer
    acMatrix($w, 'Thema (fünf Minuten alt)', 'delete', ['topic_id' => $w->t['tnew']], acW(['*' => 'no_permission', 'OWN' => 'ok'] + $mods));
    acMatrix($w, 'Thema (drei Stunden alt)', 'delete', ['topic_id' => $w->t['t']], acW(['*' => 'no_permission', 'OWN' => 'edit_window'] + $mods));
    acMatrix($w, 'Thema deleted', 'delete', ['topic_id' => $w->t['td']], acW(['*' => 'not_found', 'FM' => 'topic_deleted', 'GM' => 'topic_deleted', 'AD' => 'topic_deleted']));
    acMatrix($w, 'ohne Ziel', 'delete', [], acW(['*' => 'not_found']));
});

T::run('Matrix Moderationsaktionen (Moderationsnamen, moderate, Capability-Aktionen)', static function (): void {
    $w = acStandardWorld();
    foreach (['topic.lock', 'topic.unlock', 'topic.pin', 'topic.unpin', 'topic.rename', 'topic.approve'] as $action) {
        acMatrix($w, $action, $action, ['topic_id' => $w->t['t']], acM(AC_MODS_OK));
    }
    foreach (['post.hide', 'post.restore', 'post.move', 'post.approve', 'post.reject'] as $action) {
        acMatrix($w, $action, $action, ['post_id' => $w->p['p']], acM(AC_MODS_OK));
    }
    acMatrix($w, 'topic.announce nur global', 'topic.announce', ['topic_id' => $w->t['t']], acM(['GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'Moderation im Forum readonly', 'topic.lock', ['topic_id' => $w->t['tr']], acM(AC_MODS_OK));
    acMatrix($w, 'Moderation im Forum archived: gesperrt', 'topic.lock', ['topic_id' => $w->t['ta']], acM(['FM' => 'forum_archived', 'GM' => 'forum_archived', 'AD' => 'forum_archived']));
    acMatrix($w, 'Moderation im Forum hidden', 'topic.lock', ['topic_id' => $w->t['thf']], acM(['*' => 'not_found', 'FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok'] + ['G' => 'login_required']));
    acMatrix($w, 'Moderation in einem Forum, das der Forum-Moderator nicht sieht und nicht moderiert', 'topic.lock', ['topic_id' => $w->t['tfi']], acM(['*' => 'not_found', 'GRP' => 'no_permission', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'Moderation im gelöschten Thema: sichtbar nur für die Moderation, Wiederherstellen darin topic_deleted (PR #2b)', 'post.restore', ['post_id' => $w->p['ptd']], acM(['*' => 'not_found', 'FM' => 'topic_deleted', 'GM' => 'topic_deleted', 'AD' => 'topic_deleted']));
    acMatrix($w, 'Ablehnen eines wartenden Beitrags im gelöschten Thema bleibt möglich (PR #2b)', 'post.reject', ['post_id' => $w->p['ptd']], acM(['*' => 'not_found'] + AC_MODS_OK));

    // Verschieben: Quelle und Ziel
    $move = static fn (string $target): array => ['topic_id' => $w->t['t'], 'target_forum_id' => $w->f[$target]];
    acMatrix($w, 'Verschieben in ein Forum, das FM auch moderiert', 'topic.move', $move('fr'), acM(AC_MODS_OK));
    acMatrix($w, 'Verschieben in ein Forum, das FM weder sieht noch moderiert: not_found wie ein fehlendes Ziel (PR #2b)', 'topic.move', $move('fi'), acM(['FM' => 'not_found', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'Zusammenführen in ein archiviertes Ziel', 'topic.merge', $move('fa'), acM(['FM' => 'forum_archived', 'GM' => 'forum_archived', 'AD' => 'forum_archived']));
    acMatrix($w, 'Teilen mit unbekanntem Ziel', 'topic.split', ['topic_id' => $w->t['t'], 'target_forum_id' => 99999], acM(['FM' => 'not_found', 'GM' => 'not_found', 'AD' => 'not_found']));
    acMatrix($w, 'Verschieben ohne Ziel prüft nur die Quelle (Menüabfrage)', 'topic.move', ['topic_id' => $w->t['t']], acM(AC_MODS_OK));

    // moderate: Sammelabfrage
    acMatrix($w, 'moderate im Forum', 'moderate', ['forum_id' => $w->f['fo']], acM(AC_MODS_OK));
    acMatrix($w, 'moderate im Forum archived', 'moderate', ['forum_id' => $w->f['fa']], acM(['FM' => 'forum_archived', 'GM' => 'forum_archived', 'AD' => 'forum_archived']));
    acMatrix($w, 'moderate im Forum, das nur Gruppe Team sieht', 'moderate', ['forum_id' => $w->f['fi']], acM(['*' => 'not_found', 'GRP' => 'no_permission', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'moderate ohne Ziel: nur globale Moderation', 'moderate', [], acM(['GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'Moderationsname ohne Ziel', 'topic.lock', [], acM(['*' => 'not_found', 'G' => 'login_required', 'IA' => 'login_required', 'UV' => 'email_unverified', 'BW' => 'banned_write', 'BF' => 'banned_full', 'NC' => 'no_capability']));

    // Capability-Aktionen: nur die globale Capability, kein Forum
    $cap = ['*' => 'no_capability', 'G' => 'login_required', 'IA' => 'login_required', 'UV' => 'email_unverified', 'BW' => 'banned_write', 'BF' => 'banned_full', 'AD' => 'ok'];
    foreach (['ban', 'manage', 'view_private_data'] as $action) {
        acMatrix($w, $action, $action, [], $cap);
    }
    // PR #2b: befristete Sperren, Sperrlisten und Verlauf für die globale Moderation (Sperren sind global: kein Forum-Moderator)
    acMatrix($w, 'ban_temporary', 'ban_temporary', [], ['GM' => 'ok'] + $cap);
    acMatrix($w, 'ban_temporary mit Forum im Kontext: das Ziel zählt nicht', 'ban_temporary', ['forum_id' => $w->f['fo']], ['GM' => 'ok'] + $cap);
});

T::run('Matrix übrige Aktionen (search, report, subscribe, upload, poll, vote, react, mark_solution, unbekannt)', static function (): void {
    $w = acStandardWorld();
    acMatrix($w, 'unbekannte Aktion', 'gibt_es_nicht', [], array_fill_keys(array_keys(AC_COLS), 'unknown_action'));
    acMatrix($w, 'leere Aktion', '', [], array_fill_keys(array_keys(AC_COLS), 'unknown_action'));
    acMatrix($w, 'Aktion mit anderer Schreibweise (Groß-/Kleinschreibung)', 'READ', [], array_fill_keys(array_keys(AC_COLS), 'unknown_action'));

    acMatrix($w, 'melden', 'report', ['post_id' => $w->p['p']], acW(['*' => 'ok', 'OWN' => 'no_permission']));
    acMatrix($w, 'melden im Forum archived', 'report', ['post_id' => $w->p['pta']], acW(['*' => 'forum_archived']));
    acMatrix($w, 'melden im Forum readonly bleibt möglich', 'report', ['post_id' => $w->p['ptr']], acW(['*' => 'ok', 'OWN' => 'no_permission']));
    acMatrix($w, 'melden eines Beitrags im Forum mit Rechte-Zeilen', 'report', ['post_id' => $w->p['pfi']], acW(['*' => 'not_found', 'GRP' => 'ok', 'OWN' => 'not_found', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'abonnieren', 'subscribe', ['topic_id' => $w->t['t']], acW(['*' => 'ok']));
    acMatrix($w, 'Anhang hochladen: Standard-Recht der Mitglieder', 'upload', ['forum_id' => $w->f['fo']], acW(['*' => 'ok']));
    acMatrix($w, 'Umfrage anlegen: Standard aus, nur globale Moderation', 'poll', ['forum_id' => $w->f['fo']], acW(['*' => 'no_permission', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'abstimmen', 'vote', ['topic_id' => $w->t['t']], acW(['*' => 'ok']));
    acMatrix($w, 'abstimmen in gesperrtem Thema', 'vote', ['topic_id' => $w->t['tl']], acW(['*' => 'topic_locked', 'FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'reagieren: nicht auf eigene Beiträge', 'react', ['post_id' => $w->p['p']], acW(['*' => 'ok', 'OWN' => 'no_permission']));
    acMatrix($w, 'reagieren ohne Beitrag', 'react', ['topic_id' => $w->t['t']], acW(['*' => 'not_found']));
    acMatrix($w, 'Lösung markieren im Forum Typ qa: Themenautor und Moderation', 'mark_solution', ['topic_id' => $w->t['tq']], acW(['*' => 'no_permission', 'OWN' => 'ok', 'FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'Lösung markieren im Forum Typ discussion', 'mark_solution', ['topic_id' => $w->t['t']], acW(['*' => 'no_permission', 'FM' => 'ok', 'GM' => 'ok', 'AD' => 'ok']));
});

// ── Konten, Sperren, Modul ──────────────────────────────────────────────────────────────────────────────────────

T::run('Sperren: write, full, abgelaufen, aufgehoben, noch nicht begonnen, unbekannter Umfang, admin ausgenommen', static function (): void {
    $w = acStandardWorld();
    $w->member('bsoon', ['posts' => 5]);
    $w->db->ban(['user' => $w->u['bsoon'], 'scope' => 'full', 'starts_at' => $w->utc(60)]);
    $w->member('bwlim', ['posts' => 5]);
    $w->db->ban(['user' => $w->u['bwlim'], 'scope' => 'write', 'starts_at' => $w->utc(-86400), 'ends_at' => $w->utc(3600), 'internal_note' => 'geheim']);
    $w->member('bweird', ['posts' => 5]);
    $w->db->ban(['user' => $w->u['bweird'], 'scope' => 'unbekannt']);
    $w->member('bmix', ['posts' => 5]);
    $w->db->ban(['user' => $w->u['bmix'], 'scope' => 'write']);
    $w->db->ban(['user' => $w->u['bmix'], 'scope' => 'full']);
    $w->db->ban(['user' => $w->u['admin'], 'scope' => 'full']);
    $w->access->flush();
    $reply = ['topic_id' => $w->t['t']];

    T::same('ok:published', $w->cell('bold', 'reply', $reply), 'abgelaufene Sperre full: wieder erlaubt');
    T::same('ok:published', $w->cell('blift', 'reply', $reply), 'aufgehobene Sperre: erlaubt');
    T::same('ok:published', $w->cell('bsoon', 'reply', $reply), 'noch nicht begonnene Sperre: erlaubt');
    T::same('banned_write', $w->cell('bwlim', 'reply', $reply), 'befristete Schreibsperre wirkt bis ends_at');
    T::same('banned_write', $w->cell('bwlim', 'create_topic', ['forum_id' => $w->f['fo']]), 'Schreibsperre: Thema anlegen verboten');
    T::same('banned_write', $w->cell('bwlim', 'report', ['post_id' => $w->p['p']]), 'Schreibsperre: melden verboten');
    T::same('banned_write', $w->cell('bwlim', 'react', ['post_id' => $w->p['p']]), 'Schreibsperre: reagieren verboten');
    T::same('banned_write', $w->cell('bwlim', 'subscribe', ['topic_id' => $w->t['t']]), 'Schreibsperre: abonnieren verboten');
    T::same('ok', $w->cell('bwlim', 'read', ['topic_id' => $w->t['t']]), 'Schreibsperre: Lesen bleibt erlaubt');
    T::same('ok', $w->cell('bwlim', 'search', AC_GLOBAL), 'Schreibsperre: Suche bleibt erlaubt');
    T::same('banned_full', $w->cell('bweird', 'read', ['topic_id' => $w->t['t']]), 'unbekannter Umfang gilt als full (auch Lesen)');
    T::same('banned_full', $w->cell('bmix', 'read', ['topic_id' => $w->t['t']]), 'bei mehreren Sperren gilt die strengste');
    T::same('banned_full', $w->cell('bf', 'search', AC_GLOBAL), 'full: auch die Suche ist gesperrt');
    T::same('ok:published', $w->cell('admin', 'reply', $reply), 'admin ist von Sperren ausgenommen');
    T::same('ok', $w->cell('admin', 'read', ['topic_id' => $w->t['t']]), 'admin liest trotz Sperre full');
    T::same(403, $w->can('bf', 'read', $reply)->status(), 'Sperre meldet 403 (nicht 404)');
    // Die interne Notiz verlässt die Repositories nie
    $decision = $w->can('bwlim', 'reply', $reply);
    T::check(!str_contains(json_encode($decision) ?: '', 'geheim'), 'die interne Notiz der Sperre steht nicht in der Entscheidung');
    // Eine Sperre läuft zur Abfragezeit ab, ohne Cron
    $w->now += 3601;
    $w->access->flush();
    T::same('ok:published', $w->cell('bwlim', 'reply', $reply), 'ends_at wird zur Abfragezeit ausgewertet (eine Stunde später)');
});

T::run('Konten: nicht aktiv = Gast, unbekannte Konto-ID, Rolle ohne Community-Recht, Rolle nur mit Leserecht', static function (): void {
    $w = acStandardWorld();
    foreach (['inactive', 'banned', 'unverified'] as $status) {
        $name = 'status_' . $status;
        $w->member($name, ['status' => $status, 'posts' => 5]);
        T::same('login_required', $w->cell($name, 'reply', ['topic_id' => $w->t['t']]), 'Konto mit Status ' . $status . ': Schreibaktion wie ein Gast');
        T::same('ok', $w->cell($name, 'read', ['topic_id' => $w->t['t']]), 'Konto mit Status ' . $status . ': Lesen wie ein Gast (Tor offen)');
        T::same('not_found', $w->cell($name, 'read', ['topic_id' => $w->t['tp']]), 'Konto mit Status ' . $status . ': wartendes Thema bleibt unsichtbar');
    }
    $w->member('inactive_admin', ['role' => 'admin', 'status' => 'inactive']);
    T::same('login_required', $w->cell('inactive_admin', 'topic.lock', ['topic_id' => $w->t['t']]), 'ein nicht aktives admin-Konto hat keine Rechte');
    T::same('not_found', $w->cell('inactive_admin', 'read', ['topic_id' => $w->t['th']]), 'ein nicht aktives admin-Konto ist ein Gast');
    T::same('login_required', $w->access->can(987654, 'reply', ['topic_id' => $w->t['t']])->reason, 'unbekannte Konto-ID: Gast');
    T::same('login_required', $w->access->can(0, 'reply', ['topic_id' => $w->t['t']])->reason, 'Konto-ID 0: Gast');
    T::same('login_required', $w->access->can(-4, 'reply', ['topic_id' => $w->t['t']])->reason, 'negative Konto-ID: Gast');
    T::same(401, $w->can('guest', 'reply', ['topic_id' => $w->t['t']])->status(), 'login_required meldet 401');

    $w2 = acStandardWorld(['guests_can_read' => false]);
    T::same('not_found', $w2->cell('inactive', 'read', ['topic_id' => $w2->t['t']]), 'nicht aktives Konto bei geschlossenem Gast-Tor: nichts lesbar');
    T::same('ok', $w2->cell('vw', 'read', ['topic_id' => $w2->t['t']]), 'Mitglied mit Leserecht liest auch bei geschlossenem Gast-Tor');
    T::same('not_found', $w2->cell('nc', 'read', ['topic_id' => $w2->t['t']]), 'Mitglied ohne community.view: read = not_found');
    T::same('no_capability', $w2->cell('nc', 'search', AC_GLOBAL), 'Mitglied ohne community.view: search = no_capability');
    T::same('no_capability', $w2->cell('vw', 'reply', ['topic_id' => $w2->t['t']]), 'Mitglied ohne community.participate: reply = no_capability');
    T::same('no_capability', $w2->cell('vw', 'create_topic', ['forum_id' => $w2->f['fo']]), 'Mitglied ohne community.participate: create_topic = no_capability');
    T::same('no_permission', $w2->cell('vw', 'topic.lock', ['topic_id' => $w2->t['t']]), 'Moderationsname braucht kein participate, nur Moderation');
    T::same('no_capability', $w2->cell('nc', 'moderate', ['forum_id' => $w2->f['fo']]), 'ohne community.view auch keine Moderationsabfrage');
});

T::run('Rolle moderator (community.moderate) ohne Forum-Zeile und Rolle admin ohne eingetragene Rechte', static function (): void {
    $w = acStandardWorld(['guests_can_read' => false]);
    T::same('ok', $w->cell('gm', 'read', ['forum_id' => $w->f['fi']]), 'globale Moderation liest das interne Forum ohne Zeile');
    T::same('ok', $w->cell('gm', 'read', ['topic_id' => $w->t['thf']]), 'globale Moderation liest Themen im verborgenen Forum');
    T::same('ok', $w->cell('admin', 'read', ['forum_id' => $w->f['fi']]), 'admin liest das interne Forum (die Rollenabfrage kennt admin nicht)');
    T::same('ok:published', $w->cell('gm', 'reply', ['topic_id' => $w->t['tfi']]), 'globale Moderation antwortet ohne Matrix-Recht');
    T::same('no_permission', $w->cell('fm', 'poll', ['forum_id' => $w->f['fo']]), 'Forum-Moderator hat die Matrix für gewöhnliche Schreibaktionen weiter');
    T::same('ok', $w->cell('gm', 'poll', ['forum_id' => $w->f['fo']]), 'globale Moderation umgeht die Matrix');

    // Entzieht die Rolle moderator ihr Recht, ist sie keine Moderation mehr.
    $w->member('gm_light', ['role' => 'viewer', 'posts' => 5]);
    T::same('no_permission', $w->cell('gm_light', 'topic.lock', ['topic_id' => $w->t['t']]), 'ohne community.moderate keine Moderation');
});

T::run('Modul aus: alles abgelehnt, auch für admin; Mengen-API leer; limitsFor unberührt', static function (): void {
    $w = acStandardWorld();
    $w->moduleOn = false;
    $cases = [['read', ['forum_id' => $w->f['fo']]], ['reply', ['topic_id' => $w->t['t']]], ['search', AC_GLOBAL], ['moderate', []], ['ban', []], ['unbekannt', []]];
    foreach (AC_COLS as $column => $name) {
        foreach ($cases as [$action, $ctx]) {
            $expected = $action === 'unbekannt' ? 'unknown_action' : 'module_disabled';
            T::same($expected, $w->cell($name, $action, $ctx), 'Modul aus: ' . $column . ' · ' . $action);
        }
    }
    T::same(404, $w->can('admin', 'read', AC_GLOBAL)->status(), 'module_disabled meldet 404');
    T::same([], $w->access->filterReadable($w->u['admin'], [$w->f['fo']]), 'Modul aus: filterReadable leer');
    T::same([], $w->access->readableForumIds($w->u['admin']), 'Modul aus: readableForumIds leer');
    T::same(1, $w->access->limitsFor($w->u['m1'])['level'], 'limitsFor liest die Stufe auch bei ausgeschaltetem Modul');
    $w->moduleOn = true;
    T::same('ok', $w->cell('admin', 'read', AC_GLOBAL), 'Modul wieder an: die Entscheidung folgt sofort (kein Zwischenspeicher für den Modulzustand)');
});

// ── Rechtematrix: Standards, Zeilen, Vererbung ──────────────────────────────────────────────────────────────────

T::run('Pflichtfall: Leck im internen Forum (Rolle editor ohne Zeile), trust:0 = alle Angemeldeten, trust:N = ab Stufe N', static function (): void {
    $w = acStandardWorld();
    // Internes Forum: Gast und Rolle member ohne read, nur die Gruppe Team liest.
    $internal = ['forum_id' => $w->f['fi']];
    T::same('not_found', $w->cell('editor', 'read', $internal), 'Rolle editor ohne Zeile liest das interne Forum NICHT (kein Standard-Rückfall je Subjekt)');
    T::same('not_found', $w->cell('m1', 'read', $internal), 'Rolle member (Zeile ohne read) liest nicht');
    T::same('not_found', $w->cell('m0', 'read', $internal), 'Stufe 0 liest nicht');
    T::same('ok', $w->cell('grp', 'read', $internal), 'Gruppenmitglied liest');
    T::same('not_found', $w->cell('editor', 'reply', ['topic_id' => $w->t['tfi']]), 'editor antwortet nicht im internen Forum');
    T::same('not_found', $w->cell('editor', 'create_topic', $internal), 'editor legt dort kein Thema an');

    // Gegenprobe: ein Forum ganz ohne Zeilen fällt auf die Standards zurück (editor liest)
    T::same('ok', $w->cell('editor', 'read', ['forum_id' => $w->f['fo']]), 'Forum ohne Zeilen: Standards gelten');

    // trust:0 = alle Angemeldeten (jede Rolle), Gäste nicht
    $w->forum('f_trust0');
    $w->permissions('f_trust0', [['subject_type' => 'trust', 'subject_key' => '0', 'permissions' => ['read' => 1, 'reply' => 1]]]);
    foreach (['editor', 'm0', 'm1', 'm3', 'nc', 'gm'] as $name) {
        $expected = $name === 'nc' ? 'not_found' : 'ok';
        T::same($expected, $w->cell($name, 'read', ['forum_id' => $w->f['f_trust0']]), 'trust:0: ' . $name . ($name === 'nc' ? ' (ohne community.view)' : ' liest'));
    }
    T::same('not_found', $w->cell('guest', 'read', ['forum_id' => $w->f['f_trust0']]), 'trust:0 gilt nicht für Gäste');
    T::same('not_found', $w->cell('inactive', 'read', ['forum_id' => $w->f['f_trust0']]), 'trust:0 gilt nicht für nicht aktive Konten');

    // trust:2 = Mindeststufe 2
    $w->forum('f_trust2');
    $w->permissions('f_trust2', [['subject_type' => 'trust', 'subject_key' => '2', 'permissions' => ['read' => 1, 'reply' => 1]]]);
    foreach (['m0' => 'not_found', 'm1' => 'not_found', 'unverified' => 'not_found', 'm2' => 'ok', 'm3' => 'ok'] as $name => $expected) {
        T::same($expected, $w->cell($name, 'read', ['forum_id' => $w->f['f_trust2']]), 'trust:2 (Mindeststufe): ' . $name);
    }

    // Zeilen mehrerer Subjekte werden vereinigt (ODER, kein Deny)
    $w->forum('f_union');
    $w->permissions('f_union', [
        ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1, 'reply' => 0]],
        ['subject_type' => 'group', 'subject_key' => (string) $w->g['team'], 'permissions' => ['read' => 0, 'reply' => 1]],
        ['subject_type' => 'trust', 'subject_key' => '3', 'permissions' => ['create_topic' => 1]],
    ]);
    $union = ['topic_id' => $w->topic('t_union', 'f_union')];
    T::same('no_permission', $w->cell('m1', 'reply', $union), 'Rolle erlaubt read, nicht reply: reply abgelehnt');
    T::same('ok:published', $w->cell('grp', 'reply', $union), 'Gruppe erlaubt reply, Rolle nicht: Vereinigung erlaubt (kein Deny)');
    T::same('ok', $w->cell('grp', 'read', ['forum_id' => $w->f['f_union']]), 'read über die Rolle, obwohl die Gruppenzeile read 0 hat');
    T::same('ok:published', $w->cell('m3', 'create_topic', ['forum_id' => $w->f['f_union']]), 'trust:3 erlaubt create_topic ab Stufe 3');
    T::same('no_permission', $w->cell('m2', 'create_topic', ['forum_id' => $w->f['f_union']]), 'Stufe 2 liegt unter trust:3');

    // Zeile mit NULL/{}: keine Rechte; Zeile für fremde Rolle zählt nicht
    $w->forum('f_empty');
    $w->permissions('f_empty', [
        ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => null],
        ['subject_type' => 'role', 'subject_key' => 'editor', 'permissions' => ['read' => 1]],
    ]);
    T::same('not_found', $w->cell('m1', 'read', ['forum_id' => $w->f['f_empty']]), 'NULL-Rechte = keine Rechte (nicht der Standard)');
    T::same('ok', $w->cell('editor', 'read', ['forum_id' => $w->f['f_empty']]), 'die Zeile für editor gilt für editor');
});

T::run('Pflichtfall: Vererbung (Ersetzen je Subjekt, kein Schlüssel-Merge, inherit_permissions = 0)', static function (): void {
    $w = acStandardWorld();
    $w->forum('P');
    $w->permissions('P', [
        ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1, 'reply' => 1, 'create_topic' => 1]],
        ['subject_type' => 'group', 'subject_key' => (string) $w->g['team'], 'permissions' => ['read' => 1, 'reply' => 0]],
    ]);
    $w->forum('C', ['parent' => 'P']);
    $w->permissions('C', [['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1, 'reply' => 0]]]);
    $w->forum('D', ['parent' => 'P', 'inherit' => false]);
    $w->forum('E', ['parent' => 'P']);
    $w->forum('Gd', ['parent' => 'C']);
    $w->forum('F0', ['parent' => 'P', 'inherit' => false]);
    $w->permissions('F0', [['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1]]]);
    foreach (['P', 'C', 'D', 'E', 'Gd', 'F0'] as $key) {
        $w->topic('t' . $key, $key);
    }
    $reply = static fn (string $key): array => ['topic_id' => $w->t['t' . $key]];
    $create = static fn (string $key): array => ['forum_id' => $w->f[$key]];

    T::same('ok:published', $w->cell('m1', 'reply', $reply('P')), 'Elternforum: Rolle member darf antworten');
    T::same('ok:published', $w->cell('m1', 'create_topic', $create('P')), 'Elternforum: Rolle member darf Themen anlegen');
    T::same('ok', $w->cell('m1', 'read', $create('C')), 'Kindforum: eigene Zeile erlaubt read');
    T::same('no_permission', $w->cell('m1', 'reply', $reply('C')), 'Kindforum: die eigene Zeile ersetzt die geerbte (reply 0)');
    T::same('no_permission', $w->cell('m1', 'create_topic', $create('C')), 'Kindforum: create_topic wird NICHT aus dem Elternforum gemerged');
    T::same('ok', $w->cell('grp', 'read', $create('C')), 'Kindforum: die Gruppenzeile des Elternforums gilt weiter (kein eigenes Gruppen-Recht)');
    T::same('no_permission', $w->cell('grp', 'create_topic', $create('C')), 'Kindforum: Gruppe erbt nur ihre eigene Zeile (kein create_topic)');
    T::same('ok:published', $w->cell('m1', 'reply', $reply('E')), 'Kindforum ohne eigene Zeilen: erbt vom Elternforum');
    T::same('no_permission', $w->cell('m1', 'reply', $reply('Gd')), 'Enkelforum: erste Zeile je Subjekt in der Kette gilt (Kindforum vor Elternforum)');
    T::same('ok', $w->cell('grp', 'read', $create('Gd')), 'Enkelforum: Gruppenzeile kommt aus dem Elternforum der Kette');
    T::same('not_found', $w->cell('editor', 'read', $create('E')), 'Whitelist gilt auch geerbt: editor ohne Zeile liest nicht');

    // inherit_permissions = 0 ohne eigene Zeilen: die Kette endet hier, es gelten die Standards
    T::same('ok:published', $w->cell('editor', 'reply', $reply('D')), 'inherit_permissions = 0 ohne Zeilen: Standards (kein Erbe der Zeilen des Elternforums)');
    T::same('ok', $w->cell('editor', 'read', $create('D')), 'inherit_permissions = 0 ohne Zeilen: editor liest über die Standards');
    // inherit_permissions = 0 mit eigener Zeile: nur diese
    T::same('ok', $w->cell('m1', 'read', $create('F0')), 'inherit_permissions = 0 mit Zeile: die eigene Zeile gilt');
    T::same('no_permission', $w->cell('m1', 'reply', $reply('F0')), 'inherit_permissions = 0 mit Zeile: nichts vom Elternforum (reply fehlt)');
    $w->member('grp_editor', ['role' => 'editor', 'posts' => 5, 'groups' => [$w->g['team']]]);
    T::same('ok', $w->cell('grp_editor', 'read', $create('E')), 'Kindforum erbt die Gruppenzeile des Elternforums (Rolle editor ohne Zeile, Gruppe Team)');
    T::same('not_found', $w->cell('grp_editor', 'read', $create('F0')), 'inherit_permissions = 0 mit Zeile: die Gruppenzeile des Elternforums gilt nicht');
});

T::run('Gruppen: nur aktive Gruppen zählen (Rechte und Forum-Moderation)', static function (): void {
    $w = acStandardWorld();
    $internal = ['forum_id' => $w->f['fi']];
    T::same('ok', $w->cell('grp', 'read', $internal), 'aktive Gruppe: Recht gilt');
    $w->sql('UPDATE cms_user_groups SET is_active = 0 WHERE id = ?', [$w->g['team']]);
    $w->access->forgetUser($w->u['grp']);
    T::same('not_found', $w->cell('grp', 'read', $internal), 'Gruppe inaktiv: ihre Rechte-Zeile zählt nicht');
    $w->sql('UPDATE cms_user_groups SET is_active = 1 WHERE id = ?', [$w->g['team']]);
    $w->access->forgetUser($w->u['grp']);
    T::same('ok', $w->cell('grp', 'read', $internal), 'Gruppe wieder aktiv');

    // Forum-Moderator über eine Gruppe
    $lock = ['topic_id' => $w->t['t']];
    T::same('ok', $w->cell('fmg', 'topic.lock', $lock), 'Moderator über die aktive Gruppe');
    $w->sql('UPDATE cms_user_groups SET is_active = 0 WHERE id = ?', [$w->g['mods']]);
    $w->access->forgetUser($w->u['fmg']);
    T::same('no_permission', $w->cell('fmg', 'topic.lock', $lock), 'Gruppe inaktiv: keine Moderation über sie');
    // Mehrere Mitgliedschaften: nur die aktive zählt
    $w->sql('UPDATE cms_user_groups SET is_active = 1 WHERE id = ?', [$w->g['mods']]);
    $other = $w->db->group('Andere', false);
    $w->db->join($w->u['fmg'], $other);
    $w->access->forgetUser($w->u['fmg']);
    T::same('ok', $w->cell('fmg', 'topic.lock', $lock), 'neben einer inaktiven Gruppe zählt die aktive weiter');
});

T::run('Forum-Moderator: include_children, Gruppen, Vorfahren; Forum unter verborgenem Elternforum', static function (): void {
    $w = acStandardWorld();
    $w->member('fmi', ['posts' => 5]);
    $w->member('fme', ['posts' => 5]);
    $w->member('other', ['posts' => 5]);
    $w->forum('Pp');
    $w->forum('Cc', ['parent' => 'Pp']);
    $w->forum('Dd', ['parent' => 'Cc']);
    $w->moderators('Pp', [
        ['subject_type' => 'user', 'subject_id' => $w->u['fmi'], 'include_children' => 1],
        ['subject_type' => 'user', 'subject_id' => $w->u['fme'], 'include_children' => 0],
    ]);
    foreach (['Pp', 'Cc', 'Dd'] as $key) {
        $w->topic('t' . $key, $key);
    }
    $lock = static fn (string $key): array => ['topic_id' => $w->t['t' . $key]];
    T::same('ok', $w->cell('fmi', 'topic.lock', $lock('Pp')), 'include_children = 1: Moderator im Forum selbst');
    T::same('ok', $w->cell('fmi', 'topic.lock', $lock('Cc')), 'include_children = 1: im Unterforum');
    T::same('ok', $w->cell('fmi', 'topic.lock', $lock('Dd')), 'include_children = 1: im Unter-Unterforum');
    T::same('ok', $w->cell('fme', 'topic.lock', $lock('Pp')), 'include_children = 0: im Forum selbst');
    T::same('no_permission', $w->cell('fme', 'topic.lock', $lock('Cc')), 'include_children = 0: nicht im Unterforum');
    T::same('no_permission', $w->cell('other', 'topic.lock', $lock('Pp')), 'ohne Eintrag keine Moderation');
    T::same('no_permission', $w->cell('fme', 'topic.lock', $lock('Cc') + ['forum_id' => $w->f['Pp']]), 'das Forum kommt aus dem Thema, nicht aus der Eingabe (forum_id des moderierten Forums nützt nichts)');
    T::same('ok', $w->cell('fme', 'topic.lock', $lock('Pp') + ['forum_id' => $w->f['fi']]), 'ein fremdes forum_id verbietet nichts, was das Thema erlaubt');

    // Moderation in Unterforen mit Verschieben: Quelle und Ziel
    T::same('ok', $w->cell('fmi', 'topic.move', $lock('Cc') + ['target_forum_id' => $w->f['Dd']]), 'Verschieben zwischen zwei Foren des Moderators');
    T::same('no_permission', $w->cell('fme', 'topic.move', $lock('Pp') + ['target_forum_id' => $w->f['Cc']]), 'Ziel ohne Moderation (include_children = 0)');
    T::same('not_found', $w->cell('fmi', 'topic.move', $lock('Pp') + ['target_forum_id' => $w->f['fi']]), 'Ziel in einem fremden Forum, das der Moderator nicht sieht: not_found (PR #2b)');

    // Forum unter einem verborgenen Elternforum gilt als verborgen
    $w->forum('Hid', ['status' => 'hidden']);
    $w->forum('HidChild', ['parent' => 'Hid']);
    $w->topic('tHidChild', 'HidChild');
    $w->moderators('HidChild', [['subject_type' => 'user', 'subject_id' => $w->u['fme'], 'include_children' => 0]]);
    $w->moderators('Hid', [['subject_type' => 'user', 'subject_id' => $w->u['fmi'], 'include_children' => 1]]);
    $read = ['topic_id' => $w->t['tHidChild']];
    T::same('not_found', $w->cell('m1', 'read', $read), 'Unterforum eines verborgenen Forums: für Mitglieder unsichtbar');
    T::same('not_found', $w->cell('guest', 'read', $read), 'dto. für Gäste');
    T::same('ok', $w->cell('fmi', 'read', $read), 'Moderator des verborgenen Elternforums (include_children) sieht das Unterforum');
    T::same('ok', $w->cell('fme', 'read', $read), 'Moderator des Unterforums selbst sieht es');
    T::same('ok', $w->cell('gm', 'read', $read), 'globale Moderation sieht es');
    T::same([], $w->access->filterReadable($w->u['m1'], [$w->f['HidChild'], $w->f['Hid']]), 'filterReadable: das Unterforum fehlt');
    T::same([$w->f['HidChild']], $w->access->filterReadable($w->u['fme'], [$w->f['HidChild'], $w->f['Hid']]), 'filterReadable: Moderator des Unterforums sieht nur dieses');
});

T::run('Fail-closed bei beschädigten Forendaten: unbekannter Forumstatus, leerer oder beschädigter Pfad gelten wie hidden (B13)', static function (): void {
    $w = acStandardWorld(['guests_can_read' => true]);
    $viewers = ['guest', 'm1', 'own', 'grp', 'vw'];
    $moderators = ['gm', 'admin'];

    // Unbekannter Forumstatus (die Spalte ist ein VARCHAR(20): auch Importe und Eingriffe in die Datenbank können beliebige Werte hinterlassen)
    foreach (['Hidden', 'HIDDEN', 'private', 'disabled', 'closed', '', ' hidden', 'open '] as $i => $status) {
        $key = 'u' . $i;
        $w->forum($key);
        $w->topic('t' . $key, $key);
        $w->post('p' . $key, 't' . $key);
        try {
            $w->sql('UPDATE cms_community_forums SET status = ? WHERE id = ?', [$status, $w->f[$key]]);
        } catch (\Throwable $e) {
            T::info('Forumstatus ' . T::export($status) . ' nicht speicherbar: übersprungen');
            continue;
        }
        $w->moderators($key, [['subject_type' => 'user', 'subject_id' => $w->u['fm'], 'include_children' => 0]]);
        $label = 'Forumstatus ' . T::export($status);
        foreach ($viewers as $viewer) {
            T::same('not_found', $w->cell($viewer, 'read', ['forum_id' => $w->f[$key]]), $label . ': ' . $viewer . ' liest das Forum nicht');
            T::same('not_found', $w->cell($viewer, 'read', ['topic_id' => $w->t['t' . $key]]), $label . ': ' . $viewer . ' liest das Thema nicht');
            T::same('not_found', $w->cell($viewer, 'read', ['post_id' => $w->p['p' . $key]]), $label . ': ' . $viewer . ' liest den Beitrag nicht');
        }
        T::same('login_required', $w->cell('guest', 'reply', ['topic_id' => $w->t['t' . $key]]), $label . ': Gast schreibt nicht (login_required)');
        T::same('not_found', $w->cell('m1', 'reply', ['topic_id' => $w->t['t' . $key]]), $label . ': Mitglied antwortet nicht');
        T::same('not_found', $w->cell('m1', 'create_topic', ['forum_id' => $w->f[$key]]), $label . ': Mitglied legt kein Thema an');
        foreach (array_merge($moderators, ['fm']) as $moderator) {
            T::same('ok', $w->cell($moderator, 'read', ['forum_id' => $w->f[$key]]), $label . ': ' . $moderator . ' (Moderation) sieht das Forum wie ein verborgenes');
        }
        T::same(false, in_array($w->f[$key], $w->access->readableForumIds($w->u['m1']), true), $label . ': readableForumIds(Mitglied) enthält es nicht');
        T::same(false, in_array($w->f[$key], $w->access->readableForumIds(null), true), $label . ': readableForumIds(Gast) enthält es nicht');
        T::same([], $w->access->filterReadable($w->u['m1'], [$w->f[$key]]), $label . ': filterReadable liefert es nicht');
        T::same([$w->f[$key]], $w->access->filterReadable($w->u['gm'], [$w->f[$key]]), $label . ': globale Moderation liest es');
    }

    // Leerer oder beschädigter Pfad bei vorhandenem Elternforum
    $w->forum('PP');
    $w->forum('CC', ['parent' => 'PP']);
    $w->topic('tCC', 'CC');
    $w->moderators('CC', [['subject_type' => 'user', 'subject_id' => $w->u['fm'], 'include_children' => 0]]);
    $pp = $w->f['PP'];
    $cc = $w->f['CC'];
    T::same('ok', $w->cell('m1', 'read', ['forum_id' => $cc]), 'Ausgangslage: das Kindforum mit intaktem Pfad ist lesbar');
    $broken = [
        'leer' => '', 'nur Schrägstriche' => '/', 'nur die eigene ID (Elternforum fehlt im Pfad)' => '/' . $cc . '/',
        'endet nicht mit der eigenen ID' => '/' . $pp . '/', 'endet mit einer fremden ID (Elternforum davor stimmt)' => '/' . $pp . '/' . $w->f['fo'] . '/',
        'falsches Elternforum vor der eigenen ID' => '/' . $w->f['fo'] . '/' . $cc . '/', 'Segment 0 vor dem Elternforum' => '/0/' . $pp . '/' . $cc . '/',
        'Buchstaben im Pfad' => '/' . $pp . '/abc/' . $cc . '/', 'Segment 0' => '/0/' . $cc . '/', 'führende Null' => '/0' . $pp . '/' . $cc . '/',
        'negative ID' => '/-' . $pp . '/' . $cc . '/', 'Leerzeichen' => '/' . $pp . '/ ' . $cc . '/', 'doppelter Schrägstrich' => '/' . $pp . '//' . $cc . '/',
    ];
    foreach ($broken as $label => $path) {
        $w->sql('UPDATE cms_community_forums SET path = ? WHERE id = ?', [$path, $cc]);
        $w->access->flush();
        T::same('not_found', $w->cell('m1', 'read', ['forum_id' => $cc]), 'Pfad ' . $label . ': Mitglied liest das Kindforum nicht');
        T::same('not_found', $w->cell('guest', 'read', ['topic_id' => $w->t['tCC']]), 'Pfad ' . $label . ': Gast liest das Thema nicht');
        T::same('not_found', $w->cell('m1', 'reply', ['topic_id' => $w->t['tCC']]), 'Pfad ' . $label . ': Mitglied antwortet nicht');
        T::same('ok', $w->cell('fm', 'read', ['forum_id' => $cc]), 'Pfad ' . $label . ': der Forum-Moderator sieht es');
        T::same('ok', $w->cell('gm', 'read', ['forum_id' => $cc]), 'Pfad ' . $label . ': globale Moderation sieht es');
        T::same(false, in_array($cc, $w->access->readableForumIds($w->u['m1']), true), 'Pfad ' . $label . ': readableForumIds(Mitglied) enthält es nicht');
        T::same(false, in_array($cc, $w->access->readableForumIds(null), true), 'Pfad ' . $label . ': readableForumIds(Gast) enthält es nicht');
        T::same([$cc], $w->access->filterReadable($w->u['gm'], [$cc]), 'Pfad ' . $label . ': filterReadable(globale Moderation) liefert es');
    }
    // Pfad repariert: das Forum ist wieder lesbar
    $w->sql('UPDATE cms_community_forums SET path = ? WHERE id = ?', ['/' . $pp . '/' . $cc . '/', $cc]);
    $w->access->flush();
    T::same('ok', $w->cell('m1', 'read', ['forum_id' => $cc]), 'Pfad repariert: wieder lesbar');

    // Der Fall des Befunds: Kindforum unter einem verborgenen oder internen Elternforum mit leerem Pfad
    $w->forum('HP', ['status' => 'hidden']);
    $w->forum('HC', ['parent' => 'HP']);
    $w->topic('tHC', 'HC');
    $w->forum('IP');
    $w->permissions('IP', [['subject_type' => 'group', 'subject_key' => (string) $w->g['team'], 'permissions' => ['read' => 1, 'reply' => 1]]]);
    $w->forum('IC', ['parent' => 'IP']);
    $w->topic('tIC', 'IC');
    T::same(['not_found', 'ok', 'not_found'], [$w->cell('m1', 'read', ['forum_id' => $w->f['HC']]), $w->cell('grp', 'read', ['forum_id' => $w->f['IC']]), $w->cell('m1', 'read', ['forum_id' => $w->f['IC']])], 'Ausgangslage mit intaktem Pfad: verborgen / nur Gruppe Team / nicht für Mitglieder');
    $w->sql('UPDATE cms_community_forums SET path = ? WHERE id IN (?, ?)', ['', $w->f['HC'], $w->f['IC']]);
    $w->access->flush();
    foreach (['guest', 'm1', 'own', 'editor'] as $viewer) {
        T::same('not_found', $w->cell($viewer, 'read', ['forum_id' => $w->f['HC']]), $viewer . ': Kind unter verborgenem Elternforum mit leerem Pfad bleibt verborgen');
        T::same('not_found', $w->cell($viewer, 'read', ['topic_id' => $w->t['tIC']]), $viewer . ': Kind unter internem Elternforum mit leerem Pfad bleibt unlesbar (die Rechte der Vorfahren sind nicht mehr bestimmbar)');
    }
    T::same(false, in_array($w->f['HC'], $w->access->readableForumIds(null), true) || in_array($w->f['IC'], $w->access->readableForumIds(null), true), 'readableForumIds(Gast) enthält beide Kinder nicht');
    T::same('not_found', $w->cell('m1', 'reply', ['topic_id' => $w->t['tIC']]), 'reply im Kind mit leerem Pfad: not_found');
    T::same('not_found', $w->cell('m1', 'create_topic', ['forum_id' => $w->f['IC']]), 'create_topic dort: not_found');

    // Ein Vorfahre fehlt im Forenbaum (der Pfad nennt ein Forum, das es nicht gibt) oder hat einen unbekannten Status
    $w->forum('GA');
    $w->forum('GB', ['parent' => 'GA']);
    $w->forum('GC', ['parent' => 'GB']);
    $w->topic('tGC', 'GC');
    T::same('ok', $w->cell('m1', 'read', ['forum_id' => $w->f['GC']]), 'Ausgangslage: Enkelforum lesbar');
    $w->sql('UPDATE cms_community_forums SET parent_id = ?, path = ? WHERE id = ?', [777777, '/777777/' . $w->f['GA'] . '/', $w->f['GA']]);
    $w->sql('UPDATE cms_community_forums SET path = ? WHERE id IN (?, ?)', ['/777777/' . $w->f['GA'] . '/' . $w->f['GB'] . '/', $w->f['GB'], $w->f['GB']]);
    $w->sql('UPDATE cms_community_forums SET path = ? WHERE id = ?', ['/777777/' . $w->f['GA'] . '/' . $w->f['GB'] . '/' . $w->f['GC'] . '/', $w->f['GC']]);
    $w->access->flush();
    T::same('not_found', $w->cell('m1', 'read', ['forum_id' => $w->f['GC']]), 'ein Vorfahre im Pfad, den es nicht gibt: das Enkelforum gilt als verborgen');
    T::same('not_found', $w->cell('m1', 'read', ['forum_id' => $w->f['GA']]), 'dto. für das Forum mit dem fehlenden Elternforum selbst');
    T::same('ok', $w->cell('gm', 'read', ['forum_id' => $w->f['GC']]), 'globale Moderation sieht es');
    // Vorfahre mit Zeile im Baum, aber unbekanntem Status: nur die Statusschleife verbirgt das Unterforum (die Kategorie ist sichtbar)
    $w->forum('UA');
    $w->forum('UB', ['parent' => 'UA']);
    $w->topic('tUB', 'UB');
    $w->moderators('UB', [['subject_type' => 'user', 'subject_id' => $w->u['fm'], 'include_children' => 0]]);
    T::same('ok', $w->cell('m1', 'read', ['forum_id' => $w->f['UB']]), 'Ausgangslage: Unterforum lesbar');
    $w->sql('UPDATE cms_community_forums SET status = ? WHERE id = ?', ['private', $w->f['UA']]);
    $w->access->flush();
    T::same(['not_found', 'not_found', 'not_found'], [$w->cell('m1', 'read', ['forum_id' => $w->f['UB']]), $w->cell('guest', 'read', ['topic_id' => $w->t['tUB']]), $w->cell('m1', 'reply', ['topic_id' => $w->t['tUB']])], 'Vorfahre mit unbekanntem Status: das Unterforum gilt als verborgen (lesen, Gast, antworten)');
    T::same(false, in_array($w->f['UB'], $w->access->readableForumIds($w->u['m1']), true), 'Vorfahre mit unbekanntem Status: readableForumIds(Mitglied) enthält das Unterforum nicht');
    T::same(['ok', 'ok'], [$w->cell('fm', 'read', ['forum_id' => $w->f['UB']]), $w->cell('gm', 'read', ['forum_id' => $w->f['UB']])], 'Vorfahre mit unbekanntem Status: Forum-Moderator und globale Moderation sehen das Unterforum');

    // Oberste Foren ohne Elternforum sind vom Pfad unabhängig (ihre Kette ist das Forum selbst)
    $w->forum('RT');
    $w->sql('UPDATE cms_community_forums SET path = ? WHERE id = ?', ['', $w->f['RT']]);
    $w->access->flush();
    T::same('ok', $w->cell('m1', 'read', ['forum_id' => $w->f['RT']]), 'oberstes Forum mit leerem Pfad bleibt lesbar (kein Elternforum, das fehlen könnte)');
});

T::run('Entscheidung B15: Unterforen unter einem readonly- oder archived-Elternforum bleiben beschreibbar (nur hidden wirkt auf die Unterforen)', static function (): void {
    $w = acStandardWorld(['rate_post_seconds' => 0, 'rate_topic_seconds' => 0, 'new_member_approval_posts' => 0]);
    foreach (['readonly' => 'forum_readonly', 'archived' => 'forum_archived'] as $status => $reason) {
        $p = 'P' . $status;
        $c = 'C' . $status;
        $w->forum($p, ['status' => $status]);
        $w->forum($c, ['parent' => $p]);
        $w->topic('t' . $p, $p);
        $w->topic('t' . $c, $c, ['by' => 'own']);
        $w->post('p' . $c, 't' . $c, ['by' => 'own']);
        T::same($reason, $w->cell('m1', 'reply', ['topic_id' => $w->t['t' . $p]]), 'Elternforum ' . $status . ': antworten nicht möglich (' . $reason . ')');
        T::same('ok:published', $w->cell('m1', 'reply', ['topic_id' => $w->t['t' . $c]]), 'Unterforum unter ' . $status . ': antworten möglich (bewusste Entscheidung)');
        T::same('ok:published', $w->cell('m1', 'create_topic', ['forum_id' => $w->f[$c]]), 'Unterforum unter ' . $status . ': Thema anlegen möglich');
        T::same('ok', $w->cell('own', 'edit', ['post_id' => $w->p['p' . $c]]), 'Unterforum unter ' . $status . ': eigenen Beitrag bearbeiten möglich');
        T::same('ok', $w->cell('gm', 'topic.lock', ['topic_id' => $w->t['t' . $c]]), 'Unterforum unter ' . $status . ': Moderation möglich');
        T::same('ok', $w->cell('guest', 'read', ['topic_id' => $w->t['t' . $c]]), 'Unterforum unter ' . $status . ': lesbar');
    }
    $w->forum('Phid', ['status' => 'hidden']);
    $w->forum('Chid', ['parent' => 'Phid']);
    T::same('not_found', $w->cell('m1', 'create_topic', ['forum_id' => $w->f['Chid']]), 'Gegenprobe: unter einem verborgenen Elternforum gilt der Zustand für die Unterforen');
    T::check(str_contains(acRead(AC_ROOT . 'Service/CommunityAccess.php'), 'bleibt beschreibbar (bewusste Entscheidung'), 'die Entscheidung steht im Docblock von CommunityAccess');
});

// ── Filter cms_community_can ────────────────────────────────────────────────────────────────────────────────────

T::run('Pflichtfall: Filter cms_community_can darf nur ablehnen oder verschärfen, nie erweitern', static function (): void {
    $w = acStandardWorld();
    $reply = ['topic_id' => $w->t['t']];
    $log = static function (): string {
        return implode("\n", array_map(static fn (string $f): string => acRead($f), glob(LOG_PATH . 'community-*.log') ?: []));
    };
    $calls = [];
    $with = static function (callable $filter, callable $test) use (&$calls): void {
        Hooks::addFilter(Events::CAN, $filter);
        try {
            $test();
        } finally {
            Hooks::removeFilter(Events::CAN, $filter);
        }
    };

    // Ablehnung: Grund wird vereinheitlicht, die Wartezeit des Filters bleibt
    $deny = static function (AccessDecision $d, int $uid, string $action, array $ctx) use (&$calls): AccessDecision {
        $calls[] = [$uid, $action];

        return $action === 'reply' ? AccessDecision::deny('geheimer_grund', 10) : $d;
    };
    $with($deny, function () use ($w, $reply): void {
        T::same('filter_denied@10', $w->cell('m1', 'reply', $reply), 'Filter lehnt ab: Grund wird filter_denied, Wartezeit bleibt');
        T::same(403, $w->can('m1', 'reply', $reply)->status(), 'filter_denied meldet 403');
        T::same('ok', $w->cell('m1', 'read', ['topic_id' => $w->t['t']]), 'andere Aktionen bleiben unberührt');
    });
    T::same('ok:published', $w->cell('m1', 'reply', $reply), 'nach dem Entfernen des Filters gilt wieder die Ausgangsentscheidung');

    // Der Filter läuft nicht, wenn schon abgelehnt wurde; er kann eine Ablehnung also nicht umkehren.
    $calls = [];
    $allowAll = static function (AccessDecision $d, int $uid, string $action) use (&$calls): AccessDecision {
        $calls[] = $action;

        return AccessDecision::allow('published');
    };
    $with($allowAll, function () use ($w, &$calls): void {
        T::same('login_required', $w->cell('guest', 'reply', ['topic_id' => $w->t['t']]), 'Ablehnung (login_required) bleibt');
        T::same('banned_write', $w->cell('bw', 'reply', ['topic_id' => $w->t['t']]), 'Ablehnung (banned_write) bleibt');
        T::same('unknown_action', $w->cell('admin', 'gibt_es_nicht'), 'Ablehnung (unknown_action) bleibt');
        T::same('topic_locked', $w->cell('m1', 'reply', ['topic_id' => $w->t['tl']]), 'Ablehnung (topic_locked) bleibt');
        T::same('not_found', $w->cell('m1', 'read', ['forum_id' => $w->f['fi']]), 'Ablehnung (not_found) bleibt');
        T::same([], $calls, 'bei Ablehnung wird der Filter gar nicht aufgerufen');
        // Erweiterung einer Freigabepflicht: wartend → veröffentlicht wird verworfen
        T::same('ok:pending', $w->cell('m0', 'reply', ['topic_id' => $w->t['t']]), 'Filter will pending → published: verworfen');
        T::same('ok:placeholder', $w->cell('m1', 'read', ['post_id' => $w->p['pd']]), 'Filter will placeholder → full (hier: Ansicht ohne Wert): verworfen');
        T::same('ok', $w->cell('m1', 'search', AC_GLOBAL), 'Filter will einen Anfangsstatus setzen, wo keiner vorgesehen ist: verworfen');
    });

    // Verschärfen ist erlaubt: veröffentlicht → wartend, voll → Platzhalter
    $tighten = static fn (AccessDecision $d): AccessDecision => $d->withInitialStatus($d->initialStatus === null ? null : 'pending')->withView($d->view === null ? null : 'placeholder');
    $with($tighten, function () use ($w): void {
        T::same('ok:pending', $w->cell('m1', 'reply', ['topic_id' => $w->t['t']]), 'Filter darf veröffentlicht → wartend verschärfen');
        T::same('ok:placeholder', $w->cell('m1', 'read', ['post_id' => $w->p['p']]), 'Filter darf voll → Platzhalter verschärfen');
        T::same('ok', $w->cell('m1', 'read', ['topic_id' => $w->t['t']]), 'ohne Anfangsstatus/Ansicht bleibt alles beim Alten');
    });

    // Ergebnisse, die keine Entscheidung sind, werden ignoriert
    foreach ([null, true, 'allow', 1, [], new stdClass()] as $junk) {
        $filter = static fn () => $junk;
        $with($filter, function () use ($w, $junk): void {
            T::same('ok:published', $w->cell('m1', 'reply', ['topic_id' => $w->t['t']]), 'Filterergebnis ' . get_debug_type($junk) . ' wird ignoriert');
        });
    }
    // Eine Ausnahme im Filter wirft nicht durch und gilt als Ablehnung (fail-closed, B8); bei read/search heißt sie not_found (B11)
    $throwing = static function (): never {
        throw new \RuntimeException('Plugin kaputt');
    };
    $with($throwing, function () use ($w): void {
        T::same('filter_denied', $w->cell('m1', 'reply', ['topic_id' => $w->t['t']]), 'werfender Filter: Ablehnung filter_denied, nicht die Eingangsentscheidung');
        T::same('not_found', $w->cell('m1', 'read', ['topic_id' => $w->t['t']]), 'werfender Filter bei read: not_found');
    });

    // Argumente: Entscheidung, Konto-ID als int (Gast 0), Aktion, Kontext
    $seen = [];
    $spy = static function (AccessDecision $d, int $uid, string $action, array $ctx) use (&$seen): AccessDecision {
        $seen[] = [$d->allowed, $uid, $action, $ctx];

        return $d;
    };
    $with($spy, function () use ($w, &$seen): void {
        $w->can('m1', 'reply', ['topic_id' => $w->t['t']]);
        $w->can('guest', 'read', ['forum_id' => $w->f['fo']]);
    });
    T::same([[true, $w->u['m1'], 'reply', ['topic_id' => $w->t['t']]], [true, 0, 'read', ['forum_id' => $w->f['fo']]]], $seen, 'Filterargumente: Entscheidung, Konto-ID (Gast 0), Aktion, Kontext');

    // Filter und Mengen-API: ein abgelehntes Forum fällt heraus
    $denyForum = static function (AccessDecision $d, int $uid, string $action, array $ctx) use ($w): AccessDecision {
        return ($ctx['forum_id'] ?? 0) === $w->f['fr'] ? AccessDecision::deny('x') : $d;
    };
    $with($denyForum, function () use ($w): void {
        T::same([$w->f['fo'], $w->f['fa']], $w->access->filterReadable($w->u['m1'], [$w->f['fo'], $w->f['fr'], $w->f['fa']]), 'filterReadable beachtet den Filter');
        T::same('not_found', $w->can('m1', 'read', ['forum_id' => $w->f['fr']])->reason, 'dto. über can(): bei read heißt die Ablehnung not_found (B11)');
    });

    // Verstöße werden protokolliert (Kanal community)
    $before = substr_count($log(), 'wollte eine Entscheidung erweitern');
    $with($allowAll, function () use ($w): void {
        $w->can('m0', 'reply', ['topic_id' => $w->t['t']]);
    });
    T::check(substr_count($log(), 'wollte eine Entscheidung erweitern') === $before + 1, 'eine Erweiterung durch den Filter wird protokolliert');
    $before = substr_count($log(), 'lieferte keine Entscheidung');
    $with(static fn () => 'allow', function () use ($w): void {
        $w->can('m1', 'reply', ['topic_id' => $w->t['t']]);
    });
    T::check(substr_count($log(), 'lieferte keine Entscheidung') === $before + 1, 'ein Filterergebnis ohne Entscheidung wird protokolliert');
});

/** Text aller Protokolldateien des Kanals community. */
function acLogText(): string
{
    return implode("\n", array_map(static fn (string $f): string => acRead($f), glob(LOG_PATH . 'community-*.log') ?: []));
}

T::run('Filter cms_community_can: Ablehnung gilt für die ganze Kette, Ausnahme = Ablehnung, Verschärfung bleibt, read/search → not_found (B1, B8, B11)', static function (): void {
    $w = acStandardWorld();
    $reply = ['topic_id' => $w->t['t']];
    /** @param list<array{0: callable, 1: int}> $filters */
    $with = static function (array $filters, callable $test): void {
        foreach ($filters as [$filter, $priority]) {
            Hooks::addFilter(Events::CAN, $filter, $priority);
        }
        try {
            $test();
        } finally {
            foreach ($filters as [$filter, $priority]) {
                Hooks::removeFilter(Events::CAN, $filter, $priority);
            }
        }
    };
    $calls = [];
    $deny = static function (string $name, ?int $retry = null) use (&$calls): \Closure {
        return static function (AccessDecision $d) use (&$calls, $name, $retry): AccessDecision {
            $calls[] = $name;

            return AccessDecision::deny('geheim', $retry);
        };
    };
    // Ein „frischer“ Freibrief: gibt immer die weiteste Entscheidung zurück (veröffentlicht, voll)
    $widest = static function (string $name) use (&$calls): \Closure {
        return static function (AccessDecision $d) use (&$calls, $name): AccessDecision {
            $calls[] = $name;

            return AccessDecision::allow($d->initialStatus === null ? null : 'published', $d->view === null ? null : 'full');
        };
    };
    $throws = static function (string $name) use (&$calls): \Closure {
        return static function () use (&$calls, $name): never {
            $calls[] = $name;
            throw new \RuntimeException('Plugin ' . $name . ' kaputt');
        };
    };

    // B1: Filter A lehnt ab, ein späterer Filter B gibt wieder frei: die Ablehnung bleibt
    $calls = [];
    $with([[$deny('A', 10), 5], [$widest('B'), 20]], function () use ($w, $reply, &$calls): void {
        T::same('filter_denied@10', $w->cell('m1', 'reply', $reply), 'A lehnt ab (Priorität 5), B erlaubt wieder (Priorität 20): die Ablehnung bleibt, mit der Wartezeit von A');
        T::same(['A'], $calls, 'die Kette endet bei der ersten Ablehnung: B läuft nicht');
        T::same('not_found', $w->cell('m1', 'read', ['forum_id' => $w->f['fo']]), 'dto. bei read: not_found');
    });
    // Die Priorität bestimmt die Reihenfolge, nicht die Registrierung: B (Priorität 5) zuerst, A (Priorität 20) danach
    $calls = [];
    $with([[$deny('A'), 20], [$widest('B'), 5]], function () use ($w, $reply, &$calls): void {
        T::same('filter_denied', $w->cell('m1', 'reply', $reply), 'B (Priorität 5) erlaubt, A (Priorität 20) lehnt ab: abgelehnt');
        T::same(['B', 'A'], $calls, 'Ausführungsreihenfolge nach Priorität');
    });
    // Der Filter nach einer Ablehnung kann auch nichts mehr erweitern: Mengen-API
    $with([[$deny('A'), 5], [$widest('B'), 20]], function () use ($w): void {
        T::same([], $w->access->readableForumIds($w->u['m1']), 'readableForumIds: A lehnt alle ab, B erlaubt wieder: nichts lesbar');
        T::same([], $w->access->filterReadable($w->u['m1'], [$w->f['fo'], $w->f['fr']]), 'filterReadable: dto.');
    });
    $only = static function (int $forumId) use ($w): \Closure {
        return static fn (AccessDecision $d, int $uid, string $action, array $ctx): AccessDecision => ($ctx['forum_id'] ?? 0) === $forumId ? AccessDecision::deny('x') : $d;
    };
    $with([[$only($w->f['fr']), 5], [$widest('B'), 20]], function () use ($w): void {
        T::same(false, in_array($w->f['fr'], $w->access->readableForumIds($w->u['m1']), true), 'ein abgelehntes Forum bleibt abgelehnt, auch wenn ein späterer Filter alles erlaubt');
        T::same(true, in_array($w->f['fo'], $w->access->readableForumIds($w->u['m1']), true), 'die übrigen Foren bleiben lesbar');
    });

    // B8: eine Ausnahme ist eine Ablehnung (vor und nach einem ablehnenden Filter, allein, bei Error/TypeError)
    $calls = [];
    $with([[$throws('X'), 5], [$deny('A'), 10]], function () use ($w, $reply, &$calls): void {
        T::same('filter_denied', $w->cell('m1', 'reply', $reply), 'X wirft (Priorität 5): Ablehnung, nicht der Eingangswert');
        T::same(['X'], $calls, 'die Kette endet bei der Ausnahme');
    });
    $calls = [];
    $with([[$deny('A'), 5], [$throws('X'), 10]], function () use ($w, $reply, &$calls): void {
        T::same('filter_denied', $w->cell('m1', 'reply', $reply), 'A lehnt ab, danach würde X werfen: abgelehnt');
        T::same(['A'], $calls, 'X läuft gar nicht mehr');
    });
    $with([[static fn (AccessDecision $d, array $uid): AccessDecision => $d, 10]], function () use ($w, $reply): void {
        T::same('filter_denied', $w->cell('m1', 'reply', $reply), 'TypeError im Filter (Signatur passt nicht): Ablehnung');
    });
    $with([[static fn (AccessDecision $d): AccessDecision => $d, 5], [static function (): never {
        throw new \RuntimeException('Plugin kaputt');
    }, 10]], function () use ($w, $reply): void {
        T::same('filter_denied', $w->cell('m1', 'reply', $reply), 'erster Filter unverändert, zweiter wirft: Ablehnung');
        T::same('not_found', $w->cell('guest', 'read', ['forum_id' => $w->f['fo']]), 'dto. bei read: not_found');
        T::same('not_found', $w->cell('m1', 'search', AC_GLOBAL), 'dto. bei search: not_found');
        T::same([], $w->access->readableForumIds($w->u['m1']), 'dto. in der Mengen-API: nichts lesbar');
    });
    $before = substr_count(acLogText(), 'warf eine Ausnahme und lehnt ab');
    $with([[$throws('X'), 10]], function () use ($w, $reply): void {
        $w->can('m1', 'reply', $reply);
    });
    T::same($before + 1, substr_count(acLogText(), 'warf eine Ausnahme und lehnt ab'), 'die Ausnahme eines Filters wird protokolliert');
    // Andere Ablehnungen, andere Aktionen: keine Wirkung ohne Filter
    T::same('ok:published', $w->cell('m1', 'reply', $reply), 'ohne Filter gilt wieder die Ausgangsentscheidung');

    // Verschärfungen bleiben: ein späterer Filter kann sie nicht zurücknehmen (published → pending → published)
    $tighten = static fn (AccessDecision $d): AccessDecision => $d->withInitialStatus($d->initialStatus === null ? null : 'pending')->withView($d->view === null ? null : 'placeholder');
    $before = substr_count(acLogText(), 'wollte eine Entscheidung erweitern');
    $with([[$tighten, 5], [$widest('B'), 20]], function () use ($w): void {
        T::same('ok:pending', $w->cell('m1', 'reply', ['topic_id' => $w->t['t']]), 'A verschärft zu pending, B will veröffentlicht: pending bleibt');
        T::same('ok:placeholder', $w->cell('m1', 'read', ['post_id' => $w->p['p']]), 'A verschärft zu Platzhalter, B will voll: Platzhalter bleibt');
    });
    T::same($before + 2, substr_count(acLogText(), 'wollte eine Entscheidung erweitern'), 'beide Erweiterungen werden protokolliert');
    // Eine Verschärfung in der Mitte der Kette gilt für den Rest, ein Ergebnis ohne Entscheidung wird übersprungen
    $with([[static fn () => null, 5], [$tighten, 10], [static fn (AccessDecision $d): AccessDecision => $d, 20]], function () use ($w): void {
        T::same('ok:pending', $w->cell('m1', 'reply', ['topic_id' => $w->t['t']]), 'ein Filter ohne Rückgabe wird übersprungen, die Verschärfung danach gilt');
    });
    $with([[static fn () => 'allow', 5], [$deny('A'), 10]], function () use ($w, $reply): void {
        T::same('filter_denied', $w->cell('m1', 'reply', $reply), 'ein ungültiges Ergebnis davor hindert die Ablehnung nicht');
    });

    // B11: bei read und search keine Unterscheidung zwischen „abgelehnt“ und „gibt es nicht“
    $denyRead = static fn (AccessDecision $d, int $uid, string $action): AccessDecision => in_array($action, ['read', 'search'], true) ? AccessDecision::deny('geheim', 30) : $d;
    $with([[$denyRead, 10]], function () use ($w): void {
        $known = $w->access->can($w->u['m1'], 'read', ['forum_id' => $w->f['fo']]);
        $unknown = $w->access->can($w->u['m1'], 'read', ['forum_id' => 99999]);
        T::same(['not_found', 404, null], [$known->reason, $known->status(), $known->retryAfter], 'Filter lehnt read ab: not_found, HTTP 404, ohne Wartezeit');
        T::same([$unknown->reason, $unknown->status(), $unknown->retryAfter], [$known->reason, $known->status(), $known->retryAfter], 'identisch zu einem unbekannten Forum (kein Existenzkanal)');
        T::same('not_found', $w->cell('m1', 'read', ['topic_id' => $w->t['t']]), 'dto. für ein Thema');
        T::same('not_found', $w->cell('m1', 'read', ['post_id' => $w->p['p']]), 'dto. für einen Beitrag');
        T::same('not_found', $w->cell('guest', 'search', AC_GLOBAL), 'search: not_found');
        T::same('ok', $w->cell('m1', 'subscribe', ['topic_id' => $w->t['t']]), 'andere Aktionen sind vom Lesefilter unberührt');
    });
    $denyWrite = static fn (AccessDecision $d, int $uid, string $action): AccessDecision => $action === 'reply' ? AccessDecision::deny('geheim', 30) : $d;
    $with([[$denyWrite, 10]], function () use ($w): void {
        T::same('filter_denied@30', $w->cell('m1', 'reply', ['topic_id' => $w->t['t']]), 'Schreibaktionen: weiter filter_denied mit der Wartezeit des Filters');
        T::same(403, $w->can('m1', 'reply', ['topic_id' => $w->t['t']])->status(), 'filter_denied meldet 403');
    });
    // Die Argumente jedes Filters sind die Entscheidung des vorigen (nicht die ursprüngliche)
    $seen = [];
    $spy = static function (AccessDecision $d) use (&$seen): AccessDecision {
        $seen[] = $d->initialStatus;

        return $d;
    };
    $with([[$tighten, 5], [$spy, 10]], function () use ($w): void {
        $w->can('m1', 'reply', ['topic_id' => $w->t['t']]);
    });
    T::same(['pending'], $seen, 'der zweite Filter bekommt die Entscheidung, die der erste übrig gelassen hat');
});

T::run('Hooks::getCallbacks, Hooks::doActionIsolated und Events::fire: jeder Verbraucher einzeln abgesichert (B20)', static function (): void {
    $tag = 'cms_community_test_isolation';
    $calls = [];
    $listener = static function (string $name, bool $throw = false) use (&$calls): \Closure {
        return static function (mixed ...$args) use (&$calls, $name, $throw): void {
            $calls[] = [$name, $args];
            if ($throw) {
                throw new \RuntimeException('Verbraucher ' . $name . ' kaputt');
            }
        };
    };
    $first = $listener('erster');
    $bad = $listener('kaputt', true);
    $last = $listener('letzter');
    $typed = static function (int $needsInt) use (&$calls): void {
        $calls[] = ['typisiert', [$needsInt]];
    };
    Hooks::addAction($tag, $last, 20);
    Hooks::addAction($tag, $bad, 10);
    Hooks::addAction($tag, $first, 5);
    Hooks::addAction($tag, $typed, 10);
    $before = substr_count(acLogText(), 'Community-Hook');

    // getCallbacks: Priorität aufsteigend, innerhalb einer Priorität in der Reihenfolge der Registrierung
    T::same([$first, $bad, $typed, $last], Hooks::getCallbacks($tag, 'action'), 'getCallbacks: Priorität aufsteigend, bei gleicher Priorität nach Registrierung');
    T::same([], Hooks::getCallbacks($tag, 'filter'), 'getCallbacks: Aktionen und Filter sind getrennt');
    T::same([], Hooks::getCallbacks($tag, 'unbekannt'), 'getCallbacks: unbekannter Typ → leere Liste');
    T::same([], Hooks::getCallbacks('cms_community_ohne_eintrag'), 'getCallbacks: unbekannter Tag → leere Liste');
    Hooks::addFilter($tag . '_f', $first, 7);
    Hooks::addFilter($tag . '_f', $last, 3);
    T::same([$last, $first], Hooks::getCallbacks($tag . '_f'), 'getCallbacks: Filter sind der Standardtyp');
    T::same([$last, $first], Hooks::getCallbacks($tag . '_f', 'filter'), 'getCallbacks: gleiche Antwort beim zweiten Aufruf (ändert nichts)');
    Hooks::removeFilter($tag . '_f', $first, 7);
    T::same([$last], Hooks::getCallbacks($tag . '_f'), 'getCallbacks: entfernte Filter fehlen');
    Hooks::removeFilter($tag . '_f', $last, 3);

    // fire(): der kaputte Verbraucher (und der mit falscher Signatur) hält die übrigen nicht auf
    Events::fire($tag, 'x');
    T::same(['erster', 'kaputt', 'letzter'], array_map(static fn (array $c): string => $c[0], $calls), 'fire(): alle Verbraucher laufen, auch hinter dem kaputten und dem TypeError (typisiert wirft vor dem Aufruf)');
    T::same([['erster', ['x']], ['kaputt', ['x']], ['letzter', ['x']]], $calls, 'fire(): jeder Verbraucher bekommt die Argumente unverändert');
    T::same($before + 2, substr_count(acLogText(), 'Community-Hook'), 'fire(): zwei Fehler (Ausnahme und TypeError) werden protokolliert');
    T::same(1, Hooks::didAction($tag), 'fire() zählt für didAction() wie doAction()');
    Events::fire($tag, 'y');
    T::same(2, Hooks::didAction($tag), 'didAction() zählt jeden Aufruf');

    // doActionIsolated(): ohne Fehlerbehandlung, mit werfender Fehlerbehandlung
    $calls = [];
    $caught = [];
    Hooks::doActionIsolated($tag, static function (\Throwable $e, callable $callback) use (&$caught): void {
        $caught[] = [$e->getMessage() !== '', is_callable($callback)];
    }, 'z');
    T::same(['erster', 'kaputt', 'letzter'], array_map(static fn (array $c): string => $c[0], $calls), 'doActionIsolated(): alle Verbraucher laufen');
    T::same([[true, true], [true, true]], $caught, 'doActionIsolated(): die Fehlerbehandlung bekommt Ausnahme und Callback');
    $calls = [];
    Hooks::doActionIsolated($tag, null, 'z');
    T::same(3, count($calls), 'doActionIsolated(): ohne Fehlerbehandlung werden Fehler verschluckt');
    $calls = [];
    $thrown = false;
    try {
        Hooks::doActionIsolated($tag, static function (): never {
            throw new \LogicException('Fehlerbehandlung kaputt');
        }, 'z');
    } catch (\Throwable) {
        $thrown = true;
    }
    T::same([false, 3], [$thrown, count($calls)], 'doActionIsolated(): auch eine werfende Fehlerbehandlung hält die Verbraucher nicht auf');
    // Ohne Verbraucher kein Fehler
    Events::fire('cms_community_ohne_eintrag', 1);
    T::same(1, Hooks::didAction('cms_community_ohne_eintrag'), 'ein Hook ohne Verbraucher wird trotzdem gezählt');

    // Einmalige Dokument-Abschluss-Hooks bleiben einmalig
    $once = 0;
    $counter = static function () use (&$once): void {
        $once++;
    };
    Hooks::addAction('body_end', $counter);
    Hooks::doActionIsolated('body_end', null);
    Hooks::doActionIsolated('body_end', null);
    Hooks::removeAction('body_end', $counter);
    T::same(1, $once, 'body_end läuft auch über doActionIsolated() nur einmal je Request');

    foreach ([[$first, 5], [$bad, 10], [$typed, 10], [$last, 20]] as [$callback, $priority]) {
        Hooks::removeAction($tag, $callback, $priority);
    }
    T::same([], Hooks::getCallbacks($tag, 'action'), 'aufgeräumt');
    $source = acRead(AC_ROOT . 'Support/Events.php');
    T::check(str_contains($source, 'Hooks::doActionIsolated('), 'Events::fire läuft über doActionIsolated (kein Hooks::doAction, das beim ersten Fehler abbricht)');
    T::check(!str_contains(acCode($source), 'Hooks::doAction('), 'Events enthält kein Hooks::doAction(');
});

// ── Zustände, Ziele, Zwischenspeicher ───────────────────────────────────────────────────────────────────────────

T::run('Ziel: Beitrag → Thema → Forum; das Forum kommt nie aus der Eingabe; Zeilen statt IDs', static function (): void {
    $w = acStandardWorld();
    // Thema im internen Forum, Forum-ID des offenen Forums dazugeschrieben: das Forum des Themas gilt
    T::same('not_found', $w->cell('m1', 'reply', ['topic_id' => $w->t['tfi'], 'forum_id' => $w->f['fo']]), 'reply: forum_id wird bei übergebenem Thema ignoriert');
    T::same('not_found', $w->cell('m1', 'read', ['topic_id' => $w->t['tfi'], 'forum_id' => $w->f['fo']]), 'read: forum_id wird bei übergebenem Thema ignoriert');
    T::same('not_found', $w->cell('m1', 'read', ['post_id' => $w->p['pfi'], 'topic_id' => $w->t['t'], 'forum_id' => $w->f['fo']]), 'Beitrag gewinnt gegen Thema- und Forum-ID');
    T::same('ok:published', $w->cell('m1', 'reply', ['topic_id' => $w->t['t'], 'forum_id' => $w->f['fi']]), 'ein falsches forum_id verbietet nichts, was das Thema erlaubt');

    // IDs als Text, ungültige IDs, unbekannte IDs
    T::same('ok:published', $w->cell('m1', 'reply', ['topic_id' => (string) $w->t['t']]), 'ID als Ziffernfolge');
    foreach ([0, -3, 'abc', '', 1.5, true, 99999] as $bad) {
        T::same('not_found', $w->cell('m1', 'reply', ['topic_id' => $bad]), 'ungültige oder unbekannte Thema-ID ' . var_export($bad, true));
        T::same('not_found', $w->cell('m1', 'read', ['post_id' => $bad]), 'ungültige oder unbekannte Beitrag-ID ' . var_export($bad, true));
        T::same('not_found', $w->cell('m1', 'read', ['forum_id' => $bad]), 'ungültige oder unbekannte Forum-ID ' . var_export($bad, true));
    }

    // Geladene Zeilen aus den Repositories
    $topics = new TopicRepository($w->db);
    $posts = new PostRepository($w->db);
    $forums = new ForumRepository($w->db);
    $topicRow = $topics->find($w->t['t']);
    $postRow = $posts->find($w->p['p']);
    T::same('ok:published', $w->cell('m1', 'reply', ['topic' => $topicRow]), 'Thema als Zeile');
    T::same('ok', $w->cell('own', 'edit', ['post' => $postRow]), 'Beitrag als Zeile');
    T::same('ok', $w->cell('own', 'edit', ['post' => $postRow, 'topic' => $topics->find($w->t['tfi'])]), 'ein fremdes Thema neben dem Beitrag zählt nicht (das Thema kommt aus dem Beitrag)');
    T::same('ok:published', $w->cell('m1', 'reply', ['topic' => ['id' => $w->t['t']]]), 'Zeile ohne die nötigen Spalten: wird neu geladen');
    T::same('not_found', $w->cell('m1', 'reply', ['topic' => ['id' => 0]]), 'Zeile ohne gültige ID: unbekannt');
    T::same('not_found', $w->cell('m1', 'reply', ['topic' => ['status' => 'published']]), 'Zeile ohne ID: unbekannt');
    T::same('ok:published', $w->cell('m1', 'reply', ['topic' => $topicRow, 'forum' => $forums->find($w->f['fo'])]), 'Thema und Forum als Zeilen');
    T::same('ok:published', $w->cell('m1', 'reply', ['topic' => $topicRow, 'forum' => $forums->find($w->f['fi'])]), 'eine Forum-Zeile eines anderen Forums wird ignoriert: das Forum des Themas gilt');

    // Zeit aus dem Kontext (Tests): eine Stunde später ist das 30-Minuten-Fenster vorbei
    T::same('edit_window', acCell($w->access->can($w->u['own'], 'edit', ['post_id' => $w->p['p'], 'now' => $w->now + 3600])), 'now im Kontext verschiebt die Zeit der Entscheidung');
    T::same('ok', acCell($w->access->can($w->u['own'], 'edit', ['post_id' => $w->p['p'], 'now' => $w->now])), 'now = jetzt: im Fenster');
    T::same('ok', acCell($w->access->can($w->u['own'], 'edit', ['post_id' => $w->p['p'], 'now' => 'später'])), 'now ohne Zahl wird ignoriert (Uhr gilt)');
    T::same('ok:published', acCell($w->access->can($w->u['m1'], 'reply', ['topic_id' => $w->t['tlf'], 'now' => $w->now + 7200])), 'locked_until wird mit dem Zeitpunkt der Entscheidung verglichen');
});

T::run('Ziellose Fragen: read/search nur mit scope = global; ein leeres oder falsch geschriebenes Ziel ist not_found; eine ID ist nie eine Zeile (B2, B12)', static function (): void {
    $w = acStandardWorld(['guests_can_read' => true]);
    $internal = $w->f['fi'];
    $nothing = [
        [], ['forum_id' => null], ['topic_id' => null], ['post_id' => null], ['forum' => null], ['topic' => null], ['post' => null],
        ['forum_id' => null, 'topic_id' => null, 'post_id' => null], ['forumId' => $internal], ['Forum_ID' => $internal], ['topicid' => $w->t['tfi']],
        ['scope' => 'Global'], ['scope' => 'all'], ['scope' => true], ['scope' => 1], ['scope' => null], ['scope' => ['global']], ['scope' => ' global'],
        ['scope' => 'global', 'forum_id' => null], ['scope' => 'global', 'topic_id' => null], ['scope' => 'global', 'post' => null],
    ];
    foreach (['guest', 'm1', 'admin'] as $user) {
        foreach (['read', 'search'] as $action) {
            foreach ($nothing as $ctx) {
                T::same('not_found', $w->cell($user, $action, $ctx), $user . ' ' . $action . ' ' . json_encode($ctx) . ': kein erkanntes Ziel und kein scope = global → not_found');
            }
            T::same('ok', $w->cell($user, $action, AC_GLOBAL), $user . ' ' . $action . ' mit scope = global: erlaubt');
        }
    }
    // scope = global ersetzt kein Ziel: ein genanntes Ziel entscheidet allein
    T::same('not_found', $w->cell('m1', 'read', AC_GLOBAL + ['forum_id' => $internal]), 'scope = global neben einem internen Forum: das Forum entscheidet (not_found)');
    T::same('not_found', $w->cell('m1', 'read', AC_GLOBAL + ['forum_id' => 99999]), 'scope = global neben einem unbekannten Forum: not_found');
    T::same('ok', $w->cell('m1', 'read', AC_GLOBAL + ['forum_id' => $w->f['fo']]), 'scope = global neben einem offenen Forum: ok');
    T::same('not_found', $w->cell('guest', 'read', AC_GLOBAL + ['topic_id' => $w->t['tp']]), 'scope = global neben einem wartenden Thema: not_found');
    // Die Sperre und das Gast-Tor gehen vor, auch mit scope = global
    T::same('banned_full', $w->cell('bf', 'read', AC_GLOBAL), 'Sperre full: auch die globale Frage ist gesperrt');
    $closed = acStandardWorld(['guests_can_read' => false]);
    T::same('not_found', $closed->cell('guest', 'read', AC_GLOBAL), 'Gast-Tor zu: scope = global öffnet nichts');
    T::same('no_capability', $closed->cell('guest', 'search', AC_GLOBAL), 'Gast-Tor zu: Suche mit scope = global nicht möglich');
    // Alle übrigen Aktionen brauchen ein Ziel, scope = global hilft ihnen nicht
    foreach (['create_topic', 'reply', 'edit', 'delete', 'upload', 'vote', 'react', 'report', 'subscribe', 'topic.lock', 'post.hide'] as $action) {
        T::same('not_found', $w->cell('gm', $action, AC_GLOBAL), $action . ' ohne Ziel (auch mit scope = global): not_found');
    }
    T::same('ok', $w->cell('gm', 'moderate', []), 'moderate ohne Ziel: globale Moderation (unverändert)');
    T::same('no_permission', $w->cell('m1', 'moderate', []), 'moderate ohne Ziel: Mitglied ohne Moderation');

    // B12: eine ID ist nie eine Zeile; ein Array unter `*_id` (aus ?topic_id[id]=… der Anfrage) ist ein unbekanntes Ziel
    $forged = [
        'id' => $w->t['tfi'], 'status' => 'published', 'forum_id' => $w->f['fo'], 'user_id' => $w->u['own'], 'created_at' => $w->utc(-60),
        'is_locked' => 0, 'locked_until' => null, 'slow_mode_seconds' => 0,
    ];
    foreach (['guest', 'm1', 'gm'] as $user) {
        T::same('not_found', $w->cell($user, 'read', ['topic_id' => $forged]), $user . ': Zeile unter topic_id → unbekanntes Ziel (kein Weg am internen Forum vorbei)');
        T::same('not_found', $w->cell($user, 'read', ['forum_id' => ['id' => $w->f['fo']]]), $user . ': Array unter forum_id → unbekanntes Ziel');
        T::same('not_found', $w->cell($user, 'read', ['post_id' => ['id' => $w->p['p']]]), $user . ': Array unter post_id → unbekanntes Ziel');
    }
    T::same('not_found', $w->cell('m1', 'reply', ['topic_id' => $forged]), 'reply mit einer Zeile unter topic_id: not_found');
    T::same('not_found', $w->cell('m1', 'read', ['topic_id' => $forged, 'forum_id' => $w->f['fo']]), 'dto. mit zusätzlicher forum_id');
    T::same('not_found', $w->cell('m1', 'read', ['topic_id' => $w->t['t'], 'post_id' => ['id' => $w->p['p']]]), 'ein Array unter post_id ist ein unbekannter Beitrag, auch neben einer gültigen topic_id (der Beitrag ist die engste Ebene)');
    // Vertrag: unter dem Namen der Zeile (`topic`, `forum`, `post`) werden Zeilen vertraut, denn nur Dienste geben sie mit
    T::same('ok', $w->cell('m1', 'read', ['topic' => $forged]), 'Vertrag: eine Zeile unter `topic` gilt (Dienste geben geladene Zeilen mit; Controller geben IDs)');
});

T::run('Zwischenspeicher: forgetUser(), flush(), fresh; Abfragen je Aufruf', static function (): void {
    $w = acStandardWorld(['guests_can_read' => false]);
    $read = ['topic_id' => $w->t['t']];
    $mid = $w->u['m1'];

    T::same('ok', $w->cell('m1', 'read', $read), 'Ausgangslage: lesbar');
    // Rolle in der Datenbank ändern: ohne forgetUser() gilt die zwischengespeicherte Rolle weiter
    $w->sql('UPDATE cms_users SET role = ? WHERE id = ?', ['nocap', $mid]);
    T::same('ok', $w->cell('m1', 'read', $read), 'ohne forgetUser(): zwischengespeicherte Rolle (Rechte je Request)');
    $w->access->forgetUser($mid);
    T::same('not_found', $w->cell('m1', 'read', $read), 'nach forgetUser(): neue Rolle gilt');
    $w->sql('UPDATE cms_users SET role = ? WHERE id = ?', ['member', $mid]);
    T::same('not_found', $w->cell('m1', 'read', $read), 'wieder ohne forgetUser(): noch die alte Rolle');
    T::same('ok', acCell($w->access->can($mid, 'read', $read + ['fresh' => true])), 'fresh im Kontext: Zwischenspeicher des Mitglieds wird verworfen');
    T::same('ok', $w->cell('m1', 'read', $read), 'fresh hat den Zwischenspeicher aufgefrischt');

    // forgetUser(A) berührt B nicht
    $w->sql('UPDATE cms_users SET role = ? WHERE id = ?', ['member', $mid]);
    $w->access->flush();
    T::same('ok', $w->cell('m1', 'read', $read), 'Konto A zwischengespeichert');
    T::same('ok', $w->cell('m3', 'read', $read), 'Konto B zwischengespeichert');
    $w->sql('UPDATE cms_users SET role = ? WHERE id IN (?, ?)', ['nocap', $mid, $w->u['m3']]);
    $w->access->forgetUser($mid);
    T::same('not_found', $w->cell('m1', 'read', $read), 'forgetUser(A): A sieht die Änderung');
    T::same('ok', $w->cell('m3', 'read', $read), 'forgetUser(A): B bleibt zwischengespeichert');
    $w->access->flush();
    T::same('not_found', $w->cell('m3', 'read', $read), 'flush(): auch B sieht die Änderung');
    $w->sql('UPDATE cms_users SET role = ? WHERE id IN (?, ?)', ['member', $mid, $w->u['m3']]);
    $w->access->flush();

    // E-Mail-Bestätigung und Rollenrechte sind zwischengespeichert, bis forgetUser()
    $uv = $w->u['unverified'];
    T::same('email_unverified', $w->cell('unverified', 'reply', $read), 'unbestätigt');
    $w->verified[$uv] = true;
    T::same('email_unverified', $w->cell('unverified', 'reply', $read), 'Bestätigung ohne forgetUser(): noch unbestätigt (Hook user_email_verified ruft forgetUser)');
    $w->access->forgetUser($uv);
    T::same('ok:published', $w->cell('unverified', 'reply', $read), 'nach forgetUser(): bestätigt');

    // Forenbaum: ein Forum, das nach dem ersten Aufruf angelegt wird, ist trotzdem bekannt (veralteter Baum wird neu gelesen)
    $w->access->flush();
    $w->cell('m1', 'read', ['forum_id' => $w->f['fo']]);
    $w->forum('spaet');
    T::same('ok', $w->cell('m1', 'read', ['forum_id' => $w->f['spaet']]), 'neu angelegtes Forum nach zwischengespeichertem Baum');
    $w->sql('UPDATE cms_community_forums SET status = ? WHERE id = ?', ['hidden', $w->f['spaet']]);
    T::same('not_found', $w->cell('m1', 'read', ['forum_id' => $w->f['spaet']]), 'der Zustand des Forums wird bei jedem Aufruf frisch gelesen');
    $w->sql('UPDATE cms_community_topics SET status = ? WHERE id = ?', ['deleted', $w->t['t']]);
    T::same('not_found', $w->cell('m1', 'read', ['topic_id' => $w->t['t']]), 'der Zustand des Themas wird bei jedem Aufruf frisch gelesen');

    // Abfragen: der zweite gleiche Aufruf braucht weniger (Konto, Rolle, Gruppen, Zeilen sind zwischengespeichert)
    $w->sql('UPDATE cms_community_topics SET status = ? WHERE id = ?', ['published', $w->t['t']]);
    $w->access->flush();
    $w->db->log = [];
    $w->can('m1', 'reply', ['topic_id' => $w->t['t']]);
    $first = count($w->db->log);
    $w->db->log = [];
    $w->can('m1', 'reply', ['topic_id' => $w->t['t']]);
    $second = count($w->db->log);
    T::check($second < $first, 'zweiter Aufruf mit weniger Abfragen (' . $first . ' → ' . $second . ')');
    $w->db->log = [];
    $w->can('m1', 'reply', ['topic_id' => $w->t['t'], 'fresh' => true]);
    T::check(count($w->db->log) >= $first - 1, 'fresh liest wieder alles (' . count($w->db->log) . ' Abfragen)');
});

T::run('fresh und Sperrencache: Sperre, Forenbaum und Stufe werden neu gelesen, wo es verlangt ist (B5, B19)', static function (): void {
    // Sperre nach der ersten Entscheidung (Dienste entscheiden unter Zeilensperre erneut mit fresh)
    $w = acStandardWorld(['rate_post_seconds' => 0]);
    $w->member('late', ['posts' => 5]);
    $reply = ['topic_id' => $w->t['t'], 'now' => $w->now];
    T::same('ok:published', acCell($w->access->can($w->u['late'], 'reply', $reply)), 'Ausgangslage: keine Sperre');
    $w->db->ban(['user' => $w->u['late'], 'scope' => 'write']);
    T::same('ok:published', acCell($w->access->can($w->u['late'], 'reply', $reply)), 'dieselbe Sekunde, ohne fresh: die zwischengespeicherte Sperrenabfrage gilt (je Sekunde)');
    T::same('banned_write', acCell($w->access->can($w->u['late'], 'reply', $reply + ['fresh' => true])), 'fresh liest die Sperre neu: banned_write');
    T::same('banned_write', acCell($w->access->can($w->u['late'], 'reply', $reply)), 'danach gilt die frisch gelesene Sperre auch ohne fresh');

    // forgetUser() verwirft die Sperre ebenso
    $w2 = acStandardWorld(['rate_post_seconds' => 0]);
    $w2->member('late', ['posts' => 5]);
    T::same('ok:published', acCell($w2->access->can($w2->u['late'], 'reply', $reply2 = ['topic_id' => $w2->t['t'], 'now' => $w2->now])), 'Ausgangslage (zweite Welt)');
    $w2->db->ban(['user' => $w2->u['late'], 'scope' => 'full']);
    $w2->access->forgetUser($w2->u['late']);
    T::same('banned_full', acCell($w2->access->can($w2->u['late'], 'reply', $reply2)), 'forgetUser() verwirft die zwischengespeicherte Sperre');
    T::same('banned_full', acCell($w2->access->can($w2->u['late'], 'read', ['topic_id' => $w2->t['t'], 'now' => $w2->now])), 'dto. für Lesen (Sperre full)');

    // Der Sperrencache gilt je Sekunde: eine Sekunde später wird neu gelesen, ohne fresh
    $w3 = acStandardWorld(['rate_post_seconds' => 0]);
    $w3->member('late', ['posts' => 5]);
    $at = static fn (int $delta): array => ['topic_id' => $w3->t['t'], 'now' => $w3->now + $delta];
    T::same('ok:published', acCell($w3->access->can($w3->u['late'], 'reply', $at(0))), 'Sekunde 0: keine Sperre');
    $w3->db->ban(['user' => $w3->u['late'], 'scope' => 'write', 'ends_at' => $w3->utc(60)]);
    T::same('ok:published', acCell($w3->access->can($w3->u['late'], 'reply', $at(0))), 'Sekunde 0 noch einmal: zwischengespeichert, noch keine Sperre');
    T::same('banned_write', acCell($w3->access->can($w3->u['late'], 'reply', $at(1))), 'Sekunde 1: die Abfrage läuft neu, die Sperre gilt');
    T::same('banned_write', acCell($w3->access->can($w3->u['late'], 'reply', $at(59))), 'Sekunde 59: gesperrt');
    T::same('ok:published', acCell($w3->access->can($w3->u['late'], 'reply', $at(61))), 'Sekunde 61: die Sperre ist abgelaufen, ohne fresh und ohne forgetUser()');
    T::same('banned_write', acCell($w3->access->can($w3->u['late'], 'reply', $at(30))), 'Sekunde 30 (zurück in der Zeit): wieder neu gelesen, gesperrt');

    // fresh verwirft auch den Forenbaum: ein Elternforum, das zwischenzeitlich verborgen wurde, gilt sofort
    $t = acStandardWorld();
    $t->forum('Pf');
    $t->forum('Cf', ['parent' => 'Pf']);
    $t->topic('tCf', 'Cf');
    $child = ['topic_id' => $t->t['tCf'], 'now' => $t->now];
    T::same('ok:published', acCell($t->access->can($t->u['m1'], 'reply', $child)), 'Kindforum: antworten möglich (der Forenbaum ist jetzt zwischengespeichert)');
    $t->sql('UPDATE cms_community_forums SET status = ? WHERE id = ?', ['hidden', $t->f['Pf']]);
    T::same('not_found', acCell($t->access->can($t->u['m1'], 'reply', $child + ['fresh' => true])), 'fresh liest den Forenbaum neu: das Elternforum ist verborgen, das Kindforum nicht mehr erreichbar');
    $t->sql('UPDATE cms_community_forums SET status = ? WHERE id = ?', ['open', $t->f['Pf']]);
    T::same('ok:published', acCell($t->access->can($t->u['m1'], 'reply', $child + ['fresh' => true])), 'fresh noch einmal: wieder offen');

    // Die Stufe für lesende Entscheidungen: je Instanz einmal gelesen; Schreibaktionen, fresh und forgetUser() frischen sie auf
    $l = acStandardWorld();
    $l->forum('t1');
    $l->permissions('t1', [['subject_type' => 'trust', 'subject_key' => '1', 'permissions' => ['read' => 1, 'reply' => 1]]]);
    $l->topic('t1t', 't1');
    $l->member('lv', ['posts' => 0]);
    $l->member('lv2', ['posts' => 0]);
    $l->member('lv3', ['posts' => 0]);
    $read = ['forum_id' => $l->f['t1']];
    foreach (['lv', 'lv2', 'lv3'] as $name) {
        T::same('not_found', $l->cell($name, 'read', $read), $name . ': Stufe 0 liest trust:1 nicht');
    }
    $l->sql('UPDATE cms_community_user_stats SET post_count = ? WHERE user_id IN (?, ?, ?)', [5, $l->u['lv'], $l->u['lv2'], $l->u['lv3']]);
    T::same('not_found', $l->cell('lv', 'read', $read), 'Lesen nutzt die je Instanz zwischengespeicherte Stufe (die Beitragszahl hat sich geändert)');
    $l->access->forgetUser($l->u['lv']);
    T::same('ok', $l->cell('lv', 'read', $read), 'forgetUser() verwirft die Stufe');
    $l->cell('lv2', 'reply', ['topic_id' => $l->t['t1t']]);
    T::same('ok', $l->cell('lv2', 'read', $read), 'eine Schreibentscheidung liest die Statistik und frischt die Stufe auf');
    T::same('ok', acCell($l->access->can($l->u['lv3'], 'read', $read + ['fresh' => true])), 'fresh verwirft die Stufe');
    // Die Stufe eines Mitglieds berührt kein anderes
    $l->member('lv4', ['posts' => 0]);
    T::same('not_found', $l->cell('lv4', 'read', $read), 'lv4 (Stufe 0) wird zwischengespeichert');
    $l->sql('UPDATE cms_community_user_stats SET post_count = ? WHERE user_id = ?', [5, $l->u['lv4']]);
    $l->access->forgetUser($l->u['lv']);
    T::same('not_found', $l->cell('lv4', 'read', $read), 'forgetUser() eines anderen Mitglieds berührt lv4 nicht');
    $l->access->flush();
    T::same('ok', $l->cell('lv4', 'read', $read), 'flush() verwirft die Stufe aller Mitglieder');
});

T::run('read eines Beitrags kostet mit übergebenen Zeilen höchstens eine Abfrage je Beitrag; die Statistik nur für Schreibaktionen (B9, B18)', static function (): void {
    $w = acStandardWorld(['guests_can_read' => true]);
    $w->topic('big', 'fo');
    $posts = new PostRepository($w->db);
    $rows = [];
    for ($i = 0; $i < 40; $i++) {
        $rows[] = (array) $posts->find($w->post('big' . $i, 'big', ['age' => 5000 - $i]));
    }
    $topicRow = (new TopicRepository($w->db))->find($w->t['big']);
    $forumRow = (new ForumRepository($w->db))->find($w->f['fo']);
    $sweep = static function (string $viewer, bool $withRows) use ($w, $rows, $topicRow, $forumRow): array {
        $w->access->flush();
        $w->db->log = [];
        $bad = 0;
        foreach ($rows as $row) {
            $ctx = ['post_id' => (int) $row['id'], 'post' => $row, 'now' => $w->now];
            if ($withRows) {
                $ctx += ['topic' => $topicRow, 'forum' => $forumRow];
            }
            $d = $w->access->can($w->u[$viewer], 'read', $ctx);
            $bad += $d->allowed && $d->view === 'full' ? 0 : 1;
        }

        return [$bad, $w->db->log];
    };
    foreach (['guest', 'm1', 'gm', 'admin', 'fm'] as $viewer) {
        [$bad, $log] = $sweep($viewer, true);
        T::same(0, $bad, $viewer . ': alle 40 Beiträge lesbar (Ansicht full)');
        T::check(count($log) <= count($rows), $viewer . ': ' . count($log) . ' Abfragen für ' . count($rows) . ' Beiträge mit Thema- und Forenzeile im Kontext (höchstens eine je Beitrag)', implode(' | ', array_slice($log, 0, 6)));
        T::check(count(preg_grep('/community_user_stats/', $log) ?: []) <= 1, $viewer . ': die Statistik wird für Lesen höchstens einmal gelesen');
        T::same(0, count(preg_grep('/FROM \w*community_(topics|posts|forums) WHERE id = \? LIMIT 1/', $log) ?: []), $viewer . ': Thema-, Forum- und Beitragszeile werden nicht neu geladen');
        [, $logIds] = $sweep($viewer, false);
        T::check(count($logIds) > count($log), $viewer . ': ohne Zeilen kostet es mehr (' . count($logIds) . ' statt ' . count($log) . ')');
        T::info($viewer . ': ' . count($log) . ' Abfragen mit Zeilen, ' . count($logIds) . ' ohne (40 Beiträge)');
    }
    // Schreibaktionen lesen die Statistik jedes Mal (Beitragszahl, Stufe: nie zwischengespeichert)
    $w->access->flush();
    $w->db->log = [];
    for ($i = 0; $i < 3; $i++) {
        $w->can('m1', 'reply', ['topic_id' => $w->t['t'], 'now' => $w->now]);
    }
    T::same(3, count(preg_grep('/community_user_stats/', $w->db->log) ?: []), 'drei Schreibentscheidungen lesen die Statistik dreimal');
});

T::run('Gleiche Abfragefolge bei „gibt es nicht“ und „darfst du nicht sehen“ (not_found)', static function (): void {
    $w = acStandardWorld(['guests_can_read' => true]);
    $shape = static function (string $user, string $action, array $ctx) use ($w): array {
        $w->access->flush();
        $w->db->log = [];
        $decision = $w->can($user, $action, $ctx);
        $normalized = array_map(static fn (string $sql): string => (string) preg_replace('/\s+/', ' ', trim($sql)), $w->db->log);
        // Tree wird einmal je Request gelesen: dieselbe Abfrage in beiden Pfaden, daher bleibt sie in der Folge
        return [acCell($decision), $normalized];
    };
    $cases = [
        ['Thema: unbekannt oder im internen Forum', 'm1', 'read', ['topic_id' => 99999], ['topic_id' => $w->t['tfi']]],
        ['Beitrag: unbekannt oder im internen Forum', 'm1', 'read', ['post_id' => 99999], ['post_id' => $w->p['pfi']]],
        ['Forum: unbekannt oder intern', 'm1', 'read', ['forum_id' => 99999], ['forum_id' => $w->f['fi']]],
        ['Antworten: unbekanntes oder verborgenes Thema', 'm1', 'reply', ['topic_id' => 99999], ['topic_id' => $w->t['thf']]],
        ['Gast: Thema unbekannt oder nicht lesbar', 'guest', 'read', ['topic_id' => 99999], ['topic_id' => $w->t['tp']]],
        ['Thema unbekannt oder gelöscht (nur Moderation)', 'm0', 'read', ['topic_id' => 99999], ['topic_id' => $w->t['td']]],
    ];
    foreach ($cases as [$label, $user, $action, $unknown, $hidden]) {
        [$cellA, $queriesA] = $shape($user, $action, $unknown);
        [$cellB, $queriesB] = $shape($user, $action, $hidden);
        T::same('not_found', $cellA, $label . ': unbekannt = not_found');
        T::same('not_found', $cellB, $label . ': nicht sichtbar = not_found');
        // Dieselbe Folge von Tabellen und Anweisungsarten
        $kind = static fn (array $queries): array => array_map(static fn (string $sql): string => preg_replace('/ (WHERE|ORDER BY|LIMIT|AND) .*$/', '', $sql) ?? $sql, $queries);
        T::same($kind($queriesA), $kind($queriesB), $label . ': gleiche Abfragefolge (' . count($queriesA) . ' Abfragen)');
    }
});

T::run('Mengen-API: filterReadable() und readableForumIds() stimmen mit can(read) überein', static function (): void {
    $w = acStandardWorld(['guests_can_read' => true]);
    $w->forum('Pr');
    $w->forum('Pc', ['parent' => 'Pr', 'status' => 'hidden']);
    $w->forum('Pd', ['parent' => 'Pc']);
    $w->permissions('Pr', [['subject_type' => 'trust', 'subject_key' => '2', 'permissions' => ['read' => 1]]]);
    $all = array_keys((new ForumRepository($w->db))->tree());
    foreach (AC_COLS as $column => $name) {
        $fromCan = [];
        foreach ($all as $forumId) {
            if ($w->can($name, 'read', ['forum_id' => $forumId])->allowed) {
                $fromCan[] = $forumId;
            }
        }
        $w->access->flush();
        T::same($fromCan, $w->access->readableForumIds($w->u[$name]), $column . ' (' . $name . '): readableForumIds = alle Foren mit can(read)');
        T::same($fromCan, $w->access->filterReadable($w->u[$name], $all), $column . ': filterReadable über alle Foren');
    }

    $m1 = $w->u['m1'];
    T::same([$w->f['fq'], $w->f['fo']], $w->access->filterReadable($m1, [$w->f['fq'], $w->f['fo'], $w->f['fo'], 99999, 0, -1, $w->f['fi'], $w->f['fh']]), 'Reihenfolge der Eingabe, ohne Doppelte, unbekannte, verborgene und interne Foren');
    T::same([], $w->access->filterReadable($m1, []), 'leere Eingabe');
    T::same([], $w->access->filterReadable($m1, [0, -2, 99999]), 'nur ungültige IDs');
    T::same([$w->f['fo']], $w->access->filterReadable(null, [$w->f['fo'], $w->f['fi'], $w->f['fh']]), 'Gast: nur Foren mit Gast-Recht');
    T::same([$w->f['fo'], $w->f['fi'], $w->f['fh']], $w->access->filterReadable($w->u['admin'], [$w->f['fo'], $w->f['fi'], $w->f['fh']]), 'admin liest alle');
    T::same([], $w->access->filterReadable($w->u['bf'], [$w->f['fo']]), 'Sperre full: nichts lesbar');
    T::same([$w->f['fo']], $w->access->filterReadable($w->u['bw'], [$w->f['fo']]), 'Sperre write: lesbar');
    T::same([], $w->access->filterReadable($w->u['nc'], [$w->f['fo']]), 'ohne community.view: nichts lesbar');
    T::check(in_array($w->f['Pr'], $w->access->readableForumIds($w->u['m2']), true) && !in_array($w->f['Pr'], $w->access->readableForumIds($w->u['m1']), true), 'trust:2 wirkt auch in der Mengen-API');
    T::check(!in_array($w->f['Pd'], $w->access->readableForumIds($w->u['m2']), true), 'ein Forum unter einem verborgenen Elternforum fehlt');

    $w2 = acStandardWorld(['guests_can_read' => false]);
    T::same([], $w2->access->filterReadable(null, [$w2->f['fo']]), 'Gast-Tor zu: nichts lesbar');
    T::same([], $w2->access->readableForumIds($w2->u['inactive']), 'Gast-Tor zu: auch das nicht aktive Konto liest nichts');

    // Wenige Abfragen: Rechte-Zeilen werden je Forum einmal gelesen, nicht je Aufruf
    $w->access->flush();
    $w->db->log = [];
    $w->access->readableForumIds($m1);
    T::check(count($w->db->log) < 6 * count($all) + 10, 'Mengen-API: Abfragen wachsen höchstens linear mit der Forenzahl (' . count($w->db->log) . ' bei ' . count($all) . ' Foren)');
});

T::run('Gast-Tor in der Mengen-API: eine Gast-Zeile mit read = 1 öffnet bei geschlossenem Tor nichts (B4)', static function (): void {
    foreach ([true, false] as $gate) {
        $w = acStandardWorld(['guests_can_read' => $gate]);
        $w->forum('pub');
        $w->permissions('pub', [['subject_type' => 'guest', 'permissions' => ['read' => 1]]]);
        $pub = $w->f['pub'];
        $label = 'Tor ' . ($gate ? 'offen' : 'zu');
        // can(), filterReadable() und readableForumIds() müssen übereinstimmen
        foreach (['guest' => null, 'inactive' => $w->u['inactive']] as $name => $uid) {
            $fromCan = $w->access->can($uid, 'read', ['forum_id' => $pub])->allowed;
            T::same($gate, $fromCan, $label . ': can(read) ' . $name . ' auf das Forum mit Gast-Zeile');
            T::same($gate ? [$pub] : [], $w->access->filterReadable($uid, [$pub, $w->f['fi'], $w->f['fh']]), $label . ': filterReadable ' . $name . ' (Forum mit Gast-Zeile, internes, verborgenes)');
            T::same($gate, in_array($pub, $w->access->readableForumIds($uid), true), $label . ': readableForumIds ' . $name . ' enthält das Forum mit Gast-Zeile genau bei offenem Tor');
        }
        if (!$gate) {
            T::same([], $w->access->readableForumIds(null), $label . ': readableForumIds(Gast) ist leer, auch für Foren ohne Zeilen');
            T::same([], $w->access->filterReadable(null, array_values($w->f)), $label . ': filterReadable(Gast) ist leer');
        }
        // Mitglieder sind vom Tor nicht betroffen: die Gast-Zeile gilt für Angemeldete
        T::same([$pub], $w->access->filterReadable($w->u['m1'], [$pub]), $label . ': Mitglied liest das Forum mit Gast-Zeile');
        T::same('ok', $w->cell('m1', 'read', ['forum_id' => $pub]), $label . ': dto. über can()');
    }
});

T::run('Mengen-API: wenige Abfragen ohne Rechte-Zeilen, Ergebnis wie can(read) mit und ohne Zeilen (B31)', static function (): void {
    $build = static function (int $roots, int $children, bool $rows): AcWorld {
        $w = acStandardWorld(['guests_can_read' => true]);
        $w->g['t2'] = $w->db->group('Zweite');
        $w->member('batch', ['posts' => 5, 'groups' => [$w->g['t2']]]);
        for ($r = 0; $r < $roots; $r++) {
            $w->forum('R' . $r);
            for ($c = 0; $c < $children; $c++) {
                $w->forum('R' . $r . 'C' . $c, ['parent' => 'R' . $r]);
            }
            if ($rows && $r % 3 === 0) {
                $w->permissions('R' . $r, [
                    ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1]],
                    ['subject_type' => 'group', 'subject_key' => (string) $w->g['t2'], 'permissions' => ['read' => 1]],
                ]);
            }
            if ($rows && $r % 3 === 1) {
                $w->permissions('R' . $r, [['subject_type' => 'trust', 'subject_key' => '3', 'permissions' => ['read' => 1]]]);
            }
        }
        if ($rows) {
            $w->permissions('R0C0', [['subject_type' => 'role', 'subject_key' => 'editor', 'permissions' => ['read' => 1]]]);
        }
        $w->access->flush();

        return $w;
    };
    foreach ([false, true] as $rows) {
        $w = $build(10, 5, $rows);
        $all = array_keys((new ForumRepository($w->db))->tree());
        $label = ($rows ? 'mit' : 'ohne') . ' Rechte-Zeilen in den Neuanlagen, ' . count($all) . ' Foren';
        foreach (['guest', 'm0', 'm1', 'm3', 'editor', 'batch', 'gm', 'fm', 'admin', 'nc', 'bf'] as $name) {
            $fromCan = [];
            foreach ($all as $forumId) {
                if ($w->can($name, 'read', ['forum_id' => $forumId])->allowed) {
                    $fromCan[] = $forumId;
                }
            }
            $w->access->flush();
            T::same($fromCan, $w->access->readableForumIds($w->u[$name]), $label . ', ' . $name . ': readableForumIds = can(read) je Forum');
            T::same($fromCan, $w->access->filterReadable($w->u[$name], $all), $label . ', ' . $name . ': filterReadable = can(read) je Forum');
        }
        // Abfragen: die Mengen-API liest die Rechte-Zeilen aller Foren in einer Abfrage (PR #2b, Grenze im Abschnitt „höchstens zehn Abfragen“)
        $w->access->flush();
        $w->db->log = [];
        $ids = $w->access->readableForumIds($w->u['batch']);
        $queries = count($w->db->log);
        $w->access->flush();
        $w->db->log = [];
        $w->access->filterReadable($w->u['batch'], $all);
        T::same($queries, count($w->db->log), $label . ': readableForumIds und filterReadable kosten gleich viele Abfragen');
        T::check($queries < 3 * count($all), $label . ': weniger als drei Abfragen je Forum (' . $queries . ' bei ' . count($all) . ' Foren)');
        T::info($label . ': ' . $queries . ' Abfragen (' . count($ids) . ' lesbar)');
        // Globale Moderation und admin sehen jedes Forum: keine Matrix- und keine Moderatorenabfrage je Forum
        foreach (['gm', 'admin'] as $moderator) {
            $w->access->flush();
            $w->db->log = [];
            $seen = $w->access->readableForumIds($w->u[$moderator]);
            T::same($all, $seen, $label . ', ' . $moderator . ': alle Foren lesbar');
            T::check(count($w->db->log) <= 6, $label . ', ' . $moderator . ': höchstens 6 Abfragen unabhängig von der Forenzahl (' . count($w->db->log) . ')', implode(' | ', array_slice($w->db->log, 0, 8)));
        }
    }

    // Ganz ohne Rechte-Zeilen: eine Abfrage für alle Foren, nicht eine je Forum
    $bare = new AcWorld(['guests_can_read' => true]);
    $bare->member('plain', ['posts' => 5]);
    $bare->member('plain2', ['posts' => 5]);
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $bare->forum('B' . $i);
        $ids[] = $bare->f['B' . $i];
        for ($c = 0; $c < 12; $c++) {
            $bare->forum('B' . $i . 'c' . $c, ['parent' => 'B' . $i]);
            $ids[] = $bare->f['B' . $i . 'c' . $c];
        }
    }
    $bare->member('gm', ['role' => 'moderator', 'posts' => 5]);
    $bare->access->flush();
    $bare->db->log = [];
    $readable = $bare->access->readableForumIds($bare->u['plain']);
    $few = count($bare->db->log);
    $sorted = static function (array $list): array {
        sort($list);

        return $list;
    };
    T::same($sorted($ids), $sorted($readable), 'ohne Rechte-Zeilen: alle ' . count($ids) . ' Foren lesbar (Standards)');
    T::check($few <= 8, 'ohne Rechte-Zeilen: höchstens 8 Abfragen für ' . count($ids) . ' Foren, unabhängig von ihrer Zahl (' . $few . ')', implode(' | ', array_slice($bare->db->log, 0, 12)));
    $bare->access->flush();
    $bare->db->log = [];
    $bare->access->filterReadable(null, $ids);
    T::check(count($bare->db->log) <= 6, 'ohne Rechte-Zeilen: Gast, höchstens 6 Abfragen (' . count($bare->db->log) . ')');
    T::info('ohne Rechte-Zeilen, ' . count($ids) . ' Foren: ' . $few . ' Abfragen (Mitglied), ' . count($bare->db->log) . ' (Gast)');
    // Eine einzige Rechte-Zeile irgendwo: das Ergebnis bleibt richtig (B1 und seine Unterforen erben die Whitelist)
    $bare->permissions('B1', [['subject_type' => 'role', 'subject_key' => 'editor', 'permissions' => ['read' => 1]]]);
    $hidden = [$bare->f['B1']];
    for ($c = 0; $c < 12; $c++) {
        $hidden[] = $bare->f['B1c' . $c];
    }
    $expected = array_values(array_diff($ids, $hidden));
    T::same($sorted($expected), $sorted($bare->access->readableForumIds($bare->u['plain'])), 'eine Rechte-Zeile bei B1 (nur editor liest): B1 und seine Unterforen fehlen, die übrigen bleiben');
    T::same($sorted($ids), $sorted($bare->access->readableForumIds($bare->u['gm'])), 'globale Moderation liest weiter alle');
    foreach ($ids as $forumId) {
        T::same(in_array($forumId, $expected, true), $bare->can('plain', 'read', ['forum_id' => $forumId])->allowed, 'can(read) stimmt mit der Menge überein: Forum ' . $forumId);
    }
});

// ── Grenzen: Abstände, Langsamer Modus, Limits, Freigabepflicht ─────────────────────────────────────────────────

T::run('Pflichtfall: retry_after bei Abstand (rate_limited) und Langsamem Modus (slow_mode); größte Restzeit gewinnt', static function (): void {
    $w = acStandardWorld(['rate_post_seconds' => 30, 'rate_topic_seconds' => 0, 'new_member_approval_posts' => 0]);
    $w->member('flood', ['posts' => 5]);
    $w->forum('slow', ['slow_mode' => 60]);
    $w->topic('tslow', 'slow', ['by' => 'flood']);
    $w->topic('tfast', 'fo', ['by' => 'flood', 'slow_mode_seconds' => 45]);
    $w->sql('UPDATE cms_community_topics SET slow_mode_seconds = ? WHERE id = ?', [45, $w->t['tfast']]);
    $w->post('flood_slow', 'tslow', ['by' => 'flood', 'age' => 20]);
    $w->access->flush();

    // Beitrag vor 20 s im Forum mit Langsamem Modus 60 s und Abstand 30 s: Rest 40 (Modus) gegen 10 (Abstand)
    T::same('slow_mode@40', $w->cell('flood', 'reply', ['topic_id' => $w->t['tslow']]), 'Forum-Modus 60 s, Beitrag vor 20 s: slow_mode, 40 s');
    T::same('slow_mode@40', $w->cell('flood', 'create_topic', ['forum_id' => $w->f['slow']]), 'dto. beim Anlegen eines Themas');
    T::same(429, $w->can('flood', 'reply', ['topic_id' => $w->t['tslow']])->status(), 'slow_mode meldet 429');
    T::same(40, $w->can('flood', 'reply', ['topic_id' => $w->t['tslow']])->retryAfter, 'retryAfter in Sekunden');
    // Anderes Forum: nur der Abstand zwischen Beiträgen (global) wirkt: 30 s − 20 s = 10 s
    T::same('rate_limited@10', $w->cell('flood', 'reply', ['topic_id' => $w->t['t']]), 'Abstand 30 s, Beitrag vor 20 s (anderes Forum): rate_limited, 10 s');
    // Beitrag vor 50 s: Abstand ist vorbei, der Modus (60 s) nicht mehr lange
    $w->sql('UPDATE cms_community_posts SET created_at = ? WHERE id = ?', [$w->utc(-50), $w->p['flood_slow']]);
    T::same('slow_mode@10', $w->cell('flood', 'reply', ['topic_id' => $w->t['tslow']]), 'Beitrag vor 50 s: slow_mode, 10 s');
    T::same('ok:published', $w->cell('flood', 'reply', ['topic_id' => $w->t['t']]), 'Beitrag vor 50 s: außerhalb des Forums frei');
    $w->sql('UPDATE cms_community_posts SET created_at = ? WHERE id = ?', [$w->utc(-60), $w->p['flood_slow']]);
    T::same('ok:published', $w->cell('flood', 'reply', ['topic_id' => $w->t['tslow']]), 'genau 60 s: der Langsame Modus ist vorbei (Grenze erlaubt)');

    // B3/B29: ein gelöschter, abgelehnter oder ausgeblendeter Beitrag hält den Langsamen Modus weiter
    foreach (['deleted', 'rejected', 'hidden'] as $gone) {
        $w->member('slow_' . $gone, ['posts' => 5]);
        $w->post('slow_' . $gone . '_p', 'tslow', ['by' => 'slow_' . $gone, 'age' => 20, 'status' => $gone]);
        T::same('slow_mode@40', $w->cell('slow_' . $gone, 'reply', ['topic_id' => $w->t['tslow']]), 'Beitrag im Status ' . $gone . ' vor 20 s im Forum mit Modus 60 s: slow_mode, 40 s');
        T::same('rate_limited@10', $w->cell('slow_' . $gone, 'reply', ['topic_id' => $w->t['t']]), 'dto.: in einem anderen Forum gilt nur der Abstand zwischen Beiträgen (30 s, Rest 10 s)');
    }
    // Langsamer Modus des Themas
    $w->post('flood_fast', 'tfast', ['by' => 'flood', 'age' => 31]);
    T::same('slow_mode@14', $w->cell('flood', 'reply', ['topic_id' => $w->t['tfast']]), 'Themen-Modus 45 s, Beitrag vor 31 s: slow_mode, 14 s');
    T::same('ok:published', $w->cell('flood', 'reply', ['topic_id' => $w->t['t']]), 'der Themen-Modus gilt nur in diesem Thema (Abstand 30 s ist vorbei)');
    T::same('ok:published', $w->cell('m1', 'reply', ['topic_id' => $w->t['tfast']]), 'andere Mitglieder sind nicht betroffen');
    // Der Langsame Modus gilt je Forum und je Thema: ein Beitrag woanders (31 s her, der Abstand von 30 s ist vorbei) hält ihn nicht
    $w->member('sc', ['posts' => 5]);
    $w->post('sc_p', 't', ['by' => 'sc', 'age' => 31]);
    T::same('ok:published', $w->cell('sc', 'reply', ['topic_id' => $w->t['tslow']]), 'Forum-Modus 60 s: ein Beitrag vor 31 s in einem anderen Forum zählt nicht');
    T::same('ok:published', $w->cell('sc', 'create_topic', ['forum_id' => $w->f['slow']]), 'dto. beim Anlegen eines Themas im Forum mit Langsamem Modus');
    T::same('ok:published', $w->cell('sc', 'reply', ['topic_id' => $w->t['tfast']]), 'Themen-Modus 45 s: ein Beitrag vor 31 s in einem anderen Thema zählt nicht');
    $w->member('tf_gone', ['posts' => 5]);
    $w->post('tf_gone_p', 'tfast', ['by' => 'tf_gone', 'age' => 31, 'status' => 'deleted']);
    T::same('slow_mode@14', $w->cell('tf_gone', 'reply', ['topic_id' => $w->t['tfast']]), 'Themen-Modus 45 s, gelöschter Beitrag vor 31 s im Thema: slow_mode, 14 s (Löschen gibt den Modus nicht frei)');

    // bypass_slow_mode (Matrix) überspringt den Modus, aber nicht den globalen Abstand
    $w->forum('slow_b', ['slow_mode' => 600]);
    $w->permissions('slow_b', [['subject_type' => 'trust', 'subject_key' => '0', 'permissions' => ['read' => 1, 'reply' => 1, 'create_topic' => 1, 'bypass_slow_mode' => 1]]]);
    $w->topic('tslow_b', 'slow_b', ['by' => 'flood']);
    $w->post('flood_b', 'tslow_b', ['by' => 'flood', 'age' => 100]);
    T::same('ok:published', $w->cell('flood', 'reply', ['topic_id' => $w->t['tslow_b']]), 'bypass_slow_mode: Langsamer Modus (600 s) übersprungen');
    $w->sql('UPDATE cms_community_posts SET created_at = ? WHERE id = ?', [$w->utc(-10), $w->p['flood_b']]);
    T::same('rate_limited@20', $w->cell('flood', 'reply', ['topic_id' => $w->t['tslow_b']]), 'bypass_slow_mode befreit nicht vom Abstand zwischen Beiträgen');
    // Moderation hat weder Modus noch Abstand
    foreach (['gm', 'admin'] as $mod) {
        $w->member($mod . '_flood', ['role' => $mod === 'gm' ? 'moderator' : 'admin', 'posts' => 5]);
        $w->post($mod . '_p', 'tslow', ['by' => $mod . '_flood', 'age' => 1]);
        T::same('ok:published', $w->cell($mod . '_flood', 'reply', ['topic_id' => $w->t['tslow']]), $mod . ': kein Langsamer Modus, kein Abstand');
    }
    $w->moderators('slow', [['subject_type' => 'user', 'subject_id' => $w->u['flood'], 'include_children' => 0]]);
    T::same('ok:published', $w->cell('flood', 'reply', ['topic_id' => $w->t['tslow']]), 'Forum-Moderator: kein Langsamer Modus, kein Abstand im eigenen Forum');
    T::same('rate_limited@20', $w->cell('flood', 'reply', ['topic_id' => $w->t['tslow_b']]), 'Forum-Moderator in einem anderen Forum: der Abstand gilt');

    // Gleiche Restzeit: der Abstand (rate_limited) steht vor dem Langsamen Modus
    $tie = acStandardWorld(['rate_post_seconds' => 30, 'rate_topic_seconds' => 0, 'new_member_approval_posts' => 0]);
    $tie->forum('slow30', ['slow_mode' => 30]);
    $tie->topic('tslow30', 'slow30', ['by' => 'm1']);
    $tie->post('tie_post', 'tslow30', ['by' => 'm1', 'age' => 10]);
    T::same('rate_limited@20', $tie->cell('m1', 'reply', ['topic_id' => $tie->t['tslow30']]), 'gleiche Restzeit (je 20 s): rate_limited vor slow_mode');
});

T::run('Pflichtfall: Freigabepflicht (initialStatus), bypass_approval, Deckel für wartende Beiträge, Tageslimit', static function (): void {
    $w = acStandardWorld(['rate_post_seconds' => 0, 'rate_topic_seconds' => 0]);
    T::same('ok:pending', $w->cell('m0', 'reply', ['topic_id' => $w->t['t']]), 'Stufe 0 ohne Beitrag: wartend');
    T::same('ok:pending', $w->cell('m0', 'create_topic', ['forum_id' => $w->f['fo']]), 'Thema anlegen: wartend');
    T::same('ok:published', $w->cell('m1', 'reply', ['topic_id' => $w->t['t']]), 'ab zwei veröffentlichten Beiträgen: veröffentlicht');
    foreach (['gm', 'admin', 'fm'] as $mod) {
        T::same('ok:published', $w->cell($mod, 'reply', ['topic_id' => $w->t['t']]), $mod . ': Moderation ohne Freigabepflicht');
    }

    // Forum verlangt Freigabe (require_approval)
    $w->forum('fra', ['require_approval' => true]);
    $w->topic('tfra', 'fra');
    T::same('ok:pending', $w->cell('m1', 'reply', ['topic_id' => $w->t['tfra']]), 'Forum mit Freigabepflicht: auch erfahrene Mitglieder warten');
    T::same('ok:pending', $w->cell('m3', 'create_topic', ['forum_id' => $w->f['fra']]), 'dto. Stufe 3, Thema anlegen');
    T::same('ok:published', $w->cell('gm', 'reply', ['topic_id' => $w->t['tfra']]), 'globale Moderation: veröffentlicht');
    $w->moderators('fra', [['subject_type' => 'user', 'subject_id' => $w->u['fm'], 'include_children' => 0]]);
    T::same('ok:published', $w->cell('fm', 'reply', ['topic_id' => $w->t['tfra']]), 'Forum-Moderator im Forum: veröffentlicht');

    // bypass_approval über die Matrix: gilt auch gegen die Freigabepflicht des Forums
    $w->forum('frb', ['require_approval' => true]);
    $w->permissions('frb', [
        ['subject_type' => 'trust', 'subject_key' => '0', 'permissions' => ['read' => 1, 'reply' => 1, 'create_topic' => 1]],
        ['subject_type' => 'trust', 'subject_key' => '2', 'permissions' => ['bypass_approval' => 1]],
    ]);
    $w->topic('tfrb', 'frb');
    T::same('ok:pending', $w->cell('m1', 'reply', ['topic_id' => $w->t['tfrb']]), 'ohne bypass_approval: wartend');
    T::same('ok:published', $w->cell('m2', 'reply', ['topic_id' => $w->t['tfrb']]), 'trust:2 mit bypass_approval: veröffentlicht trotz Freigabepflicht des Forums');
    T::same('ok:published', $w->cell('m3', 'create_topic', ['forum_id' => $w->f['frb']]), 'dto. Thema anlegen (Stufe 3 ≥ 2)');
    $w->forum('frc');
    $w->permissions('frc', [['subject_type' => 'trust', 'subject_key' => '0', 'permissions' => ['read' => 1, 'reply' => 1, 'bypass_approval' => 1]]]);
    $w->topic('tfrc', 'frc');
    T::same('ok:published', $w->cell('m0', 'reply', ['topic_id' => $w->t['tfrc']]), 'bypass_approval hebt die Freigabepflicht neuer Mitglieder auf');

    // Deckel für wartende Beiträge
    $w->member('queue', []);
    for ($i = 0; $i < 3; $i++) {
        $w->post('q' . $i, 't', ['by' => 'queue', 'status' => 'pending', 'age' => 200000 + $i]);
    }
    T::same('pending_limit', $w->cell('queue', 'reply', ['topic_id' => $w->t['t']]), 'drei wartende Beiträge (Deckel 3): pending_limit');
    T::same('pending_limit', $w->cell('queue', 'create_topic', ['forum_id' => $w->f['fo']]), 'dto. Thema anlegen');
    T::same(403, $w->can('queue', 'reply', ['topic_id' => $w->t['t']])->status(), 'pending_limit meldet 403');
    T::same('ok:published', $w->cell('queue', 'reply', ['topic_id' => $w->t['tfrc']]), 'wo kein Warten nötig ist (bypass_approval), gilt der Deckel nicht');
    T::same('ok:published', $w->cell('gm', 'reply', ['topic_id' => $w->t['t']]), 'Moderation: kein Deckel nötig');

    // Tageslimit der Stufe (Stufe 0: 5 Beiträge je Tag), wartende und ausgeblendete zählen mit
    $w2 = acStandardWorld(['rate_post_seconds' => 0, 'rate_topic_seconds' => 0, 'new_member_approval_posts' => 0]);
    $w2->member('day', []);
    foreach (['published', 'pending', 'hidden', 'published'] as $i => $status) {
        $w2->post('d' . $i, 't', ['by' => 'day', 'status' => $status, 'age' => 7200 + $i]);
    }
    T::same('ok:published', $w2->cell('day', 'reply', ['topic_id' => $w2->t['t']]), 'vier Beiträge heute (Limit 5): erlaubt');
    $w2->post('d4', 't', ['by' => 'day', 'status' => 'deleted', 'age' => 100]);
    T::same('trust_limit', $w2->cell('day', 'reply', ['topic_id' => $w2->t['t']]), 'ein gelöschter Beitrag zählt mit: fünf Beiträge heute (davon einer gelöscht) = trust_limit (B3/B29)');
    T::same('trust_limit', $w2->cell('day', 'create_topic', ['forum_id' => $w2->f['fo']]), 'dto. beim Anlegen eines Themas');
    T::same('ok:published', $w2->cell('m1', 'reply', ['topic_id' => $w2->t['t']]), 'andere Mitglieder sind nicht betroffen');
    // Ein abgelehnter Beitrag verbraucht das Kontingent ebenso (er wurde angelegt)
    $w3 = acStandardWorld(['rate_post_seconds' => 0, 'rate_topic_seconds' => 0, 'new_member_approval_posts' => 0]);
    $w3->member('day2', []);
    foreach (['published', 'published', 'published', 'published', 'rejected'] as $i => $status) {
        $w3->post('e' . $i, 't', ['by' => 'day2', 'status' => $status, 'age' => 7200 + $i]);
    }
    T::same('trust_limit', $w3->cell('day2', 'reply', ['topic_id' => $w3->t['t']]), 'ein abgelehnter Beitrag zählt mit (Limit 5)');
});

// ── Wirkung je Einstellung ──────────────────────────────────────────────────────────────────────────────────────

T::run('Wirkung: guests_can_read', static function (): void {
    T::same(false, Settings::DEFINITIONS['guests_can_read']['default'], 'Standard: Gäste dürfen nicht lesen');
    $default = acStandardWorld(['guests_can_read' => 'unbrauchbar']);
    $off = acStandardWorld(['guests_can_read' => false]);
    $on = acStandardWorld(['guests_can_read' => true]);
    foreach ([['Standard (ungültiger Wert)', $default], ['aus', $off]] as [$label, $w]) {
        T::same('not_found', $w->cell('guest', 'read', ['forum_id' => $w->f['fo']]), $label . ': Gast liest kein Forum');
        T::same('not_found', $w->cell('guest', 'read', ['topic_id' => $w->t['t']]), $label . ': Gast liest kein Thema');
        T::same('not_found', $w->cell('guest', 'read', AC_GLOBAL), $label . ': Gast darf die Community nicht betreten');
        T::same('no_capability', $w->cell('guest', 'search', AC_GLOBAL), $label . ': Gast sucht nicht');
        T::same('login_required', $w->cell('guest', 'reply', ['topic_id' => $w->t['t']]), $label . ': Schreibaktionen bleiben login_required');
        T::same([], $w->access->filterReadable(null, [$w->f['fo']]), $label . ': keine lesbaren Foren');
        T::same([], $w->access->readableForumIds(null), $label . ': readableForumIds leer');
        T::same('ok', $w->cell('m1', 'read', ['topic_id' => $w->t['t']]), $label . ': Mitglieder lesen weiter');
        T::same('ok', $w->cell('admin', 'read', ['topic_id' => $w->t['thf']]), $label . ': admin liest weiter');
    }
    T::same('ok', $on->cell('guest', 'read', ['forum_id' => $on->f['fo']]), 'an: Gast liest ein Forum ohne Zeilen (Standard guest.read)');
    T::same('ok', $on->cell('guest', 'read', ['topic_id' => $on->t['t']]), 'an: Gast liest ein Thema');
    T::same('ok:full', $on->cell('guest', 'read', ['post_id' => $on->p['p']]), 'an: Gast liest einen Beitrag');
    T::same('ok', $on->cell('guest', 'read', AC_GLOBAL), 'an: Gast darf die Community betreten');
    T::same('ok', $on->cell('guest', 'search', AC_GLOBAL), 'an: Gast sucht');
    T::same('not_found', $on->cell('guest', 'read', ['forum_id' => $on->f['fi']]), 'an: ein Forum mit Rechte-Zeilen ohne Gast-read bleibt zu');
    T::same('not_found', $on->cell('guest', 'read', ['forum_id' => $on->f['fh']]), 'an: ein verborgenes Forum bleibt zu');
    T::same('not_found', $on->cell('guest', 'read', ['topic_id' => $on->t['tp']]), 'an: ein wartendes Thema bleibt zu');
    T::same('login_required', $on->cell('guest', 'reply', ['topic_id' => $on->t['t']]), 'an: Schreiben bleibt login_required');
    T::same([$on->f['fo'], $on->f['fr'], $on->f['fa'], $on->f['fl'], $on->f['fn'], $on->f['fq']], $on->access->filterReadable(null, array_values($on->f)), 'an: filterReadable liefert die offenen Foren');

    // Das Tor gilt zusätzlich zur Matrix: Gast-read in den Zeilen öffnet bei geschlossenem Tor nichts
    foreach ([[true, 'ok'], [false, 'not_found']] as [$gate, $expected]) {
        $w = acStandardWorld(['guests_can_read' => $gate]);
        $w->forum('pub');
        $w->permissions('pub', [['subject_type' => 'guest', 'permissions' => ['read' => 1]]]);
        T::same($expected, $w->cell('guest', 'read', ['forum_id' => $w->f['pub']]), 'Gast-Zeile mit read = 1, Tor ' . ($gate ? 'offen' : 'zu'));
    }
    // Das Tor gilt nicht für das nicht aktive Konto: es ist ein Gast
    T::same('ok', $on->cell('inactive', 'read', ['forum_id' => $on->f['fo']]), 'an: nicht aktives Konto liest wie ein Gast');
    T::same('not_found', $off->cell('inactive', 'read', ['forum_id' => $off->f['fo']]), 'aus: nicht aktives Konto liest nicht');
});

T::run('Wirkung: require_verified_email', static function (): void {
    T::same(true, Settings::DEFINITIONS['require_verified_email']['default'], 'Standard: an');
    $w = acStandardWorld();
    $reply = ['topic_id' => $w->t['t']];
    T::same('email_unverified', $w->cell('unverified', 'reply', $reply), 'an: unbestätigtes Mitglied schreibt nicht');
    T::same('email_unverified', $w->cell('unverified', 'create_topic', ['forum_id' => $w->f['fo']]), 'an: auch kein neues Thema');
    T::same('email_unverified', $w->cell('unverified', 'report', ['post_id' => $w->p['p']]), 'an: auch kein Melden');
    T::same('email_unverified', $w->cell('unverified', 'topic.lock', ['topic_id' => $w->t['t']]), 'an: auch keine Moderationsaktion');
    T::same('ok', $w->cell('unverified', 'read', ['topic_id' => $w->t['t']]), 'an: Lesen bleibt möglich');
    T::same('ok', $w->cell('unverified', 'search', AC_GLOBAL), 'an: Suchen bleibt möglich');
    T::same(403, $w->can('unverified', 'reply', $reply)->status(), 'email_unverified meldet 403');
    // admin ist ausgenommen, auch ohne bestätigte Adresse
    $w->verified[$w->u['admin']] = false;
    $w->access->flush();
    T::same('ok:published', $w->cell('admin', 'reply', $reply), 'admin ohne bestätigte E-Mail-Adresse schreibt');
    $w->verified[$w->u['gm']] = false;
    $w->access->flush();
    T::same('email_unverified', $w->cell('gm', 'reply', $reply), 'globale Moderation ist nicht ausgenommen');

    // Ohne Prüfung: unbestätigte schreiben, bleiben aber auf Stufe 0
    $off = acStandardWorld(['require_verified_email' => false]);
    T::same('ok:published', $off->cell('unverified', 'reply', ['topic_id' => $off->t['t']]), 'aus: unbestätigtes Mitglied schreibt');
    T::same('ok:published', $off->cell('unverified', 'create_topic', ['forum_id' => $off->f['fo']]), 'aus: auch neue Themen');
    T::same(0, $off->access->limitsFor($off->u['unverified'])['level'], 'ohne Bestätigung nie über Stufe 0, auch mit 5 Beiträgen');
    T::same(1, $off->access->limitsFor($off->u['m1'])['level'], 'bestätigt mit 5 Beiträgen: Stufe 1');

    // Fail-closed: ist der Bestätigungsdienst nicht erreichbar, gilt die Adresse als unbestätigt
    $failing = acStandardWorld();
    $failing->verifyFails = true;
    T::same('email_unverified', $failing->cell('m1', 'reply', ['topic_id' => $failing->t['t']]), 'Ausnahme beim Prüfen der Bestätigung: abgelehnt (fail-closed)');
    T::same('ok:published', $failing->cell('admin', 'reply', ['topic_id' => $failing->t['t']]), 'admin braucht die Prüfung nicht');
    $failing->verifyFails = false;
    $failing->access->flush();
    T::same('ok:published', $failing->cell('m1', 'reply', ['topic_id' => $failing->t['t']]), 'Dienst wieder erreichbar');
});

T::run('Wirkung: edit_window_minutes', static function (): void {
    T::same(30, Settings::DEFINITIONS['edit_window_minutes']['default'], 'Standard: 30 Minuten');
    // [Fenster in Minuten, Alter des Beitrags in Sekunden, erwartet]
    $cases = [
        [30, 29 * 60, 'ok'], [30, 30 * 60, 'ok'], [30, 30 * 60 + 1, 'edit_window'], [30, 31 * 60, 'edit_window'], [30, 3 * 3600, 'edit_window'],
        [60, 59 * 60, 'ok'], [60, 61 * 60, 'edit_window'],
        [1, 59, 'ok'], [1, 61, 'edit_window'],
        [0, 10 * 86400, 'ok'], [0, 400 * 86400, 'ok'],
    ];
    foreach ($cases as [$minutes, $age, $expected]) {
        $w = acStandardWorld(['edit_window_minutes' => $minutes, 'default_permissions' => ['member' => ['delete_own' => 1]]]);
        $id = $w->post('fenster', 't', ['age' => $age]);
        $label = 'Fenster ' . $minutes . ' min, Beitrag ' . $age . ' s alt';
        T::same($expected, $w->cell('own', 'edit', ['post_id' => $id]), $label . ': Bearbeiten');
        T::same($expected, $w->cell('own', 'delete', ['post_id' => $id]), $label . ': Löschen gilt dasselbe Fenster');
        T::same('ok', $w->cell('fm', 'edit', ['post_id' => $id]), $label . ': Forum-Moderator ohne Fenster');
        T::same('ok', $w->cell('gm', 'edit', ['post_id' => $id]), $label . ': globale Moderation ohne Fenster');
        T::same('ok', $w->cell('admin', 'delete', ['post_id' => $id]), $label . ': admin ohne Fenster');
    }
    // Fail-closed: ohne gültiges created_at (leer, fehlt, kein Datum) gilt das Fenster als abgelaufen
    $w = acStandardWorld(['edit_window_minutes' => 30, 'default_permissions' => ['member' => ['delete_own' => 1]]]);
    $row = (array) (new PostRepository($w->db))->find($w->p['p']);
    foreach ([null, '', 'kein Datum', '2026-13-45 99:99:99', '2026-11-15'] as $broken) {
        $label = 'created_at ' . T::export($broken);
        T::same('edit_window', acCell($w->access->can($w->u['own'], 'edit', ['post_id' => $w->p['p'], 'post' => ['created_at' => $broken] + $row, 'now' => $w->now])), $label . ': Bearbeiten abgelaufen');
        T::same('edit_window', acCell($w->access->can($w->u['own'], 'delete', ['post_id' => $w->p['p'], 'post' => ['created_at' => $broken] + $row, 'now' => $w->now])), $label . ': Löschen abgelaufen');
        T::same('ok', acCell($w->access->can($w->u['gm'], 'edit', ['post_id' => $w->p['p'], 'post' => ['created_at' => $broken] + $row, 'now' => $w->now])), $label . ': Moderation hat kein Fenster');
    }
    T::same('ok', acCell($w->access->can($w->u['own'], 'edit', ['post_id' => $w->p['p'], 'post' => $row, 'now' => $w->now])), 'Gegenprobe: gültiges created_at, im Fenster');
    $unlimited = acStandardWorld(['edit_window_minutes' => 0]);
    $row0 = (array) (new PostRepository($unlimited->db))->find($unlimited->p['p']);
    T::same('ok', acCell($unlimited->access->can($unlimited->u['own'], 'edit', ['post_id' => $unlimited->p['p'], 'post' => ['created_at' => null] + $row0, 'now' => $unlimited->now])), 'Fenster 0 (unbegrenzt): auch ohne created_at erlaubt');

    $w = acStandardWorld(['edit_window_minutes' => 30]);
    // Erster Beitrag: der Titel gehört zum Fenster des Beitrags
    $w->post('erster_alt', 't', ['first' => true, 'age' => 31 * 60]);
    T::same('edit_window', $w->cell('own', 'edit', ['post_id' => $w->p['erster_alt']]), 'erster Beitrag nach 31 min: Titel und Text nicht mehr bearbeitbar');
    // Das Fenster der Stufe 3 ist ein anderes: edit_window_minutes gilt nicht für Stufe 3
    $w->post('m3_alt', 't', ['by' => 'm3', 'age' => 3 * 3600]);
    T::same('ok', $w->cell('m3', 'edit', ['post_id' => $w->p['m3_alt']]), 'Stufe 3: das normale Fenster gilt nicht (siehe edit_window_minutes_trusted)');
    // Die Zeit ist UTC: Beitrag nach Ortszeit Europe/Berlin (UTC+1) um eine Stunde verschoben wäre ein Fehler
    T::same('Europe/Berlin', date_default_timezone_get(), 'PHP-Zone der Suite: Europe/Berlin (Ortszeit ≠ UTC)');
    $w->post('knapp', 't', ['age' => 29 * 60]);
    T::same('ok', $w->cell('own', 'edit', ['post_id' => $w->p['knapp']]), 'UTC: 29 Minuten alt liegt im Fenster, nicht um die Zonenverschiebung falsch');
    $w->post('knapp2', 't', ['age' => 31 * 60]);
    T::same('edit_window', $w->cell('own', 'edit', ['post_id' => $w->p['knapp2']]), 'UTC: 31 Minuten alt liegt außerhalb');
    // limitsFor nennt das Fenster
    T::same(30, $w->access->limitsFor($w->u['own'])['edit_window_minutes'], 'limitsFor: Fenster der Stufe 1');
    T::same(0, acStandardWorld(['edit_window_minutes' => 0])->access->limitsFor(null)['edit_window_minutes'], 'limitsFor: 0 = unbegrenzt');
});

T::run('Wirkung: edit_window_minutes_trusted', static function (): void {
    T::same(1440, Settings::DEFINITIONS['edit_window_minutes_trusted']['default'], 'Standard: 1440 Minuten (24 Stunden)');
    $w = acStandardWorld(['edit_window_minutes' => 30, 'edit_window_minutes_trusted' => 1440]);
    $w->post('m3_2h', 't', ['by' => 'm3', 'age' => 2 * 3600]);
    $w->post('m3_25h', 't', ['by' => 'm3', 'age' => 25 * 3600]);
    $w->post('m2_2h', 't', ['by' => 'm2', 'age' => 2 * 3600]);
    $w->post('own_2h', 't', ['by' => 'own', 'age' => 2 * 3600]);
    T::same('ok', $w->cell('m3', 'edit', ['post_id' => $w->p['m3_2h']]), 'Stufe 3: Beitrag nach 2 Stunden bearbeitbar');
    T::same('edit_window', $w->cell('m3', 'edit', ['post_id' => $w->p['m3_25h']]), 'Stufe 3: nach 25 Stunden nicht mehr');
    T::same('edit_window', $w->cell('m2', 'edit', ['post_id' => $w->p['m2_2h']]), 'Stufe 2: das große Fenster gilt nicht');
    T::same('edit_window', $w->cell('own', 'edit', ['post_id' => $w->p['own_2h']]), 'Stufe 1: das große Fenster gilt nicht');
    T::same(1440, $w->access->limitsFor($w->u['m3'])['edit_window_minutes'], 'limitsFor Stufe 3: vertrautes Fenster');
    T::same(30, $w->access->limitsFor($w->u['m2'])['edit_window_minutes'], 'limitsFor Stufe 2: normales Fenster');

    $short = acStandardWorld(['edit_window_minutes_trusted' => 60]);
    $short->post('a', 't', ['by' => 'm3', 'age' => 59 * 60]);
    $short->post('b', 't', ['by' => 'm3', 'age' => 61 * 60]);
    T::same('ok', $short->cell('m3', 'edit', ['post_id' => $short->p['a']]), 'vertrautes Fenster 60 min: 59 min ok');
    T::same('edit_window', $short->cell('m3', 'edit', ['post_id' => $short->p['b']]), 'vertrautes Fenster 60 min: 61 min nicht mehr');

    $unlimited = acStandardWorld(['edit_window_minutes_trusted' => 0]);
    $unlimited->post('alt', 't', ['by' => 'm3', 'age' => 100 * 86400]);
    $unlimited->post('alt1', 't', ['by' => 'own', 'age' => 100 * 86400]);
    T::same('ok', $unlimited->cell('m3', 'edit', ['post_id' => $unlimited->p['alt']]), 'vertrautes Fenster 0: Stufe 3 unbegrenzt');
    T::same('edit_window', $unlimited->cell('own', 'edit', ['post_id' => $unlimited->p['alt1']]), 'vertrautes Fenster 0: Stufe 1 weiter im normalen Fenster');
    T::same(0, $unlimited->access->limitsFor($unlimited->u['m3'])['edit_window_minutes'], 'limitsFor Stufe 3: 0 = unbegrenzt');

    // Die Stufe kommt aus der Regel: eine gespeicherte, nicht festgesetzte Stufe 3 genügt
    $w->member('m3_unlocked', ['posts' => 100, 'level' => 3]);
    $w->post('u3', 't', ['by' => 'm3_unlocked', 'age' => 2 * 3600]);
    T::same('ok', $w->cell('m3_unlocked', 'edit', ['post_id' => $w->p['u3']]), 'gespeicherte Stufe 3 (nicht festgesetzt) zählt');
    // Stufe 4 wie 3
    $w->member('m4', ['posts' => 100, 'level' => 4, 'locked' => true]);
    $w->post('u4', 't', ['by' => 'm4', 'age' => 2 * 3600]);
    T::same('ok', $w->cell('m4', 'edit', ['post_id' => $w->p['u4']]), 'Stufe 4 wie Stufe 3');
});

T::run('Wirkung: rate_post_seconds', static function (): void {
    T::same(30, Settings::DEFINITIONS['rate_post_seconds']['default'], 'Standard: 30 Sekunden');
    $make = static function (int $seconds): AcWorld {
        $w = acStandardWorld(['rate_post_seconds' => $seconds, 'rate_topic_seconds' => 0, 'new_member_approval_posts' => 0]);
        $w->post('juengst', 't', ['by' => 'm1', 'age' => 10]);
        $w->sql('UPDATE cms_community_user_stats SET last_post_at = ? WHERE user_id = ?', [$w->utc(-10), $w->u['m1']]);
        $w->access->flush();

        return $w;
    };
    $reply = static fn (AcWorld $w): array => ['topic_id' => $w->t['t']];
    $w = $make(30);
    T::same('rate_limited@20', $w->cell('m1', 'reply', $reply($w)), '30 s Abstand, letzter Beitrag vor 10 s: rate_limited, Rest 20 s');
    T::same('rate_limited@20', $w->cell('m1', 'create_topic', ['forum_id' => $w->f['fo']]), 'der Abstand gilt auch für neue Themen (Themenstart = Beitrag)');
    T::same(429, $w->can('m1', 'reply', $reply($w))->status(), 'rate_limited meldet 429');
    T::same('ok', $w->cell('m1', 'edit', ['post_id' => $w->p['juengst']]), 'Bearbeiten unterliegt keinem Abstand');
    T::same('ok:published', $w->cell('m2', 'reply', $reply($w)), 'andere Mitglieder sind nicht betroffen');
    foreach (['gm', 'admin'] as $mod) {
        $w->member('r_' . $mod, ['role' => $mod === 'gm' ? 'moderator' : 'admin', 'posts' => 5]);
        $w->post('r_' . $mod . '_p', 't', ['by' => 'r_' . $mod, 'age' => 1]);
        T::same('ok:published', $w->cell('r_' . $mod, 'reply', $reply($w)), $mod . ': Moderation ohne Abstand');
    }
    T::same('ok:published', $make(10)->cell('m1', 'reply', $reply($make(10))), '10 s Abstand, letzter Beitrag vor 10 s: genau die Grenze, erlaubt');
    $w11 = $make(11);
    T::same('rate_limited@1', $w11->cell('m1', 'reply', $reply($w11)), '11 s Abstand: noch 1 s');
    $w0 = $make(0);
    T::same('ok:published', $w0->cell('m1', 'reply', $reply($w0)), '0 = aus');
    $w3600 = $make(3600);
    T::same('rate_limited@3590', $w3600->cell('m1', 'reply', $reply($w3600)), '3600 s Abstand: Rest 3590 s');

    // Quelle Beitragstabelle allein (Statistik ohne last_post_at)
    $w = $make(30);
    $w->post('nur_tabelle', 't', ['by' => 'm2', 'age' => 10]);
    T::same('rate_limited@20', $w->cell('m2', 'reply', $reply($w)), 'ohne last_post_at: der Beitrag in der Tabelle genügt');
    // B3/B29: auch ohne Zeile in der Statistik hält ein gelöschter, abgelehnter oder ausgeblendeter Beitrag den Abstand
    foreach (['deleted', 'rejected', 'hidden'] as $gone) {
        $w->member('t_' . $gone, ['posts' => 5]);
        $w->post('t_' . $gone . '_p', 't', ['by' => 't_' . $gone, 'age' => 10, 'status' => $gone]);
        T::same('rate_limited@20', $w->cell('t_' . $gone, 'reply', $reply($w)), 'Beitrag im Status ' . $gone . ' vor 10 s (nur die Beitragstabelle kennt ihn): der Abstand gilt');
    }
    // Quelle Statistik allein (der Beitrag wurde gelöscht: kein Umgehen des Abstands durch Löschen)
    $w->member('del', ['posts' => 5]);
    $w->post('geloescht', 't', ['by' => 'del', 'age' => 10, 'status' => 'deleted']);
    $w->sql('UPDATE cms_community_user_stats SET last_post_at = ? WHERE user_id = ?', [$w->utc(-10), $w->u['del']]);
    $w->access->flush();
    T::same('rate_limited@20', $w->cell('del', 'reply', $reply($w)), 'gelöschter Beitrag: last_post_at der Statistik hält den Abstand');
    // Eine Uhrabweichung (Zeitpunkt in der Zukunft) sperrt höchstens um die volle Grenze
    $w->sql('UPDATE cms_community_user_stats SET last_post_at = ? WHERE user_id = ?', [$w->utc(86400), $w->u['del']]);
    $w->access->flush();
    T::same('rate_limited@30', $w->cell('del', 'reply', $reply($w)), 'last_post_at in der Zukunft: höchstens die volle Grenze');
    // Zeit aus dem Kontext: nach 20 weiteren Sekunden ist der Abstand vorbei
    $w = $make(30);
    T::same('ok:published', acCell($w->access->can($w->u['m1'], 'reply', $reply($w) + ['now' => $w->now + 20])), '20 s später: Abstand vorbei');
});

T::run('Wirkung: rate_topic_seconds', static function (): void {
    T::same(120, Settings::DEFINITIONS['rate_topic_seconds']['default'], 'Standard: 120 Sekunden');
    $make = static function (int $seconds, string $status = 'published', int $age = 60): AcWorld {
        $w = acStandardWorld(['rate_topic_seconds' => $seconds, 'rate_post_seconds' => 0, 'new_member_approval_posts' => 0]);
        $w->topic('juengst', 'fq', ['by' => 'm1', 'age' => $age, 'activity' => $age, 'status' => $status]);
        $w->access->flush();

        return $w;
    };
    $create = static fn (AcWorld $w): array => ['forum_id' => $w->f['fo']];
    $w = $make(120);
    T::same('rate_limited@60', $w->cell('m1', 'create_topic', $create($w)), '120 s Abstand, letztes Thema vor 60 s (in einem anderen Forum): rate_limited, Rest 60 s');
    T::same('ok:published', $w->cell('m1', 'reply', ['topic_id' => $w->t['t']]), 'Antworten unterliegen dem Themenabstand nicht');
    T::same('ok:published', $w->cell('m2', 'create_topic', $create($w)), 'andere Mitglieder sind nicht betroffen');
    T::same('ok:published', $w->cell('gm', 'create_topic', $create($w)), 'Moderation ohne Abstand');
    $b = $make(60);
    T::same('ok:published', $b->cell('m1', 'create_topic', $create($b)), '60 s Abstand, Thema vor 60 s: genau die Grenze, erlaubt');
    $c = $make(61);
    T::same('rate_limited@1', $c->cell('m1', 'create_topic', $create($c)), '61 s Abstand: noch 1 s');
    $d = $make(0);
    T::same('ok:published', $d->cell('m1', 'create_topic', $create($d)), '0 = aus');
    $e = $make(86400);
    T::same('rate_limited@86340', $e->cell('m1', 'create_topic', $create($e)), '86400 s Abstand: Rest 86340 s');
    $hidden = $make(120, 'hidden');
    T::same('rate_limited@60', $hidden->cell('m1', 'create_topic', $create($hidden)), 'ein ausgeblendetes Thema zählt');
    $pending = $make(120, 'pending');
    T::same('rate_limited@60', $pending->cell('m1', 'create_topic', $create($pending)), 'ein wartendes Thema zählt');
    // B3/B29: Löschen gibt den Abstand nicht frei (eigenes Löschen und Löschen durch die Moderation, auch im Papierkorb)
    foreach (['deleted', 'trash'] as $gone) {
        $deleted = $make(120, $gone);
        T::same('rate_limited@60', $deleted->cell('m1', 'create_topic', $create($deleted)), 'ein Thema im Status ' . $gone . ' zählt weiter (Löschen gibt den Abstand nicht frei)');
    }
    $moved = $make(120, 'moved');
    T::same('ok:published', $moved->cell('m1', 'create_topic', $create($moved)), 'der Verweis auf ein verschobenes Thema (moved) ist kein neues Thema und zählt nicht');
    $goneOld = $make(120, 'deleted', 121);
    T::same('ok:published', $goneOld->cell('m1', 'create_topic', $create($goneOld)), 'ein gelöschtes Thema vor 121 s: Abstand vorbei');
    $old = $make(120, 'published', 121);
    T::same('ok:published', $old->cell('m1', 'create_topic', $create($old)), 'Thema vor 121 s: Abstand vorbei');
    // Die Restzeit ist die größte aus Themenabstand und Beitragsabstand
    $both = acStandardWorld(['rate_topic_seconds' => 120, 'rate_post_seconds' => 30, 'new_member_approval_posts' => 0]);
    $both->topic('x', 'fq', ['by' => 'm1', 'age' => 20, 'activity' => 20]);
    $both->sql('UPDATE cms_community_user_stats SET last_post_at = ? WHERE user_id = ?', [$both->utc(-20), $both->u['m1']]);
    $both->access->flush();
    T::same('rate_limited@100', $both->cell('m1', 'create_topic', ['forum_id' => $both->f['fo']]), 'Themenabstand (100 s Rest) gewinnt gegen den Beitragsabstand (10 s)');
});

T::run('Wirkung: new_member_approval_posts', static function (): void {
    T::same(2, Settings::DEFINITIONS['new_member_approval_posts']['default'], 'Standard: 2 Beiträge');
    $make = static function (int $needed): AcWorld {
        $w = acStandardWorld(['new_member_approval_posts' => $needed, 'rate_post_seconds' => 0, 'rate_topic_seconds' => 0]);
        foreach ([1, 2, 4, 5] as $count) {
            $w->member('a' . $count, ['posts' => $count]);
        }

        return $w;
    };
    $reply = static fn (AcWorld $w): array => ['topic_id' => $w->t['t']];
    $w = $make(2);
    T::same('ok:pending', $w->cell('m0', 'reply', $reply($w)), 'Schwelle 2: Konto ohne Statistik-Zeile (0 Beiträge) wartet');
    T::same('ok:pending', $w->cell('a1', 'reply', $reply($w)), 'Schwelle 2: ein veröffentlichter Beitrag wartet');
    T::same('ok:published', $w->cell('a2', 'reply', $reply($w)), 'Schwelle 2: zwei veröffentlichte Beiträge genügen');
    T::same('ok:published', $w->cell('a5', 'reply', $reply($w)), 'Schwelle 2: fünf Beiträge');
    T::same('ok:pending', $w->cell('a1', 'create_topic', ['forum_id' => $w->f['fo']]), 'Schwelle 2: Thema anlegen wartet ebenso');
    T::same('ok:published', $make(0)->cell('m0', 'reply', $reply($make(0))), 'Schwelle 0: Freigabepflicht aus');
    $five = $make(5);
    T::same('ok:pending', $five->cell('a4', 'reply', $reply($five)), 'Schwelle 5: vier Beiträge warten');
    T::same('ok:published', $five->cell('a5', 'reply', $reply($five)), 'Schwelle 5: fünf Beiträge genügen');
    T::same('ok:published', $five->cell('gm', 'reply', $reply($five)), 'Moderation wartet nie');
    // Stufe und Freigabepflicht sind unabhängig: Stufe 3 (festgesetzt) mit wenigen Beiträgen wartet trotzdem
    $five->member('lvl3_few', ['posts' => 1, 'level' => 3, 'locked' => true]);
    T::same('ok:pending', $five->cell('lvl3_few', 'reply', $reply($five)), 'die Freigabepflicht hängt an der Beitragszahl, nicht an der Stufe');
    // Wartende Beiträge zählen nicht als veröffentlicht
    $w->member('pend1', ['posts' => 1]);
    $w->post('pe0', 't', ['by' => 'pend1', 'status' => 'pending', 'age' => 200000]);
    $w->post('pe1', 't', ['by' => 'pend1', 'status' => 'pending', 'age' => 200001]);
    T::same('ok:pending', $w->cell('pend1', 'reply', $reply($w)), 'ein veröffentlichter und zwei wartende Beiträge: noch Freigabepflicht (nur veröffentlichte zählen)');
    // bypass_approval über die Matrix und Freigabepflicht des Forums
    $w->forum('mit_bypass');
    $w->permissions('mit_bypass', [['subject_type' => 'trust', 'subject_key' => '0', 'permissions' => ['read' => 1, 'reply' => 1, 'bypass_approval' => 1]]]);
    $w->topic('tb', 'mit_bypass');
    T::same('ok:published', $w->cell('m0', 'reply', ['topic_id' => $w->t['tb']]), 'bypass_approval: neue Mitglieder veröffentlichen sofort');
});

T::run('Wirkung: max_pending_per_user', static function (): void {
    T::same(3, Settings::DEFINITIONS['max_pending_per_user']['default'], 'Standard: 3 wartende Beiträge');
    $make = static function (int $cap, int $pending, array $others = []): AcWorld {
        $w = acStandardWorld(['max_pending_per_user' => $cap, 'new_member_approval_posts' => 2, 'rate_post_seconds' => 0, 'rate_topic_seconds' => 0, 'trust_limits' => [0 => ['posts_per_day' => -1, 'topics_per_hour' => -1]]]);
        for ($i = 0; $i < $pending; $i++) {
            $w->post('wartend' . $i, 't', ['by' => 'm0', 'status' => 'pending', 'age' => 200000 + $i]);
        }
        foreach ($others as $i => $status) {
            $w->post('weitere' . $i, 't', ['by' => 'm0', 'status' => $status, 'age' => 200100 + $i]);
        }

        return $w;
    };
    $reply = static fn (AcWorld $w): array => ['topic_id' => $w->t['t']];
    $w = $make(3, 2);
    T::same('ok:pending', $w->cell('m0', 'reply', $reply($w)), 'Deckel 3, zwei wartende: noch einer erlaubt');
    $w = $make(3, 3);
    T::same('pending_limit', $w->cell('m0', 'reply', $reply($w)), 'Deckel 3, drei wartende: pending_limit');
    T::same('pending_limit', $w->cell('m0', 'create_topic', ['forum_id' => $w->f['fo']]), 'dto. Thema anlegen');
    $w = $make(4, 3);
    T::same('ok:pending', $w->cell('m0', 'reply', $reply($w)), 'Deckel 4, drei wartende: erlaubt');
    $w = $make(1, 1);
    T::same('pending_limit', $w->cell('m0', 'reply', $reply($w)), 'Deckel 1, ein wartender: pending_limit');
    $w = $make(1, 0);
    T::same('ok:pending', $w->cell('m0', 'reply', $reply($w)), 'Deckel 1, keiner wartend: erlaubt');
    $w = $make(0, 1);
    T::same('pending_limit', $w->cell('m0', 'reply', $reply($w)), 'Deckel 0 wird auf die untere Grenze 1 geklemmt');
    $w = $make(20, 19);
    T::same('ok:pending', $w->cell('m0', 'reply', $reply($w)), 'Deckel 20 (obere Grenze), 19 wartende: erlaubt');
    $w = $make(3, 1, ['rejected', 'rejected', 'rejected', 'hidden', 'deleted']);
    T::same('ok:pending', $w->cell('m0', 'reply', $reply($w)), 'nur Status pending zählt (abgelehnte, ausgeblendete, gelöschte nicht)');
    $w = $make(3, 3);
    T::same('pending_limit', $w->cell('m0', 'reply', $reply($w)), 'Kontrolle: drei wartende, Deckel 3');
    $w->member('anderer', []);
    T::same('ok:pending', $w->cell('anderer', 'reply', $reply($w)), 'wartende Beiträge anderer Mitglieder zählen nicht');
    // Der Deckel greift nur, wo ein Beitrag wartet; veröffentlichte Beiträge brauchen keinen Platz in der Warteschlange
    $w->member('erfahren', ['posts' => 5]);
    for ($i = 0; $i < 5; $i++) {
        $w->post('erf' . $i, 't', ['by' => 'erfahren', 'status' => 'pending', 'age' => 300000 + $i]);
    }
    T::same('ok:published', $w->cell('erfahren', 'reply', $reply($w)), 'wer veröffentlicht, unterliegt dem Deckel nicht');
    T::same('ok:published', $w->cell('gm', 'reply', $reply($w)), 'Moderation: kein Deckel');
});

T::run('Wirkung: trust_limits', static function (): void {
    T::same(5, Settings::DEFINITIONS['trust_limits']['default'][0]['posts_per_day'], 'Standard Stufe 0: 5 Beiträge je Tag');
    $limits = [
        0 => ['posts_per_day' => 2, 'topics_per_hour' => 1],
        1 => ['posts_per_day' => 4, 'topics_per_hour' => 2],
        2 => ['posts_per_day' => -1, 'topics_per_hour' => -1],
        3 => ['posts_per_day' => 7, 'topics_per_hour' => 3],
    ];
    $make = static fn (array $limits): AcWorld => acStandardWorld([
        'trust_limits' => $limits, 'rate_post_seconds' => 0, 'rate_topic_seconds' => 0, 'new_member_approval_posts' => 0,
    ]);
    $w = $make($limits);
    $reply = ['topic_id' => $w->t['t']];
    $create = ['forum_id' => $w->f['fo']];

    // Tageslimit je Stufe
    $w->post('z0', 't', ['by' => 'm0', 'age' => 3600]);
    T::same('ok:published', $w->cell('m0', 'reply', $reply), 'Stufe 0, Limit 2: ein Beitrag heute → erlaubt');
    $w->post('z1', 't', ['by' => 'm0', 'age' => 3600]);
    T::same('trust_limit', $w->cell('m0', 'reply', $reply), 'Stufe 0, Limit 2: zwei Beiträge heute → trust_limit');
    T::same('trust_limit', $w->cell('m0', 'create_topic', $create), 'dto. Thema anlegen (der Themenstart ist ein Beitrag)');
    T::same(403, $w->can('m0', 'reply', $reply)->status(), 'trust_limit meldet 403');
    $w->member('l1', ['posts' => 5]);
    foreach ([3600, 3700, 3800] as $i => $age) {
        $w->post('l1_' . $i, 't', ['by' => 'l1', 'age' => $age]);
    }
    T::same('ok:published', $w->cell('l1', 'reply', $reply), 'Stufe 1, Limit 4: drei Beiträge → erlaubt');
    $w->post('l1_3', 't', ['by' => 'l1', 'age' => 3900]);
    T::same('trust_limit', $w->cell('l1', 'reply', $reply), 'Stufe 1, Limit 4: vier Beiträge → trust_limit');
    $w->member('l2', ['posts' => 25]);
    for ($i = 0; $i < 12; $i++) {
        $w->post('l2_' . $i, 't', ['by' => 'l2', 'age' => 3600 + $i]);
    }
    T::same('ok:published', $w->cell('l2', 'reply', $reply), 'Stufe 2, Limit -1 = unbegrenzt: zwölf Beiträge → erlaubt');
    T::same('ok:published', $w->cell('l2', 'create_topic', $create), 'Stufe 2, topics_per_hour -1 = unbegrenzt');
    // Beiträge außerhalb der 24 Stunden zählen nicht, jeder Beitrag im Fenster zählt (auch gelöschte und abgelehnte, B3/B29)
    $w->member('l0b', []);
    $w->post('alt_a', 't', ['by' => 'l0b', 'age' => 86401]);
    $w->post('alt_b', 't', ['by' => 'l0b', 'age' => 90000]);
    $w->post('alt_c', 't', ['by' => 'l0b', 'age' => 90000, 'status' => 'deleted']);
    $w->post('alt_d', 't', ['by' => 'l0b', 'age' => 90000, 'status' => 'rejected']);
    T::same('ok:published', $w->cell('l0b', 'reply', $reply), 'ältere Beiträge (> 24 h) zählen nicht, auch nicht gelöschte und abgelehnte');
    $w->post('neu_a', 't', ['by' => 'l0b', 'age' => 3600, 'status' => 'pending']);
    $w->post('neu_b', 't', ['by' => 'l0b', 'age' => 3600, 'status' => 'hidden']);
    T::same('trust_limit', $w->cell('l0b', 'reply', $reply), 'wartende und ausgeblendete Beiträge zählen');
    $w->member('l0c', []);
    $w->post('del_a', 't', ['by' => 'l0c', 'age' => 3600, 'status' => 'deleted', 'status_reason' => 'author']);
    $w->post('del_b', 't', ['by' => 'l0c', 'age' => 3500, 'status' => 'deleted', 'status_reason' => 'moderator']);
    T::same('trust_limit', $w->cell('l0c', 'reply', $reply), 'zwei gelöschte Beiträge im Fenster (eigenes Löschen und Löschen durch die Moderation) zählen: Limit 2');
    foreach (['gm', 'admin'] as $mod) {
        foreach ([3600, 3700, 3800, 3900, 4000, 4100, 4200, 4300] as $i => $age) {
            $w->post($mod . '_tag' . $i, 't', ['by' => $mod, 'age' => $age]);
        }
        T::same('ok:published', $w->cell($mod, 'reply', $reply), ($mod === 'gm' ? 'globale Moderation' : 'admin') . ': kein Tageslimit (acht Beiträge heute, Limit der Stufe 4)');
        T::same('ok:published', $w->cell($mod, 'create_topic', $create), ($mod === 'gm' ? 'globale Moderation' : 'admin') . ': kein Themenlimit je Stunde');
    }
    foreach ([3600, 3700, 3800, 3900] as $i => $age) {
        $w->post('fm_' . $i, 't', ['by' => 'fm', 'age' => $age]);
    }
    T::same('trust_limit', $w->cell('fm', 'reply', $reply), 'Forum-Moderator: das Tageslimit der Stufe gilt für gewöhnliche Schreibaktionen weiter');
    T::same('ok', $w->cell('fm', 'topic.lock', ['topic_id' => $w->t['t']]), 'Forum-Moderator: Moderationsaktionen kennen kein Tageslimit');
    T::same('ok:published', $w->cell('m3', 'reply', $reply), 'Stufe 3 (Limit 7): erlaubt');

    // Themen je Stunde
    $w->member('h0', []);
    $w->topic('h0_t', 'fq', ['by' => 'h0', 'age' => 1800, 'activity' => 1800]);
    T::same('trust_limit', $w->cell('h0', 'create_topic', $create), 'Stufe 0, 1 Thema je Stunde: ein Thema vor 30 min → trust_limit');
    T::same('ok:published', $w->cell('h0', 'reply', $reply), 'topics_per_hour gilt nicht für Antworten');
    $w->member('h0b', []);
    $w->topic('h0b_t', 'fq', ['by' => 'h0b', 'age' => 7200, 'activity' => 7200]);
    T::same('ok:published', $w->cell('h0b', 'create_topic', $create), 'ein Thema vor zwei Stunden zählt nicht');
    $w->member('h1', ['posts' => 5]);
    $w->topic('h1_a', 'fq', ['by' => 'h1', 'age' => 600, 'activity' => 600]);
    T::same('ok:published', $w->cell('h1', 'create_topic', $create), 'Stufe 1, 2 Themen je Stunde: ein Thema → erlaubt');
    $w->topic('h1_b', 'fq', ['by' => 'h1', 'age' => 900, 'activity' => 900]);
    T::same('trust_limit', $w->cell('h1', 'create_topic', $create), 'Stufe 1, 2 Themen je Stunde: zwei Themen → trust_limit');
    // B3/B29: Löschen gibt das Stundenkontingent nicht frei (auch nicht durch die Moderation, auch nicht im Papierkorb)
    $w->member('h2', ['posts' => 5]);
    $w->topic('h2_a', 'fq', ['by' => 'h2', 'age' => 600, 'activity' => 600]);
    T::same('ok:published', $w->cell('h2', 'create_topic', $create), 'Stufe 1, 2 Themen je Stunde: ein Thema → erlaubt');
    $w->topic('h2_b', 'fq', ['by' => 'h2', 'age' => 900, 'activity' => 900, 'status' => 'deleted']);
    T::same('trust_limit', $w->cell('h2', 'create_topic', $create), 'ein Thema und ein gelöschtes Thema in der Stunde: trust_limit');
    $w->member('h3', ['posts' => 5]);
    $w->topic('h3_a', 'fq', ['by' => 'h3', 'age' => 600, 'activity' => 600, 'status' => 'trash']);
    $w->topic('h3_b', 'fq', ['by' => 'h3', 'age' => 900, 'activity' => 900, 'status' => 'deleted']);
    T::same('trust_limit', $w->cell('h3', 'create_topic', $create), 'zwei gelöschte Themen (deleted, trash) in der Stunde: trust_limit');
    $w->member('h4', ['posts' => 5]);
    $w->topic('h4_a', 'fq', ['by' => 'h4', 'age' => 600, 'activity' => 600, 'status' => 'moved']);
    $w->topic('h4_b', 'fq', ['by' => 'h4', 'age' => 900, 'activity' => 900, 'status' => 'deleted']);
    T::same('ok:published', $w->cell('h4', 'create_topic', $create), 'ein Verweis (moved) zählt nicht: ein gelöschtes Thema, Limit 2 → erlaubt');
    $w->member('h5', ['posts' => 5]);
    $w->topic('h5_a', 'fq', ['by' => 'h5', 'age' => 4000, 'activity' => 4000, 'status' => 'deleted']);
    $w->topic('h5_b', 'fq', ['by' => 'h5', 'age' => 5000, 'activity' => 5000, 'status' => 'deleted']);
    $w->topic('h5_c', 'fq', ['by' => 'h5', 'age' => 600, 'activity' => 600, 'status' => 'published']);
    T::same('ok:published', $w->cell('h5', 'create_topic', $create), 'gelöschte Themen älter als eine Stunde zählen nicht (ein Thema in der Stunde, Limit 2)');
    $w->topic('h5_d', 'fq', ['by' => 'h5', 'age' => 700, 'activity' => 700, 'status' => 'deleted']);
    T::same('trust_limit', $w->cell('h5', 'create_topic', $create), 'ein weiteres gelöschtes Thema in der Stunde: trust_limit');

    // limitsFor liefert die eingestellten Zahlen je Stufe
    foreach (['m0' => [0, 2, 1], 'own' => [1, 4, 2], 'm2' => [2, -1, -1], 'm3' => [3, 7, 3]] as $name => [$level, $day, $hour]) {
        $l = $w->access->limitsFor($w->u[$name]);
        T::same([$level, $day, $hour], [$l['level'], $l['posts_per_day'], $l['topics_per_hour']], 'limitsFor ' . $name . ': Stufe ' . $level);
    }
    T::same(['level' => 0, 'posts_per_day' => 2, 'topics_per_hour' => 1, 'links_per_post' => 1, 'images_per_post' => 0, 'attachments_per_post' => 0, 'attachments_per_day' => 0, 'edit_window_minutes' => 30], $w->access->limitsFor(null), 'limitsFor für Gäste: Stufe 0, alle sechs Felder (nicht gesetzte Felder: Standard der Stufe)');
    T::same(0, $w->access->limitsFor(987654)['level'], 'limitsFor für unbekannte Konten: Stufe 0');
    T::same(0, $w->access->limitsFor($w->u['inactive'])['level'], 'limitsFor für nicht aktive Konten: Stufe 0');
    // Standardwerte
    $defaults = acStandardWorld(['rate_post_seconds' => 0, 'rate_topic_seconds' => 0, 'new_member_approval_posts' => 0]);
    T::same(['level' => 1, 'posts_per_day' => 30, 'topics_per_hour' => 3, 'links_per_post' => 5, 'images_per_post' => 10, 'attachments_per_post' => 3, 'attachments_per_day' => 20, 'edit_window_minutes' => 30], $defaults->access->limitsFor($defaults->u['own']), 'Standard Stufe 1');
    T::same(['level' => 3, 'posts_per_day' => 300, 'topics_per_hour' => 10, 'links_per_post' => -1, 'images_per_post' => -1, 'attachments_per_post' => 3, 'attachments_per_day' => 100, 'edit_window_minutes' => 1440], $defaults->access->limitsFor($defaults->u['m3']), 'Standard Stufe 3');
    T::same(['level', ...Settings::LIMIT_FIELDS, 'edit_window_minutes'], array_keys($defaults->access->limitsFor($defaults->u['m0'])), 'limitsFor: Schlüsselreihenfolge level, LIMIT_FIELDS, edit_window_minutes');
    for ($i = 0; $i < 4; $i++) {
        $defaults->post('d' . $i, 't', ['by' => 'm0', 'age' => 3600 + $i]);
    }
    T::same('ok:published', $defaults->cell('m0', 'reply', ['topic_id' => $defaults->t['t']]), 'Standard Stufe 0: vier Beiträge → erlaubt (Limit 5)');
    $defaults->post('d4', 't', ['by' => 'm0', 'age' => 3600]);
    T::same('trust_limit', $defaults->cell('m0', 'reply', ['topic_id' => $defaults->t['t']]), 'Standard Stufe 0: fünf Beiträge → trust_limit');

    // PR #2b: -1 = unbegrenzt, 0 = keine; die vier Inhaltsfelder (Vertrag §3.1)
    $zero = $make([1 => ['posts_per_day' => 0, 'topics_per_hour' => 0]]);
    T::same('trust_limit', $zero->cell('own', 'reply', ['topic_id' => $zero->t['t']]), 'posts_per_day = 0: keine Beiträge (trust_limit, auch ohne einen Beitrag heute)');
    T::same('trust_limit', $zero->cell('own', 'create_topic', ['forum_id' => $zero->f['fo']]), 'topics_per_hour = 0 bzw. posts_per_day = 0: kein Thema');
    $hourZero = $make([1 => ['topics_per_hour' => 0]]);
    T::same('trust_limit', $hourZero->cell('own', 'create_topic', ['forum_id' => $hourZero->f['fo']]), 'topics_per_hour = 0 allein: kein Thema');
    T::same('ok:published', $hourZero->cell('own', 'reply', ['topic_id' => $hourZero->t['t']]), 'topics_per_hour = 0 gilt nicht für Antworten');
    T::same('ok:published', $zero->cell('gm', 'reply', ['topic_id' => $zero->t['t']]), 'globale Moderation: auch Grenze 0 gilt nicht');
    $content = static fn (int $links, int $images = 0, int $attachments = 0): array => ['links' => $links, 'images' => $images, 'attachments' => $attachments];
    $c = $make([0 => ['links_per_post' => -1, 'images_per_post' => 2], 1 => ['links_per_post' => 0, 'attachments_per_post' => -1]]);
    $create = ['forum_id' => $c->f['fo']];
    T::same('ok:published', $c->cell('m0', 'create_topic', $create + ['content' => $content(500, 2)]), 'Stufe 0, links_per_post -1: beliebig viele Links; images_per_post 2: zwei Bilder erlaubt');
    T::same('too_many_images', $c->cell('m0', 'create_topic', $create + ['content' => $content(0, 3)]), 'Stufe 0, images_per_post 2: drei Bilder → too_many_images');
    T::same('too_many_links', $c->cell('own', 'create_topic', $create + ['content' => $content(1)]), 'Stufe 1, links_per_post 0: kein Link');
    T::same('ok:published', $c->cell('own', 'create_topic', $create + ['content' => $content(0, 0, 50)]), 'Stufe 1, attachments_per_post -1: Anhänge unbegrenzt (die Obergrenze je Beitrag setzt AttachmentService)');
    $stored = $make([0 => ['links_per_post' => -5, 'posts_per_day' => -7]]);
    T::same([1, 5], [$stored->access->limitsFor($stored->u['m0'])['links_per_post'], $stored->access->limitsFor($stored->u['m0'])['posts_per_day']], 'gespeichertes -5 bzw. -7 (unter -1) → Standard des Feldes, nie unbegrenzt');
    T::same('too_many_links', $stored->cell('m0', 'create_topic', ['forum_id' => $stored->f['fo'], 'content' => $content(2)]), 'gespeichertes -5: Standard 1 Link gilt');
    T::same('ok:published', $stored->cell('m0', 'create_topic', ['forum_id' => $stored->f['fo'], 'content' => $content(1)]), 'gespeichertes -5: ein Link erlaubt');
    $huge = $make([2 => ['attachments_per_day' => 999999]]);
    T::same(Settings::LIMIT_MAX, $huge->access->limitsFor($huge->u['m2'])['attachments_per_day'], 'über 100000 → 100000');
});

T::run('Wirkung: default_permissions', static function (): void {
    T::same(0, Settings::DEFINITIONS['default_permissions']['default']['member']['delete_own'], 'Standard: Mitglieder dürfen nicht löschen');
    $w = acStandardWorld();
    $own = ['post_id' => $w->p['p']];
    T::same('no_permission', $w->cell('own', 'delete', $own), 'Standard delete_own = 0');
    T::same('no_permission', $w->cell('m1', 'poll', ['forum_id' => $w->f['fo']]), 'Standard poll = 0');
    T::same('ok', $w->cell('m1', 'upload', ['forum_id' => $w->f['fo']]), 'Standard upload = 1');
    T::same('ok:pending', $w->cell('m0', 'reply', ['topic_id' => $w->t['t']]), 'Standard bypass_approval = 0');

    // Überschreiben je Matrix-Schlüssel (Mitglieder)
    $override = static fn (array $member, array $guest = []): AcWorld => acStandardWorld(['default_permissions' => ['member' => $member, 'guest' => $guest]]);
    $o = $override(['delete_own' => 1]);
    T::same('ok', $o->cell('own', 'delete', ['post_id' => $o->p['p']]), 'member.delete_own = 1: Autor darf löschen');
    $o = $override(['reply' => 0]);
    T::same('no_permission', $o->cell('m1', 'reply', ['topic_id' => $o->t['t']]), 'member.reply = 0: Antworten nicht erlaubt');
    T::same('ok:published', $o->cell('m1', 'create_topic', ['forum_id' => $o->f['fo']]), 'member.reply = 0 lässt create_topic unberührt');
    T::same('ok:published', $o->cell('gm', 'reply', ['topic_id' => $o->t['t']]), 'globale Moderation ignoriert die Standards');
    $o = $override(['create_topic' => 0]);
    T::same('no_permission', $o->cell('m1', 'create_topic', ['forum_id' => $o->f['fo']]), 'member.create_topic = 0');
    $o = $override(['read' => 0]);
    T::same('ok', $o->cell('m1', 'read', ['forum_id' => $o->f['fo']]), 'member.read = 0, aber guest.read = 1 und Tor offen: die Gast-Zeile gilt auch für Angemeldete (Vereinigung)');
    $o = $override(['read' => 0], ['read' => 0]);
    T::same('not_found', $o->cell('m1', 'read', ['forum_id' => $o->f['fo']]), 'member.read = 0 und guest.read = 0: Foren ohne Zeilen sind nicht lesbar');
    T::same('not_found', $o->cell('guest', 'read', ['forum_id' => $o->f['fo']]), 'guest.read = 0: Gäste lesen auch bei offenem Tor nicht');
    T::same('ok', $o->cell('gm', 'read', ['forum_id' => $o->f['fo']]), 'globale Moderation liest trotzdem');
    T::same([], $o->access->filterReadable($o->u['m1'], [$o->f['fo']]), 'dasselbe in der Mengen-API');
    $o = $override(['poll' => 1]);
    T::same('ok', $o->cell('m1', 'poll', ['forum_id' => $o->f['fo']]), 'member.poll = 1');
    $o = $override(['bypass_approval' => 1]);
    T::same('ok:published', $o->cell('m0', 'reply', ['topic_id' => $o->t['t']]), 'member.bypass_approval = 1: keine Freigabepflicht');
    foreach ([[0, 'slow_mode@500'], [1, 'ok:published']] as [$bypass, $expected]) {
        $o = acStandardWorld(['rate_post_seconds' => 0, 'rate_topic_seconds' => 0, 'new_member_approval_posts' => 0, 'default_permissions' => ['member' => ['bypass_slow_mode' => $bypass]]]);
        $o->forum('slow', ['slow_mode' => 600]);
        $o->topic('tslow', 'slow', ['by' => 'own']);
        $o->post('prev', 'tslow', ['by' => 'm1', 'age' => 100]);
        T::same($expected, $o->cell('m1', 'reply', ['topic_id' => $o->t['tslow']]), 'member.bypass_slow_mode = ' . $bypass . ': Langsamer Modus (600 s, Beitrag vor 100 s)');
    }
    $o = $override(['upload' => 0]);
    T::same('no_permission', $o->cell('m1', 'upload', ['forum_id' => $o->f['fo']]), 'member.upload = 0');
    $o = $override([], ['reply' => 1]);
    T::same('ok:published', $o->cell('m1', 'reply', ['topic_id' => $o->t['t']]), 'guest.reply = 1 gilt über die Vereinigung auch für Mitglieder; Gäste bleiben login_required');
    T::same('login_required', $o->cell('guest', 'reply', ['topic_id' => $o->t['t']]), 'Gäste schreiben nie');

    // Forum mit Rechte-Zeilen: die Standards gelten dort nicht (Whitelist), auch wenn sie großzügig sind
    $o = $override(['read' => 1, 'reply' => 1, 'create_topic' => 1, 'delete_own' => 1]);
    T::same('not_found', $o->cell('m1', 'read', ['forum_id' => $o->f['fi']]), 'Forum mit Zeilen: Standards gelten nicht (member.read = 1 hilft nicht)');
    T::same('not_found', $o->cell('m1', 'reply', ['topic_id' => $o->t['tfi']]), 'dto. reply');
    // Nur ein Forum ganz ohne Zeilen in der ganzen Kette nutzt die Standards
    $o->forum('Pz');
    $o->permissions('Pz', [['subject_type' => 'role', 'subject_key' => 'editor', 'permissions' => ['read' => 1]]]);
    $o->forum('Cz', ['parent' => 'Pz']);
    $o->forum('Dz', ['parent' => 'Pz', 'inherit' => false]);
    T::same('not_found', $o->cell('m1', 'read', ['forum_id' => $o->f['Cz']]), 'Kindforum erbt Zeilen: keine Standards');
    T::same('ok', $o->cell('m1', 'read', ['forum_id' => $o->f['Dz']]), 'Kindforum mit inherit_permissions = 0 und ohne Zeilen: Standards');
    // Gast-Standard mit geschlossenem Tor
    $closed = acStandardWorld(['guests_can_read' => false, 'default_permissions' => ['guest' => ['read' => 1]]]);
    T::same('not_found', $closed->cell('guest', 'read', ['forum_id' => $closed->f['fo']]), 'guest.read = 1, Tor zu: Gäste lesen nicht');
});

T::run('Wirkung: trust_level1_posts', static function (): void {
    T::same(1, Settings::DEFINITIONS['trust_level1_posts']['default'], 'Standard: 1 Beitrag');
    foreach ([[1, 1], [3, 1], [4, 0], [10, 0], [0, 1]] as [$threshold, $expectedLevel]) {
        $w = acStandardWorld(['trust_level1_posts' => $threshold, 'trust_level2_posts' => 1000]);
        $w->member('t3', ['posts' => 3]);
        T::same($expectedLevel, $w->access->limitsFor($w->u['t3'])['level'], 'Schwelle ' . $threshold . ', drei Beiträge: Stufe ' . $expectedLevel);
    }
    $w = acStandardWorld(['trust_level1_posts' => 0]);
    T::same(1, $w->access->limitsFor($w->u['m0'])['level'], 'Schwelle 0: schon ohne Beitrag Stufe 1 (bestätigt)');
    T::same(0, $w->access->limitsFor($w->u['unverified'])['level'], 'Schwelle 0: ohne bestätigte E-Mail bleibt es Stufe 0');
    // Die Stufe wirkt auf Rechte-Zeilen (trust:1) und Limits
    $w = acStandardWorld(['trust_level1_posts' => 6]);
    $w->forum('t1');
    $w->permissions('t1', [['subject_type' => 'trust', 'subject_key' => '1', 'permissions' => ['read' => 1]]]);
    T::same('not_found', $w->cell('m1', 'read', ['forum_id' => $w->f['t1']]), 'Schwelle 6: fünf Beiträge reichen für trust:1 nicht');
    $w = acStandardWorld(['trust_level1_posts' => 5]);
    $w->forum('t1');
    $w->permissions('t1', [['subject_type' => 'trust', 'subject_key' => '1', 'permissions' => ['read' => 1]]]);
    T::same('ok', $w->cell('m1', 'read', ['forum_id' => $w->f['t1']]), 'Schwelle 5: fünf Beiträge reichen für trust:1');
    // Eine festgesetzte Stufe ignoriert die Schwelle
    $w->member('locked0', ['posts' => 500, 'level' => 0, 'locked' => true]);
    T::same(0, $w->access->limitsFor($w->u['locked0'])['level'], 'festgesetzte Stufe 0 bleibt trotz vieler Beiträge');
});

T::run('Wirkung: trust_level2_posts', static function (): void {
    T::same(20, Settings::DEFINITIONS['trust_level2_posts']['default'], 'Standard: 20 Beiträge');
    foreach ([[20, 20, 2], [20, 19, 1], [30, 25, 1], [30, 30, 2], [1, 1, 2], [10000, 9999, 1]] as [$threshold, $posts, $expectedLevel]) {
        $w = acStandardWorld(['trust_level2_posts' => $threshold]);
        $w->member('t2', ['posts' => $posts]);
        T::same($expectedLevel, $w->access->limitsFor($w->u['t2'])['level'], 'Schwelle ' . $threshold . ', ' . $posts . ' Beiträge: Stufe ' . $expectedLevel);
    }
    // Die Stufe 2 wirkt auf Limits und Rechte-Zeilen
    $w = acStandardWorld(['trust_level2_posts' => 30]);
    $w->forum('t2');
    $w->permissions('t2', [['subject_type' => 'trust', 'subject_key' => '2', 'permissions' => ['read' => 1]]]);
    T::same('not_found', $w->cell('m2', 'read', ['forum_id' => $w->f['t2']]), 'Schwelle 30: 25 Beiträge reichen für trust:2 nicht');
    $default = acStandardWorld();
    T::same(['level' => 2, 'posts_per_day' => 100, 'topics_per_hour' => 5, 'links_per_post' => -1, 'images_per_post' => -1, 'attachments_per_post' => 3, 'attachments_per_day' => 50, 'edit_window_minutes' => 30], $default->access->limitsFor($default->u['m2']), 'Standard: 25 Beiträge sind Stufe 2 mit den Limits der Stufe 2');
    $w = acStandardWorld(['trust_level2_posts' => 25]);
    $w->forum('t2');
    $w->permissions('t2', [['subject_type' => 'trust', 'subject_key' => '2', 'permissions' => ['read' => 1]]]);
    T::same('ok', $w->cell('m2', 'read', ['forum_id' => $w->f['t2']]), 'Schwelle 25: 25 Beiträge reichen für trust:2');
});

// ── Eigene Vertrauensregel ──────────────────────────────────────────────────────────────────────────────────────

T::run('TrustLevelPolicy austauschbar: CommunityAccess nutzt die Regel, nicht die Standardstufen', static function (): void {
    $policy = new class () implements TrustLevelPolicy {
        public int $result = 3;
        /** @var list<array<int, mixed>> */
        public array $calls = [];

        public function levelFor(int $userId, bool $emailVerified, int $postCount, int $storedLevel, bool $storedLocked): int
        {
            $this->calls[] = [$userId, $emailVerified, $postCount, $storedLevel, $storedLocked];

            return $this->result;
        }
    };
    $w = acStandardWorld([], $policy);
    T::same(3, $w->access->limitsFor($w->u['m1'])['level'], 'die eigene Regel bestimmt die Stufe (nicht die Beitragszahl)');
    T::same([$w->u['m1'], true, 5, 0, false], $policy->calls[0] ?? null, 'die Regel bekommt Konto, Bestätigung, Beitragszahl, gespeicherte Stufe und Festsetzung');
    $policy->calls = [];
    $w->access->limitsFor($w->u['m3']);
    T::same([$w->u['m3'], true, 100, 3, true], $policy->calls[0] ?? null, 'dto. für ein Konto mit festgesetzter Stufe');
    T::same(1440, $w->access->limitsFor($w->u['m1'])['edit_window_minutes'], 'Stufe 3 aus der Regel: vertrautes Bearbeitungsfenster');
    // Werte außerhalb von 0 bis 4 werden geklemmt
    $policy->result = 99;
    T::same(4, $w->access->limitsFor($w->u['m1'])['level'], 'Stufe 99 wird auf 4 geklemmt');
    $policy->result = -3;
    T::same(0, $w->access->limitsFor($w->u['m1'])['level'], 'Stufe -3 wird auf 0 geklemmt');
    // Die Stufe aus der Regel öffnet trust:N-Zeilen
    $policy->result = 3;
    $w->forum('t3');
    $w->permissions('t3', [['subject_type' => 'trust', 'subject_key' => '3', 'permissions' => ['read' => 1]]]);
    T::same('ok', $w->cell('m0', 'read', ['forum_id' => $w->f['t3']]), 'die Regel liefert Stufe 3: trust:3 gilt, unabhängig von der Beitragszahl');
    $policy->result = 2;
    $w->access->flush();
    T::same('not_found', $w->cell('m0', 'read', ['forum_id' => $w->f['t3']]), 'die Regel liefert Stufe 2: trust:3 gilt nicht');
});

T::run('Fehler beim Lesen: im Zweifel abgelehnt (fail-closed), protokolliert, ohne Ausnahme nach außen', static function (): void {
    $log = static fn (): string => implode("\n", array_map(static fn (string $f): string => acRead($f), glob(LOG_PATH . 'community-*.log') ?: []));
    $w = acStandardWorld();
    $read = ['forum_id' => $w->f['fo']];
    $reply = ['topic_id' => $w->t['t']];
    T::same('ok', $w->cell('m1', 'read', $read), 'Ausgangslage');

    // Datenbankfehler bei den Rechte-Zeilen
    $before = substr_count($log(), 'Zugriffsprüfung');
    $w->access->flush();
    $w->db->failOn = ['/community_forum_permissions/'];
    T::same('not_found', $w->cell('m1', 'read', $read), 'Datenbankfehler beim Lesen: not_found');
    T::same('no_permission', $w->cell('m1', 'reply', $reply), 'Datenbankfehler beim Schreiben: no_permission');
    T::same('no_permission', $w->cell('admin', 'reply', $reply), 'auch admin wird bei einem Lesefehler nicht durchgewunken');
    T::same([], $w->access->filterReadable($w->u['m1'], [$w->f['fo']]), 'Datenbankfehler: filterReadable liefert nichts');
    T::check(substr_count($log(), 'Zugriffsprüfung') >= $before + 3, 'Fehler werden im Kanal community protokolliert');
    $w->db->failOn = [];
    $w->access->flush();
    T::same('ok', $w->cell('m1', 'read', $read), 'Fehler behoben: die Entscheidung folgt');

    // Datenbankfehler schon beim Konto
    $w->access->flush();
    $w->db->failOn = ['/FROM cms_users/'];
    T::same('not_found', $w->cell('m1', 'read', $read), 'Fehler beim Laden des Kontos: abgelehnt');
    $w->db->failOn = [];
    $w->access->flush();

    // Rollenabfrage nicht erreichbar: kein Recht
    $w->rolesFail = true;
    $w->access->flush();
    T::same('not_found', $w->cell('m1', 'read', $read), 'Rollenabfrage wirft: keine community.view');
    T::same('no_capability', $w->cell('m1', 'reply', $reply), 'Rollenabfrage wirft: keine community.participate');
    T::same('ok:published', $w->cell('admin', 'reply', $reply), 'admin braucht die Rollenabfrage nicht');
    T::same('no_capability', $w->cell('gm', 'topic.lock', ['topic_id' => $w->t['t']]), 'Rollenabfrage wirft: weder globale Moderation noch community.view');
    $w->rolesFail = false;
    $w->access->forgetUser($w->u['m1']);
    T::same('ok', $w->cell('m1', 'read', $read), 'Rollenabfrage wieder erreichbar (forgetUser verwirft den gemerkten Fehler)');
});

// ── PR #2b (Vertrag DESIGN-2b §3.5): Moderation, Ziel des Verschiebens, Melden, Rang, Moderationsbereich, Inhalt ─────

/** Anweisungsarten eines Abfrageprotokolls (Tabelle und Art, ohne Bedingungen), wie im Abschnitt „Gleiche Abfragefolge“. */
function acKinds(array $log): array
{
    return array_map(static fn (string $sql): string => (string) preg_replace('/ (WHERE|ORDER BY|LIMIT|AND) .*$/', '', (string) preg_replace('/\s+/', ' ', trim($sql))), $log);
}

T::run('Matrix Moderationsaktionen aus PR #2b: neue Aktionen, nur global, Themenzustand (gelöscht, Papierkorb, Verweis)', static function (): void {
    $w = acStandardWorld();
    $topic = ['topic_id' => $w->t['t']];
    acMatrix($w, 'topic.unannounce nur global', 'topic.unannounce', $topic, acM(['GM' => 'ok', 'AD' => 'ok']));
    acMatrix($w, 'topic.announce nur global (Kontrolle)', 'topic.announce', $topic, acM(['GM' => 'ok', 'AD' => 'ok']));
    foreach (['topic.hide', 'topic.restore', 'topic.reject'] as $action) {
        acMatrix($w, $action, $action, $topic, acM(AC_MODS_OK));
        acMatrix($w, $action . ' im Forum archived', $action, ['topic_id' => $w->t['ta']], acM(['FM' => 'forum_archived', 'GM' => 'forum_archived', 'AD' => 'forum_archived']));
    }
    $deleted = ['*' => 'not_found', 'FM' => 'topic_deleted', 'GM' => 'topic_deleted', 'AD' => 'topic_deleted'];
    foreach (['topic.lock', 'topic.pin', 'topic.rename', 'topic.hide', 'topic.restore', 'topic.approve', 'topic.reject', 'topic.move'] as $action) {
        acMatrix($w, $action . ' im gelöschten Thema: topic_deleted', $action, ['topic_id' => $w->t['td']], acM($deleted));
    }
    acMatrix($w, 'post.hide im gelöschten Thema: topic_deleted', 'post.hide', ['post_id' => $w->p['ptd']], acM($deleted));
    acMatrix($w, 'post.approve im gelöschten Thema: topic_deleted', 'post.approve', ['post_id' => $w->p['ptd']], acM($deleted));
    acMatrix($w, 'topic.lock im Papierkorb: nur admin sieht es, dann topic_deleted', 'topic.lock', ['topic_id' => $w->t['ttr']], acM(['*' => 'not_found', 'AD' => 'topic_deleted']));
    acMatrix($w, 'post.reject im Papierkorb: nur admin sieht es, Ablehnen bleibt möglich', 'post.reject', ['topic_id' => $w->t['ttr']], acM(['*' => 'not_found', 'AD' => 'ok']));
    acMatrix($w, 'topic.lock auf ein Verweis-Thema: topic_moved', 'topic.lock', ['topic_id' => $w->t['tm']], acM(['FM' => 'topic_moved', 'GM' => 'topic_moved', 'AD' => 'topic_moved']));
    acMatrix($w, 'post.reject im Verweis-Thema: topic_moved', 'post.reject', ['topic_id' => $w->t['tm']], acM(['FM' => 'topic_moved', 'GM' => 'topic_moved', 'AD' => 'topic_moved']));
    acMatrix($w, 'moderate mit gelöschtem Thema als Ziel: die Sammelabfrage prüft keinen Themenzustand', 'moderate', ['topic_id' => $w->t['td']], acM(['*' => 'not_found'] + AC_MODS_OK));
    acMatrix($w, 'topic.lock im wartenden Thema: erlaubt', 'topic.lock', ['topic_id' => $w->t['tp']], acM(['*' => 'not_found', 'OWN' => 'no_permission'] + AC_MODS_OK));
    acMatrix($w, 'topic.restore im ausgeblendeten Thema: erlaubt', 'topic.restore', ['topic_id' => $w->t['th']], acM(['*' => 'not_found', 'OWN' => 'no_permission'] + AC_MODS_OK));
    acMatrix($w, 'topic.unannounce im gelöschten Thema: erst die Rechte (FM no_permission), dann der Zustand', 'topic.unannounce', ['topic_id' => $w->t['td']], acM(['*' => 'not_found', 'FM' => 'no_permission', 'GM' => 'topic_deleted', 'AD' => 'topic_deleted']));
});

T::run('Ziel des Verschiebens: fehlend = unsichtbar = not_found, sichtbar ohne Moderation = no_permission, danach Zustände (Vertrag §1 Nr. 8, §3.5)', static function (): void {
    $w = acStandardWorld();
    $w->forum('fx');
    $category = $w->db->category(['slug' => 'intern-ziel', 'visible' => false]);
    $w->forum('fk', ['category' => $category]);
    $w->forum('fla', ['type' => 'link', 'status' => 'archived']);
    $w->moderators('fla', [['subject_type' => 'user', 'subject_id' => $w->u['fm'], 'include_children' => 0]]);
    $move = static fn (string $topic, mixed $target): array => ['topic_id' => $w->t[$topic], 'target_forum_id' => is_string($target) && isset($w->f[$target]) ? $w->f[$target] : $target];
    $mods = static fn (string $fm, string $global): array => acM(['FM' => $fm, 'GM' => $global, 'AD' => $global]);

    acMatrix($w, 'Ziel moderiert und sichtbar', 'topic.move', $move('t', 'fr'), acM(AC_MODS_OK));
    acMatrix($w, 'Ziel verborgen (hidden), aber moderiert', 'topic.move', $move('t', 'fh'), acM(AC_MODS_OK));
    acMatrix($w, 'Ziel nicht sichtbar (Rechte-Zeilen) und nicht moderiert', 'topic.move', $move('t', 'fi'), $mods('not_found', 'ok'));
    acMatrix($w, 'Ziel in unsichtbarer Kategorie, nicht moderiert', 'topic.move', $move('t', 'fk'), $mods('not_found', 'ok'));
    acMatrix($w, 'Ziel sichtbar, aber nicht moderiert', 'topic.move', $move('t', 'fx'), $mods('no_permission', 'ok'));
    foreach (['unbekannt' => 99999, 'null' => 0, 'negativ' => -2, 'Text' => 'abc', 'Ziffern mit Rest' => $w->f['fr'] . 'x', 'Array' => [$w->f['fr']], 'true' => true] as $label => $target) {
        acMatrix($w, 'Ziel ' . $label . ': not_found', 'topic.move', $move('t', $target), $mods('not_found', 'not_found'));
    }
    acMatrix($w, 'Ziel als Ziffernfolge', 'topic.move', $move('t', (string) $w->f['fr']), acM(AC_MODS_OK));
    acMatrix($w, 'Ziel ohne Angabe (null): Menüabfrage prüft nur die Quelle', 'topic.move', $move('t', null), acM(AC_MODS_OK));
    acMatrix($w, 'Ziel = Quelle: invalid_target, erst nach den Rechten', 'topic.move', $move('t', 'fo'), $mods('invalid_target', 'invalid_target'));
    acMatrix($w, 'Ziel vom Typ link: forum_link', 'topic.move', $move('t', 'fl'), $mods('forum_link', 'forum_link'));
    acMatrix($w, 'Ziel archiviert: forum_archived', 'topic.move', $move('t', 'fa'), $mods('forum_archived', 'forum_archived'));
    acMatrix($w, 'Ziel archiviert und vom Typ link: forum_archived zuerst', 'topic.move', $move('t', 'fla'), $mods('forum_archived', 'forum_archived'));
    acMatrix($w, 'Quelle archiviert und Ziel = Quelle: forum_archived vor invalid_target', 'topic.move', $move('ta', 'fa'), $mods('forum_archived', 'forum_archived'));
    acMatrix($w, 'gelöschtes Thema, Ziel moderiert: topic_deleted', 'topic.move', $move('td', 'fr'), acM(['*' => 'not_found', 'FM' => 'topic_deleted', 'GM' => 'topic_deleted', 'AD' => 'topic_deleted']));
    acMatrix($w, 'gelöschtes Thema, Ziel unsichtbar: die Rechte zuerst (FM not_found)', 'topic.move', $move('td', 'fi'), acM(['*' => 'not_found', 'FM' => 'not_found', 'GM' => 'topic_deleted', 'AD' => 'topic_deleted']));
    acMatrix($w, 'Verweis-Thema in dasselbe Forum: topic_moved vor invalid_target', 'topic.move', $move('tm', 'fo'), $mods('topic_moved', 'topic_moved'));
    acMatrix($w, 'Zusammenführen in ein unsichtbares Ziel', 'topic.merge', $move('t', 'fi'), $mods('not_found', 'ok'));
    acMatrix($w, 'Teilen in ein sichtbares, nicht moderiertes Ziel', 'topic.split', $move('t', 'fx'), $mods('no_permission', 'ok'));
    acMatrix($w, 'Zusammenführen im selben Forum ist kein invalid_target', 'topic.merge', $move('t', 'fo'), acM(AC_MODS_OK));

    // post.move gehört jetzt zu den verschiebenden Aktionen
    $posts = static fn (mixed $target): array => ['topic_id' => $w->t['t'], 'target_forum_id' => is_string($target) && isset($w->f[$target]) ? $w->f[$target] : $target];
    acMatrix($w, 'post.move in ein moderiertes Forum', 'post.move', $posts('fr'), acM(AC_MODS_OK));
    acMatrix($w, 'post.move ins selbe Forum (anderes Thema) ist erlaubt', 'post.move', $posts('fo'), acM(AC_MODS_OK));
    acMatrix($w, 'post.move in ein unsichtbares Forum', 'post.move', $posts('fi'), $mods('not_found', 'ok'));
    acMatrix($w, 'post.move in ein sichtbares, nicht moderiertes Forum', 'post.move', $posts('fx'), $mods('no_permission', 'ok'));
    acMatrix($w, 'post.move mit Köder-ID (fehlendes Zielthema)', 'post.move', ['topic_id' => $w->t['t'], 'target_forum_id' => CommunityAccess::DECOY_ID], $mods('not_found', 'not_found'));
    acMatrix($w, 'post.move in ein Forum vom Typ link', 'post.move', $posts('fl'), $mods('forum_link', 'forum_link'));
    acMatrix($w, 'post.move mit Köder als Quelle (gemischte Beiträge)', 'post.move', ['topic_id' => CommunityAccess::DECOY_ID, 'target_forum_id' => $w->f['fr']], acM(['*' => 'not_found', 'FM' => 'not_found', 'GM' => 'not_found', 'AD' => 'not_found']));

    // Unberechtigter sieht keinen Unterschied: fehlend, unsichtbar und nicht zuständig, gleiche Abfragefolge
    $shape = static function (mixed $target) use ($w, $move): array {
        $w->access->flush();
        $w->db->log = [];
        $cell = $w->cell('fmg', 'topic.move', $move('t', $target));

        return [$cell, acKinds($w->db->log)];
    };
    [$missing, $missingLog] = $shape(99999);
    foreach (['fh' => 'verborgen', 'fi' => 'Rechte-Zeilen', 'fk' => 'Kategorie unsichtbar'] as $key => $label) {
        [$cell, $log] = $shape($key);
        T::same(['not_found', 'not_found'], [$missing, $cell], 'Gruppenmoderator (nur fo): fehlendes und unsichtbares Ziel (' . $label . ') = not_found');
        T::same($missingLog, $log, 'gleiche Abfragefolge für fehlendes und unsichtbares Ziel (' . $label . ', ' . count($log) . ' Abfragen)');
    }
    T::same('no_permission', $w->cell('fmg', 'topic.move', $move('t', 'fr')), 'Gruppenmoderator: sichtbares, nicht moderiertes Ziel = no_permission');
    T::same(404, $w->can('fm', 'topic.move', $move('t', 'fi'))->status(), 'unsichtbares Ziel meldet 404');
    T::same(422, $w->can('fm', 'topic.move', $move('t', 'fo'))->status(), 'invalid_target meldet 422');
});

T::run('Melden eines Mitglieds (report mit member_id): Ziel aktives Konto, nicht man selbst; unbekannt und inaktiv gleich (E1)', static function (): void {
    $w = acStandardWorld();
    acMatrix($w, 'report Mitglied m1', 'report', ['member_id' => $w->u['m1']], acW(['*' => 'ok', 'M1' => 'no_permission']));
    acMatrix($w, 'report Mitglied, ID als Ziffernfolge', 'report', ['member_id' => (string) $w->u['m2']], acW(['*' => 'ok', 'M2' => 'no_permission']));
    acMatrix($w, 'report der Administration', 'report', ['member_id' => $w->u['admin']], acW(['*' => 'ok', 'AD' => 'no_permission']));
    $unknown = [
        'unbekannt' => 987654, 'nicht aktiv' => $w->u['inactive'], 'null' => 0, 'negativ' => -3, 'Text' => 'abc', 'Ziffern mit Rest' => $w->u['m2'] . 'x',
        'Array' => [$w->u['m2']], 'leer (null)' => null, 'Kommazahl' => 2.0, 'Köder' => CommunityAccess::DECOY_ID,
    ];
    foreach ($unknown as $label => $id) {
        T::same('not_found', $w->cell('m1', 'report', ['member_id' => $id]), 'Ziel ' . $label . ': not_found');
    }
    foreach (['post_id' => $w->p['p'], 'topic_id' => $w->t['t'], 'forum_id' => $w->f['fo'], 'post' => null] as $key => $value) {
        T::same('not_found', $w->cell('m1', 'report', ['member_id' => $w->u['m2'], $key => $value]), 'member_id mit weiterem Zielschlüssel ' . $key . ': not_found');
    }
    T::same('ok', $w->cell('m1', 'report', ['member_id' => $w->u['m2'], 'now' => $w->now, 'fresh' => true]), 'now und fresh sind keine Zielschlüssel');
    T::same('ok', $w->cell('m1', 'report', ['post_id' => $w->p['p']]), 'report ohne member_id: Beitrag wie bisher');

    // Unbekannt und inaktiv: dieselbe Abfragefolge
    $shape = static function (mixed $id) use ($w): array {
        $w->access->flush();
        $w->db->log = [];
        $cell = $w->cell('m1', 'report', ['member_id' => $id]);

        return [$cell, acKinds($w->db->log)];
    };
    T::same($shape(987654), $shape($w->u['inactive']), 'unbekanntes und nicht aktives Konto: gleiche Antwort und gleiche Abfragefolge');
    T::same($shape('abc'), $shape($w->u['inactive']), 'ungültige ID und nicht aktives Konto: dto. (Köder-ID)');

    // fresh liest das Zielkonto neu; der Filter läuft wie immer
    T::same('ok', $w->cell('m1', 'report', ['member_id' => $w->u['m3']]), 'Ausgangslage: m3 meldbar');
    $w->sql('UPDATE cms_users SET status = ? WHERE id = ?', ['inactive', $w->u['m3']]);
    T::same('not_found', $w->cell('m1', 'report', ['member_id' => $w->u['m3'], 'fresh' => true]), 'fresh: das inzwischen deaktivierte Konto ist nicht mehr meldbar');
    $w->sql('UPDATE cms_users SET status = ? WHERE id = ?', ['active', $w->u['m3']]);
    $w->access->flush();
    $seen = [];
    $filter = static function (AccessDecision $d, int $uid, string $action, array $ctx) use (&$seen): AccessDecision {
        $seen[] = [$action, $ctx['member_id'] ?? null];

        return $action === 'report' ? AccessDecision::deny('plugin') : $d;
    };
    Hooks::addFilter(Events::CAN, $filter);
    try {
        T::same('filter_denied', $w->cell('m1', 'report', ['member_id' => $w->u['m2']]), 'Filter cms_community_can lehnt das Melden eines Mitglieds ab: filter_denied');
        T::same([['report', $w->u['m2']]], $seen, 'der Filter sieht Aktion und member_id');
        $seen = [];
        T::same('no_permission', $w->cell('m1', 'report', ['member_id' => $w->u['m1']]), 'Ablehnung vor dem Filter: der Filter läuft nicht');
        T::same([], $seen, 'kein Filteraufruf bei Ablehnung');
    } finally {
        Hooks::removeFilter(Events::CAN, $filter);
    }
});

T::run('Rang: authority() aus users.role (Status egal, je Aufruf gelesen), outranks() nur bei höherem Rang, Fehler fail-closed', static function (): void {
    $w = acStandardWorld();
    $w->member('mgr', ['role' => 'manager']);
    $w->member('mgo', ['role' => 'manageronly']);
    $w->member('gm2', ['role' => 'moderator']);
    $w->member('admin2', ['role' => 'admin']);
    $w->member('gmia', ['role' => 'moderator', 'status' => 'inactive']);
    $w->member('adia', ['role' => 'admin', 'status' => 'banned']);
    T::same([0, 1, 2, 3, 4], [CommunityAccess::AUTHORITY_NONE, CommunityAccess::AUTHORITY_MEMBER, CommunityAccess::AUTHORITY_MODERATOR, CommunityAccess::AUTHORITY_MANAGER, CommunityAccess::AUTHORITY_ADMIN], 'Rangstufen 0 bis 4');
    $expect = [
        'guest' => 0, 'm0' => 1, 'm1' => 1, 'fm' => 1, 'fmg' => 1, 'grp' => 1, 'nc' => 1, 'vw' => 1, 'bf' => 1, 'inactive' => 1,
        'gm' => 2, 'gm2' => 2, 'gmia' => 2, 'mgr' => 3, 'mgo' => 3, 'admin' => 4, 'admin2' => 4, 'adia' => 4,
    ];
    foreach ($expect as $name => $level) {
        T::same($level, $w->access->authority($w->u[$name]), 'authority(' . $name . ') = ' . $level);
    }
    T::same([0, 0, 0, 0], [$w->access->authority(0), $w->access->authority(-5), $w->access->authority(987654), $w->access->authority(null)], 'authority: 0, negativ, unbekannt, null = AUTHORITY_NONE');

    // Kein Konto-Zwischenspeicher
    $w->cell('m1', 'read', ['topic_id' => $w->t['t']]);
    $w->sql('UPDATE cms_users SET role = ? WHERE id = ?', ['moderator', $w->u['m1']]);
    T::same(2, $w->access->authority($w->u['m1']), 'neue Rolle gilt sofort (authority liest das Konto je Aufruf, ohne forgetUser)');
    $w->sql('UPDATE cms_users SET role = ? WHERE id = ?', ['member', $w->u['m1']]);

    $wrong = [];
    foreach (array_keys($expect) as $a) {
        foreach (array_keys($expect) as $b) {
            $want = $a !== $b && $w->u[$a] !== null && $expect[$a] > $expect[$b];
            if ($w->access->outranks((int) $w->u[$a], (int) $w->u[$b]) !== $want) {
                $wrong[] = $a . '>' . $b;
            }
        }
    }
    T::same([], $wrong, 'outranks für alle Paare: nur höherer Rang; nie gegen sich selbst; Gleichrangige (Moderator/Moderator, Admin/Admin) nicht');
    T::same([false, false], [$w->access->outranks($w->u['admin'], $w->u['admin']), $w->access->outranks(0, $w->u['m1'])], 'Diagonale und Akteur 0: false');

    // Fehler: null bzw. false
    $w->rolesFail = true;
    $w->access->flush();
    T::same(null, $w->access->authority($w->u['m1']), 'Rollenabfrage wirft: authority = null');
    T::same(4, $w->access->authority($w->u['admin']), 'admin braucht die Rollenabfrage nicht');
    T::same(false, $w->access->outranks($w->u['admin'], $w->u['m1']), 'Fehler beim Ziel: outranks = false');
    T::same(false, $w->access->outranks($w->u['gm'], 0), 'Fehler beim Akteur: outranks = false');
    $w->cell('m1', 'read', ['topic_id' => $w->t['t']]);
    T::same(null, $w->access->authority($w->u['m1']), 'auch nach einem gemerkten Fehler der Rechteprüfung in can(): null, nie Rang 1');
    $w->rolesFail = false;
    $w->access->flush();
    T::same(1, $w->access->authority($w->u['m1']), 'Rollenabfrage wieder erreichbar');
    $w->db->failOn = ['/FROM cms_users/'];
    T::same([null, false], [$w->access->authority($w->u['gm']), $w->access->outranks($w->u['admin'], $w->u['gm'])], 'Datenbankfehler: null und false');
    $w->db->failOn = [];
    $w->db->log = [];
    $w->access->outranks($w->u['admin'], $w->u['m1']);
    T::same([], array_values(preg_grep('/^\s*(INSERT|UPDATE|DELETE)\b|FOR UPDATE/i', $w->db->log) ?: []), 'authority/outranks lesen nur');
});

T::run('Moderationsbereich: moderationScope() je Nutzerklasse, Kinderregel, Filter, archivierte Foren bleiben, Kontext nur now/fresh', static function (): void {
    $w = acStandardWorld();
    foreach (['fmi', 'fme', 'fmbw', 'fmbs', 'fgs'] as $name) {
        $w->member($name, ['posts' => 5]);
    }
    $w->member('fmuv', ['posts' => 5, 'verified' => false]);
    $w->member('fmnc', ['role' => 'nocap', 'posts' => 5]);
    $w->member('fmvw', ['role' => 'viewer', 'posts' => 5]);
    $w->db->ban(['user' => $w->u['fmbw'], 'scope' => 'write']);
    $w->db->ban(['user' => $w->u['fmbs'], 'scope' => 'write', 'starts_at' => $w->utc(-60), 'ends_at' => $w->utc(60)]);
    $w->g['scope'] = $w->db->group('Bereich');
    $w->db->join($w->u['fgs'], $w->g['scope']);
    $w->forum('Sp');
    $w->forum('Sc', ['parent' => 'Sp']);
    $w->forum('Sd', ['parent' => 'Sc', 'status' => 'archived']);
    $w->forum('Sx');
    $w->moderators('Sp', [
        ['subject_type' => 'user', 'subject_id' => $w->u['fmi'], 'include_children' => 1],
        ['subject_type' => 'user', 'subject_id' => $w->u['fme'], 'include_children' => 0],
        ['subject_type' => 'user', 'subject_id' => $w->u['fmuv'], 'include_children' => 1],
        ['subject_type' => 'user', 'subject_id' => $w->u['fmbw'], 'include_children' => 1],
        ['subject_type' => 'user', 'subject_id' => $w->u['fmbs'], 'include_children' => 0],
        ['subject_type' => 'user', 'subject_id' => $w->u['fmnc'], 'include_children' => 1],
        ['subject_type' => 'user', 'subject_id' => $w->u['fmvw'], 'include_children' => 0],
    ]);
    $w->moderators('Sx', [['subject_type' => 'group', 'subject_id' => $w->g['scope'], 'include_children' => 0]]);
    $all = array_keys((new ForumRepository($w->db))->tree());
    $in = static function (array $keys) use ($w): array {
        $tree = array_keys((new ForumRepository($w->db))->tree());

        return array_values(array_intersect($tree, array_map(static fn (string $k): int => $w->f[$k], $keys)));
    };
    $scope = static fn (string $name, array $ctx = []): array => $w->access->moderationScope($w->u[$name], $ctx);
    $none = ['global' => false, 'forum_ids' => []];

    T::same(['global' => true, 'forum_ids' => $all], $scope('gm'), 'globale Moderation: global, alle Foren in Baumreihenfolge');
    T::same(['global' => true, 'forum_ids' => $all], $scope('admin'), 'admin: global, alle Foren');
    T::same(['global' => false, 'forum_ids' => $in(['fo', 'fr', 'fa', 'fh', 'fl', 'fn', 'fq'])], $scope('fm'), 'Forum-Moderator: seine Foren, auch archivierte und verborgene');
    T::same(['global' => false, 'forum_ids' => $in(['fo'])], $scope('fmg'), 'Moderator über eine Gruppe');
    T::same(['global' => false, 'forum_ids' => $in(['Sp', 'Sc', 'Sd'])], $scope('fmi'), 'include_children = 1: Forum und alle Unterforen (archiviertes Unterforum bleibt)');
    T::same(['global' => false, 'forum_ids' => $in(['Sp'])], $scope('fme'), 'include_children = 0: nur das Forum');
    T::same(['global' => false, 'forum_ids' => $in(['Sx'])], $scope('fgs'), 'Gruppenmoderator');
    T::same(['global' => false, 'forum_ids' => $in(['Sp'])], $scope('fmvw'), 'Forum-Moderator nur mit community.view: moderiert sein Forum (wie can)');
    foreach (['guest', 'inactive', 'm0', 'm1', 'own', 'grp', 'vw', 'nc', 'bf', 'bw', 'fmuv', 'fmbw', 'fmnc'] as $name) {
        T::same($none, $scope($name), $name . ': leerer Bereich (kein Moderator, Gast, Sperre, E-Mail-Pflicht oder ohne community.view)');
    }
    $w->sql('UPDATE cms_user_groups SET is_active = 0 WHERE id = ?', [$w->g['scope']]);
    $w->access->forgetUser($w->u['fgs']);
    T::same($none, $scope('fgs'), 'inaktive Gruppe: kein Bereich');
    $w->sql('UPDATE cms_user_groups SET is_active = 1 WHERE id = ?', [$w->g['scope']]);
    $w->access->forgetUser($w->u['fgs']);

    // Kontext: nur now und fresh; ein Ziel macht aus einem Forum-Moderator keine globale Moderation
    T::same($scope('fm'), $scope('fm', ['forum_id' => $w->f['fo']]), 'forum_id im Kontext wird ignoriert');
    T::same($scope('fm'), $scope('fm', ['topic_id' => $w->t['t'], 'scope' => 'global']), 'topic_id und scope im Kontext werden ignoriert');
    T::same($none, $scope('fmbs', ['now' => $w->now]), 'now: befristete Sperre wirkt');
    T::same(['global' => false, 'forum_ids' => $in(['Sp'])], $scope('fmbs', ['now' => $w->now + 120]), 'now nach dem Ende der Sperre: Bereich wieder da');

    // Modul aus
    $w->moduleOn = false;
    foreach (['gm', 'admin', 'fm'] as $name) {
        T::same($none, $scope($name), 'Modul aus: ' . $name . ' ohne Bereich');
    }
    $w->moduleOn = true;

    // Filter cms_community_can mit moderate je Forum
    $hidden = $w->f['fh'];
    $filter = static fn (AccessDecision $d, int $uid, string $action, array $ctx): AccessDecision => $action === 'moderate' && ($ctx['forum_id'] ?? null) === $hidden ? AccessDecision::deny('plugin') : $d;
    Hooks::addFilter(Events::CAN, $filter);
    try {
        T::same(['global' => false, 'forum_ids' => array_values(array_diff($all, [$hidden]))], $scope('gm'), 'Filter entfernt ein Forum: global wird false, das Forum fehlt');
        T::same(['global' => false, 'forum_ids' => $in(['fo', 'fr', 'fa', 'fl', 'fn', 'fq'])], $scope('fm'), 'Filter entfernt ein Forum des Forum-Moderators');
    } finally {
        Hooks::removeFilter(Events::CAN, $filter);
    }
    $gate = static fn (AccessDecision $d, int $uid, string $action, array $ctx): AccessDecision => $action === 'moderate' && !isset($ctx['forum_id']) ? AccessDecision::deny('plugin') : $d;
    Hooks::addFilter(Events::CAN, $gate);
    try {
        T::same($none, $scope('gm'), 'Filter lehnt moderate ohne Ziel ab: leerer Bereich');
    } finally {
        Hooks::removeFilter(Events::CAN, $gate);
    }

    // Gleiche Antwort wie can('moderate', forum) je Forum (archivierte Foren bleiben im Bereich, Vertrag K6)
    $names = array_merge(array_values(AC_COLS), ['fmg', 'fmi', 'fme', 'fmbw', 'fmuv', 'fmnc', 'fmvw', 'fgs']);
    foreach ($names as $name) {
        $fromCan = [];
        foreach ($all as $forumId) {
            $decision = $w->can($name, 'moderate', ['forum_id' => $forumId]);
            if ($decision->allowed || $decision->reason === 'forum_archived') {
                $fromCan[] = $forumId;
            }
        }
        $w->access->flush();
        T::same($fromCan, $scope($name)['forum_ids'], $name . ': forum_ids = can(moderate, forum) je Forum (dazu archivierte)');
    }

    // fresh: Konto und Forenbaum werden neu gelesen
    T::same(['global' => false, 'forum_ids' => $in(['Sp', 'Sc', 'Sd'])], $scope('fmi'), 'Ausgangslage (Baum zwischengespeichert)');
    $w->forum('Se', ['parent' => 'Sp']);
    T::same(['global' => false, 'forum_ids' => $in(['Sp', 'Sc', 'Sd'])], $scope('fmi'), 'neues Unterforum ohne fresh: zwischengespeicherter Baum');
    T::same(['global' => false, 'forum_ids' => $in(['Sp', 'Sc', 'Sd', 'Se'])], $scope('fmi', ['fresh' => true]), 'fresh: das neue Unterforum gehört zum Bereich');
    $w->sql('UPDATE cms_users SET role = ? WHERE id = ?', ['nocap', $w->u['fmi']]);
    T::same(['global' => false, 'forum_ids' => $in(['Sp', 'Sc', 'Sd', 'Se'])], $scope('fmi'), 'Rollenwechsel ohne fresh: zwischengespeicherte Rolle');
    T::same($none, $scope('fmi', ['fresh' => true]), 'fresh: ohne community.view kein Bereich');
    $w->sql('UPDATE cms_users SET role = ? WHERE id = ?', ['member', $w->u['fmi']]);
    $w->access->flush();

    // Fehler: leer, nichts geschrieben
    $w->db->failOn = ['/community_forum_moderators/'];
    T::same($none, $scope('fm'), 'Datenbankfehler bei den Moderatoren: leerer Bereich (fail-closed)');
    $w->db->failOn = [];
    $w->access->flush();
    $w->db->log = [];
    $scope('fm');
    $scope('gm');
    T::same([], array_values(preg_grep('/^\s*(INSERT|UPDATE|DELETE)\b|FOR UPDATE/i', $w->db->log) ?: []), 'moderationScope liest nur');
    T::check(count(preg_grep('/community_forum_moderators/', $w->db->log) ?: []) === 1, 'Forum-Moderator: eine Abfrage der Zuordnungen für den ganzen Baum');
});

T::run('Inhaltsgrenzen der Stufe (content, content_before): Grenze selbst erlaubt, -1 unbegrenzt, 0 keine, Moderation ausgenommen, fail-closed', static function (): void {
    $w = acStandardWorld(['rate_post_seconds' => 0, 'rate_topic_seconds' => 0, 'new_member_approval_posts' => 0]);
    $w->forum('fx');
    $c = static fn (mixed $links, mixed $images = 0, mixed $attachments = 0): array => ['links' => $links, 'images' => $images, 'attachments' => $attachments];
    foreach (['create_topic' => ['forum_id' => $w->f['fo']], 'reply' => ['topic_id' => $w->t['t']]] as $action => $ctx) {
        $cell = static fn (string $name, mixed $content) => $w->cell($name, $action, $ctx + ['content' => $content]);
        // Stufe 0 (m0): 1 Link, 0 Bilder, 0 Anhänge
        T::same('ok:published', $cell('m0', $c(1, 0, 0)), $action . ', Stufe 0: ein Link (die Grenze selbst) erlaubt');
        T::same('too_many_links', $cell('m0', $c(2)), $action . ', Stufe 0: zwei Links');
        T::same('too_many_images', $cell('m0', $c(0, 1)), $action . ', Stufe 0: Bilder 0 = keine');
        T::same('too_many_attachments', $cell('m0', $c(0, 0, 1)), $action . ', Stufe 0: Anhänge 0 = keine');
        // Stufe 1 (m1): 5 Links, 10 Bilder, 3 Anhänge
        T::same('ok:published', $cell('m1', $c(5, 10, 3)), $action . ', Stufe 1: alle Grenzen genau erreicht');
        T::same('too_many_links', $cell('m1', $c(6, 10, 3)), $action . ', Stufe 1: sechs Links');
        T::same('too_many_images', $cell('m1', $c(5, 11, 3)), $action . ', Stufe 1: elf Bilder');
        T::same('too_many_attachments', $cell('m1', $c(5, 10, 4)), $action . ', Stufe 1: vier Anhänge');
        // Stufe 2 (m2): unbegrenzt, unbegrenzt, 3
        T::same('ok:published', $cell('m2', $c(100000, 100000, 3)), $action . ', Stufe 2: Links und Bilder unbegrenzt (-1)');
        T::same('too_many_attachments', $cell('m2', $c(0, 0, 4)), $action . ', Stufe 2: Anhänge weiter begrenzt');
        T::same('too_many_links', $cell('m0', $c(9, 9, 9)), $action . ': Links vor Bildern vor Anhängen');
        T::same('too_many_images', $cell('m0', $c(1, 9, 9)), $action . ': Bilder vor Anhängen');
        foreach (['fm', 'gm', 'admin'] as $mod) {
            T::same('ok:published', $cell($mod, $c(999, 999, 999)), $action . ': ' . $mod . ' (Moderation) ist ausgenommen');
        }
        T::same('ok:published', $w->cell('m0', $action, $ctx), $action . ': ohne content keine Prüfung (Menüabfrage)');
        $failClosed = [
            'negativ' => [$c(-1), 'too_many_links'], 'Text' => [$c(0, '2'), 'too_many_images'], 'Anhänge fehlen' => [['links' => 0, 'images' => 0], 'too_many_attachments'],
            'Kommazahl' => [$c(1.0), 'too_many_links'], 'true' => [$c(true), 'too_many_links'], 'null-Wert' => [$c(null), 'too_many_links'],
            'kein Array' => ['x', 'too_many_links'], 'null' => [null, 'too_many_links'], 'leeres Array' => [[], 'too_many_links'], 'Überlauf' => [$c(PHP_INT_MAX), 'too_many_links'],
        ];
        foreach ($failClosed as $label => [$content, $reason]) {
            T::same($reason, $cell('m2', $content), $action . ', Stufe 2 (Links unbegrenzt): ungültiger Inhalt (' . $label . ') wird abgelehnt (fail-closed)');
        }
        T::same('too_many_links', $w->cell('m0', $action, $ctx + ['content' => $c(3), 'content_before' => $c(5)]), $action . ': content_before beim Anlegen ignoriert');
    }
    T::same('too_many_links', $w->cell('fm', 'create_topic', ['forum_id' => $w->f['fx'], 'content' => $c(6)]), 'Forum-Moderator außerhalb seines Forums: Grenzen der Stufe gelten');
    T::same('ok', $w->cell('m0', 'react', ['post_id' => $w->p['p'], 'content' => $c(99, 99, 99)]), 'react kennt keine Inhaltsgrenzen');
    T::same('ok', $w->cell('m0', 'upload', ['forum_id' => $w->f['fo'], 'content' => 'x']), 'upload kennt keine Inhaltsgrenzen');

    // Bearbeiten: je Feld max(Grenze, Vorfassung); ungültige Vorfassung zählt 0 (own: Stufe 1, 5 Links, 10 Bilder)
    $edit = static fn (mixed $content, mixed $before = null): string => $w->cell('own', 'edit', ['post_id' => $w->p['p'], 'content' => $content] + ($before === null ? [] : ['content_before' => $before]));
    T::same('ok', $edit($c(5)), 'edit: Grenze erreicht');
    T::same('too_many_links', $edit($c(6)), 'edit ohne Vorfassung: sechs Links');
    T::same('ok', $edit($c(6), $c(6)), 'edit: Vorfassung hatte schon sechs Links (Korrektur möglich)');
    T::same('ok', $edit($c(7), $c(9)), 'edit: weniger Links als vorher');
    T::same('too_many_links', $edit($c(7), $c(6)), 'edit: mehr Links als Grenze und Vorfassung');
    T::same('too_many_images', $edit($c(0, 12), $c(0, 11)), 'edit: Bilder über Grenze und Vorfassung');
    foreach (['Text' => 'x', 'negativ' => $c(-9), 'Links fehlen' => ['images' => 0, 'attachments' => 0], 'Text-Zahl' => $c('9')] as $label => $before) {
        T::same('too_many_links', $edit($c(6), $before), 'edit: ungültige Vorfassung (' . $label . ') zählt 0');
    }
    T::same('ok', $w->cell('fm', 'edit', ['post_id' => $w->p['p'], 'content' => $c(99, 99, 99)]), 'Moderation bearbeitet fremde Beiträge ohne Inhaltsgrenzen');
    T::same('too_many_links', $w->cell('own', 'edit', ['post_id' => $w->p['pp'], 'content' => $c(6)]), 'wartender Beitrag: Grenzen gelten');
    T::same('edit_window', $w->cell('own', 'edit', ['post_id' => $w->p['pold'], 'content' => $c(99)]), 'Bearbeitungsfenster vor den Inhaltsgrenzen');
    T::same(422, $w->can('m0', 'reply', ['topic_id' => $w->t['t'], 'content' => $c(2)])->status(), 'too_many_links meldet 422');

    // Reine Prüfung vor den Zählabfragen (Kontingente laufen zuletzt)
    $w->access->flush();
    $w->db->log = [];
    $w->can('m0', 'reply', ['topic_id' => $w->t['t'], 'content' => $c(2)]);
    T::same([], array_values(preg_grep('/COUNT\(/i', $w->db->log) ?: []), 'Ablehnung durch Inhaltsgrenze: keine Zählabfrage');
    $w->db->log = [];
    $w->can('m0', 'reply', ['topic_id' => $w->t['t'], 'content' => $c(1)]);
    T::check((preg_grep('/COUNT\(/i', $w->db->log) ?: []) !== [], 'Gegenprobe: erlaubter Inhalt läuft bis zu den Kontingenten');
});

T::run('Prüfpflicht (review): wartend statt veröffentlicht, Vorrang vor bypass_approval, nicht für Moderation; Bearbeiten mit Prüfpflicht = content_review (I-33)', static function (): void {
    $w = acStandardWorld(['rate_post_seconds' => 0, 'rate_topic_seconds' => 0]);
    $reply = ['topic_id' => $w->t['t']];
    T::same('ok:published', $w->cell('m1', 'reply', $reply), 'Ausgangslage: veröffentlicht');
    T::same('ok:pending', $w->cell('m1', 'reply', $reply + ['review' => true]), 'review: wartend');
    T::same('ok:pending', $w->cell('m1', 'create_topic', ['forum_id' => $w->f['fo'], 'review' => true]), 'review beim Thema: wartend');
    foreach (['Text 1' => '1', 'Zahl 1' => 1, 'Text true' => 'true', 'false' => false, 'null' => null, 'Array' => [true]] as $label => $loose) {
        T::same('ok:published', $w->cell('m1', 'reply', $reply + ['review' => $loose]), 'review wirkt nur bei === true (' . $label . ')');
    }
    $w->forum('frc');
    $w->permissions('frc', [['subject_type' => 'trust', 'subject_key' => '0', 'permissions' => ['read' => 1, 'reply' => 1, 'create_topic' => 1, 'bypass_approval' => 1]]]);
    $w->topic('tfrc', 'frc');
    T::same('ok:published', $w->cell('m0', 'reply', ['topic_id' => $w->t['tfrc']]), 'bypass_approval: veröffentlicht');
    T::same('ok:pending', $w->cell('m0', 'reply', ['topic_id' => $w->t['tfrc'], 'review' => true]), 'review geht bypass_approval vor');
    foreach (['fm', 'gm', 'admin'] as $mod) {
        T::same('ok:published', $w->cell($mod, 'reply', $reply + ['review' => true]), $mod . ': Moderation geht review vor');
    }
    $w->member('queue2', ['posts' => 5]);
    for ($i = 0; $i < 3; $i++) {
        $w->post('rq' . $i, 't', ['by' => 'queue2', 'status' => 'pending', 'age' => 200000 + $i]);
    }
    T::same('ok:published', $w->cell('queue2', 'reply', $reply), 'drei wartende Beiträge, ohne review: veröffentlicht, kein Deckel');
    T::same('pending_limit', $w->cell('queue2', 'reply', $reply + ['review' => true]), 'mit review: der Deckel für wartende Beiträge greift');

    $edit = ['post_id' => $w->p['p']];
    T::same('content_review', $w->cell('own', 'edit', $edit + ['review' => true]), 'edit mit review an einem veröffentlichten Beitrag: content_review');
    T::same(422, $w->can('own', 'edit', $edit + ['review' => true])->status(), 'content_review meldet 422');
    T::same('ok', $w->cell('own', 'edit', ['post_id' => $w->p['pp'], 'review' => true]), 'edit mit review an einem wartenden Beitrag: erlaubt (bleibt wartend)');
    T::same('ok', $w->cell('own', 'edit', $edit + ['review' => '1']), 'edit: review nur bei === true');
    foreach (['fm', 'gm', 'admin'] as $mod) {
        T::same('ok', $w->cell($mod, 'edit', $edit + ['review' => true]), $mod . ': Moderation ohne content_review');
    }
    T::same('too_many_links', $w->cell('own', 'edit', $edit + ['review' => true, 'content' => ['links' => 6, 'images' => 0, 'attachments' => 0]]), 'Inhaltsgrenze vor content_review');
    T::same(null, $w->can('own', 'edit', $edit)->initialStatus, 'edit liefert keinen Anfangsstatus');
});

T::run('Reagieren nur auf veröffentlichte Beiträge in veröffentlichten Themen, auch für die Moderation', static function (): void {
    $w = acStandardWorld();
    $w->post('pth3', 'th', ['by' => 'm3', 'first' => false]);
    $w->post('ptp3', 'tp', ['by' => 'm3', 'first' => false]);
    $mods = ['FM' => 'no_permission', 'GM' => 'no_permission', 'AD' => 'no_permission'];
    acMatrix($w, 'react auf veröffentlichten Beitrag (Kontrolle)', 'react', ['post_id' => $w->p['p']], acW(['*' => 'ok', 'OWN' => 'no_permission']));
    acMatrix($w, 'react auf ausgeblendeten Beitrag (Platzhalter)', 'react', ['post_id' => $w->p['ph']], acW(['*' => 'no_permission']));
    acMatrix($w, 'react auf gelöschten Beitrag (Platzhalter)', 'react', ['post_id' => $w->p['pd']], acW(['*' => 'no_permission']));
    acMatrix($w, 'react auf wartenden Beitrag', 'react', ['post_id' => $w->p['pp']], acW(['*' => 'not_found', 'OWN' => 'no_permission'] + $mods));
    acMatrix($w, 'react auf abgelehnten Beitrag', 'react', ['post_id' => $w->p['pr']], acW(['*' => 'not_found', 'OWN' => 'no_permission'] + $mods));
    acMatrix($w, 'react im ausgeblendeten Thema', 'react', ['post_id' => $w->p['pth3']], acW(['*' => 'not_found', 'OWN' => 'no_permission'] + $mods));
    acMatrix($w, 'react im wartenden Thema (der Themenautor sieht es: topic_pending aus dem Themenzustand)', 'react', ['post_id' => $w->p['ptp3']], acW(['*' => 'not_found', 'OWN' => 'topic_pending'] + $mods));
    acMatrix($w, 'Platzhalter bleiben lesbar', 'read', ['post_id' => $w->p['ph']], acR(['*' => 'ok:placeholder', 'OWN' => 'ok:full', 'FM' => 'ok:full', 'GM' => 'ok:full', 'AD' => 'ok:full']));
});

T::run('Kategorie: unsichtbare oder fehlende Kategorie verbirgt das Forum und seine Unterforen; category_id = 0 sichtbar; Moderation sieht sie', static function (): void {
    $w = acStandardWorld(['guests_can_read' => true]);
    $visible = $w->db->category(['slug' => 'sichtbar']);
    $hidden = $w->db->category(['slug' => 'intern', 'visible' => false]);
    $w->forum('kv', ['category' => $visible]);
    $w->forum('kh', ['category' => $hidden]);
    $w->forum('km', ['category' => 987654]);
    $w->forum('k0');
    $w->forum('khs', ['parent' => 'kh', 'category' => $visible]);
    $w->forum('kvs', ['parent' => 'kv']);
    $w->moderators('kh', [['subject_type' => 'user', 'subject_id' => $w->u['fm'], 'include_children' => 1]]);
    foreach (['kv', 'kh', 'km', 'k0', 'khs', 'kvs'] as $key) {
        $w->topic('t' . $key, $key);
    }
    $readable = ['kv' => true, 'kh' => false, 'km' => false, 'k0' => true, 'khs' => false, 'kvs' => true];
    $labels = ['kv' => 'sichtbare Kategorie', 'kh' => 'unsichtbare Kategorie', 'km' => 'fehlende Kategorie', 'k0' => 'ohne Kategorie (0)', 'khs' => 'Unterforum eines Forums mit unsichtbarer Kategorie', 'kvs' => 'Unterforum, sichtbar'];
    foreach ($readable as $key => $yes) {
        foreach (['guest', 'm1', 'own', 'grp', 'vw', 'fmg'] as $name) {
            T::same($yes ? 'ok' : 'not_found', $w->cell($name, 'read', ['forum_id' => $w->f[$key]]), $labels[$key] . ': ' . $name . ' liest das Forum ' . ($yes ? '' : 'nicht'));
            T::same($yes ? 'ok' : 'not_found', $w->cell($name, 'read', ['topic_id' => $w->t['t' . $key]]), $labels[$key] . ': ' . $name . ' liest das Thema ' . ($yes ? '' : 'nicht'));
        }
        foreach (['gm', 'admin'] as $mod) {
            T::same('ok', $w->cell($mod, 'read', ['forum_id' => $w->f[$key]]), $labels[$key] . ': ' . $mod . ' sieht es');
        }
    }
    T::same(['ok', 'ok', 'not_found'], [$w->cell('fm', 'read', ['forum_id' => $w->f['kh']]), $w->cell('fm', 'read', ['forum_id' => $w->f['khs']]), $w->cell('fm', 'read', ['forum_id' => $w->f['km']])], 'Forum-Moderator sieht sein Forum in der unsichtbaren Kategorie samt Unterforum, nicht fremde');
    T::same(['not_found', 'ok:published', 'ok:published'], [$w->cell('m1', 'create_topic', ['forum_id' => $w->f['kh']]), $w->cell('fm', 'create_topic', ['forum_id' => $w->f['kh']]), $w->cell('gm', 'create_topic', ['forum_id' => $w->f['kh']])], 'Thema anlegen: Mitglied not_found, Moderation erlaubt');
    $all = array_keys((new ForumRepository($w->db))->tree());
    foreach (['guest', 'm1', 'vw', 'fm', 'fmg', 'gm'] as $name) {
        $fromCan = [];
        foreach ($all as $forumId) {
            if ($w->can($name, 'read', ['forum_id' => $forumId])->allowed) {
                $fromCan[] = $forumId;
            }
        }
        $w->access->flush();
        T::same($fromCan, $w->access->readableForumIds($w->u[$name]), $name . ': Mengen-API = can(read) mit Kategorie-Regel');
    }
    // Zwischenspeicher: der Baum trägt die Sichtbarkeit; fresh bzw. flush() liest sie neu
    T::same('not_found', $w->cell('m1', 'read', ['forum_id' => $w->f['kh']]), 'Ausgangslage: unsichtbar');
    $w->sql('UPDATE cms_community_categories SET is_visible = 1 WHERE id = ?', [$hidden]);
    T::same('not_found', $w->cell('m1', 'read', ['forum_id' => $w->f['kh']]), 'Kategorie sichtbar geschaltet, ohne fresh: zwischengespeicherter Baum (die Verwaltung ruft flush(), PR #3)');
    T::same('ok', acCell($w->access->can($w->u['m1'], 'read', ['forum_id' => $w->f['kh'], 'fresh' => true])), 'fresh liest die Kategorie neu');
    T::same('ok', $w->cell('m1', 'read', ['forum_id' => $w->f['khs']]), 'auch das Unterforum ist wieder sichtbar');
    $w->sql('DELETE FROM cms_community_categories WHERE id = ?', [$visible]);
    $w->access->flush();
    T::same(['not_found', 'not_found', 'ok'], [$w->cell('m1', 'read', ['forum_id' => $w->f['kv']]), $w->cell('m1', 'read', ['forum_id' => $w->f['kvs']]), $w->cell('gm', 'read', ['forum_id' => $w->f['kv']])], 'gelöschte Kategorie: Forum und Unterforum verborgen (fail-closed), Moderation sieht sie');
});

T::run('Mengen-API: höchstens zehn Abfragen mit Rechte-Zeilen bei 50 und bei 200 Foren; Ergebnis = can(read) für alle Klassen; can() behält seine Abfragen', static function (): void {
    $build = static function (int $count): AcWorld {
        $w = acStandardWorld(['guests_can_read' => true]);
        $w->g['bulk'] = $w->db->group('Mengen');
        $w->g['off'] = $w->db->group('Aus', false);
        $w->member('batch', ['posts' => 25, 'groups' => [$w->g['bulk'], $w->g['off']]]);
        $w->member('bfm', ['posts' => 5]);
        $w->member('bfm0', ['posts' => 5]);
        $w->member('bgm', ['posts' => 5, 'groups' => [$w->g['bulk']]]);
        $w->member('boff', ['posts' => 5, 'groups' => [$w->g['off']]]);
        for ($r = 0; $r < intdiv($count, 5); $r++) {
            $w->forum('B' . $r, ['status' => $r % 7 === 3 ? 'hidden' : 'open']);
            for ($c = 0; $c < 4; $c++) {
                $w->forum('B' . $r . 'c' . $c, ['parent' => 'B' . $r, 'inherit' => !($c === 3 && $r % 2 === 0)]);
            }
            if ($r % 4 === 0) {
                $w->permissions('B' . $r, [
                    ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1]],
                    ['subject_type' => 'group', 'subject_key' => (string) $w->g['bulk'], 'permissions' => ['read' => 1]],
                ]);
            } elseif ($r % 4 === 1) {
                $w->permissions('B' . $r, [['subject_type' => 'trust', 'subject_key' => '2', 'permissions' => ['read' => 1]]]);
            } elseif ($r % 4 === 2) {
                $w->permissions('B' . $r . 'c1', [['subject_type' => 'group', 'subject_key' => (string) $w->g['off'], 'permissions' => ['read' => 1]]]);
            }
            if ($r % 5 === 3) {
                $w->moderators('B' . $r, [
                    ['subject_type' => 'user', 'subject_id' => $w->u['bfm'], 'include_children' => 1],
                    ['subject_type' => 'user', 'subject_id' => $w->u['bfm0'], 'include_children' => 0],
                    ['subject_type' => 'group', 'subject_id' => $w->g['bulk'], 'include_children' => 1],
                    ['subject_type' => 'group', 'subject_id' => $w->g['off'], 'include_children' => 1],
                ]);
            }
        }
        $w->access->flush();

        return $w;
    };
    $queries = [];
    foreach ([50, 200] as $count) {
        $w = $build($count);
        $all = array_keys((new ForumRepository($w->db))->tree());
        foreach (['guest', 'm0', 'm1', 'm2', 'm3', 'batch', 'bfm', 'bfm0', 'bgm', 'boff', 'fm', 'gm', 'admin', 'nc', 'bf', 'bw', 'editor'] as $name) {
            $fromCan = [];
            foreach ($all as $forumId) {
                if ($w->can($name, 'read', ['forum_id' => $forumId])->allowed) {
                    $fromCan[] = $forumId;
                }
            }
            $w->access->flush();
            T::same($fromCan, $w->access->readableForumIds($w->u[$name]), $count . ' Foren, ' . $name . ': readableForumIds = can(read) je Forum');
            T::same($fromCan, $w->access->filterReadable($w->u[$name], $all), $count . ' Foren, ' . $name . ': filterReadable = can(read) je Forum');
        }
        foreach (['batch', 'bfm', 'bgm', 'm1', 'guest', 'gm'] as $name) {
            $w->access->flush();
            $w->db->log = [];
            $w->access->readableForumIds($w->u[$name]);
            $queries[$name][$count] = count($w->db->log);
            T::check(count($w->db->log) <= 10, $count . ' Foren (' . count($all) . ' im Baum), ' . $name . ': höchstens zehn Abfragen (' . count($w->db->log) . ')', implode(' | ', array_slice($w->db->log, 0, 12)));
        }
        // can() behält seine Abfragefolge: die Mengen-API füllt die Zwischenspeicher der Rechte-Zeilen und Moderatoren nicht
        $w->access->flush();
        $w->access->readableForumIds($w->u['batch']);
        $w->db->log = [];
        $w->can('batch', 'read', ['forum_id' => $w->f['B0']]);
        T::check((preg_grep('/community_forum_permissions/', $w->db->log) ?: []) !== [] && (preg_grep('/community_forum_moderators/', $w->db->log) ?: []) !== [], $count . ' Foren: can() nach der Mengen-API liest Rechte-Zeilen und Moderatoren selbst');
    }
    foreach ($queries as $name => $byCount) {
        T::same($byCount[50], $byCount[200], $name . ': gleich viele Abfragen bei 50 und bei 200 Foren (' . $byCount[50] . ')');
    }
});

T::run('utcTimestamp() öffentlich und statisch, DECOY_ID öffentlich (Vertrag §1 Nr. 10, §3.5)', static function (): void {
    $method = new \ReflectionMethod(CommunityAccess::class, 'utcTimestamp');
    T::check($method->isPublic() && $method->isStatic(), 'utcTimestamp ist public static');
    T::same(gmmktime(12, 0, 0, 11, 15, 2026), CommunityAccess::utcTimestamp('2026-11-15 12:00:00'), 'UTC-Text → Unix (unabhängig von der Zone Europe/Berlin)');
    foreach (['leer' => '', 'null' => null, 'Zahl' => 1700000000, 'Überlauf' => '2026-13-45 00:00:00', 'Nullen' => '0000-00-00 00:00:00', 'ohne Zeit' => '2026-11-15', 'ISO mit T' => '2026-11-15T12:00:00'] as $label => $value) {
        T::same(null, CommunityAccess::utcTimestamp($value), 'utcTimestamp: ' . $label . ' → null');
    }
    $decoy = new \ReflectionClassConstant(CommunityAccess::class, 'DECOY_ID');
    T::check($decoy->isPublic(), 'DECOY_ID ist öffentlich');
    T::same(4294967295, CommunityAccess::DECOY_ID, 'DECOY_ID unverändert 4294967295');
    $scope = new \ReflectionMethod(CommunityAccess::class, 'moderationScope');
    T::same(['userId', 'context'], array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $scope->getParameters()), 'moderationScope(?int $userId, array $context = [])');
    T::same(3, (new \ReflectionMethod(CommunityAccess::class, 'matrixFor'))->getNumberOfParameters(), 'matrixFor hat drei Parameter (ohne $lean)');
});

T::run('Ablehnungsgründe: genau die Codes aus Codes::ACCESS_REASONS kommen vor; can() schreibt und sperrt nichts', static function (): void {
    $known = array_keys(Codes::ACCESS_REASONS);
    $seen = array_keys(AcSeen::$reasons);
    sort($known);
    sort($seen);
    T::same([], array_values(array_diff($seen, $known)), 'jeder gelieferte Grund ist ein bekannter Code (Sprachschlüssel community.access.<code>)');
    T::same([], array_values(array_diff(AC_PENDING_REASONS_2B, $known)), 'Warteliste AC_PENDING_REASONS_2B: nur registrierte Gründe');
    T::same([], array_values(array_diff($known, $seen, AC_PENDING_REASONS_2B)), 'jeder Code der Liste wird auch geliefert (keine toten Codes; außer der Warteliste)');
    $seenPending = array_values(array_intersect(AC_PENDING_REASONS_2B, $seen));
    if ($seenPending !== []) {
        T::info('Warteliste: schon geliefert, im J-Schritt streichen: ' . implode(', ', $seenPending));
    }

    $w = acStandardWorld();
    $before = [];
    foreach (['community_user_stats', 'community_topics', 'community_posts', 'community_forums', 'community_bans'] as $table) {
        $before[$table] = $w->db->count($table);
    }
    $w->db->log = [];
    foreach (AC_COLS as $name) {
        foreach (['read', 'search', 'create_topic', 'reply', 'edit', 'delete', 'report', 'topic.lock', 'moderate', 'ban'] as $action) {
            foreach ([['topic_id' => $w->t['t']], ['post_id' => $w->p['p']], ['forum_id' => $w->f['fo']], []] as $ctx) {
                $w->can($name, $action, $ctx);
                $w->can($name, $action, $ctx + ['fresh' => true]);
            }
        }
    }
    $after = [];
    foreach (array_keys($before) as $table) {
        $after[$table] = $w->db->count($table);
    }
    T::same($before, $after, 'can() legt keine Zeilen an (auch keine Statistik-Zeile für Konten ohne Beiträge)');
    T::same([], array_values(preg_grep('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER)\b/i', $w->db->log) ?: []), 'can() führt nur Lesezugriffe aus');
    T::same([], array_values(preg_grep('/FOR UPDATE/i', $w->db->log) ?: []), 'can() sperrt keine Zeilen (das tun die Dienste)');
    T::check($w->db->log !== [], 'die Prüfung hat tatsächlich Abfragen ausgeführt');
});

T::finish();
