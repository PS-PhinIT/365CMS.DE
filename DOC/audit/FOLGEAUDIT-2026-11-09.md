# 365CMS – Projektdokumentation | Abschnitt: Folgeaudit 2026-11-09

> **Stand:** 2026-11-09 | **Version:** 4.0.0 | **Prüfumfang:** Umsetzung der Empfehlungen OFFEN-20 bis OFFEN-26 aus dem [Folgeaudit vom 2026-11-08](FOLGEAUDIT-2026-11-08.md), Regressionsprüfung der Korrekturen aus den Audits vom 2026-10-14 und 2026-11-08, `CMS/` inkl. Theme `cms-default` | Übersicht: [README.md](README.md)

## English (summary)

Second pass on 4.0.0. It implements the open recommendations of the follow-up audit of 2026-11-08 and re-checks the earlier fixes for regressions.

Implemented in 4.0.0:

- **OFFEN-20, OFFEN-21 – secrets at rest:** TOTP secrets and secret settings use authenticated encryption (XChaCha20-Poly1305, or AES-256-GCM without libsodium) with a key ring in `config/app.php` (`CMS_ENCRYPTION_KEY`, `CMS_ENCRYPTION_KEYS_PREVIOUS`). Old AES-256-CBC values and plaintext TOTP secrets are migrated automatically. Both MFA code paths now share one implementation.
- **OFFEN-22 – e-mail changes:** a new address takes effect only after the link sent to it is opened. The old address stays active until then and receives a notice. Guest orders are assigned to the account only after a confirmed address.
- **OFFEN-23 – sign out everywhere:** a token version per account ends all sessions and API tokens (JWT) except the current session.
- **OFFEN-24 – registration:** an e-mail address that is already registered gets the same answer as a new one; the owner receives a notice.
- **OFFEN-25 – login throttling per account:** after five failed attempts within 15 minutes each further attempt waits 1, 2, 4, 8, 16 and at most 30 seconds. There is no hard lock, so third parties cannot lock an owner out.
- **OFFEN-26 – backups:** the backup directory can live outside the web root (`CMS_BACKUP_PATH`), backups can be encrypted, and the security audit checks the storage location.

OFFEN-27 (signed update packages) remains open; the maintainer implements it separately.

The regression pass found six defects that had existed since 3.5.x. All are fixed:

- Plain-text mails (e.g. password reset) were sent as HTML.
- The `mail()` fallback lacked RFC 2047 header encoding and a transfer encoding.
- Dashboard links pointed to disabled modules.
- A theme helper showed exception texts and revealed registered e-mail addresses.
- The internal template `/member/plugin-section` was directly reachable.

The code review of the new code found four further problems before release. These were fixed and never shipped.

Runtime tests on PHP 8.4 and MySQL 8.4 confirmed every change. Admin and member pages run without PHP errors, and PHPStan reports no new findings.

## Deutsch

### Methodik

- **Umsetzung:** OFFEN-20 bis OFFEN-26 nach den Vorgaben aus dem Folgeaudit vom 2026-11-08. OFFEN-27 bleibt auf Wunsch des Maintainers offen.
- **Code-Review:** Jede Änderung wurde nach der Umsetzung erneut geprüft, besonders Migration, Schlüsselwechsel, Fehlerpfade und Rate-Limits. Die Funde stehen getrennt unter *Im Code-Review gefunden*.
- **Regressionsprüfung:**
  - Korrekturen aus den Audits vom 2026-10-14 und 2026-11-08 gegen den aktuellen Code geprüft
  - Abgleich mit dem Paket 3.5.0, um zu bestimmen, seit wann ein Befund besteht
  - Admin-Crawl (102 Seiten) auf dem Ausgangsstand und auf dem neuen Stand, alle Core-Module aktiv
  - Crawl des Mitgliederbereichs, öffentliche Routen, Vergleich der PHP-Fehlerprotokolle
- **Werkzeuge:**
  - `php -l` (PHP 8.4) auf allen geänderten Dateien
  - PHPStan bis Level 5 über `CMS/`: 55 Befunde wie im Ausgangsstand, keine neuen
