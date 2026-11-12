<?php
/**
 * Inhaltstyp „Knowledgebase-Eintrag“ (`kb_entry`): Papierkorb, Suchindex und Import/Export.
 *
 * - Papierkorb: Löschen im Admin setzt `trashed_at`; der Eintrag verschwindet aus Frontend, Auto-Verlinkung,
 *   Glossar-Sitemap und Suche. Wiederherstellen setzt ihn zurück, endgültiges Löschen entfernt die Zeile.
 * - Suche: aktive Einträge mit Titel, Kurzbeschreibung, Synonymen und allen Inhaltsbereichen als Klartext;
 *   die Inhalte sind einsprachig, die Adresse folgt der Sprache (`/kb/<slug>`, `/en/kb/<slug>`).
 * - Import/Export: alle Felder; Kategorien werden beim Import bei Bedarf angelegt, Editor.js-Inhalte erneut
 *   bereinigt. Konfliktschlüssel ist der Slug.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Knowledgebase;

use CMS\Database;
use CMS\Services\ContentTypes\ExchangeFilter;
use CMS\Services\ContentTypes\ExchangeProvider;
use CMS\Services\ContentTypes\ExportBatch;
use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\ContentTypes\ImportPreview;
use CMS\Services\ContentTypes\ImportResult;
use CMS\Services\ContentTypes\SearchDocument;
use CMS\Services\ContentTypes\SearchDocumentProvider;
use CMS\Services\ContentTypes\TrashItem;
use CMS\Services\ContentTypes\TrashProvider;
use CMS\Services\ContentTypes\TrashResult;
use CMS\Services\Exchange\ExchangeSupport;
use CMS\Services\Search\ReportsChangedDocuments;
use CMS\Services\Knowledgebase\Repository\EntryRepository;
use CMS\Services\Knowledgebase\Service\SectionContent;
use CMS\Services\Knowledgebase\Support\ContentSections;

if (!defined('ABSPATH')) {
    exit;
}

final class KnowledgebaseContentProvider implements TrashProvider, SearchDocumentProvider, ReportsChangedDocuments, ExchangeProvider
{
    public const TYPE = 'kb_entry';
    public const CAPABILITY = 'manage_knowledgebase';

    /** Felder, die Export und Import übertragen (Inhaltsbereiche kommen aus ContentSections). */
    private const SCALAR_FIELDS = ['title', 'keyword', 'slug', 'excerpt', 'tooltip_text', 'synonyms', 'category', 'priority',
        'is_active', 'is_case_sensitive', 'is_whole_word', 'max_links_per_page'];

    private Database $db;
    private string $table;

    public function __construct()
    {
        $this->db = Database::instance();
        $this->table = $this->db->getPrefix() . 'kb_entries';
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return (string) __('knowledgebase.type_label');
    }

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    // ── Papierkorb ──────────────────────────────────────────────────────────

    public function countTrashed(string $search = ''): int
    {
        [$where, $params] = $this->trashWhere($search);

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table} WHERE {$where}", $params);
    }

    public function listTrashed(int $offset, int $limit, string $search = ''): array
    {
        [$where, $params] = $this->trashWhere($search);
        $rows = $this->db->execute(
            "SELECT id, title, slug, category, trashed_at, trashed_by, is_active FROM {$this->table} WHERE {$where}
             ORDER BY trashed_at DESC, id DESC LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)])
        )->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): TrashItem => new TrashItem(
            self::TYPE,
            (int) $row['id'],
            (string) $row['title'],
            (string) ($row['trashed_at'] ?? ''),
            $row['trashed_by'] !== null ? (int) $row['trashed_by'] : null,
            (int) $row['is_active'] === 1 ? 'active' : 'inactive',
            '/admin/knowledgebase?section=edit&id=' . (int) $row['id'],
            ['slug' => (string) $row['slug'], 'category' => (string) ($row['category'] ?? '')]
        ), $rows);
    }

    public function restore(int $id, int $userId): TrashResult
    {
        $slug = (string) ($this->db->get_var("SELECT slug FROM {$this->table} WHERE id = ? AND trashed_at IS NOT NULL", [$id]) ?? '');
        if ($slug === '' || !EntryRepository::instance()->restoreEntry($id)) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, (string) __('knowledgebase.admin.error_not_found'));
        }

        return TrashResult::success((string) __('knowledgebase.admin.entry_restored'), ['slug' => $slug]);
    }

    public function deletionWarnings(int $id): array
    {
        return [];
    }

    public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult
    {
        if ((int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table} WHERE id = ? AND trashed_at IS NOT NULL", [$id]) < 1) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, (string) __('knowledgebase.admin.error_not_found'));
        }
        $result = EntryRepository::instance()->deleteEntry($id);

        return !empty($result['success'])
            ? TrashResult::success((string) __('knowledgebase.admin.entry_deleted'))
            : TrashResult::failure(TrashResult::CODE_ERROR, (string) ($result['error'] ?? ''));
    }

    public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array
    {
        return array_map('intval', $this->db->get_col(
            "SELECT id FROM {$this->table} WHERE trashed_at IS NOT NULL AND trashed_at < ? ORDER BY trashed_at ASC LIMIT ?",
            [$trashedBefore->format('Y-m-d H:i:s'), max(1, $limit)]
        ));
    }

    /** @return array{0:string,1:list<mixed>} */
    private function trashWhere(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['trashed_at IS NOT NULL', []];
        }
        $like = '%' . addcslashes($search, '\\%_') . '%';

        return ['trashed_at IS NOT NULL AND (title LIKE ? OR slug LIKE ? OR keyword LIKE ?)', [$like, $like, $like]];
    }

    // ── Suchindex ───────────────────────────────────────────────────────────

    public function countDocuments(string $locale): int
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table} WHERE is_active = 1 AND trashed_at IS NULL");
    }

    public function documents(string $locale, int $cursor, int $limit): array
    {
        $rows = $this->db->execute(
            "SELECT * FROM {$this->table} WHERE is_active = 1 AND trashed_at IS NULL AND id > ? ORDER BY id ASC LIMIT ?",
            [$cursor, max(1, $limit)]
        )->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_filter(array_map(fn (array $row): ?SearchDocument => $this->toDocument($row, $locale), $rows)));
    }

    /**
     * Für den stündlichen Abgleich: erfasst auch Änderungen ohne Hook (CSV-Pakete, Kategorie-Umbenennung).
     */
    public function changedDocumentIds(string $since, string $until, int $limit): array
    {
        $rows = $this->db->execute(
            "SELECT id FROM {$this->table} WHERE updated_at > ? ORDER BY updated_at ASC LIMIT ?",
            [$since, max(1, $limit)]
        )->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_map('intval', $rows ?: []));
    }

    public function document(int $id, string $locale): ?SearchDocument
    {
        $row = $this->db->execute(
            "SELECT * FROM {$this->table} WHERE id = ? AND is_active = 1 AND trashed_at IS NULL LIMIT 1",
            [$id]
        )->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->toDocument($row, $locale) : null;
    }

    /** @param array<string, mixed> $row */
    private function toDocument(array $row, string $locale): ?SearchDocument
    {
        $slug = trim((string) ($row['slug'] ?? ''));
        $title = trim((string) ($row['title'] ?? ''));
        if ($slug === '' || $title === '') {
            return null;
        }
        $body = trim(implode(' ', array_filter([
            (string) ($row['keyword'] ?? ''),
            str_replace(["\r", "\n", ',', ';', '|'], ' ', (string) ($row['synonyms'] ?? '')),
            strip_tags((string) ($row['excerpt'] ?? '')),
            SectionContent::instance()->plainText($row),
        ])));
        $path = '/kb/' . rawurlencode($slug);

        return new SearchDocument(
            self::TYPE,
            (int) $row['id'],
            $locale,
            $title,
            $body,
            (str_starts_with(strtolower($locale), 'en') ? '/en' : '') . $path,
            trim(strip_tags((string) ($row['excerpt'] ?? ''))),
            (string) ($row['updated_at'] ?? '')
        );
    }

    // ── Import/Export ───────────────────────────────────────────────────────

    public function importPriority(): int
    {
        return 55;
    }

    public function countExport(ExchangeFilter $filter): int
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table} WHERE trashed_at IS NULL");
    }

    public function exportBatch(ExchangeFilter $filter, int $cursor, int $limit): ExportBatch
    {
        $limit = max(1, min(200, $limit));
        $columns = implode(', ', array_merge(['id', 'updated_at'], self::SCALAR_FIELDS, ContentSections::columns()));
        $rows = $this->db->execute(
            "SELECT {$columns} FROM {$this->table} WHERE trashed_at IS NULL AND id > ? ORDER BY id ASC LIMIT ?",
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
        $title = ExchangeSupport::text($record['title'] ?? '', 255);
        $slug = $this->importSlug($record, $title);
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

        $slug = $preview->conflictKey;
        $existing = $this->idBySlug($slug);
        $status = ImportResult::CREATED;
        $input = [];
        if ($existing !== null) {
            if ($context->conflictStrategy === ImportContext::STRATEGY_SKIP) {
                return new ImportResult(ImportResult::SKIPPED, $existing, $preview->message);
            }
            if ($context->conflictStrategy === ImportContext::STRATEGY_OVERWRITE) {
                $input['entry_id'] = $existing;
                $status = ImportResult::UPDATED;
            } else {
                $slug = $this->freeSlug($slug);
                $status = ImportResult::RENAMED;
            }
        }

        foreach (self::SCALAR_FIELDS as $field) {
            $input[$field] = is_scalar($record[$field] ?? null) ? (string) $record[$field] : '';
        }
        $input['slug'] = $slug;
        foreach (['is_active', 'is_case_sensitive', 'is_whole_word'] as $flag) {
            $input[$flag] = $input[$flag] === '1' ? '1' : '0';
        }
        if ($context->importAsDraft) {
            $input['is_active'] = '0';
        }
        foreach (ContentSections::columns() as $column) {
            $input[$column] = ExchangeSupport::rewriteMedia(ExchangeSupport::editorJson($record[$column] ?? ''), $context);
        }

        $result = EntryRepository::instance()->saveEntry($input, true);
        $newId = (int) ($result['id'] ?? 0);
        if (empty($result['success']) || $newId <= 0) {
            return new ImportResult(ImportResult::FAILED, null, (string) ($result['error'] ?? __('exchange.error.save')));
        }
        $context->map(self::TYPE, (int) ($record['id'] ?? 0), $newId);

        return new ImportResult($status, $newId, $status === ImportResult::RENAMED ? sprintf((string) __('exchange.result.renamed'), $slug) : '');
    }

    /** @param array<string, mixed> $record */
    private function importSlug(array $record, string $title): string
    {
        $slug = mb_strtolower(trim(is_scalar($record['slug'] ?? null) ? (string) $record['slug'] : ''), 'UTF-8');
        if ($slug !== '' && preg_match('/^[\p{L}\p{N}-]{1,190}$/u', $slug) === 1) {
            return $slug;
        }

        return ExchangeSupport::slug('', $title);
    }

    private function idBySlug(string $slug): ?int
    {
        $id = $this->db->get_var("SELECT id FROM {$this->table} WHERE slug = ? LIMIT 1", [$slug]);

        return $id !== null ? (int) $id : null;
    }

    private function freeSlug(string $slug): string
    {
        for ($n = 2; $n < 1000; $n++) {
            $candidate = mb_substr($slug, 0, 180, 'UTF-8') . '-' . $n;
            if ($this->idBySlug($candidate) === null) {
                return $candidate;
            }
        }

        return $slug . '-' . bin2hex(random_bytes(3));
    }
}
