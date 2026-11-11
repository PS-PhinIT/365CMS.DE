<?php
/**
 * Ein Eintrag der Admin-Menü-Registry (Core-Seite, Plugin-Seite oder externer Link).
 *
 * Route, Capabilities und Badge sind in jedem Profil gleich. Profile und das eigene Layout
 * ändern nur Gruppierung, Reihenfolge, Beschriftung, Icon und Sichtbarkeit.
 *
 * @package CMSv2\Core\Services\AdminMenu
 */

declare(strict_types=1);

namespace CMS\Services\AdminMenu;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminMenuItem
{
    public const TYPE_PAGE = 'page';
    public const TYPE_LINK = 'link';

    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,150}$/';

    /**
     * @param list<string>          $capabilities     Eine von mehreren genügt; leer = nur das isAdmin-Gate des Adminbereichs
     * @param array<string, string> $icon             ['type' => 'tabler', 'name' => …] | ['type' => 'image', 'src' => …] | ['type' => 'text', 'text' => …]
     * @param string                $file             Seitendatei relativ zu ABSPATH; fehlt sie, bleibt der Eintrag ausgeblendet
     * @param string                $pageSlug         Seiten-Slug für die Modulsteuerung (CoreModuleService) und die $activePage-Zuordnung
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $route,
        public readonly array $icon = ['type' => 'tabler', 'name' => 'point'],
        public readonly array $capabilities = [],
        public readonly string $file = '',
        public readonly string $pageSlug = '',
        public readonly string $category = '',
        public readonly string $badge = '',
        public readonly string $source = 'core',
        public readonly bool $locked = false,
        public readonly string $type = self::TYPE_PAGE,
        public readonly bool $requiresMarketplace = false,
        public readonly string $tileLabel = '',
        public readonly string $description = '',
        public readonly string $pluginSlug = '',
        public readonly string $categoryLabel = '',
    ) {
    }

    public function isPlugin(): bool
    {
        return str_starts_with($this->source, 'plugin:');
    }

    public function isExternal(): bool
    {
        return $this->type === self::TYPE_LINK;
    }

    public function labelKey(): string
    {
        return 'admin_menu.item.' . $this->id;
    }

    public function descriptionKey(): string
    {
        return 'admin_menu.description.' . $this->id;
    }

    /**
     * @return array{path:string, query:array<string, string>}
     */
    public function routeParts(): array
    {
        return self::splitRoute($this->route);
    }

    /**
     * @return array{path:string, query:array<string, string>}
     */
    public static function splitRoute(string $route): array
    {
        $path = (string) parse_url($route, PHP_URL_PATH);
        $path = $path === '' ? '/' : '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $query = [];
        parse_str((string) parse_url($route, PHP_URL_QUERY), $query);
        $normalized = [];
        foreach ($query as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $normalized[$key] = (string) $value;
            }
        }

        return ['path' => $path, 'query' => $normalized];
    }
}
