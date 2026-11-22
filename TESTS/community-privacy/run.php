<?php
/**
 * Community-Datenschutz (PR #5): `CommunityRetention` (Löschfristen: Trockenlauf, Löschen/Anonymisieren in Blöcken, Fristen und Grenzen) und
 * `CommunityPrivacy` (Auskunft und Löschung eines Mitglieds: Umfang, Fremdbestand unberührt, Zähler, Idempotenz, Selbstauskunft).
 * DB-frei (SQLite-Double). Die Dateien der Exporte und der verwaisten Uploads (private Ablage) prüft die Suite nicht.
 *
 *   php TESTS/community-privacy/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-privacy-' . getmypid() . '/');
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

use CMS\Database;
use CMS\Hooks;
use CMS\Services\Community\CommunityLegal;
use CMS\Services\Community\CommunityPrivacy;
use CMS\Services\Community\CommunityRetention;
use CMS\Services\Community\Support\Settings;

const NOW = 1800000000; // 2027-01-15 08:00:00 UTC

function ago(int $days): string
{
    return gmdate('Y-m-d H:i:s', NOW - $days * 86400);
}

/** @param array<string, mixed> $row */
function ins(Database $db, string $table, array $row): void
{
    $db->execute('INSERT INTO ' . $db->getPrefix() . $table . ' (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
}

function retention(Database $db, array $settings = []): CommunityRetention
{
    return new CommunityRetention($db, static fn (): int => NOW, Settings::fromArray($settings));
}

T::run('Fristen: Standards, Grenzen, Filter', static function (): void {
    $db = CommunityTestDatabase::create();
    $days = retention($db)->days();
    T::same(['ip_hash' => 30, 'read_states' => 180, 'orphan_uploads' => 1, 'drafts' => 30, 'reports' => 365, 'moderation_log' => 365, 'warnings' => 730, 'bans' => 365, 'revisions' => 365], $days, 'Standardfristen laut SECURITY-PRIVACY §5');
    T::same(60, retention($db, ['ip_hash_days' => 60])->days()['ip_hash'], 'ip_hash folgt der Einstellung ip_hash_days');
    T::same(0, retention($db, ['ip_hash_days' => 0])->days()['ip_hash'], 'ip_hash_days 0: nichts zu löschen');
    $n = CommunityRetention::normalize(['drafts' => 3, 'reports' => 99999, 'bans' => 0, 'revisions' => 'x', 'orphan_uploads' => 50], $days);
    T::same([7, 3650, 0, 365, 1], [$n['drafts'], $n['reports'], $n['bans'], $n['revisions'], $n['orphan_uploads']], 'Grenzen: mindestens 7, höchstens 3650, 0 = nie, Unsinn = Standard, orphan nur 1');
    Hooks::addFilter('cms_community_retention_days', static fn (mixed $d): array => ['drafts' => 10] + (array) $d);
    T::same(10, retention($db)->days()['drafts'], 'Filter cms_community_retention_days ändert eine Frist');
    $rules = retention($db)->rules();
    T::same(9, count($rules), 'neun Regeln');
    T::check(count(array_filter($rules, static fn ($r): bool => str_starts_with($r->key, 'community.'))) === 9, 'Schlüssel mit Präfix community.');
});

T::run('Regeln: Trockenlauf und Löschen', static function (): void {
    $db = CommunityTestDatabase::create();
    $u = $db->user();
    $forum = $db->forum();
    $topic = $db->topic(['forum' => $forum, 'user' => $u]);

    // ip_hash: 30 Tage
    $old = $db->post(['topic' => $topic, 'user' => $u, 'created_at' => ago(40)]);
    $new = $db->post(['topic' => $topic, 'user' => $u, 'created_at' => ago(5)]);
    $none = $db->post(['topic' => $topic, 'user' => $u, 'created_at' => ago(60)]);
    $db->execute('UPDATE cms_community_posts SET ip_hash = ? WHERE id IN (?, ?)', [str_repeat('a', 64), $old, $new]);
    // Lesestatus: 180 Tage
    ins($db, 'community_read_states', ['user_id' => $u, 'topic_id' => $topic, 'last_read_post_id' => 1, 'updated_at' => ago(200)]);
    ins($db, 'community_read_states', ['user_id' => $u + 1, 'topic_id' => $topic, 'last_read_post_id' => 1, 'updated_at' => ago(10)]);
    // Entwürfe: 30 Tage
    ins($db, 'community_drafts', ['user_id' => $u, 'target_type' => 'forum', 'target_id' => $forum, 'title' => 't', 'body' => 'b', 'updated_at' => ago(31)]);
    ins($db, 'community_drafts', ['user_id' => $u, 'target_type' => 'topic', 'target_id' => $topic, 'title' => 't', 'body' => 'b', 'updated_at' => ago(2)]);
    // Meldungen: erledigt nach 365 Tagen, offene bleiben
    $db->report(['target' => $old, 'status' => 'valid', 'handled_at' => ago(400)]);
    $db->report(['target' => $old, 'status' => 'invalid', 'handled_at' => ago(100)]);
    $db->report(['target' => $old, 'status' => 'open', 'created_at' => ago(900)]);
    // Moderationsprotokoll
    $db->moderationLog(['action' => 'post.hide', 'target' => $old, 'created_at' => ago(400)]);
    $db->moderationLog(['action' => 'post.hide', 'target' => $old, 'created_at' => ago(10)]);
    // Verwarnungen: 730 Tage nach Ablauf; ohne Ablauf 1825 Tage nach Anlage
    ins($db, 'community_warnings', ['user_id' => $u, 'reason' => 'a', 'points' => 1, 'expires_at' => ago(800), 'created_at' => ago(900)]);
    ins($db, 'community_warnings', ['user_id' => $u, 'reason' => 'b', 'points' => 1, 'expires_at' => ago(100), 'created_at' => ago(200)]);
    ins($db, 'community_warnings', ['user_id' => $u, 'reason' => 'c', 'points' => 1, 'expires_at' => null, 'created_at' => ago(2000)]);
    ins($db, 'community_warnings', ['user_id' => $u, 'reason' => 'd', 'points' => 1, 'expires_at' => null, 'created_at' => ago(1000)]);
    // Sperren: 365 Tage nach Aufheben oder Ablauf; dauerhafte bleiben
    $db->ban(['user' => $u, 'lifted_at' => ago(400), 'starts_at' => ago(500)]);
    $db->ban(['user' => $u, 'ends_at' => ago(500), 'starts_at' => ago(600)]);
    $db->ban(['user' => $u, 'lifted_at' => ago(50), 'starts_at' => ago(100)]);
    $db->ban(['user' => $u, 'ends_at' => null, 'starts_at' => ago(3000)]); // dauerhaft, nicht aufgehoben
    $db->ban(['user' => $u, 'ends_at' => ago(20), 'starts_at' => ago(90)]);
    // Bearbeitungsverlauf: 365 Tage, die jüngste Fassung je Beitrag bleibt
    foreach ([500, 450, 400] as $d) {
        ins($db, 'community_post_revisions', ['post_id' => $old, 'title' => null, 'body' => 'v' . $d, 'format' => 'markdown', 'edited_by' => $u, 'edit_reason' => '', 'created_at' => ago($d)]);
    }
    ins($db, 'community_post_revisions', ['post_id' => $new, 'title' => null, 'body' => 'älteste', 'format' => 'markdown', 'edited_by' => $u, 'edit_reason' => '', 'created_at' => ago(500)]);
    ins($db, 'community_post_revisions', ['post_id' => $new, 'title' => null, 'body' => 'jung', 'format' => 'markdown', 'edited_by' => $u, 'edit_reason' => '', 'created_at' => ago(30)]);
    // Verwaiste Uploads: Trockenlauf (die Dateien räumt AttachmentService)
    $db->attachment(['created_at' => ago(3)]);
    $db->attachment(['created_at' => gmdate('Y-m-d H:i:s', NOW - 600)]);
    $db->attachment(['post' => $old, 'created_at' => ago(30)]);

    $r = retention($db);
    $due = static fn (string $key): int => $r->countDue($key);
    T::same(['ip_hash' => 1, 'read_states' => 1, 'orphan_uploads' => 1, 'drafts' => 1, 'reports' => 1, 'moderation_log' => 1, 'warnings' => 2, 'bans' => 2, 'revisions' => 3], [
        'ip_hash' => $due('ip_hash'), 'read_states' => $due('read_states'), 'orphan_uploads' => $due('orphan_uploads'), 'drafts' => $due('drafts'), 'reports' => $due('reports'),
        'moderation_log' => $due('moderation_log'), 'warnings' => $due('warnings'), 'bans' => $due('bans'), 'revisions' => $due('revisions'),
    ], 'Trockenlauf zählt genau die fälligen Zeilen');

    T::same(1, $r->prune('ip_hash'), 'ip_hash: eine Zeile anonymisiert');
    T::same([null, str_repeat('a', 64), null], [$db->row('community_posts', ['id' => $old])['ip_hash'], $db->row('community_posts', ['id' => $new])['ip_hash'], $db->row('community_posts', ['id' => $none])['ip_hash']], 'ip_hash: nur der alte Beitrag verliert die Kennung, der Beitrag selbst bleibt');
    T::same(1, $r->prune('read_states'), 'read_states');
    T::same(1, $db->count('community_read_states'), 'read_states: junge Zeile bleibt');
    T::same(1, $r->prune('drafts'), 'drafts');
    T::same(1, $db->count('community_drafts'), 'drafts: junger Entwurf bleibt');
    T::same(1, $r->prune('reports'), 'reports');
    T::same(['open', 'invalid'], array_reverse(array_map(static fn (array $row): string => (string) $row['status'], $db->rows('community_reports'))), 'reports: offene und jüngere erledigte bleiben');
    T::same(1, $r->prune('moderation_log'), 'moderation_log');
    T::same(1, $db->count('community_moderation_log'), 'moderation_log: junger Eintrag bleibt');
    T::same(2, $r->prune('warnings'), 'warnings');
    T::same(['b', 'd'], array_map(static fn (array $row): string => (string) $row['reason'], $db->rows('community_warnings', [], 'id')), 'warnings: abgelaufene vor 730 Tagen und unbefristete über 5 Jahre sind weg');
    T::same(2, $r->prune('bans'), 'bans');
    T::same(3, $db->count('community_bans'), 'bans: die vor 50 Tagen aufgehobene, die dauerhafte und die vor 20 Tagen abgelaufene bleiben');
    T::check($db->count('community_bans', ['ends_at' => null, 'lifted_at' => null]) === 1, 'bans: die dauerhafte, nicht aufgehobene Sperre bleibt');
    T::same(3, $r->prune('revisions'), 'revisions');
    $left = array_map(static fn (array $row): string => (string) $row['body'], $db->rows('community_post_revisions', [], 'id'));
    T::same(['v400', 'jung'], $left, 'revisions: die jüngste Fassung je Beitrag bleibt auch, wenn sie älter als die Frist ist; alte Zwischenfassungen entfallen');
    T::same(0, $r->prune('drafts'), 'zweiter Lauf: nichts mehr fällig');
    T::same(0, $r->countDue('ip_hash'), 'zweiter Lauf: ip_hash nichts mehr fällig');
});

T::run('Regeln: Fristen 0, Blöcke, unbekannte Regel', static function (): void {
    $db = CommunityTestDatabase::create();
    $u = $db->user();
    for ($i = 0; $i < 7; $i++) {
        ins($db, 'community_drafts', ['user_id' => $u, 'target_type' => 'topic', 'target_id' => 100 + $i, 'title' => 't', 'body' => 'b', 'updated_at' => ago(100 + $i)]);
    }
    Hooks::addFilter('cms_community_retention_days', static fn (mixed $d): array => array_merge((array) $d, ['drafts' => 0]), 20);
    $r = retention($db);
    T::same(0, $r->countDue('drafts'), 'Frist 0: Trockenlauf zählt nichts');
    T::same(0, $r->prune('drafts'), 'Frist 0: nichts gelöscht');
    T::same(7, $db->count('community_drafts'), 'Frist 0: alle Entwürfe bleiben');
    $rule = array_values(array_filter($r->rules(), static fn ($x): bool => $x->key === 'community.drafts'))[0];
    T::same([0, 0, 0], [$rule->days(), $rule->countDue(), $rule->prune()], 'RetentionRule folgt: Frist 0 schaltet ab');
    try {
        $r->countDue('gibtsnicht');
        T::same(0, $r->countDue('gibtsnicht'), 'unbekannte Regel: 0 (Frist fehlt)');
    } catch (\InvalidArgumentException) {
        T::check(true, 'unbekannte Regel wirft');
    }
    T::same(['community.ip_hash', 'anonymize'], [retention($db)->rules()[0]->key, retention($db)->rules()[0]->action], 'ip_hash anonymisiert');
});

T::run('Auskunft', static function (): void {
    $db = CommunityTestDatabase::create();
    $a = $db->user();
    $b = $db->user();
    $forum = $db->forum();
    $ta = $db->topic(['forum' => $forum, 'user' => $a, 'title' => 'Thema von A']);
    $tb = $db->topic(['forum' => $forum, 'user' => $b, 'title' => 'Thema von B']);
    $pa = $db->post(['topic' => $ta, 'user' => $a, 'first' => true, 'body' => 'Text von A']);
    $pb = $db->post(['topic' => $tb, 'user' => $b, 'first' => true, 'body' => 'Text von B']);
    $db->post(['topic' => $tb, 'user' => $a, 'body' => 'Antwort von A']);
    $db->execute('UPDATE cms_community_posts SET ip_hash = ? WHERE id = ?', [str_repeat('c', 64), $pa]);
    $db->reaction(['post' => $pb, 'user' => $a]);
    $db->reaction(['post' => $pa, 'user' => $b]);
    $db->subscription(['user' => $a, 'target' => $tb]);
    $db->subscription(['user' => $b, 'target' => $ta]);
    ins($db, 'community_drafts', ['user_id' => $a, 'target_type' => 'forum', 'target_id' => $forum, 'title' => 'Entwurf', 'body' => 'noch nicht fertig', 'updated_at' => ago(1)]);
    ins($db, 'community_ignores', ['user_id' => $a, 'ignored_user_id' => $b, 'created_at' => ago(3)]);
    $db->report(['target' => $pb, 'reporter' => $a, 'message' => 'meine Meldung']);
    $db->ban(['user' => $a, 'reason' => 'Grund A', 'internal_note' => 'Notiz zu A']);
    $db->moderationLog(['action' => 'post.hide', 'target' => $pb, 'actor' => $a]);
    $db->stats($a, ['topic_count' => 1, 'post_count' => 2]);
    $db->attachment(['post' => $pa, 'user' => $a, 'original_name' => 'bild.png']);

    $data = (new CommunityPrivacy($db))->collect($a);
    T::same($a, $data['user_id'], 'Auskunft nennt das Mitglied');
    T::same(['Thema von A'], array_column($data['topics'], 'title'), 'Themen: nur eigene');
    T::same(['Text von A', 'Antwort von A'], array_column($data['posts'], 'body'), 'Beiträge: nur eigene, mit Text');
    T::same(1, count($data['reactions']), 'Reaktionen: nur eigene');
    T::same([$tb], array_column($data['subscriptions'], 'target_id'), 'Abos');
    T::same(['Entwurf'], array_column($data['drafts'], 'title'), 'Entwürfe');
    T::same([$b], array_column($data['ignored_members'], 'ignored_user_id'), 'Ignorierliste');
    T::same(['meine Meldung'], array_column($data['reports_filed'], 'message'), 'eigene Meldungen');
    T::same(['Grund A'], array_column($data['bans'], 'reason'), 'Sperren');
    T::same('Notiz zu A', $data['bans'][0]['internal_note'], 'Sperre: die interne Notiz über das Mitglied gehört zur Auskunft');
    T::same(['post.hide'], array_column($data['moderation_actions'], 'action'), 'Moderationsaktionen des Mitglieds');
    T::same(['bild.png'], array_column($data['attachments'], 'original_name'), 'Anhänge');
    T::same(2, $data['profile']['post_count'], 'Profil: Statistik');
    T::same(1, $data['ip_hash_posts'], 'IP-Kennung: nur die Zahl der Beiträge, nie der Wert');
    $json = (string) json_encode($data);
    T::check(!str_contains($json, str_repeat('c', 64)) && !str_contains($json, 'ip_hash"'), 'der Export enthält keine IP-Kennung');
    T::check(!str_contains($json, 'Thema von B') && !str_contains($json, 'Text von B'), 'der Export enthält keine fremden Inhalte');
    T::same([], (new CommunityPrivacy($db))->collect($a + 1000), 'Mitglied ohne Daten: leer');
    T::same([], (new CommunityPrivacy($db))->collect(0), 'Konto 0: leer');
    $short = (new CommunityPrivacy($db))->collect($a, 1);
    T::same(['posts'], $short['truncated'] ?? null, 'Kürzung je Bereich wird genannt');
    T::same(1, count($short['posts']), 'Kürzung: höchstens so viele Zeilen');
    $member = CommunityPrivacy::memberExport(['profile' => 1], 0);
    T::same(['profile' => 1], $member, 'Selbstauskunft ohne Konto: unverändert');
    T::same('x', CommunityPrivacy::memberExport('x', 5), 'Selbstauskunft: Nicht-Array bleibt');
    T::same('', CommunityPrivacy::handleExport(0, 'a@b.c'), 'Export ohne Konto: leer (keine Daten zu E-Mail-Adressen)');
    T::same(0, CommunityPrivacy::handleDelete(0, 'a@b.c'), 'Löschung ohne Konto: nichts');
});

T::run('Löschung', static function (): void {
    $db = CommunityTestDatabase::create();
    $a = $db->user();
    $b = $db->user();
    $forum = $db->forum();
    $ta = $db->topic(['forum' => $forum, 'user' => $a, 'title' => 'Thema von A']);
    $tb = $db->topic(['forum' => $forum, 'user' => $b, 'title' => 'Thema von B', 'last_poster_id' => $a]);
    $pa = $db->post(['topic' => $ta, 'user' => $a, 'first' => true, 'body' => 'Text von A']);
    $pb = $db->post(['topic' => $tb, 'user' => $b, 'first' => true, 'body' => 'Text von B']);
    $pa2 = $db->post(['topic' => $tb, 'user' => $a, 'body' => 'Antwort von A']);
    $db->execute('UPDATE cms_community_posts SET ip_hash = ?, edited_by = ? WHERE id IN (?, ?)', [str_repeat('d', 64), $a, $pa, $pb]);
    // Reaktionen: A reagiert auf B (Zähler zurückbuchen), B reagiert auf A (bleibt, nur der Autor wird anonym)
    $db->reaction(['post' => $pb, 'user' => $a]);
    $db->reaction(['post' => $pa, 'user' => $b]);
    $db->execute('UPDATE cms_community_posts SET reaction_count = 1 WHERE id IN (?, ?)', [$pb, $pa]);
    $db->execute('UPDATE cms_community_topics SET reaction_count = 1 WHERE id IN (?, ?)', [$tb, $ta]);
    $db->stats($a, ['topic_count' => 1, 'post_count' => 2]);
    $db->stats($b, ['topic_count' => 1, 'post_count' => 1]);
    $db->execute('UPDATE cms_community_user_stats SET reactions_received = 1 WHERE user_id = ?', [$b]);
    $db->subscription(['user' => $a, 'target' => $tb]);
    $db->subscription(['user' => $b, 'target' => $ta]);
    ins($db, 'community_read_states', ['user_id' => $a, 'topic_id' => $tb, 'last_read_post_id' => 1, 'updated_at' => ago(1)]);
    ins($db, 'community_read_states', ['user_id' => $b, 'topic_id' => $ta, 'last_read_post_id' => 1, 'updated_at' => ago(1)]);
    ins($db, 'community_forum_read_marks', ['user_id' => $a, 'forum_id' => $forum, 'marked_at' => ago(1)]);
    ins($db, 'community_drafts', ['user_id' => $a, 'target_type' => 'forum', 'target_id' => $forum, 'title' => 'E', 'body' => 'b', 'updated_at' => ago(1)]);
    ins($db, 'community_ignores', ['user_id' => $a, 'ignored_user_id' => $b, 'created_at' => ago(1)]);
    ins($db, 'community_ignores', ['user_id' => $b, 'ignored_user_id' => $a, 'created_at' => ago(1)]);
    ins($db, 'community_ignores', ['user_id' => $b, 'ignored_user_id' => $b + 50, 'created_at' => ago(1)]);
    ins($db, 'community_user_badges', ['user_id' => $a, 'badge_id' => 1, 'granted_by' => $b, 'reason' => '', 'granted_at' => ago(1)]);
    ins($db, 'community_user_badges', ['user_id' => $b, 'badge_id' => 1, 'granted_by' => $a, 'reason' => '', 'granted_at' => ago(1)]);
    ins($db, 'community_warnings', ['user_id' => $a, 'issued_by' => $b, 'reason' => 'w', 'points' => 1, 'created_at' => ago(1)]);
    ins($db, 'community_warnings', ['user_id' => $b, 'issued_by' => $a, 'reason' => 'w2', 'points' => 1, 'created_at' => ago(1)]);
    $db->ban(['user' => $a, 'issued_by' => $b]);
    $db->ban(['user' => $b, 'issued_by' => $a, 'lifted_by' => $a]);
    $db->report(['target' => $pb, 'reporter' => $a, 'handled_by' => $a, 'status' => 'valid']);
    $db->report(['target' => $pa, 'reporter' => $b]);
    $db->moderationLog(['action' => 'post.hide', 'target' => $pb, 'actor' => $a]);
    $db->moderationLog(['action' => 'post.hide', 'target' => $pa, 'actor' => $b]);
    ins($db, 'community_post_revisions', ['post_id' => $pa, 'title' => null, 'body' => 'alt', 'format' => 'markdown', 'edited_by' => $a, 'edit_reason' => '', 'created_at' => ago(1)]);
    $db->attachment(['post' => $pa, 'user' => $a]);
    $db->queueJob(['actor' => $a, 'topic' => $tb, 'post' => $pa2]);
    $db->queueJob(['actor' => $b, 'topic' => $ta, 'post' => $pa]);

    $privacy = new CommunityPrivacy($db, Settings::fromArray([]));
    $counts = $privacy->erase($a);
    T::check(!isset($counts['posts_deleted']), 'Standard (erase_mode = anonymize): kein Löschschritt für Beiträge');
    T::same(1, $counts['reactions'], 'eine Reaktion gelöscht');
    T::same([0, 0], [(int) $db->row('community_posts', ['id' => $pb])['reaction_count'], (int) $db->row('community_topics', ['id' => $tb])['reaction_count']], 'Reaktionszähler von Beitrag und Thema zurückgebucht');
    T::same(0, (int) $db->row('community_user_stats', ['user_id' => $b])['reactions_received'], 'Zähler „erhaltene Reaktionen“ des Autors zurückgebucht');
    T::same(1, (int) $db->row('community_posts', ['id' => $pa])['reaction_count'], 'Reaktion von B auf den Beitrag von A bleibt (Inhalt bleibt, anonym)');
    T::same(1, $db->count('community_reactions'), 'fremde Reaktion bleibt');

    // Anonymisiert, Inhalt bleibt
    T::same([null, 'Text von A'], [$db->row('community_posts', ['id' => $pa])['user_id'], $db->row('community_posts', ['id' => $pa])['body']], 'Beitrag: Konto entfällt, Text bleibt');
    T::same([null, null], [$db->row('community_posts', ['id' => $pa])['ip_hash'], $db->row('community_posts', ['id' => $pa])['edited_by']], 'Beitrag: IP-Kennung und Bearbeiter entfallen');
    T::same(str_repeat('d', 64), $db->row('community_posts', ['id' => $pb])['ip_hash'], 'fremder Beitrag: Kennung bleibt (Frist über community.ip_hash)');
    T::same([null, 'Thema von A'], [$db->row('community_topics', ['id' => $ta])['user_id'], $db->row('community_topics', ['id' => $ta])['title']], 'Thema: Konto entfällt, Titel bleibt');
    T::same(null, $db->row('community_topics', ['id' => $tb])['last_poster_id'], 'letzter Beitragender entfällt');
    T::same($b, (int) $db->row('community_topics', ['id' => $tb])['user_id'], 'fremdes Thema unberührt');
    T::same(null, $db->row('community_post_revisions', ['post_id' => $pa])['edited_by'], 'Verlauf: Bearbeiter entfällt');
    T::same(null, $db->row('community_attachments', ['post_id' => $pa])['user_id'], 'Anhang: Konto entfällt');

    // Gelöscht
    T::same([0, 0, 0, 0, 0], [$db->count('community_subscriptions', ['user_id' => $a]), $db->count('community_read_states', ['user_id' => $a]), $db->count('community_forum_read_marks', ['user_id' => $a]), $db->count('community_drafts', ['user_id' => $a]), $db->count('community_user_stats', ['user_id' => $a])], 'Abos, Lesestatus, Lesemarken, Entwürfe und Statistik gelöscht');
    T::same(1, $db->count('community_ignores'), 'Ignorierliste: beide Richtungen gelöscht, fremde Zeile bleibt');
    T::same([0, 1], [$db->count('community_user_badges', ['user_id' => $a]), $db->count('community_user_badges', ['user_id' => $b])], 'Abzeichen des Mitglieds gelöscht, fremdes bleibt');
    T::same(null, $db->row('community_user_badges', ['user_id' => $b])['granted_by'], 'Abzeichen: Vergeber entfällt');
    T::same([0, 1], [$db->count('community_warnings', ['user_id' => $a]), $db->count('community_warnings', ['user_id' => $b])], 'Verwarnungen des Mitglieds gelöscht');
    T::same(null, $db->row('community_warnings', ['user_id' => $b])['issued_by'], 'Verwarnung: Aussteller entfällt');
    T::same([0, 1], [$db->count('community_bans', ['user_id' => $a]), $db->count('community_bans', ['user_id' => $b])], 'Sperren des Mitglieds gelöscht');
    T::same([null, null], [$db->row('community_bans', ['user_id' => $b])['issued_by'], $db->row('community_bans', ['user_id' => $b])['lifted_by']], 'Sperre: Aussteller und Aufheber entfallen');
    T::same([null, null], [$db->row('community_reports', ['target_id' => $pb])['reporter_id'], $db->row('community_reports', ['target_id' => $pb])['handled_by']], 'Meldung bleibt, Melder und Bearbeiter entfallen');
    T::same($b, (int) $db->row('community_reports', ['target_id' => $pa])['reporter_id'], 'fremde Meldung unberührt');
    T::same(2, $db->count('community_moderation_log'), 'Protokoll bleibt');
    T::same(1, $db->count('community_moderation_log', ['actor_id' => null]), 'Protokoll: Akteur entfällt');
    T::same([0, 1], [$db->count('community_notify_queue', ['actor_id' => $a]), $db->count('community_notify_queue', ['actor_id' => $b])], 'wartende Benachrichtigungen des Mitglieds gelöscht');

    // Zweiter Lauf: nichts mehr zu tun; Fremdbestand unberührt
    $again = $privacy->erase($a);
    T::same(0, array_sum($again), 'idempotent: der zweite Lauf ändert nichts');
    T::same(1, $db->count('community_user_stats', ['user_id' => $b]), 'Statistik des anderen Mitglieds bleibt');
    T::same([], $privacy->collect($a), 'nach der Löschung gibt es keine Auskunft mehr');
    T::same(0, array_sum((new CommunityPrivacy($db))->erase(0)), 'Konto 0: nichts');
});

T::run('Wirkung: erase_mode (delete = Inhalt der Beiträge löschen, Platzhalter bleibt)', static function (): void {
    $db = CommunityTestDatabase::create();
    $a = $db->user();
    $b = $db->user();
    $forum = $db->forum();
    $ta = $db->topic(['forum' => $forum, 'user' => $a, 'title' => 'Thema von A']);
    $tb = $db->topic(['forum' => $forum, 'user' => $b, 'title' => 'Thema von B']);
    $pa = $db->post(['topic' => $ta, 'user' => $a, 'first' => true, 'body' => 'Text von A']);
    $pa2 = $db->post(['topic' => $tb, 'user' => $a, 'body' => 'Antwort von A']);
    $pb = $db->post(['topic' => $tb, 'user' => $b, 'first' => true, 'body' => 'Text von B']);
    $db->execute("UPDATE cms_community_posts SET body_html = '<p>Cache</p>', ip_hash = ? WHERE id IN (?, ?, ?)", [str_repeat('e', 64), $pa, $pa2, $pb]);
    ins($db, 'community_post_revisions', ['post_id' => $pa, 'title' => null, 'body' => 'alte Fassung von A', 'format' => 'markdown', 'edited_by' => $a, 'edit_reason' => '', 'created_at' => ago(1)]);
    ins($db, 'community_post_revisions', ['post_id' => $pb, 'title' => null, 'body' => 'alte Fassung von B', 'format' => 'markdown', 'edited_by' => $b, 'edit_reason' => '', 'created_at' => ago(1)]);
    $db->attachment(['post' => $pa, 'user' => $a]);
    $db->attachment(['post' => $pb, 'user' => $b]);
    $unindexed = [];
    $privacy = new CommunityPrivacy($db, Settings::fromArray(['erase_mode' => 'delete']), static function (array $ids) use (&$unindexed): void {
        $unindexed[] = $ids;
    });

    $counts = $privacy->erase($a);
    T::same([2, 1, 1], [$counts['posts_deleted'], $counts['revisions_deleted'], $counts['attachments_released']], 'zwei Beiträge geleert, eine Fassung gelöscht, ein Anhang gelöst');
    foreach ([$pa, $pa2] as $id) {
        $row = $db->row('community_posts', ['id' => $id]);
        T::same(['', '', null, null, 'published'], [$row['body'], $row['body_html'], $row['user_id'], $row['ip_hash'], $row['status']], 'Beitrag ' . $id . ': Text und Cache leer, anonym, Zeile bleibt veröffentlicht (Zählung und Seitenposition)');
        T::check(is_string($row['deleted_at']) && $row['deleted_at'] !== '', 'Beitrag ' . $id . ': deleted_at gesetzt');
    }
    $foreign = $db->row('community_posts', ['id' => $pb]);
    T::same(['Text von B', '<p>Cache</p>', null], [$foreign['body'], $foreign['body_html'], $foreign['deleted_at']], 'fremder Beitrag unberührt');
    T::same([0, 1], [$db->count('community_post_revisions', ['post_id' => $pa]), $db->count('community_post_revisions', ['post_id' => $pb])], 'Verlauf: nur die Fassungen des Mitglieds gelöscht');
    T::same([1, $pb], [$db->count('community_attachments', ['user_id' => null, 'post_id' => null]), (int) $db->row('community_attachments', ['user_id' => $b])['post_id']], 'Anhang des Mitglieds vom Beitrag gelöst (Aufräumregel community.orphan_uploads), fremder bleibt');
    T::same([[$pa, $pa2]], $unindexed, 'Suchindex: genau die Beiträge des Mitglieds entfernt');
    T::same('Thema von A', $db->row('community_topics', ['id' => $ta])['title'], 'Themen-Titel bleibt (anonym)');

    $again = $privacy->erase($a);
    T::same(0, array_sum($again), 'idempotent: der zweite Lauf ändert nichts');
    T::same([[$pa, $pa2]], $unindexed, 'zweiter Lauf: kein weiterer Aufruf des Suchindex');

    $html = CommunityLegal::privacySection(['erase_mode' => 'delete', 'days' => ['ip_hash' => 30] + CommunityRetention::DEFAULTS]);
    T::check(str_contains($html, 'wird der Inhalt Ihrer Beiträge samt früherer Fassungen und Anhänge gelöscht') && !str_contains($html, 'werden Ihre Beiträge anonymisiert'), 'Datenschutztext nennt den Löschmodus');
    T::check(str_contains(CommunityLegal::privacySection(['days' => CommunityRetention::DEFAULTS]), 'werden Ihre Beiträge anonymisiert'), 'Datenschutztext: Standard anonymisieren');
});

T::run('Legal-Baustein: Abschnitt „Forum (Community)“ der Datenschutzerklärung folgt den Einstellungen und Fristen', static function (): void {
    $days = ['ip_hash' => 30] + CommunityRetention::DEFAULTS;
    $facts = static fn (array $over = []): array => $over + ['guests_can_read' => false, 'notify_mail' => true, 'captcha_mode' => 'first_post', 'days' => $days];
    $html = CommunityLegal::privacySection($facts());
    T::check(str_starts_with($html, '<h3>8a. Forum (Community)</h3>'), 'Überschrift 8a (die übrigen Abschnitte behalten ihre Nummern)');

    // Wohlgeformt: lässt sich als HTML-Fragment ohne Fehler lesen, nur erlaubte Tags
    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    T::same([], array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors()), 'das HTML-Fragment ist wohlgeformt');
    libxml_clear_errors();
    $tags = [];
    foreach ($dom->getElementsByTagName('*') as $node) {
        $tags[$node->nodeName] = true;
    }
    unset($tags['html'], $tags['body']);
    T::same(['h3', 'p', 'ul', 'li'], array_keys($tags), 'nur h3, p, ul und li (der Purifier des Legal-Moduls lässt sie zu)');

    // Fristen: dieselben Zahlen wie die Aufbewahrungsregeln
    T::check(str_contains($html, 'Lesestatus: 180 Tage nach der letzten Änderung;') && str_contains($html, 'Entwürfe auf dem Server: 30 Tage nach der letzten Änderung;'), 'Lesestatus 180 Tage, Entwürfe 30 Tage (Standardfristen)');
    T::check(str_contains($html, 'Anhänge, die keinem Beitrag zugeordnet wurden: 1 Tag nach dem Hochladen;'), 'verwaiste Uploads: 1 Tag (Singular)');
    T::check(str_contains($html, 'erledigte Meldungen: 365 Tage nach der Bearbeitung;') && str_contains($html, 'Moderationsprotokoll: 365 Tage nach dem Eintrag;'), 'Meldungen und Protokoll 365 Tage');
    T::check(str_contains($html, 'Verwarnungen: 730 Tage nach ihrem Ablauf (Verwarnungen ohne Ablauf höchstens fünf Jahre') && str_contains($html, 'Sperren: 365 Tage nach der Aufhebung oder dem Ablauf (unbefristete Sperren bleiben'), 'Verwarnungen 730 Tage, Sperren 365 Tage, Höchstdauer genannt');
    T::check(str_contains($html, 'frühere Fassungen bearbeiteter Beiträge: 365 Tage nach der Bearbeitung (die jüngste Fassung bleibt)'), 'Bearbeitungsverlauf 365 Tage');
    T::same(CommunityRetention::WARNING_OPEN_DAYS, 1825, 'fünf Jahre = 1825 Tage (Höchstdauer unbefristeter Verwarnungen)');
    $changed = CommunityLegal::privacySection($facts(['days' => ['read_states' => 7, 'drafts' => 0, 'ip_hash' => 1] + $days]));
    T::check(str_contains($changed, 'Lesestatus: 7 Tage nach der letzten Änderung;') && str_contains($changed, 'Entwürfe auf dem Server: ohne feste Frist;') && str_contains($changed, 'für 1 Tag;'), 'geänderte Fristen erscheinen; 0 = ohne feste Frist; 1 Tag im Singular');

    // IP-Kennung nur, wenn sie gespeichert wird
    T::check(str_contains($html, 'gekürzte und nicht umkehrbar verschlüsselte Kennung der IP-Adresse') && str_contains($html, 'für 30 Tage;'), 'IP-Kennung genannt, mit Frist');
    $noIp = CommunityLegal::privacySection($facts(['days' => ['ip_hash' => 0] + $days]));
    T::check(!str_contains($noIp, 'Kennung der IP-Adresse') && !str_contains($noIp, 'Spam-Abwehr und Moderation') && !str_contains($noIp, 'IP-Kennung'), 'ip_hash_days = 0: keine IP-Kennung im Text');

    // Captcha nur, wenn verlangt
    $when = ['first_post' => 'vor dem ersten Beitrag eines neuen Mitglieds', 'trust0' => 'solange ein Mitglied die niedrigste Vertrauensstufe hat', 'always' => 'vor jedem neuen Thema und jeder Antwort'];
    foreach ($when as $mode => $text) {
        $section = CommunityLegal::privacySection($facts(['captcha_mode' => $mode]));
        T::check(str_contains($section, 'Sicherheitsprüfung (Captcha)') && str_contains($section, $text) && str_contains($section, 'keine Daten an Dritte übermittelt'), 'Captcha-Modus ' . $mode . ': genannt, lokal, ohne Dritte');
    }
    $off = CommunityLegal::privacySection($facts(['captcha_mode' => 'off']));
    $junk = CommunityLegal::privacySection($facts(['captcha_mode' => 'turnstile']));
    T::check(!str_contains($off, 'Captcha') && !str_contains($junk, 'Captcha'), 'Modus aus (oder unbekannt): kein Captcha im Text');

    // Benachrichtigungen, Sichtbarkeit
    T::check(str_contains($html, 'Benachrichtigungen: ') && !str_contains(CommunityLegal::privacySection($facts(['notify_mail' => false])), 'Benachrichtigungen: '), 'E-Mail-Benachrichtigungen nur, wenn sie an sind');
    T::check(str_contains($html, 'nur für angemeldete und berechtigte Mitglieder sichtbar') && str_contains(CommunityLegal::privacySection($facts(['guests_can_read' => true])), 'ohne Anmeldung gelesen werden'), 'Sichtbarkeit folgt guests_can_read');
    T::check(str_contains($html, 'Art. 6 Abs. 1 lit. b DSGVO') && str_contains($html, 'Art. 6 Abs. 1 lit. f DSGVO') && str_contains($html, 'Gelöschtes Mitglied'), 'Rechtsgrundlagen und Anonymisierung genannt');

    // Fakten aus den Einstellungen
    $fromSettings = CommunityLegal::facts(Settings::fromArray(['guests_can_read' => true, 'notify_mail' => false, 'captcha_mode' => 'always', 'erase_mode' => 'delete']), $days);
    T::same(['guests_can_read' => true, 'notify_mail' => false, 'captcha_mode' => 'always', 'erase_mode' => 'delete', 'days' => $days], $fromSettings, 'facts(): Einstellungen und Fristen');
    $defaults = CommunityLegal::facts(Settings::fromArray([]), $days);
    T::same([false, true, 'first_post', 'anonymize'], [$defaults['guests_can_read'], $defaults['notify_mail'], $defaults['captcha_mode'], $defaults['erase_mode']], 'facts(): Standardeinstellungen');
    // Der Abschnitt nennt jede Frist der Aufbewahrungsregeln (kein Eintrag fehlt)
    $missing = [];
    foreach (array_keys($days) as $key) {
        $probe = CommunityLegal::privacySection($facts(['days' => [$key => 4242] + $days]));
        if (!str_contains($probe, '4242 Tag')) {
            $missing[] = $key;
        }
    }
    T::same([], $missing, 'jede Frist (' . implode(', ', array_keys($days)) . ') wirkt auf den Text');

    // Einbindung in den Datenschutztext
    $module = (string) file_get_contents(ABSPATH . 'admin/modules/legal/LegalSitesModule.php');
    $body = substr($module, (int) strpos($module, 'private function buildPrivacyTemplate'));
    $community = strpos($body, '$this->buildCommunityPrivacySection()');
    T::check($community !== false && $community > strpos($body, '8. Kommentare und Beiträge') && $community < strpos($body, '9. Analyse und Reichweitenmessung'), 'Datenschutztext: der Abschnitt steht zwischen 8 und 9');
    $helper = substr($module, (int) strpos($module, 'private function buildCommunityPrivacySection'), 900);
    T::check(str_contains($helper, 'CommunityModule::isEnabled()') && str_contains($helper, 'catch (\Throwable') && str_contains($helper, "return '';"), 'nur bei aktivem Modul; ein Fehler lässt den Abschnitt weg');
    T::check(str_contains($module, "TEMPLATE_VERSION = '2026.11.22'"), 'Vorlagenversion angehoben (Betreiber werden auf die geänderte Vorlage hingewiesen)');
    $source = (string) file_get_contents(ABSPATH . 'core/Services/Community/CommunityLegal.php');
    T::check(!preg_match('~https?://~', (string) preg_replace('~/\*.*?\*/~s', '', $source)), 'der Baustein nennt keinen Drittanbieter und keine Adresse');
});

