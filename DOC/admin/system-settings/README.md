# 365CMS – Projektdokumentation | Abschnitt: Admin – System & Dokumentation

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

This folder documents the system administration area:

- settings, including MultiSites,
- mail,
- core modules,
- backups,
- updates,
- the documentation viewer,
- the monitoring and cron pages.

Where a page appears depends on the sidebar profile ([../ADMIN-MENU.md](../ADMIN-MENU.md)):

- *Classic*: most pages are tiles on the **Einstellungen** tile page. Backups are under **Werkzeuge**, monitoring and documentation on **Website-Zustand**.
- *Structured*: they are tiles under **Konfiguration** and **Berichte**.
- *Updates* is a direct sidebar link with a badge in both profiles.

Most pages require `manage_settings`; backups, mail and documentation also accept `manage_system` for reading.

## Deutsch

### Menüpunkte

| Menüpunkt | Route | Klassisch | Strukturiert | Dokument |
|---|---|---|---|---|
| Allgemeine Einstellungen | `/admin/settings` | Einstellungen › *Allgemein* | Konfiguration › *Allgemein* | [SYSTEM.md](SYSTEM.md) (Tab *Beiträge & Sites*: [../pages-posts/SETTINGS.md](../pages-posts/SETTINGS.md)) |
| MultiSites | `/admin/multisites` | Einstellungen › *Allgemein* | Konfiguration › *Allgemein* | [MULTISITES.md](MULTISITES.md) |
| E-Mail & OAuth2 | `/admin/mail-settings` | Einstellungen › *Allgemein* | Konfiguration › *Allgemein* | [MAIL.md](MAIL.md) |
| RSS-Feeds | `/admin/settings?tab=feeds` | Einstellungen › *Allgemein* | Konfiguration › *Allgemein* | [FEEDS.md](FEEDS.md) |
| Module | `/admin/modules` | Plugins | Erweitern | [MODULES.md](MODULES.md) |
| Backups | `/admin/backups` | Werkzeuge | Konfiguration › *Wartung* | [BACKUP.md](BACKUP.md) |
| Updates | `/admin/updates` | direkter Link mit Badge | direkter Link mit Badge | [UPDATES.md](UPDATES.md) |
| Dokumentation | `/admin/documentation` | Website-Zustand › *Hilfe*, zusätzlich Hilfe-Symbol in der Kopfleiste | Berichte › *Hilfe*, zusätzlich Hilfe-Symbol | [SYSTEM.md](SYSTEM.md#dokumentation-admindocumentation) |

Kursiv gesetzt ist die Kategorie auf der Kachelseite. Das Admin-Menü selbst (`/admin/settings?tab=admin-menu`) ist in [../ADMIN-MENU.md](../ADMIN-MENU.md) beschrieben.

### Monitoring und Cron (*Website-Zustand* bzw. *Berichte*, Kategorie *Monitore*)

[MONITORING.md](MONITORING.md) beschreibt:

- Antwortzeit und Speichernutzung,
- Cron-Status und geplante Aufgaben,
- Systemprüfung,
- E-Mail-Alarme,
- die Warnzentrale.

Datenbank- und Asset-Diagnose sowie die Protokolle stehen in [../diagnose/DIAGNOSE.md](../diagnose/DIAGNOSE.md).

### KI

Die Seite **KI-Einstellungen** ist eine Kachel unter *Einstellungen* (Klassisch) bzw. *Konfiguration* (Strukturiert), Kategorie *KI*. Die übrigen KI-Seiten liegen in der optionalen Gruppe **KI-Dienste**, die nur bei aktivem Modul erscheint. Siehe [AI-SERVICES.md](AI-SERVICES.md) → [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md).

### Wichtige Hinweise

- **Speichern der allgemeinen Einstellungen ändert in `config/app.php` nur `SITE_NAME`, `SITE_URL`, `ADMIN_EMAIL` und `CMS_DEBUG`;** eigene LDAP-/JWT-/SMTP-/HTTPS-Konstanten bleiben erhalten. Vor jeder Änderung entsteht eine Kopie `config/app.backup-<Datum>.php` (Rechte `0640`, die letzten fünf bleiben erhalten) ([SYSTEM.md](SYSTEM.md#speichern-und-configappphp)).
- Vor Updates, Restores und Site-URL-Migrationen immer ein Backup erstellen.
- Geheimnisse (SMTP-Passwort, Azure-/Graph-Secrets, KI-API-Keys) werden über `SettingsService` verschlüsselt in der Datenbank gespeichert – seit 4.0.0 authentifiziert (XChaCha20-Poly1305 bzw. AES-256-GCM über `CryptoService`, Schlüssel `CMS_ENCRYPTION_KEY`, siehe [../../core/SECURITY.md](../../core/SECURITY.md)) – und nie im Klartext angezeigt.
