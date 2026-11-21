<?php
/**
 * Abschnitte der Einheit R (PR #2b, Darstellung; Vertrag DESIGN-2b §7.3, Entwurf render §6.2) für `TESTS/community-db/run.php`:
 *
 *   render-race      fester Ablauf (Zeile lesen, bearbeiten, mit der alten Zeile anzeigen ⇒ der Wächter verhindert das Speichern)
 *                    und Wettlauf mit 8 Prozessen auf einen Beitrag: 4 Bearbeiter (`PostService::edit()` ohne Renderer, der Cache
 *                    wird NULL), 4 Nachrenderer (`RenderService::present()` mit 2 ms zwischen Lesen und Speichern). Nach jeder Runde
 *                    und bei jedem Nachlesen ist `body_html` NULL oder `render(body)`.
 *   render-lockwait  `storeRendered()` gegen eine offene Bearbeitung (Zeilensperre) mit `innodb_lock_wait_timeout = 1`: 1205 ⇒
 *                    Warnung, Anzeige mit frischem HTML, kein Überschreiben.
 *   explain-render   EXPLAIN-Gate: `renderBatch()` als Bereich über PRIMARY (höchstens das Fenster), `findMany()` und
 *                    `storeRendered()` über PRIMARY.
 *
 * Geladen von `run.php` (nutzt `cdbSection()`, `CdbWorld`, `cdbSpawn()`/`cdbFinish()`, `cdbMeasure()`, `cdbPlanProblems()`).
 * Worker-Aktionen in `actions-render.php`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\RenderService;
use CMS\Services\Community\Support\ContentRenderer;
use CMS\Services\Community\Support\Settings;

// CommonMark-Bündel und HTMLPurifier (CMS\Vendor\…) über den produktiven Assets-Autoloader.
require_once ABSPATH . 'assets/autoload.php';

/** RenderService auf einer echten Verbindung (echte CommunityAccess, Filter und Drossel neutral, kein Job). */
function cdbRenderService(MariaDbTestDatabase $db): RenderService
{
    $settings = Settings::fromArray(CDB_RACE_SETTINGS);
    $access = new CommunityAccess(
        $db,
        $settings,
        static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, CDB_ROLE_CAPS[$role] ?? [], true),
        static fn (): bool => true,
        null,
        static fn (int $userId): bool => true
    );

    return new RenderService(
        $db,
        $settings,
        null,
        static fn (?int $userId, string $action, array $context): AccessDecision => $access->can($userId, $action, $context),
        new ContentRenderer(),
        static fn (): object => throw new \LogicException('kein Job in diesem Abschnitt'),
        static fn (string $html, int $postId): string => $html,
        static fn (string $action, ?int $userId): array => ['allowed' => true, 'retry_after' => 0]
    );
}

/**
 * Prüft das Cache-Invariant eines Beitrags: `body_html` ist NULL (Version 0) oder genau `render(body)` mit VERSION.
 *
 * @return list<string> Abweichungen
 */
function cdbRenderInvariant(MariaDbTestDatabase $db, int $postId): array
{
    $row = $db->row('community_posts', ['id' => $postId]);
    if ($row === null) {
        return ['Beitrag ' . $postId . ' fehlt'];
    }
    if ($row['body_html'] === null) {
        return (int) $row['rendered_version'] === 0 ? [] : ['body_html NULL, aber rendered_version ' . $row['rendered_version']];
    }
    $expected = (new ContentRenderer())->render((string) $row['body'], ['post_id' => $postId])['html'];
    $problems = [];
    if ($row['body_html'] !== $expected) {
        $problems[] = 'body_html passt nicht zu body (edit_count ' . $row['edit_count'] . '): ' . substr((string) $row['body_html'], 0, 80) . ' statt ' . substr((string) $expected, 0, 80);
    }
    if ((int) $row['rendered_version'] !== ContentRenderer::VERSION) {
        $problems[] = 'rendered_version ' . $row['rendered_version'] . ' statt ' . ContentRenderer::VERSION;
    }

    return $problems;
}

