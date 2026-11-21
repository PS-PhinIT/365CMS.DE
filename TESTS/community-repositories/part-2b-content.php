<?php
/**
 * Teil der Suite `community-repositories` (Besitzer B1, PR #2b Welle 1): Inhalts-Repositories.
 *
 *  - PostRepository: Cache-Wächter (`storeRendered`, `clearRendered`, `applyEdit` mit HTML, `UPDATABLE` ohne Text und Cache),
 *    Job-Fenster (`renderBatch`, `maxId`), Mengenlesen (`findMany`, `lockMany`, Stücke zu 100, ohne `ip_hash` und Text),
 *    einzige Lesestelle von `ip_hash` (`ipHashes`), Anhänge (`withAttachments`, `topicHasPublishedAttachments`), Spam-Dublette
 *    (`recentWithBody`), Moderation (`publishedByAuthor`, `setTopicForum`, `moveToTopic`, `bumpReactions`, `queue`, `queueSummary`).
 *  - TopicRepository: `listAnnouncements`, `countPinned`, `listMoved`, `stubIdsFor`, `deleteStub`, `expiredIds`/`clearExpired`
 *    (Grenze einschließlich, erneute Prüfung), `bumpReactions`.
 *  - ModerationLogRepository: Form der Einträge (Aktionen, Zielarten, `DETAIL_KEYS`, 4000 Byte), `addMany`, `list` (Keyset mit gleichen
 *    Zeitstempeln, Forenfilter je Forum oder `IN`), `latestFor`.
 *
 * Läuft gegen das SQLite-Double und, mit `CMS_TEST_DB_HOST`, gegen MariaDB. Geladen von `run.php` vor `T::finish()`; nutzt dessen
 * Hilfen (`rt()`, `rIds()`, `rCollect()`, `rSource()`).
 */

declare(strict_types=1);

use CMS\Database;
use CMS\Services\Community\Repository\ModerationLogRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Support\Cursor;

/** Ruft `$call` auf und erwartet eine `InvalidArgumentException`. */
function b1Throws(\Closure $call, string $message): void
{
    try {
        $call();
        T::check(false, $message);
    } catch (\InvalidArgumentException) {
        T::check(true, $message);
    }
}

/** Zahl der protokollierten Anweisungen, die auf das Muster passen. */
function b1Statements(Database $db, string $pattern): int
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    return count(array_filter($db->log, static fn (string $sql): bool => preg_match($pattern, $sql) === 1));
}

/**
 * Gebundene Parameter der protokollierten Anweisungen, die auf das Muster passen.
 *
 * @return list<list<mixed>>
 */
function b1Params(Database $db, string $pattern): array
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $params = [];
    foreach ($db->log as $i => $sql) {
        if (preg_match($pattern, $sql) === 1) {
            $params[] = $db->paramsAt($i);
        }
    }

    return $params;
}

/** Zeile direkt aus der Tabelle (ohne Repository). */
function b1Row(Database $db, string $table, int $id): ?array
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    return $db->row($table, ['id' => $id]);
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// PostRepository
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

function b1ScenarioPostCache(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new PostRepository($db);
    $forum = $db->forum();
    $user = $db->user();
    $topic = $db->topic(['forum' => $forum, 'user' => $user]);
    $id = $repo->insert(['topic_id' => $topic, 'forum_id' => $forum, 'user_id' => $user, 'status' => 'published', 'body' => 'A', 'created_at' => rt(), 'updated_at' => rt()]);

    T::same(1, $repo->storeRendered($id, 0, '<p>A</p>', 3), 'storeRendered: leerer Cache, edit_count passt → 1 Zeile');
    $row = b1Row($db, 'community_posts', $id);
    T::same(['<p>A</p>', 3, 0, rt()], [$row['body_html'], (int) $row['rendered_version'], (int) $row['edit_count'], $row['updated_at']], 'storeRendered: HTML und Version gesetzt, edit_count und updated_at unverändert');
    T::same(0, $repo->storeRendered($id, 0, '<p>B</p>', 3), 'storeRendered: gleiche Version ohne replaceCurrent → 0 (aktuelle Zeile bleibt)');
    T::same('<p>A</p>', b1Row($db, 'community_posts', $id)['body_html'], 'gleiche Version überschreibt nicht');
    T::same(0, $repo->storeRendered($id, 0, '<p>C</p>', 2), 'storeRendered: ältere Version überschreibt keine neuere');
    T::same(1, $repo->storeRendered($id, 0, '<p>B</p>', 3, true), 'storeRendered mit replaceCurrent erneuert dieselbe Version (Job „alles neu“)');
    T::same(['<p>B</p>', 3], [b1Row($db, 'community_posts', $id)['body_html'], (int) b1Row($db, 'community_posts', $id)['rendered_version']], 'replaceCurrent: HTML erneuert, Version bleibt');
    $repo->storeRendered($id, 0, '<p>B</p>', 3, true);
    T::same('<p>B</p>', b1Row($db, 'community_posts', $id)['body_html'], 'zweimal derselbe Inhalt ändert nichts');
    T::same(0, $repo->storeRendered($id, 0, '<p>X</p>', 3, false), 'ohne replaceCurrent bleibt die Zeile nach dem Erneuern');
    T::same(1, $repo->storeRendered($id, 0, '<p>D</p>', 4), 'storeRendered: neuere Version überschreibt');

    // Bearbeitung zwischen Lesen und Schreiben des Nachrenderers: der Wächter edit_count greift.
    T::check($repo->applyEdit($id, 'Neu', $user, null, rt(60)), 'applyEdit ohne HTML (Verhalten #2a)');
    $row = b1Row($db, 'community_posts', $id);
    T::same(['Neu', null, 0, 1], [$row['body'], $row['body_html'], (int) $row['rendered_version'], (int) $row['edit_count']], 'applyEdit ohne HTML: Cache NULL, Version 0, edit_count 1');
    T::same(0, $repo->storeRendered($id, 0, '<p>A alt</p>', 4), 'storeRendered mit veraltetem edit_count (0 statt 1) → 0: kein HTML eines älteren Texts');
    T::same(0, $repo->storeRendered($id, 2, '<p>falsch</p>', 4), 'storeRendered mit größerem edit_count als gespeichert → 0');
    T::same(null, b1Row($db, 'community_posts', $id)['body_html'], 'nach beiden Versuchen bleibt der Cache leer');
    T::same(1, $repo->storeRendered($id, 1, '<p>Neu</p>', 4), 'storeRendered mit aktuellem edit_count → 1');

    // applyEdit mit HTML in einer Anweisung
    $db->log = [];
    T::check($repo->applyEdit($id, 'Neuer', $user, 'Grund', rt(120), '<p>Neuer</p>', 7), 'applyEdit mit HTML: Beitrag gefunden');
    T::same(1, count($db->log), 'applyEdit: eine Anweisung');
    $row = b1Row($db, 'community_posts', $id);
    T::same(['Neuer', '<p>Neuer</p>', 7, 2, rt(120), $user, 'Grund', rt(120)], [$row['body'], $row['body_html'], (int) $row['rendered_version'], (int) $row['edit_count'], $row['edited_at'], (int) $row['edited_by'], $row['edit_reason'], $row['updated_at']], 'applyEdit mit HTML: Text, Cache, Version, edit_count + 1, Bearbeiter, Grund, Zeit');
    $repo->applyEdit($id, 'Ohne', $user, null, rt(130), null, 7);
    T::same([null, 0, 3], [b1Row($db, 'community_posts', $id)['body_html'], (int) b1Row($db, 'community_posts', $id)['rendered_version'], (int) b1Row($db, 'community_posts', $id)['edit_count']], 'applyEdit ohne HTML setzt die Version auf 0, auch wenn eine Version übergeben wird');
    b1Throws(static fn () => $repo->applyEdit($id, 'x', $user, null, rt(140), '<p>x</p>', 0), 'applyEdit mit HTML und Version 0 → InvalidArgumentException');
    T::same('Ohne', b1Row($db, 'community_posts', $id)['body'], 'abgelehnte Bearbeitung schreibt nichts');
    foreach ([0, -1, 65536] as $version) {
        b1Throws(static fn () => $repo->storeRendered($id, 3, '<p>x</p>', $version), "storeRendered mit Version {$version} → InvalidArgumentException");
    }
    T::same(1, $repo->storeRendered($id, 3, '<p>max</p>', PostRepository::MAX_RENDERED_VERSION), 'storeRendered mit der größten Version 65535');

    // Anhangsmenge geändert ohne Textänderung: Cache verwerfen und edit_count erhöhen (R7)
    $before = (int) b1Row($db, 'community_posts', $id)['edit_count'];
    T::check($repo->clearRendered($id, rt(200)), 'clearRendered: Beitrag gefunden');
    $row = b1Row($db, 'community_posts', $id);
    T::same(['Ohne', null, 0, $before + 1, rt(200)], [$row['body'], $row['body_html'], (int) $row['rendered_version'], (int) $row['edit_count'], $row['updated_at']], 'clearRendered: Text bleibt, Cache NULL, Version 0, edit_count + 1, updated_at');
    T::same(0, $repo->storeRendered($id, $before, '<p>mit altem Anhang</p>', 9), 'nach clearRendered schreibt ein Nachrenderer mit dem alten edit_count nichts');
    T::same(false, $repo->clearRendered(99999, rt(200)), 'clearRendered: unbekannt → false');
    T::same(0, $repo->storeRendered(99999, 0, '<p>x</p>', 1), 'storeRendered: unbekannt → 0');

    foreach (['body' => 'x', 'body_html' => '<p>x</p>', 'rendered_version' => 1] as $column => $value) {
        b1Throws(static fn () => $repo->update($id, [$column => $value]), "update lehnt {$column} ab (Text und Cache nur über applyEdit/storeRendered/clearRendered)");
    }
}

