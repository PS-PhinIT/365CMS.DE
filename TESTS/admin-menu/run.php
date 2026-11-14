<?php
/**
 * Tests für das datengetriebene Admin-Menü (Katalog, Profile, Validator, Registry, Builder).
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis (PHP >= 8.4, keine Datenbank nötig):
 *   php TESTS/admin-menu/run.php [-v]
 *   docker run --rm -v "$PWD":/repo -w /repo cms-php84 php TESTS/admin-menu/run.php -v
 *
 * Teil 1  Katalog: IDs, Routen, Seitendateien, Icons, Badges, Kategorien
 * Teil 2  Zugriff (statisch): Capabilities im Katalog = in der Seitendatei geprüfte Capabilities
 * Teil 3  Abdeckung: jede Adminseite und jede Modulseite ist im Katalog oder bewusst ausgenommen
 * Teil 4  Profile classic/structured: Bäume aus DOC/workflow/specs/ADMIN-MENU-PROFILE.md
 * Teil 5  Unit-Tests für LayoutValidator, MenuRegistry und MenuBuilder (Fakes für Rechte, Module, Dateien),
 *         die Plugin-API cms_admin_menu_set_preferred_parent() mit Filter sowie das Sidebar-Modell
 *         (AdminNavigationService: aktiver Eintrag, Badges, Aufklappzustand, Kachelseiten, Plugin-Hinweis)
 * Teil 6  Installer-Schritt „Admin-Layout“: läuft ohne Autoloader und Datenbank (eigener PHP-Prozess),
 *         Skizzen = echter MenuBuilder, Modulzustände ohne Datenbank (CoreModuleService)
 * Teil 7  Schema v25: Tabellen admin_menu_layouts und batch_jobs mit je einer Definition für
 *         Neuinstallation (SchemaManager), Update (MigrationManager) und Laufzeitprüfung
 *         (LayoutRepository bzw. BatchJobService)
 *
 * Bewusst ohne Laufzeittest gegen die Datenbank: Rollen und Rechte werden nie verändert
 * (DOC/workflow/specs/KLARSTELLUNGEN.md §7 „Zugriffsprüfung (statisch abgesichert)“).
 * Exit-Code 0 = alles grün, 1 = mindestens ein Fehler.
 */

declare(strict_types=1);

use CMS\MigrationManager;
use CMS\SchemaManager;
use CMS\Services\AdminMenu\AdminMenuItem;
use CMS\Services\AdminMenu\BadgeProvider;
use CMS\Services\AdminMenu\CoreCatalog;
use CMS\Services\AdminMenu\LayoutRepository;
use CMS\Services\AdminMenu\LayoutValidator;
use CMS\Services\AdminMenu\MenuBuilder;
use CMS\Services\AdminMenu\MenuRegistry;
use CMS\Services\AdminMenu\ProfileLayouts;
use CMS\Services\AdminNavigationService;
use CMS\Services\BatchJobService;
use CMS\Services\CoreModuleService;

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';

T::$verbose = in_array('-v', $argv ?? [], true);

// ─────────────────────────────────────────────────────────────────────────────
// Von Hand gepflegte Listen
// ─────────────────────────────────────────────────────────────────────────────

/** Badge-Schlüssel, die die Sidebar frisch (ungecacht) auswertet. */
const KNOWN_BADGES = ['updates', 'comments', 'form_submissions', 'privacy_requests', 'logs'];

/**
 * Optionale Module (KLARSTELLUNGEN §7): eigene Hauptpunkte erlaubt, aber nur bei aktivem Modul sichtbar.
 * Modul-Schlüssel aus CoreModuleService => ID-Präfix ihrer Katalogeinträge.
 */
const OPTIONAL_MODULES = [
    'ai_services' => 'ai.',
    'seo' => 'seo.',
    'member_dashboard' => 'members.',
    'subscription_admin_packages' => 'subscriptions.',
    'subscription_admin_orders' => 'subscriptions.',
    'subscription_admin_settings' => 'subscriptions.',
];

/**
 * Seitendateien, die erst in einer späteren Phase entstehen. Ihr Katalogeintrag bleibt bis dahin
 * ausgeblendet (MenuBuilder::isVisible prüft die Datei). Sobald die Datei existiert, schlägt der Test
 * fehl, bis sie hier entfernt ist – ab dann gilt der normale Zugriffsvergleich aus Teil 2.
 */
const RESERVED_FILES = [
];

/**
 * Seiten ohne eigenes Gate in der Seitendatei (Wrapper um gemeinsame Renderer mit Bereichs-Maps).
 * Eintrags-ID => [Datei mit der Prüfung, erwartete Capabilities (eine genügt)].
 * Geprüft wird: die Seitendatei bindet die Quelle (transitiv) ein, jede Capability steht dort als
 * String-Literal, und der Katalog führt genau diese Capabilities.
 */
const MANUAL_ACCESS = [
    // KI-Dienste: Wrapper um admin/ai-page.php, Gate über CMS_ADMIN_AI_READ_CAPABILITIES.
    'ai.dashboard' => ['admin/ai-page.php', ['manage_settings', 'manage_system', 'manage_ai_services']],
    'ai.translation' => ['admin/ai-page.php', ['manage_settings', 'manage_system', 'manage_ai_services']],
    'ai.content-creator' => ['admin/ai-page.php', ['manage_settings', 'manage_system', 'manage_ai_services']],
    'ai.seo-creator' => ['admin/ai-page.php', ['manage_settings', 'manage_system', 'manage_ai_services']],
    'ai.settings' => ['admin/ai-page.php', ['manage_settings', 'manage_system', 'manage_ai_services']],

    // SEO: Wrapper um admin/seo-page.php, Capability je Bereich aus cms_admin_seo_read_capabilities_by_section().
    'seo.dashboard' => ['admin/seo-page.php', ['manage_settings']],
    'seo.analytics' => ['admin/seo-page.php', ['manage_settings', 'view_analytics']],
    'seo.audit' => ['admin/seo-page.php', ['manage_settings']],
    'seo.meta' => ['admin/seo-page.php', ['manage_settings']],
    'seo.social' => ['admin/seo-page.php', ['manage_settings']],
    'seo.schema' => ['admin/seo-page.php', ['manage_settings']],
    'seo.sitemap' => ['admin/seo-page.php', ['manage_settings']],
    'seo.search-engines' => ['admin/seo-page.php', ['manage_settings']],
    'seo.technical' => ['admin/seo-page.php', ['manage_settings']],

    // Protokolle: admin/logs/<x>.php → admin/logs-<x>.php → admin/logs-page.php (ein gemeinsames Gate).
    'logs.overview' => ['admin/logs-page.php', ['manage_settings']],
    'logs.operational' => ['admin/logs-page.php', ['manage_settings']],
    'logs.security-audit' => ['admin/logs-page.php', ['manage_settings']],
    'logs.php-errors' => ['admin/logs-page.php', ['manage_settings']],
    'logs.channels' => ['admin/logs-page.php', ['manage_settings']],

    // Monitore, Systeminfo und Diagnose: Wrapper um admin/system-monitor-page.php.
    'monitor.assets' => ['admin/system-monitor-page.php', ['manage_settings']],
    'monitor.response-time' => ['admin/system-monitor-page.php', ['manage_settings']],
    'monitor.cron-status' => ['admin/system-monitor-page.php', ['manage_settings']],
    'monitor.disk-usage' => ['admin/system-monitor-page.php', ['manage_settings']],
    'monitor.scheduled-tasks' => ['admin/system-monitor-page.php', ['manage_settings']],
    'monitor.health-check' => ['admin/system-monitor-page.php', ['manage_settings']],
    'monitor.email-alerts' => ['admin/system-monitor-page.php', ['manage_settings']],
    'monitor.warnings' => ['admin/system-monitor-page.php', ['manage_settings']],
    'system.info' => ['admin/system-monitor-page.php', ['manage_settings']],
    'system.diagnose' => ['admin/system-monitor-page.php', ['manage_settings']],

    // Performance: Wrapper um admin/performance-page.php.
    'performance.overview' => ['admin/performance-page.php', ['manage_settings']],
    'performance.cache' => ['admin/performance-page.php', ['manage_settings']],
    'performance.media' => ['admin/performance-page.php', ['manage_settings']],
    'performance.database' => ['admin/performance-page.php', ['manage_settings']],
    'performance.settings' => ['admin/performance-page.php', ['manage_settings']],
    'performance.sessions' => ['admin/performance-page.php', ['manage_settings']],

    // Mitgliederbereich: Wrapper um admin/member-dashboard-page.php, Bereichs-Map
    // CMS_ADMIN_MEMBER_DASHBOARD_SECTION_CAPABILITIES (Profil-Felder verlangt manage_users).
    'members.general' => ['admin/member-dashboard-page.php', ['manage_settings']],
    'members.design' => ['admin/member-dashboard-page.php', ['manage_settings']],
    'members.frontend-modules' => ['admin/member-dashboard-page.php', ['manage_settings']],
    'members.widgets' => ['admin/member-dashboard-page.php', ['manage_settings']],
    'members.plugin-widgets' => ['admin/member-dashboard-page.php', ['manage_settings']],
    'members.notifications' => ['admin/member-dashboard-page.php', ['manage_settings']],
    'members.onboarding' => ['admin/member-dashboard-page.php', ['manage_settings']],
    'members.profile-fields' => ['admin/member-dashboard-page.php', ['manage_users']],
];

/**
 * Dateien unter admin/, die bewusst keinen Menüeintrag haben: Art => Ziel bzw. Zweck.
 * alias    = Weiterleitung über partials/redirect-alias-shell.php; das Ziel muss eine Katalogroute sein.
 * endpoint = AJAX-, API- oder Cron-Endpunkt ohne eigene Seite.
 * renderer = gemeinsamer Renderer, den Katalogseiten einbinden.
 * legacy   = alte Einstiegsdatei, die nur noch eingebunden wird oder weiterleitet.
 * tiles    = Kachelseiten der Gruppen im Anzeigemodus „tiles“ (MenuBuilder::CONFIGURATION_ROUTE).
 */
const NOT_IN_MENU = [
    'admin/configuration.php' => ['tiles', '/admin/configuration'],
    'admin/cms-logs.php' => ['alias', '/admin/logs'],
    'admin/deletion-requests.php' => ['alias', '/admin/data-requests'],
    'admin/design-settings.php' => ['alias', '/admin/theme-editor'],
    'admin/privacy-requests.php' => ['alias', '/admin/data-requests'],
    'admin/support.php' => ['alias', '/admin/documentation'],
    'admin/system-info.php' => ['alias', '/admin/info'],
    'admin/theme-settings.php' => ['alias', '/admin/settings'],
    'admin/admin-ui.php' => ['endpoint', 'gemeinsame Admin-UI-Aktionen (JSON)'],
    'admin/ai-generate-seo-metadata.php' => ['endpoint', 'KI: SEO-Metadaten erzeugen (JSON)'],
    'admin/ai-translate-editorjs.php' => ['endpoint', 'KI: Editor.js-Inhalte übersetzen (JSON)'],
    'admin/editorial-calendar-api.php' => ['endpoint', 'Redaktionskalender: Termine und Verschieben (JSON)'],
    'admin/content-exchange-api.php' => ['endpoint', 'Import/Export: Fortschritt von Export und Import (JSON)'],
    'admin/media-tools-api.php' => ['endpoint', 'Medien-Werkzeuge: Vorschaubilder neu erzeugen (JSON)'],
    'admin/error-report.php' => ['endpoint', 'Fehlerbericht senden (POST)'],
    'admin/media-license-api.php' => ['endpoint', 'Medien: Urheber- und Lizenzangaben (JSON)'],
    'admin/monitor-cron-runner.php' => ['endpoint', 'Cron-Runner der Monitore'],
    'admin/post-editor-api.php' => ['endpoint', 'API des Beitragseditors (JSON)'],
    'admin/search-index-api.php' => ['endpoint', 'Suchindex: Neuaufbau/Status (JSON)'],
    'admin/ai-page.php' => ['renderer', 'KI-Dienste'],
    'admin/logs-page.php' => ['renderer', 'Protokolle'],
    'admin/member-dashboard-page.php' => ['renderer', 'Mitgliederbereich'],
    'admin/performance-page.php' => ['renderer', 'Performance'],
    'admin/seo-page.php' => ['renderer', 'SEO'],
    'admin/system-monitor-page.php' => ['renderer', 'Monitore, Systeminfo, Diagnose'],
    'admin/logs-channels.php' => ['legacy', 'eingebunden von admin/logs/channels.php'],
    'admin/logs-operational.php' => ['legacy', 'eingebunden von admin/logs/operational.php'],
    'admin/logs-php-errors.php' => ['legacy', 'eingebunden von admin/logs/php-errors.php'],
    'admin/logs-security-audit.php' => ['legacy', 'eingebunden von admin/logs/security-audit.php'],
    'admin/logs/index.php' => ['legacy', 'alte Einstiegsseite der Protokolle'],
];

// ─────────────────────────────────────────────────────────────────────────────
// Hilfsfunktionen
// ─────────────────────────────────────────────────────────────────────────────

function am_read(string $relative): string
{
    $content = @file_get_contents(ABSPATH . $relative);

    return is_string($content) ? $content : '';
}

/**
 * Gates einer Seitendatei und die darin geprüften Capabilities (Vereinigung aller Gates).
 * Erkannt werden nur einfache Formen; alles andere landet in „problems“ und gehört in MANUAL_ACCESS.
 *
 * @return array{gates:int, caps:list<string>, problems:list<string>}
 */
function am_page_access(string $src): array
{
    $patterns = [
        // function cms_admin_…can_access…(): bool { … }
        '/^([ \t]*)function\s+cms_admin_\w*can_access\w*\s*\([^)]*\)\s*:\s*bool\s*\{(.*?)\R\1\}/ms',
        // 'access_checker' => static fn (): bool => …,
        '/()\'access_checker\'\s*=>\s*static\s+fn\s*\([^)]*\)\s*:\s*bool\s*=>(.*?),\s*\R\s*(?:\'\w+\'\s*=>|\])/s',
        // 'access_checker' => static function (): bool { … }
        '/^([ \t]*)\'access_checker\'\s*=>\s*static\s+function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?:\s*bool\s*\{(.*?)\R\1\}/ms',
        // if (!Auth::instance()->isAdmin() …) { – Gate direkt im Seitenkopf
        '/^()if\s*\((\s*!\s*(?:(?:\\\\)?CMS\\\\)?Auth::instance\(\)->isAdmin\(\).*?)\)\s*\{/ms',
    ];

    $gates = 0;
    $caps = [];
    $problems = [];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $src, $matches) === false) {
            $problems[] = 'Regex-Fehler ' . preg_last_error_msg();
            continue;
        }
        foreach ($matches[2] as $body) {
            $gates++;
            array_push($caps, ...am_caps_from_body($body, $src, $problems));
        }
    }

    $caps = array_values(array_unique($caps));
    sort($caps);

    return ['gates' => $gates, 'caps' => $caps, 'problems' => array_values(array_unique($problems))];
}

/**
 * @param list<string> $problems
 * @return list<string>
 */
function am_caps_from_body(string $body, string $src, array &$problems): array
{
    $caps = [];
    $constants = preg_match_all('/\bCMS_ADMIN_[A-Z0-9_]*CAPABILIT(?:Y|IES)\b/', $body, $found) > 0
        ? array_values(array_unique($found[0]))
        : [];

    if (preg_match_all('/\b(?:hasCapability|current_user_can)\s*\(\s*([^)\s][^)]*)\)/', $body, $calls) > 0) {
        foreach ($calls[1] as $argument) {
            $argument = trim($argument);
            if (preg_match('/^\'([a-z0-9_.]+)\'$/', $argument, $literal) === 1) {
                $caps[] = $literal[1];
            } elseif (preg_match('/^CMS_ADMIN_[A-Z0-9_]*CAPABILIT(?:Y|IES)$/', $argument) === 1) {
                continue; // über die Konstante unten aufgelöst
            } elseif (str_starts_with($argument, '$') && $constants !== []) {
                continue; // Schleife über eine Capability-Konstante derselben Datei
            } else {
                $problems[] = 'Argument „' . $argument . '“ ist weder String-Literal noch Capability-Konstante';
            }
        }
    }

    foreach ($constants as $constant) {
        $values = am_resolve_constant($src, $constant);
        if ($values === null) {
            $problems[] = 'Konstante ' . $constant . ' ist nicht in derselben Datei als String oder einfache Liste definiert';
            continue;
        }
        array_push($caps, ...$values);
    }

    if (preg_match_all('/\b(cms_admin_\w*can_access\w*)\s*\(/', $body, $helpers) > 0) {
        foreach (array_unique($helpers[1]) as $helper) {
            if (preg_match('/function\s+' . preg_quote($helper, '/') . '\s*\(/', $src) !== 1) {
                $problems[] = 'Hilfsfunktion ' . $helper . '() ist nicht in derselben Datei definiert';
            }
        }
    }

    return $caps;
}

/**
 * Wert einer Capability-Konstante derselben Datei: String oder einfache Liste (keine Map).
 *
 * @return list<string>|null
 */
function am_resolve_constant(string $src, string $name): ?array
{
    $quoted = preg_quote($name, '/');
    if (preg_match('/\bconst\s+' . $quoted . '\s*=\s*\'([a-z0-9_.]+)\'\s*;/', $src, $match) === 1
        || preg_match('/\bdefine\(\s*\'' . $quoted . '\'\s*,\s*\'([a-z0-9_.]+)\'\s*\)/', $src, $match) === 1) {
        return [$match[1]];
    }
    if (preg_match('/\bconst\s+' . $quoted . '\s*=\s*\[([^\]]*)\]\s*;/', $src, $match) === 1
        && !str_contains($match[1], '=>')
        && preg_match_all('/\'([a-z0-9_.]+)\'/', $match[1], $values) > 0) {
        return $values[1];
    }

    return null;
}

/**
 * Dateien, die eine Seitendatei per require/include (__DIR__ bzw. dirname(__DIR__)) einbindet – transitiv.
 *
 * @return list<string> relativ zu ABSPATH
 */
function am_required_files(string $relative, int $maxDepth = 4): array
{
    $found = [];
    $queue = [[$relative, 0]];
    while ($queue !== []) {
        [$file, $depth] = array_shift($queue);
        if ($depth >= $maxDepth) {
            continue;
        }
        $pattern = '/\b(?:require|include)(?:_once)?\s*\(?\s*(__DIR__|dirname\(__DIR__\))\s*\.\s*\'\/([^\']+)\'/';
        if (preg_match_all($pattern, am_read($file), $matches, PREG_SET_ORDER) === 0) {
            continue;
        }
        foreach ($matches as $match) {
            $dir = $match[1] === '__DIR__' ? dirname($file) : dirname($file, 2);
            $target = ($dir === '.' ? '' : $dir . '/') . $match[2];
            if (!in_array($target, $found, true)) {
                $found[] = $target;
                $queue[] = [$target, $depth + 1];
            }
        }
    }

    return $found;
}

/**
 * Seitendatei, die der Admin-Router für die Route eines Katalogeintrags lädt.
 *
 * @param array<string, string> $extensionTabs Tab-Schlüssel => Datei unter admin/settings-tabs/
 */
function am_expected_file(AdminMenuItem $item, array $extensionTabs): ?string
{
    $parts = $item->routeParts();
    $path = $parts['path'];
    if ($path === '/admin') {
        return 'admin/index.php';
    }
    if (preg_match('#^/admin/logs/([a-z0-9-]+)$#', $path, $match) === 1) {
        return 'admin/logs/' . $match[1] . '.php';
    }
    if (preg_match('#^/admin/([a-z0-9-]+)$#', $path, $match) !== 1) {
        return null;
    }
    $tab = $parts['query']['tab'] ?? '';
    if ($match[1] === 'settings' && isset($extensionTabs[$tab])) {
        return 'admin/settings-tabs/' . $extensionTabs[$tab];
    }

    return 'admin/' . $match[1] . '.php';
}

/**
 * admin_pages je Modul aus CoreModuleService (statisch gelesen, ohne Datenbank).
 *
 * @return array<string, list<string>>
 */
function am_module_pages(): array
{
    $src = am_read('core/Services/CoreModuleService.php');
    $modules = [];
    if (preg_match_all('/^ {8}\'([a-z_]+)\'\s*=>\s*\[(.*?)^ {8}\],/ms', $src, $blocks, PREG_SET_ORDER) > 0) {
        foreach ($blocks as $block) {
            if (preg_match('/\'admin_pages\'\s*=>\s*\[(.*?)\]/s', $block[2], $pages) === 1) {
                preg_match_all('/\'([a-z0-9-]+)\'/', $pages[1], $slugs);
                $modules[$block[1]] = $slugs[1];
            }
        }
    }

    return $modules;
}

/**
 * Erweiterungs-Tabs aus admin/settings.php (CMS_ADMIN_SETTINGS_EXTENSION_TABS).
 *
 * @return array<string, string>
 */
function am_extension_tabs(): array
{
    $tabs = [];
    if (preg_match('/const\s+CMS_ADMIN_SETTINGS_EXTENSION_TABS\s*=\s*\[(.*?)\];/s', am_read('admin/settings.php'), $block) === 1) {
        preg_match_all('/\'([a-z0-9-]+)\'\s*=>\s*\'([a-z0-9-]+\.php)\'/', $block[1], $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $tabs[$pair[1]] = $pair[2];
        }
    }

    return $tabs;
}

