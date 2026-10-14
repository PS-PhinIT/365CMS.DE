<?php
declare(strict_types=1);

/**
 * PluginMarketplaceModule – Verfügbare Plugins aus der Registry
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Http\Client as HttpClient;
use CMS\Services\ErrorReportService;
use CMS\Services\MarketplaceEndpoints;
use CMS\Services\UpdateService;

class PluginMarketplaceModule
{
    private const MAX_REGISTRY_BYTES = 1048576;
    private const MAX_MANIFEST_BYTES = 524288;
    private const REGISTRY_CACHE_TTL = 900;
    private const REGISTRY_CACHE_OPTION = 'plugin_marketplace_registry_cache';
    private const REGISTRY_CACHE_META_OPTION = 'plugin_marketplace_registry_cache_meta';
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
        'category',
        'manifest',
        'update_url',
        'download_url',
        'package_url',
        'archive_url',
        'purchase_url',
        'buy_url',
        'order_url',
        'is_paid',
        'is_commercial',
        'price_amount',
        'price_currency',
        'price',
        'contact_form_slug',
        'purchase_type',
        'sha256',
        'checksum_sha256',
        'package_size',
        'homepage_url',
        'docs_url',
        'changelog_url',
        'icon_url',
        'screenshot',
        'requires_cms',
        'min_cms_version',
        'requires_php',
        'min_php',
        'tested_up_to',
        'released',
        'notes',
        'submission_source',
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

    private const DEFAULT_REGISTRY_URL = MarketplaceEndpoints::PLUGIN_INDEX_URL;

    private readonly \CMS\Database $db;
    private readonly string $prefix;
    private readonly HttpClient $httpClient;
    private readonly UpdateService $updateService;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $registryCache = null;
    /** @var array<string, string> */
    private array $registrySource = [
        'type' => 'none',
        'status' => 'warning',
        'message' => 'Noch keine Marketplace-Quelle ausgewertet.',
    ];

    public function __construct()
    {
        $this->db     = \CMS\Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->httpClient = HttpClient::getInstance();
        $this->updateService = UpdateService::getInstance();
    }

    public function getData(): array
    {
        $available = $this->loadRegistry();
        $installed = $this->getInstalledSlugs();
        $installedLookup = array_fill_keys($installed, true);

        foreach ($available as &$plugin) {
            $pluginSlug = $this->normalizePluginSlug((string) ($plugin['slug'] ?? ''));
            $downloadUrl = trim((string) ($plugin['download_url'] ?? ''));
            $integrityHash = $this->resolveIntegrityHash($plugin);
            $packageSizeBytes = $this->normalizePackageSize($plugin['package_size'] ?? null);
            $plugin['installed'] = $pluginSlug !== '' && isset($installedLookup[$pluginSlug]);
            $plugin['package_size_bytes'] = $packageSizeBytes;
            $plugin['package_size_label'] = $packageSizeBytes > 0 ? $this->formatBytesLabel($packageSizeBytes) : '';
            $plugin['download_host'] = strtolower((string) parse_url($downloadUrl, PHP_URL_HOST));
            $plugin['download_host_allowed'] = $downloadUrl !== '' && $this->isAllowedMarketplaceUrl($downloadUrl);
            $plugin['download_extension_allowed'] = $downloadUrl !== '' && $this->hasAllowedArchiveExtension($downloadUrl);
            $plugin['package_size_allowed'] = $this->isAllowedPackageSize($plugin);
            $plugin['integrity_hash_short'] = $integrityHash !== '' ? substr($integrityHash, 0, 12) . '…' : '';
            $plugin['auto_install_supported'] = $this->canAutoInstall($plugin);
            $plugin['manual_install_only'] = !$plugin['auto_install_supported'];
            $plugin['integrity_hash_present'] = $integrityHash !== '';
            $plugin['compatibility_reason'] = $this->getCompatibilityFailureReason($plugin);
            $plugin['compatible'] = $plugin['compatibility_reason'] === '';
            $plugin['install_reason'] = $plugin['auto_install_supported']
                ? 'Paket und SHA-256 vorhanden.'
                : $this->getManualInstallReason($plugin);
        }
        unset($plugin);

        return [
            'plugins'   => $available,
            'installed' => $installed,
            'stats'     => $this->buildStats($available, $installed),
            'source'    => $this->registrySource,
            'endpoints' => MarketplaceEndpoints::all(),
            'constraints' => [
                'registry_cache_ttl' => self::REGISTRY_CACHE_TTL,
                'registry_max_bytes' => self::MAX_REGISTRY_BYTES,
                'registry_max_bytes_label' => $this->formatBytesLabel(self::MAX_REGISTRY_BYTES),
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
                'categories' => $this->buildCategoryFilters($available),
                'statuses' => $this->buildStatusFilters(),
            ],
        ];
    }

    public function hasCatalogPluginSlug(string $slug): bool
    {
        return $this->findCatalogPlugin($slug) !== null;
    }

    public function installPlugin(string $slug): array
    {
        $slug = $this->normalizePluginSlug($slug);
        if ($slug === '') {
            return $this->buildInstallFailureResult('Ungültiger Plugin-Slug.', 'plugin_marketplace_invalid_slug');
        }

        $found = $this->findCatalogPlugin($slug);

        if (!$found) {
            return $this->buildInstallFailureResult('Plugin nicht im Marketplace gefunden.', 'plugin_marketplace_missing_catalog_entry', [
                'Slug: ' . $slug,
            ], [
                'slug' => $slug,
            ]);
        }

        if (!empty($found['installed'])) {
            return $this->buildInstallFailureResult('Plugin ist bereits installiert.', 'plugin_marketplace_already_installed', [
                'Slug: ' . $slug,
            ], [
                'slug' => $slug,
            ]);
        }

        $downloadUrl = $found['download_url'] ?? '';
        $integrityHash = $this->resolveIntegrityHash($found);

        if (!$this->canAutoInstall($found)) {
            return $this->buildInstallFailureResult(
                $this->getManualInstallReason($found),
                'plugin_marketplace_manual_install_required',
                $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $found),
                $this->buildInstallErrorData($slug, $downloadUrl, $integrityHash, $found)
            );
        }

        if (!$this->isAllowedPackageSize($found)) {
            return $this->buildInstallFailureResult(
                'Paket überschreitet die erlaubte Marketplace-Größe für Auto-Installationen.',
                'plugin_marketplace_package_too_large',
                $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $found),
                $this->buildInstallErrorData($slug, $downloadUrl, $integrityHash, $found)
            );
        }

        if (!$this->isAllowedMarketplaceUrl($downloadUrl)) {
            return $this->buildInstallFailureResult(
                'Download-URL liegt außerhalb der erlaubten Marketplace-Hosts.',
                'plugin_marketplace_disallowed_download_host',
                $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $found),
                $this->buildInstallErrorData($slug, $downloadUrl, $integrityHash, $found)
            );
        }

        if (!$this->hasAllowedArchiveExtension($downloadUrl)) {
            return $this->buildInstallFailureResult(
                'Download-Archiv hat keine erlaubte Dateiendung für Auto-Installationen.',
                'plugin_marketplace_disallowed_archive_extension',
                $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $found),
                $this->buildInstallErrorData($slug, $downloadUrl, $integrityHash, $found)
            );
        }

        $pluginsDir = $this->resolvePluginsDir();
        if ($pluginsDir === '') {
            return $this->buildInstallFailureResult('Plugin-Zielverzeichnis ist nicht verfügbar.', 'plugin_marketplace_plugins_dir_missing', [
                'Slug: ' . $slug,
            ], [
                'slug' => $slug,
            ]);
        }

        $targetDir = $pluginsDir . $slug . DIRECTORY_SEPARATOR;
        $normalizedPluginsDir = rtrim(str_replace('\\', '/', $pluginsDir), '/') . '/';
        $normalizedTargetDir = rtrim(str_replace('\\', '/', $targetDir), '/') . '/';

        if (!str_starts_with($normalizedTargetDir, $normalizedPluginsDir)) {
            return $this->buildInstallFailureResult('Plugin-Zielverzeichnis ist ungültig.', 'plugin_marketplace_invalid_target_dir', [
                'Slug: ' . $slug,
            ], [
                'slug' => $slug,
            ]);
        }

        if (is_dir($targetDir)) {
            return $this->buildInstallFailureResult('Plugin ist bereits installiert.', 'plugin_marketplace_target_exists', [
                'Slug: ' . $slug,
            ], [
                'slug' => $slug,
            ]);
        }

        $result = $this->updateService->downloadAndInstallUpdate(
            $downloadUrl,
            $integrityHash,
            $targetDir,
            'plugin',
            (string) ($found['name'] ?? $slug),
            (string) ($found['version'] ?? 'Marketplace')
        );

        if (($result['success'] ?? false) !== true) {
            return $this->buildInstallFailureResult(
                (string) ($result['message'] ?? 'Plugin konnte nicht installiert werden.'),
                'plugin_marketplace_install_failed',
                $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $found),
                array_merge(
                    $this->buildInstallErrorData($slug, $downloadUrl, $integrityHash, $found),
                    ['installer_result' => is_array($result) ? array_intersect_key($result, array_flip(['message', 'status', 'code', 'sha256_verified'])) : []]
                )
            );
        }

        return [
            'success' => true,
            'message' => "Plugin \"{$slug}\" installiert. Aktiviere es unter Plugin-Verwaltung.",
            'details' => array_merge(
                $this->buildInstallContextDetails($slug, $downloadUrl, $integrityHash, $found),
                [
                    'Zielpfad: ' . $normalizedTargetDir,
                    'SHA-256 verifiziert: ' . (!empty($result['sha256_verified']) ? 'ja' : 'nein'),
                ]
            ),
        ];
    }

    private function loadRegistry(): array
    {
        if ($this->registryCache !== null) {
            return $this->registryCache;
        }

        $registryUrl = $this->getRegistryUrl();
        $remoteRegistry = ['plugins' => []];
        $cachedRegistry = $this->loadCachedRegistry($registryUrl);
        if ($cachedRegistry !== null) {
            $this->registrySource = [
                'type' => 'cache',
                'status' => 'info',
                'message' => 'Plugin-Registry aus lokalem Cache geladen.',
                'url' => $registryUrl,
                'details' => array_values(array_filter([
                    !empty($cachedRegistry['cached_at_label']) ? 'Stand: ' . $cachedRegistry['cached_at_label'] : '',
                    isset($cachedRegistry['age_seconds']) ? 'Cache-Alter: ' . (int) $cachedRegistry['age_seconds'] . ' Sekunden' : '',
                ])),
            ];

            return $this->registryCache = $cachedRegistry['plugins'];
        }

        if ($registryUrl !== '') {
            $remoteRegistry = $this->loadRemoteRegistry($registryUrl);
            $remotePlugins = is_array($remoteRegistry['plugins'] ?? null) ? $remoteRegistry['plugins'] : [];
            if ($remotePlugins !== []) {
                $this->persistRegistryCache($registryUrl, $remotePlugins);
                $this->registrySource = [
                    'type' => 'remote',
                    'status' => 'success',
                    'message' => 'Plugin-Registry erfolgreich aus der Remote-Quelle geladen.',
                    'url' => $registryUrl,
                    'details' => array_values(array_filter([
                        !empty($remoteRegistry['details']) && is_array($remoteRegistry['details']) ? implode(' · ', array_map('strval', $remoteRegistry['details'])) : '',
                    ])),
                ];
                return $this->registryCache = $remotePlugins;
            }

            $staleRegistry = $this->loadCachedRegistry($registryUrl, true);
            if ($staleRegistry !== null) {
                $this->registrySource = [
                    'type' => 'cache',
                    'status' => 'warning',
                    'message' => 'Remote-Registry derzeit nicht verfügbar; letzter bekannter Cache wird als Fallback genutzt.',
                    'url' => $registryUrl,
                    'details' => array_values(array_filter([
                        !empty($staleRegistry['cached_at_label']) ? 'Cache-Stand: ' . $staleRegistry['cached_at_label'] : '',
                        isset($staleRegistry['age_seconds']) ? 'Cache-Alter: ' . (int) $staleRegistry['age_seconds'] . ' Sekunden' : '',
                        !empty($remoteRegistry['error']) ? 'Remote-Fehler: ' . (string) $remoteRegistry['error'] : '',
                        !empty($remoteRegistry['details']) && is_array($remoteRegistry['details']) ? implode(' · ', array_map('strval', $remoteRegistry['details'])) : '',
                    ])),
                ];

                return $this->registryCache = $staleRegistry['plugins'];
            }
        }

        $localIndex = $this->resolveLocalRegistryPath();
        if ($localIndex === '' || !is_file($localIndex)) {
            $this->registrySource = [
                'type' => 'none',
                'status' => 'warning',
                'message' => 'Es konnte weder eine Remote-Registry noch ein lokaler Marketplace-Index geladen werden.',
                'url' => $registryUrl,
                'details' => array_values(array_filter([
                    !empty($remoteRegistry['error']) ? 'Remote-Fehler: ' . (string) $remoteRegistry['error'] : '',
                    !empty($remoteRegistry['details']) && is_array($remoteRegistry['details']) ? implode(' · ', array_map('strval', $remoteRegistry['details'])) : '',
                ])),
            ];
            return $this->registryCache = [];
        }

        $content = file_get_contents($localIndex);
        if ($content === false || $content === '') {
            $this->registrySource = [
                'type' => 'none',
                'status' => 'warning',
                'message' => 'Der lokale Marketplace-Index ist leer oder nicht lesbar.',
                'url' => $localIndex,
            ];
            return $this->registryCache = [];
        }

        $json = \CMS\Json::decodeArray($content, []);
        $plugins = is_array($json) ? ($json['plugins'] ?? $json) : [];

        $this->registrySource = [
            'type' => 'local',
            'status' => $registryUrl !== '' ? 'warning' : 'info',
            'message' => $registryUrl !== ''
                ? 'Remote-Registry derzeit nicht verfügbar; lokaler Marketplace-Index wird als Fallback genutzt.'
                : 'Lokaler Marketplace-Index wird verwendet.',
            'url' => $localIndex,
        ];

        return $this->registryCache = $this->sanitizeCatalogEntries(is_array($plugins) ? $plugins : [], dirname($localIndex));
    }

    /**
     * @return array{plugins:array<int, array<string, mixed>>, age_seconds:int, cached_at_label:string}|null
     */
    private function loadCachedRegistry(string $registryUrl, bool $allowExpired = false): ?array
    {
        $payload = $this->db->get_var(
            "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
            [self::REGISTRY_CACHE_OPTION]
        );
        $metaPayload = $this->db->get_var(
            "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
            [self::REGISTRY_CACHE_META_OPTION]
        );

        $cacheData = \CMS\Json::decodeArray((string) $payload, []);
        $cacheMeta = \CMS\Json::decodeArray((string) $metaPayload, []);
        if (!is_array($cacheData) || !is_array($cacheMeta)) {
            return null;
        }

        $cachedUrl = $this->normalizeMarketplaceUrl((string) ($cacheMeta['registry_url'] ?? ''));
        $normalizedRegistryUrl = $this->normalizeMarketplaceUrl($registryUrl);
        if ($cachedUrl === '' || $normalizedRegistryUrl === '' || $cachedUrl !== $normalizedRegistryUrl) {
            return null;
        }

        $cachedAt = (int) ($cacheMeta['cached_at'] ?? 0);
        if ($cachedAt <= 0) {
            return null;
        }

        $ageSeconds = max(0, time() - $cachedAt);
        if (!$allowExpired && $ageSeconds > self::REGISTRY_CACHE_TTL) {
            return null;
        }

        $plugins = is_array($cacheData['plugins'] ?? null) ? $cacheData['plugins'] : [];

        return [
            'plugins' => $this->sanitizeCatalogEntries($plugins),
            'age_seconds' => $ageSeconds,
            'cached_at_label' => date('d.m.Y H:i:s', $cachedAt),
        ];
    }

    /** @param array<int, array<string, mixed>> $plugins */
    private function persistRegistryCache(string $registryUrl, array $plugins): void
    {
        $payload = json_encode(['plugins' => $plugins], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $meta = json_encode([
            'registry_url' => $registryUrl,
            'cached_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload) || !is_string($meta)) {
            return;
        }

        $this->upsertSetting(self::REGISTRY_CACHE_OPTION, $payload);
        $this->upsertSetting(self::REGISTRY_CACHE_META_OPTION, $meta);
    }

    private function getRegistryUrl(): string
    {
        $normalizedRegistryUrl = $this->normalizeMarketplaceUrl(MarketplaceEndpoints::pluginIndexUrl());
        if ($normalizedRegistryUrl !== '') {
            return $normalizedRegistryUrl;
        }

        return $this->normalizeMarketplaceUrl(self::DEFAULT_REGISTRY_URL);
    }

    /**
     * @return array{plugins: array<int, array<string, mixed>>, error?: string, details?: list<string>}
     */
    private function loadRemoteRegistry(string $registryUrl): array
    {
        $registryUrl = $this->normalizeMarketplaceUrl($registryUrl);
        if ($registryUrl === '') {
            return [
                'plugins' => [],
                'error' => 'Registry-URL ist ungültig oder nicht freigegeben.',
            ];
        }

        $response = $this->httpClient->get($registryUrl, [
            'userAgent' => '365CMS-PluginMarketplace/1.0',
            'timeout' => 10,
            'connectTimeout' => 5,
            'maxBytes' => self::MAX_REGISTRY_BYTES,
            'allowedContentTypes' => ['application/json', 'text/plain'],
        ]);
        $content = (string) ($response['body'] ?? '');

        if (($response['success'] ?? false) !== true || $content === '') {
            return [
                'plugins' => [],
                'error' => (string) ($response['error'] ?? 'Remote-Registry konnte nicht geladen werden.'),
                'details' => array_values(array_filter([
                    !empty($response['status']) ? 'HTTP-Status: ' . (int) $response['status'] : '',
                    !empty($response['contentType']) ? 'Content-Type: ' . (string) $response['contentType'] : '',
                ])),
            ];
        }

        $data = \CMS\Json::decodeArray($content, []);
        $plugins = is_array($data) ? ($data['plugins'] ?? $data) : [];

        $sanitizedPlugins = $this->sanitizeCatalogEntries(
            is_array($plugins) ? $plugins : [],
            $this->resolveSourceBase($registryUrl)
        );

        if ($sanitizedPlugins === []) {
            return [
                'plugins' => [],
                'error' => 'Remote-Registry enthielt keine verwertbaren Plugin-Einträge.',
                'details' => [
                    'Quelle: ' . $registryUrl,
                    'Rohdaten vorhanden: ' . (is_array($plugins) && $plugins !== [] ? 'ja' : 'nein'),
                ],
            ];
        }

        return [
            'plugins' => $sanitizedPlugins,
            'details' => [
                'Quelle: ' . $registryUrl,
                'Einträge: ' . count($sanitizedPlugins),
            ],
        ];
    }

    private function getInstalledSlugs(): array
    {
        $pluginsDir = $this->resolvePluginsDir();
        $slugs = [];
        if (is_dir($pluginsDir)) {
            foreach (new \DirectoryIterator($pluginsDir) as $item) {
                if ($item->isDot() || !$item->isDir()) continue;

                $slug = $this->normalizePluginSlug($item->getFilename());
                if ($slug !== '') {
                    $slugs[$slug] = true;
                }
            }
        }

        return array_keys($slugs);
    }

    private function sanitizeCatalogEntries(array $entries, string $sourceBase = ''): array
    {
        $sanitized = [];
        $seenSlugs = [];
        $normalizedRemoteSourceBase = $this->normalizeMarketplaceUrl($sourceBase);

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $manifestData = $this->sanitizeManifestData(
                $this->loadManifestData($entry, $normalizedRemoteSourceBase !== '' ? $normalizedRemoteSourceBase : $sourceBase)
            );
            $entrySlug = $this->normalizePluginSlug((string) ($entry['slug'] ?? ''));
            $manifestSlug = $this->normalizePluginSlug((string) ($manifestData['slug'] ?? ''));

            if ($entrySlug !== '' && $manifestSlug !== '' && $entrySlug !== $manifestSlug) {
                continue;
            }

            $normalized = array_merge($entry, $manifestData);
            $normalized['slug'] = $entrySlug !== '' ? $entrySlug : $manifestSlug;

            $resolvedSourceBase = $normalizedRemoteSourceBase !== '' ? $normalizedRemoteSourceBase : $sourceBase;
            $downloadUrl = $this->resolveCatalogUrl($normalized, ['download_url', 'package_url', 'archive_url'], $resolvedSourceBase);
            $purchaseUrl = $this->resolveCatalogUrl($normalized, ['purchase_url', 'buy_url', 'order_url'], $resolvedSourceBase);
            $updateUrl = $this->resolveCatalogUrl($normalized, ['update_url'], $resolvedSourceBase);

            if (($normalized['slug'] ?? '') === '') {
                continue;
            }

            if (isset($seenSlugs[$normalized['slug']])) {
                continue;
            }

            $normalized['name'] = $this->normalizeCatalogString($normalized['name'] ?? $normalized['slug'] ?? '');
            $normalized['description'] = $this->normalizeCatalogString($normalized['description'] ?? '', 2000);
            $normalized['version'] = $this->normalizeCatalogString($normalized['version'] ?? '', 80);
            $normalized['author'] = $this->normalizeCatalogString($normalized['author'] ?? '', 120);
            $normalized['category'] = $this->normalizeCatalogString($normalized['category'] ?? '', 80);
            $normalized['manifest'] = $this->normalizeCatalogString($normalized['manifest'] ?? '');
            $normalized['update_url'] = $updateUrl;
            $normalized['download_url'] = $downloadUrl;
            $normalized['purchase_url'] = $purchaseUrl;
            $normalized['is_paid'] = $this->normalizeBooleanValue($normalized['is_paid'] ?? false);
            $normalized['price_amount'] = $this->normalizeCatalogString($normalized['price_amount'] ?? $normalized['price'] ?? '', 40);
            $normalized['price_currency'] = strtoupper($this->normalizeCatalogString($normalized['price_currency'] ?? 'EUR', 8));
            $normalized['requires_cms'] = $this->normalizeCatalogString($normalized['requires_cms'] ?? $normalized['min_cms_version'] ?? '', 40);
            $normalized['requires_php'] = $this->normalizeCatalogString($normalized['requires_php'] ?? $normalized['min_php'] ?? '', 40);
            $normalized['package_size'] = $this->normalizePackageSize($normalized['package_size'] ?? null);
            $normalized['sha256'] = $this->resolveIntegrityHash($normalized);

            $seenSlugs[$normalized['slug']] = true;
            $sanitized[] = $normalized;
        }

        return $sanitized;
    }

    /**
     * @param array<int, array<string, mixed>> $plugins
     * @param array<int, string> $installed
     * @return array<string, int>
     */
    private function buildStats(array $plugins, array $installed): array
    {
        return [
            'available' => count($plugins),
            'installed' => count($installed),
            'installable' => count(array_filter($plugins, fn($plugin) => empty($plugin['installed']) && !empty($plugin['auto_install_supported']))),
            'manual_only' => count(array_filter($plugins, fn($plugin) => empty($plugin['installed']) && empty($plugin['auto_install_supported']))),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $plugins
     * @return array<int, string>
     */
    private function buildCategoryFilters(array $plugins): array
    {
        $categories = array_unique(array_filter(array_map(
            fn(array $plugin): string => $this->normalizeCatalogString($plugin['category'] ?? '', 80),
            $plugins
        )));

        sort($categories);

        return array_values($categories);
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

    private function loadManifestData(array $entry, string $sourceBase): array
    {
        $manifest = trim((string) ($entry['manifest'] ?? ''));
        if ($manifest === '') {
            return [];
        }

        $manifestUrl = $this->normalizeMarketplaceUrl($manifest);
        if ($manifestUrl !== '') {
            return $this->fetchRemoteJson($manifestUrl);
        }

        if ($sourceBase !== '' && $this->normalizeMarketplaceUrl($sourceBase) !== '') {
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
        $url = $this->normalizeMarketplaceUrl($url);
        if ($url === '') {
            return [];
        }

        $response = $this->httpClient->get($url, [
            'userAgent' => '365CMS-PluginMarketplace/1.0',
            'timeout' => 10,
            'connectTimeout' => 5,
            'maxBytes' => self::MAX_MANIFEST_BYTES,
            'allowedContentTypes' => ['application/json', 'text/plain'],
        ]);
        $content = (string) ($response['body'] ?? '');

        if (($response['success'] ?? false) !== true || $content === '') {
            return [];
        }

        $data = \CMS\Json::decodeArray($content, []);

        return is_array($data) ? $data : [];
    }

    private function resolveCatalogUrl(array $entry, array $keys, string $sourceBase): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($entry[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $normalizedUrl = $this->normalizeMarketplaceUrl($value);
            if ($normalizedUrl !== '') {
                return $normalizedUrl;
            }

            if ($sourceBase !== '' && $this->normalizeMarketplaceUrl($sourceBase) !== '') {
                $resolved = $this->resolveAllowedRelativeMarketplaceUrl($sourceBase, $value);
                if ($resolved !== '') {
                    return $resolved;
                }
            }
        }

        return '';
    }

    /**
     * Basis für relative Katalog-URLs: konfigurierte Plugins-Basis,
     * sonst das Verzeichnis des Plugins-Index.
     */
    private function resolveSourceBase(string $registryUrl): string
    {
        $base = $this->normalizeMarketplaceUrl(rtrim(MarketplaceEndpoints::pluginBaseUrl(), '/'));

        return $base !== '' ? $base : $this->resolveBasePath($registryUrl);
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

    private function normalizePluginSlug(string $slug): string
    {
        return (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
    }

    private function findCatalogPlugin(string $slug): ?array
    {
        foreach ($this->loadRegistry() as $plugin) {
            if ($this->normalizePluginSlug((string) ($plugin['slug'] ?? '')) === $slug) {
                return $plugin;
            }
        }

        return null;
    }

    private function normalizeBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'ja', 'paid'], true);
    }

    private function canAutoInstall(array $plugin): bool
    {
        $downloadUrl = trim((string)($plugin['download_url'] ?? ''));
        $integrityHash = $this->resolveIntegrityHash($plugin);

        return $downloadUrl !== ''
            && $integrityHash !== ''
            && $this->meetsEnvironmentRequirements($plugin)
            && $this->isAllowedPackageSize($plugin)
            && $this->isAllowedMarketplaceUrl($downloadUrl)
            && $this->hasAllowedArchiveExtension($downloadUrl);
    }

    private function resolveIntegrityHash(array $plugin): string
    {
        foreach (['sha256', 'checksum_sha256'] as $key) {
            $value = strtolower(trim((string)($plugin[$key] ?? '')));
            if ($value !== '' && preg_match('/^[0-9a-f]{64}$/', $value) === 1) {
                return $value;
            }
        }

        return '';
    }

    private function getManualInstallReason(array $plugin): string
    {
        $compatibilityReason = $this->getCompatibilityFailureReason($plugin);
        if ($compatibilityReason !== '') {
            return $compatibilityReason;
        }

        $purchaseUrl = trim((string)($plugin['purchase_url'] ?? ''));
        if ($this->normalizeBooleanValue($plugin['is_paid'] ?? false) && $purchaseUrl !== '') {
            return 'Kostenpflichtiges Plugin – bitte zuerst über den Marketplace erwerben oder anfragen.';
        }

        $downloadUrl = trim((string)($plugin['download_url'] ?? ''));
        if ($downloadUrl === '') {
            return 'Keine Download-URL verfügbar. Plugin muss manuell installiert werden.';
        }

        if (!$this->isAllowedPackageSize($plugin)) {
            return 'Paket überschreitet das Auto-Install-Limit von ' . $this->formatBytesLabel(self::MAX_PACKAGE_SIZE_BYTES) . '.';
        }

        if (!$this->isAllowedMarketplaceUrl($downloadUrl)) {
            return 'Download-URL liegt außerhalb der erlaubten Marketplace-Hosts.';
        }

        if (!$this->hasAllowedArchiveExtension($downloadUrl)) {
            return 'Download-Archiv muss eine erlaubte Endung (' . implode(', ', self::ALLOWED_ARCHIVE_EXTENSIONS) . ') besitzen.';
        }

        return 'Für die automatische Installation fehlt eine gültige SHA-256-Prüfsumme. Bitte Paket manuell prüfen und installieren.';
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

    private function isAllowedMarketplaceUrl(string $url): bool
    {
        return $this->normalizeMarketplaceUrl($url) !== '';
    }

    private function normalizeMarketplaceUrl(string $url): string
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

    private function meetsEnvironmentRequirements(array $plugin): bool
    {
        return $this->getCompatibilityFailureReason($plugin) === '';
    }

    private function getCompatibilityFailureReason(array $plugin): string
    {
        $requiresCms = trim((string) ($plugin['requires_cms'] ?? ''));
        $requiresPhp = trim((string) ($plugin['requires_php'] ?? ''));
        $currentCms = defined('CMS_VERSION')
            ? (string) CMS_VERSION
            : (class_exists('\CMS\\Version') ? (string) \CMS\Version::CURRENT : '0.0.0');

        if ($requiresCms !== '' && version_compare($currentCms, $requiresCms, '<')) {
            return 'Plugin erfordert mindestens 365CMS ' . $requiresCms . '.';
        }

        if ($requiresPhp !== '' && version_compare(PHP_VERSION, $requiresPhp, '<')) {
            return 'Plugin erfordert mindestens PHP ' . $requiresPhp . '.';
        }

        return '';
    }

    private function resolvePluginsDir(): string
    {
        if (defined('PLUGIN_PATH')) {
            return rtrim((string) PLUGIN_PATH, '/\\') . DIRECTORY_SEPARATOR;
        }

        if (defined('PLUGINS_PATH')) {
            return rtrim((string) PLUGINS_PATH, '/\\') . DIRECTORY_SEPARATOR;
        }

        return defined('ABSPATH') ? ABSPATH . 'plugins/' : '';
    }

    /** @return array<int, string> */
    private function buildInstallContextDetails(string $slug, string $downloadUrl, string $integrityHash, array $plugin): array
    {
        return array_values(array_filter([
            'Slug: ' . $slug,
            $downloadUrl !== '' ? 'Quelle: ' . $downloadUrl : '',
            !empty($plugin['package_size']) ? 'Paketgröße: ' . $this->formatBytesLabel($this->normalizePackageSize($plugin['package_size'])) : '',
            $downloadUrl !== '' ? 'Archiv-Endung erlaubt: ' . ($this->hasAllowedArchiveExtension($downloadUrl) ? 'ja' : 'nein') : '',
            $integrityHash !== '' ? 'SHA-256: ' . substr($integrityHash, 0, 12) . '…' : '',
            !empty($plugin['requires_cms']) ? '365CMS ab: ' . (string) $plugin['requires_cms'] : '',
            !empty($plugin['requires_php']) ? 'PHP ab: ' . (string) $plugin['requires_php'] : '',
        ]));
    }

    /** @return array<string, mixed> */
    private function buildInstallErrorData(string $slug, string $downloadUrl, string $integrityHash, array $plugin): array
    {
        return [
            'slug' => $slug,
            'download_url' => $downloadUrl,
            'download_host' => strtolower((string) parse_url($downloadUrl, PHP_URL_HOST)),
            'archive_extension' => strtolower((string) pathinfo((string) parse_url($downloadUrl, PHP_URL_PATH), PATHINFO_EXTENSION)),
            'sha256' => $integrityHash,
            'package_size_bytes' => $this->normalizePackageSize($plugin['package_size'] ?? null),
            'requires_cms' => (string) ($plugin['requires_cms'] ?? ''),
            'requires_php' => (string) ($plugin['requires_php'] ?? ''),
        ];
    }

    private function buildInstallFailureResult(string $message, string $errorCode, array $details = [], array $errorData = []): array
    {
        $context = [
            'source' => '/admin/plugin-marketplace',
            'title' => 'Plugin Marketplace',
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

    private function normalizePackageSize(mixed $value): int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            return max(0, (int) $value);
        }

        return 0;
    }

    private function isAllowedPackageSize(array $plugin): bool
    {
        $packageSize = $this->normalizePackageSize($plugin['package_size'] ?? null);

        return $packageSize === 0 || $packageSize <= self::MAX_PACKAGE_SIZE_BYTES;
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

    private function resolveLocalRegistryPath(): string
    {
        $pluginsDir = $this->resolvePluginsDir();
        if ($pluginsDir === '') {
            return '';
        }

        $registryRoot = realpath(dirname(rtrim($pluginsDir, '/\\')));
        if ($registryRoot === false || !is_dir($registryRoot) || is_link($registryRoot)) {
            return '';
        }

        $registryPath = $registryRoot . DIRECTORY_SEPARATOR . 'index.json';
        return is_file($registryPath) && !is_link($registryPath) ? $registryPath : '';
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
