# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 3.5.2

## English

This release directory documents the distributable `3.5.2` packages (release date 2026-10-17, database schema `v23`, unchanged). Both packages contain deployable code only. Installation-specific configuration, uploads, caches, logs and backups are intentionally excluded; the full package ships only the protective `.htaccess`/index files of those directories.

Bugfix release on top of 3.5.0. It ships the 3.5.1 fix for saving pages (HTTP 500 caused by a `TypeError` in `PageTemplateService::encodeMetadata()`), which had only been merged into the repository while version and update manifests still announced 3.5.0. In addition, unexpected errors in admin actions are now logged (channel `admin.section_shell`) and shown as a notice instead of ending in HTTP 500. See `Changelog.md` (v3.5.1, v3.5.2).

## Deutsch

Dieses Release-Verzeichnis dokumentiert die auslieferbaren Pakete `3.5.2` (Release 17.10.2026, Datenbankschema unverändert `v23`). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code; installationsspezifische Konfiguration, Uploads, Cache, Logs und Backups sind bewusst ausgeschlossen. Das Vollpaket enthält aus diesen Verzeichnissen nur die schützenden `.htaccess`-/Index-Dateien.

Bugfix-Release auf Basis von 3.5.0. Es liefert den Fix aus 3.5.1 für das Speichern von Seiten aus (HTTP 500 durch `TypeError` in `PageTemplateService::encodeMetadata()`); dieser lag bisher nur im Repository, während Version und Update-Manifeste weiter 3.5.0 meldeten. Zusätzlich werden unerwartete Fehler in Admin-Aktionen im Kanal `admin.section_shell` protokolliert und als Hinweis angezeigt statt mit HTTP 500 zu enden. Details: `Changelog.md` (v3.5.1, v3.5.2).

- `365CMS-3.5.2-update.zip`: vollständiges Core-Updatepaket für den automatischen Core-Updater (Wurzelordner `CMS/`).
- `365CMS-3.5.2-full.zip`: sauberes Gesamtpaket für eine Neuinstallation. Nach dem Entpacken `CMS/install.php` aufrufen.
- `365CMS-3.5.2-update.json`: Update-Manifest inklusive SHA-256 für das Updatepaket.
- `365CMS-3.5.2-SHA256SUMS.txt`: Prüfsummen beider ZIP-Dateien.

### Bereitstellung des automatischen Updates

Das Updatepaket muss unter folgendem Pfad erreichbar sein:

`https://365cms.de/marketplace/core/365cms/365CMS-3.5.2-update.zip`

Die Datei `CMS/marketplace/core/365cms/update.json` muss am selben Marketplace-Pfad liegen. Nach dem Core-Update den Adminbereich neu laden und unter **Admin → Updates** das idempotente Datenbank-Update ausführen (Schema bleibt `v23`, die installierte Version wird auf `3.5.2` gesetzt).

Die automatische Installation setzt eine gültige SHA-256-Prüfsumme im Manifest voraus. Der Core-Swap bewahrt `config/`, `config.php`, `uploads/`, `cache/`, `logs/` und `backups/`.

### Nach dem Update prüfen

- **Seiten:** Unter *Seiten → Neue Seite* eine Seite mit der Vorlage „Standard“ speichern – sie wird angelegt und der Editor öffnet sich mit dem Hinweis „Seite erstellt.“.
- **OPcache:** Falls weiterhin der alte Stand ausgeführt wird, OPcache bzw. PHP-FPM neu starten (Hoster-Panel).
- **Upgrade von 3.4.x:** Zusätzlich die Hinweise aus `RELEASE/README-3.5.0.md` beachten (Proxy/CDN, Lazy Sessions, JWT-Bindung, nginx-Regel).
