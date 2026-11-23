# 365CMS Projektdokumentation | Admin: Performance

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable

## English (summary)

This folder documents the admin area **Performance** (core module `performance`). See [PERFORMANCE.md](PERFORMANCE.md).

## Deutsch

| Menüpunkt | Route |
|---|---|
| Übersicht | `/admin/performance` |
| Cache-Verwaltung | `/admin/performance-cache` |
| Medien-Optimierung | `/admin/performance-media` |
| Datenbank-Wartung | `/admin/performance-database` |
| Performance-Einstellungen | `/admin/performance-settings` |
| Session-Verwaltung | `/admin/performance-sessions` |

Alle Seiten: [PERFORMANCE.md](PERFORMANCE.md). Messung im Frontend (Core Web Vitals): [../seo/ANALYTICS.md](../seo/ANALYTICS.md). Antwortzeiten und Systemlast: [../diagnose/DIAGNOSE.md](../diagnose/DIAGNOSE.md).

**Sicherheitsnetz:** Cache-Bereinigung, Datenbankwartung und WebP-Konvertierung lassen sich innerhalb einer Stunde zurückrollen (Snapshots unter `CMS/backups/performance-rollbacks/`).

Bildoptimierung in 4.2.0: JPEG-EXIF wird für GD-Ausgaben in allen acht Ausrichtungen auf die Pixel angewendet; `imagesavealpha(true)` erhält Transparenz. `strip_exif` reinigt erhaltene JPEG/PNG/WebP-Originale ohne Reencode und behält ICC-Profil/Ausrichtung. Gezielter
