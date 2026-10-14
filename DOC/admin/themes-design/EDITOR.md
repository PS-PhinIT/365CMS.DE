# 365CMS – Projektdokumentation | Abschnitt: Admin – Theme-Explorer (Dateieditor)

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/theme-explorer` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_theme_explorer`

## English (summary)

The theme explorer (`CMS/admin/theme-explorer.php` → `CMS/admin/modules/themes/ThemeEditorModule.php` → `CMS/admin/views/themes/editor.php`) is a sandboxed file editor for the **active theme**. Only action: `save_file`. Editable extensions: `php`, `css`, `js`, `json`, `html`, `txt`, `md`; max. 1 MB per file. Writes are atomic (temp file + rename).

> The content editor (Editor.js) for pages and posts is documented in [../pages-posts/PAGES.md](../pages-posts/PAGES.md) and [../../assets/editorjs/README.md](../../assets/editorjs/README.md).

## Deutsch

### Funktionsumfang

- **Dateibaum** des aktiven Themes (max. Tiefe 8, max. 600 Einträge, max. 200 je Verzeichnis). Übersprungen werden `vendor`, `node_modules`, `cache`, `.git`, `dist`, `build`.
- **Editor** (Monospace-Textfeld) für die gewählte Datei (`?file=<relativer Pfad>`).
- **Speichern** (`action=save_file`):
  - Pfad muss dem Muster `[A-Za-z0-9._/-]` entsprechen und im Theme-Verzeichnis liegen (Schutz vor `..`).
  - Erlaubte Endungen: `php`, `css`, `js`, `json`, `html`, `txt`, `md`.
  - Größe ≤ **1 MB**.
  - PHP-Dateien werden vor dem Schreiben auf Syntaxfehler geprüft.
  - Schreiben über temporäre Datei (`cmstheme_*`) und atomaren Austausch.
- Fehler werden mit Fehlercode und optionalem Fehlerbericht ([../diagnose/DIAGNOSE.md](../diagnose/DIAGNOSE.md)) angezeigt.

### Sicherheitshinweise

- Änderungen wirken sofort im Frontend. Vor größeren Änderungen ein Backup erstellen (`/admin/backups`).
- Theme-Updates aus dem Marktplatz überschreiben manuelle Änderungen. Für dauerhafte Anpassungen ein eigenes (Child-)Theme verwenden.
- Auf Produktivsystemen empfiehlt sich, Dateiänderungen über Git/Deployment statt im Browser vorzunehmen.

### Abgrenzung

| Seite | Zweck |
|---|---|
| `/admin/theme-editor` | Optionen des Themes über dessen Customizer ([CUSTOMIZER.md](CUSTOMIZER.md)) |
| `/admin/theme-explorer` | Quelltext der Theme-Dateien bearbeiten (dieses Dokument) |
| `/admin/menu-editor` | Navigationsmenüs ([MENUS.md](MENUS.md)) |

### Verwandte Dokumente

[CUSTOMIZER.md](CUSTOMIZER.md) · [../../theme/THEME-DEVELOPMENT.md](../../theme/THEME-DEVELOPMENT.md)
