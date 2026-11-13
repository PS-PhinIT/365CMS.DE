<?php
/**
 * Export/Import von Kategorien (`post_categories`, Typ `category`) und Schlagwörtern (`post_tags`, Typ `tag`).
 *
 * Konfliktschlüssel ist der Slug. `skip` ordnet den vorhandenen Begriff zu, `overwrite` übernimmt Name, Beschreibung
 * und englischen Slug, `rename` legt den Begriff mit freiem Slug (`-2`, `-3` …) neu an. Die Zuordnung alte → neue ID
 * landet im Namensraum des Typs (`category`, `tag`) für Beiträge und Seiten.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Exchange;

use CMS\Database;
use CMS\Services\ContentTypes\ExchangeFilter;
use CMS\Services\ContentTypes\ExchangeProvider;
use CMS\Services\ContentTypes\ExportBatch;
use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\ContentTypes\ImportPreview;
use CMS\Services\ContentTypes\ImportResult;

if (!defined('ABSPATH')) {
    exit;
}

final class TaxonomyExchangeProvider implements ExchangeProvider
{
    /** ImportContext-Namensraum: Ziel-ID einer Kategorie => alte ID ihrer noch nicht importierten Oberkategorie. */
    private const PENDING_PARENTS = 'category_parent_pending';

    private Database $db;
    private string $table;

    /**
     * @param 'category'|'tag' $type
     */
    public function __construct(private readonly string $type)
    {
        $this->db = Database::instance();
        $this->table = $this->db->getPrefix() . ($type === 'category' ? 'post_categories' : 'post_tags');
    }

    public function type(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return (string) __($this->type === 'category' ? 'exchange.type.category' : 'exchange.type.tag');
    }

    public function capability(): string
    {
        return 'manage_categories';
    }

    public function importPriority(): int
    {
        return $this->type === 'category' ? 10 : 11;
    }

    public function countExport(ExchangeFilter $filter): int
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table}");
    }

    public function exportBatch(ExchangeFilter $filter, int $cursor, int $limit): ExportBatch
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->execute("SELECT * FROM {$this->table} WHERE id > ? ORDER BY id ASC LIMIT ?", [$cursor, $limit + 1])->fetchAll(\PDO::FETCH_ASSOC);
        $done = count($rows) <= $limit;
        $rows = array_slice($rows, 0, $limit);
        $records = [];
        $next = $cursor;
        foreach ($rows as $row) {
            $next = (int) $row['id'];
            $record = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'slug_en' => (string) ($row['slug_en'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
            ];
            if ($this->type === 'category') {
                $record['parent_id'] = (int) ($row['parent_id'] ?? 0);
                $record['sort_order'] = (int) ($row['sort_order'] ?? 0);
            }
            $records[] = $record;
        }

        return new ExportBatch($records, [], $next, $done);
    }

    public function preview(array $record, ImportContext $context): ImportPreview
    {
        $name = ExchangeSupport::text($record['name'] ?? '', 150);
        $slug = ExchangeSupport::slug((string) ($record['slug'] ?? ''), $name);
        if ($name === '' || $slug === '') {
            return new ImportPreview(ImportPreview::ACTION_INVALID, $name !== '' ? $name : '#' . (int) ($record['id'] ?? 0), '', (string) __('exchange.error.invalid_record'));
        }

        return $this->findBySlug($slug) !== null
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
        $values = [
            'name' => ExchangeSupport::text($record['name'] ?? '', 150),
            'slug_en' => ExchangeSupport::slug((string) ($record['slug_en'] ?? ''), '') ?: null,
            'description' => ExchangeSupport::text($record['description'] ?? '', 5000, true),
        ];
        if ($this->type === 'category') {
            $parent = (int) ($record['parent_id'] ?? 0);
            $resolvedParent = $parent > 0 ? $context->resolve('category', $parent) : null;
            // Steht die Oberkategorie erst später im Archiv (höhere ID), bleibt parent_id offen und wird
            // nachgetragen, sobald sie importiert ist – statt die Hierarchie still zu verlieren.
            $pendingParent = $parent > 0 && $resolvedParent === null ? $parent : 0;
            if ($pendingParent === 0) {
                $values['parent_id'] = $resolvedParent !== null ? (int) $resolvedParent : null;
            }
            $values['sort_order'] = (int) ($record['sort_order'] ?? 0);
        }

        $status = ImportResult::CREATED;
        if ($preview->action === ImportPreview::ACTION_CONFLICT) {
            $existing = $this->findBySlug($slug);
            if ($existing !== null && $context->conflictStrategy === ImportContext::STRATEGY_SKIP) {
                $context->map($this->type, $oldId, (int) $existing['id']);
                $this->applyPendingParents($context, $oldId, (int) $existing['id']);

                return new ImportResult(ImportResult::SKIPPED, (int) $existing['id'], $preview->message);
            }
            if ($existing !== null && $context->conflictStrategy === ImportContext::STRATEGY_OVERWRITE) {
                $this->db->update($this->shortTable(), $values, ['id' => (int) $existing['id']]);
                $context->map($this->type, $oldId, (int) $existing['id']);
                $this->rememberPendingParent($context, (int) $existing['id'], $pendingParent ?? 0);
                $this->applyPendingParents($context, $oldId, (int) $existing['id']);

                return new ImportResult(ImportResult::UPDATED, (int) $existing['id']);
            }
            $slug = $this->freeSlug($slug);
            $status = ImportResult::RENAMED;
        }

        $values['slug'] = $slug;
        $newId = $this->db->insert($this->shortTable(), $values);
        if (!is_int($newId) || $newId <= 0) {
            return new ImportResult(ImportResult::FAILED, null, (string) __('exchange.error.save'));
        }
        $context->map($this->type, $oldId, $newId);
        $this->rememberPendingParent($context, $newId, $pendingParent ?? 0);
        $this->applyPendingParents($context, $oldId, $newId);

        return new ImportResult($status, $newId, $status === ImportResult::RENAMED ? sprintf((string) __('exchange.result.renamed'), $slug) : '');
    }

    private function rememberPendingParent(ImportContext $context, int $categoryId, int $oldParentId): void
    {
        if ($this->type === 'category' && $oldParentId > 0) {
            $context->map(self::PENDING_PARENTS, $categoryId, $oldParentId);
        }
    }

    /**
     * Trägt die gerade importierte Kategorie bei allen Unterkategorien nach, die vor ihr im Archiv standen.
     */
    private function applyPendingParents(ImportContext $context, int $oldId, int $targetId): void
    {
        if ($this->type !== 'category') {
            return;
        }

        foreach (self::pendingChildrenOf($context->mapOf(self::PENDING_PARENTS), $oldId, $targetId) as $childId) {
            $this->db->update($this->shortTable(), ['parent_id' => $targetId], ['id' => $childId]);
            $context->map(self::PENDING_PARENTS, $childId, 0);
        }
    }

    /**
     * @param array<string|int, string|int> $pending Ziel-ID der Unterkategorie => alte ID der Oberkategorie (0 = erledigt)
     * @return list<int> Ziel-IDs der Unterkategorien, die auf $oldParentId warten (ohne Selbstbezug)
     */
    public static function pendingChildrenOf(array $pending, int $oldParentId, int $targetId): array
    {
        $children = [];
        foreach ($pending as $childId => $waitingFor) {
            if ($oldParentId > 0 && (int) $waitingFor === $oldParentId && (int) $childId > 0 && (int) $childId !== $targetId) {
                $children[] = (int) $childId;
            }
        }

        return $children;
    }

    private function shortTable(): string
    {
        return $this->type === 'category' ? 'post_categories' : 'post_tags';
    }

    /** @return array<string, mixed>|null */
    private function findBySlug(string $slug): ?array
    {
        $row = $this->db->execute("SELECT id FROM {$this->table} WHERE slug = ? LIMIT 1", [$slug])->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function freeSlug(string $slug): string
    {
        for ($n = 2; $n < 1000; $n++) {
            $candidate = mb_substr($slug, 0, 180) . '-' . $n;
            if ($this->findBySlug($candidate) === null) {
                return $candidate;
            }
        }

        return $slug . '-' . bin2hex(random_bytes(3));
    }
}
