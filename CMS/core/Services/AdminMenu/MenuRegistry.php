<?php
/**
 * Admin-Menü-Registry: Core-Katalog plus die über den bestehenden Hook registrierten Plugin-Menüs.
 *
 * Plugin-Menüs werden ausschließlich gelesen (Ergebnis von get_registered_admin_menus()); an der
 * Registrierung (Hook `cms_admin_menu`, add_menu_page/add_submenu_page) ändert sich nichts.
 * Menüs von Plugins, die der aktuellen Site nicht zugewiesen sind, werden ausgelassen und gezählt.
 *
 * Plugin-IDs: `plugin:<menu_slug>` (Menü ohne Unterseiten) bzw. `plugin:<menu_slug>:<child_slug>`.
 *
 * @package CMSv2\Core\Services\AdminMenu
 */

declare(strict_types=1);

namespace CMS\Services\AdminMenu;

if (!defined('ABSPATH')) {
    exit;
}

final class MenuRegistry
{
    public const DEFAULT_PLUGIN_ICON = 'puzzle';
    public const PREFERRED_PARENTS_FILTER = 'cms_admin_menu_preferred_parents';

    /** @var array<string, AdminMenuItem> */
    private array $items = [];

    /** @var array<string, true> */
    private array $knownCoreIds = [];

    /**
     * Plugin-Menüs in Registrierungsreihenfolge: Menü-Schlüssel => Titel, Icon, Eintrags-IDs.
     *
     * @var array<string, array{label:string, icon:array<string, string>, items:list<string>}>
     */
    private array $pluginMenus = [];

    /** @var array<string, array<string, string>> */
    private array $preferredParents = [];

    private int $pluginTotal = 0;
    private int $pluginHidden = 0;

    /**
     * @param list<AdminMenuItem> $coreItems
     */
    private function __construct(array $coreItems)
    {
        foreach ($coreItems as $item) {
            $this->items[$item->id] = $item;
            $this->knownCoreIds[$item->id] = true;
        }
    }

    /**
     * @param array<int|string, mixed>                $registeredPluginMenus Unverändertes Ergebnis von get_registered_admin_menus()
     * @param array<string, array<string, string>>    $preferredParents      Menü-Slug => [Profil => Gruppen-ID]
     * @param (callable(array<string, mixed>): bool)|null $isAssigned        Liefert false, wenn das Plugin der Site nicht zugewiesen ist
     * @param list<AdminMenuItem>|null                $coreItems             Für Tests; Standard ist der Core-Katalog
     */
    public static function create(
        array $registeredPluginMenus,
        array $preferredParents = [],
        ?callable $isAssigned = null,
        string $siteUrl = '',
        ?array $coreItems = null,
    ): self {
        $registry = new self($coreItems ?? CoreCatalog::items());
        $registry->preferredParents = self::normalizePreferredParents($preferredParents);
        $registry->addPluginMenus($registeredPluginMenus, $isAssigned, $siteUrl);

        return $registry;
    }

    /**
     * @return array<string, AdminMenuItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function get(string $id): ?AdminMenuItem
    {
        return $this->items[$id] ?? null;
    }

    /**
     * @return array<string, true>
     */
    public function knownCoreIds(): array
    {
        return $this->knownCoreIds;
    }

