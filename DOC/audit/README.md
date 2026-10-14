# 365CMS – Projektdokumentation | Abschnitt: Gesamtaudit 2026-10-14

> **Stand:** 2026-10-14 | **Version:** 3.4.00 (Changelog bis 3.4.25) | **Prüfumfang:** `CMS/` ohne `CMS/vendor/` und gebündelte Drittbibliotheken unter `CMS/assets/`, Standard-Theme `cms-default`, Plugin `cms-importer`, CI-Workflow `.github/workflows/security.yml` | Vorherige Audits: [../checks/README.md](../checks/README.md)

## English (summary)

Full audit of 365CMS on 2026-10-14 covering security, speed, behaviour under mass requests and bot traffic, missing functions, broken references, GUI defects and operational topics. The checklist was compiled from OWASP Top 10:2025 and current website/CMS audit checklists (see [PRUEFKATALOG.md](PRUEFKATALOG.md)). 54 findings were recorded; 41 were fixed in changelog entry `3.4.25`, 13 are documented as open recommendations. The most important fixes:

- The second factor at login could be brute-forced without limit and accepted replayed TOTP codes.
- Fresh installs lacked the unique key on `user_meta`, so MFA secrets and profile fields were duplicated.
- Disabling MFA, regenerating backup codes, registering passkeys and changing the e-mail address did not ask for the current password.
- Private member and import uploads were reachable by URL.
- Every request ran schema checks (`SHOW TABLES`, `CREATE TABLE IF NOT EXISTS`, `SHOW INDEX`) and opened a second database connection.
- The firewall wrote two log rows per blocked request.
- Search, 404 logging and the Core Web Vitals endpoint had no limits, or limits that could be bypassed.
- Analytics tables grew without any retention.

Runtime tests against MySQL 8.0 show the number of queries per request dropping from 61 to 15 (home page), 63 to 17 (blog) and 89 to 17 (404 page).

## Deutsch

### Dokumente dieses Audits

| Dokument | Inhalt |
|---|---|
| [PRUEFKATALOG.md](PRUEFKATALOG.md) | Prüfkatalog mit Quellen aus dem Internet, Abdeckung je Prüfpunkt, Methodik |
| [SICHERHEIT.md](SICHERHEIT.md) | Sicherheitsbefunde SEC-16 bis SEC-27 |
| [PERFORMANCE-LAST.md](PERFORMANCE-LAST.md) | Geschwindigkeit (PERF-07 bis PERF-11) und Massenanfragen/Bot-Last (LAST-01 bis LAST-06) inkl. Messwerten |
| [FUNKTIONEN-VERWEISE.md](FUNKTIONEN-VERWEISE.md) | Fehlende oder unvollständige Funktionen (FUN-21 bis FUN-25) und fehlerhafte Verweise (REF-06 bis REF-11) |
| [GUI-BARRIEREFREIHEIT.md](GUI-BARRIEREFREIHEIT.md) | Fehler in der Oberfläche und Barrierefreiheit (GUI-01 bis GUI-07) |

Die IDs setzen die Nummerierung der Audits vom 2026-10-03 und 2026-10-07 fort (SEC, PERF, FUN, REF). `LAST` (Massenanfragen) und `GUI` sind neu. Offene Empfehlungen tragen `OFFEN-xx`.

### Methodik (Kurzfassung)

1. **Prüfkatalog:** zusammengestellt aus OWASP Top 10:2025 und aktuellen Website-/CMS-Audit-Checklisten ([PRUEFKATALOG.md](PRUEFKATALOG.md)).
2. **Statische Prüfung:** vier parallele Stränge (Sicherheit, Geschwindigkeit/Last, Verweise/Funktionen, GUI).
   - Jeder Treffer wurde am Code nachvollzogen.
   - Nicht bestätigte Treffer wurden verworfen. Beispiel: „`cmsConfirm`/`cmsAlert` nie definiert“ stimmt nicht – beide stehen in `admin/partials/footer.php`.
3. **Laufzeittests:** gegen MySQL 8.0 mit dem PHP-Entwicklungsserver auf einer **Kopie** von `CMS/` (Neuinstallation über `install.php`).
   - Abfragezählung über das MySQL-General-Log je Seitenaufruf.
   - Geprüft wurden u. a. Startseite, Blog, Suche inkl. Rate-Limit, 404 inkl. überlangem Pfad, Feed und `robots.txt`.
   - Im Admin: Dashboard, Log-Ansichten, Kategorien und Tags speichern, Medien, Einstellungen, Kommentare, Info- und Diagnoseseiten.
