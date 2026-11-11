<?php
/**
 * Papierkorb-Provider für Hub-Sites (`site_tables` mit `content_mode = hub`).
 *
 * Hub-Sites werden weich gelöscht (`trashed_at`/`trashed_by`); alle Daten – Einstellungen inkl. CSS/JS,
 * Karten, Spalten, Template-Zuordnung, Domains – bleiben in der Zeile und kommen beim Wiederherstellen
 * vollständig zurück. Slug und Zusatzdomains bleiben während der Papierkorb-Zeit für die Hub-Site reserviert;
 * Auflösung (Slug/Domain), Listen und Auswahlfelder ignorieren Papierkorb-Einträge. Endgültiges Löschen
 * entfernt die Zeile und feuert `hub_deleted`.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Trash;

use CMS\AuditLogger;
use CMS\Database;
use CMS\Hooks;
use CMS\Json;
use CMS\Logger;
use CMS\Services\ContentTypes\TrashItem;
use CMS\Services\ContentTypes\TrashResult;

if (!defined('ABSPATH')) {
    exit;
}

final class HubSiteTrashProvider implements TrashableProvider
{
    public const TYPE = 'hub_site';

    public const DELETED_HOOK = 'hub_deleted';

    private const HUB_WHERE = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.settings_json, '$.content_mode')), 'table') = 'hub'";

    private const SLUG_MAX_LENGTH = 191;

    private readonly Database $db;
    private readonly string $prefix;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return TrashSupport::t('trash.type.hub_site');
    }

    public function capability(): string
    {
        return 'manage_settings';
    }

    public function countTrashed(string $search = ''): int
    {
        [$where, $params] = $this->trashWhere($search);

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}site_tables s WHERE {$where}", $params);
    }

    public function listTrashed(int $offset, int $limit, string $search = ''): array
    {
        [$where, $params] = $this->trashWhere($search);
        $params[] = max(1, min(500, $limit));
        $params[] = max(0, $offset);

        $rows = $this->db->get_results(
            "SELECT s.id, s.table_name, s.table_slug, s.settings_json, s.rows_json, s.trashed_at, s.trashed_by,
                    u.display_name AS trashed_by_name, u.username AS trashed_by_username
             FROM {$this->prefix}site_tables s
             LEFT JOIN {$this->prefix}users u ON u.id = s.trashed_by
             WHERE {$where}
             ORDER BY s.trashed_at DESC, s.id DESC
             LIMIT ? OFFSET ?",
            $params
        ) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            $settings = Json::decodeArray($row->settings_json ?? null, []);
            $title = trim((string) ($row->table_name ?? ''));
            $trashedByName = trim((string) ($row->trashed_by_name ?? ''));
            if ($trashedByName === '') {
                $trashedByName = trim((string) ($row->trashed_by_username ?? ''));
            }

            $items[] = new TrashItem(
                self::TYPE,
                $id,
                $title !== '' ? $title : '#' . $id,
                TrashSupport::timestamp($row->trashed_at ?? null),
                ($row->trashed_by ?? null) !== null ? (int) $row->trashed_by : null,
                '',
                '',
                [
                    'slug' => $this->slugOf($settings, (string) ($row->table_slug ?? '')),
                    'domains' => $this->domainsOf($settings),
                    'card_count' => count(Json::decodeArray($row->rows_json ?? null, [])),
                    'trashed_by_name' => $trashedByName,
                ]
            );
        }

        return $items;
    }

    public function isTrashed(int $id): bool
    {
        $row = $this->fetchHub($id);

        return $row !== null && $row['trashed_at'] !== '';
    }

    public function trash(int $id, int $userId): TrashResult
    {
        $hub = $this->fetchHub($id);
        if ($hub === null) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, TrashSupport::t('trash.error.not_found'));
        }

        if ($hub['trashed_at'] !== '') {
            return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.already_trashed'));
        }

        try {
            $statement = $this->db->execute(
                "UPDATE {$this->prefix}site_tables SET trashed_at = ?, trashed_by = ?, updated_at = updated_at WHERE id = ? AND trashed_at IS NULL LIMIT 1",
                [date('Y-m-d H:i:s'), $userId > 0 ? $userId : null, $id]
            );
            if ($statement->rowCount() < 1) {
                return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.state_changed'));
            }
        } catch (\Throwable $e) {
            return $this->error('trash', $id, $e);
        }

        $this->afterVisibilityChange($hub, 'trash');
        $this->audit('trash', sprintf('Hub-Site „%s“ in den Papierkorb verschoben.', $hub['title']), $id, [
            'slug' => $hub['slug'],
            'domains' => $hub['domains'],
            'user_id' => $userId,
        ]);

        return TrashResult::success(TrashSupport::t('trash.msg.trashed', $hub['title']), ['id' => $id]);
    }

    public function restore(int $id, int $userId): TrashResult
    {
        $hub = $this->fetchHub($id);
        if ($hub === null) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, TrashSupport::t('trash.error.not_found'));
        }

        if ($hub['trashed_at'] === '') {
            return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.not_in_trash'));
        }

        $originalSlug = $hub['slug'];
        $slug = TrashSlug::resolve(
            $originalSlug,
            fn (string $candidate): bool => $this->isSlugTaken($candidate, $id),
            self::SLUG_MAX_LENGTH,
            (string) $id
        );

        $takenDomains = $this->domainsAssignedElsewhere($id);
        $keptDomains = [];
        $droppedDomains = [];
        foreach ($hub['domains'] as $domain) {
            if (isset($takenDomains[strtolower($domain)])) {
                $droppedDomains[] = $domain;
                continue;
            }
            $keptDomains[] = $domain;
        }

        $settings = $hub['settings'];
        $settings['hub_slug'] = $slug;
        if ($droppedDomains !== []) {
            $settings['hub_domains'] = $keptDomains;
        }

        try {
            $statement = $this->db->execute(
                "UPDATE {$this->prefix}site_tables
                 SET trashed_at = NULL, trashed_by = NULL, table_slug = ?, settings_json = ?, updated_at = updated_at
                 WHERE id = ? AND trashed_at IS NOT NULL LIMIT 1",
                [$slug, json_encode($settings, JSON_UNESCAPED_UNICODE), $id]
            );
            if ($statement->rowCount() < 1) {
                return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.state_changed'));
            }
        } catch (\Throwable $e) {
            return $this->error('restore', $id, $e);
        }

        $hub['settings'] = $settings;
        $slugChanged = $slug !== $originalSlug;
        $this->afterVisibilityChange($hub, 'restore');
        $this->audit('restore', sprintf('Hub-Site „%s“ aus dem Papierkorb wiederhergestellt.', $hub['title']), $id, [
            'slug' => $slug,
            'original_slug' => $originalSlug,
            'slug_changed' => $slugChanged,
            'dropped_domains' => $droppedDomains,
            'user_id' => $userId,
        ]);

        $message = TrashSupport::t('trash.msg.restored', $hub['title']);
        if ($slugChanged) {
            $message .= ' ' . TrashSupport::t('trash.msg.slug_changed', $originalSlug !== '' ? $originalSlug : '—', $slug);
        }
        if ($droppedDomains !== []) {
            $message .= ' ' . TrashSupport::t('trash.msg.domains_dropped', implode(', ', $droppedDomains));
        }

        return TrashResult::success($message, [
            'id' => $id,
            'slug' => $slug,
            'slug_changed' => $slugChanged,
            'dropped_domains' => $droppedDomains,
        ]);
    }

    public function deletionWarnings(int $id): array
    {
        return [];
    }

    public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult
    {
        $hub = $this->fetchHub($id);
        if ($hub === null) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, TrashSupport::t('trash.error.not_found'));
        }

        if ($hub['trashed_at'] === '') {
            return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.delete_requires_trash'));
        }

        $warnings = $this->deletionWarnings($id);
        if ($warnings !== [] && !$confirmed) {
            return TrashSupport::confirmationRequired($warnings, $hub['title']);
        }

        try {
            $statement = $this->db->execute(
                "DELETE FROM {$this->prefix}site_tables WHERE id = ? AND trashed_at IS NOT NULL LIMIT 1",
                [$id]
            );
            if ($statement->rowCount() < 1) {
                return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.state_changed'));
            }
        } catch (\Throwable $e) {
            return $this->error('delete', $id, $e);
        }

        try {
            Hooks::doAction(self::DELETED_HOOK, $id);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('trash')->warning('Folgeaktionen nach endgültigem Löschen einer Hub-Site fehlgeschlagen.', [
                'id' => $id,
                'exception' => $e->getMessage(),
            ]);
        }
        TrashSupport::clearContentCache($this->db, 'hub_site_trash_delete', $id);
        $this->audit('delete_permanently', sprintf('Hub-Site „%s“ endgültig gelöscht.', $hub['title']), $id, [
            'slug' => $hub['slug'],
            'domains' => $hub['domains'],
            'card_count' => $hub['card_count'],
            'user_id' => $userId,
        ], $userId > 0 ? 'warning' : 'info');

        return TrashResult::success(TrashSupport::t('trash.msg.deleted', $hub['title']), ['id' => $id]);
    }

    public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array
    {
        $ids = $this->db->get_col(
            "SELECT s.id FROM {$this->prefix}site_tables s
             WHERE s.trashed_at IS NOT NULL AND s.trashed_at < ? AND " . self::HUB_WHERE . "
             ORDER BY s.trashed_at ASC, s.id ASC
             LIMIT ?",
            [$trashedBefore->format('Y-m-d H:i:s'), max(1, $limit)]
        );

        return array_values(array_map('intval', $ids));
    }

    /**
     * @return array{id:int,title:string,slug:string,domains:list<string>,settings:array<string,mixed>,cards:array<int|string,mixed>,card_count:int,trashed_at:string}|null
     */
    private function fetchHub(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->db->get_row(
            "SELECT s.id, s.table_name, s.table_slug, s.settings_json, s.rows_json, s.trashed_at
             FROM {$this->prefix}site_tables s
             WHERE s.id = ? AND " . self::HUB_WHERE . ' LIMIT 1',
            [$id]
        );
        if ($row === null) {
            return null;
        }

        $settings = Json::decodeArray($row->settings_json ?? null, []);
        $cards = Json::decodeArray($row->rows_json ?? null, []);
        $title = trim((string) ($row->table_name ?? ''));

        return [
            'id' => (int) $row->id,
            'title' => $title !== '' ? $title : '#' . (int) $row->id,
            'slug' => $this->slugOf($settings, (string) ($row->table_slug ?? '')),
            'domains' => $this->domainsOf($settings),
            'settings' => $settings,
            'cards' => $cards,
            'card_count' => count($cards),
            'trashed_at' => TrashSupport::timestamp($row->trashed_at ?? null),
        ];
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function trashWhere(string $search): array
    {
        $where = 's.trashed_at IS NOT NULL AND ' . self::HUB_WHERE;
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $like = TrashSupport::likePattern($search);
            $where .= ' AND (s.table_name LIKE ? OR s.table_slug LIKE ? OR s.description LIKE ?)';
            $params = [$like, $like, $like];
        }

        return [$where, $params];
    }

    /** Slug belegt durch eine andere Hub-Site (auch im Papierkorb) oder eine Seite? */
    private function isSlugTaken(string $slug, int $ignoreId): bool
    {
        $hubTaken = $this->db->get_var(
            "SELECT s.id FROM {$this->prefix}site_tables s
             WHERE s.id <> ? AND " . self::HUB_WHERE . "
               AND (s.table_slug = ? OR JSON_UNQUOTE(JSON_EXTRACT(s.settings_json, '$.hub_slug')) = ?)
             LIMIT 1",
            [$ignoreId, $slug, $slug]
        );
        if ($hubTaken !== null) {
            return true;
        }

        return $this->db->get_var("SELECT id FROM {$this->prefix}pages WHERE slug = ? LIMIT 1", [$slug]) !== null;
    }

    /**
     * @return array<string, int> Domain (klein geschrieben) => Hub-ID anderer Hub-Sites
     */
    private function domainsAssignedElsewhere(int $ignoreId): array
    {
        $rows = $this->db->get_results(
            "SELECT s.id, s.settings_json FROM {$this->prefix}site_tables s WHERE s.id <> ? AND " . self::HUB_WHERE,
            [$ignoreId]
        ) ?: [];

        $assigned = [];
        foreach ($rows as $row) {
            foreach ($this->domainsOf(Json::decodeArray($row->settings_json ?? null, [])) as $domain) {
                $assigned[strtolower($domain)] = (int) ($row->id ?? 0);
            }
        }

        return $assigned;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function slugOf(array $settings, string $tableSlug): string
    {
        $slug = trim((string) ($settings['hub_slug'] ?? ''));

        return $slug !== '' ? $slug : trim($tableSlug);
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private function domainsOf(array $settings): array
    {
        $domains = is_array($settings['hub_domains'] ?? null) ? $settings['hub_domains'] : [];

        return array_values(array_filter(
            array_map(static fn (mixed $domain): string => is_scalar($domain) ? trim((string) $domain) : '', $domains),
            static fn (string $domain): bool => $domain !== ''
        ));
    }

    /**
     * @param array{id:int,settings:array<string,mixed>,cards:array<int|string,mixed>} $hub
     */
    private function afterVisibilityChange(array $hub, string $reason): void
    {
        try {
            Hooks::doAction('cms_after_hub_save', $hub['id'], $hub['settings'], $hub['cards'], ['trash_action' => $reason]);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('trash')->warning('Folgeaktionen nach Papierkorb-Statuswechsel einer Hub-Site fehlgeschlagen.', [
                'id' => $hub['id'],
                'reason' => $reason,
                'exception' => $e->getMessage(),
            ]);
        }

        TrashSupport::clearContentCache($this->db, 'hub_site_' . $reason, $hub['id']);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, string $description, int $id, array $metadata, string $severity = 'info'): void
    {
        AuditLogger::instance()->log(AuditLogger::CAT_CONTENT, 'hub_sites.' . $action, $description, 'hub_sites', $id, $metadata, $severity);
    }

    private function error(string $action, int $id, \Throwable $e): TrashResult
    {
        Logger::instance()->withChannel('trash')->error('Papierkorb-Aktion für Hub-Site fehlgeschlagen.', [
            'action' => $action,
            'id' => $id,
            'exception_class' => get_debug_type($e),
            'exception' => $e->getMessage(),
        ]);

        return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.error.failed'));
    }
}
