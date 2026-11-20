<?php
/**
 * Orakel der Community-Zähler und -Invarianten (PR #2b; Vertrag DESIGN-2b §3.11, Entwurf moderation §6.1): Neuberechnung aller gespeicherten
 * Zähler per COUNT und Abgleich, für beide Doubles (`CommunityTestDatabase`, `MariaDbTestDatabase`; nur `execute()` und `getPrefix()`).
 *
 * Geprüft wird (Semantik S1):
 * - Forum: `topic_count` = veröffentlichte Themen, `post_count` = veröffentlichte Beiträge in veröffentlichten Themen, `last_*` = das
 *   veröffentlichte Thema mit der jüngsten Aktivität (bei gleicher Sekunde auch der Beitrag mit der größten ID, wie `bumpCounters()` schreibt).
 * - Thema: `reply_count` = veröffentlichte Antworten (unabhängig vom Themenstatus), `last_*` = jüngster veröffentlichter Beitrag
 *   (ohne Beitrag `last_post_id` NULL), `last_post_at` nie NULL.
 * - Mitglied (jede Statistikzeile und jeder Autor oder Starter mit Zählbarem): `topic_count` = veröffentlichte eigene Themen,
 *   `post_count` = veröffentlichte eigene Beiträge in veröffentlichten Themen, `solutions_count` = veröffentlichte Themen mit eigener Lösung.
 * - Lösung: `solution_post_id`, `solved_at`, `solved_by` gemeinsam gesetzt oder gemeinsam NULL; gesetzt ⇒ veröffentlichter Nicht-Erstbeitrag
 *   desselben Themas.
 * - `posts.forum_id` = `topics.forum_id` für jeden Beitrag.
 * - Verweis-Themen (`moved`): ohne Beiträge, `reply_count` 0, `last_post_id` NULL, `last_post_at` gesetzt.
 * - Je Forum höchstens `TopicRepository::MAX_PINNED` wirksame Pins in `published|pending|hidden` (nur mit `$nowUtc`).
 * - Zusatz §3.11: `topics.has_attachments` = es gibt einen veröffentlichten Beitrag des Themas mit Anhang; `posts.reaction_count` = Zeilen
 *   in `community_reactions`; `topics.reaction_count` = Summe über die Beiträge des Themas; `reactions_received` = Summe über die Beiträge
 *   des Mitglieds; `reactions_given` = seine Reaktionszeilen.
 *
 *   require_once dirname(__DIR__) . '/lib/CommunityAudit.php';
 *   T::same([], communityAudit($db, $nowUtc), 'Zähler und Invarianten stimmen');
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

/** Höchstzahl wirksamer Pins je Forum (gleich `TopicRepository::MAX_PINNED`; die Suite vergleicht beide). */
const COMMUNITY_AUDIT_MAX_PINNED = 50;

/**
 * Abweichungen der gespeicherten Zähler und Invarianten von der Neuberechnung.
 *
 * @param object $db `CommunityTestDatabase` oder `MariaDbTestDatabase`
 * @param string|null $nowUtc Zeitpunkt für wirksame Pins (UTC `Y-m-d H:i:s`); `null` überspringt die Pin-Grenze
 * @return list<string> leer = alles stimmt
 */
