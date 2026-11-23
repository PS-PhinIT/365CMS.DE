# 365CMS Projektdokumentation | Core: Klassenübersicht

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Schema:** v27 | **Status:** Stable

## English (summary)

Inventory of the classes directly under `CMS/core/` and the implemented Community runtime (`CMS\Services\Community`). Domain services are listed in [SERVICES.md](SERVICES.md). The Community routes use `PublicController`, `ComposeController`, `ApiController`, and `AttachmentController`; access and write decisions are centralized in `CommunityAccess` and `WriteGate`.

## Deutsch

### Laufzeit & Infrastruktur

| Klasse | Datei | Aufgabe | Wichtige Methoden |
|---|---|---|---|
| `Bootstrap` | `core/Bootstrap.php` | Start, Modus, Plattformprüfung, Initialisierung, Run | `instance()`, `run()`, `db()`, `auth()`, `security()`, `container()` |
| `Container` | `core/Container.php` | kleiner DI-Container | `bind()`, `singleton()`, `bindInstance()`, `make()`, `get()`, `has()`, `registered()`, `forget()`, `flush()` |
| `Router` | `core/Router.php` | URI-Auflösung, Routenregister, Dispatch, Redirect, 404, Inhaltsaufbereitung | `addRoute()`, `dispatch()`, `redirect()`, `render404()`, `renderError()`, `getRequestLocale()`, `prepareRenderableContent()`, `streamContentAsPdf()`, ruft `dispatch()` die private Konto-Weiche `enforceAccountGates()` (abgelaufenes Passwort, MFA-Zwang) vor dem CSRF-Block auf und nimmt `/payments/webhook/` vom CSRF-Schutz aus |
| `Hooks` | `core/Hooks.php` | Actions/Filter | siehe [HOOKS-REFERENCE.md](HOOKS-REFERENCE.md) |
| `Database` | `core/Database.php` | PDO-Wrapper | siehe [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) |
| `SchemaManager` | `core/SchemaManager.php` | Basisschema `v27` (118 Tabellendefinitionen; 28 Community-Tabellen mit 284 Spalten und 65 Nicht-Primär-Indizes), `FEATURE_TABLES_V26`, `FEATURE_TABLES_V27`, Integritätskonstanten und `ensureFeatureSchema()` | `getSchemaQueries()`, `createTables()`, `getFlagFile()`, `clearFlag()` |
| `MigrationManager` | `core/MigrationManager.php` | Migrationen je Schemaversion | `run()`, `repairTables()` |
| `DatabaseUpdateRunner` | `core/DatabaseUpdateRunner.php` | manuelles DB-Update | `getStatus()`, `run()` |
| `CacheManager` | `core/CacheManager.php` | Datei-/APCu-Cache (PSR-16-ähnlich), HTTP-Cache-Header | `get()`, `set()`, `delete()`, `clear()`, `clearAll()`, `getStatus()`, `sendResponseHeaders()`, `sendConditionalHeaders()` |
| `Logger` | `core/Logger.php` | PSR-3-ähnliches Logging in Kanal-Dateien | `withChannel()`, `debug()` … `emergency()`, `log()` |
| `AuditLogger` | `core/AuditLogger.php` | Audit-Log in `cms_audit_log` | `log()`, `loginSuccess()`, `loginFailed()`, `pluginAction()`, `themeSwitch()`, `userRoleChange()`, `backupAction()`, `getRecent()` |
| `Debug` | `core/Debug.php` | Checkpoints und Query-Telemetrie bei `CMS_DEBUG` | `checkpoint()`, `query()` |
| `Json` | `core/Json.php` | sichere JSON-Hilfen | `decodeArray()` u. a. |
| `Slug` | `core/Slug.php` | URL-Slugs für Beiträge, Kategorien, Tags und Seiten: Umlaut-Umschrift (ä → ae, ß → ss), Akzente entfernt, dann `[a-z0-9-]` | `fromText()`, `transliterate()` |
| `Version` | `core/Version.php` | `CURRENT = '4.2.0'`, `RELEASE_DATE = '2026-11-23'`, `STATUS = 'stable'` | `current()`, `releaseDate()` |
| `VendorRegistry` | `core/VendorRegistry.php` | Inventar und Laden gebündelter Bibliotheken | `loadAssetsAutoloader()`, `loadPackage()`, `getDiagnostics()` |
| `WP_Error` | `core/WP_Error.php` | WordPress-kompatibles Fehlerobjekt | `get_error_code()`, `get_error_message()`, `get_error_data()`, `add()` |
| `Api` | `core/Api.php` | generische JSON-API (Rate-Limit, `pages`, `users`) | `handleRequest()` |

### Sicherheit & Authentifizierung