4. **Syntax:** ohne Fehler bei
   - `php -l` über alle geänderten PHP-Dateien,
   - `node --check` für geänderte Inline-Skripte,
   - YAML-Parse des Workflows.

**Grenzen:**

- Die Prüfumgebung hat PHP 8.3, das Projekt verlangt 8.4. In der Kopie wurden deshalb die Mindestversion auf 8.3 gesetzt und die Plattformprüfung der gebündelten Symfony-Pakete übersprungen.
- Kein Lasttest auf Produktionshardware, keine Produktivdaten, kein externer Penetrationstest.
- Mailversand, LDAP, Passkeys, Zahlungsanbieter und S3-Upload wurden nicht real ausgeführt.

### Gesamtbild

| Bereich | Bewertung | Kurzfazit |
|---|---|---|
| Sicherheit | **gut** (nach Fixes) | Die Grundlagen sind solide (Prepared Statements, CSRF, CSP mit Nonce und Trusted Types, bcrypt). Lücken gab es beim zweiten Faktor, bei der fehlenden Re-Authentifizierung, bei privaten Upload-Pfaden und bei der uneinheitlichen Ermittlung der Client-IP. |
| Geschwindigkeit | **gut** (vorher befriedigend) | Am meisten kosteten Schema-Prüfungen und eine zweite DB-Verbindung in **jedem** Request. Nach den Fixes sind es 15–17 Abfragen je Seite. |
| Massenanfragen / Bots | **befriedigend** (vorher mangelhaft) | Über Suche, 404-Protokoll und Web-Vitals-Endpunkt ließen sich Schreib- und CPU-Last vervielfachen; die Analytik-Tabellen wuchsen unbegrenzt. Jetzt gibt es Rate-Limits, Obergrenzen und eine tägliche Bereinigung. Offen: Für jeden anonymen Besucher wird eine Session gestartet. |
| Funktionsumfang | **befriedigend** | Mehrere Einstellungen und Formularfelder hatten keine Wirkung (Kommentare global, `robots.txt`, Beschreibungen von Kategorien und Tags). Behoben. |
| Verweise | **befriedigend** | Fünf Aufrufe nicht existierender Klassen oder Methoden in Fehlerpfaden führten zu einem Fatal Error statt einer Meldung. Behoben. |
| GUI | **gut** | Vier Admin-Ansichten hatten Inline-Skripte ohne CSP-Nonce, deren Funktionen der Browser blockierte. Außerdem fehlten Bestätigungen vor destruktiven Aktionen, und es gab ASCII-Umlaute. Behoben. |

### Messwerte (Abfragen je Seitenaufruf, MySQL-General-Log)

| Seite | vorher | nachher |
|---|---:|---:|
| Startseite `/` | 61 | 15 |
| Blog `/blog` | 63 | 17 |
| 404-Seite | 89 | 17 |

Details und Aufschlüsselung: [PERFORMANCE-LAST.md](PERFORMANCE-LAST.md).

### Befundübersicht und Status

Schweregrad: 🔴 hoch · 🟠 mittel · 🟡 niedrig · ⚪ Hinweis

