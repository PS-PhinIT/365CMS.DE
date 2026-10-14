<?php
declare(strict_types=1);

namespace CMS\Services\SEO;

use CMS\Contracts\DatabaseInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class SeoSettingsStore
{
    private const ALLOWED_OG_TYPES = ['website', 'article', 'profile'];
    private const ALLOWED_TWITTER_CARDS = ['summary', 'summary_large_image'];

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $prefix
    ) {
    }

    public function getSetting(string $key, string $default = ''): string
    {
        // Request-Cache statt Einzelabfrage je SEO-Schlüssel.
        return \CMS\Services\OptionStore::getInstance()->get('seo_' . $key) ?? $default;
    }

    public function getCustomHeaderCode(): string
    {
        return $this->getSetting('custom_header_code', '');
    }

    /**
     * X-/Twitter-Handle ohne "@" aus SEO → Social (Fallback: früherer Schlüssel twitter_site).
     * Akzeptiert "@name", "name" und Profil-URLs von x.com bzw. twitter.com.
     */
    public function getTwitterHandle(): string
    {
        $profile = trim($this->getSetting('social_twitter_profile', ''));
        if ($profile === '') {
            $profile = trim($this->getSetting('twitter_site', ''));
        }

        if (preg_match('#^https?://(?:www\.|mobile\.)?(?:x|twitter)\.com/@?([A-Za-z0-9_]{1,15})/?(?:[?\#].*)?$#i', $profile, $match) === 1
            || preg_match('/^@?([A-Za-z0-9_]{1,15})$/', $profile, $match) === 1
        ) {
            return $match[1];
        }

        return '';
    }

    public function getHomepageTitle(string $default = ''): string
    {
        return $this->getSetting('homepage_title', $default);
    }

    public function getHomepageDescription(string $default = ''): string
    {
        return $this->getSetting('homepage_description', $default);
    }

    public function getMetaDescription(string $default = ''): string
    {
        $desc = $this->getSetting('meta_description', '');
        if ($desc === '') {
            $desc = $this->getSetting('homepage_description', '');
        }

        return $desc !== '' ? $desc : $default;
    }

    /**
     * @return array{og_type: string, image: string, twitter_card: string, brand_name: string}
     */
    public function getSocialDefaults(): array
    {
        $fallbackSiteName = defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
        $brandName = trim($this->getSetting('social_brand_name', $fallbackSiteName));

        return [
            'og_type' => $this->normalizeAllowedValue($this->getSetting('social_default_og_type', 'website'), self::ALLOWED_OG_TYPES, 'website'),
            'image' => trim($this->getSetting('social_default_image', '')),
            'twitter_card' => $this->normalizeAllowedValue($this->getSetting('social_default_twitter_card', 'summary_large_image'), self::ALLOWED_TWITTER_CARDS, 'summary_large_image'),
            'brand_name' => $brandName !== '' ? $brandName : $fallbackSiteName,
        ];
    }

    public function getSitemapSettings(): array
    {
        return [
            'pages_priority' => $this->getSetting('sitemap_pages_priority', '0.8'),
            'pages_changefreq' => $this->getSetting('sitemap_pages_changefreq', 'weekly'),
            'posts_priority' => $this->getSetting('sitemap_posts_priority', '0.6'),
            'posts_changefreq' => $this->getSetting('sitemap_posts_changefreq', 'monthly'),
            'ping_google' => $this->getSetting('sitemap_ping_google', '0') === '1',
            'ping_bing' => $this->getSetting('sitemap_ping_bing', '0') === '1',
            'news_publication_name' => $this->getSetting('sitemap_news_publication_name', defined('SITE_NAME') ? SITE_NAME : '365CMS'),
            'news_language' => $this->getSetting('sitemap_news_language', 'de'),
        ];
    }

    public function getSiteTitleFormat(): string
    {
        return $this->getSetting('site_title_format', '%title% | %sitename%');
    }

    public function getTitleSeparator(): string
    {
        return $this->getSetting('title_separator', '|');
    }

    private function normalizeAllowedValue(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
