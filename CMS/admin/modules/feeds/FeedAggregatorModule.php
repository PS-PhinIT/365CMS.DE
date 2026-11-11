<?php
declare(strict_types=1);

/**
 * Verwaltung des Feed-Aggregators unter Einstellungen → Feeds (ehemals Plugin cms-feed, `/admin/feeds`).
 *
 * Unterseiten (`section`): overview, sources, items, categories, digests, subscriptions, log, import, settings.
 * Der RSS-Ausgang (`section=rss`) bleibt im Tab-Controller. Alle Schreibaktionen laufen per POST mit CSRF-Token
 * des Tabs; der Aufrufer hat `manage_feeds` bereits geprüft.
 */

use CMS\AuditLogger;
use CMS\Services\Feed\AggregatorFrontendController;
use CMS\Services\Feed\AggregatorRepository;
use CMS\Services\Feed\AggregatorSettings;
use CMS\Services\Feed\DigestMailer;
use CMS\Services\Feed\FeedCatalog;
use CMS\Services\Feed\FeedI18n;
use CMS\Services\Feed\FetchQueue;
use CMS\Services\Feed\LegacyNormalizer;
use CMS\Services\Feed\OpmlService;
use CMS\Services\Feed\SourceFetcher;
use CMS\Services\FeedService;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedAggregatorModule
{
    public const SECTIONS = ['overview', 'sources', 'items', 'categories', 'digests', 'subscriptions', 'log', 'import', 'settings', 'rss'];
    public const SETTINGS_TABS = ['general', 'design', 'digest'];
    private const ITEMS_PER_PAGE = 50;
    private const LOG_PER_PAGE = 50;
    private const BULK_LIMIT = 200;

    private AggregatorRepository $repo;

    public function __construct()
    {
        $this->repo = AggregatorRepository::getInstance();
    }

    public function isReady(): bool
    {
        return $this->repo->isReady();
    }

    /** @param array<string, scalar|null> $query */
    public static function url(array $query = []): string
    {
        $query = array_filter(['tab' => 'feeds'] + $query, static fn ($value): bool => $value !== null && $value !== '' && $value !== 0);

        return '/admin/settings?' . http_build_query($query);
    }

    public static function t(string $key, string|int ...$args): string
    {
        return FeedI18n::admin('feeds.admin.' . $key, ...$args);
    }

    // ── Daten der Unterseiten ───────────────────────────────────────────────

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function data(string $section, array $query): array
    {
        $base = [
            'enabled' => AggregatorSettings::isEnabled(),
            'archive_url' => AggregatorFrontendController::archivePath('de'),
            'error_count' => (int) ($this->repo->stats()['sources_errors'] ?? 0),
        ];

        return $base + match ($section) {
            'sources' => $this->sourcesData($query),
            'items' => $this->itemsData($query),
            'categories' => $this->categoriesData($query),
            'digests' => $this->digestsData($query),
            'subscriptions' => $this->subscriptionsData($query),
            'log' => $this->logData($query),
            'import' => ['catalog' => FeedCatalog::all(), 'categories' => $this->repo->categories()],
            'settings' => [
                'settings' => AggregatorSettings::all(),
                'settings_tab' => in_array($query['stab'] ?? '', self::SETTINGS_TABS, true) ? (string) $query['stab'] : 'general',
            ],
            default => [
                'stats' => $this->repo->stats(),
                'health' => $this->repo->health(),
                'queue' => $this->repo->queueStats(),
                'attention' => $this->repo->attentionSources(6),
                'latest' => $this->repo->items(['status' => 'visible'], 0, 8),
            ],
        };
    }

    /** @param array<string, mixed> $query */
    private function sourcesData(array $query): array
    {
        $categories = $this->repo->categories();
        $editId = self::int($query['edit'] ?? 0);
        $source = $editId > 0 ? $this->repo->source($editId) : null;
        $filters = [
            'category_id' => self::int($query['cat'] ?? 0),
            'status' => in_array($query['status'] ?? '', ['active', 'inactive', 'error'], true) ? (string) $query['status'] : '',
            'search' => self::text($query['q'] ?? '', 120),
        ];

        return [
            'categories' => $categories,
            'filters' => $filters,
            'sources' => $this->repo->sources($filters),
            'source' => $source,
            'form' => $source !== null || !empty($query['new']),
        ];
    }

    /** @param array<string, mixed> $query */
    private function itemsData(array $query): array
    {
        $filters = [
            'category_id' => self::int($query['cat'] ?? 0),
            'source_id' => self::int($query['source'] ?? 0),
            'status' => in_array($query['status'] ?? '', ['visible', 'hidden', 'featured'], true) ? (string) $query['status'] : '',
            'search' => self::text($query['q'] ?? '', 120),
        ];
        $total = $this->repo->countItems($filters);
        $pages = max(1, (int) ceil($total / self::ITEMS_PER_PAGE));
        $page = max(1, min($pages, self::int($query['page'] ?? 1)));

        return [
            'categories' => $this->repo->categories(),
            'sources' => $this->repo->sources(),
            'filters' => $filters,
            'items' => $total > 0 ? $this->repo->items($filters, ($page - 1) * self::ITEMS_PER_PAGE, self::ITEMS_PER_PAGE) : [],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /** @param array<string, mixed> $query */
    private function categoriesData(array $query): array
    {
        $editId = self::int($query['edit'] ?? 0);
        $category = $editId > 0 ? $this->repo->category($editId) : null;

        return [
            'categories' => $this->repo->categories(),
            'category' => $category,
            'form' => $category !== null || !empty($query['new']),
        ];
    }

    /** @param array<string, mixed> $query */
    private function digestsData(array $query): array
    {
        $editId = self::int($query['edit'] ?? 0);
        $digest = $editId > 0 ? $this->repo->digest($editId) : null;

        return [
            'digests' => $this->repo->digests(),
            'digest' => $digest,
            'categories' => $this->repo->categories(),
            'form' => $digest !== null || !empty($query['new']),
        ];
    }

    /** @param array<string, mixed> $query */
    private function subscriptionsData(array $query): array
    {
        $sourceNames = [];
        foreach ($this->repo->sources() as $source) {
            $sourceNames[(int) $source['id']] = (string) $source['name'];
        }

        return [
            'subscriptions' => $this->repo->subscriptions(0, 200),
            'total' => $this->repo->countSubscriptions(),
            'active' => $this->repo->countSubscriptions(true),
            'source_names' => $sourceNames,
        ];
    }

    /** @param array<string, mixed> $query */
    private function logData(array $query): array
    {
        $filters = [
            'status' => in_array($query['status'] ?? '', ['pending', 'processing', 'done', 'failed'], true) ? (string) $query['status'] : '',
            'source_id' => self::int($query['source'] ?? 0),
        ];
        $total = $this->repo->countQueueLog($filters);
        $pages = max(1, (int) ceil($total / self::LOG_PER_PAGE));
        $page = max(1, min($pages, self::int($query['page'] ?? 1)));

        return [
            'filters' => $filters,
            'queue' => $this->repo->queueStats(),
            'entries' => $total > 0 ? $this->repo->queueLog($filters, ($page - 1) * self::LOG_PER_PAGE, self::LOG_PER_PAGE) : [],
            'sources' => $this->repo->sources(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    // ── Aktionen ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array{success:bool,message?:string,error?:string,__redirect?:string}
     */
    public function handlePost(string $section, array $post, array $files): array
    {
        $action = self::text($post['action'] ?? '', 40);
        $ids = self::ids($post['ids'] ?? []);

        return match ($action) {
            'run_queue' => $this->runQueue(false),
            'fetch_all' => $this->runQueue(true),
            'fetch_source' => $this->fetchSource(self::int($post['id'] ?? 0)),
            'save_source' => $this->saveSource($post),
            'bulk_sources' => $this->bulkSources(self::text($post['bulk'] ?? '', 20), $ids),
            'save_category' => $this->saveCategory($post),
            'delete_category' => $this->deleteCategories([self::int($post['id'] ?? 0)]),
            'bulk_categories' => $this->deleteCategories($ids),
            'item_flag' => $this->itemFlag(self::int($post['id'] ?? 0), self::text($post['flag'] ?? '', 20), $post),
            'bulk_items' => $this->bulkItems(self::text($post['bulk'] ?? '', 20), $ids, $post),
            'save_digest' => $this->saveDigest($post),
            'delete_digest' => $this->deleteDigest(self::int($post['id'] ?? 0)),
            'test_digest' => $this->testDigest(self::int($post['id'] ?? 0)),
            'delete_subscription' => $this->deleteSubscription(self::int($post['user_id'] ?? 0)),
            'retry_failed' => self::ok(self::t('log.retried', $this->repo->retryFailed()), 'log'),
            'clear_pending' => self::ok(self::t('log.cleared', $this->repo->clearPending()), 'log'),
            'cleanup_log' => self::ok(self::t('log.cleaned', $this->repo->cleanupQueue((int) AggregatorSettings::get('log_retention_days'))), 'log'),
            'import_catalog' => $this->importCatalog($post),
            'import_opml' => $this->importOpml($post, $files),
            'save_settings' => $this->saveSettings($post),
            default => ['success' => false, 'error' => self::t('error.unknown_action')],
        };
    }

    private function runQueue(bool $force): array
    {
        $result = (new FetchQueue($this->repo, new SourceFetcher($this->repo)))->tick(['force' => $force, 'limit' => 25]);
        if (!empty($result['locked'])) {
            return ['success' => false, 'error' => self::t('overview.locked')];
        }
        self::audit('feeds.queue_run', 'Feed-Abruf manuell gestartet.', ['force' => $force, 'result' => $result]);

        return self::ok(self::t('overview.run_done', (int) $result['processed'], (int) $result['success'], (int) $result['failed'], (int) $result['new_items']), 'overview');
    }

    private function fetchSource(int $id): array
    {
        if ($this->repo->source($id) === null) {
            return ['success' => false, 'error' => self::t('error.not_found')];
        }
        $outcome = (new FetchQueue($this->repo, new SourceFetcher($this->repo)))->fetchNow($id);

        return $outcome['success']
            ? self::ok(self::t('sources.fetched', (int) $outcome['new_items'], (int) $outcome['filtered']), 'sources')
            : ['success' => false, 'error' => self::t('sources.fetch_failed', (string) $outcome['error']), '__redirect' => self::url(['section' => 'sources'])];
    }

    /** @param array<string, mixed> $post */
    private function saveSource(array $post): array
    {
        $id = self::int($post['id'] ?? 0);
        $back = self::url(['section' => 'sources', 'edit' => $id ?: null, 'new' => $id > 0 ? null : 1]);
        $name = self::text($post['name'] ?? '', 255);
        $categoryId = self::int($post['category_id'] ?? 0);
        $url = FeedService::getInstance()->normalizeRemoteUrl(trim((string) ($post['feed_url'] ?? '')));
        $siteRaw = trim((string) ($post['site_url'] ?? ''));
        $site = AggregatorFrontendController::safeUrl($siteRaw);

        if ($categoryId <= 0 || $this->repo->category($categoryId) === null) {
            return ['success' => false, 'error' => self::t('error.category_required'), '__redirect' => $back];
        }
        if ($url === '') {
            return ['success' => false, 'error' => self::t('error.url'), '__redirect' => $back];
        }
        if ($siteRaw !== '' && $site === '') {
            return ['success' => false, 'error' => self::t('error.site_url'), '__redirect' => $back];
        }
        if ($this->repo->sourceUrlExists($url, $id)) {
            return ['success' => false, 'error' => self::t('error.url_exists'), '__redirect' => $back];
        }

        $saved = $this->repo->saveSource([
            'category_id' => $categoryId,
            'name' => $name !== '' ? $name : (string) parse_url($url, PHP_URL_HOST),
            'feed_url' => $url,
            'site_url' => $site,
            'description' => self::text($post['description'] ?? '', 1000, true),
            'is_active' => !empty($post['is_active']),
            'fetch_interval' => $post['fetch_interval'] ?? 60,
            'max_items' => $post['max_items'] ?? 50,
        ], $id);
        if ($saved <= 0) {
            return ['success' => false, 'error' => self::t('error.save'), '__redirect' => $back];
        }
        self::audit($id > 0 ? 'feeds.source_updated' : 'feeds.source_created', 'Feed-Quelle gespeichert.', ['id' => $saved, 'url' => $url]);

        $message = self::t('sources.saved');
        if ($id <= 0 && !empty($post['fetch_now'])) {
            $outcome = (new FetchQueue($this->repo, new SourceFetcher($this->repo)))->fetchNow($saved);
            $message .= ' ' . ($outcome['success'] ? self::t('sources.fetched', (int) $outcome['new_items'], (int) $outcome['filtered']) : self::t('sources.fetch_failed', (string) $outcome['error']));
        }

        return self::ok($message, 'sources');
    }

    /** @param list<int> $ids */
    private function bulkSources(string $bulk, array $ids): array
    {
        if ($ids === []) {
            return ['success' => false, 'error' => self::t('error.no_selection'), '__redirect' => self::url(['section' => 'sources'])];
        }
        $count = match ($bulk) {
            'activate' => $this->repo->setSourcesActive($ids, true),
            'deactivate' => $this->repo->setSourcesActive($ids, false),
            'fetch' => $this->repo->enqueue($ids, 'batch'),
            'delete' => $this->repo->deleteSources($ids),
            default => -1,
        };
        if ($count < 0) {
            return ['success' => false, 'error' => self::t('error.unknown_action')];
        }
        if ($bulk === 'delete') {
            self::audit('feeds.sources_deleted', 'Feed-Quellen gelöscht.', ['ids' => $ids]);
        }
        if ($bulk === 'fetch') {
            (new FetchQueue($this->repo, new SourceFetcher($this->repo)))->tick(['limit' => 25]);
        }

        return self::ok(self::t('bulk.done_' . $bulk, $count), 'sources');
    }

    /** @param array<string, mixed> $post */
    private function saveCategory(array $post): array
    {
        $id = self::int($post['id'] ?? 0);
        $back = self::url(['section' => 'categories', 'edit' => $id ?: null, 'new' => $id > 0 ? null : 1]);
        $name = self::text($post['name'] ?? '', 150);
        if ($name === '') {
            return ['success' => false, 'error' => self::t('error.name_required'), '__redirect' => $back];
        }
        $slug = LegacyNormalizer::categorySlug($post['slug'] ?? '', $name);
        if ($slug === AggregatorSettings::archiveSlug() || $slug === 'embed' || in_array($slug, AggregatorSettings::RESERVED_SLUGS, true)) {
            return ['success' => false, 'error' => self::t('error.slug_reserved'), '__redirect' => $back];
        }
        if ($this->repo->categorySlugExists($slug, $id)) {
            return ['success' => false, 'error' => self::t('error.slug_exists'), '__redirect' => $back];
        }

        $saved = $this->repo->saveCategory([
            'name' => $name,
            'name_en' => self::text($post['name_en'] ?? '', 150),
            'slug' => $slug,
            'description' => self::text($post['description'] ?? '', 2000, true),
            'description_en' => self::text($post['description_en'] ?? '', 2000, true),
            'icon' => self::text($post['icon'] ?? '', 10),
            'is_public' => !empty($post['is_public']),
            'sort_order' => self::int($post['sort_order'] ?? 0, true),
            'layout' => $post['layout'] ?? 'grid',
            'items_per_page' => $post['items_per_page'] ?? 20,
        ], $id);
        if ($saved <= 0) {
            return ['success' => false, 'error' => self::t('error.save'), '__redirect' => $back];
        }
        self::audit($id > 0 ? 'feeds.category_updated' : 'feeds.category_created', 'Feed-Bereich gespeichert.', ['id' => $saved, 'slug' => $slug]);

        return self::ok(self::t('categories.saved'), 'categories');
    }

    /** @param list<int> $ids */
    private function deleteCategories(array $ids): array
    {
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return ['success' => false, 'error' => self::t('error.no_selection'), '__redirect' => self::url(['section' => 'categories'])];
        }
        $count = $this->repo->deleteCategories($ids);
        self::audit('feeds.categories_deleted', 'Feed-Bereiche samt Quellen und Artikeln gelöscht.', ['ids' => $ids]);

        return self::ok(self::t('categories.deleted', $count), 'categories');
    }

    /** @param array<string, mixed> $post */
    private function itemFlag(int $id, string $flag, array $post): array
    {
        if ($flag === 'delete') {
            $this->repo->deleteItems([$id]);

            return self::ok(self::t('items.deleted', 1), 'items', $post);
        }
        $column = $flag === 'featured' ? 'is_featured' : ($flag === 'hidden' ? 'is_hidden' : '');
        if ($column === '' || $id <= 0) {
            return ['success' => false, 'error' => self::t('error.unknown_action')];
        }
        $this->repo->setItemsFlag([$id], $column, null);

        return self::ok(self::t('items.updated', 1), 'items', $post);
    }

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $post
     */
    private function bulkItems(string $bulk, array $ids, array $post): array
    {
        if ($ids === []) {
            return ['success' => false, 'error' => self::t('error.no_selection'), '__redirect' => self::itemsReturnUrl($post)];
        }
        $count = match ($bulk) {
            'hide' => $this->repo->setItemsFlag($ids, 'is_hidden', true),
            'show' => $this->repo->setItemsFlag($ids, 'is_hidden', false),
            'feature' => $this->repo->setItemsFlag($ids, 'is_featured', true),
            'unfeature' => $this->repo->setItemsFlag($ids, 'is_featured', false),
            'delete' => $this->repo->deleteItems($ids),
            default => -1,
        };
        if ($count < 0) {
            return ['success' => false, 'error' => self::t('error.unknown_action')];
        }

        return self::ok($bulk === 'delete' ? self::t('items.deleted', $count) : self::t('items.updated', $count), 'items', $post);
    }

    /** @param array<string, mixed> $post */
    private function saveDigest(array $post): array
    {
        $id = self::int($post['id'] ?? 0);
        $back = self::url(['section' => 'digests', 'edit' => $id ?: null, 'new' => $id > 0 ? null : 1]);
        $email = trim((string) ($post['email'] ?? ''));
        $name = self::text($post['name'] ?? '', 150);
        $categories = array_values(array_filter(
            self::ids($post['category_ids'] ?? []),
            fn (int $categoryId): bool => $this->repo->category($categoryId) !== null
        ));
        if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['success' => false, 'error' => self::t('error.digest_invalid'), '__redirect' => $back];
        }
        if ($categories === []) {
            return ['success' => false, 'error' => self::t('error.digest_categories'), '__redirect' => $back];
        }

        $saved = $this->repo->saveDigest([
            'name' => $name,
            'email' => mb_substr($email, 0, 255),
            'category_ids' => $categories,
            'frequency' => $post['frequency'] ?? 1,
            'language' => self::text($post['language'] ?? 'de', 5),
            'is_active' => !empty($post['is_active']),
            // Neuer Digest: nur Artikel ab jetzt.
            'last_sent_at' => AggregatorRepository::now(),
        ], $id);
        if ($saved <= 0) {
            return ['success' => false, 'error' => self::t('error.save'), '__redirect' => $back];
        }
        self::audit($id > 0 ? 'feeds.digest_updated' : 'feeds.digest_created', 'Feed-Digest gespeichert.', ['id' => $saved]);

        return self::ok(self::t('digests.saved'), 'digests');
    }

    private function deleteDigest(int $id): array
    {
        if (!$this->repo->deleteDigest($id)) {
            return ['success' => false, 'error' => self::t('error.not_found'), '__redirect' => self::url(['section' => 'digests'])];
        }
        self::audit('feeds.digest_deleted', 'Feed-Digest gelöscht.', ['id' => $id]);

        return self::ok(self::t('digests.deleted'), 'digests');
    }

    private function testDigest(int $id): array
    {
        $digest = $this->repo->digest($id);
        if ($digest === null) {
            return ['success' => false, 'error' => self::t('error.not_found'), '__redirect' => self::url(['section' => 'digests'])];
        }
        $status = (new DigestMailer($this->repo))->sendDigest($digest, true);

        return $status === 'sent' || $status === 'queued'
            ? self::ok(self::t('digests.test_sent', (string) $digest['email']), 'digests')
            : ['success' => false, 'error' => self::t('digests.test_failed', $status), '__redirect' => self::url(['section' => 'digests'])];
    }

    private function deleteSubscription(int $userId): array
    {
        if (!$this->repo->deleteSubscription($userId)) {
            return ['success' => false, 'error' => self::t('error.not_found'), '__redirect' => self::url(['section' => 'subscriptions'])];
        }
        self::audit('feeds.subscription_deleted', 'Feed-Abo eines Mitglieds gelöscht.', ['user_id' => $userId]);

        return self::ok(self::t('subscriptions.deleted'), 'subscriptions');
    }

    /** @param array<string, mixed> $post */
    private function importCatalog(array $post): array
    {
        $key = self::text($post['catalog_key'] ?? '', 40);
        $indexes = array_map(static fn (int $id): int => $id - 1, self::ids($post['feeds'] ?? []));
        $result = FeedCatalog::import($key, self::int($post['category_id'] ?? 0), $indexes);
        if ($result['category_id'] <= 0) {
            return ['success' => false, 'error' => implode(' ', $result['errors']), '__redirect' => self::url(['section' => 'import'])];
        }
        self::audit('feeds.catalog_imported', 'Feed-Katalog übernommen.', ['catalog' => $key] + $result);

        return self::ok(self::t('import.catalog_done', $result['imported'], $result['skipped']), 'sources');
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    private function importOpml(array $post, array $files): array
    {
        $back = self::url(['section' => 'import']);
        $file = is_array($files['opml'] ?? null) ? $files['opml'] : [];
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            return ['success' => false, 'error' => self::t('import.opml_missing'), '__redirect' => $back];
        }
        if ((int) ($file['size'] ?? 0) > OpmlService::MAX_BYTES) {
            return ['success' => false, 'error' => self::t('import.opml_too_large'), '__redirect' => $back];
        }
        $entries = OpmlService::parse((string) file_get_contents($tmp));
        if ($entries === null) {
            return ['success' => false, 'error' => self::t('import.opml_invalid'), '__redirect' => $back];
        }
        $result = OpmlService::import($entries, self::int($post['category_id'] ?? 0), 'OPML-Import');
        self::audit('feeds.opml_imported', 'Feed-Quellen aus OPML übernommen.', $result);

        return self::ok(self::t('import.opml_done', $result['imported'], $result['skipped'], $result['invalid'], $result['categories']), 'sources');
    }

    /** @param array<string, mixed> $post */
    private function saveSettings(array $post): array
    {
        $tab = in_array($post['stab'] ?? '', self::SETTINGS_TABS, true) ? (string) $post['stab'] : 'general';
        $values = [];
        foreach (AggregatorSettings::SECTIONS[$tab] as $key) {
            $default = AggregatorSettings::DEFAULTS[$key];
            $values[$key] = is_bool($default) ? !empty($post[$key]) : ($post[$key] ?? $default);
        }
        if ($tab === 'general') {
            $slug = AggregatorSettings::sanitizeSlug($values['archive_slug']);
            if ($this->repo->categoryBySlug($slug) !== null) {
                return ['success' => false, 'error' => self::t('error.archive_slug_taken'), '__redirect' => self::url(['section' => 'settings', 'stab' => $tab])];
            }
        }
        $before = AggregatorSettings::all();
        if (!AggregatorSettings::save($values)) {
            return ['success' => false, 'error' => self::t('error.save'), '__redirect' => self::url(['section' => 'settings', 'stab' => $tab])];
        }
        $changed = array_keys(array_diff_assoc(array_map('strval', array_intersect_key(AggregatorSettings::all(), $values)), array_map('strval', array_intersect_key($before, $values))));
        if ($changed !== []) {
            self::audit('feeds.aggregator_settings_updated', 'Einstellungen des Feed-Aggregators geändert.', ['keys' => $changed]);
        }

        return ['success' => true, 'message' => self::t('settings.saved'), '__redirect' => self::url(['section' => 'settings', 'stab' => $tab])];
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────

    /** OPML-Download aller Quellen. */
    public function streamOpml(): never
    {
        $xml = OpmlService::export();
        header('Content-Type: text/x-opml; charset=utf-8');
        header('Content-Disposition: attachment; filename="feeds-' . date('Y-m-d') . '.opml"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $xml;
        exit;
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function itemsReturnUrl(array $post): string
    {
        $return = is_string($post['return'] ?? null) ? (string) $post['return'] : '';

        return str_starts_with($return, '/admin/settings?tab=feeds&section=items') && !preg_match('/[\r\n]/', $return)
            ? $return
            : self::url(['section' => 'items']);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{success:true,message:string,__redirect:string}
     */
    private static function ok(string $message, string $section, array $post = []): array
    {
        return [
            'success' => true,
            'message' => $message,
            '__redirect' => $section === 'items' ? self::itemsReturnUrl($post) : self::url(['section' => $section !== 'overview' ? $section : null]),
        ];
    }

    /** @param array<string, mixed> $context */
    private static function audit(string $action, string $description, array $context = []): void
    {
        try {
            AuditLogger::instance()->log(AuditLogger::CAT_SETTING, $action, $description, 'feeds', null, $context);
        } catch (\Throwable) {
        }
    }

    private static function int(mixed $value, bool $allowNegative = false): int
    {
        $int = is_numeric($value) ? (int) $value : 0;

        return $allowNegative ? max(-100000, min(100000, $int)) : max(0, $int);
    }

    private static function text(mixed $value, int $max, bool $multiline = false): string
    {
        $value = is_scalar($value) ? strip_tags((string) $value) : '';
        $value = $multiline
            ? (preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value) ?? '')
            : (preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return mb_substr(trim($value), 0, $max);
    }

    /** @return list<int> */
    private static function ids(mixed $value): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, is_array($value) ? $value : []),
            static fn (int $id): bool => $id > 0
        )));

        return array_slice($ids, 0, self::BULK_LIMIT);
    }
}
