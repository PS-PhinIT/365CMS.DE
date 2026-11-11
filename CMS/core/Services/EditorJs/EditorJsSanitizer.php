<?php
/**
 * Editor.js Sanitizer für Block-Payloads.
 *
 * @package CMSv2\Services
 */

declare(strict_types=1);

namespace CMS\Services\EditorJs;

if (!defined('ABSPATH')) {
    exit;
}

final class EditorJsSanitizer
{
    public function sanitize(string $json): string
    {
        $data = EditorJsContentNormalizer::normalize($json);
        if (empty($data['blocks']) || !is_array($data['blocks'])) {
            return '{"blocks":[]}';
        }

        $cleaned = $this->sanitizePayload($data);
        return (string) json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sanitizePayload(array $payload): array
    {
        $blocks = [];

        foreach (($payload['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }

            $cleanBlock = $this->sanitizeBlock($block);
            if ($cleanBlock !== null) {
                $blocks[] = $cleanBlock;
            }
        }

        return ['blocks' => $blocks];
    }

    private function sanitizeBlock(array $block): ?array
    {
        $allowedTypes = [
            'paragraph', 'header', 'list', 'checklist', 'quote', 'warning', 'alert',
            'code', 'raw', 'table', 'image', 'attaches', 'linkTool', 'delimiter',
            'embed', 'imageGallery', 'carousel', 'columns', 'accordion', 'drawingTool', 'spacer', 'mediaText',
            'callout', 'terminal', 'codeTabs', 'mermaid', 'apiEndpoint', 'changelog', 'prosCons', 'details', 'button',
        ];

        $type = (string) ($block['type'] ?? '');
        if (!in_array($type, $allowedTypes, true) && !EditorJsBlockRegistry::has($type)) {
            return null;
        }

        $rawData = is_array($block['data'] ?? null) ? $block['data'] : [];
        if ($type === 'checklist') {
            $type = 'list';
            $rawData['style'] = 'checklist';
        }

        $data = $this->sanitizeBlockData($type, $rawData);
        $cleanBlock = [
            'type' => $type,
            'data' => $data,
        ];

        $tunes = $this->sanitizeTunes($type, is_array($block['tunes'] ?? null) ? $block['tunes'] : []);
        if ($tunes !== []) {
            $cleanBlock['tunes'] = $tunes;
        }

        return $cleanBlock;
    }

    private function sanitizeBlockData(string $type, array $data): array
    {
        if (EditorJsBlockRegistry::has($type)) {
            return EditorJsBlockRegistry::sanitize($type, $data);
        }

        $cleanInline = static fn(mixed $value): string => EditorJsHtmlSanitizer::sanitizeInline((string) $value);

        switch ($type) {
            case 'paragraph':
                $data['text'] = $cleanInline($data['text'] ?? '');
                $alignment = (string) ($data['alignment'] ?? 'left');
                $spacing = (string) ($data['spacing'] ?? 'normal');
                $data['alignment'] = in_array($alignment, ['left', 'center', 'right', 'justify'], true) ? $alignment : 'left';
                $data['spacing'] = in_array($spacing, ['compact', 'normal', 'relaxed', 'loose'], true) ? $spacing : 'normal';
                break;

            case 'header':
                $data['text'] = $cleanInline($data['text'] ?? '');
                $data['level'] = max(1, min(6, (int) ($data['level'] ?? 2)));
                $alignment = (string) ($data['alignment'] ?? 'left');
                $spacing = (string) ($data['spacing'] ?? 'normal');
                $data['alignment'] = in_array($alignment, ['left', 'center', 'right', 'justify'], true) ? $alignment : 'left';
                $data['spacing'] = in_array($spacing, ['compact', 'normal', 'relaxed', 'loose'], true) ? $spacing : 'normal';
                break;

            case 'list':
                $style = (string) ($data['style'] ?? 'unordered');
                $data['style'] = in_array($style, ['ordered', 'unordered', 'checklist'], true) ? $style : 'unordered';
                $data['meta'] = $this->sanitizeListMeta($data['style'], is_array($data['meta'] ?? null) ? $data['meta'] : []);
                $data['items'] = $this->sanitizeListItems(is_array($data['items'] ?? null) ? $data['items'] : [], $data['style']);
                break;

            case 'checklist':
                $data['items'] = array_values(array_filter(array_map(static function ($item) use ($cleanInline) {
                    if (!is_array($item)) {
                        return null;
                    }

                    return [
                        'text' => $cleanInline($item['text'] ?? ''),
                        'checked' => !empty($item['checked']),
                    ];
                }, is_array($data['items'] ?? null) ? $data['items'] : [])));
                break;

            case 'quote':
                $data['text'] = $cleanInline($data['text'] ?? '');
                $data['caption'] = $cleanInline($data['caption'] ?? '');
                $quoteAlignment = (string) ($data['alignment'] ?? 'left');
                $quoteDesign = (string) ($data['design'] ?? 'bar');
                $data['alignment'] = in_array($quoteAlignment, ['left', 'center'], true) ? $quoteAlignment : 'left';
                $data['design'] = in_array($quoteDesign, ['bar', 'card', 'minimal', 'mark'], true) ? $quoteDesign : 'bar';
                break;

            case 'warning':
                $variant = (string) ($data['variant'] ?? $data['type'] ?? $data['tone'] ?? 'info');
                if (!in_array($variant, ['info', 'warning', 'success', 'danger'], true)) {
                    $variant = 'info';
                }

                $data['variant'] = $variant;
                $data['title'] = $cleanInline($data['title'] ?? '');
                $data['message'] = $cleanInline($data['message'] ?? '');
                break;

            case 'alert':
                $type = strtolower((string) ($data['type'] ?? $data['variant'] ?? 'info'));
                $align = strtolower((string) ($data['align'] ?? $data['alignment'] ?? 'left'));
                $data = [
                    'type' => in_array($type, ['primary', 'secondary', 'info', 'success', 'warning', 'danger', 'light', 'dark'], true) ? $type : 'info',
                    'align' => in_array($align, ['left', 'center', 'right'], true) ? $align : 'left',
                    'message' => $cleanInline($data['message'] ?? $data['text'] ?? ''),
                ];
                break;

            case 'code':
                $data['code'] = (string) ($data['code'] ?? '');
                if (isset($data['language'])) {
                    $data['language'] = preg_replace('/[^a-z0-9_\-+#]/i', '', (string) $data['language']);
                }
                break;

            case 'raw':
                $data['html'] = EditorJsHtmlSanitizer::sanitizeRawBlock((string) ($data['html'] ?? ''));
                break;

            case 'table':
                $data['withHeadings'] = !empty($data['withHeadings']);
                $data['content'] = array_values(array_map(function ($row) use ($cleanInline) {
                    if (!is_array($row)) {
                        return [];
                    }

                    return array_values(array_map(static fn($cell) => $cleanInline($cell), $row));
                }, is_array($data['content'] ?? null) ? $data['content'] : []));
                break;

            case 'image':
                $data['file'] = $this->sanitizeFileInfo(is_array($data['file'] ?? null) ? $data['file'] : []);
                $data['caption'] = $cleanInline($data['caption'] ?? '');
                $data['sourceUrl'] = EditorJsHtmlSanitizer::sanitizeUrl(
                    (string) ($data['sourceUrl'] ?? $data['source_url'] ?? $data['imageSource'] ?? $data['source'] ?? ''),
                    ['http', 'https'],
                    false
                );
                $alignment = (string) ($data['alignment'] ?? $data['align'] ?? 'center');
                if (!in_array($alignment, ['left', 'center', 'right'], true)) {
                    $alignment = 'center';
                }

                $size = (string) ($data['size'] ?? $data['widthPreset'] ?? (!empty($data['stretched']) ? 'full' : 'normal'));
                if (!in_array($size, ['normal', 'wide', 'full'], true)) {
                    $size = 'normal';
                }

                $borderStyle = (string) ($data['borderStyle'] ?? (!empty($data['withBorder']) ? 'thin' : 'none'));
                if (!in_array($borderStyle, ['none', 'thin', 'medium', 'thick'], true)) {
                    $borderStyle = !empty($data['withBorder']) ? 'thin' : 'none';
                }

                $data['alignment'] = $alignment;
                $data['size'] = $size;
                $data['widthPreset'] = $size;
                $data['borderStyle'] = $borderStyle;
                $data['imageFit'] = $this->sanitizeImageFit($data['imageFit'] ?? $data['objectFit'] ?? $data['fit'] ?? 'contain', 'contain');
                $data['objectFit'] = $data['imageFit'];
                $data['maxHeight'] = $this->sanitizeImageMaxHeight($data['maxHeight'] ?? $data['imageMaxHeight'] ?? $data['max_height'] ?? 0);
                $data['withBorder'] = $borderStyle !== 'none';
                $data['withBackground'] = !empty($data['withBackground']);
                $data['stretched'] = $size === 'full' || !empty($data['stretched']);
                $data['rounded'] = array_key_exists('rounded', $data) ? !empty($data['rounded']) : true;
                $data['shadow'] = !empty($data['shadow']);
                break;

            case 'attaches':
                $data['file'] = $this->sanitizeFileInfo(is_array($data['file'] ?? null) ? $data['file'] : []);
                $data['title'] = $cleanInline($data['title'] ?? '');
                break;

            case 'linkTool':
                $data['link'] = EditorJsHtmlSanitizer::sanitizeUrl((string) ($data['link'] ?? ''), ['http', 'https', 'mailto', 'tel'], false);
                $data['meta'] = $this->sanitizeLinkMeta(is_array($data['meta'] ?? null) ? $data['meta'] : []);
                break;

            case 'button':
                $url = EditorJsHtmlSanitizer::sanitizeUrl((string) ($data['url'] ?? $data['link'] ?? ''), ['http', 'https', 'mailto', 'tel'], false);
                $text = $cleanInline($data['text'] ?? $data['label'] ?? '');
                $align = (string) ($data['align'] ?? $data['alignment'] ?? 'left');
                $color = (string) ($data['color'] ?? $data['variant'] ?? 'primary');
                $size = (string) ($data['size'] ?? 'medium');
                $data = [
                    'url' => $url,
                    'text' => $text,
                    'align' => in_array($align, ['left', 'center', 'right'], true) ? $align : 'left',
                    'color' => in_array($color, ['primary', 'secondary', 'info', 'success', 'warning', 'danger', 'light', 'dark'], true) ? $color : 'primary',
                    'size' => in_array($size, ['small', 'medium', 'large'], true) ? $size : 'medium',
                ];
                break;

            case 'delimiter':
                $style = strtolower((string) ($data['style'] ?? $data['type'] ?? 'line'));
                $lineWidth = (int) ($data['lineWidth'] ?? $data['width'] ?? 35);
                $lineThickness = (int) ($data['lineThickness'] ?? $data['thickness'] ?? 2);

                if (!in_array($style, ['star', 'dash', 'line'], true)) {
                    $style = 'line';
                }
                $lineWidth = $this->snapNumberToPreset($lineWidth, [8, 15, 25, 35, 50, 60, 100], 35);
                $lineThickness = $this->snapNumberToPreset($lineThickness, [1, 2, 3, 4, 5, 6], 2);

                $data = $style === 'line'
                    ? ['style' => $style, 'lineWidth' => $lineWidth, 'lineThickness' => $lineThickness]
                    : ['style' => $style];
                break;

            case 'embed':
                $data['service'] = preg_replace('/[^a-z0-9\-]/i', '', (string) ($data['service'] ?? 'embed'));
                $data['source'] = EditorJsHtmlSanitizer::sanitizeUrl((string) ($data['source'] ?? ''), ['http', 'https'], false);
                $data['embed'] = EditorJsHtmlSanitizer::sanitizeUrl((string) ($data['embed'] ?? ''), ['http', 'https'], false);
                $data['width'] = max(0, (int) ($data['width'] ?? 0));
                $data['height'] = max(0, (int) ($data['height'] ?? 0));
                $data['caption'] = $cleanInline($data['caption'] ?? '');
                break;

            case 'imageGallery':
                $columns = (int) ($data['columns'] ?? 3);
                $borderStyle = (string) ($data['borderStyle'] ?? (!empty($data['withBorder']) ? 'thin' : 'none'));
                if (!in_array($columns, [2, 3, 4, 5, 6], true)) {
                    $columns = 3;
                }
                if (!in_array($borderStyle, ['none', 'thin', 'medium', 'thick'], true)) {
                    $borderStyle = !empty($data['withBorder']) ? 'thin' : 'none';
                }

                $images = array_values(array_filter(array_map(function ($item) use ($cleanInline) {
                    $itemData = is_array($item) ? $item : ['url' => (string) $item];
                    $file = $this->sanitizeFileInfo(is_array($itemData['file'] ?? null) ? $itemData['file'] : $itemData);
                    if ($file['url'] === '') {
                        return null;
                    }

                    return [
                        'file' => $file,
                        'caption' => $cleanInline($itemData['caption'] ?? $itemData['alt'] ?? ''),
                    ];
                }, is_array($data['images'] ?? null) ? $data['images'] : [])));

                if ($images === []) {
                    $images = array_values(array_map(function (string $url): array {
                        return [
                            'file' => [
                                'url' => $url,
                                'name' => '',
                                'size' => 0,
                                'extension' => '',
                            ],
                            'caption' => '',
                        ];
                    }, $this->sanitizeUrlList(is_array($data['urls'] ?? null) ? $data['urls'] : [])));
                }

                $data = [
                    'columns' => $columns,
                    'borderStyle' => $borderStyle,
                    'withBorder' => $borderStyle !== 'none',
                    'images' => $images,
                    'urls' => array_values(array_map(static fn(array $item): string => (string) ($item['file']['url'] ?? ''), $images)),
                ];
                break;

            case 'mediaText':
                $data['file'] = $this->sanitizeFileInfo(is_array($data['file'] ?? null) ? $data['file'] : []);
                $data['alt'] = strip_tags((string) ($data['alt'] ?? ''), '');
                $data['heading'] = $this->truncatePlainText(strip_tags((string) ($data['heading'] ?? $data['title'] ?? $data['headline'] ?? ''), ''), 180);
                $data['text'] = EditorJsHtmlSanitizer::sanitizeRawBlock((string) ($data['text'] ?? ''));
                $imagePosition = (string) ($data['imagePosition'] ?? $data['position'] ?? $data['mediaPosition'] ?? 'left');
                $imageWidth = (string) ($data['imageWidth'] ?? $data['mediaWidth'] ?? '40');
                $data['imagePosition'] = in_array($imagePosition, ['left', 'right'], true) ? $imagePosition : 'left';
                $data['imageWidth'] = in_array($imageWidth, ['33', '40', '50'], true) ? $imageWidth : '40';
                $data['imageFit'] = $this->sanitizeImageFit($data['imageFit'] ?? $data['objectFit'] ?? $data['fit'] ?? 'cover', 'cover');
                $data['objectFit'] = $data['imageFit'];
                $data['verticalAlignment'] = $this->sanitizeMediaTextVerticalAlignment($data['verticalAlignment'] ?? $data['mediaVerticalAlignment'] ?? $data['imageVerticalAlignment'] ?? $data['verticalAlign'] ?? 'top');
                $data['showBorder'] = filter_var($data['showBorder'] ?? $data['border'] ?? $data['hasBorder'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $data['spacingTop'] = $this->sanitizeMediaTextSpacing($data['spacingTop'] ?? $data['marginTop'] ?? $data['blockSpacingTop'] ?? 10);
                $data['spacingBottom'] = $this->sanitizeMediaTextSpacing($data['spacingBottom'] ?? $data['marginBottom'] ?? $data['blockSpacingBottom'] ?? 10);
                break;

            case 'callout':
                $variant = (string) ($data['variant'] ?? 'info');
                $data = [
                    'variant' => in_array($variant, ['info', 'warning', 'success', 'danger'], true) ? $variant : 'info',
                    'title' => $cleanInline($data['title'] ?? ''),
                    'message' => $cleanInline($data['message'] ?? ''),
                ];
                break;

            case 'terminal':
                $shell = (string) ($data['shell'] ?? 'bash');
                $data = [
                    'shell' => in_array($shell, ['bash', 'sh', 'zsh', 'powershell', 'cmd'], true) ? $shell : 'bash',
                    'title' => $cleanInline($data['title'] ?? ''),
                    'command' => (string) ($data['command'] ?? ''),
                    'output' => (string) ($data['output'] ?? ''),
                ];
                break;

            case 'codeTabs':
                $tabs = array_values(array_filter(array_map(static function ($tab) {
                    if (!is_array($tab)) {
                        return null;
                    }

                    $label = strip_tags((string) ($tab['label'] ?? ''), '');
                    $language = preg_replace('/[^a-z0-9_\-+#]/i', '', (string) ($tab['language'] ?? ''));
                    $code = (string) ($tab['code'] ?? '');

                    if ($label === '' && trim($code) === '') {
                        return null;
                    }

                    return [
                        'label' => $label !== '' ? $label : 'Tab',
                        'language' => $language,
                        'code' => $code,
                    ];
                }, is_array($data['tabs'] ?? null) ? $data['tabs'] : [])));

                $data = [
                    'title' => $cleanInline($data['title'] ?? ''),
                    'tabs' => array_slice($tabs, 0, 8),
                ];
                break;

            case 'mermaid':
                $data = [
                    'title' => $cleanInline($data['title'] ?? ''),
                    'code' => (string) ($data['code'] ?? ''),
                    'caption' => $cleanInline($data['caption'] ?? ''),
                ];
                break;

            case 'apiEndpoint':
                $method = strtoupper((string) ($data['method'] ?? 'GET'));
                $data = [
                    'method' => in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true) ? $method : 'GET',
                    'path' => strip_tags((string) ($data['path'] ?? ''), ''),
                    'summary' => $cleanInline($data['summary'] ?? ''),
                    'auth' => $cleanInline($data['auth'] ?? ''),
                    'requestExample' => (string) ($data['requestExample'] ?? ''),
                    'responseExample' => (string) ($data['responseExample'] ?? ''),
                ];
                break;

            case 'changelog':
                $data = [
                    'title' => $cleanInline($data['title'] ?? ''),
                    'version' => strip_tags((string) ($data['version'] ?? ''), ''),
                    'date' => strip_tags((string) ($data['date'] ?? ''), ''),
                    'items' => array_values(array_filter(array_map(static fn($item) => trim(strip_tags((string) $item, '')), is_array($data['items'] ?? null) ? $data['items'] : []))),
                ];
                break;

            case 'prosCons':
                $data = [
                    'title' => $cleanInline($data['title'] ?? ''),
                    'prosTitle' => $cleanInline($data['prosTitle'] ?? 'Vorteile'),
                    'consTitle' => $cleanInline($data['consTitle'] ?? 'Nachteile'),
                    'pros' => array_values(array_filter(array_map(static fn($item) => trim($cleanInline($item)), is_array($data['pros'] ?? null) ? $data['pros'] : []))),
                    'cons' => array_values(array_filter(array_map(static fn($item) => trim($cleanInline($item)), is_array($data['cons'] ?? null) ? $data['cons'] : []))),
                ];
                break;

            case 'details':
                $data = [
                    'summary' => $cleanInline($data['summary'] ?? ''),
                    'content' => $cleanInline($data['content'] ?? ''),
                ];
                break;

            case 'carousel':
                $data = array_values(array_filter(array_map(function ($item) use ($cleanInline) {
                    if (!is_array($item)) {
                        return null;
                    }

                    $url = $this->sanitizeAssetUrl((string) ($item['url'] ?? ''));
                    if ($url === '') {
                        return null;
                    }

                    return [
                        'url' => $url,
                        'caption' => $cleanInline($item['caption'] ?? ''),
                    ];
                }, $data)));
                break;

            case 'columns':
                $cleanCols = [];
                foreach ((is_array($data['cols'] ?? null) ? $data['cols'] : []) as $column) {
                    if (!is_array($column)) {
                        continue;
                    }
                    $cleanCols[] = $this->sanitizePayload($column);
                }
                $data['cols'] = $cleanCols;
                break;

            case 'accordion':
                $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
                $data['settings'] = [
                    'blockCount' => max(1, min(10, (int) ($settings['blockCount'] ?? 3))),
                    'defaultExpanded' => !empty($settings['defaultExpanded']),
                ];
                $data['title'] = $cleanInline($data['title'] ?? '');
                break;

            case 'drawingTool':
                $data['canvasJson'] = is_string($data['canvasJson'] ?? null) ? $data['canvasJson'] : null;
                $data['canvasHeight'] = max(150, min(3000, (int) ($data['canvasHeight'] ?? 700)));
                $data['canvasImages'] = array_values(array_filter(array_map(function ($item) {
                    if (!is_array($item)) {
                        return null;
                    }

                    $src = (string) ($item['src'] ?? '');
                    if (!$this->isValidAssetUrl($src)) {
                        return null;
                    }

                    return [
                        'id' => preg_replace('/[^a-z0-9_\-]/i', '', (string) ($item['id'] ?? 'img')),
                        'src' => $src,
                        'attrs' => is_array($item['attrs'] ?? null) ? $item['attrs'] : [],
                    ];
                }, is_array($data['canvasImages'] ?? null) ? $data['canvasImages'] : [])));
                break;

            case 'spacer':
                $allowedHeights = [0, 8, 10, 15, 16, 24, 25, 32, 40, 48, 56, 60, 64, 72, 75, 80, 96, 100, 120, 140, 150, 160, 180, 200];
                $height = $this->normalizeSpacerHeight($data);
                if (!in_array($height, $allowedHeights, true)) {
                    $height = max(0, min(200, $height));
                }

                $data = [
                    'height' => $height,
                    'preset' => $height . 'px',
                ];
                break;
        }

        return $data;
    }

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

        $raw = (string) ($data['height'] ?? $data['size'] ?? $data['value'] ?? $data['spacer'] ?? $data['space'] ?? $data['preset'] ?? '40');
        $key = strtolower(preg_replace('/\s+/', '', trim($raw)) ?? trim($raw));
        if (isset($presetMap[$key])) {
            return $presetMap[$key];
        }

        return (int) preg_replace('/[^0-9]/', '', $key);
    }

    private function sanitizeTunes(string $type, array $tunes): array
    {
        $cleanTunes = [];
        $visualTune = $this->sanitizeVisualTune($tunes);
        if ($visualTune !== []) {
            $cleanTunes['cmsVisual'] = $visualTune;
        }

        $anchor = $this->sanitizeAnchorTune($tunes['anchor'] ?? null);
        if ($anchor !== '') {
            $cleanTunes['anchor'] = $anchor;
        }

        $indentTune = $this->sanitizeIndentTune($tunes['indentTune'] ?? null);
        if ($indentTune !== []) {
            $cleanTunes['indentTune'] = $indentTune;
        }

        $textVariant = $this->sanitizeTextVariantTune($tunes['textVariant'] ?? null);
        if ($textVariant !== '') {
            $cleanTunes['textVariant'] = $textVariant;
        }

        if ($type !== 'image') {
            return $cleanTunes;
        }

        foreach (['Cropper', 'CropperTune'] as $key) {
            if (!isset($tunes[$key]) || !is_array($tunes[$key])) {
                continue;
            }

            $croppedImage = (string) ($tunes[$key]['croppedImage'] ?? '');
            if ($croppedImage !== '' && $this->isValidAssetUrl($croppedImage)) {
                $cleanTunes[$key] = ['croppedImage' => $croppedImage];
            }
        }

        return $cleanTunes;
    }

    private function sanitizeAnchorTune(mixed $value): string
    {
        $anchor = strtolower(trim((string) $value));
        $anchor = (string) preg_replace('/\s+/', '-', $anchor);
        $anchor = (string) preg_replace('/[^a-z0-9_-]/', '', $anchor);
        $anchor = trim($anchor, '-_');

        return substr($anchor, 0, 80);
    }

    /** @return array{indentLevel?:int} */
    private function sanitizeIndentTune(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $indentLevel = max(0, min(8, (int) ($value['indentLevel'] ?? 0)));
        return $indentLevel > 0 ? ['indentLevel' => $indentLevel] : [];
    }

    private function sanitizeTextVariantTune(mixed $value): string
    {
        $variant = (string) $value;

        return in_array($variant, ['call-out', 'citation', 'details'], true) ? $variant : '';
    }

    /**
     * @param array<string,mixed> $tunes
     * @return array{spacing?:string,alignment?:string}
     */
    private function sanitizeVisualTune(array $tunes): array
    {
        $sources = [];
        foreach (['cmsVisual', 'cmsSpacing', 'spacing', 'spacingTune', 'alignmentTune'] as $key) {
            if (isset($tunes[$key]) && is_array($tunes[$key])) {
                $sources[] = $tunes[$key];
            }
        }

        $visualTune = [];
        foreach ($sources as $source) {
            $spacing = (string) ($source['spacing'] ?? $source['space'] ?? '');
            if ($spacing !== '' && in_array($spacing, ['compact', 'normal', 'relaxed', 'loose'], true)) {
                $visualTune['spacing'] = $spacing;
            }

            $alignment = (string) ($source['alignment'] ?? $source['align'] ?? '');
            if ($alignment !== '' && in_array($alignment, ['left', 'center', 'right', 'justify'], true)) {
                $visualTune['alignment'] = $alignment;
            }
        }

        return $visualTune;
    }

    private function sanitizeListItems(array $items, string $style): array
    {
        $cleanInline = static fn(mixed $value): string => EditorJsHtmlSanitizer::sanitizeInline((string) $value);
        $cleanItems = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $cleanItems[] = [
                    'content' => $cleanInline($item),
                    'meta' => $style === 'checklist' ? ['checked' => false] : [],
                    'items' => [],
                ];
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
            if ($style === 'checklist' && !array_key_exists('checked', $meta) && array_key_exists('checked', $item)) {
                $meta['checked'] = !empty($item['checked']);
            }

            $cleanItems[] = [
                'content' => $cleanInline($item['content'] ?? $item['text'] ?? ''),
                'meta' => $this->sanitizeListMeta($style, $meta),
                'items' => $this->sanitizeListItems(is_array($item['items'] ?? null) ? $item['items'] : [], $style),
            ];
        }

        return $cleanItems;
    }

    private function sanitizeListMeta(string $style, array $meta): array
    {
        return match ($style) {
            'ordered' => [
                'start' => max(1, (int) ($meta['start'] ?? 1)),
                'counterType' => in_array(($meta['counterType'] ?? 'numeric'), ['numeric', 'lower-roman', 'upper-roman', 'lower-alpha', 'upper-alpha'], true)
                    ? (string) $meta['counterType']
                    : 'numeric',
            ],
            'checklist' => [
                'checked' => !empty($meta['checked']),
            ],
            default => [],
        };
    }

    private function sanitizeFileInfo(array $file): array
    {
        $url = $this->sanitizeAssetUrl((string) ($file['url'] ?? ''));

        return [
            'url' => $url,
            'name' => strip_tags((string) ($file['name'] ?? ''), ''),
            'size' => max(0, (int) ($file['size'] ?? 0)),
            'extension' => preg_replace('/[^a-z0-9]/i', '', (string) ($file['extension'] ?? '')),
        ];
    }

    private function sanitizeLinkMeta(array $meta): array
    {
        $cleanInline = static fn(mixed $value): string => EditorJsHtmlSanitizer::sanitizeInline((string) $value);

        return [
            'title' => $cleanInline($meta['title'] ?? ''),
            'description' => $cleanInline($meta['description'] ?? ''),
            'site_name' => $cleanInline($meta['site_name'] ?? ''),
            'image' => [
                'url' => EditorJsHtmlSanitizer::sanitizeUrl((string) ($meta['image']['url'] ?? ''), ['http', 'https'], false),
            ],
        ];
    }

    /** @return string[] */
    private function sanitizeUrlList(array $urls): array
    {
        $cleanUrls = [];
        foreach ($urls as $url) {
            $sanitized = $this->sanitizeAssetUrl((string) $url);
            if ($sanitized !== '') {
                $cleanUrls[] = $sanitized;
            }
        }

        return array_values(array_unique($cleanUrls));
    }

    private function isValidAssetUrl(string $url): bool
    {
        return $this->sanitizeAssetUrl($url, true) !== '';
    }

    private function sanitizeMediaTextSpacing(mixed $value): string
    {
        $spacing = (int) preg_replace('/[^0-9]/', '', (string) $value);
        $allowed = [0, 5, 10, 15, 20, 30, 40, 60, 80, 100];

        return in_array($spacing, $allowed, true) ? (string) $spacing : '10';
    }

    private function sanitizeMediaTextVerticalAlignment(mixed $value): string
    {
        $alignment = strtolower(trim((string) $value));

        return match ($alignment) {
            'middle', 'centre', 'center' => 'center',
            'bottom', 'end', 'flex-end' => 'bottom',
            'top', 'start', 'flex-start' => 'top',
            default => 'top',
        };
    }

    private function sanitizeImageFit(mixed $value, string $fallback): string
    {
        $fit = (string) $value;

        return in_array($fit, ['contain', 'cover', 'fill', 'none', 'scale-down'], true) ? $fit : $fallback;
    }

    private function sanitizeImageMaxHeight(mixed $value): string
    {
        $height = (int) preg_replace('/[^0-9]/', '', (string) $value);
        $allowed = [0, 200, 300, 400, 500, 600, 800, 1000];

        return (string) $this->snapNumberToPreset($height, $allowed, 0);
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

    private function sanitizeAssetUrl(string $url, bool $allowDataImage = false): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if ($allowDataImage && ($dataUrl = EditorJsHtmlSanitizer::sanitizeUrl($url, [], false, true)) !== '') {
            return $dataUrl;
        }

        if (str_starts_with($url, 'media-file?')) {
            $url = '/' . $url;
        }

        if (preg_match('#^/(?:media-file(?:\?.*)?|uploads/[A-Za-z0-9._\-/%]+)$#', $url) === 1) {
            return $url;
        }

        return EditorJsHtmlSanitizer::sanitizeUrl($url, ['http', 'https'], false);
    }
}
