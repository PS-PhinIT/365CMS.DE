<?php
/**
 * Gemeinsame Grundlage der Such-Provider für Inhaltstabellen mit Sprachspalten (`title_en`, `content_en` …).
 *
 * Liefert nur öffentlich sichtbare Einträge (veröffentlicht, Veröffentlichungszeitpunkt erreicht, nicht im
 * Papierkorb). Für Sprachen außer Deutsch gilt je Feld die Sprachspalte und – wie im Frontend – die
 * deutsche Fassung als Ersatz, wenn die Sprachspalte leer ist oder fehlt. Der Inhalt wird über
 * `SiteSearchService::plainText()` in reinen Text umgewandelt (Editor.js-JSON und HTML).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Search;

use CMS\Database;
use CMS\Logger;
use CMS\Services\ContentTypes\SearchDocument;
use CMS\Services\ContentTypes\SearchDocumentProvider;
use CMS\Services\SiteSearchService;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractTableSearchProvider implements SearchDocumentProvider, RendersOwnSearchResults, ReportsChangedDocuments
{
    private const EXCERPT_LENGTH = 300;
    private const MAX_BATCH = 500;

    protected readonly Database $db;

    /** @var array<string, true>|null */
    private ?array $columns = null;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /** Tabellenname ohne Präfix. */
    abstract protected function table(): string;

    /**
     * Zusätzliche Bedingung (Alias `c`) für die Verfügbarkeit in einer Sprache.
     *
     * @return string|null null = Sprache wird für diesen Typ nicht angeboten
     */
    abstract protected function localeCondition(string $locale): ?string;

    /**
     * Wurzelrelativer Pfad der öffentlichen Seite.
     *
     * @param array<string, mixed> $row
     */
    abstract protected function buildUrl(array $row, string $locale): string;

    public function countDocuments(string $locale): int
    {
        $where = $this->visibility($locale);
        if ($where === null) {
            return 0;
        }

        try {
            return (int) $this->db->get_var(
                'SELECT COUNT(*) FROM ' . $this->tableName() . ' c WHERE ' . $where['sql'],
                $where['params']
            );
        } catch (\Throwable $e) {
            $this->logFailure('count', $locale, $e);

            return 0;
        }
    }

    public function documents(string $locale, int $cursor, int $limit): array
    {
        $where = $this->visibility($locale);
        if ($where === null) {
            return [];
        }

        $limit = max(1, min(self::MAX_BATCH, $limit));
        $sql = 'SELECT ' . $this->selectList($locale) . ' FROM ' . $this->tableName() . ' c WHERE ' . $where['sql']
            . ' AND c.id > ? ORDER BY c.id ASC LIMIT ' . $limit;

        try {
            $rows = $this->db->execute($sql, [...$where['params'], max(0, $cursor)])->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->logFailure('documents', $locale, $e);
            throw new \RuntimeException('Suchdokumente konnten nicht gelesen werden.', 0, $e);
        }

        $documents = [];
        foreach ($rows as $row) {
            $documents[] = $this->toDocument($row, $locale);
        }

        return $documents;
    }

    public function document(int $id, string $locale): ?SearchDocument
    {
        $where = $this->visibility($locale);
        if ($where === null || $id <= 0) {
            return null;
        }

        try {
            $row = $this->db->execute(
                'SELECT ' . $this->selectList($locale) . ' FROM ' . $this->tableName() . ' c WHERE ' . $where['sql'] . ' AND c.id = ? LIMIT 1',
                [...$where['params'], $id]
            )->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->logFailure('document', $locale, $e);
            throw new \RuntimeException('Suchdokument konnte nicht gelesen werden.', 0, $e);
        }

        return is_array($row) ? $this->toDocument($row, $locale) : null;
    }

    public function changedDocumentIds(string $since, string $until, int $limit): array
    {
        $conditions = [];
        $params = [];
        if ($this->hasColumn('updated_at')) {
            $conditions[] = 'c.updated_at >= ?';
            $params[] = $since;
        }
        if ($this->hasColumn('published_at')) {
            $conditions[] = '(c.published_at > ? AND c.published_at <= ?)';
            $params[] = $since;
            $params[] = $until;
        }
        if ($conditions === []) {
            return [];
        }

        try {
            $ids = $this->db->get_col(
                'SELECT c.id FROM ' . $this->tableName() . ' c WHERE ' . implode(' OR ', $conditions)
                . ' ORDER BY c.id ASC LIMIT ' . max(1, $limit),
                $params
            );
        } catch (\Throwable $e) {
            $this->logFailure('changed', '', $e);

            return [];
        }

        return array_values(array_map('intval', $ids));
    }

    protected function tableName(): string
    {
        return $this->db->getPrefix() . $this->table();
    }

    protected function hasColumn(string $column): bool
    {
        if ($this->columns === null) {
            $this->columns = [];
            try {
                foreach ($this->db->get_col(
                    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [$this->tableName()]
                ) as $name) {
                    $this->columns[strtolower((string) $name)] = true;
                }
            } catch (\Throwable $e) {
                $this->logFailure('columns', '', $e);
            }
        }

        return isset($this->columns[strtolower($column)]);
    }

    /**
     * Spalten in Prioritätsreihenfolge: Sprachspalte (falls vorhanden), dann die deutsche Fassung.
     *
     * @return list<string>
     */
    protected function localizedColumns(string $field, string $locale): array
    {
        $columns = [];
        if ($locale !== 'de' && $this->hasColumn($field . '_' . $locale)) {
            $columns[] = $field . '_' . $locale;
        }
        if ($this->hasColumn($field)) {
            $columns[] = $field;
        }

        return $columns;
    }

    protected static function translate(string $key, string $fallback): string
    {
        if (!function_exists('__')) {
            return $fallback;
        }

        $translated = (string) __($key);

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }

    /**
     * @return array{sql:string, params:list<mixed>}|null
     */
    private function visibility(string $locale): ?array
    {
        if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) !== 1 || !$this->hasColumn('id')) {
            return null;
        }

        $localeCondition = $this->localeCondition($locale);
        if ($localeCondition === null) {
            return null;
        }

        $conditions = ["c.status = 'published'"];
        $params = [];
        if ($this->hasColumn('published_at')) {
            $conditions[] = '(c.published_at IS NULL OR c.published_at <= ?)';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($this->hasColumn('trashed_at')) {
            $conditions[] = 'c.trashed_at IS NULL';
        }
        if ($localeCondition !== '') {
            $conditions[] = '(' . $localeCondition . ')';
        }

        return ['sql' => implode(' AND ', $conditions), 'params' => $params];
    }

    private function selectList(string $locale): string
    {
        $columns = ['id'];
        foreach (['title', 'excerpt', 'content', 'slug'] as $field) {
            array_push($columns, ...$this->localizedColumns($field, $locale));
        }
        foreach (['published_at', 'created_at', 'updated_at', 'content_updated_at'] as $column) {
            if ($this->hasColumn($column)) {
                $columns[] = $column;
            }
        }
        // Für die Beitrags-URL (PermalinkService) zusätzlich den englischen Slug.
        if ($this->hasColumn('slug_en')) {
            $columns[] = 'slug_en';
        }

        return implode(', ', array_map(
            static fn (string $column): string => 'c.' . $column,
            array_values(array_unique($columns))
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toDocument(array $row, string $locale): SearchDocument
    {
        $title = trim(strip_tags($this->firstValue($row, $this->localizedColumns('title', $locale))));
        $content = SiteSearchService::plainText($this->firstValue($row, $this->localizedColumns('content', $locale)));
        $excerpt = SiteSearchService::plainText($this->firstValue($row, $this->localizedColumns('excerpt', $locale)));
        if ($excerpt === '') {
            $excerpt = $content;
        }
        $excerpt = trim((string) preg_replace('/\s+/u', ' ', $excerpt));
        if (mb_strlen($excerpt) > self::EXCERPT_LENGTH) {
            $excerpt = rtrim(mb_substr($excerpt, 0, self::EXCERPT_LENGTH - 1)) . '…';
        }

        $updatedAt = '';
        foreach (['content_updated_at', 'updated_at', 'published_at', 'created_at'] as $column) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '' && !str_starts_with($value, '0000')) {
                $updatedAt = $value;
                break;
            }
        }

        return new SearchDocument(
            $this->type(),
            (int) $row['id'],
            $locale,
            $title,
            $content,
            $this->buildUrl($row, $locale),
            $excerpt,
            $updatedAt
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $columns
     */
    private function firstValue(array $row, array $columns): string
    {
        foreach ($columns as $column) {
            $value = (string) ($row[$column] ?? '');
            if (trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    private function logFailure(string $operation, string $locale, \Throwable $e): void
    {
        Logger::instance()->withChannel('search')->warning('Such-Provider: Abfrage fehlgeschlagen.', [
            'type' => $this->type(),
            'operation' => $operation,
            'locale' => $locale,
            'exception' => $e,
        ]);
    }
}
