# 365CMS – Projektdokumentation | Abschnitt: Folgeaudit 2026-11-08

> **Stand:** 2026-11-08 | **Version:** 4.0.0 (Basis 3.5.6) | **Prüfumfang:** `CMS/` inkl. Theme `cms-default` und Plugin `cms-importer`, gebündelte Bibliotheken unter `CMS/assets/` (Versionsabgleich gegen bekannte Sicherheitslücken) | Übersicht: [README.md](README.md)

## English (summary)

Security and quality audit of 365CMS 3.5.6, released as 4.0.0. The audit covered security (known exploits and vulnerable bundled libraries), bugs, missing functions or values, speed and best practice. 24 findings were recorded and all are fixed in 4.0.0. Six recommendations (OFFEN-20 … OFFEN-25) remain open because they need a migration or a product decision.

Most important fixes:

- **Host header / cache poisoning:** `cms_runtime_host()` trusted `X-Forwarded-Host` and `Host` from anyone. The value ended up in script and asset URLs of cacheable pages, including nonce-carrying `<script src>`. The host now has to belong to the installation (SITE_URL host, its `www` variant, or a hub domain), and forwarded headers count only behind trusted proxies.
- **MFA:** a hijacked session could enrol its own authenticator or replace the active one without the password. Setting up TOTP now needs the current password, and an active authenticator is never replaced silently.
- **Privacy export:** the GDPR export contained MFA secrets and LDAP data. These are now excluded.
- **Installer:** `config/install.lock` was ignored when `config/app.php` could not be parsed (for example, credentials from environment variables).
- **Importer:** remote SVG files were stored in `uploads/` (stored XSS). Only raster images are accepted now.
- **Plugin dashboards:** unknown capability strings granted access (fail-open). They are now denied.
- **E-mail verification:** the option `member_email_verification` had no effect. New registrations now stay `unverified` until the link in the confirmation mail is opened (valid for 48 hours, single use). A completed password reset also confirms the address.
- **Admin user creation:** creating a user under `/admin/users` failed on MySQL/MariaDB in strict mode (the default), because `display_name` was never set.
- **Update notices:** the `critical` flag in the core update manifest was ignored, so security releases looked like routine updates. *System → Updates* now shows critical releases as a red alert.
- **Libraries:** DOMPurify 3.4.17, symfony/mime 8.1.10, symfony/yaml 8.1.11, symfony/serializer 8.1.11.

Runtime and CLI tests on PHP 8.4 and MySQL 8.4 confirmed every code fix and the updated libraries. Pages render in 10–30 ms, and all 102 admin pages load without PHP errors.

## Deutsch

### Methodik

- **Statische Prüfung in sechs parallelen Strängen:**
  - Authentifizierung, MFA, Passkeys, Sitzungen, API/JWT
  - Mitgliederbereich und Datenschutz
  - Admin-Bereich (Rechte, CSRF, Weiterleitungen, Uploads)
  - öffentliche Ausgabe (Routing, Templates, SQL, Feeds, Sitemaps)
  - Installer, Updates, Backups, Cron, Plugins
  - Bibliotheken unter `CMS/assets/`
- **Bibliotheken:** Versionen mit der GitHub Advisory Database abgeglichen. Neue Fassungen aus den offiziellen Quellarchiven übernommen (Code identisch mit Upstream); die Quellarchive liegen unter `CMS_ASSETS/`.
- **Werkzeuge:**
  - `php -l` auf PHP 8.4.27 und 8.5.12
  - PHPStan bis Level 5 über `CMS/` (ohne Drittbibliotheken)
- **Verifikation:** Jeder Befund wurde im Code nachvollzogen, behoben und anschließend zur Laufzeit geprüft.
- **Laufzeittest:** Docker mit PHP 8.4 und MySQL 8.4, Neuinstallation über `install.php` auf einer Kopie von `CMS/`.
- **Grenzen:**
  - kein Lasttest unter Produktionsbedingungen
  - kein echter Mail-, LDAP- oder Zahlungsanbieter
  - Passkeys mit synthetischen P-256-Assertions geprüft

