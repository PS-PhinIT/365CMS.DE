# 365CMS – Projektdokumentation | Abschnitt: Audit 2026-10-03

> **Stand:** 2026-10-03 | **Version:** 3.5.0 | **Schema:** v23 | **Prüfumfang:** `CMS/` ohne `CMS/vendor/` und gebündelte Drittbibliotheken unter `CMS/assets/`

## English (summary)

Full code audit of 365CMS on 2026-10-03 covering security, performance, missing or incomplete functionality and broken references (code and documentation). 536 first-party PHP files were linted (no syntax errors) and checked with targeted static sweeps plus manual review of all hits. 36 findings: 19 fixed in changelog entry `3.5.0`, 15 in `3.5.0` (four of them – ineffective DB rate limits on servers whose MySQL time zone differs from PHP, a contact form that could never be submitted, a fresh-install 500 on `/blog`, and a git allowlist bypass – were found by runtime tests against MariaDB); one is partially addressed (PERF-06) and one was left open as a release decision (FUN-12, runtime version; closed in 3.5.0). Details per area are in the linked documents below.

## Deutsch

### Ablage

Die Berichte der Audits vom 2026-10-03 und 2026-10-07 liegen in `DOC/checks/`. Das Gesamtaudit vom 2026-10-14 liegt in [`DOC/audit/`](../audit/README.md).

### Gesamtaudit 2026-10-14

[../audit/README.md](../audit/README.md) – Sicherheit, Geschwindigkeit, Massenanfragen/Bot-Last, fehlende Funktionen, Verweise und GUI (54 Befunde, 41 behoben mit Changelog `3.5.0`, 13 offene Empfehlungen). Die IDs setzen die Nummerierung fort (SEC-16 ff., PERF-07 ff., FUN-21 ff., REF-06 ff.); `LAST` und `GUI` sind neu.

### Folgeaudit 2026-10-07

[FOLGEAUDIT-2026-10-07.md](FOLGEAUDIT-2026-10-07.md) – Sicherheit, SEO, Geschwindigkeit, interne Verlinkung, Barrierefreiheit und unvollständige Funktionen (32 Befunde, behoben mit Changelog `3.5.0`; Laufzeittests gegen MariaDB und axe-core). Die IDs setzen die Nummerierung dieses Audits fort (SEC-12 ff., FUN-15 ff.); SEO- und A11Y-Befunde sind neu.

### Dokumente dieses Audits

| Dokument | Inhalt |
|---|---|
| [SECURITY.md](SECURITY.md) | Sicherheitsbefunde (SQL, XSS, CSRF, Uploads, Redirects, Konfiguration, Rate-Limits, Proxy-IP) |
| [PERFORMANCE.md](PERFORMANCE.md) | Geschwindigkeit: N+1-Abfragen, Pro-Request-Arbeit, Indizes, Frontend |
| [FUNKTIONEN.md](FUNKTIONEN.md) | Fehlende, unvollständige oder tote Funktionen |
| [VERWEISE.md](VERWEISE.md) | Falsche Verweise in Code, Links und Dokumentation |

### Methodik

1. **Syntax:** `php -l` über alle 536 PHP-Dateien außerhalb von `vendor/` und `assets/` (PHP 8.3 CLI) – **0 Fehler**.
2. **Statische Sweeps** (jeder Treffer manuell geprüft):
   - gefährliche Funktionen (`eval`, `exec`, `shell_exec`, `unserialize`, dynamische `include`),
   - SQL mit String-Interpolation, `ORDER BY`/`LIMIT` aus Variablen,
   - ungefilterte Ausgabe von Superglobals und Variablen in Views/Themes,
   - POST-Handler ohne CSRF-Prüfung, offene Redirects, Cookie-/Session-Flags, Rate-Limits,
   - Upload-Validierung, SSRF-Schutz des HTTP-Clients, Installer-Sperre,
   - Abfragen innerhalb von Schleifen (N+1), Dateisystem-Scans im Request-Pfad,
   - `require`/`include` auf nicht vorhandene Dateien, Asset-URLs, Klassen-Referenzen (`CMS\…`), Admin-Links gegen das Routing,
   - Markdown-Links und in `DOC/` genannte `CMS/…`-Pfade gegen das Repository.
