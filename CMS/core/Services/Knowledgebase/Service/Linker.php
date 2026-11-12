<?php

declare(strict_types=1);

namespace CMS\Services\Knowledgebase\Service;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use CMS\Services\Knowledgebase\Repository\EntryRepository;
use CMS\Services\Knowledgebase\Support\RequestInspector;
use CMS\Services\Knowledgebase\Support\LoggerFactory;

if (!defined('ABSPATH')) {
    exit;
}

final class Linker
{
    private const MAX_BUFFER_ENTRY_COUNT = 80;
    private const MAX_BUFFER_HTML_BYTES = 250000;
    private const BUFFER_TARGET_CLASSES = [
        'page-content',
        'post-body',
        'article-body',
        'entry-content',
        'content-body',
    ];

    private static ?self $instance = null;

    private bool $bufferStarted = false;

    private $logger;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->logger = LoggerFactory::create();
    }

    public function filterContent(mixed $content): mixed
    {
        if (!is_string($content) || $content === '') {
            return $content;
        }

        if ($this->isKnowledgebaseRequest()) {
            return $content;
        }

        if (!$this->isAutolinkEnabled()) {
            return $content;
        }

        return $this->linkHtml($content);
    }

    public function bootOutputBufferFallback(): void
    {
        if ($this->bufferStarted || !$this->isFrontendRequest()) {
            return;
        }

        if ($this->isKnowledgebaseRequest()) {
            return;
        }

        $settings = EntryRepository::instance()->getSettings();
        if (($settings['enable_autolink'] ?? '0') !== '1') {
            return;
        }

        $entryCount = EntryRepository::instance()->countActiveEntries();
        $enableFullBuffer = ($settings['enable_output_buffer'] ?? '0') === '1';

        ob_start(function (string $html) use ($entryCount, $enableFullBuffer): string {
            if (!$this->looksLikeHtmlDocument($html)) {
                return $html;
            }

            if ($enableFullBuffer && $entryCount <= self::MAX_BUFFER_ENTRY_COUNT && strlen($html) <= self::MAX_BUFFER_HTML_BYTES) {
                return $this->linkHtml($html);
            }

            return $this->linkDocumentRegions($html);
        });

        $this->bufferStarted = true;
        $this->logger->debug('Knowledgebase Output-Buffer-Fallback aktiviert.');
    }

    private function linkHtml(string $html): string
    {
        $entries = EntryRepository::instance()->getActiveEntriesForLinking();
        if ($entries === []) {
            return $html;
        }

        $settings = EntryRepository::instance()->getSettings();
        $maxGlobal = max(1, min(40, (int) ($settings['max_links_per_page'] ?? 6)));
        $segments = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($segments)) {
            return $html;
        }

        $output = '';
        $skipTags = ['a', 'script', 'style', 'code', 'pre', 'textarea', 'kbd', 'samp'];
        $openSkipTags = [];
        $usageCounts = [];
        $totalReplacements = 0;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment[0] === '<') {
                $this->trackSkipTagState($segment, $skipTags, $openSkipTags);
                $output .= $segment;
                continue;
            }

            if ($this->isInsideSkippedTag($openSkipTags)) {
                $output .= $segment;
                continue;
            }

            $output .= $this->replaceTextSegment($segment, $entries, $settings, $usageCounts, $totalReplacements, $maxGlobal);
        }

        return $output;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, string> $settings
     * @param array<int, int> $usageCounts
     */
    private function replaceTextSegment(string $text, array $entries, array $settings, array &$usageCounts, int &$totalReplacements, int $maxGlobal): string
    {
        if (trim($text) === '' || $totalReplacements >= $maxGlobal) {
            return $text;
        }

        $replacements = [];
        $tokenCounter = 0;

        foreach ($entries as $entry) {
            $entryId = (int) ($entry['id'] ?? 0);
            $maxPerEntry = max(1, min(10, (int) ($entry['max_links_per_page'] ?? 1)));
            $entryUsage = $usageCounts[$entryId] ?? 0;
            if ($entryUsage >= $maxPerEntry || $totalReplacements >= $maxGlobal) {
                continue;
            }

            foreach (($entry['terms'] ?? []) as $term) {
                $term = trim((string) $term);
                if ($term === '' || $entryUsage >= $maxPerEntry || $totalReplacements >= $maxGlobal) {
                    continue;
                }

                $pattern = $this->buildPattern($term, (int) ($entry['is_whole_word'] ?? 1) === 1, (int) ($entry['is_case_sensitive'] ?? 0) === 1);
                if ($pattern === null || !preg_match($pattern, $text)) {
                    continue;
                }

                $remainingForEntry = $maxPerEntry - $entryUsage;
                $remainingGlobal = $maxGlobal - $totalReplacements;
                $limit = max(1, min($remainingForEntry, $remainingGlobal));

                $text = preg_replace_callback($pattern, function (array $matches) use ($entry, $settings, &$replacements, &$tokenCounter, &$entryUsage, &$totalReplacements): string {
                    $label = (string) ($matches[0] ?? '');
                    if ($label === '') {
                        return $label;
                    }

                    if ($this->isCurrentEntry((string) ($entry['slug'] ?? ''))) {
                        return $label;
                    }

                    $token = '%%KB_LINK_' . (++$tokenCounter) . '%%';
                    $replacements[$token] = $this->buildAnchor($entry, $label, $settings);
                    ++$entryUsage;
                    ++$totalReplacements;

                    return $token;
                }, $text, $limit) ?? $text;

                $usageCounts[$entryId] = $entryUsage;
            }
        }

        return $replacements === [] ? $text : strtr($text, $replacements);
    }

    private function buildPattern(string $term, bool $wholeWord, bool $caseSensitive): ?string
    {
        $escaped = preg_quote($term, '/');
        if ($escaped === '') {
            return null;
        }

        $pattern = $wholeWord
            ? '/(?<![\p{L}\p{N}_-])' . $escaped . '(?![\p{L}\p{N}_-])/u'
            : '/' . $escaped . '/u';

        if (!$caseSensitive) {
            $pattern .= 'i';
        }

        return $pattern;
    }

    /**
     * @param array<string, string> $settings
     * @param array<string, mixed> $entry
     */
    private function buildAnchor(array $entry, string $label, array $settings): string
    {
        $href = htmlspecialchars((string) ($entry['url'] ?? '#'), ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $tooltipTitle = htmlspecialchars((string) ($entry['title'] ?? $label), ENT_QUOTES, 'UTF-8');
        $tooltipBody = htmlspecialchars($this->tooltipBody($entry), ENT_QUOTES, 'UTF-8');
        $target = ($settings['open_links_new_tab'] ?? '0') === '1' ? ' target="_blank"' : '';

        $rel = [];
        if (($settings['nofollow_links'] ?? '0') === '1') {
            $rel[] = 'nofollow';
        }
        if (($settings['open_links_new_tab'] ?? '0') === '1') {
            $rel[] = 'noopener';
            $rel[] = 'noreferrer';
        }

        $relAttr = $rel !== [] ? ' rel="' . htmlspecialchars(implode(' ', $rel), ENT_QUOTES, 'UTF-8') . '"' : '';
        $tooltipAttributes = '';
        if (($settings['enable_tooltips'] ?? '0') === '1'
            && $tooltipBody !== ''
            && RequestInspector::shouldAttachTooltipAttributes()
        ) {
            $tooltipAttributes = ' data-kb-tooltip-title="' . $tooltipTitle . '" data-kb-tooltip-body="' . $tooltipBody . '"';
        }

        return '<a href="' . $href . '" class="cms-kb-link"' . $target . $relAttr . $tooltipAttributes . '>' . $safeLabel . '</a>';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function tooltipBody(array $entry): string
    {
        $tooltip = trim((string) ($entry['tooltip_text'] ?? ''));
        if ($tooltip !== '') {
            return mb_substr($tooltip, 0, 220, 'UTF-8');
        }

        $excerpt = trim((string) ($entry['excerpt'] ?? ''));
        return mb_substr($excerpt, 0, 220, 'UTF-8');
    }

    /**
     * @param array<int, string> $skipTags
     * @param array<string, int> $openSkipTags
     */
    private function trackSkipTagState(string $segment, array $skipTags, array &$openSkipTags): void
    {
        if (preg_match('/^<\s*\/\s*([a-z0-9:-]+)/i', $segment, $matches)) {
            $tag = strtolower((string) ($matches[1] ?? ''));
            if (in_array($tag, $skipTags, true) && isset($openSkipTags[$tag]) && $openSkipTags[$tag] > 0) {
                --$openSkipTags[$tag];
            }

            return;
        }

        if (preg_match('/^<\s*([a-z0-9:-]+)/i', $segment, $matches)) {
            $tag = strtolower((string) ($matches[1] ?? ''));
            if (!in_array($tag, $skipTags, true)) {
                return;
            }

            if (preg_match('/\/>\s*$/', $segment)) {
                return;
            }

            $openSkipTags[$tag] = ($openSkipTags[$tag] ?? 0) + 1;
        }
    }

    /**
     * @param array<string, int> $openSkipTags
     */
    private function isInsideSkippedTag(array $openSkipTags): bool
    {
        foreach ($openSkipTags as $depth) {
            if ($depth > 0) {
                return true;
            }
        }

        return false;
    }

    private function isCurrentEntry(string $slug): bool
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return $slug !== '' && rtrim($path, '/') === '/kb/' . $slug;
    }

    private function isAutolinkEnabled(): bool
    {
        $settings = EntryRepository::instance()->getSettings();
        return ($settings['enable_autolink'] ?? '0') === '1';
    }

    private function isFrontendRequest(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($path !== '' && (str_starts_with($path, '/admin') || str_starts_with($path, '/api/'))) {
            return false;
        }

        return true;
    }

    private function isKnowledgebaseRequest(): bool
    {
        return RequestInspector::isKnowledgebaseRequest();
    }

    private function linkDocumentRegions(string $html): string
    {
        if (!class_exists(DOMDocument::class)) {
            return $html;
        }

        $internalErrors = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        if ($loaded !== true) {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
            return $html;
        }

        $xpath = new DOMXPath($dom);
        $conditions = array_map(
            static fn(string $class): string => "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')",
            self::BUFFER_TARGET_CLASSES
        );
        $query = '//*[self::div or self::section or self::article][' . implode(' or ', $conditions) . ']';
        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length === 0) {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
            return $html;
        }

        $updated = false;
        /** @var DOMElement $node */
        foreach ($nodes as $node) {
            $innerHtml = $this->getInnerHtml($node);
            if ($innerHtml === '' || strpos($innerHtml, 'cms-kb-link') !== false) {
                continue;
            }

            $linkedHtml = $this->linkHtml($innerHtml);
            if ($linkedHtml === $innerHtml) {
                continue;
            }

            $this->replaceInnerHtml($node, $linkedHtml);
            $updated = true;
        }

        $output = $updated ? $dom->saveHTML() : $html;
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        if (!is_string($output) || $output === '') {
            return $html;
        }

        return preg_replace('/^<\?xml.+?\?>/i', '', $output) ?? $html;
    }

    private function getInnerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }

    private function replaceInnerHtml(DOMElement $node, string $html): void
    {
        while ($node->firstChild !== null) {
            $node->removeChild($node->firstChild);
        }

        $fragmentDocument = new DOMDocument('1.0', 'UTF-8');
        $fragmentDocument->loadHTML(
            '<?xml encoding="utf-8" ?><div id="kb-fragment-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $wrapper = $fragmentDocument->documentElement;
        if (!$wrapper instanceof DOMElement) {
            $node->appendChild($node->ownerDocument->createTextNode($html));
            return;
        }

        foreach (iterator_to_array($wrapper->childNodes) as $child) {
            $imported = $node->ownerDocument->importNode($child, true);
            $node->appendChild($imported);
        }
    }

    private function looksLikeHtmlDocument(string $html): bool
    {
        $trimmed = ltrim($html);
        if ($trimmed === '') {
            return false;
        }

        return str_contains($trimmed, '<html') || str_contains($trimmed, '<body') || str_contains($trimmed, '<main');
    }
}
