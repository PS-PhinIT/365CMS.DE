# 365CMS – Projektdokumentation | Abschnitt: Admin – Monitoring, Cron & Benachrichtigungen

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Routen:** `/admin/monitor-response-time`, `/admin/monitor-cron-status`, `/admin/monitor-disk-usage`, `/admin/monitor-scheduled-tasks`, `/admin/monitor-health-check`, `/admin/monitor-email-alerts`, `/admin/monitor-warnings` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_system_info`

## English (summary)

The monitoring pages belong to the sidebar group *Diagnose* and are rendered by `CMS/admin/system-monitor-page.php` with `CMS/admin/modules/system/SystemInfoModule.php` (views in `CMS/admin/views/system/`). Trend data is collected hourly by `MonitoringTrendService`; security alerts by `SecurityAlertService`. Scheduled work runs through `CMS/cron.php` → `CronRunnerService` with the tasks `all`, `mail-queue`, `hourly`, `daily`, `feeds` (and generic `cms_cron_*` hooks). Web cron requires the token shown under *Mail → Queue*.

## Deutsch

### Seiten

| Route | Inhalt | Aktionen |
|---|---|---|
| `/admin/monitor-response-time` | Antwortzeit der Startseite und Verlauf, Schwellwert `monitor_response_threshold_ms` (Standard 800 ms) | – |
| `/admin/monitor-cron-status` | Letzte Läufe je Task, Cron-URL/CLI-Befehl, Verlauf | `run_cron_direct`, `run_cron_loopback` |
| `/admin/monitor-disk-usage` | Belegung von Uploads, Cache, Logs, Backups, Datenbank; Schwellwert `monitor_disk_threshold_percent` (85 %) | – |
| `/admin/monitor-scheduled-tasks` | Registrierte `cms_cron_*`-Hooks und Zeitpläne | – |
| `/admin/monitor-health-check` | Gesamtprüfung (Datenbank, Dateisystem, PHP, Sicherheit, Performance über `StatusService`), optional Abfrage eines externen Health-Endpunkts | – |
| `/admin/monitor-email-alerts` | Alarm-E-Mails für Monitoring und Sicherheit | `save_monitoring_alerts`, `send_monitoring_test_email` |
| `/admin/monitor-warnings` | **Warnzentrale**: gesammelte Warnungen aus allen Bereichen | `ignore_warning_center_warning`, `snooze_warning_center_warning` (1/3/7/14/30 Tage), `restore_warning_center_warning` |

### E-Mail-Benachrichtigungen

| Option | Standard | Bedeutung |
|---|---|---|
| `monitor_email_notifications_enabled` | aus | Monitoring-Alarme versenden |
| `monitor_alert_email` | leer (Admin-E-Mail) | Empfänger |
| `monitor_response_threshold_ms` | 800 | Alarm bei langsamer Antwort |
| `monitor_disk_threshold_percent` | 85 | Alarm bei Speicherbelegung |
| `monitor_health_endpoint_enabled` / `_path` | aus / `/health` | Health-Endpunkt mitprüfen. Seit 3.4.12 stellt der Core `GET /health` bereit (JSON `status`/`database`, nur bei aktivierter Option, sonst 404); ein abweichender Pfad muss vom Webserver oder einem Plugin bereitgestellt werden |
| `security_email_notifications_enabled` | aus | Sicherheitsalarme |
| `security_alert_bruteforce_threshold` | 15 | fehlgeschlagene Logins im Fenster |
| `security_alert_antispam_threshold` | 10 | Anti-Spam-Ablehnungen im Fenster |
| `security_alert_firewall_threshold` | 10 | Firewall-Blockaden im Fenster |
| `security_alert_window_minutes` | 60 | Betrachtungsfenster |
| `security_alert_cooldown_minutes` | 180 | Mindestabstand zwischen gleichen Alarmen |

### Cron

`CMS/cron.php` ist der einzige Einstieg für geplante Aufgaben.

**CLI (empfohlen):**

```bash
# alle fälligen Aufgaben, z. B. jede Minute bzw. alle 5 Minuten
php /pfad/zu/CMS/cron.php --task=all --quiet
# nur Mail-Queue mit Limit
php /pfad/zu/CMS/cron.php --task=mail-queue --limit=10 --quiet
```

Optionen: `--task=<all|mail-queue|hourly|daily|feeds|cms_cron_…>`, `--limit=1..100`, `--force`, Ausgabe `--json`/`--verbose`, `--text`, `--quiet`.

**Web-Cron:** `https://<domain>/cron.php?task=all` mit Header `X-CMS-Cron-Token: <token>` (alternativ `?token=` – nur über HTTPS, sonst Warnung im Log). Ohne gültigen Token: HTTP 403. Token anzeigen/erneuern unter `/admin/mail-settings` → Tab *Queue*.

**Aufgaben und Hooks:**

| Task | Hook | Was passiert (Core) |
|---|---|---|
| `mail-queue` | `cms_cron_mail_queue` | fällige Mails versenden; stößt standardmäßig auch fällige Hourly-/Daily-Läufe an (`cron.mail_queue_triggers_hourly/daily`) |
| `hourly` | `cms_cron_hourly` (Zeitplan `0 * * * *`) | Broken-Link-Scan, SEO-Trend-Snapshot, Monitoring-Trend-Snapshot, Sicherheitsalarm-Scan |
| `daily` | `cms_cron_daily` (Zeitplan `15 2 * * *`) | Sitemap-Bundle neu erzeugen |
| `feeds` | `cms_cron_feeds` | Feed-Aktualisierung durch Plugins |
| `cms_cron_<name>` | beliebig | eigene Plugin-Hooks |

`run_cron_direct` führt den Lauf im aktuellen Request aus, `run_cron_loopback` ruft `cron.php` per HTTP mit Token auf (prüft damit auch die Erreichbarkeit).

### Verwandte Dokumente

[../diagnose/DIAGNOSE.md](../diagnose/DIAGNOSE.md) · [MAIL.md](MAIL.md) · [../../assets/cron/README.md](../../assets/cron/README.md) · [../../core/HOOKS-REFERENCE.md](../../core/HOOKS-REFERENCE.md)
