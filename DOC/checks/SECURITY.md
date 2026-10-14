# 365CMS – Projektdokumentation | Abschnitt: Audit 2026-10-03 – Sicherheit

> **Stand:** 2026-10-03 | **Version:** 3.5.0 | Übersicht: [README.md](README.md)

## English (summary)

Security review of the first-party code in `CMS/`. The baseline is solid (prepared statements everywhere, consistent output escaping, CSRF tokens, bcrypt, session regeneration, SSRF protection with IP pinning, hardened `.htaccess`, locked installer). Fixed in this audit: plain-text config backups readable on servers without `.htaccess` support, double-extension uploads, missing registration rate limit, client IP behind reverse proxies, password-breaking config parser, outdated PHP check. Fixed in 3.5.0: DB rate limits that never counted when the MySQL time zone differed from PHP (found by runtime test), a git allowlist bypass in the documentation sync, origin/CSRF checks for plugin member sections, contact-form rate limit, Google Fonts off by default, plus opt-in JWT bearer auth with MFA-safe token issuance.

## Deutsch

### Geprüft und unauffällig

| Prüfpunkt | Ergebnis | Fundstellen |
|---|---|---|
| SQL-Injection | Alle Abfragen mit Benutzerdaten nutzen Prepared Statements (`ATTR_EMULATE_PREPARES = false`). Interpolierte Werte sind ausschließlich Tabellenpräfixe, Platzhalterlisten oder zuvor mit `(int)` + `min/max` begrenzte `LIMIT`/`OFFSET`-Werte. `LandingRepository::getSectionsByType()` interpoliert `ORDER BY`, wird aber nur mit dem Standardwert aufgerufen. | `CMS/core/Database.php`, `AuditLogger`, `ErrorReportService`, `ThemeRouter`, `ApiRouter` |
| Gefährliche Funktionen | Einziges `exec()` in `DocumentationSyncEnvironment::runCommand()` mit Allowlist und Sanitizing. `unserialize()` nur mit `allowed_classes => false`. | `CMS/admin/modules/system/DocumentationSyncEnvironment.php`, `includes/functions/escaping.php` |
| XSS | Views und Themes escapen konsequent (`htmlspecialchars`, `$escape`-Closures); stichprobenartig geprüfte Rohausgaben sind Integer oder vorab escaped. Suchfeld `?q` im Theme escaped. | `CMS/admin/views/**`, `CMS/themes/cms-default/**` |
| CSRF | Globaler `form_guard` im Router für öffentliche POSTs; Admin-Module über `section-page-shell` bzw. eigene `verifyToken`; Member-Controller über `verifyCsrf`; Auth-Routen prüfen eigene Tokens. | `CMS/core/Router.php`, `CMS/member/includes/class-member-controller.php` |
| Offene Redirects | `?redirect=` wird auf `/admin`, `/member`, `/dashboard` mit gleichem Origin beschränkt; `//host` wird verworfen. | `PublicRouter::resolveAllowedRedirectParts()` |
| Passwörter / Sessions | `password_hash` (bcrypt, cost 12), `session_regenerate_id(true)` beim Login, Cookies `HttpOnly`, `Secure` (bei HTTPS), `SameSite=Strict`. Login und Passwort-Reset mit DB-Rate-Limit. | `CMS/core/Security.php`, `CMS/core/Auth.php`, `CmsAuthPageService` |
| SSRF | Zentraler HTTP-Client blockiert private/reservierte Ziele, pinnt die aufgelöste IP (`CURLOPT_RESOLVE`), keine Redirect-Verfolgung, nur HTTP(S). `allowPrivateHosts` nur für Self-Monitoring im Admin. | `CMS/core/Http/Client.php` |
| Uploads | Whitelist je Typgruppe, MIME-Prüfung, SVG gesperrt, `.htaccess` blockiert ausführbare Dateien unter `uploads/`, Pfad-Sanitizing ohne `..`/versteckte Segmente, Member auf `member/user-<id>` beschränkt. | `MediaService`, `FileUploadService`, `CMS/.htaccess` |
| Installer | Nach Installation per `config/install.lock` gesperrt; Zugriff nur für eingeloggte Admins; CSRF im Formular. | `CMS/install/InstallerController.php` |
| Webserver-Härtung | `.htaccess` sperrt `config/`, `core/`, `logs/`, `backups/`, `cache/`, `vendor/`, `.git`, Backup-Endungen und PHP unter `assets/`/`uploads/`. | `CMS/.htaccess` |

### Behobene Befunde