function communityAudit(object $db, ?string $nowUtc = null): array
{
    $p = $db->getPrefix();
    $all = static fn (string $sql, array $params = []): array => $db->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    $one = static function (string $sql, array $params = []) use ($db): ?array {
        $row = $db->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    };
    $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
    $bad = [];

    // ── Foren ──
    foreach ($all("SELECT * FROM {$p}community_forums ORDER BY id") as $f) {
        $id = (int) $f['id'];
        $topics = (int) $one("SELECT COUNT(*) AS n FROM {$p}community_topics WHERE forum_id = ? AND status = 'published'", [$id])['n'];
        $posts = (int) $one(
            "SELECT COUNT(*) AS n FROM {$p}community_posts p JOIN {$p}community_topics t ON t.id = p.topic_id WHERE p.forum_id = ? AND p.status = 'published' AND t.status = 'published'",
            [$id]
        )['n'];
        if ((int) $f['topic_count'] !== $topics) {
            $bad[] = "Forum {$id}: topic_count {$f['topic_count']} statt {$topics}";
        }
        if ((int) $f['post_count'] !== $posts) {
            $bad[] = "Forum {$id}: post_count {$f['post_count']} statt {$posts}";
        }
        $last = $one("SELECT id, last_post_id, last_post_at, last_poster_id FROM {$p}community_topics WHERE forum_id = ? AND status = 'published' ORDER BY last_post_at DESC, id DESC LIMIT 1", [$id]);
        $byTopic = $last === null ? [null, null, null, null] : [$int($last['last_post_id']), (string) $last['last_post_at'], (int) $last['id'], $int($last['last_poster_id'])];
        $actual = [$int($f['last_post_id']), $f['last_post_at'] === null ? null : (string) $f['last_post_at'], $int($f['last_topic_id']), $int($f['last_poster_id'])];
        if ($actual !== $byTopic) {
            // Gleiche Sekunde: `bumpCounters()` setzt den Beitrag mit der größten ID, die Neubestimmung das Thema mit der größten ID.
            $newest = $one(
                "SELECT p.id, p.topic_id, p.user_id, t.last_post_at FROM {$p}community_posts p JOIN {$p}community_topics t ON t.id = p.topic_id WHERE p.forum_id = ? AND p.status = 'published' AND t.status = 'published' ORDER BY p.id DESC LIMIT 1",
                [$id]
            );
            $sameSecond = $newest !== null && $last !== null && (string) $newest['last_post_at'] === (string) $last['last_post_at'];
            if (!$sameSecond || [$actual[0], $actual[2], $actual[3]] !== [(int) $newest['id'], (int) $newest['topic_id'], $int($newest['user_id'])]) {
                $bad[] = "Forum {$id}: last_* " . json_encode($actual) . ' statt ' . json_encode($byTopic);
            }
        }
    }

    // ── Themen ──
    $pins = [];
    foreach ($all("SELECT * FROM {$p}community_topics ORDER BY id") as $t) {
        $id = (int) $t['id'];
        $replies = (int) $one("SELECT COUNT(*) AS n FROM {$p}community_posts WHERE topic_id = ? AND status = 'published' AND is_first_post = 0", [$id])['n'];
        if ((int) $t['reply_count'] !== $replies) {
            $bad[] = "Thema {$id}: reply_count {$t['reply_count']} statt {$replies}";
        }
        $last = $one("SELECT id, user_id, created_at FROM {$p}community_posts WHERE topic_id = ? AND status = 'published' ORDER BY id DESC LIMIT 1", [$id]);
        if ($last === null) {
            if ($t['last_post_id'] !== null) {
                $bad[] = "Thema {$id}: last_post_id {$t['last_post_id']} statt NULL";
            }
        } elseif ([$int($t['last_post_id']), (string) $t['last_post_at'], $int($t['last_poster_id'])] !== [(int) $last['id'], (string) $last['created_at'], $int($last['user_id'])]) {
            $bad[] = "Thema {$id}: last_* " . json_encode([$t['last_post_id'], $t['last_post_at'], $t['last_poster_id']]) . ' statt ' . json_encode([$last['id'], $last['created_at'], $last['user_id']]);
        }
        if ($t['last_post_at'] === null) {
            $bad[] = "Thema {$id}: last_post_at ist NULL";
        }

        // Lösung: drei Spalten gemeinsam; Ziel ein veröffentlichter Nicht-Erstbeitrag desselben Themas
        $set = array_map(static fn (string $c): bool => $t[$c] !== null, ['solution_post_id', 'solved_at', 'solved_by']);
        if (count(array_unique($set)) !== 1) {
            $bad[] = "Thema {$id}: solution_post_id/solved_at/solved_by nicht gemeinsam gesetzt " . json_encode([$t['solution_post_id'], $t['solved_at'], $t['solved_by']]);
        }
        if ($t['solution_post_id'] !== null) {
            $solution = $one("SELECT topic_id, is_first_post, status FROM {$p}community_posts WHERE id = ?", [(int) $t['solution_post_id']]);
            if ($solution === null || (int) $solution['topic_id'] !== $id || (int) $solution['is_first_post'] === 1 || (string) $solution['status'] !== 'published') {
                $bad[] = "Thema {$id}: Lösung {$t['solution_post_id']} ist kein veröffentlichter Nicht-Erstbeitrag des Themas";
            }
        }

        // Verweis-Themen
        if ((string) $t['status'] === 'moved') {
            $count = (int) $one("SELECT COUNT(*) AS n FROM {$p}community_posts WHERE topic_id = ?", [$id])['n'];
            if ($count !== 0 || (int) $t['reply_count'] !== 0 || $t['last_post_id'] !== null || $t['last_post_at'] === null) {
                $bad[] = "Verweis-Thema {$id}: Beiträge {$count}, reply_count {$t['reply_count']}, last_post_id " . json_encode($t['last_post_id']) . ', last_post_at ' . json_encode($t['last_post_at']);
            }
        }

        // has_attachments (Delta-Pflege, §3.11)
        $attached = $one(
            "SELECT p.id FROM {$p}community_posts p INNER JOIN {$p}community_attachments a ON a.post_id = p.id WHERE p.topic_id = ? AND p.status = 'published' LIMIT 1",
            [$id]
        ) !== null;
        if ((int) $t['has_attachments'] !== ($attached ? 1 : 0)) {
            $bad[] = "Thema {$id}: has_attachments {$t['has_attachments']} statt " . ($attached ? 1 : 0);
        }

        // Reaktionen des Themas = Summe über seine Beiträge
        $sum = (int) $one("SELECT COALESCE(SUM(reaction_count), 0) AS n FROM {$p}community_posts WHERE topic_id = ?", [$id])['n'];
        if ((int) $t['reaction_count'] !== $sum) {
            $bad[] = "Thema {$id}: reaction_count {$t['reaction_count']} statt {$sum}";
        }

        if ($nowUtc !== null && in_array((string) $t['status'], ['published', 'pending', 'hidden'], true) && (int) $t['is_pinned'] === 1
            && ($t['pinned_until'] === null || (string) $t['pinned_until'] > $nowUtc)
        ) {
            $pins[(int) $t['forum_id']] = ($pins[(int) $t['forum_id']] ?? 0) + 1;
        }
    }
    foreach ($pins as $forumId => $count) {
        if ($count > COMMUNITY_AUDIT_MAX_PINNED) {
            $bad[] = "Forum {$forumId}: {$count} wirksame Pins (höchstens " . COMMUNITY_AUDIT_MAX_PINNED . ')';
        }
    }

    // ── Beiträge ──
    foreach ($all("SELECT p.id, p.forum_id, p.reaction_count, t.forum_id AS topic_forum FROM {$p}community_posts p LEFT JOIN {$p}community_topics t ON t.id = p.topic_id ORDER BY p.id") as $row) {
        if ($row['topic_forum'] === null || (int) $row['forum_id'] !== (int) $row['topic_forum']) {
            $bad[] = "Beitrag {$row['id']}: forum_id {$row['forum_id']} statt " . json_encode($row['topic_forum']);
        }
        $reactions = (int) $one("SELECT COUNT(*) AS n FROM {$p}community_reactions WHERE post_id = ?", [(int) $row['id']])['n'];
        if ((int) $row['reaction_count'] !== $reactions) {
            $bad[] = "Beitrag {$row['id']}: reaction_count {$row['reaction_count']} statt {$reactions}";
        }
    }

    // ── Mitglieder ──
    $stats = [];
    foreach ($all("SELECT * FROM {$p}community_user_stats ORDER BY user_id") as $row) {
        $stats[(int) $row['user_id']] = $row;
    }
    $members = array_keys($stats);
    foreach ($all("SELECT DISTINCT user_id FROM {$p}community_posts WHERE user_id IS NOT NULL") as $row) {
        $members[] = (int) $row['user_id'];
    }
    foreach ($all("SELECT DISTINCT user_id FROM {$p}community_topics WHERE user_id IS NOT NULL AND status = 'published'") as $row) {
        $members[] = (int) $row['user_id'];
    }
    $members = array_values(array_unique($members));
    sort($members);
    foreach ($members as $id) {
        $topics = (int) $one("SELECT COUNT(*) AS n FROM {$p}community_topics WHERE user_id = ? AND status = 'published'", [$id])['n'];
        $posts = (int) $one(
            "SELECT COUNT(*) AS n FROM {$p}community_posts p JOIN {$p}community_topics t ON t.id = p.topic_id WHERE p.user_id = ? AND p.status = 'published' AND t.status = 'published'",
            [$id]
        )['n'];
        $solutions = (int) $one(
            "SELECT COUNT(*) AS n FROM {$p}community_topics t JOIN {$p}community_posts p ON p.id = t.solution_post_id WHERE p.user_id = ? AND t.status = 'published'",
            [$id]
        )['n'];
        $received = (int) $one("SELECT COALESCE(SUM(reaction_count), 0) AS n FROM {$p}community_posts WHERE user_id = ?", [$id])['n'];
        $given = (int) $one("SELECT COUNT(*) AS n FROM {$p}community_reactions WHERE user_id = ?", [$id])['n'];
        $row = $stats[$id] ?? null;
        $actual = $row === null ? [0, 0, 0, 0, 0] : [(int) $row['topic_count'], (int) $row['post_count'], (int) $row['solutions_count'], (int) $row['reactions_received'], (int) $row['reactions_given']];
        $expected = [$topics, $posts, $solutions, $received, $given];
        if ($actual !== $expected) {
            $bad[] = "Mitglied {$id}" . ($row === null ? ' (ohne Statistikzeile)' : '') . ': topic/post/solutions/received/given ' . json_encode($actual) . ' statt ' . json_encode($expected);
        }
    }

    return $bad;
}

