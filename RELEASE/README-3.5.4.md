# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 3.5.4

## English

This release directory documents the distributable `3.5.4` packages (release date 2026-10-23, database schema `v23`). Both packages contain deployable code only. Installation-specific configuration, uploads, caches, logs and backups are intentionally excluded; the full package ships only the protective `.htaccess`/index files of those directories.

Performance and security release on top of 3.5.3. The font manager now downloads Google Fonts as WOFF2 instead of TTF (about 25 instead of 140 KiB per style): `CMS\Http\Client` no longer sends two `User-Agent` headers. Existing TTF/OTF fonts can be converted with “Als WOFF2 neu laden”. Local font CSS is rebuilt from an allow-list, the frontend preloads the primary WOFF2 file and versions the font CSS. See `Changelog.md` (v3.5.4).

## Deutsch

Dieses Release-Verzeichnis dokumentiert die auslieferbaren Pakete `3.5.4` (Release 23.10.2026, Datenbankschema `v23`). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code; installationsspezifische Konfiguration, Uploads, Cache, Logs und Backups sind bewusst ausgeschlossen. Das Vollpaket enthält aus diesen Verzeichnissen nur die schützenden `.htaccess`-/Index-Dateien.

Performance- und Sicherheits-Release auf Basis von 3.5.3. Der Font Manager lädt Google Fonts jetzt als WOFF2 statt TTF (ca. 25 statt 140 KiB pro Schriftschnitt): `CMS\Http\Client` sendet nicht mehr zwei `User-Agent`-Header. Bereits lokal gespeicherte TTF/OTF-Schriften lassen sich per „Als WOFF2 neu laden“ umstellen. Das lokale Font-CSS wird aus einer Positivliste neu aufgebaut, das Frontend lädt die Primärdatei per Preload vor und versioniert das Font-CSS. Details: `Changelog.md` (v3.5.4).

- `365CMS-3.5.4-update.zip`: vollständiges Core-Updatepaket für den automatischen Core-Updater (Wurzelordner `CMS/`).
- `365CMS-3.5.4-full.zip`: sauberes Gesamtpaket für eine Neuinstallation. Nach dem Entpacken `CMS/install.php` aufrufen.
- `365CMS-3.5.4-update.json`: Update-Manifest inklusive SHA-256 für das Updatepaket.
- `365CMS-3.5.4-SHA256SUMS.txt`: Prüfsummen beider ZIP-Dateien.

### Bereitstellung des automatischen Updates

Das Updatepaket muss unter folgendem Pfad erreichbar sein:

`https://365cms.de/marketplace/core/365cms/365CMS-3.5.4-update.zip`

Die Datei `CMS/marketplace/core/365cms/update.json` muss am selben Marketplace-Pfad liegen. Nach dem Core-Update den Adminbereich neu laden und unter **Admin → Updates** das idempotente Datenbank-Update ausführen (Schema bleibt `v23`, die installierte Version wird auf `3.5.4` gesetzt).

Die automatische Installation setzt eine gültige SHA-256-Prüfsumme im Manifest voraus. Der Core-Swap bewahrt `config/`, `config.php`, `uploads/`, `cache/`, `logs/` und `backups/`.

### Nach dem Update prüfen

- **Schriften:** Unter *Design → Font Manager* stehen ältere Google Fonts mit Badge „Legacy-Format“ (TTF). „Als WOFF2 neu laden“ ausführen; danach zeigt die Liste `WOFF2`, im Seitenquelltext erscheint `<link rel="preload" … type="font/woff2">`.
- **OPcache:** Falls weiterhin der alte Stand ausgeführt wird, OPcache bzw. PHP-FPM neu starten (Hoster-Panel).
- **Upgrade von 3.4.x:** Zusätzlich die Hinweise aus `RELEASE/README-3.5.0.md` beachten (Proxy/CDN, Lazy Sessions, JWT-Bindung, nginx-Regel).
