# 365CMS – Projektdokumentation | Abschnitt: Admin – Performance

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

This folder documents the sidebar group **Performance** (core module `performance`). See [PERFORMANCE.md](PERFORMANCE.md).

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
