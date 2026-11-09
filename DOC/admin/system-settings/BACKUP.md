# 365CMS – Projektdokumentation | Abschnitt: Admin – Backup & Restore

> **Stand:** 2026-11-08 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/backups` (Alt-Route `/admin/backup` leitet um) | **Capability:** `manage_settings` oder `manage_system` | **CSRF-Aktion:** `admin_backups`

## English (summary)

Backups are created, validated, downloaded, restored and deleted at `/admin/backups` (`CMS/admin/backups.php` → `CMS/admin/modules/system/BackupsModule.php` → `CMS\Services\BackupService`, view `CMS/admin/views/system/backups.php`). Backups are stored in `CMS/backups/`, one directory per backup with a `manifest.json` (SHA-256 hashes). A **full backup** contains a database dump plus a ZIP of `uploads/`, `themes/` and `plugins/` (since 4.0.0 without the core-owned `assets/`); a **database backup** contains a (gzip-compressed) SQL dump. Older single-file dumps `database_*.sql[.gz]` are still listed. Backups without a valid manifest or with missing files are shown as *incomplete*, cannot be restored and do not count in the security audit. Before a restore, the dump and the archive are checked against the manifest hashes. Actions: `create_full`, `create_db`, `validate`, `download`, `restore`, `delete`.

## Deutsch

### Backup-Arten

| Art | Aktion | Inhalt | Name |
|---|---|---|---|
| Vollständig | `create_full` | SQL-Dump aller CMS-Tabellen + ZIP der Verzeichnisse `uploads`, `themes`, `plugins` + `manifest.json` (Datum, CMS-Version, Größe, SHA-256) | `full_backup_JJJJ-MM-TT_HH-MM-SS/` |
| Datenbank | `create_db` | SQL-Dump (wenn möglich gzip-komprimiert) + `manifest.json` | `database_backup_JJJJ-MM-TT_HH-MM-SS/` |

- Entstehen zwei Backups in derselben Sekunde, erhält das zweite die Endung `-2` (bis `-50`); vorhandene Backups werden nie überschrieben.
- Bricht die Erstellung ab, wird das angefangene Verzeichnis entfernt.
- `assets/` gehört zum Core und kommt mit jedem Update neu; es ist seit 4.0.0 nicht mehr im Datei-Archiv. Ältere Archive mit `assets/` bleiben wiederherstellbar, der Ordner wird dabei übersprungen.
- Einzelne Dumps `database_JJJJ-MM-TT_HH-MM-SS.sql[.gz]` direkt in `backups/` (ältere Versionen) werden weiter angezeigt, heruntergeladen und wiederhergestellt. Fremde Ordner wie `performance-rollbacks/` gelten nicht als Backup.

`config/` (Zugangsdaten, Schlüssel) ist bewusst **nicht** enthalten – `CMS/config/app.php` und `CMS/config.php` separat und sicher aufbewahren.

### Status in der Übersicht

| Status | Bedeutung | Mögliche Aktionen |
|---|---|---|
| OK | Manifest lesbar, alle darin genannten Dateien vorhanden | alle |
| Unvollständig | `manifest.json` fehlt oder ist beschädigt bzw. ein genannter Dump oder ein Archiv fehlt (z. B. Abbruch durch Zeitlimit oder vollen Speicher). Der Grund steht im Fehlerprotokoll des Eintrags | `validate`, Trockenlauf, `delete` |
| In Arbeit | Eigenes Backup-Verzeichnis ohne Manifest, jünger als eine Stunde (Erstellung läuft oder wurde gerade abgebrochen); danach „Unvollständig“ | `validate`, Trockenlauf, `delete` |

Unvollständige Backups zählen im Sicherheits-Audit nicht als Sicherung.

### Aktionen

| Aktion | Wirkung |
|---|---|
| `validate` | Prüft Vollständigkeit, Manifest, Hashes, Lesbarkeit und kritische Tabellen (`users`, `settings`, `pages`, `posts`); optional Trockenlauf der Wiederherstellung. Bei unvollständigen Backups meldet die Prüfung „Backup-Vollständigkeit – Blockiert“ |
| `download` | Einmaliger, sessiongebundener Download-Token je Datei und Teil (`database` oder `files`); nur für vorhandene Teile |
| `restore` | Spielt Datenbank-Dump und – falls vorhanden – Datei-Archiv zurück; nur bei Status OK |
| `delete` | Löscht das Backup |

Die Übersicht zeigt bis zu 25 Backups und die letzten 15 Backup-Vorgänge (Historie).

### Wiederherstellung

1. Vorher `validate` ausführen.
2. Wartungsfenster einplanen; die Datenbank wird überschrieben.
3. `restore` – vor dem Rollback-Snapshot und vor jeder Änderung prüft das CMS:
   - SHA-256 von Dump und Archiv gegen das Manifest; bei Abweichung wird abgebrochen.
   - das Datei-Archiv vollständig: keine absoluten Pfade, kein `..`, keine Symlinks, nur `uploads/`, `themes/`, `plugins/` (und übersprungenes `assets/`); max. 250.000 Einträge, 4 GiB je Datei, 32 GiB entpackt.
   - freien Speicherplatz: mindestens das 1,1-Fache der entpackten Größe.
4. Danach anmelden, Inhalte stichprobenartig prüfen, Cache leeren (`/admin/performance-cache`).

Vor 4.0.0 lagen die Grenzen bei 5000 Einträgen und 512 MB; größere Archive, die das CMS selbst erstellt hatte, ließen sich nicht zurückspielen.

### Externe Ziele (Service-API)

`BackupService` bietet zusätzlich `emailDatabaseBackup($email)` (SQL per E-Mail) und `uploadToS3($pfad, $s3Config)` (AWS-SDK oder REST, max. 25 MB per REST). Diese Funktionen sind nicht in der Oberfläche verdrahtet und können von Plugins oder eigenen Cron-Hooks genutzt werden.

### Empfehlungen

- Vor jedem Core-, Plugin- oder Theme-Update sowie vor Site-URL-Migration und Sammel-Löschungen ein Backup erstellen.
- `CMS/backups/` ist per `.htaccess` gesperrt (Apache 2.2 und 2.4); eine ältere Datei mit nur `deny from all` ersetzt das CMS beim nächsten Backup. Unter nginx den Ordner in der Serverkonfiguration sperren. Backups regelmäßig außerhalb des Servers sichern.
- Das Sicherheits-Audit bewertet das jüngste vollständige Backup: bis 7 Tage OK, bis 30 Tage Warnung, älter oder keines kritisch. Externe Hoster-Backups kann es nicht erkennen.

### Verwandte Dokumente

[UPDATES.md](UPDATES.md) · [../security/SECURITY-AUDIT.md](../security/SECURITY-AUDIT.md) · [../../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md](../../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md)
