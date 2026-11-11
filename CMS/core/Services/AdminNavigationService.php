<?php
declare(strict_types=1);

namespace CMS\Services;

use CMS\Services\AdminMenu\AdminMenuItem;
use CMS\Services\AdminMenu\AdminMenuService;
use CMS\Services\AdminMenu\BadgeProvider;
use CMS\Services\AdminMenu\CoreCatalog;
use CMS\Services\AdminMenu\LayoutValidator;
use CMS\Services\AdminMenu\MenuBuilder;
use CMS\Services\AdminMenu\MenuRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Navigationsmodell der Admin-Seitenleiste – Darstellungsschicht über dem datengetriebenen Admin-Menü.
 *
 * Der Menübaum kommt aus AdminMenuService (Core-Katalog, registrierte Plugin-Menüs und aktives Profil
 * bzw. eigenes Layout; gecacht). Dieser Dienst ergänzt nur den nicht cachebaren Teil – aktiven Eintrag,
 * Badges, Aufklappzustand und Brotkrumen – und liefert das Format für admin/partials/sidebar-nav.php.
 * Die Plugin-Registrierung (Hook `cms_admin_menu`, add_menu_page()/add_submenu_page()) wird nur gelesen.
 */
final class AdminNavigationService
{
    public const DEFAULT_PLUGIN_ICON = MenuRegistry::DEFAULT_PLUGIN_ICON;
    public const DOM_PREFIX = 'cms-nav-';

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public static function normalizeActivePage(string $activePage): string
    {
        return match (trim($activePage)) {
            'theme-settings' => 'settings',
            'system-info' => 'info',
            'support' => 'documentation',
            'privacy-requests', 'deletion-requests' => 'data-requests',
            default => trim($activePage),
        };
    }

    /**
     * @param array<int|string, mixed> $registeredPluginMenus Unverändertes Ergebnis von get_registered_admin_menus()
     * @param array{site_url?:string,request_path?:string,site_key?:string,site_label?:string,group_states?:array<string,bool>,logs_badge?:int,page_title?:string} $context
     * @return array{groups:list<array<string,mixed>>,breadcrumb:array{section:string,page:string},recent_label:string,plugins_total:int,plugins_hidden:int,profile:string,scope:string}
     */
    public function build(string $activePage, array $registeredPluginMenus, array $context = []): array
    {
        $siteUrl = rtrim((string) ($context['site_url'] ?? ''), '/');
        $resolved = AdminMenuService::getInstance()->tree($registeredPluginMenus, (string) ($context['site_key'] ?? ''), $siteUrl);

        $model = $this->present($resolved['tree'], $resolved['registry'], $activePage, $context);
        $model['profile'] = $resolved['active']['profile'];
        $model['scope'] = $resolved['active']['scope'];

        return $model;
    }

    /**
     * Wandelt einen MenuBuilder-Baum in das Sidebar-Modell um (auch für die Vorschau im Einstellungs-Tab).
     *
     * Zusätzliche Kontext-Schlüssel: `dom_prefix` (eigene DOM-IDs, z. B. für die Vorschau), `badges` (false =
     * keine Zähler abfragen), `expand_all` (alles aufgeklappt, gespeicherte Zustände ignorieren).
     *
     * @param array<string, mixed> $tree Ausgabe von MenuBuilder::build()
     * @param array<string, mixed> $context
     * @return array{groups:list<array<string,mixed>>,breadcrumb:array{section:string,page:string},recent_label:string,plugins_total:int,plugins_hidden:int}
     */
    public function present(array $tree, MenuRegistry $registry, string $activePage, array $context = []): array
    {
        $activePage = self::normalizeActivePage($activePage);
        $siteUrl = rtrim((string) ($context['site_url'] ?? ''), '/');
        $expandAll = !empty($context['expand_all']);
        $groupStates = !$expandAll && is_array($context['group_states'] ?? null) ? $context['group_states'] : [];
        $siteLabel = trim((string) ($context['site_label'] ?? ($context['site_key'] ?? '')));
        $domPrefix = is_string($context['dom_prefix'] ?? null) && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $context['dom_prefix']) === 1
            ? $context['dom_prefix']
            : self::DOM_PREFIX;
        $sections = is_array($tree['sections'] ?? null) ? $tree['sections'] : [];