| ID | Bereich | Befund | Schwere | Status |
|---|---|---|---|---|
| SEC-16 | Sicherheit | Zweiter Faktor (TOTP/Backup-Code) ohne Versuchslimit und ohne Ablauf; TOTP-Codes wiederverwendbar | 🔴 | ✅ 3.4.25 |
| SEC-17 | Sicherheit | Neuinstallationen ohne `UNIQUE(user_id, meta_key)` auf `user_meta`: MFA- und Profilwerte werden dupliziert, veraltete Secrets gelesen | 🟠 | ✅ 3.4.25 |
| SEC-18 | Sicherheit | MFA abschalten, Backup-Codes erneuern, Passkey registrieren und E-Mail ändern ohne aktuelles Passwort | 🟠 | ✅ 3.4.25 |
| SEC-19 | Sicherheit | Private Uploads (`uploads/member`, `uploads/import`) und Importer-Berichte per URL abrufbar | 🟠 | ✅ 3.4.25 |
| SEC-20 | Sicherheit | Installer setzt `debug_mode` standardmäßig auf aktiv | 🟡 | ✅ 3.4.25 |
| SEC-21 | Sicherheit | Nachrichten: Antwort in fremde Threads möglich, kein Sendelimit (Spam) | 🟠 | ✅ 3.4.25 |
| SEC-22 | Sicherheit | `REMOTE_ADDR` statt `Security::getClientIp()` in fünf Diensten | 🟡 | ✅ 3.4.25 |
| SEC-23 | Sicherheit | Öffentlicher Checkout akzeptiert `billing=lifetime` | 🟡 | ✅ 3.4.25 |
| SEC-24 | Sicherheit | CSV-Export der Seitentabellen ohne Formel-Schutz | 🟡 | ✅ 3.4.25 |
| SEC-25 | Sicherheit | Admin-`cmsAlert()` setzt den Meldungstext per `innerHTML` | 🟡 | ✅ 3.4.25 |
| SEC-26 | Sicherheit | CI-Actions nicht auf Commit-SHA fixiert (`trivy-action@master`) | 🟡 | ✅ 3.4.25 |
| SEC-27 | Sicherheit | Seiten-Suche liefert Seiten mit `published_at` in der Zukunft | 🟡 | ✅ 3.4.25 |
| PERF-07 | Performance | Laufzeit-Schemaprüfungen in jedem Request | 🔴 | ✅ 3.4.25 |
| PERF-08 | Performance | Zweite PDO-Verbindung je Request für die Session-GC-Lebensdauer | 🟠 | ✅ 3.4.25 |
| PERF-09 | Performance | Einzelabfragen auf `settings` (Router-Cache-TTL, Firewall-Einstellungen) | 🟡 | ✅ 3.4.25 |
| PERF-10 | Performance | Theme-Kategorien und Hub-Tabellen mehrfach je Request geladen | 🟡 | ✅ 3.4.25 |
| PERF-11 | Performance | Seiten-Suche mit `SELECT *` | ⚪ | ✅ 3.4.25 |
| LAST-01 | Last | Firewall schreibt je blockierter Anfrage zwei Zeilen; Zählung ohne Index; Bereinigung in einem einzigen DELETE | 🟠 | ✅ 3.4.25 |
| LAST-02 | Last | Öffentliche Suche ohne Rate-Limit (LIKE über LONGTEXT) | 🟠 | ✅ 3.4.25 |
| LAST-03 | Last | Web-Vitals-Endpunkt: Race Condition im Datei-Limiter, Limit per User-Agent-Wechsel umgehbar, fail-open bei voller Datei | 🟡 | ✅ 3.4.25 |
| LAST-04 | Last | 404-Monitor ohne Obergrenze und Pfadlänge (Zufalls-URLs füllen die Tabelle) | 🟠 | ✅ 3.4.25 |
| LAST-05 | Last | Keine Aufbewahrungsfrist für `page_views`, `core_web_vitals`, `not_found_logs` und Tageslogs | 🟠 | ✅ 3.4.25 |
| LAST-06 | Last | Medienauslieferung hält die Session-Sperre während des Streamings; parallele Requests desselben Nutzers warten | 🟡 | ✅ 3.4.25 |
| FUN-21 | Funktion | Einstellung „Kommentare aktivieren“ wird nie ausgewertet | 🟠 | ✅ 3.4.25 |
| FUN-22 | Funktion | `robots.txt`-Inhalt aus *Einstellungen → Allgemein* wird nicht verwendet | 🟡 | ✅ 3.4.25 |
| FUN-23 | Funktion | Kategorie-Beschreibung wird nicht gespeichert; Felder „Farbe“ und „Sofort veröffentlichen“ ohne Wirkung | 🟡 | ✅ 3.4.25 |
| FUN-24 | Funktion | Tag-Beschreibung wird nicht gespeichert; „Sofort veröffentlichen“ ohne Wirkung | 🟡 | ✅ 3.4.25 |
| FUN-25 | Funktion | Importer-Link „Bericht herunterladen“ ohne Handler | 🟡 | ✅ 3.4.25 |
| REF-06 | Verweis | `\WP_Error` im Namespace-Code von `MediaModule` | 🟠 | ✅ 3.4.25 |
| REF-07 | Verweis | `use WP_Error;` in `StatusService` | 🟠 | ✅ 3.4.25 |
| REF-08 | Verweis | `SystemInfoModule` ruft nicht vorhandenes `formatBytesLabel()` auf | 🟠 | ✅ 3.4.25 |
| REF-09 | Verweis | `MailSettingsActionResult::failure()` existiert nicht | 🟡 | ✅ 3.4.25 |
| REF-10 | Verweis | `BackupService` ruft nicht vorhandenes `uploadToS3WithSDK()` auf | 🟠 | ✅ 3.4.25 |
| REF-11 | Verweis | Installer übergeht `MigrationManager`, deshalb fehlen bei Neuinstallation Schlüssel, die nur per Migration angelegt werden | 🟡 | ✅ 3.4.25 (siehe SEC-17) |
| GUI-01 | GUI | Inline-Skripte in vier Log-Ansichten ohne CSP-Nonce, Filter und Umschalter blockiert | 🟠 | ✅ 3.4.25 |
| GUI-02 | GUI | Destruktive Admin-Aktionen ohne Bestätigung | 🟡 | ✅ 3.4.25 |
| GUI-03 | GUI | Destruktive Aktionen im Mitgliederbereich ohne Bestätigung | 🟡 | ✅ 3.4.25 |
| GUI-04 | GUI | ASCII-Umlaute und Entwicklertexte im Dashboard | ⚪ | ✅ 3.4.25 |
| GUI-05 | GUI | Bestätigungsdialog ohne Dialog-Semantik | 🟡 | ✅ 3.4.25 |
| GUI-06 | GUI | Such-Rate-Limit ohne sichtbaren Hinweis | ⚪ | ✅ 3.4.25 |
| GUI-07 | GUI | Formulare verlieren die Beschreibung nach einem Validierungsfehler | ⚪ | ✅ 3.4.25 |
| OFFEN-01 … OFFEN-13 | diverse | Empfehlungen ohne Codeänderung | – | 📋 offen, siehe unten |