function b1ScenarioRenderBatch(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new PostRepository($db);
    T::same(0, $repo->maxId(), 'maxId: leere Tabelle → 0');
    $forum = $db->forum();
    $user = $db->user();
    $topic = $db->topic(['forum' => $forum, 'user' => $user]);
    $make = static fn (int $version, ?int $author = null): int => $repo->insert(['topic_id' => $topic, 'forum_id' => $forum, 'user_id' => $author, 'status' => 'published', 'body' => 'Text v' . $version, 'body_html' => $version > 0 ? '<p>v</p>' : null, 'rendered_version' => $version, 'created_at' => rt(), 'updated_at' => rt()]);
    $p1 = $make(0, $user);
    $p2 = $make(1);
    $p3 = $make(2);
    $p4 = $make(0);
    $p5 = $make(1);
    $p6 = $make(0);
    T::same($p6, $repo->maxId(), 'maxId: größte ID');

    T::same([$p1, $p2, $p4, $p5], rIds($repo->renderBatch(0, $p5, 2, 100)), 'renderBatch: Version unter der Grenze, bis untilId einschließlich, aufsteigend');
    T::same([$p2, $p4, $p5], rIds($repo->renderBatch($p1, $p5, 2, 100)), 'renderBatch: afterId ausschließlich');
    T::same([$p1, $p4], rIds($repo->renderBatch(0, $p6, 1, 2)), 'renderBatch: Grenze limit');
    T::same([$p1, $p4, $p6], rIds($repo->renderBatch(0, $p6, 1, 100)), 'renderBatch: Fenster bis zur größten ID');
    T::same([], $repo->renderBatch($p6, $p6 + 5000, 99, 100), 'renderBatch: hinter dem letzten Beitrag leer');
    T::same([$p1], rIds($repo->renderBatch(0, $p6, 1, 0)), 'renderBatch: limit 0 → 1');
    $db->log = [];
    $repo->renderBatch(0, $p6, 1, 1000);
    T::same(PostRepository::MAX_LIMIT, (int) (b1Params($db, '/rendered_version < \?/')[0][3] ?? 0), 'renderBatch: limit höchstens MAX_LIMIT');
    $row = $repo->renderBatch(0, $p1, 1, 1)[0];
    T::same(['id' => $p1, 'body' => 'Text v0', 'edit_count' => 0, 'user_id' => $user], $row, 'renderBatch: Form id, body, edit_count, user_id mit Ganzzahlen');
    T::same(null, $repo->renderBatch($p1, $p2, 2, 1)[0]['user_id'], 'renderBatch: user_id NULL bleibt null');
}

function b1ScenarioPostSets(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new PostRepository($db);
    $forum = $db->forum();
    $user = $db->user();
    $topic = $db->topic(['forum' => $forum, 'user' => $user]);
    $a = $db->post(['topic' => $topic, 'user' => $user, 'first' => true, 'body' => 'geheim A']);
    $b = $db->post(['topic' => $topic, 'user' => $user, 'status' => 'hidden', 'body' => 'geheim B']);
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_posts SET ip_hash = ?, body_html = ? WHERE id = ?', [str_repeat('a', 64), '<p>geheim A</p>', $a]);

    $expected = array_values(array_diff(array_keys((array) $repo->find($a)), ['ip_hash', 'body', 'body_html']));
    $many = $repo->findMany([$b, $a, $a, 0, -3, 99999]);
    T::same([$a, $b], array_keys($many), 'findMany: Schlüssel = ID, aufsteigend, eindeutig; fehlende und ungültige IDs fehlen');
    T::same($expected, array_keys($many[$a]), 'findMany: alle Spalten außer ip_hash, body, body_html');
    T::same(['hidden', $topic], [$many[$b]['status'], (int) $many[$b]['topic_id']], 'findMany: Status und Thema');
    $db->log = [];
    T::same([], $repo->findMany([]), 'findMany: leer → []');
    T::same([], $repo->findMany([0, -1]), 'findMany: nur ungültige IDs → []');
    T::same(0, count($db->log), 'findMany ohne gültige ID: keine Abfrage');

    $db->log = [];
    $repo->findMany(array_reverse(range(1, 250)));
    $chunks = b1Params($db, '/^SELECT .* FROM \S*community_posts WHERE id IN \(/');
    T::same([100, 100, 50], array_map('count', $chunks), 'findMany: 250 IDs in drei Stücken zu höchstens 100');
    T::same([range(1, 100), range(101, 200), range(201, 250)], $chunks, 'findMany: Stücke aufsteigend, auch bei absteigender Eingabe');

    $db->log = [];
    $locked = $repo->lockMany([$b, $a]);
    T::same([$a, $b], array_keys($locked), 'lockMany: Zeilen aufsteigend');
    T::same($expected, array_keys($locked[$a]), 'lockMany: dieselben Spalten wie findMany');
    T::same(1, b1Statements($db, '/ WHERE id IN \(\?, \?\) ORDER BY id ASC FOR UPDATE$/'), 'lockMany: IN-Liste in Schlüsselfolge mit FOR UPDATE');
    $db->log = [];
    $repo->lockMany(range(1, 150));
    T::same([[...range(1, 100)], [...range(101, 150)]], b1Params($db, '/FOR UPDATE$/'), 'lockMany: Stücke zu 100, aufsteigend, je Stück gesperrt');
    $db->log = [];
    $repo->findMany([$a]);
    T::same(0, b1Statements($db, '/FOR UPDATE/'), 'findMany sperrt nicht');

    T::same([$a => str_repeat('a', 64), $b => null], $repo->ipHashes([$b, $a, 99999]), 'ipHashes: je Beitrag der Wert oder null, fehlende fehlen, aufsteigend');
    T::same([], $repo->ipHashes([]), 'ipHashes: leer → []');
    $db->log = [];
    $repo->ipHashes(range(1, 101));
    T::same([100, 1], array_map('count', b1Params($db, '/SELECT id, ip_hash FROM/')), 'ipHashes: Stücke zu 100');
}

