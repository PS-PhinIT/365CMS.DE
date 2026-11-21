<?php
declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

final class VendorRegistry
{
    private static ?self $instance = null;

    /** @var array<string, bool> */
    private array $loadedPackages = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public function getDiagnostics(): array
    {
        $this->loadAssetsAutoloader();

        $autoload = $this->getAutoloadDiagnostics();
        $packages = $this->getPackageDiagnostics();
        $bundles = $this->getBundledLibraryDiagnostics();
        $platform = $this->getBundledPlatformDiagnostics();
        $moduleAssets = $this->getModuleAssetDiagnostics();

        return [
            'autoload' => $autoload,
            'packages' => $packages,
            'bundles' => $bundles,
            'platform' => $platform,
            'module_assets' => $moduleAssets,
            'summary' => [
                'managed_total' => count($packages),
                'managed_available' => count(array_filter($packages, static fn(array $package): bool => !empty($package['available']))),
                'managed_loaded' => count(array_filter($packages, static fn(array $package): bool => !empty($package['loaded']))),
                'bundle_total' => count($bundles),
                'bundle_available' => count(array_filter($bundles, static fn(array $bundle): bool => !empty($bundle['available']))),
                'bundle_ready' => count(array_filter($bundles, static fn(array $bundle): bool => !empty($bundle['runtime_ready']))),
                'platform_warning_count' => count(array_filter($platform, static fn(array $entry): bool => empty($entry['cms_compatible']) || empty($entry['runtime_compatible']))),
                'autoload_candidate_count' => count($autoload['candidates'] ?? []),
            ],
        ];
    }

    public function loadAssetsAutoloader(): bool
    {
        if (($this->loadedPackages['assets-autoload'] ?? false) === true) {
            return true;
        }

        foreach ($this->getAssetsAutoloadCandidates() as $autoloadPath) {
            if (!is_file($autoloadPath)) {
                continue;
            }

            require_once $autoloadPath;
            $this->loadedPackages['assets-autoload'] = true;
            return true;
        }

        $this->loadedPackages['assets-autoload'] = false;

        return false;
    }

    public function loadPackage(string $package): bool
    {
        if (array_key_exists($package, $this->loadedPackages)) {
            return $this->loadedPackages[$package];
        }

        $loaded = match ($package) {
            'assets-autoload' => $this->loadAssetsAutoloader(),
            'dompdf' => $this->loadDompdf(),
            'melbahja-seo' => $this->loadMelbahjaSeo(),
            'symfony/ai-platform' => $this->loadAssetsAutoloader(),
            'symfony-contracts' => $this->loadAssetsAutoloader(),
            default => false,
        };

        $this->loadedPackages[$package] = $loaded;

        return $loaded;
    }

    private function loadDompdf(): bool
    {
        $autoloadPath = ABSPATH . 'vendor' . DIRECTORY_SEPARATOR . 'dompdf' . DIRECTORY_SEPARATOR . 'autoload.php';

        if (!is_file($autoloadPath)) {
            return false;
        }

        require_once $autoloadPath;

        return class_exists(\Dompdf\Dompdf::class);
    }

