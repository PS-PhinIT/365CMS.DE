# 365CMS – Projektdokumentation | Abschnitt: Admin – Dashboard

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin` | **Zugang:** Rolle `admin` | **CSRF-Aktion:** `admin_dashboard`

## English (summary)

The admin start page (`CMS/admin/index.php` → `CMS/admin/modules/dashboard/DashboardModule.php` → `CMS/admin/views/dashboard/index.php`, statistics from `CMS\Services\DashboardService`) shows welcome data, KPIs, a configurable work overview, favourite shortcuts, attention items, system/security/performance status, recent orders and recent activity. Each administrator can personalise sections, widgets and shortcuts (`save_dashboard_preferences`, `reset_dashboard_preferences`); preferences are stored per user in `cms_settings` as `admin_dashboard_preferences_user_<id>`.

## Deutsch

### Bereiche

| Bereich (Schlüssel) | Inhalt |
|---|---|
| Begrüßung | Name, Version, Hinweise zum System |
| KPIs | Benutzer, Seiten, Beiträge, Medien, Umsatz (30 Tage, nur bei aktiver Bestellverwaltung) |
| Highlights | neue Benutzer heute/7 Tage, Entwürfe & private Seiten, geplante & private Beiträge, Uploads gesamt |
| `work_overview` – Zentrale Arbeitsübersicht | konfigurierbare Widgets (siehe unten) |
| `favorites_recent` – Favoriten & zuletzt genutzt | Schnellzugriffe und zuletzt aufgerufene Admin-Seiten (`FeatureUsageService`) |
| `attention` – Nächste Aufmerksamkeit | offene Aufgaben: ausstehende Kommentare, Updates, Warnungen, Fehler |
| `system_status` – Systemstatus | PHP, Datenbank, Speicher, Cron |
| `security_performance` | Sicherheits- und Performance-Kennzahlen |
| `recent_orders` – Neueste Bestellungen | nur mit aktivem Modul `subscription_admin_orders` |
| `recent_activity` – Letzte Aktivitäten | Aktivitäts-/Audit-Feed |

Hinweise (Alerts) entstehen aus Systemzustand und Statistik, z. B. ausstehende Kommentare, fehlende Backups oder Health-Probleme.

### Widgets der Arbeitsübersicht

`users_total`, `pages_total`, `posts_total`, `media_total`, `orders_revenue`, `user_growth`, `content_pipeline` (Redaktions-Pipeline), `comment_queue` (Kommentar-Moderation), `sessions_live` (aktive Sessions), `security_snapshot`, `system_stack`.

### Favoriten-Shortcuts

`new_page`, `new_post`, `comments`, `media`, `featured_media`, `users`, `analytics`, `security_audit`, `updates`, `cms_logs`, `settings`.

### Personalisierung

- **Speichern** (`save_dashboard_preferences`): sichtbare Bereiche, sichtbare Widgets und deren Reihenfolge, Favoriten und deren Reihenfolge.
- **Zurücksetzen** (`reset_dashboard_preferences`): Rückkehr zur Rollenvorlage „Administrator“ („Breite Steuerungsansicht mit Betriebs-, Sicherheits- und Aktivitätsfokus“).
- Gespeichert je Benutzer als Option `admin_dashboard_preferences_user_<benutzer-id>`.
- Clientseitige Sortierung über `CMS/assets/js/admin-dashboard.js` (CSP-konform, ohne Inline-Skripte).

### Datenquellen (`DashboardService`)

`getAllStats()`, `getUserStats()`, `getPageStats()`, `getPostStats()` (inkl. geplanter Beiträge), `getMediaStats()`, `getSessionStats()`, `getSecurityStats()`, `getPerformanceStats()`, `getOrderStats()`, `getRecentOrders()`, `getActivityFeed()`, `getAttentionItems()`, `getSystemInfo()`.

### Plugin-Bereiche

Plugins erscheinen nicht direkt auf dem Dashboard, sondern in der Sidebar unter *Plugin-Erweiterungen* ([../plugins/PLUGINS.md](../plugins/PLUGINS.md)). Kennzahlen für Mitglieder liefern Plugins über das Mitglieder-Dashboard ([../themes-design/DASHBOARD-WIDGETS.md](../themes-design/DASHBOARD-WIDGETS.md)).

### Verwandte Dokumente

[README.md](README.md) · [../README.md](../README.md) · [../diagnose/DIAGNOSE.md](../diagnose/DIAGNOSE.md)