- **Laufzeittest:** Docker mit PHP 8.4 und MySQL 8.4, zwei Installationen:
  - Ausgangsstand mit TOTP-Admin
  - neuer Stand
  - Mailversand über einen Test-`sendmail`
- **Grenzen:**
  - kein echter SMTP-Server und kein Mail-Client-Test
  - kein Lasttest der Anmeldeverzögerung unter PHP-FPM
  - Backup-Verzeichnis außerhalb des Webroots nur im Container geprüft

Schwere: 🔴 hoch · 🟠 mittel · 🟡 niedrig · ⚪ Hinweis. Die IDs setzen die Nummerierung des Folgeaudits vom 2026-11-08 fort (bis SEC-47, FUN-54, BP-09, OFFEN-27).

### Umgesetzte Empfehlungen

| ID | Empfehlung (2026-11-08) | Status |
|---|---|---|
| OFFEN-20 | TOTP-Secrets im Klartext in `user_meta`; doppelte MFA-Pfade in `Auth` und `TotpAdapter` | ✅ 4.0.0 – `mfa_secret` und `mfa_pending_secret` über `CryptoService` (Kontext `mfa`, an Benutzer-ID und Feld gebunden). `Auth` delegiert alle MFA-Methoden an `TotpAdapter`. Klartext-Secrets werden beim Lesen und einmal je Schlüsselstand beim Start (`Bootstrap::migrateStoredSecrets()`) verschlüsselt. `/mfa-setup` bestätigt nur das in derselben Sitzung erzeugte Secret |
| OFFEN-21 | `SettingsService` mit AES-256-CBC ohne MAC | ✅ 4.0.0 – Format `enc:v2:<alg>:<Schlüssel-ID>:<Daten>`: XChaCha20-Poly1305 (libsodium), ohne sodium AES-256-GCM. Der Optionsname ist als Associated Data gebunden. Schlüsselring aus `CMS_ENCRYPTION_KEY` (der Installer erzeugt ihn), der Ableitung aus `AUTH_KEY`/`SECURE_AUTH_KEY` und `CMS_ENCRYPTION_KEYS_PREVIOUS`. Altwerte (`enc:` ohne `v2`) werden beim Lesen und per Massenmigration übernommen |
| OFFEN-22 | Geänderte E-Mail-Adresse ohne Bestätigung | ✅ 4.0.0 – `EmailChangeService`: Die neue Adresse gilt erst nach dem Link an sie (`/cms-confirm-email-change`, 48 h, einmalig, Token nur als SHA-256-Hash). Die alte Adresse bleibt bis dahin aktiv und erhält einen Hinweis. Die Änderung im Mitgliederbereich verlangt das aktuelle Passwort. Admins können die Bestätigung per Schalter überspringen. Ausstehende Änderungen lassen sich verwerfen. Bestätigte Adressen (auch über `/cms-verify-email`) übernehmen Gastbestellungen mit gleicher Adresse. Limits: 5 Anfragen je Konto und Stunde, 3 Hinweise je Adresse und Stunde, 10 Bestätigungsversuche je IP in 15 min |
| OFFEN-23 | JWT nur durch Passwortänderung entwertet | ✅ 4.0.0 – Token-Version je Konto (`auth_token_version`), Teil des Fingerabdrucks von Sitzung und API-Token (`pwf`). „Überall abmelden“ im Mitgliederbereich (Passwort erforderlich) und unter *Benutzer → Bearbeiten* beendet alle anderen Sitzungen und alle API-Tokens; die eigene Sitzung bleibt angemeldet. Audit-Ereignis `sessions_revoked` |
| OFFEN-24 | Registrierung verrät vorhandene E-Mail-Adressen | ✅ 4.0.0 – Gleiche Erfolgsmeldung wie bei einer neuen Adresse, kein Konto. Der Inhaber erhält einen Hinweis (höchstens 3 je Stunde). Audit-Ereignis `register_existing_email`. Vergebene Benutzernamen werden weiterhin gemeldet. Vollständig neutral nur mit aktiver E-Mail-Verifizierung (siehe Grenzen) |
| OFFEN-25 | Login-Rate-Limit nur je IP | ✅ 4.0.0 – Zusätzlich je Konto (Aktion `login_account`, IP-unabhängig): fünf Fehlversuche in 15 min ohne Wartezeit, danach 1, 2, 4, 8, 16 und höchstens 30 s vor der Passwortprüfung. Keine harte Sperre. Eine erfolgreiche Anmeldung setzt den Zähler zurück. Unbekannte Namen verhalten sich gleich |
| OFFEN-26 | Backups unverschlüsselt im Webroot | ✅ 4.0.0 – `CMS_BACKUP_PATH` (absolut oder relativ zu `CMS/`); unzulässige Ziele werden abgelehnt. Optionale Verschlüsselung (libsodium secretstream, Schlüsselring wie oben); ohne sodium entsteht kein Backup statt eines unverschlüsselten. Neuer Audit-Prüfpunkt „Backup-Speicherort & Verschlüsselung“. Downloads bleiben verschlüsselt. Update-Vorprüfung mit dem konfigurierten Verzeichnis |
| OFFEN-27 | Core-Updatepakete nur per SHA-256 aus derselben Quelle geprüft | ⏳ offen – Signatur der Pakete (z. B. Ed25519 mit öffentlichem Schlüssel im Core). Umsetzung und Schlüsselverwaltung übernimmt der Maintainer |