        $current = $this->findCurrent($sections, (string) ($context['request_path'] ?? ''), $activePage);
        $counts = ($context['badges'] ?? true) !== false ? $this->badgeCounts($sections, $context) : [];
        $badge = static fn (array $keys): ?array => self::badgeFor($keys, $counts);

        $hintSection = is_array($tree['plugin_hint'] ?? null) ? (string) ($tree['plugin_hint']['section'] ?? '') : '';
        $breadcrumb = ['section' => 'Dashboard', 'page' => trim((string) ($context['page_title'] ?? '')) ?: 'Übersicht'];
        $recentLabel = '';
        $groups = [];

        foreach ($sections as $section) {
            $sectionId = 'g:' . $this->idFragment((string) ($section['id'] ?? 'section'));
            $plain = !empty($section['plain']);
            // „Aktive Plugins“ ist standardmäßig eingeklappt; nur ein gespeichertes „offen“ oder die aktuelle Seite öffnen ihn.
            $isActivePlugins = ($section['id'] ?? '') === MenuBuilder::ACTIVE_PLUGINS_SECTION_ID;
            $entries = [];
            $hint = null;
            if ($hintSection !== '' && ($section['id'] ?? '') === $hintSection) {
                $hint = [
                    'text' => sprintf('Für %s sind keine Plugins mit Menüeinträgen zugewiesen.', $siteLabel !== '' ? $siteLabel : 'diese Site'),
                    'url' => $siteUrl . PluginSiteAssignmentService::ADMIN_PATH,
                    'link' => 'Plugin-Zuweisung öffnen',
                ];
            }

            foreach ((array) ($section['nodes'] ?? []) as $node) {
                if (($node['kind'] ?? '') === 'item') {
                    $isCurrent = $current['item'] !== '' && $node['id'] === $current['item'];
                    $entries[] = $this->itemEntry($node, $siteUrl, $domPrefix, $isCurrent, $badge([(string) $node['badge']]));
                    if ($isCurrent) {
                        $breadcrumb = ['section' => $isActivePlugins ? (string) ($section['label'] ?? '') : '', 'page' => (string) $node['label']];
                        $recentLabel = (string) $node['label'];
                    }
                    continue;
                }

                $items = (array) ($node['items'] ?? []);
                if ($items === []) {
                    continue;
                }

                $badgeKeys = array_map(static fn (array $child): string => (string) ($child['badge'] ?? ''), $items);

                if (($node['display'] ?? '') === LayoutValidator::DISPLAY_TILES) {
                    $currentChild = null;
                    foreach ($items as $child) {
                        if ($current['item'] !== '' && $child['id'] === $current['item']) {
                            $currentChild = $child;
                            break;
                        }
                    }
                    $isPage = $current['tiles'] !== '' && $node['id'] === $current['tiles'];
                    $entries[] = $this->tilesEntry($node, $siteUrl, $domPrefix, $isPage || $currentChild !== null, $isPage, $badge($badgeKeys));
                    if ($currentChild !== null) {
                        $breadcrumb = ['section' => (string) $node['label'], 'page' => (string) $currentChild['label']];
                        $recentLabel = $node['label'] . ' · ' . $currentChild['label'];
                    } elseif ($isPage) {
                        $breadcrumb = ['section' => '', 'page' => (string) $node['label']];
                        $recentLabel = (string) $node['label'];
                    }
                    continue;
                }

                $entryId = $this->groupEntryId((string) $node['id']);
                $isPlugin = str_starts_with((string) $node['id'], MenuBuilder::PLUGIN_AUTO_GROUP_PREFIX);
                $children = [];
                $active = false;
                foreach ($items as $child) {
                    $isCurrent = $current['item'] !== '' && $child['id'] === $current['item'];
                    $children[] = [
                        'id' => (string) $child['id'],
                        'label' => (string) $child['label'],
                        'slug' => (string) $child['page'],
                        'url' => $siteUrl . (string) $child['route'],
                        'path' => AdminMenuItem::splitRoute((string) $child['route'])['path'],
                        'current' => $isCurrent,
                        'badge' => $badge([(string) $child['badge']]),
                        'plugin' => !empty($child['plugin']),
                        'external' => !empty($child['external']),
                    ];
                    if ($isCurrent) {
                        $active = true;
                        $breadcrumb = ['section' => (string) $node['label'], 'page' => (string) $child['label']];
                        $recentLabel = $node['label'] !== $child['label'] ? $node['label'] . ' · ' . $child['label'] : (string) $child['label'];
                    }
                }

                $parts = $isPlugin ? $this->splitPluginTitle((string) $node['label']) : ['prefix' => '', 'name' => (string) $node['label']];
                $stored = $groupStates[$entryId] ?? null;
                $entries[] = [
                    'id' => $entryId,
                    'label' => (string) $node['label'],
                    'prefix' => $parts['prefix'],
                    'name' => $parts['name'],
                    'slug' => '',
                    'url' => null,
                    'path' => '',
                    'icon' => $node['icon'],
                    'children' => $children,
                    'current' => false,
                    'aria_current' => '',
                    'badge' => $badge($badgeKeys),
                    'plugin' => $isPlugin,
                    'tiles' => false,
                    'external' => false,
                    'dom_id' => $this->domId($domPrefix, $entryId),
                    'active' => $active,
                    'expanded' => $active || $expandAll || $stored === true,
                ];
            }

            if ($entries === [] && $hint === null) {
                continue;
            }

            $sectionActive = false;
            foreach ($entries as $entry) {
                $sectionActive = $sectionActive || $entry['active'];
            }
            $stored = $groupStates[$sectionId] ?? null;
            $groups[] = [
                'id' => $sectionId,
                'label' => (string) ($section['label'] ?? ''),
                'plain' => $plain,
                'plugins' => $isActivePlugins,
                'count' => $isActivePlugins ? count($entries) : 0,
                'entries' => $entries,
                'hint' => $hint,
                'dom_id' => $this->domId($domPrefix, $sectionId),
                'active' => $sectionActive,
                'expanded' => $isActivePlugins
                    ? $sectionActive || $expandAll || $stored === true
                    : $plain || $sectionActive || $expandAll || !is_bool($stored) || $stored,
            ];
        }

