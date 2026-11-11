<?php
/**
 * Datenzugriff des Feed-Aggregators (Tabellen `feeds_*`, ehemals Plugin cms-feed).
 *
 * Alle Abfragen laufen über gebundene Parameter. Zeitstempel erzeugt PHP (gleiche Zeitzone wie Cron und
 * Anzeige), nicht `NOW()` der Datenbank. Fehlen die Tabellen (z. B. vor dem Schema-Update), meldet
 * `isReady()` false und der Aggregator bleibt still deaktiviert.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

use CMS\Database;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class AggregatorRepository
{
    public const TABLES = [
        'feeds_categories',
        'feeds_sources',
        'feeds_items',
        'feeds_digests',
        'feeds_subscriptions',
        'feeds_fetch_queue',
    ];

    public const ITEM_FLAGS = ['is_featured', 'is_hidden'];

    private static ?self $instance = null;
    private Database $db;
    private string $prefix;
    private ?bool $ready = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public static function now(int $offsetSeconds = 0): string
    {
        return date('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    public function isReady(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }

        $missing = [];
        try {
            foreach (self::TABLES as $table) {
                if (!$this->db->tableExists($this->prefix . $table)) {
                    $missing[] = $table;
                }
            }
        } catch (\Throwable $e) {
            $missing = ['(' . $e->getMessage() . ')'];
        }

        if ($missing !== []) {
            Logger::instance()->withChannel('feeds')->warning('Feed-Aggregator deaktiviert: Tabellen fehlen.', ['missing' => $missing]);
        }

        return $this->ready = $missing === [];
    }

    public function resetReadyState(): void
    {
        $this->ready = null;
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    // ── Bereiche ────────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(bool $publicOnly = false): array
    {
        $where = $publicOnly ? 'WHERE c.is_public = 1' : '';

        return $this->rows(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM {$this->table('feeds_sources')} s WHERE s.category_id = c.id) AS source_count,
                    (SELECT COUNT(*) FROM {$this->table('feeds_items')} i WHERE i.category_id = c.id AND i.is_hidden = 0) AS item_count
             FROM {$this->table('feeds_categories')} c
             {$where}
             ORDER BY c.sort_order ASC, c.name ASC"
        );
    }

    /** @return array<string, mixed>|null */
    public function category(int $id): ?array
    {
        return $this->row("SELECT * FROM {$this->table('feeds_categories')} WHERE id = ?", [$id]);
    }

    /** @return array<string, mixed>|null */
    public function categoryBySlug(string $slug): ?array
    {
        return $this->row("SELECT * FROM {$this->table('feeds_categories')} WHERE slug = ? LIMIT 1", [$slug]);
    }

    public function categorySlugExists(string $slug, int $excludeId = 0): bool
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->table('feeds_categories')} WHERE slug = ? AND id <> ?",
            [$slug, $excludeId]
        ) > 0;
    }

    /**
     * @param array<string, mixed> $data bereits validierte Werte
     */
    public function saveCategory(array $data, int $id = 0): int
    {
        $values = [
            'name' => (string) $data['name'],
            'name_en' => (string) ($data['name_en'] ?? ''),
            'slug' => (string) $data['slug'],
            'description' => (string) ($data['description'] ?? ''),
            'description_en' => (string) ($data['description_en'] ?? ''),
            'icon' => (string) ($data['icon'] ?? ''),
            'is_public' => !empty($data['is_public']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'layout' => LegacyNormalizer::layout($data['layout'] ?? 'grid'),
            'items_per_page' => LegacyNormalizer::itemsPerPage($data['items_per_page'] ?? 20),
            'updated_at' => self::now(),
        ];

        if ($id > 0) {
            return $this->db->update('feeds_categories', $values, ['id' => $id]) ? $id : 0;
        }

        $values['created_at'] = $values['updated_at'];
        if (isset($data['legacy_id'])) {
            $values['legacy_id'] = (int) $data['legacy_id'];
        }
        $newId = $this->db->insert('feeds_categories', $values);

        return is_int($newId) ? $newId : 0;
    }

    /**
     * Löscht Bereiche samt Quellen, Artikeln und Queue (Fremdschlüssel) und bereinigt Abos/Digests.
     *
     * @param list<int> $ids
     */
    public function deleteCategories(array $ids): int
    {
        $ids = $this->ids($ids);
        if ($ids === []) {
            return 0;
        }

        $sourceIds = $this->ids($this->db->get_col(
            "SELECT id FROM {$this->table('feeds_sources')} WHERE category_id IN ({$this->placeholders($ids)})",
            $ids
        ));
        $this->removeSourcesFromSubscriptions($sourceIds);
        $this->removeCategoriesFromDigests($ids);
        $this->db->execute("DELETE FROM {$this->table('feeds_items')} WHERE category_id IN ({$this->placeholders($ids)})", $ids);

        return $this->db->execute(
            "DELETE FROM {$this->table('feeds_categories')} WHERE id IN ({$this->placeholders($ids)})",
            $ids
        )->rowCount();
    }

    // ── Quellen ─────────────────────────────────────────────────────────────

    /**
     * @param array{category_id?:int,status?:string,search?:string} $filters
     * @return list<array<string, mixed>>
     */
    public function sources(array $filters = []): array
    {
        [$where, $params] = $this->sourceWhere($filters);

        return $this->rows(
            "SELECT s.*, c.name AS category_name, c.slug AS category_slug
             FROM {$this->table('feeds_sources')} s
             LEFT JOIN {$this->table('feeds_categories')} c ON c.id = s.category_id
             WHERE {$where}
             ORDER BY c.sort_order ASC, c.name ASC, s.name ASC",
            $params
        );
    }

    /** @return array<string, mixed>|null */
    public function source(int $id): ?array
    {
        return $this->row(
            "SELECT s.*, c.name AS category_name, c.slug AS category_slug
             FROM {$this->table('feeds_sources')} s
             LEFT JOIN {$this->table('feeds_categories')} c ON c.id = s.category_id
             WHERE s.id = ?",
            [$id]
        );
    }

    public function sourceUrlExists(string $feedUrl, int $excludeId = 0): bool
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->table('feeds_sources')} WHERE feed_url = ? AND id <> ?",
            [$feedUrl, $excludeId]
        ) > 0;
    }

    /**
     * @param array<string, mixed> $data bereits validierte Werte
     */
    public function saveSource(array $data, int $id = 0): int
    {
        $values = [
            'category_id' => (int) $data['category_id'],
            'name' => (string) $data['name'],
            'feed_url' => (string) $data['feed_url'],
            'site_url' => (string) ($data['site_url'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'fetch_interval' => LegacyNormalizer::fetchInterval($data['fetch_interval'] ?? 60),
            'max_items' => LegacyNormalizer::maxItems($data['max_items'] ?? 50),
            'updated_at' => self::now(),
        ];

        if ($id > 0) {
            $previous = $this->source($id);
            if (!$this->db->update('feeds_sources', $values, ['id' => $id])) {
                return 0;
            }
            if ($previous !== null && (int) $previous['category_id'] !== $values['category_id']) {
                $this->db->execute(
                    "UPDATE {$this->table('feeds_items')} SET category_id = ? WHERE source_id = ?",
                    [$values['category_id'], $id]
                );
            }

            return $id;
        }

        $values['created_at'] = $values['updated_at'];
        foreach (['icon_url', 'last_error', 'last_fetched_at', 'legacy_id'] as $optional) {
            if (array_key_exists($optional, $data)) {
                $values[$optional] = $data[$optional];
            }
        }
        $newId = $this->db->insert('feeds_sources', $values);

        return is_int($newId) ? $newId : 0;
    }

    /** @param list<int> $ids */
    public function deleteSources(array $ids): int
    {
        $ids = $this->ids($ids);
        if ($ids === []) {
            return 0;
        }

        $this->removeSourcesFromSubscriptions($ids);

        return $this->db->execute(
            "DELETE FROM {$this->table('feeds_sources')} WHERE id IN ({$this->placeholders($ids)})",
            $ids
        )->rowCount();
    }

    /** @param list<int> $ids */
    public function setSourcesActive(array $ids, bool $active): int
    {
        $ids = $this->ids($ids);
        if ($ids === []) {
            return 0;
        }

        return $this->db->execute(
            "UPDATE {$this->table('feeds_sources')} SET is_active = ?, updated_at = ? WHERE id IN ({$this->placeholders($ids)})",
            array_merge([$active ? 1 : 0, self::now()], $ids)
        )->rowCount();
    }

    /**
     * Erfolgreicher Abruf: Fehlerzustand zurücksetzen, Artikelanzahl neu zählen, fehlende Metadaten ergänzen.
     */
    public function recordFetchSuccess(int $sourceId, string $siteUrl = '', string $iconUrl = ''): void
    {
        $this->db->execute(
            "UPDATE {$this->table('feeds_sources')}
             SET last_fetched_at = ?, last_error = NULL, error_count = 0,
                 item_count = (SELECT COUNT(*) FROM {$this->table('feeds_items')} WHERE source_id = ?),
                 site_url = IF(site_url = '', ?, site_url),
                 icon_url = IF(icon_url = '', ?, icon_url)
             WHERE id = ?",
            [self::now(), $sourceId, mb_substr($siteUrl, 0, 2048), mb_substr($iconUrl, 0, 2048), $sourceId]
        );
    }

    public function recordFetchError(int $sourceId, string $error): void
    {
        $now = self::now();
        $this->db->execute(
            "UPDATE {$this->table('feeds_sources')}
             SET last_fetched_at = ?, last_error = ?, last_error_at = ?, error_count = error_count + 1
             WHERE id = ?",
            [$now, mb_substr($error, 0, 500), $now, $sourceId]
        );
    }

    /**
     * Fällige aktive Quellen: nie abgerufen, Intervall (mind. 5 Minuten) abgelaufen, nach Fehlern mind. 30 Minuten Pause.
     *
     * @return list<int>
     */
    public function dueSourceIds(int $limit = 0): array
    {
        $rows = $this->rows(
            "SELECT id, fetch_interval, last_fetched_at, last_error
             FROM {$this->table('feeds_sources')}
             WHERE is_active = 1
             ORDER BY (last_fetched_at IS NOT NULL) ASC, last_fetched_at ASC, id ASC"
        );

        $due = [];
        $now = time();
        foreach ($rows as $row) {
            if (self::isDue($row, $now)) {
                $due[] = (int) $row['id'];
                if ($limit > 0 && count($due) >= $limit) {
                    break;
                }
            }
        }

        return $due;
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function isDue(array $source, ?int $now = null): bool
    {
        $now ??= time();
        $last = !empty($source['last_fetched_at']) ? strtotime((string) $source['last_fetched_at']) : false;
        if ($last === false) {
            return true;
        }

        $minutes = max(5, (int) ($source['fetch_interval'] ?? 60));
        if (trim((string) ($source['last_error'] ?? '')) !== '') {
            $minutes = max(30, $minutes);
        }

        return $now - $last >= $minutes * 60;
    }

    /** @return list<int> */
    public function activeSourceIds(): array
    {
        return $this->ids($this->db->get_col(
            "SELECT id FROM {$this->table('feeds_sources')} WHERE is_active = 1 ORDER BY id ASC"
        ));
    }

    // ── Artikel ─────────────────────────────────────────────────────────────

    /**
     * Fügt einen Artikel ein; vorhandene GUIDs je Quelle werden ignoriert.
     *
     * @param array<string, mixed> $item
     */
    public function insertItem(array $item): bool
    {
        $values = [
            (int) $item['source_id'],
            (int) $item['category_id'],
            mb_substr((string) $item['guid'], 0, 500),
            mb_substr((string) $item['title'], 0, 500),
            mb_substr((string) ($item['link'] ?? ''), 0, 2048),
            (string) ($item['description'] ?? ''),
            (string) ($item['content'] ?? ''),
            mb_substr((string) ($item['author'] ?? ''), 0, 190),
            mb_substr((string) ($item['image_url'] ?? ''), 0, 2048),
            (string) $item['pub_date'],
            !empty($item['is_featured']) ? 1 : 0,
            !empty($item['is_hidden']) ? 1 : 0,
            isset($item['legacy_id']) ? (int) $item['legacy_id'] : null,
            (string) ($item['created_at'] ?? self::now()),
        ];

        return $this->db->execute(
            "INSERT IGNORE INTO {$this->table('feeds_items')}
                (source_id, category_id, guid, title, link, description, content, author, image_url, pub_date,
                 is_featured, is_hidden, legacy_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            $values
        )->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $filters `public`, `category_id`, `source_id`, `search`, `status`, `since`, `source_ids`, `category_ids`
     * @return list<array<string, mixed>>
     */
    public function items(array $filters = [], int $offset = 0, int $limit = 20): array
    {
        [$where, $params] = $this->itemWhere($filters);
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);

        $rows = $this->rows(
            "SELECT i.id, i.source_id, i.category_id, i.guid, i.title, i.link, i.description, i.author, i.image_url,
                    i.pub_date, i.is_featured, i.is_hidden, i.created_at,
                    s.name AS source_name, s.site_url AS source_site_url, s.icon_url AS source_icon_url,
                    c.name AS category_name, c.name_en AS category_name_en, c.slug AS category_slug, c.icon AS category_icon
             FROM {$this->table('feeds_items')} i
             INNER JOIN {$this->table('feeds_sources')} s ON s.id = i.source_id
             INNER JOIN {$this->table('feeds_categories')} c ON c.id = i.category_id
             WHERE {$where}
             ORDER BY i.pub_date DESC, i.id DESC
             LIMIT ? OFFSET ?",
            $params
        );

        foreach ($rows as &$row) {
            // Kompatibilität zu Theme-Overrides des Plugins (`channel_*`).
            $row['channel_id'] = $row['source_id'];
            $row['channel_name'] = $row['source_name'];
            $row['channel_site_url'] = $row['source_site_url'];
            $row['channel_icon_url'] = $row['source_icon_url'];
        }
        unset($row);

        return $rows;
    }

    /** @param array<string, mixed> $filters */
    public function countItems(array $filters = []): int
    {
        [$where, $params] = $this->itemWhere($filters);

        return (int) $this->db->get_var(
            "SELECT COUNT(*)
             FROM {$this->table('feeds_items')} i
             INNER JOIN {$this->table('feeds_sources')} s ON s.id = i.source_id
             INNER JOIN {$this->table('feeds_categories')} c ON c.id = i.category_id
             WHERE {$where}",
            $params
        );
    }

    /**
     * @param list<int> $ids
     */
    public function setItemsFlag(array $ids, string $flag, ?bool $value): int
    {
        $ids = $this->ids($ids);
        if ($ids === [] || !in_array($flag, self::ITEM_FLAGS, true)) {
            return 0;
        }

        $set = $value === null ? "{$flag} = 1 - {$flag}" : "{$flag} = " . ($value ? '1' : '0');

        return $this->db->execute(
            "UPDATE {$this->table('feeds_items')} SET {$set} WHERE id IN ({$this->placeholders($ids)})",
            $ids
        )->rowCount();
    }

    /** @param list<int> $ids */
    public function deleteItems(array $ids): int
    {
        $ids = $this->ids($ids);
        if ($ids === []) {
            return 0;
        }

        $sourceIds = $this->ids($this->db->get_col(
            "SELECT DISTINCT source_id FROM {$this->table('feeds_items')} WHERE id IN ({$this->placeholders($ids)})",
            $ids
        ));
        $deleted = $this->db->execute(
            "DELETE FROM {$this->table('feeds_items')} WHERE id IN ({$this->placeholders($ids)})",
            $ids
        )->rowCount();
        $this->recountSourceItems($sourceIds);

        return $deleted;
    }

    /**
     * Entfernt nicht hervorgehobene Artikel, deren Veröffentlichung älter als `$days` Tage ist (0 = nie).
     */
    public function pruneItems(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $deleted = $this->db->execute(
            "DELETE FROM {$this->table('feeds_items')} WHERE pub_date < ? AND is_featured = 0",
            [self::now(-$days * 86400)]
        )->rowCount();
        if ($deleted > 0) {
            $this->recountSourceItems();
        }

        return $deleted;
    }

    /** @param list<int> $sourceIds leer = alle Quellen */
    public function recountSourceItems(array $sourceIds = []): void
    {
        $sql = "UPDATE {$this->table('feeds_sources')} s
                SET item_count = (SELECT COUNT(*) FROM {$this->table('feeds_items')} i WHERE i.source_id = s.id)";
        $sourceIds = $this->ids($sourceIds);
        if ($sourceIds !== []) {
            $sql .= " WHERE s.id IN ({$this->placeholders($sourceIds)})";
        }

        $this->db->execute($sql, $sourceIds);
    }

    // ── Digests ─────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function digests(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';

        return $this->rows("SELECT * FROM {$this->table('feeds_digests')} {$where} ORDER BY name ASC, id ASC");
    }

    /** @return array<string, mixed>|null */
    public function digest(int $id): ?array
    {
        return $this->row("SELECT * FROM {$this->table('feeds_digests')} WHERE id = ?", [$id]);
    }

    /** @param array<string, mixed> $data bereits validierte Werte */
    public function saveDigest(array $data, int $id = 0): int
    {
        $values = [
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'category_ids' => json_encode(LegacyNormalizer::idList($data['category_ids'] ?? []), JSON_THROW_ON_ERROR),
            'frequency' => LegacyNormalizer::digestFrequency($data['frequency'] ?? 1),
            'language' => FeedI18n::normalizeLocale((string) ($data['language'] ?? 'de')),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_at' => self::now(),
        ];

        if ($id > 0) {
            return $this->db->update('feeds_digests', $values, ['id' => $id]) ? $id : 0;
        }

        $values['created_at'] = $values['updated_at'];
        foreach (['last_sent_at', 'legacy_id'] as $optional) {
            if (array_key_exists($optional, $data)) {
                $values[$optional] = $data[$optional];
            }
        }
        $newId = $this->db->insert('feeds_digests', $values);

        return is_int($newId) ? $newId : 0;
    }

    public function deleteDigest(int $id): bool
    {
        return $this->db->execute("DELETE FROM {$this->table('feeds_digests')} WHERE id = ?", [$id])->rowCount() > 0;
    }

    public function markDigestSent(int $id, ?string $at = null): void
    {
        $this->db->execute(
            "UPDATE {$this->table('feeds_digests')} SET last_sent_at = ? WHERE id = ?",
            [$at ?? self::now(), $id]
        );
    }

    public function deleteDigestsByEmail(string $email): int
    {
        $email = trim($email);
        if ($email === '') {
            return 0;
        }

        return $this->db->execute(
            "DELETE FROM {$this->table('feeds_digests')} WHERE LOWER(email) = LOWER(?)",
            [$email]
        )->rowCount();
    }

    /** @param list<int> $categoryIds */
    private function removeCategoriesFromDigests(array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }

        foreach ($this->digests() as $digest) {
            $current = LegacyNormalizer::idList($digest['category_ids'] ?? '[]');
            $remaining = array_values(array_diff($current, $categoryIds));
            if ($remaining !== $current) {
                $this->db->execute(
                    "UPDATE {$this->table('feeds_digests')} SET category_ids = ?, updated_at = ? WHERE id = ?",
                    [json_encode($remaining), self::now(), (int) $digest['id']]
                );
            }
        }
    }

    // ── Mitglieder-Abos ─────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    public function subscriptionForUser(int $userId): ?array
    {
        return $this->row("SELECT * FROM {$this->table('feeds_subscriptions')} WHERE user_id = ? LIMIT 1", [$userId]);
    }

    /**
     * Legt das Abo eines Mitglieds an oder aktualisiert es (ein Abo je Mitglied).
     *
     * @param array<string, mixed> $data
     */
    public function saveSubscription(int $userId, array $data): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $now = self::now();
        $values = [
            $userId,
            json_encode(LegacyNormalizer::idList($data['source_ids'] ?? []), JSON_THROW_ON_ERROR),
            LegacyNormalizer::frequency($data['frequency'] ?? 'daily'),
            LegacyNormalizer::dailyMode($data['daily_mode'] ?? '09'),
            LegacyNormalizer::weeklyDay($data['weekly_day'] ?? 1),
            LegacyNormalizer::weeklyTime($data['weekly_time'] ?? '09'),
            FeedI18n::normalizeLocale((string) ($data['language'] ?? 'de')),
            !empty($data['is_active']) ? 1 : 0,
            $data['last_sent_at'] ?? null,
            (string) ($data['created_at'] ?? $now),
            $now,
        ];

        $this->db->execute(
            "INSERT INTO {$this->table('feeds_subscriptions')}
                (user_id, source_ids, frequency, daily_mode, weekly_day, weekly_time, language, is_active, last_sent_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                source_ids = VALUES(source_ids), frequency = VALUES(frequency), daily_mode = VALUES(daily_mode),
                weekly_day = VALUES(weekly_day), weekly_time = VALUES(weekly_time), language = VALUES(language),
                is_active = VALUES(is_active), updated_at = VALUES(updated_at)",
            $values
        );

        return true;
    }

    public function deleteSubscription(int $userId): bool
    {
        return $this->db->execute(
            "DELETE FROM {$this->table('feeds_subscriptions')} WHERE user_id = ?",
            [$userId]
        )->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function subscriptions(int $offset = 0, int $limit = 50): array
    {
        return $this->rows(
            "SELECT f.*, u.username, u.display_name, u.email, u.status AS user_status
             FROM {$this->table('feeds_subscriptions')} f
             LEFT JOIN {$this->table('users')} u ON u.id = f.user_id
             ORDER BY f.is_active DESC, f.updated_at DESC, f.id DESC
             LIMIT ? OFFSET ?",
            [max(1, $limit), max(0, $offset)]
        );
    }

    public function countSubscriptions(bool $activeOnly = false): int
    {
        $where = $activeOnly ? "WHERE is_active = 1 AND source_ids IS NOT NULL AND source_ids NOT IN ('', '[]')" : '';

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table('feeds_subscriptions')} {$where}");
    }

    /**
     * Aktive Abos aktiver Mitglieder mit mindestens einer Quelle, inkl. aktueller E-Mail-Adresse.
     *
     * @return list<array<string, mixed>>
     */
    public function activeSubscriptions(): array
    {
        return $this->rows(
            "SELECT f.*, u.email, u.display_name, u.username
             FROM {$this->table('feeds_subscriptions')} f
             INNER JOIN {$this->table('users')} u ON u.id = f.user_id
             WHERE f.is_active = 1
               AND u.status = 'active'
               AND u.email <> ''
               AND f.source_ids IS NOT NULL
               AND f.source_ids NOT IN ('', '[]')
             ORDER BY f.updated_at ASC, f.id ASC"
        );
    }

    public function markSubscriptionSent(int $id, string $at): void
    {
        $this->db->execute(
            "UPDATE {$this->table('feeds_subscriptions')} SET last_sent_at = ? WHERE id = ?",
            [$at, $id]
        );
    }

    /** Entfernt Abos gelöschter Benutzer. */
    public function deleteOrphanSubscriptions(): int
    {
        return $this->db->execute(
            "DELETE f FROM {$this->table('feeds_subscriptions')} f
             LEFT JOIN {$this->table('users')} u ON u.id = f.user_id
             WHERE u.id IS NULL"
        )->rowCount();
    }

    /** @param list<int> $sourceIds */
    private function removeSourcesFromSubscriptions(array $sourceIds): void
    {
        if ($sourceIds === []) {
            return;
        }

        $rows = $this->rows("SELECT id, source_ids FROM {$this->table('feeds_subscriptions')}");
        foreach ($rows as $row) {
            $current = LegacyNormalizer::idList($row['source_ids'] ?? '[]');
            $remaining = array_values(array_diff($current, $sourceIds));
            if ($remaining !== $current) {
                $this->db->execute(
                    "UPDATE {$this->table('feeds_subscriptions')} SET source_ids = ?, updated_at = ? WHERE id = ?",
                    [json_encode($remaining), self::now(), (int) $row['id']]
                );
            }
        }
    }

    /**
     * Neueste sichtbare Artikel der angegebenen Quellen bzw. Bereiche seit `$since`.
     *
     * @param list<int> $sourceIds
     * @param list<int> $categoryIds
     * @return list<array<string, mixed>>
     */
    public function recentItems(array $sourceIds, array $categoryIds, string $since, int $limit): array
    {
        $sourceIds = $this->ids($sourceIds);
        $categoryIds = $this->ids($categoryIds);
        if ($sourceIds === [] && $categoryIds === []) {
            return [];
        }

        $filters = ['since' => $since, 'status' => 'visible'];
        if ($sourceIds !== []) {
            $filters['source_ids'] = $sourceIds;
        }
        if ($categoryIds !== []) {
            $filters['category_ids'] = $categoryIds;
        }

        return $this->items($filters, 0, max(1, $limit));
    }

    // ── Abruf-Queue und Protokoll ───────────────────────────────────────────

    /**
     * Reiht Quellen ein, die noch keinen offenen Auftrag haben.
     *
     * @param list<int> $sourceIds
     */
    public function enqueue(array $sourceIds, string $trigger = 'cron'): int
    {
        $sourceIds = $this->ids($sourceIds);
        if ($sourceIds === []) {
            return 0;
        }

        $open = array_flip($this->ids($this->db->get_col(
            "SELECT DISTINCT source_id FROM {$this->table('feeds_fetch_queue')}
             WHERE status IN ('pending', 'processing') AND source_id IN ({$this->placeholders($sourceIds)})",
            $sourceIds
        )));

        $trigger = in_array($trigger, ['cron', 'manual', 'batch', 'import'], true) ? $trigger : 'cron';
        $added = 0;
        foreach ($sourceIds as $sourceId) {
            if (isset($open[$sourceId])) {
                continue;
            }
            $this->db->execute(
                "INSERT INTO {$this->table('feeds_fetch_queue')} (source_id, status, trigger_type, created_at) VALUES (?, 'pending', ?, ?)",
                [$sourceId, $trigger, self::now()]
            );
            $added++;
        }

        return $added;
    }

    /** Setzt hängende Aufträge (Prozess abgebrochen) nach `$minutes` Minuten zurück auf `pending`. */
    public function releaseStale(int $minutes = 20): int
    {
        return $this->db->execute(
            "UPDATE {$this->table('feeds_fetch_queue')}
             SET status = 'pending', claim_token = NULL, started_at = NULL,
                 error = 'Zeitüberschreitung: Auftrag wurde erneut eingereiht.'
             WHERE status = 'processing' AND started_at IS NOT NULL AND started_at < ?",
            [self::now(-max(5, $minutes) * 60)]
        )->rowCount();
    }

    /**
     * Beansprucht bis zu `$limit` offene Aufträge atomar für diesen Lauf (Claim-Token).
     *
     * @return list<array<string, mixed>>
     */
    public function claim(int $limit): array
    {
        $token = bin2hex(random_bytes(16));
        $this->db->execute(
            "UPDATE {$this->table('feeds_fetch_queue')}
             SET status = 'processing', claim_token = ?, started_at = ?, attempts = attempts + 1
             WHERE status = 'pending'
             ORDER BY id ASC
             LIMIT ?",
            [$token, self::now(), max(1, min(25, $limit))]
        );

        return $this->rows(
            "SELECT q.*, s.name AS source_name, s.feed_url
             FROM {$this->table('feeds_fetch_queue')} q
             INNER JOIN {$this->table('feeds_sources')} s ON s.id = q.source_id
             WHERE q.claim_token = ?
             ORDER BY q.id ASC",
            [$token]
        );
    }

    public function finishTask(int $taskId, bool $success, int $newItems = 0, ?string $error = null): void
    {
        $this->db->execute(
            "UPDATE {$this->table('feeds_fetch_queue')}
             SET status = ?, items_new = ?, error = ?, processed_at = ?, claim_token = NULL
             WHERE id = ?",
            [$success ? 'done' : 'failed', max(0, $newItems), $error !== null ? mb_substr($error, 0, 500) : null, self::now(), $taskId]
        );
    }

    /**
     * Protokolliert einen direkten Abruf (ohne Queue-Durchlauf) als erledigten Auftrag.
     */
    public function logFetch(int $sourceId, string $trigger, bool $success, int $newItems, ?string $error): void
    {
        $now = self::now();
        $this->db->execute(
            "INSERT INTO {$this->table('feeds_fetch_queue')}
                (source_id, status, trigger_type, attempts, items_new, error, created_at, started_at, processed_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)",
            [$sourceId, $success ? 'done' : 'failed', $trigger, max(0, $newItems), $error !== null ? mb_substr($error, 0, 500) : null, $now, $now, $now]
        );
    }

    /** @return array{pending:int,processing:int,done:int,failed:int,total:int} */
    public function queueStats(): array
    {
        $stats = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'total' => 0];
        foreach ($this->rows("SELECT status, COUNT(*) AS cnt FROM {$this->table('feeds_fetch_queue')} GROUP BY status") as $row) {
            $status = (string) $row['status'];
            if (array_key_exists($status, $stats)) {
                $stats[$status] = (int) $row['cnt'];
            }
            $stats['total'] += (int) $row['cnt'];
        }

        return $stats;
    }

    /**
     * Abrufprotokoll (Queue-Einträge) mit Quellenname, neueste zuerst.
     *
     * @param array{status?:string,source_id?:int} $filters
     * @return list<array<string, mixed>>
     */
    public function queueLog(array $filters = [], int $offset = 0, int $limit = 50): array
    {
        [$where, $params] = $this->queueWhere($filters);
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);

        return $this->rows(
            "SELECT q.*, s.name AS source_name, s.feed_url
             FROM {$this->table('feeds_fetch_queue')} q
             LEFT JOIN {$this->table('feeds_sources')} s ON s.id = q.source_id
             WHERE {$where}
             ORDER BY q.id DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /** @param array{status?:string,source_id?:int} $filters */
    public function countQueueLog(array $filters = []): int
    {
        [$where, $params] = $this->queueWhere($filters);

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table('feeds_fetch_queue')} q WHERE {$where}", $params);
    }

    /** Entfernt erledigte/fehlgeschlagene Aufträge, die älter als `$days` Tage sind. */
    public function cleanupQueue(int $days): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table('feeds_fetch_queue')}
             WHERE status IN ('done', 'failed') AND COALESCE(processed_at, created_at) < ?",
            [self::now(-max(1, $days) * 86400)]
        )->rowCount();
    }

    /** Fehlgeschlagene Aufträge erneut einreihen. */
    public function retryFailed(): int
    {
        $sourceIds = $this->ids($this->db->get_col(
            "SELECT DISTINCT q.source_id FROM {$this->table('feeds_fetch_queue')} q
             INNER JOIN {$this->table('feeds_sources')} s ON s.id = q.source_id AND s.is_active = 1
             WHERE q.status = 'failed'"
        ));

        return $this->enqueue($sourceIds, 'manual');
    }

    /** Offene (noch nicht gestartete) Aufträge verwerfen. */
    public function clearPending(): int
    {
        return $this->db->execute("DELETE FROM {$this->table('feeds_fetch_queue')} WHERE status = 'pending'")->rowCount();
    }

    // ── Kennzahlen ──────────────────────────────────────────────────────────

    /** @return array<string, int> */
    public function stats(): array
    {
        $row = $this->row(
            "SELECT
                (SELECT COUNT(*) FROM {$this->table('feeds_categories')}) AS categories,
                (SELECT COUNT(*) FROM {$this->table('feeds_sources')}) AS sources,
                (SELECT COUNT(*) FROM {$this->table('feeds_sources')} WHERE is_active = 1) AS sources_active,
                (SELECT COUNT(*) FROM {$this->table('feeds_sources')} WHERE last_error IS NOT NULL AND last_error <> '') AS sources_errors,
                (SELECT COUNT(*) FROM {$this->table('feeds_items')} WHERE is_hidden = 0) AS items,
                (SELECT COUNT(*) FROM {$this->table('feeds_items')} WHERE is_hidden = 0 AND pub_date >= ?) AS items_today,
                (SELECT COUNT(*) FROM {$this->table('feeds_digests')} WHERE is_active = 1) AS digests",
            [self::now(-86400)]
        ) ?? [];

        $stats = array_map('intval', $row);
        $stats['subscriptions'] = $this->countSubscriptions(true);

        return $stats;
    }

    /** @return array{active:int,with_errors:int,never_fetched:int,overdue:int} */
    public function health(): array
    {
        $health = ['active' => 0, 'with_errors' => 0, 'never_fetched' => 0, 'overdue' => 0];
        $now = time();
        foreach ($this->rows("SELECT fetch_interval, last_fetched_at, last_error FROM {$this->table('feeds_sources')} WHERE is_active = 1") as $row) {
            $health['active']++;
            if (trim((string) ($row['last_error'] ?? '')) !== '') {
                $health['with_errors']++;
            }
            if (empty($row['last_fetched_at'])) {
                $health['never_fetched']++;
            }
            if (self::isOverdue($row, $now)) {
                $health['overdue']++;
            }
        }

        return $health;
    }

    /**
     * Quellen mit Handlungsbedarf (Fehler, nie abgerufen, überfällig), Fehler zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function attentionSources(int $limit = 5): array
    {
        $now = time();
        $rows = array_values(array_filter(
            $this->sources(['status' => 'active']),
            static fn (array $row): bool => trim((string) ($row['last_error'] ?? '')) !== ''
                || empty($row['last_fetched_at'])
                || self::isOverdue($row, $now)
        ));

        usort($rows, static function (array $a, array $b): int {
            $rank = static fn (array $row): int => trim((string) ($row['last_error'] ?? '')) !== '' ? 0 : (empty($row['last_fetched_at']) ? 1 : 2);

            return [$rank($a), (string) ($a['last_fetched_at'] ?? ''), (string) $a['name']]
                <=> [$rank($b), (string) ($b['last_fetched_at'] ?? ''), (string) $b['name']];
        });

        return array_slice($rows, 0, max(1, $limit));
    }

    /** @param array<string, mixed> $source */
    public static function isOverdue(array $source, ?int $now = null): bool
    {
        $last = !empty($source['last_fetched_at']) ? strtotime((string) $source['last_fetched_at']) : false;
        if ($last === false) {
            return true;
        }

        return ($now ?? time()) - $last > max((int) ($source['fetch_interval'] ?? 60) * 2, 60) * 60;
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function itemWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['public'])) {
            $where[] = 'i.is_hidden = 0';
            $where[] = 'c.is_public = 1';
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'visible') {
            $where[] = 'i.is_hidden = 0';
        } elseif ($status === 'hidden') {
            $where[] = 'i.is_hidden = 1';
        } elseif ($status === 'featured') {
            $where[] = 'i.is_featured = 1';
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'i.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }
        if (!empty($filters['source_id'])) {
            $where[] = 'i.source_id = ?';
            $params[] = (int) $filters['source_id'];
        }
        foreach (['source_ids' => 'i.source_id', 'category_ids' => 'i.category_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $ids = $this->ids((array) $filters[$key]);
                if ($ids === []) {
                    $where[] = '1 = 0';
                    continue;
                }
                $where[] = "{$column} IN ({$this->placeholders($ids)})";
                array_push($params, ...$ids);
            }
        }
        if (!empty($filters['since'])) {
            $where[] = 'i.pub_date >= ?';
            $params[] = (string) $filters['since'];
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $where[] = '(i.title LIKE ? OR i.description LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function sourceWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['category_id'])) {
            $where[] = 's.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'active') {
            $where[] = 's.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 's.is_active = 0';
        } elseif ($status === 'error') {
            $where[] = "s.last_error IS NOT NULL AND s.last_error <> ''";
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $where[] = '(s.name LIKE ? OR s.feed_url LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function queueWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['pending', 'processing', 'done', 'failed'], true)) {
            $where[] = 'q.status = ?';
            $params[] = $status;
        }
        if (!empty($filters['source_id'])) {
            $where[] = 'q.source_id = ?';
            $params[] = (int) $filters['source_id'];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, $values),
            static fn (int $id): bool => $id > 0
        )));
    }

    /** @param list<mixed> $values */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        return $this->db->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $params = []): ?array
    {
        $row = $this->db->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
