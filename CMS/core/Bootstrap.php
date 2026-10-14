<?php
/**
 * CMS Bootstrap Class
 * 
 * Initializes the CMS system, loads plugins/themes, handles routing
 * 
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

use CMS\Services\SiteTable\SiteTableHubRenderer;

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists(__NAMESPACE__ . '\\Bootstrap', false)) {
    return;
}

class Bootstrap
{
    private static ?self $instance = null;
    
    /** H-12: Erkannter Betriebsmodus (web | admin | api | cli) */
    private string $mode = 'web';

    /** H-06: Service-Container */
    private Container $container;

    private Database $db;
    private Security $security;
    private Auth $auth;
    private ?Router $router = null;
    private PluginManager $pluginManager;
    /** @var ThemeManager|null Im API/CLI-Modus nicht geladen (H-12) */
    private ?ThemeManager $themeManager = null;

    /**
     * Singleton instance
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * H-12: Betriebsmodus ermitteln und als Konstante definieren.
     *
     * Modus       | Trigger
     * ------------|----------------------------------------------
     * cli         | PHP_SAPI === 'cli'
     * api         | Request-URI beginnt mit /api/
     * admin       | Request-URI beginnt mit /admin/
     * web         | alle anderen Anfragen (Standard)
     */
    private static function detectMode(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'cli';
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        // Strip query string for comparison; strtok kann false zurückgeben → Fallback '/'
        $path = (string)(strtok($uri, '?') ?: '/');

        if (str_starts_with($path, '/api/')) {
            return 'api';
        }

        if (str_starts_with($path, '/admin/') || $path === '/admin') {
            return 'admin';
        }

        return 'web';
    }

    /**
     * Private constructor
     */
    private function __construct()
    {
        require_once CORE_PATH . 'Debug.php';

        $this->mode = self::detectMode();
        defined('CMS_MODE') || define('CMS_MODE', $this->mode);

        Debug::enable(defined('CMS_DEBUG') && CMS_DEBUG);
        Debug::resetRuntimeProfile([
            'mode' => $this->mode,
            'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? '/'),
            'request_method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'GET'))),
            'sapi' => PHP_SAPI,
        ]);
        Debug::checkpoint('bootstrap.start', ['mode' => $this->mode]);

        $this->loadDependencies();
        Debug::checkpoint('bootstrap.dependencies_loaded', ['mode' => $this->mode]);
        $this->validateBundledPhpPlatform();
        Debug::checkpoint('bootstrap.platform_validated', ['mode' => $this->mode]);
        $this->initializeCore();
        Debug::checkpoint('bootstrap.ready', ['mode' => $this->mode]);
    }

    /**
     * H-08: Prüft, ob gebündelte Runtime-Libraries zur offiziellen PHP-Zielplattform passen.
     */
    private function validateBundledPhpPlatform(): void
    {
        $requiredPhpVersion = Version::minimumPhp();
        $manifests = [
            'symfony/mailer' => ABSPATH . 'assets/mailer/composer.json',
            'symfony/mime' => ABSPATH . 'assets/mime/composer.json',
            'symfony/translation' => ABSPATH . 'assets/translation/composer.json',
            'symfony/yaml' => ABSPATH . 'assets/yaml/composer.json',
            'symfony/clock' => ABSPATH . 'assets/clock/composer.json',
            'egulias/email-validator' => ABSPATH . 'assets/egulias-email-validator/composer.json',
        ];

        // Ergebnis merken: sonst wurden bei jedem Request sechs composer.json gelesen und dekodiert.
        $signature = PHP_VERSION . '|' . $requiredPhpVersion;
        foreach ($manifests as $manifestPath) {
            $signature .= '|' . (is_file($manifestPath) ? (string) filemtime($manifestPath) : '-');
        }
        $okFlag = ABSPATH . 'cache/platform_ok_' . hash('crc32b', $signature) . '.flag';
        if (is_file($okFlag)) {
            return;
        }

        $violations = [];

        foreach ($manifests as $packageName => $manifestPath) {
            $bundlePhpVersion = $this->extractMinimumPhpVersion($manifestPath);
            if ($bundlePhpVersion === null) {
                continue;
            }

            if (version_compare($requiredPhpVersion, $bundlePhpVersion, '<')) {
                $violations[] = sprintf(
                    '%s verlangt mindestens PHP %s, die offizielle CMS-Plattform ist aber nur auf %s gesetzt.',
                    $packageName,
                    $bundlePhpVersion,
                    $requiredPhpVersion
                );
            }

            if (version_compare(PHP_VERSION, $bundlePhpVersion, '<')) {
                $violations[] = sprintf(
                    '%s verlangt mindestens PHP %s, aktiv ist jedoch PHP %s.',
                    $packageName,
                    $bundlePhpVersion,
                    PHP_VERSION
                );
            }
        }

        if ($violations === []) {
            if (is_dir(ABSPATH . 'cache') || @mkdir(ABSPATH . 'cache', 0755, true)) {
                foreach (glob(ABSPATH . 'cache/platform_ok_*.flag') ?: [] as $staleFlag) {
                    @unlink($staleFlag);
                }
                @file_put_contents($okFlag, '', LOCK_EX);
            }
            return;
        }

        $message = '365CMS konnte nicht starten, weil gebündelte Runtime-Abhängigkeiten eine höhere PHP-Version verlangen: ' . implode(' ', $violations);
        error_log($message);

        self::abortForPlatformMismatch($message);
    }

    private function extractMinimumPhpVersion(string $manifestPath): ?string
    {
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return null;
        }

        $manifest = Json::decodeArray(file_get_contents($manifestPath), []);
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

    private static function abortForPlatformMismatch(string $message): never
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }

        http_response_code(503);

        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $expectsJson = defined('CMS_AJAX_REQUEST')
            || str_starts_with($requestUri, '/api/')
            || $requestMethod === 'POST';

        if ($expectsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>365CMS – Plattformanforderung nicht erfüllt</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 2rem; }
                .box { max-width: 760px; margin: 10vh auto; background: #fff; border-radius: 16px; padding: 2rem 2.25rem; box-shadow: 0 20px 45px rgba(15, 23, 42, 0.16); }
                h1 { margin-top: 0; color: #b91c1c; }
                p { line-height: 1.65; color: #475569; }
            </style>
        </head>
        <body>
            <div class="box">
                <h1>⚠️ Plattformanforderung nicht erfüllt</h1>
                <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
                <p>Bitte die Hosting-Plattform und die offiziell deklarierte CMS-Mindestversion synchron halten, bevor 365CMS produktiv gebootet wird.</p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * Load core dependencies
     */
    private function loadDependencies(): void
    {
        require_once CORE_PATH . 'Debug.php';
        require_once CORE_PATH . 'Version.php';
        require_once CORE_PATH . 'Container.php';
        require_once CORE_PATH . 'Database.php';
        require_once CORE_PATH . 'Security.php';
        require_once CORE_PATH . 'AuditLogger.php';
        require_once CORE_PATH . 'Logger.php';
        require_once CORE_PATH . 'Totp.php';
        require_once CORE_PATH . 'Auth.php';
        require_once CORE_PATH . 'PluginManager.php';
        require_once CORE_PATH . 'Hooks.php';
        require_once CORE_PATH . 'CacheManager.php';
        // H-10: Schema- und Migrations-Manager
        if (!class_exists(__NAMESPACE__ . '\SchemaManager', false)) {
            require_once CORE_PATH . 'SchemaManager.php';
        }
        require_once CORE_PATH . 'MigrationManager.php';

        if ($this->mode !== 'cli') {
            require_once CORE_PATH . 'Router.php';
            require_once CORE_PATH . 'PageManager.php';
            require_once CORE_PATH . 'Api.php';
            require_once CORE_PATH . 'SubscriptionManager.php';
        }

        if (in_array($this->mode, ['web', 'admin'], true) && !class_exists(__NAMESPACE__ . '\ThemeManager', false)) {
            require_once CORE_PATH . 'ThemeManager.php';
        }

        if ($this->mode !== 'cli' && !class_exists(__NAMESPACE__ . '\\Services\\SecurityRuntimeService', false)) {
            require_once CORE_PATH . 'Services/SecurityRuntimeService.php';
        }
        
        if (file_exists(ABSPATH . 'includes/functions.php') && !defined('CMS_GLOBAL_FUNCTIONS_LOADED') && !function_exists('esc_html')) {
            require_once ABSPATH . 'includes/functions.php';
        }
        
        if ($this->mode !== 'cli' && file_exists(ABSPATH . 'includes/subscription-helpers.php')) {
            require_once ABSPATH . 'includes/subscription-helpers.php';
        }

        // Vendor-Autoloader für externe Libraries (CMS/assets/)
        // Primär: produktiver Autoloader aus CMS/assets/ (wird aufs Hosting deployed)
        $vendorAutoload = ABSPATH . 'assets/autoload.php';
        if (file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        } else {
            // Fallback: Entwicklungs-Autoloader (ASSETS/ im Repo-Root, wird NICHT deployed)
            $devAutoload = dirname(ABSPATH) . '/ASSETS/autoload.php';
            if (file_exists($devAutoload)) {
                require_once $devAutoload;
            }
        }
    }
    
    /**
     * Stellt sicher dass alle kritischen CMS-Konstanten definiert sind.
     * Dient als Fallback für Server-Umgebungen, in denen config.php
     * ältere oder unvollständige Definitionen enthält.
     */
    private function ensureConstants(): void
    {
        defined('CMS_VERSION')   || define('CMS_VERSION',   Version::CURRENT);
        defined('CMS_MIN_PHP_VERSION') || define('CMS_MIN_PHP_VERSION', Version::MIN_PHP);
        defined('SITE_NAME')     || define('SITE_NAME',     'CMS');
        defined('SITE_URL')      || define('SITE_URL',      '');
        defined('ADMIN_EMAIL')   || define('ADMIN_EMAIL',   '');
        defined('CORE_PATH')     || define('CORE_PATH',     ABSPATH . 'core/');
        defined('THEME_PATH')    || define('THEME_PATH',    ABSPATH . 'themes/');
        defined('PLUGIN_PATH')   || define('PLUGIN_PATH',   ABSPATH . 'plugins/');
        defined('UPLOAD_PATH')   || define('UPLOAD_PATH',   ABSPATH . 'uploads/');
        defined('ASSETS_PATH')   || define('ASSETS_PATH',   ABSPATH . 'assets/');
    }

    /**
     * Initialize core components
     * H-12: Differenzierter Bootstrap-Pfad je Betriebsmodus
     * H-06: DI-Container mit Core-Services befüllen
     */
    private function initializeCore(): void
    {
        $this->ensureConstants();
        Debug::checkpoint('bootstrap.constants_ready', ['mode' => $this->mode]);

        // H-06: Container initialisieren
        $this->container = Container::instance();

        // Database – immer erforderlich
        $this->db = Database::instance();
        $this->container->bindInstance(Database::class, $this->db);
        $this->container->bindInstance('db', $this->db);
        Debug::checkpoint('bootstrap.database_ready');

        // H-10: Inkrementelle DB-Migrationen ausführen (idempotent, version-basiert –
        // nur 1 DB-Query pro Request wenn bereits aktuell)
        (new MigrationManager($this->db))->run();
        Debug::checkpoint('bootstrap.migrations_checked');

        // Security init (setzt HTTP-Security-Header) – nicht im CLI-Modus
        $this->security = Security::instance();
        $this->container->bindInstance(Security::class, $this->security);
        if ($this->mode !== 'cli') {
            $this->security->init();
        }
        Debug::checkpoint('bootstrap.security_ready');

        // Authentication – immer erforderlich
        $this->auth = Auth::instance();
        $this->container->bindInstance(Auth::class, $this->auth);

        if ($this->mode !== 'cli') {
            Services\SecurityRuntimeService::getInstance()->handleRequest();
        }

        // Logger – immer verfügbar
        $logger = Logger::instance();
        $this->container->bindInstance(Logger::class, $logger);
        $this->container->bindInstance('logger', $logger);

        // CacheManager – immer verfügbar
        $cache = CacheManager::instance();
        $this->container->bindInstance(CacheManager::class, $cache);
        $this->container->bindInstance('cache', $cache);

        // PurifierService – lazy Singleton (wird erst bei erstem Zugriff instanziiert)
        $this->container->singleton(Services\PurifierService::class, fn() => Services\PurifierService::getInstance());
        $this->container->singleton('purifier', fn() => Services\PurifierService::getInstance());

        // MailService – lazy Singleton
        $this->container->singleton(Services\MailService::class, fn() => Services\MailService::getInstance());
        $this->container->singleton('mail', fn() => Services\MailService::getInstance());

        // SettingsService – zentrale Settings-Abstraktion für gruppierte/verschlüsselte Werte
        $this->container->singleton(Services\SettingsService::class, fn() => Services\SettingsService::getInstance());
        $this->container->singleton('settings', fn() => Services\SettingsService::getInstance());

        // AzureMailTokenProvider – XOAUTH2 Token-Caching für Microsoft 365 SMTP
        $this->container->singleton(Services\AzureMailTokenProvider::class, fn() => Services\AzureMailTokenProvider::getInstance());
        $this->container->singleton('mail.azure', fn() => Services\AzureMailTokenProvider::getInstance());

        // MailLogService – Versandhistorie für Admin & Diagnose
        $this->container->singleton(Services\MailLogService::class, fn() => Services\MailLogService::getInstance());
        $this->container->singleton('mail.logs', fn() => Services\MailLogService::getInstance());

        // MailQueueService – asynchroner Mailversand mit Cron-Worker und Retry-Backoff
        $this->container->singleton(Services\MailQueueService::class, fn() => Services\MailQueueService::getInstance());
        $this->container->singleton('mail.queue', fn() => Services\MailQueueService::getInstance());

        // GraphApiService – Microsoft Graph via Client-Credentials
        $this->container->singleton(Services\GraphApiService::class, fn() => Services\GraphApiService::getInstance());
        $this->container->singleton('graph', fn() => Services\GraphApiService::getInstance());

        // SearchService – lazy Singleton (TNTSearch Volltextsuche)
        $this->container->singleton(Services\SearchService::class, fn() => Services\SearchService::getInstance());
        $this->container->singleton('search', fn() => Services\SearchService::getInstance());

        // ImageService – lazy Singleton (GD-basierte Bildbearbeitung)
        $this->container->singleton(Services\ImageService::class, fn() => Services\ImageService::getInstance());
        $this->container->singleton('image', fn() => Services\ImageService::getInstance());

        // FeedService – lazy Singleton (nativer RSS/Atom-Parser)
        $this->container->singleton(Services\FeedService::class, fn() => Services\FeedService::getInstance());
        $this->container->singleton('feed', fn() => Services\FeedService::getInstance());

        // TranslationService – lazy Singleton (I18n)
        $this->container->singleton(Services\TranslationService::class, fn() => Services\TranslationService::getInstance());
        $this->container->singleton('translation', fn() => Services\TranslationService::getInstance());

        // EditorJsService – lazy Singleton (Editor.js Block-Editor)
        if ($this->mode !== 'cli') {
            $this->container->singleton(Services\EditorJsService::class, fn() => Services\EditorJsService::getInstance());
            $this->container->singleton('editorjs', fn() => Services\EditorJsService::getInstance());
        }

        // EditorJsRenderer – lazy Singleton (Editor.js HTML-Rendering)
        if ($this->mode !== 'cli') {
            $this->container->singleton(Services\EditorJsRenderer::class, fn() => Services\EditorJsRenderer::getInstance());
            $this->container->singleton('editorjs.renderer', fn() => Services\EditorJsRenderer::getInstance());
        }

        // FileUploadService – lazy Singleton (interner Upload-Endpunkt)
        if ($this->mode !== 'cli') {
            $this->container->singleton(Services\FileUploadService::class, fn() => Services\FileUploadService::getInstance());
            $this->container->singleton('fileupload', fn() => Services\FileUploadService::getInstance());
        }

        // CommentService – lazy Singleton (Kommentarsystem)
        $this->container->singleton(Services\CommentService::class, fn() => Services\CommentService::getInstance());
        $this->container->singleton('comments', fn() => Services\CommentService::getInstance());

        // SEOService – lazy Singleton (Meta-Tags, Schema.org)
        $this->container->singleton(Services\SEOService::class, fn() => Services\SEOService::getInstance());
        $this->container->singleton('seo', fn() => Services\SEOService::getInstance());

        // MemberService – lazy Singleton (Mitgliederverwaltung)
        $this->container->singleton(Services\MemberService::class, fn() => Services\MemberService::getInstance());
        $this->container->singleton('member', fn() => Services\MemberService::getInstance());

        // MessageService – lazy Singleton (Internes Nachrichtensystem)
        $this->container->singleton(Services\MessageService::class, fn() => Services\MessageService::getInstance());
        $this->container->singleton('messages', fn() => Services\MessageService::getInstance());

        // UserService – lazy Singleton (Benutzerverwaltung)
        $this->container->singleton(Services\UserService::class, fn() => Services\UserService::getInstance());
        $this->container->singleton('users', fn() => Services\UserService::getInstance());

        // StatusService – lazy Singleton (Online-Status)
        $this->container->singleton(Services\StatusService::class, fn() => Services\StatusService::getInstance());
        $this->container->singleton('status', fn() => Services\StatusService::getInstance());

        // DashboardService – lazy Singleton (Admin-Dashboard-Statistiken)
        $this->container->singleton(Services\DashboardService::class, fn() => Services\DashboardService::getInstance());
        $this->container->singleton('dashboard', fn() => Services\DashboardService::getInstance());

        // LandingPageService – lazy Singleton (Landing-Page-Builder)
        $this->container->singleton(Services\LandingPageService::class, fn() => Services\LandingPageService::getInstance());
        $this->container->singleton('landingpage', fn() => Services\LandingPageService::getInstance());

        // TrackingService – lazy Singleton (Seitenaufrufe)
        if ($this->mode !== 'cli') {
            $this->container->singleton(Services\TrackingService::class, fn() => Services\TrackingService::getInstance());
            $this->container->singleton('tracking', fn() => Services\TrackingService::getInstance());

            // FeatureUsageService – datensparsame Admin-/Member-Funktionsmetriken
            $this->container->singleton(Services\FeatureUsageService::class, fn() => Services\FeatureUsageService::getInstance());
            $this->container->singleton('featureusage', fn() => Services\FeatureUsageService::getInstance());
        }

        // BackupService – lazy Singleton (Datenbank-Backups)
        $this->container->singleton(Services\BackupService::class, fn() => Services\BackupService::getInstance());
        $this->container->singleton('backup', fn() => Services\BackupService::getInstance());

        // SystemService – lazy Singleton (Systeminfo & Wartung)
        $this->container->singleton(Services\SystemService::class, fn() => Services\SystemService::instance());
        $this->container->singleton('system', fn() => Services\SystemService::instance());

        // UpdateService – lazy Singleton (Auto-Update-Prüfung)
        $this->container->singleton(Services\UpdateService::class, fn() => Services\UpdateService::getInstance());
        $this->container->singleton('update', fn() => Services\UpdateService::getInstance());

        if ($this->mode !== 'cli') {
            // Router – nur für Web/Admin/API (CLI routed anders)
            $this->router = Router::instance();
            $this->container->bindInstance(Router::class, $this->router);
        }

        // Plugin Manager – immer laden (Plugins können CLI-Hooks registrieren)
        $this->pluginManager = PluginManager::instance();
        $this->container->bindInstance(PluginManager::class, $this->pluginManager);
        $this->pluginManager->loadPlugins();
        Debug::checkpoint('bootstrap.plugins_loaded');

        // Theme Manager – im Admin verfügbar, Theme-Runtime aber nur im Web-Pfad booten
        if (in_array($this->mode, ['web', 'admin'], true)) {
            $this->themeManager = ThemeManager::instance();
            $this->container->bindInstance(ThemeManager::class, $this->themeManager);
            if ($this->mode === 'web') {
                $this->themeManager->loadTheme();
            }
        }
        Debug::checkpoint('bootstrap.theme_state_ready', ['theme_loaded' => $this->mode === 'web']);

        // Subscription Manager – in Web/Admin verfügbar, API/CLI laden bei Bedarf direkt
        if (in_array($this->mode, ['web', 'admin'], true)) {
            $sm = SubscriptionManager::instance();
            $this->container->bindInstance(SubscriptionManager::class, $sm);
        }

        if ($this->mode !== 'cli') {
            // PdfService – typischer Web-/Admin-Renderpfad
            $this->container->singleton(Services\PdfService::class, fn() => Services\PdfService::getInstance());
            $this->container->singleton('pdf', fn() => Services\PdfService::getInstance());
        }

        if (in_array($this->mode, ['web', 'admin'], true)) {
            // EditorService – SunEditor / Content-Rendering in Web/Admin
            $this->container->singleton(Services\EditorService::class, fn() => Services\EditorService::getInstance());
            $this->container->singleton('editor', fn() => Services\EditorService::getInstance());

            // ThemeCustomizer – Theme-bezogene Laufzeit-/Admin-Konfiguration
            $this->container->singleton(Services\ThemeCustomizer::class, fn() => Services\ThemeCustomizer::instance());
            $this->container->singleton('customizer', fn() => Services\ThemeCustomizer::instance());
        }

        if ($this->mode === 'admin') {
            // AnalyticsService – nur im Admin-Diagnose-/SEO-Pfad
            $this->container->singleton(Services\AnalyticsService::class, fn() => Services\AnalyticsService::getInstance());
            $this->container->singleton('analytics', fn() => Services\AnalyticsService::getInstance());
        }

        if ($this->mode === 'web') {
            // Sicherheitsnetz: CSP-Runtime (DOMPurify + Trusted-Types-Policy) als erste head-Ausgabe,
            // auch für Themes, die cms_csp_runtime_tags() nicht selbst aufrufen. Mehrfachaufrufe sind leer.
            Hooks::addAction('head', static function (): void {
                if (function_exists('cms_csp_runtime_tags')) {
                    echo cms_csp_runtime_tags();
                }
            }, PHP_INT_MIN);

            Hooks::addAction('body_end', static function (): void {
                Services\CoreWebVitalsService::getInstance()->renderTrackingScript();
            }, 15);

            // CookieConsentService – reiner Frontend-Consent-Banner
            $this->container->singleton(Services\CookieConsentService::class, fn() => Services\CookieConsentService::getInstance());
            $this->container->singleton('cookieconsent', fn() => Services\CookieConsentService::getInstance());
        }

        // Frontend: CookieConsent im Theme-Footer ausgeben
        if ($this->mode === 'web') {
            $resolveCurrentHubPage = static function (): ?array {
                $page = $GLOBALS['page'] ?? null;

                if (is_object($page)) {
                    $page = (array) $page;
                }

                return is_array($page) ? $page : null;
            };

            $isHubPagePayload = static function (?array $page): bool {
                if (!is_array($page)) {
                    return false;
                }

                $contentType = strtolower(trim((string) ($page['content_type'] ?? '')));
                if ($contentType === 'hub') {
                    return true;
                }

                $content = (string) ($page['content'] ?? '');

                return $content !== '' && str_contains($content, 'cms-hub-site');
            };

            $isCurrentHubFrontendRequest = static function () use ($resolveCurrentHubPage, $isHubPagePayload): bool {
                $requestUri = (string) (strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/');
                $path = $requestUri;

                try {
                    $context = Services\ContentLocalizationService::getInstance()->resolveRequestContext($requestUri);
                    $baseUri = (string) ($context['base_uri'] ?? $requestUri);
                    if ($baseUri !== '') {
                        $path = $baseUri;
                    }
                } catch (\Throwable) {
                }

                if (
                    in_array($path, ['/blog', '/search', '/404', '/error', '/login', '/register', '/cms-login', '/cms-register', '/cms-password-forgot'], true)
                    || str_starts_with($path, '/member')
                    || str_starts_with($path, '/dashboard')
                    || \cms_is_archive_request_path($path, 'category')
                    || \cms_is_archive_request_path($path, 'tag')
                    || str_starts_with($path, '/author/')
                    || $path === '/authors'
                    || $path === '/autoren'
                ) {
                    return false;
                }

                if ($isHubPagePayload($resolveCurrentHubPage())) {
                    return true;
                }

                if ($path === '/' || $path === '') {
                    return false;
                }

                try {
                    return SiteTableHubRenderer::isHubRequestUri($requestUri);
                } catch (\Throwable) {
                    return false;
                }
            };

            // Ergebnis je Request merken: head (CSS) und body_end (Script) fragen beide (Folgeaudit PERF-22).
            $photoSwipeDecision = null;
            $shouldLoadPhotoSwipe = static function () use ($isCurrentHubFrontendRequest, &$photoSwipeDecision): bool {
                return $photoSwipeDecision ??= (static function () use ($isCurrentHubFrontendRequest): bool {
                    try {
                        $enabled = Services\ThemeCustomizer::instance()->get('performance', 'enable_photoswipe', true);
                        if (!filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
                            return false;
                        }
                    } catch (\Throwable) {
                    }

                    $requestUri = (string) (strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/');
                    $path = $requestUri;

                    try {
                        $context = Services\ContentLocalizationService::getInstance()->resolveRequestContext($requestUri);
                        $baseUri = (string) ($context['base_uri'] ?? $requestUri);
                        if ($baseUri !== '') {
                            $path = $baseUri;
                        }
                    } catch (\Throwable) {
                    }

                    if ($isCurrentHubFrontendRequest()) {
                        return true;
                    }

                    if (
                        in_array($path, ['/', '/blog', '/search', '/404', '/error', '/login', '/register', '/cms-login', '/cms-register', '/cms-password-forgot'], true)
                        || str_starts_with($path, '/member')
                        || str_starts_with($path, '/dashboard')
                        || \cms_is_archive_request_path($path, 'category')
                        || \cms_is_archive_request_path($path, 'tag')
                        || str_starts_with($path, '/author/')
                        || $path === '/authors'
                        || $path === '/autoren'
                    ) {
                        if ($path !== '/') {
                            return false;
                        }
                    }

                    if ($path === '/') {
                        return false;
                    }

                    try {
                        $postSlug = Services\PermalinkService::getInstance()->extractPostSlugFromPath($path);
                        if (is_string($postSlug) && trim($postSlug) !== '') {
                            return true;
                        }
                    } catch (\Throwable) {
                    }

                    if (preg_match('#^/blog/[^/]+$#', $path) === 1) {
                        return true;
                    }

                    // Einstufige Pfade (Seiten und Hub-Sites) laden PhotoSwipe immer; die frühere
                    // Hub-Abfrage änderte das Ergebnis nicht und entfällt.
                    $slug = trim($path, '/');

                    return $slug !== '' && !str_contains($slug, '/');
                })();
            };

            Hooks::addAction('body_end', static function (): void {
                Services\CookieConsentService::getInstance()->render();
            }, 20);

            // Analytics: CSP nur für konfigurierte Anbieter öffnen, Loader lädt erst nach Einwilligung
            $analyticsCspReady = false;
            Hooks::addAction('cms_csp_prepare', static function () use (&$analyticsCspReady): void {
                try {
                    $sources = Services\SEOService::getInstance()->getAnalyticsCspSources();
                    $analyticsCspReady = $sources !== [] && Security::instance()->allowCspSources($sources);
                } catch (\Throwable) {
                    $analyticsCspReady = false;
                }
            });
            Hooks::addAction('head', static function () use (&$analyticsCspReady): void {
                if (!$analyticsCspReady) {
                    return;
                }
                try {
                    echo Services\SEOService::getInstance()->getAnalyticsHeadCode();
                } catch (\Throwable) {
                }
            }, 90);

            // PhotoSwipe V5 — Lightbox für Bilder in Content-Bereichen
            Hooks::addAction('head', static function () use ($shouldLoadPhotoSwipe): void {
                if (!$shouldLoadPhotoSwipe()) {
                    return;
                }

                $href = htmlspecialchars(SITE_URL . '/assets/photoswipe/photoswipe.css', ENT_QUOTES, 'UTF-8');
                echo '<link rel="stylesheet" href="' . $href . '">' . "\n";
            }, 30);

            Hooks::addAction('head', static function () use ($isCurrentHubFrontendRequest): void {
                static $hubStylesRendered = false;

                if ($hubStylesRendered || !$isCurrentHubFrontendRequest()) {
                    return;
                }

                $hubStylesRendered = true;
                $href = htmlspecialchars(cms_asset_url('css/hub-sites.css'), ENT_QUOTES, 'UTF-8');
                echo '<link rel="stylesheet" href="' . $href . '">' . "\n";
            }, 12);

            Hooks::addAction('head', static function (): void {
                static $editorJsContentStylesRendered = false;

                if ($editorJsContentStylesRendered) {
                    return;
                }

                $editorJsContentStylesRendered = true;
                $href = htmlspecialchars(cms_asset_url('css/editorjs-content.css'), ENT_QUOTES, 'UTF-8');
                $criticalCss = '.editorjs-block{--cms-editorjs-default-space-after:1.1rem;--cms-editorjs-space-before:0rem;--cms-editorjs-space-after:var(--cms-editorjs-default-space-after);box-sizing:border-box;display:block;max-width:100%;margin-block-start:var(--cms-editorjs-space-before)!important;margin-block-end:var(--cms-editorjs-space-after)!important}.editorjs-block+.editorjs-block:not(.editorjs-media-text),.editorjs-block:first-child:not(.editorjs-media-text){margin-block-start:0!important}.editorjs-block:last-child:not(.editorjs-media-text){margin-block-end:0!important}.editorjs-image img,.editorjs-gallery img,.editorjs-carousel img,.editorjs-drawing img,.editorjs-media-text img{box-sizing:border-box;max-width:100%!important;height:auto!important}.editorjs-block.editorjs-media-text{--cms-editorjs-media-text-align-items:flex-start;--cms-editorjs-media-text-spacing-top:10px;--cms-editorjs-media-text-spacing-bottom:10px;--cms-editorjs-space-before:var(--cms-editorjs-media-text-spacing-top);--cms-editorjs-space-after:var(--cms-editorjs-media-text-spacing-bottom);display:flex;flex-wrap:wrap;align-items:var(--cms-editorjs-media-text-align-items,flex-start)!important;gap:1.5rem;margin-block-start:var(--cms-editorjs-media-text-spacing-top)!important;margin-block-end:var(--cms-editorjs-media-text-spacing-bottom)!important}.editorjs-block.editorjs-media-text[data-spacing-top="0"],.editorjs-block.editorjs-media-text--spacing-top-0{--cms-editorjs-media-text-spacing-top:0px;--cms-editorjs-space-before:0px;margin-block-start:0!important}.editorjs-block.editorjs-media-text[data-spacing-top="5"],.editorjs-block.editorjs-media-text--spacing-top-5{--cms-editorjs-media-text-spacing-top:5px;--cms-editorjs-space-before:5px;margin-block-start:5px!important}.editorjs-block.editorjs-media-text[data-spacing-top="10"],.editorjs-block.editorjs-media-text--spacing-top-10{--cms-editorjs-media-text-spacing-top:10px;--cms-editorjs-space-before:10px;margin-block-start:10px!important}.editorjs-block.editorjs-media-text[data-spacing-top="15"],.editorjs-block.editorjs-media-text--spacing-top-15{--cms-editorjs-media-text-spacing-top:15px;--cms-editorjs-space-before:15px;margin-block-start:15px!important}.editorjs-block.editorjs-media-text[data-spacing-top="20"],.editorjs-block.editorjs-media-text--spacing-top-20{--cms-editorjs-media-text-spacing-top:20px;--cms-editorjs-space-before:20px;margin-block-start:20px!important}.editorjs-block.editorjs-media-text[data-spacing-top="30"],.editorjs-block.editorjs-media-text--spacing-top-30{--cms-editorjs-media-text-spacing-top:30px;--cms-editorjs-space-before:30px;margin-block-start:30px!important}.editorjs-block.editorjs-media-text[data-spacing-top="40"],.editorjs-block.editorjs-media-text--spacing-top-40{--cms-editorjs-media-text-spacing-top:40px;--cms-editorjs-space-before:40px;margin-block-start:40px!important}.editorjs-block.editorjs-media-text[data-spacing-top="60"],.editorjs-block.editorjs-media-text--spacing-top-60{--cms-editorjs-media-text-spacing-top:60px;--cms-editorjs-space-before:60px;margin-block-start:60px!important}.editorjs-block.editorjs-media-text[data-spacing-top="80"],.editorjs-block.editorjs-media-text--spacing-top-80{--cms-editorjs-media-text-spacing-top:80px;--cms-editorjs-space-before:80px;margin-block-start:80px!important}.editorjs-block.editorjs-media-text[data-spacing-top="100"],.editorjs-block.editorjs-media-text--spacing-top-100{--cms-editorjs-media-text-spacing-top:100px;--cms-editorjs-space-before:100px;margin-block-start:100px!important}.editorjs-block.editorjs-media-text[data-spacing-bottom="0"],.editorjs-block.editorjs-media-text--spacing-bottom-0{--cms-editorjs-media-text-spacing-bottom:0px;--cms-editorjs-space-after:0px;margin-block-end:0!important}.editorjs-block.editorjs-media-text[data-spacing-bottom="5"],.editorjs-block.editorjs-media-text--spacing-bottom-5{--cms-editorjs-media-text-spacing-bottom:5px;--cms-editorjs-space-after:5px;margin-block-end:5px!important}.editorjs-block.editorjs-media-text[data-spacing-bottom="10"],.editorjs-block.editorjs-media-text--spacing-bottom-10{--cms-editorjs-media-text-spacing-bottom:10px;--cms-editorjs-space-after:10px;margin-block-end:10px!important}.editorjs-block.editorjs-media-text[data-spacing-bottom="15"],.editorjs-block.editorjs-media-text--spacing-bottom-15{--cms-editorjs-media-text-spacing-bottom:15px;--cms-editorjs-space-after:15px;margin-block-end:15px!important}.editorjs-block.editorjs-media-text[data-spacing-bottom="20"],.editorjs-block.editorjs-media-text--spacing-bottom-20{--cms-editorjs-media-text-spacing-bottom:20px;--cms-editorjs-space-after:20px;margin-block-end:20px!important}.editorjs-block.editorjs-media-text[data-spacing-bottom="30"],.editorjs-block.editorjs-media-text--spacing-bottom-30{--cms-editorjs-media-text-spacing-bottom:30px;--cms-editorjs-space-after:30px;margin-block-end:30px!important}.editorjs-block.editorjs-media-text[data-spacing-bottom="40"],.editorjs-block.editorjs-media-text--spacing-bottom-40{--cms-editorjs-media-text-spacing-bottom:40px;--cms-editorjs-space-after:40px;margin-block-end:40px!important}.editorjs-block.editorjs-media-text[data-spacing-bottom="60"],.editorjs-block.editorjs-media-text--spacing-bottom-60{--cms-editorjs-media-text-spacing-bottom:60px;--cms-editorjs-space-after:60px;margin-block-end:60px!important}.editorjs-block.editorjs-media-text[data-spacing-bottom="80"],.editorjs-block.editorjs-media-text--spacing-bottom-80{--cms-editorjs-media-text-spacing-bottom:80px;--cms-editorjs-space-after:80px;margin-block-end:80px!important}.editorjs-block.editorjs-media-text[data-spacing-bottom="100"],.editorjs-block.editorjs-media-text--spacing-bottom-100{--cms-editorjs-media-text-spacing-bottom:100px;--cms-editorjs-space-after:100px;margin-block-end:100px!important}.editorjs-media-text--image-right{flex-direction:row-reverse}.editorjs-block.editorjs-media-text.editorjs-media-text--valign-top,.editorjs-block.editorjs-media-text[data-vertical-alignment="top"]{--cms-editorjs-media-text-align-items:flex-start}.editorjs-block.editorjs-media-text.editorjs-media-text--valign-center,.editorjs-block.editorjs-media-text[data-vertical-alignment="center"]{--cms-editorjs-media-text-align-items:center}.editorjs-block.editorjs-media-text.editorjs-media-text--valign-bottom,.editorjs-block.editorjs-media-text[data-vertical-alignment="bottom"]{--cms-editorjs-media-text-align-items:flex-end}.editorjs-block.editorjs-media-text.editorjs-media-text--image-width-33{--cms-editorjs-media-text-image-width:33%}.editorjs-block.editorjs-media-text.editorjs-media-text--image-width-40{--cms-editorjs-media-text-image-width:40%}.editorjs-block.editorjs-media-text.editorjs-media-text--image-width-50{--cms-editorjs-media-text-image-width:50%}.editorjs-table{overflow-x:auto}.editorjs-spacer{--cms-editorjs-space-after:0rem;--cms-editorjs-spacer-height:40px;display:block!important;clear:both;width:100%;height:var(--cms-editorjs-spacer-height)!important;min-height:var(--cms-editorjs-spacer-height)!important;margin:0!important;padding:0!important;line-height:0;font-size:0;overflow:hidden}';
                $criticalCss .= '.editorjs-spacer[data-height="0"]{--cms-editorjs-spacer-height:0px}.editorjs-spacer[data-height="8"]{--cms-editorjs-spacer-height:8px}.editorjs-spacer[data-height="10"]{--cms-editorjs-spacer-height:10px}.editorjs-spacer[data-height="15"]{--cms-editorjs-spacer-height:15px}.editorjs-spacer[data-height="16"]{--cms-editorjs-spacer-height:16px}.editorjs-spacer[data-height="24"]{--cms-editorjs-spacer-height:24px}.editorjs-spacer[data-height="25"]{--cms-editorjs-spacer-height:25px}.editorjs-spacer[data-height="32"]{--cms-editorjs-spacer-height:32px}.editorjs-spacer[data-height="40"]{--cms-editorjs-spacer-height:40px}.editorjs-spacer[data-height="48"]{--cms-editorjs-spacer-height:48px}.editorjs-spacer[data-height="56"]{--cms-editorjs-spacer-height:56px}.editorjs-spacer[data-height="60"]{--cms-editorjs-spacer-height:60px}.editorjs-spacer[data-height="64"]{--cms-editorjs-spacer-height:64px}.editorjs-spacer[data-height="72"]{--cms-editorjs-spacer-height:72px}.editorjs-spacer[data-height="75"]{--cms-editorjs-spacer-height:75px}.editorjs-spacer[data-height="80"]{--cms-editorjs-spacer-height:80px}.editorjs-spacer[data-height="96"]{--cms-editorjs-spacer-height:96px}.editorjs-spacer[data-height="100"]{--cms-editorjs-spacer-height:100px}.editorjs-spacer[data-height="120"]{--cms-editorjs-spacer-height:120px}.editorjs-spacer[data-height="140"]{--cms-editorjs-spacer-height:140px}.editorjs-spacer[data-height="150"]{--cms-editorjs-spacer-height:150px}.editorjs-spacer[data-height="160"]{--cms-editorjs-spacer-height:160px}.editorjs-spacer[data-height="180"]{--cms-editorjs-spacer-height:180px}.editorjs-spacer[data-height="200"]{--cms-editorjs-spacer-height:200px}';
                echo '<style id="cms-editorjs-critical-css" ' . \CMS\Security::instance()->nonceAttr() . '>' . $criticalCss . '</style>' . "\n";
                echo '<link rel="stylesheet" href="' . $href . '">' . "\n";
            }, 95);

            // Custom Fonts (DSGVO-konform lokal gespeicherte Schriften)
            Hooks::addAction('head', function () {
                try {
                    if (Services\OptionStore::getInstance()->get('privacy_use_local_fonts', '0') !== '1') {
                        return;
                    }

                    $db = Database::instance();
                    $requestedFontSlugs = Hooks::applyFilters('local_font_slugs', []);
                    $requestedFontSlugs = array_values(array_unique(array_filter(array_map(
                        static fn($slug): string => preg_replace('/[^a-z0-9_-]/i', '', (string)$slug) ?? '',
                        is_array($requestedFontSlugs) ? $requestedFontSlugs : []
                    ))));

                    if ($requestedFontSlugs === []) {
                        foreach (\CMS\Services\OptionStore::getInstance()->getMany(['font_heading', 'font_body']) as $fontSettingValue) {
                            $slug = preg_replace('/[^a-z0-9_-]/i', '', (string) ($fontSettingValue ?? ''));
                            if ($slug !== '') {
                                $requestedFontSlugs[] = $slug;
                            }
                        }

                        $requestedFontSlugs = array_values(array_unique($requestedFontSlugs));
                    }

                    if ($requestedFontSlugs === []) {
                        return;
                    }

                    $fonts = $db->get_results(
                        "SELECT slug, css_path FROM {$db->getPrefix()}custom_fonts WHERE css_path IS NOT NULL AND css_path != ''"
                    ) ?: [];

                    $fontMap = [];
                    foreach ($fonts as $font) {
                        $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)($font->slug ?? ''));
                        if ($slug === '') {
                            continue;
                        }
                        $fontMap[$slug] = (string)($font->css_path ?? '');
                    }

                    foreach ($requestedFontSlugs as $slug) {
                        if (!isset($fontMap[$slug])) {
                            continue;
                        }

                        $cssFile = ABSPATH . ltrim($fontMap[$slug], '/');
                        if (is_file($cssFile)) {
                            $cssHref = function_exists('cms_runtime_base_url')
                                ? cms_runtime_base_url(ltrim((string) $fontMap[$slug], '/'))
                                : rtrim((string) SITE_URL, '/') . '/' . ltrim((string) $fontMap[$slug], '/');

                            $safeCssHref = htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8');
                            echo '<link rel="stylesheet" href="' . $safeCssHref . '">' . "\n";
                        }
                    }
                } catch (\Throwable $e) {
                    // Tabelle existiert noch nicht – ignorieren
                }
            }, 15);
            Hooks::addAction('body_end', static function () use ($shouldLoadPhotoSwipe): void {
                if (!$shouldLoadPhotoSwipe()) {
                    return;
                }

                echo '<script type="module" src="' . SITE_URL . '/assets/js/photoswipe-init.js"></script>' . "\n";
            }, 10);
        }

        // Initialize hooks
        Hooks::addAction('cms_cron_mail_queue', static function (...$args): void {
            $context = $args[0] ?? null;
            if (is_array($context) && !empty($context['mail_queue_already_handled'])) {
                return;
            }

            Services\MailQueueService::getInstance()->handleCronHook(...$args);
        }, 10);
        Hooks::addAction('cms_cron_hourly', static function (): void {
            Services\SeoBrokenLinkService::getInstance()->runScheduledScan();
        }, 20);
        Hooks::addAction('cms_cron_hourly', static function (): void {
            Services\SeoTrendService::getInstance()->runScheduledSnapshot();
        }, 30);
        Hooks::addAction('cms_cron_hourly', static function (): void {
            Services\MonitoringTrendService::getInstance()->runScheduledSnapshot();
        }, 35);
        Hooks::addAction('cms_cron_hourly', static function (): void {
            Services\SecurityAlertService::getInstance()->runScheduledScan();
        }, 40);
        // Sitemaps nach Inhaltsänderungen verwerfen: die nächste Anfrage auf /sitemap.xml erzeugt
        // das Bundle neu, statt bis zum nächsten Tages-Cron veraltete URLs auszuliefern.
        foreach (['cms_after_post_save', 'cms_after_page_save', 'post_deleted', 'page_deleted'] as $sitemapInvalidationHook) {
            Hooks::addAction($sitemapInvalidationHook, static function (): void {
                try {
                    Services\SEOService::getInstance()->invalidateSavedSitemaps();
                } catch (\Throwable) {
                }
            }, 90);
        }
        Hooks::addAction('cms_cron_daily', static function (): void {
            if (!Services\SEOService::getInstance()->saveSitemapBundle()) {
                throw new \RuntimeException('SEO-Sitemap-Bundle konnte nicht aktualisiert werden.');
            }
        }, 20);
        // Aufbewahrungsfristen für Besucher-/Telemetriedaten, 404-Monitor und Tages-Logdateien.
        Hooks::addAction('cms_cron_daily', static function (): void {
            Services\DataRetentionService::getInstance()->run();
        }, 40);

        // Warmup erst nach der Antwort: opcache_compile_file() darf weder bereits geladene noch im
        // weiteren Request noch einzubindende Klassen-Dateien ein zweites Mal deklarieren.
        register_shutdown_function(static function (): void {
            try {
                Services\OpcacheWarmupService::getInstance()->maybeWarmAfterDeploy(30);
            } catch (\Throwable $e) {
                Logger::instance()->warning('OPcache-Warmup nach Deploy fehlgeschlagen.', [
                    'component' => 'opcache_warmup',
                    'exception' => $e,
                ]);
            }
        });
        Debug::checkpoint('bootstrap.opcache_checked');

        Hooks::doAction('cms_init');
        Hooks::doAction('cms_init_' . $this->mode); // H-12: Modus-spezifischer Hook
        Debug::checkpoint('bootstrap.hooks_initialized', ['mode' => $this->mode]);
    }
    
    /**
     * Run the CMS
     */
    public function run(): void
    {
        if ($this->router === null) {
            throw new \RuntimeException('Bootstrap::run() ist im Modus "' . $this->mode . '" ohne Router nicht verfügbar.');
        }

        Debug::checkpoint('bootstrap.run.start', ['mode' => $this->mode]);

        // Pre-routing hook
        Hooks::doAction('cms_before_route');
        
        // Allow plugins to register routes (MUST be before dispatch)
        Hooks::doAction('register_routes', $this->router);
        
        // Style-Attribute vollständiger HTML-Antworten in nonce-geschützte Klassen überführen (CSP style-src-attr)
        if (in_array($this->mode, ['web', 'admin'], true) && class_exists(Http\InlineStyleRewriter::class)) {
            Http\InlineStyleRewriter::startBuffer(Security::instance()->getNonce());
        }

        // Handle routing
        $this->router->dispatch();
        Debug::checkpoint('bootstrap.run.after_dispatch', ['mode' => $this->mode]);
        
        // Post-routing hook
        Hooks::doAction('cms_after_route');
    }
    
    /**
     * Get database instance
     */
    public function db(): Database
    {
        return $this->db;
    }
    
    /**
     * Get auth instance
     */
    public function auth(): Auth
    {
        return $this->auth;
    }
    
    /**
     * Get security instance
     */
    public function security(): Security
    {
        return $this->security;
    }

    /**
     * H-06: Zugriff auf den Service-Container
     */
    public function container(): Container
    {
        return $this->container;
    }
}