### Offene Empfehlungen

| ID | Bereich | Empfehlung |
|---|---|---|
| OFFEN-01 | Last | Für **jeden** anonymen Besucher wird eine Session gestartet. Das kostet eine Session-Datei und ein `Set-Cookie` je Besucher und verhindert Full-Page-Caching durch CDN oder Reverse Proxy. Sessions erst bei Login, Formular oder Warenkorb starten. |
| OFFEN-02 | Last | Die Suche lädt bis zu 400 Kandidaten mit `alias.*` (inkl. LONGTEXT) und filtert in PHP. Empfehlung: FULLTEXT-Index und Projektion auf die benötigten Spalten. |
| OFFEN-03 | Last | Der RSS-Feed wird bei jedem Abruf neu erzeugt. Ergebnis kurz cachen (z. B. 5 Minuten) und beim Speichern verwerfen. |
| OFFEN-04 | Last | `ErrorDocument 404 /index.php` startet für jede fehlende Datei das CMS. Bei sehr hohem Bot-Aufkommen statische 404 für Assets auf Webserver-Ebene ausliefern. |
| OFFEN-05 | Sicherheit | Die Firewall-Regel `block_country` liest Länderheader (`CF-IPCountry` u. a.), die ohne vorgeschaltetes CDN frei setzbar sind. Nur hinter Cloudflare o. Ä. aktivieren. |
| OFFEN-06 | Sicherheit | Bei einer E-Mail-Änderung zusätzlich die **alte** Adresse benachrichtigen. |
| OFFEN-07 | Funktion | Diese Optionen werden gelesen, lassen sich aber nirgends einstellen: `seo_twitter_site` (`SeoSchemaRenderer`), `seo_custom_header_code` (`SeoSettingsStore`), `cookie_primary_color` (`CookieConsentService`). Feld ergänzen oder Lesezugriff entfernen. |
| OFFEN-08 | Verweis | Tote private Methoden: `MediaService::purgeMetaForPath()` (die Logik lebt in `MediaRepository`) und `LandingPageService::upgradeLegacyFooterDefaults()` (die Logik lebt in `LandingSectionProfileService`). |
| OFFEN-09 | Verweis | Ältere Changelog-Einträge nennen Pfade als `CMS/DOC/…`; korrekt ist `DOC/…`. |
| OFFEN-10 | GUI | Rund 380 `<label>` haben kein `for=`. Ein Teil umschließt das Feld und ist damit gültig. Die übrigen mit axe-core prüfen und verknüpfen. |
| OFFEN-11 | GUI | Dashboard-Links auf `/admin/posts` sind fest verdrahtet statt über `SITE_URL` gebildet (Problem bei Installation im Unterverzeichnis). |
| OFFEN-12 | GUI | Für `data-autohide` und `data-copy-input-target` gibt es JS-Handler, aber kein Markup – entfernen oder nutzen. |
| OFFEN-13 | Datenschutz | `activity_log` und `audit_log` werden bewusst **nicht** automatisch gelöscht (Nachweispflicht). Die Frist im Verarbeitungsverzeichnis festlegen. |

