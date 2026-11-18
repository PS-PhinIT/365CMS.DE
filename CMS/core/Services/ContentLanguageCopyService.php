<?php
/**
 * Content Language Copy Service
 *
 * Builds deterministic DE -> EN copy payloads for pages and posts while preserving
 * EditorJS JSON, media references, metadata and language relations.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Slug;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentLanguageCopyService
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $page
     * @return array<string,mixed>
     */
    public function buildPageGermanToEnglishPayload(array $page): array
    {
        $title = $this->normalizeText((string) ($page['title'] ?? ''), 255);
        $slug = $this->normalizeSlug((string) ($page['slug'] ?? ''));
        $content = $this->normalizeEditorJson((string) ($page['content'] ?? ''));

        // Require a real German body. Title-only payloads previously overwrote
        // existing content_en with '' and wiped published English pages.
        if (!$this->hasCopyableBodyContent($content)) {
            return [];
        }

        return [
            'title_en' => $title,
            'slug_en' => $slug !== '' ? $slug : null,
            'content_en' => $content,
            'status' => $this->normalizeStatus((string) ($page['status'] ?? 'draft'), ['published', 'draft', 'private', 'pending']),
            'category_id' => $this->normalizeNullableId($page['category_id'] ?? null),
            'featured_image' => $this->normalizeMediaReference((string) ($page['featured_image'] ?? '')),
            'meta_title_en' => $this->normalizeText((string) ($page['meta_title'] ?? ''), 255),
            'meta_description_en' => $this->normalizeText((string) ($page['meta_description'] ?? ''), 2000),
        ];
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function buildPostGermanToEnglishPayload(array $post): array
    {
        $title = $this->normalizeText((string) ($post['title'] ?? ''), 255);
        $slug = $this->normalizeSlug((string) ($post['slug'] ?? ''));
        $content = $this->normalizeEditorJson((string) ($post['content'] ?? ''));
        $excerpt = $this->normalizeText((string) ($post['excerpt'] ?? ''), 2000);

        // Title/excerpt alone must not clear an existing English body.
        if (!$this->hasCopyableBodyContent($content)) {
            return [];
        }

        return [
            'title_en' => $title,
            'slug_en' => $slug !== '' ? $slug : null,
            'content_en' => $content,
            'excerpt_en' => $excerpt,
            'status' => $this->normalizeStatus((string) ($post['status'] ?? 'draft'), ['published', 'draft', 'private', 'pending']),
            'category_id' => $this->normalizeNullableId($post['category_id'] ?? null),
            'featured_image' => $this->normalizeMediaReference((string) ($post['featured_image'] ?? '')),
            'tags' => $this->normalizeText((string) ($post['tags'] ?? ''), 500),
            'author_display_name' => $this->normalizeText((string) ($post['author_display_name'] ?? ''), 150),
            'post_template' => $this->normalizeIdentifier((string) ($post['post_template'] ?? 'default'), 80),
            'post_meta_json' => $this->normalizeJsonObject((string) ($post['post_meta_json'] ?? '')),
            'published_at' => $this->normalizeDateTime((string) ($post['published_at'] ?? '')),
            'meta_title_en' => $this->normalizeText((string) ($post['meta_title'] ?? ''), 255),
            'meta_description_en' => $this->normalizeText((string) ($post['meta_description'] ?? ''), 2000),
        ];
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function buildSeoRelationPayload(string $contentType, int $contentId, array $meta): array
    {
        $contentType = preg_replace('/[^a-z0-9_-]/i', '', strtolower(trim($contentType))) ?? 'content';
        $hreflangGroup = $this->normalizeIdentifier((string) ($meta['hreflang_group'] ?? ''), 120);

        if ($hreflangGroup === '' && $contentId > 0) {
            $hreflangGroup = $contentType . '-' . $contentId;
        }

        $meta['hreflang_group'] = $hreflangGroup;

        return $meta;
    }

    public function decodePostMetaJson(string $json): array
    {
        $normalized = $this->normalizeJsonObject($json);
        if ($normalized === null) {
            return [];
        }

        try {
            $decoded = json_decode($normalized, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeText(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value) ?? '';

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function normalizeIdentifier(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[^a-z0-9_.:-]/i', '', $value) ?? '';

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function normalizeSlug(string $slug): string
    {
        return Slug::fromText($slug);
    }

    private function normalizeStatus(string $status, array $allowed): string
    {
        $status = strtolower(trim($status));

        return in_array($status, $allowed, true) ? $status : 'draft';
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }

    private function normalizeMediaReference(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, 500) : substr($value, 0, 500);
    }

    private function normalizeEditorJson(string $rawContent): string
    {
        $rawContent = trim($rawContent);
        if ($rawContent === '') {
            return '';
        }

        try {
            $decoded = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $rawContent;
        }

        if (!is_array($decoded)) {
            return $rawContent;
        }

        if (!isset($decoded['blocks']) || !is_array($decoded['blocks'])) {
            $decoded['blocks'] = [];
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : $rawContent;
    }

    /**
     * Returns true when the German body has content worth copying to EN.
     * Empty strings and EditorJS documents without blocks are rejected so
     * DE→EN copy cannot silently erase an existing English body.
     */
    private function hasCopyableBodyContent(string $content): bool
    {
        $content = trim($content);
        if ($content === '') {
            return false;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return true;
        }

        if (!is_array($decoded)) {
            return true;
        }

        $blocks = $decoded['blocks'] ?? null;
        if (!is_array($blocks) || $blocks === []) {
            return false;
        }

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $data = $block['data'] ?? null;
            if (!is_array($data) || $data === []) {
                continue;
            }

            foreach ($data as $value) {
                if (is_string($value) && trim(strip_tags($value)) !== '') {
                    return true;
                }
                if (is_numeric($value) || is_bool($value)) {
                    return true;
                }
                if (is_array($value) && $value !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeJsonObject(string $json): ?string
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }

    private function normalizeDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
