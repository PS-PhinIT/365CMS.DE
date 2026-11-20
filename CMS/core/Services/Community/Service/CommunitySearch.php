<?php
/**
 * Suche, Suchindex und Sitemap der Community (PR #2b; Vertrag DESIGN-2b §2.1, §3.6, §4.8, Entwurf medien §1.4, §1.5, §2.5 bis §2.7).
 *
 * - Eigener TNT-Index im privaten Speicher (`community-index/`), ein Dokument je veröffentlichtem Beitrag in veröffentlichtem Thema
 *   (Titel nur beim ersten Beitrag, Text ohne Zitatblöcke). Dateinamen tragen ein Kennzeichen aus dem Geheimnis; ohne Geheimnis gibt es
 *   keinen Index. Je Wort liest TNT höchstens MAX_DOCS_PER_WORD Dokumente über den Verbundindex `doclist_term_hits`.
 * - Jeder Treffer wird per SQL nachgeprüft (Beitrag und Thema `published`, Forum lesbar); Auszug und Titel kommen aus der Datenbank.
 *   Ohne Index: LIKE-Rückfall nur für Mitglieder, Gäste erhalten `source = 'unavailable'`.
 * - Pflege: Vormerken je Prozess, Schreiben bei Request-Ende unter `flock(index.lock)`; bei belegter Sperre ins Journal. Der Cron
 *   (`reconcile()`) verarbeitet das Journal, holt neue Beiträge nach und baut bei Bedarf neu auf (BatchJobService). Jeder Schreibweg
 *   setzt den Eintrag auf den Datenbankstand und schreibt in die Live- und, falls vorhanden, die Aufbaudatei.
 * - Sitemap (`sitemapEntries()`): Gastsicht, höchstens SITEMAP_MAX_ENTRIES Einträge; angemeldet wird der Filter erst in PR #4.
 *
 * Sprache des Stemmers und Geheimnis werden erst bei der ersten Nutzung aufgelöst. Keine Datenbanksperren, keine Transaktion.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\BatchJobService;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\SearchRepository;
use CMS\Services\Community\Support\ContentRenderer;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\IpHash;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Urls;
use CMS\Services\PrivateStorageService;
use CMS\Services\SearchService;
use CMS\Services\SiteSearchService;
use TeamTNT\TNTSearch\TNTSearch;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunitySearch
{
    /** Bereich im privaten Speicher. */
    public const AREA = 'community-index';
    /** Live-Index; `%s` = Kennzeichen aus dem Geheimnis. */
    public const INDEX_FILE = 'posts-%s.index';
    /** Aufbaudatei des Neuaufbaus. */
    public const BUILD_FILE = 'posts-%s.building.index';
    public const LOCK_FILE = 'index.lock';
    /** Journal vorgemerkter Änderungen bei belegter Sperre. */
    public const JOURNAL_FILE = 'pending-%s.log';
    /** Marke „Neuaufbau empfohlen“. */
    public const STALE_FILE = 'rebuild-recommended';
    /** Themen je Suche; Seiten schneidet PHP. */
    public const MAX_TOPICS = 100;
    public const PER_PAGE = 20;
    public const PER_PAGE_MAX = 50;
    /** Stücke der Prüfung je Suche (× `SearchRepository::VERIFY_CHUNK` = höchstens 5000 Kandidaten). */
    public const VERIFY_CHUNKS = 10;
    /** LIKE-Rückfall: jüngste Beitrags-IDs. */
    public const LIKE_WINDOW = 50000;
    public const LIKE_LIMIT = 400;
    public const TITLE_BONUS = 2.0;
    public const SIMILAR_MIN_WORDS = 3;
    /** Dokumente je SQLite-Transaktion. */
    public const INDEX_BATCH = 200;
    /** Beiträge je vorgemerktem Thema; darüber Neuaufbau empfohlen. */
    public const TOPIC_EXPAND_MAX = 5000;
    public const JOURNAL_MAX_BYTES = 1048576;
    /** ID-Fenster je Neuaufbau-Schritt. */
    public const REBUILD_WINDOW = 5000;
    /** Beiträge je stündlichem Abgleich. */
    public const CATCHUP_MAX = 2000;
    public const JOB_TYPE = 'community_search_index';
    public const JOB_SCOPE = 'posts';
    public const CRON_STEPS = 20;
    /** Wartezeit von SQLite auf eine gesperrte Datei (Sekunden). */
    public const BUSY_TIMEOUT = 2;
    /** Versuche auf `index.lock` zu je 100 ms bei Request-Ende; der Cron wartet blockierend. */
    public const LOCK_TRIES = 10;
    /** Dokumente je Wort, die TNT höchstens liest (Vertrag §4.8 Nr. 2). */
    public const MAX_DOCS_PER_WORD = 20000;
    /** Verbundindex für `ORDER BY hit_count DESC LIMIT` je Wort (Fassung 3.1). */
    public const HITS_INDEX_SQL = 'CREATE INDEX IF NOT EXISTS doclist_term_hits ON doclist (term_id, hit_count)';
    /** Einträge der Sitemap (halbe Plugin-Grenze von `SeoSitemapService::MAX_PLUGIN_ENTRIES`). */
    public const SITEMAP_MAX_ENTRIES = 5000;

    /** IDs je LIKE-Abfrage (das Fenster LIKE_WINDOW in höchstens 10 Stücken, jedes über PRIMARY). */
    private const LIKE_CHUNK = 5000;
    /** Wörter je Suche (`parseQuery()` lässt 8 Begriffe zu); begrenzt die Indexlesungen auf 8 × MAX_DOCS_PER_WORD. */
    private const MAX_WORDS = 8;
    /** Zeichen eines Auszugs. */
    private const EXCERPT_CHARS = 240;
    /** HKDF-Info des Index-Kennzeichens. */
    private const TAG_INFO = '365cms:community:index:v1';
    /** Erlaubte Filter. */
    private const FILTER_KEYS = ['forum_id', 'with_children', 'user_id', 'since', 'until', 'first_only', 'solved', 'sort'];
    /** Beiträge je Schritt der Themen-Expansion. */
    private const EXPAND_STEP = 500;
    /** Journalzeile. */
    private const JOURNAL_LINE = '/^[ptf]:[1-9][0-9]{0,18}$/';

    private static ?self $instance = null;
    private static bool $shutdownRegistered = false;
    /** @var array<int, self> Instanzen mit Vormerkungen (Schreiben bei Request-Ende) */
    private static array $queued = [];

    private Database $db;
    private Settings $settings;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision */
    private \Closure $can;
    /** @var \Closure(?int): list<int> */
    private \Closure $readable;
    /** @var \Closure(string, ?int): array{allowed: bool, retry_after: int} */
    private \Closure $throttle;
    /** @var \Closure(): ?string */
    private \Closure $indexDir;
    /** @var \Closure(string): string */
    private \Closure $plainText;
    /** @var \Closure(): object */
    private \Closure $jobs;
    private ?string $locale;
    /** @var \Closure(): mixed */
    private \Closure $secret;
    private PostRepository $posts;
    private SearchRepository $search;
    private ?ForumRepository $forums = null;

    /** @var array<string, true> Vorgemerkte Zeilen `p:<id>`, `t:<id>`, `f:<id>` */
    private array $pending = [];
    private bool $dirResolved = false;
    private ?string $dir = null;
    private bool $tagResolved = false;
    private ?string $tag = null;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard `CommunityAccess::instance()->can()`
     * @param \Closure(?int): list<int>|null $readable lesbare Foren (Standard `CommunityAccess::instance()->readableForumIds()`)
     * @param \Closure(string, ?int): array{allowed: bool, retry_after: int}|null $throttle Standard `ContentGuard::instance()->throttle()`
     * @param \Closure(): ?string|null $indexDir Ordner des Index (Standard `PrivateStorageService::path(AREA)`); null = kein Index
     * @param \Closure(string): string|null $plainText Klartext ohne Zitatblöcke (Standard `ContentRenderer::plainText()`)
     * @param \Closure(): object|null $jobs Standard `BatchJobService::getInstance()`
     * @param string|null $locale Sprache des Stemmers; null = `I18n::siteLocale()` bei der ersten Nutzung
     * @param \Closure(): mixed|null $secret Geheimnis für das Kennzeichen der Indexdateien (Standard wie `IpHash`, erst bei Nutzung)
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $clock = null,
        ?\Closure $can = null,
        ?\Closure $readable = null,
        ?\Closure $throttle = null,
        ?\Closure $indexDir = null,
        ?\Closure $plainText = null,
        ?\Closure $jobs = null,
        ?string $locale = null,
        ?\Closure $secret = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->can = $can ?? static function (?int $userId, string $action, array $context): AccessDecision {
            return class_exists(CommunityAccess::class)
                ? CommunityAccess::instance()->can($userId, $action, $context)
                : AccessDecision::deny('no_permission');
        };
        $this->readable = $readable ?? static fn (?int $userId): array => CommunityAccess::instance()->readableForumIds($userId);
        // Ohne ContentGuard (Einheit S) bleibt die Suche zu (fail-closed).
        $this->throttle = $throttle ?? static function (string $action, ?int $userId): array {
            return class_exists(ContentGuard::class)
                ? ContentGuard::instance()->throttle($action, $userId)
                : ['allowed' => false, 'retry_after' => 60];
        };
        $this->indexDir = $indexDir ?? static function (): ?string {
            try {
                return PrivateStorageService::getInstance()->path(self::AREA);
            } catch (\Throwable) {
                return null;
            }
        };
        $this->plainText = $plainText ?? static function (string $markdown): string {
            $renderer = ContentRenderer::instance();

            return $renderer->isAvailable() ? $renderer->plainText($markdown, false) : self::stripMarkdown($markdown);
        };
        $this->jobs = $jobs ?? static fn (): object => BatchJobService::getInstance();
        $this->locale = $locale;
        // Standard wie IpHash (Vertrag §3.6), gelesen erst bei der ersten Nutzung.
        $this->secret = $secret ?? static fn (): ?string => IpHash::defaultSecret();
        $this->posts = new PostRepository($this->db);
        $this->search = new SearchRepository($this->db);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Suche ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Suche über Beiträge. Filter: `forum_id` (int ≥ 1), `with_children` (bool, Standard true), `user_id` (int ≥ 1), `since`/`until`
     * (Unix ≥ 0, `since < until`), `first_only`, `solved` (bool), `sort` (`relevance`|`date`). Ergebnis
     * `{success: true, results: list<Hit>, total, page, pages, has_more, source: index|like|unavailable, truncated, terms}`;
     * `Hit` = `{topic_id, post_id, forum_id, title, slug, excerpt, score, created_at, is_first_post, solved}` (ein Treffer je Thema).
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function search(?int $userId, string $query, array $filters = [], int $page = 1, ?int $perPage = null): array
    {
        $userId = $userId !== null && $userId > 0 ? $userId : null;
        $filters = self::normalizeFilters($filters);
        $perPage ??= self::PER_PAGE;
        if ($filters === null || $perPage < 1 || $perPage > self::PER_PAGE_MAX || $page < 1 || $page > (int) ceil(self::MAX_TOPICS / $perPage)) {
            return self::failure('invalid_input');
        }
        $parsed = SiteSearchService::parseQuery($query);
        if ($parsed['terms'] === []) {
            return self::failure('query_too_short');
        }
        $nowTs = ($this->clock)();
        $decision = ($this->can)($userId, 'search', ['scope' => 'global', 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::failure($decision->reason, $decision->retryAfter !== null ? ['retry_after' => $decision->retryAfter] : []);
        }
        $gate = ($this->throttle)('search', $userId);
        if (!($gate['allowed'] ?? false)) {
            return self::failure('rate_limited', ['retry_after' => max(1, (int) ($gate['retry_after'] ?? 60))]);
        }
        try {
            $forums = ($this->readable)($userId);
            $forums = $this->restrictForums($forums, $filters);
            $sqlFilters = self::sqlFilters($filters);
            $found = $this->indexReady() ? $this->indexHits($parsed['words'], $forums, $sqlFilters) : null;
            $source = 'index';
            if ($found === null) {
                if ($userId === null) { // kein LIKE für Gäste
                    return self::result([], $page, $perPage, 'unavailable', false, $parsed['terms']);
                }
                $found = $this->likeHits($parsed, $forums, $sqlFilters);
                $source = 'like';
            }
            $remaining = $found['remaining'];
            $topics = self::groupTopics($found['hits'], $parsed['terms'], $filters['sort']);
            $truncated = count($topics) >= self::MAX_TOPICS && $remaining;
            $topics = array_slice($topics, 0, self::MAX_TOPICS);
            $start = ($page - 1) * $perPage;
            $slice = array_slice($topics, $start, $perPage);
            $bodies = $slice === [] ? [] : $this->search->bodies(array_map(static fn (array $hit): int => $hit['id'], $slice));
            $results = [];
            foreach ($slice as $hit) {
                $results[] = [
                    'topic_id' => $hit['topic_id'],
                    'post_id' => $hit['id'],
                    'forum_id' => $hit['forum_id'],
                    'title' => $hit['title'],
                    'slug' => $hit['slug'],
                    'excerpt' => self::excerpt($this->text((string) ($bodies[$hit['id']] ?? '')), $parsed['words']),
                    'score' => round((float) $hit['score'], 6),
                    'created_at' => $hit['created_at'],
                    'is_first_post' => $hit['is_first_post'] === 1,
                    'solved' => $hit['solution_post_id'] !== null,
                ];
            }

            return self::result($results, $page, $perPage, $source, $truncated, $parsed['terms'], count($topics));
        } catch (\Throwable $e) {
            self::warn('search', $e);

            return self::failure('db_error');
        }
    }

    /**
     * Ähnliche Themen zu einem Titel (ODER-Suche über den Index, nur erste Beiträge, lesbare Foren). Unter SIMILAR_MIN_WORDS Wörtern oder
     * ohne Index eine leere Liste; kein LIKE-Rückfall. `$limit` 1 bis 10.
     *
     * @return array<string, mixed>
     */
    public function similarTopics(?int $userId, string $title, int $limit = 5): array
    {
        $userId = $userId !== null && $userId > 0 ? $userId : null;
        if ($limit < 1 || $limit > 10) {
            return self::failure('invalid_input');
        }
        $words = array_slice(SiteSearchService::parseQuery($title)['words'], 0, self::MAX_WORDS);
        if (count($words) < self::SIMILAR_MIN_WORDS) {
            return ['success' => true, 'results' => []];
        }
        $nowTs = ($this->clock)();
        $decision = ($this->can)($userId, 'search', ['scope' => 'global', 'now' => $nowTs]);
        if (!$decision->allowed) {
            return self::failure($decision->reason, $decision->retryAfter !== null ? ['retry_after' => $decision->retryAfter] : []);
        }
        $similarGate = ($this->throttle)('search', $userId);
        if (!($similarGate['allowed'] ?? false)) {
            return self::failure('rate_limited', ['retry_after' => max(1, (int) ($similarGate['retry_after'] ?? 60))]);
        }
        try {
            $readable = ($this->readable)($userId);
            if ($readable === [] || !$this->indexReady()) {
                return ['success' => true, 'results' => []];
            }
            try {
                $tnt = $this->openIndex($this->file(self::INDEX_FILE), false);
                $scores = self::sortedScores((array) ($tnt->search(implode(' ', $words), 200)['docScores'] ?? []));
            } catch (\Throwable $e) {
                self::warn('similarTopics', $e);

                return ['success' => true, 'results' => []];
            }
            $results = [];
            foreach (array_chunk(array_keys($scores), SearchRepository::VERIFY_CHUNK) as $n => $chunk) {
                if ($n >= self::VERIFY_CHUNKS) {
                    break;
                }
                $rows = [];
                foreach ($this->search->verify($chunk, $readable, ['first_only' => true]) as $row) {
                    $rows[$row['id']] = $row;
                }
                foreach ($chunk as $id) {
                    if (isset($rows[$id]) && !isset($results[$rows[$id]['topic_id']])) {
                        $row = $rows[$id];
                        $results[$row['topic_id']] = ['topic_id' => $row['topic_id'], 'forum_id' => $row['forum_id'], 'title' => $row['title'], 'slug' => $row['slug'], 'score' => round((float) $scores[$id], 6)];
                        if (count($results) >= $limit) {
                            break 2;
                        }
                    }
                }
            }

            return ['success' => true, 'results' => array_values($results)];
        } catch (\Throwable $e) {
            self::warn('similarTopics', $e);

            return self::failure('db_error');
        }
    }

    // ── Indexpflege ─────────────────────────────────────────────────────────────────────────────────────────────

    /** Beiträge vormerken (Schreiben bei Request-Ende). */
    public function queuePosts(int ...$postIds): void
    {
        foreach ($postIds as $postId) {
            if ($postId > 0) {
                $this->pending['p:' . $postId] = true;
            }
        }
        $this->registerShutdown();
    }

    /** Thema vormerken: alle Beiträge, mit `$firstOnly` nur den ersten (Titeländerung). */
    public function queueTopic(int $topicId, bool $firstOnly = false): void
    {
        if ($topicId > 0) {
            $this->pending[($firstOnly ? 'f:' : 't:') . $topicId] = true;
        }
        $this->registerShutdown();
    }

    /**
     * Vormerkungen schreiben: ohne Index-Ordner oder Geheimnis verworfen (der Neuaufbau liest den Stand), bei belegter Sperre ins Journal,
     * sonst mit dem Journal zusammen auf den Datenbankstand gesetzt. Wirft nie.
     *
     * @return array{indexed: int, removed: int, journaled: int}
     */
    public function flushQueue(bool $wait = false): array
    {
        $pending = array_keys($this->pending);
        $this->pending = [];
        unset(self::$queued[spl_object_id($this)]);
        $result = ['indexed' => 0, 'removed' => 0, 'journaled' => 0];
        if (($pending === [] && !$wait) || !$this->available()) {
            return $result;
        }
        $lock = $this->acquireLock($wait);
        if ($lock === null) {
            $this->appendJournal($pending);
            $result['journaled'] = count($pending);

            return $result;
        }
        $all = $pending;
        try {
            $all = array_values(array_unique([...$this->readJournal(), ...$pending]));
            [$result['indexed'], $result['removed']] = $this->applyLines($all);
        } catch (\Throwable $e) {
            self::warn('flushQueue', $e);
            $this->appendJournal($all);
            $result['journaled'] = count($all);
        } finally {
            self::releaseLock($lock);
        }

        return $result;
    }

    /**
     * Beiträge sofort aus Live- und Aufbaudatei entfernen, ohne Datenbankprüfung (PR #5, DSGVO). `false` bei Fehler.
     *
     * @param array<array-key, mixed> $postIds
     */
    public function removePosts(array $postIds): bool
    {
        $ids = [];
        foreach ($postIds as $postId) {
            if (is_int($postId) && $postId > 0) {
                $ids[$postId] = $postId;
            }
        }
        if ($ids === [] || !$this->available()) {
            return true;
        }
        $lock = $this->acquireLock(true);
        if ($lock === null) {
            return false;
        }
        try {
            foreach ($this->targets() as $target) {
                $tnt = $this->openIndex($target, true);
                $pdo = $tnt->engine->index;
                $pdo->beginTransaction();
                try {
                    foreach ($ids as $id) {
                        $tnt->engine->delete($id);
                    }
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }

            return true;
        } catch (\Throwable $e) {
            self::warn('removePosts', $e);

            return false;
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * Stündlicher Abgleich (Cron): laufenden Neuaufbau fortsetzen; fehlt der Index, ist die Marke gesetzt oder passt die Sprache nicht,
     * Neuaufbau starten; sonst unter `index.lock` Verbundindex sicherstellen, Journal verarbeiten und neue Beiträge nachholen. Wirft nie.
     *
     * @return array{mode: string, processed: int, journal: int}
     */
    public function reconcile(): array
    {
        $result = ['mode' => 'none', 'processed' => 0, 'journal' => 0];
        try {
            if (!$this->available()) {
                return $result;
            }
            $jobs = ($this->jobs)();
            $job = $jobs->findActive(self::JOB_TYPE, self::JOB_SCOPE);
            $live = $this->file(self::INDEX_FILE);
            if (!is_array($job) && (!is_file($live) || is_file($this->path(self::STALE_FILE)) || self::readInfo($live, 'cms_locale') !== $this->locale())) {
                $started = $this->startRebuild(null);
                $job = !empty($started['success']) ? $jobs->find((int) ($started['job']['id'] ?? 0)) : null;
            }
            if (is_array($job)) {
                $result['mode'] = 'rebuild';
                $jobId = (int) $job['id'];
                $before = (int) ($job['processed'] ?? 0);
                for ($i = 0; $i < self::CRON_STEPS; $i++) {
                    $job = (array) $jobs->step($jobId, fn (array $job): array => $this->rebuildStep((array) ($job['state'] ?? [])));
                    if (!empty($job['busy']) || ($job['status'] ?? '') !== BatchJobService::STATUS_RUNNING) {
                        break;
                    }
                }
                $result['processed'] = max(0, (int) ($job['processed'] ?? 0) - $before);

                return $result;
            }
            $lock = $this->acquireLock(true);
            if ($lock === null) {
                return $result;
            }
            try {
                $result['mode'] = 'catchup';
                $tnt = $this->openIndex($live, true);
                $this->ensureHitsIndex($tnt->engine->index);
                $journal = $this->readJournal();
                $result['journal'] = count($journal);
                if ($journal !== []) {
                    try {
                        $this->applyLines($journal);
                    } catch (\Throwable $e) {
                        self::warn('reconcile', $e);
                        $this->appendJournal($journal);
                    }
                }
                $last = (int) (self::readInfo($live, 'cms_last_id') ?? '0');
                $max = $this->posts->maxId();
                while ($result['processed'] < self::CATCHUP_MAX && $last < $max) {
                    $until = min($last + self::REBUILD_WINDOW, $max);
                    $rows = $this->search->indexWindow($last, $until, self::INDEX_BATCH);
                    $this->writeTargets($rows);
                    $result['processed'] += count($rows);
                    $last = count($rows) >= self::INDEX_BATCH ? (int) $rows[count($rows) - 1]['id'] : $until;
                    self::writeInfo($tnt->engine->index, ['cms_last_id' => (string) $last]);
                }
            } finally {
                self::releaseLock($lock);
            }
        } catch (\Throwable $e) {
            self::warn('reconcile', $e);
        }

        return $result;
    }

    /**
     * Neuaufbau als BatchJob starten (läuft schon einer, wird er fortgesetzt). Ohne `pdo_sqlite`, Index-Ordner oder Geheimnis `invalid_input`.
     *
     * @return array<string, mixed>
     */
    public function startRebuild(?int $userId = null): array
    {
        if (!$this->available()) {
            return self::failure('invalid_input');
        }
        try {
            $jobs = ($this->jobs)();
            $max = $this->posts->maxId();
            $started = $jobs->start(self::JOB_TYPE, self::JOB_SCOPE, $max, ['after_id' => 0, 'max_id' => $max, 'created' => false], $userId !== null && $userId > 0 ? $userId : null);

            return ['success' => true, 'job' => $jobs->toPublic((array) $started['job']), 'resumed' => (bool) ($started['resumed'] ?? false)];
        } catch (\Throwable $e) {
            self::warn('startRebuild', $e);

            return self::failure('db_error');
        }
    }

    /**
     * Ein Schritt des Neuaufbaus über `BatchJobService::step()`; ein Job eines anderen Typs ergibt `invalid_input`.
     *
     * @return array<string, mixed>
     */
    public function runRebuildStep(int $jobId): array
    {
        if ($jobId < 1) {
            return self::failure('invalid_input');
        }
        try {
            $jobs = ($this->jobs)();
            $job = $jobs->find($jobId);
            if (!is_array($job) || ($job['type'] ?? null) !== self::JOB_TYPE) {
                return self::failure('invalid_input');
            }
            $after = $jobs->step($jobId, fn (array $job): array => $this->rebuildStep((array) ($job['state'] ?? [])));

            return ['success' => true, 'job' => $jobs->toPublic((array) $after)];
        } catch (\Throwable $e) {
            self::warn('runRebuildStep', $e);

            return self::failure('db_error');
        }
    }

    /**
     * Neuaufbau abbrechen (nur Typ JOB_TYPE); die Aufbaudatei wird entfernt.
     *
     * @return array<string, mixed>
     */
    public function cancelRebuild(int $jobId): array
    {
        if ($jobId < 1) {
            return self::failure('invalid_input');
        }
        try {
            $jobs = ($this->jobs)();
            $job = $jobs->find($jobId);
            if (!is_array($job) || ($job['type'] ?? null) !== self::JOB_TYPE) {
                return self::failure('invalid_input');
            }
            $cancelled = (bool) $jobs->cancel($jobId);
            if ($cancelled && $this->available()) {
                $lock = $this->acquireLock(true);
                if ($lock !== null) {
                    @unlink($this->file(self::BUILD_FILE));
                    self::releaseLock($lock);
                }
            }

            return ['success' => $cancelled];
        } catch (\Throwable $e) {
            self::warn('cancelRebuild', $e);

            return self::failure('db_error');
        }
    }

    /**
     * Reiner Arbeitsschritt des Neuaufbaus (für `BatchJobService::step`) unter `index.lock`: der erste Schritt legt die Aufbaudatei neu
     * an (mit Verbundindex und Sprache) und löscht Dateien mit fremdem Kennzeichen; je Schritt ein ID-Fenster; der letzte aktiviert die
     * Aufbaudatei per `rename()`. Wirft bei Fehlern (der Job wird `failed`).
     *
     * @param array<string, mixed> $state {after_id: int, max_id: int, created: bool}
     * @return array{processed: int, failed: int, state: array<string, mixed>, done: bool, message: string}
     */
    public function rebuildStep(array $state): array
    {
        $after = max(0, (int) ($state['after_id'] ?? 0));
        $max = max(0, (int) ($state['max_id'] ?? 0));
        $created = !empty($state['created']);
        if (!$this->available()) {
            throw new \RuntimeException('Suchindex nicht verfügbar.');
        }
        $lock = $this->acquireLock(true) ?? throw new \RuntimeException('index.lock nicht verfügbar.');
        try {
            $build = $this->file(self::BUILD_FILE);
            $start = $after;
            if (!$created || !is_file($build)) {
                $tag = (string) $this->tag();
                foreach ($this->staleIndexFiles($tag) as $stale) {
                    @unlink($stale);
                }
                $this->createIndexFile($build);
                $created = true;
                $after = 0;
                $start = 0;
            }
            $next = $after;
            if ($after < $max) {
                $until = min($after + self::REBUILD_WINDOW, $max);
                $rows = $this->search->indexWindow($after, $until, self::INDEX_BATCH);
                $this->writeRows($this->openIndex($build, true), $rows);
                $next = count($rows) >= self::INDEX_BATCH ? (int) $rows[count($rows) - 1]['id'] : $until;
            }
            $done = $next >= $max;
            if ($done) {
                $tnt = $this->openIndex($build, true);
                self::writeInfo($tnt->engine->index, ['cms_last_id' => (string) $max, 'cms_built_at' => gmdate('Y-m-d H:i:s', ($this->clock)())]);
                unset($tnt);
                if (!@rename($build, $this->file(self::INDEX_FILE))) {
                    throw new \RuntimeException('Suchindex konnte nicht aktiviert werden.');
                }
                @unlink($this->path(self::STALE_FILE));
            }
        } finally {
            self::releaseLock($lock);
        }

        return ['processed' => max(0, $next - $start), 'failed' => 0, 'state' => ['after_id' => $next, 'max_id' => $max, 'created' => $created], 'done' => $done, 'message' => ''];
    }

    /**
     * Zustand für die Verwaltung (PR #3): `available` (Neuaufbau möglich), `built` (Live-Index vorhanden), Dokumente, letzte ID des
     * Abgleichs, Marke, Sprache, Job (`toPublic`) und `outside_webroot` (privater Speicher außerhalb des Webroots; sonst warnt PR #3).
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $available = $this->available();
        $live = $available ? $this->file(self::INDEX_FILE) : null;
        $built = $live !== null && is_file($live);
        $job = null;
        try {
            $jobs = ($this->jobs)();
            $found = $jobs->findActive(self::JOB_TYPE, self::JOB_SCOPE) ?? $jobs->latest(self::JOB_TYPE, self::JOB_SCOPE);
            $job = is_array($found) ? $jobs->toPublic($found) : null;
        } catch (\Throwable $e) {
            self::warn('status', $e);
        }
        try {
            $outside = PrivateStorageService::getInstance()->isOutsideWebroot();
        } catch (\Throwable) {
            $outside = false;
        }

        return [
            'available' => $available,
            'built' => $built,
            'documents' => $built ? (int) (self::readInfo((string) $live, 'total_documents') ?? '0') : 0,
            'last_id' => $built ? (int) (self::readInfo((string) $live, 'cms_last_id') ?? '0') : 0,
            'stale' => $available && is_file($this->path(self::STALE_FILE)),
            'locale' => $this->locale(),
            'job' => $job,
            'outside_webroot' => $outside,
        ];
    }

    /** Zwischenspeicher verwerfen (Cron und Worker je Auftrag). */
    public function flush(): void
    {
        $this->forums = null;
        $this->dirResolved = false;
        $this->dir = null;
    }

    // ── Sitemap ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Sitemap-Einträge in Gastsicht: Startseite, Foren mit Themen (ohne `link`) und veröffentlichte Themen nach Aktivität, wurzelrelative
     * DE-Adressen, höchstens SITEMAP_MAX_ENTRIES. Wirft nie (Fehler ⇒ `[]` und Warnung).
     *
     * @return list<array{url: string, lastmod: string, changefreq: string, priority: float}>
     */
    public function sitemapEntries(): array
    {
        try {
            $forums = ($this->readable)(null);
            if ($forums === []) {
                return [];
            }
            $readable = array_flip(array_map('intval', $forums));
            $forumEntries = [];
            $ids = [];
            $latest = null;
            foreach ($this->forums()->tree() as $id => $forum) {
                if (!isset($readable[(int) $id])) {
                    continue;
                }
                if ((string) $forum['type'] === 'link' || (int) $forum['topic_count'] < 1) {
                    continue;
                }
                $activity = $forum['last_post_at'] ?? null;
                $lastmod = self::w3c(is_string($activity) ? $activity : null) ?? self::w3c(is_string($forum['updated_at'] ?? null) ? $forum['updated_at'] : null) ?? '';
                if (is_string($activity) && ($latest === null || strcmp($activity, $latest) > 0)) {
                    $latest = $activity;
                }
                $forumEntries[] = [
                    'url' => Urls::forum(Urls::forumSlugFor((string) $forum['slug'], is_string($forum['slug_en'] ?? null) ? $forum['slug_en'] : null, 'de')),
                    'lastmod' => $lastmod,
                    'changefreq' => 'daily',
                    'priority' => 0.6,
                ];
                $ids[] = (int) $id;
            }
            if ($ids === []) {
                return [];
            }
            $entries = [['url' => Urls::index(), 'lastmod' => self::w3c($latest) ?? '', 'changefreq' => 'daily', 'priority' => 0.6]];
            foreach (array_slice($forumEntries, 0, self::SITEMAP_MAX_ENTRIES - 1) as $entry) {
                $entries[] = $entry;
            }
            foreach ($this->search->sitemapTopics($ids, self::SITEMAP_MAX_ENTRIES - count($entries)) as $topic) {
                $entries[] = ['url' => Urls::topic($topic['id'], $topic['slug'], 'de'), 'lastmod' => self::w3c($topic['last_post_at']) ?? '', 'changefreq' => 'weekly', 'priority' => 0.5];
            }

            return $entries;
        } catch (\Throwable $e) {
            self::warn('sitemapEntries', $e);

            return [];
        }
    }

    /** `Y-m-d H:i:s` (UTC) → `Y-m-d\TH:i:s+00:00`, sonst `null` (reine Textumformung, keine Zeitfunktion). */
    public static function w3c(?string $utc): ?string
    {
        if ($utc === null || preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $utc, $match) !== 1) {
            return null;
        }

        return $match[1] . 'T' . $match[2] . '+00:00';
    }

    // ── Hilfen: Suche ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Kandidaten aus dem Index (UND über die Wörter), in Stücken nachgeprüft, bis MAX_TOPICS Themen beisammen sind. `null`, wenn der
     * Index nicht lesbar ist (LIKE-Rückfall).
     *
     * @param list<string> $words
     * @param list<int> $forums
     * @param array<string, mixed> $sqlFilters
     * @return array{hits: list<array<string, mixed>>, remaining: bool}|null
     */
    private function indexHits(array $words, array $forums, array $sqlFilters): ?array
    {
        try {
            $tnt = $this->openIndex($this->file(self::INDEX_FILE), false);
            $scores = null;
            foreach (array_slice($words, 0, self::MAX_WORDS) as $word) {
                if ($tnt->breakIntoTokens((string) $word) === []) {
                    continue;
                }
                $wordScores = (array) ($tnt->search((string) $word, PHP_INT_MAX)['docScores'] ?? []);
                $next = [];
                foreach ($scores === null ? $wordScores : $scores as $id => $score) {
                    if ($scores === null) {
                        $next[(int) $id] = (float) $score;
                    } elseif (isset($wordScores[$id])) {
                        $next[(int) $id] = $score + (float) $wordScores[$id];
                    }
                }
                $scores = $next;
                if ($scores === []) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            self::warn('search', $e);

            return null;
        }
        $scores = self::sortedScores($scores ?? []);
        if ($forums === [] || $scores === []) {
            return ['hits' => [], 'remaining' => false];
        }
        $hits = [];
        $topics = [];
        $remaining = false;
        $chunks = array_chunk(array_keys($scores), SearchRepository::VERIFY_CHUNK);
        foreach ($chunks as $n => $chunk) {
            if ($n >= self::VERIFY_CHUNKS) {
                $remaining = true;
                break;
            }
            $rows = [];
            foreach ($this->search->verify($chunk, $forums, $sqlFilters) as $row) {
                $rows[$row['id']] = $row;
            }
            foreach ($chunk as $id) {
                if (!isset($rows[$id])) {
                    continue;
                }
                $topicId = $rows[$id]['topic_id'];
                if (!isset($topics[$topicId]) && count($topics) >= self::MAX_TOPICS) {
                    $remaining = true;
                    continue;
                }
                $topics[$topicId] = true;
                $hits[] = $rows[$id] + ['score' => $scores[$id]];
            }
            if (count($topics) >= self::MAX_TOPICS) {
                $remaining = $remaining || $n < count($chunks) - 1;
                break;
            }
        }

        return ['hits' => $hits, 'remaining' => $remaining];
    }

    /**
     * LIKE-Rückfall für Mitglieder: die jüngsten LIKE_WINDOW Beiträge in Stücken zu LIKE_CHUNK IDs (absteigend, bis LIKE_LIMIT Zeilen),
     * danach Grundformprüfung in PHP auf dem Klartext.
     *
     * @param array{query: string, terms: list<string>, words: list<string>} $parsed
     * @param list<int> $forums
     * @param array<string, mixed> $sqlFilters
     * @return array{hits: list<array<string, mixed>>, remaining: bool}
     */
    private function likeHits(array $parsed, array $forums, array $sqlFilters): array
    {
        if ($forums === []) {
            return ['hits' => [], 'remaining' => false];
        }
        $patterns = array_map(SearchRepository::likePattern(...), array_slice($parsed['words'], 0, self::MAX_WORDS));
        $max = $this->posts->maxId();
        $min = max(0, $max - self::LIKE_WINDOW);
        $rows = [];
        for ($upper = $max; $upper > $min && count($rows) < self::LIKE_LIMIT; $upper -= self::LIKE_CHUNK) {
            $lower = max($min, $upper - self::LIKE_CHUNK);
            array_push($rows, ...$this->search->likeCandidates($patterns, $forums, $sqlFilters, $lower, self::LIKE_LIMIT - count($rows), $upper));
        }
        $locale = $this->locale();
        $hits = [];
        foreach ($rows as $row) {
            $text = $this->text((string) $row['body']) . ($row['is_first_post'] === 1 ? ' ' . $row['title'] : '');
            if (SiteSearchService::matchingTerms(SiteSearchService::fold($text), $parsed['terms'], $locale) === null) {
                continue;
            }
            unset($row['body']);
            $hits[] = $row + ['score' => 1.0];
        }

        return ['hits' => $hits, 'remaining' => count($rows) >= self::LIKE_LIMIT];
    }

    /**
     * Ein Treffer je Thema (bester Beitrag), Titelbonus, Sortierung nach Relevanz oder Datum.
     *
     * @param list<array<string, mixed>> $hits
     * @param list<string> $terms
     * @return list<array<string, mixed>>
     */
    private static function groupTopics(array $hits, array $terms, string $sort): array
    {
        $best = [];
        foreach ($hits as $hit) {
            if ($hit['is_first_post'] === 1 && SiteSearchService::matchesAll(SiteSearchService::fold((string) $hit['title']), $terms)) {
                $hit['score'] = (float) $hit['score'] + self::TITLE_BONUS;
            }
            $topicId = $hit['topic_id'];
            if (!isset($best[$topicId]) || self::compareRelevance($hit, $best[$topicId]) < 0) {
                $best[$topicId] = $hit;
            }
        }
        $topics = array_values($best);
        usort($topics, $sort === 'date' ? self::compareDate(...) : self::compareRelevance(...));

        return $topics;
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private static function compareRelevance(array $a, array $b): int
    {
        return [(float) $b['score'], (string) $b['created_at'], (int) $b['id']] <=> [(float) $a['score'], (string) $a['created_at'], (int) $a['id']];
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private static function compareDate(array $a, array $b): int
    {
        return [(string) $b['created_at'], (int) $b['id']] <=> [(string) $a['created_at'], (int) $a['id']];
    }

    /**
     * Punktzahlen absteigend, bei Gleichstand die größere ID zuerst.
     *
     * @param array<int|string, mixed> $scores
     * @return array<int, float>
     */
    private static function sortedScores(array $scores): array
    {
        $list = [];
        foreach ($scores as $id => $score) {
            if ((int) $id > 0) {
                $list[(int) $id] = (float) $score;
            }
        }
        uksort($list, static fn (int $a, int $b): int => [$list[$b], $b] <=> [$list[$a], $a]);

        return $list;
    }

    /**
     * Foren der Suche: lesbare, mit `forum_id` geschnitten mit dem Forum (und bei `with_children` seinen Unterforen).
     *
     * @param list<int> $readable
     * @param array<string, mixed> $filters
     * @return list<int>
     */
    private function restrictForums(array $readable, array $filters): array
    {
        $readable = array_values(array_unique(array_map('intval', $readable)));
        if (!isset($filters['forum_id'])) {
            return $readable;
        }
        $forumId = (int) $filters['forum_id'];
        $allowed = $filters['with_children'] ? [$forumId, ...$this->forums()->descendantIds($forumId)] : [$forumId];

        return array_values(array_intersect($readable, $allowed));
    }

    /**
     * Filter prüfen und mit Standards füllen; `null` = ungültig.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    private static function normalizeFilters(array $filters): ?array
    {
        if (array_diff(array_map('strval', array_keys($filters)), self::FILTER_KEYS) !== []) {
            return null;
        }
        $normal = ['with_children' => true, 'first_only' => false, 'solved' => false, 'sort' => 'relevance'];
        foreach (['forum_id', 'user_id'] as $key) {
            if (array_key_exists($key, $filters)) {
                if (!is_int($filters[$key]) || $filters[$key] < 1) {
                    return null;
                }
                $normal[$key] = $filters[$key];
            }
        }
        foreach (['since', 'until'] as $key) {
            if (array_key_exists($key, $filters)) {
                if (!is_int($filters[$key]) || $filters[$key] < 0) {
                    return null;
                }
                $normal[$key] = $filters[$key];
            }
        }
        if (isset($normal['since'], $normal['until']) && $normal['since'] >= $normal['until']) {
            return null;
        }
        foreach (['with_children', 'first_only', 'solved'] as $key) {
            if (array_key_exists($key, $filters)) {
                if (!is_bool($filters[$key])) {
                    return null;
                }
                $normal[$key] = $filters[$key];
            }
        }
        if (array_key_exists('sort', $filters)) {
            if (!in_array($filters['sort'], ['relevance', 'date'], true)) {
                return null;
            }
            $normal['sort'] = $filters['sort'];
        }

        return $normal;
    }

    /**
     * Filter für `SearchRepository` (Zeiten als UTC-Text).
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function sqlFilters(array $filters): array
    {
        $sql = [];
        if (isset($filters['user_id'])) {
            $sql['user_id'] = $filters['user_id'];
        }
        if (isset($filters['since'])) {
            $sql['since'] = gmdate('Y-m-d H:i:s', $filters['since']);
        }
        if (isset($filters['until'])) {
            $sql['until'] = gmdate('Y-m-d H:i:s', $filters['until']);
        }
        if ($filters['first_only']) {
            $sql['first_only'] = true;
        }
        if ($filters['solved']) {
            $sql['solved'] = true;
        }

        return $sql;
    }

    /**
     * Auszug um das erste gefundene Wort, höchstens EXCERPT_CHARS Zeichen samt Auslassungszeichen.
     *
     * @param list<string> $words
     */
    private static function excerpt(string $text, array $words): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $length = mb_strlen($text, 'UTF-8');
        if ($length <= self::EXCERPT_CHARS) {
            return $text;
        }
        $position = null;
        foreach ($words as $word) {
            $found = mb_stripos($text, (string) $word, 0, 'UTF-8');
            if ($found !== false && ($position === null || $found < $position)) {
                $position = $found;
            }
        }
        $start = $position === null ? 0 : max(0, $position - 60);
        if ($start > 0 && $length - $start < self::EXCERPT_CHARS - 1) {
            $start = $length - (self::EXCERPT_CHARS - 1);
        }
        $prefix = $start > 0 ? '…' : '';
        $room = self::EXCERPT_CHARS - mb_strlen($prefix, 'UTF-8');
        $suffix = $start + $room < $length ? '…' : '';

        return $prefix . mb_substr($text, $start, $room - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param list<string> $terms
     * @return array<string, mixed>
     */
    private static function result(array $results, int $page, int $perPage, string $source, bool $truncated, array $terms, int $total = 0): array
    {
        $pages = max(1, (int) ceil($total / $perPage));

        return [
            'success' => true,
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'has_more' => $page < $pages,
            'source' => $source,
            'truncated' => $truncated,
            'terms' => $terms,
        ];
    }

    /** Klartext eines Beitrags für Index, LIKE-Prüfung und Auszug ('' bei Fehler). */
    private function text(string $markdown): string
    {
        try {
            return (string) ($this->plainText)($markdown);
        } catch (\Throwable) {
            return '';
        }
    }

    /** Einfacher Klartext ohne CommonMark-Bündel: Zitatzeilen entfallen, Markdown-Zeichen werden Leerraum. */
    private static function stripMarkdown(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $kept = array_filter($lines, static fn (string $line): bool => !str_starts_with(ltrim($line), '>'));

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[*_~`#>\[\]()!|]+/u', ' ', implode("\n", $kept))));
    }

    // ── Hilfen: Index ───────────────────────────────────────────────────────────────────────────────────────────

    /** Index technisch möglich: `pdo_sqlite`, TNTSearch, Ordner und Geheimnis. */
    private function available(): bool
    {
        return extension_loaded('pdo_sqlite') && class_exists(TNTSearch::class) && $this->directory() !== null && $this->tag() !== null;
    }

    /** Live-Index vorhanden. */
    private function indexReady(): bool
    {
        return $this->available() && is_file($this->file(self::INDEX_FILE));
    }

    private function directory(): ?string
    {
        if (!$this->dirResolved) {
            $this->dirResolved = true;
            try {
                $dir = ($this->indexDir)();
            } catch (\Throwable) {
                $dir = null;
            }
            $this->dir = is_string($dir) && $dir !== '' && is_dir($dir) ? rtrim($dir, '/\\') . '/' : null;
        }

        return $this->dir;
    }

    /** Kennzeichen der Dateinamen aus dem Geheimnis (`null` ohne brauchbares Geheimnis). */
    private function tag(): ?string
    {
        if (!$this->tagResolved) {
            $this->tagResolved = true;
            try {
                $secret = ($this->secret)();
            } catch (\Throwable) {
                $secret = null;
            }
            $this->tag = IpHash::usableSecret($secret) ? self::tagFor(hash_hkdf('sha256', (string) $secret, 32, self::TAG_INFO)) : null;
        }

        return $this->tag;
    }

    private static function tagFor(string $key): string
    {
        return substr(hash_hmac('sha256', 'community-index', $key), 0, 16);
    }

    /** Pfad einer Datei mit Kennzeichen (`INDEX_FILE`, `BUILD_FILE`, `JOURNAL_FILE`). */
    private function file(string $pattern): string
    {
        return $this->directory() . sprintf($pattern, (string) $this->tag());
    }

    /** Pfad einer festen Datei (`LOCK_FILE`, `STALE_FILE`). */
    private function path(string $name): string
    {
        return $this->directory() . $name;
    }

    private function locale(): string
    {
        return $this->locale ??= I18n::siteLocale();
    }

    /**
     * Vorhandene Ziele eines Schreibwegs: Live- und Aufbaudatei.
     *
     * @return list<string>
     */
    private function targets(): array
    {
        return array_values(array_filter([$this->file(self::INDEX_FILE), $this->file(self::BUILD_FILE)], 'is_file'));
    }

    /**
     * Indexdateien und Journale mit fremdem Kennzeichen (Schlüsselwechsel).
     *
     * @return list<string>
     */
    private function staleIndexFiles(string $tag): array
    {
        $stale = [];
        foreach (@scandir((string) $this->directory()) ?: [] as $entry) {
            if (preg_match('/^(?:posts-([a-f0-9]{16})(?:\.building)?\.index|pending-([a-f0-9]{16})\.log)$/', (string) $entry, $match) === 1
                && ($match[1] !== '' ? $match[1] : ($match[2] ?? '')) !== $tag) {
                $stale[] = $this->directory() . $entry;
            }
        }

        return $stale;
    }

    private function tnt(): TNTSearch
    {
        $tnt = new TNTSearch();
        $tnt->loadConfig([
            'driver' => 'filesystem',
            'storage' => (string) $this->directory(),
            'stemmer' => SearchService::stemmerForLocale($this->locale()),
            'wal' => false,
        ]);

        return $tnt;
    }

    /** Öffnet eine vorhandene Indexdatei (Live oder Aufbau); zum Schreiben mit Stemmer und Tokenizer der Datei, ohne Term-Cache. */
    private function openIndex(string $file, bool $forWriting): TNTSearch
    {
        $tnt = $this->tnt();
        $tnt->selectIndex(basename($file));
        $tnt->engine->index->setAttribute(\PDO::ATTR_TIMEOUT, self::BUSY_TIMEOUT);
        $tnt->engine->maxDocs = self::MAX_DOCS_PER_WORD;
        if ($forWriting) {
            $engine = $tnt->engine;
            if ($tnt->stemmer !== null) {
                $engine->stemmer = $tnt->stemmer;
            }
            if ($tnt->tokenizer !== null) {
                $engine->tokenizer = $tnt->tokenizer;
            }
            $engine->inMemory = false;
        }

        return $tnt;
    }

    /** Legt eine leere Indexdatei an (vorhandene wird ersetzt), mit Verbundindex und Sprache. */
    private function createIndexFile(string $file): void
    {
        $tnt = $this->tnt();
        $tnt->createIndex(basename($file), true);
        $pdo = $tnt->engine->index;
        $this->ensureHitsIndex($pdo);
        self::writeInfo($pdo, ['cms_locale' => $this->locale(), 'cms_last_id' => '0', 'cms_built_at' => '']);
    }

    private function ensureHitsIndex(\PDO $pdo): void
    {
        $pdo->exec(self::HITS_INDEX_SQL);
    }

    /**
     * Vormerkungen anwenden (unter `index.lock`): Themen expandieren, Datenbankstand lesen, in alle Ziele schreiben.
     *
     * @param list<string> $lines `p:<id>`, `t:<id>`, `f:<id>`
     * @return array{0: int, 1: int} indexiert, entfernt
     */
    private function applyLines(array $lines): array
    {
        if ($this->targets() === []) {
            return [0, 0];
        }
        $postIds = [];
        foreach ($lines as $line) {
            [$kind, $id] = [$line[0], (int) substr($line, 2)];
            if ($kind === 'p') {
                $postIds[$id] = $id;
            } elseif ($kind === 'f') {
                $first = $this->search->firstPostId($id);
                if ($first !== null && $first > 0) {
                    $postIds[$first] = $first;
                }
            } else {
                $after = 0;
                $count = 0;
                while (true) {
                    $chunk = $this->search->topicPostIds($id, $after, self::EXPAND_STEP);
                    foreach ($chunk as $postId) {
                        $postIds[$postId] = $postId;
                    }
                    $count += count($chunk);
                    if (count($chunk) < self::EXPAND_STEP) {
                        break;
                    }
                    if ($count >= self::TOPIC_EXPAND_MAX) {
                        $this->markStale('Thema ' . $id . ' hat mehr als ' . self::TOPIC_EXPAND_MAX . ' Beiträge');
                        break;
                    }
                    $after = (int) $chunk[count($chunk) - 1];
                }
            }
        }
        if ($postIds === []) {
            return [0, 0];
        }
        $rows = $this->search->indexRows(array_values($postIds));
        $found = [];
        foreach ($rows as $row) {
            $found[$row['id']] = true;
        }
        foreach ($postIds as $id) {
            if (!isset($found[$id])) {
                $rows[] = ['id' => $id, 'topic_id' => 0, 'is_first_post' => 0, 'status' => 'deleted', 'body' => '', 'topic_status' => null, 'title' => null];
            }
        }
        return $this->writeTargets($rows);
    }

    /**
     * Dieselben Zeilen in alle vorhandenen Ziele schreiben (Live- und Aufbaudatei, gleiche Sperre).
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: int, 1: int} indexiert, entfernt (letztes Ziel)
     */
    private function writeTargets(array $rows): array
    {
        $counts = [0, 0];
        foreach ($this->targets() as $file) {
            $counts = $this->writeRows($this->openIndex($file, true), $rows);
        }

        return $counts;
    }

    /**
     * Schreibt Zeilen auf den Datenbankstand: passende ersetzen, übrige entfernen; je INDEX_BATCH Dokumente eine Transaktion. Gelöscht
     * wird nur ein vorhandenes Dokument (`doc_id_index`): TNT durchsucht beim Löschen die ganze Wortliste, ein frischer Aufbau spart das.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: int, 1: int} indexiert, entfernt
     */
    private function writeRows(TNTSearch $tnt, array $rows): array
    {
        $engine = $tnt->engine;
        $pdo = $engine->index;
        $present = $pdo->prepare('SELECT 1 FROM doclist WHERE doc_id = ? LIMIT 1');
        $indexed = 0;
        $removed = 0;
        foreach (array_chunk($rows, self::INDEX_BATCH) as $batch) {
            $pdo->beginTransaction();
            try {
                foreach ($batch as $row) {
                    $id = (int) $row['id'];
                    $present->execute([$id]);
                    $exists = $present->fetchColumn() !== false;
                    $present->closeCursor();
                    if ($exists) {
                        $engine->delete($id);
                    }
                    if ($row['status'] === 'published' && $row['topic_status'] === 'published') {
                        $engine->insert([
                            'id' => $id,
                            'title' => (int) $row['is_first_post'] === 1 ? (string) $row['title'] : '',
                            'content' => $this->text((string) $row['body']),
                        ]);
                        $indexed++;
                    } else {
                        $removed++;
                    }
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        return [$indexed, $removed];
    }

    /** `index.lock` exklusiv: blockierend (`$wait`) oder LOCK_TRIES Versuche zu 100 ms; `null` = belegt bzw. nicht anlegbar. */
    private function acquireLock(bool $wait): mixed
    {
        $handle = @fopen($this->path(self::LOCK_FILE), 'c');
        if ($handle === false) {
            return null;
        }
        if ($wait) {
            if (flock($handle, LOCK_EX)) {
                return $handle;
            }
        } else {
            for ($try = 0; $try < self::LOCK_TRIES; $try++) {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    return $handle;
                }
                usleep(100000);
            }
        }
        fclose($handle);

        return null;
    }

    private static function releaseLock(mixed $handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Zeilen ans Journal hängen (`FILE_APPEND | LOCK_EX`); würde es JOURNAL_MAX_BYTES überschreiten, wird es geleert und die Marke gesetzt.
     *
     * @param list<string> $lines
     */
    private function appendJournal(array $lines): void
    {
        if ($lines === [] || !$this->available()) {
            return;
        }
        $journal = $this->file(self::JOURNAL_FILE);
        $text = implode("\n", $lines) . "\n";
        clearstatcache(true, $journal);
        if ((is_file($journal) ? (int) filesize($journal) : 0) + strlen($text) > self::JOURNAL_MAX_BYTES) {
            @file_put_contents($journal, '', LOCK_EX);
            $this->markStale('Journal über ' . self::JOURNAL_MAX_BYTES . ' Byte');

            return;
        }
        if (@file_put_contents($journal, $text, FILE_APPEND | LOCK_EX) === false) {
            self::notice('appendJournal', 'Journal nicht beschreibbar: ' . $journal);
        }
    }

    /**
     * Journal lesen und leeren (unter der Sperre der Journaldatei, Lesen und Kürzen zusammen).
     *
     * @return list<string>
     */
    private function readJournal(): array
    {
        $journal = $this->file(self::JOURNAL_FILE);
        if (!is_file($journal)) {
            return [];
        }
        $handle = @fopen($journal, 'c+');
        if ($handle === false) {
            return [];
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return [];
            }
            $content = stream_get_contents($handle);
            ftruncate($handle, 0);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        $lines = [];
        foreach (preg_split('/\R/', (string) $content) ?: [] as $line) {
            $line = trim($line);
            if (preg_match(self::JOURNAL_LINE, $line) === 1) {
                $lines[$line] = $line;
            }
        }

        return array_values($lines);
    }

    private function markStale(string $reason): void
    {
        @file_put_contents($this->path(self::STALE_FILE), '', LOCK_EX);
        self::notice('index', 'Neuaufbau empfohlen: ' . $reason);
    }

    private function forums(): ForumRepository
    {
        return $this->forums ??= new ForumRepository($this->db);
    }

    /** Einmal je Prozess: Vormerkungen bei Request-Ende schreiben (nach dem Senden der Antwort, wo möglich). */
    private function registerShutdown(): void
    {
        self::$queued[spl_object_id($this)] = $this;
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            if (self::$queued === []) {
                return;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            foreach (self::$queued as $search) {
                $search->flushQueue(false);
            }
        });
    }

    /** @param array<string, string> $values */
    private static function writeInfo(\PDO $pdo, array $values): void
    {
        $delete = $pdo->prepare('DELETE FROM info WHERE key = ?');
        $insert = $pdo->prepare('INSERT INTO info (key, value) VALUES (?, ?)');
        foreach ($values as $key => $value) {
            $delete->execute([$key]);
            $insert->execute([$key, $value]);
        }
    }

    /** Wert aus der Tabelle `info` einer vorhandenen Indexdatei (`null`, wenn er fehlt oder die Datei nicht lesbar ist). */
    private static function readInfo(string $file, string $key): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        try {
            $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => self::BUSY_TIMEOUT]);
            $stmt = $pdo->prepare('SELECT value FROM info WHERE key = ? LIMIT 1');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();

            return $value === false || $value === null ? null : (string) $value;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function failure(string $error, array $extra = []): array
    {
        return ['success' => false, 'error' => $error] + $extra;
    }

    private static function warn(string $operation, \Throwable $e): void
    {
        Logger::instance()->withChannel('community')->warning('Community-Suche: {operation} fehlgeschlagen: {exception}', [
            'operation' => $operation,
            'exception' => $e,
        ]);
    }

    private static function notice(string $operation, string $message): void
    {
        Logger::instance()->withChannel('community')->warning('Community-Suche: {operation}: {message}', [
            'operation' => $operation,
            'message' => $message,
        ]);
    }
}
