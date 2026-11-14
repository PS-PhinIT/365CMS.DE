<?php
/**
 * 365CMS – Einheitliches Plugin-Admin-Layout (Layout-Vertrag 4.0.0)
 *
 * Wiederverwendbare Render-Helfer für Plugin-Adminseiten innerhalb von
 * `.cms-plugin-admin-content`. Die Gestaltung liegt in `assets/css/admin-plugins.css`.
 *
 *   ┌───────────────────────────────────────────────────────────────────────┐
 *   │ .cms-plugin-header                                                    │
 *   │  __info: Icon, Name (h1), Version/Status-Badge, Einzeiler  │ __stats  │
 *   ├────────────────────┬──────────────────────────────────────────────────┤
 *   │ .cms-plugin-sidebar│ .cms-plugin-main                                 │
 *   │  Übersicht         │  Dashboard (cms_plugin_dashboard()) oder         │
 *   │  Unterseite …      │  Unterseite: Header → Toolbar/Filter → Inhalt    │
 *   └────────────────────┴──────────────────────────────────────────────────┘
 *
 * Verwendung im Seiten-Callback eines Plugins:
 *
 *   echo cms_plugin_workspace_open([
 *       'plugin'      => 'mein-plugin',                  // Menü-Slug
 *       'title'       => 'PHINIT | Mein Plugin',
 *       'icon'        => 'ti ti-puzzle',
 *       'version'     => '1.2.0',
 *       'status'      => ['label' => 'Aktiv', 'tone' => 'success'],
 *       'description' => 'Einzeiler zum Zweck des Plugins.',
 *       'stats'       => [['label' => 'Einträge', 'value' => 12, 'icon' => 'ti ti-list']],
 *       'nav'         => [
 *           ['slug' => 'mein-plugin', 'label' => 'Übersicht'],
 *           ['slug' => 'mein-plugin-settings', 'label' => 'Einstellungen', 'icon' => 'ti ti-settings', 'badge' => 2],
 *       ],
 *   ]);
 *   echo cms_plugin_dashboard(['actions' => [...], 'checks' => [...], 'activity' => [...]]);
 *   echo cms_plugin_workspace_close();
 *
 * CSP: Die Ausgabe enthält keine Inline-Styles, Inline-Scripts oder Event-Handler.
 *
 * @package 365CMS
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_PLUGIN_WORKSPACE_VERSION')) {
    /** Version des Layout-Vertrags; Plugins prüfen damit, ob der Core das Layout bereitstellt. */
    define('CMS_PLUGIN_WORKSPACE_VERSION', '1.0.0');
}

if (!function_exists('cms_plugin_workspace_esc')) {
    function cms_plugin_workspace_esc(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('cms_plugin_workspace_icon')) {
    /** Tabler-Icon-Klasse normalisieren („ti-x“ oder „ti ti-x“); ungültige Werte ergeben ''. */
    function cms_plugin_workspace_icon(mixed $icon, string $fallback = ''): string
    {
        $icon = trim(is_string($icon) ? $icon : '');
        if ($icon === '' || preg_match('/^(?:ti\s+)?ti-[a-z0-9-]+$/', $icon) !== 1) {
            return $fallback;
        }

        return str_starts_with($icon, 'ti ') ? $icon : 'ti ' . $icon;
    }
}

if (!function_exists('cms_plugin_workspace_tone')) {
    function cms_plugin_workspace_tone(mixed $tone): string
    {
        $tone = strtolower(trim(is_string($tone) ? $tone : ''));
        $aliases = ['ok' => 'success', 'error' => 'danger', 'fail' => 'danger', 'warn' => 'warning'];
        $tone = $aliases[$tone] ?? $tone;

        return in_array($tone, ['success', 'warning', 'danger', 'info', 'muted'], true) ? $tone : '';
    }
}

if (!function_exists('cms_plugin_workspace_url_ok')) {
    /** Nur relative Pfade sowie http(s)-Adressen als Linkziel zulassen. */
    function cms_plugin_workspace_url_ok(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        return str_starts_with($url, '/') || str_starts_with($url, '#') || str_starts_with($url, '?')
            || preg_match('#^https?://#i', $url) === 1;
    }
}

if (!function_exists('cms_plugin_workspace_format_value')) {
    function cms_plugin_workspace_format_value(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            $decimals = is_float($value) && floor($value) !== $value ? 1 : 0;

            return number_format((float) $value, $decimals, ',', '.');
        }

        return is_scalar($value) ? (string) $value : '';
    }
}

