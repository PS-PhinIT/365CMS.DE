# 365CMS – Projektdokumentation | Abschnitt: Admin – Diagnose & Protokolle

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

This folder documents the diagnostic pages, monitors and logs. All of them are tiles on one tile page:

- **Website-Zustand** (`/admin/configuration?group=health`) in the *Classic* sidebar profile,
- **Berichte** (`/admin/configuration?group=reports`) in *Structured*.

The tiles are sorted into the categories *Protokolle*, *Monitore*, *Sicherheit*, *System* and *Hilfe* ([../ADMIN-MENU.md](../ADMIN-MENU.md#7-kachelseiten)). The sidebar link adds up the tile badges, e.g. new log errors. All pages require `manage_settings`.

## Deutsch

Alle Seiten sind Kacheln auf **einer** Kachelseite. Im Profil *Klassisch* heißt sie **Website-Zustand** (`/admin/configuration?group=health`), im Profil *Strukturiert* **Berichte** (`/admin/configuration?group=reports`). Badges der Kacheln, etwa neue Fehler im Protokoll, erscheinen summiert am Sidebar-Link.

### Kategorie „Protokolle“

| Kachel | Route | Dokument |
|---|---|---|
| Protokolle: Übersicht (Badge: neue Fehler) | `/admin/logs` | [DIAGNOSE.md](DIAGNOSE.md#protokolle-adminlogs) |
| Operativer Log | `/admin/logs/operational` | [DIAGNOSE.md](DIAGNOSE.md#protokolle-adminlogs) |
| Sicherheits-Audit-Log | `/admin/logs/security-audit` | [DIAGNOSE.md](DIAGNOSE.md#protokolle-adminlogs) |
| PHP-Fehlerlog | `/admin/logs/php-errors` | [DIAGNOSE.md](DIAGNOSE.md#protokolle-adminlogs) |
| Kanal-Logs & Update-Historie | `/admin/logs/channels` | [DIAGNOSE.md](DIAGNOSE.md#protokolle-adminlogs) |

Der alte Link `/admin/cms-logs` leitet auf `/admin/logs` um.

### Kategorie „Monitore“

| Kachel | Route | Dokument |
|---|---|---|
| Asset-Monitor | `/admin/monitor-assets` | [DIAGNOSE.md](DIAGNOSE.md#diagnose--assets-adminmonitor-assets) |
| Antwortzeit-Monitor | `/admin/monitor-response-time` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md) |
| Cron-Job-Status | `/admin/monitor-cron-status` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md#cron) |
| Speichernutzung | `/admin/monitor-disk-usage` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md) |
| Geplante Aufgaben | `/admin/monitor-scheduled-tasks` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md) |
| Systemprüfung | `/admin/monitor-health-check` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md) |
| E-Mail-Benachrichtigungen | `/admin/monitor-email-alerts` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md#e-mail-benachrichtigungen) |
| Warnungen (Warnzentrale) | `/admin/monitor-warnings` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md) |

### Kategorien „Sicherheit“, „System“ und „Hilfe“

| Kachel | Route | Dokument |
|---|---|---|
| Sicherheits-Audit | `/admin/security-audit` | [../security/SECURITY-AUDIT.md](../security/SECURITY-AUDIT.md) |
| Systeminfo | `/admin/info` | [../info/INFO.md](../info/INFO.md) |
| Diagnose (Datenbank) | `/admin/diagnose` | [DIAGNOSE.md](DIAGNOSE.md) |
| Dokumentation, zusätzlich Hilfe-Symbol in der Kopfleiste | `/admin/documentation` | [../system-settings/SYSTEM.md](../system-settings/SYSTEM.md#dokumentation-admindocumentation) |

### Vorgehen bei Problemen

1. `/admin/info` – Übersicht und Warnungen prüfen.
2. `/admin/monitor-health-check` – Systemprüfung ausführen.
3. `/admin/logs` – aktuelle Fehler und Warnungen ansehen.
4. Bei Datenbankfehlern `/admin/diagnose` → fehlende Tabellen anlegen bzw. reparieren (vorher Backup).
5. Für den Support `export_diagnostic_report` nutzen.
