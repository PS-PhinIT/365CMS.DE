<?php
/**
 * Editor.js Renderer
 *
 * Rendert Editor.js JSON-Daten in sauberes HTML für das Frontend.
 * Unterstützt Standard-Tools sowie zusätzliche 365CMS-Plugins.
 *
 * @package CMSv2\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Services\EditorJs\EditorJsContentNormalizer;
use CMS\Services\EditorJs\EditorJsHtmlSanitizer;

if (!defined('ABSPATH')) {
    exit;
}

final class EditorJsRenderer
{
    private static ?self $instance = null;
    private ?bool $lazyLoadingEnabled = null;
    private ?int $eagerImageCount = null;
    private int $renderDepth = 0;
    private int $renderedImageCount = 0;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * Editor.js JSON rendern.
     *
     * @param string|array<string,mixed> $data
     */
    public function render(string|array $data): string
    {
        $isRootRender = $this->renderDepth === 0;
        if ($isRootRender) {
            $this->renderedImageCount = 0;
        }

        $this->renderDepth++;

        $data = is_string($data) ? EditorJsContentNormalizer::normalize($data) : EditorJsContentNormalizer::normalize($data);

        if (!isset($data['blocks']) || !is_array($data['blocks'])) {
            $this->renderDepth = max(0, $this->renderDepth - 1);
            return '';
        }

        try {
            return $this->renderBlocks($data['blocks']);
        } finally {
            $this->renderDepth = max(0, $this->renderDepth - 1);
        }
    }

    /**
     * @param array<int, array<string,mixed>> $blocks
     */
    private function renderBlocks(array $blocks): string
    {
        $html = '';
        $count = count($blocks);

        for ($index = 0; $index < $count; $index++) {
            $block = $blocks[$index] ?? null;
            if (!is_array($block)) {
                continue;
            }

            $type = (string)($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if ($type === 'accordion') {
                $nestedBlocks = [];
                $blockCount = max(1, (int)($data['settings']['blockCount'] ?? 3));

                for ($offset = 1; $offset <= $blockCount; $offset++) {
                    $nextBlock = $blocks[$index + $offset] ?? null;
                    if (!is_array($nextBlock) || (string)($nextBlock['type'] ?? '') === 'accordion') {
                        break;
                    }
                    $nestedBlocks[] = $nextBlock;
                }
                try {
                    $html .= $this->renderAccordion($data, $nestedBlocks);
                } catch (\Throwable) {
                    $html .= $this->renderUnknownBlockFallback($data);
                }
                $index += count($nestedBlocks);
                continue;
            }

            try {
                $html .= $this->renderBlock($block);
            } catch (\Throwable) {
                $html .= $this->renderUnknownBlockFallback($data);
            }
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $block
     */
    private function renderBlock(array $block): string
    {
        $type = $this->normalizeBlockType((string)($block['type'] ?? ''));
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $tunes = is_array($block['tunes'] ?? null) ? $block['tunes'] : [];
        $data = $this->applyVisualTuneData($data, $tunes);
        $html = '';

        if ((string)($block['type'] ?? '') === 'checklist') {
            $data['style'] = 'checklist';
        }

        $html = match ($type) {
            'paragraph' => $this->renderParagraph($data),
            'header' => $this->renderHeader($data),
            'list' => $this->renderList($data),
            'checklist' => $this->renderChecklist($data),
            'quote' => $this->renderQuote($data),
            'warning' => $this->renderWarning($data),
            'alert' => $this->renderAlert($data),
            'code' => $this->renderCode($data),
            'raw' => $this->renderRaw($data),
            'table' => $this->renderTable($data),
            'image' => $this->renderImage($data, $tunes),
            'attaches' => $this->renderAttaches($data),
            'linkTool' => $this->renderLinkTool($data),
            'delimiter' => $this->renderDelimiter($data),
            'spacer' => $this->renderSpacer($data),
            'embed' => $this->renderEmbed($data),
            'imageGallery' => $this->renderImageGallery($data),
            'carousel' => $this->renderCarousel($data),
            'columns' => $this->renderColumns($data),
            'drawingTool' => $this->renderDrawingTool($data),
            'mediaText' => $this->renderMediaText($data),
            'button' => $this->renderButton($data),
            'callout' => $this->renderCallout($data),
            'terminal' => $this->renderTerminal($data),
            'codeTabs' => $this->renderCodeTabs($data),
            'mermaid' => $this->renderMermaid($data),
            'apiEndpoint' => $this->renderApiEndpoint($data),
            'changelog' => $this->renderChangelog($data),
            'prosCons' => $this->renderProsCons($data),
            'details' => $this->renderDetails($data),
            default => $this->renderUnknownBlockFallback($data),
        };

        return $this->applyBlockTuneAttributes($html, $tunes);
    }

    private function normalizeBlockType(string $type): string
    {
        return match ($type) {
            'checklist' => 'list',
            'link' => 'linkTool',
            'gallery', 'image_gallery', 'image-gallery' => 'imageGallery',
            'space' => 'spacer',
            'media-text', 'media_text', 'mediatext', 'core/media-text', 'wp:media-text' => 'mediaText',
            'core/image', 'wp:image' => 'image',
            'core/paragraph', 'wp:paragraph' => 'paragraph',
            'core/heading', 'wp:heading' => 'header',
            'core/list', 'wp:list' => 'list',
            'core/quote', 'wp:quote' => 'quote',
            'core/html', 'wp:html' => 'raw',
            'core/separator', 'wp:separator' => 'delimiter',
            default => $type,
        };
    }

    /**
     * @param array<string,mixed> $tunes
     */
    private function applyBlockTuneAttributes(string $html, array $tunes): string
    {
        if ($html === '' || $tunes === []) {
            return $html;
        }

        $attributes = [];
        $classes = [];
        $styles = [];
        $anchor = $this->sanitizeAnchorTune($tunes['anchor'] ?? null);
        $textVariant = $this->sanitizeTextVariantTune($tunes['textVariant'] ?? null);
        $indentLevel = $this->sanitizeIndentLevel($tunes['indentTune'] ?? null);

        if ($anchor !== '') {
            $attributes[] = 'id="' . htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8') . '"';
            $attributes[] = 'data-editorjs-anchor="' . htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8') . '"';
        }

        if ($textVariant !== '') {
            $classes[] = 'editorjs-text-variant';
            $classes[] = 'editorjs-text-variant--' . $textVariant;
            $attributes[] = 'data-editorjs-text-variant="' . htmlspecialchars($textVariant, ENT_QUOTES, 'UTF-8') . '"';
        }

        if ($indentLevel > 0) {
            $classes[] = 'editorjs-indent';
            $classes[] = 'editorjs-indent--' . $indentLevel;
            $attributes[] = 'data-editorjs-indent="' . $indentLevel . '"';
            $styles[] = 'margin-left:' . ($indentLevel * 1.5) . 'rem';
        }

        if ($attributes === [] && $classes === [] && $styles === []) {
            return $html;
        }

        if ($attributes !== []) {
            $html = (string) preg_replace('/^<([a-z][a-z0-9:-]*)(\s[^>]*)?>/i', '<$1$2 ' . implode(' ', $attributes) . '>', $html, 1);
        }

        if ($classes !== []) {
            $classValue = htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8');
            if (preg_match('/^<[^>]+\sclass="[^"]*"/i', $html) === 1) {
                $html = (string) preg_replace('/^(<[^>]+\sclass=")([^"]*)(")/i', '$1$2 ' . $classValue . '$3', $html, 1);
            } else {
                $html = (string) preg_replace('/^<([a-z][a-z0-9:-]*)(\s[^>]*)?>/i', '<$1$2 class="' . $classValue . '">', $html, 1);
            }
        }

        if ($styles !== []) {
            $styleValue = htmlspecialchars(implode(';', $styles), ENT_QUOTES, 'UTF-8');
            if (preg_match('/^<[^>]+\sstyle="[^"]*"/i', $html) === 1) {
                $html = (string) preg_replace_callback('/^(<[^>]+\sstyle=")([^"]*)(")/i', static function (array $matches) use ($styleValue): string {
                    $existingStyle = rtrim(trim($matches[2]), ';');
                    return $matches[1] . ($existingStyle !== '' ? $existingStyle . ';' : '') . $styleValue . $matches[3];
                }, $html, 1);
            } else {
                $html = (string) preg_replace('/^<([a-z][a-z0-9:-]*)(\s[^>]*)?>/i', '<$1$2 style="' . $styleValue . '">', $html, 1);
            }
        }

        return $html;
    }

    private function sanitizeAnchorTune(mixed $value): string
    {
        $anchor = strtolower(trim((string) $value));
        $anchor = (string) preg_replace('/\s+/', '-', $anchor);
        $anchor = (string) preg_replace('/[^a-z0-9_-]/', '', $anchor);
        $anchor = trim($anchor, '-_');

        return substr($anchor, 0, 80);
    }

    private function sanitizeTextVariantTune(mixed $value): string
    {
        $variant = (string) $value;

        return in_array($variant, ['call-out', 'citation', 'details'], true) ? $variant : '';
    }

    private function sanitizeIndentLevel(mixed $value): int
    {
        if (!is_array($value)) {
            return 0;
        }

        return max(0, min(8, (int) ($value['indentLevel'] ?? 0)));
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $tunes
     * @return array<string,mixed>
     */
    private function applyVisualTuneData(array $data, array $tunes): array
    {
        $visualTune = $this->extractVisualTuneData($tunes);

        if (!isset($data['spacing']) && isset($visualTune['spacing'])) {
            $data['spacing'] = $visualTune['spacing'];
        }
        if (!isset($data['alignment']) && isset($visualTune['alignment'])) {
            $data['alignment'] = $visualTune['alignment'];
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $tunes
     * @return array{spacing?:string,alignment?:string}
     */
    private function extractVisualTuneData(array $tunes): array
    {
        $sources = [];
        foreach (['cmsVisual', 'cmsSpacing', 'spacing', 'spacingTune', 'alignmentTune'] as $key) {
            if (isset($tunes[$key]) && is_array($tunes[$key])) {
                $sources[] = $tunes[$key];
            }
        }

        $visual = [];
        foreach ($sources as $source) {
            $spacing = (string)($source['spacing'] ?? $source['space'] ?? '');
            if ($spacing !== '' && in_array($spacing, ['compact', 'normal', 'relaxed', 'loose'], true)) {
                $visual['spacing'] = $spacing;
            }

            $alignment = (string)($source['alignment'] ?? $source['align'] ?? '');
            if ($alignment !== '' && in_array($alignment, ['left', 'center', 'right', 'justify'], true)) {
                $visual['alignment'] = $alignment;
            }
        }

        return $visual;
    }

    /** @param array<string,mixed> $data */
    private function renderUnknownBlockFallback(array $data): string
    {
        $text = $this->sanitizeInline((string)($data['text'] ?? $data['content'] ?? $data['html'] ?? $data['caption'] ?? ''));

        return $text !== '' ? '<div class="editorjs-block editorjs-unknown"><p>' . $text . '</p></div>' : '';
    }

    /** @param array<string,mixed> $data */
    private function renderParagraph(array $data): string
    {
        $rawText = (string)($data['text'] ?? '');
        $text = $this->sanitizeInline($rawText);
        $attributes = $this->buildTextBlockAttributes($data, 'editorjs-paragraph');

        if ($text === '') {
            return array_key_exists('text', $data)
                ? '<div' . $attributes . '><p><br></p></div>'
                : '';
        }

        return '<div' . $attributes . '><p>' . $text . '</p></div>';
    }

    /** @param array<string,mixed> $data */
    private function renderHeader(array $data): string
    {
        $text = $this->sanitizeInline((string)($data['text'] ?? ''));
        $level = max(1, min(6, (int)($data['level'] ?? 2)));
        $attributes = $this->buildTextBlockAttributes($data, 'editorjs-header');

        return $text !== '' ? '<div' . $attributes . '><h' . $level . '>' . $text . '</h' . $level . '></div>' : '';
    }

    /** @param array<string,mixed> $data */
    private function buildTextBlockAttributes(array $data, string $baseClass): string
    {
        $alignment = (string)($data['alignment'] ?? 'left');
        $spacing = (string)($data['spacing'] ?? 'normal');
        $classes = ['editorjs-block', $baseClass];
        $styles = [];

        if (!in_array($alignment, ['left', 'center', 'right', 'justify'], true)) {
            $alignment = 'left';
        }
        if (!in_array($spacing, ['compact', 'normal', 'relaxed', 'loose'], true)) {
            $spacing = 'normal';
        }

        $classes[] = $baseClass . '--align-' . $alignment;
        $classes[] = $baseClass . '--spacing-' . $spacing;
        $styles[] = 'text-align:' . $alignment;
        $styles[] = '--cms-editorjs-space-before:0rem';
        $styles[] = '--cms-editorjs-space-after:' . match ($spacing) {
            'compact' => '0.25rem',
            'relaxed' => '1rem',
            'loose' => '1.6rem',
            default => '0.65rem',
        };
        $styles[] = 'margin-top:0';
        $styles[] = 'margin-bottom:var(--cms-editorjs-space-after)';
        $styles[] = 'margin-block-start:0';
        $styles[] = 'margin-block-end:var(--cms-editorjs-space-after)';

        return ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '" data-cms-editorjs-spacing="' . htmlspecialchars($spacing, ENT_QUOTES, 'UTF-8') . '" data-cms-editorjs-align="' . htmlspecialchars($alignment, ENT_QUOTES, 'UTF-8') . '" style="' . htmlspecialchars(implode(';', $styles), ENT_QUOTES, 'UTF-8') . '"';
    }

    /** @param array<string,mixed> $data */
    private function renderList(array $data): string
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if ($items === []) {
            return '';
        }

        $style = (string)($data['style'] ?? 'unordered');
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        return '<div class="editorjs-block editorjs-list">' . $this->renderListMarkup($items, $style, $meta) . '</div>';
    }

    /** @param array<int, mixed> $items */
    private function renderListMarkup(array $items, string $style, array $meta = []): string
    {
        $tag = $style === 'ordered' ? 'ol' : 'ul';
        $attributes = '';

        if ($style === 'ordered') {
            $start = max(1, (int)($meta['start'] ?? 1));
            if ($start > 1) {
                $attributes .= ' start="' . $start . '"';
            }

            $counterType = (string)($meta['counterType'] ?? 'numeric');
            $typeMap = [
                'numeric' => '1',
                'lower-alpha' => 'a',
                'upper-alpha' => 'A',
                'lower-roman' => 'i',
                'upper-roman' => 'I',
            ];
            if (isset($typeMap[$counterType])) {
                $attributes .= ' type="' . $typeMap[$counterType] . '"';
            }
        }

        $class = 'editorjs-list__items';
        if ($style === 'checklist') {
            $class .= ' editorjs-list__items--checklist';
        }

        $html = '<' . $tag . ' class="' . $class . '"' . $attributes . '>';
        foreach ($items as $item) {
            if (is_string($item)) {
                $item = ['content' => $item, 'items' => []];
            }
            if (!is_array($item)) {
                continue;
            }

            $content = $this->sanitizeInline((string)($item['content'] ?? $item['text'] ?? ''));
            $children = is_array($item['items'] ?? null) ? $item['items'] : [];
            $itemMeta = is_array($item['meta'] ?? null) ? $item['meta'] : [];

            $html .= '<li class="editorjs-list__item">';
            if ($style === 'checklist') {
                $checked = (!empty($itemMeta['checked']) || !empty($item['checked'])) ? ' checked' : '';
                $html .= '<span class="editorjs-checklist__label"><input type="checkbox" disabled' . $checked . '><span>' . $content . '</span></span>';
            } else {
                $html .= $content;
            }

            if ($children !== []) {
                $html .= $this->renderListMarkup($children, $style, $itemMeta);
            }
            $html .= '</li>';
        }
        $html .= '</' . $tag . '>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderChecklist(array $data): string
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if ($items === []) {
            return '';
        }

        $html = '<div class="editorjs-block editorjs-checklist"><ul>';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemMeta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
            $text = $this->sanitizeInline((string)($item['text'] ?? $item['content'] ?? ''));
            $checked = (!empty($item['checked']) || !empty($itemMeta['checked'])) ? ' checked' : '';
            $html .= '<li><label><input type="checkbox" disabled' . $checked . '> ' . $text . '</label></li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderQuote(array $data): string
    {
        $text = $this->sanitizeInline((string)($data['text'] ?? ''));
        $caption = $this->sanitizeInline((string)($data['caption'] ?? ''));
        $alignment = (string)($data['alignment'] ?? 'left');
        $alignmentClass = in_array($alignment, ['left', 'center'], true) ? ' editorjs-quote--' . $alignment : '';
        $design = (string)($data['design'] ?? 'bar');
        if (!in_array($design, ['bar', 'card', 'minimal', 'mark'], true)) {
            $design = 'bar';
        }
        if ($text === '') {
            return '';
        }

        $html = '<div class="editorjs-block editorjs-quote' . $alignmentClass . ' editorjs-quote--design-' . $design . '"><blockquote><p>' . $text . '</p>';
        if ($caption !== '') {
            $html .= '<cite>' . $caption . '</cite>';
        }
        $html .= '</blockquote></div>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderWarning(array $data): string
    {
        $variant = (string)($data['variant'] ?? $data['type'] ?? $data['tone'] ?? 'info');
        $title = $this->sanitizeInline((string)($data['title'] ?? 'Hinweis'));
        $message = $this->sanitizeInline((string)($data['message'] ?? ''));
        if ($title === '' && $message === '') {
            return '';
        }

        if (!in_array($variant, ['info', 'warning', 'success', 'danger'], true)) {
            $variant = 'info';
        }

        return '<div class="editorjs-block editorjs-warning editorjs-warning--' . htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') . '" data-variant="' . htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') . '"><div class="warning-title">' . ($title !== '' ? $title : 'Hinweis') . '</div><div class="warning-message">' . $message . '</div></div>';
    }

    /** @param array<string,mixed> $data */
    private function renderAlert(array $data): string
    {
        $type = strtolower((string) ($data['type'] ?? $data['variant'] ?? 'info'));
        $align = strtolower((string) ($data['align'] ?? $data['alignment'] ?? 'left'));
        $message = $this->sanitizeInline((string) ($data['message'] ?? $data['text'] ?? ''));

        if ($message === '') {
            return '';
        }

        if (!in_array($type, ['primary', 'secondary', 'info', 'success', 'warning', 'danger', 'light', 'dark'], true)) {
            $type = 'info';
        }
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'left';
        }

        $variantMap = [
            'primary' => 'info',
            'secondary' => 'info',
            'light' => 'info',
            'dark' => 'info',
            'info' => 'info',
            'success' => 'success',
            'warning' => 'warning',
            'danger' => 'danger',
        ];
        $warningVariant = $variantMap[$type] ?? 'info';

        return '<div class="editorjs-block editorjs-alert editorjs-alert--' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . ' editorjs-alert--align-' . htmlspecialchars($align, ENT_QUOTES, 'UTF-8') . ' editorjs-warning editorjs-warning--' . htmlspecialchars($warningVariant, ENT_QUOTES, 'UTF-8') . '" data-variant="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '"><div class="warning-message">' . $message . '</div></div>';
    }

    /** @param array<string,mixed> $data */
    private function renderCode(array $data): string
    {
        $code = htmlspecialchars((string)($data['code'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($code === '') {
            return '';
        }

        $language = trim((string)($data['language'] ?? ''));
        $class = $language !== '' ? ' class="language-' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '"' : '';

        return '<div class="editorjs-block editorjs-code"><pre><code' . $class . '>' . $code . '</code></pre></div>';
    }

    /** @param array<string,mixed> $data */
    private function renderRaw(array $data): string
    {
        $html = EditorJsHtmlSanitizer::sanitizeRawBlock((string)($data['html'] ?? ''));
        return $html !== '' ? '<div class="editorjs-block editorjs-raw">' . $html . '</div>' : '';
    }

    /** @param array<string,mixed> $data */
    private function renderTable(array $data): string
    {
        $rows = is_array($data['content'] ?? null) ? $data['content'] : [];
        if ($rows === []) {
            return '';
        }

        $withHeadings = !empty($data['withHeadings']);
        $html = '<div class="editorjs-block editorjs-table"><table>';

        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }
            $html .= '<tr>';
            foreach ($row as $cell) {
                $tag = $withHeadings && $rowIndex === 0 ? 'th' : 'td';
                $html .= '<' . $tag . '>' . $this->sanitizeInline((string)$cell) . '</' . $tag . '>';
            }
            $html .= '</tr>';
        }

        $html .= '</table></div>';
        return $html;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $tunes
     */
    private function renderImage(array $data, array $tunes = []): string
    {
        $file = is_array($data['file'] ?? null) ? $data['file'] : [];
        $imageUrl = $this->normalizeRenderableAssetUrl((string)($file['url'] ?? $data['url'] ?? ''), true);

        foreach (['Cropper', 'CropperTune'] as $tuneKey) {
            $croppedImage = $this->normalizeRenderableAssetUrl((string) ($tunes[$tuneKey]['croppedImage'] ?? ''), true);
            if ($croppedImage !== '') {
                $imageUrl = $croppedImage;
                break;
            }
        }

        if ($imageUrl === '') {
            return '';
        }

        $caption = $this->sanitizeInline((string)($data['caption'] ?? ''));
        $sourceUrl = EditorJsHtmlSanitizer::sanitizeUrl(
            (string) ($data['sourceUrl'] ?? $data['source_url'] ?? $data['imageSource'] ?? $data['source'] ?? ''),
            ['http', 'https'],
            false
        );
        $classes = ['editorjs-block', 'editorjs-image'];
        $alignment = (string) ($data['alignment'] ?? $data['align'] ?? 'center');
        $size = (string) ($data['size'] ?? $data['widthPreset'] ?? (!empty($data['stretched']) ? 'full' : 'normal'));
        $borderStyle = (string) ($data['borderStyle'] ?? (!empty($data['withBorder']) ? 'thin' : 'none'));
        $imageFit = $this->normalizeImageFit($data['imageFit'] ?? $data['objectFit'] ?? $data['fit'] ?? 'contain', 'contain');
        $maxHeight = $this->normalizeImageMaxHeight($data['maxHeight'] ?? $data['imageMaxHeight'] ?? $data['max_height'] ?? 0);
        $figureStyles = [];
        $imageStyles = ['display:block', 'height:auto!important', 'box-sizing:border-box'];

        if (!in_array($alignment, ['left', 'center', 'right'], true)) {
            $alignment = 'center';
        }
        if (!in_array($size, ['normal', 'wide', 'full'], true)) {
            $size = 'normal';
        }
        if (!in_array($borderStyle, ['none', 'thin', 'medium', 'thick'], true)) {
            $borderStyle = !empty($data['withBorder']) ? 'thin' : 'none';
        }

        $classes[] = 'editorjs-image--align-' . $alignment;
        $classes[] = 'editorjs-image--' . $size;
        $classes[] = 'editorjs-image--fit-' . $imageFit;
        $classes[] = 'editorjs-image--max-height-' . $maxHeight;
        $figureStyles[] = '--cms-editorjs-image-fit:' . $imageFit;

        if ($borderStyle !== 'none') {
            $classes[] = 'editorjs-image--border';
            $classes[] = 'editorjs-image--border-' . $borderStyle;
        }
        if (!empty($data['withBackground'])) {
            $classes[] = 'editorjs-image--background';
            $figureStyles[] = 'padding:1rem';
            $figureStyles[] = 'background:#f8fafc';
            $figureStyles[] = 'border-radius:16px';
        }
        if ($size === 'full' || !empty($data['stretched'])) {
            $classes[] = 'editorjs-image--stretched';
        }
        if (array_key_exists('rounded', $data) ? !empty($data['rounded']) : false) {
            $classes[] = 'editorjs-image--rounded';
            $imageStyles[] = 'border-radius:12px';
        }
        if (!empty($data['shadow'])) {
            $classes[] = 'editorjs-image--shadow';
            $imageStyles[] = 'box-shadow:0 18px 40px rgba(15,23,42,.16)';
        }

        if ($size === 'full') {
            $imageStyles[] = 'width:100%!important';
            $imageStyles[] = 'max-width:100%!important';
        } elseif ($size === 'wide') {
            $imageStyles[] = 'width:min(100%,var(--cms-editorjs-image-wide-width,1000px))!important';
            $imageStyles[] = 'max-width:100%!important';
        } else {
            $imageStyles[] = 'width:var(--cms-editorjs-image-normal-width,auto)!important';
            $imageStyles[] = 'max-width:100%!important';
        }
        $imageStyles[] = 'object-fit:' . $imageFit . '!important';
        if ($maxHeight > 0) {
            $imageStyles[] = 'max-height:' . $maxHeight . 'px!important';
            if (in_array($imageFit, ['cover', 'fill'], true)) {
                $imageStyles[] = 'height:' . $maxHeight . 'px!important';
            }
        }

        if ($alignment === 'left') {
            $figureStyles[] = 'text-align:left';
            $imageStyles[] = 'margin-left:0';
            $imageStyles[] = 'margin-right:auto';
        } elseif ($alignment === 'right') {
            $figureStyles[] = 'text-align:right';
            $imageStyles[] = 'margin-left:auto';
            $imageStyles[] = 'margin-right:0';
        } else {
            $figureStyles[] = 'text-align:center';
            $imageStyles[] = 'margin-left:auto';
            $imageStyles[] = 'margin-right:auto';
        }

        $borderWidths = ['thin' => '1px', 'medium' => '2px', 'thick' => '4px'];
        if (isset($borderWidths[$borderStyle])) {
            $imageStyles[] = 'border:' . $borderWidths[$borderStyle] . ' solid #cbd5e1!important';
        }

        $styleAttr = $figureStyles !== [] ? ' style="' . htmlspecialchars(implode(';', $figureStyles), ENT_QUOTES, 'UTF-8') . '"' : '';
        $imageStyleAttr = ' style="' . htmlspecialchars(implode(';', $imageStyles), ENT_QUOTES, 'UTF-8') . '"';
        $dataAttributes = ' data-align="' . htmlspecialchars($alignment, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-size="' . htmlspecialchars($size, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-border="' . htmlspecialchars($borderStyle, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-image-fit="' . htmlspecialchars($imageFit, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-max-height="' . $maxHeight . '"'
            . ' data-background="' . (!empty($data['withBackground']) ? '1' : '0') . '"'
            . ' data-rounded="' . ((array_key_exists('rounded', $data) ? !empty($data['rounded']) : false) ? '1' : '0') . '"'
            . ' data-shadow="' . (!empty($data['shadow']) ? '1' : '0') . '"';

        $isGeneratedCaption = $caption !== '' && $this->isGeneratedFilenameCaption($caption, $imageUrl);
        $altText = $isGeneratedCaption ? '' : $caption;

        $html = '<figure class="' . implode(' ', $classes) . '"' . $dataAttributes . $styleAttr . '>';
        $html .= '<img src="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars(strip_tags($altText), ENT_QUOTES, 'UTF-8') . '"' . $imageStyleAttr . $this->getLazyLoadingAttribute() . '>';
        if (!$isGeneratedCaption && $caption !== '') {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }
        if ($sourceUrl !== '') {
            $html .= '<a class="editorjs-image__source" href="' . htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer external" aria-label="Bildquelle in neuem Tab öffnen">';
            $html .= '<span class="editorjs-image__source-label">QUELLE</span>';
            $html .= '<span class="editorjs-image__source-icon" aria-hidden="true">↗</span>';
            $html .= '</a>';
        }
        $html .= '</figure>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderAttaches(array $data): string
    {
        $file = is_array($data['file'] ?? null) ? $data['file'] : [];
        $url = $this->normalizeRenderableAssetUrl((string)($file['url'] ?? ''), false);
        if ($url === '') {
            return '';
        }

        $name = htmlspecialchars((string)($data['title'] ?? $file['name'] ?? 'Download'), ENT_QUOTES, 'UTF-8');
        $size = max(0, (int)($file['size'] ?? 0));
        $sizeLabel = $size > 0 ? ' <span>(' . $this->formatFileSize($size) . ')</span>' : '';

        return '<div class="editorjs-block editorjs-attaches"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $name . $sizeLabel . '</a></div>';
    }

    /** @param array<string,mixed> $data */
    private function renderLinkTool(array $data): string
    {
        $link = EditorJsHtmlSanitizer::sanitizeUrl((string)($data['link'] ?? ''), ['http', 'https', 'mailto', 'tel'], false);
        if ($link === '') {
            return '';
        }

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $title = $this->sanitizeInline((string)($meta['title'] ?? $link));
        $description = $this->sanitizeInline((string)($meta['description'] ?? ''));
        $image = $this->normalizeRenderableAssetUrl((string)($meta['image']['url'] ?? ''), true);

        $html = '<div class="editorjs-block editorjs-link"><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">';
        if ($image !== '') {
            $html .= '<div class="editorjs-link__image"><img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt=""' . $this->getLazyLoadingAttribute() . '></div>';
        }
        $html .= '<div class="editorjs-link__content"><strong>' . $title . '</strong>';
        if ($description !== '') {
            $html .= '<p>' . $description . '</p>';
        }
        $html .= '<small>' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</small></div></a></div>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderDelimiter(array $data): string
    {
        $style = strtolower((string) ($data['style'] ?? $data['type'] ?? 'line'));
        $lineWidth = (int) ($data['lineWidth'] ?? $data['width'] ?? 35);
        $lineThickness = (int) ($data['lineThickness'] ?? $data['thickness'] ?? 2);

        if (!in_array($style, ['line', 'dash', 'star'], true)) {
            $style = 'line';
        }
        $lineWidth = $this->snapNumberToPreset($lineWidth, [8, 15, 25, 35, 50, 60, 100], 35);
        $lineThickness = $this->snapNumberToPreset($lineThickness, [1, 2, 3, 4, 5, 6], 2);

        $classes = ['editorjs-block', 'editorjs-delimiter', 'editorjs-delimiter--' . $style];
        if ($style === 'line') {
            $classes[] = 'editorjs-delimiter--width-' . $lineWidth;
            $classes[] = 'editorjs-delimiter--thickness-' . $lineThickness;
        }
        $attributes = ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"';

        if ($style === 'line') {
            $attributes .= ' data-line-width="' . $lineWidth . '" data-line-thickness="' . $lineThickness . '"'
                . ' style="' . htmlspecialchars('--cms-editorjs-delimiter-width:' . $lineWidth . '%;--cms-editorjs-delimiter-thickness:' . $lineThickness . 'px', ENT_QUOTES, 'UTF-8') . '"';
            return '<div' . $attributes . '><hr aria-hidden="true"></div>';
        }

        $symbol = $style === 'dash' ? '———' : '***';
        return '<div' . $attributes . ' role="separator" aria-hidden="true"><span>' . htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8') . '</span></div>';
    }

    /** @param array<string,mixed> $data */
    private function renderEmbed(array $data): string
    {
        $sourceUrl = EditorJsHtmlSanitizer::sanitizeUrl((string)($data['source'] ?? $data['embed'] ?? ''), ['http', 'https'], false);
        $embedUrl = EditorJsHtmlSanitizer::sanitizeUrl((string)($data['embed'] ?? ''), ['https'], false);
        if ($sourceUrl === '') {
            return '';
        }

        $caption = $this->sanitizeInline((string)($data['caption'] ?? ''));
        $label = $caption !== '' ? $caption : htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8');

        if ($embedUrl !== '') {
            $width = max(200, min(1920, (int)($data['width'] ?? 640)));
            $height = max(120, min(1080, (int)($data['height'] ?? 360)));
            $ratio = round(($height / max(1, $width)) * 100, 4);

            return '<figure class="editorjs-block editorjs-embed">'
                . '<div class="editorjs-embed__frame" style="padding-top:' . $ratio . '%;">'
                . '<iframe src="' . htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars(strip_tags($label), ENT_QUOTES, 'UTF-8') . '" loading="lazy" allowfullscreen sandbox="allow-scripts allow-same-origin allow-presentation" referrerpolicy="strict-origin-when-cross-origin"></iframe>'
                . '</div>'
                . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '')
                . '</figure>';
        }

        return '<div class="editorjs-block editorjs-embed editorjs-embed--link-only"><a href="'
            . htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8')
            . '" target="_blank" rel="noopener noreferrer">'
            . $label
            . '</a></div>';
    }

    /** @param array<string,mixed> $data */
    private function renderSpacer(array $data): string
    {
        $allowedHeights = [0, 8, 10, 15, 16, 24, 25, 32, 40, 48, 56, 60, 64, 72, 75, 80, 96, 100, 120, 140, 150, 160, 180, 200];
        $height = $this->normalizeSpacerHeight($data);

        if (!in_array($height, $allowedHeights, true)) {
            $height = max(0, min(200, $height));
        }

        $style = 'display:block;clear:both;width:100%;height:' . $height . 'px!important;min-height:' . $height . 'px!important;margin:0!important;padding:0!important;line-height:0;font-size:0;overflow:hidden;box-sizing:border-box';

        return '<div class="editorjs-block editorjs-spacer" aria-hidden="true" role="presentation" data-height="' . $height . '" style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"><span class="editorjs-spacer__marker" aria-hidden="true">&#8203;</span></div>';
    }

    /** @param array<string,mixed> $data */
    private function normalizeSpacerHeight(array $data): int
    {
        $presetMap = [
            'none' => 0,
            'xs' => 8,
            'small' => 15,
            'sm' => 15,
            'medium' => 40,
            'md' => 40,
            'normal' => 40,
            'large' => 75,
            'lg' => 75,
            'xl' => 100,
            'xlarge' => 100,
            'huge' => 140,
            'xxl' => 160,
        ];
        $raw = (string)($data['height'] ?? $data['size'] ?? $data['value'] ?? $data['spacer'] ?? $data['space'] ?? $data['preset'] ?? '40');
        $key = strtolower(preg_replace('/\s+/', '', trim($raw)) ?? trim($raw));
        if (isset($presetMap[$key])) {
            return $presetMap[$key];
        }

        return (int)preg_replace('/[^0-9]/', '', $key);
    }

    private function normalizeMediaTextSpacing(mixed $value): string
    {
        $spacing = (int) preg_replace('/[^0-9]/', '', (string) $value);
        $allowed = [0, 5, 10, 15, 20, 30, 40, 60, 80, 100];

        return in_array($spacing, $allowed, true) ? (string) $spacing : '10';
    }

    private function normalizeMediaTextVerticalAlignment(mixed $value): string
    {
        $alignment = strtolower(trim((string) $value));

        return match ($alignment) {
            'middle', 'centre', 'center' => 'center',
            'bottom', 'end', 'flex-end' => 'bottom',
            'top', 'start', 'flex-start' => 'top',
            default => 'top',
        };
    }

    private function mediaTextAlignItems(string $verticalAlignment): string
    {
        return match ($verticalAlignment) {
            'center' => 'center',
            'bottom' => 'flex-end',
            default => 'flex-start',
        };
    }

    private function normalizeImageFit(mixed $value, string $fallback): string
    {
        $fit = (string) $value;

        return in_array($fit, ['contain', 'cover', 'fill', 'none', 'scale-down'], true) ? $fit : $fallback;
    }

    private function normalizeImageMaxHeight(mixed $value): int
    {
        $height = (int) preg_replace('/[^0-9]/', '', (string) $value);
        $allowed = [0, 200, 300, 400, 500, 600, 800, 1000];

        return $this->snapNumberToPreset($height, $allowed, 0);
    }

    /** @param int[] $presets */
    private function snapNumberToPreset(int $value, array $presets, int $fallback): int
    {
        if ($presets === []) {
            return $fallback;
        }

        $closest = in_array($fallback, $presets, true) ? $fallback : (int) $presets[0];
        foreach ($presets as $preset) {
            if (abs($preset - $value) < abs($closest - $value)) {
                $closest = (int) $preset;
            }
        }

        return $closest;
    }

    private function truncatePlainText(string $value, int $maxLength): string
    {
        $value = trim($value);

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    }

    /** @param array<string,mixed> $data */
    private function renderImageGallery(array $data): string
    {
        $columns = (int)($data['columns'] ?? 3);
        if (!in_array($columns, [2, 3, 4, 5, 6], true)) {
            $columns = 3;
        }

        $borderStyle = (string)($data['borderStyle'] ?? (!empty($data['withBorder']) ? 'thin' : 'none'));
        if (!in_array($borderStyle, ['none', 'thin', 'medium', 'thick'], true)) {
            $borderStyle = !empty($data['withBorder']) ? 'thin' : 'none';
        }

        $images = [];
        foreach ((is_array($data['images'] ?? null) ? $data['images'] : []) as $item) {
            $itemData = is_array($item) ? $item : ['url' => (string)$item];
            $file = is_array($itemData['file'] ?? null) ? $itemData['file'] : [];
            $url = $this->normalizeRenderableAssetUrl((string)($file['url'] ?? $itemData['url'] ?? $itemData['src'] ?? ''), true);
            if ($url === '') {
                continue;
            }

            $itemCaption = $this->sanitizeInline((string)($itemData['caption'] ?? $itemData['alt'] ?? ''));
            $isGeneratedItemCaption = $itemCaption !== '' && $this->isGeneratedFilenameCaption($itemCaption, $url);

            $images[] = [
                'url' => $url,
                'caption' => $itemCaption,
                'is_generated_caption' => $isGeneratedItemCaption,
                'alt' => $isGeneratedItemCaption ? '' : htmlspecialchars(strip_tags($itemCaption), ENT_QUOTES, 'UTF-8'),
            ];
        }

        if ($images === []) {
            $urls = is_array($data['urls'] ?? null) ? $data['urls'] : [];
            foreach ($urls as $url) {
                $urlValue = is_array($url) ? (string)($url['url'] ?? $url['src'] ?? $url['file']['url'] ?? '') : (string)$url;
                $normalizedUrl = $this->normalizeRenderableAssetUrl($urlValue, true);
                if ($normalizedUrl === '') {
                    continue;
                }

                $images[] = [
                    'url' => $normalizedUrl,
                    'caption' => '',
                    'is_generated_caption' => false,
                    'alt' => '',
                ];
            }
        }

        if ($images === []) {
            return '';
        }

        $galleryClasses = ['editorjs-block', 'editorjs-gallery', 'editorjs-gallery--cols-' . $columns, 'editorjs-gallery--border-' . $borderStyle];
        if ($borderStyle !== 'none') {
            $galleryClasses[] = 'editorjs-gallery--border';
        }

        $html = '<div class="' . implode(' ', $galleryClasses) . '" data-columns="' . $columns . '" data-border="' . htmlspecialchars($borderStyle, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($images as $image) {
            $html .= '<figure class="editorjs-gallery__item">';
            $html .= '<img src="' . htmlspecialchars($image['url'], ENT_QUOTES, 'UTF-8') . '" alt="' . $image['alt'] . '"' . $this->getLazyLoadingAttribute() . '>';
            if ($image['caption'] !== '' && !$image['is_generated_caption']) {
                $html .= '<figcaption>' . $image['caption'] . '</figcaption>';
            }
            $html .= '</figure>';
        }
        $html .= '</div>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderMediaText(array $data): string
    {
        $file = is_array($data['file'] ?? null) ? $data['file'] : [];
        $imageUrl = $this->normalizeRenderableAssetUrl((string)($file['url'] ?? ''), true);

        $heading = $this->truncatePlainText(strip_tags((string)($data['heading'] ?? $data['title'] ?? $data['headline'] ?? ''), ''), 180);
        $textHtml = $this->renderMediaTextContent((string)($data['text'] ?? ''));
        if ($imageUrl === '' && $textHtml === '' && $heading === '') {
            return '';
        }

        $altText = trim((string)($data['alt'] ?? ''));
        $alt = htmlspecialchars($altText, ENT_QUOTES, 'UTF-8');
        $imagePosition = (string)($data['imagePosition'] ?? $data['position'] ?? $data['mediaPosition'] ?? 'left');
        $imageWidth = (string)($data['imageWidth'] ?? $data['mediaWidth'] ?? '40');
        $imageFit = $this->normalizeImageFit($data['imageFit'] ?? $data['objectFit'] ?? $data['fit'] ?? 'cover', 'cover');
        $verticalAlignment = $this->normalizeMediaTextVerticalAlignment($data['verticalAlignment'] ?? $data['mediaVerticalAlignment'] ?? $data['imageVerticalAlignment'] ?? $data['verticalAlign'] ?? 'top');
        $showBorder = filter_var($data['showBorder'] ?? $data['border'] ?? $data['hasBorder'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $spacingTop = $this->normalizeMediaTextSpacing($data['spacingTop'] ?? $data['marginTop'] ?? $data['blockSpacingTop'] ?? 10);
        $spacingBottom = $this->normalizeMediaTextSpacing($data['spacingBottom'] ?? $data['marginBottom'] ?? $data['blockSpacingBottom'] ?? 10);

        if (!in_array($imagePosition, ['left', 'right'], true)) {
            $imagePosition = 'left';
        }
        if (!in_array($imageWidth, ['33', '40', '50'], true)) {
            $imageWidth = '40';
        }

        $classes = [
            'editorjs-block',
            'editorjs-media-text',
            'editorjs-media-text--image-' . $imagePosition,
            'editorjs-media-text--image-width-' . $imageWidth,
            'editorjs-media-text--image-fit-' . $imageFit,
            'editorjs-media-text--valign-' . $verticalAlignment,
            'editorjs-media-text--spacing-top-' . $spacingTop,
            'editorjs-media-text--spacing-bottom-' . $spacingBottom,
        ];
        if ($showBorder) {
            $classes[] = 'editorjs-media-text--bordered';
        }
        if ($heading !== '') {
            $classes[] = 'editorjs-media-text--has-heading';
        }
        $alignItems = $this->mediaTextAlignItems($verticalAlignment);
        $style = '--cms-editorjs-media-text-image-width:' . $imageWidth . '%;--cms-editorjs-media-text-image-fit:' . $imageFit . ';--cms-editorjs-media-text-align-items:' . $alignItems . ';--cms-editorjs-media-text-spacing-top:' . $spacingTop . 'px;--cms-editorjs-media-text-spacing-bottom:' . $spacingBottom . 'px;--cms-editorjs-space-before:' . $spacingTop . 'px;--cms-editorjs-space-after:' . $spacingBottom . 'px;display:flex;flex-wrap:wrap;align-items:' . $alignItems . ';gap:24px;margin:' . $spacingTop . 'px 0 ' . $spacingBottom . 'px;padding:' . ($showBorder ? '16px' : '0') . ';padding-top:' . ($showBorder && $heading !== '' ? '0' : ($showBorder ? '16px' : '0')) . ';border:' . ($showBorder ? '1px solid rgba(100,116,139,.32)' : '0 solid transparent') . ';border-radius:2px;width:100%;max-width:100%;min-width:0;box-sizing:border-box;';
        $mediaStyle = 'margin:0;flex:0 0 var(--cms-editorjs-media-text-image-width);width:var(--cms-editorjs-media-text-image-width);min-width:0;max-width:var(--cms-editorjs-media-text-image-width);box-sizing:border-box;';
        $contentStyle = 'flex:1 1 0;min-width:0;box-sizing:border-box;';
        $headingStyle = 'flex:0 0 ' . ($showBorder ? 'calc(100% + 32px)' : '100%') . ';order:-2;margin:' . ($showBorder ? '0 -16px 8px' : '0 0 8px') . ';padding:' . ($showBorder ? '9px 16px' : '0') . ';border-bottom:' . ($showBorder ? '1px solid rgba(100,116,139,.22)' : '0') . ';border-radius:' . ($showBorder ? '2px 2px 0 0' : '0') . ';background:' . ($showBorder ? 'var(--bg-secondary,#f8fafc)' : 'transparent') . ';color:var(--text-primary,#0f172a);font-size:var(--fs-h4,1.125rem);font-weight:700;line-height:1.3;box-sizing:border-box;';

        $html = '<section class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '" data-image-position="' . htmlspecialchars($imagePosition, ENT_QUOTES, 'UTF-8') . '" data-image-width="' . htmlspecialchars($imageWidth, ENT_QUOTES, 'UTF-8') . '" data-image-fit="' . htmlspecialchars($imageFit, ENT_QUOTES, 'UTF-8') . '" data-vertical-alignment="' . htmlspecialchars($verticalAlignment, ENT_QUOTES, 'UTF-8') . '" data-spacing-top="' . $spacingTop . '" data-spacing-bottom="' . $spacingBottom . '" style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '">';
        if ($heading !== '') {
            $html .= '<h4 class="editorjs-media-text__heading" style="' . htmlspecialchars($headingStyle, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h4>';
        }
        if ($imageUrl !== '') {
            $html .= '<figure class="editorjs-media-text__media" style="' . htmlspecialchars($mediaStyle, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<img class="editorjs-media-text__image" src="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '"' . $this->getLazyLoadingAttribute() . '>';
            $html .= '</figure>';
        }

        $html .= '<div class="editorjs-media-text__content" style="' . htmlspecialchars($contentStyle, ENT_QUOTES, 'UTF-8') . '">';
        $html .= $textHtml !== '' ? $textHtml : '<p></p>';
        $html .= '</div>';
        $html .= '</section>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderButton(array $data): string
    {
        $url = EditorJsHtmlSanitizer::sanitizeUrl((string)($data['url'] ?? $data['link'] ?? ''), ['http', 'https', 'mailto', 'tel'], false);
        $text = $this->sanitizeInline((string)($data['text'] ?? $data['label'] ?? ''));
        if ($url === '' || $text === '') {
            return '';
        }

        $align = (string)($data['align'] ?? $data['alignment'] ?? 'left');
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'left';
        }

        $color = (string)($data['color'] ?? $data['variant'] ?? 'primary');
        if (!in_array($color, ['primary', 'secondary', 'info', 'success', 'warning', 'danger', 'light', 'dark'], true)) {
            $color = 'primary';
        }

        $size = (string)($data['size'] ?? 'medium');
        if (!in_array($size, ['small', 'medium', 'large'], true)) {
            $size = 'medium';
        }

        $isExternal = (bool) preg_match('#^https?://#i', $url);
        $relAttr = $isExternal ? ' target="_blank" rel="noopener noreferrer"' : '';

        $classes = [
            'editorjs-block',
            'editorjs-button',
            'editorjs-button--align-' . $align,
        ];
        $linkClasses = [
            'editorjs-button__link',
            'editorjs-button__link--' . $color,
            'editorjs-button__link--' . $size,
        ];

        $html = '<div class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '" data-align="' . htmlspecialchars($align, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<a class="' . htmlspecialchars(implode(' ', $linkClasses), ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $relAttr . '>' . $text . '</a>';
        $html .= '</div>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderCallout(array $data): string
    {
        $variant = (string) ($data['variant'] ?? 'info');
        $title = $this->sanitizeInline((string) ($data['title'] ?? ''));
        $message = $this->sanitizeInline((string) ($data['message'] ?? ''));

        if ($title === '' && $message === '') {
            return '';
        }

        $variantMap = [
            'info' => ['class' => 'callout-info', 'icon' => 'ℹ️'],
            'warning' => ['class' => 'callout-warn', 'icon' => '⚠️'],
            'success' => ['class' => 'callout-ok', 'icon' => '✅'],
            'danger' => ['class' => 'callout-danger', 'icon' => '⛔'],
        ];
        $resolved = $variantMap[$variant] ?? $variantMap['info'];

        return '<aside class="editorjs-block editorjs-callout callout ' . $resolved['class'] . '"><span class="ci">' . $resolved['icon'] . '</span><div><strong>' . ($title !== '' ? $title : 'Hinweis') . '</strong>' . ($message !== '' ? '<div>' . $message . '</div>' : '') . '</div></aside>';
    }

    /** @param array<string,mixed> $data */
    private function renderTerminal(array $data): string
    {
        $shell = htmlspecialchars((string) ($data['shell'] ?? 'bash'), ENT_QUOTES, 'UTF-8');
        $title = $this->sanitizeInline((string) ($data['title'] ?? ''));
        $command = trim((string) ($data['command'] ?? ''));
        $output = trim((string) ($data['output'] ?? ''));

        if ($command === '') {
            return '';
        }

        $html = '<section class="editorjs-block editorjs-terminal">';
        $html .= '<div class="editorjs-terminal__header"><strong>' . ($title !== '' ? $title : 'Terminal') . '</strong><span class="editorjs-terminal__shell">' . $shell . '</span></div>';
        $html .= '<pre class="editorjs-terminal__command"><code>' . htmlspecialchars($command, ENT_QUOTES, 'UTF-8') . '</code></pre>';
        if ($output !== '') {
            $html .= '<div class="editorjs-terminal__output">' . htmlspecialchars($output, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $html .= '</section>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderCodeTabs(array $data): string
    {
        $tabs = is_array($data['tabs'] ?? null) ? $data['tabs'] : [];
        if ($tabs === []) {
            return '';
        }

        $instanceId = 'editorjs-code-tabs-' . uniqid();
        $title = $this->sanitizeInline((string) ($data['title'] ?? ''));
        $buttonsHtml = '';
        $panesHtml = '';
        $renderedTabs = 0;

        foreach ($tabs as $index => $tab) {
            if (!is_array($tab)) {
                continue;
            }

            $tabId = $instanceId . '-pane-' . $index;
            $label = htmlspecialchars((string) ($tab['label'] ?? ('Tab ' . ($index + 1))), ENT_QUOTES, 'UTF-8');
            $language = htmlspecialchars((string) ($tab['language'] ?? ''), ENT_QUOTES, 'UTF-8');
            $code = trim((string) ($tab['code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $isActive = $renderedTabs === 0;
            $renderedTabs++;

            $buttonsHtml .= '<button type="button" class="editorjs-code-tabs__tab" data-target="' . htmlspecialchars($tabId, ENT_QUOTES, 'UTF-8') . '" aria-pressed="' . ($isActive ? 'true' : 'false') . '">' . $label . '</button>';
            $panesHtml .= '<div id="' . htmlspecialchars($tabId, ENT_QUOTES, 'UTF-8') . '" class="editorjs-code-tabs__pane" data-code-tab-pane' . ($isActive ? '' : ' hidden') . '>'
                . '<div class="editorjs-code-tabs__language">' . ($language !== '' ? $language : 'code') . '</div>'
                . '<pre class="editorjs-code-tabs__code"><code class="' . ($language !== '' ? 'language-' . $language : '') . '">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</code></pre>'
                . '</div>';
        }

        if ($buttonsHtml === '' || $panesHtml === '') {
            return '';
        }

        $script = '(function(){var root=document.getElementById(' . json_encode($instanceId) . ');if(!root){return;}var buttons=root.querySelectorAll("[data-target]");var panes=root.querySelectorAll("[data-code-tab-pane]");buttons.forEach(function(button){button.addEventListener("click",function(){var targetId=button.getAttribute("data-target");buttons.forEach(function(item){var active=item===button;item.setAttribute("aria-pressed",active?"true":"false");});panes.forEach(function(pane){pane.hidden=pane.id!==targetId;});});});})();';

        $html = '<section class="editorjs-block editorjs-code-tabs" id="' . htmlspecialchars($instanceId, ENT_QUOTES, 'UTF-8') . '">';
        if ($title !== '') {
            $html .= '<div class="editorjs-code-tabs__title">' . $title . '</div>';
        }
        $html .= '<div class="editorjs-code-tabs__tabs">' . $buttonsHtml . '</div>';
        $html .= $panesHtml;
        $html .= '<script ' . \CMS\Security::instance()->nonceAttr() . '>' . $script . '</script>';
        $html .= '</section>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderMermaid(array $data): string
    {
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            return '';
        }

        $instanceId = 'editorjs-mermaid-' . uniqid();
        $title = $this->sanitizeInline((string) ($data['title'] ?? ''));
        $caption = $this->sanitizeInline((string) ($data['caption'] ?? ''));
        $script = '(function(){var root=document.getElementById(' . json_encode($instanceId) . ');if(!root||!window.mermaid||typeof window.mermaid.render!=="function"){return;}var source=root.querySelector("[data-mermaid-source]");var target=root.querySelector("[data-mermaid-target]");var preview=root.querySelector("[data-mermaid-preview]");if(!source||!target){return;}try{window.mermaid.initialize({startOnLoad:false,securityLevel:"strict"});window.mermaid.render(' . json_encode($instanceId . '-svg') . ',source.textContent||"").then(function(result){target.innerHTML=result.svg;target.hidden=false;if(preview){preview.hidden=true;}}).catch(function(){});}catch(error){}})();';

        $html = '<figure class="editorjs-block editorjs-mermaid" id="' . htmlspecialchars($instanceId, ENT_QUOTES, 'UTF-8') . '">';
        if ($title !== '') {
            $html .= '<div class="editorjs-mermaid__title">' . $title . '</div>';
        }
        $html .= '<div class="editorjs-mermaid__diagram" data-mermaid-target hidden></div>';
        $html .= '<pre class="editorjs-mermaid__source" data-mermaid-preview><code data-mermaid-source class="language-mermaid">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</code></pre>';
        if ($caption !== '') {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }
        $html .= '<script ' . \CMS\Security::instance()->nonceAttr() . '>' . $script . '</script>';
        $html .= '</figure>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderApiEndpoint(array $data): string
    {
        $method = htmlspecialchars((string) ($data['method'] ?? 'GET'), ENT_QUOTES, 'UTF-8');
        $path = htmlspecialchars((string) ($data['path'] ?? ''), ENT_QUOTES, 'UTF-8');
        $summary = $this->sanitizeInline((string) ($data['summary'] ?? ''));
        $auth = $this->sanitizeInline((string) ($data['auth'] ?? ''));
        $requestExample = trim((string) ($data['requestExample'] ?? ''));
        $responseExample = trim((string) ($data['responseExample'] ?? ''));

        if ($path === '') {
            return '';
        }

        $html = '<section class="editorjs-block editorjs-api-endpoint">';
        $html .= '<div class="editorjs-api-endpoint__head"><span class="editorjs-api-endpoint__method">' . $method . '</span><code class="editorjs-api-endpoint__path">' . $path . '</code></div>';
        if ($summary !== '') {
            $html .= '<p class="editorjs-api-endpoint__summary">' . $summary . '</p>';
        }
        if ($auth !== '') {
            $html .= '<div class="editorjs-api-endpoint__auth"><strong>Auth:</strong> ' . $auth . '</div>';
        }
        if ($requestExample !== '' || $responseExample !== '') {
            $html .= '<div class="editorjs-api-endpoint__examples">';
            if ($requestExample !== '') {
                $html .= '<div><div class="editorjs-api-endpoint__label">Request</div><pre class="editorjs-api-endpoint__code"><code>' . htmlspecialchars($requestExample, ENT_QUOTES, 'UTF-8') . '</code></pre></div>';
            }
            if ($responseExample !== '') {
                $html .= '<div><div class="editorjs-api-endpoint__label">Response</div><pre class="editorjs-api-endpoint__code"><code>' . htmlspecialchars($responseExample, ENT_QUOTES, 'UTF-8') . '</code></pre></div>';
            }
            $html .= '</div>';
        }
        $html .= '</section>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderChangelog(array $data): string
    {
        $title = $this->sanitizeInline((string) ($data['title'] ?? ''));
        $version = htmlspecialchars((string) ($data['version'] ?? ''), ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars((string) ($data['date'] ?? ''), ENT_QUOTES, 'UTF-8');
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        if ($version === '' && $items === []) {
            return '';
        }

        $html = '<section class="editorjs-block editorjs-changelog">';
        $html .= '<div class="editorjs-changelog__header">';
        $html .= '<div class="editorjs-changelog__title"><strong>' . ($title !== '' ? $title : 'Changelog') . '</strong></div>';
        $html .= '<div class="editorjs-changelog__meta">' . ($version !== '' ? '<span class="badge badge-b">' . $version . '</span>' : '') . ($date !== '' ? '<span class="badge">' . $date . '</span>' : '') . '</div>';
        $html .= '</div>';
        if ($items !== []) {
            $html .= '<ul class="changelog-list editorjs-changelog__items">';
            foreach ($items as $item) {
                $itemText = $this->renderMarkdownInline((string) $item);
                if ($itemText === '') {
                    continue;
                }
                $html .= '<li>' . $itemText . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</section>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderProsCons(array $data): string
    {
        $title = $this->sanitizeInline((string) ($data['title'] ?? ''));
        $prosTitle = $this->sanitizeInline((string) ($data['prosTitle'] ?? 'Vorteile'));
        $consTitle = $this->sanitizeInline((string) ($data['consTitle'] ?? 'Nachteile'));
        $pros = is_array($data['pros'] ?? null) ? $data['pros'] : [];
        $cons = is_array($data['cons'] ?? null) ? $data['cons'] : [];

        if ($pros === [] && $cons === []) {
            return '';
        }

        $html = '<section class="editorjs-block editorjs-pros-cons">';
        if ($title !== '') {
            $html .= '<h3 class="editorjs-pros-cons__title">' . $title . '</h3>';
        }
        $html .= '<div class="editorjs-pros-cons__grid">';
        $html .= '<div class="editorjs-pros-cons__column editorjs-pros-cons__column--pros"><strong>' . ($prosTitle !== '' ? $prosTitle : 'Vorteile') . '</strong><ul class="editorjs-pros-cons__list">';
        foreach ($pros as $item) {
            $itemText = $this->sanitizeInline((string) $item);
            if ($itemText !== '') {
                $html .= '<li>' . $itemText . '</li>';
            }
        }
        $html .= '</ul></div>';
        $html .= '<div class="editorjs-pros-cons__column editorjs-pros-cons__column--cons"><strong>' . ($consTitle !== '' ? $consTitle : 'Nachteile') . '</strong><ul class="editorjs-pros-cons__list">';
        foreach ($cons as $item) {
            $itemText = $this->sanitizeInline((string) $item);
            if ($itemText !== '') {
                $html .= '<li>' . $itemText . '</li>';
            }
        }
        $html .= '</ul></div>';
        $html .= '</div></section>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderDetails(array $data): string
    {
        $summary = $this->sanitizeInline((string)($data['summary'] ?? $data['title'] ?? ''));
        $content = $this->sanitizeInline((string)($data['content'] ?? $data['text'] ?? $data['description'] ?? ''));

        if ($summary === '' && $content === '') {
            return '';
        }

        $resolvedSummary = $summary !== '' ? $summary : 'Details';
        $resolvedContent = $content !== '' ? $content : '<br>';

        return '<div class="editorjs-block editorjs-details"><details><summary>' . $resolvedSummary . '</summary><div class="editorjs-details__content"><p>' . $resolvedContent . '</p></div></details></div>';
    }

    /** @param array<string,mixed> $data */
    private function renderCarousel(array $data): string
    {
        if (!array_is_list($data) || $data === []) {
            return '';
        }

        $html = '<div class="editorjs-block editorjs-carousel">';
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = $this->normalizeRenderableAssetUrl((string)($item['url'] ?? ''), true);
            if ($url === '') {
                continue;
            }

            $caption = $this->sanitizeInline((string)($item['caption'] ?? ''));
            $html .= '<figure class="editorjs-carousel__item">';
            $html .= '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt=""' . $this->getLazyLoadingAttribute() . '>';
            if ($caption !== '' && !$this->isGeneratedFilenameCaption($caption, $url)) {
                $html .= '<figcaption>' . $caption . '</figcaption>';
            }
            $html .= '</figure>';
        }
        $html .= '</div>';

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function renderColumns(array $data): string
    {
        $columns = is_array($data['cols'] ?? null) ? $data['cols'] : [];
        if ($columns === []) {
            return '';
        }

        $html = '<div class="editorjs-block editorjs-columns editorjs-columns--' . count($columns) . '">';
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }
            $html .= '<div class="editorjs-columns__col">' . $this->render($column) . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int, array<string,mixed>> $nestedBlocks
     */
    private function renderAccordion(array $data, array $nestedBlocks): string
    {
        $title = $this->sanitizeInline((string)($data['title'] ?? 'Accordion'));
        if ($nestedBlocks === []) {
            $content = $this->renderAccordionDataContent($data);
            if ($content !== '') {
                $open = !empty($data['settings']['defaultExpanded']) || !empty($data['open']) ? ' open' : '';
                return '<div class="editorjs-block editorjs-accordion"><details' . $open . '><summary>' . $title . '</summary><div class="editorjs-accordion__content">' . $content . '</div></details></div>';
            }
        }

        $content = $this->renderBlocks($nestedBlocks);
        if ($content === '') {
            return '';
        }

        $open = !empty($data['settings']['defaultExpanded']) ? ' open' : '';
        return '<div class="editorjs-block editorjs-accordion"><details' . $open . '><summary>' . $title . '</summary><div class="editorjs-accordion__content">' . $content . '</div></details></div>';
    }

    /** @param array<string,mixed> $data */
    private function renderAccordionDataContent(array $data): string
    {
        if (isset($data['content']) && is_array($data['content'])) {
            return $this->render($data['content']);
        }

        if (isset($data['blocks']) && is_array($data['blocks'])) {
            return $this->render(['blocks' => $data['blocks']]);
        }

        $html = $this->sanitizeInline((string)($data['content'] ?? $data['text'] ?? $data['description'] ?? ''));

        return $html !== '' ? '<p>' . $html . '</p>' : '';
    }

    /** @param array<string,mixed> $data */
    private function renderDrawingTool(array $data): string
    {
        $images = is_array($data['canvasImages'] ?? null) ? $data['canvasImages'] : [];
        if ($images === []) {
            return '';
        }

        $html = '<div class="editorjs-block editorjs-drawing">';
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $src = $this->normalizeRenderableAssetUrl((string)($image['src'] ?? ''), true, true);
            if ($src === '') {
                continue;
            }

            $html .= '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="Zeichnung"' . $this->getLazyLoadingAttribute() . '>';
        }
        $html .= '</div>';

        return $html;
    }

    private function getLazyLoadingAttribute(): string
    {
        if (!$this->isLazyLoadingEnabled()) {
            return '';
        }

        $this->renderedImageCount++;
        if ($this->renderedImageCount <= $this->getEagerImageCount()) {
            return ' loading="eager" fetchpriority="high" decoding="async"';
        }

        return ' loading="lazy" decoding="async"';
    }

    private function isLazyLoadingEnabled(): bool
    {
        if ($this->lazyLoadingEnabled !== null) {
            return $this->lazyLoadingEnabled;
        }

        try {
            if (function_exists('get_option')) {
                $this->lazyLoadingEnabled = (string) get_option('perf_lazy_loading', '1') !== '0';
                return $this->lazyLoadingEnabled;
            }

            $db = \CMS\Database::instance();
            $value = $db->get_var(
                "SELECT option_value FROM {$db->getPrefix()}settings WHERE option_name = 'perf_lazy_loading' LIMIT 1"
            );
            $this->lazyLoadingEnabled = (string) $value !== '0';
        } catch (\Throwable) {
            $this->lazyLoadingEnabled = true;
        }

        return $this->lazyLoadingEnabled;
    }

    private function getEagerImageCount(): int
    {
        if ($this->eagerImageCount !== null) {
            return $this->eagerImageCount;
        }

        try {
            if (function_exists('get_option')) {
                $this->eagerImageCount = max(0, min(5, (int)get_option('perf_lazy_loading_eager_images', '1')));
                return $this->eagerImageCount;
            }

            $db = \CMS\Database::instance();
            $value = $db->get_var(
                "SELECT option_value FROM {$db->getPrefix()}settings WHERE option_name = 'perf_lazy_loading_eager_images' LIMIT 1"
            );
            $this->eagerImageCount = max(0, min(5, (int)($value ?? 1)));
        } catch (\Throwable) {
            $this->eagerImageCount = 1;
        }

        return $this->eagerImageCount;
    }

    private function sanitizeInline(string $html): string
    {
        return EditorJsHtmlSanitizer::sanitizeInline($html);
    }

    private function normalizeRenderableAssetUrl(string $url, bool $preferInline = false, bool $allowDataImage = false): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if ($allowDataImage) {
            $dataImage = EditorJsHtmlSanitizer::sanitizeUrl($url, [], false, true);
            if ($dataImage !== '') {
                return $dataImage;
            }
        }

        try {
            $normalizedUrl = \CMS\Services\MediaDeliveryService::getInstance()->normalizeUrl($url, $preferInline);
        } catch (\Throwable) {
            $normalizedUrl = $url;
        }
        $normalizedUrl = trim($normalizedUrl);

        if ($normalizedUrl === '') {
            return '';
        }

        $absoluteUrl = EditorJsHtmlSanitizer::sanitizeUrl($normalizedUrl, ['http', 'https'], false);
        if ($absoluteUrl !== '') {
            return $absoluteUrl;
        }

        return preg_match('#^/(?:media-file(?:\?|$)|uploads(?:/|$))#', $normalizedUrl) === 1
            ? $normalizedUrl
            : '';
    }

    private function renderMarkdownInline(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        $escaped = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');
        $placeholders = [];
        $placeholderIndex = 0;

        $escaped = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/i',
            function (array $matches) use (&$placeholders, &$placeholderIndex): string {
                $label = $matches[1];
                $href = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');
                if (!filter_var($href, FILTER_VALIDATE_URL)) {
                    return $matches[0];
                }

                $key = '@@MDLINK' . $placeholderIndex++ . '@@';
                $placeholders[$key] = '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">'
                    . $label
                    . '</a>';

                return $key;
            },
            $escaped
        ) ?? $escaped;

        $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $escaped) ?? $escaped;

        if ($placeholders !== []) {
            $escaped = strtr($escaped, $placeholders);
        }

        return $escaped;
    }



    private function renderMediaTextContent(string $html): string
    {
        $raw = trim($html);
        if ($raw === '') {
            return '';
        }

        $blockHtml = trim(EditorJsHtmlSanitizer::sanitizeRawBlock($raw));
        if ($blockHtml === '') {
            return '';
        }

        if (preg_match('/<(?:p|div|ul|ol|blockquote|pre|table|h[1-6]|hr)\b/i', $blockHtml) === 1) {
            return $blockHtml;
        }

        $inline = trim($this->sanitizeInline($raw));
        return $inline !== '' ? '<p>' . $inline . '</p>' : '';
    }

    private function isGeneratedFilenameCaption(string $caption, string $assetUrl): bool
    {
        $plainCaption = trim(html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plainCaption === '' || $assetUrl === '') {
            return false;
        }

        $path = (string) (parse_url($assetUrl, PHP_URL_PATH) ?: $assetUrl);
        $basename = rawurldecode((string) basename($path));
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return false;
        }

        $filename = pathinfo($basename, PATHINFO_FILENAME);
        $candidates = array_filter(array_unique([
            $basename,
            $filename,
            str_replace(['-', '_'], ' ', $filename),
        ]), static fn(string $value): bool => trim($value) !== '');

        $normalizedCaption = $this->normalizeFilenameCaptionComparison($plainCaption);
        foreach ($candidates as $candidate) {
            if ($normalizedCaption === $this->normalizeFilenameCaptionComparison($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeFilenameCaptionComparison(string $value): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('/\.[a-z0-9]{2,5}$/iu', '', $value) ?? $value;
        $value = preg_replace('/[-_]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
