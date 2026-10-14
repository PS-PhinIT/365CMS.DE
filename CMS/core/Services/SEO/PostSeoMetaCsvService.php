<?php
/**
 * CSV-Export und -Import der SEO-Metadaten von Beiträgen.
 *
 * Geschrieben werden ausschließlich SEO-Felder: Meta-Titel und Meta-Beschreibung in `posts`
 * sowie die Felder aus `seo_meta`. Titel, Slug, Inhalt, Auszug, Status und Veröffentlichungsdaten
 * bleiben unverändert; `posts.updated_at` wird beim Import nicht verschoben.
 *
 * @package CMS\Services\SEO
 */

declare(strict_types=1);

namespace CMS\Services\SEO;

use CMS\CacheManager;
use CMS\Database;
use CMS\Logger;
use CMS\Services\CsvExchange;
use CMS\Services\PermalinkService;
use CMS\Services\SEOService;

if (!defined('ABSPATH')) {
    exit;
}

final class PostSeoMetaCsvService
{
    /** Nur zur Orientierung exportiert, beim Import nicht geschrieben (slug dient als Ersatzschlüssel ohne post_id). */
    public const INFO_COLUMNS = ['post_id', 'slug', 'title', 'status', 'url'];

    /** Beim Import auswertbare SEO-Spalten. */
    public const SEO_COLUMNS = [
        'meta_title',
        'meta_description',
        'focus_keyphrase',
        'keywords',
        'canonical_url',
        'robots_index',
        'robots_follow',
        'og_title',
        'og_description',
        'og_image',
        'twitter_card',
        'twitter_title',
        'twitter_description',
        'twitter_image',
        'schema_type',
        'sitemap_priority',
        'sitemap_changefreq',
        'hreflang_group',
    ];

    public const EXPORT_COLUMNS = [...self::INFO_COLUMNS, ...self::SEO_COLUMNS];
    public const IMPORT_MAX_ROWS = 5000;
    public const EXPORT_MAX_ROWS = 20000;

    private const POST_TABLE_FIELDS = ['meta_title', 'meta_description'];
    private const TWITTER_CARDS = ['summary_large_image', 'summary'];
    private const CHANGEFREQ_VALUES = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
    private const MAX_ERROR_DETAILS = 25;

    private const HEADER_ALIASES = [
        'post_id' => ['id', 'beitrag_id', 'beitrags_id'],
        'slug' => [],
        'meta_title' => ['meta_titel', 'seo_title', 'seo_titel'],
        'meta_description' => ['meta_beschreibung', 'seo_description', 'seo_beschreibung'],
        'focus_keyphrase' => ['fokus_keyphrase', 'focus_keyword', 'fokus_keyword'],
        'keywords' => ['seo_tags', 'meta_keywords'],
        'canonical_url' => ['canonical', 'kanonische_url'],
        'robots_index' => ['index'],
        'robots_follow' => ['follow'],
        'og_title' => [],
        'og_description' => [],
        'og_image' => [],
        'twitter_card' => [],
        'twitter_title' => [],
        'twitter_description' => [],
        'twitter_image' => [],
        'schema_type' => ['schema'],
        'sitemap_priority' => ['priority', 'prioritaet', 'priorität', 'sitemap_prioritaet', 'sitemap_priorität'],
        'sitemap_changefreq' => ['changefreq', 'sitemap_frequenz', 'aenderungsfrequenz', 'änderungsfrequenz'],
        'hreflang_group' => ['hreflang'],
    ];

    private static ?self $instance = null;

