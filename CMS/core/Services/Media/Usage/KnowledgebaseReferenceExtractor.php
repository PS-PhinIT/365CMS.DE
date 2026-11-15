<?php
/**
 * Release-Prüfung 4.1.0 (FUN-410-19): Wissensdatenbank-Einträge im Medien-Verwendungsnachweis.
 * Alle Inhaltsspalten (Editor.js oder HTML) und der Tooltip-Text werden nach Medienpfaden durchsucht.
 */

declare(strict_types=1);

namespace CMS\Services\Media\Usage;

use CMS\Database;
use CMS\Services\Media\MediaReferenceExtractor;

if (!defined('ABSPATH')) {
    exit;
}

final class KnowledgebaseReferenceExtractor implements MediaReferenceExtractor
{
    private const CONTENT_COLUMNS = ['excerpt', 'content', 'content_details', 'content_benefits', 'content_practice', 'content_notes', 'content_license', 'content_conclusion', 'content_links', 'tooltip_text'];

    public function contentType(): string
    {
        return 'kb_entry';
    }

    public function label(): string
    {
        return 'Wissensdatenbank-Eintrag';
    }

    public function ids(int $afterId, int $limit): array
    {
        $db = Database::instance();
        if (!$db->tableExists('kb_entries')) {
            return [];
        }

        return array_map('intval', $db->get_col(
            "SELECT id FROM {$db->getPrefix()}kb_entries WHERE id > ? ORDER BY id ASC LIMIT " . max(1, min(1000, $limit)),
            [$afterId]
        ));
    }

    public function load(int $id): ?array
    {
        $db = Database::instance();
        $row = $db->get_row(
            'SELECT id, title, slug, ' . implode(', ', self::CONTENT_COLUMNS) . " FROM {$db->getPrefix()}kb_entries WHERE id = ? LIMIT 1",
            [$id]
        );
        if ($row === null) {
            return null;
        }

        $values = [];
        foreach (self::CONTENT_COLUMNS as $column) {
            $values[] = ['context' => 'content', 'value' => (string) ($row->{$column} ?? '')];
        }

        return [
            'title' => ContentTableReferenceExtractor::title((string) ($row->title ?? ''), (string) ($row->slug ?? ''), $this->label(), $id),
            'edit_url' => '/admin/knowledgebase?section=edit&id=' . $id,
            'values' => $values,
        ];
    }
}
