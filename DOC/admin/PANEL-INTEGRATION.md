# 365CMS – Projektdokumentation | Abschnitt: Admin – Panel-Integration

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

There are two ways to add an admin screen:

1. **Core page** – an entry file `CMS/admin/<page>.php` that configures `$sectionPageConfig` and includes `CMS/admin/partials/section-page-shell.php`. `AdminRouter` resolves `/admin/<page>` to that file (fallbacks: `admin/modules/<page>/page.php`, `admin/old/<page>.php`).
2. **Plugin page** – registered in the hook `cms_admin_menu` with `add_menu_page()` / `add_submenu_page()` (`CMS/includes/functions/admin-menu.php`) and rendered by `AdminRouter::renderPluginPage()` at `/admin/plugins/<menu>/<page>`.

Both inherit authentication (role `admin`), the sidebar, flash messages, CSRF handling and the Tabler-based admin layout.

## Deutsch

### 1. Core-Adminseite mit der Section-Shell

**Ablauf der Shell (`section-page-shell.php`):**

1. Zugriff prüfen (`access_checker`, sonst `Auth::isAdmin()`), bei Ablehnung Weiterleitung auf `access_denied_route`.
2. Modul laden (`module_file`) und über `module_factory` instanziieren – Fehler ergeben eine Fehlerseite statt eines Fatal Errors.
3. Laufzeitkontext ermitteln (`request_context_resolver`: View, Titel, Assets, Daten je Unteransicht).
4. **POST:** CSRF-Token (`csrf_token`) gegen `csrf_action`/`csrf_actions` prüfen → `post_handler($module, $section, $_POST)` → Ergebnis als Flash-Meldung speichern → Redirect (303) auf `redirect_path_resolver` (Post/Redirect/Get). Mit `render_inline = true` wird stattdessen sofort gerendert (z. B. Validierungsfehler im Editor).
5. **GET:** Daten über `data_loader` bzw. `$module->getData()` laden, `header.php`, `sidebar.php`, View und `footer.php` ausgeben. Fehler in der View werden abgefangen und mit Diagnosehinweis angezeigt.

**Konfigurationsschlüssel von `$sectionPageConfig`:**

| Schlüssel | Bedeutung |
|---|---|
| `route_path` | kanonische Route, z. B. `/admin/meine-seite` |
| `view_file` | Pfad zur View unter `CMS/admin/views/…` (muss existieren) |
| `page_title`, `active_page` | Titel und aktiver Sidebar-Eintrag |
| `section` | logischer Bereich (für Handler und Redirects) |
| `page_assets` | `['css' => [...], 'js' => [...]]` – nur externe Dateien (CSP) |
| `csrf_action` / `csrf_actions` | Token-Aktion(en); `csrf_persistent_validation` für mehrfach nutzbare Tokens |
| `module_file`, `module_factory` | Modulklasse unter `CMS/admin/modules/<bereich>/` |
| `access_checker`, `access_denied_route` | Berechtigungsprüfung |
| `data_loader`, `request_context_resolver` | Datenbeschaffung |
| `post_handler`, `redirect_path_resolver` | Schreibaktionen und Weiterleitungsziel |
| `template_vars` | zusätzliche Variablen für die View |
| `alert_session_key`, `invalid_token_message`, `unknown_action_message` | Meldungen |
| `guard_constant` | Konstante, die Views vor Direktaufruf schützt |

**Minimalbeispiel:**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

use CMS\Auth;

$sectionPageConfig = [
    'route_path'     => '/admin/beispiel',
    'view_file'      => __DIR__ . '/views/system/beispiel.php',
    'page_title'     => 'Beispiel',
    'active_page'    => 'settings',
    'csrf_action'    => 'admin_beispiel',
    'module_file'    => __DIR__ . '/modules/system/BeispielModule.php',
    'module_factory' => static fn (): BeispielModule => new BeispielModule(),
    'access_checker' => static fn (): bool => Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability('manage_settings'),
    'post_handler'   => static fn (BeispielModule $m, string $s, array $post): array
        => ($post['action'] ?? '') === 'save' ? $m->save($post) : ['success' => false, 'error' => 'Unbekannte Aktion.'],
];

require __DIR__ . '/partials/section-page-shell.php';
```

Rückgabe des Handlers: `['success' => bool, 'message' => '…']` bzw. `['success' => false, 'error' => '…', 'details' => [...]]`.

**Alt-Routen** werden mit `CMS/admin/partials/redirect-alias-shell.php` umgeleitet (`$adminRedirectAliasConfig` mit `access_checker`, `target_url`, `fallback_url`).

**Sidebar-Eintrag:** Core-Menüs sind in `CMS/admin/partials/sidebar.php` definiert; die Sichtbarkeit hängt am Core-Modul (`CoreModuleService::isAdminPageEnabled()`).

### 2. Plugin-Adminseite

```php
\CMS\Hooks::addAction('cms_admin_menu', static function (): void {
    add_menu_page(
        'Mein Plugin',             // Seitentitel
        'PHINIT | Mein Plugin',    // Menütitel (Schema „Familie | Name“)
        'manage_settings',         // Capability
        'mein-plugin',             // Menü-Slug
        [MeinPluginAdmin::class, 'renderDashboard'],
        '📊'                       // Icon: SVG-Markup, Bild-URL oder kurzer Text/Emoji; leer = Standard-Plugin-Icon
    );
    add_submenu_page('mein-plugin', 'Einstellungen', 'Einstellungen', 'manage_settings',
        'mein-plugin-settings', [MeinPluginAdmin::class, 'renderSettings']);
});
```

- URL: `/admin/plugins/mein-plugin/mein-plugin-settings`; die aktive Unterseite steht zusätzlich in `$_GET['page']`.
- Der Callback gibt **nur den Inhalt** aus; der Router bettet ihn in die Admin-Shell ein und ergänzt `assets/css/admin-plugins.css`. Ältere Plugins dürfen `renderAdminLayoutStart()`/`renderAdminLayoutEnd()` verwenden.
- AJAX an dieselbe URL mit Header `X-Requested-With: XMLHttpRequest` ruft den Callback ohne Layout auf.
- Eigene Formulare brauchen eigene CSRF-Tokens (`\CMS\Security::instance()->generateToken('mein_plugin')` / `verifyToken()`).

### Gestaltung

Admin-Oberfläche auf Basis von **Tabler** (`CMS/assets/tabler/`) mit Tabler-Icons, CSS in `CMS/assets/css/admin*.css`, JavaScript in `CMS/assets/js/admin-*.js`. Keine Inline-Skripte oder Inline-Styles verwenden – die Content-Security-Policy blockiert sie.

### Verwandte Dokumente

[ADMIN-API-AJAX.md](ADMIN-API-AJAX.md) · [FILESTRUCTURE.md](FILESTRUCTURE.md) · [plugins/PLUGINS.md](plugins/PLUGINS.md) · [../plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md)