### Neue Befunde (Regressionsprüfung)

Alle Befunde bestanden bereits im Paket 3.5.0 (Code identisch).

| ID | Befund | Schwere | Status |
|---|---|---|---|
| SEC-48 | Die Theme-Helfer `theme_login_user()` und `theme_register_user()` gaben Exception-Texte (z. B. SQL-Fehler) an Besucher aus. `theme_register_user()` prüfte E-Mail-Adresse und Benutzernamen selbst vor und meldete „E-Mail-Adresse oder Benutzername bereits vergeben“ – an der neutralen Registrierung vorbei (OFFEN-24). Das Standard-Theme ruft die Helfer nicht auf; betroffen sind eigene Vorlagen | ⚪ | ✅ 4.0.0 – nur `Auth::register()` bzw. `Auth::login()`, neutrale Meldungen, Ursache im PHP-Fehlerprotokoll (`functions.php` und `includes/theme-auth-helpers.php`) |
| FUN-55 | `cms_mail()` ignorierte `Content-Type: text/plain`. Klartext-Mails (Passwort-Reset, seit 4.0.0 auch Bestätigungsmails) gingen über SMTP und die Mail-Warteschlange als HTML hinaus; Zeilenumbrüche gingen verloren | 🟡 | ✅ 4.0.0 – `text/plain` wird über `MailService::sendPlain()` bzw. `MailQueueService::enqueuePlain()` versendet; der Content-Type kommt immer vom MailService |
| FUN-56 | Der `mail()`-Fallback (ohne SMTP) übergab Betreff und Absendername ohne RFC-2047-Kodierung; Umlaute kamen je nach Mailserver verstümmelt an | 🟡 | ✅ 4.0.0 – `=?UTF-8?B?…?=` für Betreff und Absendername, auch beim Versand mit Anhang |
| FUN-57 | Dashboard (Favoriten, Hinweise, Widgets) verlinkte Seiten deaktivierter Core-Module (Standard nach der Installation); deren Einstieg leitet auf die Startseite um | ⚪ | ✅ 4.0.0 – Links auf deaktivierte Seiten werden ersetzt (`/admin/security-audit` → `/admin/logs/security-audit`) oder ausgeblendet (`DashboardModule::filterAdminLinks()`) |
| FUN-61 | Der `mail()`-Fallback setzte kein `Content-Transfer-Encoding`. UTF-8-Text galt damit als 7bit, und HTML-Zeilen über 998 Zeichen konnten von Mailservern umbrochen werden (zerstörte Tags) | 🟡 | ✅ 4.0.0 – Body als `quoted-printable` mit CRLF; ein vom Aufrufer gesetztes Transfer-Encoding wird ersetzt |
| FUN-62 | Die interne Vorlage `CMS/member/plugin-section.php` war über `/member/:page` als `/member/plugin-section` direkt aufrufbar. Ohne Bereich entstanden PHP-Warnungen (bei `CMS_DEBUG` mit Serverpfad sichtbar), und der Hook `member_plugin_section_head` erhielt `null` statt des Bereichs | ⚪ | ✅ 4.0.0 – `MemberRouter::INTERNAL_MEMBER_TEMPLATES`, direkter Aufruf ergibt 404 (Theme- und Core-Vorlage) |

