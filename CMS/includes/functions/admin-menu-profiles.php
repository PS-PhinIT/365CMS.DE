<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wunschplätze von Plugin-Menüs in den Admin-Menü-Profilen – ohne Wirkung auf die Platzierung.
 *
 * Seiten aktiver Plugins stehen gesammelt im Abschnitt „Aktive Plugins“ der Seitenleiste; nur ein
 * Administrator kann sie in einem eigenen Layout an andere Stellen verschieben. Die Funktionen und der
 * Filter `cms_admin_menu_preferred_parents` (Menü-Slug => [Profil => Gruppen-ID]) bleiben bestehen,
 * damit Plugins, die sie aufrufen, weiter laufen. Siehe DOC/admin/ADMIN-MENU.md.
 *
 *     cms_admin_menu_set_preferred_parent('my-plugin', ['classic' => 'tools', 'structured' => 'reports']);
 */
global $cms_admin_menu_preferred_parents;
if (!isset($cms_admin_menu_preferred_parents) || !is_array($cms_admin_menu_preferred_parents)) {
    $cms_admin_menu_preferred_parents = [];
}

/**
 * @param string                $menuSlug Slug des Hauptmenüs aus add_menu_page()
 * @param array<string, string> $parents  Profil => Gruppen-ID; spätere Aufrufe überschreiben je Profil
 */
function cms_admin_menu_set_preferred_parent(string $menuSlug, array $parents): void
{
    global $cms_admin_menu_preferred_parents;

    if ($menuSlug === '') {
        return;
    }
    foreach ($parents as $profile => $groupId) {
        if (is_string($profile) && $profile !== '' && is_string($groupId) && $groupId !== '') {
            $cms_admin_menu_preferred_parents[$menuSlug][$profile] = $groupId;
        }
    }
}

/**
 * @return array<mixed> Menü-Slug => [Profil => Gruppen-ID] nach dem Filter (Normalisierung in MenuRegistry)
 */
function cms_admin_menu_get_preferred_parents(): array
{
    global $cms_admin_menu_preferred_parents;

    $parents = is_array($cms_admin_menu_preferred_parents) ? $cms_admin_menu_preferred_parents : [];
    $filtered = \CMS\Hooks::applyFilters(\CMS\Services\AdminMenu\MenuRegistry::PREFERRED_PARENTS_FILTER, $parents);

    return is_array($filtered) ? $filtered : $parents;
}
