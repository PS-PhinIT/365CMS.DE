<?php
/**
 * Update Service
 * 
 * Manages system, plugin and theme updates via GitHub API
 * Repository: https://github.com/PS-easyIT/365CMS.DE
 * 
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Http\Client as HttpClient;
use CMS\Json;
use CMS\Logger;
use CMS\PluginManager;
use CMS\ThemeManager;
use CMS\Version;

if (!defined('ABSPATH')) {
    exit;
}

class UpdateService
{
    private const UPDATE_MIN_FREE_DISK_BYTES = 1073741824;
    private const UPDATE_WARN_FREE_DISK_BYTES = 2147483648;
    private const UPDATE_MAX_ARCHIVE_ENTRIES = 5000;
    private const UPDATE_MAX_ARCHIVE_FILE_BYTES = 268435456;
    private const UPDATE_MAX_ARCHIVE_UNCOMPRESSED_BYTES = 536870912;

    private const ALLOWED_UPDATE_HOSTS = [
        '365cms.de',
        'www.365cms.de',
        '365network.de',
        'www.365network.de',
        'api.github.com',
        'codeload.github.com',
        'github.com',
        'objects.githubusercontent.com',
        'raw.githubusercontent.com',
    ];

    private static ?self $instance = null;
    private Database $db;
    private HttpClient $httpClient;
    
    /** Fallback-Werte – werden von DB-Setting überschrieben, wenn vorhanden */
    private const DEFAULT_GITHUB_REPO = 'PS-easyIT/365CMS.DE';
    private const DEFAULT_GITHUB_API = 'https://api.github.com';
    private const DEFAULT_CORE_UPDATE_URL = MarketplaceEndpoints::CORE_UPDATE_URL;
    private const CACHE_DURATION = 3600; // 1 hour

    /** Aktive Konfiguration (aus DB oder Fallback) */
    private string $githubRepo;
    private string $githubApi;
    private string $coreUpdateUrl;
    
    /**
     * Singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->db = Database::instance();
        $this->httpClient = HttpClient::getInstance();
        $this->loadUpdateConfig();
    }

    /**
     * Load update repo configuration from DB settings (or use defaults)
     */
    private function loadUpdateConfig(): void
    {
        try {
            $row = $this->db->fetchOne(
                "SELECT option_value FROM {$this->db->getPrefix()}settings WHERE option_name = 'update_github_repo'"
            );
            $this->githubRepo = (!empty($row['option_value'])) ? $row['option_value'] : self::DEFAULT_GITHUB_REPO;

            $row2 = $this->db->fetchOne(
                "SELECT option_value FROM {$this->db->getPrefix()}settings WHERE option_name = 'update_github_api'"
            );
            $this->githubApi = (!empty($row2['option_value'])) ? rtrim($row2['option_value'], '/') : self::DEFAULT_GITHUB_API;

            $this->coreUpdateUrl = MarketplaceEndpoints::coreUpdateUrl();
        } catch (\Throwable $e) {
            $this->githubRepo = self::DEFAULT_GITHUB_REPO;
            $this->githubApi  = self::DEFAULT_GITHUB_API;
            $this->coreUpdateUrl = self::DEFAULT_CORE_UPDATE_URL;
        }
    }
    
    /**
     * Check for CMS core updates via GitHub
     */
    public function checkCoreUpdates(): array
    {
        $currentVersion = CMS_VERSION ?? Version::CURRENT;
        
        // Check cache first
        $cacheKey = 'core_update_check';
        $cached = $this->getCache($cacheKey);
        
        if ($cached !== null) {
            return $cached;
        }

        $marketplaceRelease = $this->fetchMarketplaceJson($this->coreUpdateUrl, 512 * 1024);
        if (is_array($marketplaceRelease) && !empty($marketplaceRelease)) {
            $latestVersion = (string) ($marketplaceRelease['version'] ?? $marketplaceRelease['latest_version'] ?? '');

            if ($latestVersion !== '') {
                $downloadUrl = $this->resolveAllowedUpdateUrl((string) ($marketplaceRelease['download_url'] ?? ''));
                $sha256 = $this->resolveIntegrityHashValue($marketplaceRelease);
                $result = [
                    'current_version' => $currentVersion,
                    'latest_version' => $latestVersion,
                    'update_available' => version_compare($latestVersion, $currentVersion, '>'),
                    'changelog' => $this->normalizeChangelogText($marketplaceRelease['changelog'] ?? $marketplaceRelease['notes'] ?? ''),
                    'changelog_items' => $this->normalizeChangelogList($marketplaceRelease['changelog'] ?? []),
                    'release_date' => (string) ($marketplaceRelease['released'] ?? $marketplaceRelease['published_at'] ?? ''),
                    'download_url' => $downloadUrl,
                    'sha256' => $sha256,
                    'install_supported' => $downloadUrl !== '' && $sha256 !== '',
                    'manual_reason' => $sha256 === '' ? 'Automatische Installation benötigt eine gültige SHA-256-Prüfsumme im Update-Manifest.' : '',
                    'release_notes' => (string) ($marketplaceRelease['notes'] ?? ''),
                    'purchase_url' => $this->resolveAllowedUpdateUrl((string) ($marketplaceRelease['purchase_url'] ?? '')),
                    'is_paid' => $this->normalizeBooleanValue($marketplaceRelease['is_paid'] ?? false),
                    'price_amount' => (string) ($marketplaceRelease['price_amount'] ?? $marketplaceRelease['price'] ?? ''),
                    'price_currency' => strtoupper((string) ($marketplaceRelease['price_currency'] ?? 'EUR')),
                    'requires_cms' => (string) ($marketplaceRelease['requires_cms'] ?? $marketplaceRelease['min_cms_version'] ?? ''),
                    'requires_php' => (string) ($marketplaceRelease['requires_php'] ?? $marketplaceRelease['min_php'] ?? ''),
                ];

                $this->setCache($cacheKey, $result);

                return $result;
            }
        }
        
        // Fetch latest release from GitHub (konfigurierbar via DB)
        $url = $this->githubApi . '/repos/' . $this->githubRepo . '/releases/latest';
        $release = $this->fetchGitHubData($url);
        
        if (!$release) {
            // No release found, return current version
            return [
                'current_version' => $currentVersion,
                'latest_version' => $currentVersion,
                'update_available' => false,
                'changelog' => [],
                'release_date' => '',
                'download_url' => '',
            ];
        }
        
        $latestVersion = ltrim($release['tag_name'] ?? $currentVersion, 'v');
        $updateAvailable = version_compare($latestVersion, $currentVersion, '>');
        
        $downloadUrl = $this->resolveAllowedUpdateUrl((string) ($release['zipball_url'] ?? ''));
        $sha256 = $this->resolveIntegrityHashValue($release);
        $result = [
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'update_available' => $updateAvailable,
            'changelog' => trim((string) ($release['body'] ?? '')),
            'changelog_items' => $this->parseChangelog($release['body'] ?? ''),
            'release_date' => isset($release['published_at']) 
                ? date('Y-m-d', strtotime($release['published_at'])) 
                : '',
            'download_url' => $downloadUrl,
            'sha256' => $sha256,
            'install_supported' => $downloadUrl !== '' && $sha256 !== '',
            'manual_reason' => $sha256 === '' ? 'Automatische Installation benötigt eine gültige SHA-256-Prüfsumme im Release-Manifest.' : '',
            'release_notes' => $release['body'] ?? '',
        ];
        
        // Cache result
        $this->setCache($cacheKey, $result);
        
        return $result;
    }
    
    /**
     * Check for plugin updates
     */
    public function checkPluginUpdates(): array
    {
        $pluginManager = PluginManager::instance();
        $plugins = $pluginManager->getAvailablePlugins();
        $catalog = $this->loadPluginMarketplaceCatalog();
        
        $updates = [];
        
        foreach ($plugins as $folder => $plugin) {
            $currentVersion = $plugin['version'] ?? '1.0.0';

            $localUpdateData = [];
            $catalogEntry = $catalog[$folder] ?? [];

            $updateInfo = array_replace($plugin, $localUpdateData, $catalogEntry);
            $updateUrl = $this->resolveAllowedUpdateUrl((string) ($updateInfo['update_url'] ?? ''));
            if ($updateUrl !== '') {
                $remoteUpdate = $this->fetchPluginUpdate($updateUrl) ?? [];
                $updateInfo = array_replace($updateInfo, $remoteUpdate);
            }

            $latestVersion = (string) ($updateInfo['version'] ?? '');
            if ($latestVersion === '' || !version_compare($latestVersion, (string) $currentVersion, '>')) {
                continue;
            }

            $downloadUrl = $this->resolveAllowedUpdateUrl((string) ($updateInfo['download_url'] ?? ''));
            $sha256 = $this->resolveIntegrityHashValue($updateInfo);
            $purchaseUrl = $this->resolveAllowedUpdateUrl((string) ($updateInfo['purchase_url'] ?? ''));
            $isPaid = $this->normalizeBooleanValue($updateInfo['is_paid'] ?? false);

            $updates[$folder] = [
                'name' => (string) ($plugin['name'] ?? $catalogEntry['name'] ?? $folder),
                'current_version' => (string) $currentVersion,
                'new_version' => $latestVersion,
                'update_available' => true,
                'download_url' => $downloadUrl,
                'sha256' => $sha256,
                'changelog' => $this->normalizeChangelogText($updateInfo['changelog'] ?? $updateInfo['notes'] ?? ''),
                'purchase_url' => $purchaseUrl,
                'is_paid' => $isPaid,
                'price_amount' => (string) ($updateInfo['price_amount'] ?? $updateInfo['price'] ?? ''),
                'price_currency' => strtoupper((string) ($updateInfo['price_currency'] ?? 'EUR')),
                'requires_cms' => (string) ($updateInfo['requires_cms'] ?? $updateInfo['min_cms_version'] ?? ''),
                'requires_php' => (string) ($updateInfo['requires_php'] ?? $updateInfo['min_php'] ?? ''),
                'install_supported' => $downloadUrl !== '' && $sha256 !== '',
                'manual_reason' => $this->buildIntegrityAwareMarketplaceReason($downloadUrl, $sha256, $purchaseUrl, $isPaid, 'Plugin'),
            ];
        }
        
        return $updates;
    }
    
    /**
     * Check for theme updates
     */
    public function checkThemeUpdates(): array
    {
        $themeSlug = ThemeManager::instance()->getActiveThemeSlug();
        $themeDir = rtrim((string) THEME_PATH, '/\\') . DIRECTORY_SEPARATOR . $themeSlug;
        $themeJsonFile = $themeDir . DIRECTORY_SEPARATOR . 'theme.json';

        if (!file_exists($themeJsonFile)) {
            return [];
        }

        $themeData = Json::decodeArray((string) file_get_contents($themeJsonFile), []);
        $currentVersion = $themeData['version'] ?? '1.0.0';

        $localUpdateData = [];
        $catalog = $this->loadThemeMarketplaceCatalog();
        $catalogEntry = $catalog[$themeSlug] ?? [];

        $updateInfo = array_replace($themeData, $localUpdateData, $catalogEntry);
        $updateUrl = $this->resolveAllowedUpdateUrl((string) ($updateInfo['update_url'] ?? ''));
        if ($updateUrl !== '') {
            $remoteUpdate = $this->fetchPluginUpdate($updateUrl) ?? [];
            $updateInfo = array_replace($updateInfo, $remoteUpdate);
        }

        $latestVersion = (string) ($updateInfo['version'] ?? $currentVersion);
        
        $updateAvailable = version_compare($latestVersion, $currentVersion, '>');
        $downloadUrl = $this->resolveAllowedUpdateUrl((string) ($updateInfo['download_url'] ?? ''));
        $sha256 = $this->resolveIntegrityHashValue($updateInfo);
        $purchaseUrl = $this->resolveAllowedUpdateUrl((string) ($updateInfo['purchase_url'] ?? ''));
        $isPaid = $this->normalizeBooleanValue($updateInfo['is_paid'] ?? false);
        
        return [
            'slug' => $themeSlug,
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'update_available' => $updateAvailable,
            'changelog' => $this->normalizeChangelogText($updateInfo['changelog'] ?? $updateInfo['notes'] ?? ''),
            'download_url' => $downloadUrl,
            'purchase_url' => $purchaseUrl,
            'is_paid' => $isPaid,
            'price_amount' => (string) ($updateInfo['price_amount'] ?? $updateInfo['price'] ?? ''),
            'price_currency' => strtoupper((string) ($updateInfo['price_currency'] ?? 'EUR')),
            'sha256' => $sha256,
            'requires_cms' => (string) ($updateInfo['requires_cms'] ?? $updateInfo['min_cms_version'] ?? ''),
            'requires_php' => (string) ($updateInfo['requires_php'] ?? $updateInfo['min_php'] ?? ''),
            'install_supported' => $downloadUrl !== '' && $sha256 !== '',
            'manual_reason' => $this->buildIntegrityAwareMarketplaceReason($downloadUrl, $sha256, $purchaseUrl, $isPaid, 'Theme'),
        ];
    }

    private function fetchMarketplaceJson(string $url, int $maxBytes = 1048576): ?array
    {
        if (!$this->isAllowedSensitiveRemoteUrl($url) || !$this->isSafeExternalUrl($url)) {
            return null;
        }

        $response = $this->httpClient->get($url, [
            'timeout' => 10,
            'connectTimeout' => 5,
            'headers' => ['Accept: application/json'],
            'userAgent' => '365CMS-UpdateChecker/1.0',
            'allowedContentTypes' => ['application/json', 'text/plain'],
            'maxBytes' => $maxBytes,
        ]);

        if (!$response['success']) {
            return null;
        }

        $data = Json::decode((string) ($response['body'] ?? ''), true, null);

        return is_array($data) ? $data : null;
    }

    private function loadPluginMarketplaceCatalog(): array
    {
        $registryUrl = MarketplaceEndpoints::pluginIndexUrl();

        $data = $this->fetchMarketplaceJson($registryUrl);
        if (!is_array($data)) {
            return [];
        }

        $entries = is_array($data['plugins'] ?? null) ? $data['plugins'] : $data;
        if (!is_array($entries)) {
            return [];
        }

        return $this->normalizePluginCatalogEntries($entries, $this->resolveMarketplaceSourceBase(MarketplaceEndpoints::pluginBaseUrl(), $registryUrl));
    }

    private function normalizePluginCatalogEntries(array $entries, string $sourceBase): array
    {
        $catalog = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $manifestData = $this->loadCatalogManifestData($entry, $sourceBase);
            $normalized = array_replace($entry, $manifestData);
            $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($normalized['slug'] ?? '')));
            if ($slug === '') {
                continue;
            }

            $normalized['slug'] = $slug;
            $normalized['download_url'] = $this->resolveAllowedUpdateUrl((string) ($normalized['download_url'] ?? $normalized['package_url'] ?? $normalized['archive_url'] ?? ''), $sourceBase);
            $normalized['update_url'] = $this->resolveAllowedUpdateUrl((string) ($normalized['update_url'] ?? ''), $sourceBase);
            $normalized['purchase_url'] = $this->resolveAllowedUpdateUrl((string) ($normalized['purchase_url'] ?? $normalized['buy_url'] ?? $normalized['order_url'] ?? ''), $sourceBase);
            $catalog[$slug] = $normalized;
        }

        return $catalog;
    }

    private function loadThemeMarketplaceCatalog(): array
    {
        $catalogUrl = $this->resolveCatalogIndexUrl(MarketplaceEndpoints::themeIndexUrl());
        if ($catalogUrl === '') {
            return [];
        }

        $data = $this->fetchMarketplaceJson($catalogUrl);
        if (!is_array($data)) {
            return [];
        }

        return $this->normalizeThemeCatalogEntries($data, $this->resolveMarketplaceSourceBase(MarketplaceEndpoints::themeBaseUrl(), $catalogUrl));
    }

    private function normalizeThemeCatalogEntries(array $data, string $sourceBase): array
    {
        $entries = [];

        if (isset($data['themes']) && is_array($data['themes'])) {
            $entries = $data['themes'];
        } elseif (array_is_list($data)) {
            $entries = $data;
        }

        $catalog = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $manifestData = $this->loadCatalogManifestData($entry, $sourceBase);
            $normalized = array_replace($entry, $manifestData);
            $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($normalized['slug'] ?? '')));
            if ($slug === '') {
                continue;
            }

            $normalized['slug'] = $slug;
            $normalized['download_url'] = $this->resolveAllowedUpdateUrl((string) ($normalized['download_url'] ?? $normalized['package_url'] ?? $normalized['archive_url'] ?? ''), $sourceBase);
            $normalized['update_url'] = $this->resolveAllowedUpdateUrl((string) ($normalized['update_url'] ?? ''), $sourceBase);
            $normalized['purchase_url'] = $this->resolveAllowedUpdateUrl((string) ($normalized['purchase_url'] ?? $normalized['buy_url'] ?? $normalized['order_url'] ?? ''), $sourceBase);
            $catalog[$slug] = $normalized;
        }

        return $catalog;
    }

    private function loadCatalogManifestData(array $entry, string $sourceBase): array
    {
        $manifest = trim((string) ($entry['manifest'] ?? ''));
        if ($manifest === '') {
            return [];
        }

        $manifestUrl = $this->resolveAllowedUpdateUrl($manifest, $sourceBase);
        if ($manifestUrl !== '') {
            return $this->fetchMarketplaceJson($manifestUrl, 512 * 1024) ?? [];
        }

        return [];
    }

    private function resolveAllowedUpdateUrl(string $value, string $baseUrl = ''): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $normalizedAbsoluteUrl = $this->normalizeSensitiveRemoteUrl($value);
        if ($normalizedAbsoluteUrl !== '') {
            return $normalizedAbsoluteUrl;
        }

        if ($baseUrl !== '') {
            return $this->resolveAllowedRelativeUpdateUrl($baseUrl, $value);
        }

        return '';
    }

    private function resolveAllowedRelativeUpdateUrl(string $baseUrl, string $relativePath): string
    {
        $normalizedBaseUrl = $this->normalizeSensitiveRemoteUrl($baseUrl);
        $normalizedRelativePath = $this->normalizeRelativeUpdatePath($relativePath);

        if ($normalizedBaseUrl === '' || $normalizedRelativePath === '') {
            return '';
        }

        $resolved = rtrim($normalizedBaseUrl, '/') . '/' . $normalizedRelativePath;

        return $this->normalizeSensitiveRemoteUrl($resolved);
    }

    /**
     * Basis für relative Katalog-URLs: konfigurierte Marketplace-Basis,
     * sonst das Verzeichnis des Index-Feeds.
     */
    private function resolveMarketplaceSourceBase(string $configuredBaseUrl, string $indexUrl): string
    {
        $base = $this->normalizeSensitiveRemoteUrl(rtrim($configuredBaseUrl, '/'));

        return $base !== '' ? $base : $this->resolveBaseUrl($indexUrl);
    }

    private function resolveCatalogIndexUrl(string $baseUrl): string
    {
        $normalizedBaseUrl = $this->normalizeSensitiveRemoteUrl($baseUrl);
        if ($normalizedBaseUrl === '') {
            return '';
        }

        $path = (string) parse_url($normalizedBaseUrl, PHP_URL_PATH);
        if (str_ends_with(strtolower($path), '.json')) {
            return $normalizedBaseUrl;
        }

        return $this->normalizeSensitiveRemoteUrl(rtrim($normalizedBaseUrl, '/') . '/index.json');
    }

    private function resolveBaseUrl(string $url): string
    {
        $url = $this->normalizeSensitiveRemoteUrl($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        if ($scheme === '' || $host === '') {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        $directory = preg_replace('~/[^/]+$~', '', $path) ?? '';

        return $scheme . '://' . $host . $directory;
    }

    private function normalizeSensitiveRemoteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $path = (string) ($parts['path'] ?? '/');
        $query = (string) ($parts['query'] ?? '');

        if ($scheme !== 'https' || $host === '') {
            return '';
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return '';
        }

        if ($port !== null && $port !== 443) {
            return '';
        }

        if (!$this->isAllowedSensitiveRemoteHost($host)) {
            return '';
        }

        $path = $path === '' ? '/' : $path;
        if ($this->containsDisallowedUrlSegments($path) || preg_match('/[\x00-\x1F\x7F]/', $query) === 1) {
            return '';
        }

        $normalizedUrl = $scheme . '://' . $host . $path;
        if ($query !== '') {
            $normalizedUrl .= '?' . $query;
        }

        return $normalizedUrl;
    }

    private function normalizeRelativeUpdatePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if ($path === '' || strlen($path) > 255 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return '';
        }

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return '';
            }
        }

        return implode('/', $segments);
    }

    private function containsDisallowedUrlSegments(string $path): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return true;
        }

        $segments = explode('/', str_replace('\\', '/', $path));
        foreach ($segments as $segment) {
            $decodedSegment = rawurldecode($segment);
            if ($decodedSegment === '.' || $decodedSegment === '..') {
                return true;
            }
        }

        return false;
    }

    private function resolveIntegrityHashValue(array $data): string
    {
        foreach (['sha256', 'checksum_sha256'] as $key) {
            $value = strtolower(trim((string) ($data[$key] ?? '')));
            if ($value !== '' && preg_match('/^[0-9a-f]{64}$/', $value) === 1) {
                return $value;
            }
        }

        return '';
    }

    private function normalizeBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'ja', 'paid'], true);
    }

    private function normalizeChangelogText(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_array($value)) {
            $lines = [];

            foreach ($value as $key => $entry) {
                if (is_string($entry)) {
                    $lines[] = is_string($key) ? $key . ': ' . $entry : $entry;
                }
            }

            return implode("\n", $lines);
        }

        return '';
    }

    private function normalizeChangelogList(mixed $value): array
    {
        if (is_string($value)) {
            return $this->parseChangelog($value);
        }

        if (is_array($value)) {
            $items = [];

            foreach ($value as $key => $entry) {
                if (is_string($entry)) {
                    $items[] = is_string($key) ? $key . ': ' . $entry : $entry;
                }
            }

            return $items;
        }

        return [];
    }

    private function buildMarketplaceReason(string $downloadUrl, string $purchaseUrl, bool $isPaid, string $type): string
    {
        if ($isPaid && $purchaseUrl !== '') {
            return $type . ' ist kostenpflichtig und wird über den Marketplace angefragt oder gekauft.';
        }

        if ($downloadUrl === '') {
            return 'Keine direkte Download-URL im Marketplace hinterlegt.';
        }

        return '';
    }

    private function buildIntegrityAwareMarketplaceReason(string $downloadUrl, string $sha256, string $purchaseUrl, bool $isPaid, string $type): string
    {
        $reason = $this->buildMarketplaceReason($downloadUrl, $purchaseUrl, $isPaid, $type);
        if ($reason !== '') {
            return $reason;
        }

        return $sha256 === ''
            ? 'Automatische Installation benötigt eine gültige SHA-256-Prüfsumme im Update-Manifest.'
            : '';
    }
    
    /**
     * C-11: Fetch data from GitHub API via cURL (ersetzt @file_get_contents)
     *
     * Verwendet cURL mit:
     * - Timeout (Verbindung + Transfer)
     * - TLS-Verifikation (CURLOPT_SSL_VERIFYPEER)
     * - Kein SSRF: nur absolute HTTPS-URLs zu github.com erlaubt
     */
    private function fetchGitHubData(string $url): ?array
    {
        if (!$this->isAllowedGitHubApiUrl($url)) {
            Logger::instance()->withChannel('updates.service')->warning('Invalid GitHub API URL rejected.', [
                'url' => $url,
            ]);
            return null;
        }

        if (!$this->isSafeExternalUrl($url)) {
            Logger::instance()->withChannel('updates.service')->warning('GitHub API URL was blocked by SSRF guard.', [
                'url' => $url,
            ]);
            return null;
        }

        $response = $this->httpClient->get($url, [
            'timeout' => 10,
            'connectTimeout' => 5,
            'userAgent' => '365CMS-UpdateChecker/1.0',
            'headers' => ['Accept: application/vnd.github.v3+json'],
            'allowedContentTypes' => ['application/json'],
            'maxBytes' => 2097152,
        ]);

        if (!$response['success']) {
            Logger::instance()->withChannel('updates.service')->warning('GitHub API request failed.', [
                'url' => $url,
                'error' => (string) ($response['error'] ?? 'unbekannt'),
            ]);
            return null;
        }

        $data = Json::decode((string) ($response['body'] ?? ''), true, null);

        if (!is_array($data)) {
            Logger::instance()->withChannel('updates.service')->warning('GitHub API returned invalid JSON.', [
                'url' => $url,
            ]);
            return null;
        }

        return $data;
    }

    private function isAllowedGitHubApiUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
            return false;
        }

        $baseUrl = rtrim($this->githubApi, '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !str_starts_with($baseUrl, 'https://')) {
            $baseUrl = self::DEFAULT_GITHUB_API;
        }

        $targetParts = parse_url($url);
        $baseParts = parse_url($baseUrl);

        if (!is_array($targetParts) || !is_array($baseParts)) {
            return false;
        }

        $targetHost = strtolower((string)($targetParts['host'] ?? ''));
        $baseHost = strtolower((string)($baseParts['host'] ?? ''));
        if ($targetHost === '' || $baseHost === '' || $targetHost !== $baseHost) {
            return false;
        }

        $targetScheme = strtolower((string)($targetParts['scheme'] ?? ''));
        $baseScheme = strtolower((string)($baseParts['scheme'] ?? ''));
        if ($targetScheme !== 'https' || $baseScheme !== 'https') {
            return false;
        }

        $targetPort = (int)($targetParts['port'] ?? 443);
        $basePort = (int)($baseParts['port'] ?? 443);
        if ($targetPort !== $basePort) {
            return false;
        }

        $basePath = rtrim((string)($baseParts['path'] ?? ''), '/');
        $targetPath = (string)($targetParts['path'] ?? '');

        if ($basePath !== '' && !str_starts_with($targetPath, $basePath . '/')) {
            return $targetPath === $basePath;
        }

        return true;
    }

    /**
     * M-20: Prüft ob eine IP-Adresse zu privaten / reservierten Bereichen gehört.
     *
     * Abgedeckt:
     *  - Loopback        127.0.0.0/8, ::1
     *  - Link-Local      169.254.0.0/16, fe80::/10
     *  - Private (RFC1918) 10.x, 172.16-31.x, 192.168.x
     *  - Unique Local    fc00::/7
     *  - Multicast       224.0.0.0/4, ff00::/8
     *
     * @param  string $ip  V4 oder V6 Adresse
     * @return bool        true = privat/reserviert, false = öffentlich
     */
    private function isPrivateOrReservedIp(string $ip): bool
    {
        return HttpClient::isNonPublicIp($ip);
    }

    /**
     * M-20: SSRF-Guard – löst Hostname auf und prüft ob die IP privat/reserviert ist.
     *
     * @param  string $url  Vollständige HTTPS-URL
     * @return bool         true = URL ist sicher, false = URL blockiert
     */
    private function isSafeExternalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return false;
        }

        $host = strtolower((string) $host);

        if (in_array($host, ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'], true)) {
            return false;
        }

        $resolvedIps = $this->resolveHostIps($host);
        if ($resolvedIps === []) {
            Logger::instance()->withChannel('updates.service')->warning('External update host could not be resolved and was blocked.', [
                'host' => $host,
            ]);
            return false;
        }

        foreach ($resolvedIps as $ip) {
            if ($ip !== '' && $this->isPrivateOrReservedIp($ip)) {
                Logger::instance()->withChannel('updates.service')->warning('External update host resolved to a private or reserved IP and was blocked.', [
                    'host' => $host,
                    'ip' => $ip,
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
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
                    $ip = trim((string) ($record['ip'] ?? $record['ipv6'] ?? ''));
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
                    $ip = trim((string) $ip);
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * C-11: Fetch plugin update information via cURL (ersetzt @file_get_contents)
     * M-20: SSRF-Guard mit Private-IP-Blockliste
     */
    private function fetchPluginUpdate(string $url): ?array
    {
        if (!$this->isAllowedSensitiveRemoteUrl($url)) {
            Logger::instance()->withChannel('updates.service')->warning('Plugin/theme update URL is outside the allowed hosts.', [
                'url' => $url,
            ]);
            return null;
        }

        // M-20: Prüfen ob der Hostname auf eine private/reservierte IP auflöst (SSRF-Schutz)
        if (!$this->isSafeExternalUrl($url)) {
            Logger::instance()->withChannel('updates.service')->warning('Plugin/theme update URL was blocked by SSRF guard.', [
                'url' => $url,
            ]);
            return null;
        }

        $response = $this->httpClient->get($url, [
            'timeout' => 10,
            'connectTimeout' => 5,
            'headers' => ['Accept: application/json'],
            'userAgent' => '365CMS-UpdateChecker/1.0',
            'allowedContentTypes' => ['application/json'],
            'maxBytes' => 1048576,
        ]);

        if (!$response['success']) {
            Logger::instance()->withChannel('updates.service')->warning('Plugin/theme update request failed.', [
                'url' => $url,
                'error' => (string) ($response['error'] ?? 'unbekannt'),
            ]);
            return null;
        }

        $data = Json::decode((string) ($response['body'] ?? ''), true, null);

        return is_array($data) ? $data : null;
    }

    /**
     * C-10: SHA-256-Verifikation für heruntergeladene Dateien (Plugin/Theme)
     *
     * Wird beim Marketplace-Download aufgerufen, sobald eine Datei lokal gespeichert ist.
     * Der erwartete Hash steht im update.json des Anbieters (Feld: sha256).
     *
     * @param  string $filePath     Absoluter Pfad zur heruntergeladenen Datei
     * @param  string $expectedHash Erwarteter SHA-256-Hash (aus update.json/API-Response)
     * @return bool   true = Datei ist integer, false = Hash-Mismatch oder Datei fehlt
     */
    public function verifyDownloadIntegrity(string $filePath, string $expectedHash): bool
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            Logger::instance()->withChannel('updates.service')->warning('Downloaded update file is missing or unreadable.', [
                'file_path' => $filePath,
            ]);
            return false;
        }

        if (!preg_match('/^[0-9a-f]{64}$/i', $expectedHash)) {
            Logger::instance()->withChannel('updates.service')->warning('Expected update hash has an invalid format.', [
                'file_path' => $filePath,
            ]);
            return false;
        }

        $actualHash = hash_file('sha256', $filePath);

        if ($actualHash === false) {
            Logger::instance()->withChannel('updates.service')->warning('Downloaded update hash could not be calculated.', [
                'file_path' => $filePath,
            ]);
            return false;
        }

        $match = hash_equals(strtolower($expectedHash), $actualHash);

        if (!$match) {
            Logger::instance()->withChannel('updates.service')->warning('Downloaded update hash does not match the expected checksum.', [
                'file' => basename($filePath),
                'expected_hash' => $expectedHash,
                'actual_hash' => $actualHash,
            ]);
        }

        return $match;
    }
    
    /**
     * Parse changelog from release notes
     */
    private function parseChangelog(string $body): array
    {
        $lines = explode("\n", $body);
        $changelog = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Match lines starting with - or * (bullet points)
            if (preg_match('/^[-*]\s+(.+)/', $line, $matches)) {
                $changelog[] = trim($matches[1]);
            }
        }
        
        return $changelog;
    }
    
    /**
     * Get update history (REAL DATA)
     */
    public function getUpdateHistory(int $limit = 20): array
    {
        try {
            $limit = max(1, min(100, $limit));
            $stmt = $this->db->prepare("
                SELECT option_value FROM {$this->db->getPrefix()}settings
                WHERE option_name LIKE 'update_log_%'
                ORDER BY option_name DESC
                LIMIT ?
            ");
            
            if (!$stmt) {
                return [];
            }
            
            $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
            $stmt->execute();
            
            $history = [];
            while ($row = $stmt->fetch(\PDO::FETCH_OBJ)) {
                $data = Json::decode($row->option_value ?? null, true, null);
                if (is_array($data)) {
                    $history[] = $data;
                }
            }

            return $this->enrichUpdateHistoryEntries($history);
        } catch (\Exception $e) {
            Logger::instance()->withChannel('updates.service')->warning('Update history could not be loaded.', [
                'limit' => $limit,
                'exception' => $e,
            ]);
            return [];
        }
    }

    public function clearUpdateHistory(): int
    {
        try {
            $statement = $this->db->execute(
                "DELETE FROM {$this->db->getPrefix()}settings WHERE option_name LIKE ?",
                ['update_log_%']
            );

            return $statement->rowCount();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('updates.service')->warning('Update history could not be cleared.', [
                'exception' => $e,
            ]);

            return 0;
        }
    }
    
    /**
     * H-19: Plugin/Theme/Core-Update herunterladen und staging-basiert installieren.
     *
     * Ablauf:
     *  1. Download der ZIP-Datei in einen temporären Pfad
     *  2. SHA-256-Prüfsumme verifizieren (Abbruch bei Mismatch)
     *  3. ZIP in ein Staging-Verzeichnis extrahieren und Paketwurzel bestimmen
     *  4. Ziel per atomarem Directory-Swap oder per Rollback-fähigem Inhalts-Swap austauschen
     *  5. Temporäre Artefakte löschen
     *
     * @param  string $downloadUrl  HTTPS-URL zur ZIP-Datei
     * @param  string $sha256       Erwartete SHA-256-Prüfsumme (leer = Warnung, kein Abbruch)
     * @param  string $targetDir    Absoluter Ziel-Pfad (z. B. PLUGIN_PATH . 'my-plugin/')
     * @param  string $type         'plugin' | 'theme' | 'core' für Logging
     * @param  string $name         Name des Pakets für Logging
     * @param  string $version      Neue Versionsnummer für Logging
     * @return array{success: bool, message: string, sha256_verified: bool}
     */
    public function downloadAndInstallUpdate(
        string $downloadUrl,
        string $sha256,
        string $targetDir,
        string $type,
        string $name,
        string $version
    ): array {
        $sha256 = strtolower(trim($sha256));
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return $this->failInstallResult(
                'updates.install.integrity_hash_missing',
                'Automatische Updates benötigen eine gültige SHA-256-Prüfsumme.',
                ['type' => $type, 'name' => $name, 'version' => $version]
            );
        }

        if (!$this->isAllowedSensitiveRemoteUrl($downloadUrl)) {
            return $this->failInstallResult(
                'updates.install.invalid_download_host',
                'Download-Quelle für das Update ist nicht erlaubt.',
                ['type' => $type, 'name' => $name, 'download_url' => $downloadUrl]
            );
        }

        if (!$this->isSafeExternalUrl($downloadUrl)) {
            return $this->failInstallResult(
                'updates.install.unsafe_download_url',
                'Download-Quelle für das Update konnte nicht verifiziert werden.',
                ['type' => $type, 'name' => $name, 'download_url' => $downloadUrl]
            );
        }

        if (!extension_loaded('zip')) {
            return $this->failInstallResult(
                'updates.install.zip_extension_missing',
                'ZIP-Unterstützung ist auf diesem System nicht verfügbar.',
                ['type' => $type, 'name' => $name]
            );
        }

        $targetDir = rtrim($targetDir, '/\\');
        if ($targetDir === '') {
            return $this->failInstallResult(
                'updates.install.invalid_target',
                'Update-Zielverzeichnis ist ungültig.',
                ['type' => $type, 'name' => $name]
            );
        }

        if (!$this->isAllowedInstallTarget($targetDir, $type)) {
            return $this->failInstallResult(
                'updates.install.target_blocked',
                'Update-Ziel liegt außerhalb der erlaubten Installationspfade.',
                ['type' => $type, 'name' => $name, 'target_dir' => $targetDir]
            );
        }

        $targetParentDir = dirname($targetDir);
        if ($targetParentDir === '' || $targetParentDir === '.' || $targetParentDir === DIRECTORY_SEPARATOR) {
            return $this->failInstallResult(
                'updates.install.target_not_stageable',
                'Update-Ziel kann nicht sicher vorbereitet werden.',
                ['type' => $type, 'name' => $name, 'target_dir' => $targetDir]
            );
        }

        if (!is_dir($targetParentDir) && !mkdir($targetParentDir, 0755, true)) {
            return $this->failInstallResult(
                'updates.install.parent_create_failed',
                'Update-Arbeitsverzeichnis konnte nicht vorbereitet werden.',
                ['type' => $type, 'name' => $name, 'target_parent' => $targetParentDir]
            );
        }

        $lockHandle = $this->acquireInstallLock();
        $tmpFile = '';
        $stagingRoot = '';
        $backupPath = '';
        $installationCompleted = false;

        try {
            // Temporäre Datei erstellen (eigene .zip-Benennung, kein tempnam-Leak)
            $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . '365cms_upd_' . bin2hex(random_bytes(8)) . '.zip';
            $stagingRoot = $this->createAdjacentTemporaryDirectory($targetParentDir, '365cms_upd_stage_');
            $backupPath = $this->buildAdjacentTemporaryPath($targetParentDir, '365cms_upd_backup_');

            $response = $this->httpClient->get($downloadUrl, [
                'timeout' => 120,
                'connectTimeout' => 10,
                'headers' => ['Accept: application/zip, application/octet-stream'],
                'userAgent' => '365CMS-Updater/1.0',
                'allowedContentTypes' => ['application/zip', 'application/octet-stream', 'application/x-zip-compressed'],
                'maxBytes' => 268435456,
            ]);

            if (!$response['success']) {
                throw new \RuntimeException('Download-Fehler: ' . (string) ($response['error'] ?? 'unbekannt'));
            }

            $responseBody = (string) ($response['body'] ?? '');
            if ($responseBody === '') {
                throw new \RuntimeException('Update-Download war leer.');
            }

            if (file_put_contents($tmpFile, $responseBody, LOCK_EX) === false) {
                throw new \RuntimeException('Temporäre Datei nicht beschreibbar: ' . $tmpFile);
            }

            if (!$this->verifyDownloadIntegrity($tmpFile, $sha256)) {
                if (file_exists($tmpFile)) { unlink($tmpFile); }
                return ['success' => false, 'message' => 'SHA-256-Prüfsumme stimmt nicht überein! Update abgebrochen.', 'sha256_verified' => false];
            }
            $sha256Verified = true;

            // ZIP extrahieren
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
                throw new \RuntimeException('Zielverzeichnis konnte nicht erstellt werden: ' . $targetDir);
            }

            $zip = new \ZipArchive();
            $zipResult = $zip->open($tmpFile);
            if ($zipResult !== true) {
                throw new \RuntimeException('ZIP konnte nicht geöffnet werden (Fehlercode: ' . $zipResult . ')');
            }

            if (!$this->validateZipEntries($zip)) {
                $zip->close();
                throw new \RuntimeException('Update-Paket enthält ungültige oder unsichere Pfade.');
            }

            if (!$zip->extractTo($stagingRoot)) {
                $zip->close();
                throw new \RuntimeException('Update-Paket konnte nicht in das Staging-Verzeichnis entpackt werden.');
            }

            $zip->close();

            $installSource = $this->resolveSafeStagedDirectory($stagingRoot, $this->detectExtractedInstallRoot($stagingRoot));
            if ($installSource === '') {
                throw new \RuntimeException('Update-Paket enthält keine sichere Staging-Struktur.');
            }

            if (!$this->directoryHasContents($installSource)) {
                throw new \RuntimeException('Update-Paket enthält keine installierbaren Dateien.');
            }
            $this->assertInstallSourceSafe($installSource, $type);

            if ($this->canUseAtomicDirectorySwap($type, $targetDir)) {
                $this->swapDirectoryAtomically($installSource, $targetDir, $backupPath);
            } else {
                $this->swapDirectoryContentsWithRollback($installSource, $targetDir, $backupPath, $type);
            }
            $installationCompleted = true;

            // Temporäre Datei löschen
            if (file_exists($tmpFile)) { unlink($tmpFile); } // M-03

            // Update protokollieren
            $this->logUpdate($type, $name, $version);
            if ($type === 'core') {
                $this->clearCacheEntry('core_update_check');
            }

            return ['success' => true, 'message' => "Update {$name} v{$version} erfolgreich installiert.", 'sha256_verified' => $sha256Verified];

        } catch (\Throwable $e) {
            if (file_exists($tmpFile)) { unlink($tmpFile); } // M-03
            $message = str_contains($e->getMessage(), 'automatische Wiederherstellung ist unvollständig')
                ? 'Update konnte nicht vollständig zurückgerollt werden. Das Recovery-Verzeichnis wurde erhalten; bitte die CMS-Logs prüfen.'
                : 'Update konnte nicht installiert werden.';

            return $this->failInstallResult(
                'updates.install.failed',
                $message,
                [
                    'type' => $type,
                    'name' => $name,
                    'version' => $version,
                    'target_dir' => $targetDir,
                    'download_url' => $downloadUrl,
                ],
                $e
            );
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }

            if ($stagingRoot !== '' && is_dir($stagingRoot)) {
                $this->removeDirectory($stagingRoot);
            }

            if ($installationCompleted && $backupPath !== '' && is_dir($backupPath)) {
                $this->removeDirectory($backupPath);
            }

            $this->releaseInstallLock($lockHandle);
        }
    }

    /**
     * Log update
     */
    public function logUpdate(string $type, string $name, string $version): bool
    {
        $userId = isset($_SESSION['user_id']) ? max(0, (int) $_SESSION['user_id']) : 0;
        $userDisplayName = trim((string) ($_SESSION['user_display_name'] ?? ''));
        $username = trim((string) ($_SESSION['username'] ?? ''));
        $userRole = $this->normalizeUpdateHistoryRoleSlug((string) ($_SESSION['user_role'] ?? ''));

        $logEntry = [
            'type' => $type,
            'name' => $name,
            'version' => $version,
            'timestamp' => date('Y-m-d H:i:s'),
            'user' => $userId > 0 ? $userId : 'System',
            'user_id' => $userId > 0 ? $userId : null,
            'user_display_name' => $userDisplayName,
            'username' => $username,
            'user_role' => $userRole,
        ];
        
        $optionName = sprintf(
            'update_log_%s_%06d_%s',
            date('YmdHis'),
            (int) ((microtime(true) - floor(microtime(true))) * 1000000),
            bin2hex(random_bytes(2))
        );
        
        try {
            return $this->db->insert('settings', [
                'option_name' => $optionName,
                'option_value' => json_encode($logEntry),
                'autoload' => 0,
            ]) !== false;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('updates.service')->warning('Update metadata could not be persisted.', [
                'type' => $type,
                'name' => $name,
                'version' => $version,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return array<int, array<string, mixed>>
     */
    private function enrichUpdateHistoryEntries(array $history): array
    {
        if ($history === []) {
            return [];
        }

        $userMap = $this->loadUpdateHistoryUserMap($history);
        $normalized = [];

        foreach ($history as $entry) {
            $normalized[] = $this->normalizeUpdateHistoryEntry($entry, $userMap);
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return array<int, array<string, mixed>>
     */
    private function loadUpdateHistoryUserMap(array $history): array
    {
        $userIds = [];

        foreach ($history as $entry) {
            $userId = $this->normalizeUpdateHistoryUserId($entry['user_id'] ?? ($entry['user'] ?? null));
            if ($userId > 0) {
                $userIds[] = $userId;
            }
        }

        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $statement = $this->db->execute(
            "SELECT id, username, display_name, role FROM {$this->db->getPrefix()}users WHERE id IN ({$placeholders})",
            $userIds
        );

        $userMap = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $userId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($userId <= 0) {
                continue;
            }

            $displayName = trim((string) ($row['display_name'] ?? ''));
            $username = trim((string) ($row['username'] ?? ''));
            $role = $this->normalizeUpdateHistoryRoleSlug((string) ($row['role'] ?? ''));

            $userMap[$userId] = [
                'user_id' => $userId,
                'user_display_name' => $displayName !== '' ? $displayName : ($username !== '' ? $username : 'User #' . $userId),
                'username' => $username,
                'user_role' => $role,
                'user_role_label' => $role !== '' ? $this->humanizeUpdateHistoryRoleSlug($role) : '',
                'user_exists' => true,
            ];
        }

        return $userMap;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, array<string, mixed>> $userMap
     * @return array<string, mixed>
     */
    private function normalizeUpdateHistoryEntry(array $entry, array $userMap): array
    {
        $userId = $this->normalizeUpdateHistoryUserId($entry['user_id'] ?? ($entry['user'] ?? null));

        if ($userId <= 0) {
            $entry['user_id'] = null;
            $entry['user_display_name'] = 'System';
            $entry['user_role'] = '';
            $entry['user_role_label'] = '';
            $entry['user_exists'] = true;
            $entry['user_label'] = 'System';

            return $entry;
        }

        if (isset($userMap[$userId])) {
            $resolved = $userMap[$userId];
            $entry = array_merge($entry, $resolved);
            $entry['user_label'] = $this->buildResolvedUpdateHistoryUserLabel(
                (string) ($resolved['user_display_name'] ?? ''),
                (string) ($resolved['user_role_label'] ?? '')
            );

            return $entry;
        }

        $entry['user_id'] = $userId;
        $entry['user_display_name'] = 'User #' . $userId;
        $entry['user_role'] = $this->normalizeUpdateHistoryRoleSlug((string) ($entry['user_role'] ?? ''));
        $entry['user_role_label'] = $entry['user_role'] !== '' ? $this->humanizeUpdateHistoryRoleSlug((string) $entry['user_role']) : '';
        $entry['user_exists'] = false;
        $entry['user_label'] = 'User #' . $userId;

        return $entry;
    }

    private function normalizeUpdateHistoryUserId(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return max(0, (int) trim($value));
        }

        return 0;
    }

    private function buildResolvedUpdateHistoryUserLabel(string $displayName, string $roleLabel): string
    {
        $displayName = trim($displayName);
        $roleLabel = trim($roleLabel);

        if ($displayName === '') {
            $displayName = 'System';
        }

        if ($roleLabel === '') {
            return $displayName;
        }

        return $displayName . ' · ' . $roleLabel;
    }

    private function normalizeUpdateHistoryRoleSlug(string $role): string
    {
        if (function_exists('cms_normalize_role_slug')) {
            return (string) cms_normalize_role_slug($role);
        }

        $role = strtolower(trim($role));
        if ($role === 'administrator') {
            $role = 'admin';
        }

        $role = preg_replace('/[^a-z0-9_-]+/', '-', $role) ?? '';

        return trim($role, '-_');
    }

    private function humanizeUpdateHistoryRoleSlug(string $role): string
    {
        $role = $this->normalizeUpdateHistoryRoleSlug($role);
        if ($role === '') {
            return '';
        }

        if (function_exists('get_role')) {
            $roleObject = get_role($role);
            $displayName = trim((string) ($roleObject->display_name ?? ''));
            if ($displayName !== '') {
                return $displayName;
            }
        }

        if (function_exists('cms_humanize_role_slug')) {
            return (string) cms_humanize_role_slug($role);
        }

        return ucwords(str_replace(['_', '-', '.'], ' ', $role));
    }
    
    /**
     * Get system requirements (REAL DATA)
     */
    public function getSystemRequirements(): array
    {
        $requiredPhpVersion = \CMS\Version::minimumPhp();
        $mysqlVersion = $this->getMySQLVersion();
        $mysqlVersionDetected = preg_match('/^\d+\.\d+\.\d+/', $mysqlVersion) === 1;
        $extensionChecks = $this->buildExtensionRequirements();
        $extensions = [];

        foreach ($extensionChecks as $key => $extension) {
            $extensions[$key] = !empty($extension['loaded']);
        }

        return [
            'php_version' => [
                'required' => $requiredPhpVersion,
                'current' => PHP_VERSION,
                'met' => version_compare(PHP_VERSION, $requiredPhpVersion, '>='),
                'instruction' => 'PHP auf mindestens ' . $requiredPhpVersion . ' anheben, bevor Updates installiert werden.',
            ],
            'mysql_version' => [
                'required' => '5.7.0',
                'current' => $mysqlVersion,
                'detected' => $mysqlVersionDetected,
                'met' => $mysqlVersionDetected ? version_compare($mysqlVersion, '5.7.0', '>=') : null,
                'instruction' => 'MySQL/MariaDB auf eine unterstützte Version aktualisieren oder die DB-Version im Diagnosepfad prüfen.',
            ],
            'extensions' => $extensions,
            'extension_checks' => $extensionChecks,
            'permissions' => [
                'uploads_writable' => is_writable(ABSPATH . 'uploads'),
                'cache_writable' => is_writable(ABSPATH . 'cache'),
                'logs_writable' => is_writable(ABSPATH . 'logs'),
                'backups_writable' => is_writable(ABSPATH . 'backups'),
                'assets_writable' => is_writable(ABSPATH . 'assets'),
                'paths' => [
                    'runtime' => $this->buildRuntimePathRequirements(),
                    'targets' => $this->buildInstallTargetRequirements(),
                ],
            ],
            'disk_space' => $this->buildDiskSpaceRequirement(ABSPATH),
        ];
    }

    private function buildExtensionRequirements(): array
    {
        $definitions = [
            'pdo' => ['label' => 'PDO', 'required' => true, 'instruction' => 'PHP-Erweiterung PDO aktivieren.'],
            'pdo_mysql' => ['label' => 'PDO MySQL', 'required' => true, 'instruction' => 'PHP-Erweiterung pdo_mysql aktivieren.'],
            'mbstring' => ['label' => 'mbstring', 'required' => true, 'instruction' => 'PHP-Erweiterung mbstring aktivieren.'],
            'json' => ['label' => 'json', 'required' => true, 'instruction' => 'PHP-Erweiterung json aktivieren.'],
            'zip' => ['label' => 'zip', 'required' => true, 'instruction' => 'PHP-Erweiterung zip für Update-Pakete aktivieren.'],
            'curl' => ['label' => 'curl', 'required' => true, 'instruction' => 'PHP-Erweiterung curl für Update-Checks und Downloads aktivieren.'],
            'gd' => ['label' => 'GD', 'required' => false, 'instruction' => 'PHP-Erweiterung GD für Bild- und Medienfunktionen aktivieren.'],
        ];

        $extensions = [];
        foreach ($definitions as $key => $definition) {
            $extensions[$key] = [
                'label' => (string) $definition['label'],
                'required' => !empty($definition['required']),
                'loaded' => extension_loaded($key),
                'instruction' => (string) $definition['instruction'],
            ];
        }

        return $extensions;
    }

    private function buildRuntimePathRequirements(): array
    {
        return [
            'cache' => $this->buildPathRequirement('Cache', ABSPATH . 'cache', 'Schreibrechte für `cache/` vergeben oder das Verzeichnis anlegen.'),
            'backups' => $this->buildPathRequirement('Backups', ABSPATH . 'backups', 'Schreibrechte für `backups/` vergeben oder das Verzeichnis anlegen.'),
            'logs' => $this->buildPathRequirement('Logs', ABSPATH . 'logs', 'Schreibrechte für `logs/` vergeben oder das Verzeichnis anlegen.'),
            'assets' => $this->buildPathRequirement('Assets', ABSPATH . 'assets', 'Schreibrechte für `assets/` vergeben oder das Verzeichnis anlegen.'),
        ];
    }

    private function buildInstallTargetRequirements(): array
    {
        $coreRoot = rtrim((string) ABSPATH, '/\\');
        $coreParent = dirname($coreRoot);

        return [
            'core_root' => $this->buildPathRequirement('CMS-Root', $coreRoot, 'Das CMS-Root muss für Core-Updates schreibbar sein.'),
            'core_parent' => $this->buildPathRequirement('Core-Staging-Parent', $coreParent, 'Das Verzeichnis oberhalb des CMS-Roots muss für Staging/Rollback schreibbar sein.'),
            'plugins_root' => $this->buildPathRequirement('Plugin-Verzeichnis', defined('PLUGIN_PATH') ? (string) PLUGIN_PATH : ABSPATH . 'plugins', 'Das Plugin-Verzeichnis muss für automatische Plugin-Updates schreibbar sein.'),
            'themes_root' => $this->buildPathRequirement('Theme-Verzeichnis', defined('THEME_PATH') ? (string) THEME_PATH : ABSPATH . 'themes', 'Das Theme-Verzeichnis muss für automatische Theme-Updates schreibbar sein.'),
        ];
    }

    private function buildPathRequirement(string $label, string $path, string $instruction): array
    {
        $normalizedPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $exists = $normalizedPath !== '' && (is_dir($normalizedPath) || is_file($normalizedPath));
        $writable = $exists && is_writable($normalizedPath);

        return [
            'label' => $label,
            'path' => $normalizedPath,
            'exists' => $exists,
            'writable' => $writable,
            'met' => $exists && $writable,
            'instruction' => $instruction,
        ];
    }

    private function buildDiskSpaceRequirement(string $path): array
    {
        $freeBytes = @disk_free_space($path);
        $totalBytes = @disk_total_space($path);
        $detected = $freeBytes !== false && $totalBytes !== false;
        $met = $detected ? $freeBytes >= self::UPDATE_MIN_FREE_DISK_BYTES : null;
        $warning = $detected ? ($freeBytes < self::UPDATE_WARN_FREE_DISK_BYTES) : true;

        return [
            'path' => rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR),
            'detected' => $detected,
            'free_bytes' => $detected ? (int) $freeBytes : null,
            'total_bytes' => $detected ? (int) $totalBytes : null,
            'required_free_bytes' => self::UPDATE_MIN_FREE_DISK_BYTES,
            'recommended_free_bytes' => self::UPDATE_WARN_FREE_DISK_BYTES,
            'met' => $met,
            'warning' => $warning,
            'instruction' => 'Freien Speicher des CMS-Volumes erhöhen; für Staging, Download und Rollback sollte mindestens 1 GB frei sein.',
        ];
    }
    
    /**
     * Get MySQL version (REAL DATA)
     */
    private function getMySQLVersion(): string
    {
        try {
            $stmt = $this->db->prepare("SELECT VERSION() as version");
            
            if (!$stmt) {
                return 'Unknown';
            }
            
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            
            if ($result && $result->version) {
                // Extract version number (remove MariaDB suffix etc.)
                preg_match('/^\d+\.\d+\.\d+/', $result->version, $matches);
                return $matches[0] ?? $result->version;
            }
            
            return 'Unknown';
        } catch (\Exception $e) {
            Logger::instance()->withChannel('updates.service')->warning('MySQL version could not be determined for update diagnostics.', [
                'exception' => $e,
            ]);
            return 'Unknown';
        }
    }
    
    /**
     * Get cached data
     */
    private function getCache(string $key): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT cache_value 
                FROM {$this->db->getPrefix()}cache
                WHERE cache_key = ? 
                AND (expires_at IS NULL OR expires_at > NOW())
            ");
            
            if (!$stmt) {
                return null;
            }
            
            $stmt->execute([$key]);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            
            if ($result) {
                $data = Json::decode($result->cache_value ?? null, true, null);
                return is_array($data) ? $data : null;
            }
            
            return null;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('updates.service')->warning('Update cache entry could not be loaded.', [
                'cache_key' => $key,
                'exception' => $e,
            ]);
            return null;
        }
    }
    
    /**
     * Set cache data
     */
    private function setCache(string $key, array $data): bool
    {
        try {
            $expiresAt = date('Y-m-d H:i:s', time() + self::CACHE_DURATION);
            
            // Delete existing cache
            $stmt = $this->db->prepare("DELETE FROM {$this->db->getPrefix()}cache WHERE cache_key = ?");
            if ($stmt) {
                $stmt->execute([$key]);
            }
            
            // Insert new cache
            return $this->db->insert('cache', [
                'cache_key' => $key,
                'cache_value' => json_encode($data),
                'expires_at' => $expiresAt,
            ]) !== false;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('updates.service')->warning('Update cache entry could not be stored.', [
                'cache_key' => $key,
                'exception' => $e,
            ]);
            return false;
        }
    }

    private function clearCacheEntry(string $key): void
    {
        try {
            $this->db->execute(
                "DELETE FROM {$this->db->getPrefix()}cache WHERE cache_key = ?",
                [$key]
            );
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('updates.service')->warning('Update cache entry could not be invalidated.', [
                'cache_key' => $key,
                'exception' => $e,
            ]);
        }
    }

    /** @return resource */
    private function acquireInstallLock()
    {
        $lockDirectory = rtrim((string) ABSPATH, '/\\') . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0755, true) && !is_dir($lockDirectory)) {
            throw new \RuntimeException('Update-Sperrverzeichnis konnte nicht vorbereitet werden.');
        }

        $handle = fopen($lockDirectory . DIRECTORY_SEPARATOR . '.update-install.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('Update-Sperrdatei konnte nicht geöffnet werden.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Ein anderes Update wird bereits installiert.');
        }

        return $handle;
    }

    /** @param resource $handle */
    private function releaseInstallLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function assertInstallSourceSafe(string $sourceDir, string $type): void
    {
        if ($type !== 'core') {
            return;
        }

        $runtimeEntries = ['backups', 'cache', 'config', 'config.php', 'logs', 'uploads'];
        foreach ($this->listDirectoryEntries($sourceDir) as $entry) {
            if (in_array($entry, $runtimeEntries, true)) {
                throw new \RuntimeException('Core-Update-Paket enthält einen installationsspezifischen Laufzeitpfad: ' . $entry);
            }
        }
    }

    private function validateZipEntries(\ZipArchive $zip): bool
    {
        $hasEntries = false;
        $totalUncompressedSize = 0;

        if ($zip->numFiles <= 0 || $zip->numFiles > self::UPDATE_MAX_ARCHIVE_ENTRIES) {
            return false;
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);
            if (!is_string($entryName) || $entryName === '') {
                return false;
            }

            $normalized = str_replace('\\', '/', $entryName);
            $normalized = ltrim($normalized, '/');
            $parts = array_values(array_filter(explode('/', $normalized), static fn (string $part): bool => $part !== ''));

            if ($normalized === ''
                || $normalized !== $entryName
                || str_contains($normalized, '../')
                || str_contains($normalized, '..\\')
                || in_array('..', $parts, true)
                || in_array('.', $parts, true)
                || preg_match('~^[A-Za-z]:/~', $normalized) === 1
                || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
            ) {
                return false;
            }

            $stat = $zip->statIndex($index);
            if (is_array($stat)) {
                $size = max(0, (int) ($stat['size'] ?? 0));
                if ($size > self::UPDATE_MAX_ARCHIVE_FILE_BYTES) {
                    return false;
                }

                $totalUncompressedSize += $size;
                if ($totalUncompressedSize > self::UPDATE_MAX_ARCHIVE_UNCOMPRESSED_BYTES) {
                    return false;
                }
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) && $opsys === \ZipArchive::OPSYS_UNIX) {
                $mode = ($attributes >> 16) & 0xF000;
                if ($mode === 0xA000) {
                    return false;
                }
            }

            $hasEntries = true;
        }

        return $hasEntries;
    }

    private function createAdjacentTemporaryDirectory(string $parentDir, string $prefix): string
    {
        $path = $this->buildAdjacentTemporaryPath($parentDir, $prefix);

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException('Temporäres Update-Verzeichnis konnte nicht erstellt werden: ' . $path);
        }

        return $path;
    }

    private function buildAdjacentTemporaryPath(string $parentDir, string $prefix): string
    {
        $parentDir = rtrim($parentDir, '/\\');

        do {
            $path = $parentDir . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        } while (file_exists($path));

        return $path;
    }

    private function detectExtractedInstallRoot(string $stagingRoot): string
    {
        $entries = $this->listDirectoryEntries($stagingRoot);

        if (count($entries) === 1) {
            $candidate = $stagingRoot . DIRECTORY_SEPARATOR . $entries[0];
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $stagingRoot;
    }

    private function resolveSafeStagedDirectory(string $stagingRoot, string $candidate): string
    {
        $realStagingRoot = realpath($stagingRoot);
        $realCandidate = realpath($candidate);

        if ($realStagingRoot === false || $realCandidate === false || !is_dir($realCandidate) || is_link($realCandidate)) {
            return '';
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $realStagingRoot), '/') . '/';
        $normalizedCandidate = rtrim(str_replace('\\', '/', $realCandidate), '/') . '/';
        if (!str_starts_with($normalizedCandidate, $normalizedRoot)) {
            return '';
        }

        return $this->isDirectoryTreeInsideRootAndLinkFree($realCandidate, $realStagingRoot) ? $realCandidate : '';
    }

    private function isDirectoryTreeInsideRootAndLinkFree(string $directory, string $root): bool
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\UnexpectedValueException) {
            return false;
        }

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                return false;
            }

            if ($item->isLink()) {
                return false;
            }

            $realPath = realpath($item->getPathname());
            if ($realPath === false) {
                return false;
            }

            $normalizedPath = str_replace('\\', '/', $realPath);
            if (!str_starts_with($normalizedPath, $normalizedRoot)) {
                return false;
            }
        }

        return true;
    }

    private function directoryHasContents(string $directory): bool
    {
        return $this->listDirectoryEntries($directory) !== [];
    }

    /**
     * @return string[]
     */
    private function listDirectoryEntries(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return [];
        }

        return array_values(array_filter($entries, static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
    }

    private function canUseAtomicDirectorySwap(string $type, string $targetDir): bool
    {
        if ($type === 'core') {
            return false;
        }

        $absoluteRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ABSPATH), DIRECTORY_SEPARATOR);
        $normalizedTarget = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetDir), DIRECTORY_SEPARATOR);

        return $normalizedTarget !== '' && $normalizedTarget !== $absoluteRoot;
    }

    private function swapDirectoryAtomically(string $sourceDir, string $targetDir, string $backupPath): void
    {
        $targetExists = is_dir($targetDir);
        $backupCreated = false;

        if ($targetExists) {
            if (!rename($targetDir, $backupPath)) {
                throw new \RuntimeException('Bestehendes Zielverzeichnis konnte nicht in das Backup verschoben werden: ' . $targetDir);
            }

            $backupCreated = true;
        }

        if (!rename($sourceDir, $targetDir)) {
            if ($backupCreated && !is_dir($targetDir) && is_dir($backupPath)) {
                if (!rename($backupPath, $targetDir)) {
                    throw new \RuntimeException('Staging-Verzeichnis konnte nicht installiert werden und das Backup konnte nicht automatisch wiederhergestellt werden. Das Recovery-Verzeichnis bleibt erhalten: ' . $backupPath);
                }
            }

            throw new \RuntimeException('Staging-Verzeichnis konnte nicht atomar in das Ziel verschoben werden: ' . $targetDir);
        }

        if ($backupCreated && is_dir($backupPath)) {
            $this->removeDirectory($backupPath);
        }
    }

    private function swapDirectoryContentsWithRollback(string $sourceDir, string $targetDir, string $backupPath, string $type = ''): void
    {
        if (!is_dir($sourceDir)) {
            throw new \RuntimeException('Staging-Quelle für das Update fehlt: ' . $sourceDir);
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            throw new \RuntimeException('Zielverzeichnis konnte nicht vorbereitet werden: ' . $targetDir);
        }

        if (!mkdir($backupPath, 0755, true) && !is_dir($backupPath)) {
            throw new \RuntimeException('Rollback-Verzeichnis konnte nicht erstellt werden: ' . $backupPath);
        }

        $backedUpEntries = [];
        $movedEntries = [];
        $preservedCoreEntries = $type === 'core'
            ? ['backups', 'cache', 'config', 'config.php', 'logs', 'uploads']
            : [];

        try {
            foreach ($this->listDirectoryEntries($targetDir) as $entry) {
                if (in_array($entry, $preservedCoreEntries, true)) {
                    continue;
                }

                $from = $targetDir . DIRECTORY_SEPARATOR . $entry;
                $to = $backupPath . DIRECTORY_SEPARATOR . $entry;

                if (!rename($from, $to)) {
                    throw new \RuntimeException('Bestehender Zielinhalt konnte nicht ins Rollback-Verzeichnis verschoben werden: ' . $entry);
                }

                $backedUpEntries[] = $entry;
            }

            foreach ($this->listDirectoryEntries($sourceDir) as $entry) {
                if (in_array($entry, $preservedCoreEntries, true)) {
                    throw new \RuntimeException('Core-Update-Paket enthält einen installationsspezifischen Laufzeitpfad: ' . $entry);
                }

                $from = $sourceDir . DIRECTORY_SEPARATOR . $entry;
                $to = $targetDir . DIRECTORY_SEPARATOR . $entry;

                if (!rename($from, $to)) {
                    throw new \RuntimeException('Staging-Inhalt konnte nicht in das Ziel verschoben werden: ' . $entry);
                }

                $movedEntries[] = $entry;
            }
        } catch (\Throwable $e) {
            $rollbackFailures = [];
            foreach (array_reverse($movedEntries) as $entry) {
                $current = $targetDir . DIRECTORY_SEPARATOR . $entry;
                $rollback = $sourceDir . DIRECTORY_SEPARATOR . $entry;

                if (file_exists($current)) {
                    if (!rename($current, $rollback)) {
                        $rollbackFailures[] = 'Neuer Eintrag konnte nicht ins Staging zurückverschoben werden: ' . $entry;
                    }
                }
            }

            foreach (array_reverse($backedUpEntries) as $entry) {
                $current = $backupPath . DIRECTORY_SEPARATOR . $entry;
                $rollback = $targetDir . DIRECTORY_SEPARATOR . $entry;

                if (file_exists($current)) {
                    if (!rename($current, $rollback)) {
                        $rollbackFailures[] = 'Backup-Eintrag konnte nicht wiederhergestellt werden: ' . $entry;
                    }
                }
            }

            if ($rollbackFailures !== []) {
                throw new \RuntimeException(
                    'Update fehlgeschlagen und die automatische Wiederherstellung ist unvollständig. Das Recovery-Verzeichnis bleibt erhalten: '
                    . $backupPath . '. Details: ' . implode('; ', $rollbackFailures),
                    0,
                    $e
                );
            }

            throw $e;
        }

        if (is_dir($backupPath)) {
            $this->removeDirectory($backupPath);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
                continue;
            }

            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    private function isAllowedInstallTarget(string $targetDir, string $type): bool
    {
        $targetDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetDir), DIRECTORY_SEPARATOR);
        if ($targetDir === '') {
            return false;
        }

        if ($type === 'core') {
            $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ABSPATH), DIRECTORY_SEPARATOR);

            return $targetDir === $root && !is_link($targetDir);
        }

        $root = match ($type) {
            'plugin' => defined('PLUGIN_PATH') ? (string) PLUGIN_PATH : (string) ABSPATH . 'plugins' . DIRECTORY_SEPARATOR,
            'theme' => defined('THEME_PATH') ? (string) THEME_PATH : (string) ABSPATH . 'themes' . DIRECTORY_SEPARATOR,
            default => '',
        };

        if ($root === '') {
            return false;
        }

        $normalizedRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        if ($normalizedRoot === '') {
            return false;
        }

        if ($targetDir === $normalizedRoot || !str_starts_with($targetDir, $normalizedRoot . DIRECTORY_SEPARATOR)) {
            return false;
        }

        $relativeTarget = substr($targetDir, strlen($normalizedRoot) + 1);
        if ($relativeTarget === ''
            || str_contains($relativeTarget, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $relativeTarget) !== 1
        ) {
            return false;
        }

        if (is_link($targetDir)) {
            return false;
        }

        $parentDir = dirname($targetDir);
        $realParentDir = realpath($parentDir);
        $realRoot = realpath($normalizedRoot);

        return $realParentDir !== false && $realRoot !== false && $realParentDir === $realRoot && !is_link($parentDir);
    }

    private function failInstallResult(string $action, string $message, array $context = [], ?\Throwable $exception = null): array
    {
        if ($exception !== null) {
            $context['exception_type'] = get_class($exception);
            $context['exception_message'] = $this->sanitizeInstallDiagnostic($exception->getMessage());
        }

        $context = $this->sanitizeInstallContext($context);

        \CMS\Logger::instance()->withChannel('updates.service')->error($message, $context);
        \CMS\AuditLogger::instance()->log(
            \CMS\AuditLogger::CAT_SYSTEM,
            $action,
            $message,
            'updates',
            null,
            $context,
            'error'
        );

        return ['success' => false, 'message' => $message . ' Bitte Logs prüfen.', 'sha256_verified' => false];
    }

    private function isAllowedSensitiveRemoteUrl(string $url): bool
    {
        return $this->normalizeSensitiveRemoteUrl($url) !== '';
    }

    private function isAllowedSensitiveRemoteHost(string $host): bool
    {
        if (in_array($host, self::ALLOWED_UPDATE_HOSTS, true)) {
            return true;
        }

        return str_ends_with($host, '.githubusercontent.com');
    }

    private function sanitizeInstallDiagnostic(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        $value = preg_replace('/(password|passwd|pwd|token|secret|apikey|api_key|access_token|refresh_token)=([^\s&]+)/i', '$1=[redacted]', $value) ?? $value;

        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, 500, 'UTF-8');
        }

        return substr($value, 0, 500);
    }

    private function sanitizeInstallContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeInstallContext($value);
                continue;
            }

            if (str_contains($normalizedKey, 'password')
                || str_contains($normalizedKey, 'token')
                || str_contains($normalizedKey, 'secret')
                || str_contains($normalizedKey, 'api_key')
            ) {
                $sanitized[$key] = '[redacted]';
                continue;
            }

            $stringValue = $this->sanitizeInstallDiagnostic((string) $value);
            $sanitized[$key] = str_contains($normalizedKey, 'url') ? $this->sanitizeUrlForInstallLog($stringValue) : $stringValue;
        }

        return $sanitized;
    }

    private function sanitizeUrlForInstallLog(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        if ($scheme === '' || $host === '') {
            return $url;
        }

        $safeUrl = $scheme . '://' . $host . (string) ($parts['path'] ?? '');
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $queryParams);
            foreach ($queryParams as $queryKey => $queryValue) {
                $queryKeyString = strtolower((string) $queryKey);
                if (str_contains($queryKeyString, 'token')
                    || str_contains($queryKeyString, 'secret')
                    || str_contains($queryKeyString, 'signature')
                    || str_contains($queryKeyString, 'key')
                ) {
                    $queryParams[$queryKey] = '[redacted]';
                }
            }

            $safeQuery = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
            if ($safeQuery !== '') {
                $safeUrl .= '?' . $safeQuery;
            }
        }

        return $safeUrl;
    }
}