### Im Code-Review gefunden (nicht ausgeliefert)

Diese Fehler steckten in den Änderungen für OFFEN-20 bis OFFEN-26 und wurden vor der Veröffentlichung behoben.

| ID | Befund | Status |
|---|---|---|
| FUN-58 | Ein unzulässiger `CMS_BACKUP_PATH` wurde per `mkdir()` angelegt, bevor die Prüfung ihn ablehnte | ✅ Prüfung vor dem Anlegen (`BackupService::assertSafeBackupRoot()` mit `prospectiveRealPath()`) |
| FUN-59 | Die Migration alter CBC-Werte probierte alle früheren Schlüssel. CBC ohne MAC liefert mit falschem Schlüssel in rund 0,4 % der Fälle gültiges Padding; der entstandene Datenmüll wäre dauerhaft gespeichert worden | ✅ Altformat nur mit dem abgeleiteten Schlüssel; frühere Schlüssel nur bei genau einem plausiblen Ergebnis (gültiges UTF-8 ohne Steuerzeichen). Prüfung mit 300 Altwerten ohne Fehler |
| FUN-60 | `UserService::updateUser()` speicherte die übrigen Felder, auch wenn die E-Mail-Änderung scheiterte (z. B. Rate-Limit); das Formular meldete trotzdem einen Fehler | ✅ E-Mail-Änderung wird zuerst geprüft; bei Fehlern bleibt der Datensatz unverändert |
| BP-10 | Die einmalige Migration gespeicherter Geheimnisse schrieb je Schlüsselstand eine eigene Flag-Datei unter `cache/`; alte Flags blieben liegen | ✅ `Database::runSchemaOnce()` mit Parameter `$variant` (hier `CryptoService::keyRingId()`); Flags früherer Varianten werden wie alte Code-Stände entfernt |

### Kein Befund nach Prüfung

- **Korrekturen aus dem Folgeaudit vom 2026-11-08 erneut bestätigt:**
  - TOTP-Einrichtung nur mit Passwort (SEC-32)
  - Exportfilter (SEC-33), Installer-Sperre (SEC-34), Host-Allowlist (SEC-38)
  - E-Mail-Verifizierung (FUN-36), Benutzer anlegen (FUN-37), Backup-Prüfung und Wiederherstellung (FUN-39 bis FUN-43, SEC-46)
  - Rate-Limit-Ausnahme für Admins (FUN-54), Statusanzeigen (FUN-46, FUN-51, FUN-53)
- **Korrekturen aus den Audits vom 2026-10-14 erneut bestätigt:**
  - MFA-Brute-Force-Schutz und Replay-Schutz
  - eindeutiger Schlüssel auf `user_meta`
  - Passwortabfrage für sicherheitsrelevante Kontoaktionen
  - private Uploads, `runSchemaOnce()` statt Schemaprüfungen je Request
- **Neue Routen:** `/cms-confirm-email-change` steht wie `/cms-verify-email` in den Ausnahmen für Wartungsmodus und Seiten-Cache (`private`) und öffnet sofort eine Sitzung.
- **Konfiguration:** Das Neuschreiben von `config/app.php` unter *Einstellungen* übernimmt `CMS_ENCRYPTION_KEY`, `CMS_ENCRYPTION_KEYS_PREVIOUS` und `CMS_BACKUP_PATH`. Ein Verlust des Schlüssels wird so ausgeschlossen.

