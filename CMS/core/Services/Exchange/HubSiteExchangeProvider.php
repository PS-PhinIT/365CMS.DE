<?php
/**
 * Export/Import von Hub-Sites und Site-Tabellen (`site_tables`, Typ `hub_site`).
 *
 * Konfliktschlüssel ist die Adresse (`table_slug`). Domains werden nicht übernommen (sie gehören zur Ziel-Installation
 * und würden sonst Anfragen umleiten); der Import meldet entfernte Domains. Medienadressen in Karten und Einstellungen
 * werden umgeschrieben.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Exchange;

use CMS\Database;
use CMS\Json;
use CMS\Services\ContentTypes\ExchangeFilter;
use CMS\Services\ContentTypes\ExchangeProvider;
use CMS\Services\ContentTypes\ExportBatch;
use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\ContentTypes\ImportPreview;
use CMS\Services\ContentTypes\ImportResult;

if (!defined('ABSPATH')) {
    exit;
}

final class HubSiteExchangeProvider implements ExchangeProvider
{
    public const TYPE = 'hub_site';

    private Database $db;
    private string $table;

    public function __construct()
    {
        $this->db = Database::instance();
        $this->table = $this->db->getPrefix() . 'site_tables';
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return (string) __('exchange.type.hub_site');
    }

    public function capability(): string
    {
        return 'manage_pages';
    }

    public function importPriority(): int
    {
        return 60;
    }

    public function countExport(ExchangeFilter $filter): int
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table} WHERE trashed_at IS NULL");
    }

    public function exportBatch(ExchangeFilter $filter, int $cursor, int $limit): ExportBatch
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->db->execute(
            "SELECT id, table_name, table_slug, description, columns_json, rows_json, settings_json FROM {$this->table}
             WHERE trashed_at IS NULL AND id > ? ORDER BY id ASC LIMIT ?",
            [$cursor, $limit + 1]
        )->fetchAll(\PDO::FETCH_ASSOC);
        $done = count($rows) <= $limit;
        $rows = array_slice($rows, 0, $limit);
        $next = $cursor;
        foreach ($rows as &$row) {
            $next = (int) $row['id'];
            $row['id'] = (int) $row['id'];
        }
        unset($row);

        return new ExportBatch($rows, [], $next, $done);
    }

    public function preview(array $record, ImportContext $context): ImportPreview
    {
        $name = ExchangeSupport::text($record['table_name'] ?? '', 255);
        $slug = ExchangeSupport::slug((string) ($record['table_slug'] ?? ''), $name);
        if ($name === '' || $slug === '') {
            return new ImportPreview(ImportPreview::ACTION_INVALID, $name !== '' ? $name : '#' . (int) ($record['id'] ?? 0), '', (string) __('exchange.error.invalid_record'));
        }

        return $this->idBySlug($slug) !== null
            ? new ImportPreview(ImportPreview::ACTION_CONFLICT, $name, $slug, (string) __('exchange.preview.slug_exists'))
            : new ImportPreview(ImportPreview::ACTION_CREATE, $name, $slug);
    }

    public function import(array $record, ImportContext $context): ImportResult
    {
        $preview = $this->preview($record, $context);
        if ($preview->action === ImportPreview::ACTION_INVALID) {
            return new ImportResult(ImportResult::FAILED, null, $preview->message);
        }

        $oldId = (int) ($record['id'] ?? 0);
        $slug = $preview->conflictKey;
        $status = ImportResult::CREATED;
        $targetId = 0;
        if ($preview->action === ImportPreview::ACTION_CONFLICT) {
            $existing = $this->idBySlug($slug);
            if ($existing !== null && $context->conflictStrategy === ImportContext::STRATEGY_SKIP) {
                $context->map(self::TYPE, $oldId, $existing);

                return new ImportResult(ImportResult::SKIPPED, $existing, $preview->message);
            }
            if ($existing !== null && $context->conflictStrategy === ImportContext::STRATEGY_OVERWRITE) {
                $targetId = $existing;
                $status = ImportResult::UPDATED;
            } else {
                $slug = $this->freeSlug($slug);
                $status = ImportResult::RENAMED;
            }
        }

        $settings = Json::decodeArray(is_string($record['settings_json'] ?? null) ? $record['settings_json'] : '', []);
        $droppedDomains = array_values(array_filter(array_map('strval', (array) ($settings['hub_domains'] ?? []))));
        $settings['hub_domains'] = [];
        if (($settings['content_mode'] ?? '') === 'hub') {
            $settings['hub_slug'] = $slug;
        }
        $jsonField = static function (mixed $value) use ($context): ?string {
            if (!is_string($value) || !is_array(json_decode($value, true))) {
                return null;
            }

            return ExchangeSupport::rewriteMedia($value, $context);
        };

        $values = [
            'table_name' => ExchangeSupport::text($record['table_name'] ?? '', 255),
            'table_slug' => $slug,
            'description' => ExchangeSupport::text($record['description'] ?? '', 5000, true),
            'columns_json' => $jsonField($record['columns_json'] ?? null),
            'rows_json' => $jsonField($record['rows_json'] ?? null),
            'settings_json' => ExchangeSupport::rewriteMedia((string) json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $context),
        ];

        if ($targetId > 0) {
            $ok = $this->db->update('site_tables', $values, ['id' => $targetId]);
            $newId = $ok ? $targetId : 0;
        } else {
            $inserted = $this->db->insert('site_tables', $values);
            $newId = is_int($inserted) ? $inserted : 0;
        }
        if ($newId <= 0) {
            return new ImportResult(ImportResult::FAILED, null, (string) __('exchange.error.save'));
        }
        $context->map(self::TYPE, $oldId, $newId);

        $messages = [];
        if ($status === ImportResult::RENAMED) {
            $messages[] = sprintf((string) __('exchange.result.renamed'), $slug);
        }
        if ($droppedDomains !== []) {
            $messages[] = sprintf((string) __('exchange.result.domains_dropped'), implode(', ', $droppedDomains));
        }

        return new ImportResult($status, $newId, implode(' ', $messages));
    }

    private function idBySlug(string $slug): ?int
    {
        $id = $this->db->get_var("SELECT id FROM {$this->table} WHERE table_slug = ? LIMIT 1", [$slug]);

        return $id !== null ? (int) $id : null;
    }

    private function freeSlug(string $slug): string
    {
        for ($n = 2; $n < 1000; $n++) {
            $candidate = mb_substr($slug, 0, 180) . '-' . $n;
            if ($this->idBySlug($candidate) === null) {
                return $candidate;
            }
        }

        return $slug . '-' . bin2hex(random_bytes(3));
    }
}
