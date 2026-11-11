<?php
/**
 * Löst Registry + Layout + Rechte + Modul-/Marketplace-Zustand zum Menübaum auf.
 *
 * Rein funktional: Rechteprüfung, Modulstatus, Dateiprüfung und Übersetzung werden injiziert,
 * damit der Builder ohne Datenbank testbar ist. Die Ausgabe ist ein reines Array (cachebar);
 * URLs, aktiver Zustand und Badge-Zahlen werden erst nach dem Cache-Abruf ergänzt.
 *
 * Regeln:
 * - sichtbar = Seitendatei vorhanden, Modul aktiv, Marketplace-Bedingung erfüllt, Capability vorhanden
 *   und nicht ausgeblendet (gesperrte Einträge lassen sich nicht ausblenden)
 * - leere Gruppen und Abschnitte entfallen
 * - nicht platzierte Einträge gehen nie verloren: Core-Einträge in ihre Gruppe des Basisprofils, sonst in die
 *   Auffanggruppe bzw. „Weitere“; Plugin-Seiten zu einer bereits platzierten Seite desselben Plugins, in eine
 *   Layout-Gruppe `plugin.<menü>`, sonst in den letzten Abschnitt „Aktive Plugins“ (eine Seite als Link,
 *   mehrere als eigene Gruppe, alphabetisch; die Seitenleiste zeigt ihn standardmäßig eingeklappt)
 *
 * @package CMSv2\Core\Services\AdminMenu
 */

declare(strict_types=1);

namespace CMS\Services\AdminMenu;

if (!defined('ABSPATH')) {
    exit;
}

final class MenuBuilder
{
    public const CONFIGURATION_ROUTE = '/admin/configuration';
    public const MAIN_SECTION_ID = 'main';
    public const MORE_GROUP_ID = 'more';
    public const PLUGIN_AUTO_GROUP_PREFIX = 'plugin.';
    /** Abschnitt „Aktive Plugins“; der führende Unterstrich verhindert Kollisionen mit Trenner-IDs eines Layouts. */
    public const ACTIVE_PLUGINS_SECTION_ID = '_active-plugins';

    /** Gründe aus hiddenReason(). */
    public const HIDDEN_FILE = 'file';
    public const HIDDEN_MODULE = 'module';
    public const HIDDEN_MARKETPLACE = 'marketplace';
    public const HIDDEN_CAPABILITY = 'capability';

    /** @var \Closure(list<string>): bool */
    private \Closure $can;
    /** @var \Closure(string): bool */
    private \Closure $isPageEnabled;
    /** @var \Closure(string): bool */
    private \Closure $fileExists;
    /** @var \Closure(string, string): string */
    private \Closure $translate;

    /**
     * @param callable(list<string>): bool  $can           true, wenn eine der Capabilities vorhanden ist
     * @param callable(string): bool        $isPageEnabled Modulstatus je Seiten-Slug (CoreModuleService)
     * @param callable(string): bool        $fileExists    Seitendatei relativ zu ABSPATH vorhanden
     * @param (callable(string, string): string)|null $translate Schlüssel, deutscher Fallback → Text
     */
    public function __construct(
        callable $can,
        callable $isPageEnabled,
        callable $fileExists,
        private readonly bool $marketplaceEnabled = true,
        ?callable $translate = null,
    ) {
        $this->can = \Closure::fromCallable($can);
        $this->isPageEnabled = \Closure::fromCallable($isPageEnabled);
        $this->fileExists = \Closure::fromCallable($fileExists);
        $this->translate = $translate !== null
            ? \Closure::fromCallable($translate)
            : static fn (string $key, string $fallback): string => $fallback;
    }

    public function isVisible(AdminMenuItem $item): bool
    {
        return $this->hiddenReason($item) === null;
    }