### Laufzeitprüfung

| Prüfung | Ergebnis |
|---|---|
| OFFEN-20 | TOTP-Einrichtung im Mitgliederbereich: `mfa_pending_secret` und `mfa_secret` als `enc:v2:x:…`, Backup-Codes erzeugt, neue Anmeldung über `/mfa-challenge`. Klartext-Secret aus 3.5.x → nach der Migration verschlüsselt, Code weiterhin gültig. In ein anderes Konto kopiertes Chiffrat → abgelehnt (Eintrag im Kanal `auth.mfa`) |
| OFFEN-21 | 300 Werte im Altformat (AES-256-CBC): 50 über den Lesepfad, der Rest per Massenmigration → 0 Fehler, 0 Altwerte übrig. Schlüsselwechsel: Werte mit Schlüssel A, dann neuer Schlüssel B mit A in `CMS_ENCRYPTION_KEYS_PREVIOUS` → beim Lesen und per Migration auf B umgestellt, danach ohne A lesbar. Fehlender Schlüssel → Fehler im Kanal `settings`, Seiten antworten weiter mit 200 |
| OFFEN-22 | Falsches Passwort abgelehnt. Anfrage → Erfolgsmeldung, `pending_email` gesetzt, Link an die neue Adresse, Hinweis an die alte. Link ohne Anmeldung → Adresse geändert, Gastbestellung dem Konto zugeordnet, Audit-Einträge. Zweiter Aufruf abgelehnt. Anmeldung mit der neuen Adresse möglich, mit der alten nicht |
| OFFEN-23 | Falsches Passwort abgelehnt. Mit Passwort: zweite Sitzung abgemeldet, eigene bleibt, `auth_token_version` erhöht, Audit `sessions_revoked`. Vorher ausgestelltes Refresh-Token → 401; neues Token aus der eigenen Sitzung → 200 |
| OFFEN-24 | Registrierung mit vorhandener Adresse (auch in anderer Schreibweise) → gleiche Erfolgsmeldung, kein neues Konto, Hinweis-Mail in der Warteschlange, Audit `register_existing_email`. Vergebener Benutzername → Fehlermeldung wie bisher. `theme_register_user()` verhält sich gleich |
| OFFEN-25 | Acht Fehlversuche nacheinander: Wartezeiten 0, 0, 0, 0, 0, 1, 2 und 4 s. Richtiges Passwort setzt den Zähler zurück. Unbekannter Benutzername gleiches Verhalten |
| OFFEN-26 | Verschlüsseltes Datenbank-Backup (`.sql.gz.enc` + Manifest): Prüfung, Wiederherstellung und Löschen OK. Download bleibt verschlüsselt; der in [BACKUP.md](../admin/system-settings/BACKUP.md) beschriebene Befehl entschlüsselt ihn. `CMS_BACKUP_PATH` außerhalb des Webroots → Voll-Backup OK, Prüfung 8/8, Audit „außerhalb des Webroots“. Pfad im CMS außerhalb von `backups/` → Fehlermeldung, kein Verzeichnis angelegt (FUN-58) |
| FUN-55, FUN-56, FUN-61 | Test-`sendmail`, direkt und über die Warteschlange: `Content-Transfer-Encoding: quoted-printable`, Zeilen höchstens 76 Zeichen, Umlaute nach dem Dekodieren korrekt, Betreff `=?UTF-8?B?…?=`, CRLF. Klartext-Mails mit `text/plain` |
| FUN-57 | Dashboard mit deaktivierten Core-Modulen: kein Link führt auf die Startseite |
| FUN-62 | `/member/plugin-section` → 404 ohne PHP-Warnung; `/member/profile` und `/member/security` → 200 |
| BP-10 | Flag `db_runtime_stored_secrets_<Präfix><CRC>_<Schlüsselring>.flag`; je Schlüsselstand ein Lauf; ein neuer Schlüsselstand entfernt das alte Flag |
| Admin | 102 Seiten ohne PHP-Fehler. Treffer der Suche nach „Warning“ stammen nur aus Protokollansichten und Statusbadges; reine POST-Endpunkte antworten mit 405, Alias-Seiten mit 302 |
| Mitgliederbereich, öffentliche Routen | ohne PHP-Fehler; PHP-Fehlerprotokoll unverändert |