3. **Abgleich** mit der Tabelle „Bekannte Lücken“ in [../core/STATUS.md](../core/STATUS.md) – alle 12 Einträge wurden nachgeprüft.

4. **Laufzeittests (3.5.0)** gegen MariaDB 10.11 mit dem PHP-Entwicklungsserver: Neuinstallation (Schema v23) und Migration v22 → v23, Startseite/Blog/Login, Wartungsmodus (Besucher, Admin, API, `/health`), `/order`, Einstellungen speichern (Config-Patch, Backup-Name), JWT (Token, Bearer, Refresh, Herkunft, gesperrtes Konto), Doku-Sync im Git-Modus, Plugin-Member-CSRF und `wp_enqueue_*` mit Test-Plugin, Rate-Limits (Login, Registrierung, Kontakt), Upload-Doppelendungen, Abfragezahl und Antwortzeit gegen `main`.

Einschränkungen: Die Prüfumgebung hat PHP 8.3; für die Laufzeittests wurden in einer **Kopie** die Mindestversion auf 8.3 gesetzt und die Plattformprüfung der gebündelten Symfony-Pakete übersprungen. Nicht geprüft: Mailversand, LDAP, Passkeys, Drittanbieter-Bundles unter `CMS/assets/`, externe Plugin-/Theme-Repositories.

### Gesamtbild

| Bereich | Bewertung | Kurzfazit |
|---|---|---|
| Sicherheit | **gut** (nach Fix SEC-10) | Durchgängig Prepared Statements, `htmlspecialchars` in Views, CSRF-Token, bcrypt (cost 12), Session-Regeneration, SSRF-Schutz mit IP-Pinning, gehärtete `.htaccess`. Behoben: Klartext-Config-Backups, Doppelendungen bei Uploads, Rate-Limits für Registrierung und Kontaktformular, Proxy-IP, CSRF für Plugin-Member-Bereiche, Google Fonts standardmäßig aus. |
| Geschwindigkeit | **befriedigend** | Kein gravierender Engpass gefunden; viele Einzelabfragen auf `settings` pro Request. Behoben: N+1 im Standard-Theme, zentraler Options-Cache (`OptionStore`), Composite-Index für Blog-Listen, redundanter Index entfernt. |
| Funktionsumfang | **befriedigend** | Mehrere gespeicherte, aber nie ausgewertete Optionen. Behoben: Wartungsmodus, `/order`, `/health`, Speicher-Limit, Upload-Token, Sammel-Löschen, Config-Speichern, JWT-API, Doku-Sync-UI, WP-Asset-Funktionen, toter Code. Offen: Versionsnummer (Release-Entscheidung). |
| Verweise | **gut** | Keine fehlenden Includes oder Klassen. Behoben: ein toter Admin-Link, 15 tote Doku-Links, veraltete Marketplace-Manifeste. |

### Befundübersicht und Status

Schweregrad: 🔴 hoch · 🟠 mittel · 🟡 niedrig · ⚪ Hinweis