    /**
     * Grund, warum ein Eintrag nicht erscheint (null = sichtbar); der Menü-Editor zeigt ihn als Hinweis.
     *
     * @return self::HIDDEN_*|null
     */
    public function hiddenReason(AdminMenuItem $item): ?string
    {
        if ($item->file !== '' && !($this->fileExists)($item->file)) {
            return self::HIDDEN_FILE;
        }
        if ($item->pageSlug !== '' && !$item->isPlugin() && !($this->isPageEnabled)($item->pageSlug)) {
            return self::HIDDEN_MODULE;
        }
        if ($item->requiresMarketplace && !$this->marketplaceEnabled) {
            return self::HIDDEN_MARKETPLACE;
        }
        if ($item->capabilities !== [] && !($this->can)($item->capabilities)) {
            return self::HIDDEN_CAPABILITY;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $layout  Validiertes Layout (LayoutValidator)
     * @param string               $profile classic | structured | custom
     * @return array{
     *     profile:string, base:string, root_group:string, fallback_group:string,
     *     sections:list<array<string, mixed>>, plugin_hint:array{section:string, hidden:int}|null
     * }
     */
    public function build(MenuRegistry $registry, array $layout, string $profile): array
    {
        $base = (string) ($layout['base'] ?? ProfileLayouts::CLASSIC);
        $isBuiltIn = in_array($profile, ProfileLayouts::PROFILES, true);
        $hidden = array_fill_keys(array_map('strval', (array) ($layout['hidden'] ?? [])), true);
        $fallbackGroup = (string) ($layout['fallback_group'] ?? '');
        $rootGroup = (string) ($layout['root_group'] ?? '');
        $items = $registry->items();

        // 1. Layout in Abschnitte mit ID-Listen überführen.
        $sections = [];
        $current = ['id' => self::MAIN_SECTION_ID, 'label' => '', 'plain' => true, 'nodes' => []];
        $placed = [];
        $groupIds = [];
        foreach ((array) ($layout['nodes'] ?? []) as $node) {
            $type = (string) ($node['type'] ?? '');
            if ($type === 'separator') {
                $sections[] = $current;
                $label = (string) ($node['label'] ?? '');
                $current = [
                    'id' => (string) $node['id'],
                    'label' => $isBuiltIn ? $this->t(ProfileLayouts::labelKey($profile, 'separator', (string) $node['id']), $label) : $label,
                    'plain' => $label === '',
                    'nodes' => [],
                ];
                continue;
            }
            if ($type === 'item') {
                $current['nodes'][] = ['kind' => 'item', 'id' => (string) $node['id']];
                $placed[(string) $node['id']] = '';
                continue;
            }
            if ($type === 'group') {
                $id = (string) $node['id'];
                $label = (string) ($node['label'] ?? $id);
                $current['nodes'][] = [
                    'kind' => 'group',
                    'id' => $id,
                    'label' => $isBuiltIn ? $this->t(ProfileLayouts::labelKey($profile, 'group', $id), $label) : $label,
                    'icon' => ['type' => 'tabler', 'name' => (string) ($node['icon'] ?? 'folder')],
                    'display' => (string) ($node['display'] ?? LayoutValidator::DISPLAY_MENU),
                    'items' => array_values(array_map('strval', (array) ($node['items'] ?? []))),
                    'auto' => false,
                ];
                $groupIds[$id] = true;
                foreach ((array) ($node['items'] ?? []) as $itemId) {
                    $placed[(string) $itemId] = $id;
                }
            }
        }
        $sections[] = $current;

        // 2. Nicht platzierte, sichtbare Einträge einsortieren.
        $append = [];
        $activePlugins = [];
        $moreCore = [];
        $pluginSiblingGroup = [];
        foreach ($placed as $itemId => $groupId) {
            $menuKey = MenuRegistry::pluginMenuKeyOf($itemId);
            if ($menuKey !== null && $groupId !== '' && !isset($pluginSiblingGroup[$menuKey])) {
                $pluginSiblingGroup[$menuKey] = $groupId;
            }
        }

        foreach ($items as $id => $item) {
            if (isset($placed[$id]) || isset($hidden[$id]) || $item->isPlugin()) {
                continue;
            }
            $target = ProfileLayouts::baseGroupOf($base, $id);
            if ($target !== null && isset($groupIds[$target])) {
                $append[$target][] = $id;
            } elseif ($fallbackGroup !== '') {
                $append[$fallbackGroup][] = $id;
            } else {
                $moreCore[] = $id;
            }
        }

        // Plugin-Seiten: Wunschplätze (MenuRegistry::preferredParent()) beeinflussen die Platzierung nicht mehr.
        foreach ($registry->pluginMenus() as $menuKey => $menu) {
            $unplaced = array_values(array_filter(
                $menu['items'],
                static fn (string $id): bool => !isset($placed[$id]) && !isset($hidden[$id])
            ));
            if ($unplaced === []) {
                continue;
            }

            $autoId = self::PLUGIN_AUTO_GROUP_PREFIX . $menuKey;
            $target = $pluginSiblingGroup[$menuKey] ?? (isset($groupIds[$autoId]) ? $autoId : null);
            if ($target !== null) {
                foreach ($unplaced as $id) {
                    $append[$target][] = $id;
                }
                continue;
            }

            if (count($menu['items']) === 1) {
                $activePlugins[] = ['kind' => 'item', 'id' => $unplaced[0], 'sort' => $items[$unplaced[0]]->label];
                continue;
            }
            $activePlugins[] = [
                'kind' => 'group',
                'id' => $autoId,
                'label' => $menu['label'],
                'icon' => $menu['icon'],
                'display' => LayoutValidator::DISPLAY_MENU,
                'items' => $unplaced,
                'auto' => true,
                'sort' => $menu['label'],
            ];
        }

        usort($activePlugins, fn (array $a, array $b): int => $this->compareLabels($a['sort'], $b['sort'], $a['id'], $b['id']));

        // 3. Anhängen, „Weitere“ und „Aktive Plugins“ ergänzen.
        foreach ($sections as &$section) {
            foreach ($section['nodes'] as &$node) {
                if ($node['kind'] === 'group' && isset($append[$node['id']])) {
                    $node['items'] = array_values(array_unique([...$node['items'], ...$append[$node['id']]]));
                }
            }
            unset($node);
        }
        unset($section);

        if ($moreCore !== []) {
            $sections[count($sections) - 1]['nodes'][] = [
                'kind' => 'group',
                'id' => self::MORE_GROUP_ID,
                'label' => $this->t('admin_menu.group.more', 'Weitere'),
                'icon' => ['type' => 'tabler', 'name' => 'dots-circle-horizontal'],
                'display' => LayoutValidator::DISPLAY_MENU,
                'items' => $moreCore,
                'auto' => true,
            ];
        }
        $sections[] = [
            'id' => self::ACTIVE_PLUGINS_SECTION_ID,
            'label' => $this->t('admin_menu.section.active_plugins', 'Aktive Plugins'),
            'plain' => false,
            'nodes' => array_map(static function (array $node): array {
                unset($node['sort']);

                return $node;
            }, $activePlugins),
        ];

        // 4. Sichtbarkeit anwenden und Ausgabe erzeugen.
        $labels = is_array($layout['labels'] ?? null) ? $layout['labels'] : [];
        $icons = is_array($layout['icons'] ?? null) ? $layout['icons'] : [];
        $resolve = function (string $id) use ($items, $hidden, $labels, $icons, $profile, $isBuiltIn): ?array {
            $item = $items[$id] ?? null;
            if ($item === null || (isset($hidden[$id]) && !$item->locked) || !$this->isVisible($item)) {
                return null;
            }

            return $this->itemNode($item, $labels[$id] ?? null, $icons[$id] ?? null, $profile, $isBuiltIn);
        };

        $output = [];
        $pluginVisible = 0;
        foreach ($sections as $section) {
            $nodes = [];
            foreach ($section['nodes'] as $node) {
                if ($node['kind'] === 'item') {
                    $resolved = $resolve($node['id']);
                    if ($resolved !== null) {
                        $pluginVisible += $resolved['plugin'] ? 1 : 0;
                        $nodes[] = $resolved;
                    }
                    continue;
                }

                $children = [];
                foreach ($node['items'] as $itemId) {
                    $resolved = $resolve($itemId);
                    if ($resolved !== null) {
                        $pluginVisible += $resolved['plugin'] ? 1 : 0;
                        $children[] = $resolved;
                    }
                }
                if ($children === []) {
                    continue;
                }

                $isTiles = $node['display'] === LayoutValidator::DISPLAY_TILES;
                $nodes[] = [
                    'kind' => 'group',
                    'id' => $node['id'],
                    'label' => $node['label'],
                    'icon' => $node['icon'],
                    'display' => $isTiles ? LayoutValidator::DISPLAY_TILES : LayoutValidator::DISPLAY_MENU,
                    'route' => $isTiles ? self::tilesRoute($node['id'], $rootGroup) : '',
                    'auto' => $node['auto'],
                    'items' => $children,
                ];
            }

            $output[] = ['id' => $section['id'], 'label' => $section['label'], 'plain' => $section['plain'], 'nodes' => $nodes];
        }

        // „Aktive Plugins“ ohne Einträge bleibt nur stehen, wenn der Abschnitt den Plugin-Hinweis trägt.
        $pluginHint = null;
        if ($registry->pluginTotal() > 0 && $pluginVisible === 0 && $registry->pluginHidden() > 0) {
            $pluginHint = ['section' => self::ACTIVE_PLUGINS_SECTION_ID, 'hidden' => $registry->pluginHidden()];
        }
        $output = array_values(array_filter(
            $output,
            static fn (array $section): bool => $section['nodes'] !== []
                || ($pluginHint !== null && $section['id'] === self::ACTIVE_PLUGINS_SECTION_ID)
        ));

        return [
            'profile' => $profile,
            'base' => $base,
            'root_group' => $rootGroup,
            'fallback_group' => $fallbackGroup,
            'sections' => $output,
            'plugin_hint' => $pluginHint,
        ];
    }

    public static function tilesRoute(string $groupId, string $rootGroup): string
    {
        return $groupId === $rootGroup && $rootGroup !== ''
            ? self::CONFIGURATION_ROUTE
            : self::CONFIGURATION_ROUTE . '?group=' . rawurlencode($groupId);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemNode(AdminMenuItem $item, ?string $labelOverride, ?string $iconOverride, string $profile, bool $isBuiltIn): array
    {
        $isPlugin = $item->isPlugin();
        $label = $isPlugin ? $item->label : $this->t($item->labelKey(), $item->label);
        $tileLabel = $item->tileLabel !== '' && !$isPlugin ? $this->t('admin_menu.tile.' . $item->id, $item->tileLabel) : $label;
        if ($labelOverride !== null && $labelOverride !== '') {
            $label = $isBuiltIn ? $this->t(ProfileLayouts::labelKey($profile, 'label', $item->id), $labelOverride) : $labelOverride;
            $tileLabel = $label;
        }

        $category = $item->category;
        $categoryLabel = $item->categoryLabel;
        if ($isPlugin) {
            $category = 'plugin.' . $item->pluginSlug;
        } elseif ($category === '') {
            $category = 'other';
            $categoryLabel = CoreCatalog::CATEGORY_LABELS['other'];
        }
        if (!$isPlugin && $categoryLabel !== '') {
            $categoryLabel = $this->t('admin_menu.category.' . $category, $categoryLabel);
        }

        return [
            'kind' => 'item',
            'id' => $item->id,
            'label' => $label,
            'tile_label' => $tileLabel,
            'description' => !$isPlugin && $item->description !== '' ? $this->t($item->descriptionKey(), $item->description) : '',
            'route' => $item->route,
            'icon' => $iconOverride !== null ? ['type' => 'tabler', 'name' => $iconOverride] : $item->icon,
            'badge' => $item->badge,
            'page' => $item->pageSlug,
            'category' => $category,
            'category_label' => $categoryLabel,
            'external' => $item->isExternal(),
            'plugin' => $isPlugin,
            'locked' => $item->locked,
        ];
    }

    private function t(string $key, string $fallback): string
    {
        $translated = ($this->translate)($key, $fallback);

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }

    private function compareLabels(string $left, string $right, string $leftId, string $rightId): int
    {
        $lower = static fn (string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $compare = strnatcasecmp($lower($left), $lower($right));

        return $compare !== 0 ? $compare : strcmp($leftId, $rightId);
    }
}