#### SEC-01 🔴 Klartext-Backups der Konfiguration

`SettingsModule::updateConfigFile()` und `InstallerService` legten vor jedem Schreiben `config/app.php.backup.<Datum>` an. Unter Apache schützt `config/.htaccess`; unter nginx oder bei deaktiviertem `AllowOverride` wird die Datei wegen der Endung `.backup.*` **nicht** als PHP ausgeführt, sondern als Text mit `DB_PASS`, `AUTH_KEY` usw. ausgeliefert.

**Fix:** Backups heißen jetzt `config/app.backup-<Datum>.php`. Ein direkter Abruf führt die Datei aus (nur `define()`-Aufrufe, keine Ausgabe).
**Hinweis für Betreiber:** Vorhandene `config/app.php.backup.*` manuell löschen oder umbenennen.

#### SEC-02 🟠 Upload-Doppelendungen und unvollständige Sperrliste

`validateUploadFile()` prüfte nur die letzte Endung. Bei Apache-Setups mit `AddHandler application/x-httpd-php .php` wird `bild.php.jpg` als PHP ausgeführt. Außerdem fehlten `php7`, `php8`, `pht`, `phps`, `phpt`, `shtml`, `htaccess`, `htpasswd`, `py`, `jsp`.

**Fix:** Sperrliste ergänzt; jede innere Endung wird gegen die Sperrliste geprüft (`CMS/core/Services/MediaService.php`).

#### SEC-03 🟠 Registrierung ohne Rate-Limit

`PublicRouter::handleRegister()` prüfte CSRF, aber keine Frequenz. Bot-Registrierungen waren unbegrenzt möglich (Mail-Versand, DB-Wachstum).

**Fix:** `Security::checkDbRateLimit($ip, 'register', 5, 900)` + `recordDbRateLimitAttempt()` – höchstens 5 Versuche pro IP in 15 Minuten.

#### SEC-04 🟠 Client-IP hinter Reverse Proxy

`Security::getClientIp()` nutzte ausschließlich `REMOTE_ADDR`. Die mitgelieferte `.htaccess` empfiehlt HTTPS-Terminierung am Proxy (`CMS_HTTPS_REDIRECT_STRATEGY = upstream`); dort sehen Login-Rate-Limit, Audit-Log, Firewall und Kommentar-Flood-Schutz nur die Proxy-IP. Fünf Fehlversuche eines Angreifers sperren dann den Login für alle.

**Fix:** Opt-in-Konstante `CMS_TRUSTED_PROXIES` (Komma-Liste, IPv4/IPv6, CIDR). Nur wenn `REMOTE_ADDR` darin liegt, wird `X-Forwarded-For` von rechts gelesen und die erste nicht vertrauenswürdige Adresse verwendet. Ohne Konstante ändert sich nichts.

```php
// config/app.php
define('CMS_TRUSTED_PROXIES', '10.0.0.0/8, 192.168.1.5');
```

#### SEC-05 🟡 PHP-Versionsprüfung im Sicherheits-Audit

`SecurityAuditModule` meldete PHP ≥ 8.2 als „ok“. Mindestversion ist `CMS_MIN_PHP_VERSION = 8.4.0`. **Fix:** Bewertung nutzt jetzt `CMS_MIN_PHP_VERSION` (ok ab 8.4, Warnung 8.2–8.3, kritisch darunter).

#### SEC-06 🟠 Config-Parser zerstört Passwörter mit `'` oder `\`

`parseExistingConfig()` las Werte mit `'([^']+)'`. Ein DB-Passwort wie `ab\'c` wurde abgeschnitten und beim Speichern der Einstellungen falsch zurückgeschrieben – die Seite verlor danach die DB-Verbindung. **Fix:** Escape-bewusste Regex plus Rückwandlung vor dem erneuten Escapen.

### Mit 3.5.0 behoben

#### SEC-07 🟠 Plugin-Member-Bereiche ohne zentrale CSRF-Prüfung

Der Router nimmt `/member/*` von der globalen CSRF-Prüfung aus; `CMS/member/plugin-section.php` rief bei POST den `post_callback` eines Plugins ohne Prüfung auf.
**Fix (abwärtskompatibel):** `PluginDashboardRegistry::handleRoute()` prüft jeden POST vor dem Rendern (auch bei Theme-Overrides):
- immer: `Origin` bzw. `Referer` müssen – falls gesendet – zur eigenen Site gehören, sonst 403,
- mit `'csrf' => 'core'` in der Registrierung zusätzlich das Token `member_plugin_<slug>` (`csrfField()`/`csrfToken()`, Feld `csrf_token` oder Header `X-CSRF-Token`).

