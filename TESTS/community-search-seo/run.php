<?php
/**
 * Community-Suche, Suchindex, Sitemap und Medienreferenzen (PR #2b; Vertrag DESIGN-2b §4.8, §7.1, §7.1a; Entwurf medien §2.5 bis §2.7,
 * §6.2): `Service\CommunitySearch` (Suche, ähnliche Themen, TNT-Index mit Verbundindex, Pflege, Neuaufbau, Sitemap),
 * `Repository\SearchRepository` und `Service\CommunityMediaExtractor`.
 *
 * Abschnitte (Besitzer): diese Datei D (W2): A Tor, B Rechte-Matrix (Index und LIKE), C Grenzen, D Ergebnis, E LIKE, F Pflege (direkt
 * über `queuePosts`/`queueTopic`/`flushQueue`/`reconcile`/Neuaufbau), G ähnliche Themen, H Sitemap, I Medienextraktor, J Uhr, K Dateinamen
 * und Status. Die Listener-Fälle (`SEARCH_TOPIC_EVENTS`, Anmeldung über `CommunityHooks`) schreibt H in W3 (`part-hooks.php`, K42).
 * Prüfkürzel SECURITY-PRIVACY D1 (Suche), T3 (Thementitel, Suchauszug: Klartext, Escaping in PR #4).
 *
 * DB-frei (SQLite-Double), echte `CommunityAccess` (Rechte aus Rollen), TNT-Index im Temp-Ordner, Zone Europe/Berlin.
 * Die Mengen-API und das EXPLAIN-Gate gegen MariaDB stehen in `TESTS/community-db/sections-media.php`.
 *
 *   php TESTS/community-search-seo/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-search-seo-' . getmypid() . '/');
define('CMS_PRIVATE_PATH', sys_get_temp_dir() . '/cms-tests-private-community-search-seo-' . getmypid());
define('SITE_URL', 'https://forum.example');
// Geheimnisse wie in config/app.php: NONCE_KEY ist der Platzhalter des Installers, SECURE_AUTH_KEY brauchbar (Standard wie IpHash).
define('NONCE_KEY', 'REPLACE_VIA_INSTALLER_run_install_php_to_generate_unique_key_nonce');
define('SECURE_AUTH_KEY', 'search-suite-secure-auth-key-0123456789');
define('AUTH_KEY', 'YOUR_AUTH_KEY_0123456789abcdef');

date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

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
require_once ABSPATH . 'assets/autoload.php';

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/lib/CommunityTestDatabase.php';

use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\SearchRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\CommunityMediaExtractor;
use CMS\Services\Community\Service\CommunitySearch;
use CMS\Services\Community\Service\QuoteService;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Settings;
use CMS\Services\SearchService;
use TeamTNT\TNTSearch\TNTSearch;

T::$verbose = in_array('-v', $argv ?? [], true);

const CS_INDEX_ROOT = CMS_PRIVATE_PATH . '-index';
const CS_SECRET = 'search-suite-secret-0123456789abcdef';
const CS_ROLE_CAPS = [
    'member' => ['community.view', 'community.participate'],
    'moderator' => ['community.view', 'community.participate', 'community.moderate'],
];

register_shutdown_function(static function (): void {
    csRemoveTree(CS_INDEX_ROOT);
    csRemoveTree(CMS_PRIVATE_PATH);
    foreach (glob(LOG_PATH . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir(LOG_PATH);
});

I18n::reset('de', 'de');
Settings::setInstance(Settings::fromArray(['base_path' => 'forum']));

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Hilfen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Uhr der Suite: 2026-11-20 12:00:00 UTC (Berlin +01:00). */
function csNow(int $offset = 0): int
{
    return gmmktime(12, 0, 0, 11, 20, 2026) + $offset;
}

function csUtc(int $offset = 0): string
{
    return gmdate('Y-m-d H:i:s', csNow($offset));
}

function csRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            csRemoveTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

function csLog(): string
{
    return implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), glob(LOG_PATH . 'community-*.log') ?: []));
}

function csLogReset(): void
{
    foreach (glob(LOG_PATH . 'community-*.log') ?: [] as $file) {
        @unlink($file);
    }
}

/** Kennzeichen der Indexdateien zu einem Geheimnis (Vertrag §4.8 Nr. 5). */
function csTag(string $secret): string
{
    return substr(hash_hmac('sha256', 'community-index', hash_hkdf('sha256', $secret, 32, '365cms:community:index:v1')), 0, 16);
}

/** Fake von `BatchJobService` (Methoden, die `CommunitySearch` braucht). */
final class CsJobs
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];
    /** @var array<int, array<string, mixed>> */
    public array $jobs = [];
    private int $seq = 0;

    public function start(string $type, string $scope, int $total, array $state = [], ?int $userId = null): array
    {
        $this->calls[] = ['start', $type, $scope, $total, $state, $userId];
        $active = $this->findActive($type, $scope);
        if ($active !== null) {
            return ['job' => $active, 'resumed' => true];
        }
        $id = ++$this->seq;
        $this->jobs[$id] = ['id' => $id, 'type' => $type, 'scope' => $scope, 'status' => 'pending', 'total' => $total, 'processed' => 0, 'failed' => 0, 'state' => $state, 'created_by' => $userId, 'message' => ''];

        return ['job' => $this->jobs[$id], 'resumed' => false];
    }

    public function step(int $jobId, callable $worker): array
    {
        $this->calls[] = ['step', $jobId];
        $job = $this->jobs[$jobId] ?? throw new \RuntimeException('Job fehlt');
        if (!in_array($job['status'], ['pending', 'running'], true)) {
            return $job;
        }
        try {
            $result = $worker($job + ['status' => 'running']);
        } catch (\Throwable $e) {
            $job['status'] = 'failed';
            $job['message'] = $e->getMessage();

            return $this->jobs[$jobId] = $job;
        }
        $job['processed'] += (int) ($result['processed'] ?? 0);
        $job['failed'] += (int) ($result['failed'] ?? 0);
        $job['state'] = $result['state'] ?? $job['state'];
        $job['status'] = !empty($result['done']) ? 'done' : 'running';

        return $this->jobs[$jobId] = $job;
    }

    public function cancel(int $jobId): bool
    {
        if (!isset($this->jobs[$jobId]) || !in_array($this->jobs[$jobId]['status'], ['pending', 'running'], true)) {
            return false;
        }
        $this->jobs[$jobId]['status'] = 'cancelled';

        return true;
    }

    public function find(int $jobId): ?array
    {
        return $this->jobs[$jobId] ?? null;
    }

    public function findActive(string $type, string $scope = ''): ?array
    {
        foreach (array_reverse($this->jobs, true) as $job) {
            if ($job['type'] === $type && $job['scope'] === $scope && in_array($job['status'], ['pending', 'running'], true)) {
                return $job;
            }
        }

        return null;
    }

    public function latest(string $type, string $scope = ''): ?array
    {
        foreach (array_reverse($this->jobs, true) as $job) {
            if ($job['type'] === $type && $job['scope'] === $scope) {
                return $job;
            }
        }

        return null;
    }

    public function toPublic(array $job): array
    {
        return ['id' => (int) $job['id'], 'type' => (string) $job['type'], 'status' => (string) $job['status'], 'total' => (int) $job['total'], 'processed' => (int) $job['processed']];
    }
}

/**
 * Testwelt: SQLite-Double, echte `CommunityAccess`, Uhr mit Zähler, Fake-Drossel und -Jobs, Index-Ordner im Temp-Ordner.
 */
