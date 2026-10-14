# 365CMS – Projektdokumentation | Abschnitt: Admin – System & Dokumentation

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

This folder documents the sidebar group **System & Documentation** (settings, mail, core modules, backups, updates, documentation viewer) plus the monitoring/cron pages of the *Diagnose* group. Most pages require `manage_settings`; backups, mail and documentation also accept `manage_system` for reading.

## Deutsch

### Menüpunkte

| Menüpunkt | Route | Dokument |
|---|---|---|
| Einstellungen | `/admin/settings` | [SYSTEM.md](SYSTEM.md) (Tab *Inhalte*: [../pages-posts/SETTINGS.md](../pages-posts/SETTINGS.md)) |
| Mail & Azure OAuth2 | `/admin/mail-settings` | [MAIL.md](MAIL.md) |
| Module | `/admin/modules` | [MODULES.md](MODULES.md) |
| Backup & Restore | `/admin/backups` | [BACKUP.md](BACKUP.md) |
| Updates | `/admin/updates` | [UPDATES.md](UPDATES.md) |
| Dokumentation | `/admin/documentation` | [SYSTEM.md](SYSTEM.md#dokumentation-admindocumentation) |

### Monitoring und Cron (Gruppe *Diagnose*)

Antwortzeit, Cron-Status, Speichernutzung, geplante Aufgaben, Systemprüfung, E-Mail-Alarme und Warnzentrale: [MONITORING.md](MONITORING.md). Datenbank- und Asset-Diagnose, Logs: [../diagnose/DIAGNOSE.md](../diagnose/DIAGNOSE.md).

### KI

KI-Einstellungen liegen in der Gruppe *KI-Dienste*: [AI-SERVICES.md](AI-SERVICES.md) → [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md).

### Wichtige Hinweise

- **`config/app.php` wird beim Speichern der allgemeinen Einstellungen neu erzeugt.** Eigene LDAP-/JWT-/SMTP-/HTTPS-Konstanten vorher sichern und nach dem Speichern erneut setzen ([SYSTEM.md](SYSTEM.md#speichern-und-configappphp)).
- Vor Updates, Restores und Site-URL-Migrationen immer ein Backup erstellen.
- Geheimnisse (SMTP-Passwort, Azure-/Graph-Secrets, KI-API-Keys) werden über `SettingsService` AES-256-verschlüsselt in der Datenbank gespeichert und nie im Klartext angezeigt.
