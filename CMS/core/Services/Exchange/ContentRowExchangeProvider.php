<?php
/**
 * Export/Import von Seiten (`pages`, Typ `page`) und Beiträgen (`posts`, Typ `post`) samt Übersetzung (EN-Spalten),
 * SEO-Feldern, Kategorien und Schlagwörtern.
 *
 * Filter: Status und Zeitraum (Änderungsdatum). Inhalte im Papierkorb werden nicht exportiert.
 * Import: Konfliktschlüssel ist der Slug; `skip` ordnet zu, `overwrite` überschreibt die vorhandene Seite bzw. den
 * Beitrag, `rename` legt mit freiem Slug an. Autoren über die Autorenzuordnung, Kategorien/Schlagwörter über die
 * zuvor importierten Begriffe, Medienadressen werden umgeschrieben und Editor.js-Inhalte erneut bereinigt.
 * „Als Entwurf importieren“ setzt den Status auf `draft`. Nach dem Speichern laufen die normalen Speicher-Hooks
 * (Suchindex, Verwendungen, Übersetzungsstatus).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Exchange;

use CMS\Database;
use CMS\Hooks;
use CMS\Services\ContentTypes\ExchangeFilter;
use CMS\Services\ContentTypes\ExchangeProvider;
use CMS\Services\ContentTypes\ExportBatch;
use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\ContentTypes\ImportPreview;
use CMS\Services\ContentTypes\ImportResult;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentRowExchangeProvider implements ExchangeProvider
{
    public const STATUSES = ['published', 'draft', 'private', 'scheduled', 'pending'];

    /** Spalten, die exportiert werden (sofern vorhanden). */
    private const COLUMNS = [
        'page' => ['title', 'title_en', 'slug', 'slug_en', 'content', 'content_en', 'excerpt', 'status', 'hide_title', 'show_title_toc',
            'featured_image', 'meta_title', 'meta_description', 'page_template', 'page_meta_json', 'author_id', 'category_id',
            'created_at', 'updated_at', 'published_at', 'planned_at'],
        'post' => ['title', 'title_en', 'slug', 'slug_en', 'content', 'content_en', 'excerpt', 'excerpt_en', 'featured_image', 'status',
            'author_id', 'author_display_name', 'author_display_url', 'post_template', 'post_meta_json', 'category_id', 'tags',
            'allow_comments', 'meta_title', 'meta_description', 'created_at', 'updated_at', 'published_at', 'planned_at'],
    ];

    private Database $db;
    private string $prefix;
    /** @var list<string>|null */
    private ?array $columns = null;

    /** @param 'page'|'post' $type */
    public function __construct(private readonly string $type)
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function type(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return (string) __('exchange.type.' . $this->type);
    }

    public function capability(): string
    {
        return $this->type === 'page' ? 'manage_pages' : 'edit_all_posts';
    }

    public function importPriority(): int
    {
        return $this->type === 'page' ? 50 : 51;
    }

    public function countExport(ExchangeFilter $filter): int
    {
        [$where, $params] = $this->where($filter);

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table()} WHERE {$where}", $params);
    }

    public function exportBatch(ExchangeFilter $filter, int $cursor, int $limit): ExportBatch
    {
        $limit = max(1, min(200, $limit));
        [$where, $params] = $this->where($filter);
        $columns = implode(', ', array_merge(['id'], $this->columns()));
        $rows = $this->db->execute(
            "SELECT {$columns} FROM {$this->table()} WHERE {$where} AND id > ? ORDER BY id ASC LIMIT ?",
            array_merge($params, [$cursor, $limit + 1])
        )->fetchAll(\PDO::FETCH_ASSOC);
        $done = count($rows) <= $limit;
        $rows = array_slice($rows, 0, $limit);

        $records = [];
        $next = $cursor;
        foreach ($rows as $row) {
            $next = (int) $row['id'];
            $row['id'] = (int) $row['id'];
            if ($this->type === 'post') {
                $row['category_ids'] = array_map('intval', $this->db->get_col("SELECT category_id FROM {$this->prefix}post_category_rel WHERE post_id = ?", [$row['id']]) ?: []);
                $row['tag_ids'] = array_map('intval', $this->db->get_col("SELECT tag_id FROM {$this->prefix}post_tag_rel WHERE post_id = ?", [$row['id']]) ?: []);
            }
            $records[] = $row;
        }

        return new ExportBatch($records, [], $next, $done);
    }

    public function preview(array $record, ImportContext $context): ImportPreview
    {
        $title = ExchangeSupport::text($record['title'] ?? '', 255);
        $slug = ExchangeSupport::slug((string) ($record['slug'] ?? ''), $title);
        if ($title === '' || $slug === '') {
            return new ImportPreview(ImportPreview::ACTION_INVALID, $title !== '' ? $title : '#' . (int) ($record['id'] ?? 0), '', (string) __('exchange.error.invalid_record'));
        }

        return $this->idBySlug($slug) !== null
            ? new ImportPreview(ImportPreview::ACTION_CONFLICT, $title, $slug, (string) __('exchange.preview.slug_exists'))
            : new ImportPreview(ImportPreview::ACTION_CREATE, $title, $slug);
    }

    public function import(array $record, ImportContext $context): ImportResult
    {
        $preview = $this->preview($record, $context);
        if ($preview->action === ImportPreview::ACTION_INVALID) {
            return new ImportResult(ImportResult::FAILED, null, $preview->message);
        }

        $oldId = (int) ($record['id'] ?? 0);
        $values = $this->values($record, $context);
        $slug = $preview->conflictKey;
        $status = ImportResult::CREATED;
        $targetId = 0;

        if ($preview->action === ImportPreview::ACTION_CONFLICT) {
            $existing = $this->idBySlug($slug);
            if ($existing !== null && $context->conflictStrategy === ImportContext::STRATEGY_SKIP) {
                $context->map($this->type, $oldId, $existing);

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
        $values['slug'] = $slug;
        if (!empty($values['slug_en']) && $this->slugEnTaken((string) $values['slug_en'], $targetId)) {
            $values['slug_en'] = null;
        }

        try {
            if ($targetId > 0) {
                unset($values['created_at']);
                $values['updated_at'] = date('Y-m-d H:i:s');
                $this->db->update($this->type === 'page' ? 'pages' : 'posts', $values, ['id' => $targetId]);
                $newId = $targetId;
            } else {
                $newId = $this->db->insert($this->type === 'page' ? 'pages' : 'posts', $values);
                if (!is_int($newId) || $newId <= 0) {
                    return new ImportResult(ImportResult::FAILED, null, (string) __('exchange.error.save'));
                }
            }
            if ($this->type === 'post') {
                $this->syncTerms($newId, $record, $context);
            }
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('exchange')->warning('Import eines Inhalts fehlgeschlagen.', ['type' => $this->type, 'exception' => $e->getMessage()]);

            return new ImportResult(ImportResult::FAILED, null, (string) __('exchange.error.save'));
        }

        $context->map($this->type, $oldId, $newId);
        try {
            Hooks::doAction($this->type === 'page' ? 'cms_after_page_save' : 'cms_after_post_save', $newId, $values, []);
        } catch (\Throwable) {
        }

        return new ImportResult($status, $newId, $status === ImportResult::RENAMED ? sprintf((string) __('exchange.result.renamed'), $slug) : '');
    }

    // ── Intern ──────────────────────────────────────────────────────────────

    private function table(): string
    {
        return $this->prefix . ($this->type === 'page' ? 'pages' : 'posts');
    }

    /** @return list<string> */
    private function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }
        $existing = [];
        foreach ($this->db->execute("SHOW COLUMNS FROM {$this->table()}")->fetchAll(\PDO::FETCH_ASSOC) as $column) {
            $existing[(string) $column['Field']] = true;
        }

        return $this->columns = array_values(array_filter(self::COLUMNS[$this->type], static fn (string $column): bool => isset($existing[$column])));
    }

    /** @return array{0:string,1:list<mixed>} */
    private function where(ExchangeFilter $filter): array
    {
        $where = ['trashed_at IS NULL'];
        $params = [];
        $statuses = array_values(array_intersect(self::STATUSES, $filter->statuses));
        if ($statuses !== []) {
            $where[] = 'status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        if ($filter->dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter->dateFrom) === 1) {
            $where[] = 'updated_at >= ?';
            $params[] = $filter->dateFrom . ' 00:00:00';
        }
        if ($filter->dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter->dateTo) === 1) {
            $where[] = 'updated_at <= ?';
            $params[] = $filter->dateTo . ' 23:59:59';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function values(array $record, ImportContext $context): array
    {
        $values = [];
        foreach ($this->columns() as $column) {
            if (!array_key_exists($column, $record)) {
                continue;
            }
            $value = $record[$column];
            $values[$column] = match ($column) {
                'title', 'title_en', 'meta_title', 'author_display_name' => ExchangeSupport::text($value, 255),
                'slug_en' => ExchangeSupport::slug((string) $value, '') ?: null,
                'content', 'content_en' => ExchangeSupport::editorJson(ExchangeSupport::rewriteMedia(is_string($value) ? $value : '', $context)),
                'excerpt', 'excerpt_en', 'meta_description' => ExchangeSupport::text($value, 5000, true),
                'featured_image' => mb_substr(ExchangeSupport::rewriteMedia(ExchangeSupport::text($value, 500), $context), 0, 500),
                'author_display_url' => filter_var($value, FILTER_VALIDATE_URL) !== false ? mb_substr((string) $value, 0, 500) : '',
                'page_meta_json', 'post_meta_json' => is_string($value) && is_array(json_decode($value, true)) ? ExchangeSupport::rewriteMedia($value, $context) : null,
                'status' => in_array($value, self::STATUSES, true) ? $value : 'draft',
                'author_id' => $context->authorFor((int) $value),
                'category_id' => (int) $value > 0 ? ($context->resolve('category', (int) $value) ?? null) : null,
                'hide_title', 'show_title_toc', 'allow_comments' => (int) (bool) $value,
                'page_template', 'post_template' => preg_replace('/[^a-z0-9_\-]/i', '', (string) $value) ?: null,
                'tags' => ExchangeSupport::text($value, 500),
                'created_at', 'updated_at', 'published_at', 'planned_at' => self::dateTime($value),
                default => $value,
            };
        }
        if ($context->importAsDraft) {
            $values['status'] = 'draft';
        }
        if (($values['created_at'] ?? null) === null) {
            unset($values['created_at']);
        }

        return $values;
    }

    /** @param array<string, mixed> $record */
    private function syncTerms(int $postId, array $record, ImportContext $context): void
    {
        $categories = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) ($context->resolve('category', (int) $id) ?? 0),
            is_array($record['category_ids'] ?? null) ? $record['category_ids'] : []
        ))));
        $tags = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) ($context->resolve('tag', (int) $id) ?? 0),
            is_array($record['tag_ids'] ?? null) ? $record['tag_ids'] : []
        ))));

        $this->db->execute("DELETE FROM {$this->prefix}post_category_rel WHERE post_id = ?", [$postId]);
        foreach ($categories as $categoryId) {
            $this->db->execute("INSERT INTO {$this->prefix}post_category_rel (post_id, category_id) VALUES (?, ?)", [$postId, $categoryId]);
        }
        $this->db->execute("DELETE FROM {$this->prefix}post_tag_rel WHERE post_id = ?", [$postId]);
        foreach ($tags as $tagId) {
            $this->db->execute("INSERT INTO {$this->prefix}post_tag_rel (post_id, tag_id) VALUES (?, ?)", [$postId, $tagId]);
        }
    }

    private function idBySlug(string $slug): ?int
    {
        $id = $this->db->get_var("SELECT id FROM {$this->table()} WHERE slug = ? LIMIT 1", [$slug]);

        return $id !== null ? (int) $id : null;
    }

    private function slugEnTaken(string $slugEn, int $exceptId): bool
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table()} WHERE slug_en = ? AND id <> ?", [$slugEn, $exceptId]) > 0;
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

    private static function dateTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $time = strtotime($value);

        return $time !== false ? date('Y-m-d H:i:s', $time) : null;
    }
}