final class CsWorld
{
    public CommunityTestDatabase $db;
    public Settings $settings;
    public \ArrayObject $time;
    public int $clockReads = 0;
    public CommunityAccess $access;
    public bool $module = true;
    public bool $throttleAllowed = true;
    /** @var list<array{action: string, user: ?int}> */
    public array $throttleLog = [];
    public ?string $dir;
    public mixed $secret = CS_SECRET;
    public CsJobs $jobs;
    /** @var (\Closure(string): string)|null */
    public ?\Closure $plainText = null;
    public CommunitySearch $search;
    public int $forum;
    public int $member;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings = [], bool $withIndex = true)
    {
        $this->dir = null;
        if ($withIndex) {
            $this->dir = CS_INDEX_ROOT . '/' . bin2hex(random_bytes(4));
            mkdir($this->dir, 0700, true);
        }
        $this->db = CommunityTestDatabase::create();
        $this->settings = Settings::fromArray($settings + ['base_path' => 'forum', 'guests_can_read' => true]);
        Settings::setInstance($this->settings);
        $this->time = new \ArrayObject(['now' => csNow()]);
        $this->jobs = new CsJobs();
        $this->rebuild();
        $this->forum = $this->db->forum(['title' => 'Allgemein', 'slug' => 'allgemein']);
        $this->member = $this->db->user(['username' => 'mia']);
    }

    public function rebuild(): void
    {
        $this->access = new CommunityAccess(
            $this->db,
            $this->settings,
            static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, CS_ROLE_CAPS[$role] ?? [], true),
            fn (): bool => $this->module,
            fn (): int => (int) $this->time['now'],
            static fn (int $userId): bool => true
        );
        $this->search = new CommunitySearch(
            $this->db,
            $this->settings,
            function (): int {
                $this->clockReads++;

                return (int) $this->time['now'];
            },
            fn (?int $userId, string $action, array $context): AccessDecision => $this->access->can($userId, $action, $context),
            fn (?int $userId): array => $this->access->readableForumIds($userId),
            function (string $action, ?int $userId): array {
                $this->throttleLog[] = ['action' => $action, 'user' => $userId];

                return ['allowed' => $this->throttleAllowed, 'retry_after' => $this->throttleAllowed ? 0 : 42];
            },
            fn (): ?string => $this->dir,
            $this->plainText,
            fn (): object => $this->jobs,
            'de',
            fn (): mixed => $this->secret
        );
    }

    /** Thema mit erstem Beitrag; liefert [Thema, Beitrag]. */
    public function thread(int $forum, string $title, string $body, array $o = []): array
    {
        $topic = $this->db->topic(['forum' => $forum, 'user' => $o['user'] ?? $this->member, 'title' => $title, 'status' => $o['topic_status'] ?? 'published', 'created_at' => $o['created_at'] ?? csUtc(-3600), 'last_post_at' => $o['last_post_at'] ?? ($o['created_at'] ?? csUtc(-3600))]);
        $post = $this->db->post(['topic' => $topic, 'user' => $o['user'] ?? $this->member, 'first' => true, 'body' => $body, 'status' => $o['post_status'] ?? 'published', 'created_at' => $o['created_at'] ?? csUtc(-3600)]);
        $this->db->execute('UPDATE cms_community_topics SET first_post_id = ? WHERE id = ?', [$post, $topic]);

        return [$topic, $post];
    }

    public function reply(int $topic, string $body, array $o = []): int
    {
        return $this->db->post(['topic' => $topic, 'user' => $o['user'] ?? $this->member, 'body' => $body, 'status' => $o['status'] ?? 'published', 'created_at' => $o['created_at'] ?? csUtc(-1800)]);
    }

    /** Neuaufbau über `rebuildStep()` bis zum Ende. */
    public function build(): void
    {
        $state = ['after_id' => 0, 'max_id' => (int) $this->db->execute('SELECT COALESCE(MAX(id), 0) FROM cms_community_posts')->fetchColumn(), 'created' => false];
        for ($i = 0; $i < 1000; $i++) {
            $step = $this->search->rebuildStep($state);
            $state = $step['state'];
            if ($step['done']) {
                return;
            }
        }
        throw new \RuntimeException('Neuaufbau endet nicht');
    }

    public function liveFile(?string $secret = null): string
    {
        return $this->dir . '/' . sprintf(CommunitySearch::INDEX_FILE, csTag($secret ?? (string) $this->secret));
    }

    public function buildFile(): string
    {
        return $this->dir . '/' . sprintf(CommunitySearch::BUILD_FILE, csTag((string) $this->secret));
    }

    public function journalFile(): string
    {
        return $this->dir . '/' . sprintf(CommunitySearch::JOURNAL_FILE, csTag((string) $this->secret));
    }

    /**
     * Themen-IDs eines Suchergebnisses.
     *
     * @return list<int>
     */
    public function topics(?int $user, string $query, array $filters = [], int $page = 1, ?int $perPage = null): array
    {
        $r = $this->search->search($user, $query, $filters, $page, $perPage);

        return array_map(static fn (array $hit): int => $hit['topic_id'], (array) ($r['results'] ?? []));
    }

    /**
     * Dokument-IDs im Live-Index für ein Wort (direkt über TNT).
     *
     * @return list<int>
     */
    public function indexed(string $word, ?string $file = null): array
    {
        $file ??= $this->liveFile();
        if (!is_file($file)) {
            return [];
        }
        $tnt = new TNTSearch();
        $tnt->loadConfig(['driver' => 'filesystem', 'storage' => dirname($file), 'stemmer' => SearchService::stemmerForLocale('de'), 'wal' => false]);
        $tnt->selectIndex(basename($file));
        $tnt->engine->maxDocs = 1000000;
        $ids = array_map('intval', array_keys((array) ($tnt->search($word, 1000000)['docScores'] ?? [])));
        sort($ids);

        return $ids;
    }
}

/**
 * TNT-Datei direkt anlegen (ohne Datenbank): Dokumente `id => text` (Titel leer), mit Verbundindex.
 *
 * @param array<int, string> $documents
 */
