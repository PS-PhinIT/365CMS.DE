# 365CMS – Projektdokumentation | Abschnitt: Admin – Diagnose

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

This folder documents the sidebar groups **Diagnose** and **Logs & Audit**. All pages require `manage_settings`.

## Deutsch

### Gruppe „Diagnose“

| Menüpunkt | Route | Dokument |
|---|---|---|
| Übersicht | `/admin/info` | [../info/INFO.md](../info/INFO.md) |
| Datenbank | `/admin/diagnose` | [DIAGNOSE.md](DIAGNOSE.md) |
| Assets | `/admin/monitor-assets` | [DIAGNOSE.md](DIAGNOSE.md#diagnose--assets-adminmonitor-assets) |
| Antwortzeit-Monitoring | `/admin/monitor-response-time` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md) |
| Cron-Job-Status | `/admin/monitor-cron-status` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md#cron) |
| Speichernutzung | `/admin/monitor-disk-usage` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md) |
| Geplante Aufgaben | `/admin/monitor-scheduled-tasks` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md) |
| Systemprüfung | `/admin/monitor-health-check` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md) |
| E-Mail-Benachrichtigungen | `/admin/monitor-email-alerts` | [../system-settings/MONITORING.md](../system-settings/MONITORING.md#e-mail-benachrichtigungen) |

Zusätzlich erreichbar: Warnzentrale `/admin/monitor-warnings`, Logdateien `/admin/cms-logs`.

### Gruppe „Protokolle & Audit“

`/admin/logs` mit den Unterseiten Operativer Log, Sicherheits-Audit, PHP-Fehlerlog sowie Kanal-Logs & Update-Historie – siehe [DIAGNOSE.md](DIAGNOSE.md#protokolle--audit-sidebar-gruppe).

### Vorgehen bei Problemen

1. `/admin/info` – Übersicht und Warnungen prüfen.
2. `/admin/monitor-health-check` – Systemprüfung ausführen.
3. `/admin/logs` – aktuelle Fehler und Warnungen ansehen.
4. Bei Datenbankfehlern `/admin/diagnose` → fehlende Tabellen anlegen bzw. reparieren (vorher Backup).
5. Für den Support `export_diagnostic_report` nutzen.
