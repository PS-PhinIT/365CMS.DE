# 365CMS – Projektdokumentation | Abschnitt: Admin – Plugin-Verwaltung

> **Stand:** 2026-11-09 | **Version:** 4.0.0 | **Status:** Stable
> **Routen:** `/admin/plugins`, `/admin/plugin-assignment` | **Capability:** `manage_settings` | **CSRF-Aktionen:** `admin_plugins`, `admin_plugin_assignment`

## English (summary)

Installed plugins are managed at `/admin/plugins` (`CMS/admin/plugins.php` → `CMS/admin/modules/plugins/PluginsModule.php` → `CMS/admin/views/plugins/list.php`, runtime `CMS\PluginManager`). Plugins live in `CMS/plugins/<slug>/<slug>.php`; their metadata comes from the file header (`Plugin Name`, `Description`, `Version`, `Author`, `Requires`, `Requires Plugins`, `Requires CMS`) and an optional `update.json`. Active slugs are stored in `cms_settings.option_name = 'active_plugins'`. Actions: `activate`, `deactivate`, `delete`. The shipped plugin `cms-importer` (3.0.3) is protected against deletion and upload overwrite.

Plugin admin pages appear in the sidebar group **Plugins** at `/admin/plugins/<plugin>/<page>`: one entry per plugin, with its sub-pages nested below it. Since 4.0.0, *Einstellungen › Plugins › Plugin-Zuweisung* (`/admin/plugin-assignment`) decides per site (phinit.de, 365network.de, …) in which admin a plugin's menu entries are shown. It is a display filter only: the plugin registration (`cms_admin_menu`, `add_menu_page()`, `add_submenu_page()`) is unchanged, plugins without a stored assignment are shown on all sites, and plugin pages stay reachable. Opening one on a site it is not assigned to just shows an info banner.

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
- Die Seitenleiste zeigt Plugin-Menüs in der Gruppe **Plugins** (zwischen den Core-Gruppen und *Verwaltung*), alphabetisch nach dem Namen ohne Präfix. Titel nach dem Schema `<Familie> | <Name>` (z. B. „365CMS | WP Importer“, „M365 | …“, „PHINIT | …“) zeigen das Präfix kleiner und gedämpft; der volle Titel steht im Tooltip.
- Seit 4.0.0 erhält jedes Plugin **einen** Eintrag. Registrierte Unterseiten erscheinen eingerückt darunter und machen den Eintrag aufklappbar; fehlt der Hauptpunkt unter den Unterseiten, steht „Übersicht“ als erster Unterpunkt. Plugins ohne Unterseiten bleiben ein einfacher Link. Ohne gültiges Icon erhält ein Plugin das neutrale Standard-Icon (Puzzleteil); die akzeptierten Icon-Formate stehen in [PANEL-INTEGRATION.md](../PANEL-INTEGRATION.md).
- Diese Darstellung liest nur die bereits registrierten Menüs (`get_registered_admin_menus()`), gruppiert, filtert und rendert sie (`CMS\Services\AdminNavigationService`). Registrierung, Hook-Namen, Signaturen und Aufrufreihenfolge sind unverändert; bestehende Plugins funktionieren ohne Anpassung.
- Gibt ein Plugin kein vollständiges Layout aus, bettet der Router die Ausgabe in die Admin-Shell ein und lädt `CMS/assets/css/admin-plugins.css` (einheitliches Plugin-Design). Stylesheets aus der Plugin-Ausgabe werden in den `<head>` verschoben.
- Exceptions im Plugin-Callback werden abgefangen, protokolliert (`error_log`) und als Fehlerkarte angezeigt – die Admin-Shell bleibt bedienbar. AJAX-Aufrufe (`X-Requested-With: XMLHttpRequest`) erhalten JSON-Fehler mit HTTP 500.

### Plugin-Zuweisung

*Einstellungen › Plugins › Plugin-Zuweisung* – Route `/admin/plugin-assignment` (`CMS/admin/plugin-assignment.php` → `CMS/admin/views/settings/plugin-assignment.php`, Logik `CMS\Services\PluginSiteAssignmentService`). Erreichbar über den Reiter „Plugins“ unter *Einstellungen* und über *Verwaltung › Plugins › Plugin-Zuweisung* in der Seitenleiste.

| Aspekt | Verhalten |
|---|---|
| Zugriff | `isAdmin()` und `manage_settings`; Speichern per POST mit CSRF-Aktion `admin_plugin_assignment`, danach Erfolgsmeldung und Audit-Eintrag `plugin.assignment.update` |
| Tabelle | Eine Zeile je installiertem Plugin (Name, Status aktiv/inaktiv, Anzahl registrierter Admin-Menüeinträge) und eine Checkbox-Spalte je Site; je Spalte die Buttons „Alle“ und „Keine“ |
| Speicherung | Option `admin_plugin_site_assignments` als JSON `{"<plugin-slug>": ["<site-key>", …]}`. Plugins, die allen Sites zugewiesen sind, werden nicht gespeichert. |
| Standard | Plugins ohne gespeicherte Zuweisung gelten für **alle** Sites – nach dem Update verschwindet nichts. Plugins, die nach dem Laden des Formulars installiert wurden, behalten beim Speichern ihren Stand. |
| Wirkung | Die Seitenleiste blendet in der Administration einer Site nur die Menüs der dort zugewiesenen Plugins ein. Sind alle Plugins ausgeblendet, zeigt die Gruppe *Plugins* den Hinweis „Für <Site> sind keine Plugins mit Menüeinträgen zugewiesen.“ mit Link hierher. |
| Direktaufruf | Wird eine Plugin-Seite in einer nicht zugewiesenen Site direkt geöffnet, erscheint das Info-Banner „Dieses Plugin ist für <Site> nicht zugewiesen.“ mit Link „Plugin-Zuweisung öffnen“. Die Seite wird nicht blockiert. |

Zuordnung Menü → Plugin (rein lesend): Liegt die Datei des registrierten Callbacks (Haupt- oder Unterpunkt) unter `CMS/plugins/<slug>/`, gehört der Menüeintrag zu diesem Plugin. Sonst wird der `menu_slug` verwendet: Er entspricht einem Plugin-Slug oder beginnt mit `<slug>-`, wobei der längste Treffer zählt. Menüs ohne Zuordnung bleiben immer sichtbar.

Die Site-Liste kommt aus `CMS\Services\AdminSiteContextService` (Standard `phinit.de` und `365network.de`, optional über die Option `admin_sites` erweiterbar). Die aktive Site wählt der Site-Umschalter oben in der Seitenleiste.

### Mitgeliefertes Plugin

| Slug | Name | Version | Zweck |
|---|---|---|---|
| `cms-importer` | CMS WordPress Importer | 3.0.3 | Import von WordPress-WXR (Beiträge, Seiten, Kommentare, Tabellen, SEO-Metadaten, Bilder) und Rank-Math-Settings-JSON (SEO-Defaults, Weiterleitungen); unbekannte Meta-Felder als Markdown-Bericht |

### Verwandte Dokumente

[MARKETPLACE.md](MARKETPLACE.md) · [UPDATES.md](UPDATES.md) · [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md) · [../PANEL-INTEGRATION.md](../PANEL-INTEGRATION.md)