    private readonly Database $db;
    private readonly string $prefix;
    private readonly SEOService $seoService;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
        // Legt bei Bedarf auch die Tabelle seo_meta an.
        $this->seoService = SEOService::getInstance();
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    public function getExportRows(): array
    {
        $rows = $this->db->get_results(
            "SELECT p.id, p.slug, p.title, p.status, p.published_at, p.created_at, p.meta_title, p.meta_description,
                    m.id AS seo_meta_id, m.canonical_url, m.robots_index, m.robots_follow, m.og_title, m.og_description,
                    m.og_image, m.twitter_card, m.twitter_title, m.twitter_description, m.twitter_image,
                    m.focus_keyphrase, m.keywords, m.schema_type, m.sitemap_priority, m.sitemap_changefreq, m.hreflang_group
             FROM {$this->prefix}posts p
             LEFT JOIN {$this->prefix}seo_meta m ON m.content_type = 'post' AND m.content_id = p.id
             WHERE p.status <> 'trash'
             ORDER BY p.id ASC
             LIMIT " . self::EXPORT_MAX_ROWS
        ) ?: [];

        $permalinks = class_exists(PermalinkService::class) ? PermalinkService::getInstance() : null;
        $siteUrl = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';

        $export = [];
        foreach ($rows as $row) {
            $slug = (string)($row->slug ?? '');
            $path = $permalinks !== null
                ? $permalinks->buildPostPathFromValues($slug, (string)($row->published_at ?? ''), (string)($row->created_at ?? ''))
                : '/blog/' . ltrim($slug, '/');
            $hasMeta = $row->seo_meta_id !== null;

            $export[] = [
                'post_id' => (int)$row->id,
                'slug' => $slug,
                'title' => (string)($row->title ?? ''),
                'status' => (string)($row->status ?? ''),
                'url' => $siteUrl . '/' . ltrim($path, '/'),
                'meta_title' => (string)($row->meta_title ?? ''),
                'meta_description' => (string)($row->meta_description ?? ''),
                'focus_keyphrase' => (string)($row->focus_keyphrase ?? ''),
                'keywords' => (string)($row->keywords ?? ''),
                'canonical_url' => (string)($row->canonical_url ?? ''),
                'robots_index' => !$hasMeta || (int)$row->robots_index === 1 ? 1 : 0,
                'robots_follow' => !$hasMeta || (int)$row->robots_follow === 1 ? 1 : 0,
                'og_title' => (string)($row->og_title ?? ''),
                'og_description' => (string)($row->og_description ?? ''),
                'og_image' => (string)($row->og_image ?? ''),
                'twitter_card' => trim((string)($row->twitter_card ?? '')) !== '' ? (string)$row->twitter_card : self::TWITTER_CARDS[0],
                'twitter_title' => (string)($row->twitter_title ?? ''),
                'twitter_description' => (string)($row->twitter_description ?? ''),
                'twitter_image' => (string)($row->twitter_image ?? ''),
                'schema_type' => SeoSchemaRenderer::selectableTypeFor((string)($row->schema_type ?? ''), 'post'),
                'sitemap_priority' => $row->sitemap_priority !== null ? number_format((float)$row->sitemap_priority, 1, '.', '') : '',
                'sitemap_changefreq' => (string)($row->sitemap_changefreq ?? ''),
                'hreflang_group' => (string)($row->hreflang_group ?? ''),
            ];
        }

        return $export;
    }

    /**
     * @return array{success: bool, message?: string, error?: string, details: array<int, string>, summary: array<string, int>}
     */
    public function importFromCsv(string $csvContent): array
    {
        $summary = ['updated' => 0, 'unchanged' => 0, 'failed' => 0];

        try {
            $records = CsvExchange::parse(
                $csvContent,
                self::HEADER_ALIASES,
                [],
                self::IMPORT_MAX_ROWS,
                implode(';', self::EXPORT_COLUMNS)
            );
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'details' => [], 'summary' => $summary];
        }

        $firstRecord = reset($records) ?: [];
        if (!array_key_exists('post_id', $firstRecord) && !array_key_exists('slug', $firstRecord)) {
            return [
                'success' => false,
                'error' => 'Der CSV-Datei fehlt die Spalte post_id oder slug zur Zuordnung der Beiträge.',
                'details' => [],
                'summary' => $summary,
            ];
        }
        if (array_intersect(array_keys($firstRecord), self::SEO_COLUMNS) === []) {
            return [
                'success' => false,
                'error' => 'Die CSV-Datei enthält keine SEO-Spalten. Erwartete Spalten: ' . implode(';', self::EXPORT_COLUMNS),
                'details' => [],
                'summary' => $summary,
            ];
        }

        $errors = [];
        $seenPostIds = [];
        $changedPostIds = [];

        // Eine Transaktion statt Autocommit je Zeile beschleunigt große Importe deutlich.
        $pdo = $this->db->getPdo();
        $ownsTransaction = !$pdo->inTransaction() && $pdo->beginTransaction();

