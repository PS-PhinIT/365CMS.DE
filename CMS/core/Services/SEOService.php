<?php
/**
 * SEO Service
 *
 * Schema.org, Sitemap, robots.txt
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Hooks;
use CMS\Http\Client as HttpClient;
use CMS\Logger;
use CMS\VendorRegistry;
use CMS\Services\SEO\SearchEngineVerification;
use CMS\Services\SEO\SeoAuditService;
use CMS\Services\SEO\SeoMetaService;
use CMS\Services\SEO\SeoSitemapService;

if (!defined('ABSPATH')) {
    exit;
}

VendorRegistry::instance()->loadPackage('melbahja-seo');

class SEOService
{
    private const DEFAULT_AUDIT_ROW_LIMIT_PER_TYPE = 1000;

    private static ?self $instance = null;

    private readonly SeoMetaService $metaService;
    private readonly SeoSitemapService $sitemapService;
    private readonly SeoAuditService $auditService;
    private bool $requestMetaHooksRegistered = false;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Backwards-compatible singleton alias for existing plugins.
     */
    public static function instance(): self
    {
        return self::getInstance();
    }

    private function __construct()
    {
        $db = Database::instance();
        $logger = Logger::instance()->withChannel('seo');
        $prefix = $db->getPrefix();

        $this->metaService = new SeoMetaService($db, $prefix);
        $this->sitemapService = new SeoSitemapService($db, $logger, HttpClient::getInstance(), $prefix, $this->metaService);
        $this->auditService = new SeoAuditService($db, $prefix);
    }

    public function getContentMeta(string $contentType, int $contentId): array
    {
        return $this->metaService->getContentMeta($contentType, $contentId);
    }

    public function saveContentMeta(string $contentType, int $contentId, array $data): void
    {
        $this->metaService->saveContentMeta($contentType, $contentId, $data);
    }

    public function getAuditRows(int $limitPerType = self::DEFAULT_AUDIT_ROW_LIMIT_PER_TYPE): array
    {
        return $this->auditService->getAuditRows($limitPerType);
    }

    public function renderCurrentHeadTags(): string
    {
        return $this->metaService->renderCurrentHeadTags();
    }

    /**
     * SEO-Daten der aktuellen Seite/des Beitrags inkl. Overrides (Meta-Titel, -Beschreibung,
     * Canonical, Robots, Open Graph, Twitter) – für Themes mit eigener Head-Ausgabe.
     *
     * @return array<string, mixed>
     */
    public function getCurrentSeoPayload(): array
    {
        return $this->metaService->getCurrentSeoPayload();
    }

    /**
     * Stores metadata only for the active HTTP request. No database value is changed.
     *
     * @param array<string,mixed> $payload
     */
    public function setRequestMeta(array $payload): void
    {
        $this->metaService->setRequestMeta(array_merge($this->metaService->getRequestMeta(), $payload));
        $this->registerRequestMetaHooks();
    }

    public function setTitle(string $title): void
    {
        $this->setRequestMeta(['title' => $title]);
    }

    public function setDescription(string $description): void
    {
        $this->setRequestMeta(['description' => $description]);
    }

    public function setCanonical(string $canonicalUrl): void
    {
        $this->setRequestMeta(['canonical_url' => $canonicalUrl]);
    }

    /** @param array<string,mixed> $values */
    public function setOpenGraph(array $values): void
    {
        $map = [
            'og:title' => 'og_title',
            'og:description' => 'og_description',
            'og:image' => 'og_image',
            'og:type' => 'og_type',
            'og:url' => 'canonical_url',
            'og:site_name' => 'og_site_name',
        ];
        $payload = [];
        foreach ($map as $source => $target) {
            if (array_key_exists($source, $values)) {
                $payload[$target] = $values[$source];
            }
        }

        $this->setRequestMeta($payload);
    }

    public function addMeta(string $name, string $value, string $type = 'name'): void
    {
        $name = strtolower(trim($name));
        $type = strtolower(trim($type));
        $map = [
            'description' => 'description',
            'keywords' => 'keywords',
            'og:title' => 'og_title',
            'og:description' => 'og_description',
            'og:image' => 'og_image',
            'og:type' => 'og_type',
            'og:url' => 'canonical_url',
            'og:site_name' => 'og_site_name',
            'twitter:card' => 'twitter_card',
            'twitter:title' => 'twitter_title',
            'twitter:description' => 'twitter_description',
            'twitter:image' => 'twitter_image',
        ];
        if (($type === 'name' || $type === 'property') && isset($map[$name])) {
            $this->setRequestMeta([$map[$name] => $value]);
        }
    }

    /**
     * Generate Schema.org JSON-LD for Organization.
     */
    public function generateOrganizationSchema(): string
    {
        return $this->metaService->generateOrganizationSchema();
    }

    /**
     * Generate Schema.org JSON-LD for WebSite.
     */
    public function generateWebSiteSchema(): string
    {
        return $this->metaService->generateWebSiteSchema();
    }

    /**
     * Generate Schema.org JSON-LD for WebPage.
     */
    public function generateWebPageSchema(string $title, string $description, string $url): string
    {
        return $this->metaService->generateWebPageSchema($title, $description, $url);
    }

    /**
     * Generate XML Sitemap index.
     */
    public function generateSitemap(): string
    {
        return $this->sitemapService->generateSitemap();
    }

    /**
     * Eine Datei des Sitemap-Bundles (sitemap.xml, pages.xml, posts.xml, plugins.xml, images.xml, news.xml).
     */
    public function getSitemapFile(string $fileName): string
    {
        return $this->sitemapService->getSitemapFile($fileName);
    }

    public function invalidateSavedSitemaps(): void
    {
        $this->sitemapService->invalidateSavedSitemaps();
    }

    /**
     * Generate robots.txt.
     */
    public function generateRobotsTxt(): string
    {
        return $this->sitemapService->generateRobotsTxt();
    }

    /**
     * Save sitemap bundle to disk.
     */
    public function saveSitemap(): bool
    {
        return $this->sitemapService->saveSitemap();
    }

    public function generateImageSitemap(): string
    {
        return $this->sitemapService->generateImageSitemap();
    }

    public function generateNewsSitemap(): string
    {
        return $this->sitemapService->generateNewsSitemap();
    }

    public function saveImageSitemap(): bool
    {
        return $this->sitemapService->saveImageSitemap();
    }

    public function saveNewsSitemap(): bool
    {
        return $this->sitemapService->saveNewsSitemap();
    }

    public function saveSitemapBundle(): bool
    {
        return $this->sitemapService->saveSitemapBundle();
    }

    /**
     * Save robots.txt to file.
     */
    public function saveRobotsTxt(): bool
    {
        return $this->sitemapService->saveRobotsTxt();
    }

    /**
     * Get Custom Header Code.
     */
    public function getCustomHeaderCode(): string
    {
        return $this->metaService->getCustomHeaderCode();
    }

    /**
     * Meta-Tags zur Website-Verifizierung (Google, Bing, Yandex, Seznam, Naver, Baidu).
     */
    public function getSearchEngineVerificationTags(): string
    {
        return SearchEngineVerification::renderTags();
    }

    /**
     * Get all Analytics Head Code.
     */
    public function getAnalyticsHeadCode(): string
    {
        return $this->metaService->getAnalyticsHeadCode();
    }

    /**
     * Get Analytics Body Code.
     */
    public function getAnalyticsBodyCode(): string
    {
        return $this->metaService->getAnalyticsBodyCode();
    }

    /**
     * @return array<string, list<string>>
     */
    public function getAnalyticsCspSources(): array
    {
        return $this->metaService->getAnalyticsCspSources();
    }

    /**
     * Aktive, validierte Analytics-Anbieter (z. B. damit Themes kein doppeltes Tracking ausgeben).
     *
     * @return array{respectDnt: bool, providers: array<string, array<string, mixed>>}
     */
    public function getAnalyticsClientConfig(): array
    {
        return $this->metaService->getAnalyticsClientConfig();
    }

    /**
     * Get SEO homepage title.
     */
    public function getHomepageTitle(string $default = ''): string
    {
        return $this->metaService->getHomepageTitle($default);
    }

    /**
     * Get SEO homepage meta description.
     */
    public function getHomepageDescription(string $default = ''): string
    {
        return $this->metaService->getHomepageDescription($default);
    }

    /**
     * Get global meta description.
     */
    public function getMetaDescription(string $default = ''): string
    {
        return $this->metaService->getMetaDescription($default);
    }

    public function getSitemapSettings(): array
    {
        return $this->metaService->getSitemapSettings();
    }

    /**
     * Get site title format.
     */
    public function getSiteTitleFormat(): string
    {
        return $this->metaService->getSiteTitleFormat();
    }

    /**
     * Get title separator.
     */
    public function getTitleSeparator(): string
    {
        return $this->metaService->getTitleSeparator();
    }

    public function getLastSitemapError(): ?string
    {
        return $this->sitemapService->getLastSitemapError();
    }

    private function registerRequestMetaHooks(): void
    {
        if ($this->requestMetaHooksRegistered || !class_exists(Hooks::class)) {
            return;
        }

        $this->requestMetaHooksRegistered = true;
        Hooks::addFilter('page_title', function (mixed $title): string {
            $requestMeta = $this->metaService->getRequestMeta();
            $requestTitle = trim((string) ($requestMeta['title'] ?? ''));

            return $requestTitle !== '' ? $requestTitle : (string) $title;
        }, 1000);
        Hooks::addFilter('phinit_head_meta_data', function (mixed $metaData): array {
            $metaData = is_array($metaData) ? $metaData : [];
            $requestMeta = $this->metaService->getRequestMeta();
            if ($requestMeta === []) {
                return $metaData;
            }

            $title = trim((string) ($requestMeta['title'] ?? ''));
            $description = trim((string) ($requestMeta['description'] ?? ''));
            $canonicalUrl = trim((string) ($requestMeta['canonical_url'] ?? ''));
            $imageUrl = trim((string) ($requestMeta['og_image'] ?? $requestMeta['twitter_image'] ?? ''));

            if ($title !== '') {
                $metaData['og_title'] = (string) ($requestMeta['og_title'] ?? $title);
                $metaData['twitter_title'] = (string) ($requestMeta['twitter_title'] ?? $title);
            }
            if ($description !== '') {
                $metaData['description'] = $description;
                $metaData['og_description'] = (string) ($requestMeta['og_description'] ?? $description);
                $metaData['twitter_description'] = (string) ($requestMeta['twitter_description'] ?? $description);
            }
            if ($canonicalUrl !== '') {
                $metaData['canonical_url'] = $canonicalUrl;
                $metaData['og_url'] = $canonicalUrl;
                $metaData['canonical_self'] = true;
            }
            if ($imageUrl !== '') {
                $metaData['og_image'] = $imageUrl;
                $metaData['twitter_image'] = (string) ($requestMeta['twitter_image'] ?? $imageUrl);
                $metaData['twitter_card'] = (string) ($requestMeta['twitter_card'] ?? 'summary_large_image');
            }
            if (!empty($requestMeta['og_type'])) {
                $metaData['og_type'] = (string) $requestMeta['og_type'];
            }
            if (array_key_exists('robots_index', $requestMeta) || array_key_exists('robots_follow', $requestMeta)) {
                $metaData['robots'] = (!array_key_exists('robots_index', $requestMeta) || !empty($requestMeta['robots_index']) ? 'index' : 'noindex')
                    . ','
                    . (!array_key_exists('robots_follow', $requestMeta) || !empty($requestMeta['robots_follow']) ? 'follow' : 'nofollow');
            }

            return $metaData;
        }, 1000);
    }
}
