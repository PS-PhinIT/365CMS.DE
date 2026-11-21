<?php
/**
 * Abschnitte der Einheit D (PR #2b, W2) in `TESTS/community-db`: Anhänge, Suche und Mengenrechte gegen MariaDB (Vertrag DESIGN-2b §3.10,
 * §4.6 Nr. 5, §7.3; Entwurf medien §6.3). Worker-Aktion `media.upload` in `actions-media.php`.
 *
 *  race-upload-quota  6 Prozesse eines Mitglieds am Tageskontingent 3, je ein Upload: genau 3 Zeilen und 3 Dateien, die übrigen
 *                     `trust_limit`, keine Zwischendatei, kein `db_error`; drei Runden. Die Tageszählung in der Transaktion wartet je
 *                     Prozess 100 ms, damit ein Kontingent ohne Statistiksperre sicher überschritten würde (Mutante `upload-quota-unlocked`).
 *  media-bulk-api     Mengen-API `readableForumIds()` = `can(read)` je Forum für Gast, Mitglied, Gruppe, Forum-Moderator, globale Moderation
 *                     und Admin, bei 50 und 200 Foren mit Rechte-Zeilen, Unterforen, verborgenen Foren und Kategorien; Abfragezahl je
 *                     Aufruf höchstens 10 und unabhängig von der Forenzahl.
 *  explain-media      EXPLAIN-Gate (6.000 Themen, 36.000 Beiträge, 12.000 Anhänge): Anhangsabfragen über `idx_community_attachments_post`,
 *                     `_token`, `_user` bzw. PRIMARY (auch die Zuordnung als UPDATE), Suche (`verify`, `indexWindow`, `indexRows`,
 *                     `likeCandidates`, `bodies`) und Extraktor über PRIMARY, Sitemap über `idx_community_topics_activity` ohne Filesort.
 *
 * Die Zuordnungswettläufe R1 bis R4 (über `PostService`/`TopicService`) folgen in W4. Geladen von `run.php` am Ende; nutzt `cdbSection()`,
 * `cdbRace()`, `cdbProcessProblems()`, `cdbMeasure()`, `cdbPlanProblems()`, `cdbScalar()` und `cdbAt()`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Services\Community\Repository\AttachmentRepository;
use CMS\Services\Community\Service\AttachmentService;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\SearchRepository;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\CommunityMediaExtractor;
use CMS\Services\Community\Support\Settings;

/** Löscht einen Ordner samt Inhalt. */
function cdbMediaRemoveTree(string $path): void
{
    if (is_file($path) || is_link($path)) {
        @unlink($path);

        return;
    }
    foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            cdbMediaRemoveTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

/**
 * Dateien unterhalb eines Ordners (relativ).
 *
 * @return list<string>
 */
function cdbMediaFiles(string $dir): array
{
    $files = [];
    if (!is_dir($dir)) {
        return [];
    }
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[] = substr((string) $file, strlen($dir) + 1);
    }
    sort($files);

    return $files;
}

/** Zugriff mit Rechten aus Rollen (wie die Testwelt der Suite). */
function cdbMediaAccess(MariaDbTestDatabase $db, Settings $settings): CommunityAccess
{
    return new CommunityAccess(
        $db,
        $settings,
        static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, CDB_ROLE_CAPS[$role] ?? [], true),
        static fn (): bool => true,
        static fn (): int => cdbAt(),
        static fn (int $userId): bool => true
    );
}

