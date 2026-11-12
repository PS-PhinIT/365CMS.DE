<?php

declare(strict_types=1);

namespace CMS\Services\Knowledgebase\Repository;

use CMS\Database;
use CMS\Services\ContentLocalizationService;
use CMS\Services\PermalinkService;
use PDO;
use CMS\Services\Knowledgebase\Service\SectionContent;
use CMS\Services\Knowledgebase\Support\ContentSections;
use CMS\Services\Knowledgebase\Support\Defaults;
use CMS\Services\Knowledgebase\Support\LoggerFactory;

if (!defined('ABSPATH')) {
    exit;
}

final class EntryRepository
{
    private static ?self $instance = null;

    private $logger;

    /** @var array<string, string>|null */
    private ?array $settingsCache = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $activeEntriesForLinkingCache = null;

    /** @var array<string, int>|null */
    private ?array $dashboardStatsCache = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->logger = LoggerFactory::create();
    }

    /**
     * @return array<string, int>
     */
    public function getDashboardStats(): array
    {
        if ($this->dashboardStatsCache !== null) {
            return $this->dashboardStatsCache;
        }

        $db = Database::instance();
        $entriesTable = $this->entriesTable();
        $categoriesTable = $this->categoriesTable();
        $stats = [
            'entries' => 0,
            'active_entries' => 0,
            'categories' => 0,
            'tooltip_entries' => 0,
        ];

        $query = "SELECT
            COUNT(*) AS entries,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_entries,
            SUM(CASE WHEN tooltip_text IS NOT NULL AND tooltip_text <> '' THEN 1 ELSE 0 END) AS tooltip_entries
            FROM {$entriesTable} WHERE trashed_at IS NULL";

        $stmt = $db->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            foreach ($stats as $key => $value) {
                if ($key === 'categories') {
                    continue;
                }

                $stats[$key] = (int) ($row[$key] ?? $value);
            }
        }

        $categoryStmt = $db->prepare("SELECT COUNT(*) FROM {$categoriesTable}");
        $categoryStmt->execute();
        $stats['categories'] = (int) $categoryStmt->fetchColumn();

        $this->dashboardStatsCache = $stats;

        return $stats;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEntries(array $filters = []): array
    {
        return $this->getEntriesByColumns('*', $filters);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEntryList(array $filters = []): array
    {
        return $this->getEntriesByColumns(
            'id, title, keyword, slug, excerpt, tooltip_text, synonyms, category, priority, is_active, is_case_sensitive, is_whole_word, max_links_per_page, created_at, updated_at',
            $filters
        );
    }

    public function getEntry(int $id): ?array
    {
        $db = Database::instance();
        $stmt = $db->prepare('SELECT * FROM ' . $this->entriesTable() . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($entry) ? $entry : null;
    }

    public function getEntryBySlug(string $slug): ?array
    {
        $db = Database::instance();
        $stmt = $db->prepare('SELECT * FROM ' . $this->entriesTable() . ' WHERE slug = ? AND is_active = 1 AND trashed_at IS NULL LIMIT 1');
        $stmt->execute([$slug]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($entry) ? $entry : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActiveEntriesForLinking(): array
    {
        if ($this->activeEntriesForLinkingCache !== null) {
            return $this->activeEntriesForLinkingCache;
        }

        $entries = $this->getEntriesByColumns(
            'id, title, keyword, slug, excerpt, tooltip_text, synonyms, is_case_sensitive, is_whole_word, max_links_per_page',
            ['status' => 'active']
        );

        foreach ($entries as &$entry) {
            $terms = [$entry['keyword'] ?? ''];
            $synonyms = preg_split('/[\r\n,]+/', (string) ($entry['synonyms'] ?? '')) ?: [];
            foreach ($synonyms as $synonym) {
                $synonym = trim((string) $synonym);
                if ($synonym !== '') {
                    $terms[] = $synonym;
                }
            }

            $terms = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string) $value), $terms))));
            usort($terms, static fn(string $left, string $right): int => mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8'));
            $entry['terms'] = $terms;
            $entry['url'] = SITE_URL . '/kb/' . rawurlencode((string) ($entry['slug'] ?? ''));
        }
        unset($entry);

        $this->activeEntriesForLinkingCache = $entries;

        return $entries;
    }

    public function countActiveEntries(): int
    {
        $db = Database::instance();
        $stmt = $db->prepare('SELECT COUNT(*) FROM ' . $this->entriesTable() . ' WHERE is_active = 1 AND trashed_at IS NULL');
        $stmt->execute();
        $count = $stmt->fetchColumn();

        return (int) $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCategories(): array
    {
        $db = Database::instance();
        $stmt = $db->prepare('SELECT c.id, c.name AS category, c.slug, c.sort_order, COUNT(e.id) AS entry_count
            FROM ' . $this->categoriesTable() . ' c
            LEFT JOIN ' . $this->entriesTable() . ' e ON e.category = c.name AND e.is_active = 1 AND e.trashed_at IS NULL
            GROUP BY c.id, c.name, c.slug, c.sort_order
            ORDER BY c.sort_order ASC, c.name ASC');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function getCategory(int $id): ?array
    {
        $db = Database::instance();
        $stmt = $db->prepare('SELECT id, name AS category, slug, sort_order FROM ' . $this->categoriesTable() . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function countEntries(array $filters = []): int
    {
        $db = Database::instance();
        $table = $this->entriesTable();
        $conditions = [];
        /** @var array<string, array{mixed, int}> $params */
        $params = [];

        $conditions[] = 'trashed_at IS NULL';
        if (($filters['status'] ?? '') === 'active') {
            $conditions[] = 'is_active = 1';
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $conditions[] = 'is_active = 0';
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $searchMode = $this->normalizeSearchMode((string) ($filters['search_mode'] ?? 'default'));
        if ($search !== '') {
            $searchCondition = $this->buildSearchCondition($search, $searchMode, 'count_search');
            if ($searchCondition['sql'] !== '') {
                $conditions[] = $searchCondition['sql'];
                foreach ($searchCondition['params'] as $paramName => $paramConfig) {
                    $params[$paramName] = $paramConfig;
                }
            }
        }

        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $conditions[] = 'category = :count_category';
            $params[':count_category'] = [$category, PDO::PARAM_STR];
        }

        $sql = 'SELECT COUNT(*) FROM ' . $table;
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $db->prepare($sql);
        foreach ($params as $name => [$value, $type]) {
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();

        return (int) $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRelatedEntries(array $entry, int $limit = 5): array
    {
        $db = Database::instance();
        $table = $this->entriesTable();
        $category = trim((string) ($entry['category'] ?? ''));
        $limit = max(1, min(20, $limit));
        $entryId = (int) ($entry['id'] ?? 0);

        if ($category !== '') {
            $stmt = $db->prepare("SELECT * FROM {$table} WHERE is_active = 1 AND trashed_at IS NULL AND id <> :entry_id AND category = :category ORDER BY priority ASC, title ASC LIMIT :entry_limit");
            $stmt->bindValue(':entry_id', $entryId, PDO::PARAM_INT);
            $stmt->bindValue(':category', $category, PDO::PARAM_STR);
            $stmt->bindValue(':entry_limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $db->prepare("SELECT * FROM {$table} WHERE is_active = 1 AND trashed_at IS NULL AND id <> :entry_id ORDER BY priority ASC, title ASC LIMIT :entry_limit");
            $stmt->bindValue(':entry_id', $entryId, PDO::PARAM_INT);
            $stmt->bindValue(':entry_limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRelatedPosts(array $entry, string $locale = 'de', int $limit = 4): array
    {
        $profile = $this->buildRelatedPostSearchProfile($entry);
        if (($profile['phrases'] ?? []) === [] && ($profile['tokens'] ?? []) === []) {
            return $this->getFallbackRelatedPosts($locale, $limit);
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();
        $locale = $this->normalizeContentLocale($locale);
        $tagNamesSql = "COALESCE((SELECT GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ')
            FROM {$prefix}post_tag_rel ptr
            INNER JOIN {$prefix}post_tags t ON t.id = ptr.tag_id
            WHERE ptr.post_id = p.id), '')";
        $localeAvailability = $this->buildPostLocaleAvailabilityExpression('p', $locale);

        $sql = "SELECT
                p.id,
                p.title,
                p.title_en,
                p.slug,
                p.slug_en,
                p.excerpt,
                p.excerpt_en,
                p.content,
                p.content_en,
                p.tags,
                p.published_at,
                p.created_at,
                COALESCE(c.name, '') AS category_name,
                {$tagNamesSql} AS tag_names
            FROM {$prefix}posts p
            LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
            WHERE p.status = 'published' AND {$localeAvailability}
            ORDER BY COALESCE(p.published_at, p.created_at) DESC
            LIMIT 250";

        $rows = $db->get_results($sql) ?: [];
        if (!is_array($rows)) {
            return [];
        }

        $localization = ContentLocalizationService::getInstance();
        $permalinks = PermalinkService::getInstance();
        $posts = [];

        foreach ($rows as $row) {
            $post = is_array($row) ? $row : (array) $row;
            $post = $localization->localizePost($post, $locale);
            $score = $this->scoreRelatedPost($post, $profile);
            if ($score <= 0) {
                continue;
            }

            $post['relevance_score'] = $score;
            $post['relevance_signals'] = $this->collectRelatedPostSignals($post, $profile);
            $post['url'] = $permalinks->buildPostUrl($post, $locale);
            $posts[] = $post;
        }

        usort($posts, static function (array $left, array $right): int {
            $scoreCompare = ((int) ($right['relevance_score'] ?? 0)) <=> ((int) ($left['relevance_score'] ?? 0));
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return strcmp(
                (string) ($right['published_at'] ?? $right['created_at'] ?? ''),
                (string) ($left['published_at'] ?? $left['created_at'] ?? '')
            );
        });

        $posts = array_slice($posts, 0, $limit);
        if (count($posts) >= $limit) {
            return $posts;
        }

        $fallbackPosts = $this->getFallbackRelatedPosts(
            $locale,
            $limit - count($posts),
            array_map(static fn(array $post): int => (int) ($post['id'] ?? 0), $posts)
        );

        return array_slice(array_merge($posts, $fallbackPosts), 0, $limit);
    }

    /**
     * @return array<string, string>
     */
    public function getSettings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $db = Database::instance();
        $table = $this->settingsTable();
        $settings = Defaults::settings();

        $stmt = $db->prepare("SELECT setting_key, setting_value FROM {$table}");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row) || empty($row['setting_key'])) {
                    continue;
                }

                $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
        }

        $this->settingsCache = $settings;

        return $settings;
    }

    public function saveSettings(array $input): array
    {
        $db = Database::instance();
        $table = $this->settingsTable();
        $stmt = $db->prepare("INSERT INTO {$table} (setting_key, setting_value)
            VALUES (:setting_key, :setting_value)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $existingSettings = $this->getSettings();
        $submittedTab = (string) ($input['redirect_section'] ?? $input['redirect_tab'] ?? 'general');

        $checkboxKeys = [
            'enable_autolink',
            'enable_tooltips',
            'enable_output_buffer',
            'nofollow_links',
            'open_links_new_tab',
            'show_nav_link',
            'show_search',
            'show_category_sidebar',
            'show_keyword_badges',
            'show_related_entries',
            'archive_card_accent_bar',
            'single_section_tints',
            'single_section_icons',
            'single_sidebar_sticky',
        ];

        $designChoices = Defaults::designChoices();

        $numberRanges = [
            'max_links_per_page' => [1, 25],
            'related_posts_limit' => [3, 6],
            'content_max_width' => [720, 1600],
            'sidebar_width' => [220, 420],
            'design_border_radius' => [0, 32],
            'archive_excerpt_lines' => [0, 8],
        ];

        $colorDefaults = [
            'design_accent_color' => '#0d9488',
            'design_accent_hover_color' => '#0f766e',
            'design_surface_color' => '#ffffff',
            'design_border_color' => '#dbe1ea',
            'tooltip_background_color' => '#111827',
            'tooltip_text_color' => '#f8fafc',
            'archive_card_bg_color' => '#ffffff',
            'single_section_bg_color' => '#ffffff',
            'single_sidebar_bg_color' => '#f8fafc',
        ];

        $tabKeys = [
            'general' => [
                'enable_autolink',
                'enable_tooltips',
                'enable_output_buffer',
                'max_links_per_page',
                'nofollow_links',
                'open_links_new_tab',
                'archive_title',
                'archive_intro',
                'archive_title_en',
                'archive_intro_en',
                'glossary_title',
                'glossary_intro',
                'glossary_title_en',
                'glossary_intro_en',
                'show_search',
                'show_category_sidebar',
                'show_keyword_badges',
                'show_related_entries',
                'related_posts_limit',
                'show_nav_link',
                'nav_label',
                'nav_label_en',
            ],
            'design' => [
                'content_max_width',
                'sidebar_width',
                'design_accent_color',
                'design_accent_hover_color',
                'design_surface_color',
                'design_border_color',
                'design_border_radius',
                'tooltip_background_color',
                'tooltip_text_color',
                'archive_hero_style',
                'archive_columns',
                'archive_card_style',
                'archive_card_accent_bar',
                'archive_excerpt_lines',
                'archive_card_bg_color',
                'single_hero_style',
                'single_section_style',
                'single_section_tints',
                'single_section_icons',
                'single_section_bg_color',
                'single_sidebar_style',
                'single_sidebar_position',
                'single_sidebar_sticky',
                'single_sidebar_bg_color',
            ],
        ];

        $activeKeys = $tabKeys[$submittedTab] ?? array_keys(Defaults::settings());
        $activeKeyLookup = array_fill_keys($activeKeys, true);

        foreach (Defaults::settings() as $key => $default) {
            $isActiveKey = isset($activeKeyLookup[$key]);
            $value = $existingSettings[$key] ?? $default;

            if ($isActiveKey) {
                if (in_array($key, $checkboxKeys, true)) {
                    $value = $input[$key] ?? '0';
                } else {
                    $value = $input[$key] ?? $value;
                }
            }

            if ($isActiveKey && isset($colorDefaults[$key])) {
                $value = $this->resolveSubmittedColorValue(
                    $input,
                    $key,
                    (string) ($existingSettings[$key] ?? $default),
                    $colorDefaults[$key]
                );
            }

            if (in_array($key, $checkboxKeys, true)) {
                $value = $this->isTruthy($value) ? '1' : '0';
            } elseif (isset($numberRanges[$key])) {
                [$min, $max] = $numberRanges[$key];
                $value = (string) max($min, min($max, (int) $value));
            } elseif (isset($colorDefaults[$key])) {
                $value = $this->sanitizeColor((string) $value, $colorDefaults[$key]);
            } elseif (isset($designChoices[$key])) {
                $value = Defaults::designChoice([$key => (string) $value], $key);
            } elseif ($key === 'nav_label') {
                $value = $this->sanitizeText((string) $value, 40);
            } elseif ($key === 'archive_title') {
                $value = $this->sanitizeText((string) $value, 120);
            } elseif ($key === 'archive_title_en') {
                $value = $this->sanitizeText((string) $value, 120);
            } elseif ($key === 'glossary_title') {
                $value = $this->sanitizeText((string) $value, 120);
            } elseif ($key === 'glossary_title_en') {
                $value = $this->sanitizeText((string) $value, 120);
            } elseif ($key === 'archive_intro') {
                $value = $this->sanitizeTextarea((string) $value);
            } elseif ($key === 'archive_intro_en') {
                $value = $this->sanitizeTextarea((string) $value);
            } elseif ($key === 'glossary_intro') {
                $value = $this->sanitizeTextarea((string) $value);
            } elseif ($key === 'glossary_intro_en') {
                $value = $this->sanitizeTextarea((string) $value);
            } elseif ($key === 'nav_label_en') {
                $value = $this->sanitizeText((string) $value, 40);
            } else {
                $value = $this->sanitizeText((string) $value, 1000);
            }

            $normalizedValue = (string) $value;
            if (($existingSettings[$key] ?? (string) $default) === $normalizedValue) {
                continue;
            }

            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => $normalizedValue,
            ]);
        }

        $this->resetCaches();

        return ['success' => true, 'message' => (string) __('knowledgebase.admin.settings_saved')];
    }

    private function resolveSubmittedColorValue(array $input, string $key, string $currentValue, string $fallback): string
    {
        $pickerValue = trim((string) ($input[$key] ?? ''));
        $textValue = trim((string) ($input[$key . '_text'] ?? ''));
        $normalizedCurrent = strtoupper($this->sanitizeColor($currentValue, $fallback));
        $normalizedPicker = preg_match('/^#[0-9A-Fa-f]{6}$/', $pickerValue) === 1 ? strtoupper($pickerValue) : '';
        $normalizedText = preg_match('/^#[0-9A-Fa-f]{6}$/', $textValue) === 1 ? strtoupper($textValue) : '';

        if ($normalizedPicker !== '' && $normalizedPicker !== $normalizedCurrent && ($normalizedText === '' || $normalizedText === $normalizedCurrent)) {
            return $normalizedPicker;
        }

        if ($normalizedText !== '') {
            return $normalizedText;
        }

        if ($normalizedPicker !== '') {
            return $normalizedPicker;
        }

        return $fallback;
    }

    /**
     * @return array<string, string>
     */
    public function getPublicDesignTokens(): array
    {
        $settings = $this->getSettings();

        return [
            '--kb-accent' => $this->sanitizeColor((string) ($settings['design_accent_color'] ?? ''), '#0d9488'),
            '--kb-accent-hover' => $this->sanitizeColor((string) ($settings['design_accent_hover_color'] ?? ''), '#0f766e'),
            '--kb-surface' => $this->sanitizeColor((string) ($settings['design_surface_color'] ?? ''), '#ffffff'),
            '--kb-border' => $this->sanitizeColor((string) ($settings['design_border_color'] ?? ''), '#dbe1ea'),
            '--kb-radius' => (string) max(0, min(32, (int) ($settings['design_border_radius'] ?? 14))) . 'px',
            '--kb-content-max-width' => (string) max(720, min(1600, (int) ($settings['content_max_width'] ?? 1200))) . 'px',
            '--kb-sidebar-width' => (string) max(220, min(420, (int) ($settings['sidebar_width'] ?? 300))) . 'px',
            '--kb-tooltip-bg' => $this->sanitizeColor((string) ($settings['tooltip_background_color'] ?? ''), '#111827'),
            '--kb-tooltip-text' => $this->sanitizeColor((string) ($settings['tooltip_text_color'] ?? ''), '#f8fafc'),
            '--kb-card-bg' => $this->sanitizeColor((string) ($settings['archive_card_bg_color'] ?? ''), '#ffffff'),
            '--kb-section-bg' => $this->sanitizeColor((string) ($settings['single_section_bg_color'] ?? ''), '#ffffff'),
            '--kb-sidebar-bg' => $this->sanitizeColor((string) ($settings['single_sidebar_bg_color'] ?? ''), '#f8fafc'),
            '--kb-excerpt-lines' => (string) max(0, min(8, (int) ($settings['archive_excerpt_lines'] ?? 3))),
        ];
    }

    /**
     * @param bool $createMissingCategory Importe legen fehlende Kategorien an; der Admin-Editor erlaubt nur bestehende.
     */
    public function saveEntry(array $input, bool $createMissingCategory = true): array
    {
        $db = Database::instance();
        $table = $this->entriesTable();
        $id = (int) ($input['entry_id'] ?? 0);
        $title = $this->sanitizeText((string) ($input['title'] ?? ''), 255);
        $keyword = $this->sanitizeText((string) ($input['keyword'] ?? ''), 190);
        $slugInput = $this->sanitizeText((string) ($input['slug'] ?? ''), 190);
        $slugSource = $slugInput !== '' ? $slugInput : ($title !== '' ? $title : $keyword);
        $slug = $this->generateUniqueSlug($slugSource, $id);
        $excerpt = $this->sanitizeTextarea((string) ($input['excerpt'] ?? ''));
        $sectionContent = SectionContent::instance();
        $sections = [];
        foreach (ContentSections::columns() as $column) {
            $sections[$column] = $sectionContent->sanitizeForStorage((string) ($input[$column] ?? ''));
        }
        $tooltipText = $this->sanitizeTextarea((string) ($input['tooltip_text'] ?? ''));
        $synonyms = $this->sanitizeSynonyms((string) ($input['synonyms'] ?? ''));
        $category = $this->sanitizeText((string) ($input['category'] ?? ''), 120);
        $priority = max(1, min(9999, (int) ($input['priority'] ?? 100)));
        $isActive = $this->isTruthy($input['is_active'] ?? '0') ? 1 : 0;
        $caseSensitive = $this->isTruthy($input['is_case_sensitive'] ?? '0') ? 1 : 0;
        $wholeWord = $this->isTruthy($input['is_whole_word'] ?? '1') ? 1 : 0;
        $maxLinksPerPage = max(1, min(20, (int) ($input['max_links_per_page'] ?? 1)));

        if ($title === '' || $keyword === '') {
            return ['success' => false, 'error' => (string) __('knowledgebase.admin.error_title_keyword')];
        }

        if ($category !== '') {
            if ($createMissingCategory) {
                $this->ensureCategoryExists($category);
            } elseif (!$this->categoryExists($category)) {
                return ['success' => false, 'error' => (string) __('knowledgebase.admin.error_category_unknown')];
            }
        }

        $params = [
            'title' => $title,
            'keyword' => $keyword,
            'slug' => $slug,
            'excerpt' => $excerpt,
            ...$sections,
            'tooltip_text' => $tooltipText,
            'synonyms' => $synonyms,
            'category' => $category,
            'priority' => $priority,
            'is_active' => $isActive,
            'is_case_sensitive' => $caseSensitive,
            'is_whole_word' => $wholeWord,
            'max_links_per_page' => $maxLinksPerPage,
        ];
        $columns = array_keys($params);

        if ($id > 0) {
            $assignments = implode(', ', array_map(static fn(string $column): string => "{$column} = :{$column}", $columns));
            $stmt = $db->prepare("UPDATE {$table} SET {$assignments} WHERE id = :id");
            $stmt->execute([...$params, 'id' => $id]);
            $this->resetCaches();

            $this->logger->info('Knowledgebase-Eintrag aktualisiert.', ['entry_id' => $id, 'slug' => $slug]);
            \CMS\Hooks::doAction('cms_knowledgebase_entry_saved', $id);

            return ['success' => true, 'message' => (string) __('knowledgebase.admin.entry_updated'), 'id' => $id];
        }

        $stmt = $db->prepare("INSERT INTO {$table} (" . implode(', ', $columns) . ')
            VALUES (:' . implode(', :', $columns) . ')');
        $stmt->execute($params);
        $this->resetCaches();

        $newId = (int) $db->lastInsertId();
        $this->logger->info('Knowledgebase-Eintrag erstellt.', ['entry_id' => $newId, 'slug' => $slug]);
        \CMS\Hooks::doAction('cms_knowledgebase_entry_saved', $newId);

        return ['success' => true, 'message' => (string) __('knowledgebase.admin.entry_created'), 'id' => $newId];
    }

    public function sanitizePublicRichText(string $value): string
    {
        return $this->sanitizeRichText($value);
    }

    public function saveCategory(array $input): array
    {
        $db = Database::instance();
        $table = $this->categoriesTable();
        $id = (int) ($input['category_id'] ?? 0);
        $name = $this->sanitizeText((string) ($input['category_name'] ?? ''), 120);
        $sortOrder = max(0, min(9999, (int) ($input['sort_order'] ?? 0)));
        $current = $id > 0 ? $this->getCategory($id) : null;

        if ($name === '') {
            return ['success' => false, 'error' => (string) __('knowledgebase.admin.error_category_name')];
        }

        $duplicateQuery = 'SELECT id FROM ' . $table . ' WHERE name = ?';
        $duplicateParams = [$name];
        if ($id > 0) {
            $duplicateQuery .= ' AND id <> ?';
            $duplicateParams[] = $id;
        }
        $duplicateQuery .= ' LIMIT 1';
        $duplicateStmt = $db->prepare($duplicateQuery);
        $duplicateStmt->execute($duplicateParams);
        if (is_array($duplicateStmt->fetch(PDO::FETCH_ASSOC))) {
            return ['success' => false, 'error' => (string) __('knowledgebase.admin.error_category_duplicate')];
        }

        $slug = $this->generateUniqueCategorySlug($name, $id);

        if ($id > 0 && $current !== null) {
            $stmt = $db->prepare("UPDATE {$table} SET name = :name, slug = :slug, sort_order = :sort_order WHERE id = :id");
            $stmt->execute([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => $sortOrder,
            ]);

            if (((string) ($current['category'] ?? '')) !== $name) {
                $entryStmt = $db->prepare('UPDATE ' . $this->entriesTable() . ' SET category = ? WHERE category = ?');
                $entryStmt->execute([$name, (string) ($current['category'] ?? '')]);
            }
            $this->resetCaches();

            return ['success' => true, 'message' => (string) __('knowledgebase.admin.category_updated'), 'id' => $id];
        }

        $stmt = $db->prepare("INSERT INTO {$table} (name, slug, sort_order) VALUES (:name, :slug, :sort_order)");
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
            'sort_order' => $sortOrder,
        ]);
        $this->resetCaches();

        return ['success' => true, 'message' => (string) __('knowledgebase.admin.category_created'), 'id' => (int) $db->lastInsertId()];
    }

    public function deleteCategory(int $id): array
    {
        $category = $this->getCategory($id);
        if ($category === null) {
            return ['success' => false, 'error' => (string) __('knowledgebase.admin.error_category_not_found')];
        }

        $db = Database::instance();
        $entryStmt = $db->prepare('UPDATE ' . $this->entriesTable() . ' SET category = NULL WHERE category = ?');
        $entryStmt->execute([(string) ($category['category'] ?? '')]);

        $deleteStmt = $db->prepare('DELETE FROM ' . $this->categoriesTable() . ' WHERE id = ?');
        $deleteStmt->execute([$id]);
        $this->resetCaches();

        return ['success' => true, 'message' => (string) __('knowledgebase.admin.category_deleted')];
    }

    /**
     * Eintrag in den Papierkorb legen (verschwindet aus Frontend, Auto-Verlinkung und Suche).
     *
     * @return array{success: bool, message?: string, error?: string}
     */
    public function trashEntry(int $id, int $userId): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => (string) __('knowledgebase.admin.error_not_found')];
        }
        $stmt = Database::instance()->prepare('UPDATE ' . $this->entriesTable() . ' SET trashed_at = NOW(), trashed_by = ? WHERE id = ? AND trashed_at IS NULL');
        $stmt->execute([$userId > 0 ? $userId : null, $id]);
        $this->resetCaches();
        if ($stmt->rowCount() < 1) {
            return ['success' => false, 'error' => (string) __('knowledgebase.admin.error_not_found')];
        }
        \CMS\Hooks::doAction('cms_knowledgebase_entry_trashed', $id);

        return ['success' => true, 'message' => (string) __('knowledgebase.admin.entry_trashed')];
    }

    /** Aus dem Papierkorb zurückholen; liefert den (unveränderten) Slug. */
    public function restoreEntry(int $id): bool
    {
        $stmt = Database::instance()->prepare('UPDATE ' . $this->entriesTable() . ' SET trashed_at = NULL, trashed_by = NULL WHERE id = ? AND trashed_at IS NOT NULL');
        $stmt->execute([$id]);
        $this->resetCaches();
        if ($stmt->rowCount() > 0) {
            \CMS\Hooks::doAction('cms_knowledgebase_entry_saved', $id);

            return true;
        }

        return false;
    }

    public function deleteEntry(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => (string) __('knowledgebase.admin.error_not_found')];
        }

        $db = Database::instance();
        $stmt = $db->prepare('DELETE FROM ' . $this->entriesTable() . ' WHERE id = ?');
        $stmt->execute([$id]);
        $this->resetCaches();

        $this->logger->info('Knowledgebase-Eintrag gelöscht.', ['entry_id' => $id]);
        \CMS\Hooks::doAction('cms_knowledgebase_entry_deleted', $id);

        return ['success' => true, 'message' => (string) __('knowledgebase.admin.entry_deleted')];
    }

    public function hardResetEntries(): array
    {
        $db = Database::instance();
        $table = $this->entriesTable();
        $deleted = $this->getDashboardStats()['entries'] ?? 0;

        $stmt = $db->prepare('DELETE FROM ' . $table);
        $stmt->execute();

        try {
            $db->getPdo()->exec('ALTER TABLE ' . $table . ' AUTO_INCREMENT = 1');
        } catch (\Throwable) {
            // AUTO_INCREMENT-Reset ist optional.
        }

        $this->logger->warning('Knowledgebase-Hardreset ausgeführt.', ['deleted_entries' => (int) $deleted]);
        $this->resetCaches();

        return [
            'success' => true,
            'message' => sprintf((string) __('knowledgebase.admin.hard_reset_done'), (int) $deleted),
        ];
    }

    public function saveCsvImportStatus(int $importedCount, int $created, int $updated, int $skipped, string $scope = 'all'): void
    {
        $timestamp = function_exists('gmdate') ? gmdate('c') : date('c');

        $this->saveSettingValue('csv_last_import_at', $timestamp);
        $this->saveSettingValue('csv_last_import_count', (string) max(0, $importedCount));
        $this->saveSettingValue('csv_last_import_created', (string) max(0, $created));
        $this->saveSettingValue('csv_last_import_updated', (string) max(0, $updated));
        $this->saveSettingValue('csv_last_import_skipped', (string) max(0, $skipped));
        $this->saveSettingValue('csv_last_import_scope', $this->sanitizeText($scope, 120));
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, int>
     */
    public function importPresetEntries(array $entries, string $packageKey = ''): array
    {
        if ($entries === []) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $normalizedEntries = [];
        $slugs = [];

        foreach ($entries as $index => $entry) {
            $slugSource = (string) ($entry['slug'] ?? $entry['title'] ?? $entry['keyword'] ?? ('knowledgebase-entry-' . ($index + 1)));
            $slug = $this->slugify($slugSource);
            if ($slug === '') {
                $slug = 'knowledgebase-entry-' . ($index + 1);
            }

            $entry['slug'] = $slug;
            $normalizedEntries[] = $entry;
            $slugs[] = $slug;
        }

        $existingEntries = $this->getExistingEntriesBySlug($slugs);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($normalizedEntries as $entry) {
            $slug = (string) ($entry['slug'] ?? '');
            $existingEntry = $slug !== '' ? ($existingEntries[$slug] ?? null) : null;
            if (is_array($existingEntry)) {
                $result = $this->saveEntry($this->mergeImportedEntry($existingEntry, $entry));

                if ((bool) ($result['success'] ?? false)) {
                    ++$updated;
                    continue;
                }

                ++$errors;
                continue;
            }

            $result = $this->saveEntry($entry);
            if ((bool) ($result['success'] ?? false)) {
                ++$created;
                continue;
            }

            ++$errors;
        }

        $this->logger->info('Knowledgebase-Preset-Import verarbeitet.', [
            'package' => $packageKey,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Bereitet einen Import-Datensatz für einen bereits vorhandenen Eintrag vor.
     *
     * Mit `import_fields` überschreibt der Import nur die dort genannten Felder; alle anderen
     * Werte (z. B. manuell gepflegte Inhaltsbereiche, Synonyme oder Status) bleiben erhalten.
     * Ohne `import_fields` bleiben wie bisher Status, Priorität und Verlinkungsregeln erhalten.
     *
     * @param array<string, mixed> $existingEntry
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function mergeImportedEntry(array $existingEntry, array $entry): array
    {
        $payload = [...$entry, 'entry_id' => (string) ($existingEntry['id'] ?? 0)];
        unset($payload['import_fields']);

        if (!is_array($entry['import_fields'] ?? null)) {
            return [
                ...$payload,
                'is_active' => (string) ($existingEntry['is_active'] ?? $entry['is_active'] ?? '1'),
                'priority' => (string) ($existingEntry['priority'] ?? $entry['priority'] ?? '100'),
                'is_case_sensitive' => (string) ($existingEntry['is_case_sensitive'] ?? $entry['is_case_sensitive'] ?? '0'),
                'is_whole_word' => (string) ($existingEntry['is_whole_word'] ?? $entry['is_whole_word'] ?? '1'),
                'max_links_per_page' => (string) ($existingEntry['max_links_per_page'] ?? $entry['max_links_per_page'] ?? '1'),
            ];
        }

        $importFields = array_map('strval', $entry['import_fields']);
        $preservableFields = [
            'keyword',
            'excerpt',
            'tooltip_text',
            'synonyms',
            'category',
            'priority',
            'max_links_per_page',
            'is_active',
            'is_whole_word',
            'is_case_sensitive',
            ...ContentSections::columns(),
        ];

        foreach (array_diff($preservableFields, $importFields) as $field) {
            if (!isset($existingEntry[$field])) {
                continue;
            }

            $existingValue = (string) $existingEntry[$field];
            // Der Fokusbegriff ist Pflicht; ist er leer, greift der Importwert (Titel).
            if ($field === 'keyword' && trim($existingValue) === '') {
                continue;
            }

            $payload[$field] = $existingValue;
        }

        return $payload;
    }

    private function entriesTable(): string
    {
        return Database::instance()->getPrefix() . 'kb_entries';
    }

    private function categoriesTable(): string
    {
        return Database::instance()->getPrefix() . 'kb_categories';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getEntriesByColumns(string $columns, array $filters = []): array
    {
        $db = Database::instance();
        $table = $this->entriesTable();
        if (preg_match('/^[a-z0-9_,\s*]+$/i', $columns) !== 1) {
            $this->logger->warning('Unsichere Spaltenliste für Knowledgebase-Abfrage verworfen.', ['columns' => $columns]);
            return [];
        }

        $conditions = [];
        /** @var array<string, array{mixed, int}> $params */
        $params = [];

        $conditions[] = 'trashed_at IS NULL';
        if (($filters['status'] ?? '') === 'active') {
            $conditions[] = 'is_active = 1';
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $conditions[] = 'is_active = 0';
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $searchMode = $this->normalizeSearchMode((string) ($filters['search_mode'] ?? 'default'));
        if ($search !== '') {
            $searchCondition = $this->buildSearchCondition($search, $searchMode, 'entry_search');
            if ($searchCondition['sql'] !== '') {
                $conditions[] = $searchCondition['sql'];
                foreach ($searchCondition['params'] as $paramName => $paramConfig) {
                    $params[$paramName] = $paramConfig;
                }
            }
        }

        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $conditions[] = 'category = :category';
            $params[':category'] = [$category, PDO::PARAM_STR];
        }

        $sql = "SELECT {$columns} FROM {$table}";
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY is_active DESC, priority ASC, title ASC';

        $limit = isset($filters['limit']) ? max(1, min(200, (int) $filters['limit'])) : 0;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        if ($limit > 0) {
            $sql .= ' LIMIT :entry_limit';
            $params[':entry_limit'] = [$limit, PDO::PARAM_INT];
            if ($offset > 0) {
                $sql .= ' OFFSET :entry_offset';
                $params[':entry_offset'] = [$offset, PDO::PARAM_INT];
            }
        }

        $stmt = $db->prepare($sql);
        foreach ($params as $name => [$value, $type]) {
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    private function normalizeSearchMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, ['default', 'strict'], true) ? $mode : 'default';
    }

    /**
     * @return array{sql: string, params: array<string, array{mixed, int}>}
     */
    private function buildSearchCondition(string $search, string $searchMode, string $paramPrefix): array
    {
        $needle = '%' . $search . '%';
        $sqlParts = [
            "title LIKE :{$paramPrefix}_title",
            "keyword LIKE :{$paramPrefix}_keyword",
            "synonyms LIKE :{$paramPrefix}_synonyms",
            "excerpt LIKE :{$paramPrefix}_excerpt",
        ];
        /** @var array<string, array{mixed, int}> $params */
        $params = [
            ":{$paramPrefix}_title" => [$needle, PDO::PARAM_STR],
            ":{$paramPrefix}_keyword" => [$needle, PDO::PARAM_STR],
            ":{$paramPrefix}_synonyms" => [$needle, PDO::PARAM_STR],
            ":{$paramPrefix}_excerpt" => [$needle, PDO::PARAM_STR],
        ];

        if ($searchMode === 'default') {
            $tokens = preg_split('/\s+/u', mb_strtolower($search, 'UTF-8')) ?: [];
            $tokens = array_values(array_unique(array_filter(array_map(
                static fn(string $token): string => trim($token),
                $tokens
            ), static fn(string $token): bool => mb_strlen($token, 'UTF-8') >= 3)));

            foreach ($tokens as $index => $token) {
                // Native Prepared Statements erlauben jeden benannten Parameter nur einmal.
                $paramKeyword = ":{$paramPrefix}_token_{$index}_kw";
                $paramTitle = ":{$paramPrefix}_token_{$index}_ti";
                $paramTokenNeedle = ":{$paramPrefix}_token_{$index}_needle";
                $sqlParts[] = "SOUNDEX(keyword) = SOUNDEX({$paramKeyword})";
                $sqlParts[] = "SOUNDEX(title) = SOUNDEX({$paramTitle})";
                $sqlParts[] = "synonyms LIKE {$paramTokenNeedle}";
                $params[$paramKeyword] = [$token, PDO::PARAM_STR];
                $params[$paramTitle] = [$token, PDO::PARAM_STR];
                $params[$paramTokenNeedle] = ['%' . $token . '%', PDO::PARAM_STR];
            }
        }

        return [
            'sql' => '(' . implode(' OR ', $sqlParts) . ')',
            'params' => $params,
        ];
    }

    private function settingsTable(): string
    {
        return Database::instance()->getPrefix() . 'kb_settings';
    }

    private function saveSettingValue(string $key, string $value): void
    {
        $db = Database::instance();
        $table = $this->settingsTable();
        $stmt = $db->prepare("INSERT INTO {$table} (setting_key, setting_value)
            VALUES (:setting_key, :setting_value)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([
            'setting_key' => $key,
            'setting_value' => $value,
        ]);
        $this->settingsCache = null;
    }

    private function generateUniqueSlug(string $source, int $ignoreId = 0): string
    {
        $slug = $this->slugify($source);
        if ($slug === '') {
            $slug = 'knowledgebase-entry';
        }

        $db = Database::instance();
        $table = $this->entriesTable();
        $candidate = $slug;
        $suffix = 2;

        while (true) {
            $query = "SELECT id FROM {$table} WHERE slug = ?";
            $params = [$candidate];
            if ($ignoreId > 0) {
                $query .= ' AND id <> ?';
                $params[] = $ignoreId;
            }
            $query .= ' LIMIT 1';

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($exists)) {
                return $candidate;
            }

            $candidate = $slug . '-' . $suffix;
            ++$suffix;
        }
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
        $value = trim($value, '-');
        return mb_substr($value, 0, 190, 'UTF-8');
    }

    /**
     * @param array<int, string> $slugs
     * @return array<string, bool>
     */
    private function getExistingEntriesBySlug(array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter(array_map(
            fn(string $slug): string => $this->slugify($slug),
            $slugs
        ))));

        if ($slugs === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($slugs), '?'));
        $stmt = Database::instance()->prepare('SELECT * FROM ' . $this->entriesTable() . " WHERE slug IN ({$placeholders})");
        $stmt->execute($slugs);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lookup = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row) || empty($row['slug'])) {
                    continue;
                }

                $lookup[(string) $row['slug']] = $row;
            }
        }

        return $lookup;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function isGeneratedPlaceholderEntry(array $entry): bool
    {
        $excerpt = trim((string) ($entry['excerpt'] ?? ''));
        $tooltip = trim((string) ($entry['tooltip_text'] ?? ''));
        $content = trim((string) ($entry['content'] ?? ''));

        if ($excerpt === '' && $tooltip === '' && $content === '') {
            return true;
        }

        $markers = [
            'kompakt erklärt – als Startpunkt für interne Verlinkung',
            'ist ein Standardbegriff aus dem Bereich',
            'kurz erklärt – ein Standardbegriff',
            'Nutze diesen Standardartikel als Startpunkt',
            '<h2>Typischer Einsatz</h2>',
            '<h2>Worauf sollte man achten?</h2>',
            '<h2>Praxis-Hinweis</h2>',
            '<h2>Lizenz &amp; Verfügbarkeit</h2>',
            'Standardpaket-Generator',
            'allgemeine Einordnung, Lizenz- und Verfügbarkeitsinfos',
        ];

        $haystack = $excerpt . "\n" . $tooltip . "\n" . $content;
        foreach ($markers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function buildRelatedPostTerms(array $entry): array
    {
        $terms = [];

        foreach (['keyword', 'title', 'category', 'slug'] as $field) {
            $value = trim((string) ($entry[$field] ?? ''));
            if ($value !== '') {
                $terms[] = $value;
            }
        }

        $synonyms = preg_split('/[\r\n,]+/', (string) ($entry['synonyms'] ?? '')) ?: [];
        foreach ($synonyms as $synonym) {
            $synonym = trim((string) $synonym);
            if ($synonym !== '' && mb_strlen($synonym, 'UTF-8') >= 3) {
                $terms[] = $synonym;
            }
        }

        $terms = array_values(array_unique(array_filter(array_map(
            static fn(string $term): string => trim($term),
            $terms
        ), static fn(string $term): bool => $term !== '' && mb_strlen($term, 'UTF-8') >= 3)));

        usort($terms, static fn(string $left, string $right): int => mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8'));

        return array_slice($terms, 0, 8);
    }

    /**
     * @return array{phrases: array<int, string>, tokens: array<int, string>}
     */
    private function buildRelatedPostSearchProfile(array $entry): array
    {
        $phrases = $this->buildRelatedPostTerms($entry);
        $tokens = [];

        foreach ($phrases as $phrase) {
            $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($phrase, 'UTF-8')) ?: [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }

                if (mb_strlen($part, 'UTF-8') < 2) {
                    continue;
                }

                if (preg_match('/^\d+$/', $part) === 1 && mb_strlen($part, 'UTF-8') < 4) {
                    continue;
                }

                if (in_array($part, ['oder', 'aber', 'eine', 'einer', 'einem', 'einen', 'einer', 'der', 'die', 'das', 'dem', 'den', 'und', 'mit', 'für', 'fur', 'the', 'and', 'for', 'von', 'aus', 'bei', 'ein', 'eine', 'ist', 'are'], true)) {
                    continue;
                }

                $tokens[] = $part;
            }
        }

        $tokens = array_values(array_unique($tokens));
        usort($tokens, static fn(string $left, string $right): int => mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8'));

        return [
            'phrases' => $phrases,
            'tokens' => array_slice($tokens, 0, 18),
        ];
    }

    /**
     * @param array{phrases: array<int, string>, tokens: array<int, string>} $profile
     */
    private function scoreRelatedPost(array $post, array $profile): int
    {
        $title = mb_strtolower(trim((string) ($post['title'] ?? '')), 'UTF-8');
        $excerpt = mb_strtolower(trim((string) ($post['excerpt'] ?? '')), 'UTF-8');
        $content = mb_strtolower(trim(strip_tags((string) ($post['content'] ?? ''))), 'UTF-8');
        $category = mb_strtolower(trim((string) ($post['category_name'] ?? '')), 'UTF-8');
        $tags = mb_strtolower(trim((string) (($post['tag_names'] ?? '') !== '' ? $post['tag_names'] : ($post['tags'] ?? ''))), 'UTF-8');

        $score = 0;

        foreach ($profile['phrases'] as $phrase) {
            $needle = mb_strtolower($phrase, 'UTF-8');
            if ($needle === '') {
                continue;
            }

            if ($title !== '' && str_contains($title, $needle)) {
                $score += 140;
            }
            if ($excerpt !== '' && str_contains($excerpt, $needle)) {
                $score += 55;
            }
            if ($category !== '' && str_contains($category, $needle)) {
                $score += 90;
            }
            if ($tags !== '' && str_contains($tags, $needle)) {
                $score += 84;
            }
            if ($content !== '' && str_contains($content, $needle)) {
                $score += 16;
            }
        }

        foreach ($profile['tokens'] as $token) {
            if ($token === '') {
                continue;
            }

            if ($title !== '' && str_contains($title, $token)) {
                $score += 24;
            }
            if ($excerpt !== '' && str_contains($excerpt, $token)) {
                $score += 10;
            }
            if ($category !== '' && str_contains($category, $token)) {
                $score += 28;
            }
            if ($tags !== '' && str_contains($tags, $token)) {
                $score += 24;
            }
            if ($content !== '' && str_contains($content, $token)) {
                $score += 4;
            }
        }

        return $score;
    }

    private function normalizeContentLocale(string $locale): string
    {
        $normalized = ContentLocalizationService::getInstance()->normalizeLocale($locale);

        return $normalized !== '' ? $normalized : 'de';
    }

    private function buildPostLocaleAvailabilityExpression(string $alias, string $locale): string
    {
        $locale = $this->normalizeContentLocale($locale);
        $baseContent = $this->buildBasePostContentExpression($alias);
        $englishLegacyOnly = $this->buildLegacyEnglishOnlyPostExpression($alias);

        if ($locale === 'de') {
            return "{$baseContent} AND NOT {$englishLegacyOnly}";
        }

        if ($locale === 'en') {
            $localizedContent = $this->buildLocalizedPostContentExpression($alias, 'en');
            return "({$localizedContent} OR {$englishLegacyOnly})";
        }

        return '1=1';
    }

    private function buildBasePostContentExpression(string $alias): string
    {
        return "(CHAR_LENGTH(TRIM(COALESCE({$alias}.content, ''))) > 0"
            . " OR CHAR_LENGTH(TRIM(COALESCE({$alias}.excerpt, ''))) > 0"
            . " OR CHAR_LENGTH(TRIM(COALESCE({$alias}.title, ''))) > 0)";
    }

    private function buildLocalizedPostContentExpression(string $alias, string $locale): string
    {
        return "(CHAR_LENGTH(TRIM(COALESCE({$alias}.content_{$locale}, ''))) > 0"
            . " OR CHAR_LENGTH(TRIM(COALESCE({$alias}.excerpt_{$locale}, ''))) > 0"
            . " OR CHAR_LENGTH(TRIM(COALESCE({$alias}.title_{$locale}, ''))) > 0)";
    }

    private function buildLegacyEnglishOnlyPostExpression(string $alias): string
    {
        $englishContent = $this->buildLocalizedPostContentExpression($alias, 'en');

        return "(CHAR_LENGTH(TRIM(COALESCE({$alias}.slug_en, ''))) > 0 AND NOT {$englishContent})";
    }

    /**
     * @param array{phrases: array<int, string>, tokens: array<int, string>} $profile
     * @return array<int, string>
     */
    private function collectRelatedPostSignals(array $post, array $profile): array
    {
        $signals = [];
        $title = mb_strtolower(trim((string) ($post['title'] ?? '')), 'UTF-8');
        $category = mb_strtolower(trim((string) ($post['category_name'] ?? '')), 'UTF-8');
        $tags = mb_strtolower(trim((string) (($post['tag_names'] ?? '') !== '' ? $post['tag_names'] : ($post['tags'] ?? ''))), 'UTF-8');
        $excerpt = mb_strtolower(trim((string) ($post['excerpt'] ?? '')), 'UTF-8');

        foreach ($profile['phrases'] as $phrase) {
            $needle = mb_strtolower($phrase, 'UTF-8');
            if ($needle === '') {
                continue;
            }

            if ($category !== '' && str_contains($category, $needle)) {
                $signals[] = 'Kategorie-Match';
            }
            if ($tags !== '' && str_contains($tags, $needle)) {
                $signals[] = 'Tag-Match';
            }
            if ($title !== '' && str_contains($title, $needle)) {
                $signals[] = 'Titel-Match';
            }
            if ($excerpt !== '' && str_contains($excerpt, $needle)) {
                $signals[] = 'Inhalts-Match';
            }
        }

        return array_values(array_unique($signals));
    }

    private function sanitizeText(string $value, int $maxLength = 255): string
    {
        $value = function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim(strip_tags($value));
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    private function sanitizeTextarea(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = trim(strip_tags($value));
        return mb_substr($value, 0, 2000, 'UTF-8');
    }

    private function sanitizeRichText(string $value): string
    {
        $html = trim(strip_tags($value, '<div><p><a><strong><em><ul><ol><li><br><blockquote><code><pre><h2><h3><h4><table><thead><tbody><tfoot><tr><th><td><caption><colgroup><col>'));
        if ($html === '') {
            return '';
        }

        if (!class_exists(\DOMDocument::class)) {
            return $this->stripRichTextAttributes($html);
        }

        return $this->sanitizeRichTextWithDom($html);
    }

    private function sanitizeRichTextWithDom(string $html): string
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="kb-richtext-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );

        if ($loaded !== true) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return $this->stripRichTextAttributes($html);
        }

        $root = $dom->getElementById('kb-richtext-root');
        if (!$root instanceof \DOMElement) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return $this->stripRichTextAttributes($html);
        }

        $this->sanitizeRichTextNode($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child) ?: '';
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return trim($output);
    }

    private function sanitizeRichTextNode(\DOMNode $node): void
    {
        if ($node instanceof \DOMElement) {
            $allowedAttributes = $node->tagName === 'a' ? ['href', 'title', 'target', 'rel'] : [];
            if (in_array($node->tagName, ['td', 'th'], true)) {
                $allowedAttributes = ['colspan', 'rowspan'];
            }

            foreach (iterator_to_array($node->attributes ?? []) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim((string) $attribute->nodeValue);
                if (str_starts_with($name, 'on') || !in_array($name, $allowedAttributes, true)) {
                    $node->removeAttribute($attribute->nodeName);
                    continue;
                }

                if ($node->tagName === 'a' && $name === 'href' && !$this->isSafeRichTextUrl($value)) {
                    $node->removeAttribute('href');
                    continue;
                }

                if ($node->tagName === 'a' && $name === 'target' && $value !== '_blank') {
                    $node->removeAttribute('target');
                    continue;
                }

                if ($name === 'title') {
                    $node->setAttribute('title', mb_substr(strip_tags($value), 0, 180, 'UTF-8'));
                }

                if (in_array($name, ['colspan', 'rowspan'], true)) {
                    $node->setAttribute($name, (string) max(1, min(12, (int) $value)));
                }
            }

            if ($node->tagName === 'a' && $node->getAttribute('target') === '_blank') {
                $relParts = preg_split('/\s+/', strtolower($node->getAttribute('rel'))) ?: [];
                $relParts = array_values(array_unique(array_filter(array_merge($relParts, ['noopener', 'noreferrer']))));
                $node->setAttribute('rel', implode(' ', $relParts));
            }
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->sanitizeRichTextNode($child);
        }
    }

    private function stripRichTextAttributes(string $html): string
    {
        return preg_replace('/<([a-z0-9]+)(?:\s[^>]*)?>/i', '<$1>', $html) ?? '';
    }

    private function isSafeRichTextUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '#')) {
            return true;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if (($parts['user'] ?? '') !== '' || ($parts['pass'] ?? '') !== '') {
            return false;
        }

        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    /**
     * @param array<int, int> $excludeIds
     * @return array<int, array<string, mixed>>
     */
    private function getFallbackRelatedPosts(string $locale, int $limit, array $excludeIds = []): array
    {
        if ($limit <= 0) {
            return [];
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();
        $locale = $this->normalizeContentLocale($locale);
        $localeAvailability = $this->buildPostLocaleAvailabilityExpression('p', $locale);
        $conditions = ["p.status = 'published'", $localeAvailability];
        /** @var array<string, int> $params */
        $params = [];

        $excludeIds = array_values(array_filter(array_map(static fn(mixed $id): int => (int) $id, $excludeIds)));
        if ($excludeIds !== []) {
            $placeholders = [];
            foreach ($excludeIds as $index => $excludeId) {
                $placeholder = ':exclude_id_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $excludeId;
            }
            $conditions[] = 'p.id NOT IN (' . implode(', ', $placeholders) . ')';
        }

        $sql = "SELECT
                p.id,
                p.title,
                p.title_en,
                p.slug,
                p.slug_en,
                p.excerpt,
                p.excerpt_en,
                p.content,
                p.content_en,
                p.tags,
                p.published_at,
                p.created_at,
                COALESCE(c.name, '') AS category_name
            FROM {$prefix}posts p
            LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY COALESCE(p.published_at, p.created_at) DESC
            LIMIT :fallback_limit";

        $stmt = $db->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':fallback_limit', max(1, $limit * 3), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $localization = ContentLocalizationService::getInstance();
        $permalinks = PermalinkService::getInstance();
        $posts = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $post = $localization->localizePost($row, $locale);
            $post['relevance_signals'] = ['Aktuell im 365CMS'];
            $post['url'] = $permalinks->buildPostUrl($post, $locale);
            $posts[] = $post;
        }

        return array_slice($posts, 0, $limit);
    }

    private function categoryExists(string $name): bool
    {
        $stmt = Database::instance()->prepare('SELECT id FROM ' . $this->categoriesTable() . ' WHERE name = ? LIMIT 1');
        $stmt->execute([trim($name)]);

        return is_array($stmt->fetch(PDO::FETCH_ASSOC));
    }

    private function ensureCategoryExists(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $db = Database::instance();
        $existing = $db->prepare('SELECT id FROM ' . $this->categoriesTable() . ' WHERE name = ? LIMIT 1');
        $existing->execute([$name]);
        if (is_array($existing->fetch(PDO::FETCH_ASSOC))) {
            return;
        }

        $stmt = $db->prepare('INSERT INTO ' . $this->categoriesTable() . ' (name, slug, sort_order) VALUES (?, ?, 0)');
        $stmt->execute([$name, $this->generateUniqueCategorySlug($name)]);
        $this->resetCaches();
    }

    private function generateUniqueCategorySlug(string $source, int $ignoreId = 0): string
    {
        $slug = $this->slugify($source);
        if ($slug === '') {
            $slug = 'kb-kategorie';
        }

        $db = Database::instance();
        $table = $this->categoriesTable();
        $candidate = $slug;
        $suffix = 2;

        while (true) {
            $query = "SELECT id FROM {$table} WHERE slug = ?";
            $params = [$candidate];
            if ($ignoreId > 0) {
                $query .= ' AND id <> ?';
                $params[] = $ignoreId;
            }
            $query .= ' LIMIT 1';

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($exists)) {
                return $candidate;
            }

            $candidate = $slug . '-' . $suffix;
            ++$suffix;
        }
    }

    private function sanitizeSynonyms(string $value): string
    {
        $items = preg_split('/[\r\n,]+/', $value) ?: [];
        $items = array_filter(array_map(fn(string $item): string => $this->sanitizeText($item, 190), $items));
        return implode("\n", array_values(array_unique($items)));
    }

    private function sanitizeColor(string $value, string $fallback): string
    {
        $value = trim($value);
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? strtoupper($value) : $fallback;
    }

    private function isTruthy(mixed $value): bool
    {
        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    private function resetCaches(): void
    {
        $this->settingsCache = null;
        $this->activeEntriesForLinkingCache = null;
        $this->dashboardStatsCache = null;
    }
}
