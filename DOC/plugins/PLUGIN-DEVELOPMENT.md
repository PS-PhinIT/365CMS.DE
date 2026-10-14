> **Website:** [365CMS.DE](https://365cms.de/) | **Version:** 3.5.0
> **Datum:** 2026-09-06 | **Status:** Stable – **Zuletzt aktualisiert am:** 2026-10-02 (Abgleich mit `PluginManager`, `AdminRouter`, `PluginDashboardRegistry`, `Security`)
> **Kurzbeschreibung:** Complete development reference for 365CMS plugins, covering lifecycle, hooks, admin and member integration, persistence, security, assets, routing, testing, and release quality.

# 365CMS Plugin Development

## English

### User-friendly guide

365CMS plugins are self-contained feature packages. They run from `CMS/plugins/<slug>/`, register behavior through the CMS hook system, expose only the pages and data they need, and must keep their own presentation assets namespaced.

#### Recommended plugin structure

```text
CMS/plugins/my-plugin/
├── my-plugin.php
├── includes/
│   ├── class-plugin.php
│   ├── class-admin.php
│   └── class-member.php
├── admin/
│   └── page.php
├── templates/
│   ├── frontend.php
│   └── member-widget.php
└── assets/
    ├── css/my-plugin.css
    └── js/my-plugin.js
```

Keep the bootstrap small. Load classes from the plugin directory, initialize one clear runtime entry point, and register hooks from that entry point. Do not copy core classes or depend on another plugin without checking that it is active.

#### User-visible integration

Use `/admin/plugins` to activate or deactivate the plugin. A plugin admin page should be reachable below `/admin/plugins/<plugin>/<page>` after registering a top-level menu and optional submenus. Member features should be exposed through a capability-aware menu item or dashboard widget and should require an authenticated member.

Use templates for HTML, the central admin shell for admin pages, and plugin-prefixed CSS such as `.my-plugin-card`. Do not use generic classes such as `.card` for plugin-specific styling, inline styles, inline scripts, or unescaped request values.

#### Safe form workflow

1. Render a persistent CSRF token with the action-specific token name.
2. Accept state changes only through POST.
3. Verify authentication, capability, token, and normalized input on the server.
4. Persist through a service or repository and return an explicit success or error.
5. Redirect after a successful admin POST and show a flash message.

#### Database and migrations

Create plugin tables idempotently with `InnoDB`, `utf8mb4`, and the runtime database prefix. Add indexes for frequent filters. Store structured values as JSON in `TEXT` or `LONGTEXT` and handle encoding errors explicitly. Migrations must be repeatable and must not assume a hard-coded `cms_` prefix.

### Technical reference

#### Bootstrap and lifecycle

Every PHP file starts with `declare(strict_types=1)` and an `ABSPATH` guard. A plugin bootstrap normally:

1. declares the CMS plugin header;
2. defines version, path, and optional URL constants;
3. requires the plugin classes;
4. creates the singleton or equivalent bootstrap object.

The runtime emits `cms_init`, plugin-specific hooks, `cms_admin_menu`, `plugin_loaded`, and `plugins_loaded` through `CMS\Hooks`. A plugin should register its own hooks from `cms_init` or its constructor and avoid work at file load time except safe definitions and requires.

#### Core hooks used by plugins

| Hook | Type | Use |
|---|---|---|
| `cms_init` | action | initialize runtime services and idempotent migrations |
| `cms_admin_menu` | action | register admin menus and submenus |
| `head` | action | add approved head-level output or assets |
| `after_header` | action | add early frontend content |
| `before_footer` | action | add footer-area output (runs once per request, before `footer.php`) |
| `body_end` | action | add scripts or modals through the asset policy (runs once per request, before `</body>`) |
| `plugin_loaded` | action | react to a plugin that loaded successfully |
| `plugins_loaded` | action | run work that requires all active plugins |
| `member_menu_items` | filter | add a member navigation item |
| `member_dashboard_widgets` | filter | register a member dashboard widget |
| `cms_sitemap_entries` | filter | add public plugin URLs to `plugins.xml` of the sitemap bundle |
| `search_results` | filter | add plugin hits to the site search (`/search`) and `SearchService::searchAll()` |

The canonical hook names and signatures are maintained in the Core hook documentation. Do not invent a second hook bus.

#### Sitemap, search and SEO metadata for public plugin pages

- **Sitemap:** `cms_sitemap_entries` receives an array and returns it with additional entries `['url' => 'path/relative/to/SITE_URL', 'lastmod' => '2026-09-29 12:00:00', 'changefreq' => 'daily', 'priority' => 0.6]`. Absolute URLs are accepted for the site's own host only; foreign hosts and duplicates are dropped. The core writes the entries to `plugins.xml` (daily via `cms_cron_daily` or *SEO → Sitemap*). List only indexable pages (no filter, search or sort variants).
- **Search:** `search_results($results, $query, $limit, $context = [])` appends hits with at least `title` and `url` (own host or path relative to `SITE_URL`) plus optional `excerpt`, `_type` (search scope, e.g. `messagecenter`), `_type_label` and `date` (publication or modification date, used by *newest first*). The core computes relevance from title and excerpt and sorts plugin hits together with pages and posts (`?sort=relevance` or `?sort=date`), so return only hits that contain every search word. `$context` contains `type`, `locale` and `source`; the search page only asks plugins when no core scope (`pages`, `posts`, …) is selected, and `?type=<_type>` shows only that plugin's hits. Split the query into words and match all of them against the plugin tables with prepared statements.
- **Meta tags:** set title, description, canonical and robots of plugin pages per request with `\CMS\Services\SEOService::getInstance()->setRequestMeta([...])` (keys `title`, `description`, `canonical_url`, `robots_index`, `robots_follow`, `og_type`, `schema_type`). `SeoAnalysisService::resolveMetaTitle()` applies the title format from the SEO settings.

#### Admin menu and routing contract

Use `add_menu_page()` for a top-level menu and `add_submenu_page()` for child pages. Both helpers store capability, title, slug, callback, and menu relationships in the central registry. The admin router resolves:

- `/admin/plugins/:plugin/:page` for registered plugin pages;
- the plugin callback or a compatibility fallback;
- normal and AJAX output paths;
- the central shell when the callback does not provide a complete layout.

Admin callbacks should render content only. They should use `renderAdminLayoutStart()` and `renderAdminLayoutEnd()` only when the compatibility path requires it, because the router already supplies the current shell for ordinary plugin callbacks.

#### Unified admin design

The admin router loads `assets/css/admin-plugins.css` after the plugin stylesheets on every plugin page. It styles the shared building blocks inside `.cms-plugin-admin-content` in the flat 365CMS core look: `.admin-page-header` (title, description, `.header-actions`), `.dashboard-grid` with `.stat-card` (`.stat-number`, `.stat-label`), `.admin-card` with `*-panel-header`, info/note/action cards (`*-info-card`, `*-note-card`, `*-action-card` with `__eyebrow`, `__value`, `__title`, `__text`), left sub-navigation, `.users-table` and empty states. Use these classes instead of plugin-specific card, hero or emoji styling; decorative icon tiles are hidden. Icon-only buttons use Tabler icons (`<i class="ti ti-pencil" aria-hidden="true"></i>`) plus a `title`. The top-level menu title follows the pattern `<family> | <name>`: `365CMS | …` for the public plugins (e.g. `365CMS | Kontakt`), `M365 | …` for the M365 plugins by PhinIT and `PHINIT | …` for the other PhinIT plugins. The core sorts plugin menus alphabetically in the **Plugin Extensions** section, so each family stays together; submenus and page titles keep the plain feature name.

For path routes (`/admin/plugins/:plugin/:page`) the router sets `$_GET['page']` to the requested page before calling the callback, so dispatchers and the active sidebar entry can rely on it. Pass the active page slug to `renderAdminLayoutStart()`.

#### Content Security Policy

In production the CSP is enforced with request nonces and Trusted Types. Inline `<script>` blocks without a nonce and inline event handlers (`onclick`, `onchange`, `onsubmit`) do not run. Put JavaScript in plugin files and wire it through `data-*` attributes; for simple confirmations use the core attribute `data-cms-confirm="…"`. Unavoidable inline `<style>`/`<script>` blocks must carry `\CMS\Security::instance()->nonceAttr()`.

#### Localized public routes

`includes/functions/plugin-public-i18n.php` provides `cms_plugin_public_language()`, `cms_plugin_public_path_without_lang()`, `cms_plugin_public_localized_path()` and related helpers for `/en/…` routes. Plugins must not depend on a shared folder outside their own directory.

#### Example: protected settings page

```php
public function renderSettingsPage(): void
{
    $security = \CMS\Security::instance();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$security->verifyPersistentToken(
            (string) ($_POST['csrf_token'] ?? ''),
            'my_plugin_settings'
        )) {
            $this->addError('Security validation failed.');
        } elseif (!\CMS\Auth::instance()->hasCapability('manage_settings')) {
            $this->addError('Permission denied.');
        } else {
            $value = sanitize_text_field((string) ($_POST['my_setting'] ?? ''));
            \CMS\Services\SettingsService::getInstance()->set('my_plugin', 'setting', $value);
            $this->redirectWithNotice('Saved.');
        }
    }

    $csrfToken = $security->generateToken('my_plugin_settings');
    include MY_PLUGIN_PATH . 'admin/page.php';
}
```

`generateToken()` creates the token (1 h, up to 20 per action); `verifyToken()` consumes it once, `verifyPersistentToken()` checks without consuming (useful for AJAX forms that submit several times). Never rely on hidden fields alone; authorization and CSRF are both server-side checks.

#### Member integration

Register member navigation through `member_menu_items` and widgets through `member_dashboard_widgets`. Member routes are resolved by `MemberController`, which enforces authentication, private cache headers, plugin slug normalization, and template overrides. A plugin must not expose a member page as a public route accidentally.

Use the plugin dashboard registry when the plugin needs structured member tabs or settings. Restrict user data by the current member ID, escape output, and use a plugin-specific capability for privileged operations.

#### Persistence and SQL

Use `\CMS\Database::instance()` and its prepared statement API:

```php
$db = \CMS\Database::instance();
$prefix = $db->getPrefix();
$statement = $db->prepare(
    "SELECT id, status FROM {$prefix}my_plugin_items WHERE user_id = ?"
);
$statement->execute([$userId]);
$rows = $statement->fetchAll() ?: [];
```

Only trusted, internally generated identifiers may appear in interpolated SQL. Values always use bindings. Use `getPrefix()` rather than hard-coded table names, add indexes for `user_id` and status fields, and use an explicit schema version for migrations.

#### REST, AJAX, and routes

Expose an endpoint only when the feature requires it. Use the core router or the established CMS endpoint registration, restrict methods, require authentication for member data, require a capability for admin data, and validate CSRF for browser state changes. Return explicit JSON errors with appropriate status codes. Never use an unrestricted public permission callback for private plugin data.

#### Assets and templates

Use plugin-specific CSS and JavaScript files. Register or enqueue them through the current CMS asset loader so cache versions and CSP rules remain consistent. Keep JavaScript external and use `data-*` configuration rather than inline scripts. Resolve template paths from the plugin directory and provide theme overrides only through the documented template-loader contract.

#### Security and error handling

- guard every direct PHP entry point;
- sanitize text, email, URL, and HTML according to the input type;
- escape text, attributes, URLs, and allowed HTML at output;
- verify capability and CSRF before every write;
- use rate limiting for public or expensive endpoints;
- do not log secrets, tokens, raw credentials, or unnecessary personal data;
- return explicit errors rather than silent success fallbacks;
- keep optional integrations fail-closed when their dependency is disabled.

#### Test and release gate

Before release, run syntax checks and the existing project test or quality commands, test activation/deactivation and missing-file behavior, exercise admin and member authorization, test invalid input and CSRF rejection, inspect database migrations on an empty and an existing installation, verify asset loading and CSP compatibility, and update the plugin's documentation, changelog, and version metadata.

## Deutsch

### Anwenderleitfaden

365CMS-Plugins sind eigenständige Funktionspakete. Sie laufen unter `CMS/plugins/<slug>/`, registrieren Verhalten über das CMS-Hook-System, stellen nur die benötigten Seiten und Daten bereit und kapseln ihre Darstellungs-Assets mit eigenen Präfixen.

#### Empfohlene Plugin-Struktur

```text
CMS/plugins/my-plugin/
├── my-plugin.php
├── includes/
│   ├── class-plugin.php
│   ├── class-admin.php
│   └── class-member.php
├── admin/
│   └── page.php
├── templates/
│   ├── frontend.php
│   └── member-widget.php
└── assets/
    ├── css/my-plugin.css
    └── js/my-plugin.js
```

Halten Sie die Bootstrap-Datei klein. Laden Sie Klassen aus dem Plugin-Ordner, verwenden Sie einen klaren Runtime-Einstieg und registrieren Sie Hooks dort. Kopieren Sie keine Core-Klassen und prüfen Sie vor einer Abhängigkeit, ob das andere Plugin aktiv ist.

#### Sichtbare Integration

Über `/admin/plugins` wird das Plugin aktiviert oder deaktiviert. Eine Plugin-Adminseite liegt nach der Registrierung eines Hauptmenüs und optionaler Untermenüs unter `/admin/plugins/<plugin>/<page>`. In der Admin-Sidebar erscheinen alle Plugin-Hauptmenüs alphabetisch sortiert im eigenen Abschnitt **„Plugin-Erweiterungen“** unterhalb sämtlicher 365CMS-Kernbereiche; eine Einsortierung zwischen Core-Menüpunkten ist nicht vorgesehen. Member-Funktionen werden über einen berechtigungsgeprüften Menüeintrag oder ein Dashboard-Widget angeboten und verlangen einen angemeldeten Member.

Verwenden Sie Templates für HTML, die zentrale Admin-Shell für Adminseiten und Plugin-Präfixe wie `.my-plugin-card` für CSS. Generische Klassen wie `.card` für Plugin-Styles, Inline-Styles, Inline-Scripts und ungeescapte Request-Werte sind unzulässig.

#### Sicherer Formularablauf

1. Ein persistentes, aktionsbezogenes CSRF-Token ausgeben.
2. Zustandsänderungen ausschließlich per POST akzeptieren.
3. Serverseitig Anmeldung, Capability, Token und normalisierte Eingaben prüfen.
4. Über Service oder Repository speichern und expliziten Erfolg oder Fehler liefern.
5. Nach erfolgreichem Admin-POST weiterleiten und eine Flash-Meldung ausgeben.

#### Datenbank und Migrationen

Plugin-Tabellen idempotent mit `InnoDB`, `utf8mb4` und dem Runtime-Datenbankpräfix anlegen. Für häufige Filter werden Indizes angelegt. Strukturwerte liegen als JSON in `TEXT` oder `LONGTEXT`; Kodierungsfehler werden explizit behandelt. Migrationen sind wiederholbar und verwenden niemals ein fest codiertes `cms_`-Präfix.

### Technische Referenz

#### Bootstrap und Lebenszyklus

Jede PHP-Datei beginnt mit `declare(strict_types=1)` und einem `ABSPATH`-Guard. Ein Plugin-Bootstrap:

1. definiert den CMS-Plugin-Header;
2. definiert Versions-, Pfad- und optionale URL-Konstanten;
3. lädt die Plugin-Klassen;
4. erzeugt Singleton oder gleichwertigen Bootstrap.

Die Runtime löst `cms_init`, Plugin-Hooks, `cms_admin_menu`, `plugin_loaded` und `plugins_loaded` über `CMS\Hooks` aus. Plugins registrieren eigene Hooks aus `cms_init` oder dem Konstruktor und vermeiden Logik beim Dateiladen, außer sicheren Definitionen und `require`s.

#### Core-Hooks für Plugins

| Hook | Typ | Verwendung |
|---|---|---|
| `cms_init` | Action | Runtime-Services und idempotente Migrationen initialisieren |
| `cms_admin_menu` | Action | Admin-Menüs und Untermenüs registrieren |
| `head` | Action | freigegebene Head-Ausgaben oder Assets ergänzen |
| `after_header` | Action | frühen Frontend-Inhalt ergänzen |
| `before_footer` | Action | Ausgaben im Footer-Bereich ergänzen (einmal pro Request, vor `footer.php`) |
| `body_end` | Action | Scripts oder Modals über die Asset-Regeln ergänzen (einmal pro Request, vor `</body>`) |
| `plugin_loaded` | Action | auf erfolgreich geladenes Plugin reagieren |
| `plugins_loaded` | Action | von allen aktiven Plugins abhängige Logik ausführen |
| `member_menu_items` | Filter | Member-Navigation erweitern |
| `member_dashboard_widgets` | Filter | Member-Dashboard-Widget registrieren |
| `cms_sitemap_entries` | Filter | öffentliche Plugin-URLs in `plugins.xml` des Sitemap-Bundles aufnehmen |
| `search_results` | Filter | Plugin-Treffer in der Seitensuche (`/search`) und in `SearchService::searchAll()` ergänzen |

Die kanonischen Hook-Namen und Signaturen stehen in der Core-Hook-Dokumentation. Es wird kein zweiter Hook-Bus eingeführt.

#### Sitemap, Suche und SEO-Metadaten für öffentliche Plugin-Seiten

- **Sitemap:** `cms_sitemap_entries` erhält ein Array und gibt es um Einträge `['url' => 'pfad/relativ/zu/SITE_URL', 'lastmod' => '2026-09-29 12:00:00', 'changefreq' => 'daily', 'priority' => 0.6]` ergänzt zurück. Absolute URLs sind nur für die eigene Domain erlaubt; fremde Hosts und Duplikate werden verworfen. Der Core schreibt die Einträge in `plugins.xml` (täglich über `cms_cron_daily` oder unter *SEO → Sitemap*). Nur indexierbare Seiten melden, keine Filter-, Such- oder Sortiervarianten.
- **Suche:** `search_results($results, $query, $limit, $context = [])` hängt Treffer mit mindestens `title` und `url` (eigene Domain oder Pfad relativ zu `SITE_URL`) an, optional `excerpt`, `_type` (Such-Scope, z. B. `messagecenter`), `_type_label` und `date` (Veröffentlichungs- oder Änderungsdatum für „Neueste zuerst“). Die Relevanz berechnet der Core aus Titel und Auszug und sortiert Plugin-Treffer gemeinsam mit Seiten und Beiträgen (`?sort=relevance` bzw. `?sort=date`); Plugins liefern deshalb nur Treffer, die alle Suchwörter enthalten. `$context` enthält `type`, `locale` und `source`; die Suchseite fragt Plugins nur, wenn kein Core-Scope (`pages`, `posts`, …) gewählt ist, und `?type=<_type>` zeigt nur die Treffer dieses Plugins. Den Suchbegriff in Wörter zerlegen und alle Wörter per Prepared Statement gegen die Plugin-Tabellen prüfen.
- **Meta-Tags:** Titel, Beschreibung, Canonical und Robots von Plugin-Seiten pro Request über `\CMS\Services\SEOService::getInstance()->setRequestMeta([...])` setzen (Schlüssel `title`, `description`, `canonical_url`, `robots_index`, `robots_follow`, `og_type`, `schema_type`). `SeoAnalysisService::resolveMetaTitle()` wendet das Titelformat der SEO-Einstellungen an.

#### Admin-Menü und Routing

Für ein Hauptmenü wird `add_menu_page()`, für Unterseiten `add_submenu_page()` verwendet. Beide Helfer speichern Capability, Titel, Slug, Callback und Menübeziehungen in der zentralen Registry. Der AdminRouter löst auf:

- `/admin/plugins/:plugin/:page` für registrierte Pluginseiten;
- Plugin-Callback oder Kompatibilitätsfallback;
- normale und AJAX-Ausgabewege;
- zentrale Shell, wenn der Callback kein vollständiges Layout liefert.

Admin-Callbacks rendern ausschließlich Inhalt. `renderAdminLayoutStart()` und `renderAdminLayoutEnd()` werden nur im Kompatibilitätspfad verwendet, weil der Router bei normalen Plugin-Callbacks bereits die aktuelle Shell bereitstellt.

#### Einheitliches Admin-Design

Der AdminRouter lädt auf jeder Plugin-Seite `assets/css/admin-plugins.css` nach den Plugin-Stylesheets. Die Datei gestaltet die gemeinsamen Bausteine innerhalb von `.cms-plugin-admin-content` im flachen Stil der 365CMS-Kernseiten: `.admin-page-header` (Titel, Beschreibung, `.header-actions`), `.dashboard-grid` mit `.stat-card` (`.stat-number`, `.stat-label`), `.admin-card` mit `*-panel-header`, Info-/Hinweis-/Schnellzugriffskarten (`*-info-card`, `*-note-card`, `*-action-card` mit `__eyebrow`, `__value`, `__title`, `__text`), linke Unternavigation, `.users-table` und Leerzustände. Plugins verwenden diese Klassen statt eigener Karten-, Hero- oder Emoji-Gestaltung; dekorative Icon-Kacheln werden ausgeblendet. Reine Icon-Buttons nutzen Tabler-Icons (`<i class="ti ti-pencil" aria-hidden="true"></i>`) plus `title`. Der Hauptmenütitel folgt dem Schema `<Familie> | <Name>`: `365CMS | …` für die öffentlichen Plugins (z. B. `365CMS | Kontakt`), `M365 | …` für die M365-Plugins von PhinIT und `PHINIT | …` für die weiteren PhinIT-Plugins. Der Core sortiert Plugin-Menüs im Abschnitt **„Plugin-Erweiterungen“** alphabetisch, dadurch stehen die Familien zusammen; Untermenüs und Seitentitel behalten den schlichten Funktionsnamen.

Bei Pfad-Routen (`/admin/plugins/:plugin/:page`) setzt der Router vor dem Callback `$_GET['page']` auf die angeforderte Seite, sodass Dispatcher und aktive Sidebar-Markierung sich darauf verlassen können. Den aktiven Seiten-Slug an `renderAdminLayoutStart()` übergeben.

#### Content Security Policy

Im Produktivbetrieb wird die CSP mit Request-Nonces und Trusted Types erzwungen. Inline-`<script>`-Blöcke ohne Nonce und Inline-Event-Handler (`onclick`, `onchange`, `onsubmit`) werden nicht ausgeführt. JavaScript gehört in Plugin-Dateien und wird über `data-*`-Attribute angebunden; für einfache Bestätigungen dient das Core-Attribut `data-cms-confirm="…"`. Unvermeidbare Inline-`<style>`/`<script>`-Blöcke erhalten `\CMS\Security::instance()->nonceAttr()`.

#### Lokalisierte öffentliche Routen

`includes/functions/plugin-public-i18n.php` stellt `cms_plugin_public_language()`, `cms_plugin_public_path_without_lang()`, `cms_plugin_public_localized_path()` und weitere Helfer für `/en/…`-Routen bereit. Plugins hängen nicht von einem gemeinsamen Ordner außerhalb ihres eigenen Verzeichnisses ab.

#### Beispiel: geschützte Settings-Seite

```php
public function renderSettingsPage(): void
{
    $security = \CMS\Security::instance();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$security->verifyPersistentToken(
            (string) ($_POST['csrf_token'] ?? ''),
            'my_plugin_settings'
        )) {
            $this->addError('Sicherheitsprüfung fehlgeschlagen.');
        } elseif (!\CMS\Auth::instance()->hasCapability('manage_settings')) {
            $this->addError('Keine Berechtigung.');
        } else {
            $value = sanitize_text_field((string) ($_POST['my_setting'] ?? ''));
            \CMS\Services\SettingsService::getInstance()->set('my_plugin', 'setting', $value);
            $this->redirectWithNotice('Gespeichert.');
        }
    }

    $csrfToken = $security->generateToken('my_plugin_settings');
    include MY_PLUGIN_PATH . 'admin/page.php';
}
```

`generateToken()` erzeugt das Token (1 h gültig, bis zu 20 je Aktion); `verifyToken()` entwertet es nach einmaliger Prüfung, `verifyPersistentToken()` prüft ohne Entwertung (für AJAX-Formulare mit mehreren Absendungen). Versteckte Felder allein schützen nicht; Autorisierung und CSRF werden beide serverseitig geprüft.

#### Member-Integration

Member-Navigation wird über `member_menu_items`, Widgets über `member_dashboard_widgets` registriert. `MemberController` erzwingt Authentifizierung, private Cache-Header, Plugin-Slug-Normalisierung und Template-Overrides. Eine Memberseite darf nicht unbeabsichtigt öffentlich erreichbar sein.

Für strukturierte Member-Tabs oder Einstellungen wird die Plugin-Dashboard-Registry verwendet. Benutzerdaten werden auf die aktuelle Member-ID begrenzt, Ausgaben escaped und privilegierte Aktionen mit einer pluginbezogenen Capability geschützt.

#### Persistenz und SQL

Verwenden Sie `\CMS\Database::instance()` und die Prepared-Statement-API:

```php
$db = \CMS\Database::instance();
$prefix = $db->getPrefix();
$statement = $db->prepare(
    "SELECT id, status FROM {$prefix}my_plugin_items WHERE user_id = ?"
);
$statement->execute([$userId]);
$rows = $statement->fetchAll() ?: [];
```

Nur vertrauenswürdige, intern erzeugte Bezeichner dürfen in interpoliertem SQL erscheinen. Werte werden immer gebunden. Verwenden Sie `getPrefix()` statt harter Tabellennamen, indizieren Sie `user_id` und Statusfelder und führen Sie Migrationen mit einer expliziten Schema-Version.

#### REST, AJAX und Routen

Ein Endpoint wird nur bei fachlichem Bedarf bereitgestellt. Verwenden Sie den Core-Router oder die etablierte CMS-Registrierung, beschränken Sie Methoden, verlangen Sie Authentifizierung für Memberdaten, Capabilities für Admindaten und CSRF für browserbasierte Zustandsänderungen. JSON-Fehler enthalten klare Statuscodes. Für private Plugin-Daten wird niemals ein uneingeschränkter öffentlicher Permission-Callback verwendet.

#### Assets und Templates

Verwenden Sie pluginbezogene CSS- und JavaScript-Dateien. Binden Sie sie über den aktuellen CMS-Asset-Loader ein, damit Cache-Versionen und CSP-Regeln erhalten bleiben. JavaScript bleibt extern und erhält Konfiguration über `data-*` statt Inline-Scripts. Templatepfade werden aus dem Plugin-Ordner aufgelöst; Theme-Overrides verwenden ausschließlich den dokumentierten Template-Loader-Vertrag.

#### Sicherheit und Fehlerbehandlung

- jede direkte PHP-Datei absichern;
- Text, E-Mail, URL und HTML passend zum Eingabetyp sanitizen;
- Text, Attribute, URLs und erlaubtes HTML bei der Ausgabe escapen;
- vor jedem Schreiben Capability und CSRF prüfen;
- öffentliche oder teure Endpoints rate-limiten;
- Secrets, Tokens, Zugangsdaten und unnötige personenbezogene Daten nicht loggen;
- explizite Fehler statt stiller Erfolgs-Fallbacks zurückgeben;
- optionale Integrationen bei deaktivierter Abhängigkeit geschlossen halten.

#### Prüf- und Release-Gate

Vor dem Release Syntaxprüfungen und vorhandene Projekt-Tests beziehungsweise Quality-Gates ausführen, Aktivierung/Deaktivierung und fehlende Dateien testen, Admin- und Member-Autorisierung prüfen, ungültige Eingaben und CSRF-Ablehnung testen, Migrationen auf leerer und bestehender Installation prüfen, Asset-Laden und CSP-Kompatibilität kontrollieren und Dokumentation, Changelog und Versionsmetadaten aktualisieren.


---

## Ergänzung (Stand 2026-10-02) / Addendum

> English: plugin header fields, lifecycle callbacks, the activation security scan, WordPress-compatible helpers, custom routes, subscription gating, GDPR and cron hooks – verified against `CMS/core/PluginManager.php` and `CMS/includes/functions/`.

### Plugin-Header

Gelesen aus der Bootstrap-Datei `CMS/plugins/<slug>/<slug>.php`:

```php
<?php
/**
 * Plugin Name: Mein Plugin
 * Description: Kurzbeschreibung für /admin/plugins
 * Version:     1.0.0
 * Author:      Firma
 * Requires CMS: 3.4.00
 * Requires Plugins: anderes-plugin, drittes-plugin
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
```

`Requires Plugins` (bzw. `Requires`) listet Abhängigkeiten; fehlt ein Plugin oder ist es inaktiv, wird die Aktivierung mit Meldung abgelehnt. `Requires CMS` (oder `requires_cms`/`min_cms_version` in `update.json`) verhindert die Aktivierung auf zu alten Core-Versionen.

### Lebenszyklus-Callbacks

Der `PluginManager` ruft nach dem Laden der Bootstrap-Datei optionale **Funktionen** auf, deren Name aus dem Slug (Bindestriche → Unterstriche) gebildet wird:

| Ereignis | Funktion | Hook danach |
|---|---|---|
| Aktivieren | `mein_plugin_activate()` | `plugin_activated` |
| Deaktivieren | `mein_plugin_deactivate()` | `plugin_deactivated` |
| Löschen | `mein_plugin_uninstall()` | `plugin_before_delete`, `plugin_deleted` |

Fehler in Callbacks werden protokolliert (Kanal `plugins`, Audit `plugin.lifecycle_error`) und brechen den Vorgang nicht ab. Tabellen in `_activate()` idempotent anlegen, in `_uninstall()` aufräumen.

### Sicherheits-Scan bei der Aktivierung

Vor dem Aktivieren durchsucht `PluginManager::securityScanPlugin()` die PHP-Dateien des Plugins nach `eval(`, `exec(`, `shell_exec(`, `system(`, `passthru(`, `popen(`, `proc_open(`, `pcntl_exec(` (ohne Methodenaufrufe `->`/`::`). Ein Treffer verhindert die Aktivierung.

### WordPress-kompatible Hilfsfunktionen

`CMS/includes/functions/wordpress-compat.php`, `escaping.php`, `admin-menu.php` u. a. stellen bereit:

- Hooks: `add_action`, `do_action`, `add_filter`, `apply_filters`, `remove_action`, `remove_filter`, `has_action`, `has_filter`
- Admin: `add_menu_page`, `add_submenu_page`, `admin_url`, `submit_button`, `check_admin_referer`, `wp_create_nonce`, `wp_verify_nonce`
- Ausgabe/Validierung: `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `esc_js`, `wp_kses_post`, `sanitize_text_field`, `sanitize_email`, `sanitize_key`, `sanitize_title`, `absint`, `wp_unslash`
- Optionen: `get_option`, `update_option`; Daten: `wp_parse_args`, `wp_json_encode`, `maybe_serialize`
- Antworten: `wp_send_json_success`, `wp_send_json_error`, `wp_redirect`, `wp_safe_redirect`, `wp_die`
- Assets: `plugins_url`; seit 3.5.0 laden `wp_register_style/script`, `wp_enqueue_style/script`, `wp_dequeue_*` und `wp_localize_script` tatsächlich: Styles und Header-Scripts erscheinen im Hook `head` bzw. `admin_head`, Footer-Scripts (`$in_footer = true`) und alles nach `head` Eingereihte in `body_end`. Abhängigkeiten werden aufgelöst, `$ver` hängt `?ver=` an, alle Tags tragen den CSP-Nonce, `javascript:`/`data:`-URLs werden verworfen, `wp_localize_script` akzeptiert nur gültige JS-Bezeichner. Externe Hosts müssen weiterhin in der CSP erlaubt sein
- Rechte: `current_user_can()`; Datenbank: globales `$wpdb` (`CMS_WPDB_Compat`) mit `prepare`, `get_row`, `get_results`, `get_var`, `insert`, `update`, `delete`, `query`, `esc_like`

Sie erleichtern die Portierung, ersetzen aber nicht die Core-APIs (`CMS\Hooks`, `CMS\Database`, `CMS\Security`).

### Eigene öffentliche Routen

```php
\CMS\Hooks::addAction('register_routes', static function (\CMS\Router $router): void {
    $router->addRoute('GET', '/veranstaltungen', [MeinPlugin\Frontend::class, 'list']);
    $router->addRoute('GET', '/veranstaltungen/:slug', [MeinPlugin\Frontend::class, 'show']);
});
```

Ausgabe über `ThemeManager::instance()->render('page', ['page' => [...]])` oder ein eigenes Template; öffentliche POST-Formulare brauchen das Token `form_guard` (globale Router-Prüfung). Für `/en/…` die Helfer aus `plugin-public-i18n.php` nutzen.

### Abo-Freigaben

```php
$subs = \CMS\SubscriptionManager::instance();
if (!$subs->canAccessPlugin($userId, 'events')) { /* Upgrade-Hinweis */ }
if (!$subs->checkLimit($userId, 'events'))       { /* Limit erreicht */ }
$subs->updateUsage($userId, 'events', $neueAnzahl);
```

Details: [../admin/subscription/SUBSCRIPTION-SYSTEM.md](../admin/subscription/SUBSCRIPTION-SYSTEM.md).

### DSGVO und Cron

- `dsgvo_export_data($userId, $email)` – eigene personenbezogene Daten zur Auskunft beitragen.
- `dsgvo_delete_data($userId, $email)` – vor der endgültigen Kontolöschung eigene Daten löschen.
- `cms_cron_hourly`, `cms_cron_daily`, `cms_cron_<name>` – geplante Aufgaben (`cron.php --task=<name>`).

### Mitglieder-Dashboard

Strukturierte Bereiche und Kacheln über `member_dashboard_init` und `PluginDashboardRegistry::register()` – vollständiges Beispiel in [../member/MEMBER-DASHBOARD.md](../member/MEMBER-DASHBOARD.md).

### Landingpage

Bausteine über den Filter `landing_page_plugins` – siehe [../admin/landing-page/LANDING-PAGE.md](../admin/landing-page/LANDING-PAGE.md).

### Weiterführend

[GUIDE.md](GUIDE.md) · [PLUGIN-MARKETPLACE.md](PLUGIN-MARKETPLACE.md) · [../core/HOOKS-REFERENCE.md](../core/HOOKS-REFERENCE.md) · [../admin/PANEL-INTEGRATION.md](../admin/PANEL-INTEGRATION.md)
