# 365CMS – Projektdokumentation | Abschnitt: Admin – Theme-Menüs

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/menu-editor` (Alt-Route `/admin/menus` leitet um) | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_menu_editor`

## English (summary)

Navigation menus are built at `/admin/menu-editor` (`CMS/admin/menu-editor.php` → `CMS/admin/modules/menus/MenuEditorModule.php` → `CMS/admin/views/menus/editor.php`). Menus are stored in `cms_menus` / `cms_menu_items` (created by the module on demand) and mirrored as JSON into `cms_settings.option_name = 'menu_<location>'`, which the theme reads through `ThemeManager::getMenu($location)`. Actions: `save_menu`, `delete_menu`, `save_items`.

## Deutsch

### Menüpositionen

Ein Theme meldet seine Positionen über `theme.json` (`"menus": { "slug": "Bezeichnung" }`) oder den Filter `register_menu_locations`. Zusätzliche Positionen können als `menu_custom_locations` gespeichert werden (`ThemeManager::saveCustomMenuLocations()`).

Positionen des Themes **cms-default**:

| Slug | Bezeichnung |
|---|---|
| `primary` | Hauptmenü (Header) |
| `secondary` | Sekundäres Menü (unter dem Header) |
| `mobile` | Mobiles Menü (Hamburger) |
| `footer` | Footer-Navigation (allgemein) |
| `footer_topics` | Footer – Spalte Rubriken |
| `footer_resources` | Footer – Spalte Ressourcen |
| `footer_about` | Footer – Spalte Über |
| `footer_legal` | Footer – Legal-Leiste unten |

Die Übersicht zeigt je Position, welches Menü zugewiesen ist.

### Menü anlegen und bearbeiten

| Aktion | Felder | Hinweise |
|---|---|---|
| `save_menu` | Name (max. 255 Zeichen), Position | Legt ein Menü an oder ändert Name/Position |
| `delete_menu` | `menu_id` | Löscht Menü und Einträge |
| `save_items` | `menu_id`, `items_json` | Speichert alle Einträge als JSON-Struktur |

**Menüeinträge** (max. **200** je Menü):

| Feld | Hinweis |
|---|---|
| Titel | max. 255 Zeichen |
| URL | interne Pfade (`/kontakt`), absolute URLs oder Anker; unsichere Schemata werden verworfen |
| Ziel (`target`) | `_self` oder `_blank` |
| Icon | optional, max. 100 Zeichen (z. B. Tabler-Icon-Name) |
| Elterneintrag (`parent_id`) | für Untermenüs; zirkuläre Verweise werden auf oberste Ebene zurückgesetzt |

Der Editor bietet eine Auswahlliste aller veröffentlichten Seiten an (Seiten-Picker). Einträge mit Titel „Startseite“/„Home“ oder URL `/` werden als Startseiten-Link erkannt.

### Speicherung und Ausgabe

1. `cms_menus` (`id`, `name`, `location`) und `cms_menu_items` werden vom Modul bei Bedarf angelegt.
2. Nach `save_items` schreibt das Modul die normalisierten Einträge zusätzlich über `ThemeManager::saveMenu()` nach `menu_<location>`.
3. Themes lesen mit `ThemeManager::instance()->getMenu('primary')`. Für `primary` gibt es den Fallback auf die alte Option `site_menu`.

### Verwandte Dokumente

[README.md](README.md) · [../../theme/THEME-DEVELOPMENT.md](../../theme/THEME-DEVELOPMENT.md)
