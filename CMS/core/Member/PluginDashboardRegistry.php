<?php
/**
 * Plugin Dashboard Registry
 *
 * Zentrale Registrierung für Plugin-Bereiche im Member-Dashboard.
 * Plugins nutzen `member_dashboard_init`-Action um sich hier einzutragen.
 *
 * Verwendung in einem Plugin:
 * ─────────────────────────────────────────────────────────────────────────────
 * \CMS\Hooks::addAction('member_dashboard_init', function(\CMS\Member\PluginDashboardRegistry $r) {
 *     $r->register([
 *         'plugin'    => 'cms-jobads',
 *         'slug'      => 'jobads',
 *         'label'     => 'Stellenanzeigen',
 *         'icon'      => '💼',
 *         'category'  => 'plugins',
 *         'priority'  => 20,
 *         'capability'=> null,       // null = alle eingeloggten Member
 *         'dashboard_widget' => [
 *             'title'          => 'Meine Stellen',
 *             'description'    => 'Verwalte deine Stellenanzeigen.',
 *             'color'          => '#f59e0b',
 *             'stats_callback' => [MyClass::class, 'getDashboardStats'],
 *             // stats_callback muss array ['count'=>int, 'label'=>string] zurückgeben
 *         ],
 *         'render_callback' => function(object $user, array $params) {
 *             include JOBADS_DIR . 'member/views/my-jobs-view.php';
 *         },
 *         'post_callback'   => function(object $user, array $params): void {
 *             // Optional: verarbeitet POST-Requests für diesen Bereich
 *         },
 *     ]);
 * });
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @package CMSv2\Core\Member
 */

declare(strict_types=1);

namespace CMS\Member;

use CMS\Auth;
use CMS\Hooks;
use CMS\Router;
use CMS\Security;
use CMS\Services\CmsAuthPageService;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

class PluginDashboardRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array> Registered plugin sections keyed by slug */
    private array $sections = [];

    /** @var bool Ob init bereits gefeuert wurde */
    private bool $initialized = false;

    /**
     * Cache für Admin-Sichtbarkeits-Einstellungen aus der DB.
     * null = noch nicht geladen; array = geladen (key=plugin-slug, val=bool)
     *
     * @var array<string,bool>|null
     */
    private ?array $adminVisibility = null;

    /** @var array<string,array<string,string>>|null */
    private ?array $widgetMetaOverrides = null;

    // ── Singleton ────────────────────────────────────────────────────────────

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ── Init ─────────────────────────────────────────────────────────────────

    /**
     * Feuert die `member_dashboard_init`-Action (einmalig).
     * Wird vom MemberController im Konstruktor aufgerufen.
     */
    public function init(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        // Plugins können sich jetzt registrieren
        Hooks::doAction('member_dashboard_init', $this);

        // Menü-Items automatisch aus der Registry einspeisen
        Hooks::addFilter('member_menu_items', [$this, 'filterMenuItems'], 20);
    }

    // ── Registration API ─────────────────────────────────────────────────────

    /**
     * Filter-Callback für `member_menu_items`.
     * Fügt Menüpunkte aller registrierten Plugin-Bereiche ein.
     *
     * @param array $menuItems Vorhandene Menüeinträge
     * @return array Ergänzte Menüeinträge
     */
    public function filterMenuItems(array $menuItems): array
    {
        // Aktuellen Slug aus URI ermitteln
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $currentSlug = '';
        if (preg_match('#/member/plugin/([a-zA-Z0-9_-]+)#', $uri, $m)) {
            $currentSlug = $m[1];
        }

        foreach ($this->getMenuItems($currentSlug) as $item) {
            // Doppeleinträge vermeiden
            $exists = false;
            foreach ($menuItems as $existing) {
                if ($existing['slug'] === $item['slug']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $menuItems[] = $item;
            }
        }

        return $menuItems;
    }

    /**
     * Registriert einen Plugin-Bereich im Member-Dashboard.     *
     * Pflichtfelder: slug, label, render_callback
     *
     * @param array{
     *   plugin:             string,
     *   slug:               string,
     *   label:              string,
     *   icon?:              string,
     *   category?:          string,
     *   priority?:          int,
     *   capability?:        string|null,
    *   dashboard_widget?:  array|bool|null,
     *   render_callback:    callable,
     *   post_callback?:     callable|null,
     *   csrf?:              string,
     * } $config
     *
     * `csrf`: 'core' erzwingt für POST-Requests das Core-Token aus csrfField()/csrfToken();
     * Standard 'plugin' = Plugin prüft selbst (zusätzlich gilt immer die Same-Origin-Prüfung).
     */
    public function register(array $config): void
    {
        $slug = trim($config['slug'] ?? '');
        if ($slug === '' || !isset($config['render_callback'])) {
            trigger_error(
                '[PluginDashboardRegistry] register() benötigt mindestens "slug" und "render_callback".',
                E_USER_WARNING
            );
            return;
        }

        $this->sections[$slug] = [
            'plugin'           => $config['plugin'] ?? $slug,
            'slug'             => $slug,
            'label'            => $config['label'] ?? $slug,
            'icon'             => $config['icon'] ?? '🔌',
            'category'         => $config['category'] ?? 'plugins',
            'priority'         => (int) ($config['priority'] ?? 50),
            'capability'       => $config['capability'] ?? null,
            'parent_slug'      => $config['parent_slug'] ?? null,
            'dashboard_widget' => $config['dashboard_widget'] ?? null,
            'render_callback'  => $config['render_callback'],
            'post_callback'    => $config['post_callback'] ?? null,
            'csrf'             => ($config['csrf'] ?? 'plugin') === 'core' ? 'core' : 'plugin',
        ];
    }

    // ── Query API ─────────────────────────────────────────────────────────────

    /**
     * Liefert einen einzelnen registrierten Bereich oder null.
     */
    public function getSection(string $slug): ?array
    {
        return $this->sections[$slug] ?? null;
    }

    /**
     * Liefert alle registrierten Bereiche, sortiert nach priority.
     *
     * Filtert nach Berechtigung des aktuellen Benutzers UND nach Admin-
     * Sichtbarkeits-Einstellungen (member_dashboard_plugin_{slug}).
     *
     * @return array[]
     */
    public function getAll(): array
    {
        $sections = array_filter(
            $this->sections,
            fn(array $s) => $this->checkCapability($s['capability'])
                         && $this->isVisibleByAdmin($s)
        );

        uasort($sections, fn(array $a, array $b) => $a['priority'] <=> $b['priority']);

        return array_values($sections);
    }

    /**
     * Lädt alle member_dashboard_plugin_* Einstellungen einmalig aus der DB.
     */
    private function loadAdminVisibility(): void
    {
        if ($this->adminVisibility !== null) {
            return;
        }
        $this->adminVisibility = [];
        try {
            $db   = \CMS\Database::instance();
            $stmt = $db->prepare(
                "SELECT option_name, option_value FROM {$db->getPrefix()}settings
                  WHERE option_name LIKE 'member_dashboard_plugin_%'"
            );
            if ($stmt) {
                $stmt->execute();
                foreach ($stmt->fetchAll(\PDO::FETCH_OBJ) as $row) {
                    $pluginSlug = substr($row->option_name, strlen('member_dashboard_plugin_'));
                    $this->adminVisibility[$pluginSlug] = ($row->option_value === '1');
                }
            }
        } catch (\Throwable $e) {
            // Bei DB-Fehler alle Bereiche anzeigen (Fallback = sichtbar)
        }
    }

    private function loadWidgetMetaOverrides(): void
    {
        if ($this->widgetMetaOverrides !== null) {
            return;
        }

        $this->widgetMetaOverrides = [];

        try {
            $db = \CMS\Database::instance();
            $stmt = $db->prepare(
                "SELECT option_name, option_value FROM {$db->getPrefix()}settings
                 WHERE option_name LIKE 'member_dashboard_widget_meta_%'"
            );

            if ($stmt) {
                $stmt->execute();
                foreach ($stmt->fetchAll(\PDO::FETCH_OBJ) as $row) {
                    $pluginSlug = substr((string) $row->option_name, strlen('member_dashboard_widget_meta_'));
                    $decoded = \CMS\Json::decodeArray((string) ($row->option_value ?? ''), []);
                    $this->widgetMetaOverrides[$pluginSlug] = is_array($decoded) ? $decoded : [];
                }
            }
        } catch (\Throwable $e) {
            $this->widgetMetaOverrides = [];
        }
    }

    /**
     * @return array<string,string>
     */
    private function getWidgetMetaOverride(string $pluginSlug): array
    {
        $this->loadWidgetMetaOverrides();
        return $this->widgetMetaOverrides[$pluginSlug] ?? [];
    }

    /**
     * Prüft ob ein Plugin-Bereich laut Admin-Einstellungen sichtbar ist.
     * Kein Eintrag in der DB = noch nie gesetzt = standardmäßig sichtbar.
     */
    private function isVisibleByAdmin(array $section): bool
    {
        $this->loadAdminVisibility();
        $pluginSlug = $section['plugin'] ?? $section['slug'];
        // null (kein Eintrag) = noch nie deaktiviert → sichtbar
        return $this->adminVisibility[$pluginSlug] ?? true;
    }

    /**
     * Gibt Menüeinträge zurück (bereit für getMemberMenuItems-Filter).
     */
    public function getMenuItems(string $currentSlug = ''): array
    {
        $items = [];
        foreach ($this->getAll() as $section) {
            $items[] = [
                'slug'        => 'plugin_' . $section['slug'],
                'label'       => $this->sanitizeWidgetText((string) ($section['label'] ?? $section['slug'] ?? 'Plugin'), 120, 'Plugin'),
                'icon'        => $this->sanitizeWidgetText((string) ($section['icon'] ?? '🔌'), 16, '🔌'),
                'url'         => '/member/plugin/' . $section['slug'],
                'active'      => $currentSlug === $section['slug'],
                'category'    => $section['category'],
                'parent_slug' => $section['parent_slug'] ?? null,
            ];
        }
        return $items;
    }

    /**
    * Gibt Dashboard-Widgets für alle sichtbaren Plugin-Bereiche zurück.
    * Ist `dashboard_widget` null, wird dennoch ein Standard-Widget erzeugt.
    * Mit `dashboard_widget === false` kann ein Bereich explizit von den
    * Kacheln im generischen 365CMS-Member-Dashboard ausgeschlossen werden.
     */
    public function getDashboardWidgets(object $user): array
    {
        $widgets = [];
        $isAdmin = Auth::instance()->isAdmin();

        foreach ($this->getAll() as $section) {
            // Sub-Items (mit parent_slug) bekommen keine eigene Dashboard-Kachel
            if (!empty($section['parent_slug'])) {
                continue;
            }
            if (($section['dashboard_widget'] ?? null) === false) {
                continue;
            }
            $wConfig = $section['dashboard_widget'] ?? [];
            $metaOverride = $this->getWidgetMetaOverride((string) ($section['plugin'] ?? $section['slug'] ?? ''));
            $pluginSlug = (string) ($section['plugin'] ?? $section['slug'] ?? '');
            $sectionSlug = (string) ($section['slug'] ?? '');

            // Stats via Callback ermitteln
            $stats = null;
            if (!empty($wConfig['stats_callback']) && is_callable($wConfig['stats_callback'])) {
                try {
                    $stats = call_user_func($wConfig['stats_callback'], $user);
                } catch (\Throwable $e) {
                    // Stats-Fehler still ignorieren
                }
            }

            $stats = is_array($stats) ? [
                'count' => (int) ($stats['count'] ?? 0),
                'label' => $this->sanitizeWidgetText((string) ($stats['label'] ?? 'Einträge'), 80, 'Einträge'),
            ] : null;

            $widgets[] = [
                'plugin'      => $this->sanitizeWidgetText($pluginSlug, 120, $sectionSlug !== '' ? $sectionSlug : 'plugin'),
                'slug'        => $this->sanitizeWidgetText($sectionSlug, 120, 'plugin'),
                'icon'        => $this->sanitizeWidgetText((string) ($metaOverride['icon'] ?? $wConfig['icon'] ?? $section['icon'] ?? '🔌'), 16, '🔌'),
                'title'       => $this->sanitizeWidgetText((string) ($metaOverride['title'] ?? $wConfig['title'] ?? $section['label'] ?? 'Plugin'), 160, 'Plugin'),
                'description' => $this->sanitizeWidgetText((string) ($metaOverride['description'] ?? $wConfig['description'] ?? ''), 255),
                'color'       => $this->sanitizeWidgetColor((string) ($metaOverride['color'] ?? $wConfig['color'] ?? '#4f46e5')),
                'link'        => '/member/plugin/' . $sectionSlug,
                'link_label'  => $this->sanitizeWidgetText((string) ($wConfig['link_label'] ?? 'Öffnen'), 80, 'Öffnen'),
                'admin_link'  => $isAdmin && !empty($wConfig['admin_url']) ? $this->sanitizeWidgetLink((string) $wConfig['admin_url']) : null,
                'admin_label' => $this->sanitizeWidgetText((string) ($wConfig['admin_label'] ?? '⚙️ Verwalten'), 80, '⚙️ Verwalten'),
                'stats'       => $stats,  // null oder ['count'=>int, 'label'=>string]
                'badge'       => isset($wConfig['badge']) ? $this->sanitizeWidgetText((string) $wConfig['badge'], 40) : null,   // z.B. 'Neu' oder '3'
            ];
        }

        return $widgets;
    }

    // ── Dispatcher ────────────────────────────────────────────────────────────

    /**
     * Wird vom Router für /member/plugin/:slug (GET + POST) aufgerufen.
     *
     * @param string $slug
     * @param array  $params Zusätzliche Route-Parameter
     */
    public function handleRoute(string $slug, array $params = []): void
    {
        // Auth-Check (Member muss eingeloggt sein)
        if (!Auth::instance()->isLoggedIn()) {
            $loginPath = CmsAuthPageService::getInstance()->getPublicPath('login', Router::instance()->getRequestLocale());
            $redirect = (string) ($_SERVER['REQUEST_URI'] ?? '/member');
            header('Location: ' . (defined('SITE_URL') ? SITE_URL : '') . $loginPath . '?redirect=' . rawurlencode($redirect));
            exit;
        }

        // Init sicherstellen (falls direkt über Route aufgerufen)
        $this->init();

        $section = $this->getSection($slug);

        if ($section === null) {
            http_response_code(404);
            require_once ABSPATH . 'member/partials/plugin-not-found.php';
            return;
        }

        // Berechtigungsprüfung
        if (!$this->checkCapability($section['capability'])) {
            http_response_code(404);
            require_once ABSPATH . 'member/partials/plugin-not-found.php';
            return;
        }

        if (!$this->verifyPostRequest($section)) {
            http_response_code(403);
            echo '<div class="alert alert-danger">Sicherheitsüberprüfung fehlgeschlagen. Bitte lade die Seite neu und versuche es erneut.</div>';
            return;
        }

        $user = Auth::instance()->getCurrentUser();

        // Theme-Override bevorzugen, damit Plugin-Bereiche im Member-Design
        // des aktiven Themes statt im generischen 365CMS-Wrapper erscheinen.
        $themeFile = ThemeManager::instance()->getThemePath() . 'member/plugin-section.php';
        if (file_exists($themeFile)) {
            require $themeFile;
            return;
        }

        // Fallback auf den generischen Core-Wrapper.
        require_once ABSPATH . 'member/plugin-section.php';
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

    /**
     * CSRF-Token für Formulare eines Plugin-Bereichs (Feldname `csrf_token`).
     */
    public function csrfToken(string $slug): string
    {
        return Security::instance()->generateToken('member_plugin_' . $slug);
    }

    /**
     * Fertiges Hidden-Field für Plugin-Formulare.
     */
    public function csrfField(string $slug): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($this->csrfToken($slug), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Zentrale Prüfung für POST-Requests auf Plugin-Bereiche:
     * - immer: Origin/Referer müssen (falls gesendet) zur eigenen Site gehören,
     * - bei `csrf => 'core'`: gültiges Core-Token `member_plugin_<slug>` Pflicht.
     *
     * @param array<string,mixed> $section
     */
    private function verifyPostRequest(array $section): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return true;
        }

        $siteHost = strtolower((string) parse_url(defined('SITE_URL') ? (string) SITE_URL : '', PHP_URL_HOST));
        $requestHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
            $value = trim((string) ($_SERVER[$header] ?? ''));
            if ($value === '' || $value === 'null') {
                continue;
            }
            $sourceHost = strtolower((string) parse_url($value, PHP_URL_HOST));
            if ($sourceHost === '' || ($sourceHost !== $siteHost && $sourceHost !== $requestHost)) {
                return false;
            }
            break;
        }

        if (($section['csrf'] ?? 'plugin') === 'core') {
            $token = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
            return Security::instance()->verifyToken($token, 'member_plugin_' . (string) $section['slug']);
        }

        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Prüft ob der aktuelle Benutzer die Berechtigung hat.
     */
    private function checkCapability(?string $capability): bool
    {
        $capability = trim((string) $capability);
        if ($capability === '' || strtolower($capability) === 'member') {
            return true;
        }
        $auth = Auth::instance();
        if ($capability === 'admin') {
            return $auth->isAdmin();
        }

        // Rollenname oder Capability – unbekannte Werte werden nicht mehr pauschal freigegeben.
        return Auth::hasRole($capability) || $auth->hasCapability($capability);
    }

    private function sanitizeWidgetColor(string $value, string $fallback = '#4f46e5'): string
    {
        $color = trim($value);
        return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? $color : $fallback;
    }

    private function sanitizeWidgetLink(string $value): ?string
    {
        $href = trim($value);
        if ($href === '') {
            return null;
        }

        if (str_starts_with($href, '/')) {
            return str_starts_with($href, '//') ? null : $href;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            return filter_var($href, FILTER_VALIDATE_URL) ? $href : null;
        }

        return null;
    }

    private function sanitizeWidgetText(string $value, int $maxLength = 160, string $fallback = ''): string
    {
        $text = trim(strip_tags($value));
        if ($text === '') {
            return $fallback;
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength) : substr($text, 0, $maxLength);
    }
}