        try {
            foreach ($records as $line => $record) {
                $label = trim((string)($record['slug'] ?? $record['post_id'] ?? ''));

                try {
                    $post = $this->findPost($record);
                    $postId = (int)$post->id;
                    $label = (string)$post->slug;

                    if (isset($seenPostIds[$postId])) {
                        throw new \InvalidArgumentException(sprintf('Beitrag ist bereits in Zeile %d enthalten.', $seenPostIds[$postId]));
                    }
                    $seenPostIds[$postId] = $line;

                    $currentPostFields = [
                        'meta_title' => (string)($post->meta_title ?? ''),
                        'meta_description' => (string)($post->meta_description ?? ''),
                    ];
                    $currentMeta = $this->normalizeStoredMeta($this->seoService->getContentMeta('post', $postId));

                    $newPostFields = $currentPostFields;
                    $newMeta = $currentMeta;
                    foreach (self::SEO_COLUMNS as $field) {
                        if (!array_key_exists($field, $record)) {
                            continue;
                        }

                        $value = $this->normalizeImportValue($field, $record[$field]);
                        if (in_array($field, self::POST_TABLE_FIELDS, true)) {
                            $newPostFields[$field] = $value;
                        } else {
                            $newMeta[$field] = $value;
                        }
                    }
                } catch (\InvalidArgumentException $e) {
                    $summary['failed']++;
                    $errors[] = sprintf('Zeile %d%s: %s', $line, $label !== '' ? ' (' . $label . ')' : '', $e->getMessage());
                    continue;
                }

                $postFieldsChanged = $newPostFields !== $currentPostFields;
                $metaChanged = $newMeta !== $currentMeta;
                if (!$postFieldsChanged && !$metaChanged) {
                    $summary['unchanged']++;
                    continue;
                }

                try {
                    if ($postFieldsChanged) {
                        // updated_at bewusst beibehalten: Es ändern sich nur SEO-Metadaten, nicht der Inhalt.
                        $this->db->execute(
                            "UPDATE {$this->prefix}posts SET meta_title = ?, meta_description = ?, updated_at = updated_at WHERE id = ?",
                            [$newPostFields['meta_title'], $newPostFields['meta_description'], $postId]
                        );
                    }
                    if ($metaChanged) {
                        $this->seoService->saveContentMeta('post', $postId, $newMeta);
                    }
                } catch (\Throwable $e) {
                    $summary['failed']++;
                    $errors[] = sprintf('Zeile %d (%s): Speichern fehlgeschlagen.', $line, $label);
                    Logger::instance()->withChannel('seo')->warning('SEO-Metadaten aus CSV konnten nicht gespeichert werden.', [
                        'post_id' => $postId,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                    continue;
                }

                $summary['updated']++;
                $changedPostIds[] = $postId;
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

        if ($changedPostIds !== []) {
            $this->afterMetaChange();
        }

        $summaryText = sprintf(
            '%d Beitrag/Beiträge aktualisiert, %d unverändert, %d fehlerhaft.',
            $summary['updated'],
            $summary['unchanged'],
            $summary['failed']
        );

        $details = array_slice($errors, 0, self::MAX_ERROR_DETAILS);
        if (count($errors) > self::MAX_ERROR_DETAILS) {
            $details[] = sprintf('… und %d weitere fehlerhafte Zeile(n).', count($errors) - self::MAX_ERROR_DETAILS);
        }

        if ($summary['failed'] > 0) {
            return [
                'success' => false,
                'error' => 'SEO-Import mit Fehlern abgeschlossen: ' . $summaryText,
                'type' => $summary['updated'] > 0 ? 'warning' : 'danger',
                'details' => $details,
                'summary' => $summary,
            ];
        }

        return [
            'success' => true,
            'message' => 'SEO-Import abgeschlossen: ' . $summaryText,
            'details' => [],
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string, string> $record
     * @throws \InvalidArgumentException
     */
    private function findPost(array $record): object
    {
        $rawId = trim((string)($record['post_id'] ?? ''));
        $slug = trim((string)($record['slug'] ?? ''));

        if ($rawId !== '') {
            $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new \InvalidArgumentException('Ungültige post_id „' . $this->limit($rawId, 40) . '“.');
            }

            $post = $this->db->get_row(
                "SELECT id, slug, meta_title, meta_description FROM {$this->prefix}posts WHERE id = ? AND status <> 'trash' LIMIT 1",
                [(int)$id]
            );
            if (!$post) {
                throw new \InvalidArgumentException('Beitrag mit post_id ' . (int)$id . ' wurde nicht gefunden.');
            }
            if ($slug !== '' && $slug !== (string)$post->slug) {
                throw new \InvalidArgumentException(sprintf(
                    'post_id %d gehört zum Slug „%s“, nicht zu „%s“. Zeile wurde zur Sicherheit nicht übernommen.',
                    (int)$id,
                    (string)$post->slug,
                    $this->limit($slug, 80)
                ));
            }

            return $post;
        }

        if ($slug === '') {
            throw new \InvalidArgumentException('post_id oder slug fehlt.');
        }

        $post = $this->db->get_row(
            "SELECT id, slug, meta_title, meta_description FROM {$this->prefix}posts WHERE slug = ? AND status <> 'trash' LIMIT 1",
            [$slug]
        );
        if (!$post) {
            throw new \InvalidArgumentException('Beitrag mit Slug „' . $this->limit($slug, 80) . '“ wurde nicht gefunden.');
        }

        return $post;
    }

    /**
     * Bringt gespeicherte Werte in die Form, die normalizeImportValue() liefert, damit unveränderte Zeilen erkannt werden.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function normalizeStoredMeta(array $meta): array
    {
        $meta['robots_index'] = !empty($meta['robots_index']);
        $meta['robots_follow'] = !empty($meta['robots_follow']);
        $meta['twitter_card'] = trim((string)($meta['twitter_card'] ?? '')) !== '' ? (string)$meta['twitter_card'] : self::TWITTER_CARDS[0];
        $meta['schema_type'] = SeoSchemaRenderer::selectableTypeFor((string)($meta['schema_type'] ?? ''), 'post');
        $priority = trim((string)($meta['sitemap_priority'] ?? ''));
        $meta['sitemap_priority'] = $priority !== '' ? number_format((float)$priority, 1, '.', '') : '';

        foreach ($meta as $key => $value) {
            if (!is_bool($value)) {
                $meta[$key] = (string)$value;
            }
        }

        return $meta;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function normalizeImportValue(string $field, string $raw): string|bool
    {
        $value = trim($raw);

        return match ($field) {
            'meta_title' => $this->plainText($value, 255),
            'meta_description' => $this->plainText($value, 2000),
            'focus_keyphrase', 'og_title', 'twitter_title' => $this->plainText($value, 255),
            'og_description', 'twitter_description' => $this->plainText($value, 0),
            'hreflang_group' => $this->plainText($value, 120),
            'keywords' => $this->normalizeKeywords($value),
            'canonical_url', 'og_image', 'twitter_image' => $this->normalizeUrl($field, $value),
            'robots_index', 'robots_follow' => $this->normalizeBoolean($field, $value),
            'twitter_card' => $this->normalizeChoice($field, $value, self::TWITTER_CARDS, self::TWITTER_CARDS[0]),
            'schema_type' => $this->normalizeChoice($field, $value, SeoSchemaRenderer::typeOptionsFor('post'), SeoSchemaRenderer::defaultTypeFor('post')),
            'sitemap_changefreq' => $this->normalizeChoice($field, $value, self::CHANGEFREQ_VALUES, ''),
            'sitemap_priority' => $this->normalizePriority($value),
            default => throw new \InvalidArgumentException('Unbekannte Spalte ' . $field . '.'),
        };
    }

    private function plainText(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value) ?? '');

        if ($maxLength > 0) {
            $value = function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
        }

        return $value;
    }

    private function normalizeKeywords(string $value): string
    {
        $keywords = [];
        foreach (preg_split('/[,;\n]+/u', $value) ?: [] as $tag) {
            $keyword = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$tag)) ?? '');
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

    /**
     * @throws \InvalidArgumentException
     */
    private function normalizeUrl(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (strlen($value) > 500) {
            throw new \InvalidArgumentException($field . ' ist länger als 500 Zeichen.');
        }

        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        throw new \InvalidArgumentException($field . ' muss mit / beginnen oder eine http(s)-URL sein.');
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function normalizeBoolean(string $field, string $value): bool
    {
        if ($value === '') {
            return true;
        }

        $parsed = CsvExchange::parseBoolean($value);
        if ($parsed === null) {
            throw new \InvalidArgumentException('Ungültiger Wert für ' . $field . ' (erlaubt: 1/0, ja/nein).');
        }

        return $parsed;
    }

    /**
     * @param array<int, string> $allowed
     * @throws \InvalidArgumentException
     */
    private function normalizeChoice(string $field, string $value, array $allowed, string $default): string
    {
        if ($value === '') {
            return $default;
        }

        foreach ($allowed as $option) {
            if (strcasecmp($option, $value) === 0) {
                return $option;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Ungültiger Wert „%s“ für %s (erlaubt: %s).',
            $this->limit($value, 40),
            $field,
            implode(', ', $allowed)
        ));
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function normalizePriority(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $normalized = str_replace(',', '.', $value);
        if (!is_numeric($normalized) || (float)$normalized < 0.0 || (float)$normalized > 1.0) {
            throw new \InvalidArgumentException('sitemap_priority muss zwischen 0.0 und 1.0 liegen.');
        }

        return number_format((float)$normalized, 1, '.', '');
    }

    private function afterMetaChange(): void
    {
        try {
            $this->seoService->invalidateSavedSitemaps();
        } catch (\Throwable) {
        }

        try {
            $enabled = (string)($this->db->get_var(
                "SELECT option_value FROM {$this->prefix}settings WHERE option_name = 'perf_auto_clear_content_cache' LIMIT 1"
            ) ?? '1') !== '0';
            if ($enabled) {
                CacheManager::instance()->clear();
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('seo')->warning('Content-Cache konnte nach SEO-Import nicht geleert werden.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