cdbSection('race-upload-quota', 'race-upload-quota: 6 gleichzeitige Uploads am Tageskontingent 3 ergeben genau 3 Zeilen (Statistiksperre des Uploaders, R16)', static function (): void {
    // Die Worker laden den Dienst; der Haupt-Prozess lädt ihn ebenfalls, damit die Gegenprobe die Mutante hier als angewendet sieht.
    T::check(class_exists(AttachmentService::class), 'AttachmentService ladbar');
    $db = MariaDbTestDatabase::create();
    $forum = $db->forum();
    $problems = [];
    $summary = [];
    foreach ([1, 2, 3] as $round) {
        $user = $db->user();
        $topic = $db->topic(['forum' => $forum, 'user' => $user]);
        $dir = sys_get_temp_dir() . '/cms-cdb-media-' . getmypid() . '-' . $round;
        cdbMediaRemoveTree($dir);
        mkdir($dir, 0700, true);
        $jobs = [];
        for ($i = 0; $i < 6; $i++) {
            $jobs[] = ['action' => 'media.upload', 'settings' => ['attachment_types' => ['txt' => 1]], 'args' => [
                'user' => $user, 'topic' => $topic, 'token' => sprintf('%032x', $round * 100 + $i), 'per_day' => 3, 'dir' => $dir, 'delay_ms' => 100,
            ]];
        }
        $results = cdbRace($db, $jobs);
        array_push($problems, ...cdbProcessProblems($results));
        $ok = count(array_filter($results, static fn (array $r): bool => ($r['result']['success'] ?? null) === true));
        $limited = count(array_filter($results, static fn (array $r): bool => ($r['result']['error'] ?? null) === 'trust_limit'));
        $rows = (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_attachments WHERE user_id = ?', [$user]);
        $files = array_values(array_filter(cdbMediaFiles($dir), static fn (string $f): bool => !str_starts_with(basename($f), 'in-')));
        $summary[] = $ok . ' angenommen/' . $rows . ' Zeilen';
        T::same([3, 3, 3], [$rows, $ok, $limited], 'Runde ' . $round . ': genau 3 Zeilen, 3 angenommen, 3 × trust_limit');
        T::same(3, count(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.txt'))), 'Runde ' . $round . ': genau 3 endgültige Dateien');
        T::same([], array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.tmp'))), 'Runde ' . $round . ': keine Zwischendatei (Ablehnung löscht sie)');
        cdbMediaRemoveTree($dir);
    }
    T::same([], $problems, 'keine Ausnahme, kein db_error, keine offene Transaktion');
    T::info('race-upload-quota (6 Prozesse, Kontingent 3, 100 ms Pause in der Transaktion): ' . implode(', ', $summary));
    $db->dispose();
});

cdbSection('media-bulk-api', 'media-bulk-api: readableForumIds() = can(read) je Forum, höchstens 10 Abfragen unabhängig von der Forenzahl (medien §2.8)', static function (): void {
    $counts = [];
    foreach ([50, 200] as $size) {
        $db = MariaDbTestDatabase::create();
        $settings = Settings::fromArray(['guests_can_read' => true]);
        $group = $db->group('Team');
        $hiddenCategory = $db->category(['visible' => false]);
        $users = [
            'Gast' => null,
            'Mitglied' => $db->user(),
            'Gruppe' => $db->user(),
            'Forum-Moderator' => $db->user(),
            'Moderation' => $db->user(['role' => 'moderator']),
            'Admin' => $db->user(['role' => 'admin']),
        ];
        $db->join($users['Gruppe'], $group);
        $repo = new ForumRepository($db);
        $forums = [];
        for ($i = 0; $i < $size; $i++) {
            $options = ['title' => 'Forum ' . $i];
            if ($i % 10 === 3 && $forums !== []) {
                $options['parent'] = $forums[$i - 1];
            }
            if ($i % 13 === 5) {
                $options['status'] = 'hidden';
            }
            if ($i % 17 === 7) {
                $options['category'] = $hiddenCategory;
            }
            $forums[$i] = $db->forum($options);
            if ($i % 5 === 1) {
                $repo->savePermissions($forums[$i], [['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 0]], ['subject_type' => 'guest', 'subject_key' => '', 'permissions' => ['read' => 0]]], cdbUtc());
            } elseif ($i % 7 === 2) {
                $repo->savePermissions($forums[$i], [['subject_type' => 'group', 'subject_key' => (string) $group, 'permissions' => ['read' => 1]], ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 0]]], cdbUtc());
            }
        }
        $repo->saveModerators($forums[5], [['subject_type' => 'user', 'subject_id' => $users['Forum-Moderator'], 'include_children' => 1]]);
        $repo->saveModerators($forums[1], [['subject_type' => 'user', 'subject_id' => $users['Forum-Moderator'], 'include_children' => 0]]);
        foreach ($users as $label => $uid) {
            $expected = [];
            $single = cdbMediaAccess($db, $settings);
            foreach ($forums as $forumId) {
                if ($single->can($uid, 'read', ['forum_id' => $forumId, 'now' => cdbAt()])->allowed) {
                    $expected[] = $forumId;
                }
            }
            $bulk = cdbMediaAccess($db, $settings);
            $db->log = [];
            $got = $bulk->readableForumIds($uid);
            $queries = count($db->log);
            sort($got);
            T::same($expected, $got, $size . ' Foren, ' . $label . ': readableForumIds() = can(read) je Forum (' . count($expected) . ' lesbar)');
            T::check($queries <= 10, $size . ' Foren, ' . $label . ': höchstens 10 Abfragen', $queries . ' Abfragen');
            $counts[$label][$size] = $queries;
        }
        $db->dispose();
    }
    foreach ($counts as $label => $bySize) {
        T::same($bySize[50], $bySize[200], $label . ': gleiche Abfragezahl bei 50 und 200 Foren (' . $bySize[50] . ')');
    }
});

cdbSection('explain-media', 'explain-media: Anhänge, Suche, Extraktor und Sitemap nutzen ihre Indizes (6.000 Themen, 36.000 Beiträge, 12.000 Anhänge)', static function (): void {
    $db = MariaDbTestDatabase::create();
    $pdo = $db->getPdo();
    $users = [];
    for ($i = 0; $i < 30; $i++) {
        $users[] = $db->user();
    }
    $forums = [];
    for ($i = 0; $i < 30; $i++) {
        $forums[] = $db->forum();
    }
    $pdo->exec('CREATE TABLE cdb_media_digits (d TINYINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_media_digits (d) VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)');
    $pdo->exec('CREATE TABLE cdb_media_numbers (n INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_media_numbers (n) SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d FROM cdb_media_digits a, cdb_media_digits b, cdb_media_digits c, cdb_media_digits e, cdb_media_digits f');
    $f0 = $forums[0];
    $u0 = $users[0];
    $base = '2036-01-01 00:00:00';
    $pdo->exec("INSERT INTO cms_community_topics (forum_id, user_id, title, slug, status, first_post_id, last_post_at, created_at, updated_at)
        SELECT {$f0} + (n MOD 30), {$u0} + (n MOD 30), CONCAT('Thema ', n), CONCAT('thema-', n), CASE WHEN n MOD 40 = 3 THEN 'hidden' WHEN n MOD 61 = 5 THEN 'moved' ELSE 'published' END,
               n + 1, TIMESTAMP('{$base}') + INTERVAL n MINUTE, TIMESTAMP('{$base}') + INTERVAL n SECOND, TIMESTAMP('{$base}') + INTERVAL n SECOND
        FROM cdb_media_numbers WHERE n < 6000 ORDER BY n");
    $pdo->exec("INSERT INTO cms_community_posts (topic_id, forum_id, user_id, is_first_post, status, body, created_at, updated_at)
        SELECT (n MOD 6000) + 1, {$f0} + ((n MOD 6000) MOD 30), {$u0} + (n MOD 30), n < 6000,
               CASE WHEN n >= 6000 AND n MOD 29 = 4 THEN 'hidden' WHEN n >= 6000 AND n MOD 53 = 9 THEN 'deleted' WHEN n >= 6000 AND n MOD 71 = 2 THEN 'pending' ELSE 'published' END,
               CONCAT('Beitrag ', n, CASE WHEN n MOD 97 = 1 THEN ' suchwort' ELSE '' END), TIMESTAMP('{$base}') + INTERVAL n SECOND, TIMESTAMP('{$base}') + INTERVAL n SECOND
        FROM cdb_media_numbers WHERE n < 36000 ORDER BY n");
    // 10.000 zugeordnete Anhänge (jeder dritte Beitrag), 2.000 verwaiste in 200 Formularen über 30 Tage
    $pdo->exec("INSERT INTO cms_community_attachments (post_id, user_id, storage_path, original_name, mime_type, extension, file_size, sha256, has_preview, upload_token, created_at)
        SELECT n * 3 + 1, {$u0} + (n MOD 30), CONCAT('2036/01/', LPAD(HEX(n), 32, '0'), '.txt'), 'a.txt', 'text/plain', 'txt', 10, REPEAT('0', 64), 0, NULL, TIMESTAMP('{$base}') + INTERVAL n SECOND
        FROM cdb_media_numbers WHERE n < 10000 ORDER BY n");
    $pdo->exec("INSERT INTO cms_community_attachments (post_id, user_id, storage_path, original_name, mime_type, extension, file_size, sha256, has_preview, upload_token, created_at)
        SELECT NULL, {$u0} + (n MOD 30), CONCAT('2036/01/', LPAD(HEX(n + 100000), 32, '0'), '.txt'), 'b.txt', 'text/plain', 'txt', 10, REPEAT('0', 64), 0, LPAD(HEX(n MOD 200), 32, '0'), TIMESTAMP('{$base}') + INTERVAL (n * 1296) SECOND
        FROM cdb_media_numbers WHERE n < 2000 ORDER BY n");
    $pdo->query('ANALYZE TABLE cms_community_topics, cms_community_posts, cms_community_attachments')->fetchAll();
    T::same([6000, 36000, 12000], [(int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_topics'), (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_posts'), (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_attachments')], 'Datenmenge');

    $attachments = new AttachmentRepository($db);
    $search = new SearchRepository($db);
    $posts = new PostRepository($db);
    $user = $u0 + 7;
    $token = str_pad(dechex(7), 32, '0', STR_PAD_LEFT);
    $someIds = range(5001, 5100);
    $postIds = array_map(static fn (int $n): int => $n * 3 + 1, range(100, 199));
    $readable = array_slice($forums, 0, 25);
    $cases = [
        'att_orphans' => [fn () => $attachments->orphansBefore('2036-01-20 00:00:00', 200), ['keys' => ['idx_community_attachments_post'], 'maxReads' => 450]],
        'att_count_post' => [fn () => $attachments->countForPost(301), ['keys' => ['idx_community_attachments_post'], 'maxReads' => 10]],
        'att_for_posts' => [fn () => $attachments->forPosts($postIds), ['keys' => ['idx_community_attachments_post'], 'maxReads' => 400]],
        'att_pending_form' => [fn () => $attachments->pendingForForm($user, $token, 10), ['keys' => ['idx_community_attachments_token'], 'maxReads' => 40, 'filesort' => true]],
        'att_count_token' => [fn () => $attachments->countPendingToken($user, $token), ['keys' => ['idx_community_attachments_token'], 'maxReads' => 40]],
        'att_count_user' => [fn () => $attachments->countUserSince($user, '2036-01-25 00:00:00'), ['keys' => ['idx_community_attachments_user'], 'maxReads' => 200]],
        'att_count_pending' => [fn () => $attachments->countPendingSince($user, '2036-01-25 00:00:00'), ['keys' => ['idx_community_attachments_user'], 'maxReads' => 200]],
        'att_find_many' => [fn () => $attachments->findMany($someIds), ['maxReads' => 250]],
        'att_find' => [fn () => $attachments->find(5005), ['keys' => ['PRIMARY'], 'maxReads' => 5]],
        'search_verify' => [fn () => $search->verify(range(1000, 1499), $readable, []), ['keys' => ['PRIMARY'], 'maxReads' => 1800]],
        'search_verify_filters' => [fn () => $search->verify(range(1000, 1499), $readable, ['user_id' => $user, 'since' => '2036-01-01 00:10:00', 'first_only' => true]), ['keys' => ['PRIMARY'], 'maxReads' => 1800]],
        'search_bodies' => [fn () => $search->bodies(range(2000, 2049)), ['keys' => ['PRIMARY'], 'maxReads' => 150]],
        'index_rows' => [fn () => $search->indexRows(range(3000, 3199)), ['keys' => ['PRIMARY'], 'maxReads' => 600]],
        'index_window' => [fn () => $search->indexWindow(10000, 15000, 200), ['keys' => ['PRIMARY'], 'maxReads' => 600]],
        // ein Stück des LIKE-Fensters (LIKE_CHUNK = 5.000 IDs, wie der Dienst es abfragt): Bereich über PRIMARY, absteigend
        'like_candidates' => [fn () => $search->likeCandidates([SearchRepository::likePattern('suchwort')], $readable, [], $posts->maxId() - 5000, 400, $posts->maxId()), ['keys' => ['PRIMARY'], 'maxReads' => 12000]],
        'topic_post_ids' => [fn () => $search->topicPostIds(17, 0, 500), ['keys' => ['idx_community_posts_topic'], 'maxReads' => 30, 'filesort' => true]],
        'first_post_id' => [fn () => $search->firstPostId(17), ['keys' => ['PRIMARY'], 'maxReads' => 5]],
        'extractor_ids' => [fn () => (new CommunityMediaExtractor($db))->ids(20000, 1000), ['keys' => ['PRIMARY'], 'maxReads' => 1300]],
        'extractor_load' => [fn () => (new CommunityMediaExtractor($db))->load(20000), ['keys' => ['PRIMARY'], 'maxReads' => 5]],
        'sitemap_topics' => [fn () => $search->sitemapTopics($readable, 5000), ['keys' => ['idx_community_topics_activity'], 'maxReads' => 6500]],
    ];
    foreach ($cases as $label => [$call, $rule]) {
        $measure = cdbMeasure($db, $call);
        $problems = cdbPlanProblems($measure, $rule);
        T::same([], $problems, $label . ': Index ' . implode('|', $rule['keys'] ?? ['PRIMARY']) . ', kein Tabellenscan, höchstens ' . $rule['maxReads'] . ' Lesezugriffe (' . $measure['reads'] . ')');
        $first = $measure['plans'][0]['rows'][0] ?? [];
        T::info($label . ': ' . ($first['type'] ?? '-') . ':' . ($first['key'] ?? 'NULL') . ':' . ($first['Extra'] ?? '') . ', ' . $measure['reads'] . ' Lesezugriffe');
    }
    $wide = cdbMeasure($db, fn () => $search->likeCandidates([SearchRepository::likePattern('suchwort')], $readable, [], 0, 400));
    $widePlan = $wide['plans'][0]['rows'][0] ?? [];
    T::info('zum Vergleich ein LIKE über alle 36.000 IDs in einer Abfrage: ' . ($widePlan['table'] ?? '-') . ':' . ($widePlan['type'] ?? '-') . ':' . ($widePlan['key'] ?? 'NULL') . ':' . ($widePlan['Extra'] ?? '') . ', ' . $wide['reads'] . ' Lesezugriffe');
    $sitemap = cdbMeasure($db, fn () => $search->sitemapTopics($readable, 5000));
    $published = (int) cdbScalar($db, "SELECT COUNT(*) FROM cms_community_topics WHERE status = 'published' AND forum_id IN (" . implode(', ', $readable) . ')');
    T::same(min(5000, $published), count($sitemap['result']), 'sitemapTopics(): alle veröffentlichten Themen der Foren, höchstens 5000');
    $newest = cdbScalar($db, "SELECT id FROM cms_community_topics WHERE status = 'published' AND forum_id IN (" . implode(', ', $readable) . ') ORDER BY last_post_at DESC, id DESC LIMIT 1');
    T::same((int) $newest, $sitemap['result'][0]['id'] ?? null, 'sitemapTopics(): jüngste Aktivität zuerst');
    T::check(!str_contains((string) ($sitemap['plans'][0]['rows'][0]['Extra'] ?? ''), 'filesort'), 'Sitemap ohne Filesort');

    // Zuordnung (UPDATE) und Sperre über PRIMARY
    $orphanIds = array_map('intval', $pdo->query('SELECT id FROM cms_community_attachments WHERE post_id IS NULL ORDER BY id LIMIT 3')->fetchAll(\PDO::FETCH_COLUMN));
    $claim = $pdo->prepare('EXPLAIN UPDATE cms_community_attachments SET post_id = ?, upload_token = NULL WHERE id IN (?, ?, ?) AND post_id IS NULL AND user_id = ?');
    $claim->execute([99, ...$orphanIds, $user]);
    $plan = $claim->fetch(\PDO::FETCH_ASSOC);
    T::same(['range', 'PRIMARY'], [$plan['type'] ?? null, $plan['key'] ?? null], 'claim(): UPDATE über PRIMARY (range)');
    $db->getPdo()->beginTransaction();
    $db->log = [];
    $locked = $attachments->lockMany(array_slice($orphanIds, 0, 2));
    $lockSql = (string) ($db->log[0] ?? '');
    $explain = $pdo->prepare('EXPLAIN ' . str_replace(' FOR UPDATE', '', $lockSql));
    $explain->execute(array_slice($orphanIds, 0, 2));
    $lockPlan = $explain->fetch(\PDO::FETCH_ASSOC);
    $db->getPdo()->rollBack();
    T::same([2, 'PRIMARY'], [count($locked), $lockPlan['key'] ?? null], 'lockMany(): Sperre über PRIMARY');
    $db->dispose();
});
