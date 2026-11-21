# 365CMS Projektdokumentation | Admin: Performance

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Stable
> **Routen:** `/admin/performance`, `-cache`, `-media`, `-database`, `-settings`, `-sessions` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_performance` | **Core-Modul:** `performance`

## English (summary)

All performance screens are rendered by `CMS/admin/performance-page.php` with the section module `CMS/admin/modules/seo/PerformanceModule.php` and views in `CMS/admin/views/performance/`. They cover file/APCu cache and OPcache, browser and HTML page cache, WebP conversion of existing media, database optimisation/repair, PHP sessions and session lifetimes. Destructive maintenance (cache cleanup, database maintenance, WebP conversion) creates a snapshot in `CMS/backups/performance-rollbacks/` that can be rolled back within one hour (`PerformanceSafetyNetService`).

## Deutsch

### Seiten und Aktionen

| Route | Bereich | Aktionen |
|---|---|---|
| `/admin/performance` | Übersicht: Cache-Status, OPcache, Datenbankgröße, Speicherplatz, Serverlast, Medien, Sessions, letzte Wartungsereignisse | – |
| `/admin/performance-cache` | Cache-Verwaltung | `clear_all_cache`, `clear_file_cache`, `rollback_cache_cleanup`, `clear_opcache`, `warmup_opcache`, `save_cache_settings` |
| `/admin/performance-media` | Medien-Optimierung | `convert_media_to_webp`, `rollback_webp_conversion`, `save_media_settings` |
| `/admin/performance-database` | Datenbank-Wartung | `optimize_database`, `repair_tables`, `rollback_database_maintenance` |
| `/admin/performance-sessions` | Session-Verwaltung | `clear_expired_sessions`, `save_session_settings` |
| `/admin/performance-settings` | Alle Einstellungen gesammelt | `save_settings` |

### Einstellungen

| Option | Standard | Bedeutung |
|---|---|---|
| `perf_page_cache` | an | Öffentliche Cache-Header (`Cache-Control: public, max-age=…`) für HTML-Seiten anonymer Besucher: nutzbar durch Browser, Reverse-Proxy oder CDN. Admin-, Member- und angemeldete Anfragen erhalten immer `private` |
| `perf_html_cache_ttl` | 300 s | `max-age` dieser HTML-Antworten; `0` = `no-cache` |
| `perf_browser_cache` | an | `Cache-Control`-Header für statische Dateien und Uploads |
| `perf_browser_cache_ttl` | 604800 (7 Tage) | wählbar: 3 Tage, 7 Tage, 31 Tage |
| `perf_auto_clear_content_cache` | an | Cache nach dem Speichern von Inhalten automatisch leeren |
| `perf_lazy_loading` | an | `loading="lazy"` für Bilder |
| `perf_lazy_loading_eager_images` | 1 | Anzahl (0–5) der ersten Bilder am Seitenanfang, die sofort geladen werden (Schutz für Hero-/LCP-Bilder) |
| `perf_minify_css` / `perf_minify_js` | aus | Minifizierte Auslieferung über `AssetOptimizerService` |
| `perf_webp_uploads` | an | WebP-Variante beim Upload |
| `perf_strip_exif` | an | Metadaten (EXIF, GPS, XMP, IPTC) beim Upload entfernen; entspricht `strip_exif` der Medien-Einstellungen (Verhalten je Upload-Weg: `admin/media/MEDIA.md`, Abschnitt „Metadaten, Ausrichtung und Transparenz“) |
| `perf_session_timeout_admin` | 28800 s (8 h) | Session-Lebensdauer für Administratoren |
| `perf_session_timeout_member` | 2592000 s (30 Tage) | Session-Lebensdauer für Mitglieder |

### Cache

- `CMS\CacheManager` arbeitet mit Dateicache in `CMS/cache/` und: falls verfügbar, **APCu** als schneller L1-Cache.
- `clear_all_cache` leert Datei- und APCu-Cache (`CacheManager::clearAll()`); `clear_file_cache` nur die Dateien in `CMS/cache/`.
- `CacheManager::clear()` (automatisch nach dem Speichern von Inhalten, Admin-Toolbar) und `clearAll()` löschen nur die eigenen Einträge (`<sha512>.cache`) und die APCu-Schlüssel mit Präfix `365cms:`. Schema- und Laufzeit-Flags (`db_*.flag`, `platform_ok_*.flag`), Sperrdateien und Einträge anderer Anwendungen im APCu-Pool bleiben erhalten. Zum erneuten Schema-Abgleich dient weiterhin `SchemaManager::clearFlag()`.
- `clear_opcache` setzt den PHP-OPcache zurück, `warmup_opcache` kompiliert die meistgenutzten Dateien vor (`OpcacheWarmupService`; wird nach Deployments auch automatisch angestoßen).
- Antwort-Header steuert `CacheManager::sendResponseHeaders()` (`public`/`private`, ETag/Last-Modified über `sendConditionalHeaders()`).
- ETag und Last-Modified öffentlicher Seiten und Hub-Sites enthalten neben `updated_at` die CMS-Version und die seitenweite Inhaltsversion (Option `site_content_version`, Unix-Zeit). Sie steigt nach jeder schreibenden Admin-Anfrage (Einstellungen, Menüs, Theme, Plugins), nach `CacheManager::clear()`/`clearAll()`, nach dem Speichern oder Löschen von Inhalten und bei geplanten Veröffentlichungen. Browser und CDNs bekommen danach kein 304 mit altem Seitenrahmen mehr. Liegt `If-None-Match` vor, entscheidet nur das ETag (auch mit Endung `-gzip`/`-br` von mod_deflate/mod_brotli).
- Statische Bilder unter `uploads/` sind nicht mehr `immutable` (1 Tag, danach Revalidierung), weil „Datei ersetzen“ die URL beibehält. Eigene CSS/JS-Dateien unter `assets/` werden über `cms_asset_url()` versioniert und bleiben ein Jahr `immutable`.

### Medien-Optimierung

`convert_media_to_webp` wandelt bestehende JPEG/PNG-Bilder stapelweise in WebP um (Standard 25, max. 200 je Lauf; Theme-, Plugin- und Systemordner ausgenommen). `rollback_webp_conversion` entfernt die zuletzt erzeugten WebP-Dateien wieder. Für Thumbnails und WebP mit Fortschrittsanzeige siehe auch den Verarbeitungsjob unter [../media/MEDIA.md](../media/MEDIA.md).

### Datenbank-Wartung

- `optimize_database` → `OPTIMIZE TABLE` für alle CMS-Tabellen (Präfix), `repair_tables` → `REPAIR TABLE` für unterstützte Engines.
- Vorher wird ein Snapshot angelegt; `rollback_database_maintenance` stellt ihn innerhalb von **60 Minuten** wieder her.
- Bei laufenden Queue-Sperren (z. B. Mail-Queue, max. 15 Minuten alt) warnt die Seite vor Wartungsaktionen.

### Sessions

`clear_expired_sessions` löscht abgelaufene Einträge aus `cms_sessions`. Die Laufzeiten wirken auf neue Anmeldungen (`Auth`).

### Kapazitätswarnungen

Die Übersicht warnt bei weniger als 1 GB freiem Speicher (kritisch < 512 MB) und bei einer Serverlast über 4 (kritisch > 8).

### Verwandte Dokumente

[README.md](README.md) · [../media/MEDIA.md](../media/MEDIA.md) · [../diagnose/DIAGNOSE.md](../diagnose/DIAGNOSE.md) · [../../core/SERVICES.md](../../core/SERVICES.md)
