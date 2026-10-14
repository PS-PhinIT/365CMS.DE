# 365CMS – Projektdokumentation | Abschnitt: Admin – Tabellen (Site Tables)

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/site-tables` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_site_tables`

## English (summary)

Reusable data tables are managed at `/admin/site-tables` (`CMS/admin/site-tables.php` → `CMS/admin/modules/tables/TablesModule.php` → `CMS/admin/views/tables/list.php`, `edit.php`, `settings.php`). Tables are stored in `cms_site_tables` (`columns_json`, `rows_json`, `settings_json`) and embedded with `[site-table id="…"]` or `[table id="…"]`. Optional exports are served at `GET /site-table/export/:id/:format`.

- Views: `list`, `edit`, `settings`. Actions: `save`, `delete`, `duplicate`, `save_settings`.
- Limits: 25 columns, 250 rows, 5000 characters per cell, payload max. 100 KB (columns) / 500 KB (rows).

## Deutsch

### Überblick

| Bestandteil | Datei |
|---|---|
| Einstieg | `CMS/admin/site-tables.php` |
| Modul | `CMS/admin/modules/tables/TablesModule.php` |
| Views | `CMS/admin/views/tables/list.php`, `edit.php`, `settings.php` |
| Rendering | `CMS/core/Services/SiteTableService.php`, `SiteTable/SiteTableTableRenderer.php`, `SiteTableDisplaySettings.php`, `SiteTableContentSource.php` |
| Export-Route | `ThemeRouter::streamSiteTableExport()` → `GET /site-table/export/:id/:format` |

### Ansichten und Aktionen

| URL / `action` | Wirkung |
|---|---|
| `/admin/site-tables` | Liste mit Suche |
| `/admin/site-tables?action=edit[&id=…]` | Tabelle anlegen/bearbeiten (visueller Spalten-/Zeilen-Editor) |
| `/admin/site-tables?action=settings` | Globale Darstellungseinstellungen |
| `save` | Tabelle speichern (Slug wird eindeutig aus dem Namen erzeugt) |
| `duplicate` | Kopie anlegen |
| `delete` | Tabelle löschen |
| `save_settings` | Darstellungseinstellungen speichern |

### Grenzen

| Wert | Grenze |
|---|---|
| Name | 150 Zeichen |
| Beschreibung | 1000 Zeichen |
| Spalten | 25 (Spaltenbezeichnung max. 80 Zeichen) |
| Zeilen | 250 |
| Zelle | 5000 Zeichen, eingeschränktes HTML (Links, Hervorhebungen) |
| JSON-Payload | 100 000 Byte Spalten, 500 000 Byte Zeilen |

### Optionen je Tabelle

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `style_theme` | `default` | `default`, `stripe`, `hover`, `cell-border` |
| `responsive`, `caption`, `aria_label` | – | Barrierefreiheit und mobile Darstellung |
| `enable_search`, `enable_sorting`, `enable_pagination`, `page_size` | an, an, an, 10 | Interaktive Funktionen (Grid.js-basierte Darstellung im Theme) |
| `highlight_rows` | aus | Zeilen hervorheben |
| `allow_export_csv` / `allow_export_json` / `allow_export_excel` | an / aus / aus | Download-Buttons; Export über `/site-table/export/<id>/<csv\|json\|excel>` |
| `custom_css` | leer | Zusätzliche Styles für diese Tabelle |
| `content_source_enabled`, `content_source_mode` (`items`), `content_source_item_keys`, `content_source_category_id` | aus | Tabelle aus CMS-Inhalten (z. B. Beiträge einer Kategorie) füllen |

### Einbindung

```text
[site-table id="7"]
[table id="7"]
```

Beide Schreibweisen sind gleichwertig. Verschachtelte Tabellen werden durch einen Render-Stack gegen Endlosschleifen geschützt. Hub-Sites (siehe [HUBSITES.md](HUBSITES.md)) nutzen dieselbe Tabelle, werden aber über `[hub-site id="…"]` eingebunden.

### Verwandte Dokumente

[HUBSITES.md](HUBSITES.md) · [PAGES.md](PAGES.md)
