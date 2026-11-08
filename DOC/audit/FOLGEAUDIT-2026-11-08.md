# 365CMS – Projektdokumentation | Abschnitt: Folgeaudit 2026-11-08

> **Stand:** 2026-11-08 | **Version:** 4.0.0 (Basis 3.5.6) | **Prüfumfang:** `CMS/` inkl. Theme `cms-default` und Plugin `cms-importer`, gebündelte Bibliotheken unter `CMS/assets/` (Versionsabgleich gegen bekannte Sicherheitslücken) | Übersicht: [README.md](README.md)

## English (summary)

Security and quality audit of 365CMS 3.5.6, released as 4.0.0. The audit covered security (known exploits and vulnerable bundled libraries), bugs, missing functions or values, speed and best practice. 44 findings were recorded and all are fixed in 4.0.0. Eight recommendations (OFFEN-20 … OFFEN-27) remain open because they need a migration or a product decision.

A second pass followed a concrete report: the security audit claimed that no backup existed although backups were present. The check looked only for `backups/*.sql*` files, while the backup service writes `full_backup_<time>/` and `database_backup_<time>/` directories with a manifest. The pass then looked for other status displays that report a wrong state and for weaknesses in backup and restore.

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
- **Backups:** the audit now evaluates the real backups. Restore accepts only complete backups whose SHA-256 checksums match the manifest, and it checks the file archive before anything is changed. The old restore limits (5,000 entries, 512 MB) were too low for normal sites, so their own backups could not be restored. A failed write of the ZIP archive was reported as success, and two backups in the same second overwrote each other.
- **Firewall:** with the default limits (60 requests per minute), working quickly in the admin area locked the administrator out for an hour, including the firewall page that could lift the block. An "allow IP" rule did not exempt an address from the rate limit. Administrators and allowed IPs are now exempt from the rate limit and its automatic blocks.
- **False status displays:** the audit reported firewall and anti-spam as active while both were switched off (the default). The dashboard counted every API call as a failed login. HTTPS behind some proxies showed as inactive. The SEO pages listed dynamic sitemaps and `robots.txt` as missing, and the anti-spam form showed values that differed from the stored ones.
- **Configuration copies:** every save of the general settings wrote another copy of `config/app.php`, including database credentials and keys, without a limit and readable by other local accounts.

Runtime and CLI tests on PHP 8.4 and MySQL 8.4 confirmed every code fix and the updated libraries. Pages render in 10–30 ms, and all admin pages load without PHP errors.

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
- **Zweiter Durchgang (Statusanzeigen, Backups):**
  - Anlass: Das Sicherheits-Audit meldete vorhandene Backups als fehlend (FUN-39).
  - Geprüft: alle Prüfpunkte des Sicherheits-Audits, Dashboard (Kacheln, Prioritätshinweise, Sicherheits-Score), *Diagnose → Übersicht*, *Performance → Datenbank*, SEO-Dashboard und Sitemap-Status, *System → Backup & Restore* sowie die zugehörigen Formulare.
  - Jeder Befund wurde zur Laufzeit gegen den Ausgangsstand gegengeprüft.
  - Schutzdateien unter Apache 2.2 und 2.4, jeweils mit und ohne `mod_access_compat`.
  - PHPStan-Vergleich mit dem Ausgangsstand: keine neuen Befunde.
  - Unabhängige Sicherheitsprüfung des Diffs sowie von Anmeldung, API, Uploads, Updater, Importern und XSS/CSRF: keine Befunde.
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
| SEC-46 | Die Wiederherstellung prüfte weder den Backup-Status noch die SHA-256-Prüfsummen aus dem Manifest (nur „Prüfen“ wertete sie aus). Ein nachträglich veränderter Dump oder ein unvollständiges Backup wurde eingespielt. Ein ungültiges Datei-Archiv fiel erst nach dem Datenbank-Restore auf (Teil-Wiederherstellung) | 🟡 | ✅ 4.0.0 – nur vollständige Backups mit passenden Prüfsummen. Das Datei-Archiv (Pfade, Symlinks, erlaubte Verzeichnisse, Grenzen, freier Speicher) wird vor dem Sicherungs-Snapshot und vor jeder Änderung geprüft (`BackupService::inspectRestoreArchive()`) |
| SEC-47 | Jedes Speichern unter *Einstellungen → Allgemein* kopierte `config/app.php` mit DB-Zugangsdaten und Schlüsseln nach `config/app.backup-<Datum>.php`. Das geschah auch ohne Änderung, ohne Begrenzung und mit Standardrechten (meist `0644`, für andere lokale Konten lesbar) | 🟡 | ✅ 4.0.0 – Schreiben und Kopie nur bei geänderten Werten. Kopien werden exklusiv angelegt und erhalten `0640`, bevor Inhalt geschrieben wird. Das CMS behält die letzten fünf und setzt ältere Kopien mit Leserechten für alle auf `0640`. `config/.htaccess` wird nur bei Abweichung geschrieben |

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

