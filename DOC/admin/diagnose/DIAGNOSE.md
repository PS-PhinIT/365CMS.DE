# 365CMS – Projektdokumentation | Abschnitt: Admin – Diagnose & Protokolle

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Routen:** `/admin/diagnose`, `/admin/monitor-assets`, `/admin/cms-logs`, `/admin/logs`, `/admin/logs/{operational,security-audit,php-errors,channels}` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_system_info`

## English (summary)

Diagnostic screens are rendered by `CMS/admin/system-monitor-page.php` (sections `diagnose`, `assets`, `logs`, …) and `CMS/admin/logs-page.php` (sidebar group *Logs & Audit*), both backed by `CMS/admin/modules/system/SystemInfoModule.php`. They show database/table state, runtime telemetry, vendor/asset inventory, error reports and log files, and offer maintenance actions (clear cache, optimise/repair/create tables, export a diagnostic report). Operational logs are daily files `CMS/logs/<channel>-YYYY-MM-DD.log` written by `CMS\Logger`; security/business events are stored in `cms_audit_log` by `CMS\AuditLogger`.

## Deutsch

### Diagnose – Datenbank (`/admin/diagnose`)

| Anzeige | Inhalt |
|---|---|
| Datenbankstatus | Verbindung, Server-Version, Größe, Zeichensatz |
| Tabellen | alle CMS-Tabellen mit Zeilen, Größe, Overhead, Engine; fehlende Tabellen werden markiert |
| Berechtigungen | Schreibrechte kritischer Verzeichnisse |
| Runtime-Telemetrie | PHP-Version, Speicherlimit, OPcache, Ausführungszeit |
| Fehlerreports | letzte 15 Einträge aus `cms_error_reports` |

| Aktion | Wirkung |
|---|---|
| `clear_cache` | Datei-/APCu-Cache leeren |
| `optimize_db` | `OPTIMIZE TABLE` für CMS-Tabellen |
| `create_tables` | fehlende Tabellen aus `SchemaManager` anlegen |
| `repair_tables` | Tabellen reparieren und idempotente Migrationen ausführen |
| `export_diagnostic_report` | Diagnosebericht (System, Datenbank, letzte Logs) als Datei herunterladen – zum Weitergeben an den Support |
| `clear_error_reports` | Fehlerreports löschen |

### Diagnose – Assets (`/admin/monitor-assets`)

Verzeichnisgrößen, Dateirechte und das **Vendor-Inventar** aus `CMS\VendorRegistry` (gebündelte Bibliotheken unter `CMS/assets/`, Version, Status, Prüfsymbol). Details: [../../assets/README.md](../../assets/README.md).

### Fehlerreports

Viele Admin-Fehlermeldungen bieten „Fehler melden“ an. Der Bericht wird über `POST /admin/error-report` (CSRF `admin_error_report`) mit Titel, Nachricht, Fehlercode, Quell-URL, Fehlerdaten und Kontext in `cms_error_reports` gespeichert und erscheint unter `/admin/diagnose`.

### Protokolle & Audit (Sidebar-Gruppe)

| Menüpunkt | Route | Inhalt | Aktionen |
|---|---|---|---|
| Übersicht | `/admin/logs` | Kennzahlen, letzte Ereignisse aller Quellen; Badge in der Sidebar zeigt neue Fehler | `clear_all_cms_logs`, `export_diagnostic_report`, `run_audit`, `clear_log` |
| Operativer Log | `/admin/logs/operational` | Einträge aus den Kanal-Logdateien (Warnung und höher) | `clear_all_cms_logs` |
| Sicherheits-Audit | `/admin/logs/security-audit` | `cms_audit_log` (Anmeldungen, Rollen, Plugins, Themes, Einstellungen, Sicherheit) | `run_audit`, `clear_log` (älter als 30 Tage) |
| PHP-Fehlerlog | `/admin/logs/php-errors` | `CMS_ERROR_LOG` (Standard `CMS/logs/error.log`) | `clear_logs` |
| Kanal-Logs & Update-Historie | `/admin/logs/channels` | einzelne Logdateien je Kanal (`?log_file=`), Update-Historie | `clear_cms_log` |

`/admin/cms-logs` zeigt dieselben Logdateien innerhalb der Diagnose-Gruppe.

### Logging im Code

| Komponente | Ziel | Hinweise |
|---|---|---|
| `CMS\Logger` | `CMS/logs/<kanal>-JJJJ-MM-TT.log` | Kanäle über `Logger::instance()->withChannel('admin.posts')`; Mindest-Level `WARNING`, mit `CMS_DEBUG` `DEBUG` oder per Konstante `LOG_LEVEL`; ab `CRITICAL` zusätzlich ins Audit-Log |
| `CMS\AuditLogger` | `cms_audit_log` | Kategorien `auth`, `content`, `theme`, `plugin`, `user`, `setting`, `media`, `system`, `security` |
| Sicherheitsereignisse | `cms_security_log` | Firewall, Anti-Spam |
| PHP-Fehler | `CMS_ERROR_LOG` | per `ini_set('error_log', …)` |

Geheimnisse, Tokens und vollständige Inhalte werden nicht protokolliert; Logtexte werden gekürzt und von Steuerzeichen bereinigt.

### Verwandte Dokumente

[README.md](README.md) · [../info/INFO.md](../info/INFO.md) · [../system-settings/MONITORING.md](../system-settings/MONITORING.md) · [../../core/SERVICES.md](../../core/SERVICES.md)
