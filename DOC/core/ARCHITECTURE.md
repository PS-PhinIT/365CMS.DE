# 365CMS – Projektdokumentation | Abschnitt: Core – Architektur

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Schema:** v23 | **PHP:** ≥ 8.4

## English (summary)

365CMS is a framework-less PHP 8.4 application. Every web request enters through `CMS/index.php`, which loads `config/app.php`, configures the session cookie, includes `CMS/config.php` and the autoloader and starts `CMS\Bootstrap`. The bootstrap detects the runtime mode (`cli`, `api`, `admin`, `web`), validates bundled PHP requirements, connects to the database, runs pending migrations, sends security headers (CSP with nonce), starts the session, runs the application firewall, registers services in a small DI container, loads active plugins and – in web mode – the active theme, registers core cron hooks and finally fires `cms_init`. `Bootstrap::run()` lets plugins add routes (`register_routes`) and dispatches through `CMS\Router`, which loads exactly one route module group per request (API, admin, member or public+theme) and falls back to dynamic page/hub resolution and the theme's 404 page.

## Deutsch

### Schichten

```text
Browser / Cron / CLI
        │
        ▼
CMS/index.php ──► CMS/config.php ──► config/app.php (Konstanten)
        │                         └─► core/autoload.php + assets/autoload.php (Vendor)
        ▼
CMS\Bootstrap ─► Modus (cli | api | admin | web)
        │       ├─ Database (PDO) ─► MigrationManager::run()
        │       ├─ Security::init()  (Header, CSP-Nonce, Session)
        │       ├─ Auth             (Session-Benutzer, Rollen, MFA)
        │       ├─ SecurityRuntimeService::handleRequest()  (Firewall, Rate-Limit)
        │       ├─ Container        (Services als Singletons)
        │       ├─ PluginManager::loadPlugins()
        │       ├─ ThemeManager     (Theme-Runtime nur im Modus web)
        │       └─ Hooks: cms_init, cms_init_<modus>
        ▼
Bootstrap::run() ─► cms_before_route ─► register_routes ─► Router::dispatch() ─► cms_after_route
        │
        ▼
Route-Module: ApiRouter | AdminRouter | MemberRouter | PublicRouter + ThemeRouter
        │
        ▼
Admin-Module / Member-Controller / Theme-Templates ─► Services ─► Database
```

### Einstiegspunkte

| Datei | Zweck |
|---|---|
| `CMS/index.php` | Web-Einstieg für alle Routen (Rewrite auf `index.php`); HTTPS-Erkennung, Session-Cookie-Domain, Session-GC-Laufzeit aus `perf_session_timeout_*` |
| `CMS/config.php` | prüft PHP ≥ 8.4 (`CMS_MIN_PHP_VERSION`), lädt `config/app.php` oder leitet zum Installer |
| `CMS/config/app.php` | Konstanten: Datenbank, Schlüssel, `SITE_URL`, Pfade, Login-Limits, HTTPS/HSTS, LDAP, JWT, SMTP |
| `CMS/install.php` + `CMS/install/` | Installer ([../INSTALLATION.md](../INSTALLATION.md)) |
| `CMS/cron.php` | geplante Aufgaben (CLI oder Web-Cron mit Token) |
| `CMS/orders.php` | öffentlicher Checkout der Aboverwaltung |
| `CMS/update.php` | Datenbank-Updater: Web nur für Administratoren mit `manage_settings` + CSRF, CLI `php update.php [--status\|--dry-run]` |

### Laufzeitmodi (`Bootstrap::detectMode()`)

| Modus | Bedingung | Besonderheiten |
|---|---|---|
| `cli` | `PHP_SAPI === 'cli'` | keine Header/Session, kein Router; Plugins werden trotzdem geladen (Cron-Hooks) |
| `api` | Pfad `/api` oder `/api/…` | `Cache-Control: no-store`, nur `ApiRouter` |
| `admin` | Pfad `/admin` oder `/admin/…` | `no-store`, `X-Robots-Tag: noindex`, nur `AdminRouter`; Theme-Runtime nicht gebootet |
| `web` | alles andere | Theme wird geladen, Frontend-Assets (Cookie-Consent, Analytics, PhotoSwipe, Hub-Styles) über `head`/`body_end` |

Der Modus steht als `CMS_MODE` zur Verfügung; zusätzlich wird `cms_init_<modus>` ausgelöst.

### Bootstrap im Detail (`Bootstrap::initializeCore()`)

