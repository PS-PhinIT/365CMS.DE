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
 *             // Optional statt der Zählerkachel eigenes Markup (bereits escaped):
 *             // 'render_callback' => fn(object $user, array $context): string => '…',
 *             // 'anchor'          => 'jobads',   // id des Widgets für Rücksprünge (#jobads)
 *         ],
 *         'render_callback' => function(object $user, array $params) {
 *             include JOBADS_DIR . 'member/views/my-jobs-view.php';
 *         },
 *         'post_callback'   => function(object $user, array $params): ?array {
 *             // Optional: verarbeitet POST-Requests VOR jeder Ausgabe (PRG-Muster).
 *             // Rückgabe ['type' => 'success', 'message' => '…', 'redirect' => '/member/plugin/jobads#liste']
 *             // → Flash-Meldung + 303-Weiterleitung. Ohne Rückgabe und ohne Ausgabe: 303 zurück auf den Bereich.
 *             return ['type' => 'success', 'message' => 'Gespeichert.'];
 *         },
 *         // 'csrf' => 'core' (Standard): Core-Token aus csrfField() ist Pflicht; 'plugin' nur ausdrücklich.
 *     ]);
 * });
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @package CMS\Member
 */

declare(strict_types=1);

namespace CMS\Member;

use CMS\Auth;
use CMS\Hooks;
use CMS\Logger;
use CMS\Router;
use CMS\Security;
use CMS\Services\CmsAuthPageService;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

class PluginDashboardRegistry
{
    /** Erlaubte Typen für Flash-Meldungen aus Plugin-Bereichen (Bootstrap-/Tabler-Alert-Klassen). */
    public const FLASH_TYPES = ['success', 'danger', 'warning', 'info'];

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