cdbSection('render-race', 'Wettlauf Nachrendern gegen Bearbeiten: der Wächter edit_count verhindert HTML eines älteren Textes (fester Ablauf, dann 8 Prozesse)', static function (): void {
    $w = new CdbWorld(CDB_RACE_SETTINGS, time() - 7200);
    $db = $w->db;
    $author = $db->user();
    $forum = cdbOpenForum($db);
    $created = $w->topics->create($author, $forum, 'Thema für den Render-Wettlauf', 'Erste Fassung');
    T::same(true, $created['success'] ?? null, 'Vorbedingung: Thema angelegt', json_encode($created));
    $post = (int) $created['post_id'];
    $render = cdbRenderService($db);

    // Fester Ablauf: die Zeile wird vor einer Bearbeitung gelesen und danach angezeigt.
    $stale = (new PostRepository($db))->find($post);
    $w->at(60);
    T::same(true, $w->posts->edit($author, $post, 'Zweite Fassung')['success'] ?? null, 'Bearbeitung zwischen Lesen und Anzeigen');
    $out = $render->present($author, [$stale]);
    T::same("<p>Erste Fassung</p>\n", $out[0]['html'] ?? null, 'Anzeige der gelesenen Fassung');
    $row = $w->post($post);
    T::same([null, 0, 'Zweite Fassung'], [$row['body_html'], (int) $row['rendered_version'], $row['body']], 'Wächter: kein HTML der alten Fassung zur neuen');
    $render->present($author, [(new PostRepository($db))->find($post)]);
    T::same([], cdbRenderInvariant($db, $post), 'mit aktueller Zeile: gespeichert und passend');

    $stored = 0;
    for ($round = 1; $round <= 3; $round++) {
        $jobs = [];
        for ($i = 0; $i < 4; $i++) {
            $jobs[] = ['action' => 'render.edits', 'args' => ['user' => $author, 'post' => $post, 'count' => 12, 'tag' => 'r' . $round . 'e' . $i], 'settings' => CDB_RACE_SETTINGS];
            $jobs[] = ['action' => 'render.lazy', 'args' => ['post' => $post, 'viewer' => $author, 'count' => 25, 'delay' => 2000], 'settings' => CDB_RACE_SETTINGS];
        }
        $before = (int) cdbScalar($db, 'SELECT edit_count FROM cms_community_posts WHERE id = ?', [$post]);
        $results = cdbRace($db, $jobs);
        T::same([], cdbProcessProblems($results), "Runde {$round}: kein Prozess scheitert (kein db_error, keine Ausnahme)");
        $edited = array_sum(array_map(static fn (array $r): int => (int) ($r['result']['edited'] ?? 0), $results));
        $anomalies = array_merge(...array_map(static fn (array $r): array => (array) ($r['result']['anomalies'] ?? []), $results));
        T::same(48, $edited, "Runde {$round}: alle 48 Bearbeitungen erfolgreich");
        T::same($before + 48, (int) cdbScalar($db, 'SELECT edit_count FROM cms_community_posts WHERE id = ?', [$post]), "Runde {$round}: edit_count um 48 gestiegen (kein verlorenes Update)");
        T::same([], $anomalies, "Runde {$round}: kein Nachrenderer sah HTML, das nicht zum Text passt");
        T::same([], cdbRenderInvariant($db, $post), "Runde {$round}: Schlusszustand body_html NULL oder render(body)");
        // Zum Nachweis, dass der Wettlauf das Speichern trifft: eine letzte Anzeige schreibt, wenn der Cache NULL ist.
        $render->flush();
        $render->present($author, [(new PostRepository($db))->find($post)]);
        $stored += (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_posts WHERE id = ? AND body_html IS NOT NULL', [$post]);
        T::same([], cdbRenderInvariant($db, $post), "Runde {$round}: nach einer weiteren Anzeige passend gespeichert");
    }
    T::same(3, $stored, 'nach jeder Runde speichert eine Anzeige ohne Wettbewerber');
    T::same([], cdbAudit($db), 'Zähler stimmen mit der Neuberechnung überein');
    T::same('', cdbLogs(), 'keine Warnungen im Protokoll');
    $db->dispose();
});

cdbSection('render-lockwait', 'Nachrendern gegen eine offene Bearbeitung: Lock-Timeout 1205 ⇒ Warnung, Anzeige mit frischem HTML, kein Überschreiben', static function (): void {
    $w = new CdbWorld(CDB_RACE_SETTINGS, time() - 7200);
    $db = $w->db;
    $author = $db->user();
    $forum = cdbOpenForum($db);
    $created = $w->topics->create($author, $forum, 'Thema für die Sperrprobe des Renderers', 'Text vor der Bearbeitung');
    $post = (int) $created['post_id'];
    $row = (new PostRepository($db))->find($post);
    T::same([true, null], [is_array($row), $row['body_html']], 'Vorbedingung: Cache NULL (Dienst ohne Renderer)');

    $dir = sys_get_temp_dir() . '/cms-hold-render-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);
    $hold = ['locked' => $dir . '/locked', 'release' => $dir . '/release'];
    $handle = cdbSpawn($db, [['action' => 'post.edit', 'args' => ['user' => $author, 'post' => $post, 'body' => 'Text nach der Bearbeitung'], 'settings' => CDB_RACE_SETTINGS, 'hold' => $hold]]);
    touch($handle['dir'] . '/start');
    $deadline = microtime(true) + 20;
    while (!is_file($hold['locked']) && microtime(true) < $deadline) {
        usleep(2000);
    }
    T::check(is_file($hold['locked']), 'die Bearbeitung hält ihre Zeilensperren (Rechteprüfung unter den Sperren)');
    T::same('blocked', cdbProbeLock($db->connection(), 'community_posts', 'id', $post), 'Vorbedingung: die Beitragszeile ist gesperrt');

    cdbLogs();
    $db->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $render = cdbRenderService($db);
    $started = microtime(true);
    $out = $render->present($author, [$row]);
    $waited = microtime(true) - $started;
    $db->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = 50');
    T::same("<p>Text vor der Bearbeitung</p>\n", $out[0]['html'] ?? null, 'Anzeige mit frisch gerendertem HTML trotz Lock-Timeout (wirft nicht)');
    T::check($waited >= 0.9 && $waited < 1.9, 'storeRendered wartete genau einen Lock-Timeout (1 s)', round($waited, 2) . ' s');
    $log = cdbLogs();
    T::check(str_contains($log, 'storeRendered') && (str_contains($log, '1205') || str_contains($log, 'Lock wait timeout')), 'Warnung mit Lock-Timeout im Protokoll', substr($log, 0, 400));
    T::same(false, $db->getPdo()->inTransaction(), 'keine offene Transaktion nach dem Lock-Timeout');

    touch($hold['release']);
    $results = cdbFinish($handle, false, 30);
    T::same([], cdbProcessProblems($results), 'die haltende Bearbeitung endet ohne Fehler');
    T::same(true, $results[0]['result']['success'] ?? null, 'und ist erfolgreich', json_encode($results[0]['result'] ?? null));
    $after = $w->post($post);
    T::same(['Text nach der Bearbeitung', null, 0], [$after['body'], $after['body_html'], (int) $after['rendered_version']], 'nach dem Commit: neuer Text, kein HTML der alten Fassung');
    array_map('unlink', glob($dir . '/*') ?: []);
    @rmdir($dir);
    $db->dispose();
});

cdbSection('explain-render', 'EXPLAIN-Gate Darstellung: renderBatch als Bereich über PRIMARY, findMany und storeRendered über PRIMARY (30.000 Beiträge)', static function (): void {
    $db = MariaDbTestDatabase::create();
    $pdo = $db->getPdo();
    $user = $db->user();
    $forum = $db->forum();
    $topic = $db->topic(['forum' => $forum, 'user' => $user]);
    $pdo->exec('CREATE TABLE cdb_render_digits (d TINYINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_render_digits (d) VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)');
    $pdo->exec('CREATE TABLE cdb_render_numbers (n INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO cdb_render_numbers (n) SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d FROM cdb_render_digits a, cdb_render_digits b, cdb_render_digits c, cdb_render_digits e, cdb_render_digits f WHERE f.d < 3');
    // Jeder zweite Beitrag ist schon gerendert (rendered_version = VERSION): der Job liest das Fenster, nicht die Tabelle.
    $pdo->exec(
        "INSERT INTO cms_community_posts (topic_id, forum_id, user_id, is_first_post, status, body, body_html, rendered_version, created_at, updated_at)
         SELECT {$topic}, {$forum}, {$user}, 0, 'published', CONCAT('Beitrag ', n), CASE WHEN n MOD 2 = 0 THEN CONCAT('<p>Beitrag ', n, '</p>') ELSE NULL END,
                CASE WHEN n MOD 2 = 0 THEN " . ContentRenderer::VERSION . " ELSE 0 END, '2036-01-01 00:00:00', '2036-01-01 00:00:00'
         FROM cdb_render_numbers ORDER BY n"
    );
    $pdo->exec('DROP TABLE cdb_render_numbers');
    $pdo->exec('DROP TABLE cdb_render_digits');
    $pdo->query('ANALYZE TABLE cms_community_posts')->fetchAll();
    T::same(30000, (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_community_posts'), 'Datenmenge: 30.000 Beiträge');
    $posts = new PostRepository($db);
    $max = $posts->maxId();

    $cases = [
        'renderBatch am Anfang' => [static fn (): array => $posts->renderBatch(0, RenderService::STEP_WINDOW, ContentRenderer::VERSION, RenderService::STEP_LIMIT), 200],
        'renderBatch in der Mitte' => [static fn (): array => $posts->renderBatch(15000, 15000 + RenderService::STEP_WINDOW, ContentRenderer::VERSION, RenderService::STEP_LIMIT), 200],
        // Modus all ohne Treffer im Versionsfilter: höchstens das Fenster (5000 Zeilen) wird gelesen.
        'renderBatch ohne Treffer' => [static fn (): array => $posts->renderBatch(20000, 20000 + RenderService::STEP_WINDOW, 0, RenderService::STEP_LIMIT), RenderService::STEP_WINDOW + 100],
        'findMany mit 150 IDs' => [static fn (): array => $posts->findMany(range(100, 249)), 400],
    ];
    foreach ($cases as $name => [$call, $maxReads]) {
        $measure = cdbMeasure($db, $call);
        $plan = $measure['plans'][0]['rows'][0] ?? [];
        T::same([], cdbPlanProblems($measure, ['keys' => ['PRIMARY'], 'maxReads' => $maxReads]), $name . ': PRIMARY, kein Tabellenscan, kein Filesort, höchstens ' . $maxReads . ' Lesezugriffe (' . $measure['reads'] . ')');
        T::same(['range', 'PRIMARY'], [$plan['type'] ?? null, $plan['key'] ?? null], $name . ': type range, key PRIMARY');
    }
    T::same(RenderService::STEP_LIMIT, count($posts->renderBatch(0, RenderService::STEP_WINDOW, ContentRenderer::VERSION, RenderService::STEP_LIMIT)), 'renderBatch liefert höchstens STEP_LIMIT Zeilen');
    T::same(true, $max >= 30000, 'maxId() liefert die größte ID');

    // storeRendered: UPDATE über den Primärschlüssel (EXPLAIN UPDATE mit den echten Parametern).
    $stmt = $pdo->prepare('EXPLAIN UPDATE cms_community_posts SET body_html = ?, rendered_version = ? WHERE id = ? AND edit_count = ? AND rendered_version < ?');
    cdbBind($stmt, ['<p>x</p>', ContentRenderer::VERSION, 102, 0, ContentRenderer::VERSION]);
    $stmt->execute();
    $update = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    T::same(['range', 'PRIMARY'], [$update['type'] ?? null, $update['key'] ?? null], 'storeRendered: UPDATE über PRIMARY (eine Zeile)');
    T::same(1, $posts->storeRendered(102, 0, '<p>Beitrag 101</p>', ContentRenderer::VERSION), 'storeRendered schreibt die eine Zeile (ungerade n: noch ohne Cache)');
    $db->dispose();
});
