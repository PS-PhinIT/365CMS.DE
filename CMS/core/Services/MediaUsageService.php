<?php
declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Json;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaUsageService
{
    private static ?self $instance = null;

    private Database $db;
    private string $prefix;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    /**
     * @param array<int, string> $relativePaths
     * @return array<string, list<array<string, mixed>>>
     */
    public function buildUsageMap(array $relativePaths): array
    {
        // 4.1.0 (2b): Nach dem ersten vollständigen Aufbau liefert der dauerhafte Index die Verwendungen über alle
        // Inhaltsarten (Beiträge, Seiten, Hub-Sites, Downloads, Formulare, Design); bis dahin die Laufzeitsuche.
        try {
            $index = \CMS\Services\Media\MediaUsageIndex::getInstance();
            if ($index->isBuilt()) {
                return $index->usageMap(array_map('strval', $relativePaths));
            }
        } catch (\Throwable) {
        }

        $targets = $this->normalizeTargetPaths($relativePaths);
        if ($targets === []) {
            return [];
        }

        $usageMap = [];
        foreach ($targets as $targetPath) {
            $usageMap[$targetPath] = [];
        }

        $lookup = $this->buildUsageLookup($targets);

        $this->collectUsagesForRows(
            $usageMap,
            $lookup,
            $this->fetchPosts(),
            'post',
            'Beitrag',
            '/admin/posts?action=edit&id='
        );

        $this->collectUsagesForRows(
            $usageMap,
            $lookup,
            $this->fetchPages(),
            'page',
            'Seite',
            '/admin/pages?action=edit&id='
        );

        foreach ($usageMap as &$items) {
            $this->sortUsageItems($items);
        }
        unset($items);

        return $usageMap;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function buildFeaturedImageMap(): array
    {
        $usageMap = [];

        // Release-Prüfung 4.1.0 (PERF, db:9): nur Beitragsbild-Spalten statt content/content_en (LONGTEXT) laden.
        $this->collectFeaturedUsages(
            $usageMap,
            $this->fetchFeaturedRows('posts', true),
            'post',
            'Beitrag',
            '/admin/posts?action=edit&id='
        );

        $this->collectFeaturedUsages(
            $usageMap,
            $this->fetchFeaturedRows('pages', true),
            'page',
            'Seite',
            '/admin/pages?action=edit&id='
        );

        foreach ($usageMap as &$items) {
            $this->sortUsageItems($items);
        }
        unset($items);

        ksort($usageMap);

        return $usageMap;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildFeaturedImageContentList(): array
    {
        $items = [];

        // Release-Prüfung 4.1.0 (PERF, db:9): ohne LONGTEXT-Spalten; auch Inhalte ohne Beitragsbild bleiben in der Liste.
        $this->collectFeaturedContentItems(
            $items,
            $this->fetchFeaturedRows('posts', false),
            'post',
            'Beitrag',
            '/admin/posts?action=edit&id='
        );

        $this->collectFeaturedContentItems(
            $items,
            $this->fetchFeaturedRows('pages', false),
            'page',
            'Seite',
            '/admin/pages?action=edit&id='
        );

        usort($items, static function (array $left, array $right): int {
            $typeCompare = strcasecmp((string) ($left['content_type_label'] ?? ''), (string) ($right['content_type_label'] ?? ''));
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        return $items;
    }

    /**
     * @param array<int, string> $relativePaths
     * @return list<string>
     */
    private function normalizeTargetPaths(array $relativePaths): array
    {
        $normalized = [];

        foreach ($relativePaths as $relativePath) {
            $path = $this->normalizeRelativeMediaPath((string) $relativePath);
            if ($path === '') {
                continue;
            }

            $normalized[$path] = true;
        }

        return array_keys($normalized);
    }

    /**
     * Release-Prüfung 4.1.0 (PERF, db:9): Zeilen für Beitragsbild-Ansichten ohne content/content_en.
     * Mit $onlyWithImage nur Zeilen mit gesetztem Beitragsbild (für die Verwendungs-Map, die leere Werte ohnehin
     * überspringt).
     *
     * @param 'posts'|'pages' $table
     * @return array<int, object>
     */
    private function fetchFeaturedRows(string $table, bool $onlyWithImage): array
    {
        $table = $table === 'pages' ? 'pages' : 'posts';
        $where = $onlyWithImage ? " WHERE featured_image IS NOT NULL AND featured_image <> ''" : '';

        return $this->db->get_results(
            "SELECT id, title, slug, featured_image
             FROM {$this->prefix}{$table}{$where}"
        ) ?: [];
    }

    /**
     * @return array<int, object>
     */
    private function fetchPosts(): array
    {
        return $this->db->get_results(
            "SELECT id, title, slug, featured_image, content, content_en
             FROM {$this->prefix}posts"
        ) ?: [];
    }

    /**
     * @return array<int, object>
     */
    private function fetchPages(): array
    {
        return $this->db->get_results(
            "SELECT id, title, slug, featured_image, content, content_en
             FROM {$this->prefix}pages"
        ) ?: [];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $usageMap
     * @param array<int, object> $rows
     */
    private function collectFeaturedUsages(array &$usageMap, array $rows, string $contentType, string $contentTypeLabel, string $editBaseUrl): void
    {
        $fieldLabel = $contentType === 'post' ? 'Beitragsbild' : 'Seitenbild';

        foreach ($rows as $row) {
            $contentId = (int) ($row->id ?? 0);
            if ($contentId <= 0) {
                continue;
            }

            $title = $this->buildContentTitle((string) ($row->title ?? ''), (string) ($row->slug ?? ''), $contentTypeLabel, $contentId);
            $editUrl = $editBaseUrl . $contentId;

            foreach ($this->extractRelativeMediaPaths((string) ($row->featured_image ?? '')) as $relativePath) {
                if ($relativePath === '') {
                    continue;
                }

                if (!isset($usageMap[$relativePath])) {
                    $usageMap[$relativePath] = [];
                }

                $usageKey = $contentType . ':' . $contentId . ':featured_image';
                if ($this->usageExists($usageMap[$relativePath], $usageKey)) {
                    continue;
                }

                $usageMap[$relativePath][] = [
                    'usage_key' => $usageKey,
                    'content_type' => $contentType,
                    'content_type_label' => $contentTypeLabel,
                    'field' => 'featured_image',
                    'field_label' => $fieldLabel,
                    'content_id' => $contentId,
                    'title' => $title,
                    'edit_url' => $editUrl,
                ];
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<int, object> $rows
     */
    private function collectFeaturedContentItems(array &$items, array $rows, string $contentType, string $contentTypeLabel, string $editBaseUrl): void
    {
        foreach ($rows as $row) {
            $contentId = (int) ($row->id ?? 0);
            if ($contentId <= 0) {
                continue;
            }

            $title = $this->buildContentTitle((string) ($row->title ?? ''), (string) ($row->slug ?? ''), $contentTypeLabel, $contentId);
            $reference = trim((string) ($row->featured_image ?? ''));
            $relativePaths = $this->extractRelativeMediaPaths($reference);

            $items[] = [
                'content_type' => $contentType,
                'content_type_label' => $contentTypeLabel,
                'content_id' => $contentId,
                'title' => $title,
                'edit_url' => $editBaseUrl . $contentId,
                'featured_image' => $reference,
                'featured_image_path' => (string) ($relativePaths[0] ?? ''),
                'has_featured_image' => $reference !== '',
            ];
        }
    }

    /**
    * @param array<string, list<array<string, mixed>>> $usageMap
    * @param array<string, list<string>> $lookup
     * @param array<int, object> $rows
     */
    private function collectUsagesForRows(array &$usageMap, array $lookup, array $rows, string $contentType, string $contentTypeLabel, string $editBaseUrl): void
    {
        $fieldLabels = [
            'featured_image' => $contentType === 'post' ? 'Beitragsbild' : 'Seitenbild',
            'content' => 'Inhalt',
            'content_en' => 'Inhalt EN',
        ];

        foreach ($rows as $row) {
            $contentId = (int) ($row->id ?? 0);
            if ($contentId <= 0) {
                continue;
            }

            $title = $this->buildContentTitle((string) ($row->title ?? ''), (string) ($row->slug ?? ''), $contentTypeLabel, $contentId);
            $editUrl = $editBaseUrl . $contentId;

            foreach ($fieldLabels as $field => $fieldLabel) {
                foreach ($this->extractRelativeMediaPaths((string) ($row->{$field} ?? '')) as $relativePath) {
                    $targetPaths = $lookup[$relativePath] ?? [];
                    if ($targetPaths === []) {
                        continue;
                    }

                    $usageKey = $contentType . ':' . $contentId . ':' . $field;

                    foreach ($targetPaths as $targetPath) {
                        if ($targetPath === '' || !array_key_exists($targetPath, $usageMap)) {
                            continue;
                        }

                        if ($this->usageExists($usageMap[$targetPath] ?? [], $usageKey)) {
                            continue;
                        }

                        $usageMap[$targetPath][] = [
                            'usage_key' => $usageKey,
                            'content_type' => $contentType,
                            'content_type_label' => $contentTypeLabel,
                            'field' => $field,
                            'field_label' => $fieldLabel,
                            'content_id' => $contentId,
                            'title' => $title,
                            'edit_url' => $editUrl,
                        ];
                    }
                }
            }
        }
    }

    /**
     * @param list<string> $targets
     * @return array<string, list<string>>
     */
    private function buildUsageLookup(array $targets): array
    {
        $lookup = [];

        foreach ($targets as $targetPath) {
            foreach ($this->buildRelatedMediaPaths($targetPath) as $candidatePath) {
                if ($candidatePath === '') {
                    continue;
                }

                if (!isset($lookup[$candidatePath])) {
                    $lookup[$candidatePath] = [];
                }

                if (!in_array($targetPath, $lookup[$candidatePath], true)) {
                    $lookup[$candidatePath][] = $targetPath;
                }
            }
        }

        return $lookup;
    }

    /**
     * @return list<string>
     */
    private function buildRelatedMediaPaths(string $relativePath): array
    {
        $normalizedPath = $this->normalizeRelativeMediaPath($relativePath);
        if ($normalizedPath === '') {
            return [];
        }

        $dirname = trim(str_replace('\\', '/', dirname($normalizedPath)), '/');
        if ($dirname === '.' || $dirname === '/') {
            $dirname = '';
        }

        $extension = strtolower((string) pathinfo($normalizedPath, PATHINFO_EXTENSION));
        $filename = (string) pathinfo($normalizedPath, PATHINFO_FILENAME);

        if ($filename === '') {
            return [$normalizedPath];
        }

        $sizeSuffixes = ['small', 'medium', 'large', 'banner'];
        $baseName = $filename;

        if (preg_match('/^(.*?)-(small|medium|large|banner)$/i', $filename, $matches) === 1) {
            $baseName = (string) ($matches[1] ?? $filename);
        }

        $buildPath = static function (string $dir, string $name, string $ext): string {
            $candidate = $name . ($ext !== '' ? '.' . $ext : '');
            return $dir !== '' ? $dir . '/' . $candidate : $candidate;
        };

        $relatedPaths = [$normalizedPath];

        if ($extension !== '') {
            $relatedPaths[] = $buildPath($dirname, $baseName, $extension);

            foreach ($sizeSuffixes as $suffix) {
                $relatedPaths[] = $buildPath($dirname, $baseName . '-' . $suffix, $extension);
            }
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            $relatedPaths[] = $buildPath($dirname, $baseName, 'webp');
        }

        if ($extension === 'webp') {
            foreach (['jpg', 'jpeg', 'png', 'gif'] as $sourceExtension) {
                $relatedPaths[] = $buildPath($dirname, $baseName, $sourceExtension);
                foreach ($sizeSuffixes as $suffix) {
                    $relatedPaths[] = $buildPath($dirname, $baseName . '-' . $suffix, $sourceExtension);
                }
            }
        }

        $normalizedRelatedPaths = [];
        foreach ($relatedPaths as $candidatePath) {
            $candidatePath = $this->normalizeRelativeMediaPath($candidatePath);
            if ($candidatePath === '') {
                continue;
            }

            $normalizedRelatedPaths[$candidatePath] = true;
        }

        return array_keys($normalizedRelatedPaths);
    }

    /**
     * @param list<array<string, mixed>> $usageItems
     */
    private function usageExists(array $usageItems, string $usageKey): bool
    {
        foreach ($usageItems as $usageItem) {
            if ((string) ($usageItem['usage_key'] ?? '') === $usageKey) {
                return true;
            }
        }

        return false;
    }

    private function buildContentTitle(string $title, string $slug, string $contentTypeLabel, int $contentId): string
    {
        $title = trim($title);
        if ($title !== '') {
            return $title;
        }

        $slug = trim($slug);
        if ($slug !== '') {
            return $slug;
        }

        return $contentTypeLabel . ' #' . $contentId;
    }

    /**
     * @return list<string>
     */
    private function extractRelativeMediaPaths(string $value): array
    {
        $seen = [];
        $this->collectRelativeMediaPathsFromValue($value, $seen);

        return array_keys($seen);
    }

    /**
     * @param array<string, bool> $seen
     */
    private function collectRelativeMediaPathsFromValue(mixed $value, array &$seen): void
    {
        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                $this->collectRelativeMediaPathsFromValue($nestedValue, $seen);
            }

            return;
        }

        if (!is_string($value)) {
            return;
        }

        $trimmedValue = trim($value);
        if ($trimmedValue === '') {
            return;
        }

        $decoded = Json::decodeArray($trimmedValue, []);
        if ($decoded !== []) {
            $this->collectRelativeMediaPathsFromValue($decoded, $seen);
        }

        foreach ($this->collectPotentialCandidatesFromString($trimmedValue) as $candidate) {
            $relativePath = $this->normalizeCandidateToRelativePath($candidate);
            if ($relativePath !== '') {
                $seen[$relativePath] = true;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function collectPotentialCandidatesFromString(string $value): array
    {
        $decodedValue = html_entity_decode(str_replace('\/', '/', $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $candidates = [$decodedValue];

        if (preg_match_all('#https?://[^\s<>"\']+|/(?:[A-Za-z0-9._%-]+/)*media-file\?[^\s<>"\']+|/(?:[A-Za-z0-9._%-]+/)*uploads/[^\s<>"\']+#i', $decodedValue, $matches) === 1 || !empty($matches[0])) {
            foreach ($matches[0] as $match) {
                $candidates[] = (string) $match;
            }
        }

        if (preg_match_all('#url\(([^)]+)\)#i', $decodedValue, $urlMatches) === 1 || !empty($urlMatches[1])) {
            foreach ($urlMatches[1] as $match) {
                $candidates[] = trim((string) $match, " \t\n\r\0\x0B\"'");
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $candidates), static fn (string $candidate): bool => $candidate !== '')));
    }

    private function normalizeCandidateToRelativePath(string $candidate): string
    {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $candidate = trim($candidate, " \t\n\r\0\x0B\"'");

        if ($candidate === '') {
            return '';
        }

        if (preg_match('#^url\((.+)\)$#i', $candidate, $matches) === 1) {
            $candidate = trim((string) ($matches[1] ?? ''), " \t\n\r\0\x0B\"'");
        }

        if ($candidate === '') {
            return '';
        }

        if (str_starts_with($candidate, 'media-file?')) {
            $candidate = '/' . $candidate;
        }

        if (preg_match('#^(?:/)?uploads/#i', $candidate) === 1) {
            return $this->normalizeRelativeMediaPath((string) preg_replace('#^(?:/)?uploads/#i', '', $candidate));
        }

        if (preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)+$#', $candidate) === 1) {
            return $this->normalizeRelativeMediaPath($candidate);
        }

        $parsedUrl = parse_url($candidate);
        if ($parsedUrl === false) {
            return '';
        }

        $host = strtolower(trim((string) ($parsedUrl['host'] ?? '')));
        if ($host !== '' && !$this->isLocalHost($host)) {
            return '';
        }

        return $this->extractRelativeMediaPathFromUrlParts(
            (string) ($parsedUrl['path'] ?? ''),
            (string) ($parsedUrl['query'] ?? '')
        );
    }

    private function extractRelativeMediaPathFromUrlParts(string $path, string $queryString = ''): string
    {
        $normalizedPath = '/' . ltrim((string) preg_replace('#/+#', '/', str_replace('\\', '/', trim($path))), '/');
        if ($normalizedPath === '/') {
            return '';
        }

        $sitePath = trim((string) (parse_url((string) SITE_URL, PHP_URL_PATH) ?? ''), '/');
        $uploadPath = trim((string) (parse_url((string) UPLOAD_URL, PHP_URL_PATH) ?? ''), '/');

        $mediaPrefixes = array_filter([
            '/media-file',
            $sitePath !== '' ? '/' . $sitePath . '/media-file' : '',
        ]);

        foreach ($mediaPrefixes as $mediaPrefix) {
            if ($normalizedPath === $mediaPrefix) {
                parse_str($queryString, $query);
                return $this->normalizeRelativeMediaPath((string) ($query['path'] ?? ''));
            }
        }

        $uploadPrefixes = array_filter([
            '/uploads/',
            $uploadPath !== '' ? '/' . $uploadPath . '/' : '',
        ]);

        foreach ($uploadPrefixes as $uploadPrefix) {
            if (str_starts_with($normalizedPath, $uploadPrefix)) {
                return $this->normalizeRelativeMediaPath(ltrim(substr($normalizedPath, strlen($uploadPrefix)), '/'));
            }
        }

        return '';
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return true;
        }

        $knownHosts = array_filter([
            strtolower(trim((string) (parse_url((string) SITE_URL, PHP_URL_HOST) ?? ''))),
            strtolower(trim((string) (parse_url((string) UPLOAD_URL, PHP_URL_HOST) ?? ''))),
        ]);

        return in_array($host, $knownHosts, true);
    }

    private function normalizeRelativeMediaPath(string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        $relativePath = preg_replace('#/+#', '/', $relativePath) ?? '';
        $relativePath = trim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, '..') || preg_match('/[\x00-\x1F\x7F]/', $relativePath) === 1) {
            return '';
        }

        return preg_match('#^[A-Za-z0-9._\-/]+$#', $relativePath) === 1 ? $relativePath : '';
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function sortUsageItems(array &$items): void
    {
        usort($items, static function (array $left, array $right): int {
            $typeSortOrder = ['post' => 0, 'page' => 1];
            $leftType = (string) ($left['content_type'] ?? '');
            $rightType = (string) ($right['content_type'] ?? '');
            $typeCompare = ($typeSortOrder[$leftType] ?? 99) <=> ($typeSortOrder[$rightType] ?? 99);
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            $titleCompare = strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
            if ($titleCompare !== 0) {
                return $titleCompare;
            }

            return strcasecmp((string) ($left['field_label'] ?? ''), (string) ($right['field_label'] ?? ''));
        });
    }
}