Schwere: 🔴 hoch · 🟠 mittel · 🟡 niedrig · ⚪ Hinweis. Die Schwere bewertet die Auswirkung auf 365CMS. Bei Bibliotheken steht die Einstufung des Herstellers im Befund.

Die IDs setzen die Nummerierung der Audits vom 2026-10-14 fort (bis SEC-31, FUN-34, BP-05, OFFEN-19). `DEP` (Bibliotheken) ist neu.

### Befunde und Status

#### Sicherheit

| ID | Befund | Schwere | Status |
|---|---|---|---|
| SEC-32 | TOTP-Einrichtung ohne Passwort: Im Mitgliederbereich (`totp_start`/`totp_confirm`) und unter `/mfa-setup` konnte eine übernommene Sitzung einen eigenen Authenticator hinterlegen und einen aktiven ersetzen (dauerhafter Zugriff, Aussperren des Inhabers) | 🟠 | ✅ 4.0.0 – aktuelles Passwort erforderlich (`Auth::verifyCurrentPassword()` mit Rate-Limit `reauth`); aktive MFA wird nie ersetzt (`TotpAdapter::confirmSetup()`, `Auth::confirmMfaSetup()`); bestätigt wird nur das in derselben Sitzung gestartete Secret |
| SEC-33 | DSGVO-Datenexport (`privacy_export`) enthielt alle `user_meta`, darunter `mfa_secret`, `mfa_pending_secret`, Backup-Codes und `ldap_dn` | 🟠 | ✅ 4.0.0 – `MemberService::filterExportableUserMeta()` nimmt Zugangsgeheimnisse aus (MFA, 2FA, LDAP, Passkeys, Token, Secrets, Passwort-/Hash-Werte, API-Keys) |
| SEC-34 | Installer-Sperre `config/install.lock` griff nicht, wenn der Installer `config/app.php` nicht lesen konnte (z. B. Zugangsdaten per `getenv()`); `install.php` war dann erneut erreichbar | 🟠 | ✅ 4.0.0 – Sperrdatei oder angepasste `config/app.php` (ohne Platzhalter `YOUR_DATABASE_`) sperren den Installer (`InstallerController::guardInstalledInstaller()`, `InstallerService::hasCustomizedConfig()`); gelöschte Sperrdatei wird bei lesbarer Konfiguration neu geschrieben |
| SEC-35 | Importer übernahm SVG-Dateien und beliebige Inhalte hinter Bild-URLs nach `uploads/` (gespeichertes XSS über SVG im eigenen Origin) | 🟠 | ✅ 4.0.0 – nur Rasterbilder (JPEG, PNG, GIF, WebP, AVIF, BMP) nach Inhaltsprüfung mit `getimagesizefromstring()`; `.svg` gilt nicht mehr als Bild-URL |
| SEC-36 | `/member/subscription` zeigte Gastbestellungen allein anhand der (nicht bestätigten) Konto-E-Mail-Adresse – mit fremden Rechnungs- und Kontaktdaten | 🟡 | ✅ 4.0.0 – nur Bestellungen mit `user_id` des Kontos |
| SEC-37 | Nachrichten-Empfänger per `username OR display_name OR email … LIMIT 1`: ein Konto mit fremdem Benutzernamen als Anzeigename konnte Nachrichten erhalten, die für andere bestimmt waren | 🟡 | ✅ 4.0.0 – Reihenfolge Benutzername → E-Mail → Anzeigename; Anzeigename nur, wenn ihn genau ein aktives Konto trägt |
| SEC-38 | `cms_runtime_host()`/`cms_runtime_scheme()` übernahmen `X-Forwarded-Host`, `Host` und `X-Forwarded-Proto` ungeprüft. Die Werte landen in Script-/Asset-URLs (auch nonce-tragende `<script src>`) öffentlich cachebarer Seiten → Cache-Poisoning mit Skriptausführung bei anderen Besuchern | 🟠 | ✅ 4.0.0 – Host-Allowlist (Host aus `SITE_URL`, dessen `www`-Variante, Hub-Domains über `SiteTableService::isHubDomain()`); `X-Forwarded-Host` und Herabstufung auf `http` nur über vertrauenswürdige Proxys (`TrustedProxyService`); sonst gilt der Host aus `SITE_URL` |
| SEC-39 | AI-Pipelines (SEO-Metadaten, Inhaltsentwürfe) entfernten Tags vor dem Dekodieren der Entities; aus `&lt;script&gt;` entstanden echte Tags | 🟡 | ✅ 4.0.0 – Tags auch nach dem Dekodieren entfernt |
| SEC-40 | `PluginDashboardRegistry::checkCapability()` gab unbekannte Capabilities pauschal frei (fail-open): Plugin-Bereiche mit z. B. `editor` waren für jedes Mitglied erreichbar | 🟠 | ✅ 4.0.0 – Prüfung über Rolle (`Auth::hasRole()`) oder Capability; unbekannte Werte werden verweigert |
| SEC-41 | Anmeldung: unbekannter Benutzername antwortete schneller als falsches Passwort (kein bcrypt) → Benutzer-Enumeration per Laufzeit | 🟡 | ✅ 4.0.0 – Prüfung gegen festen Dummy-Hash; gemessen 0,23 s (unbekannt) zu 0,22 s (falsches Passwort) |
| SEC-42 | Passkey-Anmeldung ohne User Verification (Flag UV, keine PIN/Biometrie) übersprang aktive TOTP-MFA | 🟡 | ✅ 4.0.0 – ohne UV zusätzlich `/mfa-challenge`; mit UV unverändert direkte Anmeldung |
| SEC-43 | Cron-Loopback (*Diagnose → Cron-Job-Status*) schrieb die URL samt Cron-Token ins Audit-Log | ⚪ | ✅ 4.0.0 – Token als `***` protokolliert |
| SEC-44 | Admins konnten das eigene Konto im Bearbeiten-Formular herabstufen, deaktivieren oder sperren (die Bulk-Aktion war bereits geschützt) | ⚪ | ✅ 4.0.0 – Formular lehnt das ab |
| SEC-45 | Routenpfade mit Backslash (`/\evil.tld`) in der Admin-Section-Shell wurden zu externen Weiterleitungen (Browser lesen `\` als `/`) | ⚪ | ✅ 4.0.0 – Backslashes normalisiert |

#### Bibliotheken

| ID | Befund | Schwere | Status |
|---|---|---|---|
| DEP-01 | `symfony/mime` 8.1.7 ohne die Korrektur zu CVE-2026-45070 (GHSA-vqc8-7275-q272, Herstellerangabe „mittel“): Header-Injection über Parameternamen; dazu fehlendes Escaping von `"` und `\` in Parameterwerten | 🟡 | ✅ 4.0.0 – 8.1.10. In 365CMS setzen nur Codepfade Parameter und Anhangnamen; vorsorglich aktualisiert |
| DEP-02 | `symfony/yaml` 8.1.8: Upstream-Härtung gegen hohen Rechen- und Speicheraufwand beim Parsen (verschachtelte Blöcke, PCRE-Backtracking) und Parser-Korrekturen; keine CVE für 8.1.8 | ⚪ | ✅ 4.0.0 – 8.1.11. Geparst werden nur die mitgelieferten Sprachkataloge |
| DEP-03 | `symfony/serializer` 8.1.8: `DataUriNormalizer` öffnete `data:`-URIs mit großgeschriebenem Schema als lokalen Pfad; `CsvEncoder` ohne Formel-Escaping in der Kopfzeile; keine CVE veröffentlicht | ⚪ | ✅ 4.0.0 – 8.1.11. Nur transitiv über die AI-Plattform genutzt |
| DEP-04 | DOMPurify 3.4.16: GHSA-cg2g-xh64-v4q7 (Herstellerangabe „kritisch“, `SAFE_FOR_TEMPLATES` mit DOM-Ausgabe), GHSA-mrv2-3pqr-j5jr („hoch“, `IN_PLACE`-Hooks), GHSA-c6gr-qvv4-5v3f („niedrig“, XHTML-Parser) | 🟡 | ✅ 4.0.0 – 3.4.17. Die Trusted-Types-Policy (`cms-csp-runtime.js`) nutzt keinen der betroffenen Modi; vorsorglich aktualisiert |

#### Funktionen, Fehler und Best Practice

| ID | Befund | Schwere | Status |
|---|---|---|---|
| FUN-35 | `Router::resolveRequestUri()` entfernte das Installationsverzeichnis per `str_replace()` an jeder Stelle des Pfads. Betrifft nur ein manuell gesetztes `SITE_URL_PATH` (der Installer schreibt `/`): bei `/cms` wurde `/cms/blog/cms-tipps` zu `/blog-tipps` und `/cmsx/seite` zu `/x/seite`; bei `/cms/` blieb `/cms` ungekürzt | 🟡 | ✅ 4.0.0 – nur als Präfix, mit und ohne abschließenden Schrägstrich |
| FUN-36 | Die Option `member_email_verification` (*Benutzer → Einstellungen → E-Mail-Verifizierung erzwingen*) hatte keine Wirkung: keine Bestätigungsmail, kein eigener Status, neue Konten waren sofort aktiv. Auch mit eingeschalteter Option ließen sich Konten mit fremden E-Mail-Adressen anlegen | 🟡 | ✅ 4.0.0 – neue Registrierungen erhalten den Status `unverified` und eine Bestätigungsmail (`/cms-verify-email`, Link 48 h gültig und einmalig, Token nur als SHA-256-Hash gespeichert). Anmeldung erst nach Bestätigung; ein Anmeldeversuch mit richtigem Passwort sendet einen neuen Link (höchstens 3 je Stunde). Ein abgeschlossener Passwort-Reset bestätigt die Adresse ebenfalls. Status in Benutzerliste, Filter und API |
| FUN-37 | `UserService::createUser()` setzte `display_name` nicht (Spalte `NOT NULL` ohne Standardwert). Das Anlegen von Benutzern unter `/admin/users` scheiterte deshalb auf MySQL/MariaDB im Strict-Mode (Standard) mit „Datenbankfehler beim Erstellen“. Ein fehlender Status wurde als `NULL` übernommen (Warnung „Undefined array key“) | 🟠 | ✅ 4.0.0 – Anzeigename aus Vor- und Nachname, sonst Benutzername (max. 100 Zeichen, ohne Tags und Steuerzeichen); Status nur aus der Liste gültiger Werte, sonst `active` |
| FUN-38 | Das Feld `critical` im Core-Update-Manifest (`update.json`) wurde nicht ausgewertet: *System → Updates* zeigte Sicherheitsupdates wie normale Updates, obwohl die Doku „kritisch ja/nein“ beschreibt. Ja/Nein-Felder des Manifests mit Liste statt Wert erzeugten die Warnung „Array to string conversion“ | 🟡 | ✅ 4.0.0 – `UpdateService::checkCoreUpdates()` liefert `critical`; kritische Updates erscheinen als roter Hinweis mit der Bitte um zeitnahe Installation. Listen und Objekte gelten als „nein“ |
| BP-06 | Installer (Datenbank-Update): `catch (PDOException)` stand hinter `catch (\RuntimeException)` und war toter Code; Datenbankfehler erschienen ohne Kennzeichnung | ⚪ | ✅ 4.0.0 – Reihenfolge getauscht |
| BP-07 | PHPStan: falsche Rückgabetypen in PHPDoc (`admin/orders.php`, `admin/site-tables.php`, `admin/packages.php`, `cms-importer`), ungültige `@implements`-Tags an Klassen (`CacheManager`, `Database`, `Logger`), Leerzeile nach dem schließenden `?>` in `admin/views/comments/list.php` | ⚪ | ✅ 4.0.0 |

Kein Befund nach Prüfung:

- SSRF-Schutz in `Http\Client` und allen ausgehenden Abrufen (IndexNow, Google Indexing, Schriften, Doku-Abgleich, Weiterleitungs-Prüfung)
- ZIP-Prüfung bei Backups, Updates und Marketplace; Theme-Editor; Medienverwaltung und private Uploads
- Cron-Token (`hash_equals`), CSRF, Sitzungen, Passwort-Reset, Passkey-Registrierung, JWT/API, Checkout, Mail-Header
- Admin: `AdminRouter` prüft durchgängig `isAdmin`, CSRF in allen Section-Shells, Allow-Lists für Aktionen, Formel-Schutz in CSV-Exporten, AI-Endpunkte nur per POST mit Admin-Recht und CSRF
- Öffentliche Ausgabe: Routing, Templates, SQL-Parameter, Kommentare (HTMLPurifier), Editor.js-Sanitizer, Escaping im Theme, JSON-LD mit `JSON_HEX_*`, Feeds und Sitemaps

### Laufzeitprüfung

| Prüfung | Ergebnis |
|---|---|
| Neuinstallation (`install.php`, Schritte 2–5) | ✅ |
| SEC-34 | Nach der Installation 403 für neue Sitzungen; gelöschte Sperrdatei → weiterhin 403, Sperrdatei neu geschrieben; `config/app.php` mit `getenv()` ohne Sperrdatei → 403 mit Hinweis |
| SEC-38 | Gefälschte `Host: evil.example` und `X-Forwarded-Host: evil.example` → 0 Vorkommen im HTML |
| SEC-32 | `totp_start` ohne Passwort abgelehnt; `/mfa-setup` ohne Passwort aktiviert MFA nicht; erneute Einrichtung bei aktiver MFA umgeleitet, Secret unverändert; Anmeldung danach verlangt `/mfa-challenge` |
| SEC-36 | `/member/subscription` zeigt nur die eigene Bestellung, nicht die Gastbestellung mit gleicher E-Mail-Adresse |
| SEC-37 | Doppelter Anzeigename abgelehnt; eindeutiger Anzeigename, Benutzername und E-Mail-Adresse korrekt aufgelöst |
| SEC-33 | Export ohne `mfa_secret`/`ldap_dn`, eigene Profilfelder enthalten |
| SEC-44 | Eigene Herabstufung und Sperre abgelehnt; Bearbeiten anderer Konten unverändert |
| SEC-40 | Mitglied erhält nur `member`-Bereiche, Redakteur zusätzlich `editor`; unbekannte Capabilities verweigert; Admin alles |
| SEC-42 | Passkey ohne UV (Flags `0x01`) → `/mfa-challenge`, keine Sitzung; mit UV (`0x05`) → Anmeldung |
| SEC-41 | 0,23 s (unbekannter Benutzer) zu 0,22 s (falsches Passwort) |
| SEC-35 | `.svg`-URLs werden nicht mehr als Bild erkannt; SVG-Inhalt fällt durch die Inhaltsprüfung, PNG wird angenommen |
| SEC-39 | `&lt;script&gt;…` und `&lt;img onerror…&gt;` ergeben in beiden Pipelines reinen Text ohne Tags |
| SEC-43 | Cron-Loopback ausgeführt; Audit-Eintrag enthält `token=***`, nicht den Token |
| SEC-45 | `/\evil.tld` → `/evil.tld`, `\\evil.tld/x` → `/evil.tld/x` (lokale Pfade) |
| FUN-35 | `SITE_URL_PATH` `/cms`, `/cms/` und `/`: alle Pfade korrekt, Teilstrings wie `/blog/cms` bleiben erhalten |
| FUN-36 | Registrierung → Status `unverified`, Mail in der Warteschlange; falsches Passwort → allgemeine Fehlermeldung; richtiges Passwort → neuer Link, alter Link ungültig; manipuliertes und abgelaufenes Token abgelehnt; gültiger Link → 303 zur Anmeldung (`Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`), Status `active`, Token entfernt, Audit-Eintrag; zweiter Aufruf abgelehnt; Passwort-Reset aktiviert ein unbestätigtes Konto; Erneut-Senden nach 3 Mails gedrosselt; Option aus → sofort `active`, keine Mail; Admin-Liste (Zähler, Filter, Badge) und `GET /api/v1/admin/users?status=unverified` |
| FUN-37 | Vor der Korrektur: Admin-Formular „Benutzer anlegen“ → „Datenbankfehler beim Erstellen“ (MySQL-Fehler 1364 `display_name`). Danach: Benutzer angelegt, Anzeigename „Max Muster“ aus Vor- und Nachname (Tags entfernt); ohne Namen der Benutzername; ohne, mit ungültigem oder mit Array-Status → `active` |
| FUN-38 | Update-Prüfung mit `critical: true` → roter Hinweis „Kritisches Update verfügbar: Version 4.0.0“; mit `critical: false` und mit älterem Cache-Eintrag ohne das Feld → gelber Hinweis wie bisher; ohne Update → „CMS ist aktuell“. Auswertung: `true`, `"1"`, `"ja"` → ja; `false`, `"no"`, `null`, Liste → nein, jeweils ohne PHP-Warnung |
| Bibliotheken | `symfony/mime` erzeugt Mails mit korrekt gequotetem Anhangnamen und lehnt Parameternamen mit Steuerzeichen ab; `symfony/yaml` und `symfony/serializer` funktionieren; DOMPurify 3.4.17 arbeitet im Browser als Trusted-Types-Policy (Event-Handler entfernt, Tabellen-Fragmente erhalten), Editor.js startet mit allen 31 Werkzeugen |
| Admin | 102 Seiten ohne PHP-Fehler; reine POST-Endpunkte antworten erwartungsgemäß mit 403/405 |
| Geschwindigkeit | `/`, `/blog`, `/cms-login`, `/sitemap.xml`, `/feed`: 10–30 ms |
| Header | CSP mit Nonce, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` |
| Protokolle | PHP-Fehlerprotokoll leer |

### Empfehlungen (offen)

| ID | Bereich | Empfehlung |
|---|---|---|
| OFFEN-20 | Sicherheit | TOTP-Secrets (`mfa_secret`, `mfa_pending_secret`) liegen im Klartext in `user_meta`. Verschlüsselung im Ruhezustand mit Schlüssel außerhalb der Datenbank, inkl. Schlüsselrotation; vorher die doppelten MFA-Pfade in `Auth` und `TotpAdapter` zusammenführen, damit keine Konten ausgesperrt werden. |
| OFFEN-21 | Sicherheit | `SettingsService` verschlüsselt mit AES-256-CBC ohne MAC. Auf ein AEAD-Verfahren (`sodium_crypto_secretbox` oder AES-256-GCM) mit Versionspräfix umstellen und Altwerte beim Lesen migrieren. |
| OFFEN-22 | Funktion | Die E-Mail-Bestätigung gilt seit 4.0.0 für neue Registrierungen (FUN-36). Eine geänderte E-Mail-Adresse (Profil, Admin) wird weiterhin ohne Bestätigung übernommen. Bestätigung an die neue Adresse ergänzen (alte Adresse bis dahin behalten, Hinweis an die alte Adresse); danach können Gastbestellungen bestätigten Konten zugeordnet werden (vgl. SEC-36). |
| OFFEN-23 | Sicherheit | API-Tokens (JWT) werden nur durch eine Passwortänderung entwertet. Token-Version je Konto für „überall abmelden“ ergänzen. |
| OFFEN-24 | Sicherheit | Die Registrierung verrät, ob eine E-Mail-Adresse bereits existiert. Neutrale Antwort und Hinweis-Mail an die bestehende Adresse. |
| OFFEN-25 | Sicherheit | Login-Rate-Limit nur je IP. Zusätzlich je Konto mit wachsender Verzögerung (keine harte Sperre, sonst Aussperren durch Dritte). |

Bewusst ohne Änderung:

- `Origin: null` wird bei Plugin-Dashboard-POSTs akzeptiert. Die Sitzungscookies sind `SameSite=Strict` und werden bei fremden Anfragen nicht mitgesendet. Core-Sektionen prüfen zusätzlich das CSRF-Token, Plugin-Sektionen ihr eigenes.
- `X-Forwarded-Proto: https` wird immer übernommen. Es kann nur auf `https` heraufstufen.
- Bei `SITE_URL` auf `localhost`/`127.0.0.1`/`[::1]` bleibt der Port der Anfrage erhalten (Entwicklung).

### Hinweise für Betreiber

- **Reverse Proxy / CDN:** `X-Forwarded-Host` und `X-Forwarded-Proto: http` zählen nur noch, wenn der Proxy als vertrauenswürdig gilt (*Sicherheit → Firewall → Proxy / CDN & Client-IP* oder `CMS_TRUSTED_PROXIES` in `config/app.php`). Hosts außerhalb von `SITE_URL`, dessen `www`-Variante und den Hub-Domains werden durch den Host aus `SITE_URL` ersetzt. `SITE_URL` muss deshalb die öffentliche Adresse enthalten.
- **Seiten-Cache / CDN:** Nach dem Update den Seiten- und CDN-Cache leeren, damit keine vorher vergifteten Einträge ausgeliefert werden.
- **MFA:** Die TOTP-Einrichtung im Mitgliederbereich und unter `/mfa-setup` verlangt das aktuelle Passwort. Eine aktive Authenticator-App lässt sich nur wechseln, indem MFA zuerst deaktiviert wird (ebenfalls mit Passwort).
- **Passkeys:** Bei aktiver MFA verlangen Passkeys ohne PIN/Biometrie zusätzlich den TOTP-Code.
- **Bestellungen:** `/member/subscription` zeigt nur Bestellungen, die angemeldet aufgegeben wurden. Gastbestellungen bleiben unter *Aboverwaltung → Bestellungen & Zuweisung* sichtbar; dort lässt sich dem Konto das Paket zuweisen.
- **Plugin-Dashboards:** Bereiche mit unbekannter `capability` sind nicht mehr für alle Mitglieder sichtbar. Plugins verwenden Rollennamen (`member`, `editor`, `admin`) oder echte Capabilities.
- **Installer:** Nach der Installation bleibt `install.php` gesperrt, solange `config/install.lock` existiert oder `config/app.php` angepasst ist. Für eine bewusste Neuinstallation die Sperrdatei per SFTP/SSH entfernen und eine unveränderte Konfigurationsvorlage verwenden.
- **Importer:** SVG-Dateien werden nicht mehr importiert. Vor 4.0.0 importierte SVGs unter `uploads/images/importer/` prüfen und bei Bedarf entfernen.
- **Admins:** Das eigene Konto lässt sich im Formular nicht mehr herabstufen oder sperren. Dafür ist ein zweites Admin-Konto nötig.
- **E-Mail-Verifizierung:** Die Option *E-Mail-Verifizierung erzwingen* wirkt jetzt. Vor dem Einschalten den Mailversand prüfen; bei aktiver Mail-Warteschlange muss der Cron laufen, sonst erreicht die Bestätigungsmail niemanden. Bestehende Konten bleiben unverändert. Unbestätigte Konten (Status „E-Mail unbestätigt“) lassen sich in der Benutzerliste über *Aktivieren* freischalten; sie bleiben unbestätigt, wenn die Option später ausgeschaltet wird.
- **Benutzer anlegen:** Funktioniert unter `/admin/users` wieder auf MySQL/MariaDB im Strict-Mode. Der Anzeigename wird aus Vor- und Nachname gebildet, sonst aus dem Benutzernamen.
- **Updates:** Ab 4.0.0 erscheinen Updates mit `"critical": true` unter *System → Updates* als roter Hinweis. Installationen mit 3.5.6 zeigen das Update auf 4.0.0 noch als normales Update an; es enthält trotzdem Sicherheitskorrekturen und sollte zeitnah eingespielt werden.
- **OPcache:** Nach dem Einspielen OPcache leeren bzw. PHP-FPM neu laden.

### Geänderte Dateien

- **Sicherheit:**
  - `CMS/includes/functions/options-runtime.php`, `CMS/core/Services/SiteTableService.php` (SEC-38)
  - `CMS/core/Auth.php`, `CMS/core/Auth/AuthManager.php`, `CMS/core/Auth/MFA/TotpAdapter.php`, `CMS/core/Routing/PublicRouter.php`, `CMS/member/includes/class-member-controller.php`, `CMS/member/security.php` (SEC-32, SEC-36, SEC-37, SEC-41, SEC-42)
  - `CMS/core/Services/MemberService.php` (SEC-33)
  - `CMS/install/InstallerController.php`, `CMS/install/InstallerService.php` (SEC-34, BP-06)
  - `CMS/plugins/cms-importer/includes/class-importer.php` (SEC-35, BP-07)
  - `CMS/core/Services/AI/SeoMetadataGenerationPipeline.php`, `CMS/core/Services/AI/ContentDraftGenerationPipeline.php` (SEC-39)
  - `CMS/core/Member/PluginDashboardRegistry.php` (SEC-40)
  - `CMS/admin/modules/system/SystemInfoModule.php` (SEC-43), `CMS/admin/modules/users/UsersModule.php` (SEC-44), `CMS/admin/partials/section-page-shell.php` (SEC-45)
- **Bibliotheken:** `CMS/assets/mime/`, `CMS/assets/yaml/`, `CMS/assets/serializer/`, `CMS/assets/dompurify/purify.min.js`; Quellarchive `CMS_ASSETS/mime-8.1.10.zip`, `yaml-8.1.11.zip`, `serializer-8.1.11.zip`
- **E-Mail-Verifizierung (FUN-36):** neu `CMS/core/Services/EmailVerificationService.php`; `CMS/core/Auth.php`, `CMS/core/Routing/PublicRouter.php`, `CMS/core/Router.php`, `CMS/index.php`, `CMS/core/Services/CmsAuthPageService.php`, `CMS/core/Services/UserService.php`, `CMS/core/Services/MemberService.php`, `CMS/core/Routing/ApiRouter.php`, `CMS/admin/views/users/list.php`, `CMS/admin/views/users/settings.php`
- **Fehler und Best Practice:** `CMS/core/Services/UserService.php` (FUN-37); `CMS/core/Services/UpdateService.php`, `CMS/admin/views/system/updates.php` (FUN-38); `CMS/core/Router.php` (FUN-35); `CMS/admin/orders.php`, `CMS/admin/site-tables.php`, `CMS/admin/packages.php`, `CMS/admin/views/comments/list.php`, `CMS/core/CacheManager.php`, `CMS/core/Database.php`, `CMS/core/Logger.php` (BP-07)
- **Dokumentation:** `DOC/core/SECURITY.md`, `DOC/core/STATUS.md`, `DOC/member/MEMBER-SECURITY.md`, `DOC/member/README.md`, `DOC/admin/users-groups/AUTH-SETTINGS.md`, `DOC/admin/users-groups/USERS.md`, `DOC/admin/themes-design/CMS-LOGINPAGE.md`, `DOC/admin/system-settings/UPDATES.md`, `DOC/assets/`
