# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 3.5.6

## English

This release directory documents the distributable `3.5.6` packages (release date 2026-11-08, database schema `v23`). Both packages contain deployable code only. Installation-specific configuration, uploads, caches, logs and backups are intentionally excluded; the full package ships only the protective `.htaccess`/index files of those directories.

Feature release on top of 3.5.5. The new admin area **SEO → Suchmaschinen** (`/admin/seo-search-engines`) groups all search-engine settings that were spread over *Sitemap*, *Technisches SEO* and *Analytics*. It covers IndexNow, the Google Search Console property, the Google Indexing API, and verification meta tags for Google, Bing, Yandex, Seznam, Naver and Baidu.

The IndexNow key can be generated in the admin UI with PHP (`random_bytes()`) and its key file can be written to the web root. Submissions follow the protocol: one JSON `POST` to `api.indexnow.org` with `host`, `key`, `keyLocation` and `urlList`. Automatic submission on save, with an hourly cron retry, is available as an opt-in.

The defunct sitemap ping was removed. Also fixed: `noindex`/`nofollow` could not be saved in the post/page editor. See `Changelog.md` (v3.5.6).

## Deutsch

Dieses Release-Verzeichnis dokumentiert die auslieferbaren Pakete `3.5.6` (Release 08.11.2026, Datenbankschema `v23`). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code; installationsspezifische Konfiguration, Uploads, Cache, Logs und Backups sind bewusst ausgeschlossen. Das Vollpaket enthält aus diesen Verzeichnissen nur die schützenden `.htaccess`-/Index-Dateien.

Feature-Release auf Basis von 3.5.5. Der neue Bereich **SEO → Suchmaschinen** (`/admin/seo-search-engines`) bündelt alle suchmaschinenbezogenen Einstellungen, die bisher auf *Sitemap*, *Technisches SEO* und *Analytics* verteilt waren. Dazu gehören IndexNow, die Google-Search-Console-Property, die Google Indexing API und die Bestätigungs-Meta-Tags für Google, Bing, Yandex, Seznam, Naver und Baidu.

Der IndexNow-Key lässt sich direkt in der Oberfläche erzeugen (PHP, `random_bytes()`), und die Keydatei kann im Web-Root abgelegt werden. Meldungen gehen gemäß Protokoll als ein JSON-`POST` mit `host`, `key`, `keyLocation` und `urlList` an `api.indexnow.org`. Optional werden gespeicherte Inhalte automatisch gemeldet; der stündliche Cron wiederholt fehlgeschlagene Meldungen.

Der abgeschaltete Sitemap-Ping entfällt. Außerdem behoben: `noindex`/`nofollow` ließ sich im Beitrags- und Seiten-Editor nicht speichern. Details: `Changelog.md` (v3.5.6).

- `365CMS-3.5.6-update.zip`: vollständiges Core-Updatepaket für den automatischen Core-Updater (Wurzelordner `CMS/`).
- `365CMS-3.5.6-full.zip`: sauberes Gesamtpaket für eine Neuinstallation. Nach dem Entpacken `CMS/install.php` aufrufen.
- `365CMS-3.5.6-update.json`: Update-Manifest inklusive SHA-256 für das Updatepaket.
- `365CMS-3.5.6-SHA256SUMS.txt`: Prüfsummen beider ZIP-Dateien.

### Bereitstellung des automatischen Updates

Das Updatepaket muss unter folgendem Pfad erreichbar sein:

`https://365cms.de/marketplace/core/365cms/365CMS-3.5.6-update.zip`

Die Datei `CMS/marketplace/core/365cms/update.json` muss am selben Marketplace-Pfad liegen. Nach dem Core-Update den Adminbereich neu laden und unter **Admin → Updates** das idempotente Datenbank-Update ausführen (Schema bleibt `v23`, die installierte Version wird auf `3.5.6` gesetzt).

Die automatische Installation setzt eine gültige SHA-256-Prüfsumme im Manifest voraus. Der Core-Swap bewahrt `config/`, `config.php`, `uploads/`, `cache/`, `logs/` und `backups/`.

### Nach dem Update prüfen

- **Suchmaschinen:** Unter *SEO → Suchmaschinen* stehen IndexNow-Key, Search-Console-Property und Google-Token wie bisher; die Werte werden übernommen.
- **IndexNow-Key:** Ist noch kein Key vorhanden, über *IndexNow-Key erzeugen* anlegen. Die Karte *IndexNow-Prüfung* muss den Key als gültig zeigen, und `https://<domain>/<key>.txt` muss den Key liefern. Ist das Root-Verzeichnis nicht beschreibbar, liefert das CMS die Keydatei dynamisch aus.
- **Automatische Meldung:** Sie ist standardmäßig aus. Nach dem Einschalten zeigt die Karte *Automatische Meldung* Warteschlange und Ergebnis des letzten Laufs. Für das Nachmelden geplanter Beiträge muss der stündliche Cron laufen.
- **Website-Verifizierung:** Codes aus den Webmaster-Tools eintragen und im Quelltext der Startseite prüfen, ob die Meta-Tags (z. B. `google-site-verification`, `msvalidate.01`) erscheinen.
- **noindex:** Beiträge, die vor 3.5.6 auf `noindex` gestellt werden sollten, im Editor erneut prüfen und speichern.
- **OPcache:** Falls weiterhin der alte Stand ausgeführt wird, OPcache bzw. PHP-FPM neu starten (Hoster-Panel).
- **Upgrade von 3.4.x:** Zusätzlich die Hinweise aus `RELEASE/README-3.5.0.md` beachten (Proxy/CDN, Lazy Sessions, JWT-Bindung, nginx-Regel).
