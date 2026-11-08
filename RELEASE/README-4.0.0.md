# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 4.0.0

## English

This release directory documents the distributable `4.0.0` packages (release date 2026-11-08, database schema `v23`). Both packages contain deployable code only. Installation-specific configuration, uploads, caches, logs and backups are intentionally excluded; the full package ships only the protective `.htaccess`/index files of those directories.

Security and bug-fix release on top of 3.5.6, based on the follow-up audit of 2026-11-08 (`DOC/audit/FOLGEAUDIT-2026-11-08.md`, 24 findings, all fixed). The update is marked as critical; install it promptly.

Fixed, among others:

- host header manipulation of script and asset URLs (cache poisoning);
- MFA enrolment without the password;
- access secrets in the GDPR export;
- a bypassable installer lock;
- SVG import through the WordPress importer (stored XSS);
- plugin dashboards that granted access for unknown capabilities;
- passkey sign-ins without user verification that skipped TOTP;
- user enumeration through login timing.

Bundled libraries were updated: DOMPurify 3.4.17, symfony/mime 8.1.10, symfony/yaml 8.1.11 and symfony/serializer 8.1.11.

The option *E-Mail-Verifizierung erzwingen* now takes effect: new registrations stay `unverified` until the confirmation link is opened. Also fixed: creating users under `/admin/users` failed on MySQL/MariaDB in strict mode, and the `critical` flag of update manifests was ignored. See `Changelog.md` (v4.0.0).

## Deutsch

Dieses Release-Verzeichnis dokumentiert die auslieferbaren Pakete `4.0.0` (Release 08.11.2026, Datenbankschema `v23`). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code; installationsspezifische Konfiguration, Uploads, Cache, Logs und Backups sind bewusst ausgeschlossen. Das Vollpaket enthält aus diesen Verzeichnissen nur die schützenden `.htaccess`-/Index-Dateien.

Sicherheits- und Korrekturrelease auf Basis von 3.5.6 nach dem Folgeaudit vom 08.11.2026 (`DOC/audit/FOLGEAUDIT-2026-11-08.md`, 24 Befunde, alle behoben). Das Update ist als kritisch markiert und sollte zeitnah installiert werden. Installationen mit 3.5.6 zeigen es noch als normales Update an, weil die rote Kennzeichnung kritischer Updates erst mit 4.0.0 kommt.

Behoben wurden unter anderem:

- manipulierbarer Host-Header in Script- und Asset-URLs (Cache-Poisoning);
- MFA-Einrichtung ohne Passwort;
- Zugangsgeheimnisse im DSGVO-Export;
- umgehbare Installer-Sperre;
- SVG-Import über den WordPress-Importer (gespeichertes XSS);
- Plugin-Dashboards, die unbekannte Capabilities freigaben;
- Passkey-Anmeldungen ohne User Verification, die TOTP übersprangen;
- Benutzer-Enumeration über die Antwortzeit der Anmeldung.

Aktualisierte Bibliotheken: DOMPurify 3.4.17, symfony/mime 8.1.10, symfony/yaml 8.1.11 und symfony/serializer 8.1.11.

Die Option *E-Mail-Verifizierung erzwingen* (`member_email_verification`) wirkt jetzt: Neue Registrierungen bleiben `unverified`, bis der Link aus der Bestätigungsmail geöffnet wurde. Außerdem behoben: „Benutzer anlegen“ unter `/admin/users` scheiterte auf MySQL/MariaDB im Strict-Mode, und das Feld `critical` im Update-Manifest wurde nicht ausgewertet. Details: `Changelog.md` (v4.0.0).

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
- **OPcache:** Falls weiterhin der alte Stand ausgeführt wird, OPcache bzw. PHP-FPM neu starten (Hoster-Panel).
- **Upgrade von 3.4.x:** Zusätzlich die Hinweise aus `RELEASE/README-3.5.0.md` beachten (Proxy/CDN, Lazy Sessions, JWT-Bindung, nginx-Regel).
