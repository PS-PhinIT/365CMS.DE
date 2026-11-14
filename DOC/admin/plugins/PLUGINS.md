# 365CMS – Projektdokumentation | Abschnitt: Admin – Plugin-Verwaltung

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable
> **Routen:** `/admin/plugins`, `/admin/plugin-assignment` | **Capability:** `manage_settings` | **CSRF-Aktionen:** `admin_plugins`, `admin_plugin_assignment`

## English (summary)

Installed plugins are managed at `/admin/plugins` (`CMS/admin/plugins.php` → `CMS/admin/modules/plugins/PluginsModule.php` → `CMS/admin/views/plugins/list.php`, runtime `CMS\PluginManager`). Plugins live in `CMS/plugins/<slug>/<slug>.php`; their metadata comes from the file header (`Plugin Name`, `Description`, `Version`, `Author`, `Requires`, `Requires Plugins`, `Requires CMS`) and an optional `update.json`. Active slugs are stored in `cms_settings.option_name = 'active_plugins'`. Actions: `activate`, `deactivate`, `delete`. The shipped plugin `cms-importer` (3.1.0) is protected against deletion and upload overwrite.

Plugin admin pages live at `/admin/plugins/<plugin>/<page>`. They appear in the collapsed sidebar section **"Aktive Plugins"** (active plugins) at the end of the sidebar, in every profile ([ADMIN-MENU.md](../ADMIN-MENU.md#4-plugin-menüs-im-abschnitt-aktive-plugins)). Only a custom layout can place them elsewhere.

The plugin registration (`cms_admin_menu`, `add_menu_page()`, `add_submenu_page()`) is unchanged.

Since 4.0.0, *Einstellungen › Plugins › Plugin-Zuweisung* (`/admin/plugin-assignment`) decides per site in which admin a plugin's menu entries are shown. The sites come from *Einstellungen › MultiSites*. The assignment is a display filter only: plugins without a stored assignment are shown on all sites, and plugin pages stay reachable. Opening one on a site it is not assigned to just shows an info banner.

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
- Seiten aktiver Plugins stehen in jedem Sidebar-Profil gesammelt im letzten Abschnitt **„Aktive Plugins“** (Details: [ADMIN-MENU.md](../ADMIN-MENU.md#4-plugin-menüs-im-abschnitt-aktive-plugins)):
  - Der Abschnitt ist standardmäßig eingeklappt und zeigt die Zahl seiner Einträge; der Aufklappzustand wird je Benutzer gespeichert.
  - Ein Plugin mit **einer** Seite ist ein Link, ein Plugin mit **mehreren** Seiten eine Gruppe mit Menütitel und Icon, gemeinsam alphabetisch sortiert. Fehlt der Hauptpunkt unter den Unterseiten, steht „Übersicht“ als erster Eintrag.
  - Andere Plätze sind nur über ein eigenes Layout im Sidebar-Editor möglich. `cms_admin_menu_set_preferred_parent()` wirkt nicht mehr.
- Die Darstellung liest nur die bereits registrierten Menüs (`get_registered_admin_menus()`). `CMS\Services\AdminMenu\AdminMenuService` setzt sie in den Menübaum ein, `CMS\Services\AdminNavigationService` bereitet ihn für die Ausgabe auf. Registrierung, Hook-Namen, Signaturen und Aufrufreihenfolge sind unverändert; bestehende Plugins funktionieren ohne Anpassung.
- Gibt ein Plugin kein vollständiges Layout aus, bettet der Router die Ausgabe in die Admin-Shell ein und lädt `CMS/assets/css/admin-plugins.css` (einheitliches Plugin-Design). Stylesheets aus der Plugin-Ausgabe werden in den `<head>` verschoben.
- Exceptions im Plugin-Callback werden abgefangen, protokolliert (`error_log`) und als Fehlerkarte angezeigt – die Admin-Shell bleibt bedienbar. AJAX-Aufrufe (`X-Requested-With: XMLHttpRequest`) erhalten JSON-Fehler mit HTTP 500.

### Plugin-Zuweisung

*Einstellungen › Plugins › Plugin-Zuweisung* – Route `/admin/plugin-assignment` (`CMS/admin/plugin-assignment.php` → `CMS/admin/views/settings/plugin-assignment.php`, Logik `CMS\Services\PluginSiteAssignmentService`). Erreichbar über den Reiter „Plugins“ unter *Einstellungen* und in der Seitenleiste über *Plugins › Plugin-Zuweisung* (Profil *Klassisch*) bzw. *Erweitern › Plugin-Zuweisung* (Profil *Strukturiert*).

| Aspekt | Verhalten |
|---|---|
| Zugriff | `isAdmin()` und `manage_settings`; Speichern per POST mit CSRF-Aktion `admin_plugin_assignment`, danach Erfolgsmeldung und Audit-Eintrag `plugin.assignment.update` |
| Tabelle | Eine Zeile je installiertem Plugin (Name, Status aktiv/inaktiv, Anzahl registrierter Admin-Menüeinträge) und eine Checkbox-Spalte je Site; je Spalte die Buttons „Alle“ und „Keine“ |
| Speicherung | Option `admin_plugin_site_assignments` als JSON `{"<plugin-slug>": ["<site-key>", …]}`. Plugins, die allen Sites zugewiesen sind, werden nicht gespeichert. |
| Standard | Plugins ohne gespeicherte Zuweisung gelten für **alle** Sites – nach dem Update verschwindet nichts. Plugins, die nach dem Laden des Formulars installiert wurden, behalten beim Speichern ihren Stand. |
| Wirkung | Die Seitenleiste blendet in der Administration einer Site nur die Menüs der dort zugewiesenen Plugins ein. Bleibt dadurch kein Plugin-Eintrag übrig, zeigt der Abschnitt „Aktive Plugins“ den Hinweis „Für <Site> sind keine Plugins mit Menüeinträgen zugewiesen.“ mit Link hierher. |
| Direktaufruf | Wird eine Plugin-Seite in einer nicht zugewiesenen Site direkt geöffnet, erscheint das Info-Banner „Dieses Plugin ist für <Site> nicht zugewiesen.“ mit Link „Plugin-Zuweisung öffnen“. Die Seite wird nicht blockiert. |

Zuordnung Menü → Plugin (rein lesend): Liegt die Datei des registrierten Callbacks (Haupt- oder Unterpunkt) unter `CMS/plugins/<slug>/`, gehört der Menüeintrag zu diesem Plugin. Sonst wird der `menu_slug` verwendet: Er entspricht einem Plugin-Slug oder beginnt mit `<slug>-`, wobei der längste Treffer zählt. Menüs ohne Zuordnung bleiben immer sichtbar.

Die Site-Liste kommt aus `CMS\Services\AdminSiteContextService` (Option `admin_sites`, gepflegt unter [*Einstellungen › MultiSites*](../system-settings/MULTISITES.md)). Ohne gespeicherte Sites gibt es nur die Haupt-Site aus `SITE_URL`. Die aktive Site wählt der Site-Umschalter oben in der Seitenleiste.

### Mitgeliefertes Plugin

| Slug | Name | Version | Zweck |
|---|---|---|---|
| `cms-importer` | CMS WordPress Importer | 3.1.0 | Import von WordPress-WXR (Beiträge, Seiten, Kommentare, Tabellen, SEO-Metadaten, Bilder) und Rank-Math-Settings-JSON (SEO-Defaults, Weiterleitungen); unbekannte Meta-Felder als Markdown-Bericht |

### Verwandte Dokumente

[MARKETPLACE.md](MARKETPLACE.md) · [UPDATES.md](UPDATES.md) · [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md) · [../PANEL-INTEGRATION.md](../PANEL-INTEGRATION.md)
