# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 3.5.3

## English

This release directory documents the distributable `3.5.3` packages (release date 2026-10-23, database schema `v23`). Both packages contain deployable code only. Installation-specific configuration, uploads, caches, logs and backups are intentionally excluded; the full package ships only the protective `.htaccess`/index files of those directories.

Feature release on top of 3.5.2. Comments can now be edited afterwards in the admin area (author name and text, capability `comments.moderate`), for example to remove sensitive information. Edited comments show “Bearbeitet vom Administrator” on the website. The new columns `comments.edited_at` and `comments.edited_by` are added automatically by the runtime schema check. See `Changelog.md` (v3.5.3).

## Deutsch

Dieses Release-Verzeichnis dokumentiert die auslieferbaren Pakete `3.5.3` (Release 23.10.2026, Datenbankschema `v23`). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code; installationsspezifische Konfiguration, Uploads, Cache, Logs und Backups sind bewusst ausgeschlossen. Das Vollpaket enthält aus diesen Verzeichnissen nur die schützenden `.htaccess`-/Index-Dateien.

Feature-Release auf Basis von 3.5.2. Kommentare lassen sich im Adminbereich nachträglich bearbeiten (Autorname und Text, Recht `comments.moderate`), z. B. um sensible Angaben zu entfernen oder eigene Kommentare zu ergänzen. Bearbeitete Kommentare zeigen auf der Website „Bearbeitet vom Administrator“. Die neuen Spalten `comments.edited_at` und `comments.edited_by` ergänzt die Laufzeit-Schemaprüfung automatisch. Details: `Changelog.md` (v3.5.3).

- `365CMS-3.5.3-update.zip`: vollständiges Core-Updatepaket für den automatischen Core-Updater (Wurzelordner `CMS/`).
- `365CMS-3.5.3-full.zip`: sauberes Gesamtpaket für eine Neuinstallation. Nach dem Entpacken `CMS/install.php` aufrufen.
- `365CMS-3.5.3-update.json`: Update-Manifest inklusive SHA-256 für das Updatepaket.
- `365CMS-3.5.3-SHA256SUMS.txt`: Prüfsummen beider ZIP-Dateien.

### Bereitstellung des automatischen Updates

Das Updatepaket muss unter folgendem Pfad erreichbar sein:

`https://365cms.de/marketplace/core/365cms/365CMS-3.5.3-update.zip`

Die Datei `CMS/marketplace/core/365cms/update.json` muss am selben Marketplace-Pfad liegen. Nach dem Core-Update den Adminbereich neu laden und unter **Admin → Updates** das idempotente Datenbank-Update ausführen (Schema bleibt `v23`, die installierte Version wird auf `3.5.3` gesetzt).

Die automatische Installation setzt eine gültige SHA-256-Prüfsumme im Manifest voraus. Der Core-Swap bewahrt `config/`, `config.php`, `uploads/`, `cache/`, `logs/` und `backups/`.

### Nach dem Update prüfen

- **Kommentare:** Unter *Seiten & Beiträge → Kommentare* bei einem Kommentar *Aktionen → Bearbeiten* wählen, Text ändern und speichern. Auf der Website erscheint unter dem Kommentar „Bearbeitet vom Administrator“.
- **OPcache:** Falls weiterhin der alte Stand ausgeführt wird, OPcache bzw. PHP-FPM neu starten (Hoster-Panel).
- **Upgrade von 3.4.x:** Zusätzlich die Hinweise aus `RELEASE/README-3.5.0.md` beachten (Proxy/CDN, Lazy Sessions, JWT-Bindung, nginx-Regel).