    private function loadMelbahjaSeo(): bool
    {
        $this->loadAssetsAutoloader();

        $baseDir = ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'melbahja-seo' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
        if (!is_dir($baseDir)) {
            return false;
        }

        foreach ($this->getMelbahjaSeoRequiredFiles() as $relativePath) {
            $absolutePath = $baseDir . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (is_file($absolutePath)) {
                require_once $absolutePath;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function getAssetsAutoloadCandidates(): array
    {
        return [
            ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'autoload.php',
            dirname(ABSPATH) . DIRECTORY_SEPARATOR . 'ASSETS' . DIRECTORY_SEPARATOR . 'autoload.php',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getAutoloadDiagnostics(): array
    {
        $activePath = null;
        $candidates = [];

        foreach ($this->getAssetsAutoloadCandidates() as $candidate) {
            $exists = is_file($candidate);
            if ($activePath === null && $exists) {
                $activePath = $candidate;
            }

            $candidates[] = [
                'path' => $this->normalizeDisplayedPath($candidate),
                'exists' => $exists,
                'active' => $activePath === $candidate,
            ];
        }

        return [
            'loaded' => defined('CMS_VENDOR_PATH') || (($this->loadedPackages['assets-autoload'] ?? false) === true),
            'active_path' => $activePath !== null ? $this->normalizeDisplayedPath($activePath) : null,
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getPackageDiagnostics(): array
    {
        $packages = [];

        foreach ($this->getManagedPackageDefinitions() as $package => $definition) {
            $loadStatus = $this->getPackageLoadStatus($package);
            $source = $this->getAssetSourceLink($package);
            $packages[] = [
                'package' => $package,
                'label' => $definition['label'],
                'path' => $this->normalizeDisplayedPath($definition['path']),
                'available' => $this->pathExists($definition['path'], $definition['path_type']),
                'loaded' => $loadStatus['loaded'],
                'notes' => $definition['notes'],
                'runtime_error' => $loadStatus['error'],
                'source_url' => $source['url'],
                'source_label' => $source['label'],
            ];
        }

        return $packages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getBundledLibraryDiagnostics(): array
    {
        $diagnostics = [];

        foreach ($this->getBundledLibraryDefinitions() as $definition) {
            if (!$this->isRuntimeBundledLibraryDefinition($definition)) {
                continue;
            }

            $source = $this->getAssetSourceLink((string) ($definition['package'] ?? ''));

            $available = true;
            foreach ($definition['paths'] as $path) {
                if (!$this->pathExists($path['path'], $path['type'])) {
                    $available = false;
                    break;
                }
            }

            $runtimeStatus = $available
                ? $this->getRuntimeSymbolStatus($definition['symbol'], $definition['symbol_type'])
                : $this->getDefaultRuntimeStatusFallback();

            if ($runtimeStatus['ready'] && ($definition['probe'] ?? null) instanceof \Closure) {
                $runtimeStatus = $this->runFunctionalProbe($definition['probe']);
            }

            $diagnostics[] = [
                'package' => $definition['package'],
                'label' => $definition['label'],
                'paths' => array_map(fn(array $path): string => $this->normalizeDisplayedPath($path['path']), $definition['paths']),
                'available' => $available,
                'runtime_ready' => $runtimeStatus['ready'],
                'notes' => $definition['notes'],
                'runtime_error' => $runtimeStatus['error'],
                'runtime_label' => $runtimeStatus['label'],
                'runtime_class' => $runtimeStatus['class'],
                'source_url' => $source['url'],
                'source_label' => $source['label'],
            ];
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function isRuntimeBundledLibraryDefinition(array $definition): bool
    {
        return in_array((string) ($definition['symbol_type'] ?? ''), ['class', 'interface', 'path'], true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getModuleAssetDiagnostics(): array
    {
        $diagnostics = [];

        foreach ($this->getModuleAssetDefinitions() as $definition) {
            $moduleSlug = (string) ($definition['module_slug'] ?? '');
            $source = $this->getAssetSourceLink((string) ($definition['source_package'] ?? ''));
            $available = true;

            foreach ((array) ($definition['paths'] ?? []) as $path) {
                if (!$this->pathExists((string) ($path['path'] ?? ''), (string) ($path['type'] ?? 'file'))) {
                    $available = false;
                    break;
                }
            }

            $moduleEnabled = $this->isCoreModuleEnabled($moduleSlug);

            $diagnostics[] = [
                'asset' => (string) ($definition['asset'] ?? ''),
                'module_slug' => $moduleSlug,
                'module_label' => (string) ($definition['module_label'] ?? $moduleSlug),
                'paths' => array_map(
                    fn(array $path): string => $this->normalizeDisplayedPath((string) ($path['path'] ?? '')),
                    (array) ($definition['paths'] ?? [])
                ),
                'available' => $available,
                'module_enabled' => $moduleEnabled,
                'activation_label' => $moduleEnabled ? 'Modul aktiv' : 'Modul aus',
                'activation_class' => $moduleEnabled ? 'success' : 'secondary',
                'notes' => (string) ($definition['notes'] ?? ''),
                'source_url' => $source['url'],
                'source_label' => $source['label'],
            ];
        }

        return $diagnostics;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getBundledPlatformDiagnostics(): array
    {
        $requiredPhpVersion = Version::minimumPhp();
        $diagnostics = [];

        foreach ($this->getBundledPlatformManifestDefinitions() as $packageName => $manifestPath) {
            $platformStatus = $this->getPlatformManifestStatus($manifestPath);
            $bundlePhpVersion = $platformStatus['required_php'];
            $source = $this->getAssetSourceLink($packageName);
            $diagnostics[] = [
                'package' => $packageName,
                'manifest' => $this->normalizeDisplayedPath($manifestPath),
                'exists' => is_file($manifestPath),
                'required_php' => $bundlePhpVersion,
                'cms_required_php' => $requiredPhpVersion,
                'runtime_php' => PHP_VERSION,
                'cms_compatible' => $bundlePhpVersion === null ? null : version_compare($requiredPhpVersion, $bundlePhpVersion, '>='),
                'runtime_compatible' => $bundlePhpVersion === null ? null : version_compare(PHP_VERSION, $bundlePhpVersion, '>='),
                'runtime_error' => $platformStatus['error'],
                'source_url' => $source['url'],
                'source_label' => $source['label'],
            ];
        }

        return $diagnostics;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getModuleAssetDefinitions(): array
    {
        $assets = ABSPATH . 'assets' . DIRECTORY_SEPARATOR;

        return [
            [
                'asset' => 'SEO Editor',
                'module_slug' => 'seo',
                'module_label' => 'SEO',
                'paths' => [['path' => $assets . 'js' . DIRECTORY_SEPARATOR . 'admin-seo-editor.js', 'type' => 'file']],
                'notes' => 'SEO-Felder und Meta-Helfer in Seiten- und Beitragseditoren.',
                'source_package' => 'cms-js',
            ],
            [
                'asset' => 'SEO Redirect Tools',
                'module_slug' => 'seo',
                'module_label' => 'SEO',
                'paths' => [['path' => $assets . 'js' . DIRECTORY_SEPARATOR . 'admin-seo-redirects.js', 'type' => 'file']],
                'notes' => 'Admin-Helfer für Weiterleitungen und 404-Monitor.',
                'source_package' => 'cms-js',
            ],
            [
                'asset' => 'Analytics-Loader (Consent)',
                'module_slug' => 'seo',
                'module_label' => 'SEO',
                'paths' => [['path' => $assets . 'js' . DIRECTORY_SEPARATOR . 'cms-analytics.js', 'type' => 'file']],
                'notes' => 'Lädt GA4/Matomo/GTM/Meta Pixel erst nach Cookie-Einwilligung; CSP nur für konfigurierte Anbieter-Hosts.',
                'source_package' => 'cms-js',
            ],
            [
                'asset' => 'Core Web Vitals Tracker',
                'module_slug' => 'seo',
                'module_label' => 'SEO',
                'paths' => [['path' => $assets . 'js' . DIRECTORY_SEPARATOR . 'web-vitals.js', 'type' => 'file']],
                'notes' => 'Frontend-Tracking für Core Web Vitals, nur wenn SEO-Modul und Analytics-Consent aktiv sind.',
                'source_package' => 'cms-js',
            ],
            [
                'asset' => 'Legal Sites Admin',
                'module_slug' => 'legal',
                'module_label' => 'Recht',
                'paths' => [['path' => $assets . 'js' . DIRECTORY_SEPARATOR . 'admin-legal-sites.js', 'type' => 'file']],
                'notes' => 'Admin-Interaktionen für Legal Sites.',
                'source_package' => 'cms-js',
            ],
            [
                'asset' => 'Cookie Manager Admin',
                'module_slug' => 'legal',
                'module_label' => 'Recht',
                'paths' => [['path' => $assets . 'js' . DIRECTORY_SEPARATOR . 'admin-cookie-manager.js', 'type' => 'file']],
                'notes' => 'Admin-Interaktionen für Kategorien, Services und Consent-Scans.',
                'source_package' => 'cms-js',
            ],
            [
                'asset' => 'Datenschutzanfragen Admin',
                'module_slug' => 'legal',
                'module_label' => 'Recht',
                'paths' => [['path' => $assets . 'js' . DIRECTORY_SEPARATOR . 'admin-data-requests.js', 'type' => 'file']],
                'notes' => 'Admin-Interaktionen für Auskunfts- und Löschanfragen.',
                'source_package' => 'cms-js',
            ],
            [
                'asset' => 'Cookie Consent Styles',
                'module_slug' => 'legal',
                'module_label' => 'Recht',
                'paths' => [['path' => $assets . 'css' . DIRECTORY_SEPARATOR . 'cms-cookie-consent.css', 'type' => 'file']],
                'notes' => 'Frontend-Styles für Banner und Cookie-Einstellungsseite.',
                'source_package' => 'cms-css',
            ],
            [
                'asset' => 'Cookie Consent Init',
                'module_slug' => 'legal',
                'module_label' => 'Recht',
                'paths' => [['path' => $assets . 'js' . DIRECTORY_SEPARATOR . 'cookieconsent-init.js', 'type' => 'file']],
                'notes' => 'Frontend-Initialisierung für Banner und Präferenzdialog.',
                'source_package' => 'cms-js',
            ],
        ];
    }

    /**
     * @return array{url: string, label: string}
     */
    private function getAssetSourceLink(string $package): array
    {
        $links = [
            'assets-autoload' => ['url' => 'https://github.com/PS-PhinIT/365CMS.DE', 'label' => 'GitHub'],
            'dompdf' => ['url' => 'https://github.com/dompdf/dompdf', 'label' => 'GitHub'],
            'melbahja-seo' => ['url' => 'https://github.com/melbahja/Seo', 'label' => 'GitHub'],
            'symfony/ai-platform' => ['url' => 'https://github.com/symfony/ai', 'label' => 'GitHub'],
            'symfony-contracts' => ['url' => 'https://github.com/symfony/contracts', 'label' => 'GitHub'],
            'htmlpurifier' => ['url' => 'https://github.com/ezyang/htmlpurifier', 'label' => 'GitHub'],
            'league/commonmark' => ['url' => 'https://github.com/thephpleague/commonmark', 'label' => 'GitHub'],
            'tntsearch' => ['url' => 'https://github.com/teamtnt/tntsearch', 'label' => 'GitHub'],
            'carbon' => ['url' => 'https://carbon.nesbot.com/', 'label' => 'Website'],
            'symfony/translation' => ['url' => 'https://github.com/symfony/translation', 'label' => 'GitHub'],
            'lbuchs/webauthn' => ['url' => 'https://github.com/lbuchs/WebAuthn', 'label' => 'GitHub'],
            'robthree/twofactorauth' => ['url' => 'https://github.com/RobThree/TwoFactorAuth', 'label' => 'GitHub'],
            'ldaprecord' => ['url' => 'https://github.com/DirectoryTree/LdapRecord', 'label' => 'GitHub'],
            'firebase/php-jwt' => ['url' => 'https://github.com/firebase/php-jwt', 'label' => 'GitHub'],
            'psr/log' => ['url' => 'https://www.php-fig.org/psr/psr-3/', 'label' => 'Website'],
            'symfony/mime' => ['url' => 'https://github.com/symfony/mime', 'label' => 'GitHub'],
            'symfony/mailer' => ['url' => 'https://github.com/symfony/mailer', 'label' => 'GitHub'],
            'psr/event-dispatcher' => ['url' => 'https://www.php-fig.org/psr/psr-14/', 'label' => 'Website'],
            'editorjs' => ['url' => 'https://editorjs.io/', 'label' => 'Website'],
            'photoswipe' => ['url' => 'https://photoswipe.com/', 'label' => 'Website'],
            'suneditor' => ['url' => 'https://github.com/JiHong88/suneditor', 'label' => 'GitHub'],
            'tabler' => ['url' => 'https://tabler.io/', 'label' => 'Website'],
            'cms-css' => ['url' => 'https://github.com/PS-PhinIT/365CMS.DE', 'label' => 'GitHub'],
            'cms-js' => ['url' => 'https://github.com/PS-PhinIT/365CMS.DE', 'label' => 'GitHub'],
            'cms-images' => ['url' => 'https://github.com/PS-PhinIT/365CMS.DE', 'label' => 'GitHub'],
            'tabler-icons' => ['url' => 'https://tabler.io/icons', 'label' => 'Website'],
            'dompurify' => ['url' => 'https://github.com/cure53/DOMPurify', 'label' => 'GitHub'],
            'bacon/bacon-qr-code' => ['url' => 'https://github.com/Bacon/BaconQrCode', 'label' => 'GitHub'],
            'symfony/yaml' => ['url' => 'https://github.com/symfony/yaml', 'label' => 'GitHub'],
            'symfony/clock' => ['url' => 'https://github.com/symfony/clock', 'label' => 'GitHub'],
            'symfony/event-dispatcher' => ['url' => 'https://github.com/symfony/event-dispatcher', 'label' => 'GitHub'],
            'symfony/serializer' => ['url' => 'https://github.com/symfony/serializer', 'label' => 'GitHub'],
            'symfony/property-info' => ['url' => 'https://github.com/symfony/property-info', 'label' => 'GitHub'],
            'symfony/property-access' => ['url' => 'https://github.com/symfony/property-access', 'label' => 'GitHub'],
            'symfony/type-info' => ['url' => 'https://github.com/symfony/type-info', 'label' => 'GitHub'],
            'symfony/uid' => ['url' => 'https://github.com/symfony/uid', 'label' => 'GitHub'],
            'symfony/string' => ['url' => 'https://github.com/symfony/string', 'label' => 'GitHub'],
            'symfony/polyfills' => ['url' => 'https://github.com/symfony/polyfill', 'label' => 'GitHub'],
            'egulias/email-validator' => ['url' => 'https://github.com/egulias/EmailValidator', 'label' => 'GitHub'],
            'psr/container' => ['url' => 'https://www.php-fig.org/psr/psr-11/', 'label' => 'Website'],
        ];

        return $links[$package] ?? ['url' => 'https://github.com/PS-PhinIT/365CMS.DE', 'label' => 'GitHub'];
    }

    private function isCoreModuleEnabled(string $slug): bool
    {
        if ($slug === '' || !class_exists('\\CMS\\Services\\CoreModuleService')) {
            return true;
        }

        try {
            return \CMS\Services\CoreModuleService::getInstance()->isModuleEnabled($slug);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return array<string, array{label: string, path: string, path_type: string, notes: string}>
     */
    private function getManagedPackageDefinitions(): array
    {
        return [
            'assets-autoload' => [
                'label' => 'Zentraler Assets-Autoloader',
                'path' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'autoload.php',
                'path_type' => 'file',
                'notes' => 'Lädt die produktiven Bundles aus CMS/assets/.',
            ],
            'dompdf' => [
                'label' => 'Dompdf PDF-Renderer',
                'path' => ABSPATH . 'vendor' . DIRECTORY_SEPARATOR . 'dompdf' . DIRECTORY_SEPARATOR . 'autoload.php',
                'path_type' => 'file',
                'notes' => 'Sonderpfad für PDF-Rendering außerhalb des Assets-Autoloaders. Benötigt ext-mbstring; PNG/GIF/WebP-Bilder zusätzlich ext-gd. Eigene Bilder (uploads/, assets/) werden lokal eingebettet, externe Bilder bleiben gesperrt (isRemoteEnabled=false).',
            ],
            'melbahja-seo' => [
                'label' => 'melbahja/seo',
                'path' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'melbahja-seo' . DIRECTORY_SEPARATOR . 'src',
                'path_type' => 'dir',
                'notes' => 'Schema-, Sitemap- und Indexing-Bundle für SEO-Funktionen.',
            ],
            'symfony/ai-platform' => [
                'label' => 'Symfony AI Platform',
                'path' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'ai-platform',
                'path_type' => 'dir',
                'notes' => 'Produktiv gebündelte AI-Plattform-Basis unter CMS/assets/ai-platform für Core-Adapter und AI-Services-Pfade.',
            ],
            'symfony-contracts' => [
                'label' => 'Symfony Contracts',
                'path' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'symfony-contracts',
                'path_type' => 'dir',
                'notes' => 'Vollständige symfony/contracts 3.6.1 für Mailer, Translation, EventDispatcher und AI Platform.',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getBundledLibraryDefinitions(): array
    {
        $assets = ABSPATH . 'assets' . DIRECTORY_SEPARATOR;
        $dir = static fn(string ...$parts): array => ['path' => $assets . implode(DIRECTORY_SEPARATOR, $parts), 'type' => 'dir'];
        $file = static fn(string ...$parts): array => ['path' => $assets . implode(DIRECTORY_SEPARATOR, $parts), 'type' => 'file'];

        return [
            [
                'package' => 'htmlpurifier',
                'label' => 'HTMLPurifier',
                'paths' => [$file('htmlpurifier', 'HTMLPurifier.auto.php')],
                'symbol' => 'HTMLPurifier',
                'symbol_type' => 'class',
                'notes' => 'HTML-Sanitizing für Rich-Content-Pfade.',
            ],
            [
                'package' => 'league/commonmark',
                'label' => 'CommonMark (Bündel)',
                'paths' => [$file('commonmark', 'commonmark.php'), $file('commonmark', 'LICENSE')],
                'symbol' => '\\CMS\\Vendor\\League\\CommonMark\\MarkdownConverter',
                'symbol_type' => 'class',
                'probe' => static function (): void {
                    $environment = new \CMS\Vendor\League\CommonMark\Environment\Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
                    $environment->addExtension(new \CMS\Vendor\League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
                    $html = (string) (new \CMS\Vendor\League\CommonMark\MarkdownConverter($environment))->convert('**x** <b>');
                    if (!str_contains($html, '<strong>x</strong>') || str_contains($html, '<b>')) {
                        throw new \RuntimeException('CommonMark-Bündel rendert nicht wie erwartet.');
                    }
                },
                'notes' => 'Markdown-Renderer der Community (ContentRenderer): league/commonmark 2.10.7 mit league/config 1.2.0, dflydev/dot-access-data 3.0.3, nette/schema 1.4.0 und nette/utils 4.1.6 als eine gescopte Datei (Namensraum CMS\\Vendor\\, PHP ab 8.2). Gebaut aus .github/deps/composer.lock, Prüfsummen in DOC/assets/commonmark/README.md.',
            ],
            [
                'package' => 'tntsearch',
                'label' => 'TNTSearch',
                'paths' => [$dir('tntsearchsrc')],
                'symbol' => '\\TeamTNT\\TNTSearch\\TNTSearch',
                'symbol_type' => 'class',
                'notes' => 'Volltextsuche für SearchService.',
            ],
            [
                'package' => 'carbon',
                'label' => 'Carbon',
                'paths' => [$dir('Carbon', 'src', 'Carbon'), $dir('Carbon', 'lazy', 'Carbon')],
                'symbol' => '\\Carbon\\Carbon',
                'symbol_type' => 'class',
                'probe' => static fn(): string => \Carbon\Carbon::now()->subMinute()->locale('de')->diffForHumans(),
                'notes' => 'Datums-/Zeit-Helfer (time_ago). Benötigt symfony/clock, psr/clock und symfony/translation.',
            ],
            [
                'package' => 'symfony/translation',
                'label' => 'Symfony Translation',
                'paths' => [$dir('translation')],
                'symbol' => '\\Symfony\\Component\\Translation\\Translator',
                'symbol_type' => 'class',
                'probe' => static function (): void {
                    $translator = new \Symfony\Component\Translation\Translator('de');
                    $translator->addLoader('array', new \Symfony\Component\Translation\Loader\ArrayLoader());
                    $translator->addResource('array', ['probe' => 'ok'], 'de');
                    if ($translator->trans('probe') !== 'ok') {
                        throw new \RuntimeException('Translator liefert keine Katalogwerte.');
                    }
                },
                'notes' => 'I18n-Bundle für TranslationService.',
            ],
            [
                'package' => 'symfony/yaml',
                'label' => 'Symfony Yaml',
                'paths' => [$dir('yaml')],
                'symbol' => '\\Symfony\\Component\\Yaml\\Yaml',
                'symbol_type' => 'class',
                'probe' => static fn(): mixed => \Symfony\Component\Yaml\Yaml::parse("default:\n  probe: ok"),
                'notes' => 'YAML-Parser für die Sprachkataloge in CMS/lang/.',
            ],
            [
                'package' => 'symfony/clock',
                'label' => 'Symfony Clock',
                'paths' => [$dir('clock'), $dir('psr', 'Clock')],
                'symbol' => '\\Symfony\\Component\\Clock\\Clock',
                'symbol_type' => 'class',
                'notes' => 'Clock-Abstraktion für Carbon und AI Platform (inkl. psr/clock).',
            ],
            [
                'package' => 'lbuchs/webauthn',
                'label' => 'WebAuthn',
                'paths' => [$dir('webauthn')],
                'symbol' => '\\lbuchs\\WebAuthn\\WebAuthn',
                'symbol_type' => 'class',
                'notes' => 'Passkey-/FIDO2-Unterstützung.',
            ],
            [
                'package' => 'robthree/twofactorauth',
                'label' => 'TwoFactorAuth',
                'paths' => [$dir('twofactorauth'), $dir('bacon-qr-code'), $dir('dasprid-enum')],
                'symbol' => '\\RobThree\\Auth\\TwoFactorAuth',
                'symbol_type' => 'class',
                'probe' => static function (): void {
                    $svg = (new \RobThree\Auth\Providers\Qr\BaconQrCodeProvider(2, '#ffffff', '#000000', 'svg'))
                        ->getQRCodeImage('otpauth://totp/probe?secret=JBSWY3DPEHPK3PXP', 120);
                    if (!str_contains($svg, '<svg')) {
                        throw new \RuntimeException('BaconQrCode liefert kein SVG.');
                    }
                },
                'notes' => 'TOTP-/MFA-Bundle; QR-Codes werden lokal per bacon/bacon-qr-code (+ dasprid/enum) als SVG erzeugt.',
            ],
            [
                'package' => 'ldaprecord',
                'label' => 'LdapRecord',
                'paths' => [$dir('ldaprecord'), $dir('psr', 'SimpleCache')],
                'symbol' => '\\LdapRecord\\Connection',
                'symbol_type' => 'class',
                'notes' => 'LDAP-/Verzeichnisintegration über Connection + Query-Builder (nutzt Carbon und psr/simple-cache). Die Eloquent-artigen Models benötigen illuminate/* und werden im Core nicht verwendet.',
            ],
            [
                'package' => 'firebase/php-jwt',
                'label' => 'Firebase JWT',
                'paths' => [$dir('php-jwt')],
                'symbol' => '\\Firebase\\JWT\\JWT',
                'symbol_type' => 'class',
                'notes' => 'JWT-Unterstützung.',
            ],
            [
                'package' => 'psr/log',
                'label' => 'PSR Log',
                'paths' => [$dir('psr', 'Log')],
                'symbol' => '\\Psr\\Log\\LoggerInterface',
                'symbol_type' => 'interface',
                'notes' => 'PSR-3 (vollständig) für Mailer, AI Platform und LdapRecord.',
            ],
            [
                'package' => 'psr/container',
                'label' => 'PSR Container',
                'paths' => [$dir('psr', 'Container')],
                'symbol' => '\\Psr\\Container\\ContainerInterface',
                'symbol_type' => 'interface',
                'notes' => 'PSR-11 für Symfony Service Contracts und TypeInfo.',
            ],
            [
                'package' => 'symfony/mime',
                'label' => 'Symfony Mime',
                'paths' => [$dir('mime')],
                'symbol' => '\\Symfony\\Component\\Mime\\Email',
                'symbol_type' => 'class',
                'probe' => static fn(): string => (new \Symfony\Component\Mime\Address('probe@example.org', 'Probe'))->toString(),
                'notes' => 'Mime-Komponenten für Mail- und Upload-Pfade. Address benötigt egulias/email-validator.',
            ],
            [
                'package' => 'egulias/email-validator',
                'label' => 'Egulias EmailValidator',
                'paths' => [$dir('egulias-email-validator'), $dir('doctrine-lexer')],
                'symbol' => '\\Egulias\\EmailValidator\\EmailValidator',
                'symbol_type' => 'class',
                'probe' => static function (): void {
                    $valid = (new \Egulias\EmailValidator\EmailValidator())
                        ->isValid('probe@example.org', new \Egulias\EmailValidator\Validation\RFCValidation());
                    if (!$valid) {
                        throw new \RuntimeException('RFC-Validierung schlägt für eine gültige Adresse fehl.');
                    }
                },
                'notes' => 'Pflichtabhängigkeit von Symfony Mime/Mailer für SMTP-Versand (inkl. doctrine/lexer).',
            ],
            [
                'package' => 'symfony/mailer',
                'label' => 'Symfony Mailer',
                'paths' => [$dir('mailer')],
                'symbol' => '\\Symfony\\Component\\Mailer\\Mailer',
                'symbol_type' => 'class',
                'probe' => static fn(): object => \Symfony\Component\Mailer\Transport::fromDsn('smtp://localhost:25'),
                'notes' => 'SMTP-Mail-Transport im Core.',
            ],
            [
                'package' => 'symfony/event-dispatcher',
                'label' => 'Symfony EventDispatcher',
                'paths' => [$dir('event-dispatcher'), $dir('psr', 'EventDispatcher')],
                'symbol' => '\\Symfony\\Component\\EventDispatcher\\EventDispatcher',
                'symbol_type' => 'class',
                'notes' => 'Event-Dispatcher für Mailer und AI Platform (inkl. psr/event-dispatcher).',
            ],
            [
                'package' => 'symfony-contracts',
                'label' => 'Symfony Contracts',
                'paths' => [$dir('symfony-contracts')],
                'symbol' => '\\Symfony\\Contracts\\Service\\ResetInterface',
                'symbol_type' => 'interface',
                'notes' => 'symfony/contracts 3.6.1 (Service, Translation, EventDispatcher, HttpClient, Deprecation).',
            ],
            [
                'package' => 'symfony/polyfills',
                'label' => 'Symfony Polyfills',
                'paths' => [
                    $dir('polyfill-mbstring'), $dir('polyfill-ctype'), $dir('polyfill-intl-idn'),
                    $dir('polyfill-intl-normalizer'), $dir('polyfill-intl-grapheme'), $dir('polyfill-uuid'),
                ],
                'symbol' => '\\Symfony\\Polyfill\\Mbstring\\Mbstring',
                'symbol_type' => 'class',
                'probe' => static fn(): string => mb_strtoupper('ä') . idn_to_ascii('bücher.example'),
                'notes' => 'mbstring-, ctype-, intl-idn/-normalizer/-grapheme- und uuid-Polyfills; greifen nur ohne passende PHP-Extension.',
            ],
            [
                'package' => 'dompurify',
                'label' => 'DOMPurify',
                'paths' => [$file('dompurify', 'purify.min.js'), $file('js', 'cms-csp-runtime.js')],
                'symbol' => $assets . 'dompurify' . DIRECTORY_SEPARATOR . 'purify.min.js',
                'symbol_type' => 'path',
                'notes' => 'HTML-Sanitizer der Trusted-Types-default-Policy (cms-csp-runtime.js) für Admin, Member und Frontend.',
            ],
            [
                'package' => 'editorjs',
                'label' => 'Editor.js',
                'paths' => [$dir('editorjs')],
                'symbol' => $assets . 'editorjs' . DIRECTORY_SEPARATOR . 'editorjs.umd.js',
                'symbol_type' => 'path',
                'notes' => 'Produktives Block-Editor-Assetset für Admin und Frontend.',
            ],
            [
                'package' => 'photoswipe',
                'label' => 'PhotoSwipe',
                'paths' => [$dir('photoswipe')],
                'symbol' => $assets . 'photoswipe' . DIRECTORY_SEPARATOR . 'photoswipe.esm.min.js',
                'symbol_type' => 'path',
                'notes' => 'Lightbox-/Galerie-Assets für Frontend-Medienansichten.',
            ],
            [
                'package' => 'suneditor',
                'label' => 'SunEditor',
                'paths' => [$file('suneditor', 'suneditor.min.js'), $file('suneditor', 'css', 'suneditor.min.css'), $file('suneditor', 'lang', 'de.js')],
                'symbol' => $assets . 'suneditor' . DIRECTORY_SEPARATOR . 'suneditor.min.js',
                'symbol_type' => 'path',
                'notes' => 'Legacy-WYSIWYG-Editor im Admin.',
            ],
            [
                'package' => 'tabler',
                'label' => 'Tabler',
                'paths' => [$file('tabler', 'css', 'tabler.min.css'), $file('tabler', 'js', 'tabler.min.js')],
                'symbol' => $assets . 'tabler' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'tabler.min.css',
                'symbol_type' => 'path',
                'notes' => 'Primäres Admin-/Member-UI-Framework.',
            ],
            [
                'package' => 'tabler-icons',
                'label' => 'Tabler Icons',
                'paths' => [$file('tabler-icons', 'tabler-icons.min.css'), $file('tabler-icons', 'fonts', 'tabler-icons.woff2')],
                'symbol' => $assets . 'tabler-icons' . DIRECTORY_SEPARATOR . 'tabler-icons.min.css',
                'symbol_type' => 'path',
                'notes' => 'Lokaler Icon-Webfont für den Admin (woff2/woff/ttf).',
            ],
            [
                'package' => 'cms-css',
                'label' => '365CMS CSS-Assets',
                'paths' => [$dir('css')],
                'symbol' => $assets . 'css',
                'symbol_type' => 'path',
                'notes' => 'Produktive Stylesheets für Admin, Frontend und Member-Bereich.',
            ],
            [
                'package' => 'cms-js',
                'label' => '365CMS JS-Assets',
                'paths' => [$dir('js')],
                'symbol' => $assets . 'js',
                'symbol_type' => 'path',
                'notes' => 'Produktive JavaScript-Helfer und Initializer.',
            ],
            [
                'package' => 'cms-images',
                'label' => '365CMS Bild-Assets',
                'paths' => [$dir('images')],
                'symbol' => $assets . 'images',
                'symbol_type' => 'path',
                'notes' => 'Logos und Branding-Assets.',
            ],
            [
                'package' => 'symfony/ai-platform',
                'label' => 'Symfony AI Platform',
                'paths' => [$dir('ai-platform', 'src')],
                'symbol' => '\\Symfony\\AI\\Platform\\PlatformInterface',
                'symbol_type' => 'interface',
                'probe' => static fn(): object => new \Symfony\AI\Platform\Message\MessageBag(
                    \Symfony\AI\Platform\Message\Message::forSystem('probe'),
                    \Symfony\AI\Platform\Message\Message::ofUser('probe')
                ),
                'notes' => 'AI-Platform-Basis inkl. Serializer, PropertyInfo/-Access, TypeInfo, Uid, String, enum-helper und phpDocumentor-Reflection.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getBundledPlatformManifestDefinitions(): array
    {
        return [
            'symfony/ai-platform' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'ai-platform' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/mailer' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'mailer' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/mime' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'mime' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/translation' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'translation' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/yaml' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'yaml' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/clock' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'clock' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/event-dispatcher' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'event-dispatcher' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/serializer' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'serializer' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/property-info' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'property-info' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/property-access' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'property-access' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/type-info' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'type-info' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/uid' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'uid' . DIRECTORY_SEPARATOR . 'composer.json',
            'symfony/string' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'string' . DIRECTORY_SEPARATOR . 'composer.json',
            'egulias/email-validator' => ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'egulias-email-validator' . DIRECTORY_SEPARATOR . 'composer.json',
        ];
    }

    private function isPackageLoaded(string $package): bool
    {
        return match ($package) {
            'assets-autoload' => defined('CMS_VENDOR_PATH') || (($this->loadedPackages['assets-autoload'] ?? false) === true),
            'dompdf' => (($this->loadedPackages['dompdf'] ?? false) === true) || class_exists(\Dompdf\Dompdf::class, false),
            'melbahja-seo' => (($this->loadedPackages['melbahja-seo'] ?? false) === true) || class_exists(\Melbahja\Seo\Schema::class, true),
            'symfony/ai-platform' => is_dir(ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'ai-platform')
                && interface_exists(\Symfony\AI\Platform\PlatformInterface::class, true),
            'symfony-contracts' => is_dir(ABSPATH . 'assets' . DIRECTORY_SEPARATOR . 'symfony-contracts')
                && interface_exists(\Symfony\Contracts\Translation\TranslatorInterface::class, true),
            default => false,
        };
    }

    /**
     * @return array{loaded: bool, error: ?string}
     */
    private function getPackageLoadStatus(string $package): array
    {
        try {
            return [
                'loaded' => $this->isPackageLoaded($package),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'loaded' => false,
                'error' => $this->formatRuntimeError($e),
            ];
        }
    }

    private function isRuntimeSymbolReady(string $symbol, string $type): bool
    {
        return match ($type) {
            'interface' => interface_exists($symbol, true),
            'path' => file_exists($symbol),
            'legacy', 'staging', 'reference' => false,
            default => class_exists($symbol, true),
        };
    }

    /**
     * @return array{ready: bool, error: ?string, label: string, class: string}
     */
    private function getRuntimeSymbolStatus(string $symbol, string $type): array
    {
        try {
            if ($type === 'staging') {
                return [
                    'ready' => false,
                    'error' => null,
                    'label' => 'nur Staging',
                    'class' => 'secondary',
                ];
            }

            if ($type === 'reference') {
                return [
                    'ready' => false,
                    'error' => null,
                    'label' => 'Referenz',
                    'class' => 'secondary',
                ];
            }

            if ($type === 'legacy') {
                return [
                    'ready' => false,
                    'error' => null,
                    'label' => 'Legacy',
                    'class' => 'warning',
                ];
            }

            if ($type === 'path') {
                $ready = $this->isRuntimeSymbolReady($symbol, $type);

                return [
                    'ready' => $ready,
                    'error' => null,
                    'label' => $ready ? 'verfügbar' : 'fehlt',
                    'class' => $ready ? 'primary' : 'secondary',
                ];
            }

            $ready = $this->isRuntimeSymbolReady($symbol, $type);

            return [
                'ready' => $ready,
                'error' => null,
                'label' => $ready ? 'auflösbar' : 'nicht aufgelöst',
                'class' => $ready ? 'success' : 'secondary',
            ];
        } catch (\Throwable $e) {
            return [
                'ready' => false,
                'error' => $this->formatRuntimeError($e),
                'label' => 'Fehler',
                'class' => 'warning',
            ];
        }
    }

    /**
     * Reine class_exists-Prüfungen erkennen fehlende transitive Abhängigkeiten
     * nicht (z. B. Carbon ohne symfony/clock). Der Probe führt deshalb einen
     * minimalen echten Aufruf der Library aus.
     *
     * @return array{ready: bool, error: ?string, label: string, class: string}
     */
    private function runFunctionalProbe(\Closure $probe): array
    {
        try {
            $probe();

            return [
                'ready' => true,
                'error' => null,
                'label' => 'funktionsfähig',
                'class' => 'success',
            ];
        } catch (\Throwable $e) {
            return [
                'ready' => false,
                'error' => $this->formatRuntimeError($e),
                'label' => 'Abhängigkeit fehlt',
                'class' => 'danger',
            ];
        }
    }

    /**
     * @return array{ready: bool, error: ?string, label: string, class: string}
     */
    private function getDefaultRuntimeStatusFallback(): array
    {
        return [
            'ready' => false,
            'error' => null,
            'label' => 'nicht aufgelöst',
            'class' => 'secondary',
        ];
    }

    private function pathExists(string $path, string $type): bool
    {
        return match ($type) {
            'dir' => is_dir($path),
            default => is_file($path),
        };
    }

    /**
     * @return array{required_php: ?string, error: ?string}
     */
    private function getPlatformManifestStatus(string $manifestPath): array
    {
        try {
            return [
                'required_php' => $this->extractMinimumPhpVersion($manifestPath),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'required_php' => null,
                'error' => $this->formatRuntimeError($e),
            ];
        }
    }

    private function normalizeDisplayedPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $normalizedAbs = str_replace('\\', '/', ABSPATH);
        $normalizedRepo = str_replace('\\', '/', dirname(ABSPATH) . DIRECTORY_SEPARATOR);

        if (str_starts_with($normalized, $normalizedAbs)) {
            return 'CMS/' . ltrim(substr($normalized, strlen($normalizedAbs)), '/');
        }

        if (str_starts_with($normalized, $normalizedRepo)) {
            return ltrim(substr($normalized, strlen($normalizedRepo)), '/');
        }

        return $normalized;
    }

    private function extractMinimumPhpVersion(string $manifestPath): ?string
    {
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return null;
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            return null;
        }

        $manifest = Json::decodeArray($raw, []);
        $phpConstraint = is_array($manifest) ? ($manifest['require']['php'] ?? null) : null;

        if (!is_string($phpConstraint) || trim($phpConstraint) === '') {
            return null;
        }

        if (preg_match('/>=\s*([0-9]+(?:\.[0-9]+){0,2})/', $phpConstraint, $matches) === 1) {
            return $this->normalizeVersion($matches[1]);
        }

        if (preg_match('/\^\s*([0-9]+(?:\.[0-9]+){0,2})/', $phpConstraint, $matches) === 1) {
            return $this->normalizeVersion($matches[1]);
        }

        if (preg_match('/([0-9]+(?:\.[0-9]+){0,2})/', $phpConstraint, $matches) === 1) {
            return $this->normalizeVersion($matches[1]);
        }

        return null;
    }

    private function normalizeVersion(string $version): string
    {
        $parts = explode('.', $version);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }

    private function formatRuntimeError(\Throwable $e): string
    {
        return $e->getMessage() . ' @ ' . $this->normalizeDisplayedPath($e->getFile()) . ':' . $e->getLine();
    }

    /**
     * @return string[]
     */
    private function getMelbahjaSeoRequiredFiles(): array
    {
        return [
            'Interfaces/SeoInterface.php',
            'Interfaces/SchemaInterface.php',
            'Interfaces/SitemapInterface.php',
            'Interfaces/SitemapBuilderInterface.php',
            'Interfaces/SitemapSetupableInterface.php',
            'Exceptions/SeoException.php',
            'Exceptions/SitemapException.php',
            'Utils/Utils.php',
            'Utils/HttpClient.php',
            'Schema/Thing.php',
            'Schema.php',
            'Sitemap/OutputMode.php',
            'Sitemap/SitemapUrl.php',
            'Sitemap/IndexBuilder.php',
            'Sitemap/LinksBuilder.php',
            'Sitemap/NewsBuilder.php',
            'Sitemap.php',
            'Indexing/IndexNowEngine.php',
            'Indexing/URLIndexingType.php',
            'Indexing/IndexNowIndexer.php',
            'Indexing/GoogleIndexer.php',
        ];
    }
}