<?php
/**
 * Community-Mitgliederbereich (PR #4, Teil 4): `CommunityMemberSection` (Anmeldung bei der Registry, Reiter und Moderationszugang,
 * POST-Auswertung ohne Dienstaufruf bei ungültigen Eingaben, Präferenzen aus dem Formular) und die Repository-Listen „Meine Themen/Antworten“
 * (`TopicRepository::listByUser()`, `PostRepository::listRepliesByUser()`: nur eigene Zeilen, ein Status, Keyset).
 * Die Entscheidungen (Abo beenden, Freigeben, Erledigen) prüfen `community-notify`, `community-moderation` und `community-sanctions`.
 *
 *   php TESTS/community-member/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-member-' . getmypid() . '/');
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

use CMS\Member\PluginDashboardRegistry;
use CMS\Services\Community\CommunityMemberSection;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Support\Cursor;

T::run('Reiter', static function (): void {
    T::same(['themen', 'beitraege', 'abos', 'einstellungen'], array_keys(CommunityMemberSection::tabsFor(false)), 'ohne Moderation: vier Reiter');
    T::same(['themen', 'beitraege', 'abos', 'einstellungen', 'moderation'], array_keys(CommunityMemberSection::tabsFor(true)), 'mit Moderation: zusätzlich „Moderation“');
    T::same('themen', CommunityMemberSection::normalizeTab(null, false), 'ohne Angabe: der erste Reiter');
    T::same('abos', CommunityMemberSection::normalizeTab('abos', false), 'gültiger Reiter');
    T::same('themen', CommunityMemberSection::normalizeTab('moderation', false), 'Moderation ohne Bereich: nicht erreichbar, erster Reiter');
    T::same('moderation', CommunityMemberSection::normalizeTab('moderation', true), 'Moderation mit Bereich');
    T::same('themen', CommunityMemberSection::normalizeTab(['moderation'], true), 'Nicht-String: erster Reiter');
    T::same('themen', CommunityMemberSection::normalizeTab('../../x', true), 'unbekannter Reiter: erster Reiter');
});

T::run('Anmeldung bei der Registry', static function (): void {
    $registry = PluginDashboardRegistry::instance();
    CommunityMemberSection::register($registry);
    $section = $registry->getSection('community');
    T::check($section !== null, 'Bereich „community“ ist registriert');
    T::same(['community', 'core', true, null], [$section['slug'] ?? null, $section['csrf'] ?? null, array_key_exists('capability', $section), $section['capability'] ?? null], 'Slug, Core-Token (csrf core), alle angemeldeten Mitglieder (capability null)');
    T::check(is_callable($section['render_callback'] ?? null) && is_callable($section['post_callback'] ?? null) && is_callable($section['dashboard_widget']['stats_callback'] ?? null), 'Callbacks aufrufbar');
    T::same('/member/plugin/community', \CMS\Services\Community\Support\Urls::MEMBER_SECTION, 'Adresse gleich Urls::MEMBER_SECTION');
});

T::run('POST ohne Dienstaufruf', static function (): void {
    $user = (object) ['id' => 7];
    $_POST = ['community_action' => 'gibtsnicht'];
    $r = CommunityMemberSection::handlePost($user, []);
    T::same(['danger', '/member/plugin/community?tab=themen'], [$r['type'] ?? null, $r['redirect'] ?? null], 'unbekannte Aktion: Fehler, zurück zum ersten Reiter');
    $_POST = ['community_action' => 'unsubscribe', 'type' => 'user', 'id' => '3'];
    $r = CommunityMemberSection::handlePost($user, []);
    T::same(['danger', '/member/plugin/community?tab=abos'], [$r['type'] ?? null, $r['redirect'] ?? null], 'Abo beenden mit falscher Art: invalid_input, zurück zu den Abos');
    $_POST = ['community_action' => 'unsubscribe', 'type' => 'topic', 'id' => '0'];
    $r = CommunityMemberSection::handlePost($user, []);
    T::same('danger', $r['type'] ?? null, 'Abo beenden ohne ID: Fehler');
    $_POST = ['community_action' => 'unsubscribe'];
    T::same(null, CommunityMemberSection::handlePost((object) ['id' => 0], []), 'ohne Konto: keine Auswertung');
    $_POST = [];

    $changes = new \ReflectionMethod(CommunityMemberSection::class, 'preferenceChanges');
    $_POST = ['auto_subscribe' => 'created', 'notify_mail' => '1', 'notify_types' => ['community.reply' => '1', 'community.moderation' => '1', 'x' => '1'], 'locale' => 'en'];
    $c = $changes->invoke(null);
    T::same(['created', true, 'en'], [$c['auto_subscribe'], $c['notify_mail'], $c['locale']], 'Präferenzen: Auswahl, E-Mail, Sprache');
    T::same(true, $c['notify_types']['community.reply'], 'Typ angekreuzt');
    T::same(false, $c['notify_types']['community.quote'], 'Typ nicht angekreuzt: aus');
    T::check(!isset($c['notify_types']['community.moderation']) && !isset($c['notify_types']['x']), 'nur die schaltbaren Typen der Liste (kein community.moderation, keine fremden Schlüssel)');
    $_POST = ['locale' => 'fr'];
    T::same(null, $changes->invoke(null)['locale'], 'unbekannte Sprache: null (wie die Website)');
    $_POST = [];
});

T::run('Repository: Meine Themen und Antworten', static function (): void {
    $db = CommunityTestDatabase::create();
    $forum = $db->forum();
    $me = $db->user();
    $other = $db->user();
    $t = [];
    foreach (range(1, 5) as $n) {
        $t[$n] = $db->topic(['forum' => $forum, 'user' => $me, 'created_at' => gmdate('Y-m-d H:i:s', 1800000000 + $n * 60)]);
    }
    $db->topic(['forum' => $forum, 'user' => $other, 'created_at' => '2027-01-01 00:00:00']);
    $db->topic(['forum' => $forum, 'user' => $me, 'status' => 'pending', 'created_at' => '2027-01-02 00:00:00']);
    $db->topic(['forum' => $forum, 'user' => $me, 'status' => 'hidden', 'created_at' => '2027-01-03 00:00:00']);
    $topics = new TopicRepository($db);

    $p1 = $topics->listByUser($me, 'published', null, 2);
    T::same([$t[5], $t[4]], array_map(static fn (array $r): int => (int) $r['id'], $p1->items), 'Seite 1: jüngste zuerst, nur eigene veröffentlichte');
    T::check($p1->next !== null, 'Cursor vorhanden');
    $p2 = $topics->listByUser($me, 'published', Cursor::decode($p1->next), 2);
    T::same([$t[3], $t[2]], array_map(static fn (array $r): int => (int) $r['id'], $p2->items), 'Seite 2 über den Cursor');
    $p3 = $topics->listByUser($me, 'published', Cursor::decode($p2->next), 2);
    T::same([$t[1]], array_map(static fn (array $r): int => (int) $r['id'], $p3->items), 'Seite 3: Rest');
    T::same(null, $p3->next, 'letzte Seite ohne Cursor');
    T::same(1, count($topics->listByUser($me, 'pending')->items), 'Status pending getrennt');
    T::same(5, count($topics->listByUser($me, 'published', null, 100)->items), 'fremde und ausgeblendete Themen fehlen');
    T::same(5, count($topics->listByUser($me, 'gibtsnicht', null, 100)->items), 'unbekannter Status fällt auf veröffentlicht');
    T::same([], $topics->listByUser($other + 100)->items, 'Konto ohne Themen: leer');

    $posts = new PostRepository($db);
    $first = $db->post(['topic' => $t[1], 'user' => $me, 'first' => true, 'created_at' => '2026-12-01 10:00:00']);
    $r1 = $db->post(['topic' => $t[1], 'user' => $me, 'created_at' => '2026-12-01 10:01:00']);
    $r2 = $db->post(['topic' => $t[2], 'user' => $me, 'created_at' => '2026-12-01 10:02:00']);
    $db->post(['topic' => $t[2], 'user' => $other, 'created_at' => '2026-12-01 10:03:00']);
    $db->post(['topic' => $t[2], 'user' => $me, 'status' => 'hidden', 'created_at' => '2026-12-01 10:04:00']);
    $db->post(['topic' => $t[2], 'user' => $me, 'status' => 'pending', 'created_at' => '2026-12-01 10:05:00']);
    $replies = $posts->listRepliesByUser($me, 'published', null, 1);
    T::same([$r2], array_map(static fn (array $r): int => (int) $r['id'], $replies->items), 'Antworten: jüngste zuerst; Themenstart, fremde, ausgeblendete und wartende fehlen');
    $more = $posts->listRepliesByUser($me, 'published', Cursor::decode($replies->next), 1);
    T::same([$r1], array_map(static fn (array $r): int => (int) $r['id'], $more->items), 'Antworten: Folgeseite');
    T::check(!in_array($first, array_map(static fn (array $r): int => (int) $r['id'], $posts->listRepliesByUser($me, 'published', null, 100)->items), true), 'Themenstart gehört nicht zu „Meine Beiträge“');
    T::check(!array_key_exists('ip_hash', $replies->items[0]), 'keine Spalte ip_hash');
    T::same(1, count($posts->listRepliesByUser($me, 'pending')->items), 'Antworten im Status pending getrennt');
    T::same(2, count($posts->listRepliesByUser($me, 'hidden', null, 100)->items), 'Status außerhalb von published/pending fällt auf veröffentlicht (ausgeblendete bleiben unsichtbar)');
});

T::run('Einbindung (Quelltext)', static function (): void {
    $source = (string) file_get_contents(ABSPATH . 'core/Services/Community/CommunityMemberSection.php');
    $forms = substr_count($source, '<form method="post"');
    T::check($forms >= 4 && preg_match_all('/<form method="post"[^\n]*?>\' \. \$field/', $source) === $forms, 'jedes POST-Formular (' . $forms . ') trägt das Core-Token der Registry');
    T::check(!str_contains($source, '<script') && !str_contains($source, ' onclick='), 'kein Inline-Skript');
    T::check(!str_contains($source, 'ip_hash'), 'der Bereich liest nie ip_hash');
    T::check(str_contains($source, 'filterReadable($userId'), 'eigene Inhalte in nicht mehr lesbaren Foren werden ausgeblendet');
    $hooks = (string) file_get_contents(ABSPATH . 'core/Services/FeatureHooks/CommunityHooks.php');
    $gate = strpos($hooks, 'if (!CommunityModule::isEnabled())');
    T::check($gate !== false && strpos($hooks, "'member_dashboard_init'") > $gate, 'Mitgliederbereich nur bei aktivem Modul');
});

T::finish();