function csWriteIndex(string $file, array $documents): void
{
    @unlink($file);
    $tnt = new TNTSearch();
    $tnt->loadConfig(['driver' => 'filesystem', 'storage' => dirname($file), 'stemmer' => SearchService::stemmerForLocale('de'), 'wal' => false]);
    $tnt->createIndex(basename($file), true);
    $engine = $tnt->engine;
    $pdo = $engine->index;
    $pdo->exec(CommunitySearch::HITS_INDEX_SQL);
    $pdo->exec("INSERT INTO info (key, value) VALUES ('cms_locale', 'de'), ('cms_last_id', '0')");
    $pdo->beginTransaction();
    foreach ($documents as $id => $text) {
        $engine->insert(['id' => $id, 'title' => '', 'content' => $text]);
    }
    $pdo->commit();
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// A Tor
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('A Tor: Modul, Gast-Tor, Sperre, Begriffe, Drossel, Filter (D1)', static function (): void {
    $w = new CsWorld();
    $w->thread($w->forum, 'Hallo', 'Ein Wort zum Finden');
    $w->build();
    $w->db->log = [];
    foreach (['a', '   ', '', '"x"', '<b></b>'] as $query) {
        T::same('query_too_short', $w->search->search($w->member, $query)['error'] ?? null, 'Suchbegriff ' . json_encode($query) . ' ⇒ query_too_short');
    }
    foreach ([
        'unbekannter Filter' => [['tag' => 'x'], 1, null],
        'forum_id 0' => [['forum_id' => 0], 1, null],
        'forum_id als Text' => [['forum_id' => '1'], 1, null],
        'since ≥ until' => [['since' => 100, 'until' => 100], 1, null],
        'since negativ' => [['since' => -1], 1, null],
        'first_only kein bool' => [['first_only' => 1], 1, null],
        'sort unbekannt' => [['sort' => 'random'], 1, null],
        'Seite 0' => [[], 0, null],
        'Seite über MAX_TOPICS' => [[], 6, 20],
        'perPage 51' => [[], 1, 51],
        'perPage 0' => [[], 1, 0],
    ] as $label => [$filters, $page, $perPage]) {
        T::same('invalid_input', $w->search->search($w->member, 'Wort', $filters, $page, $perPage)['error'] ?? null, $label . ' ⇒ invalid_input');
    }
    T::same([], $w->db->log, 'Eingabefehler ohne Datenbankanweisung');
    T::same([], $w->throttleLog, 'Eingabefehler ohne Drossel');

    $w->throttleAllowed = false;
    T::same(['rate_limited', 42], [$w->search->search($w->member, 'Wort')['error'] ?? null, $w->search->search($w->member, 'Wort')['retry_after'] ?? null], 'Drossel ⇒ rate_limited mit retry_after');
    $w->throttleAllowed = true;
    $w->throttleLog = [];
    $w->search->search(null, 'Wort');
    $w->search->search($w->member, 'Wort');
    T::same([['action' => 'search', 'user' => null], ['action' => 'search', 'user' => $w->member]], $w->throttleLog, 'Drossel search je Gast und Mitglied (der Gesamteimer der Gäste liegt in ContentGuard)');

    $banned = $w->db->user(['username' => 'bf']);
    $w->db->ban(['user' => $banned, 'scope' => 'full']);
    $w->rebuild();
    T::same('banned_full', $w->search->search($banned, 'Wort')['error'] ?? null, 'Vollsperre ⇒ banned_full');
    $writeBan = $w->db->user(['username' => 'bw']);
    $w->db->ban(['user' => $writeBan, 'scope' => 'write']);
    T::same(true, $w->search->search($writeBan, 'Wort')['success'] ?? null, 'Schreibsperre darf suchen');

    $closed = new CsWorld(['guests_can_read' => false]);
    $closed->thread($closed->forum, 'Hallo', 'Ein Wort');
    $closed->build();
    $guest = $closed->search->search(null, 'Wort');
    $expected = $closed->access->can(null, 'search', ['scope' => 'global', 'now' => csNow()]);
    T::same([false, $expected->reason], [$guest['success'] ?? null, $guest['error'] ?? null], 'Gast ohne Tor ⇒ Grund aus can() (' . $expected->reason . ')');
    $w->module = false;
    $w->rebuild();
    T::same('module_disabled', $w->search->search($w->member, 'Wort')['error'] ?? null, 'Modul aus ⇒ module_disabled');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// B Rechte-Matrix
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('B Rechte-Matrix: Treffer genau veröffentlicht/veröffentlicht in lesbaren Foren, Index und LIKE (D1, E23)', static function (): void {
    foreach ([true, false] as $withIndex) {
        $w = new CsWorld([], $withIndex);
        $label = $withIndex ? 'Index' : 'LIKE';
        $db = $w->db;
        $group = $db->group('Team');
        $visibleCategory = $db->category(['visible' => true]);
        $hiddenCategory = $db->category(['visible' => false]);
        $forums = [
            'offen' => $w->forum,
            'intern' => $db->forum(['title' => 'Intern']),
            'hidden' => $db->forum(['title' => 'Versteckt', 'status' => 'hidden']),
            'archived' => $db->forum(['title' => 'Archiv', 'status' => 'archived']),
            'kategorie' => $db->forum(['title' => 'Kat sichtbar', 'category' => $visibleCategory]),
            'kategorie-unsichtbar' => $db->forum(['title' => 'Kat unsichtbar', 'category' => $hiddenCategory]),
            'kategorie-fehlt' => $db->forum(['title' => 'Kat fehlt', 'category' => 9999]),
        ];
        $forums['unter-hidden'] = $db->forum(['title' => 'Unter versteckt', 'parent' => $forums['hidden']]);
        (new ForumRepository($db))->savePermissions($forums['intern'], [
            ['subject_type' => 'group', 'subject_key' => (string) $group, 'permissions' => ['read' => 1, 'reply' => 1]],
            ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 0]],
            ['subject_type' => 'guest', 'subject_key' => '', 'permissions' => ['read' => 0]],
        ], csUtc());
        $users = [
            'G' => null,
            'M0' => $w->member,
            'GRP' => $db->user(['username' => 'grp']),
            'FM' => $db->user(['username' => 'fm']),
            'GM' => $db->user(['username' => 'gm', 'role' => 'moderator']),
            'AD' => $db->user(['username' => 'ad', 'role' => 'admin']),
            'BW' => $db->user(['username' => 'bw']),
        ];
        $db->join($users['GRP'], $group);
        (new ForumRepository($db))->saveModerators($forums['hidden'], [['subject_type' => 'user', 'subject_id' => $users['FM'], 'include_children' => 1]]);
        $db->ban(['user' => $users['BW'], 'scope' => 'write']);
        $expectedRows = [];
        foreach ($forums as $forumName => $forumId) {
            foreach (['published', 'pending', 'hidden', 'deleted', 'trash', 'moved'] as $topicStatus) {
                foreach (['published', 'pending', 'hidden', 'deleted', 'rejected'] as $postStatus) {
                    [$topic] = $w->thread($forumId, 'Matrix ' . $forumName . ' ' . $topicStatus . ' ' . $postStatus, 'zebrafink ' . $forumName, ['topic_status' => $topicStatus, 'post_status' => $postStatus]);
                    $expectedRows[] = [$topic, $forumId, $topicStatus === 'published' && $postStatus === 'published'];
                }
            }
        }
        $db->forum(['title' => 'Verweis', 'type' => 'link']);
        if ($withIndex) {
            // Index mit allen Beiträgen (auch nicht veröffentlichte), damit die SQL-Prüfung allein entscheidet
            $all = [];
            foreach ($db->rows('community_posts') as $row) {
                $all[(int) $row['id']] = (string) $row['body'];
            }
            csWriteIndex($w->liveFile(), $all);
        }
        foreach ($users as $class => $uid) {
            $w->rebuild();
            $readable = array_flip($w->access->readableForumIds($uid));
            $expected = [];
            foreach ($expectedRows as [$topic, $forumId, $visible]) {
                if ($visible && isset($readable[$forumId])) {
                    $expected[] = $topic;
                }
            }
            sort($expected);
            $r = $w->search->search($uid, 'zebrafink', [], 1, 50);
            if ($uid === null && !$withIndex) {
                T::same([true, 'unavailable', []], [$r['success'] ?? null, $r['source'] ?? null, $r['results'] ?? null], $label . ' ' . $class . ': Gast ohne Index ⇒ unavailable');
                continue;
            }
            $got = array_map(static fn (array $hit): int => $hit['topic_id'], (array) ($r['results'] ?? []));
            sort($got);
            T::same([$withIndex ? 'index' : 'like', $expected], [$r['source'] ?? null, $got], $label . ' ' . $class . ': genau die veröffentlichten Beiträge in veröffentlichten Themen der lesbaren Foren (' . count($expected) . ')');
            T::check($expected !== [] || $class === 'G', $label . ' ' . $class . ': die Matrix hat Treffer');
        }
    }
});

T::run('B Rechte: veralteter Indexeintrag, verschobenes Thema mit veraltetem p.forum_id, gelöschtes Thema', static function (): void {
    $w = new CsWorld();
    $intern = $w->db->forum(['title' => 'Intern']);
    (new ForumRepository($w->db))->savePermissions($intern, [['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 0]], ['subject_type' => 'guest', 'subject_key' => '', 'permissions' => ['read' => 0]]], csUtc());
    [$t1, $p1] = $w->thread($w->forum, 'Eins', 'kolibri eins');
    [$t2, $p2] = $w->thread($w->forum, 'Zwei', 'kolibri zwei');
    [$t3, $p3] = $w->thread($w->forum, 'Drei', 'kolibri drei');
    [$t4, $p4] = $w->thread($intern, 'Vier', 'kolibri vier');
    $w->reply($t3, 'kolibri antwort');
    $w->build();
    $sorted = static function (array $ids): array {
        sort($ids);

        return $ids;
    };
    T::same([$t1, $t2, $t3], $sorted($w->topics($w->member, 'kolibri')), 'Ausgang: drei Themen im offenen Forum');
    $w->db->execute("UPDATE cms_community_posts SET status = 'hidden' WHERE id = ?", [$p1]);
    T::same([$t2, $t3], $sorted($w->topics($w->member, 'kolibri')), 'ausgeblendet ohne Indexpflege: Treffer fehlt (Prüfung per SQL)');
    // Thema 2 ins interne Forum verschoben, p.forum_id bleibt veraltet
    $w->db->execute('UPDATE cms_community_topics SET forum_id = ? WHERE id = ?', [$intern, $t2]);
    T::same([$t3], $w->topics($w->member, 'kolibri'), 'verschobenes Thema erscheint mit den Rechten des neuen Forums (t.forum_id)');
    // Thema 4 aus dem internen Forum ins offene verschoben, p.forum_id bleibt intern
    $w->db->execute('UPDATE cms_community_topics SET forum_id = ? WHERE id = ?', [$w->forum, $t4]);
    T::same([$t3, $t4], $sorted($w->topics($w->member, 'kolibri')), 'aus dem internen Forum verschoben ⇒ sichtbar');
    $w->db->execute("UPDATE cms_community_topics SET status = 'deleted' WHERE id = ?", [$t3]);
    T::same([$t4], $w->topics($w->member, 'kolibri'), 'gelöschtes Thema mit veröffentlichten Beiträgen fehlt');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// C Grenzen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('C Grenzen: Rang über 500 gefunden, MAX_TOPICS, truncated, Seiten (D1)', static function (): void {
    $w = new CsWorld();
    [$topic, $post] = $w->thread($w->forum, 'Einziges', 'papagei');
    $documents = [$post => 'papagei'];
    for ($i = 1; $i <= 599; $i++) {
        $documents[100000 + $i] = 'papagei papagei papagei';
    }
    csWriteIndex($w->liveFile(), $documents);
    T::same([$topic], $w->topics($w->member, 'papagei'), '600 passende Dokumente, das einzige lesbare auf Rang 600 ⇒ gefunden');

    $w2 = new CsWorld();
    $topics = [];
    for ($i = 0; $i < 101; $i++) {
        $topics[] = $w2->thread($w2->forum, 'Thema ' . $i, 'pinguin nummer ' . $i, ['created_at' => csUtc(-10000 + $i)])[0];
    }
    $w2->build();
    $r = $w2->search->search($w2->member, 'pinguin');
    T::same([100, true, 5, 1, true, 20], [$r['total'] ?? null, $r['truncated'] ?? null, $r['pages'] ?? null, $r['page'] ?? null, $r['has_more'] ?? null, count($r['results'] ?? [])], '101 Themen ⇒ total 100, truncated, 5 Seiten zu 20');
    $last = $w2->search->search($w2->member, 'pinguin', [], 5);
    T::same([20, false], [count($last['results'] ?? []), $last['has_more'] ?? null], 'Seite 5: 20 Treffer, keine weitere Seite');
    $ids = [];
    for ($page = 1; $page <= 5; $page++) {
        foreach ($w2->search->search($w2->member, 'pinguin', [], $page)['results'] as $hit) {
            $ids[$hit['topic_id']] = true;
        }
    }
    T::same(100, count($ids), 'Seiten ohne Doppelte');
    $w2->db->execute("UPDATE cms_community_topics SET status = 'hidden' WHERE id = ?", [$topics[0]]);
    $r = $w2->search->search($w2->member, 'pinguin');
    T::same([100, false], [$r['total'] ?? null, $r['truncated'] ?? null], 'genau 100 lesbare Themen ⇒ truncated = false');
    T::same('invalid_input', $w2->search->search($w2->member, 'pinguin', [], 3, 50)['error'] ?? null, '50 je Seite: höchstens Seite 2');
});

T::run('C Grenzen (Fassung 3): 20.000 Dokumente je Wort, truncated ohne Leck über unsichtbare Kandidaten (Vertrag §4.8 Nr. 2, 3)', static function (): void {
    $w = new CsWorld();
    [$topic, $post] = $w->thread($w->forum, 'Selten', 'alpha beta');
    $documents = [$post => 'alpha beta'];
    for ($i = 1; $i <= 20000; $i++) {
        $documents[200000 + $i] = 'alpha alpha gamma';
    }
    csWriteIndex($w->liveFile(), $documents);
    T::same([], $w->topics($w->member, 'alpha beta'), '20.001 Dokumente mit alpha: das lesbare mit der kleinsten Trefferzahl fällt unter die Grenze je Wort ⇒ nicht gefunden');
    T::same([$topic], $w->topics($w->member, 'beta'), 'über beta allein gefunden');
    T::same(20000, CommunitySearch::MAX_DOCS_PER_WORD, 'Grenze 20.000');

    $w2 = new CsWorld();
    [$visibleTopic] = $w2->thread($w2->forum, 'Sichtbar', 'nashorn');
    $documents = [];
    for ($i = 1; $i <= 5100; $i++) {
        $documents[300000 + $i] = 'nashorn nashorn';   // unsichtbar: keine Zeile in der Datenbank
    }
    csWriteIndex($w2->liveFile(), $documents);
    $r = $w2->search->search($w2->member, 'nashorn');
    T::same([0, false, 'index'], [$r['total'] ?? null, $r['truncated'] ?? null, $r['source'] ?? null], 'mehr als 5.000 unsichtbare Kandidaten bei 0 Treffern ⇒ truncated = false');
    T::check($visibleTopic > 0, 'sichtbares Thema ist nicht im Index');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// D Ergebnis
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('D Ergebnis: ein Treffer je Thema, Titelbonus, Sortierung, Form ohne body und ip_hash', static function (): void {
    $w = new CsWorld();
    [$a, $a1] = $w->thread($w->forum, 'Marmelade kochen', 'Rezept für Erdbeeren', ['created_at' => csUtc(-9000)]);
    $a2 = $w->reply($a, 'Etwas Marmelade dazu', ['created_at' => csUtc(-8000)]);
    [$b, $b1] = $w->thread($w->forum, 'Brot backen', 'Marmelade, Marmelade und noch mehr Marmelade', ['created_at' => csUtc(-1000)]);
    $w->db->execute("UPDATE cms_community_posts SET ip_hash = ? WHERE id = ?", [str_repeat('f', 64), $b1]);
    $w->build();
    $r = $w->search->search($w->member, 'Marmelade');
    T::same([$a, $b], array_column($r['results'], 'topic_id'), 'Relevanz: Titeltreffer im ersten Beitrag vor dreifachem Treffer im Text (Titelbonus), ein Treffer je Thema');
    T::same(2, $r['total'], 'total = Themen');
    $hit = $r['results'][0];
    T::same(['topic_id', 'post_id', 'forum_id', 'title', 'slug', 'excerpt', 'score', 'created_at', 'is_first_post', 'solved'], array_keys($hit), 'Form eines Treffers');
    T::check(!str_contains(json_encode($r), 'ip_hash') && !str_contains(json_encode($r), str_repeat('f', 64)) && !array_key_exists('body', $hit), 'kein body und kein ip_hash im Ergebnis');
    T::same([$a1, true], [$hit['post_id'], $hit['is_first_post']], 'bester Beitrag des Themas: der erste Beitrag mit Titeltreffer');
    T::check($hit['score'] > $r['results'][1]['score'] && $hit['score'] >= CommunitySearch::TITLE_BONUS, 'Punktzahl enthält den Titelbonus');
    $date = $w->search->search($w->member, 'Marmelade', ['sort' => 'date']);
    T::same([$b, $a], array_column($date['results'], 'topic_id'), 'sort = date: jüngster zuerst');
    T::same(['marmelade'], $r['terms'], 'Begriffe gefaltet zurück');
    T::same('index', $r['source'], 'Quelle index');
    T::check($a2 > 0, 'Antwort angelegt');
});

T::run('D Filter: Forum mit und ohne Unterforen, Autor, Zeitraum (UTC bei Zone Berlin), first_only, solved', static function (): void {
    $w = new CsWorld();
    $child = $w->db->forum(['title' => 'Unterforum', 'parent' => $w->forum]);
    $other = $w->db->forum(['title' => 'Anderes']);
    $author = $w->db->user(['username' => 'autor']);
    [$t1, $p1] = $w->thread($w->forum, 'Haupt', 'filterwort eins', ['created_at' => '2026-11-10 22:59:59']);
    [$t2] = $w->thread($child, 'Kind', 'filterwort zwei', ['created_at' => '2026-11-10 23:00:00', 'user' => $author]);
    [$t3] = $w->thread($other, 'Fremd', 'neutral', ['created_at' => '2026-11-11 10:00:00']);
    $reply = $w->reply($t3, 'filterwort drei als Antwort', ['created_at' => '2026-11-11 10:00:00']);
    $w->db->execute('UPDATE cms_community_topics SET solution_post_id = ? WHERE id = ?', [$reply, $t3]);
    $w->build();
    $sort = static function (array $ids): array {
        sort($ids);

        return $ids;
    };
    T::same($sort([$t1, $t2, $t3]), $sort($w->topics($w->member, 'filterwort')), 'ohne Filter alle drei');
    T::same($sort([$t1, $t2]), $sort($w->topics($w->member, 'filterwort', ['forum_id' => $w->forum])), 'Forum mit Unterforen');
    T::same([$t1], $w->topics($w->member, 'filterwort', ['forum_id' => $w->forum, 'with_children' => false]), 'Forum ohne Unterforen');
    T::same([$t2], $w->topics($w->member, 'filterwort', ['user_id' => $author]), 'Autor');
    // Berlin 2026-11-11 00:00 = 2026-11-10 23:00 UTC
    $berlinMidnight = gmmktime(23, 0, 0, 11, 10, 2026);
    T::same($sort([$t2, $t3]), $sort($w->topics($w->member, 'filterwort', ['since' => $berlinMidnight])), 'since: UTC-Grenze einschließlich (23:00:00 UTC dabei, 22:59:59 nicht)');
    T::same([$t1], $w->topics($w->member, 'filterwort', ['until' => $berlinMidnight]), 'until: ausschließlich');
    T::same($sort([$t1, $t2]), $sort($w->topics($w->member, 'filterwort', ['first_only' => true])), 'first_only: nur erste Beiträge');
    T::same([$t3], $w->topics($w->member, 'filterwort', ['solved' => true]), 'solved: nur gelöste Themen');
    $r = $w->search->search($w->member, 'filterwort', ['solved' => true]);
    T::same([true, false], [$r['results'][0]['solved'] ?? null, $r['results'][0]['is_first_post'] ?? null], 'Treffer: solved = true, is_first_post = false');
    T::same([], $w->topics($w->member, 'filterwort', ['forum_id' => 987654]), 'unbekanntes Forum ⇒ keine Treffer');
    T::check($p1 > 0, 'Daten angelegt');
});

T::run('D Auszug und Text: aktueller Text, Zitate nicht indexiert, Klartext statt Markup (T3, Vertrag §7.1a)', static function (): void {
    $w = new CsWorld();
    [$quotedTopic, $quoted] = $w->thread($w->forum, 'Quelle', 'Originaltext mit seltenwort');
    $quote = (string) QuoteService::format($quoted, 'Originaltext mit seltenwort');
    [$quoting] = $w->thread($w->forum, 'Zitierend', $quote . "\n\nEigene Antwort ohne das Wort");
    [$markup, $markupPost] = $w->thread($w->forum, '<script>alert("t")</script> Titel', 'Text mit **fettem** Begriff und <b>roh</b> markupwort');
    [$long, $longPost] = $w->thread($w->forum, 'Lang', str_repeat('Füllwort ', 80) . 'mittelwort ' . str_repeat('Ende ', 80));
    $w->build();
    T::same([$quotedTopic], $w->topics($w->member, 'seltenwort'), 'zitierter Text wird nicht gefunden (nur die Quelle)');
    T::check($quoting > 0, 'zitierendes Thema angelegt');
    $r = $w->search->search($w->member, 'markupwort');
    $hit = $r['results'][0] ?? [];
    T::same('<script>alert("t")</script> Titel', $hit['title'] ?? null, 'Titel unverändert als Klartext (Escaping in PR #4)');
    T::check(!str_contains((string) ($hit['excerpt'] ?? ''), '<strong>') && str_contains((string) ($hit['excerpt'] ?? ''), 'fettem'), 'Auszug ohne erzeugtes HTML (Markdown wird Klartext)', (string) ($hit['excerpt'] ?? ''));
    T::check(str_contains((string) ($hit['excerpt'] ?? ''), '<b>roh</b>'), 'HTML im Markdown bleibt Text im Auszug (kein Markup erzeugt)');
    $w->db->execute('UPDATE cms_community_posts SET body = ? WHERE id = ?', ['Neuer Text mit markupwort danach', $markupPost]);
    T::same('Neuer Text mit markupwort danach', $w->search->search($w->member, 'markupwort')['results'][0]['excerpt'] ?? null, 'Auszug aus dem aktuellen Text (nach der Indexierung bearbeitet)');
    $excerpt = (string) ($w->search->search($w->member, 'mittelwort')['results'][0]['excerpt'] ?? '');
    T::check(mb_strlen($excerpt) <= 240 && str_contains($excerpt, 'mittelwort') && str_starts_with($excerpt, '…') && str_ends_with($excerpt, '…'), 'langer Text: Auszug ≤ 240 Zeichen um das Wort, mit Auslassungszeichen', mb_strlen($excerpt) . ': ' . $excerpt);
    T::check($long > 0 && $longPost > 0, 'langes Thema angelegt');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// E LIKE-Rückfall
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('E LIKE: ohne Index nur für Mitglieder, Gäste unavailable ohne Beitragsabfrage, Maskierung, Fenster (Vertrag §4.8 Nr. 4)', static function (): void {
    $w = new CsWorld([], false);
    [$t1] = $w->thread($w->forum, 'Rabatt', 'Heute 100% günstiger');
    [$t2] = $w->thread($w->forum, 'Unterstrich', 'mit snake_case Namen');
    [$t3] = $w->thread($w->forum, 'Ausruf', 'Achtung! wichtig');
    [$t4] = $w->thread($w->forum, 'Neutral', 'nichts davon 100 x snakeXcase');
    [$t5] = $w->thread($w->forum, 'GROSS', 'GROSSBUCHSTABEN im Text');
    $r = $w->search->search($w->member, '100%');
    T::same(['like', [$t1]], [$r['source'] ?? null, array_column($r['results'] ?? [], 'topic_id')], 'Index fehlt ⇒ LIKE; % maskiert');
    T::same([$t2], $w->topics($w->member, 'snake_case'), '_ maskiert');
    T::same([$t3], $w->topics($w->member, 'Achtung!'), '! maskiert (Escape-Zeichen)');
    T::same([$t5], $w->topics($w->member, 'grossbuchstaben'), 'ASCII ohne Beachtung der Groß-/Kleinschreibung (SQLite)');
    T::same('%a!%b!_c!!d%', SearchRepository::likePattern('a%b_c!d'), 'likePattern()');
    $w->db->log = [];
    $guest = $w->search->search(null, 'snake_case');
    T::same([true, 'unavailable', [], 0], [$guest['success'] ?? null, $guest['source'] ?? null, $guest['results'] ?? null, $guest['total'] ?? null], 'Gast ohne Index ⇒ source unavailable, leer');
    T::same([], array_values(array_filter($w->db->log, static fn (string $sql): bool => str_contains($sql, 'cms_community_posts'))), 'Gast: keine Abfrage der Beiträge (kein LIKE)');
    T::check($t4 > 0, 'neutrales Thema angelegt');

    // Fenster: der älteste Beitrag liegt mehr als LIKE_WINDOW IDs zurück
    $w2 = new CsWorld([], false);
    [$old] = $w2->thread($w2->forum, 'Alt', 'fensterwort alt');
    $topic = $w2->db->topic(['forum' => $w2->forum, 'user' => $w2->member]);
    $w2->db->execute("INSERT INTO cms_community_posts (id, topic_id, forum_id, user_id, is_first_post, status, body, created_at, updated_at) VALUES (60000, ?, ?, ?, 1, 'published', 'fensterwort neu', ?, ?)", [$topic, $w2->forum, $w2->member, csUtc(), csUtc()]);
    T::same([$topic], $w2->topics($w2->member, 'fensterwort'), 'LIKE_WINDOW: Beitrag außerhalb der jüngsten 50.000 IDs wird nicht gefunden');
    T::check($old > 0, 'altes Thema angelegt');

    // ohne Geheimnis gibt es keinen Index (wie indexDir null)
    $w3 = new CsWorld();
    $w3->thread($w3->forum, 'Geheim', 'schluesselwort');
    $w3->build();
    $w3->secret = 'kurz';
    $w3->rebuild();
    T::same('like', $w3->search->search($w3->member, 'schluesselwort')['source'] ?? null, 'ohne brauchbares Geheimnis kein Index: Mitglied LIKE');
    T::same('unavailable', $w3->search->search(null, 'schluesselwort')['source'] ?? null, 'ohne brauchbares Geheimnis: Gast unavailable');
    T::same(false, $w3->search->status()['available'] ?? null, 'status(): nicht verfügbar');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// F Pflege
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('F Pflege: vormerken, flushQueue auf den Datenbankstand, Themen expandieren, Titel nur erster Beitrag', static function (): void {
    $w = new CsWorld();
    [$t1, $p1] = $w->thread($w->forum, 'Pflege', 'eichhorn eins');
    $w->build();
    T::same([$p1], $w->indexed('eichhorn'), 'Ausgang im Index');
    $p2 = $w->reply($t1, 'eichhorn zwei');
    $w->search->queuePosts($p2);
    T::same(['indexed' => 1, 'removed' => 0, 'journaled' => 0], $w->search->flushQueue(), 'neue Antwort indexiert');
    T::same([$p1, $p2], $w->indexed('eichhorn'), 'Antwort im Index');
    $w->db->execute("UPDATE cms_community_posts SET status = 'hidden' WHERE id = ?", [$p2]);
    $w->search->queuePosts($p2, $p2, 0, -5);
    T::same(['indexed' => 0, 'removed' => 1, 'journaled' => 0], $w->search->flushQueue(), 'ausgeblendet ⇒ entfernt (doppelte und ungültige IDs entfallen)');
    T::same([$p1], $w->indexed('eichhorn'), 'Antwort nicht mehr im Index');
    $w->db->execute('DELETE FROM cms_community_posts WHERE id = ?', [$p2]);
    $w->search->queuePosts($p2);
    T::same(1, $w->search->flushQueue()['removed'], 'fehlende Zeile ⇒ entfernt');

    $p3 = $w->reply($t1, 'eichhorn drei');
    $w->build();
    T::same([$p1, $p3], $w->indexed('eichhorn'), 'zwei Dokumente');
    $w->db->execute("UPDATE cms_community_topics SET status = 'deleted' WHERE id = ?", [$t1]);
    $w->search->queueTopic($t1);
    T::same(2, $w->search->flushQueue()['removed'], 'Thema gelöscht ⇒ alle Beiträge entfernt (Expansion)');
    T::same([], $w->indexed('eichhorn'), 'nichts mehr im Index');

    $w->db->execute("UPDATE cms_community_topics SET status = 'published', title = 'Neuer Titel mit siebenschlaefer' WHERE id = ?", [$t1]);
    $w->search->queueTopic($t1, true);
    $result = $w->search->flushQueue();
    T::same(['indexed' => 1, 'removed' => 0, 'journaled' => 0], $result, 'Titeländerung: nur der erste Beitrag neu indexiert');
    T::same([$p1], $w->indexed('siebenschlaefer'), 'neuer Titel im Dokument des ersten Beitrags');
    T::same([$p1], $w->indexed('eichhorn'), 'die Antwort bleibt draußen (nicht expandiert)');

    $noDir = new CsWorld([], false);
    $noDir->search->queuePosts(1, 2, 3);
    T::same(['indexed' => 0, 'removed' => 0, 'journaled' => 0], $noDir->search->flushQueue(), 'ohne Index-Ordner: Vormerkungen verworfen, wirft nicht');
    T::same(['mode' => 'none', 'processed' => 0, 'journal' => 0], $noDir->search->reconcile(), 'reconcile() ohne Index-Ordner: none');
    T::same('invalid_input', $noDir->search->startRebuild()['error'] ?? null, 'startRebuild() ohne Index-Ordner: invalid_input');
    T::same(true, $noDir->search->removePosts([1]), 'removePosts() ohne Index: nichts zu tun');
});

T::run('F Pflege: Sperre belegt ⇒ Journal, nächster Lauf verarbeitet; Überlauf ⇒ Marke; Themen über TOPIC_EXPAND_MAX', static function (): void {
    $w = new CsWorld();
    [$t1, $p1] = $w->thread($w->forum, 'Journal', 'murmeltier');
    $w->build();
    $p2 = $w->reply($t1, 'murmeltier zwei');
    $lock = fopen($w->dir . '/' . CommunitySearch::LOCK_FILE, 'c');
    flock($lock, LOCK_EX);
    $w->search->queuePosts($p2);
    $w->search->queueTopic($t1, true);
    $result = $w->search->flushQueue();
    T::same(['indexed' => 0, 'removed' => 0, 'journaled' => 2], $result, 'Sperre belegt ⇒ zwei Zeilen ins Journal');
    T::same("p:{$p2}\nf:{$t1}\n", (string) file_get_contents($w->journalFile()), 'Journalzeilen p:<id> und f:<id>');
    flock($lock, LOCK_UN);
    fclose($lock);
    T::same([$p1], $w->indexed('murmeltier'), 'Antwort noch nicht im Index');
    $w->search->queuePosts($p1);
    $result = $w->search->flushQueue();
    T::same([2, 0], [$result['indexed'], $result['journaled']], 'nächster Lauf verarbeitet Journal und Vormerkung');
    T::same([$p1, $p2], $w->indexed('murmeltier'), 'Antwort im Index');
    T::same('', (string) file_get_contents($w->journalFile()), 'Journal geleert');

    // Überlauf
    file_put_contents($w->journalFile(), str_repeat("p:1\n", intdiv(CommunitySearch::JOURNAL_MAX_BYTES, 4)));
    $lock = fopen($w->dir . '/' . CommunitySearch::LOCK_FILE, 'c');
    flock($lock, LOCK_EX);
    csLogReset();
    $w->search->queuePosts($p1);
    $w->search->flushQueue();
    flock($lock, LOCK_UN);
    fclose($lock);
    T::same([true, ''], [is_file($w->dir . '/' . CommunitySearch::STALE_FILE), (string) file_get_contents($w->journalFile())], 'Journal über JOURNAL_MAX_BYTES ⇒ geleert, Marke rebuild-recommended');
    T::check(str_contains(csLog(), 'Neuaufbau empfohlen'), 'Marke protokolliert');
    T::same(true, $w->search->status()['stale'] ?? null, 'status(): stale');

    // Expansion über TOPIC_EXPAND_MAX ⇒ Marke
    @unlink($w->dir . '/' . CommunitySearch::STALE_FILE);
    $big = $w->db->topic(['forum' => $w->forum, 'user' => $w->member]);
    $w->db->sqlite->beginTransaction();
    for ($i = 0; $i < CommunitySearch::TOPIC_EXPAND_MAX + 1; $i++) {
        $w->db->sqlite->exec("INSERT INTO cms_community_posts (topic_id, forum_id, user_id, is_first_post, status, body, created_at, updated_at) VALUES ({$big}, {$w->forum}, {$w->member}, 0, 'deleted', 'x', '2026-11-01 00:00:00', '2026-11-01 00:00:00')");
    }
    $w->db->sqlite->commit();
    $w->search->queueTopic($big);
    $w->search->flushQueue();
    T::same(true, is_file($w->dir . '/' . CommunitySearch::STALE_FILE), 'Thema mit mehr als TOPIC_EXPAND_MAX Beiträgen ⇒ Marke');
});

T::run('F Neuaufbau: Schritte, Aufbaudatei, Änderung während des Aufbaus in beiden Dateien, rename, Abbruch, Fehler', static function (): void {
    $w = new CsWorld();
    [$t1, $p1] = $w->thread($w->forum, 'Aufbau', 'biber eins');
    $p2 = $w->reply($t1, 'biber zwei');
    $p3 = $w->reply($t1, 'biber drei');
    $start = $w->search->startRebuild(7);
    T::same([true, false, 'pending'], [$start['success'] ?? null, $start['resumed'] ?? null, $start['job']['status'] ?? null], 'startRebuild(): Job angelegt');
    T::same(['start', CommunitySearch::JOB_TYPE, CommunitySearch::JOB_SCOPE, $p3, ['after_id' => 0, 'max_id' => $p3, 'created' => false], 7], $w->jobs->calls[0], 'Job mit Typ, Scope, max_id und Startzustand');
    T::same(true, $w->search->startRebuild()['resumed'] ?? null, 'ein Job zugleich (fortgesetzt)');
    $jobId = (int) $start['job']['id'];
    // Schritt 1 legt die Aufbaudatei an und indexiert das erste Fenster
    $state = ['after_id' => 0, 'max_id' => $p3, 'created' => false];
    T::same(false, is_file($w->liveFile()), 'vor dem Aufbau kein Live-Index');
    $step = $w->search->rebuildStep(['after_id' => 0, 'max_id' => $p2, 'created' => false] + $state);
    T::same([true, $p2, true], [$step['done'], $step['state']['after_id'], $step['state']['created']], 'ein Fenster bis max_id ⇒ done im ersten Schritt');
    T::same([true, false], [is_file($w->liveFile()), is_file($w->buildFile())], 'Aktivierung per rename(): Live-Index, keine Aufbaudatei');
    T::same([$p1, $p2], $w->indexed('biber'), 'nur bis max_id indexiert');
    // Neuaufbau über den Job mit einer Änderung während des Aufbaus
    $w->db->execute("UPDATE cms_community_posts SET status = 'hidden' WHERE id = ?", [$p1]);
    $w->jobs->jobs[$jobId]['state'] = ['after_id' => 0, 'max_id' => 0, 'created' => false];
    $w->search->rebuildStep(['after_id' => 0, 'max_id' => $p3, 'created' => false]);
    T::same(true, is_file($w->buildFile()) || is_file($w->liveFile()), 'Datei vorhanden');
    // gezielt: Aufbaudatei anlegen, ohne fertig zu werden
    $w2 = new CsWorld();
    [$tt, $q1] = $w2->thread($w2->forum, 'Zwei Dateien', 'dachs eins');
    $w2->build();
    $posts = [];
    for ($i = 0; $i < 250; $i++) {
        $posts[] = $w2->reply($tt, 'dachs mehr ' . $i);
    }
    $max = end($posts);
    $first = $w2->search->rebuildStep(['after_id' => 0, 'max_id' => $max, 'created' => false]);
    T::same([false, true, true], [$first['done'], is_file($w2->buildFile()), is_file($w2->liveFile())], 'unfertiger Aufbau: Live- und Aufbaudatei');
    T::same(CommunitySearch::INDEX_BATCH, $first['state']['after_id'] - 0 >= 1 ? count(array_filter($posts, static fn (int $id): bool => $id <= $first['state']['after_id'])) + 1 : 0, 'ein Schritt liest höchstens INDEX_BATCH Zeilen');
    $changed = $posts[249];
    $w2->db->execute('UPDATE cms_community_posts SET body = ? WHERE id = ?', ['dachs geaendert marder', $changed]);
    $w2->search->queuePosts($changed);
    $w2->search->flushQueue();
    T::same([[$changed], [$changed]], [$w2->indexed('marder', $w2->liveFile()), $w2->indexed('marder', $w2->buildFile())], 'Änderung während des Aufbaus landet in Live- und Aufbaudatei');
    $state = $first['state'];
    while (true) {
        $next = $w2->search->rebuildStep($state);
        $state = $next['state'];
        if ($next['done']) {
            break;
        }
    }
    T::same([[$changed], false], [$w2->indexed('marder'), is_file($w2->buildFile())], 'nach dem Aufbau: Änderung erhalten, Aufbaudatei aktiviert');
    // Abbruch: nächster Start legt die Aufbaudatei neu an
    $abort = $w2->search->rebuildStep(['after_id' => 0, 'max_id' => $max, 'created' => false]);
    T::same(true, is_file($w2->buildFile()), 'abgebrochener Aufbau hinterlässt die Aufbaudatei');
    $restart = $w2->search->rebuildStep(['after_id' => $abort['state']['after_id'], 'max_id' => $max, 'created' => false]);
    T::same(true, $restart['state']['after_id'] <= CommunitySearch::INDEX_BATCH + $q1 && $restart['state']['created'], 'neuer Start (created = false) beginnt von vorn');
    @unlink($w2->buildFile());
    $missing = $w2->search->rebuildStep(['after_id' => 100, 'max_id' => $max, 'created' => true]);
    T::same(true, $missing['state']['after_id'] <= CommunitySearch::INDEX_BATCH + $q1, 'Aufbaudatei fehlt trotz created ⇒ von vorn');
    // runRebuildStep / cancelRebuild über den Fake-Job
    $job = $w2->search->startRebuild();
    $id = (int) $job['job']['id'];
    $run = $w2->search->runRebuildStep($id);
    T::same([true, 'running'], [$run['success'] ?? null, $run['job']['status'] ?? null], 'runRebuildStep(): ein Schritt über BatchJobService::step()');
    T::same(['success' => true], $w2->search->cancelRebuild($id), 'cancelRebuild()');
    T::same(false, is_file($w2->buildFile()), 'Abbruch entfernt die Aufbaudatei');
    T::same('invalid_input', $w2->search->runRebuildStep(999)['error'] ?? null, 'unbekannter Job ⇒ invalid_input');
    $w2->jobs->jobs[50] = ['id' => 50, 'type' => 'community_render', 'scope' => 'x', 'status' => 'pending', 'total' => 0, 'processed' => 0, 'failed' => 0, 'state' => []];
    T::same(['invalid_input', 'invalid_input'], [$w2->search->runRebuildStep(50)['error'] ?? null, $w2->search->cancelRebuild(50)['error'] ?? null], 'fremder Jobtyp ⇒ invalid_input');
});

T::run('F reconcile(): Nachholen über cms_last_id, Neuaufbau bei fehlendem Index, Marke oder Sprachwechsel, Verbundindex (GS4)', static function (): void {
    $w = new CsWorld();
    [$t1, $p1] = $w->thread($w->forum, 'Abgleich', 'hamster eins');
    $first = $w->search->reconcile();
    T::same('rebuild', $first['mode'], 'Index fehlt ⇒ Neuaufbau');
    T::same([true, [$p1]], [is_file($w->liveFile()), $w->indexed('hamster')], 'Neuaufbau in einem Cron-Lauf fertig');
    $p2 = $w->reply($t1, 'hamster zwei');
    $p3 = $w->reply($t1, 'hamster drei');
    $catchup = $w->search->reconcile();
    T::same(['catchup', 2], [$catchup['mode'], $catchup['processed']], 'neue Beiträge nachgeholt');
    T::same([$p1, $p2, $p3], $w->indexed('hamster'), 'im Index');
    T::same((int) $p3, (int) $w->search->status()['last_id'], 'cms_last_id fortgeschrieben');
    T::same(0, $w->search->reconcile()['processed'], 'nichts Neues ⇒ 0');
    // Journal verarbeitet der Cron
    file_put_contents($w->journalFile(), "p:{$p2}\n");
    $w->db->execute("UPDATE cms_community_posts SET status = 'hidden' WHERE id = ?", [$p2]);
    T::same(1, $w->search->reconcile()['journal'], 'Journal im Abgleich verarbeitet');
    T::same([$p1, $p3], $w->indexed('hamster'), 'Journalzeile auf den Datenbankstand gesetzt');
    // Verbundindex fehlt in einer vorhandenen Datei ⇒ reconcile legt ihn an
    $pdo = new \PDO('sqlite:' . $w->liveFile());
    $pdo->exec('DROP INDEX IF EXISTS doclist_term_hits');
    T::same(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'doclist_term_hits'")->fetchColumn(), 'Verbundindex entfernt');
    $w->search->reconcile();
    T::same(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'doclist_term_hits'")->fetchColumn(), 'reconcile() legt doclist_term_hits in einer Datei ohne ihn an');
    $pdo = null;
    // Marke ⇒ Neuaufbau
    touch($w->dir . '/' . CommunitySearch::STALE_FILE);
    T::same('rebuild', $w->search->reconcile()['mode'], 'Marke ⇒ Neuaufbau');
    T::same(false, is_file($w->dir . '/' . CommunitySearch::STALE_FILE), 'Marke nach dem Neuaufbau gelöscht');
    // Sprachwechsel ⇒ Neuaufbau
    $en = new CommunitySearch($w->db, $w->settings, static fn (): int => csNow(), fn (?int $u, string $a, array $c): AccessDecision => $w->access->can($u, $a, $c), fn (?int $u): array => $w->access->readableForumIds($u), static fn (string $a, ?int $u): array => ['allowed' => true, 'retry_after' => 0], fn (): ?string => $w->dir, null, fn (): object => $w->jobs, 'en', static fn (): string => CS_SECRET);
    T::same('rebuild', $en->reconcile()['mode'], 'Sprache des Index (de) ≠ Stemmer-Sprache (en) ⇒ Neuaufbau');
    T::same('en', $en->status()['locale'], 'status(): Sprache');
    $pdo = new \PDO('sqlite:' . $w->liveFile());
    T::same('en', $pdo->query("SELECT value FROM info WHERE key = 'cms_locale'")->fetchColumn(), 'neuer Index mit cms_locale = en');
});

T::run('F removePosts() (PR #5): sofort aus Live- und Aufbaudatei, ohne Datenbank', static function (): void {
    $w = new CsWorld();
    [$t1, $p1] = $w->thread($w->forum, 'Weg', 'luchs eins');
    $p2 = $w->reply($t1, 'luchs zwei');
    $w->build();
    for ($i = 0; $i < 250; $i++) {
        $w->reply($t1, 'luchs weiter ' . $i);
    }
    $w->search->rebuildStep(['after_id' => 0, 'max_id' => (int) $w->db->execute('SELECT MAX(id) FROM cms_community_posts')->fetchColumn(), 'created' => false]);
    T::check(is_file($w->buildFile()), 'Aufbaudatei vorhanden');
    $w->db->log = [];
    T::same(true, $w->search->removePosts([$p1, $p2, 'x', -1]), 'removePosts()');
    T::same([], $w->db->log, 'ohne Datenbank');
    T::same([[], []], [array_values(array_intersect($w->indexed('luchs'), [$p1, $p2])), array_values(array_intersect($w->indexed('luchs', $w->buildFile()), [$p1, $p2]))], 'aus beiden Dateien entfernt');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// G Ähnliche Themen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('G similarTopics(): unter 3 Wörtern leer, nur erste Beiträge, nur lesbare Foren, ohne Index leer', static function (): void {
    $w = new CsWorld();
    $intern = $w->db->forum(['title' => 'Intern']);
    (new ForumRepository($w->db))->savePermissions($intern, [['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 0]], ['subject_type' => 'guest', 'subject_key' => '', 'permissions' => ['read' => 0]]], csUtc());
    [$a] = $w->thread($w->forum, 'Drucker druckt keine Seiten', 'Mein Drucker druckt nichts');
    [$b] = $w->thread($intern, 'Drucker druckt intern', 'Drucker Seiten intern');
    [$c] = $w->thread($w->forum, 'Ganz anderes Thema', 'Kaffee');
    $w->reply($c, 'Drucker druckt Seiten auch hier');
    $w->build();
    $w->db->log = [];
    T::same(['success' => true, 'results' => []], $w->search->similarTopics($w->member, 'Drucker kaputt'), 'unter 3 Wörtern leer');
    T::same([], $w->db->log, 'ohne Datenbank');
    $r = $w->search->similarTopics($w->member, 'Drucker druckt Seiten');
    T::same([$a], array_column($r['results'] ?? [], 'topic_id'), 'nur erste Beiträge lesbarer Foren');
    T::same(['topic_id', 'forum_id', 'title', 'slug', 'score'], array_keys($r['results'][0] ?? []), 'Form');
    T::same('invalid_input', $w->search->similarTopics($w->member, 'Drucker druckt Seiten', 11)['error'] ?? null, 'limit 11 ⇒ invalid_input');
    $w->throttleAllowed = false;
    T::same('rate_limited', $w->search->similarTopics($w->member, 'Drucker druckt Seiten')['error'] ?? null, 'Drossel search');
    $noIndex = new CsWorld([], false);
    $noIndex->thread($noIndex->forum, 'Drucker druckt Seiten', 'x');
    T::same(['success' => true, 'results' => []], $noIndex->search->similarTopics($noIndex->member, 'Drucker druckt Seiten'), 'ohne Index leer (kein LIKE)');
    T::check($b > 0, 'internes Thema angelegt');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// H Sitemap
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('H Sitemap: Gastsicht, ohne interne, versteckte, Verweis-, leere und unsichtbare Foren, Aktivität, lastmod (E26)', static function (): void {
    $w = new CsWorld();
    $db = $w->db;
    $db->execute('UPDATE cms_community_forums SET topic_count = 2, last_post_at = ?, slug_en = ? WHERE id = ?', ['2026-11-15 10:00:00', 'general', $w->forum]);
    $hiddenCategory = $db->category(['visible' => false]);
    $intern = $db->forum(['title' => 'Intern', 'slug' => 'intern', 'topic_count' => 1]);
    (new ForumRepository($db))->savePermissions($intern, [['subject_type' => 'guest', 'subject_key' => '', 'permissions' => ['read' => 0]]], csUtc());
    $hidden = $db->forum(['title' => 'Versteckt', 'slug' => 'versteckt', 'status' => 'hidden', 'topic_count' => 1]);
    $link = $db->forum(['title' => 'Verweis', 'slug' => 'verweis', 'type' => 'link', 'topic_count' => 1]);
    $empty = $db->forum(['title' => 'Leer', 'slug' => 'leer', 'topic_count' => 0]);
    $invisible = $db->forum(['title' => 'Unsichtbar', 'slug' => 'unsichtbar', 'category' => $hiddenCategory, 'topic_count' => 1]);
    $second = $db->forum(['title' => 'Zweites', 'slug' => 'zweites', 'topic_count' => 1]);
    $db->execute('UPDATE cms_community_forums SET last_post_at = NULL, updated_at = ? WHERE id = ?', ['2026-11-12 08:30:00', $second]);
    $old = $db->topic(['forum' => $w->forum, 'slug' => 'alt', 'last_post_at' => '2026-11-01 09:00:00']);
    $new = $db->topic(['forum' => $w->forum, 'slug' => 'neu', 'last_post_at' => '2026-11-15 10:00:00']);
    $other = $db->topic(['forum' => $second, 'slug' => '', 'last_post_at' => '2026-11-10 09:00:00']);
    $db->topic(['forum' => $w->forum, 'slug' => 'verschoben', 'status' => 'moved', 'last_post_at' => '2026-11-16 10:00:00']);
    $db->topic(['forum' => $w->forum, 'slug' => 'wartend', 'status' => 'pending', 'last_post_at' => '2026-11-16 10:00:00']);
    foreach ([$intern, $hidden, $link, $empty, $invisible] as $forum) {
        $db->topic(['forum' => $forum, 'slug' => 'versteckt-' . $forum]);
    }
    $w->rebuild();
    $w->clockReads = 0;
    $entries = $w->search->sitemapEntries();
    T::same(0, $w->clockReads, 'Sitemap liest keine Uhr');
    T::same([
        ['url' => '/forum', 'lastmod' => '2026-11-15T10:00:00+00:00', 'changefreq' => 'daily', 'priority' => 0.6],
        ['url' => '/forum/allgemein', 'lastmod' => '2026-11-15T10:00:00+00:00', 'changefreq' => 'daily', 'priority' => 0.6],
        ['url' => '/forum/zweites', 'lastmod' => '2026-11-12T08:30:00+00:00', 'changefreq' => 'daily', 'priority' => 0.6],
        ['url' => '/forum/thema/' . $new . '-neu', 'lastmod' => '2026-11-15T10:00:00+00:00', 'changefreq' => 'weekly', 'priority' => 0.5],
        ['url' => '/forum/thema/' . $other, 'lastmod' => '2026-11-10T09:00:00+00:00', 'changefreq' => 'weekly', 'priority' => 0.5],
        ['url' => '/forum/thema/' . $old . '-alt', 'lastmod' => '2026-11-01T09:00:00+00:00', 'changefreq' => 'weekly', 'priority' => 0.5],
    ], $entries, 'Startseite, Foren mit Themen (DE-Slug, lastmod last_post_at bzw. updated_at), Themen nach Aktivität; ohne verschobene und wartende');
    T::same('2026-11-15T10:00:00+00:00', CommunitySearch::w3c('2026-11-15 10:00:00'), 'w3c(): UTC-Text bei Zone Berlin');
    T::same([null, null, null], [CommunitySearch::w3c(null), CommunitySearch::w3c('2026-11-15'), CommunitySearch::w3c('15.11.2026 10:00:00')], 'w3c(): sonst null');

    $closed = new CsWorld(['guests_can_read' => false]);
    $closed->db->execute('UPDATE cms_community_forums SET topic_count = 1 WHERE id = ?', [$closed->forum]);
    $closed->db->topic(['forum' => $closed->forum]);
    T::same([], $closed->search->sitemapEntries(), 'Gast-Tor aus ⇒ []');
    $w->module = false;
    $w->rebuild();
    T::same([], $w->search->sitemapEntries(), 'Modul aus ⇒ []');
    $w->module = true;
    $w->rebuild();
    $w->db->failOn = ['/FROM cms_community_topics/'];
    csLogReset();
    T::same([], $w->search->sitemapEntries(), 'Fehler ⇒ []');
    T::check(str_contains(csLog(), 'sitemapEntries'), 'Fehler protokolliert');
    $w->db->failOn = [];

    T::same(5000, CommunitySearch::SITEMAP_MAX_ENTRIES, 'SITEMAP_MAX_ENTRIES');
    $big = new CsWorld();
    $big->db->execute('UPDATE cms_community_forums SET topic_count = 5100 WHERE id = ?', [$big->forum]);
    $big->db->sqlite->beginTransaction();
    for ($i = 0; $i < 5100; $i++) {
        $big->db->sqlite->exec("INSERT INTO cms_community_topics (forum_id, title, slug, status, last_post_at, created_at, updated_at) VALUES ({$big->forum}, 't', 's{$i}', 'published', '2026-11-01 00:00:00', '2026-11-01 00:00:00', '2026-11-01 00:00:00')");
    }
    $big->db->sqlite->commit();
    T::same(5000, count($big->search->sitemapEntries()), 'höchstens 5000 Einträge');

    $hooks = (string) file_get_contents(ABSPATH . 'core/Services/FeatureHooks/CommunityHooks.php');
    T::check(!str_contains($hooks, 'cms_sitemap_entries'), 'CommunityHooks meldet cms_sitemap_entries nicht an (PR #4)');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// I Medienextraktor
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('I CommunityMediaExtractor: Inhaltsart, IDs mit Statusfilter, load(), Konstruktor ohne Abfrage (E27)', static function (): void {
    $w = new CsWorld();
    $w->db->log = [];
    $extractor = new CommunityMediaExtractor($w->db);
    T::same([], $w->db->log, 'Konstruktor ohne Abfrage');
    T::check($extractor instanceof \CMS\Services\Media\MediaReferenceExtractor, 'implementiert MediaReferenceExtractor');
    T::same(['community_post', 'Forenbeitrag'], [$extractor->contentType(), $extractor->label()], 'Inhaltsart und Name');
    [$topic, $first] = $w->thread($w->forum, 'Medien', 'Bild ![x](/uploads/media/2026/bild.jpg)');
    $ids = ['published' => $first];
    foreach (['pending', 'hidden', 'deleted', 'rejected'] as $status) {
        $ids[$status] = $w->reply($topic, 'Antwort ' . $status . ' /uploads/datei.pdf', ['status' => $status]);
    }
    T::same([$first, $ids['pending'], $ids['hidden']], $extractor->ids(-1, 100), 'ids(-1, n): aufsteigend, nur published, pending, hidden');
    T::same([$ids['pending'], $ids['hidden']], $extractor->ids($first, 100), 'ids() nach afterId');
    T::same([$first], $extractor->ids(-1, 1), 'Grenze');
    $loaded = $extractor->load($first);
    T::same(['title' => 'Medien', 'edit_url' => '/forum/beitrag/' . $first, 'values' => [['context' => 'content', 'value' => 'Bild ![x](/uploads/media/2026/bild.jpg)']]], $loaded, 'load(): Titel, Link, Inhalt');
    T::same('Medien · #' . $ids['hidden'], $extractor->load($ids['hidden'])['title'] ?? null, 'Antwort: Titel mit · #<id>');
    T::same([null, null, null, null], [$extractor->load($ids['deleted']), $extractor->load($ids['rejected']), $extractor->load(999999), $extractor->load(0)], 'gelöscht, abgelehnt, fehlend ⇒ null');
    $w->db->execute("UPDATE cms_community_topics SET title = '' WHERE id = ?", [$topic]);
    T::check(str_starts_with((string) ($extractor->load($first)['title'] ?? ''), 'thema-'), 'leerer Titel ⇒ Slug (ContentTableReferenceExtractor::title)');
    T::same(['published', 'pending', 'hidden'], CommunityMediaExtractor::STATUSES, 'STATUSES');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// J Uhr
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('J Uhr: genau ein Lesen je search(); Konstruktor löst weder Sprache noch Geheimnis auf', static function (): void {
    $w = new CsWorld();
    $w->thread($w->forum, 'Uhr', 'wecker');
    $w->build();
    $w->clockReads = 0;
    $w->search->search($w->member, 'wecker');
    T::same(1, $w->clockReads, 'search(): ein Lesen der Uhr');
    $w->clockReads = 0;
    $w->search->similarTopics($w->member, 'drei kleine wecker');
    T::same(1, $w->clockReads, 'similarTopics(): ein Lesen der Uhr');
    $calls = 0;
    I18n::reset('de', 'de');
    $lazy = new CommunitySearch($w->db, $w->settings, static fn (): int => csNow(), null, null, null, fn (): ?string => $w->dir, null, fn (): object => $w->jobs, null, function () use (&$calls): string {
        $calls++;

        return CS_SECRET;
    });
    I18n::reset('en', 'en');
    T::same([0, 'en'], [$calls, $lazy->status()['locale']], 'Sprache erst bei der Nutzung (nach dem Konstruieren auf en gestellt)');
    T::same(1, $calls, 'Geheimnis erst bei der Nutzung, einmal je Instanz');
    I18n::reset('de', 'de');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// K Dateinamen, Status, Verbundindex (Fassung 3/3.1)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('K Dateinamen mit Kennzeichen aus dem Geheimnis, Schlüsselwechsel, status() (Vertrag §4.8 Nr. 5, 6)', static function (): void {
    $w = new CsWorld();
    [$t1, $p1] = $w->thread($w->forum, 'Name', 'otter');
    $w->build();
    $tag = csTag(CS_SECRET);
    T::same(16, strlen($tag), 'Kennzeichen: 16 Hex-Zeichen');
    $files = array_values(array_diff(scandir($w->dir) ?: [], ['.', '..']));
    T::same(['index.lock', 'posts-' . $tag . '.index'], $files, 'Live-Index unter posts-<Kennzeichen>.index, Sperre fest');
    T::check(!in_array('posts.index', $files, true), 'kein fester Name');
    T::check(csTag('anderes-geheimnis-0123456789abcdef') !== $tag, 'anderes Geheimnis ⇒ anderer Name');
    $status = $w->search->status();
    T::same(['available' => true, 'built' => true, 'documents' => 1, 'last_id' => $p1, 'stale' => false, 'locale' => 'de', 'job' => null], array_diff_key($status, ['outside_webroot' => 1]), 'status()');
    T::same(\CMS\Services\PrivateStorageService::getInstance()->isOutsideWebroot(), $status['outside_webroot'] ?? null, 'status().outside_webroot aus PrivateStorageService');
    T::same(true, $status['outside_webroot'], 'privater Testordner liegt außerhalb des Webroots');

    // Standard des Geheimnisses: erste brauchbare Konstante wie IpHash (hier SECURE_AUTH_KEY)
    $default = new CommunitySearch($w->db, $w->settings, static fn (): int => csNow(), null, null, null, fn (): ?string => $w->dir, null, fn (): object => $w->jobs, 'de');
    $default->rebuildStep(['after_id' => 0, 'max_id' => $p1, 'created' => false]);
    T::check(is_file($w->dir . '/posts-' . csTag(SECURE_AUTH_KEY) . '.index'), 'Standard-Geheimnis: SECURE_AUTH_KEY (NONCE_KEY ist der Platzhalter)');

    // Schlüsselwechsel: Neuaufbau löscht Index und Journal mit fremdem Kennzeichen
    $journalOld = $w->journalFile();
    file_put_contents($journalOld, "p:1\n");
    $oldIndex = $w->liveFile();
    $w->secret = 'neues-geheimnis-fuer-den-index-0123456789';
    $w->rebuild();
    $w->build();
    T::same([false, false, true], [is_file($oldIndex), is_file($journalOld), is_file($w->liveFile())], 'Schlüsselwechsel, Neuaufbau ⇒ alter Index und altes Journal weg, die Datei unter dem neuen Kennzeichen bleibt');
    T::check(!is_file($w->dir . '/posts-' . csTag(SECURE_AUTH_KEY) . '.index'), 'auch der Index des Standard-Geheimnisses ist weg');
    T::same([$t1], $w->topics($w->member, 'otter'), 'Suche über den neuen Index');
});

T::run('K Verbundindex doclist_term_hits: nach dem Aufbau im Plan der TNT-Abfrage, maxDocs = MAX_DOCS_PER_WORD (Vertrag §4.8 Nr. 2)', static function (): void {
    $w = new CsWorld();
    $w->thread($w->forum, 'Plan', 'elch');
    $w->build();
    $pdo = new \PDO('sqlite:' . $w->liveFile());
    $plan = implode(' | ', array_map(static fn (array $row): string => (string) $row['detail'], $pdo->query('EXPLAIN QUERY PLAN SELECT * FROM doclist WHERE term_id = 1 ORDER BY hit_count DESC LIMIT 20000')->fetchAll(\PDO::FETCH_ASSOC)));
    T::check(str_contains($plan, 'doclist_term_hits') && !str_contains($plan, 'TEMP B-TREE'), 'EXPLAIN QUERY PLAN nennt doclist_term_hits, ohne Sortierung', $plan);
    T::same('CREATE INDEX IF NOT EXISTS doclist_term_hits ON doclist (term_id, hit_count)', CommunitySearch::HITS_INDEX_SQL, 'HITS_INDEX_SQL');
    $w->db->log = [];
    $w->search->search($w->member, 'elch');
    T::same(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'doclist_term_hits'")->fetchColumn(), 'search() legt nichts an (Index vorhanden)');
});

T::run('K Repository: Rechtebedingung über t.forum_id, Stücke zu 500, leere Listen ohne Abfrage', static function (): void {
    $w = new CsWorld();
    $repo = new SearchRepository($w->db);
    [$topic, $post] = $w->thread($w->forum, 'Repo', 'x');
    $w->db->log = [];
    T::same([], $repo->verify([$post], [], []), 'keine Foren ⇒ []');
    T::same([], $repo->verify([], [$w->forum], []), 'keine IDs ⇒ []');
    T::same([], $w->db->log, 'ohne Abfrage');
    $ids = range(1, 1100);
    $ids[] = $post;
    $w->db->log = [];
    $rows = $repo->verify($ids, [$w->forum], []);
    T::same([3, [$post]], [count($w->db->log), array_column($rows, 'id')], 'verify(): 1101 IDs in drei Stücken');
    T::check(str_contains($w->db->log[0], "AND p.status = 'published' AND t.status = 'published' AND t.forum_id IN ("), 'Rechte über t.forum_id, beide Status');
    T::same(['id', 'topic_id', 'user_id', 'is_first_post', 'created_at', 'forum_id', 'title', 'slug', 'solution_post_id'], array_keys($rows[0]), 'Form ohne body und ip_hash');
    T::same([$post => 'x'], $repo->bodies([$post, 999999]), 'bodies()');
    T::same($post, $repo->firstPostId($topic), 'firstPostId()');
    T::same([$post], $repo->topicPostIds($topic, 0, 10), 'topicPostIds()');
    T::same([], $repo->sitemapTopics([], 10), 'sitemapTopics() ohne Foren ⇒ []');
    T::same([], $repo->mediaIds(0, 10, ['bogus']), 'mediaIds() mit unbekanntem Status ⇒ []');
});

foreach (glob(__DIR__ . '/part-*.php') ?: [] as $part) {
    require $part;
}

T::finish();