| Klasse | Datei | Aufgabe |
|---|---|---|
| `Security` | `core/Security.php` | Header, CSP-Nonce, Session-Start, CSRF-Tokens, Rate-Limits, Passwort-Hash, Client-IP (`getClientIp()`), Sanitizing |
| `Auth` | `core/Auth.php` | Login (inkl. Verzögerung je Konto), Registrierung, Session-Benutzer, Rollen/Capabilities, Passwortrichtlinie, MFA (TOTP über `TotpAdapter`), Geräte-Cookie, Logout, „Überall abmelden“ (`revokeAllSessions()`) |
| `Auth\AuthManager` | `core/Auth/AuthManager.php` | Fassade für Passwort-, Passkey-, LDAP-, MFA-Anmeldung; `getAvailableProviders()` |
| `Auth\Passkey\WebAuthnAdapter` | `core/Auth/Passkey/` | WebAuthn-Registrierung und -Anmeldung |
| `Auth\LDAP\LdapAuthProvider` | `core/Auth/LDAP/` | LDAP/AD-Anmeldung, Verzeichnis-Sync |
| `Auth\MFA\TotpAdapter`, `BackupCodesManager` | `core/Auth/MFA/` | Zweiter Faktor (Secrets seit 4.0.0 verschlüsselt über `CryptoService`), Backup-Codes |
| `Auth\SSO\EntraSsoService` | `core/Auth/SSO/` | Entra-ID-Anmeldung: Start, Callback (Rate-Limit → `state` → Code → ID-Token → Zuordnung → `completeLoginForUserId`), Verknüpfen/Trennen, frische SSO-Anmeldung als Re-Auth ([SSO-ENTRA.md](../admin/security/SSO-ENTRA.md)) |
| `Auth\SSO\EntraIdProvider` (`OidcProviderInterface`) | `core/Auth/SSO/` | Autorisierungs-URL (PKCE S256), Token-Tausch, ID-Token-Prüfung (nur RS256, JWKS-Cache 24 h, `iss`/`aud`/`nonce`/`tid`/`oid`/`ver`), `amr`, `xms_edov`, „Verbindung prüfen“ |
| `Auth\SSO\EntraSettings` | `core/Auth/SSO/` | Einstellungsgruppe `auth_entra` (Secret verschlüsselt), `passwordLoginAllowed()`, Secret-Ablaufhinweis |
| `Auth\SSO\IdentityLinker` (`IdentityStoreInterface`, `DatabaseIdentityStore`) | `core/Auth/SSO/` | Kontozuordnung über `tid`+`oid` (`user_identities`), bedingte automatische Verknüpfung, Provisioning, Rollen-Mapping |
| `Auth\SSO\OidcFlowState` | `core/Auth/SSO/` | `state`/`nonce`/PKCE-Verifier in der Sitzung, einmalig, 10 Minuten |
| `Services\Security\PasswordPolicyService` | `core/Services/Security/` | konfigurierbare Passwort-Richtlinie: Länge, Unicode-Zeichenklassen, Historie (`password_history`), Höchstalter (`users.password_changed_at`); Hook `cms_password_changed`, Filter `cms_password_policy` |
| `Services\Security\MfaEnforcementService` | `core/Services/Security/` | rollenbasierter MFA-Zwang: `isRequiredFor()`, `hasRecognisedFactor()`, `decide()` für die Konto-Weiche `Router::enforceAccountGates()`; Filter `cms_mfa_enforcement_required` |
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

### Community (`CMS\Services\Community`)

| Klasse/Gruppe | Aufgabe |
|---|---|
| `Http\PublicController`, `ComposeController` | Leseseiten, Profile, Suche, Listen, neues Thema, Antwort und Bearbeiten unter `/forum`; Keyset-/Cursor-Navigation, `ReadScope`, JSON-LD auf öffentlichen ersten Themenseiten |
| `Http\ApiController`, `AttachmentController` | Nur-POST-Interaktionen unter `/forum/api/{aktion}` (CSRF mit dauerhaftem Token) sowie geschützte Auslieferung privater Anhänge |
| `Service\CommunityAccess` | einzige fail-closed Rechteentscheidung; Modul, Konto/E-Mail, Sperren, sechs Capabilities, Forumrechte, Vertrauensstufe, Moderationsbereich, Zustände und Filter |
| `Service\WriteGate`, `ContentGuard`, `CaptchaGate` | Schreibprüfung, Wortfilter/Spam/Rate-Limits/IP-HMAC sowie lokale Captchas (`pow`, `image`, `math`); der Filter darf Captcha nur verschärfen |
| `Service\DraftService` | Server-Entwürfe je Mitglied und Ziel; Laden/Speichern nur nach `can('read')`, Verwerfen nach erfolgreichem Absenden |
| `Support\ReadScope`, `Cursor` | Memoisierung pro Leseanforderung und Cursor-Keyset (`Y-m-d H:i:s UTC` + ID), keine `OFFSET`-Paginierung der Community-Listen |
| `Service\AttachmentService` | privater Dateispeicher, MIME-/Namens-/Bildprüfung, Limits, sichere Zuordnung und Auslieferungsprüfung |
| `Service\CounterBook`, `TopicTrashService`, `CommunityContentProvider` | atomare Zähler nach S1 und zentraler Papierkorb für Themen (`status = deleted`), Wiederherstellen/endgültiges Löschen |
| `CommunityPrivacy`, `CommunityRetention`, `CommunityLegal` | DSGVO-Export/Löschung, Aufbewahrungsregeln und dynamischer Abschnitt 8a der Datenschutzerklärung |

### Routing (`CMS\Routing`)

| Klasse | Routen |
|---|---|
| `ApiRouter` | `/api/v1/*`, `/api/upload`, `/api/media` |
| `AdminRouter` | `/admin`, `/admin/:page`, `/admin/logs/:section`, `/admin/plugins/:plugin/:page` |
| `MemberRouter` | `/member`, `/member/:page`, `/member/plugin/:slug[/:action[/:id]]`, `/dashboard` |
| `PublicRouter` | Login/Registrierung/Passwort, Bestätigungslinks (`/cms-verify-email`, `/cms-confirm-email-change`), Logout, MFA, `/order`, `/comments/post`, `/cookie-einstellungen`, `/media-file` |
| `SsoRouter` | `/cms-login/sso/entra/start`, `/cms-login/sso/entra/callback` (Zwischenseite wegen `SameSite=Strict`) |
| `PaymentRouter` | `POST /payments/webhook/{stripe\|mollie}` (ohne Sitzung und CSRF, Signatur bzw. Abruf), `GET /payments/return/{Bestellnummer}?sig=…` (signierter Rücksprung) |
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
