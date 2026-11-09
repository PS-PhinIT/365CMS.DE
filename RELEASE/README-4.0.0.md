# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 4.0.0

## English

This release directory documents the distributable `4.0.0` packages (release date 2026-11-08, database schema `v23`). Both packages contain deployable code only. Installation-specific configuration, uploads, caches, logs and backups are intentionally excluded; the full package ships only the protective `.htaccess`/index files of those directories.

Security and bug-fix release on top of 3.5.6, based on the follow-up audit of 2026-11-08 (`DOC/audit/FOLGEAUDIT-2026-11-08.md`, 44 findings, all fixed). A second pass on 2026-11-09 (`DOC/audit/FOLGEAUDIT-2026-11-09.md`) implemented the recommendations OFFEN-20 … OFFEN-26 and fixed six further defects that had existed since 3.5.x. The update is marked as critical; install it promptly.

Fixed, among others:

- host header manipulation of script and asset URLs (cache poisoning);
- MFA enrolment without the password;
- access secrets in the GDPR export;
- a bypassable installer lock;
- SVG import through the WordPress importer (stored XSS);
- plugin dashboards that granted access for unknown capabilities;
- passkey sign-ins without user verification that skipped TOTP;
- user enumeration through login timing;
- restores that accepted modified or incomplete backups;
- copies of `config/app.php` (with database credentials) on every settings save, unlimited and readable by other local accounts.

Bundled libraries were updated: DOMPurify 3.4.17, symfony/mime 8.1.10, symfony/yaml 8.1.11 and symfony/serializer 8.1.11.

The option *E-Mail-Verifizierung erzwingen* now takes effect: new registrations stay `unverified` until the confirmation link is opened. Also fixed: creating users under `/admin/users` failed on MySQL/MariaDB in strict mode, and the `critical` flag of update manifests was ignored.

Backups and status displays were reviewed as well. The security audit no longer reports existing backups as missing, and it warns while the firewall or anti-spam is switched off. Large sites can restore their own file backups again. The firewall rate limit no longer locks out signed-in administrators or IPs on the allow list. Dashboard, diagnostics and SEO pages no longer show wrong values for failed logins, HTTPS, sitemaps and `robots.txt`. See `Changelog.md` (v4.0.0).

Added in the second pass:

- authenticated encryption of stored secrets and TOTP secrets with a key ring (`CMS_ENCRYPTION_KEY`, `CMS_ENCRYPTION_KEYS_PREVIOUS`);
- e-mail changes take effect only after the new address is confirmed;
- "sign out everywhere" ends all other sessions and all API tokens (JWT);
- registration no longer reveals registered e-mail addresses;
- login throttling per account (1 to 30 s after five failed attempts);
- backup directory outside the web root (`CMS_BACKUP_PATH`) and optional backup encryption.

Also fixed: plain-text mails sent as HTML, missing header and transfer encoding in the `mail()` fallback, dashboard links to disabled modules, exception texts in theme helpers, and the directly reachable template `/member/plugin-section`.

## Deutsch

Dieses Release-Verzeichnis dokumentiert die auslieferbaren Pakete `4.0.0` (Release 08.11.2026, Datenbankschema `v23`). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code; installationsspezifische Konfiguration, Uploads, Cache, Logs und Backups sind bewusst ausgeschlossen. Das Vollpaket enthält aus diesen Verzeichnissen nur die schützenden `.htaccess`-/Index-Dateien.

Sicherheits- und Korrekturrelease auf Basis von 3.5.6 nach dem Folgeaudit vom 08.11.2026 (`DOC/audit/FOLGEAUDIT-2026-11-08.md`, 44 Befunde, alle behoben). Ein zweiter Durchgang am 09.11.2026 (`DOC/audit/FOLGEAUDIT-2026-11-09.md`) setzt die Empfehlungen OFFEN-20 bis OFFEN-26 um und behebt sechs weitere Fehler, die seit 3.5.x bestanden. Das Update ist als kritisch markiert und sollte zeitnah installiert werden. Installationen mit 3.5.6 zeigen es noch als normales Update an, weil die rote Kennzeichnung kritischer Updates erst mit 4.0.0 kommt.

Behoben wurden unter anderem:

- manipulierbarer Host-Header in Script- und Asset-URLs (Cache-Poisoning);
- MFA-Einrichtung ohne Passwort;
- Zugangsgeheimnisse im DSGVO-Export;
- umgehbare Installer-Sperre;
- SVG-Import über den WordPress-Importer (gespeichertes XSS);
- Plugin-Dashboards, die unbekannte Capabilities freigaben;
- Passkey-Anmeldungen ohne User Verification, die TOTP übersprangen;
- Benutzer-Enumeration über die Antwortzeit der Anmeldung;
- Wiederherstellung veränderter oder unvollständiger Backups;
- Kopien von `config/app.php` (mit DB-Zugangsdaten) bei jedem Speichern der Einstellungen, unbegrenzt und für andere lokale Konten lesbar.

