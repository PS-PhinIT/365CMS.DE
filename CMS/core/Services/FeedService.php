<?php
/**
 * Feed Service – natives RSS/Atom-Parsing ohne externe Parser-Bibliothek.
 *
 * Zentraler Service für das Laden, Cachen und Parsen von RSS-/Atom-Feeds.
 * Nutzt DOM/XML, einen abgesicherten Remote-Fetch und einen JSON-Datei-Cache.
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

    public function fetch(string $url, int $maxItems = 50): array
    {
        if (!$this->available) {
            return $this->errorResult('Feed-Parser nicht verfügbar');
        }

        $normalizedUrl = $this->normalizeFeedUrl($url);
        if ($normalizedUrl === '') {
            return $this->errorResult('Ungültige Feed-URL');
        }

        $normalizedMaxItems = $this->normalizeMaxItems($maxItems, 50);

        try {
            $feed = $this->loadFeedData($normalizedUrl);
            if ($feed === null) {
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

            return $this->errorResult('Feed konnte nicht geladen werden.');
        }
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

    private function loadFeedData(string $url): ?array
    {
        $cacheFile = $this->buildCacheFilePath($url);
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
                    $this->logger->warning('Feed-Redirect wurde verworfen.', [
                        'url' => $currentUrl,
                        'status' => $status,
                        'has_location' => $location !== null && $location !== '',
                    ]);
                    return null;
                }

                $redirectUrl = $this->resolveRedirectUrl($currentUrl, $location);
                if ($redirectUrl === '') {
                    $this->logger->warning('Feed-Redirect zielte auf eine unzulässige URL.', [
                        'url' => $currentUrl,
                        'location' => $this->sanitizeErrorMessage($location),
                    ]);
                    return null;
                }

                $currentUrl = $redirectUrl;
                continue;
            }

            if ($status < 200 || $status >= 400 || $body === '' || strlen($body) > self::MAX_FEED_BYTES) {
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
            'thumbnail' => $this->normalizePublicUrl((string)$this->queryFirstScalar($xpath, $item, ['./*[local-name()="thumbnail"]/@url', './*[local-name()="content"][@medium="image"]/@url', './*[local-name()="enclosure"][starts-with(@type,"image/")]/@url'])),
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