Bestehende Plugins mit eigener Token-Prüfung laufen unverändert weiter. Zusammen mit den `SameSite=Strict`-Session-Cookies ist Cross-Site-POST damit doppelt abgesichert.

#### SEC-08 🟡 Kontaktformular ohne Rate-Limit

**Fix:** `CMS/themes/cms-default/contact.php` sendet höchstens 5 Nachrichten je IP und Stunde (`checkDbRateLimit(…, 'contact_form', 5, 3600)`); gezählt wird nur ein tatsächlicher Versandversuch.

#### SEC-09 🟡 Google Fonts standardmäßig extern

**Fix:** Die Theme-Option `typography.google_fonts` ist in `theme.json` und im Customizer standardmäßig **aus**; der Fallback in `shouldLoadExternalFonts()` liefert ebenfalls `false`. Ohne lokale Schriften greifen die System-Fallbacks (`Georgia`, `system-ui`). Bereits gespeicherte Customizer-Werte bleiben erhalten.

#### SEC-10 🔴 DB-Rate-Limits wirkungslos bei abweichender MySQL-Zeitzone (Laufzeittest)

`Security::recordDbRateLimitAttempt()` schreibt `attempted_at` mit `NOW()` (Zeitzone des MySQL-Servers), `checkDbRateLimit()` vergleicht mit `date('Y-m-d H:i:s', time() - $fenster)` aus PHP (`Europe/Berlin`, gesetzt in `config/app.php`). Läuft MySQL in UTC – typisch für Cloud-Datenbanken und Container –, liegt das Prüffenster zwei Stunden in der Zukunft und **es wird nie ein Versuch gezählt**. Betroffen: Login-Bruteforce-Schutz, Passwort-Reset, Registrierung, API-, Kontakt- und Token-Limits sowie die Zeitfenster von Firewall (`SecurityRuntimeService`), Sicherheitsalarmen und Mail-Queue-Locks.
Nachweis in der Testumgebung (MariaDB `UTC`): Auf `main` gelang der Login mit korrektem Passwort nach sechs Fehlversuchen weiterhin; nach dem Fix wird er blockiert.
**Fix:** `CMS\Database` setzt nach dem Verbindungsaufbau `SET time_zone = '<PHP-Offset>'` (`date('P')`). `NOW()`/`CURRENT_TIMESTAMP` und alle PHP-Zeitvergleiche nutzen damit dieselbe Zeitbasis. `TIMESTAMP`-Spalten werden korrekt umgerechnet; ältere, per `NOW()` geschriebene `DATETIME`-Werte können um den bisherigen Versatz abweichen.

#### SEC-11 🟡 Git-Allowlist im Doku-Sync übersprungen

`DocumentationSyncEnvironment::isAllowedCommand()` gab bei jedem Befehl mit Shell-Metazeichen sofort zurück, ob er mit `2>&1` endet – da alle internen Befehle so enden, wurde die Liste erlaubter Git-Unterbefehle (`fetch`, `checkout`, `status`, `rev-parse`, `--version`) nie geprüft. Die Befehle entstehen nur intern mit validierten Parametern, daher kein direkter Angriffspfad. **Fix:** abschließendes `2>&1` wird abgetrennt, danach sind keine Metazeichen erlaubt und die Allowlist gilt immer (Test: `push` und `status; rm …` werden abgelehnt).

### Weitere Härtung in 3.5.0

- **API-Bearer-Anmeldung (FUN-08)** nur opt-in mit `JWT_SECRET` ≥ 32 Zeichen; Token nur für bestehende Sessions (verhindert MFA-Umgehung durch Passwort-Login per API), Rate-Limits auf Token- und Refresh-Route, Refresh-Tokens nicht als Access-Token verwendbar.
- **WP-Asset-Funktionen (FUN-10)** verwerfen `javascript:`/`data:`-URLs, escapen alle Attribute, setzen den CSP-Nonce und lassen bei `wp_localize_script` nur gültige JS-Bezeichner zu (JSON mit `JSON_HEX_*`).
- **Doku-Sync (FUN-09)** nur mit `manage_system`, CSRF und Bestätigungsdialog; ZIP-Modus nur mit freigegebenem SHA-256-Bundle.

### Verwandte Dokumente

[README.md](README.md) · [../core/SECURITY.md](../core/SECURITY.md) · [../admin/security/SECURITY-AUDIT.md](../admin/security/SECURITY-AUDIT.md)
