<?php
declare(strict_types=1);

/**
 * Menu Editor Module – Menü-Verwaltung
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\Json;
use CMS\Logger;
use CMS\ThemeManager;

class MenuEditorModule
{
    private const MAX_EDITOR_ITEMS = 200;
    private const MAX_MENU_NAME_LENGTH = 255;
    private const MAX_ITEM_TITLE_LENGTH = 255;
    private const MAX_ITEM_ICON_LENGTH = 100;
    private const HOMEPAGE_TITLES = ['startseite', 'home', 'homepage'];

    private Database $db;
    private Logger $logger;
    private string $prefix;
    private bool $bootstrapped = false;

    public function __construct()
    {
        $this->db     = Database::instance();
        $this->logger = Logger::instance()->withChannel('admin.menu-editor');
        $this->prefix = $this->db->getPrefix();
    }

    /**
     * Daten laden
     */
    public function getData(int $currentMenuId = 0): array
    {
        $this->ensureBootstrapped();

        $menus = $this->getMenus();
        $locations = $this->getMenuLocations();
        $locationOverview = $this->buildLocationOverview($locations, $menus);
        $pages = $this->getPages();

        $currentMenu = $currentMenuId > 0 ? $this->findMenuById($menus, $currentMenuId) : null;
        $menuItems = $currentMenu ? $this->getMenuItems($currentMenuId) : [];
        $editorConfig = $this->buildEditorConfig($menuItems);

        return [
            'menus'       => $menus,
            'currentMenu' => $currentMenu,
            'menuItems'   => $menuItems,
            'locations'   => $locations,
            'locationOverview' => $locationOverview,
            'pagePickerOptions' => $this->buildPagePickerOptions($pages),
            'editorConfigJson' => $this->encodeEditorConfig($editorConfig),
        ];
    }

    public function menuExists(int $menuId): bool
    {
        $this->ensureBootstrapped();

        return $menuId > 0 && $this->getMenuById($menuId) !== null;
    }

    /**
     * Menü erstellen/bearbeiten
     */
    public function saveMenu(array $post): array
    {
        $this->ensureBootstrapped();

        $menuId = $this->normalizePositiveInt($post['menu_id'] ?? 0);
        $name = $this->normalizeMenuName($post['menu_name'] ?? '');
        $location = $this->normalizeMenuLocation($post['menu_location'] ?? '');
        $knownLocations = $this->getMenuLocations();
        $existingMenu = $menuId > 0 ? $this->getMenuById($menuId) : null;

        if (empty($name)) {
            return ['success' => false, 'error' => 'Menü-Name darf nicht leer sein.'];
        }

        if ($menuId > 0 && !$existingMenu) {
            return ['success' => false, 'error' => 'Menü wurde nicht gefunden.'];
        }

        if ($location !== '' && !array_key_exists($location, $knownLocations)) {
            return ['success' => false, 'error' => 'Ungültige Theme-Position.'];
        }

        if ($location !== '' && $this->locationAssignedToDifferentMenu($location, $menuId > 0 ? $menuId : null)) {
            return ['success' => false, 'error' => 'Diese Theme-Position ist bereits einem anderen Menü zugewiesen.'];
        }

        try {
            if ($menuId > 0) {
                $this->db->execute(
                    "UPDATE {$this->prefix}menus SET name = ?, location = ? WHERE id = ?",
                    [$name, $location, $menuId]
                );

                $previousLocation = trim((string) ($existingMenu->location ?? ''));
                if ($previousLocation !== '' && $previousLocation !== $location) {
                    ThemeManager::instance()->saveMenu($previousLocation, []);
                }

                if ($location !== '') {
                    ThemeManager::instance()->saveMenu($location, $this->buildThemeItemsFromStoredMenu($menuId));
                }

                return ['success' => true, 'id' => $menuId, 'message' => 'Menü aktualisiert.'];
            } else {
                $this->db->execute(
                    "INSERT INTO {$this->prefix}menus (name, location) VALUES (?, ?)",
                    [$name, $location]
                );
                $menuId = (int) $this->db->lastInsertId();

                if ($location !== '') {
                    ThemeManager::instance()->saveMenu($location, []);
                }

                return ['success' => true, 'id' => $menuId, 'message' => 'Menü erstellt.'];
            }
        } catch (\Throwable $e) {
            $this->logger->error('Menü konnte nicht gespeichert werden.', [
                'menu_id' => $menuId,
                'location' => $location,
                'exception' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'Menü konnte nicht gespeichert werden.'];
        }
    }

    /**
     * Menü löschen
     */
    public function deleteMenu(int $menuId): array
    {
        $this->ensureBootstrapped();

        $menuId = $this->normalizePositiveInt($menuId);

        if ($menuId <= 0) {
            return ['success' => false, 'error' => 'Ungültige Menü-ID.'];
        }

        try {
            $pdo = $this->db->getPdo();
            $menu = $this->db->get_row(
                "SELECT * FROM {$this->prefix}menus WHERE id = ? LIMIT 1",
                [$menuId]
            );

            if (!$menu) {
                return ['success' => false, 'error' => 'Menü wurde nicht gefunden.'];
            }

            if ($menu && !empty($menu->location)) {
                ThemeManager::instance()->saveMenu((string)$menu->location, []);
            }

            $pdo->beginTransaction();
            $this->db->execute("DELETE FROM {$this->prefix}menu_items WHERE menu_id = ?", [$menuId]);
            $this->db->execute("DELETE FROM {$this->prefix}menus WHERE id = ?", [$menuId]);
            $pdo->commit();

            return ['success' => true, 'message' => 'Menü gelöscht.'];
        } catch (\Throwable $e) {
            $pdo = $this->db->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logger->error('Menü konnte nicht gelöscht werden.', [
                'menu_id' => $menuId,
                'exception' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'Menü konnte nicht gelöscht werden.'];
        }
    }

    /**
     * Menü-Items speichern (JSON)
     */
    public function saveItems(int $menuId, string $itemsJson): array
    {
        $this->ensureBootstrapped();

        $menuId = $this->normalizePositiveInt($menuId);

        if ($menuId <= 0) {
            return ['success' => false, 'error' => 'Ungültige Menü-ID.'];
        }

        $decodedItems = Json::decode($itemsJson, true, null);
        if (!is_array($decodedItems)) {
            return ['success' => false, 'error' => 'Ungültiges JSON-Format.'];
        }

        try {
            $pdo = $this->db->getPdo();
            $menu = $this->db->get_row(
                "SELECT * FROM {$this->prefix}menus WHERE id = ? LIMIT 1",
                [$menuId]
            );

            if (!$menu) {
                return ['success' => false, 'error' => 'Menü wurde nicht gefunden.'];
            }

            $normalizedItems = $this->normalizeEditorItems($decodedItems);
            if ($normalizedItems['error'] !== '') {
                return ['success' => false, 'error' => $normalizedItems['error']];
            }

            $pdo->beginTransaction();
            $this->db->execute("DELETE FROM {$this->prefix}menu_items WHERE menu_id = ?", [$menuId]);

            $position = 0;
            $tree = $this->buildEditorTree($normalizedItems['items']);
            $this->insertEditorTree($menuId, $tree, 0, $position);

            if ($menu && !empty($menu->location)) {
                ThemeManager::instance()->saveMenu((string)$menu->location, $this->normalizeThemeItems($normalizedItems['items']));
            }

            $pdo->commit();

            return ['success' => true, 'message' => 'Menü-Items gespeichert.'];
        } catch (\Throwable $e) {
            $pdo = $this->db->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logger->error('Menü-Items konnten nicht gespeichert werden.', [
                'menu_id' => $menuId,
                'exception' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'Menü-Items konnten nicht gespeichert werden.'];
        }
    }

    private function ensureBootstrapped(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        // Release-Prüfung 4.1.0 (PERF, db:0): CREATE TABLE nur einmal je Dateistand statt bei jedem Aufruf.
        $this->db->runSchemaOnce('menu_editor', __FILE__, fn (): bool => $this->ensureTables());
        $this->syncThemeMenus();
        $this->bootstrapped = true;
    }

    /**
     * Alle Menüs laden
     */
    private function getMenus(): array
    {
        try {
            return $this->db->get_results(
                "SELECT m.*, (SELECT COUNT(*) FROM {$this->prefix}menu_items WHERE menu_id = m.id) AS item_count 
                 FROM {$this->prefix}menus m ORDER BY m.name ASC"
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<int,object> $menus
     */
    private function findMenuById(array $menus, int $menuId): ?object
    {
        foreach ($menus as $menu) {
            if ((int) ($menu->id ?? 0) === $menuId) {
                return $menu;
            }
        }

        return null;
    }

    private function getMenuById(int $menuId): ?object
    {
        try {
            $menu = $this->db->get_row(
                "SELECT * FROM {$this->prefix}menus WHERE id = ? LIMIT 1",
                [$menuId]
            );

            return is_object($menu) ? $menu : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizePositiveInt(mixed $value): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $normalized === false ? 0 : (int) $normalized;
    }

    private function normalizeMenuName(mixed $value): string
    {
        $normalized = trim(strip_tags((string) $value));

        if ($normalized === '') {
            return '';
        }

        return $this->truncateText($normalized, self::MAX_MENU_NAME_LENGTH);
    }

    private function normalizeMenuLocation(mixed $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $value) ?? '';
    }

    /**
     * @param array<int,object> $menuItems
     * @return array{items:array<int,array<string,string>>}
     */
    private function buildEditorConfig(array $menuItems): array
    {
        return [
            'items' => array_map(static function ($item): array {
                return [
                    'id' => (string) ($item->id ?? ''),
                    'title' => (string) ($item->title ?? ''),
                    'url' => (string) ($item->url ?? '#'),
                    'target' => (string) ($item->target ?? '_self'),
                    'icon' => (string) ($item->icon ?? ''),
                    'parent_id' => (string) ($item->parent_id ?? 0),
                ];
            }, $menuItems),
        ];
    }

    private function encodeEditorConfig(array $config): string
    {
        return (string) json_encode(
            $config,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    /**
     * @param array<int,object> $pages
     * @return array<int,array{title:string,url:string}>
     */
    private function buildPagePickerOptions(array $pages): array
    {
        $options = [];

        foreach ($pages as $page) {
            $title = $this->normalizeMenuItemTitle($page->title ?? '');
            $slug = trim((string) ($page->slug ?? ''));

            if ($title === '' || $slug === '') {
                continue;
            }

            $segments = array_values(array_filter(
                explode('/', str_replace('\\', '/', $slug)),
                static fn (string $segment): bool => $segment !== ''
            ));

            if ($segments === []) {
                continue;
            }

            $options[] = [
                'title' => $title,
                'url' => '/' . implode('/', array_map('rawurlencode', $segments)),
            ];
        }

        return $options;
    }

    private function locationAssignedToDifferentMenu(string $location, ?int $excludeMenuId = null): bool
    {
        if ($location === '') {
            return false;
        }

        $sql = "SELECT id FROM {$this->prefix}menus WHERE location = ?";
        $params = [$location];

        if ($excludeMenuId !== null && $excludeMenuId > 0) {
            $sql .= ' AND id != ?';
            $params[] = $excludeMenuId;
        }

        return $this->db->get_var($sql . ' LIMIT 1', $params) !== null;
    }

    /**
     * Menü-Items laden
     */
    private function getMenuItems(int $menuId): array
    {
        try {
            return $this->db->get_results(
                "SELECT * FROM {$this->prefix}menu_items WHERE menu_id = ? ORDER BY position ASC",
                [$menuId]
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Menü-Positionen aus Theme-JSON laden
     */
    private function getMenuLocations(): array
    {
        $locations = [];

        try {
            foreach (ThemeManager::instance()->getMenuLocations() as $location) {
                if (is_array($location) && isset($location['slug'], $location['label'])) {
                    $locations[(string)$location['slug']] = (string)$location['label'];
                    continue;
                }

                if (is_string($location)) {
                    $locations[$location] = $location;
                }
            }
        } catch (\Throwable $e) {
            // Fallback unten verwenden
        }

        if (empty($locations)) {
            $locations = ['primary' => 'Hauptnavigation', 'footer' => 'Footer-Menü'];
        }

        return $locations;
    }

    /**
     * Registrierte Theme-Menüs in die Admin-Tabellen spiegeln.
     */
    private function syncThemeMenus(): void
    {
        $locations = $this->getMenuLocations();

        if (empty($locations)) {
            return;
        }

        foreach ($locations as $slug => $label) {
            $menu = $this->db->get_row(
                "SELECT * FROM {$this->prefix}menus WHERE location = ? LIMIT 1",
                [$slug]
            );

            if (!$menu) {
                $this->db->execute(
                    "INSERT INTO {$this->prefix}menus (name, location) VALUES (?, ?)",
                    [$label, $slug]
                );

                $menu = $this->db->get_row(
                    "SELECT * FROM {$this->prefix}menus WHERE location = ? LIMIT 1",
                    [$slug]
                );
            }

            if (!$menu) {
                continue;
            }

            $currentName = trim((string) ($menu->name ?? ''));
            if ($currentName === '' || $currentName === (string) $slug || $currentName !== (string) $label) {
                $this->db->execute(
                    "UPDATE {$this->prefix}menus SET name = ? WHERE id = ?",
                    [$label, (int) $menu->id]
                );
            }

            $this->syncMenuItemsFromThemeSettings((int)$menu->id, $slug);
        }
    }

    /**
     * Baut eine Übersicht aller registrierten Menüpositionen inkl. zugewiesenem Menü.
     *
     * @param array<string,string> $locations
     * @param array<int,object>    $menus
     * @return array<int,array<string,mixed>>
     */
    private function buildLocationOverview(array $locations, array $menus): array
    {
        $menusByLocation = [];

        foreach ($menus as $menu) {
            $location = trim((string) ($menu->location ?? ''));
            if ($location === '') {
                continue;
            }

            $menusByLocation[$location] = $menu;
        }

        $overview = [];
        foreach ($locations as $slug => $label) {
            $assignedMenu = $menusByLocation[$slug] ?? null;
            $overview[] = [
                'slug' => (string) $slug,
                'label' => (string) $label,
                'menu' => $assignedMenu,
            ];
        }

        return $overview;
    }

    /**
     * Spiegelt die eigentlichen Theme-Menüeinträge in die Admin-Tabelle.
     */
    private function syncMenuItemsFromThemeSettings(int $menuId, string $location): void
    {
        $items = ThemeManager::instance()->getMenu($location);

        // Release-Prüfung 4.1.0 (PERF, db:0): Spiegel nur neu aufbauen, wenn er vom Theme-Menü abweicht.
        // Sonst schrieb jeder Aufruf des Menü-Editors alle Einträge neu (DELETE plus INSERT je Eintrag),
        // und die Zeilen-IDs, die der Editor gerade ausgeliefert hatte, waren beim nächsten Aufruf verbraucht.
        $existingRows = $this->db->get_results(
            "SELECT id, parent_id, title, url, target, icon, position FROM {$this->prefix}menu_items WHERE menu_id = ? ORDER BY position ASC, id ASC",
            [$menuId]
        ) ?: [];
        if (self::mirrorMatchesThemeItems(self::flattenThemeItems($items), $existingRows)) {
            return;
        }

        // Release-Prüfung 4.1.0 (PERF, db:0): Neuaufbau eines Menüs atomar, damit parallele Aufrufe
        // weder ein leeres noch ein doppelt befülltes Menü sehen.
        $pdo = $this->db->getPdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->db->execute("DELETE FROM {$this->prefix}menu_items WHERE menu_id = ?", [$menuId]);

            $position = 0;
            $this->insertThemeItemsRecursive($menuId, $items, 0, $position);

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Flache Sollliste des Spiegels in derselben Reihenfolge und mit denselben Regeln wie
     * insertThemeItemsRecursive(): parent = Index des Elterneintrags in der Liste, -1 = oberste Ebene.
     *
     * @param array<int|string,mixed> $items
     * @return list<array{parent:int,title:string,url:string,target:string,icon:string}>
     */
    public static function flattenThemeItems(array $items, int $parentIndex = -1, array &$rows = []): array
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string)($item['label'] ?? $item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $index = count($rows);
            $rows[] = [
                'parent' => $parentIndex,
                'title' => $title,
                'url' => trim((string)($item['url'] ?? '#')),
                'target' => ((string)($item['target'] ?? '_self')) === '_blank' ? '_blank' : '_self',
                'icon' => trim((string)($item['icon'] ?? '')),
            ];

            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            if ($children !== []) {
                self::flattenThemeItems($children, $index, $rows);
            }
        }

        return $rows;
    }

    /**
     * Vergleicht die gespeicherten Zeilen (sortiert nach position) mit der Sollliste aus flattenThemeItems().
     *
     * @param list<array{parent:int,title:string,url:string,target:string,icon:string}> $desired
     * @param array<int,object|array<string,mixed>> $existingRows
     */
    public static function mirrorMatchesThemeItems(array $desired, array $existingRows): bool
    {
        if (count($desired) !== count($existingRows)) {
            return false;
        }

        $indexById = [];
        foreach (array_values($existingRows) as $index => $row) {
            $row = (array) $row;
            $indexById[(int) ($row['id'] ?? 0)] = $index;
        }

        foreach (array_values($existingRows) as $index => $row) {
            $row = (array) $row;
            $expected = $desired[$index] ?? null;
            if ($expected === null || (int) ($row['position'] ?? -1) !== $index) {
                return false;
            }

            $parentId = (int) ($row['parent_id'] ?? 0);
            $parentIndex = $parentId === 0 ? -1 : ($indexById[$parentId] ?? -2);

            if (
                $parentIndex !== $expected['parent']
                || (string) ($row['title'] ?? '') !== $expected['title']
                || (string) ($row['url'] ?? '') !== $expected['url']
                || (string) ($row['target'] ?? '') !== $expected['target']
                || (string) ($row['icon'] ?? '') !== $expected['icon']
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalisiert Menü-Items aus dem Admin-Editor für ThemeManager::saveMenu().
     */
    private function normalizeThemeItems(array $items): array
    {
        $normalized = [];

        foreach ($this->buildEditorTree($items) as $item) {
            $node = $this->buildThemeNodeFromEditorItem($item);
            if ($node !== null) {
                $normalized[] = $node;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int,mixed> $items
     * @return array{items:array<int,array<string,string>>,error:string}
     */
    private function normalizeEditorItems(array $items): array
    {
        $normalized = [];
        $seenIds = [];

        if (count($items) > self::MAX_EDITOR_ITEMS) {
            return [
                'items' => [],
                'error' => 'Es sind maximal ' . self::MAX_EDITOR_ITEMS . ' Menü-Items erlaubt.',
            ];
        }

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                return [
                    'items' => [],
                    'error' => 'Ungültiges Menü-Item in Zeile ' . ($index + 1) . '.',
                ];
            }

            $title = $this->normalizeMenuItemTitle($item['title'] ?? $item['label'] ?? '');
            if ($title === '') {
                return [
                    'items' => [],
                    'error' => 'Jedes Menü-Item benötigt einen Titel.',
                ];
            }

            $url = $this->normalizeMenuItemUrl($item['url'] ?? '');
            $id = trim((string)($item['id'] ?? 'item-' . ($index + 1)));
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?? '';
            if ($id === '') {
                $id = 'item-' . ($index + 1);
            }

            if (isset($seenIds[$id])) {
                return [
                    'items' => [],
                    'error' => 'Menü-Items enthalten doppelte IDs. Bitte Seite neu laden und erneut speichern.',
                ];
            }
            $seenIds[$id] = true;

            $parentId = $item['parent_id'] ?? 0;
            if (is_string($parentId)) {
                $parentId = trim($parentId);
            }

            $parentId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $parentId) ?? '0';
            if ($parentId === '' || $parentId === '0' || $parentId === $id) {
                $parentId = '0';
            }

            $normalized[] = [
                'id' => $id,
                'title' => $title,
                'url' => $url,
                'target' => ((string)($item['target'] ?? '_self')) === '_blank' ? '_blank' : '_self',
                'icon' => $this->normalizeMenuItemIcon($item['icon'] ?? ''),
                'parent_id' => $parentId,
                '_row' => $index + 1,
            ];
        }

        $itemsById = [];
        $parentIds = [];
        foreach ($normalized as $item) {
            $itemsById[$item['id']] = $item;

            if (($item['parent_id'] ?? '0') !== '0') {
                $parentIds[(string) $item['parent_id']] = true;
            }
        }

        foreach ($normalized as $index => $item) {
            if (($item['url'] ?? '') === '') {
                if ($this->isHomepageMenuTitle((string) ($item['title'] ?? ''))) {
                    $normalized[$index]['url'] = '/';
                    continue;
                }

                if (isset($parentIds[$item['id']])) {
                    $normalized[$index]['url'] = '#';
                } else {
                    return [
                        'items' => [],
                        'error' => 'Menü-Item „' . $item['title'] . '“ in Zeile ' . (int) ($item['_row'] ?? ($index + 1)) . ' benötigt eine gültige URL oder einen gültigen internen Pfad.',
                    ];
                }
            }

            $parentId = $item['parent_id'];
            if ($parentId === '0') {
                continue;
            }

            if (!isset($itemsById[$parentId])) {
                return [
                    'items' => [],
                    'error' => 'Ein Menü-Item verweist auf einen unbekannten Elternpunkt.',
                ];
            }

            $visited = [$item['id'] => true];
            $currentParentId = $parentId;
            while ($currentParentId !== '0') {
                if (isset($visited[$currentParentId])) {
                    return [
                        'items' => [],
                        'error' => 'Die Menü-Struktur enthält eine ungültige Verschachtelung.',
                    ];
                }

                $visited[$currentParentId] = true;
                $currentParentId = (string) ($itemsById[$currentParentId]['parent_id'] ?? '0');
            }

            $normalized[$index]['parent_id'] = $parentId;
        }

        foreach ($normalized as $index => $item) {
            unset($normalized[$index]['_row']);
        }

        return [
            'items' => $normalized,
            'error' => '',
        ];
    }

    private function normalizeMenuItemTitle(mixed $value): string
    {
        $normalized = trim(strip_tags((string) $value));

        if ($normalized === '') {
            return '';
        }

        return $this->truncateText($normalized, self::MAX_ITEM_TITLE_LENGTH);
    }

    private function normalizeMenuItemIcon(mixed $value): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_:\-\s]/', '', trim((string) $value)) ?? '';

        if ($normalized === '') {
            return '';
        }

        return $this->truncateText($normalized, self::MAX_ITEM_ICON_LENGTH);
    }

    private function normalizeMenuItemUrl(mixed $value): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }

        if ($this->isHomepageMenuAlias($url)) {
            return '/';
        }

        if ($this->isNoOpMenuItemUrl($url)) {
            return '#';
        }

        $sanitized = filter_var($url, FILTER_SANITIZE_URL);
        if (!is_string($sanitized) || $sanitized === '') {
            return '';
        }

        $normalizedSanitized = $this->normalizeLowercase($sanitized);
        if (
            (str_starts_with($sanitized, '/') && !str_starts_with($sanitized, '//'))
            || str_starts_with($sanitized, '#')
            || str_starts_with($sanitized, '?')
            || str_starts_with($normalizedSanitized, 'mailto:')
            || str_starts_with($normalizedSanitized, 'tel:')
        ) {
            return $sanitized;
        }

        if (defined('SITE_URL') && is_string(SITE_URL) && SITE_URL !== '') {
            $siteUrl = rtrim((string) SITE_URL, '/');
            if ($siteUrl !== '' && str_starts_with($sanitized, $siteUrl)) {
                $relative = substr($sanitized, strlen($siteUrl));

                return $relative === '' ? '/' : $this->normalizeMenuItemUrl($relative);
            }
        }

        if (preg_match('#^(?!//)(?![a-z][a-z0-9+\-.]*:)#i', $sanitized) === 1) {
            $parts = parse_url('https://menu-editor.local/' . ltrim($sanitized, '/'));
            if (is_array($parts)) {
                $path = trim((string) ($parts['path'] ?? ''), '/');
                $query = (string) ($parts['query'] ?? '');
                $fragment = (string) ($parts['fragment'] ?? '');

                if ($path !== '') {
                    $normalizedPath = '/' . $path;
                    if ($query !== '') {
                        $normalizedPath .= '?' . $query;
                    }
                    if ($fragment !== '') {
                        $normalizedPath .= '#' . $fragment;
                    }

                    return $normalizedPath;
                }
            }
        }

        $validated = filter_var($sanitized, FILTER_VALIDATE_URL);
        if ($validated === false) {
            return '';
        }

        $scheme = strtolower((string) parse_url($validated, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $validated : '';
    }

    private function isHomepageMenuTitle(string $title): bool
    {
        $normalizedTitle = $this->normalizeLowercase(trim($title));

        return in_array($normalizedTitle, self::HOMEPAGE_TITLES, true);
    }

    private function isHomepageMenuAlias(string $url): bool
    {
        $normalizedUrl = $this->normalizeLowercase(trim($url));

        return in_array($normalizedUrl, ['/', 'index.php', './', 'home', 'homepage', 'startseite'], true);
    }

    private function truncateText(string $value, int $maxLength): string
    {
        return function_exists('mb_substr')
            ? (string) mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    }

    private function normalizeLowercase(string $value): string
    {
        return function_exists('mb_strtolower')
            ? (string) mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    private function isNoOpMenuItemUrl(string $url): bool
    {
        $normalizedUrl = preg_replace('/\s+/u', '', $this->normalizeLowercase(trim($url))) ?? '';

        return in_array($normalizedUrl, ['javascript:', 'javascript:;', 'javascript:void(0)', 'javascript:void(0);'], true);
    }

    private function buildEditorTree(array $items): array
    {
        $nodes = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }

            $item['children'] = [];
            $nodes[(string)$item['id']] = $item;
        }

        $tree = [];
        foreach ($nodes as $id => $item) {
            $parentId = $item['parent_id'] ?? 0;
            if ($parentId !== 0 && $parentId !== '0' && $parentId !== $id && isset($nodes[(string)$parentId])) {
                $nodes[(string)$parentId]['children'][] = &$nodes[$id];
                continue;
            }

            $tree[] = &$nodes[$id];
        }

        return array_values($tree);
    }

    private function buildThemeItemsFromStoredMenu(int $menuId): array
    {
        $menuItems = $this->getMenuItems($menuId);
        $editorItems = $this->buildEditorConfig($menuItems);

        return $this->normalizeThemeItems($editorItems['items']);
    }

    private function insertEditorTree(int $menuId, array $items, int $parentDbId, int &$position): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $this->db->execute(
                "INSERT INTO {$this->prefix}menu_items (menu_id, parent_id, title, url, target, icon, position) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $menuId,
                    $parentDbId,
                    (string) ($item['title'] ?? ''),
                    (string) ($item['url'] ?? '#'),
                    ((string)($item['target'] ?? '_self')) === '_blank' ? '_blank' : '_self',
                    (string) ($item['icon'] ?? ''),
                    $position++,
                ]
            );

            $currentId = (int) $this->db->lastInsertId();
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            if ($children !== []) {
                $this->insertEditorTree($menuId, $children, $currentId, $position);
            }
        }
    }

    private function insertThemeItemsRecursive(int $menuId, array $items, int $parentId, int &$position): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string)($item['label'] ?? $item['title'] ?? ''));
            $url   = trim((string)($item['url'] ?? '#'));
            $target = ((string)($item['target'] ?? '_self')) === '_blank' ? '_blank' : '_self';
            $icon  = trim((string)($item['icon'] ?? ''));

            if ($title === '') {
                continue;
            }

            $this->db->execute(
                "INSERT INTO {$this->prefix}menu_items (menu_id, parent_id, title, url, target, icon, position) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$menuId, $parentId, $title, $url, $target, $icon, $position++]
            );

            $currentId = (int) $this->db->lastInsertId();
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            if ($children !== []) {
                $this->insertThemeItemsRecursive($menuId, $children, $currentId, $position);
            }
        }
    }

    private function buildThemeNodeFromEditorItem(array $item): ?array
    {
        $label = trim((string)($item['title'] ?? $item['label'] ?? ''));
        $url   = filter_var((string)($item['url'] ?? ''), FILTER_SANITIZE_URL);

        if ($label === '' || $url === '') {
            return null;
        }

        $node = [
            'label'  => $label,
            'url'    => $url,
            'target' => ((string)($item['target'] ?? '_self')) === '_blank' ? '_blank' : '_self',
            'icon'   => trim(strip_tags((string)($item['icon'] ?? ''))),
        ];

        $children = [];
        foreach (($item['children'] ?? []) as $child) {
            if (!is_array($child)) {
                continue;
            }

            $childNode = $this->buildThemeNodeFromEditorItem($child);
            if ($childNode !== null) {
                $children[] = $childNode;
            }
        }

        if ($children !== []) {
            $node['children'] = $children;
        }

        return $node;
    }

    /**
     * Seiten für "Link zu Seite" laden
     */
    private function getPages(): array
    {
        try {
            return $this->db->get_results(
                "SELECT id, title, slug FROM {$this->prefix}pages WHERE status = 'published' ORDER BY title ASC"
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Tabellen anlegen
     */
    private function ensureTables(): bool
    {
        try {
            $this->db->getPdo()->exec("CREATE TABLE IF NOT EXISTS {$this->prefix}menus (
                id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name     VARCHAR(255) NOT NULL,
                location VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->db->getPdo()->exec("CREATE TABLE IF NOT EXISTS {$this->prefix}menu_items (
                id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                menu_id   INT UNSIGNED NOT NULL,
                parent_id INT UNSIGNED DEFAULT 0,
                title     VARCHAR(255) NOT NULL,
                url       VARCHAR(500) NOT NULL DEFAULT '#',
                target    VARCHAR(10) DEFAULT '_self',
                icon      VARCHAR(100) DEFAULT NULL,
                position  INT UNSIGNED DEFAULT 0,
                INDEX idx_menu (menu_id),
                INDEX idx_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            // Tabellen existieren bereits; ohne Erfolgsmeldung wird die Prüfung beim nächsten Aufruf wiederholt.
            return false;
        }

        return true;
    }
}