| ID | Bereich | Befund | Schwere | Status |
|---|---|---|---|---|
| SEC-01 | Sicherheit | Backups `config/app.php.backup.*` werden ohne `.htaccess`-Schutz (nginx) als Klartext mit DB-Zugangsdaten ausgeliefert | 🔴 | ✅ behoben |
| SEC-02 | Sicherheit | Upload-Doppelendungen (`shell.php.jpg`) nicht blockiert; Liste gefährlicher Endungen unvollständig | 🟠 | ✅ behoben |
| SEC-03 | Sicherheit | Registrierung ohne Rate-Limit | 🟠 | ✅ behoben |
| SEC-04 | Sicherheit | Client-IP hinter Reverse Proxy = Proxy-IP → Login-Sperre trifft alle Nutzer | 🟠 | ✅ behoben (opt-in `CMS_TRUSTED_PROXIES`) |
| SEC-05 | Sicherheit | Sicherheits-Audit bewertet PHP ≥ 8.2 als „ok“, Mindestanforderung ist 8.4 | 🟡 | ✅ behoben |
| SEC-06 | Sicherheit | Config-Parser verliert Werte mit `'`/`\` (Passwörter) | 🟠 | ✅ behoben |
| SEC-07 | Sicherheit | Plugin-Member-Bereiche (`post_callback`) ohne zentrale CSRF-Prüfung | 🟠 | ✅ 3.5.0 |
| SEC-08 | Sicherheit | Kontaktformular des Standard-Themes ohne Rate-Limit (nur Honeypot + CSRF) | 🟡 | ✅ 3.5.0 |
| SEC-09 | Sicherheit | Google Fonts werden standardmäßig extern geladen (DSGVO) | 🟡 | ✅ 3.5.0 |
| SEC-10 | Sicherheit | DB-Rate-Limits (Login, Passwort-Reset, Registrierung, API, Kontakt) und Firewall-/Alarmfenster wirkungslos, wenn MySQL-Zeitzone ≠ PHP-Zeitzone (z. B. UTC vs. Europe/Berlin) | 🔴 | ✅ 3.5.0 – Laufzeittest |
| SEC-11 | Sicherheit | `DocumentationSyncEnvironment::isAllowedCommand()` übersprang die Git-Allowlist bei jedem Befehl mit `2>&1` | 🟡 | ✅ 3.5.0 |
| PERF-01 | Performance | N+1-Abfragen im Cookie-Banner und Kontaktblock des Standard-Themes | 🟡 | ✅ behoben |
| PERF-02 | Performance | `privacy_use_local_fonts` zweimal pro Seitenaufruf abgefragt | ⚪ | ✅ behoben |
| PERF-03 | Performance | Viele Einzelabfragen auf `settings` pro Request statt zentralem Cache | 🟠 | ✅ 3.5.0 (`OptionStore`) |
| PERF-04 | Performance | Kein Composite-Index `posts(status, published_at)` für Blog-Listen | 🟡 | ✅ 3.5.0 (Schema v23) |
| PERF-05 | Performance | Redundanter Index `settings.idx_key` neben `UNIQUE(option_name)` | ⚪ | ✅ 3.5.0 (Schema v23) |
| PERF-06 | Performance | 47 `SELECT *`-Abfragen, u. a. `PageManager::listPages()` ohne Limit | ⚪ | teilweise – `listPages()` entfernt, `SELECT *` bleibt Hinweis |
| FUN-01 | Funktion | Wartungsmodus wird gespeichert, aber nie ausgewertet | 🟠 | ✅ behoben |
| FUN-02 | Funktion | Speichern unter `/admin/settings` überschreibt manuelle Konstanten in `config/app.php` | 🟠 | ✅ behoben |
| FUN-03 | Funktion | `/order` liefert 404 (`member/order_public.php` fehlt) | 🟠 | ✅ behoben (Weiterleitung auf `/orders.php`) |
| FUN-04 | Funktion | Health-Endpunkt `/health` konfigurierbar, aber keine Route | 🟡 | ✅ behoben (Standardpfad) |
| FUN-05 | Funktion | `checkLimit('storage')` findet Spalte `limit_storage_mb` nicht; Free-Fallback zufällig | 🟠 | ✅ behoben |
| FUN-06 | Funktion | Erster Member-Upload je Seitenaufruf scheitert (falscher CSRF-Kontext) | 🟠 | ✅ behoben |
| FUN-07 | Funktion | Sammelaktion `delete` löscht endgültig wie `hard_delete` | 🟡 | ✅ behoben (Soft-Delete) |
| FUN-13 | Funktion | Kontaktformular des Standard-Themes nie absendbar: `POST /contact` → 404 (nur GET-Route) | 🟠 | ✅ 3.5.0 – Laufzeittest |
| FUN-14 | Funktion | Frische Installation: `/blog` und `/api/v1/admin/posts` → 500 (`posts.title_en`/`content_en`/`excerpt_en` fehlen, bis `/admin/posts` einmal geöffnet wird) | 🔴 | ✅ 3.5.0 – Schema v23, Laufzeittest |
| FUN-08 | Funktion | `JwtService` nicht an API-Routen angebunden | 🟡 | ✅ 3.5.0 (opt-in) |
| FUN-09 | Funktion | Doku-Sync-Klassen nicht an `/admin/documentation` angebunden | 🟡 | ✅ 3.5.0 |
| FUN-10 | Funktion | `wp_enqueue_style/script`, `wp_dequeue_*` sind leere Stubs | 🟡 | ✅ 3.5.0 |
| FUN-11 | Funktion | Toter Code: `DesignSettingsModule`, `views/themes/settings.php`, `PageManager::listPages()`, Customizer-Helper | ⚪ | ✅ 3.5.0 (entfernt) |
| FUN-12 | Funktion | Runtime-Version bleibt `3.4.00`, obwohl Fixes bis 3.5.0 ausgeliefert werden | 🟡 | ✅ 3.5.0 (Version, `update.json`, Release-Pakete) |
| REF-01 | Verweise | Admin-Link `/admin/subscriptions/packages` → 404 | 🟡 | ✅ behoben |
| REF-02 | Verweise | Marketplace-Manifeste nennen `cms-importer` 1.6.0 / `cms-default` 1.0.3 | 🟡 | ✅ behoben |
| REF-03 | Verweise | 15 tote Markdown-Links in `Changelog.md` und `DOC/` | ⚪ | ✅ behoben |
| REF-04 | Verweise | `README.md` nennt Changelog-Stand 3.5.0 | ⚪ | ✅ behoben |
| REF-05 | Verweise | Customizer-Helper verweist auf nicht existierendes `admin/partials/admin-menu.php` | ⚪ | ✅ 3.5.0 (entfernt) |

**Summe:** 36 Befunde – 19 mit 3.5.0 behoben, 15 mit 3.5.0 behoben (davon 4 erst bei den Laufzeittests gefunden: SEC-10, SEC-11, FUN-13, FUN-14), 1 teilweise (PERF-06), 1 als Release-Entscheidung offen gelassen (FUN-12, mit 3.5.0 erledigt).

### Geänderte Dateien

**3.5.0:** neu `CMS/core/Services/OptionStore.php`; geändert u. a. `Database`, `Auth`, `Router`, `ApiRouter`, `JwtService`, `PluginDashboardRegistry`, `MigrationManager`, `SchemaManager`, `ThemeManager`, `PluginManager`, `Bootstrap`, `TableOfContents`, mehrere Services, `includes/functions/options-runtime.php`, `includes/functions/wordpress-compat.php`, `admin/documentation.php` mit Modul und View, Theme `cms-default` (`functions.php`, `contact.php`, `theme.json`, Customizer); entfernt `DesignSettingsModule.php`, `admin/views/themes/settings.php`.

**3.5.0:**
`CMS/core/Router.php`, `CMS/core/Routing/PublicRouter.php`, `CMS/core/Security.php`, `CMS/core/SubscriptionManager.php`, `CMS/core/Services/MediaService.php`, `CMS/core/Services/UserService.php`, `CMS/admin/modules/settings/SettingsModule.php`, `CMS/admin/modules/security/SecurityAuditModule.php`, `CMS/admin/views/subscriptions/settings.php`, `CMS/install/InstallerService.php`, `CMS/member/media.php`, `CMS/themes/cms-default/functions.php`, `CMS/themes/cms-default/contact.php`, `CMS/marketplace/plugins/index.json`, `CMS/marketplace/themes/index.json` sowie Dokumentation.

### Verwandte Dokumente

[../core/STATUS.md](../core/STATUS.md) · [../core/SECURITY.md](../core/SECURITY.md) · [../INDEX.md](../INDEX.md) · [../../Changelog.md](../../Changelog.md)
