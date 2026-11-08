# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 3.5.5

## English

This release directory documents the distributable `3.5.5` packages (release date 2026-11-08, database schema `v23`). Both packages contain deployable code only. Installation-specific configuration, uploads, caches, logs and backups are intentionally excluded; the full package ships only the protective `.htaccess`/index files of those directories.

Bugfix release on top of 3.5.4. The post view counter no longer overwrites `posts.updated_at` on every visit, so the admin list, the sitemap `lastmod` and `dateModified` show the last real change again. Saving without changes and bulk actions that do not change a value keep the date. A one-time repair resets already overwritten dates to the last revision (or publication date). See `Changelog.md` (v3.5.5).

## Deutsch

Dieses Release-Verzeichnis dokumentiert die auslieferbaren Pakete `3.5.5` (Release 08.11.2026, Datenbankschema `v23`). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code; installationsspezifische Konfiguration, Uploads, Cache, Logs und Backups sind bewusst ausgeschlossen. Das Vollpaket enthält aus diesen Verzeichnissen nur die schützenden `.htaccess`-/Index-Dateien.

Bugfix-Release auf Basis von 3.5.4. Der Aufrufzähler von Beiträgen überschreibt `posts.updated_at` nicht mehr bei jedem Besuch; Beitragsliste, Sitemap-`lastmod` und `dateModified` zeigen wieder die letzte echte Änderung. Speichern ohne Änderung und Bulk-Aktionen ohne geänderten Wert lassen das Datum unverändert. Eine einmalige Reparatur setzt bereits überschriebene Daten auf die letzte Revision (bzw. das Veröffentlichungsdatum) zurück. Details: `Changelog.md` (v3.5.5).

- `365CMS-3.5.5-update.zip`: vollständiges Core-Updatepaket für den automatischen Core-Updater (Wurzelordner `CMS/`).
- `365CMS-3.5.5-full.zip`: sauberes Gesamtpaket für eine Neuinstallation. Nach dem Entpacken `CMS/install.php` aufrufen.
- `365CMS-3.5.5-update.json`: Update-Manifest inklusive SHA-256 für das Updatepaket.
- `365CMS-3.5.5-SHA256SUMS.txt`: Prüfsummen beider ZIP-Dateien.

### Bereitstellung des automatischen Updates

Das Updatepaket muss unter folgendem Pfad erreichbar sein:

`https://365cms.de/marketplace/core/365cms/365CMS-3.5.5-update.zip`

Die Datei `CMS/marketplace/core/365cms/update.json` muss am selben Marketplace-Pfad liegen. Nach dem Core-Update den Adminbereich neu laden und unter **Admin → Updates** das idempotente Datenbank-Update ausführen (Schema bleibt `v23`, die installierte Version wird auf `3.5.5` gesetzt).

Die automatische Installation setzt eine gültige SHA-256-Prüfsumme im Manifest voraus. Der Core-Swap bewahrt `config/`, `config.php`, `uploads/`, `cache/`, `logs/` und `backups/`.

### Nach dem Update prüfen

- **Aktualisierungsdatum:** Einen Beitrag im Frontend aufrufen; in *Beiträge* bleibt die Spalte „Aktualisiert“ unverändert. Die einmalige Reparatur läuft mit der Laufzeit-Schemaprüfung beim ersten Request nach dem Update. Hinweis: Änderungen ohne Revision (z. B. nur Beitragsbild) lassen sich nicht rekonstruieren; diese Beiträge erhalten das Datum der letzten Revision bzw. Veröffentlichung.
- **Sitemap:** Nach dem Update die Sitemap neu erzeugen, damit `lastmod` die korrigierten Daten übernimmt.
- **OPcache:** Falls weiterhin der alte Stand ausgeführt wird, OPcache bzw. PHP-FPM neu starten (Hoster-Panel).
- **Upgrade von 3.4.x:** Zusätzlich die Hinweise aus `RELEASE/README-3.5.0.md` beachten (Proxy/CDN, Lazy Sessions, JWT-Bindung, nginx-Regel).