Aktualisierte Bibliotheken: DOMPurify 3.4.17, symfony/mime 8.1.10, symfony/yaml 8.1.11 und symfony/serializer 8.1.11.

Die Option *E-Mail-Verifizierung erzwingen* (`member_email_verification`) wirkt jetzt: Neue Registrierungen bleiben `unverified`, bis der Link aus der Bestätigungsmail geöffnet wurde. Außerdem behoben: „Benutzer anlegen“ unter `/admin/users` scheiterte auf MySQL/MariaDB im Strict-Mode, und das Feld `critical` im Update-Manifest wurde nicht ausgewertet.

Außerdem wurden Backups und Statusanzeigen geprüft. Das Sicherheits-Audit meldet vorhandene Backups nicht mehr als fehlend und warnt, solange Firewall oder Anti-Spam ausgeschaltet sind. Große Installationen können ihre Datei-Backups wieder einspielen. Das Rate-Limit der Firewall sperrt angemeldete Administratoren und freigegebene IPs nicht mehr aus. Dashboard, Diagnose und SEO-Seiten zeigen keine falschen Werte mehr zu fehlgeschlagenen Anmeldungen, HTTPS, Sitemaps und `robots.txt`. Details: `Changelog.md` (v4.0.0).

Neu im zweiten Durchgang:

- authentifizierte Verschlüsselung gespeicherter Geheimnisse und der TOTP-Secrets mit Schlüsselring (`CMS_ENCRYPTION_KEY`, `CMS_ENCRYPTION_KEYS_PREVIOUS`);
- geänderte E-Mail-Adressen gelten erst nach Bestätigung der neuen Adresse;
- „Überall abmelden“ beendet alle anderen Sitzungen und alle API-Tokens (JWT);
- die Registrierung verrät keine vorhandenen E-Mail-Adressen mehr;
- Anmeldeverzögerung je Konto (1 bis 30 s nach fünf Fehlversuchen);
- Backup-Verzeichnis außerhalb des Webroots (`CMS_BACKUP_PATH`) und optionale Backup-Verschlüsselung.

Außerdem behoben: Klartext-Mails wurden als HTML versendet, beim Versand über `mail()` fehlten Header- und Transfer-Kodierung, das Dashboard verlinkte deaktivierte Module, Theme-Helfer zeigten Exception-Texte, und die interne Vorlage `/member/plugin-section` war direkt aufrufbar.

- `365CMS-4.0.0-update.zip`: vollständiges Core-Updatepaket für den automatischen Core-Updater (Wurzelordner `CMS/`).
- `365CMS-4.0.0-full.zip`: sauberes Gesamtpaket für eine Neuinstallation. Nach dem Entpacken `CMS/install.php` aufrufen.
- `365CMS-4.0.0-update.json`: Update-Manifest inklusive SHA-256 für das Updatepaket.
- `365CMS-4.0.0-SHA256SUMS.txt`: Prüfsummen beider ZIP-Dateien.

### Bereitstellung des automatischen Updates

Das Updatepaket muss unter folgendem Pfad erreichbar sein:

`https://365cms.de/marketplace/core/365cms/365CMS-4.0.0-update.zip`

Die Datei `CMS/marketplace/core/365cms/update.json` muss am selben Marketplace-Pfad liegen. Nach dem Core-Update den Adminbereich neu laden und unter **Admin → Updates** das idempotente Datenbank-Update ausführen (Schema bleibt `v23`, die installierte Version wird auf `4.0.0` gesetzt).

Die automatische Installation setzt eine gültige SHA-256-Prüfsumme im Manifest voraus. Der Core-Swap bewahrt `config/`, `config.php`, `uploads/`, `cache/`, `logs/` und `backups/`.

### Nach dem Update prüfen

