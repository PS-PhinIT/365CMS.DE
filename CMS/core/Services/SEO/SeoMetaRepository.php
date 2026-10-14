<?php
declare(strict_types=1);

namespace CMS\Services\SEO;

use CMS\Contracts\DatabaseInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class SeoMetaRepository
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $prefix
    ) {
        if ($this->db instanceof \CMS\Database) {
            $this->db->runSchemaOnce('seo_meta', __FILE__, function (): void {
                $this->ensureSeoMetaTable();
            });
        } else {
            $this->ensureSeoMetaTable();
        }
    }

    public function getContentMeta(string $contentType, int $contentId): array
    {
        if ($contentId <= 0) {
            return $this->getDefaultMeta();
        }

        $row = $this->db->get_row(
            "SELECT * FROM {$this->prefix}seo_meta WHERE content_type = ? AND content_id = ? LIMIT 1",
            [$contentType, $contentId]
        );

        if (!$row) {
            return $this->getDefaultMeta();
        }

        return [
            'canonical_url' => (string) ($row->canonical_url ?? ''),
            'robots_index' => (int) ($row->robots_index ?? 1) === 1,
            'robots_follow' => (int) ($row->robots_follow ?? 1) === 1,
            'og_title' => (string) ($row->og_title ?? ''),
            'og_description' => (string) ($row->og_description ?? ''),
            'og_image' => (string) ($row->og_image ?? ''),
            'og_type' => (string) ($row->og_type ?? ''),
            'twitter_card' => (string) ($row->twitter_card ?? ''),
            'twitter_title' => (string) ($row->twitter_title ?? ''),
            'twitter_description' => (string) ($row->twitter_description ?? ''),
            'twitter_image' => (string) ($row->twitter_image ?? ''),
            'focus_keyphrase' => (string) ($row->focus_keyphrase ?? ''),
            'keywords' => (string) ($row->keywords ?? ''),
            'schema_type' => (string) ($row->schema_type ?? ''),
            'sitemap_priority' => $row->sitemap_priority !== null ? (string) $row->sitemap_priority : '',
            'sitemap_changefreq' => (string) ($row->sitemap_changefreq ?? ''),
            'hreflang_group' => (string) ($row->hreflang_group ?? ''),
        ];
    }

    public function saveContentMeta(string $contentType, int $contentId, array $data): void
    {
        if ($contentId <= 0) {
            return;
        }

        $payload = [
            'content_type' => $contentType,
            'content_id' => $contentId,
            'canonical_url' => $this->sanitizeOptionalUrl((string) ($data['canonical_url'] ?? '')),
            'robots_index' => array_key_exists('robots_index', $data) ? (!empty($data['robots_index']) ? 1 : 0) : 1,
            'robots_follow' => array_key_exists('robots_follow', $data) ? (!empty($data['robots_follow']) ? 1 : 0) : 1,
            'og_title' => $this->sanitizeText((string) ($data['og_title'] ?? ''), 255),
            'og_description' => $this->sanitizeLongText((string) ($data['og_description'] ?? '')),
            'og_image' => $this->sanitizeOptionalUrl((string) ($data['og_image'] ?? '')),
            'og_type' => $this->sanitizeText((string) ($data['og_type'] ?? 'article'), 50),
            'twitter_card' => $this->sanitizeText((string) ($data['twitter_card'] ?? 'summary_large_image'), 50),
            'twitter_title' => $this->sanitizeText((string) ($data['twitter_title'] ?? ''), 255),
            'twitter_description' => $this->sanitizeLongText((string) ($data['twitter_description'] ?? '')),
            'twitter_image' => $this->sanitizeOptionalUrl((string) ($data['twitter_image'] ?? '')),
            'focus_keyphrase' => $this->sanitizeText((string) ($data['focus_keyphrase'] ?? ''), 255),
            'keywords' => $this->sanitizeKeywords((string) ($data['keywords'] ?? $data['seo_tags'] ?? $data['meta_keywords'] ?? '')),
            'schema_type' => SeoSchemaRenderer::selectableTypeFor((string) ($data['schema_type'] ?? ''), $contentType),
            // DECIMAL-Spalte: leer muss NULL sein – '' lässt den INSERT/UPDATE im SQL-Strict-Mode scheitern.
            'sitemap_priority' => $this->sanitizePriority((string) ($data['sitemap_priority'] ?? '')) ?: null,
            'sitemap_changefreq' => $this->sanitizeChangefreq((string) ($data['sitemap_changefreq'] ?? '')),
            'hreflang_group' => $this->sanitizeText((string) ($data['hreflang_group'] ?? ''), 120),
        ];

        $existing = $this->db->get_row(
            "SELECT id FROM {$this->prefix}seo_meta WHERE content_type = ? AND content_id = ? LIMIT 1",
            [$contentType, $contentId]
        );

        if ($existing) {
            $this->db->update('seo_meta', $payload, ['id' => (int) $existing->id]);
            return;
        }

        $this->db->insert('seo_meta', $payload);
    }

    private function ensureSeoMetaTable(): void
    {
        $this->db->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->prefix}seo_meta (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                content_type VARCHAR(20) NOT NULL,
                content_id BIGINT UNSIGNED NOT NULL,
                canonical_url VARCHAR(500) DEFAULT NULL,
                robots_index TINYINT(1) NOT NULL DEFAULT 1,
                robots_follow TINYINT(1) NOT NULL DEFAULT 1,
                og_title VARCHAR(255) DEFAULT NULL,
                og_description TEXT DEFAULT NULL,
                og_image VARCHAR(500) DEFAULT NULL,
                og_type VARCHAR(50) DEFAULT NULL,
                twitter_card VARCHAR(50) DEFAULT NULL,
                twitter_title VARCHAR(255) DEFAULT NULL,
                twitter_description TEXT DEFAULT NULL,
                twitter_image VARCHAR(500) DEFAULT NULL,
                focus_keyphrase VARCHAR(255) DEFAULT NULL,
                keywords VARCHAR(500) DEFAULT NULL,
                schema_type VARCHAR(100) DEFAULT NULL,
                sitemap_priority DECIMAL(2,1) DEFAULT NULL,
                sitemap_changefreq VARCHAR(20) DEFAULT NULL,
                hreflang_group VARCHAR(120) DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_content (content_type, content_id),
                INDEX idx_content_type (content_type),
                INDEX idx_focus_keyphrase (focus_keyphrase)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->ensureSeoMetaColumns();
    }

    private function ensureSeoMetaColumns(): void
    {
        $columns = [
            'keywords' => "ALTER TABLE {$this->prefix}seo_meta ADD COLUMN keywords VARCHAR(500) DEFAULT NULL AFTER focus_keyphrase",
        ];

        foreach ($columns as $column => $sql) {
            $stmt = $this->db->getPdo()->query("SHOW COLUMNS FROM {$this->prefix}seo_meta LIKE " . $this->db->getPdo()->quote($column));
            $exists = $stmt instanceof \PDOStatement && $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
            if ($stmt instanceof \PDOStatement) {
                $stmt->closeCursor();
            }

            if (!$exists) {
                $this->db->getPdo()->exec($sql);
            }
        }
    }

    private function getDefaultMeta(): array
    {
        return [
            'canonical_url' => '',
            'robots_index' => true,
            'robots_follow' => true,
            'og_title' => '',
            'og_description' => '',
            'og_image' => '',
            'og_type' => 'article',
            'twitter_card' => 'summary_large_image',
            'twitter_title' => '',
            'twitter_description' => '',
            'twitter_image' => '',
            'focus_keyphrase' => '',
            'keywords' => '',
            // Leer: der Standard hängt vom Inhaltstyp ab (SeoSchemaRenderer::defaultTypeFor()).
            'schema_type' => '',
            'sitemap_priority' => '',
            'sitemap_changefreq' => '',
            'hreflang_group' => '',
        ];
    }

    private function sanitizeOptionalUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '/')) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }

    private function sanitizeText(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function sanitizeLongText(string $value): string
    {
        return trim(strip_tags($value));
    }

    private function sanitizeKeywords(string $value): string
    {
        $rawTags = preg_split('/[,;\n]+/u', $value) ?: [];
        $keywords = [];

        foreach ($rawTags as $tag) {
            $keyword = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $tag)) ?? '');
            if ($keyword === '') {
                continue;
            }

            $keyword = function_exists('mb_substr') ? mb_substr($keyword, 0, 60) : substr($keyword, 0, 60);
            $lookupKey = function_exists('mb_strtolower') ? mb_strtolower($keyword) : strtolower($keyword);
            if (isset($keywords[$lookupKey])) {
                continue;
            }

            $keywords[$lookupKey] = $keyword;
            if (count($keywords) >= 20) {
                break;
            }
        }

        $serialized = implode(', ', array_values($keywords));

        return function_exists('mb_substr') ? mb_substr($serialized, 0, 500) : substr($serialized, 0, 500);
    }

    private function sanitizePriority(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $float = (float) $value;
        if ($float < 0.0 || $float > 1.0) {
            return '';
        }

        return number_format($float, 1, '.', '');
    }

    private function sanitizeChangefreq(string $value): string
    {
        $allowed = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
        return in_array($value, $allowed, true) ? $value : '';
    }
}
