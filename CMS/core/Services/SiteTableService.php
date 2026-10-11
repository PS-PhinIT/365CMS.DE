<?php
/**
 * SiteTableService – Frontend-Rendering und Export für Seitentabellen.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Services\SiteTable\SiteTableHubRenderer;
use CMS\Services\SiteTable\SiteTableRepository;
use CMS\Services\SiteTable\SiteTableTableRenderer;
use CMS\Services\SiteTable\SiteTableTemplateRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteTableService
{
    private static ?self $instance = null;

    /** @var array<int,int> */
    private array $renderStack = [];

    private SiteTableRepository $repository;

    private SiteTableTemplateRegistry $templateRegistry;

    private SiteTableHubRenderer $hubRenderer;

    private SiteTableTableRenderer $tableRenderer;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $db = Database::instance();
        $this->repository = new SiteTableRepository($db, $db->getPrefix());
        $this->templateRegistry = new SiteTableTemplateRegistry($this->repository);
        $this->hubRenderer = new SiteTableHubRenderer($this->templateRegistry);
        $this->tableRenderer = new SiteTableTableRenderer($this->templateRegistry);
    }

    public function replaceShortcodes(string $content): string
    {
        if (!str_contains($content, '[site-table') && !str_contains($content, '[table') && !str_contains($content, '[hub-site')) {
            return $content;
        }

        $content = (string) preg_replace_callback(
            '/\[(?:site-table|table)\s+id\s*=\s*["\']?(\d+)["\']?\s*\/?\]/i',
            fn(array $matches): string => $this->renderTableById((int) ($matches[1] ?? 0)),
            $content
        );

        return (string) preg_replace_callback(
            '/\[hub-site\s+id\s*=\s*["\']?(\d+)["\']?\s*\]/i',
            fn(array $matches): string => $this->renderHubById((int) ($matches[1] ?? 0)),
            $content
        );
    }

    public function renderTableById(int $tableId): string
    {
        if ($tableId <= 0 || in_array($tableId, $this->renderStack, true)) {
            return '';
        }

        $this->renderStack[] = $tableId;

        try {
        $table = $this->repository->getTableById($tableId);
        if ($table === null) {
            return '';
        }

        if ($this->isHubTable($table)) {
            return $this->hubRenderer->renderHubMarkup($table);
        }

        return $this->tableRenderer->renderTable($tableId, $table);
        } finally {
            array_pop($this->renderStack);
        }
    }

    public function renderHubById(int $tableId): string
    {
        if ($tableId <= 0 || in_array($tableId, $this->renderStack, true)) {
            return '';
        }

        $this->renderStack[] = $tableId;

        try {
            $table = $this->repository->getTableById($tableId);
            if ($table === null || !$this->isHubTable($table)) {
                return '';
            }

            return $this->hubRenderer->renderHubMarkup($table);
        } finally {
            array_pop($this->renderStack);
        }
    }

    public function getHubPageBySlug(string $slug, string $locale = 'de'): ?array
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        $table = $this->repository->getHubTableBySlug($slug);
        if ($table === null) {
            return null;
        }

        $page = $this->hubRenderer->buildHubPage($table, $slug, $locale);
        $page['updated_at'] = $this->resolveTableLastModified(
            $table,
            (string) ($page['updated_at'] ?? ($table['updated_at'] ?? ''))
        );

        return $page;
    }

    public function getHubPageByDomain(string $domain, string $locale = 'de'): ?array
    {
        $table = $this->repository->getHubTableByDomain($domain);
        if ($table === null || !$this->isHubTable($table)) {
            return null;
        }

        $slug = trim((string)($table['settings']['hub_slug'] ?? ($table['table_slug'] ?? '')));
        if ($slug === '') {
            return null;
        }

        $page = $this->hubRenderer->buildHubPage($table, $slug, $locale);
        $page['updated_at'] = $this->resolveTableLastModified(
            $table,
            (string) ($page['updated_at'] ?? ($table['updated_at'] ?? ''))
        );

        return $page;
    }

    public function hubExistsBySlug(string $slug): bool
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '') {
            return false;
        }

        return $this->repository->getHubTableBySlug($slug) !== null;
    }

    public function streamExportById(int $tableId, string $format, bool $respectFrontendPermissions = true): bool
    {
        $table = $this->repository->getTableById($tableId);
        if ($table === null) {
            return false;
        }

        return $this->tableRenderer->streamExport($table, $format, $respectFrontendPermissions, [$this, 'sanitizeSlug']);
    }

    public function resolveContentLastModified(string $content, string $fallback = ''): string
    {
        $latest = $this->normalizeTimestamp($fallback);
        $visited = [];

        foreach ($this->extractEmbeddedTableIds($content) as $tableId) {
            $latest = $this->maxTimestamp($latest, $this->resolveTableDependencyTimestamp($tableId, $visited));
        }

        if ($latest === null) {
            return $fallback;
        }

        return date('Y-m-d H:i:s', $latest);
    }

    public function resolveTableLastModified(array $table, string $fallback = ''): string
    {
        $tableId = (int) ($table['id'] ?? 0);
        $baseValue = $fallback !== ''
            ? $fallback
            : (string) ($table['updated_at'] ?? $table['created_at'] ?? '');
        $latest = $this->normalizeTimestamp($baseValue);

        if ($tableId > 0) {
            $visited = [];
            $latest = $this->maxTimestamp($latest, $this->resolveTableDependencyTimestamp($tableId, $visited));
        }

        if ($latest === null) {
            return $baseValue;
        }

        return date('Y-m-d H:i:s', $latest);
    }

    private function isHubTable(array $table): bool
    {
        return (($table['settings']['content_mode'] ?? 'table') === 'hub');
    }

    /** @return list<int> */
    private function extractEmbeddedTableIds(string $content): array
    {
        if ($content === '') {
            return [];
        }

        preg_match_all('/\[(?:site-table|table|hub-site)\s+id\s*=\s*["\']?(\d+)["\']?\s*\/?\]/i', $content, $matches);
        $ids = [];

        foreach ($matches[1] ?? [] as $rawId) {
            $tableId = (int) $rawId;
            if ($tableId > 0) {
                $ids[$tableId] = $tableId;
            }
        }

        return array_values($ids);
    }

    /** @return list<int> */
    private function collectEmbeddedTableIdsFromValue(mixed $value): array
    {
        $ids = [];

        if (is_string($value)) {
            foreach ($this->extractEmbeddedTableIds($value) as $tableId) {
                $ids[$tableId] = $tableId;
            }

            return array_values($ids);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                foreach ($this->collectEmbeddedTableIdsFromValue($item) as $tableId) {
                    $ids[$tableId] = $tableId;
                }
            }
        }

        return array_values($ids);
    }

    private function resolveTableDependencyTimestamp(int $tableId, array &$visited): ?int
    {
        if ($tableId <= 0 || isset($visited[$tableId])) {
            return null;
        }

        $visited[$tableId] = true;
        $table = $this->repository->getTableById($tableId);
        if ($table === null) {
            return null;
        }

        $latest = $this->normalizeTimestamp((string) ($table['updated_at'] ?? $table['created_at'] ?? ''));
        $embeddedIds = $this->collectEmbeddedTableIdsFromValue([
            $table['description'] ?? '',
            $table['rows'] ?? [],
            $table['settings'] ?? [],
        ]);

        foreach ($embeddedIds as $embeddedId) {
            if ($embeddedId === $tableId) {
                continue;
            }

            $latest = $this->maxTimestamp($latest, $this->resolveTableDependencyTimestamp($embeddedId, $visited));
        }

        return $latest;
    }

    private function normalizeTimestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private function maxTimestamp(?int $left, ?int $right): ?int
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return max($left, $right);
    }

    private function sanitizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/i', '-', $value);

        return trim($value, '-');
    }
}