/**
 * @return array<string, true>
 */
function am_tabler_icons(): array
{
    preg_match_all('/\.ti-([a-z0-9-]+):before/', am_read('assets/tabler-icons/tabler-icons.min.css'), $icons);

    return array_fill_keys($icons[1], true);
}

/**
 * Builder-Ausgabe → [Eintrags-ID => Gruppen-ID ('' = oberste Ebene)] in Reihenfolge.
 *
 * @param array<string, mixed> $menu
 * @return array<string, string>
 */
function am_item_positions(array $menu): array
{
    $positions = [];
    foreach ($menu['sections'] as $section) {
        foreach ($section['nodes'] as $node) {
            if ($node['kind'] === 'item') {
                $positions[$node['id']] = '';
                continue;
            }
            foreach ($node['items'] as $item) {
                $positions[$item['id']] = $node['id'];
            }
        }
    }

    return $positions;
}

/**
 * @param array<string, mixed> $menu
 * @return list<string> IDs der obersten Ebene (Einträge und Gruppen) über alle Abschnitte
 */
function am_top_ids(array $menu): array
{
    $ids = [];
    foreach ($menu['sections'] as $section) {
        foreach ($section['nodes'] as $node) {
            $ids[] = $node['id'];
        }
    }

    return $ids;
}

/**
 * @param array<string, mixed> $menu
 * @return list<string>|null IDs im Abschnitt „Aktive Plugins“ (null = Abschnitt fehlt)
 */
