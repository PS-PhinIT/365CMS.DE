# 365CMS – Projektdokumentation | Abschnitt: Core – Klassenübersicht

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

Inventory of the classes directly under `CMS/core/` (namespace `CMS\`) and its sub-namespaces `CMS\Auth`, `CMS\Contracts`, `CMS\Http`, `CMS\Member` and `CMS\Routing`. Domain services in `CMS\Services\…` are listed in [SERVICES.md](SERVICES.md). Most core classes are singletons accessed via `::instance()`; services use `::getInstance()` and are additionally registered in the DI container.

## Deutsch

### Laufzeit & Infrastruktur

| Klasse | Datei | Aufgabe | Wichtige Methoden |
|---|---|---|---|
| `Bootstrap` | `core/Bootstrap.php` | Start, Modus, Plattformprüfung, Initialisierung, Run | `instance()`, `run()`, `db()`, `auth()`, `security()`, `container()` |
| `Container` | `core/Container.php` | kleiner DI-Container | `bind()`, `singleton()`, `bindInstance()`, `make()`, `get()`, `has()`, `registered()`, `forget()`, `flush()` |
| `Router` | `core/Router.php` | URI-Auflösung, Routenregister, Dispatch, Redirect, 404, Inhaltsaufbereitung | `addRoute()`, `dispatch()`, `redirect()`, `render404()`, `renderError()`, `getRequestLocale()`, `prepareRenderableContent()`, `streamContentAsPdf()` |
| `Hooks` | `core/Hooks.php` | Actions/Filter | siehe [HOOKS-REFERENCE.md](HOOKS-REFERENCE.md) |
| `Database` | `core/Database.php` | PDO-Wrapper | siehe [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) |
| `SchemaManager` | `core/SchemaManager.php` | Basisschema, `SCHEMA_VERSION = 'v23'` | `createTables()`, `getFlagFile()`, `clearFlag()` |
| `MigrationManager` | `core/MigrationManager.php` | Migrationen je Schemaversion | `run()`, `repairTables()` |
| `DatabaseUpdateRunner` | `core/DatabaseUpdateRunner.php` | manuelles DB-Update | `getStatus()`, `run()` |
| `CacheManager` | `core/CacheManager.php` | Datei-/APCu-Cache (PSR-16-ähnlich), HTTP-Cache-Header | `get()`, `set()`, `delete()`, `clear()`, `clearAll()`, `getStatus()`, `sendResponseHeaders()`, `sendConditionalHeaders()` |
| `Logger` | `core/Logger.php` | PSR-3-ähnliches Logging in Kanal-Dateien | `withChannel()`, `debug()` … `emergency()`, `log()` |
| `AuditLogger` | `core/AuditLogger.php` | Audit-Log in `cms_audit_log` | `log()`, `loginSuccess()`, `loginFailed()`, `pluginAction()`, `themeSwitch()`, `userRoleChange()`, `backupAction()`, `getRecent()` |
| `Debug` | `core/Debug.php` | Checkpoints und Query-Telemetrie bei `CMS_DEBUG` | `checkpoint()`, `query()` |
| `Json` | `core/Json.php` | sichere JSON-Hilfen | `decodeArray()` u. a. |
| `Version` | `core/Version.php` | `CURRENT = '3.5.0'`, `RELEASE_DATE = '2026-10-14'`, `STATUS = 'stable'` | `current()`, `releaseDate()` |
| `VendorRegistry` | `core/VendorRegistry.php` | Inventar und Laden gebündelter Bibliotheken | `loadAssetsAutoloader()`, `loadPackage()`, `getDiagnostics()` |
| `WP_Error` | `core/WP_Error.php` | WordPress-kompatibles Fehlerobjekt | `get_error_code()`, `get_error_message()`, `get_error_data()`, `add()` |
| `Api` | `core/Api.php` | generische JSON-API (Rate-Limit, `pages`, `users`) | `handleRequest()` |

### Sicherheit & Authentifizierung

| Klasse | Datei | Aufgabe |
|---|---|---|
| `Security` | `core/Security.php` | Header, CSP-Nonce, Session-Start, CSRF-Tokens, Rate-Limits, Passwort-Hash, Client-IP (`getClientIp()`), Sanitizing |
| `Auth` | `core/Auth.php` | Login, Registrierung, Session-Benutzer, Rollen/Capabilities, Passwortrichtlinie, MFA (TOTP), Geräte-Cookie, Logout |
| `Auth\AuthManager` | `core/Auth/AuthManager.php` | Fassade für Passwort-, Passkey-, LDAP-, MFA-Anmeldung; `getAvailableProviders()` |
| `Auth\Passkey\WebAuthnAdapter` | `core/Auth/Passkey/` | WebAuthn-Registrierung und -Anmeldung |
| `Auth\LDAP\LdapAuthProvider` | `core/Auth/LDAP/` | LDAP/AD-Anmeldung, Verzeichnis-Sync |
| `Auth\MFA\TotpAdapter`, `BackupCodesManager` | `core/Auth/MFA/` | Zweiter Faktor, Backup-Codes |
| `Totp` | `core/Totp.php` | RFC-6238-TOTP: Secret, Code, `otpauth://`-URI, QR-Code |

### Inhalte, Themes, Plugins, Abos

| Klasse | Datei | Aufgabe |
|---|---|---|
| `PageManager` | `core/PageManager.php` | Seiten-CRUD, Slug, Suche, Revisionen, Sprachverfügbarkeit |
| `TableOfContents` | `core/TableOfContents.php` | Inhaltsverzeichnis aus Überschriften (Option `toc_settings`) |
| `ThemeManager` | `core/ThemeManager.php` | aktives Theme, Template-Rendering, Header/Footer, Menüs, Theme-Wechsel/-Löschen/-Health-Check, Custom Styles, Favicon |
| `PluginManager` | `core/PluginManager.php` | Plugins laden, aktivieren, deaktivieren, löschen, per ZIP installieren; Abhängigkeitsprüfung |
| `SubscriptionManager` | `core/SubscriptionManager.php` | Pakete, Abos, Limits, Plugin-Freigaben, Verlängerungshinweise |
| `Member\PluginDashboardRegistry` | `core/Member/` | Registrierung von Plugin-Widgets und -Bereichen im Mitglieder-Dashboard |

### Routing (`CMS\Routing`)

| Klasse | Routen |
|---|---|
| `ApiRouter` | `/api/v1/*`, `/api/upload`, `/api/media` |
| `AdminRouter` | `/admin`, `/admin/:page`, `/admin/logs/:section`, `/admin/plugins/:plugin/:page` |
| `MemberRouter` | `/member`, `/member/:page`, `/member/plugin/:slug[/:action[/:id]]`, `/dashboard` |
| `PublicRouter` | Login/Registrierung/Passwort, Logout, MFA, `/order`, `/comments/post`, `/cookie-einstellungen`, `/media-file` |
| `ThemeRouter` | `/`, `/blog`, Beitrags-Permalinks, Archive, `/search`, `/sitemap`, `/sitemap.xml`, `/robots.txt`, `/feed`, `/contact`, Autoren, `/security.txt`, IndexNow-Key, Tabellen-Export |
| `ThemeArchiveRepository` | Datenabfragen für Kategorie-/Tag-/Autoren-Archive |

### HTTP & Verträge

| Klasse | Aufgabe |
|---|---|
| `Http\Client` | ausgehende HTTP-Anfragen (cURL) mit Host-Prüfung, Timeouts, Größenlimits |
| `Http\Request` | Request-Hilfen |
| `Http\InlineStyleRewriter` | wandelt `style`-Attribute in nonce-geschützte Klassen (CSP) |
| `Contracts\CacheInterface`, `DatabaseInterface`, `LoggerInterface` | Schnittstellen für Cache, Datenbank, Logger |

### Hilfsfunktionen (`CMS/includes/`)

`functions.php` lädt `includes/functions/*.php`: `admin-menu.php` (Admin-Menü-API), `escaping.php` (Escaping-Helfer), `mail.php`, `options-runtime.php` (Optionen, `cms_post_publication_where()`, CSP-Vorbereitung), `plugin-public-i18n.php`, `redirects-auth.php` (`current_user_can()`, Redirect-Helfer), `roles.php` (Rollen/Capabilities), `translation.php`, `wordpress-compat.php` (`add_action`, `get_option`, `esc_html` …). `subscription-helpers.php` enthält Abo-Helfer.

### Verwandte Dokumente

[SERVICES.md](SERVICES.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [STRUCTURE.md](STRUCTURE.md)