function b1ScenarioAttachments(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new PostRepository($db);
    $forum = $db->forum();
    $user = $db->user();
    $topic = $db->topic(['forum' => $forum, 'user' => $user]);
    $empty = $db->topic(['forum' => $forum, 'user' => $user]);
    $p1 = $db->post(['topic' => $topic, 'user' => $user, 'first' => true]);
    $p2 = $db->post(['topic' => $topic, 'user' => $user]);
    $p3 = $db->post(['topic' => $topic, 'user' => $user]);
    $db->attachment(['post' => $p1, 'user' => $user]);
    $db->attachment(['post' => $p1, 'user' => $user]);
    $db->attachment(['post' => $p3, 'user' => $user]);
    $db->attachment(['user' => $user, 'token' => str_repeat('b', 32)]);

    T::same([$p1, $p3], $repo->withAttachments([$p3, $p2, $p1, 99999]), 'withAttachments: Beiträge mit mindestens einem Anhang, eindeutig, aufsteigend');
    T::same([], $repo->withAttachments([$p2]), 'withAttachments: Beitrag ohne Anhang');
    $db->log = [];
    T::same([], $repo->withAttachments([]), 'withAttachments: leer → []');
    T::same(0, count($db->log), 'withAttachments ohne ID: keine Abfrage');

    T::check($repo->topicHasPublishedAttachments($topic), 'topicHasPublishedAttachments: veröffentlichter Beitrag mit Anhang');
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_posts SET status = ? WHERE id = ?', ['hidden', $p1]);
    T::check($repo->topicHasPublishedAttachments($topic), 'ein weiterer veröffentlichter Beitrag mit Anhang genügt');
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_posts SET status = ? WHERE id = ?', ['pending', $p3]);
    T::same(false, $repo->topicHasPublishedAttachments($topic), 'topicHasPublishedAttachments: Anhänge nur an ausgeblendeten/wartenden Beiträgen → false');
    T::same(false, $repo->topicHasPublishedAttachments($empty), 'Thema ohne Beiträge → false');
}

function b1ScenarioRecentWithBody(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new PostRepository($db);
    $forum = $db->forum();
    $user = $db->user();
    $other = $db->user();
    $topic = $db->topic(['forum' => $forum, 'user' => $user]);
    $db->post(['topic' => $topic, 'user' => $user, 'body' => 'zu alt', 'created_at' => rt(-7200)]);
    $published = $db->post(['topic' => $topic, 'user' => $user, 'body' => 'eins', 'created_at' => rt(-60)]);
    $rejected = $db->post(['topic' => $topic, 'user' => $user, 'body' => 'zwei', 'status' => 'rejected', 'created_at' => rt(-30)]);
    $deleted = $db->post(['topic' => $topic, 'user' => $user, 'body' => 'drei', 'status' => 'deleted', 'status_reason' => 'moderator', 'created_at' => rt(-30)]);
    $hidden = $db->post(['topic' => $topic, 'user' => $user, 'body' => 'vier', 'status' => 'hidden', 'created_at' => rt(-10)]);
    $pending = $db->post(['topic' => $topic, 'user' => $user, 'body' => 'fünf', 'status' => 'pending', 'created_at' => rt(-3600)]);
    $db->post(['topic' => $topic, 'user' => $other, 'body' => 'fremd', 'created_at' => rt(-5)]);

    $db->log = [];
    $rows = $repo->recentWithBody($user, rt(-3600));
    T::same([$hidden, $deleted, $rejected, $published, $pending], rIds($rows), 'recentWithBody: alle Status, jüngste zuerst (gleiche Zeit: größere ID zuerst), Grenze einschließlich, nur das Mitglied');
    T::same(['id' => $deleted, 'topic_id' => $topic, 'status' => 'deleted', 'status_reason' => 'moderator', 'body' => 'drei'], $rows[1], 'recentWithBody: Form mit Ganzzahlen, Status, Grund und Text');
    T::same([$user, ...PostRepository::STATUSES, rt(-3600), 20], b1Params($db, '/^SELECT id, topic_id, status, status_reason, body FROM/')[0] ?? [], 'recentWithBody: vollständige Statusliste als Parameter (Index idx_community_posts_user), Standardgrenze 20');
    T::same([$hidden], rIds($repo->recentWithBody($user, rt(-3600), 0)), 'recentWithBody: limit 0 → 1');
    $db->log = [];
    $repo->recentWithBody($user, rt(-3600), 500);
    T::same(50, (int) (array_slice(b1Params($db, '/^SELECT id, topic_id, status/')[0] ?? [], -1)[0] ?? 0), 'recentWithBody: limit höchstens 50');
    T::same([], $repo->recentWithBody($user, rt(1)), 'recentWithBody: nichts seit der Grenze');
    T::check(!array_key_exists('ip_hash', $rows[0]), 'recentWithBody liefert kein ip_hash');
}

function b1ScenarioPostModeration(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new PostRepository($db);
    $f1 = $db->forum();
    $f2 = $db->forum();
    $f3 = $db->forum();
    $u1 = $db->user();
    $u2 = $db->user();
    $u3 = $db->user();
    $topic = $db->topic(['forum' => $f1, 'user' => $u1, 'title' => 'Quelle']);
    $target = $db->topic(['forum' => $f2, 'user' => $u2, 'title' => 'Ziel']);
    $first = $db->post(['topic' => $topic, 'user' => $u1, 'first' => true]);
    $r1 = $db->post(['topic' => $topic, 'user' => $u1]);
    $r2 = $db->post(['topic' => $topic, 'user' => $u2]);
    $r3 = $db->post(['topic' => $topic, 'user' => null]);
    $db->post(['topic' => $topic, 'user' => $u1, 'status' => 'pending']);
    $db->post(['topic' => $topic, 'user' => $u3, 'status' => 'hidden']);
    $targetFirst = $db->post(['topic' => $target, 'user' => $u2, 'first' => true]);

    T::same(['total' => 4, 'users' => [$u1 => 2, $u2 => 1]], $repo->publishedByAuthor($topic), 'publishedByAuthor: veröffentlichte Beiträge je Autor, total mit Autor NULL, wartende und ausgeblendete zählen nicht');
    T::same(['total' => 0, 'users' => []], $repo->publishedByAuthor(99999), 'publishedByAuthor: unbekanntes Thema');

    T::same(6, $repo->setTopicForum($topic, $f3), 'setTopicForum: alle sechs Beiträge des Themas');
    T::same([$f3], array_values(array_unique(array_map(static fn (array $r): int => (int) $r['forum_id'], $db->rows('community_posts', ['topic_id' => $topic])))), 'setTopicForum: forum_id aller Beiträge');
    T::same($f2, (int) b1Row($db, 'community_posts', $targetFirst)['forum_id'], 'setTopicForum: Beiträge anderer Themen bleiben');

    T::same(2, $repo->moveToTopic([$r2, $first, $r1, 99999], $target, $f2), 'moveToTopic: zwei Beiträge verschoben, der erste Beitrag nie');
    foreach ([$r1, $r2] as $moved) {
        $row = b1Row($db, 'community_posts', $moved);
        T::same([$target, $f2], [(int) $row['topic_id'], (int) $row['forum_id']], 'moveToTopic: topic_id und forum_id des Ziels (Beitrag ' . $moved . ')');
    }
    T::same([$topic, $f3], [(int) b1Row($db, 'community_posts', $first)['topic_id'], (int) b1Row($db, 'community_posts', $first)['forum_id']], 'moveToTopic: erster Beitrag bleibt im Quellthema');
    T::same($topic, (int) b1Row($db, 'community_posts', $r3)['topic_id'], 'moveToTopic: nicht genannte Beiträge bleiben');
    T::same(0, $repo->moveToTopic([], $target, $f2), 'moveToTopic: leer → 0');

    $repo->bumpReactions($r1, 3);
    T::same(3, (int) b1Row($db, 'community_posts', $r1)['reaction_count'], 'bumpReactions: +3');
    $repo->bumpReactions($r1, -5);
    T::same(0, (int) b1Row($db, 'community_posts', $r1)['reaction_count'], 'bumpReactions: nie unter 0');
}