T::run('Einbindung (Quelltext)', static function (): void {
    $hooks = (string) file_get_contents(ABSPATH . 'core/Services/FeatureHooks/CommunityHooks.php');
    $gate = strpos($hooks, 'if (!CommunityModule::isEnabled())');
    foreach (["'dsgvo_export_data'", "'dsgvo_delete_data'", "'cms_user_deleted'", "'cms_member_export_data'", 'CommunityRetention::registerAll', 'CommunityPrivacy::pruneExports'] as $needle) {
        $at = strpos($hooks, $needle);
        T::check($at !== false && $at < $gate, $needle . ' ist angemeldet, auch bei abgeschaltetem Modul (vor dem Modul-Gate)');
    }
    $retention = (string) file_get_contents(ABSPATH . 'core/Services/Community/CommunityRetention.php');
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $retention);
    T::check(!preg_match('/(UPDATE|DELETE)[^;]*\bLIMIT\b/i', $code), 'Aufbewahrung: kein UPDATE/DELETE mit LIMIT (nicht portabel)');
    T::check(!str_contains($retention, 'NOW()') && !str_contains($retention, 'INTERVAL'), 'Aufbewahrung: Grenzen aus der Uhr der Anwendung (UTC), nicht aus NOW()');
    $privacy = (string) file_get_contents(ABSPATH . 'core/Services/Community/CommunityPrivacy.php');
    T::check(!str_contains($privacy, 'ip_hash FROM') && !str_contains($privacy, 'SELECT ip_hash') && !preg_match('/SELECT[^;]*\bip_hash\b[^;]*FROM/', $privacy), 'Auskunft liest nie die IP-Kennung');
});

T::finish();
