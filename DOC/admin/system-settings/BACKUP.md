# 365CMS – Projektdokumentation | Abschnitt: Admin – Backup & Restore

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/backups` (Alt-Route `/admin/backup` leitet um) | **Capability:** `manage_settings` oder `manage_system` | **CSRF-Aktion:** `admin_backups`

## English (summary)

Backups are created, validated, downloaded, restored and deleted at `/admin/backups` (`CMS/admin/backups.php` → `CMS/admin/modules/system/BackupsModule.php` → `CMS\Services\BackupService`, view `CMS/admin/views/system/backups.php`). Backups are stored in `CMS/backups/`. A **full backup** contains a database dump plus a ZIP of `uploads/`, `themes/`, `plugins/` and `assets/` and a `manifest.json` with SHA-256 hashes; a **database backup** is a (gzip-compressed) SQL dump. Actions: `create_full`, `create_db`, `validate`, `download`, `restore`, `delete`.

## Deutsch

### Backup-Arten

| Art | Aktion | Inhalt | Name |
|---|---|---|---|
| Vollständig | `create_full` | SQL-Dump aller CMS-Tabellen + ZIP der Verzeichnisse `uploads`, `themes`, `plugins`, `assets` + `manifest.json` (Datum, Größe, SHA-256) | `full_backup_JJJJ-MM-TT_HH-MM-SS/` |
| Datenbank | `create_db` | SQL-Dump, wenn möglich gzip-komprimiert | `database_JJJJ-MM-TT_HH-MM-SS.sql[.gz]` |

`config/` (Zugangsdaten, Schlüssel) ist bewusst **nicht** enthalten – `CMS/config/app.php` und `CMS/config.php` separat und sicher aufbewahren.

### Aktionen

| Aktion | Wirkung |
|---|---|
| `validate` | Prüft Manifest, Hashes, Lesbarkeit und kritische Tabellen (`users`, `settings`, `pages`, `posts`); optional Trockenlauf der Wiederherstellung |
| `download` | Einmaliger, sessiongebundener Download-Token je Datei und Teil (`database` oder `files`) |
| `restore` | Spielt Datenbank-Dump und – falls vorhanden – Datei-Archiv zurück |
| `delete` | Löscht das Backup |

Die Übersicht zeigt bis zu 25 Backups und die letzten 15 Backup-Vorgänge (Historie).

### Wiederherstellung

1. Vorher `validate` ausführen.
2. Wartungsfenster einplanen; die Datenbank wird überschrieben.
3. `restore` – Grenzen für Datei-Archive: max. 5000 Einträge, 256 MB je Datei, 512 MB entpackt (Schutz vor ZIP-Bomben/Pfad-Traversal).
4. Danach anmelden, Inhalte stichprobenartig prüfen, Cache leeren (`/admin/performance-cache`).

### Externe Ziele (Service-API)

`BackupService` bietet zusätzlich `emailDatabaseBackup($email)` (SQL per E-Mail) und `uploadToS3($pfad, $s3Config)` (AWS-SDK oder REST, max. 25 MB per REST). Diese Funktionen sind nicht in der Oberfläche verdrahtet und können von Plugins oder eigenen Cron-Hooks genutzt werden.

### Empfehlungen

- Vor jedem Core-, Plugin- oder Theme-Update sowie vor Site-URL-Migration und Sammel-Löschungen ein Backup erstellen.
- `CMS/backups/` per Webserver vor direktem Zugriff schützen und regelmäßig außerhalb des Servers sichern.
- Das Sicherheits-Audit warnt, wenn das letzte Backup zu alt ist.

### Verwandte Dokumente

[UPDATES.md](UPDATES.md) · [../security/SECURITY-AUDIT.md](../security/SECURITY-AUDIT.md) · [../../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md](../../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md)
