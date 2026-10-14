<?php
declare(strict_types=1);

/**
 * Theme Marketplace Module – Remote-Theme-Katalog
 *
 * Liest index.json aus dem Theme-Repository um verfügbare Themes anzuzeigen
 * und abzugleichen welche bereits lokal installiert sind.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Http\Client as HttpClient;
use CMS\Database;
use CMS\Services\ErrorReportService;
use CMS\Services\MarketplaceEndpoints;
use CMS\Services\UpdateService;
use CMS\ThemeManager;

class ThemeMarketplaceModule
{
    private const MAX_CATALOG_BYTES = 1048576;
    private const MAX_MANIFEST_BYTES = 524288;
    private const CATALOG_CACHE_TTL = 900;
    private const CATALOG_CACHE_OPTION = 'theme_marketplace_catalog_cache';
    private const CATALOG_CACHE_META_OPTION = 'theme_marketplace_catalog_cache_meta';
    private const MAX_ARCHIVE_ENTRIES = 2000;
    private const MAX_ARCHIVE_UNCOMPRESSED_BYTES = 52428800;
    private const MAX_CATALOG_STRING_LENGTH = 500;
    private const MAX_PACKAGE_SIZE_BYTES = 104857600;
    private const ALLOWED_ARCHIVE_EXTENSIONS = ['zip'];
    private const MANIFEST_ALLOWED_KEYS = [
        'slug',
        'name',
        'description',
        'version',
        'author',
        'manifest',
        'update_url',
        'download_url',
        'package_url',
        'archive_url',
        'purchase_url',
        'buy_url',
        'order_url',
        'is_paid',
        'price_amount',
        'price_currency',
        'price',
        'sha256',
        'checksum_sha256',
        'package_size',
        'homepage_url',
        'docs_url',
        'changelog_url',
        'screenshot',
        'requires_cms',
        'min_cms_version',
        'requires_php',
        'min_php',
        'tested_up_to',
        'released',
        'notes',
        'type',
    ];

    private const ALLOWED_MARKETPLACE_HOSTS = [
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

    private const DEFAULT_MARKETPLACE_URL = MarketplaceEndpoints::THEME_INDEX_URL;

    private readonly Database $db;
    private readonly string $prefix;
    private ThemeManager $themeManager;
    private HttpClient $httpClient;
    private UpdateService $updateService;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $catalogCache = null;
    /** @var array<string, mixed> */
    private array $catalogSource = [
        'type' => 'none',
        'status' => 'warning',
        'message' => 'Noch keine Marketplace-Quelle ausgewertet.',
    ];

    public function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->themeManager = ThemeManager::instance();
        $this->httpClient = HttpClient::getInstance();
        $this->updateService = UpdateService::getInstance();
    }

    /**
     * Marketplace-Daten laden
     */
    public function getData(): array
    {
        $installed    = $this->themeManager->getAvailableThemes();
        $installedMap = $this->getInstalledThemeMap($installed);
        $catalog      = $this->getCatalog();
        $activeTheme  = $this->normalizeThemeKey($this->themeManager->getActiveThemeSlug());

        // Installationsstatus anreichern
        foreach ($catalog as &$theme) {
            $slugKey = $this->normalizeThemeKey((string) ($theme['slug'] ?? ''));
            $localTheme = $installedMap[$slugKey] ?? null;
            $downloadUrl = trim((string) ($theme['download_url'] ?? ''));
            $integrityHash = $this->resolveIntegrityHash($theme);
            $packageSizeBytes = $this->normalizePackageSize($theme['package_size'] ?? null);

            $theme['installed'] = $localTheme !== null;
            $theme['active']    = $slugKey !== '' && $slugKey === $activeTheme;
            $theme['local_folder'] = (string) ($localTheme['folder'] ?? '');

            // Versions-Vergleich
            if ($localTheme !== null && !empty($localTheme['version']) && !empty($theme['version'])) {
                $theme['updateAvailable'] = version_compare((string) $theme['version'], (string) $localTheme['version'], '>');
            } else {
                $theme['updateAvailable'] = false;
            }

            $theme['package_size_bytes'] = $packageSizeBytes;
            $theme['package_size_label'] = $packageSizeBytes > 0 ? $this->formatBytesLabel($packageSizeBytes) : '';
            $theme['download_host'] = strtolower((string) parse_url($downloadUrl, PHP_URL_HOST));
            $theme['download_host_allowed'] = $downloadUrl !== '' && $this->isAllowedMarketplaceUrl($downloadUrl);
            $theme['download_extension_allowed'] = $downloadUrl !== '' && $this->hasAllowedArchiveExtension($downloadUrl);
            $theme['package_size_allowed'] = $this->isAllowedPackageSize($theme);
            $theme['integrity_hash'] = $integrityHash;
            $theme['integrity_hash_short'] = $integrityHash !== '' ? substr($integrityHash, 0, 12) . '…' : '';
            $theme['integrity_hash_present'] = $integrityHash !== '';
            $theme['compatibility_reason'] = $this->getCompatibilityFailureReason($theme);
            $theme['compatible'] = $theme['compatibility_reason'] === '';
            $theme['install_supported'] = $this->canAutoInstall($theme);
            $theme['manual_install_only'] = !$theme['install_supported'];
            $theme['install_reason'] = $theme['install_supported']
                ? 'Paket und SHA-256 vorhanden.'
                : $this->getManualInstallReason($theme);
        }
        unset($theme);

        return [
            'catalog'   => $catalog,
            'installed' => $installed,
            'total'     => count($catalog),
            'stats'     => $this->buildStats($catalog),
            'source'    => $this->catalogSource,
            'endpoints' => MarketplaceEndpoints::all(),
            'constraints' => [
                'catalog_cache_ttl' => self::CATALOG_CACHE_TTL,
                'catalog_max_bytes' => self::MAX_CATALOG_BYTES,
                'catalog_max_bytes_label' => $this->formatBytesLabel(self::MAX_CATALOG_BYTES),
                'manifest_max_bytes' => self::MAX_MANIFEST_BYTES,
                'manifest_max_bytes_label' => $this->formatBytesLabel(self::MAX_MANIFEST_BYTES),
                'package_size_limit_bytes' => self::MAX_PACKAGE_SIZE_BYTES,
                'package_size_limit_label' => $this->formatBytesLabel(self::MAX_PACKAGE_SIZE_BYTES),
                'archive_entries_limit' => self::MAX_ARCHIVE_ENTRIES,
                'archive_uncompressed_limit_bytes' => self::MAX_ARCHIVE_UNCOMPRESSED_BYTES,
                'archive_uncompressed_limit_label' => $this->formatBytesLabel(self::MAX_ARCHIVE_UNCOMPRESSED_BYTES),
                'allowed_marketplace_hosts' => self::ALLOWED_MARKETPLACE_HOSTS,
                'allowed_archive_extensions' => self::ALLOWED_ARCHIVE_EXTENSIONS,
                'allowed_archive_extensions_label' => implode(', ', self::ALLOWED_ARCHIVE_EXTENSIONS),
            ],
            'filters'   => [
                'statuses' => $this->buildStatusFilters(),
            ],
        ];
    }

    public function hasCatalogThemeSlug(string $slug): bool
    {
        return $this->findCatalogTheme($this->normalizeThemeKey($slug)) !== null;
    }

    /**
     * Theme aus dem Marketplace installieren
     */
    public function installTheme(string $slug): array
    {
        $slug = $this->normalizeThemeKey($slug);
        if ($slug === '') {
            return $this->buildInstallFailureResult('Kein Theme angegeben.', 'theme_marketplace_invalid_slug');
        }

        $theme = $this->findCatalogTheme($slug);
        if ($theme === null) {
            return $this->buildInstallFailureResult('Theme nicht im Marketplace gefunden.', 'theme_marketplace_missing_catalog_entry', [
                'Theme-Slug: ' . $slug,
            ], [
                'slug' => $slug,
            ]);
        }

        $installedThemeMap = $this->getInstalledThemeMap($this->themeManager->getAvailableThemes());
        if (isset($installedThemeMap[$slug])) {
            return $this->buildInstallFailureResult('Theme ist bereits installiert.', 'theme_marketplace_already_installed', [
                'Theme-Slug: ' . $slug,
            ], [
                'slug' => $slug,
            ]);
        }

        $downloadUrl = trim((string) ($theme['download_url'] ?? ''));
        $integrityHash = $this->resolveIntegrityHash($theme);

        if (!$this->canAutoInstall($theme)) {
            return $this->buildInstallFailureResult(
                $this->getManualInstallReason($theme),
                'theme_marketplace_manual_install_required',
                $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $theme),
                $this->buildInstallErrorData($slug, $downloadUrl, $integrityHash, $theme)
            );
        }

        if (!$this->isAllowedMarketplaceUrl($downloadUrl)) {
            return $this->buildInstallFailureResult(
                'Download-URL liegt außerhalb der erlaubten Marketplace-Hosts.',
                'theme_marketplace_disallowed_download_host',
                $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $theme),
                $this->buildInstallErrorData($slug, $downloadUrl, $integrityHash, $theme)
            );
        }

        if (!$this->isAllowedPackageSize($theme)) {
            return $this->buildInstallFailureResult(
                'Theme-Paket überschreitet das Auto-Install-Limit von ' . $this->formatBytesLabel(self::MAX_PACKAGE_SIZE_BYTES) . '.',
                'theme_marketplace_package_too_large',
                $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $theme),
                $this->buildInstallErrorData($slug, $downloadUrl, $integrityHash, $theme)
            );
        }

        if (!$this->hasAllowedArchiveExtension($downloadUrl)) {
            return $this->buildInstallFailureResult(
                'Download-Archiv muss eine erlaubte Endung (' . implode(', ', self::ALLOWED_ARCHIVE_EXTENSIONS) . ') besitzen.',
                'theme_marketplace_disallowed_archive_extension',
                $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $theme),
                $this->buildInstallErrorData($slug, $downloadUrl, $integrityHash, $theme)
            );
        }

        $targetFolder = $this->determineThemeTargetFolder($theme, $slug);
        $targetDir = rtrim(THEME_PATH, '/\\') . DIRECTORY_SEPARATOR . $targetFolder . DIRECTORY_SEPARATOR;
        $targetParentDir = dirname(rtrim($targetDir, '/\\'));
        $normalizedThemeBase = rtrim(str_replace('\\', '/', (string) THEME_PATH), '/') . '/';
        $normalizedTargetDir = rtrim(str_replace('\\', '/', $targetDir), '/') . '/';

        if (!str_starts_with($normalizedTargetDir, $normalizedThemeBase)) {
            return $this->buildInstallFailureResult(
                'Theme-Zielverzeichnis ist ungültig.',
                'theme_marketplace_invalid_target_dir',
                ['Theme-Slug: ' . $slug],
                ['slug' => $slug, 'target_dir' => $normalizedTargetDir]
            );
        }

        $installLockPath = $this->acquireThemeInstallLock($targetParentDir, $targetFolder);
        if ($installLockPath === '') {
            return $this->buildInstallFailureResult(
                'Für dieses Theme läuft bereits eine Installation oder Bereinigung. Bitte kurz warten und anschließend erneut versuchen.',
                'theme_marketplace_install_locked',
                ['Theme-Slug: ' . $slug],
                ['slug' => $slug, 'target_dir' => $normalizedTargetDir]
            );
        }

        try {
            clearstatcache(true, rtrim($targetDir, '/\\'));

            if (is_dir(rtrim($targetDir, '/\\'))) {
                return $this->buildInstallFailureResult(
                    'Theme ist bereits installiert.',
                    'theme_marketplace_target_exists',
                    ['Theme-Slug: ' . $slug],
                    ['slug' => $slug, 'target_dir' => $normalizedTargetDir]
                );
            }

            $result = $this->updateService->downloadAndInstallUpdate(
                $downloadUrl,
                $integrityHash,
                $targetDir,
                'theme',
                (string) ($theme['name'] ?? $slug),
                (string) ($theme['version'] ?? 'Marketplace')
            );

            if (($result['success'] ?? false) !== true) {
                return $this->buildInstallFailureResult(
                    (string) ($result['message'] ?? 'Theme konnte nicht installiert werden.'),
                    'theme_marketplace_install_failed',
                    $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $theme),
                    array_merge(
                        $this->buildInstallErrorData($slug, $downloadUrl, $integrityHash, $theme),
                        ['installer_result' => is_array($result) ? array_intersect_key($result, array_flip(['message', 'status', 'code', 'sha256_verified'])) : []]
                    )
                );
            }

            $finalizationError = $this->finalizeInstalledThemePackage($targetDir, $targetFolder);
            if ($finalizationError !== '') {
                return $this->buildInstallFailureResult(
                    $finalizationError,
                    'theme_marketplace_finalization_failed',
                    $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $theme),
                    $this->buildInstallErrorData($slug, $downloadUrl, $integrityHash, $theme)
                );
            }

            return [
                'success' => true,
                'message' => 'Theme „' . ((string) ($theme['name'] ?? $slug)) . '“ wurde installiert. Du kannst es jetzt in der Theme-Verwaltung aktivieren.',
                'details' => array_merge(
                    $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $theme),
                    [
                        'Zielpfad: ' . $normalizedTargetDir,
                        'SHA-256 verifiziert: ' . (!empty($result['sha256_verified']) ? 'ja' : 'nein'),
                    ]
                ),
            ];
        } finally {
            $this->releaseThemeInstallLock($installLockPath);
        }
    }

    /**
     * Theme-Katalog aus index.json laden
     */
    private function getCatalog(): array
    {
        if ($this->catalogCache !== null) {
            return $this->catalogCache;
        }

        $marketplaceUrl = $this->getMarketplaceUrl();
        $cachedCatalog = $this->loadCachedCatalog($marketplaceUrl);
        if ($cachedCatalog !== null) {
            $this->catalogSource = [
                'type' => 'cache',
                'status' => 'info',
                'message' => 'Theme-Katalog aus lokalem Cache geladen.',
                'url' => $marketplaceUrl,
                'details' => array_values(array_filter([
                    !empty($cachedCatalog['cached_at_label']) ? 'Stand: ' . $cachedCatalog['cached_at_label'] : '',
                    isset($cachedCatalog['age_seconds']) ? 'Cache-Alter: ' . (int) $cachedCatalog['age_seconds'] . ' Sekunden' : '',
                ])),
            ];

            return $this->catalogCache = $cachedCatalog['catalog'];
        }

        $remoteCatalog = $this->loadRemoteCatalog($marketplaceUrl);
        $remoteThemes = is_array($remoteCatalog['catalog'] ?? null) ? $remoteCatalog['catalog'] : [];
        if ($remoteThemes !== []) {
            $this->persistCatalogCache($marketplaceUrl, $remoteThemes);
            $this->catalogSource = [
                'type' => 'remote',
                'status' => 'success',
                'message' => 'Theme-Katalog erfolgreich aus der Remote-Quelle geladen.',
                'url' => $marketplaceUrl,
                'details' => array_values(array_filter([
                    !empty($remoteCatalog['details']) && is_array($remoteCatalog['details']) ? implode(' · ', array_map('strval', $remoteCatalog['details'])) : '',
                ])),
            ];

            return $this->catalogCache = $remoteThemes;
        }

        $staleCatalog = $this->loadCachedCatalog($marketplaceUrl, true);
        if ($staleCatalog !== null) {
            $this->catalogSource = [
                'type' => 'cache',
                'status' => 'warning',
                'message' => 'Remote-Katalog derzeit nicht verfügbar; letzter bekannter Cache wird als Fallback genutzt.',
                'url' => $marketplaceUrl,
                'details' => array_values(array_filter([
                    !empty($staleCatalog['cached_at_label']) ? 'Cache-Stand: ' . $staleCatalog['cached_at_label'] : '',
                    isset($staleCatalog['age_seconds']) ? 'Cache-Alter: ' . (int) $staleCatalog['age_seconds'] . ' Sekunden' : '',
                    !empty($remoteCatalog['error']) ? 'Remote-Fehler: ' . (string) $remoteCatalog['error'] : '',
                    !empty($remoteCatalog['details']) && is_array($remoteCatalog['details']) ? implode(' · ', array_map('strval', $remoteCatalog['details'])) : '',
                ])),
            ];

            return $this->catalogCache = $staleCatalog['catalog'];
        }

        $localCatalog = $this->loadLocalCatalog();
        $localThemes = is_array($localCatalog['catalog'] ?? null) ? $localCatalog['catalog'] : [];
        if ($localThemes !== []) {
            $this->catalogSource = [
                'type' => 'local',
                'status' => $marketplaceUrl !== '' ? 'warning' : 'info',
                'message' => $marketplaceUrl !== ''
                    ? 'Remote-Katalog derzeit nicht verfügbar; lokaler Theme-Index wird als Fallback genutzt.'
                    : 'Lokaler Theme-Index wird verwendet.',
                'url' => (string) ($localCatalog['index_path'] ?? 'index.json'),
                'details' => array_values(array_filter([
                    !empty($remoteCatalog['error']) ? 'Remote-Fehler: ' . (string) $remoteCatalog['error'] : '',
                    !empty($remoteCatalog['details']) && is_array($remoteCatalog['details']) ? implode(' · ', array_map('strval', $remoteCatalog['details'])) : '',
                ])),
            ];

            return $this->catalogCache = $localThemes;
        }

        $this->catalogSource = [
            'type' => 'none',
            'status' => 'warning',
            'message' => 'Es konnte weder ein Remote-Katalog noch ein lokaler Theme-Index geladen werden.',
            'url' => $marketplaceUrl,
            'details' => array_values(array_filter([
                !empty($remoteCatalog['error']) ? 'Remote-Fehler: ' . (string) $remoteCatalog['error'] : '',
                !empty($remoteCatalog['details']) && is_array($remoteCatalog['details']) ? implode(' · ', array_map('strval', $remoteCatalog['details'])) : '',
            ])),
        ];

        return $this->catalogCache = [];
    }

    /**
     * Themes-Index-URL aus den zentralen Marketplace-Endpunkten
     */
    private function getMarketplaceUrl(): string
    {
        $value = $this->normalizeMarketplaceUrl(MarketplaceEndpoints::themeIndexUrl());

        return $value !== '' ? $value : $this->normalizeMarketplaceUrl(self::DEFAULT_MARKETPLACE_URL);
    }

    /**
     * @return array{catalog:array<int, array<string, mixed>>, age_seconds:int, cached_at_label:string}|null
     */
    private function loadCachedCatalog(string $marketplaceUrl, bool $allowExpired = false): ?array
    {
        $payload = $this->db->get_var(
            "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
            [self::CATALOG_CACHE_OPTION]
        );
        $metaPayload = $this->db->get_var(
            "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
            [self::CATALOG_CACHE_META_OPTION]
        );

        $cacheData = \CMS\Json::decodeArray((string) $payload, []);
        $cacheMeta = \CMS\Json::decodeArray((string) $metaPayload, []);
        if (!is_array($cacheData) || !is_array($cacheMeta)) {
            return null;
        }

        $cachedUrl = $this->normalizeMarketplaceUrl((string) ($cacheMeta['marketplace_url'] ?? ''));
        $normalizedMarketplaceUrl = $this->normalizeMarketplaceUrl($marketplaceUrl);
        if ($cachedUrl === '' || $normalizedMarketplaceUrl === '' || $cachedUrl !== $normalizedMarketplaceUrl) {
            return null;
        }

        $cachedAt = (int) ($cacheMeta['cached_at'] ?? 0);
        if ($cachedAt <= 0) {
            return null;
        }

        $ageSeconds = max(0, time() - $cachedAt);
        if (!$allowExpired && $ageSeconds > self::CATALOG_CACHE_TTL) {
            return null;
        }

        $catalog = is_array($cacheData['catalog'] ?? null) ? $cacheData['catalog'] : [];

        return [
            'catalog' => $catalog,
            'age_seconds' => $ageSeconds,
            'cached_at_label' => date('d.m.Y H:i:s', $cachedAt),
        ];
    }

    /** @param array<int, array<string, mixed>> $catalog */
    private function persistCatalogCache(string $marketplaceUrl, array $catalog): void
    {
        $payload = json_encode(['catalog' => $catalog], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $meta = json_encode([
            'marketplace_url' => $marketplaceUrl,
            'cached_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload) || !is_string($meta)) {
            return;
        }

        $this->upsertSetting(self::CATALOG_CACHE_OPTION, $payload);
        $this->upsertSetting(self::CATALOG_CACHE_META_OPTION, $meta);
    }

    /**
     * @return array{catalog:array<int, array<string, mixed>>, index_path:string}
     */
    private function loadLocalCatalog(): array
    {
        $themeBase = rtrim(THEME_PATH, '/\\');
        $indexPaths = [
            dirname($themeBase) . DIRECTORY_SEPARATOR . 'index.json',
            $themeBase . DIRECTORY_SEPARATOR . 'index.json',
        ];

        foreach ($indexPaths as $indexPath) {
            if (!is_file($indexPath)) {
                continue;
            }

            $content = file_get_contents($indexPath);
            if ($content === false) {
                continue;
            }

            $data = \CMS\Json::decodeArray($content, []);
            if (!is_array($data)) {
                continue;
            }

            $catalog = $this->normalizeCatalogEntries($data, dirname($indexPath));
            if ($catalog !== []) {
                return [
                    'catalog' => $catalog,
                    'index_path' => $indexPath,
                ];
            }
        }

        return [
            'catalog' => [],
            'index_path' => '',
        ];
    }

    /**
     * @return array{catalog:array<int, array<string, mixed>>, error?:string, details?:list<string>}
     */
    private function loadRemoteCatalog(string $marketplaceUrl): array
    {
        $catalogUrl = $this->resolveCatalogIndexUrl($marketplaceUrl);
        if ($catalogUrl === '') {
            return [
                'catalog' => [],
                'error' => 'Marketplace-URL ist ungültig oder nicht freigegeben.',
            ];
        }

        $response = $this->httpClient->get($catalogUrl, [
            'timeout' => 10,
            'connectTimeout' => 5,
            'maxBytes' => self::MAX_CATALOG_BYTES,
            'allowedContentTypes' => ['application/json', 'text/plain'],
            'userAgent' => '365CMS Theme Marketplace',
        ]);

        $content = (string) ($response['body'] ?? '');
        if (($response['success'] ?? false) !== true || $content === '') {
            return [
                'catalog' => [],
                'error' => (string) ($response['error'] ?? 'Remote-Katalog konnte nicht geladen werden.'),
                'details' => array_values(array_filter([
                    !empty($response['status']) ? 'HTTP-Status: ' . (int) $response['status'] : '',
                    !empty($response['contentType']) ? 'Content-Type: ' . (string) $response['contentType'] : '',
                ])),
            ];
        }

        $data = \CMS\Json::decodeArray($content, []);
        if (!is_array($data)) {
            return [
                'catalog' => [],
                'error' => 'Remote-Katalog enthält kein gültiges JSON.',
                'details' => ['Quelle: ' . $catalogUrl],
            ];
        }

        $themeBaseUrl = $this->normalizeMarketplaceUrl(rtrim(MarketplaceEndpoints::themeBaseUrl(), '/'));
        $catalog = $this->normalizeCatalogEntries($data, $themeBaseUrl !== '' ? $themeBaseUrl : $this->resolveBasePath($catalogUrl));
        if ($catalog === []) {
            return [
                'catalog' => [],
                'error' => 'Remote-Katalog enthielt keine verwertbaren Theme-Einträge.',
                'details' => [
                    'Quelle: ' . $catalogUrl,
                    'Rohdaten vorhanden: ' . (($data !== []) ? 'ja' : 'nein'),
                ],
            ];
        }

        return [
            'catalog' => $catalog,
            'details' => [
                'Quelle: ' . $catalogUrl,
                'Einträge: ' . count($catalog),
            ],
        ];
    }

    private function normalizeCatalogEntries(array $data, string $sourceBase): array
    {
        $entries = [];

        if (isset($data['themes']) && is_array($data['themes'])) {
            $entries = $data['themes'];
        } elseif (array_is_list($data)) {
            $entries = $data;
        }

        $catalog = [];
        $seenSlugs = [];
        $normalizedRemoteSourceBase = $this->normalizeMarketplaceUrl($sourceBase);

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $manifestData = $this->sanitizeManifestData(
                $this->loadManifestData($entry, $normalizedRemoteSourceBase !== '' ? $normalizedRemoteSourceBase : $sourceBase)
            );
            $entrySlug = $this->normalizeThemeKey((string) ($entry['slug'] ?? ''));
            $manifestSlug = $this->normalizeThemeKey((string) ($manifestData['slug'] ?? ''));

            if ($entrySlug !== '' && $manifestSlug !== '' && $entrySlug !== $manifestSlug) {
                continue;
            }

            $slug = $entrySlug !== '' ? $entrySlug : $manifestSlug;
            if ($slug === '') {
                continue;
            }

            if (isset($seenSlugs[$slug])) {
                continue;
            }

            $normalized = array_merge($entry, $manifestData);
            $normalized['slug'] = $slug;
            $resolvedSourceBase = $normalizedRemoteSourceBase !== '' ? $normalizedRemoteSourceBase : $sourceBase;
            $normalized['name'] = $this->normalizeCatalogString($normalized['name'] ?? $entry['name'] ?? $slug, 120);
            $normalized['description'] = $this->normalizeCatalogString($normalized['description'] ?? '', 2000);
            $normalized['version'] = $this->normalizeCatalogString($normalized['version'] ?? '', 80);
            $normalized['author'] = $this->normalizeCatalogString($normalized['author'] ?? '', 120);
            $normalized['manifest'] = $this->normalizeCatalogString($normalized['manifest'] ?? '');
            $normalized['update_url'] = $this->resolveCatalogUrl($normalized, ['update_url'], $resolvedSourceBase);
            $normalized['download_url'] = $this->resolveDownloadUrl($normalized, $resolvedSourceBase);
            $normalized['purchase_url'] = $this->resolveCatalogUrl($normalized, ['purchase_url', 'buy_url', 'order_url'], $resolvedSourceBase);
            $normalized['is_paid'] = $this->normalizeBooleanValue($normalized['is_paid'] ?? false);
            $normalized['price_amount'] = $this->normalizeCatalogString($normalized['price_amount'] ?? $normalized['price'] ?? '', 40);
            $normalized['price_currency'] = strtoupper($this->normalizeCatalogString($normalized['price_currency'] ?? 'EUR', 8));
            $normalized['requires_cms'] = $this->normalizeCatalogString($normalized['requires_cms'] ?? $normalized['min_cms_version'] ?? '', 40);
            $normalized['requires_php'] = $this->normalizeCatalogString($normalized['requires_php'] ?? $normalized['min_php'] ?? '', 40);
            $normalized['screenshot'] = $this->resolveAssetUrl((string) ($normalized['screenshot'] ?? ''), $resolvedSourceBase);
            $normalized['sha256'] = $this->resolveIntegrityHash($normalized);

            $seenSlugs[$slug] = true;
            $catalog[] = $normalized;
        }

        return $catalog;
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     * @return array<string, int>
     */
    private function buildStats(array $catalog): array
    {
        return [
            'available' => count($catalog),
            'installable' => count(array_filter($catalog, static fn (array $theme): bool => !empty($theme['install_supported']) && empty($theme['installed']))),
            'manual_only' => count(array_filter($catalog, static fn (array $theme): bool => empty($theme['install_supported']) && empty($theme['installed']))),
            'active' => count(array_filter($catalog, static fn (array $theme): bool => !empty($theme['active']))),
        ];
    }

    /**
     * @return array<int, array{value:string,label:string}>
     */
    private function buildStatusFilters(): array
    {
        return [
            ['value' => 'installable', 'label' => 'Automatisch installierbar'],
            ['value' => 'manual', 'label' => 'Nur manuell / Anfrage'],
            ['value' => 'installed', 'label' => 'Bereits installiert'],
            ['value' => 'active', 'label' => 'Aktiv'],
        ];
    }

    private function sanitizeManifestData(array $manifestData): array
    {
        $sanitized = [];

        foreach (self::MANIFEST_ALLOWED_KEYS as $key) {
            if (!array_key_exists($key, $manifestData)) {
                continue;
            }

            $value = $manifestData[$key];
            if (is_scalar($value) || $value === null) {
                $sanitized[$key] = is_bool($value)
                    ? $value
                    : $this->normalizeCatalogString($value);
            }
        }

        return $sanitized;
    }

    private function normalizeCatalogString(mixed $value, int $length = self::MAX_CATALOG_STRING_LENGTH): string
    {
        $string = preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $value));

        $normalized = $string ?? '';

        if (function_exists('mb_substr')) {
            return (string) mb_substr($normalized, 0, $length);
        }

        return substr($normalized, 0, $length);
    }

    private function resolveCatalogUrl(array $theme, array $keys, string $sourceBase): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($theme[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            if ($this->isAllowedMarketplaceUrl($value)) {
                return $value;
            }

            if ($this->isAllowedMarketplaceUrl($sourceBase)) {
                $resolved = $this->resolveAllowedRelativeMarketplaceUrl($sourceBase, $value);
                if ($this->isAllowedMarketplaceUrl($resolved)) {
                    return $resolved;
                }
            }
        }

        return '';
    }

    private function loadManifestData(array $entry, string $sourceBase): array
    {
        $manifest = trim((string) ($entry['manifest'] ?? ''));
        if ($manifest === '') {
            return [];
        }

        if ($this->isAllowedMarketplaceUrl($manifest)) {
            return $this->fetchRemoteJson($manifest);
        }

        if ($this->isAllowedMarketplaceUrl($sourceBase)) {
            $remoteManifestUrl = $this->resolveAllowedRelativeMarketplaceUrl($sourceBase, $manifest);
            if ($remoteManifestUrl !== '') {
                return $this->fetchRemoteJson($remoteManifestUrl);
            }

            return [];
        }

        $relativeManifestPath = $this->normalizeRelativeCatalogPath($manifest);
        if ($relativeManifestPath === '') {
            return [];
        }

        $manifestPath = $this->resolveSafeLocalCatalogFile($sourceBase, $relativeManifestPath);
        if ($manifestPath === '') {
            return [];
        }

        return $this->readLocalCatalogJsonFile($manifestPath);
    }

    private function fetchRemoteJson(string $url): array
    {
        if (!$this->isAllowedMarketplaceUrl($url)) {
            return [];
        }

        $response = $this->httpClient->get($url, [
            'timeout' => 10,
            'connectTimeout' => 5,
            'maxBytes' => 512 * 1024,
            'allowedContentTypes' => ['application/json', 'text/plain'],
            'userAgent' => '365CMS Theme Marketplace',
        ]);

        $content = (string) ($response['body'] ?? '');
        if (($response['success'] ?? false) !== true || $content === '') {
            return [];
        }

        $data = \CMS\Json::decodeArray($content, []);
        return is_array($data) ? $data : [];
    }

    private function resolveDownloadUrl(array $theme, string $sourceBase): string
    {
        foreach (['download_url', 'package_url', 'archive_url'] as $key) {
            $value = trim((string) ($theme[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            if ($this->isAllowedMarketplaceUrl($value)) {
                return $value;
            }

            if ($this->isAllowedMarketplaceUrl($sourceBase)) {
                $resolved = $this->resolveAllowedRelativeMarketplaceUrl($sourceBase, $value);
                if ($this->isAllowedMarketplaceUrl($resolved)) {
                    return $resolved;
                }
            }
        }

        return '';
    }

    private function resolveAssetUrl(string $value, string $sourceBase): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $normalizedValue = $this->normalizeMarketplaceUrl($value);
        if ($normalizedValue !== '') {
            return $normalizedValue;
        }

        if ($this->normalizeMarketplaceUrl($sourceBase) !== '') {
            return $this->resolveAllowedRelativeMarketplaceUrl($sourceBase, $value);
        }

        return '';
    }

    private function resolveIntegrityHash(array $theme): string
    {
        foreach (['sha256', 'checksum_sha256'] as $key) {
            $value = strtolower(trim((string)($theme[$key] ?? '')));
            if ($value !== '' && preg_match('/^[0-9a-f]{64}$/', $value) === 1) {
                return $value;
            }
        }

        return '';
    }

    private function finalizeInstalledThemePackage(string $targetDir, string $expectedSlug): string
    {
        $targetDir = rtrim($targetDir, '/\\');
        if ($targetDir === '' || !is_dir($targetDir)) {
            return 'Installiertes Theme-Zielverzeichnis fehlt nach dem Marketplace-Download.';
        }

        if ($this->isValidThemeDirectory($targetDir)) {
            return '';
        }

        $detectedThemeDirectory = $this->detectThemeDirectory($targetDir, $expectedSlug);
        if ($detectedThemeDirectory === '') {
            $this->removeDirectory($targetDir);

            return 'Installationspaket enthält kein gültiges Theme-Verzeichnis mit style.css, theme.json und functions.php.';
        }

        if (!$this->promoteNestedThemeDirectory($targetDir, $detectedThemeDirectory)) {
            $this->removeDirectory($targetDir);

            return 'Installiertes Theme-Paket konnte nicht in ein gültiges Laufzeit-Theme überführt werden.';
        }

        if ($this->isValidThemeDirectory($targetDir)) {
            return '';
        }

        $this->removeDirectory($targetDir);

        return 'Installiertes Theme-Paket blieb auch nach der Paketbereinigung unvollständig.';
    }

    private function canAutoInstall(array $theme): bool
    {
        $downloadUrl = trim((string)($theme['download_url'] ?? ''));
        $integrityHash = $this->resolveIntegrityHash($theme);

        return $downloadUrl !== ''
            && $integrityHash !== ''
            && $this->meetsEnvironmentRequirements($theme)
            && $this->isAllowedPackageSize($theme)
            && $this->isAllowedMarketplaceUrl($downloadUrl)
            && $this->hasAllowedArchiveExtension($downloadUrl);
    }

    private function getManualInstallReason(array $theme): string
    {
        $compatibilityReason = $this->getCompatibilityFailureReason($theme);
        if ($compatibilityReason !== '') {
            return $compatibilityReason;
        }

        $purchaseUrl = trim((string)($theme['purchase_url'] ?? ''));
        if ($this->normalizeBooleanValue($theme['is_paid'] ?? false) && $purchaseUrl !== '') {
            return 'Kostenpflichtiges Theme – bitte zuerst über den Marketplace erwerben oder anfragen.';
        }

        $downloadUrl = trim((string)($theme['download_url'] ?? ''));
        if ($downloadUrl === '') {
            return 'Für dieses Theme ist aktuell kein Installationspaket im Marketplace hinterlegt. Bitte kopiere das Theme manuell nach /themes/ und aktiviere es anschließend in der Theme-Verwaltung.';
        }

        if (!$this->isAllowedMarketplaceUrl($downloadUrl)) {
            return 'Download-URL liegt außerhalb der erlaubten Marketplace-Hosts.';
        }

        if (!$this->isAllowedPackageSize($theme)) {
            return 'Theme-Paket überschreitet das Auto-Install-Limit von ' . $this->formatBytesLabel(self::MAX_PACKAGE_SIZE_BYTES) . '.';
        }

        if (!$this->hasAllowedArchiveExtension($downloadUrl)) {
            return 'Download-Archiv muss eine erlaubte Endung (' . implode(', ', self::ALLOWED_ARCHIVE_EXTENSIONS) . ') besitzen.';
        }

        return 'Für die automatische Installation fehlt eine gültige SHA-256-Prüfsumme. Bitte Paket manuell prüfen und installieren.';
    }

    private function findCatalogTheme(string $slug): ?array
    {
        foreach ($this->getCatalog() as $theme) {
            if ($this->normalizeThemeKey((string) ($theme['slug'] ?? '')) === $slug) {
                return $theme;
            }
        }

        return null;
    }

    private function determineThemeTargetFolder(array $theme, string $fallbackSlug): string
    {
        $candidates = [
            (string) ($theme['local_folder'] ?? ''),
            (string) ($theme['slug'] ?? ''),
            (string) ($theme['name'] ?? ''),
            $fallbackSlug,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeThemeKey($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return $fallbackSlug;
    }

    private function getInstalledThemeMap(array $installed): array
    {
        $map = [];

        foreach ($installed as $folder => $theme) {
            $key = $this->normalizeThemeKey((string) $folder);
            if ($key === '') {
                continue;
            }

            $theme['folder'] = (string) $folder;
            $map[$key] = $theme;
        }

        return $map;
    }

    private function normalizeThemeKey(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_replace('/[^a-z0-9_-]/', '', $value) ?? '';
    }

    private function normalizeBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'ja', 'paid'], true);
    }

    private function normalizePackageSize(mixed $value): int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            return max(0, (int) $value);
        }

        return 0;
    }

    private function isAllowedPackageSize(array $theme): bool
    {
        $packageSize = $this->normalizePackageSize($theme['package_size'] ?? null);

        return $packageSize === 0 || $packageSize <= self::MAX_PACKAGE_SIZE_BYTES;
    }

    private function hasAllowedArchiveExtension(string $url): bool
    {
        $normalizedUrl = $this->normalizeMarketplaceUrl($url);
        if ($normalizedUrl === '') {
            return false;
        }

        $path = strtolower((string) parse_url($normalizedUrl, PHP_URL_PATH));
        if ($path === '') {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::ALLOWED_ARCHIVE_EXTENSIONS, true);
    }

    private function formatBytesLabel(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with($url, 'https://');
    }

    private function isAllowedMarketplaceUrl(string $url): bool
    {
        return $this->normalizeMarketplaceUrl($url) !== '';
    }

    private function normalizeMarketplaceUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !$this->isHttpsUrl($url)) {
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

        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        if ($port !== null && $port !== 443) {
            return '';
        }

        if (!$this->isAllowedMarketplaceHost($host)) {
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

    private function isAllowedMarketplaceHost(string $host): bool
    {
        if (in_array($host, self::ALLOWED_MARKETPLACE_HOSTS, true)) {
            return true;
        }

        return str_ends_with($host, '.githubusercontent.com');
    }

    private function resolveCatalogIndexUrl(string $marketplaceUrl): string
    {
        $marketplaceUrl = $this->normalizeMarketplaceUrl($marketplaceUrl);
        if ($marketplaceUrl === '') {
            return '';
        }

        $path = (string) parse_url($marketplaceUrl, PHP_URL_PATH);
        if (str_ends_with(strtolower($path), '.json')) {
            return $marketplaceUrl;
        }

        return $this->normalizeMarketplaceUrl(rtrim($marketplaceUrl, '/') . '/index.json');
    }

    private function resolveBasePath(string $url): string
    {
        $url = $this->normalizeMarketplaceUrl($url);
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

    private function detectThemeDirectory(string $extractDir, string $slug): string
    {
        if ($this->isValidThemeDirectory($extractDir)) {
            return $extractDir;
        }

        $candidates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isDir()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            if (!$this->isValidThemeDirectory($path)) {
                continue;
            }

            $candidates[] = $path;
        }

        if ($candidates === []) {
            return '';
        }

        usort($candidates, function (string $left, string $right) use ($slug): int {
            $leftBase = $this->normalizeThemeKey(basename($left));
            $rightBase = $this->normalizeThemeKey(basename($right));

            $leftMatches = (int) ($leftBase === $slug);
            $rightMatches = (int) ($rightBase === $slug);
            if ($leftMatches !== $rightMatches) {
                return $rightMatches <=> $leftMatches;
            }

            return substr_count($left, DIRECTORY_SEPARATOR) <=> substr_count($right, DIRECTORY_SEPARATOR);
        });

        return $candidates[0];
    }

    private function promoteNestedThemeDirectory(string $targetDir, string $detectedThemeDirectory): bool
    {
        $targetDir = rtrim($targetDir, '/\\');
        $detectedThemeDirectory = rtrim($detectedThemeDirectory, '/\\');

        if ($targetDir === '' || $detectedThemeDirectory === '' || $detectedThemeDirectory === $targetDir) {
            return false;
        }

        $normalizedTargetDir = rtrim(str_replace('\\', '/', $targetDir), '/') . '/';
        $normalizedDetectedThemeDirectory = rtrim(str_replace('\\', '/', $detectedThemeDirectory), '/') . '/';
        if (!str_starts_with($normalizedDetectedThemeDirectory, $normalizedTargetDir)) {
            return false;
        }

        $targetParentDir = dirname($targetDir);
        $promotedThemeDirectory = $this->buildTemporaryThemePath($targetParentDir, '365cms_theme_promote_');

        if (!$this->moveDirectory($detectedThemeDirectory, $promotedThemeDirectory)) {
            return false;
        }

        $this->removeDirectory($targetDir);

        if (@rename($promotedThemeDirectory, $targetDir)) {
            return true;
        }

        if ($this->copyDirectory($promotedThemeDirectory, $targetDir)) {
            $this->removeDirectory($promotedThemeDirectory);
            return true;
        }

        if (is_dir($promotedThemeDirectory)) {
            $this->removeDirectory($promotedThemeDirectory);
        }

        return false;
    }

    private function isValidThemeDirectory(string $path): bool
    {
        return is_dir($path)
            && is_file($path . DIRECTORY_SEPARATOR . 'style.css')
            && is_file($path . DIRECTORY_SEPARATOR . 'theme.json')
            && is_file($path . DIRECTORY_SEPARATOR . 'functions.php');
    }

    private function buildTemporaryThemePath(string $parentDir, string $prefix): string
    {
        $parentDir = rtrim($parentDir, '/\\');

        do {
            $candidate = $parentDir . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        } while (file_exists($candidate));

        return $candidate;
    }

    private function moveDirectory(string $sourceDir, string $targetDir): bool
    {
        if (is_link($sourceDir) || is_link($targetDir)) {
            return false;
        }

        $targetBase = dirname($targetDir);
        if (!is_dir($targetBase) && !mkdir($targetBase, 0775, true) && !is_dir($targetBase)) {
            return false;
        }

        if (rename($sourceDir, $targetDir)) {
            return true;
        }

        if (!$this->copyDirectory($sourceDir, $targetDir)) {
            return false;
        }

        $this->removeDirectory($sourceDir);
        return true;
    }

    private function copyDirectory(string $sourceDir, string $targetDir): bool
    {
        if (is_link($sourceDir) || is_link($targetDir)) {
            return false;
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return false;
        }

        $iterator = new \DirectoryIterator($sourceDir);
        foreach ($iterator as $item) {
            if ($item->isDot()) {
                continue;
            }

            $sourcePath = $item->getPathname();
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $item->getFilename();

            if ($item->isLink()) {
                return false;
            }

            if ($item->isDir()) {
                if (!$this->copyDirectory($sourcePath, $targetPath)) {
                    return false;
                }
                continue;
            }

            if (!copy($sourcePath, $targetPath)) {
                return false;
            }
        }

        return true;
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path)) {
            unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }

    private function acquireThemeInstallLock(string $targetParentDir, string $targetFolder): string
    {
        $normalizedFolder = $this->normalizeThemeKey($targetFolder);
        if ($normalizedFolder === '') {
            return '';
        }

        $lockPath = rtrim($targetParentDir, '/\\') . DIRECTORY_SEPARATOR . '.cms-theme-install-' . $normalizedFolder;

        return @mkdir($lockPath, 0700) ? $lockPath : '';
    }

    private function releaseThemeInstallLock(string $lockPath): void
    {
        if ($lockPath === '' || !is_dir($lockPath)) {
            return;
        }

        @rmdir($lockPath);
    }

    private function normalizeRelativeCatalogPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if ($path === '' || strlen($path) > 255 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path), static fn(string $segment): bool => $segment !== ''));
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

    private function resolveSafeLocalCatalogFile(string $sourceBase, string $relativePath): string
    {
        $resolvedSourceBase = realpath($sourceBase);
        if ($resolvedSourceBase === false || !is_dir($resolvedSourceBase) || is_link($resolvedSourceBase)) {
            return '';
        }

        $manifestPath = $resolvedSourceBase . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $resolvedManifestPath = realpath($manifestPath);
        if ($resolvedManifestPath === false || !is_file($resolvedManifestPath) || is_link($resolvedManifestPath)) {
            return '';
        }

        $normalizedSourceBase = rtrim(str_replace('\\', '/', $resolvedSourceBase), '/') . '/';
        $normalizedManifestPath = str_replace('\\', '/', $resolvedManifestPath);

        return str_starts_with($normalizedManifestPath, $normalizedSourceBase) ? $resolvedManifestPath : '';
    }

    private function readLocalCatalogJsonFile(string $filePath): array
    {
        if (filesize($filePath) > self::MAX_MANIFEST_BYTES) {
            return [];
        }

        try {
            $file = new \SplFileObject($filePath, 'rb');
        } catch (\RuntimeException) {
            return [];
        }

        $content = '';
        while (!$file->eof()) {
            $content .= (string) $file->fgets();
            if (strlen($content) > self::MAX_MANIFEST_BYTES) {
                return [];
            }
        }

        if ($content === '') {
            return [];
        }

        $data = \CMS\Json::decodeArray($content, []);
        return is_array($data) ? $data : [];
    }

    private function resolveAllowedRelativeMarketplaceUrl(string $sourceBase, string $path): string
    {
        $relativePath = $this->normalizeRelativeCatalogPath($path);
        $normalizedSourceBase = $this->normalizeMarketplaceUrl($sourceBase);
        if ($relativePath === '' || $normalizedSourceBase === '') {
            return '';
        }

        $resolved = rtrim($normalizedSourceBase, '/') . '/' . $relativePath;

        return $this->normalizeMarketplaceUrl($resolved);
    }

    private function meetsEnvironmentRequirements(array $theme): bool
    {
        return $this->getCompatibilityFailureReason($theme) === '';
    }

    private function getCompatibilityFailureReason(array $theme): string
    {
        $requiresCms = trim((string) ($theme['requires_cms'] ?? ''));
        $requiresPhp = trim((string) ($theme['requires_php'] ?? ''));
        $currentCms = defined('CMS_VERSION')
            ? (string) CMS_VERSION
            : (class_exists('\CMS\\Version') ? (string) \CMS\Version::CURRENT : '0.0.0');

        if ($requiresCms !== '' && version_compare($currentCms, $requiresCms, '<')) {
            return 'Theme erfordert mindestens 365CMS ' . $requiresCms . '.';
        }

        if ($requiresPhp !== '' && version_compare(PHP_VERSION, $requiresPhp, '<')) {
            return 'Theme erfordert mindestens PHP ' . $requiresPhp . '.';
        }

        return '';
    }

    /** @return array<int, string> */
    private function buildInstallContextDetails(string $slug, string $downloadUrl, string $integrityHash, array $theme): array
    {
        return array_values(array_filter([
            'Theme-Slug: ' . $slug,
            $downloadUrl !== '' ? 'Quelle: ' . $downloadUrl : '',
            !empty($theme['package_size']) ? 'Paketgröße: ' . $this->formatBytesLabel($this->normalizePackageSize($theme['package_size'])) : '',
            $downloadUrl !== '' ? 'Archiv-Endung erlaubt: ' . ($this->hasAllowedArchiveExtension($downloadUrl) ? 'ja' : 'nein') : '',
            $integrityHash !== '' ? 'SHA-256: ' . substr($integrityHash, 0, 12) . '…' : '',
            !empty($theme['requires_cms']) ? '365CMS ab: ' . (string) $theme['requires_cms'] : '',
            !empty($theme['requires_php']) ? 'PHP ab: ' . (string) $theme['requires_php'] : '',
        ]));
    }

    /** @return array<string, mixed> */
    private function buildInstallErrorData(string $slug, string $downloadUrl, string $integrityHash, array $theme): array
    {
        return [
            'slug' => $slug,
            'download_url' => $downloadUrl,
            'download_host' => strtolower((string) parse_url($downloadUrl, PHP_URL_HOST)),
            'archive_extension' => strtolower((string) pathinfo((string) parse_url($downloadUrl, PHP_URL_PATH), PATHINFO_EXTENSION)),
            'sha256' => $integrityHash,
            'package_size_bytes' => $this->normalizePackageSize($theme['package_size'] ?? null),
            'requires_cms' => (string) ($theme['requires_cms'] ?? ''),
            'requires_php' => (string) ($theme['requires_php'] ?? ''),
        ];
    }

    private function buildInstallFailureResult(string $message, string $errorCode, array $details = [], array $errorData = []): array
    {
        $context = [
            'source' => '/admin/theme-marketplace',
            'title' => 'Theme Marketplace',
        ];

        return [
            'success' => false,
            'error' => $message,
            'details' => array_values(array_filter(array_map(static fn (mixed $detail): string => trim((string) $detail), $details), static fn (string $detail): bool => $detail !== '')),
            'error_details' => [
                'code' => $errorCode,
                'data' => $errorData,
                'context' => $context,
            ],
            'report_payload' => ErrorReportService::buildReportPayloadFromWpError(
                new \CMS\WP_Error($errorCode, $message, $errorData),
                $context
            ),
        ];
    }

    private function upsertSetting(string $key, string $value): void
    {
        $this->db->execute(
            "INSERT INTO {$this->prefix}settings (option_name, option_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            [$key, $value]
        );
    }
}
