<?php

declare(strict_types=1);

namespace CMS\Services\Knowledgebase\Service;

use CMS\Services\Knowledgebase\Repository\EntryRepository;
use CMS\Services\Knowledgebase\Support\ContentSections;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Speichert, migriert und rendert die strukturierten EditorJS-Inhaltsbereiche.
 */
final class SectionContent
{
    private const PREFIX_PLACEHOLDERS = ['{{cms_prefix}}', '{cms_prefix}', '[cms_prefix]', '%cms_prefix%', '{{table_prefix}}', '{table_prefix}'];

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public static function editorJsAvailable(): bool
    {
        return class_exists('CMS\\Services\\EditorJs\\EditorJsSanitizer')
            && class_exists('CMS\\Services\\EditorJs\\EditorJsAssetService');
    }

    public function isEditorJsJson(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || $value[0] !== '{') {
            return false;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks']);
    }

    /**
     * Normalisiert/sanitiert einen Bereich für die Datenbank (EditorJS-JSON, Legacy-HTML als Fallback).
     */
    public function sanitizeForStorage(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (class_exists('CMS\\Services\\EditorJs\\EditorJsSanitizer')) {
            $json = (new \CMS\Services\EditorJs\EditorJsSanitizer())->sanitize($value);
            $decoded = json_decode($json, true);

            return is_array($decoded) && !empty($decoded['blocks']) ? $json : '';
        }

        if ($this->isEditorJsJson($value)) {
            return $value;
        }

        return EntryRepository::instance()->sanitizePublicRichText($value);
    }

    /**
     * Rendert einen gespeicherten Bereich (EditorJS-JSON oder Legacy-HTML) als sicheres HTML.
     */
    public function renderSection(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (class_exists('CMS\\Services\\EditorJsRenderer')) {
            $html = (string) \CMS\Services\EditorJsRenderer::getInstance()->render($value);
        } elseif ($this->isEditorJsJson($value)) {
            $html = $this->renderPlainBlocks($value);
        } else {
            $html = EntryRepository::instance()->sanitizePublicRichText($value);
        }

        $html = str_replace(self::PREFIX_PLACEHOLDERS, \CMS\Database::instance()->getPrefix(), $html);

        if (class_exists('\\CMS\\Services\\SiteTableService')) {
            $html = \CMS\Services\SiteTableService::getInstance()->replaceShortcodes($html);
        }

        return trim($html);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<int, array{key: string, heading: string, html: string}>
     */
    public function renderEntrySections(array $entry, string $lang = 'de'): array
    {
        $sections = [];

        foreach (ContentSections::definitions() as $key => $definition) {
            $html = $this->renderSection((string) ($entry[$definition['column']] ?? ''));
            if (trim(strip_tags($html, '<img><table><iframe><video><audio><hr>')) === '') {
                continue;
            }

            $sections[] = [
                'key' => $key,
                'heading' => $lang === 'en' ? $definition['heading_en'] : $definition['heading_de'],
                'html' => $html,
            ];
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function plainText(array $entry): string
    {
        $parts = [];
        foreach (ContentSections::columns() as $column) {
            $raw = trim((string) ($entry[$column] ?? ''));
            if ($raw === '') {
                continue;
            }

            $html = $this->renderSection($raw);
            $parts[] = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /**
     * Teilt Legacy-HTML anhand bekannter Überschriften auf die Inhaltsbereiche auf.
     *
     * @return array<string, string> Spaltenname => HTML-Fragment
     */
    public function splitLegacyHtml(string $html, string $excerpt = ''): array
    {
        $definitions = ContentSections::definitions();
        $buckets = array_fill_keys(array_keys($definitions), '');
        $html = trim($html);

        if ($html !== '' && class_exists(\DOMDocument::class)) {
            $previous = libxml_use_internal_errors(true);
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $loaded = $dom->loadHTML(
                '<?xml encoding="utf-8" ?><div id="kb-legacy-root">' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
            );
            $root = $loaded ? $dom->getElementById('kb-legacy-root') : null;

            if ($root instanceof \DOMElement) {
                $current = 'overview';
                $normalizedExcerpt = $this->normalizeCompareText($excerpt);

                foreach (iterator_to_array($root->childNodes) as $child) {
                    if ($child instanceof \DOMElement && preg_match('/^h[2-4]$/', $child->tagName) === 1) {
                        $headingText = (string) $child->textContent;
                        $normalizedHeading = mb_strtolower(trim($headingText), 'UTF-8');

                        if ($normalizedHeading === 'metadaten') {
                            $current = '__drop';
                            continue;
                        }

                        if ($normalizedHeading === 'kurzbeschreibung') {
                            $current = '__short';
                            continue;
                        }

                        $matched = ContentSections::matchLegacyHeading($headingText);
                        if ($matched !== null) {
                            $current = $matched;
                            continue;
                        }

                        if ($current === '__drop' || $current === '__short') {
                            $current = 'overview';
                        }
                    }

                    $fragment = (string) $dom->saveHTML($child);
                    if ($current === '__drop') {
                        continue;
                    }

                    if ($current === '__short') {
                        if ($normalizedExcerpt !== '' && $this->normalizeCompareText($fragment) === $normalizedExcerpt) {
                            continue;
                        }

                        $buckets['overview'] .= $fragment;
                        continue;
                    }

                    $buckets[$current] .= $fragment;
                }
            } else {
                $buckets['overview'] = $html;
            }

            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        } else {
            $buckets['overview'] = $html;
        }

        $result = [];
        foreach ($definitions as $key => $definition) {
            $result[$definition['column']] = trim($buckets[$key]);
        }

        return $result;
    }

    private function normalizeCompareText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }

    private function renderPlainBlocks(string $json): string
    {
        $decoded = json_decode($json, true);
        $html = '';

        foreach ((array) ($decoded['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $text = trim(strip_tags((string) ($data['text'] ?? '')));
            if ($text !== '') {
                $html .= '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
            }
        }

        return $html;
    }
}
