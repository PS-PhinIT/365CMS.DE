<?php

declare(strict_types=1);

namespace CMS\Services\Knowledgebase\Http;

use CMS\Services\Knowledgebase\Repository\EntryRepository;
use CMS\Services\Knowledgebase\Support\LoggerFactory;

if (!defined('ABSPATH')) {
    exit;
}

final class PublicController
{
    private static ?self $instance = null;

    private $logger;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->logger = LoggerFactory::create();
    }

    public function registerRoutes($router): void
    {
        if (!is_object($router) || !method_exists($router, 'addRoute')) {
            $this->logger->error('Knowledgebase-Router ohne addRoute in registerRoutes erhalten.');
            return;
        }

        $router->addRoute('GET', $this->publicPath('kb', 'de'), [$this, 'archivePage']);
        $router->addRoute('GET', $this->publicPath('glossar', 'de'), [$this, 'glossaryPage']);
        $router->addRoute('GET', $this->publicPath('glossar-sitemap.xml', 'de'), [$this, 'glossarySitemap']);
        $router->addRoute('GET', $this->publicPath('kb/:slug', 'de'), [$this, 'singlePage']);

        $router->addRoute('GET', $this->publicPath('kb', 'en'), [$this, 'archivePage']);
        $router->addRoute('GET', $this->publicPath('glossar', 'en'), [$this, 'glossaryPage']);
        $router->addRoute('GET', $this->publicPath('glossar-sitemap.xml', 'en'), [$this, 'glossarySitemap']);
        $router->addRoute('GET', $this->publicPath('kb/:slug', 'en'), [$this, 'singlePage']);
    }

    public function renderNavItem(string $context = 'desktop'): void
    {
        $settings = EntryRepository::instance()->getSettings();
        if (($settings['show_nav_link'] ?? '0') !== '1') {
            return;
        }

        $publicLang = $this->resolvePublicLanguage();
        $currentPath = $this->resolvePublicPathWithoutLanguage();
        $active = (str_starts_with($currentPath, '/kb') || $currentPath === '/glossar') ? 'active' : '';
        $label = htmlspecialchars($this->resolvePublicI18nText($settings, 'nav_label', $publicLang, 'Knowledgebase', 'Knowledgebase'), ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars($this->publicUrl('kb', $publicLang), ENT_QUOTES, 'UTF-8');

        if ($context === 'mobile') {
            echo '<a href="' . $href . '" class="mobile-menu__kb-link">' . $label . '</a>';
            return;
        }

        echo '<a href="' . $href . '" class="main-nav__link ' . htmlspecialchars($active, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
    }

    public function archivePage(): void
    {
        $repository = EntryRepository::instance();
        $settings = $repository->getSettings();
        $publicLang = $this->resolvePublicLanguage();
        $uiText = $this->publicUiText($publicLang);
        $search = $this->sanitizeSearchTerm($_GET['q'] ?? '');
        $searchMode = $this->sanitizeSearchMode($_GET['search_mode'] ?? 'default');
        $category = $this->sanitizeCategoryFilter($_GET['category'] ?? '');
        $allowedPerPage = [25, 50, 100, 200];
        $requestedPerPage = (int) ($_GET['per_page'] ?? 25);
        $perPage = in_array($requestedPerPage, $allowedPerPage, true) ? $requestedPerPage : 25;
        $filters = [
            'status' => 'active',
            'search' => $search,
            'search_mode' => $searchMode,
            'category' => $category,
        ];
        $totalEntries = $repository->countEntries($filters);
        $totalPages = max(1, (int) ceil($totalEntries / $perPage));
        $currentPage = $this->sanitizePageNumber($_GET['page'] ?? 1);
        $currentPage = min($currentPage, $totalPages);
        $entries = $repository->getEntries([
            ...$filters,
            'limit' => $perPage,
            'offset' => ($currentPage - 1) * $perPage,
        ]);
        $categories = $repository->getCategories();
        $archiveHeroEyebrow = $uiText['archive_eyebrow_knowledgebase'];
        $archiveIntro = $this->resolvePublicI18nText(
            $settings,
            'archive_intro',
            $publicLang,
            (string) ($settings['archive_intro'] ?? ''),
            (string) ($settings['archive_intro_en'] ?? (string) ($settings['archive_intro'] ?? ''))
        );
        $archiveTitle = $this->resolvePublicI18nText($settings, 'archive_title', $publicLang, 'Knowledgebase', 'Knowledgebase');
        $archiveResultsLabel = $search !== '' || $category !== ''
            ? $uiText['results_label_kb_filtered']
            : $uiText['results_label_kb_default'];
        $perPageOptions = $allowedPerPage;
        $pageBaseUrl = $this->publicUrl('kb', $publicLang);
        $archiveUrl = $this->publicUrl('kb', $publicLang);
        $entryBaseUrl = $this->publicUrl('kb', $publicLang);
        $searchModeOptions = ['default', 'strict'];
        $theme = \CMS\ThemeManager::instance();
        $theme->getHeader(['title' => $archiveTitle]);

        include $this->viewPath('archive');

        $theme->getFooter();
    }

    public function glossaryPage(): void
    {
        $repository = EntryRepository::instance();
        $settings = $repository->getSettings();
        $publicLang = $this->resolvePublicLanguage();
        $uiText = $this->publicUiText($publicLang);
        $search = $this->sanitizeSearchTerm($_GET['q'] ?? '');
        $searchMode = $this->sanitizeSearchMode($_GET['search_mode'] ?? 'default');
        $category = $this->sanitizeCategoryFilter($_GET['category'] ?? '');
        $allowedPerPage = [25, 50, 100, 200];
        $requestedPerPage = (int) ($_GET['per_page'] ?? 25);
        $perPage = in_array($requestedPerPage, $allowedPerPage, true) ? $requestedPerPage : 25;
        $filters = [
            'status' => 'active',
            'search' => $search,
            'search_mode' => $searchMode,
            'category' => $category,
        ];
        $totalEntries = $repository->countEntries($filters);
        $totalPages = max(1, (int) ceil($totalEntries / $perPage));
        $currentPage = $this->sanitizePageNumber($_GET['page'] ?? 1);
        $currentPage = min($currentPage, $totalPages);
        $entries = $repository->getEntries([
            ...$filters,
            'limit' => $perPage,
            'offset' => ($currentPage - 1) * $perPage,
        ]);
        $categories = $repository->getCategories();
        $archiveVariant = 'glossary';
        $archiveBasePath = $this->publicPath('glossar', $publicLang);
        $archiveUrl = $this->publicUrl('glossar', $publicLang);
        $entryBaseUrl = $this->publicUrl('kb', $publicLang);
        $pageBaseUrl = $archiveUrl;
        $perPageOptions = $allowedPerPage;
        $searchModeOptions = ['default', 'strict'];
        $archiveHeroEyebrow = $uiText['archive_eyebrow_glossary'];
        $archiveTitle = $this->resolvePublicI18nText($settings, 'glossary_title', $publicLang, 'Glossar', 'Glossary');
        $archiveIntro = $this->resolvePublicI18nText(
            $settings,
            'glossary_intro',
            $publicLang,
            (string) ($settings['glossary_intro'] ?? ''),
            (string) ($settings['glossary_intro_en'] ?? (string) ($settings['glossary_intro'] ?? ''))
        );
        $archiveResultsLabel = $search !== '' || $category !== ''
            ? $uiText['results_label_glossary_filtered']
            : $uiText['results_label_glossary_default'];
        $theme = \CMS\ThemeManager::instance();
        $theme->getHeader(['title' => $archiveTitle]);

        include $this->viewPath('archive');

        $theme->getFooter();
    }

    public function glossarySitemap(): void
    {
        $repository = EntryRepository::instance();
        $entries = $repository->getEntryList(['status' => 'active']);
        $generatedAt = function_exists('gmdate') ? gmdate('c') : date('c');
        $publicLang = $this->resolvePublicLanguage();
        $glossaryUrl = $this->publicUrl('glossar', $publicLang);

        $latestGlossaryTimestamp = null;
        foreach ($entries as $entry) {
            $timestamp = $this->resolveLastModified((string) ($entry['updated_at'] ?? ''), (string) ($entry['created_at'] ?? ''));
            if ($timestamp === null) {
                continue;
            }

            if ($latestGlossaryTimestamp === null || $timestamp > $latestGlossaryTimestamp) {
                $latestGlossaryTimestamp = $timestamp;
            }
        }

        $urls = [[
            'loc' => $glossaryUrl,
            'lastmod' => $latestGlossaryTimestamp !== null ? gmdate('c', $latestGlossaryTimestamp) : $generatedAt,
            'changefreq' => 'daily',
            'priority' => '0.9',
        ]];

        foreach ($entries as $entry) {
            $slug = trim((string) ($entry['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $timestamp = $this->resolveLastModified((string) ($entry['updated_at'] ?? ''), (string) ($entry['created_at'] ?? ''));
            $urls[] = [
                'loc' => $this->publicUrl('kb/' . rawurlencode($slug), $publicLang),
                'lastmod' => $timestamp !== null ? gmdate('c', $timestamp) : $generatedAt,
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];
        }

        $this->sendXmlHeaders();

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach ($urls as $url) {
            echo "  <url>\n";
            echo '    <loc>' . htmlspecialchars((string) $url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
            echo '    <lastmod>' . htmlspecialchars((string) $url['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</lastmod>\n";
            echo '    <changefreq>' . htmlspecialchars((string) $url['changefreq'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</changefreq>\n";
            echo '    <priority>' . htmlspecialchars((string) $url['priority'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</priority>\n";
            echo "  </url>\n";
        }

        echo "</urlset>";
        exit;
    }

    public function singlePage(string $slug): void
    {
        $slug = $this->sanitizePublicSlug($slug);
        if ($slug === '') {
            $this->renderNotFound();
            return;
        }

        $repository = EntryRepository::instance();
        $entry = $repository->getEntryBySlug($slug);
        $settings = $repository->getSettings();
        $publicLang = $this->resolvePublicLanguage();
        $uiText = $this->publicUiText($publicLang);
        if ($entry === null) {
            $this->renderNotFound($publicLang, $uiText);
            return;
        }

        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/kb/' . $slug), PHP_URL_PATH);
        $requestContext = class_exists('CMS\\Services\\ContentLocalizationService')
            ? \CMS\Services\ContentLocalizationService::getInstance()->resolveRequestContext($requestPath)
            : ['locale' => $publicLang];
        $contentLocale = (string) ($requestContext['locale'] ?? $publicLang);
        $relatedPostsLimit = max(3, min(6, (int) ($settings['related_posts_limit'] ?? 4)));
        $relatedPosts = $repository->getRelatedPosts($entry, $contentLocale, $relatedPostsLimit);
        $kbUrl = $this->publicUrl('kb', $publicLang);
        $singleUrl = $this->publicUrl('kb/' . rawurlencode($slug), $publicLang);
        $structuredDataEnabled = ($settings['enable_structured_data'] ?? '1') === '1';
        $structuredDataJson = $structuredDataEnabled ? $this->buildStructuredDataJson($entry, $singleUrl, $publicLang) : '';
        $theme = \CMS\ThemeManager::instance();
        $theme->getHeader(['title' => (string) ($entry['title'] ?? '')]);

        include $this->viewPath('single');

        $theme->getFooter();
    }

    private function resolveLastModified(string $updatedAt, string $createdAt): ?int
    {
        foreach ([$updatedAt, $createdAt] as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    private function sanitizeSearchTerm(mixed $value): string
    {
        $value = trim(strip_tags((string) $value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, 120, 'UTF-8');
    }

    private function sanitizeCategoryFilter(mixed $value): string
    {
        $value = trim(strip_tags((string) $value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, 120, 'UTF-8');
    }

    private function sanitizePageNumber(mixed $value): int
    {
        return max(1, min(10000, (int) $value));
    }

    private function sanitizeSearchMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, ['default', 'strict'], true) ? $mode : 'default';
    }

    private function sanitizePublicSlug(string $slug): string
    {
        $slug = trim(rawurldecode($slug));
        $slug = mb_substr($slug, 0, 190, 'UTF-8');

        return preg_match('/^[\p{L}\p{N}-]+$/u', $slug) === 1 ? $slug : '';
    }

    /** Vorlage `archive`/`single`: im aktiven Theme unter `knowledgebase/<name>.php` überschreibbar. */
    private function viewPath(string $view): string
    {
        $view = in_array($view, ['archive', 'single'], true) ? $view : 'archive';
        try {
            $themePath = \CMS\ThemeManager::instance()->getThemePath();
        } catch (\Throwable) {
            $themePath = '';
        }
        if ($themePath !== '') {
            $candidate = rtrim($themePath, '/\\') . '/knowledgebase/' . $view . '.php';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return rtrim((string) ABSPATH, '/\\') . '/views/knowledgebase/' . $view . '.php';
    }

    private function sendXmlHeaders(): void
    {
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
    }

    private function renderNotFound(string $publicLang = 'de', ?array $uiText = null): void
    {
        http_response_code(404);
        $uiText = $uiText ?? $this->publicUiText($publicLang);
        $theme = \CMS\ThemeManager::instance();
        $theme->getHeader(['title' => $uiText['not_found_title'], 'error_code' => 404]);

        echo '<main class="cms-kb-content cms-kb-content--single"><article class="cms-kb-article"><h1>' . htmlspecialchars($uiText['not_found_title'], ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($uiText['not_found_body'], ENT_QUOTES, 'UTF-8') . '</p><p><a class="cms-kb-entry__cta" href="' . htmlspecialchars($this->publicUrl('kb', $publicLang), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($uiText['back_to_kb'], ENT_QUOTES, 'UTF-8') . '</a></p></article></main>';

        $theme->getFooter();
    }

    private function resolvePublicLanguage(): string
    {
        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $normalizedPath = function_exists('cms_plugin_public_normalize_path')
            ? cms_plugin_public_normalize_path($requestPath)
            : trim($requestPath, '/');

        if (function_exists('cms_plugin_public_language')) {
            return cms_plugin_public_language($normalizedPath);
        }

        if ($normalizedPath === 'en' || str_starts_with($normalizedPath, 'en/')) {
            return 'en';
        }

        return 'de';
    }

    private function resolvePublicPathWithoutLanguage(): string
    {
        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $normalizedPath = function_exists('cms_plugin_public_normalize_path')
            ? cms_plugin_public_normalize_path($requestPath)
            : trim($requestPath, '/');

        if (function_exists('cms_plugin_public_path_without_lang')) {
            $withoutLang = cms_plugin_public_path_without_lang($normalizedPath);
            return '/' . ltrim($withoutLang, '/');
        }

        return '/' . ltrim($normalizedPath, '/');
    }

    private function publicPath(string $path, string $lang = 'de'): string
    {
        if (function_exists('cms_plugin_public_localized_path')) {
            return cms_plugin_public_localized_path($path, $lang);
        }

        $path = '/' . ltrim($path, '/');
        if ($lang === 'en') {
            return '/en' . $path;
        }

        return $path;
    }

    private function publicUrl(string $path, string $lang = 'de'): string
    {
        return SITE_URL . $this->publicPath($path, $lang);
    }

    /**
     * @param array<string, string> $values
     */
    private function resolvePublicI18nText(array $values, string $key, string $lang, string $deFallback, string $enFallback): string
    {
        $fallback = $lang === 'en' ? $enFallback : $deFallback;
        if (function_exists('cms_plugin_public_i18n_value')) {
            return cms_plugin_public_i18n_value($values, $key, $lang, $fallback);
        }

        if ($lang === 'en' && isset($values[$key . '_en']) && trim((string) $values[$key . '_en']) !== '') {
            return (string) $values[$key . '_en'];
        }

        if (isset($values[$key]) && trim((string) $values[$key]) !== '') {
            return (string) $values[$key];
        }

        return $fallback;
    }

    /**
     * @return array<string, string>
     */
    private function publicUiText(string $lang): array
    {
        /** @var array<string, array{de: string, en: string}> $dictionary */
        $dictionary = [
            'archive_eyebrow_knowledgebase' => ['de' => 'Knowledgebase', 'en' => 'Knowledgebase'],
            'archive_eyebrow_glossary' => ['de' => 'Glossar', 'en' => 'Glossary'],
            'results_label_kb_filtered' => ['de' => 'Gefilterte Knowledgebase-Treffer', 'en' => 'Filtered knowledgebase matches'],
            'results_label_kb_default' => ['de' => 'Knowledgebase-Übersicht', 'en' => 'Knowledgebase overview'],
            'results_label_glossary_filtered' => ['de' => 'Gefilterte Glossar-Einträge', 'en' => 'Filtered glossary entries'],
            'results_label_glossary_default' => ['de' => 'Glossar-Übersicht', 'en' => 'Glossary overview'],
            'active_filters_aria' => ['de' => 'Aktive Filter', 'en' => 'Active filters'],
            'filter_search_prefix' => ['de' => 'Suche', 'en' => 'Search'],
            'filter_category_prefix' => ['de' => 'Kategorie', 'en' => 'Category'],
            'filters_aria' => ['de' => 'Knowledgebase-Filter', 'en' => 'Knowledgebase filters'],
            'search_label' => ['de' => 'Begriff suchen', 'en' => 'Search term'],
            'search_placeholder' => ['de' => 'Begriff suchen …', 'en' => 'Search term ...'],
            'category_label' => ['de' => 'Kategorie', 'en' => 'Category'],
            'all_categories_option' => ['de' => 'Alle Kategorien', 'en' => 'All categories'],
            'search_mode_label' => ['de' => 'Suchmodus', 'en' => 'Search mode'],
            'search_mode_default' => ['de' => 'Standard (tolerant)', 'en' => 'Default (tolerant)'],
            'search_mode_strict' => ['de' => 'Strikt', 'en' => 'Strict'],
            'per_page_label' => ['de' => 'Anzahl', 'en' => 'Count'],
            'entries_suffix' => ['de' => 'Einträge', 'en' => 'entries'],
            'search_button' => ['de' => 'Suchen', 'en' => 'Search'],
            'reset_button' => ['de' => 'Zurücksetzen', 'en' => 'Reset'],
            'categories_aria' => ['de' => 'Kategorien', 'en' => 'Categories'],
            'all_categories_chip' => ['de' => 'Alle', 'en' => 'All'],
            'empty_title' => ['de' => 'Keine Einträge gefunden', 'en' => 'No entries found'],
            'empty_body' => ['de' => 'Versuche es mit einem anderen Suchbegriff oder entferne den Kategorie-Filter.', 'en' => 'Try another search term or remove the category filter.'],
            'fallback_category' => ['de' => 'Allgemein', 'en' => 'General'],
            'entry_cta' => ['de' => 'zum Eintrag', 'en' => 'open entry'],
            'pagination_aria' => ['de' => 'Seitennavigation', 'en' => 'Pagination'],
            'pagination_prev' => ['de' => '← Zurück', 'en' => '← Previous'],
            'pagination_next' => ['de' => 'Weiter →', 'en' => 'Next →'],
            'pagination_page_prefix' => ['de' => 'Seite', 'en' => 'Page'],
            'pagination_page_connector' => ['de' => 'von', 'en' => 'of'],
            'breadcrumb_aria' => ['de' => 'Breadcrumb', 'en' => 'Breadcrumb'],
            'breadcrumb_home' => ['de' => 'Start', 'en' => 'Home'],
            'breadcrumb_kb' => ['de' => 'Knowledgebase', 'en' => 'Knowledgebase'],
            'related_heading' => ['de' => 'Verwandte Artikel', 'en' => 'Related articles'],
            'related_fallback_category' => ['de' => '365CMS Beitrag', 'en' => '365CMS post'],
            'related_meta_aria' => ['de' => 'Metainformationen zum Eintrag', 'en' => 'Meta information about the entry'],
            'details_heading' => ['de' => 'Begriffsdetails', 'en' => 'Term details'],
            'details_keyword' => ['de' => 'Keyword', 'en' => 'Keyword'],
            'details_category' => ['de' => 'Bereich', 'en' => 'Area'],
            'details_synonyms' => ['de' => 'Synonyme', 'en' => 'Synonyms'],
            'quick_explained_heading' => ['de' => 'Kurz erklärt', 'en' => 'Quick explanation'],
            'back_to_overview' => ['de' => '← Zurück zur Übersicht', 'en' => '← Back to overview'],
            'not_found_title' => ['de' => 'Knowledgebase-Eintrag nicht gefunden', 'en' => 'Knowledgebase entry not found'],
            'not_found_body' => ['de' => 'Der angeforderte Eintrag ist nicht verfügbar oder wurde verschoben.', 'en' => 'The requested entry is unavailable or has been moved.'],
            'back_to_kb' => ['de' => 'Zur Knowledgebase', 'en' => 'Back to knowledgebase'],
        ];

        foreach (\CMS\Services\Knowledgebase\Support\ContentSections::definitions() as $sectionKey => $sectionDefinition) {
            $dictionary['section_' . $sectionKey] = ['de' => $sectionDefinition['heading_de'], 'en' => $sectionDefinition['heading_en']];
        }

        $translated = [];
        foreach ($dictionary as $key => $label) {
            $translated[$key] = $this->resolvePublicI18nText(
                [$key => $label['de'], $key . '_en' => $label['en']],
                $key,
                $lang,
                $label['de'],
                $label['en']
            );
        }

        return $translated;
    }

    private function buildStructuredDataJson(array $entry, string $singleUrl, string $lang): string
    {
        $title = trim((string) ($entry['title'] ?? ''));
        if ($title === '') {
            return '';
        }

        $excerpt = trim(strip_tags((string) ($entry['excerpt'] ?? '')));
        $tooltip = trim(strip_tags((string) ($entry['tooltip_text'] ?? '')));
        $definition = $excerpt !== '' ? $excerpt : $tooltip;
        $articleBody = \CMS\Services\Knowledgebase\Service\SectionContent::instance()->plainText($entry);
        $articleBody = preg_replace('/\s+/u', ' ', $articleBody) ?? '';
        $articleBody = trim($articleBody);

        $graph = [];
        $graph[] = [
            '@type' => 'DefinedTerm',
            '@id' => $singleUrl . '#defined-term',
            'name' => $title,
            'url' => $singleUrl,
            'inDefinedTermSet' => $this->publicUrl('glossar', $lang),
            'description' => $definition !== '' ? $definition : $articleBody,
        ];

        if ($definition !== '' || $articleBody !== '') {
            $graph[] = [
                '@type' => 'FAQPage',
                '@id' => $singleUrl . '#faq',
                'mainEntity' => [[
                    '@type' => 'Question',
                    'name' => $title,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $definition !== '' ? $definition : $articleBody,
                    ],
                ]],
            ];
        }

        $payload = [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];

        // HEX-Flags: der Abschnittstext ist entity-dekodiert, `</script>` oder `<!--` dürfen den Datenblock nicht verlassen.
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return is_string($json) ? $json : '';
    }
}
