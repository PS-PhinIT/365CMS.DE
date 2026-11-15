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
 * Beiträge und Seiten: Beitragsbild, Inhalt (DE/EN, Editor.js oder HTML), Auszug.
 */
final class ContentTableReferenceExtractor implements MediaReferenceExtractor
{
    public function __construct(
        private readonly string $type,
        private readonly string $label,
        private readonly string $table,
        private readonly string $editUrl,
        /** @var list<string> Auszugsspalten (Seiten haben kein `excerpt_en`) */
        private readonly array $excerptColumns = ['excerpt'],
    ) {
    }

    public static function posts(): self
    {
        return new self('post', 'Beitrag', 'posts', '/admin/posts?action=edit&id=', ['excerpt', 'excerpt_en']);
    }

    public static function pages(): self
    {
        return new self('page', 'Seite', 'pages', '/admin/pages?action=edit&id=', ['excerpt']);
    }

    public function contentType(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function ids(int $afterId, int $limit): array
    {
        $db = Database::instance();

        return array_map('intval', $db->get_col(
            "SELECT id FROM {$db->getPrefix()}{$this->table} WHERE id > ? ORDER BY id ASC LIMIT " . max(1, min(1000, $limit)),
            [$afterId]
        ));
    }

    public function load(int $id): ?array
    {
        $db = Database::instance();
        $excerpts = array_values(array_intersect($this->excerptColumns, ['excerpt', 'excerpt_en']));
        $row = $db->get_row(
            "SELECT id, title, slug, featured_image, content, content_en" . ($excerpts !== [] ? ', ' . implode(', ', $excerpts) : '')
                . " FROM {$db->getPrefix()}{$this->table} WHERE id = ? LIMIT 1",
            [$id]
        );
        if ($row === null) {
            return null;
        }

        return [
            'title' => self::title((string) ($row->title ?? ''), (string) ($row->slug ?? ''), $this->label, $id),
            'edit_url' => $this->editUrl . $id,
            'values' => [
                ['context' => 'featured', 'value' => (string) ($row->featured_image ?? '')],
                ['context' => 'content', 'value' => (string) ($row->content ?? '')],
                ['context' => 'content', 'value' => (string) ($row->excerpt ?? '')],
                ['context' => 'content_en', 'value' => (string) ($row->content_en ?? '')],
                ['context' => 'content_en', 'value' => (string) ($row->excerpt_en ?? '')],
            ],
        ];
    }

    public static function title(string $title, string $slug, string $label, int $id): string
    {
        $title = trim($title);
        if ($title !== '') {
            return $title;
        }

        return trim($slug) !== '' ? trim($slug) : $label . ' #' . $id;
    }
}