- SSRF-Schutz in `Http\Client` und allen ausgehenden Abrufen (IndexNow, Google Indexing, Schriften, Doku-Abgleich, Weiterleitungs-Prüfung); Fehlermeldung und IPv6-Behandlung siehe FUN-50
- ZIP-Prüfung bei Updates und Marketplace (Backups: SEC-46, FUN-42); Theme-Editor; Medienverwaltung und private Uploads
- Cron-Token (`hash_equals`), CSRF, Sitzungen, Passwort-Reset, Passkey-Registrierung, JWT/API, Checkout, Mail-Header
- Admin: `AdminRouter` prüft durchgängig `isAdmin`, CSRF in allen Section-Shells, Allow-Lists für Aktionen, Formel-Schutz in CSV-Exporten, AI-Endpunkte nur per POST mit Admin-Recht und CSRF
- Öffentliche Ausgabe: Routing, Templates, SQL-Parameter, Kommentare (HTMLPurifier), Editor.js-Sanitizer, Escaping im Theme, JSON-LD mit `JSON_HEX_*`, Feeds und Sitemaps

#### Zweiter Durchgang: Backups und Statusanzeigen

| ID | Befund | Schwere | Status |
|---|---|---|---|
| FUN-39 | Sicherheits-Audit „Letztes Backup“ suchte nur `backups/*.sql*`. Der Backup-Dienst legt aber Verzeichnisse `full_backup_<Zeit>/` bzw. `database_backup_<Zeit>/` mit Manifest an, deshalb meldete das Audit vorhandene Backups als „Kein Datenbank-Backup gefunden“ (kritisch). Ohne `backups/` entfiel die Prüfung ganz; das Alter wurde aufgerundet | 🟠 | ✅ 4.0.0 – `SecurityAuditModule::buildBackupCheck()` wertet `BackupService::getBackupOverview()` aus. Gezählt werden alle vollständigen Backups einschließlich älterer `.sql`-Dumps: bis 7 Tage OK, bis 30 Tage Warnung, sonst kritisch, mit Datum, Typ und Anzahl. Unvollständige Backups werden genannt |
| FUN-40 | Abgebrochene Backups ohne Manifest waren in *System → Backup & Restore* unsichtbar, belegten aber Speicher und ließen sich nicht löschen. Backups mit fehlenden Teilen erschienen mit „Wiederherstellen“ und Download-Schaltflächen | 🟡 | ✅ 4.0.0 – Status „Unvollständig“ (mit Ursache) und „In Arbeit“ (ohne Manifest, jünger als 1 Stunde). Solche Einträge lassen sich prüfen, im Trockenlauf testen oder löschen; Downloads gibt es nur für vorhandene Teile |
| FUN-41 | Zwei Backups desselben Typs in derselben Sekunde schrieben in dasselbe Verzeichnis, und der Protokolleintrag `backup_log_<Zeit>` wurde überschrieben. Ein Fehler beim Abschluss des ZIP-Archivs (`ZipArchive::close()`) wurde als Erfolg gemeldet. Ein fehlgeschlagenes Backup hinterließ ein halbes Verzeichnis. Symlinks auf anderen Speicher führten zu falschen Archivpfaden | 🟠 | ✅ 4.0.0 – Verzeichnis exklusiv reserviert (Suffix `-2`, `-3` …), eindeutige Protokolleinträge, Prüfung von `close()`, Aufräumen bei Fehlern, Archivpfade relativ zum Iterator |
| FUN-42 | Die Restore-Grenzen (5.000 Einträge, 256 MB je Datei, 512 MB entpackt) lagen unter der Größe üblicher Datei-Backups. Eigene Backups ließen sich nicht wiederherstellen; gemeldet wurde „ungültige oder unsichere Pfade“ | 🟠 | ✅ 4.0.0 – 250.000 Einträge, 4 GiB je Datei, 32 GiB gesamt, konkrete Fehlermeldung, Prüfung des freien Speichers |
| FUN-43 | Datei-Backups enthielten `assets/` (vom Core ausgelieferte Bibliotheken). Ein älteres Backup spielte nach einem Update veraltete, ggf. verwundbare Bibliotheken zurück | 🟡 | ✅ 4.0.0 – gesichert werden `uploads/`, `themes/` und `plugins/`; `assets/` aus älteren Archiven wird beim Restore übersprungen |
| FUN-44 | Audit: „install.php entfernt“ warnte auch bei per `config/install.lock` gesperrtem Installer (der mit jedem Update ausgeliefert wird). Die Rechteprüfung der Konfigurationsdateien akzeptierte nur `0644`, `0640`, `0600` und warnte bei strengeren Rechten wie `0400`/`0440` | ⚪ | ✅ 4.0.0 – „install.php gesperrt“ (OK); bewertet wird, ob Gruppe oder andere schreiben dürfen |
| FUN-45 | `backups/.htaccess` nur mit Apache-2.2-Syntax, `logs/.htaccess` nur mit 2.4-Syntax: je nach Version und Modulen HTTP 500 statt 403 (der Inhalt blieb geschützt) | 🟡 | ✅ 4.0.0 – beide mit Varianten für 2.2 und 2.4; der Backup-Dienst ersetzt eine unveränderte alte `backups/.htaccess` |
| FUN-46 | „Fehlgeschlagene Logins“ zählte alle Einträge in `cms_login_attempts`, also auch jeden API-Aufruf, jede Suche und Registrierung (die Tabelle speichert alle Rate-Limits). Betroffen waren Dashboard (Kachel, Prioritätshinweis, Sicherheits-Score), *Diagnose → Übersicht* und *Performance → Datenbank* | 🟡 | ✅ 4.0.0 – nur `Security::FAILED_LOGIN_ACTIONS` (`login`, `mfa_verify`) |
| FUN-47 | Der Sitemap-Index verweist auf `/en-pages.xml` und `/en-posts.xml`, für beide gab es aber keine Route (404 ohne gespeicherte Datei) | 🟡 | ✅ 4.0.0 – Routen ergänzt, Dateien werden bei Änderungen verworfen |
| FUN-48 | *SEO → Sitemap* und SEO-Dashboard meldeten dynamisch erzeugte Sitemaps und `robots.txt` als „fehlt“ | ⚪ | ✅ 4.0.0 – „vorhanden“, „keine Einträge“, „bei Abruf“, „dynamisch“, „deaktiviert“ |
| FUN-49 | Eine gespeicherte statische `robots.txt` überdeckte die dynamische Ausgabe; spätere Änderungen unter *Einstellungen* (robots.txt-Inhalt, Website-URL) blieben wirkungslos. Eigener Inhalt ohne `Sitemap:`-Zeile verlor den Verweis auf die Sitemap | 🟡 | ✅ 4.0.0 – eine unveränderte, vom CMS erzeugte Datei wird neu geschrieben, eine manuell geänderte bleibt erhalten (Hinweis). `Sitemap:`-Zeile ergänzt; eine geänderte Website-URL verwirft gespeicherte Sitemaps |
| FUN-50 | `Http\Client` meldete eine gescheiterte DNS-Auflösung als „durch den SSRF-Schutz blockiert“; IPv6-Adressen in eckigen Klammern wurden immer blockiert | 🟡 | ✅ 4.0.0 – eigene DNS-Meldung; öffentliche IPv6-Adressen zugelassen, private weiter gesperrt |
| FUN-51 | Audit: „Firewall Runtime aktiv“ und „AntiSpam Runtime aktiv“ prüften nur, ob der Code verdrahtet ist, und meldeten OK, auch wenn `firewall_enabled` bzw. `antispam_enabled` aus waren (Standard nach der Installation). Dann greifen keine Firewall-Regeln und kein Request-Rate-Limit bzw. keine Spam-Prüfung | 🟠 | ✅ 4.0.0 – Warnung bei ausgeschaltetem Schalter mit Hinweis auf den Menüpunkt. AntiSpam nennt die tatsächlich aktiven Einzelprüfungen und ist bei global abgeschalteten Kommentaren ohne Kontakt-Plugin OK |
| FUN-52 | Anti-Spam-Formular: Ein gespeicherter Wert `0` bei Mindestzeit und Link-Limit erschien als `3`, ein erneutes Speichern schaltete die Prüfung wieder ein. Ohne gespeicherte Werte waren Honeypot und User-Agent-Prüfung nicht angehakt (Laufzeit-Standard: an), das erste Speichern schaltete sie aus | 🟡 | ✅ 4.0.0 – Formular liest die wirksamen Werte aus `AntispamService::getSettings()` |
| FUN-53 | Dashboard erkannte HTTPS nur über `HTTPS` und `X-Forwarded-Proto: https`. Hinter Proxys mit `X-Forwarded-SSL`, `Front-End-Https` oder bei Port 443 erschienen „HTTPS nicht aktiv“ und ein um 20 Punkte niedrigerer Sicherheits-Score, während Audit und System-Info HTTPS als aktiv zeigten | ⚪ | ✅ 4.0.0 – Dashboard und `StatusService` nutzen `Security::getSecurityHeaderProfile()` |
| FUN-54 | Das Request-Rate-Limit der Firewall galt auch für angemeldete Administratoren. Mit den Standardwerten (auch Profil „Produktion“: 60 Anfragen in 60 s) folgten beim zügigen Arbeiten im Backend HTTP 429 und eine einstündige IP-Sperre. Die Sperre blockierte auch die Firewall-Seite, über die sie sich aufheben ließe. Die Laufzeitprüfung reproduzierte das: 60 Admin-Seiten, dann 429 und 30 × 403. Eine Regel „IP erlauben“ schützte nicht vor dem Rate-Limit, obwohl sie laut Oberfläche Vorrang hat: Nach 60 Anfragen folgte 429, und es wurde sogar eine automatische Sperre angelegt. Eine abgelaufene, noch nicht aufgeräumte automatische Sperre verhinderte eine neue | 🟠 | ✅ 4.0.0 – IPs mit Allow-Regel sowie angemeldete Konten mit `manage_settings` zählen nicht zum Rate-Limit und umgehen automatische Sperren (`SecurityRuntimeService::isRateLimitExempt()`); manuelle Block-Regeln gelten weiter. Nur aktive Sperren verhindern eine neue |
| BP-08 | Landing-Page-Beitragsanzahl ohne Obergrenze; `BackupService` griff auf eine möglicherweise undefinierte Konstante zu; Größenangaben ohne deutsches Zahlenformat; `robots.txt` ohne Dateisperre geschrieben | ⚪ | ✅ 4.0.0 |
| BP-09 | Die Anti-Spam-Seite führte bei jedem Aufruf `CREATE TABLE` und ein `ALTER TABLE` aus, das bei vorhandenem Index jedes Mal fehlschlägt | ⚪ | ✅ 4.0.0 – einmal je Code-Stand über `Database::runSchemaOnce()` |

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

