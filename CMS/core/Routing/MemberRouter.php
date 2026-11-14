<?php
/**
 * Member Router Module
 *
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS\Routing;

use CMS\Auth;
use CMS\Logger;
use CMS\Router;
use CMS\Services\CmsAuthPageService;
use CMS\Services\FeatureUsageService;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberRouter
{
    /** Vorlagen, die nur eingebunden werden (PluginDashboardRegistry) und ohne deren Variablen nicht direkt aufrufbar sind. */
    private const INTERNAL_MEMBER_TEMPLATES = ['plugin-section'];

    private FeatureUsageService $featureUsage;

    public function __construct(private readonly Router $router)
    {
        $this->featureUsage = FeatureUsageService::getInstance();
    }

    public function registerRoutes(): void
    {
        $this->router->addRoute('GET', '/dashboard', [$this, 'renderDashboard']);
        $this->router->addRoute('POST', '/dashboard', [$this, 'renderDashboard']);
        $this->router->addRoute('GET', '/member', [$this, 'renderMember']);
        $this->router->addRoute('POST', '/member', [$this, 'renderMember']);
        $this->router->addRoute('GET', '/member/plugin/:slug/:action/:id', [$this, 'renderMemberPluginSection']);
        $this->router->addRoute('POST', '/member/plugin/:slug/:action/:id', [$this, 'renderMemberPluginSection']);
        $this->router->addRoute('GET', '/member/plugin/:slug/:action', [$this, 'renderMemberPluginSection']);
        $this->router->addRoute('POST', '/member/plugin/:slug/:action', [$this, 'renderMemberPluginSection']);
        $this->router->addRoute('GET', '/member/plugin/:slug', [$this, 'renderMemberPluginSection']);
        $this->router->addRoute('POST', '/member/plugin/:slug', [$this, 'renderMemberPluginSection']);
        $this->router->addRoute('GET', '/member/:page', [$this, 'renderMemberPage']);
        $this->router->addRoute('POST', '/member/:page', [$this, 'renderMemberPage']);
    }

    public function renderDashboard(): void
    {
        if (!Auth::instance()->isLoggedIn()) {
            $this->redirectToLogin();
            return;
        }

        $themeFile = ThemeManager::instance()->getThemePath() . 'member/dashboard.php';
        if (file_exists($themeFile)) {
            $this->trackMemberFeature('dashboard', '/member/dashboard', 'Dashboard');
            require $themeFile;
            return;
        }

        $this->router->redirect('/member/dashboard');
    }

    public function renderMember(): void
    {
        if (!Auth::instance()->isLoggedIn()) {
            $this->redirectToLogin();
            return;
        }

        $this->router->redirect('/member/dashboard');
    }

    public function renderMemberPage(string $page): void
    {
        if (!Auth::instance()->isLoggedIn()) {
            $this->redirectToLogin();
            return;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $page)
            || in_array(strtolower($page), self::INTERNAL_MEMBER_TEMPLATES, true)) {
            $this->router->render404();
            return;
        }

        $themeFile = ThemeManager::instance()->getThemePath() . 'member/' . $page . '.php';
        if (file_exists($themeFile)) {
            $this->trackMemberFeature($page, '/member/' . $page, $this->humanizeSlug($page));
            require $themeFile;
            return;
        }

        $file = ABSPATH . 'member/' . $page . '.php';
        if (file_exists($file)) {
            $this->trackMemberFeature($page, '/member/' . $page, $this->humanizeSlug($page));
            require $file;
            return;
        }

        Logger::instance()->withChannel('member-router')->warning('Member page file was not found.', [
            'page' => $page,
            'file' => $file,
        ]);
        $this->router->render404();
    }

    public function renderMemberPluginSection(string $slug, string $action = '', string $id = ''): void
    {
        if (!Auth::instance()->isLoggedIn()) {
            $this->redirectToLogin();
            return;
        }

        // Route-Parameter direkt übergeben statt in $_GET zu schreiben (kein Core-Bereich liest $_GET['action'|'id']).
        $params = \CMS\Member\PluginDashboardRegistry::normalizeRouteParams(['action' => $action, 'id' => $id]);
        $action = $params['action'] ?? '';

        $registry = \CMS\Member\PluginDashboardRegistry::instance();
        $registry->init();
        $this->featureUsage->trackFeatureUsage(
            'member.plugin.' . $slug . ($action !== '' ? '.' . $action : ''),
            'member-plugin',
            '/member/plugin/' . $slug . ($action !== '' ? '/' . $action : ''),
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            [
                'label' => $this->humanizeSlug($slug) . ($action !== '' ? ' – ' . $this->humanizeSlug($action) : ''),
                'route_group' => $slug,
                'plugin_slug' => $slug,
            ]
        );
        $registry->handleRoute($slug, $params);
    }

    private function trackMemberFeature(string $page, string $routePath, string $label): void
    {
        $this->featureUsage->trackFeatureUsage(
            'member.' . $page,
            'member',
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

    private function redirectToLogin(): void
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/member');
        $path = CmsAuthPageService::getInstance()->getPublicPath('login', $this->router->getRequestLocale())
            . '?redirect=' . urlencode($requestUri);
        $this->router->redirect($path);
    }
}