### Grenzen der Umsetzung

- **OFFEN-24:** Ohne E-Mail-Verifizierung kann sich ein neues Konto sofort anmelden. Ob eine Adresse schon registriert war, lässt sich dann über einen Anmeldeversuch nach der Registrierung erkennen. Vollständig neutral ist die Registrierung nur mit *E-Mail-Verifizierung erzwingen*.
- **OFFEN-25:** Die Wartezeit belegt einen PHP-Prozess (höchstens 30 s je Anfrage). Viele parallele Versuche gegen viele Konten binden Prozesse; dagegen wirken das Login-Rate-Limit je IP und die Firewall.
- **OFFEN-22:** Der Bestätigungslink funktioniert ohne Anmeldung (wie `/cms-verify-email`). Er geht nur an die neue Adresse, und die Änderung selbst verlangt das Passwort.
- **OFFEN-26:**
  - `manifest.json` bleibt unverschlüsselt (Dateinamen, Größen, Prüfsummen, keine Inhalte).
  - Beim Prüfen und Wiederherstellen liegt der Klartext kurz in einem Arbeitsverzeichnis mit Rechten `0700`.
  - Die Rollback-Snapshots der Performance-Wartung bleiben unter `CMS/backups/performance-rollbacks/`.
  - Die vorhandenen Hilfsfunktionen für S3-Upload und Backup per E-Mail sind weiterhin nicht in der Oberfläche verdrahtet. Eine externe Ablage bleibt Aufgabe des Betreibers.

Bewusst ohne Änderung (vorhandenes Verhalten, kein Fehler):

- `/mfa-disable` erwartet ein Token, das kein Core-Formular erzeugt; die Route lehnt deshalb jede Anfrage ab. MFA wird im Mitgliederbereich bzw. unter *Benutzer* deaktiviert.
- Die Registrierung entfernt unzulässige Zeichen aus dem Benutzernamen, statt die Eingabe abzulehnen.
- `themes/cms-default/includes/*.php` werden vom Theme nicht geladen; die Helfer stehen zusätzlich in `functions.php`. Beide Fassungen wurden angeglichen.

### Hinweise für Betreiber

- **Schlüssel:** Neuinstallationen erhalten `CMS_ENCRYPTION_KEY` vom Installer. Bei bestehenden Installationen ändert das Update `config/app.php` nicht; der Schlüssel wird weiter aus `AUTH_KEY`/`SECURE_AUTH_KEY` abgeleitet.
  - Empfohlen ist ein eigener Schlüssel (z. B. `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`).
  - Gespeicherte Geheimnisse werden beim nächsten Aufruf automatisch umgeschlüsselt.
  - Den Schlüssel getrennt von Datenbank und Backups sichern; ohne ihn sind verschlüsselte Einstellungen, TOTP-Secrets und Backups verloren.
- **Schlüsselwechsel:** Den neuen Schlüssel in `CMS_ENCRYPTION_KEY` setzen und den bisherigen in `CMS_ENCRYPTION_KEYS_PREVIOUS` eintragen. Den alten Schlüssel dort lassen, solange verschlüsselte Backups mit ihm wiederherstellbar bleiben sollen.
- **Backups:** `CMS_BACKUP_PATH` außerhalb des Webroots setzen und unter *System → Backup & Restore* die Verschlüsselung einschalten (benötigt die PHP-Erweiterung sodium). Vorhandene Backups im alten Verzeichnis `CMS/backups/` per SFTP/SSH verschieben oder löschen; das Sicherheits-Audit nennt ihre Anzahl. Verschlüsselte Backups bleiben auch beim Download verschlüsselt.
- **E-Mail-Adressen:** Mitglieder ändern ihre Adresse nur noch mit Bestätigung. Admins können die Bestätigung unter *Benutzer → Bearbeiten* per Schalter überspringen, z. B. bei Tippfehlern. Bei aktiver Mail-Warteschlange muss der Cron laufen.
- **Registrierung:** Für eine vollständig neutrale Registrierung *E-Mail-Verifizierung erzwingen* einschalten.
- **Anmeldung:** Nach fünf Fehlversuchen in 15 Minuten verzögert die Anmeldung für dieses Konto. Hinweise im Protokoll („Login um … s verzögert“) zeigen gezielte Versuche.
- **Sitzungen:** „Überall abmelden“ beendet auch alle API-Tokens. Integrationen mit JWT müssen danach ein neues Token anfordern.
- **OPcache:** Nach dem Einspielen OPcache leeren bzw. PHP-FPM neu laden.

