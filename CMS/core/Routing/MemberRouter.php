<?php
/**
 * Member Router Module
 *
 * Routen `/dashboard`, `/member`, `/member/:page` und `/member/plugin/:slug[/:action[/:id]]`.
 *
 * @package CMS\Routing
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

        $this->router->redirect($this->resolveMemberHomePath());
    }

    public function renderMember(): void
    {
        if (!Auth::instance()->isLoggedIn()) {
            $this->redirectToLogin();
            return;
        }

        // Direkt auf die Startseite: bei abgeschaltetem Dashboard ohne Umweg über /member/dashboard (MD-03).
        $this->router->redirect($this->resolveMemberHomePath());
    }

    /**
     * Startseite des Mitgliederbereichs laut Einstellungen (`/member/dashboard` oder `/member/profile`).
     */
    private function resolveMemberHomePath(): string
    {
        try {
            $controllerFile = ABSPATH . 'member/includes/class-member-controller.php';
            if (!class_exists(\CMS\MemberArea\MemberController::class, false) && is_file($controllerFile)) {
                require_once $controllerFile;
            }

            if (class_exists(\CMS\MemberArea\MemberController::class, false)) {
                return \CMS\MemberArea\MemberController::instance()->getHomePath();
            }
        } catch (\Throwable) {
        }

        return '/member/dashboard';
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

        if ($action !== '') {
            $_GET['action'] = sanitize_key($action);
        }
        if ($id !== '' && ctype_digit($id)) {
            $_GET['id'] = $id;
        }

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
        $registry->handleRoute($slug);
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
