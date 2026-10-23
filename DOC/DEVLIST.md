# 365CMS – Projektdokumentation | Abschnitt: DEVLIST
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02

## Table of contents | Inhaltsverzeichnis

- [0. Platform](#0-platform--abhängigkeiten-plattformen-und-betriebsgrundlagen)
- [1. System picture](#1-system-picture--systembild)
- [2. Repository and runtime](#2-repository-and-runtime--repository--und-laufzeitmodell)
- [3. Bootstrap](#3-bootstrap--betriebsmodi-und-startpfad)
- [4. Configuration](#4-configuration--konfiguration)
- [5. Container](#5-container--dependency-injection)
- [6. Security](#6-security--sicherheitsarchitektur)
- [7. Authentication](#7-authentication--mfa-passkeys-ldap-jwt)
- [8. Database](#8-database--schema-und-migrationen)
- [9. Content and i18n](#9-content-and-i18n--content-modell-und-mehrsprachigkeit)
- [10. Routing](#10-routing)
- [11. Services](#11-services)
- [12. SEO](#12-seo)
- [13. Plugins](#13-plugins)
- [14. Themes](#14-themes)
- [15. Admin](#15-admin)
- [16. Member](#16-member)
- [17. Hooks](#17-hooks)
- [18. Performance](#18-performance)
- [19. Cron](#19-cron)
- [20. Media](#20-media)
- [21. Logging](#21-logging)
- [22. Best practices](#22-best-practices)
- [23–27. Do not regress](#23-27-do-not-regress--nicht-wieder-tun)
- [28. Pitfalls](#28-pitfalls--stolperfallen)
- [29. Checklist](#29-checklist--checkliste-vor-änderungen)
- [30. Closing](#30-closing--abschlussbild)

Audience: developers, integrators, auditors, operators. Companion docs: [INDEX.md](INDEX.md), [FILELIST.md](FILELIST.md), [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md).

---

## 0. Platform | Abhängigkeiten, Plattformen und Betriebsgrundlagen

**English**

- **PHP 8.4.1+** is enforced (`CMS_MIN_PHP_VERSION` in `CMS/config.php`).
- Database: **MySQL or MariaDB** via PDO (`pdo_mysql`). No version gate in the installer.
- Typical web server: Apache 2.4 with rewrite, or Nginx `try_files` to `index.php`.
- Required / widely used extensions: `pdo_mysql`, `mbstring`, `json`, `openssl`.
- Feature-path extensions: `curl`, `zip`, `fileinfo`, `gd` (or equivalent image support), `intl`.
- Bundled runtime libraries under `CMS/assets/` include Editor.js, SunEditor, Grid.js, PhotoSwipe, HTMLPurifier, JWT, WebAuthn, mailer, mime, translation, Tabler, TNTSearch, TwoFactorAuth, Carbon, cron, ldaprecord, melbahja-seo.
- Extra vendor tree: `CMS/vendor/dompdf/`. **TCPDF is not in this repository.**
- Optional integrations: SMTP / OAuth2 mail, Microsoft Graph mail tokens, LDAP, Passkeys / WebAuthn, cron.

**Deutsch**

- **PHP 8.4.1+** wird erzwungen (`CMS_MIN_PHP_VERSION` in `CMS/config.php`).
- Datenbank: **MySQL oder MariaDB** über PDO (`pdo_mysql`). Kein Versionsgate im Installer.
- Typischer Webserver: Apache 2.4 mit Rewrite oder Nginx `try_files` auf `index.php`.
- Nötige / häufig genutzte Erweiterungen: `pdo_mysql`, `mbstring`, `json`, `openssl`.
- Featurepfade: `curl`, `zip`, `fileinfo`, `gd` (oder vergleichbare Bildverarbeitung), `intl`.
- Gebündelte Runtime-Bibliotheken unter `CMS/assets/` umfassen Editor.js, SunEditor, Grid.js, PhotoSwipe, HTMLPurifier, JWT, WebAuthn, Mailer, Mime, Translation, Tabler, TNTSearch, TwoFactorAuth, Carbon, Cron, LdapRecord, melbahja-seo.
- Zusätzlicher Vendor-Baum: `CMS/vendor/dompdf/`. **TCPDF liegt in diesem Repository nicht vor.**
- Optionale Integrationen: SMTP / OAuth2-Mail, Microsoft-Graph-Mail-Tokens, LDAP, Passkeys / WebAuthn, Cron.

---

## 1. System picture | Systembild

**English**

365CMS is a modular PHP CMS with a hard split between core, admin, member area, theme runtime, plugin runtime, services, routing, security, SEO, and operations. The productive runtime boots from `CMS/`.

Treat it as a small application platform, not as “a few PHP files plus a template”.

Every change should answer: Where is the canonical runtime? Which entry or service is the source of truth? Which assets, hooks, or wrappers are attached? Which security or audit boundary is touched? Does the change affect live operation or docs?

**Deutsch**

365CMS ist ein modulbasiertes PHP-CMS mit klarer Trennung zwischen Core, Admin, Member-Bereich, Theme-Laufzeit, Plugin-Laufzeit, Services, Routing, Sicherheit, SEO und Betrieb. Die produktive Laufzeit startet aus `CMS/`.

Es ist eine kleine Anwendungsplattform, kein „ein paar PHP-Dateien plus Template“.

Jede Änderung sollte klären: Wo liegt die kanonische Runtime? Welcher Entry oder Service ist die Wahrheit? Welche Assets, Hooks oder Wrapper hängen dran? Welche Sicherheits- oder Audit-Grenze wird berührt? Betrifft die Änderung Live-Betrieb oder Doku?

---

## 2. Repository and runtime | Repository- und Laufzeitmodell

**English**

Canonical runtime: `365CMS.DE-MAIN/CMS/`.

- Theme source repo `365CMS.DE-THEME` is **not** auto-loaded.
- Plugin source repo `365CMS.DE-PLUGINS` is **not** auto-loaded.
- Live theme path: `CMS/themes/<slug>/`
- Live plugin path: `CMS/plugins/<slug>/`

Layers: configuration → bootstrap/core → services → admin modules → themes → plugins → member & public UI.

This project’s working model is that the repository should match the deployed runtime. A file that exists only in a sibling source repo is not live.

**Deutsch**

Kanonische Runtime: `365CMS.DE-MAIN/CMS/`.

- Theme-Quellrepo `365CMS.DE-THEME` wird **nicht** automatisch geladen.
- Plugin-Quellrepo `365CMS.DE-PLUGINS` wird **nicht** automatisch geladen.
- Live-Theme-Pfad: `CMS/themes/<slug>/`
- Live-Plugin-Pfad: `CMS/plugins/<slug>/`

Schichten: Konfiguration → Bootstrap/Core → Services → Admin-Module → Themes → Plugins → Member- und Public-UI.

Das Arbeitsmodell dieses Projekts ist, dass das Repository der deployten Runtime entsprechen soll. Eine Datei nur im Nachbar-Quellrepo ist nicht live.

---

## 3. Bootstrap | Betriebsmodi und Startpfad

**English**

Entry: `CMS/index.php` → `CMS/config.php` → `CMS/config/app.php` → `CMS\Bootstrap`.

`Bootstrap::detectMode()`:

| Mode | Trigger |
|---|---|
| `cli` | `PHP_SAPI === 'cli'` |
| `api` | URI path starts with `/api/` |
| `admin` | path is `/admin` or starts with `/admin/` |
| `web` | default |

ThemeManager is not loaded in API/CLI mode. `Database` constructs `SchemaManager` and calls `createTables()` (failures are logged, not thrown).

**Deutsch**

Einstieg: `CMS/index.php` → `CMS/config.php` → `CMS/config/app.php` → `CMS\Bootstrap`.

`Bootstrap::detectMode()`:

| Modus | Auslöser |
|---|---|
| `cli` | `PHP_SAPI === 'cli'` |
| `api` | URI-Pfad beginnt mit `/api/` |
| `admin` | Pfad ist `/admin` oder beginnt mit `/admin/` |
| `web` | Standard |

ThemeManager wird im API-/CLI-Modus nicht geladen. `Database` erzeugt `SchemaManager` und ruft `createTables()` auf (Fehler werden geloggt, nicht geworfen).

---

## 4. Configuration | Konfiguration

**English**

`CMS/config.php` is the stub (PHP gate + load). `CMS/config/app.php` is the template/real config.

Config layers that must not be mixed blindly:

1. static constants in `app.php`
2. persisted settings in `cms_settings`
3. request/session/mode
4. theme.json / plugin metadata / module settings

Security and path parameters do not belong in theme UI settings. `SITE_URL` must not use a trailing slash or a subdirectory.

**Deutsch**

`CMS/config.php` ist der Stub (PHP-Gate + Laden). `CMS/config/app.php` ist Template/echte Konfiguration.

Konfigurationsschichten, die nicht blind vermischt werden dürfen:

1. statische Konstanten in `app.php`
2. persistierte Settings in `cms_settings`
3. Request/Session/Modus
4. theme.json / Plugin-Metadaten / Modulsettings

Sicherheits- und Pfadparameter gehören nicht in Theme-UI-Settings. `SITE_URL` darf keinen Trailing Slash und kein Unterverzeichnis haben.

Constants are listed in [INSTALLATION.md](INSTALLATION.md).

---

## 5. Container | Dependency Injection

**English**

`CMS/core/Container.php` is the service container (`bindInstance()`, `singleton()`, aliases such as `db`, `logger`, `cache`, `mail`, `search`, `seo`).

High-relevance core objects: `Database`, `Security`, `Auth`, `Logger`, `AuditLogger`, `Hooks`, `Router`, `PluginManager`, `ThemeManager`, `CacheManager`, `MigrationManager`, `SchemaManager`, `Version`.

Many services are lazy (first access): Purifier, Mail, MailQueue, Search, Image, SEO, ThemeCustomizer, Pdf.

Do not hide remote I/O in constructors. Do not turn views into a service locator.

**Deutsch**

`CMS/core/Container.php` ist der Service-Container (`bindInstance()`, `singleton()`, Aliase wie `db`, `logger`, `cache`, `mail`, `search`, `seo`).

Kernobjekte mit hoher Relevanz: `Database`, `Security`, `Auth`, `Logger`, `AuditLogger`, `Hooks`, `Router`, `PluginManager`, `ThemeManager`, `CacheManager`, `MigrationManager`, `SchemaManager`, `Version`.

Viele Services sind lazy (erster Zugriff): Purifier, Mail, MailQueue, Search, Image, SEO, ThemeCustomizer, Pdf.

Kein Remote-I/O im Konstruktor verstecken. Views nicht zum Service-Locator machen.

---

## 6. Security | Sicherheitsarchitektur

**English**

Defense in depth: CSRF, XSS, SQL injection, session fixation, unsafe theme/plugin bootstrap, login rate limits, security headers, audit logging.

CSRF lives in `CMS/core/Security.php`: per-action tokens, default one-hour validity, one-shot invalidation after success. Nested admin shells that already verified a token must not blindly verify the same token again.

Sessions: `HttpOnly`, `Secure` on HTTPS, `use_strict_mode`, ID regeneration on init.

Passwords: `PASSWORD_BCRYPT`, cost `12`.

Rate limit: session fallback plus DB `login_attempts` (IP + action + window). Defaults `MAX_LOGIN_ATTEMPTS = 5`, `LOGIN_TIMEOUT = 300`.

Headers include `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `COOP`, `CORP`, HSTS when HTTPS and not debug, CSP (nonce-based; report-only in debug).

SQL: PDO prepared statements only. Table names only from trusted internal context.

Themes: `realpath()` path checks, `functions.php` scan, rollback to `DEFAULT_THEME`.

Plugins: activation checks bootstrap file, dependencies, and dangerous patterns (`eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, …).

3.5.0 AI path: admin-only, no public AI routes, no automatic publish, provider policy, HTTPS / Ollama egress rules, atomic quotas (`ai_quota_usage`), CSP-compliant admin JS.

**Deutsch**

Defense in Depth: CSRF, XSS, SQL-Injection, Session-Fixation, unsichere Theme-/Plugin-Bootstraps, Login-Rate-Limits, Security-Header, Audit-Logging.

CSRF sitzt in `CMS/core/Security.php`: Token pro Aktion, Standardgültigkeit eine Stunde, One-Shot nach Erfolg. Eingebettete Admin-Shells, die bereits geprüft haben, dürfen denselben Token nicht blind erneut prüfen.

Sessions: `HttpOnly`, `Secure` bei HTTPS, `use_strict_mode`, ID-Regeneration beim Init.

Passwörter: `PASSWORD_BCRYPT`, Cost `12`.

Rate-Limit: Session-Fallback plus DB `login_attempts` (IP + Aktion + Fenster). Standard `MAX_LOGIN_ATTEMPTS = 5`, `LOGIN_TIMEOUT = 300`.

Header u. a. `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `COOP`, `CORP`, HSTS bei HTTPS und Nicht-Debug, CSP (nonce-basiert; im Debug Report-Only).

SQL: nur PDO Prepared Statements. Tabellennamen nur aus vertrauenswürdigem internem Kontext.

Themes: `realpath()`-Pfadprüfung, Scan von `functions.php`, Rollback auf `DEFAULT_THEME`.

Plugins: Aktivierung prüft Bootstrap-Datei, Abhängigkeiten und gefährliche Muster (`eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, …).

AI-Pfad 3.5.0: nur Admin, keine Public-AI-Routen, keine automatische Veröffentlichung, Provider-Policy, HTTPS-/Ollama-Egress, atomare Quotas (`ai_quota_usage`), CSP-konformes Admin-JS.

---

## 7. Authentication | MFA, Passkeys, LDAP, JWT

**English**

Core files: `CMS/core/Auth.php`, `CMS/core/Auth/AuthManager.php`, `CMS/core/Totp.php`.

- TOTP: `core/Auth/MFA/TotpAdapter.php`
- Backup codes: `core/Auth/MFA/BackupCodesManager.php`
- Passkeys / WebAuthn: `core/Auth/Passkey/WebAuthnAdapter.php` plus `CMS/assets/webauthn/`
- LDAP: `core/Auth/LDAP/LdapAuthProvider.php` plus `LDAP_*` constants
- JWT API auth: `JwtService`, `JWT_SECRET` (empty → `AUTH_KEY`), `JWT_TTL` default 3600, `JWT_ISSUER` default `SITE_URL`

Login branding / public auth pages: admin route `/admin/cms-loginpage` (`cms-loginpage.php`) and views under `CMS/views/auth/`. See [admin/themes-design/CMS-LOGINPAGE.md](admin/themes-design/CMS-LOGINPAGE.md).

**Deutsch**

Kern-Dateien: `CMS/core/Auth.php`, `CMS/core/Auth/AuthManager.php`, `CMS/core/Totp.php`.

- TOTP: `core/Auth/MFA/TotpAdapter.php`
- Backup-Codes: `core/Auth/MFA/BackupCodesManager.php`
- Passkeys / WebAuthn: `core/Auth/Passkey/WebAuthnAdapter.php` plus `CMS/assets/webauthn/`
- LDAP: `core/Auth/LDAP/LdapAuthProvider.php` plus `LDAP_*`-Konstanten
- JWT-API-Auth: `JwtService`, `JWT_SECRET` (leer → `AUTH_KEY`), `JWT_TTL` Standard 3600, `JWT_ISSUER` Standard `SITE_URL`

Login-Branding / öffentliche Auth-Seiten: Admin-Route `/admin/cms-loginpage` und Views unter `CMS/views/auth/`. Siehe [admin/themes-design/CMS-LOGINPAGE.md](admin/themes-design/CMS-LOGINPAGE.md).

---

## 8. Database | Schema und Migrationen

**English**

- Wrapper: `CMS/core/Database.php` (PDO, singleton, `get_instance()` alias for plugins)
- Prefix: `DB_PREFIX` (default `cms_`)
- Charset: `DB_CHARSET` (default `utf8mb4`)
- Schema create: `SchemaManager` (`SCHEMA_VERSION = 'v23'`)
- Incremental: `MigrationManager`, `DatabaseUpdateRunner`

Installer and admin updater show installed vs. target core and schema versions. Schema markers must not be rolled back through the admin updater. Table `ai_quota_usage` belongs to `v22`.

Do not invent generic foreign-key names that collide schema-wide.

Authoritative table docs: [core/DATABASE-SCHEMA.md](core/DATABASE-SCHEMA.md).

**Deutsch**

- Wrapper: `CMS/core/Database.php` (PDO, Singleton, Alias `get_instance()` für Plugins)
- Präfix: `DB_PREFIX` (Standard `cms_`)
- Zeichensatz: `DB_CHARSET` (Standard `utf8mb4`)
- Schema-Anlage: `SchemaManager` (`SCHEMA_VERSION = 'v23'`)
- Inkrementell: `MigrationManager`, `DatabaseUpdateRunner`

Installer und Admin-Updater zeigen installierte und Ziel-Versionen. Schema-Marker dürfen über den Admin-Updater nicht zurückgesetzt werden. Tabelle `ai_quota_usage` gehört zu `v22`.

Keine generischen Foreign-Key-Namen, die schemaweit kollidieren.

Maßgeblich: [core/DATABASE-SCHEMA.md](core/DATABASE-SCHEMA.md).

---

## 9. Content and i18n | Content-Modell und Mehrsprachigkeit

**English**

Pages and posts use EditorJS JSON, sanitized server-side and rendered by `EditorJsRenderer` / related services — not raw HTML.

Language files: `CMS/lang/de.yaml`, `CMS/lang/en.yaml`. `TranslationService` provides fallbacks when Symfony Translation returns unknown keys unchanged.

DE/EN content paths often need to be updated together. Search indexing must listen to the correct hooks or multilingual fields stay stale.

**Deutsch**

Seiten und Beiträge nutzen EditorJS-JSON, serverseitig sanitisiert und gerendert durch `EditorJsRenderer` / verwandte Services — nicht als rohes HTML.

Sprachdateien: `CMS/lang/de.yaml`, `CMS/lang/en.yaml`. `TranslationService` liefert Fallbacks, wenn Symfony Translation unbekannte Keys unverändert zurückgibt.

DE-/EN-Content-Pfade oft gemeinsam aktualisieren. Die Suchindexierung muss an den richtigen Hooks hängen, sonst bleiben mehrsprachige Felder veraltet.

---

## 10. Routing

**English**

Central: `CMS/core/Router.php`.

Specialized routers in `CMS/core/Routing/`:

- `AdminRouter.php`
- `ApiRouter.php`
- `MemberRouter.php`
- `PublicRouter.php` (includes core auth pages)
- `ThemeRouter.php`
- `ThemeArchiveRepository.php`

Pretty URLs depend on Apache rewrite or Nginx `try_files` plus correct `SITE_URL`.

**Deutsch**

Zentrale: `CMS/core/Router.php`.

Spezialisierte Router in `CMS/core/Routing/`:

- `AdminRouter.php`
- `ApiRouter.php`
- `MemberRouter.php`
- `PublicRouter.php` (inkl. Core-Auth-Seiten)
- `ThemeRouter.php`
- `ThemeArchiveRepository.php`

Pretty URLs brauchen Apache-Rewrite oder Nginx-`try_files` plus korrekte `SITE_URL`.

---

## 11. Services

**English**

Domain logic lives in `CMS/core/Services/`. Inventory: [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md). Overview: [core/SERVICES.md](core/SERVICES.md).

Keep constructors free of hidden side effects. One service, one job. Views should not reimplement service contracts.

AI services in 3.5.0 are admin-only: policy, quotas, retry/fallback, health checks, no public routes.

**Deutsch**

Fachlogik liegt in `CMS/core/Services/`. Inventar: [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md). Überblick: [core/SERVICES.md](core/SERVICES.md).

Konstruktoren ohne versteckte Seiteneffekte. Ein Service, eine Aufgabe. Views sollen Service-Verträge nicht neu implementieren.

AI-Services in 3.5.0 sind admin-only: Policy, Quotas, Retry/Fallback, Healthchecks, keine Public-Routen.

---

## 12. SEO

**English**

Services: `SEOService`, `SeoAnalysisService`, `SeoBrokenLinkService`, `SeoTrendService`, `RedirectService`, `IndexingService`, `SitemapService`, plus `Services/SEO/*`.

Admin UI: `CMS/admin/seo*.php`, `CMS/admin/modules/seo/`, `CMS/assets/js/admin-seo-*.js`.

Docs: [admin/seo/SEO.md](admin/seo/SEO.md), [admin/seo/REDIRECTS.md](admin/seo/REDIRECTS.md).

Redirect and 404 dialogs share JavaScript — fix the shared script, not only one view.

**Deutsch**

Services: `SEOService`, `SeoAnalysisService`, `SeoBrokenLinkService`, `SeoTrendService`, `RedirectService`, `IndexingService`, `SitemapService`, plus `Services/SEO/*`.

Admin-UI: `CMS/admin/seo*.php`, `CMS/admin/modules/seo/`, `CMS/assets/js/admin-seo-*.js`.

Doku: [admin/seo/SEO.md](admin/seo/SEO.md), [admin/seo/REDIRECTS.md](admin/seo/REDIRECTS.md).

Redirect- und 404-Dialoge teilen sich JavaScript — den gemeinsamen Scriptpfad fixen, nicht nur eine View.

---

## 13. Plugins

**English**

`PluginManager` loads `CMS/plugins/<slug>/`. Current runtime plugin: `cms-importer`.

Development guide: [plugins/PLUGIN-DEVELOPMENT.md](plugins/PLUGIN-DEVELOPMENT.md). Marketplace: [plugins/PLUGIN-MARKETPLACE.md](plugins/PLUGIN-MARKETPLACE.md).

Do not unzip packages directly into production without a staging/check path. Class files must guard against double includes (`Cannot redeclare`).

**Deutsch**

`PluginManager` lädt `CMS/plugins/<slug>/`. Aktuelles Runtime-Plugin: `cms-importer`.

Entwicklungsleitfaden: [plugins/PLUGIN-DEVELOPMENT.md](plugins/PLUGIN-DEVELOPMENT.md). Marktplatz: [plugins/PLUGIN-MARKETPLACE.md](plugins/PLUGIN-MARKETPLACE.md).

Pakete nicht ungeprüft direkt nach Produktion entpacken. Klassendateien gegen Doppel-Includes absichern (`Cannot redeclare`).

---

## 14. Themes

**English**

`ThemeManager` uses `CMS/themes/<slug>/` and `DEFAULT_THEME` (`cms-default`). Current runtime theme: `cms-default`.

Path validation via `realpath()`, syntax/security checks, rollback on failure.

Guide: [theme/THEME-DEVELOPMENT.md](theme/THEME-DEVELOPMENT.md).

**Deutsch**

`ThemeManager` nutzt `CMS/themes/<slug>/` und `DEFAULT_THEME` (`cms-default`). Aktuelles Runtime-Theme: `cms-default`.

Pfadvalidierung per `realpath()`, Syntax-/Sicherheitsprüfung, Rollback bei Fehlern.

Leitfaden: [theme/THEME-DEVELOPMENT.md](theme/THEME-DEVELOPMENT.md).

---

## 15. Admin

**English**

HTTP entries in `CMS/admin/*.php`, logic in `modules/`, templates in `views/`, chrome in `partials/`.

Do not nest forms in admin views. Do not put inline event handlers as the primary interaction contract. Keep UI limits aligned with server-side limits. Preserve tab/filter/edit context after errors.

Plugin admin pages without a full layout should receive the shared `page-body` / `container-xl` wrapper (existing panel-integration contract). Numeric menu positions must not collide across plugins.

**Deutsch**

HTTP-Einstiege in `CMS/admin/*.php`, Logik in `modules/`, Templates in `views/`, Rahmen in `partials/`.

Keine verschachtelten Formulare in Admin-Views. Keine Inline-Event-Handler als primären Interaktionsvertrag. UI-Limits und Server-Limits angleichen. Tab-/Filter-/Edit-Kontext nach Fehlern erhalten.

Plugin-Admin-Seiten ohne vollständiges Layout sollen den gemeinsamen `page-body`-/`container-xl`-Wrapper erhalten. Numerische Menüpositionen dürfen sich zwischen Plugins nicht überschreiben.

---

## 16. Member

**English**

Path `/member`. Files under `CMS/member/` (17 files). Controller `member/includes/class-member-controller.php`. Router `MemberRouter`. Service `MemberService`.

Docs: [member/README.md](member/README.md), [member/MEMBER-DASHBOARD.md](member/MEMBER-DASHBOARD.md), [member/MEMBER-ROUTES.md](member/MEMBER-ROUTES.md), [member/MEMBER-SECURITY.md](member/MEMBER-SECURITY.md).

**Deutsch**

Pfad `/member`. Dateien unter `CMS/member/` (17 Dateien). Controller `member/includes/class-member-controller.php`. Router `MemberRouter`. Service `MemberService`.

Doku: [member/README.md](member/README.md), [member/MEMBER-DASHBOARD.md](member/MEMBER-DASHBOARD.md), [member/MEMBER-ROUTES.md](member/MEMBER-ROUTES.md), [member/MEMBER-SECURITY.md](member/MEMBER-SECURITY.md).

---

## 17. Hooks

**English**

`CMS/core/Hooks.php` is the action/filter system. Reference: [core/HOOKS-REFERENCE.md](core/HOOKS-REFERENCE.md).

Search, cache, plugins, and multilingual saves depend on the correct hook. Do not invent a second event name for an existing contract.

**Deutsch**

`CMS/core/Hooks.php` ist das Action-/Filter-System. Referenz: [core/HOOKS-REFERENCE.md](core/HOOKS-REFERENCE.md).

Suche, Cache, Plugins und mehrsprachige Saves hängen am richtigen Hook. Keinen zweiten Event-Namen für einen bestehenden Vertrag erfinden.

---

## 18. Performance

**English**

`CacheManager`, `AssetOptimizerService` (public files under `cache/optimized-assets/`), `OpcacheWarmupService`, `CoreWebVitalsService`, `PerformanceSafetyNetService`. Admin: `CMS/admin/performance*.php`.

**Deutsch**

`CacheManager`, `AssetOptimizerService` (öffentliche Dateien unter `cache/optimized-assets/`), `OpcacheWarmupService`, `CoreWebVitalsService`, `PerformanceSafetyNetService`. Admin: `CMS/admin/performance*.php`.

---

## 19. Cron

**English**

Entry: `CMS/cron.php`. Services: `CronRunnerService`, `CronExpressionAdapter`. Admin JS: `assets/js/admin-system-cron.js`. Cron (or an equivalent runner) is required for queues, monitoring, and scheduled tasks.

**Deutsch**

Einstieg: `CMS/cron.php`. Services: `CronRunnerService`, `CronExpressionAdapter`. Admin-JS: `assets/js/admin-system-cron.js`. Cron (oder ein gleichwertiger Runner) ist für Queues, Monitoring und Zeitaufgaben nötig.

---

## 20. Media

**English**

`MediaService`, `MediaDeliveryService`, `FileUploadService`, `ImageService`, `ElfinderService`, `ContentMediaPlacementService`. Admin: `CMS/admin/media.php`. Member: `CMS/member/media.php`.

Uploads land in `CMS/uploads/`. Executable uploads must stay blocked. Docs: [admin/media/README.md](admin/media/README.md), [workflow/MEDIA-UPLOAD-WORKFLOW.md](workflow/MEDIA-UPLOAD-WORKFLOW.md).

**Deutsch**

`MediaService`, `MediaDeliveryService`, `FileUploadService`, `ImageService`, `ElfinderService`, `ContentMediaPlacementService`. Admin: `CMS/admin/media.php`. Member: `CMS/member/media.php`.

Uploads landen in `CMS/uploads/`. Ausführbare Uploads müssen blockiert bleiben. Doku: [admin/media/README.md](admin/media/README.md), [workflow/MEDIA-UPLOAD-WORKFLOW.md](workflow/MEDIA-UPLOAD-WORKFLOW.md).

---

## 21. Logging

**English**

`CMS/core/Logger.php` is PSR-3 compatible. Default path: `ABSPATH . 'logs/'` (`LOG_PATH`). There is **no** `var/logs/` in this repository.

Also: `AuditLogger`, admin CMS logs (`cms-logs.php`, `logs-*.php`). Do not leak raw exceptions into JSON, checkout, media, or admin responses.

**Deutsch**

`CMS/core/Logger.php` ist PSR-3-kompatibel. Standardpfad: `ABSPATH . 'logs/'` (`LOG_PATH`). Es gibt **kein** `var/logs/` in diesem Repository.

Außerdem: `AuditLogger`, Admin-CMS-Logs (`cms-logs.php`, `logs-*.php`). Keine rohen Exceptions in JSON-, Checkout-, Media- oder Admin-Antworten leaken.

---

## 22. Best practices

**English**

- Edit the runtime file under `CMS/`, not only a sibling source repo.
- Escape output; prepare SQL; verify CSRF on every state change.
- Hide empty UI sections instead of placeholder noise (project convention).
- Keep plugin CSS prefixed; do not invent generic `.card` / `.button` collisions.
- After CPT-like or route changes, think through rewrites and caches.
- Match `Version.php`, `update.json`, marketplace metadata, and docs on release.

**Deutsch**

- Die Runtime-Datei unter `CMS/` bearbeiten, nicht nur ein Nachbar-Quellrepo.
- Output escapen; SQL preparen; CSRF bei jedem Zustandswechsel prüfen.
- Leere UI-Abschnitte verstecken statt Platzhaltertext.
- Plugin-CSS präfixen; keine generischen `.card`-/`.button`-Kollisionen.
- Nach Route-Änderungen Rewrites und Caches mitdenken.
- Bei Releases `Version.php`, `update.json`, Marktplatz-Metadaten und Doku angleichen.

---

## 23-27. Do not regress | Nicht-wieder-tun

**English**

Keep these regressions dead:

- nested forms in admin views
- absolute foreign hosts inside internal admin posts/redirects
- inline event handlers as the primary contract
- empty arrays silently replacing remote failures
- different limits in view vs module
- raw exceptions in user-facing JSON
- file read/download without realpath/root contract
- plugin/theme unzip without staging
- assuming an asset fix without checking live initialization
- releasing with broken required footer/auth/contact links
- treating a repo change as theoretical when it is FTP-deployed
- colliding plugin menu positions
- `Cannot redeclare` from double-including plugin classes
- public AI routes or auto-publish of AI output
- schema version rollback through the admin updater
- filename sanitizer regex that triggers `Unknown modifier ']'`

**Deutsch**

Diese Regressionen tot halten:

- verschachtelte Formulare in Admin-Views
- absolute Fremd-Hosts in internen Admin-Posts/Redirects
- Inline-Event-Handler als primären Vertrag
- stille Leer-Arrays als Ersatz für Remote-Fehler
- unterschiedliche Limits in View und Modul
- rohe Exceptions in nutzerseitigem JSON
- Dateilesen/Download ohne Realpath-/Root-Vertrag
- Plugin-/Theme-Unzip ohne Staging
- Asset-Fix ohne Prüfung der Live-Initialisierung
- Release mit defekten Pflichtlinks in Footer/Auth/Kontakt
- Repo-Änderung als „nur theoretisch“ behandeln, obwohl sie per FTP ausgeliefert wird
- kollidierende Plugin-Menüpositionen
- `Cannot redeclare` durch doppeltes Includen von Plugin-Klassen
- öffentliche AI-Routen oder Auto-Publish von AI-Output
- Schema-Versions-Rollback über den Admin-Updater
- Dateinamen-Sanitizer-Regex, der `Unknown modifier ']'` auslöst

Known gaps: [`core/STATUS.md`](core/STATUS.md). Changelog: [`../Changelog.md`](../Changelog.md).

---

## 28. Pitfalls | Stolperfallen

**English**

- Theme/plugin source repo ≠ runtime path
- Redirect/404 UI only half-fixed because JS is shared
- Editor.js changed in only one of factory / asset registry / public renderer
- Search hooked to the wrong event
- Home menu path not normalized as root
- Generic foreign-key names
- Live errors that are cache, rewrite, proxy, or data — not code
- Hard bugs sit on **boundaries**: entry↔module, module↔view, view↔asset, save↔follow-up, source↔runtime, docs↔operations

**Deutsch**

- Theme-/Plugin-Quellrepo ≠ Runtime-Pfad
- Redirect-/404-UI nur halb gefixt, weil JS geteilt ist
- Editor.js nur an einer Stelle von Factory / Asset-Registry / Public-Renderer geändert
- Suche am falschen Event
- Home-Menüpfad nicht als Root normalisiert
- generische Foreign-Key-Namen
- Live-Fehler durch Cache, Rewrite, Proxy oder Daten — nicht Code
- Harte Bugs sitzen an **Übergängen**: Entry↔Modul, Modul↔View, View↔Asset, Save↔Folgeprozess, Quelle↔Runtime, Doku↔Betrieb

---

## 29. Checklist | Checkliste vor Änderungen

**English**

Before a non-trivial change:

- [ ] Am I editing the runtime file under `CMS/`?
- [ ] Correct hook, router, shell, or view?
- [ ] DE/EN paths both affected?
- [ ] Shared JS or service involved?
- [ ] CSRF, escape, prepared statements?
- [ ] Settings, cache, index, cron?
- [ ] Theme/plugin runtime contract still true?
- [ ] Extra audit/logging needed?
- [ ] After deploy, which live URL must be checked?

**Deutsch**

Vor einer nicht trivialen Änderung:

- [ ] Bearbeite ich die Runtime-Datei unter `CMS/`?
- [ ] Richtiger Hook, Router, Shell oder View?
- [ ] DE-/EN-Pfade beide betroffen?
- [ ] Gemeinsames JS oder Service involviert?
- [ ] CSRF, Escape, Prepared Statements?
- [ ] Settings, Cache, Index, Cron?
- [ ] Theme-/Plugin-Runtime-Vertrag noch gültig?
- [ ] Zusätzliches Audit/Logging nötig?
- [ ] Nach dem Deploy: welche Live-URL muss geprüft werden?

---

## 30. Closing | Abschlussbild

**English**

Runtime chain:

**Configuration → Bootstrap → Security/Auth → Hooks → Services → Router → Module/Theme/Plugin → Logging/Audit → Operations**

Working model for this project:

**Repo ↔ runtime file under `CMS/` ↔ deploy ↔ live check**

Version source of truth: `CMS/core/Version.php` (`3.5.4`). `CMS/update.json`, `CMS/marketplace/core/365cms/update.json`, the release manifests under `RELEASE/` and the newest `Changelog.md` heading must show the same version. Every PR gets its own version: new `Changelog.md` heading `### vX.Y.Z — DD.MM.YYYY` (no "unreleased" sections or `–` placeholders in the version column), `Version::CURRENT`, both `update.json` files and the release packages `RELEASE/365CMS-X.Y.Z-{update,full}.zip` with SHA-256 are updated in the same PR.

**Deutsch**

Runtime-Kette:

**Konfiguration → Bootstrap → Security/Auth → Hooks → Services → Router → Modul/Theme/Plugin → Logging/Audit → Betrieb**

Arbeitsmodell dieses Projekts:

**Repo ↔ Runtime-Datei unter `CMS/` ↔ Deploy ↔ Live-Prüfung**

Versionsquelle: `CMS/core/Version.php` (`3.5.4`). `CMS/update.json`, `CMS/marketplace/core/365cms/update.json`, die Release-Manifeste unter `RELEASE/` und die oberste Überschrift in `Changelog.md` müssen dieselbe Version zeigen. Jeder PR erhält eine eigene Version: neue Überschrift `### vX.Y.Z — TT.MM.JJJJ` in `Changelog.md` (keine Abschnitte „Unveröffentlicht“ und keine `–`-Platzhalter in der Versionsspalte); `Version::CURRENT`, beide `update.json` und die Release-Pakete `RELEASE/365CMS-X.Y.Z-{update,full}.zip` samt SHA-256 werden im selben PR angepasst.
