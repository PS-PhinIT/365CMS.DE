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
 * Downloads: Titelbild und Beschreibungen (Dateiversionen liegen im privaten Speicher, nicht in der Mediathek).
 */
final class DownloadReferenceExtractor implements MediaReferenceExtractor
{
    public function contentType(): string
    {
        return 'download';
    }

    public function label(): string
    {
        return 'Download';
    }

    public function ids(int $afterId, int $limit): array
    {
        $db = Database::instance();

        return array_map('intval', $db->get_col(
            "SELECT id FROM {$db->getPrefix()}downloads_items WHERE id > ? ORDER BY id ASC LIMIT " . max(1, min(1000, $limit)),
            [$afterId]
        ));
    }

    public function load(int $id): ?array
    {
        $db = Database::instance();
        $row = $db->get_row(
            "SELECT id, title, slug, cover_media, summary, description FROM {$db->getPrefix()}downloads_items WHERE id = ? LIMIT 1",
            [$id]
        );
        if ($row === null) {
            return null;
        }

        return [
            'title' => ContentTableReferenceExtractor::title((string) ($row->title ?? ''), (string) ($row->slug ?? ''), 'Download', $id),
            'edit_url' => '/admin/downloads?action=edit&id=' . $id,
            'values' => [
                ['context' => 'featured', 'value' => (string) ($row->cover_media ?? '')],
                ['context' => 'content', 'value' => (string) ($row->summary ?? '')],
                ['context' => 'content', 'value' => (string) ($row->description ?? '')],
            ],
        ];
    }
}
