# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 3.5.0

## English

This release directory documents the distributable `3.5.0` packages (release date 2026-10-14, database schema `v23`). Both packages contain deployable code only. Installation-specific configuration, uploads, caches, logs and backups are intentionally excluded; the full package ships only the protective `.htaccess`/index files of those directories.

Highlights: proxy/CDN-aware client IP detection with one-click approval of unknown CDN networks (e.g. Hostinger), cookie-less cacheable anonymous pages (lazy sessions), feed cache, lighter search, static 404 for missing assets, all open recommendations of the 2026-10-14 audit and the fixes of the follow-up audit (SSRF filter, JWT bound to the password, working importer, fewer queries per page). See `Changelog.md` (v3.5.0), `DOC/audit/README.md` and `DOC/audit/FOLGEAUDIT-2026-10-14.md`.

## Deutsch

Dieses Release-Verzeichnis dokumentiert die auslieferbaren Pakete `3.5.0` (Release 14.10.2026, Datenbankschema `v23`). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code; installationsspezifische Konfiguration, Uploads, Cache, Logs und Backups sind bewusst ausgeschlossen. Das Vollpaket enthält aus diesen Verzeichnissen nur die schützenden `.htaccess`-/Index-Dateien.

- `365CMS-3.5.0-update.zip`: vollständiges Core-Updatepaket für den automatischen Core-Updater (Wurzelordner `CMS/`).
- `365CMS-3.5.0-full.zip`: sauberes Gesamtpaket für eine Neuinstallation. Nach dem Entpacken `CMS/install.php` aufrufen.
- `365CMS-3.5.0-update.json`: Update-Manifest inklusive SHA-256 für das Updatepaket.
- `365CMS-3.5.0-SHA256SUMS.txt`: Prüfsummen beider ZIP-Dateien.

### Bereitstellung des automatischen Updates

Das Updatepaket muss unter folgendem Pfad erreichbar sein:

`https://365cms.de/marketplace/core/365cms/365CMS-3.5.0-update.zip`

Die Datei `CMS/marketplace/core/365cms/update.json` muss am selben Marketplace-Pfad liegen. Nach dem Core-Update den Adminbereich neu laden und unter **Admin → Updates** bei Bedarf das idempotente Datenbankschema-Update auf `v23` ausführen.

Die automatische Installation setzt eine gültige SHA-256-Prüfsumme im Manifest voraus. Der Core-Swap bewahrt `config/`, `config.php`, `uploads/`, `cache/`, `logs/` und `backups/`.

### Nach dem Update prüfen

- **Proxy / CDN:** *Sicherheit → Firewall → Proxy / CDN & Client-IP* öffnen. Die Diagnose zeigt `REMOTE_ADDR`, Weiterleitungs-Header und die ermittelte Client-IP. Läuft die Seite hinter einem Host-CDN mit unbekannten Adressen (z. B. Hostinger), erscheint dort das Netz Ihres eigenen Aufrufs mit der Markierung **Ihre Anfrage** – mit **Vertrauen** übernehmen. Hinter einem lokalen Reverse Proxy (nginx, Docker) `private` eintragen. Standard ist jetzt `cloudflare`. Eine gesetzte Konstante `CMS_TRUSTED_PROXIES` hat Vorrang.
- **Seiten-Cache:** Anonyme Seiten kommen jetzt ohne Session-Cookie und mit `Cache-Control: public`. Wer das nicht möchte: `define('CMS_LAZY_SESSIONS', false);` in `config/app.php`.
- **nginx:** Regel für statische 404 aus `DOC/INSTALLATION.md` übernehmen (Apache nutzt die mitgelieferte `.htaccess`).
- **API-Clients:** JWTs sind jetzt an das Passwort gebunden (Claim `pwf`). Vor 3.5.0 ausgestellte Tokens werden abgelehnt; Clients holen über `POST /api/v1/auth/token` ein neues Token.
