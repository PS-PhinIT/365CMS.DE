# 365CMS – Projektdokumentation | Abschnitt: Admin – Inhalts-Einstellungen

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/settings?tab=content` | **Capability:** `manage_settings`

## English (summary)

The sidebar entry *Pages & Posts → Settings* opens the `content` tab of the general settings page (`CMS/admin/settings.php` → `CMS/admin/modules/settings/SettingsModule.php` → `CMS/admin/views/settings/general.php`). It controls the editor type, default statuses, editor widths, post permalink structure, archive bases for categories/tags (DE/EN) and offers a repair action for imported slugs.

## Deutsch

### Einstellungen

| Feld | Option in `cms_settings` | Werte / Standard |
|---|---|---|
| Editor | `setting_editor_type` | `editorjs` (Standard) oder `suneditor` |
| Standardstatus Seiten | `setting_page_default_status` | `draft` (Standard), `published`, `private` |
| Standardstatus Beiträge | `setting_post_default_status` | `draft` (Standard), `published` |
| Editorbreite Seiten / Beiträge | `setting_page_editor_width`, `setting_post_editor_width` | 320–1600 px, Schrittweite 10 |
| Beitrags-Permalinks | `setting_post_permalink_structure`, `setting_post_permalink_custom` | Presets `blog` (`/blog/%postname%`), `slug`, `year`, `dated`, `custom` |
| Kategorie-Archiv DE / EN | `routing.category_base_de`, `routing.category_base_en` | `kategorie` / `category` |
| Tag-Archiv DE / EN | `routing.tag_base_de`, `routing.tag_base_en` | `tag` / `tag` |

**Validierung:**
- Beim Preset `custom` ist eine Struktur Pflicht, erlaubt sind `%year%`, `%monthnum%`, `%day%`, `%postname%` (z. B. `/wissen/%postname%`).
- Kategorie- und Tag-Basis müssen je Sprache unterschiedlich sein, sonst würden beide Archive dieselbe URL belegen.
- Fehlerhafte Felder werden markiert (`aria-invalid`), die Eingaben bleiben erhalten.

### Wartungsaktion „Importierte Slugs reparieren“

`action=repair_imported_slugs` (`SettingsModule::repairImportedSlugs()`) bereinigt Slugs, die z. B. beim WordPress-Import mit Sonderzeichen, Prozent-Kodierung oder Dubletten angelegt wurden. Vorher ein Backup erstellen (`/admin/backups`).

### Allgemeine Einstellungen (Tab `general`)

Die übrigen Felder der Seite (Website-Name, URL, Logo, Favicon, Sprache, Zeitzone, Datumsformate, Beiträge pro Seite, Kommentare, Wartungsmodus, Marketplace-URLs, Site-URL-Migration) sind in [../system-settings/SYSTEM.md](../system-settings/SYSTEM.md) beschrieben.

### Auswirkungen

- Eine Änderung der Permalink-Struktur ändert alle Beitrags-URLs. Für bestehende Links sollten Weiterleitungen angelegt werden ([../seo/REDIRECTS.md](../seo/REDIRECTS.md)).
- Die Archiv-Basen wirken auf `ThemeRouter`/`ThemeArchiveRepository` und auf Sitemap-URLs.

### Verwandte Dokumente

[PAGES.md](PAGES.md) · [POSTS.md](POSTS.md) · [../system-settings/SYSTEM.md](../system-settings/SYSTEM.md)