function am_active_plugins(array $menu): ?array
{
    foreach ($menu['sections'] as $section) {
        if ($section['id'] === MenuBuilder::ACTIVE_PLUGINS_SECTION_ID) {
            return array_column($section['nodes'], 'id');
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $menu
 * @return array<string, mixed>|null
 */
function am_node(array $menu, string $id): ?array
{
    foreach ($menu['sections'] as $section) {
        foreach ($section['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
            foreach ($node['items'] ?? [] as $item) {
                if ($item['id'] === $id) {
                    return $item;
                }
            }
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $menu
 * @return list<string> Eintrags-IDs einer Gruppe der Builder-Ausgabe
 */
function am_group_items(array $menu, string $groupId): array
{
    $node = am_node($menu, $groupId);

    return $node !== null && $node['kind'] === 'group' ? array_column($node['items'], 'id') : [];
}

/**
 * @param array<string, mixed> $layout
 * @return list<string> Eintrags-IDs einer Gruppe im Layout
 */
function am_layout_group(array $layout, string $groupId): array
{
    foreach ($layout['nodes'] as $node) {
        if ($node['type'] === 'group' && $node['id'] === $groupId) {
            return $node['items'];
        }
    }

    return [];
}

/**
 * @param list<string> $values
 * @return list<string>
 */
function am_sorted(array $values): array
{
    $values = array_values(array_unique($values));
    sort($values);

    return $values;
}

// ─────────────────────────────────────────────────────────────────────────────
// Gemeinsame Daten
// ─────────────────────────────────────────────────────────────────────────────

$catalogList = CoreCatalog::items();
$tabler = am_tabler_icons();
$extensionTabs = am_extension_tabs();
$modulePages = am_module_pages();
$optionalPages = [];
foreach (OPTIONAL_MODULES as $module => $prefix) {
    foreach ($modulePages[$module] ?? [] as $slug) {
        $optionalPages[$slug] = true;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Teil 1: Katalog
// ─────────────────────────────────────────────────────────────────────────────

T::run('Teil 1: Katalog', static function () use ($catalogList, $tabler, $extensionTabs): void {
    T::check(count($tabler) > 1000, 'Tabler-Icon-Liste geladen (' . count($tabler) . ' Icons)');
    T::same(['feeds' => 'feeds.php', 'security' => 'security.php', 'sso' => 'sso.php', 'admin-menu' => 'admin-menu.php'], $extensionTabs, 'Erweiterungs-Tabs aus admin/settings.php gelesen');

    $settings = am_read('admin/settings.php');
    $dispatch = strpos($settings, "require __DIR__ . '/settings-tabs/'");
    $gate = strpos($settings, 'if (!cms_admin_settings_can_access())');
    T::check($dispatch !== false && $gate !== false && $dispatch < $gate, 'settings.php leitet Erweiterungs-Tabs vor dem manage_settings-Gate weiter (jeder Tab hat ein eigenes Gate)');

    $ids = [];
    $routes = [];
    $pageSlugs = [];
    $usedReserved = [];
    $areas = [];
    foreach ($catalogList as $item) {
        $id = $item->id;
        $errors = [];
        if (preg_match(AdminMenuItem::ID_PATTERN, $id) !== 1 || str_starts_with($id, 'plugin:')) {
            $errors[] = 'ungültige Core-ID';
        }
        if (isset($ids[$id])) {
            $errors[] = 'ID doppelt';
        }
        $ids[$id] = true;
        if ($item->label === '' || LayoutValidator::label($item->label) !== $item->label) {
            $errors[] = 'Beschriftung leer, mit Markup oder länger als ' . LayoutValidator::MAX_LABEL_LENGTH . ' Zeichen';
        }
        if ($item->tileLabel !== '' && LayoutValidator::label($item->tileLabel) !== $item->tileLabel) {
            $errors[] = 'Kachel-Beschriftung ungültig';
        }
        if (($item->icon['type'] ?? '') !== 'tabler' || !isset($tabler[$item->icon['name'] ?? ''])) {
            $errors[] = 'Icon ' . T::export($item->icon) . ' ist kein Tabler-Icon';
        }
        foreach ($item->capabilities as $capability) {
            if (!is_string($capability) || preg_match('/^[a-z][a-z0-9_.]*$/', $capability) !== 1) {
                $errors[] = 'ungültige Capability ' . T::export($capability);
            }
        }
        if (count(array_unique($item->capabilities)) !== count($item->capabilities)) {
            $errors[] = 'Capability doppelt';
        }
        if ($item->badge !== '' && !in_array($item->badge, KNOWN_BADGES, true)) {
            $errors[] = 'unbekannter Badge ' . $item->badge;
        }
        if ($item->category !== '' && !isset(CoreCatalog::CATEGORY_LABELS[$item->category])) {
            $errors[] = 'unbekannte Kategorie ' . $item->category;
        }
        if ($item->categoryLabel !== (CoreCatalog::CATEGORY_LABELS[$item->category] ?? '')) {
            $errors[] = 'Kategorie-Beschriftung passt nicht zur Kategorie';
        }
        if ($item->source !== 'core' || $item->pluginSlug !== '') {
            $errors[] = 'Core-Eintrag mit Plugin-Quelle';
        }
        $area = CoreCatalog::areaOf($id);
        $areas[$area] = true;
        if ($area === 'other') {
            $errors[] = 'kein Bereich im Menü-Editor (ID-Präfix fehlt in CoreCatalog::AREA_LABELS)';
        }
        if (isset($routes[$item->route])) {
            $errors[] = 'Route doppelt (' . $routes[$item->route] . ')';
        }
        $routes[$item->route] = $id;
        if ($item->requiresMarketplace !== str_contains($item->route, 'marketplace')) {
            $errors[] = 'Marketplace-Kennzeichen passt nicht zur Route';
        }

        if ($item->isExternal()) {
            if ($item->file !== '' || $item->pageSlug !== '') {
                $errors[] = 'externer Link mit Seitendatei oder Seiten-Slug';
            }
            if (!str_starts_with($item->route, '/') || str_starts_with($item->route, '//') || str_starts_with($item->route, '/admin')) {
                $errors[] = 'externer Link muss eine Member- oder Frontend-Route sein';
            }
            T::info($id . ': externer Link ' . $item->route . ' – vom Seitendatei-Check ausgenommen');
            T::check($errors === [], $id . ': Katalogeintrag (externer Link)', implode('; ', $errors));
            continue;
        }

        $parts = $item->routeParts();
        if (array_diff(array_keys($parts['query']), ['tab']) !== []) {
            $errors[] = 'Route darf als Query nur „tab“ enthalten';
        }
        $expected = am_expected_file($item, $extensionTabs);
        if ($expected === null || $expected !== $item->file) {
            $errors[] = 'Seitendatei ' . $item->file . ' passt nicht zur Route (erwartet ' . T::export($expected) . ')';
        }
        if (preg_match('/^[a-z0-9-]+$/', $item->pageSlug) !== 1) {
            $errors[] = 'Seiten-Slug fehlt oder ist ungültig';
        } elseif (isset($pageSlugs[$item->pageSlug])) {
            $errors[] = 'Seiten-Slug doppelt (' . $pageSlugs[$item->pageSlug] . ')';
        }
        $pageSlugs[$item->pageSlug] = $id;

        $exists = is_file(ABSPATH . $item->file);
        if (isset(RESERVED_FILES[$item->file])) {
            $usedReserved[$item->file] = true;
            if ($exists) {
                $errors[] = $item->file . ' existiert – aus RESERVED_FILES entfernen';
            } else {
                T::info($id . ': ' . $item->file . ' folgt in ' . RESERVED_FILES[$item->file] . ' – Eintrag bis dahin ausgeblendet');
            }
        } elseif (!$exists) {
            $errors[] = 'Seitendatei ' . $item->file . ' fehlt';
        }
        $tab = $parts['query']['tab'] ?? '';
        if ($tab !== '' && $exists && !str_starts_with($item->file, 'admin/settings-tabs/') && !str_contains(am_read($item->file), "'" . $tab . "'")) {
            $errors[] = $item->file . ' kennt den Tab „' . $tab . '“ nicht';
        }

        T::check($errors === [], $id . ': Katalogeintrag', implode('; ', $errors));
    }

    foreach (RESERVED_FILES as $file => $phase) {
        T::check(isset($usedReserved[$file]), 'RESERVED_FILES[' . $file . '] gehört zu einem Katalogeintrag');
    }
    T::same(['dashboard', 'people.users', 'people.roles', 'settings.admin-menu'], CoreCatalog::lockedIds(), 'Gesperrte Einträge (immer erreichbar)');
    T::same([], array_values(array_diff(array_keys(CoreCatalog::AREA_LABELS), ['other'], array_keys($areas))), 'Jeder Bereich des Menü-Editors hat Katalogeinträge');
});

// ─────────────────────────────────────────────────────────────────────────────
// Teil 2: Zugriff (statisch) – Katalog-Capabilities = in der Seitendatei geprüfte Capabilities
// ─────────────────────────────────────────────────────────────────────────────

T::run('Teil 2: Zugriff (statisch)', static function () use ($catalogList): void {
    $manualUsed = [];
    foreach ($catalogList as $item) {
        if ($item->isExternal()) {
            T::info($item->id . ': externer Link – kein Zugriffsvergleich');
            continue;
        }
        if (!is_file(ABSPATH . $item->file)) {
            continue; // reserviert (Teil 1)
        }

        $access = am_page_access(am_read($item->file));
        $automatic = $access['gates'] > 0 && $access['problems'] === [];
        $catalogCaps = am_sorted($item->capabilities);

        if (isset(MANUAL_ACCESS[$item->id])) {
            $manualUsed[$item->id] = true;
            [$source, $expected] = MANUAL_ACCESS[$item->id];
            $errors = [];
            if ($automatic) {
                $errors[] = 'Seite ist automatisch prüfbar (' . implode(', ', $access['caps']) . ') – aus MANUAL_ACCESS entfernen';
            }
            if (!is_file(ABSPATH . $source)) {
                $errors[] = 'Quelle ' . $source . ' fehlt';
            } elseif ($source !== $item->file && !in_array($source, am_required_files($item->file), true)) {
                $errors[] = $item->file . ' bindet ' . $source . ' nicht ein';
            }
            $sourceCode = am_read($source);
            foreach ($expected as $capability) {
                if (!str_contains($sourceCode, "'" . $capability . "'")) {
                    $errors[] = 'Capability ' . $capability . ' steht nicht in ' . $source;
                }
            }
            if (am_sorted($expected) !== $catalogCaps) {
                $errors[] = 'Katalog ' . T::export($catalogCaps) . ' ≠ Liste ' . T::export(am_sorted($expected));
            }
            T::check($errors === [], $item->id . ': Capabilities laut gepflegter Liste (' . $source . ')', implode('; ', $errors));
            continue;
        }

        if (!$automatic) {
            T::check(false, $item->id . ': Gate in ' . $item->file . ' automatisch prüfbar',
                $access['gates'] === 0 ? 'kein Gate gefunden – in MANUAL_ACCESS eintragen' : implode('; ', $access['problems']));
            continue;
        }
        T::same($catalogCaps, $access['caps'], $item->id . ': Capabilities = Gate in ' . $item->file);
    }

    foreach (array_keys(MANUAL_ACCESS) as $id) {
        T::check(isset($manualUsed[$id]), 'MANUAL_ACCESS[' . $id . '] gehört zu einem Katalogeintrag mit vorhandener Seitendatei');
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Teil 3: Abdeckung – Seitendateien und Modulseiten
// ─────────────────────────────────────────────────────────────────────────────

T::run('Teil 3: Abdeckung', static function () use ($catalogList, $modulePages, $optionalPages): void {
    $catalogFiles = [];
    $catalogPaths = [];
    $pageSlugs = [];
    foreach ($catalogList as $item) {
        if ($item->file !== '') {
            $catalogFiles[$item->file] = true;
        }
        $catalogPaths[$item->routeParts()['path']] = true;
        if ($item->pageSlug !== '') {
            $pageSlugs[$item->pageSlug] = $item;
        }
    }

    $files = [
        ...(glob(ABSPATH . 'admin/*.php') ?: []),
        ...(glob(ABSPATH . 'admin/logs/*.php') ?: []),
        ...(glob(ABSPATH . 'admin/settings-tabs/*.php') ?: []),
    ];
    $uncovered = [];
    foreach ($files as $absolute) {
        $relative = substr($absolute, strlen(ABSPATH));
        $inCatalog = isset($catalogFiles[$relative]);
        $excluded = isset(NOT_IN_MENU[$relative]);
        if ($inCatalog && $excluded) {
            T::check(false, $relative . ' steht im Katalog und in NOT_IN_MENU');
        }
        if (!$inCatalog && !$excluded) {
            $uncovered[] = $relative;
        }
    }
    T::same([], $uncovered, count($files) . ' Seitendateien unter admin/ sind im Katalog oder in NOT_IN_MENU');

    $usedFiles = [];
    foreach ($catalogList as $item) {
        if ($item->file !== '' && is_file(ABSPATH . $item->file)) {
            foreach (am_required_files($item->file) as $required) {
                $usedFiles[$required] = true;
            }
        }
    }
    foreach (NOT_IN_MENU as $file => [$kind, $target]) {
        $errors = [];
        if (!is_file(ABSPATH . $file)) {
            $errors[] = 'Datei fehlt – aus NOT_IN_MENU entfernen';
        }
        if (!in_array($kind, ['alias', 'endpoint', 'renderer', 'legacy', 'tiles'], true)) {
            $errors[] = 'unbekannte Art ' . $kind;
        }
        if ($kind === 'tiles' && ($target !== MenuBuilder::CONFIGURATION_ROUTE || '/' . substr($file, 0, -4) !== $target)) {
            $errors[] = 'Kachelseite muss die Route ' . MenuBuilder::CONFIGURATION_ROUTE . ' bedienen';
        }
        if ($kind === 'alias') {
            if (!str_contains(am_read($file), "'target_url' => '" . $target . "'")) {
                $errors[] = 'Weiterleitungsziel ' . $target . ' steht nicht in der Datei';
            }
            if (!isset($catalogPaths[$target])) {
                $errors[] = 'Ziel ' . $target . ' ist keine Katalogroute';
            }
        }
        if ($kind === 'renderer' && !isset($usedFiles[$file])) {
            $errors[] = 'wird von keiner Katalogseite eingebunden';
        }
        T::check($errors === [], $file . ' (' . $kind . ')', implode('; ', $errors));
    }

    T::check(count($modulePages) >= 10, 'Module aus CoreModuleService gelesen (' . count($modulePages) . ')');
    $aliasSlugs = [];
    foreach (NOT_IN_MENU as $file => [$kind]) {
        if ($kind === 'alias') {
            $aliasSlugs[basename($file, '.php')] = true;
        }
    }
    $moduleOfPage = [];
    foreach ($modulePages as $module => $slugs) {
        foreach ($slugs as $slug) {
            $moduleOfPage[$slug] = $module;
            T::check(isset($pageSlugs[$slug]) || isset($aliasSlugs[$slug]), 'Modulseite ' . $slug . ' (' . $module . ') ist im Katalog oder ein Alias');
        }
    }
    foreach ($catalogList as $item) {
        $parts = $item->routeParts();
        if ($item->isExternal() || $parts['query'] !== [] || preg_match('#^/admin/([a-z0-9-]+)$#', $parts['path'], $match) !== 1) {
            continue;
        }
        if (isset($moduleOfPage[$match[1]])) {
            T::same($match[1], $item->pageSlug, $item->id . ': Seiten-Slug = Modulseite (' . $moduleOfPage[$match[1]] . ')');
        }
    }

    foreach (OPTIONAL_MODULES as $module => $prefix) {
        T::check(($modulePages[$module] ?? []) !== [], 'Optionales Modul ' . $module . ' hat Adminseiten');
        foreach ($modulePages[$module] ?? [] as $slug) {
            $item = $pageSlugs[$slug] ?? null;
            T::check($item !== null && str_starts_with($item->id, $prefix), 'Modulseite ' . $slug . ' gehört zu einem Eintrag mit Präfix ' . $prefix);
        }
    }
    foreach ($catalogList as $item) {
        foreach (array_unique(OPTIONAL_MODULES) as $prefix) {
            if (str_starts_with($item->id, $prefix)) {
                T::check(isset($optionalPages[$item->pageSlug]), $item->id . ': wird mit seinem optionalen Modul ausgeblendet');
            }
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Teil 4: Profile classic und structured (DOC/workflow/specs/ADMIN-MENU-PROFILE.md)
// ─────────────────────────────────────────────────────────────────────────────

T::run('Teil 4: Profile', static function () use ($catalogList, $tabler, $optionalPages): void {
    $registry = MenuRegistry::create([]);
    $known = $registry->knownCoreIds();
    $locked = $registry->lockedIds();
    $byId = [];
    foreach ($catalogList as $item) {
        $byId[$item->id] = $item;
    }
    $allIds = am_sorted(array_keys($byId));
    $seoItems = ['seo.dashboard', 'seo.analytics', 'seo.audit', 'seo.meta', 'seo.social', 'seo.schema', 'seo.sitemap', 'seo.search-engines', 'seo.technical'];

    $all = static fn (): bool => true;
    $builder = new MenuBuilder($all, $all, $all, true);
    $withoutOptional = new MenuBuilder($all, static fn (string $slug): bool => !isset($optionalPages[$slug]), $all, true);

    $menus = [];
    foreach (ProfileLayouts::PROFILES as $profile) {
        $layout = ProfileLayouts::get($profile);
        $result = LayoutValidator::validate($layout, $known, $locked);
        T::check($result['valid'], $profile . ': Layout gültig', implode('; ', $result['errors']));
        T::same([], $result['warnings'], $profile . ': keine Warnungen des Validators');
        T::check($result['layout'] === $layout, $profile . ': Validator gibt das Profil unverändert zurück');

        $placed = [];
        $display = [];
        foreach ($layout['nodes'] as $node) {
            if ($node['type'] === 'item') {
                $placed[] = $node['id'];
                continue;
            }
            if ($node['type'] !== 'group') {
                continue;
            }
            array_push($placed, ...$node['items']);
            $display[$node['id']] = $node['display'];
            T::check(isset($tabler[$node['icon']]), $profile . '/' . $node['id'] . ': Gruppen-Icon ' . $node['icon'] . ' existiert');
            if ($node['display'] === LayoutValidator::DISPLAY_TILES) {
                $missing = array_values(array_filter($node['items'], static fn (string $id): bool => !isset($byId[$id]) || $byId[$id]->category === '' || $byId[$id]->description === ''));
                T::same([], $missing, $profile . '/' . $node['id'] . ': jede Kachel hat Kategorie und Beschreibung');
            } else {
                T::check(count($node['items']) <= 8 || $node['id'] === 'seo', $profile . '/' . $node['id'] . ': Menügruppe mit höchstens 8 Einträgen (Ausnahme SEO)', count($node['items']) . ' Einträge');
            }
        }
        T::same($allIds, am_sorted($placed), $profile . ': jeder Katalogeintrag ist platziert');
        T::same(count(array_unique($placed)), count($placed), $profile . ': kein Eintrag doppelt platziert');
        T::same(LayoutValidator::DISPLAY_MENU, $display[$layout['fallback_group']] ?? null, $profile . ': Auffanggruppe „' . $layout['fallback_group'] . '“ ist eine Menügruppe');
        T::same(LayoutValidator::DISPLAY_TILES, $display[$layout['root_group']] ?? null, $profile . ': Start-Kachelseite „' . $layout['root_group'] . '“ ist eine Kachelgruppe');
        T::same([], array_values(array_diff(array_keys($layout['labels']), array_keys($known))), $profile . ': Beschriftungen nur für bekannte Einträge');

        $menu = $builder->build($registry, $layout, $profile);
        $menus[$profile] = $menu;
        $positions = am_item_positions($menu);
        $count = 0;
        $wrongRoutes = [];
        foreach ($positions as $id => $group) {
            $node = am_node($menu, $id);
            if ($node === null || $node['route'] !== $byId[$id]->route) {
                $wrongRoutes[] = $id;
            }
        }
        foreach ($menu['sections'] as $section) {
            foreach ($section['nodes'] as $node) {
                $count += $node['kind'] === 'item' ? 1 : count($node['items']);
            }
        }
        T::same($allIds, am_sorted(array_keys($positions)), $profile . ': mit allen Rechten erscheint jeder Katalogeintrag');
        T::same(count($allIds), $count, $profile . ': jeder Eintrag erscheint genau einmal');
        T::same([], $wrongRoutes, $profile . ': jeder Eintrag führt auf seine Katalogroute');
        T::same(null, $menu['plugin_hint'], $profile . ': ohne Plugins kein Plugin-Hinweis');

        // Entscheidungen, die für beide Profile gelten (KLARSTELLUNGEN §7)
        $root = $layout['root_group'];
        $reports = $profile === ProfileLayouts::CLASSIC ? 'health' : 'reports';
        $people = $profile === ProfileLayouts::CLASSIC ? 'users' : 'people';
        T::same(['dashboard', 'system.updates'], array_slice(am_top_ids($menu), 0, 2), $profile . ': Dashboard und Updates sind die ersten Hauptpunkte');
        T::check(($positions['dashboard'] ?? null) === '' && ($positions['system.updates'] ?? null) === '', $profile . ': Dashboard und Updates sind direkte Links (keine Gruppe)');
        T::same('updates', am_node($menu, 'system.updates')['badge'] ?? null, $profile . ': Updates mit Badge');
        T::same('media', $positions['media.downloads'] ?? null, $profile . ': Downloads unter Medien');
        T::same('Medienkategorien', am_node($menu, 'media.categories')['label'] ?? null, $profile . ': Medienkategorien eindeutig beschriftet');
        T::check(!isset($layout['labels']['media.categories']), $profile . ': keine Beschriftungs-Überschreibung für Medienkategorien');
        T::same($seoItems, am_group_items($menu, 'seo'), $profile . ': SEO-Gruppe = genau die 9 SEO-Seiten');
        T::same('privacy_requests', am_node($menu, 'legal.data-requests')['badge'] ?? null, $profile . ': Auskunft & Löschen mit Badge offener Anfragen');
        T::same($root, $positions['legal.data-requests'] ?? null, $profile . ': Auskunft & Löschen auf der Kachelseite „' . $root . '“');
        T::same($root, $positions['ai.settings'] ?? null, $profile . ': KI-Einstellungen als Kachel unter „' . $root . '“');
        T::same($root, $positions['system.multisites'] ?? null, $profile . ': MultiSites als Kachel unter „' . $root . '“');
        T::same($reports, $positions['security.audit'] ?? null, $profile . ': Sicherheits-Audit unter „' . $reports . '“');
        T::same($reports, $positions['system.documentation'] ?? null, $profile . ': Dokumentation als Kachel unter „' . $reports . '“');
        T::same('help', am_node($menu, 'system.documentation')['category'] ?? null, $profile . ': Dokumentation in der Kategorie Hilfe');
        T::same($people, $positions['people.profile'] ?? null, $profile . ': Mein Profil unter „' . $people . '“');
        T::same(true, am_node($menu, 'people.profile')['external'] ?? null, $profile . ': Mein Profil ist als externer Link markiert');
        T::same(MenuBuilder::CONFIGURATION_ROUTE, am_node($menu, $root)['route'] ?? null, $profile . ': Kachelseite „' . $root . '“ unter ' . MenuBuilder::CONFIGURATION_ROUTE);
        T::same(MenuBuilder::CONFIGURATION_ROUTE . '?group=' . $reports, am_node($menu, $reports)['route'] ?? null, $profile . ': Kachelseite „' . $reports . '“ über ?group=');
        T::same(MenuBuilder::CONFIGURATION_ROUTE . '?group=members', am_node($menu, 'members')['route'] ?? null, $profile . ': Mitgliederbereich als Kachelseite');
    }

    $classic = $menus[ProfileLayouts::CLASSIC];
    $classicTop = am_top_ids($classic);
    $classicPositions = am_item_positions($classic);
    T::same(['dashboard', 'system.updates', 'posts', 'media', 'pages', 'content.comments', 'forms', 'ai', 'design', 'plugins', 'seo', 'users', 'members', 'subscriptions', 'tools', 'settings', 'health'],
        $classicTop, 'classic: Hauptpunkte mit allen Modulen');
    T::same(['sep.work', 'sep.design', 'sep.manage'], array_column($classic['sections'], 'id'), 'classic: Abschnitte Arbeit, Gestaltung, Verwaltung');
    T::same(['Arbeit', 'Gestaltung', 'Verwaltung'], array_column($classic['sections'], 'label'), 'classic: Abschnittsbeschriftungen');
    T::same('comments', am_node($classic, 'content.comments')['badge'] ?? null, 'classic: Kommentare als Hauptpunkt mit Badge');
    T::same(array_search('forms', $classicTop, true) + 1, array_search('ai', $classicTop, true), 'classic: KI-Dienste direkt nach Formulare');
    T::same('tools', $classicPositions['seo.redirects'] ?? null, 'classic: Weiterleitungen unter Werkzeuge');
    T::same('tools', $classicPositions['seo.not-found'] ?? null, 'classic: 404-Monitor unter Werkzeuge');
    T::same('Alle Beiträge', am_node($classic, 'content.posts')['label'] ?? null, 'classic: Beschriftung „Alle Beiträge“');

    $classicLean = $withoutOptional->build($registry, ProfileLayouts::get(ProfileLayouts::CLASSIC), ProfileLayouts::CLASSIC);
    T::same(['dashboard', 'system.updates', 'posts', 'media', 'pages', 'content.comments', 'forms', 'design', 'plugins', 'users', 'tools', 'settings', 'health'],
        am_top_ids($classicLean), 'classic ohne optionale Module: 13 Hauptpunkte');
    $leanPositions = am_item_positions($classicLean);
    T::check(!isset($leanPositions['seo.redirects']) && !isset($leanPositions['seo.not-found']) && !isset($leanPositions['ai.settings']),
        'classic ohne optionale Module: Weiterleitungen, 404-Monitor und KI-Einstellungen ausgeblendet');

    $structured = $menus[ProfileLayouts::STRUCTURED];
    $structuredTop = am_top_ids($structured);
    T::same(['dashboard', 'system.updates', 'content', 'media', 'editorial', 'ai', 'structure', 'design', 'seo', 'extend', 'people', 'members', 'subscriptions', 'configuration', 'reports'],
        $structuredTop, 'structured: Hauptpunkte mit allen Modulen');
    T::same(['main'], array_column($structured['sections'], 'id'), 'structured: ein Abschnitt ohne Trenner');
    T::same(['content.pages', 'content.posts', 'content.comments', 'content.knowledgebase', 'content.forms', 'content.form-submissions', 'content.newsletter', 'content.trash'],
        am_group_items($structured, 'content'), 'structured: Inhalt');
    T::same('comments', am_node($structured, 'content.comments')['badge'] ?? null, 'structured: Kommentare mit Badge');
    T::same([], array_values(array_diff(['media.library', 'media.categories', 'media.check', 'media.downloads', 'media.tools'], am_group_items($structured, 'media'))),
        'structured: Medien mit Mediathek, Medienkategorien, Medien-Check, Downloads, Medien-Werkzeuge');
    T::same(['content.editorial-calendar', 'content.changes', 'content.translations'], am_group_items($structured, 'editorial'), 'structured: Redaktion');
    T::same(array_search('editorial', $structuredTop, true) + 1, array_search('ai', $structuredTop, true), 'structured: KI-Dienste direkt nach Redaktion');
    T::same(['design.menus', 'content.post-categories', 'content.post-tags', 'content.hub-sites', 'content.site-tables', 'content.toc', 'seo.redirects', 'seo.not-found'],
        am_group_items($structured, 'structure'), 'structured: Struktur');
    T::same(['people.users', 'people.groups', 'people.roles', 'people.settings', 'people.profile'], am_group_items($structured, 'people'), 'structured: Personen');

    $structuredLean = $withoutOptional->build($registry, ProfileLayouts::get(ProfileLayouts::STRUCTURED), ProfileLayouts::STRUCTURED);
    T::same(['dashboard', 'system.updates', 'content', 'media', 'editorial', 'structure', 'design', 'extend', 'people', 'configuration', 'reports'],
        am_top_ids($structuredLean), 'structured ohne optionale Module: Hauptpunkte');
});

// ─────────────────────────────────────────────────────────────────────────────
// Teil 5: Unit-Tests mit Fakes (keine Datenbank, keine Rechteänderung)
// ─────────────────────────────────────────────────────────────────────────────

T::run('Teil 5a: LayoutValidator', static function (): void {
    $known = ['dashboard' => true, 'people.users' => true, 'a' => true, 'b' => true, 'c' => true];
    $locked = ['dashboard', 'people.users'];
    $validate = static fn (mixed $input): array => LayoutValidator::validate($input, $known, $locked);
    $users = ['type' => 'group', 'id' => 'users', 'label' => 'Benutzer', 'icon' => 'users', 'display' => LayoutValidator::DISPLAY_MENU, 'items' => ['people.users']];
    $layout = static fn (array $nodes, array $extra = []): array => array_replace([
        'format' => LayoutValidator::FORMAT,
        'version' => LayoutValidator::FORMAT_VERSION,
        'base' => ProfileLayouts::CLASSIC,
        'fallback_group' => '',
        'root_group' => '',
        'nodes' => $nodes,
        'labels' => [],
        'icons' => [],
        'hidden' => [],
    ], $extra);

    $formatError = 'Unbekanntes Format. Erwartet wird „365cms-admin-menu“.';
    $versionError = 'Nicht unterstützte Formatversion. Unterstützt wird Version 1.';
    $nodesError = 'Das Feld „nodes“ fehlt oder ist keine Liste.';
    $fatal = [
        'kein Objekt' => ['x', ['Das Layout muss ein JSON-Objekt sein.']],
        'fremdes Format' => [['format' => 'other', 'nodes' => []], [$formatError]],
        'Version 2' => [['version' => 2, 'nodes' => []], [$versionError]],
        'Version als Text' => [['version' => '1', 'nodes' => []], [$versionError]],
        'nodes fehlt' => [['format' => LayoutValidator::FORMAT], [$nodesError]],
        'nodes als Map' => [['nodes' => ['x' => 'a']], [$nodesError]],
        'mehrere Fehler' => [['format' => 'other', 'version' => 0], [$formatError, $versionError, $nodesError]],
    ];
    foreach ($fatal as $name => [$input, $errors]) {
        $result = $validate($input);
        T::check(!$result['valid'] && $result['layout'] === LayoutValidator::emptyLayout(ProfileLayouts::CLASSIC), 'Fehler „' . $name . '“ verwirft das Layout');
        T::same($errors, $result['errors'], 'Fehler „' . $name . '“: Meldung');
    }

    $minimal = $validate(['nodes' => ['dashboard', ['type' => 'group', 'id' => 'users', 'items' => ['people.users']]]]);
    T::check($minimal['valid'] && $minimal['errors'] === [] && $minimal['warnings'] === [], 'Format, Version und Basisprofil sind optional');
    T::same($layout([
        ['type' => 'item', 'id' => 'dashboard'],
        ['type' => 'group', 'id' => 'users', 'label' => 'users', 'icon' => 'folder', 'display' => LayoutValidator::DISPLAY_MENU, 'items' => ['people.users']],
    ]), $minimal['layout'], 'Standardwerte: classic, Gruppen-Beschriftung = ID, Icon „folder“, Anzeige „menu“');

    $limit = 'Das Layout enthält mehr als 200 Elemente.';
    $tooMany = $validate(['base' => ProfileLayouts::STRUCTURED, 'nodes' => array_fill(0, LayoutValidator::MAX_NODES + 1, 'a')]);
    T::check(!$tooMany['valid'] && $tooMany['layout'] === LayoutValidator::emptyLayout(ProfileLayouts::STRUCTURED), 'Mehr als 200 Elemente verwerfen das Layout (Basisprofil bleibt)');
    T::same([$limit], $tooMany['errors'], 'Mehr als 200 Elemente: Meldung');
    $inGroup = $validate(['nodes' => [['type' => 'group', 'id' => 'g', 'items' => array_fill(0, LayoutValidator::MAX_NODES, 'a')]]]);
    T::same([$limit], $inGroup['errors'], 'Gruppeneinträge zählen zur Obergrenze');
    $atLimit = $validate(['nodes' => [['type' => 'group', 'id' => 'g', 'items' => array_fill(0, LayoutValidator::MAX_NODES - 1, 'a')]]]);
    T::check($atLimit['valid'], 'Genau 200 Elemente sind erlaubt', implode('; ', $atLimit['errors']));

    $unknownBase = $validate(['base' => 'x<b>', 'nodes' => ['dashboard', $users]]);
    T::same(['Unbekanntes Basisprofil „xb“ – „classic“ wird verwendet.'], $unknownBase['warnings'], 'Unbekanntes Basisprofil: Warnung ohne Sonderzeichen');
    T::same(ProfileLayouts::CLASSIC, $unknownBase['layout']['base'], 'Unbekanntes Basisprofil: classic');
    T::same(ProfileLayouts::STRUCTURED, $validate(['base' => 'structured', 'nodes' => ['dashboard', 'people.users']])['layout']['base'], 'Basisprofil structured bleibt');

    $items = $validate(['nodes' => [
        'dashboard',
        ['id' => 'a'],
        ['type' => 'item', 'id' => '-bad'],
        ['type' => 'item'],
        42,
        ['type' => 'item', 'id' => 'zzz'],
        ['type' => 'item', 'id' => 'a'],
        ['type' => 'item', 'id' => 'plugin:shop:orders'],
        ['type' => 'widget', 'id' => 'w'],
        ['type' => 'group', 'id' => 'users', 'label' => 'Benutzer', 'icon' => 'users', 'items' => ['people.users', '', 'zzz', ['id' => 'b'], 'a']],
    ]]);
    T::check($items['valid'], 'Warnungen machen das Layout nicht ungültig');
    T::same([
        ['type' => 'item', 'id' => 'dashboard'],
        ['type' => 'item', 'id' => 'a'],
        ['type' => 'item', 'id' => 'plugin:shop:orders'],
        ['type' => 'group', 'id' => 'users', 'label' => 'Benutzer', 'icon' => 'users', 'display' => LayoutValidator::DISPLAY_MENU, 'items' => ['people.users', 'b']],
    ], $items['layout']['nodes'], 'Einträge: Zeichenkette oder Objekt ohne Typ; Plugin-IDs ohne Registry; Ungültiges entfernt');
    T::same([
        'Ungültige Eintrags-ID auf Ebene 1 wurde übersprungen.',
        'Element 5 hat ein ungültiges Format und wurde übersprungen.',
        'Unbekannter Eintrag „zzz“ wurde übersprungen.',
        'Doppelter Eintrag „a“ wurde entfernt.',
        'Unbekannter Elementtyp „widget“ wurde übersprungen.',
        'Ungültige Eintrags-ID in Gruppe „users“ wurde übersprungen.',
    ], $items['warnings'], 'Einträge: Warnungen (jede Meldung einmal)');

    $groups = $validate(['nodes' => [
        ['type' => 'separator', 'label' => " <b>Arbeit</b>\n "],
        'dashboard',
        ['type' => 'group', 'id' => 'g', 'label' => '', 'icon' => 'ti-Star', 'display' => 'grid', 'items' => [
            'a',
            ['type' => 'separator', 'id' => 'x'],
            ['type' => 'group', 'id' => 'inner', 'items' => ['b', ['type' => 'group', 'items' => ['c']]]],
        ]],
        ['type' => 'group', 'id' => 'g', 'label' => str_repeat('Ä', 70), 'icon' => '<svg>', 'display' => 'tiles', 'items' => []],
        ['type' => 'group', 'id' => 'bad id!', 'label' => 'Ohne gültige ID', 'items' => []],
        $users,
    ]]);
    T::same([
        ['type' => 'separator', 'id' => 'sep-1', 'label' => 'Arbeit'],
        ['type' => 'item', 'id' => 'dashboard'],
        ['type' => 'group', 'id' => 'g', 'label' => 'g', 'icon' => 'star', 'display' => LayoutValidator::DISPLAY_MENU, 'items' => ['a', 'b', 'c']],
        ['type' => 'group', 'id' => 'g-2', 'label' => str_repeat('Ä', 60), 'icon' => 'folder', 'display' => LayoutValidator::DISPLAY_TILES, 'items' => []],
        ['type' => 'group', 'id' => 'group-4', 'label' => 'Ohne gültige ID', 'icon' => 'folder', 'display' => LayoutValidator::DISPLAY_MENU, 'items' => []],
        $users,
    ], $groups['layout']['nodes'], 'Gruppen: IDs eindeutig, Beschriftung bereinigt und gekürzt, Icon normalisiert, höchstens 2 Ebenen');
    T::same([
        'Ein Trenner innerhalb der Gruppe „g“ wurde entfernt.',
        'Die verschachtelte Gruppe in „g“ wurde aufgelöst (höchstens 2 Ebenen).',
        'Doppelte Gruppen- bzw. Trenner-ID „g“ wurde umbenannt.',
    ], $groups['warnings'], 'Gruppen: Warnungen');
    $again = $validate($groups['layout']);
    T::check($again['layout'] === $groups['layout'] && $again['warnings'] === [], 'Validator ist idempotent (bereinigtes Layout bleibt unverändert)', T::export($again['warnings']));

    $maps = $validate([
        'nodes' => ['dashboard', $users, 'a'],
        'labels' => ['a' => ' <i>Neu</i> ', 'zzz' => 'Unbekannt', 'plugin:shop:orders' => 'Bestellungen', '-bad' => 'Ungültig', 'b' => '', 'c' => 123],
        'icons' => ['a' => 'ti-Star', 'b' => 'bad icon!', 'plugin:shop:orders' => 'bolt', 'zzz' => 'star', 'c' => ['x']],
        'hidden' => ['b', 'zzz', 'plugin:shop:orders', '-bad', 42, 'dashboard', 'b'],
    ]);
    T::same(['a' => 'Neu', 'plugin:shop:orders' => 'Bestellungen'], $maps['layout']['labels'], 'Beschriftungen: nur bekannte bzw. Plugin-IDs, bereinigt, nicht leer');
    T::same(['a' => 'star', 'plugin:shop:orders' => 'bolt'], $maps['layout']['icons'], 'Icons: nur gültige Icon-Namen für bekannte bzw. Plugin-IDs');
    T::same(['b', 'plugin:shop:orders'], $maps['layout']['hidden'], 'Ausgeblendet: unbekannte und ungültige IDs entfallen still, keine Duplikate');
    T::same(['Der gesperrte Eintrag „dashboard“ kann nicht ausgeblendet werden.'], $maps['warnings'], 'Gesperrter Eintrag lässt sich nicht ausblenden');

    $reinserted = $validate(['nodes' => [
        ['type' => 'separator', 'id' => 'sep.work', 'label' => 'Arbeit'],
        'a',
        ['type' => 'group', 'id' => 'users', 'label' => 'Benutzer', 'icon' => 'users', 'items' => ['b']],
    ]]);
    T::same([
        ['type' => 'separator', 'id' => 'sep.work', 'label' => 'Arbeit'],
        ['type' => 'item', 'id' => 'dashboard'],
        ['type' => 'item', 'id' => 'a'],
        ['type' => 'group', 'id' => 'users', 'label' => 'Benutzer', 'icon' => 'users', 'display' => LayoutValidator::DISPLAY_MENU, 'items' => ['b', 'people.users']],
    ], $reinserted['layout']['nodes'], 'Gesperrte Einträge: Dashboard nach dem ersten Trenner, sonst in die Gruppe des Basisprofils');
    T::same([
        'Der gesperrte Eintrag „dashboard“ wurde wieder eingefügt.',
        'Der gesperrte Eintrag „people.users“ wurde wieder eingefügt.',
    ], $reinserted['warnings'], 'Gesperrte Einträge: Warnungen');
    T::same(
        [['type' => 'item', 'id' => 'dashboard'], ['type' => 'item', 'id' => 'a'], ['type' => 'item', 'id' => 'people.users']],
        $validate(['nodes' => ['a']])['layout']['nodes'],
        'Gesperrte Einträge ohne passende Gruppe: Dashboard an Position 1, Rest ans Ende'
    );
    T::same(
        ['people.users'],
        am_layout_group($validate(['base' => 'structured', 'nodes' => ['dashboard', ['type' => 'group', 'id' => 'people', 'items' => []]]])['layout'], 'people'),
        'Gesperrte Einträge: Gruppe des Basisprofils structured'
    );

    $wrongGroups = $validate([
        'fallback_group' => 'nope',
        'root_group' => 'm',
        'nodes' => ['dashboard', $users, ['type' => 'group', 'id' => 'm', 'items' => ['a']], ['type' => 'group', 'id' => 't', 'display' => 'tiles', 'items' => ['b']]],
    ]);
    T::same(['', ''], [$wrongGroups['layout']['fallback_group'], $wrongGroups['layout']['root_group']], 'Auffanggruppe und Start-Kachelseite müssen existieren');
    T::same([
        'Die Auffanggruppe „nope“ fehlt; nicht platzierte Core-Einträge erscheinen in der Gruppe „Weitere“.',
        'Die Start-Kachelseite „m“ ist keine Kachelgruppe dieses Layouts.',
    ], $wrongGroups['warnings'], 'Auffanggruppe und Start-Kachelseite: Warnungen');
    $rightGroups = $validate(['fallback_group' => 'm', 'root_group' => 't'] + ['nodes' => $wrongGroups['layout']['nodes']]);
    T::same(['m', 't', []], [$rightGroups['layout']['fallback_group'], $rightGroups['layout']['root_group'], $rightGroups['warnings']], 'Gültige Auffanggruppe und Start-Kachelseite bleiben');

    $json = static fn (string $input): array => LayoutValidator::validateJson($input, $known, $locked);
    $invalidJson = ['Die Datei enthält kein gültiges JSON.'];
    $wrapped = $json((string) json_encode(['layout' => ['nodes' => ['dashboard', $users]], 'exported_at' => '2026-11-08']));
    T::check($wrapped['valid'] && $wrapped['warnings'] === [] && am_layout_group($wrapped['layout'], 'users') === ['people.users'], 'JSON-Import mit Hülle {"layout": …}');
    T::same([$nodesError], $json('{"layout":"x"}')['errors'], 'Hülle nur, wenn „layout“ ein Objekt ist');
    T::same(['Die Datei ist größer als 256 KB.'], $json(str_repeat(' ', LayoutValidator::MAX_JSON_BYTES + 1))['errors'], 'JSON-Import größer als 256 KB');
    $exact = '{"nodes":["dashboard","people.users"]}';
    T::check($json($exact . str_repeat(' ', LayoutValidator::MAX_JSON_BYTES - strlen($exact)))['valid'], 'JSON-Import mit genau 256 KB');
    T::same($invalidJson, $json('{"nodes": [')['errors'], 'Ungültiges JSON');
    // json_decode-Tiefe 16 zählt die innerste Ebene mit: höchstens 15 verschachtelte Arrays (Export-Layouts brauchen ≤ 6).
    T::same($invalidJson, $json(str_repeat('[', 16) . str_repeat(']', 16))['errors'], 'JSON über dem Tiefenlimit');
    T::same([$nodesError], $json(str_repeat('[', 15) . str_repeat(']', 15))['errors'], 'JSON innerhalb des Tiefenlimits wird gelesen');

    T::same(
        ['', 'A B', 'Hallo Welt', str_repeat('ß', 60)],
        [LayoutValidator::label(null), LayoutValidator::label("A\x00\x1F B"), LayoutValidator::label('<b>Hallo</b>   Welt'), LayoutValidator::label(str_repeat('ß', 61))],
        'label(): Text ohne Markup und Steuerzeichen, höchstens 60 Zeichen'
    );
    T::same(
        ['home', null, null, null, null, str_repeat('a', 60)],
        [
            LayoutValidator::icon(' TI-Home '), LayoutValidator::icon(['star']), LayoutValidator::icon('ti-'),
            LayoutValidator::icon('star" onmouseover="x'), LayoutValidator::icon(str_repeat('a', 61)), LayoutValidator::icon(str_repeat('a', 60)),
        ],
        'icon(): Tabler-Name ohne Präfix, sonst null'
    );
    T::same([ProfileLayouts::CLASSIC, ProfileLayouts::STRUCTURED], [LayoutValidator::emptyLayout('x')['base'], LayoutValidator::emptyLayout('structured')['base']], 'emptyLayout(): nur bekannte Basisprofile');
});

T::run('Teil 5b: MenuRegistry', static function (): void {
    $catalogRegistry = MenuRegistry::create([]);
    T::same(count(CoreCatalog::items()), count($catalogRegistry->knownCoreIds()), 'Standard: alle Katalogeinträge bekannt');
    T::same(CoreCatalog::lockedIds(), $catalogRegistry->lockedIds(), 'Standard: gesperrte Einträge aus dem Katalog');

    $menus = [
        ['menu_slug' => 'zeta-tool', 'menu_title' => 'Zeta', 'icon_url' => 'ti ti-bolt', 'capability' => 'manage_zeta', 'plugin_slug' => 'zeta'],
        ['menu_slug' => 'alpha-tool', 'menu_title' => '<b>alpha</b> Tool', 'icon_url' => '<img src=x onerror=alert(1)>'],
        ['menu_slug' => 'shop', 'menu_title' => 'Shop &amp; Co', 'icon_url' => '🛒', 'capability' => 'manage_shop', 'plugin_slug' => 'cms-shop', 'children' => [
            ['menu_slug' => 'shop-orders', 'menu_title' => 'Bestellungen'],
            ['menu_slug' => 'shop-settings', 'menu_title' => 'Einstellungen', 'capability' => 'manage_shop_settings'],
            'kaputt',
            ['menu_slug' => '', 'menu_title' => 'Ohne Slug'],
        ]],
        ['menu_slug' => 'ghost', 'menu_title' => 'Ghost', 'hidden' => true],
        ['menu_slug' => '', 'menu_title' => 'Ohne Slug'],
        ['menu_title' => 'Ohne Slug-Feld'],
        'kein-array',
    ];
    $asked = [];
    $isAssigned = static function (array $menu) use (&$asked): bool {
        $asked[] = $menu['menu_slug'];
        if ($menu['menu_slug'] === 'alpha-tool') {
            throw new \RuntimeException('Zuweisung nicht ermittelbar');
        }

        return $menu['menu_slug'] !== 'zeta-tool';
    };
    $registry = MenuRegistry::create($menus, [], $isAssigned, 'https://cms.example', []);

    T::same(['zeta-tool', 'alpha-tool', 'shop'], $asked, 'Site-Zuweisung nur für gültige, nicht versteckte Menüs abgefragt');
    T::same([3, 1], [$registry->pluginTotal(), $registry->pluginHidden()], 'Plugin-Menüs gesamt / der Site nicht zugewiesen');
    T::same(['alpha-tool', 'shop'], array_keys($registry->pluginMenus()), 'Nicht ermittelbare Zuweisung lässt das Menü sichtbar');
    T::same([[], []], [$registry->knownCoreIds(), $registry->lockedIds()], 'Plugin-Einträge sind weder Core-IDs noch gesperrt');
    T::same([null, null], [$registry->get('plugin:zeta-tool'), $registry->get('plugin:ghost')], 'Nicht zugewiesene und versteckte Menüs haben keine Einträge');

    $alpha = $registry->get('plugin:alpha-tool');
    T::same(
        ['alpha Tool', '/admin/plugins/alpha-tool/alpha-tool', [], 'alpha-tool', 'plugin:alpha-tool', 'alpha-tool', 'alpha Tool', ['type' => 'tabler', 'name' => MenuRegistry::DEFAULT_PLUGIN_ICON]],
        [$alpha?->label, $alpha?->route, $alpha?->capabilities, $alpha?->pageSlug, $alpha?->source, $alpha?->pluginSlug, $alpha?->categoryLabel, $alpha?->icon],
        'Einzelseite: Beschriftung ohne Markup, Route, Rechte, Quelle, Kategorie, Standard-Icon statt Markup'
    );
    T::check($alpha !== null && $alpha->isPlugin() && !$alpha->locked && !$alpha->isExternal() && $alpha->file === '', 'Einzelseite ist ein Plugin-Eintrag ohne Seitendatei');

    $shop = $registry->pluginMenus()['shop'] ?? [];
    T::same(
        ['label' => 'Shop & Co', 'icon' => ['type' => 'text', 'text' => '🛒'], 'items' => ['plugin:shop:shop', 'plugin:shop:shop-orders', 'plugin:shop:shop-settings']],
        $shop,
        'Mehrseitiges Menü: Entities dekodiert, Emoji-Icon, „Übersicht“ vorangestellt, ungültige Unterseiten übersprungen'
    );
    $pages = [];
    foreach ($shop['items'] ?? [] as $id) {
        $page = $registry->get($id);
        $pages[$id] = [$page?->label, $page?->route, $page?->capabilities, $page?->pageSlug, $page?->source, $page?->pluginSlug, $page?->categoryLabel];
    }
    T::same([
        'plugin:shop:shop' => ['Übersicht', '/admin/plugins/shop/shop', ['manage_shop'], 'shop', 'plugin:cms-shop', 'shop', 'Shop & Co'],
        'plugin:shop:shop-orders' => ['Bestellungen', '/admin/plugins/shop/shop-orders', ['manage_shop'], 'shop-orders', 'plugin:cms-shop', 'shop', 'Shop & Co'],
        'plugin:shop:shop-settings' => ['Einstellungen', '/admin/plugins/shop/shop-settings', ['manage_shop_settings'], 'shop-settings', 'plugin:cms-shop', 'shop', 'Shop & Co'],
    ], $pages, 'Unterseiten: Capability des Menüs als Standard, eigene Capability hat Vorrang');

    $dedup = MenuRegistry::create([
        ['menu_slug' => 'shop', 'menu_title' => 'Shop A'],
        ['menu_slug' => 'shop', 'menu_title' => 'Shop B'],
        ['menu_slug' => 'Mein Plugin/ä', 'menu_title' => 'Mein Plugin', 'plugin_slug' => 'mein-plugin', 'children' => [
            ['menu_slug' => 'a b', 'menu_title' => 'A B'],
            ['menu_slug' => 'a-b', 'menu_title' => ''],
        ]],
        ['menu_slug' => '§§', 'menu_title' => ''],
    ], [
        'shop' => ['classic' => 'tools', 'structured' => 'bad id!', 'custom' => 'g1', 'other' => 'x'],
        '' => ['classic' => 'tools'],
        'x' => 'not-array',
        'news' => ['classic' => 7],
    ], null, '', []);
    $hash = 'x' . substr(sha1('§§'), 0, 10);
    T::same(['shop', 'shop-2', 'Mein-Plugin', $hash], array_keys($dedup->pluginMenus()), 'Menü-Schlüssel: doppelte Slugs nummeriert, Sonderzeichen ersetzt, sonst Hash');
    T::same('Shop B', $dedup->get('plugin:shop-2')?->label, 'Zweites Menü mit gleichem Slug bleibt erhalten');
    $mine = $dedup->pluginMenus()['Mein-Plugin']['items'] ?? [];
    T::same(['plugin:Mein-Plugin:Mein-Plugin', 'plugin:Mein-Plugin:a-b', 'plugin:Mein-Plugin:a-b-2'], $mine, 'Unterseiten-Schlüssel eindeutig');
    $prefix = '/admin/plugins/Mein%20Plugin%2F%C3%A4/';
    T::same(
        [[$prefix . 'Mein%20Plugin%2F%C3%A4', 'Übersicht'], [$prefix . 'a%20b', 'A B'], [$prefix . 'a-b', 'a-b']],
        array_map(static fn (string $id): array => [$dedup->get($id)?->route, $dedup->get($id)?->label], $mine),
        'Routen mit rawurlencode, leere Beschriftung → Slug'
    );
    T::same(['plugin:mein-plugin', 'Mein-Plugin'], [$dedup->get('plugin:Mein-Plugin:a-b')?->source, $dedup->get('plugin:Mein-Plugin:a-b')?->pluginSlug], 'Quelle aus plugin_slug, Kategorie aus dem Menü-Schlüssel');
    T::same(['§§', '/admin/plugins/%C2%A7%C2%A7/%C2%A7%C2%A7'], [$dedup->get('plugin:' . $hash)?->label, $dedup->get('plugin:' . $hash)?->route], 'Leerer Titel → Menü-Slug');
    T::same(
        ['tools', null, 'g1', null, 'tools', 'g1', null, null],
        [
            $dedup->preferredParent('shop', 'classic'), $dedup->preferredParent('shop', 'structured'),
            $dedup->preferredParent('shop', 'custom'), $dedup->preferredParent('shop', 'other'),
            $dedup->preferredParent('shop-2', 'classic'), $dedup->preferredParent('shop-2', 'custom'),
            $dedup->preferredParent('', 'classic'), $dedup->preferredParent('news', 'classic'),
        ],
        'Wunschplätze: nur gültige Profile und Gruppen-IDs, auf nummerierte Menüs übertragen'
    );

    T::same(
        ['shop', 'news', null, null],
        [MenuRegistry::pluginMenuKeyOf('plugin:shop:shop-orders'), MenuRegistry::pluginMenuKeyOf('plugin:news'), MenuRegistry::pluginMenuKeyOf('plugin:'), MenuRegistry::pluginMenuKeyOf('content.posts')],
        'pluginMenuKeyOf()'
    );
    T::same(['x' . substr(sha1('_x'), 0, 10) . '-_x', 70], [MenuRegistry::idFragment('_x'), strlen(MenuRegistry::idFragment(str_repeat('a', 100)))], 'idFragment(): Hash-Präfix, höchstens 70 Zeichen');

    $svg = '<svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>';
    $puzzle = ['type' => 'tabler', 'name' => MenuRegistry::DEFAULT_PLUGIN_ICON];
    $iconCases = [
        ['ti ti-bolt', ['type' => 'tabler', 'name' => 'bolt']],
        ['  ti-bolt ', ['type' => 'tabler', 'name' => 'bolt']],
        [$svg, ['type' => 'image', 'src' => 'data:image/svg+xml;base64,' . base64_encode($svg)]],
        ['<svg' . str_repeat(' ', 20000) . '>', $puzzle],
        ['<i class="ti ti-Star"></i>', ['type' => 'tabler', 'name' => 'star']],
        ['<span class="dashicons dashicons-admin-generic"></span>', $puzzle],
        ['<img src=x onerror=alert(1)>', $puzzle],
        ['data:image/png;base64,iVBORw0KGgo=', ['type' => 'image', 'src' => 'data:image/png;base64,iVBORw0KGgo=']],
        ['data:text/html;base64,PHNjcmlwdD4=', $puzzle],
        ['data:image/png;base64,abc" onerror="x', $puzzle],
        ['/assets/plugin.svg', ['type' => 'image', 'src' => '/assets/plugin.svg']],
        ['//evil.example/x.svg', $puzzle],
        ['https://cms.example/icon.png', ['type' => 'image', 'src' => 'https://cms.example/icon.png']],
        ['https://cms.example.evil/icon.png', $puzzle],
        ['https://evil.example/icon.png', $puzzle],
        ['javascript:alert(1)', $puzzle],
        ['🛒', ['type' => 'text', 'text' => '🛒']],
        ['AB', ['type' => 'text', 'text' => 'AB']],
        ['A&B', $puzzle],
        ['abcde', $puzzle],
        ['', $puzzle],
    ];
    foreach ($iconCases as [$input, $expected]) {
        T::same($expected, MenuRegistry::pluginIcon($input, 'https://cms.example'), 'pluginIcon(' . T::export(mb_substr($input, 0, 40)) . ')');
    }

    $fingerprint = static fn (array $list, array $preferred = [], ?callable $assigned = null): string => MenuRegistry::create($list, $preferred, $assigned, '', [])->pluginFingerprint();
    $base = [
        ['menu_slug' => 'shop', 'menu_title' => 'Shop', 'capability' => 'manage_shop', 'children' => [['menu_slug' => 'shop-orders', 'menu_title' => 'Bestellungen']]],
        ['menu_slug' => 'news', 'menu_title' => 'News'],
    ];
    $reference = $fingerprint($base);
    $capability = $base;
    $capability[0]['children'][0]['capability'] = 'manage_orders';
    $label = $base;
    $label[1]['menu_title'] = 'Neuigkeiten';
    T::same($reference, $fingerprint($base), 'Fingerprint ist stabil');
    T::check($fingerprint($capability) !== $reference, 'Fingerprint ändert sich mit einer Capability');
    T::check($fingerprint($label) !== $reference, 'Fingerprint ändert sich mit einer Beschriftung');
    T::check($fingerprint($base, ['news' => ['classic' => 'tools']]) !== $reference, 'Fingerprint ändert sich mit einem Wunschplatz');
    T::check($fingerprint($base, [], static fn (array $menu): bool => $menu['menu_slug'] !== 'news') !== $reference, 'Fingerprint ändert sich mit der Site-Zuweisung');
});

T::run('Teil 5c: MenuBuilder', static function (): void {
    $core = [
        new AdminMenuItem(id: 'dashboard', label: 'Dashboard', route: '/admin', locked: true),
        new AdminMenuItem(id: 'f.alpha', label: 'Alpha', route: '/admin/alpha', capabilities: ['cap_a'], file: 'admin/alpha.php', pageSlug: 'alpha'),
        new AdminMenuItem(id: 'f.beta', label: 'Beta', route: '/admin/beta', capabilities: ['cap_a', 'cap_b'], file: 'admin/beta.php', pageSlug: 'beta'),
        new AdminMenuItem(id: 'f.gamma', label: 'Gamma', route: '/admin/gamma', capabilities: ['cap_c']),
        new AdminMenuItem(id: 'f.module', label: 'Modulseite', route: '/admin/module-page', pageSlug: 'module-page'),
        new AdminMenuItem(id: 'f.market', label: 'Marketplace', route: '/admin/marketplace', requiresMarketplace: true),
        new AdminMenuItem(id: 'f.reserved', label: 'Reserviert', route: '/admin/reserved', file: 'admin/reserved.php', pageSlug: 'reserved'),
        new AdminMenuItem(id: 'f.link', label: 'Mein Profil', route: '/member/profile', type: AdminMenuItem::TYPE_LINK),
        new AdminMenuItem(id: 'f.tile', label: 'Kachel-Eintrag', route: '/admin/tile', category: 'general', tileLabel: 'Kachel', description: 'Beschreibung', categoryLabel: 'Allgemein'),
        new AdminMenuItem(id: 'f.orphan', label: 'Waise', route: '/admin/orphan'),
        new AdminMenuItem(id: 'f.locked', label: 'Gesperrt', route: '/admin/locked', capabilities: ['cap_locked'], locked: true),
    ];
    $plugins = [
        ['menu_slug' => 'zeta-tool', 'menu_title' => 'Zeta Tool'],
        ['menu_slug' => 'alpha-tool', 'menu_title' => 'Alpha Tool'],
        ['menu_slug' => 'news', 'menu_title' => 'News'],
        ['menu_slug' => 'wiki', 'menu_title' => 'Wiki'],
        ['menu_slug' => 'shop', 'menu_title' => 'Shop', 'capability' => 'manage_shop', 'children' => [
            ['menu_slug' => 'shop-orders', 'menu_title' => 'Bestellungen'],
            ['menu_slug' => 'shop-settings', 'menu_title' => 'Einstellungen', 'capability' => 'manage_shop_settings'],
        ]],
        ['menu_slug' => 'crm', 'menu_title' => 'CRM', 'children' => [
            ['menu_slug' => 'crm', 'menu_title' => 'Kontakte'],
            ['menu_slug' => 'crm-deals', 'menu_title' => 'Deals'],
        ]],
        ['menu_slug' => 'blog', 'menu_title' => 'Blog', 'children' => [['menu_slug' => 'blog-posts', 'menu_title' => 'Artikel']]],
    ];
    $preferred = [
        'zeta-tool' => ['classic' => 'nope'],
        'news' => ['custom' => 'nope', 'classic' => 'g1'],
        'wiki' => ['custom' => 'rep'],
        'blog' => ['classic' => 'g1'],
    ];
    $registry = MenuRegistry::create($plugins, $preferred, null, '', $core);
    // Fakes: Rechte (eine Capability genügt), Module (module-page und – wirkungslos für Plugins – zeta-tool aus),
    // Dateien (admin/reserved.php fehlt), Marketplace.
    $builder = static fn (array $granted, bool $marketplace = false, ?callable $translate = null): MenuBuilder => new MenuBuilder(
        static fn (array $capabilities): bool => array_intersect($capabilities, $granted) !== [],
        static fn (string $page): bool => !in_array($page, ['module-page', 'zeta-tool'], true),
        static fn (string $file): bool => $file !== 'admin/reserved.php',
        $marketplace,
        $translate,
    );
    $main = $builder(['cap_b', 'cap_locked', 'manage_shop']);

    $visible = array_keys(array_filter($registry->items(), static fn (AdminMenuItem $item): bool => $main->isVisible($item)));
    T::same([
        'dashboard', 'f.beta', 'f.link', 'f.tile', 'f.orphan', 'f.locked',
        'plugin:zeta-tool', 'plugin:alpha-tool', 'plugin:news', 'plugin:wiki', 'plugin:shop:shop', 'plugin:shop:shop-orders',
        'plugin:crm:crm', 'plugin:crm:crm-deals', 'plugin:blog:blog', 'plugin:blog:blog-posts',
    ], $visible, 'isVisible(): Datei, Modul (nur Core-Seiten), Marketplace, eine der Capabilities');
    T::check($builder([], true)->isVisible($registry->get('f.market')), 'isVisible(): Marketplace-Eintrag bei aktivem Marketplace');

    $reason = static fn (string $id): ?string => $main->hiddenReason($registry->get($id));
    T::same(
        [MenuBuilder::HIDDEN_FILE, MenuBuilder::HIDDEN_MODULE, MenuBuilder::HIDDEN_MARKETPLACE, MenuBuilder::HIDDEN_CAPABILITY, null, null, MenuBuilder::HIDDEN_CAPABILITY, null],
        [$reason('f.reserved'), $reason('f.module'), $reason('f.market'), $reason('f.alpha'), $reason('f.beta'), $reason('plugin:zeta-tool'), $reason('plugin:shop:shop-settings'), $reason('f.link')],
        'hiddenReason(): Grund je Eintrag; Modulschalter gilt nicht für Plugin-Seiten'
    );
    $failsAll = ['id' => 'f.all', 'label' => 'Alles', 'route' => '/admin/all', 'capabilities' => ['cap_x'], 'file' => 'admin/reserved.php', 'pageSlug' => 'module-page', 'requiresMarketplace' => true];
    $reasons = [];
    foreach ([[], ['file' => ''], ['pageSlug' => ''], ['requiresMarketplace' => false], ['capabilities' => ['cap_x', 'cap_b']]] as $fix) {
        $failsAll = array_replace($failsAll, $fix);
        $reasons[] = $main->hiddenReason(new AdminMenuItem(...$failsAll));
    }
    T::same([MenuBuilder::HIDDEN_FILE, MenuBuilder::HIDDEN_MODULE, MenuBuilder::HIDDEN_MARKETPLACE, MenuBuilder::HIDDEN_CAPABILITY, null], $reasons, 'hiddenReason(): Reihenfolge Datei → Modul → Marketplace → Capability');

    $rawLayout = [
        'base' => ProfileLayouts::CLASSIC,
        'fallback_group' => 'plugins',
        'root_group' => 'cfg',
        'nodes' => [
            ['type' => 'item', 'id' => 'dashboard'],
            ['type' => 'group', 'id' => 'g1', 'label' => 'Gruppe 1', 'icon' => 'folder', 'items' => ['f.alpha', 'f.beta', 'f.module', 'f.market', 'f.reserved', 'f.link']],
            ['type' => 'group', 'id' => 'plugins', 'label' => 'Plugins', 'icon' => 'plug', 'items' => []],
            ['type' => 'group', 'id' => 'g2', 'label' => 'Gruppe 2', 'icon' => 'folder', 'items' => ['f.tile', 'plugin:shop:shop-orders']],
            ['type' => 'separator', 'id' => 's2', 'label' => 'Verwaltung'],
            ['type' => 'group', 'id' => 'cfg', 'label' => 'Konfiguration', 'icon' => 'settings', 'display' => LayoutValidator::DISPLAY_TILES, 'items' => ['f.locked']],
            ['type' => 'group', 'id' => 'rep', 'label' => 'Berichte', 'icon' => 'report', 'display' => LayoutValidator::DISPLAY_TILES, 'items' => ['plugin:wiki']],
            ['type' => 'separator', 'id' => 's3', 'label' => ''],
            ['type' => 'group', 'id' => 'g3', 'label' => 'Gruppe 3', 'icon' => 'folder', 'items' => ['f.gamma']],
        ],
        'labels' => ['f.beta' => 'Beta (eigen)'],
        'icons' => ['f.beta' => 'star'],
    ];
    $validate = static fn (array $input, ?MenuRegistry $for = null): array => LayoutValidator::validate($input, ($for ?? $registry)->knownCoreIds(), ($for ?? $registry)->lockedIds());
    $validated = $validate($rawLayout);
    T::check($validated['valid'] && $validated['warnings'] === [], 'Test-Layout ist gültig', implode('; ', [...$validated['errors'], ...$validated['warnings']]));
    $layout = $validated['layout'];
    $layout['hidden'][] = 'f.locked'; // gespeichertes Layout mit ausgeblendetem gesperrten Eintrag (Validator verhindert das sonst)

    $menu = $main->build($registry, $layout, ProfileLayouts::CUSTOM);
    T::same(['custom', 'classic', 'cfg', 'plugins'], [$menu['profile'], $menu['base'], $menu['root_group'], $menu['fallback_group']], 'Kopfdaten der Builder-Ausgabe');
    $activePlugins = ['plugin:alpha-tool', 'plugin.blog', 'plugin.crm', 'plugin:news', 'plugin:zeta-tool'];
    T::same([['main', '', true], ['s2', 'Verwaltung', false], [MenuBuilder::ACTIVE_PLUGINS_SECTION_ID, 'Aktive Plugins', false]], array_map(static fn (array $s): array => [$s['id'], $s['label'], $s['plain']], $menu['sections']), 'Abschnitte; leerer Abschnitt s3 entfällt; „Aktive Plugins“ am Ende');
    T::same(['dashboard', 'g1', 'plugins', 'g2', 'cfg', 'rep', ...$activePlugins], am_top_ids($menu), 'Hauptpunkte: leere Gruppe entfällt, Plugins nur im letzten Abschnitt');
    T::same($activePlugins, am_active_plugins($menu), '„Aktive Plugins“: Einzelseite als Link, mehrseitiges Plugin als Gruppe, gemeinsam alphabetisch');
    T::same(['f.beta', 'f.link'], am_group_items($menu, 'g1'), 'g1: unsichtbare Einträge entfallen; Wunschplätze (news, blog → g1) ohne Wirkung');
    T::same(['f.orphan'], am_group_items($menu, 'plugins'), 'Auffanggruppe: nur nicht platzierte Core-Einträge');
    T::same(['plugin:crm:crm', 'plugin:crm:crm-deals'], am_group_items($menu, 'plugin.crm'), 'Mehrseitiges Plugin ohne Platz → eigene Gruppe');
    T::same(['f.tile', 'plugin:shop:shop-orders', 'plugin:shop:shop'], am_group_items($menu, 'g2'), 'Plugin-Seiten folgen einer platzierten Seite desselben Plugins; Rechte gelten weiter');
    T::same(['f.locked'], am_group_items($menu, 'cfg'), 'Gesperrter Eintrag bleibt trotz „hidden“ sichtbar');
    T::same(['plugin:wiki'], am_group_items($menu, 'rep'), 'Im Layout platzierte Plugin-Seite bleibt an ihrem Platz');
    T::same(null, $menu['plugin_hint'], 'Kein Plugin-Hinweis, solange Plugin-Einträge sichtbar sind');

    $node = static fn (array $tree, string $id, string $key): mixed => am_node($tree, $id)[$key] ?? null;
    T::same(['CRM', true, LayoutValidator::DISPLAY_MENU, ''], [$node($menu, 'plugin.crm', 'label'), $node($menu, 'plugin.crm', 'auto'), $node($menu, 'plugin.crm', 'display'), $node($menu, 'plugin.crm', 'route')], 'Auto-Gruppe: Plugin-Titel, automatisch, Menü ohne Route');
    T::same([MenuBuilder::CONFIGURATION_ROUTE, MenuBuilder::CONFIGURATION_ROUTE . '?group=rep', ''], [$node($menu, 'cfg', 'route'), $node($menu, 'rep', 'route'), $node($menu, 'g1', 'route')], 'Routen: Start-Kachelseite, weitere Kachelseite, Menügruppe');
    T::same(['Gruppe 1', false], [$node($menu, 'g1', 'label'), $node($menu, 'g1', 'auto')], 'custom: Gruppen-Beschriftung wörtlich');
    T::same([
        'kind' => 'item', 'id' => 'f.beta', 'label' => 'Beta (eigen)', 'tile_label' => 'Beta (eigen)', 'description' => '',
        'route' => '/admin/beta', 'icon' => ['type' => 'tabler', 'name' => 'star'], 'badge' => '', 'page' => 'beta',
        'category' => 'other', 'category_label' => 'Weitere', 'external' => false, 'plugin' => false, 'locked' => false,
    ], am_node($menu, 'f.beta'), 'Eintrag: eigene Beschriftung und eigenes Icon; ohne Kategorie „Weitere“');
    T::same(['Kachel-Eintrag', 'Kachel', 'Beschreibung', 'general', 'Allgemein'], [$node($menu, 'f.tile', 'label'), $node($menu, 'f.tile', 'tile_label'), $node($menu, 'f.tile', 'description'), $node($menu, 'f.tile', 'category'), $node($menu, 'f.tile', 'category_label')], 'Kachel: eigene Kachel-Beschriftung, Beschreibung, Kategorie');
    T::same([true, '/member/profile', ''], [$node($menu, 'f.link', 'external'), $node($menu, 'f.link', 'route'), $node($menu, 'f.link', 'page')], 'Externer Link');
    T::same(['Übersicht', 'plugin.shop', 'Shop', '', true, false], [$node($menu, 'plugin:shop:shop', 'label'), $node($menu, 'plugin:shop:shop', 'category'), $node($menu, 'plugin:shop:shop', 'category_label'), $node($menu, 'plugin:shop:shop', 'description'), $node($menu, 'plugin:shop:shop', 'plugin'), $node($menu, 'plugin:shop:shop', 'locked')], 'Plugin-Eintrag: Kategorie je Plugin, keine Beschreibung');
    T::same([true, true], [$node($menu, 'dashboard', 'locked'), $node($menu, 'f.locked', 'locked')], 'Gesperrte Einträge sind markiert');

    $withoutLockedCap = $builder(['cap_b', 'manage_shop'])->build($registry, $layout, ProfileLayouts::CUSTOM);
    T::same(['dashboard', 'g1', 'plugins', 'g2', 'rep', ...$activePlugins], am_top_ids($withoutLockedCap), 'Gesperrte Einträge brauchen ihre Capability; leere Kachelgruppe entfällt');

    $hiddenLayout = $layout;
    $hiddenLayout['hidden'] = ['f.beta', 'f.orphan', 'plugin:alpha-tool', 'plugin:crm:crm-deals'];
    $hiddenMenu = $main->build($registry, $hiddenLayout, ProfileLayouts::CUSTOM);
    T::same(['f.link'], am_group_items($hiddenMenu, 'g1'), 'Ausgeblendeter Eintrag entfällt in seiner Gruppe');
    T::same([['plugin.blog', 'plugin.crm', 'plugin:news', 'plugin:zeta-tool'], false], [am_active_plugins($hiddenMenu), in_array('plugins', am_top_ids($hiddenMenu), true)], 'Ausgeblendete, nicht platzierte Einträge werden nicht einsortiert; leere Auffanggruppe entfällt');
    T::same(['plugin:crm:crm'], am_group_items($hiddenMenu, 'plugin.crm'), 'Ausgeblendete Plugin-Unterseite entfällt');

    $noFallback = $rawLayout;
    $noFallback['fallback_group'] = '';
    $noFallback['nodes'] = array_values(array_filter($rawLayout['nodes'], static fn (array $n): bool => ($n['id'] ?? '') !== 'plugins'));
    $noFallbackMenu = $main->build($registry, $validate($noFallback)['layout'], ProfileLayouts::CUSTOM);
    T::same(['dashboard', 'g1', 'g2', 'cfg', 'rep', 'more', ...$activePlugins], am_top_ids($noFallbackMenu), 'Ohne Auffanggruppe: „Weitere“ am Ende des Layouts, danach „Aktive Plugins“');
    T::same(['main', 's2', 's3', MenuBuilder::ACTIVE_PLUGINS_SECTION_ID], array_column($noFallbackMenu['sections'], 'id'), 'Ohne Auffanggruppe: „Weitere“ im letzten Layout-Abschnitt');
    T::same([['f.orphan'], 'Weitere'], [am_group_items($noFallbackMenu, 'more'), $node($noFallbackMenu, 'more', 'label')], 'Ohne Auffanggruppe: Inhalt von „Weitere“');

    $withAuto = $rawLayout;
    $withAuto['nodes'][] = ['type' => 'group', 'id' => 'plugin.crm', 'label' => 'Kunden', 'icon' => 'users', 'items' => []];
    $withAutoMenu = $main->build($registry, $validate($withAuto)['layout'], ProfileLayouts::CUSTOM);
    T::same([['plugin:crm:crm', 'plugin:crm:crm-deals'], 'Kunden', false, 1], [am_group_items($withAutoMenu, 'plugin.crm'), $node($withAutoMenu, 'plugin.crm', 'label'), $node($withAutoMenu, 'plugin.crm', 'auto'), count(array_keys(am_top_ids($withAutoMenu), 'plugin.crm', true))], 'Vorhandene Gruppe plugin.<menü> nimmt die Plugin-Seiten auf (keine zweite Auto-Gruppe)');

    $hintLayout = $rawLayout;
    $hintLayout['nodes'][3]['items'] = ['f.tile', 'f.orphan'];
    $hintMenuFor = static fn (MenuRegistry $for, ?MenuBuilder $with = null): array => ($with ?? $main)->build($for, $validate($hintLayout, $for)['layout'], ProfileLayouts::CUSTOM);
    $unassigned = $hintMenuFor(MenuRegistry::create($plugins, $preferred, static fn (array $menu): bool => false, '', $core));
    T::same([['section' => MenuBuilder::ACTIVE_PLUGINS_SECTION_ID, 'hidden' => 7], [], false], [$unassigned['plugin_hint'], am_active_plugins($unassigned), in_array('plugins', am_top_ids($unassigned), true)], 'Plugin-Hinweis: kein Plugin der Site zugewiesen → leerer Abschnitt „Aktive Plugins“ bleibt mit Hinweis, leere Auffanggruppe entfällt');
    $noPlugins = $hintMenuFor(MenuRegistry::create([], [], null, '', $core));
    T::same([null, null], [$noPlugins['plugin_hint'], am_active_plugins($noPlugins)], 'Ohne Plugins: kein Hinweis, kein Abschnitt „Aktive Plugins“');
    $partly = $hintMenuFor(MenuRegistry::create($plugins, $preferred, static fn (array $menu): bool => $menu['menu_slug'] !== 'zeta-tool', '', $core));
    T::same(null, $partly['plugin_hint'], 'Kein Hinweis, solange ein Plugin-Eintrag sichtbar ist');
    $capOnly = $hintMenuFor(MenuRegistry::create([$plugins[4]], [], null, '', $core), $builder([]));
    T::same([null, null], [$capOnly['plugin_hint'], am_active_plugins($capOnly)], 'Kein Hinweis, wenn Plugin-Einträge nur an Rechten scheitern');

    $asked = [];
    $translations = [
        'admin_menu.classic.group.g1' => 'Erste Gruppe',
        'admin_menu.classic.separator.s2' => 'Administration',
        'admin_menu.classic.label.f.beta' => 'Beta (übersetzt)',
        'admin_menu.item.f.link' => 'My profile',
        'admin_menu.item.f.orphan' => '',
        'admin_menu.tile.f.tile' => 'Tile',
        'admin_menu.description.f.tile' => 'Description',
        'admin_menu.category.general' => 'General',
        'admin_menu.category.other' => 'Other',
        'admin_menu.item.plugin:news' => 'Nachrichten',
        'admin_menu.section.active_plugins' => 'Active plugins',
    ];
    $translate = static function (string $key, string $fallback) use (&$asked, $translations): string {
        $asked[] = $key;

        return $translations[$key] ?? $key; // unbekannt → Schlüssel → Fallback
    };
    $translated = $builder(['cap_b', 'cap_locked', 'manage_shop'], false, $translate);
    $classicMenu = $translated->build($registry, $layout, ProfileLayouts::CLASSIC);
    T::same(['main' => '', 's2' => 'Administration', MenuBuilder::ACTIVE_PLUGINS_SECTION_ID => 'Active plugins'], array_column($classicMenu['sections'], 'label', 'id'), 'Mitgeliefertes Profil: Trenner und „Aktive Plugins“ übersetzt');
    T::same(
        ['Erste Gruppe', 'Plugins', 'CRM', 'Beta (übersetzt)', 'Beta (übersetzt)', 'Other', 'My profile', 'Waise', 'Dashboard', 'News'],
        [
            $node($classicMenu, 'g1', 'label'), $node($classicMenu, 'plugins', 'label'), $node($classicMenu, 'plugin.crm', 'label'),
            $node($classicMenu, 'f.beta', 'label'), $node($classicMenu, 'f.beta', 'tile_label'), $node($classicMenu, 'f.beta', 'category_label'),
            $node($classicMenu, 'f.link', 'label'), $node($classicMenu, 'f.orphan', 'label'), $node($classicMenu, 'dashboard', 'label'), $node($classicMenu, 'plugin:news', 'label'),
        ],
        'Mitgeliefertes Profil: Gruppen und Überschreibungen übersetzt; leere Übersetzung oder Schlüssel → Fallback; Plugin-Texte nie'
    );
    T::same(['Kachel-Eintrag', 'Tile', 'Description', 'General'], [$node($classicMenu, 'f.tile', 'label'), $node($classicMenu, 'f.tile', 'tile_label'), $node($classicMenu, 'f.tile', 'description'), $node($classicMenu, 'f.tile', 'category_label')], 'Kachel-Texte übersetzt');
    T::same([['f.orphan'], $activePlugins], [am_group_items($classicMenu, 'plugins'), am_active_plugins($classicMenu)], 'classic: Plugin-Seiten ebenfalls unter „Aktive Plugins“, nicht in der Auffanggruppe');
    T::same([], array_values(array_filter($asked, static fn (string $key): bool => str_contains($key, 'plugin:'))), 'Für Plugin-Einträge wird keine Übersetzung abgefragt');
    $asked = [];
    $customMenu = $translated->build($registry, $layout, ProfileLayouts::CUSTOM);
    T::same(
        ['Gruppe 1', 'Verwaltung', 'Beta (eigen)', 'Other', 'My profile'],
        [$node($customMenu, 'g1', 'label'), array_column($customMenu['sections'], 'label', 'id')['s2'] ?? null, $node($customMenu, 'f.beta', 'label'), $node($customMenu, 'f.beta', 'category_label'), $node($customMenu, 'f.link', 'label')],
        'Eigenes Layout: Gruppen, Trenner und Überschreibungen wörtlich, Core-Texte übersetzt'
    );
    T::same([], array_values(preg_grep('/^admin_menu\.(classic|structured|custom)\./', $asked)), 'Eigenes Layout fragt keine Profil-Schlüssel ab');

    $catalog = MenuRegistry::create([]);
    $everything = new MenuBuilder(static fn (array $c): bool => true, static fn (string $p): bool => true, static fn (string $f): bool => true, true);
    $classic = ProfileLayouts::get(ProfileLayouts::CLASSIC);
    $tools = am_layout_group($classic, 'tools');
    $pluginGroup = am_layout_group($classic, 'plugins');
    $without = static fn (callable $keep): array => array_replace($classic, ['nodes' => array_values(array_filter(array_map($keep, $classic['nodes'])))]);
    $noTrash = $everything->build($catalog, $without(static fn (array $n): array => ($n['id'] === 'tools') ? array_replace($n, ['items' => array_values(array_diff($n['items'], ['content.trash']))]) : $n), ProfileLayouts::CLASSIC);
    T::same([...array_values(array_diff($tools, ['content.trash'])), 'content.trash'], am_group_items($noTrash, 'tools'), 'Echter Katalog: fehlender Eintrag kommt ans Ende seiner Basisgruppe');
    $noTools = $everything->build($catalog, $without(static fn (array $n): ?array => $n['id'] === 'tools' ? null : $n), ProfileLayouts::CLASSIC);
    $fallbackItems = am_group_items($noTools, 'plugins');
    T::check(array_slice($fallbackItems, 0, count($pluginGroup)) === $pluginGroup && am_sorted($fallbackItems) === am_sorted([...$pluginGroup, ...$tools]), 'Echter Katalog: Einträge einer fehlenden Gruppe landen in der Auffanggruppe', T::export($fallbackItems));
    $noComments = $everything->build($catalog, $without(static fn (array $n): ?array => $n['id'] === 'content.comments' ? null : $n), ProfileLayouts::CLASSIC);
    T::same([...$pluginGroup, 'content.comments'], am_group_items($noComments, 'plugins'), 'Echter Katalog: fehlender Hauptpunkt landet in der Auffanggruppe');

    T::same(
        ['/admin/configuration', '/admin/configuration?group=health', '/admin/configuration?group=x', '/admin/configuration?group=a%20b%2Fc'],
        [MenuBuilder::tilesRoute('settings', 'settings'), MenuBuilder::tilesRoute('health', 'settings'), MenuBuilder::tilesRoute('x', ''), MenuBuilder::tilesRoute('a b/c', 'settings')],
        'tilesRoute()'
    );

    $sortRegistry = MenuRegistry::create([
        ['menu_slug' => 't10', 'menu_title' => 'Tool 10'],
        ['menu_slug' => 'zoo', 'menu_title' => 'Zoo', 'children' => [['menu_slug' => 'zoo-animals', 'menu_title' => 'Tiere']]],
        ['menu_slug' => 't2', 'menu_title' => 'tool 2'],
        ['menu_slug' => 'beta-b', 'menu_title' => 'Beta'],
        ['menu_slug' => 'alpha', 'menu_title' => 'Alpha'],
        ['menu_slug' => 'ant', 'menu_title' => 'Ant', 'children' => [['menu_slug' => 'ant-hills', 'menu_title' => 'Hügel']]],
        ['menu_slug' => 'beta-a', 'menu_title' => 'Beta'],
    ], [], null, '', [$core[0], $core[9]]);
    $sortMenu = $main->build($sortRegistry, $validate([
        'fallback_group' => 'plugins',
        'nodes' => [
            ['type' => 'separator', 'id' => 'top', 'label' => 'Start'],
            'dashboard',
            ['type' => 'group', 'id' => 'plugins', 'label' => 'Plugins', 'icon' => 'plug', 'items' => []],
            ['type' => 'group', 'id' => 'after', 'label' => 'Danach', 'icon' => 'folder', 'items' => ['f.orphan']],
        ],
    ], $sortRegistry)['layout'], ProfileLayouts::CUSTOM);
    T::same([['top' => 'Start', MenuBuilder::ACTIVE_PLUGINS_SECTION_ID => 'Aktive Plugins'], ['dashboard', 'after']], [array_column($sortMenu['sections'], 'label', 'id'), array_column($sortMenu['sections'][0]['nodes'], 'id')], 'Leerer Hauptabschnitt vor erstem Trenner entfällt; leere Auffanggruppe entfällt');
    T::same(['plugin:alpha', 'plugin.ant', 'plugin:beta-a', 'plugin:beta-b', 'plugin:t2', 'plugin:t10', 'plugin.zoo'], am_active_plugins($sortMenu), '„Aktive Plugins“: Einzelseiten und Gruppen gemeinsam natürlich sortiert, bei Gleichstand nach ID');
});

T::run('Teil 5d: Plugin-API für Wunschplätze', static function (): void {
    require_once ABSPATH . 'includes/functions/admin-menu-profiles.php';
    cms_admin_menu_set_preferred_parent('shop', ['classic' => 'tools', 'structured' => 'reports']);
    cms_admin_menu_set_preferred_parent('shop', ['structured' => 'extend', 'custom' => '', 7 => 'x']);
    cms_admin_menu_set_preferred_parent('', ['classic' => 'tools']);
    $registered = ['shop' => ['classic' => 'tools', 'structured' => 'extend']];
    T::same($registered, cms_admin_menu_get_preferred_parents(), 'cms_admin_menu_set_preferred_parent(): je Profil überschreibbar, leere Werte ignoriert');

    \CMS\Hooks::addFilter(MenuRegistry::PREFERRED_PARENTS_FILTER, static function (array $parents): array {
        $parents['news'] = ['classic' => 'posts'];

        return $parents;
    });
    $parents = cms_admin_menu_get_preferred_parents();
    T::same(['classic' => 'posts'], $parents['news'] ?? null, 'Filter cms_admin_menu_preferred_parents ergänzt Wunschplätze');
    $registry = MenuRegistry::create([['menu_slug' => 'shop', 'menu_title' => 'Shop'], ['menu_slug' => 'news', 'menu_title' => 'News']], $parents, null, '', []);
    T::same(['tools', 'extend', 'posts'], [$registry->preferredParent('shop', 'classic'), $registry->preferredParent('shop', 'structured'), $registry->preferredParent('news', 'classic')], 'Wunschplätze aus API und Filter kommen in der Registry an');

    \CMS\Hooks::addFilter(MenuRegistry::PREFERRED_PARENTS_FILTER, static fn (mixed $parents): string => 'kaputt', 20);
    T::same($registered, cms_admin_menu_get_preferred_parents(), 'Filter ohne Array-Ergebnis → registrierte Wunschplätze');
});

// ─────────────────────────────────────────────────────────────────────────────
// Teil 5e: Sidebar-Modell (AdminNavigationService) – Fixture
// ─────────────────────────────────────────────────────────────────────────────

/** Zähler-Fake für den registrierbaren Badge „form_submissions“ (Formular-Eingänge ab Phase B). */
final class AmFormBadge
{
    public static int $pending = 2;
    public static int $calls = 0;
}

/**
 * Eigenes Layout mit Hauptpunkten (Core, Plugin, Plugin-Unterseite), Menügruppe, Auffanggruppe,
 * Plugin-Auto-Gruppe und zwei Kachelgruppen; Builder mit allen Rechten, Modulen und Dateien.
 *
 * @param (callable(array<string, mixed>): bool)|null $isAssigned Plugin-Zuweisung zur Site
 * @param (callable(array<string, mixed>): array<string, mixed>)|null $editLayout passt das Rohlayout an
 * @return array{registry:MenuRegistry, tree:array<string, mixed>, valid:bool, problems:list<string>}
 */
function am_nav_fixture(?callable $isAssigned = null, ?callable $editLayout = null): array
{
    BadgeProvider::register(BadgeProvider::FORM_SUBMISSIONS, static function (array $context): int {
        AmFormBadge::$calls++;

        return AmFormBadge::$pending;
    });

    $icon = static fn (string $name): array => ['type' => 'tabler', 'name' => $name];
    $core = [
        new AdminMenuItem(id: 'dashboard', label: 'Dashboard', route: '/admin', icon: $icon('home'), pageSlug: 'dashboard', locked: true),
        new AdminMenuItem(id: 'm.featured', label: 'Hervorgehoben', route: '/admin/media?tab=featured', pageSlug: 'media'),
        new AdminMenuItem(id: 'm.library', label: 'Medien', route: '/admin/media', pageSlug: 'media'),
        new AdminMenuItem(id: 'w.inbox', label: 'Eingänge', route: '/admin/inbox', pageSlug: 'inbox', badge: BadgeProvider::FORM_SUBMISSIONS),
        new AdminMenuItem(id: 'w.spam', label: 'Spam', route: '/admin/inbox?view=spam', pageSlug: 'inbox', badge: BadgeProvider::FORM_SUBMISSIONS),
        new AdminMenuItem(id: 'p.profile', label: 'Mein Profil', route: '/member/profile', type: AdminMenuItem::TYPE_LINK),
        new AdminMenuItem(id: 's.general', label: 'Allgemein', route: '/admin/settings', icon: $icon('settings'), pageSlug: 'settings', category: 'general', tileLabel: 'Grundeinstellungen', description: 'Name, Sprache und Zeitzone', categoryLabel: 'Allgemein'),
        new AdminMenuItem(id: 's.mail', label: 'E-Mail', route: '/admin/settings?tab=mail', pageSlug: 'settings', category: 'general', categoryLabel: 'Allgemein'),
        new AdminMenuItem(id: 's.smtp', label: 'SMTP', route: '/admin/settings?tab=mail&section=smtp', pageSlug: 'settings', category: 'general', categoryLabel: 'Allgemein'),
        new AdminMenuItem(id: 's.privacy', label: 'Auskunft & Löschen', route: '/admin/data-requests', pageSlug: 'data-requests', category: 'legal', categoryLabel: 'Recht'),
        new AdminMenuItem(id: 'h.logs', label: 'Protokolle', route: '/admin/logs', icon: $icon('file-text'), pageSlug: 'logs', category: 'logs', badge: BadgeProvider::LOGS, categoryLabel: 'Protokolle'),
        new AdminMenuItem(id: 'h.forms', label: 'Formular-Fehler', route: '/admin/form-errors', pageSlug: 'form-errors', category: 'logs', badge: BadgeProvider::FORM_SUBMISSIONS, categoryLabel: 'Protokolle'),
        new AdminMenuItem(id: 'h.info', label: 'Systeminfo', route: '/admin/info', pageSlug: 'info'),
        new AdminMenuItem(id: 'h.docs', label: 'Dokumentation', route: '/admin/documentation', pageSlug: 'documentation', category: 'help', categoryLabel: 'Hilfe'),
    ];
    $plugins = [
        ['menu_slug' => 'news', 'menu_title' => 'Acme | News', 'icon_url' => 'ti ti-news'],
        ['menu_slug' => 'wiki', 'menu_title' => 'Wiki'],
        ['menu_slug' => 'shop', 'menu_title' => 'Acme | Shop', 'children' => [
            ['menu_slug' => 'shop', 'menu_title' => 'Bestellungen'],
            ['menu_slug' => 'shop-settings', 'menu_title' => 'Einstellungen'],
        ]],
        ['menu_slug' => 'backup-pro', 'menu_title' => 'Backup Pro'],
    ];
    $registry = MenuRegistry::create($plugins, [], $isAssigned, '', $core);
    $layout = [
        'base' => ProfileLayouts::CLASSIC,
        'fallback_group' => 'plugins',
        'root_group' => 'cfg',
        'nodes' => [
            ['type' => 'item', 'id' => 'dashboard'],
            ['type' => 'item', 'id' => 'plugin:news'],
            ['type' => 'item', 'id' => 'plugin:shop:shop-settings'],
            ['type' => 'group', 'id' => 'media', 'label' => 'Medien', 'icon' => 'photo', 'items' => ['m.featured', 'm.library', 'w.inbox', 'w.spam', 'p.profile']],
            ['type' => 'group', 'id' => 'plugins', 'label' => 'Plugins', 'icon' => 'plug', 'items' => []],
            ['type' => 'separator', 'id' => 'admin', 'label' => 'Verwaltung'],
            ['type' => 'group', 'id' => 'cfg', 'label' => 'Einstellungen', 'icon' => 'settings', 'display' => LayoutValidator::DISPLAY_TILES, 'items' => ['s.general', 's.mail', 's.smtp', 's.privacy']],
            ['type' => 'group', 'id' => 'health', 'label' => 'Website-Zustand', 'icon' => 'heartbeat', 'display' => LayoutValidator::DISPLAY_TILES, 'items' => ['h.logs', 'h.forms', 'h.info', 'plugin:backup-pro', 'h.docs']],
        ],
    ];
    if ($editLayout !== null) {
        $layout = $editLayout($layout);
    }
    $validated = LayoutValidator::validate($layout, $registry->knownCoreIds(), $registry->lockedIds());
    $builder = new MenuBuilder(static fn (array $c): bool => true, static fn (string $p): bool => true, static fn (string $f): bool => true, true);

    return [
        'registry' => $registry,
        'tree' => $builder->build($registry, $validated['layout'], ProfileLayouts::CUSTOM),
        'valid' => $validated['valid'] && $validated['warnings'] === [],
        'problems' => [...$validated['errors'], ...$validated['warnings']],
    ];
}

/**
 * Aktuelle Einträge eines Sidebar-Modells: „<Eintrag>“, „<Gruppe> > <Kind>“ bzw. „<Kachelgruppe>#<aria-current>“.
 *
 * @param array<string, mixed> $model
 * @return list<string>
 */
function am_nav_current(array $model): array
{
    $found = [];
    foreach ($model['groups'] as $group) {
        foreach ($group['entries'] as $entry) {
            if ($entry['current']) {
                $found[] = $entry['id'] . ($entry['tiles'] ? '#' . $entry['aria_current'] : '');
            }
            foreach ($entry['children'] as $child) {
                if ($child['current']) {
                    $found[] = $entry['id'] . ' > ' . $child['id'];
                }
            }
        }
    }

    return $found;
}

/**
 * @param array<string, mixed> $model
 * @return array<string, mixed>|null Eintrag der obersten Ebene mit dieser ID
 */
function am_nav_entry(array $model, string $id): ?array
{
    foreach ($model['groups'] as $group) {
        foreach ($group['entries'] as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }
    }

    return null;
}

T::run('Teil 5e: Sidebar-Modell – Aufbau und Badges', static function (): void {
    $fixture = am_nav_fixture();
    T::check($fixture['valid'], 'Fixture-Layout ist gültig', implode('; ', $fixture['problems']));
    $nav = AdminNavigationService::getInstance();
    AmFormBadge::$pending = 2;
    AmFormBadge::$calls = 0;
    $model = $nav->present($fixture['tree'], $fixture['registry'], '', ['site_url' => 'https://cms.test/', 'logs_badge' => 3]);

    T::same(['groups', 'breadcrumb', 'recent_label', 'plugins_total', 'plugins_hidden'], array_keys($model), 'Schlüssel des Modells');
    T::same(
        [
            'g:main' => ['e:dashboard', 'p:news', 'e:p.shop:shop-settings', 'e:media'],
            'g:admin' => ['e:cfg', 'e:health'],
            'g:_active-plugins' => ['p:shop', 'p:wiki'],
        ],
        array_combine(array_column($model['groups'], 'id'), array_map(static fn (array $group): array => array_column($group['entries'], 'id'), $model['groups'])),
        'Abschnitte und Eintrags-IDs: Core e:<id>, Plugin p:<menü>, Plugin-Unterseite e:p.<menü>:<seite>, Auto-Gruppe p:<menü>; nicht platzierte Plugins unter „Aktive Plugins“, leere Auffanggruppe entfällt'
    );
    T::same([4, 0], [$model['plugins_total'], $model['plugins_hidden']], 'Plugin-Zahlen aus der Registry');
    T::same([['section' => 'Dashboard', 'page' => 'Übersicht'], ''], [$model['breadcrumb'], $model['recent_label']], 'Ohne aktuelle Seite: Brotkrumen „Dashboard › Übersicht“');
    T::same(1, AmFormBadge::$calls, 'Badge-Zähler einmal je Aufbau, obwohl drei Einträge den Schlüssel tragen');

    $sections = array_map(static function (array $group): array {
        unset($group['entries']);

        return $group;
    }, $model['groups']);
    T::same([
        ['id' => 'g:main', 'label' => '', 'plain' => true, 'plugins' => false, 'count' => 0, 'hint' => null, 'dom_id' => 'cms-nav-g-main', 'active' => false, 'expanded' => true],
        ['id' => 'g:admin', 'label' => 'Verwaltung', 'plain' => false, 'plugins' => false, 'count' => 0, 'hint' => null, 'dom_id' => 'cms-nav-g-admin', 'active' => false, 'expanded' => true],
        ['id' => 'g:_active-plugins', 'label' => 'Aktive Plugins', 'plain' => false, 'plugins' => true, 'count' => 2, 'hint' => null, 'dom_id' => 'cms-nav-g-_active-plugins', 'active' => false, 'expanded' => false],
    ], $sections, 'Abschnitte: Hauptabschnitt ohne Überschrift; ohne gespeicherten Zustand aufgeklappt; „Aktive Plugins“ eingeklappt, mit Anzahl');

    T::same([
        'id' => 'p:news', 'label' => 'Acme | News', 'prefix' => 'Acme', 'name' => 'News', 'slug' => 'news',
        'url' => 'https://cms.test/admin/plugins/news/news', 'path' => '/admin/plugins/news/news', 'icon' => ['type' => 'tabler', 'name' => 'news'],
        'children' => [], 'current' => false, 'aria_current' => '', 'badge' => null, 'plugin' => true, 'tiles' => false, 'external' => false,
        'dom_id' => 'cms-nav-p-news', 'active' => false, 'expanded' => false,
    ], am_nav_entry($model, 'p:news'), 'Plugin-Hauptpunkt: „Präfix | Name“ getrennt, Tabler-Icon aus icon_url, Site-URL ohne doppelten Schrägstrich');
    $shopSettings = am_nav_entry($model, 'e:p.shop:shop-settings') ?? [];
    T::same(
        ['Einstellungen', '', 'Einstellungen', 'shop-settings', '/admin/plugins/shop/shop-settings', true, 'cms-nav-e-p-shop-shop-settings', ['type' => 'tabler', 'name' => AdminNavigationService::DEFAULT_PLUGIN_ICON]],
        [$shopSettings['label'] ?? null, $shopSettings['prefix'] ?? null, $shopSettings['name'] ?? null, $shopSettings['slug'] ?? null, $shopSettings['path'] ?? null, $shopSettings['plugin'] ?? null, $shopSettings['dom_id'] ?? null, $shopSettings['icon'] ?? null],
        'Plugin-Unterseite als Hauptpunkt: eigene ID, DOM-ID ohne Sonderzeichen, Standard-Icon'
    );

    $media = am_nav_entry($model, 'e:media') ?? [];
    $children = $media['children'] ?? [];
    unset($media['children']);
    $two = ['label' => '2', 'sr' => '2 neue Eingänge', 'count' => 2];
    T::same([
        'id' => 'e:media', 'label' => 'Medien', 'prefix' => '', 'name' => 'Medien', 'slug' => '', 'url' => null, 'path' => '',
        'icon' => ['type' => 'tabler', 'name' => 'photo'], 'current' => false, 'aria_current' => '', 'badge' => $two,
        'plugin' => false, 'tiles' => false, 'external' => false, 'dom_id' => 'cms-nav-e-media', 'active' => false, 'expanded' => false,
    ], $media, 'Menügruppe: ohne URL; Summen-Badge zählt jeden Schlüssel einmal');
    T::same([
        ['id' => 'm.featured', 'label' => 'Hervorgehoben', 'slug' => 'media', 'url' => 'https://cms.test/admin/media?tab=featured', 'path' => '/admin/media', 'current' => false, 'badge' => null, 'plugin' => false, 'external' => false],
        ['id' => 'm.library', 'label' => 'Medien', 'slug' => 'media', 'url' => 'https://cms.test/admin/media', 'path' => '/admin/media', 'current' => false, 'badge' => null, 'plugin' => false, 'external' => false],
        ['id' => 'w.inbox', 'label' => 'Eingänge', 'slug' => 'inbox', 'url' => 'https://cms.test/admin/inbox', 'path' => '/admin/inbox', 'current' => false, 'badge' => $two, 'plugin' => false, 'external' => false],
        ['id' => 'w.spam', 'label' => 'Spam', 'slug' => 'inbox', 'url' => 'https://cms.test/admin/inbox?view=spam', 'path' => '/admin/inbox', 'current' => false, 'badge' => $two, 'plugin' => false, 'external' => false],
        ['id' => 'p.profile', 'label' => 'Mein Profil', 'slug' => '', 'url' => 'https://cms.test/member/profile', 'path' => '/member/profile', 'current' => false, 'badge' => null, 'plugin' => false, 'external' => true],
    ], $children, 'Kinder: Katalog-IDs, URL mit Query, Pfad ohne Query, Badge je Eintrag, externer Link markiert');

    $wiki = am_nav_entry($model, 'p:wiki') ?? [];
    $auto = am_nav_entry($model, 'p:shop') ?? [];
    T::same(
        [true, 'https://cms.test/admin/plugins/wiki/wiki', 'cms-nav-p-wiki', ['plugin:shop:shop'], ['Bestellungen'], true, 'Acme', 'Shop', 'cms-nav-p-shop'],
        [$wiki['plugin'] ?? null, $wiki['url'] ?? null, $wiki['dom_id'] ?? null, array_column($auto['children'] ?? [], 'id'), array_column($auto['children'] ?? [], 'label'), $auto['plugin'] ?? null, $auto['prefix'] ?? null, $auto['name'] ?? null, $auto['dom_id'] ?? null],
        '„Aktive Plugins“: Einzelseite als Link p:<menü>; Auto-Gruppe p:<menü> mit getrenntem Titel nimmt die nicht platzierte Geschwisterseite auf (Kind mit Menü-Slug, keine zusätzliche „Übersicht“)'
    );
    $orphanFixture = am_nav_fixture(null, static function (array $layout): array {
        $layout['nodes'][7]['items'] = ['h.logs', 'h.forms', 'plugin:backup-pro', 'h.docs'];

        return $layout;
    });
    $orphanModel = $nav->present($orphanFixture['tree'], $orphanFixture['registry'], '', ['site_url' => 'https://cms.test/', 'badges' => false]);
    $fallback = am_nav_entry($orphanModel, 'e:plugins') ?? [];
    T::same(
        [['h.info'], false, 'Plugins', ['p:shop', 'p:wiki']],
        [array_column($fallback['children'] ?? [], 'id'), $fallback['plugin'] ?? null, $fallback['name'] ?? null, array_column(end($orphanModel['groups'])['entries'], 'id')],
        'Auffanggruppe ist keine Plugin-Gruppe und nimmt nur nicht platzierte Core-Einträge auf'
    );

    T::same([
        'id' => 'e:health', 'label' => 'Website-Zustand', 'prefix' => '', 'name' => 'Website-Zustand', 'slug' => '',
        'url' => 'https://cms.test/admin/configuration?group=health', 'path' => '/admin/configuration', 'icon' => ['type' => 'tabler', 'name' => 'heartbeat'],
        'children' => [], 'current' => false, 'aria_current' => '', 'badge' => ['label' => '5', 'sr' => '3 ungelesene Hinweise, 2 neue Eingänge', 'count' => 5],
        'plugin' => false, 'tiles' => true, 'external' => false, 'dom_id' => 'cms-nav-e-health', 'active' => false, 'expanded' => false,
    ], am_nav_entry($model, 'e:health'), 'Kachelgruppe: ein Link auf ihre Kachelseite; Summen-Badge nennt jede Quelle');
    $cfg = am_nav_entry($model, 'e:cfg') ?? [];
    T::same(['https://cms.test/admin/configuration', null], [$cfg['url'] ?? '', array_key_exists('badge', $cfg) ? $cfg['badge'] : 'fehlt'], 'Start-Kachelgruppe: /admin/configuration, ohne Badge');
});

T::run('Teil 5e: Sidebar-Modell – aktiver Eintrag und Brotkrumen', static function (): void {
    $fixture = am_nav_fixture();
    $nav = AdminNavigationService::getInstance();
    $model = static fn (string $request, string $activePage = '', array $extra = []): array => $nav->present(
        $fixture['tree'],
        $fixture['registry'],
        $activePage,
        array_replace(['site_url' => 'https://cms.test', 'badges' => false, 'request_path' => $request], $extra)
    );
    $at = static function (string $request, string $activePage = '') use ($model): array {
        $result = $model($request, $activePage);
        $crumb = $result['breadcrumb'];

        return [implode(', ', am_nav_current($result)), ($crumb['section'] !== '' ? $crumb['section'] . ' › ' : '') . $crumb['page'], $result['recent_label']];
    };

    // [Anfrage (REQUEST_URI), $activePage der Seite, erwartet [aktuell, Brotkrumen, „Zuletzt besucht“], Fall]
    $cases = [
        ['/admin/settings?tab=mail&section=smtp', 'settings', ['e:cfg#true', 'Einstellungen › SMTP', 'Einstellungen · SMTP'], 'Route mit Query: die spezifischste passende gewinnt'],
        ['/admin/settings?tab=mail', 'settings', ['e:cfg#true', 'Einstellungen › E-Mail', 'Einstellungen · E-Mail'], 'Route mit Query'],
        ['/admin/settings?section=imap&tab=mail&x=1', 'settings', ['e:cfg#true', 'Einstellungen › E-Mail', 'Einstellungen · E-Mail'], 'Weitere Parameter und Reihenfolge der Anfrage stören nicht'],
        ['/admin/settings?tab=unbekannt', 'settings', ['e:cfg#true', 'Einstellungen › Allgemein', 'Einstellungen · Allgemein'], 'Unbekannter Tab → Eintrag der Seite ohne Query mit passendem Pfad'],
        ['/admin/theme-settings', 'theme-settings', ['e:cfg#true', 'Einstellungen › Allgemein', 'Einstellungen · Allgemein'], '$activePage theme-settings → settings, erster Eintrag der Seite'],
        ['/admin/media', 'media', ['e:media > m.library', 'Medien › Medien', 'Medien'], 'Mehrere Einträge einer Seite: der ohne Query mit passendem Pfad; „Zuletzt“ ohne doppelte Bezeichnung'],
        ['/admin/media?tab=featured', 'media', ['e:media > m.featured', 'Medien › Hervorgehoben', 'Medien · Hervorgehoben'], 'Query-Route vor $activePage'],
        ['/admin/media/upload', 'media', ['e:media > m.featured', 'Medien › Hervorgehoben', 'Medien · Hervorgehoben'], 'Unterseite ohne eigenen Eintrag → erster Eintrag der Seite'],
        ['https://cms.test/admin/inbox?view=spam', 'inbox', ['e:media > w.spam', 'Medien › Spam', 'Medien · Spam'], 'Vollständige URL als Anfrage'],
        ['/admin/plugins/shop/shop-settings', 'shop-settings', ['e:p.shop:shop-settings', 'Einstellungen', 'Einstellungen'], 'Plugin-Unterseite als Hauptpunkt per Pfad'],
        ['/admin/plugins/news/news', 'dashboard', ['p:news', 'Acme | News', 'Acme | News'], 'Plugin-Pfad schlägt $activePage einer Core-Seite'],
        ['/admin/plugins/shop/shop/', '', ['p:shop > plugin:shop:shop', 'Acme | Shop › Bestellungen', 'Acme | Shop · Bestellungen'], 'Abschließender Schrägstrich wird ignoriert'],
        ['', 'wiki', ['p:wiki', 'Aktive Plugins › Wiki', 'Wiki'], 'Plugin-Seite per $activePage (ohne Anfragepfad); Brotkrume nennt „Aktive Plugins“'],
        ['/admin/configuration', 'configuration', ['e:cfg#page', 'Einstellungen', 'Einstellungen'], 'Start-Kachelseite'],
        ['/admin/configuration?group=health', 'configuration', ['e:health#page', 'Website-Zustand', 'Website-Zustand'], 'Kachelseite per ?group='],
        ['/admin/logs', 'logs', ['e:health#true', 'Website-Zustand › Protokolle', 'Website-Zustand · Protokolle'], 'Seite einer Kachelgruppe: Kachel-Link aktiv (aria-current=true)'],
        ['/admin/plugins/backup-pro/backup-pro', 'backup-pro', ['e:health#true', 'Website-Zustand › Backup Pro', 'Website-Zustand · Backup Pro'], 'Plugin-Seite in einer Kachelgruppe'],
        ['/admin/diagnose', 'system-info', ['e:health#true', 'Website-Zustand › Systeminfo', 'Website-Zustand · Systeminfo'], '$activePage system-info → info'],
        ['/admin/deletion-requests', 'deletion-requests', ['e:cfg#true', 'Einstellungen › Auskunft & Löschen', 'Einstellungen · Auskunft & Löschen'], '$activePage deletion-requests → data-requests'],
        ['/admin/privacy-requests', 'privacy-requests', ['e:cfg#true', 'Einstellungen › Auskunft & Löschen', 'Einstellungen · Auskunft & Löschen'], '$activePage privacy-requests → data-requests'],
        ['/admin/support', 'support', ['e:health#true', 'Website-Zustand › Dokumentation', 'Website-Zustand · Dokumentation'], '$activePage support → documentation'],
        ['/admin/', '', ['e:dashboard', 'Dashboard', 'Dashboard'], 'Pfad /admin/ ohne $activePage → Dashboard'],
        ['/member/profile', '', ['', 'Dashboard › Übersicht', ''], 'Externer Link wird nie per Pfad aktiv'],
        ['/admin/unbekannt', 'unbekannt', ['', 'Dashboard › Übersicht', ''], 'Unbekannte Seite: nichts aktiv'],
    ];
    foreach ($cases as [$request, $activePage, $expected, $message]) {
        T::same($expected, $at($request, $activePage), $message . ' (' . ($request !== '' ? $request : '–') . ' | ' . ($activePage !== '' ? $activePage : '–') . ')');
    }

    $dashboard = am_nav_entry($model('/admin/', 'dashboard'), 'e:dashboard') ?? [];
    T::same([true, 'page', true], [$dashboard['current'] ?? null, $dashboard['aria_current'] ?? null, $dashboard['active'] ?? null], 'Aktueller Hauptpunkt: aria-current=page');
    $mediaModel = $model('/admin/media', 'media');
    $media = am_nav_entry($mediaModel, 'e:media') ?? [];
    T::same([false, '', true, true, true], [$media['current'] ?? null, $media['aria_current'] ?? null, $media['active'] ?? null, $media['expanded'] ?? null, $mediaModel['groups'][0]['active']], 'Gruppe mit aktuellem Kind: aktiv und aufgeklappt, selbst nicht aktuell; Abschnitt aktiv');
    $logsModel = $model('/admin/logs', 'logs');
    T::same([false, true], [$logsModel['groups'][0]['active'], $logsModel['groups'][1]['active']], 'Kachelseite einer Gruppe: nur ihr Abschnitt ist aktiv');
    T::same(
        [['section' => 'Dashboard', 'page' => 'Eigene Seite'], ['section' => 'Dashboard', 'page' => 'Übersicht']],
        [$model('/admin/unbekannt', 'unbekannt', ['page_title' => ' Eigene Seite '])['breadcrumb'], $model('/admin/unbekannt', 'unbekannt', ['page_title' => '   '])['breadcrumb']],
        'Ohne Menüeintrag: Seitentitel als Brotkrume, sonst „Übersicht“'
    );
});

T::run('Teil 5e: Sidebar-Modell – Aufklappzustand, DOM-IDs, Badges, Plugin-Hinweis', static function (): void {
    $fixture = am_nav_fixture();
    $nav = AdminNavigationService::getInstance();
    $present = static fn (array $context, string $activePage = ''): array => $nav->present($fixture['tree'], $fixture['registry'], $activePage, $context + ['site_url' => 'https://cms.test', 'badges' => false]);
    $states = static function (array $model): array {
        $result = [];
        foreach ($model['groups'] as $group) {
            $result[$group['id']] = $group['expanded'];
            foreach ($group['entries'] as $entry) {
                if ($entry['url'] === null) {
                    $result[$entry['id']] = $entry['expanded'];
                }
            }
        }

        return $result;
    };
    $expect = static fn (bool $media, bool $admin, bool $plugins, bool $shop): array => ['g:main' => true, 'e:media' => $media, 'g:admin' => $admin, 'g:_active-plugins' => $plugins, 'p:shop' => $shop];
    $stateCases = [
        [[], '', $expect(false, true, false, false), 'Ohne gespeicherten Zustand: Gruppen zu, Abschnitte auf, „Aktive Plugins“ zu'],
        [['group_states' => ['e:media' => true, 'p:shop' => true, 'g:admin' => false, 'g:main' => false]], '', $expect(true, false, false, true), 'Gespeicherter Zustand je ID; Hauptabschnitt immer offen'],
        [['group_states' => ['g:admin' => 'nein', 'e:media' => 1, 'g:_active-plugins' => 1]], '', $expect(false, true, false, false), 'Nur echte Booleans zählen'],
        [['group_states' => 'kaputt'], '', $expect(false, true, false, false), 'Ungültige group_states werden ignoriert'],
        [['group_states' => ['e:media' => false, 'g:admin' => false], 'request_path' => '/admin/media'], 'media', $expect(true, false, false, false), 'Gruppe mit aktueller Seite ist immer aufgeklappt'],
        [['group_states' => ['g:admin' => false], 'request_path' => '/admin/logs'], 'logs', $expect(false, true, false, false), 'Abschnitt mit aktueller Seite ist immer aufgeklappt'],
        [['group_states' => ['e:media' => false, 'g:admin' => false, 'g:_active-plugins' => false], 'expand_all' => true], '', $expect(true, true, true, true), 'expand_all: alles aufgeklappt, gespeicherte Zustände ignoriert'],
        [['group_states' => ['g:_active-plugins' => true]], '', $expect(false, true, true, false), '„Aktive Plugins“: gespeichertes „offen“ gilt'],
        [['group_states' => ['g:_active-plugins' => false], 'request_path' => '/admin/plugins/wiki/wiki'], 'wiki', $expect(false, true, true, false), '„Aktive Plugins“ mit aktueller Plugin-Seite ist aufgeklappt'],
        [['group_states' => ['g:_active-plugins' => false, 'p:shop' => false], 'request_path' => '/admin/plugins/shop/shop'], 'shop', $expect(false, true, true, true), '„Aktive Plugins“ und Plugin-Gruppe mit aktueller Seite sind aufgeklappt'],
        [['group_states' => ['g:_active-plugins' => true], 'request_path' => '/admin/plugins/news/news'], 'news', $expect(false, true, true, false), 'Im Layout platzierte Plugin-Seite öffnet „Aktive Plugins“ nicht; gespeicherter Zustand bleibt'],
    ];
    foreach ($stateCases as [$context, $activePage, $expected, $message]) {
        T::same($expected, $states($present($context, $activePage)), $message);
    }

    $domIds = static function (array $model): array {
        $ids = array_column($model['groups'], 'dom_id');
        foreach ($model['groups'] as $group) {
            array_push($ids, ...array_column($group['entries'], 'dom_id'));
        }

        return $ids;
    };
    T::same(
        ['cms-nav-g-main', 'cms-nav-g-admin', 'cms-nav-g-_active-plugins', 'cms-nav-e-dashboard', 'cms-nav-p-news', 'cms-nav-e-p-shop-shop-settings', 'cms-nav-e-media', 'cms-nav-e-cfg', 'cms-nav-e-health', 'cms-nav-p-shop', 'cms-nav-p-wiki'],
        $domIds($present([])),
        'DOM-IDs: Präfix cms-nav-, Sonderzeichen der Eintrags-ID → Bindestrich'
    );
    $longest = 'a' . str_repeat('b', 40);
    T::same(['am-preview-e-p-shop-shop-settings', $longest . 'g-main'], [$domIds($present(['dom_prefix' => 'am-preview-']))[5], $domIds($present(['dom_prefix' => $longest]))[0]], 'Eigenes DOM-Präfix (Vorschau im Einstellungs-Tab), bis 41 Zeichen');
    foreach (['Am-preview-', '1am-', 'am_preview-', 'am preview', '', $longest . 'c', 5, null] as $invalid) {
        T::same('cms-nav-g-main', $domIds($present(['dom_prefix' => $invalid]))[0], 'Ungültiges DOM-Präfix ' . T::export($invalid) . ' → cms-nav-');
    }

    $badges = static function (array $model): array {
        $labels = [];
        foreach ($model['groups'] as $group) {
            foreach ($group['entries'] as $entry) {
                $labels[$entry['id']] = $entry['badge']['label'] ?? null;
                foreach ($entry['children'] as $child) {
                    $labels[$entry['id'] . ' > ' . $child['id']] = $child['badge']['label'] ?? null;
                }
            }
        }

        return array_filter($labels, static fn (?string $label): bool => $label !== null);
    };
    $live = static fn (array $context = []): array => $badges($nav->present($fixture['tree'], $fixture['registry'], '', $context + ['site_url' => 'https://cms.test']));
    AmFormBadge::$pending = 2;
    AmFormBadge::$calls = 0;
    T::same([[], 0], [$badges($present(['logs_badge' => 3])), AmFormBadge::$calls], 'badges=false: keine Zähler-Abfrage, keine Badges');
    $badgeCases = [
        [2, ['logs_badge' => 3], ['e:media' => '2', 'e:media > w.inbox' => '2', 'e:media > w.spam' => '2', 'e:health' => '5'], 'Badges an Gruppe, Kindern und Kachelgruppe'],
        [0, ['logs_badge' => 3], ['e:health' => '3'], 'Zahlen bei jedem Aufbau frisch (nie gecacht): 0 → kein Badge'],
        [150, ['logs_badge' => 3], ['e:media' => '99+', 'e:media > w.inbox' => '99+', 'e:media > w.spam' => '99+', 'e:health' => '99+'], 'Ab 100: „99+“'],
        [-4, ['logs_badge' => -5], [], 'Negative Zahlen zählen als 0'],
        [0, ['logs_badge' => '7'], ['e:health' => '7'], 'logs_badge als Zeichenkette'],
        [0, ['logs_badge' => 'abc'], [], 'logs_badge ohne Zahl → 0'],
        [0, [], [], 'Ohne logs_badge → 0'],
    ];
    foreach ($badgeCases as [$pending, $context, $expected, $message]) {
        AmFormBadge::$pending = $pending;
        T::same($expected, $live($context), $message);
    }
    T::same(count($badgeCases), AmFormBadge::$calls, 'Ein Zähleraufruf je Sidebar-Aufbau');
    AmFormBadge::$pending = 2;

    $unassigned = am_nav_fixture(static fn (array $menu): bool => false);
    $hintModel = static fn (array $for, array $context = []): array => $nav->present($for['tree'], $for['registry'], '', $context + ['site_url' => 'https://cms.test/', 'badges' => false]);
    $pluginSection = static function (array $model): ?array {
        foreach ($model['groups'] as $group) {
            if ($group['id'] === 'g:' . MenuBuilder::ACTIVE_PLUGINS_SECTION_ID) {
                return $group;
            }
        }

        return null;
    };
    $model = $hintModel($unassigned, ['site_label' => 'PhinIT', 'site_key' => 'phinit']);
    T::same(
        ['text' => 'Für PhinIT sind keine Plugins mit Menüeinträgen zugewiesen.', 'url' => 'https://cms.test/admin/plugin-assignment', 'link' => 'Plugin-Zuweisung öffnen'],
        $pluginSection($model)['hint'] ?? null,
        'Plugin-Hinweis im Abschnitt „Aktive Plugins“ mit Link zur Plugin-Zuweisung'
    );
    T::same(
        [['g:main', 'g:admin', 'g:_active-plugins'], ['e:dashboard', 'e:media'], ['e:cfg', 'e:health'], [], 0, false, 4, 4],
        [array_column($model['groups'], 'id'), array_column($model['groups'][0]['entries'], 'id'), array_column($model['groups'][1]['entries'], 'id'), $model['groups'][2]['entries'], $model['groups'][2]['count'], $model['groups'][2]['expanded'], $model['plugins_total'], $model['plugins_hidden']],
        'Ohne zugewiesene Plugins: keine Plugin-Einträge; „Aktive Plugins“ trägt nur den Hinweis, eingeklappt und ohne Zähler'
    );
    $hintText = static fn (array $context): ?string => $pluginSection($hintModel($unassigned, $context))['hint']['text'] ?? null;
    T::same(
        ['Für phinit sind keine Plugins mit Menüeinträgen zugewiesen.', 'Für diese Site sind keine Plugins mit Menüeinträgen zugewiesen.', 'Für diese Site sind keine Plugins mit Menüeinträgen zugewiesen.'],
        [$hintText(['site_key' => 'phinit']), $hintText([]), $hintText(['site_label' => '  ', 'site_key' => 'phinit'])],
        'Plugin-Hinweis nennt die Site-Bezeichnung, sonst den Site-Schlüssel, sonst „diese Site“'
    );
    $separated = am_nav_fixture(static fn (array $menu): bool => false, static function (array $layout): array {
        array_splice($layout['nodes'], 4, 0, [['type' => 'separator', 'id' => 'ext', 'label' => 'Erweiterungen']]);

        return $layout;
    });
    T::same(
        ['g:main', 'g:admin', 'g:_active-plugins'],
        array_column($hintModel($separated)['groups'], 'id'),
        'Layout-Abschnitt mit nur der leeren Auffanggruppe entfällt; der Hinweis steht immer unter „Aktive Plugins“'
    );
    T::same([null, null, null], array_column($hintModel($fixture)['groups'], 'hint'), 'Mit zugewiesenen Plugins kein Hinweis');
});

T::run('Teil 5e: Kachelseiten (tilesPage)', static function (): void {
    $fixture = am_nav_fixture();
    $nav = AdminNavigationService::getInstance();
    $context = ['site_url' => 'https://cms.test/', 'logs_badge' => 3];
    $tabler = static fn (string $name): array => ['type' => 'tabler', 'name' => $name];
    $tile = static fn (string $id, string $label, string $url, array $icon, ?array $badge = null, bool $plugin = false, string $description = ''): array => [
        'id' => $id, 'label' => $label, 'description' => $description, 'url' => 'https://cms.test' . $url, 'icon' => $icon, 'badge' => $badge, 'plugin' => $plugin, 'external' => false,
    ];

    AmFormBadge::$pending = 2;
    AmFormBadge::$calls = 0;
    T::same([
        'id' => 'health',
        'label' => 'Website-Zustand',
        'icon' => $tabler('heartbeat'),
        'route' => '/admin/configuration?group=health',
        'badge' => ['label' => '5', 'sr' => '3 ungelesene Hinweise, 2 neue Eingänge', 'count' => 5],
        'categories' => [
            ['id' => 'logs', 'label' => 'Protokolle', 'tiles' => [
                $tile('h.logs', 'Protokolle', '/admin/logs', $tabler('file-text'), ['label' => '3', 'sr' => '3 ungelesene Hinweise', 'count' => 3]),
                $tile('h.forms', 'Formular-Fehler', '/admin/form-errors', $tabler('point'), ['label' => '2', 'sr' => '2 neue Eingänge', 'count' => 2]),
            ]],
            ['id' => 'other', 'label' => 'Weitere', 'tiles' => [$tile('h.info', 'Systeminfo', '/admin/info', $tabler('point'))]],
            ['id' => 'plugin.backup-pro', 'label' => 'Backup Pro', 'tiles' => [
                $tile('plugin:backup-pro', 'Backup Pro', '/admin/plugins/backup-pro/backup-pro', $tabler(AdminNavigationService::DEFAULT_PLUGIN_ICON), null, true),
            ]],
            ['id' => 'help', 'label' => 'Hilfe', 'tiles' => [$tile('h.docs', 'Dokumentation', '/admin/documentation', $tabler('point'))]],
        ],
    ], $nav->tilesPage($fixture['tree'], 'health', $context), 'Kachelseite: Kategorien in Layout-Reihenfolge, ohne Kategorie → „Weitere“, Plugin-Seite unter ihrem Plugin, Badge je Kachel und als Summe');
    T::same(1, AmFormBadge::$calls, 'Kachelseite fragt jeden Zähler einmal ab');

    AmFormBadge::$calls = 0;
    T::same([
        'id' => 'cfg',
        'label' => 'Einstellungen',
        'icon' => $tabler('settings'),
        'route' => '/admin/configuration',
        'badge' => null,
        'categories' => [
            ['id' => 'general', 'label' => 'Allgemein', 'tiles' => [
                $tile('s.general', 'Grundeinstellungen', '/admin/settings', $tabler('settings'), null, false, 'Name, Sprache und Zeitzone'),
                $tile('s.mail', 'E-Mail', '/admin/settings?tab=mail', $tabler('point')),
                $tile('s.smtp', 'SMTP', '/admin/settings?tab=mail&section=smtp', $tabler('point')),
            ]],
            ['id' => 'legal', 'label' => 'Recht', 'tiles' => [$tile('s.privacy', 'Auskunft & Löschen', '/admin/data-requests', $tabler('point'))]],
        ],
    ], $nav->tilesPage($fixture['tree'], 'cfg', $context), 'Start-Kachelgruppe: Route /admin/configuration, Kachelbezeichnung und Beschreibung aus dem Katalog');
    T::same(0, AmFormBadge::$calls, 'Kachelseite ohne Badge-Schlüssel fragt keinen Zähler ab');

    $page = $nav->tilesPage($fixture['tree'], 'health', $context + ['badges' => false]) ?? [];
    $tileBadges = [];
    foreach ($page['categories'] ?? [] as $category) {
        array_push($tileBadges, ...array_column($category['tiles'], 'badge'));
    }
    T::same([null, [null, null, null, null, null], 0], [array_key_exists('badge', $page) ? $page['badge'] : 'fehlt', $tileBadges, AmFormBadge::$calls], 'badges=false: Kachelseite ohne Zahlen und ohne Zähler-Abfrage');

    $empty = ['sections' => [['id' => 'main', 'nodes' => [['kind' => 'group', 'id' => 'leer', 'label' => 'Leer', 'display' => LayoutValidator::DISPLAY_TILES, 'route' => '/admin/configuration?group=leer', 'items' => []]]]]];
    foreach ([[$fixture['tree'], 'unbekannt', 'unbekannte Gruppe'], [$fixture['tree'], 'media', 'Menügruppe (Liste)'], [$fixture['tree'], 'plugins', 'Auffanggruppe'], [$fixture['tree'], 'dashboard', 'Einzeleintrag'], [$fixture['tree'], '', 'leere ID'], [$empty, 'leer', 'Kachelgruppe ohne Einträge'], [[], 'health', 'leerer Baum']] as [$tree, $id, $case]) {
        T::same(null, $nav->tilesPage($tree, $id, $context), 'Keine Kachelseite (→ 404): ' . $case);
    }

    $raw = ['sections' => [['id' => 'main', 'nodes' => [[
        'kind' => 'group', 'id' => 'roh', 'label' => 'Roh', 'display' => LayoutValidator::DISPLAY_TILES, 'route' => '/admin/configuration?group=roh', 'icon' => 'kein-array',
        'items' => [
            ['id' => 'x.one', 'label' => 'Eins', 'tile_label' => '  ', 'route' => '/admin/eins', 'icon' => 'kein-array', 'category' => 'misc', 'category_label' => '  '],
            ['id' => 'x.two', 'label' => 'Zwei', 'tile_label' => ' Zwei (Kachel) ', 'route' => '/admin/zwei', 'category' => 'misc', 'category_label' => 'Später'],
            ['id' => 'x.three', 'label' => 'Drei', 'route' => '/admin/drei', 'plugin' => 1, 'external' => 'ja'],
        ],
    ]]]]];
    $puzzle = $tabler(AdminNavigationService::DEFAULT_PLUGIN_ICON);
    T::same([
        'id' => 'roh', 'label' => 'Roh', 'icon' => $tabler('layout-grid'), 'route' => '/admin/configuration?group=roh', 'badge' => null,
        'categories' => [
            ['id' => 'misc', 'label' => 'Weitere', 'tiles' => [
                $tile('x.one', 'Eins', '/admin/eins', $puzzle),
                $tile('x.two', 'Zwei (Kachel)', '/admin/zwei', $puzzle),
            ]],
            ['id' => 'other', 'label' => 'Weitere', 'tiles' => [['id' => 'x.three', 'label' => 'Drei', 'description' => '', 'url' => 'https://cms.test/admin/drei', 'icon' => $puzzle, 'badge' => null, 'plugin' => true, 'external' => true]]],
        ],
    ], $nav->tilesPage($raw, 'roh', $context), 'Rückfallwerte: Gruppen-Icon layout-grid, Kachel-Icon puzzle, Kategorie-Bezeichnung vom ersten Eintrag (leer → „Weitere“), leere Kachelbezeichnung → Bezeichnung');
});

T::run('Teil 5e: BadgeProvider und normalizeActivePage()', static function (): void {
    $noop = static fn (array $context): int => 1;
    foreach ([BadgeProvider::UPDATES, BadgeProvider::COMMENTS, BadgeProvider::PRIVACY_REQUESTS, BadgeProvider::LOGS, 'Am_test', '1am', 'am-test', 'am test', '', 'a' . str_repeat('b', 64)] as $key) {
        T::same(false, BadgeProvider::register($key, $noop), 'register() lehnt ' . T::export($key) . ' ab (eingebaute Quelle oder ungültiger Schlüssel)');
    }
    T::same([false, false], [BadgeProvider::isKnown('am-test'), BadgeProvider::isKnown('Am_test')], 'Abgelehnte Schlüssel bleiben unbekannt');

    $seen = [];
    T::check(BadgeProvider::register('am_test', static function (array $context) use (&$seen): int {
        $seen[] = $context;

        return 7;
    }, ['1 Test', '%d Tests']), 'register() nimmt einen neuen Schlüssel mit Texten an');
    T::check(BadgeProvider::register('am_negative', static fn (array $context): int => -3), 'register(): negativer Zähler');
    T::check(BadgeProvider::register('am_throws', static function (array $context): int {
        throw new \RuntimeException('Zähler kaputt');
    }), 'register(): Zähler mit Ausnahme');
    T::check(BadgeProvider::register('am_half', $noop, ['nur Einzahl']), 'register(): unvollständige Texte');
    T::check(BadgeProvider::register('a' . str_repeat('b', 63), $noop), 'register(): Schlüssel mit 64 Zeichen');
    T::same(
        [true, true, true, false, false, false],
        [BadgeProvider::isKnown(BadgeProvider::UPDATES), BadgeProvider::isKnown(BadgeProvider::FORM_SUBMISSIONS), BadgeProvider::isKnown('am_test'), BadgeProvider::isKnown('am_unbekannt'), BadgeProvider::isKnown(''), BadgeProvider::isKnown('Updates')],
        'isKnown(): Katalogschlüssel und registrierte Zähler'
    );

    T::same(
        ['am_test' => 7, 'logs' => 4, 'am_negative' => 0, 'am_throws' => 0],
        (new BadgeProvider())->counts(['am_test', 'am_unbekannt', '', 5, 'am_test', BadgeProvider::LOGS, 'am_negative', 'am_throws'], ['logs' => 4]),
        'counts(): jeder Schlüssel einmal, unbekannte übersprungen, negative und fehlerhafte Zähler → 0, logs aus dem Kontext'
    );
    T::same([['logs' => 4]], $seen, 'Registrierter Zähler erhält den Kontext und läuft trotz doppeltem Schlüssel einmal');

    $formatCases = [
        [[], null, 'leer'],
        [['logs' => 0, 'updates' => -2], null, 'nur Nullen und negative Zahlen'],
        [['updates' => 1], ['label' => '1', 'sr' => '1 Update verfügbar', 'count' => 1], 'Einzahl'],
        [['comments' => 3, 'updates' => 1], ['label' => '4', 'sr' => '3 offene Kommentare, 1 Update verfügbar', 'count' => 4], 'Summe, jede Quelle im Screenreader-Text'],
        [['privacy_requests' => 1, 'logs' => 0, 'form_submissions' => 100], ['label' => '99+', 'sr' => '1 offene DSGVO-Anfrage, 100 neue Eingänge', 'count' => 101], 'ab 100 „99+“, Nullen ohne Text'],
        [['logs' => 99], ['label' => '99', 'sr' => '99 ungelesene Hinweise', 'count' => 99], 'genau 99'],
        [['am_test' => 1], ['label' => '1', 'sr' => '1 Test', 'count' => 1], 'registrierte Texte (Einzahl)'],
        [['am_test' => 2], ['label' => '2', 'sr' => '2 Tests', 'count' => 2], 'registrierte Texte (Mehrzahl)'],
        [['am_half' => 2], ['label' => '2', 'sr' => '2 Hinweise', 'count' => 2], 'unvollständige Texte → Standardtext'],
        [['unbekannt' => 1], ['label' => '1', 'sr' => '1 Hinweis', 'count' => 1], 'unbekannter Schlüssel → Standardtext'],
    ];
    foreach ($formatCases as [$counts, $expected, $case]) {
        T::same($expected, BadgeProvider::format($counts), 'format(): ' . $case);
    }

    $pages = [
        'theme-settings' => 'settings', ' system-info ' => 'info', 'support' => 'documentation', 'privacy-requests' => 'data-requests',
        'deletion-requests' => 'data-requests', ' posts ' => 'posts', 'data-requests' => 'data-requests', '' => '', 'Theme-Settings' => 'Theme-Settings',
    ];
    foreach ($pages as $activePage => $expected) {
        T::same($expected, AdminNavigationService::normalizeActivePage((string) $activePage), 'normalizeActivePage(' . T::export((string) $activePage) . ')');
    }
});

T::run('Teil 5e: Echter Katalog – jede Seite markiert ihren eigenen Eintrag', static function (): void {
    $nav = AdminNavigationService::getInstance();
    $registry = MenuRegistry::create([]);
    $builder = new MenuBuilder(static fn (array $c): bool => true, static fn (string $p): bool => true, static fn (string $f): bool => true, true);
    $findCurrent = new \ReflectionMethod(AdminNavigationService::class, 'findCurrent');
    foreach (ProfileLayouts::PROFILES as $profile) {
        $sections = $builder->build($registry, ProfileLayouts::get($profile), $profile)['sections'];
        $mismatches = [];
        $checked = 0;
        $tilePages = 0;
        foreach ($sections as $section) {
            foreach ($section['nodes'] as $node) {
                if ($node['kind'] === 'group' && $node['display'] === LayoutValidator::DISPLAY_TILES) {
                    $tilePages++;
                    $found = $findCurrent->invoke($nav, $sections, $node['route'], 'configuration');
                    if ($found !== ['item' => '', 'tiles' => $node['id']]) {
                        $mismatches[] = 'Kachelseite ' . $node['id'] . ' (' . $node['route'] . ') → ' . T::export($found);
                    }
                }
                foreach ($node['kind'] === 'item' ? [$node] : $node['items'] as $item) {
                    if ($item['external']) {
                        continue;
                    }
                    $checked++;
                    // Aufruf der Katalog-Route mit dem $activePage, unter dem die Seite registriert ist, und ohne $activePage.
                    foreach ([AdminNavigationService::normalizeActivePage($item['page']), ''] as $activePage) {
                        $found = $findCurrent->invoke($nav, $sections, $item['route'], $activePage);
                        if ($found['item'] !== $item['id']) {
                            $mismatches[] = $item['id'] . ' (' . $item['route'] . ' | ' . ($activePage !== '' ? $activePage : '–') . ') → ' . ($found['item'] !== '' ? $found['item'] : ($found['tiles'] !== '' ? 'Kachelseite ' . $found['tiles'] : 'nichts'));
                        }
                    }
                }
            }
        }
        T::info($profile . ': ' . $checked . ' Seiten-Einträge und ' . $tilePages . ' Kachelseiten geprüft');
        T::check($checked > 50 && $tilePages >= 2, $profile . ': Katalog vollständig durchlaufen', $checked . ' Seiten, ' . $tilePages . ' Kachelseiten');
        T::same([], $mismatches, $profile . ': jede Katalog-Route markiert genau ihren Eintrag, jede Kachelseite ihre Gruppe');
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Teil 6: Installer-Schritt „Admin-Layout“
// ─────────────────────────────────────────────────────────────────────────────

T::run('Teil 6: Installer-Schritt „Admin-Layout“ (eigener Prozess, ohne Autoloader und Datenbank)', static function (): void {
    $output = [];
    $exitCode = -1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/installer-preview.php') . ' ' . escapeshellarg(rtrim(ABSPATH, '/')) . ' 2>&1', $output, $exitCode);
    $raw = implode("\n", $output);
    if (!T::check($exitCode === 0, 'Installer-Vorschau läuft im eigenen Prozess ohne Fehler', 'Exit ' . $exitCode . ': ' . substr($raw, 0, 700))) {
        return;
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        T::check(false, 'Installer-Vorschau liefert JSON', $e->getMessage() . ': ' . substr($raw, 0, 300));

        return;
    }

    T::same(0, $data['autoloaders'], 'Kein Autoloader registriert (wie in install.php)');
    T::same([
        'CMS\\Install\\InstallerService',
        'CMS\\Services\\AdminMenu\\AdminMenuItem',
        'CMS\\Services\\AdminMenu\\CoreCatalog',
        'CMS\\Services\\AdminMenu\\LayoutValidator',
        'CMS\\Services\\AdminMenu\\MenuBuilder',
        'CMS\\Services\\AdminMenu\\MenuRegistry',
        'CMS\\Services\\AdminMenu\\ProfileLayouts',
        'CMS\\Services\\CoreModuleService',
    ], $data['classes'], 'Geladen werden nur InstallerService, CoreModuleService und die sechs Menü-Klassen');
    T::same(
        ['structured', 'classic', 'structured', 'classic', 'classic', 'classic', 'classic', 'classic', 'classic', 'classic'],
        $data['normalized'],
        'normalizeAdminMenuProfile(): nur classic/structured (getrimmt), sonst classic; „custom“ gibt es erst nach dem Speichern im Editor'
    );
    $moduleSlugs = array_keys($data['modules']);
    T::same(['ai_services', 'legal', 'member_dashboard', 'performance', 'security', 'seo', 'subscriptions'], am_sorted($moduleSlugs), 'Schaltbare Core-Module des Installers');
    T::same([true], array_values(array_unique($data['modules'])), 'Alle schaltbaren Module sind standardmäßig aktiv');

    $registry = MenuRegistry::create([]);
    $mirror = static function (array $states) use ($registry): array {
        $builder = new MenuBuilder(
            static fn (array $capabilities): bool => true,
            static fn (string $page): bool => CoreModuleService::isAdminPageEnabledForStates($page, $states),
            static fn (string $file): bool => is_file(ABSPATH . $file),
        );
        $previews = [];
        foreach (ProfileLayouts::PROFILES as $profile) {
            $sections = [];
            foreach ($builder->build($registry, ProfileLayouts::get($profile), $profile)['sections'] as $section) {
                $sections[] = [
                    'label' => $section['label'],
                    'nodes' => array_map(static fn (array $node): array => ['label' => $node['label'], 'kind' => $node['kind'], 'display' => (string) ($node['display'] ?? '')], $section['nodes']),
                ];
            }
            $previews[$profile] = ['label' => ProfileLayouts::LABELS[$profile], 'description' => ProfileLayouts::DESCRIPTIONS[$profile], 'sections' => $sections];
        }

        return $previews;
    };
    T::same($mirror(array_fill_keys($moduleSlugs, true)), $data['on'], 'Skizzen (alle Module aktiv) = echter MenuBuilder mit allen Rechten und vorhandenen Seitendateien');
    T::same($mirror(array_fill_keys($moduleSlugs, false)), $data['off'], 'Skizzen (alle Module aus) = echter MenuBuilder');

    $labels = static fn (array $preview): array => array_merge([], ...array_map(static fn (array $section): array => array_column($section['nodes'], 'label'), $preview['sections']));
    $tiles = static function (array $preview): array {
        $found = [];
        foreach ($preview['sections'] as $section) {
            foreach ($section['nodes'] as $node) {
                if ($node['display'] === LayoutValidator::DISPLAY_TILES) {
                    $found[] = $node['label'];
                }
            }
        }

        return $found;
    };
    T::same(ProfileLayouts::PROFILES, array_keys($data['on']), 'Skizzen für genau die wählbaren Profile');
    T::same([['Arbeit', 'Gestaltung', 'Verwaltung'], ['']], [array_column($data['on']['classic']['sections'], 'label'), array_column($data['on']['structured']['sections'], 'label')], 'Abschnitte: classic Arbeit/Gestaltung/Verwaltung, structured ohne Überschrift');
    $optional = ['classic' => ['KI-Dienste', 'SEO', 'Mitgliederbereich', 'Abos'], 'structured' => ['KI-Dienste', 'SEO & Marketing', 'Mitgliederbereich', 'Abos']];
    $tilePages = ['classic' => ['Mitgliederbereich', 'Einstellungen', 'Website-Zustand'], 'structured' => ['Mitgliederbereich', 'Konfiguration', 'Berichte']];
    foreach (ProfileLayouts::PROFILES as $profile) {
        $on = $data['on'][$profile];
        $off = $data['off'][$profile];
        $direct = [['label' => 'Dashboard', 'kind' => 'item', 'display' => ''], ['label' => 'Updates', 'kind' => 'item', 'display' => '']];
        T::same([$direct, $direct], [array_slice($on['sections'][0]['nodes'], 0, 2), array_slice($off['sections'][0]['nodes'], 0, 2)], $profile . ': Dashboard und Updates als direkte Einträge zuerst');
        T::same($optional[$profile], array_values(array_intersect($labels($on), $optional[$profile])), $profile . ': optionale Module bei aktivem Modul sichtbar');
        T::same(array_values(array_diff($labels($on), $optional[$profile])), $labels($off), $profile . ': ohne Module fehlen genau die optionalen Hauptpunkte');
        T::same([$tilePages[$profile], array_values(array_diff($tilePages[$profile], ['Mitgliederbereich']))], [$tiles($on), $tiles($off)], $profile . ': Kachelseiten (Mitgliederbereich nur mit Modul)');

        $hidden = [];
        foreach (ProfileLayouts::get($profile)['nodes'] as $node) {
            if (($node['type'] ?? '') === 'group' && !in_array($node['label'] ?? '', $labels($on), true)) {
                $hidden[] = $node['label'] ?? $node['id'];
            }
        }
        T::info($profile . ': Gruppen ohne vorhandene Seitendateien (bis Phase B/WP1–4 ausgeblendet): ' . ($hidden !== [] ? implode(', ', $hidden) : '–'));
    }
});

T::run('Teil 6: Modulzustände ohne Datenbank (CoreModuleService::isAdminPageEnabledForStates)', static function (): void {
    $cases = [
        ['seo-dashboard', ['seo' => false], false, 'Seite eines abgeschalteten Moduls'],
        ['redirect-manager', ['seo' => false], false, 'Weiterleitungen gehören zum SEO-Modul'],
        ['seo-dashboard', ['seo' => true], true, 'Seite eines aktiven Moduls'],
        ['seo-dashboard', [], true, 'Ohne Zustand gilt der Standard des Moduls'],
        [' seo-dashboard ', ['seo' => false], false, 'Seitenslug wird getrimmt'],
        ['packages', ['subscriptions' => false], false, 'Abhängigkeit: Paketverwaltung braucht die Aboverwaltung'],
        ['packages', ['subscription_admin_packages' => false], true, 'Nicht schaltbare Module ignorieren übergebene Zustände'],
        ['member-dashboard', ['member_dashboard' => false], false, 'Mitgliederbereich'],
        ['privacy-requests', ['legal' => false], false, 'Alte DSGVO-Seite gehört zum Rechtsmodul'],
        ['ai-settings', ['ai_services' => false, 'seo' => true], false, 'KI-Einstellungen gehören zu den KI-Diensten'],
        ['posts', ['seo' => false, 'legal' => false], true, 'Seite ohne Modul ist immer aktiv'],
        ['', ['seo' => false], true, 'Leerer Slug'],
    ];
    foreach ($cases as [$page, $states, $expected, $case]) {
        T::same($expected, CoreModuleService::isAdminPageEnabledForStates($page, $states), $case . ' (' . T::export($page) . ', ' . T::export($states) . ')');
    }
});

T::run('Teil 7: Schema v25 (Neuinstallation, Update, Laufzeit)', static function (): void {
    T::same(SchemaManager::SCHEMA_VERSION, MigrationManager::SCHEMA_VERSION, 'SchemaManager und MigrationManager haben denselben Schema-Stand');
    T::check(version_compare(ltrim(SchemaManager::SCHEMA_VERSION, 'v'), '25', '>='), 'Schema-Stand mindestens v25 (Tabellen admin_menu_layouts, batch_jobs)', SchemaManager::SCHEMA_VERSION);

    $sql = SchemaManager::getSchemaQueries('cms_', 'utf8mb4')[LayoutRepository::TABLE] ?? '';
    if (!T::check($sql !== '', 'SchemaManager::getSchemaQueries() enthält die Tabelle ' . LayoutRepository::TABLE . ' (Neuinstallation)')) {
        return;
    }
    T::check(str_contains($sql, 'CREATE TABLE IF NOT EXISTS cms_admin_menu_layouts'), 'Anlage idempotent und mit Tabellenpräfix');
    foreach (['id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', "site_key VARCHAR(64) NOT NULL DEFAULT ''", 'layout_json MEDIUMTEXT NULL', 'version INT UNSIGNED NOT NULL DEFAULT 1', 'updated_by INT UNSIGNED NULL', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'UNIQUE KEY uniq_admin_menu_layouts_site (site_key)'] as $definition) {
        T::check(str_contains($sql, $definition), 'Spalte bzw. Schlüssel: ' . $definition);
    }

    $batchSql = SchemaManager::getSchemaQueries('cms_', 'utf8mb4')[BatchJobService::TABLE] ?? '';
    if (T::check($batchSql !== '', 'SchemaManager::getSchemaQueries() enthält die Tabelle ' . BatchJobService::TABLE . ' (Neuinstallation)')) {
        T::check(str_contains($batchSql, 'CREATE TABLE IF NOT EXISTS cms_batch_jobs'), 'batch_jobs: Anlage idempotent und mit Tabellenpräfix');
        foreach (['job_type VARCHAR(60) NOT NULL', "scope_key VARCHAR(120) NOT NULL DEFAULT ''", "status VARCHAR(20) NOT NULL DEFAULT 'pending'", 'state_json LONGTEXT NULL', 'lock_token CHAR(32) NULL', 'locked_until DATETIME NULL', 'INDEX idx_batch_jobs_lookup (job_type, scope_key, status)', 'INDEX idx_batch_jobs_updated (updated_at)'] as $definition) {
            T::check(str_contains($batchSql, $definition), 'batch_jobs: Spalte bzw. Schlüssel: ' . $definition);
        }
    }

    // Update und Laufzeitprüfung verwenden dieselbe Definition; kein Dienst legt eine eigene Fassung an.
    $migration = (string) file_get_contents(ABSPATH . 'core/MigrationManager.php');
    $runtimeChecks = [
        LayoutRepository::TABLE => 'core/Services/AdminMenu/LayoutRepository.php',
        BatchJobService::TABLE => 'core/Services/BatchJobService.php',
    ];
    foreach ($runtimeChecks as $table => $file) {
        $service = basename($file, '.php');
        T::check(str_contains($migration, "SchemaManager::getSchemaQueries(\$p, \$charset)['{$table}']"), "MigrationManager legt {$table} beim Update mit derselben Definition an");
        $source = (string) file_get_contents(ABSPATH . $file);
        T::check(str_contains($source, 'SchemaManager::getSchemaQueries($db->getPrefix(), $charset)[self::TABLE]'), "{$service}::ensureSchema() nutzt dieselbe Definition (Laufzeitprüfung)");
        T::check(!str_contains($source, 'CREATE TABLE'), "{$service} enthält keine eigene Tabellendefinition");
    }
});

T::finish();
