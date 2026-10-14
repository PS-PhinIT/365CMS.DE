<?php
/**
 * CMS WordPress Importer – Inhalts-, Slug-, Autor- und Editor.js-Hilfen
 *
 * Wiederhergestellt aus Commit 9601ef83 (vor dem Monolith-Split 6d519166).
 *
 * @package CMS_Importer
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('CMS_IMPORTER_SERVICE_CONTENT_TRAIT_LOADED') || trait_exists('CMS_Importer_Service_Content_Trait', false)) {
    return;
}

define('CMS_IMPORTER_SERVICE_CONTENT_TRAIT_LOADED', true);

trait CMS_Importer_Service_Content_Trait
{
    /**
     * Erzeugt einen eindeutigen Slug (max. 10 Versuche, dann UUID-Suffix).
     *
     * @param string $table  Vollständiger Tabellenname inkl. Prefix (z. B. 'cms_posts')
     */
    private function unique_slug(\CMS\Database $db, string $table, string $base, bool $preserveBase = false): string
    {
        $slug   = $preserveBase ? $this->preserve_source_slug($base) : $this->sanitize_slug($base);
        $try    = $slug;
        $suffix = 2;

        for ($i = 0; $i <= 10; $i++) {
            $exists = (int) $db->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE slug = ?",
                [$try]
            );
            if ($exists === 0) {
                return $try;
            }
            $try = $slug . '-' . $suffix;
            $suffix++;
        }

        return $slug . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * Nutzt vorhandene WordPress-Slugs unverändert, damit SEO-relevante URLs
     * möglichst 1:1 übernommen werden. Nur wenn kein Slug vorhanden ist oder
     * er ungültige Zeichen enthält, wird auf eine bereinigte Variante bzw.
     * den Titel-Fallback zurückgegriffen.
     */
    private function resolve_import_slug(array $item, string $fallbackTitle): string
    {
        $fallbackSlug = $this->slugify($fallbackTitle);

        if (class_exists('CMS\Services\PermalinkService')) {
            return \CMS\Services\PermalinkService::resolveImportedSourceSlug(
                (string) ($item['slug'] ?? ''),
                (string) ($item['link'] ?? ''),
                $fallbackSlug
            );
        }

        $sourceSlug = $this->preserve_source_slug((string) ($item['slug'] ?? ''));
        if ($sourceSlug !== '') {
            return $sourceSlug;
        }

        $sourceUrlSlug = $this->preserve_source_slug($this->extract_slug_from_url((string) ($item['link'] ?? '')));
        return $sourceUrlSlug !== '' ? $sourceUrlSlug : $fallbackSlug;
    }

    private function resolve_content_source_reference(array $item): ?string
    {
        foreach ([(string) ($item['guid'] ?? ''), (string) ($item['link'] ?? '')] as $candidate) {
            $reference = $this->normalize_content_reference_from_url($candidate);
            if ($reference !== null) {
                return $reference;
            }
        }

        $slugReference = $this->normalize_content_reference_path((string) ($item['slug'] ?? ''));
        return $slugReference !== '' ? $slugReference : null;
    }

    private function normalize_content_reference_from_url(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $normalizedPath = $this->normalize_content_reference_path($url);
            return $normalizedPath !== '' ? $normalizedPath : null;
        }

        $normalizedPath = $this->normalize_content_reference_path((string) parse_url($url, PHP_URL_PATH));
        if ($normalizedPath !== '') {
            return $normalizedPath;
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return null;
        }

        parse_str($query, $queryArgs);
        foreach (['page_id', 'p', 'post'] as $queryKey) {
            $queryValue = (int) ($queryArgs[$queryKey] ?? 0);
            if ($queryValue > 0) {
                return 'id:' . $queryValue;
            }
        }

        return null;
    }

    private function normalize_content_reference_path(string $path): string
    {
        $path = trim(html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $path = trim($path, "/ \t\n\r\0\x0B");
        if ($path === '') {
            return '';
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn(string $segment): bool => trim($segment) !== ''
        ));

        if ($segments === []) {
            return '';
        }

        while ($segments !== [] && $this->is_language_path_segment((string) ($segments[0] ?? ''))) {
            array_shift($segments);
        }

        while ($segments !== [] && $this->is_language_path_segment((string) ($segments[count($segments) - 1] ?? ''))) {
            array_pop($segments);
        }

        if (
            count($segments) > 1
            && $this->safe_lower((string) ($segments[0] ?? '')) === 'blog'
            && preg_match('/^\d{4}$/', (string) ($segments[1] ?? '')) === 1
        ) {
            array_shift($segments);
        }

        $normalizedSegments = [];
        foreach ($segments as $segment) {
            $normalizedSegment = $this->preserve_source_slug(rawurldecode((string) $segment));
            if ($normalizedSegment !== '') {
                $normalizedSegments[] = $normalizedSegment;
            }
        }

        return implode('/', $normalizedSegments);
    }

    private function is_language_path_segment(string $segment): bool
    {
        return in_array($this->safe_lower(trim($segment)), ['en'], true);
    }

    /**
     * Erhält einen vorhandenen WP-Slug so weit wie möglich unverändert.
     */
    private function preserve_source_slug(string $slug): string
    {
        if (class_exists('CMS\Services\PermalinkService')) {
            return \CMS\Services\PermalinkService::preserveImportedSlug($slug);
        }

        $slug = html_entity_decode(trim($slug), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug = trim($slug, "/ \t\n\r\0\x0B");
        $slug = preg_replace('/[\x00-\x1F\x7F]+/u', '', $slug) ?? $slug;

        if ($slug === '') {
            return '';
        }

        if (preg_match('/^[\p{L}\p{N}\-._~%]+$/u', $slug) === 1) {
            return $this->safe_substr($slug, 0, 190);
        }

        return $this->sanitize_slug($slug);
    }

    private function extract_slug_from_url(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path), static fn(string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return '';
        }

        return urldecode((string) end($segments));
    }

    /**
     * Bereinigt einen Slug: Unicode-Buchstaben/Ziffern + Bindestriche, max. 190 Zeichen.
     */
    private function sanitize_slug(string $slug): string
    {
        $slug = $this->safe_lower(trim($slug));
        $slug = preg_replace('/[^\p{L}\p{N}\-]/u', '-', $slug) ?? $slug;
        $slug = preg_replace('/-{2,}/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        return $this->safe_substr($slug !== '' ? $slug : 'imported', 0, 190);
    }

    /**
     * Wandelt beliebigen Text in einen URL-freundlichen Slug um.
     * Nutzt intl-Transliteration falls verfügbar, sonst Umlaut-Fallback.
     */
    private function slugify(string $text): string
    {
        if (function_exists('transliterator_transliterate')) {
            $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
            if ($ascii !== false && $ascii !== '') {
                return $this->sanitize_slug($ascii);
            }
        }

        $map = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ];
        $text = strtr($this->safe_lower($text), $map);
        return $this->sanitize_slug($text ?: 'imported-' . time());
    }

    /**
     * Bereinigt einen Titel für DB-Speicherung (strip_tags + trim, kein HTML-Encoding!).
     */
    private function sanitize_title(string $text): string
    {
        return trim(strip_tags($text));
    }

    /**
     * Gibt einen gültigen MySQL-Datumstring oder null zurück.
     */
    private function safe_date(string $date): ?string
    {
        if ($date === '' || $date === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime($date);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function resolve_original_created_at(array $item): ?string
    {
        foreach (['date', 'date_gmt', 'modified', 'modified_gmt'] as $field) {
            $resolved = $this->safe_date((string) ($item[$field] ?? ''));
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function resolve_original_updated_at(array $item, ?string $fallback): ?string
    {
        foreach (['modified', 'modified_gmt', 'date', 'date_gmt'] as $field) {
            $resolved = $this->safe_date((string) ($item[$field] ?? ''));
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $fallback;
    }

    /**
     * Ermittelt die CMS-User-ID anhand des WP-Author-Logins (Username oder E-Mail).
     */
    private function resolve_author_id(\CMS\Database $db, string $p, string $login): int
    {
        if ($login === '') {
            return 0;
        }
        try {
            $id = $db->get_var(
                "SELECT id FROM {$p}users WHERE username = ? OR email = ? LIMIT 1",
                [$login, $login]
            );
            return (int) ($id ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function resolve_import_author_id(\CMS\Database $db, string $p, array $item): int
    {
        $assignedAuthorId = max(0, (int) ($this->options['assigned_author_id'] ?? 0));
        if ($assignedAuthorId > 0) {
            try {
                $existingId = $db->get_var(
                    "SELECT id FROM {$p}users WHERE id = ? LIMIT 1",
                    [$assignedAuthorId]
                );

                if ((int) ($existingId ?? 0) > 0) {
                    return $assignedAuthorId;
                }
            } catch (\Throwable) {
            }
        }

        return $this->resolve_author_id($db, $p, (string) ($item['author_login'] ?? ''));
    }

    private function resolve_import_author_display_name(): ?string
    {
        $displayName = trim(strip_tags((string) ($this->options['author_display_name'] ?? '')));
        if ($displayName === '') {
            return null;
        }

        return function_exists('mb_substr')
            ? mb_substr($displayName, 0, 150)
            : substr($displayName, 0, 150);
    }

    /**
     * @return array{author_id:int,author_label:string,author_display_name:string}
     */
    private function resolve_import_author_preview(\CMS\Database $db, string $p, array $item): array
    {
        $authorId = $this->resolve_import_author_id($db, $p, $item);
        $authorLabel = '';

        if ($authorId > 0) {
            try {
                $row = $db->get_row(
                    "SELECT display_name, username FROM {$p}users WHERE id = ? LIMIT 1",
                    [$authorId]
                );
                if ($row !== null) {
                    $displayName = trim((string) ($row->display_name ?? ''));
                    $username = trim((string) ($row->username ?? ''));
                    $authorLabel = $displayName !== '' ? $displayName : $username;
                    if ($username !== '' && $username !== $authorLabel) {
                        $authorLabel .= ' (@' . $username . ')';
                    }
                }
            } catch (\Throwable) {
            }
        }

        return [
            'author_id' => $authorId,
            'author_label' => $authorLabel,
            'author_display_name' => (string) ($this->resolve_import_author_display_name() ?? ''),
        ];
    }

    private function prepare_content_payload(\CMS\Database $db, string $p, array $item, string $contextType, string $slug): array
    {
        $content = (string) ($item['content'] ?? '');
        if (!empty($this->options['convert_table_shortcodes'])) {
            $content = $this->replace_table_shortcodes($db, $p, $content);
        }

        $excerpt = (string) ($item['excerpt'] ?? '');
        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : $this->default_seo_payload();
        $featuredImage = trim((string) ($item['featured_image'] ?? ''));
        $candidates = $this->collect_media_candidates($item, $featuredImage);

        if (!empty($this->options['download_images'])) {
            $downloads = $this->download_media_assets($db, $p, $contextType, $slug, $candidates, $featuredImage);
            $urlMap = $downloads['url_map'] ?? [];

            if ($urlMap !== []) {
                $content = $this->rewrite_url_map($content, $urlMap);
                $excerpt = $this->rewrite_url_map($excerpt, $urlMap);
                $featuredImage = $this->resolve_featured_image($featuredImage, $urlMap, (string) ($downloads['featured_local_url'] ?? ''));
                $seo = $this->rewrite_seo_image_urls($seo, $urlMap, $featuredImage);
            }
        }

        if ($featuredImage === '' && !empty($item['image_urls'][0])) {
            $featuredImage = (string) $item['image_urls'][0];
        }

        if (in_array($contextType, ['post', 'page'], true)) {
            $content = $this->normalize_content_for_active_editor($content);
        }

        return [
            'content' => $content,
            'excerpt' => $excerpt,
            'featured_image' => $featuredImage,
            'seo' => $seo,
        ];
    }

    private function normalize_content_for_active_editor(string $content): string
    {
        if ($content === '' || !$this->is_editorjs_active() || $this->is_editorjs_payload($content)) {
            return $content;
        }

        $blocks = $this->convert_html_to_editorjs_blocks($content);
        if ($blocks === []) {
            return $content;
        }

        $payload = [
            'time' => time() * 1000,
            'blocks' => $blocks,
            'version' => '2.30.8',
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) && $json !== '' ? $json : $content;
    }

    private function is_editorjs_active(): bool
    {
        if (class_exists('CMS\Services\EditorService') && method_exists('CMS\Services\EditorService', 'isEditorJs')) {
            return \CMS\Services\EditorService::isEditorJs();
        }

        if (function_exists('get_option')) {
            return (string) get_option('setting_editor_type', 'editorjs') === 'editorjs';
        }

        return false;
    }

    private function is_editorjs_payload(string $content): bool
    {
        $trimmed = trim($content);
        if ($trimmed === '' || ($trimmed[0] ?? '') !== '{') {
            return false;
        }

        $decoded = json_decode($trimmed, true);
        return is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function convert_html_to_editorjs_blocks(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        if (!class_exists('DOMDocument')) {
            return [[
                'type' => 'raw',
                'data' => ['html' => $html],
            ]];
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previousState = libxml_use_internal_errors(true);

        $wrappedHtml = '<!DOCTYPE html><html><body>' . $html . '</body></html>';
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        if ($loaded === false) {
            return [[
                'type' => 'raw',
                'data' => ['html' => $html],
            ]];
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return [[
                'type' => 'raw',
                'data' => ['html' => $html],
            ]];
        }

        return $this->convert_dom_nodes_to_editorjs_blocks($body->childNodes);
    }

    /**
     * @param \DOMNodeList<int>|array<int, \DOMNode> $nodes
     * @return array<int, array<string, mixed>>
     */
    private function convert_dom_nodes_to_editorjs_blocks(\DOMNodeList|array $nodes): array
    {
        $nodeList = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMNode) {
                $nodeList[] = $node;
            }
        }

        $blocks = [];

        for ($index = 0, $total = count($nodeList); $index < $total; $index++) {
            $node = $nodeList[$index];

            [$galleryUrls, $lastGalleryIndex] = $this->consume_gallery_sequence($nodeList, $index);
            if (count($galleryUrls) > 1) {
                $blocks[] = [
                    'type' => 'imageGallery',
                    'data' => ['urls' => $galleryUrls],
                ];
                $index = $lastGalleryIndex;
                continue;
            }

            if ($node instanceof \DOMText) {
                $text = trim($node->textContent ?? '');
                if ($text !== '') {
                    $blocks[] = [
                        'type' => 'paragraph',
                        'data' => [
                            'text' => nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false),
                        ],
                    ];
                }
                continue;
            }

            if (!$node instanceof \DOMElement) {
                continue;
            }

            if ($this->is_gallery_element($node)) {
                $galleryBlock = $this->build_gallery_block($node);
                if ($galleryBlock !== null) {
                    $blocks[] = $galleryBlock;
                    continue;
                }
            }

            $tag = strtolower($node->tagName);
            $block = match ($tag) {
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->build_header_block($node, (int) substr($tag, 1)),
                'p' => $this->build_paragraph_like_block($node),
                'blockquote' => $this->build_quote_block($node),
                'pre' => $this->build_code_block($node),
                'hr' => ['type' => 'delimiter', 'data' => new \stdClass()],
                'ul', 'ol' => $this->build_list_block($node),
                'table' => $this->build_table_block($node),
                'figure' => $this->build_figure_block($node),
                'img' => $this->build_image_block($node),
                'iframe' => $this->build_embed_block_from_url((string) $node->getAttribute('src'), ''),
                'video' => $this->build_raw_block($this->get_outer_html($node)),
                'audio' => $this->build_raw_block($this->get_outer_html($node)),
                'div', 'section' => $this->build_container_block($node),
                default => $this->build_fallback_block($node),
            };

            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @param array<int, \DOMNode> $nodes
     * @return array{0: array<int, string>, 1: int}
     */
    private function consume_gallery_sequence(array $nodes, int $startIndex): array
    {
        $urls = [];
        $lastGalleryIndex = $startIndex;
        $foundGalleryNode = false;

        for ($index = $startIndex, $total = count($nodes); $index < $total; $index++) {
            $node = $nodes[$index];

            if ($node instanceof \DOMText) {
                if (trim($node->textContent ?? '') === '') {
                    continue;
                }

                break;
            }

            if (!$node instanceof \DOMElement) {
                break;
            }

            $candidateUrls = $this->extract_gallery_candidate_urls($node);
            if ($candidateUrls === []) {
                break;
            }

            foreach ($candidateUrls as $url) {
                $urls[$url] = $url;
            }

            $foundGalleryNode = true;
            $lastGalleryIndex = $index;
        }

        if (!$foundGalleryNode || count($urls) <= 1) {
            return [[], $startIndex];
        }

        return [array_values($urls), $lastGalleryIndex];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_header_block(\DOMElement $element, int $level): ?array
    {
        $text = trim($this->get_inner_html($element));
        if ($text === '') {
            return null;
        }

        return [
            'type' => 'header',
            'data' => [
                'text' => $text,
                'level' => max(1, min(6, $level)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_paragraph_like_block(\DOMElement $element): ?array
    {
        $images = $this->collect_image_urls_from_element($element);
        if (count($images) > 1) {
            return [
                'type' => 'imageGallery',
                'data' => ['urls' => array_values($images)],
            ];
        }

        if (count($images) === 1 && trim(strip_tags($this->get_inner_html_without_media($element))) === '') {
            $img = $this->find_first_descendant_tag($element, 'img');
            return $img instanceof \DOMElement ? $this->build_image_block($img) : null;
        }

        $link = $this->resolve_single_file_link($element);
        if ($link !== null) {
            return $link;
        }

        $iframe = $this->find_first_descendant_tag($element, 'iframe');
        if ($iframe instanceof \DOMElement) {
            $caption = trim($this->extract_caption_from_element($element));
            return $this->build_embed_block_from_url((string) $iframe->getAttribute('src'), $caption);
        }

        $html = trim($this->get_inner_html($element));
        if ($html === '') {
            return null;
        }

        return [
            'type' => 'paragraph',
            'data' => [
                'text' => $html,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_quote_block(\DOMElement $element): ?array
    {
        $text = trim($this->get_inner_html($element));
        if ($text === '') {
            return null;
        }

        $caption = '';
        foreach ($element->getElementsByTagName('cite') as $cite) {
            if ($cite instanceof \DOMElement) {
                $caption = trim($this->get_inner_html($cite));
                break;
            }
        }

        return [
            'type' => 'quote',
            'data' => [
                'text' => $text,
                'caption' => $caption,
                'alignment' => 'left',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_code_block(\DOMElement $element): ?array
    {
        $code = trim($element->textContent ?? '');
        if ($code === '') {
            return null;
        }

        return [
            'type' => 'code',
            'data' => [
                'code' => $code,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_list_block(\DOMElement $element): ?array
    {
        $style = strtolower($element->tagName) === 'ol' ? 'ordered' : 'unordered';
        $items = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'li') {
                $items[] = $this->normalize_list_item($child);
            }
        }

        if ($items === []) {
            return null;
        }

        return [
            'type' => 'list',
            'data' => [
                'style' => $style,
                'meta' => $style === 'ordered' ? ['start' => 1, 'counterType' => 'numeric'] : new \stdClass(),
                'items' => $items,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize_list_item(\DOMElement $element): array
    {
        $content = '';
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                foreach ($child->childNodes as $nestedChild) {
                    if ($nestedChild instanceof \DOMElement && strtolower($nestedChild->tagName) === 'li') {
                        $children[] = $this->normalize_list_item($nestedChild);
                    }
                }
                continue;
            }

            $content .= $this->serialize_node_html($child);
        }

        return [
            'content' => trim($content),
            'meta' => new \stdClass(),
            'items' => $children,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_table_block(\DOMElement $element): ?array
    {
        $rows = [];
        $withHeadings = false;

        foreach ($element->getElementsByTagName('tr') as $row) {
            if (!$row instanceof \DOMElement) {
                continue;
            }

            $cells = [];
            foreach ($row->childNodes as $cell) {
                if (!$cell instanceof \DOMElement) {
                    continue;
                }

                $tag = strtolower($cell->tagName);
                if (!in_array($tag, ['th', 'td'], true)) {
                    continue;
                }

                if ($tag === 'th') {
                    $withHeadings = true;
                }

                $cells[] = trim($this->get_inner_html($cell));
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return null;
        }

        return [
            'type' => 'table',
            'data' => [
                'withHeadings' => $withHeadings,
                'content' => $rows,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_figure_block(\DOMElement $element): ?array
    {
        if ($this->is_gallery_element($element)) {
            return $this->build_gallery_block($element);
        }

        $iframe = $this->find_first_descendant_tag($element, 'iframe');
        if ($iframe instanceof \DOMElement) {
            return $this->build_embed_block_from_url(
                (string) $iframe->getAttribute('src'),
                $this->extract_caption_from_element($element)
            );
        }

        $img = $this->find_first_descendant_tag($element, 'img');
        if ($img instanceof \DOMElement) {
            $caption = $this->extract_caption_from_element($element);
            return $this->build_image_block($img, $caption);
        }

        return $this->build_fallback_block($element);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_image_block(\DOMElement $element, string $caption = ''): ?array
    {
        $url = trim((string) $element->getAttribute('src'));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $resolvedCaption = trim($caption !== '' ? $caption : (string) $element->getAttribute('alt'));

        return [
            'type' => 'image',
            'data' => [
                'file' => ['url' => $url],
                'caption' => $resolvedCaption,
                'withBorder' => false,
                'withBackground' => false,
                'stretched' => false,
            ],
        ];
    }
}
