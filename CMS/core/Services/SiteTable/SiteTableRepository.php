<?php
declare(strict_types=1);

namespace CMS\Services\SiteTable;

use CMS\Database;
use CMS\Json;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteTableRepository
{
    /**
     * Hub-Zeilen je Request: Router (404/Domain-Erkennung) und Hub-Renderer fragen die
     * Domain-Zuordnung mehrfach ab – ohne Cache je Aufruf ein Full-Scan inkl. rows_json.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $hubRowsCache = null;

    /** @var array<string, array<string, mixed>|null> Hub-Tabellen je Slug (Request-Cache, auch Fehltreffer). */
    private array $hubBySlugCache = [];

    public function __construct(
        private Database $db,
        private string $prefix,
    ) {
    }

    public function getTableById(int $tableId): ?array
    {
        if ($tableId <= 0) {
            return null;
        }

        $row = $this->db->fetchOne(
            "SELECT id, table_name, description, columns_json, rows_json, settings_json, updated_at, table_slug
             FROM {$this->prefix}site_tables
             WHERE id = ? LIMIT 1",
            [$tableId]
        );

        return is_array($row) ? $this->hydrateTable($row) : null;
    }

    public function getHubTableBySlug(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        if (array_key_exists($slug, $this->hubBySlugCache)) {
            return $this->hubBySlugCache[$slug];
        }

        // table_slug ist im Schema garantiert, indiziert und wird mit hub_slug abgeglichen
        // (SchemaManager::ensureSiteTableSlugs()); Suche daher über den Index statt JSON_EXTRACT.
        $row = $this->db->fetchOne(
            "SELECT id, table_name, description, columns_json, rows_json, settings_json, updated_at, table_slug
             FROM {$this->prefix}site_tables
             WHERE table_slug = ?
               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.content_mode')), 'table') = 'hub'
             LIMIT 1",
            [$slug]
        );

        return $this->hubBySlugCache[$slug] = is_array($row) ? $this->hydrateTable($row) : null;
    }

    public function getHubTableByDomain(string $domain): ?array
    {
        $domain = $this->normalizeDomainHost($domain);
        if ($domain === '') {
            return null;
        }

        if ($this->hubRowsCache === null) {
            $rows = $this->db->fetchAll(
                "SELECT id, table_name, description, columns_json, rows_json, settings_json, updated_at, table_slug
                 FROM {$this->prefix}site_tables
                 WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.content_mode')), 'table') = 'hub'"
            );
            $this->hubRowsCache = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        }

        foreach ($this->hubRowsCache as $row) {
            if (!is_array($row)) {
                continue;
            }

            $settings = Json::decodeArray($row['settings_json'] ?? null, []);
            $domains = is_array($settings['hub_domains'] ?? null) ? $settings['hub_domains'] : [];
            foreach ($domains as $candidate) {
                if ($this->normalizeDomainHost((string)$candidate) === $domain) {
                    return $this->hydrateTable($row);
                }
            }
        }

        return null;
    }

    public function getStoredTemplateProfiles(string $settingKey): array
    {
        $row = $this->db->fetchOne(
            "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
            [$settingKey]
        );

        if (!is_array($row) || empty($row['option_value'])) {
            return [];
        }

        $stored = Json::decodeArray($row['option_value'] ?? null, []);

        return is_array($stored) ? $stored : [];
    }

    private function hydrateTable(array $row): array
    {
        $tableSlug = trim((string) ($row['table_slug'] ?? ''));
        $settings = Json::decodeArray($row['settings_json'] ?? null, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        if ($tableSlug !== '' && trim((string) ($settings['hub_slug'] ?? '')) === '') {
            $settings['hub_slug'] = $tableSlug;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['table_name'] ?? 'Tabelle'),
            'description' => trim((string) ($row['description'] ?? '')),
            'columns' => Json::decodeArray($row['columns_json'] ?? null, []),
            'rows' => Json::decodeArray($row['rows_json'] ?? null, []),
            'settings' => $settings,
            'table_slug' => $tableSlug,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function normalizeDomainHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $candidate = preg_match('#^https?://#i', $value) === 1 ? $value : 'https://' . ltrim($value, '/');
        $parts = parse_url($candidate);
        if ($parts === false) {
            return '';
        }

        $host = strtolower(trim((string)($parts['host'] ?? ''), '.'));
        if ($host === '') {
            return '';
        }

        return $host;
    }
}
