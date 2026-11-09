<?php
/**
 * Admin Router Module
 *
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS\Routing;

use CMS\Auth;
use CMS\Hooks;
use CMS\Router;
use CMS\Services\CmsAuthPageService;
use CMS\Services\FeatureUsageService;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminRouter
{
    private FeatureUsageService $featureUsage;

    public function __construct(private readonly Router $router)
    {
        $this->featureUsage = FeatureUsageService::getInstance();
    }

    public function registerRoutes(): void
    {
        $this->router->addRoute('GET', '/admin', [$this, 'renderAdmin']);
        $this->router->addRoute('POST', '/admin', [$this, 'renderAdmin']);
        $this->router->addRoute('GET', '/admin/:page', [$this, 'renderAdminPage']);
        $this->router->addRoute('POST', '/admin/:page', [$this, 'renderAdminPage']);
        $this->router->addRoute('GET', '/admin/logs/:section', [$this, 'renderAdminLogsSection']);
        $this->router->addRoute('POST', '/admin/logs/:section', [$this, 'renderAdminLogsSection']);
        $this->router->addRoute('GET', '/admin/plugins/:plugin/:page', [$this, 'renderPluginPage']);
        $this->router->addRoute('POST', '/admin/plugins/:plugin/:page', [$this, 'renderPluginPage']);
    }

    public function renderAdmin(): void
    {
        if (!Auth::instance()->isAdmin()) {
            $this->redirectUnauthorized();
            return;
        }

        $candidates = [
            ABSPATH . 'admin/index.php',
            ABSPATH . 'admin/modules/dashboard/page.php',
            ABSPATH . 'admin/old/index.php',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                $this->trackAdminFeature('dashboard', '/admin', 'Dashboard');
                require_once $file;
                return;
            }
        }

        $this->router->render404();
    }

    public function renderAdminPage(string $page): void
    {
        if (!Auth::instance()->isAdmin()) {
            $this->redirectUnauthorized();
            return;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $page)) {
            $this->router->render404();
            return;
        }

        // Frühere Einstiege, deren Dateien entfernt wurden (fehlende Legacy-Partials) → aktuelle Seiten
        $legacyTargets = [
            'backup' => 'backups',
            'cms-firewall' => 'firewall',
            'cookies' => 'cookie-manager',
            'system' => 'info',
            'theme-customizer' => 'theme-editor',
            'menus' => 'menu-editor',
            'rbac' => 'roles',
            'seo' => 'seo-dashboard',
            'subscriptions' => 'subscription-settings',
            'data-deletion' => 'deletion-requests',
            'data-access' => 'privacy-requests',
            'fonts-local' => 'font-manager',
        ];
        if (isset($legacyTargets[$page]) && !is_file(ABSPATH . 'admin/' . $page . '.php')) {
            $this->router->redirect('/admin/' . $legacyTargets[$page]);
            return;
        }

        $candidates = [
            ABSPATH . 'admin/' . $page . '.php',
            ABSPATH . 'admin/modules/' . $page . '/page.php',
            ABSPATH . 'admin/old/' . $page . '.php',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                $this->trackAdminFeature($page, '/admin/' . $page, $this->humanizeSlug($page));
                require_once $file;
                return;
            }
        }

        $this->router->render404();
    }

    public function renderAdminLogsSection(string $section): void
    {
        if (!Auth::instance()->isAdmin()) {
            $this->redirectUnauthorized();
            return;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $section)) {
            $this->router->render404();
            return;
        }

        $file = ABSPATH . 'admin/logs/' . $section . '.php';
        if (is_file($file)) {
            $this->trackAdminFeature('logs-' . $section, '/admin/logs/' . $section, 'Logs ' . $this->humanizeSlug($section));
            require_once $file;
            return;
        }

        $this->router->render404();
    }

    public function renderPluginPage(string $plugin, string $page): void
    {
        if (!Auth::instance()->isAdmin()) {
            $this->redirectUnauthorized();
            return;
        }

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $this->ensureAdminMenuCompatibility();

        try {
            Hooks::doAction('cms_admin_menu');
        } catch (\Throwable $e) {
            $this->logPluginAdminError($plugin, $page, $e, 'menu-registration');
        }

        $menus = function_exists('get_registered_admin_menus') ? get_registered_admin_menus() : [];
        $callback = null;
        $title = 'Plugin Page';

        foreach ($menus as $menu) {
            if (isset($menu['menu_slug']) && $menu['menu_slug'] === $plugin && empty($menu['children']) && $plugin === $page) {
                $callback = $menu['callable'] ?? null;
                $title = $menu['page_title'] ?? $title;
                break;
            }

            if (empty($menu['children'])) {
                continue;
            }

            foreach ($menu['children'] as $child) {
                if (($menu['menu_slug'] === $plugin) && ($child['menu_slug'] === $page)) {
                    $callback = $child['callable'] ?? null;
                    $title = $child['page_title'] ?? $title;
                    break 2;
                }
            }
        }

        if (!$callback || !is_callable($callback)) {
            $fallback = $this->resolvePluginAdminFallback($plugin, $page);
            if ($fallback !== null) {
                $callback = $fallback['callback'];
                $title = $fallback['title'];
            }
        }

        if (!$callback || !is_callable($callback)) {
            if ($isAjax) {
                http_response_code(404);
                echo 'Plugin-Adminseite nicht gefunden.';
                return;
            }

            $this->renderPluginAdminShell(
                'Plugin-Adminseite nicht gefunden',
                $page,
                $this->renderPluginUnavailableCard($plugin, $page)
            );
            return;
        }

        try {
            $this->featureUsage->trackFeatureUsage(
                'admin.plugin.' . $plugin . '.' . $page,
                'admin-plugin',
                '/admin/plugins/' . $plugin . '/' . $page,
                isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                [
                    'label' => $title,
                    'route_group' => $plugin,
                    'plugin_slug' => $plugin,
                ]
            );
        } catch (\Throwable $e) {
            $this->logPluginAdminError($plugin, $page, $e, 'feature-tracking');
        }

        // Plugin-Dispatcher lesen die aktive Unterseite aus $_GET['page'].
        // Bei Pfad-Routen (/admin/plugins/:plugin/:page) wird sie hier bereitgestellt.
        if (!isset($_GET['page']) || !is_string($_GET['page']) || trim($_GET['page']) === '') {
            $_GET['page'] = $page;
        }

        if ($isAjax) {
            try {
                call_user_func($callback);
            } catch (\Throwable $e) {
                $this->logPluginAdminError($plugin, $page, $e, 'ajax-callback');
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error' => 'Plugin-Adminaktion konnte nicht ausgeführt werden.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return;
        }

        try {
            $content = $this->capturePluginCallback($callback);
        } catch (\Throwable $e) {
            $this->logPluginAdminError($plugin, $page, $e, 'callback');
            $this->renderPluginAdminShell($title, $page, $this->renderPluginExceptionCard($plugin, $page, $e));
            return;
        }

        if ($this->containsCompleteAdminLayout($content)) {
            echo $this->injectPluginDesignStylesheet($this->relocateStylesheetsToHead($content));
            return;
        }

        $stylesheetHrefs = [];
        $content = $this->extractStylesheetHrefs($content, $stylesheetHrefs);
        $this->renderPluginAdminShell($title, $page, $content, $stylesheetHrefs);
    }

    /**
     * Plugin-spezifische Notfall-Zuordnung für Adminseiten, deren Menü-Hook in
     * älteren Installationen nicht zuverlässig in der Sidebar-Phase befüllt ist.
     *
     * @return array{callback: callable, title: string}|null
     */
    private function resolvePluginAdminFallback(string $plugin, string $page): ?array
    {
        if (in_array($plugin, ['knowledgebase-dashboard', 'cms-knowledgebase'], true) && class_exists(\CmsKnowledgebase\Admin\Pages::class)) {
            $pages = [
                'knowledgebase-dashboard' => ['Knowledgebase', [\CmsKnowledgebase\Admin\Pages::class, 'renderDashboard']],
                'dashboard' => ['Knowledgebase', [\CmsKnowledgebase\Admin\Pages::class, 'renderDashboard']],
                'knowledgebase-entries' => ['Knowledgebase-Einträge', [\CmsKnowledgebase\Admin\Pages::class, 'renderEntries']],
                'entries' => ['Knowledgebase-Einträge', [\CmsKnowledgebase\Admin\Pages::class, 'renderEntries']],
                'knowledgebase-categories' => ['Knowledgebase-Kategorien', [\CmsKnowledgebase\Admin\Pages::class, 'renderCategories']],
                'categories' => ['Knowledgebase-Kategorien', [\CmsKnowledgebase\Admin\Pages::class, 'renderCategories']],
                'knowledgebase-entry-editor' => ['Knowledgebase-Eintrag bearbeiten', [\CmsKnowledgebase\Admin\Pages::class, 'renderEntryEditor']],
                'entry-editor' => ['Knowledgebase-Eintrag bearbeiten', [\CmsKnowledgebase\Admin\Pages::class, 'renderEntryEditor']],
                'knowledgebase-settings' => ['Knowledgebase-Einstellungen', [\CmsKnowledgebase\Admin\Pages::class, 'renderSettings']],
                'settings' => ['Knowledgebase-Einstellungen', [\CmsKnowledgebase\Admin\Pages::class, 'renderSettings']],
            ];

            if (!isset($pages[$page]) || !is_callable($pages[$page][1])) {
                return null;
            }

            return [
                'title' => $pages[$page][0],
                'callback' => $pages[$page][1],
            ];
        }

        $m365Fallbacks = [
            'm365azure' => [
                'class' => \CMS_M365Azure_Admin_Pages::class,
                'method' => 'render_dispatch',
                'title' => 'M365 Azure',
                'pages' => ['m365azure', 'm365azure-dashboard', 'dashboard'],
            ],
            'm365landing' => [
                'class' => \CMS_M365Landing_Admin_Pages::class,
                'method' => 'render_dispatch',
                'title' => 'M365 Landing',
                'pages' => ['m365landing', 'm365landing-dashboard', 'dashboard'],
            ],
            'm365matrices-dashboard' => [
                'class' => \CMS_M365MATRICES_Admin_Pages::class,
                'method' => 'render_dashboard',
                'title' => 'M365 Matrixen',
                'pages' => ['m365matrices-dashboard', 'm365matrices', 'dashboard'],
            ],
            'm365matrices' => [
                'class' => \CMS_M365MATRICES_Admin_Pages::class,
                'method' => 'render_dashboard',
                'title' => 'M365 Matrixen',
                'pages' => ['m365matrices-dashboard', 'm365matrices', 'dashboard'],
            ],
        ];

        if (isset($m365Fallbacks[$plugin]) && in_array($page, (array) $m365Fallbacks[$plugin]['pages'], true)) {
            $callback = [$m365Fallbacks[$plugin]['class'], $m365Fallbacks[$plugin]['method']];
            if (is_callable($callback)) {
                return [
                    'title' => (string) $m365Fallbacks[$plugin]['title'],
                    'callback' => $callback,
                ];
            }
        }

        if ($plugin !== 'booking' || !class_exists('CMS_Booking_Admin_Pages')) {
            return null;
        }

        $pages = [
            'booking' => ['Buchungen', [\CMS_Booking_Admin_Pages::class, 'render_dashboard']],
            'booking-dashboard' => ['Buchungen', [\CMS_Booking_Admin_Pages::class, 'render_dashboard']],
            'bookings' => ['Buchungen verwalten', [\CMS_Booking_Admin_Pages::class, 'render_bookings']],
            'booking-bookings' => ['Buchungen verwalten', [\CMS_Booking_Admin_Pages::class, 'render_bookings']],
            'providers' => ['Booking Anbieter', [\CMS_Booking_Admin_Pages::class, 'render_providers']],
            'booking-providers' => ['Booking Anbieter', [\CMS_Booking_Admin_Pages::class, 'render_providers']],
            'services' => ['Booking Leistungen', [\CMS_Booking_Admin_Pages::class, 'render_services']],
            'booking-services' => ['Booking Leistungen', [\CMS_Booking_Admin_Pages::class, 'render_services']],
            'settings' => ['Booking Einstellungen', [\CMS_Booking_Admin_Pages::class, 'render_settings']],
            'booking-settings' => ['Booking Einstellungen', [\CMS_Booking_Admin_Pages::class, 'render_settings']],
        ];

        if (!isset($pages[$page]) || !is_callable($pages[$page][1])) {
            return null;
        }

        return [
            'title' => $pages[$page][0],
            'callback' => $pages[$page][1],
        ];
    }

    private function ensureAdminMenuCompatibility(): void
    {
        $menuFunctions = ABSPATH . 'includes/functions/admin-menu.php';
        if (is_file($menuFunctions)) {
            require_once $menuFunctions;
        }

        if (function_exists('renderAdminLayoutStart') && function_exists('renderAdminLayoutEnd')) {
            return;
        }

        $legacyMenuPartial = ABSPATH . 'admin/partials/admin-menu.php';
        if (is_file($legacyMenuPartial) && !function_exists('renderAdminLayoutStart')) {
            require_once $legacyMenuPartial;
        }
    }

    private function capturePluginCallback(callable $callback): string
    {
        $bufferLevel = ob_get_level();
        $layoutDepth = (int) ($GLOBALS['cms_admin_layout_depth'] ?? 0);

        ob_start();
        try {
            call_user_func($callback);

            $safety = 8;
            while (function_exists('cms_admin_layout_is_active') && cms_admin_layout_is_active() && $safety > 0) {
                renderAdminLayoutEnd();
                $safety--;
            }

            $content = ob_get_clean();
            return is_string($content) ? $content : '';
        } catch (\Throwable $e) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            if (function_exists('cms_admin_layout_reset')) {
                cms_admin_layout_reset($layoutDepth);
            } else {
                $GLOBALS['cms_admin_layout_depth'] = $layoutDepth;
            }

            throw $e;
        }
    }

    /**
     * Rendert Inhalte in der aktuellen Admin-Shell, wenn ein Plugin selbst kein
     * vollständiges Layout ausgegeben hat.
     *
     * @param array<int, string> $stylesheetHrefs
     */
    private function renderPluginAdminShell(string $title, string $activePage, string $content, array $stylesheetHrefs = []): void
    {
        $pageTitle = $title !== '' ? $title : 'Plugin Page';
        $activePage = $activePage !== '' ? $activePage : 'plugins';
        $stylesheets = array_values(array_unique(array_filter($stylesheetHrefs, static fn ($href): bool => is_string($href) && trim($href) !== '')));
        // Einheitliches Plugin-Admin-Design zuletzt laden, damit es gemeinsame Bausteine vereinheitlicht.
        if (function_exists('cms_asset_url') && is_file(ABSPATH . 'assets/css/admin-plugins.css')) {
            $stylesheets[] = cms_asset_url('css/admin-plugins.css');
        }
        $pageAssets = [
            'css' => $stylesheets,
        ];

        require ABSPATH . 'admin/partials/header.php';
        require ABSPATH . 'admin/partials/sidebar.php';
        if ($this->containsPageBody($content)) {
            echo $content;
        } else {
            echo '<div class="page-body"><div class="container-xl cms-plugin-admin-content">';
            echo $content;
            echo '</div></div>';
        }
        require ABSPATH . 'admin/partials/footer.php';
    }

    private function containsCompleteAdminLayout(string $content): bool
    {
        $normalized = strtolower($content);

        if (str_contains($normalized, '<html')
            && str_contains($normalized, '<body')
            && (str_contains($normalized, 'class="page"') || str_contains($normalized, "class='page'"))
            && str_contains($normalized, '</html>')) {
            return true;
        }

        return str_contains($normalized, 'admin-body')
            && (str_contains($normalized, 'navbar-vertical') || str_contains($normalized, 'cms-sidebar'))
            && str_contains($normalized, 'page-wrapper');
    }

    private function containsPageBody(string $content): bool
    {
        $normalized = strtolower($content);

        return str_contains($normalized, 'class="page-body"')
            || str_contains($normalized, "class='page-body'")
            || str_contains($normalized, 'class="page-body ')
            || str_contains($normalized, "class='page-body ");
    }

    /**
     * @param array<int, string> $stylesheetHrefs
     */
    private function extractStylesheetHrefs(string $content, array &$stylesheetHrefs): string
    {
        $stylesheetHrefs = [];
        $result = preg_replace_callback(
            '~<link\b(?=[^>]*\brel\s*=\s*["\'][^"\']*stylesheet[^"\']*["\'])[^>]*>\s*~i',
            static function (array $matches) use (&$stylesheetHrefs): string {
                if (preg_match('~\bhref\s*=\s*(["\'])(.*?)\1~i', (string) $matches[0], $hrefMatch) === 1) {
                    $href = html_entity_decode((string) $hrefMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (trim($href) !== '') {
                        $stylesheetHrefs[] = $href;
                    }
                }

                return '';
            },
            $content
        );

        $stylesheetHrefs = array_values(array_unique($stylesheetHrefs));

        return is_string($result) ? $result : $content;
    }

    private function relocateStylesheetsToHead(string $content): string
    {
        if (stripos($content, '</head>') === false) {
            return $content;
        }

        $stylesheetLinks = [];
        $withoutLinks = preg_replace_callback(
            '~<link\b(?=[^>]*\brel\s*=\s*["\'][^"\']*stylesheet[^"\']*["\'])[^>]*>\s*~i',
            static function (array $matches) use (&$stylesheetLinks): string {
                $link = trim((string) $matches[0]);
                $normalized = preg_replace('/\s+/', ' ', $link) ?? $link;
                if (!isset($stylesheetLinks[$normalized])) {
                    $stylesheetLinks[$normalized] = $link;
                }

                return '';
            },
            $content
        );

        if (!is_string($withoutLinks) || $stylesheetLinks === []) {
            return $content;
        }

        $linkHtml = "    " . implode("\n    ", array_values($stylesheetLinks)) . "\n";
        $result = preg_replace('~</head>~i', $linkHtml . '</head>', $withoutLinks, 1);

        return is_string($result) ? $result : $content;
    }

    /**
     * Ergänzt das einheitliche Plugin-Admin-Design bei Plugins, die ein
     * vollständiges Layout selbst ausgeben (nach allen Plugin-Stylesheets).
     */
    private function injectPluginDesignStylesheet(string $content): string
    {
        if (!function_exists('cms_asset_url') || !is_file(ABSPATH . 'assets/css/admin-plugins.css')) {
            return $content;
        }

        if (stripos($content, 'css/admin-plugins.css') !== false || stripos($content, '</head>') === false) {
            return $content;
        }

        $link = '    <link rel="stylesheet" href="' . htmlspecialchars(cms_asset_url('css/admin-plugins.css'), ENT_QUOTES, 'UTF-8') . '">' . "\n";
        $result = preg_replace('~</head>~i', $link . '</head>', $content, 1);

        return is_string($result) ? $result : $content;
    }

    private function renderPluginUnavailableCard(string $plugin, string $page): string
    {
        return '<div class="page-body"><div class="container-xl">'
            . '<div class="alert alert-warning" role="alert">'
            . '<h2 class="alert-title">Plugin-Adminseite nicht gefunden</h2>'
            . '<div>Für <strong>' . htmlspecialchars($plugin, ENT_QUOTES, 'UTF-8') . '</strong> wurde keine passende Admin-Callback-Seite für <strong>' . htmlspecialchars($page, ENT_QUOTES, 'UTF-8') . '</strong> registriert.</div>'
            . '</div></div></div>';
    }

    private function renderPluginExceptionCard(string $plugin, string $page, \Throwable $e): string
    {
        $debugEnabled = defined('CMS_DEBUG') && CMS_DEBUG;
        $details = $debugEnabled
            ? '<details class="mt-3"><summary>Technische Details</summary><pre class="mt-2 mb-0">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre></details>'
            : '';

        return '<div class="page-body"><div class="container-xl">'
            . '<div class="alert alert-danger" role="alert">'
            . '<h2 class="alert-title">Plugin-Adminseite konnte nicht geladen werden</h2>'
            . '<div>Die Seite <strong>' . htmlspecialchars($plugin . ' / ' . $page, ENT_QUOTES, 'UTF-8') . '</strong> hat einen Fehler ausgelöst. Der Fehler wurde protokolliert; die Admin-Shell bleibt weiterhin nutzbar.</div>'
            . $details
            . '</div></div></div>';
    }

    private function logPluginAdminError(string $plugin, string $page, \Throwable $e, string $context): void
    {
        error_log(sprintf(
            '365CMS plugin admin error [%s:%s/%s]: %s in %s:%d',
            $context,
            $plugin,
            $page,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    private function trackAdminFeature(string $page, string $routePath, string $label): void
    {
        $this->featureUsage->trackFeatureUsage(
            'admin.' . $page,
            'admin',
            $routePath,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            [
                'label' => $label,
                'route_group' => $page,
            ]
        );
    }

    private function humanizeSlug(string $slug): string
    {
        $label = str_replace(['-', '_'], ' ', $slug);
        $label = preg_replace('/\s+/', ' ', $label) ?? $slug;

        return ucwords(trim($label));
    }

    private function redirectUnauthorized(): void
    {
        if (!Auth::instance()->isLoggedIn()) {
            $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/admin');
            $requestUri = $requestUri !== '' ? $requestUri : '/admin';
            $loginPath = CmsAuthPageService::getInstance()->getPublicPath('login', $this->router->getRequestLocale());
            $this->router->redirect($loginPath . '?redirect=' . urlencode($requestUri) . '&login_error=session_required');
            return;
        }

        $this->router->redirect('/member');
    }
}