1. **Konstanten** sicherstellen (`ensureConstants()`), Plattformprüfung der gebündelten Bibliotheken (`validateBundledPhpPlatform()` liest Mindest-PHP-Versionen aus den Vendor-Manifests).
2. **Datenbank** (`Database::instance()`), danach `MigrationManager::run()` – läuft nur, bis `SCHEMA_VERSION = v23` erreicht ist (Flag-Datei `cache/db_schema_v23.flag`).
3. **Security** (`Security::init()`): CSP-Nonce, Sicherheitsheader, Session-Start.
4. **Auth** (`Auth::instance()`): Session-Benutzer laden, Rollen-Lebensdauer prüfen.
5. **Firewall** (`SecurityRuntimeService::handleRequest()`), nicht im CLI.
6. **Container**: Logger, Cache und Services (siehe [SERVICES.md](SERVICES.md)) als Singletons, jeweils unter Klassenname und Kurzname (`'mail'`, `'seo'`, `'search'` …).
7. **Router** (außer CLI), **PluginManager::loadPlugins()** (`plugin_loaded`, `plugins_loaded`), **ThemeManager** (Theme-Runtime nur `web`).
8. **Frontend-Hooks** (nur `web`): Cookie-Consent, Analytics (consent-gesteuert, CSP-Quellen über `cms_csp_prepare`), Core-Web-Vitals-Skript, PhotoSwipe für Galerien/Hub-Seiten, Hub-Styles.
9. **Cron-Hooks**: `cms_cron_mail_queue`, `cms_cron_hourly` (Broken-Links, SEO-Trend, Monitoring-Trend, Sicherheitsalarme), `cms_cron_daily` (Sitemap).
10. **OPcache-Warmup** nach Deployments als Shutdown-Funktion.
11. `cms_init`, `cms_init_<modus>`.

### Routing (`CMS\Router`)

**Registrierung:** Pro Request wird nur die passende Modulgruppe geladen:

| Pfadpräfix | Module |
|---|---|
| `/api` | `Routing\ApiRouter` |
| `/admin` | `Routing\AdminRouter` |
| `/member`, `/dashboard` | `Routing\MemberRouter` |
| sonst | `Routing\PublicRouter` + `Routing\ThemeRouter` |

Plugins ergänzen Routen im Hook `register_routes` (`$router->addRoute('GET', '/mein-pfad', $callback)`; Platzhalter `:name`).

**Dispatch-Ablauf (`Router::dispatch()`):**

1. Sprachkontext auflösen (`ContentLocalizationService::resolveRequestContext()`): `/en/…` → `locale = en`, `base_uri` ohne Präfix.
2. Cache-Header setzen (öffentlich/privat, siehe [../admin/performance/PERFORMANCE.md](../admin/performance/PERFORMANCE.md)).
3. Hub-Alias-Domains umleiten.
4. Weiterleitungsregeln prüfen (`RedirectService::findRedirect()`).
5. **Globaler CSRF-Schutz** für `POST/PUT/PATCH/DELETE` außerhalb von `/api`, `/admin`, `/member`: Token `csrf_token` der Aktion `form_guard` erforderlich (Ausnahmen: Login/Registrierung/Passwort, Logout, Kontakt, Kommentare, MFA, Theme-Favoriten mit eigenem Token). Fehlender Token → 404, ungültiger → 403.
6. Exakter Routentreffer → Muster-Treffer (`/pfad/:param`).
7. Landing-Alias, dann **dynamische Auflösung**: zuerst Hub-Site per Slug, dann Seite per Slug (DE/EN) – beide werden über das Theme-Template `page` gerendert. Beiträge laufen über die vom `ThemeRouter` registrierte Permalink-Route (`PermalinkService`).
8. Sonst `render404()` (404-Protokoll, Theme-Template `404.php`).

`HEAD` wird wie `GET` behandelt. `Router::redirect()` erlaubt nur interne bzw. zulässige Ziele.

### Inhalte rendern

`Router::prepareRenderableContent()` wandelt gespeicherte Inhalte in HTML: Editor.js-JSON → `EditorJsRenderer`, Shortcodes (`[site-table]`, `[hub-site]`), Inhaltsverzeichnis (`TableOfContents`), Bild-Lazy-Loading. Themes erhalten fertiges HTML und Metadaten.

### Mehrsprachigkeit

Zwei Inhaltssprachen (DE als Standard, EN unter `/en/…`): Inhalte führen `*_en`-Spalten, Slugs je Sprache, Archiv-Basen je Sprache. `ContentLocalizationService::buildLocalizedPath()` erzeugt Links, Hreflang-Gruppen verbinden Übersetzungen ([../admin/seo/SEO.md](../admin/seo/SEO.md)). Die Admin-Oberfläche ist deutsch; Übersetzungsdateien liegen unter `CMS/lang/`.

### Erweiterbarkeit

- **Hooks** (Actions/Filter): [HOOKS-REFERENCE.md](HOOKS-REFERENCE.md)
- **Plugins**: `CMS/plugins/<slug>/<slug>.php` ([../plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md))
- **Themes**: `CMS/themes/<slug>/` ([../theme/THEME-DEVELOPMENT.md](../theme/THEME-DEVELOPMENT.md))
- **WordPress-Kompatibilität**: Hilfsfunktionen in `CMS/includes/functions/` (`add_menu_page`, `current_user_can`, `get_option`, `WP_Error` …) erleichtern die Portierung.

### Fehlertoleranz

Fehler in optionalen Teilen (Plugin-Callback, Admin-View, Firewall, Hub-Auflösung) werden abgefangen und protokolliert; die Seite bleibt erreichbar. Mit `CMS_DEBUG = true` liefert `CMS\Debug` Checkpoints (`bootstrap.*`, `router.*`) und Details.

### Verwandte Dokumente

[CORE-CLASSES.md](CORE-CLASSES.md) · [SERVICES.md](SERVICES.md) · [SECURITY.md](SECURITY.md) · [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) · [STRUCTURE.md](STRUCTURE.md)