    /**
     * Ein CSRF-Token je Bereich und Request: alle Formulare einer Seite (Widget + Unterseite) teilen es,
     * damit der 20er-Puffer von Security nicht durch viele Formulare verdrängt wird.
     *
     * @var array<string,string>
     */
    private array $csrfTokens = [];

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
     * Registriert einen Plugin-Bereich im Member-Dashboard.
     *
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
     *   dashboard_widget?:  array{title?: string, description?: string, color?: string, icon?: string,
     *                             stats_callback?: callable, render_callback?: callable, anchor?: string,
     *                             link_label?: string, admin_url?: string, admin_label?: string, badge?: string}|bool|null,
     *   render_callback:    callable,
     *   post_callback?:     callable|null,
     *   csrf?:              string,
     * } $config
     *
     * `csrf`: 'core' (Standard seit 4.0.0) erzwingt für POST-Requests das Core-Token aus csrfField()/csrfToken().
     * 'plugin' = Plugin prüft selbst (zusätzlich gilt immer die Same-Origin-Prüfung); nur noch ausdrücklich
     * und mit Hinweis im Debug-Log.
     *
     * `dashboard_widget.render_callback`: `fn(object $user, array $context): string` liefert fertig escaptes Markup
     * statt der Zählerkachel; `$context` = slug, plugin, action_url, csrf_field, return_to, anchor.
     * Wirft der Callback oder liefert er '', erscheint die normale Kachel.
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

        $csrf = ($config['csrf'] ?? 'core') === 'plugin' ? 'plugin' : 'core';
        if ($csrf === 'plugin') {
            $this->debugLog('Plugin-Bereich prüft POST-Anfragen selbst (csrf => plugin); empfohlen ist csrf => core.', ['slug' => $slug]);
        }

        $widget = $config['dashboard_widget'] ?? null;
        if (is_array($widget)) {
            if (isset($widget['render_callback']) && !is_callable($widget['render_callback'])) {
                unset($widget['render_callback']);
            }
            if (isset($widget['anchor'])) {
                $widget['anchor'] = self::sanitizeAnchor((string) $widget['anchor']);
            }
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
            'dashboard_widget' => $widget,
            'render_callback'  => $config['render_callback'],
            'post_callback'    => $config['post_callback'] ?? null,
            'csrf'             => $csrf,
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
     *
     * Widgets mit `render_callback` erhalten zusätzlich `html` (fertiges, escaptes Markup) und `anchor`;
     * scheitert der Callback oder liefert er '', bleibt es bei der Kachel (ohne `html`).
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
            $wConfig = is_array($section['dashboard_widget'] ?? null) ? $section['dashboard_widget'] : [];
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

            $widget = [
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

            if (isset($wConfig['render_callback']) && is_callable($wConfig['render_callback'])) {
                $anchor = self::sanitizeAnchor((string) ($wConfig['anchor'] ?? ''), self::sanitizeAnchor($sectionSlug, 'plugin-widget'));
                $widget['anchor'] = $anchor;
                $html = $this->renderWidgetCallback($wConfig['render_callback'], $user, [
                    'slug'       => $sectionSlug,
                    'plugin'     => $pluginSlug,
                    'action_url' => '/member/plugin/' . $sectionSlug,
                    'csrf_field' => $this->csrfField($sectionSlug),
                    'return_to'  => 'dashboard',
                    'anchor'     => $anchor,
                ]);
                if ($html !== '') {
                    $widget['html'] = $html;
                }
            }

            $widgets[] = $widget;
        }

        return $widgets;
    }

    /**
     * Führt den Widget-Callback aus. Ausgaben per echo werden verworfen (Vertrag: Rückgabe als String),
     * Fehler führen zur normalen Kachel.
     *
     * @param array<string,mixed> $context
     */
    private function renderWidgetCallback(callable $callback, object $user, array $context): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            $html = call_user_func($callback, $user, $context);
        } catch (\Throwable $e) {
            $html = '';
            $this->debugLog('Widget-Callback fehlgeschlagen, normale Kachel wird angezeigt.', [
                'slug' => (string) ($context['slug'] ?? ''),
                'error' => $e->getMessage(),
            ]);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return is_string($html) ? trim($html) : '';
    }

    // ── Dispatcher ────────────────────────────────────────────────────────────

    /**
     * Wird vom Router für /member/plugin/:slug[/:action[/:id]] (GET + POST) aufgerufen.
     *
     * Ablauf: Anmeldung → Bereich/Berechtigung (404) → POST-Prüfung (403) → bei POST `post_callback`
     * vor jeder Ausgabe mit Flash-Meldung und 303-Weiterleitung (PRG) → Darstellung im Member-Layout.
     *
     * @param string $slug
     * @param array  $params Route-Parameter (`action`, `id`) – werden nicht mehr in `$_GET` geschrieben.
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

        // Unbekannter Bereich oder fehlende Berechtigung: 404 (Existenz nicht verraten)
        if ($section === null || !$this->checkCapability($section['capability'])) {
            $this->renderError(404);
            return;
        }

        if (!$this->verifyPostRequest($section)) {
            $this->renderError(403);
            return;
        }

        $user = Auth::instance()->getCurrentUser();
        $params = self::normalizeRouteParams($params);

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && is_callable($section['post_callback'] ?? null)) {
            $outcome = $this->dispatchPost($section, $user, $params);
            if ($outcome['redirect'] !== null) {
                if ($outcome['flash'] !== null) {
                    $this->flash($outcome['flash']['type'], $outcome['flash']['message'], $outcome['flash']['payload']);
                }
                $this->redirectSeeOther($outcome['redirect']);
                return;
            }

            // Altes Muster: post_callback hat selbst Markup ausgegeben. Einmalig im Wrapper anzeigen und
            // verhindern, dass ein (Theme-)Wrapper den Callback ein zweites Mal aufruft.
            $legacyOutput = $outcome['output'];
            $section['post_callback'] = null;
            $section['render_callback'] = static function () use ($legacyOutput): void {
                echo $legacyOutput;
            };
        }

        $this->renderSection($section, $user, $params);
    }

    /**
     * Ruft `post_callback` gepuffert auf und bestimmt das Ergebnis (siehe resolvePostOutcome()).
     *
     * @param array<string,mixed> $section
     * @param array<string,string> $params
     * @return array{redirect: ?string, flash: ?array{type: string, message: string, payload: array<string,mixed>}, output: string}
     */
    private function dispatchPost(array $section, object $user, array $params): array
    {
        $level = ob_get_level();
        ob_start();
        $result = null;
        try {
            $result = call_user_func($section['post_callback'], $user, $params);
        } catch (\Throwable $e) {
            $this->logger()->warning('Plugin-Bereich: Verarbeitung des Formulars fehlgeschlagen.', [
                'slug' => (string) ($section['slug'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            $result = ['type' => 'danger', 'message' => 'Die Aktion konnte nicht ausgeführt werden. Bitte versuche es erneut.'];
        }
        $output = '';
        while (ob_get_level() > $level) {
            $output = (string) ob_get_clean() . $output;
        }

        $outcome = self::resolvePostOutcome($result, $output, '/member/plugin/' . (string) ($section['slug'] ?? ''));
        if ($outcome['redirect'] === null) {
            $this->debugLog('post_callback gibt Markup aus statt Meldung/Weiterleitung zurückzugeben (kein PRG).', [
                'slug' => (string) ($section['slug'] ?? ''),
            ]);
        }

        return $outcome;
    }

    /**
     * Wertet die Rückgabe eines `post_callback` aus (reine Funktion, ohne Seiteneffekte).
     *
     * - Array mit `message`/`type`/`redirect`/`payload` → Flash (falls Meldung) + Weiterleitung
     *   (`redirect` als lokaler Pfad, sonst der Bereich selbst),
     * - String mit lokalem Pfad → Weiterleitung ohne Meldung,
     * - keine Rückgabe und keine Ausgabe → Weiterleitung auf den Bereich (PRG),
     * - keine Rückgabe, aber Ausgabe → altes Muster: Ausgabe wird im Wrapper gezeigt (`redirect` = null).
     *
     * @internal Öffentlich für Tests.
     * @return array{redirect: ?string, flash: ?array{type: string, message: string, payload: array<string,mixed>}, output: string}
     */
    public static function resolvePostOutcome(mixed $result, string $output, string $sectionPath): array
    {
        $fallback = self::safeRedirectTarget($sectionPath, '/member/dashboard');

        if (is_string($result) && $result !== '') {
            return ['redirect' => self::safeRedirectTarget($result, $fallback), 'flash' => null, 'output' => ''];
        }

        if (is_array($result) && array_intersect(['message', 'type', 'redirect', 'payload'], array_keys($result)) !== []) {
            $message = trim(strip_tags((string) ($result['message'] ?? '')));
            $type = in_array($result['type'] ?? '', self::FLASH_TYPES, true) ? (string) $result['type'] : 'info';
            $flash = $message !== '' ? [
                'type' => $type,
                'message' => function_exists('mb_substr') ? mb_substr($message, 0, 1000) : substr($message, 0, 1000),
                'payload' => is_array($result['payload'] ?? null) ? $result['payload'] : [],
            ] : null;

            return [
                'redirect' => self::safeRedirectTarget((string) ($result['redirect'] ?? ''), $fallback),
                'flash' => $flash,
                'output' => '',
            ];
        }

        if (trim($output) === '') {
            return ['redirect' => $fallback, 'flash' => null, 'output' => ''];
        }

        return ['redirect' => null, 'flash' => null, 'output' => $output];
    }

    /**
     * Lokales Weiterleitungsziel (Pfad mit optionaler Query und Fragment) oder `$fallback`.
     *
     * @internal Öffentlich für Tests.
     */
    public static function safeRedirectTarget(string $target, string $fallback): string
    {
        $target = trim($target);
        if ($target === '') {
            return $fallback;
        }
        $safe = Security::safeLocalPath($target);

        return $safe !== '' ? $safe : $fallback;
    }

    /**
     * Route-Parameter bereinigen: `action` als Schlüssel (a-z, 0-9, _ und -), `id` nur Ziffern.
     *
     * @internal Öffentlich für Tests.
     * @param array<mixed> $params
     * @return array<string,string>
     */
    public static function normalizeRouteParams(array $params): array
    {
        $normalized = [];
        $action = is_scalar($params['action'] ?? null) ? strtolower(trim((string) $params['action'])) : '';
        $action = (string) preg_replace('/[^a-z0-9_-]/', '', $action);
        if ($action !== '') {
            $normalized['action'] = substr($action, 0, 80);
        }
        $id = is_scalar($params['id'] ?? null) ? trim((string) $params['id']) : '';
        if ($id !== '' && ctype_digit($id)) {
            $normalized['id'] = $id;
        }

        return $normalized;
    }

    /**
     * Theme-Override bevorzugen, damit Plugin-Bereiche im Member-Design des aktiven Themes statt im
     * generischen 365CMS-Wrapper erscheinen. Variablen im Wrapper: `$section`, `$user`, `$params`.
     *
     * @param array<string,mixed> $section
     * @param array<string,string> $params
     */
    private function renderSection(array $section, object $user, array $params): void
    {
        $themeFile = ThemeManager::instance()->getThemePath() . 'member/plugin-section.php';
        if (file_exists($themeFile)) {
            require $themeFile;
            return;
        }

        // Fallback auf den generischen Core-Wrapper.
        require ABSPATH . 'member/plugin-section.php';
    }

    /**
     * Fehlerseite (403/404) mit Kopf und Fuß des Mitgliederbereichs.
     * Theme-Override: gleichnamige Vorlage unter `<theme>/member/partials/` (Variable `$pluginErrorStatus`).
     */
    private function renderError(int $status): void
    {
        http_response_code($status);
        $pluginErrorStatus = $status;

        $themeFile = ThemeManager::instance()->getThemePath() . 'member/partials/plugin-not-found.php';
        if (file_exists($themeFile)) {
            require $themeFile;
            return;
        }

        require ABSPATH . 'member/partials/plugin-not-found.php';
    }

    /**
     * Flash über den Mechanismus des Mitgliederbereichs (`MemberController::flash()`, angezeigt von
     * `member/partials/alerts.php`).
     *
     * @param array<string,mixed> $payload
     */
    private function flash(string $type, string $message, array $payload = []): void
    {
        $controllerClass = \CMS\MemberArea\MemberController::class;
        if (!class_exists($controllerClass, false)) {
            $file = ABSPATH . 'member/includes/class-member-controller.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
        if (class_exists($controllerClass, false)) {
            $controllerClass::instance()->flash($type, $message, $payload);
            return;
        }

        // Rückfall mit derselben Struktur, falls der Controller nicht geladen werden kann.
        $_SESSION['member_alert'] = ['type' => $type, 'message' => $message, 'payload' => $payload];
    }

    private function redirectSeeOther(string $path): void
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

    /**
     * CSRF-Token für Formulare eines Plugin-Bereichs (Feldname `csrf_token`).
     * Je Bereich und Request wird genau ein Token erzeugt; alle Formulare der Seite teilen es.
     */
    public function csrfToken(string $slug): string
    {
        return $this->csrfTokens[$slug] ??= Security::instance()->generateToken('member_plugin_' . $slug);
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
     * - bei `csrf => 'core'` (Standard): gültiges Core-Token `member_plugin_<slug>` Pflicht.
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

        if (($section['csrf'] ?? 'core') !== 'plugin') {
            $token = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
            return Security::instance()->verifyToken($token, 'member_plugin_' . (string) $section['slug']);
        }

        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * HTML-id für Widget-Anker: Kleinbuchstaben, Ziffern, `-` und `_`, beginnend mit einem Buchstaben.
     *
     * @internal Öffentlich für Tests.
     */
    public static function sanitizeAnchor(string $value, string $fallback = ''): string
    {
        $anchor = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim($value))), '-');
        if ($anchor === '' || preg_match('/^[a-z]/', $anchor) !== 1) {
            return $fallback;
        }

        return substr($anchor, 0, 64);
    }

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

    private function logger(): Logger
    {
        return Logger::instance()->withChannel('member-plugins');
    }

    /** @param array<string,mixed> $context */
    private function debugLog(string $message, array $context = []): void
    {
        try {
            $this->logger()->debug($message, $context);
        } catch (\Throwable) {
            // Logging darf die Registrierung nie verhindern.
        }
    }
}
