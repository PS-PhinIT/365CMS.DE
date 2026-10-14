<?php
/**
 * CMS WordPress Importer – Kern-Logik & Datenbankzugriff
 *
 * Verantwortlich für:
 * - DB-Tabellen anlegen (create_tables)
 * - Posts importieren (import_as_post)
 * - Pages importieren (import_as_page)
 * - Bilder herunterladen (download_post_images)
 * - Import-Log schreiben
 * - Markdown-Bericht für unbekannte Meta-Felder erstellen
 *
 * @package CMS_Importer
 * @since   1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (
    defined('CMS_IMPORTER_SERVICE_CLASSES_LOADED')
    || class_exists('CMS_Importer_DB', false)
    || class_exists('CMS_Importer_Service', false)
) {
    return;
}

define('CMS_IMPORTER_SERVICE_CLASSES_LOADED', true);

// ── Datenbankschicht ──────────────────────────────────────────────────────────

/**
 * Kapselt die DB-Initialisierung des Importers.
 */
class CMS_Importer_DB
{
    /**
     * Erstellt die benötigten Tabellen, falls noch nicht vorhanden.
     * Legt außerdem das Upload-Verzeichnis uploads/import/ an.
     */
    public static function create_tables(): void
    {
        if (!class_exists('CMS\Database')) {
            return;
        }

        $db = CMS\Database::instance();
        $p  = $db->getPrefix();

        // Import-Log: eine Zeile pro Import-Run
        $db->query("
            CREATE TABLE IF NOT EXISTS {$p}import_log (
                id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename          VARCHAR(255) NOT NULL,
                import_type       ENUM('posts','pages','mixed','other') DEFAULT 'mixed',
                total             INT UNSIGNED DEFAULT 0,
                imported          INT UNSIGNED DEFAULT 0,
                skipped           INT UNSIGNED DEFAULT 0,
                errors            INT UNSIGNED DEFAULT 0,
                images_downloaded INT UNSIGNED DEFAULT 0,
                meta_report_path  VARCHAR(500),
                started_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                finished_at       TIMESTAMP NULL,
                user_id           INT UNSIGNED NULL,
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Importierte Meta-Felder (nicht auf CMS-Felder gemappte Keys)
        $db->query("
            CREATE TABLE IF NOT EXISTS {$p}import_meta (
                id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                log_id      INT UNSIGNED NOT NULL,
                source_id   VARCHAR(50)  NOT NULL COMMENT 'wp_post_id aus WXR',
                post_title  VARCHAR(255),
                post_type   VARCHAR(50),
                meta_key    VARCHAR(255) NOT NULL,
                meta_value  LONGTEXT,
                INDEX idx_log (log_id),
                INDEX idx_key (meta_key(100))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS {$p}import_items (
                id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                log_id           INT UNSIGNED DEFAULT NULL,
                source_type      VARCHAR(50) NOT NULL,
                source_wp_id     BIGINT UNSIGNED DEFAULT NULL,
                source_reference VARCHAR(191) DEFAULT NULL,
                source_slug      VARCHAR(255) DEFAULT NULL,
                source_url       VARCHAR(500) DEFAULT NULL,
                target_type      VARCHAR(50) NOT NULL,
                target_id        BIGINT UNSIGNED DEFAULT NULL,
                target_created   TINYINT(1) NOT NULL DEFAULT 1,
                target_slug      VARCHAR(255) DEFAULT NULL,
                target_url       VARCHAR(500) DEFAULT NULL,
                created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_log (log_id),
                INDEX idx_source_wp (source_type, source_wp_id),
                INDEX idx_source_ref (source_type, source_reference),
                INDEX idx_target (target_type, target_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        self::ensure_import_item_columns($db, $p);
        self::ensure_post_author_display_name_column($db, $p);

        // Upload-Ordner für Import-Dateien anlegen
        if (defined('UPLOAD_PATH')) {
            $import_dir = rtrim(UPLOAD_PATH, '/') . '/import/';
            if (!is_dir($import_dir)) {
                mkdir($import_dir, 0755, true);
            }
        }
    }

    private static function ensure_import_item_columns(\CMS\Database $db, string $p): void
    {
        try {
            $targetCreated = $db->query("SHOW COLUMNS FROM {$p}import_items LIKE 'target_created'");
            if ($targetCreated instanceof \PDOStatement && !$targetCreated->fetch()) {
                $db->query("ALTER TABLE {$p}import_items ADD COLUMN target_created TINYINT(1) NOT NULL DEFAULT 1 AFTER target_id");
            }
        } catch (\Throwable $e) {
            error_log('CMS_Importer_DB::ensure_import_item_columns() warning: ' . $e->getMessage());
        }
    }

    private static function ensure_post_author_display_name_column(\CMS\Database $db, string $p): void
    {
        try {
            $authorDisplay = $db->query("SHOW COLUMNS FROM {$p}posts LIKE 'author_display_name'");
            if ($authorDisplay instanceof \PDOStatement && !$authorDisplay->fetch()) {
                $db->query("ALTER TABLE {$p}posts ADD COLUMN author_display_name VARCHAR(150) DEFAULT NULL AFTER author_id");
            }
        } catch (\Throwable $e) {
            error_log('CMS_Importer_DB::ensure_post_author_display_name_column() warning: ' . $e->getMessage());
        }
    }
}

// ── Haupt-Importer ────────────────────────────────────────────────────────────

/**
 * Führt den eigentlichen Import durch.
 */
class CMS_Importer_Service
{
    /** Status-Mapping WP → CMS */
    private const STATUS_MAP = [
        'publish'   => 'published',
        'published' => 'published',
        'draft'     => 'draft',
        'pending'   => 'draft',
        'future'    => 'draft',
        'private'   => 'draft',
        'trash'     => 'trash',
    ];

    private int    $log_id            = 0;
    private int    $total             = 0;
    private int    $imported          = 0;
    private int    $skipped           = 0;
    private int    $errors            = 0;
    private int    $images_downloaded = 0;
    private int    $comments_total    = 0;
    private int    $comments_imported = 0;
    private int    $comments_skipped  = 0;
    private int    $settings_imported = 0;
    private array  $unknown_meta      = [];
    private array  $skip_reasons      = [];
    private string $filename          = '';
    private array  $options           = [];
    private array  $attachment_lookup = [];
    private array  $attachment_by_url = [];
    private array  $table_reference_map = [];
    private array  $import_breakdown  = [
        'settings' => 0,
        'posts'  => 0,
        'pages'  => 0,
        'tables' => 0,
        'redirects' => 0,
        'comments' => 0,
        'others' => 0,
    ];
    private ?\CMS\Services\SEO\SeoMetaRepository $seoRepository = null;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Importiert eine geparste WXR-Datei.
     *
     * @param  array  $parsed    Ergebnis von CMS_Importer_XML_Parser::parse()
     * @param  string $filename  Originaler Dateiname (für Log)
     * @param  int    $user_id   Benutzer-ID die den Import auslöst
     * @param  array  $options   Import-Optionen (skip_duplicates, import_drafts, ...)
     * @return array  Zusammenfassung des Import-Runs
     */
    public function import(array $parsed, string $filename, int $user_id = 0, array $options = []): array
    {
        if (!class_exists('CMS\Database')) {
            return ['error' => 'CMS\\Database nicht verfügbar'];
        }

        $this->filename = $filename;
        $this->options  = array_merge([
            'skip_duplicates'     => true,
            'import_drafts'       => true,
            'import_trashed'      => false,
            'import_custom_types' => true,
            'import_only_en'      => false,
            'generate_report'     => true,
            'download_images'     => true,
            'convert_table_shortcodes' => true,
            'assigned_author_id'  => 0,
            'author_display_name' => '',
        ], $options);

        $this->reset_counters();

        $db = CMS\Database::instance();
        $p  = $db->getPrefix();
        $this->attachment_lookup = $parsed['attachments'] ?? [];
        $this->attachment_by_url = $this->index_attachments_by_url($this->attachment_lookup);
        $this->seoRepository = class_exists('CMS\\Services\\SEO\\SeoMetaRepository')
            ? new \CMS\Services\SEO\SeoMetaRepository($db, $p)
            : null;

        $this->log_id = $this->create_log_entry($db, $p, $filename, $user_id);

        if (!empty($parsed['seo_settings']['settings']) && is_array($parsed['seo_settings']['settings'])) {
            $this->total++;
            $this->import_rank_math_seo_settings($db, $p, $parsed['seo_settings']);
        }

        foreach ($parsed['tables'] as $item) {
            $this->total++;
            $this->import_as_table($db, $p, $item);
        }

        foreach ($parsed['redirects'] ?? [] as $item) {
            $this->total++;
            $this->import_as_redirect($db, $p, $item);
        }

        foreach ($this->prioritize_items_by_locale($parsed['posts']) as $item) {
            $this->total++;
            $this->import_as_post($db, $p, $item, false);
        }

        foreach ($this->prioritize_items_by_locale($parsed['pages']) as $item) {
            $this->total++;
            $this->import_as_page($db, $p, $item);
        }

        if ($this->options['import_custom_types']) {
            foreach ($this->prioritize_items_by_locale($parsed['others']) as $item) {
                $this->total++;
                $this->import_as_post($db, $p, $item, true);
            }
        }

        if ($this->options['generate_report']) {
            $this->store_unknown_meta($db, $p);
        }

        $report_path = '';
        if ($this->options['generate_report']) {
            $report_path = $this->generate_meta_report($parsed['site'] ?? []);
        }

        $this->finalize_log($db, $p, $report_path);

        return [
            'log_id'            => $this->log_id,
            'total'             => $this->total,
            'imported'          => $this->imported,
            'skipped'           => $this->skipped,
            'skip_reasons'      => $this->skip_reasons,
            'errors'            => $this->errors,
            'images_downloaded' => $this->images_downloaded,
            'comments_total'    => $this->comments_total,
            'comments_imported' => $this->comments_imported,
            'comments_skipped'  => $this->comments_skipped,
            'meta_keys'         => count(array_unique(array_column($this->unknown_meta, 'meta_key'))),
            'meta_report'       => $report_path,
            'settings_imported' => $this->import_breakdown['settings'],
            'settings_keys_imported' => $this->settings_imported,
            'posts_imported'    => $this->import_breakdown['posts'],
            'pages_imported'    => $this->import_breakdown['pages'],
            'tables_imported'   => $this->import_breakdown['tables'],
            'redirects_imported' => $this->import_breakdown['redirects'],
            'comments_imported_breakdown' => $this->import_breakdown['comments'],
            'others_imported'   => $this->import_breakdown['others'],
        ];
    }

    /**
     * Simuliert einen Importlauf ohne Schreibzugriffe.
     *
     * @return array<string, mixed>
     */
    public function preview(array $parsed, string $filename, array $options = []): array
    {
        if (!class_exists('CMS\Database')) {
            return ['error' => 'CMS\\Database nicht verfügbar'];
        }

        $this->filename = $filename;
        $this->options  = array_merge([
            'skip_duplicates'     => true,
            'import_drafts'       => true,
            'import_trashed'      => false,
            'import_custom_types' => true,
            'import_only_en'      => false,
            'generate_report'     => true,
            'download_images'     => true,
            'convert_table_shortcodes' => true,
            'assigned_author_id'  => 0,
            'author_display_name' => '',
        ], $options);

        $this->reset_counters();

        $db = CMS\Database::instance();
        $p  = $db->getPrefix();
        $this->attachment_lookup = $parsed['attachments'] ?? [];
        $this->attachment_by_url = $this->index_attachments_by_url($this->attachment_lookup);

        $context = [
            'reserved_slugs' => [
                'post' => [],
                'page' => [],
                'site_table' => [],
                'redirect' => [],
            ],
            'preview_items' => [],
            'preview_limit' => 25,
            'would_import' => 0,
            'would_skip' => 0,
            'images_detected' => 0,
            'table_shortcodes_found' => 0,
            'table_shortcodes_resolved' => 0,
            'breakdown' => [
                'settings' => 0,
                'posts' => 0,
                'pages' => 0,
                'tables' => 0,
                'redirects' => 0,
                'comments' => 0,
                'others' => 0,
            ],
            'skip_reasons' => [],
            'table_preview_map' => [],
            'items_total' => 0,
            'comments_total' => 0,
            'comments_would_import' => 0,
            'comments_would_skip' => 0,
        ];

        if (!empty($parsed['seo_settings']['settings']) && is_array($parsed['seo_settings']['settings'])) {
            $this->collect_preview_item($this->build_settings_preview($parsed['seo_settings']), $context);
        }

        foreach ($parsed['tables'] as $item) {
            $this->collect_preview_item($this->build_table_preview($db, $p, $item, $context), $context);
        }

        foreach (($parsed['redirects'] ?? []) as $item) {
            $this->collect_preview_item($this->build_redirect_preview($db, $p, $item, $context), $context);
        }

        foreach ($this->prioritize_items_by_locale($parsed['posts']) as $item) {
            $this->collect_preview_item($this->build_post_preview($db, $p, $item, false, $context), $context);
        }

        foreach ($this->prioritize_items_by_locale($parsed['pages']) as $item) {
            $this->collect_preview_item($this->build_page_preview($db, $p, $item, $context), $context);
        }

        foreach ($this->prioritize_items_by_locale($parsed['others']) as $item) {
            $this->collect_preview_item($this->build_post_preview($db, $p, $item, true, $context), $context);
        }

        return [
            'mode' => 'preview',
            'filename' => $filename,
            'total' => $context['items_total'],
            'would_import' => $context['would_import'],
            'would_skip' => $context['would_skip'],
            'images_detected' => $context['images_detected'],
            'table_shortcodes_found' => $context['table_shortcodes_found'],
            'table_shortcodes_resolved' => $context['table_shortcodes_resolved'],
            'meta_keys' => count(array_unique(array_column($this->unknown_meta, 'meta_key'))),
            'attachments' => count($parsed['attachments'] ?? []),
            'comments_detected' => $context['comments_total'],
            'comments_would_import' => $context['comments_would_import'],
            'comments_would_skip' => $context['comments_would_skip'],
            'source_counts' => [
                'settings' => !empty($parsed['seo_settings']['settings']) ? 1 : 0,
                'posts' => count($parsed['posts'] ?? []),
                'pages' => count($parsed['pages'] ?? []),
                'tables' => count($parsed['tables'] ?? []),
                'redirects' => count($parsed['redirects'] ?? []),
                'others' => count($parsed['others'] ?? []),
            ],
            'preview_counts' => $context['breakdown'],
            'skip_reasons' => $context['skip_reasons'],
            'items' => $context['preview_items'],
            'items_total' => $context['items_total'],
            'items_shown' => count($context['preview_items']),
            'items_truncated' => $context['items_total'] > count($context['preview_items']),
        ];
    }

    // ── Private: Posts ────────────────────────────────────────────────────────

    private function import_as_post(\CMS\Database $db, string $p, array $item, bool $isCustomType): void
    {
        $status = self::STATUS_MAP[$item['post_status']] ?? 'draft';
        $sourceType = (string) ($item['post_type'] ?? 'post');
        $sourceReference = $this->resolve_content_source_reference($item);
        $locale = $this->resolve_item_locale($item);

        if ($this->should_skip_item_by_locale_filter($item)) {
            $this->skip_item($this->get_locale_filter_skip_reason($item));
            return;
        }

        if ($status === 'trash' && !$this->options['import_trashed']) {
            $this->skip_item('Papierkorb-Elemente deaktiviert');
            return;
        }
        if ($status === 'draft' && !$this->options['import_drafts']) {
            $this->skip_item('Entwürfe deaktiviert');
            return;
        }

        $base_slug = $this->resolve_target_slug($item, (string) ($item['title'] ?? ''));

        if ($locale !== 'de') {
            $localizedTarget = $this->find_preferred_localized_content_target($db, $p, 'post', $sourceType, $sourceReference, $base_slug);
            if ($localizedTarget !== null) {
                $this->import_into_localized_post($db, $p, $item, $sourceType, $sourceReference, $localizedTarget, $base_slug, $locale, $isCustomType);
                return;
            }
        }

        $existingSourceMapping = $this->find_existing_mapping_by_wp_id($db, $p, $sourceType, (int) ($item['wp_id'] ?? 0), 'post', true);
        if ($this->should_ignore_existing_source_mapping($existingSourceMapping, $base_slug, $sourceReference)) {
            $existingSourceMapping = null;
        }

        if ($this->options['skip_duplicates'] && $existingSourceMapping !== null) {
            if ($locale !== 'de') {
                $this->import_into_localized_post($db, $p, $item, $sourceType, $sourceReference, $existingSourceMapping, $base_slug, $locale, $isCustomType);
                return;
            }

            $this->import_comments_for_post($db, $p, $item, (int) ($existingSourceMapping['target_id'] ?? 0), (string) ($existingSourceMapping['target_slug'] ?? ''), (string) ($item['date'] ?? ''));
            $this->skip_item('Bereits per Import-Mapping vorhanden');
            return;
        }

        if ($this->options['skip_duplicates']) {
            $existingPostId = (int) ($db->get_var(
                "SELECT id FROM {$p}posts WHERE slug = ? ORDER BY id ASC LIMIT 1",
                [$base_slug]
            ) ?? 0);
            if ($existingPostId > 0) {
                if ($locale !== 'de') {
                    $this->import_into_localized_post($db, $p, $item, $sourceType, $sourceReference, [
                        'target_id' => $existingPostId,
                        'target_slug' => $base_slug,
                    ], $base_slug, $locale, $isCustomType);
                    return;
                }

                $this->store_import_item($db, $p, [
                    'log_id'           => $this->log_id,
                    'source_type'      => $sourceType,
                    'source_wp_id'     => (int) ($item['wp_id'] ?? 0),
                    'source_reference' => $sourceReference,
                    'source_slug'      => (string) ($item['slug'] ?? ''),
                    'source_url'       => (string) ($item['link'] ?? ''),
                    'target_type'      => 'post',
                    'target_id'        => $existingPostId,
                    'target_created'   => 0,
                    'target_slug'      => $base_slug,
                    'target_url'       => $this->build_target_url('post', $base_slug, $existingPostId, (string) ($item['date'] ?? ''), $locale),
                ]);
                $this->import_comments_for_post($db, $p, $item, $existingPostId, $base_slug, (string) ($item['date'] ?? ''));
                $this->skip_item('Slug bereits vorhanden');
                return;
            }
            $slug = $base_slug;
        } else {
            $slug = $this->unique_slug($db, $p . 'posts', $base_slug, !empty($item['slug']));
        }

        $author_id = $this->resolve_import_author_id($db, $p, $item);
        $authorDisplayName = $this->resolve_import_author_display_name();
        $categories = $this->normalize_tag_names($item['categories'] ?? []);
        $tagNames   = $this->normalize_tag_names($item['tags'] ?? []);
        $categoryId = $this->ensure_category_id($db, $p, (string) ($categories[0] ?? ''));
        $prepared   = $this->prepare_content_payload($db, $p, $item, 'post', $slug);
        $createdAt  = $this->resolve_original_created_at($item);
        $updatedAt  = $this->resolve_original_updated_at($item, $createdAt);
        $publishedAt = $status === 'published' ? $createdAt : null;

        $data = [
            'title'            => $locale === 'de' ? $this->sanitize_title($item['title']) : '',
            'title_en'         => $locale !== 'de' ? $this->sanitize_title((string) ($item['title'] ?? '')) : '',
            'slug'             => $slug,
            'slug_en'          => $locale !== 'de' ? $slug : null,
            'content'          => $locale === 'de' ? $prepared['content'] : '',
            'content_en'       => $locale !== 'de' ? $prepared['content'] : '',
            'excerpt'          => $locale === 'de' ? $prepared['excerpt'] : '',
            'excerpt_en'       => $locale !== 'de' ? $prepared['excerpt'] : '',
            'featured_image'   => $prepared['featured_image'],
            'status'           => $status,
            'author_id'        => $author_id,
            'author_display_name' => $authorDisplayName,
            'category_id'      => $categoryId,
            'tags'             => $this->safe_substr(implode(',', $tagNames), 0, 500),
            'meta_title'       => $this->safe_substr((string) ($item['meta_title'] ?? ''), 0, 255),
            'meta_description' => (string) ($item['meta_description'] ?? ''),
            'created_at'       => $createdAt,
            'updated_at'       => $updatedAt,
            'published_at'     => $publishedAt,
        ];

        try {
            $this->ensure_localized_content_columns($db, $p, 'post');
            $post_id = $db->insert('posts', $data);
            if ($post_id === false) {
                throw new \RuntimeException($db->last_error !== '' ? $db->last_error : 'Insert in posts fehlgeschlagen.');
            }

            $post_id = (int) $post_id;
            $this->imported++;
            $this->import_breakdown[$isCustomType ? 'others' : 'posts']++;

            $this->sync_post_tags($db, $p, $post_id, $tagNames);
            $this->save_seo_meta('post', $post_id, $prepared['seo']);
            $this->store_import_item($db, $p, [
                'log_id'           => $this->log_id,
                'source_type'      => $sourceType,
                'source_wp_id'     => (int) ($item['wp_id'] ?? 0),
                'source_reference' => $sourceReference,
                'source_slug'      => (string) ($item['slug'] ?? ''),
                'source_url'       => (string) ($item['link'] ?? ''),
                'target_type'      => 'post',
                'target_id'        => $post_id,
                'target_created'   => 1,
                'target_slug'      => $slug,
                'target_url'       => $this->build_target_url('post', $slug, $post_id, (string) ($item['date'] ?? ''), $locale),
            ]);
            $this->collect_taxonomy_fallback_meta($item, 'post');
            $this->collect_unknown_meta($item);
            $this->import_comments_for_post($db, $p, $item, $post_id, $slug, (string) ($item['date'] ?? ''), $locale);

        } catch (\Exception $e) {
            $this->errors++;
            error_log('CMS_Importer: Post-Import fehlgeschlagen: ' . $e->getMessage() . ' – Titel: ' . $item['title']);
        }
    }

    private function import_as_page(\CMS\Database $db, string $p, array $item): void
    {
        $status = self::STATUS_MAP[$item['post_status']] ?? 'draft';
        $sourceReference = $this->resolve_content_source_reference($item);
        $locale = $this->resolve_item_locale($item);

        if ($this->should_skip_item_by_locale_filter($item)) {
            $this->skip_item($this->get_locale_filter_skip_reason($item));
            return;
        }

        if ($status === 'trash' && !$this->options['import_trashed']) {
            $this->skip_item('Papierkorb-Elemente deaktiviert');
            return;
        }
        if ($status === 'draft' && !$this->options['import_drafts']) {
            $this->skip_item('Entwürfe deaktiviert');
            return;
        }

        $base_slug = $this->resolve_target_slug($item, (string) ($item['title'] ?? ''));

        if ($locale !== 'de') {
            $localizedTarget = $this->find_preferred_localized_content_target($db, $p, 'page', 'page', $sourceReference, $base_slug);
            if ($localizedTarget !== null) {
                $this->import_into_localized_page($db, $p, $item, $sourceReference, $localizedTarget, $base_slug, $locale);
                return;
            }
        }

        $existingSourceMapping = $this->find_existing_mapping_by_wp_id($db, $p, 'page', (int) ($item['wp_id'] ?? 0), 'page', true);
        if ($this->should_ignore_existing_source_mapping($existingSourceMapping, $base_slug, $sourceReference)) {
            $existingSourceMapping = null;
        }

        if ($this->options['skip_duplicates'] && $existingSourceMapping !== null) {
            if ($locale !== 'de') {
                $this->import_into_localized_page($db, $p, $item, $sourceReference, $existingSourceMapping, $base_slug, $locale);
                return;
            }

            $this->skip_item_comments($item);
            $this->skip_item('Bereits per Import-Mapping vorhanden');
            return;
        }

        if ($this->options['skip_duplicates']) {
            $existingPageId = (int) ($db->get_var(
                "SELECT id FROM {$p}pages WHERE slug = ? ORDER BY id ASC LIMIT 1",
                [$base_slug]
            ) ?? 0);
            if ($existingPageId > 0) {
                if ($locale !== 'de') {
                    $this->import_into_localized_page($db, $p, $item, $sourceReference, [
                        'target_id' => $existingPageId,
                        'target_slug' => $base_slug,
                    ], $base_slug, $locale);
                    return;
                }

                $this->store_import_item($db, $p, [
                    'log_id'           => $this->log_id,
                    'source_type'      => 'page',
                    'source_wp_id'     => (int) ($item['wp_id'] ?? 0),
                    'source_reference' => $sourceReference,
                    'source_slug'      => (string) ($item['slug'] ?? ''),
                    'source_url'       => (string) ($item['link'] ?? ''),
                    'target_type'      => 'page',
                    'target_id'        => $existingPageId,
                    'target_created'   => 0,
                    'target_slug'      => $base_slug,
                    'target_url'       => $this->build_target_url('page', $base_slug, $existingPageId, (string) ($item['date'] ?? ''), $locale),
                ]);
                $this->skip_item_comments($item);
                $this->skip_item('Slug bereits vorhanden');
                return;
            }
            $slug = $base_slug;
        } else {
            $slug = $this->unique_slug($db, $p . 'pages', $base_slug, !empty($item['slug']));
        }

        $author_id = $this->resolve_import_author_id($db, $p, $item);
        $prepared  = $this->prepare_content_payload($db, $p, $item, 'page', $slug);
        $createdAt = $this->resolve_original_created_at($item);
        $updatedAt = $this->resolve_original_updated_at($item, $createdAt);
        $publishedAt = $status === 'published' ? $createdAt : null;

        $data = [
            'slug'         => $slug,
            'slug_en'      => $locale !== 'de' ? $slug : null,
            'title'        => $locale === 'de' ? $this->sanitize_title($item['title']) : '',
            'title_en'     => $locale !== 'de' ? $this->sanitize_title((string) ($item['title'] ?? '')) : '',
            'content'      => $locale === 'de' ? $prepared['content'] : '',
            'content_en'   => $locale !== 'de' ? $prepared['content'] : '',
            'excerpt'      => $locale === 'de' ? $prepared['excerpt'] : '',
            'status'       => $status,
            'hide_title'   => 0,
            'featured_image' => $prepared['featured_image'],
            'meta_title'     => $this->safe_substr((string) ($item['meta_title'] ?? ''), 0, 255),
            'meta_description' => (string) ($item['meta_description'] ?? ''),
            'author_id'    => $author_id,
            'created_at'   => $createdAt,
            'updated_at'   => $updatedAt,
            'published_at' => $publishedAt,
        ];

        try {
            $this->ensure_localized_content_columns($db, $p, 'page');
            $page_id = $db->insert('pages', $data);
            if ($page_id === false) {
                throw new \RuntimeException($db->last_error !== '' ? $db->last_error : 'Insert in pages fehlgeschlagen.');
            }

            $this->imported++;
            $this->import_breakdown['pages']++;
            $this->save_seo_meta('page', (int) $page_id, $prepared['seo']);
            $this->store_import_item($db, $p, [
                'log_id'           => $this->log_id,
                'source_type'      => 'page',
                'source_wp_id'     => (int) ($item['wp_id'] ?? 0),
                'source_reference' => $sourceReference,
                'source_slug'      => (string) ($item['slug'] ?? ''),
                'source_url'       => (string) ($item['link'] ?? ''),
                'target_type'      => 'page',
                'target_id'        => (int) $page_id,
                'target_created'   => 1,
                'target_slug'      => $slug,
                'target_url'       => $this->build_target_url('page', $slug, (int) $page_id, (string) ($item['date'] ?? ''), $locale),
            ]);
            $this->collect_taxonomy_fallback_meta($item, 'page');
            $this->collect_unknown_meta($item);
            $this->skip_item_comments($item);

        } catch (\Exception $e) {
            $this->errors++;
            error_log('CMS_Importer: Page-Import fehlgeschlagen: ' . $e->getMessage() . ' – Titel: ' . $item['title']);
        }
    }

    private function import_comments_for_post(\CMS\Database $db, string $p, array $item, int $targetPostId, string $targetSlug = '', string $targetDate = '', string $targetLocale = 'de'): void
    {
        $comments = $this->get_comment_candidates($item);
        if ($comments === []) {
            return;
        }

        $targetUrl = $targetSlug !== ''
            ? $this->build_target_url('post', $targetSlug, $targetPostId, $targetDate, $targetLocale)
            : null;

        foreach ($comments as $comment) {
            $this->comments_total++;

            if ($targetPostId <= 0) {
                $this->comments_skipped++;
                continue;
            }

            $sourceReference = $this->build_comment_source_reference($item, $comment);
            $existingComment = $this->find_existing_mapping(
                $db,
                $p,
                'wp_comment',
                (int) ($comment['comment_id'] ?? 0),
                $sourceReference,
                'comment',
                true
            );

            if ($existingComment !== null) {
                $this->comments_skipped++;
                continue;
            }

            $content = $this->sanitize_imported_comment_content((string) ($comment['content'] ?? ''));
            if ($content === '') {
                $this->comments_skipped++;
                continue;
            }

            $data = [
                'post_id' => $targetPostId,
                'user_id' => $this->resolve_comment_user_id($db, $p, $comment),
                'author' => $this->sanitize_imported_comment_author((string) ($comment['author'] ?? '')),
                'author_email' => $this->sanitize_imported_comment_email((string) ($comment['author_email'] ?? '')),
                'author_ip' => $this->safe_substr(trim((string) ($comment['author_ip'] ?? '')), 0, 45),
                'content' => $content,
                'status' => $this->normalize_imported_comment_status((string) ($comment['status'] ?? 'pending')),
            ];

            $commentDate = $this->safe_date((string) ($comment['date'] ?? ''));
            if ($commentDate !== null) {
                $data['post_date'] = $commentDate;
            }

            try {
                $commentId = $db->insert('comments', $data);
                if ($commentId === false) {
                    throw new \RuntimeException($db->last_error !== '' ? $db->last_error : 'Insert in comments fehlgeschlagen.');
                }

                $commentId = (int) $commentId;
                $this->comments_imported++;
                $this->import_breakdown['comments']++;

                $this->store_import_item($db, $p, [
                    'log_id' => $this->log_id,
                    'source_type' => 'wp_comment',
                    'source_wp_id' => (int) ($comment['comment_id'] ?? 0),
                    'source_reference' => $sourceReference,
                    'source_slug' => (string) ($item['slug'] ?? ''),
                    'source_url' => (string) ($item['link'] ?? ''),
                    'target_type' => 'comment',
                    'target_id' => $commentId,
                    'target_created' => 1,
                    'target_slug' => null,
                    'target_url' => $targetUrl !== null ? $targetUrl . '#comment-' . $commentId : null,
                ]);
            } catch (\Throwable $e) {
                $this->comments_skipped++;
                $this->errors++;
                error_log('CMS_Importer: Kommentar-Import fehlgeschlagen: ' . $e->getMessage() . ' – Kommentar-ID: ' . (int) ($comment['comment_id'] ?? 0));
            }
        }
    }

    private function skip_item_comments(array $item): void
    {
        $count = count($this->get_comment_candidates($item));
        if ($count <= 0) {
            return;
        }

        $this->comments_total += $count;
        $this->comments_skipped += $count;
    }

    private function import_as_table(\CMS\Database $db, string $p, array $item): void
    {
        $table = $item['table'] ?? null;
        if (!is_array($table) || empty($table['columns']) || !isset($table['rows'])) {
            $this->skip_item('Keine gültige Tabellenstruktur erkannt');
            return;
        }

        $legacyTableId = trim((string) ($item['legacy_table_id'] ?? ''));

        if ($this->options['skip_duplicates']) {
            $existingByMapping = $this->find_existing_mapping($db, $p, 'tablepress_table', (int) ($item['wp_id'] ?? 0), $legacyTableId !== '' ? $legacyTableId : null, 'site_table', true);
            if ($existingByMapping !== null) {
                if ($legacyTableId !== '') {
                    $this->table_reference_map[$legacyTableId] = $existingByMapping['target_id'];
                }
                $this->skip_item('Bereits per Import-Mapping vorhanden');
                return;
            }
        }

        $tableName    = trim((string) ($table['name'] ?? 'Importierte Tabelle'));
        $description  = trim((string) ($table['description'] ?? ''));
        $columnsJson  = json_encode($table['columns'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rowsJson     = json_encode($table['rows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $settingsJson = json_encode($table['settings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tableSlug    = $this->build_unique_table_slug($db, $p, (string) ($table['slug'] ?? $tableName));
        $createdAt    = $this->resolve_original_created_at($item);
        $updatedAt    = $this->resolve_original_updated_at($item, $createdAt);

        if ($this->options['skip_duplicates']) {
            $existingId = $this->find_existing_table_id_by_slug($db, $p, $tableSlug);
            if ($existingId > 0) {
                if ($legacyTableId !== '') {
                    $this->table_reference_map[$legacyTableId] = $existingId;
                    $this->store_import_item($db, $p, [
                        'log_id'           => $this->log_id,
                        'source_type'      => 'tablepress_table',
                        'source_wp_id'     => (int) ($item['wp_id'] ?? 0),
                        'source_reference' => $legacyTableId,
                        'source_slug'      => (string) ($item['slug'] ?? ''),
                        'source_url'       => (string) ($item['link'] ?? ''),
                        'target_type'      => 'site_table',
                        'target_id'        => $existingId,
                        'target_created'   => 0,
                        'target_slug'      => $tableSlug,
                        'target_url'       => '[site-table id="' . $existingId . '"]',
                    ]);
                }
                $this->skip_item('Tabellenslug bereits vorhanden');
                return;
            }
        }

        try {
            $params = [$tableName, $description, $columnsJson ?: '[]', $rowsJson ?: '[]', $settingsJson ?: '{}', $createdAt, $updatedAt];
            $columns = ['table_name', 'description', 'columns_json', 'rows_json', 'settings_json', 'created_at', 'updated_at'];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?'];

            if ($this->has_table_slug_column($db, $p)) {
                $columns[] = 'table_slug';
                $placeholders[] = '?';
                $params[] = $tableSlug;
            }

            $db->execute(
                'INSERT INTO ' . $p . 'site_tables (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
                $params
            );

            $tableId = (int) $db->lastInsertId();
            $this->imported++;
            $this->import_breakdown['tables']++;

            if ($legacyTableId !== '') {
                $this->table_reference_map[$legacyTableId] = $tableId;
            }

            $this->store_import_item($db, $p, [
                'log_id'           => $this->log_id,
                'source_type'      => 'tablepress_table',
                'source_wp_id'     => (int) ($item['wp_id'] ?? 0),
                'source_reference' => $legacyTableId !== '' ? $legacyTableId : null,
                'source_slug'      => (string) ($item['slug'] ?? ''),
                'source_url'       => (string) ($item['link'] ?? ''),
                'target_type'      => 'site_table',
                'target_id'        => $tableId,
                'target_created'   => 1,
                'target_slug'      => $tableSlug,
                'target_url'       => '[site-table id="' . $tableId . '"]',
            ]);

            $this->collect_unknown_meta($item);
        } catch (\Throwable $e) {
            $this->errors++;
            error_log('CMS_Importer: Tabellen-Import fehlgeschlagen: ' . $e->getMessage() . ' – Titel: ' . $tableName);
        }
    }

    private function import_as_redirect(\CMS\Database $db, string $p, array $item): void
    {
        $sourceReference = trim((string) ($item['source_reference'] ?? ''));
        $sourcePath = $this->normalize_import_redirect_source_path((string) ($item['redirect_source'] ?? ''));
        $targetUrl = $this->normalize_import_redirect_target((string) ($item['redirect_target'] ?? ''));
        $comparison = strtolower(trim((string) ($item['redirect_comparison'] ?? 'exact')));
        $redirectType = $this->normalize_import_redirect_type((int) ($item['redirect_type'] ?? 301));
        $isActive = strtolower(trim((string) ($item['status'] ?? 'active'))) === 'active' ? 1 : 0;

        if ($comparison !== 'exact') {
            $this->skip_item('Rank-Math-Regeln mit Vergleich "' . $comparison . '" werden nicht unterstützt');
            return;
        }

        if ($sourcePath === '' || $sourcePath === '/') {
            $this->skip_item('Ungültiger Redirect-Quellpfad');
            return;
        }

        if ($targetUrl === '') {
            $this->skip_item('Ungültiges Redirect-Ziel');
            return;
        }

        $this->ensure_redirect_rule_tables($db, $p);

        $existingRule = $db->get_row(
            "SELECT id, hits FROM {$p}redirect_rules WHERE source_path = ? LIMIT 1",
            [$sourcePath]
        );

        $notes = trim((string) ($item['notes'] ?? 'Importiert aus Rank Math JSON'));
        $hits = max(0, (int) ($item['redirect_hits'] ?? 0));
        $lastHitAt = $this->safe_date((string) ($item['last_accessed'] ?? ''));
        $createdAt = $this->safe_date((string) ($item['date'] ?? ''));
        $updatedAt = $this->safe_date((string) ($item['modified'] ?? ''));

        try {
            if ($existingRule !== null) {
                $db->execute(
                    "UPDATE {$p}redirect_rules
                     SET target_url = ?, redirect_type = ?, is_active = ?, notes = ?, hits = ?, last_hit_at = ?, updated_at = ?
                     WHERE id = ?",
                    [
                        $targetUrl,
                        $redirectType,
                        $isActive,
                        $notes,
                        $hits,
                        $lastHitAt,
                        $updatedAt ?? date('Y-m-d H:i:s'),
                        (int) ($existingRule->id ?? 0),
                    ]
                );

                $ruleId = (int) ($existingRule->id ?? 0);
                $targetCreated = 0;
            } else {
                $ruleId = (int) $db->insert('redirect_rules', [
                    'source_path' => $sourcePath,
                    'target_url' => $targetUrl,
                    'redirect_type' => $redirectType,
                    'is_active' => $isActive,
                    'notes' => $notes,
                    'hits' => $hits,
                    'last_hit_at' => $lastHitAt,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ]);
                $targetCreated = 1;
            }

            if ($ruleId <= 0) {
                throw new \RuntimeException('Redirect-Regel konnte nicht gespeichert werden.');
            }

            $this->store_import_item($db, $p, [
                'log_id' => $this->log_id,
                'source_type' => 'rank_math_redirection',
                'source_wp_id' => null,
                'source_reference' => $sourceReference !== '' ? $sourceReference : $sourcePath,
                'source_slug' => $sourcePath,
                'source_url' => $sourcePath,
                'target_type' => 'redirect',
                'target_id' => $ruleId,
                'target_created' => $targetCreated,
                'target_slug' => $sourcePath,
                'target_url' => $targetUrl,
            ]);

            $this->imported++;
            $this->import_breakdown['redirects']++;
        } catch (\Throwable $e) {
            $this->errors++;
            error_log('CMS_Importer: Redirect-Import fehlgeschlagen: ' . $e->getMessage() . ' – Quelle: ' . $sourcePath);
        }
    }

    /**
     * @param array<string, mixed> $settingsBundle
     */
    private function import_rank_math_seo_settings(\CMS\Database $db, string $p, array $settingsBundle): void
    {
        $settings = is_array($settingsBundle['settings'] ?? null) ? $settingsBundle['settings'] : [];
        if ($settings === []) {
            $this->skip_item('Keine importierbaren SEO-Settings erkannt');
            return;
        }

        $savedCount = 0;
        foreach ($settings as $optionName => $value) {
            if (!is_string($optionName) || !str_starts_with($optionName, 'seo_')) {
                continue;
            }

            try {
                $existing = $db->get_var(
                    "SELECT option_value FROM {$p}settings WHERE option_name = ? LIMIT 1",
                    [$optionName]
                );

                if ($existing === null) {
                    $created = $db->insert('settings', [
                        'option_name' => $optionName,
                        'option_value' => (string) $value,
                        'autoload' => 1,
                    ]);

                    if ($created === false) {
                        throw new \RuntimeException($db->last_error !== '' ? $db->last_error : 'Insert in settings fehlgeschlagen.');
                    }
                } elseif ((string) $existing !== (string) $value) {
                    $updated = $db->update('settings', ['option_value' => (string) $value], ['option_name' => $optionName]);
                    if ($updated === false) {
                        throw new \RuntimeException($db->last_error !== '' ? $db->last_error : 'Update in settings fehlgeschlagen.');
                    }
                }

                $savedCount++;
            } catch (\Throwable $e) {
                $this->errors++;
                error_log('CMS_Importer: SEO-Setting-Import fehlgeschlagen: ' . $e->getMessage() . ' – Option: ' . $optionName);
            }
        }

        if ($savedCount <= 0) {
            $this->skip_item('Rank-Math-SEO-Settings konnten nicht gespeichert werden');
            return;
        }

        $this->settings_imported += $savedCount;
        $this->imported++;
        $this->import_breakdown['settings']++;

        $this->store_import_item($db, $p, [
            'log_id' => $this->log_id,
            'source_type' => 'rank_math_settings',
            'source_wp_id' => null,
            'source_reference' => 'rank_math:seo_settings',
            'source_slug' => 'seo-settings',
            'source_url' => null,
            'target_type' => 'setting_bundle',
            'target_id' => null,
            'target_created' => 1,
            'target_slug' => 'seo-settings',
            'target_url' => null,
        ]);
    }

    // ── Private: Bild-Downloader ──────────────────────────────────────────────

    /**
     * Lädt alle Bilder eines Posts herunter und registriert sie in cms_media.
     * Zielverzeichnis: uploads/images/{slug}/
     *
     * @param  string[] $urls  Absolute Bild-URLs
     * @return string[]        Lokale Dateipfade der erfolgreich geladenen Bilder
     */
    private function download_media_assets(\CMS\Database $db, string $p, string $contextType, string $slug, array $candidates, string $featuredUrl): array
    {
        if (empty($candidates) || !defined('UPLOAD_PATH') || !defined('UPLOAD_URL')) {
            return ['url_map' => [], 'featured_local_url' => ''];
        }

        $dir = rtrim(UPLOAD_PATH, '/') . '/images/importer/' . $contextType . '/' . $this->sanitize_slug($slug) . '/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            error_log('CMS_Importer: Konnte Verzeichnis nicht anlegen: ' . $dir);
            return ['url_map' => [], 'featured_local_url' => ''];
        }

        $relativeDir = 'images/importer/' . $contextType . '/' . $this->sanitize_slug($slug) . '/';
        $urlMap = [];
        $featuredLocalUrl = '';
        $usedNames = [];

        foreach ($candidates as $candidate) {
            $url = trim((string) ($candidate['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            if (!$this->looks_like_image_url($url)) {
                continue;
            }

            $filename   = $this->ensure_unique_filename($this->url_to_filename($url), $url, $usedNames);
            $local_path = $dir . $filename;
            $public_url = rtrim(UPLOAD_URL, '/') . '/' . $relativeDir . $filename;

            if (file_exists($local_path)) {
                $urlMap[$url] = $public_url;
                if ($featuredLocalUrl === '' && $this->urls_match($featuredUrl, $url)) {
                    $featuredLocalUrl = $public_url;
                }
                $this->register_media($db, $p, $local_path, $relativeDir . $filename, $filename, $candidate);
                continue;
            }

            $content = $this->fetch_remote_file($url);
            if ($content === null) {
                continue;
            }

            if (file_put_contents($local_path, $content) === false) {
                continue;
            }

            $urlMap[$url] = $public_url;
            $this->images_downloaded++;
            if ($featuredLocalUrl === '' && $this->urls_match($featuredUrl, $url)) {
                $featuredLocalUrl = $public_url;
            }
            $this->register_media($db, $p, $local_path, $relativeDir . $filename, $filename, $candidate);
        }

        return [
            'url_map' => $urlMap,
            'featured_local_url' => $featuredLocalUrl,
        ];
    }

    /**
     * Lädt eine Remote-Bild-URL über den zentralen Core-HTTP-Client herunter.
     * Erzwingt TLS-Prüfung, SSRF-Schutz, Größenlimit und zulässige Bild-Content-Types.
     */
    private function fetch_remote_file(string $url): ?string
    {
        if (!class_exists('CMS\\Http\\Client')) {
            return null;
        }

        $response = \CMS\Http\Client::getInstance()->get($url, [
            'userAgent' => 'CMS-Importer/' . CMS_IMPORTER_VERSION,
            'timeout' => 20,
            'connectTimeout' => 5,
            'maxBytes' => 10 * 1024 * 1024,
            'allowedContentTypes' => ['image/'],
        ]);

        if (($response['success'] ?? false) !== true) {
            error_log('CMS_Importer: Remote-Dateidownload blockiert oder fehlgeschlagen: ' . (string) ($response['error'] ?? 'Unbekannter Fehler') . ' – URL: ' . $url);
            return null;
        }

        $body = (string) ($response['body'] ?? '');
        return $body !== '' ? $body : null;
    }

    /**
     * Leitet eine URL in einen sicheren Dateinamen um.
     */
    private function url_to_filename(string $url): string
    {
        $path     = parse_url($url, PHP_URL_PATH) ?? '';
        $filename = basename($path);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) ?? 'image';
        $filename = trim($filename, '-');
        return $filename !== '' ? $filename : 'image-' . substr(md5($url), 0, 8);
    }

    /**
     * Registriert eine heruntergeladene Datei in der cms_media-Tabelle.
     */
    private function register_media(\CMS\Database $db, string $p, string $local_path, string $relativePath, string $filename, array $candidate): void
    {
        try {
            $existingId = $db->get_var(
                "SELECT id FROM {$p}media WHERE filepath = ? LIMIT 1",
                [$relativePath]
            );
            if ($existingId !== null) {
                return;
            }

            $db->insert('media', [
                'filename'    => $filename,
                'filepath'    => $relativePath,
                'filetype'    => mime_content_type($local_path) ?: 'image/jpeg',
                'filesize'    => (int) (filesize($local_path) ?: 0),
                'title'       => $this->safe_substr((string) (($candidate['title'] ?? '') !== '' ? $candidate['title'] : pathinfo($filename, PATHINFO_FILENAME)), 0, 255),
                'alt_text'    => $this->safe_substr((string) ($candidate['alt'] ?? ''), 0, 255),
                'caption'     => (string) ($candidate['caption'] ?? ''),
                'uploaded_by' => 0,
            ]);
        } catch (\Exception $e) {
            error_log('CMS_Importer: Media-Registrierung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    // ── Private: Meta-Handling ────────────────────────────────────────────────

    private function collect_unknown_meta(array $item): void
    {
        if (empty($item['meta'])) {
            return;
        }

        $mappedKeys = array_fill_keys($item['mapped_meta_keys'] ?? [], true);
        foreach ($item['meta'] as $key => $value) {
            if (isset($mappedKeys[$key])) {
                continue;
            }
            $this->unknown_meta[] = [
                'source_id'  => (string) $item['wp_id'],
                'post_title' => $this->safe_substr($item['title'], 0, 255),
                'post_type'  => $item['post_type'],
                'meta_key'   => $key,
                'meta_value' => $value,
            ];
        }
    }

    private function store_unknown_meta(\CMS\Database $db, string $p): void
    {
        if (empty($this->unknown_meta) || $this->log_id === 0) {
            return;
        }
        foreach ($this->unknown_meta as $row) {
            try {
                // WICHTIG: insert() fügt intern den Prefix hinzu → KEIN Prefix übergeben!
                $db->insert('import_meta', array_merge(['log_id' => $this->log_id], $row));
            } catch (\Exception $e) {
                error_log('CMS_Importer: Meta-Speicherung fehlgeschlagen: ' . $e->getMessage());
            }
        }
    }

    // ── Private: Markdown-Bericht ─────────────────────────────────────────────

    private function generate_meta_report(array $site_info): string
    {
        if (empty($this->unknown_meta)) {
            return '';
        }

        $report_dir = CMS_IMPORTER_PLUGIN_DIR . 'reports/';
        if (!is_dir($report_dir)) {
            mkdir($report_dir, 0755, true);
        }

        $safe_name   = preg_replace('/[^a-z0-9_-]/', '_', strtolower(pathinfo($this->filename, PATHINFO_FILENAME)));
        $report_base = $report_dir . date('Y-m-d_His') . '_' . $safe_name . '_meta-report';
        $report_file = $report_base . '.md';
        $html_file   = $report_base . '.html';

        $grouped = [];
        foreach ($this->unknown_meta as $row) {
            $key = $row['meta_key'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['count' => 0, 'examples' => []];
            }
            $grouped[$key]['count']++;
            if (count($grouped[$key]['examples']) < 3) {
                $grouped[$key]['examples'][] = [
                    'source_id'  => $row['source_id'],
                    'post_title' => $row['post_title'],
                    'post_type'  => $row['post_type'],
                    'value'      => $this->safe_substr((string) $row['meta_value'], 0, 200),
                ];
            }
        }

        ksort($grouped);

        $md  = "# WordPress-Import – Unbekannte Meta-Felder\n\n";
        $md .= "> **Import-Datei:** `{$this->filename}`  \n";
        $md .= "> **Erstellt:** " . date('d.m.Y H:i:s') . "  \n";
        $md .= "> **Quelle:** " . htmlspecialchars($site_info['title'] ?? 'Unbekannt') . " (`" . ($site_info['base_site_url'] ?? '') . "`)  \n";
        $md .= "> **Anzahl unbekannter Keys:** " . count($grouped) . "  \n";
        $md .= "> **Gesamte Meta-Einträge:** " . count($this->unknown_meta) . "  \n\n";
        $md .= "---\n\n";
        $md .= "## Hinweis\n\n";
        $md .= "Die folgenden Meta-Keys aus dem WordPress-Export konnten **nicht automatisch** auf ein CMS-Datenbankfeld gemappt werden. ";
        $md .= "Sie wurden trotzdem in der Tabelle `cms_import_meta` gespeichert und können manuell nachverarbeitet werden.\n\n";
        $md .= "---\n\n";
        $md .= "## Übersicht aller unbekannten Meta-Keys\n\n";
        $md .= "| # | Meta-Key | Anzahl | Hinweis |\n";
        $md .= "|---|----------|--------|---------|\n";

        $i = 1;
        foreach ($grouped as $key => $info) {
            $hint = $this->get_meta_hint($key);
            $md  .= "| {$i} | `{$key}` | {$info['count']} | {$hint} |\n";
            $i++;
        }

        $md .= "\n---\n\n";
        $md .= "## Details der unbekannten Meta-Keys\n\n";

        foreach ($grouped as $key => $info) {
            $hint = $this->get_meta_hint($key);
            $md  .= "### `{$key}`\n\n";
            $md  .= "- **Vorkommen:** {$info['count']}\n";
            $md  .= "- **Hinweis:** {$hint}\n";
            $md  .= "- **Beispielwerte:**\n\n";
            foreach ($info['examples'] as $ex) {
                $value = str_replace(['|', "\n", "\r"], [' &#124; ', ' ', ''], $ex['value']);
                $md   .= "  - Post `{$ex['source_id']}` (**{$ex['post_title']}**, Typ: `{$ex['post_type']}`):  \n";
                $md   .= "    `{$value}`\n";
            }
            $md .= "\n";
        }

        $md .= "---\n\n";
        $md .= "*Automatisch generiert vom CMS WordPress Importer v" . CMS_IMPORTER_VERSION . "*\n";

        file_put_contents($report_file, $md);
        file_put_contents($html_file, $this->build_meta_report_html($site_info, $grouped, $report_file));
        return $report_file;
    }

    private function build_meta_report_html(array $site_info, array $grouped, string $markdownPath): string
    {
        $title = htmlspecialchars((string) ($site_info['title'] ?? 'Unbekannt'), ENT_QUOTES, 'UTF-8');
        $siteUrl = htmlspecialchars((string) ($site_info['base_site_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $generated = htmlspecialchars(date('d.m.Y H:i:s'), ENT_QUOTES, 'UTF-8');
        $markdownName = htmlspecialchars(basename($markdownPath), ENT_QUOTES, 'UTF-8');

        $rows = '';
        foreach ($grouped as $key => $info) {
            $rows .= '<tr>'
                . '<td><code>' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '</code></td>'
                . '<td>' . (int) ($info['count'] ?? 0) . '</td>'
                . '<td>' . htmlspecialchars($this->get_meta_hint((string) $key), ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }

        $details = '';
        foreach ($grouped as $key => $info) {
            $details .= '<section class="report-section">';
            $details .= '<h2><code>' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '</code></h2>';
            $details .= '<p><strong>Vorkommen:</strong> ' . (int) ($info['count'] ?? 0) . '<br>';
            $details .= '<strong>Hinweis:</strong> ' . htmlspecialchars($this->get_meta_hint((string) $key), ENT_QUOTES, 'UTF-8') . '</p>';
            $details .= '<ul>';

            foreach (($info['examples'] ?? []) as $example) {
                $details .= '<li><strong>Post ' . htmlspecialchars((string) ($example['source_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong>'
                    . ' (' . htmlspecialchars((string) ($example['post_title'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . ', Typ: ' . htmlspecialchars((string) ($example['post_type'] ?? ''), ENT_QUOTES, 'UTF-8') . ')<br>'
                    . '<code>' . htmlspecialchars((string) ($example['value'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code></li>';
            }

            $details .= '</ul></section>';
        }

        return '<!DOCTYPE html>'
            . '<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>WordPress-Import Meta-Bericht</title>'
            . '<style>'
            . 'body{font-family:Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#1f2937;margin:0;padding:32px;line-height:1.5}'
            . '.wrap{max-width:1100px;margin:0 auto;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.08);padding:32px}'
            . 'h1,h2{margin:0 0 12px}h1{font-size:28px}h2{font-size:20px;margin-top:28px}'
            . '.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:24px 0}'
            . '.meta div{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px}'
            . 'table{width:100%;border-collapse:collapse;margin-top:16px}th,td{padding:12px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}'
            . 'th{background:#f8fafc}code{background:#f3f4f6;padding:2px 6px;border-radius:6px}'
            . '.report-section{border-top:1px solid #e5e7eb;padding-top:20px;margin-top:20px}.footer{margin-top:32px;color:#6b7280;font-size:14px}'
            . '</style></head><body><div class="wrap">'
            . '<h1>WordPress-Import – Meta-Bericht</h1>'
            . '<p>Lesbare HTML-Version des Import-Berichts. Die Rohfassung liegt zusätzlich als <code>' . $markdownName . '</code> vor.</p>'
            . '<div class="meta">'
            . '<div><strong>Quelle</strong><br>' . $title . '</div>'
            . '<div><strong>Website</strong><br>' . $siteUrl . '</div>'
            . '<div><strong>Erstellt</strong><br>' . $generated . '</div>'
            . '<div><strong>Unbekannte Keys</strong><br>' . count($grouped) . '</div>'
            . '</div>'
            . '<table><thead><tr><th>Meta-Key</th><th>Anzahl</th><th>Hinweis</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . $details
            . '<div class="footer">Automatisch generiert vom CMS WordPress Importer v' . htmlspecialchars((string) CMS_IMPORTER_VERSION, ENT_QUOTES, 'UTF-8') . '</div>'
            . '</div></body></html>';
    }

    private function get_meta_hint(string $key): string
    {
        $hints = [
            'rank_math_seo_score'                => 'Rank Math SEO-Score (0–100)',
            'rank_math_focus_keyword'            => 'Rank Math Fokus-Keyword',
            'rank_math_canonical_url'            => 'Rank Math Canonical-URL',
            'rank_math_og_content_image'         => 'Rank Math Open-Graph-Bild',
            'rank_math_internal_links_processed' => 'Rank Math interne Verlinkung (Technik)',
            'rank_math_analytic_object_id'       => 'Rank Math Analytics-ID (Technik)',
            '_yoast_wpseo_focuskw'               => 'Yoast SEO Fokus-Keyword',
            '_yoast_wpseo_canonical'             => 'Yoast SEO Canonical-URL',
            '_yoast_wpseo_opengraph-image'       => 'Yoast SEO Open-Graph-Bild',
            '_yoast_wpseo_schema_page_type'      => 'Yoast SEO Schema-Seitentyp',
            '_yoast_wpseo_schema_article_type'   => 'Yoast SEO Schema-Artikeltyp',
            '_wp_page_template'                  => 'WordPress Seitentemplate-Zuweisung',
            'cmplz_hide_cookiebanner'            => 'Complianz – Cookie-Banner ausblenden',
            'litespeed_vpi_list'                 => 'LiteSpeed Cache VPI-Liste (Technik)',
            '_lwpgls_synonyms'                   => 'Lightweight Glossary – Synonyme',
            '_wpml_word_count'                   => 'WPML Wortanzahl (Technik)',
            '_wpml_media_featured'               => 'WPML Medien Featured (Technik)',
        ];

        if (str_starts_with($key, '_yoast_')) {
            return $hints[$key] ?? 'Yoast SEO Plugin – kein direktes CMS-Äquivalent';
        }
        if (str_starts_with($key, 'rank_math_')) {
            return $hints[$key] ?? 'Rank Math SEO Plugin – kein direktes CMS-Äquivalent';
        }
        if (str_starts_with($key, '_wpml_')) {
            return $hints[$key] ?? 'WPML Mehrsprachigkeit – kein direktes CMS-Äquivalent';
        }
        if (str_starts_with($key, 'litespeed_')) {
            return $hints[$key] ?? 'LiteSpeed Cache – Technik-Metadaten (kann ignoriert werden)';
        }

        return $hints[$key] ?? '—';
    }

    // ── Private: Log-Verwaltung ───────────────────────────────────────────────

    private function create_log_entry(\CMS\Database $db, string $p, string $filename, int $user_id): int
    {
        try {
            $db->insert('import_log', [
                'filename'    => $filename,
                'import_type' => 'mixed',
                'total'       => 0,
                'imported'    => 0,
                'skipped'     => 0,
                'errors'      => 0,
                'user_id'     => $user_id > 0 ? $user_id : null,
            ]);
            return $db->insert_id();
        } catch (\Exception $e) {
            error_log('CMS_Importer: Log-Eintrag konnte nicht erstellt werden: ' . $e->getMessage());
            return 0;
        }
    }

    private function finalize_log(\CMS\Database $db, string $p, string $report_path): void
    {
        if ($this->log_id === 0) {
            return;
        }
        try {
            $db->execute(
                "UPDATE {$p}import_log
                 SET total = ?, imported = ?, skipped = ?, errors = ?,
                     images_downloaded = ?, meta_report_path = ?, finished_at = NOW()
                 WHERE id = ?",
                [
                    $this->total,
                    $this->imported,
                    $this->skipped,
                    $this->errors,
                    $this->images_downloaded,
                    $report_path !== '' ? $report_path : null,
                    $this->log_id,
                ]
            );
        } catch (\Exception $e) {
            error_log('CMS_Importer: Log-Update fehlgeschlagen: ' . $e->getMessage());
        }
    }

    // ── Private: Hilfsmethoden ────────────────────────────────────────────────

    private function reset_counters(): void
    {
        $this->total             = 0;
        $this->imported          = 0;
        $this->skipped           = 0;
        $this->errors            = 0;
        $this->images_downloaded = 0;
        $this->comments_total    = 0;
        $this->comments_imported = 0;
        $this->comments_skipped  = 0;
        $this->settings_imported = 0;
        $this->unknown_meta      = [];
        $this->skip_reasons      = [];
        $this->log_id            = 0;
        $this->table_reference_map = [];
        $this->import_breakdown = [
            'settings' => 0,
            'posts'  => 0,
            'pages'  => 0,
            'tables' => 0,
            'redirects' => 0,
            'comments' => 0,
            'others' => 0,
        ];
    }

    private function skip_item(string $reason): void
    {
        $reason = $this->normalize_skip_reason($reason);
        $this->skipped++;
        $this->skip_reasons[$reason] = (int) ($this->skip_reasons[$reason] ?? 0) + 1;
    }

    private function normalize_skip_reason(?string $reason): string
    {
        $reason = trim((string) $reason);
        return $reason !== '' ? $reason : 'Unbekannter Überspring-Grund';
    }

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

    /**
     * @return array<string, mixed>|null
     */
    private function build_gallery_block(\DOMElement $element): ?array
    {
        $urls = array_values($this->collect_image_urls_from_element($element));
        if ($urls === []) {
            return null;
        }

        if (count($urls) === 1) {
            $img = $this->find_first_descendant_tag($element, 'img');
            return $img instanceof \DOMElement ? $this->build_image_block($img, $this->extract_caption_from_element($element)) : null;
        }

        return [
            'type' => 'imageGallery',
            'data' => [
                'urls' => $urls,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extract_gallery_candidate_urls(\DOMElement $element): array
    {
        if ($this->is_gallery_element($element)) {
            return array_values($this->collect_image_urls_from_element($element));
        }

        $tag = strtolower($element->tagName);
        if (!in_array($tag, ['figure', 'div', 'p'], true)) {
            return [];
        }

        $urls = array_values($this->collect_image_urls_from_element($element));
        if ($urls === []) {
            return [];
        }

        $remainingText = trim(strip_tags($this->get_inner_html_without_media($element)));
        if ($remainingText !== '') {
            return [];
        }

        return $urls;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_embed_block_from_url(string $url, string $caption = ''): ?array
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return [
            'type' => 'embed',
            'data' => [
                'service' => '',
                'source' => $url,
                'embed' => $url,
                'caption' => trim($caption),
                'width' => 640,
                'height' => 360,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_container_block(\DOMElement $element): ?array
    {
        if ($this->is_gallery_element($element)) {
            return $this->build_gallery_block($element);
        }

        $iframe = $this->find_first_descendant_tag($element, 'iframe');
        if ($iframe instanceof \DOMElement && count($this->collect_non_empty_child_elements($element)) <= 2) {
            return $this->build_embed_block_from_url(
                (string) $iframe->getAttribute('src'),
                $this->extract_caption_from_element($element)
            );
        }

        $images = $this->collect_image_urls_from_element($element);
        if (count($images) > 1 && trim(strip_tags($this->get_inner_html_without_media($element))) === '') {
            return [
                'type' => 'imageGallery',
                'data' => ['urls' => array_values($images)],
            ];
        }

        if ($this->has_meaningful_nested_blocks($element)) {
            $nested = $this->convert_dom_nodes_to_editorjs_blocks($element->childNodes);
            return count($nested) === 1 ? $nested[0] : $this->build_raw_block($this->get_outer_html($element));
        }

        return $this->build_fallback_block($element);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build_fallback_block(\DOMElement $element): ?array
    {
        $html = trim($this->get_outer_html($element));
        if ($html === '') {
            return null;
        }

        return $this->build_raw_block($html);
    }

    /**
     * @return array<string, mixed>
     */
    private function build_raw_block(string $html): array
    {
        return [
            'type' => 'raw',
            'data' => [
                'html' => $html,
            ],
        ];
    }

    private function is_gallery_element(\DOMElement $element): bool
    {
        $class = ' ' . strtolower(trim((string) $element->getAttribute('class'))) . ' ';
        if (
            str_contains($class, ' wp-block-gallery ')
            || str_contains($class, ' blocks-gallery-grid ')
            || str_contains($class, ' gallery ')
            || str_contains($class, ' gallery-grid ')
            || str_contains($class, ' tiled-gallery ')
        ) {
            return count($this->collect_image_urls_from_element($element)) > 0;
        }

        $tag = strtolower($element->tagName);
        return in_array($tag, ['figure', 'div'], true) && count($this->collect_image_urls_from_element($element)) > 1;
    }

    /**
     * @return array<int, string>
     */
    private function collect_image_urls_from_element(\DOMElement $element): array
    {
        $urls = [];
        foreach ($element->getElementsByTagName('img') as $img) {
            if (!$img instanceof \DOMElement) {
                continue;
            }

            $url = trim((string) $img->getAttribute('src'));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $urls[$url] = $url;
        }

        return array_values($urls);
    }

    private function extract_caption_from_element(\DOMElement $element): string
    {
        foreach ($element->getElementsByTagName('figcaption') as $caption) {
            if ($caption instanceof \DOMElement) {
                return trim($this->get_inner_html($caption));
            }
        }

        return '';
    }

    private function get_inner_html(\DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $this->serialize_node_html($child);
        }

        return $html;
    }

    private function get_outer_html(\DOMNode $node): string
    {
        return $this->serialize_node_html($node);
    }

    private function serialize_node_html(?\DOMNode $node): string
    {
        if (!$node instanceof \DOMNode || !$node->ownerDocument instanceof \DOMDocument) {
            return '';
        }

        return $node->ownerDocument->saveHTML($node) ?: '';
    }

    private function get_inner_html_without_media(\DOMElement $element): string
    {
        $clone = $element->cloneNode(true);
        if (!$clone instanceof \DOMElement) {
            return '';
        }

        $tagsToRemove = ['img', 'figure', 'iframe', 'video', 'audio'];
        foreach ($tagsToRemove as $tag) {
            while (true) {
                $nodes = $clone->getElementsByTagName($tag);
                $target = $nodes->item(0);
                if (!$target instanceof \DOMNode || !$target->parentNode instanceof \DOMNode) {
                    break;
                }
                $target->parentNode->removeChild($target);
            }
        }

        return $this->get_inner_html($clone);
    }

    private function find_first_descendant_tag(\DOMElement $element, string $tagName): ?\DOMElement
    {
        foreach ($element->getElementsByTagName($tagName) as $node) {
            if ($node instanceof \DOMElement) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return array<int, \DOMElement>
     */
    private function collect_non_empty_child_elements(\DOMElement $element): array
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            if (trim(strip_tags($this->get_outer_html($child))) === '' && !$this->find_first_descendant_tag($child, 'img') instanceof \DOMElement && !$this->find_first_descendant_tag($child, 'iframe') instanceof \DOMElement) {
                continue;
            }

            $children[] = $child;
        }

        return $children;
    }

    private function has_meaningful_nested_blocks(\DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            if (in_array(strtolower($child->tagName), ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'pre', 'table', 'figure'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve_single_file_link(\DOMElement $element): ?array
    {
        $anchors = $element->getElementsByTagName('a');
        if ($anchors->length !== 1) {
            return null;
        }

        $anchor = $anchors->item(0);
        if (!$anchor instanceof \DOMElement) {
            return null;
        }

        $href = trim((string) $anchor->getAttribute('href'));
        if ($href === '' || !filter_var($href, FILTER_VALIDATE_URL)) {
            return null;
        }

        $path = strtolower((string) parse_url($href, PHP_URL_PATH));
        if (!preg_match('/\.(pdf|docx?|xlsx?|pptx?|zip|rar|txt)$/i', $path)) {
            return null;
        }

        $name = trim($anchor->textContent ?? '') ?: basename($path);

        return [
            'type' => 'attaches',
            'data' => [
                'file' => [
                    'url' => $href,
                    'name' => $name,
                    'size' => 0,
                ],
                'title' => $name,
            ],
        ];
    }

    private function collect_media_candidates(array $item, string $featuredImage): array
    {
        $candidates = [];

        if ($featuredImage !== '') {
            $meta = $this->find_attachment_meta_for_url($featuredImage, (int) ($item['featured_image_wp_id'] ?? 0));
            $candidates[] = [
                'url' => $featuredImage,
                'alt' => $meta['alt_text'] ?? '',
                'caption' => $meta['caption'] ?? '',
                'title' => $meta['title'] ?? '',
            ];
        }

        foreach ($item['image_urls'] ?? [] as $url) {
            $meta = $this->find_attachment_meta_for_url((string) $url, 0);
            $candidates[] = [
                'url' => (string) $url,
                'alt' => $meta['alt_text'] ?? '',
                'caption' => $meta['caption'] ?? '',
                'title' => $meta['title'] ?? '',
            ];
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $key = $this->normalize_url_key((string) ($candidate['url'] ?? ''));
            if ($key === '' || isset($unique[$key])) {
                continue;
            }
            $unique[$key] = $candidate;
        }

        return array_values($unique);
    }

    private function find_attachment_meta_for_url(string $url, int $attachmentId): array
    {
        if ($attachmentId > 0 && isset($this->attachment_lookup[$attachmentId]) && is_array($this->attachment_lookup[$attachmentId])) {
            return $this->attachment_lookup[$attachmentId];
        }

        $key = $this->normalize_url_key($url);
        return $key !== '' && isset($this->attachment_by_url[$key]) && is_array($this->attachment_by_url[$key])
            ? $this->attachment_by_url[$key]
            : [];
    }

    private function index_attachments_by_url(array $attachments): array
    {
        $indexed = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $key = $this->normalize_url_key((string) ($attachment['url'] ?? ''));
            if ($key === '') {
                continue;
            }
            $indexed[$key] = $attachment;
        }

        return $indexed;
    }

    private function normalize_url_key(string $url): string
    {
        return trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function rewrite_url_map(string $content, array $urlMap): string
    {
        if ($content === '' || $urlMap === []) {
            return $content;
        }

        foreach ($urlMap as $sourceUrl => $targetUrl) {
            $content = str_replace($sourceUrl, $targetUrl, $content);
        }

        return $content;
    }

    private function resolve_featured_image(string $featuredImage, array $urlMap, string $featuredLocalUrl): string
    {
        if ($featuredLocalUrl !== '') {
            return $featuredLocalUrl;
        }

        if ($featuredImage !== '' && isset($urlMap[$featuredImage])) {
            return (string) $urlMap[$featuredImage];
        }

        return $featuredImage;
    }

    private function rewrite_seo_image_urls(array $seo, array $urlMap, string $featuredImage): array
    {
        foreach (['og_image', 'twitter_image'] as $key) {
            $value = trim((string) ($seo[$key] ?? ''));
            if ($value !== '' && isset($urlMap[$value])) {
                $seo[$key] = $urlMap[$value];
            }
        }

        if (trim((string) ($seo['og_image'] ?? '')) === '' && $featuredImage !== '') {
            $seo['og_image'] = $featuredImage;
        }

        if (trim((string) ($seo['twitter_image'] ?? '')) === '' && $featuredImage !== '') {
            $seo['twitter_image'] = $featuredImage;
        }

        return $seo;
    }

    private function replace_table_shortcodes(\CMS\Database $db, string $p, string $content): string
    {
        if ($content === '' || !preg_match('/\[(?:table|tablepress)\s+id\s*=\s*["\']?(\d+)["\']?\s*\/?\]/i', $content)) {
            return $content;
        }

        return (string) preg_replace_callback(
            '/\[(?:table|tablepress)\s+id\s*=\s*["\']?(\d+)["\']?\s*\/?\]/i',
            function (array $matches) use ($db, $p): string {
                $legacyId = (string) ($matches[1] ?? '');
                if ($legacyId === '') {
                    return (string) ($matches[0] ?? '');
                }

                $tableId = $this->resolve_site_table_id($db, $p, $legacyId);
                return $tableId > 0 ? '[site-table id="' . $tableId . '"]' : (string) ($matches[0] ?? '');
            },
            $content
        );
    }

    private function resolve_site_table_id(\CMS\Database $db, string $p, string $legacyId): int
    {
        if (isset($this->table_reference_map[$legacyId])) {
            return (int) $this->table_reference_map[$legacyId];
        }

        $targetId = $db->get_var(
            "SELECT target_id
             FROM {$p}import_items
             WHERE source_type = ?
               AND source_reference = ?
               AND target_type = ?
             ORDER BY id DESC
             LIMIT 1",
            ['tablepress_table', $legacyId, 'site_table']
        );

        if ($targetId === null) {
            $targetId = $db->get_var(
                "SELECT target_id
                 FROM {$p}import_items
                 WHERE source_type = ?
                   AND source_wp_id = ?
                   AND target_type = ?
                 ORDER BY id DESC
                 LIMIT 1",
                ['tablepress_table', (int) $legacyId, 'site_table']
            );
        }

        $resolved = (int) ($targetId ?? 0);
        if ($resolved > 0) {
            $this->table_reference_map[$legacyId] = $resolved;
        }

        return $resolved;
    }

    private function find_existing_mapping(\CMS\Database $db, string $p, string $sourceType, int $sourceWpId, ?string $sourceReference, string $targetType, bool $cleanupStale = false): ?array
    {
        if ($sourceWpId > 0) {
            $mapping = $this->find_existing_mapping_by_wp_id($db, $p, $sourceType, $sourceWpId, $targetType, $cleanupStale);
            if ($mapping !== null) {
                return $mapping;
            }
        }

        if ($sourceReference !== null && $sourceReference !== '') {
            $mapping = $this->find_existing_mapping_by_reference($db, $p, $sourceType, $sourceReference, $targetType, $cleanupStale);
            if ($mapping !== null) {
                return $mapping;
            }
        }

        return null;
    }

    private function find_existing_mapping_by_wp_id(\CMS\Database $db, string $p, string $sourceType, int $sourceWpId, string $targetType, bool $cleanupStale = false): ?array
    {
        if ($sourceWpId <= 0) {
            return null;
        }

        return $this->resolve_existing_mapping_rows($db, $p, $db->get_results(
            "SELECT id, target_id, target_slug
             FROM {$p}import_items
             WHERE source_type = ? AND source_wp_id = ? AND target_type = ?
             ORDER BY id DESC",
            [$sourceType, $sourceWpId, $targetType]
        ), $targetType, $cleanupStale);
    }

    private function find_existing_mapping_by_reference(\CMS\Database $db, string $p, string $sourceType, string $sourceReference, string $targetType, bool $cleanupStale = false): ?array
    {
        if ($sourceReference === '') {
            return null;
        }

        return $this->resolve_existing_mapping_rows($db, $p, $db->get_results(
            "SELECT id, target_id, target_slug
             FROM {$p}import_items
             WHERE source_type = ? AND source_reference = ? AND target_type = ?
             ORDER BY id DESC",
            [$sourceType, $sourceReference, $targetType]
        ), $targetType, $cleanupStale);
    }

    /**
     * @param array<int, object> $rows
     * @return array{target_id:int,target_slug:string}|null
     */
    private function resolve_existing_mapping_rows(\CMS\Database $db, string $p, array $rows, string $targetType, bool $cleanupStale): ?array
    {
        foreach ($rows as $row) {
            $resolved = $this->resolve_mapping_target($db, $p, $targetType, (int) ($row->target_id ?? 0), (string) ($row->target_slug ?? ''));
            if ($resolved !== null) {
                if (
                    $cleanupStale
                    && ((int) ($row->target_id ?? 0) !== $resolved['target_id']
                    || (string) ($row->target_slug ?? '') !== $resolved['target_slug'])
                ) {
                    $db->update('import_items', [
                        'target_id' => $resolved['target_id'],
                        'target_slug' => $resolved['target_slug'],
                    ], [
                        'id' => (int) ($row->id ?? 0),
                    ]);
                }

                return $resolved;
            }

            if ($cleanupStale && !empty($row->id)) {
                $db->delete('import_items', ['id' => (int) $row->id]);
            }
        }

        return null;
    }

    /**
     * @return array{target_id:int,target_slug:string}|null
     */
    private function resolve_mapping_target(\CMS\Database $db, string $p, string $targetType, int $targetId, string $targetSlug): ?array
    {
        return match ($targetType) {
            'post' => $this->resolve_content_target($db, $p . 'posts', 'slug', $targetId, $targetSlug, 'slug_en'),
            'page' => $this->resolve_content_target($db, $p . 'pages', 'slug', $targetId, $targetSlug, 'slug_en'),
            'comment' => $this->resolve_comment_target($db, $p, $targetId),
            'site_table' => $this->resolve_site_table_target($db, $p, $targetId, $targetSlug),
            'redirect' => $this->resolve_redirect_target($db, $p, $targetId, $targetSlug),
            default => null,
        };
    }

    /**
     * @return array{target_id:int,target_slug:string}|null
     */
    private function resolve_content_target(\CMS\Database $db, string $table, string $slugColumn, int $targetId, string $targetSlug, ?string $localizedSlugColumn = null): ?array
    {
        $localizedSlugColumn = $this->normalize_optional_slug_column($db, $table, $localizedSlugColumn);
        $slugSelect = $this->build_content_target_slug_select($slugColumn, $localizedSlugColumn);

        if ($targetId > 0) {
            $row = $db->get_row(
                "SELECT {$slugSelect} FROM {$table} WHERE id = ? LIMIT 1",
                [$targetId]
            );
            if ($row !== null) {
                $resolvedTargetSlug = $this->resolve_content_target_slug_from_row($row, $slugColumn, $localizedSlugColumn, $targetSlug);
                return [
                    'target_id' => (int) ($row->id ?? 0),
                    'target_slug' => $resolvedTargetSlug,
                ];
            }
        }

        if ($targetSlug !== '') {
            $query = "SELECT {$slugSelect} FROM {$table} WHERE {$slugColumn} = ?";
            $params = [$targetSlug];

            if ($localizedSlugColumn !== null) {
                $query .= " OR {$localizedSlugColumn} = ?";
                $params[] = $targetSlug;
            }

            $query .= ' LIMIT 1';

            $row = $db->get_row($query, $params);
            if ($row !== null) {
                $resolvedTargetSlug = $this->resolve_content_target_slug_from_row($row, $slugColumn, $localizedSlugColumn, $targetSlug);
                return [
                    'target_id' => (int) ($row->id ?? 0),
                    'target_slug' => $resolvedTargetSlug,
                ];
            }
        }

        return null;
    }

    private function normalize_optional_slug_column(\CMS\Database $db, string $table, ?string $localizedSlugColumn): ?string
    {
        $localizedSlugColumn = trim((string) $localizedSlugColumn);
        if ($localizedSlugColumn === '') {
            return null;
        }

        try {
            $stmt = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$localizedSlugColumn}'");
            if ($stmt instanceof \PDOStatement && $stmt->fetch()) {
                return $localizedSlugColumn;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function build_content_target_slug_select(string $slugColumn, ?string $localizedSlugColumn = null): string
    {
        $select = "id, {$slugColumn} AS target_slug";

        if ($localizedSlugColumn !== null) {
            $select .= ", {$localizedSlugColumn} AS target_localized_slug";
        }

        return $select;
    }

    private function resolve_content_target_slug_from_row(object $row, string $slugColumn, ?string $localizedSlugColumn, string $desiredSlug = ''): string
    {
        $primarySlug = trim((string) ($row->target_slug ?? $row->{$slugColumn} ?? ''));
        $localizedSlug = $localizedSlugColumn !== null
            ? trim((string) ($row->target_localized_slug ?? $row->{$localizedSlugColumn} ?? ''))
            : '';
        $desiredSlug = trim($desiredSlug);

        if ($desiredSlug !== '') {
            if ($localizedSlug !== '' && strcasecmp($localizedSlug, $desiredSlug) === 0) {
                return $localizedSlug;
            }

            if ($primarySlug !== '' && strcasecmp($primarySlug, $desiredSlug) === 0) {
                return $primarySlug;
            }
        }

        if ($primarySlug !== '') {
            return $primarySlug;
        }

        return $localizedSlug;
    }

    /**
     * @return array{target_id:int,target_slug:string}|null
     */
    private function resolve_site_table_target(\CMS\Database $db, string $p, int $targetId, string $targetSlug): ?array
    {
        if ($targetId > 0) {
            $row = $db->get_row(
                "SELECT id FROM {$p}site_tables WHERE id = ? LIMIT 1",
                [$targetId]
            );
            if ($row !== null) {
                return [
                    'target_id' => (int) ($row->id ?? 0),
                    'target_slug' => $targetSlug,
                ];
            }
        }

        if ($targetSlug !== '' && $this->has_table_slug_column($db, $p)) {
            $row = $db->get_row(
                "SELECT id, table_slug AS target_slug FROM {$p}site_tables WHERE table_slug = ? LIMIT 1",
                [$targetSlug]
            );
            if ($row !== null) {
                return [
                    'target_id' => (int) ($row->id ?? 0),
                    'target_slug' => (string) ($row->target_slug ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * @return array{target_id:int,target_slug:string}|null
     */
    private function resolve_redirect_target(\CMS\Database $db, string $p, int $targetId, string $targetSlug): ?array
    {
        if ($targetId > 0) {
            $row = $db->get_row(
                "SELECT id, source_path AS target_slug FROM {$p}redirect_rules WHERE id = ? LIMIT 1",
                [$targetId]
            );
            if ($row !== null) {
                return [
                    'target_id' => (int) ($row->id ?? 0),
                    'target_slug' => (string) ($row->target_slug ?? ''),
                ];
            }
        }

        if ($targetSlug !== '') {
            $row = $db->get_row(
                "SELECT id, source_path AS target_slug FROM {$p}redirect_rules WHERE source_path = ? LIMIT 1",
                [$targetSlug]
            );
            if ($row !== null) {
                return [
                    'target_id' => (int) ($row->id ?? 0),
                    'target_slug' => (string) ($row->target_slug ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * @return array{target_id:int,target_slug:string}|null
     */
    private function resolve_comment_target(\CMS\Database $db, string $p, int $targetId): ?array
    {
        if ($targetId <= 0) {
            return null;
        }

        $row = $db->get_row(
            "SELECT id FROM {$p}comments WHERE id = ? LIMIT 1",
            [$targetId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'target_id' => (int) ($row->id ?? 0),
            'target_slug' => '',
        ];
    }

    private function store_import_item(\CMS\Database $db, string $p, array $payload): void
    {
        $existing = null;
        $existingTargetCreated = null;
        if (!empty($payload['source_wp_id'])) {
            $existingRow = $db->get_row(
                "SELECT id, target_created FROM {$p}import_items WHERE source_type = ? AND source_wp_id = ? AND target_type = ? ORDER BY id DESC LIMIT 1",
                [(string) ($payload['source_type'] ?? ''), (int) ($payload['source_wp_id'] ?? 0), (string) ($payload['target_type'] ?? '')]
            );
            if ($existingRow !== null) {
                $existing = (int) ($existingRow->id ?? 0);
                $existingTargetCreated = isset($existingRow->target_created) ? (int) $existingRow->target_created : null;
            }
        }

        if ($existing === null && empty($payload['source_wp_id']) && !empty($payload['source_reference'])) {
            $existingRow = $db->get_row(
                "SELECT id, target_created FROM {$p}import_items WHERE source_type = ? AND source_reference = ? AND target_type = ? AND target_id <=> ? ORDER BY id DESC LIMIT 1",
                [(string) ($payload['source_type'] ?? ''), (string) ($payload['source_reference'] ?? ''), (string) ($payload['target_type'] ?? ''), $payload['target_id'] ?? null]
            );
            if ($existingRow !== null) {
                $existing = (int) ($existingRow->id ?? 0);
                $existingTargetCreated = isset($existingRow->target_created) ? (int) $existingRow->target_created : null;
            }
        }

        $requestedTargetCreated = isset($payload['target_created']) ? (int) $payload['target_created'] : 1;
        $targetCreated = $existingTargetCreated === 1 ? 1 : $requestedTargetCreated;

        $data = [
            'log_id' => $payload['log_id'] ?? null,
            'source_type' => (string) ($payload['source_type'] ?? ''),
            'source_wp_id' => !empty($payload['source_wp_id']) ? (int) $payload['source_wp_id'] : null,
            'source_reference' => $payload['source_reference'] ?? null,
            'source_slug' => $payload['source_slug'] ?? null,
            'source_url' => $payload['source_url'] ?? null,
            'target_type' => (string) ($payload['target_type'] ?? ''),
            'target_id' => !empty($payload['target_id']) ? (int) $payload['target_id'] : null,
            'target_created' => $targetCreated,
            'target_slug' => $payload['target_slug'] ?? null,
            'target_url' => $payload['target_url'] ?? null,
        ];

        if ($existing !== null) {
            $updated = $db->update('import_items', $data, ['id' => (int) $existing]);
            if ($updated === false) {
                $this->errors++;
                error_log('CMS_Importer: Import-Mapping konnte nicht aktualisiert werden: ' . $db->last_error);
            }
            return;
        }

        $insertedId = $db->insert('import_items', $data);
        if ($insertedId === false) {
            $this->errors++;
            error_log('CMS_Importer: Import-Mapping konnte nicht gespeichert werden: ' . $db->last_error);
        }
    }

    private function save_seo_meta(string $contentType, int $contentId, array $seo): void
    {
        if ($contentId <= 0 || $this->seoRepository === null) {
            return;
        }

        $this->seoRepository->saveContentMeta($contentType, $contentId, $seo);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function get_comment_candidates(array $item): array
    {
        $comments = $item['comments'] ?? [];
        if (!is_array($comments)) {
            return [];
        }

        return array_values(array_filter($comments, static fn(mixed $comment): bool => is_array($comment)));
    }

    private function build_comment_source_reference(array $item, array $comment): string
    {
        $commentId = (int) ($comment['comment_id'] ?? 0);
        if ($commentId > 0) {
            return 'wp-comment:' . (int) ($item['wp_id'] ?? 0) . ':' . $commentId;
        }

        return 'wp-comment:'
            . (int) ($item['wp_id'] ?? 0)
            . ':' . md5(
                (string) ($comment['author_email'] ?? '')
                . '|'
                . (string) ($comment['author'] ?? '')
                . '|'
                . (string) ($comment['date'] ?? '')
                . '|'
                . (string) ($comment['content'] ?? '')
            );
    }

    private function sanitize_imported_comment_author(string $author): string
    {
        $author = trim(strip_tags($author));
        $author = $author !== '' ? $author : 'Gast';
        return $this->safe_substr($author, 0, 100);
    }

    private function sanitize_imported_comment_email(string $email): string
    {
        $email = trim($email);
        $validated = filter_var($email, FILTER_VALIDATE_EMAIL);

        if ($validated !== false) {
            return $this->safe_substr((string) $validated, 0, 150);
        }

        return $this->safe_substr($email, 0, 150);
    }

    private function sanitize_imported_comment_content(string $content): string
    {
        $originalContent = trim($content);
        if ($originalContent === '') {
            return '';
        }

        $cleanContent = $originalContent;
        if (class_exists('CMS\\Services\\PurifierService')) {
            try {
                $cleanContent = trim(\CMS\Services\PurifierService::getInstance()->purify($originalContent, 'strict'));
            } catch (\Throwable) {
                $cleanContent = $originalContent;
            }
        }

        if ($cleanContent === '') {
            $cleanContent = trim(strip_tags($originalContent));
        }

        return $cleanContent;
    }

    private function normalize_imported_comment_status(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, ['pending', 'approved', 'spam', 'trash'], true)
            ? $status
            : 'pending';
    }

    private function resolve_comment_user_id(\CMS\Database $db, string $p, array $comment): ?int
    {
        $email = trim((string) ($comment['author_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $userId = $db->get_var(
                "SELECT id FROM {$p}users WHERE email = ? LIMIT 1",
                [$email]
            );

            if ($userId !== null) {
                return (int) $userId;
            }
        }

        $author = trim((string) ($comment['author'] ?? ''));
        if ($author !== '') {
            $userId = $db->get_var(
                "SELECT id FROM {$p}users WHERE username = ? OR display_name = ? LIMIT 1",
                [$author, $author]
            );

            if ($userId !== null) {
                return (int) $userId;
            }
        }

        return null;
    }

    private function ensure_category_id(\CMS\Database $db, string $p, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $slug = $this->slugify($name);
        $existing = $db->get_var(
            "SELECT id FROM {$p}post_categories WHERE slug = ? LIMIT 1",
            [$slug]
        );

        if ($existing !== null) {
            return (int) $existing;
        }

        $created = $db->insert('post_categories', [
            'name' => $name,
            'slug' => $slug,
        ]);

        return $created !== false ? (int) $created : null;
    }

    private function normalize_tag_names(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $tag = trim(strip_tags((string) $tag));
            if ($tag === '') {
                continue;
            }
            $normalized[$this->safe_lower($tag)] = $tag;
        }

        return array_values($normalized);
    }

    private function sync_post_tags(\CMS\Database $db, string $p, int $postId, array $tags): void
    {
        if ($postId <= 0 || $tags === []) {
            return;
        }

        foreach ($tags as $tagName) {
            $slug = $this->slugify($tagName);
            $tagId = $db->get_var(
                "SELECT id FROM {$p}post_tags WHERE slug = ? LIMIT 1",
                [$slug]
            );

            if ($tagId === null) {
                $created = $db->insert('post_tags', [
                    'name' => $tagName,
                    'slug' => $slug,
                ]);
                if ($created === false) {
                    continue;
                }
                $tagId = (int) $created;
            }

            $relationExists = $db->get_var(
                "SELECT id FROM {$p}post_tag_rel WHERE post_id = ? AND tag_id = ? LIMIT 1",
                [$postId, (int) $tagId]
            );

            if ($relationExists === null) {
                $db->insert('post_tag_rel', [
                    'post_id' => $postId,
                    'tag_id' => (int) $tagId,
                ]);
            }

            $db->execute(
                "UPDATE {$p}post_tags
                 SET post_count = (
                     SELECT COUNT(*) FROM {$p}post_tag_rel WHERE tag_id = ?
                 )
                 WHERE id = ?",
                [(int) $tagId, (int) $tagId]
            );
        }
    }

    private function build_target_url(string $targetType, string $slug, int $targetId, string $date = '', string $locale = 'de'): ?string
    {
        if (!defined('SITE_URL')) {
            return null;
        }

        return match ($targetType) {
            'post' => class_exists('CMS\\Services\\PermalinkService')
                ? \CMS\Services\PermalinkService::getInstance()->buildPostUrlFromValues($slug, $date, $date, $locale)
                : rtrim(SITE_URL, '/') . '/blog/' . ltrim($slug, '/'),
            'page' => class_exists('CMS\\Services\\ContentLocalizationService')
                ? rtrim(SITE_URL, '/') . \CMS\Services\ContentLocalizationService::getInstance()->buildLocalizedPath('/' . ltrim($slug, '/'), $locale)
                : rtrim(SITE_URL, '/') . '/' . ltrim($slug, '/'),
            'site_table' => '[site-table id="' . $targetId . '"]',
            default => null,
        };
    }

    private function find_existing_table_id_by_slug(\CMS\Database $db, string $p, string $tableSlug): int
    {
        if (!$this->has_table_slug_column($db, $p)) {
            return 0;
        }

        return (int) ($db->get_var(
            "SELECT id FROM {$p}site_tables WHERE table_slug = ? LIMIT 1",
            [$tableSlug]
        ) ?? 0);
    }

    private function build_unique_table_slug(\CMS\Database $db, string $p, string $base): string
    {
        $baseSlug = $this->sanitize_slug($base !== '' ? $base : 'site-table');
        $slug = $baseSlug;
        $suffix = 2;

        while ($slug !== '' && $this->find_existing_table_id_by_slug($db, $p, $slug) > 0) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        return $slug !== '' ? $slug : 'site-table';
    }

    private function has_table_slug_column(\CMS\Database $db, string $p): bool
    {
        try {
            return $db->get_var("SHOW COLUMNS FROM {$p}site_tables LIKE 'table_slug'") !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensure_redirect_rule_tables(\CMS\Database $db, string $p): void
    {
        if (class_exists('CMS\\Services\\RedirectService')) {
            \CMS\Services\RedirectService::getInstance()->ensureTables();
            return;
        }

        $db->query(
            "CREATE TABLE IF NOT EXISTS {$p}redirect_rules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_path VARCHAR(255) NOT NULL,
                target_url VARCHAR(500) NOT NULL,
                redirect_type SMALLINT NOT NULL DEFAULT 301,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT DEFAULT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 0,
                last_hit_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_source_path (source_path),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function normalize_import_redirect_source_path(string $source): string
    {
        $source = trim(html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($source === '') {
            return '';
        }

        if (($pos = strpos($source, '?')) !== false) {
            $source = substr($source, 0, $pos);
        }

        if (filter_var($source, FILTER_VALIDATE_URL) !== false) {
            $source = (string) parse_url($source, PHP_URL_PATH);
        }

        $source = '/' . ltrim($source, '/');
        if ($source !== '/') {
            $source = rtrim($source, '/');
        }

        return $source;
    }

    private function normalize_import_redirect_target(string $target): string
    {
        $target = trim(html_entity_decode($target, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($target === '') {
            return '';
        }

        if (filter_var($target, FILTER_VALIDATE_URL) !== false) {
            return $target;
        }

        return $this->normalize_import_redirect_source_path($target);
    }

    private function normalize_import_redirect_type(int $type): int
    {
        return in_array($type, [301, 302], true) ? $type : 301;
    }

    private function ensure_unique_filename(string $filename, string $url, array &$usedNames): string
    {
        $filename = $filename !== '' ? $filename : 'image-' . substr(md5($url), 0, 8) . '.jpg';
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $candidate = $filename;
        $suffix = 2;

        while (isset($usedNames[$candidate])) {
            $candidate = $name . '-' . $suffix . ($ext !== '' ? '.' . $ext : '');
            $suffix++;
        }

        $usedNames[$candidate] = true;
        return $candidate;
    }

    private function urls_match(string $left, string $right): bool
    {
        return $left !== '' && $right !== '' && $this->normalize_url_key($left) === $this->normalize_url_key($right);
    }

    private function looks_like_image_url(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        return preg_match('/\.(jpe?g|png|gif|webp|bmp|svg|avif)(?:$|\?)/i', $path) === 1;
    }

    private function default_seo_payload(): array
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
            'schema_type' => 'WebPage',
            'sitemap_priority' => '',
            'sitemap_changefreq' => '',
            'hreflang_group' => '',
        ];
    }

    /**
     * @param array<string, mixed> $settingsBundle
     * @return array<string, mixed>
     */
    private function build_settings_preview(array $settingsBundle): array
    {
        $settings = is_array($settingsBundle['settings'] ?? null) ? $settingsBundle['settings'] : [];
        $mappedFields = is_array($settingsBundle['mapped_fields'] ?? null) ? $settingsBundle['mapped_fields'] : [];

        $labels = [];
        foreach ($mappedFields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        if ($labels === []) {
            $labels = array_keys($settings);
        }

        return [
            'action' => $settings !== [] ? 'import' : 'skip',
            'reason' => $settings !== [] ? '' : 'Keine importierbaren SEO-Settings erkannt',
            'source_type' => 'rank_math_settings',
            'source_label' => 'SEO-Settings',
            'source_wp_id' => 0,
            'source_title' => 'Rank Math SEO-Defaults',
            'source_status' => 'bereit',
            'target_group' => 'settings',
            'target_type' => 'setting_bundle',
            'target_slug' => 'seo-settings',
            'target_url' => '',
            'target_hint' => 'Wird in globale 365CMS-SEO-Einstellungen geschrieben',
            'image_candidates' => 0,
            'featured_image' => '',
            'table_shortcodes_found' => 0,
            'table_shortcodes_resolved' => 0,
            'table_targets' => [],
            'unknown_meta_count' => 0,
            'category' => '',
            'tags' => [],
            'settings_keys_count' => count($settings),
            'settings_labels' => $labels,
        ];
    }

    /**
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $context
     */
    private function collect_preview_item(array $preview, array &$context): void
    {
        $context['items_total']++;

        if (($preview['action'] ?? '') === 'import') {
            $context['would_import']++;
            $type = (string) ($preview['target_group'] ?? 'others');
            if (isset($context['breakdown'][$type])) {
                $context['breakdown'][$type]++;
            }
            $context['images_detected'] += (int) ($preview['image_candidates'] ?? 0);
            $context['table_shortcodes_found'] += (int) ($preview['table_shortcodes_found'] ?? 0);
            $context['table_shortcodes_resolved'] += (int) ($preview['table_shortcodes_resolved'] ?? 0);
        } else {
            $context['would_skip']++;
            $reason = $this->normalize_skip_reason((string) ($preview['reason'] ?? ''));
            $context['skip_reasons'][$reason] = (int) ($context['skip_reasons'][$reason] ?? 0) + 1;
        }

        $context['comments_total'] += (int) ($preview['comments_total'] ?? 0);
        $context['comments_would_import'] += (int) ($preview['comments_importable'] ?? 0);
        $context['comments_would_skip'] += (int) ($preview['comments_skipped'] ?? 0);

        if (count($context['preview_items']) < (int) ($context['preview_limit'] ?? 25)) {
            $context['preview_items'][] = $preview;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function prioritize_items_by_locale(array $items): array
    {
        $primary = [];
        $localized = [];

        foreach ($items as $item) {
            if ($this->resolve_item_locale($item) === 'de') {
                $primary[] = $item;
                continue;
            }

            $localized[] = $item;
        }

        return array_merge($primary, $localized);
    }

    private function resolve_item_locale(array $item): string
    {
        $locale = strtolower(trim((string) ($item['locale'] ?? '')));
        if ($locale !== '') {
            return $locale;
        }

        $translationPriority = strtolower(trim((string) ($item['translation_priority'] ?? '')));
        if ($translationPriority !== '' && str_ends_with($translationPriority, '-en')) {
            return 'en';
        }

        foreach ([(string) ($item['link'] ?? ''), (string) ($item['guid'] ?? '')] as $candidateUrl) {
            $path = filter_var($candidateUrl, FILTER_VALIDATE_URL) !== false
                ? (string) parse_url($candidateUrl, PHP_URL_PATH)
                : $candidateUrl;
            $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => trim($segment) !== ''));
            if ($segments === []) {
                continue;
            }

            $firstSegment = strtolower((string) ($segments[0] ?? ''));
            $lastSegment = strtolower((string) ($segments[count($segments) - 1] ?? ''));
            if ($firstSegment === 'en' || $lastSegment === 'en') {
                return 'en';
            }
        }

        return 'de';
    }

    private function should_skip_item_by_locale_filter(array $item): bool
    {
        if (empty($this->options['import_only_en'])) {
            return false;
        }

        return $this->resolve_item_locale($item) !== 'en';
    }

    private function get_locale_filter_skip_reason(array $item): string
    {
        if (!empty($this->options['import_only_en']) && $this->resolve_item_locale($item) !== 'en') {
            return 'Nur /en/-Inhalte ausgewählt';
        }

        return 'Element entspricht nicht dem aktiven Sprachfilter';
    }

    private function is_locale_filter_skip(string $action, string $reason, string $locale): bool
    {
        return $action === 'skip'
            && $reason === 'Nur /en/-Inhalte ausgewählt'
            && strtolower(trim($locale)) !== 'en';
    }

    private function build_locale_filter_skip_hint(string $locale): string
    {
        $locale = strtolower(trim($locale));
        $label = $locale !== '' ? strtoupper($locale) : 'DE';

        return 'Kein Importziel – vom /en/-Filter ausgeschlossen (erkannte Sprache: ' . $label . ')';
    }

    private function resolve_target_slug(array $item, string $fallbackTitle): string
    {
        return $this->resolve_import_slug($item, $fallbackTitle);
    }

    /**
     * @return array{target_id:int,target_slug:string}|null
     */
    private function find_preferred_localized_content_target(\CMS\Database $db, string $p, string $targetType, string $sourceType, ?string $sourceReference, string $desiredSlug): ?array
    {
        if ($sourceReference !== null && $sourceReference !== '') {
            $mapping = $this->find_existing_mapping_by_reference($db, $p, $sourceType, $sourceReference, $targetType, true);
            if ($mapping !== null && !$this->should_ignore_existing_source_mapping($mapping, $desiredSlug, $sourceReference)) {
                return $mapping;
            }
        }

        foreach ($this->build_localized_merge_slug_candidates((string) ($sourceReference ?? ''), $desiredSlug) as $candidateSlug) {
            $resolved = $this->resolve_mapping_target($db, $p, $targetType, 0, $candidateSlug);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function should_ignore_existing_source_mapping(?array $mapping, string $desiredSlug, ?string $sourceReference = null): bool
    {
        if (!is_array($mapping)) {
            return false;
        }

        $mappedSlug = trim((string) ($mapping['target_slug'] ?? ''));
        $desiredSlug = trim($desiredSlug);

        if ($mappedSlug === '' || $desiredSlug === '' || strcasecmp($mappedSlug, $desiredSlug) === 0) {
            return false;
        }

        foreach ($this->build_localized_merge_slug_candidates((string) ($sourceReference ?? ''), $mappedSlug) as $candidate) {
            if (strcasecmp($candidate, $desiredSlug) === 0) {
                return true;
            }
        }

        foreach ($this->build_localized_merge_slug_candidates((string) ($sourceReference ?? ''), $desiredSlug) as $candidate) {
            if (strcasecmp($candidate, $mappedSlug) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{target_id:int,target_slug:string} $localizedTarget
     */
    private function import_into_localized_post(\CMS\Database $db, string $p, array $item, string $sourceType, ?string $sourceReference, array $localizedTarget, string $baseSlug, string $locale, bool $isCustomType): void
    {
        $targetId = (int) ($localizedTarget['target_id'] ?? 0);
        $targetSlug = trim((string) ($localizedTarget['target_slug'] ?? $baseSlug));
        if ($targetId <= 0 || $targetSlug === '') {
            return;
        }

        $prepared = $this->prepare_content_payload($db, $p, $item, 'post', $targetSlug);
        $updatedAt = $this->resolve_original_updated_at($item, $this->resolve_original_created_at($item));

        try {
            $this->ensure_localized_content_columns($db, $p, 'post');
            $updated = $db->update('posts', [
                'slug_en' => $baseSlug,
                'title_en' => $this->sanitize_title((string) ($item['title'] ?? '')),
                'content_en' => $prepared['content'],
                'excerpt_en' => $prepared['excerpt'],
                'updated_at' => $updatedAt,
            ], [
                'id' => $targetId,
            ]);

            if ($updated === false) {
                throw new \RuntimeException($db->last_error !== '' ? $db->last_error : 'Update lokalisierter Post-Inhalte fehlgeschlagen.');
            }

            $this->imported++;
            $this->import_breakdown[$isCustomType ? 'others' : 'posts']++;
            $this->store_import_item($db, $p, [
                'log_id' => $this->log_id,
                'source_type' => $sourceType,
                'source_wp_id' => (int) ($item['wp_id'] ?? 0),
                'source_reference' => $sourceReference,
                'source_slug' => (string) ($item['slug'] ?? ''),
                'source_url' => (string) ($item['link'] ?? ''),
                'target_type' => 'post',
                'target_id' => $targetId,
                'target_created' => 0,
                'target_slug' => $baseSlug,
                'target_url' => $this->build_target_url('post', $baseSlug, $targetId, (string) ($item['date'] ?? ''), $locale),
            ]);
            $this->collect_taxonomy_fallback_meta($item, 'post');
            $this->collect_unknown_meta($item);
            $this->import_comments_for_post($db, $p, $item, $targetId, $baseSlug, (string) ($item['date'] ?? ''), $locale);
        } catch (\Throwable $e) {
            $this->errors++;
            error_log('CMS_Importer: Lokalisierter Post-Import fehlgeschlagen: ' . $e->getMessage() . ' – Titel: ' . (string) ($item['title'] ?? ''));
        }
    }

    /**
     * @param array{target_id:int,target_slug:string} $localizedTarget
     */
    private function import_into_localized_page(\CMS\Database $db, string $p, array $item, ?string $sourceReference, array $localizedTarget, string $baseSlug, string $locale): void
    {
        $targetId = (int) ($localizedTarget['target_id'] ?? 0);
        $targetSlug = trim((string) ($localizedTarget['target_slug'] ?? $baseSlug));
        if ($targetId <= 0 || $targetSlug === '') {
            return;
        }

        $prepared = $this->prepare_content_payload($db, $p, $item, 'page', $targetSlug);
        $updatedAt = $this->resolve_original_updated_at($item, $this->resolve_original_created_at($item));

        try {
            $this->ensure_localized_content_columns($db, $p, 'page');
            $updated = $db->update('pages', [
                'slug_en' => $baseSlug,
                'title_en' => $this->sanitize_title((string) ($item['title'] ?? '')),
                'content_en' => $prepared['content'],
                'updated_at' => $updatedAt,
            ], [
                'id' => $targetId,
            ]);

            if ($updated === false) {
                throw new \RuntimeException($db->last_error !== '' ? $db->last_error : 'Update lokalisierter Seiten-Inhalte fehlgeschlagen.');
            }

            $this->imported++;
            $this->import_breakdown['pages']++;
            $this->store_import_item($db, $p, [
                'log_id' => $this->log_id,
                'source_type' => 'page',
                'source_wp_id' => (int) ($item['wp_id'] ?? 0),
                'source_reference' => $sourceReference,
                'source_slug' => (string) ($item['slug'] ?? ''),
                'source_url' => (string) ($item['link'] ?? ''),
                'target_type' => 'page',
                'target_id' => $targetId,
                'target_created' => 0,
                'target_slug' => $baseSlug,
                'target_url' => $this->build_target_url('page', $baseSlug, $targetId, (string) ($item['date'] ?? ''), $locale),
            ]);
            $this->collect_taxonomy_fallback_meta($item, 'page');
            $this->collect_unknown_meta($item);
            $this->skip_item_comments($item);
        } catch (\Throwable $e) {
            $this->errors++;
            error_log('CMS_Importer: Lokalisierter Seiten-Import fehlgeschlagen: ' . $e->getMessage() . ' – Titel: ' . (string) ($item['title'] ?? ''));
        }
    }

    private function build_locale_specific_slug(string $slug, string $locale): string
    {
        $slug = $this->preserve_source_slug($slug);
        $locale = strtolower(trim($locale));

        if ($slug === '' || $locale === '' || $locale === 'de') {
            return $slug;
        }

        if (preg_match('/(?:-|_)' . preg_quote($locale, '/') . '$/i', $slug) === 1) {
            return $slug;
        }

        return $this->preserve_source_slug($slug . '-' . $locale);
    }

    private function should_ignore_item_by_en_slug(array $item): bool
    {
        if ($this->resolve_item_locale($item) === 'en') {
            return true;
        }

        $translationPriority = strtolower(trim((string) ($item['translation_priority'] ?? '')));
        if ($translationPriority !== '' && str_ends_with($translationPriority, '-en')) {
            return true;
        }

        foreach ([(string) ($item['link'] ?? ''), (string) ($item['guid'] ?? ''), (string) ($item['slug'] ?? '')] as $candidate) {
            if ($this->contains_en_path_segment($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function contains_en_path_segment(string $candidate): bool
    {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($candidate === '') {
            return false;
        }

        $path = filter_var($candidate, FILTER_VALIDATE_URL) !== false
            ? (string) parse_url($candidate, PHP_URL_PATH)
            : $candidate;

        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn(string $segment): bool => trim($segment) !== ''
        ));

        foreach ($segments as $segment) {
            if (strtolower(trim($segment)) === 'en') {
                return true;
            }
        }

        return false;
    }

    private function find_localized_content_target(\CMS\Database $db, string $p, string $targetType, string $sourceType, ?string $sourceReference, string $fallbackSlug = ''): ?array
    {
        return $this->find_preferred_localized_content_target($db, $p, $targetType, $sourceType, $sourceReference, $fallbackSlug);
    }

    /**
     * @return array<int, string>
     */
    private function build_localized_merge_slug_candidates(string $sourceReference, string $fallbackSlug = ''): array
    {
        $candidates = [];

        $referenceSlug = $this->extract_reference_slug($sourceReference);
        foreach ([$referenceSlug, trim($fallbackSlug)] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            $variants = [$candidate];

            if (preg_match('/^(.*?)[-_]en$/i', $candidate, $matches) === 1 && trim((string) ($matches[1] ?? '')) !== '') {
                $variants[] = trim((string) $matches[1]);
            }

            if (preg_match('/^en[-_](.+)$/i', $candidate, $matches) === 1 && trim((string) ($matches[1] ?? '')) !== '') {
                $variants[] = trim((string) $matches[1]);
            }

            foreach ($variants as $variant) {
                $normalized = $this->preserve_source_slug($variant);
                if ($normalized !== '') {
                    $candidates[$normalized] = $normalized;
                }
            }
        }

        return array_values($candidates);
    }

    private function extract_reference_slug(string $sourceReference): string
    {
        $sourceReference = trim($sourceReference, '/');
        if ($sourceReference === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $sourceReference), static fn(string $segment): bool => trim($segment) !== ''));
        if ($segments === []) {
            return '';
        }

        return (string) end($segments);
    }

    private function ensure_localized_content_columns(\CMS\Database $db, string $p, string $targetType): void
    {
        $definitions = $targetType === 'post'
            ? [
                'slug_en' => "ALTER TABLE {$p}posts ADD COLUMN slug_en VARCHAR(255) DEFAULT NULL AFTER slug",
                'title_en' => "ALTER TABLE {$p}posts ADD COLUMN title_en VARCHAR(255) DEFAULT NULL AFTER title",
                'content_en' => "ALTER TABLE {$p}posts ADD COLUMN content_en LONGTEXT DEFAULT NULL AFTER content",
                'excerpt_en' => "ALTER TABLE {$p}posts ADD COLUMN excerpt_en TEXT DEFAULT NULL AFTER excerpt",
            ]
            : [
                'slug_en' => "ALTER TABLE {$p}pages ADD COLUMN slug_en VARCHAR(200) DEFAULT NULL AFTER slug",
                'title_en' => "ALTER TABLE {$p}pages ADD COLUMN title_en VARCHAR(255) DEFAULT NULL AFTER title",
                'content_en' => "ALTER TABLE {$p}pages ADD COLUMN content_en LONGTEXT DEFAULT NULL AFTER content",
            ];

        $table = $targetType === 'post' ? $p . 'posts' : $p . 'pages';

        foreach ($definitions as $column => $sql) {
            try {
                $stmt = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
                if ($stmt instanceof \PDOStatement && !$stmt->fetch()) {
                    $db->query($sql);
                }
            } catch (\Throwable $e) {
                error_log(sprintf('CMS_Importer: ensure_localized_content_columns(%s.%s) warning: %s', $table, $column, $e->getMessage()));
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function build_post_preview(\CMS\Database $db, string $p, array $item, bool $isCustomType, array &$context): array
    {
        $status = self::STATUS_MAP[$item['post_status']] ?? 'draft';
        $sourceType = (string) ($item['post_type'] ?? 'post');
        $sourceReference = $this->resolve_content_source_reference($item);
        $locale = $this->resolve_item_locale($item);
        $reason = '';
        $action = 'import';
        $existingMapping = null;
        $localizedTarget = null;
        $localizedMerge = false;

        if ($this->should_skip_item_by_locale_filter($item)) {
            $action = 'skip';
            $reason = $this->get_locale_filter_skip_reason($item);
        } elseif ($isCustomType && !$this->options['import_custom_types']) {
            $action = 'skip';
            $reason = 'Custom Post Types deaktiviert';
        } elseif ($status === 'trash' && !$this->options['import_trashed']) {
            $action = 'skip';
            $reason = 'Papierkorb-Elemente deaktiviert';
        } elseif ($status === 'draft' && !$this->options['import_drafts']) {
            $action = 'skip';
            $reason = 'Entwürfe deaktiviert';
        }

        $baseSlug = $this->resolve_target_slug($item, (string) ($item['title'] ?? ''));

        if ($action === 'import' && $this->options['skip_duplicates']) {
            $existingMapping = $this->find_existing_mapping_by_wp_id($db, $p, $sourceType, (int) ($item['wp_id'] ?? 0), 'post');
            if ($this->should_ignore_existing_source_mapping($existingMapping, $baseSlug, $sourceReference)) {
                $existingMapping = null;
            }

            if ($existingMapping !== null) {
                $action = 'skip';
                $reason = 'Bereits per Import-Mapping vorhanden';
            }
        }

        if ($action === 'import' && $locale !== 'de') {
            $localizedTarget = $this->find_preferred_localized_content_target($db, $p, 'post', $sourceType, $sourceReference, $baseSlug);
            $localizedMerge = $localizedTarget !== null || !empty($context['reserved_slugs']['post'][$baseSlug]);
        }

        $targetSlug = $baseSlug;
        if ($action === 'import') {
            if ($localizedMerge) {
                $targetSlug = $baseSlug;
                $this->reserve_preview_slug('post', $targetSlug, $context);
            } elseif ($this->options['skip_duplicates']) {
                if ($this->preview_slug_exists($db, $p . 'posts', $baseSlug, 'post', $context)) {
                    $action = 'skip';
                    $reason = 'Slug bereits vorhanden';
                } else {
                    $this->reserve_preview_slug('post', $baseSlug, $context);
                    $targetSlug = $baseSlug;
                }
            } else {
                $targetSlug = $this->preview_unique_slug($db, $p . 'posts', $baseSlug, 'post', $context, !empty($item['slug']));
            }
        }

        $contentPreview = $this->preview_content_payload($db, $p, $item, $context);
        $commentPreview = $this->preview_comment_summary(
            $db,
            $p,
            $item,
            $action === 'import' || $existingMapping !== null || $reason === 'Slug bereits vorhanden',
            true
        );
        $categories = $this->normalize_tag_names($item['categories'] ?? []);
        $categoryName = trim((string) ($categories[0] ?? ''));
        $tagNames = $this->normalize_tag_names($item['tags'] ?? []);
        $authorPreview = $this->resolve_import_author_preview($db, $p, $item);
        $fallbackTaxonomies = $this->get_taxonomy_fallback_meta_entries($item, 'post');
        $targetHint = $isCustomType
            ? 'Wird als CMS-Beitrag importiert'
            : 'Wird in cms_posts geschrieben';
        if ($localizedMerge && $locale !== 'de') {
            $targetHint = 'Aktualisiert vorhandenen CMS-Beitrag als /' . $locale . '/-Variante';
        }
        if ($fallbackTaxonomies !== []) {
            $targetHint .= ' · Zusätzliche WordPress-Kategorien werden im Meta-Bericht gesichert';
        }
        $this->collect_unknown_meta($item);

        if ($action === 'skip') {
            $reason = $this->normalize_skip_reason($reason);
        }

        $targetUrl = $this->build_target_url('post', $targetSlug, 0, (string) ($item['date'] ?? ''), $locale);
        if ($this->is_locale_filter_skip($action, $reason, $locale)) {
            $targetSlug = '';
            $targetUrl = null;
            $targetHint = $this->build_locale_filter_skip_hint($locale);
        }

        return [
            'action' => $action,
            'reason' => $reason,
            'detected_locale' => $locale,
            'source_type' => $sourceType,
            'source_label' => $isCustomType ? 'Custom Type' : 'Beitrag',
            'source_wp_id' => (int) ($item['wp_id'] ?? 0),
            'source_title' => (string) ($item['title'] ?? ''),
            'source_status' => (string) ($item['post_status'] ?? ''),
            'target_group' => $isCustomType ? 'others' : 'posts',
            'target_type' => 'post',
            'target_slug' => $targetSlug,
            'target_url' => $targetUrl,
            'target_hint' => $targetHint,
            'category' => $categoryName,
            'tags' => $tagNames,
            'author_label' => (string) ($authorPreview['author_label'] ?? ''),
            'author_display_name' => (string) ($authorPreview['author_display_name'] ?? ''),
            'image_candidates' => (int) ($contentPreview['image_candidates'] ?? 0),
            'featured_image' => (string) ($contentPreview['featured_image'] ?? ''),
            'table_shortcodes_found' => (int) ($contentPreview['table_shortcodes_found'] ?? 0),
            'table_shortcodes_resolved' => (int) ($contentPreview['table_shortcodes_resolved'] ?? 0),
            'table_targets' => $contentPreview['table_targets'] ?? [],
            'comments_total' => (int) ($commentPreview['total'] ?? 0),
            'comments_importable' => (int) ($commentPreview['importable'] ?? 0),
            'comments_skipped' => (int) ($commentPreview['skipped'] ?? 0),
            'unknown_meta_count' => $this->count_unknown_meta_for_item($item, 'post'),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function build_page_preview(\CMS\Database $db, string $p, array $item, array &$context): array
    {
        $status = self::STATUS_MAP[$item['post_status']] ?? 'draft';
        $sourceReference = $this->resolve_content_source_reference($item);
        $locale = $this->resolve_item_locale($item);
        $reason = '';
        $action = 'import';
        $existingMapping = null;
        $localizedTarget = null;
        $localizedMerge = false;

        if ($this->should_skip_item_by_locale_filter($item)) {
            $action = 'skip';
            $reason = $this->get_locale_filter_skip_reason($item);
        } elseif ($status === 'trash' && !$this->options['import_trashed']) {
            $action = 'skip';
            $reason = 'Papierkorb-Elemente deaktiviert';
        } elseif ($status === 'draft' && !$this->options['import_drafts']) {
            $action = 'skip';
            $reason = 'Entwürfe deaktiviert';
        }

        $baseSlug = $this->resolve_target_slug($item, (string) ($item['title'] ?? ''));

        if ($action === 'import' && $this->options['skip_duplicates']) {
            $existingMapping = $this->find_existing_mapping_by_wp_id($db, $p, 'page', (int) ($item['wp_id'] ?? 0), 'page');
            if ($this->should_ignore_existing_source_mapping($existingMapping, $baseSlug, $sourceReference)) {
                $existingMapping = null;
            }

            if ($existingMapping !== null) {
                $action = 'skip';
                $reason = 'Bereits per Import-Mapping vorhanden';
            }
        }

        if ($action === 'import' && $locale !== 'de') {
            $localizedTarget = $this->find_preferred_localized_content_target($db, $p, 'page', 'page', $sourceReference, $baseSlug);
            $localizedMerge = $localizedTarget !== null || !empty($context['reserved_slugs']['page'][$baseSlug]);
        }

        $targetSlug = $baseSlug;
        if ($action === 'import') {
            if ($localizedMerge) {
                $targetSlug = $baseSlug;
                $this->reserve_preview_slug('page', $targetSlug, $context);
            } elseif ($this->options['skip_duplicates']) {
                if ($this->preview_slug_exists($db, $p . 'pages', $baseSlug, 'page', $context)) {
                    $action = 'skip';
                    $reason = 'Slug bereits vorhanden';
                } else {
                    $this->reserve_preview_slug('page', $baseSlug, $context);
                    $targetSlug = $baseSlug;
                }
            } else {
                $targetSlug = $this->preview_unique_slug($db, $p . 'pages', $baseSlug, 'page', $context, !empty($item['slug']));
            }
        }

        $contentPreview = $this->preview_content_payload($db, $p, $item, $context);
        $commentPreview = $this->preview_comment_summary($db, $p, $item, false, false);
        $pageCategories = $this->normalize_tag_names($item['categories'] ?? []);
        $pageTags = $this->normalize_tag_names($item['tags'] ?? []);
        $pageFallbackMeta = $this->get_taxonomy_fallback_meta_entries($item, 'page');
        $this->collect_unknown_meta($item);

        if ($action === 'skip') {
            $reason = $this->normalize_skip_reason($reason);
        }

        $targetHint = $localizedMerge && $locale !== 'de'
            ? ('Aktualisiert vorhandene CMS-Seite als /' . $locale . '/-Variante' . ($pageFallbackMeta !== [] ? ' · WordPress-Kategorien/Tags werden im Meta-Bericht gesichert' : ''))
            : ($pageFallbackMeta !== []
                ? 'Wird in cms_pages geschrieben · WordPress-Kategorien/Tags werden im Meta-Bericht gesichert'
                : 'Wird in cms_pages geschrieben');

        $targetUrl = $this->build_target_url('page', $targetSlug, 0, (string) ($item['date'] ?? ''), $locale);
        if ($this->is_locale_filter_skip($action, $reason, $locale)) {
            $targetSlug = '';
            $targetUrl = null;
            $targetHint = $this->build_locale_filter_skip_hint($locale);
        }

        return [
            'action' => $action,
            'reason' => $reason,
            'detected_locale' => $locale,
            'source_type' => 'page',
            'source_label' => 'Seite',
            'source_wp_id' => (int) ($item['wp_id'] ?? 0),
            'source_title' => (string) ($item['title'] ?? ''),
            'source_status' => (string) ($item['post_status'] ?? ''),
            'target_group' => 'pages',
            'target_type' => 'page',
            'target_slug' => $targetSlug,
            'target_url' => $targetUrl,
            'target_hint' => $targetHint,
            'category' => implode(', ', $pageCategories),
            'tags' => $pageTags,
            'image_candidates' => (int) ($contentPreview['image_candidates'] ?? 0),
            'featured_image' => (string) ($contentPreview['featured_image'] ?? ''),
            'table_shortcodes_found' => (int) ($contentPreview['table_shortcodes_found'] ?? 0),
            'table_shortcodes_resolved' => (int) ($contentPreview['table_shortcodes_resolved'] ?? 0),
            'table_targets' => $contentPreview['table_targets'] ?? [],
            'comments_total' => (int) ($commentPreview['total'] ?? 0),
            'comments_importable' => (int) ($commentPreview['importable'] ?? 0),
            'comments_skipped' => (int) ($commentPreview['skipped'] ?? 0),
            'unknown_meta_count' => $this->count_unknown_meta_for_item($item, 'page'),
        ];
    }

    /**
     * @return array{total:int,importable:int,skipped:int}
     */
    private function preview_comment_summary(\CMS\Database $db, string $p, array $item, bool $targetAvailable, bool $supportedTarget): array
    {
        $summary = [
            'total' => 0,
            'importable' => 0,
            'skipped' => 0,
        ];

        foreach ($this->get_comment_candidates($item) as $comment) {
            $summary['total']++;

            if (!$supportedTarget || !$targetAvailable) {
                $summary['skipped']++;
                continue;
            }

            $sourceReference = $this->build_comment_source_reference($item, $comment);
            $existingComment = $this->find_existing_mapping(
                $db,
                $p,
                'wp_comment',
                (int) ($comment['comment_id'] ?? 0),
                $sourceReference,
                'comment'
            );

            if ($existingComment !== null || $this->sanitize_imported_comment_content((string) ($comment['content'] ?? '')) === '') {
                $summary['skipped']++;
                continue;
            }

            $summary['importable']++;
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function build_table_preview(\CMS\Database $db, string $p, array $item, array &$context): array
    {
        $table = $item['table'] ?? null;
        $legacyTableId = trim((string) ($item['legacy_table_id'] ?? ''));
        if (!is_array($table) || empty($table['columns']) || !isset($table['rows'])) {
            return [
                'action' => 'skip',
                'reason' => 'Keine gültige Tabellenstruktur erkannt',
                'source_type' => 'tablepress_table',
                'source_label' => 'Tabelle',
                'source_wp_id' => (int) ($item['wp_id'] ?? 0),
                'source_title' => (string) ($item['title'] ?? ''),
                'source_status' => (string) ($item['post_status'] ?? ''),
                'target_group' => 'tables',
                'target_type' => 'site_table',
                'target_slug' => '',
                'target_url' => '',
                'target_hint' => '',
                'image_candidates' => 0,
                'featured_image' => '',
                'table_shortcodes_found' => 0,
                'table_shortcodes_resolved' => 0,
                'table_targets' => [],
                'unknown_meta_count' => $this->count_unknown_meta_for_item($item),
                'category' => '',
                'tags' => [],
            ];
        }

        $reason = '';
        $action = 'import';
        $existingMapping = null;

        if ($this->options['skip_duplicates']) {
            $existingMapping = $this->find_existing_mapping($db, $p, 'tablepress_table', (int) ($item['wp_id'] ?? 0), $legacyTableId !== '' ? $legacyTableId : null, 'site_table');
            if ($existingMapping !== null) {
                $action = 'skip';
                $reason = 'Bereits per Import-Mapping vorhanden';
            }
        }

        $baseSlug = $this->sanitize_slug((string) ($table['slug'] ?? $table['name'] ?? 'site-table'));
        $targetSlug = $baseSlug;

        if ($action === 'import') {
            if ($this->options['skip_duplicates']) {
                if ($this->preview_table_slug_exists($db, $p, $baseSlug, $context)) {
                    $action = 'skip';
                    $reason = 'Tabellenslug bereits vorhanden';
                } else {
                    $this->reserve_preview_slug('site_table', $baseSlug, $context);
                    $targetSlug = $baseSlug;
                }
            } else {
                $targetSlug = $this->preview_unique_table_slug($db, $p, $baseSlug, $context);
            }
        }

        $targetShortcode = '[site-table id="neu:' . $targetSlug . '"]';
        if ($action === 'import' && $legacyTableId !== '') {
            $context['table_preview_map'][$legacyTableId] = $targetShortcode;
        }

        $this->collect_unknown_meta($item);

        if ($action === 'skip') {
            $reason = $this->normalize_skip_reason($reason);
        }

        return [
            'action' => $action,
            'reason' => $reason,
            'source_type' => 'tablepress_table',
            'source_label' => 'Tabelle',
            'source_wp_id' => (int) ($item['wp_id'] ?? 0),
            'source_title' => (string) (($table['name'] ?? '') !== '' ? $table['name'] : ($item['title'] ?? '')),
            'source_status' => (string) ($item['post_status'] ?? ''),
            'target_group' => 'tables',
            'target_type' => 'site_table',
            'target_slug' => $existingMapping['target_slug'] ?? $targetSlug,
            'target_url' => $existingMapping !== null
                ? '[site-table id="' . (int) ($existingMapping['target_id'] ?? 0) . '"]'
                : $targetShortcode,
            'target_hint' => 'Wird in cms_site_tables geschrieben',
            'image_candidates' => 0,
            'featured_image' => '',
            'table_shortcodes_found' => 0,
            'table_shortcodes_resolved' => 0,
            'table_targets' => [],
            'unknown_meta_count' => $this->count_unknown_meta_for_item($item, 'table'),
            'category' => '',
            'tags' => [],
            'table_rows' => count($table['rows'] ?? []),
            'table_columns' => count($table['columns'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function build_redirect_preview(\CMS\Database $db, string $p, array $item, array &$context): array
    {
        $this->ensure_redirect_rule_tables($db, $p);

        $sourceReference = trim((string) ($item['source_reference'] ?? ''));
        $sourcePath = $this->normalize_import_redirect_source_path((string) ($item['redirect_source'] ?? ''));
        $targetUrl = $this->normalize_import_redirect_target((string) ($item['redirect_target'] ?? ''));
        $comparison = strtolower(trim((string) ($item['redirect_comparison'] ?? 'exact')));
        $status = strtolower(trim((string) ($item['status'] ?? 'active')));
        $redirectType = $this->normalize_import_redirect_type((int) ($item['redirect_type'] ?? 301));
        $action = 'import';
        $reason = '';

        if ($comparison !== 'exact') {
            $action = 'skip';
            $reason = 'Rank-Math-Regeln mit Vergleich "' . $comparison . '" werden nicht unterstützt';
        } elseif ($sourcePath === '' || $sourcePath === '/') {
            $action = 'skip';
            $reason = 'Ungültiger Redirect-Quellpfad';
        } elseif ($targetUrl === '') {
            $action = 'skip';
            $reason = 'Ungültiges Redirect-Ziel';
        }

        $existingMapping = $sourceReference !== ''
            ? $this->find_existing_mapping_by_reference($db, $p, 'rank_math_redirection', $sourceReference, 'redirect')
            : null;
        $existingRule = $db->get_row(
            "SELECT id FROM {$p}redirect_rules WHERE source_path = ? LIMIT 1",
            [$sourcePath]
        );

        if ($action === 'import' && empty($context['reserved_slugs']['redirect'][$sourcePath])) {
            $this->reserve_preview_slug('redirect', $sourcePath, $context);
        }

        if ($action === 'skip') {
            $reason = $this->normalize_skip_reason($reason);
        }

        return [
            'action' => $action,
            'reason' => $reason,
            'source_type' => 'rank_math_redirection',
            'source_label' => 'Weiterleitung',
            'source_wp_id' => (int) ($item['rank_math_id'] ?? 0),
            'source_title' => (string) (($item['title'] ?? '') !== '' ? $item['title'] : $sourcePath),
            'source_status' => $status !== '' ? $status : 'active',
            'target_group' => 'redirects',
            'target_type' => 'redirect',
            'target_slug' => $existingMapping['target_slug'] ?? $sourcePath,
            'target_url' => $targetUrl,
            'target_hint' => $existingRule !== null
                ? 'Bestehende 365CMS-Weiterleitung wird aktualisiert'
                : 'Wird in cms_redirect_rules angelegt',
            'image_candidates' => 0,
            'featured_image' => '',
            'table_shortcodes_found' => 0,
            'table_shortcodes_resolved' => 0,
            'table_targets' => [],
            'unknown_meta_count' => 0,
            'category' => '',
            'tags' => [],
            'source_comparison' => $comparison,
            'redirect_type' => $redirectType,
            'redirect_state' => $status === 'active' ? 'aktiv' : 'inaktiv',
            'redirect_hits' => max(0, (int) ($item['redirect_hits'] ?? 0)),
            'last_hit_at' => (string) ($item['last_accessed'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function preview_content_payload(\CMS\Database $db, string $p, array $item, array $context): array
    {
        $featuredImage = trim((string) ($item['featured_image'] ?? ''));
        $candidates = $this->collect_media_candidates($item, $featuredImage);

        if ($featuredImage === '' && !empty($item['image_urls'][0])) {
            $featuredImage = (string) $item['image_urls'][0];
        }

        $tablePreview = $this->preview_table_shortcodes(
            $db,
            $p,
            (string) ($item['content'] ?? ''),
            $context['table_preview_map'] ?? []
        );

        return [
            'featured_image' => $featuredImage,
            'image_candidates' => !empty($this->options['download_images']) ? count($candidates) : 0,
            'table_shortcodes_found' => (int) ($tablePreview['found'] ?? 0),
            'table_shortcodes_resolved' => !empty($this->options['convert_table_shortcodes']) ? (int) ($tablePreview['resolved'] ?? 0) : 0,
            'table_targets' => !empty($this->options['convert_table_shortcodes']) ? ($tablePreview['targets'] ?? []) : [],
        ];
    }

    /**
     * @param array<string, string> $previewTableMap
     * @return array{found:int,resolved:int,targets:array<int, string>}
     */
    private function preview_table_shortcodes(\CMS\Database $db, string $p, string $content, array $previewTableMap): array
    {
        if ($content === '' || !preg_match_all('/\[(?:table|tablepress)\s+id\s*=\s*["\']?(\d+)["\']?\s*\/?\]/i', $content, $matches)) {
            return ['found' => 0, 'resolved' => 0, 'targets' => []];
        }

        $found = 0;
        $resolved = 0;
        $targets = [];

        foreach ($matches[1] as $legacyId) {
            $found++;
            $target = $this->lookup_preview_table_target($db, $p, (string) $legacyId, $previewTableMap);
            if ($target === null) {
                continue;
            }

            $resolved++;
            if (!in_array($target, $targets, true) && count($targets) < 5) {
                $targets[] = $target;
            }
        }

        return ['found' => $found, 'resolved' => $resolved, 'targets' => $targets];
    }

    /**
     * @param array<string, string> $previewTableMap
     */
    private function lookup_preview_table_target(\CMS\Database $db, string $p, string $legacyId, array $previewTableMap): ?string
    {
        if (isset($previewTableMap[$legacyId])) {
            return $previewTableMap[$legacyId];
        }

        $targetId = $db->get_var(
            "SELECT target_id
             FROM {$p}import_items
             WHERE source_type = ?
               AND source_reference = ?
               AND target_type = ?
             ORDER BY id DESC
             LIMIT 1",
            ['tablepress_table', $legacyId, 'site_table']
        );

        if ($targetId === null) {
            $targetId = $db->get_var(
                "SELECT target_id
                 FROM {$p}import_items
                 WHERE source_type = ?
                   AND source_wp_id = ?
                   AND target_type = ?
                 ORDER BY id DESC
                 LIMIT 1",
                ['tablepress_table', (int) $legacyId, 'site_table']
            );
        }

        return $targetId !== null ? '[site-table id="' . (int) $targetId . '"]' : null;
    }

    private function count_unknown_meta_for_item(array $item, string $targetType = ''): int
    {
        if (empty($item['meta'])) {
            return count($this->get_taxonomy_fallback_meta_entries($item, $targetType));
        }

        $mappedKeys = array_fill_keys($item['mapped_meta_keys'] ?? [], true);
        $count = 0;
        foreach ($item['meta'] as $key => $value) {
            if (!isset($mappedKeys[$key])) {
                $count++;
            }
        }

        return $count + count($this->get_taxonomy_fallback_meta_entries($item, $targetType));
    }

    private function collect_taxonomy_fallback_meta(array $item, string $targetType): void
    {
        foreach ($this->get_taxonomy_fallback_meta_entries($item, $targetType) as $entry) {
            $this->unknown_meta[] = $entry;
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function get_taxonomy_fallback_meta_entries(array $item, string $targetType): array
    {
        $entries = [];
        $categories = $this->normalize_tag_names($item['categories'] ?? []);
        $tags = $this->normalize_tag_names($item['tags'] ?? []);

        if ($targetType === 'post') {
            $secondaryCategories = array_values(array_slice($categories, 1));
            if ($secondaryCategories !== []) {
                $entries[] = $this->build_import_meta_entry($item, '_wp_import_additional_categories', implode(' | ', $secondaryCategories));
            }

            return $entries;
        }

        if ($targetType === 'page') {
            if ($categories !== []) {
                $entries[] = $this->build_import_meta_entry($item, '_wp_import_page_categories', implode(' | ', $categories));
            }
            if ($tags !== []) {
                $entries[] = $this->build_import_meta_entry($item, '_wp_import_page_tags', implode(' | ', $tags));
            }
        }

        return $entries;
    }

    private function build_import_meta_entry(array $item, string $metaKey, string $metaValue): array
    {
        return [
            'source_id'  => (string) ($item['wp_id'] ?? ''),
            'post_title' => $this->safe_substr((string) ($item['title'] ?? ''), 0, 255),
            'post_type'  => (string) ($item['post_type'] ?? ''),
            'meta_key'   => $metaKey,
            'meta_value' => $metaValue,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function preview_slug_exists(\CMS\Database $db, string $table, string $slug, string $bucket, array $context): bool
    {
        if ($slug === '' || !empty($context['reserved_slugs'][$bucket][$slug])) {
            return true;
        }

        return (int) $db->get_var("SELECT COUNT(*) FROM {$table} WHERE slug = ?", [$slug]) > 0;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reserve_preview_slug(string $bucket, string $slug, array &$context): void
    {
        $context['reserved_slugs'][$bucket][$slug] = true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function preview_unique_slug(\CMS\Database $db, string $table, string $base, string $bucket, array &$context, bool $preserveBase = false): string
    {
        $slug   = $preserveBase ? $this->preserve_source_slug($base) : $this->sanitize_slug($base);
        $try    = $slug;
        $suffix = 2;

        for ($i = 0; $i <= 25; $i++) {
            if (!$this->preview_slug_exists($db, $table, $try, $bucket, $context)) {
                $this->reserve_preview_slug($bucket, $try, $context);
                return $try;
            }
            $try = $slug . '-' . $suffix;
            $suffix++;
        }

        $fallback = $slug . '-preview-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $this->reserve_preview_slug($bucket, $fallback, $context);
        return $fallback;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function preview_table_slug_exists(\CMS\Database $db, string $p, string $slug, array $context): bool
    {
        if ($slug === '' || !empty($context['reserved_slugs']['site_table'][$slug])) {
            return true;
        }

        if (!$this->has_table_slug_column($db, $p)) {
            return false;
        }

        return $this->find_existing_table_id_by_slug($db, $p, $slug) > 0;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function preview_unique_table_slug(\CMS\Database $db, string $p, string $base, array &$context): string
    {
        $slug = $this->sanitize_slug($base !== '' ? $base : 'site-table');
        $try = $slug;
        $suffix = 2;

        for ($i = 0; $i <= 25; $i++) {
            if (!$this->preview_table_slug_exists($db, $p, $try, $context)) {
                $this->reserve_preview_slug('site_table', $try, $context);
                return $try;
            }
            $try = $slug . '-' . $suffix;
            $suffix++;
        }

        $fallback = $slug . '-preview-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $this->reserve_preview_slug('site_table', $fallback, $context);
        return $fallback;
    }

    private function safe_substr(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, $start, $length);
        }

        return substr($value, $start, $length);
    }

    private function safe_lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return (string) mb_strtolower($value);
        }

        return strtolower($value);
    }
}