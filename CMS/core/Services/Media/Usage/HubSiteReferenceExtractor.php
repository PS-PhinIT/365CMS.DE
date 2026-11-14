<?php
/**
 * Extraktor des Cores für den Verwendungsindex (4.1.0, Bereich 2b). Nur lesende Abfragen mit gebundenen Parametern.
 *
 * @package CMSv2\Core\Services\Media\Usage
 */

declare(strict_types=1);

namespace CMS\Services\Media\Usage;

use CMS\Database;
use CMS\Services\Media\MediaReferenceExtractor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hub-Sites (`site_tables` mit `content_mode = hub`): Einstellungen und Karten.
 */
final class HubSiteReferenceExtractor implements MediaReferenceExtractor
{
    private const HUB_CONDITION = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.content_mode')), 'table') = 'hub'";

    public function contentType(): string
    {
        return 'hub_site';
    }

    public function label(): string
    {
        return 'Hub-Site';
    }

    public function ids(int $afterId, int $limit): array
    {
        $db = Database::instance();

        return array_map('intval', $db->get_col(
            "SELECT id FROM {$db->getPrefix()}site_tables WHERE id > ? AND " . self::HUB_CONDITION . ' ORDER BY id ASC LIMIT ' . max(1, min(1000, $limit)),
            [$afterId]
        ));
    }

    public function load(int $id): ?array
    {
        $db = Database::instance();
        $row = $db->get_row(
            "SELECT id, table_name, description, rows_json, settings_json FROM {$db->getPrefix()}site_tables WHERE id = ? AND " . self::HUB_CONDITION . ' LIMIT 1',
            [$id]
        );
        if ($row === null) {
            return null;
        }

        return [
            'title' => ContentTableReferenceExtractor::title((string) ($row->table_name ?? ''), '', 'Hub-Site', $id),
            'edit_url' => '/admin/hub-sites?action=edit&id=' . $id,
            'values' => [
                ['context' => 'setting', 'value' => (string) ($row->settings_json ?? '')],
                ['context' => 'content', 'value' => (string) ($row->rows_json ?? '')],
                ['context' => 'content', 'value' => (string) ($row->description ?? '')],
            ],
        ];
    }
}