- **Hostname / Proxy:** Script- und Asset-URLs verwenden nur noch den Host aus `SITE_URL`, dessen `www`-Variante oder eine Hub-Domain. `SITE_URL` muss die öffentliche Adresse enthalten. Hinter Reverse-Proxy oder CDN gelten `X-Forwarded-Host` und `X-Forwarded-Proto` nur, wenn der Proxy als vertrauenswürdig eingetragen ist (*Sicherheit → Firewall → Proxy / CDN & Client-IP* oder `CMS_TRUSTED_PROXIES`).
- **Cache:** Seiten- und CDN-Cache leeren, damit keine vorher manipulierten Einträge ausgeliefert werden.
- **MFA:** Das Einrichten einer Authenticator-App verlangt jetzt das aktuelle Passwort. Bei aktiver MFA verlangen Passkeys ohne PIN/Biometrie zusätzlich den TOTP-Code.
- **E-Mail-Verifizierung:** Vor dem Einschalten der Option den Mailversand prüfen. Bei aktiver Mail-Warteschlange muss der Cron laufen. Bestehende Konten bleiben unverändert; unbestätigte Konten lassen sich in der Benutzerliste über *Aktivieren* freischalten.
- **Benutzer anlegen:** Unter `/admin/users` einen Testbenutzer anlegen. Das Speichern muss ohne „Datenbankfehler beim Erstellen“ gelingen.
- **Plugin-Dashboards:** Bereiche mit unbekannter `capability` sind nicht mehr für alle Mitglieder sichtbar. Plugins verwenden Rollennamen (`member`, `editor`, `admin`) oder echte Capabilities.
- **Installer:** `config/install.lock` muss vorhanden sein; `install.php` bleibt dann gesperrt.
- **Importer:** Vor 4.0.0 importierte SVG-Dateien unter `uploads/images/importer/` prüfen und bei Bedarf entfernen.
- **Sicherheits-Audit:** *Sicherheit → Audit* erneut ausführen. Neue Warnungen zu Firewall oder Anti-Spam bedeuten, dass der Schutz ausgeschaltet ist (Standard nach der Installation).
- **Anti-Spam:** Wurde das Formular vor 4.0.0 gespeichert, Honeypot und User-Agent-Prüfung kontrollieren; sie wurden dabei ausgeschaltet.
- **Backups:** Unter *System → Backup & Restore* als „Unvollständig“ markierte Backups prüfen und löschen. Datei-Backups enthalten `assets/` nicht mehr. Unter nginx `backups/`, `logs/` und `config/` per Server-Regel sperren.
- **Verschlüsselungsschlüssel:** Das Update ändert `config/app.php` nicht; Geheimnisse werden bis dahin mit dem aus `AUTH_KEY`/`SECURE_AUTH_KEY` abgeleiteten Schlüssel verschlüsselt. Empfohlen ist ein eigener Schlüssel: `defined('CMS_ENCRYPTION_KEY') || define('CMS_ENCRYPTION_KEY', '…');` mit 64 Hex-Zeichen (z. B. aus `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`). Gespeicherte Geheimnisse werden beim nächsten Aufruf umgeschlüsselt. Den Schlüssel getrennt von Datenbank und Backups sichern; ohne ihn sind verschlüsselte Einstellungen, TOTP-Secrets und Backups verloren. Für einen späteren Schlüsselwechsel den bisherigen Schlüssel in `CMS_ENCRYPTION_KEYS_PREVIOUS` eintragen.
- **Backup-Speicherort:** `CMS_BACKUP_PATH` außerhalb des Webroots setzen und unter *System → Backup & Restore* die Verschlüsselung einschalten (PHP-Erweiterung sodium). Ältere Backups unter `CMS/backups/` per SFTP/SSH verschieben oder löschen; das Sicherheits-Audit nennt ihre Anzahl.
- **E-Mail-Änderung:** Geänderte Adressen gelten erst nach dem Bestätigungslink. Bei aktiver Mail-Warteschlange muss der Cron laufen. Admins können die Bestätigung unter *Benutzer → Bearbeiten* überspringen.
- **Registrierung und Anmeldung:** Für eine vollständig neutrale Registrierung *E-Mail-Verifizierung erzwingen* einschalten. Nach fünf Fehlversuchen in 15 Minuten verzögert die Anmeldung für das betroffene Konto um bis zu 30 s.
- **API-Tokens:** „Überall abmelden“ beendet auch alle JWTs des Kontos; Integrationen fordern danach ein neues Token an.
- **Konfigurationskopien:** Unter `config/` bleiben nur die fünf neuesten `app.backup-*.php`; ältere entfernt das nächste Speichern mit Änderung. Kopien aus Versionen vor 3.4.12 (`app.php.backup.*`) manuell löschen.
- **OPcache:** Falls weiterhin der alte Stand ausgeführt wird, OPcache bzw. PHP-FPM neu starten (Hoster-Panel).
- **Upgrade von 3.4.x:** Zusätzlich die Hinweise aus `RELEASE/README-3.5.0.md` beachten (Proxy/CDN, Lazy Sessions, JWT-Bindung, nginx-Regel).