function b1ScenarioQueue(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new PostRepository($db);
    $f1 = $db->forum();
    $f2 = $db->forum();
    $user = $db->user();
    $t1 = $db->topic(['forum' => $f1, 'user' => $user, 'title' => 'Wartend', 'status' => 'pending']);
    $t2 = $db->topic(['forum' => $f2, 'user' => $user, 'title' => 'Offen']);
    $q1 = $db->post(['topic' => $t1, 'user' => $user, 'first' => true, 'status' => 'pending', 'body' => 'Rohtext', 'created_at' => rt(10)]);
    $q2 = $db->post(['topic' => $t2, 'user' => $user, 'status' => 'pending', 'created_at' => rt(20)]);
    $q3 = $db->post(['topic' => $t2, 'user' => $user, 'status' => 'pending', 'created_at' => rt(20)]);
    $q4 = $db->post(['topic' => $t1, 'user' => $user, 'status' => 'pending', 'created_at' => rt(20)]);
    $q0 = $db->post(['topic' => $t2, 'user' => $user, 'status' => 'pending', 'created_at' => rt(5)]);
    $db->post(['topic' => $t2, 'user' => $user, 'status' => 'published', 'created_at' => rt(1)]);
    $db->post(['topic' => $t2, 'user' => $user, 'status' => 'hidden', 'created_at' => rt(2)]);
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_posts SET ip_hash = ? WHERE id = ?', [str_repeat('c', 64), $q1]);

    [$rows, $pages] = rCollect(static fn (?Cursor $c) => $repo->queue(null, $c, 2));
    T::same([$q0, $q1, $q2, $q3, $q4], rIds($rows), 'queue: wartende Beiträge aller Foren, älteste zuerst, gleiche Zeit nach ID, ohne Verlust und Doppelung');
    T::same(3, $pages, 'queue: drei Seiten zu zwei');
    $first = $repo->queue(null, null, 5)->items[1];
    T::same(['Wartend', 'pending', 'Rohtext'], [$first['topic_title'], $first['topic_status'], $first['body']], 'queue: topic_title, topic_status und Rohtext (Dienst neutralisiert)');
    T::check(!array_key_exists('ip_hash', $first), 'queue: kein ip_hash');
    T::same(null, $repo->queue(null, null, 5)->next, 'queue: letzte Seite ohne next');
    T::same([$q1, $q4], rIds($repo->queue([$f1], null, 10)->items), 'queue: nur die genannten Foren');
    T::same([$q0, $q1, $q2, $q3, $q4], rIds($repo->queue([$f2, $f1, $f1], null, 10)->items), 'queue: mehrere Foren');
    $db->log = [];
    $empty = $repo->queue([], null, 10);
    T::same([[], null, 0], [$empty->items, $empty->next, count($db->log)], 'queue: leere Forenliste → leere Seite ohne Abfrage');
    T::same(1, count($repo->queue(null, null, 0)->items), 'queue: limit 0 → 1');

    T::same(['count' => 5, 'oldest_at' => rt(5)], $repo->queueSummary(null), 'queueSummary: Zahl und ältester wartender Beitrag');
    T::same(['count' => 2, 'oldest_at' => rt(10)], $repo->queueSummary([$f1]), 'queueSummary: nur die genannten Foren');
    $db->log = [];
    T::same(['count' => 0, 'oldest_at' => null], $repo->queueSummary([]), 'queueSummary: leere Forenliste ohne Abfrage');
    T::same(0, count($db->log), 'queueSummary: keine Abfrage bei leerer Liste');

    // Obergrenze der Zählung: ein mehrzeiliges INSERT (schnell auf beiden Backends)
    $values = [];
    $params = [];
    for ($i = 0; $i < PostRepository::QUEUE_COUNT_MAX; $i++) {
        $values[] = '(?, ?, ?, ?, ?, ?, ?)';
        array_push($params, $t2, $f2, $user, 'pending', 'x', rt(100), rt(100));
    }
    $db->execute('INSERT INTO ' . $db->getPrefix() . 'community_posts (topic_id, forum_id, user_id, status, body, created_at, updated_at) VALUES ' . implode(', ', $values), $params);
    T::same(PostRepository::QUEUE_COUNT_MAX, $repo->queueSummary(null)['count'], 'queueSummary: höchstens QUEUE_COUNT_MAX (1005 wartende)');
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// TopicRepository
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

function b1ScenarioAnnouncements(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new TopicRepository($db);
    $f1 = $db->forum();
    $f2 = $db->forum();
    $f3 = $db->forum();
    $user = $db->user();
    $announce = static function (int $forum, string $at, string $status = 'published') use ($db, $user): int {
        $id = $db->topic(['forum' => $forum, 'user' => $user, 'status' => $status, 'last_post_at' => $at]);
        $db->execute('UPDATE ' . $db->getPrefix() . 'community_topics SET is_announcement = 1 WHERE id = ?', [$id]);

        return $id;
    };
    $a1 = $announce($f1, rt(10));
    $a2 = $announce($f2, rt(30));
    $announce($f1, rt(50), 'pending');
    $announce($f3, rt(60));
    $db->topic(['forum' => $f1, 'user' => $user, 'last_post_at' => rt(70)]);
    $a5 = $announce($f2, rt(30));

    T::same([$a5, $a2, $a1], rIds($repo->listAnnouncements([$f2, $f1])), 'listAnnouncements: veröffentlichte Ankündigungen der Foren, jüngste Aktivität zuerst, gleiche Zeit nach ID absteigend');
    T::same([$a5], rIds($repo->listAnnouncements([$f1, $f2], 1)), 'listAnnouncements: limit');
    T::same([$a1], rIds($repo->listAnnouncements([$f1, 0, -2])), 'listAnnouncements: wartende Ankündigung, andere Foren und normale Themen fehlen');
    $row = $repo->listAnnouncements([$f1])[0];
    T::same([$f1, 1, 'published'], [(int) $row['forum_id'], (int) $row['is_announcement'], $row['status']], 'listAnnouncements: ganze Themenzeile');
    $db->log = [];
    T::same([], $repo->listAnnouncements([]), 'listAnnouncements: leere Forenliste → []');
    T::same(0, count($db->log), 'listAnnouncements: keine Abfrage bei leerer Liste');
    $db->log = [];
    $repo->listAnnouncements([$f1], 99);
    T::same([$f1, TopicRepository::MAX_ANNOUNCEMENTS], b1Params($db, '/is_announcement = 1/')[0] ?? [], 'listAnnouncements: limit höchstens MAX_ANNOUNCEMENTS');
    T::same(1, count($repo->listAnnouncements([$f1, $f2], 0)), 'listAnnouncements: limit 0 → 1');
}

function b1ScenarioTopicModeration(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new TopicRepository($db);
    $forum = $db->forum();
    $other = $db->forum();
    $user = $db->user();
    $now = rt();

    // Anheften: alle wieder veröffentlichbaren Status, wirksame Frist
    $db->topic(['forum' => $forum, 'pinned' => true]);
    $db->topic(['forum' => $forum, 'pinned' => true, 'status' => 'pending']);
    $db->topic(['forum' => $forum, 'pinned' => true, 'status' => 'hidden']);
    $db->topic(['forum' => $forum, 'pinned' => true, 'pinned_until' => rt(60)]);
    $db->topic(['forum' => $forum, 'pinned' => true, 'status' => 'deleted']);
    $db->topic(['forum' => $forum, 'pinned' => true, 'pinned_until' => rt(-60)]);
    $db->topic(['forum' => $forum, 'pinned' => true, 'pinned_until' => $now]);
    $db->topic(['forum' => $forum]);
    $db->topic(['forum' => $other, 'pinned' => true]);
    T::same(4, $repo->countPinned($forum, $now), 'countPinned: published, pending, hidden und Frist in der Zukunft; gelöscht, abgelaufen (auch genau jetzt), andere Foren nicht');

    // Verweis-Themen
    $moved = static function (int $forumId, int $to, ?string $expires, string $lastAt, bool $pinned = false) use ($db): int {
        $id = $db->topic(['forum' => $forumId, 'status' => 'moved', 'last_post_at' => $lastAt, 'pinned' => $pinned]);
        $db->execute('UPDATE ' . $db->getPrefix() . 'community_topics SET moved_to_topic_id = ?, moved_expires_at = ? WHERE id = ?', [$to, $expires, $id]);

        return $id;
    };
    $target = $db->topic(['forum' => $other, 'user' => $user]);
    $s1 = $moved($forum, $target, rt(3600), rt(10));
    $s2 = $moved($forum, $target, null, rt(20));
    $sExpired = $moved($forum, $target, rt(-1), rt(30));
    $sNow = $moved($forum, $target, $now, rt(40));
    $moved($forum, $target, rt(3600), rt(50), true);
    $sOther = $moved($forum, $target + 1000, rt(3600), rt(5));
    $moved($other, $target, rt(3600), rt(60));
    T::same([$s2, $s1, $sOther], rIds($repo->listMoved($forum, $now)), 'listMoved: wirksame Verweise des Forums (Frist leer oder in der Zukunft), jüngste Aktivität zuerst; abgelaufen und angeheftet nicht');
    T::same([$s2], rIds($repo->listMoved($forum, $now, 1)), 'listMoved: limit');
    T::same([$s1, $s2, $sExpired, $sNow], $repo->stubIdsFor($forum, $target), 'stubIdsFor: Verweise im Forum auf das Thema, aufsteigend; angehefteter, fremdes Ziel und anderes Forum nicht');
    T::same([], $repo->stubIdsFor($other, $target + 1000), 'stubIdsFor: keine Verweise');

    T::check($repo->deleteStub($s1), 'deleteStub: Verweis gelöscht');
    T::same(null, b1Row($db, 'community_topics', $s1), 'deleteStub: Zeile weg');
    T::same(false, $repo->deleteStub($target), 'deleteStub: ein normales Thema bleibt');
    T::check(b1Row($db, 'community_topics', $target) !== null, 'deleteStub: Thema noch vorhanden');
    T::same(false, $repo->deleteStub(99999), 'deleteStub: unbekannt → false');

    // Ablauf befristeter Zustände
    $l1 = $db->topic(['forum' => $forum, 'locked' => true, 'locked_until' => rt(-100)]);
    $l2 = $db->topic(['forum' => $other, 'locked' => true, 'locked_until' => $now]);
    $l3 = $db->topic(['forum' => $forum, 'locked' => true, 'locked_until' => rt(-200)]);
    $db->topic(['forum' => $forum, 'locked' => true, 'locked_until' => rt(100)]);
    $db->topic(['forum' => $forum, 'locked' => true]);
    $db->topic(['forum' => $forum, 'locked_until' => rt(-100)]);
    $expired = $repo->expiredIds('lock', $now, 10);
    T::same([['id' => $l3, 'forum_id' => $forum], ['id' => $l1, 'forum_id' => $forum], ['id' => $l2, 'forum_id' => $other]], $expired, 'expiredIds(lock): abgelaufene Sperren, Grenze einschließlich, früheste Frist zuerst; ohne Frist, Frist in der Zukunft und nicht gesperrt nicht');
    T::same([['id' => $l3, 'forum_id' => $forum]], $repo->expiredIds('lock', $now, 1), 'expiredIds: limit');
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_topics SET locked_until = ? WHERE id = ?', [rt(500), $l1]);
    $version = (int) b1Row($db, 'community_topics', $l3)['version'];
    T::same(2, $repo->clearExpired('lock', array_column($expired, 'id'), $now), 'clearExpired(lock): zwei Themen; die inzwischen verlängerte Frist bleibt');
    $row = b1Row($db, 'community_topics', $l3);
    T::same([0, null, $version + 1, $now], [(int) $row['is_locked'], $row['locked_until'], (int) $row['version'], $row['updated_at']], 'clearExpired(lock): Schalter und Frist zurückgesetzt, version + 1, updated_at');
    T::same([1, rt(500)], [(int) b1Row($db, 'community_topics', $l1)['is_locked'], b1Row($db, 'community_topics', $l1)['locked_until']], 'clearExpired: verlängerte Sperre unverändert');
    T::same(0, $repo->clearExpired('lock', array_column($expired, 'id'), $now), 'clearExpired: zweiter Lauf 0');
    $db->log = [];
    T::same(0, $repo->clearExpired('lock', [], $now), 'clearExpired: leere Liste → 0');
    T::same(0, count($db->log), 'clearExpired: keine Anweisung bei leerer Liste');

    $p1 = $db->topic(['forum' => $forum, 'pinned' => true, 'pinned_until' => rt(-5)]);
    $p2 = $db->topic(['forum' => $forum, 'pinned' => true, 'pinned_until' => $now]);
    T::same([$p1, $p2], array_values(array_intersect(array_column($repo->expiredIds('pin', $now, 50), 'id'), [$p1, $p2])), 'expiredIds(pin): abgelaufene Anheftungen, Grenze einschließlich');
    T::same(2, $repo->clearExpired('pin', [$p1, $p2], $now), 'clearExpired(pin)');
    T::same([0, null], [(int) b1Row($db, 'community_topics', $p2)['is_pinned'], b1Row($db, 'community_topics', $p2)['pinned_until']], 'clearExpired(pin): Anheftung gelöst');

    $stubs = array_column($repo->expiredIds('moved', $now, 50), 'id');
    T::same([$sExpired, $sNow], $stubs, 'expiredIds(moved): abgelaufene Verweise, Grenze einschließlich; ohne Frist nicht');
    T::same(2, $repo->clearExpired('moved', [...$stubs, $target, $s2], $now), 'clearExpired(moved): löscht nur abgelaufene Verweise');
    T::same([null, null], [b1Row($db, 'community_topics', $sExpired), b1Row($db, 'community_topics', $sNow)], 'clearExpired(moved): Verweise gelöscht');
    T::check(b1Row($db, 'community_topics', $target) !== null && b1Row($db, 'community_topics', $s2) !== null, 'clearExpired(moved): Thema und Verweis ohne Frist bleiben');
    b1Throws(static fn () => $repo->expiredIds('solved', $now, 10), 'expiredIds: unbekannte Art → InvalidArgumentException');
    b1Throws(static fn () => $repo->clearExpired('solved', [1], $now), 'clearExpired: unbekannte Art → InvalidArgumentException');
    $db->log = [];
    $repo->expiredIds('lock', $now, 99999);
    T::same(TopicRepository::EXPIRE_MAX, (int) (b1Params($db, '/locked_until <= \?/')[0][1] ?? 0), 'expiredIds: limit höchstens EXPIRE_MAX');

    // Reaktionen am Thema
    $version = (int) b1Row($db, 'community_topics', $target)['version'];
    $updated = b1Row($db, 'community_topics', $target)['updated_at'];
    $repo->bumpReactions($target, 2);
    $row = b1Row($db, 'community_topics', $target);
    T::same([2, $version + 1, $updated], [(int) $row['reaction_count'], (int) $row['version'], $row['updated_at']], 'bumpReactions: Zähler + 2, version + 1, updated_at unverändert ohne Zeit');
    $repo->bumpReactions($target, -9, rt(900));
    $row = b1Row($db, 'community_topics', $target);
    T::same([0, $version + 2, rt(900)], [(int) $row['reaction_count'], (int) $row['version'], $row['updated_at']], 'bumpReactions: nie unter 0; mit Zeit auch updated_at');
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// ModerationLogRepository
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

function b1ScenarioLogWrite(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new ModerationLogRepository($db);
    $actor = $db->user(['role' => 'moderator']);
    $entry = ['actor_id' => $actor, 'action' => 'post.hide', 'target_type' => 'post', 'target_id' => 17, 'forum_id' => 3, 'reason' => 'Spam „ä“', 'details' => ['from' => 'published', 'auto' => 'reports', 'reports' => 3], 'created_at' => rt(5)];
    $id = $repo->add($entry);
    $row = b1Row($db, 'community_moderation_log', $id);
    T::same([$actor, 'post.hide', 'post', 17, 3, 'Spam „ä“', '{"from":"published","auto":"reports","reports":3}', rt(5)], [(int) $row['actor_id'], $row['action'], $row['target_type'], (int) $row['target_id'], (int) $row['forum_id'], $row['reason'], $row['details_json'], $row['created_at']], 'add: alle Spalten, details als JSON (Unicode und Schrägstriche unmaskiert)');
    $system = b1Row($db, 'community_moderation_log', $repo->add(['actor_id' => null, 'action' => 'topic.restore', 'target_type' => 'topic', 'target_id' => 4, 'forum_id' => null, 'reason' => '', 'details' => [], 'created_at' => rt(6)]));
    T::same([null, null, null, null], [$system['actor_id'], $system['forum_id'], $system['reason'], $system['details_json']], 'add: Systemaktion ohne Akteur und Forum; leerer Grund und leere Details → NULL');
    $minimal = b1Row($db, 'community_moderation_log', $repo->add(['action' => 'user.unban', 'target_type' => 'user', 'target_id' => 9, 'created_at' => rt(7)]));
    T::same([null, null, null, null], [$minimal['actor_id'], $minimal['forum_id'], $minimal['reason'], $minimal['details_json']], 'add: nur Pflichtfelder');
    $lists = b1Row($db, 'community_moderation_log', $repo->add(['action' => 'topic.lock', 'target_type' => 'topic', 'target_id' => 5, 'details' => ['until' => null], 'created_at' => rt(8)]));
    T::same('{"until":null}', $lists['details_json'], 'add: null als Detailwert bleibt (details nicht leer)');
    $repo->add(['action' => 'report.resolve', 'target_type' => 'post', 'target_id' => 6, 'details' => ['status' => 'valid', 'reports' => [1, 2, 3], 'first_report_id' => 1, 'last_report_id' => 3], 'created_at' => rt(9)]);
    T::same(5, $db->count('community_moderation_log'), 'add: fünf Einträge');

    $rename = static fn (int $chars): array => ['action' => 'topic.rename', 'target_type' => 'topic', 'target_id' => 1, 'details' => ['from' => str_repeat('a', $chars)], 'created_at' => rt()];
    T::same(ModerationLogRepository::MAX_DETAILS_BYTES, strlen((string) b1Row($db, 'community_moderation_log', $repo->add($rename(3989)))['details_json']), 'add: details mit genau 4000 Byte werden angenommen');
    $bad = [
        'unbekannte Aktion' => ['action' => 'post.burn'],
        'Aktion kein Text' => ['action' => 5],
        'Zielart report' => ['target_type' => 'report'],
        'Zielart forum' => ['target_type' => 'forum'],
        'Detailschlüssel nicht erlaubt' => ['details' => ['ip' => '1.2.3.4']],
        'Details für Aktion ohne Details' => ['action' => 'topic.unlock', 'target_type' => 'topic', 'details' => ['from' => 'x']],
        'Details als Liste' => ['details' => ['published']],
        'verschachtelte Liste' => ['details' => ['from' => [[1]]]],
        'Objekt als Wert' => ['details' => ['from' => new \stdClass()]],
        'unendliche Zahl' => ['details' => ['from' => INF]],
        'ungültiges UTF-8 in details' => ['details' => ['from' => "\xC3\x28"]],
        'details zu groß (4001 Byte)' => $rename(3990),
        'Akteur 0' => ['actor_id' => 0],
        'Akteur als Text' => ['actor_id' => '5'],
        'Ziel 0' => ['target_id' => 0],
        'Forum 0' => ['forum_id' => 0],
        'Forum über INT UNSIGNED' => ['forum_id' => 4294967296],
        'Grund 256 Zeichen' => ['reason' => str_repeat('ä', 256)],
        'Grund ungültiges UTF-8' => ['reason' => "\xFF"],
        'Grund kein Text' => ['reason' => 5],
        'Zeit im falschen Format' => ['created_at' => '2026-11-15T10:00:00Z'],
        'Zeit fehlt' => ['created_at' => null],
        'unbekanntes Feld' => ['ip_address' => '1.2.3.4'],
    ];
    $base = ['action' => 'post.hide', 'target_type' => 'post', 'target_id' => 17, 'created_at' => rt()];
    foreach ($bad as $label => $change) {
        $db->log = [];
        $candidate = array_key_exists('created_at', $change) && $change['created_at'] === null ? array_diff_key($base, ['created_at' => 1]) : array_merge($base, $change);
        b1Throws(static fn () => $repo->add($candidate), 'add lehnt ab: ' . $label);
        T::same(0, b1Statements($db, '/^INSERT INTO \S*community_moderation_log/'), 'add schreibt nichts bei: ' . $label);
    }
    T::check(strlen(str_repeat('ä', 255)) > 255 && $repo->add(array_merge($base, ['reason' => str_repeat('ä', 255)])) > 0, 'add: Grund mit 255 Zeichen (mehr als 255 Byte) wird angenommen');

    // addMany: ein INSERT je 100 Einträge, alles geprüft vor der ersten Anweisung
    $move = static fn (int $post): array => ['actor_id' => $actor, 'action' => 'post.move', 'target_type' => 'post', 'target_id' => $post, 'forum_id' => 2, 'details' => ['from_topic' => 7, 'to_topic' => 8], 'created_at' => rt(20)];
    $before = $db->count('community_moderation_log');
    $db->log = [];
    $repo->addMany([$move(31), $move(32), $move(33)]);
    T::same(1, b1Statements($db, '/^INSERT INTO \S*community_moderation_log/'), 'addMany: drei Einträge in einer Anweisung');
    T::same([31, 32, 33], array_map(static fn (array $r): int => (int) $r['target_id'], $db->rows('community_moderation_log', ['action' => 'post.move'], 'id')), 'addMany: Einträge in Reihenfolge');
    $db->log = [];
    $repo->addMany(array_map($move, range(100, 349)));
    T::same(3, b1Statements($db, '/^INSERT INTO \S*community_moderation_log/'), 'addMany: 250 Einträge in drei Anweisungen');
    T::same($before + 253, $db->count('community_moderation_log'), 'addMany: alle Einträge geschrieben');
    $db->log = [];
    b1Throws(static fn () => $repo->addMany([$move(1), ['action' => 'post.move', 'target_type' => 'post', 'target_id' => 2, 'details' => ['ip' => 'x'], 'created_at' => rt()]]), 'addMany: ein ungültiger Eintrag → InvalidArgumentException');
    T::same(0, b1Statements($db, '/^INSERT INTO/'), 'addMany: bei einem ungültigen Eintrag keine Anweisung');
    $db->log = [];
    $repo->addMany([]);
    T::same(0, count($db->log), 'addMany: leere Liste ohne Anweisung');
}

function b1ScenarioLogRead(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new ModerationLogRepository($db);
    $m1 = $db->user(['role' => 'moderator']);
    $m2 = $db->user(['role' => 'moderator']);
    $f1 = $db->forum();
    $f2 = $db->forum();
    $add = static fn (array $o): int => $repo->add($o + ['actor_id' => $m1, 'action' => 'post.hide', 'target_type' => 'post', 'target_id' => 1, 'forum_id' => $f1, 'created_at' => rt(100)]);
    $e1 = $add(['created_at' => rt(10), 'details' => ['from' => 'published']]);
    $e2 = $add(['forum_id' => $f2, 'actor_id' => $m2]);
    $e3 = $add(['forum_id' => null, 'action' => 'user.ban', 'target_type' => 'user', 'target_id' => 9, 'details' => ['ban_id' => 4, 'scope' => 'write', 'ends_at' => null, 'duration' => 7]]);
    $e4 = $add(['action' => 'post.restore', 'details' => ['from' => 'hidden']]);
    $e5 = $add(['forum_id' => $f2, 'created_at' => rt(200), 'action' => 'topic.lock', 'target_type' => 'topic', 'target_id' => 3]);
    $e6 = $add(['actor_id' => null, 'details' => ['from' => 'published', 'auto' => 'reports', 'reports' => 3]]);

    $all = $repo->list([], null, 100);
    T::same([$e5, $e6, $e4, $e3, $e2, $e1], rIds($all->items), 'list: jüngste zuerst, gleiche Zeit nach ID absteigend');
    T::same(null, $all->next, 'list: eine Seite ohne next');
    T::same(['id' => $e3, 'actor_id' => $m1, 'action' => 'user.ban', 'target_type' => 'user', 'target_id' => 9, 'forum_id' => null, 'reason' => null, 'details' => ['ban_id' => 4, 'scope' => 'write', 'ends_at' => null, 'duration' => 7], 'created_at' => rt(100)], $all->items[3], 'list: Zeile mit Ganzzahlen und dekodierten details, ohne details_json');
    T::same(null, $all->items[0]['details'], 'list: ohne details → null');

    [$rows, $pages] = rCollect(static fn (?Cursor $c) => $repo->list([], $c, 2));
    T::same(rIds($all->items), rIds($rows), 'list: Keyset über drei Seiten mit vier gleichen Zeitstempeln: keine Zeile verloren, keine doppelt');
    T::same(3, $pages, 'list: drei Seiten zu zwei');
    [$rows] = rCollect(static fn (?Cursor $c) => $repo->list([], $c, 1));
    T::same(rIds($all->items), rIds($rows), 'list: Keyset mit Seiten zu einer Zeile');

    T::same([$e6, $e4, $e1], rIds($repo->list(['forum_ids' => [$f1]], null, 100)->items), 'list: Forenfilter, Einträge ohne Forum fehlen');
    T::same([$e5, $e6, $e4, $e2, $e1], rIds($repo->list(['forum_ids' => [$f2, $f1, $f1]], null, 100)->items), 'list: zwei Foren gemischt, jüngste zuerst');
    [$rows] = rCollect(static fn (?Cursor $c) => $repo->list(['forum_ids' => [$f1, $f2]], $c, 2));
    T::same([$e5, $e6, $e4, $e2, $e1], rIds($rows), 'list: Keyset über gemischte Foren');
    T::same([$e5, $e6, $e4, $e3, $e2, $e1], rIds($repo->list(['forum_ids' => null], null, 100)->items), 'list: forum_ids null = global, auch ohne Forum');
    $db->log = [];
    $page = $repo->list(['forum_ids' => []], null, 100);
    T::same([[], null, 0], [$page->items, $page->next, count($db->log)], 'list: leere Forenliste → leere Seite ohne Abfrage');

    $db->log = [];
    $repo->list(['forum_ids' => [$f1, $f2]], null, 10);
    T::same([2, 0], [b1Statements($db, '/ WHERE forum_id = \? /'), b1Statements($db, '/forum_id IN \(/')], 'list: bis PER_FORUM_MERGE Foren je Forum eine Abfrage');
    $many = range($f1, $f1 + ModerationLogRepository::PER_FORUM_MERGE);
    $db->log = [];
    T::same([$e5, $e6, $e4, $e2, $e1], rIds($repo->list(['forum_ids' => $many], null, 10)->items), 'list: mehr als PER_FORUM_MERGE Foren, gleiches Ergebnis');
    T::same([0, 1], [b1Statements($db, '/ WHERE forum_id = \? /'), b1Statements($db, '/forum_id IN \(/')], 'list: mehr als PER_FORUM_MERGE Foren über eine IN-Liste');

    T::same([$e2], rIds($repo->list(['actor_id' => $m2], null, 100)->items), 'list: Filter Akteur');
    T::same([$e6, $e2, $e1], rIds($repo->list(['action' => 'post.hide'], null, 100)->items), 'list: Filter Aktion');
    T::same([$e3], rIds($repo->list(['target_type' => 'user', 'target_id' => 9], null, 100)->items), 'list: Filter Ziel');
    T::same([$e5], rIds($repo->list(['target_type' => 'topic'], null, 100)->items), 'list: Filter Zielart');
    T::same([$e6, $e4, $e3, $e2], rIds($repo->list(['since' => rt(100), 'until' => rt(200)], null, 100)->items), 'list: Zeitraum since einschließlich, until ausschließlich');
    T::same([$e6, $e4], rIds($repo->list(['forum_ids' => [$f1], 'since' => rt(11)], null, 100)->items), 'list: Filter kombiniert');
    T::same([$e5], rIds($repo->list([], null, 0)->items), 'list: limit 0 → 1');
    foreach ([
        'unbekannter Filter' => ['forum' => 1],
        'unbekannte Aktion' => ['action' => 'post.burn'],
        'unbekannte Zielart' => ['target_type' => 'report'],
        'target_id ohne target_type' => ['target_id' => 9],
        'Zeit im falschen Format' => ['since' => '15.11.2026'],
        'forum_ids kein Array' => ['forum_ids' => 5],
        'actor_id als Text' => ['actor_id' => '5'],
    ] as $label => $filter) {
        b1Throws(static fn () => $repo->list($filter, null, 10), 'list lehnt ab: ' . $label);
    }

    $e7 = $add(['action' => 'post.hide', 'target_id' => 1, 'created_at' => rt(100), 'details' => ['from' => 'published']]);
    $latest = $repo->latestFor('post', 1, ['post.hide']);
    T::same([$e7, 'published'], [$latest['id'] ?? 0, $latest['details']['from'] ?? null], 'latestFor: jüngster Eintrag der Aktionen (gleiche Zeit: größere ID)');
    T::same($e7, $repo->latestFor('post', 1, ['post.hide', 'post.restore'])['id'] ?? 0, 'latestFor: mehrere Aktionen');
    $add(['action' => 'post.hide', 'target_id' => 1, 'created_at' => rt(50)]);
    T::same($e7, $repo->latestFor('post', 1, ['post.hide'])['id'] ?? 0, 'latestFor: ein älterer Eintrag mit größerer ID ändert nichts');
    T::same($e4, $repo->latestFor('post', 1, ['post.restore'])['id'] ?? 0, 'latestFor: andere Aktion');
    T::same(null, $repo->latestFor('post', 2, ['post.hide']), 'latestFor: ohne Eintrag → null');
    T::same(null, $repo->latestFor('topic', 1, ['post.hide']), 'latestFor: andere Zielart → null');
    b1Throws(static fn () => $repo->latestFor('report', 1, ['post.hide']), 'latestFor: unbekannte Zielart → InvalidArgumentException');
    b1Throws(static fn () => $repo->latestFor('post', 1, []), 'latestFor: leere Aktionsliste → InvalidArgumentException');
    b1Throws(static fn () => $repo->latestFor('post', 1, ['post.burn']), 'latestFor: unbekannte Aktion → InvalidArgumentException');
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Läufe
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

$b1Backends = ['SQLite-Double' => static fn (): Database => CommunityTestDatabase::create()];
if (MariaDbTestDatabase::configured()) {
    $b1Backends['MariaDB'] = static fn (): Database => MariaDbTestDatabase::create();
}
$b1Scenarios = [
    'Beiträge (PR #2b): Cache-Wächter storeRendered/clearRendered, applyEdit mit HTML, Text und Cache nicht über update()' => 'b1ScenarioPostCache',
    'Beiträge (PR #2b): Job-Fenster renderBatch, maxId' => 'b1ScenarioRenderBatch',
    'Beiträge (PR #2b): findMany/lockMany in Stücken ohne ip_hash und Text, ipHashes' => 'b1ScenarioPostSets',
    'Beiträge (PR #2b): withAttachments, topicHasPublishedAttachments' => 'b1ScenarioAttachments',
    'Beiträge (PR #2b): recentWithBody für die Spam-Dublette' => 'b1ScenarioRecentWithBody',
    'Beiträge (PR #2b): publishedByAuthor, setTopicForum, moveToTopic, bumpReactions' => 'b1ScenarioPostModeration',
    'Beiträge (PR #2b): Warteschlange queue/queueSummary' => 'b1ScenarioQueue',
    'Themen (PR #2b): Ankündigungen' => 'b1ScenarioAnnouncements',
    'Themen (PR #2b): countPinned, Verweise, Ablauf befristeter Zustände, bumpReactions' => 'b1ScenarioTopicModeration',
    'Moderationsprotokoll (PR #2b): Form der Einträge, addMany' => 'b1ScenarioLogWrite',
    'Moderationsprotokoll (PR #2b): list mit Keyset und Forenfilter, latestFor' => 'b1ScenarioLogRead',
];
foreach ($b1Backends as $label => $make) {
    foreach ($b1Scenarios as $title => $function) {
        T::run($title . ' [' . $label . ']', static function () use ($make, $function): void {
            $db = $make();
            try {
                $function($db);
            } finally {
                if ($db instanceof MariaDbTestDatabase) {
                    $db->dispose();
                }
            }
        });
    }
}

T::run('Inhalts-Repositories (PR #2b): Spaltenlisten, einzige Lesestelle von ip_hash, Pflichtschreibweisen', static function (): void {
    $db = CommunityTestDatabase::create();
    $schema = array_map(static fn (object $c): string => (string) $c->name, $db->get_results('PRAGMA table_info(cms_community_posts)'));
    $columns = (new \ReflectionClassConstant(PostRepository::class, 'COLUMNS'))->getValue();
    T::same($schema, $columns, 'PostRepository::COLUMNS = Spalten von community_posts in Schemareihenfolge (neue Spalten müssen eingeordnet werden)');
    T::same(['ip_hash'], PostRepository::PRIVATE_COLUMNS, 'PRIVATE_COLUMNS = [ip_hash]');
    $updatable = (new \ReflectionClassConstant(PostRepository::class, 'UPDATABLE'))->getValue();
    T::same([], array_values(array_intersect($updatable, ['body', 'body_html', 'rendered_version', 'edit_count', 'ip_hash'])), 'UPDATABLE ohne Text, Cache, edit_count und ip_hash');

    // Zeichenketten im Code (ohne Kommentare), die eine SELECT-Anweisung mit der Spalte ip_hash enthalten.
    $readers = [];
    foreach ([...glob(ABSPATH . 'core/Services/Community/*/*.php') ?: [], ...glob(ABSPATH . 'core/Services/FeatureHooks/*.php') ?: []] as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && stripos($token[1], 'SELECT') !== false && preg_match('/\bip_hash\b/', $token[1]) === 1) {
                $readers[basename($file)] = ($readers[basename($file)] ?? 0) + 1;
            }
        }
    }
    T::same(['PostRepository.php' => 1], $readers, 'ip_hash wird nur in PostRepository::ipHashes() gelesen (SELECT mit der Spalte, Vertrag DESIGN-2b K35)');
    $source = rSource('core/Services/Community/Repository/PostRepository.php');
    T::same(1, substr_count($source, "'SELECT id, ip_hash FROM '"), 'die Lesestelle ist ipHashes()');

    $topics = rSource('core/Services/Community/Repository/TopicRepository.php');
    T::check(str_contains($topics, "SELECT id, last_post_at FROM {\$table} WHERE forum_id = ? AND status = ? AND is_pinned = 0"), 'listForum bleibt zweistufig (erst id und last_post_at über den Index, dann die Zeilen; HANDOFF §2 Nr. 10)');
    $catalog = require dirname(__DIR__) . '/community-mutants/catalog-2b-repo-content.php';
    foreach ($catalog as $name => $row) {
        T::same(1, substr_count(rSource($row[0]), $row[1]), 'Pflichtschreibweise der Mutante ' . $name . ' steht genau einmal in ' . basename($row[0]));
    }
});