if (!function_exists('cms_plugin_workspace_request_slugs')) {
    /**
     * Aktive Plugin-Unterseite aus der Anfrage: `?view=`, Pfad `/admin/plugins/<plugin>/<unterseite>`
     * und `$_GET['page']` (Fallback des AdminRouters) – in dieser Reihenfolge.
     *
     * @return list<string>
     */
    function cms_plugin_workspace_request_slugs(string $plugin = ''): array
    {
        $slugs = [];
        $view = $_GET['view'] ?? null;
        if (is_string($view) && trim($view) !== '') {
            $slugs[] = trim($view);
        }

        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        if (preg_match('#/admin/plugins/([A-Za-z0-9_-]+)(?:/([A-Za-z0-9_-]+))?/?$#', $path, $m) === 1) {
            if ($plugin === '' || $m[1] === $plugin) {
                $slugs[] = ($m[2] ?? '') !== '' ? $m[2] : $m[1];
            }
        }

        $page = $_GET['page'] ?? null;
        if (is_string($page) && trim($page) !== '') {
            $slugs[] = trim($page);
        }

        return array_values(array_unique($slugs));
    }
}

if (!function_exists('cms_plugin_workspace_active_index')) {
    /**
     * Index des aktiven Menüpunkts: explizit `active`, sonst Slug-Abgleich mit der Anfrage,
     * sonst der längste passende URL-Pfad, sonst der erste Punkt (Übersicht).
     *
     * Mit $explicitOnly zählt nur `active` (der Aufrufer hat den aktiven Punkt bereits bestimmt);
     * ohne Treffer ist dann kein Punkt aktiv (-1).
     *
     * @param list<array<string,mixed>> $nav
     */
    function cms_plugin_workspace_active_index(array $nav, string $plugin = '', bool $explicitOnly = false): int
    {
        foreach ($nav as $index => $item) {
            if (!empty($item['active'])) {
                return (int) $index;
            }
        }
        if ($explicitOnly) {
            return -1;
        }

        foreach (cms_plugin_workspace_request_slugs($plugin) as $slug) {
            foreach ($nav as $index => $item) {
                if ((string) ($item['slug'] ?? '') !== '' && (string) $item['slug'] === $slug) {
                    return (int) $index;
                }
            }
        }

        $request = '/' . trim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? ''), '/');
        $best = -1;
        $bestLength = -1;
        foreach ($nav as $index => $item) {
            $path = '/' . trim((string) (parse_url((string) ($item['url'] ?? ''), PHP_URL_PATH) ?? ''), '/');
            if ($path === '/') {
                continue;
            }
            if (($request === $path || str_starts_with($request, $path . '/')) && strlen($path) > $bestLength) {
                $best = (int) $index;
                $bestLength = strlen($path);
            }
        }

        return $best >= 0 ? $best : 0;
    }
}

