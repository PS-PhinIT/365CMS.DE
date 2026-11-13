<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class DocumentationCatalog
{
    private const int MAX_DOCUMENT_READ_BYTES = 262144;
    private const int MAX_METADATA_READ_BYTES = 32768;

    public function __construct(
        private readonly string $docsRoot,
        private readonly string $siteUrl
    ) {
    }

    /**
     * @return array{sections: array<int, array<string, mixed>>, all_docs: array<string, array<string, mixed>>, featured_docs: array<int, array<string, mixed>>, doc_count: int, section_count: int}
     */
    public function buildCatalog(): array
    {
        $sections = $this->scanSections();
        $allDocs = $this->flattenDocuments($sections);

        return [
            'sections' => $sections,
            'all_docs' => $allDocs,
            'featured_docs' => $this->getFeaturedDocuments($allDocs),
            'doc_count' => count($allDocs),
            'section_count' => count($sections),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $allDocs
     * @return array{selected_relative: string, selected_document: array<string, mixed>|null}
     */
    public function resolveSelection(?string $selectedDoc, array $allDocs): array
    {
        $selectedRelative = $this->normalizeRelativePath((string) $selectedDoc);
        if ($selectedRelative === '' || !isset($allDocs[$selectedRelative])) {
            $selectedRelative = $this->resolveDefaultDocument(array_keys($allDocs));
        }

        return [
            'selected_relative' => $selectedRelative,
            'selected_document' => $selectedRelative !== '' && isset($allDocs[$selectedRelative]) ? $allDocs[$selectedRelative] : null,
        ];
    }

    public function readDocumentContents(string $fullPath): string
    {
        if (!$this->isReadableDocumentPath($fullPath)) {
            $this->logFilesystemFailure('file_get_contents_preflight', 'Dokument konnte nicht gelesen werden.', [
                'path' => $this->safeLogPath($fullPath),
                'reason' => 'file_unreadable',
            ]);

            return '';
        }

        $size = filesize($fullPath);
        $maxBytes = self::MAX_DOCUMENT_READ_BYTES;
        $bytesToRead = $maxBytes;
        if (is_int($size) && $size > 0) {
            $bytesToRead = min($size, $maxBytes + 1);
        }

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });

        try {
            $contents = file_get_contents($fullPath, false, null, 0, $bytesToRead);
        } finally {
            restore_error_handler();
        }

        if (!is_string($contents)) {
            $context = ['path' => $this->safeLogPath($fullPath)];
            if ($warning !== null) {
                $context['warning'] = $this->sanitizeLogValue($warning, 180);
            }
            $this->logFilesystemFailure('file_get_contents', 'Dokument konnte nicht gelesen werden.', $context);
            return '';
        }

        if (strlen($contents) > $maxBytes) {
            $this->logFilesystemFailure('file_read_truncated', 'Dokument wurde für die Admin-Ansicht auf die maximale Lesegröße begrenzt.', [
                'path' => $this->safeLogPath($fullPath),
                'max_bytes' => $maxBytes,
            ]);
            $contents = substr($contents, 0, $maxBytes);
        }

        return $contents;
    }

    public function resolveLink(string $currentDocument, string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return '#';
        }

        if ($target[0] === '#') {
            return $target;
        }

        if ($this->isExternalUrl($target) || str_starts_with($target, '/')) {
            return $target;
        }

        $baseDir = trim(str_replace('\\', '/', dirname($currentDocument)), '.');
        $combined = $baseDir === '' ? $target : $baseDir . '/' . $target;
        $normalized = $this->normalizeRelativePath($combined);

        if ($normalized === '') {
            return '#';
        }

        $extension = strtolower((string) pathinfo($normalized, PATHINFO_EXTENSION));
        if (in_array($extension, ['md', 'csv'], true)) {
            return $this->buildAdminUrl($normalized);
        }

        return '#';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scanSections(): array
    {
        $directories = [];
        $rootDocs = [];

        $entries = scandir($this->docsRoot);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $this->docsRoot . DIRECTORY_SEPARATOR . $entry;

            if (is_file($fullPath) && $this->isSupportedDocument($fullPath)) {
                $rootDocs[] = $this->buildDocumentMeta($fullPath);
                continue;
            }

            if (is_dir($fullPath) && !is_link($fullPath)) {
                $directoryNode = $this->scanDirectoryNode($fullPath);
                if ($directoryNode === null) {
                    continue;
                }

                $directories[] = $directoryNode;
            }
        }

        usort($rootDocs, [$this, 'compareDocuments']);
        usort($directories, [$this, 'compareDirectoryNodes']);

        if ($rootDocs === [] && $directories === []) {
            return [];
        }

        return [[
            'type' => 'directory',
            'slug' => 'doc-root',
            'title' => '/DOC',
            'relative_path' => '',
            'doc_count' => count($rootDocs) + $this->countDocumentsInDirectoryNodes($directories),
            'documents' => $rootDocs,
            'children' => $directories,
        ]];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scanDirectoryNode(string $directory): ?array
    {
        $documents = [];
        $children = [];

        $entries = scandir($directory);
        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_file($fullPath) && $this->isSupportedDocument($fullPath)) {
                $documents[] = $this->buildDocumentMeta($fullPath);
                continue;
            }

            if (is_dir($fullPath) && !is_link($fullPath)) {
                $child = $this->scanDirectoryNode($fullPath);
                if ($child !== null) {
                    $children[] = $child;
                }
            }
        }

        usort($documents, [$this, 'compareDocuments']);
        usort($children, [$this, 'compareDirectoryNodes']);

        $docCount = count($documents) + $this->countDocumentsInDirectoryNodes($children);
        if ($docCount < 1) {
            return null;
        }

        $relativePath = $this->relativeDirectoryPath($directory);

        return [
            'type' => 'directory',
            'slug' => $this->buildDirectorySlug($relativePath),
            'title' => basename($directory),
            'relative_path' => $relativePath,
            'doc_count' => $docCount,
            'documents' => $documents,
            'children' => $children,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDocumentMeta(string $fullPath): array
    {
        $relativePath = $this->relativePath($fullPath);
        $contents = $this->readMetadataContents($fullPath);
        $extension = strtolower((string) pathinfo($fullPath, PATHINFO_EXTENSION));

        return [
            'title' => basename($relativePath),
            'excerpt' => $this->extractExcerpt($contents, $extension),
            'relative_path' => $relativePath,
            'full_path' => $fullPath,
            'extension' => $extension,
            'admin_url' => $this->buildAdminUrl($relativePath),
        ];
    }

    private function relativePath(string $fullPath): string
    {
        $relative = substr($fullPath, strlen(rtrim($this->docsRoot, '\\/')) + 1);
        return $this->normalizeRelativePath((string) $relative);
    }

    private function relativeDirectoryPath(string $directory): string
    {
        $relative = substr($directory, strlen(rtrim($this->docsRoot, '\\/')) + 1);
        return $this->normalizeRelativePath((string) $relative);
    }

    private function buildDirectorySlug(string $relativePath): string
    {
        $slug = strtolower(trim(str_replace(['/', '\\', ' '], '-', $relativePath), '-'));
        $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug) ?? '';

        return $slug !== '' ? $slug : 'doc-root';
    }

    private function readMetadataContents(string $fullPath): string
    {
        if (!$this->isReadableDocumentPath($fullPath)) {
            return '';
        }

        $contents = @file_get_contents($fullPath, false, null, 0, self::MAX_METADATA_READ_BYTES);
        return is_string($contents) ? $contents : '';
    }

    private function extractExcerpt(string $contents, string $extension): string
    {
        if ($extension === 'csv') {
            return 'CSV-Export bzw. tabellarische Dokumentationsdaten.';
        }

        $lines = preg_split('/\R/', $contents) ?: [];
        $paragraph = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($paragraph !== []) {
                    break;
                }
                continue;
            }

            if (
                str_starts_with($trimmed, '#')
                || str_starts_with($trimmed, '---')
                || str_starts_with($trimmed, '```')
                || str_starts_with($trimmed, '![')
                || preg_match('/^\|.+\|$/', $trimmed) === 1
            ) {
                continue;
            }

            $paragraph[] = $trimmed;
            if (count($paragraph) >= 3) {
                break;
            }
        }

        $text = $this->stripMarkdown(implode(' ', $paragraph));
        if ($text === '') {
            return 'Dokumentation aus dem lokalen Repository-Verzeichnis /DOC.';
        }

        return cms_truncate_text($text, 180);
    }

    private function stripMarkdown(string $text): string
    {
        $text = preg_replace('/!\[[^\]]*\]\([^\)]*\)/', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '$1', $text) ?? $text;
        $text = preg_replace('/[*_`>#-]/', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param array<int, string> $availablePaths
     */
    private function resolveDefaultDocument(array $availablePaths): string
    {
        $preferred = [
            'README.md',
            'INDEX.md',
            'admin/README.md',
            'member/README.md',
            'theme/README.md',
        ];

        foreach ($preferred as $candidate) {
            if (in_array($candidate, $availablePaths, true)) {
                return $candidate;
            }
        }

        return $availablePaths[0] ?? '';
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @return array<string, array<string, mixed>>
     */
    private function flattenDocuments(array $sections): array
    {
        $documents = [];

        foreach ($sections as $section) {
            $this->collectDocumentsFromNode($section, $documents);
        }

        return $documents;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $documents
     */
    private function collectDocumentsFromNode(array $node, array &$documents): void
    {
        foreach ((array) ($node['documents'] ?? []) as $document) {
            if (!is_array($document) || empty($document['relative_path'])) {
                continue;
            }

            $documents[(string) $document['relative_path']] = $document;
        }

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectDocumentsFromNode($child, $documents);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $documents
     * @return array<int, array<string, mixed>>
     */
    private function getFeaturedDocuments(array $documents): array
    {
        $paths = [
            'README.md',
            'INSTALLATION.md',
            'INDEX.md',
            'admin/README.md',
            'member/README.md',
            'theme/README.md',
            'core/README.md',
            'workflow/MARKETPLACE-WORKFLOW.md',
        ];

        $featured = [];
        foreach ($paths as $path) {
            if (isset($documents[$path])) {
                $featured[] = $documents[$path];
            }
        }

        return $featured;
    }

    private function compareDocuments(array $left, array $right): int
    {
        $leftPath = (string) ($left['relative_path'] ?? '');
        $rightPath = (string) ($right['relative_path'] ?? '');

        $leftIsReadme = str_ends_with(strtolower($leftPath), '/readme.md') || strtolower($leftPath) === 'readme.md';
        $rightIsReadme = str_ends_with(strtolower($rightPath), '/readme.md') || strtolower($rightPath) === 'readme.md';

        if ($leftIsReadme !== $rightIsReadme) {
            return $leftIsReadme ? -1 : 1;
        }

        return strcasecmp($leftPath, $rightPath);
    }

    private function compareDirectoryNodes(array $left, array $right): int
    {
        return strcasecmp((string) ($left['relative_path'] ?? ''), (string) ($right['relative_path'] ?? ''));
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function countDocumentsInDirectoryNodes(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            $count += (int) ($node['doc_count'] ?? 0);
        }

        return $count;
    }

    private function isSupportedDocument(string $fullPath): bool
    {
        $extension = strtolower((string) pathinfo($fullPath, PATHINFO_EXTENSION));
        return in_array($extension, ['md', 'csv'], true);
    }

    private function buildAdminUrl(string $relativePath): string
    {
        return $this->siteUrl . '/admin/documentation?doc=' . rawurlencode($relativePath);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function isReadableDocumentPath(string $fullPath): bool
    {
        if (!is_file($fullPath) || !is_readable($fullPath) || is_link($fullPath)) {
            return false;
        }

        return $this->isWithinDocsRoot($fullPath);
    }

    private function isWithinDocsRoot(string $path): bool
    {
        $docsRoot = realpath($this->docsRoot);
        $resolvedPath = realpath($path);
        if ($docsRoot === false || $resolvedPath === false) {
            return false;
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $docsRoot), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $resolvedPath);

        return str_starts_with($normalizedPath, $normalizedRoot);
    }

    private function safeLogPath(string $fullPath): string
    {
        if ($this->isWithinDocsRoot($fullPath)) {
            return $this->relativePath($fullPath);
        }

        return basename($fullPath);
    }

    private function sanitizeLogValue(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function isExternalUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logFilesystemFailure(string $operation, string $message, array $context = []): void
    {
        \CMS\Logger::instance()->withChannel('admin.documentation')->warning($message, array_merge([
            'operation' => $operation,
        ], $context));
    }
}
