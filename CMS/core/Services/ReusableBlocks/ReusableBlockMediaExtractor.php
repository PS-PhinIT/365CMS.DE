<?php
/**
 * Verwendungsnachweis für Medien (Bereich 2b): Bilder und Dateien in wiederverwendbaren Blöcken (Bereich 3b).
 * Inhalte, die den Block einbinden, enthalten nur `{ "id": … }`; die Medien zählen beim Block selbst.
 *
 * @package CMSv2\Core\Services\ReusableBlocks
 */

declare(strict_types=1);

namespace CMS\Services\ReusableBlocks;

use CMS\Database;
use CMS\Services\Media\MediaReferenceExtractor;

if (!defined('ABSPATH')) {
    exit;
}

final class ReusableBlockMediaExtractor implements MediaReferenceExtractor
{
    public function contentType(): string
    {
        return ReusableBlockService::CONTENT_TYPE;
    }

    public function label(): string
    {
        return 'Block-Vorlage';
    }

    public function ids(int $afterId, int $limit): array
    {
        $db = Database::instance();

        return array_map('intval', $db->get_col(
            "SELECT id FROM {$db->getPrefix()}reusable_blocks WHERE id > ? AND status = ? ORDER BY id ASC LIMIT " . max(1, min(1000, $limit)),
            [$afterId, ReusableBlockService::STATUS_ACTIVE]
        ));
    }

    public function load(int $id): ?array
    {
        $block = ReusableBlockService::getInstance()->find($id);
        if ($block === null) {
            return null;
        }

        return [
            'title' => $block['title'] !== '' ? $block['title'] : 'Vorlage #' . $id,
            'edit_url' => '/admin/reusable-blocks?action=edit&id=' . $id,
            'values' => [
                ['context' => 'content', 'value' => $block['content']],
                ['context' => 'content_en', 'value' => (string) ($block['content_en'] ?? '')],
            ],
        ];
    }
}
