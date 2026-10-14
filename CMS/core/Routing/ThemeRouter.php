<?php
/**
 * Theme Router Module
 *
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS\Routing;

use CMS\Api;
use CMS\Services\CmsAuthPageService;
use CMS\Database;
use CMS\Json;
use CMS\PageManager;
use CMS\PluginManager;
use CMS\Router;
use CMS\Services;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists(__NAMESPACE__ . '\\ThemeRouter', false)) {
    return;
}

if (!class_exists(__NAMESPACE__ . '\\ThemeRouter', false)) {
final class ThemeRouter
{
    private const SEARCH_RATE_LIMIT = 30;
    private const SEARCH_RATE_WINDOW = 60;
    private const FEED_CACHE_TTL = 300;

    /**
     * Rückwärtskompatible Aliase für den Such-Scope (`?type=`).
     * Ältere/gecachte Suchformulare können noch Singular-Werte senden;
     * diese werden hier transparent auf die intern erwarteten Plural-Werte gemappt.
     */
    private const SEARCH_TYPE_ALIASES = [
        'post' => 'posts',
        'page' => 'pages',
        'category' => 'categories',
        'tag' => 'tags',
        'company' => 'companies',
        'event' => 'events',
        'speaker' => 'speakers',
        'expert' => 'experts',
        // Deutsche Bezeichnungen aus Theme-Formularen bzw. Links
        'beitraege' => 'posts',
        'beiträge' => 'posts',
        'beitrag' => 'posts',
        'artikel' => 'posts',
        'blog' => 'posts',
        'seiten' => 'pages',
        'seite' => 'pages',
        'kategorien' => 'categories',
        'kategorie' => 'categories',
        'schlagwoerter' => 'tags',
        'schlagwörter' => 'tags',
        // „Alle Typen“
        'all' => '',
        'alle' => '',
        'alles' => '',
    ];

    /** Such-Scopes, die der Core selbst bedient; alle anderen (`?type=`) gehören Plugins. */
    private const CORE_SEARCH_TYPES = ['pages', 'posts', 'categories', 'tags', 'experts', 'companies', 'speakers', 'events'];

    /** Höchstzahl an Plugin-Treffern pro Suche. */
    private const MAX_PLUGIN_SEARCH_RESULTS = 50;

    private ThemeArchiveRepository $archiveRepository;

    public function __construct(private readonly Router $router, ?ThemeArchiveRepository $archiveRepository = null)
    {
        $this->archiveRepository = $archiveRepository ?? new ThemeArchiveRepository();
    }

    public function registerRoutes(): void
    {
        $permalinkService = Services\PermalinkService::getInstance();
        $currentPostRoutePattern = $permalinkService->buildPostRoutePattern();
        $indexNowKey = Services\IndexingService::getInstance()->getIndexNowKey();

        $this->router->addRoute('GET', '/', [$this, 'renderHome']);
        $this->router->addRoute('GET', '/sitemap', [$this, 'renderHtmlSitemap']);
        $this->router->addRoute('GET', '/search', [$this, 'renderSearch']);
        $this->router->addRoute('GET', '/contact', [$this, 'renderContact']);
        $this->router->addRoute('GET', '/kontakt', [$this, 'renderContact']);
        // Das Theme-Kontaktformular sendet an dieselbe URL; ohne POST-Route endete jedes Absenden mit 404.
        // CSRF prüft das Template selbst (Token "contact_form"), siehe Router-Bypass für /contact und /kontakt.
        $this->router->addRoute('POST', '/contact', [$this, 'renderContact']);
        $this->router->addRoute('POST', '/kontakt', [$this, 'renderContact']);
        $this->router->addRoute('GET', '/autoren', [$this, 'renderAuthorsIndex']);
        $this->router->addRoute('GET', '/authors', [$this, 'renderAuthorsIndex']);
        $this->router->addRoute('GET', '/author/:identifier', [$this, 'renderAuthorPage']);
        $this->registerArchiveRoutes('category', [$this, 'renderCategoryArchive'], [$this, 'renderCategoryArchiveIndex']);
        $this->registerArchiveRoutes('tag', [$this, 'renderTagArchive'], [$this, 'renderTagArchiveIndex']);
        $this->router->addRoute('GET', '/site-table/export/:id/:format', [$this, 'streamSiteTableExport']);
        $this->router->addRoute('GET', '/blog', [$this, 'renderBlogIndex']);
        $this->router->addRoute('GET', $currentPostRoutePattern, [$this, 'renderBlogSingle']);
        if ($currentPostRoutePattern !== Services\PermalinkService::LEGACY_POST_ROUTE_PATTERN) {
            $this->router->addRoute('GET', Services\PermalinkService::LEGACY_POST_ROUTE_PATTERN, [$this, 'renderLegacyBlogSingle']);
        }
        $this->router->addRoute('GET', '/feed', [$this, 'serveRssFeed']);
        $this->router->addRoute('GET', '/sitemap.xml', [$this, 'serveSitemap']);
        foreach (['pages', 'posts', 'plugins', 'images', 'news'] as $sitemapPart) {
            $this->router->addRoute('GET', '/' . $sitemapPart . '.xml', fn() => $this->serveSitemapFile($sitemapPart . '.xml'));
        }
        $this->router->addRoute('GET', '/robots.txt', [$this, 'serveRobotsTxt']);
        $this->router->addRoute('GET', '/security.txt', [$this, 'serveSecurityTxt']);
        $this->router->addRoute('GET', '/.well-known/security.txt', [$this, 'serveSecurityTxt']);
        if ($indexNowKey !== '') {
            $this->router->addRoute('GET', '/' . $indexNowKey . '.txt', [$this, 'serveIndexNowKeyFile']);
        }
    }

    private function registerArchiveRoutes(string $type, callable $archiveCallback, ?callable $indexCallback = null): void
    {
        $detailPaths = [];
        $indexPaths = [];

        foreach (\cms_get_archive_locales() as $locale) {
            $baseSegments = [
                (string) \cms_get_archive_base($type, $locale),
                (string) \cms_get_default_archive_base($type, $locale),
            ];

            foreach (array_unique($baseSegments) as $baseSegment) {
                $basePath = '/' . trim($baseSegment, '/');
                if ($basePath === '/') {
                    continue;
                }

                $indexPaths[] = $basePath;
                $detailPaths[] = $basePath . '/:slug';
            }
        }

        if ($indexCallback !== null) {
            foreach (array_values(array_unique($indexPaths)) as $path) {
                $this->router->addRoute('GET', $path, $indexCallback);
            }
        }

        foreach (array_values(array_unique($detailPaths)) as $path) {
            $this->router->addRoute('GET', $path, $archiveCallback);
        }
    }

    public function renderHome(): void
    {
        ThemeManager::instance()->render('home');
    }

    public function renderSearch(): void
    {
        $type = $this->normalizeSearchType($_GET['type'] ?? '');
        $sort = Services\SiteSearchService::normalizeSort($_GET['sort'] ?? '');
        $location = trim((string)($_GET['location'] ?? ''));
        $filter = trim((string)($_GET['filter'] ?? ''));
        $contentLocale = $this->getResolvedContentLocale();

        $siteSearch = Services\SiteSearchService::getInstance();
        $parsed = Services\SiteSearchService::parseQuery(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
        $query = $parsed['query'];

        $results = [];
        $pluginMgr = PluginManager::instance();
        $db = Database::instance();
        $prefix = $db->getPrefix();

        // Jede Suche führt mehrere LIKE-Scans über LONGTEXT-Spalten aus: Kontingent je IP,
        // damit Bots/Flooding die Datenbank nicht auslasten.
        if ($parsed['terms'] !== [] || $location !== '' || $filter !== '') {
            $clientIp = \CMS\Security::getClientIp();
            if (!\CMS\Security::checkDbRateLimit($clientIp, 'site_search', self::SEARCH_RATE_LIMIT, self::SEARCH_RATE_WINDOW)) {
                http_response_code(429);
                header('Retry-After: ' . self::SEARCH_RATE_WINDOW);
                ThemeManager::instance()->render('search', [
                    'results' => [],
                    'query' => $query,
                    'type' => $type,
                    'sort' => $sort,
                    'total' => 0,
                    'location' => $location,
                    'filter' => $filter,
                    'search_notice' => 'Zu viele Suchanfragen in kurzer Zeit. Bitte in einer Minute erneut versuchen.',
                ]);
                return;
            }
            \CMS\Security::recordDbRateLimitAttempt($clientIp, 'site_search');
        }

        // Ohne verwertbaren Suchbegriff keine Inhaltstreffer (bisher listete eine leere Suche alle Seiten).
        if ($parsed['terms'] !== []) {
            if ($type === '' || $type === 'pages') {
                $results = array_merge($results, $this->searchPagesForQuery($siteSearch, $parsed, $contentLocale));
            }
            if ($type === '' || $type === 'posts') {
                $results = array_merge($results, $this->searchPostsForQuery($siteSearch, $parsed, $contentLocale));
            }
            if ($type === '' || $type === 'categories') {
                $results = array_merge($results, $this->searchTaxonomyForQuery($siteSearch, $parsed, 'post_categories', 'category', 'Kategorie'));
            }
            if ($type === '' || $type === 'tags') {
                $results = array_merge($results, $this->searchTaxonomyForQuery($siteSearch, $parsed, 'post_tags', 'tag', 'Tag'));
            }
        }

        if ($type === 'experts' && $pluginMgr->isPluginActive('cms-experts')) {
            try {
                $where = ["e.status = 'active'"];
                $params = [];
                if ($query !== '') {
                    $where[] = '(e.name LIKE ? OR e.title LIKE ? OR e.skills LIKE ? OR e.specializations LIKE ?)';
                    $like = '%' . \cms_escape_like($query) . '%';
                    $params = array_merge($params, [$like, $like, $like, $like]);
                }
                if ($location !== '') {
                    $where[] = '(e.location LIKE ? OR e.availability LIKE ?)';
                    $locLike = '%' . \cms_escape_like($location) . '%';
                    $params[] = $locLike;
                    $params[] = $locLike;
                }
                if ($filter !== '') {
                    $where[] = '(e.skills LIKE ? OR e.specializations LIKE ?)';
                    $fLike = '%' . \cms_escape_like($filter) . '%';
                    $params[] = $fLike;
                    $params[] = $fLike;
                }
                $sql = "SELECT e.* FROM {$prefix}experts e WHERE " . implode(' AND ', $where) . ' ORDER BY e.created_at DESC LIMIT 20';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $row['_type'] = 'expert';
                    $row['_type_label'] = 'Experte';
                    $row['slug'] = 'experts/' . ($row['id'] ?? 0);
                    $row['title'] = $row['name'] ?? $row['display_name'] ?? 'Experte';
                    $row['meta_description'] = $row['title'] ?? $row['skills'] ?? '';
                    $results[] = $row;
                }
            } catch (\Throwable) {
            }
        }

        if ($type === 'companies' && $pluginMgr->isPluginActive('cms-companies')) {
            try {
                $where = ["c.status = 'active'"];
                $params = [];
                if ($query !== '') {
                    $where[] = '(c.name LIKE ? OR c.description LIKE ? OR c.industry LIKE ?)';
                    $like = '%' . \cms_escape_like($query) . '%';
                    $params = array_merge($params, [$like, $like, $like]);
                }
                if ($location !== '') {
                    $where[] = '(c.location LIKE ? OR c.city LIKE ?)';
                    $locLike = '%' . \cms_escape_like($location) . '%';
                    $params[] = $locLike;
                    $params[] = $locLike;
                }
                if ($filter !== '') {
                    $where[] = '(c.industry LIKE ? OR c.description LIKE ?)';
                    $fLike = '%' . \cms_escape_like($filter) . '%';
                    $params[] = $fLike;
                    $params[] = $fLike;
                }
                $sql = "SELECT c.* FROM {$prefix}companies c WHERE " . implode(' AND ', $where) . ' ORDER BY c.created_at DESC LIMIT 20';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $row['_type'] = 'company';
                    $row['_type_label'] = 'Firma';
                    $row['slug'] = 'companies/' . ($row['id'] ?? 0);
                    $row['title'] = $row['name'] ?? $row['company_name'] ?? 'Firma';
                    $row['meta_description'] = $row['description'] ?? $row['short_description'] ?? '';
                    $results[] = $row;
                }
            } catch (\Throwable) {
            }
        }

        if ($type === 'speakers' && $pluginMgr->isPluginActive('cms-speakers')) {
            try {
                $where = ["s.status = 'active'"];
                $params = [];
                if ($query !== '') {
                    $where[] = '(s.name LIKE ? OR s.bio LIKE ? OR s.expertise LIKE ?)';
                    $like = '%' . \cms_escape_like($query) . '%';
                    $params = array_merge($params, [$like, $like, $like]);
                }
                if ($location !== '') {
                    $where[] = '(s.location LIKE ?)';
                    $params[] = '%' . \cms_escape_like($location) . '%';
                }
                if ($filter !== '') {
                    $where[] = '(s.expertise LIKE ? OR s.topics LIKE ?)';
                    $fLike = '%' . \cms_escape_like($filter) . '%';
                    $params[] = $fLike;
                    $params[] = $fLike;
                }
                $sql = "SELECT s.* FROM {$prefix}event_speakers s WHERE " . implode(' AND ', $where) . ' ORDER BY s.created_at DESC LIMIT 20';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $row['_type'] = 'speaker';
                    $row['_type_label'] = 'Speaker';
                    $row['slug'] = 'speakers/' . ($row['id'] ?? 0);
                    $row['title'] = $row['name'] ?? 'Speaker';
                    $row['meta_description'] = $row['bio'] ?? $row['expertise'] ?? '';
                    $results[] = $row;
                }
            } catch (\Throwable) {
            }
        }

        if ($type === 'events' && $pluginMgr->isPluginActive('cms-events')) {
            try {
                $where = ["ev.status = 'active'"];
                $params = [];
                if ($query !== '') {
                    $where[] = '(ev.title LIKE ? OR ev.description LIKE ?)';
                    $like = '%' . \cms_escape_like($query) . '%';
                    $params = array_merge($params, [$like, $like]);
                }
                if ($location !== '') {
                    $where[] = '(ev.location LIKE ? OR ev.venue LIKE ?)';
                    $locLike = '%' . \cms_escape_like($location) . '%';
                    $params[] = $locLike;
                    $params[] = $locLike;
                }
                $sql = "SELECT ev.* FROM {$prefix}events ev WHERE " . implode(' AND ', $where) . ' ORDER BY ev.start_date DESC LIMIT 20';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $row['_type'] = 'event';
                    $row['_type_label'] = 'Event';
                    $row['slug'] = 'events/' . ($row['id'] ?? 0);
                    $row['title'] = $row['title'] ?? 'Event';
                    $row['meta_description'] = $row['description'] ?? '';
                    $results[] = $row;
                }
            } catch (\Throwable) {
            }
        }

        if ($parsed['terms'] !== [] && !in_array($type, self::CORE_SEARCH_TYPES, true)) {
            $results = array_merge($results, $this->collectPluginSearchResults($query, $type, $contentLocale));
        }

        $results = $siteSearch->hydrate($siteSearch->rank($results, $parsed['terms'], $sort));

        ThemeManager::instance()->render('search', [
            'results' => $results,
            'query' => $query,
            'type' => $type,
            'sort' => $sort,
            'total' => count($results),
            'location' => $location,
            'filter' => $filter,
        ]);
    }

    /** Such-Scope aus `?type=` (Plural-Werte, Aliase, Plugin-Scopes); ungültige Werte = alle Typen. */
    private function normalizeSearchType(mixed $type): string
    {
        $type = is_string($type) ? mb_strtolower(trim($type), 'UTF-8') : '';
        if ($type === '' || preg_match('/^[\p{Ll}\p{N}_-]{1,40}$/u', $type) !== 1) {
            return '';
        }

        return self::SEARCH_TYPE_ALIASES[$type] ?? $type;
    }

    /**
     * @param array{query:string, terms:list<string>, words:list<string>} $parsed
     * @return list<array<string, mixed>>
     */
    private function searchPagesForQuery(Services\SiteSearchService $siteSearch, array $parsed, string $locale): array
    {
        $prefix = Database::instance()->getPrefix();
        $english = $locale === 'en';
        $rows = $siteSearch->searchTable($parsed, [
            'table' => "{$prefix}pages",
            'alias' => 'pg',
            'where' => "pg.status = 'published'",
            'title' => $english ? ['title_en', 'title'] : ['title'],
            'excerpt' => ['excerpt'],
            'content' => $english ? ['content_en', 'content'] : ['content'],
            'date' => ['published_at', 'created_at'],
        ]);

        foreach ($rows as &$row) {
            $row['_type'] = 'page';
            $row['_type_label'] = 'Seite';
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array{query:string, terms:list<string>, words:list<string>} $parsed
     * @return list<array<string, mixed>>
     */
    private function searchPostsForQuery(Services\SiteSearchService $siteSearch, array $parsed, string $locale): array
    {
        $prefix = Database::instance()->getPrefix();
        $english = $locale === 'en';
        $rows = $siteSearch->searchTable($parsed, [
            'table' => "{$prefix}posts",
            'alias' => 'p',
            'where' => \cms_post_publication_where('p') . ' AND ' . $this->buildPostLocaleAvailabilityExpression('p', $locale),
            'title' => $english ? ['title_en', 'title'] : ['title'],
            'excerpt' => $english ? ['excerpt_en', 'excerpt'] : ['excerpt'],
            'content' => $english ? ['content_en', 'content'] : ['content'],
            'date' => ['published_at', 'created_at'],
        ]);

        foreach ($rows as &$row) {
            $row['_type'] = 'post';
            $row['_type_label'] = 'Beitrag';
        }
        unset($row);

        return $rows;
    }

    /**
     * Kategorien bzw. Tags: alle Begriffe müssen in Name oder Beschreibung vorkommen.
     *
     * @param array{query:string, terms:list<string>, words:list<string>} $parsed
     * @return list<array<string, mixed>>
     */
    private function searchTaxonomyForQuery(Services\SiteSearchService $siteSearch, array $parsed, string $table, string $type, string $label): array
    {
        $prefix = Database::instance()->getPrefix();
        $rows = $siteSearch->searchTable($parsed, [
            'table' => "{$prefix}{$table}",
            'alias' => 't',
            'where' => '1=1',
            'title' => ['name'],
            'excerpt' => ['description'],
            'content' => [],
            'date' => [],
        ]);

        foreach ($rows as &$row) {
            $row['_type'] = $type;
            $row['_type_label'] = $label;
            $row['slug'] = $type . '/' . rawurlencode((string) ($row['slug'] ?? ''));
            $row['title'] = $row['name'] ?? $label;
            $row['meta_description'] = $row['description'] ?? '';
            $row['_search_date'] = '';
        }
        unset($row);

        return $rows;
    }

    /**
     * Treffer aus Plugins (z. B. Message Center, Tools) über den Filter `search_results`.
     *
     * Aufruf: `search_results($results, $query, $limit, $context)` mit `$context = ['type', 'locale', 'source']`.
     * Jeder Treffer braucht `title` und `url` (absolute URL der eigenen Domain oder Pfad relativ zu
     * SITE_URL); optional `excerpt`, `_type` (Such-Scope, z. B. `messagecenter`), `_type_label` und
     * `date` (Veröffentlichungs- oder Änderungsdatum für „Neueste zuerst“). Die Relevanz berechnet der
     * Core aus Titel und Auszug; Plugins liefern nur Treffer, die alle Suchbegriffe enthalten.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectPluginSearchResults(string $query, string $type, string $locale): array
    {
        try {
            $rows = \CMS\Hooks::applyFilters('search_results', [], $query, 20, [
                'type' => $type,
                'locale' => $locale,
                'source' => 'search_page',
            ]);
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('search')->warning('Plugin-Suchergebnisse konnten nicht geladen werden.', [
                'exception' => $e,
            ]);
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $siteUrl = rtrim((string) SITE_URL, '/');
        $siteHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        $results = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($results) >= self::MAX_PLUGIN_SEARCH_RESULTS) {
                continue;
            }

            $title = trim(strip_tags((string) ($row['title'] ?? '')));
            $url = trim((string) ($row['url'] ?? ''));
            if ($title === '' || $url === '' || preg_match('/[\s<>"]/', $url) === 1) {
                continue;
            }

            if (preg_match('#^https?://#i', $url) === 1) {
                if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== $siteHost) {
                    continue;
                }
            } elseif (str_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
                continue;
            } else {
                $url = $siteUrl . '/' . ltrim($url, '/');
            }

            $resultType = strtolower(trim((string) ($row['_type'] ?? '')));
            $resultType = $resultType !== '' ? $resultType : 'plugin';
            if ($type !== '' && $resultType !== $type) {
                continue;
            }

            $description = trim((string) ($row['meta_description'] ?? $row['excerpt'] ?? ''));
            $row['title'] = $title;
            $row['url'] = $url;
            // Themes bauen Links teils aus `slug` (Pfad relativ zu SITE_URL) – beide Felder bereitstellen.
            $row['slug'] = str_starts_with($url, $siteUrl . '/') ? substr($url, strlen($siteUrl) + 1) : ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            $row['excerpt'] = trim((string) ($row['excerpt'] ?? $description));
            $row['meta_description'] = $description;
            $row['_type'] = $resultType;
            $row['_type_label'] = trim((string) ($row['_type_label'] ?? '')) ?: 'Inhalt';
            $date = trim((string) ($row['date'] ?? ''));
            $row['_search_date'] = $date !== '' && strtotime($date) !== false ? $date : '';
            unset($row['_search_score']);
            $results[] = $row;
        }

        return $results;
    }

    public function renderContact(): void
    {
        $themeManager = ThemeManager::instance();
        $contactTemplate = $themeManager->getThemePath() . 'contact.php';

        if (is_file($contactTemplate)) {
            $themeManager->render('contact');
            return;
        }

        $locale = $this->getResolvedContentLocale();

        foreach (['contact', 'kontakt'] as $slug) {
            $pageManager = PageManager::instance();
            $page = $pageManager->getPageBySlug($slug, $locale);
            if ($page === null || ($page['status'] ?? '') !== 'published' || !$pageManager->pageMatchesLocaleAvailability($page, $locale)) {
                continue;
            }

            $page = Services\ContentLocalizationService::getInstance()->localizePage($page, $locale);
            if (!empty($page['content'])) {
                $page['content'] = $this->router->prepareRenderableContent((string)$page['content'], 'page', (int)($page['id'] ?? 0));
            }

            $themeManager->render('page', ['page' => $page, 'contentLocale' => $locale]);
            return;
        }

        $this->router->render404();
    }

    public function streamSiteTableExport(string $id, string $format): void
    {
        $tableId = (int)$id;
        if ($tableId <= 0 || !Services\SiteTableService::getInstance()->streamExportById($tableId, $format, true)) {
            $this->router->render404();
        }
    }

    private function redirectToArchive(string $type, string $slug): void
    {
        $query = $_GET;
        unset($query['category'], $query['tag']);
        $target = \cms_get_archive_path($type, $slug, $this->router->getRequestLocale());
        if ($query !== []) {
            $target .= (str_contains($target, '?') ? '&' : '?') . http_build_query($query);
        }

        $this->router->redirect($target, 301);
    }

    public function renderBlogIndex(): void
    {
        $requestedCategory = trim((string) ($_GET['category'] ?? ''));
        if ($requestedCategory !== '') {
            $resolvedCategorySlug = $this->resolveRequestedCategorySlug($requestedCategory);
            if ($resolvedCategorySlug === '') {
                $this->router->render404();
                return;
            }

            // Filter-URL /blog?category=… ist Duplicate Content des Archivs: dauerhaft auf die kanonische Archiv-URL.
            $this->redirectToArchive('category', $resolvedCategorySlug);
            return;
        }

        $requestedTag = trim((string) ($_GET['tag'] ?? ''));
        if ($requestedTag !== '') {
            $resolvedTagSlug = $this->resolveRequestedTagSlug($requestedTag);
            if ($resolvedTagSlug === '') {
                $this->router->render404();
                return;
            }

            // Filter-URL /blog?tag=… ist Duplicate Content des Archivs: dauerhaft auf die kanonische Archiv-URL.
            $this->redirectToArchive('tag', $resolvedTagSlug);
            return;
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();
        $locale = $this->getResolvedContentLocale();
        $localeFilter = $this->buildPostLocaleAvailabilityExpression('p', $locale);
        $perPage = 9;
        $total = (int)$db->get_var("SELECT COUNT(*) FROM {$prefix}posts p WHERE " . \cms_post_publication_where('p') . " AND {$localeFilter}");
        // Auf die letzte Seite begrenzen: Riesige ?p=-Werte liefen sonst als Float-OFFSET ins SQL (Syntaxfehler, HTTP 500).
        $page = min(max(1, (int)($_GET['p'] ?? 1)), max(1, (int)ceil($total / $perPage)));
        $offset = ($page - 1) * $perPage;
        $posts = $db->get_results(
            "SELECT p.*, c.name AS category_name, " . $this->categorySlugSelectExpression($locale) . ",
                    COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), NULLIF(u.username, ''), 'Autor') AS author_name
             FROM {$prefix}posts p
             LEFT JOIN {$prefix}users u ON u.id = p.author_id
             LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
             WHERE " . \cms_post_publication_where('p') . " AND {$localeFilter}
             ORDER BY p.published_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        ) ?: [];

        ThemeManager::instance()->render('blog', [
            'posts' => $posts,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
            'perPage' => $perPage,
        ]);
    }

    public function renderCategoryArchive(string $slug): void
    {
        $slug = trim(rawurldecode($slug));
        if ($slug === '') {
            $this->router->render404();
            return;
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();
        $locale = $this->getResolvedContentLocale();
        $category = $db->get_row(
            "SELECT id, name, slug, slug_en, description, parent_id
             FROM {$prefix}post_categories
             WHERE slug = ? OR (slug_en IS NOT NULL AND slug_en != '' AND slug_en = ?)
             LIMIT 1",
            [$slug, $slug]
        );

        if ($category === null) {
            $this->router->render404();
            return;
        }

        $categoryData = (array) $category;
        $categoryData['slug_de'] = trim((string) ($categoryData['slug'] ?? ''));
        $categoryData['slug_en'] = trim((string) ($categoryData['slug_en'] ?? ''));
        if ($locale === 'en' && $categoryData['slug_en'] !== '') {
            $categoryData['slug'] = $categoryData['slug_en'];
        }

        $query = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? $_GET['p'] ?? 1));
        $perPage = 10;

        $categoryIds = $this->archiveRepository->getCategoryArchiveIds((int) ($category->id ?? 0));
        if ($categoryIds === []) {
            $categoryIds = [(int) ($category->id ?? 0)];
        }

        $categoryPlaceholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $categoryMatchSql = "(
            p.category_id IN ({$categoryPlaceholders})
            OR EXISTS (
                SELECT 1
                FROM {$prefix}post_category_rel pcr
                WHERE pcr.post_id = p.id
                  AND pcr.category_id IN ({$categoryPlaceholders})
            )
        )";
        $where = [\cms_post_publication_where('p'), $this->buildPostLocaleAvailabilityExpression('p', $locale), $categoryMatchSql];
        $params = array_merge($categoryIds, $categoryIds);

        if ($query !== '') {
            $where[] = $this->buildLocalizedPostSearchClause('p', $locale);
            $like = '%' . \cms_escape_like($query) . '%';
            array_push($params, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $db->get_var(
            "SELECT COUNT(*)
             FROM {$prefix}posts p
             WHERE {$whereSql}",
            $params
        );
        $totalPages = max(1, (int) ceil($total / $perPage));
        if (($query === '' && $total === 0) || $page > $totalPages) {
            $this->router->render404();
            return;
        }
        $offset = ($page - 1) * $perPage;

        $posts = $db->get_results(
            "SELECT p.*, c.name AS category_name, " . $this->categorySlugSelectExpression($locale) . ",
                    COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), NULLIF(u.username, ''), 'Autor') AS author_name
             FROM {$prefix}posts p
             LEFT JOIN {$prefix}users u ON u.id = p.author_id
             LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
             WHERE {$whereSql}
             ORDER BY COALESCE(p.published_at, p.created_at) DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        ) ?: [];

        ThemeManager::instance()->render('category', [
            'category' => $categoryData,
            'posts' => $posts,
            'query' => $query,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
        ]);
    }

    public function renderCategoryArchiveIndex(): void
    {
        $locale = $this->getResolvedContentLocale();
        $query = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? $_GET['p'] ?? 1));
        $perPage = 18;
        $overview = $this->buildArchiveOverviewPage(
            $this->archiveRepository->getPublishedCategoryOverview($locale, $this->buildPostLocaleAvailabilityExpression('p', $locale)),
            $query,
            $page,
            $perPage
        );
        if ($overview === null) {
            $this->router->render404();
            return;
        }

        ThemeManager::instance()->render('category', [
            'category' => [
                'name' => $locale === 'en' ? 'Categories' : 'Kategorien',
                'slug' => '',
                'description' => $locale === 'en'
                    ? 'Browse all editorial categories and jump directly into the right topic cluster.'
                    : 'Alle Themenbereiche im Überblick – direkt zum passenden Fachbereich springen.',
            ],
            'posts' => [],
            'query' => $query,
            'total' => $overview['total'],
            'currentPage' => $overview['currentPage'],
            'totalPages' => $overview['totalPages'],
            'perPage' => $perPage,
            'isOverview' => true,
            'overviewItems' => $overview['items'],
        ]);
    }

    public function renderTagArchive(string $slug): void
    {
        $normalizedSlug = $this->archiveRepository->normalizeArchiveSlug(rawurldecode($slug));
        if ($normalizedSlug === '') {
            $this->router->render404();
            return;
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();
        $locale = $this->getResolvedContentLocale();
        $query = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? $_GET['p'] ?? 1));
        $perPage = 10;

        $tagRow = $db->get_row(
            "SELECT id, name, slug, slug_en
             FROM {$prefix}post_tags
             WHERE slug = ? OR (slug_en IS NOT NULL AND slug_en != '' AND slug_en = ?)
             LIMIT 1",
            [$normalizedSlug, $normalizedSlug]
        );

        if ($tagRow !== null) {
            $where = [\cms_post_publication_where('p'), $this->buildPostLocaleAvailabilityExpression('p', $locale), 'ptr.tag_id = ?'];
            $params = [(int) ($tagRow->id ?? 0)];

            if ($query !== '') {
                $where[] = $this->buildLocalizedPostSearchClause('p', $locale);
                $like = '%' . \cms_escape_like($query) . '%';
                array_push($params, $like, $like, $like);
            }

            $whereSql = implode(' AND ', $where);
            $total = (int) $db->get_var(
                "SELECT COUNT(DISTINCT p.id)
                 FROM {$prefix}posts p
                 INNER JOIN {$prefix}post_tag_rel ptr ON ptr.post_id = p.id
                 WHERE {$whereSql}",
                $params
            );

            $totalPages = max(1, (int) ceil($total / $perPage));
            if (($query === '' && $total === 0) || $page > $totalPages) {
                $this->router->render404();
                return;
            }
            $offset = ($page - 1) * $perPage;

            $posts = $db->get_results(
                "SELECT DISTINCT p.*, c.name AS category_name, " . $this->categorySlugSelectExpression($locale) . ",
                        COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), NULLIF(u.username, ''), 'Autor') AS author_name
                 FROM {$prefix}posts p
                 INNER JOIN {$prefix}post_tag_rel ptr ON ptr.post_id = p.id
                 LEFT JOIN {$prefix}users u ON u.id = p.author_id
                 LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
                 WHERE {$whereSql}
                 ORDER BY COALESCE(p.published_at, p.created_at) DESC
                 LIMIT {$perPage} OFFSET {$offset}",
                $params
            ) ?: [];

            $tagSlugDe = trim((string) ($tagRow->slug ?? $normalizedSlug));
            $tagSlugEn = trim((string) ($tagRow->slug_en ?? ''));
            $tagLocalizedSlug = $locale === 'en' && $tagSlugEn !== '' ? $tagSlugEn : $tagSlugDe;

            ThemeManager::instance()->render('tag', [
                'tag' => [
                    'name' => (string) ($tagRow->name ?? str_replace('-', ' ', $normalizedSlug)),
                    'slug' => $tagLocalizedSlug,
                    'slug_de' => $tagSlugDe,
                    'slug_en' => $tagSlugEn,
                ],
                'posts' => $posts,
                'query' => $query,
                'total' => $total,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'perPage' => $perPage,
            ]);
            return;
        }

        // Legacy-Fallback (Tags nur als Freitext in posts.tags): zuerst nur ID und Tag-Feld laden,
        // Volltextzeilen anschließend nur für die passenden Beiträge (vorher lud jede unbekannte
        // /tag/xyz-URL alle getaggten Beiträge samt Inhalt).
        $publicationWhere = \cms_post_publication_where('p') . " AND " . $this->buildPostLocaleAvailabilityExpression('p', $locale);
        $tagRows = $db->get_results(
            "SELECT p.id, p.tags FROM {$prefix}posts p
               WHERE {$publicationWhere} AND p.tags IS NOT NULL AND p.tags != ''"
        ) ?: [];

        $matchingIds = [];
        $resolvedTagName = '';
        foreach ($tagRows as $tagRow) {
            foreach ($this->archiveRepository->parsePostTags((string) ($tagRow->tags ?? '')) as $tag) {
                if (($tag['slug'] ?? '') !== $normalizedSlug) {
                    continue;
                }

                if ($resolvedTagName === '') {
                    $resolvedTagName = (string) ($tag['name'] ?? '');
                }

                $matchingIds[] = (int) ($tagRow->id ?? 0);
                break;
            }
        }

        $rows = [];
        if ($matchingIds !== []) {
            $placeholders = implode(',', array_fill(0, count($matchingIds), '?'));
            $rows = $db->get_results(
                "SELECT p.*, c.name AS category_name,
                        COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), NULLIF(u.username, ''), 'Autor') AS author_name
                 FROM {$prefix}posts p
                 LEFT JOIN {$prefix}users u ON u.id = p.author_id
                 LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
                   WHERE p.id IN ({$placeholders}) AND {$publicationWhere}
                 ORDER BY COALESCE(p.published_at, p.created_at) DESC",
                $matchingIds
            ) ?: [];
        }

        $allMatchingPosts = [];
        $matchingPosts = [];

        foreach ($rows as $row) {
            $post = (array) $row;
            $allMatchingPosts[] = (object) $post;

            if ($this->matchesArchiveSearch($post, $query, $locale)) {
                $matchingPosts[] = (object) $post;
            }
        }

        if ($allMatchingPosts === []) {
            $this->router->render404();
            return;
        }

        $total = count($matchingPosts);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $this->router->render404();
            return;
        }
        $offset = ($page - 1) * $perPage;
        $posts = array_slice($matchingPosts, $offset, $perPage);

        ThemeManager::instance()->render('tag', [
            'tag' => [
                'name' => $resolvedTagName !== '' ? $resolvedTagName : str_replace('-', ' ', $normalizedSlug),
                'slug' => $normalizedSlug,
            ],
            'posts' => $posts,
            'query' => $query,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
        ]);
    }

    public function renderTagArchiveIndex(): void
    {
        $locale = $this->getResolvedContentLocale();
        $query = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? $_GET['p'] ?? 1));
        $perPage = 24;
        $overview = $this->buildArchiveOverviewPage(
            $this->archiveRepository->getPublishedTagOverview($locale, $this->buildPostLocaleAvailabilityExpression('p', $locale)),
            $query,
            $page,
            $perPage
        );
        if ($overview === null) {
            $this->router->render404();
            return;
        }

        ThemeManager::instance()->render('tag', [
            'tag' => [
                'name' => $locale === 'en' ? 'Tags' : 'Schlagwörter',
                'slug' => '',
                'description' => $locale === 'en'
                    ? 'Explore recurring topics, tools, releases and keyword clusters across the blog.'
                    : 'Alle Schlagwörter im Überblick – ideal für Serien, Tools, Releases und wiederkehrende Themen.',
            ],
            'posts' => [],
            'query' => $query,
            'total' => $overview['total'],
            'currentPage' => $overview['currentPage'],
            'totalPages' => $overview['totalPages'],
            'perPage' => $perPage,
            'isOverview' => true,
            'overviewItems' => $overview['items'],
        ]);
    }

    public function renderAuthorsIndex(): void
    {
        ThemeManager::instance()->render('authors');
    }

    public function renderHtmlSitemap(): void
    {
        ThemeManager::instance()->render('sitemap');
    }

    public function renderAuthorPage(string $identifier): void
    {
        $viewerIsLoggedIn = \CMS\Auth::instance()->isLoggedIn();
        $author = $this->resolveAuthorPageProfile($identifier, $viewerIsLoggedIn);

        if ($author === null) {
            $this->router->render404();
            return;
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();
        $locale = $this->getResolvedContentLocale();
        $perPage = 10;
        $authorId = (int)($author['id'] ?? 0);
        $localeFilter = $this->buildPostLocaleAvailabilityExpression('p', $locale);

        $total = (int)$db->get_var(
            "SELECT COUNT(*) FROM {$prefix}posts p WHERE p.author_id = ? AND " . \cms_post_publication_where('p') . " AND {$localeFilter}",
            [$authorId]
        );
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = max(1, (int)($_GET['page'] ?? 1));
        // Wie Kategorie-/Tag-Archive: überzählige Seiten sind 404. Riesige ?page=-Werte liefen sonst
        // als Float-OFFSET ins SQL (Syntaxfehler, HTTP 500).
        if ($page > $totalPages) {
            $this->router->render404();
            return;
        }
        $offset = ($page - 1) * $perPage;

        $posts = $db->get_results(
            "SELECT p.*, c.name AS category_name, " . $this->categorySlugSelectExpression($locale) . "
             FROM {$prefix}posts p
             LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
             WHERE p.author_id = ? AND " . \cms_post_publication_where('p') . " AND {$localeFilter}
             ORDER BY COALESCE(p.published_at, p.created_at) DESC
             LIMIT {$perPage} OFFSET {$offset}",
            [$authorId]
        ) ?: [];

        ThemeManager::instance()->render('author', [
            'author' => $author,
            'posts' => $posts,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveAuthorPageProfile(string $identifier, bool $viewerIsLoggedIn): ?array
    {
        $memberService = Services\MemberService::getInstance();
        $author = $memberService->getPublicAuthorProfile($identifier, $viewerIsLoggedIn);
        if ($author !== null) {
            return $author;
        }

        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $userId = 0;
        if (preg_match('/^user-(\d+)$/', $identifier, $matches) === 1) {
            $userId = (int) ($matches[1] ?? 0);
        } elseif (ctype_digit($identifier)) {
            $userId = (int) $identifier;
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();

        $user = $userId > 0
            ? $db->get_row(
                "SELECT id, username, display_name, status
                 FROM {$prefix}users
                 WHERE id = ?
                 LIMIT 1",
                [$userId]
            )
            : $db->get_row(
                "SELECT id, username, display_name, status
                 FROM {$prefix}users
                 WHERE username = ?
                 LIMIT 1",
                [$identifier]
            );

        if ($user === null || (string) ($user->status ?? 'active') === 'banned') {
            return null;
        }

        $resolvedUserId = (int) ($user->id ?? 0);
        if ($resolvedUserId <= 0) {
            return null;
        }

        $publishedPosts = (int) $db->get_var(
            "SELECT COUNT(*)
             FROM {$prefix}posts
             WHERE author_id = ? AND " . \cms_post_publication_where() . "",
            [$resolvedUserId]
        );

        if ($publishedPosts <= 0) {
            return null;
        }

        $displayName = trim((string) ($user->display_name ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($user->username ?? 'Autor'));
        }

        return [
            'id' => $resolvedUserId,
            'slug' => 'user-' . $resolvedUserId,
            'username' => (string) ($user->username ?? ''),
            'display_name' => $displayName !== '' ? $displayName : 'Autor',
            'bio' => '',
            'avatar_url' => '',
            'details' => [],
            'profile_visibility' => 'public',
            'show_activity' => true,
            'profile_url' => $memberService->buildPublicAuthorPath($resolvedUserId),
        ];
    }

    public function renderBlogSingle(string ...$segments): void
    {
        $slug = rawurldecode(trim((string)end($segments), '/'));
        if ($slug === '') {
            $this->router->render404();
            return;
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();
        $locale = $this->router->getRequestLocale();
        $localeAvailability = $this->buildPostLocaleAvailabilityExpression('p', $locale);
        $slugField = $locale === 'en' ? '(p.slug_en = ? OR p.slug = ?)' : 'p.slug = ?';
        $slugParams = $locale === 'en' ? [$slug, $slug] : [$slug];
        $postRow = $db->get_row(
            "SELECT p.*, COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), NULLIF(u.username, ''), 'Autor') AS author_name, c.name AS category_name, " . $this->categorySlugSelectExpression($locale) . "
             FROM {$prefix}posts p
             LEFT JOIN {$prefix}users u ON u.id = p.author_id
             LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
             WHERE {$slugField} AND {$localeAvailability}",
            $slugParams
        );

        if (!$postRow) {
            if ($locale === 'de') {
                $englishAvailability = $this->buildPostLocaleAvailabilityExpression('p', 'en');
                $englishRow = $db->get_row(
                    "SELECT p.*, COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), NULLIF(u.username, ''), 'Autor') AS author_name, c.name AS category_name, " . $this->categorySlugSelectExpression('en') . "
                     FROM {$prefix}posts p
                     LEFT JOIN {$prefix}users u ON u.id = p.author_id
                     LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
                     WHERE (p.slug_en = ? OR p.slug = ?) AND {$englishAvailability}",
                    [$slug, $slug]
                );

                if ($englishRow && ((string) ($englishRow->status ?? '') === 'private' || \cms_post_is_publicly_visible($englishRow))) {
                    $englishPost = Services\ContentLocalizationService::getInstance()->localizePost((array) $englishRow, 'en');
                    $this->router->redirect(Services\PermalinkService::getInstance()->buildPostPath($englishPost, 'en'), 301);
                    return;
                }
            }

            if (Services\PermalinkService::getInstance()->usesSlugOnlyStructure() && count($segments) === 1 && $this->renderPageFallback($slug)) {
                return;
            }

            $this->router->render404();
            return;
        }

        $postStatus = (string) ($postRow->status ?? '');
        if ($postStatus === 'private') {
            if (!\CMS\Auth::instance()->isLoggedIn()) {
                $this->router->redirect($this->buildLocalizedLoginRedirectPath(), 302);
                return;
            }
        } elseif (!\cms_post_is_publicly_visible($postRow)) {
            if (!\CMS\Auth::instance()->isLoggedIn()) {
                $this->router->redirect($this->buildLocalizedLoginRedirectPath(), 302);
                return;
            }

            if (!$this->canCurrentViewerAccessUnpublishedContent($postRow, 'edit_all_posts')) {
                $this->router->render404();
                return;
            }

            $this->sendDraftPreviewHeaders();
        }

        $requestContext = $this->router->getRequestContext();
        $requestBaseUri = (string)($requestContext['base_uri'] ?? '');
        $postData = Services\ContentLocalizationService::getInstance()->localizePost((array)$postRow, $locale);
        $permalinkService = Services\PermalinkService::getInstance();
        $canonicalBasePath = $permalinkService->buildPostPath($postData);
        $canonicalPath = $permalinkService->buildPostPath($postData, $locale);
        $localizedCanonicalContext = Services\ContentLocalizationService::getInstance()->resolveRequestContext($canonicalPath);
        $expectedRequestBasePath = $locale === 'de'
            ? $canonicalBasePath
            : (string) ($localizedCanonicalContext['base_uri'] ?? $canonicalBasePath);

        if ($requestBaseUri !== '' && $requestBaseUri !== $expectedRequestBasePath) {
            $query = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
            $target = $canonicalPath . ($query !== '' ? '?' . $query : '');
            $this->router->redirect($target, 301);
            return;
        }

        $postData = $this->attachLegacyCompatibleTagsToPost($postData);

        $post = (object)$postData;
        if (\cms_post_is_publicly_visible($postRow) && $this->shouldCountPostView()) {
            $db->execute("UPDATE {$prefix}posts SET views = views + 1 WHERE id = ?", [(int)$post->id]);
        }

        if (!empty($post->content)) {
            $post->content = $this->router->prepareRenderableContent((string)$post->content, 'post', (int)($post->id ?? 0));
        }

        if (isset($_GET['pdf']) && $_GET['pdf'] === '1') {
            $this->router->streamContentAsPdf(
                htmlspecialchars((string)($post->title ?? 'Beitrag'), ENT_QUOTES, 'UTF-8'),
                (string)$post->content,
                $post->author_name ?? null
            );
            return;
        }

        ThemeManager::instance()->render('blog-single', [
            'post' => $post,
            'contentLocale' => $locale,
        ]);
    }

    public function renderLegacyBlogSingle(string $slug): void
    {
        $this->renderBlogSingle($slug);
    }

    public function serveSitemap(): void
    {
        $this->serveSitemapFile('sitemap.xml');
    }

    public function serveSitemapFile(string $fileName): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/xml; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            header('X-Robots-Tag: noindex', true);
        }
        echo Services\SEOService::getInstance()->getSitemapFile($fileName);
        exit;
    }

    public function serveRssFeed(): void
    {
        $db = Database::instance();
        $prefix = $db->getPrefix();
        $locale = $this->router->getRequestLocale();
        $localeFilter = $this->buildPostLocaleAvailabilityExpression('p', $locale);
        $siteTitle = defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
        $siteDescription = 'Aktuelle Beiträge von ' . $siteTitle;
        $feedUrl = SITE_URL . '/feed';
        $language = $locale === 'en' ? 'en' : 'de';

        if (!headers_sent()) {
            header('Content-Type: application/rss+xml; charset=utf-8');
            header('X-Robots-Tag: noindex, follow', true);
        }

        // Kurzzeit-Cache (5 Minuten): Feed-Reader und Bots fragen oft im Minutentakt ab. Schreibzugriffe auf
        // Beiträge/Kategorien löschen den Cache sofort (Database::invalidateFeedCache()).
        $cacheDir = rtrim((string) ABSPATH, '/\\') . '/cache/feed';
        $cacheFile = $cacheDir . '/rss-' . (preg_replace('/[^a-z_-]/', '', strtolower((string) $locale)) ?: $language) . '-' . substr(hash('sha256', (string) SITE_URL), 0, 16) . '.xml';
        $cachedMtime = @filemtime($cacheFile);
        if ($cachedMtime !== false && $cachedMtime > time() - self::FEED_CACHE_TTL) {
            $cached = @file_get_contents($cacheFile);
            if (is_string($cached) && $cached !== '') {
                echo $cached;
                exit;
            }
        }

        ob_start();

        $posts = $db->get_results(
            "SELECT p.*, COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), NULLIF(u.username, ''), 'Autor') AS author_name, c.name AS category_name
             FROM {$prefix}posts p
             LEFT JOIN {$prefix}users u ON u.id = p.author_id
             LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
                             WHERE " . \cms_post_publication_where('p') . " AND {$localeFilter}
             ORDER BY COALESCE(p.published_at, p.created_at) DESC
             LIMIT 25"
        ) ?: [];

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<rss version=\"2.0\">\n";
        echo "<channel>\n";
        echo '  <title>' . $this->escapeXml($siteTitle) . "</title>\n";
        echo '  <link>' . $this->escapeXml(SITE_URL . '/') . "</link>\n";
        echo '  <description>' . $this->escapeXml($siteDescription) . "</description>\n";
        echo '  <language>' . $this->escapeXml($language) . "</language>\n";
        echo '  <atom:link xmlns:atom="http://www.w3.org/2005/Atom" href="' . $this->escapeXml($feedUrl) . '" rel="self" type="application/rss+xml" />' . "\n";
        echo '  <lastBuildDate>' . gmdate(DATE_RSS) . "</lastBuildDate>\n";

        foreach ($posts as $postRow) {
            $postData = Services\ContentLocalizationService::getInstance()->localizePost((array) $postRow, $locale);
            $title = trim((string) ($postData['title'] ?? 'Beitrag'));
            $link = SITE_URL . Services\PermalinkService::getInstance()->buildPostPath($postData, $locale);
            $guid = $link;
            $pubDate = (string) ($postData['published_at'] ?? $postData['created_at'] ?? '');
            $excerpt = trim((string) ($postData['excerpt'] ?? ''));
            $content = trim((string) ($postData['content'] ?? ''));
            $description = $this->buildFeedDescription($excerpt, $content);
            $categoryName = trim((string) ($postData['category_name'] ?? ''));
            $author = trim((string) ($postData['author_name'] ?? ''));

            echo "  <item>\n";
            echo '    <title>' . $this->escapeXml($title) . "</title>\n";
            echo '    <link>' . $this->escapeXml($link) . "</link>\n";
            echo '    <guid isPermaLink="true">' . $this->escapeXml($guid) . "</guid>\n";
            if ($pubDate !== '') {
                echo '    <pubDate>' . $this->escapeXml(gmdate(DATE_RSS, strtotime($pubDate))) . "</pubDate>\n";
            }
            if ($author !== '') {
                echo '    <author>' . $this->escapeXml($author) . "</author>\n";
            }
            if ($categoryName !== '') {
                echo '    <category>' . $this->escapeXml($categoryName) . "</category>\n";
            }
            if ($description !== '') {
                echo '    <description>' . $this->wrapCdata($description) . "</description>\n";
            }
            echo "  </item>\n";
        }

        echo "</channel>\n";
        echo "</rss>";

        $xml = (string) ob_get_clean();
        if ((is_dir($cacheDir) || @mkdir($cacheDir, 0755, true)) && is_writable($cacheDir)) {
            $tmp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $xml, LOCK_EX) !== false && !@rename($tmp, $cacheFile)) {
                @unlink($tmp);
            }
        }

        echo $xml;
        exit;
    }

    public function serveRobotsTxt(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo Services\SEOService::getInstance()->generateRobotsTxt();
        exit;
    }

    public function serveSecurityTxt(): void
    {
        $canonicalUrl = rtrim(SITE_URL, '/') . '/.well-known/security.txt';
        $contactEmail = $this->resolveSecurityContactEmail();
        $expires = gmdate('Y-m-d\TH:i:s\Z', strtotime('+180 days'));

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }

        $lines = [
            'Contact: mailto:' . $contactEmail,
            'Canonical: ' . $canonicalUrl,
            'Preferred-Languages: de, en',
            'Expires: ' . $expires,
        ];

        echo implode("\n", $lines) . "\n";
        exit;
    }

    public function serveIndexNowKeyFile(): void
    {
        $indexNowKey = Services\IndexingService::getInstance()->getIndexNowKey();
        if ($indexNowKey === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Page not found';
            exit;
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $indexNowKey;
        exit;
    }

    private function renderPageFallback(string $slug): bool
    {
        $hubPage = Services\SiteTableService::getInstance()->getHubPageBySlug($slug, $this->router->getRequestLocale());
        if ($hubPage !== null) {
            ThemeManager::instance()->render('page', ['page' => $hubPage, 'contentLocale' => $this->router->getRequestLocale()]);
            return true;
        }

        $pageManager = PageManager::instance();
        $locale = $this->router->getRequestLocale();
        $page = $pageManager->getPageBySlug($slug, $locale);
        if ($page !== null && $pageManager->pageMatchesLocaleAvailability($page, $locale)) {
            $pageStatus = (string) ($page['status'] ?? '');
            if ($pageStatus === 'private') {
                if (!\CMS\Auth::instance()->isLoggedIn()) {
                    $this->router->redirect($this->buildLocalizedLoginRedirectPath(), 302);
                    return true;
                }
            } elseif ($pageStatus !== 'published') {
                if (!\CMS\Auth::instance()->isLoggedIn()) {
                    $this->router->redirect($this->buildLocalizedLoginRedirectPath(), 302);
                    return true;
                }

                if (!$this->canCurrentViewerAccessUnpublishedContent($page, 'manage_pages')) {
                    return false;
                }

                $this->sendDraftPreviewHeaders();
            }

            $page = Services\ContentLocalizationService::getInstance()->localizePage($page, $locale);
            if (!empty($page['content'])) {
                $page['content'] = $this->router->prepareRenderableContent((string)$page['content'], 'page', (int)($page['id'] ?? 0));
            }

            ThemeManager::instance()->render('page', ['page' => $page, 'contentLocale' => $locale]);
            return true;
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array{items:array<int,array<string,mixed>>,total:int,currentPage:int,totalPages:int}|null
     */
    private function buildArchiveOverviewPage(array $items, string $query, int $page, int $perPage): ?array
    {
        if ($query !== '') {
            $needle = mb_strtolower($query, 'UTF-8');
            $items = array_values(array_filter($items, static function (array $item) use ($needle): bool {
                $haystack = mb_strtolower(
                    trim((string) ($item['title'] ?? '')) . ' ' . trim((string) ($item['description'] ?? '')) . ' ' . trim((string) ($item['slug'] ?? '')),
                    'UTF-8'
                );

                return str_contains($haystack, $needle);
            }));
        }

        $total = count($items);
        $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
        if ($page > $totalPages) {
            return null;
        }
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * SQL-Ausdruck für den sprachabhängigen Kategorie-Slug in Beitragslisten.
     */
    private function categorySlugSelectExpression(string $locale, string $alias = 'c'): string
    {
        return $locale === 'en'
            ? "COALESCE(NULLIF({$alias}.slug_en, ''), {$alias}.slug) AS category_slug"
            : "{$alias}.slug AS category_slug";
    }

    private function resolveRequestedCategorySlug(string $value): string
    {
        $value = trim(rawurldecode($value));
        if ($value === '') {
            return '';
        }
        $db = Database::instance();
        $prefix = $db->getPrefix();
        $rows = $db->get_results(
            "SELECT slug, slug_en, name
             FROM {$prefix}post_categories",
            []
        ) ?: [];

        $needle = mb_strtolower($value, 'UTF-8');
        $normalizedNeedle = $this->archiveRepository->normalizeArchiveSlug($value);

        foreach ($rows as $row) {
            $slug = trim((string) ($row->slug ?? ''));
            $slugEn = trim((string) ($row->slug_en ?? ''));
            $name = trim((string) ($row->name ?? ''));

            if ($slug === '') {
                continue;
            }

            if ($slug === $value || ($slugEn !== '' && $slugEn === $value) || mb_strtolower($name, 'UTF-8') === $needle) {
                return $slug;
            }

            if ($normalizedNeedle !== '' && $this->archiveRepository->normalizeArchiveSlug($name) === $normalizedNeedle) {
                return $slug;
            }
        }

        if ($normalizedNeedle !== '') {
            $redirectedSlug = $this->resolveArchiveSlugFromRedirect('category', $normalizedNeedle);
            if ($redirectedSlug !== '') {
                return $redirectedSlug;
            }
        }

        return '';
    }

    private function resolveRequestedTagSlug(string $value): string
    {
        $value = trim(rawurldecode($value));
        if ($value === '') {
            return '';
        }

        $normalizedNeedle = $this->archiveRepository->normalizeArchiveSlug($value);
        if ($normalizedNeedle === '') {
            return '';
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();
        $rows = $db->get_results(
            "SELECT slug, slug_en, name
             FROM {$prefix}post_tags",
            []
        ) ?: [];

        $needle = mb_strtolower($value, 'UTF-8');

        foreach ($rows as $row) {
            $slug = trim((string) ($row->slug ?? ''));
            $slugEn = trim((string) ($row->slug_en ?? ''));
            $name = trim((string) ($row->name ?? ''));

            if ($slug === '') {
                continue;
            }

            if ($slug === $value || ($slugEn !== '' && $slugEn === $value) || mb_strtolower($name, 'UTF-8') === $needle) {
                return $slug;
            }

            if ($this->archiveRepository->normalizeArchiveSlug($name) === $normalizedNeedle) {
                return $slug;
            }
        }

        $redirectedSlug = $this->resolveArchiveSlugFromRedirect('tag', $normalizedNeedle);
        if ($redirectedSlug !== '') {
            return $redirectedSlug;
        }

        return $normalizedNeedle;
    }

    private function resolveArchiveSlugFromRedirect(string $archiveType, string $slug): string
    {
        $slug = $this->archiveRepository->normalizeArchiveSlug($slug);
        if ($slug === '' || !function_exists('cms_get_archive_locales') || !function_exists('cms_get_archive_base')) {
            return '';
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();
        $checkedPaths = [];

        foreach (cms_get_archive_locales() as $locale) {
            $archiveBase = trim((string) cms_get_archive_base($archiveType, (string) $locale), '/');
            if ($archiveBase === '') {
                continue;
            }

            $sourcePath = '/' . $archiveBase . '/' . $slug;
            if (isset($checkedPaths[$sourcePath])) {
                continue;
            }

            $checkedPaths[$sourcePath] = true;

            $row = $db->get_row(
                "SELECT target_url FROM {$prefix}redirect_rules WHERE source_path = ? AND site_scope = ? AND is_active = 1 LIMIT 1",
                [$sourcePath, '']
            );

            if ($row === null) {
                continue;
            }

            $resolvedSlug = $this->extractArchiveSlugFromTarget($archiveType, (string) ($row->target_url ?? ''));
            if ($resolvedSlug !== '') {
                return $resolvedSlug;
            }
        }

        return '';
    }

    private function extractArchiveSlugFromTarget(string $archiveType, string $targetUrl): string
    {
        $targetUrl = trim($targetUrl);
        if ($targetUrl === '' || !function_exists('cms_get_archive_locales') || !function_exists('cms_get_archive_base')) {
            return '';
        }

        if (($queryPosition = strpos($targetUrl, '?')) !== false) {
            $targetUrl = substr($targetUrl, 0, $queryPosition);
        }

        if (filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            $targetUrl = (string) parse_url($targetUrl, PHP_URL_PATH);
        }

        $targetPath = '/' . ltrim($targetUrl, '/');
        if ($targetPath !== '/') {
            $targetPath = rtrim($targetPath, '/');
        }

        foreach (cms_get_archive_locales() as $locale) {
            $archiveBase = trim((string) cms_get_archive_base($archiveType, (string) $locale), '/');
            if ($archiveBase === '') {
                continue;
            }

            $archivePrefix = '/' . $archiveBase . '/';
            if (!str_starts_with($targetPath, $archivePrefix)) {
                continue;
            }

            $resolvedSlug = trim(substr($targetPath, strlen($archivePrefix)), '/');

            return $this->archiveRepository->normalizeArchiveSlug($resolvedSlug);
        }

        return '';
    }


    /**
     * Aufrufzähler nur für echte Seitenaufrufe: HEAD-Anfragen, Prefetch und Crawler/Monitoring
     * schrieben sonst bei jedem Abruf in `posts` (Schreiblast, verfälschte „Beliebt“-Listen).
     */
    private function shouldCountPostView(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }

        $purpose = strtolower((string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? $_SERVER['HTTP_X_MOZ'] ?? ''));
        if ($purpose !== '' && str_contains($purpose, 'prefetch')) {
            return false;
        }

        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($userAgent === '') {
            return false;
        }

        return preg_match('/bot|crawl|spider|slurp|scrape|preview|monitor|uptime|headless|lighthouse|curl|wget|python|java\/|go-http|okhttp|httpclient|facebookexternalhit|embedly/i', $userAgent) !== 1;
    }

    /**
     * @param array<string,mixed> $postData
     * @return array<string,mixed>
     */
    private function attachLegacyCompatibleTagsToPost(array $postData): array
    {
        $postId = (int) ($postData['id'] ?? 0);
        if ($postId <= 0) {
            return $postData;
        }

        $tagRows = $this->archiveRepository->getPostTagRows($postId, $this->getResolvedContentLocale());
        if ($tagRows !== []) {
            $postData['tags'] = implode(', ', array_map(
                static fn(array $tag): string => (string) ($tag['name'] ?? ''),
                $tagRows
            ));
            $postData['tag_items'] = $tagRows;
            return $postData;
        }

        $legacyTags = $this->archiveRepository->parsePostTags((string) ($postData['tags'] ?? ''));
        if ($legacyTags !== []) {
            $postData['tag_items'] = $legacyTags;
        }

        return $postData;
    }

    private function buildLocalizedLoginRedirectPath(): string
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $loginPath = CmsAuthPageService::getInstance()->getPublicPath('login', $this->router->getRequestLocale());

        return $loginPath . '?redirect=' . urlencode($requestUri);
    }

    /** @param array<string,mixed>|object $content */
    private function canCurrentViewerAccessUnpublishedContent(array|object $content, string $capability): bool
    {
        $auth = \CMS\Auth::instance();
        $viewer = $auth->currentUser();
        $viewerId = (int) ($viewer->id ?? 0);
        $authorId = is_array($content)
            ? (int) ($content['author_id'] ?? 0)
            : (int) ($content->author_id ?? 0);

        if ($viewerId <= 0) {
            return false;
        }

        if (\CMS\Auth::isAdmin() || $auth->hasCapability($capability)) {
            return true;
        }

        return $authorId > 0 && $authorId === $viewerId;
    }

    private function sendDraftPreviewHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Robots-Tag: noindex, nofollow', true);
        header('Cache-Control: private, no-store, no-cache, must-revalidate', true);
        header('Pragma: no-cache', true);
    }


    private function buildLocalizedPostSearchClause(string $alias, string $locale): string
    {
        $locale = Services\ContentLocalizationService::getInstance()->normalizeLocale($locale);

        if ($locale === 'en') {
            return '('
                . "COALESCE(NULLIF({$alias}.title_en, ''), {$alias}.title) LIKE ?"
                . " OR COALESCE(NULLIF({$alias}.excerpt_en, ''), {$alias}.excerpt) LIKE ?"
                . " OR COALESCE(NULLIF({$alias}.content_en, ''), {$alias}.content) LIKE ?"
                . ')';
        }

        return "({$alias}.title LIKE ? OR {$alias}.excerpt LIKE ? OR {$alias}.content LIKE ?)";
    }

    private function buildLocalizedPostSearchHaystack(array $post, string $locale): string
    {
        $locale = Services\ContentLocalizationService::getInstance()->normalizeLocale($locale);

        if ($locale === 'en') {
            $title = trim((string) ($post['title_en'] ?? '')) !== ''
                ? (string) ($post['title_en'] ?? '')
                : (string) ($post['title'] ?? '');
            $excerpt = trim((string) ($post['excerpt_en'] ?? '')) !== ''
                ? (string) ($post['excerpt_en'] ?? '')
                : (string) ($post['excerpt'] ?? '');
            $content = trim((string) ($post['content_en'] ?? '')) !== ''
                ? (string) ($post['content_en'] ?? '')
                : (string) ($post['content'] ?? '');

            return trim($title . ' ' . $excerpt . ' ' . $content);
        }

        return trim(
            trim((string) ($post['title'] ?? '')) . ' '
            . trim((string) ($post['excerpt'] ?? '')) . ' '
            . trim((string) ($post['content'] ?? ''))
        );
    }


    private function matchesArchiveSearch(array $post, string $query, string $locale): bool
    {
        if ($query === '') {
            return true;
        }

        $haystack = mb_strtolower($this->buildLocalizedPostSearchHaystack($post, $locale), 'UTF-8');

        return str_contains($haystack, mb_strtolower($query, 'UTF-8'));
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function wrapCdata(string $value): string
    {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
    }

    private function buildFeedDescription(string $excerpt, string $content): string
    {
        $sources = array_values(array_filter([$excerpt, $content], static fn(string $value): bool => trim($value) !== ''));
        if ($sources === []) {
            return '';
        }

        foreach ($sources as $source) {
            $plainText = $this->extractFeedPlainText($source);
            if ($plainText !== '') {
                return mb_substr($plainText, 0, 320);
            }
        }

        return '';
    }

    private function extractFeedPlainText(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        $rendered = Services\EditorService::getInstance()->renderContent($source);
        $plainText = $this->normalizeFeedPlainText($rendered);

        if ($plainText !== '' && !$this->looksLikeEditorJsPayload($plainText)) {
            return $plainText;
        }

        $editorJsText = $this->extractEditorJsSnippet($source);
        if ($editorJsText !== '') {
            return $editorJsText;
        }

        if ($source !== $rendered) {
            $plainText = $this->normalizeFeedPlainText($source);
        }

        return $this->looksLikeEditorJsPayload($plainText) ? '' : $plainText;
    }

    private function normalizeFeedPlainText(string $value): string
    {
        $plainText = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plainText = preg_replace('/\s+/u', ' ', $plainText) ?? '';

        return trim($plainText);
    }

    private function looksLikeEditorJsPayload(string $value): bool
    {
        $normalized = ltrim($value);

        return str_starts_with($normalized, '{"time":')
            || str_starts_with($normalized, '{"blocks":')
            || str_starts_with($normalized, '{"id":')
            || str_contains($normalized, '"blocks":[{');
    }

    private function extractEditorJsSnippet(string $source): string
    {
        $decoded = Json::decodeArray($source, []);
        if ($decoded !== [] && isset($decoded['blocks']) && is_array($decoded['blocks'])) {
            $collected = [];
            foreach ($decoded['blocks'] as $block) {
                if (!is_array($block)) {
                    continue;
                }

                $text = trim((string) ($block['data']['text'] ?? $block['data']['html'] ?? ''));
                if ($text !== '') {
                    $collected[] = $this->normalizeFeedPlainText($text);
                }

                if (count($collected) >= 3) {
                    break;
                }
            }

            return trim(implode(' ', array_filter($collected, static fn(string $value): bool => $value !== '')));
        }

        if (preg_match_all('/"(?:text|html)"\s*:\s*"((?:\\.|[^"\\\\])*)"/u', $source, $matches) === 1 || (isset($matches[1]) && $matches[1] !== [])) {
            $parts = [];
            foreach ($matches[1] as $rawMatch) {
                $decodedMatch = json_decode('"' . $rawMatch . '"', true);
                $text = is_string($decodedMatch) ? $decodedMatch : stripcslashes($rawMatch);
                $text = $this->normalizeFeedPlainText($text);
                if ($text !== '') {
                    $parts[] = $text;
                }
                if (count($parts) >= 3) {
                    break;
                }
            }

            return trim(implode(' ', $parts));
        }

        return '';
    }

    private function resolveSecurityContactEmail(): string
    {
        if (defined('ADMIN_EMAIL')) {
            $adminEmail = trim((string) ADMIN_EMAIL);
            if ($adminEmail !== '') {
                return $adminEmail;
            }
        }

        $host = (string) (parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost');

        return 'security@' . preg_replace('/^www\./i', '', $host);
    }

    private function getResolvedContentLocale(): string
    {
        $locale = Services\ContentLocalizationService::getInstance()->normalizeLocale($this->router->getRequestLocale());

        return $locale !== '' ? $locale : 'de';
    }

    private function buildPostLocaleAvailabilityExpression(string $alias, string $locale): string
    {
        $localization = Services\ContentLocalizationService::getInstance();
        $locale = $localization->normalizeLocale($locale);
        $baseContent = $this->buildBasePostContentExpression($alias);
        $englishLegacyOnly = $this->buildLegacyEnglishOnlyPostExpression($alias);

        if ($locale === '' || $locale === 'de') {
            return "{$baseContent} AND NOT {$englishLegacyOnly}";
        }

        if (!in_array($locale, $localization->getContentLocales(), true)) {
            return '1=1';
        }

        $localizedContent = $this->buildLocalizedPostContentExpression($alias, $locale);

        if ($locale === 'en') {
            return "({$localizedContent} OR {$englishLegacyOnly})";
        }

        return $localizedContent;
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
}
}