        return [
            'groups' => $groups,
            'breadcrumb' => $breadcrumb,
            'recent_label' => $recentLabel,
            'plugins_total' => $registry->pluginTotal(),
            'plugins_hidden' => $registry->pluginHidden(),
        ];
    }

    /**
     * @param array<string, mixed> $node Eintrag aus MenuBuilder::build()
     * @param array{label:string,sr:string,count:int}|null $badge
     * @return array<string, mixed>
     */
    private function itemEntry(array $node, string $siteUrl, string $domPrefix, bool $isCurrent, ?array $badge): array
    {
        $id = $this->itemEntryId((string) $node['id']);
        $isPlugin = !empty($node['plugin']);
        $parts = $isPlugin ? $this->splitPluginTitle((string) $node['label']) : ['prefix' => '', 'name' => (string) $node['label']];

        return [
            'id' => $id,
            'label' => (string) $node['label'],
            'prefix' => $parts['prefix'],
            'name' => $parts['name'],
            'slug' => (string) $node['page'],
            'url' => $siteUrl . (string) $node['route'],
            'path' => AdminMenuItem::splitRoute((string) $node['route'])['path'],
            'icon' => $node['icon'],
            'children' => [],
            'current' => $isCurrent,
            'aria_current' => $isCurrent ? 'page' : '',
            'badge' => $badge,
            'plugin' => $isPlugin,
            'tiles' => false,
            'external' => !empty($node['external']),
            'dom_id' => $this->domId($domPrefix, $id),
            'active' => $isCurrent,
            'expanded' => false,
        ];
    }

    /**
     * Kachelgruppe = ein Link auf ihre Kachelseite; aktiv auf der Kachelseite und auf jeder ihrer Seiten.
     *
     * @param array<string, mixed> $node
     * @param array{label:string,sr:string,count:int}|null $badge
     * @return array<string, mixed>
     */
    private function tilesEntry(array $node, string $siteUrl, string $domPrefix, bool $isCurrent, bool $isPage, ?array $badge): array
    {
        $id = 'e:' . $this->idFragment((string) $node['id']);

        return [
            'id' => $id,
            'label' => (string) $node['label'],
            'prefix' => '',
            'name' => (string) $node['label'],
            'slug' => '',
            'url' => $siteUrl . (string) $node['route'],
            'path' => AdminMenuItem::splitRoute((string) $node['route'])['path'],
            'icon' => $node['icon'],
            'children' => [],
            'current' => $isCurrent,
            'aria_current' => $isCurrent ? ($isPage ? 'page' : 'true') : '',
            'badge' => $badge,
            'plugin' => false,
            'tiles' => true,
            'external' => false,
            'dom_id' => $this->domId($domPrefix, $id),
            'active' => $isCurrent,
            'expanded' => false,
        ];
    }

    /**
     * Kachelseite einer Gruppe im Anzeigemodus „tiles“ (/admin/configuration[?group=<id>]),
     * gegliedert nach Kategorie in der Reihenfolge des Layouts.
     *
     * @param array<string, mixed> $tree    MenuBuilder-Ausgabe (derselbe Baum wie die Sidebar)
     * @param array<string, mixed> $context site_url, logs_badge, badges (false = ohne Zahlen)
     * @return array{
     *     id:string, label:string, icon:array<string, mixed>, route:string, badge:array{label:string,sr:string,count:int}|null,
     *     categories:list<array{id:string, label:string, tiles:list<array<string, mixed>>}>
     * }|null null, wenn die Gruppe im Baum fehlt, keine Kachelgruppe ist oder keine sichtbaren Einträge hat
     */
    public function tilesPage(array $tree, string $groupId, array $context = []): ?array
    {
        $node = null;
        foreach ((array) ($tree['sections'] ?? []) as $section) {
            foreach ((array) ($section['nodes'] ?? []) as $candidate) {
                if (($candidate['kind'] ?? '') === 'group' && (string) ($candidate['id'] ?? '') === $groupId) {
                    $node = $candidate;
                    break 2;
                }
            }
        }
        $items = is_array($node['items'] ?? null) ? $node['items'] : [];
        if ($node === null || ($node['display'] ?? '') !== LayoutValidator::DISPLAY_TILES || $items === []) {
            return null;
        }

        $siteUrl = rtrim((string) ($context['site_url'] ?? ''), '/');
        $badgeKeys = array_map(static fn (array $item): string => (string) ($item['badge'] ?? ''), $items);
        $counts = [];
        $wanted = array_values(array_unique(array_filter($badgeKeys, static fn (string $key): bool => $key !== '')));
        if ($wanted !== [] && ($context['badges'] ?? true) !== false) {
            try {
                $counts = (new BadgeProvider())->counts($wanted, ['logs' => max(0, (int) ($context['logs_badge'] ?? 0))]);
            } catch (\Throwable) {
                $counts = [];
            }
        }

        $categories = [];
        foreach ($items as $item) {
            $category = (string) ($item['category'] ?? '');
            $category = $category !== '' ? $category : 'other';
            if (!isset($categories[$category])) {
                $label = trim((string) ($item['category_label'] ?? ''));
                $categories[$category] = [
                    'id' => $category,
                    'label' => $label !== '' ? $label : CoreCatalog::CATEGORY_LABELS['other'],
                    'tiles' => [],
                ];
            }
            $tileLabel = trim((string) ($item['tile_label'] ?? ''));
            $categories[$category]['tiles'][] = [
                'id' => (string) $item['id'],
                'label' => $tileLabel !== '' ? $tileLabel : (string) $item['label'],
                'description' => (string) ($item['description'] ?? ''),
                'url' => $siteUrl . (string) $item['route'],
                'icon' => is_array($item['icon'] ?? null) ? $item['icon'] : ['type' => 'tabler', 'name' => self::DEFAULT_PLUGIN_ICON],
                'badge' => self::badgeFor([(string) ($item['badge'] ?? '')], $counts),
                'plugin' => !empty($item['plugin']),
                'external' => !empty($item['external']),
            ];
        }

        return [
            'id' => $groupId,
            'label' => (string) $node['label'],
            'icon' => is_array($node['icon'] ?? null) ? $node['icon'] : ['type' => 'tabler', 'name' => 'layout-grid'],
            'route' => (string) ($node['route'] ?? ''),
            'badge' => self::badgeFor($badgeKeys, $counts),
            'categories' => array_values($categories),
        ];
    }

    /**
     * Summen-Badge für die Schlüssel eines Eintrags bzw. einer Gruppe (jeder Schlüssel einmal).
     *
     * @param list<string> $keys
     * @param array<string, int> $counts
     * @return array{label:string,sr:string,count:int}|null
     */
    private static function badgeFor(array $keys, array $counts): ?array
    {
        $selected = [];
        foreach (array_unique($keys) as $key) {
            if ($key !== '' && ($counts[$key] ?? 0) > 0) {
                $selected[$key] = $counts[$key];
            }
        }

        return $selected !== [] ? BadgeProvider::format($selected) : null;
    }

    /**
     * IDs für den gespeicherten Aufklappzustand (AdminUiPreferenceService::GROUP_ID_PATTERN):
     * Plugin-Einträge behalten das bisherige Format `p:<menu>`.
     */
    private function itemEntryId(string $itemId): string
    {
        $pluginKey = MenuRegistry::pluginMenuKeyOf($itemId);
        if ($pluginKey !== null && $itemId === 'plugin:' . $pluginKey) {
            return 'p:' . $this->idFragment($pluginKey);
        }

        return 'e:' . $this->idFragment(str_starts_with($itemId, 'plugin:') ? 'p.' . substr($itemId, 7) : $itemId);
    }

    private function groupEntryId(string $groupId): string
    {
        if (str_starts_with($groupId, MenuBuilder::PLUGIN_AUTO_GROUP_PREFIX)) {
            return 'p:' . $this->idFragment(substr($groupId, strlen(MenuBuilder::PLUGIN_AUTO_GROUP_PREFIX)));
        }

        return 'e:' . $this->idFragment($groupId);
    }

    /**
     * Aktueller Eintrag, in dieser Reihenfolge:
     * 1. Route mit Query-Parametern (alle müssen passen; die spezifischste gewinnt, z. B. `?tab=mail`)
     * 2. Plugin-Seite per Pfad
     * 3. Core-Seite per `$activePage` (bei mehreren Einträgen der mit passendem Pfad)
     * 4. Core-Seite oder Kachelseite per Pfad
     * 5. Plugin-Seite per `$activePage` (bisheriges Verhalten)
     *
     * @param list<array<string, mixed>> $sections
     * @return array{item:string, tiles:string} Katalog-ID der aktuellen Seite bzw. ID der aufgerufenen Kachelgruppe
     */
    private function findCurrent(array $sections, string $request, string $activePage): array
    {
        $requestPath = $this->normalizePath($request);
        $requestQuery = [];
        parse_str((string) parse_url($request, PHP_URL_QUERY), $requestQuery);

        $candidates = [];
        $add = static function (array $node, string $tilesId = '') use (&$candidates): void {
            $parts = AdminMenuItem::splitRoute((string) ($node['route'] ?? ''));
            $candidates[] = [
                'item' => $tilesId === '' ? (string) ($node['id'] ?? '') : '',
                'tiles' => $tilesId,
                'path' => $parts['path'],
                'query' => $parts['query'],
                'page' => (string) ($node['page'] ?? ''),
                'plugin' => !empty($node['plugin']),
                'external' => !empty($node['external']),
            ];
        };
        foreach ($sections as $section) {
            foreach ((array) ($section['nodes'] ?? []) as $node) {
                if (($node['kind'] ?? '') === 'item') {
                    $add($node);
                    continue;
                }
                if (($node['display'] ?? '') === LayoutValidator::DISPLAY_TILES && (string) ($node['route'] ?? '') !== '') {
                    $add($node, (string) $node['id']);
                }
                foreach ((array) ($node['items'] ?? []) as $child) {
                    $add($child);
                }
            }
        }

        $found = static fn (array $candidate): array => ['item' => $candidate['item'], 'tiles' => $candidate['tiles']];
        $pathMatches = static fn (array $candidate): bool => !$candidate['external'] && $requestPath !== '' && $candidate['path'] === $requestPath;
        $queryMatches = static function (array $candidate) use ($requestQuery): bool {
            foreach ($candidate['query'] as $key => $value) {
                if (!isset($requestQuery[$key]) || !is_scalar($requestQuery[$key]) || (string) $requestQuery[$key] !== $value) {
                    return false;
                }
            }

            return true;
        };

        $best = null;
        foreach ($candidates as $candidate) {
            if ($candidate['query'] !== [] && $pathMatches($candidate) && $queryMatches($candidate)
                && ($best === null || count($candidate['query']) > count($best['query']))) {
                $best = $candidate;
            }
        }
        if ($best !== null) {
            return $found($best);
        }

        foreach ($candidates as $candidate) {
            if ($candidate['plugin'] && $candidate['query'] === [] && $pathMatches($candidate)) {
                return $found($candidate);
            }
        }

        if ($activePage !== '') {
            $first = null;
            foreach ($candidates as $candidate) {
                if ($candidate['plugin'] || $candidate['tiles'] !== '' || $candidate['external'] || $candidate['page'] !== $activePage) {
                    continue;
                }
                if ($candidate['query'] === [] && $pathMatches($candidate)) {
                    return $found($candidate);
                }
                $first ??= $candidate;
            }
            if ($first !== null) {
                return $found($first);
            }
        }

        foreach ($candidates as $candidate) {
            if (!$candidate['plugin'] && $candidate['query'] === [] && $pathMatches($candidate)) {
                return $found($candidate);
            }
        }

        if ($activePage !== '') {
            foreach ($candidates as $candidate) {
                if ($candidate['plugin'] && $candidate['page'] === $activePage) {
                    return $found($candidate);
                }
            }
        }

        return ['item' => '', 'tiles' => ''];
    }

    /**
     * Badge-Zahlen der sichtbaren Einträge – bei jedem Aufruf frisch (nie Teil des Menü-Caches).
     *
     * @param list<array<string, mixed>> $sections
     * @param array<string, mixed> $context
     * @return array<string, int>
     */
    private function badgeCounts(array $sections, array $context): array
    {
        $keys = [];
        foreach ($sections as $section) {
            foreach ((array) ($section['nodes'] ?? []) as $node) {
                if (($node['kind'] ?? '') === 'item') {
                    $keys[(string) ($node['badge'] ?? '')] = true;
                    continue;
                }
                foreach ((array) ($node['items'] ?? []) as $child) {
                    $keys[(string) ($child['badge'] ?? '')] = true;
                }
            }
        }
        unset($keys['']);
        if ($keys === []) {
            return [];
        }

        try {
            return (new BadgeProvider())->counts(array_keys($keys), ['logs' => max(0, (int) ($context['logs_badge'] ?? 0))]);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{prefix:string,name:string}
     */
    private function splitPluginTitle(string $title): array
    {
        if (preg_match('/^\s*([^|]{1,16}?)\s*\|\s*(\S.*)$/u', $title, $matches) === 1) {
            return ['prefix' => trim($matches[1]), 'name' => trim($matches[2])];
        }

        return ['prefix' => '', 'name' => $title];
    }

    private function idFragment(string $value): string
    {
        $fragment = trim((string) preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $value), '-');

        return substr($fragment !== '' ? $fragment : substr(sha1($value), 0, 12), 0, 100);
    }

    private function domId(string $prefix, string $id): string
    {
        return $prefix . trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $id), '-');
    }

    private function normalizePath(string $path): string
    {
        $path = (string) parse_url($path, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }

        $path = '/' . ltrim($path, '/');

        return $path !== '/' ? rtrim($path, '/') : $path;
    }
}