/**
 * Schreibt alle Zähler aus der Neuberechnung (Fixtures, die direkt in die Tabellen schreiben, bringen damit einen stimmigen
 * Ausgangszustand mit). Gleiche Regeln wie `communityAudit()`; legt fehlende Statistikzeilen an.
 *
 * @param object $db `CommunityTestDatabase` oder `MariaDbTestDatabase`
 */
function communityRecount(object $db, string $nowUtc): void
{
    $p = $db->getPrefix();
    $db->execute(
        "UPDATE {$p}community_posts SET reaction_count = (SELECT COUNT(*) FROM {$p}community_reactions r WHERE r.post_id = {$p}community_posts.id)"
    );
    foreach ($db->execute("SELECT id, created_at FROM {$p}community_topics")->fetchAll(\PDO::FETCH_ASSOC) as $t) {
        $id = (int) $t['id'];
        $last = $db->execute("SELECT id, user_id, created_at FROM {$p}community_posts WHERE topic_id = ? AND status = 'published' ORDER BY id DESC LIMIT 1", [$id])->fetch(\PDO::FETCH_ASSOC);
        $replies = (int) $db->execute("SELECT COUNT(*) FROM {$p}community_posts WHERE topic_id = ? AND status = 'published' AND is_first_post = 0", [$id])->fetchColumn();
        $first = $db->execute("SELECT id FROM {$p}community_posts WHERE topic_id = ? AND is_first_post = 1 ORDER BY id ASC LIMIT 1", [$id])->fetchColumn();
        $reactions = (int) $db->execute("SELECT COALESCE(SUM(reaction_count), 0) FROM {$p}community_posts WHERE topic_id = ?", [$id])->fetchColumn();
        $attached = $db->execute(
            "SELECT p.id FROM {$p}community_posts p INNER JOIN {$p}community_attachments a ON a.post_id = p.id WHERE p.topic_id = ? AND p.status = 'published' LIMIT 1",
            [$id]
        )->fetchColumn() !== false;
        $db->execute(
            "UPDATE {$p}community_topics SET reply_count = ?, last_post_id = ?, last_post_at = ?, last_poster_id = ?, first_post_id = COALESCE(first_post_id, ?), reaction_count = ?, has_attachments = ? WHERE id = ?",
            [$replies, $last === false ? null : (int) $last['id'], $last === false ? (string) $t['created_at'] : (string) $last['created_at'], $last === false || $last['user_id'] === null ? null : (int) $last['user_id'], $first === false ? null : (int) $first, $reactions, $attached ? 1 : 0, $id]
        );
    }
    foreach ($db->execute("SELECT id FROM {$p}community_forums")->fetchAll(\PDO::FETCH_ASSOC) as $f) {
        $id = (int) $f['id'];
        $topics = (int) $db->execute("SELECT COUNT(*) FROM {$p}community_topics WHERE forum_id = ? AND status = 'published'", [$id])->fetchColumn();
        $posts = (int) $db->execute(
            "SELECT COUNT(*) FROM {$p}community_posts p JOIN {$p}community_topics t ON t.id = p.topic_id WHERE p.forum_id = ? AND p.status = 'published' AND t.status = 'published'",
            [$id]
        )->fetchColumn();
        $last = $db->execute("SELECT id, last_post_id, last_post_at, last_poster_id FROM {$p}community_topics WHERE forum_id = ? AND status = 'published' ORDER BY last_post_at DESC, id DESC LIMIT 1", [$id])->fetch(\PDO::FETCH_ASSOC);
        $db->execute(
            "UPDATE {$p}community_forums SET topic_count = ?, post_count = ?, last_post_id = ?, last_post_at = ?, last_topic_id = ?, last_poster_id = ? WHERE id = ?",
            [$topics, $posts, $last === false ? null : $last['last_post_id'], $last === false ? null : $last['last_post_at'], $last === false ? null : (int) $last['id'], $last === false ? null : $last['last_poster_id'], $id]
        );
    }
    $members = [];
    foreach ($db->execute("SELECT user_id FROM {$p}community_user_stats UNION SELECT user_id FROM {$p}community_posts WHERE user_id IS NOT NULL UNION SELECT user_id FROM {$p}community_topics WHERE user_id IS NOT NULL UNION SELECT user_id FROM {$p}community_reactions")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $members[] = (int) $row['user_id'];
    }
    foreach ($members as $id) {
        $db->execute("INSERT IGNORE INTO {$p}community_user_stats (user_id, updated_at) VALUES (?, ?)", [$id, $nowUtc]);
        $db->execute(
            "UPDATE {$p}community_user_stats SET
                topic_count = (SELECT COUNT(*) FROM {$p}community_topics t WHERE t.user_id = ? AND t.status = 'published'),
                post_count = (SELECT COUNT(*) FROM {$p}community_posts p JOIN {$p}community_topics t ON t.id = p.topic_id WHERE p.user_id = ? AND p.status = 'published' AND t.status = 'published'),
                solutions_count = (SELECT COUNT(*) FROM {$p}community_topics t JOIN {$p}community_posts p ON p.id = t.solution_post_id WHERE p.user_id = ? AND t.status = 'published'),
                reactions_received = (SELECT COALESCE(SUM(p.reaction_count), 0) FROM {$p}community_posts p WHERE p.user_id = ?),
                reactions_given = (SELECT COUNT(*) FROM {$p}community_reactions r WHERE r.user_id = ?)
             WHERE user_id = ?",
            [$id, $id, $id, $id, $id, $id]
        );
    }
}
