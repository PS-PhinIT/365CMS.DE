<?php
declare(strict_types=1);

namespace CMS\Services\SEO;

use CMS\Services\SeoAnalysisService;

if (!defined('ABSPATH')) {
    exit;
}

final class SeoHeadRenderer
{
    /** @var array<string,mixed> */
    private array $requestMeta = [];

    public function __construct(
        private readonly SeoMetaRepository $repository,
        private readonly SeoSettingsStore $settings,
        private readonly SeoSchemaRenderer $schemaRenderer
    ) {
    }

    /**
     * Aufgelöste SEO-Daten der aktuellen Anfrage (inkl. Redaktions-Overrides aus dem SEO-Panel),
     * damit Themes mit eigener Meta-Ausgabe dieselben Werte verwenden können.
     *
     * @return array<string, mixed>
     */
    public function getCurrentPayload(): array
    {
        return $this->getCurrentSeoPayload();
    }

    public function renderCurrentHeadTags(): string
    {
        $payload = $this->getCurrentSeoPayload();
        if ($payload === []) {
            return '';
        }

        $lines = [];
        $metaDescription = trim((string) ($payload['description'] ?? ''));
        if ($metaDescription !== '') {
            $lines[] = '<meta name="description" content="' . htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') . '">';
        }

        $metaKeywords = trim((string) ($payload['keywords'] ?? ''));
        if ($metaKeywords !== '') {
            $lines[] = '<meta name="keywords" content="' . htmlspecialchars($metaKeywords, ENT_QUOTES, 'UTF-8') . '">';
        }

        $robots = [];
        $robots[] = !empty($payload['robots_index']) ? 'index' : 'noindex';
        $robots[] = !empty($payload['robots_follow']) ? 'follow' : 'nofollow';
        $lines[] = '<meta name="robots" content="' . htmlspecialchars(implode(',', $robots), ENT_QUOTES, 'UTF-8') . '">';

        if (!empty($payload['canonical_url'])) {
            $lines[] = '<link rel="canonical" href="' . htmlspecialchars((string) $payload['canonical_url'], ENT_QUOTES, 'UTF-8') . '">';
        }

        $ogMap = [
            'og:title' => $payload['og_title'] ?? '',
            'og:description' => $payload['og_description'] ?? '',
            'og:image' => $payload['og_image'] ?? '',
            'og:type' => $payload['og_type'] ?? 'website',
            'og:url' => $payload['canonical_url'] ?? '',
            'og:site_name' => $payload['og_site_name'] ?? SITE_NAME,
        ];

        foreach ($ogMap as $property => $value) {
            if ((string) $value === '') {
                continue;
            }
            $lines[] = '<meta property="' . htmlspecialchars($property, ENT_QUOTES, 'UTF-8') . '" content="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
        }

        $twitterHandle = $this->settings->getTwitterHandle();
        $twitterMap = [
            'twitter:card' => $payload['twitter_card'] ?? 'summary_large_image',
            'twitter:title' => $payload['twitter_title'] ?? '',
            'twitter:description' => $payload['twitter_description'] ?? '',
            'twitter:image' => $payload['twitter_image'] ?? '',
            'twitter:site' => $twitterHandle !== '' ? '@' . $twitterHandle : '',
        ];

        foreach ($twitterMap as $name => $value) {
            if ((string) $value === '') {
                continue;
            }
            $lines[] = '<meta name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" content="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
        }

        foreach ($this->buildHreflangAlternates($payload) as $hreflang => $alternateUrl) {
            $lines[] = '<link rel="alternate" hreflang="' . htmlspecialchars($hreflang, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($alternateUrl, ENT_QUOTES, 'UTF-8') . '">';
        }

        $schema = !empty($payload['suppress_schema']) ? '' : $this->schemaRenderer->renderSchemaForPayload($payload);
        if ($schema !== '') {
            $lines[] = $schema;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Sets non-persistent metadata for the current request only.
     *
     * @param array<string,mixed> $payload
     */
    public function setRequestMeta(array $payload): void
    {
        $this->requestMeta = $this->normalizeRequestMeta($payload);
    }

    /** @return array<string,mixed> */
    public function getRequestMeta(): array
    {
        return $this->requestMeta;
    }

    private function getCurrentSeoPayload(): array
    {
        $analysis = SeoAnalysisService::getInstance();
        $socialDefaults = $this->settings->getSocialDefaults();
        $uri = isset($_SERVER['REQUEST_URI']) ? strtok((string) $_SERVER['REQUEST_URI'], '?') : '/';
        $uri = $uri !== false ? $uri : '/';
        // Bei Installation in einem Unterverzeichnis enthält SITE_URL den Pfad bereits.
        $sitePath = rtrim((string) parse_url((string) SITE_URL, PHP_URL_PATH), '/');
        if ($sitePath !== '' && ($uri === $sitePath || str_starts_with($uri, $sitePath . '/'))) {
            $uri = substr($uri, strlen($sitePath)) ?: '/';
        }
        $canonicalUrl = SITE_URL . ($uri === '/' ? '/' : $uri) . $this->buildPaginationQuery();

        if ($this->requestMeta !== []) {
            return $this->applyRequestRobotsPolicy($this->buildRequestMetaPayload($socialDefaults, $canonicalUrl));
        }

        $pageData = $GLOBALS['page'] ?? null;
        $postData = $GLOBALS['post'] ?? null;
        $content = null;
        $contentType = 'page';

        if (is_object($postData) || is_array($postData)) {
            $content = $postData;
            $contentType = 'post';
        } elseif (is_object($pageData) || is_array($pageData)) {
            $content = $pageData;
            $contentType = 'page';
        }

        if ($content === null) {
            $description = $this->settings->getMetaDescription('');
            $genericTitle = (string) SITE_NAME;
            if ($uri === '/' || $uri === '/en' || $uri === '/en/') {
                // Unter SEO → Meta gepflegte Startseiten-Angaben.
                $genericTitle = trim($this->settings->getHomepageTitle('')) ?: $genericTitle;
                $description = trim($this->settings->getHomepageDescription('')) ?: $description;
            }
            return $this->applyRequestRobotsPolicy([
                'description' => $description,
                'canonical_url' => $canonicalUrl,
                'robots_index' => true,
                'robots_follow' => true,
                'og_title' => $genericTitle,
                'og_description' => $description,
                'og_image' => $this->absolutizeUrl($socialDefaults['image']),
                'og_type' => $socialDefaults['og_type'],
                'og_site_name' => $socialDefaults['brand_name'],
                'twitter_card' => $socialDefaults['twitter_card'],
                'twitter_title' => $genericTitle,
                'twitter_description' => $description,
                'twitter_image' => $this->absolutizeUrl($socialDefaults['image']),
                'schema_type' => 'WebPage',
                'title' => $genericTitle,
                'content_type' => $genericTitle !== (string) SITE_NAME ? 'home' : '',
                'url' => $canonicalUrl,
                'updated_at' => date(DATE_W3C),
            ]);
        }

        $id = (int) ($this->readField($content, 'id') ?? 0);
        // Hub-Sites tragen die ID ihrer Site-Table; ein SEO-Datensatz `page` mit dieser ID gehört zu einer anderen Seite.
        if ($contentType === 'page' && $this->isHubPayload($content)) {
            $id = 0;
        }
        $resolvedContext = [
            'title' => (string) ($this->readField($content, 'title') ?? SITE_NAME),
            'slug' => (string) ($this->readField($content, 'slug') ?? ''),
            'content' => (string) ($this->readField($content, 'content') ?? ''),
            'excerpt' => (string) ($this->readField($content, 'excerpt') ?? ''),
            'meta_title' => (string) ($this->readField($content, 'meta_title') ?? ''),
            'meta_description' => (string) ($this->readField($content, 'meta_description') ?? ''),
        ];

        $title = trim($analysis->resolveMetaTitle($resolvedContext));
        $description = trim($analysis->resolveMetaDescription($resolvedContext));
        $featuredImage = trim((string) ($this->readField($content, 'featured_image') ?? ''));
        $meta = $this->repository->getContentMeta($contentType, $id);
        foreach (['og_title', 'og_description', 'twitter_title', 'twitter_description'] as $templateField) {
            $meta[$templateField] = $analysis->resolveTemplateVariables((string) ($meta[$templateField] ?? ''), $resolvedContext);
        }
        $updatedAt = (string) ($this->readField($content, 'updated_at') ?? $this->readField($content, 'created_at') ?? date(DATE_W3C));
        $resolvedOgImage = $meta['og_image'] !== '' ? $meta['og_image'] : ($featuredImage !== '' ? $featuredImage : $socialDefaults['image']);
        $resolvedOgImage = $this->absolutizeUrl($resolvedOgImage);
        $isPublic = $this->isPubliclyVisibleContent($content);

        return $this->applyRequestRobotsPolicy([
            'title' => $title,
            'description' => $description,
            'keywords' => (string) ($meta['keywords'] ?? ''),
            'canonical_url' => $meta['canonical_url'] !== '' ? $this->absolutizeUrl($meta['canonical_url']) : $canonicalUrl,
            // Entwürfe, geplante und private Inhalte (Admin-Vorschau) nie indexieren.
            'robots_index' => $meta['robots_index'] && $isPublic,
            'robots_follow' => $meta['robots_follow'] && $isPublic,
            'og_title' => $meta['og_title'] !== '' ? $meta['og_title'] : $title,
            'og_description' => $meta['og_description'] !== '' ? $meta['og_description'] : $description,
            'og_image' => $resolvedOgImage,
            'og_type' => $meta['og_type'] !== '' ? $meta['og_type'] : $socialDefaults['og_type'],
            'og_site_name' => $socialDefaults['brand_name'],
            'twitter_card' => $meta['twitter_card'] !== '' ? $meta['twitter_card'] : $socialDefaults['twitter_card'],
            'twitter_title' => $meta['twitter_title'] !== '' ? $meta['twitter_title'] : $title,
            'twitter_description' => $meta['twitter_description'] !== '' ? $meta['twitter_description'] : $description,
            'twitter_image' => $meta['twitter_image'] !== '' ? $this->absolutizeUrl($meta['twitter_image']) : $resolvedOgImage,
            'schema_type' => SeoSchemaRenderer::effectiveTypeFor((string) $meta['schema_type'], $contentType),
            'url' => $canonicalUrl,
            'content_type' => $contentType,
            'content_title' => trim($resolvedContext['title']),
            'content_source' => $content,
            'updated_at' => (string) ($this->readField($content, 'content_updated_at') ?: $updatedAt),
            'published_at' => (string) ($this->readField($content, 'published_at') ?? $this->readField($content, 'created_at') ?? ''),
            'author_name' => trim((string) ($this->readField($content, 'author_name') ?? '')),
            'locale' => (string) ($this->readField($content, 'locale') ?? $this->readField($content, 'content_locale') ?? ''),
        ]);
    }

    /**
     * Fehlerseiten, Suchergebnisse und nicht öffentliche Bereiche dürfen nicht in den Index.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyRequestRobotsPolicy(array $payload): array
    {
        $status = http_response_code();
        if (is_int($status) && $status >= 400) {
            $payload['robots_index'] = false;
            // Fehlerseiten bekommen weder Canonical noch strukturierte Daten.
            $payload['canonical_url'] = '';
            $payload['suppress_schema'] = true;

            return $payload;
        }

        $path = (string) (strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/');
        $sitePath = rtrim((string) parse_url((string) SITE_URL, PHP_URL_PATH), '/');
        if ($sitePath !== '' && str_starts_with($path, $sitePath)) {
            $path = substr($path, strlen($sitePath)) ?: '/';
        }
        $path = '/' . trim(preg_replace('#^/(?:en|de)(?=/|$)#', '', $path) ?? $path, '/');

        $noindexPaths = ['/search', '/suche', '/login', '/register', '/cms-login', '/cms-register', '/cms-password-forgot', '/forgot-password', '/order', '/orders.php'];
        if (in_array($path, $noindexPaths, true) || str_starts_with($path, '/member') || str_starts_with($path, '/mfa-')) {
            $payload['robots_index'] = false;
        }

        return $payload;
    }

    /**
     * Folgeseiten paginierter Listen kanonisieren auf sich selbst, nicht auf Seite 1.
     */
    private function buildPaginationQuery(): string
    {
        foreach (['p', 'page'] as $param) {
            $value = $_GET[$param] ?? null;
            if (is_string($value) && ctype_digit($value) && (int) $value > 1) {
                return '?' . $param . '=' . (int) $value;
            }
        }

        return '';
    }

    private function absolutizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return (str_starts_with((string) SITE_URL, 'http://') ? 'http:' : 'https:') . $url;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return '';
        }

        $siteUrl = rtrim((string) SITE_URL, '/');
        $sitePath = rtrim((string) parse_url($siteUrl, PHP_URL_PATH), '/');
        $origin = $sitePath !== '' ? substr($siteUrl, 0, -strlen($sitePath)) : $siteUrl;
        if ($sitePath !== '' && str_starts_with($url, $sitePath . '/')) {
            return $origin . $url;
        }

        return $siteUrl . '/' . ltrim($url, '/');
    }

    /**
     * hreflang-Paare für Beiträge/Seiten mit gepflegter englischer Fassung (DE ist x-default).
     *
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    private function buildHreflangAlternates(array $payload): array
    {
        $content = $payload['content_source'] ?? null;
        if ((!is_array($content) && !is_object($content)) || empty($payload['robots_index'])
            || $this->settings->getSetting('technical_hreflang_enabled', '1') === '0'
        ) {
            return [];
        }

        $hasEnglish = false;
        foreach (['title_en', 'content_en'] as $field) {
            if (trim((string) ($this->readField($content, $field) ?? '')) !== '') {
                $hasEnglish = true;
                break;
            }
        }
        $baseSlug = trim((string) ($this->readField($content, 'slug_base') ?? $this->readField($content, 'slug') ?? ''), '/');
        if (!$hasEnglish || $baseSlug === '' || $this->isHubPayload($content)) {
            return [];
        }

        try {
            if (($payload['content_type'] ?? '') === 'post') {
                $permalinks = \CMS\Services\PermalinkService::getInstance();
                $base = array_merge(is_object($content) ? get_object_vars($content) : $content, ['slug' => $baseSlug]);
                $alternates = [
                    'de' => $permalinks->buildPostUrl($base, 'de'),
                    'en' => $permalinks->buildPostUrl($base, 'en'),
                ];
            } else {
                $englishSlug = trim((string) ($this->readField($content, 'slug_en') ?? ''), '/');
                $siteUrl = rtrim((string) SITE_URL, '/');
                $alternates = [
                    'de' => $siteUrl . '/' . $baseSlug,
                    'en' => $siteUrl . '/en/' . ($englishSlug !== '' ? $englishSlug : $baseSlug),
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        $alternates['x-default'] = $alternates['de'];

        return $alternates;
    }

    private function isHubPayload(object|array $content): bool
    {
        if (strtolower(trim((string) ($this->readField($content, 'content_type') ?? ''))) === 'hub') {
            return true;
        }

        return str_contains((string) ($this->readField($content, 'content') ?? ''), 'cms-hub-site');
    }

    private function isPubliclyVisibleContent(object|array $content): bool
    {
        $status = (string) ($this->readField($content, 'status') ?? 'published');
        if ($status !== '' && $status !== 'published') {
            return false;
        }

        $publishedAt = trim((string) ($this->readField($content, 'published_at') ?? ''));
        $timestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;

        return $timestamp === false || $timestamp <= time();
    }

    private function readField(object|array $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        return $source->{$key} ?? null;
    }

    /** @param array<string,mixed> $socialDefaults
     *  @return array<string,mixed>
     */
    private function buildRequestMetaPayload(array $socialDefaults, string $fallbackCanonicalUrl): array
    {
        $title = (string) ($this->requestMeta['title'] ?? SITE_NAME);
        $description = (string) ($this->requestMeta['description'] ?? $this->settings->getMetaDescription(''));
        $canonicalUrl = (string) ($this->requestMeta['canonical_url'] ?? $fallbackCanonicalUrl);
        $ogImage = $this->absolutizeUrl((string) ($this->requestMeta['og_image'] ?? $socialDefaults['image']));

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => (string) ($this->requestMeta['keywords'] ?? ''),
            'canonical_url' => $canonicalUrl,
            'robots_index' => !array_key_exists('robots_index', $this->requestMeta) || !empty($this->requestMeta['robots_index']),
            'robots_follow' => !array_key_exists('robots_follow', $this->requestMeta) || !empty($this->requestMeta['robots_follow']),
            'og_title' => (string) ($this->requestMeta['og_title'] ?? $title),
            'og_description' => (string) ($this->requestMeta['og_description'] ?? $description),
            'og_image' => $ogImage,
            'og_type' => (string) ($this->requestMeta['og_type'] ?? 'website'),
            'og_site_name' => (string) ($this->requestMeta['og_site_name'] ?? $socialDefaults['brand_name']),
            'twitter_card' => (string) ($this->requestMeta['twitter_card'] ?? ($ogImage !== '' ? 'summary_large_image' : $socialDefaults['twitter_card'])),
            'twitter_title' => (string) ($this->requestMeta['twitter_title'] ?? $title),
            'twitter_description' => (string) ($this->requestMeta['twitter_description'] ?? $description),
            'twitter_image' => (string) ($this->requestMeta['twitter_image'] ?? $ogImage),
            'schema_type' => (string) ($this->requestMeta['schema_type'] ?? 'WebPage'),
            'url' => $canonicalUrl,
            'content_type' => 'request',
            'updated_at' => date(DATE_W3C),
        ];
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private function normalizeRequestMeta(array $payload): array
    {
        $normalized = [];
        foreach (['title', 'description', 'keywords', 'og_title', 'og_description', 'og_site_name', 'twitter_title', 'twitter_description'] as $key) {
            $value = $this->sanitizeRequestText($payload[$key] ?? '', $key === 'description' || str_contains($key, 'description') ? 500 : 255);
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        foreach (['canonical_url', 'og_image', 'twitter_image'] as $key) {
            $value = $this->sanitizeRequestUrl($payload[$key] ?? '');
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        foreach (['robots_index', 'robots_follow'] as $key) {
            if (array_key_exists($key, $payload)) {
                $normalized[$key] = !empty($payload[$key]);
            }
        }

        $ogType = strtolower(trim((string) ($payload['og_type'] ?? '')));
        if (in_array($ogType, ['website', 'article'], true)) {
            $normalized['og_type'] = $ogType;
        }

        $twitterCard = strtolower(trim((string) ($payload['twitter_card'] ?? '')));
        if (in_array($twitterCard, ['summary', 'summary_large_image'], true)) {
            $normalized['twitter_card'] = $twitterCard;
        }

        $schemaType = trim((string) ($payload['schema_type'] ?? ''));
        if (in_array($schemaType, SeoSchemaRenderer::SUPPORTED_TYPES, true)) {
            $normalized['schema_type'] = $schemaType;
        }

        return $normalized;
    }

    private function sanitizeRequestText(mixed $value, int $maxLength): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }

        $value = trim(strip_tags((string) $value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
    }

    private function sanitizeRequestUrl(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }

        $url = trim((string) $value);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }
}