Zweiter Durchgang (Backups und Statusanzeigen):

| Prüfung | Ergebnis |
|---|---|
| FUN-39 | Vorher: Backups vorhanden, Audit „Kein Datenbank-Backup gefunden“ (kritisch). Danach: OK mit Datum, Typ und Anzahl; unvollständige Backups werden genannt |
| SEC-46 | Nachträglich veränderter Dump → Wiederherstellung abgelehnt, bevor der Sicherungs-Snapshot entsteht oder etwas geändert wird; unvollständiges Backup abgelehnt |
| FUN-40, FUN-41 | Zwei Backups in derselben Sekunde → zweites Verzeichnis mit Suffix `-2`, beide vollständig; Verzeichnis ohne Manifest → „In Arbeit“, Backup mit fehlenden Teilen → „Unvollständig“ (löschbar, ohne Wiederherstellen) |
| FUN-44 | `config/app.php` mit `0440` → OK, mit `0664` → Warnung; Installer mit `config/install.lock` → „install.php gesperrt“ (OK) |
| FUN-45 | Apache 2.4 ohne `mod_access_compat`: alte `backups/.htaccess` → 500, neue → 403. Apache 2.2: alte `logs/.htaccess` → 500, neue → 403 |
| FUN-46 | 15 API-Aufrufe und 2 fehlgeschlagene Anmeldungen → Dashboard, Diagnose und *Performance → Datenbank* zeigen 2 statt 17 |
| FUN-47 bis FUN-49 | `/en-pages.xml` → 200; SEO-Seiten zeigen dynamische Sitemaps und `robots.txt` nicht mehr als fehlend; eine unveränderte gespeicherte `robots.txt` folgt geänderten Einstellungen, eine manuell geänderte bleibt erhalten |
| FUN-50 | Nicht auflösbarer Host → DNS-Meldung statt SSRF-Meldung; `10.0.0.1`, `localhost`, `169.254.169.254`, `[::1]`, `[fd00::1]` und `127.1` weiterhin blockiert |
| SEC-47 | Speichern ohne Änderung → keine Kopie; mit Änderung → eine Kopie mit `0640`; eine Serie von Änderungen → nur die fünf neuesten Kopien bleiben |
| FUN-51 | Schalter nicht gesetzt → Firewall und AntiSpam „Warnung“; eingeschaltet → OK, AntiSpam mit den aktiven Prüfungen („Honeypot, Mindestzeit 3 s, User-Agent-Prüfung, höchstens 3 Links, Blacklist“); AntiSpam aus bei abgeschalteten Kommentaren → OK, bei aktiven Kommentaren → Warnung |
| FUN-52 | Ohne gespeicherte Werte: Honeypot und User-Agent-Prüfung angehakt, 3 s / 3 Links; nach dem Speichern von 0 / 0 zeigt das Formular 0 / 0 |
| FUN-53 | Ohne HTTPS: „Prüfen“, Prioritätshinweis, Score 80/100. Mit `X-Forwarded-SSL: on`: „Aktiv“, kein Hinweis, 100/100, Audit übereinstimmend |
| FUN-54 | Firewall aktiv, Standardwerte. 91 Admin-Seiten am Stück: vorher 60 × 200, dann 429 und 30 × 403; danach 91 × 200 ohne Sperre. IP mit Allow-Regel, 65 Anfragen: vorher 429 und automatische Sperre; danach 65 × 200. Anonyme Anfragen sind weiter begrenzt (60 × 200, dann 429/403); ein angemeldeter Admin erreicht dabei `/admin/firewall`. Eine manuelle Block-Regel gilt auch für Admins (403). Nach einer abgelaufenen automatischen Sperre wird eine neue angelegt |
| BP-09 | Flag-Datei für `antispam_blacklist` angelegt; die Schemaprüfung läuft nur noch einmal je Code-Stand |
| Admin | 91 Seiten ohne PHP-Fehler, PHP-Fehlerprotokoll leer |