### Hinweise für Betreiber

- **Update:**
  - Beim ersten Request nach dem Update laufen die Schema-Prüfungen einmal.
  - Dabei werden doppelte `user_meta`-Zeilen bereinigt (der jüngste Wert bleibt) und `UNIQUE(user_id, meta_key)` angelegt.
  - Danach laufen die Prüfungen erst wieder, wenn sich Schema-Dateien ändern. Das Flag liegt unter `CMS/cache/db_runtime_*.flag`; wer es löscht, erzwingt eine erneute Prüfung.
- **Zwei-Faktor:** Nach 5 falschen Codes muss das Passwort erneut eingegeben werden; zusätzlich gilt ein Limit von 20 Fehlversuchen je IP in 15 Minuten. Eine ausstehende Prüfung verfällt nach 10 Minuten.
- **Re-Authentifizierung:** MFA abschalten, Backup-Codes erneuern, Passkey hinzufügen und E-Mail ändern verlangen das aktuelle Passwort (10 Fehlversuche je IP in 15 Minuten).
- **Aufbewahrung:** Ein täglicher Cron löscht
  - `page_views` nach 365 Tagen,
  - `core_web_vitals` und `not_found_logs` nach 90 Tagen,
  - Tageslogdateien nach 30 Tagen.

  Die Fristen lassen sich über den Filter `cms_data_retention_days` anpassen (Wert `0` = nicht löschen; sonst gelten mindestens 7 und höchstens 3650 Tage).
- **Reverse Proxy:** Die Rate-Limits (Suche, Web-Vitals, MFA, Re-Auth, Nachrichten) zählen je Client-IP. Hinter Proxy oder CDN `CMS_TRUSTED_PROXIES` setzen, sonst teilen sich alle Besucher ein Limit.
- **nginx:** Die `.htaccess`-Regeln greifen hier nicht. Die Sperren für `uploads/member`, `uploads/import` und die Importer-Verzeichnisse stehen in [../INSTALLATION.md](../INSTALLATION.md).
- **Checkout:** Lifetime-Tarife werden nur noch im Admin vergeben.

### Geänderte Dateien (Auswahl)

**Kern:**

- `core/Auth.php`, `core/Auth/AuthManager.php`
- `core/Database.php`, `core/SchemaManager.php`, `core/Bootstrap.php`, `core/Router.php`, `core/PageManager.php`
- `core/Routing/{ApiRouter,PublicRouter,ThemeRouter}.php`
- `core/Services/{DataRetentionService (neu),RedirectService,SecurityRuntimeService,MessageService,CommentService,CoreWebVitalsService,TrackingService,UserService,MediaDeliveryService,StatusService,BackupService}.php`
- `core/Services/SEO/{SeoMetaRepository,SeoSitemapService}.php`
- `core/Services/SiteTable/{SiteTableRepository,SiteTableTableRenderer}.php`
- `index.php`, `.htaccess`, `orders.php`, `install/InstallerController.php`

**Admin, Member, Theme, Plugin:**

- `admin/partials/footer.php`
- `admin/views/logs/*.php`
- `admin/views/{dashboard/index,security/audit,seo/sitemap,posts/categories,posts/tags}.php`
- `admin/{post-categories,post-tags}.php`
- `admin/modules/{media/MediaModule,posts/PostsModule,system/SystemInfoModule,system/MailSettingsModule,seo/PerformanceModule}.php`
- `member/{security,profile,privacy}.php`, `member/includes/class-member-controller.php`
- `themes/cms-default/{functions,search,blog-single}.php`
- `plugins/cms-importer/includes/class-admin.php`, `plugins/cms-importer/reports/.htaccess` (neu)

**Sonstiges:**

- `.github/workflows/security.yml`
- `.gitignore` (`/DOC/audit/` wird nicht mehr ausgeschlossen)
- `DOC/INSTALLATION.md`
