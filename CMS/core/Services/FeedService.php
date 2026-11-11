<?php
/**
 * Feed Service – RSS/Atom-Parsing und Ausgabe.
 *
 * Zentraler Service für eingehende RSS-/Atom-Feeds und die öffentliche RSS-Ausgabe.
 * Eingehende Feeds nutzen DOM/XML, einen abgesicherten Remote-Fetch und einen JSON-Datei-Cache.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Http\Client as HttpClient;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedService
{
    private const PUBLIC_FEED_CACHE_TTL = 300;
    private const MAX_ITEMS_PER_FEED = 100;
    private const MAX_FEEDS_PER_BATCH = 20;
    private const MAX_TITLE_LENGTH = 300;
    private const MAX_DESCRIPTION_LENGTH = 12000;
    private const MAX_CONTENT_LENGTH = 40000;
    private const MAX_CATEGORY_LENGTH = 80;
    private const MAX_CATEGORY_COUNT = 10;
    private const MAX_GUID_LENGTH = 255;
    private const MAX_ERROR_LENGTH = 180;
    private const MAX_FEED_BYTES = 2097152;
    private const MAX_REMOTE_REDIRECTS = 5;

    private static ?self $instance = null;

    private readonly string $cachePath;
    private readonly bool $available;
    private readonly bool $cacheEnabled;
    private readonly Logger $logger;

    private int $fetchTimeout = 15;
    private int $cacheDuration = 3600;
    private string $userAgent;

    /** @var array{code:string,detail:string} Grund des letzten fehlgeschlagenen Abrufs (nur für Admin-Protokolle). */
    private array $lastError = ['code' => '', 'detail' => ''];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->cachePath = ABSPATH . '/cache/feeds/';
        $this->logger = Logger::instance()->withChannel('feeds');
        $this->available = class_exists(\DOMDocument::class) && class_exists(\DOMXPath::class);
        $this->userAgent = '365CMS/' . (defined('CMS_VERSION') ? CMS_VERSION : '2.0') . ' (NativeFeedParser)';
        $this->cacheEnabled = $this->ensureDirectory($this->cachePath, 'Feed-Cache-Verzeichnis');
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Verwirft die zwischengespeicherten öffentlichen RSS-Feeds (`cache/feed/*.xml`).
     */
    public static function invalidatePublicFeedCache(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }

        foreach (glob(rtrim((string) ABSPATH, '/\\') . '/cache/feed/*.xml') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function serveRssFeed(string $requestLocale): never
    {
        $db = \CMS\Database::instance();
        $prefix = $db->getPrefix();
        $feedSettings = SettingsService::getInstance()->getGroup('feeds');
        if (array_key_exists('enabled', $feedSettings) && !filter_var($feedSettings['enabled'], FILTER_VALIDATE_BOOLEAN)) {
            http_response_code(404);
            exit;
        }

        $localization = ContentLocalizationService::getInstance();
        $supportedLocales = $localization->getContentLocales();
        array_unshift($supportedLocales, 'de');
        $languageEnabled = !array_key_exists('language_feeds', $feedSettings)
            || filter_var($feedSettings['language_feeds'], FILTER_VALIDATE_BOOLEAN);
        $locale = $requestLocale;
        $requestedLocale = is_string($_GET['lang'] ?? null) ? strtolower(trim($_GET['lang'])) : '';
        if ($languageEnabled && in_array($requestedLocale, $supportedLocales, true)) {
            $locale = $requestedLocale;
        }
        if (!$languageEnabled || !in_array($locale, $supportedLocales, true)) {
            $locale = 'de';
        }

        $localeFilter = $localization->buildPostAvailabilitySql('p', $locale);
        $siteTitle = defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
        $siteDescription = 'Aktuelle Beiträge von ' . $siteTitle;
        $itemsPerFeed = filter_var($feedSettings['items_per_feed'] ?? 25, FILTER_VALIDATE_INT);
        $itemsPerFeed = max(1, min(100, $itemsPerFeed === false ? 25 : $itemsPerFeed));
        $contentMode = ($feedSettings['content_mode'] ?? 'excerpt') === 'full' ? 'full' : 'excerpt';
        $categoryEnabled = !array_key_exists('category_feeds', $feedSettings) || filter_var($feedSettings['category_feeds'], FILTER_VALIDATE_BOOLEAN);
        $tagEnabled = !array_key_exists('tag_feeds', $feedSettings) || filter_var($feedSettings['tag_feeds'], FILTER_VALIDATE_BOOLEAN);
        $includeFeaturedImage = array_key_exists('include_featured_image', $feedSettings)
            && filter_var($feedSettings['include_featured_image'], FILTER_VALIDATE_BOOLEAN);
        if ((!$categoryEnabled && isset($_GET['category'])) || (!$tagEnabled && isset($_GET['tag']))) {
            http_response_code(404);
            exit;
        }
        $categorySlug = $categoryEnabled && is_string($_GET['category'] ?? null)
            ? $this->normalizePublicTaxonomySlug($_GET['category'])
            : '';
        $tagSlug = $tagEnabled && is_string($_GET['tag'] ?? null)
            ? $this->normalizePublicTaxonomySlug($_GET['tag'])
            : '';
        if ((isset($_GET['category']) && $categorySlug === '') || (isset($_GET['tag']) && $tagSlug === '')) {
            http_response_code(400);
            exit;
        }
        if ($categorySlug !== '' && $tagSlug !== '') {
            http_response_code(400);
            exit;
        }

        $feedQuery = [];
        if ($locale !== 'de' && $languageEnabled) {
            $feedQuery['lang'] = $locale;
        }
        if ($categorySlug !== '') {
            $feedQuery['category'] = $categorySlug;
        }
        if ($tagSlug !== '') {
            $feedQuery['tag'] = $tagSlug;
        }
        $feedUrl = rtrim((string) SITE_URL, '/') . '/feed' . ($feedQuery !== [] ? '?' . http_build_query($feedQuery, '', '&', PHP_QUERY_RFC3986) : '');
        $language = str_replace('_', '-', $locale);

        // Archive routes accept the German and the English slug in every locale; unknown terms are 404.
        $categoryIds = [];
        $tagId = 0;
        $termName = '';
        if ($categorySlug !== '') {
            $category = $db->get_row(
                "SELECT id, name FROM {$prefix}post_categories
                 WHERE slug = ? OR (slug_en IS NOT NULL AND slug_en != '' AND slug_en = ?)
                 ORDER BY (slug = ?) DESC, id ASC
                 LIMIT 1",
                [$categorySlug, $categorySlug, $categorySlug]
            );
            if ($category === null) {
                http_response_code(404);
                exit;
            }
            $categoryIds = (new \CMS\Routing\ThemeArchiveRepository())->getCategoryArchiveIds((int) ($category->id ?? 0));
            if ($categoryIds === []) {
                $categoryIds = [(int) ($category->id ?? 0)];
            }
            $termName = trim((string) ($category->name ?? ''));
        }
        if ($tagSlug !== '') {
            $tag = $db->get_row(
                "SELECT id, name FROM {$prefix}post_tags
                 WHERE slug = ? OR (slug_en IS NOT NULL AND slug_en != '' AND slug_en = ?)
                 ORDER BY (slug = ?) DESC, id ASC
                 LIMIT 1",
                [$tagSlug, $tagSlug, $tagSlug]
            );
            if ($tag === null) {
                http_response_code(404);
                exit;
            }
            $tagId = (int) ($tag->id ?? 0);
            $termName = trim((string) ($tag->name ?? ''));
        }
        if ($termName !== '') {
            $siteDescription = $siteTitle . ' – ' . $termName;
            $siteTitle .= ' – ' . $termName;
        }

        if (!headers_sent()) {
            header('Content-Type: application/rss+xml; charset=utf-8');
            header('X-Robots-Tag: noindex, follow', true);
        }

        $cacheDir = rtrim((string) ABSPATH, '/\\') . '/cache/feed';
        $cacheKey = hash('sha256', json_encode([$locale, $categorySlug, $tagSlug, $feedSettings], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $cacheFile = $cacheDir . '/rss-' . substr($cacheKey, 0, 48) . '.xml';
        $cachedMtime = @filemtime($cacheFile);
        if ($cachedMtime !== false && $cachedMtime > time() - self::PUBLIC_FEED_CACHE_TTL) {
            $cached = @file_get_contents($cacheFile);
            if (is_string($cached) && $cached !== '') {
                $this->sendRssResponse($cached, $cachedMtime);
            }
        }

        $where = \cms_post_publication_where('p') . " AND {$localeFilter}";
        $params = [];
        if ($categoryIds !== []) {
            $categoryPlaceholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $where .= " AND (
                p.category_id IN ({$categoryPlaceholders})
                OR EXISTS (
                    SELECT 1 FROM {$prefix}post_category_rel pcr
                    WHERE pcr.post_id = p.id
                      AND pcr.category_id IN ({$categoryPlaceholders})
                )
            )";
            array_push($params, ...$categoryIds, ...$categoryIds);
        }
        if ($tagId > 0) {
            $where .= " AND EXISTS (
                SELECT 1 FROM {$prefix}post_tag_rel ptr
                WHERE ptr.post_id = p.id AND ptr.tag_id = ?
            )";
            $params[] = $tagId;
        }
        $params[] = $itemsPerFeed;

        $posts = $db->get_results(
            "SELECT p.*, COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), NULLIF(u.username, ''), 'Autor') AS author_name, c.name AS category_name
             FROM {$prefix}posts p
             LEFT JOIN {$prefix}users u ON u.id = p.author_id
             LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
             WHERE {$where}
             ORDER BY COALESCE(p.published_at, p.created_at) DESC
             LIMIT ?",
            $params
        ) ?: [];

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:media="http://search.yahoo.com/mrss/">' . "\n";
        $xml .= "<channel>\n";
        $xml .= '  <title>' . $this->escapeXml($siteTitle) . "</title>\n";
        $xml .= '  <link>' . $this->escapeXml(rtrim((string) SITE_URL, '/') . '/') . "</link>\n";
        $xml .= '  <description>' . $this->escapeXml($siteDescription) . "</description>\n";
        $xml .= '  <language>' . $this->escapeXml($language) . "</language>\n";
        $xml .= '  <atom:link href="' . $this->escapeXml($feedUrl) . '" rel="self" type="application/rss+xml" />' . "\n";
        $xml .= '  <lastBuildDate>' . gmdate(DATE_RSS) . "</lastBuildDate>\n";

        foreach ($posts as $postRow) {
            $postData = $localization->localizePost((array) $postRow, $locale);
            $title = trim((string) ($postData['title'] ?? 'Beitrag'));
            $link = SITE_URL . PermalinkService::getInstance()->buildPostPath($postData, $locale);
            $pubDate = (string) ($postData['published_at'] ?? $postData['created_at'] ?? '');
            $excerpt = trim((string) ($postData['excerpt'] ?? ''));
            $content = trim((string) ($postData['content'] ?? ''));
            $description = $this->buildPublicFeedDescription($excerpt, $content);
            $categoryName = trim((string) ($postData['category_name'] ?? ''));
            $author = trim((string) ($postData['author_name'] ?? ''));

            $xml .= "  <item>\n";
            $xml .= '    <title>' . $this->escapeXml($title) . "</title>\n";
            $xml .= '    <link>' . $this->escapeXml($link) . "</link>\n";
            $xml .= '    <guid isPermaLink="true">' . $this->escapeXml($link) . "</guid>\n";
            if ($pubDate !== '') {
                $timestamp = strtotime($pubDate);
                if ($timestamp !== false) {
                    $xml .= '    <pubDate>' . $this->escapeXml(gmdate(DATE_RSS, $timestamp)) . "</pubDate>\n";
                }
            }
            if ($author !== '') {
                $xml .= '    <author>' . $this->escapeXml($author) . "</author>\n";
            }
            if ($categoryName !== '') {
                $xml .= '    <category>' . $this->escapeXml($categoryName) . "</category>\n";
            }
            if ($description !== '') {
                $xml .= '    <description>' . $this->wrapCdata($description) . "</description>\n";
            }
            if ($contentMode === 'full' && $content !== '') {
                $html = EditorJsRenderer::getInstance()->render($content);
                $html = PurifierService::getInstance()->purify($html, 'default');
                $html = preg_replace_callback(
                    '/\b(src|href)=(["\'])\/(?!\/)/i',
                    static fn (array $matches): string => $matches[1] . '=' . $matches[2] . rtrim((string) SITE_URL, '/') . '/',
                    $html
                ) ?? $html;
                $xml .= '    <content:encoded>' . $this->wrapCdata($html) . "</content:encoded>\n";
            }
            $featuredImage = trim((string) ($postData['featured_image'] ?? ''));
            if ($includeFeaturedImage && $featuredImage !== '') {
                $imageUrl = MediaDeliveryService::getInstance()->normalizeAdminVisibleUrl($featuredImage);
                if (preg_match('#^https?://#i', $imageUrl) === 1) {
                    $xml .= '    <media:content url="' . $this->escapeXml($imageUrl) . '" medium="image" />' . "\n";
                }
            }
            $xml .= "  </item>\n";
        }

        $xml .= "</channel>\n</rss>";
        if ((is_dir($cacheDir) || @mkdir($cacheDir, 0755, true)) && is_writable($cacheDir)) {
            $tmp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $xml, LOCK_EX) !== false && !@rename($tmp, $cacheFile)) {
                @unlink($tmp);
            }
        }

        $this->sendRssResponse($xml, time());
    }

    /**
     * @param bool $bypassCache true = Datei-Cache weder lesen noch schreiben (Aggregator-Abruf mit eigenem Intervall)
     */
    public function fetch(string $url, int $maxItems = 50, bool $bypassCache = false): array
    {
        $this->lastError = ['code' => '', 'detail' => ''];
        if (!$this->available) {
            $this->setLastError('parser_unavailable');
            return $this->errorResult('Feed-Parser nicht verfügbar');
        }

        $normalizedUrl = $this->normalizeFeedUrl($url);
        if ($normalizedUrl === '') {
            $this->setLastError('invalid_url');
            return $this->errorResult('Ungültige Feed-URL');
        }

        $normalizedMaxItems = $this->normalizeMaxItems($maxItems, 50);

        try {
            $feed = $this->loadFeedData($normalizedUrl, !$bypassCache);
            if ($feed === null) {
                if ($this->lastError['code'] === '') {
                    $this->setLastError('empty');
                }
                return $this->errorResult('Feed konnte nicht geladen werden.');
            }

            $items = $feed['items'] ?? [];
            if ($normalizedMaxItems > 0) {
                $items = array_slice(is_array($items) ? $items : [], 0, $normalizedMaxItems);
            }

            return [
                'success' => true,
                'title' => (string)($feed['title'] ?? ''),
                'description' => (string)($feed['description'] ?? ''),
                'link' => (string)($feed['link'] ?? ''),
                'image' => isset($feed['image']) ? (string)$feed['image'] : null,
                'language' => isset($feed['language']) ? (string)$feed['language'] : null,
                'items' => is_array($items) ? $items : [],
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Feed-Abruf ist fehlgeschlagen.', [
                'url' => $normalizedUrl,
                'error' => $this->sanitizeErrorMessage($e->getMessage()),
            ]);
            $this->setLastError('exception');

            return $this->errorResult('Feed konnte nicht geladen werden.');
        }
    }

    /**
     * Grund des letzten fehlgeschlagenen `fetch()` als Code (`invalid_url`, `connection`, `http_status`,
     * `redirect`, `too_large`, `empty`, `invalid_xml`, `unsupported_format`, `parser_unavailable`,
     * `exception`) mit bereinigtem Detail (z. B. HTTP-Status). Öffentliche Antworten bleiben generisch.
     *
     * @return array{code:string,detail:string}
     */
    public function getLastError(): array
    {
        return $this->lastError;
    }

    /**
     * Parst ein bereits geladenes RSS-/RDF-/Atom-Dokument mit denselben Regeln wie `fetch()`
     * (Bereinigung über PurifierService, normalisierte URLs). Ohne Netzwerkzugriff.
     *
     * @return array<string, mixed>|null Feed-Daten (`title`, `link`, `image`, `items` …) oder null bei ungültigem XML
     */
    public function parseFeedString(string $xml, string $sourceUrl = ''): ?array
    {
        $this->lastError = ['code' => '', 'detail' => ''];
        if (!$this->available || trim($xml) === '' || strlen($xml) > self::MAX_FEED_BYTES) {
            $this->setLastError(trim($xml) === '' ? 'empty' : 'too_large');
            return null;
        }

        return $this->parseFeedXml($xml, $sourceUrl);
    }

    /**
     * Normalisiert eine entfernte Feed-URL inkl. SSRF-Prüfung (nur http/https, keine Zugangsdaten,
     * keine lokalen/privaten Ziele nach DNS-Auflösung). Leerer String = nicht zulässig.
     */
    public function normalizeRemoteUrl(string $url): string
    {
        return $this->normalizeFeedUrl($url);
    }

    public function fetchMultiple(array $urls, int $maxItems = 100, bool $sortByDate = true): array
    {
        $allItems = [];
        $errors = [];

        $normalizedUrls = $this->normalizeFeedUrls($urls);
        $normalizedMaxItems = $this->normalizeMaxItems($maxItems, 100);

        if ($normalizedUrls === []) {
            return [
                'success' => false,
                'items' => [],
                'errors' => ['feeds' => 'Keine gültigen Feed-URLs vorhanden.'],
            ];
        }

        foreach ($normalizedUrls as $url) {
            $result = $this->fetch($url, 0);
            if ($result['success']) {
                foreach ($result['items'] as $item) {
                    $item['_feed_title'] = $result['title'];
                    $item['_feed_url'] = $url;
                    $allItems[] = $item;
                }
            } else {
                $errors[$url] = $result['error'] ?? 'Feed konnte nicht geladen werden.';
            }
        }

        if ($sortByDate && $allItems !== []) {
            usort($allItems, static function (array $a, array $b): int {
                return ((int)($b['date_timestamp'] ?? 0)) <=> ((int)($a['date_timestamp'] ?? 0));
            });
        }

        if ($normalizedMaxItems > 0 && count($allItems) > $normalizedMaxItems) {
            $allItems = array_slice($allItems, 0, $normalizedMaxItems);
        }

        return [
            'success' => count($errors) < count($normalizedUrls),
            'items' => $allItems,
            'errors' => $errors,
        ];
    }

    public function getFeedInfo(string $url): ?array
    {
        $normalizedUrl = $this->normalizeFeedUrl($url);
        if (!$this->available || $normalizedUrl === '') {
            return null;
        }

        try {
            $feed = $this->loadFeedData($normalizedUrl);
            if ($feed === null) {
                return null;
            }

            return [
                'title' => (string)($feed['title'] ?? ''),
                'description' => (string)($feed['description'] ?? ''),
                'link' => (string)($feed['link'] ?? ''),
                'image' => isset($feed['image']) ? (string)$feed['image'] : null,
                'language' => isset($feed['language']) ? (string)$feed['language'] : null,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Feed-Metadaten-Abruf ist fehlgeschlagen.', [
                'url' => $normalizedUrl,
                'error' => $this->sanitizeErrorMessage($e->getMessage()),
            ]);
            return null;
        }
    }

    public function validateFeedUrl(string $url): bool
    {
        $normalizedUrl = $this->normalizeFeedUrl($url);
        if (!$this->available || $normalizedUrl === '') {
            return false;
        }

        try {
            return $this->loadFeedData($normalizedUrl) !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('Feed-Validierung ist fehlgeschlagen.', [
                'url' => $normalizedUrl,
                'error' => $this->sanitizeErrorMessage($e->getMessage()),
            ]);
            return false;
        }
    }

    public function clearCache(): bool
    {
        if (!is_dir($this->cachePath)) {
            return true;
        }

        $success = true;
        $cacheRoot = realpath($this->cachePath);
        if ($cacheRoot === false) {
            return false;
        }

        try {
            $iterator = new \FilesystemIterator($cacheRoot, \FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || $fileInfo->isLink()) {
                    continue;
                }

                if (!$this->deleteFile($fileInfo->getPathname(), $cacheRoot)) {
                    $success = false;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Feed-Cache konnte nicht vollständig geleert werden.', [
                'path' => $this->cachePath,
                'error' => $this->sanitizeErrorMessage($e->getMessage()),
            ]);

            return false;
        }

        return $success;
    }

    public function setFetchTimeout(int $seconds): void
    {
        $this->fetchTimeout = max(1, min(60, $seconds));
    }

    public function setCacheDuration(int $seconds): void
    {
        $this->cacheDuration = max(0, $seconds);
    }

    private function normalizePublicTaxonomySlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        return preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) === 1 ? $slug : '';
    }

    private function sendRssResponse(string $xml, int $lastModified): never
    {
        $etag = '"' . hash('sha256', $xml) . '"';
        $lastModified = max(0, $lastModified);
        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        $notModified = false;
        if ($ifNoneMatch !== '') {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || hash_equals($etag, preg_replace('/^W\//', '', $candidate) ?? $candidate)) {
                    $notModified = true;
                    break;
                }
            }
        } elseif (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $since = strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']);
            $notModified = $since !== false && $lastModified <= $since;
        }

        if (!headers_sent()) {
            header('Cache-Control: public, max-age=' . self::PUBLIC_FEED_CACHE_TTL);
            header('ETag: ' . $etag);
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
            if ($notModified) {
                http_response_code(304);
            }
        }
        if (!$notModified) {
            echo $xml;
        }
        exit;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function wrapCdata(string $value): string
    {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
    }

    private function buildPublicFeedDescription(string $excerpt, string $content): string
    {
        $sources = array_values(array_filter([$excerpt, $content], static fn(string $value): bool => trim($value) !== ''));
        foreach ($sources as $source) {
            $plainText = $this->extractPublicFeedPlainText($source);
            if ($plainText !== '') {
                return mb_substr($plainText, 0, 320);
            }
        }

        return '';
    }

    private function extractPublicFeedPlainText(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        $rendered = EditorService::getInstance()->renderContent($source);
        $plainText = $this->normalizePublicFeedPlainText($rendered);
        if ($plainText !== '' && !$this->looksLikeEditorJsPayload($plainText)) {
            return $plainText;
        }

        $editorJsText = $this->extractEditorJsSnippet($source);
        if ($editorJsText !== '') {
            return $editorJsText;
        }

        if ($source !== $rendered) {
            $plainText = $this->normalizePublicFeedPlainText($source);
        }

        return $this->looksLikeEditorJsPayload($plainText) ? '' : $plainText;
    }

    private function normalizePublicFeedPlainText(string $value): string
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
        $decoded = \CMS\Json::decodeArray($source, []);
        if ($decoded !== [] && isset($decoded['blocks']) && is_array($decoded['blocks'])) {
            $collected = [];
            foreach ($decoded['blocks'] as $block) {
                if (!is_array($block)) {
                    continue;
                }

                $text = trim((string) ($block['data']['text'] ?? $block['data']['html'] ?? ''));
                if ($text !== '') {
                    $collected[] = $this->normalizePublicFeedPlainText($text);
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
                $text = $this->normalizePublicFeedPlainText($text);
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

    private function loadFeedData(string $url, bool $useCache = true): ?array
    {
        $cacheFile = $useCache ? $this->buildCacheFilePath($url) : null;
        if ($this->cacheEnabled && $this->cacheDuration > 0 && $cacheFile !== null && is_file($cacheFile)) {
            $cached = $this->readCacheFile($cacheFile);
            if ($cached !== null) {
                return $cached;
            }
        }

        $xml = $this->fetchRemoteFeed($url);
        if ($xml === null) {
            return null;
        }

        $parsed = $this->parseFeedXml($xml, $url);
        if ($parsed === null) {
            return null;
        }

        if ($this->cacheEnabled && $this->cacheDuration > 0 && $cacheFile !== null) {
            $this->writeCacheFile($cacheFile, $parsed);
        }

        return $parsed;
    }

    private function buildCacheFilePath(string $url): ?string
    {
        $cacheRoot = $this->getCacheRoot();
        if ($cacheRoot === null) {
            return null;
        }

        $fileName = hash('sha256', $url) . '.json';
        if (preg_match('/^[a-f0-9]{64}\.json$/', $fileName) !== 1) {
            return null;
        }

        return $cacheRoot . DIRECTORY_SEPARATOR . $fileName;
    }

    private function getCacheRoot(): ?string
    {
        if (!$this->cacheEnabled || !is_dir($this->cachePath)) {
            return null;
        }

        $cacheRoot = realpath($this->cachePath);
        if ($cacheRoot === false || is_link($cacheRoot)) {
            return null;
        }

        return rtrim($cacheRoot, '\\/');
    }

    private function resolveExistingCacheFilePath(string $cacheFile): ?string
    {
        $cacheRoot = $this->getCacheRoot();
        if ($cacheRoot === null) {
            return null;
        }

        $resolvedPath = realpath($cacheFile);
        if ($resolvedPath === false || is_link($resolvedPath)) {
            return null;
        }

        return str_starts_with($resolvedPath, $cacheRoot . DIRECTORY_SEPARATOR) ? $resolvedPath : null;
    }

    private function readCacheFile(string $cacheFile): ?array
    {
        $cacheFile = $this->resolveExistingCacheFilePath($cacheFile);
        if ($cacheFile === null || !is_file($cacheFile)) {
            return null;
        }

        if ((time() - (int)@filemtime($cacheFile)) > $this->cacheDuration) {
            return null;
        }

        $payload = $this->readBoundedCacheFile($cacheFile);
        if (!is_string($payload) || trim($payload) === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeCacheFile(string $cacheFile, array $payload): void
    {
        $cacheRoot = $this->getCacheRoot();
        if ($cacheRoot === null || !str_starts_with($cacheFile, $cacheRoot . DIRECTORY_SEPARATOR)) {
            return;
        }

        $this->writeBoundedCacheFile($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function readBoundedCacheFile(string $cacheFile): ?string
    {
        if (@filesize($cacheFile) > self::MAX_FEED_BYTES) {
            return null;
        }

        try {
            $file = new \SplFileObject($cacheFile, 'rb');
        } catch (\RuntimeException) {
            return null;
        }

        $content = '';
        while (!$file->eof()) {
            $content .= (string) $file->fgets();
            if (strlen($content) > self::MAX_FEED_BYTES) {
                return null;
            }
        }

        return $content;
    }

    private function writeBoundedCacheFile(string $cacheFile, string|false $payload): void
    {
        if (!is_string($payload) || $payload === '' || strlen($payload) > self::MAX_FEED_BYTES) {
            return;
        }

        try {
            $file = new \SplFileObject($cacheFile, 'wb');
            $file->fwrite($payload);
        } catch (\RuntimeException) {
            return;
        }
    }

    private function fetchRemoteFeed(string $url): ?string
    {
        $currentUrl = $this->normalizeFeedUrl($url);
        if ($currentUrl === '') {
            return null;
        }

        for ($redirectCount = 0; $redirectCount <= self::MAX_REMOTE_REDIRECTS; $redirectCount++) {
            $response = $this->fetchRemoteFeedResponse($currentUrl);
            if ($response === null) {
                return null;
            }

            $status = $response['status'];
            $location = $response['location'];
            $body = $response['body'];

            if ($status >= 300 && $status < 400) {
                if ($location === null || $location === '' || $redirectCount >= self::MAX_REMOTE_REDIRECTS) {
                    $this->setLastError('redirect', (string) $status);
                    $this->logger->warning('Feed-Redirect wurde verworfen.', [
                        'url' => $currentUrl,
                        'status' => $status,
                        'has_location' => $location !== null && $location !== '',
                    ]);
                    return null;
                }

                $redirectUrl = $this->resolveRedirectUrl($currentUrl, $location);
                if ($redirectUrl === '') {
                    $this->setLastError('redirect', (string) $status);
                    $this->logger->warning('Feed-Redirect zielte auf eine unzulässige URL.', [
                        'url' => $currentUrl,
                        'location' => $this->sanitizeErrorMessage($location),
                    ]);
                    return null;
                }

                $currentUrl = $redirectUrl;
                continue;
            }

            if ($status < 200 || $status >= 400) {
                $this->setLastError('http_status', (string) $status);
                return null;
            }
            if ($body === '') {
                $this->setLastError('empty', (string) $status);
                return null;
            }
            if (strlen($body) > self::MAX_FEED_BYTES) {
                $this->setLastError('too_large');
                return null;
            }

            return $body;
        }

        return null;
    }

    /**
     * @return array{status:int,body:string,location:?string}|null
     */
    private function fetchRemoteFeedResponse(string $url): ?array
    {
        $response = HttpClient::getInstance()->get($url, [
            'userAgent' => $this->userAgent,
            'timeout' => $this->fetchTimeout,
            'connectTimeout' => min(10, $this->fetchTimeout),
            'maxBytes' => self::MAX_FEED_BYTES,
            'allowedContentTypes' => ['application/rss+xml', 'application/atom+xml', 'application/xml', 'text/xml', 'text/plain'],
        ]);

        $status = (int) ($response['status'] ?? 0);
        if ($status === 0) {
            $this->setLastError('connection', (string) ($response['error'] ?? ''));
            if (!empty($response['error'])) {
                $this->logger->warning('Feed-Abruf ist fehlgeschlagen.', [
                    'url' => $url,
                    'error' => $this->sanitizeErrorMessage((string) $response['error']),
                ]);
            }

            return null;
        }

        return [
            'status' => $status,
            'body' => (string) ($response['body'] ?? ''),
            'location' => trim((string) (($response['headers']['location'] ?? '') ?: '')) ?: null,
        ];
    }

    private function parseFeedXml(string $xml, string $url): ?array
    {
        $dom = new \DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (!$loaded || !$dom->documentElement instanceof \DOMElement) {
            $this->setLastError('invalid_xml');
            $this->logger->warning('Feed-XML konnte nicht geparst werden.', ['url' => $url]);
            return null;
        }

        $xpath = new \DOMXPath($dom);
        $root = $dom->documentElement;
        $rootName = strtolower($root->localName ?: $root->nodeName);

        if ($rootName === 'feed') {
            return $this->parseAtomFeed($xpath, $root);
        }

        if ($rootName === 'rss' || $rootName === 'rdf') {
            return $this->parseRssFeed($xpath, $root);
        }

        $this->setLastError('unsupported_format', $rootName);
        $this->logger->warning('Feed-Typ wird nicht unterstützt.', [
            'url' => $url,
            'root' => $rootName,
        ]);

        return null;
    }

    private function parseRssFeed(\DOMXPath $xpath, \DOMElement $root): array
    {
        $channel = $this->queryFirstNode($xpath, $root, ['./*[local-name()="channel"][1]']);
        $context = $channel ?? $root;
        $items = [];

        foreach ($xpath->query('./*[local-name()="item"]', $context) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $items[] = $this->parseRssItem($xpath, $node);
            }
        }

        return [
            'title' => $this->sanitizePlainText($this->queryFirstScalar($xpath, $context, ['./*[local-name()="title"][1]']), self::MAX_TITLE_LENGTH),
            'description' => $this->sanitizeHtml($this->queryFirstScalar($xpath, $context, ['./*[local-name()="description"][1]', './*[local-name()="subtitle"][1]']), self::MAX_DESCRIPTION_LENGTH),
            'link' => $this->normalizePublicUrl((string)$this->queryFirstScalar($xpath, $context, ['./*[local-name()="link"][1]'])) ?? '',
            'image' => $this->normalizePublicUrl((string)$this->queryFirstScalar($xpath, $context, ['./*[local-name()="image"]/*[local-name()="url"][1]'])),
            'language' => $this->normalizeLanguage((string)$this->queryFirstScalar($xpath, $context, ['./*[local-name()="language"][1]', './*[local-name()="lang"][1]'])),
            'items' => $items,
        ];
    }

    private function parseAtomFeed(\DOMXPath $xpath, \DOMElement $root): array
    {
        $items = [];

        foreach ($xpath->query('./*[local-name()="entry"]', $root) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $items[] = $this->parseAtomItem($xpath, $node);
            }
        }

        return [
            'title' => $this->sanitizePlainText($this->queryFirstScalar($xpath, $root, ['./*[local-name()="title"][1]']), self::MAX_TITLE_LENGTH),
            'description' => $this->sanitizeHtml($this->queryFirstScalar($xpath, $root, ['./*[local-name()="subtitle"][1]']), self::MAX_DESCRIPTION_LENGTH),
            'link' => $this->normalizePublicUrl((string)$this->queryFirstScalar($xpath, $root, ['./*[local-name()="link"][@rel="alternate"]/@href', './*[local-name()="link"][1]/@href'])) ?? '',
            'image' => $this->normalizePublicUrl((string)$this->queryFirstScalar($xpath, $root, ['./*[local-name()="logo"][1]', './*[local-name()="icon"][1]'])),
            'language' => $this->normalizeLanguage((string)($root->getAttribute('xml:lang') ?: $root->getAttribute('lang'))),
            'items' => $items,
        ];
    }

    private function parseRssItem(\DOMXPath $xpath, \DOMElement $item): array
    {
        $categories = $this->extractCategories($xpath, $item, './*[local-name()="category"]');
        $content = $this->queryFirstScalar($xpath, $item, ['./*[local-name()="encoded"][1]', './*[local-name()="content"][1]', './*[local-name()="description"][1]']);
        $date = $this->normalizeDate($this->queryFirstScalar($xpath, $item, ['./*[local-name()="pubDate"][1]', './*[local-name()="date"][1]', './*[local-name()="published"][1]']));
        $link = $this->normalizePublicUrl((string)$this->queryFirstScalar($xpath, $item, ['./*[local-name()="link"][1]']));

        return [
            'title' => $this->sanitizePlainText($this->queryFirstScalar($xpath, $item, ['./*[local-name()="title"][1]']), self::MAX_TITLE_LENGTH),
            'link' => $link ?? '',
            'description' => $this->sanitizeHtml($this->queryFirstScalar($xpath, $item, ['./*[local-name()="description"][1]']), self::MAX_DESCRIPTION_LENGTH),
            'content' => $this->sanitizeHtml($content, self::MAX_CONTENT_LENGTH),
            'author' => $this->normalizeAuthor($this->queryFirstScalar($xpath, $item, ['./*[local-name()="author"][1]', './*[local-name()="creator"][1]'])),
            'date' => $date['date'],
            'date_timestamp' => $date['timestamp'],
            'categories' => $categories,
            'thumbnail' => $this->normalizePublicUrl((string)$this->queryFirstScalar($xpath, $item, ['./*[local-name()="thumbnail"]/@url', './*[local-name()="content"][@medium="image"]/@url', './*[local-name()="content"][starts-with(@type,"image/")]/@url', './*[local-name()="group"]/*[local-name()="content"][@medium="image" or starts-with(@type,"image/")]/@url', './*[local-name()="group"]/*[local-name()="thumbnail"]/@url', './*[local-name()="enclosure"][starts-with(@type,"image/")]/@url'])),
            'guid' => $this->sanitizeGuid((string)($this->queryFirstScalar($xpath, $item, ['./*[local-name()="guid"][1]']) ?: ($link ?? ''))),
        ];
    }

    private function parseAtomItem(\DOMXPath $xpath, \DOMElement $item): array
    {
        $categories = $this->extractAtomCategories($xpath, $item);
        $content = $this->queryFirstScalar($xpath, $item, ['./*[local-name()="content"][1]', './*[local-name()="summary"][1]']);
        $date = $this->normalizeDate($this->queryFirstScalar($xpath, $item, ['./*[local-name()="updated"][1]', './*[local-name()="published"][1]']));
        $link = $this->normalizePublicUrl((string)$this->queryFirstScalar($xpath, $item, ['./*[local-name()="link"][@rel="alternate"]/@href', './*[local-name()="link"][1]/@href']));

        return [
            'title' => $this->sanitizePlainText($this->queryFirstScalar($xpath, $item, ['./*[local-name()="title"][1]']), self::MAX_TITLE_LENGTH),
            'link' => $link ?? '',
            'description' => $this->sanitizeHtml($this->queryFirstScalar($xpath, $item, ['./*[local-name()="summary"][1]', './*[local-name()="content"][1]']), self::MAX_DESCRIPTION_LENGTH),
            'content' => $this->sanitizeHtml($content, self::MAX_CONTENT_LENGTH),
            'author' => $this->normalizeAuthor($this->queryFirstScalar($xpath, $item, ['./*[local-name()="author"]/*[local-name()="name"][1]', './*[local-name()="author"]/*[local-name()="email"][1]'])),
            'date' => $date['date'],
            'date_timestamp' => $date['timestamp'],
            'categories' => $categories,
            'thumbnail' => $this->normalizePublicUrl((string)$this->queryFirstScalar($xpath, $item, ['./*[local-name()="thumbnail"]/@url', './*[local-name()="link"][@rel="enclosure" and starts-with(@type,"image/")]/@href'])),
            'guid' => $this->sanitizeGuid((string)($this->queryFirstScalar($xpath, $item, ['./*[local-name()="id"][1]']) ?: ($link ?? ''))),
        ];
    }

    private function queryFirstNode(\DOMXPath $xpath, \DOMNode $context, array $queries): ?\DOMNode
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query, $context);
            if ($nodes instanceof \DOMNodeList && $nodes->length > 0) {
                return $nodes->item(0);
            }
        }

        return null;
    }

    private function queryFirstScalar(\DOMXPath $xpath, \DOMNode $context, array $queries): string
    {
        foreach ($queries as $query) {
            $value = trim((string)$xpath->evaluate('string((' . $query . ')[1])', $context));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractCategories(\DOMXPath $xpath, \DOMNode $context, string $query): array
    {
        $categories = [];
        foreach ($xpath->query($query, $context) ?: [] as $node) {
            $label = trim((string)$node->textContent);
            if ($label === '') {
                continue;
            }

            $normalizedLabel = $this->sanitizePlainText($label, self::MAX_CATEGORY_LENGTH);
            if ($normalizedLabel !== '') {
                $categories[] = $normalizedLabel;
            }
        }

        return array_values(array_slice(array_unique($categories), 0, self::MAX_CATEGORY_COUNT));
    }

    private function extractAtomCategories(\DOMXPath $xpath, \DOMNode $context): array
    {
        $categories = [];
        foreach ($xpath->query('./*[local-name()="category"]', $context) ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $label = trim((string)($node->getAttribute('term') !== '' ? $node->getAttribute('term') : $node->textContent));
            if ($label === '') {
                continue;
            }

            $normalizedLabel = $this->sanitizePlainText($label, self::MAX_CATEGORY_LENGTH);
            if ($normalizedLabel !== '') {
                $categories[] = $normalizedLabel;
            }
        }

        return array_values(array_slice(array_unique($categories), 0, self::MAX_CATEGORY_COUNT));
    }

    private function normalizeDate(string $rawDate): array
    {
        $rawDate = trim($rawDate);
        if ($rawDate === '') {
            return ['date' => null, 'timestamp' => null];
        }

        $timestamp = strtotime($rawDate);
        if ($timestamp === false) {
            return ['date' => null, 'timestamp' => null];
        }

        return ['date' => date('Y-m-d H:i:s', $timestamp), 'timestamp' => $timestamp];
    }

    private function normalizeAuthor(string $author): ?string
    {
        $author = $this->sanitizePlainText($author, 120);
        return $author !== '' ? $author : null;
    }

    private function setLastError(string $code, string $detail = ''): void
    {
        $this->lastError = ['code' => $code, 'detail' => $this->sanitizeErrorMessage($detail)];
    }

    private function errorResult(string $message): array
    {
        return [
            'success' => false,
            'title' => '',
            'description' => '',
            'link' => '',
            'image' => null,
            'language' => null,
            'items' => [],
            'error' => $message,
        ];
    }

    private function ensureDirectory(string $path, string $label): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            $this->logger->warning(sprintf('FeedService: %s konnte nicht erstellt werden.', $label), ['path' => $path]);
            return false;
        }

        if (!is_writable($path)) {
            $this->logger->warning(sprintf('FeedService: %s ist nicht beschreibbar.', $label), ['path' => $path]);
            return false;
        }

        return true;
    }

    private function deleteFile(string $path, string $cacheRoot = ''): bool
    {
        if (!is_file($path)) {
            return true;
        }

        if ($cacheRoot !== '') {
            $realPath = realpath($path);
            if ($realPath === false || !str_starts_with($realPath, rtrim($cacheRoot, '\\/') . DIRECTORY_SEPARATOR)) {
                $this->logger->warning('FeedService: Cache-Datei außerhalb des Cache-Roots verworfen.', ['path' => $path]);
                return false;
            }
        }

        if (!unlink($path)) {
            $this->logger->warning('FeedService: Cache-Datei konnte nicht gelöscht werden.', ['path' => $path]);
            return false;
        }

        return true;
    }

    private function normalizeFeedUrls(array $urls): array
    {
        $normalized = [];
        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }

            $normalizedUrl = $this->normalizeFeedUrl($url);
            if ($normalizedUrl === '') {
                continue;
            }

            $normalized[] = $normalizedUrl;
            if (count($normalized) >= self::MAX_FEEDS_PER_BATCH) {
                break;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeFeedUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = trim((string)($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        if (!in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || ($port !== null && (!is_int($port) || $port < 1 || $port > 65535))
        ) {
            return '';
        }

        $asciiHost = $this->normalizeHost($host);
        if ($asciiHost === '' || !$this->isSafeRemoteHost($asciiHost)) {
            $this->logger->warning('Feed-URL verworfen.', ['url' => $url, 'host' => $host]);
            return '';
        }

        unset($parts['fragment']);
        return $this->buildUrl($parts, $asciiHost);
    }

    private function normalizeMaxItems(int $maxItems, int $default): int
    {
        if ($maxItems === 0) {
            return 0;
        }

        return max(1, min(self::MAX_ITEMS_PER_FEED, $maxItems > 0 ? $maxItems : $default));
    }

    private function sanitizePlainText(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return $this->truncate($value, $maxLength);
    }

    private function sanitizeHtml(string $value, int $maxLength): string
    {
        $value = $this->truncate($value, $maxLength);
        return PurifierService::getInstance()->purify($value, 'default');
    }

    private function normalizePublicUrl(string $url): ?string
    {
        $normalized = trim($url);
        if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($normalized);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $this->buildUrl($parts, $this->normalizeHost((string)($parts['host'] ?? '')));
    }

    private function normalizeLanguage(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-_]/', '', $value) ?? '';
        return $value !== '' ? substr($value, 0, 16) : null;
    }

    private function sanitizeGuid(string $value): string
    {
        $value = trim($value);
        return $value !== '' ? $this->truncate($value, self::MAX_GUID_LENGTH) : '';
    }

    private function sanitizeErrorMessage(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return $this->truncate($value, self::MAX_ERROR_LENGTH);
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim(rtrim($host, '.')));
        if ($host === '') {
            return '';
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                return $ascii;
            }
        }

        return $host;
    }

    private function isSafeRemoteHost(string $host): bool
    {
        if (in_array($host, ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'], true)) {
            return false;
        }

        $resolvedIps = $this->resolveHostIps($host);
        if ($resolvedIps === []) {
            return false;
        }

        foreach ($resolvedIps as $ip) {
            if ($ip !== '' && $this->isPrivateOrReservedIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        return HttpClient::isNonPublicIp($ip);
    }

    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $ip = trim((string)($record['ip'] ?? $record['ipv6'] ?? ''));
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        if ($ips === [] && function_exists('gethostbynamel')) {
            $fallbackRecords = @gethostbynamel($host);
            if (is_array($fallbackRecords)) {
                foreach ($fallbackRecords as $ip) {
                    $ip = trim((string)$ip);
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function buildUrl(array $parts, string $host): string
    {
        if ($host === '') {
            return '';
        }

        $url = strtolower((string)($parts['scheme'] ?? 'https')) . '://' . $host;
        if (isset($parts['port']) && is_int($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        $path = (string)($parts['path'] ?? '');
        $url .= $path !== '' ? $path : '/';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . (string)$parts['query'];
        }

        return $url;
    }




    /**
     * @param array<int, string> $headerLines
     * @return array{status:int,location:?string}
     */
    private function extractRedirectMetaFromHeaderLines(array $headerLines): array
    {
        $blocks = [];
        $currentBlock = [];

        foreach ($headerLines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^HTTP\//i', $line) === 1 && $currentBlock !== []) {
                $blocks[] = $currentBlock;
                $currentBlock = [];
            }

            $currentBlock[] = $line;
        }

        if ($currentBlock !== []) {
            $blocks[] = $currentBlock;
        }

        $lastBlock = $blocks !== [] ? $blocks[array_key_last($blocks)] : [];
        if ($lastBlock === []) {
            return ['status' => 0, 'location' => null];
        }

        $status = 0;
        $location = null;

        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $lastBlock[0], $matches) === 1) {
            $status = (int) $matches[1];
        }

        foreach ($lastBlock as $line) {
            if (stripos($line, 'Location:') === 0) {
                $candidate = trim(substr($line, 9));
                if ($candidate !== '') {
                    $location = $candidate;
                }
            }
        }

        return [
            'status' => $status,
            'location' => $location,
        ];
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return '';
        }

        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $this->normalizeFeedUrl($location);
        }

        $base = parse_url($currentUrl);
        if (!is_array($base)) {
            return '';
        }

        $scheme = strtolower((string) ($base['scheme'] ?? 'https'));
        $host = $this->normalizeHost((string) ($base['host'] ?? ''));
        if ($host === '') {
            return '';
        }

        $port = isset($base['port']) && is_int($base['port']) ? ':' . $base['port'] : '';

        if (str_starts_with($location, '//')) {
            return $this->normalizeFeedUrl($scheme . ':' . $location);
        }

        if (str_starts_with($location, '/')) {
            return $this->normalizeFeedUrl($scheme . '://' . $host . $port . $location);
        }

        $basePath = (string) ($base['path'] ?? '/');
        $baseDirectory = preg_replace('~/[^/]*$~', '/', $basePath) ?? '/';
        if ($baseDirectory === '') {
            $baseDirectory = '/';
        }

        return $this->normalizeFeedUrl($scheme . '://' . $host . $port . $this->normalizeRelativePath($baseDirectory . $location));
    }

    private function normalizeRelativePath(string $path): string
    {
        $query = '';
        $queryPos = strpos($path, '?');
        if ($queryPos !== false) {
            $query = substr($path, $queryPos);
            $path = substr($path, 0, $queryPos);
        }

        $segments = explode('/', str_replace('\\', '/', $path));
        $normalizedSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalizedSegments);
                continue;
            }

            $normalizedSegments[] = $segment;
        }

        return '/' . implode('/', $normalizedSegments) . $query;
    }

    private function truncate(string $value, int $maxLength): string
    {
        if ($maxLength <= 0 || $value === '') {
            return $value;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }
}