### Empfehlungen (offen)

| ID | Bereich | Empfehlung |
|---|---|---|
| OFFEN-20 | Sicherheit | TOTP-Secrets (`mfa_secret`, `mfa_pending_secret`) liegen im Klartext in `user_meta`. Verschlüsselung im Ruhezustand mit Schlüssel außerhalb der Datenbank, inkl. Schlüsselrotation; vorher die doppelten MFA-Pfade in `Auth` und `TotpAdapter` zusammenführen, damit keine Konten ausgesperrt werden. |
| OFFEN-21 | Sicherheit | `SettingsService` verschlüsselt mit AES-256-CBC ohne MAC. Auf ein AEAD-Verfahren (`sodium_crypto_secretbox` oder AES-256-GCM) mit Versionspräfix umstellen und Altwerte beim Lesen migrieren. |
| OFFEN-22 | Funktion | Die E-Mail-Bestätigung gilt seit 4.0.0 für neue Registrierungen (FUN-36). Eine geänderte E-Mail-Adresse (Profil, Admin) wird weiterhin ohne Bestätigung übernommen. Bestätigung an die neue Adresse ergänzen (alte Adresse bis dahin behalten, Hinweis an die alte Adresse); danach können Gastbestellungen bestätigten Konten zugeordnet werden (vgl. SEC-36). |
| OFFEN-23 | Sicherheit | API-Tokens (JWT) werden nur durch eine Passwortänderung entwertet. Token-Version je Konto für „überall abmelden“ ergänzen. |
| OFFEN-24 | Sicherheit | Die Registrierung verrät, ob eine E-Mail-Adresse bereits existiert. Neutrale Antwort und Hinweis-Mail an die bestehende Adresse. |
| OFFEN-25 | Sicherheit | Login-Rate-Limit nur je IP. Zusätzlich je Konto mit wachsender Verzögerung (keine harte Sperre, sonst Aussperren durch Dritte). |
| OFFEN-26 | Sicherheit | Backups (Datenbank-Dumps mit Passwort-Hashes und Einstellungen) liegen unverschlüsselt unter `CMS/backups/` im Webroot und sind nur per `.htaccess` geschützt; unter nginx gilt das nur mit eigener Regel. Backup-Verzeichnis außerhalb des Webroots konfigurierbar machen, optional verschlüsseln und extern ablegen. |
| OFFEN-27 | Sicherheit | Core-Updatepakete werden nur gegen die SHA-256-Prüfsumme aus dem Update-Manifest geprüft, das von derselben Quelle kommt. Eine Signatur (z. B. Ed25519 mit im Core hinterlegtem öffentlichem Schlüssel) schützt auch bei kompromittierter Update-Quelle. |

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
- **Backups:** Datei-Backups sichern ab 4.0.0 `uploads/`, `themes/` und `plugins/`, nicht mehr `assets/` (kommt mit jedem Core-Update). Ältere Backups mit fehlenden Teilen erscheinen jetzt als „Unvollständig“ und lassen sich löschen. Die Wiederherstellung verlangt passende Prüfsummen; ältere `.sql`-Dumps ohne Manifest zählen im Audit weiterhin.
- **nginx:** `.htaccess` wirkt dort nicht. Den Zugriff auf `backups/`, `logs/` und `config/` im Server-Block sperren (z. B. `location ~ ^/(backups|logs|config)/ { deny all; }`).
- **robots.txt:** Eine vom CMS erzeugte, unveränderte `robots.txt` wird bei Änderungen unter *Einstellungen* neu geschrieben. Eine manuell bearbeitete Datei bleibt erhalten; dann erscheint ein Hinweis unter *SEO → Sitemap*.
- **Konfigurationskopien:** Unter `config/` bleiben nur die fünf neuesten `app.backup-*.php`; ältere werden beim nächsten Speichern mit Änderung entfernt. Kopien aus Versionen vor 3.4.12 (`app.php.backup.*`) bitte manuell löschen.
- **Firewall und Anti-Spam:** Das Sicherheits-Audit warnt jetzt, solange Firewall oder Anti-Spam ausgeschaltet sind (Standard nach der Installation). Angemeldete Administratoren und IPs mit Regel „IP erlauben“ unterliegen nicht mehr dem Request-Rate-Limit. Vor dem Anmelden zählen auch Admin-Anfragen; für feste Büro-IPs eine Allow-Regel anlegen.
- **Anti-Spam-Einstellungen:** Wer das Formular vor 4.0.0 gespeichert hat, sollte Honeypot und User-Agent-Prüfung kontrollieren. Sie waren dort nicht angehakt und wurden beim ersten Speichern ausgeschaltet.
- **Statistik:** „Fehlgeschlagene Logins“ zeigt nach dem Update deutlich niedrigere Werte, weil API-Aufrufe, Suchen und Registrierungen nicht mehr mitgezählt werden.

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
- **Zweiter Durchgang:**
  - `CMS/admin/modules/security/SecurityAuditModule.php` (FUN-39, FUN-44, FUN-51)
  - `CMS/core/Services/BackupService.php`, `CMS/admin/modules/system/BackupsModule.php`, `CMS/admin/views/system/backups.php` (SEC-46, FUN-40 bis FUN-43, BP-08)
  - `CMS/backups/.htaccess`, `CMS/logs/.htaccess` (FUN-45)
  - `CMS/core/Security.php`, `CMS/core/Services/SystemService.php`, `CMS/admin/modules/seo/PerformanceModule.php` (FUN-46)
  - `CMS/core/Services/DashboardService.php` (FUN-46, FUN-53), `CMS/core/Services/StatusService.php` (FUN-53)
  - `CMS/core/Routing/ThemeRouter.php`, `CMS/core/Services/SEO/SeoSitemapService.php`, `CMS/core/Services/SEOService.php`, `CMS/admin/modules/seo/SeoSuiteModule.php`, `CMS/admin/views/seo/dashboard.php`, `CMS/admin/views/seo/sitemap.php` (FUN-47 bis FUN-49, BP-08)
  - `CMS/admin/modules/settings/SettingsModule.php` (SEC-47, FUN-49)
  - `CMS/core/Http/Client.php` (FUN-50)
  - `CMS/admin/modules/security/AntispamModule.php`, `CMS/admin/views/security/antispam.php` (FUN-52, BP-09)
  - `CMS/core/Services/SecurityRuntimeService.php`, `CMS/admin/views/security/firewall.php` (FUN-54)
  - `CMS/themes/cms-default/partials/home-landing.php` (BP-08)
- **Dokumentation:** `DOC/admin/security/ANTISPAM.md`, `DOC/admin/security/FIREWALL.md`, `DOC/admin/security/SECURITY-AUDIT.md`, `DOC/admin/seo/SEO.md`, `DOC/admin/system-settings/BACKUP.md`, `DOC/admin/system-settings/README.md`, `DOC/admin/system-settings/SYSTEM.md`, `DOC/core/SECURITY.md`, `DOC/core/STATUS.md`, `DOC/member/MEMBER-SECURITY.md`, `DOC/member/README.md`, `DOC/admin/users-groups/AUTH-SETTINGS.md`, `DOC/admin/users-groups/USERS.md`, `DOC/admin/themes-design/CMS-LOGINPAGE.md`, `DOC/admin/system-settings/UPDATES.md`, `DOC/assets/`
