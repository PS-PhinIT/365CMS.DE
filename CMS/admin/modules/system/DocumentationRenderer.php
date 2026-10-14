<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class DocumentationRenderer
{
    private const MAX_RENDER_BYTES = 262144;
    private const MAX_LINES = 4000;
    private const MAX_CODE_BLOCK_LINES = 800;
    private const MAX_TABLE_ROWS = 200;
    private const MAX_TABLE_COLUMNS = 12;
    private const MAX_CELL_LENGTH = 1000;
    private const MAX_LINK_TARGET_LENGTH = 1000;

    /** @var \Closure(string, string): string */
    private readonly \Closure $linkResolver;

    /**
     * @param callable(string, string): string $linkResolver
     */
    public function __construct(callable $linkResolver)
    {
        $this->linkResolver = \Closure::fromCallable($linkResolver);
    }

    public function renderDocument(string $contents, string $extension, string $currentDocument): string
    {
        $extension = strtolower(trim($extension));
        $currentDocument = $this->normalizeCurrentDocument($currentDocument);
        $contents = $this->limitContents($contents, $currentDocument, $extension !== 'csv' ? 'markdown' : 'csv');

        return $extension === 'csv'
            ? $this->renderCsv($contents, $currentDocument)
            : $this->renderMarkdown($contents, $currentDocument);
    }

    private function renderMarkdown(string $markdown, string $currentDocument): string
    {
        $lines = preg_split('/\R/', str_replace(["\r\n", "\r"], "\n", $markdown), self::MAX_LINES + 1) ?: [];
        if (count($lines) > self::MAX_LINES) {
            $this->logRenderGuard('markdown_line_limit', 'Dokumentations-Rendering auf maximales Zeilenlimit begrenzt.', [
                'document' => $currentDocument,
                'max_lines' => self::MAX_LINES,
            ]);
            $lines = array_slice($lines, 0, self::MAX_LINES);
        }

        $html = '';
        $paragraph = [];
        $listType = null;
        $tableLines = [];
        $inCodeBlock = false;
        $codeLines = [];

        foreach ($lines as $line) {
            $trimmed = rtrim($line);
            $clean = trim($trimmed);

            if (preg_match('/^```\s*([^`]*)$/', $clean) === 1) {
                $html .= $this->flushTable($tableLines, $currentDocument);
                $html .= $this->flushParagraph($paragraph, $currentDocument);
                $html .= $this->closeList($listType);

                if ($inCodeBlock) {
                    $html .= $this->renderCodeBlock($codeLines, $currentDocument);
                    $inCodeBlock = false;
                    $codeLines = [];
                } else {
                    $inCodeBlock = true;
                }

                continue;
            }

            if ($inCodeBlock) {
                $codeLines[] = $trimmed;
                continue;
            }

            if ($clean === '') {
                $html .= $this->flushTable($tableLines, $currentDocument);
                $html .= $this->flushParagraph($paragraph, $currentDocument);
                $html .= $this->closeList($listType);
                continue;
            }

            if (preg_match('/^\|.+\|$/', $clean) === 1) {
                $html .= $this->flushParagraph($paragraph, $currentDocument);
                $html .= $this->closeList($listType);
                $tableLines[] = $clean;
                continue;
            }

            if ($tableLines !== []) {
                $html .= $this->flushTable($tableLines, $currentDocument);
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $clean, $matches) === 1) {
                $html .= $this->flushParagraph($paragraph, $currentDocument);
                $html .= $this->closeList($listType);
                $level = strlen($matches[1]);
                $html .= sprintf(
                    '<h%d class="mt-4 mb-3">%s</h%d>',
                    $level,
                    $this->renderInline(trim($matches[2]), $currentDocument),
                    $level
                );
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $clean, $matches) === 1) {
                $html .= $this->flushParagraph($paragraph, $currentDocument);
                if ($listType !== 'ul') {
                    $html .= $this->closeList($listType);
                    $html .= '<ul class="mb-3">';
                    $listType = 'ul';
                }
                $html .= '<li>' . $this->renderInline(trim($matches[1]), $currentDocument) . '</li>';
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $clean, $matches) === 1) {
                $html .= $this->flushParagraph($paragraph, $currentDocument);
                if ($listType !== 'ol') {
                    $html .= $this->closeList($listType);
                    $html .= '<ol class="mb-3">';
                    $listType = 'ol';
                }
                $html .= '<li>' . $this->renderInline(trim($matches[1]), $currentDocument) . '</li>';
                continue;
            }

            $html .= $this->closeList($listType);
            $paragraph[] = $clean;
        }

        if ($inCodeBlock) {
            $html .= $this->renderCodeBlock($codeLines, $currentDocument);
        }

        $html .= $this->flushTable($tableLines, $currentDocument);
        $html .= $this->flushParagraph($paragraph, $currentDocument);
        $html .= $this->closeList($listType);

        return $html;
    }

    /**
     * @param array<int, string> $paragraph
     */
    private function flushParagraph(array &$paragraph, string $currentDocument): string
    {
        if ($paragraph === []) {
            return '';
        }

        $text = implode(' ', $paragraph);
        $paragraph = [];

        if ($text === '---') {
            return '<hr class="my-4">';
        }

        return '<p>' . $this->renderInline($text, $currentDocument) . '</p>';
    }

    private function closeList(?string &$listType): string
    {
        if ($listType === null) {
            return '';
        }

        $tag = $listType;
        $listType = null;

        return '</' . $tag . '>';
    }

    /**
     * @param array<int, string> $tableLines
     */
    private function flushTable(array &$tableLines, string $currentDocument): string
    {
        if ($tableLines === []) {
            return '';
        }

        if (count($tableLines) > self::MAX_TABLE_ROWS) {
            $this->logRenderGuard('markdown_table_row_limit', 'Dokumentations-Tabelle auf maximales Zeilenlimit begrenzt.', [
                'document' => $currentDocument,
                'row_count' => count($tableLines),
                'max_rows' => self::MAX_TABLE_ROWS,
            ]);
            $tableLines = array_slice($tableLines, 0, self::MAX_TABLE_ROWS);
        }

        $rows = [];
        foreach ($tableLines as $line) {
            $trimmed = trim($line);
            $trimmed = trim($trimmed, '|');
            $cells = array_map('trim', explode('|', $trimmed, self::MAX_TABLE_COLUMNS + 1));
            if (count($cells) > self::MAX_TABLE_COLUMNS) {
                $this->logRenderGuard('markdown_table_column_limit', 'Dokumentations-Tabelle auf maximale Spaltenzahl begrenzt.', [
                    'document' => $currentDocument,
                    'column_count' => count($cells),
                    'max_columns' => self::MAX_TABLE_COLUMNS,
                ]);
                $cells = array_slice($cells, 0, self::MAX_TABLE_COLUMNS);
            }

            $rows[] = array_map(fn (string $cell): string => $this->limitCellText($cell), $cells);
        }

        $tableLines = [];
        if ($rows === []) {
            return '';
        }

        $header = $rows[0];
        $bodyRows = array_slice($rows, 1);

        if ($bodyRows !== [] && $this->isTableSeparator($bodyRows[0])) {
            $bodyRows = array_slice($bodyRows, 1);
        }

        $html = '<div class="table-responsive mb-4"><table class="table table-bordered table-striped table-sm">';
        $html .= '<thead><tr>';
        foreach ($header as $cell) {
            $html .= '<th>' . $this->renderInline($cell, $currentDocument) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($bodyRows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $this->renderInline($cell, $currentDocument) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    /**
     * @param array<int, string> $row
     */
    private function isTableSeparator(array $row): bool
    {
        foreach ($row as $cell) {
            if (preg_match('/^:?-{3,}:?$/', trim($cell)) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function renderInline(string $text, string $currentDocument): string
    {
        $rendered = htmlspecialchars($this->limitCellText($text), ENT_QUOTES);

        $rendered = preg_replace_callback('/\[([^\]]+)\]\(([^\)]+)\)/', function (array $matches) use ($currentDocument): string {
            $label = $matches[1];
            $target = $this->limitLinkTarget(html_entity_decode($matches[2], ENT_QUOTES));

            try {
                $href = $this->sanitizeHref((string) ($this->linkResolver)($currentDocument, $target));
            } catch (\Throwable $e) {
                $this->logRenderGuard('link_resolution_failed', 'Dokumentations-Link konnte nicht aufgelöst werden.', [
                    'document' => $currentDocument,
                    'target' => $target,
                    'exception' => $e::class,
                ]);
                $href = '#';
            }

            $isExternal = $this->isExternalUrl($href);

            return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '"'
                . ($isExternal ? ' target="_blank" rel="noopener noreferrer"' : '')
                . '>' . $label . '</a>';
        }, $rendered) ?? $rendered;

        $rendered = preg_replace_callback('/`([^`]+)`/', static function (array $matches): string {
            return '<code>' . $matches[1] . '</code>';
        }, $rendered) ?? $rendered;

        $rendered = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $rendered) ?? $rendered;
        $rendered = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $rendered) ?? $rendered;

        return $rendered;
    }

    private function isExternalUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1;
    }

    private function renderCsv(string $contents, string $currentDocument): string
    {
        $lines = preg_split('/\R/', trim($contents), self::MAX_LINES + 1) ?: [];
        if ($lines === []) {
            return '<div class="text-secondary">Keine CSV-Inhalte vorhanden.</div>';
        }

        if (count($lines) > self::MAX_LINES) {
            $this->logRenderGuard('csv_line_limit', 'CSV-Rendering auf maximales Zeilenlimit begrenzt.', [
                'document' => $currentDocument,
                'line_count' => count($lines),
                'max_lines' => self::MAX_LINES,
            ]);
            $lines = array_slice($lines, 0, self::MAX_LINES);
        }

        $rows = array_map(fn (string $line): array => $this->normalizeCsvRow(str_getcsv($line, ',', '"', '')), $lines);
        $header = array_shift($rows) ?: [];

        $html = '<div class="table-responsive"><table class="table table-bordered table-striped table-sm">';
        $html .= '<thead><tr>';
        foreach ($header as $cell) {
            $html .= '<th>' . htmlspecialchars((string) $cell, ENT_QUOTES) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    private function normalizeCurrentDocument(string $currentDocument): string
    {
        $currentDocument = trim(str_replace('\\', '/', $currentDocument));
        $segments = [];

        foreach (explode('/', $currentDocument) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = preg_replace('/[^a-zA-Z0-9._\/-]/', '', $segment) ?? '';
        }

        return implode('/', array_filter($segments, static fn (string $segment): bool => $segment !== ''));
    }

    private function limitContents(string $contents, string $currentDocument, string $type): string
    {
        if (strlen($contents) <= self::MAX_RENDER_BYTES) {
            return $contents;
        }

        $this->logRenderGuard('document_size_limit', 'Dokumentations-Rendering auf maximale Inhaltsgröße begrenzt.', [
            'document' => $currentDocument,
            'type' => $type,
            'max_bytes' => self::MAX_RENDER_BYTES,
        ]);

        return substr($contents, 0, self::MAX_RENDER_BYTES);
    }

    private function limitCellText(string $text): string
    {
        $text = trim($text);

        return cms_truncate_text($text, self::MAX_CELL_LENGTH);
    }

    private function limitLinkTarget(string $target): string
    {
        $target = trim($target);

        return cms_truncate_text($target, self::MAX_LINK_TARGET_LENGTH, '');
    }

    private function sanitizeHref(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '#';
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $href) === 1 || str_contains($href, '\\')) {
            return '#';
        }

        if ($href[0] === '#') {
            return preg_match('/^#[a-zA-Z][a-zA-Z0-9_\-:.]*$/', $href) === 1 ? $href : '#';
        }

        if ($href[0] === '/') {
            if (str_starts_with($href, '//')) {
                return '#';
            }

            return $href;
        }

        if ($this->isExternalUrl($href)) {
            return filter_var($href, FILTER_VALIDATE_URL) ? $href : '#';
        }

        return '#';
    }

    /**
     * @param array<int, string> $codeLines
     */
    private function renderCodeBlock(array $codeLines, string $currentDocument): string
    {
        if (count($codeLines) > self::MAX_CODE_BLOCK_LINES) {
            $this->logRenderGuard('markdown_code_block_line_limit', 'Dokumentations-Codeblock auf maximales Zeilenlimit begrenzt.', [
                'document' => $currentDocument,
                'line_count' => count($codeLines),
                'max_lines' => self::MAX_CODE_BLOCK_LINES,
            ]);
            $codeLines = array_slice($codeLines, 0, self::MAX_CODE_BLOCK_LINES);
        }

        return '<pre class="bg-dark-lt p-3 rounded overflow-auto"><code>'
            . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES)
            . '</code></pre>';
    }

    /**
     * @param array<int, mixed> $row
     * @return array<int, string>
     */
    private function normalizeCsvRow(array $row): array
    {
        if (count($row) > self::MAX_TABLE_COLUMNS) {
            $row = array_slice($row, 0, self::MAX_TABLE_COLUMNS);
        }

        return array_map(fn (mixed $cell): string => $this->limitCellText((string) $cell), $row);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logRenderGuard(string $operation, string $message, array $context = []): void
    {
        \CMS\Logger::instance()->withChannel('admin.documentation')->warning($message, array_merge([
            'operation' => $operation,
        ], $context));
    }
}
