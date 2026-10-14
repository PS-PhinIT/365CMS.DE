# 365CMS – Projektdokumentation | Abschnitt: Admin – Plugin-Verwaltung

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/plugins` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_plugins`

## English (summary)

Installed plugins are managed at `/admin/plugins` (`CMS/admin/plugins.php` → `CMS/admin/modules/plugins/PluginsModule.php` → `CMS/admin/views/plugins/list.php`, runtime `CMS\PluginManager`). Plugins live in `CMS/plugins/<slug>/<slug>.php`; their metadata comes from the file header (`Plugin Name`, `Description`, `Version`, `Author`, `Requires`, `Requires Plugins`, `Requires CMS`) and an optional `update.json`. Active slugs are stored in `cms_settings.option_name = 'active_plugins'`. Actions: `activate`, `deactivate`, `delete`. The shipped plugin `cms-importer` (3.0.3) is protected against deletion and upload overwrite.

Plugin admin pages appear under the sidebar section **Plugin Extensions** at `/admin/plugins/<plugin>/<page>`.

## Deutsch

### Liste

Je Plugin: Name, Beschreibung, Version, Autor, Status (aktiv/inaktiv), Abhängigkeiten, verfügbares Update, Schutzstatus.

| Aktion | Wirkung |
|---|---|
| `activate` | Prüft Abhängigkeiten (`Requires Plugins`) und Mindestversion (`Requires CMS` aus Header oder `update.json`), führt den Aktivierungs-Callback aus, löst `plugin_activated` aus und protokolliert im Audit-Log |
| `deactivate` | Entfernt das Plugin aus `active_plugins`, führt den Deaktivierungs-Callback aus |
| `delete` | Löscht das Plugin-Verzeichnis – nur für inaktive Plugins; `cms-importer` ist geschützt |

### Laden zur Laufzeit

`PluginManager::loadPlugins()` bindet für jedes aktive Plugin `CMS/plugins/<slug>/<slug>.php` ein, löst je Plugin `plugin_loaded` und am Ende `plugins_loaded` aus. Fehlende Dateien werden protokolliert, ohne die Seite zu blockieren. Historische Slugs werden über Aliase umgeschrieben (z. B. `cms-companies` → `cms-365netcompanies`, `cms-events` → `cms-365neteventsandspeaker`).

### Plugin-Adminseiten

- Plugins registrieren Menüs im Hook `cms_admin_menu` (`add_menu_page()` / `add_submenu_page()`, WordPress-kompatibel).
- Aufruf: `/admin/plugins/<menü-slug>/<unterseiten-slug>` → `AdminRouter::renderPluginPage()`.
- Die Sidebar sortiert Plugin-Menüs alphabetisch im Abschnitt *Plugin-Erweiterungen*. Titel nach dem Schema `<Familie> | <Name>` (z. B. „365CMS | WP Importer“, „M365 | …“, „PHINIT | …“) zeigen das Präfix kleiner und gedämpft; der volle Titel steht im Tooltip.
- Gibt ein Plugin kein vollständiges Layout aus, bettet der Router die Ausgabe in die Admin-Shell ein und lädt `CMS/assets/css/admin-plugins.css` (einheitliches Plugin-Design). Stylesheets aus der Plugin-Ausgabe werden in den `<head>` verschoben.
- Exceptions im Plugin-Callback werden abgefangen, protokolliert (`error_log`) und als Fehlerkarte angezeigt – die Admin-Shell bleibt bedienbar. AJAX-Aufrufe (`X-Requested-With: XMLHttpRequest`) erhalten JSON-Fehler mit HTTP 500.

### Mitgeliefertes Plugin

| Slug | Name | Version | Zweck |
|---|---|---|---|
| `cms-importer` | CMS WordPress Importer | 3.0.3 | Import von WordPress-WXR (Beiträge, Seiten, Kommentare, Tabellen, SEO-Metadaten, Bilder) und Rank-Math-Settings-JSON (SEO-Defaults, Weiterleitungen); unbekannte Meta-Felder als Markdown-Bericht |

### Verwandte Dokumente

[MARKETPLACE.md](MARKETPLACE.md) · [UPDATES.md](UPDATES.md) · [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md) · [../PANEL-INTEGRATION.md](../PANEL-INTEGRATION.md)
