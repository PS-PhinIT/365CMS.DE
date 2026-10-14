<?php
/**
 * Router Class
 *
 * Handles URL routing and request dispatching
 *
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

class Router
{
    /**
     * Filter für öffentliche Formulare mit sessiongebundenem Persistent-Token (statt Einmal-Token `form_guard`).
     * Callback: fn(array|null $current, string $routingUri, string $method): ?array – Rückgabe
     * `['action' => 'token-aktion', 'token' => 'übermittelter Token']` oder null. Themes prüfen die
     * Unterstützung über defined('\CMS\Router::PUBLIC_FORM_TOKEN_FILTER').
     */
    public const PUBLIC_FORM_TOKEN_FILTER = 'cms_public_form_persistent_token';

    private static ?self $instance = null;
    private string $requestUri;
    private string $requestMethod;
    private array $routes = [];
    private array $requestContext = [
        'uri' => '/',
        'base_uri' => '/',
        'locale' => 'de',
        'is_localized' => false,
    ];
    private bool $notFoundLogged = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->requestUri = $this->resolveRequestUri();
        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->registerDefaultRoutes();
    }

    private function resolveRequestUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        if (SITE_URL_PATH !== '/') {
            $uri = str_replace(SITE_URL_PATH, '', $uri);
        }

        return '/' . trim($uri, '/');
    }

    private function registerDefaultRoutes(): void
    {
        if ($this->isApiRequest($this->requestUri)) {
            $this->loadRouteModule('ApiRouter');
            return;
        }

        if ($this->isAdminRequest($this->requestUri)) {
            $this->loadRouteModule('AdminRouter');
            return;
        }

        if ($this->isMemberRequest($this->requestUri)) {
            $this->loadRouteModule('MemberRouter');
            return;
        }

        $this->loadRouteModule('PublicRouter');
        $this->loadRouteModule('ThemeRouter');
    }

    private function loadRouteModule(string $moduleName): void
    {
        $className = 'CMS\\Routing\\' . $moduleName;
        if (!class_exists($className, false)) {
            $file = CORE_PATH . 'Routing/' . $moduleName . '.php';
            if (!is_file($file)) {
                Logger::instance()->withChannel('router')->warning('Route module file not found.', [
                    'module' => $moduleName,
                    'file' => $file,
                ]);
                return;
            }

            require_once $file;
        }

        if (!class_exists($className)) {
            Logger::instance()->withChannel('router')->warning('Route module class could not be loaded.', [
                'module' => $moduleName,
                'class' => $className,
            ]);
            return;
        }

        $module = new $className($this);
        if (!method_exists($module, 'registerRoutes')) {
            Logger::instance()->withChannel('router')->warning('Route module does not expose registerRoutes().', [
                'module' => $moduleName,
                'class' => $className,
            ]);
            return;
        }

        $module->registerRoutes();
    }

    private function isApiRequest(string $uri): bool
    {
        return $uri === '/api' || str_starts_with($uri, '/api/');
    }

    private function isAdminRequest(string $uri): bool
    {
        return $uri === '/admin' || str_starts_with($uri, '/admin/');
    }

    private function isMemberRequest(string $uri): bool
    {
        $prefixes = ['/member', '/dashboard'];
        foreach ($prefixes as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    public function addRoute(string $method, string $path, callable $callback): void
    {
        $this->routes[$method][$path] = $callback;
    }

    public function dispatch(): void
    {
        $method = $this->requestMethod;
        $routeMethod = $method === 'HEAD' ? 'GET' : $method;
        $uri = $this->requestUri;
        Debug::checkpoint('router.dispatch.start', ['method' => $method, 'uri' => $uri]);
        $this->requestContext = Services\ContentLocalizationService::getInstance()->resolveRequestContext($uri);
        $routingUri = (string)($this->requestContext['base_uri'] ?? $uri);

        $this->applyRequestCacheHeaders($routingUri, $method);

        // Apache-ErrorDocument (siehe .htaccess): Der Webserver leitet eigene Fehler (z. B. 403 für gesperrte
        // Pfade) intern an index.php weiter und setzt REDIRECT_STATUS. Normales Rewrite-Routing liefert 200.
        $webServerErrorStatus = (int) ($_SERVER['REDIRECT_STATUS'] ?? 0);
        if ($webServerErrorStatus >= 400 && $webServerErrorStatus <= 599 && !$this->isApiRequest($routingUri)) {
            $this->renderError($webServerErrorStatus);
            return;
        }

        if ($this->maybeRedirectWwwAliasHost()) {
            return;
        }

        if ($this->maybeRedirectHubAliasDomain($routingUri)) {
            return;
        }

        if (class_exists('\\CMS\\Services\\RedirectService')) {
            $redirect = Services\RedirectService::getInstance()->findRedirect(
                $uri,
                (string)($_SERVER['HTTP_HOST'] ?? '')
            );
            if (is_array($redirect) && !empty($redirect['target_url'])) {
                $this->redirect((string)$redirect['target_url'], (int)($redirect['redirect_type'] ?? 301));
            }
        }

        if ($this->isApiRequest($routingUri)) {
            // Optional: API-Clients per JWT-Bearer-Token anmelden (nur wenn JWT_SECRET gesetzt ist).
            Auth::instance()->authenticateBearerToken();
        }

        if ($this->shouldServeMaintenancePage($routingUri)) {
            $this->renderMaintenancePage($routingUri);
            return;
        }

        $csrfBypassPrefixes = ['/contact/'];
        $csrfBypassExact = ['/login', '/register', '/forgot-password', '/cms-login', '/cms-register', '/cms-password-forgot', '/logout', '/contact', '/kontakt', '/comments/post', '/mfa-challenge', '/mfa-setup', '/mfa-disable'];
            $isThemeFavoriteToggle = $method === 'POST'
                && (string) ($_POST['phinit_toggle_favorite'] ?? '') === '1'
                && in_array((string) ($_POST['favorite_content_type'] ?? ''), ['post', 'page'], true)
                && (int) ($_POST['favorite_content_id'] ?? 0) > 0;
        $isProtectedMethod = in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true);

        if ($isProtectedMethod
            && !$this->isM365LicPublicEvaluationRequest($routingUri, $method)
            && !$this->isApiRequest($routingUri)
            && !$this->isAdminRequest($routingUri)
            && !$this->isMemberRequest($routingUri)
            && !in_array($routingUri, $csrfBypassExact, true)
            && !array_reduce($csrfBypassPrefixes, fn(bool $carry, string $prefix): bool => $carry || str_starts_with($routingUri, $prefix), false)
        ) {
            $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));
            $hasValidThemeFavoriteToken = false;

            // Generischer Weg: Theme/Plugin nennt Token-Aktion und Token für dieses Formular.
            $persistentFormToken = Hooks::applyFilters(self::PUBLIC_FORM_TOKEN_FILTER, null, $routingUri, $method);
            if (is_array($persistentFormToken)) {
                $persistentAction = trim((string) ($persistentFormToken['action'] ?? ''));
                $persistentToken = trim((string) ($persistentFormToken['token'] ?? ''));
                $hasValidThemeFavoriteToken = $persistentAction !== ''
                    && $persistentToken !== ''
                    && Security::instance()->verifyPersistentToken($persistentToken, $persistentAction);
            }

            // Legacy: PHINIT-Theme bis 1.7.34 (eine Token-Aktion pro Beitrag/Seite).
            if (!$hasValidThemeFavoriteToken && $isThemeFavoriteToggle) {
                $favoriteType = (string) ($_POST['favorite_content_type'] ?? '');
                $favoriteId = (int) ($_POST['favorite_content_id'] ?? 0);
                $favoriteAction = 'phinit_favorite_' . $favoriteType . '_' . $favoriteId;
                $hasValidThemeFavoriteToken = Security::instance()->verifyPersistentToken(
                    (string) ($_POST['favorite_csrf_token'] ?? ''),
                    $favoriteAction
                );
            }

            if (!$hasValidThemeFavoriteToken && $csrfToken === '') {
                $this->respondWithPublicPostNotFound($routingUri);
            }

            if (!$hasValidThemeFavoriteToken && !Security::instance()->verifyToken($csrfToken, 'form_guard')) {
                http_response_code(403);
                Logger::instance()->withChannel('router')->warning('CSRF validation failed.', [
                    'method' => $method,
                    'routing_uri' => $routingUri,
                ]);
                if ($this->isAjaxRequest()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'CSRF-Sicherheitsüberprüfung fehlgeschlagen.']);
                } else {
                    $this->renderError(
                        403,
                        'Sicherheitsprüfung fehlgeschlagen',
                        'Das Formular war abgelaufen oder ungültig. Lade die vorherige Seite neu und sende es erneut.'
                    );
                }
                exit;
            }
        }

        if (isset($this->routes[$routeMethod][$routingUri])) {
            Debug::checkpoint('router.route.exact_match', ['method' => $routeMethod, 'uri' => $routingUri]);
            call_user_func($this->routes[$routeMethod][$routingUri]);
            return;
        }

        foreach ($this->routes[$routeMethod] ?? [] as $pattern => $callback) {
            $params = $this->matchRoute($pattern, $routingUri);
            if ($params !== false) {
                Debug::checkpoint('router.route.pattern_match', ['method' => $routeMethod, 'pattern' => $pattern, 'uri' => $routingUri]);
                call_user_func_array($callback, $params);
                return;
            }
        }

        // Route existiert, aber nicht für diese Methode → 405 statt 404 (RFC 9110 inkl. Allow-Header).
        $allowedMethods = $this->findAllowedMethods($routingUri, $routeMethod);
        if ($allowedMethods !== []) {
            if (!headers_sent()) {
                header('Allow: ' . implode(', ', $allowedMethods));
            }
            $this->renderError(405);
            return;
        }

        $landingAliasPath = $this->resolveLandingAliasPath();
        if ($landingAliasPath !== '' && $routingUri === $landingAliasPath) {
            Debug::checkpoint('router.route.landing_alias', ['uri' => $routingUri, 'alias' => $landingAliasPath]);
            ThemeManager::instance()->render('home');
            return;
        }

        if (!$this->shouldAttemptDynamicPageLookup($routingUri)) {
            Debug::checkpoint('router.route.404_direct', ['uri' => $routingUri]);
            $this->render404();
            return;
        }

        Debug::checkpoint('router.route.dynamic_lookup', ['uri' => $routingUri]);
        $this->dispatchDynamicPage($routingUri, $uri);
    }

    /**
     * Wartungsmodus (Option `maintenance_mode`): Besucher erhalten 503, Admins sowie
     * Admin-, Login- und Auth-Routen bleiben erreichbar.
     */
    private function shouldServeMaintenancePage(string $routingUri): bool
    {
        if ($this->isAdminRequest($routingUri)) {
            return false;
        }

        $exempt = ['/login', '/logout', '/cms-login', '/cms-password-forgot', '/forgot-password', '/mfa-challenge', '/mfa-setup', '/mfa-disable', '/health'];
        if (in_array($routingUri, $exempt, true)) {
            return false;
        }

        if (Services\OptionStore::getInstance()->get('maintenance_mode', '0') !== '1') {
            return false;
        }

        if (Auth::isAdmin()) {
            return false;
        }

        // Konfigurierte (ggf. umbenannte) Login-/Passwort-Pfade freihalten, damit Admins sich anmelden können.
        try {
            $authPages = Services\CmsAuthPageService::getInstance();
            foreach (['login', 'forgot-password'] as $authPage) {
                if (rtrim($authPages->getPublicPath($authPage, $this->getRequestLocale()), '/') === rtrim($routingUri, '/')) {
                    return false;
                }
            }
        } catch (\Throwable) {
        }

        return true;
    }

    private function renderMaintenancePage(string $routingUri): void
    {
        $message = (string) Services\OptionStore::getInstance()->get('maintenance_message', '');
        if (trim($message) === '') {
            $message = 'Die Website wird gerade gewartet.';
        }

        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 3600');
            header('Cache-Control: no-store, private');
            header('Content-Type: ' . ($this->isApiRequest($routingUri) ? 'application/json' : 'text/html') . '; charset=utf-8');
        }

        if ($this->isApiRequest($routingUri)) {
            echo json_encode(['success' => false, 'error' => 'maintenance'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Theme-Fehlerseite im eigenständigen Modus (ohne Navigation – Links würden nur wieder hier landen).
        $plainMessage = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($this->renderThemeErrorTemplate(503, 'Wartungsmodus', $plainMessage, ['error_standalone' => true, 'error_retry_after' => 3600])) {
            return;
        }

        // Nachricht ist beim Speichern auf <p><strong><em><br> begrenzt (SettingsModule).
        $safeMessage = (string) preg_replace('/<(\/?)(p|strong|em|br)\b[^>]*>/i', '<$1$2>', strip_tags($message, '<p><strong><em><br>'));
        $siteName = defined('SITE_NAME') ? htmlspecialchars((string) SITE_NAME, ENT_QUOTES, 'UTF-8') : '365CMS';
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex"><title>Wartungsmodus – ' . $siteName . '</title>'
            . '<style ' . Security::instance()->nonceAttr() . '>body{font-family:system-ui,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;background:#f8fafc;color:#0f172a}main{max-width:36rem;padding:2rem;text-align:center}</style></head>'
            . '<body><main><h1>' . $siteName . '</h1><div>' . $safeMessage . '</div></main></body></html>';
    }

    private function shouldAttemptDynamicPageLookup(string $routingUri): bool
    {
        foreach (['/api', '/admin', '/member'] as $prefix) {
            if ($routingUri === $prefix || str_starts_with($routingUri, $prefix . '/')) {
                return false;
            }
        }

        return true;
    }

    private function dispatchDynamicPage(string $routingUri, string $uri): void
    {
        try {
            $slug = trim($routingUri, '/');
            if ($slug === '') {
                $slug = 'home';
            }

            $hubPage = Services\SiteTableService::getInstance()->getHubPageBySlug($slug, $this->getRequestLocale());
            if ($hubPage !== null) {
                if (!$this->sendConditionalPublicPageHeaders('hub', $hubPage, $this->getRequestLocale())) {
                    return;
                }

                ThemeManager::instance()->render('page', ['page' => $hubPage, 'contentLocale' => $this->getRequestLocale()]);
                return;
            }

            $pageManager = PageManager::instance();

            $page = $pageManager->getPageBySlug($slug, $this->getRequestLocale());
            $locale = $this->getRequestLocale();
            if ($page && $page['status'] === 'published' && $pageManager->pageMatchesLocaleAvailability($page, $locale)) {
                $requestBaseUri = (string)($this->requestContext['base_uri'] ?? $routingUri);
                $canonicalPath = $this->buildLocalizedPagePath($page, $locale);
                $localizedCanonicalContext = Services\ContentLocalizationService::getInstance()->resolveRequestContext($canonicalPath);
                $expectedRequestBasePath = $locale === 'de'
                    ? $canonicalPath
                    : (string) ($localizedCanonicalContext['base_uri'] ?? $canonicalPath);

                if ($requestBaseUri !== '' && $requestBaseUri !== $expectedRequestBasePath) {
                    $query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
                    $target = $canonicalPath . ($query !== '' ? '?' . $query : '');
                    $this->redirect($target, 301);
                    return;
                }

                if (!empty($page['content'])) {
                    $page['updated_at'] = Services\SiteTableService::getInstance()->resolveContentLastModified(
                        (string) $page['content'],
                        (string) ($page['updated_at'] ?? $page['created_at'] ?? '')
                    );
                }

                if (!$this->sendConditionalPublicPageHeaders('page', $page, $locale)) {
                    return;
                }

                $page = Services\ContentLocalizationService::getInstance()->localizePage($page, $locale);
                if (!empty($page['content'])) {
                    $showTitleToc = !empty($page['show_title_toc']);
                    $page['content'] = $this->prepareRenderableContent((string)$page['content'], 'page', (int)($page['id'] ?? 0), [
                        'skip_toc' => $showTitleToc,
                    ]);

                    if ($showTitleToc) {
                        $titleTocResult = TableOfContents::instance()->buildPageTitleToc((string)$page['content']);
                        $page['content'] = (string)($titleTocResult['toc'] ?? '') . (string)($titleTocResult['content'] ?? $page['content']);
                    }
                }
                if (isset($_GET['pdf']) && $_GET['pdf'] === '1') {
                    $this->streamContentAsPdf(
                        htmlspecialchars((string)($page['title'] ?? 'Seite'), ENT_QUOTES, 'UTF-8'),
                        (string)$page['content'],
                        null
                    );
                    return;
                }

                ThemeManager::instance()->render('page', ['page' => $page, 'contentLocale' => $locale]);
                return;
            }

            if (CMS_DEBUG) {
                if ($page === null) {
                    Logger::instance()->withChannel('router')->debug('Dynamic page lookup returned no page.', [
                        'slug' => $slug,
                    ]);
                } else {
                    Logger::instance()->withChannel('router')->debug('Dynamic page lookup returned a non-published page.', [
                        'slug' => $slug,
                        'page_id' => (int) ($page['id'] ?? 0),
                        'status' => (string) ($page['status'] ?? 'unbekannt'),
                    ]);
                }
            }

            if ($this->redirectLegacyEnglishPostSlug($routingUri)) {
                return;
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('router')->warning('Dynamic page lookup failed.', [
                'slug' => $slug ?? $uri,
                'request_uri' => $uri,
                'exception' => $e,
            ]);
        }

        $this->render404();
    }

    private function redirectLegacyEnglishPostSlug(string $routingUri): bool
    {
        if ($this->getRequestLocale() !== 'en' || !in_array($this->requestMethod, ['GET', 'HEAD'], true)) {
            return false;
        }

        $slug = trim($routingUri, '/');
        if ($slug === '' || str_contains($slug, '/')) {
            return false;
        }

        $slug = rawurldecode($slug);
        $db = Database::instance();
        $post = $db->get_row(
            "SELECT p.slug, p.slug_en, p.published_at, p.created_at
             FROM {$db->getPrefix()}posts p
             WHERE p.slug = ?
               AND p.slug_en IS NOT NULL
               AND TRIM(p.slug_en) <> ''
               AND p.slug_en <> p.slug
               AND " . \cms_post_publication_where('p') . "
             LIMIT 1",
            [$slug]
        );

        if (!$post) {
            return false;
        }

        $target = Services\PermalinkService::getInstance()->buildPostPath($post, 'en');
        $query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
        if ($query !== '') {
            $target .= '?' . $query;
        }

        $this->redirect($target, 301);
        return true;
    }

    /**
     * Leitet den reinen www-Alias (bzw. die Variante ohne www) per 301 auf den Host aus SITE_URL um.
     * Sonst lädt die Seite auf dem Alias-Host, während Medien-URLs auf SITE_URL zeigen (CSP/Cookies/Duplicate Content).
     */
    private function maybeRedirectWwwAliasHost(): bool
    {
        if (!in_array($this->requestMethod, ['GET', 'HEAD'], true) || PHP_SAPI === 'cli') {
            return false;
        }

        $siteUrl = rtrim((string) SITE_URL, '/');
        $requestHost = $this->normalizeHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $mainHost = $this->normalizeHost((string) (parse_url($siteUrl, PHP_URL_HOST) ?? ''));
        if ($requestHost === '' || $mainHost === '' || $requestHost === $mainHost) {
            return false;
        }

        if ($requestHost !== 'www.' . $mainHost && 'www.' . $requestHost !== $mainHost) {
            return false;
        }

        // Explizit als Hub-Domain konfigurierte www-Hosts nicht umleiten.
        try {
            if (Services\SiteTableService::getInstance()->getHubPageByDomain($requestHost, $this->getRequestLocale()) !== null) {
                return false;
            }
        } catch (\Throwable) {
        }

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($requestUri === '' || $requestUri[0] !== '/' || preg_match('/[\r\n]/', $requestUri) === 1) {
            $requestUri = '/';
        }

        if (headers_sent()) {
            return false;
        }

        http_response_code(301);
        header('Location: ' . $siteUrl . $requestUri, true, 301);
        exit;
    }

    private function maybeRedirectHubAliasDomain(string $routingUri): bool
    {
        if (!in_array($this->requestMethod, ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($routingUri !== '/') {
            return false;
        }

        $requestHost = $this->normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
        $mainHost = $this->normalizeHost((string)(parse_url((string)SITE_URL, PHP_URL_HOST) ?? ''));
        if ($requestHost === '' || $mainHost === '' || $requestHost === $mainHost) {
            return false;
        }

        $locale = $this->getRequestLocale();
        $hubPage = Services\SiteTableService::getInstance()->getHubPageByDomain($requestHost, $locale);
        if ($hubPage === null) {
            return false;
        }

        $slug = trim((string)($hubPage['slug'] ?? ''), '/');
        if ($slug === '') {
            return false;
        }

        $targetPath = '/' . $slug;
        if ($locale !== '' && $locale !== 'de') {
            $targetPath = Services\ContentLocalizationService::getInstance()->buildLocalizedPath($targetPath, $locale);
        }

        $query = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? '');
        if ($query !== '') {
            $targetPath .= '?' . $query;
        }

        $this->redirect($targetPath, 302);
        return true;
    }

    private function matchRoute(string $pattern, string $uri): array|false
    {
        $pattern = preg_replace('/:([a-zA-Z0-9_]+)/', '([a-zA-Z0-9_-]+)', $pattern);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $uri, $matches)) {
            array_shift($matches);
            return $matches;
        }

        return false;
    }

    private function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private function isM365LicPublicEvaluationRequest(string $routingUri, string $method): bool
    {
        if ($method !== 'POST') {
            return false;
        }

        if (!isset($_POST['evaluation_csrf_token']) || !array_key_exists('requirements_payload', $_POST)) {
            return false;
        }

        $routeSlug = $this->resolveM365LicRouteSlug();
        if ($routeSlug === '') {
            return false;
        }

        return in_array($routingUri, [
            '/' . $routeSlug,
            '/' . $routeSlug . '/eu-vergleich',
        ], true);
    }

    private function resolveM365LicRouteSlug(): string
    {
        static $routeSlug = null;

        if ($routeSlug !== null) {
            return $routeSlug;
        }

        $routeSlug = '';

        if (!class_exists('\\CMS_M365LIC_Repository')) {
            return $routeSlug;
        }

        try {
            $settings = \CMS_M365LIC_Repository::instance()->get_settings();
            $routeSlug = trim((string) ($settings['route_slug'] ?? 'm365-lizenzberater'), '/');
        } catch (\Throwable $e) {
            $routeSlug = '';
        }

        return $routeSlug;
    }

    private function respondWithPublicPostNotFound(string $routingUri): never
    {
        // Öffentlicher POST ohne CSRF-Token: Existiert der Pfad für andere Methoden, ist es 405, sonst 404 –
        // jeweils als Fehlerseite des aktiven Themes.
        $allowedMethods = $this->findAllowedMethods($routingUri, 'POST');
        if ($allowedMethods !== []) {
            if (!headers_sent()) {
                header('Allow: ' . implode(', ', $allowedMethods));
            }
            $this->renderError(405);
            exit;
        }

        $this->render404();
        exit;
    }

    private function applyRequestCacheHeaders(string $routingUri, string $method): void
    {
        $cache = CacheManager::instance();

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $cache->sendResponseHeaders('private');
            return;
        }

        if ($this->requestHasPersonalizedAuthState()) {
            $cache->sendResponseHeaders('private');
            return;
        }

        foreach (['/admin', '/member', '/api'] as $prefix) {
            if ($routingUri === $prefix || str_starts_with($routingUri, $prefix . '/')) {
                $cache->sendResponseHeaders('private');
                return;
            }
        }

        if (in_array($routingUri, ['/login', '/register', '/forgot-password', '/cms-login', '/cms-register', '/cms-password-forgot', '/logout', '/mfa-challenge', '/mfa-setup', '/order'], true)) {
            $cache->sendResponseHeaders('private');
            return;
        }

        $publicHtmlTtl = $this->resolvePublicHtmlCacheTtl();
        if ($publicHtmlTtl <= 0) {
            $cache->sendResponseHeaders('public_no_cache', 0);
            return;
        }

        $cache->sendResponseHeaders('public', $publicHtmlTtl);
    }

    private function requestHasPersonalizedAuthState(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ((int)($_SESSION['user_id'] ?? 0) > 0) {
                return true;
            }

            if ((int)($_SESSION['mfa_pending_user_id'] ?? 0) > 0) {
                return true;
            }
        }

        try {
            if (Auth::instance()->isLoggedIn()) {
                return true;
            }
        } catch (\Throwable) {
        }

        return trim((string)($_COOKIE['cms_device'] ?? '')) !== '';
    }

    private function resolvePublicHtmlCacheTtl(): int
    {
        $ttl = 300;

        try {
            // Über den OptionStore: die Autoload-Optionen sind meist schon geladen (keine Extra-Abfrage).
            $options = Services\OptionStore::getInstance()->getMany(['perf_page_cache', 'perf_html_cache_ttl']);
            $pageCacheEnabled = (string) ($options['perf_page_cache'] ?? '1') !== '0';
            $configuredTtl = isset($options['perf_html_cache_ttl']) ? (int) $options['perf_html_cache_ttl'] : $ttl;

            if (!$pageCacheEnabled) {
                return 0;
            }

            if ($configuredTtl > 0) {
                $ttl = $configuredTtl;
            }
        } catch (\Throwable) {
            return $ttl;
        }

        return max(60, min(86400, $ttl));
    }

    private function sendConditionalPublicPageHeaders(string $type, array $resource, string $locale): bool
    {
        if (!in_array($this->requestMethod, ['GET', 'HEAD'], true)) {
            return true;
        }

        if ($this->requestHasPersonalizedAuthState()) {
            return true;
        }

        $resourceId = (int)($resource['id'] ?? 0);
        if ($resourceId <= 0) {
            return true;
        }

        $lastModified = (string)($resource['updated_at'] ?? $resource['created_at'] ?? '');
        if ($lastModified === '') {
            return true;
        }

        return CacheManager::instance()->sendConditionalHeaders(
            $type . ':' . $resourceId . ':' . $locale,
            $lastModified
        );
    }

    private function buildLocalizedPagePath(array $page, string $locale): string
    {
        $localization = Services\ContentLocalizationService::getInstance();
        $resolvedSlug = $localization->resolveLocalizedSlug($page, $locale);
        $pagePath = '/' . ltrim($resolvedSlug !== '' ? $resolvedSlug : 'home', '/');

        return $localization->buildLocalizedPath($pagePath, $locale);
    }

    private function resolveLandingAliasPath(): string
    {
        if (!function_exists('get_option')) {
            return '';
        }

        $landingSlug = trim((string) get_option('landing_slug', ''));
        if ($landingSlug === '' || $landingSlug === '/') {
            return '';
        }

        return '/' . trim($landingSlug, '/');
    }

    public function render404(): void
    {
        Debug::checkpoint('router.render_404', ['uri' => $this->requestUri]);
        if (!$this->notFoundLogged && class_exists('\\CMS\\Services\\RedirectService')) {
            Services\RedirectService::getInstance()->logNotFound($this->requestUri, [
                'request_host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
                'request_method' => $this->requestMethod,
                'referrer_url' => (string)($_SERVER['HTTP_REFERER'] ?? ''),
                'ip_address' => Security::getClientIp(),
                'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);
            $this->notFoundLogged = true;
        }

        http_response_code(404);

        $rendered = '';

        try {
            ob_start();
            ThemeManager::instance()->render('404');
            $rendered = (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            Logger::instance()->withChannel('router')->error('404 page rendering failed.', [
                'request_uri' => $this->requestUri,
                'exception' => $e,
            ]);
            $rendered = '';
        }

        if (trim($rendered) !== '') {
            echo $rendered;
            return;
        }

        echo $this->buildFallbackErrorPage(
            404,
            'Seite nicht gefunden',
            'Die angeforderte Seite konnte nicht gefunden werden.',
            $this->requestUri
        );
    }

    public function getRequestLocale(): string
    {
        return (string)($this->requestContext['locale'] ?? 'de');
    }

    public function getRequestContext(): array
    {
        return $this->requestContext;
    }

    public function prepareRenderableContent(string $content, string $type, int $id = 0, array $options = []): string
    {
        $content = Services\EditorService::getInstance()->renderContent($content);
        $content = Services\SiteTableService::getInstance()->replaceShortcodes($content);

        if (!empty($options['skip_toc'])) {
            $prepared = str_replace('[cms_toc]', '', $content);
        } else {
            $tocResult = TableOfContents::instance()->process($content, $type, $id);
            $prepared = (string)($tocResult['content'] ?? $content);
            $tocHtml = (string)($tocResult['toc'] ?? '');

            if ($tocHtml !== '') {
                $prepared = $this->injectTocIntoContent(
                    $prepared,
                    $tocHtml,
                    (string)TableOfContents::instance()->getSetting('position', 'before')
                );
            }
        }

        $prepared = (string) Hooks::applyFilters('content_render', $prepared, $type, $id);

        return $prepared;
    }

    private function injectTocIntoContent(string $content, string $tocHtml, string $position): string
    {
        if ($tocHtml === '') {
            return $content;
        }

        return match ($position) {
            'top' => $tocHtml . $content,
            'bottom' => $content . $tocHtml,
            'after' => $this->insertAfterFirstHeading($content, $tocHtml),
            default => $this->insertBeforeFirstHeading($content, $tocHtml),
        };
    }

    private function insertBeforeFirstHeading(string $content, string $html): string
    {
        if (preg_match('/<h[1-6]\b[^>]*>/i', $content, $match, PREG_OFFSET_CAPTURE)) {
            $position = (int)($match[0][1] ?? 0);
            return substr($content, 0, $position) . $html . substr($content, $position);
        }

        return $html . $content;
    }

    private function insertAfterFirstHeading(string $content, string $html): string
    {
        if (preg_match('/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', $content, $match, PREG_OFFSET_CAPTURE)) {
            $fullMatch = (string)($match[0][0] ?? '');
            $position = (int)($match[0][1] ?? 0) + strlen($fullMatch);
            return substr($content, 0, $position) . $html . substr($content, $position);
        }

        return $html . $content;
    }

    public function redirect(string $url, int $status = 302): void
    {
        $status = $this->normalizeRedirectStatus($status);
        $targetPath = $this->resolveRedirectPath($url);
        $locationPath = $this->buildRedirectLocationPath($targetPath);

        if (!headers_sent()) {
            http_response_code($status);
            header('Location: ' . $locationPath, true, $status);
            exit;
        }

        http_response_code($status);
        echo $this->buildRedirectFallbackPage(SITE_URL . $locationPath, $status);
        exit;
    }

    private function normalizeRedirectStatus(int $status): int
    {
        return in_array($status, [301, 302, 303, 307, 308], true) ? $status : 302;
    }

    private function buildRedirectLocationPath(string $targetPath): string
    {
        $targetPath = $this->normalizeInternalRedirectPath($targetPath);
        if ($targetPath === '') {
            $targetPath = '/';
        }

        return '/' . ltrim($targetPath, '/');
    }

    private function resolveRedirectPath(string $url): string
    {
        $normalizedPath = $this->normalizeInternalRedirectPath($url);
        if ($normalizedPath === '') {
            return '/';
        }

        return $normalizedPath;
    }

    private function normalizeInternalRedirectPath(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return '';
        }

        if (str_starts_with($url, '//')) {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return '';
        }

        if (!str_starts_with($url, '/')) {
            $url = '/' . ltrim($url, '/');
        }

        if (preg_match('#^/(?:[A-Za-z0-9\-._~%!$&()*+,;=:@/]*)?(?:\?[A-Za-z0-9\-._~%!$&()*+,;=:@/?]*)?(?:\#[A-Za-z0-9\-._~%!$&()*+,;=:@/?]*)?$#', $url) !== 1) {
            return '';
        }

        if (str_contains($url, '/../') || str_contains($url, '/./') || str_ends_with($url, '/..') || str_ends_with($url, '/.')) {
            return '';
        }

        return $url;
    }

    private function normalizeHost(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, ':')) {
            $value = explode(':', $value, 2)[0];
        }

        return trim($value, '.');
    }

    private function buildRedirectFallbackPage(string $targetUrl, int $status): string
    {
        $safeUrl = htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8');
        $safeStatus = max(300, min(399, $status));

        return '<!DOCTYPE html>'
            . '<html lang="de"><head><meta charset="utf-8">'
            . '<meta http-equiv="refresh" content="0;url=' . $safeUrl . '">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>Weiterleitung</title></head><body>'
            . '<h1>Weiterleitung</h1>'
            . '<p>Die Seite leitet weiter (' . $safeStatus . '). Falls nichts passiert, nutze bitte diesen Link:</p>'
            . '<p><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>'
            . '</body></html>';
    }

    /**
     * Rendert eine HTTP-Fehlerseite (4xx/5xx) mit dem aktiven Theme.
     *
     * Reihenfolge: Theme-Template `<status>.php` (z. B. 403.php), sonst `error.php` mit `$error_code`;
     * 404 nutzt render404(). Ohne passendes Theme-Template greift die neutrale Fallback-Seite.
     */
    public function renderError(int $status, string $title = '', string $message = ''): void
    {
        $status = $status >= 400 && $status <= 599 ? $status : 500;

        if ($status === 404) {
            $this->render404();
            return;
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Cache-Control: no-store, private');
            if (in_array($status, [429, 503], true)) {
                header('Retry-After: 120');
            }
        }

        if ($this->renderThemeErrorTemplate($status, $title, $message)) {
            return;
        }

        echo $this->buildFallbackErrorPage(
            $status,
            $title !== '' ? $title : 'Fehler ' . $status,
            $message !== '' ? $message : 'Die Anfrage konnte nicht verarbeitet werden.',
            $this->requestUri
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function renderThemeErrorTemplate(int $status, string $title, string $message, array $extra = []): bool
    {
        try {
            $themeManager = ThemeManager::instance();
            $themePath = rtrim($themeManager->getThemePath(), '/\\') . DIRECTORY_SEPARATOR;
            $template = is_file($themePath . $status . '.php') ? (string) $status : (is_file($themePath . 'error.php') ? 'error' : '');
            if ($template === '') {
                return false;
            }

            $data = array_merge([
                'error_code' => $status,
                'error_title' => $title,
                'error_message' => $message,
            ], $extra);
            if ($title !== '') {
                // Seitentitel für Themes, die getHeader(['title' => …]) auswerten; sonst bestimmt das Theme ihn.
                $data['title'] = $status . ' – ' . $title;
            }

            ob_start();
            if (!empty($data['error_standalone'])) {
                (static function (string $__file, array $__data): void {
                    extract($__data, EXTR_SKIP);
                    include $__file;
                })($themePath . $template . '.php', $data);
            } else {
                $themeManager->render($template, $data);
            }
            $rendered = (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            Logger::instance()->withChannel('router')->error('Error page rendering failed.', [
                'status' => $status,
                'request_uri' => $this->requestUri,
                'exception' => $e,
            ]);

            return false;
        }

        if (trim($rendered) === '') {
            return false;
        }

        echo $rendered;
        return true;
    }

    /**
     * Methoden, für die der Pfad registriert ist – leer, wenn er für keine andere Methode existiert.
     *
     * @return list<string>
     */
    private function findAllowedMethods(string $routingUri, string $currentMethod): array
    {
        $allowed = [];
        foreach ($this->routes as $routeMethod => $routes) {
            if ($routeMethod === $currentMethod) {
                continue;
            }

            $matches = isset($routes[$routingUri]);
            if (!$matches) {
                foreach (array_keys($routes) as $pattern) {
                    if ($this->matchRoute((string) $pattern, $routingUri) !== false) {
                        $matches = true;
                        break;
                    }
                }
            }

            if ($matches) {
                $allowed[] = (string) $routeMethod;
                if ($routeMethod === 'GET') {
                    $allowed[] = 'HEAD';
                }
            }
        }

        return array_values(array_unique($allowed));
    }

    private function buildFallbackErrorPage(int $status, string $title, string $message, string $path = ''): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $safePath = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
        $details = $safePath !== '' ? '<p><small>Angefragter Pfad: ' . $safePath . '</small></p>' : '';

        return '<!DOCTYPE html>'
            . '<html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . (int) $status . ' – ' . $safeTitle . '</title>'
            . '<style ' . \CMS\Security::instance()->nonceAttr() . '>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f8fafc;color:#0f172a}main{max-width:720px;margin:8vh auto;padding:2rem}a{color:#2563eb}h1{margin-bottom:.75rem}p{line-height:1.6}</style>'
            . '</head><body><main>'
            . '<p>' . (int) $status . '</p>'
            . '<h1>' . $safeTitle . '</h1>'
            . '<p>' . $safeMessage . '</p>'
            . $details
            . '<p><a href="' . htmlspecialchars(SITE_URL . '/', ENT_QUOTES, 'UTF-8') . '">Zur Startseite</a></p>'
            . '</main></body></html>';
    }

    public function streamContentAsPdf(string $title, string $htmlContent, ?string $author): void
    {
        $pdf = Services\PdfService::getInstance();
        if (!$pdf->isAvailable()) {
            http_response_code(503);
            echo 'PDF-Generierung ist nicht verfügbar.';
            return;
        }

        $html = $pdf->wrapTemplate($title, $htmlContent);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', mb_substr($title, 0, 80)) . '.pdf';
        $pdf->streamFromHtml($html, $safeFilename);
    }
}