### Geänderte Dateien

- **Verschlüsselung (OFFEN-20, OFFEN-21, BP-10):**
  - neu `CMS/core/Services/CryptoService.php`
  - `CMS/core/Services/SettingsService.php`, `CMS/core/Auth/MFA/TotpAdapter.php`, `CMS/core/Auth.php`
  - `CMS/core/Bootstrap.php`, `CMS/core/Database.php`
  - `CMS/config/app.php`, `CMS/install/InstallerService.php`, `CMS/admin/modules/settings/SettingsModule.php`
- **E-Mail-Änderung (OFFEN-22):**
  - neu `CMS/core/Services/EmailChangeService.php`
  - `CMS/core/Services/UserService.php`, `CMS/core/Services/MemberService.php`, `CMS/core/Services/EmailVerificationService.php`
  - `CMS/core/Routing/PublicRouter.php`, `CMS/core/Router.php`, `CMS/index.php`
  - `CMS/member/profile.php`, `CMS/member/includes/class-member-controller.php`
  - `CMS/admin/users.php`, `CMS/admin/modules/users/UsersModule.php`, `CMS/admin/views/users/edit.php`
- **Sitzungen, Registrierung, Anmeldung (OFFEN-23 bis OFFEN-25):** `CMS/core/Auth.php`, `CMS/core/Security.php`, `CMS/member/security.php`, `CMS/member/includes/class-member-controller.php`, `CMS/admin/modules/users/UsersModule.php`
- **Backups (OFFEN-26, FUN-58):** `CMS/core/Services/BackupService.php`, `CMS/admin/modules/system/BackupsModule.php`, `CMS/admin/views/system/backups.php`, `CMS/admin/backups.php`, `CMS/admin/modules/security/SecurityAuditModule.php`, `CMS/core/Services/UpdateService.php`
- **Regressionsfunde:**
  - `CMS/includes/functions/mail.php`, `CMS/core/Services/MailService.php` (FUN-55, FUN-56, FUN-61)
  - `CMS/admin/modules/dashboard/DashboardModule.php` (FUN-57)
  - `CMS/core/Routing/MemberRouter.php` (FUN-62)
  - `CMS/themes/cms-default/functions.php`, `CMS/themes/cms-default/includes/theme-auth-helpers.php` (SEC-48)
- **Dokumentation:**
  - `DOC/admin/security/SECURITY-AUDIT.md`
  - `DOC/admin/system-settings/` (BACKUP, MAIL, README, SYSTEM, UPDATES)
  - `DOC/admin/users-groups/` (USERS, AUTH-SETTINGS)
  - `DOC/core/` (SECURITY, SERVICES, API-REFERENCE, DATABASE-SCHEMA, STRUCTURE, CORE-CLASSES, ARCHITECTURE, HOOKS-REFERENCE, README, STATUS)
  - `DOC/member/` (MEMBER-SECURITY, MEMBER-ROUTES, README)
  - `DOC/ai/AI-SERVICES.md`, `DOC/workflow/UPDATE-DEPLOYMENT-WORKFLOW.md`
  - `DOC/INSTALLATION.md`, `DOC/FILELIST.md`, `DOC/CMSFILESTRUCTUR.md`
  - dieses Dokument (`DOC/audit/FOLGEAUDIT-2026-11-09.md`)