if (!function_exists('cms_plugin_workspace_header')) {
    /**
     * Kopfzeile: links Name, Badges und Einzeiler, rechts 3–4 KPI-Karten.
     *
     * @param array<string,mixed> $args title, icon, version, status{label,tone}, description, stats
     */
    function cms_plugin_workspace_header(array $args): string
    {
        $title = trim((string) ($args['title'] ?? ''));
        $icon = cms_plugin_workspace_icon($args['icon'] ?? '');
        $version = ltrim(trim((string) ($args['version'] ?? '')), 'vV');
        $description = trim((string) ($args['description'] ?? ''));

        $parts = array_map('trim', explode('|', $title, 2));
        $name = count($parts) === 2 && $parts[1] !== ''
            ? '<span class="cms-plugin-header__brand">' . cms_plugin_workspace_esc($parts[0]) . '</span>'
                . '<span class="cms-plugin-header__sep" aria-hidden="true">|</span>'
                . '<span class="cms-plugin-header__name">' . cms_plugin_workspace_esc($parts[1]) . '</span>'
            : '<span class="cms-plugin-header__name">' . cms_plugin_workspace_esc($title) . '</span>';

        $badges = '';
        if ($version !== '') {
            $badges .= '<span class="cms-plugin-header__badge">v' . cms_plugin_workspace_esc($version) . '</span>';
        }
        $status = $args['status'] ?? null;
        if (is_array($status) && trim((string) ($status['label'] ?? '')) !== '') {
            $tone = cms_plugin_workspace_tone($status['tone'] ?? '');
            $badges .= '<span class="cms-plugin-header__badge' . ($tone !== '' ? ' cms-plugin-header__badge--' . $tone : '') . '">'
                . cms_plugin_workspace_esc($status['label']) . '</span>';
        }

        $html = '<header class="cms-plugin-header"><div class="cms-plugin-header__info">'
            . ($icon !== '' ? '<span class="cms-plugin-header__icon" aria-hidden="true"><i class="' . cms_plugin_workspace_esc($icon) . '"></i></span>' : '')
            . '<div class="cms-plugin-header__text"><div class="cms-plugin-header__titlerow">'
            . '<h1 class="cms-plugin-header__title">' . $name . '</h1>'
            . ($badges !== '' ? '<span class="cms-plugin-header__badges">' . $badges . '</span>' : '')
            . '</div>'
            . ($description !== '' ? '<p class="cms-plugin-header__desc">' . cms_plugin_workspace_esc($description) . '</p>' : '')
            . '</div></div>';

        $stats = [];
        foreach (is_array($args['stats'] ?? null) ? $args['stats'] : [] as $stat) {
            if (is_array($stat) && isset($stat['label'], $stat['value']) && is_scalar($stat['value'])) {
                $stats[] = $stat;
            }
        }
        // Höchstens vier Kennzahlen, damit die Kopfzeile einzeilig bleibt.
        $stats = array_slice($stats, 0, 4);

        if ($stats !== []) {
            $html .= '<ul class="cms-plugin-header__stats" aria-label="Kennzahlen">';
            foreach ($stats as $stat) {
                $tone = cms_plugin_workspace_tone($stat['tone'] ?? '');
                $statIcon = cms_plugin_workspace_icon($stat['icon'] ?? '', 'ti ti-chart-dots');
                $hint = trim((string) ($stat['hint'] ?? ''));
                $url = trim((string) ($stat['url'] ?? ''));
                $inner = '<span class="cms-plugin-kpi__icon" aria-hidden="true"><i class="' . cms_plugin_workspace_esc($statIcon) . '"></i></span>'
                    . '<span class="cms-plugin-kpi__body">'
                    . '<span class="cms-plugin-kpi__value">' . cms_plugin_workspace_esc(cms_plugin_workspace_format_value($stat['value'])) . '</span>'
                    . '<span class="cms-plugin-kpi__label">' . cms_plugin_workspace_esc($stat['label']) . '</span>'
                    . '</span>';
                $html .= '<li class="cms-plugin-kpi' . ($tone !== '' ? ' cms-plugin-kpi--' . $tone : '') . '"'
                    . ($hint !== '' ? ' title="' . cms_plugin_workspace_esc($hint) . '"' : '') . '>'
                    . (cms_plugin_workspace_url_ok($url) ? '<a class="cms-plugin-kpi__link" href="' . cms_plugin_workspace_esc($url) . '">' . $inner . '</a>' : $inner)
                    . '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '</header>';
    }
}

if (!function_exists('cms_plugin_workspace_sidebar')) {
    /**
     * Linke Plugin-Navigation. Der erste Punkt ist immer die Übersicht.
     *
     * Punkte mit `group` erscheinen unter einer Zwischenüberschrift (z. B. Reiter einer Einstellungsseite).
     *
     * @param list<array<string,mixed>> $nav slug, label, url, icon, badge, badge_tone, active, group
     */
    function cms_plugin_workspace_sidebar(array $nav, string $plugin = '', string $label = '', bool $explicitOnly = false): string
    {
        $nav = array_values(array_filter($nav, static fn ($item): bool => is_array($item) && trim((string) ($item['label'] ?? '')) !== ''));
        if ($nav === []) {
            return '';
        }

        $active = cms_plugin_workspace_active_index($nav, $plugin, $explicitOnly);
        $html = '<nav class="cms-plugin-sidebar" aria-label="' . cms_plugin_workspace_esc($label !== '' ? $label : 'Bereiche des Plugins') . '">'
            . '<ul class="cms-plugin-sidebar__list">';
        $group = '';
        foreach ($nav as $index => $item) {
            $itemGroup = trim((string) ($item['group'] ?? ''));
            if ($itemGroup !== $group) {
                $html .= $itemGroup !== ''
                    ? '<li class="cms-plugin-sidebar__group">' . cms_plugin_workspace_esc($itemGroup) . '</li>'
                    : '<li class="cms-plugin-sidebar__group cms-plugin-sidebar__group--end" aria-hidden="true"></li>';
                $group = $itemGroup;
            }
            $url = (string) ($item['url'] ?? '');
            if ($url === '' && (string) ($item['slug'] ?? '') !== '' && $plugin !== '') {
                $url = '/admin/plugins/' . rawurlencode($plugin) . '/' . rawurlencode((string) $item['slug']);
            }
            $icon = cms_plugin_workspace_icon($item['icon'] ?? '', $index === 0 ? 'ti ti-layout-dashboard' : 'ti ti-point');
            $isActive = $index === $active;
            $badge = $item['badge'] ?? null;
            $badgeTone = cms_plugin_workspace_tone($item['badge_tone'] ?? '');
            $badgeHtml = (is_int($badge) && $badge > 0) || (is_string($badge) && trim($badge) !== '')
                ? '<span class="cms-plugin-sidebar__badge' . ($badgeTone !== '' ? ' cms-plugin-sidebar__badge--' . $badgeTone : '') . '">'
                    . cms_plugin_workspace_esc(cms_plugin_workspace_format_value($badge)) . '</span>'
                : '';

            $html .= '<li class="cms-plugin-sidebar__item"><a class="cms-plugin-sidebar__link' . ($isActive ? ' is-active' : '') . '"'
                . ' href="' . cms_plugin_workspace_esc(cms_plugin_workspace_url_ok($url) ? $url : '#') . '"'
                . ($isActive ? ' aria-current="page"' : '') . '>'
                . '<i class="' . cms_plugin_workspace_esc($icon) . '" aria-hidden="true"></i>'
                . '<span class="cms-plugin-sidebar__label">' . cms_plugin_workspace_esc($item['label']) . '</span>'
                . $badgeHtml . '</a></li>';
        }

        return $html . '</ul></nav>';
    }
}

if (!function_exists('cms_plugin_workspace_open')) {
    /**
     * Öffnet das Plugin-Layout: Kopfzeile, Workspace-Grid, linke Navigation und Inhaltsspalte.
     * Gegenstück: cms_plugin_workspace_close().
     *
     * @param array<string,mixed> $args plugin, title, icon, version, status, description, stats, nav, nav_label,
     *                                  nav_explicit (true: aktiv ist nur ein Punkt mit 'active' => true)
     */
    function cms_plugin_workspace_open(array $args): string
    {
        $plugin = trim((string) ($args['plugin'] ?? ''));
        $nav = is_array($args['nav'] ?? null) ? array_values($args['nav']) : [];

        return '<div class="cms-plugin-layout"' . ($plugin !== '' ? ' data-plugin="' . cms_plugin_workspace_esc($plugin) . '"' : '') . '>'
            . cms_plugin_workspace_header($args)
            . '<div class="cms-plugin-workspace' . ($nav === [] ? ' cms-plugin-workspace--single' : '') . '">'
            . cms_plugin_workspace_sidebar($nav, $plugin, (string) ($args['nav_label'] ?? ''), !empty($args['nav_explicit']))
            . '<div class="cms-plugin-main">';
    }
}

if (!function_exists('cms_plugin_workspace_close')) {
    function cms_plugin_workspace_close(): string
    {
        return '</div></div></div>';
    }
}

if (!function_exists('cms_plugin_dashboard')) {
    /**
     * Standardisierte Dashboard-Startseite eines Plugins (rechte Spalte, Menüpunkt „Übersicht“):
     * 1. Schnellaktionen, 2. Status-/System-Check, 3. Letzte Aktivitäten, optional Bereichs-Kacheln.
     *
     * @param array<string,mixed> $args
     *   intro    string
     *   actions  list<{label,url,icon?,description?,primary?:bool,external?:bool}>
     *   checks   list<{label,status:ok|warning|error|info|muted,value?,hint?,url?}>
     *   activity {heading?,items:list<{title,url?,meta?,badge?,tone?}>,empty?,more_url?,more_label?}
     *            oder direkt eine Liste von Einträgen
     *   sections list<{label,url,icon?,description?}> (optional, Kachel je Bereich)
     */
    function cms_plugin_dashboard(array $args): string
    {
        $html = '<div class="cms-plugin-dashboard">';
        $intro = trim((string) ($args['intro'] ?? ''));
        if ($intro !== '') {
            $html .= '<p class="cms-plugin-dashboard__intro">' . cms_plugin_workspace_esc($intro) . '</p>';
        }

        // 1. Schnellaktionen
        $actions = [];
        foreach (is_array($args['actions'] ?? null) ? $args['actions'] : [] as $action) {
            if (is_array($action) && trim((string) ($action['label'] ?? '')) !== '' && cms_plugin_workspace_url_ok((string) ($action['url'] ?? ''))) {
                $actions[] = $action;
            }
        }
        if ($actions !== []) {
            $html .= '<section class="cms-plugin-panel cms-plugin-panel--actions" aria-labelledby="cms-plugin-dashboard-actions">'
                . '<div class="cms-plugin-panel__head"><h2 class="cms-plugin-panel__title" id="cms-plugin-dashboard-actions">'
                . cms_plugin_workspace_esc((string) ($args['actions_heading'] ?? 'Schnellaktionen')) . '</h2></div>'
                . '<ul class="cms-plugin-actions">';
            foreach ($actions as $action) {
                $external = !empty($action['external']);
                $description = trim((string) ($action['description'] ?? ''));
                $html .= '<li class="cms-plugin-actions__item"><a class="cms-plugin-action' . (!empty($action['primary']) ? ' cms-plugin-action--primary' : '') . '"'
                    . ' href="' . cms_plugin_workspace_esc((string) $action['url']) . '"'
                    . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
                    . '<span class="cms-plugin-action__icon" aria-hidden="true"><i class="' . cms_plugin_workspace_esc(cms_plugin_workspace_icon($action['icon'] ?? '', 'ti ti-arrow-right')) . '"></i></span>'
                    . '<span class="cms-plugin-action__body"><span class="cms-plugin-action__title">' . cms_plugin_workspace_esc($action['label'])
                    . ($external ? ' <i class="ti ti-external-link cms-plugin-action__external" aria-hidden="true"></i><span class="visually-hidden">(öffnet in neuem Tab)</span>' : '')
                    . '</span>'
                    . ($description !== '' ? '<span class="cms-plugin-action__text">' . cms_plugin_workspace_esc($description) . '</span>' : '')
                    . '</span></a></li>';
            }
            $html .= '</ul></section>';
        }

        $html .= '<div class="cms-plugin-dashboard__grid">';

        // 2. Status-/System-Check
        $checks = [];
        foreach (is_array($args['checks'] ?? null) ? $args['checks'] : [] as $check) {
            if (is_array($check) && trim((string) ($check['label'] ?? '')) !== '') {
                $checks[] = $check;
            }
        }
        $html .= '<section class="cms-plugin-panel cms-plugin-panel--checks" aria-labelledby="cms-plugin-dashboard-checks">'
            . '<div class="cms-plugin-panel__head"><h2 class="cms-plugin-panel__title" id="cms-plugin-dashboard-checks">'
            . cms_plugin_workspace_esc((string) ($args['checks_heading'] ?? 'Status & System-Check')) . '</h2></div>';
        if ($checks === []) {
            $html .= '<p class="cms-plugin-empty">Für dieses Plugin sind keine Prüfungen hinterlegt.</p>';
        } else {
            $icons = ['success' => 'ti ti-circle-check', 'warning' => 'ti ti-alert-triangle', 'danger' => 'ti ti-circle-x', 'info' => 'ti ti-info-circle', 'muted' => 'ti ti-circle-dashed'];
            $labels = ['success' => 'OK', 'warning' => 'Hinweis', 'danger' => 'Fehler', 'info' => 'Info', 'muted' => 'Nicht aktiv'];
            $html .= '<ul class="cms-plugin-checks">';
            foreach ($checks as $check) {
                $tone = cms_plugin_workspace_tone($check['status'] ?? 'info') ?: 'info';
                $value = trim(cms_plugin_workspace_format_value($check['value'] ?? ''));
                $hint = trim((string) ($check['hint'] ?? ''));
                $url = trim((string) ($check['url'] ?? ''));
                $label = cms_plugin_workspace_esc($check['label']);
                $html .= '<li class="cms-plugin-check cms-plugin-check--' . $tone . '">'
                    . '<i class="' . $icons[$tone] . ' cms-plugin-check__icon" aria-hidden="true"></i>'
                    . '<span class="visually-hidden">' . $labels[$tone] . ': </span>'
                    . '<span class="cms-plugin-check__body"><span class="cms-plugin-check__label">'
                    . (cms_plugin_workspace_url_ok($url) ? '<a href="' . cms_plugin_workspace_esc($url) . '">' . $label . '</a>' : $label) . '</span>'
                    . ($hint !== '' ? '<span class="cms-plugin-check__hint">' . cms_plugin_workspace_esc($hint) . '</span>' : '')
                    . '</span>'
                    . ($value !== '' ? '<span class="cms-plugin-check__value">' . cms_plugin_workspace_esc($value) . '</span>' : '')
                    . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</section>';

        // 3. Letzte Aktivitäten / aktuelle Einträge
        $activity = is_array($args['activity'] ?? null) ? $args['activity'] : [];
        if (array_is_list($activity)) {
            $activity = ['items' => $activity];
        }
        $items = [];
        foreach (is_array($activity['items'] ?? null) ? $activity['items'] : [] as $item) {
            if (is_array($item) && trim((string) ($item['title'] ?? '')) !== '') {
                $items[] = $item;
            }
        }
        $html .= '<section class="cms-plugin-panel cms-plugin-panel--activity" aria-labelledby="cms-plugin-dashboard-activity">'
            . '<div class="cms-plugin-panel__head"><h2 class="cms-plugin-panel__title" id="cms-plugin-dashboard-activity">'
            . cms_plugin_workspace_esc((string) ($activity['heading'] ?? 'Letzte Aktivitäten')) . '</h2>';
        $moreUrl = trim((string) ($activity['more_url'] ?? ''));
        if (cms_plugin_workspace_url_ok($moreUrl)) {
            $html .= '<a class="cms-plugin-panel__more" href="' . cms_plugin_workspace_esc($moreUrl) . '">'
                . cms_plugin_workspace_esc((string) ($activity['more_label'] ?? 'Alle anzeigen')) . '</a>';
        }
        $html .= '</div>';
        if ($items === []) {
            $html .= '<p class="cms-plugin-empty">' . cms_plugin_workspace_esc((string) ($activity['empty'] ?? 'Noch keine Aktivitäten vorhanden.')) . '</p>';
        } else {
            $html .= '<ul class="cms-plugin-activity">';
            foreach (array_slice($items, 0, 8) as $item) {
                $url = trim((string) ($item['url'] ?? ''));
                $title = cms_plugin_workspace_esc($item['title']);
                $meta = trim((string) ($item['meta'] ?? ''));
                $badge = trim((string) ($item['badge'] ?? ''));
                $tone = cms_plugin_workspace_tone($item['tone'] ?? '');
                $html .= '<li class="cms-plugin-activity__item"><span class="cms-plugin-activity__main">'
                    . (cms_plugin_workspace_url_ok($url) ? '<a class="cms-plugin-activity__title" href="' . cms_plugin_workspace_esc($url) . '">' . $title . '</a>' : '<span class="cms-plugin-activity__title">' . $title . '</span>')
                    . ($meta !== '' ? '<span class="cms-plugin-activity__meta">' . cms_plugin_workspace_esc($meta) . '</span>' : '')
                    . '</span>'
                    . ($badge !== '' ? '<span class="cms-plugin-activity__badge' . ($tone !== '' ? ' cms-plugin-activity__badge--' . $tone : '') . '">' . cms_plugin_workspace_esc($badge) . '</span>' : '')
                    . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</section></div>';

        // Optional: Bereichs-Kacheln
        $sections = [];
        foreach (is_array($args['sections'] ?? null) ? $args['sections'] : [] as $section) {
            if (is_array($section) && trim((string) ($section['label'] ?? '')) !== '' && cms_plugin_workspace_url_ok((string) ($section['url'] ?? ''))) {
                $sections[] = $section;
            }
        }
        if ($sections !== []) {
            $html .= '<section class="cms-plugin-panel cms-plugin-panel--sections" aria-labelledby="cms-plugin-dashboard-sections">'
                . '<div class="cms-plugin-panel__head"><h2 class="cms-plugin-panel__title" id="cms-plugin-dashboard-sections">'
                . cms_plugin_workspace_esc((string) ($args['sections_heading'] ?? 'Bereiche')) . '</h2></div><ul class="cms-plugin-tiles">';
            foreach ($sections as $section) {
                $description = trim((string) ($section['description'] ?? ''));
                $html .= '<li class="cms-plugin-tiles__item"><a class="cms-plugin-tile" href="' . cms_plugin_workspace_esc((string) $section['url']) . '">'
                    . '<i class="' . cms_plugin_workspace_esc(cms_plugin_workspace_icon($section['icon'] ?? '', 'ti ti-point')) . ' cms-plugin-tile__icon" aria-hidden="true"></i>'
                    . '<span class="cms-plugin-tile__body"><span class="cms-plugin-tile__title">' . cms_plugin_workspace_esc($section['label']) . '</span>'
                    . ($description !== '' ? '<span class="cms-plugin-tile__text">' . cms_plugin_workspace_esc($description) . '</span>' : '')
                    . '</span><i class="ti ti-chevron-right cms-plugin-tile__chevron" aria-hidden="true"></i></a></li>';
            }
            $html .= '</ul></section>';
        }

        return $html . '</div>';
    }
}