    /**
     * @return list<string>
     */
    public function lockedIds(): array
    {
        $ids = [];
        foreach ($this->items as $item) {
            if ($item->locked) {
                $ids[] = $item->id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, array{label:string, icon:array<string, string>, items:list<string>}>
     */
    public function pluginMenus(): array
    {
        return $this->pluginMenus;
    }

    public function preferredParent(string $menuKey, string $profile): ?string
    {
        return $this->preferredParents[$menuKey][$profile] ?? null;
    }

    public function pluginTotal(): int
    {
        return $this->pluginTotal;
    }

    public function pluginHidden(): int
    {
        return $this->pluginHidden;
    }

    /**
     * Fingerprint aller Plugin-Einträge, Wunschplätze und der Site-Zuweisung (Teil des Cache-Schlüssels).
     */
    public function pluginFingerprint(): string
    {
        $data = [$this->pluginTotal, $this->pluginHidden, $this->preferredParents];
        foreach ($this->pluginMenus as $key => $menu) {
            $data[] = [$key, $menu['label'], $menu['icon'], $menu['items']];
            foreach ($menu['items'] as $id) {
                $item = $this->items[$id];
                $data[] = [$item->label, $item->route, $item->capabilities];
            }
        }

        return sha1((string) json_encode($data));
    }

    /**
     * Schlüssel des Plugin-Menüs aus einer Eintrags-ID (`plugin:<menu>` bzw. `plugin:<menu>:<child>`).
     */
    public static function pluginMenuKeyOf(string $itemId): ?string
    {
        if (!str_starts_with($itemId, 'plugin:')) {
            return null;
        }
        $parts = explode(':', $itemId, 3);

        return ($parts[1] ?? '') !== '' ? $parts[1] : null;
    }

    public static function idFragment(string $value): string
    {
        $fragment = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value), '-');
        if ($fragment === '' || preg_match('/^[A-Za-z0-9]/', $fragment) !== 1) {
            $fragment = 'x' . substr(sha1($value), 0, 10) . ($fragment !== '' ? '-' . $fragment : '');
        }

        return substr($fragment, 0, 70);
    }

    public static function plainLabel(string $label, string $fallback): string
    {
        $label = html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = trim((string) (preg_replace('/\s+/u', ' ', $label) ?? $label));

        return $label !== '' ? $label : $fallback;
    }

    /**
     * Plugin-Icon aus icon_url: Tabler-Klasse, Bild-URL (same origin), Inline-SVG (als data-URI-Bild)
     * oder kurzes Zeichen (Emoji). Alles andere → neutrales Standard-Icon.
     *
     * @return array<string, string>
     */
    public static function pluginIcon(string $icon, string $siteUrl): array
    {
        $icon = trim($icon);
        $default = ['type' => 'tabler', 'name' => self::DEFAULT_PLUGIN_ICON];
        if ($icon === '') {
            return $default;
        }

        if (preg_match('/^(?:ti\s+)?ti-([a-z0-9-]{1,60})$/', $icon, $match) === 1) {
            return ['type' => 'tabler', 'name' => $match[1]];
        }

        if (str_starts_with($icon, '<')) {
            if (preg_match('/^<svg[\s>]/i', $icon) === 1 && strlen($icon) <= 20000) {
                return ['type' => 'image', 'src' => 'data:image/svg+xml;base64,' . base64_encode($icon)];
            }
            if (preg_match('/class\s*=\s*["\'][^"\']*\bti-([a-z0-9-]{1,60})\b/i', $icon, $match) === 1) {
                return ['type' => 'tabler', 'name' => strtolower($match[1])];
            }

            return $default;
        }

        if (preg_match('~^data:image/(?:png|gif|webp|jpeg|svg\+xml);base64,[A-Za-z0-9+/=]+$~', $icon) === 1) {
            return ['type' => 'image', 'src' => $icon];
        }

        if ((str_starts_with($icon, '/') && !str_starts_with($icon, '//'))
            || ($siteUrl !== '' && str_starts_with($icon, rtrim($siteUrl, '/') . '/'))) {
            return ['type' => 'image', 'src' => $icon];
        }

        $length = function_exists('mb_strlen') ? mb_strlen($icon, 'UTF-8') : strlen($icon);
        if ($length <= 4 && preg_match('/[\s<>"\'&]/', $icon) !== 1 && preg_match('~^(?:https?:)?//~i', $icon) !== 1) {
            return ['type' => 'text', 'text' => $icon];
        }

        return $default;
    }

    /**
     * @param array<int|string, mixed> $menus
     * @param (callable(array<string, mixed>): bool)|null $isAssigned
     */
    private function addPluginMenus(array $menus, ?callable $isAssigned, string $siteUrl): void
    {
        foreach ($menus as $menu) {
            if (!is_array($menu) || !empty($menu['hidden']) || !is_scalar($menu['menu_slug'] ?? null) || (string) $menu['menu_slug'] === '') {
                continue;
            }

            $this->pluginTotal++;
            if ($isAssigned !== null) {
                try {
                    if (!$isAssigned($menu)) {
                        $this->pluginHidden++;
                        continue;
                    }
                } catch (\Throwable) {
                    // Zuordnung nicht ermittelbar → Menü bleibt sichtbar.
                }
            }

            $menuSlug = (string) $menu['menu_slug'];
            $key = self::idFragment($menuSlug);
            $suffix = 2;
            while (isset($this->pluginMenus[$key])) {
                $key = self::idFragment($menuSlug) . '-' . $suffix++;
            }

            $title = self::plainLabel((string) ($menu['menu_title'] ?? ''), $menuSlug);
            $icon = self::pluginIcon((string) ($menu['icon_url'] ?? ''), $siteUrl);
            $source = 'plugin:' . (is_string($menu['plugin_slug'] ?? null) && $menu['plugin_slug'] !== '' ? $menu['plugin_slug'] : $menuSlug);
            $basePath = '/admin/plugins/' . rawurlencode($menuSlug) . '/';
            $capability = is_string($menu['capability'] ?? null) && $menu['capability'] !== '' ? [$menu['capability']] : [];

            $pages = [];
            $hasOverview = false;
            foreach ((array) ($menu['children'] ?? []) as $child) {
                if (!is_array($child) || !is_scalar($child['menu_slug'] ?? null) || (string) $child['menu_slug'] === '') {
                    continue;
                }
                $childSlug = (string) $child['menu_slug'];
                $hasOverview = $hasOverview || $childSlug === $menuSlug;
                $pages[] = [
                    'slug' => $childSlug,
                    'label' => self::plainLabel((string) ($child['menu_title'] ?? ''), $childSlug),
                    'caps' => is_string($child['capability'] ?? null) && $child['capability'] !== '' ? [$child['capability']] : $capability,
                ];
            }
            if ($pages !== [] && !$hasOverview) {
                array_unshift($pages, ['slug' => $menuSlug, 'label' => 'Übersicht', 'caps' => $capability]);
            }

            $ids = [];
            if ($pages === []) {
                $id = 'plugin:' . $key;
                $this->items[$id] = new AdminMenuItem(
                    id: $id,
                    label: $title,
                    route: $basePath . rawurlencode($menuSlug),
                    icon: $icon,
                    capabilities: $capability,
                    pageSlug: $menuSlug,
                    source: $source,
                    pluginSlug: $key,
                    categoryLabel: $title,
                );
                $ids[] = $id;
            } else {
                $usedChildKeys = [];
                foreach ($pages as $page) {
                    $childKey = self::idFragment($page['slug']);
                    $candidate = $childKey;
                    $n = 2;
                    while (isset($usedChildKeys[$candidate])) {
                        $candidate = $childKey . '-' . $n++;
                    }
                    $usedChildKeys[$candidate] = true;

                    $id = 'plugin:' . $key . ':' . $candidate;
                    $this->items[$id] = new AdminMenuItem(
                        id: $id,
                        label: $page['label'],
                        route: $basePath . rawurlencode($page['slug']),
                        icon: $icon,
                        capabilities: $page['caps'],
                        pageSlug: $page['slug'],
                        source: $source,
                        pluginSlug: $key,
                        categoryLabel: $title,
                    );
                    $ids[] = $id;
                }
            }

            $this->pluginMenus[$key] = ['label' => $title, 'icon' => $icon, 'items' => $ids];

            if (isset($this->preferredParents[$menuSlug]) && $key !== $menuSlug) {
                $this->preferredParents[$key] = $this->preferredParents[$menuSlug];
            }
        }
    }

    /**
     * @param array<mixed> $preferredParents
     * @return array<string, array<string, string>>
     */
    private static function normalizePreferredParents(array $preferredParents): array
    {
        $normalized = [];
        foreach ($preferredParents as $menuSlug => $parents) {
            if (!is_string($menuSlug) || $menuSlug === '' || !is_array($parents)) {
                continue;
            }
            foreach ($parents as $profile => $groupId) {
                if (is_string($profile) && ProfileLayouts::isProfile($profile)
                    && is_string($groupId) && preg_match(LayoutValidator::NODE_ID_PATTERN, $groupId) === 1) {
                    $normalized[$menuSlug][$profile] = $groupId;
                }
            }
        }

        return $normalized;
    }
}
