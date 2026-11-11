<?php
/**
 * Plugin-Zuweisung je Site (Einstellungen › Plugins › Plugin-Zuweisung).
 *
 * Reine Darstellungsschicht: Die Plugin-Menüregistrierung (`cms_admin_menu`, add_menu_page(),
 * add_submenu_page(), get_registered_admin_menus()) bleibt unverändert. Die Seitenleiste liest die
 * bereits registrierten Einträge und filtert sie anschließend anhand dieser Zuweisung.
 *
 * Speicherung: Option `admin_plugin_site_assignments` als JSON `{plugin-slug: [site-key, …]}`.
 * Plugins ohne gespeicherten Eintrag gelten als allen Sites zugewiesen (Standard nach dem Update).
 *
 * @package 365CMS
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Json;
use CMS\PluginManager;

if (!defined('ABSPATH')) {
    exit;
}

final class PluginSiteAssignmentService
{
    public const OPTION = 'admin_plugin_site_assignments';
    public const ADMIN_PATH = '/admin/plugin-assignment';

    private static ?self $instance = null;

    /** @var array<string, list<string>>|null */
    private ?array $assignments = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $plugins = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * Installierte Plugins (Slug => Header-Daten), alphabetisch nach Name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getInstalledPlugins(): array
    {
        if ($this->plugins !== null) {
            return $this->plugins;
        }

        try {
            $plugins = PluginManager::instance()->getAvailablePlugins();
        } catch (\Throwable) {
            $plugins = [];
        }

        uasort($plugins, static fn (array $a, array $b): int => strnatcasecmp(
            (string) ($a['name'] ?? ''),
            (string) ($b['name'] ?? '')
        ));

        return $this->plugins = $plugins;
    }

    /**
     * Gespeicherte (abweichende) Zuweisungen.
     *
     * @return array<string, list<string>>
     */
    public function getStoredAssignments(): array
    {
        if ($this->assignments !== null) {
            return $this->assignments;
        }

        $raw = function_exists('get_option') ? get_option(self::OPTION, '') : '';
        $decoded = is_array($raw) ? $raw : Json::decodeArray(is_string($raw) ? $raw : '', []);
        $sites = AdminSiteContextService::getInstance()->getSites();

        $assignments = [];
        foreach ($decoded as $slug => $siteKeys) {
            $slug = $this->normalizeSlug((string) $slug);
            if ($slug === '' || !is_array($siteKeys)) {
                continue;
            }
            $assignments[$slug] = array_values(array_unique(array_filter(
                array_map(static fn ($key): string => strtolower(trim((string) $key)), $siteKeys),
                static fn (string $key): bool => isset($sites[$key])
            )));
        }

        return $this->assignments = $assignments;
    }

    /**
     * @return list<string> Site-Keys, denen das Plugin zugewiesen ist (Standard: alle)
     */
    public function getAssignedSites(string $pluginSlug): array
    {
        $pluginSlug = $this->normalizeSlug($pluginSlug);
        $stored = $this->getStoredAssignments();

        return $stored[$pluginSlug] ?? array_keys(AdminSiteContextService::getInstance()->getSites());
    }

    public function isAssigned(string $pluginSlug, string $siteKey): bool
    {
        return in_array($siteKey, $this->getAssignedSites($pluginSlug), true);
    }

    /**
     * Speichert die Matrix für die übergebenen Plugins (Standard: alle installierten). Plugins, die
     * allen Sites zugewiesen sind, werden nicht gespeichert (Standardverhalten); Plugins außerhalb
     * von `$pluginSlugs` – etwa nach dem Laden des Formulars installierte – behalten ihren Stand.
     *
     * @param array<string, mixed> $matrix plugin-slug => list<site-key>
     * @param list<string>|null $pluginSlugs im Formular enthaltene Plugins
     */
    public function saveAssignments(array $matrix, ?array $pluginSlugs = null): bool
    {
        $sites = array_keys(AdminSiteContextService::getInstance()->getSites());
        $stored = $this->getStoredAssignments();
        $installed = $this->getInstalledPlugins();

        foreach ($pluginSlugs ?? array_keys($installed) as $slug) {
            $slug = is_string($slug) || is_int($slug) ? $this->normalizeSlug((string) $slug) : '';
            if ($slug === '' || !isset($installed[$slug])) {
                continue;
            }

            $requested = is_array($matrix[$slug] ?? null) ? $matrix[$slug] : [];
            $selected = array_values(array_intersect($sites, array_map(
                static fn (string $key): string => strtolower(trim($key)),
                array_filter($requested, 'is_string')
            )));

            if (count($selected) === count($sites)) {
                unset($stored[$slug]);
                continue;
            }

            $stored[$slug] = $selected;
        }

        return $this->persist($stored);
    }

    /**
     * Entfernt Verweise auf nicht mehr vorhandene Sites (nach dem Löschen unter Einstellungen ›
     * MultiSites). Plugins, die danach allen verbleibenden Sites zugewiesen sind, gelten wieder als
     * Standard (kein gespeicherter Eintrag).
     */
    public function pruneUnknownSites(): bool
    {
        $this->assignments = null;
        $siteCount = count(AdminSiteContextService::getInstance()->getSites());
        $stored = $this->getStoredAssignments();

        foreach ($stored as $slug => $siteKeys) {
            if (count($siteKeys) === $siteCount) {
                unset($stored[$slug]);
            }
        }

        return $this->persist($stored);
    }

    /**
     * Entfernt gespeicherte Zuweisungen der angegebenen Plugins (z. B. nach der Integration in den Core).
     *
     * @param list<string> $pluginSlugs
     */
    public function removePlugins(array $pluginSlugs): bool
    {
        $this->assignments = null;
        $stored = $this->getStoredAssignments();
        $remaining = array_diff_key($stored, array_flip(array_map(fn (string $slug): string => $this->normalizeSlug($slug), $pluginSlugs)));

        return count($remaining) === count($stored) || $this->persist($remaining);
    }

    /**
     * @param array<string, list<string>> $stored
     */
    private function persist(array $stored): bool
    {
        ksort($stored);
        $saved = function_exists('update_option')
            && update_option(self::OPTION, $stored === [] ? '{}' : (string) json_encode($stored, JSON_UNESCAPED_SLASHES));

        $this->assignments = null;

        return $saved;
    }

    /**
     * Ordnet einen registrierten Menüeintrag seinem Plugin zu – ohne die Registrierung zu verändern.
     * 1. Datei des registrierten Callables liegt unter PLUGIN_PATH/<slug>/
     * 2. Fallback: menu_slug entspricht einem Plugin-Slug oder beginnt mit "<slug>-"
     *
     * @param array<string, mixed> $menu
     */
    public function resolveMenuPluginSlug(array $menu): ?string
    {
        $known = $this->getInstalledPlugins();

        $callables = [$menu['callable'] ?? null];
        foreach ((array) ($menu['children'] ?? []) as $child) {
            if (is_array($child)) {
                $callables[] = $child['callable'] ?? null;
            }
        }

        foreach ($callables as $callable) {
            $slug = $this->pluginSlugFromCallable($callable);
            if ($slug !== null && isset($known[$slug])) {
                return $slug;
            }
        }

        $menuSlug = $this->normalizeSlug((string) ($menu['menu_slug'] ?? ''));
        if ($menuSlug === '') {
            return null;
        }
        if (isset($known[$menuSlug])) {
            return $menuSlug;
        }

        $best = null;
        foreach (array_keys($known) as $slug) {
            $slug = (string) $slug;
            if (str_starts_with($menuSlug, $slug . '-') && ($best === null || strlen($slug) > strlen($best))) {
                $best = $slug;
            }
        }

        return $best;
    }

    /**
     * Plugin-Slug des Plugin-Menüs der aktuellen Anfrage (/admin/plugins/<menu>/<seite>), sonst null.
     */
    public function resolveRequestPluginSlug(): ?string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (preg_match('~^/admin/plugins/([A-Za-z0-9_-]+)/[A-Za-z0-9_-]+/?$~', $path, $match) !== 1) {
            return null;
        }

        $menus = function_exists('get_registered_admin_menus') ? get_registered_admin_menus() : [];
        foreach ($menus as $menu) {
            if (is_array($menu) && (string) ($menu['menu_slug'] ?? '') === $match[1]) {
                return $this->resolveMenuPluginSlug($menu);
            }
        }

        $slug = $this->normalizeSlug($match[1]);

        return isset($this->getInstalledPlugins()[$slug]) ? $slug : null;
    }

    /**
     * Anzahl registrierter Menüeinträge (Hauptpunkt + Unterpunkte) je Plugin – nur lesend, setzt
     * voraus, dass `cms_admin_menu` bereits ausgelöst wurde (geschieht in der Seitenleiste).
     *
     * @return array<string, int>
     */
    public function countRegisteredMenuEntries(): array
    {
        $menus = function_exists('get_registered_admin_menus') ? get_registered_admin_menus() : [];
        $counts = [];

        foreach ($menus as $menu) {
            if (!is_array($menu)) {
                continue;
            }
            $slug = $this->resolveMenuPluginSlug($menu);
            if ($slug === null) {
                continue;
            }
            $children = array_filter((array) ($menu['children'] ?? []), 'is_array');
            $counts[$slug] = ($counts[$slug] ?? 0) + 1 + count($children);
        }

        return $counts;
    }

    public function getPluginName(string $slug): string
    {
        $plugin = $this->getInstalledPlugins()[$slug] ?? null;
        $name = trim((string) ($plugin['name'] ?? ''));

        return $name !== '' ? $name : $slug;
    }

    private function pluginSlugFromCallable(mixed $callable): ?string
    {
        if (!defined('PLUGIN_PATH')) {
            return null;
        }

        try {
            if (is_array($callable) && count($callable) === 2) {
                $target = $callable[0];
                if (!is_object($target) && !(is_string($target) && class_exists($target))) {
                    return null;
                }
                $file = (new \ReflectionClass($target))->getFileName();
            } elseif (is_string($callable) && str_contains($callable, '::')) {
                $class = explode('::', $callable, 2)[0];
                if (!class_exists($class)) {
                    return null;
                }
                $file = (new \ReflectionClass($class))->getFileName();
            } elseif ($callable instanceof \Closure || (is_string($callable) && function_exists($callable))) {
                $file = (new \ReflectionFunction($callable))->getFileName();
            } elseif (is_object($callable) && method_exists($callable, '__invoke')) {
                $file = (new \ReflectionClass($callable))->getFileName();
            } else {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($file) || $file === '') {
            return null;
        }

        $pluginRoot = realpath((string) PLUGIN_PATH);
        $realFile = realpath($file);
        if ($pluginRoot === false || $realFile === false) {
            return null;
        }

        $pluginRoot = rtrim(str_replace('\\', '/', $pluginRoot), '/') . '/';
        $realFile = str_replace('\\', '/', $realFile);
        if (!str_starts_with($realFile, $pluginRoot)) {
            return null;
        }

        $segment = explode('/', substr($realFile, strlen($pluginRoot)), 2)[0] ?? '';
        $slug = $this->normalizeSlug($segment);

        return $slug !== '' ? $slug : null;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $slug) === 1 ? $slug : '';
    }
}
