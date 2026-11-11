<?php
/**
 * Übernahme der Daten des Plugins cms-feed in den Feed-Aggregator des Cores (Tabellen `feeds_*`).
 *
 * Quelle: `feed_settings`, `feed_categories`, `feed_channels`, `feed_items`, `feed_digests`,
 * `feed_member_subscriptions` und die ältere Abo-Tabelle `feed_subscriptions` (eine Zeile je Kanal). Die Abruf-Queue
 * (`feed_fetch_queue`) ist Laufzeitzustand und wird nicht übernommen. Die Plugin-Tabellen bleiben unverändert.
 *
 * Idempotent über `legacy_id` (Bereiche, Quellen, Digests), die GUID je Quelle (Artikel) und „ein Abo je Mitglied“
 * (vorhandene Core-Abos werden nicht überschrieben). Artikel werden in Blöcken nach ID übernommen; der Fortschritt
 * liegt in der Option `feeds_plugin_migration`.
 *
 * Einstellungen werden nur übernommen, solange der Core-Wert noch dem Standard entspricht. War das Plugin beim
 * Update aktiv, wird der Aggregator eingeschaltet, damit `/feeds` und `/feed/<bereich>` ohne Unterbrechung
 * weiterlaufen.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\PluginMigration;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Feed\AggregatorRepository;
use CMS\Services\Feed\AggregatorSettings;
use CMS\Services\Feed\LegacyNormalizer;
use CMS\Services\IntegratedPluginService;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedPluginMigrator implements PluginMigrator
{
    private const STATE_OPTION = 'feeds_plugin_migration';
    private const ITEM_BATCH = 500;

    private Database $db;
    private string $prefix;

    /** @var array<string, int> */
    private array $report = ['categories' => 0, 'sources' => 0, 'items' => 0, 'digests' => 0, 'subscriptions' => 0, 'skipped' => 0];

    public function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function pluginSlug(): string
    {
        return 'cms-feed';
    }

    public function hasSourceData(): bool
    {
        try {
            return $this->db->tableExists($this->prefix . 'feed_channels') || $this->db->tableExists($this->prefix . 'feed_categories');
        } catch (\Throwable) {
            return false;
        }
    }

    public function migrate(): bool
    {
        $repo = AggregatorRepository::getInstance();
        $repo->resetReadyState();
        if (!$repo->isReady()) {
            return false;
        }

        $state = $this->state();
        foreach ($this->report as $key => $value) {
            $this->report[$key] = (int) ($state['report'][$key] ?? 0);
        }

        foreach (['settings', 'categories', 'sources'] as $stage) {
            if (empty($state[$stage . '_done'])) {
                match ($stage) {
                    'settings' => $this->migrateSettings(),
                    'categories' => $this->migrateCategories($repo),
                    'sources' => $this->migrateSources($repo),
                };
                $state[$stage . '_done'] = true;
                $state['report'] = $this->report;
                $this->saveState($state);
            }
        }

        if (empty($state['items_done'])) {
            $next = $this->migrateItems($repo, (int) ($state['item_cursor'] ?? 0));
            $state['item_cursor'] = $next ?? (int) ($state['item_cursor'] ?? 0);
            if ($next === null) {
                $state['items_done'] = true;
                $repo->recountSourceItems();
            }
            $state['report'] = $this->report;
            $this->saveState($state);
            if ($next !== null) {
                return false;
            }
        }

        foreach (['digests', 'subscriptions'] as $stage) {
            if (empty($state[$stage . '_done'])) {
                $stage === 'digests' ? $this->migrateDigests($repo) : $this->migrateSubscriptions($repo);
                $state[$stage . '_done'] = true;
            }
        }

        $state['done'] = true;
        $state['report'] = $this->report;
        $this->saveState($state);

        return true;
    }

    public function report(): array
    {
        return $this->report;
    }

    // ── Stufen ──────────────────────────────────────────────────────────────

    private function migrateSettings(): void
    {
        $values = [];
        if ($this->tableExists('feed_settings')) {
            $plugin = [];
            foreach ($this->rows("SELECT setting_key, setting_value FROM {$this->prefix}feed_settings") as $row) {
                $plugin[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
            $current = AggregatorSettings::all();
            foreach ($plugin as $key => $value) {
                if ($key === 'aggregator_enabled' || !array_key_exists($key, AggregatorSettings::DEFAULTS)) {
                    continue;
                }
                if ($current[$key] === AggregatorSettings::DEFAULTS[$key]) {
                    $values[$key] = $value;
                }
            }
        }

        try {
            $retired = IntegratedPluginService::getInstance()->getStatus()['cms-feed']['retired_at'] ?? null;
        } catch (\Throwable) {
            $retired = null;
        }
        if ($retired !== null && !AggregatorSettings::isEnabled()) {
            $values['aggregator_enabled'] = true;
        }

        if ($values !== []) {
            AggregatorSettings::save($values);
        }
    }

    private function migrateCategories(AggregatorRepository $repo): void
    {
        if (!$this->tableExists('feed_categories')) {
            return;
        }
        $known = $this->legacyMap('feeds_categories');
        foreach ($this->rows("SELECT * FROM {$this->prefix}feed_categories ORDER BY id ASC") as $row) {
            $legacyId = (int) $row['id'];
            if (isset($known[$legacyId])) {
                continue;
            }
            $slug = LegacyNormalizer::categorySlug($row['slug'] ?? '', (string) ($row['name'] ?? ''));
            $existing = $repo->categoryBySlug($slug);
            if ($existing !== null) {
                if (empty($existing['legacy_id'])) {
                    $this->db->execute("UPDATE {$this->prefix}feeds_categories SET legacy_id = ? WHERE id = ?", [$legacyId, (int) $existing['id']]);
                    continue;
                }
                $slug = $this->freeSlug($repo, $slug);
            }

            $id = $repo->saveCategory([
                'name' => mb_substr(trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : $slug, 0, 150),
                'name_en' => mb_substr((string) ($row['name_en'] ?? ''), 0, 150),
                'slug' => $slug,
                'description' => (string) ($row['description'] ?? ''),
                'description_en' => (string) ($row['description_en'] ?? ''),
                'icon' => LegacyNormalizer::icon($row['icon'] ?? ''),
                'is_public' => LegacyNormalizer::bool($row['is_public'] ?? 1, true),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'layout' => $row['layout'] ?? 'grid',
                'items_per_page' => $row['items_per_page'] ?? 20,
                'legacy_id' => $legacyId,
            ]);
            $id > 0 ? $this->report['categories']++ : $this->report['skipped']++;
        }
    }

    private function migrateSources(AggregatorRepository $repo): void
    {
        if (!$this->tableExists('feed_channels')) {
            return;
        }
        $categories = $this->legacyMap('feeds_categories');
        $known = $this->legacyMap('feeds_sources');
        foreach ($this->rows("SELECT * FROM {$this->prefix}feed_channels ORDER BY id ASC") as $row) {
            $legacyId = (int) $row['id'];
            if (isset($known[$legacyId])) {
                continue;
            }
            $categoryId = $categories[(int) ($row['category_id'] ?? 0)] ?? 0;
            $feedUrl = trim((string) ($row['feed_url'] ?? ''));
            if ($categoryId <= 0 || $feedUrl === '' || $repo->sourceUrlExists($feedUrl)) {
                $this->report['skipped']++;
                continue;
            }

            $error = trim((string) ($row['last_error'] ?? ''));
            $id = $repo->saveSource([
                'category_id' => $categoryId,
                'name' => mb_substr(trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : $feedUrl, 0, 255),
                'feed_url' => mb_substr($feedUrl, 0, 2048),
                'site_url' => mb_substr((string) ($row['site_url'] ?? ''), 0, 2048),
                'description' => (string) ($row['description'] ?? ''),
                'is_active' => LegacyNormalizer::bool($row['is_active'] ?? 1, true),
                'fetch_interval' => $row['fetch_interval'] ?? 60,
                'max_items' => $row['max_items'] ?? 50,
                'icon_url' => mb_substr((string) ($row['icon_url'] ?? ''), 0, 2048),
                'last_error' => $error !== '' ? mb_substr($error, 0, 500) : null,
                'last_fetched_at' => LegacyNormalizer::dateTime($row['last_fetched_at'] ?? null),
                'legacy_id' => $legacyId,
            ]);
            $id > 0 ? $this->report['sources']++ : $this->report['skipped']++;
        }
    }

    /** Nächster Artikelblock; liefert den neuen Cursor oder null, wenn alle übernommen sind. */
    private function migrateItems(AggregatorRepository $repo, int $cursor): ?int
    {
        if (!$this->tableExists('feed_items')) {
            return null;
        }
        $rows = $this->rows(
            "SELECT * FROM {$this->prefix}feed_items WHERE id > ? ORDER BY id ASC LIMIT " . self::ITEM_BATCH,
            [$cursor]
        );
        if ($rows === []) {
            return null;
        }

        $sources = [];
        foreach ($this->rows("SELECT id, legacy_id, category_id FROM {$this->prefix}feeds_sources WHERE legacy_id IS NOT NULL") as $source) {
            $sources[(int) $source['legacy_id']] = ['id' => (int) $source['id'], 'category_id' => (int) $source['category_id']];
        }

        foreach ($rows as $row) {
            $cursor = (int) $row['id'];
            $source = $sources[(int) ($row['channel_id'] ?? 0)] ?? null;
            $guid = trim((string) ($row['guid'] ?? ''));
            if ($guid === '') {
                $guid = trim((string) ($row['link'] ?? ''));
            }
            if ($source === null || $guid === '') {
                $this->report['skipped']++;
                continue;
            }

            $inserted = $repo->insertItem([
                'source_id' => $source['id'],
                'category_id' => $source['category_id'],
                'guid' => $guid,
                'title' => trim((string) ($row['title'] ?? '')),
                'link' => (string) ($row['link'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'author' => (string) ($row['author'] ?? ''),
                'image_url' => (string) ($row['image_url'] ?? ''),
                'pub_date' => LegacyNormalizer::dateTime($row['pub_date'] ?? null)
                    ?? LegacyNormalizer::dateTime($row['created_at'] ?? null)
                    ?? AggregatorRepository::now(),
                'is_featured' => LegacyNormalizer::bool($row['is_featured'] ?? 0),
                'is_hidden' => LegacyNormalizer::bool($row['is_hidden'] ?? 0),
                'legacy_id' => (int) $row['id'],
                'created_at' => LegacyNormalizer::dateTime($row['created_at'] ?? null) ?? AggregatorRepository::now(),
            ]);
            if ($inserted) {
                $this->report['items']++;
            }
        }

        return $cursor;
    }

    private function migrateDigests(AggregatorRepository $repo): void
    {
        if (!$this->tableExists('feed_digests')) {
            return;
        }
        $categories = $this->legacyMap('feeds_categories');
        $known = $this->legacyMap('feeds_digests');
        foreach ($this->rows("SELECT * FROM {$this->prefix}feed_digests ORDER BY id ASC") as $row) {
            $legacyId = (int) $row['id'];
            $email = trim((string) ($row['email'] ?? ''));
            if (isset($known[$legacyId])) {
                continue;
            }
            $categoryIds = LegacyNormalizer::mapIds(LegacyNormalizer::idList($row['category_ids'] ?? '[]'), $categories);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $categoryIds === []) {
                $this->report['skipped']++;
                continue;
            }
            $id = $repo->saveDigest([
                'name' => mb_substr(trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : $email, 0, 150),
                'email' => $email,
                'category_ids' => $categoryIds,
                'frequency' => $row['frequency'] ?? 1,
                'language' => (string) ($row['language'] ?? 'de'),
                'is_active' => LegacyNormalizer::bool($row['is_active'] ?? 1, true),
                'last_sent_at' => LegacyNormalizer::dateTime($row['last_sent_at'] ?? null),
                'legacy_id' => $legacyId,
            ]);
            $id > 0 ? $this->report['digests']++ : $this->report['skipped']++;
        }
    }

    private function migrateSubscriptions(AggregatorRepository $repo): void
    {
        $sources = $this->legacyMap('feeds_sources');
        /** @var array<int, array<string, mixed>> $plans */
        $plans = [];

        if ($this->tableExists('feed_member_subscriptions')) {
            foreach ($this->rows("SELECT * FROM {$this->prefix}feed_member_subscriptions ORDER BY id ASC") as $row) {
                $plans[(int) $row['user_id']] = $row + ['_channels' => LegacyNormalizer::idList($row['channel_ids'] ?? '[]')];
            }
        }
        // Ältere Abo-Tabelle (eine Zeile je Kanal): nur Mitglieder ohne Eintrag in der neueren Tabelle.
        if ($this->tableExists('feed_subscriptions')) {
            foreach ($this->rows("SELECT * FROM {$this->prefix}feed_subscriptions ORDER BY id ASC") as $row) {
                $userId = (int) $row['user_id'];
                if (isset($plans[$userId]) && empty($plans[$userId]['_legacy'])) {
                    continue;
                }
                $plans[$userId] ??= $row + ['_channels' => [], '_legacy' => true];
                $plans[$userId]['_channels'][] = (int) ($row['channel_id'] ?? 0);
                $plans[$userId]['is_active'] = !empty($plans[$userId]['is_active']) || LegacyNormalizer::bool($row['is_active'] ?? 1, true);
            }
        }

        foreach ($plans as $userId => $plan) {
            if ($userId <= 0 || $repo->subscriptionForUser($userId) !== null || !$this->userExists($userId)) {
                $this->report['skipped']++;
                continue;
            }
            $sourceIds = LegacyNormalizer::mapIds($plan['_channels'], $sources);
            if ($sourceIds === []) {
                $this->report['skipped']++;
                continue;
            }
            $repo->saveSubscription($userId, [
                'source_ids' => $sourceIds,
                'frequency' => $plan['frequency'] ?? 'daily',
                'daily_mode' => $plan['daily_mode'] ?? '09',
                'weekly_day' => $plan['weekly_day'] ?? 1,
                'weekly_time' => $plan['weekly_time'] ?? '09',
                'language' => (string) ($plan['language'] ?? 'de'),
                'is_active' => LegacyNormalizer::bool($plan['is_active'] ?? 1, true),
                'last_sent_at' => LegacyNormalizer::dateTime($plan['last_sent_at'] ?? null),
                'created_at' => LegacyNormalizer::dateTime($plan['created_at'] ?? null) ?? AggregatorRepository::now(),
            ]);
            $this->report['subscriptions']++;
        }
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────

    private function freeSlug(AggregatorRepository $repo, string $slug): string
    {
        $base = substr($slug, 0, 110);
        for ($n = 2; $n < 1000; $n++) {
            $candidate = $base . '-' . $n;
            if (!$repo->categorySlugExists($candidate)) {
                return $candidate;
            }
        }

        return $base . '-' . bin2hex(random_bytes(3));
    }

    /** @return array<int, int> legacy_id => id */
    private function legacyMap(string $table): array
    {
        $map = [];
        foreach ($this->rows("SELECT id, legacy_id FROM {$this->prefix}{$table} WHERE legacy_id IS NOT NULL") as $row) {
            $map[(int) $row['legacy_id']] = (int) $row['id'];
        }

        return $map;
    }

    private function userExists(int $userId): bool
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}users WHERE id = ?", [$userId]) > 0;
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->db->tableExists($this->prefix . $table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        try {
            return $this->db->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('plugins.integration')->warning('cms-feed: Lesen der Plugin-Daten fehlgeschlagen.', ['exception' => $e->getMessage()]);

            return [];
        }
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        try {
            $raw = $this->db->get_var("SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1", [self::STATE_OPTION]);
            $state = is_string($raw) ? json_decode($raw, true) : null;
        } catch (\Throwable) {
            $state = null;
        }

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private function saveState(array $state): void
    {
        $this->db->execute(
            "INSERT INTO {$this->prefix}settings (option_name, option_value, autoload) VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            [self::STATE_OPTION, (string) json_encode($state)]
        );
    }
}
