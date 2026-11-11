# 365CMS – Projektdokumentation | Abschnitt: Admin – Panel-Integration

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

There are two ways to add an admin screen:

1. **Core page** – an entry file `CMS/admin/<page>.php` that configures `$sectionPageConfig` and includes `CMS/admin/partials/section-page-shell.php`. `AdminRouter` resolves `/admin/<page>` to that file (fallbacks: `admin/modules/<page>/page.php`, `admin/old/<page>.php`).
2. **Plugin page** – registered in the hook `cms_admin_menu` with `add_menu_page()` / `add_submenu_page()` (`CMS/includes/functions/admin-menu.php`) and rendered by `AdminRouter::renderPluginPage()` at `/admin/plugins/<menu>/<page>`.

Both inherit authentication (role `admin`), the sidebar, flash messages, CSRF handling and the Tabler-based admin layout.

Since 4.0.0 the sidebar is data-driven ([ADMIN-MENU.md](ADMIN-MENU.md)):

- Core pages are rows in `CMS\Services\AdminMenu\CoreCatalog`.
- The profiles *Classic* and *Structured* (`ProfileLayouts`), or a custom layout, place them; `AdminMenuService` and `MenuBuilder` build the tree.
- Plugin menus are read unchanged from `get_registered_admin_menus()`. They are placed in the profile's fallback group (*Plugins* / *Erweitern*), in a group of their own or in a preferred group, and filtered by the per-site plugin assignment.
- `CMS\Services\AdminNavigationService` adds the active entry and badges for rendering.

The plugin API (`cms_admin_menu`, `add_menu_page()`, `add_submenu_page()`) is unchanged. Pages can load ES modules via `page_assets['modules']`.

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
| `page_title`, `active_page` | Titel und aktiver Sidebar-Eintrag (Wert `page` der Katalogzeile in `CMS\Services\AdminMenu\CoreCatalog`, z. B. `posts`, `settings`) |
| `section` | logischer Bereich (für Handler und Redirects) |
| `page_assets` | `['css' => [...], 'js' => [...], 'modules' => [...]]` – nur externe Dateien (CSP); `modules` werden seit 4.0.0 im Footer als `<script type="module">` geladen |
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

**Sidebar-Eintrag:** Jede Core-Seite braucht zwei Dinge (Details in [ADMIN-MENU.md](ADMIN-MENU.md#3-katalog-der-core-seiten)):

- eine Zeile in `CMS\Services\AdminMenu\CoreCatalog` (ID, Label, Route, Icon, Capabilities, Seitendatei, `page`-Slug, bei Kacheln die Kategorie),
- einen Platz in beiden Profilen in `ProfileLayouts`.

`php TESTS/admin-menu/run.php` prüft Katalog und Profile sowie die Capabilities, die in der Seitendatei geprüft werden. Die Sichtbarkeit hängt weiterhin am Core-Modul (`CoreModuleService::isAdminPageEnabled()`). `CMS/admin/partials/sidebar.php` rendert nur.

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

**Darstellung in der Seitenleiste (seit 4.0.0):** Die Registrierung oben bleibt unverändert – Hook-Name, Signaturen und Aufrufreihenfolge sind dieselben, bestehende Plugins brauchen keine Anpassung. `AdminMenuService` liest das Ergebnis von `get_registered_admin_menus()` nur aus:

- Ein Plugin-Menü ohne Unterseiten wird ein einfacher Link in der Auffanggruppe des Profils (*Plugins* bzw. *Erweitern*, alphabetisch nach Menütitel).
- Ein Menü mit Unterseiten wird eine eigene Gruppe direkt nach der Auffanggruppe. Ist der Hauptpunkt nicht selbst als Unterseite registriert, wird „Übersicht“ als erster Eintrag ergänzt.
- Mit `cms_admin_menu_set_preferred_parent()` kann ein Plugin je Profil eine andere Gruppe wünschen ([ADMIN-MENU.md](ADMIN-MENU.md#4-plugin-menüs-und-wunschplätze-je-profil)).
- Menüs mit gesetztem `hidden` entfallen.
- Aktiv ist der Eintrag, dessen Pfad der aufgerufenen URL entspricht (`aria-current="page"`); seine Gruppe klappt dann automatisch auf.
- **Icon** (6. Parameter von `add_menu_page()`): Tabler-Klasse (`ti-chart-bar` bzw. `ti ti-chart-bar`), Inline-`<svg>` bis 20.000 Zeichen (wird als Bild ausgegeben), `data:image/…;base64`-URI, Pfad oder URL derselben Herkunft, oder Text/Emoji mit höchstens 4 Zeichen. Alles andere sowie ein leerer Wert ergibt das neutrale Standard-Icon (Tabler `puzzle`).
- **Plugin-Zuweisung:** Unter *Einstellungen › Plugins › Plugin-Zuweisung* wird festgelegt, in welcher Site-Administration die Menüs eines Plugins erscheinen (Standard: alle Sites). Wird eine Plugin-Seite in einer nicht zugewiesenen Site direkt aufgerufen, zeigt die Shell nur ein Info-Banner; die Seite bleibt nutzbar. Details: [plugins/PLUGINS.md](plugins/PLUGINS.md#plugin-zuweisung).

### Gestaltung

Admin-Oberfläche auf Basis von **Tabler** (`CMS/assets/tabler/`) mit Tabler-Icons, CSS in `CMS/assets/css/admin*.css`, JavaScript in `CMS/assets/js/admin-*.js`. Keine Inline-Skripte oder Inline-Styles verwenden – die Content-Security-Policy blockiert sie.

Seit 4.0.0 gelten zusätzlich:

- **Design-Tokens** als CSS-Custom-Properties in `CMS/assets/css/admin-tabler.css` (`--cms-bg`, `--cms-sidebar-bg`, `--cms-border`, `--cms-text`, `--cms-text-muted`, `--cms-accent`, `--cms-accent-soft`, `--cms-accent-strong`, `--cms-hover`, Radien `--cms-radius` 8 px / `--cms-radius-card` 12 px, Seitenleiste 264 px bzw. 76 px eingeklappt), auf Tablers `--tblr-*`-Variablen abgebildet. Eigene Styles sollten diese Tokens statt fester Farben nutzen.
- **Site-Akzent:** `<html data-site="…">` und die Akzentfarbe der Site aus *Einstellungen › MultiSites* (Standard `#4f46e5`). `partials/header.php` setzt sie als `--cms-accent`, `--cms-accent-soft` und `--cms-accent-strong`.
- **Schriften** lokal unter `CMS/assets/fonts/`: Inter (Oberfläche), Source Serif 4 (Beitragstitel im Editor), JetBrains Mono (Code).
- **Layout-Shell** in `CMS/assets/css/admin-shell.css` und den ES-Modulen unter `CMS/assets/js/admin-shell/` (Seitenleiste, Drawer, Site-Umschalter, Benutzermenü). Neue Skripte als kleine ES-Module über `page_assets['modules']` einbinden, ohne Build-Schritt und ohne Inline-Handler.
- **Barrierefreiheit:** sichtbarer Fokus (2 px Akzent-Outline mit Abstand), Touch-Ziele ≥ 44 px, `aria-label` an Icon-Buttons, `prefers-reduced-motion` respektieren.

### Verwandte Dokumente

[ADMIN-API-AJAX.md](ADMIN-API-AJAX.md) · [FILESTRUCTURE.md](FILESTRUCTURE.md) · [plugins/PLUGINS.md](plugins/PLUGINS.md) · [../plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md)
